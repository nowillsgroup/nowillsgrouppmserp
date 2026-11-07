<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v8.2 – one month of one budget version signed off, once every flagged variance has been explained. */
class BudgetSignoff extends Model
{
    use BelongsToHotel;

    protected $table = 'budget_signoffs';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'flagged' => 'integer', 'explained' => 'integer'];
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function periodLabel(): string
    {
        return \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->period)->format('M Y');
    }
}
