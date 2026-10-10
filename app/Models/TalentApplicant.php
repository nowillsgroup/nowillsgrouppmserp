<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 A candidate for a vacancy, moving through screening → interview → offer → hired (or rejected / withdrawn). */
class TalentApplicant extends Model
{
    use BelongsToHotel;

    public const STAGES = ['applied' => 'Applied', 'screening' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];

    public const OPEN = ['applied', 'screening', 'interview', 'offer'];

    public const SOURCES = ['careers' => 'Careers page', 'referral' => 'Staff referral', 'agency' => 'Agency', 'walk_in' => 'Walk-in / CV drop', 'job_board' => 'Job board / social', 'internal' => 'Internal candidate'];

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['cv_path'];

    protected function casts(): array
    {
        return ['stage_changed_at' => 'datetime', 'consent_at' => 'datetime', 'anonymised_at' => 'datetime', 'experience_years' => 'decimal:1', 'expected_salary' => 'decimal:2'];
    }

    public function vacancy()
    {
        return $this->belongsTo(TalentVacancy::class, 'vacancy_id');
    }

    public function interviews()
    {
        return $this->hasMany(TalentInterview::class, 'applicant_id')->orderBy('scheduled_at');
    }

    public function offers()
    {
        return $this->hasMany(TalentOffer::class, 'applicant_id')->orderByDesc('id');
    }

    public function referrer()
    {
        return $this->belongsTo(Employee::class, 'referred_by_employee_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'hired_employee_id');
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN, true);
    }
}
