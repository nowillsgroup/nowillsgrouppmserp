<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class RoomOutage extends Model
{
    use BelongsToHotel;

    protected $table = 'room_outages';

    protected $guarded = ['id', 'hotel_id'];

    public const REASONS = [
        'maintenance' => 'Maintenance',
        'renovation' => 'Renovation',
        'vip' => 'VIP hold',
        'group' => 'Group hold',
        'management' => 'Management block',
        'house' => 'House use',
        'owner' => 'Owner use',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'released_at' => 'datetime',
        ];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /** Active outages that cover any night in [from, to). */
    public static function covering($from, $to, ?int $roomId = null)
    {
        return static::where('status', 'active')
            ->whereDate('starts_on', '<', Carbon::parse($to)->toDateString())
            ->whereDate('ends_on', '>', Carbon::parse($from)->toDateString())
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId));
    }
}
