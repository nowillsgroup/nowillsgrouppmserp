<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 A vacancy (manpower requisition): approved headcount for a post, salary range, the careers-page advert. */
class TalentVacancy extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['draft' => 'Draft', 'pending' => 'Awaiting approval', 'open' => 'Open', 'on_hold' => 'On hold', 'filled' => 'Filled', 'cancelled' => 'Cancelled'];

    public const REASONS = ['new' => 'New post', 'replacement' => 'Replacement', 'seasonal' => 'Seasonal / peak'];

    public const TYPES = ['permanent' => 'Permanent (monthly pay)', 'fixed_term' => 'Fixed-term (monthly pay)', 'weekly' => 'Weekly-paid', 'casual' => 'Casual (daily rate)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['closing_on' => 'date', 'filled_on' => 'date', 'approved_at' => 'datetime', 'is_public' => 'boolean', 'salary_min' => 'decimal:2', 'salary_max' => 'decimal:2'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function applicants()
    {
        return $this->hasMany(TalentApplicant::class, 'vacancy_id');
    }

    public function replaces()
    {
        return $this->belongsTo(Employee::class, 'replaces_employee_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->employment_type] ?? $this->employment_type;
    }

    public function remaining(): int
    {
        return max(0, $this->headcount - $this->filled);
    }

    public function range(): string
    {
        return money($this->salary_min).' – '.money($this->salary_max);
    }
}
