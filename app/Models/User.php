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

    protected ?array $permissionCache = null;

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

    public function permissionKeys(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = $this->role_id
                ? Permission::query()->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('permission_role.role_id', $this->role_id)->pluck('key')->all()
                : [];
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

    /** Hotels this user may switch into. */
    public function accessibleHotels()
    {
        if ($this->is_group_admin && $this->hotel_group_id) {
            return Hotel::where('hotel_group_id', $this->hotel_group_id)->where('is_active', true)->orderBy('name')->get();
        }

        return Hotel::whereKey($this->hotel_id)->get();
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
