<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToHotel;

    protected $table = 'settings';

    protected $guarded = ['id', 'hotel_id'];

    public $timestamps = false;

    /** Bumped on every write so the per-request setting() cache is invalidated (matters for long-lived workers & tests). */
    public static int $version = 0;

    protected static function booted(): void
    {
        static::saved(fn () => static::$version++);
        static::deleted(fn () => static::$version++);
    }
}
