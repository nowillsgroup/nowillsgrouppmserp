<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\PartnerRequest;
use App\Models\PartnerUser;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\PartnerService as PS;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v7.4 Corporate & travel-agent portal – the partner side (/partner/…). Sign-in, invitation and password reset are public;
 * everything else runs behind the 'partner' middleware. Records are always looked up inside the partner's own company.
 */
class PartnerPortalController extends Controller
{
    protected function me(Request $r): PartnerUser
    {
        return $r->attributes->get('partner');
    }

    protected function headers($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    // ------------------------------------------------------------------ public: sign-in, invitation, reset

    public function loginForm(Request $r)
    {
        if ($r->session()->has('partner')) {
            return redirect()->route('partner.home');
        }

        return $this->headers(response()->view('partner.login', ['code' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r->query('h', ''))]));
    }

    public function login(Request $r)
    {
        $d = $r->validate(['hotel_code' => 'required|string|max:30', 'email' => 'required|string|max:190', 'password' => 'required|string|max:200']);
        $hotel = Hotel::where('code', $d['hotel_code'])->where('is_active', true)->first();
        if (! $hotel) {
            return back()->withInput($r->only('hotel_code', 'email'))->withErrors(['email' => 'Invalid hotel ID, e-mail or password.']);
        }
        Tenant::set($hotel);
        try {
            $u = PS::attempt($d['email'], $d['password']);
        } catch (RuntimeException $e) {
            return back()->withInput($r->only('hotel_code', 'email'))->withErrors(['email' => $e->getMessage()]);
        }
        $r->session()->regenerate();
        $r->session()->put('partner', ['id' => $u->id, 'hotel_id' => $hotel->id, 'at' => time(), 'pw' => (int) $u->password_changed_at?->timestamp]);

        return redirect()->route('partner.home');
    }

    public function logout(Request $r)
    {
        if ($u = $r->attributes->get('partner')) {
            PS::audit($u, 'logout', 'Signed out');
        }
        $code = Tenant::check() ? hotel()->code : null;
        $r->session()->forget('partner');
        $r->session()->regenerateToken();

        return redirect()->route('partner.login', $code ? ['h' => $code] : [])->with('success', 'You have signed out.');
    }

    /** Token links carry no hotel: find the login across hotels, then work inside its hotel. */
    protected function tokenUser(string $column, string $token): ?PartnerUser
    {
        Tenant::set(null);
        $u = PS::byToken($column, $token);
        if ($u) {
            Tenant::set(Hotel::find($u->hotel_id));
        }

        return $u;
    }

    public function inviteForm(string $token)
    {
        $u = $this->tokenUser('invite_token', $token);

        return $this->headers(response()->view('partner.password', ['u' => $u, 'mode' => 'invite', 'action' => route('partner.invite.accept', $token)]));
    }

    public function inviteAccept(Request $r, string $token)
    {
        $u = $this->tokenUser('invite_token', $token);
        if (! $u) {
            return redirect()->route('partner.invite', $token);
        }
        try {
            PS::setPassword($u, (string) $r->input('password'), (string) $r->input('password_confirmation'), 'invite');
        } catch (RuntimeException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        return redirect()->route('partner.login', ['h' => hotel()->code])->with('success', 'Password set – sign in with your e-mail and new password.');
    }

    public function forgotForm(Request $r)
    {
        return $this->headers(response()->view('partner.forgot', ['code' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r->query('h', ''))]));
    }

    public function forgot(Request $r)
    {
        $d = $r->validate(['hotel_code' => 'required|string|max:30', 'email' => 'required|string|max:190']);
        if ($hotel = Hotel::where('code', $d['hotel_code'])->where('is_active', true)->first()) {
            Tenant::set($hotel);
            PS::requestReset($d['email']);
        }

        return redirect()->route('partner.login', ['h' => $d['hotel_code']])->with('success', 'If that e-mail has a partner login, a reset link is on its way (valid for 60 minutes).');
    }

    public function resetForm(string $token)
    {
        $u = $this->tokenUser('reset_token', $token);

        return $this->headers(response()->view('partner.password', ['u' => $u, 'mode' => 'reset', 'action' => route('partner.reset.save', $token)]));
    }

    public function reset(Request $r, string $token)
    {
        $u = $this->tokenUser('reset_token', $token);
        if (! $u) {
            return redirect()->route('partner.reset', $token);
        }
        try {
            PS::setPassword($u, (string) $r->input('password'), (string) $r->input('password_confirmation'), 'reset');
        } catch (RuntimeException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        return redirect()->route('partner.login', ['h' => hotel()->code])->with('success', 'Password changed – sign in with your new password.');
    }

    // ------------------------------------------------------------------ portal

    public function home(Request $r)
    {
        return view('partner.home', PS::partnerDashboard($this->me($r)) + ['welcome' => setting('partner_welcome', PS::DEFAULTS['partner_welcome'])]);
    }

    public function book(Request $r)
    {
        $u = $this->me($r);
        abort_unless($u->can('book'), 403);
        $result = null;
        $error = null;
        $q = ['arrival' => $r->query('arrival', bdate()->copy()->addDays(7)->toDateString()), 'departure' => $r->query('departure', bdate()->copy()->addDays(9)->toDateString()),
            'rooms' => max(1, (int) $r->query('rooms', 1)), 'adults' => max(1, (int) $r->query('adults', 1))];
        if ($r->filled('arrival')) {
            try {
                $result = PS::search($u->company, $q['arrival'], $q['departure'], $q['adults'], $q['rooms']);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        $pick = $result && $r->filled('type') ? collect($result['rows'])->first(fn ($row) => $row['type']->id === (int) $r->query('type') && ! $row['why']) : null;

        return view('partner.book', compact('q', 'result', 'error', 'pick'));
    }

    public function store(Request $r)
    {
        $u = $this->me($r);
        try {
            [$res, $req] = PS::book($u, $r->only('room_type_id', 'arrival', 'departure', 'rooms', 'adults', 'children', 'first_name', 'last_name', 'email', 'phone', 'bill_to', 'partner_ref', 'special_request'));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['book' => $e->getMessage()]);
        }

        return redirect()->route('partner.booking', $res->id)->with('success', $req
            ? "Booking {$res->reservation_no} is held for the hotel's approval ({$req->reason}). We will e-mail you as soon as it is confirmed."
            : "Booking {$res->reservation_no} is confirmed.");
    }

    public function bookings(Request $r)
    {
        $u = $this->me($r);
        $tab = in_array($r->query('tab'), ['upcoming', 'inhouse', 'past', 'cancelled', 'all']) ? $r->query('tab') : 'upcoming';
        $today = bdate()->toDateString();
        $q = Reservation::with('guest', 'rooms.roomType', 'rooms.room', 'partnerUser')->where('company_id', $u->company_id);
        match ($tab) {
            'upcoming' => $q->whereIn('status', ['confirmed', 'tentative'])->where('departure_date', '>=', $today)->orderBy('arrival_date'),
            'inhouse' => $q->where('status', 'checked_in')->orderBy('departure_date'),
            'past' => $q->whereIn('status', ['checked_out', 'no_show'])->orderByDesc('departure_date'),
            'cancelled' => $q->where('status', 'cancelled')->orderByDesc('cancelled_at'),
            default => $q->orderByDesc('arrival_date'),
        };
        if ($s = trim((string) $r->query('q'))) {
            $q->where(fn ($w) => $w->where('reservation_no', 'like', "%{$s}%")->orWhere('partner_ref', 'like', "%{$s}%")
                ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")));
        }
        if ($r->query('mine')) {
            $q->where('partner_user_id', $u->id);
        }

        return view('partner.bookings', ['rows' => $q->paginate(20)->withQueryString(), 'tab' => $tab]);
    }

    protected function ownReservation(PartnerUser $u, int $id): Reservation
    {
        return Reservation::with('guest', 'rooms.roomType', 'rooms.room', 'partnerUser', 'rateContract')->where('company_id', $u->company_id)->findOrFail($id);
    }

    public function booking(Request $r, int $id)
    {
        $u = $this->me($r);
        $res = $this->ownReservation($u, $id);

        return view('partner.booking', ['r' => $res, 'cancellable' => PS::cancellable($res), 'cancelQuote' => PS::cancellable($res) ? PS::cancelQuote($res) : null,
            'requests' => PartnerRequest::with('partnerUser', 'decider')->where('reservation_id', $res->id)->orderByDesc('id')->get(),
            'value' => round($res->rooms->where('status', '!=', 'cancelled')->sum(fn ($s) => PS::stayValue($s)), 2)]);
    }

    public function cancel(Request $r, int $id)
    {
        $u = $this->me($r);
        $res = $this->ownReservation($u, $id);
        try {
            $fee = PS::cancel($u, $res, (string) $r->input('reason'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('partner.booking', $res->id)->with('success', "Booking {$res->reservation_no} cancelled".($fee > 0 ? ' – a late-cancellation fee of '.money($fee).' applies.' : ' free of charge.'));
    }

    public function amend(Request $r, int $id)
    {
        $u = $this->me($r);
        $res = $this->ownReservation($u, $id);
        try {
            $q = PS::amend($u, $res, $r->only('arrival', 'departure', 'rooms', 'details'));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['amend' => $e->getMessage()]);
        }

        return redirect()->route('partner.booking', $res->id)->with('success', "Change request {$q->number} sent – the reservations team will confirm by e-mail.");
    }

    public function requests(Request $r)
    {
        $u = $this->me($r);

        return view('partner.requests', ['rows' => PartnerRequest::with('reservation', 'partnerUser')->where('company_id', $u->company_id)->orderByRaw("status = 'pending' desc")->orderByDesc('id')->paginate(20)]);
    }

    public function group(Request $r)
    {
        $u = $this->me($r);
        try {
            $q = PS::groupRequest($u, $r->only('arrival', 'departure', 'rooms', 'adults', 'name', 'details'));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['group' => $e->getMessage()]);
        }

        return redirect()->route('partner.requests')->with('success', "Group request {$q->number} sent – our sales team will reply with a quote.");
    }

    public function withdraw(Request $r, int $id)
    {
        $u = $this->me($r);
        $q = PartnerRequest::where('company_id', $u->company_id)->findOrFail($id);
        try {
            PS::withdraw($u, $q);
        } catch (RuntimeException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('success', "Request {$q->number} withdrawn.");
    }

    public function statement(Request $r)
    {
        $u = $this->me($r);
        abort_unless($u->can('finance'), 403);

        return view('partner.statement', PS::statement($u->company) + ['canPay' => setting('partner_pay', '1') === '1' && \App\Services\OnlinePaymentService::enabled()]);
    }

    public function invoice(Request $r, int $folio)
    {
        $u = $this->me($r);
        abort_unless($u->can('finance') || $u->can('book'), 403);
        $f = PS::invoice($u->company, $folio);
        abort_unless((bool) $f, 404);

        return view('partner.invoice', ['f' => $f]);
    }

    public function pay(Request $r)
    {
        $u = $this->me($r);
        try {
            $p = PS::pay($u, (float) $r->input('amount'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['pay' => $e->getMessage()]);
        }

        return redirect()->route('pay.show', $p->token);
    }

    public function commissions(Request $r)
    {
        $u = $this->me($r);
        abort_unless($u->company->type === 'travel_agent' && $u->can('finance'), 403);
        $rows = PS::commissions($u->company);

        return view('partner.commissions', ['rows' => $rows, 'due' => round($rows->where('status', 'due')->sum('amount'), 2), 'paid' => round($rows->where('status', 'paid')->sum('amount'), 2)]);
    }

    public function users(Request $r)
    {
        $u = $this->me($r);
        abort_unless($u->can('users'), 403);

        return view('partner.users', ['rows' => PartnerUser::where('company_id', $u->company_id)->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function invite(Request $r)
    {
        $u = $this->me($r);
        try {
            [$new] = PS::invite($u->company, $r->only('name', 'email', 'phone', 'role'), null, $u);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', "Invitation e-mailed to {$new->email}.");
    }

    public function userAction(Request $r, int $id)
    {
        $u = $this->me($r);
        $t = PartnerUser::where('company_id', $u->company_id)->findOrFail($id);
        try {
            abort_unless($u->can('users'), 403);
            match ($r->input('do')) {
                'disable' => PS::setActive($t, false, $u),
                'enable' => PS::setActive($t, true, $u),
                'resend' => $t->isPending() && $t->is_active ? PS::issueInvite($t) : throw new RuntimeException('Only an invited user who has not signed in yet can get a new invitation.'),
                'role' => PS::setRole($t, (string) $r->input('role'), $u),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', "{$t->name} updated.");
    }

    public function profile(Request $r)
    {
        return view('partner.profile', ['activity' => \App\Models\PartnerAudit::where('partner_user_id', $this->me($r)->id)->orderByDesc('id')->limit(15)->get()]);
    }

    public function password(Request $r)
    {
        $u = $this->me($r);
        try {
            PS::changePassword($u, (string) $r->input('current_password'), (string) $r->input('password'), (string) $r->input('password_confirmation'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }
        $r->session()->put('partner.pw', (int) $u->fresh()->password_changed_at?->timestamp);

        return back()->with('success', 'Password changed.');
    }
}
