<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 A probation review: confirm, extend or end the employment. */
class TalentProbation extends Model
{
    use BelongsToHotel;

    public const OUTCOMES = ['confirm' => 'Confirmed', 'extend' => 'Probation extended', 'terminate' => 'Not confirmed – employment ends'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'reviewed_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
