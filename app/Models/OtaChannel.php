<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OtaChannel extends Model
{
    use BelongsToHotel;

    protected $table = 'ota_channels';

    protected $guarded = ['id', 'hotel_id', 'export_token'];

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->export_token ??= Str::random(32));
    }

    protected function casts(): array
    {
        return [ 'last_sync_at' => 'datetime', 'is_active' => 'boolean',];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function source()
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
