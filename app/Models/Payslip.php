<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['employed_days' => 'decimal:1', 'present_days' => 'decimal:1', 'paid_leave_days' => 'decimal:1', 'lop_days' => 'decimal:1', 'paid_days' => 'decimal:1',
            'ot_hours' => 'decimal:2', 'basic' => 'decimal:2', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'employer_cost' => 'decimal:2'];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines()
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }
}
