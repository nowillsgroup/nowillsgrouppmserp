<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LaundryItem extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['gents' => 'Gents', 'ladies' => 'Ladies', 'kids' => 'Kids', 'household' => 'Household'];

    public const SERVICES = ['wash' => 'Wash & iron', 'dryclean' => 'Dry clean', 'press' => 'Press only'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['wash_rate' => 'decimal:2', 'dryclean_rate' => 'decimal:2', 'press_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /** Price for a service, or null when the item isn't offered with that service. */
    public function rate(string $service): ?float
    {
        $v = $this->{$service.'_rate'} ?? null;

        return $v === null ? null : (float) $v;
    }
}
