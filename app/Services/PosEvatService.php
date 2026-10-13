<?php

namespace App\Services;

use App\Models\EinvoiceLog;
use App\Models\PosOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v4.4 GRA E-VAT for restaurant / outlet bills.
 *
 * A bill paid at the outlet is a sale in its own right, so it goes to the GRA Virtual Sales Data Controller like an
 * invoice (flag INVOICE). The VSDC stamp (SDC ID, receipt no., internal data, signature, QR) is printed on the bill.
 * Bills posted to a room are not stamped here – they become part of the guest's folio invoice, which is stamped
 * at check-out. Complimentary bills are not sales.
 *
 * Settled bills are final in Nowills (no re-opening), so a bill is stamped once. Failures are retried with the same
 * back-off as invoices (`pms:evat-retry` / Retry now), and appear on the E-VAT screen and the dashboard.
 *
 * Amounts: tax-exclusive item prices; the bill discount is spread over the items in proportion to their value;
 * the service charge is sent as a separate item without VAT or levies (as billed); cash round-off is not part of the
 * fiscal document.
 */
class PosEvatService
{
    public static function enabled(): bool
    {
        return EInvoiceService::enabled() && setting('gst_evat_pos', '1') === '1';
    }

    public static function stampable(PosOrder $o): bool
    {
        return $o->status === 'settled' && $o->settlement === 'payment' && (float) $o->total > 0 && $o->bill_no;
    }

    public static function eligible(PosOrder $o): bool
    {
        return self::enabled() && self::stampable($o) && $o->evat_status !== 'generated';
    }

    /** Model hook: a bill just became settled → queue it, and stamp straight after the commit when auto-stamping is on. */
    public static function onSettled(PosOrder $o): void
    {
        if (! self::eligible($o)) {
            return;
        }
        $o->forceFill(['evat_status' => 'pending'])->saveQuietly();
        if (setting('gst_einvoice_auto', '1') === '1') {
            $id = $o->id;
            DB::afterCommit(fn () => ($fresh = PosOrder::find($id)) && self::generate($fresh));
        }
    }

    /** Fiscal lines: each item tax-exclusive, with its share of the discount and its VAT / NHIL / GETFund / Tourism levy. */
    public static function lines(PosOrder $o): Collection
    {
        $items = $o->items()->where('is_cancelled', false)->where(fn ($q) => $q->where('is_pending', false)->orWhereNull('is_pending'))->orderBy('id')->get();
        $sub = (float) $items->sum('amount');
        if ($sub <= 0) {
            return collect();
        }
        $disc = min((float) $o->discount, $sub);
        $ratio = ($sub - $disc) / $sub;
        $weighted = (float) $items->sum(fn ($i) => $i->amount * $i->tax_rate);
        $rows = $items->values()->map(fn ($i, $k) => [
            'code' => 'FNB-'.($i->menu_item_id ?: 'X'.($k + 1)), 'desc' => $i->item_name, 'qty' => (float) $i->qty, 'unit' => (float) $i->rate,
            'amount' => (float) $i->amount, 'rate' => (float) $i->tax_rate,
            'discount' => round((float) $i->amount * (1 - $ratio), 2),
            'tax' => $weighted > 0 ? round((float) $o->tax * $i->amount * $i->tax_rate / $weighted, 2) : 0.0,
        ])->all();
        // make the parts add up exactly to the bill (rounding residue on the largest line)
        $big = collect($rows)->sortByDesc('amount')->keys()->first();
        $rows[$big]['discount'] = round($rows[$big]['discount'] + round($disc - collect($rows)->sum('discount'), 2), 2);
        $rows[$big]['tax'] = round($rows[$big]['tax'] + round((float) $o->tax - collect($rows)->sum('tax'), 2), 2);

        return collect($rows)->map(function ($r) {
            $b = TaxService::breakdown($r['tax'], 'fnb', $r['rate']);

            return $r + ['taxable' => round($r['amount'] - $r['discount'], 2), 'vat' => $b['VAT'], 'nhil' => $b['NHIL'], 'getfund' => $b['GETFUND'], 'tl' => $b['TL']];
        });
    }

    public static function validate(PosOrder $o): array
    {
        $e = [];
        if (! GstService::validTin(GstService::hotelTin())) {
            $e[] = 'Hotel TIN is missing or invalid (Administration › Hotel settings).';
        }
        if ($o->buyer_tin && ! GstService::validTin($o->buyer_tin)) {
            $e[] = "Customer TIN {$o->buyer_tin} is not a valid Ghana TIN / Ghana Card PIN.";
        } elseif ($o->buyer_tin && GstService::normaliseTin($o->buyer_tin) === GstService::hotelTin()) {
            $e[] = 'The customer TIN is the hotel\'s own TIN.';
        }
        if (self::lines($o)->isEmpty()) {
            $e[] = 'The bill has no items.';
        }

        return $e;
    }

    public static function payload(PosOrder $o): array
    {
        $h = hotel();
        $lines = self::lines($o);
        $items = $lines->values()->map(fn ($l) => [
            'itemCode' => Str::limit($l['code'], 20, ''), 'itemCategory' => '', 'expireDate' => '', 'description' => mb_substr($l['desc'], 0, 150),
            'quantity' => $l['qty'], 'unitPrice' => $l['unit'], 'discountAmount' => $l['discount'], 'batchCode' => '',
            'levyAmountA' => $l['nhil'], 'levyAmountB' => $l['getfund'], 'levyAmountC' => 0, 'levyAmountD' => 0, 'levyAmountE' => $l['tl'],
            'vatAmount' => $l['vat'], 'vatRate' => (float) (TaxService::componentRates('fnb')['VAT'] ?? 0),
        ])->all();
        if ((float) $o->service_charge > 0) {
            $items[] = ['itemCode' => 'SVC', 'itemCategory' => '', 'expireDate' => '', 'description' => 'Service charge', 'quantity' => 1, 'unitPrice' => (float) $o->service_charge,
                'discountAmount' => 0, 'batchCode' => '', 'levyAmountA' => 0, 'levyAmountB' => 0, 'levyAmountC' => 0, 'levyAmountD' => 0, 'levyAmountE' => 0, 'vatAmount' => 0, 'vatRate' => 0];
        }
        $tin = $o->buyer_tin ? GstService::normaliseTin($o->buyer_tin) : null;
        $at = $o->settled_at ?? now();

        return [
            'currency' => $h->currency ?: 'GHS', 'exchangeRate' => 1.0, 'invoiceNumber' => $o->bill_no,
            'totalLevy' => round($lines->sum(fn ($l) => $l['nhil'] + $l['getfund'] + $l['tl']), 2),
            'userName' => (string) (setting('gst_evat_username', '') ?: (auth()->user()?->username ?? 'system')),
            'flag' => 'INVOICE', 'calculationType' => 'EXCLUSIVE', 'totalVat' => round($lines->sum('vat'), 2),
            'transactionDate' => Carbon::parse($o->business_date->toDateString().' '.$at->format('H:i:s'))->format('Y-m-d\TH:i:s\Z'),
            'totalAmount' => round((float) $o->subtotal - (float) $o->discount + (float) $o->service_charge + (float) $o->tax, 2), 'voucherAmount' => 0,
            'businessPartnerName' => $tin ? ($o->buyer_name ?: 'Business customer') : 'Cash customer', 'businessPartnerTin' => $tin ?: GstService::CASH_CUSTOMER_TIN,
            'saleType' => 'NORMAL', 'discountType' => 'GENERAL', 'discountAmount' => round((float) $o->discount, 2),
            'reference' => '', 'groupReferenceId' => '', 'purchaseOrderReference' => '',
            'sellerTin' => GstService::hotelTin(), 'sellerName' => $h->legal_name ?: $h->name,
            'items' => $items,
        ];
    }

    /** Stamp a bill. True when it carries a VSDC stamp afterwards. Never throws for VSDC / network errors. */
    public static function generate(PosOrder $o, ?int $userId = null): bool
    {
        if ($o->evat_status === 'generated') {
            return true;
        }
        if (! self::enabled() || ! self::stampable($o)) {
            return false;
        }
        $userId ??= auth()->id();
        if ($errors = self::validate($o)) {
            self::fail($o, 'VALIDATION', implode(' ', $errors), false);
            self::log($o, false, 'VALIDATION', implode(' ', $errors), null, null, 0, $userId);

            return false;
        }
        $payload = self::payload($o);
        [$res, $ms] = EInvoiceService::submit($payload);
        if ($res['ok']) {
            $o->forceFill(['evat_status' => 'generated', 'evat_sdc_id' => $res['sdc_id'], 'evat_rec_num' => (string) $res['rec_num'], 'evat_at' => Carbon::parse($res['time']),
                'evat_qr' => $res['qr'], 'evat_intdata' => $res['intdata'], 'evat_signature' => $res['signature'], 'evat_error' => null, 'evat_next_try_at' => null,
                'evat_retryable' => true, 'evat_attempts' => min(255, $o->evat_attempts + 1)])->saveQuietly();
            self::log($o, true, null, 'SDC '.$res['sdc_id'].' · receipt '.$res['rec_num'], $payload, $res['raw'], $ms, $userId);
            ActivityLogger::log('pos', 'evat', "Bill {$o->bill_no} stamped by GRA E-VAT (SDC {$res['sdc_id']}, receipt {$res['rec_num']})", $o);

            return true;
        }
        self::fail($o, $res['code'], $res['message'], $res['retry']);
        self::log($o, false, $res['code'], $res['message'], $payload, $res['raw'], $ms, $userId);

        return false;
    }

    protected static function fail(PosOrder $o, ?string $code, string $message, bool $retry): void
    {
        $n = $o->evat_attempts + 1;
        $o->forceFill(['evat_status' => 'failed', 'evat_error' => Str::limit(($code && $code !== 'VALIDATION' ? "[{$code}] " : '').$message, 490), 'evat_attempts' => min(255, $n),
            'evat_retryable' => $retry, 'evat_next_try_at' => $retry ? now()->addMinutes(EInvoiceService::BACKOFF_MIN[min($n, count(EInvoiceService::BACKOFF_MIN)) - 1]) : null])->saveQuietly();
    }

    protected static function log(PosOrder $o, bool $ok, ?string $code, ?string $msg, ?array $req, $raw, int $ms, ?int $userId): void
    {
        EinvoiceLog::create(['folio_id' => null, 'pos_order_id' => $o->id, 'doc_no' => $o->bill_no, 'action' => 'generate', 'driver' => EInvoiceService::driver(), 'ok' => $ok,
            'error_code' => $code, 'message' => Str::limit((string) $msg, 490),
            'request' => $req ? json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'response' => $raw ? json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, 'duration_ms' => $ms, 'user_id' => $userId, 'created_at' => now()]);
    }

    /** Bills still waiting for a stamp. $due = only those whose back-off has elapsed (scheduler); otherwise every retryable one (Retry now). */
    public static function queue(bool $due = true)
    {
        return PosOrder::where('status', 'settled')->where('settlement', 'payment')
            ->where(fn ($w) => $w->where('evat_status', 'pending')->orWhere(fn ($x) => $x->where('evat_status', 'failed')->where('evat_retryable', true)
                ->when($due, fn ($q) => $q->where('evat_next_try_at', '<=', now()))))
            ->orderBy('business_date')->orderBy('id');
    }

    public static function retry(bool $due = true, ?int $userId = null): array
    {
        $ok = 0;
        $bad = 0;
        if (! self::enabled()) {
            return [0, 0];
        }
        foreach (self::queue($due)->limit(200)->get() as $o) {
            self::generate($o, $userId) ? $ok++ : $bad++;
        }

        return [$ok, $bad];
    }

    /** Settled outlet bills of the period that are not stamped (for the VAT return pre-filing checks). */
    public static function unstamped(string $from, string $to): int
    {
        return self::enabled() ? PosOrder::where('status', 'settled')->where('settlement', 'payment')->where('total', '>', 0)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('evat_status')->orWhere('evat_status', '!=', 'generated'))->count() : 0;
    }

    public static function failedCount(): int
    {
        return self::enabled() ? PosOrder::where('evat_status', 'failed')->count() : 0;
    }
}
