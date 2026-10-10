<?php

namespace App\Http\Controllers;

use App\Models\DataBreach;
use App\Services\ActivityLogger;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrivacyBreachController extends Controller
{
    public function index(Request $request)
    {
        $breaches = DataBreach::with('reporter:id,name')->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("status = 'closed'")->orderByDesc('detected_at')->paginate(25)->withQueryString();

        return view('privacy.breaches', ['breaches' => $breaches, 'hours' => PrivacyService::settings()['breach_notify_hours']]);
    }

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:150', 'description' => 'required|string|min:10|max:5000',
            'detected_at' => 'required|date|before_or_equal:now', 'occurred_at' => 'nullable|date|before_or_equal:detected_at',
            'severity' => ['required', Rule::in(array_keys(DataBreach::SEVERITIES))], 'categories' => 'nullable|array',
            'categories.*' => Rule::in(array_keys(DataBreach::CATEGORIES)), 'affected_count' => 'required|integer|min:0|max:10000000',
            'notifiable' => 'boolean', 'not_notifiable_reason' => 'nullable|required_if:notifiable,false|string|max:255',
        ];
    }

    protected function payload(Request $request): array
    {
        $request->merge(['notifiable' => $request->boolean('notifiable')]);
        $data = $request->validate($this->rules(), ['not_notifiable_reason.required_if' => 'Record why this breach does not need to be notified.']);
        $data['categories'] = implode(',', $data['categories'] ?? []) ?: null;
        if ($data['notifiable']) {
            $data['not_notifiable_reason'] = null;
        }

        return $data;
    }

    public function create()
    {
        return view('privacy.breach-form', ['breach' => new DataBreach(['detected_at' => now(), 'notifiable' => true])]);
    }

    public function store(Request $request)
    {
        $data = $this->payload($request);
        $b = DataBreach::create($data + ['ref' => PrivacyService::nextBreachRef(), 'reported_by' => auth()->id()]);
        ActivityLogger::log('privacy', 'breach', "Breach {$b->ref} recorded: {$b->title}", $b);

        return redirect()->route('privacy.breaches.show', $b)->with('success', "Breach {$b->ref} recorded.".($b->notifiable ? ' Notify the Data Protection Commission by '.$b->deadline()->format('d M Y H:i').'.' : ''));
    }

    public function show(DataBreach $breach)
    {
        return view('privacy.breach', ['b' => $breach->load('reporter')]);
    }

    public function edit(DataBreach $breach)
    {
        abort_if($breach->status === 'closed', 403);

        return view('privacy.breach-form', ['breach' => $breach]);
    }

    public function update(Request $request, DataBreach $breach)
    {
        abort_if($breach->status === 'closed', 403);
        $breach->update($this->payload($request));
        ActivityLogger::log('privacy', 'breach', "Breach {$breach->ref} updated", $breach);

        return redirect()->route('privacy.breaches.show', $breach)->with('success', 'Breach record updated.');
    }

    public function contain(Request $request, DataBreach $breach)
    {
        $data = $request->validate(['containment' => 'required|string|min:10|max:3000', 'contained_at' => 'required|date|before_or_equal:now|after_or_equal:'.$breach->detected_at->toDateTimeString()]);
        abort_unless($breach->status === 'open', 403);
        $breach->update($data + ['status' => 'contained']);
        ActivityLogger::log('privacy', 'breach', "Breach {$breach->ref} contained", $breach);

        return back()->with('success', 'Containment recorded.');
    }

    public function notify(Request $request, DataBreach $breach)
    {
        $data = $request->validate(['authority_notified_at' => 'required|date|before_or_equal:now|after_or_equal:'.$breach->detected_at->toDateTimeString(), 'authority_ref' => 'nullable|string|max:80']);
        abort_if($breach->status === 'closed' || ! $breach->notifiable || $breach->authority_notified_at, 403);
        $late = \Illuminate\Support\Carbon::parse($data['authority_notified_at'])->gt($breach->deadline());
        $breach->update($data + ['status' => 'notified']);
        ActivityLogger::log('privacy', 'breach', "Breach {$breach->ref}: authority notified".($late ? ' (after the deadline)' : ''), $breach);

        return back()->with($late ? 'warning' : 'success', $late ? 'Notification recorded – it was after the '.PrivacyService::settings()['breach_notify_hours'].'-hour deadline; record the reasons for the delay in the root-cause notes.' : 'Authority notification recorded.');
    }

    public function subjects(Request $request, DataBreach $breach)
    {
        $data = $request->validate(['subjects_notified_at' => 'required|date|before_or_equal:now', 'subjects_notice' => 'required|string|min:5|max:255']);
        abort_if($breach->status === 'closed', 403);
        $breach->update($data);
        ActivityLogger::log('privacy', 'breach', "Breach {$breach->ref}: affected people informed", $breach);

        return back()->with('success', 'Notification of affected people recorded.');
    }

    public function close(Request $request, DataBreach $breach)
    {
        $data = $request->validate(['root_cause' => 'required|string|min:10|max:3000', 'lessons' => 'required|string|min:10|max:3000']);
        abort_if($breach->status === 'closed', 403);
        if ($breach->status === 'open') {
            return back()->withErrors(['root_cause' => 'Record the containment before closing the breach.']);
        }
        if ($breach->notifiable && ! $breach->authority_notified_at) {
            return back()->withErrors(['root_cause' => 'This breach is notifiable – record the notification to the Data Protection Commission before closing it.']);
        }
        $breach->update($data + ['status' => 'closed', 'closed_at' => now()]);
        ActivityLogger::log('privacy', 'breach', "Breach {$breach->ref} closed", $breach);

        return back()->with('success', "Breach {$breach->ref} closed.");
    }
}
