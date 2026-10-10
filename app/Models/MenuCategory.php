<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MenuCategory extends Model
{
    use BelongsToHotel;

    protected $table = 'menu_categories';

    protected $guarded = ['id', 'hotel_id'];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function printer()
    {
        return $this->belongsTo(KotPrinter::class, 'kot_printer_id');
    }
}
