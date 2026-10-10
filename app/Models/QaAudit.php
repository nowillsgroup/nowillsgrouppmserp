<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.5 One audit against a checklist: scheduled → in progress → completed (scored) or cancelled. */
class QaAudit extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['scheduled' => ['Scheduled', 'blue'], 'in_progress' => ['In progress', 'amber'], 'completed' => ['Completed', 'green'], 'cancelled' => ['Cancelled', 'slate']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['scheduled_for' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'acknowledged_at' => 'datetime', 'passed' => 'boolean',
            'pass_mark' => 'decimal:2', 'points_earned' => 'decimal:2', 'points_possible' => 'decimal:2', 'score_pct' => 'decimal:2'];
    }

    public function template()
    {
        return $this->belongsTo(QaTemplate::class, 'qa_template_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function items()
    {
        return $this->hasMany(QaAuditItem::class)->orderBy('sort');
    }

    public function actions()
    {
        return $this->hasMany(QaAction::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function typeLabel(): string
    {
        return QaTemplate::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function auditorName(): string
    {
        return $this->external_auditor ?: ($this->auditor?->name ?? '—');
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['scheduled', 'in_progress'], true) && $this->scheduled_for->lt(bdate()->copy()->startOfDay());
    }

    public function resultTone(): string
    {
        return $this->passed === null ? 'slate' : ($this->passed ? 'green' : 'red');
    }
}
