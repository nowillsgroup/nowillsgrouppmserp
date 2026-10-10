<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LinenBatch extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['sent' => 'At laundry', 'partial' => 'Partly returned', 'closed' => 'Closed'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['sent_date' => 'date', 'expected_date' => 'date', 'closed_date' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(LinenBatchLine::class)->orderBy('id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sent(): int
    {
        return (int) $this->lines->sum('qty_sent');
    }

    public function outstanding(): int
    {
        return (int) $this->lines->sum(fn ($l) => $l->outstanding());
    }

    /** External laundry charge: pieces returned (clean or damaged) × the per-piece rate. Lost pieces aren't paid for. */
    public function charge(): float
    {
        return round($this->lines->sum(fn ($l) => ($l->qty_returned + $l->qty_damaged) * (float) $l->rate), 2);
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'closed' && $this->expected_date && $this->expected_date->lt(bdate());
    }
}
