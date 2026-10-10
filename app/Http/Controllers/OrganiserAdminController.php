<?php

namespace App\Http\Controllers;

use App\Models\BanquetEvent;
use App\Models\Company;
use App\Models\OrganiserAudit;
use App\Models\OrganiserRequest;
use App\Models\OrganiserUser;
use App\Services\OrganiserService as OS;
use Illuminate\Http\Request;
use RuntimeException;

/** v7.5 Event organiser portal – the hotel side: dashboard, portal events + BEO publishing, logins, request queue, activity, settings. */
class OrganiserAdminController extends Controller
{
    public function dashboard()
    {
        return view('organiser-admin.dashboard', OS::staffDashboard());
    }

    public function events(Request $r)
    {
        $portal = $r->query('portal', 'on');
        $rows = BanquetEvent::with('company', 'hall', 'salesUser')->withCount('attendees')
            ->when($portal === 'on', fn ($q) => $q->where('portal_enabled', true))
            ->when($portal === 'off', fn ($q) => $q->where('portal_enabled', false)->whereIn('status', ['enquiry', 'tentative', 'definite']))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('event_no', 'like', '%'.$r->query('q').'%')->orWhere('title', 'like', '%'.$r->query('q').'%')));

        return view('organiser-admin.events', ['rows' => $rows->orderBy('event_date')->orderBy('id')->paginate(25)->withQueryString(), 'portal' => $portal]);
    }

    public function event(BanquetEvent $bevent)
    {
        $bevent->load('functions.hall', 'functions.package', 'functions.items', 'company', 'salesUser', 'hall', 'folio', 'beoPublisher');

        return view('organiser-admin.event', ['e' => $bevent, 'money' => OS::money($bevent), 'live' => OS::beo($bevent),
            'changed' => OS::beoChanged($bevent), 'logins' => OS::loginsFor($bevent),
            'requests' => OrganiserRequest::with('organiserUser', 'decider')->where('banquet_event_id', $bevent->id)->orderByDesc('id')->limit(10)->get(),
            'audit' => OrganiserAudit::with('organiserUser')->where('banquet_event_id', $bevent->id)->orderByDesc('id')->limit(15)->get(),
            'summary' => OS::attendeeSummary($bevent), 'loginUrl' => route('organiser.login', ['h' => hotel()->code])]);
    }

    public function portal(Request $r, BanquetEvent $bevent)
    {
        try {
            OS::saveEventPortal($bevent, $r->only('portal_enabled'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['portal' => $e->getMessage()]);
        }

        return back()->with('success', "Organiser portal ".($bevent->fresh()->portal_enabled ? 'switched on' : 'switched off')." for {$bevent->event_no}.");
    }

    public function publish(Request $r, BanquetEvent $bevent)
    {
        try {
            OS::publishBeo($bevent, $r->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['beo' => $e->getMessage()]);
        }

        return back()->with('success', "BEO published for {$bevent->event_no} – the organiser has been asked to sign it.");
    }

    public function organisers(Request $r)
    {
        $rows = OrganiserUser::with('company', 'creator')->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$r->query('q').'%')->orWhere('email', 'like', '%'.$r->query('q').'%')));
        $events = BanquetEvent::whereIn('status', ['enquiry', 'tentative', 'definite'])->orderBy('event_date')->get();
        $companies = Company::where('is_active', true)->orderBy('name')->get();

        return view('organiser-admin.organisers', ['rows' => $rows->orderByDesc('is_active')->orderBy('name')->paginate(25)->withQueryString(), 'events' => $events, 'companies' => $companies]);
    }

    public function invite(Request $r)
    {
        try {
            $c = $r->filled('company_id') ? Company::findOrFail((int) $r->input('company_id')) : null;
            $e = $r->filled('banquet_event_id') ? BanquetEvent::findOrFail((int) $r->input('banquet_event_id')) : null;
            [$u] = OS::invite($c, $r->only('name', 'email', 'phone', 'role'), $r->user(), null, $e);
        } catch (RuntimeException $e2) {
            return back()->withInput()->withErrors(['invite' => $e2->getMessage()]);
        }

        return back()->with('success', "Invitation e-mailed to {$u->email} (valid ".setting('organiser_invite_days', 7).' days).');
    }

    public function user(Request $r, OrganiserUser $orguser)
    {
        try {
            match ($r->input('do')) {
                'disable' => OS::setActive($orguser, false),
                'enable' => OS::setActive($orguser, true),
                'unlock' => OS::unlock($orguser),
                'resend' => $orguser->isPending() && $orguser->is_active ? OS::issueInvite($orguser) : throw new RuntimeException('Only an invited user who has not accepted yet can get a new invitation.'),
                'reset' => $orguser->isPending() ? throw new RuntimeException('This user has not accepted the invitation yet – resend it instead.') : OS::requestReset($orguser->email),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['invite' => $e->getMessage()]);
        }

        return back()->with('success', "{$orguser->name}: done.");
    }

    public function requests(Request $r)
    {
        $status = in_array($r->query('status'), ['pending', 'approved', 'declined', 'done', 'withdrawn', 'all']) ? $r->query('status') : 'pending';
        $q = OrganiserRequest::with('company', 'organiserUser', 'event.company', 'decider')
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when(isset(OrganiserRequest::TYPES[$r->query('type')]), fn ($w) => $w->where('type', $r->query('type')));

        return view('organiser-admin.requests', ['rows' => $q->orderBy($status === 'pending' ? 'created_at' : 'id', $status === 'pending' ? 'asc' : 'desc')->paginate(25)->withQueryString(),
            'status' => $status, 'counts' => OrganiserRequest::where('status', 'pending')->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type')]);
    }

    public function decide(Request $r, OrganiserRequest $orgreq)
    {
        try {
            match ($r->input('do')) {
                'approve' => OS::approve($orgreq, $r->user(), $r->input('note')),
                'decline' => OS::decline($orgreq, $r->user(), (string) $r->input('note')),
                'done' => OS::complete($orgreq, $r->user(), (string) $r->input('note')),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('success', "{$orgreq->number}: ".['approve' => 'cancellation approved – the event is released and the organiser notified.', 'decline' => 'declined – the organiser has been told why.', 'done' => 'marked done – the organiser has your note.'][$r->input('do')]);
    }

    public function activity(Request $r)
    {
        $q = OrganiserAudit::with('company', 'organiserUser', 'event')
            ->when($r->filled('event'), fn ($w) => $w->where('banquet_event_id', (int) $r->query('event')))
            ->when($r->filled('action'), fn ($w) => $w->where('action', $r->query('action')))
            ->when($r->filled('from'), fn ($w) => $w->where('created_at', '>=', $r->query('from').' 00:00:00'))
            ->when($r->filled('to'), fn ($w) => $w->where('created_at', '<=', $r->query('to').' 23:59:59'));

        return view('organiser-admin.activity', ['rows' => $q->orderByDesc('id')->paginate(40)->withQueryString(),
            'events' => BanquetEvent::where('portal_enabled', true)->orderByDesc('event_date')->get(),
            'actions' => OrganiserAudit::distinct()->orderBy('action')->pluck('action')]);
    }

    public function settings()
    {
        return view('organiser-admin.settings', ['s' => OS::settings(), 'loginUrl' => route('organiser.login', ['h' => hotel()->code])]);
    }

    public function saveSettings(Request $r)
    {
        try {
            OS::saveSettings($r->all());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['settings' => $e->getMessage()]);
        }

        return back()->with('success', 'Organiser portal settings saved.');
    }
}
