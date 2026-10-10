<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 A leaver: termination type, statutory notice, last day, final-pay date, clearance and exit interview. */
class TalentExit extends Model
{
    use BelongsToHotel;

    public const TYPES = ['resignation' => 'Resignation', 'dismissal' => 'Dismissal – proven misconduct', 'incompetence' => 'Termination – incompetence / lacks qualification',
        'probation' => 'Not confirmed after probation', 'redundancy' => 'Redundancy', 'mutual' => 'Mutual agreement', 'medical' => 'Medically unfit', 'legal' => 'Legal restriction',
        'contract_end' => 'Fixed-term contract ended', 'retirement' => 'Retirement', 'death' => 'Death in service'];

    /** Terminations the employer initiates – the s. 63 confirmation (no unfair reason) applies. */
    public const EMPLOYER = ['dismissal', 'incompetence', 'probation', 'redundancy', 'medical', 'legal'];

    public const VOLUNTARY = ['resignation', 'retirement'];

    public const STATUSES = ['notice' => 'Working notice / clearance', 'completed' => 'Completed', 'withdrawn' => 'Withdrawn'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['notice_on' => 'date', 'last_day' => 'date', 'final_pay_due' => 'date', 'clo_notified_on' => 'date', 'completed_at' => 'datetime', 'pilon' => 'boolean',
            'fair_confirmed' => 'boolean', 'regretted' => 'boolean', 'rehire' => 'boolean', 'clearance' => 'array', 'interview' => 'array', 'pilon_amount' => 'decimal:2'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function disciplinaryCase()
    {
        return $this->belongsTo(TalentCase::class, 'case_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isVoluntary(): bool
    {
        return in_array($this->type, self::VOLUNTARY, true);
    }
}
