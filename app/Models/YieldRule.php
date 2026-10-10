<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Dynamic-pricing rule: when every condition holds for a room type on a stay date, the rate is adjusted.
 * Conditions left empty always match. Matching rules apply cumulatively in priority order.
 */
class YieldRule extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'is_active' => 'boolean', 'adjust_value' => 'decimal:2', 'room_type_id' => 'integer',
            'occ_min' => 'integer', 'occ_max' => 'integer', 'lead_min' => 'integer', 'lead_max' => 'integer', 'min_stay' => 'integer', 'priority' => 'integer'];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function dayList(): array
    {
        return $this->days ? array_map('intval', explode(',', $this->days)) : [];
    }

    /** Does the rule apply to this type/date given lead time (days) and occupancy % (hotel / type)? */
    public function matches(int $typeId, Carbon $date, int $lead, int $occHotel, int $occType): bool
    {
        if ($this->room_type_id && $this->room_type_id !== $typeId) {
            return false;
        }
        if (($this->date_from && $date->lt($this->date_from)) || ($this->date_to && $date->gt($this->date_to))) {
            return false;
        }
        if ($this->days && ! in_array($date->dayOfWeekIso, $this->dayList(), true)) {
            return false;
        }
        if (($this->lead_min !== null && $lead < $this->lead_min) || ($this->lead_max !== null && $lead > $this->lead_max)) {
            return false;
        }
        $occ = $this->basis === 'type' ? $occType : $occHotel;

        return ! (($this->occ_min !== null && $occ < $this->occ_min) || ($this->occ_max !== null && $occ > $this->occ_max));
    }

    public function apply(float $rate): float
    {
        return $this->adjust_type === 'amount' ? $rate + (float) $this->adjust_value : $rate * (1 + (float) $this->adjust_value / 100);
    }

    public function adjustmentLabel(): string
    {
        $v = (float) $this->adjust_value;
        $sign = $v >= 0 ? '+' : '−';

        return $this->adjust_type === 'amount' ? $sign.money(abs($v)) : $sign.rtrim(rtrim(number_format(abs($v), 2), '0'), '.').'%';
    }

    /** Human summary of the conditions. */
    public function conditionLabel(): string
    {
        $c = [];
        if ($this->occ_min !== null || $this->occ_max !== null) {
            $c[] = ($this->basis === 'type' ? 'room-type' : 'hotel').' occupancy '.($this->occ_min ?? 0).'–'.($this->occ_max ?? 100).'%';
        }
        if ($this->lead_min !== null || $this->lead_max !== null) {
            $c[] = match (true) {
                $this->lead_min === null => "booked ≤ {$this->lead_max} days ahead",
                $this->lead_max === null => "booked ≥ {$this->lead_min} days ahead",
                default => "booked {$this->lead_min}–{$this->lead_max} days ahead",
            };
        }
        if ($this->days) {
            $c[] = implode('/', array_map(fn ($d) => self::DAYS[$d] ?? $d, $this->dayList())).' nights';
        }
        if ($this->date_from || $this->date_to) {
            $c[] = ($this->date_from?->format('d M') ?? '…').' – '.($this->date_to?->format('d M Y') ?? '…');
        }

        return $c ? implode(' · ', $c) : 'always';
    }
}
