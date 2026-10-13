<?php

namespace App\Services;

use App\Models\BanquetEvent;
use App\Models\BanquetFunction;
use App\Models\ComplianceItem;
use App\Models\DataBreach;
use App\Models\Employee;
use App\Models\EsgChecklistItem;
use App\Models\EsgCommunity;
use App\Models\EsgGreenNight;
use App\Models\EsgGreenStay;
use App\Models\EsgInitiative;
use App\Models\EsgLaundryMonth;
use App\Models\EsgPolicy;
use App\Models\EsgRefrigerantLog;
use App\Models\EsgTarget;
use App\Models\EsgWasteLog;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\HseAccident;
use App\Models\LoyaltyEntry;
use App\Models\MeterReading;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Setting;
use App\Models\SupplierInvoice;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v7.0 Sustainability & ESG.
 *  - Carbon footprint by scope: Scope 1 fuels burnt on site (LPG, diesel, gas) and refrigerant leaks (kg topped up × GWP);
 *    Scope 2 grid electricity (Ghana grid ≈ 0.314 kg/kWh); Scope 3 water supply & treatment, waste by disposal route
 *    and outsourced laundry. Every factor is an editable setting.
 *  - Hotel Carbon Measurement Initiative (HCMI v1.2 / 2.0 method): whole-hotel Scope 1 + 2 + outsourced laundry, less
 *    private space pro-rata to area, split between guest rooms (+ corridors) and meeting space by floor area →
 *    kg CO₂e per occupied room and per m² of meeting space per hour of use. HWMI does the same for water. The per-room and
 *    per-m²-hour footprints drive carbon statements for a booking, an event or a corporate account.
 *  - Waste register by stream and route (diversion rate), F-gas log with annual leak rate per system, outsourced laundry
 *    tonnage (or the HCMI estimate per occupied room), targets against a baseline, green initiatives with a business case
 *    and four-eyes approval, community investment, eco-certification self-assessment, governance policy register.
 *  - Guest green-stay choice: the stay-over clean is skipped (a full service every N nights), the night audit logs each
 *    green night, awards loyalty points and estimates the energy, water and carbon saved.
 *  - People indicators for the social pillar: gender, women in management, local hires, turnover, training hours, LTIFR,
 *    minimum-wage compliance and the mean gender pay gap on basic salary.
 */
class EsgService
{
    public const DEFAULTS = [
        // building areas (m²) – HCMI allocation
        'esg_area_total' => '9500', 'esg_area_rooms' => '6200', 'esg_area_meeting' => '900', 'esg_area_private' => '0',
        // Scope 3 factors, kg CO₂e per unit (DEFRA / DESNZ conversion factors; editable)
        'esg_ef_water_supply' => '0.149', 'esg_ef_wastewater' => '0.272',
        'esg_ef_landfill' => '0.520', 'esg_ef_landfill_food' => '0.700', 'esg_ef_recycling' => '0.021', 'esg_ef_composting' => '0.009',
        'esg_ef_anaerobic' => '0.009', 'esg_ef_incineration' => '0.006', 'esg_ef_donation' => '0', 'esg_ef_rendering' => '0.021',
        'esg_ef_laundry' => '0.60',                 // per kg of linen processed – replace with your laundry's own figure
        'esg_laundry_outsourced' => '1', 'esg_laundry_kg_room' => '5.1256',   // HCMI default when tonnage is unknown
        // green-stay choice
        'esg_green_enabled' => '1', 'esg_green_points' => '50', 'esg_green_service_every' => '3', 'esg_green_kwh' => '1.5', 'esg_green_litres' => '60',
        // controls
        'esg_leak_alert_pct' => '10', 'esg_four_eyes' => '1',
    ];

    public const WASTE_EF = ['landfill' => 'esg_ef_landfill', 'recycling' => 'esg_ef_recycling', 'composting' => 'esg_ef_composting', 'anaerobic' => 'esg_ef_anaerobic',
        'incineration' => 'esg_ef_incineration', 'donation' => 'esg_ef_donation', 'rendering' => 'esg_ef_rendering'];

    /** metric => [label, unit, better (lower|higher), decimals] */
    public const METRICS = [
        'co2_room' => ['Carbon per occupied room (HCMI)', 'kg CO₂e', 'lower', 1],
        'co2_total' => ['Total carbon footprint (Scopes 1–3, annualised)', 't CO₂e / yr', 'lower', 1],
        'kwh_room' => ['Energy per occupied room', 'kWh', 'lower', 1],
        'water_room' => ['Water per occupied room (HWMI)', 'litres', 'lower', 0],
        'waste_room' => ['Waste per occupied room', 'kg', 'lower', 2],
        'diversion' => ['Waste diverted from landfill', '%', 'higher', 1],
        'renewable' => ['Renewable share of electricity', '%', 'higher', 1],
        'local_spend' => ['Local purchasing share', '%', 'higher', 1],
        'female_mgmt' => ['Women in management', '%', 'higher', 1],
        'training_hours' => ['Training hours per employee (12 months)', 'h', 'higher', 1],
    ];

    /** starter eco-certification self-assessment (generic – modelled on Green Key / EarthCheck style criteria) */
    public const CHECKLIST = [
        ['M01', 'management', 'A written environmental / sustainability policy signed by the general manager', 'Displayed to staff and guests; reviewed yearly.', true],
        ['M02', 'management', 'A named sustainability manager and a green team that meets at least quarterly', 'Minutes of the last two meetings.', true],
        ['M03', 'management', 'Energy, water and waste are measured monthly and compared with the previous year', 'Utilities module readings; ESG dashboard.', true],
        ['M04', 'management', 'Yearly improvement targets with an action plan', 'ESG targets & initiatives.', true],
        ['M05', 'management', 'Legal compliance register (EPA permit, fire certificate, water & effluent) is up to date', 'Compliance register – no expired items.', true],
        ['S01', 'staff', 'All staff receive sustainability induction and yearly refresher training', 'Training records.', true],
        ['S02', 'staff', 'Staff can suggest green ideas and are recognised for them', 'Initiative log shows staff-raised ideas.', false],
        ['G01', 'guests', 'Guests are informed about the sustainability programme (room card, portal, website)', 'Photo of the in-room card; portal page.', true],
        ['G02', 'guests', 'Guests can opt out of daily linen / towel change', 'Green-stay choice switched on.', true],
        ['G03', 'guests', 'Guests are offered information on public transport, walking and local attractions', 'Concierge local guide.', false],
        ['E01', 'energy', 'At least 75 % of lighting is LED or equally efficient', 'Lighting survey.', true],
        ['E02', 'energy', 'Air-conditioning switches off or sets back automatically in unsold / unoccupied rooms', 'Key-card or occupancy sensors.', false],
        ['E03', 'energy', 'Boilers, chillers and AC units are serviced at least yearly and refrigerant leaks logged', 'PM schedule; F-gas log.', true],
        ['E04', 'energy', 'Renewable energy is generated on site or purchased', 'Solar meter readings.', false],
        ['E05', 'energy', 'Outdoor and public-area lighting runs on timers or daylight sensors', '', false],
        ['W01', 'water', 'Shower heads ≤ 9 L/min and taps ≤ 8 L/min (except kitchen)', 'Flow-test sheet.', true],
        ['W02', 'water', 'Toilets flush ≤ 6 L or dual flush', '', false],
        ['W03', 'water', 'Monthly leak checks; leaks repaired within 48 hours', 'Maintenance tickets.', true],
        ['W04', 'water', 'Garden irrigation early morning / evening or with recycled water', '', false],
        ['X01', 'waste', 'Waste is separated into at least three streams and weighed', 'Waste register.', true],
        ['X02', 'waste', 'Food waste is measured and reduced; surplus food donated where safe', 'Food waste log; donations.', false],
        ['X03', 'waste', 'Single-use plastic toiletries, straws and bottled water in rooms are phased out', 'Purchasing records.', false],
        ['X04', 'waste', 'Hazardous waste (batteries, lamps, oil, e-waste) goes to a licensed contractor', 'Waste transfer notes.', true],
        ['P01', 'purchasing', 'At least 25 % of food & beverage is bought from local producers', 'Local-spend report.', false],
        ['P02', 'purchasing', 'A supplier code of conduct covers labour, environment and anti-corruption', '', false],
        ['P03', 'purchasing', 'Paper products are recycled or certified (FSC / PEFC)', '', false],
        ['C01', 'chemicals', 'Cleaning chemicals are eco-labelled or dosed automatically', 'Chemical register and SDS file.', false],
        ['C02', 'chemicals', 'Pesticides and fertilisers are minimised and applied by trained staff', '', false],
        ['R01', 'social', 'The hotel supports local community projects and records the value given', 'Community investment register.', false],
        ['R02', 'social', 'Equal opportunity and anti-harassment policies are in place and communicated', 'Policy register.', true],
        ['R03', 'social', 'All staff are paid at least the national minimum wage', 'People indicators.', true],
    ];

    public const POLICIES = [
        ['ENV-01', 'Environmental & sustainability policy', 'environment'], ['ENV-02', 'Energy & water management procedure', 'environment'],
        ['ENV-03', 'Waste management & recycling procedure', 'environment'], ['ENV-04', 'Sustainable & local purchasing policy', 'environment'],
        ['SOC-01', 'Equal opportunity, diversity & inclusion policy', 'social'], ['SOC-02', 'Anti-harassment & grievance policy', 'social'],
        ['SOC-03', 'Child protection & anti-trafficking policy', 'social'], ['SOC-04', 'Occupational health & safety policy', 'social'],
        ['GOV-01', 'Code of conduct & ethics', 'governance'], ['GOV-02', 'Anti-bribery & corruption policy', 'governance'],
        ['GOV-03', 'Whistle-blowing policy', 'governance'], ['GOV-04', 'Data protection policy (Act 843)', 'governance'],
    ];

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** settings, the starter checklist and draft policy register; migrates the legacy Indian grid factor to Ghana's */
    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        // v7.0 localisation fix: only a factor still at the old CEA (India) default is replaced – an edited value is kept
        Setting::where('key', 'eng_ef_electricity')->where('value', '0.71')->update(['value' => UtilityService::DEFAULTS['eng_ef_electricity']]);
        if (! EsgChecklistItem::exists()) {
            foreach (self::CHECKLIST as $i => [$code, $sec, $req, $guide, $mand]) {
                EsgChecklistItem::create(['code' => $code, 'section' => $sec, 'requirement' => $req, 'guidance' => $guide ?: null, 'is_mandatory' => $mand, 'status' => 'not', 'sort' => $i + 1]);
            }
        }
        if (! EsgPolicy::exists()) {
            foreach (self::POLICIES as [$code, $title, $pillar]) {
                EsgPolicy::create(['code' => $code, 'title' => $title, 'pillar' => $pillar, 'status' => 'draft', 'review_months' => 12]);
            }
        }
        Setting::$version++;
        self::flush();
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= (function () {
            $f = fn ($k) => (float) setting($k, self::DEFAULTS[$k]);
            $waste = [];
            foreach (self::WASTE_EF as $d => $k) {
                $waste[$d] = max(0, $f($k));
            }

            return [
                'area' => ['total' => max(0, $f('esg_area_total')), 'rooms' => max(0, $f('esg_area_rooms')), 'meeting' => max(0, $f('esg_area_meeting')), 'private' => max(0, $f('esg_area_private'))],
                'ef_water' => max(0, $f('esg_ef_water_supply')), 'ef_wastewater' => max(0, $f('esg_ef_wastewater')),
                'ef_waste' => $waste, 'ef_landfill_food' => max(0, $f('esg_ef_landfill_food')), 'ef_laundry' => max(0, $f('esg_ef_laundry')),
                'laundry_outsourced' => setting('esg_laundry_outsourced', '1') === '1', 'laundry_kg_room' => max(0, $f('esg_laundry_kg_room')),
                'green_enabled' => setting('esg_green_enabled', '1') === '1', 'green_points' => max(0, (int) $f('esg_green_points')),
                'green_every' => max(2, min(7, (int) $f('esg_green_service_every'))), 'green_kwh' => max(0, $f('esg_green_kwh')), 'green_litres' => max(0, $f('esg_green_litres')),
                'leak_alert_pct' => max(1, $f('esg_leak_alert_pct')), 'four_eyes' => setting('esg_four_eyes', '1') === '1',
                'grid' => UtilityService::cfg()['ef']['electricity'],
            ];
        })();
    }

    public static function saveSettings(array $d): void
    {
        $num = function (string $k, float $lo, float $hi, string $msg) use ($d) {
            $v = $d[$k] ?? null;
            self::need(is_numeric($v) && $v >= $lo && $v <= $hi, $msg);

            return (string) round((float) $v, 4);
        };
        $out = [];
        foreach (['total' => 'Total floor area', 'rooms' => 'Guest rooms & corridors area', 'meeting' => 'Meeting space area', 'private' => 'Private / tenant area'] as $k => $l) {
            $out['esg_area_'.$k] = $num('area_'.$k, 0, 1000000, "{$l} must be 0 to 1,000,000 m².");
        }
        self::need((float) $out['esg_area_total'] > 0, 'Enter the total floor area.');
        self::need((float) $out['esg_area_rooms'] + (float) $out['esg_area_meeting'] + (float) $out['esg_area_private'] <= (float) $out['esg_area_total'] + 0.01,
            'Rooms, meeting and private areas cannot add up to more than the total floor area.');
        self::need((float) $out['esg_area_rooms'] > 0, 'Enter the guest-room area (rooms and corridors) – HCMI allocates the footprint by area.');
        foreach (['ef_water_supply', 'ef_wastewater', 'ef_landfill', 'ef_landfill_food', 'ef_recycling', 'ef_composting', 'ef_anaerobic', 'ef_incineration', 'ef_donation', 'ef_rendering', 'ef_laundry'] as $k) {
            $out['esg_'.$k] = $num($k, 0, 20, 'Emission factors are 0 to 20 kg CO₂e per unit.');
        }
        $out['esg_laundry_kg_room'] = $num('laundry_kg_room', 0, 50, 'Laundry per occupied room is 0 to 50 kg.');
        $out['esg_green_points'] = (string) (int) $num('green_points', 0, 10000, 'Green-stay points are 0 to 10,000 per night.');
        $out['esg_green_service_every'] = (string) (int) $num('green_service_every', 2, 7, 'The full service comes every 2 to 7 nights.');
        $out['esg_green_kwh'] = $num('green_kwh', 0, 100, 'Energy saved per green night is 0 to 100 kWh.');
        $out['esg_green_litres'] = $num('green_litres', 0, 2000, 'Water saved per green night is 0 to 2,000 litres.');
        $out['esg_leak_alert_pct'] = $num('leak_alert_pct', 1, 100, 'The leak-rate alert is 1 to 100 %.');
        foreach (['laundry_outsourced', 'green_enabled', 'four_eyes'] as $k) {
            $out['esg_'.$k] = ! empty($d[$k]) ? '1' : '0';
        }
        foreach ($out as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('esg', 'settings', 'Sustainability & ESG settings updated');
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('esg.manage');
    }

    public static function canRecord(?User $u): bool
    {
        return (bool) ($u?->hasPermission('esg.record') || $u?->hasPermission('esg.manage'));
    }

    public static function canGreen(?User $u): bool
    {
        return (bool) ($u?->hasPermission('esg.green') || $u?->hasPermission('esg.manage'));
    }

    protected static function today(): string
    {
        return bdate()->toDateString();
    }

    protected static function text($v, int $max = 255): ?string
    {
        return filled($v) ? mb_substr(trim((string) $v), 0, $max) : null;
    }

    protected static function date($v, string $label, bool $required = true): ?string
    {
        if (! filled($v)) {
            self::need(! $required, "Enter the {$label}.");

            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("The {$label} is not a valid date.");
        }
    }

    protected static function num($v, float $lo, float $hi, string $msg): float
    {
        $v = filled($v) ? $v : 0;
        self::need(is_numeric($v) && $v >= $lo && $v <= $hi, $msg);

        return (float) $v;
    }

    // ───────────────────────────── activity data ─────────────────────────────

    /** hotel areas used by HCMI: the footprint of private space is excluded pro-rata; the rest is split rooms : meeting by area */
    public static function allocation(): array
    {
        $a = self::cfg()['area'];
        $private = $a['total'] > 0 ? min(1, $a['private'] / $a['total']) : 0.0;
        $base = $a['rooms'] + $a['meeting'];
        $meeting = $base > 0 ? $a['meeting'] / $base : 0.0;

        return ['private_share' => $private, 'meeting_share' => $meeting, 'rooms_share' => 1 - $meeting, 'area' => $a];
    }

    /** meeting-space use: Σ hall area (m²) × function hours for indoor functions of events that went ahead */
    public static function meetingUse(string $from, string $to, ?int $eventId = null): array
    {
        $fns = BanquetFunction::with('hall', 'event')->whereBetween('date', [$from, $to])
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['definite', 'completed'])->when($eventId, fn ($w) => $w->whereKey($eventId)))->get();
        $rows = [];
        $m2h = 0.0;
        foreach ($fns as $f) {
            if (! $f->hall || $f->hall->is_outdoor || ! $f->hall->area_sqft || ! $f->start_time || ! $f->end_time) {
                continue;
            }
            $s = Carbon::parse($f->date->toDateString().' '.$f->start_time);
            $e = Carbon::parse($f->date->toDateString().' '.$f->end_time);
            if ($e->lte($s)) {
                $e->addDay();
            }
            $h = round($s->diffInMinutes($e) / 60, 2);
            $m2 = round($f->hall->area_sqft * 0.092903, 1);
            $m2h += $m2 * $h;
            $rows[] = ['function' => $f, 'hours' => $h, 'm2' => $m2, 'm2h' => round($m2 * $h, 1)];
        }

        return ['rows' => $rows, 'm2h' => round($m2h, 1)];
    }

    /** outsourced laundry for the period: supplier tonnage per month, else the HCMI estimate per occupied room */
    public static function laundry(string $from, string $to, int $rooms): array
    {
        $c = self::cfg();
        if (! $c['laundry_outsourced']) {
            return ['kg' => 0.0, 'estimated' => false, 'kg_co2' => 0.0, 'months' => 0];
        }
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $logged = EsgLaundryMonth::whereBetween('month', [$f->copy()->startOfMonth()->toDateString(), $t->toDateString()])->get()->keyBy(fn ($m) => $m->month->format('Y-m'));
        $kg = 0.0;
        $est = false;
        $occ = UtilityService::occupancy($from, $to);
        for ($m = $f->copy()->startOfMonth(); $m->lte($t); $m->addMonthNoOverflow()) {
            $ms = max($m->toDateString(), $from);
            $me = min($m->copy()->endOfMonth()->toDateString(), $to);
            $roomsIn = array_sum(array_map(fn ($o) => $o['rooms'], array_filter($occ, fn ($k) => $k >= $ms && $k <= $me, ARRAY_FILTER_USE_KEY)));
            if ($row = $logged->get($m->format('Y-m'))) {
                $days = $m->daysInMonth;
                $share = (Carbon::parse($ms)->diffInDays(Carbon::parse($me)) + 1) / $days;
                $kg += (float) $row->kg * $share;
            } else {
                $kg += $roomsIn * $c['laundry_kg_room'];
                $est = $est || $roomsIn > 0;
            }
        }

        return ['kg' => round($kg, 1), 'estimated' => $est, 'kg_co2' => round($kg * $c['ef_laundry'], 1)];
    }

    public static function wasteFactor(string $stream, string $disposal): float
    {
        $c = self::cfg();

        return $disposal === 'landfill' && in_array($stream, ['food', 'garden'], true) ? $c['ef_landfill_food'] : ($c['ef_waste'][$disposal] ?? 0.0);
    }

    /** carbon footprint by scope for a period: Scope 1 fuels + refrigerants, Scope 2 electricity, Scope 3 water, waste, laundry */
    public static function carbon(string $from, string $to): array
    {
        $key = "carbon{$from}{$to}".Tenant::id();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $c = self::cfg();
        $u = UtilityService::byUtility($from, $to);
        $ef = UtilityService::cfg()['ef'];
        $rows = [];
        $add = function (string $key, int $scope, string $label, float $qty, string $unit, float $factor, ?float $kg = null, ?string $note = null) use (&$rows) {
            if ($qty <= 0 && ! $kg) {
                return;
            }
            $rows[$key] = ['scope' => $scope, 'label' => $label, 'qty' => round($qty, 1), 'unit' => $unit, 'factor' => $factor, 'kg' => round($kg ?? $qty * $factor, 1), 'note' => $note];
        };
        foreach (['lpg' => 'LPG', 'diesel' => 'Diesel', 'png' => 'Piped natural gas', 'dg' => 'Generator output'] as $k => $l) {
            $add($k, 1, $l, $u[$k]['cons'] ?? 0, \App\Models\UtilityMeter::UTILITIES[$k][1] ?? '', $ef[$k] ?? 0);
        }
        $refs = EsgRefrigerantLog::whereBetween('date', [$from, $to])->get();
        $refKg = $refs->sum(fn ($r) => $r->co2e());
        $add('refrigerant', 1, 'Refrigerant leakage', (float) $refs->sum(fn ($r) => $r->emittedKg()), 'kg gas', 0, $refKg, 'gas lost (top-ups, service & disposal losses) × GWP (AR5)');
        $add('electricity', 2, 'Grid electricity', $u['electricity']['cons'] ?? 0, 'kWh', $ef['electricity']);
        $water = (float) ($u['water']['cons'] ?? 0);
        $add('water', 3, 'Water supply & treatment', $water, 'm³', round($c['ef_water'] + $c['ef_wastewater'], 3));
        $waste = EsgWasteLog::live()->whereBetween('date', [$from, $to])->get();
        foreach ($waste->groupBy('disposal') as $disp => $g) {
            $kg = $g->sum(fn ($w) => (float) $w->weight_kg * self::wasteFactor($w->stream, $w->disposal));
            $add('waste_'.$disp, 3, 'Waste – '.(EsgWasteLog::DISPOSALS[$disp] ?? $disp), (float) $g->sum('weight_kg'), 'kg', $g->sum('weight_kg') > 0 ? round($kg / $g->sum('weight_kg'), 3) : 0, $kg);
        }
        $occ = UtilityService::occupancy($from, $to);
        $rooms = array_sum(array_column($occ, 'rooms'));
        $l = self::laundry($from, $to, $rooms);
        $add('laundry', 3, 'Outsourced laundry', $l['kg'], 'kg linen', $c['ef_laundry'], null, $l['estimated'] ? 'estimated at '.rtrim(rtrim(number_format($c['laundry_kg_room'], 4), '0'), '.').' kg per occupied room (HCMI) for months without supplier tonnage' : null);
        $scopes = [1 => 0.0, 2 => 0.0, 3 => 0.0];
        foreach ($rows as $r) {
            $scopes[$r['scope']] += $r['kg'];
        }
        $total = array_sum($scopes);

        return self::$memo[$key] = ['rows' => $rows, 'scopes' => array_map(fn ($v) => round($v, 1), $scopes), 'total_kg' => round($total, 1), 'rooms' => $rooms,
            'guests' => array_sum(array_column($occ, 'guests')), 'per_room' => $rooms ? round($total / $rooms, 2) : null, 'water_m3' => $water,
            'kwh' => round(($u['electricity']['cons'] ?? 0) + ($u['dg']['cons'] ?? 0) + ($u['solar']['cons'] ?? 0), 1), 'solar_kwh' => (float) ($u['solar']['cons'] ?? 0),
            'grid_kwh' => (float) ($u['electricity']['cons'] ?? 0), 'avoided_kg' => round(($u['solar']['cons'] ?? 0) * $ef['electricity'], 1), 'laundry' => $l, 'waste_kg' => round((float) $waste->sum('weight_kg'), 1)];
    }

    /** HCMI & HWMI for a period */
    public static function hcmi(string $from, string $to): array
    {
        $key = "hcmi{$from}{$to}".Tenant::id();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $cb = self::carbon($from, $to);
        $al = self::allocation();
        // HCMI boundary: Scope 1 + 2 (fuels, refrigerants, electricity) + outsourced laundry; water and waste are not in HCMI
        $in = collect($cb['rows'])->filter(fn ($r, $k) => $r['scope'] < 3 || $k === 'laundry')->sum('kg');
        $hotel = $in * (1 - $al['private_share']);
        $meetKg = $hotel * $al['meeting_share'];
        $roomKg = $hotel - $meetKg;
        $use = self::meetingUse($from, $to);
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $water = $cb['water_m3'] * (1 - $al['private_share']);
        $wMeet = $water * $al['meeting_share'];
        $energy = $cb['kwh'] * (1 - $al['private_share']);

        return self::$memo[$key] = [
            'from' => $from, 'to' => $to, 'days' => $days, 'boundary_kg' => round($in, 1), 'hotel_kg' => round($hotel, 1), 'rooms_kg' => round($roomKg, 1), 'meeting_kg' => round($meetKg, 1),
            'occupied' => $cb['rooms'], 'm2h' => $use['m2h'], 'alloc' => $al, 'laundry' => $cb['laundry'],
            'per_room' => $cb['rooms'] ? round($roomKg / $cb['rooms'], 2) : null,
            'per_m2h' => $use['m2h'] > 0 ? round($meetKg / $use['m2h'], 4) : null,
            'per_m2_year' => $al['area']['total'] > 0 ? round($in / $al['area']['total'] * 365 / $days, 2) : null,
            'hwmi_room' => $cb['rooms'] ? round(($water - $wMeet) * 1000 / $cb['rooms'], 0) : null,       // litres per occupied room
            'hwmi_m2h' => $use['m2h'] > 0 ? round($wMeet * 1000 / $use['m2h'], 2) : null,                  // litres per m² per hour
            'kwh_room' => $cb['rooms'] ? round($energy * (1 - $al['meeting_share']) / $cb['rooms'], 1) : null,
        ];
    }

    /** the benchmark window: the last 12 months, starting no earlier than the first meter reading (so empty months don't dilute) */
    public static function window(?string $to = null): array
    {
        $to ??= Carbon::parse(self::today())->subDay()->toDateString();
        $from = Carbon::parse($to)->subYear()->addDay()->toDateString();
        $first = MeterReading::whereDate('reading_date', '>=', $from)->whereDate('reading_date', '<=', $to)->min('reading_date');
        $first = $first ? Carbon::parse($first)->toDateString() : $from;

        return [max($from, $first), $to];
    }

    public static function benchmark(?string $to = null): array
    {
        [$f, $t] = self::window($to);

        return self::hcmi($f, $t);
    }

    // ───────────────────────────── carbon statements ─────────────────────────────

    /** carbon & water statement for a reservation, a banquet event or a company account (per year) */
    public static function statement(string $type, int $id, ?int $year = null): array
    {
        $b = self::benchmark();
        self::need($b['per_room'] !== null, 'There is not yet enough metered consumption and occupancy to calculate footprints.');
        $lines = [];
        $roomLine = function (ReservationRoom $s) use ($b) {
            $n = max(0, (int) $s->arrival_date->diffInDays($s->departure_date));

            return ['kind' => 'room', 'label' => 'Room '.($s->room?->room_no ?? $s->roomType?->name ?? '').' · '.$s->arrival_date->format('d M').' – '.$s->departure_date->format('d M Y'),
                'qty' => $n, 'unit' => 'room-nights', 'kg' => round($n * $b['per_room'], 1), 'litres' => round($n * ($b['hwmi_room'] ?? 0), 0)];
        };
        $fnLine = function (array $u) use ($b) {
            return ['kind' => 'meeting', 'label' => $u['function']->name.' · '.($u['function']->hall?->name).' · '.$u['function']->date->format('d M Y'),
                'qty' => $u['m2h'], 'unit' => 'm²·h', 'kg' => $b['per_m2h'] !== null ? round($u['m2h'] * $b['per_m2h'], 1) : 0.0,
                'litres' => $b['hwmi_m2h'] !== null ? round($u['m2h'] * $b['hwmi_m2h'], 0) : 0];
        };
        if ($type === 'booking') {
            $r = Reservation::with('guest', 'company', 'rooms.room', 'rooms.roomType')->findOrFail($id);
            foreach ($r->rooms->whereNotIn('status', ['cancelled', 'no_show']) as $s) {
                $lines[] = $roomLine($s);
            }
            $title = 'Booking '.$r->reservation_no;
            $party = $r->company?->name ?? $r->guest?->full_name;
            $period = $r->arrival_date->format('d M Y').' – '.$r->departure_date->format('d M Y');
        } elseif ($type === 'event') {
            $e = BanquetEvent::with('company', 'guest')->findOrFail($id);
            foreach (self::meetingUse($e->event_date->toDateString(), ($e->end_date ?? $e->event_date)->toDateString(), $e->id)['rows'] as $u) {
                $lines[] = $fnLine($u);
            }
            if ($e->room_block_id) {
                $stays = ReservationRoom::with('room', 'roomType')->whereHas('reservation', fn ($q) => $q->where('room_block_id', $e->room_block_id))->whereNotIn('status', ['cancelled', 'no_show'])->get();
                foreach ($stays as $s) {
                    $lines[] = $roomLine($s);
                }
            }
            $title = 'Event '.$e->event_no.' – '.$e->title;
            $party = $e->company?->name ?? $e->contact_name ?? $e->guest?->full_name;
            $period = $e->event_date->format('d M Y').($e->end_date && ! $e->end_date->eq($e->event_date) ? ' – '.$e->end_date->format('d M Y') : '');
        } elseif ($type === 'company') {
            $co = \App\Models\Company::findOrFail($id);
            $year ??= (int) bdate()->year;
            $from = "{$year}-01-01";
            $to = "{$year}-12-31";
            $stays = ReservationRoom::with('room', 'roomType')->whereHas('reservation', fn ($q) => $q->where('company_id', $co->id))->whereIn('status', ['checked_in', 'checked_out'])
                ->whereDate('arrival_date', '<=', $to)->whereDate('arrival_date', '>=', $from)->orderBy('arrival_date')->get();
            foreach ($stays as $s) {
                $lines[] = $roomLine($s);
            }
            foreach (BanquetEvent::where('company_id', $co->id)->whereIn('status', ['definite', 'completed'])->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->get() as $e) {
                foreach (self::meetingUse($e->event_date->toDateString(), ($e->end_date ?? $e->event_date)->toDateString(), $e->id)['rows'] as $u) {
                    $lines[] = $fnLine($u);
                }
            }
            $title = 'Corporate account '.$co->name;
            $party = $co->name;
            $period = "Calendar year {$year}";
        } else {
            throw new RuntimeException('Choose a booking, an event or a company.');
        }

        return ['title' => $title, 'party' => $party, 'period' => $period, 'lines' => $lines, 'bench' => $b,
            'kg' => round(array_sum(array_column($lines, 'kg')), 1), 'litres' => round(array_sum(array_column($lines, 'litres')), 0),
            'nights' => array_sum(array_map(fn ($l) => $l['kind'] === 'room' ? $l['qty'] : 0, $lines)), 'm2h' => round(array_sum(array_map(fn ($l) => $l['kind'] === 'meeting' ? $l['qty'] : 0, $lines)), 1)];
    }

    // ───────────────────────────── waste ─────────────────────────────

    public static function saveWaste(array $d, User $by): EsgWasteLog
    {
        self::need(self::canRecord($by));
        self::need(isset(EsgWasteLog::STREAMS[$d['stream'] ?? '']), 'Choose the waste stream.');
        self::need(isset(EsgWasteLog::DISPOSALS[$d['disposal'] ?? '']), 'Choose where the waste went.');
        $date = self::date($d['date'] ?? null, 'date');
        self::need($date <= self::today(), 'The date cannot be after the business date.');
        $kg = self::num($d['weight_kg'] ?? null, 0.01, 100000, 'Enter the weight (0.01 to 100,000 kg).');
        self::need($kg > 0, 'Enter the weight.');
        self::need(! ($d['stream'] === 'hazardous' && $d['disposal'] === 'landfill' && ! filled($d['manifest_no'] ?? null)), 'Hazardous waste needs the waste transfer note / manifest number.');
        $w = EsgWasteLog::create(['number' => Numbering::next('esg_waste'), 'date' => $date, 'stream' => $d['stream'], 'disposal' => $d['disposal'], 'weight_kg' => round($kg, 2),
            'department_id' => $d['department_id'] ?? null, 'supplier_id' => $d['supplier_id'] ?? null, 'contractor' => self::text($d['contractor'] ?? null, 120),
            'manifest_no' => self::text($d['manifest_no'] ?? null, 60), 'cost' => round(self::num($d['cost'] ?? 0, 0, 10000000, 'The cost is not valid.'), 2),
            'income' => round(self::num($d['income'] ?? 0, 0, 10000000, 'The income is not valid.'), 2), 'notes' => self::text($d['notes'] ?? null), 'user_id' => $by->id]);
        ActivityLogger::log('esg', 'waste', "Waste {$w->number}: ".number_format($kg, 1)." kg {$w->streamLabel()} → {$w->disposalLabel()}", $w);

        return $w;
    }

    public static function cancelWaste(EsgWasteLog $w, string $reason, User $by): void
    {
        self::need(self::canManage($by) || ($w->user_id === $by->id && $w->date->toDateString() === self::today()), 'Only an ESG manager can cancel an earlier entry.');
        self::need(! $w->cancelled_at, 'This entry is already cancelled.');
        self::need(filled(trim($reason)), 'Give a reason for cancelling.');
        $w->update(['cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => self::text($reason, 190)]);
        ActivityLogger::log('esg', 'waste_cancel', "Waste {$w->number} cancelled: {$reason}", $w);
    }

    /** waste summary: matrix stream × route, diversion, carbon, per occupied room */
    public static function waste(string $from, string $to): array
    {
        $list = EsgWasteLog::live()->whereBetween('date', [$from, $to])->get();
        $matrix = [];
        foreach ($list as $w) {
            $matrix[$w->stream][$w->disposal] = ($matrix[$w->stream][$w->disposal] ?? 0) + (float) $w->weight_kg;
        }
        $total = (float) $list->sum('weight_kg');
        $div = (float) $list->filter(fn ($w) => $w->isDiverted())->sum('weight_kg');
        $co2 = $list->sum(fn ($w) => (float) $w->weight_kg * self::wasteFactor($w->stream, $w->disposal));
        $landfillAll = $list->sum(fn ($w) => (float) $w->weight_kg * self::wasteFactor($w->stream, 'landfill'));
        $rooms = array_sum(array_column(UtilityService::occupancy($from, $to), 'rooms'));

        return ['matrix' => $matrix, 'total' => round($total, 1), 'diverted' => round($div, 1), 'diversion' => $total > 0 ? round($div / $total * 100, 1) : null,
            'co2' => round($co2, 1), 'avoided' => round($landfillAll - $co2, 1), 'cost' => round((float) $list->sum('cost'), 2), 'income' => round((float) $list->sum('income'), 2),
            'rooms' => $rooms, 'per_room' => $rooms ? round($total / $rooms, 2) : null, 'food' => round((float) $list->where('stream', 'food')->sum('weight_kg'), 1),
            'by_stream' => $list->groupBy('stream')->map(fn ($g) => round((float) $g->sum('weight_kg'), 1))->sortDesc()->all(), 'count' => $list->count()];
    }

    /** kg per month for the last 12 months, split diverted / not */
    public static function wasteTrend(): array
    {
        $start = bdate()->copy()->startOfMonth()->subMonths(11);
        $rows = EsgWasteLog::live()->where('date', '>=', $start->toDateString())->get(['date', 'weight_kg', 'disposal']);
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $in = $rows->filter(fn ($w) => $w->date->format('Y-m') === $m->format('Y-m'));
            $out[] = ['label' => $m->format('M'), 'diverted' => round((float) $in->filter(fn ($w) => $w->isDiverted())->sum('weight_kg'), 0),
                'disposed' => round((float) $in->reject(fn ($w) => $w->isDiverted())->sum('weight_kg'), 0)];
        }

        return $out;
    }

    // ───────────────────────────── refrigerants ─────────────────────────────

    public static function saveRefrigerant(array $d, User $by): EsgRefrigerantLog
    {
        self::need(self::canRecord($by));
        self::need(isset(EsgRefrigerantLog::GASES[$d['gas'] ?? '']), 'Choose the refrigerant.');
        self::need(isset(EsgRefrigerantLog::REASONS[$d['reason'] ?? '']), 'Choose why gas was added or recovered.');
        self::need(filled($d['equipment'] ?? null), 'Name the equipment / system.');
        $date = self::date($d['date'] ?? null, 'date');
        self::need($date <= self::today(), 'The date cannot be after the business date.');
        $add = round(self::num($d['kg_added'] ?? 0, 0, 5000, 'Gas added is 0 to 5,000 kg.'), 3);
        $rec = round(self::num($d['kg_recovered'] ?? 0, 0, 5000, 'Gas recovered is 0 to 5,000 kg.'), 3);
        self::need($add > 0 || $rec > 0, 'Enter the gas added or recovered.');
        self::need($d['reason'] !== 'decommission' || $rec > 0, 'A decommissioned system must record the gas recovered.');
        $charge = filled($d['system_charge_kg'] ?? null) ? round(self::num($d['system_charge_kg'], 0.01, 5000, 'The system charge is 0.01 to 5,000 kg.'), 2) : null;
        $r = EsgRefrigerantLog::create(['number' => Numbering::next('esg_refrigerant'), 'date' => $date, 'asset_id' => $d['asset_id'] ?? null, 'equipment' => self::text($d['equipment'], 120),
            'gas' => $d['gas'], 'system_charge_kg' => $charge, 'kg_added' => $add, 'kg_recovered' => $rec, 'reason' => $d['reason'], 'technician' => self::text($d['technician'] ?? null, 120),
            'certificate_no' => self::text($d['certificate_no'] ?? null, 60), 'maintenance_request_id' => $d['maintenance_request_id'] ?? null, 'notes' => self::text($d['notes'] ?? null), 'user_id' => $by->id]);
        ActivityLogger::log('esg', 'refrigerant', "F-gas {$r->number}: {$r->equipment} +{$add} kg {$r->gas} (".number_format($r->co2e(), 0).' kg CO₂e)', $r);

        return $r;
    }

    /** annual leak rate per system: gas replaced in the last 12 months ÷ system charge */
    public static function leakRates(): array
    {
        $from = Carbon::parse(self::today())->subYear()->addDay()->toDateString();
        $logs = EsgRefrigerantLog::where('date', '>=', $from)->orderBy('date')->get();
        $all = EsgRefrigerantLog::orderByDesc('date')->get()->groupBy(fn ($r) => mb_strtolower($r->equipment));
        $alert = self::cfg()['leak_alert_pct'];
        $out = [];
        foreach ($logs->groupBy(fn ($r) => mb_strtolower($r->equipment)) as $k => $g) {
            $charge = (float) ($all[$k]->firstWhere('system_charge_kg', '!=', null)?->system_charge_kg ?? 0);
            $emitted = $g->sum(fn ($r) => $r->leakedKg());
            $rate = $charge > 0 && ! $g->contains('reason', 'decommission') ? round($emitted / $charge * 100, 1) : null;
            $out[] = ['equipment' => $g->first()->equipment, 'gas' => $g->last()->gas, 'charge' => $charge ?: null, 'added' => round($emitted, 2), 'co2' => round($g->sum(fn ($r) => $r->co2e()), 0),
                'rate' => $rate, 'events' => $g->count(), 'flag' => $rate !== null && $rate > $alert, 'last' => $g->last()->date];
        }

        return collect($out)->sortByDesc(fn ($r) => $r['rate'] ?? -1)->values()->all();
    }

    // ───────────────────────────── laundry ─────────────────────────────

    public static function saveLaundry(array $d, User $by): EsgLaundryMonth
    {
        self::need(self::canManage($by));
        $m = self::date(($d['month'] ?? '') !== '' && strlen($d['month']) === 7 ? $d['month'].'-01' : ($d['month'] ?? null), 'month');
        $m = Carbon::parse($m)->startOfMonth();
        self::need($m->lte(Carbon::parse(self::today())->startOfMonth()), 'The month cannot be in the future.');
        $kg = round(self::num($d['kg'] ?? null, 0, 1000000, 'Linen processed is 0 to 1,000,000 kg.'), 1);
        $row = EsgLaundryMonth::updateOrCreate(['month' => $m->toDateString()], ['kg' => $kg, 'supplier_id' => $d['supplier_id'] ?? null,
            'kwh' => filled($d['kwh'] ?? null) ? round(self::num($d['kwh'], 0, 10000000, 'Energy is not valid.'), 1) : null, 'notes' => self::text($d['notes'] ?? null), 'user_id' => $by->id]);
        ActivityLogger::log('esg', 'laundry', 'Outsourced laundry '.$m->format('M Y').': '.number_format($kg, 1).' kg', $row);

        return $row;
    }

    // ───────────────────────────── targets ─────────────────────────────

    /** current value of a metric for a period (defaults to the benchmark window) */
    public static function metric(string $metric, ?string $from = null, ?string $to = null): ?float
    {
        if (! $from) {
            [$from, $to] = self::window();
        }
        $key = "m{$metric}{$from}{$to}".Tenant::id();
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $v = match ($metric) {
            'co2_room' => self::hcmi($from, $to)['per_room'],
            'co2_total' => round(self::carbon($from, $to)['total_kg'] / 1000 * 365 / (Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1), 1),
            'kwh_room' => self::hcmi($from, $to)['kwh_room'],
            'water_room' => self::hcmi($from, $to)['hwmi_room'],
            'waste_room' => self::waste(self::yearAgo($to), $to)['per_room'],
            'diversion' => self::waste(self::yearAgo($to), $to)['diversion'],
            'renewable' => ($cb = self::carbon($from, $to))['grid_kwh'] + $cb['solar_kwh'] > 0 ? round($cb['solar_kwh'] / ($cb['grid_kwh'] + $cb['solar_kwh']) * 100, 1) : null,
            'local_spend' => self::localSpend(self::yearAgo($to), $to)['pct'],
            'female_mgmt' => self::social(self::yearAgo($to), $to)['female_mgmt_pct'],
            'training_hours' => self::social(self::yearAgo($to), $to)['training_per_emp'],
            default => null,
        };

        return self::$memo[$key] = $v;
    }

    protected static function yearAgo(string $to): string
    {
        return Carbon::parse($to)->subYear()->addDay()->toDateString();
    }

    public static function saveTarget(array $d, User $by, ?EsgTarget $t = null): EsgTarget
    {
        self::need(self::canManage($by));
        self::need(isset(self::METRICS[$d['metric'] ?? '']), 'Choose the metric.');
        $by1 = (int) ($d['baseline_year'] ?? 0);
        $ty = (int) ($d['target_year'] ?? 0);
        self::need($by1 >= 2000 && $by1 <= 2100 && $ty >= 2000 && $ty <= 2100, 'Enter the baseline and target years.');
        self::need($ty > $by1, 'The target year must be after the baseline year.');
        $bv = self::num($d['baseline_value'] ?? null, 0, 1e9, 'Enter the baseline value.');
        $tv = self::num($d['target_value'] ?? null, 0, 1e9, 'Enter the target value.');
        $better = self::METRICS[$d['metric']][2];
        self::need($better === 'lower' ? $tv < $bv : $tv > $bv, $better === 'lower' ? 'For this metric the target must be lower than the baseline.' : 'For this metric the target must be higher than the baseline.');
        self::need(in_array(self::METRICS[$d['metric']][1], ['%'], true) === false || ($bv <= 100 && $tv <= 100), 'Percentages cannot exceed 100.');
        self::need(! EsgTarget::where('metric', $d['metric'])->where('is_active', true)->when($t, fn ($q) => $q->whereKeyNot($t->id))->exists() || empty($d['is_active'] ?? true),
            'There is already an active target for this metric – edit or retire it first.');
        $data = ['metric' => $d['metric'], 'baseline_year' => $by1, 'baseline_value' => $bv, 'target_year' => $ty, 'target_value' => $tv, 'notes' => self::text($d['notes'] ?? null),
            'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active']), 'user_id' => $by->id];
        $t ? $t->update($data) : $t = EsgTarget::create($data);
        ActivityLogger::log('esg', 'target', 'Target: '.self::METRICS[$t->metric][0]." {$bv} ({$by1}) → {$tv} ({$ty})", $t);

        return $t;
    }

    /** progress of each active target: achieved / on track / behind, against a straight line from the baseline year to the target year */
    public static function targets(): array
    {
        $year = (int) bdate()->year;
        $frac = (bdate()->dayOfYear - 1) / (bdate()->isLeapYear() ? 366 : 365);

        return EsgTarget::where('is_active', true)->orderBy('target_year')->get()->map(function (EsgTarget $t) use ($year, $frac) {
            [$label, $unit, $better, $dp] = self::METRICS[$t->metric];
            $cur = self::metric($t->metric);
            $span = $t->baseline_value - $t->target_value;
            $progress = $cur !== null && abs($span) > 1e-9 ? round(($t->baseline_value - $cur) / $span * 100, 0) : null;
            $elapsed = max(0, min(1, ($year + $frac - $t->baseline_year - 1) / max(1, $t->target_year - $t->baseline_year)));
            $expected = $t->baseline_value - $span * $elapsed;
            $state = match (true) {
                $cur === null => 'no_data',
                $progress >= 100 => 'achieved',
                ($better === 'lower' ? $cur <= $expected * 1.02 : $cur >= $expected * 0.98) => 'on_track',
                default => 'behind',
            };

            return ['target' => $t, 'label' => $label, 'unit' => $unit, 'dp' => $dp, 'current' => $cur, 'progress' => $progress, 'expected' => round($expected, $dp), 'state' => $state];
        })->all();
    }

    // ───────────────────────────── initiatives ─────────────────────────────

    public static function saveInitiative(array $d, User $by, ?EsgInitiative $i = null): EsgInitiative
    {
        self::need(self::canRecord($by));
        self::need(! $i || in_array($i->status, ['idea', 'approved', 'in_progress'], true), 'A closed initiative cannot be edited.');
        self::need(! $i || $i->status === 'idea' || self::canManage($by), 'Only an ESG manager can change an approved initiative.');
        self::need(filled($d['title'] ?? null), 'Give the initiative a title.');
        self::need(isset(EsgInitiative::CATEGORIES[$d['category'] ?? '']), 'Choose the category.');
        $c = self::cfg();
        $kwh = self::num($d['saving_kwh'] ?? 0, 0, 1e8, 'The energy saving is not valid.');
        $m3 = self::num($d['saving_m3'] ?? 0, 0, 1e8, 'The water saving is not valid.');
        $wkg = self::num($d['saving_waste_kg'] ?? 0, 0, 1e8, 'The waste saving is not valid.');
        $co2 = filled($d['saving_co2_kg'] ?? null) && (float) $d['saving_co2_kg'] > 0 ? self::num($d['saving_co2_kg'], 0, 1e9, 'The carbon saving is not valid.')
            : $kwh * $c['grid'] + $m3 * ($c['ef_water'] + $c['ef_wastewater']) + $wkg * max(0, $c['ef_waste']['landfill'] - $c['ef_waste']['recycling']);
        $start = self::date($d['start_on'] ?? null, 'start date', false);
        $due = self::date($d['due_on'] ?? null, 'due date', false);
        self::need(! $start || ! $due || $due >= $start, 'The due date is before the start date.');
        $data = ['title' => self::text($d['title'], 150), 'category' => $d['category'], 'description' => self::text($d['description'] ?? null, 5000), 'owner_user_id' => $d['owner_user_id'] ?? null,
            'capex' => round(self::num($d['capex'] ?? 0, 0, 1e10, 'The capital cost is not valid.'), 2), 'saving_ghs' => round(self::num($d['saving_ghs'] ?? 0, 0, 1e10, 'The annual saving is not valid.'), 2),
            'saving_kwh' => round($kwh, 1), 'saving_m3' => round($m3, 1), 'saving_waste_kg' => round($wkg, 1), 'saving_co2_kg' => round($co2, 1), 'start_on' => $start, 'due_on' => $due];
        if ($i) {
            $i->update($data);
        } else {
            $i = EsgInitiative::create($data + ['number' => Numbering::next('esg_initiative'), 'status' => 'idea', 'raised_by' => $by->id]);
        }
        ActivityLogger::log('esg', 'initiative', "Initiative {$i->number} {$i->title} saved", $i);

        return $i;
    }

    public static function moveInitiative(EsgInitiative $i, string $action, User $by, array $d = []): EsgInitiative
    {
        $note = self::text($d['note'] ?? null);
        switch ($action) {
            case 'approve':
            case 'reject':
                self::need(self::canManage($by), 'Only an ESG manager can decide on an initiative.');
                self::need($i->status === 'idea', 'Only a proposed initiative can be approved or rejected.');
                self::need(! self::cfg()['four_eyes'] || $i->raised_by !== $by->id, 'Someone other than the person who proposed it must approve or reject the initiative.');
                self::need($action === 'approve' || filled($note), 'Give a reason for rejecting.');
                $i->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'approved_by' => $by->id, 'approved_at' => now(), 'decision_note' => $note]);
                break;
            case 'start':
                self::need(self::canRecord($by));
                self::need($i->status === 'approved', 'Only an approved initiative can start.');
                $i->update(['status' => 'in_progress', 'start_on' => $i->start_on ?? self::today()]);
                break;
            case 'complete':
                self::need(self::canManage($by) || $i->owner_user_id === $by->id, 'Only the owner or an ESG manager can complete an initiative.');
                self::need(in_array($i->status, ['approved', 'in_progress'], true), 'Only an approved or running initiative can be completed.');
                self::need(filled($d['outcome'] ?? null), 'Describe the outcome achieved.');
                $on = self::date($d['completed_on'] ?? self::today(), 'completion date');
                self::need($on <= self::today(), 'The completion date cannot be in the future.');
                $i->update(['status' => 'done', 'completed_on' => $on, 'outcome' => self::text($d['outcome'], 500), 'start_on' => $i->start_on ?? $on]);
                break;
            case 'cancel':
                self::need(self::canManage($by), 'Only an ESG manager can cancel an initiative.');
                self::need(in_array($i->status, ['idea', 'approved', 'in_progress'], true), 'This initiative is already closed.');
                self::need(filled($note), 'Give a reason for cancelling.');
                $i->update(['status' => 'cancelled', 'decision_note' => $note]);
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
        ActivityLogger::log('esg', 'initiative_'.$action, "Initiative {$i->number} → {$i->statusLabel()}".($note ? ": {$note}" : ''), $i);

        return $i;
    }

    // ───────────────────────────── community ─────────────────────────────

    public static function saveCommunity(array $d, User $by, $file = null): EsgCommunity
    {
        self::need(self::canRecord($by));
        self::need(isset(EsgCommunity::KINDS[$d['kind'] ?? '']), 'Choose the type of contribution.');
        self::need(filled($d['beneficiary'] ?? null), 'Name the beneficiary / project.');
        $date = self::date($d['date'] ?? null, 'date');
        self::need($date <= self::today(), 'The date cannot be after the business date.');
        $amt = round(self::num($d['amount'] ?? 0, 0, 1e9, 'The amount is not valid.'), 2);
        $hours = round(self::num($d['hours'] ?? 0, 0, 100000, 'Volunteering hours are not valid.'), 1);
        self::need($amt > 0 || $hours > 0, 'Enter the value given or the volunteering hours.');
        self::need($d['kind'] !== 'volunteering' || $hours > 0, 'Enter the volunteering hours.');
        $row = new EsgCommunity(['number' => Numbering::next('esg_community'), 'date' => $date, 'kind' => $d['kind'], 'beneficiary' => self::text($d['beneficiary'], 150),
            'description' => self::text($d['description'] ?? null, 500), 'amount' => $amt, 'hours' => $hours, 'volunteers' => (int) self::num($d['volunteers'] ?? 0, 0, 5000, 'Volunteers is not valid.'),
            'people_reached' => (int) self::num($d['people_reached'] ?? 0, 0, 10000000, 'People reached is not valid.'), 'user_id' => $by->id]);
        if ($file) {
            $row->evidence_path = $file->store('esg/'.Tenant::id(), 'local');
            $row->evidence_name = mb_substr($file->getClientOriginalName(), 0, 190);
        }
        $row->save();
        ActivityLogger::log('esg', 'community', "Community {$row->number}: {$row->kindLabel()} – {$row->beneficiary} ".money($amt).($hours ? " · {$hours} h" : ''), $row);

        return $row;
    }

    public static function community(string $from, string $to): array
    {
        $l = EsgCommunity::whereBetween('date', [$from, $to])->get();

        return ['count' => $l->count(), 'amount' => round((float) $l->sum('amount'), 2), 'cash' => round((float) $l->where('kind', 'donation')->sum('amount'), 2),
            'hours' => round((float) $l->sum('hours'), 1), 'people' => (int) $l->sum('people_reached'), 'by_kind' => $l->groupBy('kind')->map(fn ($g) => round((float) $g->sum('amount'), 2))->all()];
    }

    // ───────────────────────────── certification checklist ─────────────────────────────

    public static function assess(EsgChecklistItem $item, array $d, User $by): EsgChecklistItem
    {
        self::need(self::canManage($by));
        self::need(isset(EsgChecklistItem::STATUSES[$d['status'] ?? '']), 'Choose the status.');
        self::need(! ($d['status'] === 'na' && $item->is_mandatory), 'A mandatory requirement cannot be marked not applicable.');
        self::need(! in_array($d['status'], ['met', 'partial'], true) || filled($d['evidence'] ?? null), 'Record the evidence that the requirement is met.');
        $item->update(['status' => $d['status'], 'evidence' => self::text($d['evidence'] ?? null, 500), 'owner_user_id' => $d['owner_user_id'] ?? $item->owner_user_id,
            'reviewed_on' => self::today(), 'reviewed_by' => $by->id]);
        ActivityLogger::log('esg', 'checklist', "Certification {$item->code} → ".EsgChecklistItem::STATUSES[$item->status][0], $item);

        return $item;
    }

    /** score = (met + ½ partial) ÷ applicable; ready when every mandatory item is met */
    public static function certScore(): array
    {
        $items = EsgChecklistItem::orderBy('sort')->get();
        $app = $items->where('status', '!=', 'na');
        $pts = $app->where('status', 'met')->count() + 0.5 * $app->where('status', 'partial')->count();
        $mand = $items->where('is_mandatory', true);

        return ['score' => $app->count() ? round($pts / $app->count() * 100, 0) : null, 'met' => $items->where('status', 'met')->count(), 'partial' => $items->where('status', 'partial')->count(),
            'not' => $items->where('status', 'not')->count(), 'na' => $items->where('status', 'na')->count(), 'total' => $items->count(),
            'mandatory' => $mand->count(), 'mandatory_met' => $mand->where('status', 'met')->count(), 'ready' => $mand->count() > 0 && $mand->every(fn ($i) => $i->status === 'met'),
            'sections' => $items->groupBy('section')->map(function ($g) {
                $a = $g->where('status', '!=', 'na');

                return $a->count() ? round(($a->where('status', 'met')->count() + 0.5 * $a->where('status', 'partial')->count()) / $a->count() * 100, 0) : null;
            })->all()];
    }

    // ───────────────────────────── policies ─────────────────────────────

    public static function savePolicy(array $d, User $by, ?EsgPolicy $p = null, $file = null): EsgPolicy
    {
        self::need(self::canManage($by));
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        self::need($code !== '' && strlen($code) <= 20, 'Enter a policy code (up to 20 characters).');
        self::need(! EsgPolicy::where('code', $code)->when($p, fn ($q) => $q->whereKeyNot($p->id))->exists(), "Policy code {$code} is already used.");
        self::need(filled($d['title'] ?? null), 'Enter the policy title.');
        self::need(isset(EsgPolicy::PILLARS[$d['pillar'] ?? '']), 'Choose the ESG pillar.');
        self::need(in_array($d['status'] ?? '', ['draft', 'active', 'retired'], true), 'Choose the status.');
        $months = (int) self::num($d['review_months'] ?? 12, 1, 60, 'The review cycle is 1 to 60 months.');
        $approved = self::date($d['approved_on'] ?? null, 'approval date', $d['status'] === 'active');
        self::need(! $approved || $approved <= self::today(), 'The approval date cannot be in the future.');
        self::need($d['status'] !== 'active' || filled($d['approved_by_name'] ?? null), 'Record who approved the policy.');
        $data = ['code' => $code, 'title' => self::text($d['title'], 150), 'pillar' => $d['pillar'], 'owner_user_id' => $d['owner_user_id'] ?? null, 'version' => self::text($d['version'] ?? '1.0', 10) ?? '1.0',
            'approved_on' => $approved, 'approved_by_name' => self::text($d['approved_by_name'] ?? null, 120), 'review_months' => $months,
            'next_review_on' => $approved ? Carbon::parse($approved)->addMonthsNoOverflow($months)->toDateString() : null, 'status' => $d['status'], 'summary' => self::text($d['summary'] ?? null, 500)];
        if ($file) {
            $data['file_path'] = $file->store('esg/'.Tenant::id(), 'local');
            $data['file_name'] = mb_substr($file->getClientOriginalName(), 0, 190);
        }
        $p ? $p->update($data) : $p = EsgPolicy::create($data);
        ActivityLogger::log('esg', 'policy', "Policy {$p->code} {$p->title} v{$p->version} ({$p->status})", $p);

        return $p;
    }

    public static function governance(): array
    {
        $pol = EsgPolicy::get();
        $active = $pol->where('status', 'active');
        $from = Carbon::parse(self::today())->subYear()->addDay();

        return ['policies' => $pol->count(), 'active' => $active->count(), 'overdue' => $active->filter(fn ($p) => $p->isOverdue())->count(), 'draft' => $pol->where('status', 'draft')->count(),
            'current_pct' => $active->count() ? round($active->reject(fn ($p) => $p->isOverdue())->count() / $active->count() * 100, 0) : null,
            'breaches' => DataBreach::where('detected_at', '>=', $from)->count(),
            'compliance_expired' => ComplianceItem::whereNotNull('expires_on')->where('expires_on', '<', self::today())->whereNotIn('status', ['cancelled', 'closed', 'renewed'])->count(),
            'compliance_total' => ComplianceItem::count(), 'cert' => self::certScore()];
    }

    // ───────────────────────────── people & sourcing ─────────────────────────────

    public static function isManagement(Employee $e): bool
    {
        return (bool) preg_match('/manager|director|head |head$|^head|executive chef|controller|chief/i', (string) $e->designation);
    }

    /** social indicators for a period (headcount at the end of it) */
    public static function social(string $from, string $to): array
    {
        $key = "soc{$from}{$to}".Tenant::id();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $emps = Employee::get();
        $inPost = fn ($e, $on) => (! $e->joining_date || $e->joining_date->toDateString() <= $on) && ($e->leaving_date ? $e->leaving_date->toDateString() >= $on : $e->is_active);
        $now = $emps->filter(fn ($e) => $inPost($e, $to));
        $start = $emps->filter(fn ($e) => $inPost($e, $from));
        $mgmt = $now->filter(fn ($e) => self::isManagement($e));
        $f = $now->where('gender', 'F');
        $m = $now->where('gender', 'M');
        $ghana = fn ($e) => $e->nationality === null ? null : (bool) preg_match('/^(ghana|ghanaian|gh)$/i', trim($e->nationality));
        $hires = $emps->filter(fn ($e) => $e->joining_date && $e->joining_date->toDateString() >= $from && $e->joining_date->toDateString() <= $to);
        $leavers = $emps->filter(fn ($e) => $e->leaving_date && $e->leaving_date->toDateString() >= $from && $e->leaving_date->toDateString() <= $to);
        $avg = ($now->count() + $start->count()) / 2;
        $hours = (float) TrainingRecord::join('training_courses as tc', 'tc.id', '=', 'training_records.training_course_id')->where('training_records.status', 'passed')
            ->whereBetween('training_records.completed_on', [$from, $to])->sum('tc.duration_hours');
        $months = max(1, Carbon::parse($from)->diffInMonths(Carbon::parse($to)->addDay()));
        $worked = $avg * HseService::cfg()['hours_month'] * $months;
        $lti = HseAccident::where('person_type', 'employee')->where('lost_days', '>', 0)->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])->count();
        $min = StatutoryService::minWageMonthly();
        $below = $now->filter(fn ($e) => $e->salary !== null && (float) $e->salary > 0 && (float) $e->salary < $min);
        $avgF = $f->where('salary', '>', 0)->avg(fn ($e) => (float) $e->salary);
        $avgM = $m->where('salary', '>', 0)->avg(fn ($e) => (float) $e->salary);
        $known = $now->filter(fn ($e) => $ghana($e) !== null);

        return self::$memo[$key] = ['headcount' => $now->count(), 'female' => $f->count(), 'male' => $m->count(), 'gender_unknown' => $now->count() - $f->count() - $m->count(),
            'female_pct' => $now->count() ? round($f->count() / $now->count() * 100, 1) : null,
            'management' => $mgmt->count(), 'female_mgmt' => $mgmt->where('gender', 'F')->count(), 'female_mgmt_pct' => $mgmt->count() ? round($mgmt->where('gender', 'F')->count() / $mgmt->count() * 100, 1) : null,
            'local_pct' => $known->count() ? round($known->filter(fn ($e) => $ghana($e))->count() / $known->count() * 100, 1) : null, 'nationality_known' => $known->count(),
            'hires' => $hires->count(), 'local_hires' => $hires->filter(fn ($e) => $ghana($e) === true)->count(),
            'local_hires_pct' => $hires->count() ? round($hires->filter(fn ($e) => $ghana($e) === true)->count() / $hires->count() * 100, 1) : null,
            'leavers' => $leavers->count(), 'turnover_pct' => $avg > 0 ? round($leavers->count() / $avg * 100, 1) : null,
            'training_hours' => round($hours, 1), 'training_per_emp' => $now->count() ? round($hours / $now->count(), 1) : null,
            'lti' => $lti, 'ltifr' => $worked > 0 ? round($lti * 1000000 / $worked, 2) : null, 'hours_worked' => round($worked),
            'min_wage' => $min, 'below_min' => $below->values(), 'disability' => $now->where('has_disability', true)->count(),
            'pay_gap_pct' => $avgF && $avgM ? round(($avgM - $avgF) / $avgM * 100, 1) : null,
            'by_dept' => $now->groupBy(fn ($e) => $e->department?->name ?? '—')->map(fn ($g) => ['n' => $g->count(), 'f' => $g->where('gender', 'F')->count()])->sortKeys()->all(),
            'community' => self::community($from, $to)];
    }

    /** supplier spend by local / non-local supplier (posted bills less debit notes) */
    public static function localSpend(string $from, string $to): array
    {
        $bills = SupplierInvoice::with('supplier')->where('status', '!=', 'cancelled')->whereDate('doc_date', '>=', $from)->whereDate('doc_date', '<=', $to)->get();
        $sum = fn ($g) => round($g->sum(fn ($b) => ($b->type === 'debit_note' ? -1 : 1) * (float) $b->subtotal), 2);
        $local = $sum($bills->filter(fn ($b) => $b->supplier?->is_local));
        $total = $sum($bills);
        $by = $bills->groupBy('supplier_id')->map(fn ($g) => ['name' => $g->first()->supplier?->name ?? '—', 'local' => (bool) $g->first()->supplier?->is_local, 'spend' => $sum($g), 'bills' => $g->count()])
            ->sortByDesc('spend')->values()->all();

        return ['total' => $total, 'local' => $local, 'pct' => $total > 0 ? round($local / $total * 100, 1) : null, 'suppliers' => $by];
    }

    // ───────────────────────────── guest green-stay choice ─────────────────────────────

    public static function activeGreen(ReservationRoom $s): ?EsgGreenStay
    {
        return EsgGreenStay::where('reservation_room_id', $s->id)->where('status', 'active')->first();
    }

    public static function optIn(ReservationRoom $s, string $source = 'desk', ?User $by = null): EsgGreenStay
    {
        $c = self::cfg();
        self::need($c['green_enabled'], 'The green-stay choice is switched off.');
        self::need($source === 'portal' || self::canGreen($by), 'You do not have permission to enrol guests in the green stay.');
        self::need($s->status === 'checked_in', 'Only an in-house stay can choose the green stay.');
        self::need(! self::activeGreen($s), 'This stay has already chosen the green stay.');
        $from = Carbon::parse(self::today())->addDay()->toDateString();
        self::need($s->departure_date->toDateString() > $from, 'The guest leaves before the next stay-over service – there is nothing to skip.');
        $g = EsgGreenStay::create(['reservation_room_id' => $s->id, 'guest_id' => $s->guest_id ?? $s->reservation?->guest_id, 'from_date' => $from, 'status' => 'active', 'source' => $source, 'user_id' => $by?->id]);
        ActivityLogger::log('esg', 'green_in', 'Green stay chosen for room '.($s->room?->room_no ?? '?')." from {$from} ({$source})", $g);

        return $g;
    }

    public static function optOut(EsgGreenStay $g, string $source = 'desk', ?User $by = null): void
    {
        self::need($source === 'portal' || self::canGreen($by), 'You do not have permission to change the green stay.');
        self::need($g->status === 'active', 'This green stay has already ended.');
        $g->update(['status' => 'ended', 'ended_on' => self::today()]);
        ActivityLogger::log('esg', 'green_out', 'Green stay ended for stay #'.$g->reservation_room_id." ({$source})", $g);
    }

    /** is the stay-over clean skipped for this stay on this date? (full service every N nights; departure day always cleaned) */
    public static function skipsService(?ReservationRoom $s, string $date): bool
    {
        if (! $s || ! self::cfg()['green_enabled'] || $s->departure_date->toDateString() <= $date) {
            return false;
        }
        $g = self::activeGreen($s);
        if (! $g || $g->from_date->toDateString() > $date) {
            return false;
        }
        $n = (int) $g->from_date->diffInDays(Carbon::parse($date));

        return ($n + 1) % self::cfg()['green_every'] !== 0;
    }

    /** night audit: log each green night, award loyalty points, close green stays of departed guests */
    public static function nightly(string $date): int
    {
        $c = self::cfg();
        $n = 0;
        foreach (EsgGreenStay::with('stay.room', 'stay.reservation')->where('status', 'active')->get() as $g) {
            $s = $g->stay;
            if (! $s || in_array($s->status, ['checked_out', 'cancelled', 'no_show'], true)) {
                $g->update(['status' => 'ended', 'ended_on' => $s?->departure_date?->toDateString() ?? $date]);

                continue;
            }
            if ($s->status !== 'checked_in' || ! self::skipsService($s, $date)) {
                continue;
            }
            // the guest asked for (and got) a clean anyway → not a green night
            $cleaned = HousekeepingTask::where('room_id', $s->room_id)->where('task_date', $date)->where('task_type', 'cleaning')->where('status', 'completed')->exists();
            if ($cleaned || EsgGreenNight::where('business_date', $date)->where('reservation_room_id', $s->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($g, $s, $date, $c, &$n) {
                $guestId = $g->guest_id ?? $s->guest_id ?? $s->reservation?->guest_id;
                $pts = $guestId && LoyaltyService::enabled() ? $c['green_points'] : 0;
                EsgGreenNight::create(['esg_green_stay_id' => $g->id, 'reservation_room_id' => $s->id, 'guest_id' => $guestId, 'room_id' => $s->room_id, 'business_date' => $date,
                    'points' => $pts, 'kwh' => $c['green_kwh'], 'litres' => $c['green_litres'],
                    'co2_kg' => round($c['green_kwh'] * $c['grid'] + $c['green_litres'] / 1000 * ($c['ef_water'] + $c['ef_wastewater']), 2)]);
                if ($pts > 0) {
                    LoyaltyEntry::create(['type' => 'green', 'guest_id' => $guestId, 'points' => $pts, 'description' => 'Green stay – room '.($s->room?->room_no ?? '').' not serviced on '.Carbon::parse($date)->format('d M Y')]);
                    Guest::whereKey($guestId)->increment('loyalty_points', $pts);
                }
                $n++;
            });
        }

        return $n;
    }

    public static function greenStats(string $from, string $to): array
    {
        $l = EsgGreenNight::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get();
        $rooms = array_sum(array_column(UtilityService::occupancy($from, $to), 'rooms'));

        return ['nights' => $l->count(), 'points' => (int) $l->sum('points'), 'kwh' => round($l->sum('kwh'), 1), 'litres' => round($l->sum('litres'), 0), 'co2' => round($l->sum('co2_kg'), 1),
            'guests' => $l->pluck('reservation_room_id')->unique()->count(), 'uptake' => $rooms ? round($l->count() / $rooms * 100, 1) : null,
            'active' => EsgGreenStay::where('status', 'active')->count()];
    }

    // ───────────────────────────── dashboard ─────────────────────────────

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= (function () {
            $leaks = collect(self::leakRates())->where('flag', true)->count();

            return ['policies_overdue' => EsgPolicy::where('status', 'active')->whereNotNull('next_review_on')->where('next_review_on', '<', self::today())->count(),
                'initiatives_pending' => EsgInitiative::where('status', 'idea')->count(),
                'initiatives_late' => EsgInitiative::whereIn('status', ['approved', 'in_progress'])->whereNotNull('due_on')->where('due_on', '<', self::today())->count(),
                'leaks' => $leaks,
                'targets_behind' => collect(self::targets())->where('state', 'behind')->count(),
                'mandatory_open' => EsgChecklistItem::where('is_mandatory', true)->where('status', '!=', 'met')->count(),
                'waste_stale' => EsgWasteLog::exists() && ! EsgWasteLog::live()->where('date', '>=', Carbon::parse(self::today())->subDays(14)->toDateString())->exists()];
        })();
    }

    /** nav badge: policies past review, proposals waiting for a decision, systems leaking above the alert rate */
    public static function attentionCount(): int
    {
        if (! can_do('esg.view')) {
            return 0;
        }
        $a = self::alerts();

        return $a['policies_overdue'] + (can_do('esg.manage') ? $a['initiatives_pending'] : 0) + $a['leaks'];
    }

    /** carbon by scope per month for the last 12 months */
    public static function carbonTrend(): array
    {
        $out = [];
        $start = bdate()->copy()->startOfMonth()->subMonths(11);
        $last = Carbon::parse(self::today())->subDay();
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $to = min($m->copy()->endOfMonth()->toDateString(), $last->toDateString());
            if ($to < $m->toDateString()) {
                $out[] = ['label' => $m->format('M'), 's1' => 0, 's2' => 0, 's3' => 0];

                continue;
            }
            $c = self::carbon($m->toDateString(), $to);
            $has = $c['kwh'] > 0 || $c['waste_kg'] > 0 || $c['scopes'][1] > 0;
            $out[] = ['label' => $m->format('M'), 's1' => $has ? $c['scopes'][1] : 0, 's2' => $has ? $c['scopes'][2] : 0, 's3' => $has ? $c['scopes'][3] : 0];
        }

        return $out;
    }

    /** annual ESG report data for a calendar year (to the day before the business date when the year is running) */
    public static function annual(int $year): array
    {
        $from = "{$year}-01-01";
        $to = min("{$year}-12-31", Carbon::parse(self::today())->subDay()->toDateString());
        self::need($to >= $from, 'That year has not started yet.');
        $first = MeterReading::whereDate('reading_date', '>=', $from)->whereDate('reading_date', '<=', $to)->min('reading_date');
        $envFrom = $first ? max($from, Carbon::parse($first)->toDateString()) : $from;

        return ['year' => $year, 'from' => $from, 'to' => $to, 'env_from' => $envFrom, 'carbon' => self::carbon($envFrom, $to), 'hcmi' => self::hcmi($envFrom, $to),
            'waste' => self::waste($from, $to), 'leaks' => self::leakRates(), 'green' => self::greenStats($from, $to), 'social' => self::social($from, $to),
            'sourcing' => self::localSpend($from, $to), 'gov' => self::governance(), 'targets' => self::targets(),
            'initiatives' => EsgInitiative::where(fn ($q) => $q->whereYear('completed_on', $year)->orWhereIn('status', ['approved', 'in_progress']))->orderBy('status')->orderBy('number')->get(),
            'community_list' => EsgCommunity::whereBetween('date', [$from, $to])->orderBy('date')->get()];
    }
}
