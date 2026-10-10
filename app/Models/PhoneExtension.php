<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.7 – a PBX extension: a guest room line, an administrative (department) line or a public phone. */
class PhoneExtension extends Model
{
    use BelongsToHotel;

    public const TYPES = ['room' => 'Guest room', 'admin' => 'Administrative', 'public' => 'Public / lobby'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function calls()
    {
        return $this->hasMany(CallRecord::class);
    }
}
