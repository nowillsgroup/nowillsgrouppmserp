<?php

/**
 * Rollback-only verification for the folio billing-reconciliation workpaper.
 * Run after migration and `pms:upgrade`:
 *   TINKER='require "/app/deploy/dev/audit/billing_reconciliation.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\BillingReconciliation;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GlJournal;
use App\Models\User;
use App\Services\BillingReconciliationService as Reconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$checks = 0;
$fails = [];
$check = function (string $label, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    if (! $ok) {
        $fails[] = $label;
    }
};
$throws = function (callable $fn, string $label) use ($check): void {
    $failed = false;
    try {
        $fn();
    } catch (RuntimeException $e) {
        $failed = true;
    }
    $check($label, $failed);
};

DB::beginTransaction();
try {
    $preparer = User::query()->orderBy('id')->first();
    $approver = $preparer ? User::query()->whereKeyNot($preparer->id)->orderBy('id')->first() : null;
    $check('Two distinct users are available for four-eyes verification', $preparer !== null && $approver !== null);
    if (! $preparer || ! $approver) {
        throw new RuntimeException('The rollback harness needs two seeded users.');
    }

    $beforeJournals = GlJournal::count();
    $suffix = strtoupper(Str::random(10));
    $makeFolio = function (array $attrs) use ($suffix): Folio {
        return Folio::create($attrs + [
            'folio_no' => 'T-BRC-'.$suffix.'-'.Str::random(4),
            'type' => 'guest', 'status' => 'closed',
            'invoice_no' => 'TINV-'.$suffix.'-'.Str::random(4),
            'invoice_date' => '2099-01-10', 'closed_at' => '2099-01-10 12:00:00',
        ]);
    };
    $makeTx = function (Folio $folio, string $type, string $category, float $amount, string $date = '2099-01-10'): FolioTransaction {
        return $folio->transactions()->create([
            'business_date' => $date, 'type' => $type, 'category' => $category,
            'description' => 'Billing reconciliation fixture', 'amount' => $amount,
            'tax_amount' => 0, 'tax_rate' => 0, 'reference' => 'BRC fixture',
        ]);
    };

    $invoice = $makeFolio([]);
    $makeTx($invoice, 'charge', 'room', 100);
    $makeTx($invoice, 'payment', 'settlement', 100);

    $credit = $makeFolio(['type' => 'credit_note', 'invoice_date' => '2099-01-11', 'original_folio_id' => $invoice->id]);
    $makeTx($credit, 'charge', 'room', -20, '2099-01-11');
    $makeTx($credit, 'refund', 'settlement', 20, '2099-01-11');

    $companyInvoice = $makeFolio(['invoice_date' => '2099-01-12']);
    $makeTx($companyInvoice, 'charge', 'room', 50, '2099-01-12');
    $companyInvoice->transactions()->create([
        'business_date' => '2099-01-12', 'type' => 'payment', 'category' => 'city_ledger',
        'description' => 'Transferred to company account', 'amount' => 50, 'tax_amount' => 0,
        'tax_rate' => 0, 'reference' => 'BRC company fixture',
    ]);

    $cancelledInvoice = $makeFolio(['invoice_date' => '2099-01-13']);
    $makeTx($cancelledInvoice, 'charge', 'room', 30, '2099-01-13');
    $makeTx($cancelledInvoice, 'payment', 'settlement', 30, '2099-01-13');

    $fallbackInvoice = $makeFolio(['invoice_date' => null, 'closed_at' => '2099-01-14 14:30:00']);
    $makeTx($fallbackInvoice, 'charge', 'room', 10, '2099-01-14');
    $makeTx($fallbackInvoice, 'payment', 'settlement', 10, '2099-01-14');

    [$reconciliation, $created, $counts] = Reconciliation::create('2099-01-01', '2099-01-31', $preparer);
    $check('A new period workpaper is created', $created && $reconciliation->status === 'draft');
    $check('Invoices, credit note and company-ledger document are snapshotted', $counts['added'] === 5);

    $items = $reconciliation->items()->get()->keyBy('folio_id');
    $invoiceItem = $items->get($invoice->id);
    $creditItem = $items->get($credit->id);
    $companyItem = $items->get($companyInvoice->id);
    $cancelledItem = $items->get($cancelledInvoice->id);
    $fallbackItem = $items->get($fallbackInvoice->id);
    $check('Invoice amount and folio payment are captured', $invoiceItem && (float) $invoiceItem->document_amount === 100.0 && (float) $invoiceItem->cash_payments === 100.0);
    $check('Credit note amount and refund are captured', $creditItem && $creditItem->document_type === 'credit_note' && (float) $creditItem->document_amount === 20.0 && (float) $creditItem->cash_refunds === 20.0);
    $check('Company-ledger transfer is kept separate from cash', $companyItem && (float) $companyItem->cash_payments === 0.0 && (float) $companyItem->city_ledger_amount === 50.0);
    $check('A missing invoice date falls back to the folio closed date', $fallbackItem && $fallbackItem->document_date?->toDateString() === '2099-01-14');

    $refresh = Reconciliation::refresh($reconciliation);
    $check('Refreshing unchanged sources is idempotent', $refresh['added'] === 0 && $refresh['updated'] === 0 && $refresh['unchanged'] === 5);

    $cancelledInvoice->forceFill(['status' => 'cancelled'])->save();
    $changed = Reconciliation::refresh($reconciliation);
    $items = $reconciliation->items()->get()->keyBy('folio_id');
    $cancelledItem = $items->get($cancelledInvoice->id);
    $check('Cancelled source is retained as a changed exception line', $changed['updated'] === 1 && $cancelledItem->source_status === 'cancelled' && ! $cancelledItem->currentSourceDiffers());
    $invoiceItem = $items->get($invoice->id);
    $creditItem = $items->get($credit->id);
    $companyItem = $items->get($companyInvoice->id);
    $fallbackItem = $items->get($fallbackInvoice->id);

    Reconciliation::reviewItem($reconciliation, $invoiceItem, [
        'review_status' => 'matched', 'settled_payments' => 100, 'settled_refunds' => 0,
        'settlement_reference' => 'BRC-TEST-BANK-01', 'review_note' => '',
    ], $preparer);
    Reconciliation::reviewItem($reconciliation, $fallbackItem, [
        'review_status' => 'matched', 'settled_payments' => 10, 'settled_refunds' => 0,
        'settlement_reference' => 'BRC-TEST-FALLBACK-01', 'review_note' => '',
    ], $preparer);
    Reconciliation::reviewItem($reconciliation, $creditItem, [
        'review_status' => 'matched', 'settled_payments' => 0, 'settled_refunds' => 20,
        'settlement_reference' => 'BRC-TEST-REFUND-01', 'review_note' => '',
    ], $preparer);
    Reconciliation::reviewItem($reconciliation, $companyItem, [
        'review_status' => 'not_applicable', 'settled_payments' => null, 'settled_refunds' => null,
        'settlement_reference' => '', 'review_note' => 'Company-account transfer; not a cash receipt.',
    ], $preparer);
    Reconciliation::reviewItem($reconciliation, $cancelledItem, [
        'review_status' => 'exception', 'settled_payments' => null, 'settled_refunds' => null,
        'settlement_reference' => '', 'review_note' => 'Source invoice was cancelled after the initial snapshot.',
    ], $preparer);
    $reconciliation->setRelation('items', $reconciliation->items()->get());
    $totals = $reconciliation->totals();
    $check('Net billings, cash movements, company ledger and evidence calculate separately',
        $totals['net_billed'] === 170.0 && $totals['net_cash'] === 120.0 && $totals['city_ledger_net'] === 50.0
        && $totals['net_evidenced'] === 90.0 && $totals['net_cash_variance'] === 30.0);

    $throws(fn () => Reconciliation::finalise($reconciliation, $preparer), 'The preparer cannot finalise their own workpaper');
    Reconciliation::finalise($reconciliation, $approver);
    $check('A different user can finalise a fully reviewed workpaper', $reconciliation->fresh()->status === 'finalised');
    $throws(fn () => Reconciliation::reopen($reconciliation, 'same reviewer', $approver), 'The final reviewer cannot reopen their own workpaper');
    Reconciliation::reopen($reconciliation, 'Rollback-only verification complete.', $preparer);
    $check('A different user can reopen with a reason', $reconciliation->fresh()->status === 'draft');
    $check('No general-ledger journal was created', GlJournal::count() === $beforeJournals);

    echo 'Billing reconciliation checks: '.$checks.'; passed '.($checks - count($fails)).'; failed '.count($fails)."\n";
    foreach ($fails as $failure) {
        echo 'FAIL: '.$failure."\n";
    }
    if ($fails) {
        throw new RuntimeException('Billing reconciliation checks failed.');
    }
} finally {
    DB::rollBack();
}
