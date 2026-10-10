<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.7 A risk assessment on the 5 × 5 matrix: hazard, who is at risk, controls, initial and residual rating, review date. */
class HseRisk extends Model
{
    use BelongsToHotel;

    public const LIKELIHOOD = [1 => 'Rare', 2 => 'Unlikely', 3 => 'Possible', 4 => 'Likely', 5 => 'Almost certain'];

    public const SEVERITY = [1 => 'Negligible', 2 => 'Minor (first aid)', 3 => 'Moderate (medical / lost time)', 4 => 'Major (serious injury)', 5 => 'Catastrophic (fatality)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['review_on' => 'date', 'last_reviewed_on' => 'date', 'accepted_at' => 'datetime'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function acceptor()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function score(): int
    {
        return $this->likelihood * $this->severity;
    }

    public function residual(): int
    {
        return $this->residual_likelihood * $this->residual_severity;
    }

    /** [key, label, badge tone] for a score 1–25. */
    public static function band(int $score): array
    {
        return match (true) {
            $score >= 15 => ['very_high', 'Very high', 'red'],
            $score >= 10 => ['high', 'High', 'amber'],
            $score >= 5 => ['medium', 'Medium', 'blue'],
            default => ['low', 'Low', 'green'],
        };
    }
}
