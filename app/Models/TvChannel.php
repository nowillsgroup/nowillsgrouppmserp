<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A channel in the line-up: number, category, language and the stream (multicast udp:// / rtp:// or http(s) HLS). */
class TvChannel extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['local' => 'Ghana & local', 'news' => 'News', 'sports' => 'Sports', 'movies' => 'Movies & series', 'entertainment' => 'Entertainment',
        'kids' => 'Kids', 'music' => 'Music', 'business' => 'Business', 'documentary' => 'Documentary', 'religion' => 'Religion', 'hotel' => 'Hotel channel', 'radio' => 'Radio'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_radio' => 'boolean', 'is_active' => 'boolean'];
    }

    public function programmes()
    {
        return $this->hasMany(TvProgramme::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
