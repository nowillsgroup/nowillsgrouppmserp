<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Models\Reservation;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\MessageService;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public const PLAIN = ['sms_enabled', 'sms_driver', 'sms_default_channel', 'sms_sender', 'sms_default_country_code', 'sms_twilio_sid', 'sms_twilio_from', 'sms_twilio_wa_from',
        'sms_hubtel_client_id', 'sms_http_url', 'sms_auto_confirmation', 'sms_auto_thankyou', 'sms_pre_arrival_days', 'sms_tpl_confirmation', 'sms_tpl_pre_arrival', 'sms_tpl_thank_you'];

    public function index(Request $request)
    {
        $logs = MessageLog::with('user')->latest('id')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('to', 'like', "%$t%")->orWhere('body', 'like', "%$t%")))
            ->paginate(30)->withQueryString();

        return view('messages.index', ['logs' => $logs]);
    }

    public function settings()
    {
        $v = Setting::whereIn('key', array_merge(self::PLAIN, MessageService::SECRETS))->pluck('value', 'key');

        return view('messages.settings', ['v' => $v, 'secretSet' => collect(MessageService::SECRETS)->mapWithKeys(fn ($k) => [$k => ! empty($v[$k])])]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'sms_driver' => 'required|in:'.implode(',', array_keys(MessageService::DRIVERS)), 'sms_default_channel' => 'required|in:sms,whatsapp',
            'sms_sender' => 'nullable|string|max:20', 'sms_default_country_code' => ['required', 'regex:/^\+?[1-9]\d{0,3}$/'],
            'sms_twilio_sid' => 'nullable|string|max:64', 'sms_twilio_from' => 'nullable|string|max:20', 'sms_twilio_wa_from' => 'nullable|string|max:20',
            'sms_hubtel_client_id' => 'nullable|string|max:64', 'sms_http_url' => 'nullable|url|max:255', 'sms_pre_arrival_days' => 'nullable|integer|min:0|max:30',
            'sms_tpl_confirmation' => 'nullable|string|max:600', 'sms_tpl_pre_arrival' => 'nullable|string|max:600', 'sms_tpl_thank_you' => 'nullable|string|max:600',
            'sms_twilio_token' => 'nullable|string|max:200', 'sms_hubtel_client_secret' => 'nullable|string|max:200', 'sms_http_token' => 'nullable|string|max:500',
            'clear_secrets' => 'nullable|array',
        ]);
        foreach (self::PLAIN as $k) {
            $val = in_array($k, ['sms_enabled', 'sms_auto_confirmation', 'sms_auto_thankyou']) ? ($request->boolean($k) ? '1' : '0') : ($data[$k] ?? null);
            if ($k === 'sms_default_country_code') {
                $val = '+'.ltrim($val, '+');
            }
            Setting::updateOrCreate(['key' => $k], ['value' => $val]);
        }
        foreach (MessageService::SECRETS as $k) {
            if (in_array($k, $data['clear_secrets'] ?? [])) {
                Setting::where('key', $k)->delete();
            } else {
                MessageService::storeSecret($k, $data[$k] ?? null);
            }
        }
        Setting::$version++;
        ActivityLogger::log('settings', 'messaging', 'Updated SMS / WhatsApp settings (driver '.$data['sms_driver'].')');

        return back()->with('success', 'Messaging settings saved.');
    }

    public function test(Request $request)
    {
        $data = $request->validate(['to' => 'required|string|max:30', 'channel' => 'required|in:sms,whatsapp']);
        $log = MessageService::send($data['to'], 'Test message from '.hotel()->name.' – Nowills PMS messaging is working.', $data['channel'], 'test');

        return back()->with($log->status === 'sent' ? 'success' : 'error', $log->status === 'sent' ? "Test sent to {$log->to} via {$log->driver}." : "Test failed: {$log->error}");
    }

    public function sendForReservation(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['template' => 'required|in:confirmation,pre_arrival,thank_you,custom', 'channel' => 'required|in:sms,whatsapp',
            'to' => 'nullable|string|max:30', 'body' => 'nullable|required_if:template,custom|string|max:600']);
        $reservation->loadMissing('guest');
        $log = $data['template'] === 'custom'
            ? MessageService::send(($data['to'] ?? null) ?: $reservation->guest?->phone, MessageService::render($data['body'], MessageService::varsFor($reservation)), $data['channel'], 'custom', $reservation)
            : MessageService::sendTemplate($data['template'], $reservation, $data['channel'], $reservation->folios()->latest('id')->first(), ($data['to'] ?? null) ?: null);
        ActivityLogger::log('messages', 'send', strtoupper($log->channel)." {$log->template} to {$log->to}: {$log->status}", $reservation);

        return back()->with($log->status === 'sent' ? 'success' : 'error', $log->status === 'sent' ? ucfirst($log->channel)." sent to {$log->to}." : "Message not sent: {$log->error}");
    }
}
