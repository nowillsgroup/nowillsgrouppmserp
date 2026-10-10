<?php

namespace App\Http\Controllers;

use App\Models\Kiosk;
use App\Models\KioskSession;
use App\Services\KioskService as KS;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v7.2 Self-service kiosk – back office: dashboard & assistance queue, kiosks, guest visits, settings. */
class KioskAdminController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    public function index()
    {
        return view('kiosk-admin.index', ['d' => KS::dashboard(), 'cfg' => KS::cfg(), 'setupUrl' => route('kiosk.setup', hotel()->code)]);
    }

    public function kiosks()
    {
        KS::sweep();
        $kiosks = Kiosk::withCount(['sessions', 'sessions as today_count' => fn ($q) => $q->where('started_at', '>=', now()->startOfDay())])->orderByRaw("status = 'pending'")->orderBy('name')->get();

        return view('kiosk-admin.kiosks', ['kiosks' => $kiosks, 'setupUrl' => route('kiosk.setup', hotel()->code), 'canManage' => KS::canManage(auth()->user())]);
    }

    public function sessions(Request $r)
    {
        try {
            $from = $r->filled('from') ? Carbon::parse($r->query('from'))->startOfDay() : now()->subDays(6)->startOfDay();
            $to = $r->filled('to') ? Carbon::parse($r->query('to'))->endOfDay() : now()->endOfDay();
        } catch (\Throwable) {
            [$from, $to] = [now()->subDays(6)->startOfDay(), now()->endOfDay()];
        }
        $q = KioskSession::with('kiosk', 'guest', 'reservation', 'room', 'resolvedBy')->whereBetween('started_at', [$from, $to]);
        foreach (['kind', 'status', 'kiosk_id'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->query($f));
            }
        }
        if ($r->filled('q')) {
            $term = '%'.trim((string) $r->query('q')).'%';
            $q->where(fn ($w) => $w->where('number', 'like', $term)->orWhereHas('guest', fn ($g) => $g->where('last_name', 'like', $term)->orWhere('first_name', 'like', $term))
                ->orWhereHas('reservation', fn ($x) => $x->where('reservation_no', 'like', $term)));
        }
        $all = (clone $q)->get();
        $list = $q->orderByDesc('started_at')->paginate(30)->withQueryString();

        return view('kiosk-admin.sessions', ['list' => $list, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'kiosks' => Kiosk::orderBy('name')->get(),
            'totals' => ['visits' => $all->count(), 'completed' => $all->where('status', 'completed')->count(), 'assist' => $all->where('status', 'assist')->count(),
                'abandoned' => $all->whereIn('status', ['abandoned', 'cancelled'])->count(), 'paid' => round((float) $all->sum('amount_paid'), 2)]]);
    }

    public function session(KioskSession $ksession)
    {
        $ksession->load('kiosk', 'guest', 'reservation', 'stay.roomType', 'room', 'payment', 'resolvedBy');
        $docs = $ksession->guest ? $ksession->guest->documents()->where('reservation_room_id', $ksession->reservation_room_id)->latest('id')->get() : collect();

        return view('kiosk-admin.session', ['s' => $ksession, 'docs' => $docs, 'canAssist' => KS::canAssist(auth()->user())]);
    }

    public function resolve(Request $r, KioskSession $ksession)
    {
        return $this->attempt(fn () => KS::resolve($ksession, $r->input('resolution'), $r->user()), "Visit {$ksession->number} marked as dealt with.");
    }

    protected function data(Request $r): array
    {
        return $r->only(['name', 'location', 'serial', 'notes']) + ['allow_checkin' => $r->boolean('allow_checkin'), 'allow_checkout' => $r->boolean('allow_checkout'),
            'allow_walkin' => $r->boolean('allow_walkin'), 'has_encoder' => $r->boolean('has_encoder'), 'has_camera' => $r->boolean('has_camera')];
    }

    public function store(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $k = KS::saveKiosk($this->data($r), null, $r->user());

            return back()->with('success', "Kiosk {$k->name} added. Open this address on the kiosk: ".route('kiosk.app', $k->token));
        }, 'Kiosk added.');
    }

    public function pair(Request $r)
    {
        return $this->attempt(fn () => 'Paired: '.KS::claim((string) $r->input('code'), $this->data($r), $r->user())->name.'. The kiosk opens its welcome screen within a few seconds.', 'Paired.');
    }

    public function update(Request $r, Kiosk $kiosk)
    {
        return $this->attempt(fn () => KS::saveKiosk($this->data($r), $kiosk, $r->user()), 'Kiosk updated.');
    }

    public function rotate(Request $r, Kiosk $kiosk)
    {
        return $this->attempt(function () use ($r, $kiosk) {
            $k = KS::rotate($kiosk, $r->user());

            return back()->with('success', "New start address for {$k->name}: ".route('kiosk.app', $k->token).' – the old address no longer works.');
        }, 'Done.');
    }

    public function status(Request $r, Kiosk $kiosk)
    {
        return $this->attempt(fn () => KS::setStatus($kiosk, $r->input('status') === 'active', $r->user()), 'Kiosk updated.');
    }

    public function destroy(Request $r, Kiosk $kiosk)
    {
        return $this->attempt(fn () => KS::deleteKiosk($kiosk, $r->user()), 'Kiosk removed.');
    }

    public function settings()
    {
        return view('kiosk-admin.settings', ['cfg' => KS::cfg(), 'payOn' => \App\Services\OnlinePaymentService::enabled(),
            'lockOn' => \App\Services\InterfaceService::cfg()['lock_enabled'] === '1', 'breakfast' => (string) setting('kiosk_breakfast', 'Breakfast is served 06:30 – 10:30 in the restaurant.')]);
    }

    public function saveSettings(Request $r)
    {
        return $this->attempt(function () use ($r) {
            KS::saveSettings($r->all(), $r->user());
            \App\Models\Setting::updateOrCreate(['key' => 'kiosk_breakfast'], ['value' => mb_substr(trim((string) $r->input('kiosk_breakfast')), 0, 200)]);
            \App\Models\Setting::$version++;
        }, 'Kiosk settings saved.');
    }
}
