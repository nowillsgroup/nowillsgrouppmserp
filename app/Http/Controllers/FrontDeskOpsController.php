<?php

namespace App\Http\Controllers;

use App\Models\GuestMessage;
use App\Models\LogEntry;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Trace;
use App\Models\WakeupCall;
use App\Services\FrontDeskOpsService;
use App\Services\GuestMessageService;
use App\Services\LogBookService;
use App\Services\TraceService;
use App\Services\WakeupService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** v3.5 – front desk operations: log book & hand-over, wake-up calls, guest messages & parcels, traces. */
class FrontDeskOpsController extends Controller
{
    public const LOG_TABS = ['today' => 'Today', 'unread' => 'Unread', 'open' => 'Open / follow-up', 'pinned' => 'Pinned', 'all' => 'All entries'];

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    // ───────────────────────── log book ─────────────────────────

    public function logbook(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::LOG_TABS) ? $request->query('tab') : 'today';
        $uid = (int) auth()->id();
        $q = match ($tab) {
            'unread' => LogBookService::unreadQuery($uid),
            'open' => LogEntry::where('status', 'open'),
            'pinned' => LogBookService::pinnedQuery(),
            'all' => LogEntry::query(),
            default => LogEntry::whereDate('business_date', bdate()->toDateString()),
        };
        $q->with('author', 'reads', 'stay.room', 'room', 'guest')->withCount('comments')
            ->when($request->query('department'), fn ($q, $d) => $q->where('department', $d))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('date'), fn ($q, $d) => $q->whereDate('business_date', $d))
            ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'like', "%{$t}%")->orWhere('body', 'like', "%{$t}%")));
        $entries = $tab === 'open'
            ? $q->orderByRaw("FIELD(priority,'urgent','high','normal')")->orderByRaw('follow_up_at IS NULL')->orderBy('follow_up_at')->orderByDesc('id')->paginate(40)->withQueryString()
            : $q->orderByDesc('pinned')->orderByDesc('id')->paginate(40)->withQueryString();

        return view('desk.logbook', ['entries' => $entries, 'tab' => $tab, 'counts' => FrontDeskOpsService::counts(), 'shift' => FrontDeskOpsService::currentShift(),
            'stays' => can_do('logbook.write') ? FrontDeskOpsService::stayOptions() : [], 'rooms' => Room::where('is_active', true)->orderBy('room_no')->pluck('room_no', 'id')->all()]);
    }

    public function storeEntry(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150', 'body' => 'nullable|string|max:5000', 'category' => 'required|in:'.implode(',', array_keys(LogEntry::CATEGORIES)),
            'department' => 'required|in:'.implode(',', array_keys(LogEntry::DEPARTMENTS)), 'priority' => 'required|in:'.implode(',', array_keys(LogEntry::PRIORITIES)),
            'reservation_room_id' => 'nullable|integer|owned:reservation_rooms', 'room_id' => 'nullable|integer|owned:rooms',
            'needs_action' => 'nullable|boolean', 'follow_up_at' => 'nullable|date', 'pinned' => 'nullable|boolean', 'pinned_until' => 'nullable|date|after_or_equal:today',
        ]);
        if (! empty($data['pinned']) && ! can_do('logbook.manage')) {
            unset($data['pinned'], $data['pinned_until']);
        }
        try {
            $e = LogBookService::create($data);
        } catch (RuntimeException $ex) {
            return $this->fail($ex);
        }

        return redirect()->route('desk.logbook')->with('success', 'Log book entry added'.($e->isOpen() ? ' – open for follow-up.' : '.'));
    }

    public function showEntry(LogEntry $logentry)
    {
        $logentry->load('author', 'resolver', 'reads.user', 'comments.user', 'stay.room', 'stay.reservation', 'room', 'guest');
        LogBookService::markRead(collect([$logentry]), (int) auth()->id());

        return view('desk.entry', ['e' => $logentry]);
    }

    public function acknowledge(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $entries = $ids ? LogEntry::whereIn('id', $ids)->get() : LogBookService::unreadQuery((int) auth()->id())->get();
        $n = LogBookService::markRead($entries, (int) auth()->id());

        return back()->with('success', $n ? "{$n} entr".($n === 1 ? 'y' : 'ies').' acknowledged.' : 'Nothing new to acknowledge.');
    }

    public function comment(Request $request, LogEntry $logentry)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);
        try {
            LogBookService::comment($logentry, $data['body']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('desk.logbook.show', $logentry)->with('success', 'Comment added.');
    }

    public function resolve(Request $request, LogEntry $logentry)
    {
        abort_unless($logentry->created_by === auth()->id() || can_do('logbook.manage') || $logentry->department !== 'management', 403);
        $data = $request->validate(['resolution' => 'required|string|max:2000']);
        try {
            $logentry = LogBookService::resolve($logentry, $data['resolution']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('desk.logbook.show', $logentry)->with('success', 'Entry resolved.');
    }

    public function reopen(LogEntry $logentry)
    {
        try {
            $logentry = LogBookService::reopen($logentry);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('desk.logbook.show', $logentry)->with('success', 'Entry re-opened.');
    }

    public function pin(Request $request, LogEntry $logentry)
    {
        $data = $request->validate(['pinned' => 'required|boolean', 'pinned_until' => 'nullable|date|after_or_equal:today']);
        $logentry = LogBookService::pin($logentry, (bool) $data['pinned'], $data['pinned_until'] ?? null);

        return back()->with('success', $data['pinned'] ? 'Entry pinned to the top of the hand-over.' : 'Entry unpinned.');
    }

    public function handover()
    {
        return view('desk.handover', LogBookService::handover() + ['snap' => FrontDeskOpsService::snapshot(), 'shift' => FrontDeskOpsService::currentShift(),
            'wakeups' => WakeupCall::with('stay.room', 'stay.guest', 'stay.reservation.guest')->where('status', 'scheduled')->whereBetween('call_at', [now()->subHours(2), now()->addHours(12)])->orderBy('call_at')->get(),
            'traces' => Trace::with('stay.room', 'reservation', 'guest')->where('status', 'open')->whereDate('trace_date', '<=', bdate()->toDateString())->orderBy('due_time')->get(),
            'messages' => GuestMessage::with('stay.room', 'guest')->whereIn('status', GuestMessage::OPEN)->orderBy('id')->get()]);
    }

    public function handoverPrint()
    {
        return view('desk.handover-print', LogBookService::handover() + ['snap' => FrontDeskOpsService::snapshot(), 'shift' => FrontDeskOpsService::currentShift(),
            'wakeups' => WakeupCall::with('stay.room', 'stay.guest', 'stay.reservation.guest')->where('status', 'scheduled')->whereBetween('call_at', [now()->subHours(2), now()->addHours(12)])->orderBy('call_at')->get(),
            'traces' => Trace::with('stay.room', 'reservation', 'guest')->where('status', 'open')->whereDate('trace_date', '<=', bdate()->toDateString())->orderBy('due_time')->get()]);
    }

    // ───────────────────────── wake-up calls ─────────────────────────

    public function wakeups(Request $request)
    {
        $date = $request->query('date') ? Carbon::parse($request->query('date')) : null;
        $with = ['stay.room', 'stay.guest', 'stay.reservation.guest', 'attemptsLog.user', 'creator', 'completer'];
        $queue = WakeupCall::with($with)->where('status', 'scheduled')->orderBy('call_at')->get();
        $done = WakeupCall::with($with)->where('status', '!=', 'scheduled')
            ->when($date, fn ($q) => $q->whereDate('call_at', $date->toDateString()), fn ($q) => $q->where('call_at', '>=', now()->subDay()))
            ->orderByDesc('call_at')->limit(200)->get();

        // the "Answered (24 h)" card always covers the last 24 hours, whatever date the history below is showing
        $last24 = WakeupCall::where('status', '!=', 'scheduled')->where('call_at', '>=', now()->subDay())->pluck('status');

        return view('desk.wakeups', ['queue' => $queue, 'done' => $done, 'last24' => $last24, 'date' => $date, 'cfg' => FrontDeskOpsService::cfg(), 'counts' => FrontDeskOpsService::counts(),
            'stays' => FrontDeskOpsService::stayOptions()]);
    }

    public function wakeupsPrint(Request $request)
    {
        $from = now()->startOfHour();
        $calls = WakeupCall::with('stay.room', 'stay.guest', 'stay.reservation.guest')->where('status', 'scheduled')
            ->where('call_at', '<=', now()->addHours(24))->orderBy('call_at')->get();

        return view('desk.wakeups-print', ['calls' => $calls, 'from' => $from]);
    }

    public function storeWakeup(Request $request)
    {
        $data = $request->validate(['reservation_room_id' => 'required|integer|owned:reservation_rooms', 'call_date' => 'required|date', 'call_time' => 'required|date_format:H:i',
            'daily' => 'nullable|boolean', 'note' => 'nullable|string|max:190', 'source' => 'nullable|in:desk,phone']);
        $stay = ReservationRoom::findOrFail($data['reservation_room_id']);
        try {
            $c = WakeupService::schedule($stay, Carbon::parse($data['call_date'].' '.$data['call_time']), ! empty($data['daily']), $data['note'] ?? null, $data['source'] ?? 'desk');
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Wake-up call booked for '.$c->call_at->format('D d M, H:i').($c->daily ? ' – daily until check-out' : '').'.');
    }

    public function attempt(Request $request, WakeupCall $wakeup)
    {
        $data = $request->validate(['outcome' => 'required|in:answered,no_answer,busy', 'note' => 'nullable|string|max:190']);
        try {
            $c = WakeupService::attempt($wakeup, $data['outcome'], $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        $room = $c->stay?->room?->room_no ?? '—';

        return back()->with($c->status === 'no_answer' ? 'error' : 'success', match (true) {
            $c->status === 'done' => "Room {$room}: wake-up call answered.",
            $c->status === 'no_answer' => "Room {$room}: no answer after {$c->attempts} attempts – logged for the duty manager. Send someone to the room.",
            default => "Room {$room}: attempt {$c->attempts} logged – retry at ".WakeupService::retryAt($c)?->format('H:i').'.',
        });
    }

    public function cancelWakeup(Request $request, WakeupCall $wakeup)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:190']);
        try {
            WakeupService::cancel($wakeup, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Wake-up call cancelled.');
    }

    // ───────────────────────── messages & parcels ─────────────────────────

    public function messages(Request $request)
    {
        $tab = in_array($request->query('tab'), ['open', 'parcels', 'closed'], true) ? $request->query('tab') : 'open';
        $q = GuestMessage::with('stay.room', 'stay.reservation', 'guest', 'taker', 'deliverer')
            ->when($tab === 'open', fn ($q) => $q->whereIn('status', GuestMessage::OPEN))
            ->when($tab === 'parcels', fn ($q) => $q->where('kind', 'parcel')->whereIn('status', GuestMessage::OPEN))
            ->when($tab === 'closed', fn ($q) => $q->whereNotIn('status', GuestMessage::OPEN))
            ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->where(fn ($w) => $w->where('message_no', 'like', "%{$t}%")->orWhere('from_name', 'like', "%{$t}%")
                ->orWhere('tracking_no', 'like', "%{$t}%")->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%{$t}%")->orWhere('last_name', 'like', "%{$t}%"))
                ->orWhereHas('stay.room', fn ($r) => $r->where('room_no', $t))));
        $messages = $tab === 'closed' ? $q->orderByDesc('id')->paginate(40)->withQueryString() : $q->orderByDesc('urgent')->orderBy('id')->paginate(100)->withQueryString();

        return view('desk.messages', ['messages' => $messages, 'tab' => $tab, 'cfg' => FrontDeskOpsService::cfg(), 'counts' => FrontDeskOpsService::counts(),
            'stays' => FrontDeskOpsService::stayOptions(), 'preselect' => (int) $request->query('stay')]);
    }

    public function storeMessage(Request $request)
    {
        $data = $request->validate(['reservation_room_id' => 'required|integer|owned:reservation_rooms', 'kind' => 'required|in:message,parcel',
            'channel' => 'nullable|in:'.implode(',', array_keys(GuestMessage::CHANNELS)), 'from_name' => 'required|string|max:120', 'from_phone' => 'nullable|string|max:40',
            'from_company' => 'nullable|string|max:120', 'body' => 'nullable|string|max:3000', 'courier' => 'nullable|string|max:60', 'tracking_no' => 'nullable|string|max:60',
            'pieces' => 'nullable|integer|min:1|max:50', 'storage_location' => 'nullable|string|max:60', 'urgent' => 'nullable|boolean', 'notify' => 'nullable|boolean']);
        $data['notify'] = $request->boolean('notify');
        try {
            $m = GuestMessageService::take(ReservationRoom::findOrFail($data['reservation_room_id']), $data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('desk.messages')->with('success', ($m->isParcel() ? 'Parcel' : 'Message')." {$m->message_no} logged".($m->status === 'notified' ? ' – guest notified by '.strtoupper($m->notified_via).'.' : '.'))
            ->with('slip', $m->id);
    }

    public function slip(GuestMessage $gmsg)
    {
        return view('desk.message-slip', ['m' => $gmsg->load('stay.room', 'stay.reservation', 'guest', 'taker')]);
    }

    public function notify(Request $request, GuestMessage $gmsg)
    {
        $data = $request->validate(['via' => 'required|in:sms,whatsapp,call']);
        try {
            GuestMessageService::notify($gmsg, $data['via']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$gmsg->message_no}: guest notified".($data['via'] === 'call' ? ' by phone.' : ' by '.strtoupper($data['via']).'.'));
    }

    public function deliver(Request $request, GuestMessage $gmsg)
    {
        $data = $request->validate(['delivery_note' => 'nullable|string|max:190', 'collected_by' => 'nullable|string|max:120']);
        try {
            GuestMessageService::deliver($gmsg, $data['delivery_note'] ?? null, $data['collected_by'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$gmsg->message_no} delivered.");
    }

    public function closeMessage(Request $request, GuestMessage $gmsg)
    {
        $data = $request->validate(['status' => 'required|in:returned,cancelled', 'reason' => 'required|string|max:190']);
        try {
            GuestMessageService::close($gmsg, $data['status'], $data['reason']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$gmsg->message_no} ".($data['status'] === 'returned' ? 'returned / forwarded.' : 'cancelled.'));
    }

    // ───────────────────────── traces ─────────────────────────

    public function traces(Request $request)
    {
        $date = Carbon::parse($request->query('date', bdate()->toDateString()));
        $dept = array_key_exists((string) $request->query('department'), TraceService::DEPARTMENTS) ? $request->query('department') : null;
        $with = ['stay.room', 'reservation', 'guest', 'creator', 'doer'];
        $isToday = $date->isSameDay(bdate());
        $open = Trace::with($with)->where('status', 'open')->when($isToday, fn ($q) => $q->whereDate('trace_date', '<=', $date->toDateString()), fn ($q) => $q->whereDate('trace_date', $date->toDateString()))
            ->when($dept, fn ($q) => $q->where('department', $dept))->orderBy('trace_date')->orderByRaw('due_time IS NULL')->orderBy('due_time')->get();
        $closed = Trace::with($with)->where('status', '!=', 'open')->where(fn ($q) => $q->whereDate('trace_date', $date->toDateString())->orWhereDate('done_at', $date->toDateString()))
            ->when($dept, fn ($q) => $q->where('department', $dept))->orderByDesc('done_at')->get();
        $upcoming = $isToday ? Trace::with($with)->where('status', 'open')->whereDate('trace_date', '>', $date->toDateString())
            ->when($dept, fn ($q) => $q->where('department', $dept))->orderBy('trace_date')->limit(50)->get() : collect();

        return view('desk.traces', ['open' => $open, 'closed' => $closed, 'upcoming' => $upcoming, 'date' => $date, 'dept' => $dept, 'isToday' => $isToday,
            'stays' => FrontDeskOpsService::stayOptions()]);
    }

    public function storeTrace(Request $request)
    {
        $data = $request->validate(['trace_date' => 'required|date', 'due_time' => 'nullable|date_format:H:i', 'department' => 'required|in:'.implode(',', array_keys(TraceService::DEPARTMENTS)),
            'text' => 'required|string|max:255', 'reservation_room_id' => 'nullable|integer|owned:reservation_rooms', 'reservation_id' => 'nullable|integer|owned:reservations']);
        try {
            $t = TraceService::create($data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Trace set for '.$t->trace_date->format('D d M').' ('.TraceService::DEPARTMENTS[$t->department].').');
    }

    public function traceDone(Request $request, Trace $trace)
    {
        $data = $request->validate(['note' => 'nullable|string|max:190']);
        try {
            TraceService::complete($trace, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Trace completed.');
    }

    public function traceCancel(Request $request, Trace $trace)
    {
        $data = $request->validate(['reason' => 'required|string|max:190']);
        try {
            TraceService::cancel($trace, $data['reason']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Trace cancelled.');
    }

    // ───────────────────────── settings ─────────────────────────

    public function settings()
    {
        return view('desk.settings', ['c' => FrontDeskOpsService::cfg()]);
    }

    public function saveSettings(Request $request)
    {
        $t = 'required|date_format:H:i';
        $data = $request->validate(['shift_morning' => $t, 'shift_evening' => $t, 'shift_night' => $t, 'log_unread_days' => 'required|integer|min:1|max:30',
            'wakeup_lead' => 'required|integer|min:0|max:120', 'wakeup_grace' => 'required|integer|min:1|max:120', 'wakeup_retry' => 'required|integer|min:1|max:60',
            'wakeup_max_attempts' => 'required|integer|min:1|max:10', 'escalate_phone' => 'nullable|string|max:40',
            'msg_notify' => 'required|in:none,sms,whatsapp', 'msg_include_text' => 'nullable|boolean', 'msg_remind_minutes' => 'required|integer|min:5|max:1440',
            'lf_retention' => 'required|integer|min:1|max:1000', 'lf_retention_valuable' => 'required|integer|min:1|max:2000', 'lf_retention_perishable' => 'required|integer|min:0|max:30',
            'lf_valuable_amount' => 'required|integer|min:0', 'lf_notify_guest' => 'nullable|boolean']);
        if (! ($data['shift_morning'] < $data['shift_evening'] && $data['shift_evening'] < $data['shift_night'])) {
            return back()->withInput()->with('error', 'Shift start times must run morning → evening → night within the day.');
        }
        $data['msg_include_text'] = $request->boolean('msg_include_text');
        $data['lf_notify_guest'] = $request->boolean('lf_notify_guest');
        $data['escalate_phone'] ??= '';
        FrontDeskOpsService::saveSettings($data);

        return redirect()->route('desk.settings')->with('success', 'Front desk operations settings saved.');
    }
}
