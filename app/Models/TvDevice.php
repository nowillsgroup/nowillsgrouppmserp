<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 An in-room TV or a public screen (lobby / restaurant signage), identified by the secret token in its start URL. */
class TvDevice extends Model
{
    use BelongsToHotel;

    public const KINDS = ['room' => 'Guest room TV', 'signage' => 'Public screen (signage)'];

    public const PLATFORMS = ['samsung' => 'Samsung (Tizen hotel mode)', 'lg' => 'LG (webOS Pro:Centric)', 'android' => 'Android / Google TV', 'philips' => 'Philips MediaSuite',
        'stb' => 'Set-top box', 'browser' => 'Web browser / PC', 'other' => 'Other'];

    public const STATUSES = ['pending' => 'Awaiting pairing', 'active' => 'Active', 'disabled' => 'Disabled'];

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['pairing_expires_at' => 'datetime', 'paired_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function pairedBy()
    {
        return $this->belongsTo(User::class, 'paired_by');
    }

    public function sessions()
    {
        return $this->hasMany(TvSession::class);
    }

    public function commands()
    {
        return $this->hasMany(TvCommand::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'active' && $this->last_seen_at && $this->last_seen_at->gte(now()->subMinutes(\App\Services\IptvService::cfg()['offline_min']));
    }

    public function place(): string
    {
        return $this->kind === 'room' ? ($this->room ? 'Room '.$this->room->room_no : 'No room') : ($this->location ?: 'Public area');
    }

    public function stateLabel(): string
    {
        return match (true) {
            $this->status === 'pending' => 'Awaiting pairing',
            $this->status === 'disabled' => 'Disabled',
            ! $this->last_seen_at => 'Never connected',
            $this->isOnline() => 'Online',
            default => 'Offline',
        };
    }

    public function stateTone(): string
    {
        return match ($this->stateLabel()) { 'Online' => 'green', 'Offline' => 'red', 'Awaiting pairing' => 'amber', default => 'slate' };
    }

    public function url(): string
    {
        return route('tv.app', $this->token);
    }
}
