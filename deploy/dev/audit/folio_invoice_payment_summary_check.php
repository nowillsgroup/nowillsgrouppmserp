<?php

// Render an issued folio invoice and check payment, refund, reference and balance presentation without database writes.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_invoice_payment_summary_check.php';"

use App\Models\Folio;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\GstService;
use App\Support\Tenant;
use Illuminate\Support\ViewErrorBag;

Tenant::set(Hotel::findOrFail(1));
$folio = Folio::with([
    'guest', 'company', 'reservation.guest', 'reservation.company', 'stay.room', 'stay.roomType', 'stay.guest',
    'stay.reservation.guest', 'stay.reservation.company', 'original',
])->findOrFail(1);
$transactions = $folio->activeTransactions()->with('paymentMode')->orderBy('business_date')->orderBy('id')->get();
$totals = $folio->totals($transactions);
$lines = GstService::lines($folio);
$gst = $folio->gstTotals();
$expectedBalance = round((float) $gst['value'] + (float) $totals['opening'] - (float) $totals['payments'] + (float) $totals['refunds'], 2);
if (abs($expectedBalance - (float) $totals['balance']) > 0.009) {
    throw new RuntimeException('The folio balance does not reconcile to invoice value, opening balance, payments and refunds.');
}
$currentHotel = hotel();
$html = view('folios.invoice', [
    'folio' => $folio,
    'tx' => $transactions,
    'totals' => $totals,
    'lines' => $lines,
    'gst' => $gst,
    'currentHotel' => $currentHotel,
    'errors' => new ViewErrorBag(),
])->render();

$assertContains = static function (string $needle, string $label) use ($html): void {
    if (! str_contains($html, $needle)) {
        throw new RuntimeException("The payment invoice is missing {$label}: {$needle}");
    }
};
$assertContains('Payment and balance summary', 'the payment summary');
$taxedCharge = $transactions->first(fn ($transaction) => $transaction->type === 'charge' && (float) $transaction->tax_amount !== 0.0);
if ($taxedCharge) {
    $assertContains('Tax '.money((float) $taxedCharge->tax_amount, false).' @'.(float) $taxedCharge->tax_rate.'%', 'the inline charge tax and rate');
}
$assertContains('Charge / tax', 'the combined charge-and-tax column');
$assertContains('Running balance', 'the transaction-by-transaction running balance');
$assertContains('invoice-header-balance', 'the prominent header balance');
$assertContains('Day total for', 'business-date subtotals');
$assertContains('Invoice amount in words', 'the amount-in-words line');
$assertContains('Original for recipient', 'the recipient copy designation');
$assertContains('<div class="invoice-watermark" aria-hidden="true"><span>ORIGINAL</span></div>', 'the across-page ORIGINAL watermark');
$proformaFolio = clone $folio;
$proformaFolio->invoice_no = null;
$proformaHtml = view('folios.invoice', [
    'folio' => $proformaFolio,
    'tx' => $transactions,
    'totals' => $totals,
    'lines' => $lines,
    'gst' => $gst,
    'currentHotel' => $currentHotel,
    'errors' => new ViewErrorBag(),
])->render();
if (str_contains($proformaHtml, '<div class="invoice-watermark"')) {
    throw new RuntimeException('A pro-forma invoice must not be marked as an original issued document.');
}
echo 'PASS ORIGINAL watermark appears on issued invoices but not pro-formas.'.PHP_EOL;
$assertContains('href="'.route('folios.show', $folio).'">← Back</a>', 'the stable back link to this folio');
$assertContains('Invoice value', 'the document value');
$assertContains('Payments received on this folio', 'the received-payment total');
$assertContains('Refunds issued on this folio', 'the refund total');
$assertContains(money((float) $totals['payments']), 'the recorded payment amount');
$assertContains(money((float) $totals['refunds']), 'the recorded refund amount');
$assertContains(money(abs((float) $totals['balance'])), 'the reconciled folio balance');
$assertContains('Settled', 'the zero-balance settlement state');
foreach ($transactions->groupBy(fn ($transaction) => $transaction->business_date->toDateString()) as $dayTransactions) {
    $activeDay = $dayTransactions->where('is_void', false);
    $dayDebits = $activeDay->sum(fn ($transaction) => in_array($transaction->type, ['charge', 'refund']) || $transaction->isOpening() ? (float) $transaction->total : 0);
    $dayCredits = $activeDay->sum(fn ($transaction) => in_array($transaction->type, ['payment', 'discount', 'allowance']) ? (float) $transaction->amount : 0);
    $assertContains(money($dayDebits, false), 'the daily debit subtotal');
    $assertContains(money($dayCredits, false), 'the daily credit subtotal');
    $assertContains(money(round($dayDebits - $dayCredits, 2), false), 'the daily net movement');
}
preg_match_all('/<tr class="day-total">.*?<\\/tr>/s', $html, $renderedDayRows);
$lastRenderedDay = $renderedDayRows[0] ? end($renderedDayRows[0]) : '';
preg_match_all('/<td class="num">([^<]*)<\\/td>/', $lastRenderedDay, $renderedDayAmounts);
$displayedClosingBalance = $renderedDayAmounts[1] ? end($renderedDayAmounts[1]) : null;
if ($displayedClosingBalance !== money((float) $totals['balance'], false)) {
    throw new RuntimeException('The last row running balance does not reconcile to the folio total.');
}
$paymentRecordedAt = $transactions->firstWhere('type', 'payment')?->created_at?->toIso8601String();
if ($paymentRecordedAt) {
    $assertContains($paymentRecordedAt, 'the machine-readable payment record time');
}

$renderBalanceScenario = static function (float $balance, float $payments, ?string $status = null) use ($folio, $transactions, $lines, $gst, $currentHotel): string {
    $scenarioFolio = clone $folio;
    if ($status !== null) {
        $scenarioFolio->status = $status;
    }
    $scenarioTotals = $scenarioFolio->totals($transactions);
    $scenarioTotals['balance'] = $balance;
    $scenarioTotals['payments'] = $payments;

    return view('folios.invoice', [
        'folio' => $scenarioFolio,
        'tx' => $transactions,
        'totals' => $scenarioTotals,
        'lines' => $lines,
        'gst' => $gst,
        'currentHotel' => $currentHotel,
        'errors' => new ViewErrorBag(),
    ])->render();
};
$dueHtml = $renderBalanceScenario(250.50, (float) $totals['payments']);
$creditHtml = $renderBalanceScenario(-73.25, (float) $totals['payments']);
$zeroHtml = $renderBalanceScenario(0, 0);
$cancelledHtml = $renderBalanceScenario(250.50, (float) $totals['payments'], 'cancelled');
if (! str_contains($dueHtml, 'Balance due') || ! str_contains($dueHtml, money(250.50))
    || ! str_contains($creditHtml, 'Credit balance') || ! str_contains($creditHtml, money(73.25))
    || ! str_contains($zeroHtml, 'Zero balance') || ! str_contains($zeroHtml, money(0))
    || ! str_contains($cancelledHtml, 'Balance at cancellation') || ! str_contains($cancelledHtml, 'Cancelled')) {
    throw new RuntimeException('The payment invoice has incorrect due, credit-balance or zero-balance messaging.');
}

echo 'PASS due, credit and zero-balance invoice states render clearly.'.PHP_EOL;

$paymentReference = $transactions->first(fn ($transaction) => in_array($transaction->type, ['payment', 'refund'], true) && $transaction->reference)?->reference;
if ($paymentReference) {
    $assertContains('Reference: '.$paymentReference, 'the payment/refund reference');
}

$invoiceValue = number_format(abs((float) $gst['value']), 2, '.', ',');
if (! str_contains($html, $invoiceValue)) {
    throw new RuntimeException('The payment summary does not reconcile to the tax invoice value.');
}

$paymentRow = $transactions->firstWhere('type', 'payment');
if ($paymentRow) {
    if (! str_contains($html, 'href="'.route('folios.receipt', $paymentRow).'"')
        || ! str_contains($html, 'aria-label="Open payment receipt for transaction '.$paymentRow->id.'"')) {
        throw new RuntimeException('The invoice is missing its safe, transaction-specific payment-receipt link.');
    }
    $renderReceipt = static fn ($transaction) => view('folios.receipt', [
        'tx' => $transaction,
        'folio' => $folio,
        'currentHotel' => $currentHotel,
        'errors' => new ViewErrorBag(),
    ])->render();
    $paymentReceiptHtml = $renderReceipt($paymentRow);
    foreach (['This confirms a payment recorded on the folio.', 'Payment amount', $folio->invoice_no, 'Recorded:', 'datetime="'.$paymentRow->business_date->toDateString().'"', 'href="'.route('folios.show', $folio).'">← Back</a>'] as $expectedReceiptText) {
        if (! str_contains($paymentReceiptHtml, $expectedReceiptText)) {
            throw new RuntimeException('The payment receipt is missing expected context: '.$expectedReceiptText);
        }
    }

    $refund = clone $paymentRow;
    $refund->type = 'refund';
    $refund->amount = 12.34;
    $refund->reference = 'AUDIT-REFUND-REF';
    $refund->is_void = false;
    $transactionsWithRefund = $transactions->concat([$refund]);
    $refundTotals = $folio->totals($transactionsWithRefund);
    $refundHtml = view('folios.invoice', [
        'folio' => $folio,
        'tx' => $transactionsWithRefund,
        'totals' => $refundTotals,
        'lines' => $lines,
        'gst' => $gst,
        'currentHotel' => $currentHotel,
        'errors' => new ViewErrorBag(),
    ])->render();
    if (! str_contains($refundHtml, money((float) $refundTotals['refunds']))
        || ! str_contains($refundHtml, 'Reference: AUDIT-REFUND-REF')
        || ! str_contains($refundHtml, 'aria-label="Open refund receipt for transaction '.$refund->id.'"')) {
        throw new RuntimeException('The invoice does not reconcile or identify a recorded refund.');
    }
    $refundReceiptHtml = $renderReceipt($refund);
    if (! str_contains($refundReceiptHtml, 'This confirms a refund recorded on the folio.')
        || ! str_contains($refundReceiptHtml, 'Refund amount')) {
        throw new RuntimeException('The refund receipt is missing refund-specific wording.');
    }

    $voidedPayment = clone $paymentRow;
    $voidedPayment->is_void = true;
    $transactionsWithVoid = $transactions->concat([$voidedPayment]);
    $voidedTotals = $folio->totals($transactionsWithVoid);
    $voidedHtml = view('folios.invoice', [
        'folio' => $folio,
        'tx' => $transactionsWithVoid,
        'totals' => $voidedTotals,
        'lines' => $lines,
        'gst' => $gst,
        'currentHotel' => $currentHotel,
        'errors' => new ViewErrorBag(),
    ])->render();
    if (! str_contains($voidedHtml, 'Excluded from invoice totals">VOIDED</span>')
        || ! str_contains($voidedHtml, '<tr class="void">')
        || (float) $voidedTotals['payments'] !== (float) $totals['payments']) {
        throw new RuntimeException('Voided payments are not visibly excluded from the invoice settlement totals.');
    }
    preg_match_all('/<tr class="day-total">.*?<\\/tr>/s', $html, $activeDayRows);
    preg_match_all('/<tr class="day-total">.*?<\\/tr>/s', $voidedHtml, $voidedDayRows);
    if ($activeDayRows[0] !== $voidedDayRows[0]) {
        throw new RuntimeException('A voided payment changed the business-day debit, credit or net-movement subtotal.');
    }
    $voidedReceiptHtml = $renderReceipt($voidedPayment);
    if (! str_contains($voidedReceiptHtml, 'not valid proof of payment or refund')) {
        throw new RuntimeException('A voided payment receipt is incorrectly presented as valid proof.');
    }
}

echo 'PASS invoice and payment/refund receipts distinguish active and voided transactions.'.PHP_EOL;
echo 'PASS issued invoice renders its value, payments, refunds, transaction reference, void markers and settled folio balance.'.PHP_EOL;

$reservationFolio = Folio::with([
    'guest', 'company', 'closedBy', 'stay.room', 'stay.roomType', 'stay.ratePlan', 'reservation.guest', 'reservation.company', 'reservation.rooms.room', 'reservation.rooms.roomType', 'reservation.rooms.ratePlan', 'original',
])->findOrFail(1);
$reservationTransactions = $reservationFolio->activeTransactions()->with('paymentMode')->orderBy('business_date')->orderBy('id')->get();
$reservationInvoiceHtml = view('folios.invoice', [
    'folio' => $reservationFolio,
    'tx' => $reservationTransactions,
    'totals' => $reservationFolio->totals($reservationTransactions),
    'lines' => GstService::lines($reservationFolio),
    'gst' => $reservationFolio->gstTotals(),
    'currentHotel' => $currentHotel,
    'errors' => new ViewErrorBag(),
])->render();
$reservationStay = $reservationFolio->stay ?? $reservationFolio->reservation?->rooms->firstWhere('status', '!=', 'cancelled');
$reservationInvoiceExpected = ['RES-00001', 'Reservation details', 'Invoice amount in words', 'Original for recipient'];
if ($reservationStay?->room && $reservationStay->roomType) {
    $reservationInvoiceExpected[] = 'Room '.$reservationStay->room->room_no.' · '.$reservationStay->roomType->name;
}
if ($reservationStay?->ratePlan?->name) {
    $reservationInvoiceExpected[] = 'Plan type';
    $reservationInvoiceExpected[] = $reservationStay->ratePlan->name;
}
if ($reservationFolio->closedBy) {
    $reservationInvoiceExpected[] = 'Prepared by <b>'.$reservationFolio->closedBy->name.'</b>';
}
foreach ($reservationInvoiceExpected as $reservationInvoiceText) {
    if (! str_contains($reservationInvoiceHtml, $reservationInvoiceText)) {
        throw new RuntimeException('The reservation-linked invoice is missing expected details: '.$reservationInvoiceText);
    }
}
$multiRoomReservation = Reservation::with(['rooms.room', 'rooms.roomType', 'rooms.ratePlan', 'guest', 'company'])
    ->get()
    ->first(fn ($reservation) => $reservation->rooms->where('status', '!=', 'cancelled')->count() > 1);
if ($multiRoomReservation) {
    $activeRooms = $multiRoomReservation->rooms->where('status', '!=', 'cancelled')->values();
    $multiRoomFolio = clone $reservationFolio;
    $multiRoomFolio->setRelation('reservation', $multiRoomReservation);
    $multiRoomFolio->setRelation('stay', null);
    $multiRoomHtml = view('folios.invoice', [
        'folio' => $multiRoomFolio,
        'tx' => $reservationTransactions,
        'totals' => $multiRoomFolio->totals($reservationTransactions),
        'lines' => GstService::lines($multiRoomFolio),
        'gst' => $multiRoomFolio->gstTotals(),
        'currentHotel' => $currentHotel,
        'errors' => new ViewErrorBag(),
    ])->render();
    $roomLabels = $activeRooms->map(fn ($stay) => trim(($stay->room?->room_no ? 'Room '.$stay->room->room_no : 'Room unassigned').' · '.($stay->roomType?->name ?? 'Room stay')))->countBy();
    foreach ($roomLabels as $label => $count) {
        $expectedRoomLabel = ($count > 1 ? $count.' × ' : '').$label;
        if (! str_contains($multiRoomHtml, e($expectedRoomLabel))) {
            throw new RuntimeException('The reservation invoice does not summarize a multi-room allocation correctly: '.$expectedRoomLabel);
        }
    }
    $adults = (int) $activeRooms->sum('adults');
    $children = (int) $activeRooms->sum('children');
    $guestCount = $adults || $children ? $adults.' adults'.($children ? ', '.$children.' children' : '') : 'Not recorded';
    if (! str_contains($multiRoomHtml, '<dt>Guests</dt><dd>'.$guestCount.'</dd>')) {
        throw new RuntimeException('The reservation invoice does not aggregate guest counts across its rooms.');
    }
    echo 'PASS multi-room reservation allocations are summarized and guest counts are aggregated.'.PHP_EOL;
}
$emptyRoomReservation = clone $reservationFolio->reservation;
$emptyRoomReservation->setRelation('rooms', collect());
$reservationWithoutRooms = clone $reservationFolio;
$reservationWithoutRooms->setRelation('reservation', $emptyRoomReservation);
$reservationWithoutRooms->setRelation('stay', null);
$reservationNoRoomHtml = view('folios.invoice', [
    'folio' => $reservationWithoutRooms,
    'tx' => $reservationTransactions,
    'totals' => $reservationWithoutRooms->totals($reservationTransactions),
    'lines' => GstService::lines($reservationWithoutRooms),
    'gst' => $reservationWithoutRooms->gstTotals(),
    'currentHotel' => $currentHotel,
    'errors' => new ViewErrorBag(),
])->render();
if (! str_contains($reservationNoRoomHtml, 'RES-00001') || ! str_contains($reservationNoRoomHtml, 'No active room allocation')) {
    throw new RuntimeException('A reservation-linked invoice without active rooms dropped its reservation context.');
}
echo 'PASS reservation-level details, issuer name, original copy label, day subtotals and amount-in-words render.'.PHP_EOL;
