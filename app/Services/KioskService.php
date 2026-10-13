<?php

namespace App\Services;

use App\Models\BusinessSource;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestFeedback;
use App\Models\Kiosk;
use App\Models\KioskSession;
use App\Models\OnlinePayment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v7.2 Self-service kiosk.
 *
 * A kiosk is a touch screen in the lobby, paired like a TV. A guest visit is a {@see KioskSession}; the kiosk calls one
 * action per screen and always gets back the whole next screen ({@see state()}), so the browser side stays simple and the
 * rules live here. Check-in reuses the desk's own services end to end – StayService::checkIn (folio, Form C, portal, TV,
 * key-card hooks), the online-payment links (the guest pays by scanning a QR code with mobile money / card), the
 * electronic registration card and guest documents. Whatever the kiosk cannot finish becomes an assistance request on
 * the front-desk dashboard – the guest is never left stuck.
 */
class KioskService
{
    public const DEFAULTS = [
        'kiosk_enabled' => '1', 'kiosk_checkin' => '1', 'kiosk_checkout' => '1', 'kiosk_walkin' => '1',
        'kiosk_checkin_from' => '12:00', 'kiosk_early' => '1', 'kiosk_room_ready' => 'clean', 'kiosk_room_choice' => '1',
        'kiosk_require_id' => '1', 'kiosk_require_sign' => '1', 'kiosk_deposit' => 'stay', 'kiosk_incidentals' => '0',
        'kiosk_max_keys' => '2', 'kiosk_vip_desk' => '0', 'kiosk_walkin_nights' => '7', 'kiosk_walkin_adults' => '3',
        'kiosk_early_checkout' => '0', 'kiosk_feedback' => '1', 'kiosk_idle' => '90', 'kiosk_pay_minutes' => '15',
        'kiosk_welcome' => 'Check in, check out or book a room in about two minutes.',
        'kiosk_terms' => 'I confirm the details above are correct. I accept the hotel\'s terms: check-out is by the time shown, I am responsible for all charges to my room, smoking in rooms is not permitted and the hotel may keep a copy of my identity document as the law requires.',
    ];

    public const DEPOSITS = ['none' => 'No deposit (pay at check-out)', 'first_night' => 'First night incl. taxes', 'stay' => 'Whole stay incl. taxes'];

    public const READY = ['clean' => 'Clean or inspected rooms', 'inspected' => 'Inspected rooms only'];

    /** minutes without a tap before a guest's visit is treated as walked away */
    public const SESSION_IDLE_MIN = 15;

    /** wrong booking look-ups a kiosk allows in 10 minutes (stops guessing surnames / references) */
    public const LOOKUP_LIMIT = 8;

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
            $from = preg_match('/^\d{2}:\d{2}$/', $s('kiosk_checkin_from')) ? $s('kiosk_checkin_from') : '12:00';

            return [
                'enabled' => $b('kiosk_enabled'), 'checkin' => $b('kiosk_checkin'), 'checkout' => $b('kiosk_checkout'), 'walkin' => $b('kiosk_walkin'),
                'checkin_from' => $from, 'early' => $b('kiosk_early'),
                'room_ready' => isset(self::READY[$s('kiosk_room_ready')]) ? $s('kiosk_room_ready') : 'clean', 'room_choice' => $b('kiosk_room_choice'),
                'require_id' => $b('kiosk_require_id'), 'require_sign' => $b('kiosk_require_sign'),
                'deposit' => isset(self::DEPOSITS[$s('kiosk_deposit')]) ? $s('kiosk_deposit') : 'stay',
                'incidentals' => max(0, min(100000, round((float) $s('kiosk_incidentals'), 2))),
                'max_keys' => max(1, min(4, (int) $s('kiosk_max_keys'))), 'vip_desk' => $b('kiosk_vip_desk'),
                'walkin_nights' => max(1, min(30, (int) $s('kiosk_walkin_nights'))), 'walkin_adults' => max(1, min(6, (int) $s('kiosk_walkin_adults'))),
                'early_checkout' => $b('kiosk_early_checkout'), 'feedback' => $b('kiosk_feedback'),
                'idle' => max(30, min(600, (int) $s('kiosk_idle'))), 'pay_minutes' => max(5, min(60, (int) $s('kiosk_pay_minutes'))),
                'welcome' => mb_substr($s('kiosk_welcome'), 0, 200), 'terms' => mb_substr($s('kiosk_terms'), 0, 1500),
            ];
        })();
    }

    public static function saveSettings(array $d, User $by): void
    {
        self::need(self::canManage($by));
        $bool = ['kiosk_enabled', 'kiosk_checkin', 'kiosk_checkout', 'kiosk_walkin', 'kiosk_early', 'kiosk_room_choice', 'kiosk_require_id', 'kiosk_require_sign',
            'kiosk_vip_desk', 'kiosk_early_checkout', 'kiosk_feedback'];
        self::need(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($d['kiosk_checkin_from'] ?? '')) === 1, 'Enter the self check-in time as HH:MM.');
        self::need(isset(self::DEPOSITS[$d['kiosk_deposit'] ?? '']), 'Choose what the kiosk collects at check-in.');
        self::need(isset(self::READY[$d['kiosk_room_ready'] ?? '']), 'Choose which rooms the kiosk may hand out.');
        foreach (['kiosk_incidentals' => [0, 100000, 'The incidentals deposit is GH₵ 0 to 100,000.'], 'kiosk_max_keys' => [1, 4, 'Guests can encode 1 to 4 key cards.'],
            'kiosk_walkin_nights' => [1, 30, 'Walk-ins can book 1 to 30 nights.'], 'kiosk_walkin_adults' => [1, 6, 'Walk-in rooms take 1 to 6 adults.'],
            'kiosk_idle' => [30, 600, 'The screen resets after 30 to 600 seconds without a tap.'], 'kiosk_pay_minutes' => [5, 60, 'A payment QR code lasts 5 to 60 minutes.']] as $k => [$lo, $hi, $msg]) {
            self::need(is_numeric($d[$k] ?? null) && $d[$k] >= $lo && $d[$k] <= $hi, $msg);
        }
        $welcome = trim((string) ($d['kiosk_welcome'] ?? ''));
        $terms = trim((string) ($d['kiosk_terms'] ?? ''));
        self::need($terms !== '', 'Enter the registration terms the guest signs.');
        $vals = ['kiosk_checkin_from' => $d['kiosk_checkin_from'], 'kiosk_deposit' => $d['kiosk_deposit'], 'kiosk_room_ready' => $d['kiosk_room_ready'],
            'kiosk_incidentals' => (string) round((float) $d['kiosk_incidentals'], 2), 'kiosk_welcome' => mb_substr($welcome, 0, 200) ?: self::DEFAULTS['kiosk_welcome'], 'kiosk_terms' => mb_substr($terms, 0, 1500)];
        foreach (['kiosk_max_keys', 'kiosk_walkin_nights', 'kiosk_walkin_adults', 'kiosk_idle', 'kiosk_pay_minutes'] as $k) {
            $vals[$k] = (string) (int) $d[$k];
        }
        foreach ($bool as $k) {
            $vals[$k] = ! empty($d[$k]) ? '1' : '0';
        }
        foreach ($vals as $k => $v) {
            \App\Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        \App\Models\Setting::$version++;
        self::flush();
        ActivityLogger::log('kiosk', 'settings', 'Self-service kiosk settings updated');
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('kiosk.manage');
    }

    public static function canAssist(?User $u): bool
    {
        return (bool) ($u?->hasPermission('kiosk.assist') || $u?->hasPermission('kiosk.manage'));
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    // ───────────────────────────── kiosks (devices) ─────────────────────────────

    protected static function kioskFields(array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '', 'Give the kiosk a name.');

        return ['name' => mb_substr($name, 0, 80), 'location' => mb_substr(trim((string) ($d['location'] ?? '')), 0, 80) ?: null,
            'serial' => mb_substr(trim((string) ($d['serial'] ?? '')), 0, 60) ?: null, 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
            'allow_checkin' => ! empty($d['allow_checkin']), 'allow_checkout' => ! empty($d['allow_checkout']), 'allow_walkin' => ! empty($d['allow_walkin']),
            'has_encoder' => ! empty($d['has_encoder']), 'has_camera' => ! empty($d['has_camera'])];
    }

    public static function saveKiosk(array $d, ?Kiosk $k, User $by): Kiosk
    {
        self::need(self::canManage($by));
        $f = self::kioskFields($d);
        if ($k) {
            $k->update($f);
            ActivityLogger::log('kiosk', 'update', "Kiosk {$k->name} updated", $k);

            return $k->fresh();
        }
        $k = Kiosk::create($f + ['token' => self::newToken(), 'status' => 'active', 'paired_at' => now(), 'paired_by' => $by->id]);
        ActivityLogger::log('kiosk', 'add', "Kiosk {$k->name} added", $k);

        return $k;
    }

    /** a new kiosk opened the hotel's setup page: it gets a secret token and shows a 6-digit pairing code for 15 minutes */
    public static function startPairing(?string $ip, ?string $ua): Kiosk
    {
        self::need(self::cfg()['enabled'], 'Self-service kiosks are switched off.');
        $open = Kiosk::where('status', 'pending')->where('pairing_expires_at', '>', now());
        self::need((clone $open)->count() < 10, 'Too many kiosks are waiting to be paired – pair or wait for the codes to expire.');
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while ((clone $open)->where('pairing_code', $code)->exists());

        return Kiosk::create(['name' => 'New kiosk', 'token' => self::newToken(), 'status' => 'pending', 'pairing_code' => $code, 'pairing_expires_at' => now()->addMinutes(15),
            'last_seen_at' => now(), 'last_ip' => $ip, 'user_agent' => mb_substr((string) $ua, 0, 190)]);
    }

    public static function claim(string $code, array $d, User $by): Kiosk
    {
        self::need(self::canManage($by));
        $code = preg_replace('/\D/', '', $code);
        $k = strlen($code) === 6 ? Kiosk::where('status', 'pending')->where('pairing_code', $code)->where('pairing_expires_at', '>', now())->first() : null;
        self::need((bool) $k, 'No kiosk is showing that code. Check the code on the screen – codes last 15 minutes.');
        $k->update(self::kioskFields($d) + ['status' => 'active', 'pairing_code' => null, 'pairing_expires_at' => null, 'paired_at' => now(), 'paired_by' => $by->id]);
        ActivityLogger::log('kiosk', 'pair', "Kiosk paired: {$k->name}", $k);

        return $k->fresh();
    }

    public static function rotate(Kiosk $k, User $by): Kiosk
    {
        self::need(self::canManage($by));
        $k->forceFill(['token' => self::newToken()])->save();
        KioskSession::where('kiosk_id', $k->id)->where('status', 'active')->update(['status' => 'cancelled', 'updated_at' => now()]);
        ActivityLogger::log('kiosk', 'rotate', "Kiosk {$k->name}: start address changed (old link no longer works)", $k);

        return $k;
    }

    public static function setStatus(Kiosk $k, bool $active, User $by): Kiosk
    {
        self::need(self::canManage($by));
        self::need($k->status !== 'pending', 'Pair the kiosk first.');
        $k->update(['status' => $active ? 'active' : 'disabled']);
        if (! $active) {
            KioskSession::where('kiosk_id', $k->id)->where('status', 'active')->update(['status' => 'cancelled', 'updated_at' => now()]);
        }
        ActivityLogger::log('kiosk', $active ? 'enable' : 'disable', "Kiosk {$k->name} ".($active ? 'switched on' : 'taken out of service'), $k);

        return $k;
    }

    public static function deleteKiosk(Kiosk $k, User $by): void
    {
        self::need(self::canManage($by));
        self::need(! $k->sessions()->exists(), "Kiosk {$k->name} has guest visits on record – take it out of service instead.");
        ActivityLogger::log('kiosk', 'delete', "Kiosk {$k->name} removed", $k);
        $k->delete();
    }

    public static function heartbeat(Kiosk $k, ?string $ip, ?string $ua): void
    {
        if (! $k->last_seen_at || $k->last_seen_at->lt(now()->subSeconds(20)) || $k->last_ip !== $ip) {
            $k->forceFill(['last_seen_at' => now(), 'last_ip' => $ip, 'user_agent' => mb_substr((string) $ua, 0, 190)])->saveQuietly();
            self::sweep(); // at most every 20 s per kiosk: visits left idle are closed even when nobody opens the dashboard
        }
    }

    /** what the kiosk offers right now (home screen) */
    public static function offer(Kiosk $k): array
    {
        $c = self::cfg();
        $on = $c['enabled'] && $k->status === 'active';
        $walkin = $on && $c['walkin'] && $k->allow_walkin && OnlinePaymentService::enabled();

        return ['checkin' => $on && $c['checkin'] && $k->allow_checkin, 'checkout' => $on && $c['checkout'] && $k->allow_checkout, 'walkin' => $walkin];
    }

    // ───────────────────────────── guest visits ─────────────────────────────

    public static function start(Kiosk $k, string $kind): KioskSession
    {
        self::need(isset(KioskSession::KINDS[$kind]), 'Choose check-in, check-out or book a room.');
        self::need((bool) (self::offer($k)[$kind] ?? false), 'This service is not available on this kiosk right now – please see the front desk.');
        self::sweep();
        KioskSession::where('kiosk_id', $k->id)->where('status', 'active')->get()->each(fn ($s) => self::close($s, 'abandoned', 'A new guest started'));
        $s = KioskSession::create(['kiosk_id' => $k->id, 'number' => Numbering::next('kiosk_session'), 'token' => self::newToken(), 'kind' => $kind, 'status' => 'active',
            'step' => $kind === 'walkin' ? 'rooms' : 'find', 'started_at' => now(), 'last_activity_at' => now()]);
        self::trail($s, 'start', KioskSession::KINDS[$kind]);

        return $s;
    }

    /** the kiosk's current visit – only while it is running */
    public static function session(Kiosk $k, string $token): KioskSession
    {
        $gone = fn () => throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(KioskSession::class); // → 404: the kiosk goes home
        $s = KioskSession::where('kiosk_id', $k->id)->where('token', $token)->first() ?? $gone();
        if ($s->status === 'active' && $s->last_activity_at && $s->last_activity_at->lt(now()->subMinutes(self::SESSION_IDLE_MIN))) {
            self::close($s, 'abandoned', 'Timed out');
        }
        $s->status === 'active' || $gone();

        return $s;
    }

    public static function trail(KioskSession $s, string $step, ?string $note = null): void
    {
        $t = $s->trail ?? [];
        $t[] = [now()->format('H:i:s'), $step, $note !== null ? mb_substr($note, 0, 180) : null];
        $s->forceFill(['trail' => array_slice($t, -60), 'last_activity_at' => now()])->save();
    }

    protected static function go(KioskSession $s, string $step, ?string $note = null): void
    {
        $s->forceFill(['step' => $step])->save();
        self::trail($s, $step, $note);
    }

    /** guest pressed "Start over" / the screen timed out */
    public static function cancel(KioskSession $s): void
    {
        self::close($s, 'cancelled', 'Guest started over');
    }

    protected static function close(KioskSession $s, string $status, string $note): void
    {
        if ($s->kind === 'walkin' && $s->reservation_room_id && in_array($status, ['cancelled', 'abandoned'], true)) {
            $stay = ReservationRoom::find($s->reservation_room_id);
            if ($stay && $stay->status === 'reserved') {
                if ((float) $s->amount_paid > 0 || ($s->payment && $s->payment->status === 'paid')) {
                    self::assist($s, 'payment', 'Walk-in paid at the kiosk but did not finish check-in – check the guest in at the desk.');

                    return;
                }
                self::dropWalkin($stay, 'Walk-in not completed at the kiosk');
            }
        }
        if ($s->payment && $s->payment->status === 'pending') {
            rescue(fn () => OnlinePaymentService::cancel($s->payment, 'Kiosk visit ended'), null, false);
        }
        $s->forceFill(['status' => $status, 'completed_at' => now()])->save();
        self::trail($s, $status, $note);
    }

    protected static function dropWalkin(ReservationRoom $stay, string $reason): void
    {
        $r = $stay->reservation;
        $stay->update(['status' => 'cancelled']);
        $r->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        ActivityLogger::log('kiosk', 'walkin_drop', "Walk-in {$r->reservation_no} released – {$reason}", $r);
    }

    /** close visits nobody touched for SESSION_IDLE_MIN minutes */
    public static function sweep(): int
    {
        $n = 0;
        KioskSession::where('status', 'active')->where('last_activity_at', '<', now()->subMinutes(self::SESSION_IDLE_MIN))->get()
            ->each(function ($s) use (&$n) {
                self::close($s, 'abandoned', 'Timed out');
                $n++;
            });

        return $n;
    }

    /** anything the kiosk cannot finish goes to the front desk */
    public static function assist(KioskSession $s, string $reason, ?string $note = null): KioskSession
    {
        $reason = isset(KioskSession::ASSIST_REASONS[$reason]) ? $reason : 'guest';
        if ($s->payment && $s->payment->status === 'pending' && $reason !== 'payment') {
            rescue(fn () => OnlinePaymentService::cancel($s->payment, 'Kiosk visit handed to the desk'), null, false);
        }
        $s->forceFill(['status' => 'assist', 'assist_reason' => $reason, 'assist_note' => $note !== null ? mb_substr($note, 0, 255) : null, 'assist_at' => now(), 'completed_at' => now()])->save();
        self::trail($s, 'assist', KioskSession::ASSIST_REASONS[$reason].($note ? ' – '.$note : ''));
        $s->loadMissing('kiosk', 'guest', 'room');
        ActivityLogger::log('kiosk', 'assist', "Kiosk {$s->kiosk?->name} needs the desk: ".KioskSession::ASSIST_REASONS[$reason]
            .($s->guest ? " ({$s->guest->full_name})" : '').($note ? " – {$note}" : ''), $s);

        return $s;
    }

    public static function resolve(KioskSession $s, ?string $note, User $by): KioskSession
    {
        self::need(self::canAssist($by));
        self::need($s->status === 'assist' && ! $s->resolved_at, 'That request has already been dealt with.');
        $s->forceFill(['resolved_by' => $by->id, 'resolved_at' => now(), 'resolution' => mb_substr(trim((string) $note), 0, 255) ?: 'Guest helped at the desk'])->save();
        self::trail($s, 'resolved', $by->name.': '.$s->resolution);
        ActivityLogger::log('kiosk', 'resolve', "Kiosk request {$s->number} dealt with – {$s->resolution}", $s);

        return $s;
    }

    public static function attentionCount(): int
    {
        return (int) (self::$memo['att'.Tenant::id()] ??= KioskSession::where('status', 'assist')->whereNull('resolved_at')->count()
            + Kiosk::where('status', 'active')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(Kiosk::OFFLINE_MIN)))->count());
    }

    // ───────────────────────────── finding the booking ─────────────────────────────

    protected static function norm(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii((string) $s)));
    }

    protected static function lookupKey(KioskSession $s): string
    {
        return 'kiosk-find:'.$s->hotel_id.':'.$s->kiosk_id;
    }

    protected static function missed(KioskSession $s, string $what): never
    {
        RateLimiter::hit(self::lookupKey($s), 600);
        $s->forceFill(['failed_lookups' => $s->failed_lookups + 1])->save();
        self::trail($s, 'find', 'Not found: '.$what);
        if ($s->failed_lookups >= 3) {
            self::assist($s, 'not_found', "Three look-ups did not match ({$what})");
            throw new RuntimeException('ASSIST:We could not find your booking. A member of our team is on the way to help you.');
        }
        throw new RuntimeException('We could not find a booking with those details. Please check and try again.');
    }

    /**
     * Check-in: reservation number (or OTA reference) + surname, or the QR code from the pre-arrival e-mail.
     * Check-out: room number + surname, or reservation number + surname.
     */
    public static function find(KioskSession $s, array $d): KioskSession
    {
        self::need(in_array($s->kind, ['checkin', 'checkout'], true) && $s->step === 'find', 'This step is finished.');
        self::need(! RateLimiter::tooManyAttempts(self::lookupKey($s), self::LOOKUP_LIMIT), 'Too many attempts on this kiosk. Please see the front desk.');
        $qr = trim((string) ($d['qr'] ?? ''));
        $ref = trim((string) ($d['ref'] ?? ''));
        $last = self::norm($d['last_name'] ?? '');
        if ($qr !== '') {
            $token = preg_match('~([A-Za-z0-9]{40})/?$~', $qr, $m) ? $m[1] : '';
            $r = $token !== '' ? Reservation::where('checkin_token', $token)->first() : null;
            $r || self::missed($s, 'QR code');
            $stay = self::stayFor($s, $r, null);
            $stay || self::missed($s, 'QR code');
            $lookup = 'qr';
        } else {
            self::need($ref !== '' && $last !== '', $s->kind === 'checkout' ? 'Enter your room or booking number and your surname.' : 'Enter your booking number and your surname.');
            $r = null;
            $stay = null;
            if ($s->kind === 'checkout' && preg_match('/^\d{1,5}[A-Za-z]?$/', $ref)) {
                $room = Room::where('room_no', $ref)->first();
                $stay = $room ? ReservationRoom::with('reservation.guest', 'guest')->where('room_id', $room->id)->where('status', 'checked_in')->first() : null;
                $r = $stay?->reservation;
                $lookup = 'room';
            }
            if (! $r) {
                $num = mb_strtoupper(preg_replace('/\s+/', '', $ref));
                // forgiving: "RES-00073", "res 00073", "RES73", "73" all find RES-00073
                $short = preg_match('/^(?:RES)?[-_.\/]*0*(\d{1,7})$/', $num, $mm) ? 'RES-'.str_pad($mm[1], 5, '0', STR_PAD_LEFT) : '#';
                $r = Reservation::with('guest')->where(fn ($q) => $q->where('reservation_no', $num)->orWhere('ota_uid', $ref)->orWhere('reservation_no', $short))->first();
                $lookup = 'reference';
                $stay = $r ? self::stayFor($s, $r, null) : null;
            }
            $names = collect([$r?->guest?->last_name, $stay?->guest?->last_name])->filter()->map(fn ($n) => self::norm($n));
            ($r && $stay && $names->contains($last)) || self::missed($s, $ref);
        }
        RateLimiter::clear(self::lookupKey($s));
        $g = $stay->guest ?? $r->guest;
        $s->forceFill(['reservation_id' => $r->id, 'reservation_room_id' => $stay->id, 'guest_id' => $g?->id, 'lookup' => $lookup, 'room_id' => $stay->room_id])->save();
        self::trail($s, 'found', $r->reservation_no.' · '.($g?->full_name ?? 'guest'));

        return self::advance($s->fresh());
    }

    /** the stay this visit is about – check-in: today's arrival on the booking; check-out: the in-house stay */
    protected static function stayFor(KioskSession $s, Reservation $r, ?int $roomId): ?ReservationRoom
    {
        $q = ReservationRoom::with('guest', 'reservation.guest', 'room', 'roomType')->where('reservation_id', $r->id)->orderBy('id');
        if ($s->kind === 'checkout') {
            return $q->where('status', 'checked_in')->first();
        }

        return (clone $q)->where('status', 'reserved')->first() ?? $q->whereIn('status', ['checked_in', 'checked_out'])->first();
    }

    /** null if the guest may check themselves in, otherwise [assist reason, message for the guest] */
    public static function checkinBlock(ReservationRoom $stay, ?Kiosk $k = null): ?array
    {
        $r = $stay->reservation;
        $c = self::cfg();
        $g = $stay->guest ?? $r->guest;
        if ($stay->status === 'checked_in') {
            return ['stop', 'You are already checked in'.($stay->room ? ' to room '.$stay->room->room_no : '').'. Enjoy your stay!'];
        }
        if ($stay->status !== 'reserved' || in_array($r->status, ['cancelled', 'no_show', 'checked_out'], true)) {
            return ['not_eligible', 'This booking cannot be checked in here. Our team will help you.'];
        }
        if ($stay->arrival_date->gt(bdate())) {
            return ['stop', 'Your booking starts on '.$stay->arrival_date->format('l j F').'. We look forward to welcoming you then.'];
        }
        if ($stay->departure_date->lte(bdate())) {
            return ['not_eligible', 'The dates on this booking have passed. Our team will help you.'];
        }
        if (ReservationRoom::where('reservation_id', $r->id)->where('status', 'reserved')->whereDate('arrival_date', '<=', bdate()->toDateString())->count() > 1) {
            return ['multi', 'Your booking has several rooms – our team will check you all in together.'];
        }
        if ($c['vip_desk'] && $g?->is_vip) {
            return ['vip', 'Welcome back! Our team would love to welcome you personally – someone is on the way.'];
        }
        if (! $c['early'] && now()->format('H:i') < $c['checkin_from']) {
            return ['stop', 'Self check-in opens at '.$c['checkin_from'].'. Our front desk can help you before then.'];
        }

        return null;
    }

    public static function checkoutBlock(ReservationRoom $stay): ?array
    {
        if ($stay->status !== 'checked_in') {
            return ['stop', 'This room is already checked out. Thank you for staying with us!'];
        }
        if ($stay->departure_date->gt(bdate()) && ! self::cfg()['early_checkout']) {
            return ['not_eligible', 'You are booked until '.$stay->departure_date->format('l j F').'. To leave early, our team will adjust your bill.'];
        }

        return null;
    }

    // ───────────────────────────── the flow ─────────────────────────────

    public static function stay(KioskSession $s): ReservationRoom
    {
        $stay = $s->reservation_room_id ? ReservationRoom::with('guest', 'reservation.guest', 'room.floor', 'roomType')->find($s->reservation_room_id) : null;
        self::need((bool) $stay, 'Find your booking first.');

        return $stay;
    }

    public static function guestOf(ReservationRoom $stay): ?Guest
    {
        return $stay->guest ?? $stay->reservation?->guest;
    }

    protected static function hasIdDoc(ReservationRoom $stay): bool
    {
        $g = self::guestOf($stay);

        return $g && $g->documents()->whereIn('type', ['id_front', 'photo'])->where(fn ($q) => $q->where('reservation_room_id', $stay->id)
            ->orWhere('created_at', '>=', now()->subYear()))->exists();
    }

    /** work out the next screen after each step */
    public static function advance(KioskSession $s): KioskSession
    {
        $c = self::cfg();
        $k = $s->kiosk;
        if ($s->kind === 'checkout') {
            $stay = self::stay($s);
            if ($s->step === 'find') {
                if ($b = self::checkoutBlock($stay)) {
                    return self::blocked($s, $b);
                }
                self::completeBill($stay);
                self::go($s, 'bill');
            }

            return $s;
        }
        // check-in and walk-in (after booking) share the same path
        $stay = self::stay($s);
        if ($stay->status === 'reserved' && ($b = self::checkinBlock($stay, $k))) {
            return self::blocked($s, $b);
        }
        $g = self::guestOf($stay);
        $r = $stay->reservation;
        $pre = (bool) $r->prechecked_in_at;
        if ($stay->status === 'reserved') {
            if (! $s->details_ok) {
                if ($pre && $g?->id_number && $g?->phone) {
                    $s->forceFill(['details_ok' => true])->save();
                    self::trail($s, 'details', 'Completed online before arrival');
                } else {
                    return self::moveTo($s, 'details');
                }
            }
            if (! $s->id_captured) {
                if (self::hasIdDoc($stay) || ! $c['require_id'] || ! $k->has_camera) {
                    $s->forceFill(['id_captured' => self::hasIdDoc($stay)])->save();
                } else {
                    return self::moveTo($s, 'document');
                }
            }
            if (! $s->signed) {
                if ($stay->grc_signature_path || ! $c['require_sign']) {
                    $s->forceFill(['signed' => (bool) $stay->grc_signature_path])->save();
                } else {
                    return self::moveTo($s, 'sign');
                }
            }
            $due = self::due($s);
            if ($due['due'] > 0.009) {
                return self::moveTo($s, 'pay');
            }
            if ($s->step === 'pay') {
                self::trail($s, 'pay', 'Nothing more to pay');
            }

            return self::moveTo($s, 'room');
        }
        // checked in
        if ($s->step !== 'keys' && $s->step !== 'done' && $k->has_encoder && InterfaceService::cfg()['lock_enabled'] === '1') {
            return self::moveTo($s, 'keys');
        }

        return self::finish($s);
    }

    protected static function moveTo(KioskSession $s, string $step): KioskSession
    {
        if ($s->step !== $step) {
            self::go($s, $step);
        }

        return $s;
    }

    protected static function blocked(KioskSession $s, array $b): KioskSession
    {
        [$reason, $msg] = $b;
        if ($reason === 'stop') {
            $s->forceFill(['status' => 'cancelled', 'completed_at' => now(), 'assist_note' => mb_substr($msg, 0, 255)])->save();
            self::trail($s, 'stop', $msg);
            throw new RuntimeException('STOP:'.$msg);
        }
        self::assist($s, $reason, $msg);
        throw new RuntimeException('ASSIST:'.$msg);
    }

    protected static function mask(?string $v, string $kind): ?string
    {
        if (! $v) {
            return null;
        }
        if ($kind === 'email' && str_contains($v, '@')) {
            [$u, $d] = explode('@', $v, 2);

            return mb_substr($u, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($u) - 1))).'@'.$d;
        }
        $digits = preg_replace('/\s+/', '', $v);

        return str_repeat('•', max(3, mb_strlen($digits) - 4)).mb_substr($digits, -4);
    }

    /** the guest confirms / completes their details (blank = keep what the hotel has) */
    public static function details(KioskSession $s, array $d): KioskSession
    {
        self::need($s->step === 'details', 'This step is finished.');
        $stay = self::stay($s);
        $g = self::guestOf($stay);
        self::need((bool) $g, 'Your guest profile is missing – please see the front desk.');
        $clean = fn ($k, $max) => mb_substr(trim(strip_tags((string) ($d[$k] ?? ''))), 0, $max);
        $f = [];
        foreach (['phone' => 30, 'email' => 150, 'nationality' => 60, 'id_number' => 60, 'passport_no' => 40] as $k => $max) {
            if (($v = $clean($k, $max)) !== '') {
                $f[$k] = $v;
            }
        }
        if (! empty($d['id_type'])) {
            self::need(isset(config('pms.id_types')[$d['id_type']]), 'Choose your ID type.');
            $f['id_type'] = $d['id_type'];
        }
        if (isset($f['email'])) {
            self::need(filter_var($f['email'], FILTER_VALIDATE_EMAIL) !== false, 'Check your e-mail address.');
        }
        if (isset($f['phone'])) {
            self::need(preg_match('/^\+?[0-9 ()\-]{7,20}$/', $f['phone']) === 1, 'Check your phone number.');
        }
        $merged = array_merge($g->only(['phone', 'email', 'nationality', 'id_type', 'id_number', 'passport_no']), $f);
        self::need(filled($merged['phone']), 'Enter your mobile number.');
        self::need(filled($merged['nationality']), 'Enter your nationality.');
        self::need(filled($merged['id_type']) && filled($merged['id_number']), 'Enter your ID type and number.');
        $foreign = FormCService::enabled() && FormCService::isForeignNationality($merged['nationality']) === true; // extra visitor fields only when Form C reporting is on
        $prefill = [];
        if ($foreign) {
            self::need(filled($merged['passport_no']) || $merged['id_type'] === 'passport', 'Enter your passport number.');
            if ($merged['id_type'] === 'passport' && ! filled($merged['passport_no'])) {
                $f['passport_no'] = $merged['id_number'];
            }
            $exp = trim((string) ($d['passport_expires_on'] ?? ''));
            self::need($exp !== '' && strtotime($exp) && Carbon::parse($exp)->gt(bdate()), 'Enter your passport expiry date – the passport must be valid.');
            $prefill['passport_expires_on'] = Carbon::parse($exp)->toDateString();
            foreach (['visa_no' => 30, 'arrived_from' => 120, 'next_destination' => 120] as $k => $max) {
                if (($v = $clean($k, $max)) !== '') {
                    $prefill[$k] = $v;
                }
            }
            if (! empty($d['purpose']) && isset(\App\Models\FormCRecord::PURPOSES[$d['purpose']])) {
                $prefill['purpose'] = $d['purpose'];
            }
            if (isset($prefill['visa_no'])) {
                self::need(preg_match('/^[A-Za-z0-9\- ]{4,30}$/', $prefill['visa_no']) === 1, 'The visa number may only contain letters, digits and dashes.');
            }
        }
        DB::transaction(function () use ($g, $f, $stay, $prefill) {
            if ($f) {
                $g->fill($f)->save();
            }
            if ($prefill) {
                $r = $stay->reservation;
                $r->forceFill(['formc_prefill' => array_merge((array) $r->formc_prefill, $prefill)])->save();
            }
        });
        $s->forceFill(['details_ok' => true])->save();
        self::trail($s, 'details', $f ? 'Updated: '.implode(', ', array_keys($f)) : 'Confirmed');

        return self::advance($s->fresh());
    }

    /** photo of the ID document from the kiosk camera (data URL) or its document scanner (file) */
    public static function document(KioskSession $s, ?UploadedFile $file, ?string $dataUrl): KioskSession
    {
        self::need($s->step === 'document', 'This step is finished.');
        $stay = self::stay($s);
        $g = self::guestOf($stay);
        if ($file) {
            $ext = strtolower($file->guessExtension() ?: '');
            self::need(in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $file->getSize() <= 6 * 1024 * 1024, 'Use a JPG or PNG photo up to 6 MB.');
            $bin = file_get_contents($file->getRealPath());
        } else {
            self::need(is_string($dataUrl) && preg_match('~^data:image/(jpeg|png|webp);base64,~', $dataUrl, $m) === 1, 'Take a photo of your ID.');
            self::need(strlen($dataUrl) < 8 * 1024 * 1024, 'The photo is too large.');
            $bin = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        }
        $info = $bin ? @getimagesizefromstring($bin) : false;
        self::need($info !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) && $info[0] >= 200 && $info[1] >= 120 && strlen($bin) > 2000,
            'The photo could not be read. Hold your ID flat in the frame and try again.');
        $path = 'guest-docs/'.$g->hotel_id.'/'.$g->id.'/'.Str::random(32).'.'.$ext;
        Storage::disk('local')->put($path, $bin);
        $g->documents()->create(['type' => 'id_front', 'reservation_room_id' => $stay->id, 'path' => $path, 'original_name' => 'Kiosk ID photo – '.$s->number,
            'mime' => $info['mime'], 'size' => strlen($bin), 'uploaded_by' => null]);
        $s->forceFill(['id_captured' => true])->save();
        self::trail($s, 'document', "ID photo {$info[0]}×{$info[1]}");

        return self::advance($s->fresh());
    }

    /** the electronic registration card (GRC), signed with a finger */
    public static function sign(KioskSession $s, ?string $dataUrl, bool $accepted): KioskSession
    {
        self::need($s->step === 'sign', 'This step is finished.');
        self::need($accepted, 'Please tick to accept the registration terms.');
        self::need(is_string($dataUrl) && str_starts_with($dataUrl, 'data:image/png;base64,') && strlen($dataUrl) < 700000, 'Please sign in the box.');
        $sig = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        $info = $sig ? @getimagesizefromstring($sig) : false;
        self::need($info !== false && $info[2] === IMAGETYPE_PNG && $info[0] <= 2000 && $info[1] <= 1000 && strlen($sig) >= 500, 'The signature could not be read. Please sign again.');
        $stay = self::stay($s);
        $path = 'grc/'.$stay->hotel_id.'/'.$stay->id.'-'.Str::random(12).'.png';
        Storage::disk('local')->put($path, $sig);
        if ($stay->grc_signature_path) {
            Storage::disk('local')->delete($stay->grc_signature_path);
        }
        $stay->forceFill(['grc_signature_path' => $path, 'grc_signed_at' => now()])->save();
        $s->forceFill(['signed' => true])->save();
        self::trail($s, 'sign', 'Registration card signed');

        return self::advance($s->fresh());
    }

    /** what the guest pays before getting a key */
    public static function due(KioskSession $s): array
    {
        $stay = self::stay($s);
        $r = $stay->reservation;
        $c = self::cfg();
        $nights = max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date));
        $rate = (float) $stay->rate + (float) $stay->extra_charge;
        $nightly = round($rate + (float) TaxService::roomTax((float) $stay->rate)['amount'], 2);
        $company = (bool) $r->company_id;
        $mode = $s->kind === 'walkin' ? 'stay' : $c['deposit'];
        $room = $company ? 0 : match ($mode) { 'stay' => $nightly * $nights, 'first_night' => $nightly, default => 0 };
        $required = round($room + $c['incidentals'], 2);
        $paid = OnlinePaymentService::reservationPaid($r);
        $lines = [];
        if ($room > 0) {
            $lines[] = [$mode === 'stay' ? "Room – {$nights} night".($nights > 1 ? 's' : '').' incl. taxes' : 'First night incl. taxes', round($room, 2)];
        }
        if ($c['incidentals'] > 0) {
            $lines[] = ['Incidentals deposit (refundable)', $c['incidentals']];
        }
        if ($paid > 0) {
            $lines[] = ['Already paid', -$paid];
        }

        return ['nights' => $nights, 'nightly' => $nightly, 'required' => $required, 'paid' => $paid, 'due' => round(max(0, $required - $paid), 2), 'lines' => $lines, 'company' => $company];
    }

    protected static function qr(string $url): string
    {
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);

        return (new \chillerlan\QRCode\QRCode($opts))->render($url);
    }

    /** create (or reuse) the payment link the guest scans; check-in = deposit on the booking, check-out = the bill balance */
    public static function pay(KioskSession $s): OnlinePayment
    {
        self::need(in_array($s->step, ['pay', 'bill'], true), 'Nothing to pay at this step.');
        self::need(OnlinePaymentService::enabled(), 'Payment on the kiosk is not available right now.');
        $p = $s->payment;
        if ($p && $p->status === 'pending' && $p->isPayable()) {
            return $p;
        }
        $minutes = self::cfg()['pay_minutes'];
        $stay = self::stay($s);
        if ($s->kind === 'checkout') {
            self::completeBill($stay);
            $f = self::mainFolio($stay);
            $bal = $f ? round($f->balance(), 2) : 0;
            self::need($bal > 0.009, 'Your bill is already settled.');
            $p = OnlinePaymentService::create(['amount' => $bal, 'purpose' => 'balance', 'minutes' => $minutes, 'description' => 'Kiosk check-out – room '.$stay->room?->room_no], null, $f);
        } else {
            $due = self::due($s)['due'];
            self::need($due > 0.009, 'Nothing more to pay.');
            $p = OnlinePaymentService::create(['amount' => $due, 'purpose' => 'deposit', 'minutes' => $minutes,
                'description' => ($s->kind === 'walkin' ? 'Walk-in stay ' : 'Check-in deposit ').$stay->reservation->reservation_no], $stay->reservation);
        }
        $s->forceFill(['online_payment_id' => $p->id, 'amount_due' => $p->amount])->save();
        self::trail($s, 'pay', "QR code {$p->pay_no} for ".money($p->amount));

        return $p;
    }

    /** kiosk polls while the guest pays on their phone */
    public static function payStatus(KioskSession $s): array
    {
        $p = $s->payment?->fresh();
        if (! $p) {
            return ['state' => 'none'];
        }
        if ($p->status === 'pending' && $p->expires_at && $p->expires_at->isPast()) {
            OnlinePaymentService::expire();
            $p = $p->fresh();
        }
        if ($p->status === 'paid') {
            $recorded = collect($s->trail)->contains(fn ($t) => ($t[1] ?? '') === 'paid' && str_starts_with((string) ($t[2] ?? ''), $p->pay_no));
            if (! $recorded) {
                $got = round((float) ($p->amount_received ?: $p->amount), 2);
                $s->forceFill(['amount_paid' => round((float) $s->amount_paid + $got, 2)])->save();
                self::trail($s, 'paid', "{$p->pay_no} ".money($got).' received');
                if ($s->kind === 'walkin' && $s->reservation && $s->reservation->status === 'tentative') {
                    $s->reservation->forceFill(['status' => 'confirmed'])->save();
                }
            }

            return ['state' => 'paid'];
        }

        return ['state' => match ($p->status) { 'paid' => 'paid', 'review' => 'review', 'pending' => 'pending', default => 'failed' }, 'message' => $p->last_error];
    }

    /** ready rooms of the booked type the guest may choose from (a pre-assigned ready room comes first) */
    public static function roomOptions(KioskSession $s): Collection
    {
        $stay = self::stay($s);
        $ready = self::cfg()['room_ready'] === 'inspected' ? ['inspected'] : ['clean', 'inspected'];
        $free = AvailabilityService::freeRooms(bdate()->toDateString(), $stay->departure_date->toDateString(), $stay->room_type_id, $stay->id)
            ->where('status', 'vacant')->whereIn('hk_status', $ready)->values();
        $free->load('floor');
        if ($stay->room_id && ($pre = $free->firstWhere('id', $stay->room_id))) {
            return collect([$pre]);
        }
        // spread the choice over floors: at most 2 per floor, 4 in all
        return $free->groupBy('floor_id')->flatMap(fn ($g) => $g->take(2))->sortBy('room_no', SORT_NATURAL)->take(4)->values();
    }

    public static function assignRoom(KioskSession $s, ?int $roomId): KioskSession
    {
        self::need($s->step === 'room', 'This step is finished.');
        $stay = self::stay($s);
        $opts = self::roomOptions($s);
        if ($opts->isEmpty()) {
            $prep = AvailabilityService::freeRooms(bdate()->toDateString(), $stay->departure_date->toDateString(), $stay->room_type_id, $stay->id)->where('status', 'vacant')->count();
            self::assist($s, 'no_room', $prep ? 'Room being prepared ('.$stay->roomType?->name.')' : 'No vacant '.$stay->roomType?->name.' today');
            throw new RuntimeException('ASSIST:Your room is being prepared. We have let the front desk know – someone will be with you in a moment.');
        }
        $room = $roomId ? $opts->firstWhere('id', $roomId) : $opts->first();
        self::need((bool) $room, 'That room is no longer available – please choose another.');
        if (self::due($s)['due'] > 0.009) {
            throw new RuntimeException('Please complete the payment first.');
        }
        try {
            $in = StayService::checkIn($stay, $room->id);
        } catch (RuntimeException $e) {
            self::assist($s, 'error', 'Check-in refused: '.$e->getMessage());
            throw new RuntimeException('ASSIST:We could not finish your check-in here. A member of our team is on the way.');
        }
        $s->forceFill(['room_id' => $room->id])->save();
        self::trail($s, 'room', 'Checked in to room '.$room->room_no);
        ActivityLogger::log('kiosk', 'checkin', "Self check-in at kiosk {$s->kiosk?->name}: {$in->reservation->reservation_no} → room {$room->room_no}", $in);

        return self::advance($s->fresh());
    }

    public static function keys(KioskSession $s, int $count): KioskSession
    {
        self::need($s->step === 'keys', 'This step is finished.');
        $max = self::cfg()['max_keys'];
        self::need($count >= 1 && $count <= $max, "Choose 1 to {$max} key cards.");
        $stay = self::stay($s);
        $have = KeyCardService::activeFor($stay)->count();
        try {
            $more = max(0, $count - $have);
            if ($more > 0) {
                KeyCardService::issue($stay, $more, $have ? 'duplicate' : 'new', null, self::guestOf($stay)?->full_name);
            }
        } catch (RuntimeException $e) {
            self::trail($s, 'keys', 'Encoder: '.$e->getMessage());
            $s->forceFill(['keys_issued' => $have])->save();
            self::assist($s, 'keys', 'Key cards not encoded – '.$e->getMessage());

            return $s;
        }
        $s->forceFill(['keys_issued' => max($count, $have)])->save();
        self::trail($s, 'keys', max($count, $have).' key card(s) encoded');

        return self::finish($s->fresh());
    }

    protected static function finish(KioskSession $s): KioskSession
    {
        $s->forceFill(['status' => 'completed', 'step' => 'done', 'completed_at' => now()])->save();
        self::trail($s, 'done', $s->kindLabel().' finished in '.$s->durationLabel());

        return $s;
    }

    /** the screen that ends a check-in: room, floor, Wi-Fi, TV PIN and the essentials */
    public static function welcome(KioskSession $s): array
    {
        $stay = self::stay($s);
        $room = $stay->room;
        $g = self::guestOf($stay);

        return ['name' => $g?->first_name ?: 'Guest', 'room' => $room?->room_no, 'floor' => $room?->floor?->name, 'type' => $stay->roomType?->name,
            'departure' => $stay->departure_date->format('l j F'), 'checkout_time' => (substr((string) hotel()->checkout_time, 0, 5) ?: '12:00'),
            'wifi' => (string) setting('portal_wifi_name', ''), 'wifi_password' => (string) setting('portal_wifi_password', ''),
            'tv_pin' => \Illuminate\Support\Facades\Schema::hasColumn('reservation_rooms', 'tv_pin') ? IptvService::pinFor($stay) : null,
            'keys' => (int) $s->keys_issued, 'keys_at_desk' => $s->status === 'assist' && $s->assist_reason === 'keys',
            'breakfast' => (string) setting('kiosk_breakfast', 'Breakfast is served 06:30 – 10:30 in the restaurant.')];
    }

    // ───────────────────────────── check-out ─────────────────────────────

    public static function mainFolio(ReservationRoom $stay): ?Folio
    {
        return Folio::where('reservation_room_id', $stay->id)->where('status', 'open')->orderBy('id')->first();
    }

    /**
     * Post what check-out would post anyway (nights up to today, one-off package items) so the bill the guest sees and pays is final –
     * exactly what the front desk's check-out screen does (FrontDeskController::checkOutForm). Idempotent.
     */
    public static function completeBill(ReservationRoom $stay): void
    {
        FolioService::openForStay($stay);
        DB::transaction(fn () => StayService::postPendingNights($stay->fresh(['folio', 'room', 'reservation']), bdate()));
    }

    public static function bill(KioskSession $s): array
    {
        $stay = self::stay($s);
        $folios = Folio::with(['transactions' => fn ($q) => $q->where('is_void', false)->orderBy('business_date')->orderBy('id')])->where('reservation_room_id', $stay->id)->where('status', 'open')->orderBy('id')->get();
        $lines = [];
        foreach ($folios as $f) {
            foreach ($f->transactions as $t) {
                $amt = (float) $t->amount + ($t->type === 'charge' ? (float) $t->tax_amount : 0);
                $sign = in_array($t->type, ['charge', 'refund'], true) ? 1 : -1;
                $lines[] = ['date' => $t->business_date?->format('d M'), 'text' => $t->description, 'amount' => round($sign * $amt, 2)];
            }
        }
        $main = $folios->first();
        $others = $folios->slice(1)->filter(fn ($f) => abs($f->balance()) > 0.009);
        $bal = $main ? round($main->balance(), 2) : 0;
        $tax = $folios->sum(fn ($f) => (float) $f->totals()['tax']);

        return ['lines' => array_slice($lines, -80), 'balance' => $bal, 'tax' => round($tax, 2), 'split' => $others->isNotEmpty(), 'credit' => $bal < -0.009,
            'room' => $stay->room?->room_no, 'name' => self::guestOf($stay)?->full_name, 'arrival' => $stay->arrival_date->format('d M'), 'departure' => $stay->departure_date->format('d M'),
            'email' => self::mask(self::guestOf($stay)?->email, 'email')];
    }

    public static function checkout(KioskSession $s): KioskSession
    {
        self::need($s->kind === 'checkout' && $s->step === 'bill', 'This step is finished.');
        $stay = self::stay($s);
        self::completeBill($stay); // the business date may have moved on since the bill was shown
        $b = self::bill($s->fresh());
        if ($b['split']) {
            self::assist($s, 'bill', 'Guest has more than one bill with a balance');
            throw new RuntimeException('ASSIST:Your stay has more than one bill. A member of our team will settle them with you.');
        }
        if ($b['credit']) {
            self::assist($s, 'bill', 'Refund due '.money(-$b['balance']));
            throw new RuntimeException('ASSIST:You have a refund of '.money(-$b['balance']).' due. Our team will return it to you now.');
        }
        self::need($b['balance'] <= 0.009, 'Please pay the balance first.');
        try {
            StayService::checkOut($stay);
        } catch (RuntimeException $e) {
            self::assist($s, 'checkout', $e->getMessage());
            throw new RuntimeException('ASSIST:We need to finish a couple of things on your bill. A member of our team is on the way.');
        }
        self::trail($s, 'checkout', 'Checked out of room '.$stay->room?->room_no);
        ActivityLogger::log('kiosk', 'checkout', "Self check-out at kiosk {$s->kiosk?->name}: room {$stay->room?->room_no} ({$stay->reservation->reservation_no})", $stay);
        if (self::cfg()['feedback']) {
            self::go($s, 'feedback');

            return $s->fresh();
        }

        return self::finish($s->fresh());
    }

    public static function feedback(KioskSession $s, ?int $rating): KioskSession
    {
        self::need($s->step === 'feedback', 'This step is finished.');
        if ($rating) {
            self::need($rating >= 1 && $rating <= 5, 'Choose 1 to 5 stars.');
            $stay = self::stay($s);
            $g = self::guestOf($stay);
            $fb = GuestFeedback::where('reservation_room_id', $stay->id)->first();
            if (! $fb || in_array($fb->source, ['tv', 'kiosk'], true)) {
                $fb = GuestFeedback::updateOrCreate(['reservation_room_id' => $stay->id], ['rating_overall' => $rating, 'reservation_id' => $stay->reservation_id, 'guest_id' => $g?->id,
                    'guest_name' => $g?->full_name ?? 'Guest', 'contact' => $g?->email ?: $g?->phone, 'source' => 'kiosk', 'in_house' => false]);
                rescue(fn () => FeedbackService::analyse($fb), null, true);
            }
            $s->forceFill(['rating' => $rating])->save();
        }
        self::trail($s, 'feedback', $rating ? $rating.'★' : 'Skipped');

        return self::finish($s->fresh());
    }

    public static function emailInvoice(KioskSession $s): string
    {
        self::need($s->kind === 'checkout' && $s->status === 'completed' && $s->completed_at?->gt(now()->subMinutes(10)), 'This visit has ended.');
        $stay = self::stay($s);
        $g = self::guestOf($stay);
        self::need((bool) $g?->email, 'We have no e-mail address for you – the front desk can print your invoice.');
        $f = Folio::where('reservation_room_id', $stay->id)->where('status', 'closed')->orderBy('id')->first();
        self::need((bool) $f, 'Your invoice is not ready yet – please ask the front desk.');
        self::need(! collect($s->trail)->contains(fn ($t) => ($t[1] ?? '') === 'invoice'), 'Your invoice has already been sent.');
        $log = MailService::invoice($f, $g->email);
        self::trail($s, 'invoice', $log->status === 'sent' ? "Invoice {$f->invoice_no} e-mailed" : 'Invoice e-mail failed');
        self::need($log->status === 'sent', 'The e-mail could not be sent – the front desk can print your invoice.');

        return self::mask($g->email, 'email');
    }

    // ───────────────────────────── walk-in ─────────────────────────────

    /** room types with a ready room right now, priced for the stay (incl. taxes) */
    public static function walkinOffer(int $nights, int $adults): array
    {
        $c = self::cfg();
        self::need($nights >= 1 && $nights <= $c['walkin_nights'], "Book 1 to {$c['walkin_nights']} nights here – for longer stays please see the desk.");
        self::need($adults >= 1 && $adults <= $c['walkin_adults'], "Rooms here take 1 to {$c['walkin_adults']} adults.");
        $from = bdate()->copy();
        $to = $from->copy()->addDays($nights);
        $ready = $c['room_ready'] === 'inspected' ? ['inspected'] : ['clean', 'inspected'];
        $free = AvailabilityService::freeRooms($from->toDateString(), $to->toDateString())->where('status', 'vacant')->whereIn('hk_status', $ready);
        $out = [];
        foreach (RoomType::where('is_active', true)->orderBy('base_rate')->get() as $t) {
            if (($t->max_adults ?? 9) < $adults || ! $free->where('room_type_id', $t->id)->count()) {
                continue;
            }
            if (AvailabilityService::typeAvailable($t->id, $from->toDateString(), $to->toDateString()) < 1) {
                continue;
            }
            $q = RateService::quote($t, $from, $to);
            if ($q['stop_sell'] || ! $q['min_stay_ok'] || $q['average'] <= 0) {
                continue;
            }
            $tax = round(collect($q['nights'])->sum(fn ($n) => (float) TaxService::roomTax((float) $n['rate'])['amount']), 2);
            $out[] = ['id' => $t->id, 'name' => $t->name, 'description' => mb_substr((string) ($t->description ?? ''), 0, 160), 'max_adults' => $t->max_adults,
                'nightly' => round(($q['total'] + $tax) / $nights, 2), 'total' => round($q['total'] + $tax, 2), 'average' => $q['average']];
        }

        return $out;
    }

    public static function walkinBook(KioskSession $s, array $d): KioskSession
    {
        self::need($s->kind === 'walkin' && $s->step === 'rooms', 'This step is finished.');
        $nights = (int) ($d['nights'] ?? 0);
        $adults = (int) ($d['adults'] ?? 0);
        $offer = collect(self::walkinOffer($nights, $adults));
        $pick = $offer->firstWhere('id', (int) ($d['room_type_id'] ?? 0));
        self::need((bool) $pick, 'That room type has just sold out – please choose another.');
        $first = mb_substr(trim(strip_tags((string) ($d['first_name'] ?? ''))), 0, 80);
        $last = mb_substr(trim(strip_tags((string) ($d['last_name'] ?? ''))), 0, 80);
        $phone = mb_substr(trim((string) ($d['phone'] ?? '')), 0, 30);
        $email = mb_substr(trim((string) ($d['email'] ?? '')), 0, 150);
        $nat = mb_substr(trim(strip_tags((string) ($d['nationality'] ?? ''))), 0, 60);
        self::need($first !== '' && $last !== '', 'Enter your first name and surname.');
        self::need(preg_match('/^\+?[0-9 ()\-]{7,20}$/', $phone) === 1, 'Enter your mobile number.');
        self::need(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'Enter your e-mail address – your receipt is sent there.');
        self::need($nat !== '', 'Enter your nationality.');
        self::need(! empty($d['accept']), 'Please accept the booking terms.');
        $type = RoomType::findOrFail($pick['id']);
        $from = bdate()->copy();
        $to = $from->copy()->addDays($nights);
        $q = RateService::quote($type, $from, $to);
        $res = DB::transaction(function () use ($first, $last, $phone, $email, $nat, $type, $q, $from, $to, $adults, $s) {
            AvailabilityService::lockRoomInventory([$type->id]);
            if (AvailabilityService::typeAvailable($type->id, $from, $to) < 1) {
                throw new RuntimeException('That room type has just sold out. Please choose another room type.');
            }
            if (Guest::where('email', $email)->where('first_name', $first)->where('is_blacklisted', true)->exists()) {
                throw new RuntimeException('This guest is blacklisted. Booking blocked.');
            }
            $guest = Guest::whereNull('privacy_status')->where('email', $email)->where('first_name', $first)->first()
                ?? Guest::create(['email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'nationality' => $nat]);
            if ($guest->is_blacklisted) {
                throw new RuntimeException('This guest is blacklisted. Booking blocked.');
            }
            $guest->fill(array_filter(['last_name' => $guest->last_name ?: $last, 'phone' => $guest->phone ?: $phone, 'nationality' => $guest->nationality ?: $nat]))->save();
            $res = Reservation::create(['reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id,
                'business_source_id' => BusinessSource::where('type', 'walk_in')->value('id'), 'arrival_date' => $from->toDateString(), 'departure_date' => $to->toDateString(),
                'adults' => $adults, 'children' => 0, 'status' => 'tentative', 'purpose' => 'Leisure', 'special_request' => 'Booked at the self-service kiosk ('.$s->kiosk?->name.')']);
            $stay = $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $guest->id, 'arrival_date' => $from->toDateString(), 'departure_date' => $to->toDateString(),
                'rate' => $q['average'], 'adults' => $adults, 'status' => 'reserved']);

            return [$res, $stay, $guest];
        });
        [$r, $stay, $guest] = $res;
        $s->forceFill(['reservation_id' => $r->id, 'reservation_room_id' => $stay->id, 'guest_id' => $guest->id, 'lookup' => 'walkin', 'details_ok' => false])->save();
        self::trail($s, 'booked', "{$r->reservation_no} · {$type->name} · {$nights} night(s) · ".money($pick['total']));
        ActivityLogger::log('kiosk', 'walkin', "Walk-in booked at kiosk {$s->kiosk?->name}: {$r->reservation_no} ({$type->name}, {$nights} night(s)) for {$guest->full_name}", $r);
        $s->forceFill(['step' => 'details'])->save();

        return self::advance($s->fresh());
    }

    // ───────────────────────────── the screen payload ─────────────────────────────

    /** everything the kiosk needs to draw the current screen */
    public static function state(KioskSession $s): array
    {
        $s->refresh();
        $out = ['session' => $s->token, 'number' => $s->number, 'kind' => $s->kind, 'step' => $s->step, 'status' => $s->status];
        if (! $s->reservation_room_id) {
            if ($s->step === 'rooms') {
                $c = self::cfg();
                $out['walkin'] = ['max_nights' => $c['walkin_nights'], 'max_adults' => $c['walkin_adults'], 'terms' => $c['terms']];
            }

            return $out;
        }
        $stay = self::stay($s);
        $g = self::guestOf($stay);
        $r = $stay->reservation;
        $out['booking'] = ['no' => $r->reservation_no, 'name' => trim(($g?->title ? $g->title.' ' : '').$g?->first_name.' '.$g?->last_name), 'first_name' => $g?->first_name,
            'type' => $stay->roomType?->name, 'arrival' => $stay->arrival_date->format('D j M'), 'departure' => $stay->departure_date->format('D j M'),
            'nights' => max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date)), 'adults' => (int) $stay->adults, 'children' => (int) $stay->children, 'room' => $stay->room?->room_no];
        switch ($s->step) {
            case 'details':
                $foreign = FormCService::enabled() && FormCService::isForeignNationality($g?->nationality) === true;
                $out['details'] = ['nationality' => $g?->nationality, 'id_type' => $g?->id_type, 'id_number' => self::mask($g?->id_number, 'id'),
                    'phone' => self::mask($g?->phone, 'phone'), 'email' => self::mask($g?->email, 'email'), 'passport' => self::mask($g?->passport_no, 'id'),
                    'foreign' => $foreign, 'home' => FormCService::enabled() ? array_values(FormCService::homeAliases()) : [], 'id_types' => config('pms.id_types'), 'purposes' => \App\Models\FormCRecord::PURPOSES,
                    'need' => array_keys(array_filter(['phone' => ! $g?->phone, 'nationality' => ! $g?->nationality, 'id' => ! $g?->id_number]))];
                break;
            case 'sign':
                $due = self::due($s);
                $out['grc'] = ['rate' => money($due['nightly']), 'terms' => self::cfg()['terms'], 'hotel' => hotel()->name, 'checkout_time' => (substr((string) hotel()->checkout_time, 0, 5) ?: '12:00')];
                break;
            case 'pay':
                $due = self::due($s);
                $out['pay'] = ['lines' => array_map(fn ($l) => [$l[0], money($l[1])], $due['lines']), 'due' => money($due['due']), 'amount' => $due['due']];
                if ($s->payment && $s->payment->status === 'pending' && $s->payment->isPayable()) {
                    $out['pay']['qr'] = self::qr($s->payment->url());
                    $out['pay']['pay_no'] = $s->payment->pay_no;
                    $out['pay']['expires'] = $s->payment->expires_at?->toIso8601String();
                }
                break;
            case 'room':
                $out['rooms'] = self::roomOptions($s)->map(fn ($rm) => ['id' => $rm->id, 'no' => $rm->room_no, 'floor' => $rm->floor?->name, 'smoking' => (bool) $rm->is_smoking,
                    'assigned' => $rm->id === $stay->room_id])->values();
                $out['choice'] = self::cfg()['room_choice'];
                break;
            case 'keys':
                $out['keys'] = ['max' => self::cfg()['max_keys'], 'room' => $stay->room?->room_no, 'have' => KeyCardService::activeFor($stay)->count()];
                break;
            case 'bill':
                $out['bill'] = self::bill($s);
                $out['bill']['balance_label'] = money($out['bill']['balance']);
                $out['bill']['lines'] = array_map(fn ($l) => $l + ['label' => money($l['amount'])], $out['bill']['lines']);
                if ($s->payment && $s->payment->status === 'pending' && $s->payment->isPayable()) {
                    $out['bill']['qr'] = self::qr($s->payment->url());
                    $out['bill']['pay_no'] = $s->payment->pay_no;
                }
                break;
            case 'done':
                if ($s->kind === 'checkout') {
                    $out['farewell'] = ['name' => $g?->first_name ?: 'Guest', 'email' => self::mask($g?->email, 'email'), 'invoice' => (bool) Folio::where('reservation_room_id', $stay->id)->where('status', 'closed')->exists()];
                } else {
                    $out['welcome'] = self::welcome($s);
                }
                break;
        }

        return $out;
    }

    // ───────────────────────────── dashboard & analytics ─────────────────────────────

    public static function dashboard(): array
    {
        self::sweep();
        $today = now()->startOfDay();
        $todayQ = fn () => KioskSession::where('started_at', '>=', $today);
        $sessions = $todayQ()->get();
        $bd = bdate()->toDateString();
        $checkinsToday = ReservationRoom::where('checked_in_at', '>=', $today)->count();
        $kioskIns = $sessions->where('kind', '!=', 'checkout')->filter(fn ($s) => $s->room_id && collect($s->trail)->contains(fn ($t) => ($t[1] ?? '') === 'room'))->count();
        $checkoutsToday = ReservationRoom::where('checked_out_at', '>=', $today)->count();
        $kioskOuts = $sessions->where('kind', 'checkout')->filter(fn ($s) => collect($s->trail)->contains(fn ($t) => ($t[1] ?? '') === 'checkout'))->count();
        $done = KioskSession::where('status', 'completed')->where('kind', 'checkin')->where('started_at', '>=', now()->subDays(30))->get();
        $avg = $done->count() ? (int) round($done->avg(fn ($s) => $s->seconds())) : 0;
        $arrivalsLeft = ReservationRoom::where('status', 'reserved')->whereDate('arrival_date', $bd)->count();

        return [
            'kiosks' => Kiosk::orderBy('name')->get(),
            'sessions_today' => $sessions->count(), 'completed_today' => $sessions->where('status', 'completed')->count(),
            'checkins_today' => $checkinsToday, 'kiosk_checkins' => $kioskIns, 'share' => $checkinsToday ? round($kioskIns * 100 / $checkinsToday) : 0,
            'checkouts_today' => $checkoutsToday, 'kiosk_checkouts' => $kioskOuts, 'walkins_today' => $sessions->where('kind', 'walkin')->where('status', 'completed')->count(),
            'collected_today' => round((float) $sessions->sum('amount_paid'), 2), 'avg_seconds' => $avg, 'arrivals_left' => $arrivalsLeft,
            'queue' => KioskSession::with('kiosk', 'guest', 'reservation', 'room')->where('status', 'assist')->whereNull('resolved_at')->orderBy('assist_at')->get(),
            'live' => KioskSession::with('kiosk', 'guest')->where('status', 'active')->orderByDesc('last_activity_at')->get(),
            'recent' => KioskSession::with('kiosk', 'guest', 'room')->where('status', '!=', 'active')->orderByDesc('started_at')->limit(12)->get(),
            'funnel' => self::funnel(now()->subDays(29)->startOfDay(), now()),
            'hours' => self::byHour(now()->subDays(13)->startOfDay()),
        ];
    }

    /** how far check-in visits get: started → found → details → ID → signed → paid/no payment → room → done */
    public static function funnel($from, $to): array
    {
        $rows = KioskSession::where('kind', 'checkin')->whereBetween('started_at', [$from, $to])->get(['trail', 'status', 'room_id', 'reservation_room_id']);
        $has = fn ($s, $step) => collect($s->trail)->contains(fn ($t) => ($t[1] ?? '') === $step);
        $n = $rows->count();

        return ['Started' => $n, 'Booking found' => $rows->filter(fn ($s) => $s->reservation_room_id)->count(),
            'Details confirmed' => $rows->filter(fn ($s) => $has($s, 'details'))->count(), 'Registration signed' => $rows->filter(fn ($s) => $has($s, 'sign') || $has($s, 'room'))->count(),
            'Checked in' => $rows->filter(fn ($s) => $has($s, 'room'))->count(), 'Finished at the kiosk' => $rows->where('status', 'completed')->count()];
    }

    public static function byHour($from): array
    {
        $out = array_fill(6, 18, 0); // 06:00 – 23:00
        KioskSession::where('started_at', '>=', $from)->get(['started_at'])->each(function ($s) use (&$out) {
            $h = (int) $s->started_at->format('G');
            if (isset($out[$h])) {
                $out[$h]++;
            }
        });

        return $out;
    }
}
