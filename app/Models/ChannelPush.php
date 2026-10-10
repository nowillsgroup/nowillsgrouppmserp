<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ChannelPush extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date'];
    }

    public function connection()
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
