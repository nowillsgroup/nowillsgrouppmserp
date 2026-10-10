<?php

namespace App\Http\Controllers;

use App\Models\GuestRequest;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\GuestPortalService;
use App\Services\MailService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/** Front office: guest request queue (portal + phone) and per-stay portal link management. */
class GuestRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'open');
        $q = GuestRequest::with('stay.guest', 'stay.reservation.guest', 'room', 'assignee', 'handler')
            ->when($status === 'open', fn ($w) => $w->whereIn('status', GuestRequest::OPEN))
            ->when(array_key_exists($status, GuestRequest::STATUSES), fn ($w) => $w->where('status', $status))
            ->when($status === 'today', fn ($w) => $w->whereDate('created_at', today()))
            ->when($request->filled('category'), fn ($w) => $w->where('category', $request->query('category')))
            ->when($request->filled('room'), fn ($w) => $w->whereHas('room', fn ($r) => $r->where('room_no', 'like', '%'.$request->query('room').'%')))
            ->when($request->filled('mine'), fn ($w) => $w->where('assigned_to', auth()->id()));
        $q = $status === 'open'
            ? $q->orderByRaw("FIELD(status,'new','acknowledged','in_progress')")->orderByRaw("FIELD(priority,'high','normal')")->orderBy('created_at')
            : $q->latest('id');
        $requests = $q->paginate(30)->withQueryString();

        $open = GuestRequest::whereIn('status', GuestRequest::OPEN)->get(['id', 'status', 'created_at', 'acknowledged_at']);
        $todayDone = GuestRequest::whereDate('completed_at', today())->where('status', 'done')->get(['created_at', 'acknowledged_at', 'completed_at', 'rating']);
        $stats = [
            'new' => $open->where('status', 'new')->count(), 'overdue' => $open->filter->isOverdue()->count(),
            'working' => $open->whereIn('status', ['acknowledged', 'in_progress'])->count(), 'done' => $todayDone->count(),
            'avg_response' => $todayDone->filter(fn ($r) => $r->acknowledged_at)->avg(fn ($r) => $r->created_at->diffInMinutes($r->acknowledged_at)),
            'rating' => $todayDone->whereNotNull('rating')->avg('rating'),
        ];
        $stays = ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->get()->sortBy(fn ($s) => $s->room?->room_no)->values();
        $staff = User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->pluck('name', 'id');

        return view('requests.index', compact('requests', 'stats', 'status', 'stays', 'staff'));
    }

    /** Lightweight counter for the queue page auto-refresh. */
    public function count()
    {
        return response()->json(['new' => GuestRequest::where('status', 'new')->count(), 'latest' => (int) GuestRequest::max('id')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'reservation_room_id' => 'required|integer|owned:reservation_rooms', 'category' => 'required|string|in:'.implode(',', array_keys(GuestRequest::CATEGORIES)),
            'item' => 'nullable|string|max:120', 'quantity' => 'nullable|integer|min:1|max:10', 'notes' => 'nullable|string|max:500',
            'preferred_at' => 'nullable|date', 'time' => 'nullable|date_format:H:i',
        ]);
        try {
            $req = GuestPortalService::raise(ReservationRoom::findOrFail($data['reservation_room_id']), $data, 'staff');
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Request {$req->request_no} logged.");
    }

    public function action(Request $request, GuestRequest $greq, string $action)
    {
        abort_unless(in_array($action, ['acknowledge', 'start', 'done', 'decline']), 404);
        $data = $request->validate(['note' => 'nullable|string|max:255']);
        try {
            GuestPortalService::transition($greq, $action, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$greq->request_no}: ".GuestRequest::STATUSES[$greq->status].'.');
    }

    public function assign(Request $request, GuestRequest $greq)
    {
        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);
        $user = $data['assigned_to'] ? User::where('hotel_id', Tenant::id())->where('is_active', true)->find($data['assigned_to']) : null;
        if ($data['assigned_to'] && ! $user) {
            return back()->with('error', 'Pick a staff member of this property.');
        }
        $greq->forceFill(['assigned_to' => $user?->id])->save();
        if ($user && $greq->status === 'new') {
            GuestPortalService::transition($greq, 'acknowledge');
        }
        ActivityLogger::log('requests', 'assign', "{$greq->request_no} assigned to ".($user?->name ?? 'nobody'), $greq);

        return back()->with('success', "{$greq->request_no} assigned".($user ? " to {$user->name}" : ' – unassigned').'.');
    }

    // ───────── portal link per stay ─────────

    public function portal(ReservationRoom $stay)
    {
        $stay->load('guest', 'room', 'roomType', 'reservation.guest');
        $url = $stay->portalUrl();
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);
        $svg = (new \chillerlan\QRCode\QRCode($opts))->render($url);
        $requests = GuestRequest::where('reservation_room_id', $stay->id)->latest('id')->limit(20)->get();

        return view(request()->boolean('card') ? 'requests.card' : 'requests.portal', compact('stay', 'url', 'svg', 'requests') + ['guest' => $stay->guest ?? $stay->reservation?->guest]);
    }

    public function sendPortal(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['channel' => 'required|in:sms,whatsapp,email']);
        if ($stay->portalState() === 'closed') {
            return back()->with('error', 'This stay is closed – the portal link no longer works.');
        }
        if ($data['channel'] === 'email') {
            $log = MailService::portalLink($stay);
            $ok = $log->status === 'sent';
            $err = $log->error;
        } else {
            $log = GuestPortalService::sendWelcome($stay, $data['channel']);
            $ok = in_array($log->status, ['sent', 'queued', 'logged', 'delivered']);
            $err = $log->error;
        }
        ActivityLogger::log('requests', 'portal_send', "Portal link sent by {$data['channel']} for {$stay->reservation->reservation_no}", $stay);

        return back()->with($ok ? 'success' : 'error', $ok ? 'Portal link sent by '.Str::upper($data['channel'] === 'email' ? 'e-mail' : $data['channel']).'.' : 'Could not send: '.($err ?: 'unknown error'));
    }

    public function regenerate(ReservationRoom $stay)
    {
        $stay->forceFill(['portal_token' => Str::random(40)])->save();
        ActivityLogger::log('requests', 'portal_regenerate', "Portal link regenerated for {$stay->reservation->reservation_no} – the old link stopped working", $stay);

        return back()->with('success', 'New link created. The old link no longer works – give the guest the new QR code.');
    }
}
