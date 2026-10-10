<?php

namespace App\Services;

use App\Models\AuditDay;
use App\Models\AuditFinding;
use App\Models\CashierShift;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\NightAudit;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\RateContract;
use App\Models\RatePlan;
use App\Models\ReservationRoom;
use App\Models\RoomBlock;
use App\Models\Room;
use App\Models\RoomCount;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.3 Income audit & revenue integrity. Every closed business date gets an audit: automated checks raise exceptions
 * (rate variance against the rate plan / contract / block / rack rate, complimentary rooms, missing or duplicate room
 * tariffs, large discounts, voids, cashier variances, bills left open, departures not settled, credit limits, E-VAT
 * clearance, room-count discrepancies and figures that changed after the night audit), the auditor explains / corrects /
 * escalates each one, re-runs the checks, signs the day, and the financial controller closes it (four-eyes). A closed
 * day is locked: transactions dated on it can no longer be voided – corrections go through today's business date.
 */
class IncomeAuditService
{
    public const SETTINGS = [
        'ia_rate_tolerance_pct' => ['Rate variance tolerance (% either side of the expected rate)', 10],
        'ia_discount_limit' => ['Discounts / allowances above this need an authorisation (GH₵)', 200],
        'ia_cash_tolerance' => ['Cashier over / short tolerance (GH₵)', 10],
        'ia_inhouse_limit' => ['In-house guest balance limit before an exception (GH₵)', 10000],
        'ia_authorise_limit' => ['Managers may authorise revenue given away up to (GH₵) – above: GM / controller', 2000],
        'ia_comp_nights_limit' => ['Managers may authorise complimentary nights up to', 2],
        'ia_four_eyes' => ['Four-eyes: no approving your own request or closing a day you signed (1 = on)', 1],
        'ia_lock_closed' => ['Lock closed days: no voids of transactions dated on them (1 = on)', 1],
    ];

    /** key => [label, severity, what it looks for] */
    public const CHECKS = [
        'missing_tariff' => ['Room tariff missing / duplicated', 'high', 'In-house room nights without exactly one room-tariff posting'],
        'zero_rate' => ['Complimentary / zero-rate room', 'high', 'Occupied rooms charged nothing without a comp or house-use authorisation'],
        'rate_variance' => ['Rate variance', 'medium', 'Room tariff differs from the rate plan, contract, block or rack rate by more than the tolerance'],
        'discount' => ['Discount / allowance', 'medium', 'Folio discounts and allowances above the limit without an authorisation'],
        'void' => ['Void / cancellation', 'medium', 'Folio postings voided, POS bills voided and items cancelled after the KOT'],
        'cash_variance' => ['Cashier over / short', 'medium', 'Closed cashier shifts whose counted cash differs from expected by more than the tolerance'],
        'open_pos' => ['POS bill left open', 'medium', 'Restaurant / bar bills still open after the day was closed'],
        'unsettled' => ['Departure not settled', 'high', 'Guests checked out with a balance still on an open folio'],
        'credit_limit' => ['In-house balance over limit', 'low', 'Guests billed to themselves owing more than the in-house limit'],
        'einvoice' => ['E-VAT not cleared', 'medium', 'Invoices and restaurant bills of the day without a GRA E-VAT signature'],
        'room_count' => ['Room status discrepancy', 'high', 'Housekeeping\'s room count disagrees with front office (sleepers, unregistered occupancy)'],
        'late_change' => ['Figures changed after the audit', 'high', 'Revenue, tax or collections for the day differ from the night-audit report'],
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::SETTINGS as $k => [$label, $v]) {
            Setting::firstOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
    }

    public static function cfg(string $key): mixed
    {
        return setting($key, self::SETTINGS[$key][1] ?? null);
    }

    public static function lastClosed(): string
    {
        return bdate()->copy()->subDay()->toDateString();
    }

    public static function day(string $date): AuditDay
    {
        $date = Carbon::parse($date)->toDateString();
        if ($date >= bdate()->toDateString()) {
            throw new RuntimeException('Only closed business dates can be audited – '.dmy($date).' is still open.');
        }

        return AuditDay::firstOrCreate(['business_date' => $date], ['status' => 'open']);
    }

    public static function isClosed(string $date): bool
    {
        return AuditDay::where('business_date', $date)->where('status', 'closed')->exists();
    }

    /** FolioService::void guard: a closed day's figures cannot change. */
    public static function assertCanVoid(FolioTransaction $tx): void
    {
        if ((int) self::cfg('ia_lock_closed') && $tx->business_date && self::isClosed($tx->business_date->toDateString())) {
            throw new RuntimeException('The income audit has closed '.dmy($tx->business_date).' – this posting cannot be voided. Post an allowance or correction today instead.');
        }
    }

    // ───────────────────────── figures ─────────────────────────

    /** Stays that occupied a room on the night of $date. */
    public static function stayNights(string $date): Collection
    {
        return ReservationRoom::with('room', 'roomType', 'reservation', 'guest')->whereIn('status', ['checked_in', 'checked_out'])
            ->where('arrival_date', '<=', $date)->where('departure_date', '>', $date)
            ->whereNotNull('checked_in_at')->whereDate('checked_in_at', '<=', $date)
            ->where(fn ($q) => $q->whereNull('checked_out_at')->orWhereDate('checked_out_at', '>', $date))->get();
    }

    /** The rate this stay should have been charged on $date, or null for channel bookings (rates set by the OTA). */
    public static function expectedRate(ReservationRoom $s, string $date): ?array
    {
        $r = $s->reservation;
        if ($r && ($r->ota_channel_id || $r->channel_connection_id)) {
            return null;
        }
        if ($s->rate_plan_id && ($p = RatePlan::find($s->rate_plan_id))) {
            return ['rate' => (float) $p->rate, 'basis' => 'rate plan '.$p->code];
        }
        if ($r?->room_block_id && ($b = RoomBlock::find($r->room_block_id)) && $b->rate !== null) {
            return ['rate' => (float) $b->rate, 'basis' => 'group block '.$b->block_no];
        }
        $next = Carbon::parse($date)->addDay()->toDateString();
        if ($r?->rate_contract_id && ($c = RateContract::find($r->rate_contract_id)) && $s->roomType) {
            $n = ContractService::quote($c, $s->roomType, $date, $next, (int) $s->adults)['nights'][0] ?? null;
            if ($n) {
                return ['rate' => (float) $n['rate'], 'basis' => 'contract '.$c->code];
            }
        }
        if (! $s->roomType) {
            return null;
        }
        $rack = (float) (RateService::quote($s->roomType, $date, $next)['nights'][0]['rate'] ?? $s->roomType->base_rate);
        $disc = (float) ($r->discount_pct ?? 0);

        return $disc > 0 ? ['rate' => round($rack * (1 - $disc / 100), 2), 'basis' => 'rack less '.rtrim(rtrim(number_format($disc, 2), '0'), '.').'% booking discount']
            : ['rate' => $rack, 'basis' => 'rack rate'];
    }

    /** Night-audit figures recomputed now, the figures reported when the day closed, and the GL income for the day. */
    public static function figures(string $date): array
    {
        $tx = FolioTransaction::where('business_date', $date)->where('is_void', false)->get(['type', 'category', 'amount', 'tax_amount']);
        $pos = PosOrder::where('business_date', $date)->where('status', 'settled')->where('settlement', 'payment')->get(['total', 'tax']);
        $charges = $tx->where('type', 'charge');
        $now = [
            'room' => round((float) $charges->whereIn('category', ['room', 'extra_bed'])->sum('amount'), 2),
            'other' => round((float) $charges->whereNotIn('category', ['room', 'extra_bed'])->sum('amount') + (float) $pos->sum(fn ($o) => $o->total - $o->tax), 2),
            'tax' => round((float) $charges->sum('tax_amount') + (float) $pos->sum('tax'), 2),
            'collections' => round((float) $tx->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount') + (float) $pos->sum('total'), 2),
        ];
        $na = NightAudit::where('business_date', $date)->first();
        $reported = $na ? ['room' => (float) $na->room_revenue, 'other' => (float) $na->other_revenue, 'tax' => (float) $na->tax_collected, 'collections' => (float) $na->collections] : null;
        $diff = $reported ? array_map(fn ($k) => round($now[$k] - $reported[$k], 2), array_combine(array_keys($now), array_keys($now))) : null;
        $gl = (float) DB::table('gl_lines')->join('account_heads', 'account_heads.id', '=', 'gl_lines.account_head_id')
            ->where('gl_lines.hotel_id', \App\Support\Tenant::id())->where('gl_lines.date', $date)->where('gl_lines.closing', false)->where('account_heads.type', 'income')
            ->selectRaw('COALESCE(SUM(gl_lines.credit - gl_lines.debit), 0) v')->value('v');

        return ['now' => $now, 'reported' => $reported, 'diff' => $diff,
            'discounts' => round((float) $tx->whereIn('type', ['discount', 'allowance'])->sum('amount'), 2),
            'occupied' => $na?->rooms_occupied, 'rooms' => $na?->rooms_total, 'gl_income' => round($gl, 2), 'na_id' => $na?->id];
    }

    // ───────────────────────── checks ─────────────────────────

    protected static function f(string $check, string $key, array $a): array
    {
        return array_merge(['check' => $check, 'severity' => self::CHECKS[$check][1], 'fingerprint' => mb_substr($check.'|'.$key, 0, 120),
            'ref_type' => null, 'ref_id' => null, 'ref_label' => null, 'room_no' => null, 'expected' => null, 'actual' => null, 'amount' => 0, 'auth' => null, 'preset' => null], $a);
    }

    /** Every exception that applies to $date right now. */
    public static function collect(string $date, ?array $baseline = null): array
    {
        $out = [];
        $tol = (float) self::cfg('ia_rate_tolerance_pct');
        $stays = self::stayNights($date);
        $folioStay = Folio::whereIn('reservation_room_id', $stays->pluck('id'))->pluck('reservation_room_id', 'id');
        $tariffs = FolioTransaction::where('source_type', 'night')->where('reference', $date)->where('is_void', false)->where('category', 'room')
            ->whereIn('folio_id', $folioStay->keys())->get()->groupBy(fn ($t) => $folioStay[$t->folio_id]);

        foreach ($stays as $s) {
            $label = ($s->reservation?->reservation_no ?? 'Stay').' · '.($s->guest?->full_name ?? '');
            $base = ['ref_type' => 'stay', 'ref_id' => $s->id, 'ref_label' => mb_substr($label, 0, 80), 'room_no' => $s->room?->room_no];
            $posted = $tariffs[$s->id] ?? collect();
            $amount = $posted->count() ? (float) $posted->sum('amount') / $posted->count() : null;
            if ($posted->count() > 1) {
                $out[] = self::f('missing_tariff', "dup|{$s->id}", $base + ['detail' => "Room tariff posted {$posted->count()} times for the night – guest over-charged.",
                    'expected' => (float) $posted->first()->amount, 'actual' => (float) $posted->sum('amount'), 'amount' => round((float) $posted->sum('amount') - (float) $posted->first()->amount, 2)]);
            }
            if (! $posted->count() && (float) $s->rate > 0) {
                $out[] = self::f('missing_tariff', "miss|{$s->id}", $base + ['detail' => 'No room tariff posted for an occupied room ('.money($s->rate).' at the stay rate).',
                    'expected' => (float) $s->rate, 'actual' => 0, 'amount' => (float) $s->rate]);

                continue;
            }
            $charged = $amount ?? (float) $s->rate;
            $exp = self::expectedRate($s, $date);
            if ($charged < 0.005) {
                $auth = RevenueAuthService::match(['comp', 'house_use'], $s->id, null, $date);
                $out[] = self::f('zero_rate', "{$s->id}", $base + ['detail' => 'Room occupied at no charge'.($exp ? ' (expected '.money($exp['rate']).', '.$exp['basis'].')' : '').'.',
                    'expected' => $exp['rate'] ?? null, 'actual' => 0, 'amount' => $exp['rate'] ?? 0, 'auth' => $auth]);

                continue;
            }
            if ($exp && $exp['rate'] > 0) {
                $var = round($charged - $exp['rate'], 2);
                if (abs($var) / $exp['rate'] * 100 > $tol + 0.0001) {
                    $auth = $var < 0 ? RevenueAuthService::match(['rate'], $s->id, null, $date, $charged) : null;
                    $out[] = self::f('rate_variance', "{$s->id}", $base + ['severity' => $var < 0 ? 'medium' : 'low',
                        'detail' => ($var < 0 ? 'Charged below' : 'Charged above').' the expected rate: '.money($charged).' vs '.money($exp['rate']).' ('.$exp['basis'].', '.($var > 0 ? '+' : '').round($var / $exp['rate'] * 100, 1).'%).',
                        'expected' => $exp['rate'], 'actual' => $charged, 'amount' => abs($var), 'auth' => $auth]);
                }
            }
        }

        // discounts & allowances above the limit
        $limit = (float) self::cfg('ia_discount_limit');
        foreach (FolioTransaction::with('folio')->where('business_date', $date)->whereIn('type', ['discount', 'allowance'])->where('is_void', false)->where('amount', '>', $limit)->get() as $t) {
            $auth = $t->source_type === 'rev_auth' ? \App\Models\RevenueAuthorisation::with('decider')->find($t->source_id) : RevenueAuthService::match(['discount'], null, $t->folio_id, null, (float) $t->amount);
            $out[] = self::f('discount', "tx|{$t->id}", ['ref_type' => 'folio', 'ref_id' => $t->folio_id, 'ref_label' => $t->folio?->folio_no, 'room_no' => $t->folio?->stay?->room?->room_no,
                'detail' => ucfirst($t->type).' of '.money($t->amount).' – '.$t->description.' (limit '.money($limit).').', 'actual' => (float) $t->amount, 'amount' => (float) $t->amount, 'auth' => $auth]);
        }

        // voids and cancellations
        foreach (FolioTransaction::with('folio')->where('business_date', $date)->where('is_void', true)->where('amount', '>', 0)->get() as $t) {
            $out[] = self::f('void', "tx|{$t->id}", ['ref_type' => 'folio', 'ref_id' => $t->folio_id, 'ref_label' => $t->folio?->folio_no, 'room_no' => $t->folio?->stay?->room?->room_no,
                'severity' => $t->type === 'payment' ? 'high' : 'medium',
                'detail' => 'Voided '.$t->type.': '.$t->description.' '.money($t->amount).' – reason: '.($t->void_reason ?: 'none given').'.', 'actual' => (float) $t->amount, 'amount' => (float) $t->amount]);
        }
        foreach (PosOrder::with('outlet')->where('business_date', $date)->where('status', 'void')->get() as $o) {
            $val = (float) $o->total ?: (float) PosOrderItem::where('pos_order_id', $o->id)->sum('amount');
            $out[] = self::f('void', "pos|{$o->id}", ['ref_type' => 'pos_order', 'ref_id' => $o->id, 'ref_label' => ($o->bill_no ?: $o->order_no).' · '.($o->outlet?->name ?? ''),
                'detail' => 'POS order voided – reason: '.($o->void_reason ?: 'none given').'.', 'actual' => $val, 'amount' => $val]);
        }
        $cancelled = PosOrderItem::whereHas('order', fn ($q) => $q->where('business_date', $date)->where('status', '!=', 'void'))->where('is_cancelled', true)->whereNotNull('kot_no')->get()->groupBy('pos_order_id');
        foreach ($cancelled as $oid => $items) {
            $o = PosOrder::with('outlet')->find($oid);
            $out[] = self::f('void', "kot|{$oid}", ['ref_type' => 'pos_order', 'ref_id' => $oid, 'ref_label' => ($o->bill_no ?: $o->order_no).' · '.($o->outlet?->name ?? ''), 'severity' => 'low',
                'detail' => $items->count().' item(s) cancelled after the KOT went to the kitchen: '.$items->map(fn ($i) => rtrim(rtrim(number_format($i->qty, 2), '0'), '.').' × '.$i->item_name)->implode(', ').'.',
                'actual' => (float) $items->sum('amount'), 'amount' => (float) $items->sum('amount')]);
        }

        // cashier over / short
        $ctol = (float) self::cfg('ia_cash_tolerance');
        foreach (CashierShift::with('user')->where('business_date', $date)->where('status', 'closed')->get() as $sh) {
            if (abs((float) $sh->variance) > $ctol + 0.004) {
                $out[] = self::f('cash_variance', "{$sh->id}", ['ref_type' => 'shift', 'ref_id' => $sh->id, 'ref_label' => 'Shift #'.$sh->id.' · '.($sh->user?->name ?? ''),
                    'detail' => ((float) $sh->variance < 0 ? 'Cash short by ' : 'Cash over by ').money(abs((float) $sh->variance)).' (expected '.money($sh->expected_cash).', counted '.money($sh->counted_cash).').'.($sh->notes ? ' Note: '.$sh->notes : ''),
                    'expected' => (float) $sh->expected_cash, 'actual' => (float) $sh->counted_cash, 'amount' => abs((float) $sh->variance), 'severity' => (float) $sh->variance < 0 ? 'medium' : 'low']);
            }
        }

        // POS bills left open
        foreach (PosOrder::with('outlet', 'items')->where('business_date', $date)->where('status', 'open')->get() as $o) {
            $val = (float) $o->items->where('is_cancelled', false)->sum('amount');
            if ($val > 0) {
                $out[] = self::f('open_pos', "{$o->id}", ['ref_type' => 'pos_order', 'ref_id' => $o->id, 'ref_label' => $o->order_no.' · '.($o->outlet?->name ?? ''),
                    'detail' => 'Bill still open '.($o->customer_name ? 'for '.$o->customer_name.' ' : '').'– '.money($val).' of items not billed or settled.', 'actual' => $val, 'amount' => $val]);
            }
        }

        // departures not settled
        foreach (Folio::with('stay.room', 'guest')->where('status', 'open')->whereHas('stay', fn ($q) => $q->where('status', 'checked_out')->whereDate('checked_out_at', $date))->get() as $fo) {
            $bal = $fo->balance();
            if ($bal > 0.009) {
                $out[] = self::f('unsettled', "{$fo->id}", ['ref_type' => 'folio', 'ref_id' => $fo->id, 'ref_label' => $fo->folio_no.' · '.($fo->guest?->full_name ?? ''), 'room_no' => $fo->stay?->room?->room_no,
                    'detail' => 'Guest checked out with '.money($bal).' still owing on an open folio.', 'actual' => $bal, 'amount' => $bal]);
            }
        }

        // in-house balances over the limit (only meaningful for the last closed day)
        if ($date === self::lastClosed()) {
            $lim = (float) self::cfg('ia_inhouse_limit');
            foreach (ReservationRoom::with('folio', 'room', 'guest', 'reservation')->where('status', 'checked_in')->get() as $s) {
                if (! $s->folio || ($s->reservation?->billing_instruction === 'company')) {
                    continue;
                }
                $bal = $s->folio->balance();
                if ($bal > $lim) {
                    $out[] = self::f('credit_limit', "{$s->id}", ['ref_type' => 'stay', 'ref_id' => $s->id, 'ref_label' => mb_substr(($s->reservation?->reservation_no ?? '').' · '.($s->guest?->full_name ?? ''), 0, 80), 'room_no' => $s->room?->room_no,
                        'detail' => 'In-house balance '.money($bal).' is above the limit of '.money($lim).' – take a payment or a card authorisation.', 'expected' => $lim, 'actual' => $bal, 'amount' => round($bal - $lim, 2)]);
                }
            }
        }

        // E-VAT clearance
        if (EInvoiceService::enabled()) {
            foreach (Folio::where('status', 'closed')->whereNotNull('invoice_no')->where('invoice_date', $date)->where(fn ($q) => $q->whereNull('irn_status')->orWhere('irn_status', '!=', 'generated'))->get() as $fo) {
                $out[] = self::f('einvoice', "folio|{$fo->id}", ['ref_type' => 'folio', 'ref_id' => $fo->id, 'ref_label' => $fo->invoice_no,
                    'detail' => "Invoice {$fo->invoice_no} has no GRA E-VAT signature".($fo->irn_status === 'failed' ? ' – the last attempt failed: '.mb_substr((string) $fo->irn_error, 0, 200) : '').'.', 'amount' => (float) $fo->totals()['charges']]);
            }
            if (PosEvatService::enabled()) {
                foreach (PosOrder::where('business_date', $date)->where('status', 'settled')->where('settlement', 'payment')->where('total', '>', 0)->whereNotNull('bill_no')
                    ->where(fn ($q) => $q->whereNull('evat_status')->orWhere('evat_status', '!=', 'generated'))->get() as $o) {
                    $out[] = self::f('einvoice', "pos|{$o->id}", ['ref_type' => 'pos_order', 'ref_id' => $o->id, 'ref_label' => $o->bill_no,
                        'detail' => "Restaurant bill {$o->bill_no} has no GRA E-VAT signature".($o->evat_status === 'failed' ? ' – '.mb_substr((string) $o->evat_error, 0, 200) : '').'.', 'amount' => (float) $o->total]);
                }
            }
        }

        // housekeeping room count
        foreach (RoomCount::with('room', 'resolver')->where('business_date', $date)->where('result', '!=', 'ok')->get() as $rc) {
            $out[] = self::f('room_count', "{$rc->room_id}", ['ref_type' => 'room_count', 'ref_id' => $rc->id, 'ref_label' => 'Room '.$rc->room?->room_no, 'room_no' => $rc->room?->room_no,
                'severity' => $rc->result === 'ooo_mismatch' ? 'low' : 'high',
                'detail' => RoomCount::RESULTS[$rc->result].': front office '.str_replace('_', ' ', $rc->fo_status).', housekeeping '.strtolower(RoomCount::HK[$rc->hk_status]).($rc->luggage ? ' (luggage in room)' : '').'.',
                'preset' => $rc->resolution ? ['status' => 'corrected', 'text' => RoomCount::RESOLUTIONS[$rc->resolution].($rc->note ? ' – '.$rc->note : '').' ('.($rc->resolver?->name ?? 'front office').')', 'by' => $rc->resolved_by] : null]);
        }

        // figures changed since the audit baseline (taken when the day closed)
        if ($baseline) {
            $fig = self::figures($date);
            $diff = collect($fig['now'])->map(fn ($v, $k) => round($v - (float) ($baseline[$k] ?? 0), 2))->filter(fn ($v) => abs($v) > 0.009);
            if ($diff->count()) {
                $names = ['room' => 'room revenue', 'other' => 'other revenue', 'tax' => 'tax', 'collections' => 'collections'];
                $parts = $diff->map(fn ($v, $k) => $names[$k].' '.money($baseline[$k] ?? 0).' → '.money($fig['now'][$k]))->implode('; ');
                $out[] = self::f('late_change', 'day', ['ref_type' => 'night_audit', 'ref_id' => $fig['na_id'], 'ref_label' => 'Day figures '.dmy($date),
                    'detail' => 'Changed after the day was closed: '.$parts.' – back-dated voids or postings.', 'amount' => round($diff->sum(fn ($v) => abs($v)), 2)]);
            }
        }

        return $out;
    }

    /** Run (or re-run) the checks for a day that is still in audit. */
    public static function run(AuditDay $day, ?User $u = null): array
    {
        if ($day->status !== 'open') {
            throw new RuntimeException('The day is '.strtolower(AuditDay::STATUSES[$day->status]).' – reopen it to re-run the checks.');
        }
        $date = $day->business_date->toDateString();
        $baseline = $day->figures['baseline'] ?? null;
        $found = self::collect($date, $baseline);
        $new = 0;
        $cleared = 0;
        DB::transaction(function () use ($day, $found, &$new, &$cleared) {
            $existing = AuditFinding::where('audit_day_id', $day->id)->get()->keyBy('fingerprint');
            $seen = [];
            foreach ($found as $f) {
                $seen[$f['fingerprint']] = true;
                $attrs = collect($f)->only(['check', 'severity', 'ref_type', 'ref_id', 'ref_label', 'room_no', 'expected', 'actual', 'amount'])->all() + ['detail' => mb_substr($f['detail'], 0, 500)];
                $auth = $f['auth'];
                $row = $existing[$f['fingerprint']] ?? null;
                if (! $row) {
                    $row = AuditFinding::create($attrs + ['audit_day_id' => $day->id, 'fingerprint' => $f['fingerprint'], 'status' => 'open']);
                    $new++;
                } else {
                    $row->fill($attrs);
                    if ($row->status === 'cleared') {
                        $row->fill(['status' => 'open', 'resolution' => null, 'resolved_by' => null, 'resolved_at' => null]);
                    }
                    $row->save();
                }
                if ($row->status === 'open' && $auth) {
                    $row->update(['status' => 'accepted', 'revenue_authorisation_id' => $auth->id, 'resolved_at' => now(), 'resolved_by' => $auth->decided_by,
                        'resolution' => 'Pre-authorised '.$auth->auth_no.' by '.($auth->decider?->name ?? '—').' – '.$auth->reason]);
                } elseif ($row->status === 'open' && $f['preset']) {
                    $row->update(['status' => $f['preset']['status'], 'resolution' => mb_substr($f['preset']['text'], 0, 500), 'resolved_by' => $f['preset']['by'], 'resolved_at' => now()]);
                }
            }
            foreach ($existing as $fp => $row) {
                if (! isset($seen[$fp]) && in_array($row->status, AuditFinding::UNRESOLVED, true)) {
                    $row->update(['status' => 'cleared', 'resolved_at' => now(), 'resolution' => 'No longer applies – put right in the system (re-check '.now()->format('d/m H:i').').']);
                    $cleared++;
                }
            }
        });
        $fig = self::figures($date);
        $fig['baseline'] = $baseline ?? $fig['now'];
        $fig['baseline_at'] = $day->figures['baseline_at'] ?? now()->toDateTimeString();
        self::recount($day, $fig);

        return ['found' => count($found), 'new' => $new, 'cleared' => $cleared, 'open' => $day->findings_open];
    }

    protected static function recount(AuditDay $day, ?array $figures = null): void
    {
        $rows = AuditFinding::where('audit_day_id', $day->id)->get();
        $day->update(array_filter(['findings_total' => $rows->where('status', '!=', 'cleared')->count(), 'findings_open' => $rows->whereIn('status', AuditFinding::UNRESOLVED)->count(),
            'checked_at' => $figures ? now() : null, 'figures' => $figures], fn ($v) => $v !== null));
    }

    public static function resolve(AuditFinding $f, string $status, string $note, User $u): void
    {
        if (! in_array($status, ['explained', 'corrected', 'escalated', 'accepted', 'open'], true)) {
            throw new RuntimeException('Choose how the exception was dealt with.');
        }
        if ($f->day->status !== 'open') {
            throw new RuntimeException('The day is signed – reopen it to change exceptions.');
        }
        if ($status !== 'open' && mb_strlen(trim($note)) < 5) {
            throw new RuntimeException('Write what was found or done (at least 5 characters).');
        }
        if ($status === 'accepted' && ! $u->hasPermission('income_audit.close')) {
            throw new RuntimeException('Only the financial controller / GM can accept an exception without explanation or correction.');
        }
        $f->update(['status' => $status, 'resolution' => $status === 'open' ? null : mb_substr(trim($note), 0, 500), 'resolved_by' => $status === 'open' ? null : $u->id,
            'resolved_at' => $status === 'open' ? null : now()]);
        self::recount($f->day);
        ActivityLogger::log('income_audit', 'finding_'.$status, 'Audit '.dmy($f->day->business_date).': '.self::CHECKS[$f->check][0].' '.($f->ref_label ?? '').' – '.AuditFinding::STATUSES[$status], $f);
    }

    public static function sign(AuditDay $day, ?string $note, User $u): void
    {
        if ($day->status !== 'open') {
            throw new RuntimeException('Already signed.');
        }
        self::run($day, $u); // always sign against fresh checks
        $day->refresh();
        if ($day->findings_open > 0) {
            throw new RuntimeException("{$day->findings_open} exception(s) still open or escalated – deal with them before signing.");
        }
        $day->update(['status' => 'signed', 'signed_by' => $u->id, 'signed_at' => now(), 'sign_note' => $note ? mb_substr(trim($note), 0, 500) : null]);
        ActivityLogger::log('income_audit', 'signed', 'Income audit '.dmy($day->business_date)." signed by {$u->name}", $day);
    }

    public static function close(AuditDay $day, ?string $note, User $u): void
    {
        if ($day->status !== 'signed') {
            throw new RuntimeException('The auditor must sign the day first.');
        }
        if ((int) self::cfg('ia_four_eyes') && $day->signed_by === $u->id) {
            throw new RuntimeException('You signed this day as auditor – another person must close it.');
        }
        $day->update(['status' => 'closed', 'closed_by' => $u->id, 'closed_at' => now(), 'close_note' => $note ? mb_substr(trim($note), 0, 500) : null]);
        ActivityLogger::log('income_audit', 'closed', 'Income audit '.dmy($day->business_date)." closed by {$u->name} – postings dated that day are locked", $day);
    }

    public static function reopen(AuditDay $day, string $reason, User $u): void
    {
        if ($day->status === 'open') {
            throw new RuntimeException('The day is already open.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give the reason for reopening (at least 5 characters).');
        }
        $day->update(['status' => 'open', 'signed_by' => null, 'signed_at' => null, 'closed_by' => null, 'closed_at' => null,
            'reopened_by' => $u->id, 'reopened_at' => now(), 'reopen_reason' => mb_substr(trim($reason), 0, 255)]);
        ActivityLogger::log('income_audit', 'reopened', 'Income audit '.dmy($day->business_date)." reopened by {$u->name} – {$reason}", $day);
    }

    /** Night-audit hook: the day just closed gets its audit and a first run of the checks; authorised rates start / end. */
    public static function afterNightAudit(string $closedDate): void
    {
        RevenueAuthService::nightly();
        $day = AuditDay::firstOrCreate(['business_date' => $closedDate], ['status' => 'open']);
        if ($day->status === 'open') {
            self::run($day);
        }
    }

    // ───────────────────────── room count ─────────────────────────

    public static function foStatus(Room $room): string
    {
        return match ($room->status) { 'occupied' => 'occupied', 'out_of_order', 'ooo', 'maintenance' => 'out_of_order', default => 'vacant' };
    }

    public static function compare(string $fo, string $hk): string
    {
        return match (true) {
            $fo === 'occupied' && $hk !== 'occupied' => 'sleeper',
            $fo !== 'occupied' && $hk === 'occupied' => 'unregistered',
            ($fo === 'out_of_order') !== ($hk === 'ooo') => 'ooo_mismatch',
            default => 'ok',
        };
    }

    /** Save housekeeping's count for today: [roomId => ['hk' => occupied|vacant|ooo, 'luggage' => bool]]. */
    public static function saveCount(array $rows, User $u, ?string $date = null): array
    {
        $date ??= bdate()->toDateString();
        $rooms = Room::where('is_active', true)->get()->keyBy('id');
        $n = 0;
        $issues = 0;
        DB::transaction(function () use ($rows, $rooms, $u, $date, &$n, &$issues) {
            foreach ($rows as $rid => $r) {
                $room = $rooms[(int) $rid] ?? null;
                $hk = $r['hk'] ?? null;
                if (! $room || ! array_key_exists((string) $hk, RoomCount::HK)) {
                    continue;
                }
                $fo = self::foStatus($room);
                $result = self::compare($fo, $hk);
                $rc = RoomCount::firstOrNew(['business_date' => $date, 'room_id' => $room->id]);
                $changed = ! $rc->exists || $rc->hk_status !== $hk || $rc->fo_status !== $fo;
                $rc->fill(['hk_status' => $hk, 'luggage' => ! empty($r['luggage']), 'fo_status' => $fo, 'result' => $result, 'counted_by' => $u->id]);
                if ($changed) {
                    $rc->fill(['resolution' => null, 'note' => null, 'resolved_by' => null, 'resolved_at' => null]);
                }
                $rc->save();
                $n++;
                $issues += $result !== 'ok' ? 1 : 0;
            }
        });
        if ($n === 0) {
            throw new RuntimeException('Mark at least one room.');
        }
        ActivityLogger::log('income_audit', 'room_count', "Room count {$date}: {$n} room(s), {$issues} discrepancy(ies)", null);

        return ['rooms' => $n, 'issues' => $issues, 'total' => $rooms->count()];
    }

    public static function resolveCount(RoomCount $rc, string $resolution, ?string $note, User $u): void
    {
        if ($rc->result === 'ok') {
            throw new RuntimeException('This room agrees – nothing to resolve.');
        }
        if (! array_key_exists($resolution, RoomCount::RESOLUTIONS)) {
            throw new RuntimeException('Choose what was found.');
        }
        if ($resolution === 'other' && mb_strlen(trim((string) $note)) < 5) {
            throw new RuntimeException('Describe what was found.');
        }
        if (($d = AuditDay::where('business_date', $rc->business_date)->first()) && $d->status !== 'open') {
            throw new RuntimeException('The income audit for this date is signed – reopen it first.');
        }
        $rc->update(['resolution' => $resolution, 'note' => $note ? mb_substr(trim($note), 0, 255) : null, 'resolved_by' => $u->id, 'resolved_at' => now()]);
    }

    // ───────────────────────── dashboard ─────────────────────────

    public static function stats(): array
    {
        $days = AuditDay::where('business_date', '>=', bdate()->copy()->subDays(30)->toDateString())->get();

        return [
            'open_days' => $days->where('status', 'open')->count(),
            'signed' => $days->where('status', 'signed')->count(),
            'closed' => $days->where('status', 'closed')->count(),
            'open_findings' => (int) AuditFinding::whereIn('status', AuditFinding::UNRESOLVED)->whereHas('day', fn ($q) => $q->where('status', 'open'))->count(),
            'escalated' => (int) AuditFinding::where('status', 'escalated')->count(),
            'pending_auth' => RevenueAuthService::pendingCount(),
            'unaudited' => self::unaudited(),
            'count_issues' => RoomCount::where('business_date', '>=', bdate()->copy()->subDays(2)->toDateString())->where('result', '!=', 'ok')->whereNull('resolution')->count(),
        ];
    }

    /** Closed business dates in the last 7 days with no audit started. */
    public static function unaudited(): int
    {
        $from = bdate()->copy()->subDays(7)->toDateString();
        $have = AuditDay::where('business_date', '>=', $from)->pluck('business_date')->map(fn ($d) => $d->toDateString())->all();
        $n = 0;
        for ($d = Carbon::parse($from); $d->toDateString() < bdate()->toDateString(); $d->addDay()) {
            $n += in_array($d->toDateString(), $have, true) ? 0 : 1;
        }

        return $n;
    }

    /** Nav badge: open exceptions on days in audit + days awaiting the controller + authorisations to decide. */
    public static function attention(): int
    {
        $s = self::stats();

        return $s['open_findings'] + (can_do('income_audit.close') ? $s['signed'] : 0) + (can_do('income_audit.authorise') ? $s['pending_auth'] : 0);
    }
}
