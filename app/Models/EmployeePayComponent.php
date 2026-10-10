<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class EmployeePayComponent extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'is_excluded' => 'boolean'];
    }

    public function component()
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
