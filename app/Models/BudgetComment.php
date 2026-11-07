<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.6 – variance commentary: why an account was over / under budget in a month. v8.2 – with an owner, a due
 * date and a state, so a flagged variance can be chased and a month signed off only when none is left open. */
class BudgetComment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['actual' => 'float', 'budget' => 'float', 'due_on' => 'date', 'resolved_at' => 'datetime'];
    }

    public function account()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** an explanation counts once it has been written and not been sent back */
    public function isExplained(): bool
    {
        return in_array((string) $this->status, ['explained', 'accepted'], true) && trim((string) $this->body) !== '';
    }

    public function statusLabel(): string
    {
        return match ((string) $this->status) {
            'accepted' => 'Accepted',
            'explained' => 'Explained',
            default => 'Needs explaining',
        };
    }

    public function statusTone(): string
    {
        return match ((string) $this->status) {
            'accepted' => 'green',
            'explained' => 'blue',
            default => 'amber',
        };
    }

    public function budgetVersion()
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }
}
