<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.7 A manual cash-forecast item (loan instalment, capex, owner's drawings, a known receipt …), once or repeating. */
class CashForecastLine extends Model
{
    use BelongsToHotel;

    public const FREQUENCIES = ['once' => 'Once', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    public const CATEGORIES = ['Financing', 'Capital expenditure', 'Owners', 'Taxes', 'Operating', 'Other'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'first_date' => 'date', 'until_date' => 'date', 'is_active' => 'boolean'];
    }

    /** @return array<int, string> the dates the line falls on between two dates (inclusive) */
    public function occurrences(string $from, string $to): array
    {
        $out = [];
        $d = $this->first_date->copy();
        $end = $this->until_date && $this->until_date->toDateString() < $to ? $this->until_date->toDateString() : $to;
        for ($i = 0; $i < 400 && $d->toDateString() <= $end; $i++) {
            if ($d->toDateString() >= $from) {
                $out[] = $d->toDateString();
            }
            if ($this->frequency === 'once') {
                break;
            }
            $d = $this->frequency === 'weekly' ? $d->addWeek() : $this->first_date->copy()->addMonthsNoOverflow($i + 1);
        }

        return $out;
    }

    public function signed(): float
    {
        return $this->direction === 'in' ? (float) $this->amount : -(float) $this->amount;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function parseDate(string $d): Carbon
    {
        return Carbon::parse($d);
    }
}
