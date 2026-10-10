<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.7 A petty cash float (imprest): a fixed amount held by a custodian, topped back up from the bank. */
class PettyFloat extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['is_active' => true, 'voucher_limit' => 500];

    protected function casts(): array
    {
        return ['imprest_amount' => 'decimal:2', 'voucher_limit' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function account()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function txns()
    {
        return $this->hasMany(PettyTxn::class);
    }

    public function counts()
    {
        return $this->hasMany(PettyCount::class);
    }

    public function replenishments()
    {
        return $this->hasMany(PettyReplenishment::class);
    }
}
