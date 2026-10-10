<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.5 An event organiser (company contact or private client) who signs in to the organiser portal. */
class OrganiserUser extends Model
{
    use BelongsToHotel;

    public const ROLES = ['admin' => 'Administrator', 'planner' => 'Event planner', 'finance' => 'Finance'];

    protected $guarded = ['id', 'hotel_id', 'password', 'invite_token', 'reset_token'];

    protected $hidden = ['password', 'invite_token', 'reset_token'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'invite_expires_at' => 'datetime', 'reset_expires_at' => 'datetime', 'locked_until' => 'datetime',
            'last_login_at' => 'datetime', 'password_changed_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** planner: events / BEO / changes / guest lists; finance: money / documents; admin: everything + users. */
    public function can(string $what): bool
    {
        return match ($what) {
            'events' => in_array($this->role, ['admin', 'planner']),
            'finance' => in_array($this->role, ['admin', 'finance']),
            'users' => $this->role === 'admin',
            default => false,
        };
    }

    public function isPending(): bool
    {
        return $this->password === null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function statusLabel(): string
    {
        return ! $this->is_active ? 'Disabled' : ($this->isLocked() ? 'Locked' : ($this->isPending() ? 'Invited' : 'Active'));
    }

    public function statusBadge(): string
    {
        return ['Disabled' => 'badge-slate', 'Locked' => 'badge-red', 'Invited' => 'badge-amber', 'Active' => 'badge-green'][$this->statusLabel()];
    }
}
