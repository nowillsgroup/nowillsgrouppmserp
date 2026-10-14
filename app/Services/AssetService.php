<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDepLine;
use App\Models\AssetDepRun;
use App\Models\PaymentMode;
use App\Models\PmSchedule;
use App\Models\SupplierInvoice;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2.3 fixed assets: the register, monthly depreciation (straight line or reducing balance, pro-rata in the first
 * month, never below the residual value), disposals with gain / loss, and the general-ledger postings:
 *   direct purchase  Dr Fixed assets / Cr cash-bank     (opening assets are in the opening balances; bill assets are posted by the bill)
 *   depreciation run Dr Depreciation / Cr Accumulated depreciation
 *   disposal         Dr Accumulated depreciation + Dr cash-bank (proceeds) / Cr Fixed assets, difference to gain / loss on disposal
 */
class AssetService
{
    /** Fields that change the numbers – frozen once the asset has been depreciated or disposed. */
    public const FINANCIAL = ['acquired_via', 'supplier_invoice_id', 'payment_mode_id', 'purchase_date', 'dep_start', 'cost', 'residual_value', 'opening_acc_dep', 'method', 'life_months', 'wdv_rate', 'asset_category_id'];

    // ------------------------------------------------------------------ register

    public static function create(array $d): Asset
    {
        $cat = AssetCategory::findOrFail($d['asset_category_id']);
        $d = self::normalise($d, $cat);
        self::validateFinancials($d);

        return DB::transaction(function () use ($d) {
            $a = Asset::create($d + ['asset_no' => Numbering::next('asset'), 'status' => 'active', 'created_by' => auth()->id()]);
            ActivityLogger::log('assets', 'create', "Asset {$a->asset_no} {$a->name} added – ".money($a->cost).' ('.(Asset::VIA[$a->acquired_via] ?? $a->acquired_via).')', $a);

            return $a;
        });
    }

    public static function update(Asset $a, array $d): Asset
    {
        if ($a->isDisposed()) {
            throw new RuntimeException("Asset {$a->asset_no} has been disposed and can't be changed.");
        }
        $locked = $a->depLines()->exists();
        if ($locked) {
            foreach (self::FINANCIAL as $f) {
                if (array_key_exists($f, $d) && (string) self::cmp($a->$f) !== (string) self::cmp($d[$f])) {
                    throw new RuntimeException('Cost, dates and depreciation settings are locked once the asset has been depreciated. Reverse the depreciation runs first, or record a new asset.');
                }
            }
            $d = array_diff_key($d, array_flip(self::FINANCIAL));
        } else {
            $cat = AssetCategory::findOrFail($d['asset_category_id'] ?? $a->asset_category_id);
            $d = self::normalise($d + $a->only(self::FINANCIAL), $cat, $a);
            self::validateFinancials($d, $a);
        }
        $a->fill($d)->save();
        ActivityLogger::log('assets', 'update', "Asset {$a->asset_no} updated", $a);

        return $a;
    }

    protected static function cmp($v)
    {
        if ($v instanceof Carbon) {
            return $v->toDateString();
        }

        return is_numeric($v) ? round((float) $v, 2) : $v;
    }

    /** Fill in defaults from the category and tidy the acquisition fields. */
    protected static function normalise(array $d, AssetCategory $cat, ?Asset $a = null): array
    {
        $d['method'] = $d['method'] ?? $cat->method;
        if (! in_array($d['method'], ['slm', 'wdv'])) {
            throw new RuntimeException('Unknown depreciation method.');
        }
        $d['life_months'] = (int) ($d['life_months'] ?? 0) ?: (int) $cat->life_months;
        $d['wdv_rate'] = (float) ($d['wdv_rate'] ?? 0) ?: (float) $cat->wdv_rate;
        $d['cost'] = round((float) ($d['cost'] ?? 0), 2);
        $d['residual_value'] = isset($d['residual_value']) && $d['residual_value'] !== '' && $d['residual_value'] !== null
            ? round((float) $d['residual_value'], 2) : round($d['cost'] * (float) $cat->residual_pct / 100, 2);
        $via = $d['acquired_via'] ?? 'direct';
        $d['opening_acc_dep'] = $via === 'opening' ? round((float) ($d['opening_acc_dep'] ?? 0), 2) : 0;
        $d['payment_mode_id'] = $via === 'direct' ? ($d['payment_mode_id'] ?? null) : null;
        $d['supplier_invoice_id'] = $via === 'bill' ? ($d['supplier_invoice_id'] ?? null) : null;
        if ($via === 'bill' && $d['supplier_invoice_id'] && ($bill = SupplierInvoice::find($d['supplier_invoice_id']))) {
            $d['supplier_id'] = $bill->supplier_id;
        }
        $purchase = Carbon::parse($d['purchase_date'] ?? bdate());
        $d['purchase_date'] = $purchase->toDateString();
        $d['dep_start'] = ! empty($d['dep_start']) ? Carbon::parse($d['dep_start'])->toDateString() : $d['purchase_date'];

        return $d;
    }

    protected static function validateFinancials(array $d, ?Asset $a = null): void
    {
        if ($d['cost'] <= 0) {
            throw new RuntimeException('Enter the asset cost.');
        }
        if ($d['residual_value'] < 0 || $d['residual_value'] >= $d['cost']) {
            throw new RuntimeException('The residual value must be less than the cost.');
        }
        if ($d['method'] === 'slm' && ($d['life_months'] < 1 || $d['life_months'] > 600)) {
            throw new RuntimeException('Useful life must be between 1 and 600 months.');
        }
        if ($d['method'] === 'wdv' && ($d['wdv_rate'] <= 0 || $d['wdv_rate'] > 100)) {
            throw new RuntimeException('Enter the reducing-balance rate (% a year).');
        }
        if ($d['purchase_date'] > bdate()->toDateString()) {
            throw new RuntimeException('The purchase date can\'t be in the future.');
        }
        if ($d['dep_start'] < $d['purchase_date']) {
            throw new RuntimeException('Depreciation can\'t start before the purchase date.');
        }
        $via = $d['acquired_via'] ?? 'direct';
        if (! array_key_exists($via, Asset::VIA)) {
            throw new RuntimeException('Choose how the asset was acquired.');
        }
        if ($via === 'opening' && $d['opening_acc_dep'] > $d['cost'] - $d['residual_value']) {
            throw new RuntimeException('Accumulated depreciation brought in can\'t exceed cost less residual value.');
        }
        if ($via === 'direct') {
            if (empty($d['payment_mode_id']) || ! PaymentMode::whereKey($d['payment_mode_id'])->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->exists()) {
                throw new RuntimeException('Choose the cash or bank account the asset was paid from.');
            }
            if (($lock = LedgerService::lockDate()) && $d['purchase_date'] <= $lock) {
                throw new RuntimeException("The books are locked up to {$lock} – use a later purchase date.");
            }
        }
        if ($via === 'bill') {
            $bill = ! empty($d['supplier_invoice_id']) ? SupplierInvoice::with('lines')->find($d['supplier_invoice_id']) : null;
            if (! $bill || $bill->status === 'cancelled' || $bill->type !== 'invoice') {
                throw new RuntimeException('Choose the supplier bill the asset was bought on.');
            }
            $fa = self::faHeads();
            $capital = round((float) $bill->lines->whereIn('account_head_id', $fa)->sum('amount'), 2);
            if ($capital <= 0) {
                throw new RuntimeException("Bill {$bill->doc_no} has no line coded to a fixed-asset account – correct the bill's account first.");
            }
            $already = (float) Asset::where('supplier_invoice_id', $bill->id)->when($a, fn ($q) => $q->whereKeyNot($a->id))->sum('cost');
            if ($already + $d['cost'] > $capital + 0.01) {
                throw new RuntimeException("Bill {$bill->doc_no} capitalises ".money($capital).'; '.money($already).' is already on the register.');
            }
        }
    }

    /** Heads that hold asset cost: the system fixed-assets head plus any other asset head in the Fixed Assets group except accumulated depreciation. */
    public static function faHeads(): array
    {
        $acc = AccountHead::sys('acc_depreciation');

        return AccountHead::where('type', 'asset')->where(fn ($q) => $q->where('system_key', 'fixed_assets')->orWhere('group_name', 'Fixed Assets'))
            ->where('id', '!=', $acc)->pluck('id')->all();
    }

    public static function setStatus(Asset $a, string $status, ?string $note = null): Asset
    {
        if ($a->isDisposed() || ! in_array($status, ['active', 'in_repair'])) {
            throw new RuntimeException('That status change isn\'t allowed.');
        }
        $a->forceFill(['status' => $status])->save();
        ActivityLogger::log('assets', 'status', "Asset {$a->asset_no} → ".Asset::STATUSES[$status].($note ? ": {$note}" : ''), $a);

        return $a;
    }

    public static function dispose(Asset $a, array $d): Asset
    {
        if ($a->isDisposed()) {
            throw new RuntimeException("Asset {$a->asset_no} is already disposed.");
        }
        $type = $d['disposal_type'] ?? '';
        if (! array_key_exists($type, Asset::DISPOSALS)) {
            throw new RuntimeException('Choose how the asset left the business.');
        }
        $date = Carbon::parse($d['disposed_date'] ?? bdate())->toDateString();
        if ($date > bdate()->toDateString() || $date < $a->purchase_date->toDateString()) {
            throw new RuntimeException('The disposal date must be between the purchase date and today.');
        }
        if ($a->dep_through && $date <= $a->dep_through->toDateString()) {
            throw new RuntimeException('The asset is depreciated up to '.$a->dep_through->format('d M Y').' – the disposal date must be after that (or reverse the later depreciation run).');
        }
        if (($lock = LedgerService::lockDate()) && $date <= $lock) {
            throw new RuntimeException("The books are locked up to {$lock}.");
        }
        // depreciation must be posted up to the month before the disposal (none is charged in the disposal month)
        $prevEnd = Carbon::parse($date)->startOfMonth()->subDay();
        if ($a->dep_start->lte($prevEnd) && (! $a->dep_through || $a->dep_through->lt($prevEnd))) {
            throw new RuntimeException('Run depreciation for '.($a->dep_through ? $a->dep_through->copy()->addDay()->format('F Y') : $a->dep_start->format('F Y')).' first – an asset is depreciated up to the month before it is disposed of.');
        }
        $proceeds = $type === 'sold' ? round((float) ($d['disposal_proceeds'] ?? 0), 2) : 0.0;
        if ($type === 'sold' && $proceeds <= 0) {
            throw new RuntimeException('Enter the sale proceeds.');
        }
        $mode = $proceeds > 0 ? ($d['disposal_mode_id'] ?? null) : null;
        if ($proceeds > 0 && (! $mode || ! PaymentMode::whereKey($mode)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->exists())) {
            throw new RuntimeException('Choose the cash or bank account the proceeds went into.');
        }

        DB::transaction(function () use ($a, $type, $date, $proceeds, $mode, $d) {
            $nbv = $a->nbv();
            $a->forceFill(['status' => 'disposed', 'disposed_date' => $date, 'disposal_type' => $type, 'disposal_proceeds' => $proceeds, 'disposal_mode_id' => $mode,
                'disposal_nbv' => $nbv, 'disposal_note' => ! empty($d['disposal_note']) ? mb_substr($d['disposal_note'], 0, 255) : null])->save();
            PmSchedule::where('asset_id', $a->id)->update(['is_active' => false]);
            $gain = round($proceeds - $nbv, 2);
            ActivityLogger::log('assets', 'dispose', "Asset {$a->asset_no} ".strtolower(Asset::DISPOSALS[$type])." on {$date} – book value ".money($nbv).($proceeds ? ', proceeds '.money($proceeds) : '').', '.($gain >= 0 ? 'gain ' : 'loss ').money(abs($gain)), $a);
        });
        LedgerService::syncSafely();

        return $a->fresh();
    }

    // ------------------------------------------------------------------ depreciation

    /** Depreciation of one asset for the month ending $end: [amount, days, opening nbv]. */
    public static function monthFor(Asset $a, Carbon $end, ?float $accumulated = null): array
    {
        $end = $end->copy()->endOfMonth()->startOfDay();
        $start = $end->copy()->startOfMonth();
        $acc = $accumulated ?? $a->accumulated();
        $nbv = round((float) $a->cost - $acc, 2);
        $none = [0.0, 0, $nbv];
        if ($a->dep_start->gt($end) || ($a->dep_through && $a->dep_through->gte($end))) {
            return $none;
        }
        if ($a->disposed_date && $a->disposed_date->lte($end)) {
            return $none; // no depreciation in the month of disposal
        }
        $room = round($nbv - (float) $a->residual_value, 2);
        if ($room <= 0) {
            return $none;
        }
        $monthly = $a->method === 'wdv'
            ? $nbv * (float) $a->wdv_rate / 100 / 12
            : ((float) $a->cost - (float) $a->residual_value) / max(1, $a->life_months);
        $from = $a->dep_start->gt($start) ? $a->dep_start : $start;
        $days = $from->diffInDays($end) + 1;
        $amount = round($monthly * $days / $end->daysInMonth, 2);

        return [min($amount, $room), (int) $days, $nbv];
    }

    public static function lastRun(): ?AssetDepRun
    {
        return AssetDepRun::where('status', 'posted')->orderByDesc('period_end')->first();
    }

    /** The month that has to be run next (runs are strictly sequential). Null when there is nothing to depreciate yet. */
    public static function nextPeriod(): ?Carbon
    {
        if ($last = self::lastRun()) {
            return $last->period_end->copy()->addDay()->endOfMonth()->startOfDay();
        }
        $first = Asset::where(fn ($q) => $q->whereNull('disposed_date')->orWhereColumn('disposed_date', '>', 'dep_start'))->min('dep_start');

        return $first ? Carbon::parse($first)->endOfMonth()->startOfDay() : null;
    }

    /** Can the period be run now? Returns the reason it can't, or null. */
    public static function blocker(?Carbon $period): ?string
    {
        if (! $period) {
            return 'There are no assets to depreciate yet.';
        }
        if ($period->gte(bdate())) {
            return $period->format('F Y').' can be run once the month is over (from '.$period->copy()->addDay()->format('d M Y').').';
        }
        if (($lock = LedgerService::lockDate()) && $period->toDateString() <= $lock) {
            return "The books are locked up to {$lock}.";
        }

        return null;
    }

    public static function preview(Carbon $period): Collection
    {
        // disposed assets are complete: disposal requires every earlier month to be run and the disposal month isn't charged
        return Asset::with('category', 'room')->withSum('depLines', 'amount')->where('status', '!=', 'disposed')
            ->orderBy('asset_no')->get()
            ->map(function ($a) use ($period) {
                [$amt, $days, $nbv] = self::monthFor($a, $period);

                return (object) ['asset' => $a, 'amount' => $amt, 'days' => $days, 'opening_nbv' => $nbv];
            })->filter(fn ($r) => $r->amount > 0)->values();
    }

    public static function run(?Carbon $period = null): AssetDepRun
    {
        $next = self::nextPeriod();
        $period = $period ? $period->copy()->endOfMonth()->startOfDay() : $next;
        if (! $next || ! $period || ! $period->equalTo($next)) {
            throw new RuntimeException('Depreciation runs month by month – the next month to run is '.($next?->format('F Y') ?? 'none').'.');
        }
        if ($why = self::blocker($period)) {
            throw new RuntimeException($why);
        }

        $run = DB::transaction(function () use ($period) {
            DB::table('asset_dep_runs')->where('hotel_id', \App\Support\Tenant::id())->lockForUpdate()->get();
            if (AssetDepRun::where('status', 'posted')->whereDate('period_end', $period->toDateString())->exists()) {
                throw new RuntimeException($period->format('F Y').' has already been depreciated.');
            }
            $rows = self::preview($period);
            $n = AssetDepRun::where('period_end', $period->toDateString())->count();
            $run = AssetDepRun::create(['run_no' => 'DEP-'.$period->format('Ym').($n ? '-'.($n + 1) : ''), 'period_end' => $period->toDateString(), 'status' => 'posted',
                'total' => round($rows->sum('amount'), 2), 'assets' => $rows->count(), 'created_by' => auth()->id()]);
            foreach ($rows as $r) {
                AssetDepLine::create(['asset_dep_run_id' => $run->id, 'asset_id' => $r->asset->id, 'opening_nbv' => $r->opening_nbv, 'amount' => $r->amount, 'days' => $r->days]);
            }
            // every asset considered for the month is now "through" it, even at zero (fully depreciated)
            Asset::where('status', '!=', 'disposed')->whereDate('dep_start', '<=', $period->toDateString())
                ->where(fn ($q) => $q->whereNull('dep_through')->orWhereDate('dep_through', '<', $period->toDateString()))
                ->update(['dep_through' => $period->toDateString()]);
            ActivityLogger::log('assets', 'depreciation', "Depreciation {$run->run_no} for ".$period->format('F Y').": {$run->assets} assets, ".money($run->total), $run);

            return $run;
        });
        LedgerService::syncSafely();

        return $run;
    }

    public static function reverse(AssetDepRun $run, string $reason): AssetDepRun
    {
        if ($run->status !== 'posted') {
            throw new RuntimeException("Run {$run->run_no} is already reversed.");
        }
        $last = self::lastRun();
        if (! $last || $last->id !== $run->id) {
            throw new RuntimeException('Only the latest depreciation run can be reversed – reverse '.$last?->run_no.' first.');
        }
        if (($lock = LedgerService::lockDate()) && $run->period_end->toDateString() <= $lock) {
            throw new RuntimeException("The books are locked up to {$lock}.");
        }
        $disposed = Asset::whereIn('id', $run->lines()->pluck('asset_id'))->where('status', 'disposed')->pluck('asset_no');
        if ($disposed->isNotEmpty()) {
            throw new RuntimeException('Asset(s) '.$disposed->implode(', ').' were disposed after this run – their book value is final.');
        }

        DB::transaction(function () use ($run, $reason) {
            $run->forceFill(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => auth()->id(), 'reverse_reason' => mb_substr($reason, 0, 190)])->save();
            $prev = self::lastRun();
            Asset::where('status', '!=', 'disposed')->whereDate('dep_through', $run->period_end->toDateString())
                ->update(['dep_through' => $prev && $prev->period_end->lt($run->period_end) ? $prev->period_end->toDateString() : null]);
            // assets that started after the previous run fall back to "not depreciated yet"
            if ($prev) {
                Asset::where('dep_through', $prev->period_end->toDateString())->whereDate('dep_start', '>', $prev->period_end->toDateString())->update(['dep_through' => null]);
            }
            ActivityLogger::log('assets', 'reverse', "Depreciation {$run->run_no} reversed: {$reason}", $run);
        });
        LedgerService::syncSafely();

        return $run->fresh();
    }

    /** Projected depreciation for the next $months months (assuming no disposals). */
    public static function projection(Asset $a, int $months = 12): array
    {
        if ($a->isDisposed()) {
            return [];
        }
        $acc = $a->accumulated();
        $clone = clone $a;
        $out = [];
        $end = ($a->dep_through ? $a->dep_through->copy()->addDay() : $a->dep_start->copy())->endOfMonth()->startOfDay();
        for ($i = 0; $i < $months; $i++) {
            [$amt] = self::monthFor($clone, $end, $acc);
            if ($amt <= 0 && $acc > 0 && round((float) $a->cost - $acc, 2) <= (float) $a->residual_value) {
                break;
            }
            $acc = round($acc + $amt, 2);
            $out[] = ['period' => $end->copy(), 'amount' => $amt, 'nbv' => round((float) $a->cost - $acc, 2)];
            $clone->dep_through = $end->copy();
            $end = $end->copy()->addDay()->endOfMonth()->startOfDay();
        }

        return $out;
    }

    // ------------------------------------------------------------------ ledger

    public static function forAcquisition(Asset $a): ?array
    {
        if ($a->acquired_via !== 'direct') {
            return null; // opening: in the opening balances; bill: the supplier bill posts Dr fixed assets / Cr payables
        }
        $c = (float) $a->cost;

        return ['date' => $a->purchase_date->toDateString(), 'reference' => $a->asset_no, 'narration' => "Asset purchase – {$a->name}".($a->reference ? " ({$a->reference})" : ''),
            'lines' => [[AccountHead::sys('fixed_assets'), $c, $a->asset_no], [LedgerService::modeAccount($a->payment_mode_id, 'bank'), -$c, $a->paymentMode?->name]]];
    }

    public static function forDepRun(AssetDepRun $run): ?array
    {
        if ($run->status !== 'posted') {
            return null;
        }
        $n = $run->lines()->count();
        $amt = round((float) $run->lines()->sum('amount'), 2);
        $memo = $run->label().' – '.$n.' asset'.($n === 1 ? '' : 's');
        $lines = $amt > 0 ? [[AccountHead::sys('depreciation'), $amt, $memo], [AccountHead::sys('acc_depreciation'), -$amt, $memo]] : [];

        return $lines ? ['date' => $run->period_end->toDateString(), 'reference' => $run->run_no, 'narration' => 'Depreciation for '.$run->label(), 'lines' => $lines] : null;
    }

    public static function forDisposal(Asset $a): ?array
    {
        if (! $a->isDisposed() || $a->disposal_nbv === null) {
            return null;
        }
        $cost = (float) $a->cost;
        $acc = round($cost - (float) $a->disposal_nbv, 2);
        $proceeds = (float) $a->disposal_proceeds;
        $gain = round($proceeds - (float) $a->disposal_nbv, 2);
        $lines = [[AccountHead::sys('acc_depreciation'), $acc, $a->asset_no], [AccountHead::sys('fixed_assets'), -$cost, $a->asset_no]];
        if ($proceeds > 0) {
            $lines[] = [LedgerService::modeAccount($a->disposal_mode_id, 'bank'), $proceeds, 'Sale proceeds'];
        }
        if (abs($gain) >= 0.005) {
            $lines[] = [AccountHead::sys('asset_disposal'), -$gain, $gain >= 0 ? 'Gain' : 'Loss'];
        }

        return ['date' => $a->disposed_date->toDateString(), 'reference' => $a->asset_no, 'narration' => 'Asset '.strtolower(Asset::DISPOSALS[$a->disposal_type] ?? 'disposed')." – {$a->name}", 'lines' => $lines];
    }

    /** Register figures as at a date, for the ledger controls: [cost, accumulated depreciation]. */
    public static function registerBalances(string $asOf): array
    {
        $live = Asset::where('purchase_date', '<=', $asOf)->where(fn ($q) => $q->whereNull('disposed_date')->orWhereDate('disposed_date', '>', $asOf));
        $cost = (float) (clone $live)->sum('cost');
        $opening = (float) (clone $live)->sum('opening_acc_dep');
        $runs = (float) AssetDepLine::whereIn('asset_id', (clone $live)->select('id'))
            ->whereHas('run', fn ($q) => $q->where('status', 'posted')->whereDate('period_end', '<=', $asOf))->sum('amount');

        return [round($cost, 2), round($opening + $runs, 2)];
    }

    // ------------------------------------------------------------------ defaults

    public static function ensureDefaults(): void
    {
        if (AssetCategory::exists()) {
            return;
        }
        foreach ([
            ['FF', 'Furniture & fixtures', 'slm', 120, 0, 5], ['KIT', 'Kitchen & laundry equipment', 'slm', 96, 0, 5], ['HVAC', 'HVAC, electrical & plant', 'slm', 180, 0, 5],
            ['IT', 'IT & electronics', 'wdv', 0, 40, 5], ['VEH', 'Vehicles', 'wdv', 0, 15, 10], ['BLD', 'Building improvements', 'slm', 300, 0, 0],
        ] as [$code, $name, $method, $life, $rate, $res]) {
            AssetCategory::create(['code' => $code, 'name' => $name, 'method' => $method, 'life_months' => $life ?: 60, 'wdv_rate' => $rate, 'residual_pct' => $res]);
        }
    }
}
