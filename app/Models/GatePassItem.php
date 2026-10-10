<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatePassItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'returned_qty' => 'decimal:2', 'est_value' => 'decimal:2'];
    }

    public function pass()
    {
        return $this->belongsTo(GatePass::class, 'gate_pass_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function outstanding(): float
    {
        return round((float) $this->quantity - (float) $this->returned_qty, 2);
    }
}
