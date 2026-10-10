<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.2 An influencer / content-creator collaboration: proposed → agreed → hosted → delivered, with cost, reach and attributed bookings. */
class DmInfluencer extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['proposed' => ['Proposed', 'slate'], 'agreed' => ['Agreed', 'blue'], 'hosted' => ['Hosted', 'amber'], 'delivered' => ['Content delivered', 'green'], 'cancelled' => ['Cancelled', 'red']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['stay_from' => 'date', 'stay_to' => 'date', 'due_date' => 'date', 'fee' => 'decimal:2', 'comp_value' => 'decimal:2'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function link()
    {
        return $this->belongsTo(DmLink::class, 'link_id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function cost(): float
    {
        return round((float) $this->fee + (float) $this->comp_value, 2);
    }

    public function urls(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->content_urls))));
    }

    /** hosted, content due date passed, nothing delivered */
    public function isOverdue(): bool
    {
        return $this->status === 'hosted' && $this->due_date && $this->due_date->lt(bdate()->copy()->startOfDay());
    }
}
