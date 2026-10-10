<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimLine;
use App\Models\PaymentMode;
use App\Models\PettyFloat;
use App\Models\Setting;
use App\Support\Numbering;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.7 Staff expense claims: an employee claims back what they paid for the hotel (transport, mileage, meals on duty, client
 * entertainment, airtime, small purchases) with receipts; a manager approves (never their own), accounts pays by bank,
 * Mobile Money or from a petty cash float. Approval accrues Dr expense (+ recoverable input VAT) / Cr 2260 Claims Payable;
 * payment clears 2260.
 */
class ExpenseClaimService
{
    public const DEFAULTS = ['claims_mileage_rate' => '2.50', 'claims_receipt_threshold' => '50', 'claims_max_age_days' => '60'];

    /** category => [label, system account] */
    public const CATEGORIES = [
        'transport' => ['Transport – taxi, trotro, bus, flights', 'travel'],
        'mileage' => ['Own vehicle mileage (km × rate)', 'travel'],
        'fuel' => ['Fuel for a hotel errand', 'travel'],
        'accommodation' => ['Accommodation on a business trip', 'travel'],
        'meals' => ['Meals & refreshments on duty', 'staff_welfare'],
        'entertainment' => ['Client entertainment', 'entertainment'],
        'airtime' => ['Airtime & data', 'telephone_exp'],
        'stationery' => ['Printing & stationery', 'stationery'],
        'repairs' => ['Small repairs & parts', 'repairs'],
        'other' => ['Other', 'misc_expense'],
    ];

    public static function cfg(string $k): string
    {
        return (string) setting($k, self::DEFAULTS[$k] ?? '');
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function employeeFor($user = null): ?Employee
    {
        $user ??= auth()->user();

        return $user ? Employee::where('user_id', $user->id)->where('is_active', true)->first() : null;
    }

    public static function canSee(ExpenseClaim $c): bool
    {
        $u = auth()->user();

        return $u && ($c->isOwn($u) || (int) $c->created_by === (int) $u->id || $u->hasPermission('claims.approve') || $u->hasPermission('claims.pay'));
    }

    public static function canEdit(ExpenseClaim $c): bool
    {
        $u = auth()->user();

        return $c->isEditable() && $u && ($c->isOwn($u) || (int) $c->created_by === (int) $u->id || $u->hasPermission('claims.approve'));
    }

    protected static function assertEdit(ExpenseClaim $c): void
    {
        if (! $c->isEditable()) {
            throw new RuntimeException("{$c->claim_no} is {$c->status} and can no longer be changed.");
        }
        if (! self::canEdit($c)) {
            throw new RuntimeException('Only the claimant can change this claim.');
        }
    }

    public static function create(?int $employeeId, string $title, ?string $notes = null): ExpenseClaim
    {
        $own = self::employeeFor();
        $emp = $employeeId ? Employee::where('is_active', true)->find($employeeId) : $own;
        if (! $emp) {
            throw new RuntimeException('Your user is not linked to an employee record – ask HR, or an approver can raise the claim for you.');
        }
        if ((! $own || $emp->id !== $own->id) && ! auth()->user()->hasPermission('claims.approve')) {
            throw new RuntimeException('You can only claim for yourself.');
        }
        $c = ExpenseClaim::create(['claim_no' => Numbering::next('exp_claim'), 'employee_id' => $emp->id, 'title' => $title, 'notes' => $notes, 'created_by' => auth()->id()]);
        ActivityLogger::log('claims', 'create', "Expense claim {$c->claim_no} started for {$emp->name}: {$title}", $c);

        return $c;
    }

    public static function addLine(ExpenseClaim $c, array $d, ?UploadedFile $receipt = null): ExpenseClaimLine
    {
        self::assertEdit($c);
        $cat = $d['category'] ?? '';
        if (! isset(self::CATEGORIES[$cat])) {
            throw new RuntimeException('Choose what the expense was for.');
        }
        $date = Carbon::parse($d['date'])->toDateString();
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('The expense cannot be in the future.');
        }
        if ($date < bdate()->copy()->subDays((int) self::cfg('claims_max_age_days'))->toDateString()) {
            throw new RuntimeException('Expenses older than '.self::cfg('claims_max_age_days').' days cannot be claimed.');
        }
        $km = null;
        if ($cat === 'mileage') {
            $km = round((float) ($d['km'] ?? 0), 1);
            if ($km <= 0 || $km > 2000) {
                throw new RuntimeException('Enter the distance driven (km).');
            }
            $amount = round($km * (float) self::cfg('claims_mileage_rate'), 2);
            $vat = 0.0;
        } else {
            $amount = round((float) ($d['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new RuntimeException('Enter the amount.');
            }
            $vat = round((float) ($d['vat_amount'] ?? 0), 2);
            if ($vat < 0 || $vat > round($amount * PettyCashService::VAT_FRACTION, 2) + 0.01) {
                throw new RuntimeException('The VAT, NHIL & GETFund cannot be more than '.money(round($amount * PettyCashService::VAT_FRACTION, 2)).' (20/120 of the receipt).');
            }
            if ($amount > (float) self::cfg('claims_receipt_threshold') && ! $receipt && trim((string) ($d['receipt_ref'] ?? '')) === '') {
                throw new RuntimeException('Attach the receipt for anything over '.money((float) self::cfg('claims_receipt_threshold')).' (or enter the receipt / MoMo transaction number).');
            }
        }
        if (trim((string) ($d['description'] ?? '')) === '') {
            throw new RuntimeException('Describe the expense (where, why).');
        }

        return DB::transaction(function () use ($c, $date, $cat, $d, $km, $amount, $vat, $receipt) {
            $l = ExpenseClaimLine::create(['expense_claim_id' => $c->id, 'date' => $date, 'category' => $cat, 'expense_head_id' => AccountHead::sys(self::CATEGORIES[$cat][1]),
                'description' => trim($d['description']), 'km' => $km, 'amount' => $amount, 'vat_amount' => $vat,
                'receipt_path' => PettyCashService::storeReceipt($receipt, 'claim-receipts'), 'receipt_ref' => $d['receipt_ref'] ?? null]);
            self::recalc($c);

            return $l;
        });
    }

    public static function deleteLine(ExpenseClaimLine $l): void
    {
        $c = $l->claim;
        self::assertEdit($c);
        $l->delete();
        self::recalc($c);
    }

    public static function recalc(ExpenseClaim $c): void
    {
        $c->forceFill(['total' => round((float) $c->lines()->sum('amount'), 2), 'vat_total' => round((float) $c->lines()->sum('vat_amount'), 2)])->save();
    }

    public static function submit(ExpenseClaim $c): void
    {
        self::assertEdit($c);
        if (! $c->lines()->exists()) {
            throw new RuntimeException('Add at least one expense.');
        }
        $c->update(['status' => 'submitted', 'submitted_at' => now(), 'reject_reason' => null, 'approved_by' => null, 'approved_on' => null]);
        ActivityLogger::log('claims', 'submit', "{$c->claim_no} submitted: ".money($c->total), $c);
    }

    public static function approve(ExpenseClaim $c): void
    {
        if ($c->status !== 'submitted') {
            throw new RuntimeException('Only a submitted claim can be approved.');
        }
        if ($c->isOwn() || (int) $c->created_by === (int) auth()->id()) {
            throw new RuntimeException('You cannot approve a claim you made or raised.');
        }
        $c->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_on' => bdate()->toDateString()]);
        ActivityLogger::log('claims', 'approve', "{$c->claim_no} approved: ".money($c->total)." to {$c->employee->name}", $c);
    }

    public static function reject(ExpenseClaim $c, string $reason): void
    {
        if ($c->status !== 'submitted') {
            throw new RuntimeException('Only a submitted claim can be sent back.');
        }
        if ($c->isOwn()) {
            throw new RuntimeException('You cannot reject your own claim.');
        }
        $c->update(['status' => 'rejected', 'reject_reason' => $reason, 'approved_by' => auth()->id()]);
        ActivityLogger::log('claims', 'reject', "{$c->claim_no} sent back: {$reason}", $c);
    }

    /** Pay an approved claim by bank / Mobile Money / cash (payment mode), or in cash from a petty float. */
    public static function pay(ExpenseClaim $c, string $method, ?int $modeId, ?int $floatId, string $date, ?string $reference = null): void
    {
        if ($c->status !== 'approved') {
            throw new RuntimeException('Only an approved claim can be paid.');
        }
        $date = PettyCashService::assertDate($date);
        if ($date < $c->approved_on->toDateString()) {
            throw new RuntimeException('The payment cannot be dated before the approval ('.dmy($c->approved_on).').');
        }
        DB::transaction(function () use ($c, $method, $modeId, $floatId, $date, $reference) {
            if ($method === 'petty') {
                $f = PettyFloat::where('is_active', true)->find($floatId);
                if (! $f) {
                    throw new RuntimeException('Choose the petty cash float.');
                }
                $t = PettyCashService::payClaim($f, $c, $date);
                $c->update(['status' => 'paid', 'pay_method' => 'petty', 'petty_txn_id' => $t->id, 'payment_mode_id' => null, 'paid_on' => $date, 'pay_reference' => $t->txn_no, 'paid_by' => auth()->id()]);
            } else {
                $m = PaymentMode::where('is_active', true)->find($modeId);
                if (! $m || ! in_array($m->type, ['cash', 'bank', 'wallet'], true)) {
                    throw new RuntimeException('Choose how it was paid: bank transfer, Mobile Money or cash.');
                }
                $c->update(['status' => 'paid', 'pay_method' => 'mode', 'payment_mode_id' => $m->id, 'petty_txn_id' => null, 'paid_on' => $date, 'pay_reference' => $reference, 'paid_by' => auth()->id()]);
            }
        });
        ActivityLogger::log('claims', 'pay', "{$c->claim_no} paid to {$c->employee->name}: ".money($c->total).' via '.($c->pay_method === 'petty' ? 'petty cash' : $c->paymentMode?->name), $c);
    }

    public static function destroy(ExpenseClaim $c): void
    {
        self::assertEdit($c);
        $c->delete();
        ActivityLogger::log('claims', 'delete', "Expense claim {$c->claim_no} deleted");
    }

    public static function attention(): array
    {
        return ['to_approve' => ExpenseClaim::where('status', 'submitted')->count(), 'to_pay' => ExpenseClaim::where('status', 'approved')->count()];
    }

    // ─────────────────────────── ledger ───────────────────────────

    public static function forApproval(ExpenseClaim $c): ?array
    {
        if (! in_array($c->status, ['approved', 'paid'], true) || ! $c->approved_on) {
            return null;
        }
        $claimable = setting('gl_input_tax', '1') === '1';
        $lines = [];
        foreach ($c->lines->groupBy('expense_head_id') as $head => $g) {
            $vat = $claimable ? (float) $g->sum('vat_amount') : 0.0;
            $lines[] = [(int) $head, round((float) $g->sum('amount') - $vat, 2), $g->pluck('description')->take(3)->implode('; ')];
        }
        if ($claimable && ($v = round((float) $c->lines->sum('vat_amount'), 2)) > 0) {
            $lines[] = [AccountHead::sys('input_tax'), $v, 'Input VAT, NHIL & GETFund'];
        }
        $lines[] = [AccountHead::sys('claims_payable'), -round((float) $c->lines->sum('amount'), 2), $c->employee?->name];

        return ['date' => $c->approved_on->toDateString(), 'reference' => $c->claim_no, 'narration' => "Expense claim – {$c->employee?->name} – {$c->title}", 'lines' => $lines];
    }

    public static function forPayment(ExpenseClaim $c): ?array
    {
        if ($c->status !== 'paid' || $c->pay_method !== 'mode' || ! $c->paid_on) {
            return null;
        }
        $amt = round((float) $c->total, 2);

        return ['date' => $c->paid_on->toDateString(), 'reference' => $c->claim_no, 'narration' => "Expense claim paid – {$c->employee?->name}".($c->pay_reference ? " – {$c->pay_reference}" : ''),
            'lines' => [[AccountHead::sys('claims_payable'), $amt, $c->employee?->name], [LedgerService::modeAccount($c->payment_mode_id, 'bank'), -$amt, $c->paymentMode?->name]]];
    }
}
