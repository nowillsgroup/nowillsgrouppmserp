<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.7 – master key sign-out / sign-in register entry. */
class KeyLoan extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['out_at' => 'datetime', 'due_at' => 'datetime', 'in_at' => 'datetime'];
    }

    public function key()
    {
        return $this->belongsTo(MasterKey::class, 'master_key_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'out' && $this->due_at->isPast();
    }
}
