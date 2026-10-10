<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use BelongsToHotel;

    protected $table = 'purchase_orders';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'expected_date' => 'date',
            'received_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /** The (non-cancelled) supplier bill matched to this goods receipt. */
    public function bill()
    {
        // status filter inside the of-many subquery (v3.8 fix: a newer row in another status must not hide this one)
        return $this->hasOne(SupplierInvoice::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('type', 'invoice')->where('status', '!=', 'cancelled'));
    }

    /** Date the goods were received (first purchase movement), as used by the ledger. */
    public function grnDate(): ?string
    {
        if ($this->hasGrns()) {
            return $this->receipts()->min('date');
        }
        if ($this->status !== 'received') {
            return null;
        }

        return StockMovement::where('type', 'purchase')->where('reference', $this->po_no)->min('date') ?? ($this->received_at ?? $this->updated_at)->toDateString();
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** v4.8 statuses in which goods may still arrive / are owed. */
    public const OPEN = ['draft', 'approved', 'partial'];

    /** v4.8 transient: goods-received value as used by the AP sub-ledger (set by ApService::unbilledReceipts). */
    public ?float $receiptValue = null;

    protected ?bool $grnCache = null;

    public function receipts()
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('date')->orderBy('id');
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** v4.8: received through goods receipt notes (partial deliveries) rather than the single-step v1 receipt. */
    public function hasGrns(): bool
    {
        return $this->grnCache ??= $this->exists && GoodsReceipt::where('purchase_order_id', $this->id)->exists();
    }

    public function forgetGrnCache(): static
    {
        $this->grnCache = null;

        return $this;
    }

    /** Goods-received value owed to the supplier (as at a date): GRN totals, or the whole PO for a single-step receipt. */
    public function receivedValue(?string $asOf = null): float
    {
        if ($this->hasGrns()) {
            return round((float) GoodsReceipt::where('purchase_order_id', $this->id)->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))->sum('total'), 2);
        }
        if ($this->status !== 'received' || ($asOf && $this->grnDate() > $asOf)) {
            return 0.0;
        }

        return (float) $this->total;
    }

    /** Received value split into subtotal / tax (defaults for the supplier bill). */
    public function receivedSplit(): array
    {
        if (! $this->hasGrns()) {
            return [(float) $this->subtotal, (float) $this->tax];
        }
        $g = GoodsReceipt::where('purchase_order_id', $this->id)->selectRaw('SUM(subtotal) s, SUM(tax) t')->first();

        return [round((float) $g->s, 2), round((float) $g->t, 2)];
    }

    /** Whether the supplier bill can be matched now: all goods in (or the balance short-closed). */
    public function billable(): bool
    {
        return in_array($this->status, ['received', 'closed']) && $this->receivedValue() > 0;
    }

    public function isOverdue(?string $on = null): bool
    {
        $on ??= bdate()->toDateString();

        return in_array($this->status, ['approved', 'partial']) && $this->expected_date && $this->expected_date->toDateString() < $on;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
