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
            'max_users' => 'integer',
            'licence_ends_on' => 'date',
            'licensed_modules' => 'array',
            'seat_basis' => 'string',
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

    /**
     * People in the tenant: staff of its properties, its group administrators and its granted people.
     *
     * v10.3 the counting basis is part of the licence. 'active' frees a seat the moment somebody is
     * switched off; 'named' counts every account, because a named-user licence is bought per person
     * rather than per person-in-work, and switching somebody off does not give the seat back.
     */
    public function seatBasis(): string
    {
        return $this->seat_basis === 'named' ? 'named' : 'active';
    }

    public function userCount(): int
    {
        $ids = $this->hotels()->pluck('id');
        $dark = $this->darkPropertyIds();

        return User::query() // audit-ok: group seat licence count — the group's own properties only
            ->when($this->seatBasis() === 'active', fn ($q) => $q->where('is_active', true))
            ->where(function ($q) use ($ids) {
                $q->whereIn('hotel_id', $ids)->orWhere('hotel_group_id', $this->id);
            })
            // v10.6 a property licensed for no modules is closed: its people keep their accounts and
            // their history, but they are not holding a seat nobody can use.
            ->when($dark !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('hotel_id')->orWhereNotIn('hotel_id', $dark)))
            ->count();
    }

    /** Properties of this group that are licensed for no modules at all. */
    public function darkPropertyIds(): array
    {
        return $this->hotels()->get(['id', 'licensed_modules'])
            ->filter(fn (Hotel $h) => $h->licenceIsEmpty())
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** The people who would be holding a seat but for a property that is licensed for nothing. */
    public function seatsReleased(): array
    {
        $dark = $this->darkPropertyIds();
        if ($dark === []) {
            return [];
        }

        return User::query() // audit-ok: group seat licence for closed (module-less) properties of this group
            ->when($this->seatBasis() === 'active', fn ($q) => $q->where('is_active', true))
            ->whereIn('hotel_id', $dark)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** The modules this tenant has bought; null means everything. */
    public function licensedModules(): ?array
    {
        return is_array($this->licensed_modules) ? array_values($this->licensed_modules) : null;
    }

    /** v10.2 seats: a licence that names no seat cap is unlimited, exactly like the property cap. */
    public function canAddUser(): bool
    {
        if ($this->isSuspended()) {
            return false;
        }
        if ($this->max_users === null) {
            return true;
        }

        return $this->userCount() < $this->max_users;
    }

    public function remainingSeats(): ?int
    {
        if ($this->max_users === null) {
            return null;
        }

        return max(0, $this->max_users - $this->userCount());
    }

    /** Seats used beyond what was bought — shown rather than hidden when a cap is lowered. */
    public function overSeats(): int
    {
        if ($this->max_users === null) {
            return 0;
        }

        return max(0, $this->userCount() - $this->max_users);
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
