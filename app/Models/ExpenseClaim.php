<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.7 A staff expense claim (draft → submitted → approved → paid, or rejected and resubmitted). */
class ExpenseClaim extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected $attributes = ['status' => 'draft', 'total' => 0, 'vat_total' => 0];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'vat_total' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_on' => 'date', 'paid_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines()
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('date')->orderBy('id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function pettyTxn()
    {
        return $this->belongsTo(PettyTxn::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    /** The claim belongs to the signed-in user (their employee record). */
    public function isOwn(?User $u = null): bool
    {
        $u ??= auth()->user();

        return $u && ((int) $this->employee?->user_id === (int) $u->id);
    }
}
