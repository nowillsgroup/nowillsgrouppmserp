<?php

namespace App\Http\Controllers;

use App\Models\DataBreach;
use App\Models\Guest;
use App\Models\PiiAccessLog;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRetentionRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PrivacyService;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public const TABS = ['open' => 'Open', 'overdue' => 'Overdue', 'unverified' => 'Awaiting e-mail', 'completed' => 'Completed', 'rejected' => 'Rejected', 'all' => 'All'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'open';
        $today = bdate()->toDateString();
        $base = PrivacyRequest::query()
            ->when($request->type && isset(PrivacyRequest::TYPES[$request->type]), fn ($q) => $q->where('type', $request->type))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('request_no', 'like', "%$t%")->orWhere('requester_name', 'like', "%$t%")->orWhere('requester_email', 'like', "%$t%")));
        $counts = [];
        foreach (array_keys(self::TABS) as $k) {
            $counts[$k] = $this->tab(clone $base, $k, $today)->count();
        }
        $requests = $this->tab($base->with('guest:id,first_name,last_name,title,privacy_status', 'assignee:id,name'), $tab, $today)
            ->orderByRaw("status = 'open' desc")->orderBy('due_on')->orderByDesc('id')->paginate(25)->withQueryString();
        $done = PrivacyRequest::whereIn('status', ['completed'])->where('completed_at', '>=', now()->subDays(365))->get(['due_on', 'completed_at', 'received_at']);

        return view('privacy.index', [
            'requests' => $requests, 'tab' => $tab, 'counts' => $counts, 'alerts' => PrivacyService::alerts(),
            'onTime' => $done->count() ? round($done->filter(fn ($r) => $r->completed_at->toDateString() <= $r->due_on->toDateString())->count() / $done->count() * 100) : null,
            'avgDays' => $done->count() ? round($done->avg(fn ($r) => $r->received_at->diffInDays($r->completed_at)), 1) : null,
            'breaches' => DataBreach::where('status', '!=', 'closed')->orderBy('detected_at')->get(),
            'lastRun' => PrivacyRetentionRun::latest('id')->first(),
            'erased' => Guest::whereNotNull('privacy_status')->selectRaw('privacy_status, count(*) n')->groupBy('privacy_status')->pluck('n', 'privacy_status'),
            'reveals' => PiiAccessLog::where('created_at', '>=', now()->subDays(30))->count(),
            'settings' => PrivacyService::settings(),
        ]);
    }

    protected function tab($q, string $tab, string $today)
    {
        return match ($tab) {
            'open' => $q->where('status', 'open'),
            'overdue' => $q->where('status', 'open')->where('due_on', '<', $today),
            'unverified', 'completed', 'rejected' => $q->where('status', $tab),
            default => $q,
        };
    }

    public function accessLog(Request $request)
    {
        $logs = PiiAccessLog::with('user:id,name', 'guest:id,first_name,last_name,title,privacy_status')
            ->when($request->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->guest_id, fn ($q, $g) => $q->where('guest_id', $g))
            ->when($request->action && isset(PiiAccessLog::ACTIONS[$request->action]), fn ($q) => $q->where('action', $request->action))
            ->when($request->from, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($request->to, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('id')->paginate(50)->withQueryString();
        $top = PiiAccessLog::with('user:id,name')->where('created_at', '>=', now()->subDays(30))->selectRaw('user_id, count(*) n, count(distinct guest_id) g')
            ->groupBy('user_id')->orderByDesc('n')->limit(8)->get();

        return view('privacy.access', ['logs' => $logs, 'top' => $top, 'users' => User::where('hotel_id', hotel()->id)->orderBy('name')->pluck('name', 'id')]);
    }

    public function retention()
    {
        return view('privacy.retention', ['settings' => PrivacyService::settings(), 'preview' => PrivacyService::retentionPreview(),
            'runs' => PrivacyRetentionRun::with('user:id,name')->latest('id')->limit(20)->get()]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'privacy_response_days' => 'required|integer|min:1|max:90', 'privacy_extension_days' => 'required|integer|min:0|max:90',
            'privacy_tax_years' => 'required|integer|min:0|max:15', 'privacy_police_years' => 'required|integer|min:0|max:15',
            'retention_id_days' => 'required|integer|min:0|max:3650', 'retention_message_days' => 'required|integer|min:0|max:3650',
            'retention_inactive_years' => 'required|integer|min:0|max:30', 'retention_unverified_days' => 'required|integer|min:1|max:60',
            'breach_notify_hours' => 'required|integer|min:1|max:720',
            'dpo_name' => 'nullable|string|max:120', 'dpo_email' => 'nullable|email|max:150', 'dpo_phone' => 'nullable|string|max:30',
            'privacy_notice' => 'nullable|string|max:5000',
        ]);
        $data['retention_enabled'] = $request->boolean('retention_enabled') ? 1 : 0;
        if ($data['retention_id_days'] && $data['retention_id_days'] < 30) {
            return back()->withInput()->withErrors(['retention_id_days' => 'Keep ID scans at least 30 days after check-out (disputes, chargebacks) – or 0 to switch the rule off.']);
        }
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) ($v ?? '')]);
        }
        Setting::$version++;
        ActivityLogger::log('privacy', 'settings', 'Privacy & retention settings updated', null, $data + ['privacy_notice' => '…']);

        return back()->with('success', 'Privacy & retention settings saved.');
    }

    public function runRetention()
    {
        $run = PrivacyService::runRetention('manual', auth()->id());
        $n = $run->total();

        return back()->with('success', $n ? 'Retention run done – '.collect($run->counts)->filter()->map(fn ($c, $k) => $c.' '.strtolower(PrivacyService::RETENTION_LABELS[$k]))->implode(', ').'.'
            : 'Retention run done – nothing was due.');
    }

    /** Staff-initiated export of one guest's data (JSON), logged as PII access. */
    public function guestExport(Request $request, Guest $guest)
    {
        abort_if($guest->isErased(), 404);
        PrivacyService::logAccess($guest, 'export', 'Profile export (JSON)', $request->input('reason', 'Export from the guest profile'));

        return response()->json(PrivacyService::export($guest), 200, [
            'Content-Disposition' => 'attachment; filename="guest-G'.$guest->id.'-personal-data-'.now()->format('Ymd').'.json"', 'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Masked identifiers on the profile: reveal is logged with the (optional) reason. */
    public function reveal(Request $request, Guest $guest)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:190']);
        abort_if($guest->isErased(), 404);
        PrivacyService::logAccess($guest, 'reveal', 'ID number / passport / date of birth', $data['reason'] ?? null);

        return response()->json(['id_number' => $guest->id_number ?: '—', 'passport_no' => $guest->passport_no ?: '—',
            'dob' => $guest->dob?->format('d M Y') ?? '—', 'visa_expiry' => $guest->visa_expiry?->format('d M Y') ?? '—']);
    }
}
