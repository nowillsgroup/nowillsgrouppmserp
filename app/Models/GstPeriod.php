<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A month whose GRA VAT return has been filed (arn = GRA acknowledgement / reference no.) – documents dated in it are locked. */
class GstPeriod extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['filed_on' => 'date', 'summary' => 'array'];
    }

    public function filer()
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public static function isFiled(?string $date): bool
    {
        return $date && static::where('period', substr($date, 0, 7))->exists();
    }
}
