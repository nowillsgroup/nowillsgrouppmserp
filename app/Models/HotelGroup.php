<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelGroup extends Model
{
    protected $table = 'hotel_groups';

    protected $guarded = ['id'];

    /** v10.0 a group is the tenant account; it is never deleted, only suspended. */
    public const STATUSES = ['active' => 'Active', 'suspended' => 'Suspended'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
            'max_properties' => 'integer',
        ];
    }

    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    /** Live properties only — the ones a user may actually enter. */
    public function liveHotels(): HasMany
    {
        return $this->hasMany(Hotel::class)->where('status', 'active')->where('is_active', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('is_group_admin', true);
    }

    public function propertyEvents(): HasMany
    {
        return $this->hasMany(PropertyEvent::class)->latest('id');
    }

    /** Management company that operates the group (v10.0 group attribute, read by the group report). */
    public function managementCompanyName(): ?string
    {
        return $this->management_company ?: null;
    }

    public function isSuspended(): bool
    {
        return ($this->status ?? 'active') === 'suspended' || ! $this->is_active;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status ?? 'active'] ?? ucfirst((string) $this->status);
    }

    public function hotelCount(): int
    {
        return $this->hotels()->notArchived()->count();
    }

    public function roomCount(): int
    {
        $ids = $this->hotels()->notArchived()->pluck('id');

        // rooms carries hotel_id itself, so this is a straight aggregate, not a tenant-scoped query.
        return (int) Room::withoutGlobalScopes()->whereIn('hotel_id', $ids)->count();
    }

    /** Whether one more property fits inside the entitlement. Null cap = unlimited. */
    public function canAddProperty(): bool
    {
        if ($this->isSuspended()) {
            return false;
        }
        if ($this->max_properties === null) {
            return true;
        }

        return $this->hotels()->notArchived()->count() < $this->max_properties;
    }

    public function remainingProperties(): ?int
    {
        if ($this->max_properties === null) {
            return null;
        }

        return max(0, $this->max_properties - $this->hotels()->notArchived()->count());
    }
}
