<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\ApAllocation;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Voucher;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accounts payable sub-ledger.
 *
 * Money owed to a supplier arises when goods are received (the goods receipt already credits AP in the GL) or when a
 * direct expense bill is entered. A goods bill is matched to its receipt: only the price variance is posted, and a
 * variance above tolerance puts the bill on hold until someone with ap.approve releases it. Payments and debit notes
 * reduce the balance and are allocated to bills; anything not allocated stays as supplier credit.
 */
class ApService
{
    /** Accounts a direct bill / debit note line may be charged to (never control, cash or clearing accounts). */
    public const BLOCKED_SYS = ['cash', 'bank', 'card_clearing', 'wallet_clearing', 'guest_ledger', 'city_ledger', 'inventory', 'input_tax', 'suspense', 'accounts_payable', 'tax_payable', 'tourism_levy', 'service_charge', 'capital', 'retained', 'opening_equity', 'salary_payable', 'staff_advances', 'gateway_clearing'];

    public static function chargeableHeads(): Collection
    {
        return AccountHead::where('is_active', true)->whereIn('type', ['expense', 'asset'])
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', self::BLOCKED_SYS))->orderBy('code')->get();
    }

    public static function tolerance(float $base): float
    {
        return max((float) setting('ap_tolerance_abs', 1), round(abs($base) * (float) setting('ap_tolerance_pct', 1) / 100, 2));
    }

    protected static function dueDate(Supplier $s, string $docDate, ?string $due): string
    {
        $d = $due ?: Carbon::parse($docDate)->addDays((int) ($s->payment_terms_days ?? 30))->toDateString();
        if ($d < $docDate) {
            throw new RuntimeException('The due date cannot be before the bill date.');
        }

        return $d;
    }

    protected static function checkDate(string $date, string $what = 'date'): void
    {
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException("The {$what} cannot be after the business date (".bdate()->format('d M Y').').');
        }
        if (($lock = LedgerService::lockDate()) && $date <= $lock) {
            throw new RuntimeException("The books are locked up to ".Carbon::parse($lock)->format('d M Y').'. Use a later date.');
        }
    }

    protected static function duplicateRef(int $supplierId, ?string $ref, string $type, ?int $except = null): void
    {
        if ($ref && SupplierInvoice::where('supplier_id', $supplierId)->where('type', $type)->where('status', '!=', 'cancelled')
            ->where('supplier_ref', $ref)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw new RuntimeException("Supplier document “{$ref}” has already been entered for this supplier.");
        }
    }

    /** Lines → [subtotal, tax, normalised lines]. */
    protected static function lines(array $lines, ?int $defaultHead = null): array
    {
        $allowed = self::chargeableHeads()->pluck('id')->all();
        $out = [];
        foreach ($lines as $l) {
            $amt = round((float) ($l['amount'] ?? 0), 2);
            if ($amt == 0.0 && empty($l['description'])) {
                continue;
            }
            if ($amt <= 0) {
                throw new RuntimeException('Every line needs a positive amount.');
            }
            $head = (int) ($l['account_head_id'] ?? 0) ?: $defaultHead;
            if (! in_array($head, $allowed, true)) {
                throw new RuntimeException('Choose an expense or asset account for every line.');
            }
            $rate = max(0, min(100, (float) ($l['tax_rate'] ?? 0)));
            $out[] = ['account_head_id' => $head, 'description' => mb_substr(trim((string) ($l['description'] ?? '')) ?: 'Charges', 0, 200),
                'amount' => $amt, 'tax_rate' => $rate, 'tax_amount' => round($amt * $rate / 100, 2)];
        }
        if (! $out) {
            throw new RuntimeException('Add at least one line.');
        }

        return [round(array_sum(array_column($out, 'amount')), 2), round(array_sum(array_column($out, 'tax_amount')), 2), $out];
    }

    /**
     * v7.53 A supplier document entered in a foreign currency, converted at the forex board.
     *
     * The lines are what the supplier's document says, in its own currency. What comes back is the same lines in the
     * hotel currency plus the audit trail of the conversion, because the ledger, the budget check, ageing, allocations
     * and payments all work in the hotel currency and none of them should have to learn a second one. The lines are
     * converted one by one and the header is their sum, so what the ledger posts always equals the sum of its lines;
     * the supplier's own subtotal, tax and total are kept beside it, and the pesewa difference between that and
     * `fx_total * fx_rate` is rounding, which fxNote() and the bill page show rather than hide.
     *
     * @return array{lines: array, subtotal: float, tax: float, fx: array|null, board: array|null}
     */
    protected static function convertLines(array $lines, ?string $currency, string $date, ?int $defaultHead = null): array
    {
        $code = strtoupper(trim((string) $currency));
        $home = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
        if ($code === '' || $code === $home) {
            [$sub, $tax, $rows] = self::lines($lines, $defaultHead);

            return ['lines' => $rows, 'subtotal' => $sub, 'tax' => $tax, 'fx' => null, 'board' => null];
        }
        if (! ForexService::apConvert()) {
            throw new RuntimeException("Supplier bills in $code are switched off, so nothing would record the rate this one was booked at. Either enter it in $home, or turn on foreign-currency supplier bills under Foreign Guests › Settings › Forex desk.");
        }
        $policy = ForexService::apPolicy($date);
        $board = ForexService::boardRate($code, $policy['date'], ['basis' => $policy['basis'], 'stale_days' => $policy['stale_days']]);
        if (! $board) {
            throw new RuntimeException("There is no $code rate on the forex board for ".Carbon::parse($policy['date'])->format('d M Y')
                .', and none in the '.$policy['stale_days'].' days before it. Set the rate under Foreign Guests › Exchange Rates, or enter the bill in '.$home.'.');
        }
        [$fxSub, $fxTax, $rows] = self::lines($lines, $defaultHead);   // validated in the supplier's own currency first
        $rate = (float) $board['rate'];
        foreach ($rows as &$row) {
            $row['amount'] = round($row['amount'] * $rate, 2);
            $row['tax_amount'] = round($row['tax_amount'] * $rate, 2);
        }
        unset($row);

        return ['lines' => $rows,
            'subtotal' => round(array_sum(array_column($rows, 'amount')), 2),
            'tax' => round(array_sum(array_column($rows, 'tax_amount')), 2),
            'fx' => ['currency' => $board['code'], 'fx_subtotal' => $fxSub, 'fx_tax' => $fxTax,
                'fx_total' => round($fxSub + $fxTax, 2), 'fx_rate' => $rate,
                'fx_date' => $board['date'], 'fx_basis' => $board['basis']],
            'board' => $board];
    }

    /** v7.53 How a converted document reads in the audit trail and on the flash: the supplier's own figure, the rate, and the board it came from. */
    public static function fxNote(?array $fx, ?array $board = null): string
    {
        if (! $fx) {
            return '';
        }
        $rounding = round((float) $fx['fx_total'] * (float) $fx['fx_rate'] - ((float) $fx['fx_subtotal'] + (float) $fx['fx_tax']), 2);

        return " ({$fx['currency']} ".number_format((float) $fx['fx_total'], 2).' at '.number_format((float) $fx['fx_rate'], 4)
            .' '.$fx['fx_basis'].', board of '.Carbon::parse($fx['fx_date'])->format('d M Y')
            .(! empty($board['stale']) ? ' — carried forward' : '')
            .($rounding ? ', '.money(abs($rounding)).' rounding' : '').')';
    }

    /**
     * Supplier documents are usually dated in the past. When the date falls in a day the ledger has already closed,
     * post (or reverse) straight away so the AP control never drifts; otherwise the night-audit sync picks it up.
     * Never blocks the AP transaction – a failure is simply retried by the next sync.
     */
    public static function postNow(SupplierInvoice|SupplierPayment $doc): void
    {
        $cutoff = setting('gl_cutoff');
        $date = ($doc instanceof SupplierPayment ? $doc->date : $doc->doc_date)?->toDateString();
        if (! $cutoff || ! $date || $date > $cutoff) {
            return;
        }
        [$type, $build] = $doc instanceof SupplierPayment
            ? ['ap_pay', fn () => LedgerService::forSupplierPayment($doc->fresh(['supplier', 'paymentMode']))]
            : ['ap_inv', fn () => LedgerService::forSupplierInvoice($doc->fresh(['lines', 'supplier', 'purchaseOrder']))];
        try {
            ChartOfAccounts::ensureLight();
            LedgerService::reconcile($type, (string) $doc->id, $build(), $cutoff);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ─────────────────────────── bills ───────────────────────────

    /** Bill for goods received against a purchase order (3-way match: PO → goods receipt → supplier invoice). */
    public static function billFromReceipt(PurchaseOrder $po, array $d): SupplierInvoice
    {
        $bill = DB::transaction(function () use ($po, $d) {
            $po = PurchaseOrder::with('supplier')->lockForUpdate()->findOrFail($po->id);
            if (! $po->billable()) {
                throw new RuntimeException($po->status === 'partial' ? "{$po->po_no} is part-received: receive the balance or short-close it before matching the bill."
                    : 'Goods must be received before the supplier bill can be matched.');
            }
            if ($po->bill()->exists()) {
                throw new RuntimeException("{$po->po_no} already has a supplier bill.");
            }
            $date = $d['doc_date'] ?? bdate()->toDateString();
            self::checkDate($date, 'bill date');
            // v7.53 a matched bill is valued at the goods receipt, and a receipt is valued at the purchase order, which
            // carries no currency: converting here would break the three-way match and the stock valuation with it
            if (! empty($d['currency']) && strtoupper(trim((string) $d['currency'])) !== strtoupper(trim((string) (hotel()?->currency ?: 'GHS')))) {
                throw new RuntimeException('A bill matched to a goods receipt is valued at the receipt, which is in '.hotel()?->currency
                    .'. Enter it in '.hotel()?->currency.', or record the supplier\'s own document as a direct bill.');
            }
            self::duplicateRef($po->supplier_id, $d['supplier_ref'] ?? null, 'invoice');
            [$recSub, $recTax] = $po->receivedSplit(); // v4.8: GRN value for part deliveries / short-closed POs
            $grnValue = $po->receivedValue();
            $sub = round((float) ($d['subtotal'] ?? $recSub), 2);
            $tax = round((float) ($d['tax'] ?? $recTax), 2);
            if ($sub <= 0 || $tax < 0) {
                throw new RuntimeException('Enter the amounts shown on the supplier invoice.');
            }
            $total = round($sub + $tax, 2);
            $variance = round($total - $grnValue, 2);
            $hold = abs($variance) > self::tolerance($grnValue);
            $bill = SupplierInvoice::create([
                'doc_no' => Numbering::next('supplier_bill'), 'type' => 'invoice', 'kind' => 'goods', 'supplier_id' => $po->supplier_id, 'purchase_order_id' => $po->id,
                'supplier_ref' => $d['supplier_ref'] ?? null, 'doc_date' => $date, 'due_date' => self::dueDate($po->supplier, $date, $d['due_date'] ?? null),
                'subtotal' => $sub, 'tax' => $tax, 'total' => $total, 'grn_total' => $grnValue, 'status' => $hold ? 'on_hold' : 'open',
                'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            $po->forceFill(['invoice_no' => $bill->supplier_ref ?? $po->invoice_no])->save();
            ActivityLogger::log('ap', 'bill', "Supplier bill {$bill->doc_no} for {$po->po_no}: ".money($total).($variance ? ' (variance '.money($variance).($hold ? ', on hold' : '').')' : ' – matched'), $bill);

            return $bill;
        });
        self::postNow($bill);

        return $bill;
    }

    /** Direct bill: services, utilities, repairs, rent … charged straight to expense/asset accounts. */
    public static function expenseBill(Supplier $s, array $d): SupplierInvoice
    {
        $date = $d['doc_date'] ?? bdate()->toDateString();
        self::checkDate($date, 'bill date');
        self::duplicateRef($s->id, $d['supplier_ref'] ?? null, 'invoice');
        $conv = self::convertLines($d['lines'] ?? [], $d['currency'] ?? null, $date, $s->expense_head_id);
        [$sub, $tax, $lines] = [$conv['subtotal'], $conv['tax'], $conv['lines']];
        [$fx, $board] = [$conv['fx'], $conv['board']];
        // v3.6 spend control: warn (flashed + noted on the bill) or block (needs budget.override and a reason).
        // Budgets are approved in the hotel currency, so this sees the converted lines, not the supplier's own.
        $over = BudgetService::checkSpend($lines, $date, $d['budget_reason'] ?? null);
        $notes = $d['notes'] ?? null;
        if ($over) {
            $reason = trim((string) ($d['budget_reason'] ?? ''));
            $notes = mb_substr(trim(($notes ? $notes.' · ' : '').'Over budget: '.BudgetService::describe($over).($reason !== '' ? '. Reason: '.$reason : '')), 0, 500);
        }

        $bill = DB::transaction(function () use ($s, $d, $date, $sub, $tax, $lines, $notes, $over, $fx, $board) {
            $bill = SupplierInvoice::create(array_merge([
                'doc_no' => Numbering::next('supplier_bill'), 'type' => 'invoice', 'kind' => 'expense', 'supplier_id' => $s->id,
                'supplier_ref' => $d['supplier_ref'] ?? null, 'doc_date' => $date, 'due_date' => self::dueDate($s, $date, $d['due_date'] ?? null),
                'subtotal' => $sub, 'tax' => $tax, 'total' => round($sub + $tax, 2), 'status' => 'open', 'notes' => $notes, 'created_by' => auth()->id(),
            ], $fx ?: []));
            $bill->lines()->createMany($lines);
            ActivityLogger::log('ap', 'bill', "Expense bill {$bill->doc_no} from {$s->name}: ".money($bill->total).self::fxNote($fx, $board), $bill);
            if ($over) {
                ActivityLogger::log('budget', 'over_budget', "Expense bill {$bill->doc_no} over budget: ".BudgetService::describe($over).(trim((string) ($d['budget_reason'] ?? '')) !== '' ? ' – reason: '.trim($d['budget_reason']) : ''), $bill);
            }

            return $bill;
        });
        self::postNow($bill);

        return $bill;
    }

    /** Debit note (return / price claim / short supply). Optionally applied straight to a bill. */
    public static function debitNote(Supplier $s, array $d): SupplierInvoice
    {
        $date = $d['doc_date'] ?? bdate()->toDateString();
        self::checkDate($date, 'debit note date');
        self::duplicateRef($s->id, $d['supplier_ref'] ?? null, 'debit_note');
        $against = ! empty($d['against_invoice_id']) ? SupplierInvoice::where('supplier_id', $s->id)->where('type', 'invoice')->findOrFail($d['against_invoice_id']) : null;
        $default = $against?->kind === 'goods' ? AccountHead::sys('price_variance') : $s->expense_head_id;
        // v7.53 a debit note is allocated against the bill it corrects, so the two have to be in one currency: inherit
        // it when the note does not say, and refuse a note in a third currency rather than allocate apples to oranges
        $currency = strtoupper(trim((string) ($d['currency'] ?? '')));
        if ($against && $against->isForeign()) {
            if ($currency === '') {
                $currency = strtoupper((string) $against->currency);
            } elseif ($currency !== strtoupper((string) $against->currency)) {
                throw new RuntimeException("{$against->doc_no} was billed in {$against->currency}, so a debit note against it has to be in {$against->currency} too — the two are allocated against each other.");
            }
        }
        $conv = self::convertLines($d['lines'] ?? [], $currency, $date, $default);
        [$sub, $tax, $lines] = [$conv['subtotal'], $conv['tax'], $conv['lines']];
        [$fx, $board] = [$conv['fx'], $conv['board']];

        $dn = DB::transaction(function () use ($s, $d, $date, $sub, $tax, $lines, $against, $fx, $board) {
            $dn = SupplierInvoice::create(array_merge([
                'doc_no' => Numbering::next('debit_note'), 'type' => 'debit_note', 'kind' => $against?->kind ?? 'expense', 'supplier_id' => $s->id,
                'against_invoice_id' => $against?->id, 'supplier_ref' => $d['supplier_ref'] ?? null, 'doc_date' => $date, 'due_date' => $date,
                'subtotal' => $sub, 'tax' => $tax, 'total' => round($sub + $tax, 2), 'status' => 'open', 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
            ], $fx ?: []));
            $dn->lines()->createMany($lines);
            ActivityLogger::log('ap', 'debit_note', "Debit note {$dn->doc_no} to {$s->name}: ".money($dn->total).self::fxNote($fx, $board), $dn);
            if ($against && in_array($against->status, ['open', 'partial'])) {
                $amt = min($dn->total, $against->fresh()->outstanding());
                if ($amt > 0) {
                    self::allocate($against, $amt, null, $dn);
                }
            }

            return $dn->fresh();
        });
        self::postNow($dn);

        return $dn;
    }

    public static function approve(SupplierInvoice $bill, ?string $note = null): SupplierInvoice
    {
        if ($bill->status !== 'on_hold') {
            throw new RuntimeException('Only bills on hold need approval.');
        }
        if ($bill->created_by && $bill->created_by === auth()->id() && setting('ap_segregation', '1') === '1') {
            throw new RuntimeException('Segregation of duties: a bill must be approved by someone other than the person who entered it.');
        }
        $bill->forceFill(['status' => 'open', 'approved_by' => auth()->id(), 'approved_at' => now(),
            'notes' => trim(($bill->notes ? $bill->notes."\n" : '').'Variance approved'.($note ? ': '.$note : ''))])->save();
        ActivityLogger::log('ap', 'approve', "Bill {$bill->doc_no} released (variance ".money($bill->variance()).')', $bill);
        self::refresh($bill);
        self::postNow($bill);

        return $bill;
    }

    /** Cancel a bill or debit note. Anything applied to / from it is released back as supplier credit. */
    public static function cancel(SupplierInvoice $doc, string $reason): SupplierInvoice
    {
        if ($doc->status === 'cancelled') {
            throw new RuntimeException('Already cancelled.');
        }
        if (! trim($reason)) {
            throw new RuntimeException('A reason is required.');
        }

        DB::transaction(function () use ($doc, $reason) {
            $touched = $doc->allocations()->with('payment', 'debitNote')->get()->merge($doc->applications()->with('invoice')->get());
            $doc->allocations()->delete();
            $doc->applications()->delete();
            $doc->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255), 'allocated' => 0])->save();
            foreach ($touched as $a) {
                $a->payment && self::refreshPayment($a->payment);
                $a->debitNote && $a->debitNote->id !== $doc->id && self::refresh($a->debitNote);
                $a->invoice && $a->invoice->id !== $doc->id && self::refresh($a->invoice);
            }
            ActivityLogger::log('ap', 'cancel', ($doc->isDebitNote() ? 'Debit note ' : 'Bill ')."{$doc->doc_no} cancelled: {$reason}", $doc);
        });
        self::postNow($doc);

        return $doc;
    }

    // ─────────────────────────── payments & allocation ───────────────────────────

    /**
     * Pay a supplier. $alloc = [bill_id => amount]. Whatever is not allocated stays as credit (advance) on the supplier.
     */
    public static function pay(Supplier $s, array $d, array $alloc = []): SupplierPayment
    {
        $date = $d['date'] ?? bdate()->toDateString();
        self::checkDate($date, 'payment date');
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Enter the amount paid.');
        }
        $mode = \App\Models\PaymentMode::find($d['payment_mode_id'] ?? 0);
        if (! $mode || in_array($mode->type, ['city_ledger', 'gift_voucher'], true)) {
            throw new RuntimeException('Choose how the supplier was paid (cash, bank transfer, mobile money …).');
        }
        $alloc = collect($alloc)->map(fn ($v) => round((float) $v, 2))->filter(fn ($v) => $v > 0);
        if ($alloc->sum() - $amount > 0.004) {
            throw new RuntimeException('Allocations ('.money($alloc->sum()).') exceed the amount paid ('.money($amount).').');
        }

        $p = DB::transaction(function () use ($s, $d, $date, $amount, $alloc) {
            $p = SupplierPayment::create(['payment_no' => Numbering::next('supplier_payment'), 'supplier_id' => $s->id, 'date' => $date,
                'payment_mode_id' => $d['payment_mode_id'], 'amount' => $amount, 'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id()]);
            // v4.4 Ghana withholding tax (and withholding VAT) – worked out before the bills are settled
            $q = WhtService::quote($s, $amount, $alloc->all(), $date);
            if (! empty($d['wht_skip']) && $q['wht'] > 0) {
                if (! trim((string) ($d['wht_reason'] ?? ''))) {
                    throw new RuntimeException('Give a reason for not withholding tax (e.g. the supplier\'s GRA exemption certificate number).');
                }
                $q = WhtService::quote($s, $amount, $alloc->all(), $date, false, $d['wht_reason']);
            }
            WhtService::stamp($p, $q);
            foreach ($alloc as $billId => $amt) {
                $bill = SupplierInvoice::where('supplier_id', $s->id)->where('type', 'invoice')->lockForUpdate()->findOrFail($billId);
                self::allocate($bill, $amt, $p, null, $date);
            }
            ActivityLogger::log('ap', 'payment', "Payment {$p->payment_no} to {$s->name}: ".money($amount).($alloc->count() ? ' against '.$alloc->count().' bill(s)' : ' on account')
                .($p->withheld() > 0 ? ' – '.money($p->withheld()).' withheld for GRA ('.$p->wht_cert_no.'), '.money($p->netPaid()).' paid' : ''), $p);

            return $p->fresh();
        });
        self::postNow($p);

        return $p;
    }

    /** Apply part of a payment's or debit note's unapplied credit to a bill. */
    public static function applyCredit(SupplierInvoice $bill, float $amount, ?SupplierPayment $p = null, ?SupplierInvoice $dn = null): ApAllocation
    {
        return DB::transaction(function () use ($bill, $amount, $p, $dn) {
            $src = $p ? SupplierPayment::lockForUpdate()->findOrFail($p->id) : SupplierInvoice::lockForUpdate()->findOrFail($dn->id);
            if ($src->supplier_id !== $bill->supplier_id) {
                throw new RuntimeException('Credit and bill belong to different suppliers.');
            }
            $free = $p ? $src->unapplied() : $src->outstanding();
            if (($p && $src->cancelled_at) || ($dn && ($src->status === 'cancelled' || ! $src->isDebitNote()))) {
                throw new RuntimeException('That credit is not available.');
            }
            if (round($amount, 2) - $free > 0.004) {
                throw new RuntimeException('Only '.money($free).' of that credit is unapplied.');
            }

            return self::allocate(SupplierInvoice::lockForUpdate()->findOrFail($bill->id), $amount, $p ? $src : null, $dn ? $src : null);
        });
    }

    protected static function allocate(SupplierInvoice $bill, float $amount, ?SupplierPayment $p, ?SupplierInvoice $dn, ?string $date = null): ApAllocation
    {
        $amount = round($amount, 2);
        if ($bill->isDebitNote() || ! in_array($bill->status, ['open', 'partial'])) {
            throw new RuntimeException("Bill {$bill->doc_no} is ".(SupplierInvoice::STATUSES[$bill->status] ?? $bill->status).' and cannot be paid.');
        }
        if ($amount <= 0 || $amount - $bill->outstanding() > 0.004) {
            throw new RuntimeException("Bill {$bill->doc_no}: at most ".money($bill->outstanding()).' is outstanding.');
        }
        $a = ApAllocation::create(['supplier_invoice_id' => $bill->id, 'supplier_payment_id' => $p?->id, 'debit_note_id' => $dn?->id,
            'amount' => $amount, 'date' => $date ?? bdate()->toDateString(), 'user_id' => auth()->id()]);
        self::refresh($bill);
        $p && self::refreshPayment($p);
        $dn && self::refresh($dn);

        return $a;
    }

    /** Remove a single allocation (e.g. applied to the wrong bill); the credit becomes unapplied again. */
    public static function unallocate(ApAllocation $a): void
    {
        DB::transaction(function () use ($a) {
            $bill = $a->invoice;
            $p = $a->payment;
            $dn = $a->debitNote;
            $a->delete();
            $bill && self::refresh($bill);
            $p && self::refreshPayment($p);
            $dn && self::refresh($dn);
            ActivityLogger::log('ap', 'unallocate', 'Allocation of '.money($a->amount).' removed from '.$bill?->doc_no, $bill);
        });
    }

    public static function cancelPayment(SupplierPayment $p, string $reason): SupplierPayment
    {
        if ($p->cancelled_at) {
            throw new RuntimeException('Already cancelled.');
        }
        if (! trim($reason)) {
            throw new RuntimeException('A reason is required.');
        }
        WhtService::assertCancellable($p);

        DB::transaction(function () use ($p, $reason) {
            $bills = $p->allocations()->with('invoice')->get()->pluck('invoice')->filter();
            $p->allocations()->delete();
            $p->forceFill(['cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255), 'allocated' => 0])->save();
            $bills->each(fn ($b) => self::refresh($b));
            ActivityLogger::log('ap', 'payment_cancel', "Payment {$p->payment_no} cancelled: {$reason}", $p);
        });
        self::postNow($p);

        return $p;
    }

    /** Oldest-due-first split of an amount over a supplier's payable bills. */
    public static function suggest(Supplier $s, float $amount): array
    {
        $left = round($amount, 2);
        $out = [];
        foreach (self::openBills($s->id) as $b) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $b->outstanding());
            $out[$b->id] = $take;
            $left = round($left - $take, 2);
        }

        return $out;
    }

    public static function openBills(?int $supplierId = null): Collection
    {
        return SupplierInvoice::with('supplier', 'purchaseOrder')->where('type', 'invoice')->whereIn('status', ['open', 'partial'])
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->orderBy('due_date')->orderBy('id')->get();
    }

    public static function refresh(SupplierInvoice $doc): SupplierInvoice
    {
        $doc->refresh();
        $sum = round((float) ($doc->isDebitNote() ? $doc->applications()->sum('amount') : $doc->allocations()->sum('amount')), 2);
        $status = match (true) {
            in_array($doc->status, ['cancelled', 'on_hold']) => $doc->status,
            $sum >= (float) $doc->total - 0.004 => 'paid',
            $sum > 0 => 'partial',
            default => 'open',
        };
        $doc->forceFill(['allocated' => $sum, 'status' => $status])->save();
        if ($doc->purchase_order_id && ! $doc->isDebitNote()) {
            PurchaseOrder::whereKey($doc->purchase_order_id)->update(['paid' => $doc->status === 'cancelled' ? 0 : $sum]);
        }

        return $doc;
    }

    public static function refreshPayment(SupplierPayment $p): SupplierPayment
    {
        $p->refresh();
        $p->forceFill(['allocated' => round((float) $p->allocations()->sum('amount'), 2)])->save();

        return $p;
    }

    // ─────────────────────────── balances, ageing, statement ───────────────────────────

    /** Received goods not yet billed (or whose bill is on hold) – still owed at the receipt value. */
    public static function unbilledReceipts(?int $supplierId = null, ?string $asOf = null): Collection
    {
        // v4.8: part-received and short-closed POs are owed at their goods-receipt value
        return PurchaseOrder::with('supplier')->whereIn('status', ['received', 'partial', 'closed'])->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->whereDoesntHave('bill', fn ($q) => $q->whereIn('status', ['open', 'partial', 'paid']))->get()
            ->each(fn ($po) => $po->receiptValue = $po->receivedValue($asOf))
            ->filter(fn ($po) => $po->receiptValue > 0)->values();
    }

    /**
     * Ageing by days past due as at a date: current (not yet due), 1–30, 31–60, 61–90, 90+.
     * Unapplied payments and debit notes are shown as credits and netted in the balance.
     */
    /**
     * v7.53 What the open foreign-currency bills would cost at the board as it stands.
     *
     * A bill booked in dollars stays a dollar obligation: if the cedi moves before it is paid the hotel hands over more
     * or fewer cedis than the ledger carries, and that movement is a real cost nobody sees until the payment leaves.
     * This reports it per currency — the outstanding foreign amount, the cedis it was booked at, what it would take at
     * the board now, and the difference. Nothing is posted: an unrealised revaluation belongs to the period end, and
     * the ledger keeps every bill at the rate it was booked at until it is paid.
     *
     * @return array{home: string, as_of: string, rows: array, booked: float, at_board: float|null, movement: float|null, unpriced: int}
     */
    public static function fxExposure(?string $asOf = null): array
    {
        $home = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
        $asOf = $asOf ?: bdate()->toDateString();
        $policy = ForexService::apPolicy($asOf);
        $rows = [];
        $bills = SupplierInvoice::whereNotNull('currency')->where('currency', '!=', $home)->whereNull('cancelled_at')
            ->whereIn('status', ['open', 'partial', 'on_hold'])->orderBy('currency')->get();
        foreach ($bills->groupBy(fn ($b) => strtoupper((string) $b->currency)) as $code => $group) {
            $booked = round((float) $group->sum(fn ($b) => $b->outstanding()), 2);
            $foreign = round((float) $group->sum(fn ($b) => $b->fxOutstanding()), 2);
            $board = ForexService::boardRate((string) $code, $policy['date'], ['basis' => $policy['basis'], 'stale_days' => $policy['stale_days']]);
            $at = $board ? round($foreign * (float) $board['rate'], 2) : null;
            $rows[] = ['currency' => (string) $code, 'bills' => $group->count(), 'foreign' => $foreign, 'booked' => $booked,
                'rate_now' => $board['rate'] ?? null, 'board_date' => $board['date'] ?? null, 'stale' => (bool) ($board['stale'] ?? false),
                'at_board' => $at, 'movement' => $at === null ? null : round($at - $booked, 2)];
        }
        $priced = array_filter($rows, fn ($r) => $r['at_board'] !== null);

        return ['home' => $home, 'as_of' => $asOf, 'rows' => $rows,
            'booked' => round((float) array_sum(array_column($rows, 'booked')), 2),
            'at_board' => $priced ? round((float) array_sum(array_column($priced, 'at_board')), 2) : null,
            'movement' => $priced ? round((float) array_sum(array_column($priced, 'movement')), 2) : null,
            'unpriced' => count($rows) - count($priced)];
    }

    public static function ageing(?string $asOf = null, ?int $supplierId = null): Collection
    {
        $asOf ??= bdate()->toDateString();
        $rows = [];
        $bucket = function (int $days): string {
            return match (true) { $days <= 0 => 'current', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', default => 'd90p' };
        };
        $row = function ($s) use (&$rows): int {
            $rows[$s->id] ??= ['supplier_id' => $s->id, 'supplier' => $s->name, 'terms' => (int) $s->payment_terms_days,
                'current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'unbilled' => 0.0, 'credit' => 0.0, 'balance' => 0.0, 'oldest' => 0];

            return $s->id;
        };
        foreach (SupplierInvoice::with('supplier')->where('type', 'invoice')->whereIn('status', ['open', 'partial'])->whereDate('doc_date', '<=', $asOf)
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get() as $b) {
            $r = &$rows[$row($b->supplier)];
            $days = $b->daysOverdue($asOf);
            $r[$bucket($days)] += $b->outstanding();
            $r['oldest'] = max($r['oldest'], $days);
            unset($r);
        }
        foreach (self::unbilledReceipts($supplierId, $asOf) as $po) {
            $r = &$rows[$row($po->supplier)];
            $due = Carbon::parse($po->grnDate())->addDays((int) $po->supplier->payment_terms_days);
            $days = max(0, (int) $due->diffInDays(Carbon::parse($asOf), false));
            $r[$bucket($days)] += (float) $po->receiptValue;
            $r['unbilled'] += (float) $po->receiptValue;
            $r['oldest'] = max($r['oldest'], $days);
            unset($r);
        }
        foreach (SupplierPayment::with('supplier')->whereNull('cancelled_at')->whereDate('date', '<=', $asOf)->whereColumn('allocated', '<', 'amount')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get() as $p) {
            $r = &$rows[$row($p->supplier)];
            $r['credit'] += $p->unapplied();
            unset($r);
        }
        foreach (SupplierInvoice::with('supplier')->where('type', 'debit_note')->whereIn('status', ['open', 'partial'])->whereDate('doc_date', '<=', $asOf)
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get() as $dn) {
            $r = &$rows[$row($dn->supplier)];
            $r['credit'] += $dn->outstanding();
            unset($r);
        }

        return collect($rows)->map(function ($r) {
            foreach (['current', 'd30', 'd60', 'd90', 'd90p', 'unbilled', 'credit'] as $k) {
                $r[$k] = round($r[$k], 2);
            }
            $r['balance'] = round($r['current'] + $r['d30'] + $r['d60'] + $r['d90'] + $r['d90p'] - $r['credit'], 2);

            return $r;
        })->filter(fn ($r) => abs($r['balance']) > 0.004 || $r['credit'] > 0)->sortByDesc('balance')->values();
    }

    /** Supplier statement: every document that moves the balance, in date order, with a running balance (credit = we owe). */
    public static function statement(Supplier $s, string $from, string $to): array
    {
        $rows = collect();
        foreach (\App\Models\GoodsReceipt::with('order')->where('supplier_id', $s->id)->where('total', '>', 0)->get() as $g) { // v4.8
            $rows->push(['date' => $g->date->toDateString(), 'doc' => $g->grn_no, 'type' => 'Goods received ('.$g->order?->po_no.')', 'ref' => $g->invoice_no ?? $g->delivery_note, 'dr' => 0.0, 'cr' => (float) $g->total, 'link' => route('purchases.show', $g->purchase_order_id)]);
        }
        foreach (PurchaseOrder::where('supplier_id', $s->id)->where('status', 'received')->whereDoesntHave('receipts')->get() as $po) {
            $rows->push(['date' => $po->grnDate(), 'doc' => $po->po_no, 'type' => 'Goods received', 'ref' => $po->invoice_no, 'dr' => 0.0, 'cr' => (float) $po->total, 'link' => route('purchases.show', $po)]);
        }
        foreach (SupplierInvoice::where('supplier_id', $s->id)->where('status', '!=', 'cancelled')->get() as $d) {
            $amt = match (true) {
                $d->isDebitNote() => -(float) $d->total,
                $d->status === 'on_hold' => 0.0,
                $d->kind === 'goods' => $d->variance(),
                default => (float) $d->total,
            };
            $label = $d->isDebitNote() ? 'Debit note' : ($d->kind === 'goods' ? 'Bill (matched to receipt'.($d->variance() ? ', price variance' : '').($d->status === 'on_hold' ? ', ON HOLD' : '').')' : 'Expense bill');
            $rows->push(['date' => $d->doc_date->toDateString(), 'doc' => $d->doc_no, 'type' => $label, 'ref' => $d->supplier_ref,
                'dr' => $amt < 0 ? -$amt : 0.0, 'cr' => $amt > 0 ? $amt : 0.0, 'info' => $d->kind === 'goods' && ! $d->isDebitNote() ? money($d->total) : null, 'link' => route('ap.bills.show', $d)]);
        }
        foreach (SupplierPayment::with('paymentMode')->where('supplier_id', $s->id)->whereNull('cancelled_at')->get() as $p) {
            $rows->push(['date' => $p->date->toDateString(), 'doc' => $p->payment_no, 'type' => 'Payment – '.($p->paymentMode?->name ?? ''), 'ref' => $p->reference, 'dr' => (float) $p->amount, 'cr' => 0.0, 'link' => route('ap.payments.show', $p)]);
        }
        $rows = $rows->sortBy(fn ($r) => $r['date'].'|'.$r['doc'])->values();
        $opening = round($rows->where('date', '<', $from)->sum(fn ($r) => $r['cr'] - $r['dr']), 2); // Collection of arrays, 'date' is already Y-m-d
        $bal = $opening;
        $period = $rows->filter(fn ($r) => $r['date'] >= $from && $r['date'] <= $to)->map(function ($r) use (&$bal) {
            $bal = round($bal + $r['cr'] - $r['dr'], 2);

            return $r + ['balance' => $bal];
        })->values();

        return ['opening' => $opening, 'rows' => $period, 'closing' => $bal, 'dr' => round($period->sum('dr'), 2), 'cr' => round($period->sum('cr'), 2)];
    }

    /** Sub-ledger balance as at a date, computed the same way the GL is posted (for the control reconciliation). */
    public static function controlBalance(string $asOf): float
    {
        $grn = PurchaseOrder::where('status', 'received')->whereDoesntHave('receipts')->get()->filter(fn ($po) => $po->grnDate() <= $asOf)->sum('total')
            + \App\Models\GoodsReceipt::where('date', '<=', $asOf)->sum('total'); // v4.8
        $bills = SupplierInvoice::where('type', 'invoice')->whereIn('status', ['open', 'partial', 'paid'])->whereDate('doc_date', '<=', $asOf)->get()
            ->sum(fn ($b) => $b->kind === 'goods' ? $b->variance() : (float) $b->total);
        $dn = SupplierInvoice::where('type', 'debit_note')->where('status', '!=', 'cancelled')->whereDate('doc_date', '<=', $asOf)->sum('total');
        $paid = SupplierPayment::whereNull('cancelled_at')->whereDate('date', '<=', $asOf)->sum('amount');
        $vouchers = Voucher::where('type', 'payment')->where('account_head_id', AccountHead::sys('accounts_payable'))->whereDate('date', '<=', $asOf)->sum('amount');

        return round((float) $grn + (float) $bills - (float) $dn - (float) $paid - (float) $vouchers, 2);
    }
}
