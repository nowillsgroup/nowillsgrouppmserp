<?php

namespace App\Models\Concerns;

use App\Models\Hotel;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level multi-tenancy: every query is restricted to the current hotel and
 * hotel_id is stamped automatically on create. hotel_id is never mass-assignable.
 */
trait BelongsToHotel
{
    public static function bootBelongsToHotel(): void
    {
        static::addGlobalScope('hotel', function (Builder $q) {
            if (Tenant::check()) {
                $q->where($q->getModel()->getTable().'.hotel_id', Tenant::id());
            }
        });

        static::creating(function ($model) {
            if (empty($model->hotel_id) && Tenant::check()) {
                $model->hotel_id = Tenant::id();
            }
        });
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}
