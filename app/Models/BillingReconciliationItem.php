<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Immutable source-document snapshot plus manual settlement evidence and review decision. */
class BillingReconciliationItem extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'document_amount' => 'decimal:2',
            'cash_payments' => 'decimal:2',
            'cash_refunds' => 'decimal:2',
            'city_ledger_amount' => 'decimal:2',
            'settled_payments' => 'decimal:2',
            'settled_refunds' => 'decimal:2',
            'source_changed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'snapshot_at' => 'datetime',
        ];
    }

    public function reconciliation()
    {
        return $this->belongsTo(BillingReconciliation::class, 'billing_reconciliation_id');
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isCreditNote(): bool
    {
        return $this->document_type === 'credit_note';
    }

    public function cashVariance(): float
    {
        return round(((float) $this->cash_payments - (float) ($this->settled_payments ?? 0))
            - ((float) $this->cash_refunds - (float) ($this->settled_refunds ?? 0)), 2);
    }

    public function hasCashMovement(): bool
    {
        return (float) $this->cash_payments > 0.005 || (float) $this->cash_refunds > 0.005;
    }

    public function currentSourceDiffers(): bool
    {
        if (! $this->folio || ! $this->source_hash) {
            return true;
        }

        return ! hash_equals($this->source_hash, \App\Services\BillingReconciliationService::sourceHash($this->folio->loadMissing('transactions', 'original', 'guest', 'company')));
    }
}
