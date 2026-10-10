<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\TvDevice;
use App\Models\TvMovie;
use App\Services\IptvService as IS;
use App\Support\Tenant;
use App\Support\TvLang;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * v7.1 The in-room TV portal. Hotel TVs (hotel mode / URL launcher) and lobby screens load /tv/{token}; a new TV opens
 * /tv/setup/{hotel code} and shows a pairing code. Stateless: no session or cookies – the 40-character device token in the
 * URL identifies the set, like a guest-portal link. Every call is also the TV's heartbeat.
 */
class TvController extends Controller
{
    protected function csp($response)
    {
        return $response->header('Content-Security-Policy', "default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob: https: http:; connect-src 'self' https://api.open-meteo.com; "
            ."style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-ancestors ".config('pms.frame_ancestors', "'self'")."; base-uri 'self'; form-action 'self'")
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    protected function json($data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    protected function hotelByCode(string $code): Hotel
    {
        $h = Hotel::whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])->first();
        abort_unless($h?->is_active, 404);
        Tenant::set($h);

        return $h;
    }

    protected function device(string $token, bool $touch = true): TvDevice
    {
        $d = TvDevice::withoutGlobalScopes()->where('token', $token)->first();
        abort_unless($d, 404);
        $h = Hotel::find($d->hotel_id);
        abort_unless($h?->is_active, 404);
        Tenant::set($h);
        $d = TvDevice::findOrFail($d->id);
        if ($touch) {
            IS::heartbeat($d, request()->ip(), request()->userAgent());
        }

        return $d;
    }

    /** the in-house stay of the TV's room – guest actions need one */
    protected function stay(TvDevice $d): ReservationRoom
    {
        $s = $d->status === 'active' && IS::cfg()['enabled'] ? IS::stayFor($d) : null;
        if (! $s) {
            throw new RuntimeException('This TV has no guest checked in.');
        }

        return $s;
    }

    protected function run(callable $fn)
    {
        try {
            return $this->json($fn());
        } catch (RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            return $this->json(['error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->json(['error' => 'Not found.'], 404);
        } catch (\Throwable $e) {
            report($e); // a TV only understands JSON – never send it an HTML error page

            return $this->json(['error' => 'Something went wrong – please call the front desk.'], 500);
        }
    }

    // ───────── setup & pairing ─────────

    public function setup(string $code)
    {
        $h = $this->hotelByCode($code);

        return $this->csp(response()->view('tv.setup', ['hotel' => $h, 'code' => mb_strtoupper($code), 'enabled' => IS::cfg()['enabled']]));
    }

    public function pairStart(Request $r, string $code)
    {
        $this->hotelByCode($code);

        return $this->run(function () use ($r) {
            $d = IS::startPairing($r->ip(), $r->userAgent());

            return ['code' => $d->pairing_code, 'token' => $d->token, 'expires' => $d->pairing_expires_at->toIso8601String()];
        });
    }

    public function pairStatus(string $token)
    {
        $d = TvDevice::withoutGlobalScopes()->where('token', $token)->first();
        if (! $d) {
            return $this->json(['state' => 'gone']);
        }

        return $this->json(match (true) {
            $d->status === 'active' => ['state' => 'paired', 'url' => route('tv.app', $d->token)],
            $d->status === 'pending' && $d->pairing_expires_at?->gt(now()) => ['state' => 'waiting', 'code' => $d->pairing_code],
            default => ['state' => 'expired'],
        });
    }

    // ───────── the portal ─────────

    public function app(string $token)
    {
        $d = $this->device($token);
        $c = IS::cfg();

        return $this->csp(response()->view('tv.app', ['token' => $token, 'device' => $d, 'hotel' => hotel(), 'boot' => IS::state($d), 'langs' => TvLang::LANGS, 'cfg' => $c]));
    }

    public function state(string $token)
    {
        return $this->json(IS::state($this->device($token)));
    }

    public function data(string $token, string $section)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $section) {
            if ($section === 'channels') {
                return ['channels' => IS::channelList()];
            }
            if ($d->kind === 'signage') {
                throw new RuntimeException('Not available on this screen.');
            }
            $stay = $this->stay($d);
            $lang = IS::language(IS::session($d, $stay), $stay);
            IS::logEvent($d, $stay, ['movies' => 'movies', 'dining' => 'dining', 'services' => 'services', 'pages' => 'info', 'explore' => 'explore', 'messages' => 'messages'][$section]);

            return match ($section) {
                'movies' => ['movies' => IS::movieList($stay)],
                'dining' => IS::diningMenu(),
                'services' => IS::serviceMenu($stay),
                'pages' => ['pages' => IS::pagesFor($lang)->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'category' => \App\Models\TvPage::CATEGORIES[$p->category] ?? $p->category,
                    'icon' => $p->icon, 'body' => $p->body])->all()],
                'explore' => ['places' => IS::explorePlaces()],
                'messages' => ['messages' => IS::messagePayload($d, $stay), 'desk' => \App\Models\GuestMessage::where('reservation_room_id', $stay->id)->whereIn('status', \App\Models\GuestMessage::OPEN)->count()],
            };
        });
    }

    // ───────── guest actions ─────────

    public function welcome(string $token)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d) {
            IS::dismissWelcome($d, $this->stay($d));

            return ['ok' => true];
        });
    }

    public function language(Request $r, string $token)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $r) {
            IS::setLanguage($d, $this->stay($d), (string) $r->input('lang'));

            return ['ok' => true];
        });
    }

    public function event(Request $r, string $token)
    {
        $d = $this->device($token);
        $stay = $d->kind === 'room' ? IS::stayFor($d) : null;
        IS::logEvent($d, $stay, (string) $r->input('event'), $r->filled('target') ? (string) $r->input('target') : null);

        return $this->json(['ok' => true]);
    }

    public function read(string $token, int $id)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $id) {
            IS::markRead($d, $d->kind === 'room' ? IS::stayFor($d) : null, $id);

            return ['ok' => true];
        });
    }

    public function ack(Request $r, string $token, int $id)
    {
        $d = $this->device($token);
        IS::ack($d, $id, $r->boolean('ok', true), $r->filled('result') ? (string) $r->input('result') : null);

        return $this->json(['ok' => true]);
    }

    public function rent(Request $r, string $token, int $movie)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $r, $movie) {
            $p = IS::rent($d, $this->stay($d), TvMovie::findOrFail($movie), $r->filled('pin') ? (string) $r->input('pin') : null);

            return ['ok' => true, 'number' => $p->number, 'url' => $p->movie?->stream_url, 'title' => $p->title, 'left' => (int) ceil(now()->diffInMinutes($p->expires_at) / 60), 'total' => money($p->total)];
        });
    }

    public function order(Request $r, string $token)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $r) {
            $o = IS::order($d, $this->stay($d), (array) $r->input('items', []));

            return ['ok' => true, 'number' => $o->order_no];
        });
    }

    public function request(Request $r, string $token)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $r) {
            $q = IS::request($d, $this->stay($d), ['category' => (string) $r->input('category'), 'item' => (string) $r->input('item'), 'quantity' => (int) $r->input('quantity', 1)]);

            return ['ok' => true, 'number' => $q->request_no];
        });
    }

    public function wakeup(Request $r, string $token)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $r) {
            $c = IS::wakeup($d, $this->stay($d), (string) $r->input('time'), $r->boolean('daily'));

            return ['ok' => true, 'at' => $c->call_at->format('D d M H:i')];
        });
    }

    public function bill(Request $r, string $token)
    {
        $d = $this->device($token);

        return $this->run(fn () => IS::bill($d, $this->stay($d), $r->filled('pin') ? (string) $r->input('pin') : null));
    }

    public function checkout(string $token)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d) {
            $q = IS::checkout($d, $this->stay($d));

            return ['ok' => true, 'number' => $q->request_no];
        });
    }

    public function feedback(Request $r, string $token)
    {
        $d = $this->device($token);

        return $this->run(function () use ($d, $r) {
            IS::feedback($d, $this->stay($d), (int) $r->input('rating'));

            return ['ok' => true];
        });
    }
}
