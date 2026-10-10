<?php

namespace App\Services;

use App\Mail\GiftVoucherMail;
use App\Models\AccountHead;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GiftVoucher;
use App\Models\GiftVoucherTransaction;
use App\Models\GiftVoucherType;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v3.8 gift vouchers & stored-value cards (a closed-system prepaid instrument – spendable only at this hotel).
 *
 *  sale / reload      Dr cash · card · UPI (payment mode)   Cr 2120 Gift vouchers outstanding
 *  promotional issue  Dr 6150 Promotional vouchers          Cr 2120
 *  redemption         paid with the "Gift Voucher" payment mode on a folio or POS bill → Dr 2120 Cr guest ledger / sales
 *  expiry (breakage)  Dr 2120   Cr 4087 Voucher breakage income (promotional vouchers: Cr 6150 – the cost never arose)
 *  cancel & refund    Dr 2120   Cr the refund payment mode (promotional: Cr 6150)
 *
 * VAT: a voucher is a prepaid instrument, not a supply – no tax on the sale or on breakage;
 * tax is charged on the room / food bill that the voucher pays for.
 */
class GiftVoucherService
{
    public const DEFAULTS = [
        'gv_enabled' => '1',
        'gv_validity_months' => '12',      // default validity of a new voucher
        'gv_min_value' => '500',           // smallest open-amount voucher
        'gv_max_value' => '50000',         // largest balance one voucher may hold
        'gv_reminder_days' => '30',        // e-mail the holder this many days before expiry
        'gv_terms' => 'Valid for the amount shown at this hotel only, until the expiry date. Not exchangeable for cash; no change given in cash. Treat the code like cash – the hotel is not responsible for lost or stolen vouchers once redeemed with the code.',
    ];

    protected const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // no 0/O, 1/I

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::modeId();
        if (GiftVoucherType::count() === 0) {
            GiftVoucherType::create(['name' => 'Gift Card', 'kind' => 'value', 'face_value' => null, 'min_value' => 1000, 'max_value' => 50000, 'validity_months' => 12,
                'usable_on' => ['folio', 'pos'], 'color' => '#0f766e', 'terms' => 'Spend in parts on rooms, dining and bar until the balance is used up.']);
            GiftVoucherType::create(['name' => 'Dinner for Two', 'kind' => 'single', 'face_value' => 3500, 'min_value' => 0, 'max_value' => 0, 'validity_months' => 6,
                'usable_on' => ['pos'], 'color' => '#b45309', 'terms' => 'A three-course dinner for two at the restaurant, beverages extra. One visit; any unused value lapses.']);
            GiftVoucherType::create(['name' => 'Stay Voucher – One Night', 'kind' => 'value', 'face_value' => 7500, 'min_value' => 0, 'max_value' => 0, 'validity_months' => 12,
                'usable_on' => ['folio'], 'color' => '#1d4ed8', 'terms' => 'Towards a room stay, subject to availability. Blackout dates may apply.']);
            GiftVoucherType::create(['name' => 'Stored-Value Card', 'kind' => 'stored', 'face_value' => null, 'min_value' => 500, 'max_value' => 50000, 'validity_months' => 12,
                'usable_on' => ['folio', 'pos'], 'color' => '#6d28d9', 'terms' => 'Reloadable. Each reload extends the validity.']);
        }
    }

    public static function cfg(): array
    {
        $g = fn ($k) => (string) setting($k, self::DEFAULTS[$k]);

        return [
            'enabled' => $g('gv_enabled') === '1',
            'validity' => max(1, min(120, (int) $g('gv_validity_months'))),
            'min' => max(0, (float) $g('gv_min_value')),
            'max' => max(1, (float) $g('gv_max_value')),
            'reminder_days' => max(0, (int) $g('gv_reminder_days')),
            'terms' => $g('gv_terms'),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $min = max(0, round((float) ($d['min'] ?? 500), 2));
        $max = max(1, round((float) ($d['max'] ?? 50000), 2));
        if ($min > $max) {
            throw new RuntimeException('The minimum voucher value cannot exceed the maximum.');
        }
        $map = ['gv_enabled' => ! empty($d['enabled']) ? '1' : '0', 'gv_validity_months' => (string) max(1, min(120, (int) ($d['validity'] ?? 12))),
            'gv_min_value' => (string) $min, 'gv_max_value' => (string) $max, 'gv_reminder_days' => (string) max(0, min(180, (int) ($d['reminder_days'] ?? 30))),
            'gv_terms' => mb_substr(trim((string) ($d['terms'] ?? '')), 0, 1000) ?: self::DEFAULTS['gv_terms']];
        foreach ($map as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('vouchers', 'settings', 'Gift voucher settings updated');
    }

    /** the "Gift Voucher" payment mode – redemptions on folios and POS bills are paid with it */
    public static function modeId(): int
    {
        return (int) (PaymentMode::where('type', 'gift_voucher')->value('id') ?? PaymentMode::create(['name' => 'Gift Voucher', 'type' => 'gift_voucher'])->id);
    }

    public static function isVoucherMode(?int $modeId): bool
    {
        return $modeId !== null && PaymentMode::whereKey($modeId)->where('type', 'gift_voucher')->exists();
    }

    protected static function assertEnabled(): void
    {
        if (! self::cfg()['enabled']) {
            throw new RuntimeException('Gift vouchers are switched off (Gift Vouchers › Settings).');
        }
    }

    // ───────────────────────── codes ─────────────────────────

    public static function newCode(): string
    {
        do {
            $s = '';
            for ($i = 0; $i < 8; $i++) {
                $s .= self::ALPHABET[random_int(0, 31)];
            }
            $code = 'GV-'.substr($s, 0, 4).'-'.substr($s, 4);
        } while (GiftVoucher::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    /** "gv 7k3m-q9zx", "GV7K3MQ9ZX" … → "GV-7K3M-Q9ZX" (null when it cannot be a voucher code) */
    public static function normalise(?string $code): ?string
    {
        $s = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $code));
        if (str_starts_with($s, 'GV')) {
            $s = substr($s, 2);
        }

        return strlen($s) === 8 ? 'GV-'.substr($s, 0, 4).'-'.substr($s, 4) : null;
    }

    public static function find(?string $code): ?GiftVoucher
    {
        $c = self::normalise($code);

        return $c ? GiftVoucher::with('type')->where('code', $c)->first() : null;
    }

    protected static function mustFind(?string $code): GiftVoucher
    {
        return self::find($code) ?? throw new RuntimeException('No gift voucher with code “'.mb_substr(trim((string) $code), 0, 20).'” at this hotel.');
    }

    // ───────────────────────── issuing ─────────────────────────

    protected static function amountFor(GiftVoucherType $type, $amount): float
    {
        $c = self::cfg();
        $v = $type->face_value ?: round((float) $amount, 2);
        if ($v <= 0) {
            throw new RuntimeException('Enter the voucher amount.');
        }
        if (! $type->face_value) {
            $min = max($type->min_value, $c['min']);
            if ($v < $min) {
                throw new RuntimeException('The smallest '.$type->name.' is '.money($min).'.');
            }
        }
        $max = $type->max_value > 0 ? min($type->max_value, $c['max']) : $c['max'];
        if ($v > $max) {
            throw new RuntimeException('A voucher can hold at most '.money($max).'.');
        }

        return $v;
    }

    protected static function expiry(GiftVoucherType $type, $override = null): Carbon
    {
        if ($override) {
            $d = Carbon::parse($override)->startOfDay();
            if ($d->lt(bdate()->copy()->startOfDay())) {
                throw new RuntimeException('The expiry date cannot be in the past.');
            }
            if ($d->gt(bdate()->copy()->addYears(5))) {
                throw new RuntimeException('Vouchers can be valid for at most 5 years.');
            }

            return $d;
        }

        return bdate()->copy()->addMonthsNoOverflow($type->validity_months ?: self::cfg()['validity'])->startOfDay();
    }

    protected static function activeType(int $typeId): GiftVoucherType
    {
        $t = GiftVoucherType::find($typeId);
        if (! $t || ! $t->is_active) {
            throw new RuntimeException('Choose an active voucher type.');
        }

        return $t;
    }

    protected static function txn(GiftVoucher $v, string $type, float $amount, array $extra = []): GiftVoucherTransaction
    {
        return GiftVoucherTransaction::create(['gift_voucher_id' => $v->id, 'type' => $type, 'amount' => round($amount, 2), 'balance_after' => round($v->balance, 2),
            'business_date' => bdate()->toDateString(), 'user_id' => auth()->id()] + $extra);
    }

    protected static function contact(array $d): array
    {
        $email = fn ($k) => ($e = trim((string) ($d[$k] ?? ''))) !== '' ? (filter_var($e, FILTER_VALIDATE_EMAIL) ? mb_substr($e, 0, 150) : throw new RuntimeException("“{$e}” is not a valid e-mail address.")) : null;

        return ['purchaser_name' => mb_substr(trim((string) ($d['purchaser_name'] ?? '')), 0, 100) ?: null, 'purchaser_email' => $email('purchaser_email'),
            'purchaser_phone' => mb_substr(trim((string) ($d['purchaser_phone'] ?? '')), 0, 30) ?: null, 'recipient_name' => mb_substr(trim((string) ($d['recipient_name'] ?? '')), 0, 100) ?: null,
            'recipient_email' => $email('recipient_email'), 'message' => mb_substr(trim((string) ($d['message'] ?? '')), 0, 300) ?: null,
            'guest_id' => ! empty($d['guest_id']) ? (int) $d['guest_id'] : null];
    }

    /** sell a voucher for money (cash / card / UPI / bank) */
    public static function sell(array $d): GiftVoucher
    {
        self::assertEnabled();
        $type = self::activeType((int) ($d['gift_voucher_type_id'] ?? 0));
        $amount = self::amountFor($type, $d['amount'] ?? 0);
        $mode = PaymentMode::find((int) ($d['payment_mode_id'] ?? 0));
        if (! $mode || in_array($mode->type, ['gift_voucher', 'city_ledger', 'forex'], true)) {
            throw new RuntimeException('Choose how the voucher was paid for (cash, card, UPI or bank).');
        }
        $contact = self::contact($d);
        if (! $contact['purchaser_name']) {
            throw new RuntimeException('Enter the purchaser’s name.');
        }
        ShiftService::assertOpen();
        $v = DB::transaction(function () use ($d, $type, $amount, $mode, $contact) {
            $v = GiftVoucher::create(['code' => self::newCode(), 'gift_voucher_type_id' => $type->id, 'source' => 'sold', 'face_value' => $amount, 'balance' => $amount,
                'status' => 'active', 'issued_on' => bdate()->toDateString(), 'expires_on' => self::expiry($type, $d['expires_on'] ?? null)->toDateString(),
                'payment_mode_id' => $mode->id, 'payment_ref' => mb_substr(trim((string) ($d['payment_ref'] ?? '')), 0, 80) ?: null, 'issued_by' => auth()->id()] + $contact);
            self::txn($v, 'issue', $amount, ['payment_mode_id' => $mode->id, 'reference' => $v->payment_ref, 'note' => 'Sold to '.$contact['purchaser_name']]);
            ActivityLogger::log('vouchers', 'sell', "Gift voucher {$v->code} ({$type->name}) sold for ".money($amount).' – '.$mode->name, $v);

            return $v;
        });
        if (! empty($d['send_email']) && ($to = $v->recipient_email ?: $v->purchaser_email)) {
            self::sendEmail($v, $to);
        }

        return $v->fresh('type');
    }

    /**
     * Free vouchers: a promotional campaign (count × value) or service recovery. $recipients = [[name, email], …] issues one
     * voucher per recipient and e-mails it.
     *
     * @return Collection<GiftVoucher>
     */
    public static function issuePromo(array $d, int $count = 1, array $recipients = []): Collection
    {
        self::assertEnabled();
        $type = self::activeType((int) ($d['gift_voucher_type_id'] ?? 0));
        $amount = self::amountFor($type, $d['amount'] ?? 0);
        $campaign = mb_substr(trim((string) ($d['campaign'] ?? '')), 0, 60);
        if (mb_strlen($campaign) < 3) {
            throw new RuntimeException('Name the campaign or reason (e.g. “Diwali 2026”, “Service recovery – room 204”).');
        }
        $recipients = array_values(array_filter($recipients, fn ($r) => trim((string) ($r[0] ?? '')) !== '' || trim((string) ($r[1] ?? '')) !== ''));
        foreach ($recipients as $i => $r) {
            if (($e = trim((string) ($r[1] ?? ''))) !== '' && ! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Recipient '.($i + 1).': “'.$e.'” is not a valid e-mail address.');
            }
        }
        $n = $recipients ? count($recipients) : $count;
        if ($n < 1 || $n > 500) {
            throw new RuntimeException('Issue between 1 and 500 vouchers at a time.');
        }
        $expires = self::expiry($type, $d['expires_on'] ?? null)->toDateString();
        $message = mb_substr(trim((string) ($d['message'] ?? '')), 0, 300) ?: null;
        $out = DB::transaction(function () use ($n, $recipients, $type, $amount, $campaign, $expires, $message, $d) {
            $out = collect();
            for ($i = 0; $i < $n; $i++) {
                $r = $recipients[$i] ?? null;
                $v = GiftVoucher::create(['code' => self::newCode(), 'gift_voucher_type_id' => $type->id, 'source' => 'promo', 'campaign' => $campaign, 'face_value' => $amount,
                    'balance' => $amount, 'status' => 'active', 'issued_on' => bdate()->toDateString(), 'expires_on' => $expires, 'message' => $message,
                    'recipient_name' => $r ? (mb_substr(trim((string) $r[0]), 0, 100) ?: null) : (mb_substr(trim((string) ($d['recipient_name'] ?? '')), 0, 100) ?: null),
                    'recipient_email' => $r ? (trim((string) ($r[1] ?? '')) ?: null) : null, 'guest_id' => ! empty($d['guest_id']) ? (int) $d['guest_id'] : null, 'issued_by' => auth()->id()]);
                self::txn($v, 'issue', $amount, ['note' => 'Promotional – '.$campaign]);
                $out->push($v);
            }
            ActivityLogger::log('vouchers', 'promo', "{$n} promotional voucher(s) “{$campaign}” issued – ".money($amount).' each', $out->first());

            return $out;
        });
        foreach ($out as $v) {
            if ($v->recipient_email) {
                self::sendEmail($v, $v->recipient_email);
            }
        }

        return $out->map->fresh('type');
    }

    /** top up a stored-value card; the validity restarts from today */
    public static function reload(GiftVoucher $v, float $amount, int $modeId, ?string $ref = null): GiftVoucherTransaction
    {
        self::assertEnabled();
        $v->loadMissing('type');
        if ($v->type->kind !== 'stored') {
            throw new RuntimeException('Only stored-value cards can be reloaded – sell a new voucher instead.');
        }
        if (! in_array($v->status, ['active', 'redeemed'], true) || $v->isExpired()) {
            throw new RuntimeException("{$v->code} is {$v->statusLabel()}".($v->isExpired() ? ' / expired' : '').' – it cannot be reloaded.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Enter the reload amount.');
        }
        $max = $v->type->max_value > 0 ? min($v->type->max_value, self::cfg()['max']) : self::cfg()['max'];
        if ($v->balance + $amount > $max + 0.004) {
            throw new RuntimeException('A card can hold at most '.money($max).' – the balance is '.money($v->balance).'.');
        }
        $mode = PaymentMode::find($modeId);
        if (! $mode || in_array($mode->type, ['gift_voucher', 'city_ledger', 'forex'], true)) {
            throw new RuntimeException('Choose how the reload was paid for.');
        }
        ShiftService::assertOpen();

        return DB::transaction(function () use ($v, $amount, $mode, $ref) {
            $v = GiftVoucher::lockForUpdate()->findOrFail($v->id);
            $newExpiry = bdate()->copy()->addMonthsNoOverflow($v->type->validity_months ?: self::cfg()['validity']);
            $v->update(['balance' => round($v->balance + $amount, 2), 'status' => 'active', 'expires_on' => $newExpiry->max($v->expires_on)->toDateString(), 'reminded_at' => null]);
            $t = self::txn($v, 'reload', $amount, ['payment_mode_id' => $mode->id, 'reference' => $ref ? mb_substr($ref, 0, 80) : null]);
            ActivityLogger::log('vouchers', 'reload', "{$v->code} reloaded with ".money($amount).' – balance '.money($v->balance), $v);

            return $t;
        });
    }

    // ───────────────────────── redemption ─────────────────────────

    /**
     * Take $amount off the voucher for a bill. $where = folio | pos. Single-use vouchers close on their first use (the unused
     * rest lapses as breakage).
     */
    public static function redeem(GiftVoucher $v, float $amount, string $where, array $link = []): GiftVoucherTransaction
    {
        self::assertEnabled();
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('The amount to redeem must be greater than zero.');
        }

        return DB::transaction(function () use ($v, $amount, $where, $link) {
            $v = GiftVoucher::with('type')->lockForUpdate()->findOrFail($v->id);
            if ($v->status === 'suspended') {
                throw new RuntimeException("{$v->code} is suspended".($v->status_note ? " ({$v->status_note})" : '').' – it cannot be used.');
            }
            if ($v->status !== 'active' || $v->balance <= 0) {
                throw new RuntimeException("{$v->code} is {$v->statusLabel()} – nothing left to redeem.");
            }
            if ($v->isExpired()) {
                throw new RuntimeException("{$v->code} expired on ".$v->expires_on->format('d M Y').'.');
            }
            if (! $v->type->usableOn($where)) {
                throw new RuntimeException("A {$v->type->name} can only be used on: ".$v->type->usesLabel().'.');
            }
            if ($amount > $v->balance + 0.004) {
                throw new RuntimeException("{$v->code} has only ".money($v->balance).' left – take the rest by another payment mode.');
            }
            $v->balance = round($v->balance - $amount, 2);
            $v->status = $v->balance <= 0 ? 'redeemed' : 'active';
            $v->save();
            $t = self::txn($v, 'redeem', $amount, $link);
            if ($v->type->kind === 'single' && $v->balance > 0) {
                $rest = $v->balance;
                $v->update(['balance' => 0, 'status' => 'redeemed']);
                self::txn($v, 'expire', $rest, ['note' => 'Unused remainder of a single-use voucher']);
            }
            ActivityLogger::log('vouchers', 'redeem', "{$v->code}: ".money($amount).' redeemed'.(! empty($link['outlet']) ? ' at '.$link['outlet'] : '').' – balance '.money($v->fresh()->balance), $v);

            return $t;
        });
    }

    /**
     * A folio payment / refund made with the Gift Voucher payment mode (called by FolioService::payment). The voucher code is
     * the payment reference. A refund puts the money back on the voucher (only what this folio took from it).
     */
    public static function folioPayment(Folio $folio, float $amount, ?string $code, string $category = 'settlement', string $type = 'payment'): FolioTransaction
    {
        $v = self::mustFind($code);
        $modeId = self::modeId();

        return DB::transaction(function () use ($folio, $amount, $v, $modeId, $category, $type) {
            $tx = $folio->transactions()->create([
                'hotel_id' => $folio->hotel_id, 'business_date' => bdate()->toDateString(), 'type' => $type, 'category' => $category,
                'description' => $type === 'refund' ? 'Refund to gift voucher '.$v->code : ($category === 'advance' ? 'Advance – gift voucher '.$v->code : 'Gift voucher '.$v->code),
                'amount' => round($amount, 2), 'payment_mode_id' => $modeId, 'reference' => $v->code, 'posted_by' => auth()->id(),
                'source_type' => 'gift_voucher', 'source_id' => $v->id,
            ]);
            if ($type === 'refund') {
                self::reverse($v, $amount, $folio, $tx);
            } else {
                self::redeem($v, $amount, 'folio', ['folio_transaction_id' => $tx->id, 'outlet' => 'Front office', 'reference' => $folio->folio_no]);
            }

            return $tx;
        });
    }

    /** money back onto a voucher: at most what this folio has taken from it */
    protected static function reverse(GiftVoucher $v, float $amount, Folio $folio, FolioTransaction $tx): GiftVoucherTransaction
    {
        return DB::transaction(function () use ($v, $amount, $folio, $tx) {
            $v = GiftVoucher::lockForUpdate()->findOrFail($v->id);
            $txIds = $folio->transactions()->pluck('id');
            $net = (float) GiftVoucherTransaction::where('gift_voucher_id', $v->id)->whereIn('folio_transaction_id', $txIds)->where('type', 'redeem')->sum('amount')
                - (float) GiftVoucherTransaction::where('gift_voucher_id', $v->id)->whereIn('folio_transaction_id', $txIds)->where('type', 'reversal')->sum('amount');
            if ($amount > $net + 0.004) {
                throw new RuntimeException('Only '.money(max(0, $net)).' from '.$v->code.' was used on this bill – that is the most that can go back on it.');
            }
            if (in_array($v->status, ['cancelled', 'expired'], true)) {
                throw new RuntimeException("{$v->code} is {$v->statusLabel()} – refund by another payment mode.");
            }
            $v->update(['balance' => round($v->balance + $amount, 2), 'status' => $v->status === 'suspended' ? 'suspended' : 'active']);
            $t = self::txn($v, 'reversal', $amount, ['folio_transaction_id' => $tx->id, 'outlet' => 'Front office', 'reference' => $folio->folio_no]);
            ActivityLogger::log('vouchers', 'reversal', "{$v->code}: ".money($amount).' put back from folio '.$folio->folio_no, $v);

            return $t;
        });
    }

    /** a voided folio line that was paid with a voucher gives the money back (or takes it again for a voided refund) */
    public static function onFolioVoid(FolioTransaction $tx): void
    {
        if ($tx->source_type !== 'gift_voucher' || ! ($v = GiftVoucher::find($tx->source_id))) {
            return;
        }
        DB::transaction(function () use ($tx, $v) {
            $v = GiftVoucher::lockForUpdate()->findOrFail($v->id);
            $amount = (float) $tx->amount;
            if ($tx->type === 'refund') {
                $v->update(['balance' => max(0, round($v->balance - $amount, 2)), 'status' => $v->balance - $amount <= 0.004 ? 'redeemed' : $v->status]);
                self::txn($v, 'redeem', $amount, ['folio_transaction_id' => $tx->id, 'outlet' => 'Front office', 'note' => 'Voided refund']);
            } else {
                $v->update(['balance' => round($v->balance + $amount, 2), 'status' => in_array($v->status, ['redeemed', 'active'], true) ? 'active' : $v->status]);
                self::txn($v, 'reversal', $amount, ['folio_transaction_id' => $tx->id, 'outlet' => 'Front office', 'note' => 'Folio payment voided: '.mb_substr((string) $tx->void_reason, 0, 150)]);
            }
        });
    }

    /** POS payment with the Gift Voucher mode: deduct the amount allocated to this payment, not necessarily the whole bill. */
    public static function posPayment(PosOrder $order, ?string $code, ?float $amount = null): GiftVoucherTransaction
    {
        $v = self::mustFind($code);
        $amount = round($amount ?? (float) $order->total, 2);
        if ($amount <= 0) {
            throw new RuntimeException('The voucher payment must be greater than zero.');
        }

        return self::redeem($v, $amount, 'pos', ['pos_order_id' => $order->id, 'outlet' => $order->outlet?->name, 'reference' => $order->bill_no]);
    }

    /** Return a POS voucher payment to the original voucher, limited to its unused redemption on this order. */
    public static function posRefund(PosOrder $order, float $amount): GiftVoucherTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('The voucher refund must be greater than zero.');
        }

        return DB::transaction(function () use ($order, $amount) {
            $redeems = GiftVoucherTransaction::where('pos_order_id', $order->id)->where('type', 'redeem')->oldest('id')->get();
            $redeem = $redeems->first();
            if (! $redeem) {
                throw new RuntimeException('The original gift voucher redemption could not be found.');
            }
            if ($redeems->pluck('gift_voucher_id')->unique()->count() > 1) {
                throw new RuntimeException('This POS bill has more than one original gift voucher; refund it through the voucher ledger.');
            }
            $v = GiftVoucher::lockForUpdate()->findOrFail($redeem->gift_voucher_id);
            $used = (float) $redeems->sum('amount')
                - (float) GiftVoucherTransaction::where('pos_order_id', $order->id)->where('gift_voucher_id', $v->id)->where('type', 'reversal')->sum('amount');
            if ($amount > $used + 0.004) {
                throw new RuntimeException('Only '.money(max(0, $used)).' from the voucher was used on this POS bill – that is the most that can be returned.');
            }
            if (in_array($v->status, ['cancelled', 'expired'], true)) {
                throw new RuntimeException("{$v->code} is {$v->statusLabel()} – refund by another payment mode.");
            }
            if ($v->isExpired()) {
                throw new RuntimeException("{$v->code} expired on ".$v->expires_on->format('d M Y')." – refund by another payment mode.");
            }
            $v->balance = round((float) $v->balance + $amount, 2);
            $v->status = $v->status === 'suspended' ? 'suspended' : 'active';
            $v->save();

            return self::txn($v, 'reversal', $amount, ['pos_order_id' => $order->id, 'outlet' => $order->outlet?->name, 'reference' => $order->bill_no, 'note' => 'POS payment refund']);
        });
    }

    // ───────────────────────── life-cycle ─────────────────────────

    public static function suspend(GiftVoucher $v, string $reason): GiftVoucher
    {
        if ($v->status !== 'active') {
            throw new RuntimeException("Only an active voucher can be suspended ({$v->code} is {$v->statusLabel()}).");
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new RuntimeException('Say why the voucher is being suspended (e.g. reported lost).');
        }
        $v->update(['status' => 'suspended', 'status_note' => mb_substr(trim($reason), 0, 200)]);
        ActivityLogger::log('vouchers', 'suspend', "{$v->code} suspended: {$reason}", $v);

        return $v;
    }

    public static function unsuspend(GiftVoucher $v): GiftVoucher
    {
        if ($v->status !== 'suspended') {
            throw new RuntimeException('The voucher is not suspended.');
        }
        $v->update(['status' => $v->balance > 0 ? 'active' : 'redeemed', 'status_note' => null]);
        ActivityLogger::log('vouchers', 'unsuspend', "{$v->code} re-activated", $v);

        return $v;
    }

    /** cancel the unused balance – a sold voucher is refunded to the purchaser, a promotional one simply withdrawn */
    public static function cancel(GiftVoucher $v, string $reason, ?int $refundModeId = null): GiftVoucherTransaction
    {
        if (! in_array($v->status, ['active', 'suspended'], true) || $v->balance <= 0) {
            throw new RuntimeException("{$v->code} is {$v->statusLabel()} – there is no balance to cancel.");
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new RuntimeException('Give the reason for cancelling.');
        }
        $mode = null;
        if ($v->source === 'sold') {
            $mode = PaymentMode::find((int) $refundModeId);
            if (! $mode || in_array($mode->type, ['gift_voucher', 'city_ledger', 'forex'], true)) {
                throw new RuntimeException('Choose how the balance is refunded to the purchaser.');
            }
            ShiftService::assertOpen();
        }

        return DB::transaction(function () use ($v, $reason, $mode) {
            $v = GiftVoucher::lockForUpdate()->findOrFail($v->id);
            $amount = $v->balance;
            $v->update(['balance' => 0, 'status' => 'cancelled', 'status_note' => mb_substr(trim($reason), 0, 200)]);
            $t = self::txn($v, 'cancel', $amount, ['payment_mode_id' => $mode?->id, 'note' => mb_substr(trim($reason), 0, 200)]);
            ActivityLogger::log('vouchers', 'cancel', "{$v->code} cancelled – ".money($amount).($mode ? ' refunded by '.$mode->name : ' withdrawn').": {$reason}", $v);

            return $t;
        });
    }

    /** vouchers past their expiry date lose the unused balance (breakage) – night audit & pms:vouchers */
    public static function expireDue(): array
    {
        $today = bdate()->toDateString();
        $n = 0;
        $amount = 0.0;
        foreach (GiftVoucher::whereIn('status', ['active', 'suspended'])->where('expires_on', '<', $today)->orderBy('id')->get() as $v) {
            DB::transaction(function () use ($v, &$n, &$amount) {
                $v = GiftVoucher::lockForUpdate()->findOrFail($v->id);
                $rest = $v->balance;
                $v->update(['balance' => 0, 'status' => 'expired']);
                if ($rest > 0) {
                    self::txn($v, 'expire', $rest, ['note' => 'Expired on '.$v->expires_on->format('d M Y')]);
                    $amount += $rest;
                }
                $n++;
            });
        }
        if ($n) {
            ActivityLogger::log('vouchers', 'expire', "{$n} gift voucher(s) expired – ".money($amount).' unused (breakage)');
        }

        return ['count' => $n, 'amount' => round($amount, 2)];
    }

    /** e-mail holders whose voucher still has a balance and expires within the reminder window (once per voucher) */
    public static function remindExpiring(): int
    {
        $days = self::cfg()['reminder_days'];
        if (! $days) {
            return 0;
        }
        $n = 0;
        foreach (GiftVoucher::with('type')->where('status', 'active')->where('balance', '>', 0)->whereNull('reminded_at')
            ->whereBetween('expires_on', [bdate()->toDateString(), bdate()->copy()->addDays($days)->toDateString()])->get() as $v) {
            $to = $v->recipient_email ?: $v->purchaser_email;
            if (! $to) {
                continue;
            }
            $log = MailService::send($to, new GiftVoucherMail($v, hotel(), true), 'gift_voucher_reminder', $v);
            if ($log->status === 'sent') {
                $v->update(['reminded_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    public static function sendEmail(GiftVoucher $v, ?string $to = null): \App\Models\EmailLog
    {
        $to = trim((string) ($to ?: ($v->recipient_email ?: $v->purchaser_email)));
        $log = MailService::send($to, new GiftVoucherMail($v->loadMissing('type'), hotel()), 'gift_voucher', $v);
        if ($log->status === 'sent') {
            $v->update(['emailed_at' => now()]);
        }

        return $log;
    }

    // ───────────────────────── general ledger ─────────────────────────

    public static function forTransaction(GiftVoucherTransaction $t): ?array
    {
        $v = $t->voucher;
        if (! $v || in_array($t->type, ['redeem', 'reversal'], true) || $t->amount <= 0) {
            return null;   // redemptions post through the folio / POS payment (Gift Voucher mode → 2120)
        }
        $liab = AccountHead::sys('voucher_liability');
        $promo = AccountHead::sys('voucher_promo');
        $other = match ($t->type) {
            'issue', 'reload' => $t->payment_mode_id ? LedgerService::modeAccount($t->payment_mode_id) : $promo,
            'expire' => $v->source === 'promo' ? $promo : AccountHead::sys('voucher_breakage'),
            'cancel' => $t->payment_mode_id ? LedgerService::modeAccount($t->payment_mode_id) : $promo,
            default => null,
        };
        if (! $other) {
            return null;
        }
        $in = in_array($t->type, ['issue', 'reload'], true);
        $label = GiftVoucherTransaction::TYPES[$t->type] ?? $t->type;

        return ['date' => $t->business_date->toDateString(), 'reference' => $v->code,
            'narration' => 'Gift voucher '.$v->code.' – '.strtolower($label).($v->source === 'promo' ? ' (promotional'.($v->campaign ? ': '.$v->campaign : '').')' : ''),
            'lines' => $in ? [[$other, $t->amount, $t->paymentMode?->name], [$liab, -$t->amount, $v->code]]
                : [[$liab, $t->amount, $v->code], [$other, -$t->amount, $t->type === 'cancel' ? $t->paymentMode?->name : null]]];
    }

    // ───────────────────────── figures ─────────────────────────

    /** outstanding balance (liability) as at the end of a date, rebuilt from the transactions */
    public static function liabilityAt(string $date): float
    {
        $rows = GiftVoucherTransaction::where('business_date', '<=', $date)->selectRaw('type, SUM(amount) a')->groupBy('type')->pluck('a', 'type');
        $s = 0.0;
        foreach ($rows as $type => $a) {
            $s += (GiftVoucherTransaction::SIGN[$type] ?? 0) * (float) $a;
        }

        return round($s, 2);
    }

    public static function snapshot(): array
    {
        $today = bdate()->toDateString();
        $days = self::cfg()['reminder_days'] ?: 30;
        $live = GiftVoucher::whereIn('status', ['active', 'suspended']);
        $t = GiftVoucherTransaction::where('business_date', $today);
        $exp = GiftVoucher::where('status', 'active')->where('balance', '>', 0)->whereBetween('expires_on', [$today, bdate()->copy()->addDays($days)->toDateString()]);

        return [
            'outstanding' => round((float) (clone $live)->sum('balance'), 2), 'live' => (clone $live)->where('balance', '>', 0)->count(),
            'sold_today' => round((float) (clone $t)->whereIn('type', ['issue', 'reload'])->whereNotNull('payment_mode_id')->sum('amount'), 2),
            'redeemed_today' => round((float) (clone $t)->where('type', 'redeem')->sum('amount') - (float) (clone $t)->where('type', 'reversal')->sum('amount'), 2),
            'expiring' => (clone $exp)->count(), 'expiring_value' => round((float) (clone $exp)->sum('balance'), 2), 'expiring_days' => $days,
            'suspended' => GiftVoucher::where('status', 'suspended')->count(),
        ];
    }

    public static function expiringCount(): int
    {
        $days = self::cfg()['reminder_days'] ?: 30;

        return GiftVoucher::where('status', 'active')->where('balance', '>', 0)->whereBetween('expires_on', [bdate()->toDateString(), bdate()->copy()->addDays($days)->toDateString()])->count();
    }
}
