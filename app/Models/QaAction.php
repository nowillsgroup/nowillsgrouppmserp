<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.5 A corrective action: open → in progress → done → verified by quality (never by its owner), or reopened. */
class QaAction extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open' => ['Open', 'blue'], 'in_progress' => ['In progress', 'amber'], 'done' => ['Done – to verify', 'purple'], 'verified' => ['Verified', 'green'], 'cancelled' => ['Cancelled', 'slate']];

    public const PRIORITIES = ['low' => ['Low', 'slate'], 'normal' => ['Normal', 'blue'], 'high' => ['High', 'amber'], 'critical' => ['Critical', 'red']];

    public const OPEN = ['open', 'in_progress'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function audit()
    {
        return $this->belongsTo(QaAudit::class, 'qa_audit_id');
    }

    public function item()
    {
        return $this->belongsTo(QaAuditItem::class, 'qa_audit_item_id');
    }

    public function standard()
    {
        return $this->belongsTo(QaStandard::class, 'qa_standard_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function ticket()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority][0] ?? ucfirst($this->priority);
    }

    public function priorityTone(): string
    {
        return self::PRIORITIES[$this->priority][1] ?? 'slate';
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN, true) && $this->due_date->lt(bdate()->copy()->startOfDay());
    }
}
