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
use App\Models\SupplierInvoice;
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

    /**
     * v9.27: the register of the only document where a price is decided by competition.
     *
     * It could say how many requests were sent and how many quotes came back. It could not say whether anybody answered
     * — an invited supplier who opened the link and never replied is not the same as one who declined — whether the
     * deadline had run out with no award made, or what the competition actually bought. Those three readings come from
     * three different records: the invitation tokens, the deadline dates, and the award on each line.
     */
    public function rfqs(Request $request)
    {
        $status = $request->status;
        $rfqs = Rfq::with('store', 'creator')->withCount(['invites', 'quotes', 'lines'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', Rfq::OPEN), fn ($q) => $q->when($status, fn ($w, $s) => $w->where('status', $s)))
            ->latest('id')->paginate(25)->withQueryString();

        $min = ProcurementService::minQuotes();

        /* ── what came back: every invitation on every request still asking for an answer ── */
        $live = Rfq::with('invites.supplier')->withCount('quotes')->whereIn('status', ['sent', 'closed'])->get();
        $invites = $live->flatMap(fn ($r) => $r->invites->map(fn ($i) => ['rfq' => $r, 'invite' => $i]));
        $declined = $invites->filter(fn ($r) => $r['invite']->declined_at && ! $r['invite']->responded_at)->values();
        $silent = $invites->filter(fn ($r) => ! $r['invite']->responded_at && ! $r['invite']->declined_at && $r['invite']->viewed_at)->values();
        $ignored = $invites->filter(fn ($r) => ! $r['invite']->responded_at && ! $r['invite']->declined_at && ! $r['invite']->viewed_at)->values();
        $quoted = (int) $live->sum('quotes_count');
        $response = [
            'requests' => $live->count(), 'invited' => $invites->count(), 'quoted' => $quoted,
            'rate' => $invites->count() ? round($quoted / $invites->count() * 100, 1) : 0.0,
            'declined' => $declined->count(), 'silent' => $silent->count(), 'ignored' => $ignored->count(),
            'rows' => $silent->take(6)->map(fn ($r) => ['rfq_no' => $r['rfq']->rfq_no, 'rfq_id' => $r['rfq']->id,
                'supplier' => $r['invite']->supplier?->name, 'viewed' => $r['invite']->viewed_at,
                'deadline' => $r['rfq']->respond_by])->all(),
            'declined_rows' => $declined->take(4)->map(fn ($r) => ['supplier' => $r['invite']->supplier?->name,
                'reason' => $r['invite']->decline_reason, 'rfq_no' => $r['rfq']->rfq_no])->all(),
        ];
        $response['sub'] = $response['invited']
            ? $response['quoted'].' of '.$response['invited'].' invited suppliers answered — '.$response['rate'].'%'
            : 'no supplier has been invited to quote';

        /* ── the clock: deadlines that ran out with no award made ── */
        // the same definition the sidebar badge and the alert use, reloaded with the counts the card prints
        $ready = ProcurementService::readyToAward()->pluck('id');
        $ready = Rfq::withCount(['quotes', 'invites'])->whereIn('id', $ready)->orderBy('respond_by')->get();
        $oldest = $ready->first();
        $clock = [
            'ready' => $ready->count(), 'min' => $min,
            'oldest_days' => $oldest ? (int) $oldest->respond_by->diffInDays(bdate()) : 0,
            'oldest' => $oldest,
            'short' => $ready->filter(fn ($r) => $r->quotes_count < $min)->count(),
            'noquotes' => $ready->filter(fn ($r) => $r->quotes_count === 0)->count(),
            'closing' => Rfq::where('status', 'sent')->whereDate('respond_by', '>=', bdate()->toDateString())
                ->whereDate('respond_by', '<=', bdate()->copy()->addDays(2)->toDateString())->count(),
            'rows' => $ready->take(6)->map(fn ($r) => [
                'id' => $r->id, 'rfq_no' => $r->rfq_no, 'title' => $r->title, 'quotes' => $r->quotes_count,
                'invites' => (int) $r->invites_count, 'deadline' => $r->respond_by,
                'days' => (int) $r->respond_by->diffInDays(bdate()), 'short' => $r->quotes_count < $min,
            ])->all(),
        ];
        $clock['sub'] = $clock['ready']
            ? $clock['ready'].' request'.($clock['ready'] === 1 ? '' : 's').' waiting on an award, the oldest '.$clock['oldest_days'].' days'
            : 'nothing is waiting on an award decision';

        /* ── what the competition bought, on the awards made this month ── */
        $awarded = Rfq::with('lines.item', 'lines.awardedLine', 'quotes.lines', 'awarder', 'purchaseOrders.supplier')
            ->where('status', 'awarded')->whereDate('awarded_at', '>=', bdate()->startOfMonth()->toDateString())->get();
        $placed = 0.0; $cheapest = 0.0; $dearest = 0.0; $extra = 0.0; $away = 0; $justified = 0;
        $awardRows = [];
        foreach ($awarded as $r) {
            $cmp = ProcurementService::comparative($r);
            $rfqPlaced = 0.0; $rfqCheapest = 0.0; $rfqDearest = 0.0; $rfqAway = 0; $rfqExtra = 0.0;
            foreach ($cmp['rows'] as $row) {
                $line = $row['line'];
                $qty = (float) $line->qty;
                $bids = collect($row['cells'])->filter()->map(fn ($c) => (float) $c['landed']);
                if ($bids->isNotEmpty()) {
                    $rfqCheapest += round($qty * (float) $bids->min(), 2);
                    $rfqDearest += round($qty * (float) $bids->max(), 2);
                }
                $ql = $line->awardedLine;
                if (! $ql) {
                    continue;
                }
                $amount = round($qty * $ql->landed(), 2);
                $rfqPlaced += $amount;
                if ($row['lowest'] !== null && (int) $row['lowest'] !== (int) $ql->id) {
                    $rfqAway++;
                    $rfqExtra += round($amount - $qty * (float) $row['lowestLanded'], 2);
                    if (filled($line->award_reason)) {
                        $justified++;
                    }
                }
            }
            $placed += $rfqPlaced; $cheapest += $rfqCheapest; $dearest += $rfqDearest; $extra += $rfqExtra; $away += $rfqAway;
            $awardRows[] = ['id' => $r->id, 'rfq_no' => $r->rfq_no, 'title' => $r->title,
                'supplier' => $r->purchaseOrders->first()?->supplier?->name, 'awarded_at' => $r->awarded_at,
                'placed' => round($rfqPlaced, 2), 'cheapest' => round($rfqCheapest, 2), 'dearest' => round($rfqDearest, 2),
                'saved' => round($rfqDearest - $rfqPlaced, 2), 'away' => $rfqAway, 'extra' => round($rfqExtra, 2),
                'quotes' => $r->quotes?->count() ?? 0];
        }
        $award = ['count' => $awarded->count(), 'placed' => round($placed, 2), 'cheapest' => round($cheapest, 2),
            'dearest' => round($dearest, 2), 'saved' => round($dearest - $placed, 2), 'away' => $away,
            'extra' => round($extra, 2), 'justified' => $justified, 'rows' => $awardRows];
        $award['away_sub'] = $award['away']
            ? $award['away'].' line'.($award['away'] === 1 ? '' : 's').' went to a supplier who was not the lowest bidder — '
                .($award['justified'] === $award['away'] ? 'each one carries a written justification'
                    : ($award['justified'] === 0 ? 'none of them carries a written justification' : $award['justified'].' of them carry a written justification'))
                .', and together they cost '.money($award['extra']).' more than the lowest bid'
            : 'every line went to the lowest bidder';
        $award['sub'] = $award['count']
            ? money($award['saved']).' under the dearest bid, on '.money($award['placed']).' placed'
            : 'no award has been made this month';
        $award['tone'] = ! $award['count'] ? 'slate' : ($award['justified'] < $award['away'] ? 'red' : ($award['away'] ? 'amber' : 'green'));

        return view('procurement.rfqs', ['rfqs' => $rfqs, 'status' => $status, 'ready' => $ready,
            'counts' => Rfq::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'response' => $response, 'clock' => $clock, 'award' => $award, 'min' => $min]);
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

    /**
     * v9.28: the screen where the award is made.
     *
     * The comparative statement already ranks the bids on landed price, line by line, which is the only fair way to
     * compare them. What it cannot say is whether the bid it ranks first can actually be taken: a quote that arrives
     * after the date the store needs the goods, or whose validity ran out while the request sat waiting, is not cheaper
     * than the next one — it is simply not available. So each quote is read on three axes it does not print of its own
     * accord — the date it lands by, the terms it asks for, and the day its price stops being an offer — and set against
     * the rates the property last paid for the same lines.
     */
    public function show(Rfq $rfq)
    {
        $rfq->load('lines.item', 'invites.supplier', 'store', 'creator', 'awarder', 'purchaseOrders.supplier', 'lines.awardedLine.quote.supplier');
        $cmp = ProcurementService::comparative($rfq);
        $today = bdate();
        $needed = $rfq->needed_by;

        /* what each supplier is promising, beside what each is charging */
        $promise = $cmp['quotes']->map(function ($q) use ($rfq, $cmp, $today, $needed) {
            $days = $q->delivery_days === null ? null : (int) $q->delivery_days;
            $arrives = $days === null ? null : $today->copy()->addDays($days);
            $lateDays = ($arrives && $needed && $arrives->toDateString() > $needed->toDateString())
                ? (int) round((strtotime($arrives->toDateString()) - strtotime($needed->toDateString())) / 86400) : 0;
            $score = $cmp['scores'][$q->supplier_id] ?? null;

            return [
                'id' => $q->id, 'supplier' => $q->supplier?->name, 'total' => round((float) $q->total, 2),
                'lines' => $q->lines->count(), 'of' => $rfq->lines->count(),
                'partial' => $q->lines->contains(fn ($l) => $l->qty_offered !== null),
                'delivery_days' => $days, 'arrives' => $arrives, 'late' => $lateDays > 0, 'late_days' => $lateDays,
                'terms_days' => $q->payment_terms_days === null ? null : (int) $q->payment_terms_days,
                'valid_until' => $q->valid_until,
                'expired' => $q->valid_until && $q->valid_until->toDateString() < $today->toDateString(),
                'expires_in' => $q->valid_until ? (int) round((strtotime($q->valid_until->toDateString()) - strtotime($today->toDateString())) / 86400) : null,
                'grade' => $score['grade'] ?? null, 'score' => $score['score'] ?? null,
            ];
        })->values();

        $late = $promise->filter(fn ($p) => $p['late'])->values();
        $expired = $promise->filter(fn ($p) => $p['expired'])->values();
        $usable = $promise->reject(fn ($p) => $p['late'] || $p['expired'])->values();

        /* what the request is worth: the rates last paid for the same lines, and the saving the statement computes */
        $atStake = 0.0;
        foreach ($cmp['rows'] as $row) {
            if ($row['last'] !== null) {
                $atStake += round((float) $row['last'] * (float) $row['line']->qty, 2);
            }
        }
        $head = [
            'quotes' => $cmp['quotes']->count(), 'invited' => $rfq->invites->count(),
            'low' => $cmp['lowTotal'], 'lowest' => $cmp['quotes']->first(),
            'at_stake' => round($atStake, 2), 'saving' => round((float) collect($cmp['rows'])->sum('saving'), 2),
            'late' => $late->count(), 'expired' => $expired->count(),
            'pick' => $usable->first(), 'worst' => $usable->last(),
            'days_left' => $needed ? (int) round((strtotime($needed->toDateString()) - strtotime($today->toDateString())) / 86400) : null,
        ];
        /* written here rather than in the attribute: a + and a ternary in one :sub is beyond the renderer's expression subset */
        $blocked = collect([
            $head['late'] ? $head['late'].' arrive'.($head['late'] === 1 ? 's' : '').' after the store needs it' : null,
            $head['expired'] ? $head['expired'].' quote'.($head['expired'] === 1 ? '' : 's').' expired before the award' : null,
        ])->filter()->implode(' · ');
        $head['blocked_sub'] = $blocked !== '' ? $blocked : 'every bid can still be taken';
        $head['blocked_tone'] = $head['late'] + $head['expired'] ? 'red' : 'green';
        $head['needed_tone'] = $head['days_left'] !== null && $head['days_left'] < 0 ? 'red' : 'slate';
        $head['needed_sub'] = $head['days_left'] === null
            ? 'the store has not given a date'
            : ($head['days_left'] < 0
                ? abs($head['days_left']).' days past — the goods are already late'
                : $head['days_left'].' day'.($head['days_left'] === 1 ? '' : 's').' left for the goods to arrive');
        $head['sub'] = $rfq->invites->count()
            ? $head['quotes'].' of '.$rfq->invites->count().' invited suppliers quoted'
            : 'no supplier has been invited to quote';

        /* the verdict: the cheapest bid is not the answer if it cannot be delivered */
        if (! $head['quotes']) {
            $head['verdict'] = 'No quotation has been received, so there is nothing to compare yet.';
            $head['tone'] = 'slate';
        } elseif ($head['pick'] && $head['lowest'] && $head['pick']['id'] !== $head['lowest']->id) {
            $cheapest = $promise->firstWhere('id', $head['lowest']->id);
            $head['verdict'] = 'The cheapest bid is '.$cheapest['supplier'].' at '.money($cheapest['total']).', but it '
                .($cheapest['late'] ? 'lands '.$cheapest['late_days'].' day'.($cheapest['late_days'] === 1 ? '' : 's').' after the store needs it' : 'is no longer valid')
                .'. '.$head['pick']['supplier'].' is '.money($head['pick']['total'] - $cheapest['total']).' dearer and can be delivered on time.';
            $head['tone'] = 'amber';
        } else {
            $head['verdict'] = $head['lowest']
                ? 'The cheapest bid can also be delivered: '.$promise->firstWhere('id', $head['lowest']->id)['supplier'].' at '.money($head['lowest']->total).', landing before the store needs it.'
                : 'No quotation can be delivered by the date the store needs it.';
            $head['tone'] = 'green';
        }

        return view('procurement.rfq-show', ['rfq' => $rfq, 'cmp' => $cmp, 'minQuotes' => ProcurementService::minQuotes(),
            'others' => Supplier::where('is_active', true)->whereNotIn('id', $rfq->invites->pluck('supplier_id'))->orderBy('name')->pluck('name', 'id'),
            'promise' => $promise, 'head' => $head, 'openStatuses' => $rfq::OPEN]);
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
        $rows = ProcurementService::scorecard($from, $to)->map(function ($r) {
            $pc = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%';

            return $r + ['on_time_label' => $pc($r['on_time']), 'fill_label' => $pc($r['fill']),
                'quality_label' => $pc($r['quality']), 'price_label' => $pc($r['price']), 'response_label' => $pc($r['response']),
                'tone' => ['A' => 'green', 'B' => 'blue', 'C' => 'amber', 'D' => 'red'][$r['grade']] ?? 'slate'];
        });

        // v9.19 the score is one number; the three readings behind it are the ones that can be argued with
        $grns = GoodsReceipt::with('order', 'supplier')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();
        $lateRows = $grns->map(function ($g) {
            $exp = $g->order?->expected_date;

            return ['supplier' => $g->supplier?->name ?? '#'.$g->supplier_id, 'grn' => $g->grn_no, 'po' => $g->order?->po_no,
                'expected' => $exp, 'arrived' => $g->date,
                'days' => $exp ? max(0, (int) round(($g->date->timestamp - $exp->timestamp) / 86400)) : 0,
                'value' => round((float) $g->total, 2), 'rejected' => round((float) $g->rejected_value, 2)];
        })->filter(fn ($r) => $r['days'] > 0)->sortByDesc('days')->values();
        $leadDays = $grns->map(fn ($g) => $g->order ? $g->order->po_date->diffInDays($g->date) : null)->filter(fn ($v) => $v !== null);
        $delivery = ['count' => $grns->count(), 'late' => $lateRows->count(), 'late_value' => round((float) $lateRows->sum('value'), 2),
            'value' => round((float) $grns->sum('total'), 2), 'worst' => $lateRows->first(), 'rows' => $lateRows->take(10)->all(),
            'lead' => $leadDays->count() ? round((float) $leadDays->avg(), 1) : null,
            'rejected' => round((float) $grns->sum('rejected_value'), 2)];

        // the money: what the shelf took in during the period, what was billed for it, and what is still owed
        $bills = SupplierInvoice::where('type', 'invoice')->where('status', '!=', 'cancelled')
            ->whereDate('doc_date', '>=', $from)->whereDate('doc_date', '<=', $to)->get();
        $received = $grns->groupBy('supplier_id')->map(fn ($g) => round((float) $g->sum('total'), 2));
        $billed = $bills->groupBy('supplier_id')->map(fn ($b) => round((float) $b->sum('total'), 2));
        $open = SupplierInvoice::where('type', 'invoice')->whereIn('status', ['open', 'partial', 'on_hold'])->get();
        $unpaidBy = $open->groupBy('supplier_id')->map(fn ($b) => [
            'value' => round((float) $b->sum(fn ($i) => $i->outstanding()), 2),
            'oldest_days' => (int) ($b->max(fn ($i) => $i->daysOverdue($to)) ?? 0),
            'count' => $b->count()]);
        $moneyIds = collect([$received->keys(), $billed->keys(), $unpaidBy->keys()])->flatten()->unique();
        $moneyNames = Supplier::whereIn('id', $moneyIds)->pluck('name', 'id');
        $moneyRows = $moneyIds->map(fn ($sid) => ['supplier' => $moneyNames[$sid] ?? '#'.$sid,
            'received' => $received[$sid] ?? 0.0, 'billed' => $billed[$sid] ?? 0.0,
            'diff' => round(($billed[$sid] ?? 0) - ($received[$sid] ?? 0), 2),
            'unpaid' => $unpaidBy[$sid]['value'] ?? 0.0, 'oldest_days' => $unpaidBy[$sid]['oldest_days'] ?? 0,
            'open_bills' => $unpaidBy[$sid]['count'] ?? 0])->sortByDesc(fn ($r) => abs($r['diff']))->values();
        $money = ['rows' => $moneyRows->take(10)->all(), 'count' => $moneyRows->count(),
            'received' => round((float) $moneyRows->sum('received'), 2), 'billed' => round((float) $moneyRows->sum('billed'), 2),
            'diff' => round((float) $moneyRows->sum(fn ($r) => $r['diff']), 2),
            'gaps' => $moneyRows->filter(fn ($r) => abs($r['diff']) > 0.005)->count(),
            'worst' => $moneyRows->sortByDesc(fn ($r) => abs($r['diff']))->first(),
            'unpaid' => round((float) $moneyRows->sum('unpaid'), 2), 'open_bills' => (int) $moneyRows->sum('open_bills'),
            'oldest' => $moneyRows->sortByDesc('oldest_days')->first()];

        // the bid: an invitation nobody answered is the only part of the response rate that costs anything
        $invites = RfqSupplier::with('supplier', 'rfq')->whereNotNull('invited_at')
            ->whereBetween('invited_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $quotes = RfqQuote::whereIn('rfq_id', $invites->pluck('rfq_id')->unique())->get()
            ->keyBy(fn ($q) => $q->rfq_id.'|'.$q->supplier_id);
        $bidRows = $invites->groupBy('supplier_id')->map(function ($set, $sid) use ($quotes) {
            $answered = $set->filter(fn ($i) => $i->responded_at)->count();
            $today = bdate()->toDateString();

            return ['supplier' => $set->first()->supplier?->name ?? '#'.$sid, 'invited' => $set->count(), 'answered' => $answered,
                'declined' => $set->filter(fn ($i) => $i->declined_at && ! $i->responded_at)->count(),
                'silent' => $set->filter(fn ($i) => ! $i->responded_at && ! $i->declined_at
                    && (! $i->rfq?->respond_by || $i->rfq->respond_by->toDateString() < $today))->count(),
                'quoted' => round((float) $set->sum(fn ($i) => (float) ($quotes[$i->rfq_id.'|'.$i->supplier_id]->total ?? 0)), 2),
                'rate' => $set->count() ? round($answered / $set->count() * 100, 1) : null];
        })->sortByDesc('invited')->values();
        $bid = ['rows' => $bidRows->take(10)->all(), 'count' => $bidRows->count(),
            'invited' => (int) $bidRows->sum('invited'), 'answered' => (int) $bidRows->sum('answered'),
            'silent' => (int) $bidRows->sum('silent'), 'quoted' => round((float) $bidRows->sum('quoted'), 2),
            'rate' => $bidRows->sum('invited') ? round($bidRows->sum('answered') / $bidRows->sum('invited') * 100, 1) : null,
            'worst' => $bidRows->sortByDesc('silent')->first(),
            'above' => (int) $rows->sum('above_list'), 'priced' => (int) $rows->sum('priced_lines')];

        $bid['answered_sub'] = $bid['rate'].' % of the invitations, and '.money($bid['quoted']).' quoted';
        $delivery['late_sub'] = ($delivery['count'] === 1 ? '1 delivery' : $delivery['count'].' deliveries').' · '.money($delivery['late_value']).' arrived after the date';
        $owedDays = (int) ($money['oldest']['oldest_days'] ?? 0);
        $money['owed_sub'] = ($money['open_bills'] === 1 ? '1 open bill' : $money['open_bills'].' open bills')
            .' · oldest '.$owedDays.' day'.($owedDays === 1 ? '' : 's').' past its date';

        $scored = $rows->pluck('score')->filter(fn ($v) => $v !== null);
        $header = ['suppliers' => $rows->count(), 'average' => $scored->count() ? round((float) $scored->avg(), 1) : null,
            'grades' => collect(['A', 'B', 'C', 'D'])->mapWithKeys(fn ($g) => [$g => $rows->where('grade', $g)->count()])->all(),
            'value' => round((float) $rows->sum('value'), 2)];

        return view('procurement.scorecard', ['rows' => $rows, 'from' => $from, 'to' => $to,
            'delivery' => $delivery, 'money' => $money, 'bid' => $bid, 'header' => $header]);
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
