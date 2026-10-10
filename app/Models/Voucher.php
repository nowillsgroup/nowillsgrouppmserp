<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use BelongsToHotel;

    protected $table = 'vouchers';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function head()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
