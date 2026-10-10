<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.7 A bank account, Mobile Money wallet, cash box, petty float or clearing account managed by treasury (one GL head each). */
class TreasuryAccount extends Model
{
    use BelongsToHotel;

    public const KINDS = ['bank' => 'Bank account', 'momo' => 'Mobile Money wallet', 'cash' => 'Cash box', 'petty' => 'Petty cash float', 'clearing' => 'Card / gateway clearing',
        'forex' => 'Foreign currency', 'transit' => 'Cash in transit', 'cheques' => 'Cheques in hand'];

    public const TONES = ['bank' => 'blue', 'momo' => 'amber', 'cash' => 'green', 'petty' => 'green', 'clearing' => 'purple', 'forex' => 'slate', 'transit' => 'slate', 'cheques' => 'slate'];

    /** kinds money can be moved from / to by a transfer */
    public const MOVABLE = ['bank', 'momo', 'cash', 'clearing', 'forex'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['min_balance' => 'decimal:2', 'overdraft_limit' => 'decimal:2', 'fee_rate' => 'decimal:2', 'in_position' => 'boolean', 'is_active' => 'boolean'];
    }

    public function head()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function chequeBooks()
    {
        return $this->hasMany(ChequeBook::class);
    }

    public function label(): string
    {
        return $this->name.($this->account_no ? ' · '.$this->maskedNo() : '');
    }

    /** account numbers are shown masked except the last four digits */
    public function maskedNo(): ?string
    {
        if (! $this->account_no) {
            return null;
        }
        $n = preg_replace('/\s+/', '', $this->account_no);

        return strlen($n) > 4 ? '••••'.substr($n, -4) : $n;
    }
}
