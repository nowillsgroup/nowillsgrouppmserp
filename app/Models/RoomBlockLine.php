<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomBlockLine extends Model
{
    use BelongsToHotel;

    protected $table = 'room_block_lines';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['rooms' => 'integer'];
    }

    public $timestamps = false;

    public function block()
    {
        return $this->belongsTo(RoomBlock::class, 'room_block_id');
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
