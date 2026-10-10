<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Guest extends Model
{
    use BelongsToHotel;

    protected $table = 'guests';

    protected $guarded = ['id', 'hotel_id'];

    public function getFullNameAttribute(): string
    {
        return trim(($this->title ? $this->title.' ' : '').$this->first_name.' '.$this->last_name);
    }

    public function scopeSearch($q, ?string $term)
    {
        if (! $term) {
            return $q;
        }

        return $q->where(function ($w) use ($term) {
            $w->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")
                ->orWhere('phone', 'like', "%$term%")->orWhere('email', 'like', "%$term%")
                ->orWhere('id_number', 'like', "%$term%");
        });
    }

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'anniversary' => 'date',
            'visa_expiry' => 'date',
            'is_vip' => 'boolean',
            'is_blacklisted' => 'boolean',
            'email_opt_in' => 'boolean', 'sms_opt_in' => 'boolean', 'whatsapp_opt_in' => 'boolean',
            'consent_at' => 'datetime', 'first_stay_on' => 'date', 'last_stay_on' => 'date', 'stats_at' => 'datetime',
            'total_revenue' => 'decimal:2',
            'legal_hold_until' => 'date', 'erased_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function folios()
    {
        return $this->hasMany(Folio::class);
    }

    public function marketingMessages()
    {
        return $this->hasMany(MarketingMessage::class);
    }

    public function consentLogs()
    {
        return $this->hasMany(MarketingConsentLog::class);
    }

    /** @return string[] */
    public function tagList(): array
    {
        return array_values(array_filter(explode(',', (string) $this->tags)));
    }

    public function optedIn(string $channel): bool
    {
        return (bool) $this->{$channel.'_opt_in'};
    }

    public function documents()
    {
        return $this->hasMany(GuestDocument::class);
    }

    public function privacyRequests()
    {
        return $this->hasMany(PrivacyRequest::class);
    }

    /** v2.9: erased (anonymised) or restricted (legal hold – kept, but no further processing). */
    public function isPrivacyLocked(): bool
    {
        return (bool) $this->privacy_status;
    }

    public function isErased(): bool
    {
        return $this->privacy_status === 'erased';
    }

    public function isRestricted(): bool
    {
        return $this->privacy_status === 'restricted';
    }

    /** Masked identifier for screens: keeps the last $keep characters. */
    public static function mask(?string $v, int $keep = 4): string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '—';
        }
        $n = mb_strlen($v);

        return $n <= $keep ? str_repeat('•', $n) : str_repeat('•', min(8, $n - $keep)).mb_substr($v, -$keep);
    }
}
