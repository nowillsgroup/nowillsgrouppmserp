<?php

namespace App\Services;

use App\Models\EinvoiceLog;
use App\Models\Folio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * v4.3 GRA E-VAT (Ghana): every tax invoice and credit note is submitted to the GRA Virtual Sales Data Controller
 * (VSDC) before it is handed to the guest. The VSDC signs the document and returns the SDC ID, receipt counter,
 * internal data, signature, timestamp and a QR code – all printed on the invoice.
 *
 * Payload modelled on the GRA E-VAT API (JSON): tax-exclusive item prices, levyAmountA = NHIL, levyAmountB = GETFund,
 * levyAmountC = COVID-19 levy (abolished 1 Jan 2026 → 0), levyAmountD = CST (n/a), levyAmountE = Tourism levy;
 * flag INVOICE / REFUND (credit note, referencing the original invoice). Confirm field names with GRA during
 * joint user-acceptance testing before going live with the "gra" driver.
 *
 * Stored on the folio (column names kept from v3.0): irn = ysdcid, irn_ack_no = ysdcrecnum, irn_ack_at = ysdctime,
 * irn_qr = qr_code, evat_intdata = ysdcintdata, evat_signature = ysdcregsig.
 * A stamped document cannot be cancelled – corrections are credit notes (REFUND).
 */
class EInvoiceService
{
    public const BACKOFF_MIN = [5, 15, 60, 180, 720];

    public static function enabled(): bool
    {
        return GstService::isGhana() && setting('gst_einvoice_enabled', '0') === '1';
    }

    public static function driver(): string
    {
        return setting('gst_einvoice_driver', 'sandbox') === 'gra' ? 'gra' : 'sandbox';
    }

    public static function driverLabel(?string $d = null): string
    {
        return ($d ?? self::driver()) === 'gra' ? 'GRA E-VAT API' : 'Sandbox (offline VSDC simulator)';
    }

    /** All issued invoices and credit notes go through E-VAT (B2B and B2C). */
    public static function eligible(Folio $f): bool
    {
        return self::enabled() && $f->status === 'closed' && $f->invoice_no && ! $f->hasActiveIrn();
    }

    /** Problems that must be fixed in the data before the VSDC can accept the document. */
    public static function validate(Folio $f): array
    {
        $e = [];
        if (! GstService::validTin(GstService::hotelTin())) {
            $e[] = 'Hotel TIN is missing or invalid (Administration › Hotel settings).';
        }
        if ($f->supply_type === 'B2B') {
            if (! GstService::validTin($f->buyer_gstin)) {
                $e[] = 'Buyer TIN is invalid – correct it on the '.($f->company_id ? 'company' : 'guest').' profile, then refresh the buyer details.';
            } elseif (GstService::normaliseTin($f->buyer_gstin) === GstService::hotelTin()) {
                $e[] = 'Buyer TIN is the hotel\'s own TIN.';
            }
        }
        if (GstService::lines($f)->isEmpty()) {
            $e[] = 'The document has no taxable lines.';
        }
        if ($f->isCreditNote()) {
            if (! $f->original?->invoice_no) {
                $e[] = 'The original invoice of this credit note is missing.';
            } elseif (! $f->original->hasActiveIrn()) {
                $e[] = "The original invoice {$f->original->invoice_no} has not been stamped by E-VAT yet – submit it first.";
            }
        }

        return $e;
    }

    /** GRA E-VAT invoice / refund payload (amounts positive; tax-exclusive). */
    public static function payload(Folio $f): array
    {
        $h = hotel();
        $sign = $f->isCreditNote() ? -1 : 1;
        $lines = GstService::lines($f);
        $t = GstService::componentTotals($lines);
        $disc = round($sign * $f->gstTotals()['discount'], 2);
        $r = fn ($v) => round($sign * (float) $v, 2);
        $items = $lines->values()->map(fn ($l, $i) => [
            'itemCode' => strtoupper(substr($l['category'], 0, 12)).'-'.($i + 1), 'itemCategory' => '', 'expireDate' => '', 'description' => mb_substr($l['desc'], 0, 150),
            'quantity' => 1, 'unitPrice' => $r($l['taxable']), 'discountAmount' => 0, 'batchCode' => '',
            'levyAmountA' => $r($l['nhil']), 'levyAmountB' => $r($l['getfund']), 'levyAmountC' => 0, 'levyAmountD' => 0, 'levyAmountE' => $r($l['tl']),
            'vatAmount' => $r($l['vat']), 'vatRate' => (float) (TaxService::componentRates($l['category'])['VAT'] ?? 0),
        ])->all();
        $at = $f->closed_at ?? now();

        return [
            'currency' => $h->currency ?: 'GHS', 'exchangeRate' => 1.0, 'invoiceNumber' => $f->invoice_no,
            'totalLevy' => $r($t['nhil'] + $t['getfund'] + $t['tl']), 'userName' => (string) (setting('gst_evat_username', '') ?: (auth()->user()?->username ?? 'system')),
            'flag' => $f->isCreditNote() ? 'REFUND' : 'INVOICE', 'calculationType' => 'EXCLUSIVE', 'totalVat' => $r($t['vat']),
            'transactionDate' => Carbon::parse(($f->invoice_date ?? bdate())->toDateString().' '.$at->format('H:i:s'))->format('Y-m-d\TH:i:s\Z'),
            'totalAmount' => round($r($t['total']) - $disc, 2), 'voucherAmount' => 0,
            'businessPartnerName' => $f->buyer_name ?: 'Cash customer', 'businessPartnerTin' => $f->supply_type === 'B2B' ? GstService::normaliseTin($f->buyer_gstin) : GstService::CASH_CUSTOMER_TIN,
            'saleType' => 'NORMAL', 'discountType' => 'GENERAL', 'discountAmount' => $disc,
            'reference' => $f->isCreditNote() ? (string) $f->original?->invoice_no : '', 'groupReferenceId' => '', 'purchaseOrderReference' => '',
            'sellerTin' => GstService::hotelTin(), 'sellerName' => $h->legal_name ?: $h->name,
            'items' => $items,
        ];
    }

    /** Submit the document to the VSDC. Returns true when it is stamped afterwards. Never throws for VSDC / network errors. */
    public static function generate(Folio $f, ?int $userId = null): bool
    {
        if ($f->hasActiveIrn()) {
            return true;
        }
        $userId ??= auth()->id();
        $errors = self::validate($f);
        if ($errors) {
            self::fail($f, 'VALIDATION', implode(' ', $errors), false);
            self::log($f, 'generate', false, 'VALIDATION', implode(' ', $errors), null, null, 0, $userId);

            return false;
        }
        $payload = self::payload($f);
        [$res, $ms] = self::submit($payload);
        if ($res['ok']) {
            $f->forceFill(['irn' => $res['sdc_id'], 'irn_ack_no' => (string) $res['rec_num'], 'irn_ack_at' => Carbon::parse($res['time']), 'irn_qr' => $res['qr'],
                'evat_intdata' => $res['intdata'], 'evat_signature' => $res['signature'],
                'irn_status' => 'generated', 'irn_error' => null, 'irn_next_try_at' => null, 'irn_retryable' => true, 'irn_attempts' => $f->irn_attempts + 1])->save();
            self::log($f, 'generate', true, null, 'SDC '.$res['sdc_id'].' · receipt '.$res['rec_num'], $payload, $res['raw'], $ms, $userId);
            ActivityLogger::log('gst', 'evat', "{$f->invoice_no} stamped by GRA E-VAT (SDC {$res['sdc_id']}, receipt {$res['rec_num']})", $f);

            return true;
        }
        self::fail($f, $res['code'], $res['message'], $res['retry']);
        self::log($f, 'generate', false, $res['code'], $res['message'], $payload, $res['raw'], $ms, $userId);

        return false;
    }

    /** Send any E-VAT payload (invoice, refund, restaurant bill) through the configured driver: [normalised result, duration ms]. Never throws. */
    public static function submit(array $payload): array
    {
        $t0 = microtime(true);
        try {
            $res = self::driver() === 'gra' ? self::graSubmit($payload) : self::sandboxSubmit($payload);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'code' => 'NETWORK', 'message' => Str::limit($e->getMessage(), 300), 'retry' => true, 'raw' => null];
        }

        return [$res, (int) round((microtime(true) - $t0) * 1000)];
    }

    protected static function fail(Folio $f, ?string $code, string $message, bool $retry): void
    {
        $n = $f->irn_attempts + 1;
        $f->forceFill(['irn_status' => 'failed', 'irn_error' => Str::limit(($code && $code !== 'VALIDATION' ? "[{$code}] " : '').$message, 490), 'irn_attempts' => min(255, $n),
            'irn_retryable' => $retry, 'irn_next_try_at' => $retry ? now()->addMinutes(self::BACKOFF_MIN[min($n, count(self::BACKOFF_MIN)) - 1]) : null])->save();
    }

    /** Scheduler: retry failed (retryable) and pending documents whose back-off has elapsed. */
    public static function retryDue(): array
    {
        $ok = 0;
        $bad = 0;
        if (! self::enabled()) {
            return [0, 0];
        }
        $q = Folio::where('status', 'closed')->where(fn ($w) => $w->where('irn_status', 'pending')
            ->orWhere(fn ($x) => $x->where('irn_status', 'failed')->where('irn_retryable', true)->where('irn_next_try_at', '<=', now())));
        foreach ($q->orderBy('invoice_date')->orderBy('id')->limit(100)->get() as $f) { // oldest first: invoices before their credit notes
            self::generate($f, null) ? $ok++ : $bad++;
        }

        return [$ok, $bad];
    }

    protected static function log(Folio $f, string $action, bool $ok, ?string $code, ?string $msg, ?array $req, $raw, int $ms, ?int $userId): void
    {
        EinvoiceLog::create(['folio_id' => $f->id, 'doc_no' => $f->invoice_no, 'action' => $action, 'driver' => self::driver(), 'ok' => $ok, 'error_code' => $code,
            'message' => Str::limit((string) $msg, 490), 'request' => $req ? json_encode($req, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'response' => $raw ? json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, 'duration_ms' => $ms, 'user_id' => $userId, 'created_at' => now()]);
    }

    // ------------------------------------------------------------------ drivers

    /** Offline VSDC simulator – applies the checks GRA rejects most often and signs the document like the VSDC does. */
    protected static function sandboxSubmit(array $p): array
    {
        $items = collect($p['items']);
        $levies = round($items->sum(fn ($i) => $i['levyAmountA'] + $i['levyAmountB'] + $i['levyAmountC'] + $i['levyAmountD'] + $i['levyAmountE']), 2);
        if (abs($levies - $p['totalLevy']) > 0.05) {
            return ['ok' => false, 'code' => 'A09', 'message' => 'Total levy does not match the sum of item levies.', 'retry' => false, 'raw' => null];
        }
        if (abs(round($items->sum('vatAmount'), 2) - $p['totalVat']) > 0.05) {
            return ['ok' => false, 'code' => 'A08', 'message' => 'Total VAT does not match the sum of item VAT.', 'retry' => false, 'raw' => null];
        }
        if ($p['businessPartnerTin'] === $p['sellerTin']) {
            return ['ok' => false, 'code' => 'B16', 'message' => 'The customer TIN cannot be the seller\'s TIN.', 'retry' => false, 'raw' => null];
        }
        if ($p['flag'] === 'REFUND' && ! $p['reference']) {
            return ['ok' => false, 'code' => 'B19', 'message' => 'A refund must reference the original invoice number.', 'retry' => false, 'raw' => null];
        }
        $dup = EinvoiceLog::where('doc_no', $p['invoiceNumber'])->where('action', 'generate')->where('ok', true)->latest('id')->first();
        if ($dup && ($prev = json_decode((string) $dup->response, true)['response']['message'] ?? null)) { // already signed → VSDC returns the same stamp
            return self::mapResponse(['status' => 'SUCCESS', 'response' => ['message' => $prev, 'info' => 'DUPLICATE']]);
        }
        $flag = $p['flag'] === 'REFUND' ? 'NR' : 'NS';
        $seq = EinvoiceLog::where('action', 'generate')->where('ok', true)->count() + 1;
        $ofType = EinvoiceLog::where('action', 'generate')->where('ok', true)->where('request', 'like', '%"flag":"'.$p['flag'].'"%')->count() + 1;
        $h = strtoupper(hash('sha256', $p['sellerTin'].'|'.$p['invoiceNumber'].'|'.$p['totalAmount'].'|'.config('app.key')));
        $group = fn ($s) => implode('-', str_split($s, 4));
        $time = now()->format('Y-m-d H:i:s');
        $sdc = 'VSDC-'.substr(preg_replace('/\D/', '', (string) $p['sellerTin']).'0000', 0, 4).'-DEMO';
        $claims = ['sdc' => $sdc, 'num' => "{$ofType}/{$seq} {$flag}", 'inv' => $p['invoiceNumber'], 'tin' => $p['sellerTin'], 'buyer' => $p['businessPartnerTin'],
            'total' => $p['totalAmount'], 'vat' => $p['totalVat'], 'levy' => $p['totalLevy'], 'time' => $time, 'iss' => 'GRA E-VAT Sandbox'];
        $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'kid' => 'NOWILLS-VSDC-SANDBOX', 'typ' => 'JWT']));
        $body = $b64(json_encode($claims));
        $qr = $head.'.'.$body.'.'.$b64(hash_hmac('sha256', $head.'.'.$body, (string) config('app.key'), true));

        return self::mapResponse(['status' => 'SUCCESS', 'response' => ['message' => [
            'ysdcid' => $sdc, 'ysdcrecnum' => "{$ofType}/{$seq} {$flag}", 'ysdcintdata' => $group(substr($h, 0, 24)), 'ysdcregsig' => $group(substr($h, 24, 16)),
            'ysdcmrc' => 'NWS-'.substr($h, 40, 6), 'ysdcmrctim' => $time, 'ysdctime' => $time, 'flag' => $p['flag'], 'ysdcitems' => count($p['items']),
        ], 'qr_code' => $qr]]);
    }

    /** Normalise a VSDC answer ({status, response: {message: {ysdc…}, qr_code}}; GRA spells "mesaage" in some versions). */
    protected static function mapResponse(array $j, ?int $http = 200): array
    {
        $resp = $j['response'] ?? [];
        $m = is_array($resp['message'] ?? null) ? $resp['message'] : (is_array($resp['mesaage'] ?? null) ? $resp['mesaage'] : null);
        if (strtoupper((string) ($j['status'] ?? '')) === 'SUCCESS' && $m && ! empty($m['ysdcid'])) {
            return ['ok' => true, 'sdc_id' => $m['ysdcid'], 'rec_num' => $m['ysdcrecnum'] ?? '', 'intdata' => $m['ysdcintdata'] ?? null, 'signature' => $m['ysdcregsig'] ?? null,
                'time' => $m['ysdcmrctim'] ?? $m['ysdctime'] ?? now()->toDateTimeString(), 'qr' => $resp['qr_code'] ?? $j['qr_code'] ?? null, 'raw' => $j];
        }
        $msg = is_string($resp['message'] ?? null) ? $resp['message'] : ($j['message'] ?? ('E-VAT returned HTTP '.$http));

        return ['ok' => false, 'code' => (string) ($j['code'] ?? $resp['code'] ?? ('HTTP'.$http)), 'message' => (string) $msg,
            'retry' => $http >= 500 || in_array($http, [401, 408, 429]), 'raw' => $j];
    }

    protected static function graSubmit(array $payload): array
    {
        $url = trim((string) setting('gst_evat_url', ''));
        if (! $url) {
            throw new RuntimeException('GRA E-VAT API URL is not configured (Billing › GRA VAT › Settings).');
        }
        IcalService::assertSafeUrl($url);
        $res = Http::timeout(30)->acceptJson()->withHeaders(['security_key' => (string) setting('gst_evat_key', '')])->post($url, $payload);

        return self::mapResponse($res->json() ?? [], $res->status());
    }

    /** Decoded QR claims (for display / verification). */
    public static function qrClaims(?string $qr): array
    {
        $parts = explode('.', (string) $qr);
        if (count($parts) !== 3) {
            return [];
        }

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [];
    }

    public static function qrSvg(string $data): string
    {
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::L, 'addQuietzone' => true]);

        return (new \chillerlan\QRCode\QRCode($opts))->render($data);
    }
}
