<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BanquetFunction extends Model
{
    use BelongsToHotel;

    public const SETUPS = ['theatre' => 'Theatre', 'classroom' => 'Classroom', 'banquet' => 'Banquet (rounds)', 'ushape' => 'U-shape', 'cocktail' => 'Cocktail / standing', 'boardroom' => 'Boardroom'];

    /** The dietary needs a function can carry, each with a count of covers. */
    public const DIETS = ['vegetarian' => 'Vegetarian', 'vegan' => 'Vegan', 'gluten_free' => 'Gluten-free',
        'coeliac' => 'Coeliac', 'nut_allergy' => 'Nut allergy', 'other' => 'Other'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'rate_per_pax' => 'float', 'hall_rate' => 'float', 'fnb_tax_rate' => 'float', 'hall_tax_rate' => 'float',
            'dietary' => 'array'];
    }

    public function event()
    {
        return $this->belongsTo(BanquetEvent::class, 'banquet_event_id');
    }

    public function hall()
    {
        return $this->belongsTo(BanquetHall::class, 'banquet_hall_id');
    }

    public function package()
    {
        return $this->belongsTo(BanquetPackage::class, 'banquet_package_id');
    }

    public function items()
    {
        return $this->hasMany(BanquetEventItem::class)->orderBy('id');
    }

    /** Pax the client pays for: the guarantee (or the estimate before one is given), or more if more turned up. */
    /**
     * The dietary rows to print, labelled, with anything unrecognised or empty dropped.
     *
     * @return list<array{label: string, covers: int, dish: string}>
     */
    public function dietaryLines(): array
    {
        $out = [];
        foreach ((array) ($this->dietary ?? []) as $row) {
            $key = (string) ($row['diet'] ?? '');
            $covers = (int) ($row['covers'] ?? 0);
            if (! array_key_exists($key, self::DIETS) || $covers <= 0) {
                continue;
            }
            $out[] = ['label' => self::DIETS[$key], 'covers' => $covers, 'dish' => trim((string) ($row['dish'] ?? ''))];
        }

        return $out;
    }

    /** Covers the kitchen is cooking to a different plate for. */
    public function dietaryCovers(): int
    {
        return array_sum(array_column($this->dietaryLines(), 'covers'));
    }

    public function billablePax(): int
    {
        return max((int) ($this->guaranteed_pax ?? $this->expected_pax), (int) ($this->actual_pax ?? 0));
    }

    public function fnbAmount(): float
    {
        return round($this->billablePax() * $this->rate_per_pax, 2);
    }

    public function extrasAmount(): float
    {
        return round($this->items->sum(fn ($i) => $i->amount()), 2);
    }

    public function net(): float
    {
        return round($this->fnbAmount() + $this->hall_rate + $this->extrasAmount(), 2);
    }

    public function tax(): float
    {
        return round($this->fnbAmount() * $this->fnb_tax_rate / 100 + $this->hall_rate * $this->hall_tax_rate / 100
            + $this->items->sum(fn ($i) => $i->amount() * $i->tax_rate / 100), 2);
    }

    public function hours(): float
    {
        return round((strtotime($this->end_time) - strtotime($this->start_time)) / 3600, 2);
    }

    public function timeRange(): string
    {
        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }

    /** Last day the guarantee can still be lowered. */
    public function guaranteeCutoff(): Carbon
    {
        return $this->date->copy()->subDays((int) setting('banquet_guarantee_days', '3'));
    }

    public function guaranteeLocked(): bool
    {
        return bdate()->gt($this->guaranteeCutoff()) || bdate()->eq($this->guaranteeCutoff());
    }
}
