<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\BankRecService as Rec;
use Illuminate\Http\Request;
use RuntimeException;

/** v4.6 Accounts › Bank Reconciliation: statements (CSV / manual), matching, bank-only entries, the reconciliation statement. */
class BankRecController extends Controller
{
    protected function attempt(callable $fn, ?string $ok = null)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', $ok);
    }

    public function index(Request $request)
    {
        $acc = $request->integer('account') ?: null;

        return view('bankrec.index', [
            'overview' => Rec::overview(),
            'statements' => BankStatement::with('account', 'reconciler')->withCount(['lines', 'lines as matched_count' => fn ($q) => $q->where('status', 'matched'), 'lines as open_count' => fn ($q) => $q->where('status', 'unmatched')])
                ->when($acc, fn ($q) => $q->where('account_head_id', $acc))->orderByDesc('period_to')->orderByDesc('id')->paginate(25)->withQueryString(),
            'accounts' => Rec::accounts(), 'account' => $acc,
            'cfg' => collect(Rec::DEFAULTS)->map(fn ($v, $k) => Rec::cfg($k))->all(),
        ]);
    }

    public function create(Request $request)
    {
        $accounts = Rec::accounts();
        $starts = $accounts->mapWithKeys(fn ($a) => [$a->id => Rec::nextStart($a->id) + [2 => (bool) Rec::last($a->id)]]);
        $busy = BankStatement::where('status', 'draft')->pluck('statement_no', 'account_head_id');

        return view('bankrec.create', ['accounts' => $accounts, 'starts' => $starts, 'busy' => $busy,
            'account' => $request->integer('account') ?: $accounts->firstWhere('system_key', 'bank')?->id]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'account_head_id' => 'required|integer|owned:account_heads', 'period_from' => 'required|date', 'period_to' => 'required|date|after_or_equal:period_from',
            'opening_balance' => 'required|numeric|between:-1000000000,1000000000', 'closing_balance' => 'nullable|numeric|between:-1000000000,1000000000',
            'mode' => 'required|in:csv,manual', 'file' => 'required_if:mode,csv|nullable|file|max:4096|mimes:csv,txt',
            'date_format' => 'nullable|in:'.implode(',', array_keys(Rec::DATE_FORMATS)), 'invert' => 'nullable|boolean', 'notes' => 'nullable|string|max:500',
            'col' => 'nullable|array', 'col.*' => 'nullable|string|max:60',
        ]);
        $from = \Illuminate\Support\Carbon::parse($d['period_from'])->toDateString();
        $to = \Illuminate\Support\Carbon::parse($d['period_to'])->toDateString();
        $closing = isset($d['closing_balance']) && $d['closing_balance'] !== null && $d['closing_balance'] !== '' ? (float) $d['closing_balance'] : null;

        return $this->attempt(function () use ($request, $d, $from, $to, $closing) {
            if ($d['mode'] === 'manual') {
                $s = Rec::create((int) $d['account_head_id'], $from, $to, (float) $d['opening_balance'], $closing ?? (float) $d['opening_balance'], [], 'manual', null, $d['notes'] ?? null);

                return redirect()->route('bankrec.show', $s)->with('success', "Statement {$s->statement_no} created – add its lines.");
            }
            $file = $request->file('file');
            $text = (string) file_get_contents($file->getRealPath());
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            }
            $cols = array_filter($d['col'] ?? [], fn ($v) => trim((string) $v) !== '');
            [$s, $n, $skipped, $dups, $warn] = Rec::import((int) $d['account_head_id'], $from, $to, (float) $d['opening_balance'], $closing, $text,
                mb_substr(basename($file->getClientOriginalName()), 0, 190), $cols, $d['date_format'] ?? 'auto', (bool) ($d['invert'] ?? false));
            $auto = Rec::autoMatch($s);
            $msg = "Imported {$n} line(s) into {$s->statement_no}".($skipped ? ", {$skipped} summary / blank row(s) skipped" : '').($dups ? ", {$dups} already-imported line(s) left out" : '')
                .'. Auto-matched '.array_sum($auto).'.';

            return redirect()->route('bankrec.show', $s)->with('success', $msg)->with($warn ? 'warning' : 'x', implode(' ', array_slice($warn, 0, 5)));
        });
    }

    public function show(Request $request, BankStatement $bstmt)
    {
        Rec::heal($bstmt);
        $bstmt->refresh();
        $sum = Rec::summary($bstmt);
        $open = $bstmt->isDraft() ? Rec::candidates($bstmt->account_head_id, $bstmt->period_to->copy()->addDays(31)->toDateString()) : collect();

        return view('bankrec.show', [
            's' => $bstmt, 'sum' => $sum, 'blockers' => $bstmt->isDraft() ? Rec::blockers($bstmt, $sum) : [],
            'lines' => $bstmt->lines()->with('glLines.journal', 'entryAccount', 'matcher')->get(),
            'open' => $open, 'filter' => in_array($request->filter, ['unmatched', 'matched', 'ignored'], true) ? $request->filter : null,
            'heads' => AccountHead::where('is_active', true)->where('id', '!=', $bstmt->account_head_id)->orderBy('code')->get()->mapWithKeys(fn ($h) => [$h->id => trim($h->code.' '.$h->name)]),
            'isLatest' => Rec::last($bstmt->account_head_id)?->id === $bstmt->id,
        ]);
    }

    public function print(BankStatement $bstmt)
    {
        return view('bankrec.print', ['s' => $bstmt, 'sum' => Rec::summary($bstmt)]);
    }

    public function autoMatch(BankStatement $bstmt)
    {
        return $this->attempt(function () use ($bstmt) {
            $r = Rec::autoMatch($bstmt);
            $n = array_sum($r);

            return back()->with($n ? 'success' : 'warning', $n ? "Matched {$n} line(s): {$r['reference']} by reference, {$r['amount']} by amount and date, {$r['group']} settlement batch(es)." : 'No further matches found – match the rest by hand, book or ignore them.');
        });
    }

    public function match(Request $request, BankStatement $bstmt)
    {
        $d = $request->validate(['line_id' => 'required|integer', 'gl' => 'required|array|min:1|max:200', 'gl.*' => 'integer']);
        $line = BankStatementLine::where('bank_statement_id', $bstmt->id)->findOrFail($d['line_id']);

        return $this->attempt(fn () => Rec::match($line, $d['gl']), 'Matched.');
    }

    public function unmatch(BankStatementLine $bline)
    {
        return $this->attempt(fn () => Rec::unmatch($bline), 'Unmatched'.($bline->entry_kind ? ' – the booked entry is reversed.' : '.'));
    }

    public function ignore(Request $request, BankStatementLine $bline)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => Rec::ignore($bline, $d['reason']), 'Line ignored.');
    }

    public function unignore(BankStatementLine $bline)
    {
        return $this->attempt(fn () => Rec::unignore($bline), 'Line back to unmatched.');
    }

    public function entry(Request $request, BankStatementLine $bline)
    {
        $d = $request->validate(['kind' => 'required|in:'.implode(',', array_keys(Rec::ENTRY_KINDS)), 'account_head_id' => 'nullable|required_if:kind,other|integer|owned:account_heads', 'memo' => 'nullable|string|max:150']);

        return $this->attempt(function () use ($bline, $d) {
            $j = Rec::createEntry($bline, $d['kind'], isset($d['account_head_id']) ? (int) $d['account_head_id'] : null, $d['memo'] ?? null);

            return back()->with('success', "Booked as {$j->journal_no} and matched.");
        });
    }

    public function addLine(Request $request, BankStatement $bstmt)
    {
        $d = $request->validate(['date' => 'required|date', 'description' => 'nullable|string|max:255', 'reference' => 'nullable|string|max:80', 'amount' => 'required|numeric|between:-1000000000,1000000000']);
        $d['date'] = \Illuminate\Support\Carbon::parse($d['date'])->toDateString();

        return $this->attempt(fn () => Rec::addLine($bstmt, $d), 'Line added.');
    }

    public function deleteLine(BankStatementLine $bline)
    {
        return $this->attempt(fn () => Rec::deleteLine($bline), 'Line deleted.');
    }

    public function closing(Request $request, BankStatement $bstmt)
    {
        $d = $request->validate(['closing_balance' => 'required|numeric|between:-1000000000,1000000000']);

        return $this->attempt(fn () => Rec::updateBalances($bstmt, (float) $d['closing_balance']), 'Closing balance updated.');
    }

    public function finalise(BankStatement $bstmt)
    {
        return $this->attempt(fn () => Rec::finalise($bstmt), "{$bstmt->statement_no} reconciled and locked.");
    }

    public function reopen(Request $request, BankStatement $bstmt)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => Rec::reopen($bstmt, $d['reason']), "{$bstmt->statement_no} reopened.");
    }

    public function destroy(BankStatement $bstmt)
    {
        return $this->attempt(function () use ($bstmt) {
            Rec::destroy($bstmt);

            return redirect()->route('bankrec.index')->with('success', "Statement {$bstmt->statement_no} deleted.");
        });
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['bankrec_window_days' => 'required|integer|min:0|max:31', 'bankrec_stale_days' => 'required|integer|min:7|max:365', 'bankrec_segregation' => 'nullable|boolean']);
        $d['bankrec_segregation'] = ! empty($d['bankrec_segregation']) ? '1' : '0';
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('bankrec', 'settings', "Bank reconciliation settings: ±{$d['bankrec_window_days']} days, overdue after {$d['bankrec_stale_days']} days, segregation ".($d['bankrec_segregation'] === '1' ? 'on' : 'off'));

        return back()->with('success', 'Bank reconciliation settings saved.');
    }
}
