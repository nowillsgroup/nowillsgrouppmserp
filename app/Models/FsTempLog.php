<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.0 One temperature reading: equipment check, delivery receiving, cooking / reheating core temperature, or a cooling record. */
class FsTempLog extends Model
{
    use BelongsToHotel;

    public const KINDS = ['equipment' => 'Equipment check', 'receiving' => 'Delivery receiving', 'cooking' => 'Cooking (core)', 'reheating' => 'Reheating (core)', 'cooling' => 'Cooling'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'reading2_at' => 'datetime', 'reviewed_at' => 'datetime', 'in_range' => 'boolean',
            'reading_c' => 'decimal:1', 'reading2_c' => 'decimal:1', 'min_c' => 'decimal:1', 'max_c' => 'decimal:1'];
    }

    public function equipment()
    {
        return $this->belongsTo(FsEquipment::class, 'fs_equipment_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function subject(): string
    {
        return $this->equipment?->name ?? ($this->item ?: self::KINDS[$this->kind]);
    }

    /** A cooling record still waiting for its 6-hour reading. */
    public function coolingOpen(): bool
    {
        return $this->kind === 'cooling' && $this->reading2_c === null && $this->in_range;
    }
}
