<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A provider-neutral GPS unit assigned to one hotel vehicle. */
class VehicleTracker extends Model
{
    use BelongsToHotel;

    /** Provider is free text on the device itself; these are the common M2M families installers use. */
    public const PROVIDERS = [
        'generic' => 'Generic HTTP push (JSON / form)',
        'traccar' => 'Traccar',
        'teltonika' => 'Teltonika',
        'ruptela' => 'Ruptela',
        'concox' => 'Concox / Jimi',
        'queclink' => 'Queclink',
        'wialon' => 'Wialon / Gurtam',
        'samsara' => 'Samsara',
        'other' => 'Other / local vendor',
    ];

    protected $guarded = ['id', 'hotel_id', 'token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'last_position_at' => 'datetime', 'is_active' => 'boolean', 'stale_minutes' => 'integer'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function positions()
    {
        return $this->hasMany(VehiclePosition::class);
    }

    /** This device's own signal window, or the property default when it has none. */
    public function signalWindow(int $default): int
    {
        return $this->stale_minutes ? max(2, (int) $this->stale_minutes) : max(2, $default);
    }

    public static function issueToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function acceptsToken(string $token): bool
    {
        return $this->is_active && hash_equals((string) $this->token_hash, self::hashToken($token));
    }
}
