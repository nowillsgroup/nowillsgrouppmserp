<?php

namespace App\Services;

use App\Models\BanquetEvent;
use App\Models\BanquetFunction;
use App\Models\BanquetHall;
use App\Models\BanquetPackage;
use App\Models\BanquetService as ServiceItem;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\PaymentMode;
use App\Models\Setting;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v2.5 banquet & conference sales.
 *
 * Pipeline: enquiry → tentative (holds the venue until the option date) → definite → completed (billed); or lost /
 * cancelled. Tentative and definite functions hold their hall (with a turnaround buffer). Deposits are real
 * payments ('advance') on the event's own folio, so they appear on the cashier shift and in the ledger as a guest-
 * ledger credit; the final bill posts the functions onto the same folio, which is then settled or sent to the city
 * ledger like any other folio. Pax billed = the guarantee (locked n days out) or the actual count if higher.
 */
class BanquetSalesService
{
    public const DEFAULTS = [
        'banquet_guarantee_days' => '3', 'banquet_turnaround_minutes' => '60', 'banquet_half_day_hours' => '5',
        'banquet_cancel_policy' => '60:0,30:25,14:50,7:75,0:100', 'banquet_deposit_pct' => '25', 'banquet_hall_tax_rate' => '21',
        'banquet_terms' => "A deposit confirms the booking. Final numbers (guarantee) are due 3 days before the function; we charge for the guarantee or the actual attendance, whichever is higher.\nCancellation: 60+ days before – no charge; 30–59 days – 25%; 14–29 days – 50%; 7–13 days – 75%; under 7 days – 100% of the estimated value.\nOutside food and beverages are not permitted. Music must end by 23:00. Prices are subject to applicable taxes.",
    ];

    // ------------------------------------------------------------------ save

    /**
     * Create or update an event with its functions and extras.
     *
     * @param  array{contact_name:string, functions:array<int, array>}  $d
     */
    public static function save(array $d, ?BanquetEvent $e = null): BanquetEvent
    {
        if ($e && ! $e->isEditable()) {
            throw ValidationException::withMessages(['event' => 'This event is '.$e->status.' – it can no longer be changed.']);
        }
        $status = $e?->status ?? (($d['status'] ?? 'enquiry') === 'tentative' ? 'tentative' : 'enquiry');
        if (! $e && $status === 'tentative') {
            self::assertOptionDate($d['option_date'] ?? null);
        }
        $existing = $e ? $e->functions()->with('items')->get()->keyBy('id') : collect();
        $halls = BanquetHall::all()->keyBy('id');
        $packages = BanquetPackage::all()->keyBy('id');
        $services = ServiceItem::all()->keyBy('id');
        $rows = [];
        foreach (array_values($d['functions'] ?? []) as $i => $f) {
            $n = $i + 1;
            $old = ! empty($f['id']) ? $existing->get((int) $f['id']) : null;
            if (! empty($f['id']) && ! $old) {
                throw ValidationException::withMessages(["functions.$i" => "Function $n does not belong to this event."]);
            }
            $hall = $halls->get((int) ($f['banquet_hall_id'] ?? 0));
            if (! $hall) {
                throw ValidationException::withMessages(["functions.$i.banquet_hall_id" => "Pick a venue for function $n."]);
            }
            $name = trim((string) ($f['name'] ?? '')) ?: 'Function '.$n;
            $date = Carbon::parse($f['date']);
            if ($date->lt(bdate()) && (! $old || ! $old->date->eq($date))) {
                throw ValidationException::withMessages(["functions.$i.date" => "$name: the date is in the past."]);
            }
            $start = substr((string) $f['start_time'], 0, 5);
            $end = substr((string) $f['end_time'], 0, 5);
            if ($end <= $start) {
                throw ValidationException::withMessages(["functions.$i.end_time" => "$name: the end time must be after the start time."]);
            }
            $style = array_key_exists($f['setup_style'] ?? '', BanquetFunction::SETUPS) ? $f['setup_style'] : 'banquet';
            $pax = (int) $f['expected_pax'];
            $cap = $hall->capacityFor($style);
            if ($pax > $cap) {
                throw ValidationException::withMessages(["functions.$i.expected_pax" => "$name: {$hall->name} takes {$cap} guests ".strtolower(BanquetFunction::SETUPS[$style]).'-style.']);
            }
            $guar = isset($f['guaranteed_pax']) && $f['guaranteed_pax'] !== '' && $f['guaranteed_pax'] !== null ? (int) $f['guaranteed_pax'] : null;
            if ($guar !== null && $guar > $cap) {
                throw ValidationException::withMessages(["functions.$i.guaranteed_pax" => "$name: the guarantee is more than {$hall->name} takes ({$cap})."]);
            }
            if ($old && $old->guaranteeLocked() && $old->guaranteed_pax !== null && ($guar === null || $guar < $old->guaranteed_pax)) {
                throw ValidationException::withMessages(["functions.$i.guaranteed_pax" => "$name: the guarantee of {$old->guaranteed_pax} is final since ".$old->guaranteeCutoff()->format('d M').' – it can go up, not down.']);
            }
            $pkg = ! empty($f['banquet_package_id']) ? $packages->get((int) $f['banquet_package_id']) : null;
            if (! empty($f['banquet_package_id']) && ! $pkg) {
                throw ValidationException::withMessages(["functions.$i.banquet_package_id" => "$name: unknown menu package."]);
            }
            if ($pkg && $pkg->min_pax && max($pax, (int) $guar) < $pkg->min_pax) {
                throw ValidationException::withMessages(["functions.$i.banquet_package_id" => "$name: {$pkg->name} needs at least {$pkg->min_pax} guests."]);
            }
            $hours = (strtotime($end) - strtotime($start)) / 3600;
            $items = [];
            foreach (array_values($f['items'] ?? []) as $it) {
                $svc = ! empty($it['banquet_service_id']) ? $services->get((int) $it['banquet_service_id']) : null;
                $desc = trim((string) ($it['description'] ?? '')) ?: $svc?->name;
                $qty = (float) ($it['qty'] ?? 0);
                if (! $desc || $qty <= 0) {
                    continue;
                }
                $items[] = ['banquet_service_id' => $svc?->id, 'description' => mb_substr($desc, 0, 150), 'qty' => round($qty, 2),
                    'unit_price' => round(isset($it['unit_price']) && $it['unit_price'] !== '' ? (float) $it['unit_price'] : (float) $svc?->price, 2),
                    'tax_rate' => isset($it['tax_rate']) && $it['tax_rate'] !== '' ? (float) $it['tax_rate'] : (float) ($svc?->tax_rate ?? TaxService::rateFor('fnb', 0))];
            }
            $rows[] = ['old' => $old, 'attrs' => [
                'banquet_hall_id' => $hall->id, 'name' => mb_substr($name, 0, 120), 'date' => $date->toDateString(), 'start_time' => $start, 'end_time' => $end,
                'setup_style' => $style, 'expected_pax' => $pax, 'guaranteed_pax' => $guar, 'banquet_package_id' => $pkg?->id,
                'rate_per_pax' => round(isset($f['rate_per_pax']) && $f['rate_per_pax'] !== '' ? (float) $f['rate_per_pax'] : (float) $pkg?->price_per_pax, 2),
                'fnb_tax_rate' => (float) ($pkg?->tax_rate ?? TaxService::rateFor('fnb', 0)),
                'hall_rate' => round(isset($f['hall_rate']) && $f['hall_rate'] !== '' ? (float) $f['hall_rate'] : $hall->rentFor($hours), 2),
                'hall_tax_rate' => (float) setting('banquet_hall_tax_rate', '21'), 'notes' => ($f['notes'] ?? null) ? mb_substr(trim($f['notes']), 0, 3000) : null, 'sort' => $i,
            ], 'items' => $items];
        }
        if (! $rows) {
            throw ValidationException::withMessages(['functions' => 'Add at least one function (date, time and venue).']);
        }
        if (in_array($status, BanquetEvent::HOLDS, true)) {
            self::assertNoClash(collect($rows)->map(fn ($r) => (object) ($r['attrs'] + ['id' => $r['old']?->id])), $e?->id);
        }

        return DB::transaction(function () use ($d, $e, $status, $rows, $existing) {
            $header = [
                'company_id' => $d['company_id'] ?? null, 'contact_name' => $d['contact_name'], 'contact_phone' => $d['contact_phone'] ?? null,
                'contact_email' => $d['contact_email'] ?? null, 'event_type' => $d['event_type'] ?? 'other', 'title' => $d['title'] ?? null,
                'source' => $d['source'] ?? null, 'sales_user_id' => $d['sales_user_id'] ?? null, 'room_block_id' => $d['room_block_id'] ?? null,
                'deposit_due' => round((float) ($d['deposit_due'] ?? 0), 2), 'deposit_due_date' => $d['deposit_due_date'] ?? null,
                'requirements' => $d['requirements'] ?? null, 'internal_notes' => $d['internal_notes'] ?? null,
            ];
            $first = collect($rows)->sortBy(fn ($r) => $r['attrs']['date'].$r['attrs']['start_time'])->first()['attrs'];
            $header += ['banquet_hall_id' => $first['banquet_hall_id'], 'event_date' => $first['date'], 'end_date' => collect($rows)->max(fn ($r) => $r['attrs']['date']),
                'start_time' => $first['start_time'], 'end_time' => $first['end_time'], 'expected_pax' => collect($rows)->max(fn ($r) => $r['attrs']['expected_pax']),
                'guaranteed_pax' => (int) collect($rows)->max(fn ($r) => $r['attrs']['guaranteed_pax']), 'rate_per_pax' => $first['rate_per_pax'], 'hall_charge' => $first['hall_rate'], 'tax_rate' => 18];
            if ($e) {
                $e->update($header);
            } else {
                $e = BanquetEvent::create($header + ['event_no' => Numbering::next('banquet'), 'status' => $status,
                    'option_date' => $status === 'tentative' ? $d['option_date'] : null, 'created_by' => auth()->id()]);
            }
            $keep = [];
            foreach ($rows as $r) {
                $fn = $r['old'] ? tap($r['old'])->update($r['attrs']) : $e->functions()->create($r['attrs'] + ['hotel_id' => $e->hotel_id]);
                $keep[] = $fn->id;
                $fn->items()->delete();
                foreach ($r['items'] as $it) {
                    $fn->items()->create($it + ['hotel_id' => $e->hotel_id]);
                }
            }
            BanquetFunction::where('banquet_event_id', $e->id)->whereNotIn('id', $keep)->delete();

            return $e->fresh(['functions.items', 'functions.hall', 'functions.package']);
        });
    }

    // ------------------------------------------------------------------ venue clashes

    /**
     * Functions (of tentative / definite events) in the hall that overlap the slot, allowing the turnaround buffer.
     *
     * @param  list<int>  $exceptFunctionIds
     */
    public static function clashes(int $hallId, string $date, string $start, string $end, array $exceptFunctionIds = []): Collection
    {
        $buf = (int) setting('banquet_turnaround_minutes', '60');
        $s = self::hhmm(max(0, self::mins($start) - $buf)); // clamp to the day – never wrap past midnight
        $en = self::hhmm(min(24 * 60 - 1, self::mins($end) + $buf));

        return BanquetFunction::with('event')->where('banquet_hall_id', $hallId)->whereDate('date', $date)
            ->whereHas('event', fn ($q) => $q->whereIn('status', BanquetEvent::HOLDS))
            ->when($exceptFunctionIds, fn ($q) => $q->whereNotIn('id', $exceptFunctionIds))
            ->where('start_time', '<', $en)->where('end_time', '>', $s)->get();
    }

    /** @param Collection<object> $fns candidate functions (attrs + id) of one event */
    protected static function assertNoClash(Collection $fns, ?int $eventId): void
    {
        $ownIds = $eventId ? BanquetFunction::where('banquet_event_id', $eventId)->pluck('id')->all() : [];
        $buf = (int) setting('banquet_turnaround_minutes', '60');
        foreach ($fns->values() as $i => $f) {
            $c = self::clashes($f->banquet_hall_id, $f->date, $f->start_time, $f->end_time, $ownIds)->first();
            if ($c) {
                $hall = BanquetHall::find($f->banquet_hall_id);
                throw ValidationException::withMessages(["functions.$i.banquet_hall_id" => "{$f->name}: {$hall->name} is held for {$c->event->event_no} ".($c->event->title ?: $c->event->contact_name)
                    ." ({$c->timeRange()}, ".($c->event->status).") – allow {$buf} min to turn the room round."]);
            }
            foreach ($fns->values() as $j => $g) { // two functions of the same event in one room
                if ($j > $i && (int) $g->banquet_hall_id === (int) $f->banquet_hall_id && $g->date === $f->date
                    && self::mins($g->start_time) < self::mins($f->end_time) + $buf && self::mins($g->end_time) + $buf > self::mins($f->start_time)) {
                    throw ValidationException::withMessages(["functions.$j.start_time" => "{$g->name} overlaps {$f->name} in the same venue."]);
                }
            }
        }
    }

    protected static function mins(string $t): int
    {
        [$h, $m] = array_map('intval', explode(':', $t) + [1 => 0]);

        return $h * 60 + $m;
    }

    protected static function hhmm(int $m): string
    {
        return sprintf('%02d:%02d:%s', intdiv($m, 60), $m % 60, $m >= 24 * 60 - 1 ? '59' : '00');
    }

    // ------------------------------------------------------------------ pipeline

    protected static function assertOptionDate(?string $date): void
    {
        if (! $date) {
            throw ValidationException::withMessages(['option_date' => 'A tentative hold needs an option date – the day the client has to confirm by.']);
        }
        if (Carbon::parse($date)->lt(bdate())) {
            throw ValidationException::withMessages(['option_date' => 'The option date is in the past.']);
        }
    }

    public static function holdTentative(BanquetEvent $e, string $optionDate): BanquetEvent
    {
        if (! in_array($e->status, ['enquiry', 'tentative'], true)) {
            throw ValidationException::withMessages(['status' => 'Only an enquiry can be put on a tentative hold.']);
        }
        self::assertOptionDate($optionDate);
        if (Carbon::parse($optionDate)->gt($e->event_date)) {
            throw ValidationException::withMessages(['option_date' => 'The option date has to be before the event.']);
        }
        if ($e->status === 'enquiry') {
            self::assertNoClash($e->functions()->get()->map(fn ($f) => (object) ['id' => $f->id, 'name' => $f->name, 'banquet_hall_id' => $f->banquet_hall_id,
                'date' => $f->date->toDateString(), 'start_time' => substr($f->start_time, 0, 5), 'end_time' => substr($f->end_time, 0, 5)]), $e->id);
        }
        $was = $e->status;
        $e->update(['status' => 'tentative', 'option_date' => $optionDate]);
        ActivityLogger::log('banquet', 'tentative', ($was === 'tentative' ? 'Option extended' : 'Tentative hold')." on {$e->event_no} until ".Carbon::parse($optionDate)->format('d M Y'), $e);

        return $e;
    }

    public static function confirm(BanquetEvent $e): BanquetEvent
    {
        if (! in_array($e->status, ['enquiry', 'tentative'], true)) {
            throw ValidationException::withMessages(['status' => 'Only an enquiry or a tentative booking can be confirmed.']);
        }
        if ($e->functions()->whereDate('date', '<', bdate()->toDateString())->exists()) {
            throw ValidationException::withMessages(['status' => 'A function date has already passed – correct the dates first.']);
        }
        $paid = $e->depositsReceived();
        if ($e->deposit_due > 0 && $paid + 0.005 < $e->deposit_due) {
            throw ValidationException::withMessages(['status' => 'The deposit of '.money($e->deposit_due).' has not been received yet ('.money($paid).' so far). Take the deposit first, or change the deposit required.']);
        }
        if ($e->status === 'enquiry') {
            self::assertNoClash($e->functions()->get()->map(fn ($f) => (object) ['id' => $f->id, 'name' => $f->name, 'banquet_hall_id' => $f->banquet_hall_id,
                'date' => $f->date->toDateString(), 'start_time' => substr($f->start_time, 0, 5), 'end_time' => substr($f->end_time, 0, 5)]), $e->id);
        }
        $e->update(['status' => 'definite', 'option_date' => null, 'confirmed_at' => now()]);
        ActivityLogger::log('banquet', 'definite', "Banquet {$e->event_no} confirmed (definite)", $e);

        return $e;
    }

    public static function markLost(BanquetEvent $e, string $reason): BanquetEvent
    {
        if (! in_array($e->status, ['enquiry', 'tentative'], true)) {
            throw ValidationException::withMessages(['status' => 'Only enquiries and tentative bookings can be marked lost – cancel a definite booking instead.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['lost_reason' => 'Say why the business was lost.']);
        }
        if ($e->depositsReceived() > 0) {
            throw ValidationException::withMessages(['status' => 'A deposit has been taken – refund it on the event folio first.']);
        }
        $e->update(['status' => 'lost', 'lost_reason' => mb_substr(trim($reason), 0, 255), 'option_date' => null, 'closed_at' => now(), 'closed_by' => auth()->id()]);
        ActivityLogger::log('banquet', 'lost', "Banquet {$e->event_no} lost: {$reason}", $e);

        return $e;
    }

    public static function reopen(BanquetEvent $e): BanquetEvent
    {
        if ($e->status !== 'lost') {
            throw ValidationException::withMessages(['status' => 'Only lost business can be re-opened.']);
        }
        $e->update(['status' => 'enquiry', 'lost_reason' => null, 'closed_at' => null, 'closed_by' => null]);
        ActivityLogger::log('banquet', 'reopen', "Banquet {$e->event_no} re-opened as an enquiry", $e);

        return $e;
    }

    /** Tentative holds past their option date release the venue (night audit / daily job). */
    public static function releaseExpired(): int
    {
        $n = 0;
        foreach (BanquetEvent::where('status', 'tentative')->whereNotNull('option_date')->whereDate('option_date', '<', bdate()->toDateString())->get() as $e) {
            if ($e->depositsReceived() > 0) {
                continue; // money taken: leave it for the sales team to decide
            }
            $e->update(['status' => 'lost', 'lost_reason' => 'Option expired on '.$e->option_date->format('d M Y').' – venue released', 'closed_at' => now()]);
            ActivityLogger::log('banquet', 'released', "Tentative hold {$e->event_no} released – option expired", $e);
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ money

    public static function folioFor(BanquetEvent $e): Folio
    {
        if ($e->folio_id && ($f = Folio::find($e->folio_id))) {
            return $f;
        }
        $guest = $e->guest_id ? Guest::find($e->guest_id) : null;
        if (! $guest) {
            $parts = preg_split('/\s+/', trim($e->contact_name), 2);
            $guest = Guest::firstOrCreate(['first_name' => $parts[0], 'last_name' => $parts[1] ?? null, 'phone' => $e->contact_phone], ['email' => $e->contact_email]);
        }
        $folio = FolioService::openStandalone(['guest_id' => $guest->id, 'company_id' => $e->company_id, 'type' => 'banquet']);
        $e->update(['folio_id' => $folio->id, 'guest_id' => $guest->id]);

        return $folio;
    }

    public static function takeDeposit(BanquetEvent $e, float $amount, int $modeId, ?string $reference = null, ?array $cheque = null): FolioTransaction
    {
        return self::takeDeposits($e, [[
            'amount' => $amount, 'payment_mode_id' => $modeId, 'reference' => $reference, 'cheque' => $cheque ?? [],
        ]])->first();
    }

    /** Record one or more separately auditable tender lines for a banquet deposit. */
    public static function takeDeposits(BanquetEvent $e, array $lines): Collection
    {
        if (! $e->isOpen() || $e->billed_at) {
            throw ValidationException::withMessages(['amount' => 'Deposits can only be taken on open bookings.']);
        }
        if (! $lines) {
            throw ValidationException::withMessages(['amount' => 'Enter at least one deposit tender.']);
        }
        foreach ($lines as $i => $line) {
            $amountField = count($lines) > 1 ? "payments.{$i}.amount" : 'amount';
            $modeField = count($lines) > 1 ? "payments.{$i}.payment_mode_id" : 'payment_mode_id';
            if ((float) ($line['amount'] ?? 0) <= 0) {
                throw ValidationException::withMessages([$amountField => 'Enter an amount received.']);
            }
            $mode = PaymentMode::find((int) ($line['payment_mode_id'] ?? 0));
            if (! $mode || ! $mode->is_active || in_array($mode->type, ['city_ledger', 'online', 'gift_voucher'], true)) {
                throw ValidationException::withMessages([$modeField => 'Take the deposit in cash, card, UPI, bank or wallet – send a payment link from the folio for online payment.']);
            }
        }
        try {
            return DB::transaction(function () use ($e, $lines) {
                $folio = self::folioFor($e);
                $transactions = collect();
                foreach ($lines as $line) {
                    $amount = round((float) $line['amount'], 2);
                    $tx = FolioService::payment($folio, $amount, (int) $line['payment_mode_id'], $line['reference'] ?? null, 'advance', 'payment', $line['cheque'] ?? []);
                    $tx->update(['description' => "Deposit – {$e->event_no}", 'source_type' => 'banquet', 'source_id' => $e->id]);
                    $transactions->push($tx);
                }
                ActivityLogger::log('banquet', 'deposit', "Deposit ".money($transactions->sum('amount'))." on {$e->event_no} (".$transactions->count().' tender(s))', $e);

                return $transactions;
            });
        } catch (\RuntimeException $x) {
            throw ValidationException::withMessages(['amount' => $x->getMessage()]);
        }
    }

    /** Refund up to what the folio holds in credit (deposit not used by charges / fees). */
    public static function refundDeposit(BanquetEvent $e, float $amount, int $modeId, ?string $reference = null, ?array $cheque = null): FolioTransaction
    {
        $folio = $e->folio_id ? Folio::find($e->folio_id) : null;
        $credit = $folio ? round(-$folio->balance(), 2) : 0;
        if (! $folio || $credit <= 0) {
            throw ValidationException::withMessages(['refund' => 'There is no unused deposit to refund.']);
        }
        if ($amount <= 0 || $amount > $credit + 0.005) {
            throw ValidationException::withMessages(['refund' => 'Refund between 0 and '.money($credit).' (the unused deposit).']);
        }
        $mode = PaymentMode::find($modeId);
        if (! $mode || in_array($mode->type, ['city_ledger', 'online', 'gift_voucher'], true)) {
            throw ValidationException::withMessages(['payment_mode_id' => 'Refund in cash, card, UPI, bank or wallet.']);
        }
        try {
            return DB::transaction(function () use ($e, $folio, $amount, $modeId, $reference, $cheque) {
                $tx = FolioService::payment($folio, $amount, $modeId, $reference, 'advance', 'refund', $cheque);
                $tx->update(['description' => "Deposit refund – {$e->event_no}", 'source_type' => 'banquet', 'source_id' => $e->id]);
                ActivityLogger::log('banquet', 'refund', 'Deposit refund '.money($amount)." on {$e->event_no}", $e);
                if (in_array($e->status, ['cancelled', 'lost'], true) && abs($folio->fresh()->balance()) < 0.01) {
                    FolioService::close($folio->fresh());
                }

                return $tx;
            });
        } catch (\RuntimeException $x) {
            throw ValidationException::withMessages(['refund' => $x->getMessage()]);
        }
    }

    /** @param array<int|string, int|string|null> $pax function_id => actual attendance */
    public static function recordActuals(BanquetEvent $e, array $pax): int
    {
        if ($e->status !== 'definite' || $e->billed_at) {
            throw ValidationException::withMessages(['actual' => 'Attendance is recorded on definite, unbilled events.']);
        }
        $n = 0;
        foreach ($e->functions as $f) {
            if (! array_key_exists($f->id, $pax) || $pax[$f->id] === null || $pax[$f->id] === '') {
                continue;
            }
            if ($f->date->gt(bdate())) {
                throw ValidationException::withMessages(['actual' => "{$f->name} hasn't taken place yet."]);
            }
            $v = (int) $pax[$f->id];
            if ($v < 0 || $v > $f->hall->capacity) {
                throw ValidationException::withMessages(['actual' => "{$f->name}: enter between 0 and {$f->hall->capacity}."]);
            }
            $f->update(['actual_pax' => $v]);
            $n++;
        }
        ActivityLogger::log('banquet', 'actuals', "Attendance recorded on {$e->event_no}", $e);

        return $n;
    }

    /** Post every function onto the event folio and complete the event. */
    public static function bill(BanquetEvent $e): Folio
    {
        $e->load('functions.items', 'functions.hall', 'functions.package');
        if (! $e->readyToBill()) {
            throw ValidationException::withMessages(['bill' => $e->billed_at ? 'Already billed.' : ($e->status !== 'definite' ? 'Only definite events are billed.' : 'Bill once the last function has taken place ('.$e->end_date?->format('d M').').')]);
        }
        $missing = $e->functions->whereNull('actual_pax')->pluck('name');
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['bill' => 'Record the actual attendance first: '.$missing->join(', ').'.']);
        }
        $multi = $e->functions->pluck('date')->map->toDateString()->unique()->count() > 1;

        return DB::transaction(function () use ($e, $multi) {
            $folio = self::folioFor($e);
            foreach ($e->functions as $f) {
                $tag = $f->name.($multi ? ' ('.$f->date->format('d M').')' : '');
                $src = ['source_type' => 'banquet_function', 'source_id' => $f->id];
                if ($f->fnbAmount() > 0) {
                    FolioService::charge($folio, 'banquet', "$tag – ".($f->package?->name ?? 'Food & beverage')." ({$f->billablePax()} pax × ".rtrim(rtrim(number_format($f->rate_per_pax, 2, '.', ''), '0'), '.').')',
                        $f->fnbAmount(), false, $src + ['tax_rate' => $f->fnb_tax_rate]);
                }
                if ($f->hall_rate > 0) {
                    FolioService::charge($folio, 'banquet', "$tag – venue: {$f->hall->name}", $f->hall_rate, false, $src + ['tax_rate' => $f->hall_tax_rate]);
                }
                foreach ($f->items as $it) {
                    FolioService::charge($folio, 'banquet', "$tag – {$it->description}".($it->qty != 1 ? ' × '.rtrim(rtrim(number_format($it->qty, 2, '.', ''), '0'), '.') : ''),
                        $it->amount(), false, $src + ['tax_rate' => $it->tax_rate]);
                }
            }
            $e->update(['status' => 'completed', 'billed_at' => now(), 'closed_at' => now(), 'closed_by' => auth()->id()]);
            ActivityLogger::log('banquet', 'bill', "Billed banquet {$e->event_no} to {$folio->folio_no} (".money($e->total()).')', $e);

            return $folio;
        });
    }

    // ------------------------------------------------------------------ cancellation

    /** @return array{days:int, pct:float, base:float, fee:float} */
    public static function cancellationQuote(BanquetEvent $e): array
    {
        $e->loadMissing('functions.items');
        $days = (int) bdate()->diffInDays($e->event_date, false);
        $pct = 0.0;
        $tiers = collect(explode(',', (string) setting('banquet_cancel_policy', self::DEFAULTS['banquet_cancel_policy'])))
            ->map(fn ($t) => array_map('floatval', explode(':', trim($t)) + [1 => 0]))->sortByDesc(fn ($t) => $t[0]);
        foreach ($tiers as [$d, $p]) {
            if ($days >= $d) {
                $pct = $p;
                break;
            }
        }
        $base = $e->net();

        return ['days' => $days, 'pct' => $pct, 'base' => $base, 'fee' => round($base * $pct / 100, 2)];
    }

    public static function cancel(BanquetEvent $e, string $reason, bool $waive = false, ?string $waiveReason = null): BanquetEvent
    {
        if ($e->status !== 'definite' || $e->billed_at) {
            throw ValidationException::withMessages(['cancel' => $e->status === 'tentative' || $e->status === 'enquiry' ? 'Mark an enquiry or tentative booking as lost instead.' : 'Only definite, unbilled bookings can be cancelled.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['cancel_reason' => 'Give the reason for the cancellation.']);
        }
        if ($waive && trim((string) $waiveReason) === '') {
            throw ValidationException::withMessages(['waive_reason' => 'Say why the cancellation fee is waived.']);
        }
        $q = self::cancellationQuote($e);
        $fee = $waive ? 0.0 : $q['fee'];

        return DB::transaction(function () use ($e, $reason, $waive, $waiveReason, $q, $fee) {
            if ($fee > 0) {
                $folio = self::folioFor($e);
                $tx = FolioService::charge($folio, 'cancellation', "Cancellation fee – {$e->event_no} ({$q['pct']}% of ".money($q['base']).", {$q['days']} days before)", $fee, false, ['tax_rate' => (float) setting('banquet_hall_tax_rate', '21')]);
                $tx->update(['source_type' => 'banquet', 'source_id' => $e->id]);
            }
            $e->update(['status' => 'cancelled', 'cancellation_fee' => $fee, 'cancel_reason' => mb_substr(trim($reason).($waive ? ' · fee waived: '.trim($waiveReason) : ''), 0, 255),
                'closed_at' => now(), 'closed_by' => auth()->id()]);
            ActivityLogger::log('banquet', 'cancel', "Banquet {$e->event_no} cancelled – fee ".money($fee).($waive ? ' (waived)' : ''), $e);

            return $e;
        });
    }

    // ------------------------------------------------------------------ diary & defaults

    /**
     * Functions per hall per day for the diary.
     *
     * @return Collection<string, Collection> keyed "hallId|Y-m-d"
     */
    public static function diary(Carbon $from, Carbon $to, bool $withEnquiries = true): Collection
    {
        return BanquetFunction::with('event')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->whereHas('event', fn ($q) => $q->whereIn('status', $withEnquiries ? ['enquiry', 'tentative', 'definite', 'completed'] : ['tentative', 'definite', 'completed']))
            ->orderBy('start_time')->get()->groupBy(fn ($f) => $f->banquet_hall_id.'|'.$f->date->toDateString());
    }

    /** Events to bill + tentative holds whose option date is within two days (or gone). */
    public static function actionCount(): int
    {
        $today = bdate()->toDateString();

        return BanquetEvent::where('status', 'definite')->whereNull('billed_at')->whereDate('end_date', '<=', $today)->count()
            + BanquetEvent::where('status', 'tentative')->whereDate('option_date', '<=', bdate()->copy()->addDays(2)->toDateString())->count();
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        BanquetHall::where('rate_full_day', 0)->where('rate_per_session', '>', 0)->update(['rate_full_day' => DB::raw('rate_per_session')]);
        Setting::$version++;
    }
}
