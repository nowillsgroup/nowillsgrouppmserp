<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 An initiative milestone. */
class BdMilestone extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'done_at' => 'datetime'];
    }

    public function initiative()
    {
        return $this->belongsTo(BdInitiative::class, 'initiative_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOverdue(): bool
    {
        return ! $this->done_at && $this->due_date->lt(bdate()->copy()->startOfDay());
    }
}
