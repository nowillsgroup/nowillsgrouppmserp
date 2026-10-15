<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\ReservationRoom;
use App\Models\RevenueAuthorisation;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.3 Revenue authorisations: complimentary and house-use rooms, rates below the expected rate and folio discounts are
 * requested by the desk and approved in advance by someone else (four-eyes), within authority limits – above the limit
 * only the general manager / controller (income_audit.close) can approve. Approving applies it: the stay's rate changes
 * for the authorised nights (and is put back afterwards by the night audit) or the discount is posted to the folio.
 * The income audit then accepts the matching exceptions automatically.
 */
class RevenueAuthService
{
    public static function request(array $d, User $u): RevenueAuthorisation
    {
        $kind = $d['kind'] ?? '';
        if (! array_key_exists($kind, RevenueAuthorisation::KINDS)) {
            throw new RuntimeException('Choose what needs authorising.');
        }
        $reason = trim((string) ($d['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Give the reason (at least 5 characters) – it is printed on the audit report.');
        }
        $attrs = ['kind' => $kind, 'reason' => mb_substr($reason, 0, 255), 'status' => 'pending', 'requested_by' => $u->id];
        if ($kind === 'discount') {
            $folio = ! empty($d['folio_id']) ? Folio::find($d['folio_id']) : null;
            if (! $folio || $folio->status !== 'open') {
                throw new RuntimeException('Choose an open folio for the discount.');
            }
            $amt = round((float) ($d['amount'] ?? 0), 2);
            $charges = (float) $folio->totals()['charges'];
            if ($amt <= 0 || $amt > $charges + 0.004) {
                throw new RuntimeException('The discount must be between GH₵ 0.01 and the charges on the folio ('.money($charges).').');
            }
            if (RevenueAuthorisation::where('folio_id', $folio->id)->where('kind', 'discount')->where('status', 'pending')->exists()) {
                throw new RuntimeException("A discount for {$folio->folio_no} is already waiting for approval.");
            }
            $attrs += ['folio_id' => $folio->id, 'amount' => $amt, 'reservation_room_id' => $folio->reservation_room_id];
        } else {
            $stay = ! empty($d['reservation_room_id']) ? ReservationRoom::with('reservation')->find($d['reservation_room_id']) : null;
            if (! $stay || ! in_array($stay->status, ['reserved', 'checked_in', 'checked_out'], true)) {
                throw new RuntimeException('Choose the stay (a reserved, in-house or departed room).');
            }
            $first = $stay->arrival_date->toDateString();
            $last = $stay->departure_date->copy()->subDay()->max($stay->arrival_date)->toDateString();
            $from = ! empty($d['date_from']) ? Carbon::parse($d['date_from'])->toDateString() : max($first, bdate()->toDateString());
            $to = ! empty($d['date_to']) ? Carbon::parse($d['date_to'])->toDateString() : $last;
            if ($from < $first || $to > $last || $to < $from) {
                throw new RuntimeException('The nights must fall within the stay ('.dmy($first).' – '.dmy($last).').');
            }
            $amt = 0.0;
            if ($kind === 'rate') {
                $amt = round((float) ($d['amount'] ?? 0), 2);
                if ($amt <= 0) {
                    throw new RuntimeException('Enter the rate to approve (use a complimentary authorisation for a free room).');
                }
                if ($amt > (float) $stay->rate + 0.004 && $stay->status !== 'checked_out') {
                    throw new RuntimeException('The approved rate cannot be above the current rate of '.money($stay->rate).'.');
                }
            }
            if (RevenueAuthorisation::where('reservation_room_id', $stay->id)->whereIn('kind', ['comp', 'house_use', 'rate'])->where('status', 'pending')->exists()) {
                throw new RuntimeException('A rate authorisation for this stay is already waiting for approval.');
            }
            $attrs += ['reservation_room_id' => $stay->id, 'date_from' => $from, 'date_to' => $to, 'amount' => $amt];
        }
        $a = RevenueAuthorisation::create($attrs + ['auth_no' => Numbering::next('rev_auth')]);
        ActivityLogger::log('income_audit', 'auth_requested', "{$a->auth_no} requested – ".RevenueAuthorisation::KINDS[$kind].' – '.$a->subject(), $a);

        return $a;
    }

    /** Revenue given away, used for the authority limit. */
    public static function value(RevenueAuthorisation $a): float
    {
        if ($a->kind === 'discount') {
            return (float) $a->amount;
        }
        $rate = (float) ($a->previous_rate ?? $a->stay?->rate ?? 0);
        if ($a->stay && $a->date_from && ($exp = IncomeAuditService::expectedRate($a->stay, $a->date_from->toDateString()))) {
            $rate = max($rate, (float) $exp['rate']); // measured against the rate plan / contract / rack rate, not a rate already cut
        }

        return round(max(0, $rate - (float) $a->amount) * max(1, $a->nights()), 2);
    }

    /** Needs the general manager / controller? */
    public static function needsSenior(RevenueAuthorisation $a): bool
    {
        if (in_array($a->kind, ['comp', 'house_use'], true) && $a->nights() > (int) IncomeAuditService::cfg('ia_comp_nights_limit')) {
            return true;
        }

        return self::value($a) > (float) IncomeAuditService::cfg('ia_authorise_limit');
    }

    public static function approve(RevenueAuthorisation $a, User $u, ?string $note = null): RevenueAuthorisation
    {
        if ($a->status !== 'pending') {
            throw new RuntimeException('Only a pending request can be approved.');
        }
        if ((int) IncomeAuditService::cfg('ia_four_eyes') && $a->requested_by === $u->id) {
            throw new RuntimeException('You cannot approve your own request – ask another manager.');
        }
        if (self::needsSenior($a) && ! $u->hasPermission('income_audit.close')) {
            throw new RuntimeException('Above your authority ('.money(IncomeAuditService::cfg('ia_authorise_limit')).' / '.IncomeAuditService::cfg('ia_comp_nights_limit')
                .' complimentary night(s)) – the general manager or financial controller must approve.');
        }
        DB::transaction(function () use ($a, $u, $note) {
            $a->update(['status' => 'approved', 'decided_by' => $u->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr(trim($note), 0, 255) : null]);
            if ($a->kind === 'discount') {
                $folio = $a->folio;
                if ($folio && $folio->status === 'open') {
                    $tx = FolioService::discount($folio, (float) $a->amount, "{$a->reason} ({$a->auth_no})");
                    $tx->update(['source_type' => 'rev_auth', 'source_id' => $a->id, 'reference' => $a->auth_no, 'posted_by' => $u->id]);
                    $a->update(['folio_transaction_id' => $tx->id]);
                }
            } else {
                self::apply($a);
            }
        });
        ActivityLogger::log('income_audit', 'auth_approved', "{$a->auth_no} approved by {$u->name} – ".RevenueAuthorisation::KINDS[$a->kind].' – '.$a->subject(), $a);

        return $a->refresh();
    }

    public static function decline(RevenueAuthorisation $a, User $u, string $note): void
    {
        if ($a->status !== 'pending') {
            throw new RuntimeException('Only a pending request can be declined.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('Say why the request is declined.');
        }
        $a->update(['status' => 'declined', 'decided_by' => $u->id, 'decided_at' => now(), 'decision_note' => mb_substr(trim($note), 0, 255)]);
        ActivityLogger::log('income_audit', 'auth_declined', "{$a->auth_no} declined – {$note}", $a);
    }

    public static function cancel(RevenueAuthorisation $a, User $u): void
    {
        if ($a->status !== 'pending') {
            throw new RuntimeException('Only a pending request can be withdrawn.');
        }
        if ($a->requested_by !== $u->id && ! $u->hasPermission('income_audit.authorise')) {
            throw new RuntimeException('Only the requester or a manager can withdraw it.');
        }
        $a->update(['status' => 'cancelled', 'decided_by' => $u->id, 'decided_at' => now()]);
    }

    /** Change the stay's rate for the authorised nights once tonight falls in the period. */
    public static function apply(RevenueAuthorisation $a): bool
    {
        $stay = $a->stay;
        $today = bdate()->toDateString();
        if ($a->applied_at || ! $stay || ! in_array($stay->status, ['reserved', 'checked_in'], true) || $a->date_from->toDateString() > $today || $a->date_to->toDateString() < $today) {
            return false;
        }
        $a->update(['previous_rate' => $stay->rate, 'applied_at' => now()]);
        $stay->update(['rate' => $a->kind === 'rate' ? $a->amount : 0]);

        return true;
    }

    /** Night audit: start authorisations whose period has begun, put the rate back after the period ends. */
    public static function nightly(): array
    {
        $applied = 0;
        $restored = 0;
        $today = bdate()->toDateString();
        foreach (RevenueAuthorisation::with('stay')->where('status', 'approved')->whereIn('kind', ['comp', 'house_use', 'rate'])->whereNull('applied_at')
            ->whereDate('date_from', '<=', $today)->get() as $a) {
            $applied += self::apply($a) ? 1 : 0;
        }
        foreach (RevenueAuthorisation::with('stay')->where('status', 'approved')->whereNotNull('applied_at')->whereNull('restored_at')->whereDate('date_to', '<', $today)->get() as $a) {
            $stay = $a->stay;
            if ($stay && in_array($stay->status, ['reserved', 'checked_in'], true) && abs((float) $stay->rate - ($a->kind === 'rate' ? (float) $a->amount : 0)) < 0.005) {
                $stay->update(['rate' => $a->previous_rate]);
                $restored++;
            }
            $a->update(['restored_at' => now()]);
        }

        return ['applied' => $applied, 'restored' => $restored];
    }

    /** An approved authorisation covering this item (used by the audit checks). */
    public static function match(array $kinds, ?int $stayId, ?int $folioId, ?string $date, float $amount = 0): ?RevenueAuthorisation
    {
        return RevenueAuthorisation::with('decider')->where('status', 'approved')->whereIn('kind', $kinds)
            ->when($folioId && in_array('discount', $kinds, true), fn ($q) => $q->where('folio_id', $folioId), fn ($q) => $q->where('reservation_room_id', $stayId))
            ->orderByDesc('id')->get()
            ->first(function ($a) use ($date, $amount) {
                if ($a->kind === 'discount') {
                    return (float) $a->amount + 0.004 >= $amount;
                }
                if (! $a->covers($date)) {
                    return false;
                }

                return $a->kind !== 'rate' || $amount + 0.004 >= (float) $a->amount; // posted at or above the approved rate
            });
    }

    public static function pendingCount(): int
    {
        return RevenueAuthorisation::where('status', 'pending')->count();
    }
}
