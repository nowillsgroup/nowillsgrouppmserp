<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.7 A cheque book for a bank account: a run of leaf numbers used in order. */
class ChequeBook extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    public function account()
    {
        return $this->belongsTo(TreasuryAccount::class, 'treasury_account_id');
    }

    public function cheques()
    {
        return $this->hasMany(ChequeIssued::class);
    }

    public function fmt(int $n): string
    {
        return str_pad((string) $n, (int) $this->digits, '0', STR_PAD_LEFT);
    }

    public function range(): string
    {
        return $this->fmt($this->first_no).'–'.$this->fmt($this->last_no);
    }

    public function leavesLeft(): int
    {
        return $this->status === 'active' ? max(0, $this->last_no - $this->next_no + 1) : 0;
    }
}
