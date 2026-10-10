<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    use BelongsToHotel;

    protected $table = 'taxes';

    protected $guarded = ['id', 'hotel_id'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\TaxService::flush());
        static::deleted(fn () => \App\Services\TaxService::flush());
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
