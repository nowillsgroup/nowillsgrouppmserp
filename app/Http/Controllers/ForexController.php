<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\ForexExchange;
use App\Models\ForexRate;
use App\Models\ForexSurrender;
use App\Models\PaymentMode;
use App\Models\ReservationRoom;
use App\Services\ForexService;
use App\Services\FormCService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** v3.4 Forex desk – buy foreign currency from in-house guests (Bank of Ghana rules, house limits), daily rates, position & surrender. */
class ForexController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->date('from') ?? bdate()->copy()->subDays(6);
        $to = $request->date('to') ?? bdate()->copy();
        $q = ForexExchange::with('currency', 'stay.room', 'creator')
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())
            ->when($request->filled('currency'), fn ($w) => $w->where('currency_id', $request->currency))
            ->when($request->filled('payout'), fn ($w) => $w->where('payout', $request->payout))
            ->when($request->filled('q'), fn ($w) => $w->where(fn ($x) => $x->where('exchange_no', 'like', '%'.$request->q.'%')->orWhere('guest_name', 'like', '%'.$request->q.'%')
                ->orWhere('passport_no', 'like', '%'.$request->q.'%')))
            ->orderByDesc('business_date')->orderByDesc('id');
        $all = (clone $q)->whereNull('voided_at')->get();

        return view('forex.index', ['rows' => $q->paginate(30)->withQueryString(), 'from' => $from, 'to' => $to, 'alerts' => ForexService::alerts(),
            'currencies' => Currency::orderBy('sort_order')->get(), 'enabled' => ForexService::enabled(),
            'totals' => ['count' => $all->count(), 'inr' => $all->sum('inr_amount'), 'cash' => $all->where('payout', 'cash')->sum('net_amount'),
                'folio' => $all->where('payout', 'folio')->sum('net_amount'), 'gst' => $all->sum('gst'), 'usd' => $all->sum('usd_equiv')]]);
    }

    public function create(Request $request)
    {
        $stays = ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->get()
            ->map(fn ($s) => ['stay' => $s, 'guest' => FormCService::stayGuest($s)])
            ->filter(fn ($r) => $r['guest'] && (FormCService::isForeign($r['guest']) || ForexService::cfg()['allow_residents'] === '1'))
            ->sortBy(fn ($r) => $r['stay']->room?->room_no)->values();
        $stay = $request->filled('stay') ? ReservationRoom::with('room')->find($request->stay) : null;

        return view('forex.create', ['stays' => $stays, 'stay' => $stay, 'currencies' => Currency::where('is_active', true)->orderBy('sort_order')->get(),
            'rates' => ForexService::todayRates(), 'cfg' => ForexService::cfg(), 'enabled' => ForexService::enabled(), 'payout' => $request->query('payout') === 'folio' ? 'folio' : 'cash']);
    }

    /** Live quote for the exchange form (JSON). */
    public function quote(Request $request)
    {
        $stay = $request->filled('reservation_room_id') ? ReservationRoom::with('reservation.guest', 'guest')->find($request->reservation_room_id) : null;
        $q = ForexService::quote(Currency::find($request->currency_id), (float) $request->fc_amount, $request->payout === 'folio' ? 'folio' : 'cash', $stay, $request->passport_no);
        if (! $stay) {
            $q['errors'][] = 'Choose the in-house guest.';
        }
        $g = $stay ? FormCService::stayGuest($stay) : null;
        $q['guest'] = $g ? ['name' => trim($g->first_name.' '.$g->last_name), 'nationality' => $g->nationality, 'passport' => $g->passport_no ? \App\Models\Guest::mask($g->passport_no) : null] : null;
        $q['fmt'] = ['inr' => money($q['inr']), 'fee' => money($q['fee']), 'net' => money($q['net']),
            'balance' => $q['balance'] !== null ? money($q['balance']) : null];

        return response()->json($q);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'reservation_room_id' => 'required|integer', 'currency_id' => 'required|integer', 'fc_amount' => 'required|numeric|min:0.01|max:9999999',
            'instrument' => 'required|in:notes,tc', 'payout' => 'required|in:cash,folio', 'passport_no' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 ]{5,30}$/'],
            'certificate' => 'nullable|boolean', 'notes' => 'nullable|string|max:255',
        ], ['passport_no.regex' => 'The passport number may only contain letters and digits.']);
        $d['certificate'] = $request->boolean('certificate');
        try {
            $x = ForexService::exchange($d);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['fc_amount' => $e->getMessage()]);
        }

        return redirect()->route('fg.fx.show', $x)->with('success', "{$x->exchange_no}: ".$x->fc().' bought – '
            .($x->payout === 'cash' ? 'pay the guest '.money($x->net_amount).' in cash.' : money($x->net_amount).' credited to the room bill.'));
    }

    public function show(ForexExchange $fx)
    {
        $fx->load('currency', 'stay.room', 'guest', 'folio', 'creator', 'voider');

        return view('forex.show', ['x' => $fx, 'cfg' => ForexService::cfg(),
            'canVoid' => ! $fx->voided_at && $fx->business_date->toDateString() === bdate()->toDateString() && can_do('forex.manage')]);
    }

    public function certificate(ForexExchange $fx)
    {
        abort_if($fx->voided_at, 404);
        $fx->load('currency', 'stay.room', 'creator');
        ForexService::printedCertificate($fx);
        $fx->refresh();

        return view('forex.certificate', ['x' => $fx, 'cfg' => ForexService::cfg(), 'hotel' => hotel()]);
    }

    public function void(Request $request, ForexExchange $fx)
    {
        $d = $request->validate(['reason' => 'required|string|min:5|max:255']);
        try {
            ForexService::void($fx, $d['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['void' => $e->getMessage()]);
        }

        return back()->with('success', "{$fx->exchange_no} voided".($fx->payout === 'cash' ? ' – collect '.money($fx->net_amount).' back from the guest and return the currency.' : ' – the bill credit was reversed.'));
    }

    // ───────── rates ─────────

    public function rates(Request $request)
    {
        $date = bdate()->toDateString();
        $currencies = Currency::orderBy('sort_order')->orderBy('code')->get();
        $prev = ForexRate::where('date', '<', $date)->orderByDesc('date')->get()->unique('currency_id')->keyBy('currency_id');
        $history = ForexRate::with('currency')->where('date', '>=', bdate()->copy()->subDays(13)->toDateString())->orderBy('date')->get()->groupBy('currency_id');

        return view('forex.rates', ['currencies' => $currencies, 'today' => ForexService::todayRates(), 'prev' => $prev, 'history' => $history,
            'cfg' => ForexService::cfg(), 'alerts' => ForexService::alerts(), 'canEdit' => can_do('forex.manage')]);
    }

    public function saveRates(Request $request)
    {
        $request->validate(['rates' => 'required|array']);
        $n = ForexService::saveRates($request->input('rates', []));

        return back()->with('success', $n ? "{$n} rate(s) saved for ".bdate()->format('d M Y').'.' : 'Nothing to save – enter at least one rate.');
    }

    public function copyRates()
    {
        $n = ForexService::copyPrevious();

        return back()->with('success', $n ? "{$n} rate(s) copied from the previous day – review them." : 'Every active currency already has a rate for today.');
    }

    // ───────── position & surrender ─────────

    public function position()
    {
        return view('forex.position', ['position' => ForexService::position(), 'alerts' => ForexService::alerts(), 'cfg' => ForexService::cfg(),
            'surrenders' => ForexSurrender::with('lines.currency', 'paymentMode', 'creator')->orderByDesc('date')->orderByDesc('id')->limit(20)->get(),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['forex', 'city_ledger', 'online', 'gift_voucher'])->orderBy('name')->get()]);
    }

    public function surrender(Request $request)
    {
        $d = $request->validate(['date' => 'required|date', 'counterparty' => 'required|string|min:3|max:120', 'reference' => 'nullable|string|max:60',
            'payment_mode_id' => 'required|integer', 'notes' => 'nullable|string|max:255', 'lines' => 'required|array']);
        try {
            $s = ForexService::surrender($d, $request->input('lines', []));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['surrender' => $e->getMessage()]);
        }

        return redirect()->route('fg.position')->with('success', "{$s->surrender_no} recorded – ".money($s->total_realised).' realised, '
            .((float) $s->gain >= 0 ? 'gain ' : 'loss ').money(abs((float) $s->gain)).'. Print the voucher from the list below.');
    }

    public function showSurrender(ForexSurrender $surrender)
    {
        $surrender->load('lines.currency', 'paymentMode', 'creator');

        return view('forex.surrender', ['s' => $surrender, 'hotel' => hotel()]);
    }
}
