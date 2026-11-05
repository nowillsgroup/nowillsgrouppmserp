<?php

namespace App\Http\Controllers;

use App\Services\ReservationWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * v7.41 Reservation Workspace — the room board (KPIs, room cards, right rail).
 * v7.79 — the board opens with the desk's own list of work: what is blocked,
 * unassigned, held or not ready, instead of leaving staff to hunt for it.
 */
class ReservationWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $snap = ReservationWorkspaceService::snapshot($this->boardDate($request));

        return view('reservations.workspace', $snap + [
            'attention' => $this->attention($snap),
            'kpiTiles' => $this->kpiTiles($snap),
        ]);
    }

    /**
     * The KPI strip: the day's headline numbers, each with its 14-day sparkline, its
     * change against the previous selected day, and the screen it opens.
     *
     * @return array<int, array{key: string, label: string, value: string, delta: string, tone: string, series: array, link: string, permission: string}>
     */
    protected function kpiTiles(array $snap): array
    {
        $isToday = $snap['date']->toDateString() === $snap['businessDate']->toDateString();
        $isHistory = $snap['date']->lt($snap['businessDate']);
        $isFuture = $snap['date']->gt($snap['businessDate']);

        $tiles = [
            'occupancy' => ['Occupancy', 'frontdesk.tapechart', 'frontdesk.view'],
            'revenue' => [$isFuture ? 'Booked room-rate value' : ($isHistory ? 'Room-rate value' : 'Room-rate total'), 'revenue.index', 'revenue.view'],
            'available' => ['Available', 'frontdesk.rooms', 'frontdesk.view'],
            'occupied' => ['Occupied', 'frontdesk.rooms', 'frontdesk.view'],
            'reserved' => ['Reserved', 'frontdesk.rooms', 'frontdesk.view'],
            'arrivals' => ['Arrivals', 'frontdesk.arrivals', 'frontdesk.view'],
            'departures' => ['Departures', 'frontdesk.departures', 'frontdesk.view'],
            'inhouse' => [$isFuture ? 'Guests expected' : ($isHistory ? 'Guests staying' : 'In-house guests'), 'frontdesk.inhouse', 'frontdesk.view'],
            'vips' => [$isFuture ? 'VIP guests expected' : 'VIP guests', 'frontdesk.inhouse', 'frontdesk.view'],
        ];

        $out = [];
        foreach ($tiles as $key => [$label, $route, $permission]) {
            if (! can_do($permission)) {
                continue;
            }
            $series = array_values((array) ($snap['trend'][$key] ?? []));
            $current = $series === [] ? ($snap['kpis'][$key] ?? 0) : $series[count($series) - 1];
            $delta = $snap['deltas'][$key] ?? null;
            $tone = ! in_array($key, ['occupancy', 'revenue'], true) || $delta === null || $delta == 0
                ? 'neutral'
                : ($delta > 0 ? 'green' : 'red');
            $out[] = [
                'key' => $key,
                'label' => $label,
                'value' => $key === 'occupancy' ? $current.'%' : ($key === 'revenue' ? money($current) : (string) $current),
                'delta' => $delta === null ? '—' : ($delta > 0 ? '+' : '').$delta.'%',
                'tone' => $tone,
                'series' => $series,
                'link' => route($route),
                'permission' => $permission,
                'opens' => $isToday && $key !== 'revenue',
            ];
        }

        return $out;
    }

    /** The board date is strict YYYY-MM-DD and bounded around the hotel's business date. */
    protected function boardDate(Request $request): Carbon
    {
        $businessDate = bdate()->copy()->startOfDay();
        $queryDate = $request->query('date', '');
        $raw = is_string($queryDate) ? trim($queryDate) : '';
        if ($raw === '') {
            return $businessDate;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $raw);
            if (! $date || $date->format('Y-m-d') !== $raw) {
                return $businessDate;
            }
        } catch (\Throwable) {
            return $businessDate;
        }

        $min = $businessDate->copy()->subDays(62);
        $max = $businessDate->copy()->addDays(62);

        return ($date->gte($min) && $date->lte($max)) ? $date : $businessDate;
    }

    /**
     * What the desk has to act on today, in the order it hurts: a guest waiting for a
     * room, a check-in that will be refused, a checkout that cannot be closed, a room
     * that is not clean yet, then the waitlist.
     *
     * Everything here is derived from the snapshot the board already rendered, so the
     * counts can never disagree with the room cards and the rail beside them.
     *
     * @return array<int, array{key: string, label: string, count: int, detail: string, url: ?string, tone: string}>
     */
    protected function attention(array $snap): array
    {
        $date = $snap['date'];
        $blocks = $snap['inventoryBlocks'] ?? [];
        $arrivals = collect($snap['arrivals'] ?? []);
        $items = [];

        $unassigned = $arrivals->filter(fn ($stay) => $stay->status === 'reserved' && ! $stay->room_id);
        if ($unassigned->isNotEmpty()) {
            $items[] = [
                'key' => 'unassigned',
                'label' => 'Arrivals without a room',
                'count' => $unassigned->count(),
                'detail' => 'Assign a room before the guest reaches the desk.',
                'url' => can_do('frontdesk.view') ? route('frontdesk.arrivals', ['date' => $date->toDateString()]) : null,
                'tone' => 'amber',
            ];
        }

        // A stay that is due in but whose room would refuse the check-in right now.
        $blocked = $arrivals->filter(function ($stay) use ($blocks) {
            if ($stay->status !== 'reserved' || ! $stay->room_id) {
                return false;
            }
            $room = $stay->room;

            return ! $room || ! $room->is_active || $room->status !== 'vacant' || isset($blocks[$room->id]);
        });
        if ($blocked->isNotEmpty()) {
            $items[] = [
                'key' => 'checkin-blocked',
                'label' => 'Check-ins that will be refused',
                'count' => $blocked->count(),
                'detail' => 'The room is not vacant, inactive or carries an inventory block.',
                'url' => can_do('frontdesk.view') ? route('frontdesk.arrivals', ['date' => $date->toDateString()]) : null,
                'tone' => 'red',
            ];
        }

        $holds = collect($snap['departureFigures'] ?? [])->filter(fn ($row) => ! empty($row['blocked']));
        if ($holds->isNotEmpty()) {
            $items[] = [
                'key' => 'checkout-held',
                'label' => 'Checkouts on hold',
                'count' => $holds->count(),
                'detail' => 'A balance, unposted charge or open folio is holding the checkout.',
                'url' => can_do('frontdesk.view') ? route('frontdesk.departures', ['date' => $date->toDateString()]) : null,
                'tone' => 'red',
            ];
        }

        $notReady = $arrivals->filter(fn ($stay) => $stay->room && in_array($stay->room->hk_status, ['dirty', 'touch_up'], true));
        if ($notReady->isNotEmpty() && can_do('housekeeping.view')) {
            $items[] = [
                'key' => 'rooms-not-ready',
                'label' => 'Arrival rooms not ready',
                'count' => $notReady->count(),
                'detail' => 'Still dirty or waiting for a touch-up.',
                'url' => route('housekeeping.index'),
                'tone' => 'amber',
            ];
        }

        $waitlist = (int) ($snap['waitlistCount'] ?? 0);
        if ($waitlist > 0) {
            $items[] = [
                'key' => 'waitlist',
                'label' => 'Guests on the waitlist',
                'count' => $waitlist,
                'detail' => 'Waiting for a room that matches their dates.',
                'url' => can_do('reservations.view') ? route('reservations.index', ['view' => 'waitlist']) : null,
                'tone' => 'slate',
            ];
        }

        return $items;
    }

    /** v7.41 — the printable Day Report for the workspace board. */
    public function report(Request $request)
    {
        $snap = ReservationWorkspaceService::snapshot($this->boardDate($request));

        // The printed day is handed to the next shift, so it carries the same list
        // of outstanding work the screen leads with.
        return view('reservations.workspace-report', $snap + ['attention' => $this->attention($snap)]);
    }
}
