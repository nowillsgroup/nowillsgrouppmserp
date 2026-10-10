<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class CashierMovement extends Model
{
    use BelongsToHotel;

    protected $table = 'cashier_movements';

    protected $guarded = ['id', 'hotel_id'];

    public const KINDS = ['cash_in' => 'Cash in', 'cash_out' => 'Cash out'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function shift()
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isIn(): bool
    {
        return $this->kind === 'cash_in';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', $this->kind));
    }
}
