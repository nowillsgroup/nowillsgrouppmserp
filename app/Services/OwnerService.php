<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Asset;
use App\Models\CapexProject;
use App\Models\ClosePeriod;
use App\Models\FfeEntry;
use App\Models\ManagementAgreement;
use App\Models\OwnerFee;
use App\Models\OwnerQuery;
use App\Models\OwnerStatement;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.8 Owner reporting: the hotel management agreement and its fees, the FF&E reserve, owner-approved capex and the
 * monthly owner pack.
 *
 * Fees (per calendar month under the agreement, accrued at month end once the night audit has closed the month):
 *  - base fee        = total operating revenue × base %
 *  - FF&E reserve    = total operating revenue × the contract year's reserve % (memorandum – funded by bank transfer)
 *  - incentive fee   = incentive % × (adjusted GOP for the fee year to date − owner's priority to date), never below
 *                      zero for the year; each month accrues the year-to-date figure less what earlier months accrued,
 *                      so a weak month trues the incentive down. Adjusted GOP = GOP − base fee − FF&E reserve.
 *    The fee year is the fiscal year (from the agreement start in its first year); months accrue in order.
 *  - posting         Dr 6600 base fee, Dr 6605 incentive fee (Cr when trued down) / Cr 2160 Management Fees Payable;
 *                    payment Dr 2160 / Cr the bank account.
 */
class OwnerService
{
    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
        UsaliService::flush();
    }

    public static function ensureDefaults(): void
    {
        ChartOfAccounts::ensureLight();
        foreach (\App\Models\Department::whereNull('usali_dept')->get() as $d) {
            $d->forceFill(['usali_dept' => UsaliService::deptDefault($d)])->save();
        }
    }

    // ───────────────────────── agreement ─────────────────────────

    public static function agreement(): ?ManagementAgreement
    {
        return ManagementAgreement::where('status', 'active')->orderByDesc('effective_from')->first();
    }

    public static function agreementFor(Carbon $monthStart): ?ManagementAgreement
    {
        $k = 'ag:'.\App\Support\Tenant::id().':'.$monthStart->format('Y-m');
        if (array_key_exists($k, self::$memo)) {
            return self::$memo[$k];
        }

        return self::$memo[$k] = ManagementAgreement::where('status', 'active')->orderByDesc('effective_from')->get()
            ->first(fn ($a) => $a->covers($monthStart->copy()->startOfMonth()));
    }

    public static function saveAgreement(array $d, User $by): ManagementAgreement
    {
        $sched = array_values(array_filter(array_map(fn ($v) => $v === null || $v === '' ? null : round((float) $v, 2), (array) ($d['ffe_schedule'] ?? [])), fn ($v) => $v !== null));
        foreach ($sched as $v) {
            if ($v < 0 || $v > 10) {
                throw new RuntimeException('FF&E reserve steps must be between 0% and 10% of revenue.');
            }
        }
        if ((float) $d['base_fee_pct'] > 10 || (float) $d['incentive_pct'] > 30) {
            throw new RuntimeException('That fee is out of range – base fees are normally 1–5% of revenue and incentive fees 5–20% of adjusted GOP.');
        }
        if (! empty($d['reserve_account_id'])) {
            $acc = TreasuryAccount::find($d['reserve_account_id']);
            if (! $acc || $acc->kind !== 'bank') {
                throw new RuntimeException('The FF&E reserve account must be a bank account in Treasury.');
            }
        }
        $a = self::agreement() ?? new ManagementAgreement(['created_by' => $by->id]);
        if ($a->exists && $a->effective_from->toDateString() !== Carbon::parse($d['effective_from'])->toDateString()
            && OwnerFee::where('management_agreement_id', $a->id)->where('status', '!=', 'reversed')->exists()) {
            throw new RuntimeException('Fees have been accrued under this agreement – its start date can no longer change.');
        }
        $a->fill([
            'operator_name' => $d['operator_name'], 'owner_name' => $d['owner_name'], 'contract_ref' => $d['contract_ref'] ?? null,
            'effective_from' => $d['effective_from'], 'ends_on' => $d['ends_on'] ?? null, 'base_fee_pct' => round((float) $d['base_fee_pct'], 2),
            'incentive_pct' => round((float) $d['incentive_pct'], 2), 'incentive_basis' => $d['incentive_basis'] ?? 'agop', 'owner_priority' => round((float) ($d['owner_priority'] ?? 0), 2),
            'ffe_schedule' => $sched, 'reserve_account_id' => $d['reserve_account_id'] ?? null, 'pay_days' => (int) ($d['pay_days'] ?? 15), 'notes' => $d['notes'] ?? null, 'status' => 'active',
        ])->save();
        self::flush();
        ActivityLogger::log('owner', 'agreement', "Management agreement {$a->contract_ref}: base {$a->base_fee_pct}% of revenue, incentive {$a->incentive_pct}% of ".strtoupper($a->incentive_basis).', FF&E '.$a->scheduleLabel(), $a);

        return $a;
    }

    // ───────────────────────── fees ─────────────────────────

    /** months that have ended, been audited and fall under the agreement but have no fee accrual yet */
    public static function feesDue(): array
    {
        $a = self::agreement();
        if (! $a) {
            return [];
        }
        $cut = LedgerService::cutoff();
        $have = OwnerFee::where('status', '!=', 'reversed')->pluck('period')->flip();
        $o = [];
        for ($c = $a->effective_from->copy()->startOfMonth(); $c->copy()->endOfMonth()->toDateString() <= $cut; $c->addMonthNoOverflow()) {
            if ($a->ends_on && $c->gt($a->ends_on)) {
                break;
            }
            if (! isset($have[$c->format('Y-m')])) {
                $o[] = $c->format('Y-m');
            }
        }

        return $o;
    }

    /** first month of the fee year a month belongs to (fiscal year start, or the agreement start in its first year) */
    public static function feeYearStart(ManagementAgreement $a, Carbon $monthStart): Carbon
    {
        $fy = BudgetService::fyFor($monthStart);
        $fyStart = BudgetService::months($fy)[1]->copy();
        $agStart = $a->effective_from->copy()->startOfMonth();

        return $agStart->gt($fyStart) ? $agStart : $fyStart;
    }

    /** the fee calculation for a month (nothing is saved) */
    public static function computeFees(string $period): array
    {
        $ms = Carbon::parse($period.'-01')->startOfDay();
        $a = self::agreementFor($ms) ?? throw new RuntimeException('No management agreement covers '.$ms->format('F Y').'.');
        $s = UsaliService::month($period);
        $rev = $s['tor'];
        $gop = $s['gop'];
        $base = round(max(0, $rev) * (float) $a->base_fee_pct / 100, 2);
        $ffePct = $a->ffePct($ms);
        $ffe = round(max(0, $rev) * $ffePct / 100, 2);
        $agop = round($a->incentive_basis === 'gop' ? $gop : $gop - $base - $ffe, 2);

        $yStart = self::feeYearStart($a, $ms);
        $prior = OwnerFee::where('management_agreement_id', $a->id)->where('status', '!=', 'reversed')
            ->where('period', '>=', $yStart->format('Y-m'))->where('period', '<', $period)->get();
        $monthsInYear = (int) $yStart->diffInMonths($ms) + 1;
        // earlier months are re-measured on the current books (base fee and FF&E as accrued), so adjustments posted after
        // a month's accrual – closing journals, late invoices – are trued up through this month's incentive
        $priorNow = 0.0;
        foreach ($prior as $pf) {
            $g = UsaliService::month($pf->period)['gop'];
            $priorNow += $a->incentive_basis === 'gop' ? $g : $g - (float) $pf->base_fee - (float) $pf->ffe_amount;
        }
        $restated = round($priorNow - (float) $prior->sum('agop'), 2);
        $agopYtd = round($priorNow + $agop, 2);
        $priorityYtd = round((float) $a->owner_priority * $monthsInYear / 12, 2);
        $incYtd = round(max(0, $agopYtd - $priorityYtd) * (float) $a->incentive_pct / 100, 2);
        $incPrior = round((float) $prior->sum('incentive_fee'), 2);
        $inc = round($incYtd - $incPrior, 2);

        return [
            'agreement' => $a, 'period' => $period, 'date' => $ms->copy()->endOfMonth()->toDateString(), 'total_revenue' => $rev, 'gop' => $gop, 'base_fee' => $base,
            'ffe_pct' => $ffePct, 'ffe_amount' => $ffe, 'agop' => $agop, 'agop_ytd' => $agopYtd, 'priority_ytd' => $priorityYtd, 'incentive_ytd' => $incYtd,
            'incentive_prior' => $incPrior, 'incentive_fee' => $inc, 'total_fee' => round($base + $inc, 2), 'year_start' => $yStart->format('Y-m'), 'months_in_year' => $monthsInYear, 'restated' => $restated,
            'missing' => self::gapBefore($a, $yStart, $ms),
        ];
    }

    /** earlier months of the same fee year that are not accrued (months accrue in order) */
    protected static function gapBefore(ManagementAgreement $a, Carbon $yStart, Carbon $ms): array
    {
        $have = OwnerFee::where('management_agreement_id', $a->id)->where('status', '!=', 'reversed')->pluck('period')->flip();
        $o = [];
        for ($c = $yStart->copy(); $c->lt($ms); $c->addMonthNoOverflow()) {
            if (! isset($have[$c->format('Y-m')])) {
                $o[] = $c->format('Y-m');
            }
        }

        return $o;
    }

    public static function accrue(string $period, User $by): OwnerFee
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('Choose a month.');
        }
        LedgerService::syncSafely();
        UsaliService::flush();
        $ms = Carbon::parse($period.'-01');
        $end = $ms->copy()->endOfMonth()->toDateString();
        if ($end > LedgerService::cutoff()) {
            throw new RuntimeException('The night audit has not closed '.dmy($end).' yet – fees are accrued on the full month.');
        }
        if (($lock = LedgerService::lockDate()) && $end <= $lock) {
            throw new RuntimeException($ms->format('F Y').' is locked in the general ledger – accrue the fees before the month is closed, or reopen it.');
        }
        if (OwnerFee::where('period', $period)->where('status', '!=', 'reversed')->exists()) {
            throw new RuntimeException('Fees for '.$ms->format('F Y').' are already accrued.');
        }
        $c = self::computeFees($period);
        if ($c['missing']) {
            throw new RuntimeException('Accrue '.Carbon::parse($c['missing'][0].'-01')->format('F Y').' first – the incentive fee is calculated on the year to date.');
        }
        $a = $c['agreement'];

        return DB::transaction(function () use ($c, $a, $period, $ms, $by) {
            $lines = [];
            if ($c['base_fee'] != 0.0) {
                $lines[] = [AccountHead::sys('mgmt_base_fee'), $c['base_fee'], 'Base fee '.rtrim(rtrim(number_format((float) $a->base_fee_pct, 2), '0'), '.').'% of '.money($c['total_revenue'])];
            }
            if ($c['incentive_fee'] != 0.0) {
                $lines[] = [AccountHead::sys('mgmt_incentive_fee'), $c['incentive_fee'], $c['incentive_fee'] > 0 ? 'Incentive fee (YTD '.money($c['incentive_ytd']).' less '.money($c['incentive_prior']).' accrued)' : 'Incentive fee trued down to the year to date'];
            }
            $j = null;
            if ($c['total_fee'] != 0.0) {
                $lines[] = [AccountHead::sys('mgmt_fees_payable'), -$c['total_fee'], $a->operator_name];
                $j = LedgerService::post(['date' => $c['date'], 'reference' => $a->contract_ref ?: 'HMA', 'narration' => 'Management fees '.$ms->format('F Y').' – '.$a->operator_name, 'lines' => $lines], 'owner_fee', $period);
            }
            $fee = OwnerFee::firstOrNew(['period' => $period]);
            $fee->fill(array_intersect_key($c, array_flip(['date', 'total_revenue', 'gop', 'base_fee', 'ffe_pct', 'ffe_amount', 'agop', 'agop_ytd', 'priority_ytd', 'incentive_ytd', 'incentive_prior', 'incentive_fee', 'total_fee'])) + [
                'management_agreement_id' => $a->id, 'status' => 'accrued', 'gl_journal_id' => $j?->id, 'accrued_by' => $by->id, 'reversal_note' => null,
                'paid_on' => null, 'paid_account_id' => null, 'paid_ref' => null, 'paid_journal_id' => null, 'paid_by' => null,
            ])->save();
            if ($c['ffe_amount'] > 0) {
                FfeEntry::create(['date' => $c['date'], 'type' => 'contribution', 'amount' => $c['ffe_amount'], 'owner_fee_id' => $fee->id,
                    'memo' => 'FF&E reserve '.rtrim(rtrim(number_format($c['ffe_pct'], 2), '0'), '.').'% of '.money($c['total_revenue']).' – '.$ms->format('F Y'), 'created_by' => $by->id]);
            }
            self::flush();
            ActivityLogger::log('owner', 'fees_accrued', 'Management fees '.$ms->format('F Y').': base '.money($c['base_fee']).', incentive '.money($c['incentive_fee']).', FF&E reserve '.money($c['ffe_amount']), $fee);

            return $fee;
        });
    }

    public static function reverseFee(OwnerFee $fee, User $by, ?string $why): OwnerFee
    {
        if (trim((string) $why) === '') {
            throw new RuntimeException('Give a reason for reversing the fees.');
        }
        if ($fee->status !== 'accrued') {
            throw new RuntimeException($fee->status === 'paid' ? 'These fees are paid – they cannot be reversed.' : 'These fees are already reversed.');
        }
        if (OwnerFee::where('management_agreement_id', $fee->management_agreement_id)->where('status', '!=', 'reversed')->where('period', '>', $fee->period)->exists()) {
            throw new RuntimeException('Reverse the later months first – each month builds on the year to date.');
        }
        if (($lock = LedgerService::lockDate()) && $fee->date->toDateString() <= $lock) {
            throw new RuntimeException($fee->label().' is locked in the general ledger.');
        }

        return DB::transaction(function () use ($fee, $by, $why) {
            if ($fee->journal && ! $fee->journal->reversed_by_id) {
                LedgerService::reverse($fee->journal, $fee->date->toDateString(), 'Management fees reversed: '.$why);
            }
            FfeEntry::where('owner_fee_id', $fee->id)->delete();
            $fee->forceFill(['status' => 'reversed', 'reversal_note' => mb_substr((string) $why, 0, 255)])->save();
            self::flush();
            ActivityLogger::log('owner', 'fees_reversed', 'Management fees '.$fee->label().' reversed: '.$why, $fee);

            return $fee;
        });
    }

    /** fees accrued by $asOf and not paid by then (the control total for management fees payable) */
    public static function payable(?string $asOf = null): float
    {
        $asOf ??= bdate()->toDateString();

        return round((float) OwnerFee::where('status', '!=', 'reversed')->whereDate('date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('paid_on')->orWhereDate('paid_on', '>', $asOf))->sum('total_fee'), 2);
    }

    /** Cash & Bank heads fees can be paid from */
    public static function payAccounts(): Collection
    {
        return TreasuryAccount::with('head')->where('is_active', true)->whereIn('kind', ['bank', 'momo', 'cash'])->orderBy('kind')->orderBy('name')->get();
    }

    public static function payFees(array $ids, int $accountId, string $date, ?string $ref, User $by): int
    {
        $fees = OwnerFee::whereIn('id', array_map('intval', $ids))->orderBy('period')->get();
        if ($fees->isEmpty()) {
            throw new RuntimeException('Tick the months you are paying.');
        }
        if ($fees->contains(fn ($f) => $f->status !== 'accrued')) {
            throw new RuntimeException('Only accrued, unpaid fees can be paid.');
        }
        $acc = self::payAccounts()->firstWhere('id', $accountId) ?? throw new RuntimeException('Pay from an active bank, Mobile Money or cash account.');
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('The payment date cannot be after the business date.');
        }
        if (($lock = LedgerService::lockDate()) && $date <= $lock) {
            throw new RuntimeException('The general ledger is locked up to '.dmy($lock).' – choose a later date.');
        }
        if ($date < $fees->max(fn ($f) => $f->date->toDateString())) {
            throw new RuntimeException('Fees cannot be paid before the month they are for has ended.');
        }
        $total = round((float) $fees->sum('total_fee'), 2);
        if ($total <= 0) {
            throw new RuntimeException('Nothing to pay – the fees ticked net to '.money($total).'.');
        }
        $a = $fees->first()->agreement;

        return DB::transaction(function () use ($fees, $acc, $date, $ref, $by, $total, $a) {
            $label = $fees->map->label()->implode(', ');
            $j = LedgerService::post(['date' => $date, 'reference' => $ref ?: ($a?->contract_ref ?: 'HMA'), 'narration' => 'Management fees paid – '.$label.' – '.($a?->operator_name),
                'lines' => [[AccountHead::sys('mgmt_fees_payable'), $total, $label], [$acc->account_head_id, -$total, $a?->operator_name]]], 'owner_fee_pay', (string) $fees->first()->id);
            foreach ($fees as $f) {
                $f->forceFill(['status' => 'paid', 'paid_on' => $date, 'paid_account_id' => $acc->account_head_id, 'paid_ref' => $ref, 'paid_journal_id' => $j->id, 'paid_by' => $by->id])->save();
            }
            ActivityLogger::log('owner', 'fees_paid', 'Management fees paid '.money($total).' ('.$label.') from '.$acc->name);

            return $fees->count();
        });
    }

    // ───────────────────────── FF&E reserve ─────────────────────────

    public static function reserveBalance(?string $asOf = null): float
    {
        return round((float) FfeEntry::when($asOf, fn ($q) => $q->whereDate('date', '<=', $asOf))->sum('amount'), 2);
    }

    /** cash actually held in the designated reserve bank account */
    public static function reserveFunded(?ManagementAgreement $a = null, ?string $asOf = null): ?float
    {
        $a ??= self::agreement();
        $head = $a?->reserveAccount?->account_head_id;
        if (! $head) {
            return null;
        }

        return round((float) \App\Models\GlLine::where('account_head_id', $head)->when($asOf, fn ($q) => $q->whereDate('date', '<=', $asOf))->sum(DB::raw('debit - credit')), 2);
    }

    /** entries with a running balance, oldest first */
    public static function ledger(?string $from = null, ?string $to = null): array
    {
        $open = $from ? self::reserveBalance(Carbon::parse($from)->subDay()->toDateString()) : 0.0;
        $bal = $open;
        $rows = [];
        foreach (FfeEntry::with('project', 'asset')->when($from, fn ($q) => $q->whereDate('date', '>=', $from))->when($to, fn ($q) => $q->whereDate('date', '<=', $to))->orderBy('date')->orderBy('id')->get() as $e) {
            $bal = round($bal + (float) $e->amount, 2);
            $rows[] = ['entry' => $e, 'balance' => $bal];
        }

        return ['opening' => $open, 'rows' => $rows, 'closing' => $bal];
    }

    public static function addEntry(array $d, User $by): FfeEntry
    {
        $type = $d['type'];
        if (! in_array($type, ['opening', 'interest', 'adjustment', 'drawdown'], true)) {
            throw new RuntimeException('Contributions come from the monthly fee accrual – choose another type.');
        }
        $amt = round((float) $d['amount'], 2);
        if ($amt == 0.0) {
            throw new RuntimeException('Enter an amount.');
        }
        if (in_array($type, ['opening', 'interest', 'drawdown'], true) && $amt < 0) {
            throw new RuntimeException('Enter the amount as a positive number.');
        }
        if ($type === 'opening' && FfeEntry::where('type', 'opening')->exists()) {
            throw new RuntimeException('The reserve already has an opening balance – use an adjustment.');
        }
        if ($d['date'] > bdate()->toDateString()) {
            throw new RuntimeException('The date cannot be after the business date.');
        }
        $project = null;
        if ($type === 'drawdown') {
            $project = CapexProject::find($d['capex_project_id'] ?? 0) ?? throw new RuntimeException('Choose the capex project the money was spent on.');
            if ($project->funding !== 'reserve') {
                throw new RuntimeException($project->number.' is funded by '.strtolower(CapexProject::FUNDING[$project->funding]).' – only reserve-funded projects draw on the FF&E reserve (link its assets instead).');
            }
            self::assertSpendable($project, $amt);
            $amt = -$amt;
        }
        $e = FfeEntry::create(['date' => $d['date'], 'type' => $type, 'amount' => $amt, 'capex_project_id' => $project?->id, 'memo' => $d['memo'] ?? null, 'created_by' => $by->id]);
        ActivityLogger::log('owner', 'ffe_entry', FfeEntry::TYPES[$type].' '.money(abs($amt)).($project ? ' – '.$project->number : '').' on the FF&E reserve', $e);

        return $e;
    }

    public static function deleteEntry(FfeEntry $e, User $by): void
    {
        if ($e->type === 'contribution' || $e->asset_id) {
            throw new RuntimeException($e->type === 'contribution' ? 'Contributions follow the fee accrual – reverse the month\'s fees instead.' : 'Unlink the asset from the project instead.');
        }
        $e->delete();
        ActivityLogger::log('owner', 'ffe_entry_deleted', FfeEntry::TYPES[$e->type].' '.money(abs((float) $e->amount)).' removed from the FF&E reserve');
    }

    protected static function assertSpendable(CapexProject $p, float $amount): void
    {
        if ($p->status !== 'approved') {
            throw new RuntimeException($p->number.' is '.strtolower(CapexProject::STATUSES[$p->status]).' – only approved projects can spend.');
        }
        if ($p->funding === 'reserve' && $amount > ($bal = self::reserveBalance())) {
            throw new RuntimeException('The FF&E reserve holds only '.money($bal).' – ask the owner to fund the difference.');
        }
    }

    // ───────────────────────── capex ─────────────────────────

    public static function saveProject(array $d, User $by, ?CapexProject $p = null): CapexProject
    {
        if ($p && ! in_array($p->status, ['draft', 'rejected'], true)) {
            throw new RuntimeException('Only draft or rejected projects can be edited.');
        }
        if ((float) $d['budget'] <= 0) {
            throw new RuntimeException('Enter the project budget.');
        }
        $p ??= new CapexProject(['number' => Numbering::next('capex_project'), 'requested_by' => $by->id, 'status' => 'draft']);
        $p->fill(['title' => $d['title'], 'category' => $d['category'], 'funding' => $d['funding'], 'usali_dept' => $d['usali_dept'] ?? null, 'budget' => round((float) $d['budget'], 2),
            'justification' => $d['justification'] ?? null, 'needed_by' => $d['needed_by'] ?? null])->save();
        ActivityLogger::log('owner', 'capex_saved', "{$p->number} {$p->title} ".money((float) $p->budget).' ('.CapexProject::FUNDING[$p->funding].')', $p);

        return $p;
    }

    public static function submitProject(CapexProject $p, User $by): CapexProject
    {
        if (! in_array($p->status, ['draft', 'rejected'], true)) {
            throw new RuntimeException('This project has already been submitted.');
        }
        if (trim((string) $p->justification) === '') {
            throw new RuntimeException('Add a justification before sending it to the owner.');
        }
        $p->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_note' => null])->save();
        ActivityLogger::log('owner', 'capex_submitted', "{$p->number} sent to the owner for approval", $p);

        return $p;
    }

    public static function decideProject(CapexProject $p, User $by, bool $approve, ?string $note): CapexProject
    {
        if ($p->status !== 'submitted') {
            throw new RuntimeException('Only projects awaiting approval can be decided.');
        }
        if ($p->requested_by === $by->id) {
            throw new RuntimeException('You requested this project – someone else must approve it.');
        }
        if (! $approve && trim((string) $note) === '') {
            throw new RuntimeException('Say why the project is rejected.');
        }
        $p->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 255) : null])->save();
        ActivityLogger::log('owner', $approve ? 'capex_approved' : 'capex_rejected', "{$p->number} ".($approve ? 'approved' : 'rejected').' by the owner'.($note ? ': '.$note : ''), $p);

        return $p;
    }

    /** link a fixed asset bought for the project; reserve-funded projects draw the cost from the reserve */
    public static function linkAsset(CapexProject $p, Asset $asset, User $by): string
    {
        if ($asset->capex_project_id) {
            throw new RuntimeException("{$asset->asset_no} is already linked to a project.");
        }
        if ($asset->status === 'disposed') {
            throw new RuntimeException("{$asset->asset_no} has been disposed of.");
        }
        self::assertSpendable($p, (float) $asset->cost);

        return DB::transaction(function () use ($p, $asset, $by) {
            $asset->forceFill(['capex_project_id' => $p->id])->save();
            if ($p->funding === 'reserve') {
                FfeEntry::create(['date' => ($asset->purchase_date?->toDateString() ?? bdate()->toDateString()) > bdate()->toDateString() ? bdate()->toDateString() : ($asset->purchase_date?->toDateString() ?? bdate()->toDateString()),
                    'type' => 'drawdown', 'amount' => -(float) $asset->cost, 'capex_project_id' => $p->id, 'asset_id' => $asset->id, 'memo' => "{$asset->asset_no} {$asset->name}", 'created_by' => $by->id]);
            }
            ActivityLogger::log('owner', 'capex_asset', "{$asset->asset_no} {$asset->name} ".money((float) $asset->cost)." charged to {$p->number}", $p);
            $spent = $p->fresh()->spent();

            return $spent > (float) $p->budget ? "{$asset->asset_no} linked – {$p->number} is now ".money($spent - (float) $p->budget).' over its budget.' : "{$asset->asset_no} linked to {$p->number}.";
        });
    }

    public static function unlinkAsset(CapexProject $p, Asset $asset, User $by): void
    {
        if ((int) $asset->capex_project_id !== $p->id) {
            throw new RuntimeException('That asset is not part of this project.');
        }
        if ($p->status === 'completed') {
            throw new RuntimeException('The project is completed.');
        }
        DB::transaction(function () use ($p, $asset) {
            FfeEntry::where('asset_id', $asset->id)->where('capex_project_id', $p->id)->delete();
            $asset->forceFill(['capex_project_id' => null])->save();
        });
        ActivityLogger::log('owner', 'capex_asset_unlinked', "{$asset->asset_no} removed from {$p->number}", $p);
    }

    public static function closeProject(CapexProject $p, User $by, string $to): CapexProject
    {
        if ($to === 'completed' && $p->status !== 'approved') {
            throw new RuntimeException('Only approved projects can be completed.');
        }
        if ($to === 'cancelled') {
            if (! in_array($p->status, ['draft', 'submitted', 'approved', 'rejected'], true)) {
                throw new RuntimeException('This project cannot be cancelled.');
            }
            if ($p->spent() > 0) {
                throw new RuntimeException('Money has been spent on this project – complete it instead.');
            }
        }
        $p->forceFill(['status' => $to, 'completed_at' => $to === 'completed' ? now() : null])->save();
        ActivityLogger::log('owner', 'capex_'.$to, "{$p->number} {$to}", $p);

        return $p;
    }

    // ───────────────────────── owner packs ─────────────────────────

    public static function prepare(string $period, User $by): OwnerStatement
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('Choose a month.');
        }
        $ms = Carbon::parse($period.'-01');
        if ($ms->copy()->endOfMonth()->toDateString() > LedgerService::cutoff()) {
            throw new RuntimeException($ms->format('F Y').' has not ended (or the night audit has not closed its last day).');
        }
        if (OwnerStatement::where('period', $period)->exists()) {
            throw new RuntimeException('The '.$ms->format('F Y').' pack already exists.');
        }
        $s = OwnerStatement::create(['period' => $period, 'status' => 'draft', 'prepared_by' => $by->id,
            'commentary' => "Trading\n\nCosts & payroll\n\nCapital projects & FF&E\n\nOutlook for next month"]);
        ActivityLogger::log('owner', 'pack_prepared', 'Owner pack '.$s->label().' prepared', $s);

        return $s;
    }

    public static function saveCommentary(OwnerStatement $s, ?string $text): void
    {
        if ($s->status !== 'draft') {
            throw new RuntimeException('The pack has been issued – the commentary is final.');
        }
        $s->forceFill(['commentary' => $text])->save();
    }

    /** what stops a pack being issued */
    public static function issueBlockers(OwnerStatement $s): array
    {
        $ms = Carbon::parse($s->period.'-01');
        $end = $ms->copy()->endOfMonth()->toDateString();
        $b = [];
        $cp = ClosePeriod::where('period', $s->period)->first();
        if ($cp ? $cp->status !== 'closed' : ! (($lock = LedgerService::lockDate()) && $lock >= $end)) {
            $b[] = 'Close '.$ms->format('F Y').' first – owner packs are issued on closed books.';
        }
        if (self::agreementFor($ms) && ! OwnerFee::where('period', $s->period)->where('status', '!=', 'reversed')->exists()) {
            $b[] = 'Accrue the '.$ms->format('F Y').' management fees.';
        }
        if (trim(str_replace(["Trading", "Costs & payroll", "Capital projects & FF&E", "Outlook for next month"], '', (string) $s->commentary)) === '') {
            $b[] = 'Write the general manager\'s commentary.';
        }

        return $b;
    }

    /** the figures that go into a pack (frozen on issue) */
    public static function snapshot(string $period): array
    {
        UsaliService::flush();
        $ms = Carbon::parse($period.'-01');
        $fy = BudgetService::fyFor($ms);
        $m = BudgetService::monthIndex($ms);
        $st = UsaliService::statement($fy, $m);
        $keys = array_column(UsaliService::layout('operator'), 0);
        $keys = array_values(array_unique(array_merge($keys, array_column(UsaliService::layout('owner'), 0), ['labor', 'fnb_cos', 'excluded', 'gl_net'])));
        $cols = [];
        foreach ($st['cols'] as $k => $c) {
            $cols[$k] = array_intersect_key($c, array_flip($keys));
        }
        $months = BudgetService::months($fy);
        $yms = array_map(fn ($c) => $c->format('Y-m'), array_slice($months, 0, $m, true));
        $lyMonths = BudgetService::months($fy - 1);
        $kp = [
            'm_act' => UsaliService::kpis($st['cols']['m_act'], UsaliService::roomStats([$period])),
            'm_bud' => UsaliService::kpis($st['cols']['m_bud'], UsaliService::budgetStats($fy, $m, $m)),
            'm_ly' => UsaliService::kpis($st['cols']['m_ly'], UsaliService::roomStats([$lyMonths[$m]->format('Y-m')])),
            'y_act' => UsaliService::kpis($st['cols']['y_act'], UsaliService::roomStats($yms)),
            'y_bud' => UsaliService::kpis($st['cols']['y_bud'], UsaliService::budgetStats($fy, 1, $m)),
        ];
        $fee = OwnerFee::where('period', $period)->where('status', '!=', 'reversed')->first();
        $end = $ms->copy()->endOfMonth()->toDateString();
        $a = self::agreementFor($ms);

        return [
            'period' => $period, 'fy' => $fy, 'm' => $m, 'fy_label' => BudgetService::fyLabel($fy), 'cols' => $cols, 'kpis' => $kp,
            'flow' => ['bud' => UsaliService::flowThrough($st['cols']['m_act'], $st['cols']['m_bud']), 'ly' => UsaliService::flowThrough($st['cols']['m_act'], $st['cols']['m_ly'])],
            'fee' => $fee ? $fee->only(['total_revenue', 'gop', 'base_fee', 'ffe_pct', 'ffe_amount', 'agop', 'agop_ytd', 'priority_ytd', 'incentive_ytd', 'incentive_prior', 'incentive_fee', 'total_fee', 'status']) : null,
            'fees_ytd' => $a ? round((float) OwnerFee::where('management_agreement_id', $a->id)->where('status', '!=', 'reversed')->where('period', '>=', self::feeYearStart($a, $ms)->format('Y-m'))->where('period', '<=', $period)->sum('total_fee'), 2) : 0,
            'agreement' => $a ? ['operator' => $a->operator_name, 'owner' => $a->owner_name, 'ref' => $a->contract_ref, 'base' => (float) $a->base_fee_pct, 'incentive' => (float) $a->incentive_pct, 'priority' => (float) $a->owner_priority, 'basis' => $a->incentive_basis] : null,
            'reserve' => ['balance' => self::reserveBalance($end), 'funded' => self::reserveFunded($a, $end)],
            'capex' => CapexProject::with('assets', 'entries')->whereIn('status', ['submitted', 'approved', 'completed'])->orderBy('number')->get()
                ->map(fn ($p) => ['number' => $p->number, 'title' => $p->title, 'status' => CapexProject::STATUSES[$p->status], 'funding' => CapexProject::FUNDING[$p->funding], 'budget' => (float) $p->budget, 'spent' => $p->spent()])->all(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    public static function issue(OwnerStatement $s, User $by): OwnerStatement
    {
        if ($s->status !== 'draft') {
            throw new RuntimeException('This pack has already been issued.');
        }
        if ($b = self::issueBlockers($s)) {
            throw new RuntimeException($b[0]);
        }
        $s->forceFill(['status' => 'issued', 'issued_by' => $by->id, 'issued_at' => now(), 'snapshot' => json_encode(self::snapshot($s->period))])->save();
        ActivityLogger::log('owner', 'pack_issued', 'Owner pack '.$s->label().' issued to the owner', $s);

        return $s;
    }

    public static function raiseQuery(OwnerStatement $s, User $by, string $body): OwnerQuery
    {
        if (! in_array($s->status, ['issued', 'queried'], true)) {
            throw new RuntimeException($s->status === 'draft' ? 'The pack has not been issued yet.' : 'The pack has been acknowledged.');
        }
        if (trim($body) === '') {
            throw new RuntimeException('Type your question.');
        }
        $q = OwnerQuery::create(['owner_statement_id' => $s->id, 'raised_by' => $by->id, 'body' => $body, 'status' => 'open']);
        $s->forceFill(['status' => 'queried'])->save();
        ActivityLogger::log('owner', 'pack_query', 'Owner query on '.$s->label().': '.mb_substr($body, 0, 120), $s);

        return $q;
    }

    public static function reply(OwnerQuery $q, User $by, string $text): OwnerQuery
    {
        if ($q->status !== 'open') {
            throw new RuntimeException('This query has already been answered.');
        }
        if (trim($text) === '') {
            throw new RuntimeException('Type the answer.');
        }
        $q->forceFill(['reply' => $text, 'replied_by' => $by->id, 'replied_at' => now(), 'status' => 'answered'])->save();
        ActivityLogger::log('owner', 'pack_reply', 'Answered the owner\'s query on '.$q->statement->label(), $q->statement);

        return $q;
    }

    public static function closeQuery(OwnerQuery $q, User $by): OwnerQuery
    {
        if ($q->status === 'closed') {
            throw new RuntimeException('This query is already closed.');
        }
        if ($q->status === 'open') {
            throw new RuntimeException('Wait for the operator\'s answer before closing the query.');
        }
        $q->forceFill(['status' => 'closed'])->save();
        $s = $q->statement;
        if ($s->status === 'queried' && ! $s->queries()->where('status', '!=', 'closed')->exists()) {
            $s->forceFill(['status' => 'issued'])->save();
        }

        return $q;
    }

    public static function acknowledge(OwnerStatement $s, User $by): OwnerStatement
    {
        if (! in_array($s->status, ['issued', 'queried'], true)) {
            throw new RuntimeException($s->status === 'draft' ? 'The pack has not been issued yet.' : 'The pack is already acknowledged.');
        }
        if ($s->queries()->where('status', 'open')->exists()) {
            throw new RuntimeException('A query is still waiting for the operator\'s answer.');
        }
        $s->queries()->where('status', 'answered')->update(['status' => 'closed']);
        $s->forceFill(['status' => 'acknowledged', 'acknowledged_by' => $by->id, 'acknowledged_at' => now()])->save();
        ActivityLogger::log('owner', 'pack_acknowledged', 'Owner pack '.$s->label().' acknowledged by the owner', $s);

        return $s;
    }

    // ───────────────────────── attention ─────────────────────────

    /** owners: packs to acknowledge, capex to approve, answered queries; operators: open queries, fees due, closed months without a pack */
    public static function attention(): int
    {
        $u = auth()->user();
        $k = 'att:'.\App\Support\Tenant::id().':'.($u?->id ?? 0);
        if (isset(self::$memo[$k])) {
            return self::$memo[$k];
        }
        $n = 0;
        try {
            if ($u && $u->hasPermission('owner.approve')) {
                $n += OwnerStatement::where('status', 'issued')->count();
                $n += CapexProject::where('status', 'submitted')->where(fn ($q) => $q->whereNull('requested_by')->orWhere('requested_by', '!=', $u->id))->count();
                $n += OwnerQuery::where('status', 'answered')->count();
            }
            if ($u && $u->hasPermission('owner.operate')) {
                $n += OwnerQuery::where('status', 'open')->count();
                $n += count(self::feesDue());
                $n += count(self::packsDue());
            }
        } catch (\Throwable $e) {
            $n = 0;
        }

        return self::$memo[$k] = $n;
    }

    /** closed months under the agreement without an issued pack */
    public static function packsDue(): array
    {
        $a = self::agreement();
        if (! $a) {
            return [];
        }
        $have = OwnerStatement::where('status', '!=', 'draft')->pluck('period')->flip();

        return ClosePeriod::where('status', 'closed')->where('period', '>=', $a->effective_from->format('Y-m'))->orderBy('period')->pluck('period')
            ->reject(fn ($p) => isset($have[$p]))->values()->all();
    }
}
