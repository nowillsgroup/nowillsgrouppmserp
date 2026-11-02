<?php

// Render and structurally validate single- and multi-page booking confirmations without database writes.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/reservation_confirmation_pdf_render_check.php';"

use App\Models\Hotel;
use App\Models\Reservation;
use App\Support\BookingConfirmationPdf;
use App\Support\Tenant;

Tenant::set(Hotel::findOrFail(1));
$reservation = Reservation::with([
    'guest', 'company', 'rooms.roomType', 'rooms.room', 'rooms.ratePlan', 'folios.transactions',
])->findOrFail(202);
$renderer = new BookingConfirmationPdf();

$validatePdf = static function (string $pdf, int $minimumPages, string $label): int {
    if (! str_starts_with($pdf, "%PDF-1.4\n") || ! str_ends_with(rtrim($pdf), '%%EOF')) {
        throw new RuntimeException("{$label} output has an invalid PDF header or trailer.");
    }
    if (! preg_match('/\/Count\s+(\d+)/', $pdf, $pagesMatch) || (int) $pagesMatch[1] < $minimumPages) {
        throw new RuntimeException("{$label} output does not contain the expected page tree.");
    }
    if (! preg_match('/startxref\n(\d+)\n%%EOF/s', $pdf, $xrefMatch) || substr($pdf, (int) $xrefMatch[1], 4) !== 'xref') {
        throw new RuntimeException("{$label} output has an invalid cross-reference offset.");
    }
    if (! preg_match('/xref\n0 (\d+)\n(.*?)trailer/s', $pdf, $xrefTable)) {
        throw new RuntimeException("{$label} output is missing its cross-reference table.");
    }
    $entries = array_slice(explode("\n", trim($xrefTable[2])), 1);
    foreach ($entries as $objectId => $entry) {
        if (! preg_match('/^(\d{10}) 00000 n/', $entry, $offsetMatch)) {
            throw new RuntimeException("{$label} object ".($objectId + 1).' has an invalid xref entry.');
        }
        $id = $objectId + 1;
        if (! str_starts_with(substr($pdf, (int) $offsetMatch[1]), $id.' 0 obj')) {
            throw new RuntimeException("{$label} object {$id} does not match its xref offset.");
        }
    }

    return (int) $pagesMatch[1];
};

$singlePage = $renderer->render($reservation, hotel());
$singlePages = $validatePdf($singlePage, 1, 'Single-page confirmation');
$singlePageText = str_replace(['\\(', '\\)'], ['(', ')'], $singlePage);
foreach ([$reservation->reservation_no, 'BOOKING CONFIRMATION', 'This reservation has been cancelled.', 'Room total (excluding taxes)', 'Booked on ', $reservation->guest?->full_name ?? 'Guest'] as $text) {
    if (! str_contains($singlePageText, (string) $text)) {
        throw new RuntimeException('The PDF does not include expected confirmation content: '.$text);
    }
}
if (str_contains($singlePage, 'Nowills PMS')) {
    throw new RuntimeException('The guest-facing PDF footer must identify the hotel, not the PMS product.');
}
if (trim((string) setting('booking_policy')) !== '' && ! str_contains($singlePage, 'BOOKING AND CANCELLATION POLICY')) {
    throw new RuntimeException('The PDF is missing the configured booking policy.');
}
echo "PASS single-page PDF structure and confirmation content ({$singlePages} page, ".strlen($singlePage)." bytes).".PHP_EOL;

foreach ([
    'tentative' => 'holding this reservation',
    'no_show' => 'marked as a no-show',
    'checked_out' => 'Thank you for staying with us.',
] as $status => $copy) {
    $statusReservation = clone $reservation;
    $statusReservation->status = $status;
    $statusPdf = $renderer->render($statusReservation, hotel());
    if (! str_contains($statusPdf, $copy)) {
        throw new RuntimeException("The PDF is missing the {$status} status message.");
    }
}
echo 'PASS status-specific PDF copy.'.PHP_EOL;

$longContactHotel = clone hotel();
$longContactHotel->address = str_repeat('Extended property address section ', 10).'CONTACT-END-MARKER';
$longContactPdf = $renderer->render($reservation, $longContactHotel);
if (! str_contains($longContactPdf, 'CONTACT-END-MARKER')) {
    throw new RuntimeException('The PDF header truncates longer property addresses.');
}
foreach ([$longContactHotel->phone, $longContactHotel->email, $longContactHotel->tax_number] as $contactValue) {
    if ($contactValue && ! str_contains($longContactPdf, (string) $contactValue)) {
        throw new RuntimeException('The PDF header truncated property contact details.');
    }
}
echo 'PASS long property contact details remain in the PDF.'.PHP_EOL;

$sampleStay = $reservation->rooms->first();
if (! $sampleStay) {
    throw new RuntimeException('Reservation 202 has no stay for the multi-page render check.');
}
$manyRooms = collect(range(1, 42))->map(static function () use ($sampleStay) {
    $stay = clone $sampleStay;
    $stay->status = 'reserved';

    return $stay;
});
$multiRoomReservation = clone $reservation;
$multiRoomReservation->setRelation('rooms', $manyRooms);
$multiPage = $renderer->render($multiRoomReservation, hotel());
$multiPages = $validatePdf($multiPage, 2, 'Multi-page confirmation');
echo "PASS multi-page PDF structure ({$multiPages} pages, ".strlen($multiPage)." bytes).".PHP_EOL;
