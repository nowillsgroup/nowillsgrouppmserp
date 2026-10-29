<?php

/**
 * Rollback-only integration check for split folio payments, reservation advances,
 * front-desk / banquet deposits, spa, transport, laundry, parking and offline folio settlement.
 * Run from the repo root with:
 *   TINKER='require "/app/deploy/dev/audit/guest_split_tender_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Http\Controllers\FolioController;
use App\Http\Controllers\FrontDeskController;
use App\Http\Controllers\ReservationController;
use App\Models\Folio;
use App\Models\BanquetEvent;
use App\Models\ClubDayPass;
use App\Models\ClubMember;
use App\Models\FolioTransaction;
use App\Models\LaundryOrder;
use App\Models\ParkingSession;
use App\Models\ReservationRoom;
use App\Models\ClubTicketType;
use App\Models\OfflineSyncOperation;
use App\Models\PaymentMode;
use App\Models\User;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\SpaAppointment;
use App\Models\TransportTrip;
use App\Services\BanquetSalesService;
use App\Services\ClubBillingService;
use App\Services\ClubService;
use App\Services\RecreationService;
use App\Services\SpaBookingService;
use App\Services\TaxService;
use App\Services\TransportService;
use App\Services\FolioService;
use App\Services\LedgerService;
use App\Services\LaundryService;
use App\Services\ParkingService;
use App\Services\DesktopSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

$checks = 0;
$fails = [];
$check = function (string $label, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    if (! $ok) {
        $fails[] = $label;
    }
};

DB::beginTransaction();
try {
    $cash = PaymentMode::where('is_active', true)->where('type', 'cash')->first();
    $card = PaymentMode::where('is_active', true)->where('type', 'card')->first();
    $wallet = PaymentMode::where('is_active', true)->where('type', 'wallet')->first();
    if (! $cash || ! $card || ! $wallet) {
        throw new RuntimeException('The rollback integration check needs active Cash, Card and Mobile Money payment modes.');
    }

    $folio = FolioService::openStandalone(['type' => 'walk_in']);
    FolioService::charge($folio, 'misc', 'Split tender audit fixture', 15.25, false);
    $lines = [
        ['amount' => '10.00', 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-AUDIT-CASH'],
        ['amount' => '4.00', 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-AUDIT-CARD'],
        ['amount' => '1.25', 'payment_mode_id' => $wallet->id, 'momo_network' => 'MTN', 'momo_phone' => '0244000000', 'reference' => 'SPLIT-AUDIT-MOMO'],
    ];
    $references = ['SPLIT-AUDIT-CASH', 'SPLIT-AUDIT-CARD', 'MTN · 0244000000 · SPLIT-AUDIT-MOMO'];
    $controller = app(FolioController::class);
    $previewRequest = Request::create('/folios/'.$folio->id.'/payment/preview', 'POST', [
        'type' => 'payment', 'payments' => $lines,
    ], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $preview = $controller->previewPayment($previewRequest, $folio);
    $previewMessage = $preview->getData(true)['message'] ?? '';
    $check('Split tender preview totals the allocations and names all methods', str_contains($previewMessage, '15.25')
        && str_contains($previewMessage, 'Cash') && str_contains($previewMessage, 'Card') && str_contains($previewMessage, 'Money'));
    $check('Preview does not write payment rows', $folio->transactions()->whereIn('reference', $references)->count() === 0);

    $request = Request::create('/folios/'.$folio->id.'/payment', 'POST', [
        'type' => 'payment', 'payments' => $lines,
    ]);
    $response = $controller->payment($request, $folio);
    $entries = $folio->transactions()->whereIn('reference', $references)->orderBy('id')->get();

    $check('The split-tender endpoint returns its normal redirect response', $response instanceof RedirectResponse);
    $check('All payment methods are stored as distinct folio payment entries', $entries->count() === 3 && $entries->every(fn ($tx) => $tx->type === 'payment'));
    $check('Each tender retains its method, amount and reference details', $entries->count() === 3
        && (int) $entries[0]->payment_mode_id === (int) $cash->id && (float) $entries[0]->amount === 10.0 && $entries[0]->reference === 'SPLIT-AUDIT-CASH'
        && (int) $entries[1]->payment_mode_id === (int) $card->id && (float) $entries[1]->amount === 4.0 && $entries[1]->reference === 'SPLIT-AUDIT-CARD'
        && (int) $entries[2]->payment_mode_id === (int) $wallet->id && (float) $entries[2]->amount === 1.25 && $entries[2]->reference === 'MTN · 0244000000 · SPLIT-AUDIT-MOMO');
    $check('The combined tenders settle the folio balance', abs($folio->fresh()->balance()) < 0.005);
    $journals = $entries->map(fn ($tx) => LedgerService::forFolioTx($tx));
    $check('Each tender posts to its own payment-mode account with a balanced journal', $journals->count() === 3
        && (int) $journals[0]['lines'][0][0] === LedgerService::modeAccount((int) $cash->id)
        && (int) $journals[1]['lines'][0][0] === LedgerService::modeAccount((int) $card->id)
        && (int) $journals[2]['lines'][0][0] === LedgerService::modeAccount((int) $wallet->id)
        && collect($journals)->every(fn ($journal) => abs(array_sum(array_column($journal['lines'], 1))) < 0.005));

    $partialFolio = FolioService::openStandalone(['type' => 'walk_in']);
    FolioService::charge($partialFolio, 'misc', 'Partial split audit fixture', 20.00, false);
    $partialLines = [
        ['amount' => '4.00', 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-PARTIAL-CASH'],
        ['amount' => '6.00', 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-PARTIAL-CARD'],
    ];
    $controller->payment(Request::create('/folios/'.$partialFolio->id.'/payment', 'POST', ['type' => 'payment', 'payments' => $partialLines]), $partialFolio);
    $partialEntries = $partialFolio->transactions()->whereIn('reference', ['SPLIT-PARTIAL-CASH', 'SPLIT-PARTIAL-CARD'])->get();
    $check('Folio split tenders can remain partial and leave the remaining balance outstanding', $partialEntries->count() === 2
        && abs($partialFolio->fresh()->balance() - 10.00) < 0.005);

    $offlineStay = ReservationRoom::where('status', 'checked_in')->first();
    $offlineUser = User::where('hotel_id', hotel()->id)->where('is_active', true)->first();
    if (! $offlineStay || ! $offlineUser) {
        throw new RuntimeException('The offline split-payment check needs an in-house stay and active user.');
    }
    $previousUser = auth()->user();
    auth()->login($offlineUser);
    try {
        $offlineFolio = FolioService::openForStay($offlineStay);
        FolioService::charge($offlineFolio, 'misc', 'Offline split audit fixture', 15.25, false);
        $offlineBalanceBefore = $offlineFolio->fresh()->balance();
        $offlineId = 'AUDIT-DESK-SPLIT-'.bin2hex(random_bytes(4));
        $offlinePayload = [
            'stay_id' => $offlineStay->id,
            'payments' => [
                ['amount' => '6.00', 'payment_mode_id' => $cash->id, 'reference' => 'OFFLINE-SPLIT-CASH'],
                ['amount' => '4.00', 'payment_mode_id' => $card->id, 'reference' => 'OFFLINE-SPLIT-CARD'],
            ],
        ];
        $offlineResponse = DesktopSyncService::sync([[
            'operation_id' => $offlineId, 'operation' => 'desk.payment', 'occurred_at' => now()->toIso8601String(), 'payload' => $offlinePayload,
        ]], $offlineUser, 'audit-split-desk');
        $offlineResult = $offlineResponse['results'][0] ?? [];
        $offlineEntries = $offlineFolio->transactions()->whereIn('reference', ['OFFLINE-SPLIT-CASH', 'OFFLINE-SPLIT-CARD'])->orderBy('id')->get();
        $offlineRecord = OfflineSyncOperation::where('operation_id', $offlineId)->first();
        $check('Offline desktop folio payments accept split tenders as separate entries', ($offlineResult['status'] ?? null) === 'applied'
            && count($offlineRecord?->result['transaction_ids'] ?? []) === 2 && $offlineEntries->count() === 2
            && (int) $offlineEntries[0]->payment_mode_id === (int) $cash->id && (int) $offlineEntries[1]->payment_mode_id === (int) $card->id
            && abs($offlineFolio->fresh()->balance() - ($offlineBalanceBefore - 10.00)) < 0.005);
        $offlineDuplicate = DesktopSyncService::sync([[
            'operation_id' => $offlineId, 'operation' => 'desk.payment', 'payload' => $offlinePayload,
        ]], $offlineUser, 'audit-split-desk')['results'][0] ?? [];
        $check('Offline split payment retries remain idempotent', ($offlineDuplicate['status'] ?? null) === 'duplicate'
            && $offlineFolio->transactions()->whereIn('reference', ['OFFLINE-SPLIT-CASH', 'OFFLINE-SPLIT-CARD'])->count() === 2);
        $offlineLegacyId = 'AUDIT-DESK-SINGLE-'.bin2hex(random_bytes(4));
        $offlineLegacy = DesktopSyncService::sync([[
            'operation_id' => $offlineLegacyId, 'operation' => 'desk.payment',
            'payload' => ['stay_id' => $offlineStay->id, 'amount' => 1, 'payment_mode_id' => $cash->id, 'reference' => 'OFFLINE-LEGACY-CASH'],
        ]], $offlineUser, 'audit-split-desk')['results'][0] ?? [];
        $check('Offline desktop legacy single-tender payload remains compatible', ($offlineLegacy['status'] ?? null) === 'applied'
            && $offlineFolio->transactions()->where('reference', 'OFFLINE-LEGACY-CASH')->exists());
        $offlineVoucherMode = \App\Services\GiftVoucherService::modeId();
        $offlineCount = $offlineFolio->transactions()->count();
        $offlineVoucherMix = DesktopSyncService::sync([[
            'operation_id' => 'AUDIT-DESK-VOUCHER-'.bin2hex(random_bytes(3)), 'operation' => 'desk.payment',
            'payload' => ['stay_id' => $offlineStay->id, 'payments' => [
                ['amount' => 1, 'payment_mode_id' => $offlineVoucherMode], ['amount' => 1, 'payment_mode_id' => $cash->id],
            ]],
        ]], $offlineUser, 'audit-split-desk')['results'][0] ?? [];
        $check('Offline desktop split payments prohibit mixing gift-voucher tender', ($offlineVoucherMix['status'] ?? null) === 'rejected'
            && $offlineFolio->transactions()->count() === $offlineCount);
    } finally {
        if ($previousUser) { auth()->login($previousUser); } else { auth()->logout(); }
    }

    $voucherModeId = \App\Services\GiftVoucherService::modeId();
    $voucherPreview = $controller->previewPayment(Request::create('/folios/'.$partialFolio->id.'/payment/preview', 'POST', [
        'type' => 'payment', 'amount' => '1.00', 'payment_mode_id' => $voucherModeId, 'reference' => 'AUDIT-VOUCHER-CODE',
    ], [], [], ['HTTP_ACCEPT' => 'application/json']), $partialFolio);
    $check('Gift vouchers remain available as a single folio tender', str_contains($voucherPreview->getData(true)['message'] ?? '', 'Confirm redeems the voucher code'));
    $folioVoucherMixRejected = false;
    try {
        $controller->previewPayment(Request::create('/folios/'.$partialFolio->id.'/payment/preview', 'POST', [
            'type' => 'payment', 'payments' => [
                ['amount' => '1.00', 'payment_mode_id' => $voucherModeId], ['amount' => '1.00', 'payment_mode_id' => $cash->id],
            ],
        ], [], [], ['HTTP_ACCEPT' => 'application/json']), $partialFolio);
    } catch (\Illuminate\Validation\ValidationException) {
        $folioVoucherMixRejected = true;
    }
    $check('Folio split tender rejects mixing a gift voucher with another method', $folioVoucherMixRejected);

    $reservation = Reservation::query()->orderBy('id')->first();
    if (! $reservation) {
        throw new RuntimeException('The reservation advance split check needs one seeded reservation.');
    }
    $advanceLines = [
        ['amount' => '10.00', 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-ADV-CASH'],
        ['amount' => '5.25', 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-ADV-CARD'],
    ];
    $advanceController = app(ReservationController::class);
    $advanceNormalizer = new ReflectionMethod($advanceController, 'advancePaymentLines');
    $advanceNormalizer->setAccessible(true);
    $legacyCreateAdvance = $advanceNormalizer->invoke($advanceController, Request::create('/reservations', 'POST'), [
        'advance' => '3.75', 'advance_mode' => $cash->id, 'advance_ref' => 'LEGACY-RESERVATION-CREATE',
    ]);
    $check('Reservation creation preserves its legacy single-tender deposit field names', count($legacyCreateAdvance) === 1
        && (float) $legacyCreateAdvance[0]['amount'] === 3.75 && (int) $legacyCreateAdvance[0]['payment_mode_id'] === (int) $cash->id
        && $legacyCreateAdvance[0]['reference'] === 'LEGACY-RESERVATION-CREATE');
    $advancePreviewRequest = Request::create('/reservations/'.$reservation->id.'/advance-preview', 'POST', [
        'payments' => $advanceLines,
    ], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $advancePreview = $advanceController->previewAdvance($advancePreviewRequest, $reservation);
    $advanceMessage = $advancePreview->getData(true)['message'] ?? '';
    $check('Reservation advance preview accepts split allocations', str_contains($advanceMessage, '15.25')
        && str_contains($advanceMessage, 'Cash') && str_contains($advanceMessage, 'Card'));
    $advanceRequest = Request::create('/reservations/'.$reservation->id.'/advance', 'POST', ['payments' => $advanceLines]);
    $advanceController->advance($advanceRequest, $reservation);
    $advanceTx = FolioTransaction::whereIn('reference', ['SPLIT-ADV-CASH', 'SPLIT-ADV-CARD'])->orderBy('id')->get();
    $check('Reservation advances post as separate methods in one atomic action', $advanceTx->count() === 2
        && $advanceTx->every(fn ($tx) => $tx->type === 'payment' && $tx->category === 'advance')
        && (int) $advanceTx[0]->payment_mode_id === (int) $cash->id && (int) $advanceTx[1]->payment_mode_id === (int) $card->id);

    $frontDesk = app(FrontDeskController::class);
    $normalizer = new ReflectionMethod($frontDesk, 'advancePaymentLines');
    $normalizer->setAccessible(true);
    $depositLines = $normalizer->invoke($frontDesk, ['payments' => [
        ['amount' => '8.00', 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-DEPOSIT-CASH'],
        ['amount' => '5.00', 'payment_mode_id' => $wallet->id, 'momo_network' => 'MTN', 'momo_phone' => '0244000000', 'reference' => 'SPLIT-DEPOSIT-MOMO'],
        ['amount' => '2.25', 'payment_mode_id' => $card->id, 'cheque_bank_name' => 'Audit Bank', 'cheque_number' => '000123', 'cheque_bank_branch' => 'Accra'],
    ]]);
    $check('Check-in and walk-in deposit normalization preserves split, MoMo and cheque details', count($depositLines) === 3
        && $depositLines[1]['reference'] === 'MTN · 0244000000 · SPLIT-DEPOSIT-MOMO'
        && $depositLines[2]['cheque_bank_name'] === 'Audit Bank' && $depositLines[2]['cheque_number'] === '000123');
    $legacyDeposit = $normalizer->invoke($frontDesk, [
        'advance' => '3.50', 'advance_mode' => $card->id, 'advance_ref' => 'LEGACY-DEPOSIT',
    ]);
    $check('Legacy single-tender check-in and walk-in deposits remain accepted', count($legacyDeposit) === 1
        && (float) $legacyDeposit[0]['amount'] === 3.5 && (int) $legacyDeposit[0]['payment_mode_id'] === (int) $card->id
        && $legacyDeposit[0]['reference'] === 'LEGACY-DEPOSIT');
    $checkoutAmount = new ReflectionMethod($frontDesk, 'checkoutSettlementAmount');
    $checkoutAmount->setAccessible(true);
    $checkoutExact = $checkoutAmount->invoke($frontDesk, 15.25, '15.25');
    $checkoutDefault = $checkoutAmount->invoke($frontDesk, 15.25, null);
    $checkoutPartialRejected = false;
    try {
        $checkoutAmount->invoke($frontDesk, 15.25, '10.00');
    } catch (\Illuminate\Validation\ValidationException) {
        $checkoutPartialRejected = true;
    }
    $check('Single-method checkout must clear the full balance; partial amount is rejected', $checkoutExact === 15.25
        && $checkoutDefault === 15.25 && $checkoutPartialRejected);
    $checkoutFolio = FolioService::openStandalone(['type' => 'walk_in']);
    FolioService::charge($checkoutFolio, 'misc', 'Split checkout audit fixture', 15.25, false);
    $checkoutPoster = new ReflectionMethod($frontDesk, 'postCheckoutPaymentAllocations');
    $checkoutPoster->setAccessible(true);
    $checkoutLines = [
        ['amount' => '10.00', 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-CHECKOUT-CASH'],
        ['amount' => '4.00', 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-CHECKOUT-CARD', 'cheque_bank_name' => 'Audit Bank', 'cheque_number' => '123456'],
        ['amount' => '1.25', 'payment_mode_id' => $wallet->id, 'momo_network' => 'MTN', 'momo_phone' => '0244000000', 'reference' => 'SPLIT-CHECKOUT-MOMO'],
    ];
    $checkoutPoster->invoke($frontDesk, $checkoutFolio, $checkoutLines, 15.25, 'payment');
    $checkoutTx = $checkoutFolio->transactions()->where('category', 'settlement')->where('type', 'payment')->orderBy('id')->get();
    $check('Split checkout posts each tender separately and retains MoMo reference details', $checkoutTx->count() === 3
        && (int) $checkoutTx[0]->payment_mode_id === (int) $cash->id && (float) $checkoutTx[0]->amount === 10.0
        && (int) $checkoutTx[1]->payment_mode_id === (int) $card->id && (float) $checkoutTx[1]->amount === 4.0
        && (int) $checkoutTx[2]->payment_mode_id === (int) $wallet->id && (float) $checkoutTx[2]->amount === 1.25
        && $checkoutTx[2]->reference === 'MTN · 0244000000 · SPLIT-CHECKOUT-MOMO'
        && abs($checkoutFolio->fresh()->balance()) < 0.005);
    $roundingFolio = FolioService::openStandalone(['type' => 'walk_in']);
    FolioService::charge($roundingFolio, 'misc', 'Checkout rounding fixture', 15.02, false);
    $checkoutPoster->invoke($frontDesk, $roundingFolio, [
        ['amount' => '10.005', 'payment_mode_id' => $cash->id], ['amount' => '5.005', 'payment_mode_id' => $card->id],
    ], 15.02, 'payment');
    $check('Checkout allocation totals use the same per-tender cents rounding as posted payments',
        abs($roundingFolio->fresh()->balance()) < 0.005);
    $mismatchFolio = FolioService::openStandalone(['type' => 'walk_in']);
    FolioService::charge($mismatchFolio, 'misc', 'Checkout mismatch fixture', 15.25, false);
    $checkoutMismatchRejected = false;
    try {
        $checkoutPoster->invoke($frontDesk, $mismatchFolio, [
            ['amount' => 10.00, 'payment_mode_id' => $cash->id], ['amount' => 4.00, 'payment_mode_id' => $card->id],
        ], 15.25, 'payment');
    } catch (\Illuminate\Validation\ValidationException) {
        $checkoutMismatchRejected = true;
    }
    $check('Split checkout rejects allocations that do not equal the full due before posting', $checkoutMismatchRejected
        && $mismatchFolio->transactions()->where('category', 'settlement')->doesntExist());
    $voucherModeId = \App\Services\GiftVoucherService::modeId();
    $voucherMixRejected = false;
    try {
        $checkoutPoster->invoke($frontDesk, $mismatchFolio, [
            ['amount' => 10.00, 'payment_mode_id' => $voucherModeId], ['amount' => 5.25, 'payment_mode_id' => $cash->id],
        ], 15.25, 'payment');
    } catch (\Illuminate\Validation\ValidationException) {
        $voucherMixRejected = true;
    }
    $check('Checkout continues to restrict gift vouchers to a single tender', $voucherMixRejected);
    $refundFolio = FolioService::openStandalone(['type' => 'walk_in']);
    FolioService::payment($refundFolio, 15.25, (int) $cash->id, 'SPLIT-CHECKOUT-REFUND-SEED', 'advance', 'payment');
    $checkoutPoster->invoke($frontDesk, $refundFolio, [
        ['amount' => 5.25, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-CHECKOUT-REFUND-CASH'],
        ['amount' => 10.00, 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-CHECKOUT-REFUND-CARD'],
    ], 15.25, 'refund');
    $refundRows = $refundFolio->transactions()->where('category', 'settlement')->where('type', 'refund')->get();
    $check('Split checkout refund allocations also clear the full credit', $refundRows->count() === 2
        && abs($refundFolio->fresh()->balance()) < 0.005);

    $depositFolio = FolioService::openStandalone(['type' => 'walk_in']);
    foreach ($depositLines as $line) {
        FolioService::payment($depositFolio, (float) $line['amount'], (int) $line['payment_mode_id'], $line['reference'] ?? null, 'advance', 'payment', [
            'bank_name' => $line['cheque_bank_name'] ?? null, 'number' => $line['cheque_number'] ?? null, 'bank_branch' => $line['cheque_bank_branch'] ?? null,
        ]);
    }
    $depositTx = $depositFolio->transactions()->where('category', 'advance')->orderBy('id')->get();
    $check('Normalized deposits persist as distinct advance tenders', $depositTx->count() === 3
        && $depositTx->every(fn ($tx) => $tx->type === 'payment')
        && (int) $depositTx[0]->payment_mode_id === (int) $cash->id
        && (int) $depositTx[1]->payment_mode_id === (int) $wallet->id
        && (int) $depositTx[2]->payment_mode_id === (int) $card->id);
    $check('Reservation creation exposes the shared split-deposit widget', str_contains(file_get_contents(base_path('resources/views/reservations/form.blade.php')), 'reservationAdvanceSplit')
        && str_contains(file_get_contents(base_path('app/Http/Controllers/ReservationController.php')), 'foreach ($advanceLines as $line)'));

    $event = BanquetEvent::whereIn('status', ['enquiry', 'tentative', 'definite'])->whereNull('billed_at')->first();
    if (! $event) {
        throw new RuntimeException('The banquet deposit split check needs one open, unbilled event.');
    }
    $banquetLines = BanquetSalesService::takeDeposits($event, [
        ['amount' => 7.00, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-BANQUET-CASH'],
        ['amount' => 8.25, 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-BANQUET-CARD'],
    ]);
    $check('Banquet deposits persist as separate folio advances', $banquetLines->count() === 2
        && $banquetLines->every(fn ($tx) => $tx->type === 'payment' && $tx->category === 'advance' && $tx->source_type === 'banquet')
        && (int) $banquetLines[0]->payment_mode_id === (int) $cash->id && (int) $banquetLines[1]->payment_mode_id === (int) $card->id
        && $banquetLines[0]->reference === 'SPLIT-BANQUET-CASH' && $banquetLines[1]->reference === 'SPLIT-BANQUET-CARD');
    $banquetLegacy = BanquetSalesService::takeDeposit($event, 2.00, (int) $cash->id, 'LEGACY-BANQUET-DEPOSIT');
    $check('Banquet single-tender deposit service remains backwards compatible', $banquetLegacy->type === 'payment'
        && $banquetLegacy->category === 'advance' && $banquetLegacy->reference === 'LEGACY-BANQUET-DEPOSIT');
    $check('Banquet deposit form is wired to the shared split-tender widget', str_contains(file_get_contents(base_path('resources/views/banquet/show.blade.php')), 'banquetDepositSplit')
        && str_contains(file_get_contents(base_path('app/Http/Controllers/BanquetController.php')), 'Sales::takeDeposits($event, $lines)'));

    $spaAppointment = SpaAppointment::whereIn('status', ['booked', 'in_progress'])->orderBy('id')->first();
    if (! $spaAppointment) {
        throw new RuntimeException('The spa split-settlement check needs one open appointment.');
    }
    Setting::updateOrCreate(['key' => 'gst_einvoice_auto'], ['value' => '0']);
    $spaNet = (float) $spaAppointment->net();
    $spaTax = TaxService::forCategory('spa', $spaNet);
    $spaGross = round($spaNet + (float) $spaTax['amount'], 2);
    $spaFirst = round($spaGross / 2, 2);
    $spaDone = SpaBookingService::settle($spaAppointment, 'payment', null, null, null, [
        ['amount' => $spaFirst, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-SPA-CASH'],
        ['amount' => round($spaGross - $spaFirst, 2), 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-SPA-CARD'],
    ]);
    $spaFolio = Folio::find($spaDone->folio_id);
    $spaPayments = $spaFolio?->transactions()->where('category', 'settlement')->where('type', 'payment')->orderBy('id')->get();
    $check('Spa direct-payment settlement posts split methods and closes a fully paid folio', $spaDone->status === 'completed'
        && $spaFolio?->status === 'closed' && $spaPayments?->count() === 2
        && (int) $spaPayments[0]->payment_mode_id === (int) $cash->id && (int) $spaPayments[1]->payment_mode_id === (int) $card->id
        && $spaPayments[0]->reference === 'SPLIT-SPA-CASH' && $spaPayments[1]->reference === 'SPLIT-SPA-CARD'
        && abs($spaFolio->balance()) < 0.005);
    $check('Spa direct-settlement form includes split tender while direct voucher settlement stays prohibited', str_contains(file_get_contents(base_path('resources/views/spa/index.blade.php')), 'spaSplitTender')
        && str_contains(file_get_contents(base_path('app/Services/SpaBookingService.php')), 'GiftVoucherService::isVoucherMode'));

    $transportTrip = TransportTrip::whereIn('status', ['assigned', 'en_route', 'onboard'])->orderBy('id')->first();
    if (! $transportTrip) {
        throw new RuntimeException('The transport split-settlement check needs one active trip.');
    }
    $transportTrip->forceFill(['billing' => 'direct', 'amount' => 100.00, 'passenger_name' => 'Split Tender Audit Guest', 'phone' => '0244000000'])->save();
    $transportTax = TaxService::forCategory('transport', 100.00);
    $transportGross = round(100.00 + (float) $transportTax['amount'], 2);
    $transportFirst = round($transportGross / 2, 2);
    $transportDone = TransportService::complete($transportTrip, ['payments' => [
        ['amount' => $transportFirst, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-TRANSPORT-CASH'],
        ['amount' => round($transportGross - $transportFirst, 2), 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-TRANSPORT-CARD'],
    ]]);
    $transportFolio = Folio::find($transportDone->folio_id);
    $transportPayments = $transportFolio?->transactions()->where('category', 'settlement')->where('type', 'payment')->orderBy('id')->get();
    $check('Transport direct completion posts split tenders to its closed invoice folio', $transportDone->status === 'completed'
        && $transportFolio?->status === 'closed' && $transportPayments?->count() === 2
        && (int) $transportPayments[0]->payment_mode_id === (int) $cash->id && (int) $transportPayments[1]->payment_mode_id === (int) $card->id
        && $transportDone->payment_mode_id === null && abs($transportFolio->balance()) < 0.005);
    $check('Dispatcher and driver transport completion forms expose split tender', str_contains(file_get_contents(base_path('resources/views/transport/show.blade.php')), 'transportSplitTender-')
        && str_contains(file_get_contents(base_path('resources/views/transport/my.blade.php')), 'driverTransportSplit-'));

    $laundrySource = LaundryOrder::whereIn('status', ['processing', 'ready'])->whereNull('folio_transaction_id')->first();
    if (! $laundrySource) {
        throw new RuntimeException('The laundry split-settlement check needs one open, uncharged order.');
    }
    Setting::updateOrCreate(['key' => 'gst_einvoice_auto'], ['value' => '0']);
    $laundryRows = [];
    foreach (['SPLIT', 'SINGLE'] as $suffix) {
        $clone = $laundrySource->replicate();
        $clone->forceFill([
            'order_no' => $laundrySource->order_no.'-'.$suffix, 'type' => 'walk_in', 'guest_name' => 'Split Tender Audit Guest',
            'phone' => '0244000000', 'reservation_room_id' => null, 'guest_request_id' => null, 'status' => 'ready',
            'folio_id' => null, 'folio_transaction_id' => null, 'payment_mode_id' => null,
        ])->save();
        $laundryRows[$suffix] = $clone;
    }
    $laundryTax = TaxService::forCategory('laundry', (float) $laundryRows['SPLIT']->total);
    $laundryGross = round((float) $laundryRows['SPLIT']->total + (float) $laundryTax['amount'], 2);
    $laundryFirst = round($laundryGross / 2, 2);
    $voucherLaundryRejected = false;
    try {
        LaundryService::deliver($laundryRows['SPLIT'], null, null, null, [
            ['amount' => $laundryFirst, 'payment_mode_id' => \App\Services\GiftVoucherService::modeId()],
            ['amount' => round($laundryGross - $laundryFirst, 2), 'payment_mode_id' => $cash->id],
        ]);
    } catch (\Illuminate\Validation\ValidationException) {
        $voucherLaundryRejected = true;
    }
    $check('Laundry direct split settlement continues to prohibit gift-voucher tender', $voucherLaundryRejected
        && $laundryRows['SPLIT']->fresh()->folio_transaction_id === null);
    $laundryDone = LaundryService::deliver($laundryRows['SPLIT'], null, null, null, [
        ['amount' => $laundryFirst, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-LAUNDRY-CASH'],
        ['amount' => round($laundryGross - $laundryFirst, 2), 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-LAUNDRY-CARD'],
    ]);
    $laundryFolio = Folio::find($laundryDone->folio_id);
    $laundryPayments = $laundryFolio?->transactions()->where('category', 'settlement')->where('type', 'payment')->orderBy('id')->get();
    $check('Laundry walk-in delivery posts separate split tenders and closes the settled invoice', $laundryDone->status === 'delivered'
        && $laundryFolio?->status === 'closed' && $laundryPayments?->count() === 2
        && (int) $laundryPayments[0]->payment_mode_id === (int) $cash->id && (int) $laundryPayments[1]->payment_mode_id === (int) $card->id
        && $laundryDone->payment_mode_id === null && $laundryPayments[0]->reference === 'SPLIT-LAUNDRY-CASH'
        && $laundryPayments[1]->reference === 'SPLIT-LAUNDRY-CARD' && abs($laundryFolio->balance()) < 0.005);
    $laundrySingle = LaundryService::deliver($laundryRows['SINGLE'], (int) $cash->id, 'SINGLE-LAUNDRY-CASH');
    $singleLaundryFolio = Folio::find($laundrySingle->folio_id);
    $singleLaundryPayment = $singleLaundryFolio?->transactions()->where('category', 'settlement')->where('type', 'payment')->first();
    $check('Laundry legacy single-tender direct delivery remains compatible', $laundrySingle->status === 'delivered'
        && $laundrySingle->payment_mode_id === (int) $cash->id && $singleLaundryPayment?->reference === 'SINGLE-LAUNDRY-CASH'
        && abs($singleLaundryFolio->balance()) < 0.005);
    $check('Laundry walk-in delivery renders the shared split widget and retains the legacy single tender',
        str_contains(file_get_contents(base_path('resources/views/laundry/show.blade.php')), 'laundrySplitTender-')
        && str_contains(file_get_contents(base_path('app/Services/LaundryService.php')), 'FolioService::payment($folio, $amountDue, (int) $paymentModeId'));

    $clubPassTotal = ClubBillingService::tax(30.00)['total'];
    $clubPassFirst = round($clubPassTotal / 2, 2);
    $clubRoomSplitRejected = false;
    $clubRoomNo = $offlineStay->loadMissing('room')->room?->room_no;
    try {
        ClubService::sellDayPass([
            'name' => 'Room Split Audit Pass', 'adults' => 1, 'amount' => 30.00, 'room_no' => $clubRoomNo,
            'payments' => [
                ['amount' => $clubPassFirst, 'payment_mode_id' => $cash->id],
                ['amount' => round($clubPassTotal - $clubPassFirst, 2), 'payment_mode_id' => $card->id],
            ],
        ]);
    } catch (\Illuminate\Validation\ValidationException) {
        $clubRoomSplitRejected = true;
    }
    $check('Club day-pass room billing remains a room-folio charge rather than a direct split payment', $clubRoomSplitRejected
        && ClubDayPass::where('name', 'Room Split Audit Pass')->doesntExist());
    $clubVoucherRejected = false;
    try {
        ClubService::sellDayPass([
            'name' => 'Voucher Mix Audit Pass', 'adults' => 1, 'amount' => 30.00,
            'payments' => [
                ['amount' => $clubPassFirst, 'payment_mode_id' => \App\Services\GiftVoucherService::modeId()],
                ['amount' => round($clubPassTotal - $clubPassFirst, 2), 'payment_mode_id' => $cash->id],
            ],
        ]);
    } catch (\Illuminate\Validation\ValidationException) {
        $clubVoucherRejected = true;
    }
    $check('Classic club day-pass split tender excludes gift vouchers', $clubVoucherRejected
        && ClubDayPass::where('name', 'Voucher Mix Audit Pass')->doesntExist());
    $clubPass = ClubService::sellDayPass([
        'name' => 'Split Tender Audit Pass', 'adults' => 1, 'amount' => 30.00,
        'payments' => [
            ['amount' => $clubPassFirst, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-CLUB-PASS-CASH'],
            ['amount' => round($clubPassTotal - $clubPassFirst, 2), 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-CLUB-PASS-CARD'],
        ],
    ]);
    $clubPassPayments = $clubPass->payments()->with('paymentMode')->orderBy('id')->get();
    $clubPassJournal = ClubBillingService::forDayPass($clubPass);
    $check('Classic club day-pass sale persists separate tenders and remains ledger-balanced', $clubPass->payment_mode_id === null
        && $clubPassPayments->count() === 2 && (int) $clubPassPayments[0]->payment_mode_id === (int) $cash->id
        && (int) $clubPassPayments[1]->payment_mode_id === (int) $card->id
        && $clubPassPayments[0]->reference === 'SPLIT-CLUB-PASS-CASH' && $clubPassPayments[1]->reference === 'SPLIT-CLUB-PASS-CARD'
        && count($clubPassJournal['lines'] ?? []) >= 3 && abs(array_sum(array_column($clubPassJournal['lines'], 1))) < 0.005);
    $clubPassSingle = ClubService::sellDayPass(['name' => 'Legacy Club Pass Audit', 'adults' => 1, 'amount' => 30.00, 'payment_mode_id' => $cash->id]);
    $check('Classic club day-pass legacy single-tender sale remains compatible', (int) $clubPassSingle->payment_mode_id === (int) $cash->id
        && $clubPassSingle->payments()->doesntExist() && abs(array_sum(array_column(ClubBillingService::forDayPass($clubPassSingle)['lines'], 1))) < 0.005);
    $check('Classic club day-pass form includes the shared split-tender widget', str_contains(file_get_contents(base_path('resources/views/club/passes.blade.php')), 'clubDayPassSplitTender')
        && str_contains(file_get_contents(base_path('app/Services/ClubService.php')), 'Tender allocations must total'));
    $check('Member guest check-in forwards split guest-pass payments while preserving account billing',
        str_contains(file_get_contents(base_path('resources/views/club/desk.blade.php')), 'clubGuestSplitTender')
        && str_contains(file_get_contents(base_path('app/Http/Controllers/ClubController.php')), '$payments, $reference ?: null')
        && str_contains(file_get_contents(base_path('app/Services/ClubService.php')), 'guest_pass'));
    $clubHost = ClubMember::with('membership.plan')->whereHas('membership')->get()->first(function ($member) {
        return ClubService::guestPassesLeft($member->membership) <= 0 && ! ClubService::access($member, 'gym')['reasons'];
    });
    if (! $clubHost) {
        throw new RuntimeException('The paid member-guest split check needs an eligible member with no free guest passes.');
    }
    $clubMembership = $clubHost->membership;
    ClubBillingService::raise($clubMembership, 'other', 'Split receipt audit charge', 50.00, ['tax_rate' => 0]);
    $accountBalanceBeforeSplit = ClubBillingService::balance($clubMembership);
    $clubReceipts = ClubBillingService::payMany($clubMembership, [
        ['amount' => 4.25, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-CLUB-ACCOUNT-CASH'],
        ['amount' => 5.75, 'payment_mode_id' => $card->id, 'momo_network' => 'MTN', 'momo_phone' => '0244000000', 'reference' => 'SPLIT-CLUB-ACCOUNT-CARD'],
    ]);
    $accountBalanceAfterSplit = ClubBillingService::balance($clubMembership);
    $clubPaymentJournals = collect($clubReceipts)->map(fn ($receipt) => ClubBillingService::forPayment($receipt));
    $check('Club membership account accepts partial split receipts and keeps each tender separate', count($clubReceipts) === 2
        && abs(array_sum(array_map(fn ($receipt) => (float) $receipt->amount, $clubReceipts)) - 10.00) < 0.005
        && abs(($accountBalanceBeforeSplit - $accountBalanceAfterSplit) - 10.00) < 0.005
        && $clubReceipts[0]->reference === 'SPLIT-CLUB-ACCOUNT-CASH'
        && $clubReceipts[1]->reference === 'MTN · 0244000000 · SPLIT-CLUB-ACCOUNT-CARD');
    $check('Club membership split receipts post balanced ledger entries to each tender account', count($clubPaymentJournals) === 2
        && (int) $clubPaymentJournals[0]['lines'][0][0] === LedgerService::modeAccount((int) $cash->id)
        && (int) $clubPaymentJournals[1]['lines'][0][0] === LedgerService::modeAccount((int) $card->id)
        && $clubPaymentJournals->every(fn ($journal) => abs(array_sum(array_column($journal['lines'], 1))) < 0.005));
    $clubAccountVoucherRejected = false;
    try {
        ClubBillingService::payMany($clubMembership, [
            ['amount' => 1.00, 'payment_mode_id' => \App\Services\GiftVoucherService::modeId()],
            ['amount' => 1.00, 'payment_mode_id' => $cash->id],
        ]);
    } catch (\Illuminate\Validation\ValidationException $e) {
        $clubAccountVoucherRejected = true;
    }
    $check('Club membership split receipts reject gift vouchers', $clubAccountVoucherRejected
        && $clubMembership->payments()->where('reference', 'SPLIT-CLUB-ACCOUNT-CASH')->count() === 1);
    $legacyClubReceipt = ClubBillingService::pay($clubMembership, 1.00, (int) $cash->id, 'LEGACY-CLUB-ACCOUNT-PAYMENT');
    $legacyClubJournal = ClubBillingService::forPayment($legacyClubReceipt);
    $check('Club membership legacy single-tender receipts remain compatible', $legacyClubReceipt->kind === 'payment'
        && $legacyClubReceipt->reference === 'LEGACY-CLUB-ACCOUNT-PAYMENT'
        && abs(array_sum(array_column($legacyClubJournal['lines'] ?? [], 1))) < 0.005);
    $check('Club membership receipt form exposes shared split tenders while refund remains single-mode', str_contains(file_get_contents(base_path('resources/views/club/show.blade.php')), 'club-payment-split')
        && str_contains(file_get_contents(base_path('app/Http/Controllers/ClubBillingController.php')), 'ClubBillingService::payMany')
        && str_contains(file_get_contents(base_path('resources/views/club/show.blade.php')), 'refundModal'));
    $clubPlan = \App\Models\ClubPlan::where('is_active', true)->where('category', '!=', 'corporate')->firstOrFail();
    $splitEnrolment = ClubService::enrol([
        'club_plan_id' => $clubPlan->id, 'holder_name' => 'Split Enrolment Audit', 'start_date' => bdate()->toDateString(),
        'payment' => ['payments' => [
            ['amount' => 3.00, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-CLUB-ENROL-CASH'],
            ['amount' => 2.00, 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-CLUB-ENROL-CARD'],
        ]],
    ]);
    $enrolReceipts = $splitEnrolment->payments()->orderBy('id')->get();
    $check('Club enrolment can accept and retain an optional split payment', $enrolReceipts->count() === 2
        && abs((float) $enrolReceipts->sum('amount') - 5.00) < 0.005
        && $enrolReceipts[0]->reference === 'SPLIT-CLUB-ENROL-CASH'
        && $enrolReceipts[1]->reference === 'SPLIT-CLUB-ENROL-CARD');
    $splitEnrolment->update(['end_date' => bdate()->toDateString()]);
    $renewalCharge = ClubService::renew($splitEnrolment->fresh('plan'), null, bdate()->toDateString());
    $renewalReceipts = ClubBillingService::payMany($splitEnrolment, [
        ['amount' => 3.50, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-CLUB-RENEW-CASH'],
        ['amount' => 1.50, 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-CLUB-RENEW-CARD'],
    ]);
    $check('Club renewal invoice supports split payments without losing its charge or receipt ledger entries', $renewalCharge->exists
        && count($renewalReceipts) === 2 && abs(array_sum(array_map(fn ($receipt) => (float) $receipt->amount, $renewalReceipts)) - 5.00) < 0.005
        && collect($renewalReceipts)->every(fn ($receipt) => abs(array_sum(array_column(ClubBillingService::forPayment($receipt)['lines'], 1))) < 0.005));
    $check('Club enrolment and renewal forms both expose split tender rows', str_contains(file_get_contents(base_path('resources/views/club/enrol.blade.php')), 'club-enrolment-split')
        && str_contains(file_get_contents(base_path('resources/views/club/show.blade.php')), 'club-renewal-split'));
    $lease = \App\Models\Lease::where('status', 'active')->first();
    if (! $lease) {
        throw new RuntimeException('The lease split-tender check needs an active lease.');
    }
    \App\Services\LeaseBillingService::raise($lease, 'other', 'Split tender audit charge', 50.00, ['tax_rate' => 0]);
    $leaseBalanceBefore = \App\Services\LeaseBillingService::balance($lease);
    $leaseReceipts = \App\Services\LeaseBillingService::payMany($lease, 'payment', [
        ['amount' => 4.00, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-LEASE-CASH'],
        ['amount' => 6.00, 'payment_mode_id' => $card->id, 'momo_network' => 'MTN', 'momo_phone' => '0244000000', 'reference' => 'SPLIT-LEASE-CARD'],
    ]);
    $leaseBalanceAfter = \App\Services\LeaseBillingService::balance($lease);
    $leaseJournals = collect($leaseReceipts)->map(fn ($receipt) => \App\Services\LeaseBillingService::forPayment($receipt));
    $check('Lease rent receipts accept partial split tenders and retain distinct tender details', count($leaseReceipts) === 2
        && abs(($leaseBalanceBefore - $leaseBalanceAfter) - 10.00) < 0.005
        && $leaseReceipts[0]->reference === 'SPLIT-LEASE-CASH'
        && $leaseReceipts[1]->reference === 'MTN · 0244000000 · SPLIT-LEASE-CARD');
    $check('Lease split receipts post balanced ledger entries to each payment-mode account', count($leaseJournals) === 2
        && (int) $leaseJournals[0]['lines'][0][0] === LedgerService::modeAccount((int) $cash->id)
        && (int) $leaseJournals[1]['lines'][0][0] === LedgerService::modeAccount((int) $card->id)
        && $leaseJournals->every(fn ($journal) => abs(array_sum(array_column($journal['lines'], 1))) < 0.005));
    $depositHeldBeforeSplit = \App\Services\LeaseBillingService::depositHeld($lease);
    $lease->update(['deposit_amount' => $depositHeldBeforeSplit + 10.00]);
    $depositReceipts = \App\Services\LeaseBillingService::payMany($lease, 'deposit', [
        ['amount' => 4.00, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-LEASE-DEPOSIT-CASH'],
        ['amount' => 6.00, 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-LEASE-DEPOSIT-CARD'],
    ]);
    $check('Lease security deposits also preserve split tender rows and the deposit-held balance', count($depositReceipts) === 2
        && collect($depositReceipts)->every(fn ($receipt) => $receipt->kind === 'deposit')
        && abs((\App\Services\LeaseBillingService::depositHeld($lease) - $depositHeldBeforeSplit) - 10.00) < 0.005);
    $leaseVoucherRejected = false;
    try {
        \App\Services\LeaseBillingService::payMany($lease, 'payment', [
            ['amount' => 1.00, 'payment_mode_id' => \App\Services\GiftVoucherService::modeId()],
            ['amount' => 1.00, 'payment_mode_id' => $cash->id],
        ]);
    } catch (\Illuminate\Validation\ValidationException) {
        $leaseVoucherRejected = true;
    }
    $leaseRefundSplitRejected = false;
    try {
        \App\Services\LeaseBillingService::payMany($lease, 'refund', [
            ['amount' => 1.00, 'payment_mode_id' => $cash->id], ['amount' => 1.00, 'payment_mode_id' => $card->id],
        ]);
    } catch (\Illuminate\Validation\ValidationException) {
        $leaseRefundSplitRejected = true;
    }
    $check('Lease split receipts exclude gift vouchers and split refunds', $leaseVoucherRejected && $leaseRefundSplitRejected);
    $check('Lease direct-receipt form offers split tenders while preserving single-tender WHT and refunds', str_contains(file_get_contents(base_path('resources/views/leases/show.blade.php')), 'lease-payment-split')
        && str_contains(file_get_contents(base_path('resources/views/leases/show.blade.php')), 'Record withholding tax with one tender.')
        && str_contains(file_get_contents(base_path('app/Http/Controllers/LeaseBillingController.php')), 'Record WHT with one tender.')
        && str_contains(file_get_contents(base_path('resources/views/leases/show.blade.php')), 'refundModal'));
    $company = \App\Models\Company::first();
    if (! $company) {
        throw new RuntimeException('The city-ledger split-receipt check needs a company.');
    }
    $cityReceiptController = app(\App\Http\Controllers\AccountsController::class);
    $cityLines = [
        ['amount' => 4.00, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-CITY-LEDGER-CASH'],
        ['amount' => 6.00, 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-CITY-LEDGER-CARD'],
    ];
    $cityResponse = $cityReceiptController->companyReceipt(Request::create('/accounts/company/'.$company->id.'/receipt', 'POST', [
        'date' => bdate()->toDateString(), 'payments' => $cityLines,
    ]), $company);
    $cityReceipts = \App\Models\Voucher::where('company_id', $company->id)->whereIn('reference', ['SPLIT-CITY-LEDGER-CASH', 'SPLIT-CITY-LEDGER-CARD'])->orderBy('id')->get();
    $check('City-ledger company receipts accept split tenders as separate vouchers', $cityResponse instanceof RedirectResponse
        && $cityReceipts->count() === 2 && (float) $cityReceipts->sum('amount') === 10.0
        && (int) $cityReceipts[0]->payment_mode_id === (int) $cash->id
        && (int) $cityReceipts[1]->payment_mode_id === (int) $card->id);
    $cityVoucherRejected = false;
    try {
        $cityReceiptController->companyReceipt(Request::create('/accounts/company/'.$company->id.'/receipt', 'POST', [
            'date' => bdate()->toDateString(), 'payments' => [
                ['amount' => 1.00, 'payment_mode_id' => \App\Services\GiftVoucherService::modeId()],
                ['amount' => 1.00, 'payment_mode_id' => $cash->id],
            ],
        ]), $company);
    } catch (\Illuminate\Validation\ValidationException) {
        $cityVoucherRejected = true;
    }
    $check('City-ledger split receipts reject gift vouchers and preserve the company receivable', $cityVoucherRejected
        && \App\Models\Voucher::where('company_id', $company->id)->whereIn('reference', ['SPLIT-CITY-LEDGER-CASH', 'SPLIT-CITY-LEDGER-CARD'])->count() === 2);
    $check('City-ledger company receipt form includes split tender entry', str_contains(file_get_contents(base_path('resources/views/accounts/company.blade.php')), 'city-ledger-receipt-split'));
    $guestPassTotal = ClubBillingService::tax((float) ClubService::cfg('club_guestpass'))['total'];
    $guestPassFirst = round($guestPassTotal / 2, 2);
    $guestVisit = ClubService::checkInGuest($clubHost, 'Split Guest Audit', 'gym', null, null, null, [
        ['amount' => $guestPassFirst, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-GUESTPASS-CASH'],
        ['amount' => round($guestPassTotal - $guestPassFirst, 2), 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-GUESTPASS-CARD'],
    ], 'SPLIT-GUESTPASS');
    $guestPass = ClubDayPass::with('payments.paymentMode')->find($guestVisit->club_day_pass_id);
    $check('Paid member-guest check-in uses split tenders when the free allowance is exhausted', $guestVisit->visitor_type === 'guest'
        && $guestPass && $guestPass->payment_mode_id === null && $guestPass->payments->count() === 2
        && (int) $guestPass->payments[0]->payment_mode_id === (int) $cash->id
        && (int) $guestPass->payments[1]->payment_mode_id === (int) $card->id
        && $guestPass->payments[0]->reference === 'SPLIT-GUESTPASS-CASH'
        && $guestPass->payments[1]->reference === 'SPLIT-GUESTPASS-CARD');

    RecreationService::ensureTypes();
    $ticketType = ClubTicketType::where('code', 'ADULT')->where('is_active', true)->first();
    if (! $ticketType) {
        throw new RuntimeException('The recreation voucher check needs an active adult ticket type.');
    }
    $ticketTotal = ClubBillingService::tax((float) $ticketType->price, $ticketType->tax_rate !== null ? (float) $ticketType->tax_rate : null)['total'];
    $ticketHalf = round($ticketTotal / 2, 2);
    $recreationVoucherRejected = false;
    try {
        RecreationService::sell([
            'guest_name' => 'Recreation Voucher Mix Audit', 'items' => [['type_id' => $ticketType->id, 'qty' => 1]],
            'payments' => [
                ['amount' => $ticketHalf, 'payment_mode_id' => \App\Services\GiftVoucherService::modeId()],
                ['amount' => round($ticketTotal - $ticketHalf, 2), 'payment_mode_id' => $cash->id],
            ],
        ]);
    } catch (\Illuminate\Validation\ValidationException) {
        $recreationVoucherRejected = true;
    }
    $check('Recreation ticket sales do not accept gift vouchers as split tenders', $recreationVoucherRejected
        && \App\Models\ClubTicketSale::where('guest_name', 'Recreation Voucher Mix Audit')->doesntExist());
    $ticketSale = RecreationService::sell([
        'guest_name' => 'Split Recreation Audit Guest', 'items' => [['type_id' => $ticketType->id, 'qty' => 1]],
        'payments' => [
            ['amount' => $ticketHalf, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-RECREATION-CASH'],
            ['amount' => round($ticketTotal - $ticketHalf, 2), 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-RECREATION-CARD'],
        ],
    ]);
    $ticketPayments = $ticketSale->payments;
    $ticketJournal = ClubBillingService::forTicketSale($ticketSale);
    $check('Recreation split payments remain supported with separate ledger tenders', $ticketSale->status === 'paid'
        && $ticketPayments->count() === 2 && (int) $ticketPayments[0]->payment_mode_id === (int) $cash->id
        && (int) $ticketPayments[1]->payment_mode_id === (int) $card->id
        && $ticketPayments[0]->reference === 'SPLIT-RECREATION-CASH' && $ticketPayments[1]->reference === 'SPLIT-RECREATION-CARD'
        && abs(array_sum(array_column($ticketJournal['lines'] ?? [], 1))) < 0.005);
    $check('Recreation till only offers active direct tender methods', str_contains(file_get_contents(base_path('app/Http/Controllers/RecreationController.php')), "whereIn('type', ['cash', 'card', 'bank', 'wallet'])"));

    $parking = ParkingSession::whereIn('status', ParkingSession::ACTIVE)->where('kind', 'visitor')->whereDoesntHave('charges')->orderBy('id')->get()
        ->first(fn ($session) => ParkingService::quote($session, now(), false)['total'] > 0);
    $parkingUser = User::where('is_active', true)->get()->first(fn ($candidate) => ParkingService::canOperate($candidate));
    if (! $parking || ! $parkingUser) {
        throw new RuntimeException('The parking split-settlement check needs an active visitor ticket and parking operator.');
    }
    $parkingQuote = ParkingService::quote($parking, now(), false);
    if ($parkingQuote['total'] <= 0) {
        throw new RuntimeException('The parking split-settlement check needs a visitor ticket with a positive charge.');
    }
    $parkingDue = (float) $parkingQuote['total'];
    $parkingFirst = round($parkingDue / 2, 2);
    $parkingMismatchRejected = false;
    try {
        ParkingService::checkOut($parking, ['settle' => 'pay', 'payments' => [
            ['amount' => $parkingFirst, 'payment_mode_id' => $cash->id],
            ['amount' => round($parkingDue - $parkingFirst - 1, 2), 'payment_mode_id' => $card->id],
        ]], $parkingUser);
    } catch (\Illuminate\Validation\ValidationException) {
        $parkingMismatchRejected = true;
    }
    $check('Parking direct checkout rejects tender rows that do not settle the due', $parkingMismatchRejected
        && ParkingSession::find($parking->id)?->isActive());
    $parkingDone = ParkingService::checkOut($parking, ['settle' => 'pay', 'payments' => [
        ['amount' => $parkingFirst, 'payment_mode_id' => $cash->id, 'reference' => 'SPLIT-PARKING-CASH'],
        ['amount' => round($parkingDue - $parkingFirst, 2), 'payment_mode_id' => $card->id, 'reference' => 'SPLIT-PARKING-CARD'],
    ]], $parkingUser);
    $parkingFolio = Folio::find($parkingDone->folio_id);
    $parkingPayments = $parkingFolio?->transactions()->where('category', 'settlement')->where('type', 'payment')->orderBy('id')->get();
    $check('Parking direct checkout posts split tender entries and closes the invoice folio', $parkingDone->status === 'out'
        && $parkingFolio?->status === 'closed' && $parkingPayments?->count() === 2
        && (int) $parkingPayments[0]->payment_mode_id === (int) $cash->id && (int) $parkingPayments[1]->payment_mode_id === (int) $card->id
        && $parkingDone->payment_mode_id === null && abs($parkingFolio->balance()) < 0.005);
    $check('Parking exit form exposes split tender while retaining room-post and voucher restrictions',
        str_contains(file_get_contents(base_path('resources/views/parking/session.blade.php')), 'pkSplitTender-')
        && str_contains(file_get_contents(base_path('app/Services/ParkingService.php')), "['city_ledger', 'online', 'gift_voucher']"));

    echo 'Guest split-tender checks: ' .$checks.'; passed '.($checks - count($fails)).'; failed '.count($fails)."\n";
    foreach ($fails as $failure) {
        echo 'FAIL: '.$failure."\n";
    }
    if ($fails) {
        throw new RuntimeException('Guest split-tender checks failed.');
    }
} finally {
    DB::rollBack();
}
