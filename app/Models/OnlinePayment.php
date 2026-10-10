<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OnlinePayment extends Model
{
    use BelongsToHotel;

    public const PURPOSES = ['deposit' => 'Deposit / advance', 'balance' => 'Bill balance', 'booking' => 'Online booking deposit'];

    public const STATUSES = ['pending' => 'Awaiting payment', 'paid' => 'Paid', 'review' => 'Needs review', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id', 'hotel_id', 'token'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'amount_received' => 'decimal:2', 'fee' => 'decimal:2', 'refunded_amount' => 'decimal:2',
            'expires_at' => 'datetime', 'paid_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->token ??= Str::random(40));
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** v7.5 purpose banquet: the event whose folio receives the money. */
    public function event()
    {
        return $this->belongsTo(BanquetEvent::class, 'banquet_event_id');
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function folioTransaction()
    {
        return $this->belongsTo(FolioTransaction::class);
    }

    public function events()
    {
        return $this->hasMany(OnlinePaymentEvent::class)->orderBy('id');
    }

    public function refunds()
    {
        return $this->hasMany(OnlineRefund::class)->orderBy('id');
    }

    public function url(): string
    {
        return route('pay.show', $this->token);
    }

    public function isPayable(): bool
    {
        return $this->status === 'pending' && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function received(): float
    {
        return (float) ($this->amount_received ?? $this->amount);
    }

    public function refundable(): float
    {
        return $this->status === 'paid' ? round($this->received() - (float) $this->refunded_amount, 2) : 0.0;
    }

    /** Status for display: paid links show their refund state. */
    public function displayStatus(): string
    {
        if ($this->status === 'pending' && $this->expires_at?->isPast()) {
            return 'expired';
        }
        if ($this->status === 'paid' && (float) $this->refunded_amount > 0) {
            return $this->refundable() <= 0.004 ? 'refunded' : 'part_refunded';
        }

        return $this->status;
    }

    public function statusLabel(): string
    {
        return ['refunded' => 'Refunded', 'part_refunded' => 'Partly refunded'][$this->displayStatus()] ?? self::STATUSES[$this->displayStatus()] ?? ucfirst($this->status);
    }
}
