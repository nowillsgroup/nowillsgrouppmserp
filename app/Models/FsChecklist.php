<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.0 HACCP checklist template (opening / closing checks, cleaning schedules, weekly deep clean …). */
class FsChecklist extends Model
{
    use BelongsToHotel;

    public const FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'is_active' => 'boolean'];
    }

    public function runs()
    {
        return $this->hasMany(FsChecklistRun::class);
    }
}
