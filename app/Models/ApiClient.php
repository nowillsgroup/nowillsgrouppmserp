<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.0 – an integration partner / app holding a bearer token for the REST API. */
class ApiClient extends Model
{
    use BelongsToHotel;

    /** scope => [label, group] */
    public const SCOPES = [
        'availability:read' => ['Availability by room type and date', 'Inventory'],
        'rates:read' => ['Rates and restrictions by room type and date', 'Inventory'],
        'reservations:read' => ['List and read reservations', 'Reservations'],
        'reservations:write' => ['Create and cancel reservations', 'Reservations'],
        'guests:read' => ['Look up guest profiles (name and contact details)', 'Guests'],
        'folios:read' => ['Read a reservation\'s folio (charges, payments, balance)', 'Guests'],
        'rooms:read' => ['Room list with occupancy and housekeeping status', 'Rooms'],
        'housekeeping:write' => ['Set a room\'s housekeeping status', 'Rooms'],
        'reports:read' => ['Daily statistics from the night audit (occupancy, ADR, RevPAR, revenue)', 'Reports'],
    ];

    /** one-click scope sets in the create dialog */
    public const PRESETS = [
        'booking' => ['Booking partner / website', ['availability:read', 'rates:read', 'reservations:read', 'reservations:write']],
        'readonly' => ['Read-only (everything)', ['availability:read', 'rates:read', 'reservations:read', 'guests:read', 'folios:read', 'rooms:read', 'reports:read']],
        'housekeeping' => ['Housekeeping / facility app', ['rooms:read', 'housekeeping:write']],
        'bi' => ['BI / revenue management', ['availability:read', 'rates:read', 'reservations:read', 'reports:read']],
        'guestapp' => ['Guest app / messaging', ['reservations:read', 'guests:read', 'folios:read']],
    ];

    protected $guarded = ['id', 'hotel_id', 'token_hash'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_on' => 'date', 'is_active' => 'boolean', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime',
            'rotated_at' => 'datetime', 'rate_limit' => 'integer'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs()
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function state(): string
    {
        if ($this->revoked_at) {
            return 'revoked';
        }
        if ($this->expires_on && $this->expires_on->lt(bdate())) {
            return 'expired';
        }

        return $this->is_active ? 'active' : 'paused';
    }

    public function stateColor(): string
    {
        return ['active' => 'green', 'paused' => 'amber', 'expired' => 'slate', 'revoked' => 'red'][$this->state()];
    }

    public function maskedToken(): string
    {
        return 'nwp_'.$this->token_prefix.'_••••••••';
    }
}
