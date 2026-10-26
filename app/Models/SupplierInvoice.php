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
            'grn_total' => 'decimal:2', 'grn_fx_subtotal' => 'decimal:2', 'grn_fx_tax' => 'decimal:2', 'grn_fx_total' => 'decimal:2', 'opening_date' => 'date',
            'allocated' => 'decimal:2', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
            // v7.53 the supplier's own document, when it was not billed in the property's currency
            'fx_date' => 'date', 'fx_subtotal' => 'decimal:2', 'fx_tax' => 'decimal:2', 'fx_total' => 'decimal:2', 'fx_rate' => 'decimal:4'];
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

    /** True for an unpaid balance or supplier credit carried in from the cutover snapshot. */
    public function isOpening(): bool
    {
        return $this->opening_date !== null;
    }

    public function outstanding(): float
    {
        return $this->status === 'cancelled' ? 0.0 : round((float) $this->total - (float) $this->allocated, 2);
    }

    /** v7.53 True when the supplier billed in a currency other than the property's own. */
    public function isForeign(): bool
    {
        return filled($this->currency)
            && strtoupper(trim((string) $this->currency)) !== strtoupper(trim((string) (hotel()?->currency ?: '')));
    }

    /**
     * v7.53 The outstanding balance in the supplier's own currency.
     *
     * Every hotel-currency figure on a converted bill is the supplier's figure at one rate, so the balance divides back
     * out. Allocations are made in the hotel currency, which is where any rounding lives.
     */
    public function fxOutstanding(): float
    {
        if (! $this->isForeign() || ! (float) $this->fx_rate) {
            return $this->outstanding();
        }

        return round($this->outstanding() / (float) $this->fx_rate, 2);
    }

    /** v7.53 An amount in the supplier's currency as it reads on the document: "USD 1,150.00". */
    /**
     * The supplier's own figure, printable whatever the bill is in: "EUR 4,280.00". On a bill in the property's own
     * currency there is no foreign figure, so this falls back to that currency and the posted total — a template can
     * call it unconditionally and never print a bare " 0.00".
     */
    public function fxLabel(?float $amount = null): string
    {
        $code = strtoupper(trim((string) ($this->currency ?: hotel()?->currency ?: 'GHS')));
        $value = $amount ?? ($this->isForeign() ? (float) $this->fx_total : (float) $this->total);

        return $code.' '.number_format($value, 2);
    }

    /** v7.53 True when the board this bill was booked at was not the board for the date asked for. */
    public function fxStale(): bool
    {
        if (! $this->isForeign() || ! $this->fx_date || $this->fx_basis === 'legacy') {
            return false;
        }
        $expected = $this->kind === 'goods' && $this->purchase_order_id
            ? $this->doc_date?->toDateString()
            : \App\Services\ForexService::apPolicy($this->doc_date?->toDateString())['date'];

        return $expected !== null && $this->fx_date->toDateString() !== $expected;
    }

    /** The bill-date home-currency value of the received foreign obligation. */
    public function grnAtInvoiceRate(): float
    {
        if (! $this->isForeign() || $this->grn_fx_subtotal === null || $this->grn_fx_tax === null || ! (float) $this->fx_rate) {
            return (float) ($this->grn_total ?? 0);
        }

        return round(round((float) $this->grn_fx_subtotal * (float) $this->fx_rate, 2)
            + round((float) $this->grn_fx_tax * (float) $this->fx_rate, 2), 2);
    }

    /** FX movement between receipt-date stock/AP accrual and invoice-date payable. Positive means a loss. */
    public function fxDifference(): float
    {
        if ($this->kind !== 'goods' || $this->grn_total === null || ! $this->isForeign()
            || $this->grn_fx_subtotal === null || $this->grn_fx_tax === null) {
            return 0.0;
        }

        return round($this->grnAtInvoiceRate() - (float) $this->grn_total, 2);
    }

    /** Supplier price/tax variance, excluding the separately recognised receipt-to-invoice FX movement. */
    public function variance(): float
    {
        if ($this->kind !== 'goods' || $this->grn_total === null) {
            return 0.0;
        }

        return round((float) $this->total - $this->grnAtInvoiceRate(), 2);
    }

    public function daysOverdue(?string $asOf = null): int
    {
        $due = $this->due_date ?? $this->doc_date;

        return max(0, (int) $due->diffInDays(\Illuminate\Support\Carbon::parse($asOf ?? bdate()->toDateString()), false));
    }
}
