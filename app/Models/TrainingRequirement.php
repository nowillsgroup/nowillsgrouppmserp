<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.0 A training-matrix rule: this course is required for a department and / or job title (or everyone). */
class TrainingRequirement extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public function course()
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function applies(Employee $e): bool
    {
        if ($this->department_id && (int) $this->department_id !== (int) $e->department_id) {
            return false;
        }
        if ($this->designation && mb_strtolower(trim((string) $e->designation)) !== mb_strtolower(trim($this->designation))) {
            return false;
        }

        return true;
    }

    public function label(): string
    {
        $p = array_filter([$this->department?->name, $this->designation]);

        return $p ? implode(' · ', $p) : 'All staff';
    }
}
