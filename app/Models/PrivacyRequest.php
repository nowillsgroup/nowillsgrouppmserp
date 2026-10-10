<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    use BelongsToHotel;

    public const TYPES = ['access' => 'Access (copy of my data)', 'correction' => 'Correction', 'erasure' => 'Erasure (delete my data)',
        'consent' => 'Withdraw consent', 'grievance' => 'Grievance / complaint'];

    public const SHORT = ['access' => 'Access', 'correction' => 'Correction', 'erasure' => 'Erasure', 'consent' => 'Withdraw consent', 'grievance' => 'Grievance'];

    public const CHANNELS = ['web' => 'Privacy page (web)', 'email' => 'E-mail', 'in_person' => 'In person', 'phone' => 'Phone', 'letter' => 'Letter'];

    public const METHODS = ['id_document' => 'Photo ID checked', 'in_person' => 'Known / in person', 'booking_details' => 'Booking details matched',
        'known_contact' => 'Reply from e-mail / mobile on file', 'email_link' => 'E-mail link only'];

    public const REJECTIONS = ['Identity could not be verified', 'No personal data held for this person', 'Manifestly unfounded or repetitive request',
        'Data must be retained under a legal obligation', 'Requester not authorised to act for the guest'];

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'open', 'channel' => 'email', 'relationship' => 'self', 'extended' => false, 'exports' => 0];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'due_on' => 'date', 'extended' => 'boolean', 'verify_expires_at' => 'datetime', 'email_verified_at' => 'datetime',
            'verified_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function events()
    {
        return $this->hasMany(PrivacyRequestEvent::class)->orderBy('id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Identity must be verified before data is disclosed, changed or erased. */
    public function needsIdentity(): bool
    {
        return in_array($this->type, ['access', 'correction', 'erasure']);
    }

    public function daysLeft(): int
    {
        return (int) bdate()->copy()->startOfDay()->diffInDays($this->due_on, false);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->daysLeft() < 0;
    }

    public function slaBadge(): string
    {
        if (! $this->isOpen()) {
            $late = $this->completed_at && $this->completed_at->toDateString() > $this->due_on->toDateString();

            return $this->status === 'unverified' ? '<span class="badge badge-slate">awaiting e-mail</span>'
                : ($late ? '<span class="badge badge-red">late</span>' : '<span class="badge badge-green">on time</span>');
        }
        $d = $this->daysLeft();

        return match (true) {
            $d < 0 => '<span class="badge badge-red">'.abs($d).' d overdue</span>',
            $d <= 7 => '<span class="badge badge-amber">'.$d.' d left</span>',
            default => '<span class="badge badge-slate">'.$d.' d left</span>',
        };
    }

    public function log(string $action, ?string $note = null, ?int $userId = null): void
    {
        $this->events()->create(['action' => $action, 'note' => $note, 'user_id' => $userId ?? auth()->id(), 'created_at' => now()]);
    }
}
