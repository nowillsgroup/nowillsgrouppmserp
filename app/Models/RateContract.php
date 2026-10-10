<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RateContract extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const MEAL_PLANS = ['EP' => 'Room only (EP)', 'CP' => 'Breakfast (CP)', 'MAP' => 'Half board (MAP)', 'AP' => 'Full board (AP)'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'lra' => 'boolean', 'approved_at' => 'datetime', 'discount_pct' => 'decimal:2',
            'commission_pct' => 'decimal:2'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function rates()
    {
        return $this->hasMany(RateContractRate::class)->orderBy('room_type_id')->orderByRaw('season_from IS NULL DESC')->orderBy('season_from');
    }

    public function blackouts()
    {
        return $this->hasMany(RateContractBlackout::class)->orderBy('date_from');
    }

    public function allotments()
    {
        return $this->hasMany(ContractAllotment::class)->orderBy('date_from');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function renewedFrom()
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    /** draft / active / suspended / expired / upcoming (active but not started yet) */
    public function state(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }
        $today = bdate()->toDateString();

        return $this->valid_to->toDateString() < $today ? 'expired' : ($this->valid_from->toDateString() > $today ? 'upcoming' : 'active');
    }

    public function daysToExpiry(): int
    {
        return (int) bdate()->copy()->startOfDay()->diffInDays($this->valid_to, false);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'suspended']);
    }

    public function basisLabel(): string
    {
        return $this->pricing === 'net' ? 'Net rates'.($this->discount_pct > 0 ? ' (others '.rtrim(rtrim((string) $this->discount_pct, '0'), '.').'% off)' : '')
            : rtrim(rtrim((string) $this->discount_pct, '0'), '.').'% off public rate';
    }
}
