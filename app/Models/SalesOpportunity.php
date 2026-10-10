<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.1 A piece of business being pursued with an account – from first contact to won (and what it became) or lost. */
class SalesOpportunity extends Model
{
    use BelongsToHotel;

    public const STAGES = [
        'prospect' => ['Prospect', 'slate'], 'qualified' => ['Qualified', 'blue'], 'proposal' => ['Proposal sent', 'purple'],
        'negotiation' => ['Negotiation', 'amber'], 'won' => ['Won', 'green'], 'lost' => ['Lost', 'red'],
    ];

    public const OPEN = ['prospect', 'qualified', 'proposal', 'negotiation'];

    public const TYPES = [
        'corporate' => 'Corporate rate agreement', 'agency' => 'Travel agency / tour operator', 'group' => 'Group rooms',
        'mice' => 'Conference / MICE', 'social' => 'Wedding / social event', 'long_stay' => 'Long stay / project crew',
    ];

    /** types that turn into a rate contract when won */
    public const CONTRACT_TYPES = ['corporate', 'agency', 'long_stay'];

    public const SOURCES = [
        'prospecting' => 'Prospecting / cold call', 'rfp' => 'RFP received', 'referral' => 'Referral', 'repeat' => 'Repeat business',
        'website' => 'Website enquiry', 'trade_show' => 'Trade show / event', 'agent' => 'Agent / DMC', 'walk_in' => 'Walk-in',
    ];

    public const LOST_REASONS = [
        'price' => 'Price / rate too high', 'availability' => 'No availability on the dates', 'competitor' => 'Chose a competitor',
        'dates' => 'Dates changed / postponed', 'budget' => 'Budget cut / cancelled', 'facilities' => 'Facilities did not fit',
        'no_response' => 'No response', 'other' => 'Other',
    ];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'expected_close' => 'date', 'arrival' => 'date', 'departure' => 'date', 'closed_on' => 'date', 'stage_changed_at' => 'datetime',
            'rooms_revenue' => 'decimal:2', 'fb_revenue' => 'decimal:2', 'other_revenue' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function contact()
    {
        return $this->belongsTo(SalesContact::class, 'sales_contact_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities()
    {
        return $this->hasMany(SalesActivity::class);
    }

    public function marketingEvent()
    {
        return $this->belongsTo(MarketingEvent::class);
    }

    public function contract()
    {
        return $this->belongsTo(RateContract::class, 'rate_contract_id');
    }

    public function block()
    {
        return $this->belongsTo(RoomBlock::class, 'room_block_id');
    }

    public function event()
    {
        return $this->belongsTo(BanquetEvent::class, 'banquet_event_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN, true);
    }

    public function total(): float
    {
        return round((float) $this->rooms_revenue + (float) $this->fb_revenue + (float) $this->other_revenue, 2);
    }

    public function weighted(): float
    {
        return round($this->total() * $this->probability / 100, 2);
    }

    public function adr(): ?float
    {
        return $this->room_nights ? round((float) $this->rooms_revenue / $this->room_nights, 2) : null;
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage][0] ?? ucfirst($this->stage);
    }

    public function stageColor(): string
    {
        return self::STAGES[$this->stage][1] ?? 'slate';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
