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
        return DB::transaction(function () use ($folio, $category, $description, $amount, $applyTax, $extra) {
            // Serialize charge posting with settlement/close so an opening-only
            // folio cannot be closed without its newly posted charges being
            // included in the decision to issue (or not issue) a tax invoice.
            $folio = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
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
        });
    }

    /** $lockedMode, if supplied, must be validated and locked in the caller's transaction. */
    public static function payment(Folio $folio, float $amount, int $paymentModeId, ?string $reference = null, string $category = 'settlement', string $type = 'payment', ?array $cheque = null, ?PaymentMode $lockedMode = null): FolioTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        ShiftService::assertOpen();

        return DB::transaction(function () use ($folio, $amount, $paymentModeId, $reference, $category, $type, $cheque, $lockedMode) {
            // Payments and refunds must serialize with checkout, folio close,
            // and other desk clicks. Without the row lock, two requests can
            // both validate a stale open folio and post against it.
            $folio = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            self::assertOpen($folio);
            // Checkout may pass the mode it just validated and locked. Re-querying
            // its type with a non-locking read could see an older MySQL snapshot.
            if ($lockedMode && ((int) $lockedMode->id !== $paymentModeId || (int) $lockedMode->hotel_id !== (int) $folio->hotel_id)) {
                throw new RuntimeException('Payment mode does not match the folio.');
            }
            $voucher = $lockedMode ? $lockedMode->type === 'gift_voucher' : GiftVoucherService::isVoucherMode($paymentModeId);
            if ($voucher) {
                return GiftVoucherService::folioPayment($folio, $amount, $reference, $category, $type, $lockedMode ? $paymentModeId : null); // the reference is the voucher code
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
            if ($lockedMode ? $lockedMode->type === 'cheque' : PaymentMode::whereKey($paymentModeId)->where('type', 'cheque')->exists()) {
                $attrs += [
                    'cheque_bank_name' => filled($cheque['bank_name'] ?? null) ? mb_substr(trim((string) $cheque['bank_name']), 0, 100) : null,
                    'cheque_number' => filled($cheque['number'] ?? null) ? mb_substr(trim((string) $cheque['number']), 0, 20) : null,
                    'cheque_bank_branch' => filled($cheque['bank_branch'] ?? null) ? mb_substr(trim((string) $cheque['bank_branch']), 0, 100) : null,
                ];
            }

            return $folio->transactions()->create($attrs);
        });
    }

    /** Call inside a transaction: lock the current folio before making ledger decisions. */
    public static function lockOpen(Folio $folio): Folio
    {
        $folio = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
        self::assertOpen($folio);

        return $folio;
    }

    /** With the folio already locked, use a current read rather than an older repeatable-read snapshot. */
    public static function lockedTransactions(Folio $folio): \Illuminate\Support\Collection
    {
        return $folio->transactions()->orderBy('id')->lockForUpdate()->get();
    }

    public static function discount(Folio $folio, float $amount, string $reason): FolioTransaction
    {
        return self::postDiscount($folio, $amount, $reason, false);
    }

    public static function discountPercent(Folio $folio, float $percent, string $reason): FolioTransaction
    {
        return self::postDiscount($folio, $percent, $reason, true);
    }

    protected static function postDiscount(Folio $folio, float $value, string $reason, bool $percent): FolioTransaction
    {
        return DB::transaction(function () use ($folio, $value, $reason, $percent) {
            $folio = self::lockOpen($folio);
            $amount = $percent ? round($folio->totals(self::lockedTransactions($folio))['charges'] * min(100, $value) / 100, 2) : $value;

            return $folio->transactions()->create([
                'hotel_id' => $folio->hotel_id,
                'business_date' => bdate()->toDateString(),
                'type' => 'discount', 'category' => 'misc',
                'description' => 'Discount: '.$reason,
                'amount' => round($amount, 2),
                'posted_by' => auth()->id(),
            ]);
        });
    }

    /** Transfer outstanding balance to a company (city ledger / bill-to-company). */
    public static function transferToCityLedger(Folio $folio, int $companyId, ?string $overrideReason = null): FolioTransaction
    {
        return DB::transaction(function () use ($folio, $companyId, $overrideReason) {
            $folio = self::lockOpen($folio);
            $bal = $folio->totals(self::lockedTransactions($folio))['balance'];
            if ($bal <= 0) {
                throw new RuntimeException('Nothing to transfer.');
            }
            // v2.7 credit control: direct-billing approval, credit hold and limit (hold / limit can be overridden with a reason)
            CreditService::assertCanCharge(\App\Models\Company::findOrFail($companyId), $bal, $overrideReason, $folio);

            $tx = $folio->transactions()->create([
                'hotel_id' => $folio->hotel_id,
                'business_date' => bdate()->toDateString(),
                'type' => 'payment', 'category' => 'city_ledger',
                'description' => 'Transferred to City Ledger',
                'amount' => $bal, 'company_id' => $companyId,
                'posted_by' => auth()->id(),
            ]);
            $folio->update(['company_id' => $companyId]);

            return $tx;
        });
    }

    public static function void(FolioTransaction $tx, string $reason): void
    {
        $voided = DB::transaction(function () use ($tx, $reason) {
            // Lock the folio before the line, as split/move does. Reject stale
            // requests for a line moved to a different folio rather than voiding it there.
            $folio = Folio::whereKey($tx->folio_id)->lockForUpdate()->firstOrFail();
            self::assertOpen($folio);
            $current = FolioTransaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->folio_id !== (int) $folio->id) {
                throw new RuntimeException('This transaction has moved to another folio. Refresh and try again.');
            }
            if ($current->is_void) {
                throw new RuntimeException('This transaction is already voided.');
            }
            $current->setRelation('folio', $folio);
            if ($current->source_type === 'pos_complimentary') {
                throw new RuntimeException('Complimentary information records are read-only and cannot be voided.');
            }
            if ($current->isOpening()) {
                throw new RuntimeException('Opening receivable balances are cutover records and cannot be voided as historical transactions. Correct an unused row in a new opening_ar batch before the opening TB is posted; otherwise use an approved live settlement or adjustment.');
            }
            IncomeAuditService::assertCanVoid($current); // v5.3: a day closed by the income audit is locked
            if (in_array($current->source_type, ['online_payment', 'online_refund'])) {
                throw new RuntimeException('Online payments and refunds cannot be voided – refund the payment from Billing › Online Payments instead.');
            }
            $current->update(['is_void' => true, 'void_reason' => $reason]);
            LoyaltyService::refund($current);
            GiftVoucherService::onFolioVoid($current); // v3.8: money paid with a voucher goes back on it
            PackageService::onFolioVoid($current); // v5.5: a waived night / add-on takes its unused packages with it

            return $current;
        });
        $tx->setRawAttributes($voided->getAttributes(), true);
    }

    /** Settle and close the folio, generating a tax invoice number. */
    public static function close(Folio $folio): Folio
    {
        $openingOnly = false;
        $closedFolio = DB::transaction(function () use ($folio, &$openingOnly) {
            $folio = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            self::assertOpen($folio);
            $lines = self::lockedTransactions($folio);
            $openingOnly = $lines->contains(fn ($line) => $line->source_type === 'opening_ar' && $line->type === 'opening_balance')
                && ! $lines->contains(fn ($line) => ! $line->is_void && $line->type === 'charge');
            $balance = $folio->totals($lines)['balance'];
            if (abs($balance) > 0.009) {
                throw new RuntimeException('Folio balance must be zero before closing. Balance: '.money($balance));
            }
            $folio->update([
                'status' => 'closed',
                'invoice_no' => $openingOnly ? null : ($folio->invoice_no ?: Numbering::next('invoice')),
                'closed_at' => now(),
                'closed_by' => auth()->id(),
            ]);
            if (! $openingOnly) {
                LoyaltyService::earn($folio);
                if (! $folio->isCreditNote()) {
                    // Snapshot the buyer and date inside the same transaction; the
                    // external E-VAT submission is deferred until its outer commit.
                    GstService::onClose($folio);
                }
            }

            return $folio;
        });
        $folio->setRawAttributes($closedFolio->getAttributes(), true);
        return $folio;
    }
}
