<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 An appraisal cycle (e.g. mid-year review) covering a period, with self-assessment and review deadlines. */
class TalentCycle extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'self_due' => 'date', 'review_due' => 'date', 'closed_at' => 'datetime'];
    }

    public function appraisals()
    {
        return $this->hasMany(TalentAppraisal::class, 'cycle_id');
    }
}
