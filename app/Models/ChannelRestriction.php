<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ChannelRestriction extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'stop_sell' => 'boolean', 'closed_to_arrival' => 'boolean'];
    }

    public function summary(): string
    {
        return collect([$this->stop_sell ? 'Stop-sell' : null, $this->min_stay ? "Min stay {$this->min_stay}" : null, $this->closed_to_arrival ? 'Closed to arrival' : null])
            ->filter()->implode(' · ') ?: '—';
    }

    public function connection()
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
