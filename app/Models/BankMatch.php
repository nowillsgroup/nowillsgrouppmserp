<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.6 Statement line ↔ GL line (each GL line clears once). */
class BankMatch extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public function line()
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function glLine()
    {
        return $this->belongsTo(GlLine::class);
    }
}
