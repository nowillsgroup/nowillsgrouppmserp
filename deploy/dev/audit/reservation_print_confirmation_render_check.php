<?php

// Render the guest-facing print form across reservation lifecycle states without database writes.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_print_confirmation_render_check.php';"

use App\Models\Hotel;
use App\Models\Reservation;
use App\Support\BookingConfirmationPdf;
use App\Support\BookingConfirmationTax;
use App\Support\Tenant;
use Illuminate\Support\ViewErrorBag;

Tenant::set(Hotel::findOrFail(1));
$reservation = Reservation::with([
    'guest', 'company', 'rooms.roomType', 'rooms.room', 'rooms.ratePlan', 'folios.transactions',
])->findOrFail(84);
$property = hotel();
$expectedCopy = [
    'confirmed' => 'This document confirms the details of your reservation.',
    'tentative' => 'We are holding this reservation while it awaits confirmation.',
    'cancelled' => 'This reservation has been cancelled.',
    'no_show' => 'This reservation was marked as a no-show.',
    'checked_out' => 'This document confirms the details of your reservation.',
];

foreach ($expectedCopy as $status => $copy) {
    $sample = clone $reservation;
    $sample->status = $status;
    $html = view('reservations.print', [
        'r' => $sample,
        'currentHotel' => $property,
        'errors' => new ViewErrorBag(),
    ])->render();

    foreach ([$sample->reservation_no, $copy, 'Guest details', 'Stay summary', 'Room allocation and rates', 'Room total (excluding taxes)'] as $expected) {
        if (! str_contains($html, (string) $expected)) {
            throw new RuntimeException("The {$status} print form is missing expected content: {$expected}");
        }
    }

    $shouldShowArrivalNote = in_array($status, ['confirmed', 'tentative'], true);
    if (str_contains($html, 'Before you arrive') !== $shouldShowArrivalNote) {
        throw new RuntimeException("The {$status} print form has an incorrect pre-arrival note state.");
    }
}

$confirmed = clone $reservation;
$confirmed->status = 'confirmed';
$confirmedHtml = view('reservations.print', [
    'r' => $confirmed,
    'currentHotel' => $property,
    'errors' => new ViewErrorBag(),
])->render();
$activeRooms = $reservation->rooms->where('status', '!=', 'cancelled');
$taxSummary = BookingConfirmationTax::summarize($activeRooms);
$roomTotal = $taxSummary['subtotal'];
$componentSum = round(array_sum(array_column($taxSummary['components'], 'amount')), 2);
if ($componentSum !== (float) $taxSummary['tax_total']
    || round($taxSummary['subtotal'] + $taxSummary['tax_total'], 2) !== (float) $taxSummary['total']) {
    throw new RuntimeException('The room-tax components do not sum to the room-tax and tax-inclusive totals.');
}
if (! str_contains($confirmedHtml, money((float) $roomTotal))) {
    throw new RuntimeException('The browser-print room total does not match the active room rows.');
}
$pdf = (new BookingConfirmationPdf())->render($confirmed, $property);
$pdfText = str_replace(['\\(', '\\)'], ['(', ')'], $pdf);
$pdfTotal = number_format((float) $roomTotal, 2, '.', ',');
if (! str_contains($pdfText, $pdfTotal)) {
    throw new RuntimeException('The downloadable PDF room total does not match the browser-print room total.');
}
foreach ($taxSummary['components'] as $component) {
    $componentAmount = number_format((float) $component['amount'], 2, '.', ',');
    if (! str_contains($confirmedHtml, $component['label'])
        || ! str_contains($confirmedHtml, money((float) $component['amount']))
        || ! str_contains($pdfText, $component['label'])
        || ! str_contains($pdfText, $componentAmount)) {
        throw new RuntimeException('A '.$component['label'].' component is missing or inconsistent between print and PDF.');
    }
}
$taxTotal = number_format((float) $taxSummary['tax_total'], 2, '.', ',');
if (! str_contains($confirmedHtml, 'Total room taxes')
    || ! str_contains($confirmedHtml, money((float) $taxSummary['tax_total']))
    || ! str_contains($pdfText, 'Total room taxes')
    || ! str_contains($pdfText, $taxTotal)) {
    throw new RuntimeException('The room-tax total is missing or inconsistent between print and PDF.');
}
$estimatedTotal = number_format((float) $taxSummary['total'], 2, '.', ',');
if (! str_contains($confirmedHtml, 'Estimated room total (including taxes)')
    || ! str_contains($confirmedHtml, money((float) $taxSummary['total']))
    || ! str_contains($pdfText, 'Estimated room total (including taxes)')
    || ! str_contains($pdfText, $estimatedTotal)) {
    throw new RuntimeException('The estimated room total including taxes is missing or inconsistent.');
}
$transactions = $reservation->folios->flatMap->transactions->where('is_void', false);
foreach ([
    'payment' => ['Payments received', 'Payments received'],
    'refund' => ['Refunds recorded', 'Refunds recorded'],
] as $type => [$printLabel, $pdfLabel]) {
    if ($transactions->where('type', $type)->sum('amount') > 0
        && (! str_contains($confirmedHtml, $printLabel) || ! str_contains($pdf, $pdfLabel))) {
        throw new RuntimeException("Payment history for {$type} is missing from one confirmation format.");
    }
}

$emptyReservation = clone $confirmed;
$emptyReservation->setRelation('rooms', collect());
$emptySummary = BookingConfirmationTax::summarize([]);
$emptyHtml = view('reservations.print', [
    'r' => $emptyReservation,
    'currentHotel' => $property,
    'errors' => new ViewErrorBag(),
])->render();
$emptyPdf = (new BookingConfirmationPdf())->render($emptyReservation, $property);
$emptyPdfText = str_replace(['\\(', '\\)'], ['(', ')'], $emptyPdf);
if ($emptySummary['subtotal'] !== 0.0 || $emptySummary['tax_total'] !== 0.0 || $emptySummary['total'] !== 0.0
    || ! str_contains($emptyHtml, 'No active room stays are listed')
    || ! str_contains($emptyHtml, 'Room taxes')
    || ! str_contains($emptyPdfText, 'No active room stays are listed')
    || ! str_contains($emptyPdfText, 'Room taxes')) {
    throw new RuntimeException('An empty or fully-cancelled room allocation has an incorrect tax summary.');
}
echo 'PASS tax-component and tax-inclusive totals agree across HTML/PDF; empty room allocations remain zero-valued.'.PHP_EOL;

echo 'PASS printed confirmation renders correctly for confirmed, tentative, cancelled, no-show and checked-out reservations; room totals agree with the PDF.'.PHP_EOL;
