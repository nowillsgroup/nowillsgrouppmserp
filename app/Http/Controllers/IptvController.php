<?php

namespace App\Http\Controllers;

use App\Models\Floor;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\TvChannel;
use App\Models\TvCommand;
use App\Models\TvDevice;
use App\Models\TvEvent;
use App\Models\TvMessage;
use App\Models\TvMovie;
use App\Models\TvPage;
use App\Models\TvPurchase;
use App\Models\TvSession;
use App\Models\TvWelcomeTemplate;
use App\Services\IptvService as IS;
use App\Support\TvLang;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v7.1 IPTV & guest welcome – back office. */
class IptvController extends Controller
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

    protected function range(Request $r, string $defFrom): array
    {
        $t = bdate()->toDateString();
        try {
            $from = $r->filled('from') ? Carbon::parse($r->query('from'))->toDateString() : $defFrom;
            $to = $r->filled('to') ? Carbon::parse($r->query('to'))->toDateString() : $t;
        } catch (\Throwable) {
            [$from, $to] = [$defFrom, $t];
        }

        return $to < $from ? [$to, $from] : [$from, $to];
    }

    protected function rooms()
    {
        return Room::where('is_active', true)->orderBy('room_no')->get(['id', 'room_no', 'floor_id']);
    }

    // ------------------------------------------------------------------ dashboard

    public function index()
    {
        $rooms = Room::with(['floor', 'currentStay.guest', 'currentStay.reservation.guest'])->where('is_active', true)->orderBy('floor_id')->orderBy('room_no')->get();
        $devices = TvDevice::where('status', '!=', 'pending')->get();
        $welcomed = TvSession::whereNull('ended_at')->whereNotNull('welcomed_at')->pluck('reservation_room_id')->all();

        return view('iptv.index', [
            'stats' => IS::stats(), 'alerts' => IS::alerts(), 'emergency' => IS::emergency(), 'rooms' => $rooms->groupBy(fn ($r) => $r->floor?->name ?? 'Rooms'),
            'byRoom' => $devices->where('kind', 'room')->groupBy('room_id'), 'signage' => $devices->where('kind', 'signage')->values(), 'welcomed' => $welcomed,
            'usage' => IS::analytics(bdate()->copy()->subDays(6)->toDateString(), bdate()->toDateString()),
            'recent' => TvEvent::with('stay.room')->whereIn('event', ['rent', 'order', 'request', 'wakeup', 'checkout', 'feedback', 'welcome', 'language'])->latest('created_at')->latest('id')->limit(12)->get(),
            'messages' => TvMessage::where('status', 'active')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest('id')->limit(6)->get(),
            'cfg' => IS::cfg(),
        ]);
    }

    // ------------------------------------------------------------------ devices

    public function devices(Request $r)
    {
        $cut = now()->subMinutes(IS::cfg()['offline_min']);
        $list = TvDevice::with(['room', 'pairedBy'])
            ->when($r->filled('kind'), fn ($q) => $q->where('kind', $r->query('kind')))
            ->when($r->query('state') === 'online', fn ($q) => $q->where('status', 'active')->where('last_seen_at', '>=', $cut))
            ->when($r->query('state') === 'offline', fn ($q) => $q->where('status', 'active')->where(fn ($w) => $w->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cut)))
            ->when(in_array($r->query('state'), ['pending', 'disabled'], true), fn ($q) => $q->where('status', $r->query('state')))
            ->when($r->query('state') !== 'pending', fn ($q) => $q->where(fn ($w) => $w->where('status', '!=', 'pending')->orWhere('pairing_expires_at', '>', now())))
            ->orderByRaw("FIELD(status, 'pending', 'active', 'disabled')")->orderBy('kind')->orderBy('name')->get();
        $stays = ReservationRoom::with(['guest', 'reservation.guest'])->where('status', 'checked_in')->whereIn('room_id', $list->pluck('room_id')->filter())->get()->keyBy('room_id');

        return view('iptv.devices', ['list' => $list, 'stays' => $stays, 'rooms' => $this->rooms(), 'setupUrl' => route('tv.setup', hotel()->code),
            'channels' => TvChannel::where('is_active', true)->orderBy('number')->get(['number', 'name']), 'cfg' => IS::cfg()]);
    }

    public function device(TvDevice $tvdevice)
    {
        return view('iptv.device', ['d' => $tvdevice->load('room', 'pairedBy'), 'stay' => IS::stayFor($tvdevice),
            'commands' => TvCommand::with('issuer')->where('tv_device_id', $tvdevice->id)->latest('id')->limit(20)->get(),
            'sessions' => TvSession::with(['stay.guest', 'stay.reservation.guest', 'template'])->where('tv_device_id', $tvdevice->id)->latest('id')->limit(15)->get(),
            'events' => TvEvent::where('tv_device_id', $tvdevice->id)->latest('created_at')->latest('id')->limit(25)->get(),
            'rooms' => $this->rooms(), 'channels' => TvChannel::where('is_active', true)->orderBy('number')->get(['number', 'name'])]);
    }

    protected function deviceData(Request $r): array
    {
        return $r->validate(['kind' => 'required|string|max:10', 'room_id' => 'nullable|integer|owned:rooms', 'location' => 'nullable|string|max:80', 'name' => 'nullable|string|max:80',
            'platform' => 'required|string|max:12', 'model' => 'nullable|string|max:60', 'mac' => 'nullable|string|max:17', 'serial' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:255']);
    }

    public function deviceStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $d = IS::saveDevice($this->deviceData($r), null, $r->user());

            return redirect()->route('iptv.device', $d)->with('success', "{$d->name} added. Set the TV's start page to the URL below.");
        }, 'Saved.');
    }

    public function deviceUpdate(Request $r, TvDevice $tvdevice)
    {
        return $this->attempt(fn () => 'Saved: '.IS::saveDevice($this->deviceData($r), $tvdevice, $r->user())->name.'.', 'Saved.');
    }

    public function pair(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $d = IS::claim((string) $r->input('code'), $this->deviceData($r), $r->user());

            return "Paired: {$d->name} ({$d->place()}). The TV switches to the guest portal within a few seconds.";
        }, 'Paired.');
    }

    public function rotate(Request $r, TvDevice $tvdevice)
    {
        return $this->attempt(function () use ($r, $tvdevice) {
            IS::rotate($tvdevice, $r->user());

            return redirect()->route('iptv.device', $tvdevice)->with('success', 'New start URL issued – enter it on the TV. The old URL no longer works.');
        }, 'Done.');
    }

    public function status(Request $r, TvDevice $tvdevice)
    {
        return $this->attempt(fn () => IS::setStatus($tvdevice, (string) $r->input('status'), $r->user()), 'TV updated.');
    }

    public function deviceDelete(Request $r, TvDevice $tvdevice)
    {
        return $this->attempt(function () use ($r, $tvdevice) {
            IS::deleteDevice($tvdevice, $r->user());

            return redirect()->route('iptv.devices')->with('success', 'TV removed.');
        }, 'Removed.');
    }

    public function command(Request $r, TvDevice $tvdevice)
    {
        return $this->attempt(function () use ($r, $tvdevice) {
            $cmd = (string) $r->input('command');
            IS::command($tvdevice, $cmd, ['level' => $r->input('level'), 'channel' => $r->input('channel'), 'text' => $r->input('text')], $r->user());

            return (TvCommand::COMMANDS[$cmd] ?? 'Command').' sent to '.$tvdevice->name.' – it runs on the next heartbeat.';
        }, 'Sent.');
    }

    /** from the reservation page: show the welcome again / clear the TV (e.g. the guest reports the previous guest's name) */
    public function stayReset(Request $r, ReservationRoom $stay)
    {
        return $this->attempt(function () use ($r, $stay) {
            $list = TvDevice::where('room_id', $stay->room_id)->where('status', 'active')->get();
            if ($list->isEmpty()) {
                throw new RuntimeException('There is no active TV in room '.$stay->room?->room_no.'.');
            }
            foreach ($list as $d) {
                IS::command($d, 'reset', [], $r->user());
            }

            return 'The TV in room '.$stay->room?->room_no.' will show the welcome screen again.';
        }, 'Done.');
    }

    // ------------------------------------------------------------------ channels & guide

    public function channels(Request $r)
    {
        $list = TvChannel::withCount(['programmes as upcoming' => fn ($q) => $q->where('ends_at', '>', now())])
            ->when($r->filled('category'), fn ($q) => $q->where('category', $r->query('category')))->orderBy('number')->get();

        return view('iptv.channels', ['list' => $list, 'nn' => IS::nowNext(), 'alerts' => IS::alerts()]);
    }

    protected function channelData(Request $r): array
    {
        return $r->validate(['number' => 'required|integer', 'name' => 'required|string|max:80', 'category' => 'required|string|max:14', 'language' => 'nullable|string|max:30',
            'stream_url' => 'required|string|max:255', 'epg_id' => 'nullable|string|max:80']) + ['is_radio' => $r->boolean('is_radio'), 'is_active' => $r->boolean('is_active')];
    }

    public function channelStore(Request $r)
    {
        return $this->attempt(fn () => 'Channel '.IS::saveChannel($this->channelData($r), null, $r->user())->number.' added.', 'Saved.');
    }

    public function channelUpdate(Request $r, TvChannel $tvchannel)
    {
        return $this->attempt(fn () => 'Channel '.IS::saveChannel($this->channelData($r), $tvchannel, $r->user())->number.' saved.', 'Saved.');
    }

    public function channelDelete(Request $r, TvChannel $tvchannel)
    {
        return $this->attempt(fn () => IS::deleteChannel($tvchannel, $r->user()), 'Channel removed.');
    }

    public function m3uImport(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $r->validate(['file' => 'nullable|file|max:2048', 'text' => 'nullable|string|max:500000']);
            $text = $r->hasFile('file') ? (string) file_get_contents($r->file('file')->getRealPath()) : (string) $r->input('text');
            $o = IS::importM3u($text, $r->user());

            return "Line-up imported: {$o['created']} added, {$o['updated']} updated".($o['skipped'] ? ", {$o['skipped']} skipped (no name or an invalid stream)" : '').'.';
        }, 'Imported.');
    }

    public function m3uExport()
    {
        return response(IS::exportM3u(), 200, ['Content-Type' => 'audio/x-mpegurl; charset=utf-8', 'Content-Disposition' => 'attachment; filename="lineup-'.hotel()->code.'.m3u"']);
    }

    public function epgImport(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $r->validate(['file' => 'required|file|max:5120']);
            $o = IS::importXmltv((string) file_get_contents($r->file('file')->getRealPath()), $r->user());

            return "Programme guide imported: {$o['programmes']} programmes on {$o['channels']} channel(s)".($o['linked'] ? ", {$o['linked']} channel(s) linked by name" : '').($o['skipped'] ? ", {$o['skipped']} skipped" : '').'.';
        }, 'Imported.');
    }

    // ------------------------------------------------------------------ movies & rentals

    public function movies(Request $r)
    {
        [$from, $to] = $this->range($r, bdate()->copy()->startOfMonth()->toDateString());
        $rentals = TvPurchase::with(['room', 'stay.guest', 'stay.reservation.guest', 'refunder'])->whereBetween('starts_at', [$from.' 00:00:00', $to.' 23:59:59'])->latest('id')->get();

        return view('iptv.movies', ['list' => TvMovie::withCount('purchases')->orderBy('sort')->orderBy('title')->get(), 'rentals' => $rentals, 'from' => $from, 'to' => $to, 'cfg' => IS::cfg()]);
    }

    protected function movieData(Request $r): array
    {
        return $r->validate(['title' => 'required|string|max:120', 'genre' => 'required|string|max:20', 'rating' => 'required|string|max:6', 'duration_min' => 'required|integer',
            'year' => 'nullable|integer', 'language' => 'nullable|string|max:30', 'synopsis' => 'nullable|string|max:500', 'price' => 'required|numeric', 'rental_hours' => 'required|integer',
            'stream_url' => 'nullable|string|max:255', 'sort' => 'nullable|integer']) + ['is_adult' => $r->boolean('is_adult'), 'is_active' => $r->boolean('is_active')];
    }

    public function movieStore(Request $r)
    {
        return $this->attempt(fn () => '“'.IS::saveMovie($this->movieData($r), null, $r->user())->title.'” added.', 'Saved.');
    }

    public function movieUpdate(Request $r, TvMovie $tvmovie)
    {
        return $this->attempt(fn () => '“'.IS::saveMovie($this->movieData($r), $tvmovie, $r->user())->title.'” saved.', 'Saved.');
    }

    public function refund(Request $r, TvPurchase $tvpurchase)
    {
        return $this->attempt(function () use ($r, $tvpurchase) {
            IS::refund($tvpurchase, (string) $r->input('reason'), $r->user());

            return "{$tvpurchase->number} refunded – the charge is voided on the folio.";
        }, 'Refunded.');
    }

    // ------------------------------------------------------------------ welcome screens & pages

    public function welcome(Request $r)
    {
        $stays = ReservationRoom::with(['room', 'guest', 'reservation.guest', 'reservation.company'])->where('status', 'checked_in')->whereNotNull('room_id')->get()->sortBy(fn ($s) => $s->room?->room_no)->values();
        $stay = $r->filled('stay') ? $stays->firstWhere('id', (int) $r->query('stay')) : $stays->first();
        $preview = null;
        if ($stay) {
            $lang = $r->filled('lang') && isset(TvLang::LANGS[$r->query('lang')]) ? $r->query('lang') : IS::language(null, $stay);
            $tpl = $r->filled('template') ? TvWelcomeTemplate::find((int) $r->query('template')) : null;
            $preview = ['stay' => $stay, 'lang' => $lang, 'segments' => IS::segmentsOf($stay), 'auto' => IS::resolveTemplate($stay, $lang)] + ($tpl
                ? ['template' => $tpl, 'headline' => IS::render($tpl->headline, $stay), 'body' => IS::render($tpl->body, $stay), 'theme' => $tpl->theme]
                : IS::welcome($stay, $lang) + ['template' => null]);
        }

        return view('iptv.welcome', ['list' => TvWelcomeTemplate::orderBy('language')->orderByDesc('priority')->orderBy('name')->get(), 'stays' => $stays, 'preview' => $preview, 'welcomeImage' => IS::cfg()['welcome_image'],
            'shown' => TvSession::whereNotNull('tv_welcome_template_id')->selectRaw('tv_welcome_template_id, COUNT(*) n')->groupBy('tv_welcome_template_id')->pluck('n', 'tv_welcome_template_id')]);
    }

    protected function templateData(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:80', 'language' => 'required|string|max:5', 'segment' => 'required|string|max:12', 'priority' => 'nullable|integer',
            'headline' => 'required|string|max:120', 'body' => 'nullable|string|max:1000', 'theme' => 'required|string|max:12', 'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date'])
            + ['is_active' => $r->boolean('is_active')];
    }

    public function templateStore(Request $r)
    {
        return $this->attempt(fn () => 'Welcome screen “'.IS::saveTemplate($this->templateData($r), null, $r->user())->name.'” added.', 'Saved.');
    }

    public function templateUpdate(Request $r, TvWelcomeTemplate $tvtemplate)
    {
        return $this->attempt(fn () => 'Welcome screen “'.IS::saveTemplate($this->templateData($r), $tvtemplate, $r->user())->name.'” saved.', 'Saved.');
    }

    public function pages()
    {
        return view('iptv.pages', ['list' => TvPage::orderBy('language')->orderBy('sort')->orderBy('title')->get(),
            'views' => TvEvent::where('event', 'page')->where('created_at', '>=', now()->subDays(30))->selectRaw('target, COUNT(*) n')->groupBy('target')->pluck('n', 'target')]);
    }

    protected function pageData(Request $r): array
    {
        return $r->validate(['title' => 'required|string|max:80', 'category' => 'required|string|max:12', 'icon' => 'required|string|max:16', 'language' => 'required|string|max:5',
            'body' => 'required|string|max:3000', 'sort' => 'nullable|integer']) + ['is_active' => $r->boolean('is_active')];
    }

    public function pageStore(Request $r)
    {
        return $this->attempt(fn () => 'Page “'.IS::savePage($this->pageData($r), null, $r->user())->title.'” added.', 'Saved.');
    }

    public function pageUpdate(Request $r, TvPage $tvpage)
    {
        return $this->attempt(fn () => 'Page “'.IS::savePage($this->pageData($r), $tvpage, $r->user())->title.'” saved.', 'Saved.');
    }

    // ------------------------------------------------------------------ messages & emergency

    public function messages(Request $r)
    {
        $list = TvMessage::with(['sender', 'closer', 'stay.room'])->withCount('reads')
            ->when($r->filled('kind'), fn ($q) => $q->where('kind', $r->query('kind')))->latest('id')->paginate(30)->withQueryString();
        $inhouse = ReservationRoom::with(['room', 'guest', 'reservation.guest'])->where('status', 'checked_in')->whereNotNull('room_id')->get()->sortBy(fn ($s) => $s->room?->room_no)->values();
        $groups = Reservation::whereHas('rooms', fn ($q) => $q->where('status', 'checked_in'), '>=', 2)->orderBy('reservation_no')->get(['id', 'reservation_no', 'group_name']);

        return view('iptv.messages', ['list' => $list, 'inhouse' => $inhouse, 'floors' => Floor::orderBy('id')->get(['id', 'name']), 'groups' => $groups, 'emergency' => IS::emergency(),
            'reach' => $list->getCollection()->mapWithKeys(fn ($m) => [$m->id => in_array($m->state(), ['live', 'scheduled'], true) ? IS::reach($m) : null])]);
    }

    public function messageStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $m = IS::send($r->validate(['target' => 'required|string|max:10', 'room_id' => 'nullable|integer|owned:rooms', 'floor_id' => 'nullable|integer|owned:floors',
                'reservation_id' => 'nullable|integer|owned:reservations', 'title' => 'required|string|max:80', 'body' => 'required|string|max:500', 'priority' => 'nullable|string|max:6',
                'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date']) + ['requires_ack' => $r->boolean('requires_ack'), 'priority' => $r->input('priority', 'normal')], $r->user());

            return "Message {$m->number} sent to {$m->targetLabel()} (".IS::reach($m).' TV'.(IS::reach($m) === 1 ? '' : 's').').';
        }, 'Sent.');
    }

    public function messageCancel(Request $r, TvMessage $tvmessage)
    {
        return $this->attempt(fn () => IS::cancelMessage($tvmessage, (string) $r->input('reason'), $r->user()), 'Message withdrawn from the screens.');
    }

    public function emergency(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $m = IS::emergencyStart($r->only(['title', 'body', 'confirm']), $r->user());

            return "EMERGENCY alert {$m->number} is on every screen (".IS::reach($m).' TVs). Clear it with “All clear” when the situation is over.';
        }, 'Sent.');
    }

    public function emergencyClear(Request $r, TvMessage $tvmessage)
    {
        return $this->attempt(fn () => IS::emergencyClear($tvmessage, $r->input('note'), $r->user()), 'All clear sent – the alert is off the screens.');
    }

    // ------------------------------------------------------------------ analytics & settings

    public function analytics(Request $r)
    {
        [$from, $to] = $this->range($r, bdate()->copy()->subDays(29)->toDateString());

        return view('iptv.analytics', ['from' => $from, 'to' => $to, 'a' => IS::analytics($from, $to)]);
    }

    public function settings()
    {
        return view('iptv.settings', ['cfg' => IS::cfg(), 'raw' => collect(IS::DEFAULTS)->map(fn ($v, $k) => setting($k, $v))->all(), 'welcomeImage' => IS::cfg()['welcome_image'], 'setupUrl' => route('tv.setup', hotel()->code),
            'channels' => TvChannel::where('is_active', true)->orderBy('number')->get(['number', 'name'])]);
    }

    public function saveSettings(Request $r)
    {
        $r->validate([
            'welcome_image_file' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'reset_welcome_image' => 'nullable|boolean',
        ]);

        $data = $r->input();
        $uploadedPath = null;
        if ($r->hasFile('welcome_image_file')) {
            $directory = public_path('uploads/iptv');
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                return back()->withInput()->with('error', 'The TV photo upload folder could not be created. Check public/uploads permissions.');
            }
            $file = $r->file('welcome_image_file');
            $filename = 'welcome-'.hotel()->id.'-'.bin2hex(random_bytes(8)).'.'.$file->extension();
            try {
                $file->move($directory, $filename);
            } catch (\Throwable $e) {
                return back()->withInput()->with('error', 'The hotel welcome photo could not be saved. Check public/uploads permissions.');
            }
            $uploadedPath = 'uploads/iptv/'.$filename;
            $data['welcome_image'] = $uploadedPath;
        } elseif ($r->boolean('reset_welcome_image')) {
            $data['welcome_image'] = IS::DEFAULTS['tv_welcome_image'];
        }

        return $this->attempt(function () use ($data, $uploadedPath) {
            try {
                IS::saveSettings($data);
            } catch (\Throwable $e) {
                if ($uploadedPath && is_file(public_path($uploadedPath))) {
                    @unlink(public_path($uploadedPath));
                }
                throw $e;
            }

            return 'IPTV settings saved.';
        }, 'IPTV settings saved.');
    }
}
