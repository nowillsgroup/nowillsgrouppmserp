<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\BankMatch;
use App\Models\BankStatement;
use App\Models\CashForecastLine;
use App\Models\ChequeBook;
use App\Models\ChequeIssued;
use App\Models\ChequeReceived;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\LeaseCharge;
use App\Models\PaymentMode;
use App\Models\PayrollRun;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.7 Treasury & cash management.
 *
 * GL design (all direct journals, dated on the business date or later – never inside the GL lock):
 *  - transfer dispatched     Dr 1090 Cash in Transit / Cr source (cash part) / Cr 1035 Cheques in Hand (cheques part)
 *  - transfer completed      Dr destination (net) + Dr fee account (fee) ± 6190 cash short / over / Cr 1090 (or the source when not via transit)
 *  - cheque received         receipt voucher, payment mode "Cheque": Dr 1035 / Cr city ledger (company) or the chosen account
 *  - cheque bounced          Dr 1035 / Cr bank (+ bank charge), then a negative receipt voucher: Dr city ledger / Cr 1035
 *  - standalone cheque paid  Dr account / Cr bank; void / stop / stale → reversal
 * A cheque linked to a supplier payment carries no journal of its own (the payment already credited the bank).
 */
class TreasuryService
{
    public const DEFAULTS = [
        'treasury_approval_threshold' => '50000',   // transfers at or above need a second person's approval
        'treasury_cash_float' => '5000',            // cash left in the cash box when banking
        'treasury_payday' => '28',                  // day of the month salaries are paid (forecast)
        'treasury_collect_pct' => '85',             // share of overdue receivables expected in week 1 (forecast)
        'treasury_arrival_pct' => '90',             // share of guest-paid arrivals expected to pay (no-shows, cancellations)
        'treasury_clear_days' => '2',               // days after a deposit that cheques are treated as cleared
    ];

    /** balance-sheet accounts that must never be the other side of a standalone cheque (sub-ledger controls) */
    public const CONTROL_KEYS = ['guest_ledger', 'city_ledger', 'club_receivable', 'lease_receivable', 'accounts_payable', 'salary_payable', 'staff_advances', 'gateway_clearing',
        'inventory', 'fixed_assets', 'acc_depreciation', 'cheques_in_hand', 'cash_in_transit', 'lease_deposits', 'voucher_liability', 'retained'];

    /** IAS 7 classes */
    public const CF_CLASSES = ['cash' => 'Cash & cash equivalents', 'operating' => 'Operating – working capital', 'noncash' => 'Operating – non-cash add-back', 'investing' => 'Investing', 'financing' => 'Financing'];

    protected static array $memo = [];

    public static function cfg(string $k): string
    {
        return (string) setting($k, self::DEFAULTS[$k]);
    }

    public static function saveSettings(array $d): void
    {
        $map = [
            'treasury_approval_threshold' => (string) max(0, round((float) ($d['approval_threshold'] ?? 50000), 2)),
            'treasury_cash_float' => (string) max(0, round((float) ($d['cash_float'] ?? 5000), 2)),
            'treasury_payday' => (string) max(1, min(28, (int) ($d['payday'] ?? 28))),
            'treasury_collect_pct' => (string) max(0, min(100, (int) ($d['collect_pct'] ?? 85))),
            'treasury_arrival_pct' => (string) max(0, min(100, (int) ($d['arrival_pct'] ?? 90))),
            'treasury_clear_days' => (string) max(0, min(10, (int) ($d['clear_days'] ?? 2))),
        ];
        foreach ($map as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('treasury', 'settings', 'Treasury settings: approval from '.money((float) $map['treasury_approval_threshold']).', cash float '.money((float) $map['treasury_cash_float']));
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    // ───────────────────────── register ─────────────────────────

    public static function ensureDefaults(): void
    {
        ChartOfAccounts::ensureLight();
        if (! PaymentMode::where('type', 'cheque')->exists()) {
            PaymentMode::create(['name' => 'Cheque', 'type' => 'cheque', 'account_head_id' => AccountHead::sys('cheques_in_hand'), 'is_active' => true]);
        }
        self::syncAccounts();
    }

    public static function chequeMode(): PaymentMode
    {
        return PaymentMode::where('type', 'cheque')->orderBy('id')->first()
            ?? PaymentMode::create(['name' => 'Cheque', 'type' => 'cheque', 'account_head_id' => AccountHead::sys('cheques_in_hand'), 'is_active' => true]);
    }

    /** every Cash & Bank / Petty Cash GL head gets a register entry */
    public static function syncAccounts(): int
    {
        $have = TreasuryAccount::pluck('account_head_id')->flip();
        $n = 0;
        foreach (AccountHead::where('type', 'asset')->whereIn('group_name', ['Cash & Bank', 'Petty Cash'])->orderBy('code')->get() as $h) {
            if (isset($have[$h->id])) {
                continue;
            }
            [$kind, $pos, $settle, $fee] = match ($h->system_key) {
                'cash' => ['cash', true, 0, 0],
                'bank' => ['bank', true, 0, 0],
                'wallet_clearing' => ['momo', true, 0, 1.0],
                'card_clearing' => ['clearing', false, 2, 1.5],
                'gateway_clearing' => ['clearing', false, 2, 0],
                'forex_on_hand' => ['forex', true, 0, 0],
                'cheques_in_hand' => ['cheques', true, 0, 0],
                'cash_in_transit' => ['transit', true, 0, 0],
                default => [$h->group_name === 'Petty Cash' ? 'petty' : 'bank', true, 0, 0],
            };
            TreasuryAccount::create(['account_head_id' => $h->id, 'kind' => $kind, 'name' => $h->name, 'in_position' => $pos, 'settle_days' => $settle, 'fee_rate' => $fee,
                'currency' => $kind === 'forex' ? 'USD' : 'GHS', 'is_active' => (bool) $h->is_active]);
            $n++;
        }

        return $n;
    }

    public static function accounts(bool $activeOnly = true): Collection
    {
        return TreasuryAccount::with('head')->when($activeOnly, fn ($q) => $q->where('is_active', true))->get()
            ->sortBy(fn ($a) => array_search($a->kind, array_keys(TreasuryAccount::KINDS)).'-'.$a->head?->code)->values();
    }

    /** a new bank / wallet / cash account: its own GL head in the Cash & Bank group */
    public static function createAccount(array $d): TreasuryAccount
    {
        $kind = $d['kind'];
        if (! in_array($kind, ['bank', 'momo', 'cash'], true)) {
            throw new RuntimeException('Only bank accounts, Mobile Money wallets and cash boxes are added here – petty floats are opened under Petty Cash.');
        }
        $series = ['bank' => [range(1021, 1029), range(1031, 1034)], 'momo' => [range(1041, 1049)], 'cash' => [range(1061, 1069)]][$kind];
        $used = AccountHead::pluck('code')->filter()->flip();
        $code = collect(array_merge(...$series))->first(fn ($c) => ! isset($used[(string) $c]));

        return DB::transaction(function () use ($d, $kind, $code) {
            $head = AccountHead::create(['name' => $d['name'], 'code' => $code ? (string) $code : null, 'type' => 'asset', 'group_name' => 'Cash & Bank', 'is_active' => true]);
            $a = TreasuryAccount::create(['account_head_id' => $head->id, 'kind' => $kind] + self::accountFields($d));
            ActivityLogger::log('treasury', 'account', "Treasury account {$a->name} ({$head->code}) added", $a);

            return $a;
        });
    }

    public static function updateAccount(TreasuryAccount $a, array $d): void
    {
        $a->forceFill(self::accountFields($d))->save();
        if ($a->head && in_array($a->kind, ['bank', 'momo', 'cash'], true) && $a->head->name !== $a->name && ! $a->head->system_key) {
            $a->head->forceFill(['name' => $a->name])->save();
        }
        ActivityLogger::log('treasury', 'account', "Treasury account {$a->name} updated", $a);
    }

    protected static function accountFields(array $d): array
    {
        return ['name' => $d['name'], 'bank_name' => $d['bank_name'] ?? null, 'branch' => $d['branch'] ?? null, 'account_no' => $d['account_no'] ?? null, 'swift' => $d['swift'] ?? null,
            'currency' => strtoupper($d['currency'] ?? 'GHS'), 'min_balance' => round((float) ($d['min_balance'] ?? 0), 2), 'overdraft_limit' => round((float) ($d['overdraft_limit'] ?? 0), 2),
            'fee_rate' => round((float) ($d['fee_rate'] ?? 0), 2), 'settle_days' => (int) ($d['settle_days'] ?? 0), 'in_position' => ! empty($d['in_position']),
            'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active']), 'notes' => $d['notes'] ?? null];
    }

    public static function balance(int $headId, ?string $asOf = null): float
    {
        return round((float) GlLine::where('account_head_id', $headId)->whereDate('date', '<=', $asOf ?? bdate()->toDateString())->sum(DB::raw('debit - credit')), 2);
    }

    /** cash position per account at a date */
    public static function position(?string $asOf = null): array
    {
        $asOf ??= bdate()->toDateString();
        $issued = ChequeIssued::with('book')->where('status', 'issued')->whereDate('cheque_date', '<=', $asOf)->get()->groupBy(fn ($c) => $c->book->treasury_account_id);
        $rows = self::accounts()->map(function (TreasuryAccount $a) use ($asOf, $issued) {
            $bal = self::balance($a->account_head_id, $asOf);
            $rec = in_array($a->kind, ['bank', 'momo', 'clearing'], true)
                ? BankStatement::where('account_head_id', $a->account_head_id)->where('status', 'reconciled')->orderByDesc('period_to')->first() : null;
            $unpresented = round((float) collect($issued[$a->id] ?? [])->sum('amount'), 2);
            $floor = (float) $a->min_balance;
            $headroom = in_array($a->kind, ['bank', 'momo', 'cash', 'petty'], true) ? round($bal + (float) $a->overdraft_limit - $floor, 2) : null;

            return ['account' => $a, 'balance' => $bal, 'reconciled_to' => $rec?->period_to, 'statement_balance' => $rec ? (float) $rec->closing_balance : null,
                'unpresented' => $unpresented, 'headroom' => $headroom, 'below_min' => $floor > 0 && $bal < $floor, 'overdrawn' => $bal < -0.005 && in_array($a->kind, ['bank', 'momo'], true)];
        });
        $in = $rows->filter(fn ($r) => $r['account']->in_position);

        return [
            'as_of' => $asOf, 'rows' => $rows,
            'cash' => round($in->sum('balance'), 2),
            'banks' => round($in->filter(fn ($r) => in_array($r['account']->kind, ['bank', 'momo'], true))->sum('balance'), 2),
            'on_hand' => round($in->filter(fn ($r) => in_array($r['account']->kind, ['cash', 'petty', 'forex', 'cheques'], true))->sum('balance'), 2),
            'transit' => round($in->filter(fn ($r) => $r['account']->kind === 'transit')->sum('balance'), 2),
            'clearing' => round($rows->filter(fn ($r) => $r['account']->kind === 'clearing')->sum('balance'), 2),
            'unpresented' => round($rows->sum('unpresented'), 2),
            'alerts' => $rows->filter(fn ($r) => $r['below_min'] || $r['overdrawn'])->values(),
        ];
    }

    // ───────────────────────── transfers ─────────────────────────

    /** allowed [from kinds, to kinds] per transfer kind */
    public const ROUTES = [
        'deposit' => [['cash', 'forex', 'cheques'], ['bank']],
        'sweep' => [['clearing', 'momo'], ['bank']],
        'transfer' => [['bank', 'momo'], ['bank', 'momo']],
        'withdrawal' => [['bank'], ['cash']],
    ];

    /** a suggested amount / fee for a new transfer */
    public static function suggest(string $kind, TreasuryAccount $from): array
    {
        $bal = self::balance($from->account_head_id);
        $committed = (float) TreasuryTransfer::where('from_account_id', $from->id)->whereIn('status', ['requested', 'approved'])->sum('cash_amount');
        if ($kind === 'deposit') {
            $amt = max(0, $bal - $committed - (float) self::cfg('treasury_cash_float'));

            return ['amount' => round(floor($amt / 10) * 10, 2), 'fee' => 0.0, 'note' => 'Cash in hand '.money($bal).' less the float of '.money((float) self::cfg('treasury_cash_float')).'.'];
        }
        if ($kind === 'sweep') {
            $d = bdate()->copy()->subDays((int) $from->settle_days)->toDateString();
            $due = max(0, self::balance($from->account_head_id, $d) - $committed);
            $fee = round($due * (float) $from->fee_rate / 100, 2);

            return ['amount' => round($due, 2), 'fee' => $fee, 'note' => 'Receipts up to '.dmy($d).' (T+'.$from->settle_days.'), fee '.rtrim(rtrim(number_format((float) $from->fee_rate, 2), '0'), '.').'%.'];
        }

        return ['amount' => 0.0, 'fee' => 0.0, 'note' => 'Balance '.money($bal).'.'];
    }

    public static function createTransfer(array $d, User $by): TreasuryTransfer
    {
        $kind = $d['kind'];
        [$fromKinds, $toKinds] = self::ROUTES[$kind] ?? throw new RuntimeException('Unknown transfer type.');
        $from = TreasuryAccount::findOrFail($d['from_account_id']);
        $to = TreasuryAccount::findOrFail($d['to_account_id']);
        if ($from->id === $to->id) {
            throw new RuntimeException('Choose two different accounts.');
        }
        if (! $from->is_active || ! $to->is_active) {
            throw new RuntimeException('Both accounts must be active.');
        }
        if ($from->head?->system_key === 'gateway_clearing') {
            throw new RuntimeException('Gateway settlements are booked from Online Payments (payouts) – they keep that clearing account in step with the gateway.');
        }
        $cheques = collect();
        if ($kind === 'deposit' && ! empty($d['cheque_ids'])) {
            $cheques = ChequeReceived::whereIn('id', (array) $d['cheque_ids'])->get();
            if ($cheques->contains(fn ($c) => $c->status !== 'in_hand' || $c->transfer_id)) {
                throw new RuntimeException('Only cheques in hand that are not already on a deposit can be banked.');
            }
        }
        if (! in_array($from->kind, $fromKinds, true) || ! in_array($to->kind, $toKinds, true)) {
            throw new RuntimeException(TreasuryTransfer::KINDS[$kind].': from '.implode(' / ', array_map(fn ($k) => strtolower(TreasuryAccount::KINDS[$k]), $fromKinds)).' to '.implode(' / ', array_map(fn ($k) => strtolower(TreasuryAccount::KINDS[$k]), $toKinds)).'.');
        }
        $cash = round((float) ($d['cash_amount'] ?? $d['amount'] ?? 0), 2);
        if ($from->kind === 'cheques') {
            $cash = 0.0; // a cheque-only deposit
        }
        $chq = round((float) $cheques->sum('amount'), 2);
        $total = round($cash + $chq, 2);
        if ($total <= 0) {
            throw new RuntimeException('Enter an amount or pick cheques to bank.');
        }
        $fee = round((float) ($d['fee'] ?? 0), 2);
        if ($fee < 0 || $fee >= $total) {
            throw new RuntimeException('The fee must be less than the amount.');
        }
        $date = $d['date'] ?? bdate()->toDateString();
        self::assertOpenDate($date);
        if ($cash > 0 && in_array($from->kind, ['cash', 'forex', 'clearing', 'momo', 'petty'], true)) {
            $avail = self::balance($from->account_head_id) - (float) TreasuryTransfer::where('from_account_id', $from->id)->whereIn('status', ['requested', 'approved'])->sum('cash_amount');
            if ($cash > round($avail, 2) + 0.004) {
                throw new RuntimeException('Only '.money(max(0, $avail)).' is available in '.$from->name.' (after other transfers waiting).');
            }
        }
        if ($cash > 0 && $from->kind === 'bank') {
            $avail = self::balance($from->account_head_id) + (float) $from->overdraft_limit;
            if ($cash > round($avail, 2) + 0.004) {
                throw new RuntimeException($from->name.' would go beyond its overdraft limit ('.money($avail).' available).');
            }
        }
        $needs = $total >= (float) self::cfg('treasury_approval_threshold');
        $count = null;
        if ($kind === 'deposit' && ! empty($d['count'])) {
            $count = collect($d['count'])->map(fn ($v) => max(0, (int) $v))->filter()->all();
            $counted = round(collect($count)->sum(fn ($pcs, $den) => $pcs * (float) $den), 2);
            if ($count && abs($counted - $cash) >= 0.005) {
                throw new RuntimeException('The cash count adds up to '.money($counted).', not '.money($cash).'.');
            }
        }

        return DB::transaction(function () use ($d, $kind, $from, $to, $cash, $chq, $total, $fee, $date, $needs, $count, $cheques, $by) {
            $t = TreasuryTransfer::create([
                'number' => Numbering::next('treasury_transfer'), 'kind' => $kind, 'from_account_id' => $from->id, 'to_account_id' => $to->id,
                'cash_amount' => $cash, 'cheque_amount' => $chq, 'amount' => $total, 'fee' => $fee,
                'fee_account_id' => $fee > 0 ? (int) ($d['fee_account_id'] ?? AccountHead::sys($from->kind === 'momo' ? 'momo_fees' : 'bank_charges')) : null,
                'via_transit' => $kind === 'sweep' ? false : ! array_key_exists('via_transit', $d) || ! empty($d['via_transit']),
                'date' => $date, 'slip_no' => $d['slip_no'] ?? null, 'cash_count' => $count ?: null, 'notes' => $d['notes'] ?? null,
                'status' => $needs ? 'requested' : 'approved', 'requested_by' => $by->id,
            ]);
            if ($cheques->count()) {
                ChequeReceived::whereIn('id', $cheques->pluck('id'))->update(['transfer_id' => $t->id]);
            }
            ActivityLogger::log('treasury', 'transfer', "{$t->number} ".TreasuryTransfer::KINDS[$kind].' '.money($total)." {$from->name} → {$to->name}".($needs ? ' – awaiting approval' : ''), $t);

            return $t;
        });
    }

    public static function approve(TreasuryTransfer $t, User $by): void
    {
        if ($t->status !== 'requested') {
            throw new RuntimeException("{$t->number} is not awaiting approval.");
        }
        if ((int) $t->requested_by === (int) $by->id) {
            throw new RuntimeException('Four-eyes rule: a transfer must be approved by someone other than the person who requested it.');
        }
        $t->forceFill(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()])->save();
        ActivityLogger::log('treasury', 'approve', "{$t->number} approved", $t);
    }

    public static function reject(TreasuryTransfer $t, User $by, string $reason): void
    {
        if ($t->status !== 'requested') {
            throw new RuntimeException("{$t->number} is not awaiting approval.");
        }
        DB::transaction(function () use ($t, $by, $reason) {
            ChequeReceived::where('transfer_id', $t->id)->update(['transfer_id' => null]);
            $t->forceFill(['status' => 'rejected', 'approved_by' => $by->id, 'approved_at' => now(), 'reason' => mb_substr($reason, 0, 255)])->save();
        });
        ActivityLogger::log('treasury', 'reject', "{$t->number} rejected: {$reason}", $t);
    }

    /** send the money: into Cash in Transit (or straight to completion when not via transit) */
    public static function dispatch(TreasuryTransfer $t, User $by, ?string $date = null, ?string $slip = null): void
    {
        if ($t->status !== 'approved') {
            throw new RuntimeException($t->status === 'requested' ? "{$t->number} needs approval first." : "{$t->number} is ".strtolower(TreasuryTransfer::STATUSES[$t->status]).'.');
        }
        $date ??= bdate()->toDateString();
        self::assertOpenDate($date);
        if (! $t->via_transit) {
            self::complete($t, $by, $date, null, null, null, $slip);

            return;
        }
        DB::transaction(function () use ($t, $by, $date, $slip) {
            $t->load('from', 'to');
            $memo = $t->number.' '.$t->from->name.' → '.$t->to->name;
            $lines = [[AccountHead::sys('cash_in_transit'), (float) $t->amount, $memo]];
            if ((float) $t->cash_amount > 0) {
                $lines[] = [$t->from->account_head_id, -(float) $t->cash_amount, $memo];
            }
            if ((float) $t->cheque_amount > 0) {
                $lines[] = [AccountHead::sys('cheques_in_hand'), -(float) $t->cheque_amount, $memo.' (cheques)'];
            }
            $j = LedgerService::post(['date' => $date, 'reference' => $slip ?: $t->number, 'narration' => TreasuryTransfer::KINDS[$t->kind].' sent – '.$t->number, 'lines' => $lines], 'treasury', $t->id.':out');
            ChequeReceived::where('transfer_id', $t->id)->update(['status' => 'deposited', 'left_on' => $date]);
            $t->forceFill(['status' => 'in_transit', 'dispatched_by' => $by->id, 'dispatched_on' => $date, 'dispatch_journal_id' => $j->id, 'slip_no' => $slip ?: $t->slip_no])->save();
        });
        ActivityLogger::log('treasury', 'dispatch', "{$t->number} sent – ".money($t->amount).' in transit', $t);
    }

    /** the bank has credited the destination: received (before fee), fee, and a reason for any short / over banking */
    public static function complete(TreasuryTransfer $t, User $by, ?string $date = null, ?float $received = null, ?float $fee = null, ?string $reason = null, ?string $slip = null): void
    {
        $direct = ! $t->via_transit && $t->status === 'approved';
        if ($t->status !== 'in_transit' && ! $direct) {
            throw new RuntimeException("{$t->number} is not in transit.");
        }
        $date ??= bdate()->toDateString();
        self::assertOpenDate($date);
        if ($t->dispatched_on && $date < $t->dispatched_on->toDateString()) {
            throw new RuntimeException('The bank cannot credit it before it was sent ('.dmy($t->dispatched_on).').');
        }
        $received = round($received ?? (float) $t->amount, 2);
        $fee = round($fee ?? (float) $t->fee, 2);
        $variance = round($received - (float) $t->amount, 2);
        if (abs($variance) >= 0.005) {
            if ($t->kind !== 'deposit' || (float) $t->cash_amount <= 0) {
                throw new RuntimeException('Only a cash deposit can be credited short or over – settle fees through the fee.');
            }
            if (abs($variance) > (float) $t->cash_amount) {
                throw new RuntimeException('The difference cannot exceed the cash banked.');
            }
            if (! $reason || mb_strlen(trim($reason)) < 5) {
                throw new RuntimeException('Give the reason the bank credited '.money(abs($variance)).($variance < 0 ? ' less' : ' more').' than the slip.');
            }
        }
        if ($fee < 0 || $fee >= $received) {
            throw new RuntimeException('The fee must be less than the amount received.');
        }
        DB::transaction(function () use ($t, $by, $date, $received, $fee, $variance, $reason, $slip, $direct) {
            $t->load('from', 'to');
            $memo = $t->number.' '.$t->from->name.' → '.$t->to->name;
            $lines = [[$t->to->account_head_id, round($received - $fee, 2), $memo]];
            if ($fee > 0) {
                $lines[] = [$t->fee_account_id ?: AccountHead::sys('bank_charges'), $fee, $memo.' fee'];
            }
            if (abs($variance) >= 0.005) {
                $lines[] = [AccountHead::sys('cash_over_short'), -$variance, $memo.' – '.mb_substr((string) $reason, 0, 60)];
            }
            if ($direct) {
                if ((float) $t->cash_amount > 0) {
                    $lines[] = [$t->from->account_head_id, -(float) $t->cash_amount, $memo];
                }
                if ((float) $t->cheque_amount > 0) {
                    $lines[] = [AccountHead::sys('cheques_in_hand'), -(float) $t->cheque_amount, $memo.' (cheques)'];
                }
                ChequeReceived::where('transfer_id', $t->id)->update(['status' => 'deposited', 'left_on' => $date]);
            } else {
                $lines[] = [AccountHead::sys('cash_in_transit'), -(float) $t->amount, $memo];
            }
            $j = LedgerService::post(['date' => $date, 'reference' => $slip ?: ($t->slip_no ?: $t->number), 'narration' => TreasuryTransfer::KINDS[$t->kind].' credited – '.$t->number, 'lines' => $lines], 'treasury', $t->id.':in');
            $t->forceFill(['status' => 'completed', 'completed_by' => $by->id, 'completed_on' => $date, 'received_amount' => $received, 'fee' => $fee, 'variance' => $variance,
                'variance_reason' => abs($variance) >= 0.005 ? mb_substr((string) $reason, 0, 255) : null, 'complete_journal_id' => $j->id,
                'dispatched_on' => $t->dispatched_on ?? $date, 'dispatched_by' => $t->dispatched_by ?? $by->id, 'slip_no' => $slip ?: $t->slip_no])->save();
        });
        ActivityLogger::log('treasury', 'complete', "{$t->number} credited to {$t->to->name}: ".money($received - $fee).($fee > 0 ? ' after a fee of '.money($fee) : '').(abs($variance) >= 0.005 ? ', '.money($variance).' variance' : ''), $t);
    }

    /** cancel before sending; once in transit, recall it (money back to the source) */
    public static function cancel(TreasuryTransfer $t, User $by, string $reason): void
    {
        if (! in_array($t->status, ['requested', 'approved', 'in_transit'], true)) {
            throw new RuntimeException("{$t->number} is ".strtolower(TreasuryTransfer::STATUSES[$t->status]).'.');
        }
        DB::transaction(function () use ($t, $by, $reason) {
            if ($t->status === 'in_transit') {
                $date = LedgerService::openDate(bdate()->toDateString());
                $t->load('from');
                $lines = [[AccountHead::sys('cash_in_transit'), -(float) $t->amount, $t->number.' recalled']];
                if ((float) $t->cash_amount > 0) {
                    $lines[] = [$t->from->account_head_id, (float) $t->cash_amount, $t->number.' recalled'];
                }
                if ((float) $t->cheque_amount > 0) {
                    $lines[] = [AccountHead::sys('cheques_in_hand'), (float) $t->cheque_amount, $t->number.' recalled (cheques)'];
                }
                $j = LedgerService::post(['date' => $date, 'reference' => $t->number, 'narration' => 'Transfer recalled – '.$t->number.': '.$reason, 'lines' => $lines], 'treasury', $t->id.':recall');
                ChequeReceived::where('transfer_id', $t->id)->update(['status' => 'in_hand', 'left_on' => null, 'transfer_id' => null]);
                $t->forceFill(['complete_journal_id' => $j->id, 'completed_on' => $date, 'completed_by' => $by->id])->save();
            } else {
                ChequeReceived::where('transfer_id', $t->id)->update(['transfer_id' => null]);
            }
            $t->forceFill(['status' => 'cancelled', 'reason' => mb_substr($reason, 0, 255)])->save();
        });
        ActivityLogger::log('treasury', 'cancel', "{$t->number} cancelled: {$reason}", $t);
    }

    public static function assertOpenDate(string $date): void
    {
        $lock = LedgerService::lockDate();
        if ($lock && $date <= $lock) {
            throw new RuntimeException('The ledger is locked up to '.dmy($lock).' – choose a later date.');
        }
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('The date cannot be after the business date ('.dmy(bdate()).').');
        }
    }

    // ───────────────────────── cheques received ─────────────────────────

    public static function receiveCheque(array $d, User $by): ChequeReceived
    {
        $amount = round((float) $d['amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('Enter the cheque amount.');
        }
        $company = ! empty($d['company_id']) ? Company::findOrFail($d['company_id']) : null;
        $head = null;
        if (! $company) {
            $head = AccountHead::findOrFail($d['account_head_id'] ?? 0);
            if (in_array($head->system_key, self::CONTROL_KEYS, true) || in_array($head->group_name, ['Cash & Bank', 'Petty Cash'], true)) {
                throw new RuntimeException('Choose a company for city-ledger receipts; '.$head->name.' cannot take a cheque receipt directly.');
            }
        }
        $received = $d['received_on'] ?? bdate()->toDateString();
        if ($received > bdate()->toDateString()) {
            throw new RuntimeException('The received date cannot be after the business date.');
        }
        $chequeDate = $d['cheque_date'];
        if (Carbon::parse($chequeDate)->addMonthsNoOverflow(ChequeReceived::STALE_MONTHS)->toDateString() < $received) {
            throw new RuntimeException('That cheque is stale – dated more than '.ChequeReceived::STALE_MONTHS.' months before it was received.');
        }
        if (! empty($d['cheque_no']) && ChequeReceived::where('cheque_no', $d['cheque_no'])->where('drawer', $company?->name ?? $d['drawer'])->whereNotIn('status', ['cancelled', 'returned'])->exists()) {
            throw new RuntimeException('Cheque '.$d['cheque_no'].' from this drawer is already in the register.');
        }

        return DB::transaction(function () use ($d, $amount, $company, $head, $received, $chequeDate, $by) {
            $c = ChequeReceived::create([
                'number' => Numbering::next('cheque_received'), 'source' => 'treasury', 'cheque_no' => $d['cheque_no'] ?? null, 'drawer' => $d['drawer'] ?? $company?->name ?? 'Unknown',
                'bank_name' => $d['bank_name'] ?? null, 'bank_branch' => $d['bank_branch'] ?? null, 'cheque_date' => $chequeDate, 'received_on' => $received, 'amount' => $amount, 'company_id' => $company?->id,
                'account_head_id' => $head?->id, 'status' => $chequeDate > bdate()->toDateString() ? 'pdc' : 'in_hand', 'notes' => $d['notes'] ?? null, 'created_by' => $by->id,
            ]);
            if ($c->status === 'in_hand') {
                self::recognise($c, max($received, LedgerService::openDate($received)));
            }
            ActivityLogger::log('treasury', 'cheque_in', "{$c->number} cheque ".($c->cheque_no ?: '')." {$c->party()} ".money($amount).($c->status === 'pdc' ? ' post-dated '.dmy($c->cheque_date) : ''), $c);

            return $c->fresh();
        });
    }

    /** post the receipt: a receipt voucher on the Cheque payment mode (Dr Cheques in Hand) */
    public static function recognise(ChequeReceived $c, string $date): void
    {
        $date = LedgerService::openDate($date);
        $v = Voucher::create([
            'voucher_no' => Numbering::next('voucher'), 'type' => 'receipt', 'date' => $date, 'account_head_id' => $c->company_id ? AccountHead::sys('city_ledger') : $c->account_head_id,
            'payment_mode_id' => self::chequeMode()->id, 'company_id' => $c->company_id, 'party_name' => $c->party(), 'amount' => $c->amount,
            'reference' => mb_substr('CHQ '.($c->cheque_no ?: $c->number), 0, 60), 'narration' => 'Cheque '.($c->cheque_no ?: '').' '.($c->bank_name ?: '').' – '.$c->number, 'user_id' => auth()->id(),
        ]);
        LedgerService::reconcile('voucher', (string) $v->id, LedgerService::forVoucher($v->fresh('paymentMode')), $date);
        $c->forceFill(['voucher_id' => $v->id, 'recognised_on' => $date, 'status' => 'in_hand'])->save();
    }

    /** post-dated cheques whose date has come: post the receipt (night audit / on demand) */
    public static function rollPdcs(?string $upTo = null): int
    {
        $upTo ??= bdate()->toDateString();
        $n = 0;
        foreach (ChequeReceived::where('status', 'pdc')->whereDate('cheque_date', '<=', $upTo)->get() as $c) {
            DB::transaction(fn () => self::recognise($c, $c->cheque_date->toDateString()));
            $n++;
        }

        return $n;
    }

    public static function bankPdc(ChequeReceived $c): void
    {
        if ($c->status !== 'pdc') {
            throw new RuntimeException("{$c->number} is not a post-dated cheque.");
        }
        if ($c->cheque_date->toDateString() > bdate()->toDateString()) {
            throw new RuntimeException('The cheque is dated '.dmy($c->cheque_date).' – it cannot be banked before then.');
        }
        DB::transaction(fn () => self::recognise($c, $c->cheque_date->toDateString()));
        ActivityLogger::log('treasury', 'pdc', "{$c->number} post-dated cheque now due – receipt posted", $c);
    }

    public static function clearCheque(ChequeReceived $c, ?string $date = null): void
    {
        if ($c->status !== 'deposited') {
            throw new RuntimeException("{$c->number} is not waiting to clear.");
        }
        if (! $c->transfer || $c->transfer->status !== 'completed') {
            throw new RuntimeException('Confirm the deposit '.($c->transfer?->number ?? '').' as credited by the bank first.');
        }
        $c->forceFill(['status' => 'cleared', 'cleared_on' => $date ?? bdate()->toDateString()])->save();
    }

    /** the bank returned it unpaid: reverse the bank credit and re-open the receivable */
    public static function bounce(ChequeReceived $c, User $by, string $reason, float $fee = 0, ?string $date = null): void
    {
        if (! in_array($c->status, ['deposited', 'cleared'], true)) {
            throw new RuntimeException('Only a banked cheque can bounce.');
        }
        $t = $c->transfer;
        if (! $t || $t->status !== 'completed') {
            throw new RuntimeException('The deposit must be credited by the bank before it can come back unpaid.');
        }
        $date ??= bdate()->toDateString();
        $date = LedgerService::openDate(max($date, $t->completed_on->toDateString()));
        $fee = round(max(0, $fee), 2);
        DB::transaction(function () use ($c, $t, $by, $reason, $fee, $date) {
            $bank = $t->to->account_head_id;
            $memo = $c->number.' cheque '.($c->cheque_no ?: '').' returned – '.mb_substr($reason, 0, 60);
            $lines = [[AccountHead::sys('cheques_in_hand'), (float) $c->amount, $memo], [$bank, -(float) $c->amount - $fee, $memo]];
            if ($fee > 0) {
                $lines[] = [AccountHead::sys('bank_charges'), $fee, $c->number.' returned-cheque charge'];
            }
            $j = LedgerService::post(['date' => $date, 'reference' => 'CHQ '.($c->cheque_no ?: $c->number), 'narration' => 'Returned cheque – '.$c->party(), 'lines' => $lines], 'cheque_in', $c->id.':bounce');
            $v = self::reverseReceipt($c, $date, 'Returned cheque '.($c->cheque_no ?: '').' – '.$reason);
            $c->forceFill(['status' => 'bounced', 'bounced_on' => $date, 'bounce_reason' => mb_substr($reason, 0, 150), 'bounce_fee' => $fee, 'bounce_journal_id' => $j->id, 'bounce_voucher_id' => $v->id])->save();
        });
        ActivityLogger::log('treasury', 'bounce', "{$c->number} cheque from {$c->party()} ".money($c->amount)." returned unpaid: {$reason}", $c);
        if ($c->company) {
            CreditService::log($c->company, 'cheque_bounced', "Cheque {$c->cheque_no} of ".money($c->amount)." returned unpaid: {$reason}", (float) $c->amount);
        }
    }

    /** give a cheque back before banking (replaced, wrong amount …); a post-dated one is simply cancelled */
    public static function returnCheque(ChequeReceived $c, User $by, string $reason): void
    {
        if ($c->status === 'pdc') {
            $c->forceFill(['status' => 'cancelled', 'notes' => mb_substr(trim(($c->notes ? $c->notes.' · ' : '').'Cancelled: '.$reason), 0, 255)])->save();
            ActivityLogger::log('treasury', 'cheque_cancel', "{$c->number} post-dated cheque cancelled: {$reason}", $c);

            return;
        }
        if ($c->status !== 'in_hand' || $c->transfer_id) {
            throw new RuntimeException($c->transfer_id ? 'Take it off deposit '.$c->transfer?->number.' first.' : "{$c->number} is ".strtolower(ChequeReceived::STATUSES[$c->status]).'.');
        }
        if ($c->source === 'folio') {
            throw new RuntimeException('This cheque was taken on a guest folio – void that payment on the folio instead.');
        }
        DB::transaction(function () use ($c, $reason) {
            $date = LedgerService::openDate(bdate()->toDateString());
            $v = in_array($c->source, ['treasury', 'voucher'], true) ? self::reverseReceipt($c, $date, 'Cheque '.($c->cheque_no ?: '').' returned to drawer – '.$reason) : null;
            $c->forceFill(['status' => 'returned', 'left_on' => $date, 'bounce_voucher_id' => $v?->id, 'bounce_reason' => mb_substr($reason, 0, 150)])->save();
        });
        ActivityLogger::log('treasury', 'cheque_return', "{$c->number} returned to {$c->party()}: {$reason}", $c);
    }

    protected static function reverseReceipt(ChequeReceived $c, string $date, string $why): Voucher
    {
        $v = Voucher::create([
            'voucher_no' => Numbering::next('voucher'), 'type' => 'receipt', 'date' => $date, 'account_head_id' => $c->company_id ? AccountHead::sys('city_ledger') : ($c->account_head_id ?: AccountHead::sys('suspense')),
            'payment_mode_id' => self::chequeMode()->id, 'company_id' => $c->company_id, 'party_name' => $c->party(), 'amount' => -(float) $c->amount,
            'reference' => mb_substr('CHQ '.($c->cheque_no ?: $c->number).' RTN', 0, 60), 'narration' => mb_substr($why, 0, 500), 'user_id' => auth()->id(),
        ]);
        LedgerService::reconcile('voucher', (string) $v->id, LedgerService::forVoucher($v->fresh('paymentMode')), $date);

        return $v;
    }

    /** cheque-mode receipts entered elsewhere (city-ledger receipt, folio payment) join the register */
    public static function capture(): int
    {
        $mode = PaymentMode::where('type', 'cheque')->pluck('id');
        if ($mode->isEmpty()) {
            return 0;
        }
        $n = 0;
        $known = ChequeReceived::whereNotNull('voucher_id')->pluck('voucher_id')->merge(ChequeReceived::whereNotNull('bounce_voucher_id')->pluck('bounce_voucher_id'))->flip();
        foreach (Voucher::where('type', 'receipt')->whereIn('payment_mode_id', $mode)->where('amount', '>', 0)->get() as $v) {
            if (isset($known[$v->id])) {
                continue;
            }
            ChequeReceived::create(['number' => Numbering::next('cheque_received'), 'source' => 'voucher', 'cheque_no' => $v->reference ? mb_substr($v->reference, 0, 20) : null,
                'drawer' => $v->party_name ?: 'Unknown', 'cheque_date' => $v->date, 'received_on' => $v->date, 'amount' => $v->amount, 'company_id' => $v->company_id,
                'account_head_id' => $v->company_id ? null : $v->account_head_id, 'voucher_id' => $v->id, 'recognised_on' => $v->date, 'status' => 'in_hand', 'created_by' => $v->user_id]);
            $n++;
        }
        $tx = ChequeReceived::whereNotNull('folio_transaction_id')->pluck('folio_transaction_id')->flip();
        foreach (FolioTransaction::with('folio.guest')->where('type', 'payment')->whereIn('payment_mode_id', $mode)->get() as $f) {
            if (isset($tx[$f->id])) {
                continue;
            }
            if ($f->is_void) {
                continue;
            }
            ChequeReceived::create(['number' => Numbering::next('cheque_received'), 'source' => 'folio',
                'cheque_no' => $f->cheque_number ?: ($f->reference ? mb_substr($f->reference, 0, 20) : null),
                'drawer' => $f->folio?->guest?->full_name ?? 'Guest', 'bank_name' => $f->cheque_bank_name, 'bank_branch' => $f->cheque_bank_branch,
                'cheque_date' => $f->business_date, 'received_on' => $f->business_date, 'amount' => $f->amount,
                'folio_transaction_id' => $f->id, 'recognised_on' => $f->business_date, 'status' => 'in_hand', 'notes' => 'Folio '.$f->folio?->folio_no, 'created_by' => $f->posted_by]);
            $n++;
        }
        // a voided folio payment takes its cheque out of hand
        foreach (ChequeReceived::where('source', 'folio')->where('status', 'in_hand')->whereNull('transfer_id')->get() as $c) {
            $f = FolioTransaction::find($c->folio_transaction_id);
            if (! $f || $f->is_void) {
                $c->forceFill(['status' => 'cancelled', 'left_on' => bdate()->toDateString(), 'notes' => 'Folio payment voided'])->save();
            }
        }

        return $n;
    }

    // ───────────────────────── cheques issued ─────────────────────────

    public static function addBook(TreasuryAccount $a, int $first, int $last, int $digits, string $date): ChequeBook
    {
        if ($a->kind !== 'bank') {
            throw new RuntimeException('Cheque books belong to bank accounts.');
        }
        if ($last < $first || $last - $first > 500) {
            throw new RuntimeException('Enter a leaf range of up to 500 cheques.');
        }
        $clash = ChequeBook::where('treasury_account_id', $a->id)->where('first_no', '<=', $last)->where('last_no', '>=', $first)->exists();
        if ($clash) {
            throw new RuntimeException('That range overlaps a cheque book already registered for '.$a->name.'.');
        }
        $b = ChequeBook::create(['treasury_account_id' => $a->id, 'first_no' => $first, 'last_no' => $last, 'next_no' => $first, 'digits' => max(4, min(10, $digits)), 'received_on' => $date, 'status' => 'active']);
        ActivityLogger::log('treasury', 'cheque_book', "Cheque book {$b->range()} for {$a->name}", $b);

        return $b;
    }

    protected static function takeLeaf(ChequeBook $b): string
    {
        $b = ChequeBook::whereKey($b->id)->lockForUpdate()->first();
        if ($b->status !== 'active' || $b->next_no > $b->last_no) {
            throw new RuntimeException('Cheque book '.$b->range().' has no leaves left.');
        }
        $no = $b->fmt($b->next_no);
        $b->next_no++;
        if ($b->next_no > $b->last_no) {
            $b->status = 'used';
        }
        $b->save();

        return $no;
    }

    public static function issueCheque(array $d, User $by): ChequeIssued
    {
        $book = ChequeBook::with('account')->findOrFail($d['cheque_book_id']);
        $bankHead = (int) $book->account->account_head_id;
        $date = $d['cheque_date'] ?? bdate()->toDateString();
        $payment = ! empty($d['supplier_payment_id']) ? SupplierPayment::with('supplier', 'paymentMode')->findOrFail($d['supplier_payment_id']) : null;
        if ($payment) {
            if ($payment->cancelled_at) {
                throw new RuntimeException("{$payment->payment_no} is cancelled.");
            }
            if (ChequeIssued::where('supplier_payment_id', $payment->id)->whereNotIn('status', ['void'])->exists()) {
                throw new RuntimeException("{$payment->payment_no} already has a cheque.");
            }
            if (LedgerService::modeAccount($payment->payment_mode_id) !== $bankHead) {
                throw new RuntimeException("{$payment->payment_no} was paid from ".($payment->paymentMode?->name ?? 'another account').', not '.$book->account->name.'.');
            }
            $amount = round((float) $payment->amount, 2);
            $payee = $payment->supplier?->name ?? 'Supplier';
            $head = null;
        } else {
            $amount = round((float) ($d['amount'] ?? 0), 2);
            $payee = trim((string) ($d['payee'] ?? ''));
            $head = AccountHead::findOrFail($d['account_head_id'] ?? 0);
            if ($amount <= 0 || $payee === '') {
                throw new RuntimeException('Enter the payee and amount.');
            }
            if (in_array($head->system_key, self::CONTROL_KEYS, true) || in_array($head->group_name, ['Cash & Bank', 'Petty Cash'], true)) {
                throw new RuntimeException($head->name.' is a control or cash account – pay suppliers through Payables and move money between accounts with a transfer.');
            }
            self::assertOpenDate($date);
            $avail = self::balance($bankHead) + (float) $book->account->overdraft_limit;
            if ($amount > $avail + 0.004) {
                throw new RuntimeException($book->account->name.' would go beyond its overdraft limit ('.money($avail).' available).');
            }
        }

        return DB::transaction(function () use ($book, $bankHead, $date, $payment, $amount, $payee, $head, $d, $by) {
            $no = self::takeLeaf($book);
            $c = ChequeIssued::create(['cheque_book_id' => $book->id, 'cheque_no' => $no, 'payee' => mb_substr($payee, 0, 150), 'amount' => $amount, 'cheque_date' => $payment ? $payment->date : $date,
                'supplier_payment_id' => $payment?->id, 'account_head_id' => $head?->id, 'memo' => $d['memo'] ?? null, 'status' => 'issued', 'issued_by' => $by->id]);
            if (! $payment) {
                $j = LedgerService::post(['date' => $date, 'reference' => 'CHQ '.$no, 'narration' => 'Cheque '.$no.' to '.$payee.($c->memo ? ' – '.$c->memo : ''),
                    'lines' => [[$head->id, $amount, $payee], [$bankHead, -$amount, 'Cheque '.$no]]], 'cheque_out', (string) $c->id);
                $c->forceFill(['gl_journal_id' => $j->id])->save();
            }
            ActivityLogger::log('treasury', 'cheque_out', "Cheque {$no} ".money($amount)." to {$payee}".($payment ? " for {$payment->payment_no}" : ''), $c);

            return $c;
        });
    }

    public static function spoilLeaf(ChequeBook $book, User $by, string $reason): ChequeIssued
    {
        return DB::transaction(function () use ($book, $by, $reason) {
            $no = self::takeLeaf($book);
            $c = ChequeIssued::create(['cheque_book_id' => $book->id, 'cheque_no' => $no, 'payee' => null, 'amount' => 0, 'cheque_date' => bdate()->toDateString(), 'status' => 'void',
                'void_reason' => mb_substr('Spoilt leaf: '.$reason, 0, 255), 'voided_at' => now(), 'issued_by' => $by->id]);
            ActivityLogger::log('treasury', 'cheque_void', "Cheque leaf {$no} spoilt: {$reason}", $c);

            return $c;
        });
    }

    /** void (never left the building) or stop (bank instructed not to pay) – a standalone cheque's journal is reversed */
    public static function voidCheque(ChequeIssued $c, User $by, string $reason, bool $stop = false): void
    {
        if ($c->status !== 'issued' && ! ($c->status === 'stale' && ! $stop)) {
            throw new RuntimeException('Cheque '.$c->cheque_no.' is '.strtolower(ChequeIssued::STATUSES[$c->status]).'.');
        }
        if ($c->payment && ! $c->payment->cancelled_at) {
            throw new RuntimeException('Cheque '.$c->cheque_no.' pays '.$c->payment->payment_no.' – cancel that payment in Payables first, then void the cheque.');
        }
        DB::transaction(function () use ($c, $reason, $stop) {
            if ($c->journal && ! $c->journal->reversed_by_id && $c->status !== 'stale') {
                LedgerService::reverse($c->journal, bdate()->toDateString(), ($stop ? 'cheque stopped: ' : 'cheque void: ').$reason);
            }
            $c->forceFill(['status' => $stop ? 'stopped' : 'void', 'void_reason' => mb_substr($reason, 0, 255), 'voided_at' => now()])->save();
        });
        ActivityLogger::log('treasury', $stop ? 'cheque_stop' : 'cheque_void', 'Cheque '.$c->cheque_no.' '.($stop ? 'stopped' : 'voided').": {$reason}", $c);
    }

    public static function markIssuedCleared(ChequeIssued $c, ?string $date = null): void
    {
        if ($c->status !== 'issued') {
            throw new RuntimeException('Cheque '.$c->cheque_no.' is '.strtolower(ChequeIssued::STATUSES[$c->status]).'.');
        }
        $c->forceFill(['status' => 'cleared', 'cleared_on' => $date ?? bdate()->toDateString()])->save();
    }

    /** the bank-side GL line of an issued cheque (its own journal, or the supplier payment's) */
    public static function bankLine(ChequeIssued $c): ?GlLine
    {
        $bank = (int) $c->book->account->account_head_id;
        $j = $c->gl_journal_id ? GlJournal::find($c->gl_journal_id)
            : ($c->supplier_payment_id ? GlJournal::where('source_type', 'ap_pay')->where('source_key', (string) $c->supplier_payment_id)->whereNull('reversed_by_id')->whereNull('reversal_of_id')->latest('id')->first() : null);

        return $j ? GlLine::where('gl_journal_id', $j->id)->where('account_head_id', $bank)->where('credit', '>', 0)->first() : null;
    }

    /** issued cheques matched to a bank statement line in the bank reconciliation are cleared */
    public static function syncClearing(): int
    {
        $n = 0;
        foreach (ChequeIssued::with('book.account')->where('status', 'issued')->get() as $c) {
            $line = self::bankLine($c);
            $m = $line ? BankMatch::with('line')->where('gl_line_id', $line->id)->first() : null;
            if ($m) {
                $c->forceFill(['status' => 'cleared', 'cleared_on' => $m->line?->date ?? bdate()->toDateString()])->save();
                $n++;
            }
        }

        return $n;
    }

    /** daily housekeeping (night audit): capture cheque-mode receipts, post due PDCs, clear banked cheques, stale issued cheques */
    public static function daily(?string $date = null): array
    {
        $date ??= bdate()->toDateString();
        $out = ['captured' => self::capture(), 'pdcs' => self::rollPdcs($date), 'cleared' => 0, 'stale' => 0, 'presented' => self::syncClearing()];
        $days = (int) self::cfg('treasury_clear_days');
        foreach (ChequeReceived::with('transfer')->where('status', 'deposited')->get() as $c) {
            if ($c->transfer && $c->transfer->status === 'completed' && $c->transfer->completed_on->copy()->addDays($days)->toDateString() <= $date) {
                $c->forceFill(['status' => 'cleared', 'cleared_on' => $c->transfer->completed_on->copy()->addDays($days)->toDateString()])->save();
                $out['cleared']++;
            }
        }
        foreach (ChequeIssued::where('status', 'issued')->get() as $c) {
            if ($c->staleOn()->toDateString() < $date) {
                DB::transaction(function () use ($c, $date) {
                    if ($c->journal && ! $c->journal->reversed_by_id) {
                        LedgerService::reverse($c->journal, $date, 'stale cheque written back');
                    }
                    $c->forceFill(['status' => 'stale'])->save();
                });
                $out['stale']++;
            }
        }

        return $out;
    }

    // ───────────────────────── sub-ledger controls ─────────────────────────

    /** cheques in hand per the register at a date (receipt posted, not yet banked / returned) */
    public static function chequesInHand(string $asOf): float
    {
        return round((float) ChequeReceived::whereNotNull('recognised_on')->whereDate('recognised_on', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('left_on')->orWhereDate('left_on', '>', $asOf))->sum('amount'), 2);
    }

    /** transfers sent through Cash in Transit and not yet credited / recalled at a date */
    public static function inTransit(string $asOf): float
    {
        return round((float) TreasuryTransfer::where('via_transit', true)->whereNotNull('dispatch_journal_id')->whereDate('dispatched_on', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('completed_on')->orWhereDate('completed_on', '>', $asOf))->sum('amount'), 2);
    }

    // ───────────────────────── 13-week cash forecast ─────────────────────────

    public static function forecast(int $weeks = 13): array
    {
        $today = bdate()->copy()->startOfDay();
        $start = $today->copy()->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addWeeks($weeks)->subDay();
        $T = $today->toDateString();
        $E = $end->toDateString();
        $items = collect();
        $add = function (string $date, string $dir, string $cat, string $label, float $amt, ?string $url = null) use ($items, $T, $E) {
            $amt = round($amt, 2);
            if ($amt < 0.005) {
                return;
            }
            $date = max($date, $T);
            if ($date > $E) {
                return;
            }
            $items->push(['date' => $date, 'dir' => $dir, 'cat' => $cat, 'label' => $label, 'amount' => $amt, 'url' => $url]);
        };

        $pos = self::position($T);
        $opening = $pos['cash'];

        // settlements owed by acquirers (card / gateway clearing) and MoMo
        foreach ($pos['rows'] as $r) {
            $a = $r['account'];
            if ($a->kind === 'clearing' && $r['balance'] > 0) { // card acquirers & the gateway pay out after their settlement days
                $add($today->copy()->addDays((int) $a->settle_days)->toDateString(), 'in', 'Card & online settlements', $a->name.' (net of '.((float) $a->fee_rate).'% fee)', $r['balance'] * (1 - (float) $a->fee_rate / 100));
            }
        }
        // city ledger receivables by due date
        $pct = (float) self::cfg('treasury_collect_pct') / 100;
        foreach (CreditService::positions() as $p) {
            foreach ($p['open_bills'] as $b) {
                $overdue = $b['due']->toDateString() < $T;
                $add($b['due']->toDateString(), 'in', 'City ledger', $p['company']->name.($overdue ? ' (overdue)' : ''), $b['amount'] * ($overdue ? $pct : 1), route('accounts.company', $p['company']->id));
            }
        }
        // post-dated cheques
        foreach (ChequeReceived::with('company')->where('status', 'pdc')->get() as $c) {
            $add($c->cheque_date->toDateString(), 'in', 'Post-dated cheques', $c->party().' cheque '.($c->cheque_no ?: ''), (float) $c->amount);
        }
        // in-house guests settle their balance at departure; guest-paid arrivals pay for their stay
        foreach (Folio::with('stay')->where('status', 'open')->whereNull('company_id')->get() as $f) {
            $bal = $f->balance();
            if ($bal > 0) {
                $add($f->stay?->departure_date?->toDateString() ?? $T, 'in', 'Guests in house', 'Folio '.$f->folio_no, $bal);
            }
        }
        $arr = (float) self::cfg('treasury_arrival_pct') / 100;
        $byWeek = [];
        foreach (ReservationRoom::with('reservation')->where('status', 'reserved')->whereHas('reservation', fn ($q) => $q->where('status', 'confirmed'))->whereDate('arrival_date', '>=', $T)->whereDate('arrival_date', '<=', $E)->get() as $rr) {
            if (($rr->reservation?->billing_instruction ?? 'guest') !== 'guest') {
                continue;
            }
            $nights = max(1, $rr->arrival_date->diffInDays($rr->departure_date));
            $dep = $rr->departure_date->toDateString();
            $byWeek[$dep] = ($byWeek[$dep] ?? 0) + (float) $rr->rate * $nights * $arr;
        }
        foreach ($byWeek as $d => $amt) {
            $add($d, 'in', 'Arrivals (guest-paid stays)', 'Stays departing '.dmy($d), $amt);
        }
        // tenants
        foreach (LeaseCharge::with('lease')->whereNull('cancelled_at')->whereNull('credit_of_id')->get() as $lc) {
            $o = $lc->outstanding();
            if ($o > 0) {
                $add(($lc->due_date ?? $lc->date)->toDateString(), 'in', 'Tenants', ($lc->lease?->lease_no ?? 'Lease').' '.$lc->charge_no, $o);
            }
        }
        // suppliers
        foreach (SupplierInvoice::with('supplier')->whereIn('status', ['open', 'partial', 'on_hold'])->where('type', '!=', 'debit_note')->get() as $inv) {
            $o = $inv->outstanding();
            if ($o > 0) {
                $add(($inv->due_date ?? $inv->doc_date)->toDateString(), 'out', 'Suppliers', ($inv->supplier?->name ?? 'Supplier').' '.$inv->doc_no, $o, route('ap.bills.show', $inv->id));
            }
        }
        // cheques written, not yet presented
        foreach (ChequeIssued::where('status', 'issued')->get() as $c) {
            $add($T, 'out', 'Cheques not presented', 'Cheque '.$c->cheque_no.' '.$c->payee, (float) $c->amount);
        }
        // payroll & statutory: the last approved run repeats monthly
        $run = PayrollRun::whereIn('status', ['approved', 'paid'])->orderByDesc('period_end')->first();
        if ($run) {
            $payday = (int) self::cfg('treasury_payday');
            $unpaid = $run->status === 'approved' ? (float) $run->net : 0.0;
            if ($unpaid > 0) {
                $add($T, 'out', 'Payroll', 'Salaries '.$run->run_no.' (approved, unpaid)', $unpaid);
            }
            $stat = ['ssnit' => -LedgerService::balance('pf_payable', $T), 'tier2' => -LedgerService::balance('esi_payable', $T), 'paye' => -LedgerService::balance('paye_payable', $T)];
            for ($m = $today->copy()->startOfMonth(), $i = 0; $i < 4; $m->addMonthNoOverflow(), $i++) {
                $pd = $m->copy()->day(min($payday, $m->daysInMonth))->toDateString();
                $period = $m->format('Y-m');
                if ($pd >= $T && $period > $run->period_end->format('Y-m')) {
                    $add($pd, 'out', 'Payroll', 'Salaries '.$m->format('M Y').' (as '.$run->run_no.')', (float) $run->net);
                }
                foreach ($stat as $kind => $amt) {
                    $due = $m->copy()->day(StatutoryService::KINDS[$kind][2])->toDateString();
                    if ($due >= $T && $amt > 0) {
                        $add($due, 'out', 'Statutory (SSNIT / GRA)', StatutoryService::label($kind).' due '.dmy($due), $amt);
                    }
                }
            }
        }
        // VAT, levies and the tourism levy: the month's net liability is paid by the end of the following month
        $vat = -LedgerService::balance('tax_payable', $T) - LedgerService::balance('tourism_levy', $T) - LedgerService::balance('input_tax', $T);
        if ($vat > 0) {
            $add($today->copy()->endOfMonth()->toDateString(), 'out', 'Taxes (GRA / GTA)', 'VAT, NHIL, GETFund & tourism levy payable', $vat);
        }
        // manual lines
        foreach (CashForecastLine::where('is_active', true)->get() as $l) {
            foreach ($l->occurrences($T, $E) as $d) {
                $add($d, $l->direction, $l->category, $l->description, (float) $l->amount);
            }
        }

        $cats = ['in' => [], 'out' => []];
        $rows = [];
        $bal = $opening;
        $min = (float) TreasuryAccount::where('is_active', true)->where('in_position', true)->sum('min_balance');
        for ($w = 0; $w < $weeks; $w++) {
            $ws = $start->copy()->addWeeks($w);
            $we = $ws->copy()->addDays(6);
            $in = $items->where('dir', 'in')->filter(fn ($i) => $i['date'] >= $ws->toDateString() && $i['date'] <= $we->toDateString());
            $out = $items->where('dir', 'out')->filter(fn ($i) => $i['date'] >= $ws->toDateString() && $i['date'] <= $we->toDateString());
            $inBy = $in->groupBy('cat')->map(fn ($g) => round($g->sum('amount'), 2))->all();
            $outBy = $out->groupBy('cat')->map(fn ($g) => round($g->sum('amount'), 2))->all();
            foreach (array_keys($inBy) as $c) {
                $cats['in'][$c] = true;
            }
            foreach (array_keys($outBy) as $c) {
                $cats['out'][$c] = true;
            }
            $open = $bal;
            $net = round($in->sum('amount') - $out->sum('amount'), 2);
            $bal = round($bal + $net, 2);
            $rows[] = ['n' => $w + 1, 'start' => $ws, 'end' => $we, 'opening' => round($open, 2), 'in' => $inBy, 'out' => $outBy, 'in_total' => round($in->sum('amount'), 2),
                'out_total' => round($out->sum('amount'), 2), 'net' => $net, 'closing' => $bal, 'short' => $bal < $min];
        }
        $low = collect($rows)->sortBy('closing')->first();

        return ['opening' => $opening, 'weeks' => $rows, 'cats' => ['in' => array_keys($cats['in']), 'out' => array_keys($cats['out'])], 'items' => $items->sortBy('date')->values(),
            'min' => $min, 'low' => $low, 'first_short' => collect($rows)->firstWhere('short', true), 'from' => $start->toDateString(), 'to' => $E,
            'total_in' => round($items->where('dir', 'in')->sum('amount'), 2), 'total_out' => round($items->where('dir', 'out')->sum('amount'), 2)];
    }

    public static function saveLine(array $d, User $by, ?CashForecastLine $l = null): CashForecastLine
    {
        $data = ['description' => $d['description'], 'direction' => $d['direction'], 'category' => $d['category'] ?? 'Other', 'amount' => round((float) $d['amount'], 2),
            'first_date' => $d['first_date'], 'frequency' => $d['frequency'] ?? 'once', 'until_date' => ($d['frequency'] ?? 'once') === 'once' ? null : ($d['until_date'] ?? null), 'is_active' => true];
        if (! empty($data['until_date']) && $data['until_date'] < $data['first_date']) {
            throw new RuntimeException('The last date is before the first.');
        }
        if ($l) {
            $l->forceFill($data)->save();
        } else {
            $l = CashForecastLine::create($data + ['created_by' => $by->id]);
        }
        ActivityLogger::log('treasury', 'forecast', 'Forecast line: '.$l->description.' '.money($l->amount).' '.strtolower(CashForecastLine::FREQUENCIES[$l->frequency]), $l);

        return $l;
    }

    // ───────────────────────── IAS 7 cash-flow statement (indirect) ─────────────────────────

    public static function cfClass(AccountHead $a): ?string
    {
        if (in_array($a->type, ['income', 'expense'], true)) {
            return null; // in profit
        }
        if ($a->cf_class && isset(self::CF_CLASSES[$a->cf_class])) {
            return $a->cf_class;
        }

        return self::autoClass($a);
    }

    public static function autoClass(AccountHead $a): string
    {
        if (in_array($a->group_name, ['Cash & Bank', 'Petty Cash'], true)) {
            return in_array($a->system_key, ['card_clearing', 'gateway_clearing'], true) ? 'operating' : 'cash';
        }
        if ($a->system_key === 'acc_depreciation') {
            return 'noncash';
        }
        if ($a->group_name === 'Fixed Assets' || in_array($a->system_key, ['fixed_assets'], true)) {
            return 'investing';
        }
        if ($a->type === 'equity' || $a->group_name === 'Long-term Liabilities' || $a->system_key === 'bank_loans') {
            return 'financing';
        }

        return 'operating';
    }

    /** cash & cash-equivalent account ids */
    public static function cashIds(): array
    {
        return AccountHead::whereIn('type', ['asset'])->get()->filter(fn ($a) => self::cfClass($a) === 'cash')->pluck('id')->all();
    }

    public static function cashFlow(string $from, string $to): array
    {
        $heads = AccountHead::orderBy('code')->get()->keyBy('id');
        $mv = GlLine::whereBetween('date', [$from, $to])->where('closing', false)->groupBy('account_head_id')->selectRaw('account_head_id, SUM(debit - credit) m')->pluck('m', 'account_head_id');
        $cashIds = self::cashIds();
        $before = Carbon::parse($from)->subDay()->toDateString();
        $open = round((float) GlLine::whereIn('account_head_id', $cashIds)->whereDate('date', '<=', $before)->sum(DB::raw('debit - credit')), 2);
        $close = round((float) GlLine::whereIn('account_head_id', $cashIds)->whereDate('date', '<=', $to)->sum(DB::raw('debit - credit')), 2);

        $profit = 0.0;
        $sec = ['noncash' => [], 'operating' => [], 'investing' => [], 'financing' => []];
        foreach ($mv as $id => $m) {
            $a = $heads[$id] ?? null;
            if (! $a) {
                continue;
            }
            $m = round((float) $m, 2);
            if (abs($m) < 0.005) {
                continue;
            }
            $cls = self::cfClass($a);
            if ($cls === null) {
                $profit -= $m;
                continue;
            }
            if ($cls === 'cash') {
                continue;
            }
            $label = match ($cls) {
                'noncash' => $a->system_key === 'acc_depreciation' ? 'Depreciation (net of disposals)' : $a->name,
                'operating' => self::wcLabel($a, -$m),
                default => $a->name,
            };
            $sec[$cls][] = ['id' => $a->id, 'code' => $a->code, 'label' => $label, 'group' => $a->group_name, 'amount' => -$m];
        }
        $profit = round($profit, 2);
        $sum = fn ($rows) => round(array_sum(array_column($rows, 'amount')), 2);
        $operating = round($profit + $sum($sec['noncash']) + $sum($sec['operating']), 2);
        $investing = $sum($sec['investing']);
        $financing = $sum($sec['financing']);
        $net = round($operating + $investing + $financing, 2);
        usort($sec['operating'], fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));

        return ['from' => $from, 'to' => $to, 'profit' => $profit, 'noncash' => $sec['noncash'], 'working' => $sec['operating'], 'investing' => $sec['investing'], 'financing' => $sec['financing'],
            'operating' => $operating, 'investing_total' => $investing, 'financing_total' => $financing, 'net' => $net, 'opening' => $open, 'closing' => $close,
            'difference' => round($open + $net - $close, 2)];
    }

    protected static function wcLabel(AccountHead $a, float $effect): string
    {
        $up = $a->type === 'asset' ? $effect < 0 : $effect > 0;

        return ($up ? 'Increase' : 'Decrease').' in '.$a->name;
    }

    // ───────────────────────── attention ─────────────────────────

    /** badge: transfers waiting for this approver, post-dated cheques due, cheques in hand to bank, deposits in transit 3+ days */
    public static function attention(): int
    {
        $u = auth()->user();
        $k = \App\Support\Tenant::id().':'.($u?->id ?? 0);
        if (isset(self::$memo[$k])) {
            return self::$memo[$k];
        }
        $n = 0;
        try {
            if ($u && $u->hasPermission('treasury.approve')) {
                $n += TreasuryTransfer::where('status', 'requested')->where('requested_by', '!=', $u->id)->count();
            }
            $T = bdate()->toDateString();
            $n += ChequeReceived::where('status', 'pdc')->whereDate('cheque_date', '<=', $T)->count();
            $n += TreasuryTransfer::where('status', 'in_transit')->whereDate('dispatched_on', '<', bdate()->copy()->subDays(2)->toDateString())->count();
        } catch (\Throwable $e) {
            $n = 0;
        }

        return self::$memo[$k] = $n;
    }

    public static function alerts(): array
    {
        $T = bdate()->toDateString();
        $out = [];
        $pos = self::position($T);
        foreach ($pos['alerts'] as $r) {
            $out[] = ['tone' => 'red', 'text' => $r['account']->name.($r['overdrawn'] ? ' is overdrawn: ' : ' is below its minimum of '.money($r['account']->min_balance).': ').money($r['balance'])];
        }
        if ($n = ChequeReceived::where('status', 'pdc')->whereDate('cheque_date', '<=', $T)->count()) {
            $out[] = ['tone' => 'amber', 'text' => $n.' post-dated cheque(s) now due – bank them'];
        }
        $inHand = ChequeReceived::where('status', 'in_hand')->whereNull('transfer_id')->get();
        if ($inHand->count()) {
            $out[] = ['tone' => 'amber', 'text' => $inHand->count().' cheque(s) in hand not yet banked ('.money($inHand->sum('amount')).')'];
        }
        $old = TreasuryTransfer::where('status', 'in_transit')->whereDate('dispatched_on', '<', bdate()->copy()->subDays(2)->toDateString())->count();
        if ($old) {
            $out[] = ['tone' => 'red', 'text' => $old.' transfer(s) in transit for more than two days – confirm with the bank'];
        }
        $cash = self::accounts()->firstWhere('kind', 'cash');
        if ($cash) {
            $s = self::suggest('deposit', $cash);
            if ($s['amount'] >= (float) self::cfg('treasury_cash_float')) {
                $out[] = ['tone' => 'blue', 'text' => money($s['amount']).' cash in hand ready to bank'];
            }
        }

        return $out;
    }
}
