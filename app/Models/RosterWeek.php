<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.9 Publication state of one department's roster for one week (Monday start). */
class RosterWeek extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'published_at' => 'datetime', 'changed_at' => 'datetime'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function state(): string
    {
        return ! $this->published_at ? 'draft' : ($this->changed_at ? 'changed' : 'published'); // publish() clears changed_at
    }
}
