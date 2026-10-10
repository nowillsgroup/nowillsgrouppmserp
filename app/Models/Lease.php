<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.2 A tenancy / long-stay agreement on a unit, with its own account, deposit, meters and inspections. */
class Lease extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'notice' => 'Notice given', 'ended' => 'Ended', 'terminated' => 'Terminated', 'cancelled' => 'Cancelled'];

    public const TAX_CLASSES = [
        'serviced' => 'Serviced apartment (VAT, NHIL, GETFund + Tourism Levy)',
        'residential' => 'Residential tenancy (VAT-exempt)',
        'commercial' => 'Commercial let – office / shop (VAT, NHIL, GETFund)',
    ];

    public const CYCLES = [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Half-yearly', 12 => 'Yearly'];

    public const UTILITY_MODES = ['included' => 'Included in rent', 'metered' => 'Metered – recharged', 'prepaid' => 'Prepaid meter (tenant buys)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'billed_to' => 'date', 'next_review_on' => 'date', 'moved_in_on' => 'date', 'notice_on' => 'date',
            'move_out_on' => 'date', 'settled_at' => 'datetime', 'signed_at' => 'datetime', 'auto_renew' => 'boolean',
            'rent' => 'decimal:2', 'service_charge' => 'decimal:2', 'deposit_amount' => 'decimal:2', 'escalation_pct' => 'decimal:2', 'wht_pct' => 'decimal:2'];
    }

    public function unit()
    {
        return $this->belongsTo(LeaseUnit::class, 'lease_unit_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function charges()
    {
        return $this->hasMany(LeaseCharge::class);
    }

    public function payments()
    {
        return $this->hasMany(LeasePayment::class);
    }

    public function readings()
    {
        return $this->hasMany(LeaseMeterReading::class);
    }

    public function inspections()
    {
        return $this->hasMany(LeaseInspection::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['active', 'notice'], true);
    }

    /** Last day of occupation: the move-out date if set, else the end of the term. */
    public function lastDay(): \Carbon\Carbon
    {
        return ($this->move_out_on ?? $this->end_date)->copy();
    }
}
