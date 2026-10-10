<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.4 A report e-mailed automatically – daily, weekly, monthly or straight after the night audit. */
class ReportSchedule extends Model
{
    use BelongsToHotel;

    public const FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'after_audit' => 'After the night audit'];

    public const FORMATS = ['both' => 'Table in the e-mail + CSV attachment', 'html' => 'Table in the e-mail', 'csv' => 'CSV attachment only'];

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['columns' => 'array', 'recipient_users' => 'array', 'skip_empty' => 'boolean', 'is_active' => 'boolean',
            'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function view()
    {
        return $this->belongsTo(ReportView::class, 'report_view_id');
    }

    public function deliveries()
    {
        return $this->hasMany(ReportDelivery::class);
    }

    public function title(): string
    {
        return \App\Http\Controllers\ReportController::REPORTS[$this->report_key][1] ?? $this->report_key;
    }

    public function when(): string
    {
        return match ($this->frequency) {
            'daily' => 'Daily at '.$this->send_time,
            'weekly' => 'Every '.(self::WEEKDAYS[$this->weekday] ?? '?').' at '.$this->send_time,
            'monthly' => ($this->month_day ? 'Monthly on the '.ordinal_day($this->month_day) : 'Last day of each month').' at '.$this->send_time,
            default => 'After each night audit',
        };
    }

    /** @return string[] */
    public function emails(): array
    {
        $users = User::whereIn('id', $this->recipient_users ?? [])->where('is_active', true)->whereNotNull('email')->pluck('email')->all();
        $ext = preg_split('/[\s,;]+/', (string) $this->recipient_emails, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('strtolower', array_merge($users, $ext))));
    }
}
