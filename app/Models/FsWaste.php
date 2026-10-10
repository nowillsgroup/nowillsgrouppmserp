<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.0 Food waste record. Raw items and prepared dishes (by recipe) leave stock as "wastage"; plate waste is recorded for analysis only. */
class FsWaste extends Model
{
    use BelongsToHotel;

    protected $table = 'fs_waste';

    public const SOURCES = ['raw' => 'Raw ingredient / stock item', 'dish' => 'Prepared dish (not served)', 'plate' => 'Plate waste (returned by guest)'];

    public const REASONS = ['spoiled' => 'Spoiled / gone off', 'expired' => 'Past use-by date', 'overproduction' => 'Over-production / buffet leftover', 'temperature' => 'Temperature breach', 'contaminated' => 'Contaminated / dropped', 'quality' => 'Quality reject / remake', 'plate' => 'Left on plate', 'returned' => 'Returned by guest'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cancelled_at' => 'datetime', 'qty' => 'decimal:3', 'cost' => 'decimal:2'];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function name(): string
    {
        return $this->source === 'raw' ? (string) $this->item?->name : (string) $this->menuItem?->name;
    }
}
