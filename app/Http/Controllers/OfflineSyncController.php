<?php

namespace App\Http\Controllers;

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
