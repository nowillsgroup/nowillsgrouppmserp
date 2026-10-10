<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use BelongsToHotel;

    protected $table = 'companies';

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['is_active' => true, 'credit_days' => 30, 'direct_billing' => true, 'credit_status' => 'open'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'direct_billing' => 'boolean', 'credit_hold_auto' => 'boolean', 'credit_hold_at' => 'datetime',
            'last_statement_at' => 'datetime', 'credit_limit' => 'decimal:2', 'portal_enabled' => 'boolean', 'portal_guest_pay' => 'boolean',
        ];
    }

    public function guests()
    {
        return $this->hasMany(Guest::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function ledger()
    {
        return $this->hasMany(FolioTransaction::class);
    }

    public function contracts()
    {
        return $this->hasMany(RateContract::class);
    }

    public function creditLogs()
    {
        return $this->hasMany(CreditLog::class)->latest('id');
    }

    // ───── v6.1 sales & account management
    public const TIERS = ['key' => ['Key account', 'purple'], 'major' => ['Major', 'blue'], 'standard' => ['Standard', 'slate']];

    public const SALES_STATUSES = ['prospect' => ['Prospect', 'amber'], 'active' => ['Active', 'green'], 'dormant' => ['Dormant', 'slate'], 'lost' => ['Lost', 'red']];

    public const INDUSTRIES = [
        'banking' => 'Banking & finance', 'telecom' => 'Telecoms & tech', 'mining' => 'Mining', 'oil_gas' => 'Oil & gas', 'government' => 'Government & agencies',
        'ngo' => 'NGO / development', 'embassy' => 'Embassy / diplomatic', 'education' => 'Education', 'health' => 'Health & pharma', 'fmcg' => 'FMCG & retail',
        'construction' => 'Construction & engineering', 'sports' => 'Sports & entertainment', 'travel' => 'Travel trade', 'events' => 'Events & weddings', 'other' => 'Other',
    ];

    public function contacts()
    {
        return $this->hasMany(SalesContact::class);
    }

    public function opportunities()
    {
        return $this->hasMany(SalesOpportunity::class);
    }

    public function salesActivities()
    {
        return $this->hasMany(SalesActivity::class);
    }

    public function tierLabel(): string
    {
        return self::TIERS[$this->tier ?? 'standard'][0] ?? ucfirst((string) $this->tier);
    }

    public function salesStatusLabel(): string
    {
        return self::SALES_STATUSES[$this->sales_status ?? 'active'][0] ?? ucfirst((string) $this->sales_status);
    }

    public function salesStatusColor(): string
    {
        return self::SALES_STATUSES[$this->sales_status ?? 'active'][1] ?? 'slate';
    }

    public function typeLabel(): string
    {
        return ['corporate' => 'Corporate', 'travel_agent' => 'Travel agent', 'ota' => 'OTA'][$this->type] ?? ucfirst((string) $this->type);
    }

    public function accountManager()
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function onHold(): bool
    {
        return $this->credit_status === 'hold';
    }

    /** v7.4 partner portal logins */
    public function partnerUsers()
    {
        return $this->hasMany(PartnerUser::class);
    }

    /** v7.5 organiser portal logins */
    public function organiserUsers()
    {
        return $this->hasMany(OrganiserUser::class);
    }
}
