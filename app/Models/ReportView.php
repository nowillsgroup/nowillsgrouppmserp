<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.4 A saved report: report, relative date range, chosen columns and a text filter – personal or shared, pinnable. */
class ReportView extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'columns' => 'array', 'is_shared' => 'boolean', 'is_pinned' => 'boolean', 'last_opened_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function schedules()
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function title(): string
    {
        return \App\Http\Controllers\ReportController::REPORTS[$this->report_key][1] ?? $this->report_key;
    }

    public function url(): string
    {
        return route('reports.show', [$this->report_key, 'view' => $this->id]);
    }
}
