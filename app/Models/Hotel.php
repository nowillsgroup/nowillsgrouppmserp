<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    protected $table = 'hotels';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'is_active' => 'boolean',
            // v7.52 per-property VAT registration (Act 1151)
            'vat_registered' => 'boolean',
            'vat_registered_at' => 'date',
            'supports_e_invoicing' => 'boolean',
        ];
    }

    public function group()
    {
        return $this->belongsTo(HotelGroup::class, 'hotel_group_id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
