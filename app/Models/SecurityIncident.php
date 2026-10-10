<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.2 – security incident register (theft, trespass, fire alarm, medical…). */
class SecurityIncident extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const TYPES = ['theft' => 'Theft / missing property', 'trespass' => 'Trespass / unauthorised entry', 'disturbance' => 'Disturbance / fight',
        'fire_alarm' => 'Fire / fire alarm', 'medical' => 'Medical emergency', 'accident' => 'Accident / injury', 'damage' => 'Damage to property',
        'harassment' => 'Harassment / misconduct', 'suspicious' => 'Suspicious person / object', 'other' => 'Other'];

    public const SEVERITIES = ['low' => ['Low', 'slate'], 'medium' => ['Medium', 'blue'], 'high' => ['High', 'amber'], 'critical' => ['Critical', 'red']];

    public const RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public const STATUSES = ['open' => ['Open', 'red'], 'investigating' => ['Investigating', 'amber'], 'closed' => ['Closed', 'green']];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'closed_at' => 'datetime', 'injuries' => 'boolean', 'police_informed' => 'boolean', 'loss_amount' => 'decimal:2'];
    }

    public function updates()
    {
        return $this->hasMany(SecurityIncidentUpdate::class)->orderBy('id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity][0] ?? $this->severity;
    }

    public function severityTone(): string
    {
        return self::SEVERITIES[$this->severity][1] ?? 'slate';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
