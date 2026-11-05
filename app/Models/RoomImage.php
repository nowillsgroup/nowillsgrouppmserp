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

    /** Where the card-sized copy of a stored photo lives, if the store writes one. */
    public static function thumbPath(string $path): ?string
    {
        return preg_match('/\.jpe?g$/i', $path) ? preg_replace('/\.jpe?g$/i', '-thumb.jpg', $path) : null;
    }

    /**
     * Web path of the stored file. `$variant = 'thumb'` asks for the card-sized copy
     * and falls back to the photo itself when there is none (a photo stored before
     * thumbnails existed, or a host without GD).
     */
    public function url(?string $variant = null): string
    {
        if ($variant === 'thumb') {
            $thumb = self::thumbPath((string) $this->path);
            if ($thumb && is_file(public_path($thumb))) {
                return asset($thumb);
            }
        }

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
