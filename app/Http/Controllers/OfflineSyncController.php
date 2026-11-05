<?php

namespace App\Http\Controllers;

use App\Services\OfflineFrontDeskService;
use App\Services\OfflinePreparationService;
use App\Services\OfflineReconciliationService;
use App\Services\OfflinePosService;
use App\Services\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Authenticated browser outbox endpoints for replay-safe offline operations. */
class OfflineSyncController extends Controller
{
    /** The offline hub: what this device can still do with no connection. */
    public function hub()
    {
        return view('offline.index');
    }

    public function queue()
    {
        return view('offline.queue');
    }

    /**
     * What this login may prepare on this device, so the hub pulls the right
     * screens and snapshots before the connection gets shaky.
     */
    public function preparation(Request $request): JsonResponse
    {
        return response()->json(OfflinePreparationService::plan($request->user()), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * What the devices sent back after an outage, and what it produced.
     *
     * Read-only: the receipts are the audit trail, so this screen shows them and
     * never replays, edits or deletes one.
     */
    public function reconciliation(Request $request)
    {
        $report = OfflineReconciliationService::report($request->user(), $request->only(['from', 'to', 'device', 'status', 'operation']));

        if ($request->query('export') === 'csv') {
            $name = 'offline-reconciliation-'.$report['from']->toDateString().'_'.$report['to']->toDateString().'.csv';

            return response()->streamDownload(
                fn () => print OfflineReconciliationService::csv($report['rows']),
                $name,
                ['Content-Type' => 'text/csv; charset=UTF-8']
            );
        }

        return view('offline.reconciliation', $report);
    }

    public function workspace()
    {
        return view('offline.housekeeping');
    }

    /** Restaurant ordering that keeps working when the connection does not. */
    public function posWorkspace()
    {
        return view('offline.pos');
    }

    public function posSnapshot(Request $request): JsonResponse
    {
        return response()->json(OfflinePosService::snapshot($request->user()), 200, ['Cache-Control' => 'no-store']);
    }

    public function frontDeskWorkspace()
    {
        return view('offline.frontdesk');
    }

    public function frontDeskSnapshot(Request $request): JsonResponse
    {
        return response()->json(OfflineFrontDeskService::snapshot($request->user()), 200, ['Cache-Control' => 'no-store']);
    }

    public function housekeepingSnapshot(Request $request): JsonResponse
    {
        return response()->json(OfflineSyncService::housekeepingSnapshot($request->user()), 200, ['Cache-Control' => 'no-store']);
    }

    public function guestRequests()
    {
        return view('offline.guest-requests');
    }

    public function guestRequestSnapshot(Request $request): JsonResponse
    {
        return response()->json(OfflineSyncService::guestRequestSnapshot($request->user()), 200, ['Cache-Control' => 'no-store']);
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operations' => 'required|array|max:100',
            'operations.*' => 'required|array',
        ]);

        return response()->json(OfflineSyncService::push($data['operations'], $request->user(), $request->header('X-Offline-Device')),
            200, ['Cache-Control' => 'no-store']);
    }
}
