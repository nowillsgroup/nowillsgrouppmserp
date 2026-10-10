<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'paid_date' => 'date', 'generated_at' => 'datetime', 'approved_at' => 'datetime',
            'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_cost' => 'decimal:2'];
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function label(): string
    {
        return $this->period_start->format('F Y');
    }

    public function isLocked(): bool
    {
        return $this->status !== 'draft';
    }
}
