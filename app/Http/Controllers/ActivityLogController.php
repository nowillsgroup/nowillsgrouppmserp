<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->input('from', bdate()->copy()->subDays(7)->toDateString());
        $to = $request->input('to', now()->toDateString());
        $logs = ActivityLog::with('user')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($request->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->module, fn ($q, $m) => $q->where('module', $m))
            ->when($request->q, fn ($q, $t) => $q->where('description', 'like', "%$t%"))
            ->latest('id')->paginate(50)->withQueryString();

        return view('logs.index', [
            'logs' => $logs, 'from' => $from, 'to' => $to,
            'users' => User::where('hotel_id', Tenant::id())->orWhere(fn ($q) => $q->where('is_group_admin', true)->where('hotel_group_id', hotel()->hotel_group_id))->orderBy('name')->pluck('name', 'id'),
            'modules' => ActivityLog::distinct()->orderBy('module')->pluck('module'),
        ]);
    }
}
