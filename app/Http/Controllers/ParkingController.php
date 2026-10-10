<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Hotel;
use App\Models\ParkingCharge;
use App\Models\ParkingPermit;
use App\Models\ParkingSession;
use App\Models\ParkingVehicle;
use App\Models\ParkingZone;
use App\Models\ReservationRoom;
use App\Services\EInvoiceService;
use App\Services\ParkingService as PS;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v6.6 Parking & valet: desk (entry / exit / lookup), valet board, tickets, vehicles, permits, zones, tariff; public valet QR page. */
class ParkingController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    protected function lookups(): array
    {
        return [
            'zones' => ParkingZone::where('is_active', true)->orderBy('sort')->orderBy('code')->get(),
            'stays' => ReservationRoom::with(['guest', 'room'])->where(fn ($q) => $q->where('status', 'checked_in')
                ->orWhere(fn ($w) => $w->where('status', 'reserved')->where('arrival_date', '<=', bdate()->toDateString())))->get()->sortBy(fn ($s) => $s->room?->room_no ?? 'zzz')->values(),
            'modes' => PS::walkInModes(), 'cfg' => PS::cfg(), 'runners' => PS::runners(),
        ];
    }

    // ------------------------------------------------------------------ desk

    public function index(Request $r)
    {
        $active = ParkingSession::with(['zone', 'vehicle', 'stay.room', 'stay.guest'])->whereIn('status', ParkingSession::ACTIVE)
            ->when($r->filled('zone'), fn ($q) => $q->where('parking_zone_id', (int) $r->query('zone')))
            ->when($r->filled('kind'), fn ($q) => $q->where('kind', $r->query('kind')))
            ->when($r->filled('q'), function ($q) use ($r) {
                $t = trim((string) $r->query('q'));
                $p = PS::normalisePlate($t);
                $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('guest_name', 'like', "%{$t}%")->orWhere('key_tag', $t)
                    ->when($p !== '', fn ($x) => $x->orWhereHas('vehicle', fn ($v) => $v->where('plate', 'like', "%{$p}%"))));
            })
            ->orderByRaw("FIELD(status, 'requested', 'ready', 'parked')")->orderByDesc('entered_at')->orderByDesc('id')->get();
        $quotes = $active->mapWithKeys(fn ($s) => [$s->id => PS::quote($s)]);

        return view('parking.index', ['active' => $active, 'quotes' => $quotes, 'occ' => PS::occupancy(), 'k' => PS::kpis(), 'alerts' => PS::alerts(),
            'trend' => PS::revenueTrend(14), 'look' => $r->filled('plate') ? PS::lookup((string) $r->query('plate')) : null,
            'departed' => ParkingSession::with(['stay.room'])->whereIn('status', ParkingSession::ACTIVE)->where('kind', 'guest')->whereHas('stay', fn ($q) => $q->whereIn('status', ['checked_out', 'cancelled', 'no_show']))->get(),
        ] + $this->lookups());
    }

    public function valet()
    {
        $cars = ParkingSession::with(['zone', 'vehicle', 'stay.room', 'runner'])->where('is_valet', true)->whereIn('status', ParkingSession::ACTIVE)->orderBy('requested_at')->orderBy('entered_at')->get();

        return view('parking.valet', ['requested' => $cars->where('status', 'requested')->values(), 'ready' => $cars->where('status', 'ready')->values(),
            'parked' => $cars->where('status', 'parked')->sortBy('key_tag', SORT_NATURAL)->values(), 'k' => PS::kpis(),
            'recent' => ParkingSession::with(['vehicle', 'runner'])->where('is_valet', true)->where('status', 'out')->whereNotNull('requested_at')->orderByDesc('exited_at')->limit(10)->get(),
        ] + $this->lookups());
    }

    public function checkIn(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $s = PS::checkIn($r->except('entered_at', 'status', 'token'), $r->user());

            return redirect()->route('parking.index')->with('success', "Ticket {$s->number}: {$s->plate} parked in {$s->zone->name}".($s->is_valet ? " – key tag {$s->key_tag}" : '').'.')
                ->with('ticket', $s->id);
        }, '');
    }

    // ------------------------------------------------------------------ tickets

    public function sessions(Request $r)
    {
        $from = $r->filled('from') ? rescue(fn () => Carbon::parse((string) $r->query('from'))->startOfDay(), null, false) : null;
        $from ??= bdate()->copy()->subDays(13);
        $to = $r->filled('to') ? rescue(fn () => Carbon::parse((string) $r->query('to'))->endOfDay(), null, false) : null;
        $to ??= bdate()->copy()->endOfDay();
        $q = ParkingSession::with(['zone', 'vehicle', 'stay.room'])->whereBetween('entered_at', [$from, $to])
            ->when($r->filled('status'), fn ($q) => $q->where('status', $r->query('status')))
            ->when($r->filled('kind'), fn ($q) => $q->where('kind', $r->query('kind')))
            ->when($r->query('valet') === '1', fn ($q) => $q->where('is_valet', true))
            ->when($r->filled('zone'), fn ($q) => $q->where('parking_zone_id', (int) $r->query('zone')))
            ->when($r->filled('q'), function ($q) use ($r) {
                $t = trim((string) $r->query('q'));
                $p = PS::normalisePlate($t);
                $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('guest_name', 'like', "%{$t}%")
                    ->when($p !== '', fn ($x) => $x->orWhereHas('vehicle', fn ($v) => $v->where('plate', 'like', "%{$p}%"))));
            })->orderByDesc('entered_at')->orderByDesc('id');
        $sum = (clone $q)->reorder()->selectRaw('count(*) n, sum(fee) fee, sum(fee_waived) waived')->first();

        return view('parking.sessions', ['sessions' => $q->paginate(30)->withQueryString(), 'from' => $from, 'to' => $to, 'sum' => $sum] + $this->lookups());
    }

    public function session(ParkingSession $parkingsession)
    {
        $s = $parkingsession->load(['zone', 'vehicle.guest', 'stay.room', 'stay.guest', 'permit', 'charges.folio', 'folio', 'paymentMode', 'parker', 'runner', 'exiter', 'validator']);

        return view('parking.session', ['s' => $s, 'q' => $s->isActive() ? PS::quote($s) : null, 'qlost' => $s->isActive() ? PS::quote($s, null, true) : null,
            'history' => ParkingSession::where('parking_vehicle_id', $s->parking_vehicle_id)->whereKeyNot($s->id)->orderByDesc('entered_at')->limit(8)->get()] + $this->lookups());
    }

    public function ticket(ParkingSession $parkingsession)
    {
        $s = $parkingsession->load(['zone', 'vehicle', 'stay.room']);

        return view('parking.ticket', ['s' => $s, 'qr' => $s->token ? EInvoiceService::qrSvg($s->publicUrl()) : EInvoiceService::qrSvg($s->number.'|'.$s->plate), 'cfg' => PS::cfg()]);
    }

    public function request(Request $r, ParkingSession $parkingsession)
    {
        return $this->attempt(function () use ($r, $parkingsession) {
            $s = PS::request($parkingsession, (string) $r->input('via', 'desk'), $r->user());

            return "{$s->plate} requested – key tag {$s->key_tag}.";
        }, '');
    }

    public function ready(Request $r, ParkingSession $parkingsession)
    {
        return $this->attempt(function () use ($r, $parkingsession) {
            $s = PS::ready($parkingsession, $r->user(), $r->filled('runner_id') ? (int) $r->input('runner_id') : null);

            return "{$s->plate} is at the door ({$s->waitMinutes()} min).";
        }, '');
    }

    public function repark(Request $r, ParkingSession $parkingsession)
    {
        return $this->attempt(fn () => PS::repark($parkingsession, $r->user()) ? "{$parkingsession->plate} is back in its bay." : '', '');
    }

    public function move(Request $r, ParkingSession $parkingsession)
    {
        return $this->attempt(fn () => ($s = PS::move($parkingsession, (int) $r->input('parking_zone_id'), $r->input('bay'), $r->user())) ? "{$s->plate} moved to {$s->zone->name}." : '', '');
    }

    public function validateTicket(Request $r, ParkingSession $parkingsession)
    {
        return $this->attempt(fn () => PS::validate($parkingsession, (int) $r->input('hours'), $r->input('reference'), $r->user()) ? "{$parkingsession->number}: {$r->input('hours')} h validated." : '', '');
    }

    public function checkOut(Request $r, ParkingSession $parkingsession)
    {
        return $this->attempt(function () use ($r, $parkingsession) {
            $s = PS::checkOut($parkingsession, $r->only('lost_ticket', 'settle', 'payment_mode_id', 'reference', 'waive', 'waive_reason', 'cheque_bank_name', 'cheque_number', 'cheque_bank_branch'), $r->user());
            $msg = "{$s->plate} has left ({$s->durationLabel()}).";
            if ((float) $s->fee > 0) {
                $msg .= $s->folio?->status === 'open' ? ' '.money($s->fee).' + tax posted to '.$s->folio->folio_no.'.' : ' Paid '.money($s->folio?->fresh()?->totals()['payments'] ?? $s->fee).($s->folio?->invoice_no ? ', invoice '.$s->folio->invoice_no : '').'.';
            } elseif ((float) $s->fee_waived > 0) {
                $msg .= ' '.money($s->fee_waived).' waived.';
            }

            return $msg;
        }, '');
    }

    public function cancel(Request $r, ParkingSession $parkingsession)
    {
        return $this->attempt(fn () => PS::cancel($parkingsession, (string) $r->input('reason'), $r->user()) ? "Ticket {$parkingsession->number} cancelled." : '', '');
    }

    public function postNightly(Request $r)
    {
        return $this->attempt(function () {
            $res = PS::postNightly();

            return $res['posted'] ? "{$res['posted']} vehicle(s) charged to room folios for tonight – ".money($res['amount']).'.' : 'Nothing to post – every in-house vehicle is already charged for tonight (or guest parking is free).';
        }, '');
    }

    // ------------------------------------------------------------------ vehicles & permits

    public function vehicles(Request $r)
    {
        $q = ParkingVehicle::with('guest')->withCount(['sessions as visits' => fn ($q) => $q->where('status', 'out')])
            ->when($r->query('view') === 'banned', fn ($q) => $q->where('is_banned', true))
            ->when($r->filled('q'), function ($q) use ($r) {
                $t = trim((string) $r->query('q'));
                $p = PS::normalisePlate($t);
                $q->where(fn ($w) => $w->where('owner_name', 'like', "%{$t}%")->orWhere('make', 'like', "%{$t}%")->when($p !== '', fn ($x) => $x->orWhere('plate', 'like', "%{$p}%")));
            })->orderByDesc('last_seen_at')->orderByDesc('id');

        return view('parking.vehicles', ['vehicles' => $q->paginate(30)->withQueryString(), 'banned' => ParkingVehicle::where('is_banned', true)->count(),
            'inside' => ParkingSession::whereIn('status', ParkingSession::ACTIVE)->pluck('number', 'parking_vehicle_id'), 'look' => $r->filled('plate') ? PS::lookup((string) $r->query('plate')) : null] + $this->lookups());
    }

    public function vehicleUpdate(Request $r, ParkingVehicle $parkingvehicle)
    {
        return $this->attempt(fn () => PS::saveVehicle($parkingvehicle, $r->all()) ? "Vehicle {$parkingvehicle->plate_display} updated." : '', '');
    }

    public function vehicleBan(Request $r, ParkingVehicle $parkingvehicle)
    {
        $ban = $r->input('do') !== 'unban';

        return $this->attempt(fn () => PS::ban($parkingvehicle, $ban, $r->input('reason'), $r->user()) ? "{$parkingvehicle->plate_display} ".($ban ? 'banned.' : 'allowed again.') : '', '');
    }

    public function permits(Request $r)
    {
        $d = bdate()->toDateString();
        $q = ParkingPermit::with(['vehicle', 'zone', 'employee'])
            ->when($r->query('view') === 'active', fn ($q) => $q->where('status', 'active')->where('valid_from', '<=', $d)->where('valid_to', '>=', $d))
            ->when($r->query('view') === 'expiring', fn ($q) => $q->where('status', 'active')->whereBetween('valid_to', [$d, bdate()->copy()->addDays(7)->toDateString()]))
            ->when($r->query('view') === 'expired', fn ($q) => $q->where(fn ($w) => $w->where('status', 'revoked')->orWhere('valid_to', '<', $d)))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('holder_name', 'like', '%'.$r->query('q').'%')->orWhere('number', 'like', '%'.$r->query('q').'%')
                ->orWhereHas('vehicle', fn ($v) => $v->where('plate', 'like', '%'.PS::normalisePlate($r->query('q')).'%'))))
            ->orderByRaw("status = 'revoked'")->orderBy('valid_to')->orderBy('id');

        return view('parking.permits', ['permits' => $q->paginate(30)->withQueryString(), 'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'emp_code']),
            'canManage' => PS::canManage($r->user())] + $this->lookups());
    }

    public function permitSave(Request $r, ?ParkingPermit $parkingpermit = null)
    {
        return $this->attempt(fn () => ($p = PS::savePermit($r->all(), $r->user(), $parkingpermit?->exists ? $parkingpermit : null)) ? "Permit {$p->number} saved." : '', '');
    }

    public function permitRevoke(Request $r, ParkingPermit $parkingpermit)
    {
        return $this->attempt(fn () => PS::revokePermit($parkingpermit, (string) $r->input('reason'), $r->user()) ? "Permit {$parkingpermit->number} revoked." : '', '');
    }

    // ------------------------------------------------------------------ zones & settings

    public function zones()
    {
        return view('parking.zones', ['occ' => PS::occupancy(), 'all' => ParkingZone::withCount('activeSessions')->orderBy('sort')->orderBy('code')->get()] + $this->lookups());
    }

    public function zoneSave(Request $r, ?ParkingZone $parkingzone = null)
    {
        return $this->attempt(fn () => ($z = PS::saveZone($r->all(), $parkingzone?->exists ? $parkingzone : null)) ? "Zone {$z->code} saved." : '', '');
    }

    public function settings()
    {
        return view('parking.settings', ['cfg' => PS::cfg(), 'examples' => collect([20, 45, 90, 180, 420, 1500])->map(fn ($m) => ['m' => $m, 't' => PS::tariff($m)])]);
    }

    public function saveSettings(Request $r)
    {
        return $this->attempt(fn () => PS::saveSettings($r->all()), 'Parking settings saved.');
    }

    // ------------------------------------------------------------------ public valet page (QR on the ticket)

    protected function publicSession(string $token): ParkingSession
    {
        $s = ParkingSession::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $hotel = Hotel::find($s->hotel_id);
        abort_unless($hotel?->is_active, 404);
        Tenant::set($hotel);

        return ParkingSession::with('vehicle')->findOrFail($s->id);
    }

    public function publicPage(string $token)
    {
        $s = $this->publicSession($token);

        return response()->view('parking.public', ['s' => $s, 'hotel' => hotel(), 'cfg' => PS::cfg()])
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    public function publicRequest(string $token)
    {
        $s = $this->publicSession($token);
        try {
            PS::request($s, 'qr');
        } catch (RuntimeException $e) {
            return redirect()->route('parking.public', ['token' => $token])->with('error', $e->getMessage());
        }

        return redirect()->route('parking.public', ['token' => $token])->with('success', 'Your car is on its way – please come to the main entrance.');
    }
}
