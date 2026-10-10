<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.4 a foreign currency the forex desk buys (rate quoted per `unit` units, e.g. JPY per 100). */
class Currency extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'unit' => 'integer'];
    }

    public function rates()
    {
        return $this->hasMany(ForexRate::class);
    }

    public function label(): string
    {
        return $this->code.' – '.$this->name.($this->unit > 1 ? ' (per '.$this->unit.')' : '');
    }
}
