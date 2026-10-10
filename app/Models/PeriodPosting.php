<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.6 One period-end journal made from a schedule (accrual / prepayment / deferral) or a recurring journal. */
class PeriodPosting extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function accrual()
    {
        return $this->belongsTo(Accrual::class, 'source_id');
    }

    public function recurring()
    {
        return $this->belongsTo(RecurringJournal::class, 'source_id');
    }

    public function sourceLabel(): string
    {
        return $this->source === 'accrual' ? (($a = $this->accrual) ? $a->number.' · '.$a->description : 'Schedule #'.$this->source_id)
            : (($r = $this->recurring) ? $r->code.' · '.$r->name : 'Recurring #'.$this->source_id);
    }
}
