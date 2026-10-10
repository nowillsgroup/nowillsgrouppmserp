<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class SpaService extends Model
{
    use BelongsToHotel;

    protected $table = 'spa_services';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price' => 'decimal:2'];
    }

    public const CATEGORIES = ['spa' => 'Spa & massage', 'salon' => 'Salon', 'fitness' => 'Fitness', 'activity' => 'Activity', 'tour' => 'Tour / excursion'];
}
