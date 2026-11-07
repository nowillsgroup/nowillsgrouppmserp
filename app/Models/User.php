<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Users are NOT globally scoped (group admins may operate across hotels).
 * Always filter by hotel explicitly in user-management screens.
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'username', 'email', 'phone', 'password', 'role_id', 'is_active'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /**
     * Scope: the staff of the current hotel plus the group administrators who
     * oversee that hotel. Users are not globally scoped (see the class note),
     * so every staff picker should start from this scope.
     */
    public function scopeVisibleToHotel($q, ?int $hotelId = null)
    {
        $hotelId ??= \App\Support\Tenant::id();
        if (! $hotelId) {
            return $q; // no tenant context (console, queue): leave the query alone
        }
        $groupId = hotel()?->hotel_group_id;

        return $q->where(function ($w) use ($hotelId, $groupId) {
            $w->where('hotel_id', $hotelId);
            if ($groupId) {
                $w->orWhere(fn ($g) => $g->where('is_group_admin', true)->where('hotel_group_id', $groupId));
            }
        });
    }

    protected ?array $permissionCache = null;

    protected ?int $permissionCacheRole = null;

    protected ?\Illuminate\Support\Collection $grantedPropertyIds = null;

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_group_admin' => 'boolean',
        ];
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function group()
    {
        return $this->belongsTo(HotelGroup::class, 'hotel_group_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /** v10.0 explicit multi-property grants (beyond the user's own property and group administration). */
    public function propertyGrants()
    {
        return $this->belongsToMany(Hotel::class, 'property_user')
            ->withPivot(['role_id', 'is_default', 'granted_by', 'last_used_at'])->withTimestamps();
    }

    public function grantedPropertyIds(): \Illuminate\Support\Collection
    {
        if ($this->grantedPropertyIds === null) {
            $this->grantedPropertyIds = \Illuminate\Support\Facades\Schema::hasTable('property_user')
                ? \Illuminate\Support\Facades\DB::table('property_user')->where('user_id', $this->id)->pluck('hotel_id')->map(fn ($id) => (int) $id)
                : collect();
        }

        return $this->grantedPropertyIds;
    }

    /**
     * v10.0 the role that governs the property the user is standing in: their own role at their own
     * property, the role granted with the property for anybody else, and the base role when a grant
     * carries no override.
     */
    public function effectiveRoleId(?int $hotelId = null): ?int
    {
        $hotelId ??= \App\Support\Tenant::id();
        if (! $hotelId) {
            return $this->role_id;
        }
        if ((int) $this->hotel_id === (int) $hotelId) {
            return $this->role_id;
        }
        $pivot = $this->propertyGrants()->where('hotels.id', $hotelId)->first();
        if ($pivot && $pivot->pivot->role_id) {
            return (int) $pivot->pivot->role_id;
        }

        return $this->role_id;
    }

    public function effectiveRole(?int $hotelId = null): ?Role
    {
        $id = $this->effectiveRoleId($hotelId);
        if (! $id) {
            return $this->relationLoaded('role') ? $this->role : $this->role()->first();
        }

        return $id === (int) $this->role_id ? ($this->relationLoaded('role') ? $this->role : $this->role()->first()) : Role::find($id);
    }

    public function permissionKeys(): array
    {
        $roleId = $this->effectiveRoleId();
        if ($this->permissionCache === null || $this->permissionCacheRole !== $roleId) {
            $this->permissionCache = $roleId
                ? Permission::query()->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('permission_role.role_id', $roleId)->pluck('key')->all()
                : [];
            $this->permissionCacheRole = $roleId;
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $key): bool
    {
        // consolidated multi-property view is reserved for group administrators
        if ($key === 'group.dashboard') {
            return $this->is_group_admin && $this->hotel_group_id;
        }
        if ($this->is_super_admin || $this->is_group_admin) {
            return true;
        }

        return in_array($key, $this->permissionKeys(), true);
    }

    /**
     * Hotels this user may switch into. v10.0 defers to PropertyAccessService so the switcher,
     * SetTenant and switchHotel() can never disagree; only live properties are offered.
     */
    public function accessibleHotels()
    {
        return app(\App\Services\PropertyAccessService::class)->operable($this);
    }

    /** Every property the user may see in the administration register, including drafts and suspended ones. */
    public function accessibleProperties()
    {
        return app(\App\Services\PropertyAccessService::class)->accessible($this);
    }

    public function canEnterProperty(Hotel $hotel, bool $forSetup = false): bool
    {
        return app(\App\Services\PropertyAccessService::class)->canEnter($this, $hotel, $forSetup);
    }

    public function canAdministerProperty(Hotel $hotel): bool
    {
        return app(\App\Services\PropertyAccessService::class)->canAdminister($this, $hotel);
    }

    /**
     * True when the user governs the group account itself. A property administrator is a super admin
     * *inside their property* and nothing more: they may never edit the group, its defaults or a
     * sibling property, or v10.0 would hand the portfolio to every hotel manager.
     */
    public function governsGroup(?int $groupId = null): bool
    {
        if ($this->is_super_admin && ! $this->hotel_group_id) {
            return true; // platform administrator: no group of their own
        }

        $groupId ??= $this->hotel_group_id;

        return (bool) $this->is_group_admin && $groupId && (int) $this->hotel_group_id === (int) $groupId;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && ! empty($this->two_factor_secret);
    }

    /** Administrators = super admins, group admins or holders of the Administrator role. */
    public function isAdministrator(): bool
    {
        return $this->is_super_admin || $this->is_group_admin || $this->role?->name === 'Administrator';
    }
}
