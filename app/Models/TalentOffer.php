<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.9 A job offer: salary, start date, probation; above the vacancy range it waits for approval before it is issued. */
class TalentOffer extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['pending' => 'Awaiting approval', 'issued' => 'Issued', 'accepted' => 'Accepted', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'respond_by' => 'date', 'approved_at' => 'datetime', 'responded_at' => 'datetime', 'above_range' => 'boolean', 'salary' => 'decimal:2'];
    }

    public function applicant()
    {
        return $this->belongsTo(TalentApplicant::class, 'applicant_id');
    }

    public function vacancy()
    {
        return $this->belongsTo(TalentVacancy::class, 'vacancy_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
