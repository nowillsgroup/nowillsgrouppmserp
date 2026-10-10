<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ReviewTemplate extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const SENTIMENTS = ['positive' => 'Positive reviews', 'neutral' => 'Mixed reviews', 'negative' => 'Negative reviews', 'any' => 'Any review'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
