<?php

namespace App\Http\Controllers;

use App\Models\PaymentMode;
use App\Models\ReservationRoom;
use App\Models\SpaAppointment;
use App\Models\SpaRoom;
use App\Models\SpaService;
use App\Services\SpaBookingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SpaController extends Controller
{
    public function index(Request $request)
    {
        $date = Carbon::parse($request->input('date', bdate()->toDateString()))->toDateString();
        $appts = SpaAppointment::with('service', 'therapist', 'spaRoom', 'stay.room')->where('date', $date)->orderBy('start_time')->get();
        $therapists = SpaBookingService::therapists();
        $slots = SpaBookingService::slots();
        $step = max(15, (int) setting('spa_slot_min', 30));
        $grid = [];
        $cols = $therapists->pluck('name', 'id')->all() + [0 => 'No therapist / activity'];
        foreach ($appts->whereNotIn('status', ['cancelled', 'no_show']) as $a) {
            $col = $a->employee_id && isset($cols[$a->employee_id]) ? $a->employee_id : 0;
            $slotIdx = null;
            foreach ($slots as $i => $s) {
                if ($s.':00' <= $a->start_time) {
                    $slotIdx = $i;
                }
            }
            $slotIdx ??= 0;
            $span = max(1, (int) ceil((strtotime($a->end_time) - strtotime(max($a->start_time, $slots[$slotIdx].':00'))) / 60 / $step));
            $grid[$col][$slotIdx][] = ['a' => $a, 'span' => min($span, count($slots) - $slotIdx)];
        }
        if (empty($grid[0])) {
            unset($cols[0]);
        }

        return view('spa.index', [
            'date' => $date, 'appts' => $appts, 'cols' => $cols, 'slots' => $slots, 'grid' => $grid, 'step' => $step,
            'services' => SpaService::where('is_active', true)->orderBy('category')->orderBy('name')->get(),
            'rooms' => SpaRoom::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'therapists' => $therapists->pluck('name', 'id'),
            'stays' => ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->get()
                ->mapWithKeys(fn ($s) => [$s->id => 'Room '.$s->room?->room_no.' – '.($s->guest ?? $s->reservation?->guest)?->full_name])->sort(),
            'paymentModes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher'])->pluck('name', 'id'),
            'stats' => ['booked' => $appts->whereIn('status', ['booked', 'in_progress'])->count(), 'done' => $appts->where('status', 'completed')->count(),
                'revenue' => $appts->where('status', 'completed')->where('settlement', '!=', 'complimentary')->sum(fn ($a) => $a->net()),
                'cancelled' => $appts->whereIn('status', ['cancelled', 'no_show'])->count()],
        ]);
    }

    protected function rules(): array
    {
        return [
            'spa_service_id' => 'required|integer|owned:spa_services', 'employee_id' => 'nullable|integer|owned:employees', 'spa_room_id' => 'nullable|integer|owned:spa_rooms',
            'date' => 'required|date', 'start_time' => 'required|date_format:H:i', 'pax' => 'nullable|integer|min:1|max:50',
            'reservation_room_id' => 'nullable|integer|owned:reservation_rooms', 'customer_name' => 'nullable|required_without:reservation_room_id|string|max:120',
            'customer_phone' => 'nullable|string|max:30', 'price' => 'nullable|numeric|min:0|max:9999999', 'discount' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255',
        ];
    }

    public function store(Request $request)
    {
        $a = SpaBookingService::save($request->validate($this->rules()));

        return redirect()->route('spa.index', ['date' => $a->date->toDateString()])->with('success', "Booked {$a->appt_no} at ".substr($a->start_time, 0, 5).'.');
    }

    public function update(Request $request, SpaAppointment $appt)
    {
        $a = SpaBookingService::save($request->validate($this->rules()), $appt);

        return redirect()->route('spa.index', ['date' => $a->date->toDateString()])->with('success', "{$a->appt_no} updated.");
    }

    public function status(Request $request, SpaAppointment $appt)
    {
        $data = $request->validate(['status' => 'required|in:in_progress,cancelled,no_show,booked', 'reason' => 'nullable|string|max:200']);
        SpaBookingService::setStatus($appt, $data['status'], $data['reason'] ?? null);

        return back()->with('success', "{$appt->appt_no}: ".str_replace('_', ' ', $data['status']).'.');
    }

    public function settle(Request $request, SpaAppointment $appt)
    {
        $data = $request->validate(['settlement' => 'required|in:room_post,payment,complimentary', 'payment_mode_id' => 'nullable|required_if:settlement,payment|integer|owned:payment_modes',
            'reference' => 'nullable|string|max:60', 'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100']);
        SpaBookingService::settle($appt, $data['settlement'], $data['payment_mode_id'] ?? null, $data['reference'] ?? null, [
            'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
        ]);

        return back()->with('success', "{$appt->appt_no} completed and settled.");
    }
}
