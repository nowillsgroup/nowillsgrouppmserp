<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.8 A question the owner's representative raises on an owner pack, and the operator's answer. */
class OwnerQuery extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    public function statement()
    {
        return $this->belongsTo(OwnerStatement::class, 'owner_statement_id');
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function replier()
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
