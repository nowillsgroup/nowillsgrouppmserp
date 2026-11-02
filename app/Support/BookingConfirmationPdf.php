<?php

namespace App\Support;

use App\Models\Hotel;
use App\Models\Reservation;

/**
 * Small dependency-free PDF renderer for the booking-confirmation download.
 * It uses PDF's built-in Helvetica fonts, so it needs no Composer package or
 * server-side browser. Text is transliterated to WinAnsi for broad reader support.
 */
class BookingConfirmationPdf
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;
    private const LEFT = 40.0;
    private const RIGHT = 40.0;
    private const CONTENT_WIDTH = self::PAGE_WIDTH - self::LEFT - self::RIGHT;
    private const BOTTOM = 785.0;

    /** @var list<string> */
    private array $pages = [];
    private int $page = 0;
    private float $y = 0;
    private string $reservationNo = '';
    private string $statusLabel = '';
    private string $bookedOn = '';
    private string $hotelName = '';
    /** @var list<string> */
    private array $hotelContactLines = [];
    private string $currency = 'GHS';

    public function render(Reservation $reservation, Hotel $hotel): string
    {
        $this->pages = [];
        $this->reservationNo = (string) $reservation->reservation_no;
        $this->statusLabel = ucwords(str_replace('_', ' ', (string) $reservation->status));
        $this->bookedOn = $reservation->created_at?->format('d M Y') ?? now()->format('d M Y');
        $this->hotelName = (string) $hotel->name;
        $address = implode(', ', array_filter([$hotel->address, $hotel->city, $hotel->state, $hotel->pincode]));
        $contactLines = array_filter([
            $address,
            implode(' · ', array_filter([$hotel->phone ? 'Ph: '.$hotel->phone : null, $hotel->email])),
            $hotel->tax_number ? 'TIN '.$hotel->tax_number : null,
        ]);
        $this->hotelContactLines = [];
        foreach ($contactLines as $contactLine) {
            array_push($this->hotelContactLines, ...$this->wrap((string) $contactLine, 100));
        }
        $this->currency = $this->currencyCode((string) ($hotel->currency ?? 'GHS'));
        $this->startPage(true);

        $guestName = $reservation->guest?->full_name ?: 'Guest';
        $this->text(self::LEFT, $this->y, 'Dear '.$guestName.',', 9, 'bold');
        $this->y += 13;
        $opening = match ((string) $reservation->status) {
            'cancelled' => 'This reservation has been cancelled. Keep this confirmation as a record of the booking.',
            'tentative' => 'Thank you for choosing '.$hotel->name.'. We are holding this reservation while it awaits confirmation.',
            'no_show' => 'This reservation was marked as a no-show. The details below are provided for your records.',
            default => 'Thank you for choosing '.$hotel->name.'. This document confirms the details of your reservation.',
        };
        $this->wrappedParagraph($opening, self::CONTENT_WIDTH, 9, 'regular', 'muted');
        $this->y += 9;

        $this->drawSummaryCards($reservation, $hotel);
        $this->y += 16;
        $this->text(self::LEFT, $this->y, 'ROOM ALLOCATION AND RATES', 9, 'bold', 'green');
        $this->y += 10;
        $this->drawRoomTableHeader();

        $stays = $reservation->rooms->where('status', '!=', 'cancelled')->values();
        $taxSummary = BookingConfirmationTax::summarize($stays);
        $total = $taxSummary['subtotal'];
        if ($stays->isEmpty()) {
            $this->ensureSpace(34);
            $this->text(self::LEFT + 8, $this->y + 17, 'No active room stays are listed for this reservation.', 9, 'regular', 'muted');
            $this->line(self::LEFT, $this->y + 27, self::PAGE_WIDTH - self::RIGHT, $this->y + 27, 'border');
            $this->y += 28;
        } else {
            foreach ($stays as $index => $stay) {
                $rate = (float) $stay->rate + (float) $stay->extra_charge;
                $nights = max(0, (int) $stay->nights);
                $amount = $rate * $nights;
                $roomLabel = (string) ($stay->roomType?->name ?? 'Room stay');
                $roomLabel .= $stay->room?->room_no ? ' (Room '.$stay->room->room_no.')' : ' (Room not yet assigned)';
                $roomLines = $this->wrap($roomLabel, 30);
                $paxLines = [(int) $stay->adults.' adults'];
                if ((int) $stay->children > 0) {
                    $paxLines[] = (int) $stay->children.' children';
                }
                $rowHeight = max(28.0, max(count($roomLines), count($paxLines)) * 11.0 + 12.0);
                if ($this->y + $rowHeight > self::BOTTOM) {
                    $this->startPage(false);
                    $this->drawRoomTableHeader();
                }
                $top = $this->y;
                if ($index % 2 === 1) {
                    $this->rectangle(self::LEFT, $top, self::CONTENT_WIDTH, $rowHeight, 'pale');
                }
                $this->text(self::LEFT + 7, $top + 17, (string) ($index + 1), 8, 'regular');
                foreach ($roomLines as $line => $value) {
                    $this->text(self::LEFT + 31, $top + 14 + ($line * 11), $value, 8, $line === 0 ? 'bold' : 'regular');
                }
                $this->text(self::LEFT + 195, $top + 17, (string) ($stay->ratePlan?->meal_plan ?? 'EP'), 8, 'regular');
                foreach ($paxLines as $line => $value) {
                    $this->text(self::LEFT + 248, $top + 14 + ($line * 11), $value, 7, 'regular');
                }
                $this->rightText(self::LEFT + 375, $top + 17, $this->amount($rate), 8);
                $this->rightText(self::LEFT + 423, $top + 17, (string) $nights, 8);
                $this->rightText(self::LEFT + self::CONTENT_WIDTH - 7, $top + 17, $this->amount($amount), 8);
                $this->line(self::LEFT, $top + $rowHeight, self::PAGE_WIDTH - self::RIGHT, $top + $rowHeight, 'border');
                $this->y += $rowHeight;
            }
        }

        $transactions = $reservation->folios->flatMap->transactions->where('is_void', false);
        $paymentsReceived = (float) $transactions->where('type', 'payment')->sum('amount');
        $refundsIssued = (float) $transactions->where('type', 'refund')->sum('amount');
        $summary = [['Room total (excluding taxes)', $this->amount($total), 'muted', true]];
        foreach ($taxSummary['components'] as $component) {
            $summary[] = [$component['label'], $this->amount($component['amount']), 'muted', false];
        }
        if ($taxSummary['components'] === []) {
            $summary[] = ['Room taxes', $this->amount(0), 'muted', false];
        }
        $summary[] = ['Total room taxes', $this->amount($taxSummary['tax_total']), 'muted', true];
        $summary[] = ['Estimated room total (including taxes)', $this->amount($taxSummary['total']), 'green', true];
        if ($paymentsReceived > 0) {
            $summary[] = ['Payments received', $this->amount($paymentsReceived), 'muted', false];
        }
        if ($refundsIssued > 0) {
            $summary[] = ['Refunds recorded', $this->amount($refundsIssued), 'muted', false];
        }
        $this->y += 11;
        $this->ensureSpace(count($summary) * 15 + 24);
        foreach ($summary as $index => [$label, $amount, $color, $bold]) {
            $lineY = $this->y + 11 + ($index * 15);
            $this->text(self::LEFT + 292, $lineY, $label, 8, $bold ? 'bold' : 'regular', $color);
            $this->rightText(self::PAGE_WIDTH - self::RIGHT - 8, $lineY, $amount, 8, $bold, $color);
        }
        $this->y += count($summary) * 15 + 5;
        $this->wrappedParagraph("Tax components are estimates based on the property's current room-tax setup; actual taxes posted to the folio may differ.", self::CONTENT_WIDTH, 7, 'regular', 'muted');
        $this->y += 8;

        if ($reservation->special_request) {
            $this->ensureSpace(32);
            $this->text(self::LEFT, $this->y + 10, 'SPECIAL REQUESTS', 8, 'bold', 'green');
            $this->y += 17;
            $this->wrappedParagraph((string) $reservation->special_request, self::CONTENT_WIDTH, 8, 'regular', 'text');
            $this->y += 8;
        }

        if (in_array((string) $reservation->status, ['confirmed', 'tentative'], true)) {
            $checkin = substr((string) ($hotel->checkin_time ?? ''), 0, 5) ?: '—';
            $checkout = substr((string) ($hotel->checkout_time ?? ''), 0, 5) ?: '—';
            $this->ensureSpace(25);
            $this->text(self::LEFT, $this->y + 9, 'BEFORE YOU ARRIVE', 8, 'bold', 'green');
            $this->y += 15;
            $this->wrappedParagraph('Please bring valid photo identification for each guest. Check-in is from '.$checkin.' and check-out is by '.$checkout.'.', self::CONTENT_WIDTH, 8, 'regular', 'muted');
            $this->y += 7;
        }

        $bookingPolicy = trim((string) setting('booking_policy'));
        if ($bookingPolicy !== '') {
            $this->ensureSpace(24);
            $this->text(self::LEFT, $this->y + 9, 'BOOKING AND CANCELLATION POLICY', 8, 'bold', 'green');
            $this->y += 15;
            $this->wrappedParagraph($bookingPolicy, self::CONTENT_WIDTH, 8, 'regular', 'muted');
            $this->y += 7;
        }

        $closing = match ((string) $reservation->status) {
            'checked_out' => 'Thank you for staying with us.',
            'cancelled', 'no_show' => 'For questions about this reservation, please contact the hotel using the details above.',
            default => 'We look forward to welcoming you.',
        };
        $this->ensureSpace(35);
        $this->wrappedParagraph($closing, self::CONTENT_WIDTH, 8, 'regular', 'text');
        $this->y += 6;
        $this->text(self::LEFT, $this->y, 'Warm regards,', 8, 'regular');
        $this->y += 12;
        $this->text(self::LEFT, $this->y, 'Reservations Team · '.$hotel->name, 8, 'bold');

        return $this->buildPdf();
    }

    private function drawSummaryCards(Reservation $reservation, Hotel $hotel): void
    {
        $top = $this->y;
        $gap = 12.0;
        $cardWidth = (self::CONTENT_WIDTH - $gap) / 2;
        $cardHeight = 132.0;
        $this->rectangle(self::LEFT, $top, $cardWidth, $cardHeight, 'pale', 'border');
        $rightX = self::LEFT + $cardWidth + $gap;
        $this->rectangle($rightX, $top, $cardWidth, $cardHeight, 'pale', 'border');

        $this->text(self::LEFT + 10, $top + 17, 'GUEST DETAILS', 8, 'bold', 'green');
        $guest = $reservation->guest;
        $guestLines = [
            $guest?->full_name ?: 'Guest name not recorded',
            'Mobile: '.($guest?->phone ?: 'Not provided'),
            'E-mail: '.($guest?->email ?: 'Not provided'),
        ];
        if ($reservation->company?->name) {
            $guestLines[] = 'Company: '.$reservation->company->name;
        }
        $guestLineY = $top + 35;
        foreach ($guestLines as $index => $value) {
            foreach ($this->wrap($value, 45) as $part) {
                $this->text(self::LEFT + 10, $guestLineY, $part, 7.5, $index === 0 ? 'bold' : 'regular');
                $guestLineY += 10;
            }
            $guestLineY += 3;
        }

        $this->text($rightX + 10, $top + 17, 'STAY SUMMARY', 8, 'bold', 'green');
        $arrival = $reservation->arrival_date?->format('D, d M Y') ?? 'Not set';
        $departure = $reservation->departure_date?->format('D, d M Y') ?? 'Not set';
        $checkin = substr((string) ($hotel->checkin_time ?? ''), 0, 5) ?: '—';
        $checkout = substr((string) ($hotel->checkout_time ?? ''), 0, 5) ?: '—';
        $guestCount = (int) $reservation->adults.' adults';
        if ((int) $reservation->children > 0) {
            $guestCount .= ', '.(int) $reservation->children.' children';
        }
        $stayLines = [
            'Arrival: '.$arrival.' · Check-in '.$checkin,
            'Departure: '.$departure.' · Check-out '.$checkout,
            'Nights: '.(int) $reservation->nights.' · Guests: '.$guestCount,
            'Status: '.ucwords(str_replace('_', ' ', (string) $reservation->status)),
        ];
        foreach ($stayLines as $index => $value) {
            $this->text($rightX + 10, $top + 35 + ($index * 16), $value, 7.5, 'regular');
        }
        $this->y = $top + $cardHeight;
    }

    private function drawRoomTableHeader(): void
    {
        $top = $this->y;
        $this->rectangle(self::LEFT, $top, self::CONTENT_WIDTH, 24, 'green');
        $this->text(self::LEFT + 7, $top + 16, '#', 7, 'bold', 'white');
        $this->text(self::LEFT + 31, $top + 16, 'Room / type', 7, 'bold', 'white');
        $this->text(self::LEFT + 195, $top + 16, 'Plan', 7, 'bold', 'white');
        $this->text(self::LEFT + 248, $top + 16, 'Guests', 7, 'bold', 'white');
        $this->rightText(self::LEFT + 375, $top + 16, 'Rate / night', 7, true, 'white');
        $this->rightText(self::LEFT + 423, $top + 16, 'Nights', 7, true, 'white');
        $this->rightText(self::LEFT + self::CONTENT_WIDTH - 7, $top + 16, 'Room total', 7, true, 'white');
        $this->y += 24;
    }

    private function startPage(bool $first): void
    {
        $this->page = count($this->pages);
        $this->pages[] = '';
        if ($first) {
            $headerHeight = max(108.0, 82.0 + (count($this->hotelContactLines) * 9.0) + 12.0);
            $this->rectangle(0, 0, self::PAGE_WIDTH, $headerHeight, 'green');
            $this->text(self::LEFT, 30, 'BOOKING CONFIRMATION', 16, 'bold', 'white');
            $this->text(self::LEFT, 49, 'Reservation '.$this->reservationNo, 9, 'regular', 'white');
            $this->text(self::LEFT, 67, $this->hotelName, 8, 'bold', 'white');
            foreach ($this->hotelContactLines as $index => $line) {
                $this->text(self::LEFT, 81 + ($index * 9), $line, 6.5, 'regular', 'white');
            }
            $this->rightText(self::PAGE_WIDTH - self::RIGHT, 30, 'STATUS: '.strtoupper($this->statusLabel), 8, true, 'white');
            $this->rightText(self::PAGE_WIDTH - self::RIGHT, 49, 'Booked on '.$this->bookedOn, 7.5, false, 'white');
            $this->y = $headerHeight + 18.0;
        } else {
            $this->text(self::LEFT, 36, 'BOOKING CONFIRMATION · '.$this->reservationNo, 9, 'bold', 'green');
            $this->rightText(self::PAGE_WIDTH - self::RIGHT, 36, $this->hotelName, 8, false, 'muted');
            $this->line(self::LEFT, 46, self::PAGE_WIDTH - self::RIGHT, 46, 'border');
            $this->y = 62;
        }
    }

    private function ensureSpace(float $height): void
    {
        if ($this->y + $height > self::BOTTOM) {
            $this->startPage(false);
        }
    }

    private function wrappedParagraph(string $value, float $width, float $size, string $weight = 'regular', string $color = 'text'): void
    {
        $characters = max(20, (int) floor($width / ($size * 0.53)));
        foreach ($this->wrap($value, $characters) as $line) {
            $this->ensureSpace($size + 4);
            $this->text(self::LEFT, $this->y + $size, $line, $size, $weight, $color);
            $this->y += $size + 3;
        }
    }

    /** @return list<string> */
    private function wrap(string $value, int $characters): array
    {
        $bytes = $this->textBytes($value);
        $lines = preg_split('/\r\n|\r|\n/', wordwrap($bytes, max(1, $characters), "\n", true)) ?: [];

        return $lines ?: [''];
    }

    private function text(float $x, float $y, string $value, float $size = 9, string $weight = 'regular', string $color = 'text'): void
    {
        $pdfY = self::PAGE_HEIGHT - $y;
        $font = $weight === 'bold' ? 'F2' : 'F1';
        $this->pages[$this->page] .= $this->colorCommand($color, false)
            .'BT /'.$font.' '.$this->number($size).' Tf 1 0 0 1 '.$this->number($x).' '.$this->number($pdfY).' Tm ('
            .$this->escape($this->textBytes($value)).") Tj ET\n";
    }

    private function rightText(float $right, float $y, string $value, float $size = 9, bool $bold = false, string $color = 'text'): void
    {
        $bytes = $this->textBytes($value);
        $estimatedWidth = strlen($bytes) * $size * 0.52;
        $this->text($right - $estimatedWidth, $y, $value, $size, $bold ? 'bold' : 'regular', $color);
    }

    private function rectangle(float $x, float $top, float $width, float $height, string $fill, ?string $stroke = null): void
    {
        $bottom = self::PAGE_HEIGHT - $top - $height;
        $this->pages[$this->page] .= $this->colorCommand($fill, false)
            .$this->number($x).' '.$this->number($bottom).' '.$this->number($width).' '.$this->number($height).' re f\n';
        if ($stroke) {
            $this->pages[$this->page] .= $this->colorCommand($stroke, true)
                .$this->number($x).' '.$this->number($bottom).' '.$this->number($width).' '.$this->number($height).' re S\n';
        }
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $color = 'border'): void
    {
        $this->pages[$this->page] .= $this->colorCommand($color, true)
            .$this->number($x1).' '. $this->number(self::PAGE_HEIGHT - $y1).' m '
            .$this->number($x2).' '. $this->number(self::PAGE_HEIGHT - $y2).' l S\n';
    }

    private function colorCommand(string $color, bool $stroke): string
    {
        $colors = [
            'green' => [0.09, 0.29, 0.18],
            'pale' => [0.95, 0.97, 0.95],
            'border' => [0.82, 0.86, 0.83],
            'muted' => [0.38, 0.44, 0.40],
            'white' => [1.0, 1.0, 1.0],
            'text' => [0.12, 0.16, 0.13],
        ];
        $rgb = $colors[$color] ?? $colors['text'];

        return implode(' ', array_map(fn (float $component): string => $this->number($component), $rgb)).($stroke ? " RG\n" : " rg\n");
    }

    private function currencyCode(string $symbol): string
    {
        if (str_contains($symbol, '₵') || str_contains(strtolower($symbol), 'gh')) {
            return 'GHS';
        }

        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $symbol) ?: 'GHS', 0, 4));
    }

    private function amount(float $amount): string
    {
        return $this->currency.' '.number_format($amount, 2, '.', ',');
    }

    private function textBytes(string $value): string
    {
        $value = str_replace(['GH₵', '₵', '’', '‘', '“', '”', '–', '—', '·', ' '], ['GHS ', 'GHS ', "'", "'", '"', '"', '-', '-', '-', ' '], $value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", '(', ')'], ["\\\\", '\\(', '\\)'], $value);
    }

    private function number(float $number): string
    {
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function buildPdf(): string
    {
        $pageCount = count($this->pages);
        foreach ($this->pages as $index => $_) {
            $this->pages[$index] .= $this->colorCommand('border', true)
                .$this->number(self::LEFT).' '. $this->number(self::PAGE_HEIGHT - 802).' m '
                .$this->number(self::PAGE_WIDTH - self::RIGHT).' '. $this->number(self::PAGE_HEIGHT - 802).' l S\n';
            $footer = $this->hotelName.' · '.$this->reservationNo.' · Page '.($index + 1).' of '.$pageCount;
            $bytes = $this->textBytes($footer);
            $this->pages[$index] .= $this->colorCommand('muted', false)
                .'BT /F1 7 Tf 1 0 0 1 '.self::LEFT.' '.($this->number(self::PAGE_HEIGHT - 819)).' Tm ('
                .$this->escape($bytes).") Tj ET\n";
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        foreach ($this->pages as $index => $stream) {
            $pageId = 5 + ($index * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.self::PAGE_HEIGHT.'] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = "<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; ++$id) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0)."\n";
        }
        $pdf .= "trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}
