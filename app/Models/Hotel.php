<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotel extends Model
{
    protected $table = 'hotels';

    protected $guarded = ['id'];

    /** v10.0 property lifecycle. A property is never deleted: it is archived, so its history survives. */
    public const STATUSES = ['draft' => 'Draft', 'active' => 'Live', 'suspended' => 'Suspended', 'archived' => 'Archived'];

    public const STATUS_TONES = ['draft' => 'slate', 'active' => 'green', 'suspended' => 'amber', 'archived' => 'red'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'is_active' => 'boolean',
            // v7.52 per-property VAT registration (Act 1151)
            'vat_registered' => 'boolean',
            'vat_registered_at' => 'date',
            'supports_e_invoicing' => 'boolean',
            // v10.0
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'opened_on' => 'date',
            'closed_on' => 'date',
            'is_head_office' => 'boolean',
            'star_rating' => 'integer',
            'sort_order' => 'integer',
            // v10.5 a property may be licensed for less than its group; null = follow the group
            'licensed_modules' => 'array',
        ];
    }

    /**
     * v10.5 the modules this property is licensed for, or null when it simply follows the group.
     *
     * A list here can only ever narrow the group's licence — LicenceService intersects the two, so
     * nothing on this screen can hand a property something its group did not buy.
     */
    public function licensedModules(): ?array
    {
        return is_array($this->licensed_modules) ? array_values(array_map('strval', $this->licensed_modules)) : null;
    }

    /** Does this property take its licence from the group as it stands? */
    public function followsGroupLicence(): bool
    {
        return $this->licensedModules() === null;
    }

    /**
     * v10.6 licensed for nothing: every screen at this property refuses, so nobody can work here.
     * A seat is a person who can work — these people stop holding one until the licence comes back.
     */
    public function licenceIsEmpty(): bool
    {
        return $this->licensedModules() === [];
    }

    public function group()
    {
        return $this->belongsTo(HotelGroup::class, 'hotel_group_id');
    }

    /** Alias used by the group reports and the management-agreement register. */
    public function hotelGroup()
    {
        return $this->group();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    /** v10.0 people explicitly granted access to this property (beyond its own staff). */
    public function propertyUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'property_user')->withPivot(['role_id', 'is_default', 'granted_by', 'last_used_at'])->withTimestamps();
    }

    public function propertyEvents(): HasMany
    {
        return $this->hasMany(PropertyEvent::class)->latest('id');
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', 'active')->where('is_active', true);
    }

    public function scopeNotArchived(Builder $q): Builder
    {
        return $q->where('status', '!=', 'archived');
    }

    public function isDraft(): bool
    {
        return ($this->status ?? 'active') === 'draft';
    }

    public function isLive(): bool
    {
        return ($this->status ?? 'active') === 'active' && (bool) $this->is_active;
    }

    public function isSuspended(): bool
    {
        return ($this->status ?? 'active') === 'suspended';
    }

    public function isArchived(): bool
    {
        return ($this->status ?? 'active') === 'archived';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status ?? 'active'] ?? ucfirst((string) $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUS_TONES[$this->status ?? 'active'] ?? 'slate';
    }

    /** A property may be worked on only while it is live. Draft is configuration, suspended and archived are read-only. */
    public function isOperable(): bool
    {
        return $this->isLive();
    }

    public function isHeadOffice(): bool
    {
        return (bool) $this->is_head_office;
    }

    /** Days between the property's business date and today — a lagging business date means an unclosed night. */
    public function businessDateLag(): int
    {
        if (! $this->business_date) {
            return 0;
        }

        // Both sides are compared in the property's own clock, not the server's.
        $today = now($this->timezone ?: config('app.timezone'))->startOfDay();

        return (int) $this->business_date->copy()->startOfDay()->diffInDays($today, false);
    }
}
