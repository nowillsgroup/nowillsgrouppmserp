<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\Hotel;
use App\Models\InterfaceCommand;
use App\Models\Setting;
use App\Services\CallAccountingService;
use App\Services\InterfaceService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** v3.7 – interface hub: PABX / lock status, settings, PMS → device command log, and the PBX push endpoint. */
class InterfaceController extends Controller
{
    public function index(Request $request)
    {
        $system = in_array($request->query('system'), ['pbx', 'lock'], true) ? $request->query('system') : null;
        $commands = InterfaceCommand::with('room', 'user', 'card')->when($system, fn ($q) => $q->where('system', $system))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(40)->withQueryString();
        $lastCall = CallRecord::latest('id')->first();

        return view('interfaces.index', [
            'cfg' => InterfaceService::cfg(), 'counts' => InterfaceService::counts(), 'commands' => $commands, 'system' => $system, 'lastCall' => $lastCall,
            'pushUrl' => url('/api/pbx/'.InterfaceService::cfg()['pbx_token']),
            'lastPush' => CallRecord::where('source', 'push')->latest('id')->value('created_at'),
            'cosOptions' => InterfaceService::COS,
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'pbx_enabled' => 'nullable|boolean', 'pbx_driver' => 'required|in:demo,offline,tcp', 'pbx_auto_post' => 'nullable|boolean', 'pbx_min_secs' => 'required|integer|min:0|max:60',
            'pbx_free_numbers' => 'nullable|string|max:300', 'pbx_strip_prefix' => 'nullable|string|max:3', 'pbx_format' => 'required|string|max:100', 'pbx_mask_digits' => 'nullable|boolean',
            'pbx_default_cos' => 'required|in:'.implode(',', array_keys(InterfaceService::COS)),
            'pbx_tcp_host' => 'nullable|string|max:190', 'pbx_tcp_port' => 'nullable|integer|min:0|max:65535', 'pbx_tcp_timeout' => 'nullable|integer|min:1|max:60',
            'pbx_tcp_ok' => 'nullable|string|max:120', 'pbx_smdr_host' => 'nullable|string|max:190', 'pbx_smdr_port' => 'nullable|integer|min:0|max:65535',
            'pbx_cmd_checkin' => 'nullable|string|max:200', 'pbx_cmd_checkout' => 'nullable|string|max:200', 'pbx_cmd_cos' => 'nullable|string|max:200', 'pbx_cmd_move' => 'nullable|string|max:200',
            'lock_enabled' => 'nullable|boolean', 'lock_driver' => 'required|in:demo,offline', 'lock_system' => 'nullable|string|max:60', 'lock_max_cards' => 'required|integer|min:1|max:10',
            'lock_lost_fee' => 'required|numeric|min:0|max:100000', 'lock_checkout_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'lock_grace_minutes' => 'required|integer|min:0|max:720',
            'lock_auto_issue' => 'required|integer|min:0|max:4', 'lock_areas' => 'nullable|string|max:200', 'lock_default_areas' => 'nullable|array', 'lock_master_hours' => 'required|integer|min:1|max:24',
        ]);
        try {
            InterfaceService::saveSettings($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Interface settings saved.');
    }

    public function token()
    {
        InterfaceService::regenerateToken();

        return back()->with('success', 'New PBX push token generated – update the URL on the PBX / call-logger PC.');
    }

    public function retry(InterfaceCommand $icmd)
    {
        try {
            $c = InterfaceService::retry($icmd);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($c->status === 'done' ? 'success' : 'error', 'Command #'.$c->id.' '.($c->status === 'done' ? 'acknowledged.' : 'failed again: '.$c->response));
    }

    public function retryAll()
    {
        $n = 0;
        foreach (InterfaceCommand::where('status', 'failed')->where('system', 'pbx')->where('created_at', '>=', now()->subDay())->get() as $c) {
            $n += InterfaceService::retry($c)->status === 'done' ? 1 : 0;
        }

        return back()->with('success', "{$n} PABX command(s) re-sent successfully.");
    }

    /**
     * PBX / call-logger push: POST /api/pbx/{token}. Body is raw SMDR text (in the configured column order) or JSON
     * {"records":[{"ext":"101","number":"0442…","started_at":"2026-09-26 14:05:00","duration":185,"trunk":"T1"}]}.
     */
    public function push(Request $request, string $token): JsonResponse
    {
        $row = Setting::withoutGlobalScope('hotel')->where('key', 'pbx_token')->where('value', $token)->first();
        if (! $row || ! hash_equals((string) $row->value, $token) || ! ($hotel = Hotel::find($row->hotel_id))) {
            return response()->json(['ok' => false, 'error' => 'Unknown token'], 401);
        }
        Tenant::set($hotel);
        if (InterfaceService::cfg()['pbx_enabled'] !== '1') {
            return response()->json(['ok' => false, 'error' => 'PBX interface is switched off'], 503);
        }
        $records = $request->isJson() ? (array) $request->json('records', []) : null;
        if ($records !== null) {
            $sum = ['received' => count($records), 'imported' => 0, 'duplicates' => 0, 'posted' => 0, 'pending' => 0, 'free' => 0, 'admin' => 0, 'errors' => []];
            if (count($records) > 2000) {
                return response()->json(['ok' => false, 'error' => 'At most 2000 records per request'], 413);
            }
            CallAccountingService::flush();
            foreach ($records as $i => $r) {
                try {
                    $at = CallAccountingService::parseDate(str_replace('T', ' ', (string) ($r['started_at'] ?? '')));
                    $dur = CallAccountingService::parseDuration((string) ($r['duration'] ?? ''));
                    if (! $at || $dur === null) {
                        throw new RuntimeException('started_at (Y-m-d H:i:s) and duration are required');
                    }
                    $call = CallAccountingService::record(['ext' => $r['ext'] ?? '', 'number' => $r['number'] ?? '', 'started_at' => $at, 'duration' => $dur, 'trunk' => $r['trunk'] ?? null], 'push', 'P'.now()->format('ymdHis'));
                } catch (RuntimeException $e) {
                    $sum['errors'][] = 'Record '.($i + 1).': '.$e->getMessage();

                    continue;
                }
                if (! $call) {
                    $sum['duplicates']++;

                    continue;
                }
                $sum['imported']++;
                $sum[$call->status === 'waived' ? 'pending' : $call->status]++;
            }
        } else {
            $sum = CallAccountingService::import((string) $request->getContent(), 'push');
            unset($sum['charge'], $sum['batch']);
        }

        return response()->json(['ok' => true] + $sum);
    }
}
