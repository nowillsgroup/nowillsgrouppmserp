<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PartnerAudit;
use App\Models\PartnerRequest;
use App\Models\PartnerUser;
use App\Services\CreditService;
use App\Services\PartnerService as PS;
use Illuminate\Http\Request;
use RuntimeException;

/** v7.4 Corporate & travel-agent portal – the hotel side: dashboard, partner accounts, request queue, activity log, settings. */
class PartnerAdminController extends Controller
{
    public function dashboard()
    {
        return view('partner-admin.dashboard', PS::staffDashboard());
    }

    public function accounts(Request $r)
    {
        $rows = Company::whereIn('type', ['corporate', 'travel_agent'])->withCount(['partnerUsers as users_count' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('portal_enabled')->orderBy('name')->get();
        $last = PartnerAudit::where('action', 'login')->selectRaw('company_id, max(created_at) as at')->groupBy('company_id')->pluck('at', 'company_id');

        return view('partner-admin.accounts', compact('rows', 'last'));
    }

    public function company(Company $company)
    {
        abort_unless(in_array($company->type, ['corporate', 'travel_agent']), 404);

        return view('partner-admin.company', [
            'c' => $company, 'users' => PartnerUser::where('company_id', $company->id)->orderByDesc('is_active')->orderBy('name')->get(),
            'position' => CreditService::position($company), 'contract' => \App\Services\ContractService::forCompany($company->id, bdate()),
            'requests' => PartnerRequest::with('partnerUser', 'reservation')->where('company_id', $company->id)->orderByDesc('id')->limit(10)->get(),
            'audit' => PartnerAudit::with('partnerUser')->where('company_id', $company->id)->orderByDesc('id')->limit(15)->get(),
            'loginUrl' => route('partner.login', ['h' => hotel()->code]),
        ]);
    }

    public function terms(Request $r, Company $company)
    {
        try {
            PS::saveTerms($company, $r->only('portal_enabled', 'portal_guest_pay', 'portal_approval_over', 'portal_max_rooms'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['terms' => $e->getMessage()]);
        }

        return back()->with('success', "Portal terms saved for {$company->name}.");
    }

    public function invite(Request $r, Company $company)
    {
        try {
            [$u] = PS::invite($company, $r->only('name', 'email', 'phone', 'role'), $r->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['invite' => $e->getMessage()]);
        }

        return back()->with('success', "Invitation e-mailed to {$u->email} (valid ".setting('partner_invite_days', 7).' days).');
    }

    public function user(Request $r, PartnerUser $puser)
    {
        try {
            match ($r->input('do')) {
                'disable' => PS::setActive($puser, false),
                'enable' => PS::setActive($puser, true),
                'unlock' => PS::unlock($puser),
                'resend' => $puser->isPending() && $puser->is_active ? PS::issueInvite($puser) : throw new RuntimeException('Only an invited user who has not accepted yet can get a new invitation.'),
                'reset' => $puser->isPending() ? throw new RuntimeException('This user has not accepted the invitation yet – resend it instead.') : PS::requestReset($puser->email),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['invite' => $e->getMessage()]);
        }

        return back()->with('success', "{$puser->name}: done.");
    }

    public function requests(Request $r)
    {
        $status = in_array($r->query('status'), ['pending', 'approved', 'declined', 'done', 'withdrawn', 'all']) ? $r->query('status') : 'pending';
        $q = PartnerRequest::with('company', 'partnerUser', 'reservation.rooms.roomType', 'reservation.guest', 'decider')
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when(isset(PartnerRequest::TYPES[$r->query('type')]), fn ($w) => $w->where('type', $r->query('type')));

        return view('partner-admin.requests', ['rows' => $q->orderBy($status === 'pending' ? 'created_at' : 'id', $status === 'pending' ? 'asc' : 'desc')->paginate(25)->withQueryString(),
            'status' => $status, 'counts' => PartnerRequest::where('status', 'pending')->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type')]);
    }

    public function decide(Request $r, PartnerRequest $partnerreq)
    {
        try {
            match ($r->input('do')) {
                'approve' => PS::approve($partnerreq, $r->user(), $r->input('note')),
                'decline' => PS::decline($partnerreq, $r->user(), (string) $r->input('note')),
                'done' => PS::complete($partnerreq, $r->user(), (string) $r->input('note')),
                default => throw new RuntimeException('Unknown action.'),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('success', "{$partnerreq->number}: ".['approve' => 'approved – booking confirmed and the partner notified.', 'decline' => 'declined – the partner has been told why.', 'done' => 'marked done – the partner has your note.'][$r->input('do')]);
    }

    public function activity(Request $r)
    {
        $q = PartnerAudit::with('company', 'partnerUser')
            ->when($r->filled('company'), fn ($w) => $w->where('company_id', (int) $r->query('company')))
            ->when($r->filled('action'), fn ($w) => $w->where('action', $r->query('action')))
            ->when($r->filled('from'), fn ($w) => $w->where('created_at', '>=', $r->query('from').' 00:00:00'))
            ->when($r->filled('to'), fn ($w) => $w->where('created_at', '<=', $r->query('to').' 23:59:59'));

        return view('partner-admin.activity', ['rows' => $q->orderByDesc('id')->paginate(40)->withQueryString(),
            'companies' => Company::whereIn('type', ['corporate', 'travel_agent'])->orderBy('name')->pluck('name', 'id'),
            'actions' => PartnerAudit::distinct()->orderBy('action')->pluck('action')]);
    }

    public function settings()
    {
        return view('partner-admin.settings', ['s' => PS::settings(), 'loginUrl' => route('partner.login', ['h' => hotel()->code])]);
    }

    public function saveSettings(Request $r)
    {
        try {
            PS::saveSettings($r->all());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['settings' => $e->getMessage()]);
        }

        return back()->with('success', 'Partner portal settings saved.');
    }
}
