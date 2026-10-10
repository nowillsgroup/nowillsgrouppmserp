<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\MarketingJourney;
use App\Models\MarketingSegment;
use App\Models\PromoCode;
use App\Models\Reservation;
use App\Services\ActivityLogger;
use App\Services\CrmService;
use Illuminate\Http\Request;

class JourneyController extends Controller
{
    public function index()
    {
        $journeys = MarketingJourney::with('segment', 'promo')->orderByDesc('is_active')->orderBy('name')->get()
            ->each(function ($j) {
                $j->stats = CrmService::stats($j);
                $j->upcoming = $j->is_active ? CrmService::journeyCandidates($j, bdate()->copy()->addDay())->count() : null;
            });

        return view('crm.journeys.index', compact('journeys'));
    }

    protected function options(): array
    {
        return ['segments' => MarketingSegment::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'promos' => PromoCode::where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => $p->code.' – '.$p->offerLabel()]),
            'sample' => Guest::where('stays_count', '>', 0)->orderByDesc('total_revenue')->first() ?? Guest::first()];
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150', 'trigger' => 'required|in:'.implode(',', array_keys(MarketingJourney::TRIGGERS)),
            'offset_days' => 'required|integer|min:0|max:730', 'channel' => 'required|in:email,sms,whatsapp',
            'segment_id' => 'nullable|integer|owned:marketing_segments', 'promo_code_id' => 'nullable|integer|owned:promo_codes',
            'subject' => 'nullable|required_if:channel,email|string|max:200', 'body' => ['required', 'string', $request->channel === 'email' ? 'max:6000' : 'max:900'],
            'cta_label' => 'nullable|string|max:60',
        ], ['subject.required_if' => 'An e-mail needs a subject.']);
        if ($data['trigger'] === 'win_back' && $data['offset_days'] < 30) {
            $data['offset_days'] = 30; // a "lapsed" guest is at least a month past the last stay
        }
        if ($data['channel'] !== 'email') {
            $data['subject'] = null;
        }

        return $data;
    }

    public function create(Request $request)
    {
        $trigger = array_key_exists((string) $request->trigger, MarketingJourney::TRIGGERS) ? $request->trigger : 'birthday';

        return view('crm.journeys.form', ['journey' => new MarketingJourney(['trigger' => $trigger, 'offset_days' => MarketingJourney::TRIGGERS[$trigger][2],
            'channel' => 'email', 'body' => "Dear {first_name},\n\n…\n\nWarm regards,\n{hotel}"])] + $this->options());
    }

    public function store(Request $request)
    {
        $j = MarketingJourney::create($this->validated($request) + ['is_active' => false, 'created_by' => auth()->id()]);
        ActivityLogger::log('crm', 'create', "Created automation \"{$j->name}\"", $j);

        return redirect()->route('crm.journeys.show', $j)->with('success', 'Automation saved (switched off). Send yourself a test, then switch it on.');
    }

    public function show(Request $request, MarketingJourney $journey)
    {
        $journey->load('segment', 'promo');
        $next = CrmService::journeyCandidates($journey, bdate()->copy())->merge(CrmService::journeyCandidates($journey, bdate()->copy()->addDay()));
        $sample = $next->first()[0] ?? $this->options()['sample'];
        $ctx = ['promo' => $journey->promo, 'reservation' => $next->first()[2] ?? Reservation::with('rooms.roomType')->where('guest_id', $sample?->id)->latest('arrival_date')->first()];

        return view('crm.journeys.show', ['j' => $journey, 'stats' => CrmService::stats($journey), 'next' => $next,
            'preview' => $sample ? CrmService::compose($sample, $journey->channel, $journey->subject, $journey->body, $journey->cta_label, $ctx) : null,
            'messages' => $journey->messages()->with('guest', 'reservation')->when(in_array($request->status, ['sent', 'failed', 'skipped']), fn ($q) => $q->where('status', $request->status))
                ->latest('id')->paginate(25)->withQueryString()]);
    }

    public function edit(MarketingJourney $journey)
    {
        return view('crm.journeys.form', ['journey' => $journey] + $this->options());
    }

    public function update(Request $request, MarketingJourney $journey)
    {
        $journey->update($this->validated($request));
        ActivityLogger::log('crm', 'update', "Updated automation \"{$journey->name}\"", $journey);

        return redirect()->route('crm.journeys.show', $journey)->with('success', 'Automation updated.');
    }

    public function toggle(MarketingJourney $journey)
    {
        $journey->update(['is_active' => ! $journey->is_active]);
        ActivityLogger::log('crm', 'update', ($journey->is_active ? 'Switched on' : 'Switched off')." automation \"{$journey->name}\"", $journey);

        return back()->with('success', 'Automation '.($journey->is_active ? 'switched on – it runs every morning and after the night audit.' : 'switched off.'));
    }

    public function run(MarketingJourney $journey)
    {
        if (! $journey->is_active) {
            return back()->with('error', 'Switch the automation on first.');
        }
        $n = CrmService::runJourney($journey);

        return back()->with('success', "Automation ran for ".bdate()->format('d M Y').": {$n} message(s) sent. Guests already contacted for this occasion are never messaged twice.");
    }

    public function test(Request $request, MarketingJourney $journey)
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:150', $journey->channel === 'email' ? 'email' : 'regex:/^[+\d][\d\s()-]{6,20}$/']]);
        $ok = CrmService::sendTest($journey->channel, $journey->subject, $journey->body, $journey->cta_label, $journey->promo, $data['to'], $this->options()['sample']);

        return back()->with($ok ? 'success' : 'error', $ok ? "Test sent to {$data['to']}." : 'The test could not be sent – see the Messaging / E-mail logs.');
    }
}
