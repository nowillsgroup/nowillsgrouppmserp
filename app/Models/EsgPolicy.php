<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 A policy on the ESG governance register: owner, version, approval and review cycle. */
class EsgPolicy extends Model
{
    use BelongsToHotel;

    public const PILLARS = ['environment' => 'Environment', 'social' => 'Social', 'governance' => 'Governance'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['approved_on' => 'date', 'next_review_on' => 'date'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active' && $this->next_review_on && $this->next_review_on->toDateString() < bdate()->toDateString();
    }
}
