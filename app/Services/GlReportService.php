<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\GlJournal;
use App\Models\GlLine;
use Illuminate\Support\Collection;

/** Financial statements computed from gl_lines. Rows are plain arrays so they feed both GL pages and the report engine. */
class GlReportService
{
    public static function accounts(): Collection
    {
        return AccountHead::orderBy('code')->orderBy('name')->get();
    }

    /**
     * Trial balance: opening (before $from), period movement, closing – split into Dr / Cr columns.
     * Year-end closing entries dated before $from are in the opening balances; those inside the range are left out
     * (a pre-closing trial balance), so income and expense still show the year's figures.
     */
    public static function trialBalance(string $from, string $to, bool $withZero = false): Collection
    {
        $before = LedgerService::totals(null, date('Y-m-d', strtotime($from.' -1 day')));
        $period = LedgerService::totals($from, $to, false);

        return self::accounts()->map(function ($a) use ($before, $period) {
            $open = round((float) ($before[$a->id]->dr ?? 0) - (float) ($before[$a->id]->cr ?? 0), 2);
            $dr = round((float) ($period[$a->id]->dr ?? 0), 2);
            $cr = round((float) ($period[$a->id]->cr ?? 0), 2);
            $close = round($open + $dr - $cr, 2);

            return ['id' => $a->id, 'code' => $a->code, 'account' => $a->name, 'type' => AccountHead::TYPES[$a->type] ?? $a->type, 'group' => $a->group_name,
                'open_dr' => max($open, 0), 'open_cr' => max(-$open, 0), 'dr' => $dr, 'cr' => $cr, 'close_dr' => max($close, 0), 'close_cr' => max(-$close, 0)];
        })->filter(fn ($r) => $withZero || $r['open_dr'] + $r['open_cr'] + $r['dr'] + $r['cr'] > 0)->values();
    }

    /** Natural-sign balances (assets/expenses Dr+, others Cr+) grouped by type → group → account. */
    protected static function natural(Collection $totals, array $types): Collection
    {
        return self::accounts()->whereIn('type', $types)->map(function ($a) use ($totals) {
            $net = (float) ($totals[$a->id]->dr ?? 0) - (float) ($totals[$a->id]->cr ?? 0);

            return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'group' => $a->group_name ?: 'Other',
                'amount' => round($a->isDebitNature() ? $net : -$net, 2)];
        })->filter(fn ($r) => abs($r['amount']) >= 0.005)->values();
    }

    public static function balanceSheet(string $asOf): array
    {
        $t = LedgerService::totals(null, $asOf);
        $rows = collect();
        $totals = [];
        $earnings = round(self::natural($t, ['income'])->sum('amount') - self::natural($t, ['expense'])->sum('amount'), 2);
        foreach (['asset' => 'ASSETS', 'liability' => 'LIABILITIES', 'equity' => 'EQUITY'] as $type => $title) {
            $accts = self::natural($t, [$type]);
            $rows->push(['section' => $title, '_class' => 'row-head']);
            foreach ($accts->groupBy('group') as $group => $items) {
                $rows->push(['section' => '', 'group' => $group, 'amount' => null, '_class' => 'row-sub']);
                foreach ($items as $i) {
                    $rows->push(['section' => '', 'group' => '', 'code' => $i['code'], 'account' => $i['name'], 'amount' => $i['amount']]);
                }
            }
            $sum = $accts->sum('amount');
            if ($type === 'equity') {
                $rows->push(['section' => '', 'group' => 'Current earnings', 'account' => 'Profit / (loss) not yet closed to retained earnings', 'amount' => $earnings]);
                $sum += $earnings;
            }
            $totals[$type] = round($sum, 2);
            $rows->push(['section' => 'Total '.strtolower($title), 'amount' => $totals[$type], '_class' => 'row-total']);
        }
        $le = round($totals['liability'] + $totals['equity'], 2);
        $rows->push(['section' => 'Total liabilities + equity', 'amount' => $le, '_class' => 'row-total']);

        return ['rows' => $rows, 'assets' => $totals['asset'], 'liabilities' => $totals['liability'], 'equity' => $totals['equity'], 'earnings' => $earnings, 'difference' => round($totals['asset'] - $le, 2)];
    }

    public static function profitLoss(string $from, string $to): array
    {
        $t = LedgerService::totals($from, $to, false);
        $rows = collect();
        $sums = [];
        foreach (['income' => 'INCOME', 'expense' => 'EXPENSES'] as $type => $title) {
            $accts = self::natural($t, [$type]);
            $rows->push(['section' => $title, '_class' => 'row-head']);
            foreach ($accts->groupBy('group') as $group => $items) {
                $rows->push(['section' => '', 'group' => $group, '_class' => 'row-sub', 'amount' => round($items->sum('amount'), 2)]);
                foreach ($items as $i) {
                    $rows->push(['section' => '', 'group' => '', 'code' => $i['code'], 'account' => $i['name'], 'amount' => $i['amount']]);
                }
            }
            $sums[$type] = round($accts->sum('amount'), 2);
            $rows->push(['section' => 'Total '.strtolower($title), 'amount' => $sums[$type], '_class' => 'row-total']);
        }
        $net = round($sums['income'] - $sums['expense'], 2);
        $rows->push(['section' => $net >= 0 ? 'NET PROFIT' : 'NET LOSS', 'amount' => $net, '_class' => 'row-total']);

        return ['rows' => $rows, 'income' => $sums['income'], 'expense' => $sums['expense'], 'net' => $net];
    }

    public static function journalRegister(string $from, string $to): Collection
    {
        return GlLine::with('journal', 'account')->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('gl_journal_id')->orderByDesc('debit')->get()
            ->map(fn ($l) => ['date' => $l->date->format('d-m-Y'), 'jv' => $l->journal->journal_no, 'source' => GlJournal::SOURCES[$l->journal->source_type] ?? $l->journal->source_type,
                'ref' => $l->journal->reference, 'narration' => $l->journal->narration, 'account' => $l->account->label, 'memo' => $l->memo, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit]);
    }

    /** Account ledger with opening and running balance in the account's natural sign. */
    public static function ledger(AccountHead $a, string $from, string $to): array
    {
        $sign = $a->isDebitNature() ? 1 : -1;
        $open = $sign * (float) GlLine::where('account_head_id', $a->id)->where('date', '<', $from)->sum(\DB::raw('debit - credit'));
        $run = $open;
        $lines = GlLine::with('journal')->where('account_head_id', $a->id)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get()
            ->map(function ($l) use (&$run, $sign) {
                $run += $sign * ((float) $l->debit - (float) $l->credit);

                return ['line' => $l, 'balance' => round($run, 2)];
            });

        return ['opening' => round($open, 2), 'lines' => $lines, 'closing' => round($run, 2), 'dr' => $lines->sum(fn ($x) => (float) $x['line']->debit), 'cr' => $lines->sum(fn ($x) => (float) $x['line']->credit)];
    }
}
