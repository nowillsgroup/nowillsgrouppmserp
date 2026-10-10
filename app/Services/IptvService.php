<?php

namespace App\Services;

use App\Models\BanquetFunction;
use App\Models\ConciergePlace;
use App\Models\Floor;
use App\Models\Folio;
use App\Models\GuestFeedback;
use App\Models\GuestMessage;
use App\Models\GuestRequest;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Setting;
use App\Models\TvChannel;
use App\Models\TvCommand;
use App\Models\TvDevice;
use App\Models\TvEvent;
use App\Models\TvMessage;
use App\Models\TvMessageRead;
use App\Models\TvMovie;
use App\Models\TvPage;
use App\Models\TvProgramme;
use App\Models\TvPurchase;
use App\Models\TvSession;
use App\Models\TvWelcomeTemplate;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use App\Support\TvLang;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v7.1 IPTV & guest welcome.
 *  - Hotel TVs (Samsung / LG hotel mode, Android TV, Philips, set-top boxes) load an HTML5 portal from a URL. Each TV has
 *    a secret 40-character token in that URL; a new TV opens the hotel's setup page, shows a 6-digit code and staff pair it
 *    to a room (or a public location for signage). The TV polls a heartbeat that carries its state and queued commands.
 *  - The PMS drives the screen: the in-house stay of the TV's room gives a personalised welcome (template by language and
 *    guest segment, name shown per the privacy setting), the guest's language (chosen on the TV, remembered on the guest,
 *    else inferred from nationality), messages and broadcasts with read receipts, and a full-screen emergency alert on
 *    every screen. Check-out ends the TV session and resets the set; a room move carries the guest to the new room.
 *  - Guest services reuse the portal: in-room dining (POS order for confirmation), housekeeping / amenity / maintenance
 *    requests, wake-up calls, the bill and express check-out, feedback. Pay movies post to the folio (charge category
 *    "pay_tv", VAT + levies) with an optional 4-digit PIN per stay, a daily limit and staff refunds (void).
 *  - Channel line-up with M3U import / export and an XMLTV programme guide; lobby screens show today's events.
 */
class IptvService
{
    public const DEFAULTS = [
        'tv_enabled' => '1', 'tv_name_mode' => 'title_last', 'tv_default_lang' => 'en', 'tv_langs' => 'en,fr,es,pt,de,zh',
        'tv_welcome_on' => '1', 'tv_reset_on_checkout' => '1', 'tv_heartbeat' => '20', 'tv_offline_min' => '5',
        'tv_vod_enabled' => '1', 'tv_pin_purchase' => '1', 'tv_pin_bill' => '0', 'tv_adult' => '0', 'tv_max_rentals' => '5',
        'tv_dining' => '1', 'tv_requests' => '1', 'tv_checkout' => '1', 'tv_feedback' => '1',
        'tv_weather' => '1', 'tv_lat' => '5.6037', 'tv_lon' => '-0.1870', 'tv_desk_ext' => '0',
        'tv_power_channel' => '', 'tv_volume_max' => '60', 'tv_event_days' => '180',
        'tv_signage_events' => '1', 'tv_signage_text' => 'Akwaaba! Welcome to our hotel. Ask the concierge about tours of Accra, Cape Coast and Kakum.',
    ];

    public const NAME_MODES = ['full' => 'Title and full name (Mr Kwame Mensah)', 'title_last' => 'Title and surname (Mr Mensah)', 'first' => 'First name (Kwame)', 'none' => 'No name (Welcome)'];

    /** request categories a guest can raise from the TV remote */
    public const TV_REQUESTS = ['housekeeping', 'amenity', 'maintenance', 'laundry'];

    public const PIN_TRIES = 5;

    public const PIN_LOCK_MIN = 15;

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= (function () {
            $s = fn ($k) => (string) setting($k, self::DEFAULTS[$k]);
            $b = fn ($k) => $s($k) === '1';
            $langs = array_values(array_intersect(array_keys(TvLang::LANGS), explode(',', $s('tv_langs')))) ?: ['en'];

            return [
                'enabled' => $b('tv_enabled'), 'name_mode' => isset(self::NAME_MODES[$s('tv_name_mode')]) ? $s('tv_name_mode') : 'title_last',
                'langs' => $langs, 'default_lang' => in_array($s('tv_default_lang'), $langs, true) ? $s('tv_default_lang') : $langs[0],
                'welcome' => $b('tv_welcome_on'), 'reset_on_checkout' => $b('tv_reset_on_checkout'),
                'heartbeat' => max(10, min(120, (int) $s('tv_heartbeat'))), 'offline_min' => max(2, min(240, (int) $s('tv_offline_min'))),
                'vod' => $b('tv_vod_enabled'), 'pin_purchase' => $b('tv_pin_purchase'), 'pin_bill' => $b('tv_pin_bill'), 'adult' => $b('tv_adult'),
                'max_rentals' => max(0, min(20, (int) $s('tv_max_rentals'))),
                'dining' => $b('tv_dining'), 'requests' => $b('tv_requests'), 'checkout' => $b('tv_checkout'), 'feedback' => $b('tv_feedback'),
                'weather' => $b('tv_weather'), 'lat' => (float) $s('tv_lat'), 'lon' => (float) $s('tv_lon'), 'desk_ext' => mb_substr($s('tv_desk_ext'), 0, 8),
                'power_channel' => (int) $s('tv_power_channel') ?: null, 'volume_max' => max(10, min(100, (int) $s('tv_volume_max'))),
                'event_days' => max(30, min(730, (int) $s('tv_event_days'))),
                'signage_events' => $b('tv_signage_events'), 'signage_text' => mb_substr($s('tv_signage_text'), 0, 300),
            ];
        })();
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        if (! TvWelcomeTemplate::exists()) {
            foreach (self::STARTER_TEMPLATES as [$name, $lang, $seg, $prio, $head, $body, $theme]) {
                TvWelcomeTemplate::create(['name' => $name, 'language' => $lang, 'segment' => $seg, 'priority' => $prio, 'headline' => $head, 'body' => $body, 'theme' => $theme, 'is_active' => true]);
            }
        }
        if (! TvPage::exists()) {
            foreach (self::STARTER_PAGES as $i => [$title, $cat, $icon, $body]) {
                TvPage::create(['title' => $title, 'category' => $cat, 'icon' => $icon, 'language' => 'en', 'body' => $body, 'sort' => $i + 1, 'is_active' => true]);
            }
        }
        Setting::$version++;
        self::flush();
    }

    public const STARTER_TEMPLATES = [
        ['Standard welcome', 'en', 'all', 0, 'Akwaaba, {name}!', "Welcome to {hotel}. Your room {room} is ready and we hope you enjoy your stay until {departure}.\nWi-Fi: {wifi_name} · password {wifi_password}\nPress OK on your remote to explore the hotel services.", 'gold'],
        ['Bienvenue (français)', 'fr', 'all', 0, 'Akwaaba, {name} !', "Bienvenue au {hotel}. Votre chambre {room} est prête ; nous vous souhaitons un excellent séjour jusqu'au {departure}.\nWi-Fi : {wifi_name} · mot de passe {wifi_password}", 'ocean'],
        ['VIP welcome', 'en', 'vip', 10, 'Welcome back to Accra, {name}', "It is a pleasure to host you again at {hotel}. Your butler will call shortly – for anything at all, press the Services button or dial {desk_ext}.", 'night'],
        ['Returning guest', 'en', 'repeat', 5, 'Great to see you again, {name}!', "Thank you for choosing {hotel} once more. Your room {room} has been prepared with your usual preferences.", 'forest'],
        ['Company guest', 'en', 'corporate', 3, 'Welcome, {name}', "We are delighted to welcome you on behalf of {company}. Express check-out and your bill are one click away on this TV.", 'ocean'],
        ['Happy birthday', 'en', 'birthday', 20, 'Happy birthday, {name}!', "The whole team at {hotel} wishes you a wonderful birthday. A small surprise is on its way to room {room}.", 'sunset'],
    ];

    public const STARTER_PAGES = [
        ['Restaurants & bars', 'dining', 'coffee', "Saffron Multi-Cuisine Restaurant – breakfast 06:30–10:30, lunch 12:00–15:00, dinner 18:30–22:30.\nSkyline Bar – 16:00 until late, with live highlife music on Fridays.\nIn-room dining is available 24 hours – order right here on your TV."],
        ['Spa & fitness', 'wellness', 'spa', "The spa is open daily 09:00–21:00. Try our signature shea-butter massage.\nThe fitness centre and pool are open 06:00–22:00 for in-house guests."],
        ['Wi-Fi & business centre', 'services', 'wifi', "Free high-speed Wi-Fi throughout the hotel – the network name and password are on your welcome screen.\nThe business centre on the ground floor offers printing and meeting pods."],
        ['Airport & transport', 'services', 'plane', "Kotoka International Airport is about 20 minutes away. Book an airport drop or a city taxi from the Services menu or at the concierge."],
        ['Safety & emergencies', 'safety', 'shield', "In an emergency dial the front desk. The fire exit plan is on the back of your room door.\nIf the alarm sounds, leave by the nearest stairway – do not use the lifts. Assembly point: front car park."],
    ];

    public static function saveSettings(array $d): void
    {
        $out = [];
        $langs = array_values(array_intersect(array_keys(TvLang::LANGS), (array) ($d['langs'] ?? [])));
        self::need($langs !== [], 'Choose at least one TV language.');
        self::need(in_array($d['default_lang'] ?? '', $langs, true), 'The default language must be one of the languages offered.');
        $out['tv_langs'] = implode(',', $langs);
        $out['tv_default_lang'] = $d['default_lang'];
        self::need(isset(self::NAME_MODES[$d['name_mode'] ?? '']), 'Choose how the guest name is shown.');
        $out['tv_name_mode'] = $d['name_mode'];
        $int = function (string $k, int $lo, int $hi, string $msg) use ($d) {
            $v = $d[$k] ?? null;
            self::need(is_numeric($v) && (int) $v >= $lo && (int) $v <= $hi, $msg);

            return (string) (int) $v;
        };
        $out['tv_heartbeat'] = $int('heartbeat', 10, 120, 'The heartbeat is every 10 to 120 seconds.');
        $out['tv_offline_min'] = $int('offline_min', 2, 240, 'A TV counts as offline after 2 to 240 minutes without a heartbeat.');
        $out['tv_max_rentals'] = $int('max_rentals', 0, 20, 'The daily movie limit is 0 to 20 per room.');
        $out['tv_volume_max'] = $int('volume_max', 10, 100, 'The volume limit is 10 to 100.');
        $out['tv_event_days'] = $int('event_days', 30, 730, 'Keep usage data for 30 to 730 days.');
        foreach (['lat' => [-90, 90], 'lon' => [-180, 180]] as $k => [$lo, $hi]) {
            self::need(is_numeric($d[$k] ?? null) && $d[$k] >= $lo && $d[$k] <= $hi, 'Enter the hotel location as latitude (-90 to 90) and longitude (-180 to 180).');
            $out['tv_'.$k] = (string) round((float) $d[$k], 4);
        }
        $pc = trim((string) ($d['power_channel'] ?? ''));
        self::need($pc === '' || TvChannel::where('number', (int) $pc)->exists(), 'The power-on channel must be a channel in the line-up.');
        $out['tv_power_channel'] = $pc === '' ? '' : (string) (int) $pc;
        $out['tv_desk_ext'] = mb_substr(trim((string) ($d['desk_ext'] ?? '')), 0, 8);
        $out['tv_signage_text'] = mb_substr(trim((string) ($d['signage_text'] ?? '')), 0, 300);
        foreach (['enabled', 'welcome_on', 'reset_on_checkout', 'vod_enabled', 'pin_purchase', 'pin_bill', 'adult', 'dining', 'requests', 'checkout', 'feedback', 'weather', 'signage_events'] as $k) {
            $out['tv_'.$k] = ! empty($d[$k]) ? '1' : '0';
        }
        foreach ($out as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('iptv', 'settings', 'IPTV & guest welcome settings updated');
    }

    // ───────────────────────────── permissions ─────────────────────────────

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('iptv.manage');
    }

    public static function canMessage(?User $u): bool
    {
        return (bool) ($u?->hasPermission('iptv.message') || $u?->hasPermission('iptv.manage'));
    }

    public static function canEmergency(?User $u): bool
    {
        return (bool) ($u?->hasPermission('iptv.emergency') || $u?->hasPermission('iptv.manage'));
    }

    // ───────────────────────────── devices & pairing ─────────────────────────────

    public static function newToken(): string
    {
        do {
            $t = Str::random(40);
        } while (TvDevice::withoutGlobalScopes()->where('token', $t)->exists());

        return $t;
    }

    /** validated device attributes (room / location, platform, name, MAC) */
    protected static function deviceFields(array $d, ?TvDevice $dev = null): array
    {
        $kind = $d['kind'] ?? ($dev?->kind ?? 'room');
        self::need(isset(TvDevice::KINDS[$kind]), 'Choose the type of screen.');
        $room = null;
        if ($kind === 'room') {
            $room = Room::find((int) ($d['room_id'] ?? 0));
            self::need((bool) $room, 'Choose the room the TV is in.');
        } else {
            self::need(filled($d['location'] ?? null), 'Enter where the screen is, e.g. Lobby.');
        }
        $platform = $d['platform'] ?? 'other';
        self::need(isset(TvDevice::PLATFORMS[$platform]), 'Choose the TV platform.');
        $mac = strtoupper(str_replace('-', ':', trim((string) ($d['mac'] ?? ''))));
        self::need($mac === '' || (bool) preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac), 'The MAC address looks like 00:1A:2B:3C:4D:5E.');
        $name = trim((string) ($d['name'] ?? ''));

        return [
            'kind' => $kind, 'room_id' => $room?->id, 'location' => $kind === 'signage' ? mb_substr(trim($d['location']), 0, 80) : null,
            'name' => mb_substr($name !== '' ? $name : ($room ? 'Room '.$room->room_no.' TV' : trim((string) $d['location']).' screen'), 0, 80),
            'platform' => $platform, 'model' => mb_substr(trim((string) ($d['model'] ?? '')), 0, 60) ?: null, 'mac' => $mac ?: null,
            'serial' => mb_substr(trim((string) ($d['serial'] ?? '')), 0, 60) ?: null, 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
        ];
    }

    /** add a TV by hand (its start URL is then typed into the TV's hotel-mode start page) or edit one */
    public static function saveDevice(array $d, ?TvDevice $dev, User $by): TvDevice
    {
        self::need(self::canManage($by));
        $f = self::deviceFields($d, $dev);
        if ($dev) {
            $moved = $dev->room_id !== $f['room_id'] || $dev->kind !== $f['kind'];
            $dev->update($f);
            if ($moved) {
                self::endSessions($dev, 'reset');
            }
            ActivityLogger::log('iptv', 'device_update', "TV {$dev->name} updated", $dev);

            return $dev->fresh();
        }
        $dev = TvDevice::create($f + ['token' => self::newToken(), 'status' => 'active', 'paired_at' => now(), 'paired_by' => $by->id]);
        ActivityLogger::log('iptv', 'device_add', "TV {$dev->name} added ({$dev->place()})", $dev);

        return $dev;
    }

    /** a new TV opened the hotel's setup page: it gets a secret token and shows a 6-digit pairing code for 15 minutes */
    public static function startPairing(?string $ip, ?string $ua): TvDevice
    {
        self::need(self::cfg()['enabled'], 'The TV portal is switched off.');
        $open = TvDevice::where('status', 'pending')->where('pairing_expires_at', '>', now());
        self::need((clone $open)->count() < 20, 'Too many TVs are waiting to be paired – pair or wait for the codes to expire.');
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ((clone $open)->where('pairing_code', $code)->exists());

        return TvDevice::create(['name' => 'New TV', 'kind' => 'room', 'platform' => self::platformFromAgent($ua), 'token' => self::newToken(), 'status' => 'pending',
            'pairing_code' => $code, 'pairing_expires_at' => now()->addMinutes(15), 'last_seen_at' => now(), 'last_ip' => $ip, 'user_agent' => mb_substr((string) $ua, 0, 190)]);
    }

    public static function platformFromAgent(?string $ua): string
    {
        $ua = mb_strtolower((string) $ua);

        return match (true) {
            str_contains($ua, 'tizen') || str_contains($ua, 'samsung') => 'samsung',
            str_contains($ua, 'webos') || str_contains($ua, 'netcast') => 'lg',
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'philips') || str_contains($ua, 'nettv') => 'philips',
            $ua === '' => 'other',
            default => 'browser',
        };
    }

    /** staff enter the code shown on the TV and say where it is */
    public static function claim(string $code, array $d, User $by): TvDevice
    {
        self::need(self::canManage($by));
        $code = preg_replace('/\D/', '', $code);
        $dev = strlen($code) === 6 ? TvDevice::where('status', 'pending')->where('pairing_code', $code)->where('pairing_expires_at', '>', now())->first() : null;
        self::need((bool) $dev, 'No TV is showing that code. Check the code on the screen – codes last 15 minutes.');
        $f = self::deviceFields($d + ['platform' => $dev->platform]);
        $dev->update($f + ['status' => 'active', 'pairing_code' => null, 'pairing_expires_at' => null, 'paired_at' => now(), 'paired_by' => $by->id]);
        ActivityLogger::log('iptv', 'pair', "TV paired: {$dev->name} ({$dev->place()})", $dev);

        return $dev->fresh();
    }

    /** new secret token: the old start URL stops working (lost / stolen set, or to re-pair) */
    public static function rotate(TvDevice $dev, User $by): TvDevice
    {
        self::need(self::canManage($by));
        $dev->forceFill(['token' => self::newToken()])->save();
        self::endSessions($dev, 'reset');
        ActivityLogger::log('iptv', 'rotate', "TV {$dev->name}: new start URL issued (the old one no longer works)", $dev);

        return $dev;
    }

    public static function setStatus(TvDevice $dev, string $status, User $by): void
    {
        self::need(self::canManage($by));
        self::need(in_array($status, ['active', 'disabled'], true) && $dev->status !== 'pending', 'A TV awaiting pairing must be paired first.');
        $dev->update(['status' => $status]);
        if ($status === 'disabled') {
            self::endSessions($dev, 'reset');
        }
        ActivityLogger::log('iptv', 'status', "TV {$dev->name} ".($status === 'active' ? 'enabled' : 'disabled'), $dev);
    }

    public static function deleteDevice(TvDevice $dev, User $by): void
    {
        self::need(self::canManage($by));
        ActivityLogger::log('iptv', 'device_delete', "TV {$dev->name} removed ({$dev->place()})");
        $dev->delete();
    }

    public static function command(TvDevice $dev, string $cmd, array $payload, ?User $by): TvCommand
    {
        self::need(isset(TvCommand::COMMANDS[$cmd]), 'Unknown command.');
        if ($by) {
            self::need(self::canManage($by) || (self::canMessage($by) && in_array($cmd, ['reload', 'reset', 'message'], true)));
        }
        self::need($dev->status === 'active', 'The TV is not active.');
        $p = [];
        if ($cmd === 'volume') {
            $v = (int) ($payload['level'] ?? 0);
            self::need($v >= 10 && $v <= 100, 'The volume limit is 10 to 100.');
            $p = ['level' => $v];
        } elseif ($cmd === 'tune') {
            $ch = TvChannel::where('is_active', true)->where('number', (int) ($payload['channel'] ?? 0))->first();
            self::need((bool) $ch, 'Choose a channel from the line-up.');
            $p = ['channel' => $ch->number, 'name' => $ch->name];
        } elseif ($cmd === 'message') {
            $t = trim((string) ($payload['text'] ?? ''));
            self::need($t !== '', 'Type the message to show.');
            $p = ['text' => mb_substr($t, 0, 200)];
        }
        TvCommand::where('tv_device_id', $dev->id)->where('command', $cmd)->where('status', 'queued')->update(['status' => 'expired', 'result' => 'Replaced by a newer command']);
        $c = TvCommand::create(['tv_device_id' => $dev->id, 'command' => $cmd, 'payload' => $p ?: null, 'status' => 'queued', 'issued_by' => $by?->id]);
        if ($cmd === 'reset') {
            self::endSessions($dev, 'reset');
        }
        if ($by) {
            ActivityLogger::log('iptv', 'command', "TV {$dev->name}: ".TvCommand::COMMANDS[$cmd], $dev);
        }

        return $c;
    }

    /** queued commands go out with the heartbeat */
    protected static function pullCommands(TvDevice $dev): array
    {
        $list = TvCommand::where('tv_device_id', $dev->id)->where('status', 'queued')->where('created_at', '>=', now()->subDay())->orderBy('id')->get();
        if ($list->isNotEmpty()) {
            TvCommand::whereIn('id', $list->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return $list->map(fn ($c) => ['id' => $c->id, 'command' => $c->command, 'payload' => $c->payload ?? (object) []])->all();
    }

    public static function ack(TvDevice $dev, int $id, bool $ok, ?string $result): void
    {
        $c = TvCommand::where('tv_device_id', $dev->id)->whereIn('status', ['sent', 'queued'])->find($id);
        $c?->update(['status' => $ok ? 'done' : 'failed', 'done_at' => now(), 'result' => $result ? mb_substr($result, 0, 190) : null]);
    }

    public static function heartbeat(TvDevice $dev, ?string $ip, ?string $ua): void
    {
        if (! $dev->last_seen_at || $dev->last_seen_at->lt(now()->subSeconds(15)) || $dev->last_ip !== $ip) {
            $dev->forceFill(['last_seen_at' => now(), 'last_ip' => $ip, 'user_agent' => mb_substr((string) $ua, 0, 190)])->saveQuietly();
        }
    }

    // ───────────────────────────── sessions, guest, language, welcome ─────────────────────────────

    public static function stayFor(TvDevice $dev): ?ReservationRoom
    {
        if ($dev->kind !== 'room' || ! $dev->room_id) {
            return null;
        }

        return ReservationRoom::with(['guest', 'reservation.guest', 'reservation.company', 'room'])->where('room_id', $dev->room_id)->where('status', 'checked_in')->latest('checked_in_at')->first();
    }

    public static function endSessions(TvDevice $dev, string $reason): int
    {
        return TvSession::where('tv_device_id', $dev->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => $reason]);
    }

    public static function session(TvDevice $dev, ReservationRoom $stay): TvSession
    {
        $open = TvSession::where('tv_device_id', $dev->id)->whereNull('ended_at')->latest('id')->first();
        if ($open && $open->reservation_room_id !== $stay->id) {
            $open->update(['ended_at' => now(), 'end_reason' => 'vacant']);
            $open = null;
        }
        if (! $open) {
            $prev = TvSession::where('reservation_room_id', $stay->id)->latest('id')->first();
            $open = TvSession::create(['tv_device_id' => $dev->id, 'reservation_room_id' => $stay->id, 'started_at' => now(), 'language' => $prev?->language,
                'welcomed_at' => $prev && $prev->end_reason === 'room_move' ? $prev->welcomed_at : null]);
        }

        return $open;
    }

    public static function guestOf(ReservationRoom $stay)
    {
        return $stay->guest ?? $stay->reservation?->guest;
    }

    public static function language(?TvSession $s, ReservationRoom $stay): string
    {
        $c = self::cfg();
        $g = self::guestOf($stay);
        foreach ([$s?->language, $g?->language, TvLang::forCountry($g?->nationality), TvLang::forCountry($g?->country)] as $l) {
            if ($l && in_array($l, $c['langs'], true)) {
                return $l;
            }
        }

        return $c['default_lang'];
    }

    public static function guestName(ReservationRoom $stay, ?string $mode = null): string
    {
        $g = self::guestOf($stay);
        if (! $g) {
            return '';
        }
        $t = trim((string) $g->title);

        return match ($mode ?? self::cfg()['name_mode']) {
            'full' => trim($t.' '.$g->first_name.' '.$g->last_name),
            'first' => (string) $g->first_name,
            'none' => '',
            default => $t && $g->last_name ? $t.' '.$g->last_name : (string) ($g->first_name ?: $g->last_name),
        };
    }

    /** segments this stay belongs to */
    public static function segmentsOf(ReservationRoom $stay): array
    {
        $g = self::guestOf($stay);
        $r = $stay->reservation;
        $nights = max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date));
        $birthday = false;
        if ($g?->dob) {
            foreach ([$stay->arrival_date->year, $stay->departure_date->year] as $y) {
                $bd = Carbon::create($y, $g->dob->month, min($g->dob->day, Carbon::create($y, $g->dob->month, 1)->daysInMonth));
                $birthday = $birthday || $bd->betweenIncluded($stay->arrival_date, $stay->departure_date);
            }
        }
        $repeat = $g && ReservationRoom::where('guest_id', $g->id)->where('status', 'checked_out')->where('id', '!=', $stay->id)->exists()
            || ($g && Reservation::where('guest_id', $g->id)->where('status', 'checked_out')->where('id', '!=', $stay->reservation_id)->exists());

        return array_keys(array_filter([
            'all' => true, 'vip' => (bool) $g?->is_vip, 'repeat' => $repeat, 'corporate' => (bool) $r?->company_id,
            'group' => (bool) ($r?->group_name || $r?->booking_type === 'group'), 'long_stay' => $nights >= 7, 'birthday' => $birthday,
        ]));
    }

    public static function resolveTemplate(ReservationRoom $stay, string $lang): ?TvWelcomeTemplate
    {
        $segs = self::segmentsOf($stay);
        $all = TvWelcomeTemplate::where('is_active', true)->get()->filter(fn ($t) => $t->isCurrent() && in_array($t->segment, $segs, true));
        foreach (array_unique([$lang, self::cfg()['default_lang'], 'en']) as $l) {
            $c = $all->where('language', $l);
            if ($c->isNotEmpty()) {
                return $c->sort(fn ($a, $b) => [$b->priority, $b->segment !== 'all', $a->id] <=> [$a->priority, $a->segment !== 'all', $b->id])->first();
            }
        }

        return null;
    }

    public static function placeholders(ReservationRoom $stay): array
    {
        $g = self::guestOf($stay);
        $r = $stay->reservation;

        return [
            '{name}' => self::guestName($stay), '{title}' => (string) $g?->title, '{first_name}' => (string) $g?->first_name, '{last_name}' => (string) $g?->last_name,
            '{room}' => (string) $stay->room?->room_no, '{arrival}' => $stay->arrival_date->format('d M Y'), '{departure}' => $stay->departure_date->format('d M Y'),
            '{nights}' => (string) max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date)), '{hotel}' => (string) hotel()?->name,
            '{wifi_name}' => (string) setting('portal_wifi_name', ''), '{wifi_password}' => (string) setting('portal_wifi_password', ''),
            '{company}' => (string) $r?->company?->name, '{group}' => (string) $r?->group_name, '{checkout_time}' => substr((string) hotel()?->checkout_time, 0, 5),
            '{desk_ext}' => self::cfg()['desk_ext'],
        ];
    }

    public static function render(?string $text, ReservationRoom $stay): string
    {
        $out = strtr((string) $text, self::placeholders($stay));
        // a name-less greeting ("Akwaaba, !") reads better without the dangling punctuation
        return trim(preg_replace(['/,\s*([!.?])/u', '/,\s*$/u', '/\s{2,}/u'], ['$1', '', ' '], $out));
    }

    public static function welcome(ReservationRoom $stay, string $lang): array
    {
        $t = self::resolveTemplate($stay, $lang);
        $s = TvLang::strings($lang);

        return [
            'template_id' => $t?->id, 'template' => $t?->name, 'theme' => $t?->theme ?? 'gold',
            'headline' => $t ? self::render($t->headline, $stay) : self::render($s['welcome'].', {name}!', $stay),
            'body' => $t ? self::render($t->body, $stay) : '',
        ];
    }

    // ───────────────────────────── state (heartbeat payload) ─────────────────────────────

    public static function emergency(): ?TvMessage
    {
        return TvMessage::where('kind', 'emergency')->where('status', 'active')->latest('id')->first();
    }

    public static function state(TvDevice $dev): array
    {
        $c = self::cfg();
        $h = hotel();
        $em = self::emergency();
        $base = [
            'hotel' => ['name' => $h?->name, 'city' => $h?->city], 'device' => ['name' => $dev->name, 'place' => $dev->place(), 'kind' => $dev->kind],
            'now' => now()->toIso8601String(), 'heartbeat' => $c['heartbeat'],
            'emergency' => $em ? ['id' => $em->id, 'title' => $em->title, 'body' => $em->body, 'at' => $em->starts_at->format('H:i')] : null,
            'weather' => $c['weather'] ? ['lat' => $c['lat'], 'lon' => $c['lon']] : null, 'desk_ext' => $c['desk_ext'],
        ];
        if (! $c['enabled'] || $dev->status !== 'active') {
            return $base + ['mode' => $dev->status === 'active' ? 'off' : 'unregistered', 'lang' => $c['default_lang'], 't' => TvLang::strings($c['default_lang']), 'commands' => []];
        }
        $base['commands'] = self::pullCommands($dev);
        $base['volume_max'] = $c['volume_max'];
        $base['power_channel'] = $c['power_channel'];
        $base['langs'] = collect($c['langs'])->mapWithKeys(fn ($l) => [$l => TvLang::LANGS[$l]])->all();
        if ($dev->kind === 'signage') {
            $lang = $c['default_lang'];

            return $base + ['mode' => 'signage', 'lang' => $lang, 't' => TvLang::strings($lang), 'text' => $c['signage_text'],
                'events' => $c['signage_events'] ? self::eventsToday() : [], 'messages' => self::messagePayload($dev, null)];
        }
        $stay = self::stayFor($dev);
        if (! $stay) {
            self::endSessions($dev, 'vacant');
            $lang = $c['default_lang'];

            return $base + ['mode' => 'vacant', 'lang' => $lang, 't' => TvLang::strings($lang)];
        }
        $s = self::session($dev, $stay);
        $lang = self::language($s, $stay);
        $w = self::welcome($stay, $lang);
        if ($s->tv_welcome_template_id !== $w['template_id']) {
            $s->forceFill(['tv_welcome_template_id' => $w['template_id']])->saveQuietly();
        }
        $msgs = self::messagePayload($dev, $stay);
        $desk = GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', GuestMessage::OPEN)->count();
        $rentals = TvPurchase::where('reservation_room_id', $stay->id)->where('status', 'active')->where('expires_at', '>', now())->with('movie')->get()
            ->map(fn ($p) => ['id' => $p->id, 'movie' => $p->tv_movie_id, 'title' => $p->title, 'left' => (int) ceil(now()->diffInMinutes($p->expires_at) / 60)])->values()->all();

        return $base + [
            'mode' => 'room', 'lang' => $lang, 't' => TvLang::strings($lang),
            'guest' => ['name' => self::guestName($stay), 'room' => $stay->room?->room_no, 'departure' => $stay->departure_date->format('d M Y'),
                'departing_today' => $stay->departure_date->toDateString() <= bdate()->toDateString()],
            'welcome' => $w + ['show' => $c['welcome'] && ! $s->welcomed_at],
            'wifi' => ['name' => (string) setting('portal_wifi_name', ''), 'password' => (string) setting('portal_wifi_password', '')],
            'messages' => $msgs, 'unread' => collect($msgs)->where('read', false)->count(), 'desk_messages' => $desk,
            'features' => self::features(), 'pin' => ['purchase' => $c['pin_purchase'], 'bill' => $c['pin_bill']], 'rentals' => $rentals,
        ];
    }

    public static function features(): array
    {
        $c = self::cfg();

        return self::$memo['features'.Tenant::id()] ??= [
            'tv' => TvChannel::where('is_active', true)->exists(), 'movies' => $c['vod'] && TvMovie::where('is_active', true)->exists(),
            'dining' => $c['dining'] && (bool) GuestPortalService::diningOutlet(), 'services' => $c['requests'], 'messages' => true, 'bill' => true,
            'checkout' => $c['checkout'], 'info' => TvPage::where('is_active', true)->exists(), 'explore' => ConciergePlace::where('portal_visible', true)->exists(),
            'feedback' => $c['feedback'],
        ];
    }

    /** today's banquet functions for the lobby screens */
    public static function eventsToday(): array
    {
        return BanquetFunction::with(['event', 'hall'])->where('date', bdate()->toDateString())
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['definite', 'completed']))->orderBy('start_time')->get()
            ->map(fn ($f) => ['name' => $f->event?->title ?: $f->name, 'function' => $f->name, 'hall' => $f->hall?->name,
                'from' => substr((string) $f->start_time, 0, 5), 'to' => substr((string) $f->end_time, 0, 5)])->values()->all();
    }

    // ───────────────────────────── messages, broadcasts, emergency ─────────────────────────────

    /** live messages a TV should show (room / floor / all / VIP / group for room TVs; signage for public screens) */
    public static function messagesFor(TvDevice $dev, ?ReservationRoom $stay): Collection
    {
        $q = TvMessage::where('status', 'active')->where('kind', '!=', 'emergency')->where('starts_at', '<=', now())
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
        if ($dev->kind === 'signage') {
            return $q->where('target', 'signage')->orderByDesc('id')->limit(10)->get();
        }
        if (! $stay) {
            return collect();
        }
        $g = self::guestOf($stay);
        $floor = $stay->room?->floor_id;

        return $q->where(function ($w) use ($stay, $g, $floor) {
            $w->where(fn ($x) => $x->where('target', 'room')->where('reservation_room_id', $stay->id))
                ->orWhere('target', 'all')
                ->orWhere(fn ($x) => $x->where('target', 'floor')->where('target_id', $floor ?: -1))
                ->orWhere(fn ($x) => $x->where('target', 'group')->where('target_id', $stay->reservation_id));
            if ($g?->is_vip) {
                $w->orWhere('target', 'vip');
            }
        })->orderByDesc('id')->limit(30)->get();
    }

    public static function messagePayload(TvDevice $dev, ?ReservationRoom $stay): array
    {
        $list = self::messagesFor($dev, $stay);
        $read = TvMessageRead::where('tv_device_id', $dev->id)->whereIn('tv_message_id', $list->pluck('id'))->pluck('tv_message_id')->all();

        return $list->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'body' => $m->body, 'priority' => $m->priority, 'ack' => $m->requires_ack,
            'read' => in_array($m->id, $read, true), 'at' => $m->starts_at->format('d M H:i'), 'kind' => $m->kind])->values()->all();
    }

    public static function send(array $d, User $by): TvMessage
    {
        self::need(self::canMessage($by));
        $target = $d['target'] ?? '';
        self::need(isset(TvMessage::TARGETS[$target]), 'Choose who should see the message.');
        $title = trim((string) ($d['title'] ?? ''));
        $body = trim((string) ($d['body'] ?? ''));
        self::need($title !== '' && mb_strlen($title) <= 80, 'Give the message a title (up to 80 characters).');
        self::need($body !== '' && mb_strlen($body) <= 500, 'Write the message (up to 500 characters).');
        $prio = $d['priority'] ?? 'normal';
        self::need(in_array($prio, ['normal', 'high'], true), 'Choose the priority.');
        $start = filled($d['starts_at'] ?? null) ? Carbon::parse($d['starts_at']) : now();
        self::need($start->gte(now()->subMinutes(5)) && $start->lte(now()->addDays(30)), 'The message can start from now up to 30 days ahead.');
        $end = filled($d['ends_at'] ?? null) ? Carbon::parse($d['ends_at']) : null;
        self::need(! $end || $end->gt($start), 'The end time must be after the start.');
        $stayId = null;
        $targetId = null;
        if ($target === 'room') {
            $room = Room::find((int) ($d['room_id'] ?? 0));
            self::need((bool) $room, 'Choose the room.');
            $stay = $room->currentStay;
            self::need($stay && $stay->status === 'checked_in', "Room {$room->room_no} has no in-house guest.");
            [$targetId, $stayId] = [$room->id, $stay->id];
        } elseif ($target === 'floor') {
            $floor = Floor::find((int) ($d['floor_id'] ?? 0));
            self::need((bool) $floor, 'Choose the floor.');
            $targetId = $floor->id;
        } elseif ($target === 'group') {
            $res = Reservation::find((int) ($d['reservation_id'] ?? 0));
            self::need($res && $res->rooms()->where('status', 'checked_in')->exists(), 'Choose a booking with guests in house.');
            $targetId = $res->id;
        }
        if (! $end && $target !== 'room') {
            $end = $start->copy()->addDay();
        }
        $m = TvMessage::create(['number' => Numbering::next('tv_message'), 'kind' => $target === 'room' ? 'room' : 'broadcast', 'target' => $target, 'target_id' => $targetId,
            'reservation_room_id' => $stayId, 'title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 500), 'priority' => $prio,
            'requires_ack' => ! empty($d['requires_ack']), 'starts_at' => $start, 'ends_at' => $end, 'status' => 'active', 'sent_by' => $by->id]);
        ActivityLogger::log('iptv', 'message', "TV message {$m->number} to {$m->targetLabel()}: {$m->title}", $m);

        return $m;
    }

    public static function emergencyStart(array $d, User $by): TvMessage
    {
        self::need(self::canEmergency($by), 'Only managers with the emergency permission can put an alert on every screen.');
        self::need(mb_strtoupper(trim((string) ($d['confirm'] ?? ''))) === 'EMERGENCY', 'Type EMERGENCY to confirm – the alert covers every TV in the hotel.');
        self::need(! self::emergency(), 'An emergency alert is already on the screens. Clear it before sending another.');
        $title = trim((string) ($d['title'] ?? '')) ?: 'Emergency – please follow staff instructions';
        $body = trim((string) ($d['body'] ?? ''));
        self::need($body !== '', 'Write what guests must do, e.g. leave by the nearest stairway to the assembly point.');
        $m = TvMessage::create(['number' => Numbering::next('tv_message'), 'kind' => 'emergency', 'target' => 'all', 'title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 500),
            'priority' => 'high', 'requires_ack' => false, 'starts_at' => now(), 'ends_at' => null, 'status' => 'active', 'sent_by' => $by->id]);
        ActivityLogger::log('iptv', 'emergency', "EMERGENCY alert {$m->number} on every TV: {$m->title}", $m);

        return $m;
    }

    public static function emergencyClear(TvMessage $m, ?string $note, User $by): void
    {
        self::need(self::canEmergency($by));
        self::need($m->kind === 'emergency' && $m->status === 'active', 'This alert is not active.');
        $note = trim((string) $note);
        $m->update(['status' => 'cleared', 'closed_at' => now(), 'closed_by' => $by->id, 'close_note' => mb_substr($note, 0, 190) ?: null]);
        foreach (['all', 'signage'] as $t) {
            TvMessage::create(['number' => Numbering::next('tv_message'), 'kind' => 'broadcast', 'target' => $t, 'title' => 'All clear',
                'body' => mb_substr($note ?: 'The emergency is over. Thank you for your cooperation – normal service has resumed.', 0, 500),
                'priority' => 'high', 'starts_at' => now(), 'ends_at' => now()->addMinutes(30), 'status' => 'active', 'sent_by' => $by->id]);
        }
        ActivityLogger::log('iptv', 'all_clear', "All clear after {$m->number}".($note ? ": {$note}" : ''), $m);
    }

    public static function cancelMessage(TvMessage $m, string $reason, User $by): void
    {
        self::need(self::canMessage($by));
        self::need($m->kind !== 'emergency', 'Clear an emergency alert with “All clear”.');
        self::need(in_array($m->state(), ['live', 'scheduled'], true), 'The message is no longer on the screens.');
        self::need(mb_strlen(trim($reason)) >= 3, 'Give a reason for withdrawing the message.');
        $m->update(['status' => 'cancelled', 'closed_at' => now(), 'closed_by' => $by->id, 'close_note' => mb_substr(trim($reason), 0, 190)]);
        ActivityLogger::log('iptv', 'message_cancel', "TV message {$m->number} withdrawn: {$reason}", $m);
    }

    public static function markRead(TvDevice $dev, ?ReservationRoom $stay, int $id): void
    {
        $m = self::messagesFor($dev, $stay)->firstWhere('id', $id);
        self::need((bool) $m, 'Message not found.');
        TvMessageRead::firstOrCreate(['tv_message_id' => $m->id, 'tv_device_id' => $dev->id], ['reservation_room_id' => $stay?->id, 'read_at' => now()]);
    }

    /** number of screens a message reaches right now */
    public static function reach(TvMessage $m): int
    {
        $devices = TvDevice::where('status', 'active');
        if ($m->target === 'signage') {
            return $devices->where('kind', 'signage')->count();
        }
        $rooms = ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id');
        $rooms = match ($m->target) {
            'room' => $rooms->where('id', $m->reservation_room_id),
            'floor' => $rooms->whereHas('room', fn ($q) => $q->where('floor_id', $m->target_id)),
            'group' => $rooms->where('reservation_id', $m->target_id),
            'vip' => $rooms->where(fn ($q) => $q->whereHas('guest', fn ($g) => $g->where('is_vip', true))->orWhere(fn ($x) => $x->whereNull('guest_id')->whereHas('reservation.guest', fn ($g) => $g->where('is_vip', true)))),
            default => $rooms,
        };
        if ($m->kind === 'emergency') {
            return $devices->count();
        }

        return $devices->where('kind', 'room')->whereIn('room_id', $rooms->pluck('room_id'))->count();
    }

    // ───────────────────────────── PIN ─────────────────────────────

    public static function pinFor(ReservationRoom $stay): string
    {
        if (! $stay->tv_pin) {
            $stay->forceFill(['tv_pin' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)])->saveQuietly();
        }

        return $stay->tv_pin;
    }

    public static function checkPin(TvSession $s, ReservationRoom $stay, ?string $pin): void
    {
        if ($s->pin_locked_until && $s->pin_locked_until->gt(now())) {
            throw new RuntimeException('Too many wrong PINs. Try again in '.max(1, (int) ceil(now()->diffInMinutes($s->pin_locked_until))).' minute(s) or call the front desk.');
        }
        if (! is_string($pin) || ! preg_match('/^\d{4}$/', $pin) || ! hash_equals(self::pinFor($stay), $pin)) {
            $f = $s->pin_failures + 1;
            if ($f >= self::PIN_TRIES) {
                $s->update(['pin_failures' => 0, 'pin_locked_until' => now()->addMinutes(self::PIN_LOCK_MIN)]);
                ActivityLogger::log('iptv', 'pin_lock', 'TV PIN locked after '.self::PIN_TRIES.' wrong attempts (room '.$stay->room?->room_no.')', $stay);
                throw new RuntimeException('Too many wrong PINs – purchases and the bill are locked for '.self::PIN_LOCK_MIN.' minutes.');
            }
            $s->update(['pin_failures' => $f]);
            throw new RuntimeException('Wrong PIN – '.(self::PIN_TRIES - $f).' attempt(s) left.');
        }
        if ($s->pin_failures) {
            $s->update(['pin_failures' => 0]);
        }
    }

    // ───────────────────────────── guest actions from the TV ─────────────────────────────

    public static function logEvent(TvDevice $dev, ?ReservationRoom $stay, string $event, ?string $target = null): void
    {
        if (isset(TvEvent::EVENTS[$event])) {
            TvEvent::create(['tv_device_id' => $dev->id, 'reservation_room_id' => $stay?->id, 'event' => $event, 'target' => $target !== null ? mb_substr(strip_tags($target), 0, 120) : null]);
        }
    }

    public static function dismissWelcome(TvDevice $dev, ReservationRoom $stay): void
    {
        $s = self::session($dev, $stay);
        if (! $s->welcomed_at) {
            $s->update(['welcomed_at' => now()]);
            self::logEvent($dev, $stay, 'welcome', $s->template?->name);
        }
    }

    public static function setLanguage(TvDevice $dev, ReservationRoom $stay, string $lang): void
    {
        self::need(in_array($lang, self::cfg()['langs'], true), 'Language not available.');
        self::session($dev, $stay)->update(['language' => $lang]);
        if ($g = self::guestOf($stay)) {
            $g->forceFill(['language' => $lang])->saveQuietly(); // remembered for the next stay
        }
        self::logEvent($dev, $stay, 'language', $lang);
    }

    public static function request(TvDevice $dev, ReservationRoom $stay, array $d): GuestRequest
    {
        self::need(self::cfg()['requests'], 'Requests are not available on the TV.');
        self::need(in_array($d['category'] ?? '', self::TV_REQUESTS, true), 'Choose a service.');
        $req = GuestPortalService::raise($stay, ['category' => $d['category'], 'item' => $d['item'] ?? '', 'quantity' => (int) ($d['quantity'] ?? 1),
            'notes' => ($d['item'] ?? '') === 'Other' ? 'Raised on the room TV – please call the guest' : null], 'tv');
        self::logEvent($dev, $stay, 'request', $req->item);

        return $req;
    }

    public static function wakeup(TvDevice $dev, ReservationRoom $stay, string $time, bool $daily)
    {
        self::need(self::cfg()['requests'], 'Wake-up calls are not available on the TV.');
        self::need((bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time), 'Choose the time.');
        $at = \Illuminate\Support\Carbon::parse(now()->toDateString().' '.$time);
        if ($at->lte(now()->addMinutes(5))) {
            $at->addDay();
        }
        $call = WakeupService::schedule($stay, $at, $daily, 'Booked on the room TV', 'tv');
        self::logEvent($dev, $stay, 'wakeup', $at->format('D H:i'));

        return $call;
    }

    public static function order(TvDevice $dev, ReservationRoom $stay, array $lines): PosOrder
    {
        self::need(self::cfg()['dining'], 'In-room dining is not available on the TV.');
        self::need($lines !== [] && count($lines) <= 30, 'Your order is empty.');
        $clean = [];
        foreach ($lines as $l) {
            $qty = (int) ($l['qty'] ?? 0);
            self::need(is_numeric($l['id'] ?? null) && $qty >= 1 && $qty <= 20, 'Choose 1 to 20 of each dish.');
            $modifierIds = array_values(array_unique(array_map('intval', (array) ($l['modifiers'] ?? []))));
            self::need(count($modifierIds) <= 20, 'Too many options selected for a dish.');
            $clean[] = ['id' => (int) $l['id'], 'qty' => $qty, 'modifiers' => $modifierIds];
        }
        $o = GuestPortalService::orderDining($stay, $clean, null, 'tv');
        self::logEvent($dev, $stay, 'order', $o->order_no);

        return $o;
    }

    public static function checkout(TvDevice $dev, ReservationRoom $stay): GuestRequest
    {
        self::need(self::cfg()['checkout'], 'Express check-out is not available on the TV.');
        $req = GuestPortalService::raise($stay, ['category' => 'checkout', 'notes' => 'Requested on the room TV'], 'tv');
        self::logEvent($dev, $stay, 'checkout', $req->request_no);

        return $req;
    }

    public static function feedback(TvDevice $dev, ReservationRoom $stay, int $rating): GuestFeedback
    {
        self::need(self::cfg()['feedback'], 'Feedback is not available on the TV.');
        self::need($rating >= 1 && $rating <= 5, 'Choose 1 to 5 stars.');
        $g = self::guestOf($stay);
        $fb = GuestFeedback::where('reservation_room_id', $stay->id)->first();
        if ($fb && $fb->source !== 'tv') {
            return $fb; // already reviewed in more detail on the portal / survey
        }
        $fb = GuestFeedback::updateOrCreate(['reservation_room_id' => $stay->id], ['rating_overall' => $rating, 'reservation_id' => $stay->reservation_id, 'guest_id' => $g?->id,
            'guest_name' => $g?->full_name ?? 'Guest', 'contact' => $g?->email ?: $g?->phone, 'source' => 'tv', 'in_house' => true]);
        FeedbackService::analyse($fb);
        self::logEvent($dev, $stay, 'feedback', $rating.'★');

        return $fb;
    }

    public static function bill(TvDevice $dev, ReservationRoom $stay, ?string $pin): array
    {
        if (self::cfg()['pin_bill']) {
            self::checkPin(self::session($dev, $stay), $stay, $pin);
        }
        $folios = Folio::with(['transactions' => fn ($q) => $q->where('is_void', false)->orderBy('business_date')->orderBy('id')])->where('reservation_room_id', $stay->id)->orderBy('id')->get();
        $lines = [];
        $bal = 0;
        foreach ($folios as $f) {
            $bal += (float) $f->totals()['balance'];
            foreach ($f->transactions as $t) {
                $amt = (float) $t->amount + (float) $t->tax_amount;
                $lines[] = ['date' => $t->business_date?->format('d M'), 'text' => $t->description, 'amount' => money($t->type === 'charge' ? $amt : -$amt), 'credit' => $t->type !== 'charge'];
            }
        }
        self::logEvent($dev, $stay, 'bill');

        return ['lines' => array_slice($lines, -60), 'balance' => money(round($bal, 2)), 'owing' => $bal > 0.005];
    }

    // ───────────────────────────── movies (video on demand) ─────────────────────────────

    public static function saveMovie(array $d, ?TvMovie $m, User $by): TvMovie
    {
        self::need(self::canManage($by));
        $title = trim((string) ($d['title'] ?? ''));
        self::need($title !== '', 'Enter the title.');
        self::need(isset(TvMovie::GENRES[$d['genre'] ?? '']), 'Choose the genre.');
        self::need(in_array($d['rating'] ?? '', TvMovie::RATINGS, true), 'Choose the age rating.');
        self::need(is_numeric($d['price'] ?? null) && $d['price'] >= 0 && $d['price'] <= 10000, 'The price is GH₵ 0 to 10,000 before tax.');
        $dur = (int) ($d['duration_min'] ?? 0);
        self::need($dur >= 1 && $dur <= 600, 'The running time is 1 to 600 minutes.');
        $hours = (int) ($d['rental_hours'] ?? 24);
        self::need($hours >= 1 && $hours <= 72, 'The rental lasts 1 to 72 hours.');
        $url = trim((string) ($d['stream_url'] ?? ''));
        self::need($url === '' || self::validStream($url), 'The stream must be an http(s), rtsp, rtp or udp address.');
        $year = filled($d['year'] ?? null) ? (int) $d['year'] : null;
        self::need(! $year || ($year >= 1900 && $year <= 2100), 'Enter the year of release.');
        $f = ['title' => mb_substr($title, 0, 120), 'genre' => $d['genre'], 'rating' => $d['rating'], 'duration_min' => $dur, 'year' => $year,
            'language' => mb_substr(trim((string) ($d['language'] ?? '')), 0, 30) ?: null, 'synopsis' => mb_substr(trim((string) ($d['synopsis'] ?? '')), 0, 500) ?: null,
            'price' => round((float) $d['price'], 2), 'rental_hours' => $hours, 'stream_url' => $url ?: null,
            'is_adult' => ! empty($d['is_adult']) || $d['genre'] === 'adult', 'is_active' => ! empty($d['is_active']), 'sort' => max(0, min(999, (int) ($d['sort'] ?? 0)))];
        $m ? $m->update($f) : $m = TvMovie::create($f);
        ActivityLogger::log('iptv', 'movie', "Pay movie saved: {$m->title} (".money($m->price).' + tax)', $m);

        return $m;
    }

    public static function rent(TvDevice $dev, ReservationRoom $stay, TvMovie $movie, ?string $pin): TvPurchase
    {
        $c = self::cfg();
        self::need($c['vod'], 'Movies are not available on this TV.');
        self::need($stay->status === 'checked_in', 'Movies can be rented while you are staying with us.');
        self::need($movie->is_active && (! $movie->is_adult || $c['adult']), 'This movie is not available.');
        $s = self::session($dev, $stay);
        $existing = TvPurchase::where('reservation_room_id', $stay->id)->where('tv_movie_id', $movie->id)->where('status', 'active')->where('expires_at', '>', now())->first();
        if ($existing) {
            return $existing; // still within the rental window – no second charge
        }
        if ($c['pin_purchase'] || $movie->is_adult) {
            self::checkPin($s, $stay, $pin);
        }
        $today = TvPurchase::where('reservation_room_id', $stay->id)->where('status', 'active')->where('starts_at', '>=', now()->subDay())->count();
        self::need($today < $c['max_rentals'], 'The movie limit for today has been reached – please call the front desk.');
        $folio = FolioService::openForStay($stay->fresh());
        self::need($folio->status === 'open', 'Your bill is closed – please call the front desk.');
        $tax = TaxService::forCategory('pay_tv', (float) $movie->price);

        return DB::transaction(function () use ($dev, $stay, $movie, $folio, $tax) {
            $p = TvPurchase::create(['number' => Numbering::next('tv_purchase'), 'tv_device_id' => $dev->id, 'reservation_room_id' => $stay->id, 'room_id' => $stay->room_id,
                'tv_movie_id' => $movie->id, 'title' => $movie->title, 'amount' => $movie->price, 'tax' => $tax['amount'], 'total' => round((float) $movie->price + $tax['amount'], 2),
                'starts_at' => now(), 'expires_at' => now()->addHours($movie->rental_hours), 'status' => 'active']);
            if ((float) $movie->price > 0) {
                $tx = FolioService::charge($folio, 'pay_tv', "Pay-TV movie: {$movie->title} ({$p->number})", (float) $movie->price, true,
                    ['source_type' => 'tv', 'source_id' => $p->id, 'reference' => $p->number]);
                $p->update(['folio_transaction_id' => $tx->id]);
            }
            self::logEvent($dev, $stay, 'rent', $movie->title);
            ActivityLogger::log('iptv', 'rent', "Movie {$p->number} “{$movie->title}” rented on the TV in room {$stay->room?->room_no} (".money($p->total).')', $p);

            return $p;
        });
    }

    public static function refund(TvPurchase $p, string $reason, User $by): void
    {
        self::need(self::canManage($by));
        self::need($p->status !== 'refunded', 'Already refunded.');
        self::need(mb_strlen(trim($reason)) >= 3, 'Give a reason for the refund, e.g. the movie would not play.');
        DB::transaction(function () use ($p, $reason, $by) {
            $tx = $p->transaction;
            if ($tx && ! $tx->is_void) {
                self::need($tx->folio?->status === 'open', 'The bill is already settled – refund the guest from Billing instead.');
                FolioService::void($tx, 'Pay-TV refund: '.mb_substr(trim($reason), 0, 150));
            }
            $p->update(['status' => 'refunded', 'refund_reason' => mb_substr(trim($reason), 0, 190), 'refunded_by' => $by->id, 'refunded_at' => now(),
                'expires_at' => $p->expires_at->gt(now()) ? now() : $p->expires_at]);
        });
        ActivityLogger::log('iptv', 'refund', "Movie {$p->number} refunded (".money($p->total)."): {$reason}", $p);
    }

    // ───────────────────────────── channels & programme guide ─────────────────────────────

    public static function validStream(string $url): bool
    {
        return (bool) preg_match('#^(https?|rtsp|rtp|udp|igmp)://[^\s<>"\']{3,250}$#i', $url);
    }

    public static function saveChannel(array $d, ?TvChannel $ch, User $by): TvChannel
    {
        self::need(self::canManage($by));
        $no = (int) ($d['number'] ?? 0);
        self::need($no >= 1 && $no <= 9999, 'The channel number is 1 to 9999.');
        self::need(! TvChannel::where('number', $no)->when($ch, fn ($q) => $q->where('id', '!=', $ch->id))->exists(), "Channel {$no} is already in the line-up.");
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '', 'Enter the channel name.');
        self::need(isset(TvChannel::CATEGORIES[$d['category'] ?? '']), 'Choose the category.');
        $url = trim((string) ($d['stream_url'] ?? ''));
        self::need(self::validStream($url), 'Enter the stream address, e.g. udp://@239.10.0.1:1234 or https://…/index.m3u8.');
        $f = ['number' => $no, 'name' => mb_substr($name, 0, 80), 'category' => $d['category'], 'language' => mb_substr(trim((string) ($d['language'] ?? '')), 0, 30) ?: null,
            'stream_url' => $url, 'epg_id' => mb_substr(trim((string) ($d['epg_id'] ?? '')), 0, 80) ?: null, 'is_radio' => ! empty($d['is_radio']) || $d['category'] === 'radio',
            'is_active' => ! empty($d['is_active'])];
        $ch ? $ch->update($f) : $ch = TvChannel::create($f);
        self::flush();
        ActivityLogger::log('iptv', 'channel', "Channel {$ch->number} {$ch->name} saved", $ch);

        return $ch;
    }

    public static function deleteChannel(TvChannel $ch, User $by): void
    {
        self::need(self::canManage($by));
        ActivityLogger::log('iptv', 'channel_delete', "Channel {$ch->number} {$ch->name} removed from the line-up");
        $ch->delete();
        self::flush();
    }

    public static function guessCategory(string $group, string $name): string
    {
        $s = mb_strtolower($group.' '.$name);
        foreach ([
            'radio' => ['radio', ' fm'], 'kids' => ['kid', 'cartoon', 'junior', 'nick', 'disney'], 'sports' => ['sport', 'football', 'espn', 'bein', 'golf'],
            'news' => ['news', 'cnn', 'bbc world', 'al jazeera', 'france 24', 'sky news', 'cgtn', 'dw'], 'business' => ['business', 'bloomberg', 'cnbc'],
            'music' => ['music', 'mtv', 'trace', 'hits'], 'movies' => ['movie', 'cinema', 'film', 'series', 'africa magic', 'hbo'], 'documentary' => ['docu', 'discovery', 'national geographic', 'nat geo', 'history'],
            'religion' => ['relig', 'gospel', 'church', 'islam', 'faith'], 'local' => ['ghana', 'local', 'gtv', 'tv3', 'utv', 'joy', 'adom', 'citi', 'metro'], 'hotel' => ['hotel', 'info channel'],
        ] as $cat => $needles) {
            foreach ($needles as $n) {
                if (str_contains($s, $n)) {
                    return $cat;
                }
            }
        }

        return isset(TvChannel::CATEGORIES[mb_strtolower($group)]) ? mb_strtolower($group) : 'entertainment';
    }

    /** M3U / M3U8 playlist (#EXTINF with tvg-chno, tvg-id, tvg-language, group-title): add or update by channel number */
    public static function importM3u(string $text, User $by): array
    {
        self::need(self::canManage($by));
        $lines = preg_split('/\r?\n/', trim($text));
        self::need(count($lines) > 1 && str_contains($text, '#EXTINF'), 'That is not an M3U playlist – it needs #EXTINF lines followed by stream addresses.');
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $pending = null;
        $next = (int) TvChannel::max('number');
        DB::transaction(function () use ($lines, &$out, &$pending, &$next) {
            foreach (array_slice($lines, 0, 4000) as $line) {
                $line = trim($line);
                if (str_starts_with($line, '#EXTINF')) {
                    preg_match_all('/([a-z\-]+)="([^"]*)"/i', $line, $m, PREG_SET_ORDER);
                    $a = [];
                    foreach ($m as $x) {
                        $a[mb_strtolower($x[1])] = trim($x[2]);
                    }
                    $pos = strrpos($line, ',');
                    $pending = $a + ['_name' => trim($pos !== false ? substr($line, $pos + 1) : '') ?: ($a['tvg-name'] ?? '')];
                } elseif ($line !== '' && $line[0] !== '#' && $pending) {
                    $name = mb_substr($pending['_name'], 0, 80);
                    if ($name === '' || ! self::validStream($line) || $out['created'] + $out['updated'] >= 1000) {
                        $out['skipped']++;
                        $pending = null;

                        continue;
                    }
                    $no = (int) ($pending['tvg-chno'] ?? 0);
                    $ch = $no >= 1 && $no <= 9999 ? TvChannel::where('number', $no)->first() : TvChannel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
                    $no = $ch?->number ?? ($no >= 1 && $no <= 9999 ? $no : ++$next);
                    $next = max($next, $no);
                    $cat = self::guessCategory((string) ($pending['group-title'] ?? ''), $name);
                    $f = ['number' => $no, 'name' => $name, 'category' => $cat, 'language' => mb_substr((string) ($pending['tvg-language'] ?? ''), 0, 30) ?: $ch?->language,
                        'stream_url' => mb_substr($line, 0, 255), 'epg_id' => mb_substr((string) ($pending['tvg-id'] ?? ''), 0, 80) ?: $ch?->epg_id, 'is_radio' => $cat === 'radio',
                        'is_active' => true];
                    $ch ? $ch->update($f) : TvChannel::create($f);
                    $out[$ch ? 'updated' : 'created']++;
                    $pending = null;
                }
            }
        });
        self::flush();
        ActivityLogger::log('iptv', 'm3u_import', "Channel line-up imported: {$out['created']} added, {$out['updated']} updated, {$out['skipped']} skipped");

        return $out;
    }

    public static function exportM3u(): string
    {
        $out = "#EXTM3U\n";
        foreach (TvChannel::where('is_active', true)->orderBy('number')->get() as $c) {
            $attr = fn ($v) => str_replace('"', "'", (string) $v);
            $out .= '#EXTINF:-1 tvg-chno="'.$c->number.'"'.($c->epg_id ? ' tvg-id="'.$attr($c->epg_id).'"' : '').($c->language ? ' tvg-language="'.$attr($c->language).'"' : '')
                .' group-title="'.$attr($c->categoryLabel()).'",'.str_replace(["\r", "\n"], ' ', $c->name)."\n".$c->stream_url."\n";
        }

        return $out;
    }

    public static function parseXmltvTime(string $v): ?Carbon
    {
        $v = trim($v);
        try {
            $c = preg_match('/^\d{14}\s*[+-]\d{4}$/', $v) ? Carbon::createFromFormat('YmdHis O', preg_replace('/\s+/', ' ', $v))
                : (preg_match('/^\d{14}$/', $v) ? Carbon::createFromFormat('YmdHis', $v, 'UTC') : (preg_match('/^\d{12}$/', $v) ? Carbon::createFromFormat('YmdHi', $v, 'UTC') : null));
        } catch (\Throwable) {
            return null;
        }

        return $c?->setTimezone(config('app.timezone'));
    }

    /** XMLTV programme guide: programmes for channels whose EPG id (or name) matches, from yesterday to 8 days ahead */
    public static function importXmltv(string $xml, User $by): array
    {
        self::need(self::canManage($by));
        $prev = libxml_use_internal_errors(true);
        $x = str_contains($xml, '<!ENTITY') ? false : simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        self::need($x !== false && $x->getName() === 'tv', 'That is not an XMLTV file (it should start with <tv>).');
        $map = TvChannel::whereNotNull('epg_id')->pluck('id', 'epg_id')->all();
        $linked = 0;
        foreach ($x->channel as $c) {
            $id = (string) $c['id'];
            if ($id === '' || isset($map[$id])) {
                continue;
            }
            foreach ($c->{'display-name'} as $dn) {
                $ch = TvChannel::whereNull('epg_id')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $dn))])->first();
                if ($ch) {
                    $ch->update(['epg_id' => mb_substr($id, 0, 80)]);
                    $map[$id] = $ch->id;
                    $linked++;
                    break;
                }
            }
        }
        [$lo, $hi] = [now()->subDay(), now()->addDays(8)];
        $rows = [];
        $skipped = 0;
        $n = 0;
        foreach ($x->programme as $p) {
            if (++$n > 20000) {
                break;
            }
            $cid = $map[(string) $p['channel']] ?? null;
            $a = self::parseXmltvTime((string) $p['start']);
            $b = self::parseXmltvTime((string) $p['stop']);
            if (! $cid || ! $a || ! $b || $b->lte($a) || $b->lt($lo) || $a->gt($hi) || trim((string) $p->title) === '') {
                $skipped++;

                continue;
            }
            $rows[$cid][] = ['hotel_id' => Tenant::id(), 'tv_channel_id' => $cid, 'starts_at' => $a->toDateTimeString(), 'ends_at' => $b->toDateTimeString(),
                'title' => mb_substr(trim((string) $p->title), 0, 150), 'description' => mb_substr(trim((string) $p->desc), 0, 500) ?: null, 'genre' => mb_substr(trim((string) $p->category), 0, 60) ?: null];
        }
        $count = 0;
        DB::transaction(function () use ($rows, &$count) {
            foreach ($rows as $cid => $list) {
                $min = min(array_column($list, 'starts_at'));
                $max = max(array_column($list, 'ends_at'));
                TvProgramme::where('tv_channel_id', $cid)->where('starts_at', '<', $max)->where('ends_at', '>', $min)->delete();
                foreach (array_chunk($list, 500) as $chunk) {
                    TvProgramme::insert($chunk);
                }
                $count += count($list);
            }
        });
        ActivityLogger::log('iptv', 'epg_import', "Programme guide imported: {$count} programmes on ".count($rows)." channel(s), {$skipped} skipped");

        return ['programmes' => $count, 'channels' => count($rows), 'skipped' => $skipped, 'linked' => $linked];
    }

    /** channel id → ['now' => TvProgramme, 'next' => TvProgramme] */
    public static function nowNext(): array
    {
        $out = [];
        $list = TvProgramme::where('ends_at', '>', now())->where('starts_at', '<', now()->addHours(12))->orderBy('starts_at')->get()->groupBy('tv_channel_id');
        foreach ($list as $cid => $ps) {
            $now = $ps->first(fn ($p) => $p->starts_at->lte(now()));
            $out[$cid] = ['now' => $now, 'next' => $ps->first(fn ($p) => $p->starts_at->gt(now()))];
        }

        return $out;
    }

    // ───────────────────────────── content: welcome templates & pages ─────────────────────────────

    public static function saveTemplate(array $d, ?TvWelcomeTemplate $t, User $by): TvWelcomeTemplate
    {
        self::need(self::canManage($by));
        self::need(filled($d['name'] ?? null), 'Name the welcome screen.');
        self::need(isset(TvLang::LANGS[$d['language'] ?? '']), 'Choose the language.');
        self::need(isset(TvWelcomeTemplate::SEGMENTS[$d['segment'] ?? '']), 'Choose which guests see it.');
        self::need(isset(TvWelcomeTemplate::THEMES[$d['theme'] ?? '']), 'Choose the background.');
        $head = trim((string) ($d['headline'] ?? ''));
        self::need($head !== '' && mb_strlen($head) <= 120, 'Write the headline (up to 120 characters).');
        $body = trim((string) ($d['body'] ?? ''));
        self::need(mb_strlen($body) <= 1000, 'Keep the text under 1,000 characters – it has to fit on the TV.');
        preg_match_all('/\{[a-z_]+\}/', $head.' '.$body, $m);
        $bad = array_diff(array_unique($m[0]), self::PLACEHOLDERS);
        self::need($bad === [], 'Unknown placeholder '.implode(', ', $bad).'. Use '.implode(' ', self::PLACEHOLDERS).'.');
        $from = filled($d['valid_from'] ?? null) ? Carbon::parse($d['valid_from'])->toDateString() : null;
        $to = filled($d['valid_to'] ?? null) ? Carbon::parse($d['valid_to'])->toDateString() : null;
        self::need(! $from || ! $to || $to >= $from, 'The end date must be on or after the start date.');
        $f = ['name' => mb_substr(trim($d['name']), 0, 80), 'language' => $d['language'], 'segment' => $d['segment'], 'priority' => max(0, min(100, (int) ($d['priority'] ?? 0))),
            'headline' => $head, 'body' => $body ?: null, 'theme' => $d['theme'], 'valid_from' => $from, 'valid_to' => $to, 'is_active' => ! empty($d['is_active'])];
        $t ? $t->update($f) : $t = TvWelcomeTemplate::create($f);
        ActivityLogger::log('iptv', 'template', "Welcome screen saved: {$t->name} ({$t->language}, ".TvWelcomeTemplate::SEGMENTS[$t->segment].')', $t);

        return $t;
    }

    public const PLACEHOLDERS = ['{name}', '{title}', '{first_name}', '{last_name}', '{room}', '{arrival}', '{departure}', '{nights}', '{hotel}', '{wifi_name}', '{wifi_password}', '{company}', '{group}', '{checkout_time}', '{desk_ext}'];

    public static function savePage(array $d, ?TvPage $p, User $by): TvPage
    {
        self::need(self::canManage($by));
        $title = trim((string) ($d['title'] ?? ''));
        self::need($title !== '', 'Enter the page title.');
        self::need(isset(TvPage::CATEGORIES[$d['category'] ?? '']), 'Choose the section.');
        self::need(in_array($d['icon'] ?? '', TvPage::ICONS, true), 'Choose an icon.');
        self::need(isset(TvLang::LANGS[$d['language'] ?? '']), 'Choose the language.');
        $body = trim((string) ($d['body'] ?? ''));
        self::need($body !== '' && mb_strlen($body) <= 3000, 'Write the page text (up to 3,000 characters).');
        $f = ['title' => mb_substr($title, 0, 80), 'category' => $d['category'], 'icon' => $d['icon'], 'language' => $d['language'], 'body' => $body,
            'sort' => max(0, min(999, (int) ($d['sort'] ?? 0))), 'is_active' => ! empty($d['is_active'])];
        $p ? $p->update($f) : $p = TvPage::create($f);
        self::flush();
        ActivityLogger::log('iptv', 'page', "TV information page saved: {$p->title}", $p);

        return $p;
    }

    /** pages in the guest's language, falling back to the default language */
    public static function pagesFor(string $lang): Collection
    {
        $all = TvPage::where('is_active', true)->orderBy('sort')->orderBy('title')->get();
        $mine = $all->where('language', $lang);

        return ($mine->isNotEmpty() ? $mine : $all->where('language', self::cfg()['default_lang']))->values();
    }

    // ───────────────────────────── data for the TV screens ─────────────────────────────

    public static function channelList(): array
    {
        $nn = self::nowNext();

        return TvChannel::where('is_active', true)->orderBy('number')->get()->map(fn ($c) => ['id' => $c->id, 'no' => $c->number, 'name' => $c->name, 'cat' => $c->category,
            'cat_label' => $c->categoryLabel(), 'lang' => $c->language, 'url' => $c->stream_url, 'radio' => $c->is_radio,
            'now' => ($p = $nn[$c->id]['now'] ?? null) ? ['title' => $p->title, 'from' => $p->starts_at->format('H:i'), 'to' => $p->ends_at->format('H:i'), 'desc' => $p->description] : null,
            'next' => ($p = $nn[$c->id]['next'] ?? null) ? ['title' => $p->title, 'from' => $p->starts_at->format('H:i')] : null])->all();
    }

    public static function movieList(ReservationRoom $stay): array
    {
        $rented = TvPurchase::where('reservation_room_id', $stay->id)->where('status', 'active')->where('expires_at', '>', now())->pluck('expires_at', 'tv_movie_id');

        return TvMovie::where('is_active', true)->when(! self::cfg()['adult'], fn ($q) => $q->where('is_adult', false))->orderBy('sort')->orderBy('title')->get()
            ->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'genre' => TvMovie::GENRES[$m->genre] ?? $m->genre, 'rating' => $m->rating, 'mins' => $m->duration_min,
                'year' => $m->year, 'lang' => $m->language, 'synopsis' => $m->synopsis, 'price' => money($m->gross()), 'hours' => $m->rental_hours, 'adult' => $m->is_adult,
                'rented' => isset($rented[$m->id]), 'left' => isset($rented[$m->id]) ? (int) ceil(now()->diffInMinutes($rented[$m->id]) / 60) : null,
                'url' => isset($rented[$m->id]) ? $m->stream_url : null, 'hue' => crc32($m->title) % 360])->all();
    }

    public static function diningMenu(): array
    {
        $outlet = GuestPortalService::diningOutlet();
        if (! $outlet) {
            return ['outlet' => null, 'cats' => []];
        }
        $cats = MenuCategory::where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->orderBy('sort_order')->get();
        $items = MenuItem::with(['modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->with('modifiers')])
            ->where('is_available', true)->whereIn('menu_category_id', $cats->pluck('id'))->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->orderBy('name')->get();

        return ['outlet' => $outlet->name, 'cats' => $cats->map(fn ($c) => ['name' => $c->name, 'items' => $items->where('menu_category_id', $c->id)->map(fn ($i) => ['id' => $i->id, 'name' => $i->name,
            'price' => money($i->price), 'raw' => (float) $i->price, 'veg' => $i->food_type === 'veg', 'image' => $i->imageUrl(),
            'modifiers' => $i->modifierGroups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'min' => $g->is_required ? max(1, $g->min_selections) : $g->min_selections, 'max' => $g->max_selections,
                'required' => $g->is_required, 'options' => $g->modifiers->where('is_available', true)->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_delta' => (float) $m->price_delta])->values()])->values()->all()])->values()->all()])->filter(fn ($c) => $c['items'] !== [])->values()->all()];
    }

    public static function serviceMenu(ReservationRoom $stay): array
    {
        return [
            'cats' => collect(self::TV_REQUESTS)->map(fn ($k) => ['key' => $k, 'label' => GuestRequest::CATEGORIES[$k][0], 'icon' => GuestRequest::CATEGORIES[$k][1],
                'items' => collect(GuestPortalService::items($k))->reject(fn ($i) => $i === 'Other')->values()->all()])->all(),
            'open' => GuestRequest::where('reservation_room_id', $stay->id)->latest('id')->limit(8)->get()
                ->map(fn ($r) => ['item' => $r->item, 'status' => GuestRequest::STATUSES[$r->status] ?? $r->status, 'at' => $r->created_at->format('d M H:i')])->all(),
            'wakeups' => \App\Models\WakeupCall::where('reservation_room_id', $stay->id)->where('status', 'scheduled')->orderBy('call_at')->limit(3)->get()
                ->map(fn ($w) => ['at' => $w->call_at->format('D d M H:i'), 'daily' => (bool) $w->daily])->all(),
        ];
    }

    public static function explorePlaces(): array
    {
        return ConciergePlace::where('portal_visible', true)->orderBy('sort_order')->orderBy('name')->limit(40)->get()
            ->map(fn ($p) => ['name' => $p->name, 'category' => $p->category, 'area' => $p->area, 'km' => $p->distance_km !== null ? (float) $p->distance_km : null,
                'hours' => $p->hours, 'text' => $p->description, 'tip' => $p->tip, 'phone' => $p->phone])->all();
    }

    // ───────────────────────────── PMS hooks & night audit ─────────────────────────────

    public static function onCheckIn(ReservationRoom $stay): void
    {
        self::pinFor($stay); // the desk hands the PIN over with the key card
    }

    public static function onCheckOut(ReservationRoom $stay): void
    {
        TvSession::where('reservation_room_id', $stay->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'checkout']);
        TvPurchase::where('reservation_room_id', $stay->id)->where('status', 'active')->where('expires_at', '>', now())->update(['expires_at' => now()]);
        TvMessage::where('reservation_room_id', $stay->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->update(['ends_at' => now()]);
        if (self::cfg()['reset_on_checkout'] && $stay->room_id) {
            foreach (TvDevice::where('room_id', $stay->room_id)->where('status', 'active')->get() as $d) {
                self::command($d, 'reset', [], null); // privacy: the next guest never sees this one's name, rentals or bill
            }
        }
    }

    public static function onRoomMove(ReservationRoom $stay, ?int $fromRoomId): void
    {
        if (! $fromRoomId) {
            return;
        }
        foreach (TvDevice::where('room_id', $fromRoomId)->where('status', 'active')->get() as $d) {
            TvSession::where('tv_device_id', $d->id)->where('reservation_room_id', $stay->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'room_move']);
            self::command($d, 'reset', [], null);
        }
    }

    public static function nightly(): array
    {
        $c = self::cfg();
        $out = [];
        $out['sessions'] = TvSession::whereNull('ended_at')->whereHas('stay', fn ($q) => $q->where('status', '!=', 'checked_in'))->update(['ended_at' => now(), 'end_reason' => 'checkout']);
        $out['pairing'] = TvDevice::where('status', 'pending')->where('pairing_expires_at', '<', now()->subDay())->delete();
        $out['commands'] = TvCommand::whereIn('status', ['queued', 'sent'])->where('created_at', '<', now()->subDay())->update(['status' => 'expired']);
        $out['programmes'] = TvProgramme::where('ends_at', '<', now()->subDays(2))->delete();
        $out['events'] = TvEvent::where('created_at', '<', now()->subDays($c['event_days']))->delete();

        return $out;
    }

    // ───────────────────────────── dashboard & analytics ─────────────────────────────

    public static function alerts(): array
    {
        $cut = now()->subMinutes(self::cfg()['offline_min']);
        $active = TvDevice::where('status', 'active');

        return [
            'offline' => (clone $active)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cut))->count(),
            'pending' => TvDevice::where('status', 'pending')->where('pairing_expires_at', '>', now())->count(),
            'emergency' => (bool) self::emergency(),
            'no_tv' => ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')->whereNotIn('room_id', (clone $active)->where('kind', 'room')->whereNotNull('room_id')->pluck('room_id'))->count(),
            'epg_stale' => TvChannel::where('is_active', true)->whereNotNull('epg_id')->whereDoesntHave('programmes', fn ($q) => $q->where('ends_at', '>', now()->addHours(6)))->count(),
        ];
    }

    public static function attentionCount(): int
    {
        if (! can_do('iptv.view') && ! can_do('iptv.manage') && ! can_do('iptv.message') && ! can_do('iptv.emergency')) {
            return 0;
        }
        $a = self::alerts();

        return $a['offline'] + ($a['emergency'] ? 1 : 0);
    }

    public static function stats(): array
    {
        $cut = now()->subMinutes(self::cfg()['offline_min']);
        $active = TvDevice::where('status', 'active');
        $inhouse = ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')->pluck('id');
        $mStart = now()->startOfMonth();
        $rent = TvPurchase::where('status', 'active')->where('starts_at', '>=', $mStart);

        return [
            'devices' => (clone $active)->count(), 'online' => (clone $active)->where('last_seen_at', '>=', $cut)->count(),
            'rooms' => Room::where('is_active', true)->count(), 'rooms_tv' => (clone $active)->where('kind', 'room')->whereNotNull('room_id')->distinct()->count('room_id'),
            'signage' => (clone $active)->where('kind', 'signage')->count(),
            'inhouse' => $inhouse->count(), 'welcomed' => TvSession::whereIn('reservation_room_id', $inhouse)->whereNotNull('welcomed_at')->distinct()->count('reservation_room_id'),
            'welcomes_today' => TvSession::where('welcomed_at', '>=', now()->startOfDay())->count(),
            'rentals' => (clone $rent)->count(), 'rental_rev' => (float) (clone $rent)->sum('amount'),
            'live_msgs' => TvMessage::where('status', 'active')->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->count(),
            'orders7' => PosOrder::where('source', 'tv')->where('created_at', '>=', now()->subDays(7))->count(),
            'requests7' => GuestRequest::where('source', 'tv')->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public static function analytics(string $from, string $to): array
    {
        [$a, $b] = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        $ev = TvEvent::whereBetween('created_at', [$a, $b]);
        $byEvent = (clone $ev)->selectRaw('event, COUNT(*) n')->groupBy('event')->pluck('n', 'event')->map(fn ($n) => (int) $n)->sortDesc()->all();
        $days = [];
        foreach ((clone $ev)->selectRaw('DATE(created_at) d, COUNT(*) n, COUNT(DISTINCT tv_device_id) tvs')->groupBy('d')->get() as $r) {
            $days[$r->d] = ['n' => (int) $r->n, 'tvs' => (int) $r->tvs];
        }
        $trend = [];
        for ($d = $a->copy(); $d->lte($b) && count($trend) < 92; $d->addDay()) {
            $trend[] = ['date' => $d->toDateString(), 'label' => $d->format('d M'), 'n' => $days[$d->toDateString()]['n'] ?? 0, 'tvs' => $days[$d->toDateString()]['tvs'] ?? 0];
        }
        $top = fn ($e) => (clone $ev)->where('event', $e)->whereNotNull('target')->selectRaw('target, COUNT(*) n')->groupBy('target')->orderByDesc('n')->limit(10)->pluck('n', 'target')->map(fn ($n) => (int) $n)->all();
        $rent = TvPurchase::whereBetween('starts_at', [$a, $b])->where('status', '!=', 'refunded');
        $orders = PosOrder::where('source', 'tv')->whereBetween('created_at', [$a, $b])->with('items')->get();
        $stays = ReservationRoom::whereIn('status', ['checked_in', 'checked_out'])->where('arrival_date', '<=', $b->toDateString())->where('departure_date', '>', $a->toDateString())->whereNotNull('checked_in_at')->count();
        $used = (clone $ev)->whereNotNull('reservation_room_id')->distinct()->count('reservation_room_id');
        $sent = TvMessage::whereBetween('starts_at', [$a, $b])->where('kind', '!=', 'emergency');

        return [
            'events' => array_sum($byEvent), 'by_event' => $byEvent, 'trend' => $trend, 'channels' => $top('channel'), 'pages' => $top('page'),
            'movies' => (clone $rent)->selectRaw('title, COUNT(*) n, SUM(amount) rev')->groupBy('title')->orderByDesc('n')->limit(10)->get()->map(fn ($r) => ['title' => $r->title, 'n' => (int) $r->n, 'rev' => (float) $r->rev])->all(),
            'rentals' => (clone $rent)->count(), 'rental_rev' => (float) (clone $rent)->sum('amount'),
            'orders' => $orders->count(), 'order_value' => round($orders->sum(fn ($o) => $o->items->where('is_cancelled', false)->sum('amount')), 2),
            'requests' => GuestRequest::where('source', 'tv')->whereBetween('created_at', [$a, $b])->selectRaw('category, COUNT(*) n')->groupBy('category')->pluck('n', 'category')->map(fn ($n) => (int) $n)->all(),
            'stays' => $stays, 'stays_used' => $used, 'adoption' => $stays ? round(min($used, $stays) / $stays * 100, 1) : null,
            'welcomes' => TvSession::whereBetween('welcomed_at', [$a, $b])->count(),
            'languages' => TvSession::whereBetween('started_at', [$a, $b])->selectRaw("COALESCE(language, '') l, COUNT(*) n")->groupBy('l')->pluck('n', 'l')->map(fn ($n) => (int) $n)->all(),
            'messages' => (clone $sent)->count(), 'reads' => TvMessageRead::whereIn('tv_message_id', (clone $sent)->pluck('id'))->count(),
        ];
    }
}
