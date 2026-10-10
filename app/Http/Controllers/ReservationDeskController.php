<?php

namespace App\Http\Controllers;

use App\Models\PaymentMode;
use App\Models\ReservationDeskView;
use App\Models\ReservationRoom;
use App\Models\ReservationWaitlist;
use App\Models\Room;
use App\Models\RoomAmenity;
use App\Models\RoomAmenityLink;
use App\Models\RoomImage;
use App\Models\RoomOutage;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\ReservationDeskService;
use App\Services\RateService;
use App\Services\StayService;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Extra actions for the existing Reservations module. Not a second module. */
class ReservationDeskController extends Controller
{
    public function search(Request $request)
    {
        $groups = ReservationDeskService::searchGroups((string) $request->input('q'));

        return response()->json([
            'guests' => $groups['guests']->map(fn ($g) => ['label' => $g->full_name, 'meta' => $g->phone, 'url' => route('guests.show', $g)])->values(),
            'reservations' => $groups['reservations']->map(fn ($r) => ['label' => $r->reservation_no.' · '.($r->guest->full_name ?? ''), 'meta' => $r->status, 'url' => route('reservations.show', $r)])->values(),
            'rooms' => $groups['rooms']->map(fn ($r) => ['label' => 'Room '.$r->room_no, 'meta' => $r->roomType->name ?? '', 'url' => route('reservations.index', ['view' => 'timeline', 'q' => $r->room_no])])->values(),
            'companies' => $groups['companies']->map(fn ($c) => ['label' => $c->name, 'meta' => $c->phone, 'url' => route('reservations.index', ['view' => 'list', 'q' => $c->name])])->values(),
            'folios' => $groups['folios']->map(fn ($f) => ['label' => $f->folio_no, 'meta' => $f->guest->full_name ?? $f->status, 'url' => route('folios.show', $f)])->values(),
        ]);
    }

    public function export(Request $request)
    {
        $rows = ReservationDeskService::listQuery($request)->limit(5000)->get();
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Reservation', 'Guest', 'Phone', 'Arrival', 'Departure', 'Nights', 'Rooms', 'Adults', 'Children', 'Source', 'Status', 'Group']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r->reservation_no, $r->guest->full_name ?? '', $r->guest->phone ?? '',
                $r->arrival_date->format('Y-m-d'), $r->departure_date->format('Y-m-d'), $r->nights,
                $r->rooms->where('status', '!=', 'cancelled')->map(fn ($s) => $s->room?->room_no ?? $s->roomType->code)->join(', '),
                $r->adults, $r->children, $r->source?->name, $r->status, $r->group_name,
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reservations-'.now()->format('Ymd-His').'.csv"',
        ]);
    }

    public function printPlan(Request $request)
    {
        $data = ReservationDeskService::page($request);
        $data['days'] = min(14, $data['days']);

        return view('reservations.plan-print', $data);
    }

    public function daySummary(Request $request)
    {
        $data = ReservationDeskService::page($request->merge(['view' => 'dashboard']));
        $data['days'] = min(14, $data['days']);

        return view('reservations.day-summary-print', $data);
    }

    public function stayCard(Request $request, ReservationRoom $stay)
    {
        $stay->load(['reservation.guest', 'reservation.company', 'reservation.source', 'room', 'roomType', 'folio.transactions', 'ratePlan']);
        $from = $stay->status === 'checked_in' ? bdate() : $stay->arrival_date;
        $free = AvailabilityService::reservationRooms($from, $stay->departure_date, null, $stay->id)
            ->when($stay->status === 'checked_in', fn ($rooms) => $rooms->where('status', 'vacant')->values());

        return $this->card($request, 'reservations.desk-stay', [
            'stay' => $stay,
            'free' => $free,
            'modes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->orderBy('name')->get(),
            'categories' => config('pms.charge_categories'),
            'taxRate' => TaxService::roomTax((float) $stay->rate)['rate'],
            'title' => $stay->reservation->reservation_no,
        ]);
    }

    public function roomCard(Request $request, Room $room)
    {
        $room->load(['roomType.amenityLinks.amenity', 'roomType.images', 'floor', 'images', 'amenityLinks.amenity', 'currentStay.reservation.guest']);
        $outage = RoomOutage::where('room_id', $room->id)->where('status', 'active')->orderBy('starts_on')->first();

        return $this->card($request, 'reservations.desk-room', [
            'room' => $room,
            'outage' => $outage,
            'amenities' => ReservationDeskService::amenityNames($room),
            'photo' => ReservationDeskService::photo($room),
            'title' => 'Room '.$room->room_no,
        ]);
    }

    public function previewCheckout(Request $request, ReservationRoom $stay)
    {
        try {
            $msg = StayService::quoteCheckout($stay);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, $msg);
    }

    public function dates(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate([
            'arrival_date' => 'required|date',
            'departure_date' => 'required|date|after:arrival_date',
        ]);
        try {
            $delta = StayService::changeDates($stay, $data['arrival_date'], $data['departure_date']);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }
        $sign = $delta['delta_nights'] > 0 ? '+' : '';
        $msg = 'Dates updated. Expected room-charge change '.$sign.$delta['delta_nights'].' night(s), '.money($delta['delta_room']).' plus tax '.money($delta['delta_tax']).'. Night audit posts room charges; this did not post or reverse a folio line.';

        return $this->ok($request, $msg);
    }

    public function previewSplit(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate([
            'split_date' => 'required|date',
            'room_id' => 'required|integer|owned:rooms',
            'rate' => 'nullable|numeric|min:0',
        ]);
        try {
            $q = StayService::quoteSplit($stay, $data['split_date'], (int) $data['room_id'], $request->filled('rate') ? (float) $data['rate'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }
        $msg = 'Split on '.$q['split']->format('d M').'. First part '.$q['first_nights'].' night(s), expected room and tax '.money($q['first_total']).'. Second part in room '.$q['room']->room_no.', '.$q['second_nights'].' night(s) at '.money($q['rate2']).', expected '.money($q['second_total']).'. Night audit posts each night. This preview has not changed the stay.';

        return $this->ok($request, $msg);
    }

    public function split(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate([
            'split_date' => 'required|date',
            'room_id' => 'required|integer|owned:rooms',
            'rate' => 'nullable|numeric|min:0',
        ]);
        try {
            $next = StayService::splitStay($stay, $data['split_date'], (int) $data['room_id'], $request->filled('rate') ? (float) $data['rate'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, 'Stay split. The second part is reserved in room '.($next->room->room_no ?? '').' from '.$next->arrival_date->format('d M').'.');
    }

    public function previewDates(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate([
            'arrival_date' => 'required|date',
            'departure_date' => 'required|date|after:arrival_date',
        ]);
        try {
            $q = StayService::quoteDates($stay, $data['arrival_date'], $data['departure_date']);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }
        $sign = $q['delta_nights'] > 0 ? '+' : '';
        $msg = 'Departure '.$q['old_departure']->format('d M').' → '.$q['departure']->format('d M').'. '.$sign.$q['delta_nights'].' night(s), room '.money($q['delta_room']).', tax '.money($q['delta_tax']).'. New expected room and tax '.money($q['new_total']).'. Night audit posts the charge; this preview has not changed the folio or the stay.';

        return $this->ok($request, $msg);
    }

    public function placeCheck(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['room_id' => 'required|integer|owned:rooms']);
        try {
            $msg = StayService::describePlace($stay, (int) $data['room_id']);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, $msg);
    }

    public function place(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate([
            'room_id' => 'required|integer|owned:rooms',
            'rate' => 'nullable|numeric|min:0',
        ]);
        try {
            $msg = StayService::placeStay($stay, (int) $data['room_id'], $request->filled('rate') ? (float) $data['rate'] : null, 'Reservation desk');
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, $msg);
    }

    public function previewOutage(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|integer|owned:rooms',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'reason' => 'required|in:'.implode(',', array_keys(RoomOutage::REASONS)),
        ]);
        try {
            $msg = ReservationDeskService::describeBlock((int) $data['room_id'], $data['starts_on'], $data['ends_on'], $data['reason']);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, $msg);
    }

    public function storeOutage(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|integer|owned:rooms',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'reason' => 'required|in:'.implode(',', array_keys(RoomOutage::REASONS)),
            'notes' => 'nullable|string|max:255',
        ]);
        try {
            ReservationDeskService::block((int) $data['room_id'], $data['starts_on'], $data['ends_on'], $data['reason'], $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($request, $e);
        }

        return $this->ok($request, 'Room blocked. It is off sale for those nights and will not accept a drag-drop.');
    }

    public function releaseOutage(Request $request, RoomOutage $outage)
    {
        ReservationDeskService::release($outage);

        return $this->ok($request, 'Block released.');
    }

    public function storeWaitlist(Request $request)
    {
        $data = $request->validate([
            'contact_name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'arrival_date' => 'required|date|after_or_equal:'.bdate()->toDateString(),
            'departure_date' => 'required|date|after:arrival_date',
            'room_type_id' => 'nullable|integer|owned:room_types',
            'adults' => 'required|integer|min:1|max:20',
            'children' => 'nullable|integer|min:0|max:20',
            'priority' => 'nullable|integer|min:0|max:9',
            'notes' => 'nullable|string|max:500',
            'guest_id' => 'nullable|integer|owned:guests',
        ]);
        $row = ReservationDeskService::addWaitlist($data);

        return back()->with('success', $row->contact_name.' is on the waitlist. Nothing is booked until you convert it.');
    }

    public function checkWaitlist(ReservationWaitlist $wait)
    {
        if (! $wait->room_type_id) {
            return back()->with('error', 'Choose a room type before checking availability. The guest is still on the waitlist.');
        }
        $quote = RateService::quote($wait->roomType, $wait->arrival_date, $wait->departure_date);
        $free = ($quote['stop_sell'] || ! $quote['min_stay_ok'])
            ? 0
            : AvailabilityService::typeAvailable($wait->room_type_id, $wait->arrival_date, $wait->departure_date);
        $name = $wait->roomType->name ?? 'That type';

        return back()->with($free > 0 ? 'success' : 'error', $free > 0
            ? $name.' has '.$free.' room(s) free for those dates. Convert only if you want to book one. Nothing has been reserved.'
            : 'No '.$name.' is free for those dates. The guest stays on the waitlist.');
    }

    public function tick(Request $request)
    {
        try {
            $date = \Illuminate\Support\Carbon::parse($request->input('date', bdate()->toDateString()));
        } catch (\Throwable) {
            $date = bdate();
        }

        return response()->json(['tick' => ReservationDeskService::tick($date)]);
    }

    public function makePrimary(RoomImage $image)
    {
        RoomImage::where('room_id', $image->room_id)->where('room_type_id', $image->room_type_id)->update(['is_primary' => false]);
        $image->update(['is_primary' => true, 'is_active' => true]);

        return back()->with('success', 'Primary photo updated.');
    }

    public function convertWaitlist(Request $request, ReservationWaitlist $wait)
    {
        try {
            $res = ReservationDeskService::convertWaitlist($wait);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('reservations.show', $res)->with('success', "Reservation {$res->reservation_no} created from the waitlist. The room is not assigned yet.");
    }

    public function cancelWaitlist(ReservationWaitlist $wait)
    {
        try {
            ReservationDeskService::cancelWaitlist($wait);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Waitlist entry cancelled. No reservation was created.');
    }

    public function saveView(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'query' => 'nullable|string|max:1000', 'is_default' => 'nullable|boolean']);
        if ($request->boolean('is_default')) {
            ReservationDeskView::where('user_id', auth()->id())->update(['is_default' => false]);
        }
        ReservationDeskView::create([
            'user_id' => auth()->id(), 'name' => $data['name'], 'query' => $data['query'] ?? '', 'is_default' => $request->boolean('is_default'),
        ]);

        return back()->with('success', 'View saved.');
    }

    public function destroyView(ReservationDeskView $deskView)
    {
        abort_unless($deskView->user_id === auth()->id(), 403);
        $deskView->delete();

        return back()->with('success', 'Saved view removed.');
    }

    public function amenities()
    {
        ReservationDeskService::syncAmenities();

        return view('reservations.amenities', [
            'amenities' => RoomAmenity::withCount('links')->orderBy('name')->get(),
            'types' => RoomType::with(['amenityLinks', 'images'])->where('is_active', true)->orderBy('name')->get(),
            'rooms' => Room::with(['images', 'roomType'])->where('is_active', true)->orderBy('room_no')->get(),
        ]);
    }

    public function storeAmenity(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'category' => 'nullable|string|max:40',
            'description' => 'nullable|string|max:255',
            'room_type_id' => 'nullable|integer|owned:room_types',
            'room_id' => 'nullable|integer|owned:rooms',
        ]);
        $amenity = RoomAmenity::firstOrCreate(['name' => trim($data['name'])], [
            'category' => $data['category'] ?: 'general', 'description' => $data['description'] ?? null,
            'show_on_desk' => true, 'show_online' => $request->boolean('show_online', true), 'is_active' => true,
        ]);
        if (! empty($data['room_type_id']) || ! empty($data['room_id'])) {
            RoomAmenityLink::firstOrCreate([
                'room_amenity_id' => $amenity->id,
                'room_type_id' => $data['room_type_id'] ?? null,
                'room_id' => $data['room_id'] ?? null,
            ]);
        }

        return back()->with('success', 'Amenity saved. It is shown only where you attached it.');
    }

    public function storeImage(Request $request)
    {
        $data = $request->validate([
            'photo' => 'required|image|max:4096',
            'room_id' => 'nullable|integer|owned:rooms',
            'room_type_id' => 'nullable|integer|owned:room_types',
            'caption' => 'nullable|string|max:160',
        ]);
        if (empty($data['room_id']) && empty($data['room_type_id'])) {
            return back()->with('error', 'Choose a room or a room type for the photo.');
        }
        $dir = public_path('uploads/room-images');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $ext = $request->file('photo')->extension() ?: 'jpg';
        $name = 'room-'.($data['room_id'] ?? 't'.$data['room_type_id']).'-'.now()->format('YmdHisv').'-'.bin2hex(random_bytes(4)).'.'.$ext;
        $request->file('photo')->move($dir, $name);
        $path = 'uploads/room-images/'.$name;
        $primary = ! RoomImage::where('room_id', $data['room_id'] ?? null)->where('room_type_id', $data['room_type_id'] ?? null)->where('is_active', true)->exists();
        RoomImage::create([
            'room_id' => $data['room_id'] ?? null, 'room_type_id' => $data['room_type_id'] ?? null,
            'path' => $path, 'caption' => $data['caption'] ?? null, 'is_primary' => $primary, 'is_active' => true,
        ]);

        return back()->with('success', 'Photo saved on the room record. It is not a stock picture.');
    }

    public function destroyImage(RoomImage $image)
    {
        $image->update(['is_active' => false]);

        return back()->with('success', 'Photo hidden from the desk.');
    }

    protected function card(Request $request, string $view, array $data)
    {
        if ($request->boolean('partial') || $request->ajax()) {
            return view($view, $data);
        }

        return view('reservations.desk-frame', $data + ['inner' => $view]);
    }

    protected function ok(Request $request, string $msg)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    protected function fail(Request $request, RuntimeException $e)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return back()->with('error', $e->getMessage());
    }
}
