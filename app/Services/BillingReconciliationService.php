<?php

namespace App\Services;

use App\Models\BillingReconciliation;
use App\Models\BillingReconciliationItem;
use App\Models\Folio;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Non-posting working paper for issued folio invoices and credit notes.
 *
 * It snapshots bill values and the receipts/refunds recorded on each folio;
 * settlement proof is entered by Finance and never creates or alters a GL entry.
 * Company-ledger transfers are shown separately from cash receipts.
 */
class BillingReconciliationService
{
    public const REVIEW_STATUSES = [
        'pending' => 'Pending review',
        'matched' => 'Matched to evidence',
        'exception' => 'Exception noted',
        'not_applicable' => 'No cash settlement',
    ];

    public static function create(string $from, string $to, User $user): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        if ($to < $from) {
            throw new RuntimeException('The period end must be on or after the period start.');
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw new RuntimeException('A single workpaper can cover at most 367 calendar days.');
        }

        $existing = BillingReconciliation::whereDate('period_from', $from)->whereDate('period_to', $to)->first();
        if ($existing) {
            return [$existing, false, ['added' => 0, 'updated' => 0, 'unchanged' => $existing->items()->count(), 'skipped_finalised' => 0]];
        }

        $reconciliation = DB::transaction(function () use ($from, $to, $user) {
            return BillingReconciliation::create([
                'reconciliation_no' => Numbering::next('billing_reconciliation'),
                'period_from' => $from,
                'period_to' => $to,
                'status' => 'draft',
                'prepared_by' => $user->id,
            ]);
        });
        $counts = self::refresh($reconciliation);
        ActivityLogger::log('billing_reconciliation', 'create', "Created {$reconciliation->reconciliation_no} for {$reconciliation->label()}.", $reconciliation);

        return [$reconciliation, true, $counts];
    }

    /**
     * Add newly issued documents and refresh changed source snapshots on a draft.
     * Reviewed lines are reset to pending when source values change; manual evidence
     * is retained so a reviewer can compare it with the updated source.
     */
    public static function refresh(BillingReconciliation $reconciliation): array
    {
        return DB::transaction(function () use ($reconciliation) {
            $reconciliation = BillingReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            self::assertDraft($reconciliation);

            $from = $reconciliation->period_from->toDateString();
            $to = $reconciliation->period_to->toDateString();
            $docs = Folio::with(['guest', 'company', 'original', 'transactions'])
                ->where('status', 'closed')->whereNotNull('invoice_no')
                ->where(fn ($q) => $q->where(fn ($d) => $d->whereDate('invoice_date', '>=', $from)->whereDate('invoice_date', '<=', $to))
                    ->orWhere(fn ($d) => $d->whereNull('invoice_date')->whereDate('closed_at', '>=', $from)->whereDate('closed_at', '<=', $to)))
                ->orderByRaw('COALESCE(invoice_date, DATE(closed_at))')->orderBy('id')->get();
            $items = $reconciliation->items()->with('folio')->get()->keyBy('folio_id');
            $seen = [];
            $counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_finalised' => 0];

            foreach ($docs as $folio) {
                $seen[$folio->id] = true;
                $snapshot = self::snapshot($folio);
                $hash = self::sourceHash($folio);
                $existing = $items->get($folio->id);
                if (! $existing) {
                    $alreadyFinalised = BillingReconciliationItem::where('folio_id', $folio->id)
                        ->where('billing_reconciliation_id', '!=', $reconciliation->id)
                        ->whereHas('reconciliation', fn ($q) => $q->where('status', 'finalised'))
                        ->exists();
                    if ($alreadyFinalised) {
                        $counts['skipped_finalised']++;
                        continue;
                    }
                    $reconciliation->items()->create($snapshot + [
                        'source_hash' => $hash,
                        'source_status' => 'issued',
                        'review_status' => 'pending',
                        'snapshot_at' => now(),
                    ]);
                    $counts['added']++;
                    continue;
                }

                $sourceStatus = self::sourceStatus($folio, $reconciliation);
                if (! hash_equals((string) $existing->source_hash, $hash) || $existing->source_status !== $sourceStatus) {
                    $existing->forceFill($snapshot + [
                        'source_hash' => $hash,
                        'source_status' => $sourceStatus,
                        'source_changed_at' => now(),
                        'review_status' => 'pending',
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                    ])->save();
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }
            }

            // Keep formerly issued lines in the working paper if a document was
            // cancelled or moved outside the selected period after the snapshot.
            foreach ($items as $folioId => $item) {
                if (isset($seen[$folioId])) {
                    continue;
                }
                $folio = $item->folio;
                $sourceStatus = $folio ? self::sourceStatus($folio, $reconciliation) : 'missing';
                $currentHash = $folio ? self::sourceHash($folio->loadMissing('transactions')) : null;
                if ($item->source_status !== $sourceStatus || ($currentHash && ! hash_equals((string) $item->source_hash, $currentHash))) {
                    $item->forceFill([
                        'source_status' => $sourceStatus,
                        'source_hash' => $currentHash ?? $item->source_hash,
                        'source_changed_at' => now(),
                        'review_status' => 'pending',
                        'reviewed_by' => null,
                        'reviewed_at' => null,
                    ])->save();
                    $counts['updated']++;
                }
            }

            $reconciliation->forceFill(['refreshed_at' => now()])->save();

            return $counts;
        });
    }

    /** Current source-value signature; used to prevent finalising stale snapshots. */
    public static function sourceHash(Folio $folio): string
    {
        $folio->loadMissing('guest', 'company', 'original');
        $transactions = $folio->relationLoaded('transactions')
            ? $folio->transactions
            : $folio->transactions()->orderBy('id')->get();
        $rows = $transactions->sortBy('id')->map(fn ($t) => [
            'id' => (int) $t->id,
            'type' => (string) $t->type,
            'category' => (string) $t->category,
            'business_date' => $t->business_date?->toDateString(),
            'reference' => (string) ($t->reference ?? ''),
            'amount' => number_format((float) $t->amount, 2, '.', ''),
            'tax_amount' => number_format((float) $t->tax_amount, 2, '.', ''),
            'is_void' => (bool) $t->is_void,
        ])->values()->all();
        $payload = [
            'id' => (int) $folio->id,
            'folio_no' => (string) ($folio->folio_no ?? ''),
            'status' => (string) $folio->status,
            'type' => (string) $folio->type,
            'invoice_no' => (string) ($folio->invoice_no ?? ''),
            'invoice_date' => $folio->invoice_date?->toDateString(),
            'closed_at' => $folio->closed_at?->toDateString(),
            'buyer_name' => (string) ($folio->buyer_name ?? ''),
            'company_id' => (int) ($folio->company_id ?? 0),
            'company_name' => (string) ($folio->company?->name ?? ''),
            'guest_id' => (int) ($folio->guest_id ?? 0),
            'guest_name' => (string) ($folio->guest?->full_name ?? ''),
            'original_folio_id' => (int) ($folio->original_folio_id ?? 0),
            'original_invoice_no' => (string) ($folio->original?->invoice_no ?? ''),
            'transactions' => $rows,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    protected static function snapshot(Folio $folio): array
    {
        $transactions = $folio->relationLoaded('transactions') ? $folio->transactions : $folio->transactions()->get();
        $active = $transactions->where('is_void', false);
        $charges = $active->where('type', 'charge');
        $discounts = $active->whereIn('type', ['discount', 'allowance']);
        $documentValue = (float) $charges->sum('amount') + (float) $charges->sum('tax_amount') - (float) $discounts->sum('amount');
        $cashPayments = (float) $active->where('type', 'payment')->where('category', '!=', 'city_ledger')->sum('amount');
        $cashRefunds = (float) $active->where('type', 'refund')->sum('amount');
        $cityLedger = (float) $active->where('type', 'payment')->where('category', 'city_ledger')
            ->sum(fn ($t) => abs((float) $t->amount));
        $issuedDate = $folio->invoice_date?->toDateString() ?? $folio->closed_at?->toDateString() ?? bdate()->toDateString();
        $customer = $folio->buyer_name ?: $folio->company?->name ?: $folio->guest?->full_name ?: 'Guest / company not recorded';

        return [
            'folio_id' => $folio->id,
            'document_no' => (string) $folio->invoice_no,
            'folio_no' => (string) $folio->folio_no,
            'document_type' => $folio->isCreditNote() ? 'credit_note' : 'invoice',
            'document_date' => $issuedDate,
            'original_invoice_no' => $folio->original?->invoice_no,
            'customer_name' => mb_substr((string) $customer, 0, 190),
            'document_amount' => round(abs($documentValue), 2),
            'cash_payments' => round(max(0, $cashPayments), 2),
            'cash_refunds' => round(max(0, $cashRefunds), 2),
            'city_ledger_amount' => round($cityLedger, 2),
        ];
    }

    protected static function sourceStatus(Folio $folio, BillingReconciliation $reconciliation): string
    {
        if ($folio->status !== 'closed') {
            return $folio->status === 'cancelled' ? 'cancelled' : 'not_issued';
        }
        if (! $folio->invoice_no) {
            return 'not_issued';
        }
        $date = $folio->invoice_date?->toDateString() ?? $folio->closed_at?->toDateString() ?? '';
        if ($date < $reconciliation->period_from->toDateString() || $date > $reconciliation->period_to->toDateString()) {
            return 'out_of_period';
        }

        return 'issued';
    }

    public static function reviewItem(
        BillingReconciliation $reconciliation,
        BillingReconciliationItem $item,
        array $data,
        User $user,
        ?UploadedFile $evidence = null,
    ): void {
        $reconciliation = BillingReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
        self::assertDraft($reconciliation);
        if ((int) $item->billing_reconciliation_id !== (int) $reconciliation->id) {
            throw new RuntimeException('This document does not belong to the selected workpaper.');
        }
        $item = BillingReconciliationItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
        if ($item->currentSourceDiffers()) {
            throw new RuntimeException('The folio changed after this snapshot. Refresh the workpaper, then review it again.');
        }

        $status = (string) ($data['review_status'] ?? '');
        if (! in_array($status, ['matched', 'exception', 'not_applicable'], true)) {
            throw new RuntimeException('Choose Matched, Exception noted or No cash settlement.');
        }
        $settledPayments = self::optionalAmount($data['settled_payments'] ?? null);
        $settledRefunds = self::optionalAmount($data['settled_refunds'] ?? null);
        $reference = trim((string) ($data['settlement_reference'] ?? ''));
        $note = trim((string) ($data['review_note'] ?? ''));
        $hasCash = $item->hasCashMovement();

        if ($item->source_status !== 'issued' && $status !== 'exception') {
            throw new RuntimeException('A cancelled, missing or out-of-period document must be recorded as an exception.');
        }
        if ($status === 'matched') {
            if (! $hasCash) {
                throw new RuntimeException('This document has no folio cash receipt or refund to match. Use No cash settlement or record an exception.');
            }
            if (abs((float) ($settledPayments ?? 0) - (float) $item->cash_payments) > 0.01
                || abs((float) ($settledRefunds ?? 0) - (float) $item->cash_refunds) > 0.01) {
                throw new RuntimeException('A matched item must evidence the full folio payment and refund totals. Use Exception noted for a partial or different settlement.');
            }
            if ($reference === '' && ! $evidence && ! $item->evidence_path) {
                throw new RuntimeException('Add a settlement reference or upload evidence before marking this document matched.');
            }
        } elseif ($status === 'exception') {
            if ($note === '') {
                throw new RuntimeException('Describe the settlement or document exception.');
            }
        } else {
            if ($hasCash) {
                throw new RuntimeException('This document has cash payments or refunds; it cannot be marked No cash settlement.');
            }
            if ((float) $item->city_ledger_amount <= 0.005 && (float) $item->document_amount > 0.005) {
                throw new RuntimeException('No cash movement is recorded, and this document has no city-ledger transfer. Record an exception instead.');
            }
            if ($note === '') {
                throw new RuntimeException('Explain why cash settlement is not applicable (for example, a company-account transfer).');
            }
        }

        $oldPath = $item->evidence_path;
        $newPath = $evidence ? $evidence->store('billing-reconciliations/'.$reconciliation->id, 'local') : null;
        $evidenceName = $evidence ? self::safeEvidenceName($evidence->getClientOriginalName()) : $item->evidence_name;
        try {
            $item->forceFill([
                'settled_payments' => $settledPayments,
                'settled_refunds' => $settledRefunds,
                'settlement_reference' => $reference !== '' ? mb_substr($reference, 0, 160) : null,
                'evidence_path' => $newPath ?: $oldPath,
                'evidence_name' => $evidenceName,
                'review_status' => $status,
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $e;
        }
        if ($newPath && $oldPath && $newPath !== $oldPath) {
            Storage::disk('local')->delete($oldPath);
        }
        ActivityLogger::log('billing_reconciliation', 'review_item', "{$item->document_no} marked {$status} in {$reconciliation->reconciliation_no}.", $item,
            ['review_status' => $status, 'settlement_reference' => $reference !== '' ? $reference : null]);
    }

    protected static function optionalAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $amount = round((float) $value, 2);
        if (! is_finite($amount) || $amount < 0 || $amount > 9999999999.99) {
            throw new RuntimeException('Settlement amounts must be zero or greater and within the supported range.');
        }

        return $amount;
    }

    protected static function safeEvidenceName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]/u', '_', $name) ?: 'settlement-evidence';

        return mb_substr($name, 0, 190);
    }

    public static function finalise(BillingReconciliation $reconciliation, User $user): void
    {
        DB::transaction(function () use ($reconciliation, $user) {
            $reconciliation = BillingReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            self::assertDraft($reconciliation);
            if ((int) $reconciliation->prepared_by === (int) $user->id) {
                throw new RuntimeException('Four-eyes control: the preparer cannot finalise their own billing reconciliation.');
            }
            if ($reconciliation->reviewed_by && (int) $reconciliation->reviewed_by === (int) $user->id) {
                throw new RuntimeException('A different approver must finalise a reopened reconciliation.');
            }

            $items = $reconciliation->items()->with('folio')->get();
            if ($items->contains(fn ($item) => $item->review_status === 'pending')) {
                throw new RuntimeException('Review every document before finalising the workpaper.');
            }
            foreach ($items as $item) {
                if ($item->currentSourceDiffers()) {
                    throw new RuntimeException("{$item->document_no} changed after its snapshot. Refresh and review the workpaper before finalising.");
                }
                if ($item->review_status === 'matched') {
                    if ($item->source_status !== 'issued' || ! $item->hasCashMovement()
                        || abs((float) ($item->settled_payments ?? 0) - (float) $item->cash_payments) > 0.01
                        || abs((float) ($item->settled_refunds ?? 0) - (float) $item->cash_refunds) > 0.01
                        || (! $item->settlement_reference && ! $item->evidence_path)) {
                        throw new RuntimeException("{$item->document_no} does not satisfy the matched-evidence checks.");
                    }
                } elseif ($item->review_status === 'exception' && ! trim((string) $item->review_note)) {
                    throw new RuntimeException("{$item->document_no} needs an exception note.");
                } elseif ($item->review_status === 'not_applicable'
                    && (trim((string) $item->review_note) === '' || $item->hasCashMovement()
                        || ((float) $item->city_ledger_amount <= 0.005 && (float) $item->document_amount > 0.005))) {
                    throw new RuntimeException("{$item->document_no} does not satisfy the no-cash-settlement checks.");
                }
            }

            $duplicate = BillingReconciliationItem::whereIn('folio_id', $items->pluck('folio_id'))
                ->where('billing_reconciliation_id', '!=', $reconciliation->id)
                ->whereHas('reconciliation', fn ($q) => $q->where('status', 'finalised'))
                ->exists();
            if ($duplicate) {
                throw new RuntimeException('One or more folio documents are already in another finalised workpaper.');
            }

            $reconciliation->forceFill(['status' => 'finalised', 'reviewed_by' => $user->id, 'finalised_at' => now()])->save();
            ActivityLogger::log('billing_reconciliation', 'finalise', "Finalised {$reconciliation->reconciliation_no}.", $reconciliation);
        });
    }

    public static function reopen(BillingReconciliation $reconciliation, string $reason, User $user): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Enter why this finalised workpaper must be reopened.');
        }
        DB::transaction(function () use ($reconciliation, $reason, $user) {
            $reconciliation = BillingReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            if (! $reconciliation->isFinalised()) {
                throw new RuntimeException('Only a finalised billing reconciliation can be reopened.');
            }
            if ((int) $reconciliation->reviewed_by === (int) $user->id) {
                throw new RuntimeException('Four-eyes control: the approver cannot reopen their own finalised workpaper.');
            }
            $reconciliation->forceFill([
                'status' => 'draft',
                'reopened_by' => $user->id,
                'reopened_at' => now(),
                'reopen_reason' => mb_substr($reason, 0, 2000),
            ])->save();
            ActivityLogger::log('billing_reconciliation', 'reopen', "Reopened {$reconciliation->reconciliation_no}: {$reason}", $reconciliation);
        });
    }

    protected static function assertDraft(BillingReconciliation $reconciliation): void
    {
        if (! $reconciliation->exists || $reconciliation->status !== 'draft') {
            throw new RuntimeException('This workpaper is finalised and read-only. Reopen it first.');
        }
    }
}
