<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.9 – a licence, permit, certificate, insurance policy, statutory inspection or AMC the hotel must keep current. */
class ComplianceItem extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['licence' => 'Licence / permit', 'certificate' => 'Certificate / approval', 'insurance' => 'Insurance policy',
        'inspection' => 'Statutory inspection / test', 'contract' => 'AMC / service contract'];

    /** computed state => [label, badge colour] */
    public const STATES = ['valid' => ['Valid', 'green'], 'due' => ['Due soon', 'amber'], 'renewing' => ['Renewal applied', 'blue'],
        'expired' => ['Expired / overdue', 'red'], 'perpetual' => ['No expiry', 'slate'], 'archived' => ['Archived', 'slate']];

    public const DEPARTMENTS = ['Management', 'Front Office', 'Food & Beverage', 'Kitchen', 'Bar', 'Engineering', 'Housekeeping', 'Security', 'Finance', 'HR', 'Spa'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'applied_on' => 'date', 'alerted_for' => 'date', 'lapsed_alerted_for' => 'date',
            'fee' => 'float', 'is_critical' => 'boolean', 'lead_days' => 'integer', 'frequency_months' => 'integer'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events()
    {
        return $this->hasMany(ComplianceEvent::class)->orderByDesc('event_date')->orderByDesc('id');
    }

    /** days until expiry / next due (negative = overdue), null when there is no expiry */
    public function daysLeft(?\Carbon\CarbonInterface $asOf = null): ?int
    {
        if (! $this->expires_on) {
            return null;
        }

        return (int) ($asOf ?? bdate())->copy()->startOfDay()->diffInDays($this->expires_on->copy()->startOfDay(), false);
    }

    public function state(?\Carbon\CarbonInterface $asOf = null): string
    {
        if ($this->status === 'archived') {
            return 'archived';
        }
        $left = $this->daysLeft($asOf);
        if ($left === null) {
            return 'perpetual';
        }
        if ($left < 0) {
            return 'expired';
        }
        if ($this->status === 'renewing') {
            return 'renewing';
        }

        return $left <= $this->lead_days ? 'due' : 'valid';
    }

    public function stateLabel(?\Carbon\CarbonInterface $asOf = null): string
    {
        return self::STATES[$this->state($asOf)][0];
    }

    public function stateColor(?\Carbon\CarbonInterface $asOf = null): string
    {
        return self::STATES[$this->state($asOf)][1];
    }

    public function isRecurring(): bool
    {
        return $this->category === 'inspection' && $this->frequency_months > 0;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
