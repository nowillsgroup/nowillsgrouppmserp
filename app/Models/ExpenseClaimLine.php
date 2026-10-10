<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ExpenseClaimLine extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['vat_amount' => 0];

    protected function casts(): array
    {
        return ['date' => 'date', 'km' => 'decimal:1', 'amount' => 'decimal:2', 'vat_amount' => 'decimal:2'];
    }

    public function claim()
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function head()
    {
        return $this->belongsTo(AccountHead::class, 'expense_head_id');
    }
}
