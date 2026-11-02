<?php

/** Render a rollback-only POS payment/refund pair in the cashier shift report. */

use App\Http\Controllers\ShiftController;
use App\Models\CashierShift;
use App\Models\Hotel;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\PosPayment;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

$hotel = Hotel::where('code', 'NOWILLS')->firstOrFail();
Tenant::set($hotel);
view()->share('currentHotel', $hotel);
view()->share('errors', new ViewErrorBag());
$user = User::where('hotel_id', $hotel->id)->where('username', 'cashier')->firstOrFail();
$shift = CashierShift::where('hotel_id', $hotel->id)->where('user_id', $user->id)->where('status', 'open')->latest('id')->firstOrFail();
$order = PosOrder::where('hotel_id', $hotel->id)->oldest('id')->firstOrFail();
$mode = PaymentMode::where('hotel_id', $hotel->id)->where('type', 'card')->firstOrFail();
$guard = Auth::guard();
$originalUser = $guard->user();
DB::beginTransaction();

try {
    Auth::login($user);
    $receivedAt = $shift->opened_at->copy()->addMinute();
    $payment = PosPayment::create([
        'pos_order_id' => $order->id, 'payment_mode_id' => $mode->id, 'kind' => 'payment', 'amount' => 12.34,
        'reference' => 'SHIFT-DETAIL-AUDIT-PAY', 'business_date' => $shift->business_date->toDateString(),
        'received_by' => $user->id, 'received_at' => $receivedAt,
    ]);
    PosPayment::create([
        'pos_order_id' => $order->id, 'payment_mode_id' => $mode->id, 'kind' => 'refund', 'amount' => 2.34,
        'reference' => 'SHIFT-DETAIL-AUDIT-REF', 'refund_of_id' => $payment->id,
        'business_date' => $shift->business_date->toDateString(), 'received_by' => $user->id,
        'received_at' => $receivedAt->copy()->addSeconds(30),
    ]);

    $response = app(ShiftController::class)->show($shift);
    $html = (string) $response->render();
    $plain = html_entity_decode(strip_tags($html));
    if (! str_contains($plain, 'SHIFT-DETAIL-AUDIT-PAY') || ! str_contains($plain, 'SHIFT-DETAIL-AUDIT-REF')
        || ! str_contains($plain, 'POS '.$order->order_no) || ! str_contains($plain, 'Refund')) {
        throw new RuntimeException('The cashier shift report omitted POS payment/refund ledger details.');
    }

    $data = $response->getData();
    $ledgerRows = collect($data['posPayments'] ?? []);
    if ($ledgerRows->where('pos_order_id', $order->id)->count() !== 2) {
        throw new RuntimeException('The shift report did not include both POS ledger rows.');
    }

    echo "PASS POS payment and refund details render with their references; all test writes will be rolled back.\n";
} finally {
    DB::rollBack();
    $originalUser ? $guard->setUser($originalUser) : $guard->forgetUser();
}

if (PosPayment::where('reference', 'SHIFT-DETAIL-AUDIT-PAY')->exists()
    || PosPayment::where('reference', 'SHIFT-DETAIL-AUDIT-REF')->exists()) {
    throw new RuntimeException('The rollback-only POS shift-detail audit left payment rows in the database.');
}

echo "PASS test payments were rolled back.\n";
