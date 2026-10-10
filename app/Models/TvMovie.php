<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A pay movie (video on demand): price before tax, rental window, age rating. */
class TvMovie extends Model
{
    use BelongsToHotel;

    public const GENRES = ['action' => 'Action', 'drama' => 'Drama', 'comedy' => 'Comedy', 'family' => 'Family', 'thriller' => 'Thriller', 'romance' => 'Romance',
        'documentary' => 'Documentary', 'nollywood' => 'Nollywood & Ghallywood', 'animation' => 'Animation', 'adult' => 'Adult'];

    public const RATINGS = ['G', 'PG', 'PG-13', '16', '18'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_adult' => 'boolean', 'is_active' => 'boolean'];
    }

    public function purchases()
    {
        return $this->hasMany(TvPurchase::class);
    }

    /** price the guest sees, VAT + levies included */
    public function gross(): float
    {
        return round((float) $this->price + \App\Services\TaxService::forCategory('pay_tv', (float) $this->price)['amount'], 2);
    }
}
