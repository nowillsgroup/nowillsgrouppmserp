<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * v3.1 a survey invitation for one stay. The link token is never stored: it is HMAC(app key, id|nonce), so a reminder
 * can carry the same link, while the database only holds its SHA-256 (token_hash) for the lookup.
 */
class SurveyInvitation extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['scheduled' => 'Scheduled', 'sent' => 'Sent', 'opened' => 'Opened', 'completed' => 'Completed', 'expired' => 'Expired', 'skipped' => 'Skipped', 'failed' => 'Failed'];

    protected function casts(): array
    {
        return ['send_at' => 'datetime', 'sent_at' => 'datetime', 'reminded_at' => 'datetime', 'opened_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    protected $hidden = ['nonce', 'token_hash'];

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function response()
    {
        return $this->hasOne(GuestFeedback::class, 'invitation_id');
    }

    public function token(): string
    {
        return substr(hash_hmac('sha256', $this->id.'|'.$this->nonce, (string) config('app.key')), 0, 40);
    }

    public function url(): string
    {
        return route('survey.show', $this->token());
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['sent', 'opened']) && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
