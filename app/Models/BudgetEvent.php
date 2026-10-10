<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.6 – the approval trail of a budget version. */
class BudgetEvent extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
