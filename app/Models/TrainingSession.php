<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.0 A scheduled delivery of a course: date, trainer, venue, enrolled staff, attendance and scores. */
class TrainingSession extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['scheduled' => ['Scheduled', 'blue'], 'completed' => ['Completed', 'green'], 'cancelled' => ['Cancelled', 'red']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'completed_at' => 'datetime', 'cost' => 'decimal:2'];
    }

    public function course()
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function records()
    {
        return $this->hasMany(TrainingRecord::class);
    }

    public function trainer()
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function trainerLabel(): string
    {
        return $this->trainer_name ?: ($this->trainer?->name ?? '—');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
