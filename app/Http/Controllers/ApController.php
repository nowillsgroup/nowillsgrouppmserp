<?php

namespace App\Http\Controllers;

use App\Models\ApAllocation;
use App\Models\PaymentMode;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\ApService;
use App\Services\ArService;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Accounts payable: supplier bills, debit notes, payments, allocation, ageing and statements. */
class ApController extends Controller
{
    protected function attempt(callable $fn, ?callable $redirect = null)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $redirect ? $redirect($r) : back();
    }

    // ───────────── bills ─────────────

    public function bills(Request $request)
    {
        $asOf = bdate()->toDateString();
        $q = SupplierInvoice::with('supplier', 'purchaseOrder')
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->supplier, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($request->status, fn ($q, $s) => $s === 'unpaid' ? $q->whereIn('status', ['open', 'partial']) : $q->where('status', $s))
            // "overdue only" is the same test the band uses: a bill falls due on its due date, or its document date when
            // it has none — the list and the 155,700 have to be the same bills
            ->when($request->boolean('overdue'), fn ($q) => $q->where('type', 'invoice')->whereIn('status', ['open', 'partial'])
                ->whereDate(DB::raw('COALESCE(due_date, doc_date)'), '<', $asOf))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('doc_no', 'like', "%{$s}%")->orWhere('supplier_ref', 'like', "%{$s}%")));
        // v9.1 the figures come from the same banding rule the ageing uses, so the two screens cannot disagree: a bill
        // with no due date is overdue on both, and goods received but not billed are counted on both
        $due = ApService::dueStats($asOf);

        return view('ap.bills', [
            'bills' => $q->latest('doc_date')->latest('id')->paginate(30)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'kpi' => array_merge($due, [
                'outstanding' => $due['open_bills'],   // the balance of the bills themselves, which is what this list holds
                'hold' => $due['counts']['hold'],      // the bills held for a variance, which the tile links to
                'unbilled' => ApService::unbilledReceipts(),   // the receipts behind the unbilled value, as documents
            ]),
        ]);
    }

    public function create(Request $request)
    {
        $po = $request->po ? PurchaseOrder::with('supplier', 'items.item')->findOrFail($request->po) : null;
        $supplier = $po?->supplier ?? ($request->supplier ? Supplier::findOrFail($request->supplier) : null);
        $mode = in_array($request->mode, ['goods', 'expense', 'debit_note']) ? $request->mode
            : ($po ? 'goods' : ($request->type === 'debit_note' ? 'debit_note' : 'expense'));

        return view('ap.bill-create', [
            'po' => $po, 'supplier' => $supplier, 'mode' => $mode,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'heads' => ApService::chargeableHeads(),
            'receipts' => ApService::unbilledReceipts()->filter(fn ($p) => $p->billable() && ! $p->bill()->exists()),
            'againstOptions' => $supplier ? SupplierInvoice::where('supplier_id', $supplier->id)->where('type', 'invoice')->whereIn('status', ['open', 'partial', 'paid'])->latest('doc_date')->get() : collect(),
            'tolerance' => $po ? ApService::tolerance($po->receivedValue() ?: (float) $po->total) : null,
            // v7.53 the payables side of the forex board: which currencies can be billed in, and at what rate today
            'home' => strtoupper(trim((string) (hotel()?->currency ?: 'GHS'))),
            'fxOn' => \App\Services\ForexService::apConvert(),
            'fxBoard' => \App\Services\ForexService::apBoard(),
            'fxPolicy' => \App\Services\ForexService::apPolicy(),
            'fxRateUrl' => route('ap.bills.fx-rate'),
        ]);
    }

    /** Invoice-date Forex Desk quote and GRN comparison for the matched foreign-currency bill form. */
    public function fxRate(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id' => 'required|integer|owned:purchase_orders',
            'currency' => 'required|string|size:3', 'date' => 'required|date',
        ]);
        $po = PurchaseOrder::findOrFail($data['purchase_order_id']);
        $currency = strtoupper(trim($data['currency']));
        if (! $po->isForeign() || strtoupper((string) $po->currency) !== $currency) {
            return response()->json(['message' => 'The bill currency must match the purchase order currency.'], 422);
        }
        if (! \App\Services\ForexService::apConvert()) {
            return response()->json(['message' => 'Foreign-currency matched bills are switched off under Foreign Guests › Settings › Forex desk.'], 422);
        }
        $policy = \App\Services\ForexService::apPolicy($data['date']);
        $board = \App\Services\ForexService::boardRate($currency, $data['date'], ['basis' => $policy['basis'], 'stale_days' => $policy['stale_days']]);
        if (! $board) {
            return response()->json(['message' => "There is no {$currency} rate on the forex board for {$data['date']}, and none in the {$policy['stale_days']} days before it."], 422);
        }
        [$fxSub, $fxTax] = $po->receivedForeignSplit();
        $atInvoiceDate = round(round($fxSub * (float) $board['rate'], 2) + round($fxTax * (float) $board['rate'], 2), 2);

        return response()->json($board + [
            'grn_fx_total' => round($fxSub + $fxTax, 2), 'grn_local' => $po->receivedValue(),
            'grn_at_invoice_date' => $atInvoiceDate, 'fx_movement' => round($atInvoiceDate - $po->receivedValue(), 2),
            'tolerance' => ApService::tolerance($atInvoiceDate),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'mode' => 'required|in:goods,expense,debit_note',
            'purchase_order_id' => 'required_if:mode,goods|nullable|owned:purchase_orders',
            'supplier_id' => 'required_unless:mode,goods|nullable|owned:suppliers',
            'against_invoice_id' => 'nullable|owned:supplier_invoices',
            'supplier_ref' => 'required_unless:mode,debit_note|nullable|string|max:60',
            'doc_date' => 'required|date', 'due_date' => 'nullable|date|after_or_equal:doc_date',
            'subtotal' => 'required_if:mode,goods|nullable|numeric|min:0.01|max:99999999',
            'tax' => 'nullable|numeric|min:0|max:99999999',
            'notes' => 'nullable|string|max:500',
            'budget_reason' => 'nullable|string|max:255',
            'currency' => 'nullable|string|size:3',   // v7.53 the supplier's own currency; blank is the property's
            'lines' => 'required_unless:mode,goods|array|max:30',
            'lines.*.account_head_id' => 'nullable|owned:account_heads',
            'lines.*.description' => 'nullable|string|max:200',
            'lines.*.amount' => 'nullable|numeric|min:0|max:99999999',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        return $this->attempt(fn () => match ($data['mode']) {
            'goods' => ApService::billFromReceipt(PurchaseOrder::findOrFail($data['purchase_order_id']), $data),
            'expense' => ApService::expenseBill(Supplier::findOrFail($data['supplier_id']), $data),
            'debit_note' => ApService::debitNote(Supplier::findOrFail($data['supplier_id']), $data),
        }, function ($doc) {
            if ($doc->isForeign()) {   // v7.53 say what the bill was booked at, and when the board was not today's
                $r = redirect()->route('ap.bills.show', $doc)
                    ->with($doc->fxStale() ? 'warning' : 'success', "{$doc->doc_no} saved: {$doc->fxLabel()} at "
                        .number_format((float) $doc->fx_rate, 4)." {$doc->fx_basis}, board of {$doc->fx_date->format('d M Y')} = ".money($doc->total)
                        .($doc->fxStale() ? '. The board for the invoice date had no rate, so the most recent one within the window was used.' : '.'));
                if ($doc->status === 'on_hold') {
                    $r->with('warning', "{$doc->doc_no} is ON HOLD: the invoice differs from the goods receipt by ".money($doc->variance()).'.');
                }

                return $r;
            }
            $r = redirect()->route('ap.bills.show', $doc)->with($doc->status === 'on_hold' ? 'warning' : 'success',
                $doc->status === 'on_hold' ? "{$doc->doc_no} saved but ON HOLD: the invoice differs from the goods receipt by ".money($doc->variance()).'. It needs approval before it can be paid.' : "{$doc->doc_no} saved.");
            if ($doc->status !== 'on_hold' && \App\Services\BudgetService::$lastWarnings) {
                $r->with('warning', 'Over budget: '.\App\Services\BudgetService::describe(\App\Services\BudgetService::$lastWarnings).'.');   // v3.6 spend control
            }

            return $r;
        });
    }

    public function show(SupplierInvoice $bill)
    {
        $bill->load('supplier', 'purchaseOrder.items.item', 'purchaseOrder.receipts.lines.item', 'lines.head', 'allocations.payment.paymentMode', 'allocations.debitNote', 'applications.invoice', 'creator', 'approver', 'againstInvoice');
        $credits = collect();
        if (in_array($bill->status, ['open', 'partial'])) {
            // v9.4 the credits on offer here are the credit rule's own documents — the set the ageing nets off, the bills
            // list takes off what is payable and the payments screen lists — narrowed to this bill's currency, because
            // applying a credit across currencies is refused and an option that throws is not an offer
            $currency = $bill->isForeign() ? strtoupper((string) $bill->currency) : strtoupper((string) (hotel()?->currency ?: 'GHS'));
            $credits = ApService::openCredits((int) $bill->supplier_id, null, $currency)->map(fn ($c) => ['key' => $c['key'], 'free' => $c['free'],
                'label' => $c['kind'] === 'payment'
                    ? "{$c['doc']} · ".Carbon::parse($c['date'])->format('d M').' · unapplied '.money($c['free'])
                    : "{$c['doc']} · debit note · unapplied ".money($c['free'])]);
        }
        $journals = \App\Models\GlJournal::where('source_type', 'ap_inv')->where('source_key', (string) $bill->id)->orderBy('id')->get();
        $grnJournal = $bill->purchase_order_id ? \App\Models\GlJournal::where('source_type', 'po')->where('source_key', (string) $bill->purchase_order_id)->whereNull('reversed_by_id')->first() : null;

        return view('ap.bill-show', compact('bill', 'credits', 'journals', 'grnJournal'));
    }

    public function approve(Request $request, SupplierInvoice $bill)
    {
        $note = $request->validate(['note' => 'nullable|string|max:200'])['note'] ?? null;

        return $this->attempt(fn () => ApService::approve($bill, $note), fn () => back()->with('success', "{$bill->doc_no} approved and released for payment."));
    }

    public function cancel(Request $request, SupplierInvoice $bill)
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'])['reason'];

        return $this->attempt(fn () => ApService::cancel($bill, $reason), fn () => back()->with('success', "{$bill->doc_no} cancelled."));
    }

    public function apply(Request $request, SupplierInvoice $bill)
    {
        $d = $request->validate(['source' => ['required', 'regex:/^[pd]\d+$/'], 'amount' => 'required|numeric|min:0.01']);
        $id = (int) substr($d['source'], 1);
        $p = $d['source'][0] === 'p' ? SupplierPayment::findOrFail($id) : null;
        $dn = $d['source'][0] === 'd' ? SupplierInvoice::findOrFail($id) : null;

        return $this->attempt(fn () => ApService::applyCredit($bill, (float) $d['amount'], $p, $dn), fn () => back()->with('success', 'Credit applied.'));
    }

    public function unallocate(ApAllocation $alloc)
    {
        return $this->attempt(fn () => ApService::unallocate($alloc), fn () => back()->with('success', 'Allocation removed – the amount is back as unapplied credit.'));
    }

    // ───────────── payments ─────────────

    /**
     * v9.15 Supplier payments, read as money that has left the bank and has not yet reached a bill.
     *
     * The screen printed the unapplied total as a figure with no age, so a payment made last week and one made in July
     * looked the same, and the two things a payment can get wrong were invisible: the withheld tax it says it paid GRA
     * without a certificate to prove it, and an allocated column that no longer agrees with the allocations behind it.
     * The ageing is built from ApService::openCredits() — the same collection the KPI is summed from, so the card and the
     * header cannot disagree — and both records are read back over the payments themselves.
     */
    public function payments(Request $request)
    {
        $asOf = bdate()->toDateString();
        $today = Carbon::parse($asOf)->startOfDay();
        $ageOf = fn ($d) => $d ? max(0, (int) round(($today->timestamp - Carbon::parse($d)->startOfDay()->timestamp) / 86400)) : 0;
        $payments = SupplierPayment::with('supplier', 'paymentMode')
            ->when($request->supplier, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($request->from, fn ($q, $d) => $q->whereDate('date', '>=', $d))->when($request->to, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            // v9.2 "unapplied" is the credit rule, not "amount differs from allocated": a foreign payment's remainder is
            // an exchange difference and a cancelled payment is not a credit, so neither belongs on this tab
            ->when($request->boolean('unapplied'), fn ($q) => $q->whereNull('cancelled_at')
                ->whereColumn('allocated', '<', 'amount')
                ->where(fn ($w) => $w->whereNull('currency')->orWhere('currency', '')
                    ->orWhereRaw('UPPER(TRIM(currency)) = ?', [strtoupper((string) (hotel()?->currency ?: 'GHS'))])))
            ->when($request->boolean('cancelled'), fn ($q) => $q->whereNotNull('cancelled_at'))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('payment_no', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('date')->latest('id')->paginate(30)->withQueryString();
        $payments->getCollection()->transform(function ($p) use ($ageOf) {
            $p->days = $ageOf($p->date);
            $p->bucket = $p->unapplied() > 0.004 ? ArService::bucketOf($p->days) : null;

            return $p;
        });

        // the supplier's money still on our books, aged from the day it left the bank, on the receivables' 30/60/90 rule
        $free = ApService::openCredits(null, $asOf)->where('kind', 'payment')
            ->map(fn ($c) => $c + ['days' => $ageOf($c['date']), 'bucket' => ArService::bucketOf($ageOf($c['date']))])->values();
        $oldest = $free->sortByDesc('days')->first();
        $aged = ['total' => round((float) $free->sum('free'), 2), 'count' => $free->count(), 'oldest' => (int) ($free->max('days') ?? 0),
            'worst' => $oldest ? ['doc' => $oldest['doc'], 'supplier' => $oldest['supplier']?->name, 'free' => (float) $oldest['free'], 'days' => (int) $oldest['days']] : null,
            'beyond' => $free->where('bucket', 'b90')->count(), 'beyond_amount' => round((float) $free->where('bucket', 'b90')->sum('free'), 2)];
        foreach (array_keys(ArService::BUCKETS) as $b) {
            $aged[$b] = round((float) $free->where('bucket', $b)->sum('free'), 2);
            $aged['n'.$b] = $free->where('bucket', $b)->count();
        }

        // v9.15 the certificate: tax withheld is a GRA liability the supplier claims back only against a certificate
        $taxed = SupplierPayment::with('supplier')->whereNull('cancelled_at')->where('wht_amount', '>', 0)->orderByDesc('date')->orderByDesc('id')->get();
        $uncertified = $taxed->filter(fn ($p) => trim((string) $p->wht_cert_no) === '')->values();
        $cert = ['payments' => $taxed->count(), 'amount' => round((float) $taxed->sum('wht_amount'), 2),
            'certified' => $taxed->count() - $uncertified->count(), 'certified_amount' => round((float) $taxed->sum('wht_amount') - (float) $uncertified->sum('wht_amount'), 2),
            'missing' => $uncertified->count(), 'missing_amount' => round((float) $uncertified->sum('wht_amount'), 2),
            'missing_rows' => $uncertified->take(10)->map(fn ($p) => ['payment_no' => $p->payment_no, 'supplier' => $p->supplier?->name,
                'date' => $p->date, 'days' => $ageOf($p->date), 'amount' => round((float) $p->wht_amount, 2), 'gross' => round((float) $p->amount, 2)])->all()];

        // and the tie: the allocated column must be the allocations on file, or the ageing is resting on a stale summary
        $onFile = ApAllocation::selectRaw('supplier_payment_id, SUM(amount) a')->groupBy('supplier_payment_id')->pluck('a', 'supplier_payment_id');
        $applied = SupplierPayment::with('supplier')->whereNull('cancelled_at')->where('allocated', '>', 0)->get()
            ->map(fn ($p) => ['payment' => $p, 'on_file' => round((float) ($onFile[$p->id] ?? 0), 2)]);
        $breaks = $applied->filter(fn ($r) => abs((float) $r['payment']->allocated - $r['on_file']) > 0.005)->values();
        $alloc = ['payments' => $applied->count(), 'allocated' => round((float) $applied->sum(fn ($r) => (float) $r['payment']->allocated), 2),
            'on_file' => round((float) $applied->sum('on_file'), 2), 'breaks' => $breaks->count(),
            'diff' => round((float) $applied->sum(fn ($r) => (float) $r['payment']->allocated) - (float) $applied->sum('on_file'), 2),
            'rows' => $breaks->take(10)->map(fn ($r) => ['payment_no' => $r['payment']->payment_no, 'supplier' => $r['payment']->supplier?->name,
                'date' => $r['payment']->date, 'allocated' => round((float) $r['payment']->allocated, 2), 'on_file' => $r['on_file']])->all()];

        return view('ap.payments', ['payments' => $payments, 'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'kpi' => ApService::paymentStats($asOf), 'as_of' => $asOf, 'aged' => $aged, 'cert' => $cert, 'alloc' => $alloc,
            'buckets' => ArService::BUCKETS]);
    }

    /** v7.54 the payment-date board quote for the live foreign-payment form. */
    public function payFxRate(Request $request)
    {
        $d = $request->validate(['currency' => 'required|string|size:3', 'date' => 'required|date']);
        $q = \App\Services\ForexService::apPayRate(strtoupper($d['currency']), $d['date']);

        return response()->json(['enabled' => \App\Services\ForexService::apPay(),
            'deal_allowed' => \App\Services\ForexService::apPayDeal(),
            'rate' => $q['rate'] ?? null, 'basis' => $q['basis'] ?? null, 'date' => $q['date'] ?? null,
            'stale' => (bool) ($q['stale'] ?? false), 'carried' => (bool) ($q['carried'] ?? false),
            'board_rate' => $q['board']['rate'] ?? ($q['rate'] ?? null), 'board_date' => $q['board']['date'] ?? ($q['date'] ?? null)]);
    }

    public function payCreate(Request $request)
    {
        $supplier = $request->supplier ? Supplier::findOrFail($request->supplier) : null;

        return view('ap.pay', [
            'supplier' => $supplier,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get()->map(fn ($s) => $s->setAttribute('due', ApService::openBills($s->id)->sum(fn ($b) => $b->outstanding()))),
            'bills' => $supplier ? ApService::openBills($supplier->id) : collect(),
            'held' => $supplier ? SupplierInvoice::where('supplier_id', $supplier->id)->where('status', 'on_hold')->get() : collect(),
            'unbilled' => $supplier ? ApService::unbilledReceipts($supplier->id) : collect(),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->orderBy('id')->get(),
            'preselect' => array_map('intval', (array) $request->input('bills', [])),
            'whtOn' => \App\Services\WhtService::enabled(), // v4.4
            // v7.54 paying a foreign bill in the currency it was billed in
            'fxOn' => \App\Services\ForexService::apPay(),
            'fxDeal' => \App\Services\ForexService::apPayDeal(),
            'fxPay' => $supplier ? ApService::payCurrencies($supplier) : [],
            'fxPolicy' => \App\Services\ForexService::apPolicy(),
        ]);
    }

    public function payStore(Request $request)
    {
        $d = $request->validate([
            'supplier_id' => 'required|owned:suppliers', 'date' => 'required|date', 'payment_mode_id' => 'required|owned:payment_modes',
            // v7.54 nullable, not required: a payment in the supplier's own currency derives its cedi amount from the
            // foreign figure and the rate, and ApService::pay() still refuses a cedi payment with no amount on it
            'amount' => 'nullable|numeric|min:0|max:99999999', 'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:300',
            'alloc' => 'nullable|array', 'alloc.*' => 'nullable|numeric|min:0',
            'wht_skip' => 'nullable|boolean', 'wht_reason' => 'nullable|string|max:200',
            'currency' => 'nullable|string|size:3',          // v7.54 blank is the property's own currency
            'fx_amount' => 'nullable|numeric|min:0|max:99999999', 'fx_rate' => 'nullable|numeric|min:0.0001|max:9999', 'fx_deal' => 'nullable|boolean',
        ]);

        return $this->attempt(fn () => ApService::pay(Supplier::findOrFail($d['supplier_id']), $d, $d['alloc'] ?? []),
            function ($p) {
                if ($p->isForeign()) {   // v7.54 say what the bank took, at what rate, and what the move cost
                    $diff = $p->fxDifference();

                    return redirect()->route('ap.payments.show', $p)->with('success', "Payment {$p->payment_no} recorded: {$p->fxLabel()} at "
                        .number_format((float) $p->fx_rate, 4).' ('.($p->fx_basis === 'deal' ? "the bank's deal rate" : $p->fx_basis.' board of '.$p->fx_date->format('d M Y')).') = '.money($p->amount)
                        .', settling '.money($p->allocated).' of bills — '
                        .($diff != 0.0 ? money(abs($diff)).' realised exchange '.($diff > 0 ? 'loss' : 'gain').' posted to Exchange Gain / Loss.' : 'no exchange difference.')
                        .($p->withheld() > 0 ? ' '.money($p->withheld())." withheld for GRA (certificate {$p->wht_cert_no})." : ''));
                }

                return redirect()->route('ap.payments.show', $p)->with('success', "Payment {$p->payment_no} recorded".($p->unapplied() > 0 ? ' – '.money($p->unapplied()).' held as supplier credit.' : '.')
                    .($p->withheld() > 0 ? ' '.money($p->withheld())." withheld for GRA (certificate {$p->wht_cert_no}); pay the supplier ".money($p->netPaid()).'.' : ''));
            });
    }

    public function payShow(SupplierPayment $spay)
    {
        $spay->load('supplier', 'paymentMode', 'allocations.invoice', 'creator');
        $view = request()->boolean('print') ? 'ap.remittance' : 'ap.payment-show';
        $asOf = bdate()->toDateString();
        // v9.5 what is left of this payment is only a credit if the credit rule says so — and the clerk's question after
        // a part payment is exactly "is this money on the ageing?" — so ask openCredits() rather than deciding again here
        $open = ApService::openCredits((int) $spay->supplier_id, $asOf)->firstWhere('key', 'p'.$spay->id);
        $credit = ['as_of' => $asOf, 'in_credit' => $open !== null, 'free' => (float) ($open['free'] ?? 0),
            'supplier_credits' => ApService::creditSources($asOf, (int) $spay->supplier_id)['credits']];

        return view($view, ['p' => $spay, 'credit' => $credit,
            'journals' => \App\Models\GlJournal::where('source_type', 'ap_pay')->where('source_key', (string) $spay->id)->orderBy('id')->get()]);
    }

    public function payCancel(Request $request, SupplierPayment $spay)
    {
        $reason = $request->validate(['reason' => 'required|string|max:200'])['reason'];

        return $this->attempt(fn () => ApService::cancelPayment($spay, $reason), fn () => back()->with('success', "{$spay->payment_no} cancelled; its bills are open again."));
    }

    // ───────────── ageing & statement ─────────────

    public function ageing(Request $request)
    {
        $asOf = $request->input('as_of', bdate()->toDateString());
        $rows = ApService::ageing($asOf);
        $keys = ['current', 'd30', 'd60', 'd90', 'd90p', 'unbilled', 'credit', 'balance'];
        $tot = collect($keys)->mapWithKeys(fn ($k) => [$k => round($rows->sum($k), 2)])->all();
        $counts = collect(array_keys(ApService::BUCKETS))->mapWithKeys(fn ($k) => [$k => (int) $rows->sum(fn ($r) => $r['counts'][$k])])->all();
        // v8.8 the documents behind each bucket, and the proof that they add up to the balance printed above them
        $items = ApService::agedItems($asOf);

        return view('ap.ageing', compact('rows', 'tot', 'asOf', 'items', 'counts'))
            ->with('buckets', ApService::BUCKETS)
            ->with('itemsTotal', round((float) $items->sum('amount'), 2))
            ->with('tie', LedgerService::control('accounts_payable', $asOf))   // v9.0 the control account, on the screen that ages it
            ->with('due', ApService::dueStats($asOf))   // v9.1 the same figures the bills screen prints
            ->with('credits', ApService::creditSources($asOf))   // v9.2 what the credit column is made of, and where the payments are
            ->with('fx', ApService::fxExposure($asOf));   // v7.53 open foreign-currency bills at today's board
    }

    public function statement(Request $request, Supplier $supplier)
    {
        $to = $request->input('to', bdate()->toDateString());
        $from = $request->input('from', Carbon::parse($to)->subDays(89)->toDateString());
        $st = ApService::statement($supplier, $from, $to);
        $age = ApService::ageing($to, $supplier->id)->first();
        // v9.3 the statement and the ageing are two readings of one supplier's balance, built from the same documents
        // by different routes; a difference is a defect to explain, not a rounding to wave through
        $balance = round((float) ($age['balance'] ?? 0), 2);
        $tie = ['as_of' => $to, 'statement' => round((float) $st['closing'], 2), 'ageing' => $balance,
            'diff' => round((float) $st['closing'] - $balance, 2)];
        $tie['ties'] = abs($tie['diff']) <= 0.005;

        return view($request->boolean('print') ? 'ap.statement-print' : 'ap.statement', compact('supplier', 'from', 'to', 'st', 'age', 'tie'));
    }
}
