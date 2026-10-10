<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexSurrenderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fc_amount' => 'decimal:2', 'cost_rate' => 'decimal:4', 'cost' => 'decimal:2', 'rate' => 'decimal:4', 'realised' => 'decimal:2'];
    }

    public function surrender()
    {
        return $this->belongsTo(ForexSurrender::class, 'forex_surrender_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
