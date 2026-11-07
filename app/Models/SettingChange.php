<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * One write of one setting, with the value it replaced.
 *
 * Written by App\Observers\SettingValueObserver on the Setting model, so the trail covers
 * every module without each screen having to remember. Read by
 * ConfigurationService::valueTrail() and shown on Settings › Configuration — "What it was
 * before". A credential's value is never stored here: `was_secret` marks the row and the
 * screen prints "Set — hidden" instead.
 */
class SettingChange extends Model
{
    use BelongsToHotel;

    protected $table = 'setting_changes';

    protected $guarded = ['id', 'hotel_id'];

    public const UPDATED_AT = null;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected $casts = [
        'was_secret' => 'boolean',
        'created' => 'boolean',
        'created_at' => 'datetime',
    ];
}
