<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrivacyRequestController extends Controller
{
    public function create(Request $request)
    {
        $guest = $request->guest_id ? Guest::find($request->guest_id) : null;
        $req = new PrivacyRequest(['type' => $request->type && isset(PrivacyRequest::TYPES[$request->type]) ? $request->type : 'access', 'channel' => 'in_person',
            'guest_id' => $guest?->id, 'requester_name' => $guest && ! $guest->isErased() ? $guest->full_name : null,
            'requester_email' => $guest?->email, 'requester_phone' => $guest?->phone]);

        return view('privacy.request-form', ['req' => $req, 'guest' => $guest, 'settings' => PrivacyService::settings()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PrivacyRequest::TYPES))], 'channel' => ['required', Rule::in(array_keys(PrivacyRequest::CHANNELS))],
            'requester_name' => 'required|string|max:120', 'requester_email' => 'nullable|email|max:150', 'requester_phone' => 'nullable|string|max:30',
            'relationship' => ['required', Rule::in(['self', 'nominee', 'authorised'])], 'booking_ref' => 'nullable|string|max:40',
            'details' => 'nullable|string|max:4000', 'received_at' => 'required|date|before_or_equal:today|after:'.now()->subDays(90)->toDateString(),
            'guest_id' => 'nullable|integer|owned:guests', 'verification_method' => ['nullable', Rule::in(array_keys(PrivacyRequest::METHODS))],
        ]);
        if (! $data['requester_email'] && ! $data['requester_phone']) {
            return back()->withInput()->withErrors(['requester_email' => 'Record an e-mail address or mobile number so we can reply.']);
        }
        $method = $data['verification_method'] ?? null;
        unset($data['verification_method']);
        $req = PrivacyService::createRequest($data + ['status' => 'open'], auth()->id());
        if ($method) {
            $this->markVerified($req, $method, 'Verified when the request was logged.');
        }

        return redirect()->route('privacy.requests.show', $req)->with('success', "Request {$req->request_no} logged – reply due by {$req->due_on->format('d M Y')}.");
    }

    public function show(PrivacyRequest $preq)
    {
        $preq->load('guest.company', 'events.user', 'verifier', 'assignee', 'completer', 'creator');
        $candidates = collect();
        if (! $preq->guest_id) {
            $candidates = Guest::whereNull('privacy_status')->where(function ($q) use ($preq) {
                $q->when($preq->requester_email, fn ($w) => $w->orWhere('email', $preq->requester_email))
                    ->when($preq->requester_phone, fn ($w) => $w->orWhere('phone', $preq->requester_phone))
                    ->orWhere(fn ($w) => $w->whereRaw("concat_ws(' ', first_name, last_name) = ?", [$preq->requester_name]));
            })->limit(10)->get();
        }
        $plan = $preq->type === 'erasure' && $preq->guest && ! $preq->guest->isErased() && $preq->isOpen() ? PrivacyService::plan($preq->guest) : null;

        return view('privacy.request', ['req' => $preq, 'candidates' => $candidates, 'plan' => $plan,
            'users' => User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')]);
    }

    protected function markVerified(PrivacyRequest $req, string $method, ?string $note): void
    {
        $req->forceFill(['verified_at' => now(), 'verified_by' => auth()->id(), 'verification_method' => $method])->save();
        $req->log('verified', 'Identity verified – '.PrivacyRequest::METHODS[$method].($note ? ". {$note}" : '.'));
    }

    public function verify(Request $request, PrivacyRequest $preq)
    {
        $data = $request->validate(['verification_method' => ['required', Rule::in(array_keys(PrivacyRequest::METHODS))], 'note' => 'nullable|string|max:500']);
        PrivacyService::assertOpen($preq);
        if ($data['verification_method'] === 'email_link' && $preq->type === 'erasure') {
            return back()->withErrors(['verification_method' => 'An e-mail link alone is not enough to erase a profile – check photo ID, booking details or reply from the contact on file.']);
        }
        $this->markVerified($preq, $data['verification_method'], $data['note'] ?? null);

        return back()->with('success', 'Identity verified.');
    }

    public function link(Request $request, PrivacyRequest $preq)
    {
        $data = $request->validate(['guest_id' => 'nullable|integer|owned:guests']);
        PrivacyService::assertOpen($preq);
        $guest = ! empty($data['guest_id']) ? Guest::find($data['guest_id']) : null;
        $preq->forceFill(['guest_id' => $guest?->id])->save();
        $preq->log('linked', $guest ? 'Linked to guest profile G-'.$guest->id.'.' : 'Guest link removed.');

        return back()->with('success', $guest ? "Linked to {$guest->full_name} (G-{$guest->id})." : 'Guest link removed.');
    }

    public function note(Request $request, PrivacyRequest $preq)
    {
        $data = $request->validate(['note' => 'required|string|min:3|max:2000']);
        $preq->log('note', $data['note']);

        return back()->with('success', 'Note added.');
    }

    public function assign(Request $request, PrivacyRequest $preq)
    {
        $data = $request->validate(['assigned_to' => 'nullable|integer|owned:users']);
        $preq->forceFill(['assigned_to' => $data['assigned_to'] ?? null])->save();
        $preq->log('assigned', $preq->assignee ? 'Assigned to '.$preq->assignee->name.'.' : 'Unassigned.');

        return back()->with('success', 'Assignment saved.');
    }

    public function extend(Request $request, PrivacyRequest $preq)
    {
        $data = $request->validate(['extension_reason' => 'required|string|min:10|max:255']);
        PrivacyService::extend($preq, $data['extension_reason'], auth()->id());

        return back()->with('success', "Deadline extended to {$preq->due_on->format('d M Y')} – tell the requester why.");
    }

    public function consent(PrivacyRequest $preq)
    {
        abort_unless($preq->type === 'consent', 403);
        $n = PrivacyService::withdrawConsent($preq, auth()->id());

        return back()->with('success', $n ? "Consent withdrawn on {$n} channel(s) – request completed." : 'No active consent to withdraw – request completed.');
    }

    public function complete(Request $request, PrivacyRequest $preq)
    {
        $data = $request->validate(['outcome' => 'required|string|min:10|max:2000']);
        PrivacyService::complete($preq, $data['outcome'], auth()->id());

        return back()->with('success', "Request {$preq->request_no} completed.");
    }

    public function reject(Request $request, PrivacyRequest $preq)
    {
        $data = $request->validate(['rejection_reason' => 'required|string|min:5|max:255']);
        PrivacyService::reject($preq, $data['rejection_reason'], auth()->id());

        return back()->with('success', "Request {$preq->request_no} rejected – tell the requester the reason and how to escalate.");
    }

    public function export(PrivacyRequest $preq)
    {
        $guest = PrivacyService::assertReady($preq);
        $preq->increment('exports');
        $preq->log('exported', 'Data export generated (JSON).');
        PrivacyService::logAccess($guest, 'export', "Access request {$preq->request_no} (JSON)", 'Data-subject access request');

        return response()->json(PrivacyService::export($guest), 200, [
            'Content-Disposition' => 'attachment; filename="'.$preq->request_no.'-personal-data.json"', 'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function report(PrivacyRequest $preq)
    {
        $guest = PrivacyService::assertReady($preq);
        $preq->increment('exports');
        $preq->log('exported', 'Printable data report generated.');
        PrivacyService::logAccess($guest, 'export', "Access request {$preq->request_no} (printable)", 'Data-subject access request');

        return view('privacy.report', ['req' => $preq, 'data' => PrivacyService::export($guest)]);
    }

    public function erase(Request $request, PrivacyRequest $preq)
    {
        abort_unless($preq->type === 'erasure', 403);
        $request->validate(['confirm' => 'required|in:ERASE'], ['confirm.in' => 'Type ERASE (capitals) to confirm.', 'confirm.required' => 'Type ERASE (capitals) to confirm.']);
        $guest = PrivacyService::assertReady($preq);
        $r = PrivacyService::erase($guest, $preq, auth()->id());
        ActivityLogger::log('privacy', 'erase_request', "Erasure executed for {$preq->request_no}", $preq);

        return back()->with('success', $r['status'] === 'erased' ? 'Personal data erased – request completed.'
            : 'Personal data erased except what the law requires; profile restricted until '.$r['hold_until']->format('d M Y').' – request completed.');
    }
}
