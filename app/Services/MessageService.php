<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\MessageLog;
use App\Models\Reservation;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Guest SMS / WhatsApp. Drivers: log (default – writes to the application log), twilio, hubtel, http (generic JSON gateway).
 * send() never throws: every attempt, successful or not, lands in message_logs.
 */
class MessageService
{
    public const DRIVERS = ['log' => 'Log only (testing – nothing is sent)', 'twilio' => 'Twilio (SMS + WhatsApp)', 'hubtel' => 'Hubtel SMS (Ghana)', 'http' => 'Generic HTTP gateway (JSON POST)'];

    public const SECRETS = ['sms_twilio_token', 'sms_hubtel_client_secret', 'sms_http_token'];

    public const TEMPLATES = [
        'confirmation' => ['Booking confirmation', 'Dear {guest}, your booking {res_no} at {hotel} is confirmed for {arrival} to {departure}. We look forward to welcoming you.'],
        'pre_arrival' => ['Pre-arrival / online check-in', 'Dear {guest}, we look forward to seeing you at {hotel} on {arrival}. Save time at the desk – check in online: {checkin_link}'],
        'thank_you' => ['Thank-you after check-out', 'Dear {guest}, thank you for staying at {hotel}. Your final bill was {amount}. We hope to see you again soon!'],
    ];

    public static function secret(string $key): ?string
    {
        $v = setting($key);
        if (! $v) {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (Throwable) {
            return null;
        }
    }

    public static function storeSecret(string $key, ?string $plain): void
    {
        if ($plain === null || $plain === '') {
            return; // blank keeps the existing secret
        }
        Setting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($plain)]);
    }

    public static function template(string $key): string
    {
        return (string) (setting('sms_tpl_'.$key) ?: (self::TEMPLATES[$key][1] ?? ''));
    }

    /** E.164 normalisation: "024 412 3456" → "+233244123456" with the default country code. */
    public static function normalise(?string $number): ?string
    {
        $n = preg_replace('/[^\d+]/', '', (string) $number);
        if (str_starts_with($n, '00')) {
            $n = '+'.substr($n, 2);
        }
        if ($n !== '' && ! str_starts_with($n, '+')) {
            $cc = preg_replace('/\D/', '', (string) setting('sms_default_country_code', '+233'));
            $n = '+'.$cc.ltrim($n, '0');
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $n) ? $n : null;
    }

    public static function render(string $body, array $vars): string
    {
        $out = strtr($body, collect($vars)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all());

        return trim(preg_replace('/\{[a-z_]+\}/', '', $out));
    }

    public static function varsFor(?Reservation $r, ?Folio $folio = null): array
    {
        $hotel = hotel();
        $guest = $r?->guest ?? $folio?->guest;

        return [
            'guest' => $guest ? trim($guest->first_name.' '.$guest->last_name) : 'Guest', 'hotel' => $hotel?->name,
            'res_no' => $r?->reservation_no, 'arrival' => $r?->arrival_date?->format('d M Y'), 'departure' => $r?->departure_date?->format('d M Y'),
            'checkin_link' => $r && $r->onlineCheckinOpen() ? $r->checkinUrl() : '', 'amount' => $folio ? money(($t = $folio->totals())['charges'] - $t['discount']) : '',
        ];
    }

    public static function send(?string $to, string $body, string $channel = 'sms', string $template = 'custom', ?Model $related = null): MessageLog
    {
        $driver = array_key_exists((string) setting('sms_driver'), self::DRIVERS) ? setting('sms_driver') : 'log';
        $log = new MessageLog;
        $log->forceFill(['hotel_id' => hotel()->id, 'channel' => $channel === 'whatsapp' ? 'whatsapp' : 'sms', 'driver' => $driver, 'to' => (string) $to,
            'template' => $template, 'body' => mb_substr($body, 0, 1000), 'user_id' => auth()->id(),
            'related_type' => $related ? class_basename($related) : null, 'related_id' => $related?->getKey()]);
        try {
            $num = self::normalise($to);
            if (! $num) {
                throw new \InvalidArgumentException('No valid mobile number.');
            }
            $log->to = $num;
            if (trim($body) === '') {
                throw new \InvalidArgumentException('Message is empty.');
            }
            if (setting('sms_enabled', '0') !== '1' && $driver !== 'log') {
                throw new \InvalidArgumentException('Messaging is switched off in Messaging settings.');
            }
            if ($log->channel === 'whatsapp' && ! in_array($driver, ['twilio', 'http', 'log'])) {
                throw new \InvalidArgumentException('WhatsApp needs the Twilio or HTTP driver.');
            }
            $log->provider_id = match ($driver) {
                'twilio' => self::twilio($num, $body, $log->channel),
                'hubtel' => self::hubtel($num, $body),
                'http' => self::http($num, $body, $log->channel),
                default => self::logOnly($num, $body, $log->channel),
            };
            $log->status = 'sent';
        } catch (Throwable $e) {
            $log->status = 'failed';
            $log->error = mb_substr($e->getMessage(), 0, 500);
            if (! $e instanceof \InvalidArgumentException) {
                report($e);
            }
        }
        $log->save();

        return $log;
    }

    public static function sendTemplate(string $key, Reservation $r, string $channel = 'sms', ?Folio $folio = null, ?string $to = null): MessageLog
    {
        $r->loadMissing('guest');

        return self::send($to ?? $r->guest?->phone, self::render(self::template($key), self::varsFor($r, $folio)), $channel, $key, $r);
    }

    public static function defaultChannel(): string
    {
        return setting('sms_default_channel', 'sms') === 'whatsapp' ? 'whatsapp' : 'sms';
    }

    /** Hooks – only fire when the feature is on and the guest has a phone number. */
    public static function autoConfirm(Reservation $r): void
    {
        if (setting('sms_enabled', '0') === '1' && setting('sms_auto_confirmation', '0') === '1' && in_array($r->status, ['confirmed', 'tentative']) && $r->guest?->phone) {
            self::sendTemplate('confirmation', $r, self::defaultChannel());
        }
    }

    public static function autoThankYou(Reservation $r, ?Folio $folio = null): void
    {
        if (setting('sms_enabled', '0') === '1' && setting('sms_auto_thankyou', '0') === '1' && $r->guest?->phone) {
            self::sendTemplate('thank_you', $r, self::defaultChannel(), $folio);
        }
    }

    // ── drivers ───────────────────────────────────────────────────────────

    protected static function logOnly(string $to, string $body, string $channel): string
    {
        Log::info("[{$channel}] to {$to}: {$body}");

        return 'log-'.substr(md5($to.$body.microtime()), 0, 12);
    }

    protected static function twilio(string $to, string $body, string $channel): string
    {
        $sid = (string) setting('sms_twilio_sid');
        $token = self::secret('sms_twilio_token');
        $from = $channel === 'whatsapp' ? (string) setting('sms_twilio_wa_from') : (string) setting('sms_twilio_from');
        if (! $sid || ! $token || ! $from) {
            throw new \InvalidArgumentException('Twilio is not fully configured (SID, token, sender).');
        }
        $pfx = $channel === 'whatsapp' ? 'whatsapp:' : '';
        $res = Http::asForm()->timeout(15)->withBasicAuth($sid, $token)
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json', ['To' => $pfx.$to, 'From' => $pfx.$from, 'Body' => $body]);
        if (! $res->successful()) {
            throw new \RuntimeException('Twilio: '.($res->json('message') ?? 'HTTP '.$res->status()));
        }

        return (string) $res->json('sid');
    }

    protected static function hubtel(string $to, string $body): string
    {
        $id = (string) setting('sms_hubtel_client_id');
        $secret = self::secret('sms_hubtel_client_secret');
        $from = (string) setting('sms_sender', 'HOTEL');
        if (! $id || ! $secret) {
            throw new \InvalidArgumentException('Hubtel is not fully configured (client ID / secret).');
        }
        $res = Http::timeout(15)->get('https://smsc.hubtel.com/v1/messages/send', [
            'clientid' => $id, 'clientsecret' => $secret, 'from' => mb_substr($from, 0, 11), 'to' => ltrim($to, '+'), 'content' => $body,
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Hubtel: '.($res->json('message') ?? $res->json('statusDescription') ?? 'HTTP '.$res->status()));
        }

        return (string) ($res->json('messageId') ?? $res->json('MessageId') ?? '');
    }

    protected static function http(string $to, string $body, string $channel): string
    {
        $url = (string) setting('sms_http_url');
        if (! $url) {
            throw new \InvalidArgumentException('Gateway URL is not set.');
        }
        IcalService::assertSafeUrl($url);
        $req = Http::timeout(15)->acceptJson();
        if ($token = self::secret('sms_http_token')) {
            $req = $req->withToken($token);
        }
        $res = $req->post($url, ['to' => $to, 'from' => setting('sms_sender'), 'message' => $body, 'channel' => $channel]);
        if (! $res->successful()) {
            throw new \RuntimeException('Gateway: HTTP '.$res->status());
        }

        return (string) ($res->json('id') ?? $res->json('message_id') ?? '');
    }
}
