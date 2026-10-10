<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.3 Income audit of one closed business date: automated checks, findings, auditor sign-off and controller close. */
class AuditDay extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open' => 'In audit', 'signed' => 'Signed – awaiting controller', 'closed' => 'Closed'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'checked_at' => 'datetime', 'signed_at' => 'datetime', 'closed_at' => 'datetime', 'reopened_at' => 'datetime', 'figures' => 'array'];
    }

    public function findings()
    {
        return $this->hasMany(AuditFinding::class);
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function label(): string
    {
        return $this->business_date->format('D d M Y');
    }
}
