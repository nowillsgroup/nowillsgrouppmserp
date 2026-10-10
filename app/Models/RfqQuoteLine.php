<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RfqQuoteLine extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public function quote()
    {
        return $this->belongsTo(RfqQuote::class, 'rfq_quote_id');
    }

    public function line()
    {
        return $this->belongsTo(RfqLine::class, 'rfq_line_id');
    }

    /** Rate including tax – the basis of the comparison. */
    public function landed(): float
    {
        return round((float) $this->rate * (1 + (float) $this->tax_rate / 100), 4);
    }
}
