<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A programme-guide entry (imported from XMLTV or entered by hand). */
class TvProgramme extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function channel()
    {
        return $this->belongsTo(TvChannel::class, 'tv_channel_id');
    }
}
