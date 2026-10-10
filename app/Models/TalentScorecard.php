<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 One panel member's scores (1–5 per competency) and recommendation for an interview. */
class TalentScorecard extends Model
{
    use BelongsToHotel;

    public const RECOMMEND = ['hire' => 'Hire', 'hold' => 'Hold', 'reject' => 'Do not hire'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['scores' => 'array', 'average' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function interview()
    {
        return $this->belongsTo(TalentInterview::class, 'interview_id');
    }
}
