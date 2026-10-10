<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PayslipLine extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'taxable' => 'boolean', 'is_manual' => 'boolean'];
    }

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }

    public function advance()
    {
        return $this->belongsTo(StaffAdvance::class, 'staff_advance_id');
    }
}
