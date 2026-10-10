<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\CashForecastLine;
use App\Models\ChequeBook;
use App\Models\ChequeIssued;
use App\Models\ChequeReceived;
use App\Models\Company;
use App\Models\SupplierPayment;
use App\Models\TreasuryAccount;
use App\Models\TreasuryTransfer;
use App\Services\ActivityLogger;
use App\Services\LedgerService;
use App\Services\TreasuryService as TS;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.7 Treasury: cash position, bankings & transfers, cheque registers, 13-week forecast, cash-flow statement. */
class TreasuryController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', $ok);
    }

    public function index()
    {
        TS::syncAccounts();
        $pos = TS::position();
        $pending = TreasuryTransfer::with('from', 'to', 'requester')->whereIn('status', ['requested', 'approved', 'in_transit'])->orderBy('date')->get();
        $inHand = ChequeReceived::with('company')->where('status', 'in_hand')->orderBy('received_on')->get();
        $pdcs = ChequeReceived::with('company')->where('status', 'pdc')->orderBy('cheque_date')->limit(8)->get();
        $fc = TS::forecast(13);

        return view('treasury.index', ['pos' => $pos, 'pending' => $pending, 'inHand' => $inHand, 'pdcs' => $pdcs, 'alerts' => TS::alerts(), 'fc' => $fc,
            'recent' => TreasuryTransfer::with('from', 'to')->where('status', 'completed')->orderByDesc('completed_on')->orderByDesc('id')->limit(6)->get()]);
    }

    // ───────────── transfers ─────────────

    public function transfers(Request $r)
    {
        $q = TreasuryTransfer::with('from', 'to', 'requester')->orderByDesc('date')->orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->status);
        }
        if ($r->filled('kind')) {
            $q->where('kind', $r->kind);
        }
        $accounts = TS::accounts();
        $suggest = [];
        foreach ($accounts->whereIn('kind', ['cash', 'clearing', 'momo']) as $a) {
            $suggest[$a->id] = TS::suggest($a->kind === 'cash' ? 'deposit' : 'sweep', $a);
        }

        return view('treasury.transfers', ['transfers' => $q->paginate(30)->withQueryString(), 'accounts' => $accounts, 'suggest' => $suggest,
            'cheques' => ChequeReceived::where('status', 'in_hand')->whereNull('transfer_id')->orderBy('received_on')->get(),
            'threshold' => (float) TS::cfg('treasury_approval_threshold'), 'feeHeads' => AccountHead::where('type', 'expense')->where('is_active', true)->orderBy('code')->get()]);
    }

    public function storeTransfer(Request $r)
    {
        $d = $r->validate([
            'kind' => ['required', Rule::in(array_keys(TreasuryTransfer::KINDS))], 'from_account_id' => 'required|integer|owned:treasury_accounts', 'to_account_id' => 'required|integer|owned:treasury_accounts',
            'cash_amount' => 'nullable|numeric|min:0|max:999999999', 'fee' => 'nullable|numeric|min:0', 'fee_account_id' => 'nullable|integer|owned:account_heads',
            'date' => 'nullable|date', 'slip_no' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:500', 'via_transit' => 'nullable|boolean',
            'cheque_ids' => 'nullable|array', 'cheque_ids.*' => 'integer|owned:cheques_received', 'count' => 'nullable|array', 'count.*' => 'nullable|integer|min:0|max:100000',
        ]);
        $d['via_transit'] = $r->boolean('via_transit');
        try {
            $t = TS::createTransfer($d, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('treasury.transfers.show', $t)->with('success', "{$t->number} saved".($t->status === 'requested' ? ' – it needs approval before it is sent.' : '.'));
    }

    public function showTransfer(TreasuryTransfer $transfer)
    {
        $transfer->load('from.head', 'to.head', 'cheques.company', 'requester', 'approver', 'dispatcher', 'completer', 'dispatchJournal.lines.account', 'completeJournal.lines.account', 'feeAccount');

        return view('treasury.transfer', ['t' => $transfer, 'canApprove' => can_do('treasury.approve') && (int) $transfer->requested_by !== (int) auth()->id()]);
    }

    public function approve(Request $r, TreasuryTransfer $transfer)
    {
        return $this->attempt(fn () => TS::approve($transfer, $r->user()), "{$transfer->number} approved.");
    }

    public function reject(Request $r, TreasuryTransfer $transfer)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:255']);

        return $this->attempt(fn () => TS::reject($transfer, $r->user(), $d['reason']), "{$transfer->number} rejected.");
    }

    public function dispatchTransfer(Request $r, TreasuryTransfer $transfer)
    {
        $d = $r->validate(['date' => 'nullable|date', 'slip_no' => 'nullable|string|max:40']);

        return $this->attempt(fn () => TS::dispatch($transfer, $r->user(), $d['date'] ?? null, $d['slip_no'] ?? null), $transfer->via_transit ? "{$transfer->number} sent – now in transit." : "{$transfer->number} completed.");
    }

    public function complete(Request $r, TreasuryTransfer $transfer)
    {
        $d = $r->validate(['date' => 'nullable|date', 'received' => 'required|numeric|min:0', 'fee' => 'nullable|numeric|min:0', 'reason' => 'nullable|string|max:255', 'slip_no' => 'nullable|string|max:40']);

        return $this->attempt(fn () => TS::complete($transfer, $r->user(), $d['date'] ?? null, (float) $d['received'], isset($d['fee']) ? (float) $d['fee'] : null, $d['reason'] ?? null, $d['slip_no'] ?? null),
            "{$transfer->number} confirmed as credited by the bank.");
    }

    public function cancel(Request $r, TreasuryTransfer $transfer)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:255']);

        return $this->attempt(fn () => TS::cancel($transfer, $r->user(), $d['reason']), "{$transfer->number} cancelled.");
    }

    public function suggest(Request $r)
    {
        if (! $r->filled('from')) {   // nothing picked yet – a blank suggestion rather than a 404
            return response()->json(['amount' => 0, 'fee' => 0, 'note' => null, 'balance' => 0]);
        }
        $a = TreasuryAccount::findOrFail((int) $r->query('from'));

        return response()->json(TS::suggest((string) $r->query('kind', 'deposit'), $a) + ['balance' => TS::balance($a->account_head_id)]);
    }

    // ───────────── cheques ─────────────

    public function cheques(Request $r)
    {
        TS::capture();
        $tab = in_array($r->query('tab'), ['received', 'issued', 'books'], true) ? $r->query('tab') : 'received';
        $rq = ChequeReceived::with('company', 'transfer', 'voucher')->orderByRaw("FIELD(status,'pdc','in_hand','deposited','bounced','cleared','returned','cancelled')")->orderByDesc('received_on')->orderByDesc('id');
        if ($r->filled('status')) {
            $rq->where('status', $r->status);
        }
        $iq = ChequeIssued::with('book.account', 'payment.supplier', 'head')->orderByDesc('id');
        $books = ChequeBook::with('account')->withCount('cheques')->orderByDesc('id')->get();
        $banks = TS::accounts()->where('kind', 'bank')->values();
        $bookHeads = $books->where('status', 'active')->mapWithKeys(fn ($b) => [$b->id => (int) $b->account->account_head_id]);
        $linked = ChequeIssued::whereNotNull('supplier_payment_id')->where('status', '!=', 'void')->pluck('supplier_payment_id');
        $payments = SupplierPayment::with('supplier', 'paymentMode')->whereNull('cancelled_at')->whereNotIn('id', $linked)->where('date', '>=', bdate()->copy()->subDays(120)->toDateString())
            ->orderByDesc('date')->get()->filter(fn ($p) => in_array(LedgerService::modeAccount($p->payment_mode_id), $bookHeads->values()->all(), true))->values();

        return view('treasury.cheques', ['tab' => $tab, 'received' => $rq->paginate(40, ['*'], 'rpage')->withQueryString(), 'issued' => $iq->paginate(40, ['*'], 'ipage')->withQueryString(),
            'books' => $books, 'banks' => $banks, 'payments' => $payments, 'bookHeads' => $bookHeads, 'companies' => Company::orderBy('name')->get(['id', 'name']),
            'heads' => AccountHead::where('is_active', true)->whereNotIn('group_name', ['Cash & Bank', 'Petty Cash'])->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', TS::CONTROL_KEYS))->orderBy('code')->get(),
            'stats' => ['pdc' => ChequeReceived::where('status', 'pdc')->sum('amount'), 'in_hand' => ChequeReceived::where('status', 'in_hand')->sum('amount'),
                'deposited' => ChequeReceived::where('status', 'deposited')->sum('amount'), 'unpresented' => ChequeIssued::where('status', 'issued')->sum('amount'),
                'bounced' => ChequeReceived::where('status', 'bounced')->where('bounced_on', '>=', bdate()->copy()->subDays(90)->toDateString())->count()]]);
    }

    public function receiveCheque(Request $r)
    {
        $d = $r->validate(['company_id' => 'nullable|integer|owned:companies', 'account_head_id' => 'nullable|required_without:company_id|integer|owned:account_heads', 'drawer' => 'nullable|required_without:company_id|string|max:150',
            'cheque_no' => 'required|string|max:20', 'bank_name' => 'nullable|string|max:80', 'bank_branch' => 'nullable|string|max:100', 'cheque_date' => 'required|date', 'received_on' => 'nullable|date', 'amount' => 'required|numeric|min:0.01|max:999999999', 'notes' => 'nullable|string|max:255']);

        return $this->attempt(fn () => TS::receiveCheque($d, $r->user()), 'Cheque recorded.');
    }

    public function bankPdc(ChequeReceived $cheque)
    {
        return $this->attempt(fn () => TS::bankPdc($cheque), "{$cheque->number} receipt posted – add it to a deposit.");
    }

    public function clearCheque(ChequeReceived $cheque)
    {
        return $this->attempt(fn () => TS::clearCheque($cheque), "{$cheque->number} cleared.");
    }

    public function bounce(Request $r, ChequeReceived $cheque)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:150', 'fee' => 'nullable|numeric|min:0|max:10000', 'date' => 'nullable|date']);

        return $this->attempt(fn () => TS::bounce($cheque, $r->user(), $d['reason'], (float) ($d['fee'] ?? 0), $d['date'] ?? null), "{$cheque->number} recorded as returned unpaid – the receivable is open again.");
    }

    public function returnCheque(Request $r, ChequeReceived $cheque)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:150']);

        return $this->attempt(fn () => TS::returnCheque($cheque, $r->user(), $d['reason']), "{$cheque->number} returned.");
    }

    public function addBook(Request $r)
    {
        $d = $r->validate(['treasury_account_id' => 'required|integer|owned:treasury_accounts', 'first_no' => 'required|integer|min:1', 'last_no' => 'required|integer|min:1', 'digits' => 'nullable|integer|min:4|max:10', 'received_on' => 'nullable|date']);

        return $this->attempt(fn () => TS::addBook(TreasuryAccount::findOrFail($d['treasury_account_id']), (int) $d['first_no'], (int) $d['last_no'], (int) ($d['digits'] ?? 6), $d['received_on'] ?? bdate()->toDateString()), 'Cheque book registered.');
    }

    public function spoil(Request $r, ChequeBook $book)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:150']);

        return $this->attempt(fn () => TS::spoilLeaf($book, $r->user(), $d['reason']), 'Leaf marked as spoilt.');
    }

    public function issue(Request $r)
    {
        $d = $r->validate(['cheque_book_id' => 'required|integer|owned:cheque_books', 'supplier_payment_id' => 'nullable|integer|owned:supplier_payments', 'payee' => 'nullable|required_without:supplier_payment_id|string|max:150',
            'amount' => 'nullable|required_without:supplier_payment_id|numeric|min:0.01', 'account_head_id' => 'nullable|required_without:supplier_payment_id|integer|owned:account_heads', 'cheque_date' => 'nullable|date', 'memo' => 'nullable|string|max:255']);

        return $this->attempt(fn () => TS::issueCheque($d, $r->user()), 'Cheque written.');
    }

    public function voidIssued(Request $r, ChequeIssued $issued)
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:255', 'stop' => 'nullable|boolean']);

        return $this->attempt(fn () => TS::voidCheque($issued, $r->user(), $d['reason'], $r->boolean('stop')), 'Cheque '.$issued->cheque_no.($r->boolean('stop') ? ' stopped.' : ' voided.'));
    }

    public function clearIssued(ChequeIssued $issued)
    {
        return $this->attempt(fn () => TS::markIssuedCleared($issued), 'Cheque '.$issued->cheque_no.' marked as presented.');
    }

    public function sync()
    {
        $o = TS::daily();

        return back()->with('success', "Register updated: {$o['captured']} captured, {$o['pdcs']} post-dated cheque(s) posted, {$o['cleared']} cleared, {$o['presented']} presented, {$o['stale']} stale.");
    }

    // ───────────── forecast & cash flow ─────────────

    public function forecast(Request $r)
    {
        $weeks = max(4, min(26, (int) $r->query('weeks', 13)));

        return view('treasury.forecast', ['fc' => TS::forecast($weeks), 'weeks' => $weeks, 'lines' => CashForecastLine::with('creator')->orderByDesc('is_active')->orderBy('first_date')->get()]);
    }

    public function storeLine(Request $r)
    {
        return $this->attempt(fn () => TS::saveLine($this->lineData($r), $r->user()), 'Forecast line added.');
    }

    public function updateLine(Request $r, CashForecastLine $line)
    {
        return $this->attempt(fn () => TS::saveLine($this->lineData($r), $r->user(), $line), 'Forecast line updated.');
    }

    public function deleteLine(CashForecastLine $line)
    {
        ActivityLogger::log('treasury', 'forecast', 'Forecast line removed: '.$line->description, $line);
        $line->delete();

        return back()->with('success', 'Forecast line removed.');
    }

    protected function lineData(Request $r): array
    {
        return $r->validate(['description' => 'required|string|max:150', 'direction' => 'required|in:in,out', 'category' => 'nullable|string|max:40', 'amount' => 'required|numeric|min:0.01|max:999999999',
            'first_date' => 'required|date', 'frequency' => ['required', Rule::in(array_keys(CashForecastLine::FREQUENCIES))], 'until_date' => 'nullable|date']);
    }

    public function cashFlow(Request $r)
    {
        [$from, $to] = $this->range($r);

        return view('treasury.cashflow', ['cf' => TS::cashFlow($from, $to), 'from' => $from, 'to' => $to,
            'prior' => TS::cashFlow(Carbon::parse($from)->subYear()->toDateString(), Carbon::parse($to)->subYear()->toDateString())]);
    }

    protected function range(Request $r): array
    {
        $fy = \App\Services\BudgetService::currentFy();
        $from = $r->query('from') ?: \App\Services\CloseService::fyStart($fy);
        $to = $r->query('to') ?: bdate()->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()];
    }

    // ───────────── accounts & settings ─────────────

    public function accounts()
    {
        TS::syncAccounts();
        $heads = AccountHead::whereIn('type', ['asset', 'liability', 'equity'])->orderBy('code')->get();

        return view('treasury.accounts', ['accounts' => TS::accounts(false), 'pos' => TS::position(), 'heads' => $heads, 'cfg' => collect(TS::DEFAULTS)->mapWithKeys(fn ($v, $k) => [$k => TS::cfg($k)])]);
    }

    public function storeAccount(Request $r)
    {
        $d = $this->accountData($r, true);

        return $this->attempt(fn () => TS::createAccount($d), 'Account added.');
    }

    public function updateAccount(Request $r, TreasuryAccount $taccount)
    {
        $d = $this->accountData($r, false);

        return $this->attempt(fn () => TS::updateAccount($taccount, $d), 'Account updated.');
    }

    protected function accountData(Request $r, bool $new): array
    {
        $d = $r->validate(['kind' => [$new ? 'required' : 'nullable', Rule::in(['bank', 'momo', 'cash'])], 'name' => 'required|string|max:100', 'bank_name' => 'nullable|string|max:80', 'branch' => 'nullable|string|max:80',
            'account_no' => 'nullable|string|max:40', 'swift' => 'nullable|string|max:15', 'currency' => 'nullable|string|size:3', 'min_balance' => 'nullable|numeric|min:0', 'overdraft_limit' => 'nullable|numeric|min:0',
            'fee_rate' => 'nullable|numeric|min:0|max:20', 'settle_days' => 'nullable|integer|min:0|max:30', 'notes' => 'nullable|string|max:255']);
        $d['in_position'] = $r->boolean('in_position');
        $d['is_active'] = $r->boolean('is_active');

        return $d;
    }

    public function settings(Request $r)
    {
        $d = $r->validate(['approval_threshold' => 'required|numeric|min:0', 'cash_float' => 'required|numeric|min:0', 'payday' => 'required|integer|min:1|max:28',
            'collect_pct' => 'required|integer|min:0|max:100', 'arrival_pct' => 'required|integer|min:0|max:100', 'clear_days' => 'required|integer|min:0|max:10']);
        TS::saveSettings($d);

        return back()->with('success', 'Treasury settings saved.');
    }

    public function cfClasses(Request $r)
    {
        $d = $r->validate(['cf' => 'required|array', 'cf.*' => ['nullable', Rule::in(array_merge([''], array_keys(TS::CF_CLASSES)))]]);
        $n = 0;
        foreach ($d['cf'] as $id => $cls) {
            $h = AccountHead::find((int) $id);
            if (! $h || in_array($h->type, ['income', 'expense'], true)) {
                continue;
            }
            $v = $cls ?: null;
            if ($v === TS::autoClass($h)) {
                $v = null; // store only overrides
            }
            if ($h->cf_class !== $v) {
                $h->forceFill(['cf_class' => $v])->save();
                $n++;
            }
        }
        ActivityLogger::log('treasury', 'cf_class', "Cash-flow classification: {$n} account(s) changed");

        return back()->with('success', "Cash-flow classification saved ({$n} changed).");
    }
}
