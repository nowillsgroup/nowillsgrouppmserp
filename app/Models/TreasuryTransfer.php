<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * v5.7 Money moved between treasury accounts:
 *  - deposit     cash (and cheques in hand) banked with a deposit slip;
 *  - sweep       card / MoMo / gateway settlement paid out by the acquirer, net of its fee;
 *  - transfer    bank to bank (or wallet to bank);
 *  - withdrawal  bank to a cash box (change float).
 * Sent via Cash in Transit: dispatch = Dr transit / Cr source; completion = Dr destination (+ fee, ± variance) / Cr transit.
 */
class TreasuryTransfer extends Model
{
    use BelongsToHotel;

    public const KINDS = ['deposit' => 'Cash / cheque banking', 'sweep' => 'Settlement sweep', 'transfer' => 'Bank transfer', 'withdrawal' => 'Cash withdrawal'];

    public const STATUSES = ['requested' => 'Awaiting approval', 'approved' => 'Approved', 'in_transit' => 'In transit', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];

    public const TONES = ['requested' => 'amber', 'approved' => 'blue', 'in_transit' => 'purple', 'completed' => 'green', 'cancelled' => 'slate', 'rejected' => 'red'];

    /** Bank of Ghana note & coin denominations (GH₵) for the deposit cash count */
    public const DENOMINATIONS = ['200', '100', '50', '20', '10', '5', '2', '1', '0.50', '0.20', '0.10'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['cash_amount' => 'decimal:2', 'cheque_amount' => 'decimal:2', 'amount' => 'decimal:2', 'fee' => 'decimal:2', 'received_amount' => 'decimal:2', 'variance' => 'decimal:2',
            'via_transit' => 'boolean', 'date' => 'date', 'dispatched_on' => 'date', 'completed_on' => 'date', 'approved_at' => 'datetime', 'cash_count' => 'array'];
    }

    public function from()
    {
        return $this->belongsTo(TreasuryAccount::class, 'from_account_id');
    }

    public function to()
    {
        return $this->belongsTo(TreasuryAccount::class, 'to_account_id');
    }

    public function cheques()
    {
        return $this->hasMany(ChequeReceived::class, 'transfer_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatcher()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function dispatchJournal()
    {
        return $this->belongsTo(GlJournal::class, 'dispatch_journal_id');
    }

    public function completeJournal()
    {
        return $this->belongsTo(GlJournal::class, 'complete_journal_id');
    }

    public function feeAccount()
    {
        return $this->belongsTo(AccountHead::class, 'fee_account_id');
    }

    /** what the destination is credited with (after the fee and any variance) */
    public function net(): float
    {
        $recv = $this->received_amount !== null ? (float) $this->received_amount : (float) $this->amount;

        return round($recv - (float) $this->fee, 2);
    }

    public function open(): bool
    {
        return in_array($this->status, ['requested', 'approved', 'in_transit'], true);
    }
}
