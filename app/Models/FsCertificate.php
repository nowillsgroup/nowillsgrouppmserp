<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.0 Food handler's medical fitness certificate / food hygiene training certificate. */
class FsCertificate extends Model
{
    use BelongsToHotel;

    public const TYPES = ['medical' => 'Medical fitness (food handler health certificate)', 'hygiene' => 'Food hygiene / safety training'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
