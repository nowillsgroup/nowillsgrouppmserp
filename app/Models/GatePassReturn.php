<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class GatePassReturn extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime', 'lines' => 'array'];
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
