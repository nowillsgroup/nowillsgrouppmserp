<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RfqLine extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:2'];
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function awardedLine()
    {
        return $this->belongsTo(RfqQuoteLine::class, 'awarded_quote_line_id');
    }
}
