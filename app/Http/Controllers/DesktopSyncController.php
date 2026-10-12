<?php

namespace App\Http\Controllers;

use App\Models\OfflineSyncOperation;
use App\Services\DesktopSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** v7.39 Desktop offline mode — multi-day cache-and-sync workspace + JSON endpoints. */
class DesktopSyncController extends Controller
{
    public function workspace()
    {
        $user = Auth::user();
        $pack = DesktopSyncService::pack($user, DesktopSyncService::WINDOW_DAYS);
        $ops = OfflineSyncOperation::where('hotel_id', $user->hotel_id)->latest('id')->limit(40)->get();

        return view('offline.desktop', [
            'pack' => $pack['meta'],
            'counts' => collect($pack['dataset'])->map(fn ($rows) => count($rows))->all(),
            'ops' => $ops,
            'window' => DesktopSyncService::WINDOW_DAYS,
        ]);
    }

    public function pack(Request $request)
    {
        $days = min(31, max(1, (int) $request->query('days', DesktopSyncService::WINDOW_DAYS)));

        return response()->json(DesktopSyncService::pack(Auth::user(), $days, $request->query('device')))
            ->withHeaders(['Content-Disposition' => 'attachment; filename="pms-desktop-pack-'.now()->format('Ymd-Hi').'.json"']);
    }

    public function delta(Request $request)
    {
        $data = $request->validate(['since' => ['required', 'date']]);

        return response()->json(DesktopSyncService::delta(Auth::user(), $data['since']));
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'max:80'],
            'operations' => ['required', 'array', 'max:500'],
            'operations.*.operation_id' => ['required', 'string', 'max:80'],
            'operations.*.operation' => ['required', 'string', 'max:80'],
        ]);

        return response()->json(DesktopSyncService::sync($data['operations'], Auth::user(), $data['device_id'] ?? $request->header('X-Device-Id')));
    }
}
