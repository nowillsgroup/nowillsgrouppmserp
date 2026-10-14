<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\FolioTransaction;
use App\Models\GlJournal;
use App\Models\GlLine;
use App\Models\PaymentMode;
use App\Models\PayrollRun;
use App\Models\PosOrder;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\StaffAdvance;
use App\Models\StockMovement;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Voucher;
use App\Support\Numbering;
use App\Support\Tenant;
use App\Models\GatewayPayout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry general ledger.
 *
 * Operational modules (front office, POS, vouchers, stores) stay the books of original entry; the ledger
 * is derived from them by sync(), which posts only CLOSED business days (≤ business date − 1).
 * Journals are immutable: when a source changes after posting (void, edit, delete) the original journal is
 * reversed and a corrected one posted, both dated on the first open day. Nothing is ever posted on or
 * before the period-lock date.
 */
class LedgerService
{
    public const ROUNDING_TOLERANCE = 1.00;

    protected static array $modeCache = [];

    // ------------------------------------------------------------------ dates

    public static function cutoff(): string
    {
        return bdate()->copy()->subDay()->toDateString();
    }

    public static function lockDate(): ?string
    {
        return setting('gl_lock_date') ?: null;
    }

    /** First date a journal may carry: never on/before the lock date. */
    public static function openDate(string $date): string
    {
        $lock = self::lockDate();

        return ($lock && $date <= $lock) ? Carbon::parse($lock)->addDay()->toDateString() : $date;
    }

    // --------------------------------------------------------------- accounts

    public static function categoryAccount(string $category): int
    {
        $mapped = (int) setting('gl_cat_'.$category);
        if ($mapped && AccountHead::whereKey($mapped)->exists()) {
            return $mapped;
        }

        return AccountHead::sys(ChartOfAccounts::CATEGORY_DEFAULTS[$category] ?? 'rev_other');
    }

    public static function posRevenueAccount(): int
    {
        $mapped = (int) setting('gl_pos_revenue');

        return ($mapped && AccountHead::whereKey($mapped)->exists()) ? $mapped : self::categoryAccount('fnb');
    }

    public static function modeAccount(?int $modeId, string $fallback = 'suspense'): int
    {
        if (! $modeId) {
            return AccountHead::sys($fallback);
        }
        $k = Tenant::id().':'.$modeId;
        if (! array_key_exists($k, self::$modeCache)) {
            $m = PaymentMode::find($modeId);
            self::$modeCache[$k] = $m?->account_head_id ?: ($m ? AccountHead::sys(ChartOfAccounts::MODE_DEFAULTS[$m->type] ?? 'suspense') : null);
        }

        return self::$modeCache[$k] ?? AccountHead::sys($fallback);
    }

    // --------------------------------------------------------------- builders
    // Each builder returns null (nothing to post) or ['date','reference','narration','lines'=>[[account_id, signed, memo]]]
    // where signed > 0 is a debit and < 0 a credit.

    public static function forFolioTx(FolioTransaction $t): ?array
    {
        if ($t->is_void) {
            return null;
        }
        $a = (float) $t->amount;
        $tax = (float) $t->tax_amount;
        $gl = AccountHead::sys('guest_ledger');
        $lines = match ($t->type) {
            'charge' => self::chargeLines($t, $a, $tax, $gl),
            'payment' => [[$t->category === 'city_ledger' ? AccountHead::sys('city_ledger') : self::modeAccount($t->payment_mode_id), $a, $t->paymentMode?->name], [$gl, -$a, null]],
            'refund' => [[$gl, $a, null], [self::modeAccount($t->payment_mode_id), -$a, $t->paymentMode?->name]],
            'discount', 'allowance' => [[AccountHead::sys($t->source_type === 'loyalty' ? 'loyalty' : 'discounts'), $a, null], [$gl, -$a, null]],
            default => null,
        };
        if (! $lines) {
            return null;
        }
        $folio = $t->folio;

        return [
            'date' => $t->business_date->toDateString(),
            'reference' => $folio?->folio_no,
            'narration' => trim($t->description.' – '.($folio?->guest?->full_name ?? $folio?->company?->name ?? ''), ' –'),
            'lines' => $lines,
        ];
    }

    protected static function chargeLines(FolioTransaction $t, float $a, float $tax, int $gl): array
    {
        if ($t->source_type === 'pos' && ($o = PosOrder::find($t->source_id))) {
            $rev = (float) $o->subtotal - (float) $o->discount;
            $sc = (float) $o->service_charge;
            $ro = (float) $o->round_off;
            if (abs($rev + $sc + $ro - $a) < 0.02) { // room-posted POS bill: split service charge and round-off out of revenue
                return [[$gl, $a + $tax, null], [self::posRevenueAccount(), -$rev, $o->bill_no], [AccountHead::sys('service_charge'), -$sc, null], [AccountHead::sys('rounding'), -$ro, null],
                    ...self::taxLines($tax, 'fnb', (float) $t->tax_rate ?: null)];
            }
        }

        return [[$gl, $a + $tax, null], [self::categoryAccount($t->category), -$a, null],
            ...self::taxLines($tax, $t->category, (float) $t->tax_rate ?: null, $tax ? rtrim(rtrim(number_format((float) $t->tax_rate, 2), '0'), '.').'%' : null)];
    }

    /**
     * v4.3 Ghana: credit the combined tax of a line to its liabilities – VAT + NHIL + GETFund to the GRA output-tax
     * account, the Tourism Levy to the GTA account (TaxService::breakdown, same split as on the invoice).
     */
    public static function taxLines(float $tax, ?string $category, ?float $rate = null, ?string $memo = null): array
    {
        $tl = TaxService::levyShare($tax, $category, $rate);

        return [[AccountHead::sys('tax_payable'), -round($tax - $tl, 2), $memo], [AccountHead::sys('tourism_levy'), -$tl, $tl ? 'Tourism levy' : null]];
    }

    public static function forPosOrder(PosOrder $o): ?array
    {
        if ($o->status !== 'settled' || $o->settlement !== 'payment') {
            return null; // room posts reach the ledger through the folio; complimentary bills carry no revenue
        }

        $paymentRows = $o->relationLoaded('payments')
            ? $o->payments->where('kind', 'payment')->sortBy('id')->values()
            : $o->payments()->where('kind', 'payment')->orderBy('id')->get();
        $lines = $paymentRows->isNotEmpty()
            ? $paymentRows->map(fn ($payment) => [self::modeAccount($payment->payment_mode_id, 'cash'), (float) $payment->amount, $payment->paymentMode?->name])->all()
            : [[self::modeAccount($o->payment_mode_id, 'cash'), (float) $o->total, $o->paymentMode?->name]];
        $lines = array_merge($lines, [
            [self::posRevenueAccount(), -((float) $o->subtotal - (float) $o->discount), null],
            [AccountHead::sys('service_charge'), -(float) $o->service_charge, null],
            ...self::taxLines((float) $o->tax, 'fnb'),
            [AccountHead::sys('rounding'), -(float) $o->round_off, null],
        ]);

        // POS refunds are tender outflows against an already settled bill. Reverse the bill components proportionally so the
        // payment mode (including voucher liability) and the revenue/tax accounts stay in balance without changing the sale's
        // original immutable payment entry.
        $refundRows = $o->relationLoaded('payments')
            ? $o->payments->where('kind', 'refund')->sortBy('id')->values()
            : $o->payments()->where('kind', 'refund')->orderBy('id')->get();
        $total = (float) $o->total;
        if ($total > 0) {
            $base = (float) $o->subtotal - (float) $o->discount;
            foreach ($refundRows as $refund) {
                $amount = (float) $refund->amount;
                $ratio = $amount / $total;
                $refundBase = round($base * $ratio, 2);
                $refundService = round((float) $o->service_charge * $ratio, 2);
                $refundTax = round((float) $o->tax * $ratio, 2);
                $refundRounding = round((float) $o->round_off * $ratio, 2);
                $componentTotal = round($refundBase + $refundService + $refundTax + $refundRounding, 2);
                $refundBase = round($refundBase + $amount - $componentTotal, 2);
                $lines[] = [self::posRevenueAccount(), $refundBase, null];
                $lines[] = [AccountHead::sys('service_charge'), $refundService, null];
                foreach (self::taxLines($refundTax, 'fnb') as [$account, $signed, $memo]) {
                    $lines[] = [$account, -$signed, $memo];
                }
                $lines[] = [AccountHead::sys('rounding'), $refundRounding, null];
                $lines[] = [self::modeAccount($refund->payment_mode_id, 'cash'), -$amount, $refund->paymentMode?->name];
            }
        }

        return [
            'date' => $o->business_date->toDateString(),
            'reference' => $o->bill_no ?: $o->order_no,
            'narration' => 'POS bill – '.($o->outlet?->name ?? 'outlet'),
            'lines' => $lines,
        ];
    }

    public static function forVoucher(Voucher $v): ?array
    {
        $amt = (float) $v->amount;
        $mode = self::modeAccount($v->payment_mode_id, 'cash');
        $lines = match ($v->type) {
            'payment' => [[$v->account_head_id, $amt, $v->party_name], [$mode, -$amt, $v->paymentMode?->name]],
            'receipt' => [[$mode, $amt, $v->paymentMode?->name], [$v->company_id ? AccountHead::sys('city_ledger') : $v->account_head_id, -$amt, $v->party_name]],
            default => null, // legacy single-sided "journal" vouchers cannot be posted; use a manual journal
        };

        return $lines ? ['date' => $v->date->toDateString(), 'reference' => $v->voucher_no, 'narration' => $v->narration ?: ucfirst($v->type).' voucher', 'lines' => $lines] : null;
    }

    public static function forPurchase(PurchaseOrder $po): ?array
    {
        // v4.8: POs received through goods receipt notes post one journal per GRN instead (source 'grn')
        if ($po->status !== 'received' || $po->hasGrns()) {
            return null;
        }
        $date = StockMovement::where('type', 'purchase')->where('reference', $po->po_no)->min('date')
            ?? ($po->received_at ?? $po->updated_at)->toDateString();
        $tax = (float) $po->tax;
        $claimable = setting('gl_input_tax', '1') === '1';

        return [
            'date' => Carbon::parse($date)->toDateString(),
            'reference' => $po->po_no,
            'narration' => 'Goods received – '.($po->supplier?->name ?? 'supplier'),
            'lines' => [
                [AccountHead::sys('inventory'), (float) $po->subtotal + ($claimable ? 0 : $tax), null],
                [AccountHead::sys('input_tax'), $claimable ? $tax : 0, null],
                [AccountHead::sys('accounts_payable'), -(float) $po->total, $po->supplier?->name],
            ],
        ];
    }

    /** v4.8 Goods receipt note: accepted goods into inventory, owed to the supplier (Dr inventory / input tax, Cr AP). */
    public static function forGoodsReceipt(\App\Models\GoodsReceipt $g): ?array
    {
        if ((float) $g->total <= 0) {
            return null; // everything rejected – nothing taken into stock
        }
        $tax = (float) $g->tax;
        $claimable = setting('gl_input_tax', '1') === '1';
        $name = $g->supplier?->name;

        return [
            'date' => $g->date->toDateString(),
            'reference' => $g->grn_no,
            'narration' => 'Goods received – '.($name ?? 'supplier').' ('.($g->order?->po_no ?? 'PO').')',
            'lines' => [
                [AccountHead::sys('inventory'), (float) $g->subtotal + ($claimable ? 0 : $tax), null],
                [AccountHead::sys('input_tax'), $claimable ? $tax : 0, null],
                [AccountHead::sys('accounts_payable'), -(float) $g->total, $name],
            ],
        ];
    }

    /**
     * Supplier bill / debit note. A goods bill only posts its price variance (the receipt already credited AP);
     * an expense bill charges its lines; a debit note is the mirror image. On-hold and cancelled documents post nothing.
     */
    public static function forSupplierInvoice(SupplierInvoice $d): ?array
    {
        if (in_array($d->status, ['on_hold', 'cancelled'])) {
            return null;
        }
        $ap = AccountHead::sys('accounts_payable');
        $sign = $d->isDebitNote() ? -1 : 1;
        $name = $d->supplier?->name;
        if ($d->kind === 'goods' && ! $d->isDebitNote()) {
            $v = $d->variance();
            $lines = [[AccountHead::sys('price_variance'), $v, $d->purchaseOrder?->po_no], [$ap, -$v, $name]];
        } else {
            $claimable = setting('gl_input_tax', '1') === '1';
            $lines = [];
            foreach ($d->lines as $l) {
                $lines[] = [$l->account_head_id, $sign * ((float) $l->amount + ($claimable ? 0 : (float) $l->tax_amount)), $l->description];
                $claimable && $lines[] = [AccountHead::sys('input_tax'), $sign * (float) $l->tax_amount, null];
            }
            $lines[] = [$ap, -$sign * (float) $d->total, $name];
        }

        return ['date' => $d->doc_date->toDateString(), 'reference' => $d->doc_no,
            'narration' => ($d->isDebitNote() ? 'Debit note' : 'Supplier bill').' – '.($name ?? 'supplier').($d->supplier_ref ? ' #'.$d->supplier_ref : ''), 'lines' => $lines];
    }

    public static function forSupplierPayment(SupplierPayment $p): ?array
    {
        if ($p->cancelled_at) {
            return null;
        }
        $amt = (float) $p->amount;

        // v7.54 a payment made in the currency the supplier billed in: the AP control is debited with what the bills
        // were booked at, the bank credited with what the payment actually cost in cedis, and the difference between
        // the two — the realised exchange gain or loss — posted to Exchange Gain / Loss. Dr AP therefore still equals
        // the sum of the bills it settled, which is what ApService::controlBalance() checks.
        $fxDiff = $p->isForeign() ? round((float) $p->fx_diff, 2) : 0.0;

        return ['date' => $p->date->toDateString(), 'reference' => $p->payment_no,
            'narration' => 'Payment to '.($p->supplier?->name ?? 'supplier').($p->reference ? ' – '.$p->reference : '')
                .($p->isForeign() ? ' ('.$p->fxLabel().' at '.number_format((float) $p->fx_rate, 4).')' : ''),
            'lines' => array_values(array_filter([[AccountHead::sys('accounts_payable'), $fxDiff != 0.0 || $p->isForeign() ? round((float) $p->allocated, 2) : $amt, $p->supplier?->name],
                $fxDiff != 0.0 ? [AccountHead::sys('forex_gain'), $fxDiff, 'Exchange difference on the '.$p->currency.' payment at '.number_format((float) $p->fx_rate, 4).' – '.($fxDiff > 0 ? 'loss' : 'gain')] : null,
                [self::modeAccount($p->payment_mode_id, 'bank'), -$p->netPaid(), $p->paymentMode?->name],
                // v4.4 Ghana: tax withheld at source is owed to GRA, not paid to the supplier
                (float) $p->wht_amount > 0 ? [AccountHead::sys('wht_payable'), -(float) $p->wht_amount, 'WHT '.$p->wht_cert_no] : null,
                (float) $p->wvat_amount > 0 ? [AccountHead::sys('wvat_payable'), -(float) $p->wvat_amount, 'Withholding VAT '.$p->wht_cert_no] : null]))];
    }

    /** Payroll accrual at the period end: salary cost & employer contributions vs statutory payables, advance recoveries and net pay. */
    public static function forPayrollRun(PayrollRun $run): ?array
    {
        if (! in_array($run->status, ['approved', 'paid'], true)) {
            return null;
        }
        $lines = [];
        $net = 0.0;
        foreach ($run->payslips()->with('lines')->get() as $slip) {
            foreach ($slip->lines as $l) {
                $a = (float) $l->amount;
                match ($l->kind) {
                    'earning' => $lines[] = [$l->expense_head_id ?: AccountHead::sys('salaries'), $a, $l->code],
                    'deduction' => $lines[] = [$l->liability_head_id ?: AccountHead::sys('suspense'), -$a, $l->code],
                    'employer' => array_push($lines, [$l->expense_head_id ?: AccountHead::sys('employer_contrib'), $a, $l->code], [$l->liability_head_id ?: AccountHead::sys('suspense'), -$a, $l->code]),
                    default => null,
                };
            }
            $net += (float) $slip->net;
        }
        if (! $lines) {
            return null;
        }
        $lines[] = [AccountHead::sys('salary_payable'), -round($net, 2), 'Net pay'];

        return ['date' => $run->period_end->toDateString(), 'reference' => $run->run_no, 'narration' => "Payroll – {$run->label()} ({$run->employees} employees)", 'lines' => $lines];
    }

    /** Salary payment: clears salaries payable, credited to each payslip's payment mode. */
    public static function forPayrollPayment(PayrollRun $run): ?array
    {
        if ($run->status !== 'paid' || ! $run->paid_date) {
            return null;
        }
        $lines = [];
        $total = 0.0;
        foreach ($run->payslips()->with('paymentMode')->get()->groupBy('payment_mode_id') as $modeId => $slips) {
            $amt = round((float) $slips->sum('net'), 2);
            $lines[] = [self::modeAccount($modeId ?: null, 'bank'), -$amt, $slips->first()->paymentMode?->name];
            $total += $amt;
        }
        if (abs($total) < 0.005) {
            return null;
        }
        array_unshift($lines, [AccountHead::sys('salary_payable'), round($total, 2), 'Net pay']);

        return ['date' => $run->paid_date->toDateString(), 'reference' => $run->run_no, 'narration' => "Salary payment – {$run->label()}", 'lines' => $lines];
    }

    public static function forStaffAdvance(StaffAdvance $a): ?array
    {
        if ($a->cancelled_at) {
            return null;
        }
        $amt = (float) $a->amount;

        return ['date' => $a->date->toDateString(), 'reference' => $a->advance_no, 'narration' => 'Salary advance – '.($a->employee?->name ?? 'employee'),
            'lines' => [[AccountHead::sys('staff_advances'), $amt, $a->employee?->emp_code], [self::modeAccount($a->payment_mode_id, 'cash'), -$amt, $a->paymentMode?->name]]];
    }

    /** v2.1: gateway payout – the net lands in the bank, fees are expensed, clearing is relieved by the gross. */
    public static function forGatewayPayout(GatewayPayout $po): ?array
    {
        $gross = (float) $po->gross;
        $fees = (float) $po->fees;

        return ['date' => $po->date->toDateString(), 'reference' => $po->payout_no, 'narration' => 'Gateway payout – '.ucfirst($po->provider).($po->reference ? ' '.$po->reference : ''),
            'lines' => [[self::modeAccount($po->payment_mode_id, 'bank'), $gross - $fees, $po->paymentMode?->name], [AccountHead::sys('gateway_fees'), $fees, null], [AccountHead::sys('gateway_clearing'), -$gross, null]]];
    }

    /** One journal per day for stores movements that change inventory value (purchases/transfers excluded). */
    public static function forStockDay(string $date): ?array
    {
        $moves = StockMovement::with('item', 'store')->whereDate('date', $date)->whereNotIn('type', ['purchase', 'transfer_in', 'transfer_out'])->get();
        if ($moves->isEmpty()) {
            return null;
        }
        $inv = AccountHead::sys('inventory');
        $lines = [];
        foreach ($moves as $m) {
            $rate = (float) $m->rate ?: (float) ($m->item?->last_cost ?? 0);
            // + stock in, − stock out. qty (3 dp) × rate (2 dp) is exact at 5 dp: snap the float first so half-cent cases
            // round like the database does (PHP 8.4's round() no longer pre-rounds 0.28499999… to 0.285).
            $value = round(round((float) $m->qty * $rate, 6), 2);
            if (abs($value) < 0.005) {
                continue;
            }
            $counter = match ($m->type) {
                'opening' => AccountHead::sys('opening_equity'),
                'issue', 'consumption' => RequisitionService::expenseHeadFor($m->department_id) ?: AccountHead::sys(match (true) {
                    in_array($m->store?->type, ['kitchen', 'bar']) || stripos((string) $m->department, 'minibar') !== false => 'cost_fnb',
                    $m->store?->type === 'housekeeping' => 'cost_hk',
                    default => 'cost_general',
                }),
                'wastage' => AccountHead::sys(str_starts_with((string) $m->reference, 'FW-') ? 'food_waste' : 'stock_variance'), // v5.0 food waste log → 5042
                default => AccountHead::sys('stock_variance'), // adjustment, stock-take adjust
            };
            $lines[] = [$inv, $value, null];
            $lines[] = [$counter, -$value, null];
        }

        return $lines ? ['date' => $date, 'reference' => 'STORES '.$date, 'narration' => 'Stores issues, consumption & adjustments', 'lines' => $lines] : null;
    }

    // ---------------------------------------------------------------- posting

    /** Build the posting for any source by type + key (null when the source no longer exists or posts nothing). */
    public static function build(string $type, string $key): ?array
    {
        return match ($type) {
            'folio_tx' => ($t = FolioTransaction::find($key)) ? self::forFolioTx($t) : null,
            'pos' => ($o = PosOrder::find($key)) ? self::forPosOrder($o) : null,
            'voucher' => ($v = Voucher::find($key)) ? self::forVoucher($v) : null,
            'po' => ($po = PurchaseOrder::find($key)) ? self::forPurchase($po) : null,
            'grn' => ($g = \App\Models\GoodsReceipt::with('supplier', 'order')->find($key)) ? self::forGoodsReceipt($g) : null,
            'stock' => self::forStockDay($key),
            'ap_inv' => ($d = SupplierInvoice::with('lines', 'supplier', 'purchaseOrder')->find($key)) ? self::forSupplierInvoice($d) : null,
            'ap_pay' => ($p = SupplierPayment::with('supplier', 'paymentMode')->find($key)) ? self::forSupplierPayment($p) : null,
            'payroll' => ($r = PayrollRun::find($key)) ? self::forPayrollRun($r) : null,
            'payroll_pay' => ($r = PayrollRun::find($key)) ? self::forPayrollPayment($r) : null,
            'advance' => ($a = StaffAdvance::with('employee', 'paymentMode')->find($key)) ? self::forStaffAdvance($a) : null,
            'gw_payout' => ($po = GatewayPayout::with('paymentMode')->find($key)) ? self::forGatewayPayout($po) : null,
            'asset' => ($a = \App\Models\Asset::with('paymentMode')->find($key)) ? AssetService::forAcquisition($a) : null,
            'asset_dep' => ($r = \App\Models\AssetDepRun::find($key)) ? AssetService::forDepRun($r) : null,
            'asset_disp' => ($a = \App\Models\Asset::with('disposalMode')->find($key)) ? AssetService::forDisposal($a) : null,
            'forex' => ($x = \App\Models\ForexExchange::with('currency')->find($key)) ? ForexService::forExchange($x) : null,
            'fx_surr' => ($s = \App\Models\ForexSurrender::find($key)) ? ForexService::forSurrender($s) : null,
            'gv' => ($t = \App\Models\GiftVoucherTransaction::with('voucher', 'paymentMode')->find($key)) ? GiftVoucherService::forTransaction($t) : null,
            'stat_remit' => ($r = \App\Models\StatutoryRemittance::find($key)) ? StatutoryService::forRemittance($r) : null, // v4.5
            'bank_rec' => ($l = \App\Models\BankStatementLine::find($key)) ? BankRecService::forEntry($l) : null, // v4.6
            'petty' => ($t = \App\Models\PettyTxn::with('float', 'employee')->find($key)) ? PettyCashService::forTxn($t) : null, // v4.7
            'exp_claim' => ($c = \App\Models\ExpenseClaim::with('lines', 'employee')->find($key)) ? ExpenseClaimService::forApproval($c) : null,
            'tip' => ($t = \App\Models\TipCollection::with('paymentMode')->find($key)) ? ServiceChargeService::forTip($t) : null, // v4.9
            'sc_dist' => ($d = \App\Models\ScDistribution::find($key)) ? ServiceChargeService::forRetention($d) : null,
            'club_chg' => ($c = \App\Models\ClubCharge::with('membership', 'creditOf')->find($key)) ? ClubBillingService::forCharge($c) : null, // v5.1
            'club_pay' => ($p = \App\Models\ClubPayment::with('membership', 'paymentMode')->find($key)) ? ClubBillingService::forPayment($p) : null,
            'club_dp' => ($d = \App\Models\ClubDayPass::with('paymentMode')->find($key)) ? ClubBillingService::forDayPass($d) : null,
            'club_tsale' => ($s = \App\Models\ClubTicketSale::with(['payments.paymentMode', 'items'])->find($key)) ? ClubBillingService::forTicketSale($s) : null, // v7.38
            'club_recog' => ClubBillingService::forRecognition($key),
            'lease_chg' => ($c = \App\Models\LeaseCharge::with('lease', 'creditOf')->find($key)) ? LeaseBillingService::forCharge($c) : null, // v5.2
            'lease_pay' => ($p = \App\Models\LeasePayment::with('lease', 'paymentMode')->find($key)) ? LeaseBillingService::forPayment($p) : null,
            'exp_claim_pay' => ($c = \App\Models\ExpenseClaim::with('employee')->find($key)) ? ExpenseClaimService::forPayment($c) : null,
            'pkg_day' => PackageService::forGlDay($key), // v5.5
            default => null,
        };
    }

    /** Merge lines per account, drop zeros, round, and absorb tiny imbalances into round-off. */
    public static function normalise(array $lines): array
    {
        $net = [];
        $memo = [];
        foreach ($lines as [$acct, $amt, $m]) {
            $net[$acct] = ($net[$acct] ?? 0) + (float) $amt;
            $memo[$acct] ??= $m;
        }
        $out = [];
        foreach ($net as $acct => $amt) {
            $amt = round($amt, 2);
            if (abs($amt) >= 0.005) {
                $out[] = [(int) $acct, $amt, $memo[$acct]];
            }
        }
        $diff = round(array_sum(array_column($out, 1)), 2);
        if (abs($diff) >= 0.005) {
            if (abs($diff) > self::ROUNDING_TOLERANCE) {
                throw new \RuntimeException('Unbalanced by '.number_format($diff, 2));
            }
            $out[] = [AccountHead::sys('rounding'), -$diff, 'auto round-off'];
        }

        return $out;
    }

    public static function hash(array $lines): string
    {
        $key = array_map(fn ($l) => $l[0].':'.number_format($l[1], 2, '.', ''), $lines);
        sort($key);

        return md5(implode('|', $key));
    }

    public static function post(array $doc, string $sourceType, ?string $sourceKey, array $extra = []): GlJournal
    {
        $lines = self::normalise($doc['lines']);
        if (! $lines) {
            throw new \RuntimeException('Nothing to post');
        }

        return DB::transaction(function () use ($doc, $sourceType, $sourceKey, $extra, $lines) {
            $j = GlJournal::create([
                'journal_no' => Numbering::next('journal'), 'date' => $doc['date'], 'source_type' => $sourceType, 'source_key' => $sourceKey,
                'reference' => mb_substr((string) ($doc['reference'] ?? ''), 0, 80) ?: null, 'narration' => mb_substr((string) ($doc['narration'] ?? ''), 0, 255) ?: null,
                'total' => array_sum(array_map(fn ($l) => max($l[1], 0), $lines)), 'hash' => self::hash($lines),
                'is_manual' => $sourceType === 'manual', 'is_closing' => $sourceType === 'ye_close', 'created_by' => auth()->id(),
            ] + $extra);
            foreach ($lines as [$acct, $amt, $memo]) {
                GlLine::create(['gl_journal_id' => $j->id, 'account_head_id' => $acct, 'date' => $doc['date'], 'debit' => max($amt, 0), 'credit' => max(-$amt, 0), 'memo' => $memo ? mb_substr((string) $memo, 0, 150) : null, 'closing' => $j->is_closing]);
            }

            return $j;
        });
    }

    public static function reverse(GlJournal $j, string $date, ?string $why = null): GlJournal
    {
        if ($j->reversed_by_id) {
            throw new \RuntimeException("Journal {$j->journal_no} is already reversed.");
        }
        if ($j->reversal_of_id) {
            throw new \RuntimeException("{$j->journal_no} is itself a reversal.");
        }
        $date = self::openDate($date);
        if ($date < $j->date->toDateString()) {
            $date = $j->date->toDateString();
        }

        return DB::transaction(function () use ($j, $date, $why) {
            $lines = $j->lines()->get()->map(fn ($l) => [$l->account_head_id, (float) $l->credit - (float) $l->debit, $l->memo])->all();
            $r = self::post(['date' => $date, 'reference' => $j->reference, 'narration' => 'Reversal of '.$j->journal_no.($why ? ' – '.$why : ''), 'lines' => $lines],
                $j->source_type, $j->source_key, ['reversal_of_id' => $j->id]);
            $j->forceFill(['reversed_by_id' => $r->id])->save();

            return $r;
        });
    }

    /** Bring one source in line with its current state. Returns 'posted'|'reposted'|'reversed'|null. */
    public static function reconcile(string $type, string $key, ?array $doc, string $changeDate): ?string
    {
        $active = GlJournal::where('source_type', $type)->where('source_key', $key)->whereNull('reversed_by_id')->whereNull('reversal_of_id')->latest('id')->first();
        $lines = $doc ? self::normalise($doc['lines']) : [];
        if (! $lines) {
            $doc = null;
        }
        if (! $active && ! $doc) {
            return null;
        }
        if ($active && $doc && $active->hash === self::hash($lines)) {
            return null; // unchanged
        }

        return DB::transaction(function () use ($active, $doc, $type, $key, $changeDate) {
            if ($active) {
                self::reverse($active, max($changeDate, $active->date->toDateString()), $doc ? 'source amended' : 'source voided/removed');
            }
            if ($doc) {
                $doc['date'] = self::openDate($active ? max($changeDate, $doc['date']) : $doc['date']);
                self::post($doc, $type, $key);
            }

            return $active ? ($doc ? 'reposted' : 'reversed') : 'posted';
        });
    }

    /**
     * Post every closed day. Incremental by default (sources touched since the last run or dated after the
     * previous cut-off); $full re-checks everything after the lock date.
     */
    public static function sync(bool $full = false): array
    {
        $stats = ['posted' => 0, 'reposted' => 0, 'reversed' => 0, 'checked' => 0, 'errors' => []];
        $cutoff = self::cutoff();
        $floor = self::lockDate() ?? '1900-01-01';
        $prevCutoff = setting('gl_cutoff') ?: '1900-01-01';
        $since = setting('gl_synced_at');
        $sinceTs = $since ? Carbon::parse($since)->subMinutes(5) : null;
        $started = now();
        // A cursor written by another process can be ahead of this worker's clock; don't skip newer sources.
        $full = $full || ! $since || ($sinceTs && $sinceTs->gt($started));

        // serialise concurrent runs per hotel
        DB::transaction(function () use (&$stats, $cutoff, $floor, $prevCutoff, $sinceTs, $full) {
            Setting::firstOrCreate(['key' => 'gl_sync_lock'], ['value' => '0']);
            Setting::where('key', 'gl_sync_lock')->lockForUpdate()->first();
            ChartOfAccounts::ensureLight();

            $scope = function ($q, string $dateCol) use ($cutoff, $floor, $prevCutoff, $sinceTs, $full) {
                $q->where($dateCol, '<=', $cutoff);

                return $full
                    ? $q->where($dateCol, '>', $floor)
                    : $q->where(fn ($w) => $w->where('updated_at', '>=', $sinceTs)->orWhere($dateCol, '>', max($prevCutoff, $floor)));
            };
            $run = function (string $type, string $key, callable $build) use (&$stats, $cutoff) {
                $stats['checked']++;
                try {
                    $r = self::reconcile($type, $key, $build(), $cutoff);
                    $r && $stats[$r]++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = GlJournal::SOURCES[$type]." #{$key}: ".$e->getMessage();
                    $stats['retry'][] = [$type, $key];
                }
            };

            // sources that failed last time are retried first, whatever the window
            foreach (json_decode(setting('gl_retry') ?: '[]', true) ?: [] as [$type, $key]) {
                $run($type, (string) $key, fn () => self::build($type, (string) $key));
            }

            $scope(FolioTransaction::with('folio.guest', 'folio.company', 'paymentMode'), 'business_date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($t) => $run('folio_tx', (string) $t->id, fn () => self::forFolioTx($t))));

            $scope(PosOrder::with('outlet', 'paymentMode', 'payments.paymentMode'), 'business_date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($o) => $run('pos', (string) $o->id, fn () => self::forPosOrder($o))));

            $scope(Voucher::with('paymentMode'), 'date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($v) => $run('voucher', (string) $v->id, fn () => self::forVoucher($v))));
            // deleted vouchers: reverse what was posted
            $posted = GlJournal::where('source_type', 'voucher')->whereNull('reversed_by_id')->whereNull('reversal_of_id')->pluck('source_key');
            $posted->diff(Voucher::whereIn('id', $posted)->pluck('id')->map(fn ($id) => (string) $id))
                ->each(fn ($k) => $run('voucher', (string) $k, fn () => null));

            $poNos = $scope(StockMovement::where('type', 'purchase'), 'date')->distinct()->pluck('reference')->filter();
            PurchaseOrder::with('supplier')->whereIn('po_no', $poNos)->get()
                ->each(fn ($po) => $run('po', (string) $po->id, fn () => self::forPurchase($po)));
            // v4.8 partial deliveries: one journal per goods receipt note
            $scope(\App\Models\GoodsReceipt::with('supplier', 'order'), 'date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($g) => $run('grn', (string) $g->id, fn () => self::forGoodsReceipt($g))));

            $scope(SupplierInvoice::with('lines', 'supplier', 'purchaseOrder'), 'doc_date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($d) => $run('ap_inv', (string) $d->id, fn () => self::forSupplierInvoice($d))));

            $scope(SupplierPayment::with('supplier', 'paymentMode'), 'date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($p) => $run('ap_pay', (string) $p->id, fn () => self::forSupplierPayment($p))));

            $scope(PayrollRun::query(), 'period_end')->orderBy('id')->get()
                ->each(fn ($r) => $run('payroll', (string) $r->id, fn () => self::forPayrollRun($r)));
            $scope(PayrollRun::whereNotNull('paid_date'), 'paid_date')->orderBy('id')->get()
                ->each(fn ($r) => $run('payroll_pay', (string) $r->id, fn () => self::forPayrollPayment($r)));
            // deleted draft runs never posted; a reopened run is caught by updated_at
            $scope(StaffAdvance::with('employee', 'paymentMode'), 'date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($a) => $run('advance', (string) $a->id, fn () => self::forStaffAdvance($a))));

            $scope(GatewayPayout::with('paymentMode'), 'date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($po) => $run('gw_payout', (string) $po->id, fn () => self::forGatewayPayout($po))));
            // deleted payouts: reverse what was posted
            $postedPo = GlJournal::where('source_type', 'gw_payout')->whereNull('reversed_by_id')->whereNull('reversal_of_id')->pluck('source_key');
            $postedPo->diff(GatewayPayout::whereIn('id', $postedPo)->pluck('id')->map(fn ($id) => (string) $id))
                ->each(fn ($k) => $run('gw_payout', (string) $k, fn () => null));

            // v2.3 fixed assets: direct purchases, monthly depreciation runs (reversal → null doc), disposals
            $scope(\App\Models\Asset::with('paymentMode'), 'purchase_date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($a) => $run('asset', (string) $a->id, fn () => AssetService::forAcquisition($a))));
            $postedFa = GlJournal::where('source_type', 'asset')->whereNull('reversed_by_id')->whereNull('reversal_of_id')->pluck('source_key');
            $postedFa->diff(\App\Models\Asset::whereIn('id', $postedFa)->pluck('id')->map(fn ($id) => (string) $id))
                ->each(fn ($k) => $run('asset', (string) $k, fn () => null));
            $scope(\App\Models\AssetDepRun::query(), 'period_end')->orderBy('id')->get()
                ->each(fn ($r) => $run('asset_dep', (string) $r->id, fn () => AssetService::forDepRun($r)));
            $scope(\App\Models\Asset::with('disposalMode')->whereNotNull('disposed_date'), 'disposed_date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($a) => $run('asset_disp', (string) $a->id, fn () => AssetService::forDisposal($a))));

            // v3.4 forex desk: cash encashments (voided → reversed) and surrenders to the bank
            $scope(\App\Models\ForexExchange::with('currency'), 'business_date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($x) => $run('forex', (string) $x->id, fn () => ForexService::forExchange($x))));
            $scope(\App\Models\ForexSurrender::query(), 'date')->orderBy('id')->get()
                ->each(fn ($s) => $run('fx_surr', (string) $s->id, fn () => ForexService::forSurrender($s)));

            // v3.8 gift vouchers: sales / reloads / promotional issues, breakage and cancellations (redemptions post via the payment mode)
            $scope(\App\Models\GiftVoucherTransaction::with('voucher', 'paymentMode')->whereIn('type', ['issue', 'reload', 'expire', 'cancel']), 'business_date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($t) => $run('gv', (string) $t->id, fn () => GiftVoucherService::forTransaction($t))));

            // v4.5 SSNIT / Tier 2 / PAYE remittances (cancelled → reversed, caught by updated_at)
            $scope(\App\Models\StatutoryRemittance::query(), 'paid_on')->orderBy('id')->get()
                ->each(fn ($r) => $run('stat_remit', (string) $r->id, fn () => StatutoryService::forRemittance($r)));

            // v4.6 charges / interest / MoMo fees booked from a bank statement line
            $scope(\App\Models\BankStatementLine::whereNotNull('entry_kind'), 'date')->orderBy('id')->get()
                ->each(fn ($l) => $run('bank_rec', (string) $l->id, fn () => BankRecService::forEntry($l)));

            // v4.7 petty cash movements (voided / rejected → reversed) and staff expense claims (approval accrual, payment)
            $scope(\App\Models\PettyTxn::with('float', 'employee'), 'date')->orderBy('id')
                ->chunkById(500, fn ($rows) => $rows->each(fn ($t) => $run('petty', (string) $t->id, fn () => PettyCashService::forTxn($t))));
            $scope(\App\Models\ExpenseClaim::with('lines', 'employee')->whereNotNull('approved_on'), 'approved_on')->orderBy('id')->get()
                ->each(fn ($c) => $run('exp_claim', (string) $c->id, fn () => ExpenseClaimService::forApproval($c)));
            $scope(\App\Models\ExpenseClaim::with('employee')->whereNotNull('paid_on')->where('pay_method', 'mode'), 'paid_on')->orderBy('id')->get()
                ->each(fn ($c) => $run('exp_claim_pay', (string) $c->id, fn () => ExpenseClaimService::forPayment($c)));

            // v4.9 staff tips paid into the service charge pool, and the house retention of each approved distribution
            $scope(\App\Models\TipCollection::with('paymentMode'), 'date')->orderBy('id')->get()
                ->each(fn ($t) => $run('tip', (string) $t->id, fn () => ServiceChargeService::forTip($t)));
            $scope(\App\Models\ScDistribution::whereNotNull('approved_on'), 'approved_on')->orderBy('id')->get()
                ->each(fn ($d) => $run('sc_dist', (string) $d->id, fn () => ServiceChargeService::forRetention($d)));

            // v5.1 health club: members' charges and receipts, paid day passes, and the monthly release of membership fees
            $scope(\App\Models\ClubCharge::with('membership', 'creditOf'), 'date')->orderBy('id')->get()
                ->each(fn ($c) => $run('club_chg', (string) $c->id, fn () => ClubBillingService::forCharge($c)));
            $scope(\App\Models\ClubPayment::with('membership', 'paymentMode'), 'date')->orderBy('id')->get()
                ->each(fn ($p) => $run('club_pay', (string) $p->id, fn () => ClubBillingService::forPayment($p)));
            $scope(\App\Models\ClubDayPass::with('paymentMode'), 'date')->orderBy('id')->get()
                ->each(fn ($d) => $run('club_dp', (string) $d->id, fn () => ClubBillingService::forDayPass($d)));
            $scope(\App\Models\ClubTicketSale::with(['payments.paymentMode', 'items']), 'business_date')->orderBy('id')->get()
                ->each(fn ($s) => $run('club_tsale', (string) $s->id, fn () => ClubBillingService::forTicketSale($s)));
            foreach (ClubBillingService::recognitionMonths($cutoff, $floor) as $mo) {
                $run('club_recog', $mo, fn () => ClubBillingService::forRecognition($mo));
            }

            // v5.2 apartments & leases: tenant charges (rent, service charge, utilities, fees, credit notes) and receipts / deposits
            $scope(\App\Models\LeaseCharge::with('lease', 'creditOf'), 'date')->orderBy('id')->get()
                ->each(fn ($c) => $run('lease_chg', (string) $c->id, fn () => LeaseBillingService::forCharge($c)));
            $scope(\App\Models\LeasePayment::with('lease', 'paymentMode'), 'date')->orderBy('id')->get()
                ->each(fn ($p) => $run('lease_pay', (string) $p->id, fn () => LeaseBillingService::forPayment($p)));

            // v5.5 rate packages: daily allocation out of room revenue, consumption at the outlets and breakage
            foreach (PackageService::glDates($scope) as $d) {
                $run('pkg_day', $d, fn () => PackageService::forGlDay($d));
            }

            $scope(StockMovement::whereNotIn('type', ['purchase', 'transfer_in', 'transfer_out']), 'date')->distinct()->pluck('date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()
                ->each(fn ($d) => $run('stock', $d, fn () => self::forStockDay($d)));
        });

        $retry = collect($stats['retry'] ?? [])->unique(fn ($r) => implode(':', $r))->values()->all();
        unset($stats['retry']);
        foreach (['gl_cutoff' => $cutoff, 'gl_synced_at' => $started->toDateTimeString(), 'gl_retry' => json_encode($retry), 'gl_last_errors' => json_encode(array_slice($stats['errors'], 0, 50))] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }

        return $stats;
    }

    /** Used by the night audit: the ledger must never block closing the day. */
    public static function syncSafely(): ?array
    {
        try {
            return self::sync();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // ---------------------------------------------------------------- queries

    /** Per-account debit/credit totals between two dates (inclusive); null from = since inception. $closing = false leaves out year-end closing entries (v5.6). */
    public static function totals(?string $from, string $to, bool $closing = true): \Illuminate\Support\Collection
    {
        return GlLine::query()->when($from, fn ($q) => $q->whereDate('date', '>=', $from))->whereDate('date', '<=', $to)->when(! $closing, fn ($q) => $q->where('closing', false))
            ->groupBy('account_head_id')->selectRaw('account_head_id, SUM(debit) dr, SUM(credit) cr')->get()->keyBy('account_head_id');
    }

    /** Signed balance (debit − credit) of one system account up to a date. */
    public static function balance(string $sysKey, string $asOf): float
    {
        // whereDate, not where: SQLite keeps whatever shape the writer used ("Y-m-d" or "Y-m-d 00:00:00"),
        // and a string comparison against a date-only bound silently drops the as-of day itself.
        return round((float) GlLine::where('account_head_id', AccountHead::sys($sysKey))->whereDate('date', '<=', $asOf)->sum(DB::raw('debit - credit')), 2);
    }

    /** Sub-ledger vs control account checks as at a date. */
    /** The last month end on or before $d (the club recognition journals are monthly). */
    public static function monthEndBefore(string $d): string
    {
        $c = Carbon::parse($d);

        return $c->isLastOfMonth() ? $c->toDateString() : $c->startOfMonth()->subDay()->toDateString();
    }

    public static function controls(string $asOf): array
    {
        $fo = FolioTransaction::where('is_void', false)->whereDate('business_date', '<=', $asOf)
            ->selectRaw("SUM(CASE WHEN type='charge' THEN amount + tax_amount WHEN type='refund' THEN amount WHEN type IN ('payment','discount','allowance') THEN -amount ELSE 0 END) bal")->value('bal');
        $clDebits = FolioTransaction::where('is_void', false)->where('type', 'payment')->where('category', 'city_ledger')->whereDate('business_date', '<=', $asOf)->sum('amount');
        $clCredits = Voucher::where('type', 'receipt')->whereNotNull('company_id')->whereDate('date', '<=', $asOf)->sum('amount');
        $stock = DB::table('stock_movements as m')->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')
            ->where('m.hotel_id', Tenant::id())->whereDate('m.date', '<=', $asOf)
            ->selectRaw('SUM(ROUND(m.qty * COALESCE(NULLIF(m.rate, 0), i.last_cost), 2)) v')->value('v'); // each movement is posted at its rounded value
        $fa = AssetService::registerBalances($asOf);

        return [
            ['label' => 'Guest ledger (open folio balances)', 'key' => 'guest_ledger', 'sub' => round((float) $fo, 2), 'gl' => self::balance('guest_ledger', $asOf), 'strict' => true],
            ['label' => 'City ledger (company receivables)', 'key' => 'city_ledger', 'sub' => round((float) $clDebits - (float) $clCredits, 2), 'gl' => self::balance('city_ledger', $asOf), 'strict' => true],
            ['label' => 'Accounts payable (supplier sub-ledger)', 'key' => 'accounts_payable', 'sub' => ApService::controlBalance($asOf), 'gl' => -self::balance('accounts_payable', $asOf), 'strict' => true],
            ['label' => 'Salaries payable (approved, unpaid payroll)', 'key' => 'salary_payable', 'sub' => PayrollService::payableBalance($asOf), 'gl' => -self::balance('salary_payable', $asOf), 'strict' => true],
            ['label' => 'Staff advances (outstanding)', 'key' => 'staff_advances', 'sub' => PayrollService::advancesBalance($asOf), 'gl' => self::balance('staff_advances', $asOf), 'strict' => true],
            ['label' => 'Club members receivable (members\' accounts)', 'key' => 'club_receivable', 'sub' => ClubBillingService::controlBalance($asOf), 'gl' => self::balance('club_receivable', $asOf), 'strict' => true],
            ['label' => 'Membership fees in advance (unearned subscriptions)', 'key' => 'club_unearned', 'sub' => ClubBillingService::unearned(self::monthEndBefore($asOf)), 'gl' => -self::balance('club_unearned', self::monthEndBefore($asOf)), 'strict' => false],
            ['label' => 'Tenant receivables (lease accounts)', 'key' => 'lease_receivable', 'sub' => LeaseBillingService::controlBalance($asOf), 'gl' => self::balance('lease_receivable', $asOf), 'strict' => true],
            ['label' => 'Tenant security deposits held', 'key' => 'lease_deposits', 'sub' => LeaseBillingService::depositControl($asOf), 'gl' => -self::balance('lease_deposits', $asOf), 'strict' => true],
            ['label' => 'Payment gateway clearing (collected online, not yet paid out)', 'key' => 'gateway_clearing', 'sub' => OnlinePaymentService::clearingBalance($asOf), 'gl' => self::balance('gateway_clearing', $asOf), 'strict' => true],
            ['label' => 'Cheques in hand (cheque register – received, not yet banked)', 'key' => 'cheques_in_hand', 'sub' => TreasuryService::chequesInHand($asOf), 'gl' => self::balance('cheques_in_hand', $asOf), 'strict' => false], // v5.7
            ['label' => 'Cash in transit (transfers sent, not yet credited)', 'key' => 'cash_in_transit', 'sub' => TreasuryService::inTransit($asOf), 'gl' => self::balance('cash_in_transit', $asOf), 'strict' => false],
            ['label' => 'Management fees payable (accrued, unpaid fees)', 'key' => 'mgmt_fees_payable', 'sub' => OwnerService::payable($asOf), 'gl' => -self::balance('mgmt_fees_payable', $asOf), 'strict' => false], // v5.8
            ['label' => 'Activity vendors payable (room charges less commission and settlements)', 'key' => 'concierge_payable', 'sub' => ConciergeService::payableBalance($asOf), 'gl' => -self::balance('concierge_payable', $asOf), 'strict' => false], // v5.9
            ['label' => 'Commission receivable from activity vendors', 'key' => 'concierge_receivable', 'sub' => ConciergeService::receivableBalance($asOf), 'gl' => self::balance('concierge_receivable', $asOf), 'strict' => false],
            ['label' => 'Inventory (stores valuation at cost)', 'key' => 'inventory', 'sub' => round((float) $stock, 2), 'gl' => self::balance('inventory', $asOf), 'strict' => false],
            ['label' => 'Fixed assets at cost (asset register)', 'key' => 'fixed_assets', 'sub' => $fa[0], 'gl' => self::balance('fixed_assets', $asOf), 'strict' => false],
            ['label' => 'Accumulated depreciation (asset register)', 'key' => 'acc_depreciation', 'sub' => $fa[1], 'gl' => -self::balance('acc_depreciation', $asOf), 'strict' => false],
        ];
    }
}
