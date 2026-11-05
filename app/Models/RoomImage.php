<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomImage extends Model
{
    use BelongsToHotel;

    protected $table = 'room_images';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Web path of the stored file, for <img src>. */
    public function url(): string
    {
        return asset($this->path);
    }

    /** File name shown in registers and reports. */
    public function filename(): string
    {
        return basename((string) $this->path);
    }

    /** True when this photo is the one the desk shows for its room. */
    public function isPrimaryPhoto(): bool
    {
        return (bool) $this->is_primary && (bool) $this->is_active;
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
