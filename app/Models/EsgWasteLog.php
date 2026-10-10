<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 A waste consignment: stream, disposal route, weight, contractor and manifest (waste transfer note). */
class EsgWasteLog extends Model
{
    use BelongsToHotel;

    public const STREAMS = ['general' => 'General / mixed', 'food' => 'Food waste', 'paper_card' => 'Paper & cardboard', 'plastic' => 'Plastics', 'glass' => 'Glass',
        'metal' => 'Metal / cans', 'garden' => 'Garden / green', 'e_waste' => 'E-waste', 'hazardous' => 'Hazardous', 'cooking_oil' => 'Used cooking oil'];

    public const DISPOSALS = ['landfill' => 'Landfill', 'recycling' => 'Recycling', 'composting' => 'Composting', 'anaerobic' => 'Anaerobic digestion / biogas',
        'incineration' => 'Incineration', 'donation' => 'Donation / reuse', 'rendering' => 'Rendering / biodiesel'];

    /** routes that keep the waste out of landfill and incineration */
    public const DIVERTED = ['recycling', 'composting', 'anaerobic', 'donation', 'rendering'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'weight_kg' => 'decimal:2', 'cost' => 'decimal:2', 'income' => 'decimal:2', 'cancelled_at' => 'datetime'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLive($q)
    {
        return $q->whereNull('cancelled_at');
    }

    public function isDiverted(): bool
    {
        return in_array($this->disposal, self::DIVERTED, true);
    }

    public function streamLabel(): string
    {
        return self::STREAMS[$this->stream] ?? $this->stream;
    }

    public function disposalLabel(): string
    {
        return self::DISPOSALS[$this->disposal] ?? $this->disposal;
    }
}
