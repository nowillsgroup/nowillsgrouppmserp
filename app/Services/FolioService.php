<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\PaymentMode;
use App\Models\ReservationRoom;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guest ledger operations. All monetary changes go through here to keep the
 * folio audit trail consistent (void instead of delete, business-dated postings).
 */
class FolioService
{
    public static function openForStay(ReservationRoom $stay): Folio
    {
        if ($stay->folio) {
            return $stay->folio;
        }
        $res = $stay->reservation;

        return Folio::create([
            'folio_no' => Numbering::next('folio'),
            'reservation_id' => $res->id,
            'reservation_room_id' => $stay->id,
            'guest_id' => $stay->guest_id ?? $res->guest_id,
            'company_id' => $res->billing_instruction === 'company' ? $res->company_id : null,
            'type' => 'guest',
            'status' => 'open',
        ]);
    }

    public static function openStandalone(array $data): Folio
    {
        return Folio::create(array_merge([
            'folio_no' => Numbering::next('folio'),
            'type' => 'walk_in',
            'status' => 'open',
        ], $data));
    }

    protected static function assertOpen(Folio $folio): void
    {
        if ($folio->status !== 'open') {
            throw new RuntimeException('Folio '.$folio->folio_no.' is closed.');
        }
    }

    public static function charge(Folio $folio, string $category, string $description, float $amount, ?bool $applyTax = true, array $extra = []): FolioTransaction
    {
        self::assertOpen($folio);
        $tax = $applyTax ? TaxService::forCategory($category, $amount) : ['rate' => 0, 'amount' => 0];
        if (isset($extra['tax_rate'])) {
            $tax = ['rate' => $extra['tax_rate'], 'amount' => round($amount * $extra['tax_rate'] / 100, 2)];
            unset($extra['tax_rate']);
        }

        return $folio->transactions()->create(array_merge([
            'hotel_id' => $folio->hotel_id,
            'business_date' => bdate()->toDateString(),
            'type' => 'charge',
            'category' => $category,
            'description' => $description,
            'amount' => round($amount, 2),
            'tax_amount' => $tax['amount'],
            'tax_rate' => $tax['rate'],
            'posted_by' => auth()->id(),
        ], $extra));
    }

    public static function payment(Folio $folio, float $amount, int $paymentModeId, ?string $reference = null, string $category = 'settlement', string $type = 'payment', ?array $cheque = null): FolioTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        ShiftService::assertOpen();

        return DB::transaction(function () use ($folio, $amount, $paymentModeId, $reference, $category, $type, $cheque) {
            // Payments and refunds must serialize with checkout, folio close,
            // and other desk clicks. Without the row lock, two requests can
            // both validate a stale open folio and post against it.
            $folio = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            self::assertOpen($folio);
            if (GiftVoucherService::isVoucherMode($paymentModeId)) {
                return GiftVoucherService::folioPayment($folio, $amount, $reference, $category, $type); // v3.8: the reference is the voucher code
            }

            $attrs = [
                'hotel_id' => $folio->hotel_id,
                'business_date' => bdate()->toDateString(),
                'type' => $type,
                'category' => $category,
                'description' => $type === 'refund' ? 'Refund' : ($category === 'advance' ? 'Advance Received' : 'Payment Received'),
                'amount' => round($amount, 2),
                'payment_mode_id' => $paymentModeId,
                'reference' => $reference,
                'posted_by' => auth()->id(),
            ];
            if (PaymentMode::whereKey($paymentModeId)->where('type', 'cheque')->exists()) {
                $attrs += [
                    'cheque_bank_name' => filled($cheque['bank_name'] ?? null) ? mb_substr(trim((string) $cheque['bank_name']), 0, 100) : null,
                    'cheque_number' => filled($cheque['number'] ?? null) ? mb_substr(trim((string) $cheque['number']), 0, 20) : null,
                    'cheque_bank_branch' => filled($cheque['bank_branch'] ?? null) ? mb_substr(trim((string) $cheque['bank_branch']), 0, 100) : null,
                ];
            }

            return $folio->transactions()->create($attrs);
        });
    }

    public static function discount(Folio $folio, float $amount, string $reason): FolioTransaction
    {
        self::assertOpen($folio);

        return $folio->transactions()->create([
            'hotel_id' => $folio->hotel_id,
            'business_date' => bdate()->toDateString(),
            'type' => 'discount', 'category' => 'misc',
            'description' => 'Discount: '.$reason,
            'amount' => round($amount, 2),
            'posted_by' => auth()->id(),
        ]);
    }

    /** Transfer outstanding balance to a company (city ledger / bill-to-company). */
    public static function transferToCityLedger(Folio $folio, int $companyId, ?string $overrideReason = null): FolioTransaction
    {
        self::assertOpen($folio);
        $bal = $folio->balance();
        if ($bal <= 0) {
            throw new RuntimeException('Nothing to transfer.');
        }
        // v2.7 credit control: direct-billing approval, credit hold and limit (hold / limit can be overridden with a reason)
        CreditService::assertCanCharge(\App\Models\Company::findOrFail($companyId), $bal, $overrideReason, $folio);

        return $folio->transactions()->create([
            'hotel_id' => $folio->hotel_id,
            'business_date' => bdate()->toDateString(),
            'type' => 'payment', 'category' => 'city_ledger',
            'description' => 'Transferred to City Ledger',
            'amount' => $bal, 'company_id' => $companyId,
            'posted_by' => auth()->id(),
        ]);
    }

    public static function void(FolioTransaction $tx, string $reason): void
    {
        self::assertOpen($tx->folio);
        IncomeAuditService::assertCanVoid($tx); // v5.3: a day closed by the income audit is locked
        if (in_array($tx->source_type, ['online_payment', 'online_refund'])) {
            throw new RuntimeException('Online payments and refunds cannot be voided – refund the payment from Billing › Online Payments instead.');
        }
        DB::transaction(function () use ($tx, $reason) {
            $tx->update(['is_void' => true, 'void_reason' => $reason]);
            LoyaltyService::refund($tx);
            GiftVoucherService::onFolioVoid($tx->fresh()); // v3.8: money paid with a voucher goes back on it
            PackageService::onFolioVoid($tx); // v5.5: a waived night / add-on takes its unused packages with it
        });
    }

    /** Settle and close the folio, generating a tax invoice number. */
    public static function close(Folio $folio): Folio
    {
        DB::transaction(function () use ($folio) {
            if (abs($folio->balance()) > 0.009) {
                throw new RuntimeException('Folio balance must be zero before closing. Balance: '.money($folio->balance()));
            }
            $folio->update([
                'status' => 'closed',
                'invoice_no' => $folio->invoice_no ?: Numbering::next('invoice'),
                'closed_at' => now(),
                'closed_by' => auth()->id(),
            ]);
            LoyaltyService::earn($folio);

            return $folio;
        });
        if (! $folio->isCreditNote()) {
            GstService::onClose($folio); // v3.0: invoice date, buyer snapshot, E-VAT stamp (outside the transaction – may call the GRA VSDC)
        }

        return $folio;
    }
}
