<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinenBatchLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2'];
    }

    public function batch()
    {
        return $this->belongsTo(LinenBatch::class, 'linen_batch_id');
    }

    public function linen()
    {
        return $this->belongsTo(LinenType::class, 'linen_type_id');
    }

    public function outstanding(): int
    {
        return max(0, (int) $this->qty_sent - (int) $this->qty_returned - (int) $this->qty_damaged - (int) $this->qty_lost);
    }
}
