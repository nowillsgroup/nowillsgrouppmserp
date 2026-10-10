<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.9 One period's service charge & tips distribution (draft → approved → posted to payroll). */
class ScDistribution extends Model
{
    use BelongsToHotel;

    public const BASES = ['points_days' => 'Points × days worked', 'points' => 'Points (pro-rated to days employed)', 'equal' => 'Equal share per eligible employee'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'approved_on' => 'date', 'posted_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(ScDistributionLine::class);
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function label(): string
    {
        return $this->period_start->isSameMonth($this->period_end) && $this->period_start->day === 1 && $this->period_end->isLastOfMonth()
            ? $this->period_start->format('M Y') : dmy($this->period_start).' – '.dmy($this->period_end);
    }
}
