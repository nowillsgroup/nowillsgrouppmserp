<?php

namespace App\Services;

use App\Models\GuestRequest;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceRequest;
use App\Models\MenuItem;
use App\Models\MessageLog;
use App\Models\Outlet;
use App\Models\PosOrder;
use App\Models\ReservationRoom;
use App\Models\SpaAppointment;
use App\Models\SpaRoom;
use App\Models\SpaService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guest mobile portal: service requests (with linked maintenance tickets / housekeeping tasks),
 * in-room dining orders (pending on the POS until staff accept), spa self-booking and the welcome message.
 */
class GuestPortalService
{
    public const MAX_OPEN_REQUESTS = 10;

    public const MAX_PENDING_DINING_LINES = 40;

    public const MAX_PORTAL_SPA = 3;

    protected const MAINT_CATEGORY = ['Air-conditioning' => 'ac', 'TV / remote' => 'it', 'Wi-Fi' => 'it', 'Hot water' => 'plumbing', 'Plumbing / leak' => 'plumbing', 'Lights / power' => 'electrical', 'Door / lock' => 'carpentry'];

    protected const URGENT = ['Hot water', 'Plumbing / leak', 'Door / lock', 'Lights / power'];

    public static function enabled(): bool
    {
        return setting('portal_enabled', '1') === '1';
    }

    /** Items the guest can pick for a category (amenities are configurable). */
    public static function items(string $category): array
    {
        if ($category === 'amenity' && trim((string) setting('portal_amenities'))) {
            return collect(preg_split('/\r?\n/', setting('portal_amenities')))->map(fn ($l) => trim($l))->filter()->take(30)->values()->all();
        }

        return GuestRequest::CATEGORIES[$category][2] ?? [];
    }

    // ───────────────────────────── requests ─────────────────────────────

    public static function raise(ReservationRoom $stay, array $d, string $source = 'portal'): GuestRequest
    {
        $cat = $d['category'] ?? '';
        if (! isset(GuestRequest::CATEGORIES[$cat])) {
            throw new RuntimeException('Unknown request type.');
        }
        $state = $stay->portalState();
        if ($state !== 'inhouse' && ! ($cat === 'front_desk' && $state === 'upcoming')) {
            throw new RuntimeException($state === 'upcoming' ? 'Requests open once you have checked in. You can still message the front desk.' : 'This stay has ended.');
        }
        $item = trim((string) ($d['item'] ?? ''));
        $notes = trim((string) ($d['notes'] ?? '')) ?: null;
        $items = self::items($cat);
        if (in_array($cat, ['front_desk', 'wakeup', 'late_checkout', 'checkout'])) {
            $item = $items[0];
        } elseif (! in_array($item, $items, true)) {
            throw new RuntimeException('Please choose an item from the list.');
        }
        if (($cat === 'front_desk' || $item === 'Other') && ! $notes) {
            throw new RuntimeException('Please describe what you need.');
        }
        $when = ! empty($d['preferred_at']) ? Carbon::parse($d['preferred_at']) : null;
        if (in_array($cat, ['wakeup', 'transport']) && ! $when) {
            throw new RuntimeException('Please choose a date and time.');
        }
        if ($when && $when->lt(now()->subMinutes(5))) {
            throw new RuntimeException('The time must be in the future.');
        }
        if ($cat === 'late_checkout') {
            $when = Carbon::parse($stay->departure_date->toDateString().' '.($d['time'] ?? '14:00'));
            if ($when->format('H:i') <= substr((string) hotel()->checkout_time, 0, 5)) {
                throw new RuntimeException('Choose a time after the standard check-out time ('.substr((string) hotel()->checkout_time, 0, 5).').');
            }
        }
        $qty = max(1, min(10, (int) ($d['quantity'] ?? 1)));

        return DB::transaction(function () use ($stay, $cat, $item, $notes, $when, $qty, $source, $d) {
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail(); // serialise per stay
            $state = $stay->portalState();
            if ($state !== 'inhouse' && ! ($cat === 'front_desk' && $state === 'upcoming')) {
                throw new RuntimeException('This stay is no longer accepting this request. Please refresh the guest portal.');
            }
            if ($cat === 'late_checkout') {
                $when = Carbon::parse($stay->departure_date->toDateString().' '.($d['time'] ?? '14:00'));
                if ($when->format('H:i') <= substr((string) hotel()->checkout_time, 0, 5)) {
                    throw new RuntimeException('Choose a time after the standard check-out time ('.substr((string) hotel()->checkout_time, 0, 5).').');
                }
            }
            $open = GuestRequest::where('reservation_room_id', $stay->id)->whereIn('status', GuestRequest::OPEN);
            if (in_array($source, ['portal', 'tv'], true) && (clone $open)->count() >= self::MAX_OPEN_REQUESTS) {
                throw new RuntimeException('You have several requests in progress. Please wait for them to be completed or call the front desk.');
            }
            if ($cat !== 'front_desk' && (clone $open)->where('category', $cat)->where('item', $item)->exists()) {
                throw new RuntimeException("“{$item}” is already requested – we are on it.");
            }
            $req = GuestRequest::create([
                'request_no' => Numbering::next('guest_request'), 'reservation_room_id' => $stay->id, 'room_id' => $stay->room_id,
                'category' => $cat, 'item' => $item, 'quantity' => in_array($cat, ['amenity']) ? $qty : 1, 'notes' => $notes, 'preferred_at' => $when,
                'priority' => ($cat === 'maintenance' && in_array($item, self::URGENT)) || $cat === 'checkout' ? 'high' : 'normal',
                'source' => $source, 'status' => 'new', 'handled_by' => $source === 'staff' ? auth()->id() : null,
            ]);
            self::link($req, $stay);
            ActivityLogger::log('requests', 'create', "Guest request {$req->request_no}: {$req->item}".($stay->room ? " (room {$stay->room->room_no})" : '').($source === 'portal' ? ' via portal' : ($source === 'tv' ? ' via room TV' : '')), $req);

            return $req;
        });
    }

    /** Maintenance issues open a ticket; "clean my room" raises (or escalates) today's housekeeping task. */
    protected static function link(GuestRequest $req, ReservationRoom $stay): void
    {
        if ($req->category === 'wakeup' && $req->preferred_at) {
            // v3.5: a wake-up request books the call in the wake-up queue (throws → the request is not created)
            $call = WakeupService::schedule($stay, $req->preferred_at->copy(), false, $req->notes, in_array($req->source, ['portal', 'tv'], true) ? $req->source : 'phone', $req->id);
            $req->forceFill(['linked_type' => 'WakeupCall', 'linked_id' => $call->id, 'status' => 'acknowledged', 'acknowledged_at' => now()])->save();
        }
        if ($req->category === 'maintenance' && $stay->room_id) {
            $t = MaintenanceRequest::create([
                'ticket_no' => Numbering::next('ticket'), 'room_id' => $stay->room_id, 'category' => self::MAINT_CATEGORY[$req->item] ?? 'general',
                'title' => 'Guest: '.$req->item, 'description' => trim(($req->notes ?? '')."\nRaised by the guest ({$req->request_no})."), 'priority' => $req->priority === 'high' ? 'high' : 'normal',
                'status' => 'open', 'block_room' => false, 'reported_by' => auth()->id(),
            ]);
            $req->forceFill(['linked_type' => 'MaintenanceRequest', 'linked_id' => $t->id])->save();
        }
        if ($req->category === 'housekeeping' && in_array($req->item, ['Clean my room now', 'Turn-down service', 'Change bed linen']) && $stay->room_id) {
            $type = match ($req->item) { 'Turn-down service' => 'turndown', 'Change bed linen' => 'linen_change', default => 'cleaning' };
            $task = HousekeepingTask::where('room_id', $stay->room_id)->whereDate('task_date', bdate()->toDateString())->whereIn('status', ['pending', 'in_progress'])
                ->when($type !== 'cleaning', fn ($q) => $q->where('task_type', $type))->first();
            if ($task) {
                $task->forceFill(['priority' => 'high', 'dnd_at' => null, 'notes' => trim(($task->notes ? $task->notes.' · ' : '').'Guest request '.$req->request_no)])->save();
            } else {
                $task = HousekeepingTask::create([
                    'room_id' => $stay->room_id, 'task_type' => array_key_exists($type, HousekeepingTask::TYPES) ? $type : 'cleaning', 'priority' => 'high',
                    'credits' => HousekeepingService::credits($type === 'cleaning' ? 'stayover' : $type), 'status' => 'pending', 'task_date' => bdate()->toDateString(),
                    'notes' => 'Guest request '.$req->request_no.($req->notes ? ': '.$req->notes : ''),
                ]);
            }
            $req->forceFill(['linked_type' => 'HousekeepingTask', 'linked_id' => $task->id])->save();
        }
    }

    public static function transition(GuestRequest $req, string $action, ?string $note = null): GuestRequest
    {
        return DB::transaction(function () use ($req, $action, $note) {
            // Wake-up workflows lock the call before its linked guest request.
            // Keep that order for cancellation/decline to avoid a lock cycle.
            $snapshot = GuestRequest::whereKey($req->id)->firstOrFail();
            $wakeCall = $snapshot->linked_type === 'WakeupCall' && in_array($action, ['decline', 'cancel'], true)
                ? \App\Models\WakeupCall::whereKey($snapshot->linked_id)->lockForUpdate()->first()
                : null;
            $req = GuestRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
            $to = ['acknowledge' => 'acknowledged', 'start' => 'in_progress', 'done' => 'done', 'decline' => 'declined', 'cancel' => 'cancelled'][$action] ?? null;
            $allowed = [
                'acknowledged' => ['new'], 'in_progress' => ['new', 'acknowledged'], 'done' => GuestRequest::OPEN,
                'declined' => GuestRequest::OPEN, 'cancelled' => $req->linked_type === 'WakeupCall' ? ['new', 'acknowledged'] : ['new'], // v3.5: a booked wake-up can still be cancelled
            ];
            if (! $to || ! in_array($req->status, $allowed[$to], true)) {
                throw new RuntimeException('This request is already '.str_replace('_', ' ', $req->status).'.');
            }
            if ($to === 'declined' && ! trim((string) $note)) {
                throw new RuntimeException('Please give the guest a reason.');
            }
            $req->forceFill([
                'status' => $to, 'staff_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : $req->staff_note,
                'acknowledged_at' => $req->acknowledged_at ?? ($to !== 'cancelled' ? now() : null),
                'completed_at' => in_array($to, ['done', 'declined', 'cancelled'], true) ? now() : null,
                'handled_by' => $action === 'cancel' ? $req->handled_by : (auth()->id() ?? $req->handled_by),
            ])->save();
            if ($wakeCall && $wakeCall->isScheduled()) {
                $wakeCall->forceFill(['status' => 'cancelled', 'completed_at' => now(), 'completed_by' => auth()->id(), 'outcome_note' => 'Request '.$to])->save(); // v3.5
            }
            ActivityLogger::log('requests', $action, "Guest request {$req->request_no} → ".GuestRequest::STATUSES[$to].($note ? ": {$note}" : ''), $req);

            return $req;
        });
    }

    /** Linked ticket / task finished → the guest's request is done. */
    public static function syncFromLinked(string $type, int $id): void
    {
        GuestRequest::where('linked_type', $type)->where('linked_id', $id)->whereIn('status', GuestRequest::OPEN)->get()
            ->each(fn ($r) => $r->forceFill(['status' => 'done', 'acknowledged_at' => $r->acknowledged_at ?? now(), 'completed_at' => now(),
                'staff_note' => $r->staff_note ?? ($type === 'MaintenanceRequest' ? 'Fixed by maintenance' : 'Done by housekeeping')])->save());
    }

    /** On check-out: an express check-out request is fulfilled, everything else still open is closed. */
    public static function closeForStay(ReservationRoom $stay): void
    {
        DB::transaction(function () use ($stay) {
            GuestRequest::where('reservation_room_id', $stay->id)->whereIn('status', GuestRequest::OPEN)
                ->where(fn ($q) => $q->whereNull('linked_type')->orWhere('linked_type', '!=', 'TransportTrip')) // v3.3: the trip closes its own request
                ->orderBy('id')->lockForUpdate()->get()->each(function ($r) {
                    $done = in_array($r->category, ['checkout', 'late_checkout']);
                    $r->forceFill(['status' => $done ? 'done' : 'cancelled', 'completed_at' => now(), 'acknowledged_at' => $r->acknowledged_at ?? now(),
                        'staff_note' => $r->staff_note ?? ($done ? 'Checked out' : 'Closed at check-out')])->save();
                });
        });
    }

    public static function rate(GuestRequest $req, int $stars): void
    {
        if ($req->status !== 'done') {
            throw new RuntimeException('You can rate a request once it is done.');
        }
        $req->forceFill(['rating' => max(1, min(5, $stars))])->save();
    }

    // ───────────────────────────── dining ─────────────────────────────

    public static function diningOutlet(): ?Outlet
    {
        $id = (int) setting('portal_dining_outlet_id');

        return $id ? Outlet::where('is_active', true)->find($id) : null;
    }

    public static function orderDining(ReservationRoom $stay, array $lines, ?string $notes = null, string $source = 'portal'): PosOrder
    {
        $outlet = self::diningOutlet();
        if (! $outlet || $stay->portalState() !== 'inhouse') {
            throw new RuntimeException('In-room dining is not available right now.');
        }

        return DB::transaction(function () use ($stay, $lines, $outlet, $notes, $source) {
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            if ($stay->portalState() !== 'inhouse') {
                throw new RuntimeException('In-room dining is no longer available for this stay. Please refresh the guest portal.');
            }
            $order = PosOrder::where('outlet_id', $outlet->id)->where('reservation_room_id', $stay->id)->where('source', $source)->where('status', 'open')->latest('id')->first();
            if ($order && $order->items()->where('is_pending', true)->where('is_cancelled', false)->count() + count($lines) > self::MAX_PENDING_DINING_LINES) {
                throw new RuntimeException('Your previous order is still being confirmed. Please wait a moment.');
            }
            $order ??= PosOrder::create([
                'order_no' => Numbering::next('pos_order'), 'outlet_id' => $outlet->id, 'reservation_room_id' => $stay->id,
                'order_type' => 'room_service', 'source' => $source, 'customer_name' => ($stay->guest ?? $stay->reservation?->guest)?->full_name,
                'business_date' => Tenant::hotel()->business_date, 'status' => 'open', 'covers' => 1,
            ]);
            foreach ($lines as $index => $l) {
                $m = MenuItem::with(['modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->with('modifiers')])
                    ->where('is_available', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->find($l['id']);
                if (! $m) {
                    throw new RuntimeException('One of the selected dishes is no longer available.');
                }
                [$modifierSnapshots, $modifierAmount] = PosModifierService::snapshot($m, $l['modifiers'] ?? [], $index);
                $rate = round((float) $m->price + $modifierAmount, 2);
                $order->items()->create(['menu_item_id' => $m->id, 'item_name' => $m->name, 'qty' => $l['qty'], 'rate' => $rate,
                    'modifier_amount' => $modifierAmount, 'modifiers' => $modifierSnapshots, 'tax_rate' => $m->tax_rate,
                    'amount' => round($rate * $l['qty'], 2), 'kot_no' => null, 'is_pending' => true, 'notes' => $l['notes'] ?? $notes]);
            }
            ActivityLogger::log('pos', 'portal_order', 'In-room dining via '.($source === 'tv' ? 'the room TV' : 'guest portal').': '.count($lines)." line(s) for room {$stay->room?->room_no}", $order);

            return $order;
        });
    }

    // ───────────────────────────── spa ─────────────────────────────

    public static function needsTherapist(SpaService $s): bool
    {
        return in_array($s->category, ['spa', 'salon']);
    }

    /** Free start times for a service on a date, with the therapist/room that would be assigned. */
    public static function spaSlots(SpaService $service, string $date): array
    {
        $out = [];
        $therapists = self::needsTherapist($service) ? SpaBookingService::therapists() : collect([null]);
        $rooms = self::needsTherapist($service) ? SpaRoom::where('is_active', true)->get() : collect();
        $rooms = $rooms->isEmpty() ? collect([null]) : $rooms;
        $close = setting('spa_close', '21:00');
        $earliest = $date === now()->toDateString() ? now()->addMinutes(60) : null;
        foreach (SpaBookingService::slots() as $slot) {
            $start = Carbon::parse($date.' '.$slot);
            $end = (clone $start)->addMinutes(max(5, (int) $service->duration_min));
            if ($end->format('H:i') > $close || $end->toDateString() !== $date || ($earliest && $start->lt($earliest))) {
                continue;
            }
            foreach ($therapists as $t) {
                foreach ($rooms as $r) {
                    if ($t === null && $r === null || ! SpaBookingService::clash($date, $start->format('H:i:s'), $end->format('H:i:s'), $t?->id, $r?->id)) {
                        $out[$slot] = ['employee_id' => $t?->id, 'spa_room_id' => $r?->id, 'end' => $end->format('H:i')];
                        continue 3;
                    }
                }
            }
        }

        return $out;
    }

    public static function bookSpa(ReservationRoom $stay, int $serviceId, string $date, string $time, int $pax = 1): SpaAppointment
    {
        if ($stay->portalState() !== 'inhouse' || setting('portal_spa_enabled', '1') !== '1') {
            throw new RuntimeException('Spa booking is not available right now.');
        }
        $service = SpaService::where('is_active', true)->findOrFail($serviceId);
        if ($date < bdate()->toDateString() || $date > $stay->departure_date->toDateString()) {
            throw new RuntimeException('Choose a date during your stay.');
        }

        return DB::transaction(function () use ($stay, $service, $date, $time, $pax) {
            $stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            if ($stay->portalState() !== 'inhouse' || $date < bdate()->toDateString() || $date > $stay->departure_date->toDateString()) {
                throw new RuntimeException('Spa booking is no longer available for this stay. Please refresh the guest portal.');
            }
            \App\Models\Setting::firstOrCreate(['key' => 'spa_booking_lock'], ['value' => '0']);
            \App\Models\Setting::where('key', 'spa_booking_lock')->lockForUpdate()->first(); // serialise slot allocation across guests
            $upcoming = SpaAppointment::where('reservation_room_id', $stay->id)->where('status', 'booked')->where('notes', 'like', 'Booked via guest portal%')->count();
            if ($upcoming >= self::MAX_PORTAL_SPA) {
                throw new RuntimeException('You already have '.self::MAX_PORTAL_SPA.' bookings. Please call the spa for more.');
            }
            $slot = self::spaSlots($service, $date)[$time] ?? null;
            if (! $slot) {
                throw new RuntimeException('Sorry, that time has just been taken. Please pick another.');
            }
            $appt = SpaBookingService::save(['spa_service_id' => $service->id, 'employee_id' => $slot['employee_id'], 'spa_room_id' => $slot['spa_room_id'],
                'reservation_room_id' => $stay->id, 'date' => $date, 'start_time' => $time, 'pax' => max(1, min(4, $pax)), 'notes' => 'Booked via guest portal']);

            return $appt;
        });
    }

    // ───────────────────────────── welcome message ─────────────────────────────

    public const WELCOME_TEMPLATE = 'Welcome to {hotel}, {guest}! Your room is {room}. Order in-room dining, request services and view your bill here: {portal_link}';

    public static function sendWelcome(ReservationRoom $stay, ?string $channel = null): MessageLog
    {
        $stay->loadMissing('reservation.guest', 'guest', 'room');
        $guest = $stay->guest ?? $stay->reservation->guest;
        $body = MessageService::render(setting('sms_tpl_welcome') ?: self::WELCOME_TEMPLATE,
            MessageService::varsFor($stay->reservation) + ['room' => $stay->room?->room_no ?? '', 'portal_link' => $stay->portalUrl(), 'guest' => $guest?->first_name ?: 'Guest']);

        return MessageService::send($guest?->phone, $body, $channel ?? MessageService::defaultChannel(), 'welcome', $stay->reservation);
    }

    /** Check-in hook: create the link and (optionally) text it to the guest. Never throws. */
    public static function onCheckIn(ReservationRoom $stay): void
    {
        try {
            if (! self::enabled()) {
                return;
            }
            $stay->portalUrl();
            if (setting('portal_auto_welcome', '0') === '1' && setting('sms_enabled', '0') === '1' && ($stay->guest ?? $stay->reservation?->guest)?->phone) {
                self::sendWelcome($stay);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
