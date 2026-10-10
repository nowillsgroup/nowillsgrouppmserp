<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    use BelongsToHotel;

    protected $table = 'outlets';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tb_enabled' => 'boolean',
            'tb_online' => 'boolean',
            'tb_settings' => 'array',
        ];
    }

    public function tables()
    {
        return $this->hasMany(PosTable::class);
    }

    public function categories()
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function orders()
    {
        return $this->hasMany(PosOrder::class);
    }

    /** v3.2 service periods (lunch, dinner…) for table reservations. */
    public function periods()
    {
        return $this->hasMany(OutletServicePeriod::class)->orderBy('start_time');
    }

    public function tableReservations()
    {
        return $this->hasMany(TableReservation::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
