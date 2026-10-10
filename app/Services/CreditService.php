<?php

namespace App\Services;

use App\Mail\CompanyStatement;
use App\Models\Company;
use App\Models\CreditLog;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * v2.7 credit control for companies and agents: exposure (city-ledger balance + open folios billed to them),
 * overdue against their credit days (receipts applied FIFO), limit / hold / direct-billing checks at every
 * city-ledger transfer with an audited override, the automatic overdue review, and statements.
 */
class CreditService
{
    /**
     * Credit position of every company (or the given ones): [companyId => [...]].
     *
     * @return Collection<int, array>
     */
    public static function positions(?array $companyIds = null): Collection
    {
        $asOn = bdate()->copy()->startOfDay();
        $bills = FolioTransaction::where('category', 'city_ledger')->where('is_void', false)->whereNotNull('company_id')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->orderBy('business_date')->orderBy('id')->get(['company_id', 'business_date', 'amount'])->groupBy('company_id');
        $paid = Voucher::where('type', 'receipt')->whereNotNull('company_id')->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->get(['company_id', 'amount'])->groupBy('company_id')->map->sum('amount');
        $pending = Folio::where('status', 'open')->whereNotNull('company_id')->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->get()->groupBy('company_id')->map(fn ($fs) => round($fs->sum(fn ($f) => max(0, $f->balance())), 2));

        return Company::when($companyIds !== null, fn ($q) => $q->whereIn('id', $companyIds))->orderBy('name')->get()->mapWithKeys(function ($c) use ($bills, $paid, $pending, $asOn) {
            // credit notes to the company account (negative city-ledger lines) join the receipts pool and settle the oldest bills first
            $credit = (float) ($paid[$c->id] ?? 0) - (float) collect($bills[$c->id] ?? [])->filter(fn ($b) => (float) $b->amount < 0)->sum('amount');
            $billed = 0.0;
            $overdue = 0.0;
            $oldest = null;
            $open = [];
            foreach ($bills[$c->id] ?? [] as $b) {
                $amt = (float) $b->amount;
                $billed += $amt;
                if ($amt < 0) {
                    continue;
                }
                $applied = min($amt, $credit);
                $credit -= $applied;
                $left = round($amt - $applied, 2);
                if ($left <= 0) {
                    continue;
                }
                $age = (int) $b->business_date->diffInDays($asOn);
                $due = $b->business_date->copy()->addDays((int) $c->credit_days);
                $open[] = ['date' => $b->business_date, 'due' => $due, 'age' => $age, 'amount' => $left, 'overdue_days' => max(0, (int) $due->diffInDays($asOn, false))];
                if ($due->lt($asOn)) {
                    $overdue += $left;
                    $oldest ??= (int) $due->diffInDays($asOn);
                }
            }
            $outstanding = round($billed - (float) ($paid[$c->id] ?? 0), 2);
            $pend = (float) ($pending[$c->id] ?? 0);
            $limit = (float) $c->credit_limit;
            $exposure = round(max(0, $outstanding) + $pend, 2);

            return [$c->id => ['company' => $c, 'billed' => round($billed, 2), 'received' => round((float) ($paid[$c->id] ?? 0), 2),
                'outstanding' => $outstanding, 'overdue' => round($overdue, 2), 'overdue_days' => $oldest ?? 0, 'pending' => $pend, 'exposure' => $exposure,
                'limit' => $limit, 'available' => $limit > 0 ? round($limit - $exposure, 2) : null, 'over_limit' => $limit > 0 && $exposure > $limit,
                'used_pct' => $limit > 0 ? round($exposure / $limit * 100, 1) : null, 'open_bills' => $open]];
        });
    }

    public static function position(Company $c): array
    {
        return self::positions([$c->id])->get($c->id);
    }

    /**
     * Guard a city-ledger charge. Inactive companies and companies not approved for direct billing are refused
     * outright; a credit hold or a charge that takes the balance over the limit needs credit.override + a reason.
     */
    public static function assertCanCharge(Company $c, float $amount, ?string $overrideReason = null, ?Folio $folio = null): void
    {
        if (! $c->is_active) {
            throw ValidationException::withMessages(['credit' => "{$c->name} is inactive and cannot be billed."]);
        }
        if (! $c->direct_billing) {
            throw ValidationException::withMessages(['credit' => "{$c->name} is not approved for direct billing – settle with the guest."]);
        }
        $p = self::position($c);
        $problems = [];
        if ($c->onHold()) {
            $problems[] = "{$c->name} is on credit hold".($c->credit_hold_reason ? " ({$c->credit_hold_reason})" : '');
        }
        if ($p['limit'] > 0 && max(0, $p['outstanding']) + $amount > $p['limit'] + 0.004) {
            $problems[] = 'this bill of '.money($amount).' takes the city-ledger balance to '.money(max(0, $p['outstanding']) + $amount).', over the '.money($p['limit']).' limit';
        }
        if (! $problems) {
            return;
        }
        $reason = trim((string) $overrideReason);
        if ($reason === '' || ! can_do('credit.override')) {
            throw ValidationException::withMessages(['credit' => ucfirst(implode('; ', $problems)).'.'
                .(can_do('credit.override') ? ' Enter an override reason to bill it anyway.' : ' Settle with the guest, or ask a manager with credit override.')]);
        }
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['credit' => 'Give a meaningful override reason (at least 5 characters).']);
        }
        self::log($c, 'override', 'Override: '.implode('; ', $problems).' – '.$reason, $amount, $folio);
    }

    public static function log(Company $c, string $action, string $detail, ?float $amount = null, ?Folio $folio = null, bool $system = false): CreditLog
    {
        return CreditLog::create(['company_id' => $c->id, 'action' => $action, 'detail' => mb_substr($detail, 0, 255), 'amount' => $amount,
            'folio_id' => $folio?->id, 'user_id' => $system ? null : auth()->id()]);
    }

    public static function hold(Company $c, string $reason, bool $auto = false): void
    {
        if ($c->onHold()) {
            throw ValidationException::withMessages(['credit' => "{$c->name} is already on hold."]);
        }
        $c->forceFill(['credit_status' => 'hold', 'credit_hold_reason' => mb_substr($reason, 0, 200), 'credit_hold_auto' => $auto, 'credit_hold_at' => now()])->save();
        self::log($c, 'hold', ($auto ? 'Automatic hold: ' : 'Placed on hold: ').$reason, null, null, $auto);
        ActivityLogger::log('credit', 'hold', "Credit hold on {$c->name}: {$reason}", $c);
    }

    public static function release(Company $c, string $reason, bool $auto = false): void
    {
        if (! $c->onHold()) {
            throw ValidationException::withMessages(['credit' => "{$c->name} is not on hold."]);
        }
        $c->forceFill(['credit_status' => 'open', 'credit_hold_reason' => null, 'credit_hold_auto' => false, 'credit_hold_at' => null])->save();
        self::log($c, 'release', ($auto ? 'Automatic release: ' : 'Released: ').$reason, null, null, $auto);
        ActivityLogger::log('credit', 'release', "Credit hold released for {$c->name}: {$reason}", $c);
    }

    /** Change limit / credit days / direct billing, logging what changed. */
    public static function setTerms(Company $c, float $limit, int $days, bool $direct, ?string $note = null): int
    {
        $changes = [];
        if (round((float) $c->credit_limit, 2) !== round($limit, 2)) {
            $changes[] = 'limit '.money($c->credit_limit).' → '.money($limit);
        }
        if ((int) $c->credit_days !== $days) {
            $changes[] = "credit days {$c->credit_days} → {$days}";
        }
        if ((bool) $c->direct_billing !== $direct) {
            $changes[] = 'direct billing '.($direct ? 'approved' : 'withdrawn');
        }
        if ($changes) {
            $c->forceFill(['credit_limit' => $limit, 'credit_days' => $days, 'direct_billing' => $direct])->save();
            self::log($c, 'terms', ucfirst(implode(', ', $changes)).($note ? " – {$note}" : ''), $limit);
            ActivityLogger::log('credit', 'terms', "Credit terms for {$c->name}: ".implode(', ', $changes), $c);
        }

        return count($changes);
    }

    /**
     * Daily review: hold companies whose oldest unpaid bill is overdue by more than the grace days; release
     * automatic holds once nothing is overdue beyond the grace. Manual holds are never released here.
     *
     * @return array{held: int, released: int}
     */
    public static function review(): array
    {
        $out = ['held' => 0, 'released' => 0];
        if (setting('credit_auto_hold', '1') !== '1') {
            return $out;
        }
        $grace = (int) setting('credit_hold_grace_days', '15');
        foreach (self::positions() as $p) {
            $c = $p['company'];
            $late = collect($p['open_bills'])->filter(fn ($b) => $b['overdue_days'] > $grace);
            if ($late->isNotEmpty() && ! $c->onHold() && $c->is_active) {
                self::hold($c, money($late->sum('amount')).' overdue more than '.$grace.' days past '.$c->credit_days.'-day terms', true);
                $out['held']++;
            } elseif ($late->isEmpty() && $c->onHold() && $c->credit_hold_auto) {
                self::release($c, 'overdue bills cleared', true);
                $out['released']++;
            }
        }

        return $out;
    }

    /** E-mail the company a statement of open bills. */
    public static function sendStatement(Company $c): bool
    {
        $p = self::position($c);
        $log = MailService::send($c->email, new CompanyStatement($c, $p, hotel()), 'company_statement', $c);
        if ($log->status === 'sent') {
            $c->forceFill(['last_statement_at' => now()])->save();
            self::log($c, 'statement', 'Statement e-mailed to '.$c->email.' – outstanding '.money($p['outstanding']).', overdue '.money($p['overdue']), $p['outstanding']);
        }

        return $log->status === 'sent';
    }

    /** Due date of a bill posted on $date for a company. */
    public static function dueDate(Company $c, Carbon $date): Carbon
    {
        return $date->copy()->addDays((int) $c->credit_days);
    }
}
