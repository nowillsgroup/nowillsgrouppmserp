<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v7.40 An HR announcement seen on the staff self-service feed. */
class HrAnnouncement extends Model
{
    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'publish_at' => 'datetime'];
    }

    /** scope: visible to one employee (their department, or staff/all audiences) */
    public function scopeVisibleTo($query, Employee $e)
    {
        return $query->where('publish_at', '<=', now())
            ->where(function ($q) use ($e) {
                $q->where('audience', 'all')
                    ->orWhere('audience', 'staff')
                    ->orWhere(fn ($w) => $w->where('audience', 'department')->where('department_id', $e->department_id));
            });
    }
}
