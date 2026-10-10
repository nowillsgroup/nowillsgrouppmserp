<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.1 A marketing-plan activity: trade show, roadshow, sales blitz, fam trip, sponsorship, advertising, PR… with budget, cost, leads and ROI. */
class MarketingEvent extends Model
{
    use BelongsToHotel;

    public const TYPES = [
        'trade_show' => ['Trade show / exhibition', 'globe'], 'roadshow' => ['Roadshow / sales mission', 'plane'], 'sales_blitz' => ['Sales blitz', 'car'],
        'fam_trip' => ['Fam trip / hosted site visit', 'hotel'], 'sponsorship' => ['Sponsorship', 'star'], 'advertising' => ['Print / radio / TV advertising', 'message'],
        'pr' => ['PR & media event', 'mail'], 'hosted_event' => ['Hosted client event', 'coffee'], 'direct_mail' => ['Direct mail & collateral', 'book'], 'other' => ['Other', 'layers'],
    ];

    /** budget lines = event types + spend recorded elsewhere */
    public const BUDGET_LINES_EXTRA = ['entertainment' => 'Client entertainment (sales diary)', 'digital' => 'Digital marketing'];

    public const SEGMENTS = [
        'corporate' => 'Corporate', 'government' => 'Government & parastatals', 'ngo' => 'NGOs & embassies', 'mice' => 'MICE / conferences',
        'travel_trade' => 'Travel trade & DMCs', 'leisure' => 'Leisure', 'wedding' => 'Weddings & social', 'diaspora' => 'Diaspora / heritage travel',
    ];

    public const MARKETS = [
        'domestic' => 'Ghana (domestic)', 'west_africa' => 'West Africa / ECOWAS', 'nigeria' => 'Nigeria', 'uk_europe' => 'UK & Europe',
        'north_america' => 'North America', 'middle_east' => 'Middle East', 'asia' => 'Asia', 'global' => 'Global',
    ];

    public const STATUSES = ['planned' => ['Planned', 'slate'], 'confirmed' => ['Confirmed', 'blue'], 'done' => ['Done', 'green'], 'cancelled' => ['Cancelled', 'red']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'budget' => 'decimal:2', 'actual_cost' => 'decimal:2'];
    }

    public static function budgetLines(): array
    {
        return array_map(fn ($t) => $t[0], self::TYPES) + self::BUDGET_LINES_EXTRA;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function opportunities()
    {
        return $this->hasMany(SalesOpportunity::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst($this->type);
    }

    public function typeIcon(): string
    {
        return self::TYPES[$this->type][1] ?? 'layers';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    /** the cost that counts: actual once done, otherwise the budget */
    public function cost(): float
    {
        return $this->status === 'done' ? (float) $this->actual_cost : (float) $this->budget;
    }
}
