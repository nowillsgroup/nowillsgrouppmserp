<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Kiosk;
use App\Models\KioskSession;
use App\Services\KioskService as KS;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * v7.2 The lobby kiosk. A new kiosk opens /kiosk/setup/{hotel code} and shows a pairing code; once paired it runs
 * /kiosk/{token}. Stateless (no session or cookies): the kiosk token identifies the device, and every guest visit gets its
 * own 40-character token that each step carries. Each step answers with the whole next screen (KioskService::state()).
 */
class KioskController extends Controller
{
    protected function secure($response)
    {
        return $response->header('Content-Security-Policy', "default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; "
            ."style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-ancestors ".config('pms.frame_ancestors', "'self'")."; base-uri 'self'; form-action 'self'")
            ->header('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
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

    protected function kiosk(string $token): Kiosk
    {
        $k = Kiosk::withoutGlobalScopes()->where('token', $token)->first();
        abort_unless($k, 404);
        $h = Hotel::find($k->hotel_id);
        abort_unless($h?->is_active, 404);
        Tenant::set($h);
        $k = Kiosk::findOrFail($k->id);
        abort_if($k->status === 'pending', 404);
        KS::heartbeat($k, request()->ip(), request()->userAgent());

        return $k;
    }

    /** RuntimeException "ASSIST:…" = handed to the desk, "STOP:…" = nothing to do here (e.g. booking starts tomorrow) */
    protected function run(callable $fn)
    {
        try {
            return $this->json($fn());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { // before RuntimeException – it is one; 404 sends the kiosk home
            return $this->json(['error' => 'This visit has ended. Please start again.'], 404);
        } catch (RuntimeException $e) {
            $m = $e->getMessage();
            foreach (['ASSIST:' => 'assist', 'STOP:' => 'stop'] as $p => $end) {
                if (str_starts_with($m, $p)) {
                    return $this->json(['end' => $end, 'message' => substr($m, strlen($p))]);
                }
            }

            return $this->json(['error' => $m], 422);
        } catch (ValidationException $e) {
            return $this->json(['error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            report($e);

            return $this->json(['error' => 'Something went wrong. Please ask the front desk for help.'], 500);
        }
    }

    // ───────── setup / pairing ─────────

    public function setup(string $code)
    {
        $h = $this->hotelByCode($code);

        return $this->secure(response()->view('kiosk.setup', ['hotel' => $h, 'code' => mb_strtoupper($code), 'enabled' => KS::cfg()['enabled']]));
    }

    public function pairStart(Request $r, string $code)
    {
        $this->hotelByCode($code);

        return $this->run(function () use ($r) {
            $k = KS::startPairing($r->ip(), $r->userAgent());

            return ['code' => $k->pairing_code, 'token' => $k->token, 'expires' => $k->pairing_expires_at->toIso8601String()];
        });
    }

    public function pairStatus(string $token)
    {
        $k = Kiosk::withoutGlobalScopes()->where('token', $token)->first();
        if (! $k) {
            return $this->json(['state' => 'gone']);
        }

        return $this->json(match (true) {
            $k->status === 'active' => ['state' => 'paired', 'url' => route('kiosk.app', $k->token)],
            $k->status === 'pending' && $k->pairing_expires_at?->gt(now()) => ['state' => 'waiting', 'code' => $k->pairing_code],
            default => ['state' => 'expired'],
        });
    }

    // ───────── the kiosk ─────────

    public function app(string $token)
    {
        $k = $this->kiosk($token);

        return $this->secure(response()->view('kiosk.app', ['kiosk' => $k, 'hotel' => hotel(), 'cfg' => KS::cfg(), 'base' => url('/kiosk/'.$token)]));
    }

    public function home(string $token)
    {
        $k = $this->kiosk($token);
        $c = KS::cfg();

        return $this->json(['hotel' => hotel()->name, 'kiosk' => $k->name, 'active' => $c['enabled'] && $k->status === 'active', 'offer' => KS::offer($k),
            'welcome' => $c['welcome'], 'idle' => $c['idle'], 'time' => now()->format('H:i'), 'date' => now()->format('l j F')]);
    }

    public function start(Request $r, string $token)
    {
        $k = $this->kiosk($token);

        return $this->run(fn () => KS::state(KS::start($k, (string) $r->input('kind'))));
    }

    protected function visit(Kiosk $k, string $visit): KioskSession
    {
        $s = KS::session($k, $visit);
        $s->setRelation('kiosk', $k);

        return $s;
    }

    public function state(string $token, string $visit)
    {
        $k = $this->kiosk($token);

        return $this->run(fn () => KS::state($this->visit($k, $visit)));
    }

    public function payStatus(string $token, string $visit)
    {
        $k = $this->kiosk($token);

        return $this->run(function () use ($k, $visit) {
            $s = $this->visit($k, $visit);
            $st = KS::payStatus($s);
            if ($st['state'] === 'paid' && $s->kind !== 'checkout') {
                $s = KS::advance($s->fresh()->setRelation('kiosk', $k));
            }

            return ['pay' => $st] + KS::state($s);
        });
    }

    public function walkinOffer(Request $r, string $token, string $visit)
    {
        $k = $this->kiosk($token);

        return $this->run(function () use ($k, $visit, $r) {
            $s = $this->visit($k, $visit);
            if ($s->kind !== 'walkin' || $s->step !== 'rooms') {
                throw new RuntimeException('This step is finished.');
            }
            $types = KS::walkinOffer((int) $r->query('nights', 1), (int) $r->query('adults', 1));

            return ['types' => array_map(fn ($t) => $t + ['nightly_label' => money($t['nightly']), 'total_label' => money($t['total'])], $types)];
        });
    }

    public function act(Request $r, string $token, string $visit, string $action)
    {
        $k = $this->kiosk($token);
        if ($action === 'invoice') {
            return $this->run(function () use ($k, $visit) {
                $s = KioskSession::where('kiosk_id', $k->id)->where('token', $visit)->firstOrFail();
                $s->setRelation('kiosk', $k);

                return ['sent' => KS::emailInvoice($s)];
            });
        }

        return $this->run(function () use ($r, $k, $visit, $action) {
            $s = $this->visit($k, $visit);
            switch ($action) {
                case 'find': $s = KS::find($s, $r->only('ref', 'last_name', 'qr'));
                    break;
                case 'details': $s = KS::details($s, $r->all());
                    break;
                case 'document': $s = KS::document($s, $r->file('photo'), $r->input('image'));
                    break;
                case 'sign': $s = KS::sign($s, $r->input('signature'), $r->boolean('accept'));
                    break;
                case 'pay': KS::pay($s);
                    break;
                case 'room': $s = KS::assignRoom($s, $r->integer('room_id') ?: null);
                    break;
                case 'keys': $s = KS::keys($s, (int) $r->input('count', 1));
                    if ($s->status === 'assist') {
                        return ['end' => 'keys', 'message' => 'You are checked in to room '.$s->room?->room_no.'. Please collect your key cards from the front desk – they are expecting you.'] + KS::state($s);
                    }
                    break;
                case 'checkout': $s = KS::checkout($s);
                    break;
                case 'feedback': $s = KS::feedback($s, $r->integer('rating') ?: null);
                    break;
                case 'walkin': $s = KS::walkinBook($s, $r->all());
                    break;
                case 'assist':
                    KS::assist($s, $r->input('reason') === 'pay_desk' ? 'pay_desk' : 'guest', mb_substr((string) $r->input('note'), 0, 200) ?: 'Guest pressed "Call for help" on the '.(KioskSession::STEPS[$s->step] ?? $s->step).' screen');

                    return ['end' => 'assist', 'message' => 'A member of our team is on the way to help you.'];
                case 'cancel':
                    KS::cancel($s);

                    return ['end' => 'cancel'];
            }

            return KS::state($s);
        });
    }
}
