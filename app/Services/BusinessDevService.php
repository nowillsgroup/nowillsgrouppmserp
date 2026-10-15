<?php

namespace App\Services;

use App\Models\BdBenchmark;
use App\Models\BdCompetitor;
use App\Models\BdInitiative;
use App\Models\BdMilestone;
use App\Models\BdPartner;
use App\Models\BdRateShop;
use App\Models\BdRateShopLine;
use App\Models\BdResult;
use App\Models\BusinessSource;
use App\Models\Company;
use App\Models\PromoCode;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v6.3 Business development: competitive set (profiles, rate shops, monthly benchmarks → MPI / ARI / RGI), market mix
 * analysis from the booking data, strategic partnerships with production tracking, and new-business initiatives through a
 * four-eyes stage gate with a business case (payback, NPV, ROI), milestones and actual results.
 */
class BusinessDevService
{
    protected static array $memo = [];

    public const DEFAULTS = ['bd_discount_rate' => '20', 'bd_shop_stale_days' => '7', 'bd_partner_notice_days' => '60', 'bd_benchmark_room_type' => ''];

    public const SEGMENTS = ['group' => 'Groups', 'corporate' => 'Corporate / negotiated', 'ota' => 'OTA', 'direct' => 'Direct (phone, walk-in, web)', 'travel_agent' => 'Travel trade', 'other' => 'Other / unassigned'];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'discount_rate' => (float) setting('bd_discount_rate', '20'),
            'shop_stale_days' => (int) setting('bd_shop_stale_days', '7'),
            'partner_notice_days' => (int) setting('bd_partner_notice_days', '60'),
            'benchmark_room_type' => (int) setting('bd_benchmark_room_type', '') ?: null,
        ];
    }

    public static function saveSettings(array $d): void
    {
        $rate = $d['discount_rate'] ?? null;
        self::need(is_numeric($rate) && $rate >= 0 && $rate <= 60, 'The discount rate is between 0 and 60 %.');
        $stale = (int) ($d['shop_stale_days'] ?? 0);
        self::need($stale >= 1 && $stale <= 60, 'Rate shops go stale after 1 to 60 days.');
        $notice = (int) ($d['partner_notice_days'] ?? 0);
        self::need($notice >= 7 && $notice <= 180, 'The partnership renewal notice is between 7 and 180 days.');
        $rt = ($d['benchmark_room_type'] ?? '') === '' ? '' : (string) (int) $d['benchmark_room_type'];
        self::need($rt === '' || RoomType::whereKey((int) $rt)->exists(), 'Choose a room type of this hotel.');
        foreach (['bd_discount_rate' => (string) round((float) $rate, 2), 'bd_shop_stale_days' => (string) $stale, 'bd_partner_notice_days' => (string) $notice, 'bd_benchmark_room_type' => $rt] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('bizdev', 'settings', 'Business development settings updated');
    }

    // ------------------------------------------------------------------ helpers

    public static function canEdit(?User $u): bool
    {
        return $u && ($u->hasPermission('bd.edit') || $u->hasPermission('bd.manage'));
    }

    public static function canApprove(?User $u): bool
    {
        return $u && ($u->hasPermission('bd.approve') || $u->hasPermission('bd.manage'));
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('bd.manage');
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    protected static function money($v, string $label, bool $allowBlank = true): ?float
    {
        if ($v === null || $v === '') {
            self::need($allowBlank, "$label is required.");

            return null;
        }
        self::need(is_numeric($v) && (float) $v >= 0 && (float) $v < 1e12, "$label must be an amount of 0 or more.");

        return round((float) $v, 2);
    }

    protected static function date($v, string $label, bool $required = false): ?Carbon
    {
        if ($v === null || $v === '') {
            self::need(! $required, "$label is required.");

            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException("$label is not a valid date.");
        }
    }

    protected static function owner($id, User $u): ?int
    {
        if (! $id) {
            return null;
        }
        self::need(User::where('hotel_id', hotel()->id)->where('id', (int) $id)->where('is_active', true)->exists(), 'Choose an active user of this hotel.');

        return (int) $id;
    }

    protected static function text($v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** users who may own BD work */
    public static function people(): Collection
    {
        return User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => self::canEdit($u) || self::canApprove($u))->values();
    }

    // ------------------------------------------------------------------ competitive set

    public static function saveCompetitor(array $d, ?BdCompetitor $c, User $u): BdCompetitor
    {
        self::need(self::canManage($u), 'Only a business development manager can change the competitive set.');
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '' && mb_strlen($name) <= 120, 'Enter the hotel name.');
        self::need(! BdCompetitor::where('name', $name)->when($c, fn ($q) => $q->where('id', '!=', $c->id))->exists(), 'That hotel is already in the competitive set.');
        $stars = ($d['stars'] ?? '') === '' ? null : (int) $d['stars'];
        self::need($stars === null || ($stars >= 1 && $stars <= 5), 'Stars are 1 to 5.');
        $rooms = ($d['rooms'] ?? '') === '' ? null : $d['rooms'];
        self::need($rooms === null || (ctype_digit((string) $rooms) && $rooms >= 1 && $rooms <= 5000), 'Rooms must be a whole number (1–5,000).');
        $pos = ($d['positioning'] ?? '') ?: null;
        self::need(! $pos || isset(BdCompetitor::POSITIONING[$pos]), 'Choose the positioning.');
        $km = ($d['distance_km'] ?? '') === '' ? null : $d['distance_km'];
        self::need($km === null || (is_numeric($km) && $km >= 0 && $km <= 500), 'The distance is 0–500 km.');
        $web = self::text($d['website'] ?? null, 255);
        self::need(! $web || (bool) preg_match('#^https?://\S+$#i', $web), 'The website must start with http:// or https://.');
        $active = array_key_exists('is_active', $d) ? (bool) $d['is_active'] : ($c->is_active ?? true);
        if ($active && (! $c || ! $c->is_active)) {
            self::need(BdCompetitor::where('is_active', true)->count() < 10, 'A competitive set has at most 10 active hotels – deactivate one first.');
        }
        $data = ['name' => $name, 'stars' => $stars, 'rooms' => $rooms === null ? null : (int) $rooms, 'positioning' => $pos, 'location' => self::text($d['location'] ?? null, 120),
            'distance_km' => $km, 'website' => $web, 'strengths' => self::text($d['strengths'] ?? null, 2000), 'weaknesses' => self::text($d['weaknesses'] ?? null, 2000),
            'is_active' => $active, 'sort' => (int) ($d['sort'] ?? ($c->sort ?? BdCompetitor::max('sort') + 1))];
        $c ? $c->update($data) : $c = BdCompetitor::create($data);
        ActivityLogger::log('bizdev', 'competitor', "Competitive set: {$c->name} saved", $c);

        return $c;
    }

    public static function competitors(bool $activeOnly = true): Collection
    {
        return BdCompetitor::when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('sort')->orderBy('name')->get();
    }

    public static function benchmarkType(): ?RoomType
    {
        $id = self::cfg()['benchmark_room_type'];

        return ($id ? RoomType::find($id) : null) ?? RoomType::orderBy('base_rate')->first();
    }

    /** our own public rate for a stay date (benchmark room type, after revenue management) */
    public static function ownRate(Carbon $date): ?float
    {
        $t = self::benchmarkType();
        if (! $t) {
            return null;
        }

        return rescue(fn () => (float) RateService::quote($t, $date->copy(), $date->copy()->addDay())['nights'][0]['rate'], null, false);
    }

    public static function saveShop(array $d, User $u): BdRateShop
    {
        self::need(self::canEdit($u));
        $date = self::date($d['stay_date'] ?? null, 'The stay date', true);
        self::need($date->gte(bdate()->copy()->startOfDay()), 'Rate shops are for today or a future stay date.');
        self::need($date->lte(bdate()->copy()->addYear()), 'Shop up to a year ahead.');
        $ch = (string) ($d['channel'] ?? '');
        self::need(isset(BdRateShop::CHANNELS[$ch]), 'Choose where the rates were shopped.');
        $ownSold = ! empty($d['own_sold_out']);
        $own = $ownSold ? null : (($d['own_rate'] ?? '') === '' ? self::ownRate($date) : self::money($d['own_rate'], 'Our rate'));
        $comps = self::competitors()->keyBy('id');
        $rates = (array) ($d['rates'] ?? []);
        $sold = (array) ($d['sold_out'] ?? []);
        foreach (array_keys($rates + $sold) as $id) {
            self::need($comps->has((int) $id), 'Rates can only be entered for active hotels of the competitive set.');
        }
        $lines = [];
        foreach ($comps as $id => $c) {
            $isSold = ! empty($sold[$id]);
            $r = $rates[$id] ?? '';
            if ($isSold) {
                $lines[$id] = ['rate' => null, 'sold_out' => true];
            } elseif ($r !== '' && $r !== null) {
                self::need(is_numeric($r) && $r > 0 && $r < 1e8, "Enter a valid rate for {$c->name}.");
                $lines[$id] = ['rate' => round((float) $r, 2), 'sold_out' => false];
            }
        }
        self::need(count($lines) > 0, 'Enter at least one competitor rate (or mark it sold out).');

        return DB::transaction(function () use ($date, $ch, $own, $ownSold, $lines, $u, $d) {
            $shop = date_upsert(BdRateShop::class, ['stay_date' => $date->toDateString(), 'channel' => $ch],
                ['own_rate' => $own, 'own_sold_out' => $ownSold, 'shopped_on' => bdate()->toDateString(), 'shopped_by' => $u->id, 'note' => self::text($d['note'] ?? null, 255)]);
            BdRateShopLine::where('shop_id', $shop->id)->delete();
            foreach ($lines as $id => $l) {
                BdRateShopLine::create($l + ['shop_id' => $shop->id, 'competitor_id' => $id]);
            }
            ActivityLogger::log('bizdev', 'rateshop', "Rate shop for {$date->format('d M Y')} ({$ch}) saved", $shop);

            return $shop->load('lines');
        });
    }

    public static function deleteShop(BdRateShop $s, User $u): void
    {
        self::need(self::canEdit($u));
        $s->delete();
    }

    /** date => shop (with lines) for a window, one channel */
    public static function shopGrid(Carbon $from, int $days, string $channel): Collection
    {
        return BdRateShop::with('lines')->where('channel', $channel)->whereDate('stay_date', '>=', $from->toDateString())->whereDate('stay_date', '<=', $from->copy()->addDays($days - 1)->toDateString())
            ->get()->keyBy(fn ($s) => $s->stay_date->toDateString());
    }

    /** forward price position: our average rate vs the comp-set average over shops in the window (100 = at par) */
    public static function ratePosition(Carbon $from, int $days): array
    {
        $shops = BdRateShop::with('lines')->whereDate('stay_date', '>=', $from->toDateString())->whereDate('stay_date', '<=', $from->copy()->addDays($days - 1)->toDateString())->get()
            ->filter(fn ($s) => $s->own_rate !== null && $s->compAverage() !== null);
        if ($shops->isEmpty()) {
            return ['shops' => 0, 'own' => null, 'comp' => null, 'index' => null, 'cheaper' => 0, 'dearer' => 0];
        }
        $own = $shops->avg(fn ($s) => (float) $s->own_rate);
        $comp = $shops->avg(fn ($s) => $s->compAverage());

        return ['shops' => $shops->count(), 'own' => round($own, 2), 'comp' => round($comp, 2), 'index' => $comp > 0 ? round(100 * $own / $comp, 1) : null,
            'cheaper' => $shops->filter(fn ($s) => (float) $s->own_rate < $s->compAverage())->count(), 'dearer' => $shops->filter(fn ($s) => (float) $s->own_rate > $s->compAverage())->count()];
    }

    public static function lastShop(): ?Carbon
    {
        $d = BdRateShop::max('shopped_on');

        return $d ? Carbon::parse($d) : null;
    }

    // ------------------------------------------------------------------ benchmarks

    public static function saveBenchmark(array $d, User $u): BdBenchmark
    {
        self::need(self::canEdit($u));
        self::need((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($d['month'] ?? '')), 'Choose the month.');
        $m = Carbon::parse($d['month'].'-01')->startOfDay();
        self::need($m->lt(bdate()->copy()->startOfMonth()), 'Benchmarks are for completed months.');
        $occ = $d['comp_occupancy'] ?? null;
        self::need(is_numeric($occ) && $occ > 0 && $occ <= 100, 'Comp-set occupancy is a percentage above 0 and up to 100.');
        $adr = $d['comp_adr'] ?? null;
        self::need(is_numeric($adr) && $adr > 0 && $adr < 1e8, 'Enter the comp-set ADR.');
        $src = (string) ($d['source'] ?? 'manual');
        self::need(isset(BdBenchmark::SOURCES[$src]), 'Choose the source.');
        $oo = ($d['own_occupancy'] ?? '') === '' ? null : $d['own_occupancy'];
        $oa = ($d['own_adr'] ?? '') === '' ? null : $d['own_adr'];
        self::need(($oo === null) === ($oa === null), 'Give both our occupancy and our ADR from the report, or neither (the PMS figures are then used).');
        self::need($oo === null || (is_numeric($oo) && $oo >= 0 && $oo <= 100), 'Our occupancy is a percentage from 0 to 100.');
        self::need($oa === null || (is_numeric($oa) && $oa >= 0 && $oa < 1e8), 'Enter a valid ADR for us.');
        $b = date_upsert(BdBenchmark::class, ['month' => $m->toDateString()], ['comp_occupancy' => round((float) $occ, 2), 'comp_adr' => round((float) $adr, 2), 'source' => $src,
            'own_occupancy' => $oo === null ? null : round((float) $oo, 2), 'own_adr' => $oa === null ? null : round((float) $oa, 2),
            'note' => self::text($d['note'] ?? null, 255), 'entered_by' => $u->id]);
        ActivityLogger::log('bizdev', 'benchmark', "Comp-set benchmark for {$m->format('M Y')} saved", $b);
        self::flush();

        return $b;
    }

    /** our own rooms statistics for a calendar month (closed days, ledger room revenue) */
    public static function ownStats(Carbon $month): array
    {
        $key = 'own'.Tenant::id().$month->format('Ym');

        return self::$memo[$key] ??= (function () use ($month) {
            $s = BudgetService::actualStats(BudgetService::fyFor($month->format('Y-m').'-01'))[BudgetService::monthIndex($month->format('Y-m').'-01')] ?? null;

            return $s ?? ['available' => 0, 'sold' => 0, 'occupancy' => 0.0, 'adr' => 0.0, 'revpar' => 0.0, 'revenue' => 0.0];
        })();
    }

    /** own vs comp set for a month: occupancy / ADR / RevPAR and the MPI / ARI / RGI indices (100 = fair share) */
    public static function indices(Carbon $month, ?BdBenchmark $b = null): array
    {
        $b ??= BdBenchmark::whereDate('month', $month->copy()->startOfMonth()->toDateString())->first();
        $own = self::ownStats($month) + ['source' => 'pms'];
        if ($b && $b->hasOwn()) {   // the report's "My Property" line wins – same definitions as the comp-set figures
            $own = ['available' => $own['available'], 'sold' => (float) $b->own_occupancy > 0 ? max(1, $own['sold']) : 0, 'occupancy' => (float) $b->own_occupancy, 'adr' => (float) $b->own_adr,
                'revpar' => round((float) $b->own_occupancy * (float) $b->own_adr / 100, 2), 'revenue' => $own['revenue'], 'source' => 'report'];
        }
        $out = ['month' => $month->copy()->startOfMonth(), 'own' => $own, 'benchmark' => $b, 'comp' => null, 'mpi' => null, 'ari' => null, 'rgi' => null];
        if (! $b || (! $own['available'] && $own['source'] === 'pms')) {
            return $out;
        }
        $comp = ['occupancy' => (float) $b->comp_occupancy, 'adr' => (float) $b->comp_adr, 'revpar' => $b->compRevpar()];
        $out['comp'] = $comp;
        $out['mpi'] = round(100 * $own['occupancy'] / $comp['occupancy'], 1);
        $out['ari'] = $own['sold'] ? round(100 * $own['adr'] / $comp['adr'], 1) : null;
        $out['rgi'] = $comp['revpar'] > 0 ? round(100 * $own['revpar'] / $comp['revpar'], 1) : null;

        return $out;
    }

    /** last n completed months of indices, oldest first */
    public static function benchmarkSeries(int $n = 6): Collection
    {
        $end = bdate()->copy()->startOfMonth()->subMonthNoOverflow();
        $bm = BdBenchmark::whereBetween('month', [$end->copy()->subMonthsNoOverflow($n - 1)->toDateString(), $end->toDateString()])->get()->keyBy(fn ($b) => $b->month->format('Y-m'));

        return collect(range($n - 1, 0))->map(fn ($k) => self::indices($m = $end->copy()->subMonthsNoOverflow($k), $bm[$m->format('Y-m')] ?? null));
    }

    // ------------------------------------------------------------------ market mix

    /**
     * Stays arriving in the period by guest nationality, business source or segment: bookings, room nights, room revenue
     * (rate × nights, cancelled excluded) and the same period last year.
     */
    public static function marketMix(Carbon $from, Carbon $to, string $by = 'nationality'): Collection
    {
        $key = match ($by) {
            'source' => "COALESCE(b.name, 'Not recorded')",
            'segment' => "CASE WHEN r.booking_type = 'group' THEN 'group' WHEN r.company_id IS NOT NULL OR b.type = 'corporate' THEN 'corporate' WHEN b.type = 'ota' OR r.ota_channel_id IS NOT NULL THEN 'ota' WHEN b.type IN ('direct', 'walk_in', 'booking_engine') THEN 'direct' WHEN b.type = 'travel_agent' THEN 'travel_agent' ELSE 'other' END",
            default => "COALESCE(NULLIF(TRIM(g.nationality), ''), 'Not recorded')",
        };
        $q = fn (Carbon $a, Carbon $z) => DB::table('reservation_rooms as rr')->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->leftJoin('guests as g', 'g.id', '=', 'r.guest_id')->leftJoin('business_sources as b', 'b.id', '=', 'r.business_source_id')
            ->where('r.hotel_id', hotel()->id)->where('r.status', '!=', 'cancelled')->where('rr.status', '!=', 'cancelled')
            ->whereBetween('r.arrival_date', [$a->toDateString(), $z->toDateString()])
            ->selectRaw("$key k, COUNT(DISTINCT r.id) bookings, COALESCE(SUM(DATEDIFF(rr.departure_date, rr.arrival_date)),0) nights, COALESCE(SUM(rr.rate * DATEDIFF(rr.departure_date, rr.arrival_date)),0) revenue")
            ->groupBy('k')->get()->keyBy('k');
        $now = $q($from, $to);
        $ly = $q($from->copy()->subYearNoOverflow(), $to->copy()->subYearNoOverflow());
        $total = max(0.01, (float) $now->sum('revenue'));
        $keys = $now->keys()->merge($ly->keys())->unique();

        return $keys->map(function ($k) use ($now, $ly, $total, $by) {
            $n = $now[$k] ?? null;
            $l = $ly[$k] ?? null;
            $rev = round((float) ($n->revenue ?? 0), 2);
            $lrev = round((float) ($l->revenue ?? 0), 2);

            return ['key' => $k, 'label' => $by === 'segment' ? (self::SEGMENTS[$k] ?? $k) : $k, 'bookings' => (int) ($n->bookings ?? 0), 'nights' => (int) ($n->nights ?? 0), 'revenue' => $rev,
                'adr' => ($n->nights ?? 0) ? round($rev / $n->nights, 2) : null, 'share' => round(100 * $rev / $total, 1),
                'ly_nights' => (int) ($l->nights ?? 0), 'ly_revenue' => $lrev, 'growth' => $lrev > 0 ? round(100 * ($rev - $lrev) / $lrev, 1) : null];
        })->sortByDesc('revenue')->values();
    }

    // ------------------------------------------------------------------ partnerships

    public static function savePartner(array $d, ?BdPartner $p, User $u): BdPartner
    {
        self::need(self::canEdit($u));
        self::need(! $p || $p->status !== 'terminated', 'Terminated partnerships cannot be changed.');
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '' && mb_strlen($name) <= 150, 'Enter the partner’s name.');
        $type = (string) ($d['type'] ?? '');
        self::need(isset(BdPartner::TYPES[$type]), 'Choose the partner type.');
        $ag = (string) ($d['agreement'] ?? '');
        self::need(isset(BdPartner::AGREEMENTS[$ag]), 'Choose the kind of agreement.');
        $pct = ($d['commission_pct'] ?? '') === '' ? null : $d['commission_pct'];
        self::need($pct === null || (is_numeric($pct) && $pct >= 0 && $pct <= 50), 'Commission is 0–50 %.');
        self::need($ag !== 'commission' || $pct !== null, 'Give the commission % of a commission agreement.');
        $email = trim((string) ($d['email'] ?? ''));
        self::need($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid e-mail address.');
        $start = self::date($d['start_date'] ?? null, 'The start date');
        $end = self::date($d['end_date'] ?? null, 'The end date');
        self::need(! $start || ! $end || $end->gt($start), 'The agreement must end after it starts.');
        $review = self::date($d['review_date'] ?? null, 'The review date');
        $src = ! empty($d['business_source_id']) ? BusinessSource::find($d['business_source_id']) : null;
        self::need(empty($d['business_source_id']) || $src, 'Choose a business source of this hotel.');
        $promo = ! empty($d['promo_code_id']) ? PromoCode::find($d['promo_code_id']) : null;
        self::need(empty($d['promo_code_id']) || $promo, 'Choose a promo code of this hotel.');
        $co = ! empty($d['company_id']) ? Company::find($d['company_id']) : null;
        self::need(empty($d['company_id']) || $co, 'Choose a company of this hotel.');
        foreach (['business_source_id' => [$src, 'business source'], 'promo_code_id' => [$promo, 'promo code'], 'company_id' => [$co, 'company']] as $col => [$obj, $label]) {
            if ($obj && ($other = BdPartner::where($col, $obj->id)->whereNotIn('status', ['terminated', 'expired'])->when($p, fn ($q) => $q->where('id', '!=', $p->id))->first())) {
                throw new RuntimeException("That {$label} already tracks {$other->number} {$other->name} – production would be counted twice.");
            }
        }
        $tn = ($d['target_nights'] ?? '') === '' ? null : $d['target_nights'];
        self::need($tn === null || (ctype_digit((string) $tn) && $tn <= 1000000), 'The room-night target is a whole number.');
        $data = ['name' => $name, 'type' => $type, 'agreement' => $ag, 'commission_pct' => $pct, 'contact_name' => self::text($d['contact_name'] ?? null, 100), 'email' => $email ?: null,
            'phone' => self::text($d['phone'] ?? null, 30), 'terms' => self::text($d['terms'] ?? null, 4000), 'business_source_id' => $src?->id, 'promo_code_id' => $promo?->id, 'company_id' => $co?->id,
            'start_date' => $start, 'end_date' => $end, 'review_date' => $review, 'target_nights' => $tn === null ? null : (int) $tn,
            'target_revenue' => self::money($d['target_revenue'] ?? null, 'The revenue target'), 'owner_id' => self::owner($d['owner_id'] ?? null, $u) ?? $p?->owner_id ?? $u->id,
            'notes' => self::text($d['notes'] ?? null, 4000)];
        if ($p) {
            self::need($p->status !== 'active' || ($start && $end), 'An active partnership needs start and end dates.');
            $p->update($data);
        } else {
            $p = BdPartner::create($data + ['number' => Numbering::next('bd_partner'), 'status' => 'prospect']);
        }
        ActivityLogger::log('bizdev', 'partner', "Partnership {$p->number} {$p->name} saved", $p);
        self::flush();

        return $p;
    }

    public static function setPartnerStatus(BdPartner $p, string $to, array $d, User $u): void
    {
        self::need(self::canEdit($u));
        $allowed = ['prospect' => ['negotiating', 'active', 'terminated'], 'negotiating' => ['prospect', 'active', 'terminated'], 'active' => ['expired', 'terminated'],
            'expired' => ['active', 'terminated'], 'terminated' => []][$p->status] ?? [];
        self::need(in_array($to, $allowed, true), 'A partnership that is '.strtolower($p->statusLabel()).' cannot become '.strtolower(BdPartner::STATUSES[$to][0] ?? $to).'.');
        $upd = ['status' => $to];
        if ($to === 'active') {
            if ($p->status === 'expired') { // renewal
                $end = self::date($d['end_date'] ?? null, 'The new end date', true);
                self::need($end->gt(bdate()), 'The renewed agreement must end in the future.');
                $upd['end_date'] = $end;
                $upd['start_date'] = $p->start_date ?? bdate()->copy();
            } else {
                self::need($p->start_date && $p->end_date, 'Record the agreement’s start and end dates first.');
                self::need($p->end_date->gt(bdate()), 'The agreement has already ended – correct the end date.');
            }
            self::need(self::canApprove($u) || self::canManage($u), 'Signing a partnership needs a business development approver.');
        }
        if ($to === 'terminated') {
            $why = trim((string) ($d['reason'] ?? ''));
            self::need($why !== '', 'Give the reason for ending the partnership.');
            $upd['end_reason'] = mb_substr($why, 0, 255);
            if ($p->status === 'active' && (! $p->end_date || $p->end_date->gt(bdate()))) {
                $upd['end_date'] = bdate()->copy();
            }
        }
        $p->update($upd);
        ActivityLogger::log('bizdev', 'partner', "Partnership {$p->number} is now {$to}", $p);
        self::flush();
    }

    /** stays credited to a partner: its business source, promo code or company */
    public static function partnerQuery(BdPartner $p)
    {
        return Reservation::where(function ($q) use ($p) {
            $q->whereRaw('1 = 0');
            if ($p->business_source_id) {
                $q->orWhere('business_source_id', $p->business_source_id);
            }
            if ($p->promo_code_id) {
                $q->orWhere('promo_code_id', $p->promo_code_id);
            }
            if ($p->company_id) {
                $q->orWhere('company_id', $p->company_id);
            }
        });
    }

    public static function partnerProduction(BdPartner $p, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= bdate()->copy()->startOfYear();
        $to ??= bdate()->copy()->endOfYear();
        $v = CrmService::bookingValue(self::partnerQuery($p)->whereDate('arrival_date', '>=', $from->toDateString())->whereDate('arrival_date', '<=', $to->toDateString()));
        $commission = $p->agreement === 'commission' && $p->commission_pct ? round($v['revenue'] * (float) $p->commission_pct / 100, 2) : 0.0;

        return $v + ['commission' => $commission, 'net' => round($v['revenue'] - $commission, 2),
            'nights_pct' => $p->target_nights ? round(100 * $v['nights'] / $p->target_nights, 1) : null,
            'revenue_pct' => (float) $p->target_revenue > 0 ? round(100 * $v['revenue'] / (float) $p->target_revenue, 1) : null];
    }

    public static function partnerFlags(BdPartner $p): array
    {
        $f = [];
        $notice = self::cfg()['partner_notice_days'];
        if ($p->status === 'active' && $p->end_date) {
            if ($p->end_date->lt(bdate()->copy()->startOfDay())) {
                $f[] = ['lapsed', 'Past its end date – renew or mark expired', 'red'];
            } elseif ($p->end_date->lte(bdate()->copy()->addDays($notice))) {
                $f[] = ['expiring', 'Ends '.$p->end_date->format('d M Y'), 'amber'];
            }
        }
        if (in_array($p->status, ['active', 'negotiating'], true) && $p->review_date && $p->review_date->lte(bdate())) {
            $f[] = ['review', 'Review due', 'amber'];
        }
        if ($p->status === 'active' && ! $p->hasTracking()) {
            $f[] = ['untracked', 'No source / promo / company to track production', 'slate'];
        }

        return $f;
    }

    // ------------------------------------------------------------------ initiatives

    protected static function mayEdit(BdInitiative $i, User $u): bool
    {
        return self::canManage($u) || (self::canEdit($u) && ($i->owner_id === $u->id || ! $i->owner_id));
    }

    public static function saveInitiative(array $d, ?BdInitiative $i, User $u): BdInitiative
    {
        self::need(self::canEdit($u));
        if ($i) {
            self::need(self::mayEdit($i, $u), 'Only the initiative owner or a business development manager can change it.');
            self::need($i->isOpen(), 'Closed or rejected initiatives cannot be changed.');
            self::need(! $i->awaitingApproval(), 'The business case is with the approver – wait for the decision.');
        }
        $locked = $i && in_array($i->stage, ['approved', 'implementation', 'live'], true); // the approved business case is frozen
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '' && mb_strlen($name) <= 150, 'Name the initiative.');
        $cat = (string) ($d['category'] ?? '');
        self::need(isset(BdInitiative::CATEGORIES[$cat]), 'Choose the category.');
        $partner = ! empty($d['partner_id']) ? BdPartner::find($d['partner_id']) : null;
        self::need(empty($d['partner_id']) || $partner, 'Choose a partnership of this hotel.');
        $launch = self::date($d['launch_date'] ?? null, 'The target launch date');
        $data = ['name' => $name, 'category' => $cat, 'target_market' => self::text($d['target_market'] ?? null, 120), 'description' => self::text($d['description'] ?? null, 5000),
            'partner_id' => $partner?->id, 'launch_date' => $launch, 'owner_id' => self::owner($d['owner_id'] ?? null, $u) ?? $i?->owner_id ?? $u->id];
        if (! $locked) {
            $h = (int) ($d['horizon_years'] ?? 3);
            self::need($h >= 1 && $h <= 10, 'The appraisal horizon is 1 to 10 years.');
            $data += ['investment' => self::money($d['investment'] ?? 0, 'The investment') ?? 0, 'annual_revenue' => self::money($d['annual_revenue'] ?? 0, 'Annual revenue') ?? 0,
                'annual_cost' => self::money($d['annual_cost'] ?? 0, 'Annual running cost') ?? 0, 'horizon_years' => $h];
        } else {
            foreach (['investment', 'annual_revenue', 'annual_cost', 'horizon_years'] as $k) {
                if (array_key_exists($k, $d) && $d[$k] !== '' && (float) $d[$k] != (float) $i->{$k}) {
                    throw new RuntimeException('The business case was approved – its figures can no longer change.');
                }
            }
        }
        if ($i && ! self::canManage($u) && $data['owner_id'] !== $u->id) {
            self::need($data['owner_id'] === $i->owner_id, 'Only a manager can hand the initiative to someone else.');
        }
        $i ? $i->update($data) : $i = BdInitiative::create($data + ['number' => Numbering::next('bd_initiative'), 'stage' => 'idea']);
        ActivityLogger::log('bizdev', 'initiative', "Initiative {$i->number} {$i->name} saved", $i);
        self::flush();

        return $i;
    }

    /** payback, NPV and ROI over the horizon at the hurdle (discount) rate */
    public static function businessCase(BdInitiative $i): array
    {
        $inv = (float) $i->investment;
        $net = round((float) $i->annual_revenue - (float) $i->annual_cost, 2);
        $r = self::cfg()['discount_rate'] / 100;
        $h = max(1, (int) $i->horizon_years);
        $npv = -$inv;
        for ($t = 1; $t <= $h; $t++) {
            $npv += $net / (1 + $r) ** $t;
        }

        return ['net' => $net, 'margin' => (float) $i->annual_revenue > 0 ? round(100 * $net / (float) $i->annual_revenue, 1) : null,
            'payback_months' => $net > 0 ? ($inv > 0 ? round(12 * $inv / $net, 1) : 0.0) : null,
            'npv' => round($npv, 2), 'roi' => $inv > 0 ? round(100 * ($net * $h - $inv) / $inv, 1) : null, 'rate' => self::cfg()['discount_rate'], 'horizon' => $h];
    }

    /** actual results since going live vs the business case pro-rata */
    public static function initiativeStats(BdInitiative $i): array
    {
        $bc = self::businessCase($i);
        $res = $i->relationLoaded('results') ? $i->results : $i->results()->get();
        $rev = round((float) $res->sum('revenue'), 2);
        $cost = round((float) $res->sum('cost'), 2);
        $months = $res->count();
        $plan = round($bc['net'] / 12 * $months, 2);
        $ms = $i->relationLoaded('milestones') ? $i->milestones : $i->milestones()->get();

        return $bc + ['months' => $months, 'revenue' => $rev, 'cost' => $cost, 'actual_net' => round($rev - $cost, 2), 'plan_net' => $plan,
            'achievement' => $plan > 0 ? round(100 * ($rev - $cost) / $plan, 1) : null, 'recovered' => (float) $i->investment > 0 ? round(100 * ($rev - $cost) / (float) $i->investment, 1) : null,
            'milestones' => $ms->count(), 'milestones_done' => $ms->whereNotNull('done_at')->count(), 'milestones_overdue' => $ms->filter->isOverdue()->count()];
    }

    public static function moveStage(BdInitiative $i, string $to, array $d, User $u): string
    {
        self::need(self::canEdit($u) || self::canApprove($u));
        $allowed = ['idea' => ['assessment', 'rejected'], 'assessment' => ['idea', 'business_case', 'rejected'], 'business_case' => ['assessment', 'rejected'],
            'approved' => ['implementation', 'closed'], 'implementation' => ['live', 'closed'], 'live' => ['closed'], 'closed' => [], 'rejected' => ['idea']][$i->stage] ?? [];
        self::need(in_array($to, $allowed, true), 'An initiative at '.strtolower($i->stageLabel()).' cannot move to '.strtolower(BdInitiative::STAGES[$to][0] ?? $to).'.');
        self::need(! $i->awaitingApproval() || $to === 'rejected', 'The business case is with the approver – use the decision instead.');
        $note = trim((string) ($d['note'] ?? ''));
        $upd = ['stage' => $to];
        if ($to === 'rejected' || $i->stage === 'rejected') {
            self::need(self::canApprove($u), 'Only a business development approver can shelve or revive an initiative.');
        } else {
            self::need(self::mayEdit($i, $u), 'Only the initiative owner or a business development manager can move it.');
        }
        if ($to === 'rejected') {
            self::need($note !== '', 'Say why the initiative is being shelved.');
            $upd += ['decision_note' => mb_substr($note, 0, 500), 'decided_by' => $u->id, 'decided_at' => now(), 'submitted_at' => null];
        }
        if ($i->stage === 'rejected') {
            $upd += ['decision_note' => null, 'decided_by' => null, 'decided_at' => null];
        }
        if ($to === 'live') {
            $open = $i->milestones()->whereNull('done_at')->count();
            self::need($open === 0, "Complete the {$open} open milestone(s) before going live.");
            $upd['live_date'] = bdate()->toDateString();
        }
        if ($to === 'closed') {
            self::need($note !== '', 'Give a closing note (completed, abandoned, merged …).');
            $upd['close_reason'] = mb_substr($note, 0, 255);
        }
        $i->update($upd);
        ActivityLogger::log('bizdev', 'stage', "Initiative {$i->number} moved to {$to}", $i);
        self::flush();

        return 'Initiative moved to '.strtolower(BdInitiative::STAGES[$to][0]).'.';
    }

    public static function submit(BdInitiative $i, User $u): void
    {
        self::need(self::mayEdit($i, $u), 'Only the initiative owner or a business development manager can submit it.');
        self::need($i->stage === 'business_case' && ! $i->submitted_at, 'Only a business case that is not yet submitted can go for approval.');
        self::need((float) $i->annual_revenue > 0, 'The business case needs the expected annual revenue.');
        self::need((bool) $i->description, 'Describe the opportunity before submitting.');
        self::need($i->launch_date && $i->launch_date->gte(bdate()->copy()->startOfDay()), 'Set a target launch date that is not in the past.');
        self::need($i->milestones()->exists(), 'Add at least one milestone (the implementation plan).');
        $i->update(['submitted_at' => now(), 'decision_note' => null]);
        ActivityLogger::log('bizdev', 'submit', "Initiative {$i->number} business case submitted for approval", $i);
        self::flush();
    }

    public static function decide(BdInitiative $i, string $decision, ?string $note, User $u): string
    {
        self::need(self::canApprove($u), 'You are not a business development approver.');
        self::need($i->awaitingApproval(), 'This initiative is not awaiting approval.');
        self::need($i->owner_id !== $u->id, 'You cannot approve your own business case – another approver must decide.');
        self::need(in_array($decision, ['approve', 'return', 'reject'], true), 'Approve, return or reject.');
        $note = trim((string) $note);
        self::need($decision === 'approve' || $note !== '', 'Explain the decision to the owner.');
        $upd = ['decided_by' => $u->id, 'decided_at' => now(), 'decision_note' => $note !== '' ? mb_substr($note, 0, 500) : null];
        $upd += match ($decision) {
            'approve' => ['stage' => 'approved'],
            'return' => ['submitted_at' => null],
            'reject' => ['stage' => 'rejected', 'submitted_at' => null],
        };
        $i->update($upd);
        ActivityLogger::log('bizdev', 'decision', "Initiative {$i->number}: business case {$decision}", $i);
        self::flush();

        return ['approve' => 'Business case approved – the initiative can move to implementation.', 'return' => 'Returned to the owner for changes.', 'reject' => 'Initiative rejected.'][$decision];
    }

    public static function addMilestone(BdInitiative $i, array $d, User $u): BdMilestone
    {
        self::need(self::mayEdit($i, $u), 'Only the initiative owner or a business development manager can plan milestones.');
        self::need(in_array($i->stage, ['idea', 'assessment', 'business_case', 'approved', 'implementation'], true), 'Milestones are planned before the initiative goes live.');
        self::need(! $i->awaitingApproval(), 'The business case is with the approver.');
        $title = trim((string) ($d['title'] ?? ''));
        self::need($title !== '' && mb_strlen($title) <= 150, 'Describe the milestone.');
        $due = self::date($d['due_date'] ?? null, 'The due date', true);
        self::need($due->gte(bdate()->copy()->startOfDay()), 'Milestones are due today or later.');
        $m = BdMilestone::create(['initiative_id' => $i->id, 'title' => $title, 'due_date' => $due, 'owner_id' => self::owner($d['owner_id'] ?? null, $u) ?? $i->owner_id]);
        self::flush();

        return $m;
    }

    public static function toggleMilestone(BdMilestone $m, User $u): bool
    {
        $i = $m->initiative;
        self::need(self::mayEdit($i, $u) || $m->owner_id === $u->id, 'Only the milestone or initiative owner can tick it off.');
        self::need(in_array($i->stage, ['approved', 'implementation', 'business_case', 'assessment', 'idea'], true) && ! $i->awaitingApproval(), 'Milestones of this initiative are locked.');
        $done = ! $m->done_at;
        $m->update(['done_at' => $done ? now() : null, 'done_by' => $done ? $u->id : null]);
        self::flush();

        return $done;
    }

    public static function deleteMilestone(BdMilestone $m, User $u): void
    {
        self::need(self::mayEdit($m->initiative, $u), 'Only the initiative owner or a business development manager can remove milestones.');
        self::need(! $m->done_at, 'Completed milestones are kept as history.');
        self::need(! $m->initiative->awaitingApproval() && in_array($m->initiative->stage, ['idea', 'assessment', 'business_case', 'approved', 'implementation'], true), 'Milestones of this initiative are locked.');
        $m->delete();
        self::flush();
    }

    public static function saveResult(BdInitiative $i, array $d, User $u): BdResult
    {
        self::need(self::mayEdit($i, $u), 'Only the initiative owner or a business development manager can record results.');
        self::need($i->stage === 'live' && $i->live_date, 'Results are recorded once the initiative is live.');
        self::need((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($d['month'] ?? '')), 'Choose the month.');
        $m = Carbon::parse($d['month'].'-01')->startOfDay();
        self::need($m->gte($i->live_date->copy()->startOfMonth()), 'That month is before the initiative went live.');
        self::need($m->lte(bdate()->copy()->startOfMonth()), 'You cannot record a future month.');
        $r = date_upsert(BdResult::class, ['initiative_id' => $i->id, 'month' => $m->toDateString()],
            ['revenue' => self::money($d['revenue'] ?? 0, 'Revenue') ?? 0, 'cost' => self::money($d['cost'] ?? 0, 'Cost') ?? 0, 'note' => self::text($d['note'] ?? null, 255), 'entered_by' => $u->id]);
        ActivityLogger::log('bizdev', 'result', "Initiative {$i->number}: results for {$m->format('M Y')} recorded", $i);

        return $r;
    }

    /** funnel counts and portfolio totals */
    public static function pipeline(): array
    {
        $all = BdInitiative::get();
        $committed = $all->whereIn('stage', ['approved', 'implementation', 'live']);

        return ['by_stage' => collect(BdInitiative::PIPELINE)->mapWithKeys(fn ($s) => [$s => $all->where('stage', $s)->count()])->all(),
            'awaiting' => $all->filter->awaitingApproval()->count(), 'open' => $all->filter->isOpen()->count(),
            'committed_revenue' => round((float) $committed->sum('annual_revenue'), 2), 'committed_investment' => round((float) $committed->sum('investment'), 2),
            'pipeline_revenue' => round((float) $all->whereIn('stage', ['idea', 'assessment', 'business_case'])->sum('annual_revenue'), 2)];
    }

    // ------------------------------------------------------------------ alerts & KPIs

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= (function () {
            $partners = BdPartner::whereIn('status', ['active', 'negotiating'])->get();
            $flags = $partners->map(fn ($p) => collect(self::partnerFlags($p))->pluck(0)->all());
            $last = self::lastShop();
            $prev = bdate()->copy()->startOfMonth()->subMonthNoOverflow();

            return [
                'approvals' => BdInitiative::where('stage', 'business_case')->whereNotNull('submitted_at')->count(),
                'milestones' => BdMilestone::whereNull('done_at')->whereDate('due_date', '<', bdate()->toDateString())
                    ->whereHas('initiative', fn ($q) => $q->whereIn('stage', ['idea', 'assessment', 'business_case', 'approved', 'implementation']))->count(),
                'lapsed' => $flags->filter(fn ($f) => in_array('lapsed', $f, true))->count(),
                'expiring' => $flags->filter(fn ($f) => in_array('expiring', $f, true))->count(),
                'reviews' => $flags->filter(fn ($f) => in_array('review', $f, true))->count(),
                'shops_stale' => BdCompetitor::where('is_active', true)->exists() && (! $last || $last->lt(bdate()->copy()->subDays(self::cfg()['shop_stale_days']))) ? 1 : 0,
                'benchmark_missing' => BdCompetitor::where('is_active', true)->exists() && ! BdBenchmark::whereDate('month', $prev->toDateString())->exists() ? 1 : 0,
            ];
        })();
    }

    /** nav badge: business cases to decide, overdue milestones, partnerships past their end date */
    public static function attention(): int
    {
        $a = self::alerts();

        return $a['approvals'] + $a['milestones'] + $a['lapsed'];
    }

    /** partnership production totals for the dashboard / reports */
    public static function partnerTotals(?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = BdPartner::whereIn('status', ['active', 'expired'])->get()->map(fn ($p) => ['partner' => $p] + self::partnerProduction($p, $from, $to));

        return ['partners' => $rows, 'active' => BdPartner::where('status', 'active')->count(), 'bookings' => $rows->sum('bookings'), 'nights' => $rows->sum('nights'),
            'revenue' => round($rows->sum('revenue'), 2), 'commission' => round($rows->sum('commission'), 2)];
    }
}
