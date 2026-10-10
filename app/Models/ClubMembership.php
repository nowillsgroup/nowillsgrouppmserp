<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 A membership (subscription) with one or more card holders and its own account of charges and receipts. */
class ClubMembership extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['active' => 'Active', 'frozen' => 'Frozen', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];

    public const SOURCES = ['walk_in' => 'Walk-in / enquiry', 'corporate' => 'Corporate scheme', 'guest' => 'Hotel guest', 'referral' => 'Member referral'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'frozen_from' => 'date', 'frozen_to' => 'date', 'cancelled_on' => 'date', 'auto_renew' => 'boolean'];
    }

    public function plan()
    {
        return $this->belongsTo(ClubPlan::class, 'club_plan_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function members()
    {
        return $this->hasMany(ClubMember::class)->orderByRaw("field(relation,'primary','spouse','employee','child','dependant')")->orderBy('id');
    }

    public function charges()
    {
        return $this->hasMany(ClubCharge::class);
    }

    public function payments()
    {
        return $this->hasMany(ClubPayment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function primary(): ?ClubMember
    {
        return $this->members->firstWhere('relation', 'primary') ?? $this->members->first();
    }
}
