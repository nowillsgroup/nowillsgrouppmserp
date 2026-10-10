<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\ChannelPush;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\ChannelService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** v2.8 channel manager: dashboard, OTA inbox, sync log and rate-parity view. */
class ChannelManagerController extends Controller
{
    public const INBOX_TABS = ['attention' => 'Needs attention', 'failed' => 'Rejected', 'processed' => 'Processed', 'all' => 'All messages'];

    public function index()
    {
        $connections = ChannelConnection::with('source', 'company')->withCount(['roomMaps', 'rateMaps'])->orderBy('name')->get()
            ->each(fn ($c) => $c->pending = ChannelService::pendingCount($c));
        $stats = ChannelService::stats(30);
        $overbooked = Reservation::with('guest', 'channel', 'rooms.roomType')->where('channel_attention', 'overbooked')->where('status', '!=', 'cancelled')->orderBy('arrival_date')->get();

        return view('channel-manager.index', [
            'connections' => $connections, 'stats' => $stats, 'overbooked' => $overbooked,
            'attention' => ChannelMessage::with('connection', 'reservation')->where('status', 'attention')->latest('id')->limit(8)->get(),
            'failedMsgs' => ChannelMessage::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
            'recent' => Reservation::with('guest', 'channel', 'rooms.roomType')->whereNotNull('channel_connection_id')->latest('id')->limit(10)->get(),
            'pushes' => ChannelPush::with('connection')->latest('id')->limit(8)->get(),
            'totals' => ['bookings' => $stats->sum('bookings'), 'revenue' => $stats->sum('revenue'), 'commission' => $stats->sum('commission'), 'nights' => $stats->sum('nights')],
        ]);
    }

    public function inbox(Request $request)
    {
        $tab = array_key_exists($request->tab, self::INBOX_TABS) ? $request->tab : 'attention';
        $base = ChannelMessage::query()->when($request->connection, fn ($q, $c) => $q->where('channel_connection_id', $c))
            ->when($request->q, fn ($q, $s) => $q->where('channel_ref', 'like', "%{$s}%"));
        $counts = collect(self::INBOX_TABS)->map(fn ($v, $k) => (clone $base)->when($k !== 'all', fn ($q) => $q->where('status', $k))->count());
        $messages = (clone $base)->with('connection', 'reservation')->when($tab !== 'all', fn ($q) => $q->where('status', $tab))->latest('id')->paginate(25)->withQueryString();

        return view('channel-manager.inbox', ['messages' => $messages, 'tab' => $tab, 'counts' => $counts, 'connections' => ChannelConnection::orderBy('name')->pluck('name', 'id')]);
    }

    public function message(ChannelMessage $chmsg)
    {
        $chmsg->load('connection', 'reservation.guest', 'resolver');
        $history = ChannelMessage::where('channel_connection_id', $chmsg->channel_connection_id)->where('channel_ref', $chmsg->channel_ref)->orderBy('id')->get();

        return view('channel-manager.message', ['m' => $chmsg, 'history' => $history]);
    }

    public function reprocess(ChannelMessage $chmsg)
    {
        abort_unless(in_array($chmsg->status, ['attention', 'failed']) && $chmsg->attention !== 'overbooked', 403, 'Only rejected or needs-attention messages (not overbookings) can be reprocessed.');
        [$code, $body] = ChannelService::process($chmsg->fresh(), true);
        $chmsg->refresh();

        return redirect()->route('cm.message', $chmsg)->with($code === 200 ? 'success' : 'warning', match (true) {
            $code === 200 => 'Reprocessed – '.($body['reservation_no'] ?? 'done').'.',
            default => 'Still not applied: '.($chmsg->error ?? $body['error'] ?? 'see details'),
        });
    }

    public function resolve(Request $request, ChannelMessage $chmsg)
    {
        abort_unless(in_array($chmsg->status, ['attention', 'failed']), 403);
        $data = $request->validate(['note' => 'required|string|min:5|max:255']);
        ChannelService::resolve($chmsg, $data['note'], auth()->id());

        return back()->with('success', "Message {$chmsg->channel_ref} marked resolved.");
    }

    public function resolveReservation(Request $request, Reservation $reservation)
    {
        abort_unless($reservation->channel_attention, 403);
        $data = $request->validate(['note' => 'required|string|min:5|max:255']);
        ChannelService::resolveReservation($reservation, $data['note'], auth()->id());

        return back()->with('success', "Overbooking on {$reservation->reservation_no} resolved.");
    }

    public function pushes(Request $request)
    {
        $pushes = ChannelPush::with('connection', 'user')->when($request->connection, fn ($q, $c) => $q->where('channel_connection_id', $c))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(40)->withQueryString();

        return view('channel-manager.pushes', ['pushes' => $pushes, 'connections' => ChannelConnection::orderBy('name')->pluck('name', 'id'),
            'failed24' => ChannelPush::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count()]);
    }

    public function parity(Request $request)
    {
        $connections = ChannelConnection::orderBy('name')->get();
        $c = $connections->firstWhere('id', (int) $request->connection) ?? $connections->first();
        $from = $request->filled('from') ? Carbon::parse($request->from)->max(bdate()->copy()->startOfDay()) : bdate()->copy()->startOfDay();
        $days = 14;
        $grid = $c ? ChannelService::parity($c, $from, $days) : [];
        $out = collect($grid)->flatMap(fn ($byDate) => collect($byDate)->where('out_of_sync', true))->count();

        return view('channel-manager.parity', ['connections' => $connections, 'c' => $c, 'from' => $from, 'days' => $days, 'grid' => $grid, 'outOfSync' => $out,
            'types' => RoomType::whereIn('id', array_keys($grid))->orderBy('base_rate')->get()]);
    }
}
