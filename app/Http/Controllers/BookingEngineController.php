<?php

namespace App\Http\Controllers;

use App\Models\BusinessSource;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Models\MarketingMessage;
use App\Services\AvailabilityService;
use App\Services\ContractService;
use App\Services\CrmService;
use App\Services\RateService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public, commission-free direct booking engine.
 */
class BookingEngineController extends Controller
{
    protected function hotel(string $code): Hotel
    {
        $hotel = Hotel::where('code', $code)->where('is_active', true)->firstOrFail();
        Tenant::set($hotel);
        abort_unless(Setting::where('key', 'booking_engine_enabled')->value('value') === '1', 404);

        return $hotel;
    }

    public function show(Request $request, string $code)
    {
        $hotel = $this->hotel($code);
        $min = $hotel->business_date->toDateString();
        $arr = $request->input('arrival', $min);
        $dep = $request->input('departure', Carbon::parse($arr)->addDay()->toDateString());
        if ($arr < $min || $dep <= $arr) {
            $arr = $min;
            $dep = Carbon::parse($min)->addDay()->toDateString();
        }
        // v2.6: promo code (typed, or carried by a campaign link) and the campaign-link token for attribution
        $promoCode = strtoupper(trim((string) $request->input('promo')));
        $promoError = null;
        $token = preg_match('/^[A-Za-z0-9]{32}$/', (string) $request->input('m')) ? $request->input('m') : null;
        // v6.2: tracked-link code (from /go/{code}) carried through to the booking for digital attribution
        $dl = preg_match('/^[a-z0-9-]{3,40}$/', (string) $request->input('dl')) ? $request->input('dl') : null;
        // v2.7: corporate / agent access code → the contract's negotiated rates (not combinable with promo codes)
        $corpCode = strtoupper(trim((string) $request->input('corp')));
        $contract = ContractService::byAccessCode($corpCode, $arr);
        $corpError = $corpCode !== '' && ! $contract ? 'That corporate code is not valid for these dates.' : null;
        if ($contract && $promoCode !== '') {
            $promoError = 'Promo codes cannot be combined with a corporate rate.';
            $promoCode = '';
        }
        $types = RoomType::where('is_active', true)->where('show_online', true)->orderBy('base_rate')->get()
            ->each(function ($t) use ($arr, $dep, $promoCode, &$promoError, $contract) {
                $t->quote = $contract ? ContractService::quote($contract, $t, $arr, $dep, 1) : RateService::quote($t, $arr, $dep);
                $t->promo = null;
                if ($promoCode !== '') {
                    try {
                        if ($p = CrmService::validatePromo($promoCode, $t->id, $arr, $dep)) {
                            $t->quote = CrmService::applyPromo($t->quote, $p);
                            $t->promo = $p;
                        }
                    } catch (ValidationException $e) {
                        $t->promo_error = $e->errors()['promo'][0] ?? null;
                    }
                }
                // stop-sell or unmet minimum stay closes the type online
                $t->available = ($t->quote['stop_sell'] || ! $t->quote['min_stay_ok']) ? 0 : AvailabilityService::typeAvailable($t->id, $arr, $dep, null, $contract?->id);
            });

        if ($promoCode !== '' && $types->every(fn ($t) => ! $t->promo)) {
            $promoError = $types->pluck('promo_error')->filter()->first() ?? 'That promo code is not valid.';
        }

        return view('public.booking', ['deposit' => \App\Services\OnlinePaymentService::depositPolicy(), 'hotel' => $hotel, 'types' => $types, 'arrival' => $arr, 'departure' => $dep,
            'promoCode' => $promoCode, 'promoError' => $promoError, 'token' => $token, 'dl' => $dl, 'contract' => $contract, 'corpCode' => $corpCode, 'corpError' => $corpError,
            'nights' => Carbon::parse($arr)->diffInDays(Carbon::parse($dep))]);
    }

    public function store(Request $request, string $code)
    {
        $hotel = $this->hotel($code);
        $data = $request->validate([
            'arrival' => 'required|date|after_or_equal:'.$hotel->business_date->toDateString(), 'departure' => 'required|date|after:arrival',
            'room_type_id' => 'required|integer|owned:room_types', 'rooms' => 'required|integer|min:1|max:5',
            'adults' => 'required|integer|min:1|max:20', 'children' => 'nullable|integer|min:0|max:10',
            'first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80',
            'phone' => 'required|string|max:30', 'email' => 'required|email|max:150', 'special_request' => 'nullable|string|max:1000',
            'website' => 'nullable|size:0', // honeypot
            'promo' => 'nullable|string|max:30', 'm' => 'nullable|string|size:32', 'dl' => 'nullable|string|max:40', 'marketing_opt_in' => 'nullable|boolean', 'corp' => 'nullable|string|max:20',
        ]);
        $type = RoomType::where('show_online', true)->findOrFail($data['room_type_id']);
        $contract = null;
        if (! empty($data['corp'])) {
            $contract = ContractService::byAccessCode($data['corp'], $data['arrival']);
            if (! $contract) {
                throw ValidationException::withMessages(['corp' => 'That corporate code is not valid for these dates.']);
            }
            if (! empty($data['promo'])) {
                throw ValidationException::withMessages(['promo' => 'Promo codes cannot be combined with a corporate rate.']);
            }
        }
        $quote = $contract ? ContractService::quote($contract, $type, $data['arrival'], $data['departure'], max(1, intdiv((int) $data['adults'], max(1, (int) $data['rooms']))))
            : RateService::quote($type, $data['arrival'], $data['departure']);
        if ($quote['stop_sell']) {
            throw ValidationException::withMessages(['room_type_id' => 'Sorry, this room type is closed for sale on the selected dates.']);
        }
        if (! $quote['min_stay_ok']) {
            throw ValidationException::withMessages(['departure' => "A minimum stay of {$quote['min_stay']} nights applies to these dates."]);
        }
        if (AvailabilityService::typeAvailable($type->id, $data['arrival'], $data['departure'], null, $contract?->id) < $data['rooms']) {
            throw ValidationException::withMessages(['room_type_id' => 'Sorry, not enough rooms available for those dates.']);
        }
        $known = Guest::where('email', $data['email'])->where('first_name', $data['first_name'])->first();
        $promo = CrmService::validatePromo($data['promo'] ?? null, $type->id, $data['arrival'], $data['departure'], $known);
        if ($promo) {
            $quote = CrmService::applyPromo($quote, $promo);
        }
        $via = ! empty($data['m']) ? MarketingMessage::where('token', $data['m'])->first() : null;
        $deposit = \App\Services\OnlinePaymentService::bookingDeposit($quote, (int) $data['rooms']);
        $res = DB::transaction(function () use ($data, $type, $quote, $deposit, $promo, $request, $contract) {
            // Serialize online bookings with desk/API reservations on the
            // physical room rows, then re-check before inserting the stay.
            AvailabilityService::lockRoomInventory([$type->id]);
            if (AvailabilityService::typeAvailable($type->id, $data['arrival'], $data['departure'], null, $contract?->id) < $data['rooms']) {
                throw ValidationException::withMessages(['room_type_id' => 'Sorry, that room type was just booked by another guest.']);
            }
            if (Guest::where('email', $data['email'])->where('first_name', $data['first_name'])->where('is_blacklisted', true)->exists()) {
                throw ValidationException::withMessages(['email' => 'This guest is not eligible for online booking.']);
            }
            $guest = Guest::whereNull('privacy_status')->where('email', $data['email'])->where('first_name', $data['first_name'])->first()
                ?? Guest::create(['email' => $data['email'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name'] ?? null, 'phone' => $data['phone']]);
            if ($guest->is_blacklisted) {
                throw ValidationException::withMessages(['email' => 'This guest is not eligible for online booking.']);
            }
            if ($request->boolean('marketing_opt_in')) {
                CrmService::setConsent($guest, ['email' => true, 'sms' => true], 'booking_engine', $request->ip());
            }
            $res = Reservation::create([
                'company_id' => $contract?->company_id, 'rate_contract_id' => $contract?->id, // guest pays; the booking counts towards the contract
                'promo_code_id' => $promo?->id, 'promo_discount' => round(($quote['promo_discount'] ?? 0) * $data['rooms'], 2),
                'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id,
                'business_source_id' => BusinessSource::where('type', 'booking_engine')->value('id'),
                'arrival_date' => $data['arrival'], 'departure_date' => $data['departure'],
                'adults' => $data['adults'], 'children' => $data['children'] ?? 0, 'status' => $deposit > 0 ? 'tentative' : 'confirmed',
                'special_request' => $data['special_request'] ?? null, 'purpose' => $contract ? 'Business' : 'Leisure',
            ]);
            $perRoom = max(1, intdiv($data['adults'], $data['rooms']));
            for ($i = 0; $i < $data['rooms']; $i++) {
                $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $guest->id, 'arrival_date' => $data['arrival'], 'departure_date' => $data['departure'], 'rate' => $quote['average'], 'adults' => $perRoom, 'status' => 'reserved']);
            }
            ActivityLogger::log('booking_engine', 'create', "Online booking {$res->reservation_no} by {$guest->full_name}".($promo ? " with promo {$promo->code}" : ''), $res);

            return $res;
        });
        if ($via) {
            rescue(fn () => CrmService::attribute($res, $via), null, true);
        }
        rescue(fn () => \App\Services\DigitalMarketingService::attributeBooking($res, $data['dl'] ?? $request->cookie(\App\Services\DigitalMarketingService::COOKIE)), null, true); // v6.2

        if ($deposit > 0) {
            // held as tentative until the deposit is paid; confirmation messages go out on payment
            $link = \App\Services\OnlinePaymentService::create(['amount' => $deposit, 'purpose' => 'booking', 'minutes' => (int) setting('pay_booking_hold_minutes', 60)], $res);

            return redirect()->route('pay.show', $link->token);
        }
        \App\Services\MailService::autoConfirm($res);
        \App\Services\MessageService::autoConfirm($res);

        return redirect()->route('booking.done', [$code, $res->reservation_no]);
    }

    public function done(string $code, string $no)
    {
        $hotel = $this->hotel($code);
        $res = Reservation::with('guest', 'rooms.roomType')->where('reservation_no', $no)->where('created_at', '>=', now()->subHours(3))->firstOrFail();

        $link = \App\Models\OnlinePayment::where('reservation_id', $res->id)->where('purpose', 'booking')->latest('id')->first();

        return view('public.booking-done', ['hotel' => $hotel, 'r' => $res, 'link' => $link]);
    }
}
