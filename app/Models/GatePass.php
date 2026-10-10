<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.2 – authority to take hotel property out through the gate (repairs, vendor returns, hire, laundry…). */
class GatePass extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = [
        'pending' => ['Awaiting approval', 'amber'],
        'approved' => ['Approved – not yet out', 'blue'],
        'rejected' => ['Rejected', 'red'],
        'out' => ['Out', 'purple'],
        'partial' => ['Partly returned', 'amber'],
        'returned' => ['Returned', 'green'],
        'closed' => ['Closed', 'slate'],
        'cancelled' => ['Cancelled', 'slate'],
    ];

    public const TYPES = ['returnable' => 'Returnable', 'non_returnable' => 'Non-returnable'];

    public const PURPOSES = ['repair' => 'Repair / service', 'vendor_return' => 'Return to vendor', 'laundry' => 'Outside laundry', 'hire_out' => 'Hired / lent out',
        'transfer' => 'Transfer to another unit', 'scrap_sale' => 'Scrap / sale', 'event' => 'Off-site event', 'other' => 'Other'];

    /** Items are (or may be) outside the property. */
    public const AWAY = ['out', 'partial'];

    protected function casts(): array
    {
        return ['expected_return_date' => 'date', 'approved_at' => 'datetime', 'out_at' => 'datetime', 'closed_at' => 'datetime', 'overdue_alerted_on' => 'date'];
    }

    public function items()
    {
        return $this->hasMany(GatePassItem::class);
    }

    public function returns()
    {
        return $this->hasMany(GatePassReturn::class)->orderBy('returned_at');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function outUser()
    {
        return $this->belongsTo(User::class, 'out_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function isOverdue(): bool
    {
        return $this->type === 'returnable' && in_array($this->status, self::AWAY, true) && $this->expected_return_date
            && $this->expected_return_date->lt(bdate()->copy()->startOfDay());
    }

    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) $this->expected_return_date->diffInDays(bdate()->copy()->startOfDay()) : 0;
    }
}
