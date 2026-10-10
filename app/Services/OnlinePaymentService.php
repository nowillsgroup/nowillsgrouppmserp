<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\BanquetEvent;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GatewayPayout;
use App\Models\OnlinePayment;
use App\Models\OnlinePaymentEvent;
use App\Models\OnlineRefund;
use App\Models\PaymentMode;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\Voucher;
use App\Services\Payments\DemoGateway;
use App\Services\Payments\Gateway;
use App\Services\Payments\PaystackGateway;
use App\Services\Payments\RazorpayGateway;
use App\Services\Payments\StripeGateway;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * v2.1 online payments. A payment link (deposit / balance / booking-engine deposit) is paid on the
 * gateway's hosted page; the result is confirmed server-side (return verification or a signed webhook),
 * then posted once – idempotently – to the right folio with the "Online Payment" mode, whose ledger
 * account is 1050 Payment Gateway Clearing. Payouts move clearing → bank (+ fees).
 */
class OnlinePaymentService
{
    public const PROVIDERS = [
        'demo' => 'Built-in test gateway (no real money)',
        'paystack' => 'Paystack – cards & mobile money (Ghana, Nigeria, Kenya, South Africa)',
        'stripe' => 'Stripe – cards & wallets (worldwide)',
        'razorpay' => 'Razorpay – cards, UPI & netbanking (India, INR only)',
    ];

    public const SECRETS = ['pay_paystack_secret', 'pay_stripe_secret', 'pay_stripe_webhook_secret', 'pay_razorpay_key_secret', 'pay_razorpay_webhook_secret'];

    public const DEPOSIT_POLICIES = ['none' => 'No deposit – book now, pay at the hotel', 'first_night' => 'First night', 'percent' => 'Percentage of the stay', 'full' => 'Full stay prepaid'];

    // ------------------------------------------------------------------ configuration

    public static function provider(): string
    {
        $p = (string) setting('pay_provider', 'demo');

        return array_key_exists($p, self::PROVIDERS) ? $p : 'demo';
    }

    public static function configured(?string $provider = null): bool
    {
        return match ($provider ?? self::provider()) {
            'demo' => true,
            'paystack' => (bool) MessageService::secret('pay_paystack_secret'),
            'stripe' => (bool) MessageService::secret('pay_stripe_secret'),
            'razorpay' => setting('pay_razorpay_key_id') && MessageService::secret('pay_razorpay_key_secret'),
            default => false,
        };
    }

    public static function enabled(): bool
    {
        return setting('pay_enabled', '0') === '1' && self::configured();
    }

    public static function gateway(?string $provider = null): Gateway
    {
        return match ($provider ?? self::provider()) {
            'paystack' => new PaystackGateway, 'stripe' => new StripeGateway, 'razorpay' => new RazorpayGateway, default => new DemoGateway,
        };
    }

    public static function currency(): string
    {
        return strtoupper(substr((string) (hotel()?->currency ?: 'GHS'), 0, 3));
    }

    /** The "Online Payment" mode (mapped to 1050 Payment Gateway Clearing). Idempotent. */
    public static function mode(): PaymentMode
    {
        $m = PaymentMode::where('type', 'online')->orderBy('id')->first();
        if (! $m) {
            $m = PaymentMode::create(['name' => 'Online Payment', 'type' => 'online', 'is_active' => true]);
        }
        if (! $m->account_head_id && ($head = AccountHead::sys('gateway_clearing'))) {
            $m->forceFill(['account_head_id' => $head])->save();
        }

        return $m;
    }

    public static function ensureDefaults(): void
    {
        ChartOfAccounts::ensureLight();
        AccountHead::flushSys();
        self::mode();
    }

    // ------------------------------------------------------------------ creating links

    /** Suggested amount: folio balance, or for a reservation the unpaid part of its first night (deposit) / stay. */
    public static function suggest(?Reservation $r, ?Folio $f): float
    {
        if ($f) {
            return max(0, (float) $f->totals()['balance']);
        }
        if (! $r) {
            return 0;
        }
        $paid = self::reservationPaid($r);
        $rooms = $r->rooms()->whereNotIn('status', ['cancelled', 'no_show'])->get();
        $first = $rooms->sum(fn ($s) => (float) $s->rate + (float) $s->extra_charge);

        return round(max(0, ($paid > 0 ? self::stayValue($r) : $first) - $paid), 2);
    }

    public static function stayValue(Reservation $r): float
    {
        return round($r->rooms()->whereNotIn('status', ['cancelled', 'no_show'])->get()
            ->sum(fn ($s) => ((float) $s->rate + (float) $s->extra_charge) * max(1, $s->arrival_date->diffInDays($s->departure_date))), 2);
    }

    /** Net payments already on the reservation's folios. */
    public static function reservationPaid(Reservation $r): float
    {
        return round((float) FolioTransaction::whereIn('folio_id', Folio::where('reservation_id', $r->id)->pluck('id'))->where('is_void', false)
            ->selectRaw("SUM(CASE WHEN type='payment' THEN amount WHEN type='refund' THEN -amount ELSE 0 END) s")->value('s'), 2);
    }

    /**
     * @param  array{amount: float|string, description?: ?string, hours?: ?int, email?: ?string, phone?: ?string, purpose?: string, minutes?: ?int}  $d
     */
    public static function create(array $d, ?Reservation $r = null, ?Folio $f = null): OnlinePayment
    {
        if (! self::enabled()) {
            throw new RuntimeException('Online payments are switched off (Administration › Online Payment Settings).');
        }
        if (! $r && ! $f) {
            throw new RuntimeException('A payment link must belong to a reservation or a folio.');
        }
        $amount = round((float) $d['amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        if ($amount > 10000000) {
            throw new RuntimeException('Amount is too large for an online payment.');
        }
        if ($f) {
            if ($f->status !== 'open') {
                throw new RuntimeException("Folio {$f->folio_no} is closed.");
            }
            $r ??= $f->reservation_id ? Reservation::find($f->reservation_id) : null;
        }
        if ($r && in_array($r->status, ['cancelled', 'no_show', 'checked_out']) && ! $f) {
            throw new RuntimeException("Reservation {$r->reservation_no} is {$r->status}.");
        }
        $guest = $f?->guest ?? $r?->guest;
        $purpose = $d['purpose'] ?? ($f ? 'balance' : 'deposit');
        $expires = ! empty($d['minutes']) ? now()->addMinutes((int) $d['minutes'])
            : now()->addHours(max(1, min(24 * 30, (int) ($d['hours'] ?? setting('pay_link_hours', 72)))));

        $p = OnlinePayment::create([
            'pay_no' => Numbering::next('online_payment'), 'purpose' => $purpose,
            'reservation_id' => $r?->id, 'folio_id' => $f?->id, 'guest_id' => $guest?->id,
            'payer_name' => mb_substr($guest?->full_name ?: ($f?->company?->name ?? 'Guest'), 0, 150),
            'payer_email' => ($d['email'] ?? null) ?: $guest?->email, 'payer_phone' => ($d['phone'] ?? null) ?: $guest?->phone,
            'description' => mb_substr(($d['description'] ?? null) ?: self::defaultDescription($purpose, $r, $f), 0, 190),
            'amount' => $amount, 'currency' => self::currency(), 'status' => 'pending', 'provider' => self::provider(),
            'expires_at' => $expires, 'created_by' => auth()->id(),
        ]);
        self::log($p, auth()->check() ? 'staff' : ($p->purpose === 'booking' ? 'guest' : ($p->purpose === 'balance' ? 'portal' : 'system')), 'created', ($p->purpose === 'booking' ? 'Online booking – deposit link' : 'Link').' created for '.$p->currency.' '.number_format($amount, 2).' (expires '.$expires->format('d M H:i').')');
        ActivityLogger::log('payments', 'link', "Payment link {$p->pay_no} for ".money($amount).($r ? " on {$r->reservation_no}" : '').($f ? " / folio {$f->folio_no}" : ''), $p);

        return $p;
    }

    protected static function defaultDescription(string $purpose, ?Reservation $r, ?Folio $f): string
    {
        return match ($purpose) {
            'booking' => 'Deposit for online booking '.$r?->reservation_no,
            'balance' => 'Balance of bill'.($f ? ' '.$f->folio_no : ''),
            default => 'Advance for reservation '.$r?->reservation_no,
        };
    }

    /** Send the link by e-mail and/or SMS / WhatsApp. Returns human-readable results per channel. */
    public static function send(OnlinePayment $p, array $channels): array
    {
        $out = [];
        $p->loadMissing('reservation');
        if (in_array('email', $channels)) {
            $log = MailService::send($p->payer_email, new \App\Mail\PaymentRequest($p, hotel()), 'payment_request', $p->reservation);
            $out['email'] = [$log->status === 'sent', $log->status === 'sent' ? "e-mailed to {$log->to_email}" : 'e-mail failed: '.$log->error];
        }
        foreach (array_intersect($channels, ['sms', 'whatsapp']) as $ch) {
            $body = "Dear {$p->payer_name}, please complete your payment of ".$p->currency.' '.number_format((float) $p->amount, 2)." to ".hotel()->name." ({$p->description}): ".$p->url();
            $log = MessageService::send($p->payer_phone, $body, $ch, 'payment_link', $p->reservation);
            $out[$ch] = [$log->status === 'sent', $log->status === 'sent' ? strtoupper($ch)." sent to {$log->to}" : strtoupper($ch).' failed: '.$log->error];
        }
        if (collect($out)->contains(fn ($r) => $r[0])) {
            $p->forceFill(['sent_at' => now()])->save();
        }
        self::log($p, 'staff', 'sent', collect($out)->map(fn ($r) => $r[1])->implode(' · ') ?: 'Nothing sent');

        return $out;
    }

    // ------------------------------------------------------------------ paying

    /** Start (or restart) the hosted checkout; returns the URL to redirect the guest to. */
    public static function start(OnlinePayment $p): string
    {
        if (! $p->isPayable()) {
            throw new RuntimeException($p->status === 'paid' ? 'This payment has already been made.' : 'This payment link is no longer valid.');
        }
        if ($p->reservation && in_array($p->reservation->status, ['cancelled', 'no_show'])) {
            throw new RuntimeException('The reservation for this payment has been cancelled.');
        }
        if ($p->banquet_event_id && ($e = BanquetEvent::find($p->banquet_event_id)) && in_array($e->status, ['cancelled', 'lost'])) {
            throw new RuntimeException('The event for this payment has been cancelled.');
        }
        if ($p->provider !== self::provider() || ! self::enabled()) {
            throw new RuntimeException('Online payment is temporarily unavailable – please contact the hotel.');
        }
        $attempt = $p->attempts + 1;
        $ref = strtoupper(hotel()->code).'-'.$p->pay_no.'-'.$attempt.'-'.substr(bin2hex(random_bytes(3)), 0, 6);
        $res = self::gateway($p->provider)->checkout($p, $ref, route('pay.return', $p->token), route('pay.show', $p->token));
        $p->forceFill(['provider_ref' => $res['ref'], 'checkout_url' => $res['url'], 'attempts' => $attempt, 'last_error' => null])->save();
        self::log($p, 'api', 'checkout', "Checkout #{$attempt} started ({$res['ref']})");

        return $res['url'];
    }

    /** Browser came back from the gateway: confirm server-side, never from the query string alone. */
    public static function handleReturn(OnlinePayment $p, array $query): OnlinePayment
    {
        if (in_array($p->status, ['paid', 'review']) || ! $p->provider_ref) {
            return $p;
        }
        try {
            $res = self::gateway($p->provider)->verify($p, $query);
        } catch (Throwable $e) {
            self::log($p, 'return', 'verify_error', mb_substr($e->getMessage(), 0, 250));

            return $p;
        }

        return self::complete($p, $res, 'return');
    }

    /** Apply a verified gateway result exactly once. */
    public static function complete(OnlinePayment $p, array $res, string $source, ?string $eventId = null): OnlinePayment
    {
        $after = null;
        $p = DB::transaction(function () use ($p, $res, $source, $eventId, &$after) {
            $p = OnlinePayment::lockForUpdate()->findOrFail($p->id);
            $state = $res['state'] ?? 'pending';
            if (in_array($p->status, ['paid', 'review'])) {
                if ($state === 'paid') {
                    self::log($p, $source, 'duplicate', 'Already '.$p->status.' – ignored', null, null, $eventId);
                }

                return $p;
            }
            if ($state !== 'paid') {
                if ($state === 'failed') {
                    $p->forceFill(['last_error' => mb_substr($res['message'] ?? 'Payment failed.', 0, 250)])->save();
                }
                self::log($p, $source, $state === 'failed' ? 'failed' : 'pending', $res['message'] ?? ($state === 'failed' ? 'Payment failed' : 'Not completed yet'), null, null, $eventId);

                return $p;
            }
            $amount = round((float) ($res['amount'] ?? 0), 2);
            $problems = [];
            if (abs($amount - (float) $p->amount) > 0.009) {
                $problems[] = 'received '.($res['currency'] ?? '').' '.number_format($amount, 2).' instead of '.number_format((float) $p->amount, 2);
            }
            if (strtoupper((string) ($res['currency'] ?? '')) !== $p->currency) {
                $problems[] = 'currency '.($res['currency'] ?? '?').' instead of '.$p->currency;
            }
            if (in_array($p->status, ['expired', 'cancelled'])) {
                $problems[] = 'paid after the link was '.$p->status;
            }
            $p->forceFill(['amount_received' => $amount, 'provider_payment_id' => $res['payment_id'] ?? null, 'fee' => $res['fee'] ?? null,
                'channel' => $res['channel'] ?? null, 'paid_at' => now(), 'last_error' => null])->save();
            if ($problems) {
                $p->forceFill(['status' => 'review', 'last_error' => mb_substr('Needs review: '.implode('; ', $problems), 0, 250)])->save();
                self::log($p, $source, 'review', $p->last_error, null, null, $eventId);
                ActivityLogger::log('payments', 'review', "Online payment {$p->pay_no} needs review: ".implode('; ', $problems), $p);

                return $p;
            }
            if ($p->purpose === 'city_ledger') {
                $v = self::postLedger($p, $amount);
                $p->forceFill(['status' => 'paid'])->save();
                self::log($p, $source, 'paid', 'Paid '.$p->currency.' '.number_format($amount, 2).' – city-ledger receipt '.$v->voucher_no, null, null, $eventId);
            } elseif ($p->purpose === 'banquet') {
                $tx = self::postBanquet($p, $amount);
                $p->forceFill(['status' => 'paid', 'folio_transaction_id' => $tx->id])->save();
                self::log($p, $source, 'paid', 'Paid '.$p->currency.' '.number_format($amount, 2).' – posted to event folio '.$tx->folio->folio_no, null, null, $eventId);
            } else {
                $tx = self::post($p, $amount);
                $p->forceFill(['status' => 'paid', 'folio_transaction_id' => $tx->id])->save();
                self::log($p, $source, 'paid', 'Paid '.$p->currency.' '.number_format($amount, 2).' – posted to folio '.$tx->folio->folio_no, null, null, $eventId);
            }
            ActivityLogger::log('payments', 'paid', "Online payment {$p->pay_no} received: ".money($amount)." ({$p->provider})", $p);
            $after = self::confirmBooking($p);

            return $p;
        });
        if ($p->status === 'paid' && $p->wasChanged('status')) {
            $after && $after();
            if (setting('pay_receipt_email', '1') === '1' && $p->payer_email) {
                MailService::send($p->payer_email, new \App\Mail\PaymentReceipt($p->fresh('reservation'), hotel()), 'payment_receipt', $p->reservation);
            }
        }

        return $p;
    }

    /** Booking-engine deposit paid → tentative booking becomes confirmed; confirmation messages go out after commit. */
    protected static function confirmBooking(OnlinePayment $p): ?\Closure
    {
        $r = $p->reservation_id ? Reservation::find($p->reservation_id) : null;
        if (! $r || $r->status !== 'tentative' || $p->purpose !== 'booking') {
            return null;
        }
        $r->update(['status' => 'confirmed']);
        ActivityLogger::log('reservations', 'confirm', "Online booking {$r->reservation_no} confirmed – deposit {$p->pay_no} received", $r);

        return function () use ($r) {
            MailService::autoConfirm($r);
            MessageService::autoConfirm($r);
        };
    }

    /** Where the money goes: the link's folio if open; else the in-house stay folio, else the reservation's advance folio. */
    public static function targetFolio(OnlinePayment $p): Folio
    {
        if ($p->folio_id && ($f = Folio::find($p->folio_id)) && $f->status === 'open') {
            return $f;
        }
        if ($p->reservation_id) {
            $stay = ReservationRoom::where('reservation_id', $p->reservation_id)->where('status', 'checked_in')->orderBy('id')->first();
            if ($stay && ($f = Folio::where('reservation_room_id', $stay->id)->where('status', 'open')->orderBy('id')->first())) {
                return $f;
            }

            return Folio::where('reservation_id', $p->reservation_id)->whereNull('reservation_room_id')->where('status', 'open')->orderBy('id')->first()
                ?? FolioService::openStandalone(['reservation_id' => $p->reservation_id, 'guest_id' => $p->guest_id, 'type' => 'guest']);
        }

        return FolioService::openStandalone(['guest_id' => $p->guest_id, 'type' => 'guest']); // original folio closed meanwhile: a credit folio
    }

    /**
     * A link that settles a company's city-ledger balance (partner portal "Pay now"): not tied to a reservation or a folio.
     * On payment a receipt voucher is recorded against the company, exactly like a cheque keyed in Accounts › City Ledger.
     */
    public static function createCityLedger(\App\Models\Company $c, float $amount, array $d = []): OnlinePayment
    {
        if (! self::enabled()) {
            throw new RuntimeException('Online payments are switched off (Administration › Online Payment Settings).');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        if ($amount > 10000000) {
            throw new RuntimeException('Amount is too large for an online payment.');
        }
        $p = OnlinePayment::create([
            'pay_no' => Numbering::next('online_payment'), 'purpose' => 'city_ledger', 'company_id' => $c->id,
            'payer_name' => mb_substr(($d['name'] ?? null) ?: $c->name, 0, 150), 'payer_email' => ($d['email'] ?? null) ?: $c->email, 'payer_phone' => $d['phone'] ?? null,
            'description' => mb_substr(($d['description'] ?? null) ?: 'Account settlement – '.$c->name, 0, 190),
            'amount' => $amount, 'currency' => self::currency(), 'status' => 'pending', 'provider' => self::provider(),
            'expires_at' => now()->addMinutes(max(5, (int) ($d['minutes'] ?? 60))), 'created_by' => auth()->id(),
        ]);
        self::log($p, $d['source'] ?? 'portal', 'created', 'City-ledger link created for '.$p->currency.' '.number_format($amount, 2).' ('.$c->name.')');

        return $p;
    }

    /** Paid city-ledger link → receipt voucher against the company (reduces its outstanding, posts to the gateway clearing head). */
    protected static function postLedger(OnlinePayment $p, float $amount): Voucher
    {
        $c = \App\Models\Company::findOrFail($p->company_id);
        $v = Voucher::create(['voucher_no' => Numbering::next('voucher'), 'type' => 'receipt', 'date' => bdate()->toDateString(), 'amount' => $amount,
            'account_head_id' => AccountHead::sys('city_ledger'), 'payment_mode_id' => self::mode()->id, 'company_id' => $c->id, 'party_name' => $c->name,
            'reference' => mb_substr($p->pay_no.' '.($p->provider_payment_id ?: $p->provider_ref), 0, 60), 'narration' => 'Online payment against city ledger ('.$p->pay_no.')', 'user_id' => null]);
        ActivityLogger::log('accounts', 'cl_receipt', 'City ledger online receipt '.money($amount)." from {$c->name} ({$p->pay_no})", $c);

        return $v;
    }

    /**
     * v7.5 A link that pays an event's deposit or balance (organiser portal "Pay now"): not tied to a reservation.
     * On payment an advance is recorded on the event folio, exactly like a deposit keyed in Banquet › the event.
     */
    public static function createBanquet(BanquetEvent $e, float $amount, array $d = []): OnlinePayment
    {
        if (! self::enabled()) {
            throw new RuntimeException('Online payments are switched off (Administration › Online Payment Settings).');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        if ($amount > 10000000) {
            throw new RuntimeException('Amount is too large for an online payment.');
        }
        $p = OnlinePayment::create([
            'pay_no' => Numbering::next('online_payment'), 'purpose' => 'banquet', 'banquet_event_id' => $e->id, 'company_id' => $e->company_id,
            'payer_name' => mb_substr(($d['name'] ?? null) ?: $e->contact_name, 0, 150), 'payer_email' => ($d['email'] ?? null) ?: $e->contact_email, 'payer_phone' => $d['phone'] ?? null,
            'description' => mb_substr(($d['description'] ?? null) ?: 'Event payment – '.$e->event_no.' '.$e->title, 0, 190),
            'amount' => $amount, 'currency' => self::currency(), 'status' => 'pending', 'provider' => self::provider(),
            'expires_at' => now()->addMinutes(max(5, (int) ($d['minutes'] ?? 60))), 'created_by' => auth()->id(),
        ]);
        self::log($p, $d['source'] ?? 'portal', 'created', 'Event link created for '.$p->currency.' '.number_format($amount, 2).' ('.$e->event_no.')');

        return $p;
    }

    /** Paid event link → advance on the event folio with the Online Payment mode (gateway clearing, no shift needed). */
    protected static function postBanquet(OnlinePayment $p, float $amount): FolioTransaction
    {
        $e = BanquetEvent::findOrFail($p->banquet_event_id);
        $f = BanquetSalesService::folioFor($e);
        if ($f->status !== 'open') {
            throw new RuntimeException('The event folio '.$f->folio_no.' is closed.');
        }
        $tx = $f->transactions()->create([
            'hotel_id' => $f->hotel_id, 'business_date' => bdate()->toDateString(), 'type' => 'payment', 'category' => 'advance',
            'description' => "Deposit – {$e->event_no}", 'amount' => $amount, 'payment_mode_id' => self::mode()->id,
            'reference' => mb_substr(ucfirst($p->provider).' '.($p->provider_payment_id ?: $p->provider_ref), 0, 80),
            'source_type' => 'banquet', 'source_id' => $e->id, 'posted_by' => null,
        ]);
        ActivityLogger::log('banquet', 'deposit', "Online deposit ".money($amount)." on {$e->event_no} ({$p->pay_no})", $e);

        return $tx;
    }

    protected static function post(OnlinePayment $p, float $amount): FolioTransaction
    {
        $f = self::targetFolio($p);

        return $f->transactions()->create([
            'hotel_id' => $f->hotel_id, 'business_date' => bdate()->toDateString(), 'type' => 'payment',
            'category' => $f->reservation_room_id ? 'settlement' : 'advance',
            'description' => 'Online payment '.$p->pay_no, 'amount' => $amount, 'payment_mode_id' => self::mode()->id,
            'reference' => mb_substr(ucfirst($p->provider).' '.($p->provider_payment_id ?: $p->provider_ref), 0, 80),
            'source_type' => 'online_payment', 'source_id' => $p->id, 'posted_by' => null,
        ]);
    }

    /** Staff accept a payment that needed review: post what was actually received. */
    public static function accept(OnlinePayment $p, string $note): OnlinePayment
    {
        return DB::transaction(function () use ($p, $note) {
            $p = OnlinePayment::lockForUpdate()->findOrFail($p->id);
            if ($p->status !== 'review') {
                throw new RuntimeException('Only a payment under review can be accepted.');
            }
            if (strtoupper((string) $p->currency) !== self::currency()) {
                throw new RuntimeException('A payment in another currency cannot be posted – refund it.');
            }
            if ($p->purpose === 'city_ledger') {
                $v = self::postLedger($p, $p->received());
                $p->forceFill(['status' => 'paid', 'last_error' => null])->save();
                self::log($p, 'staff', 'accepted', 'Accepted '.money($p->received())." as city-ledger receipt {$v->voucher_no}: {$note}");
            } elseif ($p->purpose === 'banquet') {
                $tx = self::postBanquet($p, $p->received());
                $p->forceFill(['status' => 'paid', 'folio_transaction_id' => $tx->id, 'last_error' => null])->save();
                self::log($p, 'staff', 'accepted', 'Accepted '.money($p->received())." and posted to event folio {$tx->folio->folio_no}: {$note}");
            } else {
                $tx = self::post($p, $p->received());
                $p->forceFill(['status' => 'paid', 'folio_transaction_id' => $tx->id, 'last_error' => null])->save();
                self::log($p, 'staff', 'accepted', 'Accepted '.money($p->received())." and posted to folio {$tx->folio->folio_no}: {$note}");
            }
            ActivityLogger::log('payments', 'accept', "Accepted online payment {$p->pay_no} ({$note})", $p);

            return $p;
        });
    }

    public static function cancel(OnlinePayment $p, string $reason): OnlinePayment
    {
        if ($p->status !== 'pending') {
            throw new RuntimeException('Only an unpaid link can be cancelled.');
        }
        $p->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
        self::log($p, 'staff', 'cancelled', $reason);
        ActivityLogger::log('payments', 'cancel', "Cancelled payment link {$p->pay_no}: {$reason}", $p);

        return $p;
    }

    public static function refund(OnlinePayment $p, float $amount, string $reason): OnlineRefund
    {
        $amount = round($amount, 2);
        $p = OnlinePayment::with('folioTransaction.folio')->findOrFail($p->id);
        if ($p->status !== 'paid' || ! $p->folioTransaction) {
            throw new RuntimeException('Only a paid and posted online payment can be refunded.');
        }
        if ($amount <= 0 || $amount > $p->refundable() + 0.001) {
            throw new RuntimeException('Refund must be between 0.01 and '.money($p->refundable()).'.');
        }
        $folio = $p->folioTransaction->folio;
        if ($folio->status !== 'open') {
            throw new RuntimeException("Folio {$folio->folio_no} is closed – the refund cannot be posted to it.");
        }
        try {
            $res = self::gateway($p->provider)->refund($p, $amount);
        } catch (Throwable $e) {
            self::log($p, 'api', 'refund_failed', mb_substr($e->getMessage(), 0, 250));
            throw new RuntimeException($e->getMessage());
        }

        return DB::transaction(function () use ($p, $amount, $reason, $res, $folio) {
            $p = OnlinePayment::lockForUpdate()->findOrFail($p->id);
            $rf = OnlineRefund::create(['online_payment_id' => $p->id, 'amount' => $amount, 'reason' => $reason, 'status' => $res['status'], 'provider_ref' => $res['ref'], 'created_by' => auth()->id()]);
            $tx = $folio->transactions()->create([
                'hotel_id' => $folio->hotel_id, 'business_date' => bdate()->toDateString(), 'type' => 'refund', 'category' => 'settlement',
                'description' => 'Online refund '.$p->pay_no, 'amount' => $amount, 'payment_mode_id' => self::mode()->id,
                'reference' => mb_substr(ucfirst($p->provider).' '.($res['ref'] ?? ''), 0, 80), 'source_type' => 'online_refund', 'source_id' => $rf->id, 'posted_by' => auth()->id(),
            ]);
            $rf->update(['folio_transaction_id' => $tx->id]);
            $p->forceFill(['refunded_amount' => round((float) $p->refunded_amount + $amount, 2)])->save();
            self::log($p, 'api', 'refunded', 'Refunded '.money($amount).' ('.$res['status'].'): '.$reason);
            ActivityLogger::log('payments', 'refund', "Refunded ".money($amount)." on online payment {$p->pay_no}: {$reason}", $p);

            return $rf;
        });
    }

    // ------------------------------------------------------------------ webhooks & housekeeping

    /** @return array{0: int, 1: string} HTTP status + message */
    public static function webhook(string $provider, \Illuminate\Http\Request $request): array
    {
        $gw = self::gateway($provider);
        $base = ['hotel_id' => hotel()->id, 'provider' => $provider, 'source' => 'webhook'];
        try {
            $w = $gw->webhook($request);
        } catch (Payments\SignatureException $e) {
            OnlinePaymentEvent::create($base + ['event' => 'rejected', 'signature_ok' => false, 'result' => $e->getMessage(), 'payload' => self::redact($request->json()->all())]);

            return [401, 'invalid signature'];
        }
        if (! $w) {
            return [200, 'ignored'];
        }
        if (OnlinePaymentEvent::where('provider', $provider)->where('event_id', $w['event_id'])->exists()) {
            return [200, 'duplicate'];
        }
        $p = $w['ref'] ? OnlinePayment::where('provider', $provider)->where('provider_ref', $w['ref'])->first() : null;
        if (! $p || ! $w['result']) {
            OnlinePaymentEvent::create($base + ['online_payment_id' => $p?->id, 'event' => $w['event'], 'event_id' => $w['event_id'], 'signature_ok' => true,
                'result' => $p ? 'No action for this event' : 'Unknown reference '.$w['ref'], 'payload' => self::redact($request->json()->all())]);

            return [200, $p ? 'ignored' : 'unknown reference'];
        }
        try {
            self::complete($p, $w['result'], 'webhook', $w['event_id']);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return [200, 'duplicate'];
        }

        return [200, 'ok'];
    }

    protected static function redact(array $payload): array
    {
        array_walk_recursive($payload, function (&$v, $k) {
            if (in_array((string) $k, ['authorization', 'card', 'bin', 'last4', 'email', 'contact', 'phone', 'customer_email', 'signature', 'account_number'], true)) {
                $v = '•••';
            }
        });

        return $payload;
    }

    public static function log(OnlinePayment $p, string $source, string $event, ?string $result = null, ?array $payload = null, ?bool $sig = null, ?string $eventId = null): void
    {
        OnlinePaymentEvent::create(['online_payment_id' => $p->id, 'provider' => $p->provider, 'source' => $source, 'event' => $event, 'event_id' => $eventId,
            'signature_ok' => $sig ?? ($source === 'webhook' ? true : null), 'result' => $result ? mb_substr($result, 0, 255) : null, 'payload' => $payload,
            'user_id' => in_array($source, ['staff', 'api']) ? auth()->id() : null]);
    }

    /** Expire overdue links (asking the gateway first) and release unpaid online-booking holds. */
    public static function expire(): array
    {
        $stats = ['expired' => 0, 'paid' => 0, 'released' => 0];
        foreach (OnlinePayment::where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<', now())->orderBy('id')->get() as $p) {
            if ($p->provider_ref && $p->provider !== 'demo' && self::configured($p->provider)) {
                try {
                    $res = self::gateway($p->provider)->verify($p, []);
                    if (($res['state'] ?? '') === 'paid') {
                        self::complete($p, $res, 'system');
                        $stats['paid']++;

                        continue;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
            // The gateway check above is intentionally outside the database
            // transaction. Re-lock the payment before expiring it so two
            // scheduler workers cannot both release the same booking hold.
            [$expired, $released, $releasedReservation] = DB::transaction(function () use ($p) {
                $locked = OnlinePayment::whereKey($p->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'pending' || ($locked->expires_at && $locked->expires_at->isFuture())) {
                    return [false, false, null];
                }
                $locked->update(['status' => 'expired']);
                self::log($locked, 'system', 'expired', 'Link expired unpaid');
                $r = $locked->purpose === 'booking' && $locked->reservation_id
                    ? Reservation::whereKey($locked->reservation_id)->lockForUpdate()->first()
                    : null;
                if (! $r || $r->status !== 'tentative'
                    || OnlinePayment::where('reservation_id', $r->id)->whereIn('status', ['paid', 'review', 'pending'])->exists()) {
                    return [true, false, null];
                }
                ReservationRoom::where('reservation_id', $r->id)->where('status', 'reserved')->update(['status' => 'cancelled']);
                $r->update(['status' => 'cancelled', 'cancel_reason' => 'Online deposit not received in time', 'cancelled_at' => now()]);

                return [true, true, $r];
            });
            if ($expired) {
                $stats['expired']++;
            }
            if ($released) {
                ActivityLogger::log('reservations', 'cancel', "Online booking {$releasedReservation->reservation_no} released – deposit not received in time", $releasedReservation);
                $stats['released']++;
            }
        }

        return $stats;
    }

    // ------------------------------------------------------------------ booking engine

    public static function depositPolicy(): string
    {
        $p = (string) setting('pay_booking_deposit', 'none');

        return self::enabled() && array_key_exists($p, self::DEPOSIT_POLICIES) ? $p : 'none';
    }

    public static function bookingDeposit(array $quote, int $rooms): float
    {
        $nights = $quote['nights'] ?? [];

        return round(match (self::depositPolicy()) {
            'first_night' => ((float) ($nights[0]['rate'] ?? $quote['average'] ?? 0)) * $rooms,
            'percent' => (float) $quote['total'] * $rooms * max(1, min(100, (float) setting('pay_booking_deposit_pct', 30))) / 100,
            'full' => (float) $quote['total'] * $rooms,
            default => 0,
        }, 2);
    }

    // ------------------------------------------------------------------ settlement & control

    /** Online money collected but not yet paid out by the gateway (sub-ledger of 1050). */
    public static function clearingBalance(?string $asOf = null): float
    {
        $modes = PaymentMode::where('type', 'online')->pluck('id');
        $fx = FolioTransaction::where('is_void', false)->whereIn('payment_mode_id', $modes)->when($asOf, fn ($q) => $q->where('business_date', '<=', $asOf))
            ->selectRaw("SUM(CASE WHEN type='payment' THEN amount WHEN type='refund' THEN -amount ELSE 0 END) s")->value('s');
        $out = GatewayPayout::when($asOf, fn ($q) => $q->where('date', '<=', $asOf))->sum('gross');

        return round((float) $fx - (float) $out, 2);
    }

    public static function recordPayout(array $d): GatewayPayout
    {
        $gross = round((float) $d['gross'], 2);
        $fees = round((float) ($d['fees'] ?? 0), 2);
        if ($fees < 0 || $fees >= $gross) {
            throw new RuntimeException('Fees must be less than the gross amount.');
        }
        if ($gross > self::clearingBalance($d['date']) + 0.001) {
            throw new RuntimeException('The payout exceeds the unsettled online collections up to '.dmy($d['date']).' ('.money(self::clearingBalance($d['date'])).').');
        }
        if (($lock = LedgerService::lockDate()) && $d['date'] <= $lock) {
            throw new RuntimeException('The ledger is locked up to '.dmy($lock).'.');
        }
        $po = GatewayPayout::create(['payout_no' => Numbering::next('gateway_payout'), 'provider' => $d['provider'], 'date' => $d['date'], 'gross' => $gross, 'fees' => $fees,
            'payment_mode_id' => $d['payment_mode_id'], 'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id()]);
        self::postPayout($po);
        ActivityLogger::log('payments', 'payout', "Gateway payout {$po->payout_no}: ".money($gross).' less fees '.money($fees), $po);

        return $po;
    }

    public static function deletePayout(GatewayPayout $po): void
    {
        if (($lock = LedgerService::lockDate()) && $po->date->toDateString() <= $lock) {
            throw new RuntimeException('The ledger is locked up to '.dmy($lock).' – this payout can no longer be deleted.');
        }
        $id = (string) $po->id;
        $po->delete();
        if ($cutoff = setting('gl_cutoff')) {
            ChartOfAccounts::ensureLight();
            LedgerService::reconcile('gw_payout', $id, null, $cutoff);
        }
        ActivityLogger::log('payments', 'payout_delete', "Deleted gateway payout {$po->payout_no}");
    }

    /** Post straight away when the payout date is already in the closed (synced) period. */
    public static function postPayout(GatewayPayout $po): void
    {
        $cutoff = setting('gl_cutoff');
        if (! $cutoff || $po->date->toDateString() > $cutoff) {
            return;
        }
        try {
            ChartOfAccounts::ensureLight();
            LedgerService::reconcile('gw_payout', (string) $po->id, LedgerService::forGatewayPayout($po->fresh('paymentMode')), $cutoff);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function saveSecrets(array $data, array $clear): void
    {
        foreach (self::SECRETS as $k) {
            if (in_array($k, $clear)) {
                Setting::where('key', $k)->delete();
            } else {
                MessageService::storeSecret($k, $data[$k] ?? null);
            }
        }
        Setting::$version++;
    }
}
