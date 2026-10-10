<?php

namespace App\Http\Controllers;

use App\Models\BdCompetitor;
use App\Models\Company;
use App\Models\CroEnquiry;
use App\Models\RoomType;
use App\Services\CroService as CRO;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v6.4 Central reservations desk: group availability & booking, the enquiry log, lost business, guest lookup and KPIs. */
class CroController extends Controller
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

    protected function range(Request $r, int $back = 29): array
    {
        $from = $r->filled('from') ? rescue(fn () => Carbon::parse((string) $r->query('from'))->startOfDay(), null, false) : null;
        $from ??= bdate()->copy()->subDays($back);
        $to = $r->filled('to') ? rescue(fn () => Carbon::parse((string) $r->query('to'))->startOfDay(), null, false) : null;
        $to ??= bdate()->copy();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        return [$from, $to];
    }

    protected function lookups(): array
    {
        return ['types' => RoomType::where('is_active', true)->orderBy('base_rate')->get(), 'competitors' => BdCompetitor::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::orderBy('name')->get(['id', 'name'])];
    }

    // ------------------------------------------------------------------ desk: group availability search & book

    public function index(Request $r)
    {
        $u = $r->user();
        $hotels = CRO::groupHotels($u);
        $q = ['arrival' => $r->query('arrival', bdate()->toDateString()), 'departure' => $r->query('departure', bdate()->copy()->addDay()->toDateString()),
            'rooms' => (int) $r->query('rooms', 1), 'adults' => (int) $r->query('adults', 2), 'children' => (int) $r->query('children', 0),
            'asked' => (int) $r->query('asked', hotel()->id), 'enquiry' => $r->query('enquiry')];
        $enq = $q['enquiry'] ? CroEnquiry::find((int) $q['enquiry']) : null;
        if ($enq && ! $r->has('arrival')) {
            $q = array_merge($q, ['arrival' => $enq->arrival_date->toDateString(), 'departure' => $enq->departure_date->toDateString(), 'rooms' => $enq->rooms, 'adults' => $enq->adults, 'children' => $enq->children]);
        }
        $results = null;
        $error = null;
        if ($r->has('arrival') || $enq) {
            try {
                $results = CRO::search($q, $u);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        $prefill = null;
        if ($r->query('guest') && $r->query('ghotel')) {
            $gh = $hotels->firstWhere('id', (int) $r->query('ghotel'));
            $g = $gh ? CRO::withHotel($gh, fn () => \App\Models\Guest::whereNull('erased_at')->find((int) $r->query('guest'))) : null;
            $prefill = $g ? ['first_name' => $g->first_name, 'last_name' => $g->last_name, 'phone' => $g->phone, 'email' => $g->email, 'nationality' => $g->nationality,
                'name' => $g->full_name, 'hotel' => $gh->name, 'vip' => (bool) $g->is_vip, 'blacklisted' => (bool) $g->is_blacklisted] : null;
        } elseif ($enq) {
            $parts = preg_split('/\s+/', trim($enq->caller_name), 2);
            $prefill = ['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'phone' => $enq->phone, 'email' => $enq->email, 'nationality' => $enq->guest?->nationality,
                'name' => $enq->caller_name, 'hotel' => null, 'vip' => false, 'blacklisted' => false];
        }
        $nights = rescue(fn () => max(1, (int) Carbon::parse($q['arrival'])->diffInDays(Carbon::parse($q['departure']))), 1, false);

        return view('cro.index', ['hotels' => $hotels, 'q' => $q, 'results' => $results, 'error' => $error, 'enq' => $enq, 'nights' => $nights,
            'kpi' => CRO::kpis(bdate()->copy()->startOfMonth(), bdate()->copy()), 'alerts' => CRO::alerts(), 'canBook' => CRO::canBook($u), 'group' => $hotels->count() > 1,
            'due' => CroEnquiry::with('agent')->where('status', 'open')->orderByRaw('follow_up_at IS NULL, follow_up_at')->limit(6)->get(),
            'booked' => session('cro_booked'), 'prefill' => $prefill] + $this->lookups());
    }

    public function book(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $hotel = CRO::hotelFor($r->input('hotel_id'), $r->user());
            $x = CRO::book($hotel, $r->all(), $r->user());
            $msg = $x['referral']
                ? "Referred to {$x['hotel']->name}: reservation {$x['reservation']->reservation_no} (".money($x['value']).") – enquiry {$x['enquiry']->number} kept in the group."
                : "Reservation {$x['reservation']->reservation_no} booked at {$x['hotel']->name} (".money($x['value']).") – enquiry {$x['enquiry']->number}.";

            return redirect()->route('cro.index')->with('success', $msg)->with('cro_booked', ['hotel' => $x['hotel']->name, 'hotel_id' => $x['hotel']->id,
                'no' => $x['reservation']->reservation_no, 'id' => $x['reservation']->id, 'value' => $x['value'], 'referral' => $x['referral'], 'enquiry' => $x['enquiry']->number]);
        }, 'Booked.');
    }

    // ------------------------------------------------------------------ enquiry log

    public function enquiries(Request $r)
    {
        [$from, $to] = $this->range($r);
        $status = array_key_exists((string) $r->query('status'), CroEnquiry::STATUSES) ? $r->query('status') : null;
        $type = in_array($r->query('type'), ['denial', 'regret'], true) ? $r->query('type') : null;
        $agent = (int) $r->query('agent') ?: null;
        $term = trim((string) $r->query('q'));
        $rows = CroEnquiry::with('agent', 'roomType', 'reservation', 'referredHotel', 'referredFrom', 'competitor')
            ->when(! $status || $status !== 'open', fn ($q) => $q->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]))
            ->when($status, fn ($q) => $q->where('status', $status))->when($type, fn ($q) => $q->where('loss_type', $type))->when($agent, fn ($q) => $q->where('taken_by', $agent))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('caller_name', 'like', "%$term%")->orWhere('phone', 'like', "%$term%")->orWhere('email', 'like', "%$term%")->orWhere('number', $term)))
            ->orderByDesc('id')->paginate(40)->withQueryString();

        return view('cro.enquiries', ['rows' => $rows, 'from' => $from, 'to' => $to, 'status' => $status, 'type' => $type, 'agent' => $agent, 'term' => $term,
            'people' => CRO::people(), 'kpi' => CRO::kpis($from, $to), 'alerts' => CRO::alerts(), 'canBook' => CRO::canBook($r->user())] + $this->lookups());
    }

    public function store(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $asked = $r->filled('asked_hotel_id') ? CRO::hotelFor($r->input('asked_hotel_id'), $r->user()) : hotel();
            if ($asked->id !== hotel()->id) {
                $data = collect($r->all())->except(['room_type_id', 'company_id', 'competitor_id'])->all(); // ids of this hotel mean nothing at the sister
                $e = CRO::withHotel($asked, fn () => CRO::logEnquiry($data, $r->user()));

                return back()->with('success', "Enquiry {$e->number} logged at {$asked->name}".($e->status === 'lost' ? ' as lost ('.$e->reasonLabel().').' : '.'));
            }
            $e = CRO::logEnquiry($r->all(), $r->user());

            return redirect()->route('cro.enquiry', $e)->with('success', "Enquiry {$e->number} logged".($e->status === 'lost' ? ' as lost ('.$e->reasonLabel().').' : ' – follow up by '.$e->follow_up_at->format('d M H:i').'.'));
        }, 'Logged.');
    }

    public function show(Request $r, CroEnquiry $croenquiry)
    {
        $croenquiry->load('agent', 'closer', 'roomType', 'reservation.guest', 'guest', 'company', 'competitor', 'referredHotel', 'referredFrom', 'contacts.user');

        return view('cro.show', ['e' => $croenquiry, 'canBook' => CRO::canBook($r->user()), 'group' => CRO::isGroupAgent($r->user())] + $this->lookups());
    }

    public function markLost(Request $r, CroEnquiry $croenquiry)
    {
        return $this->attempt(function () use ($r, $croenquiry) { CRO::markLost($croenquiry, $r->all(), $r->user()); return "Enquiry {$croenquiry->number} closed as lost."; }, 'Closed.');
    }

    public function reopen(Request $r, CroEnquiry $croenquiry)
    {
        return $this->attempt(function () use ($r, $croenquiry) { CRO::reopen($croenquiry, $r->user()); return "Enquiry {$croenquiry->number} reopened."; }, 'Reopened.');
    }

    public function contact(Request $r, CroEnquiry $croenquiry)
    {
        return $this->attempt(function () use ($r, $croenquiry) { CRO::addContact($croenquiry, $r->all(), $r->user()); return 'Contact recorded.'; }, 'Recorded.');
    }

    // ------------------------------------------------------------------ guest lookup

    public function guests(Request $r)
    {
        $term = trim((string) $r->query('q'));

        return view('cro.guests', ['term' => $term, 'rows' => CRO::guestLookup($term, $r->user()), 'hotels' => CRO::groupHotels($r->user())]);
    }

    // ------------------------------------------------------------------ analysis

    public function lost(Request $r)
    {
        [$from, $to] = $this->range($r);
        $dFrom = ($r->filled('stay') ? rescue(fn () => Carbon::parse((string) $r->query('stay'))->startOfDay(), null, false) : null) ?? bdate()->copy();

        return view('cro.lost', ['from' => $from, 'to' => $to, 'kpi' => CRO::kpis($from, $to), 'reasons' => CRO::lostReasons($from, $to),
            'competitors' => CRO::lostToCompetitors($from, $to), 'demand' => CRO::demand($dFrom, 21), 'stay' => $dFrom]);
    }

    public function performance(Request $r)
    {
        [$from, $to] = $this->range($r);

        return view('cro.performance', ['from' => $from, 'to' => $to, 'kpi' => CRO::kpis($from, $to), 'agents' => CRO::breakdown($from, $to, 'agent'),
            'channels' => CRO::breakdown($from, $to, 'channel'), 'referrals' => CRO::referrals($from, $to, $r->user()), 'group' => CRO::isGroupAgent($r->user())]);
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        CRO::ensureDefaults();

        return view('cro.settings', ['cfg' => CRO::cfg()]);
    }

    public function settingsSave(Request $r)
    {
        return $this->attempt(fn () => CRO::saveSettings($r->all()), 'Central reservations settings saved.');
    }
}
