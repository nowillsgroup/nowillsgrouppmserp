<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.3 a chauffeur on the transport desk; a linked login sees "My trips" on the phone. */
class Driver extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['licence_until' => 'date', 'is_active' => 'boolean'];
    }

    public function trips()
    {
        return $this->hasMany(TransportTrip::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function licenceExpired($date = null): bool
    {
        return $this->licence_until && $this->licence_until->lt(\Illuminate\Support\Carbon::parse($date ?? now())->startOfDay());
    }
}
