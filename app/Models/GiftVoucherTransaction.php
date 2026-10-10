<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.8 – one movement on a gift voucher: issue, reload, redeem, reversal, expiry (breakage) or cancellation. */
class GiftVoucherTransaction extends Model
{
    use BelongsToHotel;

    public const TYPES = ['issue' => 'Issued', 'reload' => 'Reloaded', 'redeem' => 'Redeemed', 'reversal' => 'Redemption reversed', 'expire' => 'Expired (breakage)', 'cancel' => 'Cancelled / refunded'];

    /** + raises the balance, − lowers it */
    public const SIGN = ['issue' => 1, 'reload' => 1, 'reversal' => 1, 'redeem' => -1, 'expire' => -1, 'cancel' => -1];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'balance_after' => 'float', 'business_date' => 'date'];
    }

    public function voucher()
    {
        return $this->belongsTo(GiftVoucher::class, 'gift_voucher_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function folioTransaction()
    {
        return $this->belongsTo(FolioTransaction::class);
    }

    public function posOrder()
    {
        return $this->belongsTo(PosOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function signed(): float
    {
        return (self::SIGN[$this->type] ?? 0) * $this->amount;
    }
}
