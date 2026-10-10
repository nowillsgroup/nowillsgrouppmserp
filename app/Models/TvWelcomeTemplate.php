<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A welcome screen for a language and guest segment; the most specific, highest-priority match is shown. */
class TvWelcomeTemplate extends Model
{
    use BelongsToHotel;

    public const SEGMENTS = ['all' => 'Every guest', 'vip' => 'VIP guests', 'repeat' => 'Returning guests', 'corporate' => 'Company bookings', 'group' => 'Group bookings',
        'long_stay' => 'Long stays (7+ nights)', 'birthday' => 'Birthday during the stay'];

    public const THEMES = ['gold' => 'Kente gold', 'ocean' => 'Atlantic blue', 'forest' => 'Forest green', 'sunset' => 'Sunset', 'night' => 'Night sky'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'is_active' => 'boolean'];
    }

    public function isCurrent(?string $date = null): bool
    {
        $d = $date ?? bdate()->toDateString();

        return $this->is_active && (! $this->valid_from || $this->valid_from->toDateString() <= $d) && (! $this->valid_to || $this->valid_to->toDateString() >= $d);
    }
}
