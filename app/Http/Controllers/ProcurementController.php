<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Models\RfqSupplier;
use App\Models\Store;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPrice;
use App\Services\ProcurementService;
use Illuminate\Http\Request;
use RuntimeException;

/** v4.8 Procurement II: RFQs & quotations, comparative statement & award, price lists, goods receipts, supplier scorecard. */
class ProcurementController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', $ok);
    }

    // ─────────────── RFQs ───────────────

    public function rfqs(Request $request)
    {
        $status = $request->status;
        $rfqs = Rfq::with('store', 'creator')->withCount(['invites', 'quotes', 'lines'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', Rfq::OPEN), fn ($q) => $q->when($status, fn ($w, $s) => $w->where('status', $s)))
            ->latest('id')->paginate(25)->withQueryString();

        return view('procurement.rfqs', ['rfqs' => $rfqs, 'status' => $status, 'ready' => ProcurementService::readyToAward(),
            'counts' => Rfq::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')]);
    }

    public function create(Request $request)
    {
        $prefill = [];
        if ($request->filled('items')) { // from re-order suggestions: items[id]=qty
            foreach ((array) $request->items as $id => $qty) {
                $prefill[] = ['item_id' => (int) $id, 'qty' => (float) $qty, 'specification' => ''];
            }
        }

        return $this->form(new Rfq(['respond_by' => bdate()->copy()->addDays(3), 'store_id' => Store::value('id')]), $prefill);
    }

    public function edit(Rfq $rfq)
    {
        abort_unless($rfq->status === 'draft', 403, 'Only a draft RFQ can be edited.');

        return $this->form($rfq, $rfq->lines->map(fn ($l) => ['item_id' => $l->inventory_item_id, 'qty' => (float) $l->qty, 'specification' => $l->specification])->all());
    }

    protected function form(Rfq $rfq, array $lines)
    {
        return view('procurement.rfq-form', ['rfq' => $rfq, 'lines' => $lines, 'items' => ProcurementService::items(), 'stores' => Store::pluck('name', 'id'),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'phone']),
            'invited' => $rfq->exists ? $rfq->invites->pluck('supplier_id')->all() : []]);
    }

    protected function rules(): array
    {
        return ['title' => 'required|string|max:150', 'store_id' => 'required|integer|owned:stores', 'respond_by' => 'required|date', 'needed_by' => 'nullable|date',
            'terms' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:500', 'supplier_ids' => 'required|array|min:1', 'supplier_ids.*' => 'integer|owned:suppliers',
            'lines' => 'required|array|min:1', 'lines.*.item_id' => 'nullable|integer|owned:inventory_items', 'lines.*.qty' => 'nullable|numeric|min:0', 'lines.*.specification' => 'nullable|string|max:255'];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        return $this->attempt(function () use ($data, $request) {
            $rfq = ProcurementService::saveRfq($data);
            if ($request->boolean('send_now')) {
                $r = ProcurementService::send($rfq);

                return redirect()->route('rfq.show', $rfq)->with('success', "{$rfq->rfq_no} sent – {$r['emailed']} supplier(s) e-mailed".($r['manual'] ? ", {$r['manual']} without e-mail: share the link or print the RFQ." : '.'));
            }

            return redirect()->route('rfq.show', $rfq)->with('success', "{$rfq->rfq_no} saved as draft.");
        }, '');
    }

    public function update(Request $request, Rfq $rfq)
    {
        $data = $request->validate($this->rules());

        return $this->attempt(fn () => redirect()->route('rfq.show', ProcurementService::saveRfq($data, $rfq))->with('success', 'RFQ updated.'), '');
    }

    public function show(Rfq $rfq)
    {
        $rfq->load('lines.item', 'invites.supplier', 'store', 'creator', 'awarder', 'purchaseOrders.supplier', 'lines.awardedLine.quote.supplier');
        $cmp = ProcurementService::comparative($rfq);

        return view('procurement.rfq-show', ['rfq' => $rfq, 'cmp' => $cmp, 'minQuotes' => ProcurementService::minQuotes(),
            'others' => Supplier::where('is_active', true)->whereNotIn('id', $rfq->invites->pluck('supplier_id'))->orderBy('name')->pluck('name', 'id')]);
    }

    public function send(Rfq $rfq)
    {
        return $this->attempt(function () use ($rfq) {
            $r = ProcurementService::send($rfq);

            return back()->with('success', "RFQ sent – {$r['emailed']} supplier(s) e-mailed".($r['manual'] ? ", {$r['manual']} without e-mail: copy their link or print the RFQ." : '.'));
        }, '');
    }

    public function addSupplier(Request $request, Rfq $rfq)
    {
        $data = $request->validate(['supplier_id' => 'required|integer|owned:suppliers']);

        return $this->attempt(fn () => ProcurementService::addSupplier($rfq, (int) $data['supplier_id']), 'Supplier added to the RFQ.');
    }

    public function resend(Rfq $rfq, RfqSupplier $invite)
    {
        abort_unless($invite->rfq_id === $rfq->id, 404);
        abort_unless($rfq->status === 'sent', 422);

        return back()->with(ProcurementService::deliver($rfq, $invite->load('supplier')) ? 'success' : 'error',
            $invite->supplier->email ? ($invite->email_status === 'sent' ? 'Invitation e-mailed again.' : 'The e-mail could not be sent – see the e-mail log.') : 'This supplier has no e-mail address – share the link instead.');
    }

    public function close(Rfq $rfq)
    {
        return $this->attempt(fn () => ProcurementService::close($rfq), 'Quotations closed – compare and award below.');
    }

    public function cancel(Request $request, Rfq $rfq)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);

        return $this->attempt(fn () => ProcurementService::cancel($rfq, $data['reason']), 'RFQ cancelled.');
    }

    /** Buyer keys in a quotation received on paper / by e-mail / by phone. */
    public function quoteForm(Rfq $rfq, RfqSupplier $invite)
    {
        abort_unless($invite->rfq_id === $rfq->id, 404);
        $quote = RfqQuote::with('lines')->where('rfq_id', $rfq->id)->where('supplier_id', $invite->supplier_id)->first();

        return view('procurement.quote-form', ['rfq' => $rfq->load('lines.item'), 'invite' => $invite->load('supplier'), 'quote' => $quote]);
    }

    public function saveQuote(Request $request, Rfq $rfq, RfqSupplier $invite)
    {
        abort_unless($invite->rfq_id === $rfq->id, 404);
        $data = $request->validate(self::quoteRules());

        return $this->attempt(fn () => redirect()->route('rfq.show', $rfq)->with('success', 'Quotation from '.$invite->supplier->name.' recorded: '.money(ProcurementService::submitQuote($invite, $data, 'manual')->total).'.'), '');
    }

    public static function quoteRules(): array
    {
        return ['quote_ref' => 'nullable|string|max:60', 'valid_until' => 'nullable|date', 'delivery_days' => 'nullable|integer|min:0|max:365',
            'payment_terms_days' => 'nullable|integer|min:0|max:365', 'notes' => 'nullable|string|max:1000', 'lines' => 'required|array',
            'lines.*.rate' => 'nullable|numeric|min:0|max:99999999', 'lines.*.tax_rate' => 'nullable|numeric|min:0|max:50', 'lines.*.qty_offered' => 'nullable|numeric|min:0', 'lines.*.remarks' => 'nullable|string|max:255'];
    }

    public function award(Request $request, Rfq $rfq)
    {
        $data = $request->validate(['award' => 'required|array', 'award.*' => 'nullable|integer', 'reason' => 'nullable|array', 'reason.*' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:500', 'update_prices' => 'nullable|boolean']);

        return $this->attempt(function () use ($rfq, $data, $request) {
            $pos = ProcurementService::award($rfq, $data['award'], $data['reason'] ?? [], $data['note'] ?? null, $request->boolean('update_prices'));

            return redirect()->route('rfq.show', $rfq)->with('success', 'Awarded – draft purchase order(s) '.$pos->pluck('po_no')->join(', ').' raised for approval.');
        }, '');
    }

    public function printRfq(Rfq $rfq, Request $request)
    {
        $rfq->load('lines.item', 'store', 'creator', 'invites.supplier');
        $invite = $request->supplier ? $rfq->invites->firstWhere('supplier_id', (int) $request->supplier) : null;

        return view('procurement.rfq-print', compact('rfq', 'invite'));
    }

    public function printComparative(Rfq $rfq)
    {
        $rfq->load('lines.item', 'store', 'creator', 'awarder', 'invites.supplier');

        return view('procurement.comparative-print', ['rfq' => $rfq, 'cmp' => ProcurementService::comparative($rfq)]);
    }

    // ─────────────── price lists ───────────────

    public function prices(Request $request)
    {
        $on = bdate()->toDateString();
        $prices = SupplierPrice::with('supplier', 'item')
            ->when($request->supplier, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($request->item, fn ($q, $i) => $q->where('inventory_item_id', $i))
            ->when($request->state === 'active' || ! $request->state, fn ($q) => $q->validOn($on))
            ->when($request->state === 'expired', fn ($q) => $q->whereDate('valid_to', '<', $on))
            ->when($request->state === 'future', fn ($q) => $q->whereDate('valid_from', '>', $on))
            ->join('inventory_items', 'inventory_items.id', '=', 'supplier_prices.inventory_item_id')->orderBy('inventory_items.name')->orderBy('supplier_prices.rate')
            ->select('supplier_prices.*')->paginate(50)->withQueryString();
        $expiring = SupplierPrice::whereNotNull('valid_to')->whereDate('valid_to', '>=', $on)->whereDate('valid_to', '<=', bdate()->copy()->addDays(14)->toDateString())->count();

        return view('procurement.prices', ['prices' => $prices, 'suppliers' => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'items' => InventoryItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'last_cost']), 'expiring' => $expiring,
            'best' => SupplierPrice::validOn($on)->where('min_qty', 0)->selectRaw('inventory_item_id, MIN(rate) r')->groupBy('inventory_item_id')->pluck('r', 'inventory_item_id')]);
    }

    public function savePrice(Request $request, ?SupplierPrice $sprice = null)
    {
        $data = $request->validate(['supplier_id' => 'required|integer|owned:suppliers', 'inventory_item_id' => 'required|integer|owned:inventory_items',
            'rate' => 'required|numeric|min:0.01', 'min_qty' => 'nullable|numeric|min:0', 'lead_days' => 'nullable|integer|min:0|max:365',
            'valid_from' => 'required|date', 'valid_to' => 'nullable|date', 'notes' => 'nullable|string|max:255']);

        return $this->attempt(fn () => ProcurementService::savePrice($data, $sprice?->exists ? $sprice : null), 'Price saved.');
    }

    public function expirePrice(SupplierPrice $sprice)
    {
        $end = bdate()->copy()->subDay()->toDateString();
        $sprice->update(['valid_to' => max($end, $sprice->valid_from->toDateString())]);

        return back()->with('success', 'Price ended.');
    }

    // ─────────────── goods receipts & scorecard ───────────────

    /**
     * v9.16 Goods received, and whether the money behind them has reached the ledger.
     *
     * A goods receipt note is a claim on Accounts Payable from the moment the goods are accepted: the supplier will
     * invoice it, and ApService::unbilledReceipts() already asks that question at purchase-order level for the bill
     * screen. The register could say what arrived, but not whether that value had reached a bill, whether the delivery
     * came when its PO said it would, or whether the accepted goods had actually landed in stock. Each of those is read
     * back from the documents here rather than restated: the bill is the PO's own bill relation (the same one the
     * unbilled list tests), lateness is the PO's expected date against the receipt date, and stock is the movement the
     * receipt wrote, matched by its GRN number.
     */
    public function grns(Request $request)
    {
        $from = $request->from ?: bdate()->copy()->startOfMonth()->toDateString();
        $to = $request->to ?: bdate()->toDateString();
        $asOf = bdate()->toDateString();
        $today = bdate()->startOfDay();
        $ageOf = fn ($d) => $d ? max(0, (int) round(($today->timestamp - \Carbon\Carbon::parse($d)->startOfDay()->timestamp) / 86400)) : 0;
        // a bill is only a bill once it is on the ledger: a draft has not been posted, and a cancelled one is off it again
        $billed = fn ($q) => $q->whereIn('status', ['open', 'partial', 'paid']);

        $grns = GoodsReceipt::with('order.bill', 'supplier', 'store', 'receiver')->withSum('lines as rejected_qty', 'qty_rejected')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->when($request->supplier, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($request->boolean('rejections'), fn ($q) => $q->where('rejected_value', '>', 0))
            ->when($request->boolean('waiting'), fn ($q) => $q->whereHas('order', fn ($w) => $w->whereDoesntHave('bill', $billed)))
            ->latest('date')->latest('id')->paginate(30)->withQueryString();

        // what the shelf actually received, per GRN, at the rate the line was booked at (qty × rate, the net goods value)
        $moved = StockMovement::whereIn('reference', $grns->getCollection()->pluck('grn_no'))->where('type', 'purchase')
            ->selectRaw('reference, SUM(qty * rate) v')->groupBy('reference')->pluck('v', 'reference');
        $grns->getCollection()->transform(function ($g) use ($moved, $ageOf, $billed) {
            $g->bill = $g->order?->bill;
            $g->waiting_days = $g->bill ? null : $ageOf($g->date);
            $g->stock_value = round((float) ($moved[$g->grn_no] ?? 0), 2);
            $g->stocked = abs($g->stock_value - (float) $g->subtotal) <= 0.005;

            return $g;
        });

        // 1. the bill: every receipt on record, not only this window — an unbilled delivery must not vanish when the month turns
        $stuck = GoodsReceipt::with('supplier', 'store')->where('subtotal', '>', 0)
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('bill', $billed))->orderBy('date')->orderBy('id')->get()
            ->map(fn ($g) => ['grn_no' => $g->grn_no, 'supplier' => $g->supplier?->name, 'store' => $g->store?->name,
                'date' => $g->date, 'days' => $ageOf($g->date), 'value' => round((float) $g->subtotal, 2)])->values();
        // and the other side of it, for the day nothing is waiting: how long the receipts that did reach a bill had to wait
        $settled = GoodsReceipt::with('order.bill', 'supplier')->where('subtotal', '>', 0)
            ->whereDate('date', '>=', bdate()->copy()->subDays(90)->toDateString())
            ->whereHas('order', fn ($q) => $q->whereHas('bill', $billed))->get()
            ->map(function ($g) {
                $bill = $g->order?->bill;
                $wait = $bill?->doc_date ? max(0, (int) round((\Carbon\Carbon::parse($bill->doc_date)->startOfDay()->timestamp
                    - \Carbon\Carbon::parse($g->date)->startOfDay()->timestamp) / 86400)) : null;

                return ['grn_no' => $g->grn_no, 'supplier' => $g->supplier?->name, 'date' => $g->date,
                    'value' => round((float) $g->subtotal, 2), 'bill' => $bill?->doc_no, 'bill_date' => $bill?->doc_date, 'wait' => $wait];
            });
        $slowest = $settled->whereNotNull('wait')->sortByDesc('wait')->first();
        $oldest = $stuck->sortByDesc('days')->first();
        $billing = ['waiting' => $stuck->count(), 'waiting_value' => round((float) $stuck->sum('value'), 2),
            'oldest' => (int) ($stuck->max('days') ?? 0), 'oldest_row' => $oldest,
            'billed' => $settled->count(), 'billed_value' => round((float) $settled->sum('value'), 2), 'slowest' => $slowest,
            'rows' => $stuck->sortByDesc('days')->take(10)->values()->all()];

        // 2. the delivery: did it come when the purchase order said it would? measured on the goods value, not the tax
        $period = GoodsReceipt::with('order', 'supplier', 'store')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($request->supplier, fn ($q, $s) => $q->where('supplier_id', $s))->where('subtotal', '>', 0)->get();
        $lateRows = $period->reject(fn ($g) => $g->onTime())->map(function ($g) {
            $exp = $g->order?->expected_date;

            return ['grn_no' => $g->grn_no, 'supplier' => $g->supplier?->name, 'date' => $g->date, 'expected' => $exp,
                'days' => $exp ? max(0, (int) round((\Carbon\Carbon::parse($g->date)->startOfDay()->timestamp
                    - \Carbon\Carbon::parse($exp)->startOfDay()->timestamp) / 86400)) : 0,
                'value' => round((float) $g->subtotal, 2)];
        })->sortByDesc('days')->values();
        $goods = round((float) $period->sum('subtotal'), 2);
        $late = ['receipts' => $period->count(), 'value' => $goods,
            'late' => $lateRows->count(), 'late_value' => round((float) $lateRows->sum('value'), 2),
            'share' => $goods > 0 ? (int) round(100 * $lateRows->sum('value') / $goods) : 0,
            'worst' => $lateRows->first(), 'rows' => $lateRows->take(10)->all()];

        // 3. the shelf: the accepted lines must be the stock movements the receipt wrote, GRN by GRN
        $movedPeriod = StockMovement::whereIn('reference', $period->pluck('grn_no'))->where('type', 'purchase')
            ->selectRaw('reference, SUM(qty * rate) v')->groupBy('reference')->pluck('v', 'reference');
        $stockRows = $period->map(fn ($g) => ['grn_no' => $g->grn_no, 'supplier' => $g->supplier?->name, 'store' => $g->store?->name,
            'date' => $g->date, 'value' => round((float) $g->subtotal, 2),
            'moved' => round((float) ($movedPeriod[$g->grn_no] ?? 0), 2)])
            ->map(fn ($r) => $r + ['diff' => round($r['value'] - $r['moved'], 2)])->values();
        $gaps = $stockRows->filter(fn ($r) => abs($r['diff']) > 0.005)->values();
        $stock = ['receipts' => $stockRows->count(), 'goods' => round((float) $stockRows->sum('value'), 2),
            'moved' => round((float) $stockRows->sum('moved'), 2), 'diff' => round((float) $stockRows->sum('diff'), 2),
            'breaks' => $gaps->count(), 'rows' => $gaps->take(10)->all(),
            'largest' => $stockRows->sortByDesc('value')->first()];

        $overdue = ProcurementService::overdueDeliveries();
        $late['overdue'] = $overdue->count();

        return view('procurement.grns', ['grns' => $grns, 'from' => $from, 'to' => $to, 'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
            'overdue' => $overdue, 'as_of' => $asOf, 'billing' => $billing, 'late' => $late, 'stock' => $stock,
            'tot' => GoodsReceipt::whereBetween('date', [$from, $to])->selectRaw('COUNT(*) n, SUM(total) v, SUM(rejected_value) r')->first()]);
    }

    public function scorecard(Request $request)
    {
        $from = $request->from ?: bdate()->copy()->subMonths(6)->startOfMonth()->toDateString();
        $to = $request->to ?: bdate()->toDateString();

        return view('procurement.scorecard', ['rows' => ProcurementService::scorecard($from, $to), 'from' => $from, 'to' => $to]);
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['po_limit_1' => 'required|numeric|min:0', 'po_limit_2' => 'required|numeric|gte:po_limit_1', 'po_price_tolerance_pct' => 'required|numeric|min:0|max:50',
            'po_over_receipt_pct' => 'required|numeric|min:0|max:50', 'rfq_min_quotes' => 'required|integer|min:1|max:10', 'po_segregation' => 'nullable|boolean']);
        $data['po_segregation'] = $request->boolean('po_segregation') ? '1' : '0';
        foreach ($data as $k => $v) {
            \App\Models\Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        \App\Services\ActivityLogger::log('purchasing', 'settings', 'Purchasing controls updated: L1 '.money($data['po_limit_1']).', L2 '.money($data['po_limit_2']).', segregation '.($data['po_segregation'] === '1' ? 'on' : 'off'));

        return back()->with('success', 'Purchasing controls saved.');
    }
}
