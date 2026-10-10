<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * v5.6 A period-end schedule:
 *  - accrual     cost incurred but not yet billed – Dr expense / Cr accrued expenses at month end, reversed on the 1st;
 *  - prepayment  cost paid in advance – Dr expense / Cr prepaid expenses, spread over the months;
 *  - deferral    income received in advance – Dr deferred income / Cr income, spread over the months.
 */
class Accrual extends Model
{
    use BelongsToHotel;

    public const TYPES = ['accrual' => 'Accrued expense', 'prepayment' => 'Prepaid expense', 'deferral' => 'Deferred income'];

    public const BALANCE_ACCOUNT = ['accrual' => 'accrued_expenses', 'prepayment' => 'prepayments', 'deferral' => 'deferred_income'];

    public const STATUSES = ['pending' => 'Awaiting approval', 'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];

    public const TONES = ['pending' => 'amber', 'active' => 'blue', 'completed' => 'green', 'cancelled' => 'slate', 'rejected' => 'red'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'posted' => 'decimal:2', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function plAccount()
    {
        return $this->belongsTo(AccountHead::class, 'pl_account_id');
    }

    public function balanceAccount()
    {
        return $this->belongsTo(AccountHead::class, 'balance_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postings()
    {
        return $this->hasMany(PeriodPosting::class, 'source_id')->where('source', 'accrual')->orderBy('period')->orderBy('kind');
    }

    public function endPeriod(): string
    {
        return Carbon::parse($this->start_period.'-01')->addMonthsNoOverflow(max(1, (int) $this->months) - 1)->format('Y-m');
    }

    /** the months of the schedule: YYYY-MM => amount (the last month absorbs rounding) */
    public function schedule(): array
    {
        $n = max(1, (int) $this->months);
        $per = round((float) $this->amount / $n, 2);
        $out = [];
        $d = Carbon::parse($this->start_period.'-01');
        for ($i = 1; $i <= $n; $i++) {
            $out[$d->format('Y-m')] = $i === $n ? round((float) $this->amount - $per * ($n - 1), 2) : $per;
            $d->addMonthNoOverflow();
        }

        return $out;
    }

    public function remaining(): float
    {
        return round((float) $this->amount - (float) $this->posted, 2);
    }
}
