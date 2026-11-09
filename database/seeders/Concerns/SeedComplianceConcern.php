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
trait SeedComplianceConcern
{
    protected function seedHse(bool $rich): void
    {
        $HS = \App\Services\HseService::class;
        $HS::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $hse = $u('hse') ?? $admin;
        $eng = $u('engineer') ?? $admin;
        $chef = $u('chef') ?? $admin;
        $fom = $u('fom') ?? $admin;
        $hk = $u('housekeeping') ?? $admin;
        Auth::setUser($hse);
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();
        $E = fn (string $name) => Models\Employee::where('name', $name)->value('id');

        // ---- fire & life-safety equipment register (AFCAS / GNFS tags)
        $items = [];
        foreach ([1, 2, 3] as $f) {
            $items[] = ['ext_co2', "Floor {$f} – lift lobby", (string) $f, '5 kg'];
            $items[] = ['ext_powder', "Floor {$f} – east stairwell", (string) $f, '9 kg'];
            $items[] = ['hose_reel', "Floor {$f} – corridor by room {$f}05", (string) $f, '30 m'];
            $items[] = ['detector', "Floor {$f} – corridor smoke heads", (string) $f, 'optical ×8'];
            $items[] = ['call_point', "Floor {$f} – east stair door", (string) $f, null];
            $items[] = ['emergency_light', "Floor {$f} – stairwell & exit signs", (string) $f, '3 h'];
            $items[] = ['fire_door', "Floor {$f} – east stairwell door", (string) $f, 'FD30'];
        }
        array_push($items,
            ['ext_co2', 'Reception – behind the front desk', 'G', '5 kg'], ['ext_powder', 'Lobby – main entrance', 'G', '9 kg'], ['hose_reel', 'Lobby – by the lifts', 'G', '30 m'],
            ['alarm_panel', 'Reception back office – main fire alarm panel', 'G', '12-zone'], ['call_point', 'Main entrance', 'G', null], ['emergency_light', 'Lobby & restaurant exits', 'G', '3 h'],
            ['detector', 'Restaurant & bar', 'G', 'optical ×10'], ['first_aid', 'Front desk', 'G', 'large'], ['aed', 'Lobby – beside the concierge desk', 'G', null],
            ['ext_wet', 'Kitchen – beside the fryers', 'G', '6 L'], ['ext_co2', 'Kitchen – pass / electrical board', 'G', '5 kg'], ['blanket', 'Kitchen – cook line', 'G', '1.2 × 1.2 m'],
            ['first_aid', 'Kitchen – chef\'s office', 'G', 'catering (blue plasters)'], ['eyewash', 'Kitchen – dishwash chemical store', 'G', null], ['fire_door', 'Kitchen – door to the restaurant', 'G', 'FD30'],
            ['lpg', 'Kitchen yard – LPG cylinder bank', 'G', '6 × 47 kg'], ['hydrant', 'Front car park – pillar hydrant', 'G', '2 × 65 mm'],
            ['ext_co2', 'Generator room', 'B1', '5 kg'], ['ext_powder', 'Generator room – fuel day tank', 'B1', '9 kg'], ['sprinkler', 'Pump room – sprinkler valve set', 'B1', 'wet riser'],
            ['first_aid', 'Engineering workshop', 'B1', 'medium'], ['emergency_light', 'Basement corridor & plant rooms', 'B1', '3 h'],
            ['ext_co2', 'Spa reception', 'G', '2 kg'], ['first_aid', 'Spa & pool', 'G', 'medium']);
        $eq = [];
        foreach ($items as $i => [$type, $loc, $floor, $cap]) {
            [, , $check, $service] = Models\HseEquipment::TYPES[$type];
            $eq[] = Models\HseEquipment::create(['code' => $HS::nextCode($type), 'type' => $type, 'location' => $loc, 'floor' => $floor, 'capacity' => $cap,
                'gnfs_tag' => in_array($type, ['ext_co2', 'ext_powder', 'ext_wet', 'hose_reel', 'hydrant', 'alarm_panel', 'fire_door', 'lpg', 'sprinkler'], true) ? sprintf('GNFS-GA-%06d', 418200 + $i * 37) : null,
                'serial' => in_array($type, ['ext_co2', 'ext_powder', 'ext_wet', 'aed', 'alarm_panel'], true) ? strtoupper(substr($type, 0, 3)).'-'.(22000 + $i * 113) : null,
                'installed_on' => $d(-900 + $i * 7), 'check_days' => $check, 'service_days' => $service,
                'expires_on' => match ($type) { 'first_aid' => $d(120 + $i), 'aed' => $d(40), 'ext_co2', 'ext_powder', 'ext_wet' => $d(1400 + $i * 20), default => null }]);
        }
        $by = fn (string $loc) => collect($eq)->first(fn ($e) => $e->location === $loc);
        // Annual contractor service: most in March; hose reels, hydrant and sprinklers last October → due again this month
        $svc = fn ($e) => in_array($e->type, ['hose_reel', 'hydrant', 'sprinkler'], true) ? '2025-10-20' : '2026-03-10';
        foreach ($eq as $e) {
            if ($e->service_days > 0 && $svc($e) <= $d(0)) {
                $on = $svc($e);
                Models\HseCheck::create(['hse_equipment_id' => $e->id, 'kind' => 'service', 'checked_on' => $on, 'result' => 'pass', 'contractor' => 'Fire Guard Ghana Ltd',
                    'certificate_no' => 'FGG/'.substr($on, 0, 4).'/'.(1400 + $e->id), 'notes' => 'Annual service to GS / ISO 7165; GNFS tag renewed', 'user_id' => $hse->id]);
                $e->update(['last_serviced_on' => $on, 'next_service_on' => \Carbon\Carbon::parse($on)->addDays($e->service_days)->toDateString()]);
            }
            // visual checks: monthly items on the round every ~30 days; floor 3 was last walked 27 days ago; weekly items every 7 days
            $last = match (true) {
                $e->location === 'Generator room' => -41,                           // overdue
                $e->type === 'hydrant' => -97,                                      // overdue (90-day check)
                $e->location === 'Engineering workshop' => -36,                     // overdue
                $e->check_days === 7 => -3,
                $e->floor === '3' => -27,
                default => -12,
            };
            $step = $e->check_days;
            $dates = [];
            for ($k = 0; $k < ($step === 7 ? 12 : ($step >= 90 ? 1 : 3)); $k++) {
                $dates[] = $last - $k * $step;
            }
            foreach (array_reverse($dates) as $n) {
                Models\HseCheck::create(['hse_equipment_id' => $e->id, 'kind' => 'visual', 'checked_on' => $d($n), 'result' => 'pass', 'user_id' => ($n % 2 ? $eng : $hse)->id]);
            }
            $e->update(['last_checked_on' => $d($last), 'next_check_on' => $d($last + $step)]);
        }
        // Kitchen fire door failed 20 days ago (closer broken) → carpentry ticket, fixed and passed a week later
        $door = $by('Kitchen – door to the restaurant');
        $tk = Models\MaintenanceRequest::create(['ticket_no' => \App\Support\Numbering::next('ticket'), 'location' => $door->location, 'category' => 'carpentry',
            'title' => "Fire safety: {$door->code} Fire door defective", 'description' => 'Closes and latches by itself; Door closer arm snapped – door wedged open with a bin', 'priority' => 'high',
            'due_date' => $d(-18), 'status' => 'resolved', 'reported_by' => $hse->id, 'resolution' => 'New overhead closer fitted and adjusted', 'resolved_at' => $B->copy()->subDays(14)->setTime(15, 0)]);
        Models\HseCheck::where('hse_equipment_id', $door->id)->where('checked_on', $d(-12))->update(['checked_on' => $d(-13)]);
        Models\HseCheck::create(['hse_equipment_id' => $door->id, 'kind' => 'visual', 'checked_on' => $d(-20), 'result' => 'fail', 'faults' => ['Closes and latches by itself', 'Door closer arm snapped – door wedged open with a bin'],
            'user_id' => $hse->id, 'maintenance_request_id' => $tk->id]);
        // The first-aid kit in the kitchen is out of date
        $by('Kitchen – chef\'s office')->update(['expires_on' => $d(-5), 'notes' => 'Burn gel and eye-wash pods expired – reorder from stores']);
        // Today: the 2nd-floor stairwell emergency light fails its test → engineering ticket (auto)
        $HS::recordCheck($by('Floor 2 – stairwell & exit signs'), ['kind' => 'visual', 'checked_on' => $d(0), 'ok' => [1, 2], 'fault' => 'Landing fitting between floors 1 and 2 stays dark on test'], $eng);

        // ---- wardens & first aiders (night shift has no first aider; the plumber's fire-team training has lapsed)
        foreach ([['Ravi Kumar', 'chief', 'morning', 'Whole building', -200], ['Grace Mensah', 'first_aider', 'morning', 'Spa, pool & lobby', -300], ['Ramesh Gowda', 'floor', 'morning', 'Floors 1–2', -150],
            ['Mohan Lal', 'floor', 'morning', 'Kitchen & restaurant', -150], ['Deepa Nair', 'chief', 'evening', 'Whole building', -200], ['Efua Asante', 'first_aider', 'evening', 'Spa & lobby', -260],
            ['Abena Owusu', 'floor', 'evening', 'Floors 2–3', -150], ['Kiran Patil', 'chief', 'night', 'Whole building', -330], ['Joseph Thomas', 'fire_team', 'morning', 'Plant rooms & generator', -120],
            ['Imran Ali', 'fire_team', 'evening', 'Plant rooms & generator', -760]] as [$name, $role, $shift, $area, $on]) {
            if ($eid = $E($name)) {
                Models\HseWarden::create(['employee_id' => $eid, 'role' => $role, 'shift' => $shift, 'area' => $area, 'trained_on' => $d($on), 'trained_until' => $d($on + 730), 'is_active' => true]);
            }
        }

        // ---- drills (the night shift last evacuated ~7½ months ago → overdue)
        $staffIds = Models\Employee::where('is_active', true)->pluck('id')->all();
        $drill = function (array $row, int $take = 0) use ($staffIds, $hse) {
            $x = Models\HseDrill::create($row + ['number' => \App\Support\Numbering::next('hse_drill'), 'created_by' => $hse->id, 'assembly_point' => 'Front car park, by the flagpoles']);
            if ($take) {
                $x->staff()->sync(array_slice($staffIds, 0, $take));
            }

            return $x;
        };
        $at = fn (int $days, string $time) => $B->copy()->addDays($days)->setTimeFromTimeString($time);
        $done = fn (int $days, string $time, int $secs, int $target) => ['status' => 'done', 'scheduled_at' => $at($days, $time), 'alarm_at' => $at($days, $time), 'clear_at' => $at($days, $time)->addSeconds($secs),
            'evac_seconds' => $secs, 'target_seconds' => $target, 'result' => $secs <= $target ? 'pass' : 'fail'];
        $drill($done(-230, '02:10', 318, 300) + ['type' => 'fire_evacuation', 'shift' => 'night', 'announced' => false, 'scenario' => 'Smoke detector activation in the 1st-floor linen store at 02:10; lift out of use.',
            'staff_expected' => 12, 'staff_accounted' => 11, 'guests_evacuated' => 31, 'fire_service_attended' => true, 'result' => 'fail', 'led_by' => $hse->id,
            'observations' => "Alarm heard in all rooms. Night auditor took 90 s to find the guest list printout. One kitchen porter unaccounted for 4 min – he had left by the service yard.\nGNFS Ring Road station attended in 11 min.",
            'improvements' => "Print the in-house list automatically at 22:00 for the night grab bag.\nService-yard exit to report to the same assembly point – signage added."], 10);
        $drill($done(-150, '10:30', 222, 240) + ['type' => 'fire_evacuation', 'shift' => 'morning', 'announced' => true, 'scenario' => 'Fire in the kitchen extract duct; full evacuation.',
            'staff_expected' => 38, 'staff_accounted' => 38, 'guests_evacuated' => 22, 'led_by' => $hse->id, 'observations' => 'Good response; wardens swept every floor. Two guests in the spa needed robes and slippers.',
            'improvements' => 'Keep a stack of robes at the spa exit.'], 14);
        $drill($done(-120, '19:40', 295, 240) + ['type' => 'fire_partial', 'shift' => 'evening', 'announced' => true, 'scenario' => '2nd floor only – call point operated outside room 205.',
            'staff_expected' => 24, 'staff_accounted' => 24, 'guests_evacuated' => 14, 'led_by' => $fom->id, 'observations' => 'Restaurant staff waited for a manager before leaving. Fire door on floor 2 found wedged open.',
            'improvements' => "Brief F&B: leave on the alarm, don't wait.\nFire-door wedges removed; added to the monthly check."], 12);
        $drill(['type' => 'fire_evacuation', 'shift' => 'evening', 'status' => 'cancelled', 'scheduled_at' => $at(-60, '18:00'), 'target_seconds' => 240, 'led_by' => $fom->id,
            'cancel_reason' => 'Heavy rain – assembly point flooded; moved to the next evening slot']);
        $drill($done(-40, '11:00', 208, 240) + ['type' => 'fire_evacuation', 'shift' => 'morning', 'announced' => false, 'scenario' => 'Unannounced – smoke in the laundry dryer bank.',
            'staff_expected' => 41, 'staff_accounted' => 41, 'guests_evacuated' => 26, 'led_by' => $hse->id, 'observations' => 'Best time this year. Lifts correctly grounded by the panel.',
            'improvements' => null], 15);
        $drill(['status' => 'done', 'type' => 'tabletop', 'shift' => 'evening', 'announced' => true, 'result' => 'pass', 'scheduled_at' => $at(-25, '15:00'), 'target_seconds' => 240, 'scenario' => 'Bomb-threat phone call to the switchboard – who does what.', 'led_by' => $hse->id,
            'observations' => 'Switchboard checklist found and used. Security knew the search sectors.', 'improvements' => 'Laminate the bomb-threat checklist at every phone.'], 8);
        $drill(['type' => 'fire_evacuation', 'shift' => 'night', 'status' => 'planned', 'scheduled_at' => $at(6, '02:30'), 'announced' => false, 'target_seconds' => 300, 'led_by' => $hse->id,
            'scenario' => 'Night evacuation – alarm from the 3rd-floor corridor detectors; lift out of use.']);

        // ---- risk assessments
        $risks = [
            ['Deep-fat frying', 'Kitchen', 4, 'Oil fire; burns from hot oil; slips on spilled oil', 'Chefs, stewards', [4, 4], 'Thermostatic fryers with high-limit cut-out; wet-chemical extinguisher and blanket at the line; oil changed cold; fryer training; non-slip mats', [2, 3], null, $chef],
            ['Wet floors in public areas', 'Lobby, pool deck, restaurant', 2, 'Slips on mopped floors and rain brought in at the entrance', 'Guests, visitors, staff', [4, 3], 'Wet-floor signs; mopping outside peak hours; entrance matting; umbrella bags on rainy days; slip-resistant shoes for staff', [3, 2], null, $hk],
            ['Swimming pool – no lifeguard', 'Pool deck', 9, 'Drowning, especially children and guests who have been drinking', 'Guests (children), visitors', [3, 5], 'Pool open 07:00–19:00 only; depth markings; "no lifeguard on duty" signs; life ring and shepherd\'s crook; spa staff first-aid trained; gate self-closing; no glass on the deck', [2, 5], 'Trial a pool attendant at weekends; CCTV to the spa desk', $fom],
            ['LPG cylinder bank', 'Kitchen yard', 4, 'Gas leak, fire or explosion at the cylinder bank or supply line', 'Kitchen staff, guests, neighbours', [3, 5], 'Cylinders chained outdoors in a ventilated cage; emergency shut-off by the kitchen door; weekly leak check; hoses and regulators dated and replaced every 5 years; no smoking signs', [1, 5], null, $eng],
            ['Work at height – lamps, chandelier, signage', 'Lobby atrium, façade', 5, 'Falls from ladders and towers; dropped tools on people below', 'Engineering staff, contractors, guests below', [3, 4], 'Permit to work above 2 m; tagged ladders and tower; harness on the façade; area below barricaded', [2, 4], null, $eng],
            ['Housekeeping chemicals', 'Floors, laundry, stores', 2, 'Skin and eye burns, fumes from mixing bleach and acid cleaners', 'Room attendants, laundry staff', [3, 3], 'Colour-coded dosing system; safety data sheets in the store; gloves and goggles; eyewash in the laundry; no mixing rule', [2, 2], null, $hk],
            ['Manual handling – laundry & stores', 'Laundry, stores, banquet', 2, 'Back and shoulder strain lifting linen bags, deliveries and banquet tables', 'Laundry, stores and banquet staff', [4, 3], 'Trolleys for linen; bags under 15 kg; two-person lift for tables; lifting training at induction', [3, 3], null, $hk],
            ['Generator & electrical plant rooms', 'Basement', 5, 'Electric shock, burns and fire from switchgear and diesel', 'Engineering staff, contractors', [2, 5], 'Rooms locked; LOTO permits; rubber mats; CO₂ extinguishers; diesel day tank bunded; only electricians on the switchboard', [1, 5], null, $eng],
            ['Legionella in water systems', 'Rooftop tanks, showers, spa', 5, 'Legionnaires\' disease from warm stagnant water and shower heads', 'Guests, staff', [3, 4], 'Hot water stored ≥ 60 °C; monthly temperature logs; weekly flushing of empty rooms; quarterly tank cleaning and disinfection', [2, 4], null, $eng],
            ['Balconies – 3rd floor rooms', 'Guest rooms 301–308', 1, 'Falls from balconies – children climbing furniture', 'Guests (children)', [2, 5], 'Balustrades 1.1 m with no climbable rails; balcony furniture fixed back from the edge; restrictors on doors in family rooms', [1, 5], null, $fom],
            ['Violence and aggression – night desk & bar', 'Front desk, bar', 7, 'Assault or threats by intoxicated or angry guests, robbery of the float', 'Front-desk and bar staff', [3, 3], 'Panic button to security; CCTV; two people on the night desk; cash float kept low with drop safe; conflict training', [2, 3], null, $fom],
            ['Knives and the meat slicer', 'Kitchen', 4, 'Cuts from knives, the slicer and broken glass in the pot-wash', 'Chefs, stewards', [4, 2], 'Slicer guard and cleaning procedure; knife racks; cut-resistant glove for the slicer; broken-glass bin', [3, 2], null, $chef],
        ];
        $saved = [];
        foreach ($risks as $i => [$title, $area, $dept, $hazard, $who, [$l, $s], $controls, [$rl, $rs], $further, $owner]) {
            $r = $HS::saveRisk(['title' => $title, 'area' => $area, 'department_id' => $dept, 'hazard' => $hazard, 'who_at_risk' => $who, 'likelihood' => $l, 'severity' => $s,
                'controls' => $controls, 'residual_likelihood' => $rl, 'residual_severity' => $rs, 'further_actions' => $further, 'owner_id' => $owner->id, 'review_on' => $d(40 + $i * 21)], $hse);
            $r->timestamps = false;
            $r->update(['created_at' => $B->copy()->subDays(330 - $i * 9), 'updated_at' => $B->copy()->subDays(330 - $i * 9)]);
            $saved[$title] = $r;
        }
        $HS::acceptRisk($saved['Swimming pool – no lifeguard']->fresh(), $admin);
        $saved['Legionella in water systems']->update(['review_on' => $d(-10), 'last_reviewed_on' => $d(-375)]);   // review overdue
        foreach (['Deep-fat frying', 'Wet floors in public areas', 'LPG cylinder bank'] as $k) {
            $saved[$k]->update(['last_reviewed_on' => $d(-60), 'reviewed_by' => $hse->id]);
        }
        $HS::saveRisk(['title' => 'Spa steam room & sauna', 'area' => 'Spa', 'department_id' => 9, 'hazard' => 'Burns from the heater, fainting and dehydration, slips on the wet floor', 'who_at_risk' => 'Spa guests, therapists',
            'likelihood' => 3, 'severity' => 4, 'controls' => 'Heater guard; 15-minute timer and sign; water station; non-slip matting; staff check every 10 minutes', 'residual_likelihood' => 3, 'residual_severity' => 4,
            'further_actions' => 'Fit an emergency pull-cord inside the steam room; add a glass panel to the sauna door', 'owner_id' => $u('spa')?->id ?? $hse->id, 'review_on' => $d(90)], $hse);   // pending acceptance

        // ---- permits to work
        $permit = function (array $row) use ($hse, $eng) {
            return Models\HsePermit::create($row + ['number' => \App\Support\Numbering::next('hse_permit'), 'requested_by' => $eng->id, 'workers' => 2]);
        };
        $all = fn (string $t) => array_keys(Models\HsePermit::TYPES[$t][2]);
        $p1s = $B->copy()->subDays(9)->setTime(14, 0);
        $permit(['type' => 'hot_work', 'status' => 'closed', 'location' => 'Kitchen – extract canopy', 'work' => 'Weld a split seam in the extract duct above the cook line', 'contractor' => 'Accra Steel Fabricators',
            'starts_at' => $p1s, 'ends_at' => $p1s->copy()->addHours(4), 'precautions' => $all('hot_work'), 'fire_watch' => 'Kofi Boateng (fitness – fire team)', 'detectors_isolated' => 'Zone 2 – kitchen heat detectors',
            'issued_by' => $hse->id, 'issued_at' => $p1s->copy()->subMinutes(20), 'closed_by' => $hse->id, 'closed_at' => $p1s->copy()->addHours(4)->addMinutes(55), 'close_note' => 'Fire watch 60 min – no smouldering; zone 2 restored and tested']);
        $p2s = $B->copy()->subDays(4)->setTime(9, 0);
        $permit(['type' => 'roof', 'status' => 'closed', 'location' => 'Front façade – hotel sign', 'work' => 'Replace failed LED modules on the rooftop sign', 'contractor' => 'Neon Signs Ghana',
            'starts_at' => $p2s, 'ends_at' => $p2s->copy()->addHours(6), 'precautions' => $all('roof'), 'issued_by' => $hse->id, 'issued_at' => $p2s->copy()->subMinutes(15),
            'closed_by' => $eng->id, 'closed_at' => $p2s->copy()->addHours(7)->addMinutes(20), 'close_note' => 'Work complete; tools and barriers removed (closed 80 min late – wind delay)']);
        $p3s = $B->copy()->subDays(2)->setTime(8, 0);
        $permit(['type' => 'confined', 'status' => 'cancelled', 'location' => 'Rooftop – cold-water storage tank 2', 'work' => 'Drain, clean and disinfect the tank (quarterly legionella control)', 'contractor' => 'AquaSafe Services',
            'starts_at' => $p3s, 'ends_at' => $p3s->copy()->addHours(6), 'workers' => 3, 'cancel_reason' => 'Contractor did not attend – rebooked']);
        $p4s = now()->subHours(6)->startOfHour();
        $permit(['type' => 'electrical', 'status' => 'issued', 'location' => 'Laundry – distribution board DB-3', 'work' => 'Replace the burnt busbar on DB-3 (dryer circuits)',
            'starts_at' => $p4s, 'ends_at' => $p4s->copy()->addHours(4), 'workers' => 1, 'requested_by' => $eng->id, 'precautions' => $all('electrical'), 'isolation_points' => 'MCCB-3 at the main LV panel – lock EL-04',
            'issued_by' => $hse->id, 'issued_at' => $p4s->copy()->subMinutes(10)]);   // past its end – not closed out
        $live = $HS::requestPermit(['type' => 'hot_work', 'location' => 'Floor 3 – balcony of room 306', 'work' => 'Weld a loose balcony balustrade bracket', 'contractor' => 'Accra Steel Fabricators', 'workers' => 2,
            'starts_at' => now()->subMinutes(30)->format('Y-m-d H:i'), 'ends_at' => now()->addHours(3)->format('Y-m-d H:i')], $eng);
        $HS::issuePermit($live, ['precautions' => $all('hot_work'), 'fire_watch' => 'Joseph Thomas', 'detectors_isolated' => 'Zone 7 – 3rd floor east corridor (heads by 305–308)'], $hse);
        $HS::requestPermit(['type' => 'height', 'location' => 'Lobby atrium – chandelier', 'work' => 'Lower, clean and relamp the atrium chandelier from a tower scaffold', 'workers' => 3,
            'starts_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i'), 'ends_at' => now()->addDay()->setTime(13, 0)->format('Y-m-d H:i')], $eng);

        // ---- accident & near-miss register
        $acc = function (int $daysAgo, string $time, array $row) use ($B, $hse) {
            $at = $B->copy()->subDays($daysAgo)->setTimeFromTimeString($time);

            return Models\HseAccident::create($row + ['number' => \App\Support\Numbering::next('hse_accident'), 'occurred_at' => $at, 'reported_by' => $hse->id, 'severity' => 'near_miss', 'status' => 'closed',
                'closed_by' => $hse->id, 'closed_at' => $at->copy()->addDays(5)]);
        };
        $acc(170, '12:40', ['kind' => 'near_miss', 'location' => 'Kitchen – stock-pot range', 'description' => 'Handle of a 40-litre stock pot came loose as it was lifted; the chef set it down before it spilled.', 'root_cause' => 'Rivets worn', 'actions' => 'All large pots inspected; 3 withdrawn']);
        $acc(150, '21:15', ['kind' => 'injury', 'location' => 'Kitchen – dishwash area', 'person_type' => 'staff', 'employee_id' => $E('Sunil Yadav'), 'injury_type' => 'slip', 'body_part' => 'foot', 'severity' => 'first_aid',
            'description' => 'Slipped on water from the dishwasher drain and twisted his ankle.', 'first_aid' => 'Ice pack, rested 30 min', 'root_cause' => 'Blocked floor drain', 'actions' => 'Drain cleared; drain cleaning added to the weekly PM']);
        $acc(128, '16:05', ['kind' => 'injury', 'location' => 'Pool bar', 'person_type' => 'guest', 'person_name' => 'Mr Daniel Ofori', 'injury_type' => 'cut', 'body_part' => 'foot', 'severity' => 'first_aid',
            'description' => 'Guest stepped on a broken glass near the pool bar.', 'first_aid' => 'Cleaned and dressed by the spa first aider', 'actions' => 'Polycarbonate glasses only on the pool deck']);
        $lb = $acc(110, '07:50', ['kind' => 'injury', 'location' => 'Laundry – linen chute room', 'person_type' => 'staff', 'employee_id' => $E('Lata Bai'), 'injury_type' => 'strain', 'body_part' => 'back', 'severity' => 'lost_time', 'lost_days' => 5,
            'description' => 'Lifting an overfull linen bag from the chute bin, felt sharp pain in the lower back.', 'hospital' => 'Ridge Hospital, Accra', 'returned_on' => $d(-104), 'reportable' => true,
            'notified_on' => $d(-106), 'notified_ref' => 'DFI/GA/2026/0417', 'notified_by' => $hse->id, 'root_cause' => 'Bags regularly over 20 kg; no trolley at the chute', 'actions' => 'Chute-room trolley bought; bags limited to 15 kg; manual-handling refresher for housekeeping']);
        $acc(96, '10:20', ['kind' => 'near_miss', 'location' => 'East stairwell – floor 2', 'description' => 'Loose stair nosing found by a room attendant – could have tripped a guest.', 'actions' => 'Nosing re-fixed same day']);
        $acc(75, '15:30', ['kind' => 'injury', 'location' => 'Kitchen yard', 'person_type' => 'contractor', 'person_name' => 'Kwesi Ansah (Accra Steel Fabricators)', 'injury_type' => 'cut', 'body_part' => 'hand', 'severity' => 'medical',
            'description' => 'Cut his palm on a sharp edge of sheet metal while unloading.', 'hospital' => 'Korle Bu Teaching Hospital – 4 stitches', 'actions' => 'Contractor reminded: cut-resistant gloves on site']);
        $acc(60, '06:45', ['kind' => 'near_miss', 'location' => 'Kitchen yard – LPG cylinder bank', 'description' => 'Smell of gas at the start of the breakfast shift; the regulator hose was perished.', 'root_cause' => 'Hose past its replacement date', 'actions' => 'Hose replaced; dates now on the weekly LPG check']);
        $acc(41, '11:35', ['kind' => 'injury', 'location' => 'Kitchen – combi oven', 'person_type' => 'staff', 'employee_id' => $E('Mohan Lal'), 'injury_type' => 'burn', 'body_part' => 'arm', 'severity' => 'first_aid',
            'description' => 'Steam scald to the forearm opening the combi oven mid-cycle.', 'first_aid' => 'Cooled under running water 20 min, burn gel']);
        $acc(33, '17:10', ['kind' => 'near_miss', 'location' => 'Pool deck', 'description' => 'A child was found alone at the pool edge – the self-closing gate had been propped open with a chair.', 'actions' => 'Gate spring replaced; spa staff check the gate hourly']);
        $imr = $acc(18, '09:25', ['kind' => 'injury', 'location' => 'Pump room (B1)', 'person_type' => 'staff', 'employee_id' => $E('Imran Ali'), 'injury_type' => 'height', 'body_part' => 'leg', 'severity' => 'lost_time', 'lost_days' => 6,
            'description' => 'Fell about 1.5 m when the stepladder slipped on the wet pump-room floor while he was changing a valve.', 'hospital' => 'Ridge Hospital, Accra – sprained knee', 'returned_on' => $d(-11), 'reportable' => true,
            'notified_on' => $d(-16), 'notified_ref' => 'DFI/GA/2026/0788', 'notified_by' => $hse->id, 'status' => 'investigating', 'closed_by' => null, 'closed_at' => null, 'investigated_by' => $hse->id,
            'root_cause' => 'Ladder not footed on a wet floor; floor leak from the pump seal.', 'actions' => 'Pump seal repaired; ladder with rubber feet; ladder-use toolbox talk pending']);
        // last 3 days: through the service (the kitchen fire is a dangerous occurrence and has NOT been notified yet)
        Auth::setUser($chef);
        $fire = Models\SecurityIncident::where('type', 'fire_alarm')->orderByDesc('occurred_at')->first();
        $HS::reportAccident(['kind' => 'dangerous', 'occurrence' => 'fire', 'severity' => 'near_miss', 'occurred_at' => now()->subDays(3)->setTime(7, 10)->format('Y-m-d H:i'), 'location' => 'Kitchen – tandoor section',
            'description' => 'Fat build-up in the tandoor flue caught fire during breakfast prep. Put out with the CO₂ extinguisher; fire alarm activated and the building partly evacuated for 12 minutes. No injuries.',
            'witnesses' => 'Mohan Lal, Sunil Yadav', 'security_incident_id' => $fire?->id], $chef);
        Auth::setUser($hk);
        $HS::reportAccident(['kind' => 'near_miss', 'occurred_at' => now()->subDay()->setTime(10, 5)->format('Y-m-d H:i'), 'location' => 'Floor 2 – west fire exit',
            'description' => 'Two linen trolleys parked against the fire-exit door during the morning service, blocking it.'], $hk);
        Auth::setUser($fom);
        $stay = Models\ReservationRoom::where('status', 'checked_in')->whereNotNull('guest_id')->orderBy('id')->first();
        $HS::reportAccident(['kind' => 'injury', 'occurred_at' => now()->subHours(3)->format('Y-m-d H:i'), 'location' => 'Lobby – main entrance', 'person_type' => 'guest',
            'guest_id' => $stay?->guest_id, 'room_id' => $stay?->room_id, 'person_name' => $stay ? null : 'Guest', 'injury_type' => 'slip', 'body_part' => 'arm', 'severity' => 'first_aid',
            'description' => 'Guest slipped on the wet tiles at the entrance after a rain shower and put out her hand to break the fall – bruised wrist.', 'first_aid' => 'Cold compress at the front desk; guest declined hospital'], $fom);
        Auth::setUser($admin);
    }

    protected function seedQa(bool $rich): void
    {
        \App\Services\QaService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $qa = $u('qa') ?? $admin;
        Auth::setUser($qa);
        $B = bdate()->copy()->startOfDay();
        $dept = Models\Department::pluck('id', 'name');
        $D = ['FO' => $dept['Front Office'] ?? null, 'HK' => $dept['Housekeeping'] ?? null, 'FB' => $dept['F&B Service'] ?? null, 'KT' => $dept['Kitchen'] ?? null,
            'MT' => $dept['Maintenance'] ?? null, 'SE' => $dept['Security'] ?? null, 'SP' => $dept['Spa & Wellness'] ?? null];
        $sop = Models\SopDocument::pluck('id', 'code');
        // code, area, dept, weight, critical, sop, title, how to check
        $lib = [
            ['FO-01', 'Arrival & check-in', 'FO', 2, 0, null, 'Guest greeted within 30 seconds of arriving at the desk', 'Eye contact and a smile even when busy on the phone'],
            ['FO-02', 'Arrival & check-in', 'FO', 1, 0, null, 'Guest addressed by name at least twice during check-in', null],
            ['FO-03', 'Arrival & check-in', 'FO', 5, 1, 'SOP-FO-001', 'Identity document checked and scanned for every adult guest', 'Ghana Card / passport scanned to the registration; Form C started for foreign nationals'],
            ['FO-04', 'Arrival & check-in', 'FO', 3, 0, null, 'Check-in completed within 5 minutes', 'From greeting to key handed over'],
            ['FO-05', 'Arrival & check-in', 'FO', 2, 0, null, 'Room rate, inclusions and check-out time confirmed with the guest', null],
            ['FO-06', 'Arrival & check-in', 'FO', 1, 0, null, 'Facilities and Wi-Fi explained, directions to the lift given', null],
            ['FO-07', 'Telephone & reservations', 'FO', 2, 0, null, 'Telephone answered within 3 rings with the standard greeting', null],
            ['FO-08', 'Telephone & reservations', 'FO', 1, 0, null, "Caller's name used and the call closed with an offer of further help", null],
            ['FO-09', 'Departure', 'FO', 3, 0, null, 'Folio reviewed with the guest and check-out completed within 4 minutes', null],
            ['FO-10', 'Departure', 'FO', 3, 0, 'SOP-FO-002', 'Cash drawer balanced and float within tolerance at the spot check', null],
            ['HK-01', 'Guest room', 'HK', 3, 0, 'SOP-HK-001', 'Bed made to standard: sheets tight, no stains or hair', null],
            ['HK-02', 'Guest room', 'HK', 4, 0, 'SOP-HK-001', 'Bathroom clean and dry: no hair, limescale or mould', 'Check the shower screen, grout, plughole and behind the WC'],
            ['HK-03', 'Guest room', 'HK', 2, 0, null, 'Amenities complete and laid out to the planogram', null],
            ['HK-04', 'Guest room', 'HK', 2, 0, null, 'Surfaces dust-free, incl. headboard, skirting and under the bed', null],
            ['HK-05', 'Guest room', 'HK', 2, 0, null, 'Minibar fully stocked and dated; nothing expired', null],
            ['HK-06', 'Guest room', 'HK', 3, 0, null, 'All lights, TV, A/C and safe working', null],
            ['HK-07', 'Guest room', 'HK', 2, 0, null, 'Room smells fresh, no odour', null],
            ['HK-08', 'Guest room', 'HK', 3, 1, 'SOP-GEN-001', 'Fire evacuation plan displayed behind the door', null],
            ['FB-01', 'Restaurant service', 'FB', 2, 0, null, 'Guest greeted and seated within 1 minute', null],
            ['FB-02', 'Restaurant service', 'FB', 1, 0, null, 'Menu presented and water offered within 2 minutes of seating', null],
            ['FB-03', 'Restaurant service', 'FB', 5, 1, 'SOP-FB-001', 'Server asks about allergies and dietary needs before taking the order', 'Allergy noted on the KOT and confirmed at the pass'],
            ['FB-04', 'Restaurant service', 'FB', 3, 0, null, 'Starter served within 12 minutes and main course within 20 of ordering', null],
            ['FB-05', 'Restaurant service', 'FB', 1, 0, null, 'Check-back within 2 minutes of food being served', null],
            ['FB-06', 'Restaurant service', 'FB', 3, 0, null, 'Bill presented within 3 minutes of request, correct, with the E-VAT receipt', null],
            ['FB-07', 'Restaurant service', 'FB', 1, 0, null, 'Table reset within 5 minutes of guests leaving', null],
            ['KT-01', 'Kitchen hygiene', 'KT', 4, 0, null, 'Fridges at or below 5 °C and freezers at or below −18 °C, logged twice a day', null],
            ['KT-02', 'Kitchen hygiene', 'KT', 3, 0, null, 'All prepared food labelled with prep and use-by date', null],
            ['KT-03', 'Kitchen hygiene', 'KT', 4, 0, null, 'Raw and cooked food separated; colour-coded boards in use', null],
            ['KT-04', 'Kitchen hygiene', 'KT', 5, 1, 'SOP-FB-001', 'Allergen matrix current and available at the pass', null],
            ['KT-05', 'Kitchen hygiene', 'KT', 3, 0, null, 'Hand-wash stations stocked with soap and paper towels', null],
            ['KT-06', 'Kitchen hygiene', 'KT', 2, 0, null, 'Staff in clean uniform, hair covered, no jewellery', null],
            ['KT-07', 'Kitchen hygiene', 'KT', 2, 0, null, 'Floors, drains and extraction hoods clean', null],
            ['SF-01', 'Fire & life safety', 'SE', 5, 1, 'SOP-GEN-001', 'Fire exits clear, unlocked and signed', 'Walk every escape route to the assembly point'],
            ['SF-02', 'Fire & life safety', 'SE', 4, 0, null, 'Extinguishers in place and serviced within 12 months (GNFS tag)', null],
            ['SF-03', 'Fire & life safety', 'SE', 3, 0, null, 'Fire alarm tested weekly and logged', null],
            ['SF-04', 'Fire & life safety', 'MT', 3, 0, null, 'Emergency lighting tested monthly', null],
            ['SF-05', 'Fire & life safety', 'SE', 2, 0, null, 'CCTV recording with 30 days retained', null],
            ['SF-06', 'Fire & life safety', 'SE', 3, 0, 'SOP-SEC-001', 'Master keys signed out and back in the key register', null],
            ['PA-01', 'Public areas', 'HK', 2, 0, null, 'Lobby clean, tidy and fragrant; entrance free of clutter', null],
            ['PA-02', 'Public areas', 'HK', 3, 0, null, 'Public washrooms clean and stocked, checked hourly', null],
            ['PA-03', 'Public areas', 'MT', 2, 0, null, 'Lifts clean, lit and working', null],
            ['SP-01', 'Spa & wellness', 'SP', 3, 0, null, 'Treatment room sanitised between guests, fresh linen', null],
            ['SP-02', 'Spa & wellness', 'SP', 4, 0, null, 'Health questionnaire completed before every treatment', null],
        ];
        $S = [];
        foreach ($lib as $i => [$code, $area, $dk, $w, $crit, $sc, $title, $desc]) {
            $S[$code] = Models\QaStandard::create(['code' => $code, 'area' => $area, 'department_id' => $D[$dk], 'weight' => $w, 'is_critical' => (bool) $crit,
                'sop_document_id' => $sc ? ($sop[$sc] ?? null) : null, 'title' => $title, 'description' => $desc, 'is_active' => true, 'sort' => $i + 1]);
        }
        Models\QaStandard::create(['code' => 'FO-00', 'area' => 'Arrival & check-in', 'department_id' => $D['FO'], 'weight' => 1, 'is_critical' => false, 'title' => 'Paper registration card signed by the guest',
            'description' => 'Replaced by the e-GRC signature (v1.3)', 'is_active' => false, 'sort' => 99]);
        $tpl = function (string $name, string $type, ?int $dep, ?int $freq, float $pm, array $codes, string $desc) use ($S) {
            $t = Models\QaTemplate::create(['name' => $name, 'type' => $type, 'department_id' => $dep, 'frequency_days' => $freq, 'pass_mark' => $pm, 'description' => $desc, 'is_active' => true]);
            $t->standards()->sync(collect($codes)->values()->mapWithKeys(fn ($c, $i) => [$S[$c]->id => ['sort' => $i + 1]])->all());

            return $t;
        };
        $T = [
            'FO' => $tpl('Front Office Service Audit', 'internal', $D['FO'], 30, 85, ['FO-01', 'FO-02', 'FO-03', 'FO-04', 'FO-05', 'FO-06', 'FO-07', 'FO-08', 'FO-09', 'FO-10', 'PA-01'], 'Arrival, telephone, departure and cash control – observed at the desk and by test calls'),
            'HK' => $tpl('Guest Room Inspection', 'walkthrough', $D['HK'], 14, 80, ['HK-01', 'HK-02', 'HK-03', 'HK-04', 'HK-05', 'HK-06', 'HK-07', 'HK-08'], 'Five vacant-ready rooms picked at random, checked against the room standard'),
            'FB' => $tpl('Restaurant Service Audit', 'internal', $D['FB'], 30, 85, ['FB-01', 'FB-02', 'FB-03', 'FB-04', 'FB-05', 'FB-06', 'FB-07', 'PA-02'], 'Lunch or dinner service observed from arrival to payment'),
            'KT' => $tpl('Kitchen Hygiene Walk', 'walkthrough', $D['KT'], 7, 90, ['KT-01', 'KT-02', 'KT-03', 'KT-04', 'KT-05', 'KT-06', 'KT-07'], 'Weekly walk-through alongside the HACCP records'),
            'SF' => $tpl('Fire & Life Safety Audit', 'internal', $D['SE'], 30, 90, ['SF-01', 'SF-02', 'SF-03', 'SF-04', 'SF-05', 'SF-06', 'PA-03', 'HK-08'], 'Monthly check ahead of the GNFS fire certificate renewal'),
            'MY' => $tpl('Mystery Guest – Brand Standards', 'mystery', null, 90, 80, ['FO-07', 'FO-01', 'FO-03', 'FO-04', 'HK-01', 'HK-02', 'HK-06', 'PA-01', 'FB-01', 'FB-03', 'FB-04', 'FB-06', 'PA-02', 'SP-02', 'FO-09'], 'Unannounced overnight stay by an independent assessor, telephone booking to check-out'),
            'SP' => $tpl('Spa Treatment Audit', 'internal', $D['SP'], null, 85, ['SP-01', 'SP-02', 'PA-02'], 'On demand – after a complaint or a new therapist'),
        ];
        $notes = ['FO-03' => 'Walk-in guest checked in without the Ghana Card being scanned', 'FO-04' => 'Check-in took 9 minutes – queue of four, one agent on the desk', 'FO-06' => 'Wi-Fi not mentioned',
            'FO-07' => 'Rang 6 times before answer', 'FO-08' => null, 'FO-10' => 'Drawer GH₵ 40 short at the spot check', 'HK-02' => 'Hair in the shower tray and limescale on the screen (rooms 204, 211)',
            'HK-04' => 'Dust on the headboard, room 108', 'HK-05' => 'Two expired juices in the minibar', 'HK-06' => 'A/C in room 215 not cooling', 'HK-07' => 'Damp smell in room 112', 'HK-03' => 'Dental kit missing, room 206',
            'FB-03' => 'Server did not ask about allergies; guest had to volunteer a nut allergy', 'FB-04' => 'Main course took 34 minutes', 'FB-05' => null, 'FB-06' => 'Bill took 9 minutes; E-VAT QR missing on the receipt',
            'KT-02' => 'Unlabelled sauces in the walk-in chiller', 'KT-04' => 'Allergen matrix still showed the old menu', 'KT-07' => 'Grease build-up on the extraction hood filters',
            'SF-04' => 'Emergency light on the 2nd-floor stairwell not working', 'SF-05' => 'Camera 7 (car park) not recording', 'SF-06' => 'Two master keys signed out with no return entry',
            'PA-02' => 'Lobby washroom out of paper towels at 14:00'];
        // [days before today, checklist, auditor, failed codes, n/a codes, external auditor]
        $plan = [
            [150, 'FO', 'qa', ['FO-04', 'FO-08'], []], [120, 'FO', 'qa', ['FO-04', 'FO-06'], []], [90, 'FO', 'qa', ['FO-07'], []], [60, 'FO', 'qa', ['FO-10', 'FO-03'], []], [30, 'FO', 'qa', ['FO-07'], []],
            [68, 'HK', 'housekeeping', ['HK-02', 'HK-04', 'HK-05'], []], [54, 'HK', 'housekeeping', ['HK-02', 'HK-07'], []], [40, 'HK', 'qa', ['HK-04'], []], [26, 'HK', 'housekeeping', ['HK-02', 'HK-06'], []], [12, 'HK', 'housekeeping', ['HK-02'], []],
            [95, 'FB', 'qa', ['FB-04', 'FB-05'], []], [65, 'FB', 'qa', ['FB-03', 'FB-04'], []], [35, 'FB', 'restaurant', ['FB-05'], []],
            [27, 'KT', 'qa', ['KT-07'], []], [20, 'KT', 'qa', ['KT-04', 'KT-02'], []], [13, 'KT', 'restaurant', ['KT-06'], []], [6, 'KT', 'qa', [], []],
            [100, 'SF', 'qa', ['SF-04'], []], [70, 'SF', 'engineer', ['SF-06', 'SF-04'], []], [40, 'SF', 'qa', ['SF-05'], ['PA-03']],
            [120, 'MY', 'qa', ['FO-04', 'HK-02', 'FB-04', 'FB-06', 'PA-02'], ['SP-02'], 'LQA assessor (Leading Quality Assurance)'], [30, 'MY', 'qa', ['FO-04', 'FB-04'], ['SP-02'], 'LQA assessor (Leading Quality Assurance)'],
        ];
        usort($plan, fn ($a, $b) => $b[0] <=> $a[0]);
        $head = [$D['FO'] => 'fom', $D['HK'] => 'housekeeping', $D['FB'] => 'restaurant', $D['KT'] => 'chef', $D['MT'] => 'engineer', $D['SE'] => 'engineer', $D['SP'] => 'fom'];
        foreach ($plan as $k => $p) {
            [$ago, $tk, $aud, $fails, $nas] = $p;
            $ext = $p[5] ?? null;
            $auditor = $u($aud) ?? $qa;
            $when = $B->copy()->subDays($ago)->setTime(10 + $k % 5, 15 + ($k * 7) % 40);
            $a = \App\Services\QaService::schedule($T[$tk], ['scheduled_for' => $B->toDateString(), 'auditor_id' => $auditor->id, 'external_auditor' => $ext], $qa);
            $res = [];
            $nt = [];
            foreach ($a->items as $it) {
                $res[$it->id] = in_array($it->code, $fails, true) ? 'fail' : (in_array($it->code, $nas, true) ? 'na' : 'pass');
                if ($res[$it->id] === 'fail') {
                    $nt[$it->id] = $notes[$it->code] ?? 'Below standard at the check';
                }
                if ($it->code === 'SP-02' && $res[$it->id] === 'na') {
                    $nt[$it->id] = 'Spa not visited on this stay';
                }
            }
            $summary = $fails ? 'Service generally warm and professional. '.count($fails).' finding(s) – see corrective actions.' : 'Excellent – every standard met.';
            \App\Services\QaService::record($a, $res, $nt, $auditor, true, $summary);
            $a->refresh();
            $ackBy = $u($head[$a->department_id] ?? 'fom');
            $ackBy = $ackBy && $ackBy->id !== $a->auditor_id ? $ackBy : $admin;
            $a->forceFill(['scheduled_for' => $when->toDateString(), 'started_at' => $when->copy()->subMinutes(70), 'completed_at' => $when, 'created_at' => $when->copy()->subDays(7), 'updated_at' => $when,
                'acknowledged_by' => $ago > 7 ? $ackBy->id : null, 'acknowledged_at' => $ago > 7 ? $when->copy()->addDay()->setTime(9, 20) : null])->save();
            foreach ($a->actions()->with('item')->get() as $x) {
                $x->forceFill(['created_at' => $when, 'updated_at' => $when, 'due_date' => $when->copy()->startOfDay()->addDays($x->item?->is_critical ? 2 : 14)->toDateString()])->save();
            }
        }
        // corrective-action life cycle, oldest first – deterministic by position
        $acts = Models\QaAction::orderBy('created_at')->orderBy('id')->get();
        foreach ($acts->values() as $i => $x) {
            $owner = $u($head[$x->department_id] ?? 'fom') ?? $admin;
            $age = (int) $x->created_at->copy()->startOfDay()->diffInDays($B);
            $due = $x->due_date->copy();
            $base = ['owner_id' => $owner->id, 'root_cause' => ['Staffing – one team member covering a busy shift', 'Training – new team member not yet briefed on the standard', 'Supervisor check missed on the shift', 'Checklist step skipped under time pressure', 'Standard not displayed at the workstation'][$i % 5],
                'action_plan' => trim(($x->action_plan ? rtrim($x->action_plan, '. ').'. ' : '').['Re-brief the team at the daily line-up and re-check within a week.', 'Retrain on the SOP and add it to the induction checklist.', 'Add a supervisor sign-off to the shift checklist.', 'Coach the individual and pair with a senior colleague for a week.', 'Post the standard at the workstation and spot-check daily.'][$i % 5])];
            if ($age > 40) {
                $late = $i % 4 === 1;   // every fourth closed late
                $done = $late ? $due->copy()->addDays(3)->setTime(16, 0) : $due->copy()->subDays($x->priority === 'critical' ? 0 : 3)->setTime(15, 30);
                $x->forceFill($base + ['status' => 'verified', 'completion_note' => 'Done – see plan; team signed the briefing sheet', 'completed_at' => $done, 'completed_by' => $owner->id,
                    'verified_by' => $owner->id === $qa->id ? $admin->id : $qa->id, 'verified_at' => $done->copy()->addDay()->setTime(11, 0), 'verify_note' => 'Checked on the floor – holding', 'updated_at' => $done->copy()->addDay()])->save();
            } elseif ($age > 18) {
                $st = ['verified', 'done', 'verified', 'reopened', 'in_progress'][$i % 5];
                if ($st === 'verified') {
                    $done = $due->copy()->subDays(1)->setTime(14, 0);
                    $x->forceFill($base + ['status' => 'verified', 'completion_note' => 'Corrected and re-checked', 'completed_at' => $done, 'completed_by' => $owner->id, 'verified_by' => $owner->id === $qa->id ? $admin->id : $qa->id,
                        'verified_at' => $done->copy()->addDay(), 'verify_note' => null, 'updated_at' => $done->copy()->addDay()])->save();
                } elseif ($st === 'done') {
                    $x->forceFill($base + ['status' => 'done', 'completion_note' => 'Fixed – awaiting QA check', 'completed_at' => $B->copy()->subDays(2)->setTime(12, 0), 'completed_by' => $owner->id])->save();
                } elseif ($st === 'reopened') {
                    $x->forceFill($base + ['status' => 'in_progress', 'reopened' => 1, 'verify_note' => 'Re-checked on the floor – not fixed yet', 'due_date' => $B->copy()->addDays(2)->toDateString()])->save();
                } else {
                    $x->forceFill($base + ['status' => 'in_progress'])->save();   // due date passed → overdue
                }
            } else {
                $st = ['open', 'in_progress', 'unassigned', 'done'][$i % 4];
                if ($st === 'unassigned') {
                    continue;   // left for the quality manager to assign
                }
                $x->forceFill($base + ['status' => $st] + ($st === 'done' ? ['completion_note' => 'Done today', 'completed_at' => $B->copy()->subDay()->setTime(17, 0), 'completed_by' => $owner->id] : []))->save();
            }
        }
        // the newest finding waits for the quality manager to assign an owner
        Models\QaAction::whereNotNull('qa_audit_id')->orderByDesc('created_at')->orderByDesc('id')->first()?->forceFill(['owner_id' => null, 'status' => 'open', 'root_cause' => null, 'completion_note' => null, 'completed_at' => null, 'completed_by' => null])->save();
        // the broken A/C found on the room inspection goes to engineering
        $ac = Models\QaAction::whereHas('item', fn ($q) => $q->where('code', 'HK-06'))->latest('id')->first();
        if ($ac) {
            $ac->forceFill(['status' => 'open', 'owner_id' => $u('housekeeping')?->id, 'root_cause' => 'Compressor fault – beyond housekeeping', 'completion_note' => null, 'completed_at' => null,
                'completed_by' => null, 'verified_by' => null, 'verified_at' => null, 'verify_note' => null])->save();
            \App\Services\QaService::raiseTicket($ac, ['category' => 'ac', 'location' => 'Room 215'], $qa);
        }
        // a walk-round finding raised directly, no audit
        \App\Services\QaService::createAction(['title' => 'Lobby entrance mat worn and curling – trip hazard', 'priority' => 'high', 'department_id' => $D['HK'], 'qa_standard_id' => $S['PA-01']->id,
            'owner_id' => $u('housekeeping')?->id, 'root_cause' => 'Mat past its replacement date', 'action_plan' => 'Replace the mat; add mats to the quarterly linen & soft-goods review'], $qa);
        // today: guest room inspection under way on the 2nd floor
        $hk = $u('housekeeping') ?? $qa;
        $run = \App\Services\QaService::schedule($T['HK'], ['scheduled_for' => $B->toDateString(), 'auditor_id' => $hk->id, 'title' => 'Guest room inspection – 2nd floor'], $qa);
        $its = $run->items->values();
        \App\Services\QaService::record($run, [$its[0]->id => 'pass', $its[1]->id => 'pass', $its[2]->id => 'fail', $its[3]->id => 'pass', $its[4]->id => 'pass'], [$its[2]->id => 'Dental kit missing, room 206'], $hk);
        $run->forceFill(['started_at' => $B->copy()->setTime(9, 40)])->save();
        // booked: restaurant audit in three days; fire & safety audit five days late
        \App\Services\QaService::schedule($T['FB'], ['scheduled_for' => $B->copy()->addDays(3)->toDateString(), 'auditor_id' => $qa->id, 'title' => 'Restaurant service audit – dinner'], $qa);
        $late = \App\Services\QaService::schedule($T['SF'], ['scheduled_for' => $B->toDateString(), 'auditor_id' => $qa->id], $qa);
        $late->forceFill(['scheduled_for' => $B->copy()->subDays(5)->toDateString(), 'created_at' => $B->copy()->subDays(12)])->save();
        // cancelled: spa audit called off
        $sp = \App\Services\QaService::schedule($T['SP'], ['scheduled_for' => $B->copy()->addDay()->toDateString(), 'auditor_id' => $qa->id], $qa);
        \App\Services\QaService::cancel($sp, 'Spa closed for refurbishment this week', $qa);
        \App\Services\QaService::flush();
        Auth::forgetUser();
    }

    protected function seedEsg(bool $rich): void
    {
        $ES = \App\Services\EsgService::class;
        $ES::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $hk = $u('housekeeping');
        $eng = $u('engineer') ?? $admin;
        $today = bdate()->copy();
        $d = fn (int $days) => $today->copy()->subDays($days)->toDateString();

        // ── people: gender & nationality (Ghana Labour Act s.14 / diversity reporting)
        $female = ['Lata', 'Deepa', 'Abena', 'Grace', 'Efua', 'Meera', 'Anjali', 'Akosua', 'Ama', 'Adwoa', 'Esi', 'Afia', 'Yaa', 'Priya', 'Nana'];
        $ghana = ['Abena', 'Grace', 'Efua', 'Kofi', 'Yaw', 'Kwesi', 'Kwame', 'Akosua', 'Ama', 'Adwoa', 'Esi', 'Afia', 'Yaa', 'Kojo', 'Kwabena', 'Nana', 'Ekow', 'Fiifi'];
        foreach (Models\Employee::where('hotel_id', $hid)->get() as $e) {
            $first = strtok((string) $e->name, ' ');
            $e->forceFill(['gender' => in_array($first, $female, true) ? 'F' : 'M', 'nationality' => in_array($first, $ghana, true) ? 'Ghanaian' : 'Indian'])->saveQuietly();
        }
        // ── local suppliers (Ghanaian-owned / Ghana-based)
        Models\Supplier::where('hotel_id', $hid)->where(fn ($q) => $q->where('name', 'like', 'FreshFarm%')->orWhere('name', 'like', 'Accra%')->orWhere('name', 'like', 'Tema%')
            ->orWhere('name', 'like', 'SparkleWash%')->orWhere('name', 'like', 'CoolAir%')->orWhere('name', 'like', 'CleanPro%'))->update(['is_local' => true]);
        $sup = fn (string $like) => Models\Supplier::where('hotel_id', $hid)->where('name', 'like', $like.'%')->value('id');
        $dept = fn (string $n) => Models\Department::where('hotel_id', $hid)->where('name', 'like', $n.'%')->value('id');
        $kitchen = $dept('Kitchen');
        $hkd = $dept('House');
        $fb = $dept('F&B');
        $maint = $dept('Maint');

        // ── waste: 12 weekly collections, diversion improving once food composting starts (6 weeks ago)
        $W = Models\EsgWasteLog::class;
        $add = function (string $date, string $stream, string $disp, float $kg, array $x = []) use ($W, $admin) {
            $W::create($x + ['number' => \App\Support\Numbering::next('esg_waste'), 'date' => $date, 'stream' => $stream, 'disposal' => $disp, 'weight_kg' => round($kg, 2), 'cost' => 0, 'income' => 0, 'user_id' => $admin->id]);
        };
        $waste = $sup('CleanPro');
        for ($wk = 11; $wk >= 0; $wk--) {
            $date = $d($wk * 7 + 2);
            $j = ($wk * 37) % 11;                        // deterministic wobble
            $late = $wk < 6;                             // food composting started 6 weeks ago
            $add($date, 'general', 'landfill', 92 + $j * 3 - ($late ? 18 : 0), ['contractor' => 'Accra Metro Waste Services', 'manifest_no' => 'WTN-'.str_pad((string) (4100 + 12 - $wk), 5, '0', STR_PAD_LEFT), 'cost' => 220]);
            $add($date, 'food', $late ? 'composting' : 'landfill', 52 + $j * 2, ['department_id' => $kitchen, 'contractor' => $late ? 'Green Soil Composting Co-op' : 'Accra Metro Waste Services', 'cost' => $late ? 150 : 0]);
            $add($date, 'paper_card', 'recycling', 21 + $j, ['contractor' => 'Accra Recyclers Ltd', 'income' => 10]);
            $add($date, 'plastic', 'recycling', 10 + $j * 0.5, ['contractor' => 'Accra Recyclers Ltd', 'department_id' => $fb]);
            if ($wk % 2 === 0) {
                $add($date, 'glass', 'recycling', 17 + $j, ['contractor' => 'Accra Recyclers Ltd', 'department_id' => $fb]);
            }
            if ($wk % 4 === 1) {
                $add($d($wk * 7 + 3), 'cooking_oil', 'rendering', 28 + $j, ['department_id' => $kitchen, 'contractor' => 'BioFuel Ghana', 'income' => 90]);
            }
        }
        $add($d(40), 'e_waste', 'recycling', 46, ['department_id' => $maint, 'contractor' => 'E-Waste Recyclers Ghana', 'manifest_no' => 'EW-2026-117', 'notes' => '11 old CRT TVs and 6 PCs', 'cost' => 300]);
        $add($d(33), 'hazardous', 'incineration', 9.5, ['department_id' => $maint, 'contractor' => 'Zoil Services', 'manifest_no' => 'HZ-2026-044', 'notes' => 'Fluorescent tubes, batteries, paint tins', 'cost' => 620]);
        $add($d(9), 'food', 'donation', 42, ['department_id' => $kitchen, 'contractor' => 'Osu Children\'s Home', 'notes' => 'Surplus banquet food (untouched), within 2 hours of service']);
        $add($d(6), 'garden', 'composting', 95, ['contractor' => 'Green Soil Composting Co-op']);
        $x = $W::where('hotel_id', $hid)->where('stream', 'plastic')->orderByDesc('date')->first();
        $x->update(['cancelled_at' => now(), 'cancel_reason' => 'Duplicate entry – same collection logged twice', 'cancelled_by' => $admin->id]);
        $add($x->date->toDateString(), 'plastic', 'recycling', (float) $x->weight_kg, ['contractor' => 'Accra Recyclers Ltd', 'department_id' => $fb]);

        // ── F-gas log: the kitchen cold room is leaking (> 10 % of charge a year)
        $R = Models\EsgRefrigerantLog::class;
        $asset = fn (string $like) => Models\Asset::where('hotel_id', $hid)->where('name', 'like', $like.'%')->value('id');
        $tk = Models\MaintenanceRequest::where('hotel_id', $hid)->where('title', 'like', 'PM: Chiller%')->value('id');
        $gas = function (int $days, array $x) use ($R, $eng, $d) {
            $R::create($x + ['number' => \App\Support\Numbering::next('esg_refrigerant'), 'date' => $d($days), 'kg_added' => 0, 'kg_recovered' => 0,
                'technician' => 'Samuel Tetteh (CoolAir HVAC)', 'certificate_no' => 'EPA-RAC-0482', 'user_id' => $eng->id]);
        };
        $gas(160, ['asset_id' => $asset('Chiller'), 'equipment' => 'Chiller plant 60 TR', 'gas' => 'R134a', 'system_charge_kg' => 180, 'kg_added' => 6.5, 'reason' => 'top_up', 'maintenance_request_id' => $tk, 'notes' => 'Quarterly service – no leak found on bubble test']);
        $gas(118, ['asset_id' => $asset('Walk-in'), 'equipment' => 'Walk-in cold room', 'gas' => 'R404A', 'system_charge_kg' => 8, 'kg_added' => 1.6, 'reason' => 'leak_repair', 'notes' => 'Flare joint at the evaporator re-made']);
        $gas(26, ['asset_id' => $asset('Walk-in'), 'equipment' => 'Walk-in cold room', 'gas' => 'R404A', 'system_charge_kg' => 8, 'kg_added' => 1.1, 'reason' => 'top_up', 'notes' => 'Low charge again – leak search booked; replacement with R290 unit proposed (ECO-6)']);
        $gas(70, ['asset_id' => $asset('Split AC 1.5 T – Room 101') ?? $asset('Split AC'), 'equipment' => 'Split AC – Room 101', 'gas' => 'R410A', 'system_charge_kg' => 1.2, 'kg_added' => 1.2, 'reason' => 'install', 'notes' => 'Replacement inverter unit']);
        $gas(71, ['equipment' => 'Old split AC – Room 101', 'gas' => 'R22', 'system_charge_kg' => 1.1, 'kg_recovered' => 0.9, 'reason' => 'decommission', 'notes' => 'Gas recovered to cylinder, returned to supplier for reclamation']);

        // ── outsourced laundry: last full month weighed by the laundry; the current month falls back to the HCMI estimate
        Models\EsgLaundryMonth::create(['month' => $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'kg' => 640, 'supplier_id' => $sup('SparkleWash'), 'notes' => 'From the SparkleWash monthly weight statement', 'user_id' => $admin->id]);

        // ── targets (2025 baseline)
        $T = Models\EsgTarget::class;
        foreach ([['co2_room', 2025, 72, 2030, 45, 'HCMI, 12-month rolling'], ['water_room', 2025, 3900, 2030, 2600, 'HWMI'], ['diversion', 2025, 18, 2028, 50, 'Composting and recycling contracts'],
            ['renewable', 2025, 6, 2030, 30, 'Rooftop solar phase 2 (ECO-2)'], ['female_mgmt', 2025, 25, 2028, 40, 'Talent pipeline & mentoring'], ['local_spend', 2025, 20, 2028, 40, 'Buy Ghana first for F&B']] as [$m, $by, $bv, $ty, $tv, $n]) {
            $T::create(['metric' => $m, 'baseline_year' => $by, 'baseline_value' => $bv, 'target_year' => $ty, 'target_value' => $tv, 'notes' => $n, 'is_active' => true, 'user_id' => $admin->id]);
        }

        // ── initiatives at every stage
        $I = Models\EsgInitiative::class;
        $grid = (float) \App\Services\UtilityService::cfg()['ef']['electricity'];
        $ini = function (array $x) use ($I, $admin) {
            return $I::create($x + ['number' => \App\Support\Numbering::next('esg_initiative'), 'raised_by' => $admin->id, 'capex' => 0, 'saving_ghs' => 0, 'saving_kwh' => 0, 'saving_m3' => 0, 'saving_waste_kg' => 0]);
        };
        $ini(['title' => 'LED retrofit – guest rooms & corridors', 'category' => 'energy', 'description' => 'Replace 640 CFL / halogen lamps with LED.', 'owner_user_id' => $eng->id, 'capex' => 48000, 'saving_ghs' => 21500,
            'saving_kwh' => 38000, 'saving_co2_kg' => round(38000 * $grid, 1), 'status' => 'done', 'approved_by' => $u('fom')?->id ?? $admin->id, 'approved_at' => $today->copy()->subDays(150), 'start_on' => $d(140),
            'due_on' => $d(100), 'completed_on' => $d(104), 'outcome' => 'All 640 lamps replaced; corridor lighting load down from 9.8 kW to 3.1 kW.']);
        $ini(['title' => 'Rooftop solar phase 2 (15 → 40 kWp)', 'category' => 'energy', 'description' => 'Add 25 kWp on the east wing roof with net metering.', 'owner_user_id' => $eng->id, 'capex' => 185000, 'saving_ghs' => 42000,
            'saving_kwh' => 36500, 'saving_co2_kg' => round(36500 * $grid, 1), 'status' => 'approved', 'approved_by' => $u('fom')?->id ?? $admin->id, 'approved_at' => $today->copy()->subDays(20), 'due_on' => $today->copy()->addMonths(4)->toDateString()]);
        $ini(['title' => 'Kitchen food-waste composting', 'category' => 'waste', 'description' => 'Separate food waste at the pass and send it to a composting co-op instead of landfill.', 'owner_user_id' => $u('costctl')?->id,
            'capex' => 6500, 'saving_ghs' => 1800, 'saving_waste_kg' => 6800, 'saving_co2_kg' => 4600, 'status' => 'in_progress', 'approved_by' => $admin->id, 'approved_at' => $today->copy()->subDays(95), 'raised_by' => $u('costctl')?->id ?? $admin->id,
            'start_on' => $d(84), 'due_on' => $d(10)]);
        $ini(['title' => 'Refill water stations instead of bottled water in rooms', 'category' => 'waste', 'description' => 'Glass carafes and filtered-water stations on each floor.', 'owner_user_id' => $hk?->id, 'capex' => 22000, 'saving_ghs' => 14000,
            'saving_waste_kg' => 900, 'saving_co2_kg' => 1100, 'status' => 'idea', 'raised_by' => $hk?->id ?? $admin->id]);
        $ini(['title' => 'Low-flow shower heads (9 L/min)', 'category' => 'water', 'description' => 'Replace 24 shower heads (currently ~14 L/min).', 'owner_user_id' => $eng->id, 'capex' => 7200, 'saving_ghs' => 6200,
            'saving_m3' => 520, 'saving_kwh' => 4200, 'saving_co2_kg' => round(4200 * $grid + 520 * 0.421, 1), 'status' => 'idea', 'raised_by' => $admin->id]);
        $ini(['title' => 'Replace the R404A cold-room unit with an R290 system', 'category' => 'carbon', 'description' => 'The cold room keeps losing R404A (GWP 3,943).', 'owner_user_id' => $eng->id, 'capex' => 38000, 'saving_ghs' => 3500,
            'saving_co2_kg' => 10500, 'status' => 'idea', 'raised_by' => $eng->id]);
        $ini(['title' => 'Plastic key cards → wooden key cards', 'category' => 'procurement', 'capex' => 9000, 'saving_waste_kg' => 12, 'saving_co2_kg' => 40, 'status' => 'rejected',
            'approved_by' => $admin->id, 'approved_at' => $today->copy()->subDays(60), 'decision_note' => 'Poor payback – revisit with the new door-lock system.', 'raised_by' => $hk?->id ?? $admin->id]);

        // ── community
        $C = Models\EsgCommunity::class;
        foreach ([[120, 'donation', 'Osu Children\'s Home', 'School fees for 10 children (term 1)', 5000, 0, 0, 10], [75, 'volunteering', 'Labadi Beach clean-up', 'Green team & volunteers – 310 kg of plastic collected', 0, 36, 12, 0],
            [9, 'food', 'Osu Children\'s Home', '42 kg of surplus banquet food', 1260, 2, 2, 60], [45, 'training', 'Accra Technical University – hospitality students', 'Two-week kitchen & front-office attachment for 6 students', 2400, 80, 4, 6],
            [20, 'sponsorship', 'La Community Football Cup', 'Kits and refreshments', 3500, 0, 0, 400]] as [$ago, $k, $b, $desc, $amt, $h, $vol, $ppl]) {
            $C::create(['number' => \App\Support\Numbering::next('esg_community'), 'date' => $d($ago), 'kind' => $k, 'beneficiary' => $b, 'description' => $desc, 'amount' => $amt, 'hours' => $h, 'volunteers' => $vol, 'people_reached' => $ppl, 'user_id' => $admin->id]);
        }

        // ── certification self-assessment: most mandatory criteria met, some gaps
        $status = ['M01' => 'met', 'M02' => 'partial', 'M03' => 'met', 'M04' => 'met', 'M05' => 'met', 'S01' => 'partial', 'S02' => 'met', 'G01' => 'met', 'G02' => 'met', 'E01' => 'met', 'E03' => 'met', 'E04' => 'met',
            'W01' => 'not', 'W03' => 'met', 'X01' => 'met', 'X02' => 'partial', 'X04' => 'met', 'P01' => 'partial', 'R01' => 'met', 'R02' => 'met', 'R03' => 'met', 'G03' => 'na', 'C02' => 'na'];
        $evidence = ['M01' => 'ENV-01 approved by the GM, framed in the lobby', 'M02' => 'Chief engineer named; green team minutes only for Q2', 'M03' => 'Utilities module readings; ESG dashboard', 'M04' => 'ESG targets & initiative register',
            'M05' => 'Compliance register', 'S01' => 'Induction covers it; refresher not yet run', 'E01' => 'ECO-1 completed', 'E04' => '15 kWp rooftop solar', 'X01' => 'Waste register – 5 streams weighed weekly', 'X02' => 'Food waste weighed; donation started',
            'P01' => 'About 20 % local – see local sourcing report', 'R03' => 'Payroll checked against the national minimum wage'];
        foreach (Models\EsgChecklistItem::where('hotel_id', $hid)->get() as $it) {
            if ($st = $status[$it->code] ?? null) {
                $it->update(['status' => $st, 'evidence' => in_array($st, ['met', 'partial'], true) ? ($evidence[$it->code] ?? 'Checked on site walk-round') : null, 'reviewed_by' => $admin->id, 'reviewed_on' => $d(12)]);
            }
        }

        // ── policies: four in force (one past its review date)
        foreach ([['ENV-01', 320, 12, 'Environmental & sustainability policy signed by the General Manager.'], ['SOC-01', 470, 12, 'Equal opportunity, anti-harassment & grievance.'],
            ['GOV-01', 230, 24, null], ['ENV-03', 110, 12, null]] as [$code, $ago, $months, $sum]) {
            $p = Models\EsgPolicy::where('hotel_id', $hid)->where('code', $code)->first();
            if ($p) {
                $on = $today->copy()->subDays($ago);
                $p->update(['status' => 'active', 'approved_on' => $on->toDateString(), 'approved_by_name' => 'General Manager', 'review_months' => $months, 'owner_user_id' => $admin->id,
                    'next_review_on' => $on->copy()->addMonthsNoOverflow($months)->toDateString(), 'summary' => $sum ?? $p->summary]);
            }
        }

        // ── green stays: one chosen at the desk two nights ago (points credited), one chosen in the guest portal today
        $stays = Models\ReservationRoom::where('hotel_id', $hid)->where('status', 'checked_in')->with('reservation')->orderBy('arrival_date')->orderBy('id')->get();
        $c = $ES::cfg();
        $a = $stays->first(fn ($s) => $s->arrival_date->lte($today->copy()->subDays(3)) && $s->departure_date->gte($today->copy()->addDays(2)));
        if ($a) {
            $from = $a->arrival_date->copy()->addDay();
            $g = Models\EsgGreenStay::create(['reservation_room_id' => $a->id, 'guest_id' => $a->guest_id ?? $a->reservation?->guest_id, 'from_date' => $from->toDateString(), 'status' => 'active', 'source' => 'desk', 'user_id' => $u('fom')?->id]);
            for ($day = $from->copy(); $day->lt($today); $day->addDay()) {
                if (((int) $from->diffInDays($day) + 1) % $c['green_every'] === 0) {
                    continue;
                }
                $pts = \App\Services\LoyaltyService::enabled() && $g->guest_id ? $c['green_points'] : 0;
                Models\EsgGreenNight::create(['esg_green_stay_id' => $g->id, 'reservation_room_id' => $a->id, 'guest_id' => $g->guest_id, 'room_id' => $a->room_id, 'business_date' => $day->toDateString(),
                    'points' => $pts, 'kwh' => $c['green_kwh'], 'litres' => $c['green_litres'], 'co2_kg' => round($c['green_kwh'] * $c['grid'] + $c['green_litres'] / 1000 * ($c['ef_water'] + $c['ef_wastewater']), 2)]);
                if ($pts) {
                    Models\LoyaltyEntry::create(['type' => 'green', 'guest_id' => $g->guest_id, 'points' => $pts, 'description' => 'Green stay – room '.$a->room?->room_no.' not serviced on '.$day->format('d M Y')]);
                    Models\Guest::whereKey($g->guest_id)->increment('loyalty_points', $pts);
                }
            }
        }
        $b = $stays->first(fn ($s) => $s->id !== $a?->id && $s->departure_date->gte($today->copy()->addDays(3)));
        if ($b) {
            Models\EsgGreenStay::create(['reservation_room_id' => $b->id, 'guest_id' => $b->guest_id ?? $b->reservation?->guest_id, 'from_date' => $today->copy()->addDay()->toDateString(), 'status' => 'active', 'source' => 'portal']);
        }
        \App\Models\Setting::$version++;
        $ES::flush();
    }

    protected function seedSecurity(bool $rich, Models\User $user): void
    {
        if (! $rich) {
            return;
        }
        $hid = Tenant::id();
        $prevUser = auth()->user();
        mt_srand(4200);
        $sec = Models\User::where('hotel_id', $hid)->where('username', 'security')->first() ?? $user;
        $desk = Models\User::where('hotel_id', $hid)->where('username', 'frontdesk')->first() ?? $user;
        $eng = Models\User::where('hotel_id', $hid)->where('username', 'engineer')->first() ?? $user;
        $hk = Models\User::where('hotel_id', $hid)->where('username', 'housekeeping')->first() ?? $user;
        $fom = Models\User::where('hotel_id', $hid)->where('username', 'fom')->first() ?? $user;
        $S = \App\Services\SecurityService::class;
        $now = now();

        // watch list
        auth()->setUser($fom);
        $S::saveWatch(['name' => 'Rakesh Malhotra', 'phone' => '+91 98110 45522', 'level' => 'block', 'reason' => 'Theft from a guest room (Mar 2026) – police complaint filed, banned by the GM']);
        $S::saveWatch(['name' => 'Vikram Sood', 'id_type' => 'driving_licence', 'id_number' => 'DL0420110149646', 'level' => 'block', 'reason' => 'Assaulted a staff member at the bar – barred for 2 years', 'valid_until' => bdate()->copy()->addYear()->toDateString()]);
        $alert = $S::saveWatch(['name' => 'Sameer Kohli', 'phone' => '+91 99887 12003', 'level' => 'alert', 'reason' => 'Disputed bills twice; inform the duty manager if he visits']);

        // in-house stays as hosts
        $stays = Models\ReservationRoom::with('guest', 'reservation.guest', 'room')->where('status', 'checked_in')->get()->sortBy(fn ($x) => $x->room?->room_no)->values();
        if ($stays->count() >= 5) {
            $stays[3]->forceFill(['visitor_policy' => 'call'])->save();
            $stays[min(6, $stays->count() - 1)]->forceFill(['visitor_policy' => 'none'])->save();
        }
        $staff = Models\User::where('hotel_id', $hid)->whereIn('username', ['hr', 'accounts', 'engineer', 'fom'])->get()->values();
        $names = ['Ananya Rao', 'Karan Mehta', 'Pooja Nair', 'Rohit Bansal', 'Sneha Kulkarni', 'Arvind Pillai', 'Neha Joshi', 'Farhan Qureshi', 'Divya Menon', 'Manish Tiwari',
            'Kavya Reddy', 'Siddharth Jain', 'Ritu Chawla', 'Gaurav Saxena', 'Ishita Ghosh', 'Nikhil Arora', 'Meenakshi S', 'Tarun Bhatia', 'Aditi Kapoor', 'Harish Gupta',
            'Blue Dart courier', 'Zomato rider', 'Suresh (Otis lift)', 'Priyanka Das', 'Imtiaz Ali', 'Lakshmi V', 'Rajiv Khanna', 'Anjali Verma'];
        $idTypes = ['aadhaar', 'driving_licence', 'voter_id', 'aadhaar', 'pan', 'company_id'];
        $mkV = function (array $a) use ($sec) {
            $v = new Models\Visitor($a + ['persons' => 1, 'source' => 'desk', 'created_by' => $sec->id]);
            $v->pass_no = \App\Support\Numbering::next('visitor');
            $v->save();

            return $v;
        };
        $host = function (int $k) use ($stays, $staff) {
            if ($k % 3 === 2 && $staff->isNotEmpty()) {
                $u = $staff[$k % $staff->count()];

                return ['host_type' => 'staff', 'host_user_id' => $u->id, 'host_name' => $u->name, 'purpose' => ['meeting', 'interview', 'contractor', 'delivery'][$k % 4], 'approved_via' => 'host'];
            }
            $st = $stays[$k % max(1, $stays->count())] ?? null;

            return $st ? ['host_type' => 'guest', 'reservation_room_id' => $st->id, 'room_id' => $st->room_id, 'guest_id' => $st->guest_id ?? $st->reservation?->guest_id,
                'host_name' => ($st->guest ?? $st->reservation?->guest)?->full_name, 'purpose' => 'guest_visit', 'approved_via' => 'phone'] : ['host_type' => 'staff', 'host_name' => 'Front office', 'purpose' => 'meeting'];
        };
        // 10 days of history
        $k = 0;
        for ($d = 10; $d >= 1; $d--) {
            for ($j = 0, $n = 2 + mt_rand(0, 2); $j < $n; $j++, $k++) {
                $in = $now->copy()->subDays($d)->setTime(10 + mt_rand(0, 9), mt_rand(0, 5) * 10);
                $h = $host($k);
                if (($h['host_type'] ?? '') === 'guest') {   // history can't point at today's in-house stays for a past date – keep the name, drop the stay link
                    $h['reservation_room_id'] = null;
                }
                $out = $in->copy()->addMinutes(25 + mt_rand(0, 150));
                $mkV($h + ['name' => $names[$k % count($names)], 'phone' => '+91 9'.mt_rand(100000000, 999999999), 'id_type' => $idTypes[$k % 6], 'id_last4' => (string) mt_rand(1000, 9999),
                    'status' => 'out', 'checked_in_at' => $in, 'valid_until' => $in->copy()->addHours(4), 'checked_out_at' => $out, 'checked_in_by' => $sec->id, 'checked_out_by' => $sec->id,
                    'created_at' => $in, 'updated_at' => $out]);
            }
        }
        $mkV(['name' => 'Rakesh Malhotra', 'phone' => '+91 98110 45522', 'host_type' => 'guest', 'host_name' => 'Room 105 guest', 'purpose' => 'guest_visit', 'status' => 'denied',
            'deny_reason' => 'Barred person – watch list', 'watchlist_id' => Models\SecurityWatch::where('name', 'Rakesh Malhotra')->value('id'),
            'created_at' => $now->copy()->subDays(4)->setTime(19, 40), 'updated_at' => $now->copy()->subDays(4)->setTime(19, 42)]);
        // on site now: one overstay, one alert match, one normal, one staff visitor; one awaiting approval
        if ($stays->count() >= 3) {
            $g0 = $host(0);
            $mkV($g0 + ['name' => 'Mohit Sharma', 'phone' => '+91 98200 11223', 'id_type' => 'aadhaar', 'id_last4' => '4471', 'status' => 'in', 'persons' => 2,
                'checked_in_at' => $now->copy()->subHours(5), 'valid_until' => $now->copy()->subMinutes(50), 'checked_in_by' => $sec->id, 'created_at' => $now->copy()->subHours(5)]);
            $mkV($host(1) + ['name' => 'Sameer Kohli', 'phone' => '+91 99887 12003', 'id_type' => 'driving_licence', 'id_last4' => '8830', 'status' => 'in', 'watchlist_id' => $alert->id,
                'checked_in_at' => $now->copy()->subMinutes(40), 'valid_until' => $now->copy()->addHours(2), 'checked_in_by' => $sec->id, 'created_at' => $now->copy()->subMinutes(40)]);
            $mkV($host(2) + ['name' => 'Deepak Otis (lift AMC)', 'company' => 'Otis Elevator', 'phone' => '+91 90040 55661', 'id_type' => 'company_id', 'id_last4' => '0192', 'status' => 'in',
                'items_declared' => 'Tool bag, multimeter', 'purpose' => 'contractor', 'checked_in_at' => $now->copy()->subMinutes(75), 'valid_until' => $now->copy()->addHours(3),
                'checked_in_by' => $sec->id, 'created_at' => $now->copy()->subMinutes(75)]);
            $mkV($host(4) + ['name' => 'Ritika Bose', 'phone' => '+91 98311 70021', 'id_type' => 'voter_id', 'id_last4' => '5518', 'status' => 'in',
                'checked_in_at' => $now->copy()->subMinutes(20), 'valid_until' => $now->copy()->addHours(3), 'checked_in_by' => $desk->id, 'created_at' => $now->copy()->subMinutes(20)]);
            $mkV(['host_type' => 'guest', 'reservation_room_id' => $stays[3]->id, 'room_id' => $stays[3]->room_id, 'guest_id' => $stays[3]->guest_id ?? $stays[3]->reservation?->guest_id,
                'host_name' => ($stays[3]->guest ?? $stays[3]->reservation?->guest)?->full_name, 'purpose' => 'guest_visit', 'name' => 'Arjun Khurana', 'phone' => '+91 98100 33445',
                'id_type' => 'aadhaar', 'id_last4' => '9012', 'status' => 'awaiting', 'valid_until' => $now->copy()->addHours(4), 'created_at' => $now->copy()->subMinutes(4)]);
            // pre-registered by guests via the portal
            foreach ([[1, 'Shalini Iyer', 1, 2], [5, 'Rahul & Priya Mathur', 2, 5]] as [$si, $nm, $pp, $hrs]) {
                $st = $stays[$si % $stays->count()];
                $at = $now->copy()->addMinutes($hrs === 2 ? 45 : 60 * $hrs);
                if ($at->toDateString() !== $now->toDateString() || $at->format('H:i') >= '21:30') {
                    $at = $now->copy()->addDay()->setTime($hrs === 2 ? 11 : 12, 0);
                }
                $mkV(['host_type' => 'guest', 'reservation_room_id' => $st->id, 'room_id' => $st->room_id, 'guest_id' => $st->guest_id ?? $st->reservation?->guest_id,
                    'host_name' => ($st->guest ?? $st->reservation?->guest)?->full_name, 'purpose' => 'guest_visit', 'name' => $nm, 'phone' => '+91 9'.mt_rand(100000000, 999999999),
                    'persons' => $pp, 'status' => 'expected', 'source' => 'portal', 'approved_via' => 'pre_registered', 'expected_at' => $at, 'valid_until' => $at->copy()->addHours(3),
                    'pre_token' => \Illuminate\Support\Str::random(40), 'created_by' => null, 'created_at' => $now->copy()->subHours(2)]);
            }
        }

        // gate passes
        $G = \App\Services\GatePassService::class;
        $dept = Models\Department::pluck('id', 'name');
        $assets = Models\Asset::pluck('id', 'asset_no');
        $mkP = function (Models\User $by, array $d) use ($G) {
            auth()->setUser($by);

            return $G::create($d);
        };
        $bd = bdate()->copy();
        // 1. dishwasher (already under repair) – out for repair, overdue
        $p = $mkP($eng, ['type' => 'returnable', 'purpose' => 'repair', 'department_id' => $dept['Maintenance'] ?? null, 'party_name' => 'Hobart Service Centre', 'party_phone' => '+91 80 4110 2200',
            'carrier_name' => 'Manjunath (Hobart)', 'vehicle_no' => 'KA01AB4521', 'expected_return_date' => $bd->toDateString(), 'reason' => 'Wash pump failure – workshop repair',
            'items' => [['asset_id' => $assets['FA-00006'] ?? null, 'description' => 'Commercial dishwasher wash pump assembly', 'quantity' => 1, 'unit' => 'nos', 'est_value' => 38000]]]);
        auth()->setUser($fom); $G::approve($p); auth()->setUser($sec); $G::markOut($p, null, null);
        $p->forceFill(['expected_return_date' => $bd->copy()->subDays(3)->toDateString(), 'created_at' => $now->copy()->subDays(9), 'approved_at' => $now->copy()->subDays(9), 'out_at' => $now->copy()->subDays(9)])->saveQuietly();
        // 2. AC out for repair – on time
        $p = $mkP($eng, ['type' => 'returnable', 'purpose' => 'repair', 'department_id' => $dept['Maintenance'] ?? null, 'party_name' => 'Cool Air Services', 'party_phone' => '+91 98450 22110',
            'carrier_name' => 'Ravi (Cool Air)', 'expected_return_date' => $bd->copy()->addDays(4)->toDateString(), 'reason' => 'Compressor not cooling – warranty repair',
            'items' => [['asset_id' => $assets['FA-00015'] ?? null, 'description' => 'Split AC 1.5 T indoor + outdoor unit – room 102', 'quantity' => 1, 'unit' => 'set', 'est_value' => 42000]]]);
        auth()->setUser($fom); $G::approve($p); auth()->setUser($sec); $G::markOut($p, null, 'KA05MN7788');
        // 3. banquet chairs hired out – partly back
        $p = $mkP($fom, ['type' => 'returnable', 'purpose' => 'hire_out', 'department_id' => $dept['F&B Service'] ?? null, 'party_name' => 'Hotel Sagar Residency (sister unit)',
            'expected_return_date' => $bd->copy()->addDays(1)->toDateString(), 'reason' => 'Lent for their wedding function',
            'items' => [['description' => 'Banquet chairs with covers', 'quantity' => 60, 'unit' => 'nos', 'est_value' => 90000], ['description' => 'Round tables 5 ft', 'quantity' => 6, 'unit' => 'nos', 'est_value' => 36000]]]);
        auth()->setUser($user); $G::approve($p); auth()->setUser($sec); $G::markOut($p, 'Tempo driver Salim', 'KA03C9911');
        $items = $p->items()->orderBy('id')->get();
        $G::receive($p, [$items[0]->id => 40, $items[1]->id => 6], 'Salim', 'Balance 20 chairs tomorrow');
        // 4. laundry – returned in full (history)
        $p = $mkP($hk, ['type' => 'returnable', 'purpose' => 'laundry', 'department_id' => $dept['Housekeeping'] ?? null, 'party_name' => 'Sparkle Commercial Laundry',
            'expected_return_date' => $bd->toDateString(), 'items' => [['description' => 'Banquet table linen', 'quantity' => 120, 'unit' => 'pcs', 'est_value' => 24000], ['description' => 'Heavy curtains (lobby)', 'quantity' => 8, 'unit' => 'pcs']]]);
        auth()->setUser($fom); $G::approve($p); auth()->setUser($sec); $G::markOut($p, 'Sparkle van', 'KA02X1212');
        $items = $p->items()->orderBy('id')->get();
        $G::receive($p, [$items[0]->id => 120, $items[1]->id => 8], 'Sparkle van', 'All received, counted');
        $p->forceFill(['created_at' => $now->copy()->subDays(5), 'out_at' => $now->copy()->subDays(5)])->saveQuietly();
        // 5. non-returnable – old mattresses to scrap dealer
        $p = $mkP($hk, ['type' => 'non_returnable', 'purpose' => 'scrap_sale', 'department_id' => $dept['Housekeeping'] ?? null, 'party_name' => 'Ganesh Scrap Traders',
            'reason' => 'Condemned mattresses – sold as scrap, receipt SCR-2211', 'items' => [['description' => 'Old mattresses (condemned)', 'quantity' => 12, 'unit' => 'nos', 'est_value' => 3600]]]);
        auth()->setUser($fom); $G::approve($p); auth()->setUser($sec); $G::markOut($p, 'Ganesh', 'KA04T3030');
        // 6. awaiting approval (raised by the chief engineer – FOM must approve)
        $mkP($eng, ['type' => 'returnable', 'purpose' => 'repair', 'department_id' => $dept['Maintenance'] ?? null, 'party_name' => 'Kirloskar Authorised Service',
            'expected_return_date' => $bd->copy()->addDays(10)->toDateString(), 'reason' => 'DG set AVR card burnt – bench repair',
            'items' => [['description' => 'AVR control card – DG 250 kVA', 'quantity' => 1, 'unit' => 'nos', 'est_value' => 18500]]]);
        // 7. approved, waiting at the gate
        $p = $mkP($hk, ['type' => 'returnable', 'purpose' => 'event', 'department_id' => $dept['F&B Service'] ?? null, 'party_name' => 'Outdoor catering – Infosys offsite',
            'expected_return_date' => $bd->copy()->addDays(2)->toDateString(), 'items' => [['description' => 'Chafing dishes', 'quantity' => 12, 'unit' => 'nos', 'est_value' => 30000], ['description' => 'Serving trays', 'quantity' => 20, 'unit' => 'nos']]]);
        auth()->setUser($fom); $G::approve($p);
        // 8. rejected
        $p = $mkP($hk, ['type' => 'non_returnable', 'purpose' => 'other', 'party_name' => 'Staff – personal use', 'reason' => 'Requesting to take home discarded bedsheets',
            'items' => [['description' => 'Discarded bedsheets', 'quantity' => 10, 'unit' => 'pcs']]]);
        auth()->setUser($fom); $G::reject($p, 'Discarded linen goes to the rag stock / CSR donation – not for personal use');

        // incidents
        $I = \App\Services\IncidentService::class;
        auth()->setUser($sec);
        $st = $stays[2] ?? null;
        $i1 = $I::report(['type' => 'theft', 'severity' => 'high', 'occurred_at' => $now->copy()->subHours(6)->format('Y-m-d H:i'), 'location' => 'Guest room',
            'room_id' => $st?->room_id, 'reservation_room_id' => $st?->id, 'title' => 'Guest reports wristwatch missing from room',
            'description' => 'Guest says a steel wristwatch left on the bedside table was missing when they returned at 14:30. Room was serviced at 11:00.',
            'persons_involved' => 'Guest; room attendant on the floor', 'action_taken' => 'Room sealed, housekeeping supervisor informed, CCTV for 10:00–14:30 requested',
            'loss_amount' => 15000, 'assigned_to' => $sec->id]);
        $I::note($i1, 'Floor CCTV reviewed 10:00–12:00: only the room attendant entered (11:02–11:24). Attendant interviewed – denies. Guest to check luggage again.', 'investigating');
        $I::report(['type' => 'disturbance', 'severity' => 'medium', 'occurred_at' => $now->copy()->subDays(1)->setTime(23, 40)->format('Y-m-d H:i'), 'location' => 'Rooftop bar',
            'title' => 'Two patrons argued loudly at closing time', 'description' => 'Heated argument over the bill split; one glass broken.',
            'action_taken' => 'Bar manager and security separated them; both left by 00:10.', 'loss_amount' => 450]);
        $c1 = $I::report(['type' => 'fire_alarm', 'severity' => 'high', 'occurred_at' => $now->copy()->subDays(3)->setTime(7, 15)->format('Y-m-d H:i'), 'location' => 'Kitchen – tandoor section',
            'title' => 'Fire alarm triggered by tandoor smoke', 'description' => 'Smoke detector near the tandoor went off; no fire. Panel showed zone 3.',
            'action_taken' => 'Alarm silenced after check, zone reset, guests on floor 1 reassured by phone.', 'assigned_to' => $eng->id]);
        $I::close($c1, 'False alarm – detector relocated 1.5 m away from the tandoor exhaust by engineering.');
        $c2 = $I::report(['type' => 'medical', 'severity' => 'high', 'occurred_at' => $now->copy()->subDays(6)->setTime(20, 5)->format('Y-m-d H:i'), 'location' => 'Lobby',
            'title' => 'Elderly guest felt dizzy in the lobby', 'description' => 'Guest felt faint while waiting for a cab.', 'injuries' => true,
            'action_taken' => 'First aid given, on-call doctor attended in 15 minutes, BP normal after rest.']);
        $I::close($c2, 'Guest recovered; family informed; follow-up call next morning – guest fine.');
        $c3 = $I::report(['type' => 'trespass', 'severity' => 'medium', 'occurred_at' => $now->copy()->subDays(8)->setTime(2, 30)->format('Y-m-d H:i'), 'location' => 'Service entrance / car park',
            'title' => 'Unknown person tried to enter through the service gate', 'description' => 'Man without ID tried to walk in through the service gate at night, claimed to be a vendor.',
            'action_taken' => 'Stopped at the gate, not allowed in; left when asked. Beat police informed.', 'police_informed' => true, 'police_station' => 'Indiranagar PS', 'police_ref' => 'DD-114/2026']);
        $I::close($c3, 'No further sightings. Night guard briefed; service gate kept locked after 23:00.');
        $I::report(['type' => 'damage', 'severity' => 'low', 'occurred_at' => $now->copy()->subDays(2)->setTime(16, 0)->format('Y-m-d H:i'), 'location' => 'Car park level B1',
            'title' => 'Guest car bumper scraped against a pillar', 'description' => 'Valet reported a light scrape while parking a guest car.', 'loss_amount' => 2500,
            'action_taken' => 'Photos taken, guest informed at once, valet supervisor report attached.']);

        auth()->setUser($prevUser ?? $user);
    }

    protected function seedPrivacy(bool $rich, Carbon $today, Models\User $user): void
    {
        if (! $rich) {
            return;
        }
        $P = \App\Services\PrivacyService::class;
        foreach (['dpo_name' => 'Meera Iyer', 'dpo_email' => 'privacy@nowillsgrand.example', 'dpo_phone' => '+91 80 4000 1200'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        $fom = Models\User::where('username', 'fom')->where('hotel_id', $user->hotel_id)->first() ?? $user;
        $desk = Models\User::where('username', 'frontdesk')->where('hotel_id', $user->hotel_id)->first() ?? $user;
        $g = fn (string $f, string $l) => Models\Guest::where('first_name', $f)->where('last_name', $l)->first();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $doc = function (Models\Guest $guest, string $type, Carbon $at) use ($png, $user) {
            $path = 'guest-docs/'.$guest->hotel_id.'/'.$guest->id.'/'.\Illuminate\Support\Str::random(32).'.png';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $png);
            $d = $guest->documents()->create(['type' => $type, 'path' => $path, 'original_name' => $type.'.png', 'mime' => 'image/png', 'size' => strlen($png), 'uploaded_by' => $user->id]);
            $d->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            return $d;
        };
        $msg = function (Carbon $at, string $to, string $body) {
            $m = new Models\MessageLog;
            $m->forceFill(['hotel_id' => \App\Support\Tenant::id(), 'channel' => 'sms', 'driver' => 'log', 'to' => $to, 'template' => 'custom', 'body' => $body, 'status' => 'sent', 'created_at' => $at, 'updated_at' => $at])->save();
        };
        $mail = function (Carbon $at, string $to, string $subject) {
            $m = new Models\EmailLog;
            $m->forceFill(['hotel_id' => \App\Support\Tenant::id(), 'to_email' => $to, 'subject' => $subject, 'template' => 'reservation', 'status' => 'sent', 'created_at' => $at, 'updated_at' => $at])->save();
        };
        $legacy = function (string $f, string $l, string $email, int $years) {
            $at = now()->subYears($years)->subDays(20);
            $x = Models\Guest::create(['first_name' => $f, 'last_name' => $l, 'email' => $email, 'phone' => '+9198'.random_int(10000000, 99999999), 'nationality' => 'India',
                'city' => 'Bengaluru', 'country' => 'India', 'last_stay_on' => $at->toDateString(), 'first_stay_on' => $at->toDateString(), 'stays_count' => 1, 'nights_count' => 2,
                'notes' => 'Imported from the legacy front-office system']);
            $x->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            return $x;
        };

        // ---- last night's retention run: old scans, message contents and one long-inactive profile
        $osei = $g('Samuel', 'Adeyemi');
        $yaa = $g('Yaa', 'Asantewaa');
        $oliver = $g('Oliver', 'Brown');
        if ($osei) {
            $doc($osei, 'passport', Carbon::parse($osei->last_stay_on ?? now()->subYear()));
            $doc($osei, 'visa', Carbon::parse($osei->last_stay_on ?? now()->subYear()));
        }
        $msg(now()->subDays(520), '+919845012345', 'Dear guest, your room 204 is ready. Welcome to Nowills Grand Hotel!');
        $msg(now()->subDays(505), '+919900112233', 'Thank you for staying with us. Your invoice has been e-mailed.');
        $mail(now()->subDays(500), 'old.guest@mail.example', 'Your reservation at Nowills Grand Hotel');
        $mail(now()->subDays(480), 'traveller@mail.example', 'Your invoice INV-00012');
        $legacy('Suresh', 'Rao', 'suresh.rao@mail.example', 10);
        $run = $P::runRetention('schedule');
        $at = now()->subDay()->setTime(3, 30);
        $run->forceFill(['created_at' => $at])->save();
        Models\Guest::where('privacy_status', 'erased')->update(['erased_at' => $at]);

        // ---- items due at the next run (shown on the retention preview)
        if ($yaa) {
            $doc($yaa, 'passport', Carbon::parse($yaa->last_stay_on ?? now()->subYear()));
        }
        if ($oliver) {
            $doc($oliver, 'passport', Carbon::parse($oliver->last_stay_on ?? now()->subYears(2)));
        }
        $inHouse = Models\Reservation::with('guest')->where('status', 'checked_in')->orderBy('id')->first();
        if ($inHouse?->guest) {
            $doc($inHouse->guest, 'aadhaar', now()->subHours(20));   // recent – kept
        }
        foreach ([400, 395, 390] as $d) {
            $msg(now()->subDays($d), '+9198'.random_int(10000000, 99999999), 'Your airport pickup is confirmed for tomorrow 06:30. Driver: Ravi, KA-01-AB-1234.');
        }
        foreach ([420, 410] as $d) {
            $mail(now()->subDays($d), 'guest'.$d.'@mail.example', 'Pre-arrival: your stay at Nowills Grand Hotel');
        }
        $legacy('Ramesh', 'Iyer', 'ramesh.iyer@mail.example', 9);
        $legacy('Pooja', 'Shah', 'pooja.shah@mail.example', 11);

        // ---- data-principal requests
        $stamp = function (Models\PrivacyRequest $r, Carbon $created) {
            $r->forceFill(['created_at' => $created])->save();
            Models\PrivacyRequestEvent::where('privacy_request_id', $r->id)->where('action', 'received')->update(['created_at' => $created]);
        };
        $ev = function (Models\PrivacyRequest $r, string $action, string $note, Models\User $by, Carbon $at) {
            Models\PrivacyRequestEvent::create(['privacy_request_id' => $r->id, 'action' => $action, 'note' => $note, 'user_id' => $by->id, 'created_at' => $at]);
        };
        $new = function (array $a, Carbon $received) use ($P, $fom, $stamp) {
            $r = $P::createRequest($a + ['status' => 'open', 'received_at' => $received], $fom->id);
            $stamp($r, $received);

            return $r;
        };
        // 1. access – completed on time
        if ($hiro = $g('Hiroshi', 'Tanaka')) {
            $t0 = now()->subDays(26)->setTime(11, 10);
            $r = $new(['type' => 'access', 'channel' => 'email', 'guest_id' => $hiro->id, 'requester_name' => 'Hiroshi Tanaka', 'requester_email' => $hiro->email,
                'details' => 'Please send me a copy of all personal information you hold about me, including stays and marketing preferences.'], $t0);
            $r->forceFill(['verified_at' => $t0->copy()->addDays(2), 'verified_by' => $fom->id, 'verification_method' => 'known_contact', 'exports' => 1,
                'status' => 'completed', 'completed_at' => $t0->copy()->addDays(6), 'completed_by' => $fom->id,
                'outcome' => 'Password-protected JSON export and printed report e-mailed; password sent by SMS to the mobile on file.'])->save();
            $ev($r, 'verified', 'Identity verified – Reply from e-mail / mobile on file.', $fom, $t0->copy()->addDays(2));
            $ev($r, 'exported', 'Data export generated (JSON).', $fom, $t0->copy()->addDays(6));
            $ev($r, 'completed', $r->outcome, $fom, $t0->copy()->addDays(6));
            Models\PiiAccessLog::create(['guest_id' => $hiro->id, 'user_id' => $fom->id, 'action' => 'export', 'detail' => "Access request {$r->request_no} (JSON)", 'reason' => 'Data-principal access request', 'created_at' => $t0->copy()->addDays(6)]);
        }
        // 2. erasure – executed; foreign national → restricted (police register hold)
        if ($osei) {
            $t0 = now()->subDays(18)->setTime(15, 40);
            $r = $new(['type' => 'erasure', 'channel' => 'email', 'guest_id' => $osei->id, 'requester_name' => 'Samuel Adeyemi', 'requester_email' => $osei->email,
                'details' => 'I no longer travel to Ghana. Please delete my details and stop all communication.'], $t0);
            $r->forceFill(['verified_at' => $t0->copy()->addDays(1), 'verified_by' => $fom->id, 'verification_method' => 'booking_details'])->save();
            $ev($r, 'verified', 'Identity verified – Booking details matched. Stay dates and room confirmed by phone.', $fom, $t0->copy()->addDays(1));
            $P::erase($osei->fresh(), $r, $user->id);
            $done = $t0->copy()->addDays(3);
            $r->forceFill(['completed_at' => $done])->save();
            Models\PrivacyRequestEvent::where('privacy_request_id', $r->id)->where('action', 'erased')->update(['created_at' => $done]);
            $osei->forceFill(['erased_at' => $done])->save();
        }
        // 3. erasure – verified, waiting for the DPO to execute (foreign national → will be restricted)
        if ($oliver) {
            $t0 = now()->subDays(6)->setTime(10, 5);
            $r = $new(['type' => 'erasure', 'channel' => 'email', 'guest_id' => $oliver->id, 'requester_name' => 'Oliver Brown', 'requester_email' => $oliver->email, 'booking_ref' => 'Stay July 2024',
                'details' => 'Under data-protection law I ask you to erase my personal data, including my passport scan.'], $t0);
            $r->forceFill(['verified_at' => now()->subDays(3), 'verified_by' => $fom->id, 'verification_method' => 'booking_details', 'assigned_to' => $user->id])->save();
            $ev($r, 'verified', 'Identity verified – Booking details matched. Arrival date, room type and last four digits of the card used matched.', $fom, now()->subDays(3));
            $ev($r, 'assigned', 'Assigned to '.$user->name.' (DPO) for execution.', $fom, now()->subDays(3));
        }
        // 4. correction – overdue (identity not yet verified)
        if ($pn = $g('Priyanka', 'Nair')) {
            $new(['type' => 'correction', 'channel' => 'letter', 'guest_id' => $pn->id, 'requester_name' => 'Priyanka Nair', 'requester_email' => $pn->email,
                'details' => "My surname is now Nair-Menon. Please also remove my old office e-mail and update my address to 14 Palm Grove, Kochi."], now()->subDays(34)->setTime(9, 0));
        }
        // 5. consent withdrawal – done immediately at the desk
        if ($yaa) {
            $t0 = now()->subDays(12)->setTime(18, 20);
            $r = $new(['type' => 'consent', 'channel' => 'in_person', 'guest_id' => $yaa->id, 'requester_name' => 'Yaa Asantewaa', 'requester_email' => $yaa->email,
                'details' => 'Asked at check-out to stop all promotional messages.'], $t0);
            \App\Services\CrmService::setConsent($yaa, ['email' => false, 'sms' => false, 'whatsapp' => false], 'privacy_request');
            $r->forceFill(['status' => 'completed', 'completed_at' => $t0->copy()->addMinutes(5), 'completed_by' => $desk->id,
                'outcome' => 'Marketing consent withdrawn on all channels. No further marketing will be sent.'])->save();
            $ev($r, 'completed', $r->outcome, $desk, $t0->copy()->addMinutes(5));
        }
        // 6. grievance – due in 5 days
        if ($sam = $g('Samuel', 'Owusu')) {
            $r = $new(['type' => 'grievance', 'channel' => 'email', 'guest_id' => $sam->id, 'requester_name' => 'Samuel Owusu', 'requester_email' => $sam->email,
                'details' => 'I unsubscribed from your e-mails in July but still received a WhatsApp offer last week. How did you get my WhatsApp consent?'], now()->subDays(25)->setTime(13, 45));
            $r->forceFill(['assigned_to' => $fom->id])->save();
        }
        // 7. web request – waiting for e-mail confirmation
        [$web] = $P::publicSubmit(['type' => 'access', 'requester_name' => 'Kofi Boateng', 'requester_email' => 'kofi.boateng@mail.example', 'relationship' => 'self',
            'details' => 'What data do you have about me from my stay last December?']);
        $web->forceFill(['created_at' => now()->subHours(20), 'received_at' => now()->subHours(20)])->save();
        // 8. rejected – no data held
        $r = $new(['type' => 'access', 'channel' => 'email', 'requester_name' => 'A. Fernandes', 'requester_email' => 'a.fernandes@mail.example',
            'details' => 'Send me everything you know about me.'], now()->subDays(41)->setTime(10, 0));
        $r->forceFill(['status' => 'rejected', 'completed_at' => now()->subDays(38), 'completed_by' => $fom->id, 'rejection_reason' => 'No personal data held for this person'])->save();
        $ev($r, 'rejected', 'No personal data held for this person – searched profiles, bookings, banquet and POS records by name, e-mail and phone.', $fom, now()->subDays(38));

        // ---- PII access log (reveals and document views over the last fortnight)
        foreach ([[$fom, 'reveal', 'ID number / passport / date of birth', 'Form-C submission', 2], [$fom, 'reveal', 'ID number / passport / date of birth', 'Police verification call', 4],
            [$desk, 'document', 'Passport', null, 5], [$fom, 'reveal', 'ID number / passport / date of birth', 'Guest asked to confirm the passport on file', 9]] as $i => [$u, $a, $d, $why, $ago]) {
            $guest = Models\Guest::whereNull('privacy_status')->whereNotNull('passport_no')->orderBy('id')->skip($i)->first() ?? Models\Guest::whereNull('privacy_status')->orderBy('id')->first();
            Models\PiiAccessLog::create(['guest_id' => $guest->id, 'user_id' => $u->id, 'action' => $a, 'detail' => $d, 'reason' => $why, 'ip' => '10.0.0.'.(20 + $i), 'created_at' => now()->subDays($ago)->setTime(10 + $i, 15)]);
        }

        // ---- breach register
        $d1 = now()->subDays(70)->setTime(16, 20);
        Models\DataBreach::create(['ref' => $P::nextBreachRef(), 'title' => 'Arrival list e-mailed to the wrong travel agent', 'severity' => 'medium', 'categories' => 'contact,stay', 'affected_count' => 14,
            'description' => "The next-day arrival list (names, mobile numbers, room types, arrival times) for a tour group was e-mailed to another agent with a similar address.",
            'detected_at' => $d1, 'occurred_at' => $d1->copy()->subMinutes(40), 'notifiable' => true, 'status' => 'closed',
            'contained_at' => $d1->copy()->addHours(3), 'containment' => 'Recipient agent called; confirmed deletion in writing. Auto-complete of external addresses disabled in the reservations mailbox.',
            'authority_notified_at' => $d1->copy()->addHours(30), 'authority_ref' => 'DPB/2026/00871', 'subjects_notified_at' => $d1->copy()->addDays(2), 'subjects_notice' => 'E-mail and phone call to the 14 guests on the list',
            'root_cause' => 'Wrong address picked from the mail client auto-complete; lists sent as unprotected attachments.',
            'lessons' => 'Group lists now shared through password-protected links only; staff refresher on data handling done.', 'closed_at' => $d1->copy()->addDays(10), 'reported_by' => $fom->id]);
        $d2 = now()->subHours(30);
        Models\DataBreach::create(['ref' => $P::nextBreachRef(), 'title' => 'Phishing – reservations mailbox credentials used from abroad', 'severity' => 'high', 'categories' => 'contact,stay,credentials', 'affected_count' => 230,
            'description' => "A reservations agent entered the mailbox password on a fake login page. Sign-ins from an unknown country were seen for about two hours; the mailbox holds booking confirmations with guest names, e-mails, phones and stay dates.",
            'detected_at' => $d2, 'occurred_at' => $d2->copy()->subHours(3), 'notifiable' => true, 'status' => 'contained', 'contained_at' => $d2->copy()->addHours(2),
            'containment' => 'Password reset, all sessions revoked, MFA enforced on every mailbox, malicious forwarding rule removed.', 'reported_by' => $fom->id]);
    }

}
