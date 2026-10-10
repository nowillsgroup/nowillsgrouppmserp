<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.8 A capital expenditure project – funded from the FF&E reserve, by the owner or from operations – approved by the owner. */
class CapexProject extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['ffe' => 'Furniture, fixtures & equipment', 'building' => 'Building & plant', 'it' => 'IT & systems', 'vehicle' => 'Vehicles', 'other' => 'Other'];

    public const FUNDING = ['reserve' => 'FF&E reserve', 'owner' => 'Owner (capital call)', 'operating' => 'Operating cash'];

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Awaiting owner approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    public const TONES = ['draft' => 'slate', 'submitted' => 'amber', 'approved' => 'blue', 'rejected' => 'red', 'completed' => 'green', 'cancelled' => 'slate'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['budget' => 'decimal:2', 'needed_by' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function entries()
    {
        return $this->hasMany(FfeEntry::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** spent = linked asset cost + manual drawdowns / payments recorded against the project */
    public function spent(): float
    {
        $assets = (float) ($this->relationLoaded('assets') ? $this->assets->sum('cost') : $this->assets()->sum('cost'));
        $manual = -(float) ($this->relationLoaded('entries') ? $this->entries->whereNull('asset_id')->where('type', 'drawdown')->sum('amount') : $this->entries()->whereNull('asset_id')->where('type', 'drawdown')->sum('amount'));

        return round($assets + $manual, 2);
    }
}
