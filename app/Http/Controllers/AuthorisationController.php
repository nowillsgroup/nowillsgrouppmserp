<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\ReservationRoom;
use App\Models\RevenueAuthorisation;
use App\Services\IncomeAuditService as IA;
use App\Services\RevenueAuthService as RA;
use Illuminate\Http\Request;
use RuntimeException;

/** v5.3 Revenue authorisations: comps, house use, rates below expected and discounts – requested, then approved by someone else. */
class AuthorisationController extends Controller
{
    protected function attempt(callable $fn, string|callable $ok)
    {
        try {
            $r = $fn();

            return back()->with('success', is_callable($ok) ? $ok($r) : $ok);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $show = $request->input('status', 'pending');
        $rows = RevenueAuthorisation::with('stay.room', 'stay.guest', 'stay.reservation', 'folio', 'requester', 'decider', 'transaction')
            ->when($show !== 'all', fn ($q) => $q->where('status', $show))
            ->when($request->input('kind'), fn ($q) => $q->where('kind', $request->kind))
            ->orderByDesc('id')->paginate(30)->withQueryString();
        $today = bdate()->toDateString();
        $stays = ReservationRoom::with('room', 'guest', 'reservation')
            ->where(fn ($q) => $q->where('status', 'checked_in')
                ->orWhere(fn ($q) => $q->where('status', 'reserved')->where('arrival_date', '<=', bdate()->copy()->addDays(14)->toDateString()))
                ->orWhere(fn ($q) => $q->where('status', 'checked_out')->where('departure_date', '>=', bdate()->copy()->subDays(7)->toDateString())))
            ->orderBy('status')->orderBy('arrival_date')->get();
        $folios = Folio::with('guest', 'stay.room')->where('status', 'open')->orderByDesc('id')->limit(200)->get();
        $counts = RevenueAuthorisation::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return view('income-audit.authorisations', ['rows' => $rows, 'show' => $show, 'stays' => $stays, 'folios' => $folios, 'counts' => $counts,
            'limit' => (float) IA::cfg('ia_authorise_limit'), 'compNights' => (int) IA::cfg('ia_comp_nights_limit'), 'today' => $today]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['kind' => 'required|in:comp,house_use,rate,discount', 'reservation_room_id' => 'nullable|integer|owned:reservation_rooms',
            'folio_id' => 'nullable|integer|owned:folios', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date', 'amount' => 'nullable|numeric|min:0|max:10000000',
            'reason' => 'required|string|max:255']);

        return $this->attempt(fn () => RA::request($d, $request->user()), fn ($a) => "{$a->auth_no} sent for approval".(RA::needsSenior($a) ? ' – above the manager limit, the GM / controller must approve.' : '.'));
    }

    public function approve(Request $request, RevenueAuthorisation $revauth)
    {
        $d = $request->validate(['note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => RA::approve($revauth, $request->user(), $d['note'] ?? null), fn ($a) => "{$a->auth_no} approved".match ($a->kind) {
            'discount' => $a->folio_transaction_id ? ' – '.money($a->amount).' discount posted to '.$a->folio?->folio_no.'.' : '.',
            default => $a->applied_at ? ' – stay rate is now '.money($a->kind === 'rate' ? $a->amount : 0).' (was '.money($a->previous_rate).'; put back after '.dmy($a->date_to).').' : ' – the rate changes when the authorised nights begin.',
        });
    }

    public function decline(Request $request, RevenueAuthorisation $revauth)
    {
        $d = $request->validate(['note' => 'required|string|max:255']);

        return $this->attempt(fn () => RA::decline($revauth, $request->user(), $d['note']), "{$revauth->auth_no} declined.");
    }

    public function cancel(Request $request, RevenueAuthorisation $revauth)
    {
        return $this->attempt(fn () => RA::cancel($revauth, $request->user()), "{$revauth->auth_no} withdrawn.");
    }
}
