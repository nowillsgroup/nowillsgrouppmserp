<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class KotPrintJob extends Model
{
    use BelongsToHotel;

    protected $table = 'kot_print_jobs';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'item_ids' => 'array',
            'printed_at' => 'datetime',
            'copies' => 'integer',
        ];
    }

    public function printer()
    {
        return $this->belongsTo(KotPrinter::class, 'kot_printer_id');
    }

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
