<?php

// Render processed, pending, full, and partial refund variants without changing the development database.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/payment_page_status_render_check.php';"

use App\Models\Hotel;
use App\Models\OnlinePayment;
use App\Models\OnlineRefund;
use App\Support\Tenant;
use Illuminate\Support\ViewErrorBag;

Tenant::set(Hotel::findOrFail(1));
$sample = OnlinePayment::with('reservation.rooms.roomType', 'folio', 'refunds')
    ->where('status', 'paid')->where('refunded_amount', '>', 0)->first();
if (! $sample) {
    throw new RuntimeException('No refunded online payment is available for the render check.');
}
$render = function (OnlinePayment $payment): string {
    $refunds = $payment->refunds;

    return view('pay.show', [
        'p' => $payment,
        'hotel' => hotel(),
        'r' => $payment->reservation,
        'test' => $payment->provider === 'demo',
        'bookingDone' => null,
        'processedRefundAmount' => (float) $refunds->where('status', 'processed')->sum('amount'),
        'pendingRefundAmount' => (float) $refunds->where('status', 'pending')->sum('amount'),
        'errors' => new ViewErrorBag(),
    ])->render();
};

$partial = clone $sample;
$partialHtml = $render($partial);
if (! str_contains($partialHtml, 'A partial refund was issued.') || ! str_contains($partialHtml, 'receipt for the original payment')) {
    throw new RuntimeException('The processed partial-refund guest page did not show the correct payment outcome.');
}

$full = clone $sample;
$full->refunded_amount = $full->received();
$fullHtml = $render($full);
if (! str_contains($fullHtml, 'This payment was fully refunded.') || ! str_contains($fullHtml, 'No further payment is due on this link.')) {
    throw new RuntimeException('The processed full-refund guest page did not show the correct payment outcome.');
}

$pendingAmount = min((float) $sample->received(), max(0.01, (float) $sample->refunded_amount));
$pending = clone $sample;
$pending->refunded_amount = $pendingAmount;
$pending->setRelation('refunds', collect([new OnlineRefund(['amount' => $pendingAmount, 'status' => 'pending'])]));
$pendingHtml = $render($pending);
if (! str_contains($pendingHtml, 'refund is in progress') || ! str_contains($pendingHtml, 'is still pending with the gateway')) {
    throw new RuntimeException('The pending partial-refund page did not identify the amount as pending.');
}
if (str_contains($pendingHtml, 'was returned to the original payment method')) {
    throw new RuntimeException('A pending refund was incorrectly described as returned.');
}

$fullPending = clone $sample;
$fullPending->refunded_amount = $fullPending->received();
$fullPending->setRelation('refunds', collect([new OnlineRefund(['amount' => $fullPending->received(), 'status' => 'pending'])]));
$fullPendingHtml = $render($fullPending);
if (! str_contains($fullPendingHtml, 'full refund is still being processed') || str_contains($fullPendingHtml, 'was returned to the original payment method')) {
    throw new RuntimeException('The pending full-refund page did not identify the pending status accurately.');
}

foreach ([$partialHtml, $fullHtml, $pendingHtml, $fullPendingHtml] as $html) {
    if (str_contains($html, 'This payment link was cancelled by the hotel.')) {
        throw new RuntimeException('A refunded payment was incorrectly described as cancelled.');
    }
}

echo 'Rendered processed/pending partial/full refund guest pages without changing payment records.'.PHP_EOL;
