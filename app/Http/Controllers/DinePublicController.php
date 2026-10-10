<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\TableReservation;
use App\Services\TableBookingService as TB;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** v3.2 public restaurant table booking page and the guest's manage / cancel link. */
class DinePublicController extends Controller
{
    protected const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private'];

    protected function hotel(string $code): Hotel
    {
        $hotel = Hotel::where('code', $code)->where('is_active', true)->firstOrFail();
        Tenant::set($hotel);

        return $hotel;
    }

    public function show(Request $request, string $code)
    {
        $hotel = $this->hotel($code);
        $outlets = TB::enabledOutlets(true);
        abort_if($outlets->isEmpty(), 404);
        $outlet = $outlets->firstWhere('id', $request->integer('outlet')) ?? $outlets->first();
        $c = TB::cfg($outlet);
        $today = now()->startOfDay();
        $max = $today->copy()->addDays((int) $c['days_ahead']);
        $date = rescue(fn () => Carbon::parse((string) $request->input('date', $today->toDateString()))->startOfDay(), $today, false);
        $date = $date->lt($today) ? $today : ($date->gt($max) ? $max : $date);
        $party = max(1, min((int) $c['max_online_party'], (int) $request->input('party', 2)));
        $slots = TB::availability($outlet, $date->toDateString(), $party, null, true);

        return response()->view('public.dine', ['hotel' => $hotel, 'outlets' => $outlets, 'outlet' => $outlet, 'cfg' => $c, 'date' => $date, 'party' => $party,
            'slots' => $slots, 'min' => $today, 'max' => $max, 'duration' => TB::durationFor($outlet, $party)])->withHeaders(self::HEADERS);
    }

    public function store(Request $request, string $code)
    {
        $this->hotel($code);
        $data = $request->validate([
            'outlet_id' => 'required|integer|owned:outlets', 'date' => 'required|date', 'time' => 'required|date_format:H:i', 'party_size' => 'required|integer|min:1|max:50',
            'name' => 'required|string|max:120', 'phone' => 'required|string|min:6|max:30', 'email' => 'nullable|email|max:150',
            'occasion' => ['nullable', Rule::in(array_keys(TableReservation::OCCASIONS))], 'tags' => 'nullable|array', 'tags.*' => Rule::in(['high_chair', 'wheelchair', 'window', 'quiet', 'vegetarian', 'allergy']),
            'notes' => 'nullable|string|max:500', 'website' => 'nullable|size:0', // honeypot
        ]);
        $outlet = TB::enabledOutlets(true)->firstWhere('id', (int) $data['outlet_id']);
        abort_unless($outlet, 404);
        // flood guard: a phone number can hold at most 3 upcoming online bookings
        if (TableReservation::where('phone', \App\Services\MessageService::normalise($data['phone']) ?? trim($data['phone']))->whereIn('status', TableReservation::UPCOMING)->where('start_at', '>', now())->count() >= 3) {
            throw ValidationException::withMessages(['phone' => 'You already have 3 upcoming bookings with us – please call the restaurant to make another.']);
        }
        $r = TB::book($outlet, $data, 'online');

        return redirect()->route('dine.manage', TB::tokenFor($r))->with('success', $r->status === 'confirmed' ? 'Your table is booked – see you soon!' : 'Thank you – we have your request and will confirm shortly.');
    }

    protected function booking(string $token): TableReservation
    {
        $r = TB::findByToken($token);
        abort_unless($r, 404);
        Tenant::set(Hotel::findOrFail($r->hotel_id));

        return $r->fresh(['outlet', 'tables']);
    }

    public function manage(string $token)
    {
        $r = $this->booking($token);

        return response()->view('public.dine-manage', ['hotel' => hotel(), 'r' => $r, 'token' => $token, 'canCancel' => $r->isUpcoming() && $r->start_at->gt(now())])->withHeaders(self::HEADERS);
    }

    public function cancel(Request $request, string $token)
    {
        $r = $this->booking($token);
        $data = $request->validate(['reason' => 'nullable|string|max:200']);
        TB::cancel($r, (string) ($data['reason'] ?? ''), true);

        return redirect()->route('dine.manage', $token)->with('success', 'Your booking has been cancelled. Thank you for letting us know.');
    }
}
