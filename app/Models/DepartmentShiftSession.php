<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class DepartmentShiftSession extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'close_snapshot' => 'array',
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'counted_denominations' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function posOrders()
    {
        return $this->hasMany(PosOrder::class, 'department_shift_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function statusLabel(): string
    {
        return $this->status === 'open' ? 'In progress' : 'Closed';
    }
}
