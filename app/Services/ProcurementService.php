<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Models\RfqQuoteLine;
use App\Models\RfqSupplier;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPrice;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v4.8 Procurement II – price lists, RFQ → quotations → comparative statement → split award → POs,
 * the value-based PO approval matrix, partial goods receipts (GRN) with rejections and short-closing,
 * and the supplier scorecard built from all of it.
 */
class ProcurementService
{
    // ─────────────────────────── settings ───────────────────────────

    /** [level-1 limit, level-2 limit]: up to L1 the store (inventory.manage) approves, up to L2 purchasing.approve, above → purchasing.approve_high. */
    public static function limits(): array
    {
        return [(float) setting('po_limit_1', '5000'), (float) setting('po_limit_2', '25000')];
    }

    public static function minQuotes(): int
    {
        return max(1, (int) setting('rfq_min_quotes', '3'));
    }

    public static function overReceiptPct(): float
    {
        return max(0.0, (float) setting('po_over_receipt_pct', '0'));
    }

    // ─────────────────────────── price lists ───────────────────────────

    /** Lowest agreed price for a supplier + item valid on a date (respecting the minimum order quantity). */
    public static function listPrice(int $supplierId, int $itemId, ?string $date = null, ?float $qty = null): ?SupplierPrice
    {
        $date ??= bdate()->toDateString();

        return SupplierPrice::where('supplier_id', $supplierId)->where('inventory_item_id', $itemId)->validOn($date)
            ->when($qty !== null, fn ($q) => $q->where('min_qty', '<=', $qty))->orderBy('rate')->first();
    }

    /** [supplier_id][item_id] => rate of every price valid on the date (lowest per pair) – feeds the PO form. */
    public static function priceMap(?string $date = null): array
    {
        $map = [];
        foreach (SupplierPrice::validOn($date ?? bdate()->toDateString())->where('min_qty', 0)->orderByDesc('rate')->get() as $p) {
            $map[$p->supplier_id][$p->inventory_item_id] = (float) $p->rate; // ascending overwrite → lowest wins
        }

        return $map;
    }

    /** Add a price. An open-ended older price for the same supplier / item / min qty is closed the day before. */
    public static function savePrice(array $d, ?SupplierPrice $p = null): SupplierPrice
    {
        if (! empty($d['valid_to']) && $d['valid_to'] < $d['valid_from']) {
            throw new RuntimeException('"Valid to" cannot be before "valid from".');
        }
        if ((float) $d['rate'] <= 0) {
            throw new RuntimeException('Enter the agreed rate.');
        }

        return DB::transaction(function () use ($d, $p) {
            $minQty = (float) ($d['min_qty'] ?? 0);
            if (! $p) {
                SupplierPrice::where('supplier_id', $d['supplier_id'])->where('inventory_item_id', $d['inventory_item_id'])->where('min_qty', $minQty)
                    ->whereNull('valid_to')->where('valid_from', '<', $d['valid_from'])
                    ->update(['valid_to' => Carbon::parse($d['valid_from'])->subDay()->toDateString()]);
            }
            $p ??= new SupplierPrice(['created_by' => auth()->id()]);
            $p->fill([
                'supplier_id' => $d['supplier_id'], 'inventory_item_id' => $d['inventory_item_id'], 'rate' => round((float) $d['rate'], 2), 'min_qty' => $minQty,
                'lead_days' => $d['lead_days'] ?? null, 'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'] ?? null,
                'notes' => $d['notes'] ?? null, 'source' => $d['source'] ?? ($p->source ?: 'manual'), 'rfq_id' => $d['rfq_id'] ?? $p->rfq_id,
            ])->save();
            ActivityLogger::log('purchasing', 'price', 'Price list: '.($p->item?->name ?? 'item')." @ {$p->rate} from ".($p->supplier?->name ?? 'supplier'), $p);

            return $p;
        });
    }

    /** Stamp each line with the supplier's agreed rate when the PO is raised (drives the price flag and the scorecard). */
    public static function stampListRates(PurchaseOrder $po): void
    {
        $date = $po->po_date?->toDateString() ?? bdate()->toDateString();
        foreach ($po->items()->get() as $l) {
            $price = self::listPrice($po->supplier_id, $l->inventory_item_id, $date, (float) $l->qty);
            $l->forceFill(['list_rate' => $price?->rate])->saveQuietly();
        }
    }

    // ─────────────────────────── PO approval matrix ───────────────────────────

    /** 1 = store, 2 = purchasing manager, 3 = senior (GM / owner). A line priced above the agreed rate needs at least level 2. */
    public static function requiredLevel(PurchaseOrder $po): int
    {
        [$l1, $l2] = self::limits();
        $total = (float) $po->total;
        $level = $total <= $l1 ? 1 : ($total <= $l2 ? 2 : 3);
        if ($level < 2 && $po->items->contains(fn ($l) => $l->aboveList())) {
            $level = 2;
        }

        return $level;
    }

    public static function levelLabel(int $level): string
    {
        return [1 => 'Store (level 1)', 2 => 'Purchasing manager (level 2)', 3 => 'Senior management (level 3)'][$level] ?? 'Level '.$level;
    }

    public static function userLevel(?User $u): int
    {
        return match (true) {
            ! $u => 0,
            $u->hasPermission('purchasing.approve_high') => 3,
            $u->hasPermission('purchasing.approve') => 2,
            $u->hasPermission('inventory.manage') => 1,
            default => 0,
        };
    }

    /** Why the user may not approve this PO (null = may approve). */
    public static function approvalBlocker(PurchaseOrder $po, ?User $u = null): ?string
    {
        $u ??= auth()->user();
        if ($po->status !== 'draft') {
            return 'Only draft purchase orders can be approved.';
        }
        if ($po->items->isEmpty() || (float) $po->total <= 0) {
            return 'The purchase order has no priced lines.';
        }
        $need = self::requiredLevel($po);
        if (self::userLevel($u) < $need) {
            return 'This PO ('.money($po->total).') needs approval by '.self::levelLabel($need).'.';
        }
        if (setting('po_segregation', '0') === '1' && (int) $po->created_by === (int) $u?->id) {
            return 'Segregation of duties is on: the person who raised a PO cannot approve it.';
        }

        return null;
    }

    public static function approve(PurchaseOrder $po): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            $po = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($po->id);
            if ($why = self::approvalBlocker($po)) {
                throw new RuntimeException($why);
            }
            $level = self::requiredLevel($po);
            $po->forceFill(['status' => 'approved', 'approval_level' => $level, 'approved_by' => auth()->id(), 'approved_at' => now()])->save();
            $above = $po->items->filter(fn ($l) => $l->aboveList())->count();
            ActivityLogger::log('inventory', 'po_approved', "{$po->po_no} approved at ".self::levelLabel($level).' – '.money($po->total).($above ? " ({$above} line(s) above the agreed price)" : ''), $po);

            return $po;
        });
    }

    // ─────────────────────────── RFQ ───────────────────────────

    /** Create / update a draft RFQ with its lines and invited suppliers. */
    public static function saveRfq(array $d, ?Rfq $rfq = null): Rfq
    {
        if ($rfq && $rfq->status !== 'draft') {
            throw new RuntimeException('Only a draft RFQ can be edited.');
        }
        $lines = collect($d['lines'] ?? [])->filter(fn ($l) => ! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0)->values();
        if ($lines->isEmpty()) {
            throw new RuntimeException('Add at least one item to the RFQ.');
        }
        if ($lines->pluck('item_id')->duplicates()->isNotEmpty()) {
            throw new RuntimeException('Each item can appear only once – combine the quantities.');
        }
        $suppliers = collect($d['supplier_ids'] ?? [])->map(fn ($v) => (int) $v)->unique()->values();
        if ($suppliers->isEmpty()) {
            throw new RuntimeException('Invite at least one supplier.');
        }
        if ($d['respond_by'] < bdate()->toDateString()) {
            throw new RuntimeException('The quotation deadline cannot be in the past.');
        }
        if (! empty($d['needed_by']) && $d['needed_by'] < $d['respond_by']) {
            throw new RuntimeException('The delivery date must be on or after the quotation deadline.');
        }

        return DB::transaction(function () use ($d, $rfq, $lines, $suppliers) {
            $new = ! $rfq;
            $rfq ??= new Rfq(['rfq_no' => Numbering::next('rfq'), 'rfq_date' => bdate()->toDateString(), 'created_by' => auth()->id(), 'status' => 'draft']);
            $rfq->fill(['title' => $d['title'], 'store_id' => $d['store_id'], 'respond_by' => $d['respond_by'], 'needed_by' => $d['needed_by'] ?? null,
                'terms' => $d['terms'] ?? null, 'notes' => $d['notes'] ?? null])->save();
            $rfq->lines()->delete();
            foreach ($lines as $l) {
                $rfq->lines()->create(['inventory_item_id' => (int) $l['item_id'], 'qty' => round((float) $l['qty'], 2), 'specification' => $l['specification'] ?? null]);
            }
            $rfq->invites()->whereNotIn('supplier_id', $suppliers)->delete();
            foreach ($suppliers as $sid) {
                self::invite($rfq, $sid);
            }
            ActivityLogger::log('purchasing', $new ? 'rfq_create' : 'rfq_edit', ($new ? 'Created' : 'Updated')." {$rfq->rfq_no} – {$rfq->title} ({$lines->count()} item(s), {$suppliers->count()} supplier(s))", $rfq);

            return $rfq->fresh();
        });
    }

    protected static function invite(Rfq $rfq, int $supplierId): RfqSupplier
    {
        $inv = RfqSupplier::where('rfq_id', $rfq->id)->where('supplier_id', $supplierId)->first();
        if ($inv) {
            return $inv;
        }
        $inv = new RfqSupplier(['rfq_id' => $rfq->id, 'supplier_id' => $supplierId]);
        $inv->forceFill(['token' => Str::random(40)])->save();

        return $inv;
    }

    /** Send the RFQ: e-mail each invited supplier their private quote link. Suppliers without e-mail get the printed RFQ. */
    public static function send(Rfq $rfq): array
    {
        if ($rfq->status !== 'draft') {
            throw new RuntimeException('This RFQ has already been sent.');
        }
        if ($rfq->respond_by->toDateString() < bdate()->toDateString()) {
            throw new RuntimeException('The quotation deadline has passed – edit the RFQ first.');
        }
        if (! $rfq->lines()->exists() || ! $rfq->invites()->exists()) {
            throw new RuntimeException('Add items and suppliers first.');
        }
        $rfq->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $out = ['emailed' => 0, 'manual' => 0];
        foreach ($rfq->invites()->with('supplier')->get() as $inv) {
            self::deliver($rfq, $inv) ? $out['emailed']++ : $out['manual']++;
        }
        ActivityLogger::log('purchasing', 'rfq_send', "{$rfq->rfq_no} sent to ".($out['emailed'] + $out['manual'])." supplier(s) ({$out['emailed']} by e-mail)", $rfq);

        return $out;
    }

    /** Add a supplier to an RFQ that is already out (and invite them straight away). */
    public static function addSupplier(Rfq $rfq, int $supplierId): RfqSupplier
    {
        if (! in_array($rfq->status, ['draft', 'sent'])) {
            throw new RuntimeException('Suppliers can only be added while the RFQ is open.');
        }
        $inv = self::invite($rfq, $supplierId);
        if ($rfq->status === 'sent' && ! $inv->invited_at) {
            self::deliver($rfq, $inv->load('supplier'));
        }

        return $inv;
    }

    /** E-mail the link (true when sent). The invitation is recorded either way. */
    public static function deliver(Rfq $rfq, RfqSupplier $inv): bool
    {
        $ok = false;
        if ($inv->supplier?->email) {
            $log = MailService::send($inv->supplier->email, new \App\Mail\RfqInviteMail($rfq, $inv, hotel()), 'rfq_invite', $rfq);
            $ok = $log->status === 'sent';
        }
        $inv->forceFill(['invited_at' => $inv->invited_at ?? now(), 'email_status' => $inv->supplier?->email ? ($ok ? 'sent' : 'failed') : null])->save();

        return $ok;
    }

    /**
     * Record a supplier's quotation (from the portal, or keyed in by the buyer from a paper / e-mail quote).
     * $lines: [rfq_line_id => ['rate' => …, 'tax_rate' => …, 'qty_offered' => …, 'remarks' => …]] – a blank rate is "no bid".
     */
    public static function submitQuote(RfqSupplier $inv, array $d, string $source = 'manual'): RfqQuote
    {
        $rfq = $inv->rfq;
        if ($source === 'portal' ? ! $rfq->acceptingQuotes() : ! in_array($rfq->status, ['sent', 'closed'])) {
            throw new RuntimeException($source === 'portal' ? 'This request for quotation is no longer accepting quotes.' : 'Quotes can only be recorded for an RFQ that has been sent.');
        }
        $rfqLines = $rfq->lines()->get()->keyBy('id');
        $bids = collect($d['lines'] ?? [])->filter(fn ($l, $id) => isset($rfqLines[$id]) && ($l['rate'] ?? '') !== '' && $l['rate'] !== null);
        if ($bids->isEmpty()) {
            throw new RuntimeException('Quote a price for at least one item.');
        }
        foreach ($bids as $id => $l) {
            if ((float) $l['rate'] <= 0) {
                throw new RuntimeException('Prices must be greater than zero ('.$rfqLines[$id]->item?->name.').');
            }
            if ((float) ($l['tax_rate'] ?? 0) < 0 || (float) ($l['tax_rate'] ?? 0) > 50) {
                throw new RuntimeException('Tax % must be between 0 and 50.');
            }
            if (($l['qty_offered'] ?? '') !== '' && $l['qty_offered'] !== null && ((float) $l['qty_offered'] <= 0 || (float) $l['qty_offered'] > (float) $rfqLines[$id]->qty)) {
                throw new RuntimeException('The quantity offered must be between 0 and the quantity requested.');
            }
        }
        if (! empty($d['valid_until']) && $d['valid_until'] < bdate()->toDateString()) {
            throw new RuntimeException('The quote validity date has already passed.');
        }

        return DB::transaction(function () use ($inv, $rfq, $d, $bids, $rfqLines, $source) {
            $q = RfqQuote::where('rfq_id', $rfq->id)->where('supplier_id', $inv->supplier_id)->lockForUpdate()->first();
            $rev = $q ? $q->revision + 1 : 1;
            $q ??= new RfqQuote(['rfq_id' => $rfq->id, 'supplier_id' => $inv->supplier_id]);
            $q->fill(['quote_ref' => $d['quote_ref'] ?? null, 'quote_date' => bdate()->toDateString(), 'valid_until' => $d['valid_until'] ?? null,
                'delivery_days' => ($d['delivery_days'] ?? '') === '' ? null : (int) $d['delivery_days'],
                'payment_terms_days' => ($d['payment_terms_days'] ?? '') === '' ? null : (int) $d['payment_terms_days'],
                'notes' => $d['notes'] ?? null, 'source' => $source, 'revision' => $rev, 'entered_by' => $source === 'manual' ? auth()->id() : null])->save();
            $q->lines()->delete();
            $sub = 0.0;
            $tax = 0.0;
            foreach ($bids as $id => $l) {
                $qty = ($l['qty_offered'] ?? '') === '' || $l['qty_offered'] === null ? (float) $rfqLines[$id]->qty : (float) $l['qty_offered'];
                $q->lines()->create(['rfq_line_id' => $id, 'rate' => round((float) $l['rate'], 2), 'tax_rate' => round((float) ($l['tax_rate'] ?? 0), 2),
                    'qty_offered' => (float) $qty === (float) $rfqLines[$id]->qty ? null : $qty, 'remarks' => isset($l['remarks']) ? mb_substr((string) $l['remarks'], 0, 255) : null]);
                $amt = round($qty * (float) $l['rate'], 2);
                $sub += $amt;
                $tax += round($amt * (float) ($l['tax_rate'] ?? 0) / 100, 2);
            }
            $q->forceFill(['subtotal' => round($sub, 2), 'tax' => round($tax, 2), 'total' => round($sub + $tax, 2)])->save();
            $inv->forceFill(['responded_at' => now(), 'declined_at' => null, 'decline_reason' => null])->save();
            ActivityLogger::log('purchasing', 'quote', "Quote from {$inv->supplier->name} for {$rfq->rfq_no}: ".money($q->total).($rev > 1 ? " (revision {$rev})" : '').($source === 'portal' ? ' via supplier portal' : ''), $q);

            return $q;
        });
    }

    public static function decline(RfqSupplier $inv, ?string $reason): void
    {
        if (! $inv->rfq->acceptingQuotes()) {
            throw new RuntimeException('This request for quotation is closed.');
        }
        if (RfqQuote::where('rfq_id', $inv->rfq_id)->where('supplier_id', $inv->supplier_id)->exists()) {
            throw new RuntimeException('A quotation has already been submitted.');
        }
        $inv->forceFill(['declined_at' => now(), 'decline_reason' => $reason ? mb_substr($reason, 0, 255) : null])->save();
        ActivityLogger::log('purchasing', 'quote_decline', "{$inv->supplier->name} declined {$inv->rfq->rfq_no}".($reason ? ": {$reason}" : ''), $inv->rfq);
    }

    /** Stop accepting quotes (bid opening). */
    public static function close(Rfq $rfq): void
    {
        if ($rfq->status !== 'sent') {
            throw new RuntimeException('Only an RFQ that is out for quotation can be closed.');
        }
        $rfq->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        ActivityLogger::log('purchasing', 'rfq_close', "{$rfq->rfq_no} closed for quotations ({$rfq->quotes()->count()} received)", $rfq);
    }

    public static function cancel(Rfq $rfq, string $reason): void
    {
        if (! in_array($rfq->status, Rfq::OPEN)) {
            throw new RuntimeException('This RFQ can no longer be cancelled.');
        }
        $rfq->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
        ActivityLogger::log('purchasing', 'rfq_cancel', "{$rfq->rfq_no} cancelled: {$reason}", $rfq);
    }

    /**
     * Comparative statement: one row per RFQ line, one column per quoting supplier.
     * The lowest landed rate (incl. tax) among bids for the full quantity is the "L1"; part-quantity bids are shown but ranked after.
     */
    public static function comparative(Rfq $rfq): array
    {
        $rfq->loadMissing('lines.item', 'quotes.supplier', 'quotes.lines');
        $quotes = $rfq->quotes->sortBy('total')->values();
        $lastRate = self::lastRates($rfq->lines->pluck('inventory_item_id')->all());
        $rows = [];
        foreach ($rfq->lines as $line) {
            $cells = [];
            foreach ($quotes as $q) {
                $ql = $q->lines->firstWhere('rfq_line_id', $line->id);
                $cells[$q->id] = $ql ? ['ql' => $ql, 'landed' => $ql->landed(), 'full' => $ql->qty_offered === null, 'qty' => (float) ($ql->qty_offered ?? $line->qty),
                    'amount' => round((float) ($ql->qty_offered ?? $line->qty) * $ql->landed(), 2)] : null;
            }
            $ranked = collect($cells)->filter()->sortBy(fn ($c) => [$c['full'] ? 0 : 1, $c['landed']]);
            $low = $ranked->first();
            $rows[] = ['line' => $line, 'cells' => $cells, 'lowest' => $low ? $low['ql']->id : null, 'lowestLanded' => $low['landed'] ?? null,
                'bids' => $ranked->count(), 'last' => $lastRate[$line->inventory_item_id] ?? null,
                'saving' => $low && isset($lastRate[$line->inventory_item_id]) ? round(((float) $lastRate[$line->inventory_item_id] - (float) $low['ql']->rate) * (float) $line->qty, 2) : null];
        }
        $lowTotal = collect($rows)->sum(fn ($r) => $r['lowest'] ? collect($r['cells'])->filter()->first(fn ($c) => $c['ql']->id === $r['lowest'])['amount'] : 0);

        return ['quotes' => $quotes, 'rows' => $rows, 'lowTotal' => round($lowTotal, 2), 'scores' => self::scorecard(bdate()->copy()->subYear()->toDateString(), bdate()->toDateString())->keyBy('supplier_id')];
    }

    /** Last PO rate per item (reference column on the comparative statement). */
    public static function lastRates(array $itemIds): array
    {
        return PurchaseOrderItem::join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.hotel_id', \App\Support\Tenant::id())->whereIn('purchase_orders.status', ['approved', 'partial', 'received', 'closed'])
            ->whereIn('purchase_order_items.inventory_item_id', $itemIds)->orderBy('purchase_orders.id')
            ->get(['purchase_order_items.inventory_item_id', 'purchase_order_items.rate'])->pluck('rate', 'inventory_item_id')->map(fn ($r) => (float) $r)->all();
    }

    /**
     * Award line by line (split award allowed) and raise one draft PO per winning supplier.
     * $awards: [rfq_line_id => rfq_quote_line_id | '' (not awarded)], $reasons: [rfq_line_id => text] – required when the award is not the L1.
     */
    public static function award(Rfq $rfq, array $awards, array $reasons = [], ?string $note = null, bool $updatePrices = false): Collection
    {
        if (! in_array($rfq->status, ['sent', 'closed'])) {
            throw new RuntimeException('This RFQ cannot be awarded.');
        }
        $cmp = self::comparative($rfq);
        $quoteCount = $cmp['quotes']->count();
        if (! $quoteCount) {
            throw new RuntimeException('No quotations have been received.');
        }
        if ($quoteCount < self::minQuotes() && trim((string) $note) === '') {
            throw new RuntimeException('Only '.$quoteCount.' quotation(s) received (policy: '.self::minQuotes().'). Record why the award is going ahead in the award note.');
        }
        $chosen = [];
        foreach ($cmp['rows'] as $r) {
            $lid = $r['line']->id;
            $qlId = (int) ($awards[$lid] ?? 0);
            if (! $qlId) {
                continue;
            }
            $cell = collect($r['cells'])->filter()->first(fn ($c) => $c['ql']->id === $qlId);
            if (! $cell) {
                throw new RuntimeException('Invalid award for '.$r['line']->item->name.'.');
            }
            $reason = trim((string) ($reasons[$lid] ?? ''));
            if ($qlId !== $r['lowest'] && mb_strlen($reason) < 5) {
                throw new RuntimeException($r['line']->item->name.' is not awarded to the lowest price – give a justification.');
            }
            $chosen[$lid] = [$r['line'], $cell['ql'], $qlId !== $r['lowest'] ? $reason : null];
        }
        if (! $chosen) {
            throw new RuntimeException('Award at least one item.');
        }

        return DB::transaction(function () use ($rfq, $chosen, $note, $updatePrices, $cmp) {
            $quotes = $cmp['quotes']->keyBy('id');
            $pos = collect();
            foreach (collect($chosen)->groupBy(fn ($c) => $c[1]->rfq_quote_id) as $quoteId => $group) {
                $q = $quotes[$quoteId];
                $expected = $rfq->needed_by?->toDateString() ?? ($q->delivery_days ? bdate()->copy()->addDays($q->delivery_days)->toDateString() : null);
                $po = PurchaseOrder::create(['po_no' => Numbering::next('po'), 'supplier_id' => $q->supplier_id, 'store_id' => $rfq->store_id, 'rfq_id' => $rfq->id,
                    'po_date' => bdate()->toDateString(), 'expected_date' => $expected, 'status' => 'draft', 'created_by' => auth()->id(),
                    'notes' => "Awarded from {$rfq->rfq_no} – {$rfq->title}".($q->quote_ref ? " (supplier quote {$q->quote_ref})" : '').($q->payment_terms_days ? " · payment {$q->payment_terms_days} days" : '')]);
                $sub = 0.0;
                $tax = 0.0;
                foreach ($group as [$line, $ql, $reason]) {
                    $qty = (float) ($ql->qty_offered ?? $line->qty);
                    $amt = round($qty * (float) $ql->rate, 2);
                    $po->items()->create(['inventory_item_id' => $line->inventory_item_id, 'qty' => $qty, 'rate' => $ql->rate, 'tax_rate' => $ql->tax_rate, 'amount' => $amt, 'rfq_quote_line_id' => $ql->id]);
                    $sub += $amt;
                    $tax += $amt * (float) $ql->tax_rate / 100;
                    $line->forceFill(['awarded_quote_line_id' => $ql->id, 'award_reason' => $reason])->save();
                    if ($updatePrices) {
                        self::savePrice(['supplier_id' => $q->supplier_id, 'inventory_item_id' => $line->inventory_item_id, 'rate' => $ql->rate, 'min_qty' => 0,
                            'lead_days' => $q->delivery_days, 'valid_from' => bdate()->toDateString(), 'valid_to' => $q->valid_until?->toDateString() ?? bdate()->copy()->addDays(90)->toDateString(),
                            'source' => 'rfq', 'rfq_id' => $rfq->id, 'notes' => "{$rfq->rfq_no} award"]);
                    }
                }
                $po->update(['subtotal' => round($sub, 2), 'tax' => round($tax, 2), 'total' => round($sub + $tax, 2)]);
                self::stampListRates($po);
                ActivityLogger::log('inventory', 'po_create', "Created {$po->po_no} from {$rfq->rfq_no} award", $po);
                $pos->push($po);
            }
            $rfq->forceFill(['status' => 'awarded', 'closed_at' => $rfq->closed_at ?? now(), 'awarded_by' => auth()->id(), 'awarded_at' => now(), 'award_note' => $note ? mb_substr($note, 0, 500) : null])->save();
            ActivityLogger::log('purchasing', 'rfq_award', "{$rfq->rfq_no} awarded: ".count($chosen).' item(s) → '.$pos->pluck('po_no')->join(', '), $rfq);

            return $pos;
        });
    }

    // ─────────────────────────── goods receipts ───────────────────────────

    /**
     * Record a delivery against an approved / part-received PO.
     * $lines: [purchase_order_item_id => ['delivered' => qty, 'rejected' => qty, 'reason' => text]]. Accepted = delivered − rejected.
     */
    public static function receive(PurchaseOrder $po, array $d): GoodsReceipt
    {
        $date = $d['date'] ?? bdate()->toDateString();
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('The receipt date cannot be after the business date.');
        }
        if (($lock = LedgerService::lockDate()) && $date <= $lock) {
            throw new RuntimeException('The books are locked up to '.Carbon::parse($lock)->format('d M Y').'.');
        }

        $grn = DB::transaction(function () use ($po, $d, $date) {
            $po = PurchaseOrder::with('items.item', 'supplier')->lockForUpdate()->findOrFail($po->id);
            if (! in_array($po->status, ['approved', 'partial'])) {
                throw new RuntimeException('Goods can only be received against an approved purchase order.');
            }
            if ($date < $po->po_date->toDateString()) {
                throw new RuntimeException('The receipt date cannot be before the PO date.');
            }
            $over = self::overReceiptPct();
            $rows = [];
            foreach ($po->items as $l) {
                $in = $d['lines'][$l->id] ?? null;
                $del = round((float) ($in['delivered'] ?? 0), 2);
                $rej = round((float) ($in['rejected'] ?? 0), 2);
                if ($del <= 0 && $rej <= 0) {
                    continue;
                }
                if ($del < 0 || $rej < 0 || $rej > $del) {
                    throw new RuntimeException("{$l->item->name}: rejected quantity cannot exceed the quantity delivered.");
                }
                if ($rej > 0 && trim((string) ($in['reason'] ?? '')) === '') {
                    throw new RuntimeException("{$l->item->name}: give the reason for rejecting {$rej} {$l->item->unit}.");
                }
                $acc = round($del - $rej, 2);
                $allowed = round($l->outstanding() + (float) $l->qty * $over / 100, 2);
                if ($acc > $allowed + 0.004) {
                    throw new RuntimeException("{$l->item->name}: accepting {$acc} exceeds the {$l->outstanding()} {$l->item->unit} still on order".($over ? " (+{$over}% tolerance)" : '').'.');
                }
                $rows[] = [$l, $del, $acc, $rej, $rej > 0 ? mb_substr(trim($in['reason']), 0, 120) : null];
            }
            if (! $rows) {
                throw new RuntimeException('Enter the quantity delivered for at least one item.');
            }

            $grn = GoodsReceipt::create(['grn_no' => Numbering::next('grn'), 'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id, 'store_id' => $po->store_id,
                'date' => $date, 'delivery_note' => $d['delivery_note'] ?? null, 'invoice_no' => $d['invoice_no'] ?? null, 'vehicle_no' => $d['vehicle_no'] ?? null,
                'notes' => $d['notes'] ?? null, 'received_by' => auth()->id()]);
            $sub = 0.0;
            $tax = 0.0;
            $rejVal = 0.0;
            foreach ($rows as [$l, $del, $acc, $rej, $reason]) {
                $amt = round($acc * (float) $l->rate, 2);
                $grn->lines()->create(['purchase_order_item_id' => $l->id, 'inventory_item_id' => $l->inventory_item_id, 'qty_delivered' => $del, 'qty_accepted' => $acc,
                    'qty_rejected' => $rej, 'reject_reason' => $reason, 'rate' => $l->rate, 'tax_rate' => $l->tax_rate, 'amount' => $amt]);
                if ($acc > 0) {
                    StockMovement::create(['inventory_item_id' => $l->inventory_item_id, 'store_id' => $po->store_id, 'date' => $date, 'type' => 'purchase',
                        'qty' => $acc, 'rate' => $l->rate, 'reference' => $grn->grn_no, 'remarks' => 'Received against '.$po->po_no, 'user_id' => auth()->id()]);
                    $l->item->update(['last_cost' => $l->rate]);
                }
                $l->forceFill(['received_qty' => round((float) $l->received_qty + $acc, 2), 'rejected_qty' => round((float) $l->rejected_qty + $rej, 2)])->save();
                $sub += $amt;
                $tax += round($amt * (float) $l->tax_rate / 100, 2);
                $rejVal += round($rej * (float) $l->rate, 2);
            }
            $grn->forceFill(['subtotal' => round($sub, 2), 'tax' => round($tax, 2), 'total' => round($sub + $tax, 2), 'rejected_value' => round($rejVal, 2)])->save();

            $complete = $po->items->every(fn ($l) => $l->outstanding() <= 0);
            $po->forceFill(['status' => $complete ? 'received' : 'partial', 'received_at' => $complete ? now() : $po->received_at,
                'invoice_no' => ($d['invoice_no'] ?? null) ?: $po->invoice_no])->save();
            $po->forgetGrnCache();
            $rejN = collect($rows)->where(3, '>', 0)->count();
            ActivityLogger::log('inventory', 'grn', "{$grn->grn_no} against {$po->po_no}: ".money($grn->total).' accepted'.($rejN ? ", {$rejN} line(s) with rejections (".money($rejVal).')' : '').($complete ? ' – PO fully received' : ' – part delivery'), $grn);

            return $grn;
        });

        return $grn;
    }

    /** Short-close a part-received PO: the balance will not be delivered. The PO becomes billable at the received value. */
    public static function shortClose(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        if ($po->status !== 'partial') {
            throw new RuntimeException('Only a part-received PO can be short-closed (cancel an approved PO with nothing received instead).');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give the reason for closing the balance.');
        }
        $po->forceFill(['status' => 'closed', 'close_reason' => mb_substr(trim($reason), 0, 255), 'closed_at' => now(), 'closed_by' => auth()->id()])->save();
        $open = $po->items()->get()->sum(fn ($l) => $l->outstanding() * (float) $l->rate);
        ActivityLogger::log('inventory', 'po_closed', "{$po->po_no} short-closed (".money($open)." not delivered): {$reason}", $po);

        return $po;
    }

    // ─────────────────────────── scorecard ───────────────────────────

    /**
     * Supplier performance over a period: on-time delivery, fill rate, quality (rejections), price compliance vs the
     * agreed list, RFQ response and lead time. Score = weighted average of the available measures (35/25/25/15).
     */
    public static function scorecard(string $from, string $to, ?int $supplierId = null): Collection
    {
        $pos = PurchaseOrder::with('items', 'receipts')->whereDate('po_date', '>=', $from)->whereDate('po_date', '<=', $to)->whereNotIn('status', ['draft', 'cancelled'])
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get()->groupBy('supplier_id');
        $grns = GoodsReceipt::with('order', 'lines')->whereBetween('date', [$from, $to])->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get()->groupBy('supplier_id');
        $legacy = PurchaseOrder::where('status', 'received')->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->whereDoesntHave('receipts')->get()->groupBy('supplier_id');
        $invites = RfqSupplier::whereNotNull('invited_at')->whereBetween('invited_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get()->groupBy('supplier_id');
        $ids = collect([$pos->keys(), $grns->keys(), $legacy->keys(), $invites->keys()])->flatten()->unique();
        $names = Supplier::whereIn('id', $ids)->pluck('name', 'id');

        return $ids->map(function ($sid) use ($pos, $grns, $legacy, $invites, $names) {
            $p = $pos[$sid] ?? collect();
            $g = $grns[$sid] ?? collect();
            $lg = $legacy[$sid] ?? collect();
            $inv = $invites[$sid] ?? collect();
            // deliveries: every GRN + every single-step receipt
            $deliveries = $g->count() + $lg->count();
            $onTime = $g->filter(fn ($r) => $r->onTime())->count()
                + $lg->filter(fn ($o) => ! $o->expected_date || $o->received_at->toDateString() <= $o->expected_date->toDateString())->count();
            $lead = $g->map(fn ($r) => $r->order ? $r->order->po_date->diffInDays($r->date) : null)->filter(fn ($v) => $v !== null)
                ->merge($lg->map(fn ($o) => $o->po_date->diffInDays($o->received_at->copy()->startOfDay())));
            // fill rate over POs that are done or in progress
            $done = $p->whereIn('status', ['partial', 'received', 'closed']);
            $ordered = $done->sum(fn ($o) => $o->items->sum('qty'));
            $got = $done->sum(fn ($o) => $o->items->sum(fn ($l) => min((float) $l->received_qty, (float) $l->qty)));
            $delivered = $g->sum(fn ($r) => $r->lines->sum('qty_delivered'));
            $rejected = $g->sum(fn ($r) => $r->lines->sum('qty_rejected'));
            $listed = $p->flatMap->items->filter(fn ($l) => $l->list_rate !== null);
            $compliant = $listed->filter(fn ($l) => ! $l->aboveList())->count();
            $m = [
                'on_time' => $deliveries ? round($onTime / $deliveries * 100, 1) : null,
                'fill' => $ordered > 0 ? round($got / $ordered * 100, 1) : null,
                'quality' => $delivered > 0 ? round(100 - $rejected / $delivered * 100, 1) : ($lg->count() ? 100.0 : null),
                'price' => $listed->count() ? round($compliant / $listed->count() * 100, 1) : null,
            ];
            $w = ['on_time' => 35, 'fill' => 25, 'quality' => 25, 'price' => 15];
            $wt = collect($m)->filter(fn ($v) => $v !== null)->keys()->sum(fn ($k) => $w[$k]);
            $score = $wt ? round(collect($m)->filter(fn ($v) => $v !== null)->sum(fn ($v, $k) => $v * $w[$k]) / $wt, 1) : null;

            return ['supplier_id' => (int) $sid, 'supplier' => $names[$sid] ?? '#'.$sid, 'orders' => $p->count(), 'value' => round((float) $p->sum('total'), 2),
                'deliveries' => $deliveries, 'on_time' => $m['on_time'], 'fill' => $m['fill'], 'quality' => $m['quality'], 'rejected_qty' => round($rejected, 2),
                'price' => $m['price'], 'priced_lines' => $listed->count(), 'above_list' => $listed->count() - $compliant,
                'invited' => $inv->count(), 'quoted' => $inv->filter(fn ($i) => $i->responded_at)->count(),
                'response' => $inv->count() ? round($inv->filter(fn ($i) => $i->responded_at)->count() / $inv->count() * 100, 1) : null,
                'lead_days' => $lead->count() ? round($lead->avg(), 1) : null, 'score' => $score, 'grade' => self::grade($score)];
        })->sortByDesc(fn ($r) => [$r['score'] ?? -1, $r['value']])->values();
    }

    public static function grade(?float $score): string
    {
        return match (true) {
            $score === null => '—',
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            default => 'D',
        };
    }

    // ─────────────────────────── queues / alerts ───────────────────────────

    /** POs this user can approve now. */
    public static function awaitingMyApproval(?User $u = null): Collection
    {
        $u ??= auth()->user();
        if (self::userLevel($u) < 1) {
            return collect();
        }

        return PurchaseOrder::with('items', 'supplier')->where('status', 'draft')->where('total', '>', 0)->get()->filter(fn ($po) => ! self::approvalBlocker($po, $u))->values();
    }

    public static function overdueDeliveries(): Collection
    {
        return PurchaseOrder::with('supplier')->whereIn('status', ['approved', 'partial'])->whereNotNull('expected_date')
            ->whereDate('expected_date', '<', bdate()->toDateString())->orderBy('expected_date')->get();
    }

    /** RFQs past their deadline (or closed) waiting for an award decision. */
    public static function readyToAward(): Collection
    {
        return Rfq::withCount('quotes')->where(fn ($q) => $q->where('status', 'closed')->orWhere(fn ($w) => $w->where('status', 'sent')->where('respond_by', '<', bdate()->toDateString())))->get();
    }

    public static function attentionCount(): int
    {
        $u = auth()->user();
        if (! $u) {
            return 0;
        }
        $n = 0;
        if (self::userLevel($u) >= 1) {
            $n += self::awaitingMyApproval($u)->count();
        }
        if ($u->hasPermission('purchasing.rfq')) {
            $n += self::readyToAward()->count();
        }

        return $n;
    }

    /** Stock items with a price list or recent purchase, for pickers. */
    public static function items(): Collection
    {
        return InventoryItem::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'last_cost', 'tax_rate']);
    }
}
