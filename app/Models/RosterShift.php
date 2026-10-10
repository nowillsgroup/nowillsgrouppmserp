<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/** v4.9 One employee's rostered shift (or day off) on one date. */
class RosterShift extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function template()
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    /** Minutes from start to end; an end at or before the start runs past midnight. */
    public static function span(?string $start, ?string $end): int
    {
        if (! $start || ! $end) {
            return 0;
        }
        $s = Carbon::parse('2000-01-01 '.$start);
        $e = Carbon::parse('2000-01-01 '.$end);
        if ($e->lte($s)) {
            $e->addDay();
        }

        return (int) $s->diffInMinutes($e);
    }

    public function isShift(): bool
    {
        return $this->kind === 'shift' && $this->start_time && $this->end_time;
    }

    public function startsAt(): ?Carbon
    {
        return $this->isShift() ? Carbon::parse($this->date->toDateString().' '.$this->start_time) : null;
    }

    public function endsAt(): ?Carbon
    {
        return $this->isShift() ? $this->startsAt()->addMinutes(self::span($this->start_time, $this->end_time)) : null;
    }

    public function paidMinutes(): int
    {
        return $this->isShift() ? max(0, self::span($this->start_time, $this->end_time) - (int) $this->break_minutes) : 0;
    }

    public function code(): string
    {
        return $this->kind === 'off' ? 'OFF' : ($this->template?->code ?? substr((string) $this->start_time, 0, 5));
    }

    public function label(): string
    {
        return $this->kind === 'off' ? 'Day off' : substr((string) $this->start_time, 0, 5).'–'.substr((string) $this->end_time, 0, 5);
    }
}
