<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ReservationRoom extends Model
{
    use BelongsToHotel;

    protected $table = 'reservation_rooms';

    protected $guarded = ['id', 'hotel_id'];

    /** v7.1 the TV purchase PIN never leaves the model in arrays / JSON (API, webhooks, logs) */
    protected $hidden = ['tv_pin'];

    public function getNightsAttribute(): int
    {
        return max(1, $this->arrival_date->diffInDays($this->departure_date));
    }

    public function nightlyTotal(): float
    {
        return (float) $this->rate + (float) $this->extra_charge;
    }

    protected function casts(): array
    {
        return [
            'arrival_date' => 'date',
            'grc_signed_at' => 'datetime',
            'portal_seen_at' => 'datetime',
            'departure_date' => 'date',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'rate' => 'decimal:2',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function folio()
    {
        return $this->hasOne(Folio::class);
    }

    public function moves()
    {
        return $this->hasMany(RoomMove::class);
    }

    /** v5.5 nightly package entitlements (breakfast covers, dinner allowance …). */
    public function entitlements()
    {
        return $this->hasMany(PackageEntitlement::class);
    }

    public function fixedCharges()
    {
        return $this->hasMany(StayFixedCharge::class, 'reservation_room_id');
    }

    public function documents()
    {
        return $this->hasMany(GuestDocument::class, 'reservation_room_id');
    }

    public function folios()
    {
        return $this->hasMany(Folio::class);
    }

    /** v3.4 Form C (FRRO) records for the foreign nationals in this room. */
    public function formCs()
    {
        return $this->hasMany(FormCRecord::class)->orderBy('is_companion')->orderBy('id');
    }

    public function guestRequests()
    {
        return $this->hasMany(GuestRequest::class);
    }

    /** v1.7: the guest's private portal link (token created on first use). */
    public function portalUrl(): string
    {
        if (! $this->portal_token) {
            $this->forceFill(['portal_token' => \Illuminate\Support\Str::random(40)])->save();
        }

        return route('portal.home', $this->portal_token);
    }

    /** upcoming | inhouse | departed (read-only for N days) | closed */
    public function portalState(): string
    {
        return match (true) {
            $this->status === 'checked_in' => 'inhouse',
            $this->status === 'reserved' && in_array($this->reservation?->status, ['confirmed', 'tentative']) => 'upcoming',
            $this->status === 'checked_out' && $this->checked_out_at && $this->checked_out_at->gt(now()->subDays(max(0, (int) setting('portal_days_after', 2)))) => 'departed',
            default => 'closed',
        };
    }
}
