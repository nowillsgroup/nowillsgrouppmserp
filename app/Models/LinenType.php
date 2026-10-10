<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LinenType extends Model
{
    use BelongsToHotel;

    /** Stock locations, in the order linen travels. */
    public const LOCATIONS = ['store' => 'Linen room (clean)', 'floor' => 'On floors / in use', 'soiled' => 'Soiled (to send)', 'laundry' => 'At laundry'];

    protected $guarded = ['id', 'hotel_id', 'qty_store', 'qty_floor', 'qty_soiled', 'qty_laundry'];

    protected function casts(): array
    {
        return ['per_room' => 'decimal:2', 'unit_cost' => 'decimal:2', 'wash_rate' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function movements()
    {
        return $this->hasMany(LinenMovement::class);
    }

    public function total(): int
    {
        return (int) ($this->qty_store + $this->qty_floor + $this->qty_soiled + $this->qty_laundry);
    }

    /** Par stock: fixed override, else rooms × pieces per room × par levels (e.g. 4 = one in the room, one in the linen room, two in the laundry cycle). */
    public function par(?int $rooms = null, ?float $levels = null): int
    {
        if ($this->par_override !== null) {
            return (int) $this->par_override;
        }
        $rooms ??= Room::count();
        $levels ??= (float) setting('linen_par_levels', '4');

        return (int) ceil($rooms * (float) $this->per_room * $levels);
    }
}
