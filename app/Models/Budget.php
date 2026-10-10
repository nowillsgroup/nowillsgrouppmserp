<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Services\BudgetService;
use Illuminate\Database\Eloquent\Model;

/** v3.6 – a budget or re-forecast version for one fiscal year. */
class Budget extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['draft' => ['Draft', 'slate'], 'submitted' => ['Awaiting approval', 'amber'], 'approved' => ['Approved', 'green']];

    public const KINDS = ['budget' => 'Budget', 'forecast' => 'Re-forecast'];

    public const METHODS = ['blank' => 'Start blank', 'last_year' => 'Last year\'s actuals (same months)', 'run_rate' => 'Run-rate (average of the last 3 closed months)', 'copy' => 'Copy another version'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'actuals_through' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
            'growth_income' => 'float', 'growth_expense' => 'float', 'fiscal_year' => 'integer'];
    }

    public function lines()
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function stats()
    {
        return $this->hasMany(BudgetStat::class)->orderBy('month');
    }

    public function events()
    {
        return $this->hasMany(BudgetEvent::class)->orderByDesc('id');
    }

    public function parent()
    {
        return $this->belongsTo(Budget::class, 'parent_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function fyLabel(): string
    {
        return BudgetService::fyLabel($this->fiscal_year);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** fiscal months (1..12) that hold actuals and cannot be edited (re-forecasts only) */
    public function lockedMonths(): array
    {
        if ($this->kind !== 'forecast' || ! $this->actuals_through) {
            return [];
        }
        $out = [];
        foreach (BudgetService::months($this->fiscal_year) as $m => $start) {
            if ($start->copy()->endOfMonth()->lte($this->actuals_through->copy()->endOfDay())) {
                $out[] = $m;
            }
        }

        return $out;
    }
}
