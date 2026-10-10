<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\FolioTransaction;
use App\Models\ReservationRoom;
use App\Models\Voucher;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Travel-agent / company commissions. Accrued once per stay at check-out on the room revenue
 * actually charged (room tariff + extra person, net of voids), at the company's commission %.
 * Paying a batch creates one payment voucher and marks the rows paid.
 */
class CommissionService
{
    public static function roomRevenue(ReservationRoom $stay): float
    {
        return round((float) FolioTransaction::whereHas('folio', fn ($q) => $q->where('reservation_room_id', $stay->id))
            ->where('type', 'charge')->whereIn('category', ['room', 'extra_bed'])->where('is_void', false)->sum('amount'), 2);
    }

    public static function accrue(ReservationRoom $stay): ?AgentCommission
    {
        $res = $stay->reservation;
        $company = $res?->company;
        // v2.7: a contract's commission % (if set) overrides the agent's default – including 0 for net / non-commissionable contracts
        $pct = $res?->rateContract && $res->rateContract->commission_pct !== null ? (float) $res->rateContract->commission_pct : (float) $company?->commission_pct;
        if (! $company || $pct <= 0 || AgentCommission::where('reservation_room_id', $stay->id)->exists()) {
            return null;
        }
        $revenue = self::roomRevenue($stay);
        if ($revenue <= 0) {
            return null;
        }
        $c = AgentCommission::create([
            'company_id' => $company->id, 'reservation_id' => $res->id, 'reservation_room_id' => $stay->id, 'business_date' => bdate(),
            'nights' => max(1, $stay->arrival_date->diffInDays($stay->departure_date)), 'room_revenue' => $revenue, 'pct' => $pct,
            'amount' => round($revenue * $pct / 100, 2), 'status' => 'due',
        ]);
        ActivityLogger::log('accounts', 'commission_accrued', "Commission {$pct}% (".money($c->amount).") accrued to {$company->name} for {$res->reservation_no}", $c);

        return $c;
    }

    /** @param  int[]  $ids */
    public static function pay(Company $company, array $ids, int $paymentModeId, string $date, ?string $reference): Voucher
    {
        return DB::transaction(function () use ($company, $ids, $paymentModeId, $date, $reference) {
            $rows = AgentCommission::where('company_id', $company->id)->whereIn('id', $ids)->where('status', 'due')->lockForUpdate()->get();
            if ($rows->isEmpty() || $rows->count() !== count(array_unique($ids))) {
                throw new RuntimeException('Select due commissions of '.$company->name.' only.');
            }
            $head = AccountHead::firstOrCreate(['name' => 'Agent Commission'], ['type' => 'expense']);
            $v = Voucher::create([
                'voucher_no' => Numbering::next('voucher'), 'type' => 'payment', 'date' => $date, 'account_head_id' => $head->id,
                'payment_mode_id' => $paymentModeId, 'party_name' => $company->name, 'amount' => round($rows->sum('amount'), 2),
                'reference' => $reference, 'narration' => 'Commission for '.$rows->count().' stay(s): '.$rows->load('reservation')->pluck('reservation.reservation_no')->unique()->join(', '),
                'user_id' => auth()->id(),
            ]);
            AgentCommission::whereIn('id', $rows->pluck('id'))->update(['status' => 'paid', 'voucher_id' => $v->id, 'paid_at' => now()]);
            ActivityLogger::log('accounts', 'commission_paid', 'Paid commission '.money($v->amount)." to {$company->name} ({$v->voucher_no})", $v);

            return $v;
        });
    }
}
