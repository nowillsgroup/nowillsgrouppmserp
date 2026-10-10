<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Guest;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    public function index(Request $request)
    {
        $guests = Guest::with('company')->withCount(['reservations as stays_count' => fn ($q) => $q->where('status', 'checked_out')])
            ->search($request->q)
            ->when($request->filter === 'vip', fn ($q) => $q->where('is_vip', true))
            ->when($request->filter === 'blacklisted', fn ($q) => $q->where('is_blacklisted', true))
            ->when($request->filter === 'foreign', fn ($q) => $q->whereNotNull('nationality')->where('nationality', '!=', '')->whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(nationality)'), \App\Services\FormCService::homeAliases()))
            ->when($request->filter === 'birthday', fn ($q) => $q->whereMonth('dob', bdate()->month))
            ->orderBy('first_name')->paginate(25)->withQueryString();

        return view('guests.index', compact('guests'));
    }

    protected function rules(): array
    {
        return [
            'title' => 'nullable|string|max:10', 'first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80',
            'gender' => 'nullable|string|max:10', 'dob' => 'nullable|date|before:today', 'anniversary' => 'nullable|date',
            'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:150', 'nationality' => 'nullable|string|max:60',
            'id_type' => 'nullable|string|max:30', 'id_number' => 'nullable|string|max:60', 'passport_no' => 'nullable|string|max:40',
            'visa_expiry' => 'nullable|date', 'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:80',
            'state' => 'nullable|string|max:80', 'country' => 'nullable|string|max:80', 'pincode' => 'nullable|string|max:20',
            'tax_number' => 'nullable|string|max:40|tin', 'company_id' => 'nullable|integer|owned:companies',
            'preferences' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:2000',
            'is_vip' => 'boolean', 'is_blacklisted' => 'boolean', 'tags' => 'nullable|string|max:255',
        ];
    }

    public function create()
    {
        return view('guests.form', ['guest' => new Guest, 'companies' => Company::orderBy('name')->pluck('name', 'id')]);
    }

    public function store(Request $request)
    {
        abort_unless(can_do('guests.manage'), 403);
        $request->merge(['is_vip' => $request->boolean('is_vip'), 'is_blacklisted' => $request->boolean('is_blacklisted')]);
        $data = $request->validate($this->rules());
        $data['tags'] = \App\Services\CrmService::normaliseTags($data['tags'] ?? '') ?: null;
        $guest = Guest::create($data);
        $this->applyConsent($request, $guest);
        ActivityLogger::log('guests', 'create', "Created guest {$guest->full_name}", $guest);

        return redirect()->route('guests.show', $guest)->with('success', 'Guest profile created.');
    }

    public function show(Guest $guest)
    {
        $guest->load(['company', 'documents', 'reservations' => fn ($q) => $q->with('rooms.room', 'rooms.roomType')->latest('arrival_date')]);
        $folios = $guest->folios()->with('transactions')->get();
        $spend = $folios->sum(fn ($f) => $f->totals()['charges'] - $f->totals()['discount']);

        $phone = \App\Services\MessageService::normalise($guest->phone);
        $messages = can_do('messages.send') || can_do('logs.view') ? \App\Models\MessageLog::with('user')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('related_type', 'Reservation')->whereIn('related_id', $guest->reservations->pluck('id')))->when($phone, fn ($w) => $w->orWhere('to', $phone)))
            ->latest('id')->limit(20)->get() : collect();

        return view('guests.show', ['guest' => $guest, 'folios' => $folios, 'spend' => $spend, 'messages' => $messages,
            'dining' => can_do('tables.view') ? \App\Models\TableReservation::with('outlet', 'tables')->where(fn ($q) => $q->where('guest_id', $guest->id)->when($guest->phone, fn ($w) => $w->orWhere('phone', $guest->phone)))->latest('start_at')->limit(10)->get() : collect(),
            'diningStats' => can_do('tables.view') ? \App\Services\TableBookingService::history($guest->phone, $guest->id) : null,
            'spa' => can_do('spa.view') ? \App\Models\SpaAppointment::with('service')->where('guest_id', $guest->id)->latest('date')->limit(10)->get() : collect(),
            'nights' => $guest->reservations->where('status', 'checked_out')->sum('nights'),
            'marketing' => can_do('crm.view') ? $guest->marketingMessages()->with('campaign', 'journey', 'reservation')->latest('id')->limit(15)->get() : collect(),
            'consentLog' => $guest->consentLogs()->with('user')->latest('id')->limit(10)->get(), 'rfm' => \App\Services\CrmService::rfm($guest),
            'privacyRequests' => can_do('privacy.view') ? $guest->privacyRequests()->latest('id')->get() : collect(),
            'piiViews' => \App\Models\PiiAccessLog::where('guest_id', $guest->id)->where('created_at', '>=', now()->subDays(30))->count(),
            // v3.1: what the guest told us – surveys, reviews matched to them and service-recovery cases
            'feedback' => can_do('feedback.view') || can_do('feedback.cases') ? \App\Models\GuestFeedback::where(fn ($q) => $q->where('guest_id', $guest->id)->orWhereIn('reservation_id', $guest->reservations->pluck('id')))->latest('id')->limit(10)->get() : collect(),
            'reviews' => can_do('feedback.view') ? \App\Models\OnlineReview::where('guest_id', $guest->id)->latest('review_date')->limit(5)->get() : collect(),
            'cases' => can_do('feedback.cases') ? \App\Models\FeedbackCase::where('guest_id', $guest->id)->latest('id')->limit(10)->get() : collect()]);
    }

    public function edit(Guest $guest)
    {
        if ($guest->isPrivacyLocked()) {
            return redirect()->route('guests.show', $guest)->with('error', 'This profile is '.$guest->privacy_status.' under the privacy policy and cannot be edited.');
        }

        return view('guests.form', ['guest' => $guest, 'companies' => Company::orderBy('name')->pluck('name', 'id')]);
    }

    public function update(Request $request, Guest $guest)
    {
        abort_unless(can_do('guests.manage'), 403);
        if ($guest->isPrivacyLocked()) {
            return redirect()->route('guests.show', $guest)->with('error', 'This profile is '.$guest->privacy_status.' under the privacy policy and cannot be edited.');
        }
        $request->merge(['is_vip' => $request->boolean('is_vip'), 'is_blacklisted' => $request->boolean('is_blacklisted')]);
        $data = $request->validate($this->rules());
        $data['tags'] = \App\Services\CrmService::normaliseTags($data['tags'] ?? '') ?: null;
        $guest->update($data);
        $this->applyConsent($request, $guest);
        ActivityLogger::log('guests', 'update', "Updated guest {$guest->full_name}", $guest);
        $fc = $guest->wasChanged('nationality') ? \App\Services\FormCService::syncGuest($guest->fresh()) : 0; // v3.4: now foreign while in house → Form C

        return redirect()->route('guests.show', $guest)->with('success', 'Guest profile updated.'.($fc ? " {$fc} Form C opened – file it with the FRRO within 24 h." : ''));
    }

    /** Consent checkboxes on the guest form (only when the form carried them). */
    protected function applyConsent(Request $request, Guest $guest): void
    {
        if ($request->has('consent_form')) {
            \App\Services\CrmService::setConsent($guest, collect(\App\Services\CrmService::CHANNELS)->map(fn ($l, $ch) => $request->boolean($ch.'_opt_in'))->all(), 'front_desk', $request->ip());
        }
    }

    public function consent(Request $request, Guest $guest)
    {
        if ($guest->isPrivacyLocked()) {
            return back()->with('error', 'Consent cannot be given for an erased or restricted profile.');
        }
        $n = \App\Services\CrmService::setConsent($guest, collect(\App\Services\CrmService::CHANNELS)->map(fn ($l, $ch) => $request->boolean($ch.'_opt_in'))->all(), 'front_desk', $request->ip());
        ActivityLogger::log('guests', 'consent', "Marketing consent updated for {$guest->full_name}", $guest);

        return back()->with('success', $n ? 'Marketing consent updated.' : 'No change to marketing consent.');
    }
}
