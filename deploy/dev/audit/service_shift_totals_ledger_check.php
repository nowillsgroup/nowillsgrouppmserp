<?php
/**
 * Read-only check of the service-shift totals sheet.
 *
 * It recomputes, independently of DepartmentShiftService, what each payment method collected on a
 * Food & Beverage service shift and compares that with what the shift report and its print sheet
 * show. Nothing is created, changed or deleted — run it on a live database without side effects.
 *
 *   php artisan tinker --execute="require 'deploy/dev/audit/service_shift_totals_ledger_check.php';"
 */

use App\Models\DepartmentShiftSession;
use App\Models\Hotel;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\PosPayment;
use App\Services\DepartmentShiftService;
use App\Support\Tenant;

$failures = [];
$lines = [];
$assert = function (string $label, bool $ok) use (&$failures, &$lines) {
    $lines[] = ($ok ? 'PASS  ' : 'FAIL  ').$label;
    if (! $ok) {
        $failures[] = $label;
    }
};

$hotel = Hotel::where('code', 'NOWILLS')->first() ?? Hotel::first();
if (! $hotel) {
    echo "SKIP  No property found in this database.\n";
    exit(0);
}
Tenant::set($hotel);

$sessions = DepartmentShiftSession::where('department', DepartmentShiftService::FOOD_BEVERAGE)
    ->orderByDesc('opened_at')->limit(5)->get();

if ($sessions->isEmpty()) {
    echo "SKIP  No Food & Beverage service shift has been recorded for {$hotel->name} yet.\n";
    echo "      Open one, settle a couple of bills, then re-run to verify the totals sheet.\n";
    exit(0);
}

$modes = PaymentMode::withoutGlobalScopes()->where('hotel_id', $hotel->id)->get()->keyBy('id');

foreach ($sessions as $session) {
    $summary = DepartmentShiftService::summary($session);
    $rows = $summary['payments'] ?? null;

    $assert('Session #'.$session->id.' ('.$session->status.') exposes the payment breakdown', is_array($rows));
    if (! is_array($rows)) {
        continue;
    }

    // Independent recomputation: ledger rows of the orders linked to this session.
    $orderIds = $session->posOrders()->pluck('id');
    $ledger = $orderIds->isEmpty() ? collect() : PosPayment::whereIn('pos_order_id', $orderIds)->get(['payment_mode_id', 'kind', 'amount']);

    $expected = [];
    foreach ($ledger as $payment) {
        $name = $modes[$payment->payment_mode_id]?->name ?? 'Unspecified method';
        $expected[$name] ??= ['collected' => 0.0, 'refunded' => 0.0];
        if ($payment->kind === 'payment') {
            $expected[$name]['collected'] += (float) $payment->amount;
        } elseif ($payment->kind === 'refund') {
            $expected[$name]['refunded'] += (float) $payment->amount;
        }
    }
    // Legacy settlements with no ledger row are counted once, at the order total.
    $legacy = PosOrder::where('department_shift_session_id', $session->id)->where('status', 'settled')
        ->where('settlement', 'payment')->whereNotIn('id', $ledger->isEmpty() ? [-1] : $ledger->pluck('pos_order_id')->unique()->all())
        ->get(['total', 'payment_mode_id']);
    foreach ($legacy as $order) {
        $name = $modes[$order->payment_mode_id]?->name ?? 'Unspecified method';
        $expected[$name] ??= ['collected' => 0.0, 'refunded' => 0.0];
        $expected[$name]['collected'] += (float) $order->total;
    }

    $reported = collect($rows)->keyBy('name');
    foreach ($expected as $name => $amounts) {
        $row = $reported->get($name);
        $assert('  '.$name.' collected matches the ledger ('.number_format($amounts['collected'], 2).')',
            $row !== null && abs((float) $row['collected'] - round($amounts['collected'], 2)) < 0.005);
        $assert('  '.$name.' refunds match the ledger ('.number_format($amounts['refunded'], 2).')',
            $row !== null && abs((float) $row['refunded'] - round($amounts['refunded'], 2)) < 0.005);
        $assert('  '.$name.' net is collected minus refunds',
            $row !== null && abs((float) $row['net'] - round($amounts['collected'] - $amounts['refunded'], 2)) < 0.005);
    }
    foreach ($reported as $name => $row) {
        $assert('  '.$name.' appears because the ledger has a tender for it', array_key_exists($name, $expected));
    }

    $assert('  The total for all methods equals the sum of the method lines',
        abs((float) $summary['payments_total'] - round(collect($rows)->sum('net'), 2)) < 0.005);
    $assert('  Collected and refunds roll up the same way',
        abs((float) $summary['payments_in'] - round(collect($rows)->sum('collected'), 2)) < 0.005
        && abs((float) $summary['payments_out'] - round(collect($rows)->sum('refunded'), 2)) < 0.005);

    // Room-posted money is never presented as collected at the table.
    $assert('  Room posts stay out of the collected total',
        (float) $summary['room_posts'] <= 0 || (float) $summary['payments_total'] <= (float) $summary['settled_sales'] + 0.005);

    if ($session->status === 'closed') {
        $snapshot = (array) $session->close_snapshot;
        $assert('  The saved close-out keeps the payment breakdown for reprints', array_key_exists('payments', $snapshot));
        $assert('  The close-out total is unchanged by reading it',
            abs((float) $snapshot['payments_total'] - (float) $summary['payments_total']) < 0.005);
    }
}

echo implode("\n", $lines)."\n\n";
echo $failures === []
    ? "Service shift totals ledger check passed: ".$sessions->count()." session(s), payment methods, refunds and the bottom total all agree with the POS payment ledger.\n"
    : "Service shift totals ledger check FAILED with ".count($failures)." problem(s):\n- ".implode("\n- ", $failures)."\n";

exit($failures === [] ? 0 : 1);
