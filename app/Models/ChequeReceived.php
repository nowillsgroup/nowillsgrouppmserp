<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * v5.7 A cheque received from a company (settles the city ledger) or for another receipt. A post-dated cheque (PDC) is
 * held off the books until its date; then the receipt posts Dr Cheques in Hand. Banking moves it out on a deposit;
 * a bounce reverses the bank credit and re-opens the receivable.
 */
class ChequeReceived extends Model
{
    use BelongsToHotel;

    protected $table = 'cheques_received';

    public const STATUSES = ['pdc' => 'Post-dated (held)', 'in_hand' => 'In hand', 'deposited' => 'Deposited', 'cleared' => 'Cleared', 'bounced' => 'Bounced', 'returned' => 'Returned to drawer', 'cancelled' => 'Cancelled'];

    public const TONES = ['pdc' => 'purple', 'in_hand' => 'amber', 'deposited' => 'blue', 'cleared' => 'green', 'bounced' => 'red', 'returned' => 'slate', 'cancelled' => 'slate'];

    /** Ghana clearing: a cheque is stale six months after its date */
    public const STALE_MONTHS = 6;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'bounce_fee' => 'decimal:2', 'cheque_date' => 'date', 'received_on' => 'date', 'recognised_on' => 'date', 'left_on' => 'date',
            'cleared_on' => 'date', 'bounced_on' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function head()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function bounceVoucher()
    {
        return $this->belongsTo(Voucher::class, 'bounce_voucher_id');
    }

    public function transfer()
    {
        return $this->belongsTo(TreasuryTransfer::class, 'transfer_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function party(): string
    {
        return $this->company?->name ?? $this->drawer;
    }

    public function staleOn(): Carbon
    {
        return $this->cheque_date->copy()->addMonthsNoOverflow(self::STALE_MONTHS);
    }
}
