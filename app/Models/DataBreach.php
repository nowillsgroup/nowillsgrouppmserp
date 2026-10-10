<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DataBreach extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['identity' => 'ID / passport details & scans', 'contact' => 'Contact details', 'stay' => 'Stay & booking history',
        'payment' => 'Payment / card data', 'health' => 'Health / dietary / accessibility', 'credentials' => 'Staff credentials / accounts', 'employee' => 'Employee records'];

    public const SEVERITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'open', 'severity' => 'medium', 'notifiable' => true, 'affected_count' => 0];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime', 'occurred_at' => 'datetime', 'contained_at' => 'datetime', 'authority_notified_at' => 'datetime',
            'subjects_notified_at' => 'datetime', 'closed_at' => 'datetime', 'notifiable' => 'boolean'];
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return string[] */
    public function categoryList(): array
    {
        return array_values(array_filter(explode(',', (string) $this->categories)));
    }

    public function deadline(): Carbon
    {
        return $this->detected_at->copy()->addHours((int) \App\Services\PrivacyService::settings()['breach_notify_hours']);
    }

    /** Authority still to be told and the clock is running (or has run out). */
    public function notificationPending(): bool
    {
        return $this->notifiable && ! $this->authority_notified_at && $this->status !== 'closed';
    }

    public function hoursLeft(): int
    {
        return (int) floor(now()->diffInMinutes($this->deadline(), false) / 60);
    }
}
