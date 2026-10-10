<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 An employee's appraisal in a cycle: weighted goals and competencies rated by the employee and the manager. */
class TalentAppraisal extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['self' => 'Awaiting self-assessment', 'review' => 'Awaiting manager review', 'hr' => 'Awaiting HR sign-off', 'signed' => 'Signed off'];

    public const BANDS = [1 => 'Unsatisfactory', 2 => 'Needs improvement', 3 => 'Meets expectations', 4 => 'Exceeds expectations', 5 => 'Outstanding'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['goals' => 'array', 'competencies' => 'array', 'self_at' => 'datetime', 'reviewed_at' => 'datetime', 'signed_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'pip' => 'boolean', 'overall' => 'decimal:2', 'goals_score' => 'decimal:2', 'comp_score' => 'decimal:2'];
    }

    public function cycle()
    {
        return $this->belongsTo(TalentCycle::class, 'cycle_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function bandLabel(): ?string
    {
        return $this->band ? self::BANDS[$this->band] : null;
    }
}
