<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Folio extends Model
{
    use BelongsToHotel;

    protected $table = 'folios';

    protected $guarded = ['id', 'hotel_id'];

    public function activeTransactions()
    {
        return $this->transactions()->where('is_void', false);
    }

    public function totals(): array
    {
        $tx = $this->activeTransactions()->get();
        $charges = $tx->where('type', 'charge')->sum(fn ($t) => $t->amount + $t->tax_amount);
        $tax = $tx->where('type', 'charge')->sum('tax_amount');
        $discount = $tx->whereIn('type', ['discount', 'allowance'])->sum('amount');
        $payments = $tx->where('type', 'payment')->sum('amount');
        $refunds = $tx->where('type', 'refund')->sum('amount');
        $opening = $tx->where('type', 'opening_balance')->sum('amount');
        $balance = round($charges + $opening - $discount - $payments + $refunds, 2);

        return compact('charges', 'opening', 'tax', 'discount', 'payments', 'refunds', 'balance');
    }

    public function balance(): float
    {
        return $this->totals()['balance'];
    }

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'invoice_date' => 'date', 'irn_ack_at' => 'datetime', 'irn_next_try_at' => 'datetime', 'irn_retryable' => 'boolean',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions()
    {
        return $this->hasMany(FolioTransaction::class);
    }

    // ---- v3.0 tax documents (v4.3: GRA VAT / E-VAT)

    public const NOTE_REASONS = ['rate' => 'Rate / tariff correction', 'deficiency' => 'Service deficiency / complaint', 'billing_error' => 'Billing error (wrong charge)',
        'discount' => 'Post-sale discount', 'cancellation' => 'Cancellation / no-show fee waived', 'other' => 'Other'];

    public function isCreditNote(): bool
    {
        return $this->type === 'credit_note';
    }

    public function isInvoiced(): bool
    {
        return $this->invoice_no !== null && $this->status === 'closed';
    }

    public function hasActiveIrn(): bool
    {
        return $this->irn_status === 'generated' && $this->irn;
    }

    public function original()
    {
        return $this->belongsTo(Folio::class, 'original_folio_id');
    }

    public function creditNotes()
    {
        return $this->hasMany(Folio::class, 'original_folio_id')->where('type', 'credit_note');
    }

    public function einvoiceLogs()
    {
        return $this->hasMany(EinvoiceLog::class)->orderByDesc('id');
    }

    /** Taxable value, tax and document value of the charges on this document (negative for credit notes). */
    public function gstTotals(): array
    {
        $c = $this->activeTransactions()->where('type', 'charge')->get();
        $taxable = round((float) $c->sum('amount'), 2);
        $tax = round((float) $c->sum('tax_amount'), 2);
        $disc = round((float) $this->activeTransactions()->whereIn('type', ['discount', 'allowance'])->sum('amount'), 2);

        return ['taxable' => $taxable, 'tax' => $tax, 'discount' => $disc, 'value' => round($taxable + $tax - $disc, 2)];
    }

    public function irnBadge(): string
    {
        return match ($this->irn_status) {
            'generated' => '<span class="badge badge-green" title="Stamped by GRA E-VAT">E-VAT stamped</span>',
            'failed' => '<span class="badge badge-red">E-VAT failed</span>',
            'pending' => '<span class="badge badge-amber">E-VAT pending</span>',
            'cancelled' => '<span class="badge badge-slate">stamp cancelled</span>',
            default => '',
        };
    }
}
