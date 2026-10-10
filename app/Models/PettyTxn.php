<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.7 A movement of petty cash: top-up, expense voucher, IOU (advance) and its settlement, count variance, claim paid out. */
class PettyTxn extends Model
{
    use BelongsToHotel;

    public const TYPES = ['topup' => 'Top-up', 'expense' => 'Voucher', 'advance' => 'IOU issued', 'settle' => 'IOU settled', 'variance' => 'Count difference', 'claim_pay' => 'Expense claim paid'];

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'posted', 'spent' => 0, 'vat_amount' => 0, 'effect' => 0];

    protected function casts(): array
    {
        return ['date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'spent' => 'decimal:2', 'vat_amount' => 'decimal:2', 'effect' => 'decimal:2',
            'settled_at' => 'datetime', 'approved_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function float()
    {
        return $this->belongsTo(PettyFloat::class, 'petty_float_id');
    }

    public function head()
    {
        return $this->belongsTo(AccountHead::class, 'expense_head_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function advance()
    {
        return $this->belongsTo(self::class, 'advance_id');
    }

    public function settlement()
    {
        return $this->hasOne(self::class, 'advance_id')->where('type', 'settle')->where('status', 'posted');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function replenishment()
    {
        return $this->belongsTo(PettyReplenishment::class, 'petty_replenishment_id');
    }

    public function claim()
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Who received the money: the payee typed in, or the employee of an IOU. */
    public function party(): ?string
    {
        return $this->payee ?: $this->employee?->name;
    }

    public function isOpenAdvance(): bool
    {
        return $this->type === 'advance' && $this->status === 'posted' && ! $this->settled_at;
    }
}
