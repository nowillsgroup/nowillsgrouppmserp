<?php

namespace App\Http\Controllers;

use App\Models\ApAllocation;
use App\Models\PaymentMode;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\ApService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            ->when($request->boolean('overdue'), fn ($q) => $q->where('type', 'invoice')->whereIn('status', ['open', 'partial'])->whereDate('due_date', '<', $asOf))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('doc_no', 'like', "%{$s}%")->orWhere('supplier_ref', 'like', "%{$s}%")));
        $open = SupplierInvoice::where('type', 'invoice')->whereIn('status', ['open', 'partial']);

        return view('ap.bills', [
            'bills' => $q->latest('doc_date')->latest('id')->paginate(30)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'kpi' => [
                'outstanding' => (float) (clone $open)->sum('total') - (float) (clone $open)->sum('allocated'),
                'overdue' => (float) (clone $open)->whereDate('due_date', '<', $asOf)->get()->sum(fn ($b) => $b->outstanding()),
                'due7' => (float) (clone $open)->whereDate('due_date', '>=', $asOf)->whereDate('due_date', '<=', Carbon::parse($asOf)->addDays(7)->toDateString())->get()->sum(fn ($b) => $b->outstanding()),
                'hold' => SupplierInvoice::where('status', 'on_hold')->count(),
                'unbilled' => ApService::unbilledReceipts(),
            ],
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
        $bill->load('supplier', 'purchaseOrder.items.item', 'lines.head', 'allocations.payment.paymentMode', 'allocations.debitNote', 'applications.invoice', 'creator', 'approver', 'againstInvoice');
        $credits = collect();
        if (in_array($bill->status, ['open', 'partial'])) {
            $credits = SupplierPayment::where('supplier_id', $bill->supplier_id)->whereNull('cancelled_at')->whereColumn('allocated', '<', 'amount')->get()
                ->map(fn ($p) => ['key' => 'p'.$p->id, 'label' => "{$p->payment_no} · ".$p->date->format('d M').' · unapplied '.money($p->unapplied()), 'free' => $p->unapplied()])
                ->merge(SupplierInvoice::where('supplier_id', $bill->supplier_id)->where('type', 'debit_note')->whereIn('status', ['open', 'partial'])->get()
                    ->map(fn ($d) => ['key' => 'd'.$d->id, 'label' => "{$d->doc_no} · debit note · unapplied ".money($d->outstanding()), 'free' => $d->outstanding()]));
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

    public function payments(Request $request)
    {
        $payments = SupplierPayment::with('supplier', 'paymentMode')
            ->when($request->supplier, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($request->from, fn ($q, $d) => $q->whereDate('date', '>=', $d))->when($request->to, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('payment_no', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('date')->latest('id')->paginate(30)->withQueryString();

        return view('ap.payments', ['payments' => $payments, 'suppliers' => Supplier::orderBy('name')->get(['id', 'name'])]);
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
        ]);
    }

    public function payStore(Request $request)
    {
        $d = $request->validate([
            'supplier_id' => 'required|owned:suppliers', 'date' => 'required|date', 'payment_mode_id' => 'required|owned:payment_modes',
            'amount' => 'required|numeric|min:0.01|max:99999999', 'reference' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:300',
            'alloc' => 'nullable|array', 'alloc.*' => 'nullable|numeric|min:0',
            'wht_skip' => 'nullable|boolean', 'wht_reason' => 'nullable|string|max:200',
        ]);

        return $this->attempt(fn () => ApService::pay(Supplier::findOrFail($d['supplier_id']), $d, $d['alloc'] ?? []),
            fn ($p) => redirect()->route('ap.payments.show', $p)->with('success', "Payment {$p->payment_no} recorded".($p->unapplied() > 0 ? ' – '.money($p->unapplied()).' held as supplier credit.' : '.')
                .($p->withheld() > 0 ? ' '.money($p->withheld())." withheld for GRA (certificate {$p->wht_cert_no}); pay the supplier ".money($p->netPaid()).'.' : '')));
    }

    public function payShow(SupplierPayment $spay)
    {
        $spay->load('supplier', 'paymentMode', 'allocations.invoice', 'creator');
        $view = request()->boolean('print') ? 'ap.remittance' : 'ap.payment-show';

        return view($view, ['p' => $spay, 'journals' => \App\Models\GlJournal::where('source_type', 'ap_pay')->where('source_key', (string) $spay->id)->orderBy('id')->get()]);
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

        return view('ap.ageing', compact('rows', 'tot', 'asOf'));
    }

    public function statement(Request $request, Supplier $supplier)
    {
        $to = $request->input('to', bdate()->toDateString());
        $from = $request->input('from', Carbon::parse($to)->subDays(89)->toDateString());
        $st = ApService::statement($supplier, $from, $to);
        $age = ApService::ageing($to, $supplier->id)->first();

        return view($request->boolean('print') ? 'ap.statement-print' : 'ap.statement', compact('supplier', 'from', 'to', 'st', 'age'));
    }
}
