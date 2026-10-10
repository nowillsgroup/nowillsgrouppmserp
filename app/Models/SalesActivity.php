<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.1 A sales call, visit, site inspection, meeting, e-mail, entertainment or task – planned, done or cancelled. */
class SalesActivity extends Model
{
    use BelongsToHotel;

    public const TYPES = [
        'call' => ['Phone call', 'phone'], 'visit' => ['Sales visit', 'car'], 'site_inspection' => ['Site inspection', 'hotel'],
        'meeting' => ['Meeting', 'users'], 'email' => ['E-mail / proposal', 'mail'], 'entertainment' => ['Entertainment', 'coffee'],
        'task' => ['Task / follow-up', 'check'],
    ];

    public const STATUSES = ['planned' => ['Planned', 'blue'], 'done' => ['Done', 'green'], 'cancelled' => ['Cancelled', 'slate']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime', 'cost' => 'decimal:2'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function contact()
    {
        return $this->belongsTo(SalesContact::class, 'sales_contact_id');
    }

    public function opportunity()
    {
        return $this->belongsTo(SalesOpportunity::class, 'sales_opportunity_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst($this->type);
    }

    public function typeIcon(): string
    {
        return self::TYPES[$this->type][1] ?? 'calendar';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    /** planned and due before the start of the business day */
    public function isOverdue(): bool
    {
        return $this->status === 'planned' && $this->due_at->toDateString() < bdate()->toDateString();
    }
}
