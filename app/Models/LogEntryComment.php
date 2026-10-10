<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LogEntryComment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
