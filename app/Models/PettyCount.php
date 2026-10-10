<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.7 A physical count of a float by denomination against the book balance. */
class PettyCount extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'agreed'];

    protected function casts(): array
    {
        return ['date' => 'date', 'denominations' => 'array', 'counted' => 'decimal:2', 'book' => 'decimal:2', 'variance' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function float()
    {
        return $this->belongsTo(PettyFloat::class, 'petty_float_id');
    }

    public function counter()
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function witness()
    {
        return $this->belongsTo(User::class, 'witness_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
