<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToHotel;

    protected $table = 'stock_movements';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function departmentRef()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function storeIssue()
    {
        return $this->belongsTo(StoreIssue::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
