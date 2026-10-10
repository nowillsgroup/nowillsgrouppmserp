<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use BelongsToHotel;

    protected $table = 'email_logs';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
