<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 One click on a tracked link (device, referring site, keyed IP hash – never the raw IP). */
class DmLinkClick extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['clicked_at' => 'datetime'];
    }

    public function link()
    {
        return $this->belongsTo(DmLink::class, 'link_id');
    }
}
