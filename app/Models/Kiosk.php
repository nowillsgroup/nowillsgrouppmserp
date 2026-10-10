<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.2 A lobby self-service kiosk, identified by the secret token in its start URL (paired like a TV). */
class Kiosk extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['pending' => 'Awaiting pairing', 'active' => 'Active', 'disabled' => 'Disabled'];

    /** minutes without a heartbeat before a kiosk shows as offline */
    public const OFFLINE_MIN = 5;

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['pairing_expires_at' => 'datetime', 'paired_at' => 'datetime', 'last_seen_at' => 'datetime',
            'allow_checkin' => 'boolean', 'allow_checkout' => 'boolean', 'allow_walkin' => 'boolean', 'has_encoder' => 'boolean', 'has_camera' => 'boolean'];
    }

    public function sessions()
    {
        return $this->hasMany(KioskSession::class);
    }

    public function pairedBy()
    {
        return $this->belongsTo(User::class, 'paired_by');
    }

    public function isOnline(): bool
    {
        return $this->status === 'active' && $this->last_seen_at && $this->last_seen_at->gte(now()->subMinutes(self::OFFLINE_MIN));
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->status === 'pending' => 'Awaiting pairing',
            $this->status === 'disabled' => 'Disabled',
            $this->isOnline() => 'Online',
            default => 'Offline',
        };
    }

    public function stateTone(): string
    {
        return ['Online' => 'green', 'Offline' => 'red', 'Disabled' => 'slate', 'Awaiting pairing' => 'amber'][$this->stateLabel()];
    }

    public function services(): array
    {
        return array_keys(array_filter(['Check-in' => $this->allow_checkin, 'Check-out' => $this->allow_checkout, 'Walk-in' => $this->allow_walkin]));
    }
}
