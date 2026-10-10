<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PosOrder extends Model
{
    use BelongsToHotel;

    /** Reasons a dish or a whole bill may be given away. */
    public const COMP_REASONS = [
        'Guest recovery' => 'Guest recovery',
        'VIP / owner guest' => 'VIP / owner guest',
        'Manager' => 'Manager',
        'Staff meal' => 'Staff meal',
        'Birthday / celebration' => 'Birthday / celebration',
        'Quality complaint' => 'Quality complaint',
        'Promotion' => 'Promotion',
    ];

    protected $table = 'pos_orders';

    protected $guarded = ['id', 'hotel_id'];

    protected static function booted(): void
    {
        // v3.2 table reservations follow the bill: settled / voided → party completed & table to clear; merged / moved → relinked
        static::updated(function (self $o) {
            if ($o->wasChanged('status')) {
                if (in_array($o->status, ['settled', 'void'], true)) {
                    \App\Services\TableBookingService::orderClosed($o);
                    $o->status === 'settled' && \App\Services\PosEvatService::onSettled($o); // v4.4 GRA E-VAT for outlet bills
                } elseif ($o->status === 'merged') {
                    \App\Services\TableBookingService::orderMerged($o);
                }
            }
            if ($o->wasChanged('pos_table_id') && in_array($o->status, ['open', 'billed'], true)) {
                \App\Services\TableBookingService::orderMoved($o, $o->getOriginal('pos_table_id'));
            }
        });
    }

    public function recalculate(): void
    {
        $items = $this->items()->where('is_cancelled', false)->where('is_pending', false)->get();
        $sub = $items->sum('amount');
        $discount = min((float) $this->discount, $sub);
        $ratio = $sub > 0 ? ($sub - $discount) / $sub : 0;
        $svc = round(($sub - $discount) * ((float) ($this->outlet->service_charge_pct ?? 0)) / 100, 2);
        $tax = round($items->sum(fn ($i) => $i->amount * $i->tax_rate / 100) * $ratio, 2);
        $delivery = $this->order_type === 'delivery' ? max(0, (float) $this->delivery_fee) : 0;
        $gross = $sub - $discount + $svc + $tax + $delivery;
        $total = round($gross);
        $this->forceFill(['subtotal' => $sub, 'discount' => $discount, 'service_charge' => $svc, 'tax' => $tax, 'round_off' => round($total - $gross, 2), 'total' => $total])->save();
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'allergens' => 'array', // v5.0 guest allergies
            'settled_at' => 'datetime',
            'delivery_fee' => 'decimal:2',
            'delivered_at' => 'datetime',
            'evat_at' => 'datetime',
            'evat_next_try_at' => 'datetime',
            'evat_retryable' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function table()
    {
        return $this->belongsTo(PosTable::class, 'pos_table_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function items()
    {
        return $this->hasMany(PosOrderItem::class);
    }

    /** Menu value given away on this bill (item comps). Whole-bill NC is the settlement, not this figure. */
    public function compValue(): float
    {
        return round((float) $this->items->where('is_cancelled', false)->where('is_pending', false)->where('is_complimentary', true)
            ->sum(fn ($l) => (float) $l->qty * (float) $l->rate), 2);
    }

    public function printJobs()
    {
        return $this->hasMany(KotPrintJob::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    /** Tender allocations for this bill. A bill may have several payment modes. */
    public function payments()
    {
        return $this->hasMany(PosPayment::class, 'pos_order_id');
    }

    public function paidAmount(): float
    {
        return round((float) $this->payments()->where('kind', 'payment')->sum('amount')
            - (float) $this->payments()->where('kind', 'refund')->sum('amount'), 2);
    }

    public function balanceDue(): float
    {
        return round(max(0, (float) $this->total - $this->paidAmount()), 2);
    }

    public function isPartiallyPaid(): bool
    {
        return $this->paidAmount() > 0.005 && $this->balanceDue() > 0.005;
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function settledBy()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function deliveryDriver()
    {
        return $this->belongsTo(Driver::class, 'delivery_driver_id');
    }

    /** v5.1 health club member whose F&B discount was applied. */
    public function clubMember()
    {
        return $this->belongsTo(ClubMember::class);
    }
}
