<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.0 A training course: how long it lasts, how long the certificate is valid, the pass mark, who delivers it. */
class TrainingCourse extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['induction' => 'Induction', 'fire' => 'Fire safety', 'first_aid' => 'First aid', 'food_safety' => 'Food safety',
        'health_safety' => 'Health & safety', 'security' => 'Security', 'service' => 'Guest service', 'compliance' => 'Compliance & legal',
        'skills' => 'Job skills', 'leadership' => 'Leadership'];

    public const DELIVERY = ['classroom' => 'Classroom', 'on_job' => 'On the job', 'online' => 'E-learning', 'external' => 'External provider'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'duration_hours' => 'decimal:1', 'cost_per_head' => 'decimal:2'];
    }

    public function requirements()
    {
        return $this->hasMany(TrainingRequirement::class);
    }

    public function sessions()
    {
        return $this->hasMany(TrainingSession::class);
    }

    public function records()
    {
        return $this->hasMany(TrainingRecord::class);
    }

    public function sop()
    {
        return $this->belongsTo(SopDocument::class, 'sop_document_id');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    public function validityLabel(): string
    {
        if (! $this->validity_months) {
            return 'No expiry';
        }

        return $this->validity_months % 12 === 0 ? ($this->validity_months / 12).' year'.($this->validity_months === 12 ? '' : 's') : $this->validity_months.' months';
    }
}
