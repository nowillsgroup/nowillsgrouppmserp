<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.5 Access & activity trail of the organiser portal (sign-ins, BEO signatures, payments, guest lists …). */
class OrganiserAudit extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $table = 'organiser_audit';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function organiserUser()
    {
        return $this->belongsTo(OrganiserUser::class);
    }

    public function event()
    {
        return $this->belongsTo(BanquetEvent::class, 'banquet_event_id');
    }
}
