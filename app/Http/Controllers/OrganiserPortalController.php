<?php

namespace App\Http\Controllers;

use App\Models\BanquetEvent;
use App\Models\BanquetFunction;
use App\Models\Hotel;
use App\Models\OrganiserAttendee;
use App\Models\OrganiserAudit;
use App\Models\OrganiserRequest;
use App\Models\OrganiserUser;
use App\Services\OrganiserService as OS;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v7.5 Event organiser portal – the organiser side (/organiser/…). Sign-in, invitation and password reset are public;
 * everything else runs behind the 'organiser' middleware. Records are always looked up inside the organiser's own events.
 */
class OrganiserPortalController extends Controller
{
    protected function me(Request $r): OrganiserUser
    {
        return $r->attributes->get('organiser');
    }

    protected function headers($response)
    {
        return $response->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    // ------------------------------------------------------------------ public: sign-in, invitation, reset

    public function loginForm(Request $r)
    {
        if ($r->session()->has('organiser')) {
            return redirect()->route('organiser.home');
        }

        return $this->headers(response()->view('organiser.login', ['code' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r->query('h', ''))]));
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
            $u = OS::attempt($d['email'], $d['password']);
        } catch (RuntimeException $e) {
            return back()->withInput($r->only('hotel_code', 'email'))->withErrors(['email' => $e->getMessage()]);
        }
        $r->session()->regenerate();
        $r->session()->put('organiser', ['id' => $u->id, 'hotel_id' => $hotel->id, 'at' => time(), 'pw' => (int) $u->password_changed_at?->timestamp]);

        return redirect()->route('organiser.home');
    }

    public function logout(Request $r)
    {
        if ($u = $r->attributes->get('organiser')) {
            OS::audit($u, 'logout', 'Signed out');
        }
        $code = Tenant::check() ? hotel()->code : null;
        $r->session()->forget('organiser');
        $r->session()->regenerateToken();

        return redirect()->route('organiser.login', $code ? ['h' => $code] : [])->with('success', 'You have signed out.');
    }

    /** Token links carry no hotel: find the login across hotels, then work inside its hotel. */
    protected function tokenUser(string $column, string $token): ?OrganiserUser
    {
        Tenant::set(null);
        $u = OS::byToken($column, $token);
        if ($u) {
            Tenant::set(Hotel::find($u->hotel_id));
        }

        return $u;
    }

    public function inviteForm(string $token)
    {
        $u = $this->tokenUser('invite_token', $token);

        return $this->headers(response()->view('organiser.password', ['u' => $u, 'mode' => 'invite', 'action' => route('organiser.invite.accept', $token)]));
    }

    public function inviteAccept(Request $r, string $token)
    {
        $u = $this->tokenUser('invite_token', $token);
        if (! $u) {
            return redirect()->route('organiser.invite', $token);
        }
        try {
            OS::setPassword($u, (string) $r->input('password'), (string) $r->input('password_confirmation'), 'invite');
        } catch (RuntimeException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        return redirect()->route('organiser.login', ['h' => hotel()->code])->with('success', 'Password set – sign in with your e-mail and new password.');
    }

    public function forgotForm(Request $r)
    {
        return $this->headers(response()->view('organiser.forgot', ['code' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r->query('h', ''))]));
    }

    public function forgot(Request $r)
    {
        $d = $r->validate(['hotel_code' => 'required|string|max:30', 'email' => 'required|string|max:190']);
        if ($hotel = Hotel::where('code', $d['hotel_code'])->where('is_active', true)->first()) {
            Tenant::set($hotel);
            OS::requestReset($d['email']);
        }

        return redirect()->route('organiser.login', ['h' => $d['hotel_code']])->with('success', 'If that e-mail has an organiser login, a reset link is on its way (valid for 60 minutes).');
    }

    public function resetForm(string $token)
    {
        $u = $this->tokenUser('reset_token', $token);

        return $this->headers(response()->view('organiser.password', ['u' => $u, 'mode' => 'reset', 'action' => route('organiser.reset.save', $token)]));
    }

    public function reset(Request $r, string $token)
    {
        $u = $this->tokenUser('reset_token', $token);
        if (! $u) {
            return redirect()->route('organiser.reset', $token);
        }
        try {
            OS::setPassword($u, (string) $r->input('password'), (string) $r->input('password_confirmation'), 'reset');
        } catch (RuntimeException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }

        return redirect()->route('organiser.login', ['h' => hotel()->code])->with('success', 'Password changed – sign in with your new password.');
    }

    // ------------------------------------------------------------------ portal

    public function home(Request $r)
    {
        return view('organiser.home', OS::organiserDashboard($this->me($r)) + ['welcome' => setting('organiser_welcome', OS::DEFAULTS['organiser_welcome'])]);
    }

    public function events(Request $r)
    {
        $rows = OS::eventsFor($this->me($r));
        $tab = in_array($r->query('tab'), ['upcoming', 'past', 'cancelled', 'all']) ? $r->query('tab') : 'upcoming';
        $today = bdate()->toDateString();
        $rows = match ($tab) {
            'upcoming' => $rows->filter(fn ($e) => $e->isOpen() && ($e->end_date?->toDateString() ?? $e->event_date?->toDateString()) >= $today),
            'past' => $rows->filter(fn ($e) => ! $e->isOpen() || ($e->end_date?->toDateString() ?? $e->event_date?->toDateString()) < $today)->reject(fn ($e) => in_array($e->status, ['cancelled', 'lost'])),
            'cancelled' => $rows->filter(fn ($e) => in_array($e->status, ['cancelled', 'lost'])),
            default => $rows,
        };

        return view('organiser.events', ['rows' => $rows, 'tab' => $tab]);
    }

    protected function ownEvent(OrganiserUser $u, int $id): BanquetEvent
    {
        $e = BanquetEvent::with('functions.hall', 'functions.package', 'functions.items', 'company', 'salesUser', 'folio')->findOrFail($id);
        try {
            OS::ownEvent($u, $e);
        } catch (RuntimeException) {
            abort(404);
        }

        return $e;
    }

    public function event(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);

        return view('organiser.event', ['e' => $e, 'money' => OS::money($e), 'changed' => OS::beoChanged($e),
            'requests' => OrganiserRequest::with('organiserUser', 'decider')->where('banquet_event_id', $e->id)->orderByDesc('id')->get(),
            'summary' => OS::attendeeSummary($e), 'openChange' => OrganiserRequest::where('banquet_event_id', $e->id)->where('type', 'change')->where('status', 'pending')->exists(),
            'openCancel' => OrganiserRequest::where('banquet_event_id', $e->id)->where('type', 'cancel')->where('status', 'pending')->exists(),
            'canPay' => setting('organiser_pay', '1') === '1' && \App\Services\OnlinePaymentService::enabled()]);
    }

    public function beo(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        abort_unless($e->beo_snapshot, 404);

        return view('organiser.beo', ['e' => $e, 'snap' => $e->beo_snapshot, 'changed' => OS::beoChanged($e)]);
    }

    public function beoSign(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        try {
            OS::approveBeo($u, $e, (string) $r->input('sign_name'));
        } catch (RuntimeException $e2) {
            return back()->withErrors(['beo' => $e2->getMessage()]);
        }

        return redirect()->route('organiser.event', $e->id)->with('success', "Banquet event order for {$e->event_no} signed – thank you.");
    }

    public function guarantee(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        $f = BanquetFunction::where('banquet_event_id', $e->id)->findOrFail((int) $r->input('function_id'));
        try {
            OS::guarantee($u, $f, (int) $r->input('pax'));
        } catch (RuntimeException $e2) {
            return back()->withInput()->withErrors(['guarantee' => $e2->getMessage()]);
        }

        return redirect()->route('organiser.event', $e->id)->with('success', "Guarantee for {$f->name} set to ".(int) $r->input('pax').' guests.');
    }

    public function change(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        try {
            $q = OS::change($u, $e, ['kind' => 'change'] + $r->only('date', 'pax', 'package', 'details'));
        } catch (RuntimeException $e2) {
            return back()->withInput()->withErrors(['change' => $e2->getMessage()]);
        }

        return redirect()->route('organiser.event', $e->id)->with('success', "Change request {$q->number} sent – your coordinator will confirm by e-mail.");
    }

    public function cancelReq(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        try {
            $q = OS::change($u, $e, ['kind' => 'cancel', 'details' => (string) $r->input('details')]);
        } catch (RuntimeException $e2) {
            return back()->withErrors(['cancel' => $e2->getMessage()]);
        }

        return redirect()->route('organiser.event', $e->id)->with('success', "Cancellation request {$q->number} sent – the hotel will confirm and release the venue.");
    }

    public function pay(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        try {
            $p = OS::pay($u, $e, (float) $r->input('amount'));
        } catch (RuntimeException $e2) {
            return back()->withErrors(['pay' => $e2->getMessage()]);
        }

        return redirect()->route('pay.show', $p->token);
    }

    public function attendees(Request $r, int $id)
    {
        $u = $this->me($r);
        abort_unless($u->can('events'), 403);
        $e = $this->ownEvent($u, $id);

        return view('organiser.attendees', ['e' => $e, 'rows' => OS::attendees($e), 'summary' => OS::attendeeSummary($e)]);
    }

    public function attendeeStore(Request $r, int $id)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        try {
            $a = OS::attendeeAdd($u, $e, $r->only('name', 'email', 'phone', 'dietary', 'tag', 'notes'));
        } catch (RuntimeException $e2) {
            return back()->withInput()->withErrors(['guest' => $e2->getMessage()]);
        }

        return back()->with('success', "{$a->name} added to the guest list.");
    }

    public function attendeeDelete(Request $r, int $id, int $aid)
    {
        $u = $this->me($r);
        $e = $this->ownEvent($u, $id);
        $a = OrganiserAttendee::where('banquet_event_id', $e->id)->findOrFail($aid);
        try {
            OS::attendeeDelete($u, $a);
        } catch (RuntimeException $e2) {
            return back()->withErrors(['guest' => $e2->getMessage()]);
        }

        return back()->with('success', "{$a->name} removed from the guest list.");
    }

    public function requests(Request $r)
    {
        $u = $this->me($r);

        return view('organiser.requests', ['rows' => OS::requestsFor($u)]);
    }

    public function quote(Request $r)
    {
        $u = $this->me($r);
        try {
            $q = OS::quoteRequest($u, $r->only('title', 'event_type', 'event_date', 'pax', 'details'));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('organiser.requests')->with('success', "Quote request {$q->number} sent – our events team will reply with a proposal.");
    }

    public function withdraw(Request $r, int $id)
    {
        $u = $this->me($r);
        $q = OrganiserRequest::findOrFail($id);
        try {
            OS::ownRequest($u, $q);
        } catch (RuntimeException) {
            abort(404);
        }
        try {
            OS::withdraw($u, $q);
        } catch (RuntimeException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('success', "Request {$q->number} withdrawn.");
    }

    public function users(Request $r)
    {
        $u = $this->me($r);
        abort_unless($u->can('users'), 403);

        return view('organiser.users', ['rows' => OS::managedUsers($u)]);
    }

    public function invite(Request $r)
    {
        $u = $this->me($r);
        try {
            [$new] = OS::invite(null, $r->only('name', 'email', 'phone', 'role'), null, $u);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', "Invitation e-mailed to {$new->email}.");
    }

    public function userAction(Request $r, int $id)
    {
        $u = $this->me($r);
        abort_unless($u->can('users'), 403);
        $t = OrganiserUser::findOrFail($id);
        abort_unless(OS::manages($u, $t), 404);
        try {
            match ($r->input('do')) {
                'disable' => OS::setActive($t, false, $u),
                'enable' => OS::setActive($t, true, $u),
                'resend' => $t->isPending() && $t->is_active ? OS::issueInvite($t) : throw new RuntimeException('Only an invited user who has not signed in yet can get a new invitation.'),
                'role' => OS::setRole($t, (string) $r->input('role'), $u),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', "{$t->name} updated.");
    }

    public function profile(Request $r)
    {
        return view('organiser.profile', ['activity' => OrganiserAudit::where('organiser_user_id', $this->me($r)->id)->orderByDesc('id')->limit(15)->get()]);
    }

    public function password(Request $r)
    {
        $u = $this->me($r);
        try {
            OS::changePassword($u, (string) $r->input('current_password'), (string) $r->input('password'), (string) $r->input('password_confirmation'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['password' => $e->getMessage()]);
        }
        $r->session()->put('organiser.pw', (int) $u->fresh()->password_changed_at?->timestamp);

        return back()->with('success', 'Password changed.');
    }
}
