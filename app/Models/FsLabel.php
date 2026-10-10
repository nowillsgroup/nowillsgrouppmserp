<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.0 A batch of prep / day-dot labels (what, when made, use by, allergens, who). */
class FsLabel extends Model
{
    use BelongsToHotel;

    public const STORAGE = ['chilled' => 'Chilled (0–5 °C)', 'frozen' => 'Frozen (−18 °C)', 'ambient' => 'Ambient / dry', 'hot' => 'Hot held (63 °C+)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['prepared_at' => 'datetime', 'use_by' => 'datetime', 'allergens' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
