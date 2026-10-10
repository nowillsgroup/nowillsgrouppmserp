<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PosTable extends Model
{
    use BelongsToHotel;

    protected $table = 'pos_tables';

    protected $guarded = ['id', 'hotel_id', 'qr_token'];

    protected $hidden = ['qr_token'];

    protected static function booted(): void
    {
        // every table gets an unguessable token for its QR menu link
        static::creating(fn (self $t) => $t->qr_token ??= Str::random(32));
    }

    public function ensureQrToken(): string
    {
        if (! $this->qr_token) {
            $this->forceFill(['qr_token' => Str::random(32)])->save();
        }

        return $this->qr_token;
    }

    public function regenerateQrToken(): string
    {
        $this->forceFill(['qr_token' => Str::random(32)])->save();

        return $this->qr_token;
    }

    public function openOrder()
    {
        // the newest *open* order – the status filter must sit inside the of-many subquery, otherwise a later settled bill hides it
        return $this->hasOne(PosOrder::class)->ofMany(['id' => 'max'], fn ($q) => $q->whereIn('status', ['open', 'billed']));
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'combinable' => 'boolean',
            'reservable' => 'boolean',
            'needs_clearing' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /** v3.2 reservations assigned to this table (joined tables share a reservation). */
    public function tableReservations()
    {
        return $this->belongsToMany(TableReservation::class, 'table_reservation_tables');
    }

    public function orders()
    {
        return $this->hasMany(PosOrder::class);
    }
}
