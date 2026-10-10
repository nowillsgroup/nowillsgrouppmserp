<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.5 A monthly SSNIT Tier 1 / Tier 2 / PAYE payment recorded against the approved payroll (posts Dr liability / Cr bank). */
class StatutoryRemittance extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'cancelled_at' => 'datetime', 'amount' => 'decimal:2', 'employee_share' => 'decimal:2', 'employer_share' => 'decimal:2'];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
