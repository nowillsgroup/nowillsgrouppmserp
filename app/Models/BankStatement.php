<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.6 A bank / Mobile Money statement for one account and period (draft → reconciled). */
class BankStatement extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'draft', 'source' => 'manual', 'needs_review' => false];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2',
            'reconciled_at' => 'datetime', 'needs_review' => 'boolean'];
    }

    public function account()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function lines()
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('date')->orderBy('line_no')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reconciler()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function label(): string
    {
        return $this->period_from->format('d M').' – '.$this->period_to->format('d M Y');
    }
}
