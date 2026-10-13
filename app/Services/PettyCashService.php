<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\ExpenseClaim;
use App\Models\PaymentMode;
use App\Models\PettyCount;
use App\Models\PettyFloat;
use App\Models\PettyReplenishment;
use App\Models\PettyTxn;
use App\Models\Setting;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v4.7 Petty cash on the imprest system. Each float has its own asset account (group "Petty Cash") and a fixed imprest.
 * Vouchers and IOUs take cash out; settlements bring change back; the float is topped back up to the imprest by an
 * approved replenishment paid from the bank / main cash; physical counts post shortages and overages once approved.
 * Every movement posts to the ledger through LedgerService::sync() (source "petty") when the night audit closes its date.
 */
class PettyCashService
{
    public const DEFAULTS = ['petty_receipt_threshold' => '100', 'petty_iou_days' => '3', 'petty_count_days' => '7', 'petty_low_pct' => '25', 'petty_variance_tolerance' => '1.00'];

    /** Cedi notes and coins (Bank of Ghana): key => [value, label] */
    public const DENOMS = [
        'n200' => [200, 'GH₵200'], 'n100' => [100, 'GH₵100'], 'n50' => [50, 'GH₵50'], 'n20' => [20, 'GH₵20'], 'n10' => [10, 'GH₵10'],
        'n5' => [5, 'GH₵5'], 'n2' => [2, 'GH₵2 note'], 'n1' => [1, 'GH₵1 note'],
        'c2' => [2, 'GH₵2 coin'], 'c1' => [1, 'GH₵1 coin'], 'c50' => [0.50, '50 Gp'], 'c20' => [0.20, '20 Gp'], 'c10' => [0.10, '10 Gp'], 'c5' => [0.05, '5 Gp'], 'c1p' => [0.01, '1 Gp'],
    ];

    public const VAT_FRACTION = 20 / 120; // VAT 15% + NHIL 2.5% + GETFund 2.5% inside a tax-inclusive receipt

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

    // ─────────────────────────── balances ───────────────────────────

    public static function balance(PettyFloat $f, ?string $asOf = null): float
    {
        return round((float) PettyTxn::where('petty_float_id', $f->id)->where('status', 'posted')->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))->sum('effect'), 2);
    }

    public static function pending(PettyFloat $f): float
    {
        return round((float) PettyTxn::where('petty_float_id', $f->id)->where('status', 'pending')->sum('amount'), 2);
    }

    /** Cash that can still be paid out: the balance less vouchers / IOUs awaiting approval. */
    public static function available(PettyFloat $f): float
    {
        return round(self::balance($f) - self::pending($f), 2);
    }

    public static function openIous(PettyFloat $f): Collection
    {
        return PettyTxn::with('employee')->where('petty_float_id', $f->id)->where('type', 'advance')->where('status', 'posted')->whereNull('settled_at')->orderBy('date')->get();
    }

    /** Paid-out items not yet covered by a replenishment request. */
    public static function unreplenished(PettyFloat $f): Collection
    {
        return PettyTxn::with('head', 'employee')->where('petty_float_id', $f->id)->where('status', 'posted')->whereIn('type', ['expense', 'settle', 'variance', 'claim_pay'])
            ->whereNull('petty_replenishment_id')->orderBy('date')->orderBy('id')->get();
    }

    /** Amount that restores the float to its imprest: imprest − cash − IOUs still out. */
    public static function topUpDue(PettyFloat $f): float
    {
        return round(max(0, (float) $f->imprest_amount - self::balance($f) - (float) self::openIous($f)->sum('amount')), 2);
    }

    public static function stats(PettyFloat $f): array
    {
        $bal = self::balance($f);
        $ious = self::openIous($f);
        $last = PettyCount::where('petty_float_id', $f->id)->whereIn('status', ['agreed', 'posted'])->orderByDesc('date')->orderByDesc('id')->first();
        $today = bdate()->toDateString();

        return [
            'balance' => $bal, 'pending' => self::pending($f), 'available' => round($bal - self::pending($f), 2),
            'ious' => round((float) $ious->sum('amount'), 2), 'ious_count' => $ious->count(),
            'overdue_ious' => $ious->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today)->count(),
            'top_up' => self::topUpDue($f), 'pct' => (float) $f->imprest_amount > 0 ? round(100 * $bal / (float) $f->imprest_amount) : 0,
            'low' => (float) $f->imprest_amount > 0 && $bal < (float) $f->imprest_amount * (int) self::cfg('petty_low_pct') / 100,
            'last_count' => $last, 'count_due' => ! $last || $last->date->diffInDays(bdate()) > (int) self::cfg('petty_count_days'),
            'open_repl' => PettyReplenishment::where('petty_float_id', $f->id)->whereIn('status', ['requested', 'approved'])->first(),
        ];
    }

    public static function floats(): Collection
    {
        return PettyFloat::with('custodian', 'account', 'department')->orderByDesc('is_active')->orderBy('code')->get();
    }

    /** Items waiting for a manager: over-limit vouchers / IOUs, replenishments to approve or pay, count differences. */
    public static function approvals(): array
    {
        return [
            'txns' => PettyTxn::with('float', 'employee', 'creator', 'head')->where('status', 'pending')->orderBy('date')->get(),
            'repl' => PettyReplenishment::with('float', 'requester')->whereIn('status', ['requested', 'approved'])->orderBy('date')->get(),
            'counts' => PettyCount::with('float', 'counter')->where('status', 'pending')->orderBy('date')->get(),
        ];
    }

    public static function attentionCount(): int
    {
        $today = bdate()->toDateString();

        return PettyTxn::where('status', 'pending')->count()
            + PettyReplenishment::whereIn('status', ['requested', 'approved'])->count()
            + PettyCount::where('status', 'pending')->count()
            + PettyTxn::where('type', 'advance')->where('status', 'posted')->whereNull('settled_at')->whereDate('due_date', '<', $today)->count();
    }

    // ─────────────────────────── guards ───────────────────────────

    public static function canOperate(PettyFloat $f, $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->hasPermission('petty.approve')) {
            return true;
        }

        return $user->hasPermission('petty.manage') && (! $f->custodian_id || (int) $f->custodian_id === (int) $user->id);
    }

    protected static function assertOperate(PettyFloat $f): void
    {
        if (! $f->is_active) {
            throw new RuntimeException("{$f->name} is closed.");
        }
        if (! self::canOperate($f)) {
            throw new RuntimeException("Only the custodian of {$f->name} ({$f->custodian?->name}) or an approver can do this.");
        }
    }

    public static function assertDate(string $date): string
    {
        $d = Carbon::parse($date)->toDateString();
        if ($d > bdate()->toDateString()) {
            throw new RuntimeException('The date cannot be after today ('.dmy(bdate()).').');
        }
        if ($d < bdate()->copy()->subDays(31)->toDateString()) {
            throw new RuntimeException('Petty cash can be back-dated by at most 31 days.');
        }
        if (($lock = LedgerService::lockDate()) && $d <= $lock) {
            throw new RuntimeException('The books are locked up to '.dmy($lock).'.');
        }

        return $d;
    }

    protected static function assertExpenseHead(?int $id): AccountHead
    {
        $h = $id ? AccountHead::where('is_active', true)->find($id) : null;
        if (! $h || $h->type !== 'expense') {
            throw new RuntimeException('Choose an expense account.');
        }

        return $h;
    }

    protected static function vat(float $amount, $vat): float
    {
        $vat = round((float) $vat, 2);
        if ($vat < 0 || $vat > round($amount * self::VAT_FRACTION, 2) + 0.01) {
            throw new RuntimeException('The VAT, NHIL & GETFund cannot be more than '.money(round($amount * self::VAT_FRACTION, 2)).' (20/120 of the receipt).');
        }

        return $vat;
    }

    protected static function receiptCheck(float $amount, ?UploadedFile $file, ?string $reason, ?string $existing = null): void
    {
        if ($amount > (float) self::cfg('petty_receipt_threshold') && ! $file && ! $existing && trim((string) $reason) === '') {
            throw new RuntimeException('Attach the receipt for anything over '.money((float) self::cfg('petty_receipt_threshold')).', or say why there is none.');
        }
    }

    public static function storeReceipt(?UploadedFile $file, string $folder = 'petty-receipts'): ?string
    {
        return $file ? $file->store($folder.'/'.Tenant::id().'/'.now()->format('Y-m'), 'local') : null;
    }

    // ─────────────────────────── floats ───────────────────────────

    protected static function nextCode(): string
    {
        $used = AccountHead::pluck('code')->filter()->flip();
        foreach (array_merge(range(1011, 1019), range(1070, 1099)) as $c) {
            if (! isset($used[(string) $c])) {
                return (string) $c;
            }
        }

        return '';
    }

    /** Open a float: its own ledger account and the imprest drawn from the bank / main cash. */
    public static function createFloat(array $d): PettyFloat
    {
        $amount = round((float) $d['imprest_amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('The imprest must be more than zero.');
        }
        $mode = self::fundingMode($d['payment_mode_id'] ?? null);
        $date = self::assertDate($d['date'] ?? bdate()->toDateString());

        return DB::transaction(function () use ($d, $amount, $mode, $date) {
            $code = self::nextCode();
            $head = AccountHead::create(['name' => 'Petty Cash – '.$d['name'], 'code' => $code ?: null, 'type' => 'asset', 'group_name' => 'Petty Cash', 'is_active' => true]);
            $f = PettyFloat::create(['code' => strtoupper($d['code']), 'name' => $d['name'], 'account_head_id' => $head->id, 'custodian_id' => $d['custodian_id'] ?? null,
                'department_id' => $d['department_id'] ?? null, 'imprest_amount' => $amount, 'voucher_limit' => round((float) ($d['voucher_limit'] ?? 500), 2), 'notes' => $d['notes'] ?? null]);
            PettyTxn::create(['petty_float_id' => $f->id, 'txn_no' => Numbering::next('petty_topup'), 'type' => 'topup', 'date' => $date, 'description' => 'Opening imprest',
                'amount' => $amount, 'effect' => $amount, 'payment_mode_id' => $mode->id, 'status' => 'posted', 'approved_by' => auth()->id(), 'approved_at' => now(), 'created_by' => auth()->id()]);
            ActivityLogger::log('petty', 'float', "Petty cash float {$f->code} {$f->name} opened with ".money($amount)." from {$mode->name}", $f);

            return $f;
        });
    }

    public static function updateFloat(PettyFloat $f, array $d): PettyFloat
    {
        $f->update(['name' => $d['name'], 'custodian_id' => $d['custodian_id'] ?? null, 'department_id' => $d['department_id'] ?? null,
            'imprest_amount' => round((float) $d['imprest_amount'], 2), 'voucher_limit' => round((float) $d['voucher_limit'], 2), 'notes' => $d['notes'] ?? null]);
        $f->account?->update(['name' => 'Petty Cash – '.$f->name]);
        ActivityLogger::log('petty', 'float', "Petty cash float {$f->code} updated: imprest ".money($f->imprest_amount).', limit '.money($f->voucher_limit).', custodian '.($f->custodian?->name ?? 'anyone'), $f);

        return $f;
    }

    public static function setActive(PettyFloat $f, bool $active): void
    {
        if (! $active && (abs(self::balance($f)) >= 0.005 || self::openIous($f)->isNotEmpty() || PettyTxn::where('petty_float_id', $f->id)->where('status', 'pending')->exists())) {
            throw new RuntimeException('Bank the cash, settle the IOUs and clear pending vouchers before closing the float (balance '.money(self::balance($f)).').');
        }
        $f->update(['is_active' => $active]);
        ActivityLogger::log('petty', 'float', "Petty cash float {$f->code} ".($active ? 'reopened' : 'closed'), $f);
    }

    protected static function fundingMode($id): PaymentMode
    {
        $m = $id ? PaymentMode::where('is_active', true)->find($id) : null;
        if (! $m || ! in_array($m->type, ['cash', 'bank', 'wallet'], true)) {
            throw new RuntimeException('Choose where the cash comes from: main cash, bank or Mobile Money.');
        }

        return $m;
    }

    // ─────────────────────────── vouchers & IOUs ───────────────────────────

    protected static function outflow(PettyFloat $f, float $amount, bool $pending): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount.');
        }
        if (! $pending && $amount > self::available($f) + 0.001) {
            throw new RuntimeException('Only '.money(self::available($f)).' is left in '.$f->name.' – request a top-up first.');
        }
    }

    /** A petty cash voucher. Over the float's limit it waits for approval (the cash is not paid out yet). */
    public static function expense(PettyFloat $f, array $d, ?UploadedFile $receipt = null): PettyTxn
    {
        self::assertOperate($f);
        $date = self::assertDate($d['date']);
        $amount = round((float) $d['amount'], 2);
        $head = self::assertExpenseHead(isset($d['expense_head_id']) ? (int) $d['expense_head_id'] : null);
        $vat = ! empty($d['vat_invoice']) ? self::vat($amount, $d['vat_amount'] ?? round($amount * self::VAT_FRACTION, 2)) : 0.0;
        $pending = $amount > (float) $f->voucher_limit;
        self::outflow($f, $amount, $pending);
        if ($pending && $amount > self::available($f) + 0.001) {
            throw new RuntimeException('Only '.money(self::available($f)).' is left in '.$f->name.'.');
        }
        self::receiptCheck($amount, $receipt, $d['no_receipt_reason'] ?? null);
        if (trim((string) ($d['payee'] ?? '')) === '') {
            throw new RuntimeException('Who was paid?');
        }

        $t = PettyTxn::create(['petty_float_id' => $f->id, 'txn_no' => Numbering::next('petty_voucher'), 'type' => 'expense', 'date' => $date, 'payee' => trim($d['payee']),
            'description' => $d['description'] ?? null, 'expense_head_id' => $head->id, 'amount' => $amount, 'vat_amount' => $vat, 'effect' => $pending ? 0 : -$amount,
            'status' => $pending ? 'pending' : 'posted', 'receipt_path' => self::storeReceipt($receipt), 'receipt_ref' => $d['receipt_ref'] ?? null,
            'no_receipt_reason' => $receipt ? null : ($d['no_receipt_reason'] ?? null), 'approved_by' => $pending ? null : auth()->id(), 'approved_at' => $pending ? null : now(), 'created_by' => auth()->id()]);
        ActivityLogger::log('petty', 'voucher', "{$t->txn_no} ".money($amount)." to {$t->payee} from {$f->name}".($pending ? ' – over the '.money($f->voucher_limit).' limit, awaiting approval' : ''), $t);

        return $t;
    }

    /** An IOU: cash advanced to a member of staff to buy something, settled later with receipts and change. */
    public static function issueIou(PettyFloat $f, array $d): PettyTxn
    {
        self::assertOperate($f);
        $date = self::assertDate($d['date']);
        $amount = round((float) $d['amount'], 2);
        $emp = \App\Models\Employee::where('is_active', true)->find($d['employee_id'] ?? 0);
        if (! $emp) {
            throw new RuntimeException('Choose the member of staff taking the cash.');
        }
        if (PettyTxn::where('type', 'advance')->where('status', 'posted')->whereNull('settled_at')->where('employee_id', $emp->id)->whereDate('due_date', '<', bdate()->toDateString())->exists()) {
            throw new RuntimeException("{$emp->name} has an overdue IOU – settle it first.");
        }
        $pending = $amount > (float) $f->voucher_limit;
        self::outflow($f, $amount, $pending);
        if ($pending && $amount > self::available($f) + 0.001) {
            throw new RuntimeException('Only '.money(self::available($f)).' is left in '.$f->name.'.');
        }
        $due = ! empty($d['due_date']) ? Carbon::parse($d['due_date'])->toDateString() : Carbon::parse($date)->addDays((int) self::cfg('petty_iou_days'))->toDateString();
        if ($due < $date) {
            throw new RuntimeException('The IOU cannot be due before it is issued.');
        }
        $t = PettyTxn::create(['petty_float_id' => $f->id, 'txn_no' => Numbering::next('petty_iou'), 'type' => 'advance', 'date' => $date, 'employee_id' => $emp->id,
            'description' => $d['description'] ?? null, 'amount' => $amount, 'effect' => $pending ? 0 : -$amount, 'due_date' => $due, 'status' => $pending ? 'pending' : 'posted',
            'approved_by' => $pending ? null : auth()->id(), 'approved_at' => $pending ? null : now(), 'created_by' => auth()->id()]);
        ActivityLogger::log('petty', 'iou', "{$t->txn_no} ".money($amount)." advanced to {$emp->name} from {$f->name}, due ".dmy($due), $t);

        return $t;
    }

    /** Settle an IOU: receipts spent against an expense account; change comes back or the shortfall is paid out. */
    public static function settleIou(PettyTxn $adv, array $d, ?UploadedFile $receipt = null): PettyTxn
    {
        $f = $adv->float;
        self::assertOperate($f);
        if (! $adv->isOpenAdvance()) {
            throw new RuntimeException('This IOU is not open.');
        }
        $date = self::assertDate($d['date']);
        if ($date < $adv->date->toDateString()) {
            throw new RuntimeException('The settlement cannot be dated before the IOU.');
        }
        $spent = round((float) $d['spent'], 2);
        if ($spent < 0) {
            throw new RuntimeException('Enter what was spent (0 if the cash came back unused).');
        }
        $head = $spent > 0 ? self::assertExpenseHead(isset($d['expense_head_id']) ? (int) $d['expense_head_id'] : null) : null;
        $vat = $spent > 0 && ! empty($d['vat_invoice']) ? self::vat($spent, $d['vat_amount'] ?? round($spent * self::VAT_FRACTION, 2)) : 0.0;
        $effect = round((float) $adv->amount - $spent, 2); // + change returned, − extra paid out
        if ($effect < 0 && -$effect > self::available($f) + 0.001) {
            throw new RuntimeException('The extra '.money(-$effect).' is more than the '.money(self::available($f)).' left in the float.');
        }
        if ($spent > 0) {
            self::receiptCheck($spent, $receipt, $d['no_receipt_reason'] ?? null);
        }

        return DB::transaction(function () use ($adv, $f, $date, $spent, $head, $vat, $effect, $receipt, $d) {
            $t = PettyTxn::create(['petty_float_id' => $f->id, 'txn_no' => $adv->txn_no.'-S', 'type' => 'settle', 'date' => $date, 'employee_id' => $adv->employee_id,
                'description' => $d['description'] ?? $adv->description, 'expense_head_id' => $head?->id, 'amount' => $adv->amount, 'spent' => $spent, 'vat_amount' => $vat,
                'effect' => $effect, 'advance_id' => $adv->id, 'status' => 'posted', 'receipt_path' => self::storeReceipt($receipt), 'receipt_ref' => $d['receipt_ref'] ?? null,
                'no_receipt_reason' => $receipt ? null : ($d['no_receipt_reason'] ?? null), 'approved_by' => auth()->id(), 'approved_at' => now(), 'created_by' => auth()->id()]);
            $adv->forceFill(['settled_at' => now()])->save();
            ActivityLogger::log('petty', 'iou_settle', "{$adv->txn_no} settled by {$adv->employee?->name}: spent ".money($spent).($effect > 0 ? ', change '.money($effect).' returned' : ($effect < 0 ? ', extra '.money(-$effect).' paid out' : '')), $t);

            return $t;
        });
    }

    public static function approve(PettyTxn $t): PettyTxn
    {
        if ($t->status !== 'pending') {
            throw new RuntimeException('Only a pending voucher / IOU can be approved.');
        }
        if ((int) $t->created_by === (int) auth()->id()) {
            throw new RuntimeException('Someone other than the person who wrote it must approve it.');
        }
        $f = $t->float;
        if ((float) $t->amount > self::balance($f) + 0.001) {
            throw new RuntimeException('Only '.money(self::balance($f)).' is in '.$f->name.' – top it up first.');
        }
        $t->update(['status' => 'posted', 'effect' => -(float) $t->amount, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLogger::log('petty', 'approve', "{$t->txn_no} ".money($t->amount).' approved', $t);

        return $t;
    }

    public static function reject(PettyTxn $t, string $reason): void
    {
        if ($t->status !== 'pending') {
            throw new RuntimeException('Only a pending voucher / IOU can be rejected.');
        }
        $t->update(['status' => 'rejected', 'reject_reason' => $reason, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLogger::log('petty', 'reject', "{$t->txn_no} rejected: {$reason}", $t);
    }

    /** Cancel a posted movement (the cash is back in the tin); its journal is reversed. */
    public static function void(PettyTxn $t, string $reason): void
    {
        if ($t->status !== 'posted' || ! in_array($t->type, ['expense', 'advance', 'settle'], true)) {
            throw new RuntimeException('Only a posted voucher, IOU or IOU settlement can be cancelled.');
        }
        if ($t->petty_replenishment_id && $t->replenishment && $t->replenishment->status === 'paid') {
            throw new RuntimeException("{$t->txn_no} was reimbursed by {$t->replenishment->repl_no} – it can no longer be cancelled.");
        }
        if ($t->type === 'advance' && $t->settled_at) {
            throw new RuntimeException('Cancel the settlement of this IOU first.');
        }
        DB::transaction(function () use ($t, $reason) {
            $t->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => auth()->id(), 'voided_at' => now(), 'petty_replenishment_id' => null]);
            if ($t->type === 'settle' && $t->advance) {
                $t->advance->forceFill(['settled_at' => null])->save();
            }
        });
        ActivityLogger::log('petty', 'void', "{$t->txn_no} cancelled: {$reason}", $t);
    }

    // ─────────────────────────── replenishment ───────────────────────────

    public static function requestReplenishment(PettyFloat $f, ?string $notes = null): PettyReplenishment
    {
        self::assertOperate($f);
        if (PettyReplenishment::where('petty_float_id', $f->id)->whereIn('status', ['requested', 'approved'])->exists()) {
            throw new RuntimeException('A top-up request for this float is already open.');
        }
        $amount = self::topUpDue($f);
        if ($amount < 0.01) {
            throw new RuntimeException("{$f->name} is at its imprest – nothing to top up.");
        }

        return DB::transaction(function () use ($f, $amount, $notes) {
            $r = PettyReplenishment::create(['petty_float_id' => $f->id, 'repl_no' => Numbering::next('petty_repl'), 'date' => bdate()->toDateString(), 'amount' => $amount,
                'requested_by' => auth()->id(), 'notes' => $notes]);
            PettyTxn::whereIn('id', self::unreplenished($f)->pluck('id'))->update(['petty_replenishment_id' => $r->id]);
            ActivityLogger::log('petty', 'repl', "{$r->repl_no}: top-up of ".money($amount)." requested for {$f->name}", $r);

            return $r;
        });
    }

    public static function approveReplenishment(PettyReplenishment $r): void
    {
        if ($r->status !== 'requested') {
            throw new RuntimeException('Only a requested top-up can be approved.');
        }
        if ((int) $r->requested_by === (int) auth()->id()) {
            throw new RuntimeException('Someone other than the requester must approve the top-up.');
        }
        $r->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLogger::log('petty', 'repl_approve', "{$r->repl_no} approved (".money($r->amount).')', $r);
    }

    public static function rejectReplenishment(PettyReplenishment $r, string $reason): void
    {
        if (! in_array($r->status, ['requested', 'approved'], true)) {
            throw new RuntimeException('This top-up is closed.');
        }
        DB::transaction(function () use ($r, $reason) {
            PettyTxn::where('petty_replenishment_id', $r->id)->update(['petty_replenishment_id' => null]);
            $r->update(['status' => 'rejected', 'reject_reason' => $reason, 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
        ActivityLogger::log('petty', 'repl_reject', "{$r->repl_no} rejected: {$reason}", $r);
    }

    /** Pay an approved top-up: cash moves from the bank / main cash into the float. */
    public static function payReplenishment(PettyReplenishment $r, int $modeId, string $date, ?string $reference = null): PettyTxn
    {
        if ($r->status !== 'approved') {
            throw new RuntimeException('Approve the top-up before paying it.');
        }
        $mode = self::fundingMode($modeId);
        $date = self::assertDate($date);
        if ($date < $r->date->toDateString()) {
            throw new RuntimeException('The payment cannot be dated before the request.');
        }

        return DB::transaction(function () use ($r, $mode, $date, $reference) {
            $t = PettyTxn::create(['petty_float_id' => $r->petty_float_id, 'txn_no' => Numbering::next('petty_topup'), 'type' => 'topup', 'date' => $date,
                'description' => "Top-up {$r->repl_no}", 'amount' => $r->amount, 'effect' => $r->amount, 'payment_mode_id' => $mode->id, 'receipt_ref' => $reference,
                'petty_replenishment_id' => $r->id, 'status' => 'posted', 'approved_by' => $r->approved_by, 'approved_at' => now(), 'created_by' => auth()->id()]);
            $r->update(['status' => 'paid', 'payment_mode_id' => $mode->id, 'paid_on' => $date, 'paid_by' => auth()->id(), 'reference' => $reference]);
            ActivityLogger::log('petty', 'repl_pay', "{$r->repl_no}: ".money($r->amount)." paid into {$r->float->name} from {$mode->name}", $r);

            return $t;
        });
    }

    // ─────────────────────────── cash counts ───────────────────────────

    public static function countTotal(array $qty): float
    {
        $sum = 0.0;
        foreach (self::DENOMS as $k => [$v]) {
            $n = (int) ($qty[$k] ?? 0);
            if ($n < 0) {
                throw new RuntimeException('Counts cannot be negative.');
            }
            $sum += $n * $v;
        }

        return round($sum, 2);
    }

    /** Record a count. Differences within the tolerance post straight away; larger ones wait for approval. */
    public static function count(PettyFloat $f, array $qty, ?string $note = null, ?int $witnessId = null): PettyCount
    {
        self::assertOperate($f);
        if (PettyCount::where('petty_float_id', $f->id)->where('status', 'pending')->exists()) {
            throw new RuntimeException('A count with a difference is waiting for approval.');
        }
        $counted = self::countTotal($qty);
        $book = self::balance($f);
        $var = round($counted - $book, 2);
        if (abs($var) >= 0.005 && trim((string) $note) === '') {
            throw new RuntimeException('The count is '.money($var).' '.($var < 0 ? 'short' : 'over').' – explain the difference.');
        }
        if ($witnessId && $witnessId === (int) auth()->id()) {
            throw new RuntimeException('The witness must be someone else.');
        }
        $tol = (float) self::cfg('petty_variance_tolerance');
        $status = abs($var) < 0.005 ? 'agreed' : (abs($var) <= $tol ? 'posted' : 'pending');
        $clean = collect(self::DENOMS)->mapWithKeys(fn ($d, $k) => [$k => (int) ($qty[$k] ?? 0)])->filter()->all();

        return DB::transaction(function () use ($f, $clean, $counted, $book, $var, $status, $note, $witnessId) {
            $c = PettyCount::create(['petty_float_id' => $f->id, 'count_no' => Numbering::next('petty_count'), 'date' => bdate()->toDateString(), 'denominations' => $clean,
                'counted' => $counted, 'book' => $book, 'variance' => $var, 'status' => $status, 'note' => $note, 'counted_by' => auth()->id(), 'witness_id' => $witnessId]);
            if ($status === 'posted') {
                self::postVariance($c);
            }
            ActivityLogger::log('petty', 'count', "{$c->count_no} {$f->name}: counted ".money($counted).', book '.money($book).($var ? ', difference '.money($var) : ' – agreed'), $c);

            return $c;
        });
    }

    protected static function postVariance(PettyCount $c): PettyTxn
    {
        $t = PettyTxn::create(['petty_float_id' => $c->petty_float_id, 'txn_no' => Numbering::next('petty_adj'), 'type' => 'variance', 'date' => $c->date,
            'description' => ($c->variance < 0 ? 'Cash short' : 'Cash over')." on count {$c->count_no}".($c->note ? ": {$c->note}" : ''),
            'amount' => abs((float) $c->variance), 'effect' => (float) $c->variance, 'status' => 'posted', 'approved_by' => auth()->id(), 'approved_at' => now(), 'created_by' => $c->counted_by]);
        $c->forceFill(['variance_txn_id' => $t->id])->save();

        return $t;
    }

    public static function approveCount(PettyCount $c): void
    {
        if ($c->status !== 'pending') {
            throw new RuntimeException('This count is not waiting for approval.');
        }
        if ((int) $c->counted_by === (int) auth()->id()) {
            throw new RuntimeException('Someone other than the counter must approve the difference.');
        }
        if (abs(self::balance($c->float) - (float) $c->book) >= 0.005) {
            throw new RuntimeException('Cash has moved since this count ('.money($c->book).' then, '.money(self::balance($c->float)).' now) – reject it and count again.');
        }
        DB::transaction(function () use ($c) {
            $c->update(['status' => 'posted', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            self::postVariance($c);
        });
        ActivityLogger::log('petty', 'count_approve', "{$c->count_no}: difference ".money($c->variance).' written off', $c);
    }

    public static function rejectCount(PettyCount $c, string $reason): void
    {
        if ($c->status !== 'pending') {
            throw new RuntimeException('This count is not waiting for approval.');
        }
        $c->update(['status' => 'rejected', 'note' => trim(($c->note ? $c->note.' · ' : '').'Rejected: '.$reason), 'approved_by' => auth()->id(), 'approved_at' => now()]);
        ActivityLogger::log('petty', 'count_reject', "{$c->count_no} rejected: {$reason}", $c);
    }

    // ─────────────────────────── expense claims paid in cash ───────────────────────────

    public static function payClaim(PettyFloat $f, ExpenseClaim $c, string $date): PettyTxn
    {
        self::assertOperate($f);
        $date = self::assertDate($date);
        self::outflow($f, (float) $c->total, false);

        return PettyTxn::create(['petty_float_id' => $f->id, 'txn_no' => Numbering::next('petty_voucher'), 'type' => 'claim_pay', 'date' => $date, 'employee_id' => $c->employee_id,
            'description' => "Expense claim {$c->claim_no} – {$c->title}", 'amount' => $c->total, 'effect' => -(float) $c->total, 'expense_claim_id' => $c->id,
            'status' => 'posted', 'approved_by' => auth()->id(), 'approved_at' => now(), 'created_by' => auth()->id()]);
    }

    // ─────────────────────────── ledger ───────────────────────────

    /** The journal for one movement (null unless posted). Input tax is recoverable unless the GL setting says otherwise. */
    public static function forTxn(PettyTxn $t): ?array
    {
        if ($t->status !== 'posted' || ! $t->float) {
            return null;
        }
        $cash = (int) $t->float->account_head_id;
        $amt = round((float) $t->amount, 2);
        $claimable = setting('gl_input_tax', '1') === '1';
        $spend = function (float $gross, float $vat, ?int $head, string $memo) use ($claimable) {
            $vat = $claimable ? $vat : 0.0;
            $out = [[$head ?: AccountHead::sys('misc_expense'), round($gross - $vat, 2), $memo]];
            if ($vat > 0) {
                $out[] = [AccountHead::sys('input_tax'), $vat, 'Input VAT, NHIL & GETFund'];
            }

            return $out;
        };
        $who = $t->party();
        $lines = match ($t->type) {
            'topup' => [[$cash, $amt, $t->description], [LedgerService::modeAccount($t->payment_mode_id, 'cash'), -$amt, $t->paymentMode?->name]],
            'expense' => array_merge($spend($amt, (float) $t->vat_amount, $t->expense_head_id, (string) $who), [[$cash, -$amt, $t->txn_no]]),
            'advance' => [[AccountHead::sys('petty_iou'), $amt, 'IOU '.$who], [$cash, -$amt, $t->txn_no]],
            'settle' => array_values(array_filter(array_merge(
                (float) $t->spent > 0 ? $spend((float) $t->spent, (float) $t->vat_amount, $t->expense_head_id, (string) $who) : [],
                [[AccountHead::sys('petty_iou'), -$amt, 'IOU '.$who]],
                abs((float) $t->effect) >= 0.005 ? [[$cash, round((float) $t->effect, 2), (float) $t->effect > 0 ? 'Change returned' : 'Extra paid out']] : []
            ))),
            'variance' => [[AccountHead::sys('cash_over_short'), round(-(float) $t->effect, 2), $t->description], [$cash, round((float) $t->effect, 2), $t->txn_no]],
            'claim_pay' => [[AccountHead::sys('claims_payable'), $amt, $who], [$cash, -$amt, $t->txn_no]],
            default => null,
        };
        if (! $lines) {
            return null;
        }

        return ['date' => $t->date->toDateString(), 'reference' => $t->txn_no, 'narration' => trim(PettyTxn::TYPES[$t->type].' – '.($t->float->name).($who ? ' – '.$who : '').($t->description ? ' – '.$t->description : '')), 'lines' => $lines];
    }

    /** Running cash book for a float. */
    public static function book(PettyFloat $f, string $from, string $to): array
    {
        $open = self::balance($f, Carbon::parse($from)->subDay()->toDateString());
        $rows = PettyTxn::with('head', 'employee', 'creator')->where('petty_float_id', $f->id)->where('status', 'posted')->whereBetween('date', [$from, $to])
            ->orderBy('date')->orderBy('id')->get();
        $run = $open;
        foreach ($rows as $r) {
            $run = round($run + (float) $r->effect, 2);
            $r->running = $run;
        }

        return [$open, $rows, $run];
    }
}
