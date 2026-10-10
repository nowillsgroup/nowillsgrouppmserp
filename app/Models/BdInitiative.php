<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 A new-business initiative through the stage gate, with its business case, milestones and monthly results. */
class BdInitiative extends Model
{
    use BelongsToHotel;

    public const CATEGORIES = ['new_segment' => 'New segment', 'new_market' => 'New source market', 'revenue_stream' => 'New revenue stream', 'product' => 'New product / package',
        'partnership' => 'Partnership', 'expansion' => 'Expansion / new outlet'];

    public const STAGES = ['idea' => ['Idea', 'slate'], 'assessment' => ['Assessment', 'blue'], 'business_case' => ['Business case', 'purple'], 'approved' => ['Approved', 'green'],
        'implementation' => ['Implementation', 'amber'], 'live' => ['Live', 'green'], 'closed' => ['Closed', 'slate'], 'rejected' => ['Rejected', 'red']];

    /** stages on the funnel board, in order */
    public const PIPELINE = ['idea', 'assessment', 'business_case', 'approved', 'implementation', 'live'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['investment' => 'decimal:2', 'annual_revenue' => 'decimal:2', 'annual_cost' => 'decimal:2', 'launch_date' => 'date', 'live_date' => 'date',
            'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function partner()
    {
        return $this->belongsTo(BdPartner::class, 'partner_id');
    }

    public function milestones()
    {
        return $this->hasMany(BdMilestone::class, 'initiative_id')->orderBy('due_date')->orderBy('id');
    }

    public function results()
    {
        return $this->hasMany(BdResult::class, 'initiative_id')->orderBy('month');
    }

    public function stageLabel(): string
    {
        return $this->stage === 'business_case' && $this->submitted_at ? 'Awaiting approval' : (self::STAGES[$this->stage][0] ?? ucfirst($this->stage));
    }

    public function stageTone(): string
    {
        return $this->stage === 'business_case' && $this->submitted_at ? 'amber' : (self::STAGES[$this->stage][1] ?? 'slate');
    }

    public function awaitingApproval(): bool
    {
        return $this->stage === 'business_case' && $this->submitted_at !== null;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, ['closed', 'rejected'], true);
    }
}
