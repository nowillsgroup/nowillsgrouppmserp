<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\MarketingCampaign;
use App\Models\MarketingSegment;
use App\Models\PromoCode;
use App\Models\Reservation;
use App\Services\ActivityLogger;
use App\Services\CrmService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    public const TABS = ['all' => 'All', 'draft' => 'Drafts', 'scheduled' => 'Scheduled', 'sent' => 'Sent', 'cancelled' => 'Cancelled'];

    /** Above this many recipients "Send now" hands the job to the scheduler instead of holding the browser. */
    public const SYNC_LIMIT = 500;

    public function index(Request $request)
    {
        $tab = array_key_exists($request->show, self::TABS) ? $request->show : 'all';
        $campaigns = MarketingCampaign::with('segment', 'promo', 'creator')
            ->when($tab === 'scheduled', fn ($q) => $q->whereIn('status', ['scheduled', 'sending']))
            ->when(! in_array($tab, ['all', 'scheduled']), fn ($q) => $q->where('status', $tab))
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->q, fn ($q, $t) => $q->where('name', 'like', "%$t%"))
            ->orderByRaw("FIELD(status, 'sending', 'scheduled', 'draft', 'sent', 'cancelled')")->latest('id')->paginate(20)->withQueryString();
        $campaigns->getCollection()->each(fn ($c) => $c->stats = in_array($c->status, ['sent', 'sending']) ? CrmService::stats($c) : null);
        $sent = MarketingCampaign::where('status', 'sent')->where('sent_at', '>=', now()->subDays(90))->get();
        $totals = $sent->map(fn ($c) => CrmService::stats($c));

        return view('crm.campaigns.index', ['campaigns' => $campaigns, 'tab' => $tab, 'summary' => [
            'count' => $sent->count(), 'sent' => $totals->sum('sent'), 'clicks' => $totals->sum('clicks'), 'bookings' => $totals->sum('bookings'),
            'revenue' => $totals->sum('revenue'), 'scheduled' => MarketingCampaign::whereIn('status', ['scheduled', 'sending'])->count(),
            'drafts' => MarketingCampaign::where('status', 'draft')->count(),
        ]]);
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
            'name' => 'required|string|max:150', 'channel' => 'required|in:email,sms,whatsapp',
            'segment_id' => 'nullable|integer|owned:marketing_segments', 'promo_code_id' => 'nullable|integer|owned:promo_codes',
            'subject' => 'nullable|required_if:channel,email|string|max:200',
            'body' => ['required', 'string', $request->channel === 'email' ? 'max:6000' : 'max:900'],
            'cta_label' => 'nullable|string|max:60',
        ], ['subject.required_if' => 'An e-mail campaign needs a subject.', 'body.max' => 'SMS / WhatsApp messages are limited to :max characters.']);
        if ($data['channel'] !== 'email') {
            $data['subject'] = null;
        }

        return $data;
    }

    public function create(Request $request)
    {
        $c = new MarketingCampaign(['channel' => 'email', 'segment_id' => $request->segment, 'cta_label' => 'Book your stay',
            'body' => "Dear {first_name},\n\nThank you for choosing {hotel}. As one of our valued guests we would love to welcome you back.\n\nWarm regards,\n{hotel}"]);

        return view('crm.campaigns.form', ['campaign' => $c] + $this->options());
    }

    public function store(Request $request)
    {
        $c = MarketingCampaign::create($this->validated($request) + ['status' => 'draft', 'created_by' => auth()->id()]);
        ActivityLogger::log('crm', 'create', "Created campaign \"{$c->name}\" ({$c->channel})", $c);

        return redirect()->route('crm.campaigns.show', $c)->with('success', 'Campaign draft saved – check the preview and send yourself a test.');
    }

    public function show(Request $request, MarketingCampaign $campaign)
    {
        $campaign->load('segment', 'promo', 'creator', 'sender');
        $breakdown = $campaign->isEditable() ? CrmService::audienceBreakdown($campaign) : null;
        $sample = $breakdown['sample'] ?? $campaign->messages()->with('guest')->where('status', 'sent')->first()?->guest ?? $this->options()['sample'];
        $preview = $sample ? CrmService::compose($sample, $campaign->channel, $campaign->subject, $campaign->body, $campaign->cta_label, ['promo' => $campaign->promo]) : null;
        $recipients = $campaign->messages()->with('guest', 'reservation')
            ->when(in_array($request->status, ['sent', 'failed', 'skipped']), fn ($q) => $q->where('status', $request->status))
            ->when($request->status === 'clicked', fn ($q) => $q->whereNotNull('clicked_at'))
            ->when($request->status === 'booked', fn ($q) => $q->whereNotNull('reservation_id'))
            ->latest('id')->paginate(25)->withQueryString();

        return view('crm.campaigns.show', ['c' => $campaign, 'stats' => CrmService::stats($campaign), 'breakdown' => $breakdown, 'preview' => $preview, 'sample' => $sample,
            'recipients' => $recipients, 'bookings' => Reservation::with('guest', 'rooms.roomType')->where('campaign_id', $campaign->id)->latest('id')->limit(20)->get(),
            'skipReasons' => $campaign->messages()->where('status', 'skipped')->groupBy('skip_reason')->selectRaw('skip_reason, count(*) n')->pluck('n', 'skip_reason')]);
    }

    public function edit(MarketingCampaign $campaign)
    {
        if (! $campaign->isEditable()) {
            return redirect()->route('crm.campaigns.show', $campaign)->with('error', 'Only draft or scheduled campaigns can be edited.');
        }

        return view('crm.campaigns.form', ['campaign' => $campaign] + $this->options());
    }

    public function update(Request $request, MarketingCampaign $campaign)
    {
        abort_unless($campaign->isEditable(), 422, 'Only draft or scheduled campaigns can be edited.');
        $campaign->update($this->validated($request));
        ActivityLogger::log('crm', 'update', "Updated campaign \"{$campaign->name}\"", $campaign);

        return redirect()->route('crm.campaigns.show', $campaign)->with('success', 'Campaign updated.');
    }

    public function test(Request $request, MarketingCampaign $campaign)
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:150', $campaign->channel === 'email' ? 'email' : 'regex:/^[+\d][\d\s()-]{6,20}$/']],
            ['to.regex' => 'Enter a mobile number.']);
        $sample = CrmService::audienceBreakdown($campaign)['sample'] ?? null;
        $ok = CrmService::sendTest($campaign->channel, $campaign->subject, $campaign->body, $campaign->cta_label, $campaign->promo, $data['to'], $sample);
        ActivityLogger::log('crm', 'test', "Test of campaign \"{$campaign->name}\" sent to {$data['to']}", $campaign);

        return back()->with($ok ? 'success' : 'error', $ok ? "Test sent to {$data['to']}." : 'The test could not be sent – see Messaging / E-mail logs.');
    }

    public function send(MarketingCampaign $campaign)
    {
        $size = CrmService::audience($campaign)->count();
        if ($size > self::SYNC_LIMIT) {
            CrmService::schedule($campaign, now());

            return back()->with('success', "{$size} recipients – the campaign is queued and goes out on the next scheduler run (within 5 minutes).");
        }
        $c = CrmService::sendCampaign($campaign, auth()->id());

        return redirect()->route('crm.campaigns.show', $c)->with('success', "Campaign sent: {$c->sent_count} delivered, {$c->skipped_count} skipped, {$c->failed_count} failed.");
    }

    public function schedule(Request $request, MarketingCampaign $campaign)
    {
        $data = $request->validate(['scheduled_at' => 'required|date']);
        CrmService::schedule($campaign, Carbon::parse($data['scheduled_at']));
        $campaign->update(['sent_by' => auth()->id()]);

        return back()->with('success', 'Campaign scheduled for '.$campaign->scheduled_at->format('D d M Y H:i').'.');
    }

    public function unschedule(MarketingCampaign $campaign)
    {
        if ($campaign->status !== 'scheduled') {
            throw ValidationException::withMessages(['campaign' => 'Only a scheduled campaign can be put back to draft.']);
        }
        $campaign->update(['status' => 'draft', 'scheduled_at' => null]);
        ActivityLogger::log('crm', 'unschedule', "Unscheduled campaign \"{$campaign->name}\"", $campaign);

        return back()->with('success', 'Campaign moved back to draft.');
    }

    public function cancel(MarketingCampaign $campaign)
    {
        if (! $campaign->isEditable()) {
            throw ValidationException::withMessages(['campaign' => 'This campaign is '.$campaign->status.' and cannot be cancelled.']);
        }
        $campaign->update(['status' => 'cancelled']);
        ActivityLogger::log('crm', 'cancel', "Cancelled campaign \"{$campaign->name}\"", $campaign);

        return back()->with('success', 'Campaign cancelled.');
    }

    public function duplicate(MarketingCampaign $campaign)
    {
        $copy = MarketingCampaign::create($campaign->only(['channel', 'segment_id', 'subject', 'body', 'cta_label', 'promo_code_id'])
            + ['name' => mb_substr($campaign->name.' (copy)', 0, 150), 'status' => 'draft', 'created_by' => auth()->id()]);
        ActivityLogger::log('crm', 'create', "Duplicated campaign \"{$campaign->name}\"", $copy);

        return redirect()->route('crm.campaigns.edit', $copy)->with('success', 'Copy created as a draft.');
    }
}
