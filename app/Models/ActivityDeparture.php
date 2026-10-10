<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.9 One run of a scheduled activity (date + time) with its seat capacity. */
class ActivityDeparture extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open' => 'Open', 'closed' => 'Closed for sale', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function bookings()
    {
        return $this->hasMany(ActivityBooking::class);
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->date->toDateString().' '.$this->time);
    }

    /** seats taken by live bookings (requested, confirmed, completed, no-show) */
    public function booked(?int $exceptId = null): int
    {
        return (int) $this->bookings()->whereIn('status', ActivityBooking::HOLDING)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->selectRaw('COALESCE(SUM(adults + children), 0) n')->value('n');
    }

    public function seatsLeft(?int $exceptId = null): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->booked($exceptId));
    }
}
