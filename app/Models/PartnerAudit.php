<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.4 Partner portal access & activity trail (sign-ins, failures, lock-outs, bookings, cancellations, payments, user admin). */
class PartnerAudit extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $table = 'partner_audit';

    protected $guarded = ['id', 'hotel_id'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function partnerUser()
    {
        return $this->belongsTo(PartnerUser::class);
    }
}
