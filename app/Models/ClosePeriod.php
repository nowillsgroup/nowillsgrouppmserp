<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.6 One accounting month (YYYY-MM): its close checklist, four-eyes close and reopen. */
class ClosePeriod extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open' => 'Open', 'review' => 'Awaiting approval', 'closed' => 'Closed'];

    public const TONES = ['open' => 'amber', 'review' => 'blue', 'closed' => 'green'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'prepared_at' => 'datetime', 'closed_at' => 'datetime', 'reopen_requested_at' => 'datetime', 'lock_before' => 'date'];
    }

    public function getRouteKeyName(): string
    {
        return 'period';
    }

    public function tasks()
    {
        return $this->hasMany(CloseTask::class)->orderBy('sort')->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(CloseEvent::class)->orderByDesc('id');
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenRequester()
    {
        return $this->belongsTo(User::class, 'reopen_requested_by');
    }

    public function start(): Carbon
    {
        return Carbon::parse($this->period.'-01')->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->start()->endOfMonth()->startOfDay();
    }

    public function label(): string
    {
        return $this->start()->format('F Y');
    }

    public function isYearEnd(): bool
    {
        return (int) $this->fiscal_month === 12;
    }
}
