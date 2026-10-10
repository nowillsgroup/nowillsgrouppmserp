<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 A disciplinary case (query → reply → hearing → sanction) or an employee grievance. */
class TalentCase extends Model
{
    use BelongsToHotel;

    public const DISCIPLINE = ['lateness' => 'Lateness', 'absence' => 'Absence without leave', 'insubordination' => 'Insubordination', 'negligence' => 'Negligence / poor work',
        'guest' => 'Guest complaint / rudeness', 'safety' => 'Safety or hygiene breach', 'sleeping' => 'Sleeping on duty', 'intoxication' => 'Alcohol / drugs at work',
        'dishonesty' => 'Dishonesty / fraud', 'theft' => 'Theft', 'harassment' => 'Harassment', 'violence' => 'Fighting / violence', 'other' => 'Other misconduct'];

    public const GRIEVANCE = ['pay' => 'Pay & allowances', 'hours' => 'Hours, rosters & leave', 'conditions' => 'Working conditions', 'manager' => 'Treatment by a manager',
        'bullying' => 'Bullying', 'discrimination' => 'Discrimination', 'sexual_harassment' => 'Sexual harassment', 'other' => 'Other'];

    public const SEVERITY = ['minor' => 'Minor', 'major' => 'Major', 'gross' => 'Gross misconduct'];

    public const OUTCOMES = ['none' => 'No action', 'counselling' => 'Counselling', 'verbal' => 'Verbal warning', 'written' => 'Written warning', 'final' => 'Final written warning',
        'suspension' => 'Suspension', 'dismissal' => 'Dismissal'];

    public const STATUSES = ['open' => 'Open', 'query' => 'Query issued', 'hearing' => 'Hearing', 'decided' => 'Decided', 'investigating' => 'Investigating', 'resolved' => 'Resolved',
        'closed' => 'Closed', 'withdrawn' => 'Withdrawn'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['incident_on' => 'date', 'query_issued_on' => 'date', 'reply_due' => 'date', 'replied_on' => 'date', 'hearing_at' => 'datetime', 'outcome_on' => 'date',
            'expires_on' => 'date', 'closed_at' => 'datetime', 'confidential' => 'boolean', 'hearing_held' => 'boolean', 'suspension_paid' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function exitRecord()
    {
        return $this->hasOne(TalentExit::class, 'case_id');
    }

    public function categoryLabel(): string
    {
        return ($this->kind === 'grievance' ? self::GRIEVANCE : self::DISCIPLINE)[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function outcomeLabel(): ?string
    {
        return $this->outcome ? (self::OUTCOMES[$this->outcome] ?? $this->outcome) : null;
    }

    /** A warning still on file (not expired) on the given date. */
    public function isActiveWarning(?string $on = null): bool
    {
        $on ??= bdate()->toDateString();

        return in_array($this->outcome, ['verbal', 'written', 'final'], true) && $this->expires_on && $this->expires_on->toDateString() >= $on;
    }
}
