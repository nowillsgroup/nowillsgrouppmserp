<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.8 – a gift voucher / stored-value card; its balance is money the hotel owes the holder. */
class GiftVoucher extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['active' => ['Active', 'green'], 'redeemed' => ['Fully redeemed', 'slate'], 'expired' => ['Expired', 'slate'], 'suspended' => ['Suspended', 'amber'], 'cancelled' => ['Cancelled', 'red']];

    public const SOURCES = ['sold' => 'Sold', 'promo' => 'Promotional / complimentary'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['face_value' => 'float', 'balance' => 'float', 'issued_on' => 'date', 'expires_on' => 'date', 'emailed_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function type()
    {
        return $this->belongsTo(GiftVoucherType::class, 'gift_voucher_type_id');
    }

    public function transactions()
    {
        return $this->hasMany(GiftVoucherTransaction::class)->orderBy('id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function isExpired(): bool
    {
        return $this->expires_on->lt(bdate()->copy()->startOfDay());
    }

    public function usable(): bool
    {
        return $this->status === 'active' && $this->balance > 0 && ! $this->isExpired();
    }

    public function redeemed(): float
    {
        return round($this->face_value + (float) $this->transactions->where('type', 'reload')->sum('amount') - $this->balance, 2);
    }

    public function publicUrl(): string
    {
        return route('voucher.public', $this->code);
    }

    /** the code with the middle hidden, for lists shown to staff without the voucher in hand */
    public function maskedCode(): string
    {
        return substr($this->code, 0, 3).'XXXX-'.substr($this->code, -4);
    }
}
