<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.7 – a staff master / section key (physical or card) kept at the key cabinet and signed out per shift. */
class MasterKey extends Model
{
    use BelongsToHotel;

    public const LEVELS = ['grand' => 'Grand master', 'floor' => 'Floor master', 'section' => 'Section key', 'emergency' => 'Emergency key'];

    protected $guarded = ['id', 'hotel_id'];

    public function loans()
    {
        return $this->hasMany(KeyLoan::class)->latest('out_at');
    }

    public function openLoan()
    {
        // status filter inside the of-many subquery (v3.8 fix: a newer row in another status must not hide this one)
        return $this->hasOne(KeyLoan::class)->ofMany(['out_at' => 'max', 'id' => 'max'], fn ($q) => $q->where('status', 'out'));
    }
}
