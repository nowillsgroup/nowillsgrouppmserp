<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.6 – one GL account's twelve fiscal months in a budget version. */
class BudgetLine extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        $c = [];
        for ($m = 1; $m <= 12; $m++) {
            $c['m'.$m] = 'float';
        }

        return $c;
    }

    public function account()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function month(int $m): float
    {
        return (float) $this->{'m'.$m};
    }

    public function total(): float
    {
        $t = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $t += (float) $this->{'m'.$m};
        }

        return round($t, 2);
    }

    /** @return array<int, float> */
    public function months(): array
    {
        $o = [];
        for ($m = 1; $m <= 12; $m++) {
            $o[$m] = (float) $this->{'m'.$m};
        }

        return $o;
    }
}
