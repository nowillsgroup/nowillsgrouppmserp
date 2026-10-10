<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.6 A journal template posted automatically at the end of every due period (monthly, quarterly or yearly). */
class RecurringJournal extends Model
{
    use BelongsToHotel;

    public const FREQUENCIES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];

    public const STEP = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public const STATUSES = ['pending' => 'Awaiting approval', 'active' => 'Active', 'paused' => 'Paused', 'ended' => 'Ended', 'rejected' => 'Rejected'];

    public const TONES = ['pending' => 'amber', 'active' => 'blue', 'paused' => 'slate', 'ended' => 'green', 'rejected' => 'red'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'total' => 'decimal:2', 'auto_reverse' => 'boolean', 'approved_at' => 'datetime'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postings()
    {
        return $this->hasMany(PeriodPosting::class, 'source_id')->where('source', 'recurring')->orderByDesc('period')->orderBy('kind');
    }

    /** is the template due in this period (ignores status)? */
    public function dueIn(string $period): bool
    {
        if ($period < $this->start_period || ($this->end_period && $period > $this->end_period)) {
            return false;
        }
        $a = Carbon::parse($this->start_period.'-01');
        $b = Carbon::parse($period.'-01');
        $months = ($b->year - $a->year) * 12 + $b->month - $a->month;

        return $months % (self::STEP[$this->frequency] ?? 1) === 0;
    }

    public function nextDue(string $from): ?string
    {
        $d = Carbon::parse(max($from, $this->start_period).'-01');
        for ($i = 0; $i < 13; $i++, $d->addMonthNoOverflow()) {
            $p = $d->format('Y-m');
            if ($this->end_period && $p > $this->end_period) {
                return null;
            }
            if ($this->dueIn($p)) {
                return $p;
            }
        }

        return null;
    }
}
