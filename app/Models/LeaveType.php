<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['days_per_year' => 'decimal:1', 'carry_forward_max' => 'decimal:1', 'is_paid' => 'boolean', 'is_active' => 'boolean'];
    }

    public function requests()
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
