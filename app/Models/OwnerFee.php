<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.8 One month's management fees under the agreement: base fee, incentive fee (YTD true-up) and the FF&E reserve contribution. */
class OwnerFee extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['accrued' => 'Accrued – unpaid', 'paid' => 'Paid', 'reversed' => 'Reversed'];

    public const TONES = ['accrued' => 'amber', 'paid' => 'green', 'reversed' => 'slate'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        $m = [];
        foreach (['total_revenue', 'gop', 'base_fee', 'ffe_pct', 'ffe_amount', 'agop', 'agop_ytd', 'priority_ytd', 'incentive_ytd', 'incentive_prior', 'incentive_fee', 'total_fee'] as $k) {
            $m[$k] = 'decimal:2';
        }

        return $m + ['date' => 'date', 'paid_on' => 'date'];
    }

    public function agreement()
    {
        return $this->belongsTo(ManagementAgreement::class, 'management_agreement_id');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function paidJournal()
    {
        return $this->belongsTo(GlJournal::class, 'paid_journal_id');
    }

    public function paidAccount()
    {
        return $this->belongsTo(AccountHead::class, 'paid_account_id');
    }

    public function accruer()
    {
        return $this->belongsTo(User::class, 'accrued_by');
    }

    public function label(): string
    {
        return Carbon::parse($this->period.'-01')->format('F Y');
    }

    public function dueOn(): Carbon
    {
        return $this->date->copy()->addDays((int) ($this->agreement?->pay_days ?? 15));
    }
}
