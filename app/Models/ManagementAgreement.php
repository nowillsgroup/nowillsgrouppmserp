<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.8 The hotel management agreement between the owner and the operator: fee terms and the FF&E reserve schedule. */
class ManagementAgreement extends Model
{
    use BelongsToHotel;

    public const BASES = ['agop' => 'Adjusted GOP (GOP − base fee − FF&E reserve)', 'gop' => 'Gross operating profit'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'ends_on' => 'date', 'base_fee_pct' => 'decimal:2', 'incentive_pct' => 'decimal:2', 'owner_priority' => 'decimal:2', 'ffe_schedule' => 'array'];
    }

    public function reserveAccount()
    {
        return $this->belongsTo(TreasuryAccount::class, 'reserve_account_id');
    }

    public function fees()
    {
        return $this->hasMany(OwnerFee::class);
    }

    /** covers the month starting $monthStart */
    public function covers(Carbon $monthStart): bool
    {
        return $this->effective_from->copy()->startOfMonth()->lte($monthStart) && (! $this->ends_on || $this->ends_on->gte($monthStart));
    }

    /** contract year (1-based) a month falls in */
    public function contractYear(Carbon $monthStart): int
    {
        $start = $this->effective_from->copy()->startOfMonth();

        return max(1, intdiv((int) $start->diffInMonths($monthStart->copy()->startOfMonth()), 12) + 1);
    }

    /** FF&E reserve % for a month – the last step of the schedule applies to every later year */
    public function ffePct(Carbon $monthStart): float
    {
        $s = array_values(array_filter((array) $this->ffe_schedule, fn ($v) => $v !== null && $v !== ''));
        if (! $s) {
            return 0.0;
        }

        return (float) $s[min($this->contractYear($monthStart), count($s)) - 1];
    }

    public function scheduleLabel(): string
    {
        $s = array_values((array) $this->ffe_schedule);

        return $s ? collect($s)->map(fn ($v, $i) => 'yr '.($i + 1).($i === count($s) - 1 ? '+' : '').' '.rtrim(rtrim(number_format((float) $v, 2), '0'), '.').'%')->implode(' · ') : 'none';
    }
}
