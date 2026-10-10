<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.4 one foreign national's Form C (FRRO) for one stay – arrival report within 24 h, departure after check-out. */
class FormCRecord extends Model
{
    use BelongsToHotel;

    protected $table = 'form_c_records';

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['pending' => 'To submit', 'submitted' => 'Submitted', 'exempt' => 'Exempt'];

    public const VISA_TYPES = ['tourist' => 'Tourist', 'e_tourist' => 'e-Tourist', 'business' => 'Business', 'e_business' => 'e-Business', 'conference' => 'Conference / e-Conference',
        'medical' => 'Medical / e-Medical', 'employment' => 'Employment', 'student' => 'Student', 'journalist' => 'Journalist', 'transit' => 'Transit',
        'oci' => 'OCI card', 'diplomatic' => 'Diplomatic / official', 'none' => 'Visa not required (Nepal / Bhutan)', 'other' => 'Other'];

    public const PURPOSES = ['tourism' => 'Tourism', 'business' => 'Business', 'conference' => 'Conference', 'medical' => 'Medical treatment', 'employment' => 'Employment',
        'study' => 'Study', 'visiting' => 'Visiting friends / relatives', 'transit' => 'Transit', 'other' => 'Other'];

    public const GENDERS = ['M' => 'Male', 'F' => 'Female', 'X' => 'Other'];

    protected function casts(): array
    {
        return ['is_companion' => 'boolean', 'dob' => 'date', 'passport_issued_on' => 'date', 'passport_expires_on' => 'date', 'visa_issued_on' => 'date',
            'visa_expires_on' => 'date', 'arrived_india_on' => 'date', 'expected_checkout' => 'date', 'checked_in_at' => 'datetime', 'deadline_at' => 'datetime',
            'submitted_at' => 'datetime', 'departed_at' => 'datetime', 'departure_submitted_at' => 'datetime', 'alert_due_at' => 'datetime', 'alert_overdue_at' => 'datetime'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function departureUser()
    {
        return $this->belongsTo(User::class, 'departure_by');
    }

    public function name(): string
    {
        return trim(($this->given_name ?? '').' '.($this->surname ?? '')) ?: 'Unnamed foreign guest';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->deadline_at && $this->deadline_at->isPast();
    }

    public function isLate(): bool
    {
        return $this->submitted_at && $this->deadline_at && $this->submitted_at->gt($this->deadline_at);
    }

    public function departurePending(): bool
    {
        return $this->status === 'submitted' && $this->departed_at && ! $this->departure_submitted_at;
    }

    /** Short state for chips: overdue | due | submitted | departure | done | exempt */
    public function state(): string
    {
        return match (true) {
            $this->status === 'exempt' => 'exempt',
            $this->status === 'pending' => $this->isOverdue() ? 'overdue' : 'due',
            $this->departurePending() => 'departure',
            (bool) $this->departure_submitted_at => 'done',
            default => 'submitted',
        };
    }
}
