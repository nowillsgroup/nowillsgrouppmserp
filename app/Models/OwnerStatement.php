<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.8 The monthly owner pack: USALI statement, KPIs, fees, FF&E reserve and capex – frozen when issued to the owner. */
class OwnerStatement extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['draft' => 'Draft', 'issued' => 'Issued to owner', 'queried' => 'Owner query open', 'acknowledged' => 'Acknowledged'];

    public const TONES = ['draft' => 'slate', 'issued' => 'blue', 'queried' => 'amber', 'acknowledged' => 'green'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function queries()
    {
        return $this->hasMany(OwnerQuery::class)->orderBy('id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function snap(): array
    {
        return $this->snapshot ? (json_decode($this->snapshot, true) ?: []) : [];
    }

    public function label(): string
    {
        return Carbon::parse($this->period.'-01')->format('F Y');
    }
}
