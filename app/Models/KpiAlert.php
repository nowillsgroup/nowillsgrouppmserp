<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.4 A KPI threshold checked after every night audit (occupancy below 60 %, arrears above GH₵ 50,000 …). */
class KpiAlert extends Model
{
    use BelongsToHotel;

    public const SEVERITIES = ['info' => 'Info', 'warning' => 'Warning', 'critical' => 'Critical'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['threshold' => 'decimal:2', 'last_value' => 'decimal:2', 'channels' => 'array', 'recipient_users' => 'array', 'is_active' => 'boolean',
            'last_date' => 'date', 'last_evaluated_at' => 'datetime', 'last_triggered_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function events()
    {
        return $this->hasMany(KpiAlertEvent::class);
    }

    public function rule(): string
    {
        $m = \App\Services\KpiService::METRICS[$this->metric] ?? [$this->metric, 'num', 'now'];

        return $m[0].($m[2] === 'flash' ? ' ('.($this->period === 'mtd' ? 'month to date' : 'day').')' : '').' '.($this->operator === 'below' ? '<' : '>').' '
            .\App\Services\KpiService::format((float) $this->threshold, $m[1]);
    }
}
