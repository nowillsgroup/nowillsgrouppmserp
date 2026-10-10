<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A supplier bill (type=invoice) or debit note (type=debit_note). */
class SupplierInvoice extends Model
{
    use BelongsToHotel;

    protected $table = 'supplier_invoices';

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['on_hold' => 'On hold (variance)', 'open' => 'Open', 'partial' => 'Part-paid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return ['doc_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2',
            'grn_total' => 'decimal:2', 'allocated' => 'decimal:2', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function againstInvoice()
    {
        return $this->belongsTo(self::class, 'against_invoice_id');
    }

    public function lines()
    {
        return $this->hasMany(SupplierInvoiceLine::class);
    }

    /** Payments / debit notes applied to this bill. */
    public function allocations()
    {
        return $this->hasMany(ApAllocation::class, 'supplier_invoice_id');
    }

    /** Where this debit note has been applied. */
    public function applications()
    {
        return $this->hasMany(ApAllocation::class, 'debit_note_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDebitNote(): bool
    {
        return $this->type === 'debit_note';
    }

    public function outstanding(): float
    {
        return $this->status === 'cancelled' ? 0.0 : round((float) $this->total - (float) $this->allocated, 2);
    }

    public function variance(): float
    {
        return $this->kind === 'goods' && $this->grn_total !== null ? round((float) $this->total - (float) $this->grn_total, 2) : 0.0;
    }

    public function daysOverdue(?string $asOf = null): int
    {
        $due = $this->due_date ?? $this->doc_date;

        return max(0, (int) $due->diffInDays(\Illuminate\Support\Carbon::parse($asOf ?? bdate()->toDateString()), false));
    }
}
