<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.0 One employee on one course: enrolled on a session, or the outcome (passed / failed / no-show), external or exempt. */
class TrainingRecord extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['enrolled' => ['Enrolled', 'blue'], 'passed' => ['Passed', 'green'], 'failed' => ['Failed', 'red'], 'no_show' => ['No-show', 'amber'], 'exempt' => ['Exempt', 'purple']];

    public const SOURCES = ['session' => 'In-house session', 'external' => 'External / previous employer', 'exempt' => 'Exemption'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['completed_on' => 'date', 'expires_on' => 'date', 'score' => 'decimal:1', 'cost' => 'decimal:2'];
    }

    public function course()
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function session()
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** counts towards the matrix: passed (or exempt) and not expired on $on */
    public function validOn(string $on): bool
    {
        return in_array($this->status, ['passed', 'exempt'], true) && (! $this->expires_on || $this->expires_on->toDateString() >= $on);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
