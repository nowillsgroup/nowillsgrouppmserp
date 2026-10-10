<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\GlJournal;
use App\Models\PaymentMode;
use App\Models\Setting;
use App\Services\ChartOfAccounts;
use App\Services\GlReportService;
use App\Services\LedgerService;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GlController extends Controller
{
    public function index(Request $request)
    {
        $cutoff = setting('gl_cutoff');
        $to = $request->input('to', $cutoff ?: LedgerService::cutoff());
        $from = $request->input('from', Carbon::parse($to)->startOfMonth()->toDateString());
        $tb = GlReportService::trialBalance($from, $to);

        return view('gl.index', [
            'from' => $from, 'to' => $to, 'tb' => $tb->groupBy('type'),
            'totals' => ['dr' => $tb->sum('dr'), 'cr' => $tb->sum('cr'), 'close_dr' => $tb->sum('close_dr'), 'close_cr' => $tb->sum('close_cr'), 'open_dr' => $tb->sum('open_dr'), 'open_cr' => $tb->sum('open_cr')],
            'cutoff' => $cutoff, 'syncedAt' => setting('gl_synced_at'), 'lock' => LedgerService::lockDate(),
            'glErrors' => json_decode(setting('gl_last_errors') ?: '[]', true) ?: [],
            'controls' => $cutoff ? LedgerService::controls($cutoff) : [],
            'journalCount' => GlJournal::count(), 'nextCutoff' => LedgerService::cutoff(),
        ]);
    }

    public function ledger(Request $request, AccountHead $head)
    {
        $to = $request->input('to', setting('gl_cutoff') ?: LedgerService::cutoff());
        $from = $request->input('from', Carbon::parse($to)->startOfMonth()->toDateString());

        return view('gl.ledger', ['head' => $head, 'from' => $from, 'to' => $to, 'l' => GlReportService::ledger($head, $from, $to), 'accounts' => GlReportService::accounts()]);
    }

    public function journals(Request $request)
    {
        $to = $request->input('to', bdate()->toDateString());
        $from = $request->input('from', Carbon::parse($to)->subDays(30)->toDateString());
        $journals = GlJournal::withCount('lines')->whereBetween('date', [$from, $to])
            ->when($request->source, fn ($q, $s) => $q->where('source_type', $s))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_no', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")->orWhere('narration', 'like', "%{$s}%")))
            ->latest('date')->latest('id')->paginate(50)->withQueryString();

        return view('gl.journals', compact('journals', 'from', 'to'));
    }

    public function show(GlJournal $journal)
    {
        $journal->load('lines.account', 'creator', 'reversalOf', 'reversedBy');

        return view('gl.show', ['j' => $journal, 'minDate' => $this->minDate()]);
    }

    protected function minDate(): string
    {
        $lock = LedgerService::lockDate();

        return $lock ? Carbon::parse($lock)->addDay()->toDateString() : '2000-01-01';
    }

    public function create(Request $request)
    {
        $copy = $request->integer('copy') ? GlJournal::with('lines')->find($request->integer('copy')) : null;

        return view('gl.create', [
            'accounts' => AccountHead::where('is_active', true)->orderBy('code')->get(),
            'minDate' => $this->minDate(), 'maxDate' => bdate()->toDateString(), 'copy' => $copy,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$this->minDate(), 'before_or_equal:'.bdate()->toDateString()],
            'reference' => 'nullable|string|max:80', 'narration' => 'required|string|max:255',
            'lines' => 'required|array|min:2|max:50',
            'lines.*.account_head_id' => 'nullable|integer|owned:account_heads',
            'lines.*.debit' => 'nullable|numeric|min:0|max:999999999',
            'lines.*.credit' => 'nullable|numeric|min:0|max:999999999',
            'lines.*.memo' => 'nullable|string|max:150',
        ], ['date.after_or_equal' => 'The period up to '.LedgerService::lockDate().' is locked.']);

        $lines = [];
        foreach ($data['lines'] as $i => $l) {
            $dr = round((float) ($l['debit'] ?? 0), 2);
            $cr = round((float) ($l['credit'] ?? 0), 2);
            if (! $dr && ! $cr && empty($l['account_head_id'])) {
                continue; // blank row
            }
            if (empty($l['account_head_id'])) {
                return back()->withInput()->withErrors(["lines.$i.account_head_id" => 'Line '.($i + 1).': choose an account.']);
            }
            if (($dr > 0) === ($cr > 0)) {
                return back()->withInput()->withErrors(["lines.$i.debit" => 'Line '.($i + 1).': enter either a debit or a credit amount.']);
            }
            $lines[] = [(int) $l['account_head_id'], $dr - $cr, $l['memo'] ?? null];
        }
        if (count($lines) < 2) {
            return back()->withInput()->withErrors(['lines' => 'A journal needs at least two lines.']);
        }
        $diff = round(array_sum(array_column($lines, 1)), 2);
        if (abs($diff) >= 0.005) {
            return back()->withInput()->withErrors(['lines' => 'Debits and credits must be equal (difference '.number_format($diff, 2).').']);
        }
        $j = LedgerService::post(['date' => $data['date'], 'reference' => $data['reference'] ?? null, 'narration' => $data['narration'], 'lines' => $lines], 'manual', null);
        ActivityLogger::log('accounts', 'journal', "Manual journal {$j->journal_no} ".money($j->total), $j);

        return redirect()->route('gl.journals.show', $j)->with('success', "Journal {$j->journal_no} posted.");
    }

    public function reverse(Request $request, GlJournal $journal)
    {
        abort_unless($journal->is_manual, 403, 'Automatic journals are corrected by amending the source document; the ledger re-posts on the next sync.');
        $data = $request->validate(['date' => ['required', 'date', 'after_or_equal:'.max($this->minDate(), $journal->date->toDateString()), 'before_or_equal:'.bdate()->toDateString()], 'reason' => 'required|string|max:120']);
        try {
            $r = LedgerService::reverse($journal, $data['date'], $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        ActivityLogger::log('accounts', 'journal_reverse', "Reversed {$journal->journal_no} by {$r->journal_no}: {$data['reason']}", $journal);

        return redirect()->route('gl.journals.show', $r)->with('success', "{$journal->journal_no} reversed by {$r->journal_no}.");
    }

    public function sync(Request $request)
    {
        $full = $request->boolean('full');
        $s = LedgerService::sync($full);
        ActivityLogger::log('accounts', 'gl_sync', ($full ? 'Full GL re-check' : 'GL sync')." up to {$this->fmt(LedgerService::cutoff())}: {$s['posted']} posted, {$s['reposted']} re-posted, {$s['reversed']} reversed, ".count($s['errors']).' errors');
        $msg = "Ledger posted up to {$this->fmt(LedgerService::cutoff())}: {$s['posted']} new, {$s['reposted']} re-posted, {$s['reversed']} reversed ({$s['checked']} sources checked).";

        return back()->with($s['errors'] ? 'error' : 'success', $msg.($s['errors'] ? ' '.count($s['errors']).' source(s) could not be posted – see the list below.' : ''));
    }

    protected function fmt(string $d): string
    {
        return Carbon::parse($d)->format('d M Y');
    }

    public function lock(Request $request)
    {
        $data = $request->validate(['lock_date' => 'nullable|date|before_or_equal:'.(setting('gl_cutoff') ?: LedgerService::cutoff()), 'reason' => 'required_with:unlock|nullable|string|max:120', 'unlock' => 'nullable|boolean']);
        $old = LedgerService::lockDate();
        $new = $request->boolean('unlock') ? null : ($data['lock_date'] ?? null);
        if (! $request->boolean('unlock') && ! $new) {
            return back()->with('error', 'Choose the date to lock up to.');
        }
        $closed = \App\Services\CloseService::lastClosedEnd(); // v5.6: closed periods are reopened through Period Close, four-eyes
        if ($closed && (! $new || $new < $closed)) {
            return back()->with('error', 'Periods up to '.$this->fmt($closed).' are closed. Reopen them through Accounts › Period Close (needs a second approver).');
        }
        if ($new && $old && $new < $old && ! $request->filled('reason')) {
            return back()->with('error', 'Moving the lock date back re-opens a closed period – give a reason.');
        }
        Setting::updateOrCreate(['key' => 'gl_lock_date'], ['value' => $new ?? '']);
        ActivityLogger::log('accounts', 'gl_lock', $new ? "Period locked up to {$this->fmt($new)}".($old ? " (was {$this->fmt($old)})" : '').($request->reason ? " – {$request->reason}" : '') : 'Period lock removed'.($old ? " (was {$this->fmt($old)})" : '')." – {$request->reason}");

        return back()->with('success', $new ? "Books locked up to {$this->fmt($new)}. Later corrections post on the next open day." : 'Period lock removed.');
    }

    public function mapping()
    {
        ChartOfAccounts::ensureLight();
        $accounts = AccountHead::where('is_active', true)->orderBy('code')->get();

        return view('gl.mapping', [
            'accounts' => $accounts, 'categories' => config('pms.charge_categories'),
            'modes' => PaymentMode::orderBy('name')->get(),
            'catDefaults' => collect(config('pms.charge_categories'))->mapWithKeys(fn ($l, $k) => [$k => LedgerService::categoryAccount($k)]),
            'posRevenue' => LedgerService::posRevenueAccount(),
        ]);
    }

    public function saveMapping(Request $request)
    {
        $cats = array_keys(config('pms.charge_categories'));
        $data = $request->validate([
            'cat' => 'array', 'cat.*' => 'nullable|integer|owned:account_heads',
            'mode' => 'array', 'mode.*' => 'nullable|integer|owned:account_heads',
            'pos_revenue' => 'nullable|integer|owned:account_heads', 'input_tax' => 'nullable|boolean',
        ]);
        foreach ($cats as $c) {
            Setting::updateOrCreate(['key' => 'gl_cat_'.$c], ['value' => (string) ($data['cat'][$c] ?? '')]);
        }
        Setting::updateOrCreate(['key' => 'gl_pos_revenue'], ['value' => (string) ($data['pos_revenue'] ?? '')]);
        Setting::updateOrCreate(['key' => 'gl_input_tax'], ['value' => $request->boolean('input_tax') ? '1' : '0']);
        foreach (PaymentMode::all() as $m) {
            if (array_key_exists($m->id, $data['mode'] ?? [])) {
                $m->forceFill(['account_head_id' => $data['mode'][$m->id] ?: null])->save();
            }
        }
        ActivityLogger::log('accounts', 'gl_mapping', 'Updated GL account mapping');

        return back()->with('success', 'Mapping saved. It applies to postings made from now on; use “Re-check all” to re-post open periods.');
    }
}
