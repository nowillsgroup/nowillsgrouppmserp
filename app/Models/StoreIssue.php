<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class StoreIssue extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'received_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(StoreIssueLine::class)->orderBy('id');
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function fromStore()
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore()
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isAcknowledged(): bool
    {
        return (bool) $this->received_at;
    }
}
