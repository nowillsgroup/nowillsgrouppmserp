<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.8 A movement on the FF&E (replacement) reserve: opening balance, monthly contribution, interest, capex drawdown, adjustment. */
class FfeEntry extends Model
{
    use BelongsToHotel;

    public const TYPES = ['opening' => 'Opening balance', 'contribution' => 'Monthly contribution', 'interest' => 'Interest earned', 'drawdown' => 'Capex drawdown', 'adjustment' => 'Adjustment'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function project()
    {
        return $this->belongsTo(CapexProject::class, 'capex_project_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function fee()
    {
        return $this->belongsTo(OwnerFee::class, 'owner_fee_id');
    }
}
