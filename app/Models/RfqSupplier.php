<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.8 A supplier invited to an RFQ; the token is the only credential for the public quote page. */
class RfqSupplier extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id', 'token'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'viewed_at' => 'datetime', 'responded_at' => 'datetime', 'declined_at' => 'datetime'];
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function state(): string
    {
        return match (true) {
            (bool) $this->declined_at => 'declined',
            (bool) $this->responded_at => 'quoted',
            (bool) $this->viewed_at => 'viewed',
            (bool) $this->invited_at => 'invited',
            default => 'pending',
        };
    }

    public function link(): string
    {
        return route('rfq.public', $this->token);
    }
}
