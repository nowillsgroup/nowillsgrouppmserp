<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Requisition extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = [
        'draft' => 'Draft', 'submitted' => 'Awaiting approval', 'approved' => 'Approved – to issue', 'partial' => 'Part issued',
        'issued' => 'Issued', 'closed' => 'Closed short', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
    ];

    public const TONES = ['draft' => 'slate', 'submitted' => 'amber', 'approved' => 'blue', 'partial' => 'purple', 'issued' => 'green', 'closed' => 'slate', 'rejected' => 'red', 'cancelled' => 'slate'];

    /** Statuses in which the store can still issue against the requisition. */
    public const OPEN_FOR_ISSUE = ['approved', 'partial'];

    protected function casts(): array
    {
        return [
            'req_date' => 'date', 'required_by' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
            'closed_at' => 'datetime', 'over_budget' => 'boolean',
        ];
    }

    public function lines()
    {
        return $this->hasMany(RequisitionLine::class)->orderBy('id');
    }

    public function issues()
    {
        return $this->hasMany(StoreIssue::class)->orderBy('id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function fromStore()
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore()
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function statusBadge(): string
    {
        return '<span class="badge badge-'.(self::TONES[$this->status] ?? 'slate').'">'.e($this->statusLabel()).'</span>';
    }

    public function isTransfer(): bool
    {
        return (bool) $this->to_store_id;
    }

    public function destination(): string
    {
        return $this->toStore ? $this->toStore->name : 'Used by '.($this->department?->name ?? 'department');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isOpenForIssue(): bool
    {
        return in_array($this->status, self::OPEN_FOR_ISSUE, true);
    }

    public function issuedValue(): float
    {
        return round((float) $this->issues->sum('value'), 2);
    }

    /** Quantity still to issue across lines (approved − issued). */
    public function outstandingQty(): float
    {
        return round((float) $this->lines->sum(fn ($l) => $l->outstanding()), 2);
    }

    public function isOverdue(): bool
    {
        return $this->required_by && in_array($this->status, ['submitted', 'approved', 'partial'], true) && $this->required_by->lt(bdate());
    }

    /** Hours from submission to the first issue (turnaround), or null. */
    public function turnaroundHours(): ?float
    {
        $first = $this->issues->min('created_at');

        return $this->submitted_at && $first ? round($this->submitted_at->diffInMinutes($first) / 60, 1) : null;
    }
}
