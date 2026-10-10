<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\MarketingCampaign;
use App\Models\MarketingJourney;
use App\Models\MarketingMessage;
use App\Models\Reservation;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\CrmService;
use Illuminate\Http\Request;

/**
 * v2.6 marketing dashboard & settings.
 */
class CrmController extends Controller
{
    public function index()
    {
        $since = now()->subDays(30);
        $guests = Guest::where('is_blacklisted', false);
        $total = (clone $guests)->count();
        $consent = ['email' => (clone $guests)->where('email_opt_in', true)->count(), 'sms' => (clone $guests)->where('sms_opt_in', true)->count(),
            'whatsapp' => (clone $guests)->where('whatsapp_opt_in', true)->count(),
            'any' => (clone $guests)->where(fn ($q) => $q->where('email_opt_in', true)->orWhere('sms_opt_in', true)->orWhere('whatsapp_opt_in', true))->count()];
        $msgs = MarketingMessage::where('created_at', '>=', $since);
        $sent = (clone $msgs)->where('status', 'sent')->count();
        $clicks = (clone $msgs)->whereNotNull('clicked_at')->count();
        $value = CrmService::bookingValue(Reservation::whereNotNull('attribution')->where('created_at', '>=', $since));
        $bd = bdate()->copy();
        $birthdays = Guest::whereNotNull('dob')->get(['id', 'title', 'first_name', 'last_name', 'dob', 'email_opt_in', 'sms_opt_in', 'whatsapp_opt_in', 'is_vip'])
            ->map(function ($g) use ($bd) {
                $next = $g->dob->copy()->year($bd->year);
                if ($next->lt($bd)) {
                    $next->addYear();
                }
                $g->next_birthday = $next;

                return $g;
            })->filter(fn ($g) => $g->next_birthday->lte($bd->copy()->addDays(14)))->sortBy('next_birthday')->values();
        $mix = Guest::where('stays_count', '>', 0)->get(['id', 'stays_count', 'last_stay_on', 'total_revenue'])
            ->map(fn ($g) => CrmService::rfm($g)['label'])->countBy()->sortDesc();

        return view('crm.index', [
            'total' => $total, 'consent' => $consent, 'sent' => $sent, 'clicks' => $clicks, 'ctr' => $sent ? round($clicks / $sent * 100, 1) : 0,
            'unsubs' => (clone $msgs)->whereNotNull('unsubscribed_at')->count(), 'value' => $value,
            'campaigns' => MarketingCampaign::with('segment')->latest('id')->limit(6)->get(),
            'journeys' => MarketingJourney::withCount(['messages as sent_30' => fn ($q) => $q->where('status', 'sent')->where('created_at', '>=', $since)])->orderByDesc('is_active')->orderBy('name')->get(),
            'bookings' => Reservation::with('guest', 'campaign', 'journey', 'promo')->whereNotNull('attribution')->latest('id')->limit(8)->get(),
            'birthdays' => $birthdays, 'mix' => $mix,
            'daily' => MarketingMessage::where('status', 'sent')->where('created_at', '>=', now()->subDays(13)->startOfDay())
                ->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd'),
        ]);
    }

    public function settings()
    {
        $s = collect(CrmService::DEFAULTS)->map(fn ($v, $k) => setting($k, $v));

        return view('crm.settings', ['s' => $s]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'crm_frequency_cap' => 'required|integer|min:0|max:14', 'crm_attribution_days' => 'required|integer|min:0|max:180',
            'crm_sms_optout' => ['required', 'string', 'max:120', function ($a, $v, $fail) {
                if (! str_contains($v, '{unsubscribe_link}')) {
                    $fail('The SMS opt-out text must contain {unsubscribe_link}.');
                }
            }],
            'crm_consent_text' => 'required|string|max:300',
        ]);
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('crm', 'settings', 'Updated marketing settings');

        return back()->with('success', 'Marketing settings saved.');
    }

    public function refreshStats()
    {
        $n = CrmService::refreshStats();

        return back()->with('success', "Guest statistics recalculated for {$n} guest(s).");
    }
}
