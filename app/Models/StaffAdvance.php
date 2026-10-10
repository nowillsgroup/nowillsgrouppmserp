<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class StaffAdvance extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'installment' => 'decimal:2', 'cancelled_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recoveries()
    {
        return $this->hasMany(PayslipLine::class);
    }

    /** Recovered through approved / paid payroll (draft runs don't count yet). */
    public function recovered(?int $exceptRunId = null): float
    {
        return (float) PayslipLine::where('staff_advance_id', $this->id)
            ->whereHas('payslip.run', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->when($exceptRunId, fn ($w) => $w->where('id', '!=', $exceptRunId)))
            ->sum('amount');
    }

    public function outstanding(?int $exceptRunId = null): float
    {
        return $this->cancelled_at ? 0.0 : round((float) $this->amount - $this->recovered($exceptRunId), 2);
    }
}
