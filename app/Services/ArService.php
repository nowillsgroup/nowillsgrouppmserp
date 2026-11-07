<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FolioTransaction;
use App\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Company receivables — the city ledger, aged.
 *
 * A city-ledger charge is not paid against itself: a company settles in lumps, and the PMS has never recorded which
 * invoice a receipt settles. So the ageing is FIFO — the oldest open charge is treated as settled first — and that is
 * the only honest reading of the sub-ledger as it stands.
 *
 * v8.9 the ageing is computed here once, with the documents behind each bucket, and both readers use it: the AR ageing
 * report and the receivables screen. Before this the report was the only reader and it returned totals alone, so a
 * company's "over 90" figure could not be opened to see which charges made it up. The items carry the open part of each
 * charge after FIFO, plus any receipt left over as a negative line, so the documents add up to the outstanding balance
 * the table prints.
 */
class ArService
{
    /** the buckets, oldest last, keyed exactly as the report and the screen print them */
    public const BUCKETS = ['b0' => '0–30 days', 'b30' => '31–60 days', 'b60' => '61–90 days', 'b90' => 'Over 90 days'];

    public static function bucketOf(int $days): string
    {
        return $days <= 30 ? 'b0' : ($days <= 60 ? 'b30' : ($days <= 90 ? 'b60' : 'b90'));
    }

    /**
     * Every company with a charge or a receipt to its name, aged as at a date.
     *
     * @return Collection<int, array{company: string, type: string, billed: float, received: float, b0: float, b30: float, b60: float, b90: float, unapplied: float, outstanding: float, limit: float, oldest: string, oldest_days: int, flag: string, id: int, counts: array<string, int>, items: array<int, array>}>
     */
    public static function ageing(Carbon $asOn): Collection
    {
        $asOn = $asOn->copy()->startOfDay();
        $charges = FolioTransaction::where('category', 'city_ledger')->where('is_void', false)->whereNotNull('company_id')
            ->whereDate('business_date', '<=', $asOn)
            ->orderBy('business_date')->orderBy('id')
            ->get(['id', 'company_id', 'business_date', 'due_date', 'amount', 'reference', 'description', 'source_type', 'opening_date'])
            ->groupBy('company_id');
        $receipts = Voucher::where('type', 'receipt')->whereNotNull('company_id')->whereDate('date', '<=', $asOn)
            ->orderBy('date')->orderBy('id')->get(['id', 'company_id', 'date', 'amount', 'voucher_no', 'reference'])->groupBy('company_id');
        $companies = Company::whereIn('id', $charges->keys()->merge($receipts->keys())->unique())->orderBy('name')->get();

        return $companies->map(function ($c) use ($charges, $receipts, $asOn) {
            $paid = (float) ($receipts[$c->id] ?? collect())->sum('amount');
            $credit = $paid;
            $b = ['b0' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0];
            $counts = ['b0' => 0, 'b30' => 0, 'b60' => 0, 'b90' => 0];
            $items = [];
            $billed = 0.0;
            $oldest = null;
            foreach ($charges[$c->id] ?? [] as $charge) {
                $amt = (float) $charge->amount;
                $billed += $amt;
                $applied = min($amt, $credit);   // FIFO: the oldest charge is settled first
                $credit -= $applied;
                $open = round($amt - $applied, 2);
                if ($open <= 0.004) {
                    continue;
                }
                $dueDate = $charge->due_date?->copy() ?? $charge->business_date->copy()->addDays((int) $c->credit_days);
                $age = $dueDate->lt($asOn) ? max(0, (int) $dueDate->diffInDays($asOn)) : 0;
                if ($age > 0) {
                    $oldest = max($oldest ?? 0, $age);
                }
                $bucket = self::bucketOf($age);
                $b[$bucket] += $open;
                $counts[$bucket]++;
                $items[] = ['kind' => $charge->opening_date !== null ? 'Opening balance' : 'Bill',
                    'company_id' => (int) $c->id, 'company' => $c->name,
                    'doc' => $charge->reference ?: ('C/L '.$charge->id), 'desc' => $charge->description,
                    'date' => $charge->business_date->toDateString(), 'due' => $dueDate->toDateString(),
                    'days' => $age, 'bucket' => $bucket, 'amount' => round($open, 2), 'note' => null];
            }
            $outstanding = array_sum($b) - $credit;   // leftover credit = advance / on-account
            if ($credit > 0.004) {
                // a receipt nobody has been billed for yet: it reduces what is owed, so it is listed as a credit
                $items[] = ['kind' => 'Receipt not applied', 'company_id' => (int) $c->id, 'company' => $c->name,
                    'doc' => 'on account', 'desc' => null, 'date' => $asOn->toDateString(), 'due' => null,
                    'days' => 0, 'bucket' => 'b0', 'amount' => round(-1 * $credit, 2), 'note' => 'credit'];
            }

            return ['company' => $c->name, 'type' => ucwords(str_replace('_', ' ', $c->type)),
                'billed' => round($billed, 2), 'received' => round($paid, 2),
                'b0' => round($b['b0'], 2), 'b30' => round($b['b30'], 2), 'b60' => round($b['b60'], 2), 'b90' => round($b['b90'], 2),
                'unapplied' => round($credit, 2), 'outstanding' => round($outstanding, 2), 'limit' => (float) $c->credit_limit,
                'oldest' => $oldest !== null ? $oldest.' d' : '—', 'oldest_days' => (int) ($oldest ?? 0),
                'flag' => $c->credit_limit > 0 && $outstanding > $c->credit_limit ? 'OVER LIMIT' : ($b['b90'] > 0 ? '90+ overdue' : ''),
                'id' => (int) $c->id, 'counts' => $counts, 'items' => $items];
        })->filter(fn ($r) => abs($r['outstanding']) > 0.004 || $r['billed'] > 0 || $r['unapplied'] > 0.004)
            ->sortByDesc('outstanding')->values();
    }

    /** the documents behind the whole ledger, oldest first — what the receivables screen lists under the buckets */
    public static function items(Carbon $asOn): Collection
    {
        return self::ageing($asOn)->flatMap(fn ($r) => $r['items'])
            ->sortByDesc('amount')->sortByDesc('days')->values();
    }
}
