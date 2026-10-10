<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 A green initiative / energy-saving project: business case (capex, annual saving, payback), approval and delivery. */
class EsgInitiative extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['energy' => 'Energy', 'water' => 'Water', 'waste' => 'Waste', 'carbon' => 'Carbon / refrigerants', 'procurement' => 'Sustainable procurement',
        'social' => 'Social / community', 'governance' => 'Governance'];

    public const STATUSES = ['idea' => ['Proposed', 'slate'], 'approved' => ['Approved', 'blue'], 'in_progress' => ['In progress', 'purple'], 'done' => ['Completed', 'green'],
        'rejected' => ['Rejected', 'red'], 'cancelled' => ['Cancelled', 'slate']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['capex' => 'decimal:2', 'saving_ghs' => 'decimal:2', 'saving_kwh' => 'float', 'saving_m3' => 'float', 'saving_waste_kg' => 'float', 'saving_co2_kg' => 'float',
            'start_on' => 'date', 'due_on' => 'date', 'completed_on' => 'date', 'approved_at' => 'datetime'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** simple payback in years; null when there is no saving */
    public function payback(): ?float
    {
        return (float) $this->saving_ghs > 0 ? round((float) $this->capex / (float) $this->saving_ghs, 1) : null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function isLate(): bool
    {
        return in_array($this->status, ['approved', 'in_progress'], true) && $this->due_on && $this->due_on->toDateString() < bdate()->toDateString();
    }
}
