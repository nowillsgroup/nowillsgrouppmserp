<?php

namespace App\Http\Controllers;

use App\Models\AccountHead;
use App\Models\Company;
use App\Models\FolioTransaction;
use App\Models\GlLine;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\ActivityLogger;
use App\Services\ArService;
use App\Services\LedgerService;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountsController extends Controller
{
    /**
     * v9.11 The voucher register.
     *
     * Every receipt and payment the property writes lands here, and each one becomes a general-ledger entry at its account
     * head. The register used to be a filtered list with three figures above it: no totals in the table, no analysis by
     * head, and no statement of whether the vouchers in the period had reached the ledger — which is the one thing the
     * register can tell an accountant that no other screen can. It now does all three: the period is clamped, the table
     * totals itself, the heads are analysed, and each head's vouchers dated on or before the posted-through day are
     * compared with that head's posted movement.
     */
    public function vouchers(Request $request)
    {
        $from = substr((string) $request->input('from', bdate()->copy()->startOfMonth()->toDateString()), 0, 10);
        $to = substr((string) $request->input('to', bdate()->toDateString()), 0, 10);
        if ($to > bdate()->toDateString()) {
            $to = bdate()->toDateString();   // a register cannot list vouchers that have not been written
        }
        if ($from > $to) {
            $from = $to;
        }
        $vouchers = Voucher::with('head', 'paymentMode', 'supplier', 'user')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->head, fn ($q, $h) => $q->where('account_head_id', $h))
            ->latest('date')->latest('id')->get();

        // the register totals itself, so a printed page carries its own sums
        $rec = round((float) $vouchers->where('type', 'receipt')->sum('amount'), 2);
        $pay = round((float) $vouchers->where('type', 'payment')->sum('amount'), 2);

        // the analysis by head: what each account head took in, paid out, and kept
        $byHead = $vouchers->groupBy('account_head_id')->map(function ($g) {
            $first = $g->first();

            return ['head' => $first->head?->name ?? '—', 'type' => $first->type, 'vouchers' => $g->count(),
                'in' => round((float) $g->where('type', 'receipt')->sum('amount'), 2),
                'out' => round((float) $g->where('type', 'payment')->sum('amount'), 2)];
        })->transform(function ($r) { $r['net'] = round($r['in'] - $r['out'], 2); return $r; })->sortBy('head')->values();

        // the tie: each head's vouchers dated on or before the posted-through day against that head's posted movement.
        // A voucher dated after it is not late — the day is simply not closed — so those are counted and named apart.
        $cut = LedgerService::cutoff();
        $postedVouchers = $vouchers->filter(fn ($v) => $v->date->toDateString() <= $cut);
        $expected = $postedVouchers->groupBy('account_head_id')->map(function ($g) {
            $first = $g->first();
            // a payment debits its head and a receipt credits it, so both read as debit-negative movement
            $movement = $g->sum(fn ($v) => $v->type === 'payment' ? (float) $v->amount : -1 * (float) $v->amount);

            return ['head' => $first->head?->name ?? '—', 'vouchers' => $g->count(), 'expected' => round((float) $movement, 2)];
        });
        $actual = GlLine::whereIn('account_head_id', $expected->keys())
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->select('account_head_id', DB::raw('SUM(debit - credit) as amt'))->groupBy('account_head_id')->pluck('amt', 'account_head_id');
        $ledger = $expected->map(fn ($r, $id) => $r + ['actual' => round((float) ($actual[$id] ?? 0), 2)])
            ->transform(function ($r) { $r['diff'] = round($r['expected'] - $r['actual'], 2); return $r; })->sortBy('head')->values();
        $tie = ['from' => $from, 'to' => $to, 'posted_through' => $cut,
            'posted' => $postedVouchers->count(), 'unposted' => $vouchers->count() - $postedVouchers->count(),
            'expected' => round((float) $ledger->sum('expected'), 2), 'actual' => round((float) $ledger->sum('actual'), 2),
            'drift' => $ledger->filter(fn ($r) => abs($r['diff']) > 0.005)->values()->all()];
        $tie['diff'] = round($tie['expected'] - $tie['actual'], 2);
        $tie['ties'] = $tie['drift'] === [];
        // the card compares magnitudes: a register that expects −6,800 against a ledger of −6,800 agrees
        $tie['expected_abs'] = abs($tie['expected']);
        $tie['actual_abs'] = abs($tie['actual']);
        $tie['diff_abs'] = abs($tie['diff']);

        return view('accounts.vouchers', [
            'vouchers' => $vouchers, 'from' => $from, 'to' => $to, 'rec' => $rec, 'pay' => $pay,
            'byHead' => $byHead, 'ledger' => $ledger, 'tie' => $tie,
            'heads' => AccountHead::orderBy('type')->orderBy('code')->get(),
            // the modal groups its heads here rather than asking the view to group a collection
            'headGroups' => AccountHead::where('is_active', true)->orderBy('type')->orderBy('code')->get()->groupBy('type'),
            'paymentModes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id'),
            'suppliers' => Supplier::pluck('name', 'id'),
        ]);
    }

    public function storeVoucher(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:payment,receipt', 'date' => 'required|date', 'account_head_id' => 'required|integer|owned:account_heads',
            'payment_mode_id' => 'nullable|integer|owned:payment_modes', 'supplier_id' => 'nullable|integer|owned:suppliers',
            'party_name' => 'nullable|string|max:150', 'amount' => 'required|numeric|min:0.01', 'reference' => 'nullable|string|max:60', 'narration' => 'nullable|string|max:500',
        ]);
        $v = Voucher::create($data + ['voucher_no' => Numbering::next('voucher'), 'user_id' => auth()->id()]);
        ActivityLogger::log('accounts', 'voucher', ucfirst($v->type)." voucher {$v->voucher_no} ".money($v->amount), $v);

        return back()->with('success', "Voucher {$v->voucher_no} saved.");
    }

    public function deleteVoucher(Voucher $voucher)
    {
        ActivityLogger::log('accounts', 'voucher_delete', "Deleted voucher {$voucher->voucher_no} ".money($voucher->amount), $voucher);
        // deleting a commission payment puts those commissions back to "due"
        \App\Models\AgentCommission::where('voucher_id', $voucher->id)->update(['status' => 'due', 'voucher_id' => null, 'paid_at' => null]);
        $voucher->delete();

        return back()->with('success', 'Voucher deleted.');
    }

    /** Company receivables: city-ledger transfers minus receipts. */
    protected function companyBalances(?string $asOf = null)
    {
        // v9.8 a balance is a balance as at a date: without the cut a receipt dated next week paid today's bill, and the
        // index disagreed with the ageing it indexes.
        $debits = FolioTransaction::where('category', 'city_ledger')->where('is_void', false)
            ->when($asOf, fn ($q) => $q->whereDate('business_date', '<=', $asOf))
            ->select('company_id', DB::raw('SUM(amount) as amt'))->groupBy('company_id')->pluck('amt', 'company_id');
        $credits = Voucher::where('type', 'receipt')->whereNotNull('company_id')
            ->when($asOf, fn ($q) => $q->whereDate('date', '<=', $asOf))
            ->select('company_id', DB::raw('SUM(amount) as amt'))->groupBy('company_id')->pluck('amt', 'company_id');

        return [$debits, $credits];
    }

    /**
     * v9.8 The city ledger index — every company with its balance, read as at a date and held to the reading it indexes.
     *
     * The list is a separate query from ArService::ageing() and always was: it summed every charge and receipt in the
     * database, the ageing cut them at a date and FIFO-netted the receipts. Two queries over one sub-ledger can drift, so
     * the page states its own tie — list total against ageing total — and names the companies whose rows disagree, which
     * is the row to open. The credit-limit and overdue flags come from the ageing too instead of re-testing the limit here.
     */
    public function cityLedger(Request $request)
    {
        $asOf = substr((string) $request->input('as_of', bdate()->toDateString()), 0, 10);
        if ($asOf > bdate()->toDateString()) {
            $asOf = bdate()->toDateString();   // the ledger has not been posted past the business date
        }
        [$debits, $credits] = $this->companyBalances($asOf);
        $aged = ArService::ageing(Carbon::parse($asOf))->keyBy('id');
        $companies = Company::orderBy('name')->get()->each(function ($c) use ($debits, $credits, $aged) {
            $c->billed = (float) ($debits[$c->id] ?? 0);
            $c->received = (float) ($credits[$c->id] ?? 0);
            $c->outstanding = round($c->billed - $c->received, 2);
            // the ageing is the same money read FIFO: a company paid in advance is a credit there and here
            $row = $aged->get($c->id);
            $c->aged = round((float) ($row['outstanding'] ?? 0), 2);
            $c->flag = (string) ($row['flag'] ?? '');           // 'OVER LIMIT' / '90+ overdue', decided once, in ArService
            $c->ties = abs(round($c->outstanding - $c->aged, 2)) <= 0.005;
            $c->quiet = abs($c->billed) <= 0.004 && abs($c->received) <= 0.004;   // nothing billed, nothing paid
        });

        $tie = [
            'as_of' => $asOf,
            'list' => round((float) $companies->sum('outstanding'), 2),
            'ageing' => round((float) $aged->sum('outstanding'), 2),
            'rows' => $companies->count(),
            'over_limit' => $companies->filter(fn ($c) => $c->flag === 'OVER LIMIT')->count(),
            'drift' => $companies->reject(fn ($c) => $c->ties)->pluck('name')->values()->all(),
        ];
        $tie['diff'] = round($tie['list'] - $tie['ageing'], 2);
        $tie['ties'] = abs($tie['diff']) <= 0.005 && ! $tie['drift'];

        return view('accounts.cityledger', compact('companies', 'tie', 'asOf'));
    }

    /**
     * v8.9 Company receivables aged — the city ledger read as the month-end reads it: FIFO buckets per company, the
     * credit limit beside them, and every charge behind the figures. The AP ageing has shown its documents since v8.8;
     * a receivable nobody can open is the same problem as a payable nobody can open.
     */
    public function receivables(Request $request)
    {
        $asOf = $request->input('as_of', bdate()->toDateString());
        $asOf = substr((string) $asOf, 0, 10);
        if ($asOf > bdate()->toDateString()) {
            $asOf = bdate()->toDateString();   // the ledger has not been posted past the business date
        }
        $rows = ArService::ageing(Carbon::parse($asOf));
        $items = ArService::items(Carbon::parse($asOf));
        $keys = ['b0', 'b30', 'b60', 'b90', 'unapplied', 'outstanding', 'billed', 'received'];
        $tot = collect($keys)->mapWithKeys(fn ($k) => [$k => round((float) $rows->sum($k), 2)])->all();
        $counts = collect(array_keys(ArService::BUCKETS))->mapWithKeys(fn ($k) => [$k => (int) $rows->sum(fn ($r) => $r['counts'][$k])])->all();
        $overLimit = $rows->filter(fn ($r) => $r['flag'] === 'OVER LIMIT')->count();

        return view('accounts.receivables', compact('rows', 'tot', 'asOf', 'items', 'counts', 'overLimit'))
            ->with('buckets', ArService::BUCKETS)
            ->with('itemsTotal', round((float) $items->sum('amount'), 2))
            ->with('tie', LedgerService::control('city_ledger', $asOf));   // v9.0 the control account, on the screen that ages it
    }

    public function companyLedger(Request $request, Company $company)
    {
        // v9.7 a ledger is read as at a date: a charge dated after it has not happened yet, and a receipt dated after it
        // has not been banked. Without the cut, "closing balance" meant "closing balance including the future".
        $asOf = substr((string) $request->input('as_of', bdate()->toDateString()), 0, 10);
        if ($asOf > bdate()->toDateString()) {
            $asOf = bdate()->toDateString();   // the ledger has not been posted past the business date
        }
        $debits = FolioTransaction::with('folio.guest')->where('category', 'city_ledger')->where('company_id', $company->id)->where('is_void', false)
            ->whereDate('business_date', '<=', $asOf)->get()
            ->map(fn ($t) => ['date' => $t->business_date, 'due' => $t->due_date, 'ref' => $t->reference ?: ($t->folio->invoice_no ?? $t->folio->folio_no),
                'desc' => $t->source_type === 'pos_complimentary' ? $t->description : ($t->isOpening() ? $t->description : 'Bill – '.($t->folio->guest?->full_name)),
                'debit' => (float) $t->amount, 'credit' => 0, 'folio' => $t->folio]);
        $credits = Voucher::where('type', 'receipt')->where('company_id', $company->id)->whereDate('date', '<=', $asOf)->get()
            ->map(fn ($v) => ['date' => $v->date, 'due' => null, 'ref' => $v->voucher_no, 'desc' => 'Receipt '.($v->reference ? '#'.$v->reference : ''), 'debit' => 0, 'credit' => (float) $v->amount, 'folio' => null]);
        $rows = $debits->concat($credits)->sortBy(fn ($r) => $r['date']->format('Y-m-d'))->values();
        // v9.7 the running balance belongs with the rows, not in a counter inside the view: a ledger whose last row and
        // whose closing figure can disagree is exactly the kind of statement a supplier or a company will query
        $running = 0.0;
        $rows = $rows->map(function ($r) use (&$running) {
            $running = round($running + $r['debit'] - $r['credit'], 2);

            return $r + ['balance' => $running];
        });

        $paymentModes = PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id');
        $splitModes = PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'card', 'bank', 'wallet'])->orderBy('id')->pluck('name', 'id');

        // the ledger and the receivables ageing read the same charges and receipts and must land on the same balance:
        // the ageing reads them FIFO into buckets, the ledger adds them up. A difference is a defect, not a rounding.
        $closing = $running;   // the last row's balance, which is the sum of the columns by construction
        $aged = ArService::ageing(Carbon::parse($asOf))->firstWhere('company', $company->name);
        $tie = ['as_of' => $asOf, 'ledger' => $closing, 'ageing' => round((float) ($aged['outstanding'] ?? 0), 2)];
        $tie['diff'] = round($tie['ledger'] - $tie['ageing'], 2);
        $tie['ties'] = abs($tie['diff']) <= 0.005;

        return view('accounts.company', compact('company', 'rows', 'paymentModes', 'splitModes', 'tie', 'asOf', 'closing'));
    }

    public function companyReceipt(Request $request, Company $company)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01', 'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'date' => 'required|date', 'reference' => 'nullable|string|max:60',
            'payments' => 'nullable|array|min:2|max:10', 'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_mode_id' => 'required|integer|owned:payment_modes', 'payments.*.reference' => 'nullable|string|max:60',
            'payments.*.momo_network' => 'nullable|string|max:20', 'payments.*.momo_phone' => 'nullable|string|max:30',
        ]);
        $lines = array_values($data['payments'] ?? []);
        if ($lines && ((float) ($data['amount'] ?? 0) > 0 || ! empty($data['payment_mode_id']))) {
            throw ValidationException::withMessages(['payments' => 'Use either one receipt tender or split tender rows, not both.']);
        }
        if (! $lines) {
            if (empty($data['amount']) || empty($data['payment_mode_id'])) {
                throw ValidationException::withMessages(['payment_mode_id' => 'Enter a receipt amount and choose a payment mode, or add split tender rows.']);
            }
            $lines = [[
                'amount' => $data['amount'], 'payment_mode_id' => $data['payment_mode_id'], 'reference' => $data['reference'] ?? null,
            ]];
        }
        foreach ($lines as $index => &$line) {
            $mode = PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'card', 'bank', 'wallet'])
                ->find((int) ($line['payment_mode_id'] ?? 0));
            if (! $mode && count($lines) > 1) {
                throw ValidationException::withMessages(["payments.{$index}.payment_mode_id" => 'Split receipts require an active cash, card, bank or mobile-money mode.']);
            }
            if (filled($line['momo_network'] ?? null) || filled($line['momo_phone'] ?? null)) {
                $line['reference'] = mb_substr(trim(implode(' · ', array_filter([
                    $line['momo_network'] ?? null, $line['momo_phone'] ?? null, $line['reference'] ?? null,
                ]))), 0, 60);
            }
        }
        unset($line);
        $head = AccountHead::find(AccountHead::sys('city_ledger')); // receipts settle the receivable, not income
        $receipts = DB::transaction(function () use ($lines, $data, $company, $head) {
            $rows = collect();
            foreach ($lines as $line) {
                $amount = round((float) $line['amount'], 2);
                if ($amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Receipt tender amounts must be positive.']);
                }
                $mode = PaymentMode::find((int) ($line['payment_mode_id'] ?? 0));
                if (! $mode || ! $mode->is_active || in_array($mode->type, ['city_ledger', 'online', 'gift_voucher'], true)) {
                    throw ValidationException::withMessages(['payment_mode_id' => 'Choose an active direct payment mode for the receipt.']);
                }
                $rows->push(Voucher::create([
                    'voucher_no' => Numbering::next('voucher'), 'type' => 'receipt', 'date' => $data['date'],
                    'account_head_id' => $head->id, 'company_id' => $company->id, 'party_name' => $company->name,
                    'amount' => $amount, 'payment_mode_id' => $mode->id, 'reference' => $line['reference'] ?? null,
                    'narration' => 'Payment against city ledger', 'user_id' => auth()->id(),
                ]));
            }
            ActivityLogger::log('accounts', 'cl_receipt', 'City ledger receipt '.money($rows->sum('amount'))." from {$company->name} (".$rows->count().' tender(s))', $company);

            return $rows;
        });

        return back()->with('success', $receipts->count().' receipt tender(s) totalling '.money($receipts->sum('amount')).' recorded.');
    }

    /**
     * v9.9 The money that moved through the tills between two dates, from the three places it can move: the front
     * office (guest payments and refunds), the POS (settled takings paid at the counter) and the voucher register.
     *
     * It is one method rather than a query inside the screen because the cash book needs the same rows twice — once for
     * everything before the day, to open the book, and once for the day itself.
     */
    protected function cashMovements(?string $from, ?string $to)
    {
        $folioPay = FolioTransaction::with('folio.guest', 'paymentMode')->whereIn('type', ['payment', 'refund'])->where('category', '!=', 'city_ledger')->where('is_void', false)
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))->get();
        $pos = PosOrder::with('paymentMode', 'outlet')->where('status', 'settled')->where('settlement', 'payment')
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))->get();
        $vouchers = Voucher::with('head', 'paymentMode')
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))->get();

        return collect()
            ->concat($folioPay->map(fn ($t) => ['date' => $t->business_date, 'src' => 'Front Office', 'ref' => $t->folio->folio_no, 'desc' => $t->description.' – '.($t->folio->guest?->full_name), 'mode' => $t->paymentMode?->name ?? '—', 'in' => $t->type === 'payment' ? (float) $t->amount : 0, 'out' => $t->type === 'refund' ? (float) $t->amount : 0]))
            ->concat($pos->map(fn ($o) => ['date' => $o->business_date, 'src' => 'POS', 'ref' => $o->bill_no, 'desc' => $o->outlet->name, 'mode' => $o->paymentMode?->name ?? '—', 'in' => (float) $o->total, 'out' => 0]))
            ->concat($vouchers->map(fn ($v) => ['date' => $v->date, 'src' => 'Voucher', 'ref' => $v->voucher_no, 'desc' => $v->head->name.' '.$v->narration, 'mode' => $v->paymentMode?->name ?? '—', 'in' => $v->type === 'receipt' ? (float) $v->amount : 0, 'out' => $v->type === 'payment' ? (float) $v->amount : 0]))
            ->sortBy(fn ($r) => $r['date']?->format('Y-m-d') ?? '')->values();
    }

    /**
     * v9.9 The daily cash book, read as a book.
     *
     * It listed one day's transactions and labelled their net "collection", which is not a figure anybody can count a
     * drawer against: a cash book opens with what was already in the tills and closes with what is in them now. So the
     * page now carries a brought-forward per mode, the day's receipts and payments, a carried-forward per mode, and the
     * posting state of the day — the ledger is posted through yesterday, so today's collections have not reached it.
     * A mode that closes below zero is flagged: no drawer can pay out more than it held.
     */
    public function cashBook(Request $request)
    {
        $date = substr((string) $request->input('date', bdate()->toDateString()), 0, 10);
        if ($date > bdate()->toDateString()) {
            $date = bdate()->toDateString();   // a cash book cannot be read before the money is taken
        }
        $yesterday = Carbon::parse($date)->subDay()->toDateString();

        $opening = $this->cashMovements(null, $yesterday);
        $rows = $this->cashMovements($date, $date);

        // per mode: what it held, what came in, what went out, what it holds — the four columns of a cash book
        $modes = [];
        $blank = fn (string $mode) => ['mode' => $mode, 'open' => 0.0, 'in' => 0.0, 'out' => 0.0];
        foreach ($opening as $r) {
            $modes[$r['mode']] ??= $blank($r['mode']);
            $modes[$r['mode']]['open'] = round($modes[$r['mode']]['open'] + $r['in'] - $r['out'], 2);
        }
        foreach ($rows as $r) {
            $modes[$r['mode']] ??= $blank($r['mode']);
            $modes[$r['mode']]['in'] = round($modes[$r['mode']]['in'] + $r['in'], 2);
            $modes[$r['mode']]['out'] = round($modes[$r['mode']]['out'] + $r['out'], 2);
        }
        $modes = collect($modes)->map(function ($m) {
            $m['close'] = round($m['open'] + $m['in'] - $m['out'], 2);
            $m['over'] = $m['close'] < -0.005;   // a till cannot hold less than nothing

            return $m;
        })->sortBy(fn ($m) => ($m['mode'] === 'Cash' ? '0' : '1').$m['mode'])->values();

        $sum = fn (string $key) => round((float) $modes->sum($key), 2);
        $totals = ['open' => $sum('open'), 'in' => $sum('in'), 'out' => $sum('out'), 'close' => $sum('close')];

        // the day's movement by the place it came from, so the net can be traced rather than trusted
        $sources = $rows->groupBy('src')->map(fn ($g) => round((float) $g->sum('in') - (float) $g->sum('out'), 2));
        $sourcesNote = $sources->map(fn ($v, $k) => $k.' '.money($v, false))->implode(' · ');

        $drawn = $modes->where('over', true)->pluck('mode')->values()->all();
        $posting = ['posted_through' => LedgerService::cutoff(), 'posted' => $date <= LedgerService::cutoff(), 'as_of' => $date];

        return view('accounts.cashbook', compact('rows', 'date', 'modes', 'totals', 'sources', 'sourcesNote', 'drawn', 'posting'));
    }

    /**
     * The general ledger's own movement for a period, by the nature of the account it landed in.
     *
     * The P&L is built from documents — folio charges, POS takings, vouchers — and the ledger from posted journals. Two
     * readings of one period, so the statement is held to the ledger rather than to itself.
     */
    protected function glMovement(string $from, string $to, string $type): float
    {
        $sign = $type === 'income' ? 'credit - debit' : 'debit - credit';   // income is credit-natured, expense debit

        return round((float) GlLine::whereIn('account_head_id', AccountHead::where('type', $type)->select('id'))
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum(DB::raw($sign)), 2);
    }

    public function pnl(Request $request)
    {
        // v9.10 a period is a period: it cannot end in the future, and it cannot run backwards
        $from = substr((string) $request->input('from', bdate()->copy()->startOfMonth()->toDateString()), 0, 10);
        $to = substr((string) $request->input('to', bdate()->toDateString()), 0, 10);
        if ($to > bdate()->toDateString()) {
            $to = bdate()->toDateString();
        }
        if ($from > $to) {
            $from = $to;
        }
        $tx = FolioTransaction::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('is_void', false)->get();
        $charges = $tx->where('type', 'charge');
        $income = collect(config('pms.charge_categories'))->map(fn ($label, $cat) => $charges->where('category', $cat)->sum('amount'))->filter();
        $income['discounts'] = -$tx->whereIn('type', ['discount', 'allowance'])->sum('amount');
        $posDirect = PosOrder::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('status', 'settled')->where('settlement', 'payment')->get();
        $income['pos_direct'] = $posDirect->sum(fn ($o) => $o->total - $o->tax);
        $otherIncome = Voucher::with('head')->where('type', 'receipt')->whereNull('company_id')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->groupBy('head.name')->map->sum('amount');
        $expenses = Voucher::with('head')->where('type', 'payment')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()->groupBy('head.name')->map->sum('amount');
        $totalIncome = $income->sum() + $otherIncome->sum();
        $exp = $expenses->sum();

        $net = round($totalIncome - $exp, 2);
        // v9.10 the statement says whether the ledger posted the same period: same money, read from the documents here and
        // from the journals there. A difference is an unposted document or one posted outside income and expense.
        $tie = [
            'from' => $from, 'to' => $to,
            'revenue' => ['pnl' => round($totalIncome, 2), 'gl' => $this->glMovement($from, $to, 'income')],
            'expense' => ['pnl' => round($exp, 2), 'gl' => $this->glMovement($from, $to, 'expense')],
        ];
        $tie['revenue']['diff'] = round($tie['revenue']['pnl'] - $tie['revenue']['gl'], 2);
        $tie['expense']['diff'] = round($tie['expense']['pnl'] - $tie['expense']['gl'], 2);
        // which line is out, decided here: a view that compares three-deep array offsets inside @if is a view the
        // preview cannot read back, and a tie nobody can check is the thing this card exists to prevent
        $tie['revenue']['abs'] = abs($tie['revenue']['diff']);
        $tie['expense']['abs'] = abs($tie['expense']['diff']);
        $tie['out'] = ['revenue' => $tie['revenue']['abs'] > 0.005, 'expense' => $tie['expense']['abs'] > 0.005];
        $tie['ties'] = ! $tie['out']['revenue'] && ! $tie['out']['expense'];
        $tie['posted'] = $to <= LedgerService::cutoff();

        return view('accounts.pnl', compact('from', 'to', 'income', 'otherIncome', 'expenses', 'totalIncome', 'tie', 'net') + [
            'taxCollected' => $charges->sum('tax_amount') + $posDirect->sum('tax'),
            'exp' => round($exp, 2),
            'margin' => $totalIncome > 0 ? round($net / $totalIncome * 100, 1) : 0.0,
            // v9.10 the labels are the view's business no longer: the two lines the statement adds to the chart of
            // categories are merged here, where a missing category cannot silently print as its key
            'labels' => collect(config('pms.charge_categories'))
                ->put('discounts', 'Less: Discounts & allowances')->put('pos_direct', 'POS sales (direct settlement)')->all(),
        ]);
    }
}
