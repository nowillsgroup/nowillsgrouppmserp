<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class MessageLog extends Model
{
    use BelongsToHotel, Prunable;

    protected $table = 'message_logs';

    protected $guarded = ['id', 'hotel_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function prunable()
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subYear());
    }
}
