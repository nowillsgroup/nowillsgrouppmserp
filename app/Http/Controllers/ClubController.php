<?php

namespace App\Http\Controllers;

use App\Models\ClubBooking;
use App\Models\ClubCharge;
use App\Models\ClubDayPass;
use App\Models\ClubMember;
use App\Models\ClubMembership;
use App\Models\ClubPlan;
use App\Models\ClubVisit;
use App\Models\Company;
use App\Models\PaymentMode;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\ClubBillingService;
use App\Services\ClubClassService;
use App\Services\ClubService;
use App\Services\EInvoiceService;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.1 Health club: dashboard, check-in desk, members, day passes, plans & settings. */
class ClubController extends Controller
{
    protected function attempt(callable $fn, string|callable $ok, ?string $route = null)
    {
        try {
            $r = $fn();
            $msg = is_callable($ok) ? $ok($r) : $ok;

            return ($route ? redirect($route) : back())->with('success', $msg);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    protected function modes()
    {
        return PaymentMode::where('is_active', true)->whereIn('type', ['cash', 'card', 'bank', 'wallet'])->orderBy('id')->get();
    }

    // ───────────────────────── dashboard ─────────────────────────

    public function index()
    {
        $today = bdate()->toDateString();
        $week = ClubClassService::week($today);

        return view('club.index', [
            's' => ClubService::stats(),
            'occ' => ClubService::occupancy(),
            'arrears' => ClubBillingService::arrears()->take(8),
            'expiring' => ClubMembership::with('plan')->whereIn('status', ['active', 'frozen'])->whereDate('end_date', '>=', $today)->whereDate('end_date', '<=', Carbon::parse($today)->addDays(14)->toDateString())
                ->orderBy('end_date')->take(10)->get(),
            'inNow' => ClubService::openVisits()->with('member.membership')->latest('checked_in_at')->take(12)->get(),
            'denied' => ClubVisit::where('denied', true)->where('checked_in_at', '>=', now()->subDays(7))->latest('checked_in_at')->take(6)->get(),
            'sessions' => $week['days'][bdate()->isoWeekday()]['sessions'] ?? collect(),
            'plans' => ClubPlan::withCount(['memberships as live' => fn ($q) => $q->whereIn('status', ['active', 'frozen'])])->where('is_active', true)->orderBy('price')->get(),
            'trend' => collect(range(13, 0))->map(fn ($i) => ['d' => now()->subDays($i), 'n' => (int) ClubVisit::where('denied', false)->whereDate('checked_in_at', now()->subDays($i)->toDateString())->sum('pax')]),
        ]);
    }

    // ───────────────────────── desk ─────────────────────────

    public function desk(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $found = $q !== '' ? ClubService::lookup($q) : collect();
        $person = $request->integer('member') ? ClubMember::with('membership.plan')->find($request->integer('member')) : ($found->count() === 1 ? $found->first() : null);
        $facility = $request->query('facility', 'gym');
        if (! array_key_exists($facility, ClubPlan::FACILITIES)) {
            $facility = 'gym';
        }

        return view('club.desk', [
            'q' => $q, 'found' => $found, 'person' => $person, 'facility' => $facility,
            'check' => $person ? ClubService::access($person, $facility) : null,
            'balance' => $person ? ClubBillingService::balance($person->membership) : 0,
            'passesLeft' => $person ? ClubService::guestPassesLeft($person->membership) : 0,
            'occ' => ClubService::occupancy(),
            'inNow' => ClubService::openVisits()->with('member')->latest('checked_in_at')->get(),
            'recent' => ClubVisit::with('member', 'overrider')->whereDate('checked_in_at', now()->toDateString())->latest('id')->take(15)->get(),
            'modes' => $this->modes(),
        ]);
    }

    public function checkIn(Request $request)
    {
        $d = $request->validate(['club_member_id' => 'required|integer', 'facility' => ['required', Rule::in(array_keys(ClubPlan::FACILITIES))], 'override' => 'nullable|string|max:200']);
        $p = ClubMember::findOrFail($d['club_member_id']);

        try {
            $v = ClubService::checkIn($p, $d['facility'], $request->user(), $d['override'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('club.desk', ['member' => $p->id, 'facility' => $d['facility']])->with('error', $e->getMessage());
        }

        return redirect()->route('club.desk', ['facility' => $d['facility']])->with('success', "{$p->name} checked in at the ".strtolower(ClubPlan::FACILITIES[$d['facility']]).' '.$v->checked_in_at->format('H:i').($v->override_reason ? ' (manager override)' : '').'.');
    }

    public function guest(Request $request)
    {
        $d = $request->validate(['club_member_id' => 'required|integer', 'facility' => ['required', Rule::in(array_keys(ClubPlan::FACILITIES))], 'name' => 'required|string|max:120', 'payment_mode_id' => 'nullable|integer']);
        $p = ClubMember::findOrFail($d['club_member_id']);

        try {
            $v = ClubService::checkInGuest($p, $d['name'], $d['facility'], $request->user(), $d['payment_mode_id'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('club.desk', ['member' => $p->id, 'facility' => $d['facility']])->with('error', $e->getMessage());
        }
        $how = $v->club_charge_id ? ' Guest pass charged to the member\'s account.' : ($v->club_day_pass_id ? ' Guest pass paid.' : ' Free guest pass used.');

        return redirect()->route('club.desk', ['member' => $p->id, 'facility' => $d['facility']])->with('success', "{$v->name} checked in as {$p->name}'s guest.".$how);
    }

    public function resident(Request $request)
    {
        $d = $request->validate(['room_no' => 'required|string|max:20', 'facility' => ['required', Rule::in(array_keys(ClubPlan::FACILITIES))], 'pax' => 'nullable|integer|min:1|max:10', 'override' => 'nullable|string|max:200']);

        return $this->attempt(fn () => ClubService::checkInResident($d['room_no'], $d['facility'], (int) ($d['pax'] ?? 1), $request->user(), $d['override'] ?? null),
            fn ($v) => "{$v->name} checked in at the ".strtolower(ClubPlan::FACILITIES[$v->facility]).($v->pax > 1 ? " ({$v->pax} people)" : '').'.');
    }

    /** v7.38 Walk-in ticket redemption — scan / type the code printed on the outlet receipt. */
    public function ticket(Request $request)
    {
        $d = $request->validate(['code' => 'required|string|max:20', 'facility' => ['required', Rule::in(array_keys(ClubPlan::FACILITIES))], 'override' => 'nullable|string|max:200']);
        $pass = ClubDayPass::where('pass_no', strtoupper(trim($d['code'])))->first();
        if (! $pass) {
            return back()->with('error', 'No ticket or pass with that code.');
        }

        try {
            $v = ClubService::checkInPass($pass, $d['facility'], $request->user(), $d['override'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('club.desk', ['facility' => $d['facility']])
            ->with('success', "Ticket {$pass->pass_no} ({$pass->name}, {$v->pax} pax) checked in at the ".strtolower(ClubPlan::FACILITIES[$v->facility]).'.');
    }

    public function checkOut(ClubVisit $clubvisit)
    {
        return $this->attempt(fn () => ClubService::checkOut($clubvisit), "{$clubvisit->name} checked out.");
    }

    public function visits(Request $request)
    {
        $from = $request->query('from', bdate()->copy()->subDays(6)->toDateString());
        $to = $request->query('to', now()->toDateString());
        $rows = ClubVisit::with('member.membership', 'host', 'user', 'overrider')->whereBetween('checked_in_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->when($request->query('facility'), fn ($q, $f) => $q->where('facility', $f))
            ->when($request->query('type'), fn ($q, $t) => $q->where('visitor_type', $t))
            ->when($request->query('denied') === '1', fn ($q) => $q->where('denied', true))
            ->latest('checked_in_at')->paginate(50)->withQueryString();

        return view('club.visits', compact('rows', 'from', 'to'));
    }

    // ───────────────────────── members ─────────────────────────

    public function members(Request $request)
    {
        $status = $request->query('status', 'live');
        $rows = ClubMembership::with('plan', 'company')->withCount(['members' => fn ($q) => $q->where('is_active', true)])
            ->when($status === 'live', fn ($q) => $q->whereIn('status', ['active', 'frozen']))
            ->when(in_array($status, array_keys(ClubMembership::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->when($request->query('plan'), fn ($q, $p) => $q->where('club_plan_id', $p))
            ->when(trim((string) $request->query('q')), fn ($q, $s) => $q->where(fn ($w) => $w->where('holder_name', 'like', "%{$s}%")->orWhere('membership_no', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")
                ->orWhereHas('members', fn ($m) => $m->where('name', 'like', "%{$s}%")->orWhere('card_no', $s))))
            ->orderByRaw("field(status,'active','frozen','expired','cancelled')")->orderBy('end_date')->paginate(40)->withQueryString();
        $bal = ClubCharge::whereIn('club_membership_id', $rows->pluck('id'))->whereNull('cancelled_at')->selectRaw('club_membership_id, SUM(total - paid) o')->groupBy('club_membership_id')->pluck('o', 'club_membership_id');
        $counts = ClubMembership::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('club.members', ['rows' => $rows, 'bal' => $bal, 'counts' => $counts, 'status' => $status, 'plans' => ClubPlan::orderBy('price')->get()]);
    }

    public function create()
    {
        return view('club.enrol', ['plans' => ClubPlan::where('is_active', true)->orderBy('category')->orderBy('price')->get(), 'companies' => Company::orderBy('name')->get(['id', 'name']),
            'modes' => $this->modes(), 'tax' => ClubBillingService::tax(100)['rate']]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'club_plan_id' => 'required|integer', 'holder_name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:120',
            'company_id' => 'nullable|integer', 'guest_id' => 'nullable|integer', 'start_date' => 'required|date', 'auto_renew' => 'nullable|boolean', 'waive_joining' => 'nullable|boolean',
            'source' => 'nullable|string', 'notes' => 'nullable|string|max:500',
            'persons' => 'array|max:8', 'persons.*.name' => 'nullable|string|max:120', 'persons.*.relation' => 'nullable|string', 'persons.*.gender' => 'nullable|in:M,F',
            'persons.*.dob' => 'nullable|date|before:today', 'persons.*.phone' => 'nullable|string|max:30', 'persons.*.emergency_contact' => 'nullable|string|max:120', 'persons.*.health' => 'nullable|boolean',
            'pay_amount' => 'nullable|numeric|min:0', 'payment_mode_id' => 'nullable|integer|required_with:pay_amount', 'reference' => 'nullable|string|max:80',
        ]);
        if (! empty($d['waive_joining']) && ! $request->user()->hasPermission('club.manage')) {
            return back()->withInput()->with('error', 'Only a club manager can waive the joining fee.');
        }
        $d['payment'] = ['amount' => $d['pay_amount'] ?? 0, 'payment_mode_id' => $d['payment_mode_id'] ?? null, 'reference' => $d['reference'] ?? null];
        $d['auto_renew'] = $request->boolean('auto_renew');

        try {
            $m = ClubService::enrol($d, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('club.members.show', $m)->with('success', "Membership {$m->membership_no} created for {$m->holder_name}. Print the member cards from here.");
    }

    public function show(ClubMembership $membership)
    {
        $membership->load('plan', 'company', 'guest', 'members', 'creator');
        $cards = $membership->members->pluck('id');

        return view('club.show', [
            'm' => $membership,
            'charges' => ClubCharge::with('creditOf', 'user')->where('club_membership_id', $membership->id)->orderByDesc('date')->orderByDesc('id')->get(),
            'payments' => $membership->payments()->with('paymentMode', 'user')->orderByDesc('date')->orderByDesc('id')->get(),
            'balance' => ClubBillingService::balance($membership),
            'overdue' => ClubBillingService::overdue($membership),
            'visits' => ClubVisit::whereIn('club_member_id', $cards)->orWhereIn('host_member_id', $cards)->latest('checked_in_at')->take(20)->get(),
            'bookings' => ClubBooking::with('klass')->whereIn('club_member_id', $cards)->latest('date')->take(10)->get(),
            'unused' => ClubService::unusedValue($membership),
            'passesLeft' => $membership->plan ? ClubService::guestPassesLeft($membership) : 0,
            'plans' => ClubPlan::where('is_active', true)->orderBy('price')->get(),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'modes' => $this->modes(),
            'visits30' => ClubVisit::whereIn('club_member_id', $cards)->where('denied', false)->where('checked_in_at', '>=', now()->subDays(30))->count(),
        ]);
    }

    public function update(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['holder_name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:120', 'company_id' => 'nullable|integer',
            'auto_renew' => 'nullable|boolean', 'notes' => 'nullable|string|max:500']);
        $d['auto_renew'] = $request->boolean('auto_renew') && ! $membership->cancel_mode;
        $membership->update($d);
        ActivityLogger::log('club', 'updated', "Membership {$membership->membership_no} details updated", $membership);

        return back()->with('success', 'Membership details saved.');
    }

    public function addPerson(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['name' => 'required|string|max:120', 'relation' => 'nullable|string', 'gender' => 'nullable|in:M,F', 'dob' => 'nullable|date|before:today',
            'phone' => 'nullable|string|max:30', 'emergency_contact' => 'nullable|string|max:120', 'health' => 'nullable|boolean']);

        return $this->attempt(fn () => ClubService::addPerson($membership->load('plan'), $d), fn ($p) => "Card {$p->card_no} issued to {$p->name}.");
    }

    public function updatePerson(Request $request, ClubMember $clubmember)
    {
        $d = $request->validate(['name' => 'required|string|max:120', 'relation' => 'nullable|string', 'gender' => 'nullable|in:M,F', 'dob' => 'nullable|date|before:today',
            'phone' => 'nullable|string|max:30', 'emergency_contact' => 'nullable|string|max:120', 'health' => 'nullable|boolean', 'is_active' => 'nullable|boolean']);
        $active = $request->boolean('is_active');
        if ($active && ! $clubmember->is_active && $clubmember->membership->members()->where('is_active', true)->count() >= $clubmember->membership->plan->max_persons) {
            return back()->with('error', 'The plan covers at most '.$clubmember->membership->plan->max_persons.' person(s).');
        }
        if (! $active && $clubmember->relation === 'primary') {
            return back()->with('error', 'The primary member\'s card cannot be deactivated – cancel the membership instead.');
        }
        $clubmember->update(['name' => $d['name'], 'relation' => $clubmember->relation === 'primary' ? 'primary' : (array_key_exists($d['relation'] ?? '', ClubMember::RELATIONS) && $d['relation'] !== 'primary' ? $d['relation'] : $clubmember->relation),
            'gender' => $d['gender'] ?? null, 'dob' => $d['dob'] ?? null, 'phone' => $d['phone'] ?? null, 'emergency_contact' => $d['emergency_contact'] ?? null, 'is_active' => $active,
            'health_declared_at' => $request->boolean('health') ? ($clubmember->health_declared_at ?? now()) : null]);

        return back()->with('success', "{$clubmember->name} updated.");
    }

    public function photo(Request $request, ClubMember $clubmember)
    {
        $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);
        $f = $request->file('photo');
        $path = $f->storeAs('club-photos/'.Tenant::id(), $clubmember->id.'-'.substr(md5(uniqid('', true)), 0, 8).'.'.$f->extension(), 'local');
        if ($clubmember->photo_path && $clubmember->photo_path !== $path) {
            Storage::disk('local')->delete($clubmember->photo_path);
        }
        $clubmember->update(['photo_path' => $path]);

        return back()->with('success', "Photo saved for {$clubmember->name}.");
    }

    public function photoShow(ClubMember $clubmember)
    {
        abort_unless($clubmember->photo_path && Storage::disk('local')->exists($clubmember->photo_path), 404);

        return Storage::disk('local')->response($clubmember->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function newCard(Request $request, ClubMember $clubmember)
    {
        $old = $clubmember->card_no;
        $clubmember->update(['card_no' => ClubService::newCardNo()]);
        ActivityLogger::log('club', 'card_replaced', "Card {$old} of {$clubmember->name} replaced by {$clubmember->card_no}", $clubmember);

        return back()->with('success', "New card {$clubmember->card_no} issued – the old card {$old} no longer works.");
    }

    public function cards(Request $request, ClubMembership $membership)
    {
        $membership->load('plan', 'members');
        $persons = $membership->members->where('is_active', true)->when($request->integer('person'), fn ($c, $id) => $c->where('id', $id));
        $qr = $persons->mapWithKeys(fn ($p) => [$p->id => EInvoiceService::qrSvg($p->card_no)]);
        $photos = $persons->mapWithKeys(fn ($p) => [$p->id => $p->photo_path && Storage::disk('local')->exists($p->photo_path)
            ? 'data:'.Storage::disk('local')->mimeType($p->photo_path).';base64,'.base64_encode(Storage::disk('local')->get($p->photo_path)) : null]);

        return view('club.cards', ['m' => $membership, 'persons' => $persons, 'qr' => $qr, 'photos' => $photos, 'hotel' => Tenant::hotel()]);
    }

    public function freeze(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['from' => 'required|date', 'to' => 'required|date', 'reason' => 'required|string|max:160']);

        return $this->attempt(fn () => ClubService::freeze($membership->load('plan'), $d['from'], $d['to'], $d['reason'], $request->user()),
            fn () => 'Membership frozen '.dmy($d['from']).' – '.dmy($d['to']).'. It now ends on '.dmy($membership->fresh()->end_date).'.');
    }

    public function unfreeze(Request $request, ClubMembership $membership)
    {
        return $this->attempt(fn () => ClubService::unfreeze($membership, $request->user()), fn () => 'Freeze ended. The membership now ends on '.dmy($membership->fresh()->end_date).'.');
    }

    public function cancel(Request $request, ClubMembership $membership)
    {
        $d = $request->validate(['mode' => 'required|in:term_end,immediate', 'reason' => 'required|string|max:255', 'credit' => 'nullable|numeric|min:0']);

        return $this->attempt(fn () => ClubService::cancel($membership->load('plan'), $d['mode'], $d['reason'], $d['mode'] === 'immediate' && isset($d['credit']) ? (float) $d['credit'] : null, $request->user()),
            fn () => $d['mode'] === 'term_end' ? 'Notice recorded – the membership will not renew and ends on '.dmy($membership->end_date).'.'
                : 'Membership cancelled.'.(\App\Models\ClubCharge::where('club_membership_id', $membership->id)->where('type', 'credit')->whereDate('created_at', today())->exists() ? ' Credit note issued – refund the credit balance or leave it on account.' : ''));
    }

    public function withdrawNotice(ClubMembership $membership)
    {
        return $this->attempt(fn () => ClubService::withdrawNotice($membership), 'Cancellation notice withdrawn – auto-renewal is back on.');
    }

    // ───────────────────────── day passes ─────────────────────────

    public function passes(Request $request)
    {
        $date = $request->query('date');
        $rows = ClubDayPass::with('paymentMode', 'stay.room', 'host', 'user')->when($date, fn ($q) => $q->whereDate('date', $date))->latest('date')->latest('id')->paginate(40)->withQueryString();
        $today = ClubDayPass::whereNull('cancelled_at')->whereDate('date', bdate()->toDateString());

        return view('club.passes', ['rows' => $rows, 'date' => $date, 'modes' => $this->modes(),
            'todayCount' => (clone $today)->sum(\DB::raw('adults + children')), 'todayTotal' => (clone $today)->sum('total'),
            'mtd' => ClubDayPass::whereNull('cancelled_at')->whereBetween('date', [bdate()->copy()->startOfMonth()->toDateString(), bdate()->toDateString()])->sum('amount'),
            'adult' => (float) ClubService::cfg('club_daypass_adult'), 'child' => (float) ClubService::cfg('club_daypass_child'), 'rate' => ClubBillingService::tax(100)['rate']]);
    }

    public function sellPass(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'adults' => 'required|integer|min:0|max:10', 'children' => 'nullable|integer|min:0|max:10',
            'settle' => 'required|in:pay,room', 'payment_mode_id' => 'nullable|required_if:settle,pay|integer', 'room_no' => 'nullable|required_if:settle,room|string|max:20',
            'reference' => 'nullable|string|max:80', 'facility' => ['nullable', Rule::in(array_keys(ClubPlan::FACILITIES))]]);
        if ($d['settle'] === 'room') {
            unset($d['payment_mode_id']);
        } else {
            unset($d['room_no']);
        }

        return $this->attempt(fn () => ClubService::sellDayPass($d, $request->user(), $d['facility'] ?? null),
            fn ($p) => "Day pass {$p->pass_no} – ".money($p->total).($p->reservation_room_id ? ' charged to the room' : ' received').(! empty($d['facility']) ? ' and checked in' : '').'.');
    }

    public function cancelPass(Request $request, ClubDayPass $daypass)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => ClubService::cancelDayPass($daypass, $d['reason'], $request->user()), "Day pass {$daypass->pass_no} cancelled.".($daypass->reservation_room_id ? ' The room charge was voided.' : ' Refund the visitor.'));
    }

    // ───────────────────────── plans & settings ─────────────────────────

    public function plans()
    {
        return view('club.plans', [
            'plans' => ClubPlan::withCount(['memberships as live' => fn ($q) => $q->whereIn('status', ['active', 'frozen'])])->orderByDesc('is_active')->orderBy('category')->orderBy('price')->get(),
            'settings' => collect(ClubService::SETTINGS)->map(fn ($s, $k) => ['label' => $s[0], 'value' => ClubService::cfg($k)]),
            'rate' => ClubBillingService::tax(100)['rate'],
        ]);
    }

    public function savePlan(Request $request, ?ClubPlan $clubplan = null)
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:12', 'alpha_dash', Rule::unique('club_plans')->where('hotel_id', Tenant::id())->ignore($clubplan?->id)],
            'name' => 'required|string|max:80', 'category' => ['required', Rule::in(array_keys(ClubPlan::CATEGORIES))], 'term_months' => ['required', Rule::in(array_keys(ClubPlan::TERMS))],
            'price' => 'required|numeric|min:1', 'joining_fee' => 'nullable|numeric|min:0', 'max_persons' => 'required|integer|min:1|max:8',
            'facilities' => 'required|array|min:1', 'facilities.*' => Rule::in(array_keys(ClubPlan::FACILITIES)),
            'access_from' => 'nullable|date_format:H:i|required_with:access_to', 'access_to' => 'nullable|date_format:H:i|required_with:access_from|after:access_from',
            'guest_passes' => 'nullable|integer|min:0|max:20', 'fnb_discount_pct' => 'nullable|numeric|min:0|max:50', 'spa_discount_pct' => 'nullable|numeric|min:0|max:50',
            'freeze_days_max' => 'nullable|integer|min:0|max:180', 'description' => 'nullable|string|max:255', 'is_active' => 'nullable|boolean',
        ]);
        $d['is_active'] = $request->boolean('is_active');
        $d['code'] = strtoupper($d['code']);
        foreach (['joining_fee', 'guest_passes', 'fnb_discount_pct', 'spa_discount_pct'] as $k) {
            $d[$k] = $d[$k] ?? 0;
        }
        $d['freeze_days_max'] = $d['freeze_days_max'] ?? 30;
        $plan = $clubplan ? tap($clubplan)->update($d) : ClubPlan::create($d);
        ActivityLogger::log('club', 'plan_saved', "Club plan {$plan->code} – {$plan->name} saved (".money($plan->price).' / '.strtolower($plan->termLabel()).')', $plan);

        return back()->with('success', "Plan {$plan->name} saved.".($clubplan ? ' New prices apply from the next renewal.' : ''));
    }

    public function settings(Request $request)
    {
        $rules = collect(ClubService::SETTINGS)->mapWithKeys(fn ($s, $k) => [$k => 'required|numeric|min:0|max:100000'])->all();
        $d = $request->validate($rules);
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) (float) $v == (string) (int) $v ? (string) (int) $v : (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('club', 'settings', 'Health club settings updated');

        return back()->with('success', 'Club settings saved.');
    }

    public function nightly(Request $request)
    {
        $r = ClubService::expiryRun();
        $rn = ClubService::renewalRun($request->user());

        return back()->with($rn['errors'] ? 'warning' : 'success', "Club housekeeping: {$r['expired']} expired, {$r['cancelled']} ended at term, {$r['frozen']} freezes started, {$r['unfrozen']} ended, {$r['checked_out']} visits closed, {$r['no_shows']} no-shows; "
            .count($rn['renewed']).' renewal invoice(s) raised.'.($rn['errors'] ? ' Problems: '.implode('; ', $rn['errors']) : ''));
    }
}
