<?php

namespace Database\Seeders\Concerns;

use App\Models;
use App\Services\FolioService;
use App\Services\HotelProvisioner;
use App\Services\ApService;
use App\Services\ForexService;
use App\Services\LedgerService;
use App\Services\StayService;
use App\Services\TaxService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo-data seeders — split out of DatabaseSeeder (one file per domain).
 * Composed into DatabaseSeeder via `use`; every method keeps its original visibility and body.
 */
trait SeedPlatformConcern
{
    protected function seedKiosk(bool $rich): void
    {
        $hid = \App\Support\Tenant::id();
        $admin = Models\User::where('hotel_id', $hid)->where('username', 'admin')->first();
        if (! $admin) {
            return;
        }
        $fd = Models\User::where('hotel_id', $hid)->where('username', 'frontdesk')->first() ?? $admin;
        $real = now()->copy();
        $h = fn (string $k, int $n) => crc32('ksk:'.$hid.':'.$k) % max(1, $n); // deterministic "random"
        $mk = fn (array $a) => Models\Kiosk::create($a + ['token' => \App\Services\KioskService::newToken(), 'paired_at' => $real->copy()->subDays(40), 'paired_by' => $admin->id]);
        $k1 = $mk(['name' => 'Lobby Kiosk 1', 'location' => 'Main lobby – left of reception', 'status' => 'active', 'serial' => 'KSK-A1-2291', 'last_seen_at' => $real, 'last_ip' => '10.10.4.21', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128 Kiosk']);
        $k2 = $mk(['name' => 'Lobby Kiosk 2', 'location' => 'Main lobby – by the lifts', 'status' => 'active', 'serial' => 'KSK-A1-2292', 'allow_walkin' => false, 'last_seen_at' => $real, 'last_ip' => '10.10.4.22', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128 Kiosk']);
        if (! $rich) {
            return;
        }
        $mk(['name' => 'Pool Terrace Kiosk', 'location' => 'Pool terrace', 'status' => 'disabled', 'serial' => 'KSK-T2-0107', 'allow_walkin' => false, 'has_encoder' => false, 'last_seen_at' => $real->copy()->subDays(9), 'last_ip' => '10.10.6.40', 'notes' => 'Out of service – screen glare in the afternoon; awaiting a canopy']);

        $from = $real->copy()->subDays(13)->startOfDay();
        $today = $real->copy()->startOfDay();
        $events = collect();
        $ins = Models\ReservationRoom::with('reservation', 'guest', 'reservation.guest')->whereBetween('checked_in_at', [$from, $today])->whereNotNull('room_id')->get();
        foreach ($ins as $st) {
            $pick = $h('in'.$st->id, 10);
            $walk = (int) $st->reservation?->business_source_id === (int) Models\BusinessSource::where('type', 'walk_in')->value('id');
            if ($pick < 6) {
                $events->push(['at' => $st->checked_in_at, 'kind' => $walk && $pick < 3 ? 'walkin' : 'checkin', 'stay' => $st, 'status' => 'completed']);
            } elseif ($pick === 6) {
                $events->push(['at' => $st->checked_in_at, 'kind' => 'checkin', 'stay' => $st, 'status' => 'assist', 'reason' => ['pay_desk', 'no_room', 'vip', 'multi'][$h('r'.$st->id, 4)]]);
            }
        }
        foreach (Models\ReservationRoom::with('reservation', 'guest', 'reservation.guest')->whereBetween('checked_out_at', [$from, $today])->whereNotNull('room_id')->get() as $st) {
            $pick = $h('out'.$st->id, 10);
            if ($pick < 5) {
                $events->push(['at' => $st->checked_out_at, 'kind' => 'checkout', 'stay' => $st, 'status' => 'completed']);
            } elseif ($pick === 5) {
                $events->push(['at' => $st->checked_out_at, 'kind' => 'checkout', 'stay' => $st, 'status' => 'assist', 'reason' => ['bill', 'checkout'][$h('ro'.$st->id, 2)]]);
            }
        }
        for ($i = 0; $i < 7; $i++) { // visitors who walked away / started over
            $events->push(['at' => $from->copy()->addDays($h('ab'.$i, 13))->setTime(8 + $h('abh'.$i, 13), $h('abm'.$i, 60)), 'kind' => ['checkin', 'checkout', 'walkin'][$h('abk'.$i, 3)], 'stay' => null, 'status' => $i % 3 ? 'abandoned' : 'cancelled']);
        }
        $events->push(['at' => $from->copy()->addDays(4)->setTime(21, 40), 'kind' => 'checkin', 'stay' => null, 'status' => 'assist', 'reason' => 'not_found']);
        $events->push(['at' => $today->copy()->setTime(7, 52), 'kind' => 'walkin', 'stay' => null, 'status' => 'abandoned']);

        foreach ($events->sortBy(fn ($e) => $e['at']->timestamp)->values() as $n => $e) {
            $st = $e['stay'];
            $g = $st ? ($st->guest ?? $st->reservation?->guest) : null;
            $kiosk = ($e['kind'] === 'walkin' || $h('k'.$n, 3) === 0) ? $k1 : ($h('k2'.$n, 2) ? $k2 : $k1);
            $dur = match ($e['kind']) { 'checkout' => 55 + $h('d'.$n, 70), 'walkin' => 170 + $h('d'.$n, 120), default => 95 + $h('d'.$n, 110) };
            $end = $e['at']->copy()->addSeconds(20 + $h('e'.$n, 30));
            $start = $end->copy()->subSeconds($dur);
            $t = fn (int $sec) => $start->copy()->addSeconds($sec)->format('H:i:s');
            $trail = [[$t(0), 'start', Models\KioskSession::KINDS[$e['kind']]]];
            $row = ['kiosk_id' => $kiosk->id, 'number' => \App\Support\Numbering::next('kiosk_session'), 'token' => \App\Services\KioskService::newToken(), 'kind' => $e['kind'], 'status' => $e['status'],
                'started_at' => $start, 'last_activity_at' => $end, 'completed_at' => $end, 'created_at' => $start, 'updated_at' => $end];
            if ($st) {
                $row += ['reservation_id' => $st->reservation_id, 'reservation_room_id' => $st->id, 'guest_id' => $g?->id, 'room_id' => $st->room_id,
                    'lookup' => $e['kind'] === 'walkin' ? 'walkin' : ($e['kind'] === 'checkout' ? ($h('lk'.$n, 2) ? 'room' : 'reference') : ($h('lq'.$n, 4) ? 'reference' : 'qr'))];
                $trail[] = [$t(12), $e['kind'] === 'walkin' ? 'booked' : 'found', $st->reservation?->reservation_no.' · '.($g?->full_name ?? 'guest')];
            }
            if ($e['status'] === 'completed' && $e['kind'] !== 'checkout') {
                $row += ['details_ok' => true, 'id_captured' => true, 'signed' => true, 'keys_issued' => 1 + $h('kc'.$n, 2)];
                $trail = array_merge($trail, [[$t(40), 'details', 'Confirmed'], [$t(62), 'document', 'ID photo 1600×1000'], [$t(80), 'sign', 'Registration card signed'],
                    [$t($dur - 30), 'room', 'Checked in to room '.$st->room?->room_no], [$t($dur - 8), 'keys', $row['keys_issued'].' key card(s) encoded'], [$t($dur), 'done', Models\KioskSession::KINDS[$e['kind']].' finished']]);
                $row['step'] = 'done';
            } elseif ($e['status'] === 'completed') {
                $rating = $h('rt'.$n, 3) ? 4 + $h('rv'.$n, 2) : null;
                $row += ['rating' => $rating];
                $trail = array_merge($trail, [[$t(30), 'bill', null], [$t($dur - 15), 'checkout', 'Checked out of room '.$st->room?->room_no], [$t($dur - 5), 'feedback', $rating ? $rating.'★' : 'Skipped'], [$t($dur), 'done', 'Check-out finished']]);
                $row['step'] = 'done';
            } elseif ($e['status'] === 'assist') {
                $why = $e['reason'];
                $note = ['pay_desk' => 'Guest chose to pay at the desk', 'no_room' => 'Room being prepared ('.$st?->roomType?->name.')', 'vip' => 'Welcome back! Our team would love to welcome you personally',
                    'multi' => 'Your booking has several rooms', 'bill' => 'Guest has a question about the bill', 'checkout' => 'There are 1 unsettled POS order(s) for this room.', 'not_found' => 'Three look-ups did not match (RES-00199)'][$why] ?? null;
                $row['step'] = ['pay_desk' => 'pay', 'no_room' => 'room', 'bill' => 'bill', 'checkout' => 'bill'][$why] ?? 'find';
                $helped = $end->copy()->addMinutes(2 + $h('hm'.$n, 6));
                $row += ['assist_reason' => $why, 'assist_note' => $note, 'assist_at' => $end, 'resolved_by' => $fd->id, 'resolved_at' => $helped,
                    'resolution' => $e['kind'] === 'checkout' ? 'Settled and checked out at the desk' : 'Checked in at the desk', 'failed_lookups' => $why === 'not_found' ? 3 : 0];
                $trail[] = [$t($dur), 'assist', Models\KioskSession::ASSIST_REASONS[$why].($note ? ' – '.$note : '')];
                $trail[] = [$helped->format('H:i:s'), 'resolved', $fd->name.': '.$row['resolution']];
            } else {
                $row['step'] = $e['kind'] === 'walkin' ? 'rooms' : 'find';
                $row['failed_lookups'] = $e['kind'] === 'walkin' ? 0 : $h('fl'.$n, 3);
                if ($row['failed_lookups']) {
                    $trail[] = [$t(25), 'find', 'Not found: '.['RES-00'.(40 + $h('x'.$n, 50)), '3'.$h('y'.$n, 99)][$h('z'.$n, 2)]];
                }
                $trail[] = [$t($dur), $e['status'], $e['status'] === 'abandoned' ? 'Timed out' : 'Guest started over'];
            }
            $s = new Models\KioskSession($row + ['trail' => $trail]);
            $s->timestamps = false;
            $s->save();
        }
        // a guest waiting at the kiosk right now
        $wait = $real->copy()->subMinutes(4);
        $s = new Models\KioskSession(['kiosk_id' => $k2->id, 'number' => \App\Support\Numbering::next('kiosk_session'), 'token' => \App\Services\KioskService::newToken(), 'kind' => 'checkin', 'status' => 'assist', 'step' => 'find',
            'failed_lookups' => 3, 'assist_reason' => 'not_found', 'assist_note' => 'Three look-ups did not match (EXP-7731902)', 'assist_at' => $wait, 'started_at' => $wait->copy()->subSeconds(95), 'last_activity_at' => $wait, 'completed_at' => $wait,
            'trail' => [[$wait->copy()->subSeconds(95)->format('H:i:s'), 'start', 'Check-in'], [$wait->copy()->subSeconds(70)->format('H:i:s'), 'find', 'Not found: EXP-7731902'], [$wait->copy()->subSeconds(40)->format('H:i:s'), 'find', 'Not found: 7731902'],
                [$wait->copy()->subSeconds(5)->format('H:i:s'), 'find', 'Not found: EXP7731902'], [$wait->format('H:i:s'), 'assist', 'Booking not found – Three look-ups did not match (EXP-7731902)']],
            'created_at' => $wait, 'updated_at' => $wait]);
        $s->timestamps = false;
        $s->save();
    }

    protected function seedIptv(bool $rich): void
    {
        $IS = \App\Services\IptvService::class;
        $IS::ensureDefaults();
        $hid = \App\Support\Tenant::id();
        $admin = Models\User::where('hotel_id', $hid)->where('username', 'admin')->first();
        if (! $admin) {
            return;
        }
        Auth::setUser($admin);
        $clock = Carbon::getTestNow();
        $real = now()->copy(); // the clock before any time travel below (getTestNow() may be a closure in tests)
        $h = fn (string $k, int $n) => crc32($hid.':'.$k) % max(1, $n); // deterministic "random"

        // ── TVs: every room, public screens
        $rooms = Models\Room::where('is_active', true)->orderBy('room_no')->get();
        $devs = collect();
        foreach ($rooms as $i => $r) {
            $floor2 = str_starts_with((string) $r->room_no, '2');
            $d = $IS::saveDevice(['kind' => 'room', 'room_id' => $r->id, 'platform' => $floor2 ? 'lg' : 'samsung', 'model' => $floor2 ? 'LG 43UR762H (Pro:Centric)' : 'Samsung HG43AU800 (Tizen)',
                'mac' => sprintf('A4:30:7A:%02X:%02X:%02X', $hid, intdiv($i, 256), $i % 256), 'serial' => ($floor2 ? 'LG' : 'SA').(2024000 + $hid * 1000 + $i)], null, $admin);
            $d->forceFill(['paired_at' => now()->subDays(40 - $i % 9), 'last_seen_at' => now()->subSeconds(5 + $h('seen'.$i, 40)), 'last_ip' => '10.20.'.$hid.'.'.(20 + $i),
                'user_agent' => $floor2 ? 'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 Chrome/94.0 Safari/537.36 WebAppManager' : 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 6.5) AppleWebKit/537.36 SamsungBrowser/3.0 TV Safari/537.36'])->saveQuietly();
            $devs[$r->room_no] = $d;
        }
        $screens = $rich ? [['Lobby', 'samsung'], ['Restaurant entrance', 'lg'], ['Conference foyer', 'android']] : [['Lobby', 'samsung']];
        foreach ($screens as $k => [$loc, $pf]) {
            $s = $IS::saveDevice(['kind' => 'signage', 'location' => $loc, 'platform' => $pf, 'model' => $pf === 'android' ? 'Philips 55BDL (Android)' : null], null, $admin);
            $s->forceFill(['last_seen_at' => now()->subSeconds(8 + $k * 3), 'last_ip' => '10.20.'.$hid.'.'.(200 + $k)])->saveQuietly();
        }

        // ── channels & programme guide
        $lineup = [
            ['GTV', 'local', 'English'], ['TV3', 'local', 'English'], ['UTV', 'local', 'Twi'], ['Joy News', 'news', 'English'], ['JoyPrime', 'local', 'English'], ['Adom TV', 'local', 'Twi'],
            ['Citi TV', 'local', 'English'], ['TV Africa', 'local', 'English'], ['Metro TV', 'local', 'English'], ['BBC World News', 'news', 'English'], ['CNN International', 'news', 'English'],
            ['Al Jazeera English', 'news', 'English'], ['France 24 (Français)', 'news', 'French'], ['France 24 (English)', 'news', 'English'], ['CGTN', 'news', 'English'], ['Bloomberg TV', 'business', 'English'],
            ['SuperSport Football', 'sports', 'English'], ['SuperSport Premier League', 'sports', 'English'], ['beIN Sports', 'sports', 'English'], ['Eurosport', 'sports', 'English'],
            ['Africa Magic Showcase', 'movies', 'English'], ['Akwaaba Magic', 'movies', 'Twi'], ['M-Net Movies', 'movies', 'English'], ['Discovery Channel', 'documentary', 'English'],
            ['National Geographic', 'documentary', 'English'], ['Cartoon Network', 'kids', 'English'], ['Trace Mziki', 'music', 'English'], ['Deutsche Welle', 'news', 'German'],
            ['Hotel Info Channel', 'hotel', 'English'], ['Joy FM 99.7', 'radio', 'English'], ['Citi FM 97.3', 'radio', 'English'], ['Peace FM 104.3', 'radio', 'Twi'],
        ];
        if (! $rich) {
            $lineup = array_values(array_filter($lineup, fn ($c) => in_array($c[0], ['GTV', 'TV3', 'Joy News', 'BBC World News', 'CNN International', 'SuperSport Football', 'Africa Magic Showcase', 'Cartoon Network', 'Hotel Info Channel', 'Joy FM 99.7'], true)));
        }
        $chs = [];
        foreach ($lineup as $i => [$name, $cat, $lang]) {
            $no = $cat === 'radio' ? 900 + $i : ($cat === 'hotel' ? 100 : $i + 1);
            $chs[] = Models\TvChannel::create(['number' => $no, 'name' => $name, 'category' => $cat, 'language' => $lang,
                'stream_url' => $cat === 'radio' ? 'http://10.20.0.5:8000/radio/'.\Illuminate\Support\Str::slug($name) : 'udp://@239.10.'.$hid.'.'.($i + 1).':1234',
                'epg_id' => $cat === 'hotel' || $cat === 'radio' ? null : \Illuminate\Support\Str::slug($name).'.gh', 'is_radio' => $cat === 'radio', 'is_active' => true]);
        }
        $shows = [
            'local' => ['Morning Show', 'News at Noon', 'Ghallywood Classics', 'Talk of the Nation', 'Adom Kasa', 'Evening News', 'Kumkum Bhagya', 'Date Rush', 'Sports Highlights', 'Midnight Movie'],
            'news' => ['World News', 'Business Today', 'Newsday', 'HARDtalk', 'Africa Business Report', 'The Newsroom', 'Inside Story', 'Weather Watch', 'Focus on Africa', 'Newsnight'],
            'business' => ['Markets Open', 'Surveillance', 'The Close', 'Daybreak Africa', 'Commodities'],
            'sports' => ['Premier League: Arsenal v Chelsea', 'Ghana Premier League: Hearts of Oak v Asante Kotoko', 'Football Focus', 'AFCON Qualifier: Ghana v Angola', 'Match of the Day', 'La Liga Live', 'Tennis: Hard Court Final', 'Boxing Night'],
            'movies' => ['Family Movie', 'Sunday Premiere', 'Ghallywood Hit', 'Nollywood Night', 'Action Blockbuster', 'Romance Drama', 'Comedy Hour'],
            'documentary' => ['Wild Africa', 'How It\'s Made', 'Planet Ocean', 'Mega Engineering', 'Lost Kingdoms of Africa', 'Deadliest Catch'],
            'kids' => ['Cartoon Club', 'Adventure Time', 'We Bare Bears', 'The Amazing World of Gumball', 'Ben 10'],
            'music' => ['Afrobeats Top 20', 'Highlife Hour', 'Amapiano Mix', 'Gospel Sunday', 'Party Mix'],
            'hotel' => ['Welcome to the hotel', 'Saffron restaurant tour', 'Spa & wellness'],
        ];
        $rows = [];
        $base = now()->copy()->startOfHour()->subHours(6);
        foreach ($chs as $c) {
            if (! $c->epg_id) {
                continue;
            }
            $list = $shows[$c->category] ?? $shows['local'];
            $t = $base->copy();
            $k = $h('ch'.$c->id, 5);
            while ($t->lt(now()->addHours(36))) {
                $len = [30, 60, 60, 90, 120][($k++) % 5];
                $hr = (int) $t->format('G');
                $fits = fn (string $x) => ! (str_contains($x, 'Morning') && ($hr < 5 || $hr > 10)) && ! (str_contains($x, 'Noon') && ($hr < 11 || $hr > 14))
                    && ! (str_contains($x, 'Evening') && ($hr < 17 || $hr > 21)) && ! ((str_contains($x, 'Midnight') || str_contains($x, 'night')) && $hr >= 3 && $hr < 21)
                    && ! (str_contains($x, 'Markets Open') && ($hr < 13 || $hr > 16)) && ! (str_contains($x, 'Daybreak') && ($hr < 4 || $hr > 9));
                for ($try = 0, $title = $list[$k % count($list)]; ! $fits($title) && $try < count($list); $try++) {
                    $title = $list[(++$k) % count($list)]; // a guide that makes sense for the time of day
                }
                $rows[] = ['hotel_id' => $hid, 'tv_channel_id' => $c->id, 'starts_at' => $t->toDateTimeString(), 'ends_at' => $t->copy()->addMinutes($len)->toDateTimeString(),
                    'title' => $title, 'description' => $c->category === 'sports' ? 'Live coverage with build-up and analysis.' : ($c->category === 'news' ? 'The latest national and international news.' : null), 'genre' => $c->categoryLabel()];
                $t->addMinutes($len);
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Models\TvProgramme::insert($chunk);
        }

        // ── movies
        $movies = [
            ['The Gold Coast Heist', 'action', 'PG-13', 118, 2025, 85, 'A retired locksmith is pulled into one last job: the gold vault under an Accra bank.'],
            ['Harmattan', 'drama', 'PG', 104, 2024, 70, 'A family in Tamale waits out the dusty season – and a secret that has kept them apart for years.'],
            ['Kente & Kisses', 'romance', 'PG', 97, 2025, 70, 'A London fashion buyer falls for the Bonwire weaver she came to sign.'],
            ['Osu Nights', 'thriller', '16', 111, 2023, 85, 'A taxi driver witnesses a crime on Oxford Street and has one night to prove it.'],
            ['Ananse and the Sky God', 'animation', 'G', 88, 2024, 60, 'The clever spider sets out to win all the world\'s stories – an animated Ghanaian folktale for all ages.'],
            ['The Last Chop Bar', 'comedy', 'PG', 94, 2025, 70, 'Three friends try to save their grandmother\'s chop bar from a fast-food chain.'],
            ['Volta', 'documentary', 'G', 76, 2023, 50, 'The story of Africa\'s largest man-made lake, told by the fishermen who live on it.'],
            ['Jollof Wars', 'nollywood', 'PG', 101, 2024, 70, 'Ghana v Nigeria in a televised cook-off that gets very, very personal.'],
            ['Border Run', 'action', '16', 109, 2025, 85, 'An ECOWAS customs officer uncovers a smuggling ring on the Aflao border.'],
            ['Sankofa Road', 'drama', 'PG-13', 122, 2024, 70, 'A diaspora son returns to Cape Coast to find where his family story began.'],
            ['Deep Blue Lagos', 'thriller', '16', 106, 2023, 85, 'A diver hired to search a sunken tanker finds more than oil.'],
            ['Little Kofi\'s Big Match', 'family', 'G', 92, 2025, 60, 'A nine-year-old from Nima dreams of playing for the Black Stars.'],
        ];
        if (! $rich) {
            $movies = array_slice($movies, 0, 4);
        }
        $mv = [];
        foreach ($movies as $i => [$t, $g, $rt, $min, $yr, $price, $syn]) {
            $mv[$t] = Models\TvMovie::create(['title' => $t, 'genre' => $g, 'rating' => $rt, 'duration_min' => $min, 'year' => $yr, 'language' => 'English', 'synopsis' => $syn, 'price' => $price,
                'rental_hours' => 24, 'stream_url' => 'http://10.20.0.6/vod/'.\Illuminate\Support\Str::slug($t).'/index.m3u8', 'is_adult' => false, 'is_active' => true, 'sort' => $i]);
        }
        if (! $rich) {
            Auth::forgetUser();

            return;
        }

        // ── extra welcome screen & pages
        Models\TvWelcomeTemplate::create(['name' => 'Group / conference delegates', 'language' => 'en', 'segment' => 'group', 'priority' => 4, 'theme' => 'forest', 'is_active' => true,
            'headline' => 'Akwaaba, {name} – welcome to {hotel}', 'body' => "We are delighted to host your group. Your programme and today's events are on the lobby screens.\nWi-Fi: {wifi_name} · password {wifi_password}"]);
        Models\TvWelcomeTemplate::create(['name' => 'Independence Day week', 'language' => 'en', 'segment' => 'all', 'priority' => 1, 'theme' => 'gold', 'is_active' => false,
            'valid_from' => '2027-03-01', 'valid_to' => '2027-03-08', 'headline' => 'Happy Independence Day, {name}!', 'body' => 'Join us for the 6th March celebrations at the pool deck – kelewele, highlife and fireworks.']);
        $IS::savePage(['title' => 'Pool & beach shuttle', 'category' => 'facilities', 'icon' => 'star', 'language' => 'en', 'sort' => 6, 'is_active' => true,
            'body' => "The rooftop pool is open 06:00–22:00; towels are at the pool bar.\nA free shuttle to Labadi Beach leaves the lobby at 10:00, 13:00 and 16:00."], null, $admin);
        $IS::savePage(['title' => 'Offer: Sunday brunch', 'category' => 'offers', 'icon' => 'coffee', 'language' => 'en', 'sort' => 7, 'is_active' => true,
            'body' => "Every Sunday 11:30–15:00 at Saffron – live band, jollof station and bottomless sobolo.\nIn-house guests save 20%: show your key card."], null, $admin);
        $IS::savePage(['title' => 'Restaurants et bars', 'category' => 'dining', 'icon' => 'coffee', 'language' => 'fr', 'sort' => 1, 'is_active' => true,
            'body' => "Restaurant Saffron – petit-déjeuner 06h30–10h30, déjeuner 12h00–15h00, dîner 18h30–22h30.\nLe room service est disponible 24h/24 – commandez directement sur votre téléviseur."], null, $admin);

        // ── offline, disabled and pending TVs
        foreach (['108', '205'] as $no) {
            if (isset($devs[$no])) {
                $devs[$no]->forceFill(['last_seen_at' => now()->subHours(2)->subMinutes(12)])->saveQuietly();
            }
        }
        if (isset($devs['206'])) {
            $devs['206']->forceFill(['status' => 'disabled', 'notes' => 'Screen cracked – replacement ordered (PO pending)'])->saveQuietly();
        }
        $IS::startPairing('10.20.1.99', 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 6.5) AppleWebKit/537.36 SamsungBrowser/3.0 TV Safari/537.36');

        // ── 14 days of sessions & usage events (stays in their room while it had a TV)
        $pages = Models\TvPage::where('language', 'en')->pluck('title')->all();
        $chNames = collect($chs)->filter(fn ($c) => ! $c->is_radio)->map(fn ($c) => $c->number.' '.$c->name)->values()->all();
        $popular = [0, 3, 9, 16, 17, 20, 10, 1, 21, 6];
        $stays = Models\ReservationRoom::with(['guest', 'reservation.guest', 'room'])->whereIn('status', ['checked_in', 'checked_out'])->whereNotNull('room_id')->whereNotNull('checked_in_at')
            ->where('departure_date', '>=', bdate()->copy()->subDays(14)->toDateString())->get();
        $ev = [];
        foreach ($stays as $st) {
            $dev = $devs[$st->room?->room_no] ?? null;
            if (! $dev) {
                continue;
            }
            $g = $st->guest ?? $st->reservation?->guest;
            $lang = \App\Support\TvLang::forCountry($g?->nationality) ?? 'en';
            $in = $st->checked_in_at->copy();
            $out = $st->status === 'checked_out' ? ($st->checked_out_at ?? $st->departure_date->copy()->setTime(11, 0)) : now();
            if ($in->lt(now()->subDays(14))) {
                $in = now()->subDays(14)->setTime(15, 0);
            }
            $skip = $st->status === 'checked_in' && in_array($st->room?->room_no, ['107', '201'], true); // two in-house guests have not switched the TV on yet
            if ($skip || $out->lte($in)) {
                continue;
            }
            $welcome = $in->copy()->addMinutes(6 + $h('w'.$st->id, 50));
            Models\TvSession::create(['tv_device_id' => $dev->id, 'reservation_room_id' => $st->id, 'language' => $lang, 'started_at' => $in, 'welcomed_at' => $welcome->lt($out) ? $welcome : null,
                'ended_at' => $st->status === 'checked_out' ? $out : null, 'end_reason' => $st->status === 'checked_out' ? 'checkout' : null,
                'tv_welcome_template_id' => $IS::resolveTemplate($st, $lang)?->id]);
            $ev[] = ['event' => 'welcome', 'at' => $welcome, 'target' => null, 'dev' => $dev->id, 'st' => $st->id];
            for ($day = $in->copy()->startOfDay(); $day->lt($out); $day->addDay()) {
                $n = 3 + $h($st->id.$day->toDateString(), 11);
                for ($j = 0; $j < $n; $j++) {
                    $k = $h($st->id.$day->toDateString().$j, 1000);
                    $at = $day->copy()->setTime([7, 8, 12, 13, 18, 19, 20, 21, 22, 23][$k % 10], ($k * 7) % 60);
                    if ($at->lt($in) || $at->gt($out)) {
                        continue;
                    }
                    $type = ['home', 'tv', 'channel', 'channel', 'channel', 'info', 'page', 'dining', 'movies', 'services', 'explore', 'messages', 'bill', 'channel'][$k % 14];
                    $k2 = $h('t'.$st->id.$day->toDateString().$j, 1000); // independent of the event type
                    $target = match ($type) { 'channel' => $chNames[$popular[$k2 % 10] % count($chNames)] ?? $chNames[0], 'page' => $pages[$k2 % max(1, count($pages))] ?? null, default => null };
                    $ev[] = ['event' => $type, 'at' => $at, 'target' => $target, 'dev' => $dev->id, 'st' => $st->id];
                }
            }
            if ($lang !== 'en') {
                $ev[] = ['event' => 'language', 'at' => $welcome->copy()->subMinute(), 'target' => $lang, 'dev' => $dev->id, 'st' => $st->id];
            }
        }
        foreach (array_chunk($ev, 500) as $chunk) {
            Models\TvEvent::insert(array_map(fn ($e) => ['hotel_id' => $hid, 'tv_device_id' => $e['dev'], 'reservation_room_id' => $e['st'], 'event' => $e['event'], 'target' => $e['target'], 'created_at' => $e['at']->toDateTimeString()], $chunk));
        }

        // ── today on the TVs: rentals (one refunded), orders, requests, a wake-up, express check-out, feedback
        $stay = fn (string $no) => Models\ReservationRoom::where('status', 'checked_in')->whereHas('room', fn ($q) => $q->where('room_no', $no))->first();
        $at = fn (int $minAgo) => Carbon::setTestNow($real->copy()->subMinutes($minAgo));
        $try = function (callable $fn) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        };
        $refund = null;
        foreach ([['103', 'Harmattan', 330], ['104', 'The Gold Coast Heist', 280], ['106', 'Ananse and the Sky God', 230], ['104', 'Jollof Wars', 150], ['105', 'Osu Nights', 95], ['102', 'Sankofa Road', 60]] as [$no, $t, $ago]) {
            $st = $stay($no);
            if ($st && isset($devs[$no], $mv[$t])) {
                $at($ago);
                $p = $try(fn () => $IS::rent($devs[$no], $st, $mv[$t], $IS::pinFor($st)));
                $refund = $no === '105' ? $p : $refund;
                Carbon::setTestNow($clock);
            }
        }
        if ($refund) {
            $at(70);
            $try(fn () => $IS::refund($refund, 'Stream froze after 20 minutes – guest called the desk', $admin));
            Carbon::setTestNow($clock);
        }
        $menu = collect($IS::diningMenu()['cats'] ?? [])->flatMap(fn ($c) => $c['items'])->values();
        if ($menu->count() >= 2 && ($st = $stay('105')) && isset($devs['105'])) {
            $at(45);
            $try(fn () => $IS::order($devs['105'], $st, [['id' => $menu[0]['id'], 'qty' => 2], ['id' => $menu[1]['id'], 'qty' => 1]]));
            Carbon::setTestNow($clock);
        }
        if (($st = $stay('105')) && isset($devs['105'])) { // a stay that already has portal requests – Phase8Test needs two in-house stays without any
            $at(35);
            $try(fn () => $IS::request($devs['105'], $st, ['category' => 'housekeeping', 'item' => 'Turn-down service']));
            Carbon::setTestNow($clock);
        }
        if (($st = $stay('104')) && isset($devs['104'])) {
            $at(25);
            $try(fn () => $IS::wakeup($devs['104'], $st, '06:15', false));
            Carbon::setTestNow($clock);
        }
        if (($st = $stay('103')) && isset($devs['103'])) {
            $at(20);
            $try(fn () => $IS::checkout($devs['103'], $st));
            $try(fn () => $IS::feedback($devs['103'], $st, 5));
            Carbon::setTestNow($clock);
        }

        // ── messages: cleared fire drill, live broadcasts, room message awaiting OK, VIP, lobby screens, withdrawn notice
        $hse = Models\User::where('hotel_id', $hid)->where('username', 'hse')->first() ?? $admin;
        $fom = Models\User::where('hotel_id', $hid)->where('username', 'fom')->first() ?? $admin;
        Carbon::setTestNow($real->copy()->subDays(3)->setTime(10, 2));
        $drill = $try(fn () => $IS::emergencyStart(['title' => 'Fire drill – please evacuate', 'confirm' => 'EMERGENCY',
            'body' => 'This is a scheduled fire drill. Leave your room by the nearest stairway – do not use the lifts – and go to the assembly point in the front car park.'], $hse));
        if ($drill) {
            Carbon::setTestNow($real->copy()->subDays(3)->setTime(10, 11));
            $try(fn () => $IS::emergencyClear($drill, 'The fire drill is complete – thank you for your cooperation. Evacuation took 6 min 40 s.', $hse));
        }
        Carbon::setTestNow($real->copy()->subHours(3));
        $pool = $try(fn () => $IS::send(['target' => 'all', 'title' => 'Pool maintenance this afternoon', 'body' => 'The rooftop pool is closed today until 17:00 for cleaning. The gym and spa are open as usual.',
            'ends_at' => $real->copy()->addHours(4)->toDateTimeString()], $fom));
        $band = $try(fn () => $IS::send(['target' => 'all', 'title' => 'Live highlife band tonight', 'body' => 'Join us at the Skyline Bar from 20:00.'], $fom));
        Carbon::setTestNow($real->copy()->subHours(2));
        $f1 = Models\Floor::where('name', 'First Floor')->value('id');
        $try(fn () => $IS::send(['target' => 'floor', 'floor_id' => $f1, 'title' => 'Water interruption tomorrow 10:00–11:00', 'body' => 'Engineering will replace a valve on the first floor. Water will be off for about an hour – we apologise for the inconvenience.'], $fom));
        if (($r = Models\Room::where('room_no', '104')->first())) {
            $try(fn () => $IS::send(['target' => 'room', 'room_id' => $r->id, 'title' => 'Airport transfer confirmed', 'body' => 'Your transfer to Kotoka International Airport is booked for 4 Oct at 07:30 from the main entrance. Press OK to confirm.',
                'requires_ack' => true, 'priority' => 'normal'], $fom));
        }
        $try(fn () => $IS::send(['target' => 'vip', 'title' => 'An invitation from the General Manager', 'body' => 'Please join us for the manager\'s cocktail at the Skyline Bar today 18:30–19:30.', 'priority' => 'high'], $fom));
        $try(fn () => $IS::send(['target' => 'signage', 'title' => 'Welcome, delegates!', 'body' => 'Infosys Quarterly Board Review – Boardroom, first floor. Lunch is served at Saffron from 12:30.'], $fom));
        Carbon::setTestNow($real->copy()->subMinutes(80));
        if ($band) {
            $try(fn () => $IS::cancelMessage($band, 'The band cancelled – rescheduled to Friday', $fom));
        }
        Carbon::setTestNow($clock);
        if ($pool) {
            foreach ($devs->take(6) as $d) {
                Models\TvMessageRead::firstOrCreate(['tv_message_id' => $pool->id, 'tv_device_id' => $d->id], ['read_at' => now()->subHours(2)]);
            }
        }

        // ── remote commands: history
        if (isset($devs['105'])) {
            $c = $IS::command($devs['105'], 'reload', [], $admin);
            $c->update(['status' => 'done', 'sent_at' => now()->subHours(5), 'done_at' => now()->subHours(5), 'created_at' => now()->subHours(5)]);
        }
        if (isset($devs['108'])) {
            $c = $IS::command($devs['108'], 'message', ['text' => 'Test from engineering – please ignore'], $admin); // queued: the TV is offline
            $c->update(['created_at' => now()->subHours(1)]);
        }
        if (isset($devs['202'])) {
            $c = $IS::command($devs['202'], 'tune', ['channel' => 17], $admin);
            $c->update(['status' => 'done', 'sent_at' => now()->subDay(), 'done_at' => now()->subDay(), 'created_at' => now()->subDay()]);
        }
        Auth::setUser($admin);
    }

    protected function seedInterfaces(bool $rich, Models\User $user): void
    {
        $I = \App\Services\InterfaceService::class;
        $C = \App\Services\CallAccountingService::class;
        $K = \App\Services\KeyCardService::class;
        $I::ensureDefaults();
        // tariff card (carrier cost per pulse → guest price with markup) and extensions – every property
        foreach ([
            ['Local', '', 'local', 60, 1.00, 400, 5, 0], ['National (STD)', '0', 'std', 60, 2.00, 300, 8, 0],
            ['Mobile', '9', 'mobile', 60, 1.50, 300, 6, 0], ['Mobile', '8', 'mobile', 60, 1.50, 300, 6, 0], ['Mobile', '7', 'mobile', 60, 1.50, 300, 6, 0], ['Mobile', '6', 'mobile', 60, 1.50, 300, 6, 0],
            ['International', '00', 'isd', 30, 6.00, 150, 30, 0], ['International (+)', '+', 'isd', 30, 6.00, 150, 30, 0],
            ['Toll-free', '1800', 'free', 60, 0, 0, 0, 0], ['Premium rate', '1900', 'premium', 60, 10.00, 100, 20, 0],
        ] as [$n, $p, $cl, $pulse, $cost, $mk, $min, $free]) {
            Models\CallTariff::firstOrCreate(['prefix' => $p], ['name' => $n, 'call_class' => $cl, 'pulse_secs' => $pulse, 'cost_per_pulse' => $cost, 'markup_pct' => $mk, 'min_charge' => $min, 'free_secs' => $free]);
        }
        $C::syncRoomExtensions();
        foreach ([['200', 'admin', 'Front Office', 'Reception desk'], ['201', 'admin', 'Reservations', 'Reservations'], ['210', 'admin', 'Accounts', 'Accounts office'], ['220', 'admin', 'Management', 'General Manager'],
            ['230', 'admin', 'Kitchen', 'Kitchen'], ['240', 'admin', 'Housekeeping', 'HK desk'], ['250', 'admin', 'Engineering', 'Engineering'], ['300', 'public', 'Lobby', 'Lobby courtesy phone']] as [$x, $t, $d, $l]) {
            Models\PhoneExtension::firstOrCreate(['extension' => $x], ['type' => $t, 'department' => $d, 'label' => $l]);
        }
        $C::flush();
        if (! $rich) {
            return;
        }
        $prevUser = auth()->user();
        $h = hotel()->id;
        $u = fn (string $n) => Models\User::where('hotel_id', $h)->where('username', $n)->first() ?? $user;
        auth()->setUser($u('frontdesk'));
        $now = now();
        $inHouse = Models\ReservationRoom::with('room')->where('status', 'checked_in')->whereNotNull('room_id')->whereNotNull('checked_in_at')->orderBy('id')->get()->values();
        $at = fn ($st, float $hours) => $st->checked_in_at->copy()->addMinutes((int) ($hours * 60))->min($now->copy()->subMinutes(25 + (int) ($hours * 3)));
        $ext = fn ($st) => $C::roomExtension($st->room_id);
        $lines = [];
        // guest calls: local, national, mobile, international, emergency, unanswered
        $plan = [[0, 1.5, '24681357', 185], [0, 4, '9840012345', 312], [1, 2, '04422334455', 424], [1, 6, '00442071234567', 610], [2, 1, '8056677889', 95],
            [2, 3, '112', 40], [3, 2.5, '0802345678', 1260], [3, 5, '9790011223', 3], [4, 1, '24561234', 64], [4, 7, '0016175550123', 245], [5, 2, '18004191234', 300]];
        foreach ($plan as [$i, $hrs, $num, $secs]) {
            if ($st = $inHouse[$i] ?? null) {
                $lines[] = ['ext' => $ext($st), 'number' => $num, 'started_at' => $at($st, $hrs), 'duration' => $secs, 'trunk' => 'T'.(1 + $i % 2)];
            }
        }
        // a guest whose phone is limited to national calls places an international call (PBX let it through) → review
        if ($st = $inHouse[6] ?? null) {
            $st->update(['phone_cos' => 'national']);
            $lines[] = ['ext' => $ext($st), 'number' => '00971501234567', 'started_at' => $at($st, 2), 'duration' => 380, 'trunk' => 'T1'];
        }
        // a vacant room, an unknown extension, and admin / public lines
        $vacant = Models\Room::where('status', 'vacant')->where('is_active', true)->orderBy('room_no')->first();
        if ($vacant && ($vx = $C::roomExtension($vacant->id))) {
            $lines[] = ['ext' => $vx, 'number' => '9812345678', 'started_at' => $now->copy()->subHours(5), 'duration' => 150, 'trunk' => 'T2'];
        }
        $lines[] = ['ext' => '999', 'number' => '24689753', 'started_at' => $now->copy()->subHours(3), 'duration' => 75, 'trunk' => 'T1'];
        foreach ([['210', '04423456789', 20, 540], ['220', '00493012345678', 26, 900], ['230', '24556677', 8, 180], ['300', '9876501234', 2, 120], ['200', '0802223344', 4, 260], ['250', '18002003000', 6, 420]] as [$x, $num, $hrs, $secs]) {
            $lines[] = ['ext' => $x, 'number' => $num, 'started_at' => $now->copy()->subHours($hrs), 'duration' => $secs, 'trunk' => 'T2'];
        }
        foreach ($lines as $l) {
            if ($l['ext']) {
                $C::record($l, 'import', 'SEED');
            }
        }
        // one disputed call waived by the duty manager
        auth()->setUser($u('fom'));
        if ($disputed = Models\CallRecord::where('status', 'posted')->where('call_class', 'mobile')->orderBy('id')->first()) {
            $C::waive($disputed, 'Guest says the call did not connect – goodwill');
        }

        // ── key cards: most in-house rooms have cards; a duplicate and a lost-card replacement ──
        auth()->setUser($u('frontdesk'));
        foreach ($inHouse->slice(0, max(0, $inHouse->count() - 2)) as $i => $st) {
            try {
                $K::issue($st, $i % 3 === 0 ? 1 : 2, 'new');
            } catch (\RuntimeException) {
                // departure time already passed on the demo clock – no card
            }
        }
        if (($st = $inHouse[0] ?? null) && $K::activeFor($st)->isNotEmpty()) {
            try {
                $K::issue($st, 1, 'duplicate', null, 'Spouse');
            } catch (\RuntimeException) {
            }
        }
        if (($st = $inHouse[1] ?? null) && ($card = $K::activeFor($st)->first())) {
            try {
                $K::markLost($card, false, true);
            } catch (\RuntimeException) {
            }
        }
        // a checked-out guest's old cards
        if ($gone = Models\ReservationRoom::where('status', 'checked_out')->whereNotNull('room_id')->orderByDesc('checked_out_at')->first()) {
            foreach ([1, 2] as $n) {
                Models\KeyCard::create(['card_no' => \App\Support\Numbering::next('key_card'), 'serial' => strtoupper(bin2hex(random_bytes(4))), 'type' => $n === 1 ? 'guest' : 'duplicate',
                    'reservation_room_id' => $gone->id, 'room_id' => $gone->room_id, 'holder' => $gone->guest?->full_name ?? 'Guest', 'valid_from' => $gone->checked_in_at ?? $now->copy()->subDays(2),
                    'valid_until' => $gone->checked_out_at ?? $now->copy()->subHours(6), 'areas' => ['Lift', 'Pool'], 'status' => $n === 1 ? 'returned' : 'expired',
                    'status_note' => $n === 1 ? 'Returned at the desk' : 'Checked out', 'issued_by' => auth()->id(), 'closed_at' => $gone->checked_out_at ?? $now->copy()->subHours(6)]);
            }
        }

        // ── master keys & register ──
        auth()->setUser($u('housekeeping'));
        foreach ([['GM-01', 'Grand master', 'grand', 'All guest rooms & back-of-house', 'Management'], ['EM-01', 'Emergency key', 'emergency', 'All doors incl. deadlocked rooms', 'Security'],
            ['MK-F1', 'Floor 1 master', 'floor', 'Rooms on floor 1', 'Front Office'], ['MK-F2', 'Floor 2 master', 'floor', 'Rooms on floor 2', 'Front Office'],
            ['HK-A', 'Housekeeping section A', 'section', 'Floor 1 rooms + linen room 1', 'Housekeeping'], ['HK-B', 'Housekeeping section B', 'section', 'Floor 2 rooms + linen room 2', 'Housekeeping'],
            ['ENG-01', 'Engineering master', 'floor', 'Plant rooms, guest rooms (maintenance)', 'Engineering']] as [$no, $name, $lvl, $scope, $dept]) {
            $K::saveKey(['key_no' => $no, 'name' => $name, 'level' => $lvl, 'scope' => $scope, 'department' => $dept]);
        }
        $key = fn ($no) => Models\MasterKey::where('key_no', $no)->first();
        $back = fn ($loan, array $f) => $loan->forceFill($f)->saveQuietly();
        // yesterday's returned loans (one late)
        foreach ([['HK-A', 'housekeeping', 'Morning rooms floor 1', 26, 17, 18], ['HK-B', 'housekeeping', 'Morning rooms floor 2', 26, 17, 16.5], ['ENG-01', 'engineer', 'AC servicing', 30, 22, 20]] as [$no, $who, $why, $outH, $dueH, $inH]) {
            $person = $u($who);
            $loan = $K::signOut($key($no), $person->id, null, $why, 9);
            $back($loan, ['out_at' => $now->copy()->subHours($outH), 'due_at' => $now->copy()->subHours($dueH), 'created_at' => $now->copy()->subHours($outH)]);
            $K::signIn($loan->fresh());
            $back($loan->fresh(), ['in_at' => $now->copy()->subHours($inH), 'note' => $inH < $dueH ? 'returned late' : null]);
        }
        // out now: section key on shift, engineering key overdue
        $loan = $K::signOut($key('HK-A'), $u('housekeeping')->id, null, 'Afternoon turn-down floor 1', 8);
        $back($loan, ['out_at' => $now->copy()->subHours(3), 'due_at' => $now->copy()->addHours(5), 'created_at' => $now->copy()->subHours(3)]);
        $loan = $K::signOut($key('ENG-01'), $u('engineer')->id, null, 'Leak in room bathroom – plumbing', 4);
        $back($loan, ['out_at' => $now->copy()->subHours(6), 'due_at' => $now->copy()->subHours(2), 'created_at' => $now->copy()->subHours(6)]);
        auth()->setUser($prevUser ?? $user);
    }

    protected function seedApi(bool $rich, Models\User $user): void
    {
        if (! $rich) {
            return;
        }
        $A = \App\Services\ApiService::class;
        $W = \App\Services\WebhookService::class;
        // channel connections that are up to date stay so – the API bookings below were pushed long ago in the demo story
        $hid = \App\Support\Tenant::id();
        $dirtyMax = (int) \Illuminate\Support\Facades\DB::table('channel_dirty')->where('hotel_id', $hid)->max('id');
        $upToDate = Models\ChannelConnection::where('pushed_upto_id', '>=', $dirtyMax)->pluck('id');
        mt_srand(4000);
        $prevUser = auth()->user();
        auth()->setUser($user);
        $hid = Tenant::id();
        $uid = fn (string $u) => Models\User::where('hotel_id', $hid)->where('username', $u)->value('id');
        $P = Models\ApiClient::PRESETS;
        $web = Models\BusinessSource::firstOrCreate(['name' => 'Website (API)'], ['type' => 'booking_engine']);

        [$site] = $A::createClient(['name' => 'Hotel website – booking widget', 'description' => 'Built by BluePixel Studio · support@bluepixel.example',
            'scopes' => $P['booking'][1], 'rate_limit' => 300, 'owner_id' => $uid('fom'), 'business_source_id' => $web->id]);
        [$rms] = $A::createClient(['name' => 'RevenuePro RMS', 'description' => 'Pricing & forecasting – syncs availability, rates and pickup every 15 minutes',
            'scopes' => $P['bi'][1], 'ip_allowlist' => '203.0.113.0/24, 198.51.100.7', 'rate_limit' => 120]);
        [$hk] = $A::createClient(['name' => 'Housekeeping tablets (FlexiClean)', 'description' => 'Room attendants mark rooms clean from the floor',
            'scopes' => $P['housekeeping'][1], 'ip_allowlist' => '10.20.0.0/16', 'rate_limit' => 600, 'owner_id' => $uid('housekeeping')]);
        [$bi] = $A::createClient(['name' => 'Owner BI dashboard', 'description' => 'Monthly owner reporting (trial until the contract is signed)',
            'scopes' => ['reports:read'], 'rate_limit' => 60, 'expires_on' => bdate()->copy()->addDays(60)->toDateString()]);
        [$old] = $A::createClient(['name' => 'Legacy lobby kiosk', 'scopes' => ['reservations:read', 'guests:read']]);
        $A::revoke($old, 'Kiosk retired – replaced by online check-in');
        Models\ApiClient::whereKey($old->id)->update(['created_at' => now()->subMonths(8), 'revoked_at' => now()->subDays(40), 'last_used_at' => now()->subDays(41), 'last_ip' => '10.20.4.31']);
        Models\ApiClient::whereKey([$site->id, $rms->id, $hk->id])->update(['created_at' => now()->subMonths(3)]);

        // ── webhooks: created before the API bookings below so those events are queued like in real life
        [$feed] = $W::create(['name' => 'RevenuePro – reservation feed', 'url' => 'https://hooks.revenuepro.example/nowills/'.Tenant::hotel()->code,
            'events' => ['reservation.created', 'reservation.cancelled', 'reservation.no_show', 'night_audit.completed'], 'description' => 'Pickup updates for the RMS forecast']);
        [$dw] = $W::create(['name' => 'Group data warehouse', 'url' => 'https://etl.nowills-group.example/ingest/pms', 'events' => ['*'], 'description' => 'Nightly ETL owner: group IT (it@nowills-group.example)']);
        [$crm] = $W::create(['name' => 'Old CRM connector', 'url' => 'https://crm-legacy.example.net/hooks/pms', 'events' => ['guest.checked_in', 'guest.checked_out']]);

        // ── three days of delivery history
        $recent = Models\Reservation::with('guest', 'rooms.roomType', 'rooms.room', 'source')->latest('id')->limit(12)->get();
        $mk = function (Models\WebhookEndpoint $ep, string $event, array $data, Carbon $at, string $status, array $tries) {
            $d = new Models\WebhookDelivery(['webhook_endpoint_id' => $ep->id, 'event' => $event, 'event_id' => 'evt_'.\Illuminate\Support\Str::uuid(), 'status' => $status,
                'payload' => [], 'attempts' => count($tries)]);
            $d->payload = ['id' => $d->event_id, 'type' => $event, 'created_at' => $at->toIso8601String(), 'api_version' => 'v1', 'hotel' => ['code' => Tenant::hotel()->code], 'data' => $data];
            $log = [];
            $t = $at->copy();
            foreach ($tries as $i => [$code, $err]) {
                $t = $i ? $t->copy()->addSeconds(\App\Services\WebhookService::BACKOFF[$i - 1]) : $t->copy()->addSeconds(mt_rand(2, 50));
                $log[] = ['at' => $t->toIso8601String(), 'code' => $code, 'ms' => $code ? mt_rand(60, 420) : 5000, 'error' => $err, 'manual' => false];
            }
            $last = end($log) ?: null;
            $d->hotel_id = $ep->hotel_id;
            $d->forceFill(['attempt_log' => $log, 'response_code' => $last['code'] ?? null, 'error' => $last['error'] ?? null, 'duration_ms' => $last['ms'] ?? null,
                'response_body' => $last ? ($last['code'] === 200 ? '{"received":true}' : ($last['code'] ? '<html><body>503 Service Temporarily Unavailable</body></html>' : null)) : null,
                'delivered_at' => $status === 'success' ? Carbon::parse($last['at']) : null,
                'next_attempt_at' => $status === 'pending' ? now()->addMinutes(22) : null, 'created_at' => $at, 'updated_at' => $last ? Carbon::parse($last['at']) : $at])->save();

            return $d;
        };
        foreach ($recent as $i => $r) {
            $at = now()->subHours(70 - $i * 5)->subMinutes(mt_rand(0, 50));
            $data = ['reservation' => $A::reservation($r)];
            $mk($feed, 'reservation.created', $data, $at, 'success', [[200, null]]);
            $mk($dw, 'reservation.created', $data, $at, 'success', $i === 4 ? [[502, 'HTTP 502'], [200, null]] : [[200, null]]);
        }
        foreach (Models\NightAudit::orderByDesc('business_date')->limit(3)->get()->reverse()->values() as $k => $a) {
            if ($a) {
                $at = now()->subDays(2 - $k)->setTime(0, 40)->subMinutes(mt_rand(0, 20));
                $mk($feed, 'night_audit.completed', ['night_audit' => $A::audit($a)], $at, 'success', [[200, null]]);
                $mk($dw, 'night_audit.completed', ['night_audit' => $A::audit($a)], $at, 'success', [[200, null]]);
            }
        }
        $room = Models\Room::where('is_active', true)->orderBy('room_no')->first();
        $mk($dw, 'room.status_changed', $A::room($room) + ['previous' => ['status' => $room->status, 'housekeeping' => 'dirty']], now()->subMinutes(35), 'pending', [[502, 'HTTP 502'], [0, 'Connection failed: Operation timed out after 10001 milliseconds']]);

        // the old CRM connector: failing for days, switched off after 20 in a row – its events are kept
        $stays = Models\ReservationRoom::with('reservation.guest', 'roomType', 'room', 'guest')->whereNotNull('checked_in_at')->latest('checked_in_at')->limit(5)->get();
        foreach ($stays as $i => $s) {
            $fail = array_fill(0, $i < 2 ? 8 : mt_rand(1, 3), [503, 'HTTP 503']);
            $mk($crm, 'guest.checked_in', ['stay' => $A::stay($s, true)], now()->subDays(4)->addHours($i * 9), $i < 2 ? 'failed' : 'pending', $fail);
        }
        Models\WebhookDelivery::where('webhook_endpoint_id', $crm->id)->where('status', 'pending')->update(['next_attempt_at' => now()->subHours(2)]);
        $crm->forceFill(['is_active' => false, 'consecutive_failures' => 20, 'disabled_at' => now()->subDays(2)->setTime(9, 12),
            'disabled_reason' => '20 failed attempts in a row – last: HTTP 503', 'last_success_at' => now()->subDays(6), 'last_failure_at' => now()->subDays(2)->setTime(9, 12)])->save();
        $feed->forceFill(['last_success_at' => now()->subHours(3), 'created_at' => now()->subMonths(2)])->save();
        $dw->forceFill(['last_success_at' => now()->subHours(3), 'last_failure_at' => now()->subMinutes(12), 'consecutive_failures' => 2, 'created_at' => now()->subMonths(2)])->save();

        // ── two bookings through the website key (queues reservation.created for the RMS feed and the warehouse), one cancelled
        auth()->setUser(Models\User::find($uid('fom')) ?? $user);
        $b1 = $A::createReservation($site, ['arrival' => bdate()->copy()->addDays(14)->toDateString(), 'departure' => bdate()->copy()->addDays(17)->toDateString(),
            'room_type' => 'DLX', 'rooms' => 1, 'adults' => 2, 'children' => 1, 'external_ref' => 'WEB-10288', 'special_request' => 'Baby cot please',
            'guest' => ['first_name' => 'Ama', 'last_name' => 'Mensah', 'email' => 'ama.mensah@example.com', 'phone' => '+233245550101', 'nationality' => 'Ghanaian']]);
        $b2 = $A::createReservation($site, ['arrival' => bdate()->copy()->addDays(21)->toDateString(), 'departure' => bdate()->copy()->addDays(23)->toDateString(),
            'room_type' => 'STD', 'rooms' => 2, 'adults' => 4, 'external_ref' => 'WEB-10293',
            'guest' => ['first_name' => 'Rohan', 'last_name' => 'Kapoor', 'email' => 'rohan.kapoor@example.com', 'phone' => '+919820011223', 'nationality' => 'Indian']]);
        $A::cancelReservation($site, $b2->fresh(), 'Guest changed travel plans (cancelled on the website)');
        auth()->setUser($user);

        // ── 24 h of request logs
        $rows = [];
        $add = function (?Models\ApiClient $c, string $m, string $path, ?string $route, int $status, ?string $err, string $ip, string $ua, Carbon $at) use (&$rows, $hid) {
            $rows[] = ['hotel_id' => $c ? $hid : null, 'api_client_id' => $c?->id, 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'method' => $m, 'path' => $path, 'route' => $route,
                'status' => $status, 'error_code' => $err, 'duration_ms' => $status >= 400 ? mt_rand(4, 30) : mt_rand(18, $route === 'reservations.store' ? 480 : 160),
                'ip' => $ip, 'user_agent' => $ua, 'created_at' => $at];
        };
        $d0 = bdate()->copy();
        for ($min = 1440; $min > 0; $min -= 15) {           // RMS: every 15 minutes
            $at = now()->subMinutes($min)->addSeconds(mt_rand(0, 40));
            $add($rms, 'GET', 'api/v1/availability?from='.$d0->toDateString().'&to='.$d0->copy()->addDays(90)->toDateString(), 'availability', 200, null, '203.0.113.25', 'RevenuePro-Sync/5.2', $at);
            $add($rms, 'GET', 'api/v1/reservations?modified_since='.urlencode(now()->subMinutes($min + 15)->toIso8601String()), 'reservations.index', 200, null, '203.0.113.25', 'RevenuePro-Sync/5.2', $at->copy()->addSeconds(2));
        }
        $add($rms, 'GET', 'api/v1/reports/daily?from='.$d0->copy()->subDays(7)->toDateString(), 'reports.daily', 200, null, '203.0.113.25', 'RevenuePro-Sync/5.2', now()->subHours(20));
        for ($i = 0; $i < 70; $i++) {                         // website: searches, a few bookings
            $at = now()->subMinutes(mt_rand(1, 1440));
            $from = $d0->copy()->addDays(mt_rand(1, 60));
            $add($site, 'GET', 'api/v1/availability?from='.$from->toDateString().'&to='.$from->copy()->addDays(mt_rand(1, 4))->toDateString(), 'availability', 200, null, '198.51.100.44', 'BluePixel-Booking/2.1 (+https://bluepixel.example)', $at);
        }
        $add($site, 'POST', 'api/v1/reservations', 'reservations.store', 201, null, '198.51.100.44', 'BluePixel-Booking/2.1', now()->subHours(5));
        $add($site, 'POST', 'api/v1/reservations', 'reservations.store', 422, 'validation_failed', '198.51.100.44', 'BluePixel-Booking/2.1', now()->subHours(4));
        $add($site, 'POST', 'api/v1/reservations', 'reservations.store', 409, 'conflict', '198.51.100.44', 'BluePixel-Booking/2.1', now()->subHours(4)->addMinutes(1));
        $add($site, 'POST', 'api/v1/reservations', 'reservations.store', 201, null, '198.51.100.44', 'BluePixel-Booking/2.1', now()->subHours(2));
        $add($site, 'POST', 'api/v1/reservations/'.$b2->reservation_no.'/cancel', 'reservations.cancel', 200, null, '198.51.100.44', 'BluePixel-Booking/2.1', now()->subHours(1));
        for ($i = 0; $i < 30; $i++) {                         // housekeeping tablets
            $at = now()->subMinutes(mt_rand(1, 600));
            $add($hk, 'GET', 'api/v1/rooms?housekeeping=dirty', 'rooms.index', 200, null, '10.20.6.'.mt_rand(10, 40), 'FlexiClean-Android/3.4', $at);
            if ($i % 3 === 0) {
                $add($hk, 'PATCH', 'api/v1/rooms/'.mt_rand(101, 110), 'rooms.update', 200, null, '10.20.6.'.mt_rand(10, 40), 'FlexiClean-Android/3.4', $at->copy()->addMinutes(20));
            }
        }
        $add($hk, 'GET', 'api/v1/guests?name=sharma', 'guests.index', 403, 'insufficient_scope', '10.20.6.18', 'FlexiClean-Android/3.4', now()->subHours(7));
        $add($bi, 'GET', 'api/v1/reports/daily?from='.$d0->copy()->subDays(30)->toDateString().'&to='.$d0->toDateString(), 'reports.daily', 200, null, '192.0.2.80', 'PowerBI-Connector', now()->subHours(9));
        foreach ([95, 94, 94, 93, 60] as $m) {                  // someone probing with made-up keys
            $add(null, 'GET', 'api/v1/reservations', null, 401, 'invalid_token', '45.155.205.'.mt_rand(2, 250), 'python-requests/2.32', now()->subMinutes($m * 3));
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Models\ApiRequestLog::withoutGlobalScope('hotel')->insert($chunk);
        }
        Models\ApiClient::whereKey($site->id)->update(['last_used_at' => now()->subMinutes(3), 'last_ip' => '198.51.100.44']);
        Models\ApiClient::whereKey($rms->id)->update(['last_used_at' => now()->subMinutes(9), 'last_ip' => '203.0.113.25']);
        Models\ApiClient::whereKey($hk->id)->update(['last_used_at' => now()->subMinutes(14), 'last_ip' => '10.20.6.22']);
        Models\ApiClient::whereKey($bi->id)->update(['last_used_at' => now()->subHours(9), 'last_ip' => '192.0.2.80']);
        auth()->setUser($prevUser ?? $user);
        Models\ChannelConnection::whereIn('id', $upToDate)->update(['pushed_upto_id' => (int) \Illuminate\Support\Facades\DB::table('channel_dirty')->where('hotel_id', $hid)->max('id')]);
    }

    protected function seedChannels(bool $rich, Carbon $today, Models\User $user): void
    {
        if (! $rich) {
            return;
        }
        \Illuminate\Support\Facades\Http::fake([
            '*booking.example/*' => \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200),
            '*expediapartnercentral.example/*' => \Illuminate\Support\Facades\Http::response(['error' => 'Service temporarily unavailable'], 503),
        ]);
        $rt = Models\RoomType::pluck('id', 'code');
        $plans = Models\RatePlan::get()->groupBy('room_type_id')->map(fn ($g) => $g->keyBy('meal_plan'));
        $src = Models\BusinessSource::pluck('id', 'name');
        $account = fn (string $name, float $pct) => Models\Company::firstOrCreate(['name' => $name], ['type' => 'ota', 'commission_pct' => $pct, 'credit_limit' => 0, 'credit_days' => 30, 'is_active' => true, 'email' => 'accounts@'.strtolower(preg_replace('/[^a-z]/i', '', $name)).'.example']);
        $mk = function (array $a, array $maps) use ($rt, $plans) {
            $c = new Models\ChannelConnection;
            $c->fill($a)->save();
            foreach ($maps as $code => [$roomCode, $maxSell, $rates]) {
                Models\ChannelRoomMap::create(['channel_connection_id' => $c->id, 'room_type_id' => $rt[$code], 'channel_room_code' => $roomCode, 'max_sell' => $maxSell]);
                foreach ($rates as $meal => $rateCode) {
                    Models\ChannelRateMap::create(['channel_connection_id' => $c->id, 'room_type_id' => $rt[$code], 'rate_plan_id' => $meal === 'BAR' ? null : $plans[$rt[$code]][$meal]->id, 'channel_rate_code' => $rateCode]);
                }
            }

            return $c;
        };
        $bdc = $mk(['name' => 'Booking.com', 'code' => 'BDC', 'provider' => 'hub', 'business_source_id' => $src['Booking.com'] ?? null, 'company_id' => $account('Booking.com', 15)->id,
            'property_code' => '4412087', 'endpoint_url' => 'https://supply-xml.booking.example/nowills/ari', 'api_key' => 'bdc_live_7Qm2xR9sK4vT1pZ8', 'commission_pct' => 15, 'rate_markup_pct' => 0,
            'payment_model' => 'hotel_collect'], [
            'STD' => ['STD-DBL', null, ['BAR' => 'RO', 'CP' => 'BB', 'MAP' => 'HB']],
            'DLX' => ['DLX-KING', 4, ['BAR' => 'RO', 'CP' => 'BB']],
            'EXE' => ['EXEC-STE', 2, ['BAR' => 'RO', 'CP' => 'BB']],
        ]);
        $exp = $mk(['name' => 'Expedia', 'code' => 'EXP', 'provider' => 'hub', 'business_source_id' => $src['Expedia'] ?? null, 'company_id' => $account('Expedia', 18)->id,
            'property_code' => 'EQC-88213', 'endpoint_url' => 'https://services.expediapartnercentral.example/eqc/ar', 'api_key' => 'exp_eqc_5Hn8Lw2Qd6', 'commission_pct' => 18, 'rate_markup_pct' => 5,
            'rate_rounding' => 'ten', 'payment_model' => 'channel_collect'], [
            'STD' => ['200145', null, ['BAR' => 'RP-STD-RO', 'CP' => 'RP-STD-BB']],
            'DLX' => ['200146', 3, ['BAR' => 'RP-DLX-RO', 'CP' => 'RP-DLX-BB']],
        ]);
        $test = $mk(['name' => 'Test Channel (Sandbox)', 'code' => 'TEST', 'provider' => 'simulator', 'commission_pct' => 10, 'rate_markup_pct' => 0, 'payment_model' => 'hotel_collect',
            'business_source_id' => Models\BusinessSource::firstOrCreate(['name' => 'Sandbox OTA'], ['type' => 'ota', 'commission_pct' => 10, 'is_active' => true])->id, 'company_id' => $account('Sandbox OTA', 10)->id], [
            'STD' => ['STD', null, ['BAR' => 'BAR', 'CP' => 'BB']],
            'DLX' => ['DLX', null, ['BAR' => 'BAR', 'CP' => 'BB']],
            'EXE' => ['EXE', null, ['BAR' => 'BAR']],
        ]);
        $mmt = $mk(['name' => 'MakeMyTrip', 'code' => 'MMT', 'provider' => 'hub', 'business_source_id' => $src['MakeMyTrip'] ?? null, 'company_id' => $account('MakeMyTrip', 15)->id,
            'property_code' => 'MMT-201907', 'endpoint_url' => 'https://connect.makemytrip.example/ari', 'commission_pct' => 15, 'is_active' => false], [
            'STD' => ['STD', null, ['BAR' => 'EP', 'CP' => 'CP']],
        ]);
        $fri = $today->copy()->next(Carbon::FRIDAY);
        Models\ChannelRestriction::create(['channel_connection_id' => $bdc->id, 'room_type_id' => $rt['EXE'], 'date_from' => $today->copy()->addDays(20), 'date_to' => $today->copy()->addDays(23),
            'stop_sell' => true, 'reason' => 'Diwali – suites for direct & corporate only', 'created_by' => $user->id]);
        Models\ChannelRestriction::create(['channel_connection_id' => $bdc->id, 'room_type_id' => null, 'date_from' => $fri, 'date_to' => $fri->copy()->addDay(),
            'min_stay' => 2, 'reason' => 'Weekend – 2-night minimum on OTA', 'created_by' => $user->id]);

        // ---- first full refresh (Booking.com + sandbox OK, Expedia endpoint down)
        foreach ([$bdc, $test] as $c) {
            \App\Services\ChannelService::push($c->fresh(), true);
        }
        \App\Services\ChannelService::push($exp->fresh(), true);
        $exp->refresh()->forceFill(['next_retry_at' => now()->subMinute()])->save();
        \App\Services\ChannelService::push($exp->fresh(), true);

        // ---- bookings through the inbound path
        $d = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $price = fn ($c, string $code, ?string $meal, int $from, int $to) => round(collect(\App\Services\RateService::quote(Models\RoomType::find($rt[$code]), $d($from), $d($to))['nights'])
            ->sum(fn ($n) => \App\Services\ChannelService::price($c, Models\RoomType::find($rt[$code]), $meal ? $plans[$rt[$code]][$meal] : null, (float) $n['rate'])), 2);
        $send = function ($c, array $p, int $daysAgo) {
            [$code, $body] = \App\Services\ChannelService::receive($c->fresh(), $p);
            if ($id = $body['message_id'] ?? null) {
                Models\ChannelMessage::whereKey($id)->update(['created_at' => now()->subDays($daysAgo)->subMinutes(random_int(5, 600)), 'updated_at' => now()->subDays($daysAgo)]);
            }
            if (! empty($body['reservation_no'])) {
                Models\Reservation::where('reservation_no', $body['reservation_no'])->where('created_at', '>', now()->subMinute())->update(['created_at' => now()->subDays($daysAgo)]);
            }

            return $body;
        };
        $bk = fn ($c, string $ref, string $ev, int $a, int $b, array $guest, string $code, string $roomCode, ?string $meal, string $rateCode, int $adults = 2, ?string $remarks = null, int $rooms = 1) => [
            'event' => $ev, 'booking_id' => $ref, 'arrival' => $d($a), 'departure' => $d($b), 'currency' => 'GHS', 'guest' => $guest,
            'rooms' => array_fill(0, $rooms, ['room_code' => $roomCode, 'rate_code' => $rateCode, 'adults' => $adults, 'children' => 0, 'amount' => $price($c, $code, $meal, $a, $b)]),
            'total' => round($price($c, $code, $meal, $a, $b) * $rooms, 2), 'payment' => ['model' => $c->payment_model], 'remarks' => $remarks,
            'card' => ['number' => '4111111111111111', 'cvc' => '123', 'expiry' => '12/28']];   // stripped before storage
        $send($bdc, $bk($bdc, '4012887731', 'new', 2, 4, ['first_name' => 'Sophie', 'last_name' => 'Laurent', 'email' => 'sophie.laurent@example.fr', 'phone' => '+33 6 12 34 56 78', 'country' => 'France'], 'DLX', 'DLX-KING', 'CP', 'BB', 2, 'Non-smoking, high floor please'), 9);
        $send($bdc, $bk($bdc, '4012902218', 'new', 6, 8, ['first_name' => 'Rahul', 'last_name' => 'Menon', 'email' => 'rahul.menon@example.in', 'phone' => '+91 98450 11223', 'country' => 'India'], 'STD', 'STD-DBL', null, 'RO', 1), 6);
        $send($bdc, $bk($bdc, '4012902218', 'modify', 6, 9, ['first_name' => 'Rahul', 'last_name' => 'Menon', 'email' => 'rahul.menon@example.in', 'phone' => '+91 98450 11223', 'country' => 'India'], 'STD', 'STD-DBL', null, 'RO', 1, 'Extended by one night'), 4);
        $send($bdc, $bk($bdc, '4013110945', 'new', 12, 14, ['first_name' => 'Hannah', 'last_name' => 'Becker', 'email' => 'h.becker@example.de', 'country' => 'Germany'], 'EXE', 'EXEC-STE', 'CP', 'BB', 2, 'Anniversary trip'), 3);
        $send($bdc, $bk($bdc, '4013288170', 'new', 5, 7, ['first_name' => 'Arjun', 'last_name' => 'Kapoor', 'email' => 'arjun.k@example.in', 'country' => 'India'], 'STD', 'STD-DBL', 'CP', 'BB'), 5);
        $send($bdc, ['event' => 'cancel', 'booking_id' => '4013288170', 'remarks' => 'Change of plans'], 2);
        $send($exp, $bk($exp, 'EXP-72419934', 'new', 4, 7, ['first_name' => 'Michael', 'last_name' => 'Chen', 'email' => 'mchen@example.com', 'phone' => '+1 415 555 0142', 'country' => 'United States'], 'DLX', '200146', 'CP', 'RP-DLX-BB', 2, 'Expedia Collect – prepaid'), 7);
        $send($test, $bk($test, 'TEST-10001', 'new', 3, 5, ['first_name' => 'Priya', 'last_name' => 'Nair', 'email' => 'priya.nair@example.in', 'country' => 'India'], 'STD', 'STD', 'CP', 'BB'), 1);
        $villa = $bk($test, 'TEST-10002', 'new', 8, 10, ['first_name' => 'Daniel', 'last_name' => 'Okafor', 'email' => 'd.okafor@example.ng', 'country' => 'Nigeria'], 'STD', 'STD', null, 'BAR');
        $villa['rooms'][0]['room_code'] = 'VILLA-POOL';
        $send($test, $villa, 0);
        $usd = $bk($test, 'TEST-10003', 'new', 9, 10, ['first_name' => 'Emma', 'last_name' => 'Wilson', 'country' => 'United Kingdom'], 'STD', 'STD', null, 'BAR');
        $usd['currency'] = 'USD';
        $send($test, $usd, 0);

        // ---- deltas after the bookings (Expedia still down; its back-off keeps the retry for later)
        foreach ([$bdc, $test] as $c) {
            \App\Services\ChannelService::push($c->fresh());
        }
        \App\Services\ChannelService::prune();
        Models\ChannelPush::where('status', 'failed')->update(['created_at' => now()->subMinutes(12)]);
        \Illuminate\Support\Facades\Http::fake([]);
    }

    protected function seedEngineering(bool $rich, Models\User $user): void
    {
        if (! $rich) {
            return;
        }
        $U = \App\Services\UtilityService::class;
        $C = \App\Services\ComplianceService::class;
        $today = bdate()->copy();
        mt_srand(3900);
        $prevUser = auth()->user();
        auth()->setUser(Models\User::where('hotel_id', Tenant::id())->where('username', 'engineer')->first() ?? $user);   // the Chief Engineer does the rounds

        // ── meters: main incomer with three sub-meters, DG + its diesel, rooftop solar, water, LPG
        $defs = [
            'EB-MAIN' => ['Main incomer – LT CT meter', 'electricity', 'Whole hotel', null, 10, 7, 9.20, 482310.0],
            'EB-KITCHEN' => ['Kitchen sub-meter', 'electricity', 'Kitchen', 'EB-MAIN', 1, 7, 9.20, 118240.0],
            'EB-LAUNDRY' => ['Laundry sub-meter', 'electricity', 'Laundry', 'EB-MAIN', 1, 5, 9.20, 99200.0],   // 5-digit register – rolls over
            'EB-HVAC' => ['Chiller plant sub-meter', 'electricity', 'HVAC / chiller', 'EB-MAIN', 1, 7, 9.20, 264400.0],
            'DG-01' => ['DG set 62.5 kVA', 'dg', 'Plant room', null, 1, 7, 0, 18420.0],
            'SOLAR-01' => ['Rooftop solar 15 kWp', 'solar', 'Whole hotel', null, 1, 7, 0, 96310.0],
            'WTR-MAIN' => ['Municipal water inlet', 'water', 'Whole hotel', null, 1, 7, 62, 38120.0],
            'LPG-BANK' => ['LPG manifold (kitchen bank)', 'lpg', 'Kitchen', null, 1, 7, 95, 22840.0],
            'HSD-DG' => ['Diesel issued to DG', 'diesel', 'Plant room', null, 1, 7, 91.5, 7420.0],
        ];
        $m = [];
        foreach ($defs as $code => [$name, $util, $area, $parent, $mult, $digits, $rate, $start]) {
            $m[$code] = $U::saveMeter(['code' => $code, 'name' => $name, 'utility' => $util, 'area' => $area, 'parent_id' => $parent ? $m[$parent]->id : null,
                'multiplier' => $mult, 'digits' => $digits, 'rate' => $rate, 'is_main' => ! $parent, 'alert_pct' => ['solar' => 45, 'dg' => 0, 'diesel' => 0][$util] ?? 30,
                'meter_no' => $util === 'electricity' && ! $parent ? 'LT-0042871' : null,
                'notes' => $util === 'dg' ? 'Fuel is costed on HSD-DG – the DG meter carries no tariff.' : ($code === 'EB-MAIN' ? 'CT ratio 100/5 → multiplier 10.' : null)]);
        }

        // ── 60 days of readings driven by occupancy (the first day is the opening baseline)
        $days = 60;
        $from = $today->copy()->subDays($days);
        $occ = $U::occupancy($from->toDateString(), $today->toDateString());
        $reg = array_map(fn ($d) => $d[7], $defs);
        $noise = fn ($v, $pct) => $v * (1 + (mt_rand(-100, 100) / 100) * $pct);
        for ($i = 0; $i <= $days; $i++) {
            $d = $from->copy()->addDays($i);
            $ds = $d->toDateString();
            $o = $occ[$ds] ?? ['rooms' => 20, 'guests' => 34];
            $rooms = max(6, $o['rooms']);
            $guests = max(10, $o['guests']);
            $back = $days - $i;                                      // days before today
            $cut = $i > 0 && $i % 9 === 4;                            // grid outage → DG runs
            $use = [
                'EB-KITCHEN' => $noise(62, .08) * ($back === 12 ? 1.8 : 1), // a 150-cover wedding
                'EB-LAUNDRY' => $noise(8 + 1.6 * $rooms, .1),
                'EB-HVAC' => $noise(95 + 7 * $rooms, .07),
                'DG-01' => $cut ? mt_rand(25, 55) : 0,
                'SOLAR-01' => $noise(62, .15) * ($i % 11 === 6 ? .45 : 1), // overcast day
                'WTR-MAIN' => $noise(4.5 + .38 * $guests, .07) * ($back === 3 ? 1.9 : 1), // leaking flush valve
                'LPG-BANK' => $noise(9 + .45 * $guests, .1),
            ];
            $use['EB-MAIN'] = ($use['EB-KITCHEN'] + $use['EB-LAUNDRY'] + $use['EB-HVAC'] + $noise(70 + 9 * $rooms, .06)) * ($cut ? .8 : 1);
            $use['HSD-DG'] = $use['DG-01'] / 3.4;                     // ~3.4 kWh per litre
            foreach ($m as $code => $meter) {
                if ($i > 0) {
                    $reg[$code] += $use[$code] / $meter->multiplier;
                    if ($reg[$code] >= $meter->rolloverAt()) {
                        $reg[$code] -= $meter->rolloverAt();
                    }
                }
                $opts = [];
                if ($code === 'EB-HVAC' && $back === 25) {            // meter replaced – the new one starts near zero
                    $reg[$code] = round($use[$code], 1);
                    $opts = ['reset' => true, 'note' => 'Old meter burnt – replaced by MSEB, new meter no. 7731902'];
                }
                if ($code === 'HSD-DG' && $back === 20) {
                    $opts = ['estimated' => true, 'note' => 'Tank dip-stick missing – estimated'];
                }
                if ($code === 'LPG-BANK' && $back === 1) {
                    continue;                                          // missed yesterday
                }
                if ($back === 0 && ! in_array($code, ['EB-MAIN', 'WTR-MAIN', 'SOLAR-01'], true)) {
                    continue;                                          // today's round is in progress
                }
                $U::record($meter, $ds, round($reg[$code], 1), $opts);
            }
        }
        // the wedding spike was explained; the water leak is still open
        foreach (Models\MeterReading::where('utility_meter_id', $m['EB-KITCHEN']->id)->whereNotNull('anomaly')->get() as $r) {
            $U::acknowledge($r, 'Wedding reception – 150 covers');
        }
        foreach (Models\MeterReading::where('utility_meter_id', $m['SOLAR-01']->id)->whereNotNull('anomaly')->get() as $r) {
            $U::acknowledge($r, 'Overcast – monsoon day');
        }
        // other flags on busy days were checked by the engineer – only the leak (3 days ago) stays open
        $leak = $today->copy()->subDays(3)->toDateString();
        foreach (Models\MeterReading::whereNotNull('anomaly')->whereNull('anomaly_ack_at')->whereIn('utility_meter_id', collect($m)->pluck('id'))->get() as $r) {
            if (! ($r->utility_meter_id === $m['WTR-MAIN']->id && $r->reading_date->toDateString() === $leak)) {
                $U::acknowledge($r, 'High occupancy – expected');
            }
        }

        // ── utility bills for last month: electricity booked to AP, water entered but not yet booked (and over-billed)
        $pf = $today->copy()->subMonthNoOverflow()->startOfMonth()->subDay()->toDateString();
        $pt = $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $mon = $today->copy()->subMonthNoOverflow()->format('M-Y');
        $eb = $U::meteredUnits($m['EB-MAIN'], $pf, $pt);
        $units = round($eb * 1.011);
        $power = Models\Supplier::where('name', 'like', '%Power%')->first();
        $bill = $U::saveBill(['utility_meter_id' => $m['EB-MAIN']->id, 'bill_no' => 'LT/'.$mon.'/0042871', 'bill_date' => $today->copy()->startOfMonth()->addDays(4)->min($today)->toDateString(),
            'period_from' => $pf, 'period_to' => $pt, 'units_billed' => $units, 'energy_charges' => round($units * 8.1, 2), 'fixed_charges' => 9800, 'taxes' => round($units * 8.1 * .05, 2),
            'due_date' => $today->copy()->startOfMonth()->addDays(19)->toDateString(), 'supplier_id' => $power?->id, 'notes' => 'LT-2 commercial tariff, contract demand 70 kVA']);
        if ($power) {
            try {
                $U::postBill($bill, $power->id, null, 'Seasonal peak – chiller load');
            } catch (\Throwable $e) {
                // the ledger may be locked in some demo states – the bill simply stays un-booked
            }
        }
        $w = $U::meteredUnits($m['WTR-MAIN'], $pf, $pt);
        $U::saveBill(['utility_meter_id' => $m['WTR-MAIN']->id, 'bill_no' => 'MWB-'.$mon.'-7781', 'bill_date' => $today->copy()->startOfMonth()->addDays(7)->min($today)->toDateString(),
            'period_from' => $pf, 'period_to' => $pt, 'units_billed' => round($w * 1.06), 'energy_charges' => round($w * 1.06 * 55, 2), 'fixed_charges' => 600, 'taxes' => 0,
            'due_date' => $today->copy()->addDays(10)->toDateString(), 'notes' => 'Billed 6% above our meter – raise with the water board']);

        // ── licences & statutory compliance register
        $C::loadTemplate();
        $users = Models\User::where('hotel_id', Tenant::id())->pluck('id', 'username');
        $at = fn (int $off) => $today->copy()->addDays($off)->toDateString();
        // name => [reference, issued offset, expires offset (null = none), owner, fee]
        $plan = [
            'FDA Food Hygiene Permit' => ['FDA/FH/GA/26-01873', -700, 30, 'chef', 1500],
            'Fire Certificate' => ['GNFS/FC/GAR/2023/1187', -1070, 25, 'engineer', 2500],
            'Business Operating Permit' => ['AMA/BOP/2026/04417', -180, 185, 'fom', 3000],
            'Liquor Licence' => ['AMA/LL/2026/0421', -179, (int) $today->diffInDays(Carbon::create($today->year, 12, 31), false), 'restaurant', 2000],
            'EPA Environmental Permit' => ['EPA/GAR/EP/2023/5521', -1000, 95, 'engineer', 5000],
            'GTA Hotel Operating Licence' => ['GTA/HL/GAR/2025/0913', -370, -5, 'fom', 3500],
            'Factories Inspectorate – Registration of Premises' => ['DFI/GAR/2019/18273', -2400, null, 'hr', 0],
            'GRA VAT Registration' => ['C0001234567', -2400, null, 'accounts', 0],
            'Data Protection Commission Registration' => ['DPC/REG/2025/11842', -270, 95, 'admin', 1800],
            'Lift Inspection Certificate' => ['DFI/LIFT/2025/331', -340, 25, 'engineer', 800],
            'Boiler / Pressure Vessel Certificate' => ['DFI/BPV/4471', -300, 65, 'engineer', 1200],
            'Electrical Installation Certificate' => ['EC/EIC/2021/0772', -1800, null, 'engineer', 0],
            'Music Licence (GHAMRO)' => ['GHAMRO/HOT/25/6612', -380, -15, 'restaurant', 4500],
            'GTA Star Rating / Grading Certificate' => ['GTA/GRD/4-STAR/2024/118', -800, 295, 'admin', 2500],
            'Signage / Advertising Permit' => ['AMA/ADV/2025/7719', -300, 65, 'fom', 1800],
            'Swimming Pool Permit' => ['AMA/EHU/SPP/2026/0331', -200, 165, 'engineer', 900],
            'Weighing Scale Verification' => ['GSA/MET/2025/99812', -330, 35, 'chef', 300],
            'Commercial Building Fire Insurance (compulsory)' => ['SIC/FIR/2025/00492', -300, 65, 'accounts', 38000],
            'Public Liability Insurance' => ['EIC/PLI/2025/77120', -350, 15, 'accounts', 9500],
            'Fire-Fighting Equipment Servicing & Extinguisher Refilling' => ['FIRE-SVC/25-26', -330, 35, 'engineer', 6500],
            'Lift Maintenance Contract' => ['OTIS/GH/AMC/1182', -200, 165, 'engineer', 18000],
        ];
        $items = Models\ComplianceItem::all()->keyBy('name');
        foreach ($plan as $name => [$ref, $iss, $exp, $owner, $fee]) {
            $it = $items[$name] ?? null;
            if (! $it) {
                continue;
            }
            $C::save(['name' => $it->name, 'category' => $it->category, 'authority' => $it->authority, 'department' => $it->department, 'is_critical' => $it->is_critical,
                'lead_days' => $it->lead_days, 'reference_no' => $ref, 'issued_on' => $at($iss), 'expires_on' => $exp === null ? null : $at($exp), 'owner_id' => $users[$owner] ?? null, 'fee' => $fee], $it);
            Models\ComplianceEvent::where('compliance_item_id', $it->id)->update(['event_date' => $at($iss)]);
        }
        // the Fire Certificate renewal is with GNFS; the FDA permit was last renewed two years ago
        $C::applyRenewal($items['Fire Certificate']->fresh(), ['applied_on' => $at(-12), 'reference' => 'FIRE-APP-88213', 'fee' => 2500, 'note' => 'GNFS fire safety inspection scheduled']);
        // recurring inspections with history (one failed pest-control visit, re-done two days later)
        $insp = [
            'Fire Drill & Evacuation Rehearsal' => [[-165, 'pass'], [-75, 'pass']],
            'Drinking Water Potability Test' => [[-63, 'pass'], [-33, 'pass']],
            'Pest Control Service' => [[-80, 'pass'], [-52, 'fail'], [-50, 'pass'], [-20, 'pass']],
            'Electrical Safety Audit' => [[-200, 'pass']],
            'Kitchen Exhaust & Duct Cleaning' => [[-130, 'pass'], [-40, 'pass']],
            'Food Handlers – Health Certificates' => [[-300, 'pass']],
        ];
        $notes = ['fail' => 'Cockroach activity behind the dish-wash area – treatment incomplete', 'pass' => null];
        $owners = ['Fire Drill & Evacuation Rehearsal' => 'fom', 'Drinking Water Potability Test' => 'engineer', 'Pest Control Service' => 'housekeeping',
            'Electrical Safety Audit' => 'engineer', 'Kitchen Exhaust & Duct Cleaning' => 'chef', 'Food Handlers – Health Certificates' => 'hr'];
        foreach ($insp as $name => $visits) {
            $it = $items[$name] ?? null;
            if (! $it) {
                continue;
            }
            $it->update(['owner_id' => $users[$owners[$name]] ?? null]);
            Models\ComplianceEvent::where('compliance_item_id', $it->id)->update(['event_date' => $at($visits[0][0] - 30)]);
            foreach ($visits as [$off, $res]) {
                $C::inspect($it->fresh(), ['done_on' => $at($off), 'result' => $res, 'note' => $notes[$res], 'reference' => 'RPT-'.mt_rand(1000, 9999), 'fee' => $name === 'Pest Control Service' ? 4500 : 0]);
            }
        }
        // the seeded failure was fixed – close its log-book item
        foreach (Models\LogEntry::where('title', 'like', 'Failed: Pest Control%')->get() as $e) {
            \App\Services\LogBookService::resolve($e, 'Re-treated on '.Carbon::parse($at(-50))->format('d M').' – passed');
        }
        $C::alerts();   // today's digest: due-soon and expired items → e-mail + log book
        auth()->setUser($prevUser ?? $user);
        mt_srand();
    }

    protected function seedTransport(bool $rich, Models\User $user): void
    {
        $TS = \App\Services\TransportService::class;
        $TS::ensureDefaults();
        $prevUser = auth()->user();
        if ($rich && ($desk = Models\User::where('username', 'concierge')->where('hotel_id', hotel()->id)->first())) {
            auth()->setUser($desk);
        }
        $now = now()->second(0);
        $d = fn (int $days) => $now->copy()->addDays($days)->toDateString();
        $mkV = fn (array $a) => Models\Vehicle::create($a + ['insurance_until' => $d(220), 'permit_until' => $d(300), 'fitness_until' => $d(400), 'puc_until' => $d(120)]);
        if (! $rich) {
            $mkV(['code' => 'BUGGY-1', 'name' => 'Electric golf buggy', 'reg_no' => 'RESORT-B1', 'type' => 'buggy', 'seats' => 6, 'luggage' => 4, 'fuel' => 'electric', 'color' => 'White', 'odometer' => 3120]);
            $mkV(['code' => 'SCORPIO-1', 'name' => 'Mahindra Scorpio-N', 'reg_no' => 'KL 07 CE 2210', 'type' => 'suv', 'seats' => 6, 'luggage' => 3, 'fuel' => 'diesel', 'color' => 'Black', 'odometer' => 41200]);
            Models\Driver::create(['name' => 'Biju Thomas', 'phone' => '+91 94470 12345', 'licence_no' => 'KL07 20110012345', 'licence_until' => $d(900), 'languages' => 'Malayalam, English, Hindi']);

            return;
        }
        $v = collect([
            $mkV(['code' => 'INNOVA-1', 'name' => 'Toyota Innova Crysta', 'reg_no' => 'KA 01 AB 4521', 'type' => 'suv', 'seats' => 6, 'luggage' => 4, 'fuel' => 'diesel', 'color' => 'Pearl white', 'odometer' => 48210]),
            $mkV(['code' => 'INNOVA-2', 'name' => 'Toyota Innova Crysta', 'reg_no' => 'KA 01 AB 7788', 'type' => 'suv', 'seats' => 6, 'luggage' => 4, 'fuel' => 'diesel', 'color' => 'Silver', 'odometer' => 61540, 'insurance_until' => $d(12)]),
            $mkV(['code' => 'DZIRE-1', 'name' => 'Maruti Suzuki Dzire', 'reg_no' => 'KA 03 MN 1290', 'type' => 'sedan', 'seats' => 4, 'luggage' => 2, 'fuel' => 'cng', 'color' => 'White', 'odometer' => 88400]),
            $mkV(['code' => 'TEMPO-1', 'name' => 'Force Tempo Traveller 12-seater', 'reg_no' => 'KA 05 TT 3321', 'type' => 'minibus', 'seats' => 12, 'luggage' => 10, 'fuel' => 'diesel', 'color' => 'White', 'odometer' => 132900, 'puc_until' => $d(-3)]),
            $mkV(['code' => 'MERC-1', 'name' => 'Mercedes-Benz E-Class', 'reg_no' => 'KA 01 LX 0001', 'type' => 'luxury', 'seats' => 3, 'luggage' => 2, 'fuel' => 'petrol', 'color' => 'Obsidian black', 'odometer' => 22150, 'status' => 'maintenance', 'notes' => 'At the dealer for the 20,000 km service – back Friday.']),
        ])->keyBy('code');
        $drvUser = Models\User::where('username', 'driver')->where('hotel_id', hotel()->id)->value('id');
        $dr = collect([
            Models\Driver::create(['name' => 'Sunil Yadav', 'phone' => '+91 98450 11223', 'user_id' => $drvUser, 'licence_no' => 'KA01 20090034512', 'licence_until' => $d(1400), 'languages' => 'Kannada, Hindi, English']),
            Models\Driver::create(['name' => 'Mahesh Gowda', 'phone' => '+91 98450 22334', 'licence_no' => 'KA03 20120045671', 'licence_until' => $d(800), 'languages' => 'Kannada, English']),
            Models\Driver::create(['name' => 'Abdul Rahim', 'phone' => '+91 98450 33445', 'licence_no' => 'KA02 20060011209', 'licence_until' => $d(20), 'languages' => 'Urdu, Hindi, English, Tamil']),
            Models\Driver::create(['name' => 'Joseph D\'Souza', 'phone' => '+91 98450 44556', 'licence_no' => 'KA05 20150078890', 'licence_until' => $d(1100), 'languages' => 'Konkani, English, Hindi']),
            Models\Driver::create(['name' => 'Kiran Patil', 'phone' => '+91 98450 55667', 'licence_no' => 'KA04 20100066128', 'licence_until' => $d(-10), 'languages' => 'Marathi, Kannada, Hindi', 'notes' => 'Licence renewal applied – off duty until it arrives.']),
        ])->keyBy('name');
        $tar = Models\TransportTariff::orderBy('sort_order')->get();
        $air = ['sedan' => $tar->firstWhere('name', 'Airport transfer – Sedan'), 'suv' => $tar->firstWhere('name', 'Airport transfer – SUV'), 'minibus' => $tar->firstWhere('kind', 'airport')];
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id') ?? $cash;
        $c = $TS::cfg();

        // ── two weeks of history ──
        $names = ['Anand Krishnan', 'Sarah Thompson', 'Rohit Verma', 'Meenakshi Sundaram', 'James O\'Connor', 'Priyanka Bhat', 'Hiroshi Tanaka', 'Deepak Chawla', 'Aisha Rahman',
            'Mark Wilson', 'Sunita Rao', 'Varun Ahuja', 'Linda Park', 'Gopal Hegde', 'Nadia Hussain', 'Sanjay Kulkarni', 'Clara Schmidt', 'Harish Menon', 'Zara Ali', 'Peter Jacob'];
        $flights = ['6E 5302', 'AI 503', 'UK 811', 'QP 1347', '6E 2131', 'AI 1576', 'EK 568', 'SQ 510', 'IX 1284', 'LH 754'];
        $use = [['INNOVA-1', 'Sunil Yadav'], ['DZIRE-1', 'Mahesh Gowda'], ['INNOVA-2', 'Abdul Rahim'], ['INNOVA-1', 'Joseph D\'Souza'], ['TEMPO-1', 'Abdul Rahim'], ['DZIRE-1', 'Sunil Yadav']];
        $odo = ['INNOVA-1' => 46900, 'INNOVA-2' => 60300, 'DZIRE-1' => 87200, 'TEMPO-1' => 132100];
        $n = 0;
        for ($day = 14; $day >= 1; $day--) {
            foreach (range(1, 1 + ($day % 3)) as $k) {
                $n++;
                [$vc, $dn] = $use[$n % count($use)];
                $type = $n % 2 ? 'pickup' : 'drop';
                $vt = $v[$vc]->type === 'minibus' ? 'minibus' : $v[$vc]->type;
                $t0 = $air[$vt] ?? $air['sedan'];
                $at = $now->copy()->subDays($day)->setTime([6, 10, 14, 19][$k % 4] + ($n % 3), [0, 15, 30, 45][$n % 4]);
                [$from, $to] = $TS::window($type, $at, (int) $t0->duration_min);
                $km = 38 + ($n * 7) % 21;
                $late = [2, 4, 0, 6, 14, 3, 1, 8, 18, 5][$n % 10];
                $status = $n === 9 ? 'cancelled' : ($n === 17 ? 'no_show' : 'completed');
                $billing = $n % 5 === 0 ? 'complimentary' : 'direct';
                $extra = $status === 'completed' && $n % 4 === 0 ? 150 : 0;
                $row = ['trip_no' => Numbering::next('transport_trip'), 'type' => $type, 'status' => $status, 'source' => $n % 3 ? 'reservation' : 'staff',
                    'scheduled_at' => $at, 'duration_min' => $t0->duration_min, 'busy_from' => $from, 'busy_to' => $to,
                    'passenger_name' => $names[$n % count($names)], 'phone' => '+91 99'.str_pad((string) ((crc32($names[$n % count($names)]) & 0xffffff) % 100000000), 8, '0', STR_PAD_LEFT),
                    'pax' => 1 + $n % 4, 'bags' => 1 + $n % 3, 'flight_no' => $flights[$n % count($flights)],
                    'pickup_point' => $type === 'pickup' ? $c['airport'] : hotel()->name, 'dropoff_point' => $type === 'pickup' ? hotel()->name : $c['airport'],
                    'transport_tariff_id' => $t0->id, 'vehicle_type' => $t0->vehicle_type, 'billing' => $billing, 'amount' => $t0->flat_rate,
                    'extra_amount' => $extra, 'extra_note' => $extra ? 'Parking + toll' : null, 'total' => (float) $t0->flat_rate + $extra,
                    'vehicle_id' => $status === 'cancelled' ? null : $v[$vc]->id, 'driver_id' => $status === 'cancelled' ? null : $dr[$dn]->id, 'created_by' => $user->id];
                if ($status === 'completed') {
                    $row += ['assigned_at' => $from->copy()->subHours(5), 'started_at' => $from->copy()->addMinutes($late), 'onboard_at' => $type === 'pickup' ? $at->copy()->addMinutes(25) : $from->copy()->addMinutes($late + 3),
                        'completed_at' => $to->copy()->subMinutes(10), 'odo_start' => $odo[$vc], 'odo_end' => $odo[$vc] + $km,
                        'payment_mode_id' => $billing === 'direct' ? ($n % 2 ? $cash : $card) : null];
                    $odo[$vc] += $km + 12;
                } elseif ($status === 'no_show') {
                    $row += ['assigned_at' => $from->copy()->subHours(5), 'started_at' => $from, 'cancelled_at' => $at->copy()->addMinutes(75), 'cancel_reason' => 'Guest not at arrivals after 75 min – phone switched off', 'odo_start' => $odo[$vc], 'odo_end' => $odo[$vc] + 44];
                    $odo[$vc] += 56;
                } else {
                    $row += ['cancelled_at' => $at->copy()->subDay(), 'cancel_reason' => 'Flight cancelled – guest re-booked for the next day'];
                }
                $t = Models\TransportTrip::create($row);
                $t->forceFill(['created_at' => $at->copy()->subDays(2), 'updated_at' => $row['completed_at'] ?? $row['cancelled_at']])->save();
            }
        }

        // ── live trips ──
        $arr = Models\ReservationRoom::with('reservation.guest', 'guest')->where('status', 'reserved')->whereDate('arrival_date', '>=', $d(0))->whereDate('arrival_date', '<=', $d(2))->orderBy('arrival_date')->orderByDesc('id')->take(3)->get();
        $inh = Models\ReservationRoom::with('reservation.guest', 'guest', 'room')->where('status', 'checked_in')->whereDate('departure_date', '>', $d(0))->orderByDesc('id')->take(3)->get();
        $depToday = Models\ReservationRoom::with('reservation.guest', 'guest')->where('status', 'checked_in')->whereDate('departure_date', $d(0))->orderBy('id')->first();
        $mk = function (string $type, ?Models\ReservationRoom $st, array $x) use ($TS) {
            return $TS::save(array_merge($TS::prefill($st?->reservation, $type, $st), ['notify' => false], $x));
        };
        if ($arr->count() > 0) { // on the road now
            $t = $mk('pickup', $arr[0], ['scheduled_at' => $now->copy()->addMinutes(40)->format('Y-m-d H:i'), 'flight_no' => '6E 5302', 'bags' => 3, 'transport_tariff_id' => $air['suv']->id, 'amount' => null,
                'billing' => 'room', 'notes' => 'Guest travelling with an infant – child seat fitted.']);
            $TS::assign($t, $v['INNOVA-1']->id, $dr['Sunil Yadav']->id, false, false);
            $TS::start($t->fresh());
        }
        if ($arr->count() > 1) { // assigned for later today
            $t = $mk('pickup', $arr[1], ['scheduled_at' => $now->copy()->addHours(3)->minute(35)->format('Y-m-d H:i'), 'flight_no' => 'AI 503', 'bags' => 2, 'transport_tariff_id' => $air['sedan']->id, 'amount' => null, 'billing' => 'room']);
            $TS::assign($t, $v['DZIRE-1']->id, $dr['Mahesh Gowda']->id, false, false);
        }
        if ($arr->count() > 2) { // confirmed, no car yet – shows as an alert
            $mk('pickup', $arr[2], ['scheduled_at' => $now->copy()->addHours(2)->minute(10)->format('Y-m-d H:i'), 'flight_no' => 'UK 811', 'bags' => 4, 'pax' => 3, 'transport_tariff_id' => $air['suv']->id, 'amount' => null, 'billing' => 'room',
                'notes' => 'VIP – meet at gate 4 with the name board.']);
        }
        if ($depToday) { // today's departure – complimentary drop (nothing pending on the bill)
            $t = $mk('drop', $depToday, ['scheduled_at' => $now->copy()->addMinutes(75)->format('Y-m-d H:i'), 'billing' => 'complimentary', 'amount' => 0, 'notes' => 'Complimentary drop – loyalty Gold member.']);
            $TS::assign($t, $v['INNOVA-2']->id, $dr['Joseph D\'Souza']->id, false, false);
        }
        if ($inh->count() > 0) { // guest asked via the portal – waiting for the desk
            $st = $inh[0];
            $TS::portalRequest($st, ['type' => 'drop', 'date' => $d(1), 'time' => '08:30', 'pax' => max(1, $st->adults), 'bags' => 2, 'flight_no' => 'QP 1347', 'notes' => 'Early flight – please keep a breakfast box.']);
        }
        if ($inh->count() > 1) { // drop on the departure day, already assigned
            $st = $inh[1];
            $t = $mk('drop', $st, ['scheduled_at' => $st->departure_date->toDateString().' 10:30', 'flight_no' => 'EK 568', 'bags' => 3, 'billing' => 'room', 'transport_tariff_id' => $air['suv']->id, 'amount' => null]);
            $TS::assign($t, $v['INNOVA-2']->id, $dr['Abdul Rahim']->id, true, false);
        }
        if ($inh->count() > 2) { // city tour tomorrow, paid direct
            $st = $inh[2];
            $tour = $tar->firstWhere('kind', 'tour');
            $mk('transfer', $st, ['scheduled_at' => $d(1).' 10:00', 'pickup_point' => hotel()->name, 'dropoff_point' => 'City sightseeing – palace, gardens & market', 'transport_tariff_id' => $tour?->id, 'amount' => null,
                'billing' => 'direct', 'notes' => 'Guest would like an English-speaking driver.']);
        }
        // a restaurant guest's station drop – no stay, paid direct
        $mk('drop', null, ['passenger_name' => 'Mr. Rajesh Khanna', 'phone' => '+91 98860 45120', 'pax' => 2, 'scheduled_at' => $d(1).' 18:00', 'dropoff_point' => $c['station'],
            'transport_tariff_id' => $tar->firstWhere('kind', 'railway')?->id, 'amount' => null, 'billing' => 'direct', 'source' => 'staff']);

        // ── running costs for the month ──
        foreach (['INNOVA-1' => [2900, 38], 'INNOVA-2' => [3100, 40], 'DZIRE-1' => [1100, 12], 'TEMPO-1' => [4800, 55]] as $code => [$amt, $lit]) {
            for ($day = 27; $day >= 1; $day -= 6) {
                Models\VehicleExpense::create(['vehicle_id' => $v[$code]->id, 'date' => $d(-$day), 'kind' => 'fuel', 'litres' => $lit + ($day % 5), 'amount' => $amt + ($day % 5) * 95,
                    'odometer' => max(0, $odo[$code] - $day * 40), 'vendor' => $code === 'DZIRE-1' ? 'Indraprastha CNG, Airport Rd' : 'HP Petrol Pump, Hebbal', 'driver_id' => $dr['Sunil Yadav']->id, 'created_by' => $user->id]);
            }
            Models\VehicleExpense::create(['vehicle_id' => $v[$code]->id, 'date' => $d(-5), 'kind' => 'toll', 'amount' => 285, 'vendor' => 'Airport expressway toll', 'created_by' => $user->id]);
            Models\VehicleExpense::create(['vehicle_id' => $v[$code]->id, 'date' => $d(-2), 'kind' => 'parking', 'amount' => 150, 'vendor' => 'Airport arrivals parking', 'created_by' => $user->id]);
        }
        Models\VehicleExpense::create(['vehicle_id' => $v['INNOVA-2']->id, 'date' => $d(-9), 'kind' => 'service', 'amount' => 14850, 'odometer' => 60500, 'vendor' => 'Toyota Service – Nandi Motors', 'notes' => '60,000 km service, brake pads', 'created_by' => $user->id]);
        Models\VehicleExpense::create(['vehicle_id' => $v['TEMPO-1']->id, 'date' => $d(-16), 'kind' => 'repair', 'amount' => 6200, 'vendor' => 'Sri Ganesh Auto Works', 'notes' => 'AC compressor belt', 'created_by' => $user->id]);
        foreach ($odo as $code => $km) {
            if ($km > $v[$code]->fresh()->odometer) {
                $v[$code]->forceFill(['odometer' => $km])->save();
            }
        }
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedParking(bool $rich): void
    {
        \App\Services\ParkingService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $PS = \App\Services\ParkingService::class;
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $valet = $u('valet') ?? $admin;
        $fom = $u('fom') ?? $admin;
        $cashier = $u('cashier') ?? $admin;
        Auth::setUser($valet);
        $Z = Models\ParkingZone::pluck('id', 'code');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $cars = [['Toyota', 'Corolla', 'Silver', 'car'], ['Hyundai', 'Elantra', 'White', 'car'], ['Toyota', 'Land Cruiser', 'Black', 'suv'], ['Kia', 'Sportage', 'Grey', 'suv'], ['Honda', 'Civic', 'Blue', 'car'],
            ['Mercedes-Benz', 'C300', 'Black', 'car'], ['Nissan', 'Almera', 'Red', 'car'], ['Toyota', 'RAV4', 'White', 'suv'], ['Ford', 'Ranger', 'Silver', 'pickup'], ['Lexus', 'RX 350', 'Pearl', 'suv'],
            ['Hyundai', 'Tucson', 'Brown', 'suv'], ['Toyota', 'Camry', 'Grey', 'car'], ['Toyota', 'Hiace', 'White', 'van'], ['Kia', 'Picanto', 'Yellow', 'car']];
        $regions = ['GR', 'GT', 'GW', 'GE', 'GN', 'GC', 'AS', 'WR', 'CR', 'ER', 'GX', 'VR'];
        $plate = fn (int $i) => $regions[$i % 12].' '.(1000 + ($i * 373) % 8999).'-'.(18 + $i % 7);
        $car = fn (int $i) => ['make' => $cars[$i % 14][0], 'model' => $cars[$i % 14][1], 'colour' => $cars[$i % 14][2], 'vehicle_type' => $cars[$i % 14][3]];
        $names = ['Kwame Mensah', 'Ama Owusu', 'Kofi Boateng', 'Abena Asante', 'Yaw Darko', 'Efua Quaye', 'Kojo Appiah', 'Akua Frimpong', 'Nii Armah', 'Adwoa Sarpong', 'Fiifi Coleman', 'Esi Bonsu'];
        $B = bdate()->copy()->startOfDay();
        $clock = Carbon::getTestNow(); // keep a frozen test clock intact

        // ---- permits: staff, a contractor expiring soon, one expired, one revoked
        $emps = Models\Employee::where('is_active', true)->orderBy('id')->take(5)->get();
        Auth::setUser($fom);
        $permits = [];
        foreach ($emps as $i => $e) {
            $permits[] = $PS::savePermit(['plate' => $plate(200 + $i), 'holder_name' => $e->name, 'holder_type' => 'staff', 'employee_id' => $e->id, 'parking_zone_id' => $Z['S1'] ?? null,
                'valid_from' => $B->copy()->subMonths(3)->toDateString(), 'valid_to' => $B->copy()->addMonths(3 + $i)->toDateString(), 'fee' => 0] + $car(200 + $i), $fom);
        }
        $contractor = $PS::savePermit(['plate' => 'GT 4471-23', 'holder_name' => 'Volta Lifts Ltd (service engineer)', 'holder_type' => 'contractor', 'parking_zone_id' => $Z['S1'] ?? null,
            'valid_from' => $B->copy()->subDays(25)->toDateString(), 'valid_to' => $B->copy()->addDays(5)->toDateString(), 'fee' => 150, 'notes' => 'Lift modernisation contract', 'make' => 'Toyota', 'model' => 'Hilux', 'colour' => 'White', 'vehicle_type' => 'pickup'], $fom);
        $PS::savePermit(['plate' => 'GE 3390-21', 'holder_name' => 'Accra Office Tenants – Suite 3', 'holder_type' => 'tenant', 'valid_from' => $B->copy()->subMonths(8)->toDateString(),
            'valid_to' => $B->copy()->subDays(12)->toDateString(), 'fee' => 400, 'make' => 'Hyundai', 'model' => 'Santa Fe', 'colour' => 'Grey', 'vehicle_type' => 'suv'], $fom);
        $rv = $PS::savePermit(['plate' => 'GR 9021-20', 'holder_name' => 'Former bartender', 'holder_type' => 'staff', 'valid_from' => $B->copy()->subMonths(2)->toDateString(),
            'valid_to' => $B->copy()->addMonths(4)->toDateString(), 'make' => 'Nissan', 'model' => 'Sunny', 'colour' => 'Blue', 'vehicle_type' => 'car'], $fom);
        $PS::revokePermit($rv, 'Left employment', $fom);

        // ---- history: 14 closed days of visitors (some valet, validated, one lost ticket) paid at the exit
        Auth::setUser($valet);
        $n = 0;
        for ($d = 14; $d >= 1; $d--) {
            $day = $B->copy()->subDays($d);
            $count = 4 + ($d % 4);
            for ($j = 0; $j < $count; $j++, $n++) {
                $in = $day->copy()->setTime(9 + ($j * 2) % 11, ($j * 17) % 60);
                $mins = [25, 75, 140, 200, 55, 330, 95, 480][($n) % 8];
                $out = $in->copy()->addMinutes($mins);
                $isValet = $n % 3 === 0;
                $mode = $cash; // back-dated history is posted to the GL: keep it off the reconciled MoMo / bank / card-sweep accounts
                Carbon::setTestNow($in);
                $s = $PS::checkIn(['kind' => 'visitor', 'parking_zone_id' => $isValet ? $Z['V1'] : $Z['P2'], 'plate' => $plate($n), 'guest_name' => $names[$n % 12],
                    'is_valet' => $isValet, 'key_tag' => $isValet ? 'K-'.str_pad((string) (100 + $n), 3, '0', STR_PAD_LEFT) : null, 'fuel' => $isValet ? '1/2' : null,
                    'damage' => $isValet && $n % 4 === 0 ? ['rear'] : [], 'condition_notes' => $isValet && $n % 4 === 0 ? 'Scuff on rear bumper' : null] + $car($n), $valet);
                if ($n % 5 === 1) {
                    Auth::setUser($cashier);
                    $PS::validate($s, 2, 'Restaurant bill BIL-'.str_pad((string) (400 + $n), 5, '0', STR_PAD_LEFT), $cashier);
                    Auth::setUser($valet);
                }
                if ($isValet) {
                    $req = $out->copy()->subMinutes(4 + ($n % 11));
                    Carbon::setTestNow($req);
                    $PS::request($s->fresh(), $n % 2 ? 'qr' : 'desk');
                    Carbon::setTestNow($req->copy()->addMinutes(4 + ($n * 7) % 12));
                    $PS::ready($s->fresh(), $valet);
                }
                Carbon::setTestNow($out);
                $s = $PS::checkOut($s->fresh(), ['payment_mode_id' => $mode, 'lost_ticket' => $n === 23, ], $valet);
                if ($s->folio_id) {
                    Models\FolioTransaction::where('folio_id', $s->folio_id)->update(['business_date' => $day->toDateString()]);
                    Models\Folio::whereKey($s->folio_id)->update(['invoice_date' => $day->toDateString()]);
                }
                Models\ParkingCharge::where('parking_session_id', $s->id)->update(['business_date' => $day->toDateString()]);
                Carbon::setTestNow($clock);
            }
        }
        // a vehicle banned after leaving without paying (gate barrier forced)
        $bv = Models\ParkingVehicle::where('plate', \App\Services\ParkingService::normalisePlate($plate(7)))->first();
        if ($bv) {
            Auth::setUser($fom);
            $PS::ban($bv, true, 'Forced the exit barrier without paying on '.$B->copy()->subDays(9)->format('d M').' – security incident report', $fom);
            Auth::setUser($valet);
        }

        // ---- in-house guests (self-park is complimentary; valet nights on the room folio)
        $inHouse = Models\ReservationRoom::with(['guest', 'room'])->where('status', 'checked_in')->orderBy('id')->get()->values();
        $gs = [];
        foreach ([[0, false, 'B-04'], [3, true, null], [4, true, null], [5, false, 'B-11'], [6, true, null], [8, false, 'B-17']] as $k => [$idx, $isValet, $bay]) {
            $st = $inHouse[$idx] ?? null;
            if (! $st) {
                continue;
            }
            $in = Carbon::parse($st->checked_in_at ?? $st->arrival_date->copy()->setTime(14, 0))->copy()->subMinutes(10);
            $in = $in->lt(now()->subDays(29)) ? now()->subDays(5) : $in;
            Carbon::setTestNow($in->lt(now()) ? $in : now()->subHour());
            $gs[$k] = $s = $PS::checkIn(['kind' => 'guest', 'reservation_room_id' => $st->id, 'parking_zone_id' => $isValet ? $Z['V1'] : $Z['P1'], 'plate' => $plate(60 + $k), 'bay' => $bay,
                'is_valet' => $isValet, 'key_tag' => $isValet ? 'K-0'.(10 + $k) : null, 'fuel' => $isValet ? ['3/4', 'F', '1/2'][$k % 3] : null,
                'damage' => $isValet && $k === 2 ? ['left', 'wheels'] : [], 'condition_notes' => $isValet && $k === 2 ? 'Dent on the left rear door, kerbed alloy' : null,
                'valuables' => $isValet && $k === 1 ? 'Laptop bag in the boot (declared)' : null] + $car(60 + $k), $valet);
            Carbon::setTestNow($clock);
            if ($isValet) { // nights already audited go to the room folio, as the night audit would have done
                $rate = $PS::nightRate($s);
                for ($night = $s->entered_at->copy()->startOfDay(); $night->lt($B); $night->addDay()) {
                    $folio = FolioService::openForStay($st);
                    $tx = FolioService::charge($folio, 'parking', "Valet parking {$s->plate} – night of {$night->format('d M')}", $rate, true,
                        ['source_type' => 'parking', 'source_id' => $s->id, 'reference' => $s->number, 'business_date' => $night->toDateString()]);
                    Models\ParkingCharge::create(['parking_session_id' => $s->id, 'kind' => 'night', 'night' => $night->toDateString(), 'business_date' => $night->toDateString(), 'amount' => $rate,
                        'folio_id' => $folio->id, 'folio_transaction_id' => $tx->id]);
                }
            }
        }
        if (isset($gs[4])) { // a guest phoned for the car a few minutes ago
            Carbon::setTestNow(now()->subMinutes(4));
            $PS::request($gs[4]->fresh(), 'phone');
            Carbon::setTestNow($clock);
        }
        // a guest who checked out this morning – the car is still in the basement
        $gone = Models\ReservationRoom::with('guest')->where('status', 'checked_out')->orderByDesc('checked_out_at')->orderByDesc('id')->first();
        if ($gone) {
            $v = Models\ParkingVehicle::create(['plate' => $PS::normalisePlate('GC 5502-23'), 'plate_display' => 'GC 5502-23', 'make' => 'Toyota', 'model' => 'Prado', 'colour' => 'Gold', 'type' => 'suv', 'guest_id' => $gone->guest_id, 'last_seen_at' => now()->subDays(2)]);
            Models\ParkingSession::create(['number' => Numbering::next('parking_ticket'), 'parking_zone_id' => $Z['P1'], 'parking_vehicle_id' => $v->id, 'plate' => $v->plate_display, 'kind' => 'guest',
                'reservation_room_id' => $gone->id, 'guest_name' => $gone->guest?->full_name, 'bay' => 'B-22', 'entered_at' => now()->subDays(2)->setTime(15, 20), 'status' => 'parked', 'parked_by' => $valet->id]);
        }

        // ---- visitors in now: restaurant diners (one validated), a valet car at the door, one requested past the target, an overstay
        $vis = [[90, 'P2', false, 'Mr Tetteh'], [35, 'P2', false, 'Business lunch'], [150, 'P2', false, 'Wedding enquiry'], [20, 'P2', false, null], [80, 'V1', true, 'Dr Ofori'], [55, 'V1', true, 'Mrs Addo'], [1800, 'P2', false, 'Unknown – no contact']];
        $vs = [];
        foreach ($vis as $k => [$ago, $zc, $isValet, $who]) {
            Carbon::setTestNow(now()->subMinutes($ago));
            $vs[$k] = $PS::checkIn(['kind' => 'visitor', 'parking_zone_id' => $Z[$zc], 'plate' => $plate(120 + $k), 'guest_name' => $who, 'phone' => $isValet ? '024'.(4400000 + $k * 131) : null,
                'is_valet' => $isValet, 'key_tag' => $isValet ? 'K-2'.(10 + $k) : null, 'fuel' => $isValet ? '1/4' : null] + $car(120 + $k), $valet);
            Carbon::setTestNow($clock);
        }
        Auth::setUser($cashier);
        $PS::validate($vs[0], 3, 'Restaurant bill – table 7', $cashier);
        Auth::setUser($valet);
        Carbon::setTestNow(now()->subMinutes(9));
        $PS::request($vs[4]->fresh(), 'qr');
        Carbon::setTestNow(now()->addMinutes(3)); // 6 min ago
        $PS::ready($vs[4]->fresh(), $valet);
        Carbon::setTestNow($clock);
        Carbon::setTestNow(now()->subMinutes(14));
        $PS::request($vs[5]->fresh(), 'desk'); // past the 10-minute target
        Carbon::setTestNow($clock);

        // ---- staff on shift with permits
        foreach (array_slice($permits, 0, 3) as $k => $p) {
            Carbon::setTestNow(now()->subHours(3 + $k));
            $PS::checkIn(['kind' => 'staff', 'parking_zone_id' => $Z['S1'], 'plate' => $p->vehicle->plate_display], $valet);
            Carbon::setTestNow($clock);
        }
        Carbon::setTestNow($clock);
        Auth::setUser($admin);
        \App\Services\LedgerService::sync(true); // back-dated valet nights & exit invoices sit outside the incremental window
    }

    protected function seedGhanaTwo(bool $rich, Carbon $today): void
    {
        if (! $rich) {
            return;
        }
        $aug = $today->copy()->subMonthNoOverflow()->format('Y-m');
        if (Models\SupplierPayment::whereNull('cancelled_at')->where('wht_amount', '>', 0)->where('date', 'like', $aug.'-%')->exists() && ! \App\Services\WhtService::isFiled($aug)) {
            \App\Services\WhtService::file($aug, 'WHT/'.str_replace('-', '', $aug).'/0419', \App\Services\WhtService::due($aug)->copy()->subDays(3)->toDateString())
                ->forceFill(['filed_by' => Models\User::where('username', 'accounts')->value('id')])->save();
        }
        if (! \App\Services\PosEvatService::enabled()) {
            return;
        }
        $bills = Models\PosOrder::where('status', 'settled')->where('settlement', 'payment')->where('total', '>', 0)->whereNull('evat_status')->orderBy('business_date')->orderBy('id')->get();
        if ($bills->isEmpty()) {
            return;
        }
        $late = $bills->pop();
        if ($b2b = $bills->sortByDesc('total')->first()) {
            $b2b->forceFill(['buyer_tin' => 'C0083522567', 'buyer_name' => 'Infosys Ghana Ltd'])->saveQuietly();
        }
        $clock = Carbon::getTestNow(); // keep a frozen test clock intact
        foreach ($bills as $o) {
            $at = $o->settled_at ?? $o->updated_at;
            Carbon::setTestNow($at && $at->lt(now()) ? $at : now()); // stamp time = settlement time
            \App\Services\PosEvatService::generate($o->fresh());
            Carbon::setTestNow($clock);
        }
        $msg = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';
        $late->forceFill(['evat_status' => 'failed', 'evat_error' => '[NETWORK] '.$msg, 'evat_attempts' => 1, 'evat_retryable' => true, 'evat_next_try_at' => now()->addHours(6)])->saveQuietly();
        Models\EinvoiceLog::create(['pos_order_id' => $late->id, 'doc_no' => $late->bill_no, 'action' => 'generate', 'driver' => \App\Services\EInvoiceService::driver(), 'ok' => false,
            'error_code' => 'NETWORK', 'message' => $msg, 'duration_ms' => 30001, 'created_at' => now()->subMinutes(20)]);
    }

    protected function seedInbox(bool $rich): void
    {
        $hid = \App\Support\Tenant::id();
        $admin = Models\User::where('hotel_id', $hid)->where('username', 'admin')->first();
        if (! $admin) {
            return;
        }
        $fd = Models\User::where('hotel_id', $hid)->where('username', 'frontdesk')->first() ?? $admin;
        $fom = Models\User::where('hotel_id', $hid)->where('username', 'fom')->first() ?? $admin;
        $real = now()->copy();
        $qr = fn (string $s, string $t, string $b, ?string $d = null, int $uses = 0) => Models\InboxQuickReply::create(['shortcut' => $s, 'title' => $t, 'body' => $b, 'department' => $d, 'is_active' => true, 'uses' => $uses, 'created_by' => $admin->id]);
        $qWifi = $qr('wifi', 'Wi-Fi details', 'Hello {guest}, the Wi-Fi network is "{wifi_name}" and the password is {wifi_password}. Enjoy your stay!', null, $rich ? 14 : 0);
        $qr('checkout', 'Check-out time', 'Check-out is at {checkout_time}, {guest}. You can also check out from the guest portal or the lobby kiosk – just leave your key card in the box at reception.', null, $rich ? 9 : 0);
        $qr('breakfast', 'Breakfast times', 'Breakfast is served in the Terrace Restaurant from 06:30 to 10:30 (weekends until 11:00), {guest}.', 'fnb', $rich ? 6 : 0);
        $qr('taxi', 'Taxi / airport transfer', 'Certainly, {guest}. Our concierge can book an airport transfer or taxi – please tell us the date, time and number of passengers, and we will confirm the fare.', 'concierge', $rich ? 5 : 0);
        $qLate = $qr('late', 'Late check-out', 'We are happy to extend your check-out, {guest}. Late check-out until 14:00 is subject to availability and charged at half a night\'s rate; after 18:00 a full night applies. Shall I confirm it for room {room}?', 'front_desk', $rich ? 4 : 0);
        $qr('pool', 'Pool & gym hours', 'The pool is open 07:00–20:00 and the gym 24 hours with your room key, {guest}. Towels are at the pool bar.', null, $rich ? 3 : 0);
        $qThanks = $qr('thanks', 'Thank you / closing', 'You are very welcome, {guest}! If there is anything else we can do, just write here. – {agent}, {hotel}', null, $rich ? 11 : 0);
        $qr('directions', 'Directions to the hotel', 'We are on Liberation Road, 10 minutes from Kotoka International Airport. Our airport shuttle runs every hour on the hour from the arrivals hall.', 'concierge', $rich ? 2 : 0);

        $rule = fn (int $sort, string $n, string $k, ?string $d, bool $urg, ?string $cat, ?string $item, ?string $reply, int $hits = 0) => Models\InboxRule::create(['sort' => $sort, 'name' => $n, 'keywords' => $k, 'department' => $d, 'urgent' => $urg,
            'request_category' => $cat, 'request_item' => $item, 'reply' => $reply, 'is_active' => true, 'hits' => $rich ? $hits : 0, 'last_hit_at' => $rich && $hits ? $real->copy()->subHours($hits) : null]);
        $rTowel = $rule(10, 'Towels & amenities', 'towel, towels, pillow, pillows, blanket, toiletries, soap, shampoo, water bottles', 'housekeeping', false, 'amenity', 'Extra towels', 'Of course, {guest} – housekeeping will bring them to room {room} within 15 minutes.', 7);
        $rule(20, 'Something broken', 'air con, aircon, ac, air conditioning, not cooling, not working, broken, leak, leaking, no hot water, power cut', 'engineering', true, null, null, null, 4);
        $rWifi = $rule(30, 'Wi-Fi questions', 'wifi, wi-fi, internet, wifi password, network', null, false, null, null, 'Hello {guest}, the Wi-Fi network is "{wifi_name}" and the password is {wifi_password}. If it does not connect, reply here and we will send a technician.', 9);
        $rule(40, 'Transport', 'airport, taxi, pickup, pick up, drop, transfer, shuttle, uber', 'concierge', false, null, null, null, 5);
        $rule(50, 'Rates & bookings', 'rate, rates, price, prices, availability, available, book, booking, reservation, discount', 'reservations', false, null, null, null, 3);
        $rule(60, 'Complaints', 'complaint, complain, manager, unhappy, disappointed, terrible, dirty, rude, refund', 'guest_relations', true, null, null, null, 1);
        if (! $rich) {
            return;
        }

        $stayOf = fn (string $room) => Models\ReservationRoom::with('guest', 'reservation.guest')->where('status', 'checked_in')->whereHas('room', fn ($r) => $r->where('room_no', $room))->first();
        $num = fn ($g) => \App\Services\MessageService::normalise($g?->phone);
        // one conversation: $lines = [[minutes ago, direction, body, user?, extra attrs], ...]
        $conv = function (array $a, array $lines) use ($real) {
            $c = Models\Conversation::create($a + ['number' => \App\Support\Numbering::next('conversation'), 'priority' => 'normal', 'unread' => 0, 'csat_asked' => false]);
            $waiting = null; $last = null; $lastIn = null; $first = null; $unread = 0;
            foreach ($lines as $l) {
                [$ago, $dir, $body] = $l;
                $at = $real->copy()->subMinutes($ago);
                $first ??= $at;
                $resp = null;
                if ($dir === 'in') {
                    $waiting ??= $at; $lastIn = $at; $unread++;
                } elseif ($dir === 'out') {
                    if ($waiting && empty($l[4]['auto'])) {
                        $resp = (int) $waiting->diffInSeconds($at, true); $waiting = null; $unread = 0;
                    } elseif ($waiting && ! empty($l[4]['auto'])) {
                        // an automatic answer does not stop the clock unless it fully answered (rule reply)
                        if (! empty($l[4]['rule_id'])) {
                            $waiting = null; $unread = 0;
                        }
                    }
                }
                Models\ConversationMessage::create(array_merge(['conversation_id' => $c->id, 'direction' => $dir, 'body' => $body, 'channel' => $c->channel, 'user_id' => $l[3] ?? null,
                    'status' => $dir === 'in' ? 'received' : ($dir === 'out' ? ($c->channel === 'portal' ? 'read' : 'delivered') : 'sent'), 'auto' => false,
                    'response_sec' => $resp, 'read_at' => $dir === 'out' && $c->channel === 'portal' ? $at->copy()->addMinute() : null,
                    'provider_id' => $dir === 'out' && in_array($c->channel, ['sms', 'whatsapp'], true) ? 'SM'.substr(md5($c->id.$ago.$body), 0, 30) : null, 'created_at' => $at, 'updated_at' => $at], $l[4] ?? []));
                if (in_array($dir, ['in', 'out'], true)) {
                    $last = $at;
                }
            }
            $open = in_array($c->status, ['open', 'pending'], true);
            $c->forceFill(['awaiting_since' => $open ? $waiting : null, 'unread' => $open && $waiting ? $unread : 0, 'last_message_at' => $last, 'last_inbound_at' => $lastIn,
                'opened_at' => $first, 'created_at' => $first, 'updated_at' => $last])->save();

            return $c;
        };
        $name = fn ($s) => $s?->guest ?? $s?->reservation?->guest;

        // 1. VIP (107) – airport transfer on WhatsApp, guest just wrote back (inside the target)
        if (($s = $stayOf('107')) && ($g = $name($s)) && ($n = $num($g))) {
            $conv(['channel' => 'whatsapp', 'handle' => $n, 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'open', 'department' => 'concierge', 'priority' => 'high', 'assigned_to' => $fom->id, 'subject' => 'Airport transfer'], [
                [52, 'in', 'Good afternoon. I fly out on Saturday at 18:40 from Kotoka. Can the hotel arrange a car to the airport?'],
                [47, 'out', 'Good afternoon Mr Asante – with pleasure. For an 18:40 international flight we suggest leaving the hotel at 15:30. A Mercedes E-Class is GH₵ 450, charged to your room. Shall I book it?', $fom->id],
                [45, 'note', 'VIP – FOM handling personally. Check if the complimentary transfer applies to his Platinum rate.', $fom->id],
                [6, 'in', 'Yes please, 15:30 is fine. Two suitcases and one golf bag.'],
            ]);
        }
        // 2. 105 – AC fault by SMS, past the target, urgent, with an internal note
        if (($s = $stayOf('105')) && ($g = $name($s)) && ($n = $num($g))) {
            $conv(['channel' => 'sms', 'handle' => $n, 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'open', 'department' => 'engineering', 'priority' => 'high', 'assigned_to' => null], [
                [24, 'in', 'Hello, the air con in room 105 is not cooling at all. It is very hot.'],
                [19, 'note', 'Called engineering on radio – Yaw is finishing a job on the 3rd floor. Offer a fan in the meantime?', $fd->id],
            ]);
        }
        // 3. unknown number – rates enquiry, unassigned, past the target
        $conv(['channel' => 'whatsapp', 'handle' => '+233245550142', 'contact_name' => 'Akosua B.', 'status' => 'open', 'department' => 'reservations', 'assigned_to' => null, 'subject' => 'Rates enquiry'], [
            [38, 'in', 'Hi, what are your rates for a deluxe room for 3 nights from 12 October, 2 adults? Is breakfast included?'],
        ]);
        // 4. 104 – towels via the portal, answered by the rule, then closed and rated
        if (($s = $stayOf('104')) && ($g = $name($s))) {
            $c = $conv(['channel' => 'portal', 'handle' => 'stay:'.$s->id, 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'closed', 'department' => 'housekeeping', 'assigned_to' => $fd->id,
                'closed_by' => $fd->id, 'csat_asked' => true, 'csat' => 5], [
                [310, 'in', 'Could we have two more towels please?'],
                [310, 'out', 'Of course, Ms Clarke – housekeeping will bring them to room 104 within 15 minutes.', null, ['auto' => true, 'rule_id' => $rTowel->id]],
                [301, 'out', 'Housekeeping has delivered the towels. Anything else we can do for you?', $fd->id],
                [296, 'in', 'That was quick, thank you!'],
                [294, 'out', 'You are very welcome, Ms Clarke! If there is anything else we can do, just write here.', $fd->id, ['quick_reply_id' => $qThanks->id]],
                [293, 'system', 'Closed by '.$fd->name],
            ]);
            $c->forceFill(['closed_at' => $real->copy()->subMinutes(293)])->save();
        }
        // 5. 108 – Wi-Fi question answered automatically, waiting for the guest
        if (($s = $stayOf('108')) && ($g = $name($s))) {
            $conv(['channel' => 'portal', 'handle' => 'stay:'.$s->id, 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'pending', 'department' => 'front_desk', 'assigned_to' => null], [
                [95, 'in', 'What is the wifi password?'],
                [95, 'out', 'Hello Mr Tanaka, the Wi-Fi network is "'.setting('portal_wifi_name', 'Nowills-Guest').'" and the password is '.setting('portal_wifi_password', 'welcome2026').'. If it does not connect, reply here and we will send a technician.', null, ['auto' => true, 'rule_id' => $rWifi->id]],
            ]);
        }
        // 6. 101 – late check-out on the portal, offered, waiting for the guest
        if (($s = $stayOf('101')) && ($g = $name($s))) {
            $conv(['channel' => 'portal', 'handle' => 'stay:'.$s->id, 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'pending', 'department' => 'front_desk', 'assigned_to' => $fd->id, 'subject' => 'Late check-out'], [
                [180, 'in', 'Hi, my flight is late tonight. Is it possible to keep the room until 6 pm?'],
                [171, 'out', 'We are happy to extend your check-out, Mr Verma. Late check-out until 14:00 is subject to availability and charged at half a night\'s rate; after 18:00 a full night applies. Shall I confirm it for room 101?', $fd->id, ['quick_reply_id' => $qLate->id]],
                [150, 'in', 'OK, please confirm until 18:00.'],
                [141, 'out', 'Confirmed – room 101 is yours until 18:00 today; the late check-out charge is on your bill. Safe travels!', $fd->id],
            ]);
        }
        // 7. 103 – dinner table on WhatsApp, snoozed until the restaurant confirms
        if (($s = $stayOf('103')) && ($g = $name($s)) && ($n = $num($g))) {
            $conv(['channel' => 'whatsapp', 'handle' => $n, 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'snoozed', 'snoozed_until' => $real->copy()->addMinutes(40), 'department' => 'fnb', 'assigned_to' => $fd->id], [
                [75, 'in', 'Can you reserve a table for 4 at the Terrace Restaurant tonight at 8?'],
                [66, 'out', 'Certainly Mr Mensah – I am checking with the restaurant and will confirm shortly.', $fd->id],
                [65, 'system', 'Snoozed by '.$fd->name.' – back in the inbox at '.$real->copy()->addMinutes(40)->format('H:i')],
            ]);
        }
        // 8. 102 – invoice by e-mail, answered and closed
        if (($s = $stayOf('102')) && ($g = $name($s)) && $g->email) {
            $c = $conv(['channel' => 'email', 'handle' => mb_strtolower($g->email), 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'closed', 'department' => 'front_desk', 'assigned_to' => $fd->id, 'closed_by' => $fd->id, 'subject' => 'Invoice to my company'], [
                [420, 'in', "Dear team,\nplease address my invoice to Kapoor Consulting Ltd, TIN C0012345678, and e-mail it to accounts@kapoor.example when I check out.\nRegards, Sneha"],
                [398, 'out', "Dear Ms Kapoor,\nnoted – your invoice will be issued to Kapoor Consulting Ltd (TIN C0012345678) and sent to accounts@kapoor.example at check-out.\nKind regards, Front Office", $fd->id],
                [397, 'system', 'Closed by '.$fd->name],
            ])->forceFill(['closed_at' => $real->copy()->subMinutes(397)])->save();
        }
        // 9–13. closed, rated conversations with guests who left in the last week (response-time history for the dashboard)
        $topics = [
            ['whatsapp', 'concierge', 'Can you recommend a restaurant for local food near the hotel?', 'We recommend Buka on Liberation Road for jollof and grilled tilapia – 5 minutes by taxi. Shall we reserve?', 4, 7],
            ['portal', 'housekeeping', 'Please could the room be cleaned after 2 pm, we are resting.', 'Of course – housekeeping will come after 14:00 today.', 5, 3],
            ['sms', 'front_desk', 'What time is breakfast?', 'Breakfast is served in the Terrace Restaurant from 06:30 to 10:30.', 5, 2],
            ['whatsapp', 'engineering', 'The TV remote is not working.', 'Sorry about that – a new remote is on its way to your room.', 3, 18],
            ['portal', 'fnb', 'Can I order room service after midnight?', 'Yes – the night menu is available 23:00–06:00; dial 5 from your room.', 4, 11],
        ];
        $inHouse = Models\ReservationRoom::with('reservation')->where('status', 'checked_in')->get()->map(fn ($s) => $s->guest_id ?? $s->reservation?->guest_id)->filter()->map(fn ($v) => (int) $v)->all();
        $gone = Models\ReservationRoom::with('guest', 'reservation.guest')->where('status', 'checked_out')->where('checked_out_at', '>=', $real->copy()->subDays(7))
            ->whereHas('room', fn ($r) => $r->whereNotIn('room_no', ['106', '201']))->orderBy('id')->get()
            ->filter(fn ($s) => ($g = $name($s)) && ! in_array($g->last_name, ['Menon', 'Sheikh'], true))->unique(fn ($s) => $name($s)->id)->values();
        foreach ($topics as $i => [$ch, $dept, $q, $a, $csat, $mins]) {
            if (! ($s = $gone[$i] ?? null)) {
                break;
            }
            $g = $name($s);
            if (in_array($g->id, $inHouse, true)) {
                $ch = 'portal'; // back in house: keep the old chat on that stay's portal thread so it never captures the guest's new messages
            }
            $handle = $ch === 'portal' ? 'stay:'.$s->id : $num($g);
            if (! $handle) {
                continue;
            }
            $base = max(60 * 24, (int) $s->checked_out_at->diffInMinutes($real, true) + 60 * 20); // during the stay, the day before check-out
            $agent = $i % 2 ? $fd : $fom;
            $conv(['channel' => $ch, 'handle' => $handle, 'guest_id' => $g->id, 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'closed', 'department' => $dept, 'assigned_to' => $agent->id,
                'closed_by' => $agent->id, 'csat_asked' => true, 'csat' => $csat], [
                [$base, 'in', $q],
                [$base - $mins, 'out', $a, $agent->id],
                [$base - $mins - 4, 'in', 'Thank you!'],
                [$base - $mins - 5, 'system', 'Closed by '.$agent->name],
            ])->forceFill(['closed_at' => $real->copy()->subMinutes($base - $mins - 5)])->save();
        }
        // 14. yesterday's check-out reminder to today's departures (portal chat)
        $deps = collect(['101', '102', '103'])->map($stayOf)->filter();
        if ($deps->isNotEmpty()) {
            $at = $real->copy()->subDay()->setTime(19, 0);
            $b = Models\InboxBroadcast::create(['number' => \App\Support\Numbering::next('inbox_broadcast'), 'title' => 'Check-out reminder', 'audience' => 'departures', 'channel' => 'portal', 'recipients' => $deps->count(), 'sent' => $deps->count(),
                'failed' => 0, 'skipped' => 0, 'created_by' => $fom->id, 'body' => 'Dear {guest}, we hope you enjoyed your stay. Check-out tomorrow is at {checkout_time} – you can settle your bill and check out from the guest portal or the lobby kiosk. Need a late check-out or an airport car? Just reply here.',
                'created_at' => $at, 'updated_at' => $at]);
            foreach ($deps as $s) {
                $g = $name($s);
                $c = \App\Services\InboxService::portalThread($s) ?? Models\Conversation::create(['number' => \App\Support\Numbering::next('conversation'), 'channel' => 'portal', 'handle' => 'stay:'.$s->id, 'guest_id' => $g?->id,
                    'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id, 'status' => 'pending', 'department' => 'front_desk', 'priority' => 'normal', 'unread' => 0, 'last_message_at' => $at, 'opened_at' => $at, 'created_at' => $at]);
                $body = \App\Services\InboxService::render($b->body, \App\Services\InboxService::vars($c, $fom, $s, $g));
                Models\ConversationMessage::create(['conversation_id' => $c->id, 'direction' => 'out', 'body' => $body, 'channel' => 'portal', 'user_id' => $fom->id, 'status' => 'read', 'broadcast_id' => $b->id,
                    'read_at' => $at->copy()->addHours(2), 'created_at' => $at, 'updated_at' => $at]);
                if (! $c->last_message_at || $c->last_message_at->lt($at)) {
                    $c->forceFill(['last_message_at' => $at])->save();
                }
            }
        }
        \App\Services\InboxService::flush();
    }

}
