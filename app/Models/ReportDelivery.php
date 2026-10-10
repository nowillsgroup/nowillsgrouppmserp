<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.4 One run of a scheduled (or sent-now) report and who received it. */
class ReportDelivery extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date'];
    }

    public function schedule()
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
