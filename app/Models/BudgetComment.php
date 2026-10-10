<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.6 – variance commentary: why an account was over / under budget in a month. */
class BudgetComment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['actual' => 'float', 'budget' => 'float'];
    }

    public function account()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function budgetVersion()
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }
}
