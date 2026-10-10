<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * v3.2 a restaurant table reservation, a walk-in seated from the floor plan, or a waitlist entry.
 */
class TableReservation extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id', 'token_hash'];

    protected $hidden = ['token_hash'];

    /** Statuses that hold tables. */
    public const HOLDING = ['booked', 'confirmed', 'seated'];

    /** Statuses a guest / staff member can still change or cancel. */
    public const UPCOMING = ['booked', 'confirmed'];

    public const WAITING = ['waiting', 'notified'];

    public const SOURCES = ['phone' => 'Phone', 'walkin' => 'Walk-in', 'online' => 'Online', 'email' => 'E-mail', 'concierge' => 'Front desk / concierge', 'staff' => 'Staff'];

    public const OCCASIONS = ['birthday' => 'Birthday', 'anniversary' => 'Anniversary', 'business' => 'Business meal', 'date' => 'Date night', 'celebration' => 'Celebration', 'other' => 'Other'];

    public const TAGS = ['high_chair' => 'High chair', 'wheelchair' => 'Wheelchair access', 'window' => 'Window / view', 'quiet' => 'Quiet table', 'vegetarian' => 'Vegetarian', 'allergy' => 'Allergy – see notes', 'vip' => 'VIP'];

    /** Phones are stored in E.164 so repeat guests / no-show history match however the number was typed. */
    public function setPhoneAttribute($v): void
    {
        $v = trim((string) $v);
        $this->attributes['phone'] = $v === '' ? null : (\App\Services\MessageService::normalise($v) ?? mb_substr($v, 0, 30));
    }

    protected function casts(): array
    {
        return [
            'date' => 'date', 'start_at' => 'datetime', 'end_at' => 'datetime', 'tags' => 'array',
            'confirmed_at' => 'datetime', 'reminder_sent_at' => 'datetime', 'notified_at' => 'datetime', 'seated_at' => 'datetime',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'no_show_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function tables()
    {
        return $this->belongsToMany(PosTable::class, 'table_reservation_tables')->orderBy('pos_tables.id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUpcoming(): bool
    {
        return in_array($this->status, self::UPCOMING, true);
    }

    public function isWaiting(): bool
    {
        return in_array($this->status, self::WAITING, true);
    }

    public function tableLabel(): string
    {
        return $this->tables->pluck('table_no')->implode(' + ');
    }

    /** Minutes the party waited (waitlist: added → seated). */
    public function waitedMinutes(): ?int
    {
        return $this->type === 'waitlist' && $this->seated_at ? (int) round($this->created_at->diffInMinutes($this->seated_at)) : null;
    }

    public function dinedMinutes(): ?int
    {
        return $this->seated_at && $this->completed_at ? (int) round($this->seated_at->diffInMinutes($this->completed_at)) : null;
    }

    public function manageUrl(string $token): string
    {
        return route('dine.manage', $token);
    }
}
