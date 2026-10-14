<?php

namespace Database\Seeders;

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

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();
        $group = Models\HotelGroup::create(['code' => 'NWGROUP', 'name' => 'Nowills Hospitality Group']);

        $main = Models\Hotel::create([
            'hotel_group_id' => $group->id, 'code' => 'NOWILLS', 'name' => 'Nowills Grand Hotel', 'legal_name' => 'Nowills Hospitality Ltd.',
            'property_type' => 'hotel', 'address' => '12 Independence Avenue, Ridge', 'city' => 'Accra', 'state' => 'Greater Accra', 'country' => 'Ghana', 'pincode' => 'GA-029-1234',
            'phone' => '+233 30 274 1234', 'email' => 'frontoffice@nowills.example', 'website' => 'www.nowills.example', 'tax_number' => 'C0001234567',
            'currency' => 'GHS', 'currency_symbol' => 'GH₵', 'timezone' => 'Africa/Accra', 'business_date' => $today->toDateString(),
            'invoice_footer' => 'Thank you for staying with us. Check-out time 11:00 AM. This is a computer generated invoice.',
            // v7.52 on the GRA VAT register since before the demo window: a supplier of services has no threshold (Act 1151)
            'vat_registered' => true, 'vat_registered_at' => '2019-04-01', 'vat_supply_kind' => 'services', 'supports_e_invoicing' => true,
        ]);
        $resort = Models\Hotel::create([
            'hotel_group_id' => $group->id, 'code' => 'NWRESORT', 'name' => 'Nowills Lake Resort', 'property_type' => 'resort',
            'address' => 'Kokrobite Beach Road', 'city' => 'Kokrobite', 'state' => 'Greater Accra', 'country' => 'Ghana', 'phone' => '+233 30 290 5678', 'tax_number' => 'C0007654321',
            'email' => 'resort@nowills.example', 'currency' => 'GHS', 'currency_symbol' => 'GH₵', 'timezone' => 'Africa/Accra', 'business_date' => $today->toDateString(),
            'vat_registered' => true, 'vat_supply_kind' => 'services', 'supports_e_invoicing' => true,   // registered, no date on file
        ]);

        foreach ([$main, $resort] as $h) {
            HotelProvisioner::provision($h);
        }

        // ---------- Users ----------
        Tenant::set($main);
        $roles = Models\Role::pluck('id', 'name');
        $admin = new Models\User(['name' => 'System Administrator', 'username' => 'admin', 'email' => 'admin@nowills.example', 'password' => 'Admin@123']);
        $admin->hotel_id = $main->id; $admin->is_super_admin = true; $admin->role_id = $roles['Administrator']; $admin->save();
        $ga = new Models\User(['name' => 'Group Admin', 'username' => 'groupadmin', 'email' => 'group@nowills.example', 'password' => 'Admin@123']);
        $ga->hotel_id = $main->id; $ga->hotel_group_id = $group->id; $ga->is_group_admin = true; $ga->role_id = $roles['Administrator']; $ga->save();
        foreach ([['Priya Sharma', 'frontdesk', 'Receptionist'], ['Ravi Kumar', 'fom', 'Front Office Manager'], ['Anita Das', 'housekeeping', 'Housekeeping Supervisor'], ['Suresh Nair', 'cashier', 'Restaurant Cashier'], ['Arjun Menon', 'restaurant', 'F&B Manager'], ['Meera Iyer', 'accounts', 'Accountant'], ['Vikram Rao', 'store', 'Store Keeper'], ['Chef Mani', 'chef', 'Kitchen / Chef'], ['Ramesh Gowda', 'attendant', 'Room Attendant'], ['Grace Mensah', 'spa', 'Spa Desk / Therapist'], ['Anjali Menon', 'hr', 'HR Manager'], ['Kwame Boateng', 'laundry', 'Laundry Attendant'], ['Joseph Thomas', 'engineer', 'Chief Engineer'], ['Farah Khan', 'banquet', 'Banquet Manager'], ['Efua Asante', 'marketing', 'Sales & Marketing'], ['Lakshmi Pillai', 'concierge', 'Transport Desk / Concierge'], ['Sunil Yadav', 'driver', 'Driver'], ['Imran Sheikh', 'security', 'Security Officer'], ['Nana Yaw Owusu', 'owner', "Owner's Representative"], ['Kojo Mensah', 'digital', 'Digital Marketing Executive'], ['Akosua Darko', 'bizdev', 'Business Development Manager'], ['Yaw Boateng', 'cro', 'Reservations Agent (CRO)'], ['Esi Quaye', 'qa', 'Quality Manager'], ['Nana Sarpong', 'hse', 'Health & Safety Officer'], ['Ebenezer Ankrah', 'costctl', 'Cost Controller'], ['Kwabena Tetteh', 'valet', 'Valet / Parking Attendant']] as [$n, $u, $r]) {
            $x = new Models\User(['name' => $n, 'username' => $u, 'password' => 'Pass@123']);
            $x->hotel_id = $main->id; $x->role_id = $roles[$r]; $x->save();
        }
        Tenant::set($resort);
        $ra = new Models\User(['name' => 'Resort Manager', 'username' => 'admin', 'password' => 'Admin@123']);
        $ra->hotel_id = $resort->id; $ra->is_super_admin = true; $ra->role_id = Models\Role::where('name', 'Administrator')->value('id'); $ra->save();

        Models\User::where('hotel_id', $main->id)->where('username', 'cro')->update(['hotel_group_id' => $group->id]); // v6.4: the CRO agent sells every hotel of the group
        $this->seedProperty($main, $admin, true);
        $this->seedProperty($resort, $ra, false);
        $this->seedCroGroup($main, $resort); // v6.4: referrals from the Grand Hotel to the Lake Resort (needs both properties)
        Tenant::set(null);
    }

    /**
     * v6.5 demo: the Grand Hotel's quality programme – 43 brand standards (5 critical, SOP-linked), 7 checklists, 22 audits
     * completed over five months through the service (so scores, critical auto-fails, repeat findings and corrective actions
     * are genuine), then backdated. Guest-room inspection in progress today, fire & safety audit 5 days late, FO checklist due.
     * Older actions verified (some closed late), recent ones done-to-verify, reopened, in progress, unassigned and overdue.
     */
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

    /**
     * v6.8 F&B cost control: bar & kitchen stock items (a Liquor category), recipes for the 21 dishes that had none (the 9 original recipes are
     * left as they are), recipe-card headers (most approved), 4 applied yield tests, 28 days of settled restaurant & bar bills with a realistic
     * popularity mix and recipe consumption, officers' checks, staff canteen issues with the staff-meal log, a posted kitchen / bar stock count
     * with shrinkage, two historic price changes and plate-cost snapshots (four dishes creeping up). Runs before seedParking and relies on its
     * LedgerService::sync(true) for the back-dated stock days and bills. All dates are on or before the day before the business date.
     */
    protected function seedFb(bool $rich): void
    {
        $FC = \App\Services\FbCostService::class;
        $FC::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $chef = $u('chef') ?? $admin;
        $fbm = $u('restaurant') ?? $admin;
        $cashier = $u('cashier') ?? $admin;
        $B = bdate()->copy()->startOfDay();
        mt_srand(6800);
        Auth::setUser($chef);
        $kitchen = (int) Models\Store::where('type', 'kitchen')->value('id');
        $barStore = (int) Models\Store::where('type', 'bar')->value('id');
        Models\Outlet::where('type', 'bar')->update(['store_id' => $barStore]); // the bar sells from its own store, so bottles are counted there
        $groc = Models\ItemCategory::where('name', 'Grocery')->value('id');
        $bev = Models\ItemCategory::where('name', 'Beverages')->value('id');
        $liq = Models\ItemCategory::create(['name' => 'Liquor', 'fb_class' => 'beverage'])->id;
        // [code, name, unit, cost, category, opening qty in the kitchen / outlet store]
        foreach ([['FB101', 'Mutton (bone-in)', 'kg', 420, $groc, 40], ['FB102', 'Onion', 'kg', 18, $groc, 120], ['FB103', 'Tomato', 'kg', 22, $groc, 90],
            ['FB104', 'Black Lentils (Urad)', 'kg', 48, $groc, 60], ['FB105', 'Fresh Milk', 'ltr', 16, $groc, 260], ['FB106', 'Tea Leaves', 'kg', 160, $groc, 8],
            ['FB107', 'Coffee Powder', 'kg', 240, $groc, 8], ['FB108', 'Sugar', 'kg', 20, $groc, 60], ['FB109', 'Vanilla Ice Cream', 'ltr', 70, $groc, 30],
            ['FB110', 'Idli / Dosa Rice', 'kg', 30, $groc, 40], ['FB111', 'Potato', 'kg', 15, $groc, 60], ['FB112', 'Bread Loaf', 'loaf', 25, $groc, 40],
            ['FB113', 'Ginger-Garlic Paste', 'kg', 60, $groc, 12], ['FB114', 'Garam Masala', 'kg', 220, $groc, 5], ['FB115', 'Mixed Vegetables', 'kg', 25, $groc, 80],
            ['FB116', 'Gram Flour (Besan)', 'kg', 40, $groc, 15], ['FB117', 'Khoya (Milk Solids)', 'kg', 90, $groc, 12], ['FB118', 'Lime', 'kg', 30, $groc, 25],
            ['FB119', 'Fresh Mint', 'bunch', 5, $groc, 90], ['FB120', 'Chocolate Sauce', 'ltr', 85, $groc, 5], ['FB121', 'Soda Water 300ml', 'btl', 6, $bev, 300],
            ['FB131', 'Johnnie Walker Black 750ml', 'btl', 2600, $liq, 14], ['FB132', 'Old Monk Rum 750ml', 'btl', 520, $liq, 18],
            ['FB133', 'Kingfisher Beer 330ml', 'btl', 85, $liq, 420], ['FB134', 'House Red Wine 750ml', 'btl', 420, $liq, 40]] as [$code, $nm, $unit, $cost, $cat, $open]) {
            $it = Models\InventoryItem::create(['item_category_id' => $cat, 'code' => $code, 'name' => $nm, 'unit' => $unit, 'reorder_level' => max(1, round($open / 5)), 'last_cost' => $cost, 'yield_pct' => 100, 'is_active' => true]);
            $split = $cat === $liq ? [$barStore => $open] : (in_array($code, ['FB108', 'FB118', 'FB119', 'FB121'], true) ? [$kitchen => $open / 2, $barStore => $open / 2] : [$kitchen => $open]);
            foreach ($split as $st => $q) {
                Models\StockMovement::create(['inventory_item_id' => $it->id, 'store_id' => $st, 'date' => $B->copy()->subDays(32)->toDateString(), 'type' => 'opening', 'qty' => $q, 'rate' => $cost,
                    'reference' => 'OPEN-FB', 'remarks' => 'Opening stock – F&B cost control', 'user_id' => $chef->id]);
            }
        }
        $I = Models\InventoryItem::pluck('id', 'code');
        foreach (['FS026' => 145, 'ITM004' => 40, 'ITM001' => 40, 'FS027' => 15, 'FS028' => 15, 'FS029' => 10, 'FS030' => 3, 'FS032' => 15, 'FS034' => 3] as $code => $open) { // top up grocery lines the kitchen never held
            Models\StockMovement::create(['inventory_item_id' => $I[$code], 'store_id' => $kitchen, 'date' => $B->copy()->subDays(32)->toDateString(), 'type' => 'opening', 'qty' => $open,
                'rate' => (float) Models\InventoryItem::whereKey($I[$code])->value('last_cost'), 'reference' => 'OPEN-FB', 'remarks' => 'Opening stock – F&B cost control', 'user_id' => $chef->id]);
        }
        // yield tests (four applied, one pending)
        foreach ([['FB101', 40, 5, 1.3, 0, 'Goat leg from Kaneshie market – bones & fat trimmed'], ['FB102', 38, 10, 1.0, 0, 'Peeled & topped'],
            ['FB103', 37, 8, 0.5, 0, 'Cored, bruised fruit removed'], ['FS032', 36, 4, 0.2, 0, 'Pin bones & skin'], ['FB118', 6, 2, 1.1, 0, 'Juice yield – pending review']] as [$code, $ago, $gross, $trim, $loss, $note]) {
            $t = $FC::recordYield(['inventory_item_id' => $I[$code], 'tested_on' => $B->copy()->subDays($ago)->toDateString(), 'gross_qty' => $gross, 'trim_qty' => $trim, 'cooking_loss_qty' => $loss, 'notes' => $note], $chef);
            if ($code !== 'FB118') {
                $FC::applyYield($t, $chef);
            }
        }
        // recipes – net quantity per portion (the 9 original recipes are re-saved unchanged so they get a card header)
        $M = Models\MenuItem::pluck('id', 'name');
        $new = [
            'Veg Spring Rolls' => [['FB115', .15], ['FS026', .06], ['ITM002', .08], ['FS034', .015], ['FB113', .01]],
            'Fish Amritsari' => [['FS032', .3], ['FB116', .06], ['ITM002', .1], ['FB113', .02], ['FB118', .02], ['FB114', .005]],
            'Dal Makhani' => [['FB104', .1], ['FS027', .04], ['FS028', .05], ['FB103', .08], ['FB102', .05], ['FB113', .01], ['FB114', .004]],
            'Mutton Rogan Josh' => [['FB101', .3], ['FB102', .12], ['FB103', .08], ['ITM002', .05], ['FB113', .02], ['FB114', .008], ['FS028', .03]],
            'Butter Naan' => [['FS026', .12], ['FS027', .015], ['FB105', .03]],
            'Garlic Naan' => [['FS026', .12], ['FS027', .02], ['FB113', .01]],
            'Masala Dosa' => [['FB110', .12], ['FB104', .03], ['FB111', .15], ['FB102', .04], ['ITM002', .03]],
            'Idli Vada' => [['FB110', .1], ['FB104', .06], ['ITM002', .05], ['FB102', .02]],
            'Omelette' => [['FS029', .1], ['FB102', .03], ['FB103', .03], ['FS027', .01]],
            'Continental Breakfast' => [['FS029', .067], ['FB112', .2], ['FS027', .03], ['FB105', .25], ['FB111', .15], ['FB103', .05], ['FB107', .012]],
            'Gulab Jamun' => [['FB117', .06], ['FB108', .08], ['ITM002', .04], ['FS026', .01]],
            'Ice Cream Sundae' => [['FB109', .2], ['FB120', .03], ['FS030', .015]],
            'Masala Chai' => [['FB105', .12], ['FB106', .006], ['FB108', .015]],
            'Filter Coffee' => [['FB105', .12], ['FB107', .012], ['FB108', .015]],
            'Fresh Lime Soda' => [['FB118', .05], ['FB121', 1], ['FB108', .02]],
            'Cold Coffee' => [['FB105', .25], ['FB107', .015], ['FB109', .08], ['FB108', .02]],
            'Kingfisher Premium' => [['FB133', 1]],
            'Old Monk (60ml)' => [['FB132', .08]],
            'Johnnie Walker Black (60ml)' => [['FB131', .08]],
            'House Red Wine (Glass)' => [['FB134', .2]],
            'Mojito (Mocktail)' => [['FB118', .05], ['FB119', .25], ['FB108', .025], ['FB121', 1]],
        ];
        $methods = [
            'Butter Chicken' => "1. Marinate the chicken in yoghurt, ginger-garlic and spices for at least 4 hours.\n2. Char in the tandoor to 75 °C core; rest.\n3. Simmer the makhani gravy (tomato, butter, cashew), add the chicken, finish with cream and kasuri methi.\n4. Portion 250 g into a copper handi.",
            'Mutton Rogan Josh' => "1. Brown the trimmed mutton in batches.\n2. Sweat onion, add ginger-garlic, Kashmiri chilli and garam masala.\n3. Add tomato and the mutton; braise covered 90 min until tender.\n4. Finish with cream; hold at 63 °C or above; portion 300 g net meat.",
            'Dal Makhani' => "1. Soak the urad overnight; pressure-cook until soft.\n2. Simmer 3 hours with tomato, butter and ginger-garlic, stirring.\n3. Finish with cream and butter; portion one bowl (220 g).",
            'Masala Dosa' => "1. Ferment the rice-urad batter 10–12 hours.\n2. Temper potato masala with mustard seeds and onion.\n3. Spread the batter thin on the tawa, oil the edges, fill and fold. Serve with sambar and chutney.",
            'Mojito (Mocktail)' => "1. Muddle lime wedges, mint leaves and sugar in a highball.\n2. Fill with crushed ice, top with soda and stir.\n3. Garnish with a mint sprig and a lime wheel.",
            'Johnnie Walker Black (60ml)' => "Pour 60 ml with the jigger – no free pouring. Serve neat, on the rocks or with a mixer on request.",
        ];
        $portion = ['Starters' => '6 pieces', 'Main Course' => '1 handi (250 g)', 'Breads & Rice' => '1 piece / 1 bowl', 'Breakfast' => '1 plate', 'Desserts' => '1 bowl', 'Beverages' => '1 glass', 'Bar' => '1 measure / bottle'];
        $items = Models\MenuItem::with(['category', 'recipes'])->orderBy('id')->get();
        $drafts = ['Veg Spring Rolls', 'Cold Coffee', 'Garlic Naan'];
        foreach ($items as $mi) {
            $lines = isset($new[$mi->name])
                ? collect($new[$mi->name])->map(fn ($l) => ['inventory_item_id' => $I[$l[0]], 'qty' => $l[1]])->all()
                : $mi->recipes->map(fn ($r) => ['inventory_item_id' => $r->inventory_item_id, 'qty' => (float) $r->qty])->all();
            if (! $lines) {
                continue;
            }
            $card = $FC::saveCard($mi, ['lines' => $lines, 'batch_portions' => in_array($mi->category?->name, ['Main Course', 'Desserts'], true) ? 10 : 1,
                'portion_size' => $portion[$mi->category?->name] ?? null, 'prep_minutes' => match ($mi->category?->name) { 'Main Course' => 35, 'Starters' => 20, 'Breakfast' => 12, 'Bar', 'Beverages' => 3, default => 10 },
                'method' => $methods[$mi->name] ?? '1. Weigh every ingredient against this card.'."\n".'2. Prepare and cook to the standard; check seasoning.'."\n".'3. Plate the standard portion and garnish.',
                'garnish' => $mi->category?->name === 'Main Course' ? 'Coriander, cream swirl' : null], $chef);
            if (! in_array($mi->name, $drafts, true)) {
                $card->forceFill(['approved_by' => $fbm->id, 'approved_at' => $B->copy()->subDays(30)->setTime(11, 0)])->save();
            }
        }
        $FC::flush();
        // historic price changes (logged at the time)
        foreach ([['Kingfisher Premium', 20, 260, 280, 'Brewery price increase passed on'], ['Masala Chai', 12, 50, 60, 'Menu review – milk & sugar prices']] as [$nm, $ago, $old, $newP, $why]) {
            Models\FbPriceChange::create(['menu_item_id' => $M[$nm], 'effective_on' => $B->copy()->subDays($ago)->toDateString(), 'old_price' => $old, 'new_price' => $newP,
                'cost_at' => $FC::portionCost($M[$nm]), 'reason' => $why, 'user_id' => $fbm->id]);
        }
        // 28 days of settled bills with a weighted popularity mix
        $rest = Models\Outlet::where('type', 'restaurant')->first();
        $bar = Models\Outlet::where('type', 'bar')->first();
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $byName = $items->keyBy('name');
        $wRest = ['Butter Chicken' => 14, 'Chicken Biryani' => 12, 'Butter Naan' => 16, 'Garlic Naan' => 10, 'Paneer Butter Masala' => 8, 'Dal Makhani' => 9, 'Steamed Rice' => 7,
            'Jeera Rice' => 6, 'Chicken 65' => 8, 'Paneer Tikka' => 7, 'Veg Spring Rolls' => 3, 'Fish Amritsari' => 2, 'Mutton Rogan Josh' => 6, 'Veg Biryani' => 3, 'Masala Dosa' => 5,
            'Idli Vada' => 3, 'Omelette' => 4, 'Continental Breakfast' => 5, 'Gulab Jamun' => 6, 'Ice Cream Sundae' => 2, 'Masala Chai' => 8, 'Filter Coffee' => 5, 'Fresh Lime Soda' => 6,
            'Mineral Water' => 10, 'Cold Coffee' => 1];
        $wBar = ['Kingfisher Premium' => 20, 'Old Monk (60ml)' => 8, 'Johnnie Walker Black (60ml)' => 5, 'House Red Wine (Glass)' => 2, 'Mojito (Mocktail)' => 7, 'Fresh Lime Soda' => 3, 'Mineral Water' => 3];
        $pick = function (array $w) {
            $r = mt_rand(1, array_sum($w));
            foreach ($w as $k => $v) {
                if (($r -= $v) <= 0) {
                    return $k;
                }
            }

            return array_key_first($w);
        };
        $tables = Models\PosTable::where('outlet_id', $rest->id)->pluck('id')->all();
        $made = collect();
        for ($ago = 28; $ago >= 1; $ago--) {
            $day = $B->copy()->subDays($ago);
            $wk = in_array($day->dayOfWeek, [5, 6], true);
            foreach ([[$rest, $wRest, 8 + ($wk ? 4 : 0) + mt_rand(0, 2)], [$bar, $wBar, 4 + ($wk ? 3 : 0) + mt_rand(0, 1)]] as [$outlet, $w, $n]) {
                for ($k = 0; $k < $n; $k++) {
                    $comp = $outlet->id === $rest->id && $k === 0 && $ago % 3 === 0;
                    $covers = mt_rand(1, 4);
                    $lines = [];
                    for ($x = 0, $cnt = $covers + mt_rand(0, 2); $x < $cnt; $x++) {
                        $nm = $pick($w);
                        $lines[$nm] = ($lines[$nm] ?? 0) + (in_array($nm, ['Butter Naan', 'Garlic Naan', 'Kingfisher Premium'], true) && mt_rand(0, 2) === 0 ? 2 : 1);
                    }
                    $at = $day->copy()->setTime($outlet->id === $bar->id ? mt_rand(18, 22) : [8, 13, 13, 14, 19, 20, 21][mt_rand(0, 6)], mt_rand(0, 59));
                    $o = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'bill_no' => Numbering::next('pos_bill'), 'outlet_id' => $outlet->id,
                        'pos_table_id' => $outlet->id === $rest->id && $tables ? $tables[array_rand($tables)] : null, 'order_type' => 'dine_in', 'covers' => $covers,
                        'business_date' => $day->toDateString(), 'status' => 'settled', 'settlement' => $comp ? 'complimentary' : 'payment', 'payment_mode_id' => $comp ? null : $cash,
                        'customer_name' => $comp ? 'Officer\'s check – GM hosting a corporate client' : null, 'comp_reason' => $comp ? 'VIP / owner guest' : null, 'user_id' => $cashier->id, 'settled_by' => $cashier->id, 'settled_at' => $at]);
                    $sub = 0;
                    foreach ($lines as $nm => $q) {
                        $mi = $byName[$nm];
                        $rate = match (true) { $nm === 'Kingfisher Premium' && $ago > 20 => 260, $nm === 'Masala Chai' && $ago > 12 => 50, default => (float) $mi->price };
                        $o->items()->create(['menu_item_id' => $mi->id, 'item_name' => $mi->name, 'qty' => $q, 'rate' => $rate, 'tax_rate' => $mi->tax_rate, 'amount' => $q * $rate, 'kot_no' => 1]);
                        $sub += $q * $rate;
                    }
                    if (! $comp && mt_rand(1, 9) === 1) {
                        $o->discount = round($sub * 0.1, 2); // loyalty / corporate 10 %
                    }
                    $o->recalculate();
                    \App\Services\RecipeService::consume($o);
                    $made->push($o);
                }
            }
        }
        foreach (Models\PosOrder::where('status', 'settled')->where('business_date', '<', $B->toDateString())->whereNotIn('id', $made->pluck('id'))->get() as $o) {
            \App\Services\RecipeService::consume($o); // earlier seeded bills now that every dish has a recipe (idempotent)
        }
        if (\App\Services\PosEvatService::enabled()) {
            $clock = Carbon::getTestNow();
            foreach ($made->where('settlement', 'payment') as $o) {
                Carbon::setTestNow($o->settled_at->lt(now()) ? $o->settled_at : now());
                \App\Services\PosEvatService::generate($o->fresh());
                Carbon::setTestNow($clock);
            }
        }
        // staff canteen: daily issue from the kitchen store + the covers served
        $canteen = [['ITM001', 2.5], ['FB104', 1.2], ['FB102', 2], ['FB103', 1.5], ['ITM002', .8], ['FS026', 2], ['FB106', .1], ['FB105', 3], ['FB108', .5]];
        $cost = fn (string $c) => (float) Models\InventoryItem::whereKey($I[$c])->value('last_cost');
        for ($ago = 28; $ago >= 1; $ago--) {
            $day = $B->copy()->subDays($ago);
            $protein = $ago % 2 ? ['ITM004', 1.2] : ['FB115', 2.5];
            foreach (array_merge($canteen, [$protein]) as [$c, $q]) {
                Models\StockMovement::create(['inventory_item_id' => $I[$c], 'store_id' => $kitchen, 'date' => $day->toDateString(), 'type' => 'issue', 'qty' => -$q, 'rate' => $cost($c),
                    'department' => 'Staff canteen', 'department_id' => Models\Department::where('name', 'Kitchen')->value('id'), 'reference' => 'CANTEEN '.$day->format('Ymd'),
                    'remarks' => 'Staff meal ingredients', 'user_id' => $chef->id]);
            }
            $FC::saveMeal(['business_date' => $day->toDateString(), 'meal' => 'lunch', 'covers' => 22 + mt_rand(0, 5), 'cost_per_cover' => 25], $chef);
            $FC::saveMeal(['business_date' => $day->toDateString(), 'meal' => 'dinner', 'covers' => 12 + mt_rand(0, 4), 'cost_per_cover' => 25], $chef);
        }
        // bar count with shrinkage (bottles over-poured, half a crate of beer missing) – the kitchen store already has a draft count in progress
        $take = \App\Services\StockTakeService::create($barStore, $B->copy()->subDay()->toDateString(), null, 'Bar bottle count – F&B cost control');
        $short = ['FB131' => .6, 'FB132' => .9, 'FB133' => 12, 'FB134' => 1, 'FB121' => 4];
        $counts = [];
        $remarks = [];
        foreach ($take->lines()->with('item')->get() as $l) {
            $code = $l->item?->code;
            if (isset($short[$code])) {
                $counts[$l->id] = max(0, round((float) $l->system_qty - $short[$code], 2));
                $remarks[$l->id] = in_array($code, ['FB131', 'FB132', 'FB134'], true) ? 'Bottle weights below book – free pouring suspected' : ($code === 'FB133' ? 'Half a crate unaccounted for' : 'Count short');
            } elseif ((float) $l->system_qty > 0 && in_array($l->item?->item_category_id, [$groc, $bev, $liq], true)) {
                $counts[$l->id] = (float) $l->system_qty;
            }
        }
        \App\Services\StockTakeService::saveCounts($take, $counts, $remarks);
        \App\Services\StockTakeService::post($take);
        // plate-cost snapshots: 90 / 60 days ago and daily for the last 30 days (four dishes have crept up)
        $FC::flush();
        $creep = ['Mutton Rogan Josh' => .86, 'Johnnie Walker Black (60ml)' => .88, 'Fish Amritsari' => .9, 'Continental Breakfast' => .92];
        $rows = [];
        $now = now();
        foreach ($FC::costs() as $id => $c) {
            $mi = $items->firstWhere('id', $id);
            $f60 = $creep[$mi->name] ?? .98;
            $days = [90 => $f60 - .02, 60 => $f60] + array_fill_keys(range(30, 1), 1.0); // keep the day keys (array_merge would renumber them)
            foreach ($days as $ago => $f) {
                $price = match (true) { $mi->name === 'Kingfisher Premium' && $ago > 20 => 260, $mi->name === 'Masala Chai' && $ago > 12 => 50, default => (float) $mi->price };
                $rows[] = ['hotel_id' => $hid, 'menu_item_id' => $id, 'business_date' => $B->copy()->subDays($ago)->toDateString(), 'cost' => round($c * $f, 4), 'price' => $price, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            Models\FbCostSnapshot::insert($chunk);
        }
        $FC::flush();
        mt_srand();
    }

    /**
     * v6.9 talent demo: establishment and line managers; vacancies in every state (Guest Service Agent ×2 open with a full
     * pipeline – scorecards, an issued offer and an above-range offer awaiting approval; Fitness Instructor filled by Kofi;
     * Room Attendant awaiting approval to replace Lata; a cancelled Night Auditor; a draft Commis Chef); new starters (Abena's
     * probation review and written statement overdue, Kofi mid-onboarding); the 2026 mid-year appraisal cycle at every stage;
     * discipline (a live verbal warning, an expired written warning, a query whose reply is overdue, a dismissal) and a
     * confidential grievance; Lata working her notice and two former employees who left before the first seeded payroll.
     * No new active employees – payroll comparisons in earlier phases depend on the headcount.
     */
    protected function seedTalent(bool $rich): void
    {
        $TS = \App\Services\TalentService::class;
        $TS::ensureDefaults();
        if (! $rich) {
            return;
        }
        Models\Setting::updateOrCreate(['key' => 'talent_statement_from'], ['value' => '2023-01-01']);   // HR caught up on every current employee
        \App\Models\Setting::$version++;
        $TS::flush();
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $hr = $u('hr') ?? $admin;
        $fom = $u('fom') ?? $admin;
        $hk = $u('housekeeping') ?? $admin;
        $fb = $u('restaurant') ?? $admin;
        $spa = $u('spa') ?? $admin;
        $sec = $u('security') ?? $admin;
        $eng = $u('engineer') ?? $admin;
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();
        $E = fn (string $name) => Models\Employee::where('name', $name)->first();
        $dept = fn (string $name) => Models\Department::where('name', $name)->value('id');

        // ---- establishment, line managers, contracts, statements, probation
        foreach (['Front Office' => 4, 'Housekeeping' => 5, 'F&B Service' => 4, 'Kitchen' => 3, 'Maintenance' => 2, 'Accounts' => 2, 'Security' => 2, 'Management' => 2, 'Spa & Wellness' => 3] as $n => $x) {
            Models\Department::where('name', $n)->update(['establishment' => $x]);
        }
        $mgr = ['Ramesh Gowda' => $hk, 'Lata Bai' => $hk, 'Abena Owusu' => $hk, 'Joseph Thomas' => $admin, 'Imran Ali' => $eng, 'Deepa Nair' => $fom, 'Ravi Kumar' => $admin,
            'Mohan Lal' => $fb, 'Sunil Yadav' => $fb, 'Kiran Patil' => $sec, 'Grace Mensah' => $admin, 'Efua Asante' => $spa, 'Kofi Boateng' => $spa, 'Meera Iyer' => $admin, 'Anjali Menon' => $admin];
        foreach (Models\Employee::where('is_active', true)->get() as $e) {
            $j = $e->joining_date->copy();
            $late = in_array($e->name, ['Abena Owusu', 'Kofi Boateng'], true);
            $e->update(['manager_user_id' => $mgr[$e->name]?->id, 'statement_issued_on' => $late ? null : $j->copy()->addDays(20)->toDateString(),
                'probation_end' => $j->copy()->addMonthsNoOverflow(6)->subDay()->toDateString(),
                'confirmed_on' => $late ? null : $j->copy()->addMonthsNoOverflow(6)->subDays(3)->toDateString()]);
        }
        $E('Kiran Patil')->update(['employment_type' => 'fixed_term', 'contract_end' => '2026-12-31']);
        foreach (['Kiran Patil', 'Efua Asante'] as $n) {
            $e = $E($n);
            Models\TalentProbation::create(['employee_id' => $e->id, 'due_on' => $e->probation_end, 'reviewed_on' => $e->confirmed_on, 'outcome' => 'confirm', 'rating' => 4,
                'comments' => 'Reliable, settled in well.', 'reviewed_by' => $hr->id]);
        }

        // ---- former employees (left before the first seeded payroll month)
        $yaw = Models\Employee::create(['department_id' => $dept('F&B Service'), 'emp_code' => 'EMP016', 'name' => 'Yaw Mensah', 'designation' => 'Waiter', 'phone' => '0244000116',
            'joining_date' => '2024-02-01', 'leaving_date' => '2026-04-30', 'salary' => 2600, 'is_active' => false, 'sc_eligible' => false, 'ot_eligible' => true,
            'statement_issued_on' => '2024-02-15', 'probation_end' => '2024-07-31', 'confirmed_on' => '2024-07-29', 'manager_user_id' => $fb->id]);
        $kwesi = Models\Employee::create(['department_id' => $dept('Kitchen'), 'emp_code' => 'EMP017', 'name' => 'Kwesi Appiah', 'designation' => 'Commis Chef', 'phone' => '0244000117',
            'joining_date' => '2025-06-02', 'leaving_date' => '2026-06-12', 'salary' => 2700, 'is_active' => false, 'sc_eligible' => false, 'ot_eligible' => true,
            'statement_issued_on' => '2025-06-20', 'probation_end' => '2025-12-01', 'confirmed_on' => '2025-11-28', 'manager_user_id' => $fb->id]);
        $clr = fn (string $by, string $at) => collect($TS::CLEARANCE)->map(fn () => ['done' => true, 'by' => $by, 'at' => $at, 'note' => null])->all();
        Models\TalentExit::create(['number' => \App\Support\Numbering::next('talent_exit'), 'employee_id' => $yaw->id, 'type' => 'resignation', 'notice_on' => '2026-04-01', 'notice_days' => 14,
            'notice_basis' => 'Under three years\' service – two weeks (s. 17(1)(a))', 'last_day' => '2026-04-30', 'final_pay_due' => '2026-04-30', 'reason' => 'Joining a resort in Takoradi as head waiter.',
            'status' => 'completed', 'regretted' => true, 'rehire' => true, 'clearance' => $clr($hr->name, '2026-04-30 16:00:00'), 'created_by' => $hr->id, 'completed_by' => $hr->id,
            'completed_at' => '2026-04-30 17:00:00', 'interview' => ['primary' => 'career', 'satisfaction' => 4, 'recommend' => true, 'liked' => 'The team and the training.',
                'improve' => 'A clearer path to supervisor.', 'by' => $hr->name, 'on' => '2026-04-29']]);
        $dc = Models\TalentCase::create(['number' => \App\Support\Numbering::next('talent_case'), 'kind' => 'discipline', 'employee_id' => $kwesi->id, 'category' => 'theft', 'severity' => 'gross',
            'incident_on' => '2026-06-02', 'description' => 'Seen on CCTV leaving the kitchen store with two packs of chicken (5 kg) in a backpack at the end of the night shift; the packs were recovered at the staff gate.',
            'status' => 'closed', 'query_issued_on' => '2026-06-03', 'reply_due' => '2026-06-06', 'reply' => 'I was taking the chicken to the staff canteen fridge.', 'replied_on' => '2026-06-05',
            'hearing_at' => '2026-06-10 10:00:00', 'panel' => 'Anjali Menon (HR), Arjun Menon (F&B Manager)', 'representative' => 'Kwabena Tetteh (colleague)',
            'hearing_notes' => 'The canteen has no fridge on that route; the gate guard found the packs inside a personal bag. The explanation was not accepted.', 'hearing_held' => true,
            'finding' => 'proven', 'outcome' => 'dismissal', 'outcome_on' => '2026-06-12', 'raised_by' => $fb->id, 'decided_by' => $admin->id, 'closed_at' => '2026-06-15 12:00:00',
            'resolution' => 'Summary dismissal for gross misconduct (theft of hotel property).']);
        Models\TalentExit::create(['number' => \App\Support\Numbering::next('talent_exit'), 'employee_id' => $kwesi->id, 'type' => 'dismissal', 'notice_on' => '2026-06-12', 'notice_days' => 0,
            'notice_basis' => 'Summary dismissal for gross misconduct', 'last_day' => '2026-06-12', 'final_pay_due' => '2026-06-15', 'case_id' => $dc->id, 'fair_confirmed' => true,
            'status' => 'completed', 'regretted' => false, 'rehire' => false, 'clearance' => $clr($hr->name, '2026-06-15 11:00:00'), 'created_by' => $admin->id, 'completed_by' => $hr->id,
            'completed_at' => '2026-06-15 11:30:00', 'reason' => 'Theft of hotel property (DC case).']);

        // ---- Fitness Instructor: filled by Kofi Boateng (built directly – the offer pre-dates today)
        $kofi = $E('Kofi Boateng');
        $fit = Models\TalentVacancy::create(['number' => \App\Support\Numbering::next('talent_vacancy'), 'department_id' => $dept('Spa & Wellness'), 'title' => 'Fitness Instructor', 'headcount' => 1,
            'filled' => 1, 'employment_type' => 'permanent', 'salary_min' => 3200, 'salary_max' => 3800, 'reason' => 'new', 'justification' => 'Health-club membership has doubled; classes need a qualified instructor.',
            'description' => 'Run the gym floor, group classes and personal-training sessions for members and in-house guests.', 'requirements' => 'Recognised fitness certification; first-aid certificate; 2+ years in a gym.',
            'is_public' => true, 'status' => 'filled', 'requested_by' => $hr->id, 'approved_by' => $admin->id, 'approved_at' => $B->copy()->subDays(80)->setTime(11, 0), 'filled_on' => '2026-07-28']);
        $mk = function (array $a) use ($fit) {
            return Models\TalentApplicant::create($a + ['number' => \App\Support\Numbering::next('talent_applicant'), 'vacancy_id' => $fit->id, 'consent_at' => now()]);
        };
        $ak = $mk(['name' => 'Kofi Boateng', 'phone' => '0244100212', 'email' => 'kofi.boateng@example.com', 'source' => 'careers', 'experience_years' => 4, 'expected_salary' => 3600,
            'stage' => 'hired', 'screen_rating' => 5, 'hired_employee_id' => $kofi->id, 'stage_changed_at' => '2026-07-28 12:00:00']);
        $mk(['name' => 'Sena Agbeko', 'phone' => '0244100213', 'source' => 'job_board', 'experience_years' => 1, 'stage' => 'rejected', 'reject_reason' => 'Certification still in progress', 'stage_changed_at' => '2026-07-20 10:00:00']);
        $mk(['name' => 'Fiifi Quansah', 'phone' => '0244100214', 'source' => 'walk_in', 'experience_years' => 3, 'stage' => 'rejected', 'reject_reason' => 'Position filled', 'stage_changed_at' => '2026-07-28 12:00:00']);
        $iv = Models\TalentInterview::create(['applicant_id' => $ak->id, 'round' => 1, 'kind' => 'trade_test', 'scheduled_at' => '2026-07-22 10:00:00', 'location' => 'Health club', 'panel' => [$spa->id, $hr->id], 'status' => 'done', 'created_by' => $hr->id]);
        foreach ([[$spa, [5, 5, 5, 4, 5], 'hire'], [$hr, [4, 5, 4, 4, 4], 'hire']] as [$who, $sc, $rec]) {
            Models\TalentScorecard::create(['interview_id' => $iv->id, 'user_id' => $who->id, 'scores' => array_combine(array_keys($TS::CRITERIA), $sc), 'average' => round(array_sum($sc) / 5, 2), 'recommendation' => $rec]);
        }
        Models\TalentOffer::create(['number' => \App\Support\Numbering::next('talent_offer'), 'applicant_id' => $ak->id, 'vacancy_id' => $fit->id, 'salary' => 3600, 'start_date' => '2026-08-18', 'probation_months' => 6,
            'employment_type' => 'permanent', 'status' => 'accepted', 'respond_by' => '2026-08-01', 'responded_at' => '2026-07-28 12:00:00', 'created_by' => $hr->id]);
        $TS::createOnboarding($kofi->fresh());
        foreach (['contract', 'uniform', 'induction'] as $k) {
            Models\TalentTask::where('employee_id', $kofi->id)->where('item', $k)->update(['done_at' => '2026-08-19 10:00:00', 'done_by' => $hr->id]);
        }
        $abena = $E('Abena Owusu');
        $TS::createOnboarding($abena);
        Models\TalentTask::where('employee_id', $abena->id)->where('item', '!=', 'statement')->update(['done_at' => '2026-03-20 10:00:00', 'done_by' => $hr->id]);

        // ---- Guest Service Agent ×2: open, full pipeline (through the service)
        Auth::setUser($fom);
        $gsa = $TS::saveVacancy(['department_id' => $dept('Front Office'), 'title' => 'Guest Service Agent', 'headcount' => 2, 'employment_type' => 'permanent', 'salary_min' => 3200, 'salary_max' => 3800,
            'reason' => 'new', 'closing_on' => $d(14), 'is_public' => 1, 'justification' => 'Occupancy up 12 points year on year; the desk runs one agent short on the evening shift.',
            'description' => "Welcome guests, check them in and out, handle bills and payments and look after requests at the front desk on a rotating shift.",
            'requirements' => "SHS or HND (hospitality preferred); 1+ year in a guest-facing role; good English (French a plus); comfortable with a PMS and mobile money."], null, $fom);
        $TS::submitVacancy($gsa, $fom);
        Auth::setUser($admin);
        $TS::approveVacancy($gsa, $admin, 'Two posts approved within the front-office establishment.');
        $gsa->update(['approved_at' => $B->copy()->subDays(20)->setTime(9, 30)]);
        Auth::setUser($hr);
        $deepa = $E('Deepa Nair');
        $apps = [];
        foreach ([
            ['Ama Serwaa', '0244200301', 'ama.serwaa@example.com', 'careers', 3, 3500, 'Front desk at a 60-room hotel in Osu for three years.'],
            ['Kwame Owusu', '0244200302', 'kwame.owusu@example.com', 'referral', 5, 4200, 'Night auditor and receptionist; referred by Deepa Nair.'],
            ['Esi Mensah', '0244200303', 'esi.mensah@example.com', 'job_board', 2, 3300, null],
            ['Nana Adjei', '0244200304', null, 'careers', 1.5, 3200, null],
            ['Akua Boakye', '0244200305', 'akua.b@example.com', 'agency', 2, 3400, null],
            ['Yaw Darko', '0244200306', null, 'walk_in', 0.5, 3000, 'Dropped a CV at the front desk.'],
            ['Efo Kpodo', '0244200307', 'efo.kpodo@example.com', 'careers', 0, 3200, null],
            ['Adwoa Sarpong', '0244200308', 'adwoa.s@example.com', 'job_board', 0, 3500, null],
            ['Kojo Asamoah', '0244200309', 'kojo.asamoah@example.com', 'careers', 4, 3800, null],
        ] as $i => [$n, $ph, $em, $src, $exp, $sal, $note]) {
            $apps[$n] = $TS::addApplicant($gsa, ['name' => $n, 'phone' => $ph, 'email' => $em, 'source' => $src, 'referred_by_employee_id' => $src === 'referral' ? $deepa->id : null,
                'experience_years' => $exp, 'expected_salary' => $sal, 'cover_note' => $note, 'consent' => 1], null, $hr);
            $apps[$n]->forceFill(['created_at' => $B->copy()->subDays(18 - $i)->setTime(9 + $i % 6, 15), 'consent_at' => $B->copy()->subDays(18 - $i)->setTime(9 + $i % 6, 15), 'stage_changed_at' => $B->copy()->subDays(18 - $i)->setTime(9 + $i % 6, 15)])->saveQuietly();
        }
        foreach (['Ama Serwaa' => 5, 'Kwame Owusu' => 4, 'Esi Mensah' => 4, 'Nana Adjei' => 3, 'Efo Kpodo' => 2] as $n => $r) {
            $TS::moveApplicant($apps[$n], 'screening', $hr, null, $r);
        }
        $TS::moveApplicant($apps['Efo Kpodo'], 'rejected', $hr, 'Insufficient guest-facing experience for the role');
        $TS::moveApplicant($apps['Adwoa Sarpong'], 'rejected', $hr, 'No customer-service experience');
        $TS::moveApplicant($apps['Kojo Asamoah'], 'withdrawn', $hr, 'Accepted another offer');
        $crit = array_keys($TS::CRITERIA);
        foreach ([['Ama Serwaa', -6, [[$fom, [5, 5, 4, 4, 5], 'hire'], [$hr, [4, 5, 4, 4, 4], 'hire']]], ['Kwame Owusu', -5, [[$fom, [4, 4, 5, 5, 4], 'hire'], [$hr, [4, 3, 4, 5, 3], 'hold']]]] as [$n, $day, $cards]) {
            $i = $TS::scheduleInterview($apps[$n], ['kind' => 'in_person', 'scheduled_at' => $B->copy()->addDays($day)->setTime(10, 0)->toDateTimeString(), 'location' => 'Front office meeting room', 'panel' => [$fom->id, $hr->id]], $hr);
            foreach ($cards as [$who, $sc, $rec]) {
                $TS::score($i, ['scores' => array_combine($crit, $sc), 'recommendation' => $rec, 'comments' => $rec === 'hire' ? 'Warm, confident, knows the check-in routine.' : 'Good experience; a little flat with guests.'], $who);
            }
        }
        $TS::scheduleInterview($apps['Esi Mensah'], ['kind' => 'in_person', 'scheduled_at' => $B->copy()->addDays(2)->setTime(11, 0)->toDateTimeString(), 'location' => 'Front office meeting room', 'panel' => [$fom->id, $hr->id, $admin->id]], $hr);
        $TS::makeOffer($apps['Ama Serwaa'], ['salary' => 3500, 'start_date' => $d(10), 'probation_months' => 6, 'notes' => 'Subject to two satisfactory references.'], $hr);
        $TS::makeOffer($apps['Kwame Owusu'], ['salary' => 4000, 'start_date' => $d(14), 'probation_months' => 6, 'notes' => 'Above range – five years\' experience incl. night audit; covers the evening shift lead.'], $hr);

        // ---- Room Attendant (pending, replaces Lata), Night Auditor (cancelled), Commis Chef (draft)
        $lata = $E('Lata Bai');
        Auth::setUser($hk);
        $ra = $TS::saveVacancy(['department_id' => $dept('Housekeeping'), 'title' => 'Room Attendant', 'headcount' => 1, 'employment_type' => 'permanent', 'salary_min' => 2400, 'salary_max' => 2600,
            'reason' => 'replacement', 'replaces_employee_id' => $lata->id, 'is_public' => 1, 'justification' => 'Lata Bai resigned (last day next month); 16 rooms per attendant is already at the limit.',
            'description' => 'Clean and prepare guest rooms and public areas to the hotel standard.', 'requirements' => 'Previous hotel housekeeping experience preferred.'], null, $hk);
        $TS::submitVacancy($ra, $hk);
        Auth::setUser($hr);
        $na = $TS::saveVacancy(['department_id' => $dept('Accounts'), 'title' => 'Night Auditor', 'headcount' => 1, 'employment_type' => 'permanent', 'salary_min' => 3500, 'salary_max' => 4200,
            'reason' => 'new', 'is_public' => 0, 'justification' => 'Night audit currently done by the duty manager.'], null, $hr);
        $TS::submitVacancy($na, $hr);
        $TS::approveVacancy($na, $admin);
        $TS::setVacancyStatus($na, 'cancelled', $hr, 'Covered by training a front-office agent in night audit instead');
        Auth::setUser($fb);
        $TS::saveVacancy(['department_id' => $dept('Kitchen'), 'title' => 'Commis Chef', 'headcount' => 1, 'employment_type' => 'fixed_term', 'contract_months' => 12, 'salary_min' => 2700, 'salary_max' => 3000,
            'reason' => 'replacement', 'replaces_employee_id' => $kwesi->id, 'is_public' => 1, 'justification' => 'Replaces Kwesi Appiah (dismissed June).'], null, $fb);

        // ---- discipline & grievances
        Auth::setUser($hr);
        $mkCase = function (array $a) use ($TS) {
            return Models\TalentCase::create($a + ['number' => \App\Support\Numbering::next($a['kind'] === 'grievance' ? 'talent_grievance' : 'talent_case')]);
        };
        $imran = $E('Imran Ali');
        $mkCase(['kind' => 'discipline', 'employee_id' => $imran->id, 'category' => 'negligence', 'severity' => 'major', 'incident_on' => '2025-06-02',
            'description' => 'Left a leaking valve in room 204 unrepaired at the end of shift; the ceiling of 104 below was damaged.', 'status' => 'closed', 'query_issued_on' => '2025-06-03',
            'reply_due' => '2025-06-06', 'reply' => 'I was called to the kitchen emergency and forgot to hand over.', 'replied_on' => '2025-06-04', 'finding' => 'proven', 'outcome' => 'written',
            'outcome_on' => '2025-06-10', 'expires_on' => '2026-06-10', 'raised_by' => $eng->id, 'decided_by' => $hr->id, 'closed_at' => '2025-06-12 10:00:00']);
        $sunil = $E('Sunil Yadav');
        $mkCase(['kind' => 'discipline', 'employee_id' => $sunil->id, 'category' => 'lateness', 'severity' => 'minor', 'incident_on' => $d(-68),
            'description' => 'Late for the breakfast shift five times in two weeks (15–45 minutes) without calling in.', 'status' => 'closed', 'query_issued_on' => $d(-66), 'reply_due' => $d(-63),
            'reply' => 'Transport problems from Kasoa; I will take the earlier trotro.', 'replied_on' => $d(-65), 'finding' => 'proven', 'outcome' => 'verbal', 'outcome_on' => $d(-60),
            'expires_on' => $B->copy()->subDays(60)->addMonthsNoOverflow(6)->toDateString(), 'raised_by' => $fb->id, 'decided_by' => $hr->id, 'closed_at' => $B->copy()->subDays(59)->setTime(10, 0)]);
        $kiran = $E('Kiran Patil');
        $kc = $TS::raiseCase(['kind' => 'discipline', 'employee_id' => $kiran->id, 'category' => 'sleeping', 'severity' => 'major', 'incident_on' => $d(-5),
            'description' => 'Found asleep in the car-park guard hut at 03:10 by the night manager; the barrier was left raised and unattended for about 40 minutes.'], $sec);
        $TS::issueQuery($kc, ['reply_due' => $d(3)], $hr);
        $kc->update(['query_issued_on' => $d(-4), 'reply_due' => $d(-1)]);
        $TS::raiseCase(['kind' => 'grievance', 'employee_id' => $E('Efua Asante')->id, 'category' => 'hours', 'incident_on' => $d(-9), 'confidential' => 1,
            'description' => 'Rostered for split shifts six days running with less than 10 hours between the late and early sessions; asks for a fair rotation.'], $hr);
        Models\TalentCase::where('kind', 'grievance')->latest('id')->first()->update(['status' => 'investigating']);

        // ---- Lata resigns: working one month's notice, clearance under way
        $x = $TS::startExit($lata, ['type' => 'resignation', 'notice_on' => $d(-5), 'reason' => 'Relocating to Kumasi with her family.'], $hr);
        $TS::updateClearance($x, 'handover', true, 'Floor 2 section handed to Ramesh', $hr);
        $TS::saveInterview($x, ['primary' => 'relocation', 'satisfaction' => 4, 'recommend' => 1, 'liked' => 'Supportive supervisor.', 'improve' => 'Transport for early shifts.', 'rehire' => 1], $hr);

        // ---- 2026 mid-year appraisals at every stage
        $cyc = $TS::createCycle(['name' => '2026 Mid-year review', 'period_from' => '2026-01-01', 'period_to' => '2026-06-30', 'self_due' => $d(-20), 'review_due' => $d(10),
            'goals' => "Guest satisfaction in my area\nAttendance & punctuality\nDepartmental standards (SOP / QA audits)"], $hr);
        $plan = [ // name => [self ratings (or null), manager goal ratings, manager competency ratings, target stage]
            'Joseph Thomas' => [[4, 5, 4], [5, 5, 4], [4, 5, 5, 5, 4, 5], 'signed'], 'Deepa Nair' => [null, [4, 4, 4], [5, 4, 4, 4, 5, 3], 'signed'],
            'Mohan Lal' => [null, [5, 4, 5], [5, 4, 5, 5, 5, 4], 'signed'], 'Grace Mensah' => [[4, 4, 4], [4, 4, 5], [5, 4, 4, 4, 5, 4], 'signed'],
            'Meera Iyer' => [[4, 4, 3], [3, 4, 4], [3, 4, 4, 4, 3, 3], 'signed'], 'Efua Asante' => [null, [3, 3, 3], [4, 3, 3, 3, 4, 3], 'signed'],
            'Ravi Kumar' => [[4, 4, 4], [4, 3, 3], [4, 3, 4, 3, 4, 3], 'hr'], 'Sunil Yadav' => [null, [2, 1, 2], [3, 2, 2, 1, 3, 2], 'hr'], 'Imran Ali' => [null, [3, 2, 3], [3, 3, 3, 2, 3, 2], 'hr'],
            'Anjali Menon' => [null, null, null, 'self'], 'Ramesh Gowda' => [null, null, null, 'self'],
        ];
        $compKeys = array_keys($TS::COMPETENCIES);
        foreach ($plan as $name => [$self, $mg, $mc, $stage]) {
            $a = Models\TalentAppraisal::where('cycle_id', $cyc->id)->where('employee_id', $E($name)->id)->first();
            if ($self) {
                $TS::saveSelf($a->fresh(), ['goal_self' => $self, 'comp_self' => array_combine($compKeys, array_fill(0, 6, 4)), 'self_comments' => 'I met most of my targets this half.'], $a->employee->user, true);
            }
            if ($mg) {
                $a = $a->fresh();
                $rev = $a->reviewer;
                $TS::saveReview($a, ['goal_title' => array_column($a->goals, 'title'), 'goal_weight' => array_column($a->goals, 'weight'), 'goal_mgr' => $mg, 'comp_mgr' => array_combine($compKeys, $mc),
                    'mgr_comments' => $mg[0] >= 4 ? 'Strong half-year – a role model for the team.' : ($mg[0] <= 2 ? 'Punctuality and standards are below what the role needs; a performance improvement plan is agreed.' : 'Solid, dependable contribution.'),
                    'development' => $mg[0] <= 2 ? 'Weekly check-ins with the F&B Manager; SOP refresher on table service.' : 'Supervisory skills course in Q4.'], $rev, true);
                if ($stage === 'signed') {
                    $TS::signOff($a->fresh(), $hr, true);
                }
            }
        }
        foreach (Models\TalentAppraisal::where('cycle_id', $cyc->id)->where('status', 'signed')->get() as $i => $a) {
            $a->update(['self_at' => $a->self_at ? $B->copy()->subDays(24)->setTime(10, 0) : null, 'reviewed_at' => $B->copy()->subDays(12 - $i)->setTime(15, 0), 'signed_at' => $B->copy()->subDays(6 - min(5, $i))->setTime(11, 0),
                'acknowledged_at' => $i < 3 ? $B->copy()->subDays(3)->setTime(12, 0) : null]);
        }
        $TS::flush();
        Auth::forgetUser();
    }

    /**
     * v7.1 IPTV & guest welcome: a TV in every room of both hotels plus lobby / restaurant / conference screens (two offline, one
     * disabled, one showing a pairing code), a ~30-channel multicast line-up with a 36-hour programme guide, 12 movies with today's
     * rentals (one refunded), starter welcome screens + a Twi-greeting group screen, 7 information pages, TV messages (a cleared fire
     * drill, live broadcasts, a room message awaiting acknowledgement, a withdrawn notice), orders / requests / a wake-up placed on
     * the TV and 14 days of sessions and usage events for the analytics.
     */
    /**
     * v7.3 guest messaging demo: quick replies and keyword routing rules for every hotel; for the rich hotel a live inbox drawn from
     * the real in-house stays – a VIP airport-transfer chat on WhatsApp, an AC fault by SMS past the response target (urgent,
     * engineering, internal note), an unknown number asking for rates (unassigned, overdue), rule-answered towels / Wi-Fi chats,
     * a late check-out waiting for the guest, a snoozed dinner booking, an e-mailed invoice request, closed & rated chats with
     * departed guests over the last week and yesterday's check-out broadcast to today's departures. Messages are inserted
     * directly (no SMS / e-mail is sent, no guest requests raised); rooms 106 and 201 are left untouched.
     */
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

    /**
     * v7.0 sustainability & ESG – runs after seedTalent (needs its employees). Everything is dated relative to the business date and written
     * straight to the tables (no GL impact).
     */
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

    /** v6.4 demo: the Grand Hotel's reservations enquiry log for the last three weeks – every outcome, reason and state. */
    protected function seedCro(bool $rich): void
    {
        \App\Services\CroService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn (string $n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $admin = $u('admin');
        $yaw = $u('cro') ?? $admin;
        $priya = $u('frontdesk') ?? $admin;
        $ravi = $u('fom') ?? $admin;
        $B = bdate()->copy()->startOfDay();
        $types = Models\RoomType::get()->keyBy('code');
        $comp = Models\BdCompetitor::where('is_active', true)->orderBy('id')->pluck('id')->values();
        $specs = [];
        // booked: existing direct / corporate / travel-trade bookings that came in by phone, e-mail or WhatsApp
        $src = Models\BusinessSource::whereIn('type', ['direct', 'corporate', 'travel_agent'])->pluck('id');
        $res = Models\Reservation::with('guest', 'rooms')->where('arrival_date', '>=', $B->toDateString())->where('status', 'confirmed')->whereIn('business_source_id', $src)
            ->orderBy('arrival_date')->orderBy('id')->limit(8)->get();
        if ($res->count() < 8) {
            // the base demo gives its upcoming bookings a random source – top the log up to its eight phone / e-mail bookings with plain
            // future bookings that no channel, booking engine, API or campaign produced, and record them as the reservations desk's own
            $direct = Models\BusinessSource::where('type', 'direct')->orderBy('id')->value('id');
            $plain = Models\BusinessSource::whereIn('type', ['ota', 'walk_in'])->pluck('id');
            $more = Models\Reservation::with('guest', 'rooms')->where('arrival_date', '>', $B->toDateString())->where('status', 'confirmed')->whereIn('business_source_id', $plain)
                ->whereNull('ota_channel_id')->whereNull('ota_uid')->whereNull('ota_reference')->whereNull('channel_connection_id')->whereNull('api_client_id')->whereNull('external_ref')
                ->whereNull('room_block_id')->whereNull('promo_code_id')->whereNull('campaign_id')->whereNull('marketing_message_id')->whereNull('journey_id')->whereNull('dm_link_id')
                ->whereNotIn('id', Models\OnlinePayment::whereNotNull('reservation_id')->pluck('reservation_id'))
                ->orderBy('arrival_date')->orderBy('id')->limit(8 - $res->count())->get();
            if ($direct) {
                $more->each(fn ($r) => $r->forceFill(['business_source_id' => $direct])->saveQuietly());
            }
            $res = $res->concat($more)->sortBy(fn ($r) => $r->arrival_date->format('Ymd').str_pad((string) $r->id, 8, '0', STR_PAD_LEFT))->values();
        }
        $agents = [$yaw, $yaw, $priya, $yaw, $ravi, $yaw, $priya, $yaw];
        $chan = ['phone', 'email', 'whatsapp', 'phone', 'travel_agent', 'phone', 'walk_in', 'email'];
        foreach ($res as $i => $r) {
            $nights = max(1, (int) $r->arrival_date->diffInDays($r->departure_date));
            $value = round((float) $r->rooms->sum(fn ($x) => (float) $x->rate * $nights), 2);
            $specs[] = ['ago' => [2, 4, 5, 7, 9, 12, 15, 20][$i], 'status' => 'booked', 'caller_name' => $r->guest?->full_name ?? 'Guest', 'phone' => $r->guest?->phone, 'email' => $r->guest?->email,
                'guest_id' => $r->guest_id, 'arrival_date' => $r->arrival_date->toDateString(), 'departure_date' => $r->departure_date->toDateString(), 'rooms' => max(1, $r->rooms->count()),
                'adults' => max(1, (int) $r->adults), 'children' => (int) $r->children, 'room_type_id' => $r->rooms->first()?->room_type_id,
                'quoted_rate' => round($value / max(1, $nights * max(1, $r->rooms->count())), 2), 'quoted_total' => $value, 'booked_value' => $value, 'reservation_id' => $r->id,
                'channel' => $chan[$i], 'taken_by' => $agents[$i]->id, 'closed_by' => $agents[$i]->id, 'duration_sec' => [240, null, null, 310, 420, 185, 600, null][$i]];
        }
        // lost: [days ago, arrival offset, nights, rooms, adults, type, channel, agent, reason, competitor index, note, call seconds]
        $lost = [
            [1, 3, 2, 2, 4, 'DLX', 'phone', $yaw, 'sold_out', null, 'Wedding party – whole hotel full that weekend', 200],
            [2, 3, 1, 1, 2, 'STD', 'whatsapp', $priya, 'sold_out', null, null, null],
            [3, 4, 2, 3, 6, 'STD', 'email', $yaw, 'sold_out', null, 'Asked for 3 Standard rooms – none left', null],
            [6, 10, 3, 1, 2, 'PRE', 'phone', $ravi, 'room_type', null, 'Presidential Suite taken; would not take the Executive Suite', 330],
            [8, 12, 1, 1, 2, 'EXE', 'travel_agent', $yaw, 'restriction', null, 'One night only – minimum stay applies', 150],
            [11, 20, 3, 12, 20, 'STD', 'email', $ravi, 'group_size', null, 'Conference delegates – 12 rooms, passed to group sales', null],
            [1, 7, 2, 1, 2, 'DLX', 'phone', $yaw, 'price', null, 'Budget GH₵ 3,500 a night', 260],
            [2, 9, 3, 1, 1, 'STD', 'web_chat', $priya, 'price', null, null, null],
            [5, 14, 2, 2, 4, 'EXE', 'phone', $yaw, 'price', null, 'Offered 10 % off – still above budget', 480],
            [4, 6, 1, 1, 2, 'DLX', 'phone', $yaw, 'competitor', 0, 'Chose the competitor for loyalty points', 210],
            [9, 16, 4, 1, 2, 'EXE', 'email', $ravi, 'competitor', 1, 'Competitor included airport transfers', null],
            [13, 18, 2, 1, 2, 'STD', 'social', $priya, 'location', null, 'Wanted to be walking distance from the airport', null],
            [20, -3, 2, 1, 2, 'DLX', 'phone', $yaw, 'no_response', null, 'Lapsed – arrival date passed without a booking', 170],
        ];
        $names = ['Nana Ama Serwaa', 'Kwesi Appiah', 'Abena Owusu-Ansah', 'Dr. Emmanuel Tetteh', 'Adjoa Mensah (Ashanti Tours)', 'Ghana Bar Association', 'Samuel Ofori', 'Linda Addo',
            'Richard Asante', 'Joyce Boakye', 'Michael Darko', 'Grace Ntim', 'Paul Quaye'];
        foreach ($lost as $i => [$ago, $arr, $n, $rooms, $adults, $code, $ch, $agent, $reason, $ci, $note, $sec]) {
            $a = $B->copy()->addDays($arr);
            $q = \App\Services\RateService::quote($types[$code], $a->toDateString(), $a->copy()->addDays($n)->toDateString());
            $specs[] = ['ago' => $ago, 'status' => 'lost', 'caller_name' => $names[$i], 'phone' => '+233 24 '.str_pad((string) (410000 + $i * 7919 % 99999), 6, '0', STR_PAD_LEFT), 'email' => null,
                'arrival_date' => $a->toDateString(), 'departure_date' => $a->copy()->addDays($n)->toDateString(), 'rooms' => $rooms, 'adults' => $adults, 'children' => 0,
                'room_type_id' => $types[$code]->id, 'quoted_rate' => $q['average'], 'quoted_total' => round($q['total'] * $rooms, 2), 'channel' => $ch, 'taken_by' => $agent->id,
                'loss_type' => array_key_exists($reason, Models\CroEnquiry::DENIALS) ? 'denial' : 'regret', 'loss_reason' => $reason, 'competitor_id' => $ci !== null ? ($comp[$ci] ?? null) : null,
                'loss_note' => $note, 'closed_by' => $reason === 'no_response' ? null : $agent->id, 'duration_sec' => $sec];
        }
        // open: two follow-ups overdue, two still ahead
        foreach ([[2, 5, 2, 1, 2, 'DLX', 'phone', $yaw, -3, 'Esi Frimpong', 'Checking dates with her husband'], [1, 2, 1, 2, 3, 'STD', 'whatsapp', $priya, -1, 'Yaw Owusu', null],
            [0, 21, 3, 1, 2, 'EXE', 'email', $yaw, 20, 'Ingrid Larsen', 'Norwegian embassy visitor – wants a quiet room'], [0, 30, 2, 4, 8, 'STD', 'travel_agent', $ravi, 48, 'Sunshine Travel & Tours', 'Family reunion – 4 rooms']] as [$ago, $arr, $n, $rooms, $adults, $code, $ch, $agent, $fu, $name, $note]) {
            $a = $B->copy()->addDays($arr);
            $q = \App\Services\RateService::quote($types[$code], $a->toDateString(), $a->copy()->addDays($n)->toDateString());
            $specs[] = ['ago' => $ago, 'status' => 'open', 'caller_name' => $name, 'phone' => '+233 20 55'.str_pad((string) ($arr * 131), 5, '0', STR_PAD_LEFT), 'email' => null,
                'arrival_date' => $a->toDateString(), 'departure_date' => $a->copy()->addDays($n)->toDateString(), 'rooms' => $rooms, 'adults' => $adults, 'children' => 0,
                'room_type_id' => $types[$code]->id, 'quoted_rate' => $q['average'], 'quoted_total' => round($q['total'] * $rooms, 2), 'channel' => $ch, 'taken_by' => $agent->id,
                'follow_up_at' => now()->addHours($fu), 'notes' => $note, 'duration_sec' => null, 'closed_by' => null];
        }
        usort($specs, fn ($x, $y) => $y['ago'] <=> $x['ago']);
        foreach ($specs as $k => $sp) {
            $at = now()->subDays($sp['ago'])->setTime(9 + $k % 8, (13 * $k) % 60);
            if ($at->gt(now())) {
                $at = now()->subMinutes(30 + $k);
            }
            $ago = $sp['ago'];
            unset($sp['ago']);
            $e = Models\CroEnquiry::create($sp + ['number' => \App\Support\Numbering::next('cro_enquiry'), 'created_at' => $at, 'updated_at' => $at,
                'closed_at' => $sp['status'] === 'open' ? null : (($sp['loss_reason'] ?? null) === 'no_response' ? $B->copy()->subDays(2)->setTime(6, 20) : $at->copy()->addMinutes(5 + $ago))]);
            if ($e->caller_name === 'Esi Frimpong') {
                $e->contacts()->create(['type' => 'call_out', 'note' => 'Called back – no answer, left a voicemail with the Deluxe rate', 'user_id' => $yaw->id, 'created_at' => $at->copy()->addDay(), 'updated_at' => $at->copy()->addDay()]);
            } elseif ($e->caller_name === 'Yaw Owusu') {
                $e->contacts()->create(['type' => 'whatsapp', 'note' => 'Sent rates and photos for two Standard rooms', 'user_id' => $priya->id]);
            } elseif ($e->caller_name === 'Richard Asante') {
                $e->contacts()->create(['type' => 'call_out', 'note' => 'Offered 10 % – still too high, will stay with family', 'user_id' => $yaw->id, 'created_at' => $at->copy()->addHours(3), 'updated_at' => $at->copy()->addHours(3)]);
            }
        }
    }

    /** v6.4 demo: the Grand Hotel referred three turnaways to the Lake Resort (linked to the resort's bookings), plus one resort regret. */
    protected function seedCroGroup(Models\Hotel $main, Models\Hotel $resort): void
    {
        $C = \App\Services\CroService::class;
        $yaw = Models\User::where('hotel_id', $main->id)->where('username', 'cro')->first() ?? Models\User::where('hotel_id', $main->id)->where('username', 'admin')->first();
        \App\Support\Tenant::set($resort);
        $B = bdate()->copy()->startOfDay();
        $targets = Models\Reservation::with('guest', 'rooms')->where('arrival_date', '>=', $B->toDateString())->whereIn('status', ['confirmed', 'tentative'])->orderBy('arrival_date')->orderByDesc('id')->limit(3)->get();
        foreach ($targets->values() as $i => $r) {
            $nights = max(1, (int) $r->arrival_date->diffInDays($r->departure_date));
            $value = round((float) $r->rooms->sum(fn ($x) => (float) $x->rate * $nights), 2);
            $at = now()->subDays([6, 5, 3][$i])->setTime(11, 10 * $i);
            $base = ['caller_name' => $r->guest?->full_name ?? 'Guest', 'phone' => $r->guest?->phone, 'email' => $r->guest?->email, 'arrival_date' => $r->arrival_date->toDateString(),
                'departure_date' => $r->departure_date->toDateString(), 'rooms' => max(1, $r->rooms->count()), 'adults' => max(1, (int) $r->adults), 'children' => (int) $r->children,
                'quoted_rate' => round($value / max(1, $nights * max(1, $r->rooms->count())), 2), 'quoted_total' => $value, 'booked_value' => $value, 'taken_by' => $yaw->id, 'closed_by' => $yaw->id,
                'created_at' => $at, 'updated_at' => $at, 'closed_at' => $at->copy()->addMinutes(6)];
            $origin = $C::withHotel($main, fn () => Models\CroEnquiry::create($base + ['number' => \App\Support\Numbering::next('cro_enquiry'), 'channel' => 'phone', 'status' => 'referred',
                'loss_type' => 'denial', 'loss_reason' => ['sold_out', 'sold_out', 'room_type'][$i], 'referred_hotel_id' => $resort->id, 'referred_reservation_id' => $r->id,
                'referred_reservation_no' => $r->reservation_no, 'duration_sec' => [380, 290, 450][$i]]));
            Models\CroEnquiry::create($base + ['number' => \App\Support\Numbering::next('cro_enquiry'), 'channel' => 'referral', 'status' => 'booked', 'guest_id' => $r->guest_id,
                'room_type_id' => $r->rooms->first()?->room_type_id, 'reservation_id' => $r->id, 'referred_from_hotel_id' => $main->id, 'origin_enquiry_id' => $origin->id,
                'notes' => "Referred by {$main->name} (enquiry {$origin->number})"]);
        }
        $std = Models\RoomType::where('code', 'STD')->first();
        if ($std) {
            $a = $B->copy()->addDays(8);
            $q = \App\Services\RateService::quote($std, $a->toDateString(), $a->copy()->addDays(2)->toDateString());
            Models\CroEnquiry::create(['number' => \App\Support\Numbering::next('cro_enquiry'), 'channel' => 'phone', 'caller_name' => 'Kojo Antwi', 'arrival_date' => $a->toDateString(),
                'departure_date' => $a->copy()->addDays(2)->toDateString(), 'rooms' => 1, 'adults' => 2, 'room_type_id' => $std->id, 'quoted_rate' => $q['average'], 'quoted_total' => $q['total'],
                'status' => 'lost', 'loss_type' => 'regret', 'loss_reason' => 'price', 'taken_by' => $yaw->id, 'closed_by' => $yaw->id, 'closed_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        }
        \App\Support\Tenant::set($main);
    }

    /** v2.5 demo: ten events across the whole pipeline, driven through the service with back-dated business days. */
    protected function seedBanquet(Models\Hotel $hotel, Models\User $user, Carbon $today): void
    {
        $S = \App\Services\BanquetSalesService::class;
        $sales = Models\User::where('username', 'banquet')->first() ?? $user;
        Auth::setUser($sales);
        $H = Models\BanquetHall::pluck('id', 'name');
        $P = Models\BanquetPackage::pluck('id', 'name');
        $V = Models\BanquetService::pluck('id', 'name');
        $co = Models\Company::orderBy('id')->pluck('id')->values();
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id');
        $day = fn (int $o) => $today->copy()->addDays($o)->toDateString();
        $at = function (int $o, callable $fn) use ($hotel, $today) { // run with the business date set $o days from today
            $hotel->business_date = $today->copy()->addDays($o);
            try {
                return $fn();
            } finally {
                $hotel->business_date = $today->copy();
            }
        };
        $fn = fn (string $name, string $hall, int $o, string $from, string $to, string $style, int $pax, ?string $pkg, array $extras = [], ?int $guar = null, ?string $notes = null) => [
            'name' => $name, 'banquet_hall_id' => $H[$hall], 'date' => $day($o), 'start_time' => $from, 'end_time' => $to, 'setup_style' => $style, 'expected_pax' => $pax,
            'guaranteed_pax' => $guar, 'banquet_package_id' => $pkg ? $P[$pkg] : null, 'notes' => $notes,
            'items' => collect($extras)->map(fn ($q, $k) => isset($V[$k]) ? ['banquet_service_id' => $V[$k], 'qty' => $q] : ['description' => $k, 'qty' => $q[0], 'unit_price' => $q[1], 'tax_rate' => TaxService::rateFor('fnb', 0)])->values()->all()];
        $mk = fn (array $h, array $fns, int $createdAgo) => $at(-$createdAgo, function () use ($S, $h, $fns, $createdAgo, $today) {
            $e = $S::save($h + ['functions' => $fns, 'sales_user_id' => Auth::id()]);
            $e->forceFill(['created_at' => $today->copy()->subDays($createdAgo)->setTime(11, 15)])->save();

            return $e;
        });
        $stamp = fn ($e, array $cols) => $e->forceFill($cols)->save();

        // 1. Wedding linked to room block BLK-00001 – definite, deposit in
        $wedBlock = Models\RoomBlock::where('name', 'Mensah–Owusu Wedding')->first();
        $w = $mk(['title' => 'Mensah–Owusu Wedding', 'event_type' => 'wedding', 'contact_name' => 'Akosua Mensah', 'contact_phone' => '+233 24 555 0142', 'contact_email' => 'akosua.mensah@example.com',
            'source' => 'referral', 'room_block_id' => $wedBlock?->id, 'deposit_due' => 400000, 'deposit_due_date' => $day(-10), 'status' => 'tentative', 'option_date' => $day(-12),
            'requirements' => "Kente-themed décor in gold & green.\nNo pork anywhere on the menus.\nBridal room from 14:00 on the reception day.",
            'internal_notes' => 'Father of the bride is a repeat corporate client – keep the rate.'], [
            $fn('Traditional engagement', 'Poolside Lawn', 10, '10:00', '15:00', 'banquet', 350, 'Royal Non-Veg Buffet', ['PA system' => 1, 'Cordless microphone' => 2, 'Additional service staff' => 6], null, "Shaded seating for elders at the front.\nDrummers arrive 09:30 – power point by the stage."),
            $fn('Wedding reception', 'Grand Ballroom', 11, '19:00', '23:30', 'banquet', 280, 'Premium Wedding Buffet', ['Stage & backdrop' => 1, 'Floral centrepiece' => 28, 'DJ & dance floor' => 1, 'Photo booth' => 1, 'LED video wall' => 1], null, "Couple's entrance at 19:45 – lights down.\nCake cutting 21:00 (cake supplied by the family)."),
            $fn('Farewell brunch', 'Emerald Hall', 12, '10:00', '13:00', 'banquet', 90, 'Classic Veg Buffet'),
        ], 20);
        $at(-15, fn () => $S::takeDeposit($w, 250000, $bank, 'GIP-WED-1'));
        $at(-11, fn () => $S::takeDeposit($w->fresh(), 150000, $card, 'CARD-8841'));
        $at(-11, fn () => $S::confirm($w->fresh()));
        $stamp($w->fresh(), ['confirmed_at' => $today->copy()->subDays(11)->setTime(16, 0)]);

        // 2. Fintech summit linked to BLK-00002 – tentative, option in ~6 days
        $fin = Models\RoomBlock::where('name', 'like', 'Accra Fintech Summit%')->first();
        $mk(['title' => 'Accra Fintech Summit 2026', 'event_type' => 'conference', 'company_id' => $co[0] ?? null, 'contact_name' => 'Abena Asare', 'contact_phone' => '+233 20 811 4420',
            'contact_email' => 'events@fintechsummit.example', 'source' => 'email', 'room_block_id' => $fin?->id, 'deposit_due' => 250000, 'deposit_due_date' => $day(6),
            'status' => 'tentative', 'option_date' => $day(6), 'requirements' => 'Registration desk in the foyer from 07:30 both days. Live stream from the ballroom.'], [
            $fn('Day 1 plenary', 'Grand Ballroom', 21, '09:00', '17:30', 'theatre', 380, 'Working Lunch', ['Projector & screen' => 2, 'PA system' => 1, 'LED video wall' => 1, 'Cordless microphone' => 4]),
            $fn('Day 1 breakout', 'Emerald Hall', 21, '14:00', '17:00', 'classroom', 80, 'Coffee Break / Hi-Tea', ['Projector & screen' => 1, 'Flip chart & markers' => 2]),
            $fn('Day 2 plenary', 'Grand Ballroom', 22, '09:00', '16:00', 'theatre', 300, 'Working Lunch', ['Projector & screen' => 2, 'PA system' => 1, 'Cordless microphone' => 4]),
            $fn('Gala dinner', 'Poolside Lawn', 22, '19:30', '23:00', 'banquet', 350, 'Royal Non-Veg Buffet', ['Mocktail bar (per guest)' => [350, 400], 'DJ & dance floor' => 1]),
        ], 9);

        // 3. Corporate leadership programme – definite, guarantee locked, 50% deposit
        $lead = $mk(['title' => 'TCS West Africa Leadership Programme', 'event_type' => 'seminar', 'company_id' => $co[1] ?? null, 'contact_name' => 'Neha Joshi', 'contact_phone' => '+91 98450 22110',
            'contact_email' => 'neha.joshi@example.com', 'source' => 'repeat', 'deposit_due' => 143600, 'deposit_due_date' => $day(-5), 'status' => 'enquiry',
            'requirements' => 'Vegetarian options on every menu. Name tents for 80. Printed agenda at each seat.'], [
            $fn('Day 1', 'Emerald Hall', 3, '09:00', '17:30', 'classroom', 80, 'Plated Corporate Lunch', ['Projector & screen' => 1, 'Video-conference kit' => 1, 'Cordless microphone' => 2, 'Flip chart & markers' => 2], 80),
            $fn('Day 2', 'Emerald Hall', 4, '09:00', '17:30', 'classroom', 80, 'Plated Corporate Lunch', ['Projector & screen' => 1, 'Video-conference kit' => 1, 'Cordless microphone' => 2, 'Flip chart & markers' => 2], 80),
        ], 18);
        $at(-8, fn () => $S::takeDeposit($lead, 143600, $bank, 'RTGS-TCS-5521'));
        $at(-8, fn () => $S::confirm($lead->fresh()));
        $stamp($lead->fresh(), ['confirmed_at' => $today->copy()->subDays(8)->setTime(12, 30)]);

        // 4. Rotary charter night – completed & billed (180 guaranteed, 192 attended), folio settled
        $rot = $mk(['title' => 'Rotary Club Charter Night', 'event_type' => 'gala', 'contact_name' => 'Kofi Asamoah', 'contact_phone' => '+233 27 301 7788', 'source' => 'repeat',
            'deposit_due' => 100000, 'status' => 'tentative', 'option_date' => $day(-33)], [
            $fn('Charter dinner', 'Grand Ballroom', -6, '19:00', '23:00', 'banquet', 200, 'Royal Non-Veg Buffet', ['PA system' => 1, 'Floral centrepiece' => 18]),
        ], 40);
        $at(-30, fn () => $S::takeDeposit($rot, 100000, $bank, 'CHQ-004512'));
        $at(-30, fn () => $S::confirm($rot->fresh()));
        $at(-9, fn () => $S::save(['functions' => [array_merge($fn('Charter dinner', 'Grand Ballroom', -6, '19:00', '23:00', 'banquet', 200, 'Royal Non-Veg Buffet', ['PA system' => 1, 'Floral centrepiece' => 18], 180), ['id' => $rot->functions->first()->id])]]
            + $rot->only(['title', 'event_type', 'contact_name', 'contact_phone', 'source', 'deposit_due', 'sales_user_id']), $rot->fresh()));
        $folio = $at(-6, function () use ($S, $rot) {
            $e = $rot->fresh('functions.hall');
            $S::recordActuals($e, [$e->functions->first()->id => 192]);

            return $S::bill($e->fresh());
        });
        $at(-5, function () use ($folio, $bank) {
            FolioService::payment($folio->fresh(), round($folio->fresh()->balance(), 2), $bank, 'GIP-ROT-2', 'settlement');
            FolioService::close($folio->fresh());
        });
        $stamp($rot->fresh(), ['confirmed_at' => $today->copy()->subDays(30)->setTime(15, 0), 'billed_at' => $today->copy()->subDays(6)->setTime(23, 40), 'closed_at' => $today->copy()->subDays(6)->setTime(23, 40)]);
        $folio->fresh()->forceFill(['closed_at' => $today->copy()->subDays(5)->setTime(11, 0)])->save();

        // 5. Board meeting today – definite, credit client, ready to bill
        $bm = $mk(['title' => 'Infosys Quarterly Board Review', 'event_type' => 'meeting', 'company_id' => $co[0] ?? null, 'contact_name' => 'Rahul Menon', 'contact_phone' => '+91 99001 44321', 'source' => 'phone'], [
            $fn('Board meeting', 'Boardroom', 0, '10:00', '14:00', 'boardroom', 12, 'Working Lunch', ['Video-conference kit' => 1], 12),
        ], 7);
        $at(-7, fn () => $S::confirm($bm));

        // 6. Birthday – tentative, option in 2 days
        $mk(['title' => "Ama Owusu's 40th Birthday", 'event_type' => 'birthday', 'contact_name' => 'Ama Owusu', 'contact_phone' => '+233 24 118 9034', 'source' => 'walk_in',
            'deposit_due' => 50000, 'status' => 'tentative', 'option_date' => $day(2)], [
            $fn('Birthday dinner', 'Emerald Hall', 15, '19:00', '23:00', 'banquet', 110, 'Royal Non-Veg Buffet', ['DJ & dance floor' => 1, 'Floral centrepiece' => 11]),
        ], 5);

        // 7. Product launch – tentative, option expired two days ago (released at the next night audit)
        $mk(['title' => 'NovaPay App Launch', 'event_type' => 'launch', 'contact_name' => 'Yaw Darko', 'contact_phone' => '+233 55 902 3311', 'contact_email' => 'yaw@novapay.example',
            'source' => 'website', 'status' => 'tentative', 'option_date' => $day(-2)], [
            $fn('Launch cocktail', 'Grand Ballroom', 9, '18:00', '21:30', 'cocktail', 250, 'Cocktail Snacks', ['LED video wall' => 1, 'PA system' => 1, 'Stage & backdrop' => 1]),
        ], 12);

        // 8. Engagement – enquiry for mid-November
        $mk(['title' => 'Boateng–Addo Engagement', 'event_type' => 'engagement', 'contact_name' => 'Efua Addo', 'contact_phone' => '+233 26 440 1276', 'source' => 'email', 'status' => 'enquiry',
            'requirements' => 'Asked for a quote for 120 and 150 guests.'], [
            $fn('Engagement dinner', 'Emerald Hall', 50, '18:30', '22:30', 'banquet', 120, 'Premium Wedding Buffet', ['Floral centrepiece' => 12, 'Photo booth' => 1]),
        ], 2);

        // 9. School annual day – lost on price
        $lost = $mk(['title' => 'Ridge School Annual Day', 'event_type' => 'other', 'contact_name' => 'Mrs. Adjoa Quaye', 'contact_phone' => '+233 30 277 5140', 'source' => 'phone', 'status' => 'enquiry'], [
            $fn('Annual day & prize giving', 'Grand Ballroom', 30, '15:00', '20:00', 'theatre', 420, 'Coffee Break / Hi-Tea', ['Stage & backdrop' => 1, 'PA system' => 1]),
        ], 20);
        $at(-12, fn () => $S::markLost($lost, 'Price – went to a competitor'));
        $stamp($lost->fresh(), ['closed_at' => $today->copy()->subDays(12)->setTime(10, 0)]);

        // 10. Sales offsite – definite, cancelled 30 days out: 25% fee, part of the deposit left to refund
        $off = $mk(['title' => 'Thomas Cook Sales Offsite', 'event_type' => 'meeting', 'company_id' => $co[2] ?? null, 'contact_name' => 'Sanjay Pillai', 'contact_phone' => '+91 98200 55412',
            'source' => 'repeat', 'deposit_due' => 30000, 'status' => 'enquiry'], [
            $fn('Offsite day', 'Emerald Hall', 25, '09:00', '17:30', 'classroom', 60, 'Plated Corporate Lunch', ['Projector & screen' => 1]),
        ], 25);
        $at(-24, fn () => $S::takeDeposit($off, 30000, $bank, 'GIP-TC-7731'));
        $at(-24, fn () => $S::confirm($off->fresh()));
        $at(-5, fn () => $S::cancel($off->fresh(), 'Client moved the offsite to Q1 next year'));
        $stamp($off->fresh(), ['confirmed_at' => $today->copy()->subDays(24)->setTime(14, 0), 'closed_at' => $today->copy()->subDays(5)->setTime(9, 45)]);

        Auth::setUser($user);
    }

    protected function seedProperty(Models\Hotel $hotel, Models\User $user, bool $rich): void
    {
        Tenant::set($hotel);
        Auth::setUser($user);
        $today = $hotel->business_date->copy();

        // ---------- Rooms ----------
        $floors = collect(['Ground Floor', 'First Floor', 'Second Floor', 'Third Floor'])->map(fn ($f, $i) => Models\Floor::create(['name' => $f, 'sort_order' => $i]));
        $types = collect([
            ['STD', 'Standard Room', 2800, 600, 300, 'AC, TV, Wi-Fi, Tea/Coffee maker', 24, 'images/rooms/std.jpg'],
            ['DLX', 'Deluxe Room', 4200, 800, 400, 'AC, Smart TV, Wi-Fi, Minibar, Work desk', 32, 'images/rooms/dlx.jpg'],
            ['EXE', 'Executive Suite', 7800, 1200, 600, 'Living area, Bathtub, Minibar, Lounge access', 48, 'images/rooms/exe.jpg'],
            ['PRE', 'Presidential Suite', 15500, 2000, 1000, 'Private butler, Jacuzzi, Dining area, City view', 85, 'images/rooms/pre.jpg'],
        ])->map(fn ($t) => Models\RoomType::create(['code' => $t[0], 'name' => $t[1], 'base_rate' => $t[2], 'extra_adult_rate' => $t[3], 'extra_child_rate' => $t[4], 'amenities' => $t[5], 'max_adults' => $t[0] === 'PRE' ? 4 : 3, 'description' => $t[1].' with modern amenities.',
            // v7.41 room media. The migration that adds these two columns backfills
            // them by code, but on a fresh build it runs before this seeder creates
            // the types, so it matches nothing and the room board is left with no
            // sizes and no photos. Seed them here as well; the migration backfill
            // still serves databases that already had room types.
            'size_sqm' => $t[6] ?? null, 'image' => $t[7] ?? null]));
        $layout = $rich ? [[101, 108, 0], [201, 208, 1], [301, 306, 2], [401, 402, 3]] : [[1, 6, 0], [11, 14, 1], [21, 22, 2]];
        foreach ($layout as $fi => [$a, $b, $ti]) {
            for ($n = $a; $n <= $b; $n++) {
                Models\Room::create(['room_no' => (string) $n, 'room_type_id' => $types[$ti]->id, 'floor_id' => $floors[$fi]->id, 'extension' => (string) (1000 + $n)]);
            }
        }
        foreach ($types as $t) {
            foreach (['EP' => 0, 'CP' => 450, 'MAP' => 1100] as $mp => $add) {
                Models\RatePlan::create(['room_type_id' => $t->id, 'code' => $t->code.'-'.$mp, 'name' => $t->name.' '.$mp, 'meal_plan' => $mp, 'rate' => $t->base_rate + $add, 'extra_adult_rate' => $t->extra_adult_rate, 'extra_child_rate' => $t->extra_child_rate]);
            }
        }

        // ---------- Companies & guests ----------
        $companies = collect([
            ['Infosys Technologies', 'corporate', 'Arun Menon', 500000, 10], ['Tata Consultancy Services', 'corporate', 'Neha Joshi', 300000, 12],
            ['Thomas Cook India', 'travel_agent', 'Karan Shah', 200000, 8], ['Yatra Travels', 'travel_agent', 'Pooja Rao', 100000, 5],
        ])->map(fn ($c) => Models\Company::create(['name' => $c[0], 'type' => $c[1], 'contact_person' => $c[2], 'phone' => '+91 98'.rand(10000000, 99999999), 'email' => strtolower(str_replace(' ', '', $c[2])).'@example.com', 'credit_limit' => $c[3], 'discount_pct' => $c[4], 'commission_pct' => $c[1] === 'travel_agent' ? $c[4] + 2 : 0, 'tax_number' => 'C00'.str_pad((string) (crc32($c[0]) % 100000000), 8, '0', STR_PAD_LEFT),
            'address' => ['Infosys Technologies' => '5th Floor, Airport City, Accra', 'Tata Consultancy Services' => 'Liberation Road, Airport Residential Area, Accra',
                'Thomas Cook India' => '14 Oxford Street, Osu, Accra', 'Yatra Travels' => 'Ring Road Central, Accra'][$c[0]]]));
        $names = [['Mr', 'Rahul', 'Verma', 'Male', 'India'], ['Mrs', 'Sneha', 'Kapoor', 'Female', 'India'], ['Mr', 'John', 'Mensah', 'Male', 'Ghana'], ['Ms', 'Emily', 'Clarke', 'Female', 'United Kingdom'], ['Mr', 'Arjun', 'Reddy', 'Male', 'India'], ['Dr', 'Kavita', 'Menon', 'Female', 'India'], ['Mr', 'Kwame', 'Asante', 'Male', 'Ghana'], ['Mr', 'Hiroshi', 'Tanaka', 'Male', 'Japan'], ['Mrs', 'Fatima', 'Sheikh', 'Female', 'India'], ['Mr', 'Daniel', 'Schmidt', 'Male', 'Germany'], ['Ms', 'Ananya', 'Gupta', 'Female', 'India'], ['Mr', 'Vivek', 'Malhotra', 'Male', 'India'], ['Mr', 'Samuel', 'Owusu', 'Male', 'Ghana'], ['Mrs', 'Lakshmi', 'Pillai', 'Female', 'India'], ['Mr', 'Carlos', 'Rivera', 'Male', 'Spain'], ['Ms', 'Aisha', 'Khan', 'Female', 'India']];
        $guests = collect($names)->map(function ($g, $i) use ($companies) {
            $foreign = $g[4] !== 'Ghana';

            return Models\Guest::create([
                'title' => $g[0], 'first_name' => $g[1], 'last_name' => $g[2], 'gender' => $g[3], 'nationality' => $g[4], 'country' => $g[4],
                'phone' => ($foreign ? '+91 9' : '+233 2').str_pad((string) (800000000 + $i * 7919), $foreign ? 9 : 8, '0'), 'email' => strtolower($g[1].'.'.$g[2]).'@mail.example',
                'id_type' => $foreign ? 'passport' : 'national_id', 'id_number' => $foreign ? 'P'.rand(1000000, 9999999) : 'GHA-'.rand(700000000, 799999999).'-'.rand(1, 9),
                'passport_no' => $foreign ? 'P'.rand(1000000, 9999999) : null, 'visa_expiry' => $foreign ? now()->addMonths(3) : null,
                'city' => $foreign ? (['India' => ['Mumbai', 'Delhi', 'Chennai', 'Pune'][$i % 4], 'United Kingdom' => 'London', 'Japan' => 'Tokyo', 'Germany' => 'Frankfurt', 'Spain' => 'Madrid'][$g[4]] ?? 'Abroad') : ['Accra', 'Kumasi', 'Takoradi', 'Tema'][$i % 4], 'address' => ($i + 10).' Main Street',
                'company_id' => $i % 5 === 0 ? $companies[$i % 4]->id : null, 'is_vip' => $i % 6 === 0, 'dob' => bdate()->copy()->subYears(25 + $i)->subDays($i * 11),
            ]);
        });

        $sources = Models\BusinessSource::pluck('id', 'name');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id');
        $upi = Models\PaymentMode::where('type', 'wallet')->value('id'); // v4.4 Mobile Money (was UPI)
        $rooms = Models\Room::with('roomType')->orderBy('room_no')->get();

        $mk = function ($guest, $room, $arr, $dep, $status, $src, $company = null) use ($user) {
            $plan = Models\RatePlan::where('room_type_id', $room->room_type_id)->where('meal_plan', 'CP')->first();
            $res = Models\Reservation::create([
                'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'company_id' => $company?->id,
                'business_source_id' => $src, 'arrival_date' => $arr, 'departure_date' => $dep, 'adults' => 2, 'children' => 0,
                'status' => $status === 'reserved' ? 'confirmed' : $status, 'created_by' => $user->id, 'eta' => '14:00',
                'billing_instruction' => $company ? 'company' : 'guest', 'purpose' => $company ? 'Business' : 'Leisure',
            ]);
            $stay = Models\ReservationRoom::create([
                'reservation_id' => $res->id, 'room_type_id' => $room->room_type_id, 'room_id' => $status === 'reserved' ? null : $room->id,
                'rate_plan_id' => $plan?->id, 'guest_id' => $guest->id, 'arrival_date' => $arr, 'departure_date' => $dep,
                'rate' => $plan?->rate ?? $room->roomType->base_rate, 'adults' => 2, 'status' => $status,
                'checked_in_at' => in_array($status, ['checked_in', 'checked_out']) ? Carbon::parse($arr)->setTime(13, 20) : null,
                'checked_out_at' => $status === 'checked_out' ? Carbon::parse($dep)->setTime(10, 45) : null,
                'checked_in_by' => in_array($status, ['checked_in', 'checked_out']) ? $user->id : null,
            ]);

            return [$res, $stay];
        };

        $n = $rich ? 1 : 0.4;
        $gi = 0;
        // Past, completed stays (history for reports)
        for ($d = 20; $d >= 3; $d -= 1) {
            foreach ($rooms->random(max(1, (int) (4 * $n))) as $room) {
                $arr = $today->copy()->subDays($d); $dep = $arr->copy()->addDays(rand(1, 3));
                if ($dep->gte($today->copy()->subDays(3))) { continue; }
                [$res, $stay] = $mk($guests[$gi++ % $guests->count()], $room, $arr, $dep, 'checked_out', $sources->random());
                $folio = FolioService::openForStay($stay);
                foreach (\Carbon\CarbonPeriod::create($arr, $dep->copy()->subDay()) as $night) {
                    $tax = \App\Services\TaxService::roomTax((float) $stay->rate);
                    $folio->transactions()->create(['hotel_id' => $hotel->id, 'business_date' => $night->toDateString(), 'type' => 'charge', 'category' => 'room', 'description' => "Room Tariff - Room {$room->room_no} ({$night->format('d M')})", 'amount' => $stay->rate, 'tax_amount' => $tax['amount'], 'tax_rate' => $tax['rate'], 'source_type' => 'night', 'reference' => $night->toDateString(), 'posted_by' => $user->id]);
                }
                if (rand(0, 1)) {
                    $folio->transactions()->create(['hotel_id' => $hotel->id, 'business_date' => $dep->copy()->subDay()->toDateString(), 'type' => 'charge', 'category' => 'fnb', 'description' => 'Restaurant', 'amount' => $fb = rand(6, 30) * 50, 'tax_amount' => TaxService::forCategory('fnb', $fb)['amount'], 'tax_rate' => TaxService::forCategory('fnb', $fb)['rate'], 'posted_by' => $user->id]);
                }
                $folio->transactions()->create(['hotel_id' => $hotel->id, 'business_date' => $dep->toDateString(), 'type' => 'payment', 'category' => 'settlement', 'description' => 'Payment Received', 'amount' => $folio->balance(), 'payment_mode_id' => [$cash, $card, $upi][rand(0, 2)], 'posted_by' => $user->id]);
                $folio->update(['status' => 'closed', 'invoice_no' => Numbering::next('invoice'), 'closed_at' => $dep->copy()->setTime(10, 45), 'closed_by' => $user->id]);
                // SQLite stores a cast date as midnight datetime, so an exact string lookup can
                // miss an existing audit and then collide with the hotel/date unique index.
                // whereDate keeps the demo seed deterministic on SQLite and MySQL alike.
                $auditDate = $arr->toDateString();
                if (! Models\NightAudit::whereDate('business_date', $auditDate)->exists()) {
                    Models\NightAudit::create(['business_date' => $auditDate, 'rooms_total' => $rooms->count(), 'run_by' => $user->id]);
                }
            }
        }
        // Recompute historical audit stats
        foreach (Models\NightAudit::all() as $a) {
            $tx = Models\FolioTransaction::where('business_date', $a->business_date)->where('is_void', false)->get();
            $occ = Models\ReservationRoom::where('arrival_date', '<=', $a->business_date)->where('departure_date', '>', $a->business_date)->where('status', 'checked_out')->count();
            $rr = $tx->where('type', 'charge')->where('category', 'room')->sum('amount');
            $a->update(['rooms_occupied' => $occ, 'room_revenue' => $rr, 'other_revenue' => $tx->where('type', 'charge')->where('category', '!=', 'room')->sum('amount'), 'tax_collected' => $tx->sum('tax_amount'), 'collections' => $tx->where('type', 'payment')->sum('amount'), 'occupancy_pct' => round($occ * 100 / max(1, $rooms->count()), 2), 'adr' => $occ ? round($rr / $occ, 2) : 0, 'revpar' => round($rr / max(1, $rooms->count()), 2)]);
        }

        // In-house guests
        $inHouseRooms = $rooms->take($rich ? 9 : 3);
        foreach ($inHouseRooms as $i => $room) {
            $arr = $today->copy()->subDays($i % 3 + 1); $dep = $i < 3 ? $today->copy() : $today->copy()->addDays($i % 4 + 1);
            $company = $i % 4 === 0 ? $companies[$i % 4] : null;
            [$res, $stay] = $mk($guests[$i], $room, $arr->toDateString(), $dep->toDateString(), 'checked_in', $company ? $sources['Corporate'] : $sources->random(), $company);
            $room->update(['status' => 'occupied', 'hk_status' => $i % 2 ? 'dirty' : 'clean']);
            $stay->load('room', 'reservation');
            $d = $arr->copy();
            while ($d->lt($today)) { StayService::postNight($stay->fresh(['room', 'reservation', 'folio']), $d->copy()); $d->addDay(); }
            $folio = $stay->fresh()->folio;
            // As if each past night had been closed by its own night audit
            Models\FolioTransaction::where('folio_id', $folio->id)->whereIn('source_type', ['night', 'night_extra'])->get()
                ->each(fn ($t) => $t->forceFill(['business_date' => $t->reference])->save());
            if ($i % 2 === 0) { FolioService::payment($folio, 2000, $upi, 'MOMO-'.rand(10000000, 99999999), 'advance'); }
            if ($i % 3 === 0) { FolioService::charge($folio, 'laundry', 'Laundry – 4 pcs', 480); }
        }
        // Arrivals today & future reservations
        $free = $rooms->slice($rich ? 12 : 5)->slice(0, -1)->values(); // the last room is put out of order below
        foreach (range(0, $rich ? 11 : 4) as $i) {
            $arr = $today->copy()->addDays($i < 4 ? 0 : $i - 2); $dep = $arr->copy()->addDays(rand(1, 4));
            [$res] = $mk($guests[($i + 5) % $guests->count()], $free[$i % $free->count()], $arr->toDateString(), $dep->toDateString(), 'reserved', $sources->random());
            if ($i === 5) { $res->update(['status' => 'tentative']); }
            if ($i % 3 === 0) {
                $f = FolioService::openStandalone(['reservation_id' => $res->id, 'guest_id' => $res->guest_id, 'type' => 'guest']);
                FolioService::payment($f, 1500, $card, 'CARD-'.rand(1000, 9999), 'advance');
            }
        }
        // Group reservation
        if ($rich) {
            $res = Models\Reservation::create(['reservation_no' => Numbering::next('reservation'), 'guest_id' => $guests[2]->id, 'company_id' => $companies[0]->id, 'business_source_id' => $sources['Corporate'], 'booking_type' => 'group', 'group_name' => 'Infosys Leadership Offsite', 'arrival_date' => $today->copy()->addDays(6), 'departure_date' => $today->copy()->addDays(8), 'adults' => 8, 'status' => 'confirmed', 'billing_instruction' => 'company', 'created_by' => $user->id]);
            foreach (range(1, 4) as $k) {
                Models\ReservationRoom::create(['reservation_id' => $res->id, 'room_type_id' => $types[1]->id, 'arrival_date' => $res->arrival_date, 'departure_date' => $res->departure_date, 'rate' => 3800, 'adults' => 2, 'status' => 'reserved']);
            }
        }
        // Out of order room + a dirty vacant one
        $rooms->last()->update(['status' => 'out_of_order']);
        $rooms[$rich ? 10 : 4]->update(['hk_status' => 'dirty']);

        // ---------- Employees ----------
        $depts = Models\Department::pluck('id', 'name');
        // [name, department, designation, monthly basic, joined (months ago | date), payment mode type, OT eligible]
        $emps = collect([['Ramesh Gowda', 'Housekeeping', 'Room Attendant', 2500, 30, 'bank', true], ['Lata Bai', 'Housekeeping', 'Room Attendant', 2400, 40, 'cash', true],
            ['Joseph Thomas', 'Maintenance', 'Electrician', 3500, 26, 'bank', true], ['Imran Ali', 'Maintenance', 'Plumber', 3200, 18, 'bank', true],
            ['Deepa Nair', 'Front Office', 'Receptionist', 3800, 14, 'bank', true], ['Mohan Lal', 'Kitchen', 'Head Chef', 8000, 36, 'bank', false],
            ['Sunil Yadav', 'F&B Service', 'Steward', 2700, 11, 'cash', true], ['Kiran Patil', 'Security', 'Guard', 2600, 8, 'bank', true],
            ['Abena Owusu', 'Housekeeping', 'Room Attendant', 2450, 6, 'wallet', true], ['Grace Mensah', 'Spa & Wellness', 'Spa Manager & Therapist', 5500, 20, 'bank', false],
            ['Efua Asante', 'Spa & Wellness', 'Massage Therapist', 3900, 9, 'wallet', true], ['Kofi Boateng', 'Spa & Wellness', 'Fitness Instructor', 3600, '2026-08-18', 'bank', true],
            ['Ravi Kumar', 'Front Office', 'Front Office Manager', 7000, 28, 'bank', false], ['Meera Iyer', 'Accounts', 'Chief Accountant', 6500, 24, 'bank', false],
            ['Anjali Menon', 'Management', 'HR Manager', 6000, 16, 'bank', false]])
            ->take($rich ? 15 : 12)
            ->map(fn ($e, $i) => Models\Employee::create(['emp_code' => 'EMP'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'name' => $e[0], 'department_id' => $depts[$e[1]], 'designation' => $e[2],
                'phone' => '+233 24'.rand(1000000, 9999999), 'joining_date' => is_string($e[4]) ? Carbon::parse($e[4]) : $today->copy()->subMonths($e[4])->startOfMonth()->addDays(($i * 3) % 20),
                'salary' => $e[3], 'payment_mode_id' => Models\PaymentMode::where('type', $e[5])->value('id'), 'ot_eligible' => $e[6],
                'bank_name' => $e[5] === 'bank' ? ['GCB Bank', 'Ecobank Ghana', 'Stanbic Bank Ghana'][$i % 3] : null, 'bank_account' => $e[5] === 'bank' ? (string) (30000000000 + $i * 7919137) : ($e[5] === 'wallet' ? '024'.str_pad((string) (5550000 + $i * 131), 7, '0') : null),
                'bank_ifsc' => $e[5] === 'bank' ? ['040101', '130101', '190101'][$i % 3] : null, 'tax_id' => 'GHA-'.(712000000 + $i * 104729).'-'.($i % 9 + 1),
                'social_security_no' => 'C'.(18000000000 + $i * 1117)]));
        $this->seedAttendance($emps, $rich, $today);
        // ---------- Housekeeping & maintenance ----------
        // v1.5: link logins to employees (attendant → My rooms, spa → therapist)
        foreach (['attendant' => 'Ramesh Gowda', 'spa' => 'Grace Mensah', 'fom' => 'Ravi Kumar', 'accounts' => 'Meera Iyer', 'hr' => 'Anjali Menon', 'engineer' => 'Joseph Thomas'] as $u => $n) {
            if ($uid = Models\User::where('hotel_id', $hotel->id)->where('username', $u)->value('id')) {
                $emps->firstWhere('name', $n)?->update(['user_id' => $uid]);
            }
        }
        Models\MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'room_id' => $rooms->last()->id, 'category' => 'ac', 'title' => 'AC not cooling', 'description' => 'Compressor noise, needs gas refill.', 'priority' => 'high', 'status' => 'in_progress', 'block_room' => true, 'employee_id' => $emps[2]->id, 'reported_by' => $user->id]);
        Models\MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'room_id' => $rooms[1]->id, 'category' => 'plumbing', 'title' => 'Leaking tap in bathroom', 'priority' => 'normal', 'status' => 'open', 'employee_id' => $emps[3]->id, 'reported_by' => $user->id]);

        // ---------- POS ----------
        $rest = Models\Outlet::create(['name' => $rich ? 'Saffron Multi-Cuisine Restaurant' : 'Lakeside Café', 'type' => 'restaurant', 'service_charge_pct' => 0]);
        $bar = Models\Outlet::create(['name' => 'Skyline Bar', 'type' => 'bar']);
        $rs = Models\Outlet::create(['name' => 'In-Room Dining', 'type' => 'room_service']);
        foreach (range(1, 12) as $t) { Models\PosTable::create(['outlet_id' => $rest->id, 'table_no' => 'T'.$t, 'capacity' => $t % 3 ? 4 : 6, 'section' => $t <= 6 ? 'Indoor' : 'Terrace']); }
        foreach (range(1, 6) as $t) { Models\PosTable::create(['outlet_id' => $bar->id, 'table_no' => 'B'.$t, 'capacity' => 4]); }
        $menu = [
            'Starters' => [['Paneer Tikka', 320, 'veg'], ['Chicken 65', 360, 'non_veg'], ['Veg Spring Rolls', 240, 'veg'], ['Fish Amritsari', 420, 'non_veg']],
            'Main Course' => [['Butter Chicken', 450, 'non_veg'], ['Dal Makhani', 280, 'veg'], ['Paneer Butter Masala', 340, 'veg'], ['Mutton Rogan Josh', 520, 'non_veg'], ['Veg Biryani', 300, 'veg'], ['Chicken Biryani', 380, 'non_veg']],
            'Breads & Rice' => [['Butter Naan', 60, 'veg'], ['Garlic Naan', 80, 'veg'], ['Steamed Rice', 150, 'veg'], ['Jeera Rice', 180, 'veg']],
            'Breakfast' => [['Masala Dosa', 160, 'veg'], ['Idli Vada', 140, 'veg'], ['Omelette', 150, 'egg'], ['Continental Breakfast', 450, 'veg']],
            'Desserts' => [['Gulab Jamun', 120, 'veg'], ['Ice Cream Sundae', 220, 'veg']],
            'Beverages' => [['Masala Chai', 60, 'beverage'], ['Filter Coffee', 80, 'beverage'], ['Fresh Lime Soda', 110, 'beverage'], ['Mineral Water', 40, 'beverage'], ['Cold Coffee', 180, 'beverage']],
        ];
        $allItems = collect();
        $sort = 0;
        foreach ($menu as $cat => $items) {
            $c = Models\MenuCategory::create(['name' => $cat, 'sort_order' => $sort++, 'kitchen_station' => match ($cat) { 'Beverages' => 'Bar', 'Desserts' => 'Pastry', 'Breads & Rice' => 'Tandoor', default => null }]);
            foreach ($items as $k => [$nm, $pr, $ft]) {
                $allItems->push(Models\MenuItem::create(['menu_category_id' => $c->id, 'code' => strtoupper(substr($cat, 0, 2)).($k + 1), 'name' => $nm, 'price' => $pr, 'tax_rate' => TaxService::rateFor('fnb', 0), 'food_type' => $ft]));
            }
        }
        $bc = Models\MenuCategory::create(['name' => 'Bar', 'outlet_id' => $bar->id, 'sort_order' => 99, 'kitchen_station' => 'Bar']);
        foreach ([['Kingfisher Premium', 280], ['Old Monk (60ml)', 220], ['Johnnie Walker Black (60ml)', 650], ['House Red Wine (Glass)', 450], ['Mojito (Mocktail)', 250]] as [$nm, $pr]) {
            Models\MenuItem::create(['menu_category_id' => $bc->id, 'outlet_id' => $bar->id, 'name' => $nm, 'price' => $pr, 'tax_rate' => TaxService::rateFor('fnb', 0), 'food_type' => 'beverage']);
        }
        // v7.6 KOT printers: hot / tandoor / pastry / bar / captain, plus a spare network grill pass
        $mkP = fn (array $a) => Models\KotPrinter::create($a + ['is_active' => true, 'copies' => 1, 'paper' => '80', 'connection' => 'browser', 'beep' => true, 'sort_order' => 0]);
        $hot = $mkP(['code' => 'HOT', 'name' => 'Hot Kitchen', 'outlet_id' => $rest->id, 'station' => 'Kitchen', 'role' => 'station', 'is_fallback' => true, 'header' => 'HOT KITCHEN', 'sort_order' => 1, 'notes' => $hotel->code.'-HOT']);
        $tan = $mkP(['code' => 'TAN', 'name' => 'Tandoor', 'outlet_id' => $rest->id, 'station' => 'Tandoor', 'role' => 'station', 'header' => 'TANDOOR', 'sort_order' => 2]);
        $pas = $mkP(['code' => 'PAS', 'name' => 'Pastry', 'outlet_id' => $rest->id, 'station' => 'Pastry', 'role' => 'station', 'header' => 'PASTRY', 'sort_order' => 3]);
        $barP = $mkP(['code' => 'BAR', 'name' => 'Bar', 'station' => 'Bar', 'role' => 'station', 'header' => 'BAR', 'sort_order' => 4]);
        $mkP(['code' => 'CAP', 'name' => 'Captain', 'role' => 'captain', 'header' => 'CAPTAIN COPY — do not cook from this slip', 'sort_order' => 5]);
        $mkP(['code' => 'GRILL', 'name' => 'Grill pass', 'outlet_id' => $rest->id, 'station' => 'Grill', 'role' => 'station', 'connection' => 'network', 'host' => '192.168.50.41', 'port' => 9100, 'header' => 'GRILL', 'sort_order' => 6, 'notes' => 'Network printer on the kitchen LAN. Not used until a category is pointed at it.']);
        Models\MenuCategory::whereNull('kitchen_station')->update(['kot_printer_id' => $hot->id]);
        Models\MenuCategory::where('kitchen_station', 'Tandoor')->update(['kot_printer_id' => $tan->id]);
        Models\MenuCategory::where('kitchen_station', 'Pastry')->update(['kot_printer_id' => $pas->id]);
        Models\MenuCategory::where('kitchen_station', 'Bar')->update(['kot_printer_id' => $barP->id]);
        foreach ([
            'Paneer Tikka' => 'paneer-tikka.jpg', 'Chicken 65' => 'chicken-65.jpg', 'Veg Spring Rolls' => 'veg-spring-rolls.jpg', 'Fish Amritsari' => 'fish-amritsari.jpg',
            'Butter Chicken' => 'butter-chicken.jpg', 'Dal Makhani' => 'dal-makhani.jpg', 'Paneer Butter Masala' => 'paneer-butter-masala.jpg', 'Mutton Rogan Josh' => 'mutton-rogan-josh.jpg',
            'Veg Biryani' => 'veg-biryani.jpg', 'Chicken Biryani' => 'chicken-biryani.jpg', 'Butter Naan' => 'butter-naan.jpg', 'Garlic Naan' => 'garlic-naan.jpg',
            'Steamed Rice' => 'steamed-rice.jpg', 'Jeera Rice' => 'jeera-rice.jpg', 'Masala Dosa' => 'masala-dosa.jpg', 'Idli Vada' => 'idli-vada.jpg',
            'Omelette' => 'omelette.jpg', 'Continental Breakfast' => 'continental-breakfast.jpg', 'Gulab Jamun' => 'gulab-jamun.jpg', 'Ice Cream Sundae' => 'ice-cream-sundae.jpg',
            'Masala Chai' => 'masala-chai.jpg', 'Filter Coffee' => 'filter-coffee.jpg', 'Fresh Lime Soda' => 'fresh-lime-soda.jpg', 'Mineral Water' => 'mineral-water.jpg', 'Cold Coffee' => 'cold-coffee.jpg',
            'Kingfisher Premium' => 'lager.jpg', 'Old Monk (60ml)' => 'dark-rum.jpg', 'Johnnie Walker Black (60ml)' => 'whisky.jpg', 'House Red Wine (Glass)' => 'red-wine.jpg', 'Mojito (Mocktail)' => 'mojito.jpg',
        ] as $dish => $file) {
            Models\MenuItem::where('name', $dish)->update(['image_path' => 'uploads/menu/demo/'.$file]);
        }
        // Historical settled POS bills
        for ($d = 10; $d >= 1; $d--) {
            foreach (range(1, $rich ? 4 : 2) as $k) {
                $o = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'bill_no' => Numbering::next('pos_bill'), 'outlet_id' => $rest->id, 'pos_table_id' => Models\PosTable::where('outlet_id', $rest->id)->inRandomOrder()->value('id'), 'order_type' => 'dine_in', 'covers' => rand(1, 4), 'business_date' => $today->copy()->subDays($d), 'status' => 'settled', 'settlement' => 'payment', 'payment_mode_id' => [$cash, $card, $upi][rand(0, 2)], 'user_id' => $user->id, 'settled_at' => $today->copy()->subDays($d)->setTime(21, 0)]);
                foreach ($allItems->random(rand(2, 5)) as $it) {
                    $q = rand(1, 2);
                    $o->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => $q, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => $q * $it->price, 'kot_no' => 1]);
                }
                $o->recalculate();
            }
        }
        // One running table order
        $o = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'outlet_id' => $rest->id, 'pos_table_id' => Models\PosTable::where('outlet_id', $rest->id)->value('id'), 'order_type' => 'dine_in', 'covers' => 3, 'steward' => 'Sunil', 'business_date' => $today, 'status' => 'open', 'user_id' => $user->id]);
        $kot = Numbering::next('kot');
        foreach ($allItems->random(3)->values() as $ix => $it) { $o->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => 1, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => $it->price, 'kot_no' => $kot])
            ->forceFill(['kitchen_status' => ['served', 'ready', 'preparing'][$ix], 'created_at' => now()->subMinutes(14), 'ready_at' => $ix < 2 ? now()->subMinutes(3) : null, 'served_at' => $ix === 0 ? now()->subMinutes(2) : null])->save(); }
        $o->recalculate();
        app(\App\Services\KotPrintService::class)->stamp($o->fresh(), (int) $kot);
        // Kitchen display demo: a second table with a fresh KOT across stations
        $t2 = Models\PosTable::where('outlet_id', $rest->id)->orderBy('id')->skip(1)->first();
        if ($t2) {
            $o2 = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'outlet_id' => $rest->id, 'pos_table_id' => $t2->id, 'order_type' => 'dine_in', 'covers' => 2, 'steward' => 'Grace', 'business_date' => $today, 'status' => 'open', 'user_id' => $user->id]);
            $kot2 = Numbering::next('kot');
            foreach ($allItems->filter(fn ($i) => in_array($i->menu_category_id, Models\MenuCategory::whereIn('kitchen_station', ['Bar', 'Pastry'])->pluck('id')->all()))->take(2)->concat($allItems->random(2)) as $it) {
                $o2->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => 1, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => $it->price, 'kot_no' => $kot2])->forceFill(['created_at' => now()->subMinutes(4)])->save();
            }
            $o2->recalculate();
            app(\App\Services\KotPrintService::class)->stamp($o2->fresh(), (int) $kot2);
        }
        Models\PosOrderItem::whereHas('order', fn ($q) => $q->where('status', 'settled'))->update(['kitchen_status' => 'served']);
        // A guest QR self-order waiting for staff confirmation
        if ($rich) {
            $qt = Models\PosTable::where('outlet_id', $rest->id)->where('table_no', 'T5')->first();
            $qo = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'outlet_id' => $rest->id, 'pos_table_id' => $qt->id, 'order_type' => 'dine_in', 'source' => 'qr', 'customer_name' => 'Kwame', 'covers' => 2, 'business_date' => $today, 'status' => 'open']);
            foreach ($allItems->whereIn('name', ['Paneer Tikka', 'Butter Naan', 'Fresh Lime Soda']) as $it) {
                $qo->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => $it->name === 'Butter Naan' ? 2 : 1, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => ($it->name === 'Butter Naan' ? 2 : 1) * $it->price, 'is_pending' => true, 'notes' => $it->name === 'Paneer Tikka' ? 'Less spicy' : null]);
            }
            $qo->recalculate();
        }

        // ---------- Seasonal rates ----------
        $byCode = $types->keyBy('code');
        $festFrom = Carbon::create($today->year, 12, 20);
        Models\RateOverride::create(['room_type_id' => $byCode['STD']->id, 'name' => 'Early-week saver', 'date_from' => $today->copy()->addDays(3), 'date_to' => $today->copy()->addDays(9), 'rate' => round($byCode['STD']->base_rate * .85), 'created_by' => $user->id]);
        foreach (['STD', 'DLX', 'EXE', 'PRE'] as $code) {
            $t = $byCode[$code];
            Models\RateOverride::create(['room_type_id' => $t->id, 'name' => 'Weekend premium', 'date_from' => $today, 'date_to' => $today->copy()->addDays(60), 'rate' => null, 'weekend_rate' => round($t->base_rate * 1.15), 'created_by' => $user->id]);
            Models\RateOverride::create(['room_type_id' => $t->id, 'name' => 'Festive peak', 'date_from' => $festFrom, 'date_to' => $festFrom->copy()->addDays(13), 'rate' => round($t->base_rate * 1.4), 'weekend_rate' => round($t->base_rate * 1.55), 'min_stay' => 2, 'created_by' => $user->id]);
        }
        if ($rich) {
            Models\RateOverride::create(['room_type_id' => $byCode['PRE']->id, 'name' => 'Owner block – closed online', 'date_from' => $today->copy()->addDays(12), 'date_to' => $today->copy()->addDays(14), 'stop_sell' => true, 'created_by' => $user->id]);
        }

        // ---------- Loyalty ----------
        foreach ($guests->take($rich ? 8 : 3)->values() as $i => $g) {
            $pts = [2450, 820, 5600, 310, 150, 1200, 95, 640][$i];
            Models\LoyaltyEntry::create(['type' => 'opening', 'guest_id' => $g->id, 'points' => $pts, 'description' => 'Opening balance (migrated from previous system)', 'user_id' => $user->id]);
            $g->update(['loyalty_points' => $pts]);
        }

        // ---------- Banquet masters (v2.5) ----------
        \App\Services\BanquetSalesService::ensureDefaults();
        foreach ([['Grand Ballroom', 'GBR', 400, 6200, [450, 220, 300, 80, 500, null], 45000, 75000, 'Stage, in-house PA, LED wall rigging, bridal room, 2 service entrances', false],
            ['Emerald Hall', 'EMH', 150, 2400, [160, 90, 120, 45, 180, 40], 20000, 35000, 'Built-in projector, divider, natural light, pre-function foyer', false],
            ['Boardroom', 'BRD', 20, 480, [24, null, null, 16, null, 20], 5000, 8000, '75" display, video-conference, whiteboard', false],
            ['Poolside Lawn', 'LWN', 700, 12000, [null, null, 450, null, 700, null], 60000, 90000, 'Open lawn by the pool, power points, rain-cover tent on request', true]] as $k => [$n, $c, $cap, $area, $caps, $half, $full, $fac, $out]) {
            if (! $rich && $k > 1) {
                continue;
            }
            Models\BanquetHall::create(['name' => $n, 'code' => $c, 'capacity' => $cap, 'area_sqft' => $area, 'cap_theatre' => $caps[0], 'cap_classroom' => $caps[1], 'cap_banquet' => $caps[2],
                'cap_ushape' => $caps[3], 'cap_cocktail' => $caps[4], 'cap_boardroom' => $caps[5], 'rate_per_session' => $half, 'rate_full_day' => $full, 'facilities' => $fac, 'is_outdoor' => $out, 'sort' => $k]);
        }
        foreach ([['Classic Veg Buffet', 'food', 'buffet', 1100, 50, "Welcome drink\n2 veg starters\nSoup\n4 veg mains, dal, rice, breads\nSalad bar\n2 desserts"],
            ['Royal Non-Veg Buffet', 'food', 'buffet', 1450, 50, "Welcome drink\n2 veg + 2 non-veg starters\nSoup\nJollof & biryani\nGrilled tilapia, chicken, 3 veg mains\nLive pasta counter\n3 desserts"],
            ['Premium Wedding Buffet', 'food', 'buffet', 1850, 100, "Mocktail welcome\n6 passed canapés\nLive grill & chaat counters\n8 mains incl. lamb & prawn\nWedding cake cutting service\n5 desserts & ice-cream bar"],
            ['Plated Corporate Lunch', 'food', 'plated', 950, 10, "Soup or salad\nChoice of 2 mains\nDessert\nTea / coffee"],
            ['Coffee Break / Hi-Tea', 'break', 'break', 350, 10, "Tea, coffee, juice\n2 savoury bites\nCookies"],
            ['Working Lunch', 'food', 'buffet', 650, 10, "Sandwich & wrap platters\n1 hot main\nSalad\nFruit"],
            ['Cocktail Snacks', 'food', 'cocktail', 700, 30, "8 passed snacks (veg & non-veg)\n2 live stations"],
            ['Mocktail Bar', 'beverage', 'bar', 400, 30, "3 signature mocktails\nSoft drinks & water\nBartender"]] as $k => [$n, $t, $st, $pr, $min, $items]) {
            Models\BanquetPackage::create(['name' => $n, 'type' => $t, 'style' => $st, 'price_per_pax' => $pr, 'min_pax' => $min, 'tax_rate' => TaxService::rateFor('fnb', 0), 'items' => $items, 'sort' => $k]);
        }
        $dId = Models\Department::pluck('id', 'name');
        foreach ([['Projector & screen', 'av', 'day', 3500, 'Maintenance'], ['Cordless microphone', 'av', 'day', 800, 'Maintenance'], ['PA system', 'av', 'day', 6000, 'Maintenance'],
            ['LED video wall', 'av', 'day', 25000, 'Maintenance'], ['Video-conference kit', 'av', 'day', 5000, 'Maintenance'], ['Stage & backdrop', 'decor', 'event', 30000, 'Housekeeping'],
            ['Floral centrepiece', 'decor', 'piece', 1200, 'Housekeeping'], ['DJ & dance floor', 'entertainment', 'event', 18000, 'F&B Service'], ['Photo booth', 'entertainment', 'event', 12000, 'F&B Service'],
            ['Flip chart & markers', 'equipment', 'day', 300, 'Housekeeping'], ['Additional service staff', 'staffing', 'piece', 1500, 'F&B Service']] as [$n, $c, $u, $pr, $dep]) {
            Models\BanquetService::create(['name' => $n, 'category' => $c, 'unit' => $u, 'price' => $pr, 'tax_rate' => TaxService::rateFor('fnb', 0), 'department_id' => $dId[$dep] ?? null]);
        }

        // ---------- Inventory ----------
        $cats = collect(['Grocery', 'Beverages', 'Housekeeping Supplies', 'Guest Amenities', 'Cleaning Chemicals'])->map(fn ($c) => Models\ItemCategory::create(['name' => $c]));
        $stores = Models\Store::pluck('id', 'type');
        $inv = [[0, 'Basmati Rice', 'kg', 25, 95], [0, 'Refined Oil', 'ltr', 20, 160], [0, 'Paneer', 'kg', 5, 380], [0, 'Chicken', 'kg', 10, 240], [1, 'Mineral Water 1L', 'btl', 100, 14], [1, 'Soft Drinks 300ml', 'btl', 48, 25], [2, 'Bath Towel', 'pcs', 40, 320], [2, 'Bed Sheet (King)', 'pcs', 40, 650], [3, 'Shampoo Sachet', 'pcs', 200, 4], [3, 'Soap 30g', 'pcs', 200, 6], [3, 'Dental Kit', 'pcs', 100, 12], [4, 'Floor Cleaner 5L', 'can', 5, 480]];
        $suppliers = collect([['Metro Cash & Carry', 'Rakesh'], ['FreshFarm Foods', 'Anil'], ['CleanPro Supplies', 'Sanjay']])->map(fn ($s) => Models\Supplier::create(['name' => $s[0], 'contact_person' => $s[1], 'phone' => '+233 24 '.rand(100, 999).' '.rand(1000, 9999), 'wht_category' => 'goods', 'tax_number' => 'C00'.rand(10000000, 99999999)]));
        foreach ($inv as $k => [$ci, $nm, $u, $ro, $cost]) {
            $item = Models\InventoryItem::create(['item_category_id' => $cats[$ci]->id, 'code' => 'ITM'.str_pad((string) ($k + 1), 3, '0', STR_PAD_LEFT), 'name' => $nm, 'unit' => $u, 'reorder_level' => $ro, 'last_cost' => $cost, 'tax_rate' => 20]);
            $store = $ci <= 1 ? $stores['kitchen'] : $stores['housekeeping'];
            $qty = $k % 5 === 0 ? $ro * 0.6 : $ro * 3;
            Models\StockMovement::create(['inventory_item_id' => $item->id, 'store_id' => $store, 'date' => $today->copy()->subDays(15), 'type' => 'opening', 'qty' => $qty, 'rate' => $cost, 'reference' => 'Opening', 'user_id' => $user->id]);
            if ($k % 2) { Models\StockMovement::create(['inventory_item_id' => $item->id, 'store_id' => $store, 'date' => $today->copy()->subDays(3), 'type' => 'issue', 'qty' => -round($qty * .2, 2), 'rate' => $cost, 'department' => $ci <= 1 ? 'Kitchen' : 'Housekeeping', 'user_id' => $user->id]); }
        }
        $po = Models\PurchaseOrder::create(['po_no' => Numbering::next('po'), 'supplier_id' => $suppliers[1]->id, 'store_id' => $stores['kitchen'], 'po_date' => $today, 'expected_date' => $today->copy()->addDays(2), 'status' => 'approved', 'created_by' => $user->id]);
        foreach (Models\InventoryItem::take(3)->get() as $it) { $po->items()->create(['inventory_item_id' => $it->id, 'qty' => 10, 'rate' => $it->last_cost, 'tax_rate' => 20, 'amount' => 10 * $it->last_cost]); }
        $sub = $po->items()->sum('amount'); $po->update(['subtotal' => $sub, 'tax' => round($sub * .20, 2), 'total' => round($sub * 1.20, 2)]);

        // ---------- Phase 3: recipes, OTA channels, cashier shifts, e-mails ----------
        Models\Outlet::query()->update(['store_id' => $stores['kitchen']]);
        $inv = Models\InventoryItem::pluck('id', 'name');
        $recipes = ['Paneer Tikka' => ['Paneer' => .15, 'Refined Oil' => .02], 'Paneer Butter Masala' => ['Paneer' => .18, 'Refined Oil' => .03],
            'Chicken 65' => ['Chicken' => .25, 'Refined Oil' => .05], 'Butter Chicken' => ['Chicken' => .25, 'Refined Oil' => .03],
            'Chicken Biryani' => ['Chicken' => .2, 'Basmati Rice' => .15, 'Refined Oil' => .02], 'Veg Biryani' => ['Basmati Rice' => .18, 'Refined Oil' => .02],
            'Steamed Rice' => ['Basmati Rice' => .15], 'Jeera Rice' => ['Basmati Rice' => .15, 'Refined Oil' => .01], 'Mineral Water' => ['Mineral Water 1L' => 1]];
        foreach ($recipes as $dish => $lines) {
            if ($mi = $allItems->firstWhere('name', $dish)) {
                foreach ($lines as $stock => $q) {
                    Models\MenuItemRecipe::create(['menu_item_id' => $mi->id, 'inventory_item_id' => $inv[$stock], 'qty' => $q]);
                }
            }
        }
        $srcs = Models\BusinessSource::pluck('id', 'name');
        $rtypes = Models\RoomType::orderBy('id')->get();
        Models\OtaChannel::create(['name' => 'Booking.com – '.$rtypes[0]->name, 'room_type_id' => $rtypes[0]->id, 'business_source_id' => $srcs['Booking.com']]);
        if ($rich) {
            $ab = Models\OtaChannel::create(['name' => 'Airbnb – '.$rtypes[1]->name, 'room_type_id' => $rtypes[1]->id, 'business_source_id' => $srcs['Airbnb'],
                'import_url' => 'https://www.airbnb.com/calendar/ical/40123456.ics?s=demo']);
            $st = \App\Services\IcalService::import($ab, [
                ['uid' => 'HMX8Q2ZP4K@airbnb.com', 'start' => $today->copy()->addDays(4)->toDateString(), 'end' => $today->copy()->addDays(7)->toDateString(), 'summary' => 'Emma Johansson (HMX8Q2ZP4K)', 'status' => 'CONFIRMED', 'description' => 'Guests: 2'],
                ['uid' => 'blk-77120@airbnb.com', 'start' => $today->copy()->addDays(12)->toDateString(), 'end' => $today->copy()->addDays(14)->toDateString(), 'summary' => 'Airbnb (Not available)', 'status' => 'CONFIRMED', 'description' => ''],
            ]);
            $ab->forceFill(['last_sync_at' => now()->subMinutes(9), 'last_sync_status' => "OK – {$st['created']} new, 0 updated, 0 cancelled"])->save();

            $cashier = Models\User::where('hotel_id', $hotel->id)->where('username', 'cashier')->first();
            $fd = Models\User::where('hotel_id', $hotel->id)->where('username', 'frontdesk')->first();
            $closed = Models\CashierShift::create(['user_id' => $fd->id, 'business_date' => $today->copy()->subDay(), 'opened_at' => now()->subDay()->setTime(7, 0), 'opening_float' => 5000]);
            $closed->forceFill(['status' => 'closed', 'closed_at' => now()->subDay()->setTime(15, 5), 'expected_cash' => 17400, 'counted_cash' => 17350, 'variance' => -50,
                'notes' => 'Handed over to evening shift', 'summary' => ['modes' => ['Cash' => ['in' => 12400.0, 'out' => 0.0, 'net' => 12400.0, 'is_cash' => true, 'count' => 5], 'Credit / Debit Card' => ['in' => 18650.0, 'out' => 0.0, 'net' => 18650.0, 'is_cash' => false, 'count' => 3], 'Mobile Money (MoMo)' => ['in' => 3200.0, 'out' => 0.0, 'net' => 3200.0, 'is_cash' => false, 'count' => 2]],
                    'cash_in' => 12400.0, 'cash_out' => 0.0, 'expected_cash' => 17400.0, 'total_in' => 34250.0, 'total_out' => 0.0, 'count' => 10]])->save();
            Models\CashierShift::create(['user_id' => $cashier->id, 'business_date' => $today, 'opened_at' => now()->subHours(2), 'opening_float' => 3000, 'notes' => 'Restaurant counter']);
            $conf = Models\Reservation::with('guest')->where('status', 'confirmed')->whereNull('ota_channel_id')->whereHas('guest', fn ($q) => $q->whereNotNull('email'))->latest('id')->first();
            if ($conf) {
                \App\Services\MailService::reservationConfirmation($conf);
            }
        }

        // ---------- Phase 4: packages, group blocks, flash e-mail ----------
        if ($rich) {
            Models\Setting::updateOrCreate(['key' => 'flash_report_emails'], ['value' => 'gm@nowills-demo.com']);
            $inhouse = Models\ReservationRoom::with('folio')->where('status', 'checked_in')->where('departure_date', '>', $today->copy()->addDay())->whereHas('folio', fn ($q) => $q->where('status', 'open'))->orderBy('id')->take(2)->get();
            foreach ($inhouse as $i => $st) {
                Models\StayFixedCharge::create(['reservation_room_id' => $st->id, 'description' => 'Breakfast ('.$st->adults.' pax)', 'category' => 'fnb',
                    'amount' => 350 * max(1, $st->adults), 'apply_tax' => true, 'frequency' => 'daily', 'start_date' => $today->copy()->max($st->arrival_date)]);
                if ($i === 0) {
                    Models\StayFixedCharge::create(['reservation_room_id' => $st->id, 'description' => 'Airport drop', 'category' => 'transport',
                        'amount' => 1200, 'apply_tax' => true, 'frequency' => 'once', 'start_date' => $today->copy()->max($st->arrival_date)]);
                }
            }
            $corp = Models\Company::where('type', 'corporate')->orderBy('id')->get();
            $rt = Models\RoomType::where('is_active', true)->orderBy('id')->get();
            $mk = function (array $attrs, array $lines) use ($today) {
                foreach ($lines as $type => $n) {
                    $free = \App\Services\AvailabilityService::typeAvailable($type, $attrs['arrival_date'], $attrs['departure_date']);
                    $lines[$type] = min($n, max(0, $free));
                }
                $lines = array_filter($lines);
                if (! $lines) {
                    return null;
                }
                $b = Models\RoomBlock::create($attrs + ['block_no' => Numbering::next('block'), 'created_by' => Auth::id()]);
                foreach ($lines as $type => $n) {
                    $b->lines()->create(['room_type_id' => $type, 'rooms' => $n]);
                }

                return $b->load('lines');
            };
            $wed = $mk(['name' => 'Mensah–Owusu Wedding', 'company_id' => null, 'contact_name' => 'Akosua Mensah', 'contact_phone' => '+233 24 555 0142', 'contact_email' => 'akosua.mensah@example.com',
                'arrival_date' => $today->copy()->addDays(10), 'departure_date' => $today->copy()->addDays(12), 'cutoff_date' => $today->copy()->addDays(3),
                'rate' => 4200, 'billing_instruction' => 'guest', 'status' => 'definite', 'notes' => 'Ceremony at the lawn on day 1; late check-out requested for the couple.'], [$rt[0]->id => 4, $rt[1]->id => 2]);
            if ($wed) {
                \App\Services\BlockService::pickup($wed, ['Kwame Mensah', 'Ama Owusu', 'Kofi Boateng'], $wed->lines->first()->room_type_id, 2, $wed->arrival_date->toDateString(), $wed->departure_date->toDateString());
            }
            $mk(['name' => 'Accra Fintech Summit 2026', 'company_id' => $corp->first()?->id, 'business_source_id' => $srcs['Corporate'] ?? null, 'contact_name' => 'Events desk',
                'arrival_date' => $today->copy()->addDays(20), 'departure_date' => $today->copy()->addDays(23), 'cutoff_date' => $today->copy()->addDays(13),
                'rate' => null, 'billing_instruction' => 'company', 'status' => 'tentative', 'notes' => 'Speakers + organising team. Rooming list expected two weeks out.'], [$rt[1]->id => 3, ($rt[2] ?? $rt[0])->id => 2]);
        }

        if ($rich) {
            $this->seedBanquet($hotel, $user, $today); // v2.5: banquet & conference sales pipeline
        }

        // ---------- Phase 5: commissions, stock take, online check-in, cancellation policy ----------
        foreach (['cancel_free_hours' => '48', 'cancel_fee_type' => 'first_night', 'cancel_fee_value' => '0', 'noshow_fee_type' => 'first_night', 'noshow_fee_value' => '0', 'noshow_fee_auto' => '1', 'online_checkin_enabled' => '1'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        $agents = Models\Company::where('commission_pct', '>', 0)->orderBy('id')->get();
        if ($agents->isNotEmpty()) {
            $past = Models\ReservationRoom::with('reservation')->where('status', 'checked_out')->orderByDesc('departure_date')->take($rich ? 5 : 2)->get();
            foreach ($past as $i => $st) {
                $st->reservation->update(['company_id' => $agents[$i % $agents->count()]->id, 'business_source_id' => $srcs['Travel Agent'] ?? $st->reservation->business_source_id]);
                \App\Services\CommissionService::accrue($st->fresh(['reservation.company']));
            }
            $paid = Models\AgentCommission::where('company_id', $agents->last()->id)->pluck('id')->all();
            if ($rich && $paid && ($pm = Models\PaymentMode::where('type', 'bank')->value('id') ?? Models\PaymentMode::value('id'))) {
                \App\Services\CommissionService::pay($agents->last(), $paid, $pm, $today->toDateString(), 'GIP-'.rand(100000, 999999));
            }
        }
        if ($rich) {
            $kitchen = Models\Store::where('type', 'kitchen')->value('id');
            if ($kitchen) {
                $take = \App\Services\StockTakeService::create($kitchen, $today->toDateString(), null, 'Month-end count (demo)');
                foreach ($take->lines()->with('item')->get() as $j => $l) {
                    if ($l->system_qty > 0 && $j < 5) {
                        $l->update(['counted_qty' => round($l->system_qty + [0, -1.5, 0, -0.5, 2][$j], 2), 'remarks' => [null, 'Spillage', null, 'Short delivery', 'Found in cold room'][$j]]);
                    }
                }
            }
            $arr = Models\Reservation::with('guest')->whereIn('status', ['confirmed'])->where('arrival_date', '>=', $today)->whereNull('room_block_id')->orderBy('arrival_date')->first();
            $arr?->forceFill(['prechecked_in_at' => now()->subHours(5), 'eta' => '15:30:00', 'checkin_token' => \Illuminate\Support\Str::random(40)])->save();
        }

        // ---------- Accounts ----------
        $heads = Models\AccountHead::pluck('id', 'name');
        foreach ([['Electricity', 48500, 8], ['Salaries & Wages', 285000, 5], ['Repairs & Maintenance', 6500, 3], ['Marketing & OTA Commission', 18400, 2], ['Internet & Telephone', 4200, 1]] as [$h, $amt, $d]) {
            Models\Voucher::create(['voucher_no' => Numbering::next('voucher'), 'type' => 'payment', 'date' => $today->copy()->subDays($d), 'account_head_id' => $heads[$h], 'payment_mode_id' => Models\PaymentMode::where('type', 'bank')->value('id'), 'amount' => $rich ? $amt : $amt / 3, 'narration' => $h.' for the month', 'user_id' => $user->id]);
        }

        // City-ledger bills of different ages + a part receipt (feeds the AR ageing report)
        if ($rich) {
            $clCompanies = Models\Company::orderBy('id')->take(3)->get();
            $bank = Models\PaymentMode::where('type', 'bank')->value('id');
            foreach ([[0, 12, 18650], [0, 47, 24300], [1, 8, 9800], [1, 72, 15400], [2, 118, 32750], [0, 95, 11200]] as [$ci, $age, $amt]) {
                $co = $clCompanies[$ci] ?? null;
                if (! $co) {
                    continue;
                }
                $f = FolioService::openStandalone(['type' => 'master', 'company_id' => $co->id]);
                FolioService::charge($f, 'room', 'Corporate stay – room charges', round($amt / (1 + TaxService::rateFor('room', $amt) / 100), 2)); // amounts are the invoice totals incl. taxes
                $cl = FolioService::transferToCityLedger($f, $co->id);
                $d = $today->copy()->subDays($age)->toDateString();
                Models\FolioTransaction::where('folio_id', $f->id)->update(['business_date' => $d]);
                FolioService::close($f);
                $f->forceFill(['closed_at' => Carbon::parse($d)->setTime(11, 30)])->save();
            }
            $clHead = Models\AccountHead::find(Models\AccountHead::sys('city_ledger'));
            Models\Voucher::create(['voucher_no' => Numbering::next('voucher'), 'type' => 'receipt', 'date' => $today->copy()->subDays(20), 'account_head_id' => $clHead->id, 'payment_mode_id' => $bank,
                'amount' => 20000, 'company_id' => $clCompanies[0]->id, 'party_name' => $clCompanies[0]->name, 'reference' => 'GIP-771204', 'narration' => 'Payment against city ledger', 'user_id' => $user->id]);
            if ($clCompanies[1] ?? null) {
                $clCompanies[1]->update(['credit_limit' => 20000]);
            }
        }

        // ---------- v1.5: housekeeping workflow, minibar, spa, messaging ----------
        $this->seedPhase6($hotel, $rich, $today);

        // ---------- Feedback & settings ----------
        foreach ([[5, 'Excellent stay, very courteous staff!'], [4, 'Good breakfast, room was clean.'], [3, 'Wi-Fi was slow in the evening.'], [5, 'Loved the rooftop bar.']] as [$r, $c]) {
            Models\GuestFeedback::create(['guest_name' => $guests->random()->full_name, 'rating_overall' => $r, 'rating_room' => $r, 'rating_service' => min(5, $r + 1), 'rating_food' => $r, 'rating_cleanliness' => $r, 'comments' => $c]);
        }
        foreach (['booking_engine_enabled' => '1', 'feedback_enabled' => '1', 'pos_auto_print_kot' => '1', 'booking_policy' => 'Free cancellation up to 48 hours before arrival. Later cancellations or no-shows are charged one night.', 'grc_terms' => 'Check-in time 12:00 noon. Photo ID is mandatory for all guests.', 'invoice_terms' => 'Subject to local jurisdiction. E. & O.E.',
            'qr_menu_enabled' => '1', 'loyalty_enabled' => '1', 'loyalty_spend_per_point' => '100', 'loyalty_point_value' => '1', 'loyalty_min_redeem' => '100'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        // ---------- v1.7: guest mobile portal ----------
        $this->seedPortal($hotel, $rich, $rs);
        $this->seedPayables($hotel, $rich, $today, $user);
        $this->seedRevenue($rich, $today, $user);
        $this->seedPayroll($hotel, $emps, $rich, $today, $user);
        $this->seedOnlinePayments($hotel, $rich, $today);
        $this->seedLaundry($hotel, $rich, $today);
        $this->seedAssets($hotel, $rich, $today, $user);
        $this->seedRequisitions($hotel, $rich, $today, $user);
        $this->seedLedger($rich, $today);
        $this->seedCrm($rich, $today, $user); // v2.6: guest marketing & CRM
        $this->seedContracts($rich, $today, $user); // v2.7: rate contracts & credit control
        $this->seedChannels($rich, $today, $user); // v2.8: two-way channel manager
        $this->seedPrivacy($rich, $today, $user); // v2.9: guest data privacy & compliance
        $this->seedGst($rich, $today, $user); // v3.0: GST e-invoicing, credit notes & returns
        $this->seedReputation($rich, $today, $user); // v3.1: guest feedback & online reputation
        $this->seedTables($rich, $user); // v3.2: restaurant table reservations, floor plan & waitlist
        $this->seedTransport($rich, $user); // v3.3: guest transport & fleet desk
        $this->seedForeign($rich, $user); // v3.4: Form C (FRRO) & forex desk
        $this->seedApForeign($rich, $today, $user); // v7.53: supplier bills in a foreign currency, converted at that board
        $this->seedFrontDesk($rich, $user); // v3.5: log book, wake-up calls, messages & parcels, traces, lost & found
        $this->seedInterfaces($rich, $user); // v3.7: PABX tariffs / extensions / calls, key cards, master keys
        $this->seedVouchers($rich, $user); // v3.8: gift vouchers & stored-value cards – sold, redeemed, expired, promo campaign
        $this->seedEngineering($rich, $user); // v3.9: utility meters & 60 days of readings, utility bills, licences & compliance register
        $this->seedBudget($rich, $user); // v3.6: approved FY budget, pending re-forecast, variance comments, next-year draft
        $this->seedApi($rich, $user); // v4.0: API keys, webhook endpoints, delivery history, 24 h of request logs, bookings made through the API
        $this->seedUpsell($rich, $user); // v4.1: upsell offers, 30 days of orders & presentations, in-house / upcoming orders, pending requests
        $this->seedSecurity($rich, $user); // v4.2: watch list, 10 days of visitors + on-site / awaiting / expected, gate passes in every state, incident register
        $this->seedStatutory($rich, $today); // v4.5: SSNIT / Tier 2 / PAYE – July paid on time, August Tier 2 still owed (overdue)
        $this->seedGhanaTwo($rich, $today); // v4.4: WHT on supplier payments (August filed), restaurant bills stamped by E-VAT with one waiting to retry
        $this->seedBankRec($today); // v4.6: bank reconciled for last month, this month's bank & MoMo statements in progress
        $this->seedPetty($today); // v4.7: front office & kitchen petty cash floats, vouchers, IOUs, counts, top-ups; staff expense claims in every state
        $this->seedProcurement($today); // v4.8: price lists, RFQs in every state with quotes, split award, GRNs with rejections, POs at each approval level
        $this->seedWorkforce($rich, $today); // v4.9: shift templates, 3 weeks of rosters, a week of time punches with exceptions, swaps, tips and the August service charge distribution
        $this->seedClub($rich, $today); // v5.1: health club plans, members (frozen / expired / cancelled / arrears / family / corporate), visits, classes, day passes
        $this->seedFoodSafety($rich, $today); // v5.0: allergens, HACCP equipment & a week of temperature logs, checklists, waste, handler certificates, prep labels
        $this->seedLeases($rich, $today); // v5.2: apartment units, corporate / expat / residential / commercial leases, arrears, notice, move-out settlement, draft
        $this->seedIncomeAudit($rich, $today); // v5.3: audit days (closed / signed / in audit), exceptions, authorisations, room count
        $this->seedReportAutomation($rich); // v5.4: saved views, report schedules + deliveries, KPI alerts + events
        $this->seedPackages($rich); // v5.5: package catalogue, rate-plan inclusions, 9 nights of breakfast entitlements used / expired, add-ons, today's meal desk
        $this->seedTreasury($rich); // v5.7: Ecobank collections account, weekly cash & cheque bankings (one short, one in transit), card sweeps, cheques received / bounced / post-dated, cheque books, term loan, forecast lines
        $this->seedOwner($rich); // v5.8: management agreement, FF&E reserve account & ledger, July fees accrued (before the close), three capex projects
        $this->seedClose($rich); // v5.6: 16 closed months (one reopened), FY 2025-26 year end, schedules & recurring journals, last month's close in progress
        $this->seedOwnerPacks($rich); // v5.8: July owner pack issued, an owner query answered; August fees left to accrue
        $this->seedConcierge($rich); // v5.9: tour operators, activities, local guide; bookings in every state, a full departure + waitlist, a vendor-cancelled session, a settlement
        $this->seedLearning($rich); // v6.0: training matrix, courses, sessions (one awaiting results), refreshers booked, SOP library with versions and sign-offs
        $this->seedSales($rich); // v6.1: account tiers & prospects, contacts, pipeline in every stage, sales diary (some overdue), targets, marketing calendar / budget / collateral, account production history
        $this->seedDigital($rich); // v6.2: social / listing / ad channels with 6 months of figures, paid campaigns with daily results, tracked links with clicks & credited bookings, posts in every state, influencer collaborations
        $this->seedBusinessDev($rich); // v6.3: competitive set with rate shops & benchmarks, partnerships in every state with production tracking, initiatives across the stage gate with milestones and results
        $this->seedCro($rich); // v6.4: reservations enquiry log – booked, denials, regrets (with competitors), open follow-ups & contacts
        $this->seedQa($rich); // v6.5: brand standards library, 7 checklists, 22 scored audits over 5 months (critical & repeat findings), one in progress, one late, corrective actions in every state
        $this->seedHse($rich); // v6.7: fire equipment register with 3 months of checks (one defective, overdue, expired), drills per shift (night overdue), wardens (night first-aid gap), 13 risk assessments, permits in every state, accident register (LTI 18 days ago, an unnotified kitchen fire)
        $this->seedFb($rich); // v6.8: bar & kitchen stock, recipes for every dish with approved cards, yield tests, 28 days of restaurant & bar bills (officers' checks), staff meals, a posted count with shrinkage, cost snapshots with creep – before seedParking, whose LedgerService::sync(true) posts the back-dated stock days
        $this->seedParking($rich); // v6.6: zones, permits (staff / contractor expiring / expired / revoked), 14 days of paid visitor tickets, in-house guest cars (valet nights on the folio), a valet queue, an overstay, a banned vehicle
        $this->seedTalent($rich); // v6.9: establishment & line managers, vacancies in every state with a full Guest Service Agent pipeline (scorecards, issued & above-range offers), Kofi hired & onboarding, Abena's probation / statement overdue, mid-year appraisals at every stage, warnings (live & expired), an overdue query reply, a confidential grievance, Lata working notice and two former employees
        $this->seedEsg($rich); // v7.0: employee gender / nationality, local suppliers, 12 weeks of waste (diversion improving), F-gas log with a leaking cold room, outsourced laundry, targets, initiatives at every stage, community giving, a partly-met certification checklist, policies (one overdue review) and two green stays
        $this->seedIptv($rich); // v7.1: TVs in every room + lobby screens (offline / disabled / pairing), 32-channel multicast line-up with guide, 12 movies & today's rentals (one refunded), welcome screens & pages, TV messages (cleared fire drill, live broadcasts, room message awaiting OK), TV orders / requests / wake-up and 14 days of usage
        $this->seedKiosk($rich); // v7.2: three lobby kiosks (one out of service), 13 days of kiosk visits drawn from real check-ins / check-outs / walk-ins, abandoned visits, resolved desk hand-overs and one guest waiting for help now
        $this->seedInbox($rich); // v7.3: quick replies & routing rules; live inbox from in-house stays (VIP transfer, overdue AC fault, unknown rates enquiry, rule answers, snoozed, e-mail), a week of rated chats and a check-out broadcast
        $this->seedPartners($rich); // v7.4: partner portal on for Infosys (corporate), Thomas Cook (agent, on credit hold) and Yatra (agent, guest pays); bookers / finance / invited / locked logins, portal bookings (confirmed, held for approval over threshold & credit hold, approved, declined, cancelled), a change request, a group request and two weeks of sign-in history
        $this->seedOrganisers($rich); // v7.5: organiser portal on for Sahara Events (summit definite + launch released after an approved cancellation) and the Mensah–Owusu wedding (BEO signed); admin / planner / finance / invited / disabled logins, guest lists, an online deposit and two weeks of sign-in history
        Auth::forgetUser();
    }


    /** v7.5 event organiser portal demo. Logins use the password Organiser2026. */
    protected function seedOrganisers(bool $rich): void
    {
        $hid = \App\Support\Tenant::id();
        $fom = Models\User::where('hotel_id', $hid)->where('username', 'fom')->first() ?? Models\User::where('hotel_id', $hid)->where('username', 'admin')->first();
        if (! $fom) {
            return;
        }
        $OS = \App\Services\OrganiserService::class;
        $S = \App\Services\BanquetSalesService::class;
        $real = now()->copy();
        $sahara = Models\Company::firstOrCreate(['name' => 'Sahara Events Ltd'], ['type' => 'corporate', 'industry' => 'events', 'contact_person' => 'Ama Serwaa',
            'phone' => '+233 24 555 0199', 'email' => 'ama.serwaa@saharaevents.com', 'address' => '14 Liberation Road, Accra', 'city' => 'Accra',
            'credit_limit' => 200000, 'tax_number' => 'C0712345678', 'is_active' => true, 'direct_billing' => true]);
        $hash = \Illuminate\Support\Facades\Hash::make('Organiser2026');
        $mk = function (?Models\Company $c, string $name, string $email, string $role, ?int $daysAgo, array $extra = []) use ($hash, $real, $fom) {
            $u = new Models\OrganiserUser(['company_id' => $c?->id, 'name' => $name, 'email' => $email, 'role' => $role, 'is_active' => true, 'created_by' => $fom->id] + $extra);
            $u->save();
            if ($daysAgo !== null) {
                $u->forceFill(['password' => $hash, 'password_changed_at' => $real->copy()->subDays(30 + $daysAgo), 'last_login_at' => $real->copy()->subDays($daysAgo)->subHours(2), 'last_login_ip' => '102.176.'.(20 + $u->id).'.'.(50 + $u->id)])->save();
            }

            return $u;
        };
        $ama = $mk($sahara, 'Ama Serwaa', 'ama.serwaa@saharaevents.com', 'admin', 0, ['phone' => '+233 24 555 0199']);
        $yaw = $mk($sahara, 'Yaw Osei', 'yaw.osei@saharaevents.com', 'planner', 1);
        $efua = $mk($sahara, 'Efua Adu', 'efua.adu@saharaevents.com', 'finance', 2);
        $kofi = $mk($sahara, 'Kofi Antwi', 'kofi.antwi@saharaevents.com', 'planner', null);
        $kofi->forceFill(['invite_token' => hash('sha256', 'demo-org-invite-'.$kofi->id.'-'.\Illuminate\Support\Str::random(20)), 'invite_expires_at' => $real->copy()->addDays(5)])->save();
        $old = $mk($sahara, 'Nana Yaa', 'nana.yaa@saharaevents.com', 'planner', 90);
        $old->forceFill(['is_active' => false])->save();
        if (! $rich) {
            return;
        }
        $wedding = Models\BanquetEvent::where('title', 'Mensah–Owusu Wedding')->first();
        if (! $wedding) {
            return;
        }
        $sales = Models\User::where('hotel_id', $hid)->where('username', 'banquet')->first() ?? $fom;
        Auth::setUser($sales);
        $H = Models\BanquetHall::pluck('id', 'name');
        $P = Models\BanquetPackage::pluck('id', 'name');
        $B = bdate()->copy();
        $day = fn (int $o) => $B->copy()->addDays($o)->toDateString();
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');

        // Sahara's summit: definite, deposit in, BEO published and waiting for Ama's signature
        $summit = $S::save(['title' => 'Ghana Energy Summit 2026', 'event_type' => 'conference', 'company_id' => $sahara->id, 'contact_name' => 'Ama Serwaa',
            'contact_phone' => '+233 24 555 0199', 'contact_email' => 'ama.serwaa@saharaevents.com', 'source' => 'repeat', 'deposit_due' => 60000, 'deposit_due_date' => $day(10),
            'status' => 'tentative', 'option_date' => $day(5), 'sales_user_id' => $sales->id,
            'requirements' => "Registration desk in the foyer from 07:30.\nLive stream from the ballroom.\nPress row at the back of the plenary.",
            'functions' => [
                ['name' => 'Summit plenary', 'banquet_hall_id' => $H['Grand Ballroom'], 'date' => $day(35), 'start_time' => '09:00', 'end_time' => '17:30', 'setup_style' => 'theatre', 'expected_pax' => 300, 'banquet_package_id' => $P['Working Lunch'], 'items' => [], 'notes' => 'Simultaneous interpretation booths at the back.'],
                ['name' => 'Summit gala night', 'banquet_hall_id' => $H['Poolside Lawn'], 'date' => $day(36), 'start_time' => '19:00', 'end_time' => '23:00', 'setup_style' => 'banquet', 'expected_pax' => 250, 'banquet_package_id' => $P['Royal Non-Veg Buffet'], 'items' => []]]]);
        $S::takeDeposit($summit, 60000, $bank, 'GIP-SUM-1');
        $S::confirm($summit->fresh());
        $summit->forceFill(['portal_enabled' => true])->save();
        $OS::publishBeo($summit->fresh(), $fom);

        // Sahara's launch: tentative, then released when the FOM approves the organiser's cancellation
        $launch = $S::save(['title' => 'Nova Bank Product Launch', 'event_type' => 'launch', 'company_id' => $sahara->id, 'contact_name' => 'Yaw Osei',
            'contact_phone' => '+233 20 555 0117', 'contact_email' => 'yaw.osei@saharaevents.com', 'source' => 'email', 'deposit_due' => 40000, 'deposit_due_date' => $day(12),
            'status' => 'tentative', 'option_date' => $day(6), 'sales_user_id' => $sales->id,
            'functions' => [['name' => 'Nova launch party', 'banquet_hall_id' => $H['Emerald Hall'], 'date' => $day(42), 'start_time' => '18:00', 'end_time' => '22:00', 'setup_style' => 'cocktail', 'expected_pax' => 150, 'banquet_package_id' => $P['Coffee Break / Hi-Tea'], 'items' => []]]]);
        $launch->forceFill(['portal_enabled' => true])->save();

        // the wedding is already definite with deposits in: portal on, BEO published, Akosua signs it
        $wedding->forceFill(['portal_enabled' => true])->save();
        $wedding->functions()->where('name', 'Wedding reception')->update(['guaranteed_pax' => 280]);
        $akosua = $mk(null, 'Akosua Mensah', 'akosua.mensah@example.com', 'admin', 0, ['banquet_event_id' => $wedding->id, 'phone' => '+233 24 555 0142']);
        $OS::publishBeo($wedding->fresh(), $fom);
        $OS::approveBeo($akosua->load('company'), $wedding->fresh(), 'Akosua Mensah');

        // two weeks of sign-in history (+ a lock-out on Yaw's login yesterday)
        foreach ([[$ama, [0, 1, 3, 6, 9, 13]], [$yaw, [1, 4, 8, 12]], [$efua, [2, 9]], [$akosua, [0, 2, 5, 7, 11]]] as [$u, $days]) {
            foreach ($days as $d) {
                Models\OrganiserAudit::create(['company_id' => $u->company_id, 'organiser_user_id' => $u->id, 'action' => 'login', 'detail' => 'Signed in', 'ip' => $u->last_login_ip,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', 'created_at' => $real->copy()->subDays($d)->setTime(9 + ($u->id + $d) % 8, ($u->id * 5 + $d * 13) % 60)]);
            }
        }
        foreach ([1, 2, 3, 4, 5] as $n) {
            Models\OrganiserAudit::create(['company_id' => $sahara->id, 'organiser_user_id' => $yaw->id, 'action' => $n === 5 ? 'locked' : 'login_failed',
                'detail' => $n === 5 ? '5 failed passwords – locked for 15 minutes' : "Wrong password ({$n}/5)", 'ip' => '41.66.201.17', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14)', 'created_at' => $real->copy()->subDay()->setTime(20, 5 + $n)]);
        }
        Models\OrganiserAudit::create(['company_id' => null, 'organiser_user_id' => null, 'action' => 'login_failed', 'detail' => 'Unknown e-mail events@partyshop.biz', 'ip' => '185.220.101.9', 'created_at' => $real->copy()->subDays(3)->setTime(2, 44)]);
        Models\OrganiserAudit::create(['company_id' => $sahara->id, 'organiser_user_id' => $ama->id, 'action' => 'user', 'detail' => 'Invited Kofi Antwi <kofi.antwi@saharaevents.com> as Event planner', 'ip' => $ama->last_login_ip, 'created_at' => $real->copy()->subDays(2)->setTime(11, 20)]);

        // requests: a declined date change, then the still-open menu change; a pending quote, an approved cancellation and a completed change
        $q5 = $OS::change($yaw->load('company'), $summit->fresh(), ['date' => $day(28), 'details' => 'Can we move the plenary a week earlier to 29 October? Our keynote speaker is only free then.']);
        $q5->forceFill(['created_at' => $real->copy()->subDays(4), 'updated_at' => $real->copy()->subDays(4)])->save();
        $OS::decline($q5, $fom, 'The Grand Ballroom is already booked on 29 October – the plenary stays on 5 November as agreed.');
        $q5->forceFill(['decided_at' => $real->copy()->subDays(3)])->save();
        $q1 = $OS::change($yaw->load('company'), $summit->fresh(), ['package' => 'Upgrade the gala to the Premium Wedding Buffet for the head table (30 guests)',
            'details' => 'Our board members sit at the head table – please upgrade their 30 gala covers to the Premium Wedding Buffet and requote. The other 220 stay on the Royal buffet.']);
        $q1->forceFill(['created_at' => $real->copy()->subDays(2), 'updated_at' => $real->copy()->subDays(2)])->save();
        $q2 = $OS::quoteRequest($ama->load('company'), ['title' => 'Sahara Year-End Party', 'event_type' => 'party', 'event_date' => $day(70), 'pax' => 200,
            'details' => 'Poolside if the harmattan allows, otherwise the Grand Ballroom. Live band, cocktail bar and fireworks at midnight.']);
        $q2->forceFill(['created_at' => $real->copy()->subHours(10), 'updated_at' => $real->copy()->subHours(10)])->save();
        $q3 = $OS::change($yaw->load('company'), $launch->fresh(), ['kind' => 'cancel', 'details' => 'Our sponsor pulled out – we must cancel the launch. Please confirm and release the Emerald Hall.']);
        $q3->forceFill(['created_at' => $real->copy()->subDays(6), 'updated_at' => $real->copy()->subDays(6)])->save();
        $OS::approve($q3, $fom, 'Released – tentative holds go free of charge. Sorry to lose the launch, Yaw.');
        $q3->forceFill(['decided_at' => $real->copy()->subDays(5)])->save();
        $q4 = $OS::change($akosua->load('company'), $wedding->fresh(), ['details' => 'Please add six more floral centrepieces for the top table and the parents’ tables. Charge them to the event.']);
        $q4->forceFill(['created_at' => $real->copy()->subDays(8), 'updated_at' => $real->copy()->subDays(8)])->save();
        $OS::complete($q4, $fom, 'Done – six centrepieces added to the reception at GH₵ 850 each.');
        $q4->forceFill(['decided_at' => $real->copy()->subDays(7)])->save();

        // guest lists
        $guests = [['Ama Serwaa', 'ama.serwaa@saharaevents.com', '', 'vip', null, 'Host'], ['Yaw Osei', 'yaw.osei@saharaevents.com', '', 'crew', 'Vegetarian', 'Show caller'],
            ['Dr Efua Adu', 'efua.adu@saharaevents.com', '', 'speaker', null, 'Keynote'], ['Nana Yaa', '', '+233 20 555 0101', 'vip', 'No pork', null],
            ['Kwame Asante', '', '', 'delegate', 'Vegetarian', null], ['Abena Owusu', '', '', 'delegate', null, null], ['Kofi Boateng', '', '', 'delegate', 'Gluten-free', null],
            ['Efua Adu', '', '', 'companion', null, null]];
        foreach ($guests as [$name, $email, $phone, $tag, $diet, $note]) {
            $OS::attendeeAdd($yaw->load('company'), $summit->fresh(), ['name' => $name, 'email' => $email, 'phone' => $phone, 'tag' => $tag, 'dietary' => $diet, 'notes' => $note]);
        }
        $wed = [['Akosua Mensah', 'akosua.mensah@example.com', '', 'vip', null, 'Bride'], ['Kwabena Owusu', '', '+233 24 555 0143', 'vip', null, 'Groom'],
            ['Mrs Mensah (mother of the bride)', '', '', 'vip', 'No pork', 'Top table'], ['Mr Owusu (father of the groom)', '', '', 'vip', null, 'Top table'],
            ['Ama Serwaa', '', '', 'companion', 'Vegetarian', null], ['Efua Adu', '', '', 'delegate', 'Vegetarian', null], ['Kofi Boateng', '', '', 'delegate', 'No pork', null],
            ['Abena Owusu', '', '', 'delegate', null, null], ['Yaw Osei', '', '', 'delegate', 'Nut allergy – severe', 'Flag to the kitchen'], ['Nana Yaa', '', '', 'delegate', 'Vegetarian', null],
            ['Kwame Asante', '', '', 'companion', null, null], ['Photo Studio (2 pax)', '', '', 'crew', null, 'Vendor meals']];
        foreach ($wed as [$name, $email, $phone, $tag, $diet, $note]) {
            $OS::attendeeAdd($akosua->load('company'), $wedding->fresh(), ['name' => $name, 'email' => $email, 'phone' => $phone, 'tag' => $tag, 'dietary' => $diet, 'notes' => $note]);
        }
        Models\OrganiserAttendee::orderBy('id')->limit(10)->update(['created_at' => $real->copy()->subDays(9), 'updated_at' => $real->copy()->subDays(9)]);

        // Efua pays GH₵ 5,000 of the summit balance online
        $p = $OS::pay($efua->load('company'), $summit->fresh(), 5000);
        \App\Services\OnlinePaymentService::complete($p, ['state' => 'paid', 'amount' => 5000, 'currency' => $p->currency, 'payment_id' => 'demo_summit_1', 'channel' => 'mobile_money'], 'webhook');
    }

    /** v7.4 corporate & travel-agent portal demo. Logins use the password Partner2026. */
    protected function seedPartners(bool $rich): void
    {
        $hid = \App\Support\Tenant::id();
        $fom = Models\User::where('hotel_id', $hid)->where('username', 'fom')->first() ?? Models\User::where('hotel_id', $hid)->where('username', 'admin')->first();
        $infy = Models\Company::where('name', 'Infosys Technologies')->first();
        $tc = Models\Company::where('name', 'Thomas Cook India')->first();
        $yatra = Models\Company::where('name', 'Yatra Travels')->first();
        if (! $fom || ! $infy || ! $tc || ! $yatra) {
            return;
        }
        $PS = \App\Services\PartnerService::class;
        $real = now()->copy();
        $infy->forceFill(['portal_enabled' => true, 'portal_approval_over' => 40000, 'portal_max_rooms' => 5, 'portal_guest_pay' => true])->save();
        $tc->forceFill(['portal_enabled' => true, 'portal_max_rooms' => 8, 'portal_guest_pay' => false])->save();
        $yatra->forceFill(['portal_enabled' => true, 'portal_max_rooms' => 4, 'portal_guest_pay' => true])->save();
        $hash = \Illuminate\Support\Facades\Hash::make('Partner2026');
        $mk = function (Models\Company $c, string $name, string $email, string $role, ?int $daysAgo, array $extra = []) use ($hash, $real, $fom) {
            $u = new Models\PartnerUser(['company_id' => $c->id, 'name' => $name, 'email' => $email, 'role' => $role, 'is_active' => true, 'created_by' => $fom->id] + $extra);
            $u->save();
            if ($daysAgo !== null) {
                $u->forceFill(['password' => $hash, 'password_changed_at' => $real->copy()->subDays(30 + $daysAgo), 'last_login_at' => $real->copy()->subDays($daysAgo)->subHours(2), 'last_login_ip' => '102.176.'.(10 + $u->id).'.'.(40 + $u->id)])->save();
            }

            return $u;
        };
        $anita = $mk($infy, 'Anita Rao', 'travel.desk@infosys.com', 'admin', 0, ['phone' => '+233 24 555 0101']);
        $rahul = $mk($infy, 'Rahul Iyer', 'rahul.iyer@infosys.com', 'booker', 1);
        $kofi = $mk($infy, 'Kofi Boateng', 'accounts.payable@infosys.com', 'finance', 3);
        $priya = $mk($infy, 'Priya Nair', 'priya.nair@infosys.com', 'booker', null);
        $priya->forceFill(['invite_token' => hash('sha256', 'demo-invite-'.$priya->id.'-'.\Illuminate\Support\Str::random(20)), 'invite_expires_at' => $real->copy()->addDays(5)])->save();
        $karan = $mk($tc, 'Karan Shah', 'karan.shah@thomascook.in', 'admin', 2);
        $pooja = $mk($yatra, 'Pooja Rao', 'bookings@yatra.com', 'admin', 0);
        $imran = $mk($yatra, 'Imran Sheikh', 'imran.sheikh@yatra.com', 'booker', 6);
        $old = $mk($infy, 'Suresh Pillai', 'suresh.pillai@infosys.com', 'booker', 60);
        $old->forceFill(['is_active' => false])->save();
        if (! $rich) {
            return;
        }
        $DB = \Illuminate\Support\Facades\DB::class;
        $chUpToDate = Models\ChannelConnection::where('pushed_upto_id', '>=', (int) $DB::table('channel_dirty')->where('hotel_id', $hid)->max('id'))->pluck('id');

        // two weeks of sign-in history (+ a lock-out on Imran's login yesterday)
        foreach ([[$anita, [0, 1, 2, 4, 7, 9, 13]], [$rahul, [1, 3, 5, 8, 11]], [$kofi, [3, 10]], [$karan, [2, 6, 12]], [$pooja, [0, 2, 5, 9]], [$imran, [6]]] as [$u, $days]) {
            foreach ($days as $d) {
                Models\PartnerAudit::create(['company_id' => $u->company_id, 'partner_user_id' => $u->id, 'action' => 'login', 'detail' => 'Signed in', 'ip' => $u->last_login_ip,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', 'created_at' => $real->copy()->subDays($d)->setTime(8 + ($u->id + $d) % 9, ($u->id * 7 + $d * 11) % 60)]);
            }
        }
        foreach ([1, 2, 3, 4, 5] as $n) {
            Models\PartnerAudit::create(['company_id' => $yatra->id, 'partner_user_id' => $imran->id, 'action' => $n === 5 ? 'locked' : 'login_failed',
                'detail' => $n === 5 ? '5 failed passwords – locked for 15 minutes' : "Wrong password ({$n}/5)", 'ip' => '41.66.201.17', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14)', 'created_at' => $real->copy()->subDay()->setTime(21, 10 + $n)]);
        }
        Models\PartnerAudit::create(['company_id' => null, 'partner_user_id' => null, 'action' => 'login_failed', 'detail' => 'Unknown e-mail admin@hotelbookings.biz', 'ip' => '185.220.101.4', 'created_at' => $real->copy()->subDays(2)->setTime(3, 12)]);
        Models\PartnerAudit::create(['company_id' => $infy->id, 'partner_user_id' => $anita->id, 'action' => 'user', 'detail' => 'Invited Priya Nair <priya.nair@infosys.com> as Booker', 'ip' => $anita->last_login_ip, 'created_at' => $real->copy()->subDays(2)->setTime(10, 5)]);

        $B = bdate()->copy();
        $book = fn (Models\PartnerUser $u, int $in, int $nights, string $type, int $rooms, array $d) => $PS::book($u->load('company'), $d + [
            'room_type_id' => Models\RoomType::where('code', $type)->value('id'), 'arrival' => $B->copy()->addDays($in)->toDateString(), 'departure' => $B->copy()->addDays($in + $nights)->toDateString(),
            'rooms' => $rooms, 'adults' => $rooms * ($d['per'] ?? 1)]);

        // Infosys: confirmed on account (+ a change request), traveller-pays, one held over the GH₵ 40,000 threshold, one approved by the FOM
        [$r1] = $book($rahul, 31, 3, 'DLX', 2, ['first_name' => 'Vikram', 'last_name' => 'Desai', 'email' => 'vikram.desai@infosys.com', 'phone' => '+91 98450 11223', 'bill_to' => 'company', 'partner_ref' => 'PO-INF-88213', 'special_request' => 'Two rooms on the same floor, late arrival ~23:00']);
        $book($anita, 44, 2, 'EXE', 1, ['first_name' => 'Meera', 'last_name' => 'Krishnan', 'email' => 'meera.k@infosys.com', 'bill_to' => 'guest', 'partner_ref' => 'CC-ACC-114', 'per' => 2]);
        $book($rahul, 52, 4, 'DLX', 3, ['first_name' => 'Sanjay', 'last_name' => 'Kulkarni', 'email' => 'sanjay.kulkarni@infosys.com', 'bill_to' => 'company', 'partner_ref' => 'PO-INF-88290', 'special_request' => 'Delivery team of three – workshop at Accra Digital Centre']);
        [$r4, $q4] = $book($anita, 34, 4, 'EXE', 2, ['first_name' => 'Lakshmi', 'last_name' => 'Venkat', 'email' => 'lakshmi.v@infosys.com', 'bill_to' => 'company', 'partner_ref' => 'PO-INF-88301']);
        if ($q4) {
            $PS::approve($q4, $fom, 'Approved – leadership visit, within the annual contract volume');
        }
        $PS::amend($rahul->load('company'), $r1, ['departure' => $B->copy()->addDays(35)->toDateString(), 'details' => 'Mr Desai\'s client workshop was extended – please add one night (check-out '.$B->copy()->addDays(35)->format('d M').') for both rooms, same PO.']);

        // Thomas Cook (on credit hold): one held for the hotel, one declined
        $book($karan, 23, 4, 'STD', 2, ['first_name' => 'Ama', 'last_name' => 'Owusu', 'email' => 'ama.owusu@example.com', 'bill_to' => 'company', 'partner_ref' => 'TC/ACC/24/0912', 'per' => 2]);
        [, $q6] = $book($karan, 47, 2, 'EXE', 1, ['first_name' => 'Daniel', 'last_name' => 'Mensah', 'bill_to' => 'company', 'partner_ref' => 'TC/ACC/24/0877']);
        if ($q6) {
            $PS::decline($q6, $fom, 'Your account is on credit hold until the overdue August invoices are settled – please book this traveller as "pays at hotel" via reservations.');
        }

        // Yatra (no direct billing, guests pay): confirmed, one cancelled free of charge, a group request
        $book($pooja, 16, 2, 'DLX', 1, ['first_name' => 'Kwame', 'last_name' => 'Asamoah', 'email' => 'kwame.asamoah@example.com', 'phone' => '+233 20 811 4455', 'bill_to' => 'guest', 'partner_ref' => 'YT-77120', 'per' => 2]);
        [$r8] = $book($pooja, 15, 3, 'STD', 1, ['first_name' => 'Efua', 'last_name' => 'Mensah', 'bill_to' => 'guest', 'partner_ref' => 'YT-77134']);
        $PS::cancel($pooja->load('company'), $r8, 'Traveller changed plans');
        $PS::groupRequest($pooja->load('company'), ['arrival' => $B->copy()->addDays(50)->toDateString(), 'departure' => $B->copy()->addDays(53)->toDateString(), 'rooms' => 12, 'adults' => 22, 'name' => 'Yatra MICE – Pharma dealers Ghana',
            'details' => '12 rooms (10 Deluxe twin, 2 Executive), half-board, a meeting room for 25 on day 2 and an airport transfer for the group.']);

        // portal bookings change availability after the channel seed ran: connections that were up to date stay up to date
        Models\ChannelConnection::whereIn('id', $chUpToDate)->update(['pushed_upto_id' => (int) $DB::table('channel_dirty')->where('hotel_id', $hid)->max('id')]);
    }

    /**
     * v3.4 foreign-guest demo: Form C for every foreign stay of the last three weeks – departed guests filed and their departures
     * reported (one filed late with a reason, the latest departure still to report); in house: one filed, one overdue, one complete
     * and due (with a companion sharing the room) and one incomplete (new visa not yet scanned). Forex: two weeks of daily rates
     * (today's CHF carried by the night audit), cash encashments from foreign guests (one voided), a surrender to the bank five
     * days ago that leaves a JPY lot held too long, and a bill credit in US dollars through the service.
     */
    protected function seedForeign(bool $rich, Models\User $user): void
    {
        $FC = \App\Services\FormCService::class;
        $FX = \App\Services\ForexService::class;
        $FC::ensureDefaults();
        $FX::ensureDefaults();
        if (! $rich) {
            return;
        }
        $prevUser = auth()->user();
        $desk = Models\User::where('username', 'frontdesk')->where('hotel_id', hotel()->id)->first() ?? $user;
        $acct = Models\User::where('username', 'accounts')->where('hotel_id', hotel()->id)->first() ?? $user;
        auth()->setUser($desk);
        $now = now();
        $bd = bdate()->copy()->startOfDay();

        // ── scans on the guest profiles ──
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $doc = function (Models\Guest $g, string $type) use ($png, $desk) {
            $path = 'guest-docs/'.$g->hotel_id.'/'.$g->id.'/'.\Illuminate\Support\Str::random(32).'.png';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $png);
            $g->documents()->create(['type' => $type, 'path' => $path, 'original_name' => $type.'.png', 'mime' => 'image/png', 'size' => strlen($png), 'uploaded_by' => $desk->id]);
        };
        $place = ['ghana' => ['Accra', 'High Commission of India, Accra', 'Accra, Ghana'], 'india' => ['Mumbai', 'e-Visa (online)', 'Mumbai, India'], 'united kingdom' => ['London', 'VFS London (e-Visa)', 'London, UK'],
            'japan' => ['Tokyo', 'Embassy of India, Tokyo', 'Tokyo, Japan'], 'germany' => ['Frankfurt', 'e-Visa (online)', 'Frankfurt, Germany'],
            'spain' => ['Madrid', 'e-Visa (online)', 'Madrid, Spain']];
        $ports = ['Bengaluru (BLR) airport', 'Mumbai (BOM) airport', 'Delhi (IGI) airport', 'Chennai (MAA) airport'];
        $next = ['Mysuru, Karnataka', 'Goa', 'Chennai, Tamil Nadu', 'Kochi, Kerala', 'Mumbai, Maharashtra', 'Return home'];
        $details = function (Models\Guest $g, Models\ReservationRoom $st, int $i) use ($place, $ports, $next) {
            [$city, $vplace, $from] = $place[mb_strtolower((string) $g->nationality)] ?? ['Capital city', 'e-Visa (online)', $g->country ?: 'Abroad'];
            $in = $st->checked_in_at->copy()->startOfDay();
            $h = abs(crc32($g->last_name.$g->first_name));

            return ['gender' => ['M', 'F'][$h % 2], 'dob' => $g->dob?->toDateString() ?? $in->copy()->subYears(28 + $h % 30)->subDays($h % 300)->toDateString(),
                'passport_place' => $city, 'passport_issued_on' => $in->copy()->subYears(1 + $h % 6)->subDays($h % 200)->toDateString(),
                'passport_expires_on' => $in->copy()->addYears(3 + $h % 5)->addDays($h % 200)->toDateString(),
                'visa_no' => sprintf('%s%07d', ['EV', 'VT', 'EB'][$h % 3], $h % 10000000), 'visa_type' => $i % 4 === 3 ? 'e_business' : 'e_tourist', 'visa_place' => $vplace,
                'visa_issued_on' => $in->copy()->subDays(20 + $h % 40)->toDateString(), 'visa_expires_on' => $g->visa_expiry?->toDateString() ?? $in->copy()->addDays(60)->toDateString(),
                'arrived_india_on' => $in->copy()->subDays($i % 3)->toDateString(), 'port_of_arrival' => $ports[$h % count($ports)],
                'arrived_from' => $i % 3 ? 'Bengaluru airport' : $from, 'next_destination' => $next[($h + $i) % count($next)], 'purpose' => $i % 4 === 3 ? 'business' : 'tourism'];
        };

        // ── Form C for foreign stays of the last 3 weeks ──
        $stays = Models\ReservationRoom::with('reservation.guest', 'guest', 'room')->whereIn('status', ['checked_in', 'checked_out'])
            ->where('checked_in_at', '>=', $bd->copy()->subDays(21))->orderBy('checked_in_at')->orderBy('id')->get()
            ->filter(fn ($st) => $FC::isForeign($FC::stayGuest($st)))->values();
        $scanned = [];
        $inHouse = $stays->where('status', 'checked_in')->values();
        $lastOut = $stays->where('status', 'checked_out')->sortBy('checked_out_at')->last();
        $lateOne = $stays->where('status', 'checked_out')->values()->get(3);
        $i = 0;
        foreach ($stays as $st) {
            $g = $FC::stayGuest($st);
            $f = $FC::createForStay($st, $g);
            $in = $st->checked_in_at;
            $x = $details($g, $st, $i++);
            $name = $g->first_name.' '.$g->last_name;
            $role = $st->status === 'checked_out' ? 'out' : match ($name) {
                'Sneha Kapoor' => 'filed', 'Hiroshi Tanaka' => 'overdue', 'Rahul Verma' => 'incomplete', default => 'due',
            };
            if ($role === 'incomplete') { // back with a new visa – not captured / scanned yet
                $x = array_diff_key($x, array_flip(['visa_no', 'visa_place', 'visa_issued_on', 'arrived_india_on', 'next_destination']));
            }
            $f->forceFill($x + ['created_at' => $in, 'updated_at' => $in])->save();
            if (! isset($scanned[$g->id])) {
                $doc($g, 'passport');
                if ($role !== 'incomplete') {
                    $doc($g, 'visa');
                }
                $scanned[$g->id] = true;
            }
            $ref = fn (Carbon $at, int $n) => 'KA'.$at->format('ymd').sprintf('%06d', (crc32($f->form_no) + $n) % 1000000);
            if ($role === 'out' || $role === 'filed') {
                $late = $lateOne && $st->id === $lateOne->id;
                $sub = $in->copy()->addHours($late ? 31 : 2 + $i % 7)->addMinutes(($i * 13) % 60);
                $upd = ['status' => 'submitted', 'submitted_at' => $sub, 'submitted_by' => $desk->id, 'frro_ref' => $ref($sub, 1),
                    'late_reason' => $late ? 'FRRO portal was down overnight (maintenance) – filed as soon as it came back' : null, 'updated_at' => $sub];
                if ($role === 'out' && ! ($lastOut && $st->id === $lastOut->id)) {
                    $dep = $st->checked_out_at->copy()->addHours(2 + $i % 5);
                    $upd += ['departure_submitted_at' => $dep, 'departure_by' => $desk->id, 'departure_ref' => $i % 2 ? $ref($dep, 7) : null, 'updated_at' => $dep];
                }
                if ($role === 'out') {
                    $upd['alert_due_at'] = null;
                }
                $f->forceFill($upd)->save();
            }
            if ($role === 'overdue') {
                $f->forceFill(['alert_due_at' => $f->deadline_at->copy()->subHours(5), 'alert_overdue_at' => $f->deadline_at->copy()->addMinutes(20)])->save();
            }
            if ($role === 'due' && $name === 'Emily Clarke') { // travelling with her husband – a second Form C, no profile of his own
                $FC::createForStay($st, null, true, ['given_name' => 'James', 'surname' => 'Clarke', 'nationality' => $g->nationality, 'gender' => 'M',
                    'dob' => $in->copy()->subYears(41)->subDays(77)->toDateString(), 'passport_no' => '5398827'.($g->id % 10).'1', 'passport_place' => 'London',
                    'passport_issued_on' => $in->copy()->subYears(4)->toDateString(), 'passport_expires_on' => $in->copy()->addYears(6)->toDateString(),
                    'visa_no' => 'EV'.sprintf('%07d', 4410000 + $g->id), 'visa_type' => 'e_tourist', 'visa_place' => 'VFS London (e-Visa)',
                    'visa_issued_on' => $in->copy()->subDays(30)->toDateString(), 'visa_expires_on' => $x['visa_expires_on'], 'arrived_india_on' => $x['arrived_india_on'],
                    'port_of_arrival' => $x['port_of_arrival'], 'arrived_from' => $x['arrived_from'], 'next_destination' => $x['next_destination'], 'purpose' => 'tourism',
                    'address_abroad' => $f->address_abroad])->forceFill(['created_at' => $in, 'updated_at' => $in])->save();
            }
        }

        // ── forex: 14 days of rates ──
        $cur = Models\Currency::all()->keyBy('code');
        foreach ($FX::CURRENCIES as $k => [$code, , , , $ref, $buy]) {
            for ($d = 13; $d >= 0; $d--) {
                $f = 1 + 0.006 * sin($d * 0.9 + $k) - 0.0004 * $d;
                Models\ForexRate::create(['currency_id' => $cur[$code]->id, 'date' => $bd->copy()->subDays($d)->toDateString(), 'reference_rate' => round($ref * $f, 4),
                    'buy_rate' => round($buy * $f, 4), 'carried' => $d === 0 && $code === 'CHF', 'set_by' => $d === 0 && $code === 'CHF' ? null : $acct->id]);
            }
        }
        $rateOn = fn (string $code, Carbon $day) => Models\ForexRate::where('currency_id', $cur[$code]->id)->whereDate('date', $day->toDateString())->first();
        $pref = ['japan' => 'JPY', 'united kingdom' => 'GBP', 'germany' => 'EUR', 'spain' => 'EUR', 'india' => 'USD'];
        $foreignStays = Models\ReservationRoom::with('reservation.guest', 'guest', 'room')->whereIn('status', ['checked_in', 'checked_out'])
            ->where('checked_in_at', '>=', $bd->copy()->subDays(20))->orderBy('id')->get()->filter(fn ($st) => $FC::isForeign($FC::stayGuest($st)));
        $stayOn = function (Carbon $day, string $code) use ($foreignStays, $FC, $pref) {
            $on = $foreignStays->filter(fn ($st) => $st->checked_in_at->copy()->startOfDay()->lte($day) && (! $st->checked_out_at || $st->checked_out_at->copy()->startOfDay()->gt($day)));

            return $on->first(fn ($st) => ($pref[mb_strtolower((string) $FC::stayGuest($st)->nationality)] ?? 'USD') === $code) ?? $on->first();
        };
        $buyCash = function (int $ago, string $code, float $fc, array $x = []) use ($FX, $FC, $cur, $rateOn, $stayOn, $bd, $desk) {
            $day = $bd->copy()->subDays($ago);
            $st = $stayOn($day, $code);
            $r = $rateOn($code, $day);
            if (! $st || ! $r) {
                return null;
            }
            $g = $FC::stayGuest($st);
            $c = $cur[$code];
            $inr = round($fc * (float) $r->buy_rate / $c->unit, 2);
            $gv = 0.0; // v4.3: money changing is VAT-exempt in Ghana
            $gst = 0.0;
            $usd = $code === 'USD' ? $fc : round($inr / (float) $rateOn('USD', $day)->reference_rate, 2);
            $at = $day->copy()->setTime(9 + $ago % 9, (7 * $ago) % 60);
            $e = Models\ForexExchange::create(['exchange_no' => Numbering::next('forex'), 'business_date' => $day->toDateString(), 'reservation_room_id' => $st->id, 'guest_id' => $g->id,
                'guest_name' => trim($g->first_name.' '.$g->last_name), 'passport_no' => $g->passport_no, 'nationality' => $g->nationality, 'currency_id' => $c->id, 'instrument' => $x['instrument'] ?? 'notes',
                'fc_amount' => $fc, 'rate' => $r->buy_rate, 'unit' => $c->unit, 'inr_amount' => $inr, 'fee' => 0, 'gst_value' => $gv, 'gst' => $gst, 'net_amount' => round($inr - $gst, 2),
                'usd_equiv' => $usd, 'payout' => 'cash', 'certificate' => true, 'certificate_prints' => 1, 'notes' => $x['notes'] ?? null, 'created_by' => $desk->id]
                + (isset($x['void']) ? ['voided_at' => $at->copy()->addMinutes(25), 'voided_by' => $desk->id, 'void_reason' => $x['void']] : []));
            $e->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            return $e;
        };
        foreach ([[12, 'USD', 500], [11, 'EUR', 300], [9, 'JPY', 50000], [8, 'GBP', 200], [7, 'USD', 800], [6, 'EUR', 150]] as [$ago, $code, $fc]) {
            $buyCash($ago, $code, $fc);
        }
        auth()->setUser($acct);
        try {
            $day = $bd->copy()->subDays(5);
            $held = $FX::position()->keyBy('code');
            $lines = [];
            foreach (['USD', 'EUR', 'GBP'] as $code) {
                if (($held[$code]['held'] ?? 0) > 0 && ($r = $rateOn($code, $day))) {
                    $lines[$cur[$code]->id] = ['fc_amount' => $held[$code]['held'], 'rate' => round((float) $r->reference_rate * 0.9985, 4)];
                }
            }
            if ($lines) {
                $sv = $FX::surrender(['date' => $day->toDateString(), 'counterparty' => 'GCB Bank – Treasury (Forex), High Street, Accra', 'reference' => 'GCB/FX/'.$day->format('ymd').'/0412',
                    'payment_mode_id' => Models\PaymentMode::where('type', 'bank')->value('id'), 'notes' => 'Weekly surrender – JPY kept back (bank had no yen buyer that day)'], $lines);
                $sv->forceFill(['created_at' => $day->copy()->setTime(15, 40), 'updated_at' => $day->copy()->setTime(15, 40)])->save();
            }
        } catch (\Throwable $e) {
            $this->command?->warn('Forex surrender seed skipped: '.$e->getMessage());
        }
        auth()->setUser($desk);
        $buyCash(3, 'USD', 300, ['void' => 'Wrong amount keyed – re-entered as the next exchange']);
        foreach ([[3, 'USD', 400], [2, 'AED', 1500], [1, 'GBP', 250, 'tc']] as $row) {
            $buyCash($row[0], $row[1], $row[2], isset($row[3]) ? ['instrument' => $row[3]] : []);
        }

        // ── a bill credit in US dollars through the service (the first foreign guest in house) ──
        $john = $inHouse->first(fn ($st) => $FC::stayGuest($st)->nationality !== 'India') ?? $inHouse->first();
        if ($john) {
            try {
                $folio = \App\Services\FolioService::openForStay($john);
                // The business date can move during the rich seed. Use today's rate or the
                // latest available rate instead of dereferencing a missing date row.
                $usd = $rateOn('USD', $bd) ?? Models\ForexRate::where('currency_id', $cur['USD']->id)
                    ->whereDate('date', '<=', $bd->toDateString())->orderByDesc('date')->first();
                $usdRate = (float) ($usd?->buy_rate ?? 0);
                $fc = $usdRate > 0 ? min(200, floor(max(0, $folio->balance()) / $usdRate)) : 0;
                if ($fc >= 10) {
                    $FX::exchange(['reservation_room_id' => $john->id, 'currency_id' => $cur['USD']->id, 'fc_amount' => $fc, 'instrument' => 'notes', 'payout' => 'folio',
                        'certificate' => true, 'notes' => 'Settled part of the bill in dollars']);
                }
            } catch (\Throwable $e) {
                $this->command?->warn('Forex bill-credit seed skipped: '.$e->getMessage());
            }
        }
        LedgerService::sync(true); // back-dated purchases & the surrender are outside the incremental window
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    /**
     * v3.6 – budgeting demo: the FY budget sized to the demo hotel (24 rooms), prepared by the accountant and approved by the
     * administrator (four-eyes); statistics drive room revenue; the ledger is compared from the first of this month (go-live);
     * September variances explained; a re-forecast after August awaiting approval; next year's draft copied at +8 % / +6 %.
     */
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

    /**
     * v3.8 gift vouchers: a spread of sold vouchers (partly redeemed on a folio, a dinner voucher used in the restaurant,
     * a reloaded stored-value card, one expiring soon, one lapsed, one suspended, one cancelled & refunded) and a promotional
     * "Diwali 2026" campaign. Dates are back-dated after issue so the liability, breakage and sales reports have history.
     */
    protected function seedVouchers(bool $rich, Models\User $user): void
    {
        $G = \App\Services\GiftVoucherService::class;
        $G::ensureDefaults();
        $types = Models\GiftVoucherType::pluck('id', 'name');
        if (! isset($types['Spa Escape'])) {
            $types['Spa Escape'] = Models\GiftVoucherType::create(['name' => 'Spa Escape', 'kind' => 'single', 'face_value' => 5000, 'validity_months' => 9, 'usable_on' => ['folio'], 'color' => '#be185d',
                'terms' => 'A 90-minute signature spa treatment. Book in advance; post the treatment to your room and redeem at the front desk.'])->id;
        }
        if (! $rich) {
            return;
        }
        $modes = Models\PaymentMode::pluck('id', 'type');
        [$cash, $card, $upi] = [$modes['cash'] ?? null, $modes['card'] ?? $modes['cash'] ?? null, $modes['wallet'] ?? $modes['card'] ?? null];
        $today = bdate()->copy();
        // re-date a voucher and its transactions: issued $days ago (expiry from the issue date unless given)
        $back = function (Models\GiftVoucher $v, int $days, ?Carbon $expires = null) use ($today) {
            $issued = $today->copy()->subDays($days);
            $v->update(['issued_on' => $issued->toDateString(), 'expires_on' => ($expires ?? $issued->copy()->addMonthsNoOverflow($v->type->validity_months))->toDateString()]);
            Models\GiftVoucherTransaction::where('gift_voucher_id', $v->id)->where('type', 'issue')->update(['business_date' => $issued->toDateString(), 'created_at' => $issued->copy()->setTime(12, 0)]);

            return $v->fresh('type');
        };
        $sell = fn (string $type, float $amount, $mode, string $buyer, ?string $to = null, ?string $toEmail = null, ?string $msg = null, ?string $phone = null) => $G::sell([
            'gift_voucher_type_id' => $types[$type], 'amount' => $amount, 'payment_mode_id' => $mode, 'purchaser_name' => $buyer, 'purchaser_phone' => $phone ?? '98'.rand(10000000, 99999999),
            'purchaser_email' => strtolower(str_replace(' ', '.', $buyer)).'@example.com', 'recipient_name' => $to, 'recipient_email' => $toEmail, 'message' => $msg, 'payment_ref' => 'SEED-'.rand(1000, 9999)]);
        $dated = fn ($v, string $type, Carbon $d) => Models\GiftVoucherTransaction::where('gift_voucher_id', $v->id)->where('type', $type)->latest('id')->first()?->update(['business_date' => $d->toDateString()]);

        // 1. birthday gift card – partly spent on an in-house guest's folio today
        $v1 = $back($sell('Gift Card', 5000, $card, 'Rohan Mehta', 'Priya Mehta', 'priya.mehta@example.com', 'Happy birthday! Enjoy a weekend on us.'), 40);
        $stay = Models\ReservationRoom::with('folio')->where('status', 'checked_in')->get()->first(fn ($s) => $s->folio && $s->folio->status === 'open' && $s->folio->balance() >= 2000);
        if ($stay) {
            \App\Services\FolioService::payment($stay->folio, 2000, $G::modeId(), $v1->code);
            $v1->update(['guest_id' => $stay->guest_id ?? $stay->reservation?->guest_id]);
        }
        // 2. dinner for two, used in the restaurant five days ago (single use – the small remainder lapses)
        $v2 = $back($sell('Dinner for Two', 3500, $upi, 'Kavya Iyer', 'Arjun & Meera Nair', null, 'Congratulations on the new home!'), 32);
        $rest = Models\Outlet::where('type', 'restaurant')->orderBy('id')->first();
        if ($rest) {
            $d = $today->copy()->subDays(5);
            $o = Models\PosOrder::create(['order_no' => Numbering::next('pos_order'), 'bill_no' => Numbering::next('pos_bill'), 'outlet_id' => $rest->id, 'pos_table_id' => Models\PosTable::where('outlet_id', $rest->id)->value('id'),
                'order_type' => 'dine_in', 'covers' => 2, 'business_date' => $d, 'status' => 'open', 'user_id' => $user->id]);
            foreach (Models\MenuItem::where(fn ($q) => $q->where('outlet_id', $rest->id)->orWhereNull('outlet_id'))->where('price', '>', 0)->orderBy('price')->get() as $it) {
                $o->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => 1, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => $it->price, 'kot_no' => 1]);
                $o->recalculate();
                if ($o->total > 2600) {
                    break;
                }
            }
            if ($o->total > 3500) {
                $o->items()->latest('id')->first()->delete();
                $o->recalculate();
            }
            $o->forceFill(['status' => 'settled', 'settlement' => 'payment', 'payment_mode_id' => $G::modeId(), 'settled_at' => $d->copy()->setTime(21, 15), 'settled_by' => $user->id])->save();
            $G::posPayment($o->fresh('outlet'), $v2->code);
            Models\GiftVoucherTransaction::where('gift_voucher_id', $v2->id)->whereIn('type', ['redeem', 'expire'])->update(['business_date' => $d->toDateString()]);
        }
        // 3. untouched stay voucher and spa voucher
        $back($sell('Stay Voucher – One Night', 7500, $card, 'Sanjay Kapoor', 'Neha Kapoor', 'neha.kapoor@example.com', 'For our anniversary getaway.'), 75);
        $back($sell('Spa Escape', 5000, $cash, 'Ananya Bose', 'Ritu Bose'), 12);
        // 4. stored-value card: loaded two months ago, reloaded ten days ago
        $v4 = $back($sell('Stored-Value Card', 5000, $card, 'Vikram Singh'), 60);
        $G::reload($v4, 2000, $upi, 'MOMO-RL-2231');
        $dated($v4, 'reload', $today->copy()->subDays(10));
        // 5. stored card close to expiry, partly used – drives the "expiring soon" alert and reminder
        $v5 = $back($sell('Stored-Value Card', 4000, $cash, 'Deepa Menon', 'Deepa Menon', 'deepa.menon@example.com'), 345, $today->copy()->addDays(18));
        // 6. gift card that lapsed with part of its balance (breakage)
        $v6 = $back($sell('Gift Card', 2500, $cash, 'Farhan Qureshi', 'Zoya Qureshi'), 400, $today->copy()->subDays(35));
        $G::expireDue();
        $dated($v6, 'expire', $today->copy()->subDays(34));
        // 7. suspended – reported lost
        $v7 = $back($sell('Gift Card', 3000, $upi, 'Manish Gupta', 'Rahul Gupta'), 20);
        $G::suspend($v7, 'Reported lost by the purchaser – awaiting confirmation');
        // 8. cancelled & refunded
        $v8 = $back($sell('Gift Card', 2000, $cash, 'Pooja Sharma'), 15);
        $G::cancel($v8, 'Purchaser changed plans – refunded within 7 days', $cash);
        $dated($v8, 'cancel', $today->copy()->subDays(13));
        // 9. more sold gift cards for the register
        foreach ([['Gift Card', 10000, $card, 'Tata Consultancy HR', 'Employee of the Quarter', 50], ['Gift Card', 1500, $upi, 'Sneha Pillai', 'Aditi Pillai', 8], ['Dinner for Two', 3500, $card, 'Harish Rao', 'Lakshmi Rao', 3]] as [$t, $amt, $m, $buyer, $to, $days]) {
            $back($sell($t, $amt, $m, $buyer, $to), $days);
        }
        // 10. promotional campaign – five loyal guests, one already used on a folio; plus a service-recovery dinner
        $promo = $G::issuePromo(['gift_voucher_type_id' => $types['Gift Card'], 'amount' => 1000, 'campaign' => 'Diwali 2026', 'message' => 'Wishing you a sparkling Diwali – a little something for your next visit.'], 5,
            [['Amit Verma', 'amit.verma@example.com'], ['Sunita Reddy', 'sunita.reddy@example.com'], ['Karan Malhotra', 'karan.malhotra@example.com'], ['Isha Agarwal', 'isha.agarwal@example.com'], ['Nikhil Joshi', 'nikhil.joshi@example.com']]);
        foreach ($promo as $p) {
            $back($p, 6, $today->copy()->addMonthsNoOverflow(3));
        }
        $other = Models\ReservationRoom::with('folio')->where('status', 'checked_in')->when($stay, fn ($q) => $q->where('id', '!=', $stay->id))->get()->first(fn ($s) => $s->folio && $s->folio->status === 'open' && $s->folio->balance() >= 1000);
        if ($other) {
            \App\Services\FolioService::payment($other->folio, 1000, $G::modeId(), $promo->first()->code);
        }
        $back($G::issuePromo(['gift_voucher_type_id' => $types['Dinner for Two'], 'campaign' => 'Service recovery – noisy room 305', 'recipient_name' => 'Mr. Thomas George'])->first(), 2);
        \App\Services\LedgerService::sync(true); // back-dated voucher sales / breakage sit outside the incremental window
    }

    /**
     * v4.0 API platform demo: keys for the hotel website (booking), a revenue-management system (IP-restricted), housekeeping tablets,
     * an owner BI dashboard (expiring) and a revoked legacy kiosk; webhook endpoints for the RMS and the group data warehouse plus an
     * old CRM connector that was switched off after 20 failures; two bookings (one cancelled) made through the API, three days of
     * delivery history (one delivery still retrying) and 24 hours of request logs including errors and refused calls.
     */
    /**
     * v4.1 upselling demo: an 8-offer catalogue; for the last 30 days' departed stays pre-arrival e-mails (most opened) and
     * front-desk presentations with the orders they converted (booked history – no folio lines); in house a desk-sold breakfast,
     * a late check-out today and a portal spa credit plus a late check-out request waiting for the desk; upcoming arrivals with
     * a pre-arrival upgrade, pick-up and romance package, an early check-in request waiting and one declined – offers e-mailed to
     * most arrivals in the window, the last ones left for the hourly run.
     */
    /** v4.2 security demo – touches no bookings or room inventory (channel parity stays clean). */
    /**
     * v4.4 Ghana compliance II: August's withholding-tax return filed with GRA; this month's outlet bills stamped by E-VAT (one to a
     * company with its TIN), the latest one caught by a timed-out E-VAT call and waiting for the automatic retry.
     */
    protected function seedStatutory(bool $rich, Carbon $today): void
    {
        foreach (\App\Services\StatutoryService::DEFAULTS as $k => $v) {
            Models\Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        if (! $rich) {
            return;
        }
        foreach (['stat_ssnit_employer_no' => '801-0419221', 'stat_tier2_trustee' => 'Coastal Pension Trustees Ltd', 'stat_tier2_scheme' => 'CPT-OPS-T2-0187'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        $bank = Models\PaymentMode::where('type', 'bank')->where('is_active', true)->value('id');
        $prev = auth()->user();
        if ($u = Models\User::where('username', 'hr')->where('hotel_id', \App\Support\Tenant::id())->first()) {
            auth()->setUser($u);
        }
        $m2 = $today->copy()->startOfMonth()->subMonthsNoOverflow(2);
        $m1 = $today->copy()->startOfMonth()->subMonthNoOverflow();
        // [period, kind, paid on (day of the following month), reference]
        $plan = [[$m2, 'ssnit', 12, 'SSNIT/'.$m2->format('Ym').'/77310'], [$m2, 'tier2', 13, 'CPT/'.$m2->format('Ym').'/0187'], [$m2, 'paye', 14, 'GRA-PAYE-'.$m2->format('Ym').'-51209'],
            [$m1, 'ssnit', 11, 'SSNIT/'.$m1->format('Ym').'/80442'], [$m1, 'paye', 14, 'GRA-PAYE-'.$m1->format('Ym').'-55861']];
        foreach ($plan as [$m, $kind, $day, $ref]) {
            $paid = $m->copy()->addMonthNoOverflow()->day($day);
            if ($paid->gt($today)) {
                continue;
            }
            try {
                \App\Services\StatutoryService::remit($kind, $m->format('Y-m'), ['paid_on' => $paid->toDateString(), 'payment_mode_id' => $bank, 'reference' => $ref]);
            } catch (\RuntimeException $e) {
                // payroll month not approved on this property – nothing to remit
            }
        }
        $prev ? auth()->setUser($prev) : auth()->forgetUser();
    }

    /**
     * v4.6 bank reconciliation demo (hotel 1): last month's bank statement reconciled (with a bank charge booked from it and two
     * items still in transit), this month's bank statement in progress (auto-matched; charges, interest and an unidentified credit
     * still to deal with, two cheques not yet presented) and a Mobile Money merchant statement with daily batched collections and fees.
     */
    /** v4.7 A small PNG "till receipt" so the demo vouchers and claims have something to open. */
    protected function receiptPng(string $shop, string $item, float $amount, Carbon $date): \Illuminate\Http\UploadedFile
    {
        $im = imagecreatetruecolor(260, 150);
        imagefill($im, 0, 0, imagecolorallocate($im, 252, 252, 247));
        $ink = imagecolorallocate($im, 30, 41, 59);
        imagestring($im, 4, 12, 10, mb_strimwidth(strtoupper($shop), 0, 28), $ink);
        imagestring($im, 2, 12, 34, $date->format('d/m/Y').'  RECEIPT', $ink);
        imageline($im, 12, 54, 248, 54, $ink);
        imagestring($im, 3, 12, 64, mb_strimwidth($item, 0, 34), $ink);
        imagestring($im, 5, 12, 96, 'TOTAL GHS '.number_format($amount, 2), $ink);
        imagestring($im, 1, 12, 130, 'Thank you - Accra', $ink);
        $path = tempnam(sys_get_temp_dir(), 'rcpt').'.png';
        imagepng($im, $path);
        imagedestroy($im);

        return new \Illuminate\Http\UploadedFile($path, 'receipt.png', 'image/png', null, true);
    }

    /** v4.7 petty cash floats and staff expense claims (cash only – nothing touches the bank statements being reconciled). */
    /** v4.8 Procurement II demo data (hotel 1). */
    protected function seedProcurement(Carbon $today): void
    {
        $S = \App\Services\ProcurementService::class;
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => \App\Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        [$admin, $store, $acct] = [$u('admin'), $u('store'), $u('accounts')];
        if (! $admin || ! $store || \App\Models\Rfq::exists()) {
            return;
        }
        $prevUser = auth()->user();
        $as = fn ($user) => auth()->setUser($user);
        $D = fn (int $n) => $today->copy()->subDays($n)->toDateString();
        $F = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $sup = fn ($n) => \App\Models\Supplier::where('name', $n)->value('id');
        $item = fn ($n) => \App\Models\InventoryItem::where('name', $n)->value('id');
        $storeId = fn ($n) => \App\Models\Store::where('name', $n)->value('id');
        foreach ([['Accra Wholesale Provisions', 'Kwame Asante', '0302 771 204', 'orders@accrawholesale.example', 'Spintex Road, Accra', 'goods'],
            ['Tema Hygiene Distributors', 'Efua Mensah', '0303 204 118', 'sales@temahygiene.example', 'Community 9, Tema', 'goods']] as [$n, $c, $ph, $em, $ad, $w]) {
            if (! $sup($n)) {
                \App\Models\Supplier::create(['name' => $n, 'contact_person' => $c, 'phone' => $ph, 'email' => $em, 'address' => $ad, 'payment_terms_days' => 30, 'wht_category' => $w, 'is_active' => true]);
            }
        }
        [$metro, $fresh, $clean, $tech, $awp, $thd] = [$sup('Metro Cash & Carry'), $sup('FreshFarm Foods'), $sup('CleanPro Supplies'), $sup('TechWorld IT Solutions'), $sup('Accra Wholesale Provisions'), $sup('Tema Hygiene Distributors')];
        if (! $metro || ! $fresh || ! $clean || ! $item('Basmati Rice')) {
            auth()->setUser($prevUser ?? new \App\Models\User);

            return;
        }
        $as($store);
        // ── price lists (one expired, one expiring soon)
        $price = fn ($s, $i, $r, $from, $to = null, $lead = null) => $S::savePrice(['supplier_id' => $s, 'inventory_item_id' => $item($i), 'rate' => $r, 'min_qty' => 0, 'lead_days' => $lead, 'valid_from' => $from, 'valid_to' => $to, 'notes' => 'Rate contract 2026']);
        $price($metro, 'Basmati Rice', 98, $D(200), $D(61));
        $price($metro, 'Basmati Rice', 92, $D(60), null, 2);
        $price($metro, 'Refined Oil', 155, $D(60), null, 2);
        $price($metro, 'Mineral Water 1L', 13.5, $D(60), null, 1);
        $price($metro, 'Soft Drinks 300ml', 24, $D(60), null, 1);
        $price($fresh, 'Chicken', 240, $D(45), null, 1);
        $price($fresh, 'Paneer', 370, $D(45), null, 1);
        $price($clean, 'Floor Cleaner 5L', 470, $D(90), null, 3);
        $price($clean, 'Glass Cleaner 500ml', 135, $D(90), null, 3);
        $price($clean, 'Toilet Cleaner 1L', 105, $D(90), null, 3);
        $price($clean, 'Garbage Bags (roll of 30)', 88, $D(90), null, 3);
        $price($awp, 'Basmati Rice', 90, $D(60), $F(10), 3);
        $price($awp, 'Refined Oil', 158, $D(60), $F(10), 3);
        $price($thd, 'Floor Cleaner 5L', 455, $D(30), null, 5);
        if ($tech) {
            $price($tech, 'Printer Toner HP 88A', 1790, $D(120), null, 4);
        }
        $backdate = function ($rfq, int $ago, int $dueAgo) use ($D) {
            $rfq->forceFill(['rfq_date' => $D($ago), 'respond_by' => $D($dueAgo), 'sent_at' => $rfq->sent_at ? \Carbon\Carbon::parse($D($ago))->setTime(10, 0) : null,
                'created_at' => \Carbon\Carbon::parse($D($ago))->setTime(9, 30)])->save();
            \App\Models\RfqSupplier::where('rfq_id', $rfq->id)->whereNotNull('invited_at')->update(['invited_at' => \Carbon\Carbon::parse($D($ago))->setTime(10, 0)]);
        };
        $quote = fn ($rfq, $sid, array $rates, array $x = [], string $src = 'manual') => $S::submitQuote(\App\Models\RfqSupplier::where('rfq_id', $rfq->id)->where('supplier_id', $sid)->first(),
            $x + ['lines' => collect($rates)->mapWithKeys(fn ($v, $name) => [$rfq->lines->firstWhere('inventory_item_id', $item($name))->id => is_array($v) ? $v : ['rate' => $v, 'tax_rate' => 20]])->all()], $src);

        // ── RFQ 1: housekeeping chemicals – awarded (split), both POs delivered
        $r1 = $S::saveRfq(['title' => 'Housekeeping chemicals – Q3 replenishment', 'store_id' => $storeId('Housekeeping Store'), 'respond_by' => $F(1), 'needed_by' => $F(5),
            'terms' => 'Prices in GH₵ delivered to the hotel. Quote VAT / NHIL / GETFund as the tax %. Payment 30 days after invoice.', 'supplier_ids' => [$clean, $thd, $metro],
            'lines' => [['item_id' => $item('Floor Cleaner 5L'), 'qty' => 20], ['item_id' => $item('Glass Cleaner 500ml'), 'qty' => 30], ['item_id' => $item('Toilet Cleaner 1L'), 'qty' => 40], ['item_id' => $item('Garbage Bags (roll of 30)'), 'qty' => 50]]]);
        $S::send($r1);
        $r1->load('lines');
        $quote($r1, $clean, ['Floor Cleaner 5L' => 468, 'Glass Cleaner 500ml' => 132, 'Toilet Cleaner 1L' => 104, 'Garbage Bags (roll of 30)' => 86], ['quote_ref' => 'CP/Q/2231', 'valid_until' => $F(20), 'delivery_days' => 3, 'payment_terms_days' => 30]);
        $quote($r1, $thd, ['Floor Cleaner 5L' => 449, 'Glass Cleaner 500ml' => 139, 'Toilet Cleaner 1L' => ['rate' => 99, 'tax_rate' => 20, 'qty_offered' => 20, 'remarks' => 'Only 20 in stock'], 'Garbage Bags (roll of 30)' => 91],
            ['quote_ref' => 'THD-0917', 'valid_until' => $F(25), 'delivery_days' => 5, 'payment_terms_days' => 45], 'portal');
        $S::decline(\App\Models\RfqSupplier::where('rfq_id', $r1->id)->where('supplier_id', $metro)->first(), 'We do not stock these brands');
        $as($admin);
        $cmp = $S::comparative($r1);
        $award = [];
        foreach ($cmp['rows'] as $row) {
            $award[$row['line']->id] = $row['lowest'];
        }
        $pos = $S::award($r1, $award, [], 'L1 on every line; Tema Hygiene part-quoted toilet cleaner so CleanPro takes the full quantity.', false);
        $backdate($r1, 24, 21);
        $r1->forceFill(['awarded_at' => \Carbon\Carbon::parse($D(20))->setTime(15, 0), 'closed_at' => \Carbon\Carbon::parse($D(20))->setTime(15, 0)])->save();
        \App\Models\RfqQuote::where('rfq_id', $r1->id)->update(['quote_date' => $D(22)]);
        foreach ($pos as $po) {
            $po->forceFill(['po_date' => $D(20), 'expected_date' => $D(15)])->save();
            $S::approve($po->fresh());
            $po->forceFill(['approved_at' => \Carbon\Carbon::parse($D(20))->setTime(16, 0)])->save();
        }
        $as($store);
        foreach ($pos as $po) {
            $po = $po->fresh('items');
            $lines = $po->items->mapWithKeys(fn ($l) => [$l->id => ['delivered' => (float) $l->qty, 'rejected' => 0]])->all();
            if ($po->supplier_id === $thd) { // first delivery: 2 leaking cans rejected, balance next week
                $first = $po->items->mapWithKeys(fn ($l) => [$l->id => ['delivered' => (float) $l->qty, 'rejected' => 2, 'reason' => 'Cans leaking / seal broken']])->all();
                $S::receive($po, ['date' => $D(16), 'delivery_note' => 'THD-DN-5521', 'lines' => $first, 'vehicle_no' => 'GT 4471-21']);
                $S::receive($po->fresh(), ['date' => $D(12), 'delivery_note' => 'THD-DN-5580', 'invoice_no' => 'THD-INV-3390', 'lines' => $po->items->mapWithKeys(fn ($l) => [$l->id => ['delivered' => 2]])->all()]);
            } else {
                $S::receive($po, ['date' => $D(17), 'delivery_note' => 'CP-DN-7710', 'invoice_no' => 'CP-INV-9921', 'lines' => $lines]);
            }
        }

        // ── RFQ 2: kitchen dry stores – out for quotation (deadline in 2 days), one online quote, one keyed-in, one only viewed
        $r2 = $S::saveRfq(['title' => 'Kitchen dry stores & beverages – October', 'store_id' => $storeId('Kitchen Store'), 'respond_by' => $F(2), 'needed_by' => $F(6),
            'supplier_ids' => [$metro, $awp, $fresh], 'lines' => [['item_id' => $item('Basmati Rice'), 'qty' => 200, 'specification' => '25 kg bags, long grain'], ['item_id' => $item('Refined Oil'), 'qty' => 80],
                ['item_id' => $item('Mineral Water 1L'), 'qty' => 480, 'specification' => 'FDA-registered brand'], ['item_id' => $item('Soft Drinks 300ml'), 'qty' => 240]]]);
        $S::send($r2);
        $r2->load('lines');
        $quote($r2, $awp, ['Basmati Rice' => 89, 'Refined Oil' => 157, 'Mineral Water 1L' => 13.2, 'Soft Drinks 300ml' => 24.5], ['quote_ref' => 'AWP-Q-118', 'valid_until' => $F(21), 'delivery_days' => 3, 'payment_terms_days' => 30], 'portal');
        $quote($r2, $metro, ['Basmati Rice' => 92, 'Refined Oil' => 152, 'Mineral Water 1L' => 13.5, 'Soft Drinks 300ml' => 23.8], ['quote_ref' => 'MCC/2609/44', 'valid_until' => $F(14), 'delivery_days' => 2, 'payment_terms_days' => 21]);
        \App\Models\RfqSupplier::where('rfq_id', $r2->id)->where('supplier_id', $fresh)->update(['viewed_at' => now()->subHours(20)]);
        $backdate($r2, 2, -2);

        // ── RFQ 3: front office stationery – closed, ready to award (3 quotes)
        if ($tech) {
            $r3 = $S::saveRfq(['title' => 'Front office stationery & toner', 'store_id' => $storeId('Main Store'), 'respond_by' => $F(1), 'supplier_ids' => [$metro, $tech, $awp],
                'lines' => [['item_id' => $item('A4 Paper Ream'), 'qty' => 40, 'specification' => '80 gsm'], ['item_id' => $item('Printer Toner HP 88A'), 'qty' => 4, 'specification' => 'Genuine HP only'], ['item_id' => $item('Ball Pens (box of 10)'), 'qty' => 20]]]);
            $S::send($r3);
            $r3->load('lines');
            $quote($r3, $metro, ['A4 Paper Ream' => 272, 'Printer Toner HP 88A' => 1880, 'Ball Pens (box of 10)' => 58], ['valid_until' => $F(20), 'delivery_days' => 2, 'payment_terms_days' => 21]);
            $quote($r3, $tech, ['A4 Paper Ream' => 285, 'Printer Toner HP 88A' => ['rate' => 1760, 'tax_rate' => 20, 'remarks' => 'Genuine HP, 12-month warranty'], 'Ball Pens (box of 10)' => 62], ['quote_ref' => 'TW-4410', 'valid_until' => $F(30), 'delivery_days' => 4, 'payment_terms_days' => 30]);
            $quote($r3, $awp, ['A4 Paper Ream' => 268, 'Ball Pens (box of 10)' => 55], ['valid_until' => $F(15), 'delivery_days' => 3, 'payment_terms_days' => 30], 'portal');
            $S::close($r3);
            $backdate($r3, 6, 1);
            \App\Models\RfqQuote::where('rfq_id', $r3->id)->update(['quote_date' => $D(3)]);
        }

        // ── RFQ 4: engineering consumables – still a draft
        $S::saveRfq(['title' => 'Engineering consumables – lamps & batteries', 'store_id' => $storeId('Main Store'), 'respond_by' => $F(5), 'supplier_ids' => array_values(array_filter([$metro, $tech])),
            'lines' => [['item_id' => $item('LED Bulb 9W'), 'qty' => 60], ['item_id' => $item('Tube Light 20W LED'), 'qty' => 24], ['item_id' => $item('AA Batteries (pack of 4)'), 'qty' => 30]], 'notes' => 'Waiting for the engineer to confirm quantities']);

        // ── POs at each approval level + a part-received overdue PO
        $mk = function ($sid, $storeName, array $lines, int $ago, ?int $expAgo, string $notes) use ($item, $storeId, $D) {
            $po = \App\Models\PurchaseOrder::create(['po_no' => \App\Support\Numbering::next('po'), 'supplier_id' => $sid, 'store_id' => $storeId($storeName), 'po_date' => $D($ago),
                'expected_date' => $expAgo === null ? null : $D($expAgo), 'status' => 'draft', 'notes' => $notes, 'created_by' => auth()->id()]);
            $sub = 0;
            $tax = 0;
            foreach ($lines as [$name, $qty, $rate]) {
                $amt = round($qty * $rate, 2);
                $po->items()->create(['inventory_item_id' => $item($name), 'qty' => $qty, 'rate' => $rate, 'tax_rate' => 20, 'amount' => $amt]);
                $sub += $amt;
                $tax += $amt * 0.2;
            }
            $po->update(['subtotal' => $sub, 'tax' => round($tax, 2), 'total' => round($sub + $tax, 2)]);
            \App\Services\ProcurementService::stampListRates($po);

            return $po->fresh('items');
        };
        $as($store);
        $mk($metro, 'Kitchen Store', [['Basmati Rice', 25, 99], ['Refined Oil', 10, 155]], 1, -4, 'Urgent top-up – rice priced above the contract rate (supplier says new crop)');
        $mk($clean, 'Housekeeping Store', [['Bed Sheet (King)', 45, 650]], 0, -10, 'Linen replacement for 3rd floor refurbishment');
        $late = $mk($fresh, 'Kitchen Store', [['Chicken', 50, 240], ['Paneer', 10, 370]], 7, 5, 'Weekly protein order');
        $as($admin);
        $S::approve($late);
        $late->forceFill(['approved_at' => \Carbon\Carbon::parse($D(7))->setTime(11, 0)])->save();
        $as($store);
        $S::receive($late->fresh(), ['date' => $D(4), 'delivery_note' => 'FF-22817', 'lines' => $late->items->mapWithKeys(fn ($l) => [$l->id => $l->item?->name === 'Chicken' || $l->inventory_item_id === $item('Chicken') ? ['delivered' => 32, 'rejected' => 2, 'reason' => 'Temperature above 5°C on arrival'] : ['delivered' => 0]])->all()]);

        auth()->setUser($prevUser ?? new \App\Models\User);

        \App\Services\LedgerService::sync(true); // back-dated GRNs sit outside the incremental window
    }

    protected function seedPetty(Carbon $today): void
    {
        $P = \App\Services\PettyCashService::class;
        $C = \App\Services\ExpenseClaimService::class;
        $P::ensureDefaults();
        $C::ensureDefaults();
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => \App\Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        [$acct, $fom, $chef, $admin] = [$u('accounts'), $u('fom'), $u('chef'), $u('admin')];
        $cash = \App\Models\PaymentMode::where('type', 'cash')->where('is_active', true)->first();
        if (! $acct || ! $fom || ! $chef || ! $cash || \App\Models\PettyFloat::exists()) {
            return;
        }
        $emp = fn ($name) => \App\Models\Employee::where('name', $name)->first();
        $head = fn ($code) => \App\Models\AccountHead::where('code', $code)->value('id');
        $sys = fn ($k) => \App\Models\AccountHead::sys($k);
        $D = fn (int $n) => $today->copy()->subDays($n);
        $dept = fn ($n) => \App\Models\Department::where('name', $n)->value('id');
        $prevUser = auth()->user();
        $as = fn ($user) => auth()->setUser($user);
        $v = function ($f, int $ago, string $payee, string $what, $headId, float $amt, array $x = []) use ($P, $D) {
            $rc = ! empty($x['png']) ? $this->receiptPng($payee, $what, $amt, $D($ago)) : null;

            return $P::expense($f, ['date' => $D($ago)->toDateString(), 'payee' => $payee, 'description' => $what, 'expense_head_id' => $headId, 'amount' => $amt,
                'vat_invoice' => ! empty($x['vat']), 'receipt_ref' => $x['ref'] ?? null, 'no_receipt_reason' => $x['why'] ?? null], $rc);
        };
        $denoms = function (float $amount) use ($P) {
            $left = (int) round($amount * 100);
            $q = [];
            foreach ($P::DENOMS as $k => [$val]) {
                $c = (int) round($val * 100);
                if ($k === 'n2' || $k === 'n1') {
                    continue; // coins for GH₵2 / GH₵1
                }
                if ($n = intdiv($left, $c)) {
                    $q[$k] = $n;
                    $left -= $n * $c;
                }
            }

            return $q;
        };

        // ── floats, funded from the main cash 25 days ago
        $as($acct);
        $fo = $P::createFloat(['code' => 'FO', 'name' => 'Front Office Petty Cash', 'custodian_id' => $fom->id, 'department_id' => $dept('Front Office'), 'imprest_amount' => 3000,
            'voucher_limit' => 500, 'payment_mode_id' => $cash->id, 'date' => $D(25)->toDateString(), 'notes' => 'Kept in the front office safe; key with the duty manager']);
        $kit = $P::createFloat(['code' => 'KIT', 'name' => 'Kitchen & Stores Petty Cash', 'custodian_id' => $chef->id, 'department_id' => $dept('Kitchen'), 'imprest_amount' => 2000,
            'voucher_limit' => 400, 'payment_mode_id' => $cash->id, 'date' => $D(25)->toDateString(), 'notes' => 'Market purchases – fresh fish, vegetables, charcoal']);

        // ── front office
        $as($fom);
        $v($fo, 24, 'Accra Printing Press', 'Registration cards & folio paper', $sys('stationery'), 180.00, ['vat' => true, 'png' => true, 'ref' => 'APP-20931']);
        $v($fo, 22, 'Taxi – Kwesi (GR 4471-19)', "Guest's forgotten passport to Kotoka airport", $sys('travel'), 85.00, ['ref' => 'MoMo 55120934']);
        $v($fo, 20, 'Telecel Ghana', 'Airtime for the duty manager phone', $sys('telephone_exp'), 50.00);
        $iou1 = $P::issueIou($fo, ['date' => $D(18)->toDateString(), 'employee_id' => $emp('Deepa Nair')?->id, 'amount' => 300, 'description' => 'Flowers & welcome fruit for the VIP arrival', 'due_date' => $D(15)->toDateString()]);
        $P::settleIou($iou1->fresh('float'), ['date' => $D(16)->toDateString(), 'spent' => 262.50, 'expense_head_id' => $head('6070'), 'description' => 'Flowers (Osu florist) and fruit basket', 'receipt_ref' => 'OF-118'],
            $this->receiptPng('Osu Florist', 'Flowers & fruit basket', 262.50, $D(16)));
        $v($fo, 12, 'Makola Electricals', 'Bulbs & extension boards for the lobby', $sys('repairs'), 240.00, ['png' => true]);
        $v($fo, 8, 'Auntie Muni Chop Bar', 'Lunch for the night audit team (stock count overtime)', $sys('staff_welfare'), 120.00, ['png' => true]);

        // ── expense claims paid so far
        $joseph = $emp('Joseph Thomas');
        $ramesh = $emp('Ramesh Gowda');
        $eng = $u('engineer');
        if ($joseph && $eng) {
            $as($eng);
            $k = $C::create(null, 'Kumasi trip – chiller spare parts supplier visit', 'Drove my own car; supplier Adum Refrigeration');
            $C::addLine($k, ['date' => $D(20)->toDateString(), 'category' => 'mileage', 'km' => 500, 'description' => 'Accra – Kumasi – Accra (500 km)']);
            $C::addLine($k, ['date' => $D(20)->toDateString(), 'category' => 'accommodation', 'amount' => 450, 'vat_amount' => 75, 'description' => 'Guest house in Kumasi, 1 night', 'receipt_ref' => 'KGH-5521'],
                $this->receiptPng('Kumasi Guest House', '1 night room', 450, $D(20)));
            $C::addLine($k, ['date' => $D(19)->toDateString(), 'category' => 'meals', 'amount' => 85, 'description' => 'Dinner on the road (Nkawkaw)', 'receipt_ref' => 'Chop bar – handwritten bill']);
            $C::submit($k);
            $k->forceFill(['submitted_at' => $D(18)->setTime(9, 20)])->save();
            $as($acct);
            $C::approve($k);
            $k->forceFill(['approved_on' => $D(17)->toDateString()])->save();
            $C::pay($k->fresh(), 'mode', $cash->id, null, $D(14)->toDateString(), 'Cash – signed for');
        }
        if ($ramesh && ($att = $u('attendant'))) {
            $as($att);
            $r = $C::create(null, 'Taxi – urgent linen to the laundry contractor');
            $C::addLine($r, ['date' => $D(9)->toDateString(), 'category' => 'transport', 'amount' => 45, 'description' => 'Taxi to Spintex laundry and back with the banquet linen']);
            $C::submit($r);
            $as($acct);
            $C::approve($r);
            $r->forceFill(['approved_on' => $D(9)->toDateString()])->save();
            $C::pay($r->fresh(), 'petty', null, $fo->id, $D(8)->toDateString());
        }

        // ── front office, last week: overdue IOU, a count 5.00 short (approved), an over-limit repair waiting, an open IOU
        $as($fom);
        $P::issueIou($fo, ['date' => $D(7)->toDateString(), 'employee_id' => $emp('Sunil Yadav')?->id, 'amount' => 150, 'description' => 'Diesel for the shuttle van', 'due_date' => $D(4)->toDateString()]);
        $bal = $P::balance($fo);
        $c1 = $P::count($fo, $denoms($bal - 5), 'GH₵5 short – probably change given with the taxi fare', $acct->id);
        $c1->forceFill(['date' => $D(3)->toDateString()])->save();
        $as($acct);
        $P::approveCount($c1->fresh('float'));
        \App\Models\PettyTxn::whereKey($c1->fresh()->variance_txn_id)->update(['date' => $D(3)->toDateString()]);
        $as($fom);
        $v($fo, 1, 'Nii Plumbing Works', 'Emergency: burst pipe in room 204 – plumber & fittings', $sys('repairs'), 850.00, ['png' => true, 'ref' => 'NPW-044']);
        $P::issueIou($fo, ['date' => $D(1)->toDateString(), 'employee_id' => $emp('Deepa Nair')?->id, 'amount' => 200, 'description' => 'Printer toner for the front desk', 'due_date' => $today->copy()->addDays(2)->toDateString()]);

        // ── kitchen: vouchers, an IOU settled with an overspend, a paid top-up, an agreed count, a new top-up request
        $as($chef);
        $food = $head('6050') ?: $sys('misc_expense');
        $v($kit, 23, 'Tema Fishing Harbour – Auntie Adjoa', 'Fresh red snapper & tuna', $food, 380.00, ['why' => 'Fish sellers at the harbour give no receipts']);
        $v($kit, 21, 'Charcoal seller – Kaneshie', 'Charcoal for the grill (4 bags)', $sys('utility_fuel'), 95.00);
        $v($kit, 19, 'Goil LPG station', 'Kitchen LPG cylinder refill', $sys('utility_fuel'), 260.00, ['png' => true]);
        $v($kit, 15, 'Agbogbloshie market', 'Tomatoes, peppers, onions & garden eggs', $food, 180.00, ['why' => 'Market traders – no receipts']);
        $r1 = $P::requestReplenishment($kit, 'Weekly top-up');
        $r1->forceFill(['date' => $D(14)->toDateString()])->save();
        $as($acct);
        $P::approveReplenishment($r1->fresh());
        $P::payReplenishment($r1->fresh(), $cash->id, $D(13)->toDateString(), 'Main cash');
        $as($chef);
        $iou2 = $P::issueIou($kit, ['date' => $D(11)->toDateString(), 'employee_id' => $emp('Mohan Lal')?->id, 'amount' => 250, 'description' => 'Fresh tilapia for the Friday buffet', 'due_date' => $D(9)->toDateString()]);
        $P::settleIou($iou2->fresh('float'), ['date' => $D(10)->toDateString(), 'spent' => 275, 'expense_head_id' => $food, 'description' => 'Tilapia 11 kg (price went up)', 'no_receipt_reason' => 'Bought from the Volta fish van – no receipt']);
        $v($kit, 7, 'Knife sharpener (walk-in)', 'Sharpening chef knives', $sys('repairs'), 60.00);
        $v($kit, 4, 'Melcom', 'Detergent, sponges & bin liners', $head('6070') ?: $sys('misc_expense'), 145.00, ['vat' => true, 'png' => true, 'ref' => 'MEL-883120']);
        $c2 = $P::count($kit, $denoms($P::balance($kit)), null, $u('store')?->id);
        $c2->forceFill(['date' => $D(2)->toDateString()])->save();
        $P::requestReplenishment($kit, 'Before the weekend market run');

        // ── claims still in progress
        if ($fomEmp = $emp('Ravi Kumar')) {
            $as($fom);
            $l = $C::create(null, 'Client lunch – Ghana Chamber of Mines delegation', 'Discussed the November conference block (40 rooms)');
            $C::addLine($l, ['date' => $D(5)->toDateString(), 'category' => 'entertainment', 'amount' => 690, 'vat_amount' => 115, 'description' => 'Lunch for 4 at Buka restaurant, Osu', 'receipt_ref' => 'BUKA-7731'],
                $this->receiptPng('Buka Restaurant Osu', 'Lunch for 4', 690, $D(5)));
            $C::addLine($l, ['date' => $D(5)->toDateString(), 'category' => 'transport', 'amount' => 40, 'description' => 'Uber to and from the restaurant', 'receipt_ref' => 'UBER-9Q2K']);
            $C::submit($l);
        }
        if (($grace = $u('spa')) && $emp('Grace Mensah')) {
            $as($grace);
            $g = $C::create(null, 'Spa product training – Osu');
            $C::addLine($g, ['date' => $D(2)->toDateString(), 'category' => 'transport', 'amount' => 60, 'description' => 'Trotro & taxi to the supplier training centre', 'receipt_ref' => 'MoMo 22019']);
        }
        if (($hr = $u('hr')) && $emp('Anjali Menon')) {
            $as($hr);
            $h = $C::create(null, 'Airtime – September recruitment calls');
            $C::addLine($h, ['date' => $D(12)->toDateString(), 'category' => 'airtime', 'amount' => 120, 'description' => 'MTN airtime for calling 30 shortlisted candidates', 'receipt_ref' => 'MTN self top-up']);
            $C::submit($h);
            $as($acct);
            $C::reject($h, 'Please attach the MTN top-up SMS or statement screenshot');
        }

        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
        \App\Services\LedgerService::sync();
    }

    protected function seedBankRec(Carbon $today): void
    {
        $Rec = \App\Services\BankRecService::class;
        $Rec::ensureDefaults();
        \App\Services\LedgerService::sync();
        $bank = \App\Models\AccountHead::where('system_key', 'bank')->first();
        $momo = \App\Models\AccountHead::where('system_key', 'wallet_clearing')->first();
        if (! $bank || ! $momo) {
            return;
        }
        $to2 = $today->copy()->subDays(3);
        $from2 = $to2->copy()->startOfMonth();
        // The demo adds three transit days. During the first few days of a month,
        // today - 3 can leave a current-month statement shorter than those lines.
        // Keep the sample period valid without ever ending it in the future.
        if ($to2->lt($from2->copy()->addDays(2))) {
            $to2 = $today->copy()->subDay();
        }
        $from1 = $from2->copy()->subMonthNoOverflow();
        $to1 = $from1->copy()->endOfMonth();
        $gl = fn ($acc, $a, $b) => \App\Models\GlLine::with('journal')->where('account_head_id', $acc)->whereBetween('date', [$a, $b])->orderBy('date')->orderBy('id')->get();
        $signed = fn ($g) => round((float) $g->debit - (float) $g->credit, 2);
        $desc = function ($g) use ($signed) {
            $j = $g->journal;
            $n = strtoupper(\Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $j->narration), 34, ''));

            return match ($j->source_type) {
                'stat_remit' => 'TRF '.$n,
                'payroll_pay' => 'BULK SALARY TRF '.$j->reference,
                'ap_pay' => preg_match('/CHQ\s*(\d+)/i', $j->reference.' '.$j->narration, $m) ? 'CHQ '.$m[1].' PRESENTED' : 'GIP TRF '.$n,
                'folio_tx', 'advance' => ($signed($g) > 0 ? 'DEPOSIT ' : 'TRF ').preg_replace('/^(DEPOSIT|PAYMENT RECEIVED) – /u', '', $n),
                'gw_payout' => 'PAYSTACK SETTLEMENT '.$j->reference,
                'fx_surr' => 'FX PURCHASE CREDIT',
                default => ($signed($g) > 0 ? 'CR ' : 'DR ').$n,
            };
        };
        $ref = fn ($g) => $g->journal->source_type === 'stat_remit' ? $g->journal->reference : (preg_match('/CHQ\s*(\d+)/i', $g->journal->reference.' '.$g->journal->narration, $m) ? $m[1] : null);
        $withBalance = function (array $lines, float $open) {
            usort($lines, fn ($x, $y) => [$x['date'], $x['o'] ?? 0] <=> [$y['date'], $y['o'] ?? 0]);
            $b = $open;
            foreach ($lines as &$l) {
                $b = round($b + $l['amount'], 2);
                $l['balance'] = $b;
                unset($l['o']);
            }

            return [$lines, $b];
        };

        // ---- bank, last month: reconciled
        $f1 = $from1->toDateString();
        $open1 = $Rec::glBalance($bank->id, $from1->copy()->subDay()->toDateString());
        $transit = collect();
        $lines = [];
        $all1 = $gl($bank->id, $f1, $to1->toDateString());
        $late = $all1->count() >= 3 ? $all1->slice(-2)->pluck('id')->flip() : collect(); // the month's last two entries reach the bank next month
        foreach ($all1 as $i => $g) {
            if (isset($late[$g->id])) {
                $transit->push($g);

                continue;
            }
            $lines[] = ['date' => min($g->date->copy()->addDay(), $to1)->toDateString(), 'description' => $desc($g), 'reference' => $ref($g), 'amount' => $signed($g), 'o' => $i];
        }
        $lines[] = ['date' => $to1->toDateString(), 'description' => 'ACCOUNT MAINTENANCE FEE', 'reference' => null, 'amount' => -45.00, 'o' => 999];
        [$lines, $close1] = $withBalance($lines, $open1);
        $s1 = $Rec::create($bank->id, $f1, $to1->toDateString(), $open1, $close1, $lines, 'csv', 'GCB_statement_'.$from1->format('M_Y').'.csv', null);
        $Rec::autoMatch($s1);
        $fee = $s1->lines()->where('amount', -45)->where('status', 'unmatched')->first();
        $fee && $Rec::createEntry($fee, 'bank_charges', null, 'Monthly account maintenance fee');
        try {
            $Rec::finalise($s1->fresh());
        } catch (\RuntimeException $e) {
            // leave it in progress rather than fail the seed
        }

        // ---- bank, this month: in progress
        $lines = [];
        foreach ($transit as $i => $g) {
            $lines[] = ['date' => $from2->copy()->addDays(min($i, 2))->toDateString(), 'description' => $desc($g), 'reference' => $ref($g), 'amount' => $signed($g), 'o' => $i];
        }
        $cur = $gl($bank->id, $from2->toDateString(), $to2->copy()->subDay()->toDateString());
        $unpresented = $cur->filter(fn ($g) => $signed($g) < 0)->sortByDesc(fn ($g) => $g->date->toDateString().sprintf('%08d', $g->id))->take(2)->pluck('id')->flip();
        foreach ($cur as $i => $g) {
            if (isset($unpresented[$g->id])) {
                continue;
            }
            $lines[] = ['date' => min($g->date->copy()->addDay(), $to2)->toDateString(), 'description' => $desc($g), 'reference' => $ref($g), 'amount' => $signed($g), 'o' => 100 + $i];
        }
        $mid = $from2->copy()->addDays(min(13, $from2->diffInDays($to2)))->toDateString();
        $lines[] = ['date' => $mid, 'description' => 'TRF FROM 0244 581 902 KWAME ADJEI', 'reference' => 'MMT7781402', 'amount' => 1250.00, 'o' => 900];
        $lines[] = ['date' => $to2->toDateString(), 'description' => 'SMS ALERT & COT CHARGES', 'reference' => null, 'amount' => -38.50, 'o' => 901];
        $lines[] = ['date' => $to2->toDateString(), 'description' => 'CREDIT INTEREST', 'reference' => null, 'amount' => 112.40, 'o' => 902];
        [$lines, $close2] = $withBalance($lines, (float) ($s1->fresh()->closing_balance));
        $s2 = $Rec::create($bank->id, $from2->toDateString(), $to2->toDateString(), (float) $s1->fresh()->closing_balance, $close2, $lines, 'csv', 'GCB_statement_'.$from2->format('M_Y').'.csv', null);
        $Rec::autoMatch($s2);

        // ---- Mobile Money merchant wallet, this month: one collections line and one fee line per day
        $open3 = $Rec::glBalance($momo->id, $from2->copy()->subDay()->toDateString());
        $lines = [];
        foreach ($gl($momo->id, $from2->toDateString(), $to2->toDateString())->filter(fn ($g) => $signed($g) > 0)->groupBy(fn ($g) => $g->date->toDateString()) as $d => $day) {
            $sum = round($day->sum($signed), 2);
            $lines[] = ['date' => $d, 'description' => 'MERCHANT COLLECTIONS ('.$day->count().' TXN)', 'reference' => 'MTN'.str_replace('-', '', $d), 'amount' => $sum, 'o' => 1];
            $lines[] = ['date' => $d, 'description' => 'MERCHANT SERVICE CHARGE 1%', 'reference' => null, 'amount' => -round($sum * 0.01, 2), 'o' => 2];
        }
        if ($lines) {
            [$lines, $close3] = $withBalance($lines, $open3);
            $s3 = $Rec::create($momo->id, $from2->toDateString(), $to2->toDateString(), $open3, $close3, $lines, 'csv', 'MTN_MoMo_merchant_'.$from2->format('M_Y').'.csv', null);
            $Rec::autoMatch($s3);
            // fees of all but the last three days already booked
            foreach ($s3->lines()->where('amount', '<', 0)->where('status', 'unmatched')->where('date', '<', $to2->copy()->subDays(2)->toDateString())->get() as $l) {
                $Rec::createEntry($l, 'momo_fees', null, null);
            }
        }
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

    protected function seedUpsell(bool $rich, Models\User $user): void
    {
        if (! $rich) {
            return;
        }
        $U = \App\Services\UpsellService::class;
        $DB = \Illuminate\Support\Facades\DB::class;
        $hid = Tenant::id();
        $dirtyMax = (int) $DB::table('channel_dirty')->where('hotel_id', $hid)->max('id');
        $upToDate = Models\ChannelConnection::where('pushed_upto_id', '>=', $dirtyMax)->pluck('id');
        mt_srand(4100);
        $prevUser = auth()->user();
        $desk = Models\User::where('hotel_id', $hid)->where('username', 'frontdesk')->first() ?? $user;
        $fom = Models\User::where('hotel_id', $hid)->where('username', 'fom')->first() ?? $user;
        auth()->setUser($user);
        $rt = Models\RoomType::pluck('id', 'code');
        $bd = bdate()->copy();
        $mk = fn (array $a) => Models\UpsellOffer::create($a + ['apply_tax' => true, 'is_active' => true, 'channels' => ['pre_arrival', 'desk', 'portal'],
            'min_nights' => 1, 'lead_hours' => 0, 'incentive_type' => 'none', 'auto_confirm' => true, 'pricing' => 'per_stay', 'category' => 'misc']);
        $off = [
            'dlx' => $mk(['code' => 'UPG-DLX', 'name' => 'Upgrade to Deluxe', 'kind' => 'upgrade', 'icon' => 'layers', 'category' => 'room', 'pricing' => 'rate_difference',
                'diff_pct' => 60, 'min_price' => 900, 'to_room_type_id' => $rt['DLX'], 'room_type_ids' => [$rt['STD']], 'channels' => ['pre_arrival', 'desk'],
                'description' => 'More space, a city-view balcony, work desk and Nespresso machine – at 60% of the usual price difference.', 'incentive_type' => 'pct', 'incentive_value' => 5, 'sort' => 1]),
            'exe' => $mk(['code' => 'UPG-EXE', 'name' => 'Executive room with lounge access', 'kind' => 'upgrade', 'icon' => 'star', 'category' => 'room', 'pricing' => 'per_night', 'price' => 2500,
                'to_room_type_id' => $rt['EXE'], 'room_type_ids' => [$rt['STD'], $rt['DLX']], 'channels' => ['pre_arrival', 'desk'],
                'description' => 'Executive floor room with lounge access: evening cocktails 18:00–20:00, all-day tea & coffee and express check-out.', 'incentive_type' => 'fixed', 'incentive_value' => 200, 'sort' => 2]),
            'bf' => $mk(['code' => 'BKFST', 'name' => 'Breakfast buffet', 'kind' => 'addon', 'icon' => 'coffee', 'category' => 'fnb', 'pricing' => 'per_person_night', 'price' => 450,
                'description' => 'Full buffet in The Terrace, 07:00–10:30 – live egg station, South Indian and continental.', 'incentive_type' => 'pct', 'incentive_value' => 5, 'sort' => 3]),
            'pick' => $mk(['code' => 'PICKUP', 'name' => 'Airport pick-up (sedan)', 'kind' => 'addon', 'icon' => 'car', 'category' => 'transport', 'price' => 1800, 'daily_cap' => 6, 'lead_hours' => 12,
                'channels' => ['pre_arrival', 'desk'], 'description' => 'Our driver meets you at arrivals with a name board. Add your flight number in the note.', 'sort' => 4]),
            'early' => $mk(['code' => 'EARLY10', 'name' => 'Early check-in from 10:00', 'kind' => 'early_checkin', 'icon' => 'login', 'category' => 'room', 'price' => 1500, 'service_time' => '10:00:00',
                'auto_confirm' => false, 'channels' => ['pre_arrival', 'desk'], 'description' => 'Your room ready from 10:00 instead of 14:00.', 'incentive_type' => 'fixed', 'incentive_value' => 100, 'sort' => 5]),
            'late' => $mk(['code' => 'LATE15', 'name' => 'Late check-out until 15:00', 'kind' => 'late_checkout', 'icon' => 'moon', 'category' => 'room', 'price' => 1500, 'service_time' => '15:00:00',
                'auto_confirm' => false, 'description' => 'Keep your room until 15:00 instead of 11:00.', 'incentive_type' => 'fixed', 'incentive_value' => 100, 'sort' => 6]),
            'rom' => $mk(['code' => 'ROMANCE', 'name' => 'Romance package', 'kind' => 'addon', 'icon' => 'star', 'category' => 'misc', 'price' => 3500, 'lead_hours' => 24, 'min_nights' => 2,
                'channels' => ['pre_arrival', 'desk'], 'description' => 'Rose-petal turndown, sparkling wine, chocolate-dipped strawberries and a candle-lit dinner for two.', 'incentive_type' => 'fixed', 'incentive_value' => 250, 'sort' => 7]),
            'spa' => $mk(['code' => 'SPA2000', 'name' => 'Spa credit GH₵2,000', 'kind' => 'addon', 'icon' => 'spa', 'category' => 'spa', 'price' => 2000,
                'description' => 'Spend on any massage or treatment at Aura Spa during your stay.', 'incentive_type' => 'pct', 'incentive_value' => 10, 'sort' => 8]),
        ];

        // ── history: departed stays of the last 30 days (booked value only – the folios of the past stay untouched) ──
        $past = Models\ReservationRoom::with('reservation.guest')->where('status', 'checked_out')
            ->whereBetween('arrival_date', [$bd->copy()->subDays(30)->toDateString(), $bd->copy()->subDay()->toDateString()])->orderBy('arrival_date')->get()
            ->unique('reservation_id')->take(95);
        foreach ($past as $i => $s) {
            $r = $s->reservation;
            $arr = \Illuminate\Support\Carbon::parse($s->arrival_date->toDateString().' 14:00', config('app.timezone'));
            $pick = function (array $keys) use ($off, $s) {
                $ok = collect($keys)->map(fn ($k) => $off[$k])->filter(function ($o) use ($s) {
                    if ($o->room_type_ids && ! in_array((int) $s->room_type_id, array_map('intval', $o->room_type_ids), true)) {
                        return false;
                    }

                    return $s->nights >= $o->min_nights;
                })->values();

                return $ok->isEmpty() ? null : $ok[mt_rand(0, $ok->count() - 1)];
            };
            $order = function ($o, string $channel, $at, ?int $seller) use ($s, $U) {
                $q = $U::quote($o, $s);
                if ($q['amount'] <= 0) {
                    return;
                }
                $inc = $seller ? match ($o->incentive_type) { 'pct' => round($q['amount'] * (float) $o->incentive_value / 100, 2), 'fixed' => (float) $o->incentive_value, default => 0 } : 0;
                $status = mt_rand(1, 100) <= 90 ? 'confirmed' : ($o->isTimed() ? 'declined' : 'cancelled');
                $row = new Models\UpsellOrder(['order_no' => Numbering::next('upsell'), 'reservation_id' => $s->reservation_id, 'reservation_room_id' => $s->id,
                    'upsell_offer_id' => $o->id, 'kind' => $o->kind, 'channel' => $channel, 'status' => $status, 'quantity' => $q['qty'], 'nights' => $q['nights'],
                    'unit_price' => $q['unit'], 'amount' => $q['amount'], 'from_room_type_id' => $s->room_type_id, 'to_room_type_id' => $o->kind === 'upgrade' ? $o->to_room_type_id : null,
                    'service_date' => $U::serviceDate($o, $s), 'sold_by' => $seller, 'incentive_amount' => $status === 'confirmed' ? $inc : 0,
                    'staff_note' => $status === 'declined' ? 'Fully booked the night before – room not ready early' : ($status === 'cancelled' ? 'Guest changed plans' : null),
                    'confirmed_by' => $status === 'confirmed' ? $seller : null, 'confirmed_at' => $status === 'confirmed' ? $at : null,
                    'closed_at' => $status !== 'confirmed' ? $at->copy()->addHours(3) : null]);
                $row->created_at = $row->updated_at = $at;
                $row->save();
            };
            if ($r->guest?->email && $i % 7 !== 3) {        // pre-arrival e-mail 3 days out
                $sent = $arr->copy()->subDays(3)->setTime(9, 20)->addMinutes(mt_rand(0, 40));
                $opened = mt_rand(1, 100) <= 68 ? $sent->copy()->addHours(mt_rand(1, 30)) : null;
                $conv = $opened && mt_rand(1, 100) <= 30 ? $pick(['dlx', 'bf', 'bf', 'pick', 'early', 'rom', 'spa']) : null;
                $p = new Models\UpsellPresentation(['reservation_id' => $r->id, 'channel' => 'pre_arrival', 'offer_ids' => collect($off)->take(6)->pluck('id')->all(),
                    'sent_at' => $sent, 'opened_at' => $opened, 'open_count' => $opened ? mt_rand(1, 3) : 0, 'converted_at' => $conv ? $opened->copy()->addMinutes(6) : null]);
                $p->created_at = $p->updated_at = $sent;
                $p->save();
                $conv && $order($conv, 'pre_arrival', $opened->copy()->addMinutes(6), null);
            }
            if ($i % 5 < 2) {                                 // shown on the check-in screen
                $conv = mt_rand(1, 100) <= 35 ? $pick(['exe', 'bf', 'late', 'spa', 'late']) : null;
                if ($conv?->kind === 'upgrade') {
                    $conv = $off['bf'];                      // (a desk upgrade on arrival is a room move – keep the history simple)
                }
                $at = $arr->copy()->addMinutes(mt_rand(0, 300));
                $p = new Models\UpsellPresentation(['reservation_id' => $r->id, 'channel' => 'desk', 'offer_ids' => [$off['bf']->id, $off['late']->id, $off['spa']->id],
                    'converted_at' => $conv ? $at : null]);
                $p->created_at = $p->updated_at = $at;
                $p->save();
                $conv && $order($conv, 'desk', $at, mt_rand(0, 2) ? $desk->id : $fom->id);
            }
        }

        // ── in house: real orders through the service (fixed charges posted by the coming night audits / at check-out) ──
        $inhouse = Models\ReservationRoom::with('reservation.guest')->where('status', 'checked_in')->orderBy('id')->get();
        $try = function (callable $f) {
            try {
                return $f();
            } catch (\RuntimeException) {
                return null;
            }
        };
        auth()->setUser($desk);
        $stayOut = $inhouse->first(fn ($s) => $s->departure_date->isSameDay($bd));
        $stayOut2 = $inhouse->first(fn ($s) => $s->departure_date->gt($bd) && $s->id !== $stayOut?->id);
        $stayBf = $inhouse->first(fn ($s) => $s->departure_date->gte($bd->copy()->addDays(2)) && ! in_array($s->id, [$stayOut?->id, $stayOut2?->id]));
        $stayBf2 = $inhouse->first(fn ($s) => $s->departure_date->gt($bd) && ! in_array($s->id, [$stayOut?->id, $stayOut2?->id, $stayBf?->id]));
        if ($stayBf) {
            $U::present($stayBf->reservation, 'desk', collect([$off['bf']->id, $off['late']->id, $off['spa']->id]));
            $try(fn () => $U::place($stayBf, $off['bf'], 'desk'));
        }
        if ($stayOut) {                                         // late check-out sold at the desk this morning → "today" list
            $try(fn () => $U::place($stayOut, $off['late'], 'desk', 'Flight at 19:40'));
        }
        auth()->setUser($user);
        if ($stayBf2) {                                         // guest bought a spa credit from the portal
            $U::opened($stayBf2->reservation, 'portal', collect([$off['bf']->id, $off['late']->id, $off['spa']->id]));
            $try(fn () => $U::place($stayBf2, $off['spa'], 'portal'));
        }
        if ($stayOut2) {                                        // late check-out request waiting for the desk
            $U::opened($stayOut2->reservation, 'portal', collect([$off['late']->id, $off['spa']->id]));
            $try(fn () => $U::place($stayOut2, $off['late'], 'portal', 'Conference call until 14:00 – could we keep the room?'));
        }

        // ── upcoming arrivals ──
        $up = Models\ReservationRoom::with('reservation.guest')->where('status', 'reserved')->whereHas('reservation', fn ($q) => $q->where('status', 'confirmed'))
            ->whereBetween('arrival_date', [$bd->copy()->addDay()->toDateString(), $bd->copy()->addDays(7)->toDateString()])->orderBy('arrival_date')->orderBy('id')->get();
        $used = [];
        $take = function (callable $f) use ($up, &$used) {
            $s = $up->first(fn ($s) => ! in_array($s->reservation_id, $used, true) && $f($s));
            $s && $used[] = $s->reservation_id;

            return $s;
        };
        if ($s = $take(fn ($s) => (int) $s->room_type_id === (int) $rt['STD'] && $s->arrival_date->gt($bd->copy()->addDay()))) {
            $U::opened($s->reservation, 'pre_arrival', collect([$off['dlx']->id, $off['bf']->id]));
            $try(fn () => $U::place($s, $off['dlx'], 'pre_arrival'));
            $try(fn () => $U::place($s->fresh(), $off['bf'], 'pre_arrival'));
        }
        if ($s = $take(fn ($s) => $s->arrival_date->gt($bd->copy()->addDay()))) {
            $U::opened($s->reservation, 'pre_arrival', collect([$off['pick']->id]));
            $try(fn () => $U::place($s, $off['pick'], 'pre_arrival', 'AI 804 landing 11:05, 2 bags'));
        }
        if ($s = $take(fn ($s) => $s->nights >= 2 && $s->arrival_date->gte($bd->copy()->addDays(2)))) {
            $U::opened($s->reservation, 'pre_arrival', collect([$off['rom']->id, $off['spa']->id]));
            $try(fn () => $U::place($s, $off['rom'], 'pre_arrival', 'Our 10th anniversary – please keep it a surprise!'));
        }
        if ($s = $take(fn ($s) => $s->arrival_date->isSameDay($bd->copy()->addDay()))) {       // early check-in request waiting
            $U::opened($s->reservation, 'pre_arrival', collect([$off['early']->id]));
            $try(fn () => $U::place($s, $off['early'], 'pre_arrival', 'Landing 07:30 on the red-eye from Delhi'));
        }
        if ($s = $take(fn ($s) => $s->arrival_date->gte($bd->copy()->addDays(2)))) {           // declined early check-in
            $U::opened($s->reservation, 'pre_arrival', collect([$off['early']->id]));
            if ($o = $try(fn () => $U::place($s, $off['early'], 'pre_arrival'))) {
                auth()->setUser($fom);
                $try(fn () => $U::decline($o, 'Sold out the night before – the room cannot be ready before 14:00. Luggage storage and the lounge are yours from 08:00.'));
                auth()->setUser($user);
            }
        }
        // pre-arrival e-mails to the rest of the window, leaving the last two for the hourly run
        $due = Models\Reservation::with('guest')->where('status', 'confirmed')
            ->whereBetween('arrival_date', [$bd->copy()->addDay()->toDateString(), $bd->copy()->addDays($U::cfg()['days_before'])->toDateString()])
            ->whereHas('guest', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))->orderBy('arrival_date')->orderBy('id')->get();
        foreach ($due->slice(0, max(0, $due->count() - 2)) as $r) {
            $try(fn () => $U::sendOffers($r));
        }

        Models\ChannelConnection::whereIn('id', $upToDate)->update(['pushed_upto_id' => (int) $DB::table('channel_dirty')->where('hotel_id', $hid)->max('id')]);
        // …and what those channels hold reflects the upgrades (room types changed availability), so rate parity stays clean
        foreach (Models\ChannelConnection::whereIn('id', $upToDate)->get() as $c) {
            foreach (\App\Services\ChannelService::ari($c, $bd->copy(), 21) as $typeId => $byDate) {
                foreach ($byDate as $date => $e) {
                    Models\ChannelAriState::where('channel_connection_id', $c->id)->where('room_type_id', $typeId)->whereDate('date', $date)
                        ->where('available', '!=', $e['available'])->update(['available' => $e['available']]);
                }
            }
        }
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
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

    protected function seedBudget(bool $rich, Models\User $user): void
    {
        \App\Services\BudgetService::ensureDefaults();
        if (! $rich) {
            return;
        }
        LedgerService::sync(); // actuals for the variance snapshots
        $B = \App\Services\BudgetService::class;
        $prevUser = auth()->user();
        $h = hotel()->id;
        $accounts = Models\User::where('hotel_id', $h)->where('username', 'accounts')->first() ?? $user;
        Models\Setting::updateOrCreate(['key' => 'budget_live_from'], ['value' => bdate()->copy()->startOfMonth()->toDateString()]);
        Models\Setting::$version++;
        $fy = $B::currentFy();
        // seasonality by fiscal month (Apr … Mar)
        $season = [1 => .9, .85, .85, .9, .9, .95, 1.1, 1.2, 1.3, 1.2, 1.1, 1.0];
        // monthly amount in a "normal" month; true = follows the season. Accounts with posted activity this month are
        // re-sized from it below, so the demo variances tell a story whatever the random demo data produced.
        $plan = [
            '4020' => [160000, true], '4030' => [260000, true], '4040' => [8000, true], '4050' => [5000, true], '4060' => [12000, true],
            '4070' => [10000, false], '4090' => [3000, false], '4500' => [8000, true], '4900' => [-15000, true],
            '5010' => [60000, true], '5020' => [25000, true], '5060' => [10000, true],
            '6010' => [720000, false], '6015' => [26000, false], '6020' => [120000, false], '6030' => [10000, false], '6040' => [40000, false],
            '6070' => [10000, true], '6080' => [60000, true], '6100' => [12000, false], '6110' => [8000, false], '6120' => [8000, true],
            '6130' => [25000, true], '6140' => [5000, false], '6500' => [125000, false],
        ];
        $monthEnd = ['6010', '6015', '6500'];                    // payroll & depreciation post at month-end – keep the plan
        $calib = ['4020' => 1.6, '4030' => 0.7];                  // restaurant behind budget, banquets ahead
        $ids = Models\AccountHead::whereIn('code', array_keys($plan))->pluck('id', 'code');
        $cut = \Illuminate\Support\Carbon::parse(LedgerService::cutoff());
        $cm = $B::fyFor($cut) === $fy ? $B::monthIndex($cut) : 0;
        $elapsed = $cm ? $cut->day / $cut->daysInMonth : 0;
        $act = $cm ? $B::actuals($fy) : [];
        foreach ($plan as $code => [$amt, $seasonal]) {
            $v = (float) ($act[$ids[$code] ?? 0][$cm] ?? 0);
            if ($cm && ! in_array((string) $code, $monthEnd, true) && abs($v) >= 1000) {
                $k = $calib[$code] ?? (str_starts_with((string) $code, '4') ? 1.03 : 0.97);
                $plan[$code][0] = round($v / $elapsed / ($seasonal ? $season[$cm] : 1) * $k, -2);
            }
        }

        auth()->setUser($accounts);
        $b = $B::create(['fiscal_year' => $fy, 'name' => 'Budget '.$B::fyLabel($fy), 'method' => 'blank',
            'notes' => '24 rooms. Occupancy follows the season (monsoon low, Dec peak); ADR rises with demand; two weddings a month in season; salaries flat after the April increments.']);
        // rooms: this month's pace + 4 % occupancy at a 3 % lower rate, phased by season
        $as = $cm ? $B::actualStats($fy)[$cm] : ['sold' => 0];
        $occ0 = $as['sold'] ? $as['occupancy'] * 1.04 : 26;
        $adr0 = $as['sold'] ? $as['adr'] * .97 : 5600;
        $ref = $season[$cm ?: 6];
        $stats = [];
        foreach ($B::months($fy) as $m => $start) {
            $stats[$m] = ['rooms_available' => 24 * $start->daysInMonth, 'occupancy' => round(min(95, $occ0 * $season[$m] / $ref), 1),
                'adr' => round($adr0 * (.85 + .15 * $season[$m]) / (.85 + .15 * $ref))];
        }
        $B::saveStats($b, $stats, true);
        $grid = [];
        foreach ($plan as $code => [$amt, $seasonal]) {
            if (! isset($ids[$code])) {
                continue;
            }
            foreach (range(1, 12) as $m) {
                $grid[$ids[$code]][$m] = round($amt * ($seasonal ? $season[$m] : 1) + ($code === '6020' && $m <= 3 ? 20000 : 0));  // summer power load
            }
        }
        $B::saveGrid($b, $grid, [$ids['6010'] ?? 0 => 'Headcount 38 · 8 % increment from April', $ids['4030'] ?? 0 => 'Weddings Oct–Feb, conferences off-season']);
        $B::submit($b, 'Budget for the year – ready for review');
        auth()->setUser($user);
        $B::approve($b, 'Approved by the board');

        // this month's variances explained
        $period = bdate()->format('Y-m');
        $bva = $B::bva($b->fresh(), $B::monthIndex(bdate()));
        $row = fn ($code) => $bva['rows']->firstWhere('code', $code);
        auth()->setUser($accounts);
        foreach (['4020' => 'Restaurant covers are down while the kitchen refit runs; the à-la-carte menu relaunches on 5 Oct.',
            '4030' => 'Two corporate conferences and a wedding reception confirmed after the budget was set – banquets are ahead for the quarter.',
            '6010' => 'Salaries, PF / ESI and depreciation post at month-end with the payroll run and the depreciation run – this variance closes after month-end.'] as $code => $text) {
            if ($r = $row($code)) {
                $B::comment($b, $r['id'], $period, $text, $r['actual'], $r['budget']);
            }
        }
        auth()->setUser($user);
        $rev = $row('4010');
        $k = $bva['kpis']['month'];
        $B::comment($b, null, $period, sprintf('Room revenue %s budget (%s): occupancy %.1f %% vs %.1f %% budgeted, ADR %s vs %s. Watch F&B – the refit is costing covers.',
            ($rev['var'] ?? 0) >= 0 ? 'ahead of' : 'behind', ($rev['var_pct'] ?? 0) > 0 ? '+'.($rev['var_pct'] ?? 0).' %' : ($rev['var_pct'] ?? 0).' %',
            $k['actual']['occupancy'], $k['budget']['occupancy'], money($k['actual']['adr']), money($k['budget']['adr'])));

        // re-forecast after August, awaiting approval: banquets up, restaurant down for the rest of the year
        auth()->setUser($accounts);
        $through = bdate()->copy()->startOfMonth()->subDay();
        if ($through->gte($B::months($fy)[1])) {
            $f = $B::forecast($b->fresh(), $through->toDateString(), 'Re-forecast after '.$through->format('M Y'));
            if (isset($ids['4030'])) {
                $B::adjust($f, 'account', 15, $ids['4030']);
            }
            if (isset($ids['4020'])) {
                $B::adjust($f, 'account', -10, $ids['4020']);
            }
            $B::submit($f, 'Banquet pipeline +15 %, restaurant −10 % during the refit');
        }

        // next year's first draft
        $B::create(['fiscal_year' => $fy + 1, 'name' => 'Budget '.$B::fyLabel($fy + 1).' – first cut', 'method' => 'copy', 'source_id' => $b->id,
            'growth_income' => 8, 'growth_expense' => 6, 'notes' => 'Copied from this year: rooms +8 % (rate), costs +6 % (inflation). To be refined.']);
        $prevUser ? auth()->setUser($prevUser) : null;
    }

    /**
     * v3.5 front desk operations demo: a day and a half of log book entries across shifts (a resolved complaint, a pinned VIP
     * arrival with a follow-up, an open high-priority leak with comments whose follow-up is due, the automatic night-audit
     * hand-over, a cash-float note), wake-up calls (a daily one, one booked through the guest portal, one due in ten minutes,
     * yesterday's answered and unanswered calls with the escalation), messages and parcels (notified by SMS, a parcel on the
     * shelf, a message waiting for an arriving guest, a parcel left behind by a departed guest), traces (today, carried from
     * yesterday, tomorrow, done) and the lost & found store (valuables in the safe, items past retention, a returned and a
     * disposed item, loss reports – one with a likely match).
     */
    protected function seedFrontDesk(bool $rich, Models\User $user): void
    {
        \App\Services\FrontDeskOpsService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $prevUser = auth()->user();
        $h = hotel()->id;
        $u = fn (string $n) => Models\User::where('hotel_id', $h)->where('username', $n)->first() ?? $user;
        $desk = $u('frontdesk');
        $fom = $u('fom');
        $hk = $u('housekeeping');
        $eng = $u('engineer');
        auth()->setUser($desk);
        $bd = bdate()->copy()->startOfDay();
        $now = now();
        $inHouse = Models\ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->whereNotNull('room_id')->orderBy('id')->get()->values();
        $arrival = Models\ReservationRoom::with('guest', 'reservation.guest')->where('status', 'reserved')->where('arrival_date', '>=', $bd->toDateString())
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['confirmed', 'tentative']))->orderBy('arrival_date')->first();
        $departed = Models\ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_out')->whereNotNull('room_id')->orderByDesc('checked_out_at')->first();
        $gName = fn ($st) => \App\Services\GuestMessageService::stayGuest($st)?->full_name ?? 'Guest';
        $back = function ($model, Carbon $at, array $extra = []) {
            $model->forceFill(['created_at' => $at, 'updated_at' => $at] + $extra)->saveQuietly();

            return $model;
        };
        $L = \App\Services\LogBookService::class;

        // ── log book ──
        $y = $bd->copy()->subDay();
        $e = $L::create(['title' => 'Night audit closed for '.$y->copy()->subDay()->format('D d M Y'), 'body' => 'All rooms posted, no-shows released. Cash drawer balanced. Two early arrivals expected.', 'category' => 'handover', 'department' => 'front_office'], 'system');
        $back($e, $y->copy()->setTime(2, 10), ['business_date' => $y->toDateString(), 'shift' => 'night', 'created_by' => null]);
        if ($st = $inHouse->get(0)) {
            auth()->setUser($desk);
            $e = $L::create(['title' => 'Room '.$st->room->room_no.' – guest complained about corridor noise', 'body' => 'A group returned late and was loud near the lift. Apologised and offered a move; guest preferred to stay. Security asked to do an extra round.',
                'category' => 'complaint', 'department' => 'front_office', 'priority' => 'normal', 'reservation_room_id' => $st->id]);
            $back($e, $y->copy()->setTime(22, 40), ['business_date' => $y->toDateString(), 'shift' => 'evening']);
            auth()->setUser($fom);
            $L::resolve($e, 'Called the guest in the morning – slept well after the security round. Complimentary breakfast offered and accepted.');
            $e->forceFill(['resolved_at' => $bd->copy()->setTime(9, 15)])->saveQuietly();
            $L::markRead(collect([$e]), $fom->id);
        }
        auth()->setUser($desk);
        $e = $L::create(['title' => 'Cash float counted – GH₵10,000 handed over', 'body' => 'Float in the front-desk drawer counted with the night auditor: GH₵10,000 in notes, GH₵420 coins. Two foreign-currency envelopes in the safe (see forex position).', 'category' => 'cash']);
        $back($e, $bd->copy()->setTime(7, 5), ['shift' => 'morning']);
        $L::markRead(collect([$e]), $fom->id);
        if ($arrival) {
            auth()->setUser($fom);
            $e = $L::create(['title' => 'VIP arrival: '.$gName($arrival).' – fruit basket & GM welcome', 'body' => 'Repeat guest. Upgrade if available, welcome card signed by the GM, fruit basket and still water in the room before arrival. Inform the GM when the guest checks in.',
                'category' => 'vip', 'department' => 'all', 'priority' => 'high', 'reservation_room_id' => $arrival->id, 'needs_action' => true,
                'follow_up_at' => $bd->copy()->addDay()->setTime(12, 0)->toDateTimeString(), 'pinned' => true, 'pinned_until' => $arrival->arrival_date->toDateString()]);
            $back($e, $bd->copy()->setTime(8, 30), ['shift' => 'morning']);
        }
        if ($st = $inHouse->get(1)) {
            auth()->setUser($hk);
            $e = $L::create(['title' => 'Room '.$st->room->room_no.' – water leaking under the bathroom sink', 'body' => 'Room attendant found water on the bathroom floor. Towels placed, engineering called. Guest informed and apologised to.',
                'category' => 'maintenance', 'department' => 'engineering', 'priority' => 'high', 'reservation_room_id' => $st->id, 'follow_up_at' => $now->copy()->subMinutes(30)->toDateTimeString()], 'system');
            $back($e, $now->copy()->subHours(3), ['source' => 'manual', 'created_by' => $hk->id]);
            auth()->setUser($eng);
            $c = $L::comment($e, 'Checked – the waste pipe seal has perished. Temporary fix done, spare part expected this evening.');
            $back($c, $now->copy()->subHours(2));
            auth()->setUser($fom);
            $c = $L::comment($e, 'Please confirm once the part is fitted – offer the guest a room move if it is not done by 20:00.');
            $back($c, $now->copy()->subMinutes(80));
        }
        auth()->setUser($desk);
        $e = $L::create(['title' => 'Lift 2 out of service 14:00–16:00 (annual inspection)', 'body' => 'Please guide guests with luggage to lift 1 and the service lift.', 'category' => 'info', 'department' => 'all']);
        $back($e, $now->copy()->subMinutes(50));
        $e = $L::create(['title' => 'Unattended bag in the lobby – checked by security', 'body' => 'Grey backpack near the entrance for 20 min. Security checked CCTV – it belonged to a guest waiting for a taxi. Returned.', 'category' => 'security', 'department' => 'security']);
        $back($e, $now->copy()->subMinutes(25));

        // ── wake-up calls ──
        $W = \App\Services\WakeupService::class;
        $tomorrow = $bd->copy()->addDay();
        $staying = $inHouse->filter(fn ($st) => $st->departure_date->gt($bd))->values(); // guests still here tomorrow morning
        if ($st = $staying->get(0)) {
            if ($st->departure_date->gt($tomorrow)) {
                $W::schedule($st, $tomorrow->copy()->setTime(6, 0), true, 'Call twice – heavy sleeper', 'phone');
            } else {
                $W::schedule($st, $tomorrow->copy()->setTime(6, 0), false, 'Call twice – heavy sleeper', 'phone');
            }
            $y1 = Models\WakeupCall::create(['reservation_room_id' => $st->id, 'room_id' => $st->room_id, 'call_at' => $bd->copy()->setTime(6, 0), 'status' => 'done', 'attempts' => 1, 'source' => 'phone',
                'last_attempt_at' => $bd->copy()->setTime(6, 1), 'completed_at' => $bd->copy()->setTime(6, 1), 'completed_by' => $desk->id, 'outcome_note' => 'Answered', 'created_by' => $desk->id]);
            Models\WakeupAttempt::create(['wakeup_call_id' => $y1->id, 'outcome' => 'answered', 'user_id' => $desk->id, 'attempted_at' => $bd->copy()->setTime(6, 1)]);
        }
        if (($st = $staying->get(1)) && \App\Services\GuestPortalService::enabled()) {
            try {
                \App\Services\GuestPortalService::raise($st, ['category' => 'wakeup', 'preferred_at' => $tomorrow->copy()->setTime(5, 30)->toDateTimeString(), 'notes' => 'Early flight – please also send a pot of tea'], 'portal');
            } catch (\Throwable $ex) {
                $this->command?->warn('Portal wake-up seed skipped: '.$ex->getMessage());
            }
        }
        if ($st = $staying->get(2)) {
            $W::schedule($st, $now->copy()->addMinutes(10)->second(0), false, null, 'phone');
            $na = Models\WakeupCall::create(['reservation_room_id' => $st->id, 'room_id' => $st->room_id, 'call_at' => $bd->copy()->setTime(7, 0), 'status' => 'no_answer', 'attempts' => 3, 'source' => 'desk',
                'last_attempt_at' => $bd->copy()->setTime(7, 11), 'completed_at' => $bd->copy()->setTime(7, 11), 'completed_by' => $desk->id, 'outcome_note' => 'No answer after 3 attempts',
                'escalated_at' => $bd->copy()->setTime(7, 11), 'created_by' => $desk->id]);
            foreach ([0, 5, 11] as $m) {
                Models\WakeupAttempt::create(['wakeup_call_id' => $na->id, 'outcome' => 'no_answer', 'user_id' => $desk->id, 'attempted_at' => $bd->copy()->setTime(7, $m)]);
            }
            $e = $L::system('Room '.$st->room->room_no.' did not answer the 07:00 wake-up call – send someone to knock', 'Guest: '.$gName($st).'.', 'guest', 'urgent', ['reservation_room_id' => $st->id], true);
            $back($e, $bd->copy()->setTime(7, 11), ['shift' => 'morning']);
            auth()->setUser($fom);
            $L::resolve($e, 'Bell captain knocked at 07:15 – guest was in the shower, thanked us.');
            $e->forceFill(['resolved_at' => $bd->copy()->setTime(7, 20)])->saveQuietly();
            auth()->setUser($desk);
        }

        // ── messages & parcels ──
        $M = \App\Services\GuestMessageService::class;
        if ($st = $inHouse->get(0)) {
            $m = $M::take($st, ['kind' => 'message', 'channel' => 'phone', 'from_name' => 'Rahul Verma', 'from_company' => 'Infosys Ltd', 'from_phone' => '+91 98450 12345',
                'body' => 'Tomorrow\'s meeting moved to 11:00 at the Electronic City office. Please call back to confirm.', 'notify' => true]);
            $back($m, $now->copy()->subMinutes(95));
        }
        if ($st = $inHouse->get(1)) {
            $m = $M::take($st, ['kind' => 'parcel', 'channel' => 'courier', 'from_name' => 'Amazon', 'courier' => 'Blue Dart', 'tracking_no' => '71234598761', 'pieces' => 1,
                'storage_location' => 'Front desk – shelf 2', 'body' => 'Small box, approx. 1 kg', 'notify' => false]);
            $back($m, $now->copy()->subMinutes(40));
            $d = $M::take($st, ['kind' => 'message', 'channel' => 'visitor', 'from_name' => 'Anand (driver)', 'body' => 'Car will be at the porch at 09:30 tomorrow.', 'notify' => false]);
            $M::deliver($d, 'Handed to the guest');
            $back($d, $bd->copy()->setTime(10, 5), ['delivered_at' => $bd->copy()->setTime(10, 20)]);
        }
        if ($arrival) {
            $m = $M::take($arrival, ['kind' => 'message', 'channel' => 'email', 'from_name' => 'Kerala Holidays (travel agent)', 'from_phone' => '+91 484 234 5678',
                'body' => 'Akwaaba to Accra! Your guide Suresh will meet you in the lobby at 09:00 on the day after arrival.', 'urgent' => false]);
            $back($m, $now->copy()->subHours(5));
        }
        if ($departed) {
            $m = Models\GuestMessage::create(['message_no' => Numbering::next('guest_message'), 'kind' => 'parcel', 'reservation_room_id' => $departed->id, 'guest_id' => $departed->guest_id ?? $departed->reservation?->guest_id,
                'channel' => 'courier', 'from_name' => 'DTDC Express', 'courier' => 'DTDC', 'tracking_no' => 'D45012987', 'pieces' => 1, 'storage_location' => 'Luggage room',
                'status' => 'pending', 'taken_by' => $desk->id]);
            $back($m, $now->copy()->subHours(7));
        }

        // ── traces ──
        $T = \App\Services\TraceService::class;
        if ($st = $inHouse->get(0)) {
            $T::create(['trace_date' => $bd->toDateString(), 'due_time' => '11:00', 'department' => 'front_office', 'reservation_room_id' => $st->id, 'text' => 'Confirm late check-out until 14:00 with the guest on departure day']);
        }
        $T::create(['trace_date' => $bd->toDateString(), 'department' => 'reservations', 'text' => 'Chase the rooming list for next week\'s wedding block']);
        if ($st = $inHouse->get(2)) {
            $t = $T::create(['trace_date' => $bd->toDateString(), 'department' => 'housekeeping', 'reservation_room_id' => $st->id, 'text' => 'Extra rollaway bed and baby cot for room '.$st->room->room_no]);
            $t->forceFill(['carried_from' => $y->toDateString()])->saveQuietly();
        }
        if ($arrival) {
            $T::create(['trace_date' => $arrival->arrival_date->toDateString(), 'due_time' => '10:00', 'department' => 'concierge', 'reservation_room_id' => $arrival->id, 'text' => 'Confirm the airport pick-up time with the guest']);
        }
        $t = $T::create(['trace_date' => $bd->toDateString(), 'department' => 'accounts', 'text' => 'Collect the company PO before the TechCorp group checks out']);
        auth()->setUser($u('accounts'));
        $T::complete($t, 'PO received by e-mail and attached to the folio');
        auth()->setUser($desk);

        // ── lost & found ──
        $LF = \App\Services\LostFoundService::class;
        $rooms = Models\Room::where('is_active', true)->orderBy('room_no')->get()->values();
        $wallet = $LF::log(['item_name' => 'Black leather wallet', 'category' => 'cash', 'description' => 'Contents counted with a witness: GH₵2,300 cash, two cards (not recorded), a business card.',
            'room_id' => $departed?->room_id ?? $rooms[3]->id, 'found_date' => $bd->copy()->subDays(1)->toDateString(), 'found_by' => 'Ramesh Gowda', 'storage_location' => 'Safe – envelope 14']);
        if ($st = $inHouse->get(3) ?? $inHouse->get(0)) {
            $LF::log(['item_name' => 'Kindle e-reader in a red cover', 'category' => 'electronics', 'est_value' => 9000, 'room_id' => $st->room_id, 'location' => 'Pool deck lounger',
                'found_date' => $bd->toDateString(), 'found_by' => 'Pool attendant']);
        }
        $LF::log(['item_name' => 'Phone charger (USB-C, white)', 'category' => 'electronics', 'room_id' => $rooms[5]->id ?? null, 'found_date' => $bd->copy()->subDays(6)->toDateString(), 'found_by' => 'Ramesh Gowda']);
        $LF::log(['item_name' => 'Blue denim jacket', 'category' => 'clothing', 'location' => 'Restaurant', 'found_date' => $bd->copy()->subDays(96)->toDateString(), 'found_by' => 'Arjun Menon']);
        $LF::log(['item_name' => 'Gold ring with a small stone', 'category' => 'jewellery', 'room_id' => $rooms[2]->id ?? null, 'found_date' => $bd->copy()->subDays(190)->toDateString(), 'found_by' => 'Ramesh Gowda', 'storage_location' => 'Safe – envelope 3']);
        $sun = $LF::log(['item_name' => 'Ray-Ban sunglasses', 'category' => 'other', 'location' => 'Lobby sofa', 'found_date' => $bd->copy()->subDays(9)->toDateString(), 'found_by' => 'Priya Sharma']);
        $LF::returnItem($sun, ['returned_to' => 'Karan Malhotra', 'claimant_phone' => '+91 99001 22334', 'claimant_id_type' => 'driving', 'claimant_id_no' => 'KA0120190034567', 'return_method' => 'in_person']);
        $sun->forceFill(['returned_date' => $bd->copy()->subDays(7)->toDateString()])->saveQuietly();
        $umb = $LF::log(['item_name' => 'Black umbrella', 'category' => 'other', 'location' => 'Porch', 'found_date' => $bd->copy()->subDays(120)->toDateString(), 'found_by' => 'Doorman']);
        auth()->setUser($fom);
        $LF::dispose($umb, ['disposal_method' => 'donated', 'disposal_note' => 'Donated with 6 other umbrellas to the Asha Foundation']);
        $umb->forceFill(['disposed_date' => $bd->copy()->subDays(20)->toDateString()])->saveQuietly();
        auth()->setUser($desk);
        $LF::report(['name' => $departed ? $gName($departed) : 'Rohan Gupta', 'phone' => ($departed ? \App\Services\GuestMessageService::stayGuest($departed)?->phone : null) ?? '+91 98860 55555',
            'item' => 'Black wallet', 'category' => 'cash', 'description' => 'Leather wallet with some cash and cards', 'lost_date' => $bd->copy()->subDays(1)->toDateString(),
            'where_lost' => $departed?->room ? 'Room '.$departed->room->room_no : 'Room', 'reservation_room_id' => $departed?->id]);
        $LF::report(['name' => 'Sophie Laurent', 'email' => 'sophie.laurent@example.fr', 'item' => 'Silver bracelet', 'category' => 'jewellery', 'description' => 'Thin silver bracelet with a heart charm',
            'lost_date' => $bd->copy()->subDays(12)->toDateString(), 'where_lost' => 'Spa changing room']);

        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    /**
     * v3.1 feedback & reputation demo: case owners and review links; post-stay surveys sent to recent guests and answered
     * over the last months (promoters, passives, detractors – detractors open recovery cases, one recovered with goodwill
     * points, one overdue and escalated), one survey still open and one scheduled; a desk complaint for an in-house guest;
     * online reviews from several sites – responded, awaiting approval, ready to post, no response, and new ones in the inbox
     * (a 1★ review matched to the guest's stay opens a high-severity case).
     */
    /**
     * v3.3 transport demo: a small fleet (one with insurance due, one off the road on an expired PUC, one in the workshop),
     * five drivers (one with a login for the phone screen, one with an expired licence), two weeks of completed trips with
     * odometer readings, a cancellation and a no-show; live trips – a pick-up on the road now, assigned and unassigned
     * pick-ups later today, a guest's portal request waiting for confirmation, drops and a city tour; and a month of fuel,
     * service, toll & parking expenses. Room-billed pending trips only for stays departing tomorrow or later.
     */
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

    /** v3.2 restaurant table reservations: floor layout, service periods, 30 days of history, a live floor today and the coming week. */
    protected function seedTables(bool $rich, Models\User $user): void
    {
        $T = \App\Services\TableBookingService::class;
        $rest = Models\Outlet::where('type', 'restaurant')->orderBy('id')->first();
        if (! $rest) {
            return;
        }
        // floor layout: Indoor (booths and 6-tops) on the left, Terrace (rounds) on the right
        $pos = ['T1' => [5, 12, 'booth'], 'T2' => [25, 12, 'square'], 'T3' => [5, 42, 'rect'], 'T4' => [25, 42, 'booth'], 'T5' => [5, 72, 'square'], 'T6' => [25, 72, 'rect'],
            'T7' => [58, 12, 'round'], 'T8' => [78, 12, 'round'], 'T9' => [58, 42, 'rect'], 'T10' => [78, 42, 'round'], 'T11' => [58, 72, 'round'], 'T12' => [78, 72, 'rect']];
        foreach (Models\PosTable::where('outlet_id', $rest->id)->get() as $t) {
            [$x, $y, $shape] = $pos[$t->table_no] ?? [null, null, 'square'];
            $t->update(['pos_x' => $x, 'pos_y' => $y, 'shape' => $shape, 'min_capacity' => $t->capacity >= 6 ? 3 : 1, 'reservable' => $t->table_no !== 'T12']);
        }
        $rest->update(['tb_enabled' => true, 'tb_online' => true, 'tb_settings' => array_merge($T::DEFAULTS, ['online_note' => 'Smart casual. We hold tables for 15 minutes – please call if you are running late.'])]);
        $rest->periods()->create(['name' => 'Lunch', 'start_time' => '12:00', 'end_time' => '14:30', 'days' => '1234567']);
        $rest->periods()->create(['name' => 'Dinner', 'start_time' => '19:00', 'end_time' => '22:30', 'days' => '1234567']);
        if (! $rich) {
            return;
        }
        $tables = Models\PosTable::where('outlet_id', $rest->id)->orderBy('id')->get()->keyBy('table_no');
        $names = ['Anjali Mehta', 'Karan Malhotra', 'Sneha Reddy', 'Arvind Pillai', 'Fatima Sheikh', 'David Miller', 'Neha Joshi', 'Rahul Bose', 'Emily Clarke', 'Vikram Singh', 'Pooja Nair',
            'Samuel Mensah', 'Divya Menon', 'Arjun Kapoor', 'Lakshmi Iyer', 'Tom Becker', 'Ritu Agarwal', 'Nikhil Rao', 'Meghna Das', 'Yusuf Khan', 'Kavya Shetty', 'Oliver Grant', 'Isha Gupta', 'Manoj Kumar'];
        $phone = fn ($name) => '+91 98'.str_pad((string) (abs(crc32($name)) % 100000000), 8, '0', STR_PAD_LEFT);
        $mk = function (array $a, array $tableNos) use ($rest, $tables) {
            $r = new Models\TableReservation(array_merge(['outlet_id' => $rest->id, 'booking_no' => Numbering::next('table_booking'), 'type' => 'booking', 'source' => 'phone'], $a));
            $r->save();
            $r->forceFill(['created_at' => $a['created_at'] ?? $r->start_at->copy()->subDays(2), 'token_hash' => null])->save();
            if ($r->type !== 'walkin') {
                $r->forceFill(['token_hash' => hash('sha256', \App\Services\TableBookingService::tokenFor($r))])->save();
            }
            $r->tables()->sync($tables->toBase()->only($tableNos)->pluck('id')->all());

            return $r;
        };
        // ---- history: 30 days of lunch & dinner; a per-day allocator keeps tables from overlapping
        mt_srand(2302);
        $regular = ['name' => 'Anjali Mehta', 'phone' => $phone('Anjali Mehta')];
        $flaky = ['name' => 'Rohit Verma', 'phone' => '+91 9876500011'];
        for ($d = 30; $d >= 1; $d--) {
            $day = now()->subDays($d)->startOfDay();
            $busy = [];
            $plan = [];
            foreach ([[12, 14], [19, 22]] as [$h1, $h2]) {
                for ($i = 0, $n = mt_rand($h1 === 12 ? 2 : 4, $h1 === 12 ? 4 : 8); $i < $n; $i++) {
                    $plan[] = $day->copy()->setTime(mt_rand($h1, $h2), [0, 15, 30, 45][mt_rand(0, 3)]);
                }
            }
            usort($plan, fn ($a, $b) => $a <=> $b);
            foreach ($plan as $k => $start) {
                $party = [2, 2, 2, 3, 4, 4, 4, 5, 6, 2, 8][mt_rand(0, 10)];
                $dur = $T::durationFor($rest, $party);
                $end = $start->copy()->addMinutes($dur);
                $pick = null;
                foreach ($tables->shuffle()->sortBy('capacity') as $t) {   // best fit, random among equals (shuffle re-indexes → use table_no)
                    $no = $t->table_no;
                    if ($t->reservable && $t->capacity >= $party && $t->min_capacity <= $party && collect($busy[$no] ?? [])->every(fn ($iv) => $end->lte($iv[0]) || $start->gte($iv[1]))) {
                        $pick = [$no];
                        break;
                    }
                }
                if (! $pick && $party > 6 && collect(['T9', 'T10'])->every(fn ($no) => collect($busy[$no] ?? [])->every(fn ($iv) => $end->lte($iv[0]) || $start->gte($iv[1])))) {
                    $pick = ['T9', 'T10'];
                }
                if (! $pick) {
                    continue;
                }
                $type = mt_rand(1, 100) <= 22 ? (mt_rand(0, 2) ? 'walkin' : 'waitlist') : 'booking';
                $roll = mt_rand(1, 100);
                $status = $type === 'booking' ? ($roll <= 7 ? 'no_show' : ($roll <= 16 ? 'cancelled' : 'completed')) : ($type === 'waitlist' && $roll <= 20 ? 'left' : 'completed');
                $who = ['name' => $names[mt_rand(0, count($names) - 1)]];
                if ($type === 'booking' && $d % 5 === 0 && $k === 0) {
                    $who = $regular;
                }
                $who['phone'] ??= $phone($who['name']);
                $late = $status === 'completed' && $type === 'booking' ? mt_rand(-5, 20) : 0;
                $seated = $status === 'completed' ? $start->copy()->addMinutes(max(0, $late) + ($type === 'waitlist' ? mt_rand(8, 35) : 0)) : null;
                $done = $seated ? $seated->copy()->addMinutes((int) round($dur * mt_rand(70, 115) / 100)) : null;
                $mk([
                    'type' => $type, 'status' => $status, 'name' => $type === 'walkin' && mt_rand(0, 1) ? 'Walk-in' : $who['name'], 'phone' => $type === 'walkin' ? null : $who['phone'],
                    'party_size' => $party, 'date' => $start->toDateString(), 'start_at' => $start, 'end_at' => $end, 'duration' => $dur,
                    'source' => $type === 'booking' ? ['phone', 'phone', 'online', 'online', 'concierge', 'email'][mt_rand(0, 5)] : 'walkin',
                    'occasion' => $type === 'booking' && mt_rand(1, 8) === 1 ? ['birthday', 'anniversary', 'business'][mt_rand(0, 2)] : null,
                    'quoted_minutes' => $type === 'waitlist' ? [10, 15, 20, 25][mt_rand(0, 3)] : null,
                    'confirmed_at' => $type === 'booking' ? $start->copy()->subDays(1) : null, 'seated_at' => $seated, 'completed_at' => $done,
                    'cancelled_at' => in_array($status, ['cancelled', 'left'], true) ? $start->copy()->subHours($status === 'left' ? 0 : mt_rand(2, 30)) : null,
                    'cancel_reason' => $status === 'cancelled' ? ['Change of plans', 'Guest called – unwell', 'Cancelled by guest online', 'Meeting moved'][mt_rand(0, 3)] : ($status === 'left' ? 'Left without being seated' : null),
                    'no_show_at' => $status === 'no_show' ? $start->copy()->addMinutes(15) : null,
                    'created_at' => $type === 'booking' ? $start->copy()->subHours(mt_rand(3, 96)) : $start,
                ], $status === 'completed' ? $pick : ($type === 'booking' ? $pick : []));
                if (in_array($status, ['completed'], true) || ($type === 'booking' && $status !== 'cancelled')) {
                    foreach ($pick as $no) {
                        $busy[$no][] = [$start->copy()->subMinutes(10), ($done ?? $end)->copy()->addMinutes(10)];
                    }
                }
            }
        }
        mt_srand();
        // a repeat no-show: two missed dinners (flagged when he books again)
        foreach ([21, 9] as $d) {
            $start = now()->subDays($d)->setTime(19, 30);
            $mk(['status' => 'no_show', 'name' => $flaky['name'], 'phone' => $flaky['phone'], 'party_size' => 2, 'date' => $start->toDateString(), 'start_at' => $start,
                'end_at' => $start->copy()->addMinutes(90), 'duration' => 90, 'confirmed_at' => $start->copy()->subDay(), 'no_show_at' => $start->copy()->addMinutes(15)], ['T12']);
        }
        // link the seeded settled dine-in bills to completed parties of the same day (table, covers, times follow the booking)
        foreach (Models\PosOrder::where('outlet_id', $rest->id)->where('order_type', 'dine_in')->where('status', 'settled')->orderBy('id')->get() as $po) {
            $r = Models\TableReservation::with('tables')->where('outlet_id', $rest->id)->where('status', 'completed')->whereNull('pos_order_id')
                ->whereDate('date', $po->business_date->toDateString())->has('tables')->orderBy('start_at')->first();
            if ($r) {
                $r->forceFill(['pos_order_id' => $po->id])->save();
                $po->forceFill(['pos_table_id' => $r->tables->first()->id, 'covers' => $r->party_size, 'customer_name' => $po->customer_name ?: ($r->name === 'Walk-in' ? null : $r->name),
                    'created_at' => $r->seated_at, 'settled_at' => $r->completed_at])->saveQuietly();
            }
        }

        // ---- today, relative to the clock so the floor plan is always "live"
        $now = now()->startOfMinute();
        $link = function (string $no, array $a) use ($tables, $mk, $now) {
            $order = Models\PosOrder::where('pos_table_id', $tables[$no]->id)->whereIn('status', ['open', 'billed'])->first();
            $seatedAt = $a['seated_at'];
            $r = $mk($a + ['status' => 'seated', 'date' => $now->toDateString(), 'pos_order_id' => $order?->id, 'created_at' => $a['created_at'] ?? $seatedAt->copy()->subHours(5)], [$no]);
            if ($order) {
                $order->forceFill(['created_at' => $seatedAt, 'customer_name' => $order->customer_name ?: $a['name'], 'covers' => $a['party_size']])->saveQuietly();
            }

            return $r;
        };
        $dur = fn ($p) => $T::durationFor($rest, $p);
        $link('T1', ['name' => $regular['name'], 'phone' => $regular['phone'], 'party_size' => 3, 'start_at' => $now->copy()->subMinutes(55), 'seated_at' => $now->copy()->subMinutes(50),
            'end_at' => $now->copy()->subMinutes(50)->addMinutes($dur(3)), 'duration' => $dur(3), 'occasion' => 'business', 'confirmed_at' => $now->copy()->subDay(), 'notes' => 'Regular – prefers the corner booth.']);
        $link('T2', ['type' => 'walkin', 'source' => 'walkin', 'name' => 'Walk-in', 'party_size' => 2, 'start_at' => $now->copy()->subMinutes(25), 'seated_at' => $now->copy()->subMinutes(25),
            'end_at' => $now->copy()->subMinutes(25)->addMinutes($dur(2)), 'duration' => $dur(2)]);
        // a large party on joined tables T9 + T10, seated through the service (opens its own POS order)
        $rao = $mk(['name' => 'Srinivas Rao', 'phone' => $phone('Srinivas Rao'), 'party_size' => 8, 'status' => 'confirmed', 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes(35),
            'end_at' => $now->copy()->subMinutes(35)->addMinutes($dur(8)), 'duration' => $dur(8), 'occasion' => 'birthday', 'tags' => ['high_chair'], 'notes' => 'Grandfather\'s 80th – cake at 9 pm, candles please.',
            'confirmed_at' => $now->copy()->subDays(3), 'created_at' => $now->copy()->subDays(4)], ['T9', 'T10']);
        $order = $T::seat($rao);
        $rao->forceFill(['seated_at' => $now->copy()->subMinutes(30), 'end_at' => $now->copy()->subMinutes(30)->addMinutes($dur(8))])->save();
        $order->forceFill(['created_at' => $now->copy()->subMinutes(30), 'steward' => 'Sunil'])->saveQuietly();
        $kot = Numbering::next('kot');
        foreach (Models\MenuItem::where('is_available', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $rest->id))->orderBy('id')->limit(5)->get() as $it) {
            $order->items()->create(['menu_item_id' => $it->id, 'item_name' => $it->name, 'qty' => 2, 'rate' => $it->price, 'tax_rate' => $it->tax_rate, 'amount' => 2 * $it->price, 'kot_no' => $kot])->forceFill(['kitchen_status' => 'served'])->save();
        }
        $order->recalculate();
        // T6 just finished (bill settled) – waiting to be cleared; T11 out of service
        $mk(['name' => 'Neha Joshi', 'phone' => $phone('Neha Joshi'), 'party_size' => 5, 'status' => 'completed', 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes(110),
            'end_at' => $now->copy()->subMinutes(110)->addMinutes($dur(5)), 'duration' => $dur(5), 'seated_at' => $now->copy()->subMinutes(105), 'completed_at' => $now->copy()->subMinutes(6),
            'confirmed_at' => $now->copy()->subDay()], ['T6']);
        $tables['T6']->update(['needs_clearing' => true]);
        $tables['T11']->update(['blocked_reason' => 'Umbrella broken – repair booked']);
        // late & arriving
        $mk(['name' => 'Arjun Kapoor', 'phone' => $phone('Arjun Kapoor'), 'party_size' => 2, 'status' => 'confirmed', 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes(10),
            'end_at' => $now->copy()->subMinutes(10)->addMinutes($dur(2)), 'duration' => $dur(2), 'source' => 'online', 'confirmed_at' => $now->copy()->subHours(6), 'created_at' => $now->copy()->subHours(6)], ['T8']);
        auth()->setUser($user);
        $book = fn (array $a, string $src = 'phone') => $T::book($rest, $a, $src);
        $at = fn (int $min) => $now->copy()->addMinutes($min);
        $sharma = $book(['date' => $at(25)->toDateString(), 'time' => $at(25)->format('H:i'), 'party_size' => 4, 'name' => 'Priyanka Sharma', 'phone' => $phone('Priyanka Sharma'), 'email' => 'priyanka.sharma@mail.example',
            'occasion' => 'birthday', 'tags' => ['high_chair'], 'notes' => 'Daughter turns 5 – small candle on dessert.', 'table_ids' => [$tables['T4']->id]]);
        $book(['date' => $at(40)->toDateString(), 'time' => $at(40)->format('H:i'), 'party_size' => 2, 'name' => $flaky['name'], 'phone' => $flaky['phone'], 'table_ids' => [$tables['T7']->id]]);
        $book(['date' => $at(80)->toDateString(), 'time' => $at(80)->format('H:i'), 'party_size' => 6, 'name' => 'Clara Fernandes', 'phone' => $phone('Clara Fernandes'), 'occasion' => 'anniversary',
            'tags' => ['window', 'quiet'], 'table_ids' => [$tables['T3']->id]]);
        $inHouse = Models\ReservationRoom::with('reservation.guest', 'room')->where('status', 'checked_in')->orderBy('id')->first();
        if ($inHouse) {
            $book(['date' => $at(105)->toDateString(), 'time' => $at(105)->format('H:i'), 'party_size' => 2, 'reservation_room_id' => $inHouse->id, 'notes' => 'In-house guest – charge to room.'], 'concierge');
        }
        $book(['date' => $at(130)->toDateString(), 'time' => $at(130)->format('H:i'), 'party_size' => 2, 'name' => 'Emily Clarke', 'phone' => $phone('Emily Clarke'), 'email' => 'emily.clarke@mail.example'], 'staff')
            ->forceFill(['status' => 'booked', 'confirmed_at' => null, 'source' => 'online'])->save();
        // waitlist
        foreach ([['Kofi Boateng', 4, 12, 15, null, 'Happy to sit on the terrace'], ['Lisa Chen', 2, 4, 10, '+91 9811122233', null]] as [$n, $p, $ago, $q, $ph, $note]) {
            $w = Models\TableReservation::create(['outlet_id' => $rest->id, 'booking_no' => Numbering::next('table_booking'), 'type' => 'waitlist', 'status' => 'waiting', 'name' => $n, 'phone' => $ph,
                'party_size' => $p, 'date' => $now->toDateString(), 'start_at' => $now->copy()->subMinutes($ago), 'end_at' => $now->copy()->subMinutes($ago)->addMinutes($dur($p)), 'duration' => $dur($p),
                'source' => 'walkin', 'quoted_minutes' => $q, 'notes' => $note, 'created_by' => $user->id]);
            $w->forceFill(['created_at' => $now->copy()->subMinutes($ago)])->save();
        }
        // ---- the coming week
        mt_srand(2303);
        for ($d = 1; $d <= 7; $d++) {
            $day = now()->addDays($d)->startOfDay();
            for ($i = 0, $n = mt_rand(3, 7); $i < $n; $i++) {
                $start = $day->copy()->setTime([12, 13, 19, 20, 20, 21][mt_rand(0, 5)], [0, 15, 30, 45][mt_rand(0, 3)]);
                $nm = $names[mt_rand(0, count($names) - 1)];
                try {
                    $book(['date' => $start->toDateString(), 'time' => $start->format('H:i'), 'party_size' => [2, 2, 3, 4, 4, 6][mt_rand(0, 5)], 'name' => $nm, 'phone' => $phone($nm),
                        'occasion' => mt_rand(1, 6) === 1 ? 'anniversary' : null, 'notes' => mt_rand(1, 7) === 1 ? 'Nut allergy (one guest).' : null], ['phone', 'online', 'online', 'email'][mt_rand(0, 3)]);
                } catch (\Illuminate\Validation\ValidationException) {
                    // slot full – skip
                }
            }
        }
        mt_srand();
        $tom = Models\TableReservation::where('outlet_id', $rest->id)->whereDate('date', now()->addDay()->toDateString())->where('source', 'online')->orderBy('start_at')->first();
        $tom?->forceFill(['status' => 'booked', 'confirmed_at' => null])->save();
        auth()->forgetUser();
    }

    protected function seedReputation(bool $rich, Carbon $today, Models\User $user): void
    {
        $F = \App\Services\FeedbackService::class;
        $C = \App\Services\CaseService::class;
        $R = \App\Services\ReviewService::class;
        $F::ensureDefaults();
        $F::backfill();
        if (! $rich) {
            return;
        }
        $u = fn ($name) => Models\User::where('hotel_id', \App\Support\Tenant::id())->where('username', $name)->first() ?? $user;
        [$fom, $hk, $eng, $mkt] = [$u('fom'), $u('housekeeping'), $u('engineer'), $u('marketing')];
        foreach (['fb_owners' => json_encode(['overall' => $fom->id, 'front_office' => $fom->id, 'housekeeping' => $hk->id, 'room' => $hk->id, 'maintenance' => $eng->id, 'fnb' => $fom->id]),
            'fb_review_link_google' => 'https://g.page/r/nowills-grand/review', 'fb_review_link_tripadvisor' => 'https://www.tripadvisor.com/UserReviewEdit-g297628-d0000000-Nowills_Grand.html',
            'fb_signature' => 'Priya Nair, Guest Relations Manager'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;

        $survey = $F::defaultSurvey();
        $qs = $survey->activeQuestions()->get();
        $qid = fn ($type, $dept = null) => $qs->first(fn ($q) => $q->type === $type && ($dept === null || $q->department === $dept))?->id;
        // [nps, overall, front, hk, room, fnb, value, again, purpose, text, consent]
        $answers = [
            [10, 5, 5, 5, 5, 5, 5, 1, 'Leisure', 'Wonderful stay – the staff were so friendly and the breakfast was delicious.', true],
            [9, 5, 5, 4, 5, 4, 4, 1, 'Business', 'Great location, clean and spacious room. Check-in was quick.', true],
            [3, 2, 3, 1, 3, 3, 2, 0, 'Leisure', 'The bathroom was dirty and the towels were stained. Very disappointing for the price.', false],
            [7, 4, 4, 4, 3, 4, 3, 1, 'Business', 'Good room but the wifi was slow in the evening.', false],
            [10, 5, 5, 5, 5, 4, 5, 1, 'Wedding or event', 'Excellent service for our family function, thank you to the banquet team!', true],
            [8, 4, 5, 4, 4, 3, 4, 1, 'Business', 'Comfortable bed. Breakfast could have more variety.', false],
            [9, 5, 4, 5, 5, 5, 4, 1, 'Leisure', 'Lovely rooftop and very helpful concierge.', true],
            [2, 1, 1, 3, 2, 3, 1, 0, 'Business', 'Found a cockroach in the room and the front desk staff were rude when I complained. Check-out took 40 minutes.', false],
        ];
        $res = Models\Reservation::with('guest')->where('status', 'checked_out')->whereNotNull('guest_id')
            ->where('departure_date', '<', $today->toDateString())->where('departure_date', '>=', $today->copy()->subMonths(6)->toDateString())
            ->whereHas('guest', fn ($q) => $q->whereNull('privacy_status')->where('email', 'like', '%@%'))
            ->orderBy('departure_date')->get()->unique('guest_id')->values();
        // spread the answered surveys over the six months, oldest first; the two most recent stays: one open, one scheduled
        $pick = $res->count() > 11 ? collect(range(0, 7))->map(fn ($i) => $res[(int) floor($i * ($res->count() - 5) / 7)]) : $res->take(8);
        $recoveredCase = null;
        foreach ($pick as $i => $r) {
            $a = $answers[$i % count($answers)];
            $inv = $F::scheduleFor($r, true, $survey);
            if (! $inv || ! $inv->isOpen()) {
                continue;
            }
            $input = array_filter([
                'q'.$qid('nps') => $a[0], 'q'.$qid('rating', 'overall') => $a[1], 'q'.$qid('rating', 'front_office') => $a[2], 'q'.$qid('rating', 'housekeeping') => $a[3],
                'q'.$qid('rating', 'room') => $a[4], 'q'.$qid('rating', 'fnb') => $a[5], 'q'.$qid('rating', 'value') => $a[6], 'q'.$qid('yesno') => $a[7],
                'q'.$qid('choice') => $a[8], 'q'.$qid('text') => $a[9], 'consent_publish' => $a[10] ? 1 : 0,
            ], fn ($v, $k) => $k !== 'q', ARRAY_FILTER_USE_BOTH);
            $fb = $F::submit($inv, $input);
            $at = Carbon::parse($r->departure_date)->setTime(10, 0)->addHours(4);
            $inv->forceFill(['send_at' => $at, 'sent_at' => $at, 'opened_at' => $at->copy()->addHours(3), 'completed_at' => $at->copy()->addHours(3)->addMinutes(2),
                'expires_at' => $at->copy()->addDays($survey->expiry_days), 'created_at' => $at, 'updated_at' => $at])->save();
            $fb->forceFill(['created_at' => $at->copy()->addHours(3), 'updated_at' => $at->copy()->addHours(3)])->save();
            if ($i < 6 && $fb->status === 'new') {
                $fb->forceFill(['status' => 'reviewed', 'reviewed_by' => $fom->id, 'reviewed_at' => $at->copy()->addDay()])->save();
            }
            if ($case = $fb->recoveryCase()->first()) {
                $case->forceFill(['created_at' => $fb->created_at, 'due_at' => $fb->created_at->copy()->addHours($C::slaHours($case->severity))])->save();
                $case->events()->update(['created_at' => $fb->created_at]);
                $ev = $case->events()->where('type', 'opened')->first();
                $ev?->forceFill(['note' => preg_replace('/resolve by [0-9]{2} [A-Za-z]{3} [0-9:]{5}/', 'resolve by '.$case->due_at->format('d M H:i'), (string) $ev->note)])->save();
                if ($i < 6) { // older detractor: called, apologised, goodwill points, guest happy again
                    Auth::setUser($hk);
                    $C::start($case, 'Checked the room history – linen batch issue that week.');
                    $C::contact($case, 'email', 'Your recent stay', "Dear {$r->guest->first_name},\n\nThank you for telling us about the bathroom. I am sorry – we have retrained the team and changed our linen checks.\n\nAs an apology we have added 1,500 points to your loyalty account.");
                    $C::resolve($case, 'Apologised by e-mail and phone; linen supplier batch replaced; room re-inspected by the supervisor.', 'goodwill_points', 1500);
                    $C::close($case, 'recovered', 'Guest replied thanking us and has booked again for next month.');
                    $case->events()->update(['created_at' => $fb->created_at->copy()->addHours(3)]);
                    $case->forceFill(['first_response_at' => $fb->created_at->copy()->addHours(2), 'resolved_at' => $fb->created_at->copy()->addHours(3)])->save();
                    $recoveredCase = $case;
                    Auth::setUser($user);
                }
            }
        }
        // the latest detractor's case is overdue → escalated by the scheduler
        $late = Models\FeedbackCase::whereIn('status', ['open', 'in_progress'])->where('source', 'feedback')->latest('id')->first();
        if ($late) {
            $late->forceFill(['created_at' => now()->subHours(30), 'due_at' => now()->subHours(26)])->save();
            $late->events()->update(['created_at' => now()->subHours(30)]);
            $ev = $late->events()->where('type', 'opened')->first();
            $ev?->forceFill(['note' => preg_replace('/resolve by [0-9]{2} [A-Za-z]{3} [0-9:]{5}/', 'resolve by '.$late->due_at->format('d M H:i'), (string) $ev->note)])->save();
            $C::escalateOverdue();
        }
        $rest = $res->slice(-3)->values();
        if ($rest->count() === 3) {
            $open = $F::scheduleFor($rest[0], true, $survey); // sent, not answered yet
            $open?->forceFill(['opened_at' => now()->subHours(5), 'status' => $open->status === 'sent' ? 'opened' : $open->status])->save();
            $sched = $F::scheduleFor($rest[1], false, $survey); // waiting for the send delay
            $sched?->forceFill(['send_at' => now()->addHours(3)])->save();
        }
        // a complaint at the desk for an in-house guest
        $stay = Models\ReservationRoom::with('reservation.guest')->where('status', 'checked_in')->orderBy('id')->skip(1)->first();
        if ($stay) {
            Auth::setUser($u('frontdesk'));
            $C::manual(['department' => 'maintenance', 'severity' => 'high', 'in_house' => true, 'title' => 'AC not cooling in room '.($stay->room?->room_no ?? ''),
                'details' => 'Guest called the desk at 11 pm – room too warm, AC blowing warm air. Offered a fan meanwhile.'], $stay->reservation->guest, $stay->reservation_id);
            Auth::setUser($user);
        }

        // ---- online reviews
        $matchGuest = $res->count() > 11 ? $res[$res->count() - 4]->guest : null; // not surveyed – matched by name to the stay
        $reviews = [
            ['google', 'Arjun Mehta', 'Pune', 5, 5, 'Perfect business stay', 'Excellent service, very friendly staff and a clean, comfortable room. The breakfast buffet was delicious.', 'business', 70, 'posted'],
            ['tripadvisor', 'Sophie Laurent', 'Lyon, France', 5, 5, 'Lovely rooftop and helpful concierge', 'We loved the rooftop bar and the concierge helped us plan every day. Spacious room with a great view.', 'couple', 55, 'posted'],
            ['booking', 'Karan Shah', 'Mumbai', 9, 10, 'Superb', 'Great location, quick check-in, very clean.', 'business', 40, 'posted'],
            ['tripadvisor', 'David Miller', 'London, UK', 2, 5, 'Noisy room and slow check-in', 'Check-in took ages and our room faced the road – very noisy all night. The breakfast was good though.', 'couple', 12, 'pending'],
            ['google', 'Ananya Rao', 'Bengaluru', 4, 5, null, 'Good food at the restaurant and friendly staff. Wifi was a bit slow.', 'family', 9, 'approved'],
            ['expedia', 'J. Smith', null, 4, 5, 'Nice', 'Nice hotel.', 'solo', 20, 'ignored'],
            ['makemytrip', 'Rohit Verma', 'Delhi', 3, 5, 'Average', 'Room was ok but the bathroom smelled and housekeeping missed our room one day.', 'family', 5, 'new'],
            ['booking', 'Meera Pillai', 'Kochi', 8.5, 10, 'Very good', 'Comfortable bed and helpful front desk. Parking was a little tight.', 'couple', 3, 'new'],
            ['agoda', 'Tan Wei Ming', 'Singapore', 4.5, 5, 'Would stay again', 'Clean room, good value for money, excellent location near MG Road.', 'business', 2, 'new'],
        ];
        if ($matchGuest) {
            $reviews[] = ['google', trim($matchGuest->first_name.' '.$matchGuest->last_name), null, 1, 5, 'Very disappointed', 'Our room was dirty, the AC was broken and nobody came to fix it despite three calls. The manager was unhelpful. Never again.', 'business', 1, 'new'];
        }
        foreach ($reviews as $n => [$src, $name, $loc, $rating, $scale, $title, $body, $trip, $ago, $status]) {
            Auth::setUser($mkt);
            [$rv] = $R::store(['source' => $src, 'external_id' => strtoupper($src).'-'.(48213 + $n * 37), 'reviewer_name' => $name, 'reviewer_location' => $loc,
                'review_date' => $today->copy()->subDays($ago)->toDateString(), 'rating' => $rating, 'rating_scale' => $scale, 'title' => $title, 'body' => $body, 'trip_type' => $trip,
                'url' => $src === 'google' ? 'https://maps.google.com/?cid=4821'.$n : null]);
            $at = $today->copy()->subDays($ago)->setTime(9, 30);
            $rv->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            $tpl = $R::suggestedTemplate($rv);
            $text = $tpl ? $R::render($tpl, $rv) : 'Dear '.$name.', thank you for your review.';
            if (in_array($status, ['posted', 'approved', 'pending'])) {
                $R::submit($rv, $text);
                $rv->forceFill(['drafted_at' => $at->copy()->addHours(5)] + ($status !== 'pending' ? ['approved_at' => $at->copy()->addHours(5)] : []))->save();
                if ($status === 'pending') {
                    $rv->recoveryCase?->forceFill(['created_at' => $at, 'due_at' => $at->copy()->addHours(24), 'status' => 'resolved', 'resolution' => 'Guest moved to a quiet courtyard room on the second night; front-desk staffing at peak check-in reviewed.', 'recovery_action' => 'room_move', 'resolved_by' => $fom->id, 'resolved_at' => $at->copy()->addHours(6), 'first_response_at' => $at->copy()->addHours(2)])->save();
                }
                if ($status === 'posted') {
                    $R::markPosted($rv->fresh());
                    $rv->forceFill(['drafted_at' => $at->copy()->addHours(5), 'approved_at' => $at->copy()->addHours(5), 'posted_at' => $at->copy()->addHours(6)])->save();
                }
            } elseif ($status === 'ignored') {
                $R::ignore($rv, 'Too short to respond to');
            }
        }
        Auth::setUser($user);
    }

    /**
     * v2.8 channel manager: Booking.com (channel API, parity rates, hotel collect) with a festival stop-sell and a weekend
     * min-stay; Expedia (channel collect, +5% markup) whose endpoint is currently down – failing with retry back-off;
     * a built-in test channel (simulator) and MakeMyTrip paused. Bookings arrive through the real inbound path
     * (new / modify / cancel), plus one unmapped-room message in the inbox and one rejected (wrong currency).
     */
    /**
     * v4.3 GRA VAT demo: E-VAT on (sandbox VSDC), every issued invoice dated, buyer-snapshotted and stamped (back-dated
     * SDC times), one Yatra invoice rejected (the hotel's own TIN keyed on the company profile), an invoice cancelled
     * before stamping and re-issued to the right company, a B2B rate-correction credit note to the company account
     * (E-VAT refund), a B2C refund credit note, and the earlier months' VAT returns marked as filed.
     */
    protected function seedGst(bool $rich, Carbon $today, Models\User $user): void
    {
        $G = \App\Services\GstService::class;
        $E = \App\Services\EInvoiceService::class;
        $G::ensureDefaults();
        if (! $rich) {
            $G::backfill();

            return;
        }
        $set = function (array $kv) {
            foreach ($kv as $k => $v) {
                Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
            }
            Models\Setting::$version++;
        };
        $set(['gst_einvoice_enabled' => '1', 'gst_einvoice_driver' => 'sandbox', 'gst_einvoice_auto' => '1']);
        // invoices issued while seeding: date them by when they were closed, freeze the buyer
        Models\Folio::whereNotNull('invoice_no')->update(['invoice_date' => null]);
        $G::backfill();
        // past invoices were stamped by E-VAT when issued (invoices before their credit notes)
        foreach (Models\Folio::where('status', 'closed')->whereNotNull('invoice_no')->whereNull('irn_status')->orderByRaw("type = 'credit_note'")->orderBy('invoice_date')->orderBy('id')->get() as $f) {
            if ($E::generate($f, $user->id)) {
                $at = $f->invoice_date->copy()->setTime(12, 5)->addMinutes($f->id % 50);
                $f->forceFill(['irn_ack_at' => $at])->save();
                Models\EinvoiceLog::where('folio_id', $f->id)->update(['created_at' => $at]);
            }
        }
        $comp = fn (string $n) => Models\Company::where('name', $n)->first();
        $guest = fn (string $f) => Models\Guest::where('first_name', $f)->orderBy('id')->first();
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');

        // Yatra – the hotel's own TIN was keyed on the company profile: E-VAT refuses the invoice until the profile is fixed
        $yatra = $comp('Yatra Travels');
        if ($yatra && ($g = $guest('Aisha'))) {
            $yatra->update(['tax_number' => $G::hotelTin()]);
            $f = FolioService::openStandalone(['guest_id' => $g->id, 'company_id' => $yatra->id, 'type' => 'walk_in']);
            FolioService::charge($f, 'banquet', 'Conference room – half day (Yatra partner meet)', 18000);
            FolioService::charge($f, 'fnb', 'Working lunch – 20 pax', 9000);
            FolioService::payment($f, $f->balance(), $bank, 'GHIPSS YTR-2291');
            FolioService::close($f->fresh());
        }

        // billed to the wrong company → cancelled before E-VAT stamping, folio re-opened and re-issued to TCS
        $inf = $comp('Infosys Technologies');
        $tcs = $comp('Tata Consultancy Services');
        if ($inf && $tcs && ($g = $guest('Kavita'))) {
            $set(['gst_einvoice_auto' => '0']);
            $f = FolioService::openStandalone(['guest_id' => $g->id, 'company_id' => $inf->id, 'type' => 'walk_in']);
            FolioService::charge($f, 'banquet', 'Board room – full day', 24000);
            FolioService::charge($f, 'fnb', 'High tea – 12 pax', 4800);
            FolioService::payment($f, $f->balance(), $bank, 'GHIPSS TCS-88120');
            FolioService::close($f->fresh());
            $G::cancel($f->fresh(), '2', 'Billed to Infosys instead of TCS', $user->id);
            $set(['gst_einvoice_auto' => '1']);
            $f = $f->fresh();
            $f->update(['company_id' => $tcs->id]);
            FolioService::close($f->fresh());
        }

        // B2B credit note: contract rate applied late on Infosys' latest invoice – credited to the company account
        $inv = Models\Folio::where('company_id', $inf?->id)->where('supply_type', 'B2B')->where('status', 'closed')->where('type', '!=', 'credit_note')
            ->where('irn_status', 'generated')->where('invoice_date', '>=', $today->copy()->startOfMonth()->toDateString())->orderByDesc('invoice_date')->first();
        if ($inv && ($line = $G::creditable($inv)->sortByDesc('amount')->first())) {
            $G::issueCreditNote($inv, [$line->id => round(min((float) $line->amount, 12000) * 0.1, 2)], 'rate', 'Corporate contract rate (10% off) not applied', 'city_ledger', null, $user->id);
        }

        // B2C refund credit note: service complaint on a recent guest invoice
        $b2c = Models\Folio::where('supply_type', 'B2C')->where('status', 'closed')->where('type', 'guest')->where('invoice_date', '>=', $today->copy()->startOfMonth()->toDateString())
            ->whereHas('transactions', fn ($q) => $q->where('category', 'room')->where('type', 'charge')->where('is_void', false)->where('amount', '>=', 2000))->orderByDesc('invoice_date')->orderByDesc('id')->first();
        if ($b2c) {
            $line = $G::creditable($b2c)->where('category', 'room')->sortByDesc('amount')->first();
            $G::issueCreditNote($b2c, [$line->id => 1000], 'deficiency', 'Air-conditioning fault on the second night', 'refund', $cash ?? $bank, $user->id);
        }

        // earlier months' VAT returns filed on the GRA Taxpayers' Portal
        for ($m = 4; $m >= 1; $m--) {
            $p = $today->copy()->startOfMonth()->subMonths($m);
            if (! Models\GstPeriod::where('period', $p->format('Y-m'))->exists()) {
                try { // a month the GRA checks would still block (e.g. restaurant bills not yet stamped) is left open
                    $G::file($p->format('Y-m'), 'GRA/VAT/'.$p->format('Y/m').'/'.str_pad((string) (41170 + $m * 313), 6, '0', STR_PAD_LEFT), $G::returnDue($p->format('Y-m'))->subDays(3)->toDateString(), $user->id);
                } catch (\Illuminate\Validation\ValidationException) {
                }
            }
        }
    }

    /** v2.9 privacy demo: DSR requests in every state, a restricted + an erased profile, retention history & due items, PII access log, breaches. */
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

    /**
     * v2.7 contracts & credit: Infosys on a net-rate LRA contract with seasons, a Christmas blackout and a Deluxe
     * allotment (its bookings linked), TCS on 15% off expiring soon, a Thomas Cook winter contract awaiting approval,
     * an expired Yatra contract; credit terms per account, a past override and statement, and the review that puts
     * Thomas Cook (bills ~3 months old on 30-day terms) on automatic hold.
     */
    protected function seedContracts(bool $rich, Carbon $today, Models\User $user): void
    {
        \App\Services\ContractService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $mk = Models\User::where('username', 'marketing')->first() ?? $user;
        $fom = Models\User::where('username', 'fom')->first() ?? $user;
        $acc = Models\User::where('username', 'accounts')->first() ?? $user;
        $co = Models\Company::orderBy('id')->get()->keyBy('name');
        $infy = $co['Infosys Technologies'];
        $tcs = $co['Tata Consultancy Services'];
        $tc = $co['Thomas Cook India'];
        $yatra = $co['Yatra Travels'];
        $rt = Models\RoomType::pluck('id', 'code');
        $ago = fn (int $days) => now()->subDays($days);
        $log = function (Models\Company $c, string $action, string $detail, ?float $amount, Models\User $by, int $daysAgo) {
            Models\CreditLog::create(['company_id' => $c->id, 'action' => $action, 'detail' => $detail, 'amount' => $amount, 'user_id' => $by->id])
                ->forceFill(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)])->save();
        };

        // ---- credit terms (as agreed at account opening / FY review)
        $infy->forceFill(['credit_days' => 45, 'account_manager_id' => $mk->id, 'last_statement_at' => $ago(7)])->save();
        $tcs->forceFill(['credit_days' => 60, 'credit_limit' => 20000, 'account_manager_id' => $mk->id])->save();
        $tc->forceFill(['credit_days' => 30])->save();
        $yatra->forceFill(['direct_billing' => false])->save();
        $log($infy, 'terms', 'Credit days 30 → 45 – FY 2026-27 agreement', 500000, $acc, 175);
        $log($tcs, 'terms', 'Limit '.money(300000).' → '.money(20000).', credit days 30 → 60 – project account only', 20000, $acc, 90);
        $log($yatra, 'terms', 'Direct billing withdrawn – clients pay at check-out', 100000, $acc, 60);
        $log($tcs, 'override', 'Override: over the credit limit by '.money(5200).' – GM approved, PO 4500123 received', 25200, $fom, 70);
        $log($infy, 'statement', 'Statement e-mailed to arunmenon@example.com – outstanding '.money(34150), 34150, $acc, 7);

        // ---- contracts
        Auth::setUser($mk);
        $fyFrom = $today->copy()->subMonths(5)->startOfMonth();
        $fyTo = $fyFrom->copy()->addYear()->subDay();
        $xmas = Carbon::create($today->month === 12 && $today->day > 24 ? $today->year + 1 : $today->year, 12, 24);
        $infyK = Models\RateContract::create(['company_id' => $infy->id, 'code' => 'INFY-'.$fyFrom->year, 'name' => 'Infosys corporate rates FY '.$fyFrom->year.'-'.substr((string) ($fyFrom->year + 1), 2),
            'valid_from' => $fyFrom, 'valid_to' => $fyTo, 'status' => 'draft', 'pricing' => 'net', 'discount_pct' => 12, 'meal_plan' => 'CP',
            'inclusions' => 'Buffet breakfast, Wi-Fi, airport drop, 2 laundry pieces per day', 'lra' => true, 'access_code' => 'INFY26', 'cancel_days' => 1,
            'room_night_target' => 900, 'created_by' => $mk->id, 'notes' => 'Negotiated with Arun Menon (travel desk). Volume review at mid-year.']);
        foreach ([['STD', 2300, 2600, 700], ['DLX', 3400, 3800, 900], ['EXE', 6200, 6800, 1200]] as [$code, $single, $double, $extra]) {
            $infyK->rates()->create(['room_type_id' => $rt[$code], 'rate' => $single, 'double_rate' => $double, 'extra_adult_rate' => $extra]);
        }
        $infyK->rates()->create(['room_type_id' => $rt['DLX'], 'season_name' => 'Peak', 'season_from' => $xmas->copy()->subDays(9), 'season_to' => $xmas->copy()->addDays(17), 'rate' => 3900, 'double_rate' => 4300, 'extra_adult_rate' => 1000]);
        $infyK->blackouts()->create(['date_from' => $xmas, 'date_to' => $xmas->copy()->addDays(8), 'reason' => 'Christmas / New Year']);
        $infyK->allotments()->create(['room_type_id' => $rt['DLX'], 'date_from' => $today, 'date_to' => $today->copy()->addDays(60)->min($fyTo), 'rooms' => 2, 'release_days' => 7]);

        $tcsK = Models\RateContract::create(['company_id' => $tcs->id, 'code' => 'TCS-'.$today->copy()->subMonths(10)->year, 'name' => 'TCS project team – 15% off BAR',
            'valid_from' => $today->copy()->subMonths(10)->startOfMonth(), 'valid_to' => $today->copy()->addDays(45), 'status' => 'draft', 'pricing' => 'discount', 'discount_pct' => 15,
            'meal_plan' => 'EP', 'lra' => false, 'cancel_days' => 2, 'room_night_target' => 400, 'created_by' => $mk->id]);

        $winterFrom = Carbon::create($today->month >= 11 ? $today->year + 1 : $today->year, 11, 1);
        $tcK = Models\RateContract::create(['company_id' => $tc->id, 'code' => 'TC-WINTER-'.$winterFrom->year, 'name' => 'Thomas Cook winter series '.$winterFrom->year.'-'.substr((string) ($winterFrom->year + 1), 2),
            'valid_from' => $winterFrom, 'valid_to' => $winterFrom->copy()->addMonths(5)->subDay(), 'status' => 'draft', 'pricing' => 'net', 'meal_plan' => 'MAP',
            'inclusions' => 'Breakfast and dinner, welcome drink', 'lra' => false, 'cancel_days' => 7, 'commission_pct' => 10, 'room_night_target' => 600, 'created_by' => $mk->id,
            'notes' => 'Series groups from UK / Germany. Awaiting FOM approval.']);
        $tcK->rates()->create(['room_type_id' => $rt['DLX'], 'rate' => 3300, 'double_rate' => 3700, 'extra_adult_rate' => 1100]);
        $tcK->rates()->create(['room_type_id' => $rt['EXE'], 'rate' => 6000, 'double_rate' => 6500, 'extra_adult_rate' => 1400]);
        $tcK->blackouts()->create(['date_from' => $xmas, 'date_to' => $xmas->copy()->addDays(8), 'reason' => 'Festive season']);

        $yK = Models\RateContract::create(['company_id' => $yatra->id, 'code' => 'YATRA-'.$today->copy()->subMonths(15)->year, 'name' => 'Yatra online agency rates',
            'valid_from' => $today->copy()->subMonths(15)->startOfMonth(), 'valid_to' => $today->copy()->subDays(100), 'status' => 'draft', 'pricing' => 'discount', 'discount_pct' => 10,
            'meal_plan' => 'EP', 'cancel_days' => 1, 'commission_pct' => 7, 'room_night_target' => 300, 'created_by' => $mk->id]);

        // approvals by the FOM (maker-checker), backdated to the signing dates
        foreach ([[$infyK, $fyFrom->copy()->subDays(12)], [$tcsK, $tcsK->valid_from->copy()->subDays(5)], [$yK, $yK->valid_from->copy()->subDays(3)]] as [$k, $on]) {
            $k->forceFill(['status' => 'active', 'approved_by' => $fom->id, 'approved_at' => $on])->save();
        }
        // bookings made under the contracts
        Models\Reservation::where('company_id', $infy->id)->update(['rate_contract_id' => $infyK->id]);
        Models\Reservation::where('company_id', $yatra->id)->whereDate('arrival_date', '<=', $yK->valid_to)->update(['rate_contract_id' => $yK->id]);

        // ---- credit review: Thomas Cook's bills are far past 30-day terms → automatic hold
        Auth::forgetUser();
        \App\Services\CreditService::review();
        Auth::setUser($user);
    }

    /**
     * v2.6 guest marketing: imported history for a few lapsed guests, consent (with audit trail), tags, segments, promo
     * codes, a sent campaign with clicks / an unsubscribe / attributed bookings, a scheduled and a draft campaign, and
     * automations that have been running for two weeks.
     */
    protected function seedCrm(bool $rich, Carbon $today, Models\User $user): void
    {
        $crm = \App\Services\CrmService::class;
        $crm::ensureDefaults();
        if (! $rich) {
            $crm::refreshStats();

            return;
        }
        $mk = Models\User::where('username', 'marketing')->first() ?? $user;
        Auth::setUser($mk);
        $rt = Models\RoomType::orderBy('base_rate')->get();
        $rooms = Models\Room::orderBy('id')->get();
        $srcs = Models\BusinessSource::pluck('id', 'type');

        // ---- guests whose history was imported from the previous PMS (stays without folios)
        $hist = [
            ['Ms', 'Grace', 'Addo', 'Female', 'Ghana', 'Kumasi', [[420, 3], [180, 2]], 'foodie'],
            ['Mrs', 'Priyanka', 'Nair', 'Female', 'India', 'Kochi', [[700, 2], [520, 3], [400, 2], [260, 4], [150, 3]], 'spa-lover,business'],
            ['Mr', 'Oliver', 'Brown', 'Male', 'United Kingdom', 'London', [[800, 4]], null],
            ['Ms', 'Yaa', 'Asantewaa', 'Female', 'Ghana', 'Accra', [[500, 2], [330, 2], [185, 3]], 'wedding-2026'],
            ['Mr', 'Tunde', 'Bakare', 'Male', 'Nigeria', 'Lagos', [[365, 2], [179, 2]], 'business,corporate'],
            ['Mr', 'Samuel', 'Adeyemi', 'Male', 'Nigeria', 'Lagos', [[400, 1]], null],
        ];
        foreach ($hist as $i => [$t, $f, $l, $gender, $country, $city, $stays, $tags]) {
            $g = Models\Guest::create(['title' => $t, 'first_name' => $f, 'last_name' => $l, 'gender' => $gender, 'nationality' => $country, 'country' => $country, 'city' => $city,
                'email' => strtolower($f.'.'.$l).'@mail.example', 'phone' => (['India' => '+91 98', 'Nigeria' => '+234 80'][$country] ?? '+233 24').str_pad((string) (4100000 + $i * 1117), 7, '0'),
                'dob' => $today->copy()->subYears(30 + $i)->addDays(3 + $i * 9), 'tags' => $tags]);
            foreach ($stays as [$ago, $n]) {
                $dep = $today->copy()->subDays($ago);
                $arr = $dep->copy()->subDays($n);
                $type = $rt[$i % $rt->count()];
                $res = Models\Reservation::create(['reservation_no' => Numbering::next('reservation'), 'guest_id' => $g->id, 'business_source_id' => $srcs['walk_in'] ?? $srcs->first(),
                    'arrival_date' => $arr, 'departure_date' => $dep, 'adults' => 2, 'status' => 'checked_out', 'purpose' => 'Leisure']);
                $res->rooms()->create(['room_type_id' => $type->id, 'room_id' => $rooms->firstWhere('room_type_id', $type->id)?->id, 'guest_id' => $g->id, 'arrival_date' => $arr,
                    'departure_date' => $dep, 'rate' => $type->base_rate, 'adults' => 2, 'status' => 'checked_out', 'checked_in_at' => $arr->copy()->setTime(14, 0), 'checked_out_at' => $dep->copy()->setTime(11, 0)]);
                $res->forceFill(['created_at' => $arr->copy()->subDays(21)])->saveQuietly();
            }
            $g->forceFill(['created_at' => $today->copy()->subDays($stays[0][0] + 30)])->saveQuietly();
        }

        // ---- consent (logged), tags and anniversaries
        $sources = ['front_desk', 'booking_engine', 'precheckin'];
        foreach (Models\Guest::orderBy('id')->get() as $i => $g) {
            if (str_contains($g->first_name, 'Airbnb') || (! $g->email && ! $g->phone)) {
                continue;
            }
            $crm::setConsent($g, ['email' => $i % 10 < 7 && (bool) $g->email, 'sms' => $i % 3 !== 2 && (bool) $g->phone, 'whatsapp' => $i % 4 === 0 && (bool) $g->phone], $sources[$i % 3]);
            $when = now()->subDays(20 + $i * 6)->setTime(9 + $i % 9, 15);
            Models\MarketingConsentLog::where('guest_id', $g->id)->update(['created_at' => $when]);
            $tags = array_merge($g->tagList(), $g->is_vip ? ['vip'] : [], $g->company_id ? ['corporate'] : [], $i % 5 === 1 ? ['foodie'] : [], $i % 7 === 3 ? ['spa-lover'] : []);
            $g->forceFill(['consent_at' => $g->consent_at ? $when : null, 'tags' => $crm::normaliseTags(implode(',', $tags)) ?: null,
                'anniversary' => $g->anniversary ?? ($i % 4 === 1 && $g->dob ? $today->copy()->subYears(4 + $i % 9)->addDays($i === 5 ? 3 : 10 + $i) : null)])->saveQuietly();
        }
        $crm::refreshStats();

        // ---- segments
        $seg = fn ($name, $desc, $match, $rules) => Models\MarketingSegment::create(['name' => $name, 'description' => $desc, 'match' => $match, 'rules' => $crm::cleanRules($rules), 'is_active' => true, 'created_by' => $mk->id]);
        $high = $seg('High-value guests', 'VIPs and anyone with 40,000+ lifetime revenue', 'any', [['field' => 'total_revenue', 'op' => 'gte', 'value' => '40000'], ['field' => 'is_vip', 'op' => 'yes']]);
        $repeat = $seg('Repeat guests', 'Two or more completed stays', 'all', [['field' => 'stays_count', 'op' => 'gte', 'value' => '2']]);
        $seg('Lapsed 6+ months', 'Stayed before, nothing in the last 180 days and no future booking', 'all', [['field' => 'days_since_stay', 'op' => 'gte', 'value' => '180'], ['field' => 'upcoming', 'op' => 'no']]);
        $ghana = $seg('Ghana & West Africa', 'Local and regional market', 'all', [['field' => 'country', 'op' => 'in', 'value' => "Ghana, Nigeria, Togo, Côte d'Ivoire"]]);
        $seg('Birthdays next month', 'For the monthly birthday mailer', 'all', [['field' => 'birthday_month', 'op' => 'next']]);
        $corp = $seg('Corporate travellers', 'Linked to a company or tagged corporate', 'any', [['field' => 'corporate', 'op' => 'yes'], ['field' => 'tag', 'op' => 'has', 'value' => 'corporate']]);

        // ---- promo codes
        $promo = fn (array $a) => Models\PromoCode::create($a + ['discount_type' => 'percent', 'min_nights' => 1, 'is_active' => true, 'created_by' => $mk->id]);
        $harvest = $promo(['code' => 'HARVEST15', 'name' => 'Harvest weekend escape', 'value' => 15, 'book_from' => $today->copy()->subDays(12), 'book_to' => $today->copy()->addDays(20),
            'stay_from' => $today, 'stay_to' => $today->copy()->addDays(75), 'min_nights' => 2, 'max_uses' => 50, 'per_guest_limit' => 1]);
        $bday = $promo(['code' => 'BDAY20', 'name' => 'Birthday treat', 'value' => 20, 'per_guest_limit' => 1]);
        $wb = $promo(['code' => 'WELCOMEBACK', 'name' => 'We miss you', 'discount_type' => 'amount', 'value' => 1000, 'min_nights' => 2, 'book_to' => $today->copy()->addDays(90), 'per_guest_limit' => 1]);
        $festive = $promo(['code' => 'FESTIVE12', 'name' => 'Festive early-bird', 'value' => 12, 'book_to' => $today->copy()->addDays(35), 'stay_from' => $today->copy()->addDays(60), 'stay_to' => $today->copy()->addDays(105), 'max_uses' => 40]);
        $corpP = $promo(['code' => 'CORP10', 'name' => 'Corporate rate refresher', 'value' => 10, 'book_from' => $today->copy()->subDays(45), 'book_to' => $today->copy()->subDays(5),
            'room_type_ids' => $rt->take(2)->pluck('id')->all()]);

        $backdate = function ($msgs, Carbon $at) {
            $msgs = collect($msgs);
            Models\MarketingMessage::whereIn('id', $msgs->pluck('id'))->update(['created_at' => $at, 'updated_at' => $at]);
            Models\EmailLog::whereIn('id', $msgs->pluck('email_log_id')->filter())->update(['created_at' => $at, 'updated_at' => $at]);
            Models\MessageLog::whereIn('id', $msgs->pluck('message_log_id')->filter())->update(['created_at' => $at, 'updated_at' => $at]);
        };
        $campaign = function (array $a, Carbon $at) use ($crm, $mk, $backdate) {
            $c = Models\MarketingCampaign::create($a + ['status' => 'draft', 'created_by' => $mk->id]);
            $crm::sendCampaign($c, $mk->id);
            $backdate($c->messages()->get(), $at);
            $c->forceFill(['started_at' => $at, 'sent_at' => $at->copy()->addMinutes(2), 'created_at' => $at->copy()->subDays(2)])->saveQuietly();

            return $c;
        };

        // ---- campaigns: an older corporate mailer, the harvest campaign (clicks, an unsubscribe, bookings), a scheduled SMS and a draft
        $c0 = $campaign(['name' => 'Corporate rate refresher', 'channel' => 'email', 'segment_id' => $corp->id, 'promo_code_id' => $corpP->id, 'cta_label' => 'Book a business stay',
            'subject' => 'New corporate rates at {hotel}',
            'body' => "Dear {first_name},\n\nThank you for choosing {hotel} for your business travel. Book before {promo_expiry} with code {promo_code} and get **{promo_offer}** our best available rate on Standard and Deluxe rooms – high-speed Wi-Fi, breakfast and airport pick-up included.\n\nKind regards,\nSales team, {hotel}"], now()->subDays(40)->setTime(10, 0));
        foreach ($c0->messages()->where('status', 'sent')->orderBy('id')->get() as $k => $m) {
            if ($k % 3 === 0) {
                $m->forceFill(['clicked_at' => now()->subDays(39)->setTime(11 + $k, 10), 'click_count' => 1])->save();
            }
        }
        $t1 = now()->subDays(10)->setTime(10, 0);
        $c1 = $campaign(['name' => 'Harvest weekend escape', 'channel' => 'email', 'segment_id' => $repeat->id, 'promo_code_id' => $harvest->id, 'cta_label' => 'Book your weekend',
            'subject' => '{first_name}, {promo_offer} your harvest weekend at {hotel}',
            'body' => "Dear {first_name},\n\nThe harvest season is here and we would love to welcome you back to {hotel}. Book a weekend of two nights or more before {promo_expiry} and enjoy **{promo_offer}** your room rate with code {promo_code}.\n\nLate check-out until 2 pm is on us, subject to availability.\n\nWarm regards,\nThe {hotel} team"], $t1);
        $delivered = $c1->messages()->with('guest')->where('status', 'sent')->orderBy('id')->get();
        foreach ($delivered as $k => $m) {
            if ($k % 2 === 0) {
                $m->forceFill(['clicked_at' => $t1->copy()->addHours(2 + $k), 'click_count' => 1 + $k % 3])->save();
            }
        }
        if ($last = $delivered->last()) { // one guest unsubscribed from e-mail
            $crm::setConsent($last->guest, ['email' => false], 'unsubscribe', '102.176.12.40');
            Models\MarketingConsentLog::where('guest_id', $last->guest_id)->where('source', 'unsubscribe')->update(['created_at' => $t1->copy()->addDay()]);
            $last->forceFill(['unsubscribed_at' => $t1->copy()->addDay()])->save();
        }
        $type = $rt[1] ?? $rt[0];
        $book = function (Models\Guest $g, int $inDays, int $nights, ?Models\PromoCode $p, Carbon $at) use ($type, $today, $srcs, $crm) {
            $arr = $today->copy()->addDays($inDays);
            $dep = $arr->copy()->addDays($nights);
            $q = \App\Services\RateService::quote($type, $arr, $dep);
            $q = $p ? $crm::applyPromo($q, $p) : $q;
            $res = Models\Reservation::create(['reservation_no' => Numbering::next('reservation'), 'guest_id' => $g->id, 'business_source_id' => $srcs['booking_engine'] ?? null,
                'arrival_date' => $arr, 'departure_date' => $dep, 'adults' => 2, 'status' => 'confirmed', 'purpose' => 'Leisure',
                'promo_code_id' => $p?->id, 'promo_discount' => $q['promo_discount'] ?? 0]); // the created hook credits the campaign
            $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $g->id, 'arrival_date' => $arr, 'departure_date' => $dep, 'rate' => $q['average'], 'adults' => 2, 'status' => 'reserved']);
            $res->forceFill(['created_at' => $at])->saveQuietly();

            return $res;
        };
        $clicked = $delivered->whereNotNull('clicked_at')->values();
        foreach ($clicked->take(2) as $k => $m) {
            $book($m->guest, 50 + $k * 6, 2, $harvest, $t1->copy()->addDays(1 + $k)->setTime(20, 30));
        }
        if ($infl = $delivered->whereNull('clicked_at')->first()) {
            $book($infl->guest, 63, 3, null, $t1->copy()->addDays(4)->setTime(12, 5)); // no code, no click – "influenced"
        }
        Models\MarketingCampaign::create(['name' => 'Festive season early-bird', 'channel' => 'sms', 'segment_id' => $ghana->id, 'promo_code_id' => $festive->id, 'status' => 'scheduled',
            'scheduled_at' => now()->addDays(2)->setTime(10, 0), 'sent_by' => $mk->id, 'created_by' => $mk->id,
            'body' => 'Hi {first_name}, beat the festive rush at {hotel}! Book your holiday stay before {promo_expiry} and get {promo_offer} with code {promo_code}.', 'cta_label' => 'Book']);
        Models\MarketingCampaign::create(['name' => 'Sunday jazz brunch launch', 'channel' => 'whatsapp', 'segment_id' => $high->id, 'status' => 'draft', 'created_by' => $mk->id,
            'body' => "Hello {first_name}! Our new Sunday Jazz Brunch at {hotel} starts this weekend – live band, bottomless juices and kids eat free.\nReply to this message to reserve your table."]);

        // ---- automations, backfilled for two weeks of daily runs
        $jr = fn (array $a) => Models\MarketingJourney::create($a + ['created_by' => $mk->id]);
        $active = [
            $jr(['name' => 'Birthday greeting', 'trigger' => 'birthday', 'offset_days' => 0, 'channel' => 'email', 'promo_code_id' => $bday->id, 'cta_label' => 'Treat yourself', 'is_active' => true,
                'subject' => 'Happy birthday, {first_name}!',
                'body' => "Dear {first_name},\n\nEveryone at {hotel} wishes you a very happy birthday!\n\nTo celebrate, enjoy **{promo_offer}** your next stay with code {promo_code} – our gift to you.\n\nWith warm wishes,\n{hotel}"]),
            $jr(['name' => 'Pre-arrival upsell', 'trigger' => 'pre_arrival', 'offset_days' => 2, 'channel' => 'sms', 'is_active' => true,
                'body' => 'Hi {first_name}, we look forward to welcoming you to {hotel} on {arrival} (booking {res_no}). Fancy a room upgrade or a spa treatment on arrival? Just reply. Check in online: {checkin_link}']),
            $jr(['name' => 'Post-stay thank you & feedback', 'trigger' => 'post_stay', 'offset_days' => 1, 'channel' => 'email', 'cta_label' => 'Book your next stay', 'is_active' => true,
                'subject' => 'Thank you for staying with us, {first_name}',
                'body' => "Dear {first_name},\n\nThank you for choosing {hotel}. We hope you enjoyed your stay.\n\nHow did we do? It takes one minute: {feedback_link}\n\nYou now have {points} loyalty points to use on your next visit.\n\nSee you soon,\n{hotel}"]),
        ];
        $jr(['name' => 'Win-back: we miss you', 'trigger' => 'win_back', 'offset_days' => 180, 'channel' => 'email', 'promo_code_id' => $wb->id, 'cta_label' => 'Come back to us', 'is_active' => false,
            'subject' => 'We miss you, {first_name}',
            'body' => "Dear {first_name},\n\nIt has been a while since your last stay on {last_stay} and we would love to see you again at {hotel}.\n\nHere is {promo_offer} with code {promo_code} for a stay of two nights or more.\n\nWarm regards,\n{hotel}"]);
        $jr(['name' => 'Anniversary wishes', 'trigger' => 'anniversary', 'offset_days' => 3, 'channel' => 'whatsapp', 'is_active' => false,
            'body' => 'Happy anniversary in advance, {first_name}! Celebrate with a candle-lit dinner at {hotel} – the cake is on us. Reply to book your table.']);
        for ($d = 14; $d >= 1; $d--) {
            $from = (int) Models\MarketingMessage::max('id');
            foreach ($active as $j) {
                $crm::runJourney($j, $today->copy()->subDays($d));
            }
            $backdate(Models\MarketingMessage::where('id', '>', $from)->get(), now()->subDays($d)->setTime(9, 5));
        }
        $crm::runJourneys(); // today's run
        foreach ($active as $j) {
            $j->forceFill(['last_run_at' => now()->setTime(9, 0)])->saveQuietly();
        }
        Auth::setUser($user);
    }

    /**
     * v1.9 revenue management: realistic booking dates (lead times) on the history, a forward book that builds
     * towards arrival (weekend-heavy), pricing rules, floors/ceilings and 14 days of on-the-books snapshots.
     */
    protected function seedRevenue(bool $rich, Carbon $today, Models\User $user): void
    {
        mt_srand(1909); // deterministic demo book
        $now = now();
        $walkIn = Models\BusinessSource::where('name', 'Walk-in')->value('id');
        // 1. booking dates: arrival minus a lead time (walk-ins same day), never in the future
        foreach (Models\Reservation::orderBy('id')->get(['id', 'arrival_date', 'business_source_id', 'cancelled_at']) as $r) {
            $x = mt_rand(1, 100);
            $lead = $r->business_source_id === $walkIn ? 0 : ($x <= 25 ? mt_rand(0, 3) : ($x <= 65 ? mt_rand(4, 14) : ($x <= 90 ? mt_rand(15, 30) : mt_rand(31, 50))));
            $created = $r->arrival_date->copy()->subDays($lead)->setTime(mt_rand(8, 21), mt_rand(0, 59));
            if ($created->gt($now)) {
                $created = $now->copy()->subHours(mt_rand(2, 30));
            }
            if ($r->cancelled_at && $created->gt($r->cancelled_at)) {
                $created = $r->cancelled_at->copy()->subDay();
            }
            \Illuminate\Support\Facades\DB::table('reservations')->where('id', $r->id)->update(['created_at' => $created]);
        }

        $types = Models\RoomType::orderBy('base_rate')->get()->keyBy('code');
        foreach (['STD' => [2200, 4200], 'DLX' => [3400, 6500], 'EXE' => [6200, 12000], 'PRE' => [12000, 22000]] as $code => [$min, $max]) {
            $types[$code]?->update(['min_rate' => $min, 'max_rate' => $max]);
        }
        if (! $rich) {
            mt_srand();

            return;
        }

        // 2. forward book: what is on the books falls away with lead time; Fri/Sat fill faster
        $sources = Models\BusinessSource::pluck('id', 'name');
        $guests = Models\Guest::orderBy('id')->get();
        $caps = ['STD' => 5, 'DLX' => 6, 'EXE' => 4, 'PRE' => 1]; // leave room for walk-ins, tests & demos
        $mix = ['STD' => 45, 'DLX' => 35, 'EXE' => 15, 'PRE' => 5];
        $srcMix = ['Booking.com' => 30, 'Direct/Phone' => 20, 'Booking Engine' => 15, 'Expedia' => 10, 'MakeMyTrip' => 8, 'Agoda' => 7, 'Corporate' => 5, 'Travel Agent' => 5];
        $pick = function (array $w) {
            $x = mt_rand(1, array_sum($w));
            foreach ($w as $k => $v) {
                if (($x -= $v) <= 0) {
                    return $k;
                }
            }

            return array_key_first($w);
        };
        $frac = fn (int $lead) => match (true) { $lead <= 2 => .9, $lead <= 7 => .7, $lead <= 14 => .45, $lead <= 30 => .22, $lead <= 45 => .1, default => .03 };
        $cap = (int) $types->sum(fn ($t) => $t->rooms()->whereNotIn('status', ['out_of_order', 'out_of_service'])->count());
        $made = [];
        $gi = 0;
        for ($d = 1; $d <= 60; $d++) {
            $date = $today->copy()->addDays($d);
            $weekend = in_array($date->dayOfWeek, \App\Services\RateService::WEEKEND, true);
            $m = \App\Services\AvailabilityService::matrix($date, 1);
            $sold = $m['types']->sum(fn ($t) => $t->rooms_count - ($m['matrix'][$t->id][$date->toDateString()] ?? 0));
            $need = (int) round($cap * ($weekend ? .84 : .58) * $frac($d)) - $sold;
            for ($try = 0; $need > 0 && $try < 15; $try++) {
                $code = $pick($mix);
                $type = $types[$code] ?? null;
                $nights = $pick([1 => 50, 2 => 35, 3 => 15]);
                if (! $type) {
                    continue;
                }
                $mm = \App\Services\AvailabilityService::matrix($date, $nights);
                $full = collect($mm['matrix'][$type->id] ?? [])->contains(fn ($left) => $type->rooms()->whereNotIn('status', ['out_of_order', 'out_of_service'])->count() - $left >= $caps[$code]);
                if ($full) {
                    continue;
                }
                $dep = $date->copy()->addDays($nights);
                $src = $sources[$pick($srcMix)] ?? null;
                $rate = \App\Services\RateService::quote($type, $date, $dep)['average'];
                $guest = $guests[$gi++ % $guests->count()];
                $ago = mt_rand(0, max(0, min(35, 50 - $d)));
                $created = $now->copy()->subDays($ago)->subHours(mt_rand(1, 9));
                $res = Models\Reservation::create([
                    'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'business_source_id' => $src,
                    'arrival_date' => $date->toDateString(), 'departure_date' => $dep->toDateString(), 'adults' => 2, 'children' => 0,
                    'status' => 'confirmed', 'created_by' => $user->id, 'eta' => '14:00', 'billing_instruction' => 'guest', 'purpose' => $weekend ? 'Leisure' : 'Business',
                ]);
                Models\ReservationRoom::create(['reservation_id' => $res->id, 'room_type_id' => $type->id, 'guest_id' => $guest->id, 'arrival_date' => $date->toDateString(),
                    'departure_date' => $dep->toDateString(), 'rate' => $rate, 'adults' => 2, 'status' => 'reserved']);
                \Illuminate\Support\Facades\DB::table('reservations')->where('id', $res->id)->update(['created_at' => $created, 'updated_at' => $created]);
                $made[] = $res->id;
                $need--;
            }
        }
        // a few cancellations this week (pickup is net of these)
        foreach (collect($made)->shuffle(7)->take(3) as $i => $id) {
            $r = Models\Reservation::find($id);
            $r->rooms()->update(['status' => 'cancelled']);
            $at = $now->copy()->subDays($i * 2 + 1);
            $r->update(['status' => 'cancelled', 'cancel_reason' => ['Change of plans', 'Found a cheaper rate', 'Trip postponed'][$i], 'cancelled_at' => $at]);
            if ($r->created_at->gt($at)) {
                \Illuminate\Support\Facades\DB::table('reservations')->where('id', $id)->update(['created_at' => $at->copy()->subDays(3)]);
            }
        }

        // 3. pricing rules
        foreach ([
            ['Last-minute low demand', 'type', 0, 30, 0, 7, null, 'percent', -10, 10],
            ['Busy (60–79% full)', 'hotel', 60, 79, null, null, null, 'percent', 10, 20],
            ['Compression (80%+)', 'hotel', 80, 100, null, null, null, 'percent', 20, 20],
            ['Weekend premium', 'hotel', null, null, null, null, '5,6', 'percent', 8, 30],
            ['Early bird', 'hotel', null, null, 45, null, null, 'percent', -5, 40],
        ] as [$name, $basis, $o1, $o2, $l1, $l2, $days, $at, $av, $prio]) {
            Models\YieldRule::create(['name' => $name, 'basis' => $basis, 'occ_min' => $o1, 'occ_max' => $o2, 'lead_min' => $l1, 'lead_max' => $l2, 'days' => $days,
                'adjust_type' => $at, 'adjust_value' => $av, 'priority' => $prio, 'is_active' => true, 'created_by' => $user->id]);
        }
        Models\YieldRule::create(['name' => 'Year-end peak', 'basis' => 'hotel', 'date_from' => $today->copy()->setDate($today->year, 12, 24), 'date_to' => $today->copy()->setDate($today->year, 12, 31),
            'adjust_type' => 'amount', 'adjust_value' => 1500, 'min_stay' => 2, 'priority' => 5, 'is_active' => false, 'created_by' => $user->id]);
        foreach (['rm_mode' => 'recommend', 'rm_round_to' => '50', 'rm_horizon_days' => '90'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }

        // 4. on-the-books history (pickup needs yesterday's and last week's position); 5. the next 3 nights already published
        for ($i = 14; $i >= 1; $i--) {
            \App\Services\RevenueService::reconstruct($today->copy()->subDays($i));
        }
        $rec = \App\Services\RevenueService::recommendations(Models\RoomType::where('is_active', true)->get(), $today->copy(), 3);
        $cells = [];
        foreach ($rec['grid'] as $typeId => $dates) {
            foreach ($dates as $date => $c) {
                if ($c['change']) {
                    $cells[$typeId][$date] = $c['rec']['rate'];
                }
            }
        }
        if ($cells) {
            \App\Services\RevenueService::publish($cells);
        }
        mt_srand();
    }

    /** v1.7: portal settings, links for every current stay, a live request queue and a pending in-room dining order. */
    protected function seedPortal(Models\Hotel $hotel, bool $rich, Models\Outlet $rs): void
    {
        foreach (['portal_enabled' => '1', 'portal_spa_enabled' => '1', 'portal_dining_outlet_id' => (string) $rs->id, 'portal_sla_minutes' => '15', 'portal_days_after' => '2',
            'portal_wifi_name' => str_replace(' ', '', $hotel->code).'-Guest', 'portal_wifi_password' => $rich ? 'Akwaaba2026' : 'Lakeside2026',
            'portal_info' => "Breakfast: 06:30 – 10:30, ".($rich ? 'Saffron Restaurant' : 'Lakeside Café')."\nPool: 07:00 – 20:00\nFront desk: dial 9\nRoom service: dial 5\nAirport transfer: on request, 24 h"] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        $stays = Models\ReservationRoom::with('room')->whereIn('status', ['checked_in', 'reserved'])->orderBy('id')->get();
        $stays->each->portalUrl();
        if (! $rich) {
            return;
        }
        $in = $stays->where('status', 'checked_in')->filter(fn ($s) => $s->room_id)->values();
        if ($in->count() < 4) {
            return;
        }
        $mk = function ($stay, array $d, int $agoMin, ?string $action = null, ?string $note = null, ?int $ackAgo = null) {
            $r = \App\Services\GuestPortalService::raise($stay, $d);
            $r->forceFill(['created_at' => now()->subMinutes($agoMin)])->save();
            if ($action) {
                \App\Services\GuestPortalService::transition($r, $action, $note);
                $r->forceFill(['acknowledged_at' => now()->subMinutes($ackAgo ?? max(0, $agoMin - 4))] + (in_array($r->status, ['done', 'declined']) ? ['completed_at' => now()->subMinutes(max(0, $agoMin - 15))] : []))->save();
            }

            return $r;
        };
        $done = $mk($in[0], ['category' => 'amenity', 'item' => 'Drinking water', 'quantity' => 2], 190, 'done', 'Delivered by housekeeping', 186);
        $done->forceFill(['rating' => 5])->save();
        $mk($in[1], ['category' => 'late_checkout', 'time' => '14:00', 'notes' => 'Evening flight'], 150, 'done', 'Approved – complimentary until 14:00', 140);
        $mk($in[3], ['category' => 'wakeup', 'preferred_at' => now()->addDay()->setTime(6, 30)->toDateTimeString()], 95); // v3.5: books the call in the wake-up queue (auto-acknowledged)
        $ac = $mk($in[1], ['category' => 'maintenance', 'item' => 'Air-conditioning', 'notes' => 'AC is not cooling, making a rattling noise'], 42, 'start', 'Technician on the way', 30);
        $mk($in[0], ['category' => 'amenity', 'item' => 'Extra towels', 'quantity' => 2, 'notes' => 'Two bath towels please'], 24);
        $mk($in[2], ['category' => 'housekeeping', 'item' => 'Clean my room now'], 6);
        $mk($in[3], ['category' => 'transport', 'item' => 'Airport drop', 'preferred_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(), 'notes' => '2 passengers, 3 bags'], 3);
        $items = Models\MenuItem::where('is_available', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $rs->id))->orderBy('id')->limit(2)->get();
        if ($items->count() === 2) {
            $o = \App\Services\GuestPortalService::orderDining($in[2], [['id' => $items[0]->id, 'qty' => 1, 'notes' => 'Less spicy'], ['id' => $items[1]->id, 'qty' => 2]]);
            $o->forceFill(['created_at' => now()->subMinutes(4)])->save();
        }
        Models\GuestFeedback::create(['reservation_id' => $in[0]->reservation_id, 'reservation_room_id' => $in[0]->id, 'guest_name' => ($in[0]->guest ?? $in[0]->reservation->guest)?->full_name ?? 'Guest',
            'rating_overall' => 5, 'rating_room' => 5, 'rating_service' => 5, 'rating_food' => 4, 'rating_cleanliness' => 5, 'comments' => 'Ordering towels from my phone was so easy!', 'source' => 'portal']);
    }

    /** v1.6: opening balances + fixed assets as manual journals, then post every closed day to the GL. */
    /** v1.8: accounts payable – received POs, matched / variance / held bills, expense bills, a debit note, payments and an advance. */
    protected function seedPayables(Models\Hotel $hotel, bool $rich, Carbon $today, Models\User $user): void
    {
        $heads = Models\AccountHead::pluck('id', 'name');
        $stores = Models\Store::pluck('id', 'type');
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $sup = Models\Supplier::pluck('id', 'name');
        Models\Supplier::whereKey($sup['Metro Cash & Carry'])->update(['payment_terms_days' => 15, 'expense_head_id' => $heads['Purchases – Food'] ?? null, 'bank_details' => "GCB Bank · A/c 1011130012345 · Sort code 040101"]);
        Models\Supplier::whereKey($sup['FreshFarm Foods'])->update(['payment_terms_days' => 7, 'expense_head_id' => $heads['Purchases – Food'] ?? null, 'bank_details' => "MoMo: 024 412 3456 (FreshFarm Foods)"]);
        Models\Supplier::whereKey($sup['CleanPro Supplies'])->update(['payment_terms_days' => 30, 'expense_head_id' => $heads['Housekeeping Supplies'] ?? null]);
        $power = Models\Supplier::create(['wht_category' => 'none', 'name' => 'City Power Distribution Co.', 'contact_person' => 'Billing desk', 'phone' => '1912', 'payment_terms_days' => 15, 'expense_head_id' => $heads['Electricity'] ?? null]);
        $items = Models\InventoryItem::orderBy('id')->get()->keyBy('name');

        $receive = function (string $supplier, string $store, int $daysAgo, array $lines) use ($today, $user, $stores, $sup, $items) {
            $date = $today->copy()->subDays($daysAgo);
            $po = Models\PurchaseOrder::create(['po_no' => Numbering::next('po'), 'supplier_id' => $sup[$supplier], 'store_id' => $stores[$store], 'po_date' => $date->copy()->subDays(2), 'expected_date' => $date, 'status' => 'received', 'created_by' => $user->id]);
            foreach ($lines as $name => [$qty, $rate]) {
                $it = $items[$name];
                $po->items()->create(['inventory_item_id' => $it->id, 'qty' => $qty, 'rate' => $rate, 'tax_rate' => 20, 'amount' => $qty * $rate]);
                Models\StockMovement::create(['inventory_item_id' => $it->id, 'store_id' => $stores[$store], 'date' => $date, 'type' => 'purchase', 'qty' => $qty, 'rate' => $rate, 'reference' => $po->po_no, 'user_id' => $user->id]);
                $it->update(['last_cost' => $rate]);
            }
            $sub = (float) $po->items()->sum('amount');
            $po->forceFill(['subtotal' => $sub, 'tax' => round($sub * .20, 2), 'total' => round($sub * 1.20, 2), 'received_at' => $date->copy()->setTime(11, 30)])->save();

            return $po;
        };
        $k = $rich ? 1 : 0.5;

        // 1. matched bill, paid in full
        $po1 = $receive('FreshFarm Foods', 'kitchen', 12, ['Paneer' => [round(12 * $k), 380], 'Chicken' => [round(20 * $k), 245]]);
        $b1 = ApService::billFromReceipt($po1, ['supplier_ref' => 'FF/2026/0412', 'doc_date' => $today->copy()->subDays(12)->toDateString()]);
        ApService::pay(Models\Supplier::find($po1->supplier_id), ['date' => $today->copy()->subDays(5)->toDateString(), 'payment_mode_id' => $bank, 'amount' => $b1->total, 'reference' => 'GIP 88231'], [$b1->id => $b1->total]);

        // 2. bill with an unmatched price, uninvoiced receipt
        $po4 = $receive('Metro Cash & Carry', 'kitchen', 2, ['Basmati Rice' => [round(50 * $k), 96], 'Refined Oil' => [round(30 * $k), 158]]);
        if (! $rich) {
            return;
        }
        $accounts = Models\User::where('hotel_id', $hotel->id)->where('username', 'accounts')->first();
        Auth::setUser($accounts ?? $user);
        // 3. price variance (+1.9%): held, approved by the administrator, part-paid, then a quality debit note
        $po2 = $receive('Metro Cash & Carry', 'kitchen', 8, ['Basmati Rice' => [40, 95], 'Refined Oil' => [25, 160], 'Mineral Water 1L' => [240, 14]]);
        $b2 = ApService::billFromReceipt($po2, ['supplier_ref' => 'MCC-INV-77120', 'doc_date' => $today->copy()->subDays(8)->toDateString(), 'subtotal' => round($po2->subtotal * 1.019, 2), 'tax' => round($po2->tax * 1.019, 2)]);
        // 4. price variance (+6%): left on hold for approval
        $po3 = $receive('CleanPro Supplies', 'housekeeping', 4, ['Floor Cleaner 5L' => [10, 480], 'Bath Towel' => [20, 320]]);
        ApService::billFromReceipt($po3, ['supplier_ref' => 'CP/1187', 'doc_date' => $today->copy()->subDays(4)->toDateString(), 'subtotal' => round($po3->subtotal * 1.06, 2), 'tax' => round($po3->tax * 1.06, 2), 'notes' => 'Towel rate on invoice 340 vs PO 320']);
        // 5. overdue utility bill (25 days past due) and an older repairs bill, part-paid (≈50 days past due)
        ApService::expenseBill($power, ['supplier_ref' => 'CPD-'.$today->copy()->subDays(40)->format('ym').'-55310', 'doc_date' => $today->copy()->subDays(40)->toDateString(),
            'lines' => [['account_head_id' => $heads['Electricity'], 'description' => 'Electricity – HT connection, 18,400 kWh', 'amount' => 148600, 'tax_rate' => 0], ['account_head_id' => $heads['Electricity'], 'description' => 'Fixed / demand charges', 'amount' => 12500, 'tax_rate' => 20]]]);
        $hvac = Models\Supplier::create(['name' => 'CoolAir HVAC Services', 'contact_person' => 'Joseph', 'phone' => '+91 98450 22110', 'payment_terms_days' => 30, 'expense_head_id' => $heads['Repairs & Maintenance'] ?? null, 'tax_number' => 'C0024410812']);
        $b5 = ApService::expenseBill($hvac, ['supplier_ref' => 'CA/AMC/219', 'doc_date' => $today->copy()->subDays(80)->toDateString(),
            'lines' => [['account_head_id' => $heads['Repairs & Maintenance'], 'description' => 'Chiller service & gas top-up', 'amount' => 42000, 'tax_rate' => 20], ['account_head_id' => $heads['Repairs & Maintenance'], 'description' => 'AHU filter replacement (12 nos)', 'amount' => 9600, 'tax_rate' => 20]]]);
        Auth::setUser($user);
        ApService::approve($b2, 'Rice price increase confirmed by purchase manager');
        $b2 = ApService::debitNote(Models\Supplier::find($po2->supplier_id), ['against_invoice_id' => $b2->id, 'supplier_ref' => 'MCC-CN-3021', 'doc_date' => $today->copy()->subDays(6)->toDateString(),
            'lines' => [['description' => '2 kg basmati rice – damaged bags', 'amount' => 190, 'tax_rate' => 20]], 'notes' => 'Damaged bags returned with the driver'])->againstInvoice;
        ApService::pay(Models\Supplier::find($po2->supplier_id), ['date' => $today->copy()->subDays(3)->toDateString(), 'payment_mode_id' => $bank, 'amount' => 5000, 'reference' => 'GIP 88415'], [$b2->id => 5000]);
        ApService::pay($hvac, ['date' => $today->copy()->subDays(30)->toDateString(), 'payment_mode_id' => $bank, 'amount' => 30000, 'reference' => 'CHQ 004512'], [$b5->id => 30000]);
        // 6. advance to CleanPro while its bill is on hold
        ApService::pay(Models\Supplier::find($po3->supplier_id), ['date' => $today->copy()->subDays(1)->toDateString(), 'payment_mode_id' => $cash, 'amount' => 3000, 'reference' => 'Advance', 'notes' => 'Advance against towel order']);
        unset($po4);
    }

    /**
     * v7.53 — two supplier bills in the supplier's own currency, booked in cedis at the forex board for their invoice
     * date, with the supplier's own document kept on the header. Called after seedForeign() because the board has to
     * exist before a bill can be converted at it; a property with no board keeps a plain cedi ledger, and a refusal is
     * not demo data, so this quietly does nothing there.
     */
    protected function seedApForeign(bool $rich, Carbon $today, Models\User $user): void
    {
        if (! $rich || ! ForexService::enabled()) {
            return;
        }
        $heads = Models\AccountHead::pluck('id', 'name');
        $otaHead = $heads['Marketing & OTA Commission'] ?? $heads['Agent Commission'] ?? null;
        $feeHead = $heads['Management Fees – Base'] ?? $heads['Audit & Professional Fees'] ?? null;
        $eurDate = $today->copy()->subDays(3)->toDateString();
        $usdDate = $today->copy()->subDays(9)->toDateString();
        if (! $otaHead || ! $feeHead || ! ForexService::boardRate('EUR', $eurDate) || ! ForexService::boardRate('USD', $usdDate)) {
            return;
        }
        Auth::setUser($user);
        $bank = Models\PaymentMode::where('name', 'like', '%Bank%')->value('id');
        // 7. v7.53 two bills in the supplier's own currency, booked at the board for their invoice date: an OTA's
        //    commission invoice from its Amsterdam entity in euros, and an international operator's base management fee
        //    in dollars, part-paid, so Accounts payable › Ageing shows what settling the rest would cost at today's board
        $ota = Models\Supplier::create(['wht_category' => 'none', 'name' => 'Booking.com B.V.', 'contact_person' => 'Partner settlements',
            'email' => 'invoices@booking.example', 'address' => 'Keizersgracht 597, 1017 DR Amsterdam, Netherlands', 'payment_terms_days' => 14, 'is_local' => false,
            'expense_head_id' => $otaHead ?? null]);
        ApService::expenseBill($ota, ['supplier_ref' => 'BCOM-INV-'.$today->copy()->subDays(3)->format('Ym').'-88213', 'currency' => 'EUR',
            'doc_date' => $today->copy()->subDays(3)->toDateString(),
            'lines' => [['account_head_id' => $otaHead, 'description' => 'Commission on 41 reservations, Sep 2026', 'amount' => 4180.55, 'tax_rate' => 0]],
            'notes' => 'Billed in EUR by the Amsterdam entity and settled monthly through the bank.']);
        $operator = Models\Supplier::create(['wht_category' => 'none', 'name' => 'Meridian Hotels International Ltd.', 'contact_person' => 'Owner services',
            'email' => 'fees@meridianhotels.example', 'address' => '110 High Holborn, London WC1V 6QT, United Kingdom', 'payment_terms_days' => 30, 'is_local' => false,
            'expense_head_id' => $feeHead]);
        $mgmt = ApService::expenseBill($operator, ['supplier_ref' => 'MHI/BMF/2026-09', 'currency' => 'USD',
            'doc_date' => $today->copy()->subDays(9)->toDateString(),
            'lines' => [['account_head_id' => $feeHead, 'description' => 'Base management fee, 3% of gross revenue, Sep 2026', 'amount' => 6250, 'tax_rate' => 0],
                ['account_head_id' => $heads['Internet & Telephone'] ?? $feeHead, 'description' => 'Reservation platform and channel-manager licence', 'amount' => 480, 'tax_rate' => 0]],
            'notes' => 'Billed in USD under the management agreement; paid from the dollar account.']);
        ApService::pay($operator, ['date' => $today->copy()->subDays(2)->toDateString(), 'payment_mode_id' => $bank, 'amount' => 40000, 'reference' => 'TT 558214'], [$mgmt->id => 40000]);
    }

    /** Attendance from 1 July (or joining): a fixed weekly off, occasional absences and some overtime; deterministic per employee/day. */
    protected function seedAttendance($emps, bool $rich, Carbon $today): void
    {
        $from = $rich ? Carbon::parse('2026-07-01') : $today->copy()->subDays(6);
        $rows = [];
        foreach ($emps as $i => $e) {
            for ($d = $from->copy(); $d->lte($today); $d->addDay()) {
                if ($e->joining_date && $d->lt($e->joining_date)) {
                    continue;
                }
                $h = crc32($e->id.'|'.$d->toDateString()) % 100;
                [$status, $in, $out] = match (true) {
                    $d->dayOfWeek === ($i % 7) => ['WO', null, null],
                    $h < 3 => ['A', null, null],
                    $e->ot_eligible && $h > 90 => ['P', '09:00', $h > 96 ? '21:00' : '20:00'], // 2–3 h overtime
                    default => ['P', '09:00', '18:00'],
                };
                $rows[] = ['hotel_id' => $e->hotel_id, 'employee_id' => $e->id, 'date' => $d->toDateString(), 'status' => $status, 'in_time' => $in, 'out_time' => $out, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Models\Attendance::insert($chunk);
        }
    }

    /**
     * v2.0 HR & payroll: leave (approved / pending / rejected), salary advances and three payroll runs –
     * July paid, August approved (salaries payable) and September in draft.
     */
    protected function seedPayroll(Models\Hotel $hotel, $emps, bool $rich, Carbon $today, Models\User $user): void
    {
        \App\Services\PayrollService::ensureDefaults();
        Models\Setting::updateOrCreate(['key' => 'payroll_segregation'], ['value' => '1']);
        if (! $rich) {
            return;
        }
        $hr = Models\User::where('username', 'hr')->first() ?? $user;
        $acc = Models\User::where('username', 'accounts')->first() ?? $user;
        $e = fn ($n) => $emps->firstWhere('name', $n);
        $t = Models\LeaveType::pluck('id', 'code')->map(fn ($id) => Models\LeaveType::find($id));
        $leave = function ($name, $code, $from, $to, $half, $reason, $decision = null, $note = null) use ($e, $t, $hr) {
            Auth::setUser($hr);
            $r = \App\Services\LeaveService::request($e($name), $t[$code], $from, $to, $half, $reason, $hr);
            match ($decision) {
                'approve' => \App\Services\LeaveService::approve($r, $note),
                'reject' => \App\Services\LeaveService::reject($r, $note),
                default => null,
            };

            return $r;
        };
        $leave('Lata Bai', 'EL', '2026-07-14', '2026-07-16', false, 'Family function in the village', 'approve');
        $leave('Sunil Yadav', 'LWP', '2026-07-22', '2026-07-22', false, 'Personal work', 'approve');
        $leave('Deepa Nair', 'SL', '2026-08-05', '2026-08-06', false, 'Fever – medical certificate submitted', 'approve');
        $leave('Joseph Thomas', 'CL', '2026-08-12', '2026-08-12', true, 'Bank work (afternoon)', 'approve');
        $leave('Imran Ali', 'CL', '2026-09-08', '2026-09-08', false, 'Child\'s school meeting', 'approve');
        $leave('Efua Asante', 'CL', '2026-09-19', '2026-09-19', false, 'Friend\'s wedding', 'reject', 'Peak weekend – spa fully booked. Please pick another date.');
        $leave('Kiran Patil', 'SL', $today->copy()->addDays(3)->toDateString(), $today->copy()->addDays(4)->toDateString(), false, 'Minor surgery scheduled');
        $leave('Abena Owusu', 'EL', $today->copy()->addDays(9)->toDateString(), $today->copy()->addDays(13)->toDateString(), false, 'Travelling home to Kumasi');
        $leave('Ramesh Gowda', 'CL', $today->copy()->addDays(6)->toDateString(), $today->copy()->addDays(6)->toDateString(), false, 'Festival at home');

        Auth::setUser($hr);
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        \App\Services\PayrollService::giveAdvance(['employee_id' => $e('Sunil Yadav')->id, 'date' => '2026-06-20', 'amount' => 1500, 'installment' => 500, 'payment_mode_id' => $bank, 'reason' => 'Medical expenses (mother)']);
        \App\Services\PayrollService::giveAdvance(['employee_id' => $e('Kiran Patil')->id, 'date' => $today->copy()->subDays(16)->toDateString(), 'amount' => 600, 'installment' => 300, 'payment_mode_id' => $cash, 'reason' => 'House rent deposit']);

        foreach (['2026-07-01' => 'paid', '2026-08-01' => 'approved', $today->copy()->startOfMonth()->toDateString() => 'draft'] as $month => $state) {
            Auth::setUser($hr);
            $run = \App\Services\PayrollService::createRun(Carbon::parse($month));
            if ($month === '2026-08-01') {
                \App\Services\PayrollService::addAdjustment(Models\Payslip::where('payroll_run_id', $run->id)->where('employee_id', $e('Deepa Nair')->id)->first(), ['kind' => 'earning', 'name' => 'Guest-review incentive', 'amount' => 300, 'taxable' => true]);
                \App\Services\PayrollService::addAdjustment(Models\Payslip::where('payroll_run_id', $run->id)->where('employee_id', $e('Sunil Yadav')->id)->first(), ['kind' => 'deduction', 'name' => 'Broken glassware recovery', 'amount' => 45]);
            }
            if ($state === 'draft') {
                continue;
            }
            Auth::setUser($acc);
            \App\Services\PayrollService::approve($run);
            if ($state === 'paid') {
                \App\Services\PayrollService::pay($run, Carbon::parse($month)->endOfMonth()->addDay()->toDateString());
            }
        }
        Auth::setUser($user);
    }

    /**
     * v2.1 online payments (built-in test gateway): paid deposits (one partly refunded), a portal bill payment,
     * a link awaiting payment, expired / cancelled links, one under review, an online booking on hold and a payout.
     */
    protected function seedOnlinePayments(Models\Hotel $hotel, bool $rich, Carbon $today): void
    {
        foreach (['pay_enabled' => '1', 'pay_provider' => 'demo', 'pay_link_hours' => '72', 'pay_booking_deposit' => $rich ? 'first_night' : 'none',
            'pay_booking_hold_minutes' => '60', 'pay_portal_enabled' => '1', 'pay_receipt_email' => '1'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        \App\Services\OnlinePaymentService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $S = \App\Services\OnlinePaymentService::class;
        $fd = Models\User::where('username', 'frontdesk')->first();
        Auth::login($fd);
        $back = function (Models\OnlinePayment $p, Carbon $created, ?Carbon $paid = null) {
            $shift = (int) round(now()->diffInSeconds($created, false));
            $p->forceFill(['created_at' => $created, 'updated_at' => $paid ?? $created, 'sent_at' => $p->sent_at ? $created->copy()->addMinute() : null,
                'expires_at' => $p->expires_at?->copy()->addSeconds($shift)] + ($paid ? ['paid_at' => $paid] : []))->saveQuietly();
            $evs = $p->events()->orderBy('id')->get();
            foreach ($evs as $i => $e) {
                $e->forceFill(['created_at' => $i === 0 ? $created : ($paid ?? $created)->copy()->addSeconds($i)])->saveQuietly();
            }
            if ($paid && $p->folio_transaction_id) {
                Models\FolioTransaction::whereKey($p->folio_transaction_id)->update(['business_date' => $paid->toDateString(), 'created_at' => $paid, 'updated_at' => $paid]);
            }
        };
        $payDemo = function (Models\OnlinePayment $p, string $channel) use ($S) {
            $S::start($p->fresh());
            $p = $p->fresh();

            return $S::handleReturn($p, ['ref' => $p->provider_ref, 'result' => 'success', 'channel' => $channel, 'sig' => \App\Services\Payments\DemoGateway::sign($p, $p->provider_ref, 'success', $channel)]);
        };
        $upcoming = Models\Reservation::with('guest')->where('status', 'confirmed')->whereBetween('arrival_date', [$today->copy()->addDays(3)->toDateString(), $today->copy()->addDays(30)->toDateString()])
            ->whereDoesntHave('folios')->whereNull('company_id')->orderBy('arrival_date')->orderBy('id')->get()->filter(fn ($r) => $r->guest?->email)->values();

        // paid deposits 6, 4 and 2 days ago (card, mobile money, card); the last one partly refunded yesterday
        $paidPl = [];
        foreach ([[0, 6, 'card'], [1, 4, 'mobile_money'], [2, 2, 'card']] as [$i, $ago, $ch]) {
            if (! ($r = $upcoming[$i] ?? null)) {
                continue;
            }
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::send($p, ['email']);
            $p = $payDemo($p, $ch);
            $back($p, $today->copy()->subDays($ago)->setTime(11, 5), $today->copy()->subDays($ago)->setTime(13, 40 + $i));
            $paidPl[] = $p->fresh();
        }
        if ($p = $paidPl[2] ?? null) {
            $half = round((float) $p->amount / 2, 2);
            $rf = $S::refund($p, $half, 'Stay shortened by one night – guest request');
            $y = $today->copy()->subDay()->setTime(10, 15);
            $rf->forceFill(['created_at' => $y])->saveQuietly();
            Models\FolioTransaction::whereKey($rf->folio_transaction_id)->update(['business_date' => $y->toDateString(), 'created_at' => $y]);
            $p->events()->where('event', 'refunded')->update(['created_at' => $y]);
        }
        // in-house guest paid the bill balance from the mobile portal yesterday evening
        $stay = Models\ReservationRoom::where('status', 'checked_in')->whereNotNull('room_id')->orderBy('id')->get()
            ->first(fn ($s) => ($f = Models\Folio::where('reservation_room_id', $s->id)->where('status', 'open')->first()) && $f->totals()['balance'] > 1000);
        if ($stay && ($f = Models\Folio::where('reservation_room_id', $stay->id)->where('status', 'open')->first())) {
            Auth::logout();
            $p = $S::create(['amount' => min(8000, round((float) $f->totals()['balance'], 2)), 'purpose' => 'balance', 'hours' => 24, 'description' => 'Room '.$stay->room?->room_no.' – bill balance'], null, $f);
            $p = $payDemo($p, 'mobile_money');
            $back($p, $today->copy()->subDay()->setTime(19, 20), $today->copy()->subDay()->setTime(19, 23));
            Auth::login($fd);
        }
        // awaiting payment (sent by e-mail 5 hours ago), an expired and a cancelled link
        if ($r = $upcoming[3] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::send($p, ['email']);
            $back($p, now()->subHours(5));
        }
        if ($r = $upcoming[4] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 48], $r);
            $S::send($p, ['email']);
            $back($p, now()->subDays(3));
            $S::expire();
        }
        if ($r = $upcoming[5] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::cancel($p, 'Guest paid the advance by bank transfer instead');
            $back($p, now()->subDays(2));
        }
        // paid a different amount – needs review (not posted)
        if ($r = $upcoming[6] ?? null) {
            $p = $S::create(['amount' => $S::suggest($r, null), 'hours' => 72], $r);
            $S::start($p->fresh());
            $p = $S::complete($p->fresh(), ['state' => 'paid', 'amount' => round((float) $p->amount - 500, 2), 'currency' => $p->currency, 'payment_id' => 'DEMO-7F31C2A9D0', 'fee' => null, 'channel' => 'card'], 'return');
            $back($p, now()->subHours(9), now()->subHours(8));
        }
        // online booking on hold, deposit link open for 45 more minutes
        $type = Models\RoomType::where('code', 'DLX')->first();
        if ($type) {
            Auth::logout();
            $arr = $today->copy()->addDays(21);
            $quote = \App\Services\RateService::quote($type, $arr, $arr->copy()->addDays(2));
            $g = Models\Guest::create(['first_name' => 'Akosua', 'last_name' => 'Frimpong', 'email' => 'akosua.frimpong@example.com', 'phone' => '+233244555019']);
            $res = Models\Reservation::create(['reservation_no' => Numbering::next('reservation'), 'guest_id' => $g->id, 'business_source_id' => Models\BusinessSource::where('type', 'booking_engine')->value('id'),
                'arrival_date' => $arr, 'departure_date' => $arr->copy()->addDays(2), 'adults' => 2, 'children' => 0, 'status' => 'tentative', 'purpose' => 'Leisure']);
            $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $g->id, 'arrival_date' => $arr, 'departure_date' => $arr->copy()->addDays(2), 'rate' => $quote['average'], 'adults' => 2, 'status' => 'reserved']);
            $p = $S::create(['amount' => (float) $quote['nights'][0]['rate'], 'purpose' => 'booking', 'minutes' => 45], $res);
            $back($p, now()->subMinutes(15));
            Auth::login($fd);
        }
        // payout 2 days ago: the two oldest deposits, less 1.95 % fees, into the bank account
        $settled = collect($paidPl)->take(2);
        if ($settled->count()) {
            $gross = round($settled->sum(fn ($p) => $p->received()), 2);
            $S::recordPayout(['date' => $today->copy()->subDays(2)->toDateString(), 'provider' => 'demo', 'gross' => $gross, 'fees' => round($gross * 0.0195, 2),
                'payment_mode_id' => Models\PaymentMode::where('type', 'bank')->value('id'), 'reference' => 'STL-'.$today->copy()->subDays(2)->format('Ymd'), 'notes' => 'Settlement for '.$settled->pluck('pay_no')->implode(', ')]);
        }
        Auth::logout();
    }

    /**
     * v2.2 laundry & linen: a week of guest / walk-in laundry orders in every state (one from a portal request),
     * linen stock with par gaps, and laundry batches – billed, closed short, part-returned, open and in-house.
     */
    protected function seedLaundry(Models\Hotel $hotel, bool $rich, Carbon $today): void
    {
        foreach (['laundry_express_pct' => '50', 'laundry_normal_hours' => '24', 'laundry_express_hours' => '6', 'linen_par_levels' => '4'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        \App\Services\LaundryService::ensureDefaults();
        \App\Services\LinenService::ensureDefaults();
        $L = \App\Services\LaundryService::class;
        $N = \App\Services\LinenService::class;
        $hk = Models\User::where('hotel_id', $hotel->id)->where('username', 'housekeeping')->first();
        $cashier = Models\User::where('hotel_id', $hotel->id)->where('username', 'cashier')->first();
        $store = Models\User::where('hotel_id', $hotel->id)->where('username', 'laundry')->first() ?? $hk;
        if ($store ?? $hk) {
            Auth::login($store ?? $hk);
        }

        // ---------- linen stock: bought two months ago, most of it issued to the floors ----------
        $rooms = Models\Room::count();
        $types = Models\LinenType::orderBy('id')->get()->keyBy('code');
        $bought = ['BS-D' => 180, 'BS-S' => 40, 'DC' => 90, 'PC' => 400, 'BT' => 170, 'HT' => 196, 'FT' => 150, 'BM' => 70, 'BR' => 150, 'PT' => 60, 'TC' => 40, 'NP' => 240];
        $scale = $rich ? 1 : 0.4;
        foreach ($types as $code => $t) {
            $q = max(10, (int) round(($bought[$code] ?? 50) * $scale));
            $N::move($t, 'purchase', $q, ['date' => $today->copy()->subDays(60)->toDateString(), 'reason' => 'Opening linen stock']);
            $issue = (int) round($q * 0.55);
            $N::move($t->fresh(), 'issue', $issue, ['date' => $today->copy()->subDays(59)->toDateString(), 'reason' => 'Room sets / outlets']);
        }
        if ($rich) {
            Models\LinenType::whereIn('code', ['PT', 'TC'])->update(['par_override' => 60]);
            Models\LinenType::where('code', 'NP')->update(['par_override' => 200]);
        }
        $T = fn (string $c) => Models\LinenType::where('code', $c)->first();
        if (! $rich) {
            foreach (['BS-D' => 20, 'PC' => 30, 'BT' => 20] as $c => $q) {
                $N::move($T($c), 'collect', $q, ['date' => $today->toDateString()]);
            }
            Auth::forgetUser();

            return;
        }

        $vendor = Models\Supplier::create(['wht_exempt_ref' => 'GRA/WHT/EXM/2026/0142', 'wht_exempt_until' => $today->copy()->endOfYear()->toDateString(), // v4.4 exempt from WHT
            'name' => 'SparkleWash Laundry Services', 'contact_person' => 'Priya Nair', 'phone' => '+91 98860 41122', 'email' => 'accounts@sparklewash.example',
            'payment_terms_days' => 15, 'tax_number' => 'C0045778812', 'expense_head_id' => Models\AccountHead::sys('laundry_linen')]);
        Models\Setting::updateOrCreate(['key' => 'linen_default_supplier_id'], ['value' => (string) $vendor->id]);
        Models\Setting::$version++;
        $d = fn (int $ago) => $today->copy()->subDays($ago)->toDateString();
        $collect = function (int $ago, array $q) use ($N, $T, $d) {
            foreach ($q as $c => $n) {
                if (($issue = min($n, (int) $T($c)->qty_store)) > 0) { // clean sets go up as the soiled ones come down
                    $N::move($T($c), 'issue', $issue, ['date' => $d($ago), 'reason' => 'Replacement sets']);
                }
                $N::move($T($c), 'collect', $n, ['date' => $d($ago), 'reason' => 'Soiled from rooms']);
            }
        };
        $ids = fn (array $q) => collect($q)->mapWithKeys(fn ($n, $c) => [$T($c)->id => $n])->all();
        $backdate = function (Models\LinenBatch $b, ?int $closedAgo = null) use ($d) {
            if ($closedAgo !== null) {
                $b->forceFill(['closed_date' => $d($closedAgo)])->saveQuietly();
                Models\LinenMovement::where('linen_batch_id', $b->id)->where('type', 'lost')->update(['date' => $d($closedAgo)]);
            }
        };
        $lineMap = fn (Models\LinenBatch $b, array $q) => $b->fresh('lines.linen')->lines->mapWithKeys(fn ($l) => [$l->id => ['returned' => $q[$l->linen->code][0] ?? 0, 'damaged' => $q[$l->linen->code][1] ?? 0]])->all();

        // LB1: 9 days ago to SparkleWash, back 8 days ago with a few damaged → closed and billed
        $sent1 = ['BS-D' => 48, 'PC' => 96, 'BT' => 44, 'HT' => 40, 'FT' => 30, 'BM' => 20];
        $collect(9, $sent1);
        $b1 = $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(9), 'expected_date' => $d(8), 'notes' => 'Weekly room linen'], $ids($sent1));
        $N::receive($b1, $lineMap($b1, ['BS-D' => [46, 2], 'PC' => [96, 0], 'BT' => [43, 1], 'HT' => [40, 0], 'FT' => [30, 0], 'BM' => [20, 0]]), $d(8));
        $N::bill($b1->fresh(), ['supplier_ref' => 'SW/2026/0412', 'tax_rate' => 20, 'doc_date' => $d(8)]);
        // LB2: 6 days ago, part back 5 days ago, rest confirmed missing 3 days ago → closed short (lost), still to bill
        $sent2 = ['BS-D' => 40, 'DC' => 20, 'PC' => 80, 'BT' => 40, 'BR' => 10];
        $collect(6, $sent2);
        $b2 = $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(6), 'expected_date' => $d(5)], $ids($sent2));
        $N::receive($b2, $lineMap($b2, ['BS-D' => [38, 0], 'DC' => [20, 0], 'PC' => [77, 1], 'BT' => [40, 0], 'BR' => [9, 0]]), $d(5));
        $N::close($b2->fresh(), 'SparkleWash confirmed 2 sheets, 2 pillow covers and a bathrobe missing');
        $backdate($b2->fresh(), 3);
        // LB3: 2 days ago, part back yesterday, due yesterday → late
        $sent3 = ['BS-D' => 44, 'PC' => 88, 'BT' => 40, 'HT' => 36, 'FT' => 30];
        $collect(2, $sent3);
        $b3 = $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(2), 'expected_date' => $d(1)], $ids($sent3));
        $N::receive($b3, $lineMap($b3, ['BS-D' => [30, 0], 'PC' => [88, 0], 'BT' => [22, 0], 'HT' => [36, 0]]), $d(1));
        // LB4: in-house laundry yesterday – F&B linen, back the same day
        $sent4 = ['TC' => 18, 'NP' => 90];
        $collect(1, $sent4);
        $b4 = $N::send(['supplier_id' => null, 'sent_date' => $d(1), 'expected_date' => $d(1), 'notes' => 'Banquet linen – own washer'], $ids($sent4));
        $N::receive($b4, $lineMap($b4, ['TC' => [18, 0], 'NP' => [88, 2]]), $d(1));
        // LB5: today, open
        $sent5 = ['BS-D' => 36, 'PC' => 72, 'BT' => 34, 'BM' => 16];
        $collect(0, $sent5);
        $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(0), 'expected_date' => $today->copy()->addDay()->toDateString()], $ids($sent5));
        // soiled waiting today, a few write-offs and a count
        $collect(0, ['BS-D' => 14, 'PC' => 26, 'BT' => 12, 'HT' => 10, 'FT' => 8]);
        $N::move($T('BT'), 'discard', 3, ['from' => 'floor', 'date' => $d(4), 'reason' => 'Stained with hair dye – room 305']);
        $N::move($T('BR'), 'lost', 1, ['from' => 'floor', 'date' => $d(2), 'reason' => 'Missing after check-out, room 402 (charged to guest)']);
        $N::count($T('HT'), 'store', max(0, $T('HT')->qty_store - 4), 'Monthly linen count');
        Models\LinenMovement::where('type', 'adjust')->update(['date' => $d(0)]);
        Models\LinenMovement::whereNull('created_at')->orWhere('created_at', '>', now())->update(['created_at' => now()]);
        foreach (Models\LinenMovement::all() as $m) {
            $m->forceFill(['created_at' => Carbon::parse($m->date)->setTime(10, 0)->addMinutes($m->id % 300)])->saveQuietly();
        }

        // ---------- laundry orders ----------
        Auth::login($hk);
        $stays = Models\ReservationRoom::with('guest', 'room')->where('status', 'checked_in')->whereNotNull('room_id')->orderBy('room_id')->get()->values();
        if ($stays->count() < 6) {
            Auth::forgetUser();

            return;
        }
        $item = fn (string $name) => Models\LaundryItem::where('name', $name)->value('id');
        $lines = fn (array $spec) => collect($spec)->map(fn ($x) => ['laundry_item_id' => $item($x[0]), 'service' => $x[1], 'qty' => $x[2], 'remark' => $x[3] ?? null])->all();
        $at = function (Models\LaundryOrder $o, Carbon $collected, ?Carbon $ready = null, ?Carbon $delivered = null) use ($L) {
            $o = $o->fresh();
            $o->forceFill(['collected_at' => $collected, 'promised_at' => $collected->copy()->addHours($L::hours($o->speed)), 'created_at' => $collected,
                'ready_at' => $ready ?? $o->ready_at, 'delivered_at' => $delivered ?? $o->delivered_at, 'updated_at' => $delivered ?? $ready ?? $collected])->saveQuietly();
            if ($delivered && $o->folio_transaction_id) {
                Models\FolioTransaction::whereKey($o->folio_transaction_id)->update(['business_date' => $delivered->toDateString(), 'created_at' => $delivered, 'updated_at' => $delivered]);
                if ($o->type === 'walk_in') {
                    Models\FolioTransaction::where('folio_id', $o->folio_id)->update(['business_date' => $delivered->toDateString(), 'created_at' => $delivered, 'updated_at' => $delivered]);
                    Models\Folio::whereKey($o->folio_id)->update(['created_at' => $delivered, 'closed_at' => $delivered]);
                }
            }
        };
        $now = now();
        $day = fn (int $ago, int $h, int $m = 0) => $now->copy()->subDays($ago)->setTime($h, $m);
        $shirts = [['Shirt', 'wash', 3], ['Trousers', 'wash', 2], ['T-shirt', 'wash', 2]];

        // delivered to rooms (3, 2 and 1 days ago)
        foreach ([[0, 3, 'normal', [['Shirt', 'wash', 4], ['Trousers', 'wash', 2], ['Undergarment', 'wash', 3]]],
            [1, 2, 'express', [['Suit (2 pc)', 'dryclean', 1], ['Shirt', 'press', 2]]],
            [2, 1, 'normal', [['Saree', 'dryclean', 1, 'Silk – hand finish'], ['Kurta', 'wash', 2], ['Dress', 'wash', 1]]]] as [$i, $ago, $speed, $spec]) {
            $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[$i]->id, 'speed' => $speed, 'bag_no' => 'B-'.(100 + $i), 'lines' => $lines($spec)]);
            $L::markReady($o->fresh());
            $L::deliver($o->fresh());
            $c = $day($ago, 9, 30);
            $at($o, $c, $c->copy()->addHours($speed === 'express' ? 5 : 20), $c->copy()->addHours($speed === 'express' ? 6 : 22));
        }
        // walk-in customers – paid at the counter (cashier has the open shift)
        Auth::login($cashier);
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id') ?? $cash;
        foreach ([['Kofi Mensah', '+233 24 555 0142', 1, $cash, [['Suit (2 pc)', 'dryclean', 2], ['Tie', 'dryclean', 3]]],
            ['Neha Kapoor', '+91 98450 77120', 0, $card, [['Salwar suit (3 pc)', 'dryclean', 2], ['Sweater', 'dryclean', 1]]]] as [$name, $ph, $ago, $mode, $spec]) {
            $o = $L::create(['type' => 'walk_in', 'guest_name' => $name, 'phone' => $ph, 'speed' => 'normal', 'lines' => $lines($spec)]);
            $L::markReady($o->fresh());
            $L::deliver($o->fresh(), $mode);
            $c = $ago ? $day(2, 11) : $day(1, 10, 15);
            $at($o, $c, $c->copy()->addHours(20), $ago ? $day(1, 12) : $now->copy()->subHours(1));
        }
        Auth::login($hk);
        // ready to deliver, processing (one overdue), express in progress, collected but not itemised
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[3]->id, 'speed' => 'normal', 'bag_no' => 'B-114', 'lines' => $lines($shirts)]);
        $L::markReady($o->fresh());
        $at($o, $day(1, 8, 45), $now->copy()->subHours(2));
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[4]->id, 'speed' => 'normal', 'bag_no' => 'B-115', 'lines' => $lines([['Trousers', 'wash', 2], ['Shirt', 'wash', 3, 'Collar stain'], ['Socks (pair)', 'wash', 4]])]);
        $at($o, $now->copy()->subHours(27));
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[5]->id, 'speed' => 'express', 'bag_no' => 'B-116', 'notes' => 'Needed for a 6 pm meeting', 'lines' => $lines([['Jacket / blazer', 'dryclean', 1], ['Shirt', 'press', 2]])]);
        $at($o, $now->copy()->subHours(2));
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[0]->id, 'speed' => 'normal', 'bag_no' => 'B-117', 'bag_pieces' => 9]);
        $at($o, $now->copy()->subMinutes(40));
        // from a guest-portal request (in progress) and one request still waiting for pick-up
        if ($stays->count() > 7) {
            $req = \App\Services\GuestPortalService::raise($stays[6], ['category' => 'laundry', 'item' => 'Express laundry (same day)', 'notes' => 'Two shirts and a dress, please']);
            $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[6]->id, 'speed' => 'express', 'guest_request_id' => $req->id, 'bag_no' => 'B-118',
                'lines' => $lines([['Shirt', 'wash', 2], ['Dress', 'wash', 1]])]);
            $at($o, $now->copy()->subHours(1));
            \App\Services\GuestPortalService::raise($stays[7], ['category' => 'laundry', 'item' => 'Pick up my laundry', 'notes' => 'Bag is on the door hook']);
        }
        // cancelled
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[1]->id, 'speed' => 'normal', 'bag_no' => 'B-109', 'bag_pieces' => 3]);
        $L::cancel($o->fresh(), 'Guest took the bag back – will use the express service tomorrow');
        $at($o, $day(2, 18));
        Auth::forgetUser();
    }

    /**
     * v2.3 fixed assets & preventive maintenance: existing assets taken on at the June go-live with the depreciation
     * already charged, June and July depreciation posted (August is left for the demo), a direct purchase, an asset
     * bought on a supplier bill, one sold and one scrapped, and PM schedules with a history of on-time / late service.
     */
    protected function seedAssets(Models\Hotel $hotel, bool $rich, Carbon $today, Models\User $user): void
    {
        $eng = Models\User::where('hotel_id', $hotel->id)->where('username', 'engineer')->first();
        Auth::setUser($eng ?? $user);
        \App\Services\AssetService::ensureDefaults();
        $cat = Models\AssetCategory::pluck('id', 'code');
        $rooms = Models\Room::orderBy('room_no')->get();
        $emp = fn ($n) => Models\Employee::where('name', $n)->value('id');
        $goLive = Carbon::parse('2026-06-01');
        // accumulated depreciation up to the day before go-live, by the category rule
        $accTo = function (float $cost, string $code, string $bought) use ($goLive) {
            $c = Models\AssetCategory::where('code', $code)->first();
            $res = round($cost * (float) $c->residual_pct / 100, 2);
            $months = Carbon::parse($bought)->diffInMonths($goLive);
            $acc = $c->method === 'wdv' ? $cost * (1 - pow(1 - (float) $c->wdv_rate / 100, $months / 12)) : ($cost - $res) / $c->life_months * $months;

            return min(round($acc, -1), $cost - $res);
        };
        // [name, cat, cost, bought, room idx|null, location, department, make, model, serial, warranty, amc vendor, amc until]
        $list = [
            ['Guest room furniture – Deluxe (8 rooms)', 'FF', 960000, '2022-04-10', null, 'Deluxe floor', 'Rooms', 'Godrej Interio', 'Hotel series', null, null, null, null],
            ['Lobby sofas & lounge furniture', 'FF', 285000, '2021-11-05', null, 'Lobby', 'Front Office', 'Durian', null, null, null, null, null],
            ['Restaurant tables & chairs (60 covers)', 'FF', 420000, '2022-01-15', null, 'Restaurant', 'F&B – Restaurant & Bar', null, null, null, null, null, null],
            ['Banquet chairs (200) & round tables (20)', 'FF', 380000, '2023-02-01', null, 'Banquet hall', 'Banquets', null, null, null, null, null, null],
            ['Combi oven – Rational iCombi Pro 10-1/1', 'KIT', 865000, '2023-06-20', null, 'Main kitchen', 'F&B – Kitchen', 'Rational', 'iCombi Pro 10-1/1', 'E11RI2306451', '2025-06-19', 'Rational Service India', '2027-06-30'],
            ['Commercial dishwasher – Hobart AM-15', 'KIT', 410000, '2024-03-12', null, 'Main kitchen', 'F&B – Kitchen', 'Hobart', 'AM-15', 'HB-AM15-88213', '2026-11-11', null, null],
            ['Walk-in cold room 8×10 ft', 'KIT', 520000, '2022-08-01', null, 'Main kitchen', 'F&B – Kitchen', 'Blue Star', null, null, null, null, null],
            ['Washer-extractor 25 kg', 'KIT', 690000, '2023-09-01', null, 'Laundry', 'Housekeeping', 'Electrolux', 'WH6-27', 'EL-WH6-27-5521', '2025-08-31', null, null],
            ['Tumble dryer 15 kg (old)', 'KIT', 240000, '2019-07-01', null, 'Laundry', 'Housekeeping', 'IFB', 'TD-15', null, null, null, null],
            ['Chiller plant 60 TR', 'HVAC', 3200000, '2021-04-01', null, 'Roof plant room', 'Engineering', 'Carrier', '30RB-060', 'CR30RB-21-0098', null, 'CoolAir HVAC Services', '2027-03-31'],
            ['DG set 250 kVA', 'HVAC', 1850000, '2021-04-01', null, 'Utility yard', 'Engineering', 'Cummins', 'C250D5', 'CUM-250-4471', null, 'Powerica Service', '2026-12-31'],
            ['Passenger lift – 8 persons', 'HVAC', 2400000, '2021-04-01', null, 'Lobby core', 'Engineering', 'Otis', 'Gen2 Life', 'OT-GL-77310', null, 'Otis Elevator Co.', '2027-03-31'],
            ['Solar water heater 3,000 LPD', 'HVAC', 360000, '2022-12-01', null, 'Roof', 'Engineering', 'Racold', null, null, null, null, null],
            ['Split AC 1.5 T', 'HVAC', 48000, '2024-05-10', 0, null, 'Rooms', 'Daikin', 'FTKF50', 'DK50-240510-01', '2027-05-09', null, null],
            ['Split AC 1.5 T', 'HVAC', 48000, '2024-05-10', 1, null, 'Rooms', 'Daikin', 'FTKF50', 'DK50-240510-02', '2027-05-09', null, null],
            ['Split AC 1.5 T', 'HVAC', 48000, '2024-05-10', 2, null, 'Rooms', 'Daikin', 'FTKF50', 'DK50-240510-03', '2027-05-09', null, null],
            ['Front-office PCs (4) & receipt printers', 'IT', 220000, '2024-01-10', null, 'Front desk', 'Front Office', 'HP', 'ProDesk 400', null, '2026-10-20', null, null],
            ['Wi-Fi network – 30 access points & controller', 'IT', 540000, '2023-07-01', null, 'Whole building', 'Administration', 'Ubiquiti', 'UniFi U6', null, null, null, null],
            ['Smart TVs 43" (24 rooms)', 'IT', 720000, '2023-10-15', null, 'Guest rooms', 'Rooms', 'Samsung', 'HG43 hospitality', null, '2026-10-14', null, null],
            ['CCTV – 32 cameras & NVR', 'IT', 310000, '2022-09-01', null, 'Security room', 'Administration', 'Hikvision', null, null, null, null, null],
            ['Airport shuttle – Toyota Innova Crysta', 'VEH', 2150000, '2023-11-20', null, 'Porch', 'Transport', 'Toyota', 'Innova Crysta 2.4', 'MH12-TX-4471', null, null, null],
            ['Staff van – Maruti Eeco (old)', 'VEH', 520000, '2019-02-15', null, 'Porch', 'Transport', 'Maruti Suzuki', 'Eeco', 'MH12-KL-1186', null, null, null],
            ['Rooftop bar fit-out', 'BLD', 1400000, '2024-02-01', null, 'Roof', 'F&B – Restaurant & Bar', null, null, null, null, null, null],
        ];
        if (! $rich) {
            $list = [$list[0], $list[4], $list[9], $list[17]];
        }
        $A = [];
        foreach ($list as $i => [$name, $code, $cost, $bought, $room, $loc, $dept, $make, $model, $serial, $warr, $amc, $amcUntil]) {
            $cost = $rich ? $cost : round($cost * .4, -3);
            $roomM = $room !== null ? ($rooms[$room] ?? null) : null;
            $A[$name.($roomM ? ' '.$roomM->room_no : '')] = \App\Services\AssetService::create([
                'name' => $name.($roomM ? ' – Room '.$roomM->room_no : ''), 'asset_category_id' => $cat[$code], 'room_id' => $roomM?->id, 'location' => $loc, 'department' => $dept,
                'make' => $make, 'model' => $model, 'serial_no' => $serial, 'acquired_via' => 'opening', 'purchase_date' => $bought, 'dep_start' => $goLive->toDateString(), 'cost' => $cost,
                'opening_acc_dep' => $accTo($cost, $code, $bought), 'warranty_until' => $warr, 'amc_vendor' => $amc, 'amc_until' => $amcUntil,
                'custodian_id' => in_array($dept, ['Engineering', 'Rooms']) ? $emp('Joseph Thomas') : ($dept === 'F&B – Kitchen' ? $emp('Mohan Lal') : null),
                'reference' => 'Take-on '.$goLive->format('M Y'),
            ]);
        }
        Models\Asset::query()->update(['created_at' => $goLive->copy()->setTime(10, 0)]);
        if (! $rich) {
            Auth::forgetUser();

            return;
        }
        $bank = Models\PaymentMode::where('type', 'bank')->value('id');

        // June depreciation, then the old staff van is sold on 10 July
        \App\Services\AssetService::run(Carbon::parse('2026-06-30'));
        \App\Services\AssetService::dispose($A['Staff van – Maruti Eeco (old)']->fresh(), ['disposal_type' => 'sold', 'disposed_date' => '2026-07-10', 'disposal_proceeds' => 185000,
            'disposal_mode_id' => $bank, 'disposal_note' => 'Sold to Sai Motors, Pune (RC transferred)']);
        // a direct purchase in July (paid by bank transfer)
        $espresso = \App\Services\AssetService::create(['name' => 'Espresso machine – La Marzocco Linea PB 2-group', 'asset_category_id' => $cat['KIT'], 'location' => 'Rooftop bar',
            'department' => 'F&B – Restaurant & Bar', 'make' => 'La Marzocco', 'model' => 'Linea PB AV 2GR', 'serial_no' => 'LM-PB2-66120', 'acquired_via' => 'direct', 'payment_mode_id' => $bank,
            'reference' => 'GIP 90112 / BrewTech inv 2207', 'purchase_date' => '2026-07-18', 'cost' => 385000, 'warranty_until' => '2027-07-17', 'custodian_id' => $emp('Sunil Yadav')]);
        $espresso->forceFill(['created_at' => Carbon::parse('2026-07-18 15:20')])->save();
        \App\Services\AssetService::run(Carbon::parse('2026-07-31'));
        // the old tumble dryer is scrapped in August (motor burnt out)
        \App\Services\AssetService::dispose($A['Tumble dryer 15 kg (old)']->fresh(), ['disposal_type' => 'scrapped', 'disposed_date' => '2026-08-05', 'disposal_note' => 'Motor burnt out – repair uneconomical, sold as scrap for nil value']);
        // a laptop bought on a supplier bill: one line capitalised, the licence expensed
        $tech = Models\Supplier::create(['wht_category' => 'goods', 'name' => 'TechWorld IT Solutions', 'contact_person' => 'Nikhil', 'phone' => '+91 98220 44310', 'payment_terms_days' => 30,
            'expense_head_id' => Models\AccountHead::where('name', 'Internet & Telephone')->value('id'), 'tax_number' => 'C0055218830']);
        Auth::setUser(Models\User::where('hotel_id', $hotel->id)->where('username', 'accounts')->first() ?? $user);
        $bill = ApService::expenseBill($tech, ['supplier_ref' => 'TW/26-27/0815', 'doc_date' => '2026-08-12', 'lines' => [
            ['account_head_id' => Models\AccountHead::sys('fixed_assets'), 'description' => 'Dell Latitude 5450 laptop – GM office', 'amount' => 98000, 'tax_rate' => 20],
            ['account_head_id' => Models\AccountHead::where('name', 'Internet & Telephone')->value('id'), 'description' => 'Microsoft 365 Business – annual licence', 'amount' => 14400, 'tax_rate' => 20],
        ]]);
        Auth::setUser($eng ?? $user);
        $laptop = \App\Services\AssetService::create(['name' => 'Laptop – Dell Latitude 5450 (GM office)', 'asset_category_id' => $cat['IT'], 'location' => 'GM office', 'department' => 'Administration',
            'make' => 'Dell', 'model' => 'Latitude 5450', 'serial_no' => 'DL5450-7GQ2K', 'acquired_via' => 'bill', 'supplier_invoice_id' => $bill->id, 'purchase_date' => '2026-08-12',
            'cost' => 98000, 'warranty_until' => '2029-08-11', 'reference' => 'TW/26-27/0815']);
        $laptop->forceFill(['created_at' => Carbon::parse('2026-08-13 11:05')])->save();

        // ---------- preventive maintenance ----------
        $d = fn (int $days) => $today->copy()->addDays($days)->toDateString();
        $sched = function (array $x) {
            return Models\PmSchedule::create($x + ['priority' => 'normal', 'lead_days' => 3, 'is_active' => true]);
        };
        $S = [
            'chiller' => $sched(['title' => 'Chiller quarterly service', 'asset_id' => $A['Chiller plant 60 TR']->id, 'category' => 'ac', 'every' => 3, 'every_unit' => 'month', 'next_due' => '2026-09-26', 'lead_days' => 7, 'vendor' => 'CoolAir HVAC Services',
                'checklist' => "Check refrigerant pressures & superheat\nClean condenser coils\nInspect compressor oil level\nTest safety cut-outs\nLog amps per phase", 'priority' => 'high']),
            'acfilter' => $sched(['title' => 'AC filter cleaning – guest rooms', 'location' => 'All guest rooms', 'category' => 'ac', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(-4), 'employee_id' => $emp('Joseph Thomas'),
                'checklist' => "Remove & wash filters in every room\nWipe indoor unit & grill\nCheck drain line for blockage\nNote any unit not cooling"]),
            'dg' => $sched(['title' => 'DG set monthly load test & oil check', 'asset_id' => $A['DG set 250 kVA']->id, 'category' => 'electrical', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(2), 'employee_id' => $emp('Joseph Thomas'),
                'checklist' => "Run on load for 30 minutes\nCheck engine oil & coolant\nCheck battery voltage\nRecord diesel level & hours meter"]),
            'oven' => $sched(['title' => 'Combi oven descaling & door seal check', 'asset_id' => $A['Combi oven – Rational iCombi Pro 10-1/1']->id, 'category' => 'general', 'every' => 2, 'every_unit' => 'week', 'next_due' => $d(10), 'lead_days' => 2,
                'checklist' => "Run CareControl descaling cycle\nInspect door gasket\nClean core-temperature probe"]),
            'fire' => $sched(['title' => 'Fire extinguisher & hydrant inspection', 'location' => 'All floors', 'category' => 'general', 'every' => 6, 'every_unit' => 'month', 'next_due' => '2026-11-15', 'lead_days' => 10, 'vendor' => 'SafeGuard Fire Services',
                'checklist' => "Check pressure gauges & seals\nRefill / replace expired units\nTest hydrant pump\nUpdate inspection tags", 'priority' => 'high']),
            'lift' => $sched(['title' => 'Lift monthly safety inspection', 'asset_id' => $A['Passenger lift – 8 persons']->id, 'category' => 'electrical', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(15), 'vendor' => 'Otis Elevator Co.',
                'checklist' => "Brake & door interlocks\nEmergency alarm & intercom\nRescue device (ARD)\nMachine room cleanliness", 'priority' => 'high']),
            'washer' => $sched(['title' => 'Washer-extractor lubrication & belt check', 'asset_id' => $A['Washer-extractor 25 kg']->id, 'category' => 'general', 'every' => 1, 'every_unit' => 'month', 'next_due' => $d(-9), 'employee_id' => $emp('Imran Ali'),
                'checklist' => "Grease bearings\nCheck drive belt tension\nClean lint & detergent lines"]),
            'pool' => $sched(['title' => 'Pool pump & filter backwash', 'location' => 'Pool plant room', 'category' => 'plumbing', 'every' => 1, 'every_unit' => 'week', 'next_due' => $d(1), 'lead_days' => 1, 'employee_id' => $emp('Imran Ali'),
                'checklist' => "Backwash sand filter\nClean pump strainer basket\nTest pH & chlorine"]),
        ];
        // service history (resolved PM tickets): [schedule, due, done, cost, note]
        $hist = [
            ['chiller', '2026-06-26', '2026-06-25', 24500, 'Quarterly service done by CoolAir – gas pressures normal, condenser coils cleaned'],
            ['acfilter', '2026-07-22', '2026-07-21', 0, 'All 24 rooms done'], ['acfilter', $d(-35), $d(-33), 0, 'Done – rooms 105 & 110 filters replaced (torn)'],
            ['dg', '2026-07-28', '2026-07-28', 0, 'Load test OK, 412 hrs'], ['dg', $d(-29), $d(-29), 0, 'Load test OK – topped up 2 L coolant'],
            ['oven', $d(-18), $d(-18), 0, 'Descaled'], ['oven', $d(-4), $d(-1), 0, 'Descaled – door gasket worn, replacement ordered'],
            ['lift', $d(-15), $d(-15), 0, 'Monthly inspection by Otis – all OK'],
            ['washer', '2026-07-17', '2026-07-19', 0, 'Bearings greased'], ['washer', $d(-40), $d(-40), 1800, 'Drive belt replaced'],
            ['pool', $d(-13), $d(-13), 0, 'Backwash done, pH 7.4'], ['pool', $d(-6), $d(-5), 0, 'Backwash done, chlorine topped up'],
        ];
        foreach ($hist as [$k, $due, $done, $cost, $note]) {
            $s = $S[$k];
            $t = Models\MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'room_id' => $s->room_id ?? $s->asset?->room_id, 'asset_id' => $s->asset_id, 'pm_schedule_id' => $s->id,
                'location' => $s->location ?? $s->asset?->location, 'category' => $s->category, 'title' => 'PM: '.$s->title, 'description' => "Checklist:\n".$s->checklist, 'priority' => $s->priority,
                'status' => 'closed', 'due_date' => $due, 'employee_id' => $s->employee_id, 'reported_by' => ($eng ?? $user)->id, 'cost' => $cost, 'resolution' => $note,
                'resolved_at' => Carbon::parse($done)->setTime(16, 30)]);
            $t->forceFill(['created_at' => Carbon::parse($due)->subDays($s->lead_days)->setTime(6, 0), 'updated_at' => Carbon::parse($done)->setTime(16, 30)])->save();
            $s->forceFill(['last_done' => $done])->save();
        }
        // a breakdown on the dishwasher: asset under repair with an open ticket
        $dw = $A['Commercial dishwasher – Hobart AM-15'];
        Models\MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'asset_id' => $dw->id, 'location' => 'Main kitchen', 'category' => 'electrical', 'title' => 'Dishwasher not heating – rinse below 82 °C',
            'description' => 'Rinse booster not reaching temperature; washing by hand meanwhile.', 'priority' => 'urgent', 'status' => 'in_progress', 'due_date' => $d(1), 'employee_id' => $emp('Joseph Thomas'), 'reported_by' => $user->id]);
        \App\Services\AssetService::setStatus($dw, 'in_repair', 'booster heater fault');
        // today's auto-generated PM tickets (overdue AC filters & washer, DG / chiller / pool due)
        \App\Services\PmService::generate($today);
        Models\MaintenanceRequest::where('pm_schedule_id', $S['acfilter']->id)->whereIn('status', ['open'])->update(['status' => 'in_progress', 'created_at' => $today->copy()->subDays(7)->setTime(6, 0)]);
        Models\MaintenanceRequest::where('pm_schedule_id', $S['washer']->id)->whereIn('status', ['open'])->update(['created_at' => $today->copy()->subDays(12)->setTime(6, 0)]);
        Auth::forgetUser();
    }

    /** v2.4: main-store stock, department heads / budgets / cost accounts, sub-store pars and a month of requisitions. */
    protected function seedRequisitions(Models\Hotel $hotel, bool $rich, Carbon $today, Models\User $user): void
    {
        \App\Services\RequisitionService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $U = Models\User::where('hotel_id', $hotel->id)->get()->keyBy('username');
        $D = Models\Department::pluck('id', 'name');
        $S = Models\Store::pluck('id', 'type');
        $misc = Models\AccountHead::where('code', '6110')->first();
        $stationery = Models\AccountHead::firstOrCreate(['code' => '6140'], ['name' => 'Printing & Stationery', 'type' => 'expense', 'group_name' => $misc?->group_name, 'is_active' => true]);
        foreach ([
            'Front Office' => [$U['fom']->id, $stationery->id, null, 8000], 'Housekeeping' => [$U['housekeeping']->id, null, null, 25000],
            'Kitchen' => [$user->id, null, $S['kitchen'], 60000], 'F&B Service' => [$user->id, null, $S['bar'], 15000],
            'Maintenance' => [$U['engineer']->id, null, null, 12000], 'Accounts' => [null, $stationery->id, null, null], 'Management' => [null, $stationery->id, null, null],
        ] as $name => [$hod, $head, $store, $budget]) {
            $d = Models\Department::find($D[$name]);
            $d->forceFill(['hod_user_id' => $hod, 'expense_head_id' => $head ?? $d->expense_head_id, 'default_store_id' => $store ?? $d->default_store_id, 'monthly_budget' => $budget])->save();
        }
        Models\Setting::updateOrCreate(['key' => 'req_auto_approve_below'], ['value' => '500']);

        // main store: new stationery / engineering / F&B-service items + opening stock taken on 1 Sep
        $cats = collect(['Printing & Stationery', 'Engineering Spares', 'F&B Service Supplies', 'Cleaning Chemicals'])->mapWithKeys(fn ($c) => [$c => Models\ItemCategory::firstOrCreate(['name' => $c])->id]);
        $code = Models\InventoryItem::count();
        foreach ([['Printing & Stationery', 'A4 Paper Ream', 'ream', 10, 280], ['Printing & Stationery', 'Printer Toner HP 88A', 'pcs', 2, 1850], ['Printing & Stationery', 'Ball Pens (box of 10)', 'box', 5, 60],
            ['Printing & Stationery', 'Registration Card Pad', 'pad', 4, 250], ['Engineering Spares', 'LED Bulb 9W', 'pcs', 20, 95], ['Engineering Spares', 'Tube Light 20W LED', 'pcs', 10, 180],
            ['Engineering Spares', 'AA Batteries (pack of 4)', 'pack', 10, 120], ['Engineering Spares', 'PVC Insulation Tape', 'roll', 10, 25], ['Engineering Spares', 'AC Filter Pad', 'pcs', 6, 450],
            ['F&B Service Supplies', 'Paper Napkins (pack of 100)', 'pack', 10, 85], ['Cleaning Chemicals', 'Glass Cleaner 500ml', 'btl', 6, 140], ['Cleaning Chemicals', 'Toilet Cleaner 1L', 'btl', 10, 110],
            ['Cleaning Chemicals', 'Garbage Bags (roll of 30)', 'roll', 10, 90]] as [$c, $n, $u, $ro, $cost]) {
            Models\InventoryItem::create(['item_category_id' => $cats[$c], 'code' => 'ITM'.str_pad((string) ++$code, 3, '0', STR_PAD_LEFT), 'name' => $n, 'unit' => $u, 'reorder_level' => $ro, 'last_cost' => $cost, 'tax_rate' => 20]);
        }
        $I = Models\InventoryItem::get()->keyBy('name');
        $open = $today->copy()->subDays(25);
        foreach (['Basmati Rice' => 120, 'Refined Oil' => 60, 'Paneer' => 12, 'Chicken' => 8, 'Mineral Water 1L' => 300, 'Soft Drinks 300ml' => 200, 'Shampoo Sachet' => 600, 'Soap 30g' => 600,
            'Dental Kit' => 300, 'Floor Cleaner 5L' => 2, 'A4 Paper Ream' => 30, 'Printer Toner HP 88A' => 4, 'Ball Pens (box of 10)' => 20, 'Registration Card Pad' => 10, 'LED Bulb 9W' => 80,
            'Tube Light 20W LED' => 25, 'AA Batteries (pack of 4)' => 30, 'PVC Insulation Tape' => 40, 'AC Filter Pad' => 4, 'Paper Napkins (pack of 100)' => 40, 'Glass Cleaner 500ml' => 20,
            'Toilet Cleaner 1L' => 30, 'Garbage Bags (roll of 30)' => 30] as $n => $q) {
            Models\StockMovement::create(['inventory_item_id' => $I[$n]->id, 'store_id' => $S['main'], 'date' => $open, 'type' => 'opening', 'qty' => $q, 'rate' => $I[$n]->last_cost, 'reference' => 'Opening', 'remarks' => 'Main store opening count', 'user_id' => $user->id]);
        }
        foreach (['kitchen' => ['Basmati Rice' => 60, 'Refined Oil' => 40, 'Paneer' => 10, 'Chicken' => 20], 'bar' => ['Mineral Water 1L' => 120, 'Soft Drinks 300ml' => 96, 'Paper Napkins (pack of 100)' => 12]] as $st => $pars) {
            foreach ($pars as $n => $par) {
                Models\StorePar::create(['store_id' => $S[$st], 'inventory_item_id' => $I[$n]->id, 'par_qty' => $par]);
            }
        }

        $RS = \App\Services\RequisitionService::class;
        $at = fn (int $daysAgo, string $time) => $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);
        $ids = fn ($r, array $byName) => collect($byName)->mapWithKeys(fn ($q, $n) => [$r->lines->first(fn ($l) => $l->item->name === $n)->id => $q])->all();
        $mk = function (string $who, string $dept, int $daysAgo, array $lines, array $o = []) use ($RS, $U, $D, $S, $I, $at) {
            Auth::setUser($U[$who] ?? $U['admin']);
            $d = Models\Department::find($D[$dept]);
            $r = $RS::saveDraft(['department_id' => $d->id, 'from_store_id' => $S['main'], 'to_store_id' => $d->default_store_id, 'priority' => $o['priority'] ?? 'normal',
                'required_by' => null, 'purpose' => $o['purpose'] ?? null, 'lines' => collect($lines)->map(fn ($q, $n) => ['item_id' => $I[$n]->id, 'qty' => $q])->values()->all()]);
            $r->forceFill(['req_date' => $at($daysAgo, '08:00')->toDateString(), 'created_at' => $at($daysAgo, $o['time'] ?? '08:30'),
                'required_by' => isset($o['need']) ? $at($daysAgo - $o['need'], '00:00')->toDateString() : null])->save();
            if (! ($o['draft'] ?? false)) {
                $RS::submit($r);
                $r->forceFill(['submitted_at' => $at($daysAgo, $o['time'] ?? '08:30')->addMinutes(5)])->save();
            }

            return $r->fresh(['lines.item', 'department']);
        };
        $approve = function ($r, string $who, $when, array $qty = [], ?string $note = null, bool $over = false) use ($RS, $U, $ids) {
            Auth::setUser($U[$who]);
            $r = $RS::approve($r->fresh(['lines.item', 'department']), $ids($r, $qty), $note, $over);
            $r->forceFill(['approved_at' => $when])->save();

            return $r->fresh(['lines.item', 'department']);
        };
        $issue = function ($r, $when, array $qty = [], ?string $notes = null) use ($RS, $U, $ids) {
            Auth::setUser($U['store']);
            $r = $r->fresh(['lines.item']);
            $q = $qty ? $ids($r, $qty) : $r->lines->mapWithKeys(fn ($l) => [$l->id => $l->outstanding()])->all();
            $i = $RS::issue($r, $q, $when->toDateString(), $notes);
            $i->forceFill(['created_at' => $when])->save();

            return $i;
        };
        $ack = function ($i, string $who, $when, ?string $note = null) use ($RS, $U) {
            Auth::setUser($U[$who]);
            $RS::acknowledge($i, $note);
            $i->forceFill(['received_at' => $when])->save();
        };

        // 1. kitchen weekly dry goods → kitchen store (transfer), issued in full
        $r = $mk('chef', 'Kitchen', 23, ['Basmati Rice' => 20, 'Refined Oil' => 10, 'Paneer' => 5], ['purpose' => 'Weekly dry-store top-up']);
        $r = $approve($r, 'admin', $at(23, '09:10'));
        $ack($issue($r, $at(23, '10:05')), 'chef', $at(23, '10:30'));
        // 2. housekeeping guest amenities & chemicals, soap cut by the HOD, issued (expensed to guest supplies)
        $r = $mk('laundry', 'Housekeeping', 21, ['Shampoo Sachet' => 150, 'Soap 30g' => 150, 'Dental Kit' => 80, 'Glass Cleaner 500ml' => 6, 'Toilet Cleaner 1L' => 10, 'Garbage Bags (roll of 30)' => 12], ['purpose' => 'Amenities & chemicals for the week', 'time' => '16:00']);
        $r = $approve($r, 'housekeeping', $at(21, '17:20'), ['Soap 30g' => 120], 'Soap par on floors is 120 – rest next week');
        $ack($issue($r, $at(20, '09:30')), 'laundry', $at(20, '11:00'));
        // 3. kitchen paneer – store short, part issued and closed short
        $r = $mk('chef', 'Kitchen', 18, ['Paneer' => 10, 'Refined Oil' => 5], ['purpose' => 'Banquet – Mensah–Owusu wedding prep']);
        $r = $approve($r, 'admin', $at(18, '09:00'));
        $ack($issue($r, $at(18, '11:40'), ['Paneer' => 6, 'Refined Oil' => 5], 'Only 6 kg paneer in store'), 'chef', $at(18, '12:15'));
        Auth::setUser($U['store']);
        $RS::closeShort($r->fresh(), 'Paneer balance bought fresh from the market by the chef');
        $r->forceFill(['closed_at' => $at(17, '10:00')])->save();
        // 4. maintenance spares – AC filter pads short (part issued, still open)
        $r = $mk('engineer', 'Maintenance', 16, ['LED Bulb 9W' => 30, 'Tube Light 20W LED' => 10, 'AA Batteries (pack of 4)' => 12, 'AC Filter Pad' => 12], ['purpose' => 'Monthly electrical spares + AC filter replacement (PM)']);
        $r = $approve($r, 'accounts', $at(16, '12:00'));
        $ack($issue($r, $at(15, '10:15'), ['LED Bulb 9W' => 30, 'Tube Light 20W LED' => 10, 'AA Batteries (pack of 4)' => 12, 'AC Filter Pad' => 4]), 'engineer', $at(15, '10:40'), 'AC filter pads – 8 still to come');
        // 5. front office stationery – issued, not yet acknowledged
        $r = $mk('fom', 'Front Office', 11, ['A4 Paper Ream' => 10, 'Printer Toner HP 88A' => 2, 'Ball Pens (box of 10)' => 5, 'Registration Card Pad' => 4], ['purpose' => 'Monthly stationery']);
        $r = $approve($r, 'admin', $at(11, '11:00'));
        $issue($r, $at(11, '15:30'), [], 'Collected by Priya');
        // 6. bar par top-up → bar store (transfer)
        $r = $mk('cashier', 'F&B Service', 8, ['Mineral Water 1L' => 96, 'Soft Drinks 300ml' => 72, 'Paper Napkins (pack of 100)' => 8], ['purpose' => 'Bar par top-up']);
        $r = $approve($r, 'admin', $at(8, '10:00'));
        $ack($issue($r, $at(7, '09:00')), 'cashier', $at(7, '09:20'));
        // 7. rejected
        $r = $mk('laundry', 'Housekeeping', 4, ['Soap 30g' => 40, 'Dental Kit' => 40], ['purpose' => 'Extra amenities for the group check-in', 'time' => '14:00']);
        Auth::setUser($U['housekeeping']);
        $RS::reject($r->fresh(['department']), 'Already covered by REQ-00002 – use the floor stock first');
        $r->forceFill(['approved_at' => $at(4, '15:10')])->save();
        // 8. kitchen, urgent, awaiting the GM's approval (chicken & paneer short in the main store)
        $mk('chef', 'Kitchen', 2, ['Chicken' => 15, 'Paneer' => 6, 'Basmati Rice' => 10], ['purpose' => 'Weekend buffet', 'priority' => 'urgent', 'need' => 2, 'time' => '18:30']);
        // 9. housekeeping, awaiting the housekeeping supervisor
        $mk('laundry', 'Housekeeping', 1, ['Shampoo Sachet' => 100, 'Soap 30g' => 100, 'Floor Cleaner 5L' => 4], ['purpose' => 'Amenities & chemicals for the week', 'time' => '16:10']);
        // 10. maintenance, approved over budget, waiting for the store
        $r = $mk('engineer', 'Maintenance', 1, ['LED Bulb 9W' => 20, 'PVC Insulation Tape' => 10], ['purpose' => 'Lift lobby & DG room lighting failure', 'priority' => 'urgent', 'need' => 1, 'time' => '19:00']);
        $approve($r, 'accounts', $at(1, '19:40'), [], 'Lighting failure in the lift lobby & DG room – safety issue', true);
        // 11. front office draft (would take the department over budget)
        $mk('fom', 'Front Office', 0, ['Registration Card Pad' => 2, 'Ball Pens (box of 10)' => 2], ['purpose' => 'Group check-ins next week', 'draft' => true, 'time' => '09:00']);
        Auth::setUser($user);
    }

    protected function seedLedger(bool $rich, Carbon $today): void
    {
        $sys = fn ($k) => Models\AccountHead::sys($k);
        // v2.3: fixed assets come from the asset register's opening (take-on) assets
        $opening = Models\Asset::where('acquired_via', 'opening');
        $faCost = round((float) (clone $opening)->sum('cost'), 2);
        $faAcc = round((float) (clone $opening)->sum('opening_acc_dep'), 2);
        $first = collect([Models\FolioTransaction::min('business_date'), Models\Voucher::min('date'), Models\StockMovement::min('date'), Models\SupplierInvoice::min('doc_date')])->filter()->min();
        $openDate = Carbon::parse($first ?? $today)->subDay()->toDateString();
        $k = $rich ? 1 : 0.3;
        LedgerService::post(['date' => $openDate, 'reference' => 'OB-'.substr($openDate, 0, 4), 'narration' => 'Opening balances brought forward', 'lines' => [
            [$sys('bank'), 2500000 * $k, 'Current account'], [$sys('cash'), 50000 * $k, 'Front office float'],
            [$sys('fixed_assets'), $faCost, 'Asset register take-on'], [$sys('acc_depreciation'), -$faAcc, 'Asset register take-on'],
            [$sys('capital'), -(2550000 * $k + $faCost - $faAcc), null],
        ]], 'manual', null);
        LedgerService::sync(true);
    }

    protected function seedPhase6(Models\Hotel $hotel, bool $rich, Carbon $today): void
    {
        foreach ([['Mineral Water 500ml', 40], ['Soft Drink (can)', 80], ['Beer (can)', 220], ['Salted Nuts', 150], ['Chocolate Bar', 120], ['Potato Chips', 90]] as $i => [$n, $p]) {
            Models\MinibarItem::create(['name' => $n, 'price' => $p, 'sort_order' => $i + 1, 'is_active' => true]);
        }
        foreach ([['Swedish Massage (60 min)', 'spa', 60, 3500], ['Deep Tissue Massage (90 min)', 'spa', 90, 4800], ['Signature Facial', 'spa', 60, 3000], ['Hot Stone Therapy', 'spa', 75, 4200],
            ['Manicure & Pedicure', 'salon', 60, 1800], ['Haircut & Styling', 'salon', 45, 1200], ['Personal Training Session', 'fitness', 60, 1500], ['Morning Yoga (group)', 'fitness', 60, 600],
            ['City Heritage Tour', 'tour', 180, 2500], ['Sunset Kayaking', 'activity', 90, 2000]] as [$n, $c, $d, $p]) {
            Models\SpaService::create(['name' => $n, 'category' => $c, 'duration_min' => $d, 'price' => $rich ? $p : round($p * .8), 'is_active' => true]);
        }
        foreach (['Treatment Room 1', 'Treatment Room 2', 'Couples Suite', 'Salon Chair'] as $n) {
            Models\SpaRoom::create(['name' => $n, 'is_active' => true]);
        }

        // Housekeeping: today's credit-balanced assignment, then a realistic mid-morning state
        \App\Services\HousekeepingService::generate($today->toDateString());
        if ($rich) {
            $tasks = Models\HousekeepingTask::with('room')->where('task_date', $today)->whereNotNull('room_id')->orderBy('id')->get()->groupBy('employee_id')->values();
            $all = \App\Services\HousekeepingService::checklist();
            foreach ($tasks as $gi => $list) {
                foreach ($list->values() as $i => $t) {
                    if ($i === 0 || ($i === 1 && $gi === 0)) {           // done – one passed, rest waiting for inspection
                        $t->forceFill(['started_at' => now()->subMinutes(70 - $i * 25)])->save();
                        \App\Services\HousekeepingService::complete($t, $all);
                        $t->forceFill(['completed_at' => now()->subMinutes(45 - $i * 25)])->save();
                        if ($i === 1) {
                            \App\Services\HousekeepingService::inspect($t->fresh(), true, 'Good job');
                        }
                    } elseif ($i === 1 && $gi === 1) {                      // failed inspection → back to the attendant
                        $t->forceFill(['started_at' => now()->subMinutes(80)])->save();
                        \App\Services\HousekeepingService::complete($t, $all);
                        \App\Services\HousekeepingService::inspect($t->fresh(), false, 'Bathroom mirror streaky, replace hand towel');
                    } elseif ($i === 2 && $gi === 0) {
                        \App\Services\HousekeepingService::start($t);
                        $t->forceFill(['started_at' => now()->subMinutes(12)])->save();
                    } elseif ($i === 3 && $gi === 0 && $t->room->status === 'occupied') {
                        \App\Services\HousekeepingService::dnd($t);
                    }
                }
            }
            // Minibar consumption in an occupied room
            if ($room = Models\Room::where('status', 'occupied')->orderBy('room_no')->first()) {
                $mb = Models\MinibarItem::orderBy('sort_order')->pluck('id');
                \App\Services\HousekeepingService::postMinibar($room, [$mb[0] => 2, $mb[2] => 1, $mb[4] => 1]);
            }
        }

        // Spa & activities
        $therapists = \App\Services\SpaBookingService::therapists()->values();
        $svc = Models\SpaService::pluck('id', 'name');
        $srooms = Models\SpaRoom::pluck('id', 'name');
        $stays = Models\ReservationRoom::where('status', 'checked_in')->orderBy('id')->take(3)->get();
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $book = fn (array $d) => \App\Services\SpaBookingService::save($d + ['date' => $today->toDateString(), 'pax' => 1]);
        if ($therapists->count() >= 3) {
            $a = $book(['spa_service_id' => $svc['Swedish Massage (60 min)'], 'employee_id' => $therapists[1]->id, 'spa_room_id' => $srooms['Treatment Room 1'], 'start_time' => '10:00', 'reservation_room_id' => $stays[0]->id ?? null, 'customer_name' => 'Walk-in guest']);
            if ($a->stay) {
                \App\Services\SpaBookingService::settle($a, 'room_post');
            }
            $b = $book(['spa_service_id' => $svc['Signature Facial'], 'employee_id' => $therapists[0]->id, 'spa_room_id' => $srooms['Treatment Room 2'], 'start_time' => '11:00', 'customer_name' => 'Ama Serwaa', 'customer_phone' => '0244123456', 'discount' => 300]);
            \App\Services\SpaBookingService::settle($b, 'payment', $cash, 'Counter');
            if ($rich) {
                $c = $book(['spa_service_id' => $svc['Deep Tissue Massage (90 min)'], 'employee_id' => $therapists[1]->id, 'spa_room_id' => $srooms['Treatment Room 1'], 'start_time' => '14:30', 'reservation_room_id' => $stays[1]->id ?? null, 'customer_name' => 'Walk-in guest']);
                \App\Services\SpaBookingService::setStatus($c, 'in_progress');
                $book(['spa_service_id' => $svc['Personal Training Session'], 'employee_id' => $therapists[2]->id, 'start_time' => '07:00', 'customer_name' => 'Kwame Mensah', 'customer_phone' => '0201234567'])->update(['status' => 'no_show']);
                $book(['spa_service_id' => $svc['Hot Stone Therapy'], 'employee_id' => $therapists[0]->id, 'spa_room_id' => $srooms['Couples Suite'], 'start_time' => '16:00', 'pax' => 2, 'reservation_room_id' => $stays[2]->id ?? null, 'customer_name' => 'Walk-in guest', 'notes' => 'Anniversary – rose petals']);
                $book(['spa_service_id' => $svc['Morning Yoga (group)'], 'employee_id' => $therapists[2]->id, 'start_time' => '09:00', 'pax' => 4, 'customer_name' => 'Yoga group (4)']);
                $book(['spa_service_id' => $svc['Sunset Kayaking'], 'start_time' => '17:00', 'pax' => 2, 'reservation_room_id' => $stays[0]->id ?? null, 'customer_name' => 'Walk-in guest']);
                $x = $book(['spa_service_id' => $svc['Manicure & Pedicure'], 'employee_id' => $therapists[0]->id, 'spa_room_id' => $srooms['Salon Chair'], 'start_time' => '13:00', 'customer_name' => 'Linda Owusu']);
                \App\Services\SpaBookingService::setStatus($x, 'cancelled', 'Guest rescheduled to next week');
                foreach ([[1, '10:30', 'Swedish Massage (60 min)', 1], [1, '12:00', 'Haircut & Styling', 0], [2, '15:00', 'City Heritage Tour', null]] as [$dd, $t, $s, $ti]) {
                    \App\Services\SpaBookingService::save(['spa_service_id' => $svc[$s], 'employee_id' => $ti === null ? null : $therapists[$ti]->id, 'date' => $today->copy()->addDays($dd)->toDateString(),
                        'start_time' => $t, 'pax' => 1, 'customer_name' => ['Nana Adjei', 'Esi Mensah', 'Tour group'][$dd === 1 && $t === '12:00' ? 1 : ($dd === 2 ? 2 : 0)]]);
                }
            }
        }

        // Messaging: log driver (demo) + a little history
        foreach (['sms_enabled' => '1', 'sms_driver' => 'log', 'sms_default_channel' => 'sms', 'sms_default_country_code' => '+233', 'sms_sender' => 'NOWILLS',
            'sms_auto_confirmation' => '1', 'sms_auto_thankyou' => '1', 'sms_pre_arrival_days' => '2', 'hk_require_inspection' => '1', 'spa_open' => '07:00', 'spa_close' => '20:00'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        if ($rich) {
            Models\Reservation::with('guest')->whereIn('status', ['confirmed', 'checked_in'])->orderBy('id')->take(4)->get()
                ->each(fn ($r, $i) => \App\Services\MessageService::sendTemplate($i === 3 ? 'pre_arrival' : 'confirmation', $r, $i === 2 ? 'whatsapp' : 'sms'));
            \App\Services\MessageService::send('12', 'Test', 'sms', 'test'); // shows a failed entry (invalid number)
        }
    }

    /**
     * v4.9 workforce: shift templates; rosters for last / this / next week (next week's spa roster still a draft); a week of
     * time punches through today (late arrivals, a no-show, a missed clock-out, an early departure, a corrected punch and
     * unrostered work) with D-6 … D-2 approved into attendance; swaps waiting for a colleague / a manager and one approved;
     * kiosk PINs; service charge points; August & September tips and the August distribution approved, ready for payroll.
     */
    protected function seedWorkforce(bool $rich, Carbon $today): void
    {
        if (\App\Models\ShiftTemplate::exists()) {
            return;
        }
        $R = \App\Services\RosterService::class;
        $TC = \App\Services\TimeClockService::class;
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        $prevUser = auth()->user();
        $tpl = [];
        foreach ([['M', 'Morning', '06:00', '14:00', 30, '#f59e0b', null], ['E', 'Evening', '14:00', '22:00', 30, '#6366f1', null], ['N', 'Night', '22:00', '06:00', 30, '#334155', null],
            ['G', 'General', '09:00', '18:00', 60, '#10b981', null], ['SP', 'Spa day', '10:00', '19:00', 60, '#ec4899', 9]] as [$c, $n, $st, $en, $br, $col, $dept]) {
            $tpl[$c] = \App\Models\ShiftTemplate::create(['code' => $c, 'name' => $n, 'start_time' => $st, 'end_time' => $en, 'break_minutes' => $br, 'color' => $col,
                'department_id' => $dept && Models\Department::whereKey($dept)->exists() ? $dept : null, 'is_active' => true]);
        }
        // points & eligibility: managers / heads 3, supervisors 2, others 1; back office (accounts, HR) not in the tronc
        foreach (Models\Employee::all() as $e) {
            $pts = preg_match('/manager|head|chief/i', (string) $e->designation) ? 3 : (preg_match('/supervisor/i', (string) $e->designation) ? 2 : 1);
            $dn = (string) Models\Department::whereKey($e->department_id)->value('name');
            $e->forceFill(['sc_points' => $pts, 'sc_eligible' => ! in_array($dn, ['Accounts', 'Management'], true)])->save();
        }
        if (! $rich) {
            return;
        }
        [$admin, $hr, $hk, $acct, $fnb] = [$u('admin'), $u('hr'), $u('housekeeping'), $u('accounts'), $u('restaurant')];
        $emp = fn ($code) => Models\Employee::where('emp_code', $code)->first();
        // pattern: code per employee; line staff alternate M / E weekly; two consecutive days off staggered by employee
        $plan = ['EMP005' => 'ME', 'EMP013' => 'G', 'EMP001' => 'M', 'EMP002' => 'ME', 'EMP009' => 'EM', 'EMP007' => 'E', 'EMP006' => 'G', 'EMP008' => 'N', 'EMP010' => 'SP', 'EMP011' => 'SP', 'EMP012' => 'SP'];
        $thisWeek = $R::weekStart(now());
        $weeks = [$thisWeek->copy()->subWeek(), $thisWeek->copy(), $thisWeek->copy()->addWeek()];
        foreach ($weeks as $w) {
            $alt = (int) $w->format('W') % 2;
            foreach ($plan as $code => $p) {
                $e = $emp($code);
                if (! $e) {
                    continue;
                }
                $shiftCode = in_array($p, ['ME', 'EM'], true) ? $p[$alt] : $p;
                $off1 = ($e->id * 2) % 7;
                $leave = $R::leaveMap(collect([$e->id]), $w->toDateString(), $w->copy()->addDays(6)->toDateString())[$e->id] ?? [];
                for ($i = 0; $i < 7; $i++) {
                    $d = $w->copy()->addDays($i)->toDateString();
                    if (isset($leave[$d]) && ! $leave[$d]->half_day) {
                        continue;
                    }
                    $off = $i === $off1 || $i === ($off1 + 1) % 7;
                    $t = $tpl[$shiftCode];
                    \App\Models\RosterShift::create(['employee_id' => $e->id, 'date' => $d, 'kind' => $off ? 'off' : 'shift', 'shift_template_id' => $off ? null : $t->id,
                        'start_time' => $off ? null : $t->start_time, 'end_time' => $off ? null : $t->end_time, 'break_minutes' => $off ? 0 : $t->break_minutes, 'created_by' => $hr?->id]);
                }
            }
        }
        // the steward worked yesterday evening and is off today (his missed clock-out below is his latest shift)
        if ($s7 = $emp('EMP007')) {
            \App\Models\RosterShift::where('employee_id', $s7->id)->where('date', now()->subDay()->toDateString())->update(['kind' => 'shift', 'shift_template_id' => $tpl['E']->id, 'start_time' => '14:00', 'end_time' => '22:00', 'break_minutes' => 30]);
            \App\Models\RosterShift::where('employee_id', $s7->id)->where('date', now()->toDateString())->update(['kind' => 'off', 'shift_template_id' => null, 'start_time' => null, 'end_time' => null, 'break_minutes' => 0]);
        }
        $depts = Models\Employee::whereIn('emp_code', array_keys($plan))->distinct()->pluck('department_id');
        $spa = Models\Department::where('name', 'Spa & Wellness')->value('id');
        foreach ($depts as $dId) {
            foreach ($weeks as $k => $w) {
                $pub = $k < 2 || $dId != $spa;
                \App\Models\RosterWeek::create(['department_id' => $dId, 'week_start' => $w->toDateString(), 'published_at' => $pub ? $w->copy()->subDays(4)->setTime(16, 30) : null, 'published_by' => $pub ? $hr?->id : null]);
            }
        }

        // ---------- time punches: D-6 … now ----------
        Models\Setting::updateOrCreate(['key' => 'timeclock_self'], ['value' => '1']);
        Models\Setting::$version++;
        auth()->setUser($hr);
        $now = now();
        $shifts = \App\Models\RosterShift::with('employee')->where('kind', 'shift')->whereBetween('date', [$now->copy()->subDays(6)->toDateString(), $now->toDateString()])->orderBy('date')->orderBy('start_time')->get();
        $D = fn (int $n) => $now->copy()->subDays($n)->toDateString();
        // each exception lands on the first preferred day the employee actually worked (weekday-independent)
        $pick = function (string $code, array $prefer) use ($shifts, $D, $now) {
            foreach ($prefer as $n) {
                if ($shifts->first(fn ($s) => $s->employee->emp_code === $code && $s->date->toDateString() === $D($n) && $s->endsAt()->lt($now))) {
                    return $D($n);
                }
            }

            return null;
        };
        $X = ['noshow' => $pick('EMP002', [3, 4, 2, 5]), 'late' => $pick('EMP005', [2, 3, 4, 5, 6]), 'late2' => $pick('EMP012', [1, 2, 3, 4]),
            'early' => $pick('EMP011', [3, 4, 2, 5, 6]), 'edit' => $pick('EMP009', [4, 5, 3, 6, 2])];
        foreach ($shifts->sortBy(fn ($s) => $s->startsAt()) as $s) {
            $e = $s->employee;
            $d = $s->date->toDateString();
            $start = $s->startsAt();
            $end = $s->endsAt();
            if ($start->gt($now) || ($e->emp_code === 'EMP002' && $d === $X['noshow'])) {
                continue; // not started yet / Lata's no-show
            }
            $h = crc32($e->id.'|'.$d) % 100;
            $inAt = $start->copy()->addMinutes(($h % 14) - 10);          // −10 … +3 min
            if ($e->emp_code === 'EMP005' && $d === $X['late']) {
                $inAt = $start->copy()->addMinutes(23);
            }
            if ($e->emp_code === 'EMP012' && $d === $X['late2']) {
                $inAt = $start->copy()->addMinutes(9);
            }
            if ($inAt->gt($now)) {
                $inAt = $now->copy()->subMinute();
            }
            $src = $h % 3 === 0 ? 'self' : 'kiosk';
            try {
                $p = $TC::clockIn($e, $src, $src === 'self' ? '10.10.4.'.(20 + $e->id) : '10.10.1.5', $inAt);
                if ($e->emp_code === 'EMP007' && $d === $D(1)) {
                    continue; // forgot to clock out
                }
                $outAt = $end->copy()->addMinutes(($h % 17) - 2);             // −2 … +14 min
                if ($e->emp_code === 'EMP011' && $d === $X['early']) {
                    $outAt = $end->copy()->subMinutes(40);                       // left early (appointment)
                }
                if ($outAt->gt($now)) {
                    continue;                                                    // still on duty
                }
                if ($e->emp_code === 'EMP009' && $d === $X['edit']) {
                    auth()->setUser($hk);
                    $TC::managerSave($e, $inAt->format('Y-m-d H:i'), $end->copy()->addMinutes(5)->format('Y-m-d H:i'), 'Forgot to clock out – finished at 22:05, confirmed by the duty supervisor', $hk, $p);
                    auth()->setUser($hr);

                    continue;
                }
                $TC::clockOut($e, $src, null, $outAt);
            } catch (\RuntimeException $ex) {
                // a clash with an earlier exception (e.g. open punch) – leave that shift unpunched
            }
        }
        // unrostered: the electrician called in for a breakdown yesterday (kiosk)
        if (($j = $emp('EMP003')) && $now->copy()->subDay()->setTime(17, 30)->lt($now)) {
            try {
                $TC::clockIn($j, 'kiosk', '10.10.1.5', $now->copy()->subDay()->setTime(9, 2));
                $TC::clockOut($j, 'kiosk', '10.10.1.5', $now->copy()->subDay()->setTime(17, 34));
            } catch (\RuntimeException $ex) {
            }
        }
        foreach ([6, 5, 4, 3, 2] as $n) {
            $TC::approveDay($D($n), null, $hr);
        }
        foreach (['EMP001' => '4821', 'EMP005' => '3907'] as $code => $pin) {
            ($x = $emp($code)) && $TC::setPin($x, $pin);
        }

        // ---------- swaps (next week, published) ----------
        $next = $weeks[2];
        $findDay = function ($a, $b, array $exclude = []) use ($next) {
            for ($i = 0; $i < 7; $i++) {
                $d = $next->copy()->addDays($i)->toDateString();
                $sa = \App\Models\RosterShift::where('employee_id', $a->id)->where('date', $d)->first();
                $sb = \App\Models\RosterShift::where('employee_id', $b->id)->where('date', $d)->first();
                if (! in_array($d, $exclude, true) && $sa?->isShift() && (! $sb?->isShift() || $sb->start_time !== $sa->start_time)) {
                    return $sa;
                }
            }

            return null;
        };
        try {
            [$ram, $lata, $abena, $deepa, $ravi] = [$emp('EMP001'), $emp('EMP002'), $emp('EMP009'), $emp('EMP005'), $emp('EMP013')];
            if ($s = $findDay($ram, $abena)) {
                $sw = $R::requestSwap($ram, $s, $abena, 'Doctor\'s appointment that morning');
                $R::peerRespond($sw, $abena, true);                                     // waiting for the manager
                $used = $s->date->toDateString();
            }
            if ($s = $findDay($lata, $ram, [$used ?? ''])) {
                $R::requestSwap($lata, $s, $ram, 'Church harvest – could you take my shift?');   // waiting for Ramesh
            }
            if ($s = $findDay($deepa, $ravi)) {
                $sw = $R::requestSwap($deepa, $s, $ravi, 'Sister\'s graduation in Cape Coast');
                $R::peerRespond($sw, $ravi, true);
                $R::decide($sw, true, 'OK – cover confirmed', $admin);                     // approved
            }
        } catch (\RuntimeException $ex) {
            // swaps are demo sugar; never fail the seed on a calendar edge case
        }

        // ---------- tips & service charge ----------
        $mode = fn ($like) => Models\PaymentMode::where('name', 'like', $like.'%')->value('id');
        [$cash, $card, $momo] = [$mode('Cash'), $mode('Credit'), $mode('Mobile')];
        auth()->setUser($fnb ?? $admin);
        $cut = \App\Services\LedgerService::cutoff();
        $srcs = [['card_tips', $card, 'POS card tips batch'], ['cash_box', $cash, 'Tips box count'], ['momo_tips', $momo, 'MoMo tips (merchant wallet)']];
        $k = 0;
        for ($d = Carbon::parse('2026-08-02'); $d->toDateString() <= $cut; $d->addDays(3)) {
            [$src, $m, $ref] = $srcs[$k % 3];
            $amt = 180 + (crc32('tip'.$d->toDateString()) % 520) + ($src === 'card_tips' ? 150 : 0);
            \App\Services\ServiceChargeService::addTip(['date' => $d->toDateString(), 'source' => $src, 'payment_mode_id' => $m, 'amount' => $amt, 'reference' => $ref.' '.$d->format('d/m')], $fnb ?? $admin);
            $k++;
        }
        \App\Services\ServiceChargeService::addTip(['date' => '2026-08-22', 'source' => 'banquet', 'payment_mode_id' => $cash, 'amount' => 1500, 'reference' => 'Asante wedding – gratuity', 'notes' => 'Handed in by the banquet captain after the event'], $fnb ?? $admin);
        $bad = \App\Services\ServiceChargeService::addTip(['date' => '2026-09-10', 'source' => 'cash_box', 'payment_mode_id' => $cash, 'amount' => 420, 'reference' => 'Tips box count 10/09'], $fnb ?? $admin);
        \App\Services\ServiceChargeService::cancelTip($bad, 'Duplicate – already counted in the 09/09 box count');
        \App\Services\LedgerService::sync();
        auth()->setUser($hr);
        $dist = \App\Services\ServiceChargeService::create(['period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'basis' => 'points_days', 'notes' => 'August tronc'], $hr);
        auth()->setUser($acct);
        \App\Services\ServiceChargeService::approve($dist, $acct);

        // attendance changed for the approved days → refresh the draft payroll so payslips match
        auth()->setUser($hr);
        if ($run = Models\PayrollRun::where('status', 'draft')->orderByDesc('period_start')->first()) {
            \App\Services\PayrollService::generate($run, false);
        }
        \App\Services\LedgerService::sync();
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedFoodSafety(bool $rich, Carbon $today): void
    {
        if (\App\Models\FsEquipment::exists()) {
            return;
        }
        $FS = \App\Services\FoodSafetyService::class;
        $FS::ensureDefaults();
        if (! $rich) {
            return;
        }
        $hid = \App\Support\Tenant::id();
        $u = fn ($n) => Models\User::where('hotel_id', $hid)->where('username', $n)->first();
        [$admin, $chef, $fnb, $store] = [$u('admin'), $u('chef'), $u('restaurant'), $u('store')];
        $chef ??= $admin;
        $fnb ??= $admin;
        $store ??= $admin;
        $prevUser = auth()->user();
        $now = now()->copy()->second(0);
        $back = $now->copy()->subDays(30);

        // ---------- allergens: ingredients (only existing items + a few stockless allergen ingredients) and menu declarations ----------
        $inv = fn ($like) => Models\InventoryItem::where('name', 'like', $like)->first();
        $inv('Paneer%')?->update(['allergens' => ['milk']]);
        $proto = Models\InventoryItem::where('name', 'like', 'Paneer%')->first() ?? Models\InventoryItem::first();
        foreach ([['Wheat Flour (Atta)', 'kg', 38, ['gluten']], ['Butter', 'kg', 95, ['milk']], ['Fresh Cream', 'ltr', 60, ['milk']], ['Eggs (tray of 30)', 'tray', 72, ['eggs']],
            ['Cashew Nuts', 'kg', 180, ['nuts']], ['Groundnut Paste', 'kg', 45, ['peanuts']], ['Tilapia Fillet', 'kg', 85, ['fish']], ['Frozen Prawns', 'kg', 160, ['crustaceans']],
            ['Soy Sauce', 'ltr', 55, ['soy', 'gluten']], ['Sesame Seeds', 'kg', 70, ['sesame']], ['Mustard Seeds', 'kg', 40, ['mustard']]] as [$n, $unit, $cost, $al]) {
            if (! Models\InventoryItem::where('name', $n)->exists() && $proto) {
                $new = $proto->replicate(['hotel_id']);
                $new->forceFill(['name' => $n, 'unit' => $unit, 'reorder_level' => 0, 'allergens' => $al]);
                foreach (['code', 'sku', 'item_code'] as $col) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('inventory_items', $col)) {
                        $new->{$col} = 'FS'.str_pad((string) (Models\InventoryItem::count() + 1), 3, '0', STR_PAD_LEFT);
                    }
                }
                foreach (['last_cost', 'cost_price', 'rate', 'purchase_rate'] as $col) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('inventory_items', $col)) {
                        $new->{$col} = $cost;
                    }
                }
                $new->save();
            }
        }
        $decl = [
            'Paneer Tikka' => [['milk'], []], 'Chicken 65' => [['eggs', 'gluten'], ['mustard']], 'Veg Spring Rolls' => [['gluten', 'soy'], ['sesame']],
            'Fish Amritsari' => [['fish', 'gluten', 'eggs'], []], 'Butter Chicken' => [['milk', 'nuts'], []], 'Dal Makhani' => [['milk'], []],
            'Paneer Butter Masala' => [['nuts'], []], // milk (paneer) deliberately undeclared → shows up as a recipe discrepancy
            'Mutton Rogan Josh' => [['milk'], []], 'Veg Biryani' => [['milk', 'nuts'], []], 'Chicken Biryani' => [['milk', 'nuts'], []],
            'Butter Naan' => [['gluten', 'milk'], ['sesame']], 'Garlic Naan' => [['gluten', 'milk'], ['sesame']], 'Masala Dosa' => [['mustard'], ['nuts']],
            'Idli Vada' => [['mustard'], []], 'Omelette' => [['eggs', 'milk'], []], 'Continental' => [['gluten', 'eggs', 'milk'], ['nuts']],
            'Gulab Jamun' => [['milk', 'gluten'], ['nuts']], 'Ice Cream Sundae' => [['milk', 'nuts'], ['peanuts']], 'Masala Chai' => [['milk'], []],
            'Filter Coffee' => [['milk'], []], 'Cold Coffee' => [['milk'], []], 'Kingfisher' => [['gluten'], []], 'Beer' => [['gluten'], []], 'Wine' => [['sulphites'], []],
        ];
        $unverified = ['Masala Dosa', 'Cold Coffee'];
        foreach (Models\MenuItem::all() as $m) {
            foreach ($decl as $name => [$c, $may]) {
                if (stripos($m->name, $name) !== false) {
                    $m->forceFill(['allergens' => $c, 'may_contain' => $may ?: null,
                        'allergens_verified_at' => in_array($name, $unverified, true) ? null : $back->copy()->addDays(20), 'allergens_verified_by' => in_array($name, $unverified, true) ? null : $chef->id])->save();
                    break;
                }
            }
        }

        // ---------- equipment ----------
        $outlet = fn ($like) => Models\Outlet::where('name', 'like', $like)->value('id');
        $eqs = [];
        foreach ([['Walk-in chiller', 'fridge', 'Main kitchen', null, 1.0, 5.0, 2], ['Walk-in freezer', 'freezer', 'Main kitchen', null, null, -18.0, 2],
            ['Pastry reach-in fridge', 'fridge', 'Pastry section', null, null, 5.0, 2], ['Bain-marie (hot pass)', 'hot_hold', 'Hot pass', 'Saffron%', 63.0, null, 3],
            ['Buffet display chiller', 'display', 'Breakfast buffet', 'Saffron%', null, 8.0, 2], ['Bar fridge', 'fridge', 'Back bar', 'Skyline%', null, 5.0, 1]] as [$n, $t, $loc, $o, $min, $max, $cpd]) {
            $e = $FS::saveEquipment(['name' => $n, 'type' => $t, 'location' => $loc, 'outlet_id' => $o ? $outlet($o) : null, 'min_c' => $min, 'max_c' => $max, 'checks_per_day' => $cpd]);
            $e->forceFill(['created_at' => $back])->saveQuietly();
            $eqs[$n] = $e;
        }

        // ---------- a week of readings (inserted directly: HACCP entry is time-limited in the app) ----------
        $log = function (array $d) {
            [$min, $max] = [$d['min_c'] ?? null, $d['max_c'] ?? null];
            $ok = \App\Services\FoodSafetyService::inRange((float) $d['reading_c'], $min, $max);

            return \App\Models\FsTempLog::create($d + ['in_range' => $ok]);
        };
        $slots = [1 => ['08:10'], 2 => ['07:50', '16:20'], 3 => ['07:45', '12:30', '18:40']];
        $missed = ['Pastry reach-in fridge|4|1', 'Bar fridge|2|0']; // unit|days ago|slot index
        for ($ago = 7; $ago >= 0; $ago--) {
            $day = $now->copy()->subDays($ago);
            foreach ($eqs as $n => $e) {
                foreach ($slots[$e->checks_per_day] as $si => $hm) {
                    $at = $day->copy()->setTimeFromTimeString($hm)->addMinutes(crc32($n.$ago.$si) % 12);
                    if ($at->gt($now) || in_array("$n|$ago|$si", $missed, true)) {
                        continue;
                    }
                    $h = crc32($n.$ago.$si) % 100;
                    $v = match ($e->type) { 'freezer' => -21 + $h % 30 / 10, 'hot_hold' => 66 + $h % 90 / 10, 'display' => 3 + $h % 35 / 10, default => 1.8 + $h % 25 / 10 };
                    $action = null;
                    if ($n === 'Walk-in chiller' && $ago === 3 && $si === 1) {
                        [$v, $action] = [8.5, 'Door seal torn – high-risk stock moved to the reach-in, 2 kg chicken discarded, engineering ticket raised'];
                    }
                    if ($n === 'Bain-marie (hot pass)' && $ago === 1 && $si === 2) {
                        [$v, $action] = [58.0, 'Butter chicken reheated to 78 °C core and returned; bain-marie thermostat turned up'];
                    }
                    $l = $log(['kind' => 'equipment', 'fs_equipment_id' => $e->id, 'reading_c' => round($v, 1), 'min_c' => $e->min_c, 'max_c' => $e->max_c,
                        'corrective_action' => $action, 'recorded_at' => $at, 'user_id' => $e->outlet_id && $e->type === 'fridge' ? $fnb->id : $chef->id]);
                    if ($action && $ago === 3) {
                        $l->update(['reviewed_at' => $at->copy()->addHours(2), 'reviewed_by' => $admin->id, 'review_note' => 'Seal replaced next morning (WO raised); discard logged in waste']);
                    }
                }
            }
        }
        $sup = Models\Supplier::orderBy('id')->pluck('id')->all();
        $L = fn ($kind) => $FS::limits($kind, null, null);
        $atDay = fn ($ago, $hm) => $now->copy()->subDays($ago)->setTimeFromTimeString($hm);
        foreach ([[6, '09:20', 'receiving', 'Chilled chicken (20 kg)', 'chilled', 3.4, null], [4, '09:05', 'receiving', 'Paneer & dairy', 'chilled', 4.1, null],
            [2, '10:15', 'receiving', 'Frozen prawns (10 kg)', 'frozen', -11.0, 'Rejected – delivery partly thawed; returned to the supplier with the driver, credit note requested'],
            [1, '09:30', 'receiving', 'Chilled chicken (15 kg)', 'chilled', 2.9, null],
            [5, '12:40', 'cooking', 'Chicken 65 (batch)', null, 82.0, null], [3, '19:10', 'cooking', 'Butter chicken base', null, 79.5, null], [2, '13:05', 'cooking', 'Chicken biryani', null, 88.0, null],
            [1, '12:20', 'cooking', 'Fish Amritsari', null, 71.0, 'Probe read 71 °C – returned to the fryer 2 minutes, re-probed 80 °C'], [1, '20:15', 'reheating', 'Dal makhani (from chiller)', null, 81.0, null]] as [$ago, $hm, $kind, $item, $cat, $v, $action]) {
            [$min, $max] = $kind === 'receiving' ? $FS::limits('receiving', null, $cat) : $L($kind);
            $at = $atDay($ago, $hm);
            $l = $log(['kind' => $kind, 'item' => $item, 'category' => $cat, 'supplier_id' => $kind === 'receiving' && $sup ? $sup[$ago % count($sup)] : null,
                'reading_c' => $v, 'min_c' => $min, 'max_c' => $max, 'corrective_action' => $action, 'recorded_at' => $at, 'user_id' => $kind === 'receiving' ? $store->id : $chef->id]);
            if ($action && $kind === 'receiving') {
                $l->update(['reviewed_at' => $at->copy()->addHour(), 'reviewed_by' => $admin->id, 'review_note' => 'Supplier warned; next frozen delivery to be probed on arrival']);
            }
        }
        // cooling: yesterday's completed, today's in progress (2-hour reading taken 1 h ago)
        [, $c1] = $FS::limits('cooling', null, null, 1);
        [, $c2] = $FS::limits('cooling', null, null, 2);
        $y = $atDay(1, '15:00');
        $log(['kind' => 'cooling', 'item' => 'Chicken stock (20 L)', 'reading_c' => 18.5, 'reading2_c' => 4.2, 'reading2_at' => $y->copy()->addHours(4), 'in_range' => true, 'min_c' => null, 'max_c' => $c2, 'recorded_at' => $y, 'user_id' => $chef->id]);
        $log(['kind' => 'cooling', 'item' => 'Jollof rice base (8 kg)', 'reading_c' => 19.0, 'min_c' => null, 'max_c' => $c1, 'recorded_at' => $now->copy()->subHour(), 'user_id' => $chef->id]);

        // ---------- checklists ----------
        $lists = Models\FsChecklist::all()->keyBy('name');
        foreach ($lists as $c) {
            $c->forceFill(['created_at' => $back])->saveQuietly();
        }
        $run = function (Models\FsChecklist $c, Carbon $period, Carbon $at, Models\User $by, array $fail = [], ?Models\User $signer = null, ?string $signNote = null) {
            $answers = [];
            foreach ($c->items as $i => $text) {
                $answers[] = ['item' => $text, 'ok' => ! isset($fail[$i]), 'note' => $fail[$i] ?? null];
            }
            Models\FsChecklistRun::create(['fs_checklist_id' => $c->id, 'period' => $period->toDateString(), 'answers' => $answers, 'failed' => count($fail), 'completed_by' => $by->id, 'completed_at' => $at,
                'signed_by' => $signer?->id, 'signed_at' => $signer ? $at->copy()->addHours(10) : null, 'sign_note' => $signNote]);
        };
        $open = $lists['Kitchen opening checks'] ?? null;
        $close = $lists['Kitchen closing & cleaning'] ?? null;
        $bar = $lists['Bar opening checks'] ?? null;
        for ($ago = 7; $ago >= 0; $ago--) {
            $d = $now->copy()->subDays($ago)->startOfDay();
            if ($open && $ago !== 2 && $d->copy()->setTime(9, 35)->lte($now)) {
                $run($open, $d, $d->copy()->setTime(9, 35), $chef);
            }
            if ($close && $ago >= 1) {
                $fail = match ($ago) { 4 => [4 => 'Filters overdue – sent for cleaning, spare set fitted tomorrow'], 1 => [5 => 'Bin lid hinge broken – bin taped shut, replacement requested'], default => [] };
                $run($close, $d, $d->copy()->setTime(23, 20), $chef, $fail, $ago === 4 ? $admin : null, $ago === 4 ? 'Spare filters fitted; weekly degrease added to the deep-clean list' : null);
            }
            if ($bar && $ago !== 5 && $d->copy()->setTime(15, 40)->lte($now)) {
                $run($bar, $d, $d->copy()->setTime(15, 40), $fnb);
            }
        }
        if ($wk = $lists['Weekly deep clean'] ?? null) {
            $p = $FS::periodFor($wk, $now->copy()->subWeek());
            $run($wk, $p, $p->copy()->addDays(5)->setTime(22, 30), $chef);
        }
        if ($mo = $lists['Monthly pest control inspection'] ?? null) {
            $run($mo, $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->startOfMonth()->addDays(9)->setTime(11, 0), $chef, [], $admin, null);
            if ($now->day > 10) {
                $run($mo, $now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addDays(9)->setTime(11, 0), $chef);
            }
        }

        // ---------- handler certificates ----------
        auth()->setUser($admin);
        $emp = fn ($code) => Models\Employee::where('emp_code', $code)->first();
        $bd = $today->copy();
        foreach ([['EMP006', 'medical', 'Korle Bu Teaching Hospital', -110, 255], ['EMP006', 'hygiene', 'Ghana Tourism Authority', -300, 430],
            ['EMP007', 'medical', 'Ridge Hospital', -370, -5], ['EMP007', 'hygiene', 'Ghana Tourism Authority', -345, 20]] as [$code, $type, $issuer, $iss, $exp]) {
            if ($e = $emp($code)) {
                $FS::saveCertificate(['employee_id' => $e->id, 'type' => $type, 'issuer' => $issuer, 'cert_no' => strtoupper(substr($type, 0, 3)).'-'.$e->id.'-'.$bd->copy()->addDays($iss)->format('y'),
                    'issued_on' => $bd->copy()->addDays($iss)->toDateString(), 'expires_on' => $bd->copy()->addDays($exp)->toDateString()], $admin);
            }
        }

        // ---------- waste (posts wastage stock movements → Dr Food Waste / Cr Inventory) ----------
        $W = \App\Services\FoodWasteService::class;
        $menu = fn ($like) => Models\MenuItem::where('name', 'like', $like)->value('id');
        auth()->setUser($chef);
        $kitchen = Models\Store::where('type', 'kitchen')->value('id');
        $rest = $outlet('Saffron%');
        try {
            if ($ch = $inv('Chicken%')) {
                $W::record(['source' => 'raw', 'inventory_item_id' => $ch->id, 'qty' => 2, 'reason' => 'temperature', 'store_id' => $kitchen, 'date' => $bd->copy()->subDays(3)->toDateString(),
                    'notes' => 'Walk-in chiller at 8.5 °C – see temperature log'], $chef);
            }
            if ($m = $menu('Chicken Biryani%')) {
                $W::record(['source' => 'dish', 'menu_item_id' => $m, 'qty' => 3, 'reason' => 'overproduction', 'outlet_id' => $rest, 'date' => $bd->copy()->subDays(2)->toDateString(), 'notes' => 'Lunch buffet leftovers past 2 hours'], $chef);
            }
            if ($pa = $inv('Paneer%')) {
                $W::record(['source' => 'raw', 'inventory_item_id' => $pa->id, 'qty' => 0.4, 'reason' => 'expired', 'store_id' => $kitchen, 'date' => $bd->copy()->subDay()->toDateString()], $chef);
                $dup = $W::record(['source' => 'raw', 'inventory_item_id' => $pa->id, 'qty' => 0.4, 'reason' => 'expired', 'store_id' => $kitchen, 'date' => $bd->copy()->subDay()->toDateString()], $chef);
                $W::cancel($dup, 'Entered twice', $admin);
            }
            if ($m = $menu('Butter Chicken%')) {
                $W::record(['source' => 'plate', 'menu_item_id' => $m, 'qty' => 2, 'reason' => 'plate', 'outlet_id' => $rest, 'date' => $bd->copy()->subDay()->toDateString(), 'notes' => 'Two plates returned – too spicy'], $fnb);
            }
            if ($ri = $inv('Basmati%')) {
                $W::record(['source' => 'raw', 'inventory_item_id' => $ri->id, 'qty' => 1.5, 'reason' => 'overproduction', 'store_id' => $kitchen, 'date' => $bd->toDateString(), 'notes' => 'Cooked rice left from breakfast'], $chef);
            }
        } catch (\RuntimeException $ex) {
            // demo data must never break the seed
        }

        // ---------- prep labels ----------
        foreach ([['Butter chicken gravy', $menu('Butter Chicken%'), 'chilled', 20, 6, 'BC-'.$now->format('dm')], ['Chicken stock', null, 'frozen', 30, 4, 'CS-'.$now->copy()->subDay()->format('dm')],
            ['Shito (house pepper sauce)', null, 'chilled', 50, 3, null], ['Jollof rice base', null, 'chilled', 2, 2, null]] as [$item, $mid, $st, $hAgo, $qty, $batch]) {
            $FS::createLabels(['item' => $mid ? null : $item, 'menu_item_id' => $mid, 'storage' => $st, 'prepared_at' => $now->copy()->subHours($hAgo)->toDateTimeString(), 'qty' => $qty, 'batch' => $batch,
                'allergens' => $item === 'Shito (house pepper sauce)' ? ['fish', 'crustaceans'] : []], $chef);
        }

        // ---------- an open table with a declared allergy ----------
        $openOrders = Models\PosOrder::with('items.menuItem.recipes.inventoryItem')->where('status', 'open')->whereNotNull('pos_table_id')->orderBy('id')->get();
        $nutty = $openOrders->first(fn ($o) => $o->items->contains(fn ($l) => $l->menuItem && \App\Services\AllergenService::conflicts($l->menuItem, ['nuts', 'peanuts'])['contains']));
        if ($o = $nutty ?? $openOrders->first()) {
            \App\Services\AllergenService::setOrderAllergies($o, ['nuts', 'peanuts'], 'Guest at seat 2 – severe nut allergy, carries an EpiPen');
        }

        \App\Services\LedgerService::sync(true);
        $FS::flush();
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    /** v5.1 Health club demo: ~14 memberships in every state, two weeks of visits, classes with a waitlist, day passes. */
    protected function seedClub(bool $rich, Carbon $today): void
    {
        \App\Services\ClubService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $u = Auth::user();
        $plan = fn ($code) => Models\ClubPlan::where('code', $code)->first();
        $momo = Models\PaymentMode::where('type', 'wallet')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id');
        $d = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $CS = \App\Services\ClubService::class;
        $CB = \App\Services\ClubBillingService::class;
        $payAll = function ($m, string $date, int $mode, ?string $ref = null) use ($CB, $u) {
            $bal = $CB::balance($m);
            if ($bal > 0.004) {
                $CB::pay($m, $bal, $mode, $ref ?? 'TXN'.random_int(100000, 999999), $u, $date);
            }
        };
        $enrol = fn (string $code, string $holder, int $startOffset, array $persons = [], array $extra = []) => $CS::enrol(array_merge([
            'club_plan_id' => $plan($code)->id, 'holder_name' => $holder, 'phone' => '024'.random_int(1000000, 9999999), 'email' => strtolower(str_replace(' ', '.', $holder)).'@example.com',
            'start_date' => $d($startOffset), 'auto_renew' => true, 'persons' => $persons ?: [['name' => $holder, 'health' => 1, 'gender' => 'M']],
        ], $extra), $u, $d($startOffset));
        // renew on the member's schedule until the term runs past today
        $keepRenewed = function ($m, int $mode) use ($CS, $payAll, $today, $u) {
            while ($m->end_date->lt($today->copy()->addDays(5))) {
                $asOf = $m->end_date->copy()->subDays(4)->toDateString();
                $CS::renew($m, $u, $asOf);
                $m->refresh();
                $payAll($m, $asOf, $mode);
            }
        };

        $kwabena = $enrol('FULL-Y', 'Kwabena Asante', -200);
        $payAll($kwabena, $d(-200), $card);

        $ama = $enrol('FULL-M', 'Ama Serwaa', -75, [['name' => 'Ama Serwaa', 'gender' => 'F', 'dob' => '1991-04-12', 'health' => 1]]);
        $payAll($ama, $d(-75), $momo);
        $keepRenewed($ama, $momo);

        $kofi = $enrol('GYM-M', 'Kofi Mensah', -40, [['name' => 'Kofi Mensah', 'gender' => 'M', 'dob' => '1988-11-02', 'health' => 1]]);
        $payAll($kofi, $d(-40), $momo);
        $CS::renew($kofi, $u, $d(-16)); // renewal never paid → in arrears
        $kofi->refresh();

        $owusu = $enrol('FAM-Y', 'Owusu Family', -120, [
            ['name' => 'Emmanuel Owusu', 'gender' => 'M', 'dob' => '1982-06-20', 'health' => 1], ['name' => 'Gifty Owusu', 'relation' => 'spouse', 'gender' => 'F', 'dob' => '1985-02-14', 'health' => 1],
            ['name' => 'Kwame Owusu', 'relation' => 'child', 'gender' => 'M', 'dob' => $today->copy()->subYears(12)->subDays(40)->toDateString(), 'health' => 1],
            ['name' => 'Adwoa Owusu', 'relation' => 'child', 'gender' => 'F', 'dob' => $today->copy()->subYears(8)->subDays(100)->toDateString(), 'health' => 1]]);
        $payAll($owusu, $d(-120), $card);

        $company = Models\Company::orderBy('id')->first();
        $corp = $enrol('CORP-Y', 'Yaa Frimpong', -100, [['name' => 'Yaa Frimpong', 'relation' => 'primary', 'gender' => 'F', 'health' => 1]], ['company_id' => $company?->id, 'source' => 'corporate']);
        $payAll($corp, $d(-95), $card, 'Company cheque via card');

        $efua = $enrol('FULL-Q', 'Efua Darko', -50, [['name' => 'Efua Darko', 'gender' => 'F', 'dob' => '1994-09-30', 'health' => 1]]);
        $payAll($efua, $d(-50), $momo);
        $efua->update(['status' => 'frozen', 'frozen_from' => $d(-3), 'frozen_to' => $d(11), 'frozen_days' => 15, 'freeze_reason' => 'Working in Kumasi for two weeks', 'end_date' => $efua->end_date->copy()->addDays(15)->toDateString()]);

        $yaw = $enrol('GYM-M', 'Yaw Boakye', -70, [['name' => 'Yaw Boakye', 'gender' => 'M', 'health' => 1]], ['auto_renew' => false]);
        $payAll($yaw, $d(-70), $momo);

        $abena = $enrol('FULL-Q', 'Abena Kyei', -30, [['name' => 'Abena Kyei', 'gender' => 'F', 'health' => 1]]);
        $payAll($abena, $d(-30), $card);
        $CS::cancel($abena, 'immediate', 'Relocating to Takoradi', null, $u, $d(-5)); // credit note for the unused part stays on account

        $nana = $enrol('OFF-M', 'Nana Adjei', 4 - 30, [['name' => 'Nana Adjei', 'gender' => 'M']], ['auto_renew' => false, 'start_date' => $today->copy()->addDays(5)->subMonthNoOverflow()->toDateString()]);
        $payAll($nana, $d(-26), $momo);

        $esiStart = $today->copy()->addDays(3)->subMonthNoOverflow()->toDateString();
        $esi = $CS::enrol(['club_plan_id' => $plan('FULL-M')->id, 'holder_name' => 'Esi Quaye', 'phone' => '0551234567', 'start_date' => $esiStart, 'auto_renew' => true, 'persons' => [['name' => 'Esi Quaye', 'gender' => 'F', 'health' => 1]]], $u, $esiStart);
        $payAll($esi, $esiStart, $card);

        $stay = Models\ReservationRoom::with('guest')->where('status', 'checked_in')->orderBy('id')->first();
        if ($stay?->guest) {
            $gm = $enrol('FULL-M', $stay->guest->full_name, -10, [['name' => $stay->guest->full_name, 'health' => 1]], ['guest_id' => $stay->guest_id, 'source' => 'guest']);
            $payAll($gm, $d(-10), $card);
        }

        $cpl = $enrol('CPL-Y', 'Daniel Boateng', -150, [['name' => 'Daniel Boateng', 'gender' => 'M', 'health' => 1], ['name' => 'Akua Boateng', 'relation' => 'spouse', 'gender' => 'F', 'health' => 1]]);
        $payAll($cpl, $d(-150), $card);

        $selorm = $enrol('FULL-M', 'Selorm Agbeko', -20, [['name' => 'Selorm Agbeko', 'gender' => 'M', 'health' => 1]]);
        $payAll($selorm, $d(-20), $momo);
        $CS::cancel($selorm, 'term_end', 'Joining a gym nearer to work', null, $u, $d(-2));

        $stu = $enrol('STU-M', 'Kojo Antwi', -3, [['name' => 'Kojo Antwi', 'gender' => 'M', 'dob' => '2004-03-08', 'health' => 1]]);
        $CB::pay($stu, 121.00, $momo, 'TXN'.random_int(100000, 999999), $u, $d(-3)); // joining fee only – subscription still due

        $CS::expiryRun($today->toDateString()); // Yaw's term lapses

        // ---- visits: two weeks of history, refusals, an override, and people in the club right now
        $now = now();
        $active = Models\ClubMember::with('membership.plan')->whereHas('membership', fn ($q) => $q->where('status', 'active'))->where('is_active', true)->get()
            ->reject(fn ($p) => $p->club_membership_id === $kofi->id);
        foreach (range(13, 1) as $back) {
            foreach ($active as $p) {
                if (random_int(1, 100) > 35 || $p->membership->start_date->gt($now->copy()->subDays($back))) {
                    continue;
                }
                $fac = collect($p->membership->plan->facilities)->reject(fn ($f) => in_array($f, ['classes', 'kids']))->values();
                $in = $now->copy()->subDays($back)->setTime(random_int(6, 19), random_int(0, 59));
                if ($p->membership->plan->access_to && $in->format('H:i:s') > $p->membership->plan->access_to) {
                    $in->setTime(random_int(6, 14), random_int(0, 59));
                }
                Models\ClubVisit::create(['visitor_type' => 'member', 'club_member_id' => $p->id, 'name' => $p->name, 'facility' => $fac->random(), 'checked_in_at' => $in, 'checked_out_at' => $in->copy()->addMinutes(random_int(40, 110)), 'user_id' => $u->id]);
            }
        }
        $kofiCard = $kofi->members()->first();
        [$od, $odSince] = $CB::overdue($kofi);
        foreach ([[3, 'override'], [1, 'deny'], [0, 'deny']] as [$back, $kind]) {
            $in = $back ? $now->copy()->subDays($back)->setTime(18, 10) : $now->copy()->subMinutes(95);
            Models\ClubVisit::create(['visitor_type' => 'member', 'club_member_id' => $kofiCard->id, 'name' => $kofiCard->name, 'facility' => 'gym', 'checked_in_at' => $in,
                'checked_out_at' => $kind === 'deny' ? $in : $in->copy()->addMinutes(70), 'denied' => $kind === 'deny', 'user_id' => $u->id,
                'deny_reason' => $kind === 'deny' ? 'Overdue balance '.money($od).' (due since '.dmy($odSince).').' : null,
                'override_reason' => $kind === 'override' ? 'Promised to pay by MoMo tonight' : null, 'override_by' => $kind === 'override' ? $u->id : null]);
        }
        $amaCard = $ama->members()->first();
        Models\ClubVisit::create(['visitor_type' => 'guest', 'host_member_id' => $amaCard->id, 'name' => 'Afia Nyarko', 'facility' => 'pool', 'checked_in_at' => $now->copy()->startOfMonth()->addHours(10)->min($now->copy()->subHours(5)), 'checked_out_at' => $now->copy()->startOfMonth()->addHours(11)->min($now->copy()->subHours(4)), 'user_id' => $u->id]);
        $inNow = [[$kwabena->members()->first(), 'gym', 25], [$amaCard, 'pool', 40], [$owusu->members()->where('relation', 'child')->first(), 'pool', 55], [$cpl->members()->first(), 'tennis', 30], [$cpl->members()->skip(1)->first(), 'tennis', 30]];
        foreach ($inNow as [$p, $fac, $min]) {
            Models\ClubVisit::create(['visitor_type' => 'member', 'club_member_id' => $p->id, 'name' => $p->name, 'facility' => $fac, 'checked_in_at' => $now->copy()->subMinutes($min), 'user_id' => $u->id]);
        }
        Models\ClubVisit::create(['visitor_type' => 'guest', 'host_member_id' => $amaCard->id, 'name' => 'Linda Owusu-Ansah', 'facility' => 'pool', 'checked_in_at' => $now->copy()->subMinutes(38), 'user_id' => $u->id]); // 2nd free pass → none left
        if ($stay) {
            Models\ClubVisit::create(['visitor_type' => 'resident', 'reservation_room_id' => $stay->id, 'name' => ($stay->guest?->full_name ?? 'Guest').' (Rm '.$stay->room?->room_no.')', 'facility' => 'pool', 'pax' => 2, 'checked_in_at' => $now->copy()->subMinutes(20), 'user_id' => $u->id]);
            foreach ([2, 5, 9] as $back) {
                $in = $now->copy()->subDays($back)->setTime(7, 15);
                Models\ClubVisit::create(['visitor_type' => 'resident', 'reservation_room_id' => $stay->id, 'name' => ($stay->guest?->full_name ?? 'Guest').' (Rm '.$stay->room?->room_no.')', 'facility' => 'gym', 'checked_in_at' => $in, 'checked_out_at' => $in->copy()->addHour(), 'user_id' => $u->id]);
            }
        }

        // ---- day passes: two weeks of walk-ins, one charged to a room today, one cancelled today, one in the pool now
        $names = ['Samuel Tetteh', 'Joyce Amankwah', 'Peter Addo', 'Mavis Ofori', 'Isaac Nkrumah', 'Comfort Asare', 'Richard Lamptey', 'Grace Adomako', 'Eric Sarpong', 'Linda Badu', 'Patrick Ayew', 'Rita Quansah', 'Francis Osei', 'Doris Mensah', 'George Appiah', 'Beatrice Kumi'];
        foreach ($names as $i => $n) {
            $back = 13 - intdiv($i * 13, count($names));
            $dp = $CS::sellDayPass(['name' => $n, 'phone' => '020'.random_int(1000000, 9999999), 'adults' => random_int(1, 2), 'children' => $i % 4 === 0 ? 1 : 0, 'payment_mode_id' => $i % 2 ? $card : $momo, 'reference' => 'DP'.random_int(1000, 9999)], $u, null, false, $d(-$back));
            $in = \Carbon\Carbon::parse($d(-$back))->setTime(9 + $i % 8, 15);
            Models\ClubVisit::create(['visitor_type' => 'day_pass', 'club_day_pass_id' => $dp->id, 'name' => $dp->name, 'facility' => $i % 3 ? 'pool' : 'gym', 'pax' => $dp->adults + $dp->children, 'checked_in_at' => $in, 'checked_out_at' => $in->copy()->addMinutes(150), 'user_id' => $u->id]);
        }
        if ($stay) {
            $CS::sellDayPass(['name' => 'Visitor of Rm '.$stay->room?->room_no, 'adults' => 1, 'room_no' => $stay->room?->room_no], $u, null, false);
        }
        $x = $CS::sellDayPass(['name' => 'Kwesi Arthur', 'adults' => 1, 'payment_mode_id' => $momo], $u, null, false);
        $CS::cancelDayPass($x, 'Visitor changed his mind before entering', $u);
        $CS::sellDayPass(['name' => 'Harriet Nyamekye', 'adults' => 1, 'children' => 1, 'payment_mode_id' => $card, 'reference' => 'POS 55621'], $u, 'pool', false);

        // ---- classes: staff instructor, past attendance (Kofi: 3 no-shows), upcoming bookings, a full session with a waitlist
        if ($emp = Models\Employee::where('hotel_id', Tenant::id())->where('is_active', true)->whereHas('department', fn ($q) => $q->where('name', 'like', 'Spa%'))->first()) {
            Models\ClubClass::whereIn('name', ['Aqua Aerobics', 'Kids Swim School'])->update(['employee_id' => $emp->id, 'instructor' => null]);
        }
        $pool = Models\ClubMember::with('membership')->where('is_active', true)->whereHas('membership', fn ($q) => $q->whereIn('status', ['active', 'frozen']))->get()
            ->reject(fn ($p) => $p->club_membership_id === $kofi->id)->values();
        $kofiNoShows = 0;
        $spinDone = false;
        foreach (Models\ClubClass::where('is_active', true)->get() as $c) {
            for ($back = 14; $back >= -7; $back--) {
                $date = $today->copy()->addDays(-$back);
                if ((int) $date->isoWeekday() !== (int) $c->weekday) {
                    continue;
                }
                $start = Carbon::parse($date->toDateString().' '.$c->start_time);
                $past = $start->lt($now);
                $spin = ! $past && $c->name === 'Spin Express' && ! $spinDone;
                $spinDone = $spinDone || $spin;
                $take = $spin ? $pool->count() : random_int(3, min(7, $pool->count()));
                foreach ($pool->shuffle()->take($take)->values() as $i => $p) {
                    $wait = $spin && $i >= $c->capacity;
                    Models\ClubBooking::create(['club_class_id' => $c->id, 'date' => $date->toDateString(), 'club_member_id' => $p->id, 'name' => $p->name,
                        'status' => $past ? (random_int(1, 100) <= 82 ? 'attended' : 'no_show') : ($wait ? 'waitlist' : 'booked'), 'position' => $wait ? $i - $c->capacity + 1 : null, 'user_id' => $u->id]);
                }
                if ($past && $kofiNoShows < 3 && $back <= 20) {
                    Models\ClubBooking::create(['club_class_id' => $c->id, 'date' => $date->toDateString(), 'club_member_id' => $kofiCard->id, 'name' => $kofiCard->name, 'status' => 'no_show', 'user_id' => $u->id]);
                    $kofiNoShows++;
                }
            }
        }
    }

    /**
     * v5.2 Serviced apartments & leases demo (standalone units – no hotel rooms are taken off sale): a corporate 2-bed with
     * 8% WHT and metered electricity, an expat paid half-yearly in advance, a residential 3-bed quarterly in arrears, an office
     * suite on a yearly cycle already reviewed +10%, a kiosk in arrears with a late fee, a studio on notice, an ended lease
     * with damages and its deposit settled, a signed draft starting next week, one vacant unit and one under maintenance.
     */
    protected function seedLeases(bool $rich, Carbon $today): void
    {
        \App\Services\LeaseService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $u = Auth::user() ?? Models\User::where('username', 'admin')->first();
        $LS = \App\Services\LeaseService::class;
        $LB = \App\Services\LeaseBillingService::class;
        $momo = Models\PaymentMode::where('type', 'wallet')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id');
        $d = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $ahead = (int) $LS::cfg('lease_bill_ahead_days');
        $blk = 'Nowills Residences – Block A';
        $unit = fn (array $a) => $LS::saveUnit($a + ['block' => $blk, 'furnished' => 1, 'is_active' => 1]);
        $units = [
            'A-101' => $unit(['code' => 'A-101', 'name' => 'Studio apartment', 'type' => 'studio', 'floor' => '1', 'bedrooms' => 0, 'size_sqm' => 38, 'tax_class' => 'serviced', 'list_rent' => 4500, 'service_charge' => 300, 'elec_meter' => 'ECG-P1101', 'amenities' => 'AC, Wi-Fi, kitchenette, weekly housekeeping']),
            'A-102' => $unit(['code' => 'A-102', 'name' => 'One-bedroom apartment', 'type' => '1bed', 'floor' => '1', 'bedrooms' => 1, 'size_sqm' => 62, 'tax_class' => 'serviced', 'list_rent' => 7500, 'service_charge' => 500, 'elec_meter' => 'ECG-P1102', 'amenities' => 'AC, Wi-Fi, full kitchen, pool & gym, twice-weekly housekeeping']),
            'A-103' => $unit(['code' => 'A-103', 'name' => 'Studio apartment', 'type' => 'studio', 'floor' => '1', 'bedrooms' => 0, 'size_sqm' => 36, 'tax_class' => 'serviced', 'list_rent' => 4200, 'service_charge' => 300, 'elec_meter' => 'ECG-P1103']),
            'A-201' => $unit(['code' => 'A-201', 'name' => 'Two-bedroom executive apartment', 'type' => '2bed', 'floor' => '2', 'bedrooms' => 2, 'size_sqm' => 96, 'tax_class' => 'serviced', 'list_rent' => 11000, 'service_charge' => 800, 'elec_meter' => 'ECG-P1201', 'water_meter' => 'GWL-2201', 'amenities' => 'AC, Wi-Fi, 2 parking bays, pool & gym, daily housekeeping']),
            'A-202' => $unit(['code' => 'A-202', 'name' => 'Three-bedroom family apartment', 'type' => '3bed', 'floor' => '2', 'bedrooms' => 3, 'size_sqm' => 128, 'furnished' => 0, 'tax_class' => 'residential', 'list_rent' => 9000, 'service_charge' => 0, 'elec_meter' => 'ECG-P1202', 'amenities' => 'Unfurnished, prepaid ECG meter, 2 parking bays']),
            'A-301' => $unit(['code' => 'A-301', 'name' => 'Penthouse with roof terrace', 'type' => 'penthouse', 'floor' => '3', 'bedrooms' => 3, 'size_sqm' => 180, 'tax_class' => 'serviced', 'list_rent' => 18000, 'service_charge' => 1200, 'elec_meter' => 'ECG-P1301', 'amenities' => 'Roof terrace, AC, Wi-Fi, butler service, 3 parking bays']),
            'A-302' => $unit(['code' => 'A-302', 'name' => 'Two-bedroom apartment', 'type' => '2bed', 'floor' => '3', 'bedrooms' => 2, 'size_sqm' => 92, 'tax_class' => 'serviced', 'list_rent' => 10500, 'service_charge' => 800, 'status' => 'maintenance', 'amenities' => 'Kitchen & bathroom refurbishment until next month']),
            'B-G01' => $unit(['code' => 'B-G01', 'name' => 'Ground-floor office suite', 'type' => 'office', 'block' => 'Nowills Business Wing', 'floor' => 'G', 'bedrooms' => 0, 'size_sqm' => 110, 'furnished' => 0, 'tax_class' => 'commercial', 'list_rent' => 14000, 'service_charge' => 1200, 'elec_meter' => 'ECG-C0001']),
            'B-G02' => $unit(['code' => 'B-G02', 'name' => 'Lobby retail kiosk', 'type' => 'shop', 'block' => 'Nowills Business Wing', 'floor' => 'G', 'bedrooms' => 0, 'size_sqm' => 12, 'furnished' => 0, 'tax_class' => 'commercial', 'list_rent' => 2500, 'service_charge' => 0]),
        ];
        // invoice each period on its bill-ahead date until the next period is beyond the window
        $catchUp = function ($l) use ($LS, $u, $today, $ahead) {
            while (true) {
                $l->refresh();
                $ps = $l->billed_to ? $l->billed_to->copy()->addDay() : $l->start_date->copy();
                if ($ps->gt($l->lastDay()) || $ps->copy()->subDays($ahead)->gt($today)) {
                    break;
                }
                $LS::bill($l, $u, max($ps->copy()->subDays($ahead)->toDateString(), $l->start_date->toDateString()));
            }
        };
        // pay every rent period whose invoice date is on or before $until, a few days after it was raised
        $payPeriods = function ($l, string $until, int $mode, bool $wht = false) use ($LB, $u) {
            $rents = Models\LeaseCharge::where('lease_id', $l->id)->where('type', 'rent')->whereNull('cancelled_at')->where('date', '<=', $until)->orderBy('period_start')->get();
            foreach ($rents as $r) {
                $group = Models\LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->where('period_start', $r->period_start)->whereIn('type', ['rent', 'service_charge'])->get();
                $total = round((float) $group->sum('total') - (float) $group->sum('paid'), 2);
                if ($total <= 0.004) {
                    continue;
                }
                $w = $wht ? round((float) $r->amount * (float) $l->wht_pct / 100, 2) : 0;
                $LB::pay($l, 'payment', $total - $w, $mode, 'TXN'.random_int(100000, 999999), $u, min($until, $r->due_date->copy()->addDays(random_int(0, 3))->toDateString()), $w, $w ? 'WHT/'.$r->due_date->format('ym').'/'.random_int(100, 999) : null);
            }
        };
        $mk = fn (array $a, int $startOffset) => $LS::create($a + ['start_date' => $d($startOffset)], $u, $d($startOffset));
        $moveIn = function ($l, array $readings = []) use ($LS, $u) {
            $LS::sign($l, $l->company ? 'For '.$l->company->name.': '.$l->tenant_name : $l->tenant_name);
            $LS::activate($l, $readings, collect(Models\LeaseInspection::CHECKLIST)->flatMap(fn ($items, $area) => collect($items)->map(fn ($i) => ['area' => $area, 'item' => $i, 'condition' => 'good']))->values()->all(), $u, $l->start_date->toDateString());
            Models\Lease::whereKey($l->id)->update(['signed_at' => $l->start_date->copy()->subDays(3)->setTime(11, 0)]);
            $l->refresh();
        };
        $infosys = Models\Company::where('name', 'like', 'Infosys%')->first();
        $tcs = Models\Company::where('name', 'like', 'Tata%')->first() ?? $infosys;

        // 1. corporate 2-bed, monthly, 8% WHT, metered electricity & water, readings every month
        $l1 = $mk(['lease_unit_id' => $units['A-201']->id, 'company_id' => $infosys?->id, 'tenant_name' => 'Rahul Menon', 'phone' => '+233 20 555 0142', 'email' => 'rahul.menon@example.com', 'id_type' => 'Passport', 'id_no' => 'Z5561203',
            'occupants' => 'Priya Menon (spouse)', 'term_months' => 12, 'rent' => 11000, 'service_charge' => 800, 'cycle_months' => 1, 'deposit_amount' => 22000, 'elec_mode' => 'metered', 'water_mode' => 'metered',
            'inclusions' => 'Daily housekeeping, Wi-Fi, 2 parking bays, pool & gym', 'notice_days' => 60, 'wht_pct' => 8], -100);
        $LB::pay($l1, 'deposit', 22000, $card, 'INFY-PO-7781', $u, $d(-102));
        $moveIn($l1, ['electricity' => 18450, 'water' => 902]);
        $e = 18450.0; $w = 902.0;
        foreach ([-70, -40, -10] as $off) {
            $e += random_int(520, 680); $w += random_int(14, 22);
            $LS::recordReading($units['A-201'], 'electricity', $e, $u, $d($off));
            $LS::recordReading($units['A-201'], 'water', $w, $u, $d($off));
        }
        $catchUp($l1);
        $payPeriods($l1, $d(-5), $card, true);
        $l1->refresh();
        foreach (Models\LeaseCharge::where('lease_id', $l1->id)->whereIn('type', ['electricity', 'water'])->where('date', '<', $d(-20))->get() as $c) {
            $LB::pay($l1, 'payment', (float) $c->total, $card, 'UTIL-'.$c->charge_no, $u, $c->date->copy()->addDays(6)->toDateString()); // receipts settle the oldest due first
        }

        // 2. expat 1-bed, 6 months billed & paid half-yearly in advance
        $l2 = $mk(['lease_unit_id' => $units['A-102']->id, 'tenant_name' => 'Jean-Luc Moreau', 'phone' => '+233 24 410 7733', 'email' => 'jl.moreau@example.com', 'id_type' => 'Passport', 'id_no' => '19FV22817',
            'term_months' => 6, 'rent' => 7500, 'service_charge' => 500, 'cycle_months' => 6, 'deposit_amount' => 8000, 'inclusions' => 'Twice-weekly housekeeping, Wi-Fi, pool & gym'], -45);
        $LB::pay($l2, 'deposit', 8000, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-47));
        $moveIn($l2);
        $catchUp($l2);
        $payPeriods($l2, $today->toDateString(), $card);

        // 3. residential 3-bed, quarterly (Rent Act cap: 6 months), current quarter only part-paid → arrears
        $l3 = $mk(['lease_unit_id' => $units['A-202']->id, 'tenant_name' => 'Kwame & Efua Boateng', 'phone' => '+233 27 301 8841', 'email' => 'kboateng@example.com', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-712004381-5',
            'occupants' => '2 children', 'tax_class' => 'residential', 'term_months' => 12, 'rent' => 9000, 'service_charge' => 0, 'cycle_months' => 3, 'deposit_amount' => 18000, 'elec_mode' => 'prepaid', 'water_mode' => 'included'], -200);
        $LB::pay($l3, 'deposit', 18000, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-203));
        $moveIn($l3);
        $catchUp($l3);
        $payPeriods($l3, $d(-60), $momo);
        $q3 = Models\LeaseCharge::where('lease_id', $l3->id)->where('type', 'rent')->orderByDesc('period_start')->first();
        $LB::pay($l3, 'payment', 10000, $momo, 'MM'.random_int(10000000, 99999999), $u, min($today->toDateString(), $q3->due_date->copy()->addDays(2)->toDateString()), 0, null, 'Part payment – balance promised end of month');

        // 4. office suite, yearly, 3-year term, +10% rent review each year (year 2 already reviewed), company withholds 8%
        $l4 = $mk(['lease_unit_id' => $units['B-G01']->id, 'company_id' => $tcs?->id, 'tenant_name' => 'Neha Joshi (country office)', 'phone' => '+233 30 277 4410', 'email' => 'accra.office@example.com', 'id_type' => 'Company registration', 'id_no' => 'CS081122019',
            'tax_class' => 'commercial', 'term_months' => 36, 'rent' => 14000, 'service_charge' => 1200, 'cycle_months' => 12, 'deposit_amount' => 28000, 'escalation_pct' => 10, 'escalation_months' => 12, 'notice_days' => 90,
            'elec_mode' => 'prepaid', 'wht_pct' => 8, 'inclusions' => 'Reception, cleaning of common areas, 4 parking bays'], -430);
        $LB::pay($l4, 'deposit', 28000, $card, 'TCS-DEP', $u, $d(-433));
        $moveIn($l4);
        $catchUp($l4);
        $payPeriods($l4, $today->toDateString(), $card, true);

        // 5. lobby kiosk, monthly – last two months unpaid, a 5% late fee on the older one
        $l5 = $mk(['lease_unit_id' => $units['B-G02']->id, 'tenant_name' => 'Abena Owusu t/a Abena Mobile Accessories', 'phone' => '+233 55 918 2230', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-715338210-2',
            'tax_class' => 'commercial', 'term_months' => 12, 'rent' => 2500, 'service_charge' => 0, 'cycle_months' => 1, 'deposit_amount' => 2500, 'notice_days' => 30], -120);
        $LB::pay($l5, 'deposit', 2500, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-121));
        $moveIn($l5);
        $catchUp($l5);
        $payPeriods($l5, $d(-60), $momo);
        $old = Models\LeaseCharge::where('lease_id', $l5->id)->where('type', 'rent')->whereRaw('total - paid > 0.004')->orderBy('period_start')->first();
        if ($old && $old->due_date->lt($today->copy()->subDays(10))) {
            $LB::raise($l5, 'late_fee', 'Late payment fee 5% on '.$old->charge_no, round($old->outstanding() * 0.05, 2), ['date' => $old->due_date->copy()->addDays(6)->toDateString(), 'due_date' => $old->due_date->copy()->addDays(6)->toDateString(), 'late_fee_of_id' => $old->id], $u);
        }

        // 6. studio on notice – the tenant leaves in 10 days
        $l6 = $mk(['lease_unit_id' => $units['A-101']->id, 'tenant_name' => 'Dr. Yaw Ofori-Atta', 'phone' => '+233 20 811 4478', 'email' => 'yaw.ofori@example.com', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-709812334-1',
            'term_months' => 12, 'rent' => 4500, 'service_charge' => 300, 'cycle_months' => 1, 'deposit_amount' => 9000, 'elec_mode' => 'included'], -170);
        $LB::pay($l6, 'deposit', 9000, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-171));
        $moveIn($l6);
        $LS::giveNotice($l6, 'tenant', $d(10), 'Transferred to Kumasi', $u, $d(-20));
        $catchUp($l6);
        $payPeriods($l6, $today->toDateString(), $momo);

        // 7. ended lease: 6 months, moved out 2 months ago with damages, deposit applied & the rest refunded
        $l7 = $mk(['lease_unit_id' => $units['A-103']->id, 'tenant_name' => 'Sarah Whitfield', 'phone' => '+44 7700 900412', 'email' => 's.whitfield@example.com', 'id_type' => 'Passport', 'id_no' => '553018842',
            'term_months' => 6, 'rent' => 4200, 'service_charge' => 300, 'cycle_months' => 1, 'deposit_amount' => 8400, 'elec_mode' => 'metered'], -250);
        $LB::pay($l7, 'deposit', 8400, $card, 'DEP-SW', $u, $d(-251));
        $moveIn($l7, ['electricity' => 7020]);
        $catchUp($l7);
        $payPeriods($l7, $today->toDateString(), $card);
        $l7->refresh();
        $out = $l7->end_date->toDateString();
        Models\Lease::whereKey($l7->id)->update(['status' => 'notice', 'notice_on' => $l7->end_date->copy()->subDays(35)->toDateString(), 'notice_by' => 'tenant', 'move_out_on' => $out, 'end_reason' => 'Assignment in Ghana completed']);
        $l7->refresh();
        $items = collect(Models\LeaseInspection::CHECKLIST)->flatMap(fn ($its, $area) => collect($its)->map(fn ($i) => ['area' => $area, 'item' => $i, 'condition' => 'good']))->values()->all();
        foreach ($items as &$it) {
            if ($it['item'] === 'Walls & paint') { $it = ['condition' => 'damaged', 'note' => 'Wall stained and scuffed behind the sofa – repaint', 'charge' => 650] + $it; }
            if ($it['item'] === 'TV & remote') { $it = ['condition' => 'missing', 'note' => 'TV remote not returned', 'charge' => 200] + $it; }
        }
        unset($it);
        // record the move-out on its own business date (the service only accepts today or earlier and dates credits by the business date)
        $realBdate = Models\Hotel::whereKey(\App\Support\Tenant::id())->value('business_date');
        Models\Hotel::whereKey(\App\Support\Tenant::id())->update(['business_date' => $out]);
        \App\Support\Tenant::set(Models\Hotel::find(\App\Support\Tenant::id()));
        try {
            $LS::moveOut($l7, $out, ['electricity' => 7020 + random_int(230, 270) * 6], $items, 'Sarah Whitfield', $u);
        } finally {
            Models\Hotel::whereKey(\App\Support\Tenant::id())->update(['business_date' => $realBdate]);
            \App\Support\Tenant::set(Models\Hotel::find(\App\Support\Tenant::id()));
        }
        $l7->refresh();
        $s = $LS::settlement($l7);
        if ($s['apply'] > 0) {
            $LB::pay($l7, 'deposit_applied', $s['apply'], null, 'Move-out settlement', $u, Carbon::parse($out)->addDays(5)->toDateString());
        }
        if ($s['refund'] > 0) {
            $LB::pay($l7, 'deposit_refund', $s['refund'], $card, 'Refund to card ending 4412', $u, Carbon::parse($out)->addDays(12)->toDateString());
        }
        Models\Lease::whereKey($l7->id)->update(['settled_at' => Carbon::parse($out)->addDays(12)->setTime(15, 0)]);

        // 8. signed draft on the same studio, starting next week – deposit received
        $l8 = $mk(['lease_unit_id' => $units['A-103']->id, 'tenant_name' => 'Nana Akua Addo', 'phone' => '+233 24 660 1983', 'email' => 'nana.addo@example.com', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-718220917-4',
            'term_months' => 6, 'rent' => 4200, 'service_charge' => 300, 'cycle_months' => 1, 'deposit_amount' => 8400, 'elec_mode' => 'metered'], 6);
        $LS::sign($l8, 'Nana Akua Addo');
        $LB::pay($l8, 'deposit', 8400, $momo, 'MM'.random_int(10000000, 99999999), $u, $today->toDateString());

        \App\Services\LedgerService::sync(true);
    }

    /** v5.3 Income audit: five closed days, one awaiting the controller, yesterday in audit with exceptions; authorisations; a room count. */
    protected function seedIncomeAudit(bool $rich, Carbon $today): void
    {
        \App\Services\IncomeAuditService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $IA = \App\Services\IncomeAuditService::class;
        $RA = \App\Services\RevenueAuthService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$fd, $fom, $acc, $admin, $hk] = [$u('frontdesk'), $u('fom'), $u('accounts'), $u('admin'), $u('housekeeping')];
        $bd = bdate()->copy();
        $d = fn (int $n) => $bd->copy()->addDays($n)->toDateString();
        $y = $d(-1);

        // in-house guests billed to themselves, with last night's tariff posted
        $stays = Models\ReservationRoom::with('reservation', 'folio', 'room')->where('status', 'checked_in')->whereDate('arrival_date', '<=', $y)->whereDate('departure_date', '>', $d(0))
            ->whereHas('reservation', fn ($q) => $q->where('billing_instruction', 'guest')->whereNull('ota_channel_id'))->whereHas('folio', fn ($q) => $q->where('status', 'open'))
            ->orderBy('id')->get()->filter(fn ($s) => Models\FolioTransaction::where('folio_id', $s->folio->id)->where('source_type', 'night')->where('reference', $y)->where('is_void', false)->exists())->values();
        $pool = Models\ReservationRoom::with('folio')->where('status', 'checked_in')->whereNotIn('id', $stays->take(4)->pluck('id'))
            ->whereHas('reservation', fn ($q) => $q->where('billing_instruction', 'guest'))->whereHas('folio', fn ($q) => $q->where('status', 'open'))->orderBy('id')->get()->values();
        $tariff = fn ($s, $date) => Models\FolioTransaction::where('folio_id', $s->folio->id)->where('source_type', 'night')->where('reference', $date)->where('is_void', false)->first();

        // 1) a walk-in given a lower rate at check-in without approval (open rate variance yesterday)
        if ($s = $stays->get(0)) {
            $new = round((float) $s->rate * 0.7, -1);
            $tariff($s, $y)?->update(['amount' => $new, 'tax_amount' => round($new * 0.21, 2)]);
            $s->update(['rate' => $new]);
        }
        // 2) a rate below expected, authorised by the FOM (accepted automatically)
        if ($s = $stays->get(1)) {
            Auth::setUser($fd);
            $a = $RA::request(['kind' => 'rate', 'reservation_room_id' => $s->id, 'date_from' => $y, 'date_to' => $d(0), 'amount' => round((float) $s->rate * 0.8, -1), 'reason' => 'Long-stay regular guest – FOM matched the rate offered by a competitor'], $fd);
            Auth::setUser($fom);
            $a = $RA::approve($a, $fom, 'Matched – keep the guest');
            $tariff($s, $y)?->update(['amount' => $a->amount, 'tax_amount' => round((float) $a->amount * 0.21, 2)]);
        }
        // 3) a complimentary night tonight (service recovery), approved
        if ($s = $stays->get(2)) {
            Auth::setUser($fd);
            $a = $RA::request(['kind' => 'comp', 'reservation_room_id' => $s->id, 'date_from' => $d(0), 'date_to' => $d(0), 'reason' => 'Service recovery – air-conditioning failed overnight, guest moved; GM agreed one night complimentary'], $fd);
            Auth::setUser($admin); // a night's rate is above the FOM's limit – the GM approves
            $RA::approve($a, $admin, 'Approved – apologise again at check-out');
        }
        // 4) a rate request declined
        if ($s = $stays->get(3)) {
            Auth::setUser($fd);
            $a = $RA::request(['kind' => 'rate', 'reservation_room_id' => $s->id, 'amount' => round((float) $s->rate * 0.5, -1), 'reason' => 'Guest asked for half price because of street noise'], $fd);
            $RA::decline($a, $fom, 'Offer a quieter room on the courtyard side instead');
        }
        // 5) a discount waiting for approval
        if ($s = $pool->get(0) ?? $stays->get(3)) {
            Auth::setUser($fd);
            $RA::request(['kind' => 'discount', 'folio_id' => $s->folio->id, 'amount' => 350, 'reason' => 'Restaurant order served cold twice – guest asks for dinner to be written off'], $fd);
        }
        // 6) a large allowance posted yesterday without authorisation
        if ($s = $pool->get(1) ?? $stays->get(0)) {
            Auth::setUser($fd);
            $tx = \App\Services\FolioService::discount($s->folio, 450, 'Allowance – laundry damaged guest shirt');
            $tx->update(['business_date' => $y]);
        }
        // 7) a minibar charge posted yesterday – voided after the day closed (void + figures changed)
        $voidLater = null;
        if ($s = $stays->get(1)) {
            Auth::setUser($fd);
            $voidLater = \App\Services\FolioService::charge($s->folio, 'minibar', 'Minibar – 2 × Club beer, 1 × cashews', 95, true);
            $voidLater->update(['business_date' => $y]);
        }
        // a charge voided on an earlier day (explained in a closed audit)
        if ($s = $stays->get(0)) {
            Auth::setUser($fd);
            $t = \App\Services\FolioService::charge($s->folio, 'laundry', 'Laundry – 3 pieces', 60, true);
            $t->update(['business_date' => $d(-4)]);
            \App\Services\FolioService::void($t, 'Posted to the wrong room – re-posted to the correct guest');
        }

        // housekeeping room count for yesterday: one sleeper (resolved), one unregistered (open)
        $rooms = Models\Room::where('is_active', true)->orderBy('room_no')->get();
        $sheet = [];
        foreach ($rooms as $r) {
            $fo = $IA::foStatus($r);
            $sheet[$r->id] = ['hk' => ['occupied' => 'occupied', 'vacant' => 'vacant', 'out_of_order' => 'ooo'][$fo], 'luggage' => $fo === 'occupied'];
        }
        $occ = $rooms->filter(fn ($r) => $IA::foStatus($r) === 'occupied')->values();
        $vac = $rooms->filter(fn ($r) => $IA::foStatus($r) === 'vacant')->values();
        if ($occ->count() && $vac->count()) {
            $sleeper = $occ->last();
            $unreg = $vac->last();
            $sheet[$sleeper->id] = ['hk' => 'vacant', 'luggage' => false];
            $sheet[$unreg->id] = ['hk' => 'occupied', 'luggage' => true];
            $IA::saveCount($sheet, $hk, $y);
            $IA::resolveCount(Models\RoomCount::whereDate('business_date', $y)->where('room_id', $sleeper->id)->first(), 'guest_out', 'Guest confirmed by phone – on a day trip to Cape Coast, luggage in the wardrobe', $fom);
        }

        // audit days: run the checks for the last seven closed days
        $notes = ['void' => 'Posted to the wrong room and re-posted correctly – checked with the cashier', 'cash_variance' => 'Counted again with the duty manager – shortage deducted per policy',
            'einvoice' => 'GRA portal was down – re-submitted and signed on retry', 'credit_limit' => 'Card pre-authorisation taken for the balance',
            'discount' => 'Laundry damage claim – receipt on file, GM informed', 'open_pos' => 'Bill settled next morning – late function', 'rate_variance' => 'Rate agreed by the FOM – retrospective authorisation filed'];
        Auth::setUser($acc);
        for ($i = 7; $i >= 1; $i--) {
            $day = $IA::day($d(-$i));
            $IA::run($day, $acc);
            if ($i >= 2) {
                foreach (Models\AuditFinding::where('audit_day_id', $day->id)->whereIn('status', ['open', 'escalated'])->get() as $f) {
                    $IA::resolve($f, $f->check === 'rate_variance' ? 'corrected' : 'explained', $notes[$f->check] ?? 'Checked against the source documents – in order', $acc);
                }
                $IA::sign($day, $i === 2 ? 'All exceptions explained – cash shortage to be discussed with the cashier' : null, $acc);
                if ($i >= 3) {
                    Auth::setUser($admin);
                    $IA::close($day, null, $admin);
                    Auth::setUser($acc);
                }
            }
        }
        // yesterday: the auditor starts – void after the day closed, re-run, explain the void, escalate the unauthorised rate
        if ($voidLater) {
            Auth::setUser($fd);
            \App\Services\FolioService::void($voidLater, 'Guest disputes – minibar was already empty on arrival');
            Auth::setUser($acc);
            $day = Models\AuditDay::whereDate('business_date', $y)->first();
            $IA::run($day, $acc);
            $void = Models\AuditFinding::where('audit_day_id', $day->id)->where('check', 'void')->where('status', 'open')->first();
            $void && $IA::resolve($void, 'explained', 'Minibar dispute – housekeeping confirms the minibar was not restocked after the previous guest', $acc);
            $rate = Models\AuditFinding::where('audit_day_id', $day->id)->where('check', 'rate_variance')->where('status', 'open')->first();
            $rate && $IA::resolve($rate, 'escalated', 'Rate reduced at check-in with no authorisation on file – front office cannot say who agreed it', $acc);
        }
        \App\Services\LedgerService::sync(true); // the back-dated tariffs / allowance / voids above sit inside days already posted
        Auth::setUser($prevUser ?? $admin);
    }

    /** v5.4 Saved report views, scheduled report e-mails (with a delivery history and one auto-paused schedule), KPI alerts. */
    /**
     * v5.5 packages: breakfast included in every CP rate (breakfast + dinner in MAP, Wi-Fi in the suites); the posted nights of
     * the last 9 days allocate their entitlements, most breakfasts were eaten (some partly, some skipped = breakage), a dinner
     * add-on used partly last night, an airport drop and a spa credit sold for tonight, and this morning's breakfast half served
     * at the Meal Desk (one extra cover charged to the room, one entry voided).
     */
    protected function seedPackages(bool $rich): void
    {
        $PS = \App\Services\PackageService::class;
        $PS::ensureDefaults();
        $pk = Models\Package::pluck('id', 'code');
        $rest = Models\Outlet::where('type', 'restaurant')->orderBy('id')->value('id');
        Models\Package::whereIn('code', ['BRKF', 'LNCH', 'DINR'])->whereNull('outlet_id')->update(['outlet_id' => $rest]);
        Models\Package::where('code', 'SPA')->update(['outlet_id' => null]);
        foreach (Models\RatePlan::all() as $plan) {
            $ids = match ($plan->meal_plan) {
                'CP' => [$pk['BRKF']], 'MAP' => [$pk['BRKF'], $pk['DINR']], 'AP' => [$pk['BRKF'], $pk['LNCH'], $pk['DINR']], default => [],
            };
            if (preg_match('/^(EXE|PRE)-/', $plan->code)) {
                $ids[] = $pk['WIFI'];
            }
            $plan->packages()->syncWithPivotValues($ids, ['hotel_id' => Tenant::id()]);
        }
        if (! $rich) {
            return;
        }
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$rs, $fd, $fom] = [$u('cashier'), $u('frontdesk'), $u('fom')];
        $bd = bdate()->copy();
        $from = $bd->copy()->subDays(9);

        // entitlements for every night already posted
        $txs = Models\FolioTransaction::with('folio.stay')->where('source_type', 'night')->where('is_void', false)
            ->whereBetween('reference', [$from->toDateString(), $bd->copy()->subDay()->toDateString()])->orderBy('reference')->orderBy('id')->get();
        foreach ($txs as $tx) {
            if ($stay = $tx->folio?->stay) {
                $PS::allocate($stay, Carbon::parse($tx->reference), $tx);
            }
        }

        // a dinner add-on sold yesterday to an in-house guest: posted with last night's tariff, GH₵ 520 of the allowance used
        Auth::setUser($fd);
        $inHouse = Models\ReservationRoom::with('folio', 'room')->where('status', 'checked_in')->orderBy('id')->get();
        $yest = $bd->copy()->subDay();
        $dinStay = $inHouse->first(fn ($s) => $s->arrival_date->lte($yest) && $s->departure_date->gt($bd->copy()->addDay()) && $s->folio?->status === 'open');
        if ($dinStay) {
            $fc = $PS::addToStay($dinStay, Models\Package::find($pk['DINR']), $yest->toDateString());
            $tx = FolioService::charge($dinStay->folio, $fc->category, $fc->description." ({$yest->format('d M')})", (float) $fc->amount, $fc->apply_tax,
                ['source_type' => 'fixed', 'source_id' => $fc->id, 'reference' => $yest->toDateString(), 'business_date' => $yest->toDateString()]);
            $fc->forceFill(['last_posted_date' => $yest->toDateString(), 'times_posted' => 1])->save();
            $PS::allocateAddons($dinStay);
        }
        // tonight: an airport drop for a guest leaving tomorrow and a spa credit
        $leaving = $inHouse->first(fn ($s) => $s->departure_date->toDateString() === $bd->copy()->addDay()->toDateString() && $s->id !== $dinStay?->id);
        $leaving && $PS::addToStay($leaving, Models\Package::find($pk['AIRP']), $bd->toDateString());
        $spaStay = $inHouse->first(fn ($s) => ! in_array($s->id, [$dinStay?->id, $leaving?->id]) && $s->departure_date->gt($bd));
        $spaStay && $PS::addToStay($spaStay, Models\Package::find($pk['SPA']), $bd->toDateString());

        // past days at the restaurant door: most breakfasts eaten, some partly, a few skipped (breakage)
        $past = Models\PackageEntitlement::with('package')->where('is_allowance', true)->where('service_date', '<', $bd->toDateString())->orderBy('service_date')->orderBy('id')->get();
        $i = 0;
        foreach ($past as $e) {
            $i++;
            if ($i % 7 === 3) {
                continue; // skipped – expires below
            }
            $amountMode = $e->package->redeem_mode === 'amount';
            $covers = $amountMode ? 1 : ($i % 7 === 5 && $e->qty > 1 ? $e->qty - 1 : $e->qty);
            $amt = $amountMode ? min((float) $e->amount, 520.0) : $PS::valueOf($e, $covers);
            $at = $e->service_date->copy()->setTime($e->package->meal === 'breakfast' ? 7 : 19, 10 + ($i * 7) % 45);
            $r = new Models\PackageRedemption(['redemption_no' => $PS::nextNo(), 'package_entitlement_id' => $e->id, 'reservation_room_id' => $e->reservation_room_id,
                'package_id' => $e->package_id, 'outlet_id' => $e->package->outlet_id, 'business_date' => $e->service_date->toDateString(), 'covers' => $covers, 'amount' => $amt,
                'extra_covers' => $i % 11 === 0 ? 1 : 0, 'extra_amount' => $i % 11 === 0 ? (float) $e->package->overage_price : 0, 'overage_mode' => $i % 11 === 0 ? 'paid' : 'folio',
                'pos_ref' => $amountMode ? 'KOT-'.(4100 + $i) : null, 'recorded_by' => $rs->id]);
            $r->created_at = $r->updated_at = $at;
            $r->save();
            $PS::recompute($e);
        }
        for ($d = $from->copy()->addDay(); $d->lt($bd); $d->addDay()) {
            $PS::expireDue($d->copy());
        }
        Models\PackageEntitlement::where('status', 'expired')->whereNotNull('expired_date')->get()
            ->each(fn ($e) => $e->forceFill(['updated_at' => $e->expired_date->copy()->setTime(23, 55)])->saveQuietly());

        // this morning: breakfast being served
        Auth::setUser($rs);
        $today = $PS::usable($bd)->with('stay.folio')->where('package_id', $pk['BRKF'])->orderBy('id')->get();
        foreach ($today->take(4)->values() as $n => $e) {
            $extra = $n === 1 && $e->stay->folio?->status === 'open' ? 1 : 0;
            $PS::redeem($e->stay, Models\Package::find($pk['BRKF']), ['covers' => $e->qty, 'extra_covers' => $extra, 'overage_mode' => 'folio']);
        }
        if ($wrong = $today->get(4)) {
            $r = $PS::redeem($wrong->stay, Models\Package::find($pk['BRKF']), ['covers' => 1]);
            Auth::setUser($fom);
            $PS::void($r, 'Wrong room keyed – guest was from the next room');
        }
        LedgerService::sync(true);
        $prevUser ? Auth::setUser($prevUser) : Auth::forgetUser();
    }

    /**
     * v5.6 financial close demo, run through the real workflow: schedules and recurring journals posted month by month,
     * checks run, gaps waived with reasons, manual tasks signed off, submitted by the accountant and approved by the
     * administrator (four eyes) – which locks the ledger and, for March, posts the FY closing entry. May is reopened
     * once for a late credit note. Last month is left open with its period-end postings due; timestamps are back-dated.
     */
    /** v5.7 treasury: all activity dated from the 1st of last month, into a new Ecobank account (never the reconciled GCB / MoMo heads). */
    protected function seedTreasury(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $TS = \App\Services\TreasuryService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $acc, $fom] = [$u('admin'), $u('accounts'), $u('fom')];
        \App\Services\ChartOfAccounts::ensure();
        $TS::ensureDefaults();
        $sys = fn (string $k) => Models\AccountHead::sys($k);
        $T = bdate()->toDateString();
        $m0 = bdate()->copy()->subMonthNoOverflow()->startOfMonth(); // e.g. 2026-08-01
        Auth::setUser($acc);

        // the register: banks, wallet, card acquirer terms
        $acct = fn (string $key) => Models\TreasuryAccount::where('account_head_id', $sys($key))->first();
        $gcb = $acct('bank');
        $gcb->forceFill(['name' => 'GCB – Operating', 'bank_name' => 'GCB Bank', 'branch' => 'High Street, Accra', 'account_no' => '1011130004528', 'swift' => 'GHCBGHAC', 'min_balance' => 250000, 'overdraft_limit' => 500000])->save();
        $cash = $acct('cash');
        $cash->forceFill(['name' => 'Main Cashier – Cash in Hand', 'min_balance' => 0])->save();
        $card = $acct('card_clearing');
        $card->forceFill(['name' => 'Card Settlements (Visa / Mastercard – Ecobank acquiring)', 'bank_name' => 'Ecobank Ghana', 'settle_days' => 2, 'fee_rate' => 1.5])->save();
        $momo = $acct('wallet_clearing');
        $momo->forceFill(['name' => 'MTN MoMo Merchant Wallet', 'bank_name' => 'MTN Mobile Money', 'account_no' => '0244123987', 'fee_rate' => 1.0, 'min_balance' => 20000])->save();
        $eco = $TS::createAccount(['kind' => 'bank', 'name' => 'Ecobank – Collections', 'bank_name' => 'Ecobank Ghana', 'branch' => 'Ridge, Accra', 'account_no' => '1441000523187', 'swift' => 'ECOCGHAC',
            'currency' => 'GHS', 'min_balance' => 50000, 'overdraft_limit' => 0, 'in_position' => 1, 'is_active' => 1, 'notes' => 'Daily cash & cheque bankings, card settlements, term loan']);
        $ecoHead = $eco->account_head_id;

        // the lowest balance an account reaches from $d to today – a transfer may never take it below zero on any day
        $minFrom = function (int $head, string $d) use ($T): float {
            $bal = (float) Models\GlLine::where("account_head_id", $head)->where("date", "<", $d)->sum(\Illuminate\Support\Facades\DB::raw('debit - credit'));
            $min = PHP_FLOAT_MAX;
            $days = Models\GlLine::where('account_head_id', $head)->whereBetween('date', [$d, $T])->groupBy('date')->orderBy('date')->selectRaw('date, SUM(debit - credit) m')->pluck('m', 'date');
            $min = $bal;
            foreach ($days as $m) {
                $bal += (float) $m;
                $min = min($min, $bal);
            }

            return max(0, round($min, 2));
        };
        $send = function (Models\TreasuryTransfer $t, string $sent, ?string $credited = null, ?float $received = null, ?string $why = null) use ($TS, $acc, $admin, $fom) {
            if ($t->status === 'requested') {
                Auth::setUser($admin);
                $TS::approve($t, $admin);
                Auth::setUser($acc);
            }
            $TS::dispatch($t->fresh(), $fom, $sent);
            if ($credited && $t->fresh()->status === 'in_transit') {
                $TS::complete($t->fresh(), $acc, $credited, $received, null, $why);
            }

            return $t->fresh();
        };

        // an Ecobank term loan for the kitchen & laundry refit, drawn on the 3rd
        \App\Services\LedgerService::post(['date' => $m0->copy()->addDays(2)->toDateString(), 'reference' => 'ECO/TL/2026/118', 'narration' => 'Ecobank term loan drawdown – kitchen & laundry refit (36 months @ 24%)',
            'lines' => [[$ecoHead, 600000, 'Loan proceeds credited'], [$sys('bank_loans'), -600000, 'Ecobank term loan TL-2026-118']]], 'manual', null);

        // company cheques: one cleared, one to bounce, two in hand, two post-dated
        $cos = collect(\App\Services\CreditService::positions())->filter(fn ($p) => $p['outstanding'] > 5000 && empty($p['over_limit']))->sortByDesc('outstanding')->values(); // over-limit accounts keep their credit story
        $co = fn (int $i) => $cos[$i % max(1, $cos->count())]['company'] ?? Models\Company::orderBy('id')->skip($i)->first();
        $chq = function (int $i, string $no, string $bank, string $dated, string $recv, float $amt, ?string $drawer = null) use ($TS, $co, $acc, $sys) {
            $c = $drawer ? null : $co($i);
            $d = ['cheque_no' => $no, 'bank_name' => $bank, 'cheque_date' => $dated, 'received_on' => $recv, 'amount' => $amt];
            $d += $c ? ['company_id' => $c->id, 'drawer' => $c->name] : ['drawer' => $drawer, 'account_head_id' => $sys('rev_space_rental'), 'notes' => 'Hall hire – monthly meeting'];

            return $TS::receiveCheque($d, $acc);
        };
        $c1 = $chq(0, '004417', 'Stanbic Bank Ghana', $m0->copy()->addDays(10)->toDateString(), $m0->copy()->addDays(11)->toDateString(), 18500);
        $c2 = $chq(1, '118203', 'CalBank', $m0->copy()->addDays(19)->toDateString(), $m0->copy()->addDays(20)->toDateString(), 12600);

        // weekly cash bankings to Ecobank: ~45% of the cash above the float, credited the next day
        $float = (float) $TS::cfg('treasury_cash_float');
        $weeks = [];
        for ($d = $m0->copy()->addDays(2); $d->lt(bdate()->copy()->subDays(2)); $d->addDays(7)) {
            $weeks[] = $d->toDateString();
        }
        foreach ($weeks as $i => $d) {
            $avail = $minFrom($cash->account_head_id, $d) - $float;
            $amt = floor(max(0, $avail) * 0.45 / 10) * 10;
            $ids = [];
            if ($d > $c1->received_on->toDateString() && $c1->fresh()->status === 'in_hand') {
                $ids[] = $c1->id;
            }
            if ($d > $c2->received_on->toDateString() && $c2->fresh()->status === 'in_hand') {
                $ids[] = $c2->id;
            }
            if ($amt < 100 && ! $ids) {
                continue;
            }
            $t = $TS::createTransfer(['kind' => 'deposit', 'from_account_id' => $cash->id, 'to_account_id' => $eco->id, 'cash_amount' => $amt, 'date' => $d, 'slip_no' => 'ECO-DS-'.str_pad((string) (4410 + $i), 6, '0', STR_PAD_LEFT),
                'cheque_ids' => $ids, 'via_transit' => true, 'notes' => 'Weekly banking – night safe to Ecobank Ridge'], $fom);
            $short = $i === 2 && $amt > 500;
            $send($t, $d, Carbon::parse($d)->addDay()->toDateString(), $short ? (float) $t->amount - 50 : null, $short ? 'GH₵50 note found counterfeit at the bank count and retained by Ecobank' : null);
        }
        // the cheque from the second company comes back unpaid
        if ($c2->fresh()->status === 'deposited') {
            $TS::bounce($c2->fresh(), $acc, 'Refer to drawer', 50, Carbon::parse($c2->fresh()->left_on)->addDays(3)->toDateString());
        }
        // yesterday's banking is still in transit
        $y = bdate()->copy()->subDay()->toDateString();
        $amt = floor(max(0, $minFrom($cash->account_head_id, $y) - $float) * 0.3 / 10) * 10;
        if ($amt >= 100) {
            $t = $TS::createTransfer(['kind' => 'deposit', 'from_account_id' => $cash->id, 'to_account_id' => $eco->id, 'cash_amount' => $amt, 'date' => $y, 'slip_no' => 'ECO-DS-004499', 'via_transit' => true, 'notes' => 'Weekend takings'], $fom);
            $send($t, $y);
        }

        // card settlements swept fortnightly, 1.5% merchant discount withheld by the acquirer
        foreach ([4, 18, 32, 46] as $k) {
            $d = $m0->copy()->addDays($k);
            if ($d->gt(bdate()->copy()->subDays(2))) {
                break;
            }
            $gross = round($minFrom($card->account_head_id, $d->toDateString()) * 0.3, 2);
            if ($gross < 1000) {
                continue;
            }
            $t = $TS::createTransfer(['kind' => 'sweep', 'from_account_id' => $card->id, 'to_account_id' => $eco->id, 'cash_amount' => $gross, 'fee' => round($gross * 0.015, 2), 'date' => $d->toDateString(),
                'slip_no' => 'ECO-MSC-'.$d->format('md'), 'notes' => 'Card settlement batch'], $acc);
            $send($t, $d->toDateString());
        }
        // waiting for approval: the MoMo sweep and a top-up of the operating account
        $TS::createTransfer(['kind' => 'sweep', 'from_account_id' => $momo->id, 'to_account_id' => $eco->id, 'cash_amount' => min(120000, floor($minFrom($momo->account_head_id, $T) * 0.5)), 'fee' => 0, 'date' => $T, 'notes' => 'Monthly wallet sweep – MTN merchant settlement'], $acc);
        $TS::createTransfer(['kind' => 'transfer', 'from_account_id' => $eco->id, 'to_account_id' => $gcb->id, 'cash_amount' => 300000, 'date' => $T, 'slip_no' => 'ECO-RTGS-0931', 'via_transit' => true, 'notes' => 'Top up GCB operating account before payroll & GRA'], $acc);

        // cheques still in hand and post-dated
        $chq(2, '220981', 'Absa Bank Ghana', bdate()->copy()->subDays(3)->toDateString(), bdate()->copy()->subDays(2)->toDateString(), 7850);
        $chq(0, '000356', 'Fidelity Bank Ghana', bdate()->copy()->subDays(1)->toDateString(), bdate()->copy()->subDay()->toDateString(), 2400, 'Rotary Club of Accra-Airport');
        $chq(3, '731002', 'Zenith Bank Ghana', bdate()->copy()->addDays(3)->toDateString(), bdate()->copy()->subDays(4)->toDateString(), 25000);
        $chq(1, '118240', 'CalBank', bdate()->copy()->addDays(16)->toDateString(), bdate()->copy()->subDays(1)->toDateString(), 12650); // the replacement for the bounced cheque

        // cheque books: GCB (supplier payments already made by bank) and Ecobank (a council permit)
        $bGcb = $TS::addBook($gcb, 100201, 100250, 6, $m0->copy()->subDays(20)->toDateString());
        $pays = Models\SupplierPayment::whereNull('cancelled_at')->orderBy('date')->get()->filter(fn ($p) => \App\Services\LedgerService::modeAccount($p->payment_mode_id) === (int) $gcb->account_head_id)->values();
        foreach ($pays->take(3) as $i => $p) {
            $TS::issueCheque(['cheque_book_id' => $bGcb->id, 'supplier_payment_id' => $p->id, 'memo' => 'Supplier payment'], $acc);
            if ($i === 0) {
                $TS::spoilLeaf($bGcb->fresh(), $acc, 'Amount in words misspelt – leaf cancelled');
            }
        }
        $bEco = $TS::addBook($eco, 5501, 5525, 6, $m0->copy()->addDays(3)->toDateString());
        $TS::issueCheque(['cheque_book_id' => $bEco->id, 'payee' => 'Accra Metropolitan Assembly', 'amount' => 4800, 'account_head_id' => $sys('misc_expense'), 'cheque_date' => bdate()->copy()->subDays(6)->toDateString(),
            'memo' => 'Business operating permit '.bdate()->year.' – hotel & restaurant'], $acc);

        // expected flows the ledger does not know about yet
        $TS::saveLine(['description' => 'Ecobank term loan TL-2026-118 – monthly instalment', 'direction' => 'out', 'category' => 'Financing', 'amount' => 23540,
            'first_date' => bdate()->copy()->addMonthNoOverflow()->day(5)->toDateString(), 'frequency' => 'monthly', 'until_date' => bdate()->copy()->addMonthsNoOverflow(36)->day(5)->toDateString()], $acc);
        $TS::saveLine(['description' => 'Kitchen extraction hood replacement (balance to contractor)', 'direction' => 'out', 'category' => 'Capital expenditure', 'amount' => 85000,
            'first_date' => bdate()->copy()->addWeeks(5)->toDateString(), 'frequency' => 'once'], $acc);
        $TS::saveLine(['description' => 'MTN Ghana conference – 50% deposit', 'direction' => 'in', 'category' => 'Operating', 'amount' => 45000,
            'first_date' => bdate()->copy()->addWeeks(3)->toDateString(), 'frequency' => 'once'], $acc);

        $TS::daily(); // clear the banked cheque, pick up cheques presented in the reconciled statement
        Auth::setUser($prevUser ?? $admin);
    }

    /** v5.8 owner reporting: HMA from the first closed month, reserve account funded from Ecobank, fees accrued for the month that is about to be closed, capex pipeline */
    /**
     * v5.9 concierge (Accra): five licensed operators, seven activities (city tour twice daily, Jamestown walk, Cape Coast / Elmina / Kakum
     * day trip, private Aburi trip on request, surf lessons, Friday highlife night, weekend Shai Hills) and the local guide. Last week's
     * pay-the-vendor bookings completed (one no-show) and Jamestown's commission settled into Ecobank; today's morning city tour done
     * and charged to the room; tomorrow's tours confirmed; a day trip still waiting for the operator; a full weekend departure with a
     * waitlist; a portal request; a free and a late (fee) cancellation; a surf session cancelled by the school for the swell.
     */
    /** v6.0 learning & development: courses and the training matrix, session history, an overdue session awaiting results, upcoming refreshers, SOP library with versions and sign-offs */
    protected function seedDigital(bool $rich): void
    {
        \App\Services\DigitalMarketingService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        $efua = $u('marketing') ?? $u('admin');
        $kojo = $u('digital') ?? $efua;
        $B = bdate()->copy()->startOfDay();
        mt_srand(62);

        // ── channels: social profiles, listings, ad accounts
        $ch = [];
        foreach ([
            ['Facebook – Nowills Grand Hotel', 'facebook', 'NowillsGrandAccra', 'https://www.facebook.com/NowillsGrandAccra', 24800],
            ['Instagram @nowillsgrand', 'instagram', '@nowillsgrand', 'https://www.instagram.com/nowillsgrand', 18450],
            ['LinkedIn – Nowills Grand', 'linkedin', 'nowills-grand-hotel', 'https://www.linkedin.com/company/nowills-grand-hotel', 5120],
            ['TikTok @nowillsgrand', 'tiktok', '@nowillsgrand', 'https://www.tiktok.com/@nowillsgrand', 9300],
            ['X @NowillsGrand', 'x', '@NowillsGrand', 'https://x.com/NowillsGrand', 3050],
        ] as [$n, $p, $h, $url, $f]) {
            $ch[$p] = Models\DmChannel::create(['name' => $n, 'platform' => $p, 'handle' => $h, 'url' => $url, 'followers' => $f, 'owner_id' => $kojo->id, 'is_active' => true]);
        }
        $all = array_fill_keys(array_keys(\App\Models\DmChannel::HEALTH_ITEMS), true);
        foreach ([
            ['Google Business Profile', 'google_business', 4.4, 812, 20, ['rate_parity' => false]],
            ['Tripadvisor', 'tripadvisor', 4.5, 640, 120, ['photos_recent' => false, 'reviews_answered' => false, 'rate_parity' => false]],
            ['Booking.com property page', 'booking_com', 8.7, 1930, null, []],
            ['Hotel website', 'website', null, null, 10, []],
        ] as [$n, $p, $r, $rv, $age, $off]) {
            $ch[$p] = Models\DmChannel::create(['name' => $n, 'platform' => $p, 'rating' => $r, 'reviews' => $rv, 'owner_id' => $efua->id, 'is_active' => true,
                'health' => $age !== null ? array_merge($all, $off) : null, 'audited_at' => $age !== null ? now()->subDays($age) : null, 'audited_by' => $age !== null ? $efua->id : null]);
        }
        $ch['google_ads'] = Models\DmChannel::create(['name' => 'Google Ads', 'platform' => 'google_ads', 'handle' => '482-117-9031', 'owner_id' => $efua->id]);
        $ch['meta_ads'] = Models\DmChannel::create(['name' => 'Meta Ads', 'platform' => 'meta_ads', 'handle' => 'act_2210457788', 'owner_id' => $efua->id]);

        // six months of figures for the social profiles (growing towards today's follower counts)
        foreach (['facebook' => [0.012, 0.9, 0.035], 'instagram' => [0.03, 1.6, 0.062], 'linkedin' => [0.02, 0.8, 0.041], 'tiktok' => [0.07, 4.2, 0.083], 'x' => [0.005, 0.5, 0.018]] as $p => [$g, $reachX, $er]) {
            $c = $ch[$p];
            for ($k = 5; $k >= 0; $k--) {
                $m = $B->copy()->startOfMonth()->subMonthsNoOverflow($k);
                $f = (int) round($c->followers / pow(1 + $g, $k));
                $reach = (int) round($f * $reachX * (0.85 + mt_rand(0, 30) / 100));
                Models\DmChannelStat::create(['channel_id' => $c->id, 'month' => $m->toDateString(), 'followers' => $f, 'reach' => $reach, 'impressions' => (int) round($reach * 1.7),
                    'engagements' => (int) round($reach * $er), 'clicks' => (int) round($reach * 0.006)]);
            }
        }

        // ── paid campaigns
        $promo = fn (string $code) => Models\PromoCode::where('code', $code)->value('id');
        $mk = fn (array $x) => Models\DmCampaign::create($x + ['number' => \App\Support\Numbering::next('dm_campaign'), 'owner_id' => $efua->id]);
        $brand = $mk(['name' => 'Always-on brand search', 'objective' => 'bookings', 'channel_id' => $ch['google_ads']->id, 'utm_campaign' => 'brand-search', 'start_date' => $B->copy()->startOfYear()->toDateString(),
            'budget' => 48000, 'target_roas' => 6, 'status' => 'active', 'notes' => 'Exact + phrase match on the hotel name and “hotel near Kotoka airport”; sitelinks to rooms, Sunday brunch and the booking engine.']);
        $detty = $mk(['name' => 'Detty December 2026 – early bird', 'objective' => 'bookings', 'channel_id' => $ch['meta_ads']->id, 'utm_campaign' => 'detty-december-2026', 'promo_code_id' => $promo('FESTIVE12'),
            'start_date' => $B->copy()->subDays(14)->toDateString(), 'end_date' => $B->copy()->startOfYear()->addMonths(11)->endOfMonth()->toDateString(), 'budget' => 36000, 'target_roas' => 5, 'status' => 'active',
            'notes' => 'Diaspora UK / US / Canada 25–45, interests Afrobeats & travel to Ghana; carousel of rooftop, pool and Afronation week packages.']);
        $diaspora = $mk(['name' => 'Summer diaspora retargeting', 'objective' => 'bookings', 'channel_id' => $ch['meta_ads']->id, 'utm_campaign' => 'diaspora-summer-2026',
            'start_date' => $B->copy()->startOfYear()->addMonths(5)->toDateString(), 'end_date' => $B->copy()->startOfYear()->addMonths(6)->endOfMonth()->toDateString(), 'budget' => 20000, 'target_roas' => 4, 'status' => 'ended']);
        $mk(['name' => 'Wedding season lead generation', 'objective' => 'leads', 'channel_id' => $ch['meta_ads']->id, 'utm_campaign' => 'weddings-2027', 'start_date' => $B->copy()->addDays(10)->toDateString(),
            'budget' => 8000, 'status' => 'draft', 'notes' => 'Lead form for couples planning 2027 weddings – feeds the banquet enquiry desk.']);
        $stat = fn ($c, $d, float $spend, float $ctr, float $cpc, float $conv, float $aov) => Models\DmAdStat::create(['campaign_id' => $c->id, 'date' => $d->toDateString(),
            'clicks' => $cl = max(1, (int) round($spend / $cpc)), 'impressions' => (int) round($cl * 100 / $ctr), 'spend' => round($spend, 2), 'conversions' => $cv = max(0, (int) round($cl * $conv + mt_rand(-40, 40) / 100)), 'conversion_value' => round($cv * $aov, 2), 'source' => 'csv']);
        for ($m = 1; $m < $B->month - 1; $m++) { // brand search: one monthly roll-up row per earlier month
            $stat($brand, $B->copy()->startOfYear()->addMonths($m - 1), 3600 + mt_rand(0, 600), 9.5, 2.4, 0.008, 2900);
        }
        for ($d = $B->copy()->subMonthNoOverflow()->startOfMonth(); $d->lt($B); $d->addDay()) {
            $stat($brand, $d->copy(), 120 + mt_rand(0, 40), 10.5, 2.3, 0.009, 3100);
        }
        for ($d = $B->copy()->subDays(14); $d->lt($B); $d->addDay()) {
            $stat($detty, $d->copy(), 800 + mt_rand(0, 250), 1.4, 1.1, 0.0016, 7800);
        }
        for ($d = Carbon::parse($diaspora->start_date); $d->lte($diaspora->end_date); $d->addDays(7)) {
            $stat($diaspora, $d->copy(), 2300 + mt_rand(0, 300), 1.1, 1.3, 0.0016, 6400);
        }

        // ── tracked links + click log
        $book = route('booking.show', hotel()->code);
        $links = [];
        foreach ([
            ['ig-bio', 'Instagram bio – book direct', $book, 'instagram', 'social', 'always-on', null, 'bio', 64, 30, $kojo],
            ['gbp-website', 'Google Business – website button', $book, 'google', 'listing', 'always-on', null, 'gbp', 41, 30, $efua],
            ['brand-search', 'Brand search ad – booking engine', $book, 'google', 'cpc', null, $brand, null, 96, 30, $efua],
            ['detty-dec', 'Detty December Meta ad', $book.'?promo=FESTIVE12', 'facebook', 'paid_social', null, $detty, 'carousel', 128, 14, $efua],
            ['adwoa', 'Adwoa Travels – story link', $book, 'instagram', 'influencer', 'creator-adwoa', null, null, 37, 45, $kojo],
            ['lobby-qr', 'Lobby & room-directory QR', $book, 'print', 'qr', 'always-on', null, 'lobby', 12, 30, $kojo],
            ['diaspora-ad', 'Summer diaspora retargeting ad', $book, 'facebook', 'paid_social', null, $diaspora, null, 70, -1, $efua],
        ] as [$code, $name, $dest, $src, $med, $camp, $campaign, $content, $n, $days, $by]) {
            $l = Models\DmLink::create(['code' => $code, 'name' => $name, 'destination' => $dest, 'utm_source' => $src, 'utm_medium' => $med, 'utm_campaign' => $camp ?? $campaign->utm_campaign,
                'utm_content' => $content, 'campaign_id' => $campaign?->id, 'is_active' => $code !== 'diaspora-ad', 'created_by' => $by->id]);
            $last = null;
            for ($i = 0; $i < $n; $i++) {
                $at = $days > 0 ? now()->subMinutes(mt_rand(30, $days * 1440)) : Carbon::parse($diaspora->start_date)->addMinutes(mt_rand(0, 60 * 1440));
                $dev = ['mobile', 'mobile', 'mobile', 'desktop', 'tablet'][mt_rand(0, 4)];
                $ref = ['instagram' => 'l.instagram.com', 'google' => 'www.google.com', 'facebook' => 'm.facebook.com', 'print' => null][$src] ?? null;
                Models\DmLinkClick::create(['link_id' => $l->id, 'clicked_at' => $at, 'device' => $dev, 'referrer_host' => mt_rand(0, 4) ? $ref : null, 'ip_hash' => hash('sha256', 'seed'.$code.$i)]);
                $last = ! $last || $at->gt($last) ? $at : $last;
            }
            $l->update(['clicks' => $n, 'last_click_at' => $last]);
            $links[$code] = $l;
        }
        // credit recent booking-engine bookings to the links (attribution normally happens on the booking engine)
        $recent = Models\Reservation::whereHas('source', fn ($q) => $q->where('type', 'booking_engine'))->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays(75))->whereNull('campaign_id')->orderBy('id')->limit(12)->get();
        $i = 0;
        foreach ($recent as $r) {
            $pick = $r->created_at->gte($B->copy()->subDays(14)) ? ['detty-dec', 'brand-search', 'ig-bio'][$i % 3] : ['brand-search', 'ig-bio', 'adwoa', 'gbp-website'][$i % 4];
            Models\Reservation::whereKey($r->id)->update(['dm_link_id' => $links[$pick]->id]);
            $i++;
        }

        // ── content calendar: published with results, approved, awaiting approval, draft, returned, one missed slot
        $soc = fn (string ...$p) => array_map(fn ($x) => $ch[$x]->id, $p);
        $post = fn (array $x) => Models\DmPost::create($x + ['author_id' => $kojo->id]);
        foreach ([
            [-27, '19:00', 'Rooftop sundowner reel', 'Golden hour on the Sky Bar ☀️🍹 Accra’s best sunset view, every evening from 5pm. #Accra #SkyBar #NowillsGrand', ['instagram', 'tiktok'], 'food', 14200, 1180, 96],
            [-23, '12:30', 'Meet our Executive Chef', 'Chef Mani brings 20 years of pan-African and Indian kitchens to our new tasting menu. Reserve your table this Friday. #MeetTheTeam', ['facebook', 'instagram', 'linkedin'], 'people', 9800, 640, 41],
            [-19, '18:00', 'Independence Day long weekend offer', 'Stay 3 nights, pay 2 this 21 September long weekend 🇬🇭 Book direct with code HARVEST15. Link in bio.', ['facebook', 'instagram', 'x'], 'offers', 21500, 1420, 310],
            [-15, '10:00', 'Corporate meetings package', 'Hybrid-ready meeting rooms 5 minutes from Kotoka airport. Full-day delegate package from GH₵ 650. Enquire today.', ['linkedin', 'facebook'], 'events', 6100, 210, 88],
            [-11, '20:00', 'Afrobeats pool party recap', 'What a night! Thank you Accra 💃🏾 Next pool party: 25 October. #AccraNightlife', ['instagram', 'tiktok', 'facebook'], 'events', 38600, 4210, 205],
            [-8, '13:00', 'Guest story: the Asante family reunion', 'Four generations, one weekend. Thank you for letting us host your homecoming ❤️ #YearOfReturn #DiasporaHome', ['facebook', 'instagram'], 'ugc', 17300, 1960, 64],
            [-5, '09:00', 'Sunday jollof brunch', 'Live band, jollof station and bottomless zobo every Sunday from 11am. Families welcome 🍛', ['instagram', 'facebook'], 'food', 11900, 870, 57],
            [-2, '18:30', 'Detty December is coming', 'December in Accra hits different 🔥 Early-bird rooms for Detty December – 12% off with FESTIVE12 until 31 October.', ['instagram', 'tiktok', 'facebook', 'x'], 'offers', 26800, 2310, 402],
        ] as [$d, $t, $title, $cap, $on, $pillar, $reach, $eng, $clk]) {
            $at = Carbon::parse($B->copy()->addDays($d)->toDateString().' '.$t);
            $post(['title' => $title, 'caption' => $cap, 'channels' => $soc(...$on), 'pillar' => $pillar, 'scheduled_at' => $at, 'status' => 'published', 'submitted_at' => $at->copy()->subDays(3),
                'reviewer_id' => $efua->id, 'reviewed_at' => $at->copy()->subDays(2), 'published_at' => $at->copy()->addMinutes(4), 'post_url' => 'https://www.instagram.com/p/seed'.abs($d),
                'reach' => $reach, 'engagements' => $eng, 'clicks' => $clk, 'campaign_id' => $pillar === 'offers' && $d > -5 ? $detty->id : null, 'link_id' => $clk > 200 ? $links['ig-bio']->id : null]);
        }
        $post(['title' => 'Weekend brunch reminder', 'caption' => 'Brunch is back tomorrow from 11am – live highlife band and the famous jollof station. Book a table via the link in bio.', 'channels' => $soc('instagram', 'facebook'), 'pillar' => 'food',
            'scheduled_at' => Carbon::parse($B->copy()->subDay()->toDateString().' 19:00'), 'status' => 'approved', 'submitted_at' => $B->copy()->subDays(4), 'reviewer_id' => $efua->id, 'reviewed_at' => $B->copy()->subDays(3)]);
        foreach ([[1, '18:00', 'Detty December room reveal', 'Presidential suite tour 👑 Swipe to see the view you’ll wake up to this December. Early-bird code FESTIVE12.', ['instagram', 'tiktok'], 'offers', $detty->id, $links['detty-dec']->id],
            [3, '12:00', 'World Tourism Day – why Ghana', 'Happy World Tourism Day! Five reasons 2026 is the year to visit Ghana 🇬🇭 (thread)', ['facebook', 'linkedin'], 'culture', null, null],
            [6, '19:00', 'Chef’s table launch', 'Eight courses, one table, twelve guests. Our Chef’s Table opens 10 October – reservations now open.', ['instagram', 'facebook'], 'food', null, null]] as [$d, $t, $title, $cap, $on, $pillar, $camp, $link]) {
            $post(['title' => $title, 'caption' => $cap, 'channels' => $soc(...$on), 'pillar' => $pillar, 'campaign_id' => $camp, 'link_id' => $link, 'scheduled_at' => Carbon::parse($B->copy()->addDays($d)->toDateString().' '.$t),
                'status' => 'approved', 'submitted_at' => $B->copy()->subDays(2), 'reviewer_id' => $efua->id, 'reviewed_at' => $B->copy()->subDay()]);
        }
        foreach ([[2, '17:00', 'Staff spotlight: Grace from the spa', 'Meet Grace – our lead therapist and the reason guests keep coming back for the shea-butter massage 💆🏾‍♀️ #MeetTheTeam', ['instagram', 'facebook'], 'people'],
            [4, '11:00', 'Airport shuttle explainer', 'Landing at Kotoka? Our free shuttle runs every 30 minutes, 24/7. Here’s how to find us in arrivals ✈️', ['instagram', 'x', 'facebook'], 'rooms']] as [$d, $t, $title, $cap, $on, $pillar]) {
            $post(['title' => $title, 'caption' => $cap, 'channels' => $soc(...$on), 'pillar' => $pillar, 'scheduled_at' => Carbon::parse($B->copy()->addDays($d)->toDateString().' '.$t), 'status' => 'review', 'submitted_at' => now()->subHours(5)]);
        }
        $post(['title' => 'Pool party #2 announcement', 'caption' => 'Round two 🔥 25 October, DJ line-up drops Friday. Tickets at reception.', 'channels' => $soc('instagram', 'tiktok'), 'pillar' => 'events',
            'scheduled_at' => Carbon::parse($B->copy()->addDays(8)->toDateString().' 18:00'), 'status' => 'draft']);
        $post(['title' => 'Rate comparison graphic', 'caption' => 'Why pay more on the OTAs? Book direct and save up to 15% 👇', 'channels' => $soc('facebook', 'instagram'), 'pillar' => 'offers',
            'scheduled_at' => Carbon::parse($B->copy()->addDays(5)->toDateString().' 16:00'), 'status' => 'rejected', 'submitted_at' => $B->copy()->subDays(2), 'reviewer_id' => $efua->id, 'reviewed_at' => $B->copy()->subDay(),
            'review_note' => 'Don’t name or show OTA prices (parity clauses). Focus on book-direct perks: free breakfast, late check-out, airport shuttle.']);

        // ── influencer collaborations
        $inf = fn (array $x) => Models\DmInfluencer::create($x + ['number' => \App\Support\Numbering::next('dm_influencer'), 'owner_id' => $kojo->id]);
        $inf(['name' => 'Adwoa Travels', 'platform' => 'instagram', 'handle' => '@adwoatravels', 'followers' => 48200, 'niche' => 'Travel & lifestyle', 'email' => 'hello@adwoatravels.example',
            'stay_from' => $B->copy()->subDays(40)->toDateString(), 'stay_to' => $B->copy()->subDays(37)->toDateString(), 'due_date' => $B->copy()->subDays(30)->toDateString(),
            'deliverables' => "2 Instagram reels, 10 stories with the link sticker, 1 blog post\nUsage rights: 12 months organic", 'fee' => 3500, 'comp_value' => 6800, 'status' => 'delivered',
            'content_urls' => "https://www.instagram.com/reel/adwoa-nowills-1\nhttps://www.instagram.com/reel/adwoa-nowills-2\nhttps://adwoatravels.example/accra-staycation", 'reach' => 61400, 'engagements' => 5870, 'rating' => 5, 'link_id' => $links['adwoa']->id]);
        $inf(['name' => 'Kwame Eats Accra', 'platform' => 'tiktok', 'handle' => '@kwameeatsaccra', 'followers' => 112000, 'niche' => 'Food', 'phone' => '+233 24 555 0192',
            'stay_from' => $B->copy()->subDays(6)->toDateString(), 'stay_to' => $B->copy()->subDays(5)->toDateString(), 'due_date' => $B->copy()->subDay()->toDateString(),
            'deliverables' => '3 TikToks (Sunday brunch, Chef’s tasting menu, Sky Bar cocktails)', 'fee' => 5000, 'comp_value' => 2400, 'status' => 'hosted']);
        $inf(['name' => 'Diaspora Diaries', 'platform' => 'youtube', 'handle' => '@diasporadiaries', 'followers' => 76500, 'niche' => 'Heritage travel', 'email' => 'collabs@diasporadiaries.example',
            'stay_from' => $B->copy()->addDays(12)->toDateString(), 'stay_to' => $B->copy()->addDays(15)->toDateString(), 'due_date' => $B->copy()->addDays(30)->toDateString(),
            'deliverables' => '1 long-form YouTube vlog (Accra homecoming), 3 Shorts, community post with the booking link', 'fee' => 8000, 'comp_value' => 9600, 'status' => 'agreed']);
        $inf(['name' => 'Accra Wedding Blog', 'platform' => 'instagram', 'handle' => '@accraweddingblog', 'followers' => 22300, 'niche' => 'Weddings', 'status' => 'proposed',
            'notes' => 'Styled shoot in the ballroom to launch the 2027 wedding packages – awaiting their rate card.']);
    }

    /** v6.3 demo: a six-hotel competitive set with a fortnight of rate shops, six months of benchmarks, partnerships in every state and initiatives across the stage gate. */
    protected function seedBusinessDev(bool $rich): void
    {
        $BD = \App\Services\BusinessDevService::class;
        $BD::ensureDefaults();
        if (! $rich) {
            return;
        }
        $u = fn (string $n) => Models\User::where('hotel_id', \App\Support\Tenant::id())->where('username', $n)->first();
        $admin = $u('admin');
        $ak = $u('bizdev') ?? $admin;
        $efua = $u('marketing') ?? $admin;
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();
        mt_srand(63);
        $prevUser = Auth::user();
        Auth::setUser($ak);

        // ── competitive set
        $comps = [];
        foreach ([
            ['Kempinski Hotel Gold Coast City', 5, 'luxury', 269, 'Ridge, Accra', 1.8, 'https://www.kempinski.com/en/accra', 5400, 'Brand, ballroom (1,000 pax), spa; the corporate first choice.', 'Premium pricing; weekend occupancy soft.'],
            ['Mövenpick Ambassador Hotel Accra', 5, 'luxury', 260, 'Ridge, Accra', 1.2, 'https://movenpick.accor.com/accra', 4600, 'Accor loyalty (ALL), large pool & gardens, embassy business.', 'Ageing rooms in the old wing.'],
            ['Accra Marriott Hotel', 5, 'upscale', 204, 'Airport City, Accra', 6.5, 'https://www.marriott.com/accmc', 4200, 'Marriott Bonvoy, next to the airport, crew contracts.', 'Far from the CBD; limited F&B.'],
            ['Labadi Beach Hotel', 5, 'upscale', 164, 'Labadi, Accra', 7.0, 'https://www.labadibeachhotel.com', 3800, 'Only beachfront 5★, leisure & weddings.', 'Tired product, no brand distribution.'],
            ['Alisa Hotel North Ridge', 4, 'midscale', 180, 'North Ridge, Accra', 0.9, 'https://www.alisahotels.com', 2700, 'Local favourite for government & NGO travel, sharp pricing.', 'Few suites, dated meeting rooms.'],
            ['Golden Tulip Accra', 4, 'midscale', 225, 'Airport Residential, Accra', 5.1, 'https://accra.goldentulip.com', 2500, 'Price leader, big conference centre.', 'Weak online reviews (3.9).'],
        ] as $k => [$n, $st, $pos, $rooms, $loc, $km, $web, $base, $s, $w]) {
            $comps[] = [$BD::saveCompetitor(['name' => $n, 'stars' => $st, 'positioning' => $pos, 'rooms' => $rooms, 'location' => $loc, 'distance_km' => $km, 'website' => $web,
                'strengths' => $s, 'weaknesses' => $w, 'sort' => $k + 1], null, $admin), $base, $pos];
        }
        $BD::saveCompetitor(['name' => 'Fiesta Royale Hotel', 'stars' => 4, 'positioning' => 'midscale', 'rooms' => 150, 'location' => 'Dzorwulu, Accra', 'distance_km' => 6.8,
            'weaknesses' => 'Dropped from the set in 2026 – different customer.', 'is_active' => false, 'sort' => 7], null, $admin);

        // ── rate shops: the next 14 days on the hotels' own websites, the next 7 on Booking.com (OTA ~5% over brand.com)
        foreach (['brand_site' => [14, 1.0], 'booking_com' => [7, 1.05]] as $ch => [$days, $uplift]) {
            for ($i = 0; $i < $days; $i++) {
                $day = $B->copy()->addDays($i);
                $we = in_array($day->dayOfWeekIso, [5, 6], true);
                $rates = $sold = [];
                foreach ($comps as [$c, $base, $pos]) {
                    if ($c->name === 'Kempinski Hotel Gold Coast City' && in_array($i, [3, 4], true)) {
                        $sold[$c->id] = 1;   // a conference fills Kempinski mid-week
                        continue;
                    }
                    $f = $we ? (in_array($pos, ['luxury'], true) || $c->name === 'Accra Marriott Hotel' ? 0.9 : 1.08) : 1.0;   // business hotels discount weekends, leisure hotels charge more
                    $rates[$c->id] = (string) (round($base * $f * $uplift * (1 + mt_rand(-40, 40) / 1000) / 10) * 10);
                }
                $BD::saveShop(['stay_date' => $day->toDateString(), 'channel' => $ch, 'rates' => $rates, 'sold_out' => $sold], $ak);
            }
        }

        // ── six months of STR-style comp-set benchmarks
        for ($k = 6; $k >= 1; $k--) {
            $m = $B->copy()->startOfMonth()->subMonthsNoOverflow($k);
            $BD::saveBenchmark(['month' => $m->format('Y-m'), 'comp_occupancy' => [68.4, 71.2, 64.9, 61.3, 66.8, 70.5][6 - $k], 'comp_adr' => [3980, 4050, 3890, 3820, 3940, 4080][6 - $k],
                'own_occupancy' => [66.1, 73.9, 60.2, 59.8, 68.4, 74.6][6 - $k], 'own_adr' => [3620, 3710, 3540, 3480, 3590, 3760][6 - $k],
                'source' => $k <= 3 ? 'str' : 'manual', 'note' => $k <= 3 ? 'STR monthly STAR report' : 'Monthly comp-set exchange with the revenue managers'], $ak);
        }

        // ── partnerships
        $awaSrc = Models\BusinessSource::firstOrCreate(['name' => 'Africa World Airlines (crew desk)'], ['type' => 'corporate', 'commission_pct' => 0, 'is_active' => true]);
        $direct = Models\BusinessSource::where('name', 'Direct / Phone')->value('id');
        $ids = Models\Reservation::whereNull('company_id')->where('business_source_id', $direct)->where('booking_type', 'individual')->where('status', '!=', 'cancelled')
            ->where('arrival_date', '>=', $B->copy()->startOfYear()->toDateString())->orderBy('arrival_date')->limit(9)->pluck('id');
        Models\Reservation::whereIn('id', $ids)->update(['business_source_id' => $awaSrc->id]);
        $co = fn (string $n) => Models\Company::where('name', $n)->value('id');
        $promo = fn (string $c) => Models\PromoCode::where('code', $c)->value('id');
        $ptr = function (array $a, string $status, array $extra = []) use ($BD, $ak) {
            $p = $BD::savePartner($a, null, $ak);
            $p->update(['status' => $status] + $extra);

            return $p;
        };
        $awa = $ptr(['name' => 'Africa World Airlines', 'type' => 'airline', 'agreement' => 'preferred', 'contact_name' => 'Esi Quansah', 'email' => 'crew.planning@awa.example.com', 'phone' => '+233 30 274 0110',
            'business_source_id' => $awaSrc->id, 'start_date' => $B->copy()->subMonths(5)->startOfMonth()->toDateString(), 'end_date' => $B->copy()->addMonths(19)->endOfMonth()->toDateString(),
            'review_date' => $d(40), 'target_nights' => 900, 'target_revenue' => 540000, 'owner_id' => $ak->id,
            'terms' => "Crew rate GH₵ 2,250 (STD, B&B) year-round, 6 rooms allotment released 24 h before arrival.\nAirport transfers included. 10% off AWA passenger bookings with the airline booking reference.\nAWA features the hotel in its in-flight magazine and on the Accra–Lagos route e-mails."], 'active');
        $sank = $ptr(['name' => 'Sankofa Heritage Tours', 'type' => 'tour_operator', 'agreement' => 'commission', 'commission_pct' => 10, 'contact_name' => 'Marcus Johnson', 'email' => 'marcus@sankofatours.example.com',
            'phone' => '+1 404 555 0199', 'company_id' => $co('Sankofa Heritage Tours'), 'start_date' => $B->copy()->subYear()->addDays(45)->toDateString(), 'end_date' => $d(45),
            'target_nights' => 60, 'target_revenue' => 250000, 'owner_id' => $efua->id,
            'terms' => "10% commission on room revenue, paid quarterly. Group rate 15% below BAR for 10+ rooms.\nFam trip for 6 US agents each year. Renewal for 2027 under negotiation (heritage tour series)."], 'active');
        $ptr(['name' => 'Stanbic Bank Ghana – cardholder offers', 'type' => 'bank', 'agreement' => 'co_marketing', 'contact_name' => 'Kofi Adjei', 'email' => 'kofi.adjei@stanbic.example.com',
            'company_id' => $co('Stanbic Bank Ghana'), 'start_date' => $B->copy()->startOfYear()->toDateString(), 'end_date' => $B->copy()->addYear()->endOfYear()->toDateString(),
            'review_date' => $d(-5), 'target_revenue' => 120000, 'owner_id' => $ak->id,
            'terms' => "15% off BAR and a free spa treatment for Stanbic Visa Infinite cardholders.\nThe bank promotes the offer in its app and statement inserts; the hotel hosts the bank's quarterly client evening at cost."], 'active');
        $ptr(['name' => 'Ghana Tourism Authority – See Ghana campaign', 'type' => 'government', 'agreement' => 'mou', 'contact_name' => 'Nana Ama Boateng', 'email' => 'partnerships@gta.example.gov.gh',
            'start_date' => $B->copy()->subYear()->toDateString(), 'end_date' => $d(-10), 'owner_id' => $ak->id,
            'terms' => 'Listed as an official partner hotel for the See Ghana / December in GH campaign; hotel offers media rates to GTA-invited journalists.'], 'active');
        $ptr(['name' => 'Tour Operators Union of Ghana (TOUGHA)', 'type' => 'tour_operator', 'agreement' => 'referral', 'commission_pct' => 8, 'contact_name' => 'Kwabena Owusu-Ansah',
            'email' => 'secretariat@tougha.example.org', 'business_source_id' => Models\BusinessSource::where('name', 'Travel Agent')->value('id'), 'review_date' => $d(10), 'target_nights' => 300, 'owner_id' => $ak->id,
            'terms' => 'Proposed: 8% referral fee for member operators, net rates published in the members’ portal.'], 'negotiating');
        $ptr(['name' => 'British High Commission Accra', 'type' => 'embassy', 'agreement' => 'preferred', 'contact_name' => 'Travel & Visits Section', 'owner_id' => $ak->id,
            'notes' => 'Visiting delegations currently use Mövenpick next door – security survey requested.'], 'prospect');
        $ptr(['name' => 'Accra International Marathon', 'type' => 'event_organiser', 'agreement' => 'barter', 'start_date' => $B->copy()->subMonths(10)->toDateString(), 'end_date' => $B->copy()->subMonths(4)->toDateString(),
            'owner_id' => $efua->id, 'terms' => '20 room nights for elite runners in exchange for title-sponsor branding at the finish line.'], 'expired');
        $ptr(['name' => 'Ashesi University – visiting faculty', 'type' => 'education', 'agreement' => 'preferred', 'start_date' => $B->copy()->subMonths(18)->toDateString(), 'end_date' => $B->copy()->addMonths(6)->toDateString(),
            'owner_id' => $ak->id], 'terminated', ['end_reason' => 'University moved visiting faculty to its new on-campus guest house.']);

        // ── initiatives across the stage gate
        $ini = function (array $a, string $stage, array $extra = [], array $ms = []) use ($BD, $ak, $B) {
            $i = $BD::saveInitiative($a + ['owner_id' => $ak->id], null, $ak);
            $i->update(['stage' => $stage] + $extra);
            foreach ($ms as [$t, $due, $done]) {
                Models\BdMilestone::create(['initiative_id' => $i->id, 'title' => $t, 'due_date' => $B->copy()->addDays($due)->toDateString(), 'owner_id' => $i->owner_id,
                    'done_at' => $done ? $B->copy()->addDays($due)->setTime(15, 0) : null, 'done_by' => $done ? $i->owner_id : null]);
            }

            return $i;
        };
        $crew = $ini(['name' => 'Airline crew accommodation – Africa World Airlines', 'category' => 'new_segment', 'target_market' => 'Airline crews', 'partner_id' => $awa->id,
            'description' => "AWA rotates 20–25 crew through Accra daily and was unhappy with its airport hotel. A year-round crew contract fills weekday low-demand STD rooms at a floor rate and brings passenger referrals.\nRisks: displacement of higher-rated business on peak nights – mitigated by the 24-hour allotment release.",
            'investment' => 60000, 'annual_revenue' => 540000, 'annual_cost' => 150000, 'horizon_years' => 3, 'launch_date' => $B->copy()->subMonths(4)->startOfMonth()->toDateString()],
            'live', ['submitted_at' => $B->copy()->subMonths(6), 'decided_by' => $admin->id, 'decided_at' => $B->copy()->subMonths(6)->addDays(2), 'live_date' => $B->copy()->subMonths(4)->startOfMonth()->toDateString()],
            [['Crew rate & allotment agreed', -190, true], ['Blackout-curtain & late-checkout crew floor ready', -170, true], ['Transfers with the transport desk', -160, true]]);
        foreach ([[4, 41200, 11800, 'Ramp-up month'], [3, 46800, 12400, null], [2, 44100, 12100, 'Two rotations cancelled (aircraft AOG)'], [1, 49300, 12900, 'Extra Lagos frequency']] as [$k, $rev, $cost, $note]) {
            $BD::saveResult($crew, ['month' => $B->copy()->startOfMonth()->subMonthsNoOverflow($k)->format('Y-m'), 'revenue' => $rev, 'cost' => $cost, 'note' => $note], $ak);
        }
        $ini(['name' => 'Nigerian business traveller programme', 'category' => 'new_market', 'target_market' => 'Lagos & Abuja corporates', 'description' => "Nigeria is our #2 source market after Ghana. Sales trips to Lagos, a naira-friendly corporate rate and a WhatsApp booking line for travel managers.",
            'investment' => 85000, 'annual_revenue' => 420000, 'annual_cost' => 110000, 'horizon_years' => 3, 'launch_date' => $d(35)],
            'implementation', ['submitted_at' => $B->copy()->subDays(50), 'decided_by' => $admin->id, 'decided_at' => $B->copy()->subDays(47)],
            [['Lagos sales trip (12 accounts)', -20, true], ['Corporate rate card in NGN equivalent', -6, false], ['WhatsApp Business line live', -2, false], ['Launch mailer to 300 travel managers', 20, false]]);
        $ini(['name' => 'Rooftop lounge & bar', 'category' => 'revenue_stream', 'target_market' => 'Hotel guests & Ridge after-work crowd', 'description' => "Convert the unused 7th-floor terrace into a 90-cover sundowner lounge with DJ nights on Fridays. Accra's rooftop bars average GH₵ 55k/week.\nNeeds structural sign-off and a liquor licence variation.",
            'investment' => 950000, 'annual_revenue' => 1450000, 'annual_cost' => 820000, 'horizon_years' => 5, 'launch_date' => $B->copy()->addMonths(5)->toDateString()],
            'business_case', ['submitted_at' => $B->copy()->subDays(3)],
            [['Structural engineer report', 21, false], ['Liquor licence variation filed', 35, false], ['Fit-out tender', 60, false]]);
        $ini(['name' => 'Diaspora heritage packages 2027', 'category' => 'product', 'target_market' => 'African-American & Caribbean diaspora', 'partner_id' => $sank->id,
            'description' => 'Five-night Accra + Cape Coast packages with Sankofa Heritage Tours for the December & Emancipation Day seasons, sold through US tour operators.',
            'investment' => 40000, 'annual_revenue' => 380000, 'annual_cost' => 95000, 'horizon_years' => 3, 'launch_date' => $B->copy()->addMonths(2)->toDateString()],
            'approved', ['submitted_at' => $B->copy()->subDays(12), 'decided_by' => $admin->id, 'decided_at' => $B->copy()->subDays(9), 'decision_note' => 'Approved – keep the allotment to 15 rooms in December.'],
            [['Package contract with Sankofa', 14, false], ['US trade launch webinar', 30, false]]);
        $ini(['name' => 'Co-working day passes', 'category' => 'revenue_stream', 'target_market' => 'Remote workers & NGOs', 'description' => 'Sell the mezzanine business lounge as day passes (desk, Wi-Fi, coffee) Monday–Friday.',
            'investment' => 30000, 'annual_revenue' => 96000, 'annual_cost' => 36000, 'horizon_years' => 3], 'assessment', [], [['Occupancy study of the mezzanine', 12, false]]);
        $ini(['name' => 'MICE packages for pharmaceutical congresses', 'category' => 'new_segment', 'target_market' => 'West African medical associations', 'annual_revenue' => 300000], 'idea');
        $ini(['name' => 'Kumasi satellite property (management contract)', 'category' => 'expansion', 'target_market' => 'Ashanti region', 'description' => 'Take a 60-room Kumasi hotel under a Nowills management contract.',
            'investment' => 250000, 'annual_revenue' => 600000, 'annual_cost' => 520000, 'horizon_years' => 5], 'idea');
        $ini(['name' => 'Wellness retreat weekends', 'category' => 'product', 'target_market' => 'Accra professionals', 'investment' => 180000, 'annual_revenue' => 150000, 'annual_cost' => 120000, 'horizon_years' => 3,
            'description' => 'Yoga & spa weekend packages with an outside wellness brand.'], 'rejected',
            ['decided_by' => $admin->id, 'decided_at' => $B->copy()->subDays(30), 'decision_note' => 'Payback too long; the spa is already full at weekends. Revisit after the spa extension.']);

        $BD::flush();
        Auth::setUser($prevUser ?? $admin);
    }

    protected function seedSales(bool $rich): void
    {
        \App\Services\SalesService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        $efua = $u('marketing') ?? $u('admin');
        $farah = $u('banquet') ?? $efua;
        Auth::setUser($efua);
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();
        $at = fn (int $n, string $t = '10:00') => $B->copy()->addDays($n)->toDateString().' '.$t.':00';

        // ── accounts: profile the existing ones, add Ghanaian prospects and clients
        $co = fn (string $name) => Models\Company::where('name', $name)->first();
        $upd = ['Infosys Technologies' => ['tier' => 'key', 'industry' => 'telecom', 'city' => 'Bengaluru', 'potential_nights' => 900],
            'Tata Consultancy Services' => ['tier' => 'major', 'industry' => 'telecom', 'city' => 'Mumbai', 'potential_nights' => 500],
            'Thomas Cook India' => ['tier' => 'major', 'industry' => 'travel', 'account_manager_id' => $efua->id, 'potential_nights' => 400],
            'Yatra Travels' => ['tier' => 'standard', 'industry' => 'travel']];
        foreach ($upd as $n => $x) {
            $co($n)?->update($x + ['sales_status' => 'active']);
        }
        $new = [
            ['MTN Ghana', 'corporate', 'key', 'prospect', 'telecom', 'Accra', $efua, 1500, 'Largest telco – head office at Ridge. Travel desk books ~120 RN/month across Accra hotels; currently with Kempinski & Mövenpick.'],
            ['Newmont Ghana Gold', 'corporate', 'major', 'prospect', 'mining', 'Accra', $efua, 2400, 'Ahafo & Akyem mines – rotating expatriate crews transit through Accra.'],
            ['Stanbic Bank Ghana', 'corporate', 'major', 'active', 'banking', 'Accra', $efua, 350, 'AGM every November; regional managers’ meetings quarterly.'],
            ['Ghana Cocoa Board', 'corporate', 'key', 'active', 'government', 'Accra', $efua, 800, 'Procurement via tender; LPO required before arrival.'],
            ['Ghana Football Association', 'corporate', 'standard', 'prospect', 'sports', 'Accra', $farah, 300, 'National team camps before AFCON qualifiers.'],
            ['Tullow Oil Ghana', 'corporate', 'major', 'dormant', 'oil_gas', 'Takoradi', $efua, 600, 'Lost the 2026 agreement to Kempinski on price – revisit before the 2027 RFP.'],
            ['Accra Weddings & Events Co.', 'corporate', 'standard', 'prospect', 'events', 'Accra', $farah, 60, 'Wedding planner – brings 6–8 weddings a year.'],
            ['Sankofa Heritage Tours', 'travel_agent', 'standard', 'active', 'travel', 'Cape Coast', null, 700, 'Diaspora heritage tours (Year of Return programmes), mostly US groups.'],
        ];
        foreach ($new as [$name, $type, $tier, $status, $ind, $city, $mgr, $pot, $notes]) {
            Models\Company::firstOrCreate(['name' => $name], ['type' => $type, 'tier' => $tier, 'sales_status' => $status, 'industry' => $ind, 'city' => $city, 'account_manager_id' => $mgr?->id,
                'potential_nights' => $pot, 'sales_notes' => $notes, 'direct_billing' => $status === 'active', 'credit_limit' => $status === 'active' ? 50000 : 0,
                'discount_pct' => $type === 'travel_agent' ? 0 : 10, 'commission_pct' => $type === 'travel_agent' ? 10 : 0, 'credit_days' => 30, 'is_active' => true]);
        }
        $C = fn (string $n) => $co($n);

        // ── contacts (birthdays around the business date for the reminder card)
        $bd = fn (int $days, int $year) => $B->copy()->addDays($days)->setYear($year)->toDateString();
        $contacts = [
            ['MTN Ghana', 'Abena Owusu', 'Head of Travel & Events', 'abena.owusu@mtn.example.com', '+233 24 400 1001', true, true, $bd(5, 1984)],
            ['MTN Ghana', 'Kwame Boateng', 'Procurement Manager', 'kwame.boateng@mtn.example.com', '+233 24 400 1002', false, true, null],
            ['Newmont Ghana Gold', 'Sarah Whitfield', 'Logistics Superintendent', 'sarah.whitfield@newmont.example.com', '+233 20 555 2001', true, true, null],
            ['Newmont Ghana Gold', 'Yaw Asamoah', 'Travel Coordinator', 'yaw.asamoah@newmont.example.com', '+233 20 555 2002', false, false, $bd(11, 1990)],
            ['Stanbic Bank Ghana', 'Esi Mensah', 'Executive Assistant to the CEO', 'esi.mensah@stanbic.example.com', '+233 30 261 0001', true, false, $bd(0, 1988)],
            ['Stanbic Bank Ghana', 'Kofi Adjei', 'Head of Corporate Affairs', 'kofi.adjei@stanbic.example.com', '+233 30 261 0002', false, true, null],
            ['Ghana Cocoa Board', 'Dr. Ama Serwaa', 'Director, Administration', 'ama.serwaa@cocobod.example.gov.gh', '+233 30 266 1212', true, true, null],
            ['Ghana Cocoa Board', 'Emmanuel Tetteh', 'Procurement Officer', 'e.tetteh@cocobod.example.gov.gh', '+233 30 266 1213', false, false, null],
            ['Ghana Football Association', 'Nana Kwasi Agyeman', 'Team Administrator', 'admin@gfa.example.com', '+233 27 700 3001', true, true, null],
            ['Tullow Oil Ghana', 'James Arthur', 'Travel Manager', 'j.arthur@tullow.example.com', '+233 31 202 4001', true, true, null],
            ['Accra Weddings & Events Co.', 'Adwoa Pokuaa', 'Lead Planner', 'adwoa@accraweddings.example.com', '+233 55 123 4567', true, true, null],
            ['Sankofa Heritage Tours', 'Marcus Johnson', 'Programme Director (US)', 'marcus@sankofatours.example.com', '+1 404 555 0199', true, true, null],
            ['Infosys Technologies', 'Priya Nair', 'Global Mobility Lead – Africa', 'priya.nair@infosys.example.com', '+91 80 4116 0000', true, true, null],
            ['Tata Consultancy Services', 'Rahul Mehta', 'Travel Desk Manager', 'rahul.mehta@tcs.example.com', '+91 22 6778 9999', true, false, null],
            ['Thomas Cook India', 'Anita Desai', 'Contracting Manager – Africa', 'anita.desai@thomascook.example.in', '+91 22 4242 7000', true, true, null],
        ];
        $X = [];
        foreach ($contacts as [$cn, $name, $title, $email, $phone, $primary, $dm, $birthday]) {
            $c = $C($cn);
            if ($c) {
                $X[$name] = Models\SalesContact::create(['company_id' => $c->id, 'name' => $name, 'title' => $title, 'email' => $email, 'phone' => $phone, 'is_primary' => $primary, 'decision_maker' => $dm, 'birthday' => $birthday]);
                if ($primary) {
                    $c->update(['contact_person' => $name, 'email' => $c->email ?? $email, 'phone' => $c->phone ?? $phone]);
                }
            }
        }

        // ── marketing calendar
        $mev = function (array $a) use ($efua) {
            return Models\MarketingEvent::create($a + ['number' => \App\Support\Numbering::next('marketing_event'), 'owner_id' => $a['owner_id'] ?? $efua->id, 'created_by' => $efua->id]);
        };
        $wtm = $mev(['name' => 'WTM Africa 2026 – Cape Town', 'type' => 'trade_show', 'segment' => 'travel_trade', 'market' => 'global', 'location' => 'CTICC, Cape Town', 'start_date' => $d(-170), 'end_date' => $d(-168),
            'status' => 'done', 'budget' => 85000, 'actual_cost' => 92300, 'target_leads' => 30, 'leads' => 34, 'objectives' => 'Meet inbound tour operators for West Africa; launch the heritage-tour package.',
            'results' => "34 buyer appointments, 6 follow-up proposals. Sankofa Heritage Tours and two UK operators asked for 2027 contracts. Stand cost over budget (shipping of banners)."]);
        $lagos = $mev(['name' => 'Lagos corporate roadshow', 'type' => 'roadshow', 'segment' => 'corporate', 'market' => 'nigeria', 'location' => 'Victoria Island, Lagos', 'start_date' => $d(-75), 'end_date' => $d(-73),
            'status' => 'done', 'budget' => 40000, 'actual_cost' => 36500, 'target_leads' => 20, 'leads' => 18, 'results' => '18 corporate travel managers met (banks, telcos, oil services). Two RFP invitations.']);
        $blitz = $mev(['name' => 'Airport City & Ridge sales blitz', 'type' => 'sales_blitz', 'segment' => 'corporate', 'market' => 'domestic', 'location' => 'Airport City / Ridge, Accra', 'start_date' => $d(-24), 'end_date' => $d(-22),
            'status' => 'done', 'budget' => 5000, 'actual_cost' => 4200, 'target_leads' => 25, 'leads' => 22, 'results' => '41 offices visited, 22 qualified leads – MTN, Stanbic and COCOBOD asked for proposals.']);
        $mev(['name' => 'PANAFEST & Emancipation Day sponsorship', 'type' => 'sponsorship', 'segment' => 'diaspora', 'market' => 'north_america', 'location' => 'Cape Coast', 'start_date' => $d(-62), 'end_date' => $d(-58),
            'status' => 'done', 'budget' => 60000, 'actual_cost' => 60000, 'target_leads' => 10, 'leads' => 6, 'results' => 'Brand visibility with diaspora groups; 6 tour-leader leads.']);
        $fam = $mev(['name' => 'West African travel agents’ fam trip', 'type' => 'fam_trip', 'segment' => 'travel_trade', 'market' => 'west_africa', 'location' => 'Hotel + Cape Coast excursion', 'start_date' => $d(16), 'end_date' => $d(18),
            'status' => 'confirmed', 'budget' => 30000, 'target_leads' => 12, 'objectives' => 'Host 12 agents from Lagos, Abidjan, Lomé and Dakar; show the new club rooms and banquet hall.']);
        $mev(['name' => 'Christmas & New Year print and radio campaign', 'type' => 'advertising', 'segment' => 'leisure', 'market' => 'domestic', 'location' => 'Joy FM, Daily Graphic', 'start_date' => $d(45), 'end_date' => $d(90),
            'status' => 'planned', 'budget' => 45000, 'target_leads' => 0]);
        $mev(['name' => 'ITB Berlin 2027', 'type' => 'trade_show', 'segment' => 'travel_trade', 'market' => 'uk_europe', 'location' => 'Messe Berlin', 'start_date' => $d(160), 'end_date' => $d(162),
            'status' => 'planned', 'budget' => 120000, 'target_leads' => 40]);

        // ── opportunities in every stage
        $opp = function (array $a) use ($efua, $B) {
            $stage = $a['stage'];
            $o = Models\SalesOpportunity::create(array_diff_key($a, ['age' => 1, 'moved' => 1]) + ['number' => \App\Support\Numbering::next('sales_opportunity'), 'owner_id' => $a['owner_id'] ?? $efua->id,
                'probability' => $a['probability'] ?? \App\Services\SalesService::probability($stage), 'created_by' => $efua->id]);
            $o->forceFill(['created_at' => $B->copy()->subDays($a['age'] ?? 20)->setTime(10, 0), 'stage_changed_at' => $B->copy()->subDays(min($a['age'] ?? 20, $a['moved'] ?? 5))->setTime(11, 0)])->save();

            return $o;
        };
        $o1 = $opp(['company_id' => $C('MTN Ghana')->id, 'sales_contact_id' => $X['Abena Owusu']->id, 'name' => '2027 corporate rate agreement', 'type' => 'corporate', 'source' => 'rfp', 'marketing_event_id' => $blitz->id,
            'stage' => 'negotiation', 'expected_close' => $d(12), 'arrival' => '2027-01-01', 'departure' => '2027-12-31', 'room_nights' => 1200, 'rooms_revenue' => 1560000, 'competitors' => 'Kempinski, Mövenpick', 'age' => 22, 'moved' => 3,
            'notes' => 'They want last-room availability and airport transfers included. Counter-offer at GH¢1,300 BAR less 12%.']);
        $o2 = $opp(['company_id' => $C('MTN Ghana')->id, 'sales_contact_id' => $X['Abena Owusu']->id, 'name' => 'Q1 leadership offsite', 'type' => 'mice', 'source' => 'prospecting', 'stage' => 'qualified',
            'expected_close' => $d(40), 'arrival' => $d(95), 'departure' => $d(98), 'room_nights' => 180, 'rooms_revenue' => 252000, 'fb_revenue' => 120000, 'other_revenue' => 30000, 'age' => 10]);
        $opp(['company_id' => $C('Newmont Ghana Gold')->id, 'sales_contact_id' => $X['Sarah Whitfield']->id, 'name' => 'Rotating crew accommodation Nov–Apr', 'type' => 'long_stay', 'source' => 'referral', 'stage' => 'proposal',
            'expected_close' => $d(20), 'arrival' => $d(35), 'departure' => $d(215), 'room_nights' => 2400, 'rooms_revenue' => 2640000, 'fb_revenue' => 360000, 'competitors' => 'Labadi Beach Hotel', 'age' => 18]);
        $opp(['company_id' => $C('Stanbic Bank Ghana')->id, 'sales_contact_id' => $X['Kofi Adjei']->id, 'name' => 'AGM & shareholders’ dinner', 'type' => 'mice', 'source' => 'repeat', 'marketing_event_id' => $blitz->id, 'stage' => 'proposal',
            'expected_close' => $d(5), 'arrival' => $d(40), 'departure' => $d(41), 'room_nights' => 60, 'rooms_revenue' => 84000, 'fb_revenue' => 150000, 'other_revenue' => 12000, 'age' => 15]);
        $won1 = $opp(['company_id' => $C('Ghana Cocoa Board')->id, 'sales_contact_id' => $X['Dr. Ama Serwaa']->id, 'name' => 'Board retreat', 'type' => 'group', 'source' => 'rfp', 'marketing_event_id' => $blitz->id, 'stage' => 'won',
            'expected_close' => $d(-20), 'closed_on' => $d(-20), 'arrival' => $d(30), 'departure' => $d(33), 'room_nights' => 90, 'rooms_revenue' => 126000, 'fb_revenue' => 60000, 'age' => 30, 'moved' => 20]);
        $opp(['company_id' => $C('Ghana Cocoa Board')->id, 'sales_contact_id' => $X['Dr. Ama Serwaa']->id, 'name' => '2026/27 corporate rate agreement', 'type' => 'corporate', 'source' => 'repeat', 'stage' => 'won',
            'expected_close' => $d(-12), 'closed_on' => $d(-12), 'arrival' => $d(2), 'departure' => $d(366), 'room_nights' => 600, 'rooms_revenue' => 720000, 'age' => 45, 'moved' => 12]);
        $opp(['company_id' => $C('Ghana Football Association')->id, 'sales_contact_id' => $X['Nana Kwasi Agyeman']->id, 'owner_id' => $farah->id, 'name' => 'Black Stars pre-qualifier camp', 'type' => 'group', 'source' => 'prospecting',
            'stage' => 'qualified', 'expected_close' => $d(25), 'arrival' => $d(60), 'departure' => $d(70), 'room_nights' => 300, 'rooms_revenue' => 360000, 'fb_revenue' => 90000, 'age' => 12]);
        $opp(['company_id' => $C('Tullow Oil Ghana')->id, 'sales_contact_id' => $X['James Arthur']->id, 'name' => '2026 corporate agreement renewal', 'type' => 'corporate', 'source' => 'repeat', 'stage' => 'lost',
            'expected_close' => $d(-30), 'closed_on' => $d(-30), 'room_nights' => 500, 'rooms_revenue' => 650000, 'lost_reason' => 'competitor', 'lost_note' => 'Kempinski at GH¢1,150 incl. breakfast', 'age' => 70, 'moved' => 30]);
        $opp(['company_id' => $C('Accra Weddings & Events Co.')->id, 'sales_contact_id' => $X['Adwoa Pokuaa']->id, 'owner_id' => $farah->id, 'name' => 'Asante–Mensah wedding', 'type' => 'social', 'source' => 'website', 'stage' => 'prospect',
            'expected_close' => $d(60), 'arrival' => $d(120), 'departure' => $d(122), 'room_nights' => 40, 'rooms_revenue' => 56000, 'fb_revenue' => 180000, 'age' => 4]);
        $opp(['company_id' => $C('Sankofa Heritage Tours')->id, 'sales_contact_id' => $X['Marcus Johnson']->id, 'name' => '2027 heritage tour series contract', 'type' => 'agency', 'source' => 'trade_show', 'marketing_event_id' => $wtm->id,
            'stage' => 'proposal', 'expected_close' => $d(30), 'arrival' => '2027-01-01', 'departure' => '2027-12-31', 'room_nights' => 800, 'rooms_revenue' => 880000, 'age' => 25]);
        $opp(['company_id' => $C('Infosys Technologies')->id, 'sales_contact_id' => $X['Priya Nair']->id, 'name' => 'December delivery-centre delegation', 'type' => 'group', 'source' => 'repeat', 'stage' => 'negotiation',
            'expected_close' => $d(-3), 'arrival' => $d(50), 'departure' => $d(55), 'room_nights' => 120, 'rooms_revenue' => 150000, 'fb_revenue' => 20000, 'age' => 16, 'moved' => 6]);
        $opp(['company_id' => $C('Tata Consultancy Services')->id, 'sales_contact_id' => $X['Rahul Mehta']->id, 'name' => 'Q4 client training programme', 'type' => 'mice', 'source' => 'repeat', 'marketing_event_id' => $lagos->id, 'stage' => 'won',
            'expected_close' => $d(-10), 'closed_on' => $d(-10), 'arrival' => $d(20), 'departure' => $d(24), 'room_nights' => 64, 'rooms_revenue' => 80000, 'fb_revenue' => 36000, 'age' => 35, 'moved' => 10]);
        $opp(['company_id' => $C('Thomas Cook India')->id, 'sales_contact_id' => $X['Anita Desai']->id, 'name' => 'Winter 2026 series', 'type' => 'agency', 'source' => 'agent', 'stage' => 'lost',
            'expected_close' => $d(-60), 'closed_on' => $d(-60), 'room_nights' => 300, 'rooms_revenue' => 330000, 'lost_reason' => 'price', 'lost_note' => 'Wanted net GH¢900', 'age' => 100, 'moved' => 60]);
        $stale = $opp(['company_id' => $C('Yatra Travels')->id, 'name' => 'Ghana fam group for Indian agents', 'type' => 'agency', 'source' => 'agent', 'stage' => 'prospect', 'expected_close' => $d(40),
            'room_nights' => 30, 'rooms_revenue' => 33000, 'age' => 40, 'moved' => 40]);

        // ── sales diary
        $act = function (array $a) use ($efua) {
            return Models\SalesActivity::create($a + ['owner_id' => $a['owner_id'] ?? $efua->id, 'created_by' => $efua->id, 'status' => $a['status'] ?? 'done']);
        };
        $doneRows = [
            [-120, 'visit', 'Thomas Cook India', 'Annual contracting visit', 'Reviewed 2026 production; they will send the winter series request.', 0],
            [-80, 'call', 'Tullow Oil Ghana', 'Renewal pricing call', 'They have a Kempinski offer at 1,150 – asked for our best rate.', 0],
            [-60, 'entertainment', 'Ghana Cocoa Board', 'Lunch with Dr. Serwaa', 'Discussed the board retreat and next year’s agreement.', 850],
            [-45, 'site_inspection', 'Ghana Cocoa Board', 'Site inspection – board retreat', 'Liked the Ocean Hall; asked for a proposal with 3 nights.', 0],
            [-30, 'meeting', 'Tata Consultancy Services', 'Programme scoping (video call)', 'Confirmed 16 delegates × 4 nights and daily training room.', 0],
            [-24, 'visit', 'MTN Ghana', 'Sales blitz – MTN head office', 'Met Abena; RFP for 2027 due in 3 weeks.', 0],
            [-23, 'visit', 'Stanbic Bank Ghana', 'Sales blitz – Stanbic Heights', 'AGM in November – they want a proposal.', 0],
            [-22, 'visit', 'Ghana Football Association', 'Introductory visit', 'Camp before the qualifiers; budget is tight.', 0],
            [-18, 'email', 'Newmont Ghana Gold', 'Crew accommodation proposal sent', 'Sent long-stay rates with laundry & transport.', 0],
            [-15, 'email', 'Stanbic Bank Ghana', 'AGM proposal sent', 'Proposal with dinner menu options.', 0],
            [-12, 'meeting', 'MTN Ghana', 'RFP presentation', 'Shortlisted with Kempinski. Negotiation on rate & LRA.', 0],
            [-10, 'entertainment', 'MTN Ghana', 'Dinner with the travel desk', 'Good rapport; decision expected in 2 weeks.', 1200],
            [-8, 'call', 'Infosys Technologies', 'Delegation dates call', 'Dates may slip a week – awaiting their confirmation.', 0],
            [-6, 'call', 'Sankofa Heritage Tours', 'Contract follow-up', 'Marcus reviewing with the board; wants 12% commission.', 0],
            [-3, 'site_inspection', 'MTN Ghana', 'Site inspection with procurement', 'Kwame toured club rooms; liked the business centre.', 0],
            [-2, 'call', 'Newmont Ghana Gold', 'Proposal follow-up', 'Sarah comparing with Labadi; wants transport included.', 0],
            [-1, 'visit', 'Accra Weddings & Events Co.', 'Wedding venue walk-through', 'Couple loved the garden; sending banquet menus.', 0],
        ];
        $oppOf = fn (string $cn) => Models\SalesOpportunity::where('company_id', $C($cn)->id)->whereIn('stage', \App\Models\SalesOpportunity::OPEN)->orderBy('id')->first();
        $contactOf = fn (string $cn) => Models\SalesContact::where('company_id', $C($cn)->id)->where('is_primary', true)->value('id');
        foreach ($doneRows as [$n, $type, $cn, $subject, $outcome, $cost]) {
            $act(['type' => $type, 'subject' => $subject, 'company_id' => $C($cn)->id, 'sales_contact_id' => $contactOf($cn), 'sales_opportunity_id' => $n > -40 ? $oppOf($cn)?->id : null,
                'owner_id' => in_array($cn, ['Ghana Football Association', 'Accra Weddings & Events Co.']) ? $farah->id : $efua->id,
                'due_at' => $at($n), 'done_at' => $at($n, '11:00'), 'outcome' => $outcome, 'cost' => $cost, 'duration_min' => in_array($type, ['call', 'email']) ? 15 : 60]);
        }
        $planned = [
            [-4, 'call', 'Stanbic Bank Ghana', 'Chase AGM decision', $efua],
            [-2, 'task', 'Infosys Technologies', 'Send revised delegation quote', $efua],
            [0, 'meeting', 'MTN Ghana', 'Final rate negotiation', $efua],
            [0, 'call', 'Ghana Football Association', 'Camp budget call', $farah],
            [2, 'visit', 'Newmont Ghana Gold', 'Visit logistics team with transport plan', $efua],
            [5, 'entertainment', 'Stanbic Bank Ghana', 'Birthday lunch – Esi Mensah', $efua],
            [9, 'site_inspection', 'Accra Weddings & Events Co.', 'Tasting & site visit with the couple', $farah],
            [14, 'call', 'Sankofa Heritage Tours', 'Contract decision call', $efua],
            [21, 'visit', 'Tullow Oil Ghana', 'Win-back visit before the 2027 RFP', $efua],
        ];
        foreach ($planned as [$n, $type, $cn, $subject, $who]) {
            $act(['type' => $type, 'subject' => $subject, 'company_id' => $C($cn)->id, 'sales_contact_id' => $contactOf($cn), 'sales_opportunity_id' => $oppOf($cn)?->id, 'owner_id' => $who->id,
                'due_at' => $at($n, $n < 0 ? '09:30' : '15:00'), 'status' => 'planned', 'duration_min' => 30]);
        }
        $act(['type' => 'call', 'subject' => 'Call re new government travel policy', 'company_id' => $C('Ghana Cocoa Board')->id, 'due_at' => $at(-5), 'status' => 'cancelled', 'outcome' => 'Cancelled: contact on leave']);

        // ── targets (Jul–Dec)
        for ($m = 7; $m <= 12; $m++) {
            Models\SalesTarget::create(['user_id' => $efua->id, 'period' => sprintf('%d-%02d', $B->year, $m), 'room_nights' => 150, 'revenue' => 260000, 'activities' => 20]);
            Models\SalesTarget::create(['user_id' => $farah->id, 'period' => sprintf('%d-%02d', $B->year, $m), 'room_nights' => 30, 'revenue' => 40000, 'activities' => 8]);
        }

        // ── marketing budget & collateral
        $grid = ['trade_show' => [4 => 90000, 11 => 30000], 'roadshow' => [7 => 40000], 'sponsorship' => [7 => 60000], 'fam_trip' => [10 => 30000], 'advertising' => [11 => 25000, 12 => 25000], 'direct_mail' => [1 => 15000]];
        for ($m = 1; $m <= 12; $m++) {
            $grid['sales_blitz'][$m] = 5000;
            $grid['entertainment'][$m] = 4000;
            $grid['digital'][$m] = 12000;
        }
        foreach ($grid as $line => $months) {
            foreach ($months as $m => $amt) {
                Models\MarketingBudget::create(['year' => $B->year, 'month' => $m, 'line' => $line, 'amount' => $amt]);
            }
        }
        foreach ([['Hotel brochure 2026', 'brochure', '2026 v1', '2026-12-31', 'https://nowills.example.com/media/brochure-2026.pdf'],
            ['Corporate rate card 2026/27', 'rate_card', 'v3', $d(90), 'https://nowills.example.com/media/corporate-rates.pdf'],
            ['MICE & banqueting fact sheet', 'fact_sheet', 'v2', null, 'https://nowills.example.com/media/mice-factsheet.pdf'],
            ['Wedding packages 2026', 'menu', '2026', $d(-10), 'https://nowills.example.com/media/weddings.pdf'],
            ['Brochure (French)', 'brochure', '2026 v1', null, 'https://nowills.example.com/media/brochure-fr.pdf', 'fr'],
            ['Sales presentation', 'presentation', 'Q3', null, 'https://nowills.example.com/media/sales-deck.pptx']] as $r) {
            Models\MarketingCollateral::create(['title' => $r[0], 'kind' => $r[1], 'version' => $r[2], 'valid_until' => $r[3], 'url' => $r[4], 'language' => $r[5] ?? 'en', 'uploaded_by' => $efua->id, 'downloads' => 3]);
        }

        // ── production history: link past FIT stays (reservation only – folios untouched) and some future business to the accounts
        $cycle = ['Infosys Technologies', 'Tata Consultancy Services', 'Ghana Cocoa Board', 'Stanbic Bank Ghana', 'Infosys Technologies', 'Thomas Cook India', 'Tata Consultancy Services', 'Sankofa Heritage Tours', 'Ghana Cocoa Board', 'Yatra Travels'];
        $ids = Models\Reservation::whereNull('company_id')->where('status', 'checked_out')->orderBy('id')->limit(30)->pluck('id');
        foreach ($ids as $i => $id) {
            Models\Reservation::whereKey($id)->update(['company_id' => $C($cycle[$i % count($cycle)])->id]);
        }
        $future = Models\Reservation::whereNull('company_id')->where('status', 'confirmed')
            ->whereHas('rooms', fn ($q) => $q->whereBetween('arrival_date', [$d(35), $d(95)]))->orderBy('id')->limit(6)->pluck('id');
        foreach ($future as $i => $id) {
            Models\Reservation::whereKey($id)->update(['company_id' => $C(['Infosys Technologies', 'Stanbic Bank Ghana', 'Ghana Cocoa Board'][$i % 3])->id]);
        }
        \App\Services\SalesService::flush();
        Auth::setUser($prevUser ?? $efua);
    }

    protected function seedLearning(bool $rich): void
    {
        $LS = \App\Services\LearningService::class;
        $LS::ensureDefaults();
        if (! $rich) {
            return;
        }
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        $hr = $u('hr') ?? $u('admin');
        Auth::setUser($hr);
        \App\Models\Setting::updateOrCreate(['key' => 'learning_notify'], ['value' => '0']);   // no SMS while seeding
        \App\Models\Setting::$version++;
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();
        $E = Models\Employee::where('is_active', true)->get()->keyBy('name');
        $e = fn (string $n) => $E[$n] ?? null;
        $dept = fn (string $n) => Models\Department::where('name', $n)->value('id');

        // ── SOP library
        $mk = function (array $a, array $versions, ?string $draft = null) use ($hr, $B) {
            $last = end($versions);
            $doc = Models\SopDocument::create(array_diff_key($a, ['reviewed' => 1]) + ['body' => $draft ?? $last['body'], 'title' => $a['title'], 'owner_id' => $a['owner_id'] ?? $hr->id, 'created_by' => $hr->id, 'status' => 'draft', 'has_draft' => true]);
            foreach ($versions as $i => $v) {
                Models\SopVersion::create(['sop_document_id' => $doc->id, 'version' => $i + 1, 'title' => $a['title'], 'body' => $v['body'], 'change_note' => $v['note'] ?? ($i ? 'Updated' : 'First issue'),
                    'reack' => $v['reack'] ?? true, 'effective_date' => $B->copy()->addDays($v['on'])->toDateString(), 'published_by' => $hr->id, 'published_at' => $B->copy()->addDays($v['on'])->setTime(10, 0)]);
            }
            if ($versions) {
                $reviewed = $B->copy()->addDays($a['reviewed'] ?? $last['on']);
                $doc->forceFill(['version' => count($versions), 'status' => $a['status'] ?? 'published', 'has_draft' => $draft !== null, 'effective_date' => $B->copy()->addDays($last['on'])->toDateString(),
                    'last_reviewed' => $reviewed->toDateString(), 'review_due' => $reviewed->copy()->addMonthsNoOverflow($a['review_months'] ?? 12)->toDateString()])->save();
            }

            return $doc;
        };
        $fireV1 = "## Purpose\nKeep guests and staff safe if a fire breaks out.\n\n## On discovering a fire\n1. Break the nearest **manual call point** to raise the alarm.\n2. Call the switchboard on **0** and the Ghana National Fire Service on **192** (or 112).\n3. Only use an extinguisher if you are trained and the fire is small – never put yourself at risk.\n\n## On hearing the alarm\n1. Stop work, switch off equipment, close doors behind you.\n2. Guide guests to the nearest exit – **never use the lifts**.\n3. Go to the **assembly point: front car park, by the flag poles**.";
        $fireV2 = $fireV1."\n4. Department heads take the **roll call** with the duty roster and the in-house guest list printed from the PMS (*Front Office → In-house*).\n5. Nobody re-enters the building until the Fire Service officer in charge says it is safe.\n\n## Guests who need help\n- Front Office prints the *assisted evacuation* list (guests with mobility or hearing needs) and hands it to the fire marshals.\n- Fire marshals check their floors, then report to the duty manager.\n\n> Fire drills are held every six months. Every employee attends the annual fire-safety training (course **FIRE**).";
        $fire = $mk(['code' => 'SOP-GEN-001', 'title' => 'Fire emergency & evacuation', 'category' => 'emergency', 'summary' => 'What every employee does when the fire alarm sounds – raising the alarm, evacuation, assembly and roll call.', 'requires_ack' => true, 'review_months' => 12],
            [['body' => $fireV1, 'on' => -560], ['body' => $fireV2, 'on' => -200, 'note' => 'Roll call from the PMS in-house list, assisted-evacuation list added', 'reack' => true]]);
        $checkin = $mk(['code' => 'SOP-FO-001', 'title' => 'Guest check-in & identity verification', 'category' => 'guest_service', 'department_id' => $dept('Front Office'), 'summary' => 'Welcome, identity checks (Ghana Card / passport), registration card, payment guarantee and key issue.', 'requires_ack' => true, 'review_months' => 12],
            [['body' => "## Standard\nEvery guest is greeted within **30 seconds** with *\"Akwaaba, welcome to Nowills Grand\"*. Check-in takes no longer than **4 minutes**.\n\n## Steps\n1. Find the reservation in the PMS (name, confirmation number or phone).\n2. Ask for identification: **Ghana Card** for Ghanaian citizens, **passport with visa / entry stamp** for foreign nationals. Scan it to the guest profile.\n3. Foreign nationals: complete the **Form C / immigration** details before the key is issued.\n4. Confirm the room type, rate, meal plan and departure date. Print or e-sign the registration card.\n5. Take the payment guarantee: card pre-authorisation, Mobile Money deposit or approved company billing.\n6. Encode the key card, explain Wi-Fi, breakfast times and the guest portal QR code.\n7. Offer help with luggage and wish the guest a pleasant stay.\n\n## Never\n- Say the room number out loud – write it on the key wallet.\n- Issue a key without identification.", 'on' => -300]]);
        $cashV2 = "## Scope\nAll cashiers at Front Office, the restaurant and the spa.\n\n## During the shift\n1. Open your own shift in the PMS with the counted float.\n2. Every payment is entered at the time it is received – cash, card, Mobile Money (MTN MoMo, Telecel Cash, AirtelTigo Money).\n3. Never keep cash outside the drawer. Drops above **GH₵ 5,000** go to the safe with a drop envelope signed by a witness.\n4. Refunds and voids above **GH₵ 500** need the duty manager's approval in the PMS.\n\n## Closing the shift\n1. Count the drawer by denomination and enter the count **before** looking at the system total (blind count).\n2. Print the shift report and explain any difference over **GH₵ 20** in writing.\n3. Place the cash, the report and card / MoMo settlement slips in the sealed bag; drop it in the safe with a witness.";
        $cash = $mk(['code' => 'SOP-FO-002', 'title' => 'Cash handling & shift close', 'category' => 'finance', 'department_id' => $dept('Front Office'), 'summary' => 'Floats, payments, safe drops, refunds and the blind count at shift close.', 'requires_ack' => true,
            'ack_departments' => [$dept('Front Office'), $dept('Accounts')], 'review_months' => 12, 'reviewed' => -375],
            [['body' => str_replace(['GH₵ 5,000', 'GH₵ 500'], ['GH₵ 3,000', 'GH₵ 300'], $cashV2), 'on' => -700], ['body' => $cashV2, 'on' => -420, 'note' => 'Higher safe-drop and refund limits, blind count introduced', 'reack' => true],
             ['body' => str_replace('AirtelTigo Money', 'AT Money', $cashV2), 'on' => -375, 'note' => 'Mobile Money brand names updated', 'reack' => false]]);
        $room = $mk(['code' => 'SOP-HK-001', 'title' => 'Guest room cleaning standard', 'category' => 'operations', 'department_id' => $dept('Housekeeping'), 'summary' => 'The 12-step departure clean and the daily service clean, with the inspection standard.', 'requires_ack' => true, 'review_months' => 18],
            [['body' => "## Before you enter\nKnock three times and say *\"Housekeeping\"*. Wait 10 seconds. Never enter a room showing **Do Not Disturb** – report it to the supervisor after 14:00.\n\n## Departure clean (target 35 minutes)\n1. Open curtains and windows, switch on all lights and check them.\n2. Remove used linen, towels and rubbish; check for **lost property** and log it in the PMS.\n3. Strip and remake the bed with fresh linen – hospital corners, pillows zipped away from the door.\n4. Clean the bathroom top to bottom with the **blue** cloth and the approved sanitiser; the toilet with the **red** cloth only.\n5. Dust high to low, polish mirrors and glass, wipe the phone, remote and switches.\n6. Restock amenities, minibar (post consumption to the room in the PMS) and water.\n7. Vacuum from the far corner towards the door.\n8. Final check: AC at 24 °C, TV on the welcome channel, curtains half-drawn.\n9. Update the room status on your mobile to **Clean – to inspect**.", 'on' => -150]]);
        $lf = $mk(['code' => 'SOP-HK-002', 'title' => 'Lost & found handling', 'category' => 'operations', 'department_id' => $dept('Housekeeping'), 'summary' => 'Logging, storing, returning and disposing of items found on the premises.', 'requires_ack' => true,
            'ack_departments' => [$dept('Housekeeping'), $dept('Front Office'), $dept('Security')], 'review_months' => 24],
            [['body' => "1. Hand every found item to the housekeeping office **within one hour**; do not keep it in your trolley.\n2. The supervisor logs it in the PMS (*Lost & Found*) with a photo, where it was found and by whom. Valuables (cash, jewellery, phones, passports) go to the **safe** with a witness.\n3. If the owner is known, Front Office contacts them the same day.\n4. Items are returned only against identification; the guest signs the release in the PMS. Courier costs are charged to the guest.\n5. Unclaimed items: perishables after 24 hours, clothing after 3 months, valuables after 6 months – disposed of or donated with the GM's approval.", 'on' => -250]]);
        $allergen = $mk(['code' => 'SOP-FB-001', 'title' => 'Allergen handling & communication', 'category' => 'food_safety', 'department_id' => $dept('Kitchen'), 'summary' => 'Taking allergy orders, preparing allergen-free dishes and communicating with guests.', 'requires_ack' => true,
            'ack_departments' => [$dept('Kitchen'), $dept('F&B Service')], 'review_months' => 12],
            [['body' => "## Service staff\n1. Ask every table: *\"Does anyone have a food allergy or intolerance?\"*\n2. Record the allergen on the POS order – it prints in **red** on the KOT.\n3. Tell the guest about the dish from the allergen matrix; **never guess**. If unsure, ask the chef.\n\n## Kitchen\n1. The chef on the pass confirms every allergen ticket verbally.\n2. Prepare it with clean boards and utensils (**purple** board for allergen-free), on a cleaned surface, with fresh oil.\n3. Carry the dish separately with an allergen flag; the server confirms the allergen with the guest at the table.\n\n> Groundnuts, sesame, shellfish, fish, gluten, milk and eggs are the most common allergens in our menu (Ghanaian dishes often contain groundnut paste).", 'on' => -90]]);
        $keys = $mk(['code' => 'SOP-SEC-001', 'title' => 'Key control & master keys', 'category' => 'security', 'department_id' => $dept('Security'), 'summary' => 'Issue, return and audit of master, sub-master and emergency keys.', 'requires_ack' => true,
            'ack_departments' => [$dept('Security'), $dept('Housekeeping'), $dept('Front Office')], 'review_months' => 12, 'reviewed' => -60],
            [['body' => "1. Master and sub-master key cards are issued only from the security office and signed for in the key log at the start of each shift.\n2. Keys never leave the premises and are never lent to another employee.\n3. A lost master key is reported **immediately** to the duty manager – the key system is re-coded the same day.\n4. Security audits the key log every night during the night audit and reports missing returns.\n5. The emergency key set is sealed in the GM's safe; breaking the seal is recorded in the incident register.", 'on' => -420]]);
        $privacy = $mk(['code' => 'SOP-IT-001', 'title' => 'Guest data protection (Data Protection Act, 2012 – Act 843)', 'category' => 'it_privacy', 'summary' => 'Handling guest personal data, ID copies, payment cards and data subject requests.', 'requires_ack' => true,
            'ack_departments' => [$dept('Front Office'), $dept('Accounts'), $dept('Management')], 'review_months' => 12],
            [['body' => "## Principles\nWe collect only the personal data we need, keep it secure and use it only for the purpose the guest was told about.\n\n## Rules\n1. ID scans and registration cards are stored **only in the PMS** – never on phones, WhatsApp or e-mail.\n2. Never read out or write down full card numbers; card details are entered only on the payment terminal or the secure payment link.\n3. Do not confirm whether a person is staying at the hotel to callers or visitors – take a message instead.\n4. Lock your screen (Windows + L) whenever you leave the desk.\n5. A guest who asks to see, correct or delete their data is referred to the privacy request process (*Guests & CRM → Privacy*); we reply within the legal deadline.\n6. Report any suspected data breach to the GM **the same day** – the Data Protection Commission may need to be notified.", 'on' => -210]]);
        $mk(['code' => 'SOP-MNT-001', 'title' => 'Lock-out / tag-out for electrical work', 'category' => 'safety', 'department_id' => $dept('Maintenance'), 'summary' => 'Isolating equipment before maintenance.', 'requires_ack' => true, 'review_months' => 12], [],
            "## Draft – for review by the Chief Engineer\n1. Tell the users of the equipment that it will be switched off.\n2. Switch off at the local isolator **and** the distribution board.\n3. Fit your personal padlock and a danger tag with your name and the date.\n4. Test that the circuit is dead with an approved voltage tester.\n5. Only the person whose lock is fitted may remove it.");
        $mk(['code' => 'SOP-FO-000', 'title' => 'Manual key register (paper)', 'category' => 'security', 'department_id' => $dept('Front Office'), 'requires_ack' => false, 'review_months' => 12, 'status' => 'retired'],
            [['body' => 'Record every key issued in the paper key register at the front desk.', 'on' => -900]]);

        // ── courses & the training matrix
        $C = fn (array $a) => Models\TrainingCourse::create($a + ['is_active' => true, 'cost_per_head' => 0]);
        $R = fn (Models\TrainingCourse $c, ?string $deptName, ?string $designation, int $due) => Models\TrainingRequirement::create(['training_course_id' => $c->id, 'department_id' => $deptName ? $dept($deptName) : null, 'designation' => $designation, 'due_days' => $due]);
        $ind = $C(['code' => 'IND', 'name' => 'Hotel induction & orientation', 'category' => 'induction', 'delivery' => 'classroom', 'duration_hours' => 4, 'provider' => 'HR – in-house', 'description' => "Our history, values and service standards\nHotel tour, departments and who's who\nHR policies, pay, leave and self-service\nUniform, grooming and conduct\nHealth, safety and emergency basics"]);
        $R($ind, null, null, 7);
        $fireC = $C(['code' => 'FIRE', 'name' => 'Fire safety & evacuation', 'category' => 'fire', 'delivery' => 'classroom', 'duration_hours' => 2, 'validity_months' => 12, 'provider' => 'Ghana National Fire Service – Accra Region', 'cost_per_head' => 80, 'sop_document_id' => $fire->id,
            'description' => "Fire triangle and classes of fire\nUsing extinguishers and fire blankets (practical)\nAlarm, evacuation routes and the assembly point\nRoll call and assisted evacuation"]);
        $R($fireC, null, null, 30);
        $faid = $C(['code' => 'FAID', 'name' => 'First aid at work', 'category' => 'first_aid', 'delivery' => 'external', 'duration_hours' => 16, 'validity_months' => 36, 'pass_mark' => 70, 'provider' => 'Ghana Red Cross Society', 'cost_per_head' => 450,
            'description' => "Primary survey, CPR and the recovery position\nChoking, bleeding, burns and fractures\nSeizures, diabetic and allergic emergencies (EpiPen)\nRecording and reporting incidents"]);
        $R($faid, null, 'Front Office Manager', 60);
        $R($faid, 'Security', null, 60);
        $R($faid, null, 'Fitness Instructor', 90);
        $R($faid, null, 'Spa Manager & Therapist', 60);
        $fsh = $C(['code' => 'FSH', 'name' => 'Food hygiene & safety (HACCP level 2)', 'category' => 'food_safety', 'delivery' => 'classroom', 'duration_hours' => 6, 'validity_months' => 24, 'pass_mark' => 75, 'provider' => 'FDA-accredited food safety trainer', 'cost_per_head' => 250, 'fs_type' => 'hygiene',
            'description' => "Food safety hazards and the law (FDA Ghana)\nPersonal hygiene and fitness to work\nTemperature control, cooling and reheating\nCross-contamination and allergens\nCleaning, pest control and HACCP records"]);
        $R($fsh, 'Kitchen', null, 14);
        $R($fsh, 'F&B Service', null, 14);
        $chem = $C(['code' => 'CHEM', 'name' => 'Safe chemical handling', 'category' => 'health_safety', 'delivery' => 'on_job', 'duration_hours' => 1.5, 'validity_months' => 24, 'provider' => 'Executive Housekeeper / Chief Engineer', 'description' => "Reading safety data sheets and labels\nDilution and the colour-coded system\nPPE: gloves, goggles, masks\nSpills, first aid and storage"]);
        $R($chem, 'Housekeeping', null, 30);
        $R($chem, 'Maintenance', null, 30);
        $gse = $C(['code' => 'GSE', 'name' => 'Guest service excellence – the Akwaaba standard', 'category' => 'service', 'delivery' => 'classroom', 'duration_hours' => 3, 'provider' => 'Front Office Manager', 'description' => "First impressions and the 10-5 rule\nListening, empathy and handling complaints (LEARN)\nUpselling with care\nPersonalising the stay with guest preferences"]);
        $R($gse, 'Front Office', null, 60);
        $R($gse, 'F&B Service', null, 60);
        $R($gse, 'Spa & Wellness', null, 60);
        $dpa = $C(['code' => 'DPA', 'name' => 'Data protection & guest privacy (Act 843)', 'category' => 'compliance', 'delivery' => 'online', 'duration_hours' => 1, 'validity_months' => 12, 'pass_mark' => 80, 'provider' => 'In-house e-learning', 'sop_document_id' => $privacy->id,
            'description' => "The Data Protection Act, 2012 (Act 843) and the Data Protection Commission\nWhat counts as personal data in a hotel\nCard data and ID copies\nPrivacy requests and breach reporting"]);
        $R($dpa, 'Front Office', null, 30);
        $R($dpa, 'Accounts', null, 30);
        $R($dpa, 'Management', null, 30);
        $traf = $C(['code' => 'TRAF', 'name' => 'Human-trafficking awareness for hotel staff', 'category' => 'security', 'delivery' => 'classroom', 'duration_hours' => 1.5, 'validity_months' => 24, 'provider' => 'Ghana Police Service – Anti-Human Trafficking Unit',
            'description' => "Human Trafficking Act, 2005 (Act 694)\nWarning signs at check-in, in rooms and in public areas\nWhat to do – and what not to do\nReporting to the duty manager and the police"]);
        $R($traf, 'Front Office', null, 60);
        $R($traf, 'Security', null, 60);
        $elec = $C(['code' => 'ELEC', 'name' => 'Electrical safety & lock-out / tag-out', 'category' => 'health_safety', 'delivery' => 'on_job', 'duration_hours' => 4, 'validity_months' => 12, 'pass_mark' => 80, 'provider' => 'Chief Engineer']);
        $R($elec, null, 'Electrician', 14);
        $lead = $C(['code' => 'LEAD', 'name' => 'Supervisory skills for hospitality', 'category' => 'leadership', 'delivery' => 'external', 'duration_hours' => 12, 'provider' => 'Ghana Tourism Authority – Hotel Training School', 'cost_per_head' => 2400]);

        // ── history
        $rec = function (Models\TrainingCourse $c, ?Models\Employee $emp, int $on, array $x = []) use ($B) {
            if (! $emp) {
                return null;
            }
            $date = $B->copy()->addDays($on);
            $st = $x['status'] ?? 'passed';

            return Models\TrainingRecord::create(['training_course_id' => $c->id, 'employee_id' => $emp->id, 'status' => $st, 'source' => $x['source'] ?? 'external', 'training_session_id' => $x['session'] ?? null,
                'score' => $x['score'] ?? null, 'completed_on' => $date->toDateString(),
                'expires_on' => $st === 'passed' && $c->validity_months ? $date->copy()->addMonthsNoOverflow($c->validity_months)->subDay()->toDateString() : ($x['expires'] ?? null),
                'certificate_no' => $st === 'passed' ? ($x['cert'] ?? \App\Support\Numbering::next('training_cert')) : null, 'provider' => $x['provider'] ?? $c->provider,
                'cost' => $st === 'no_show' ? 0 : ($x['cost'] ?? (float) $c->cost_per_head), 'notes' => $x['notes'] ?? null, 'recorded_by' => Auth::id()]);
        };
        $sess = function (Models\TrainingCourse $c, int $on, array $x = []) use ($B, $hr) {
            return Models\TrainingSession::create(['number' => \App\Support\Numbering::next('training_session'), 'training_course_id' => $c->id, 'date' => $B->copy()->addDays($on)->toDateString(),
                'start_time' => $x['start'] ?? '09:00', 'end_time' => $x['end'] ?? '11:00', 'venue' => $x['venue'] ?? 'Training Room, 1st floor', 'trainer_name' => $x['trainer'] ?? $c->provider,
                'trainer_id' => $x['trainer_id'] ?? null, 'capacity' => $x['capacity'] ?? 15, 'cost' => $x['cost'] ?? 0, 'status' => $x['status'] ?? 'completed', 'cancel_reason' => $x['cancel'] ?? null,
                'completed_at' => ($x['status'] ?? 'completed') === 'completed' ? $B->copy()->addDays($on)->setTime(12, 0) : null, 'completed_by' => ($x['status'] ?? 'completed') === 'completed' ? $hr->id : null, 'created_by' => $hr->id]);
        };
        // induction for everyone (except the newest starter, who is inside the 7-day window? no – everyone did it on day 2)
        foreach ($E as $emp) {
            if ($emp->joining_date && $emp->joining_date->copy()->addDays(2)->lte($B)) {
                $rec($ind, $emp, (int) $B->diffInDays($emp->joining_date->copy()->addDays(2), false), ['provider' => 'HR – in-house', 'cost' => 0]);
            }
        }
        // fire: two sessions a year – last autumn's group expires in 3–4 weeks, the spring group is current; one lapsed certificate from a previous employer
        $s1 = $sess($fireC, -340, ['cost' => 900, 'trainer' => 'Station Officer K. Addo, GNFS']);
        foreach (['Ramesh Gowda', 'Lata Bai', 'Joseph Thomas', 'Deepa Nair', 'Mohan Lal', 'Sunil Yadav', 'Ravi Kumar', 'Meera Iyer'] as $n) {
            $rec($fireC, $e($n), -340, ['source' => 'session', 'session' => $s1->id, 'cost' => 112.5]);
        }
        $s2 = $sess($fireC, -200, ['cost' => 600, 'trainer' => 'Station Officer K. Addo, GNFS']);
        foreach (['Abena Owusu', 'Grace Mensah', 'Efua Asante', 'Anjali Menon', 'Kiran Patil'] as $n) {
            $rec($fireC, $e($n), -200, ['source' => 'session', 'session' => $s2->id, 'cost' => 120]);
        }
        $rec($fireC, $e('Imran Ali'), -400, ['provider' => 'GNFS (previous employer – Golden Tulip Kumasi)', 'cost' => 0, 'cert' => 'GNFS-KSI-2025-0917']);
        // first aid
        $rec($faid, $e('Ravi Kumar'), -700, ['score' => 84, 'cert' => 'GRCS-FA-24-3310']);
        if ($e('Grace Mensah')) {
            Models\TrainingRecord::create(['training_course_id' => $faid->id, 'employee_id' => $e('Grace Mensah')->id, 'status' => 'exempt', 'source' => 'exempt', 'completed_on' => $d(-120),
                'expires_on' => $d(300), 'notes' => 'Registered nurse (N&MC Ghana) – holds a current clinical first-aid / BLS certificate', 'recorded_by' => $hr->id]);
        }
        // food hygiene (history kept as records only – the food-safety certificates were seeded with v5.0)
        $rec($fsh, $e('Mohan Lal'), -600, ['score' => 91]);
        $rec($fsh, $e('Sunil Yadav'), -690, ['score' => 78]);
        // chemicals
        $rec($chem, $e('Ramesh Gowda'), -100, ['source' => 'external', 'provider' => 'Executive Housekeeper (on the job)', 'cost' => 0]);
        $rec($chem, $e('Lata Bai'), -800, ['provider' => 'Executive Housekeeper (on the job)', 'cost' => 0]);
        $rec($chem, $e('Abena Owusu'), -150, ['provider' => 'Executive Housekeeper (on the job)', 'cost' => 0]);
        $rec($chem, $e('Joseph Thomas'), -300, ['provider' => 'Chief Engineer (on the job)', 'cost' => 0]);
        // service
        foreach (['Deepa Nair' => -180, 'Ravi Kumar' => -500, 'Sunil Yadav' => -160, 'Grace Mensah' => -400] as $n => $on) {
            $rec($gse, $e($n), $on, ['cost' => 0]);
        }
        // data protection – a failed attempt then a pass
        $rec($dpa, $e('Deepa Nair'), -200, ['score' => 92, 'cost' => 0]);
        $rec($dpa, $e('Ravi Kumar'), -380, ['score' => 88, 'cost' => 0]);
        $rec($dpa, $e('Meera Iyer'), -45, ['status' => 'failed', 'score' => 65, 'cost' => 0]);
        $rec($dpa, $e('Meera Iyer'), -40, ['score' => 90, 'cost' => 0]);
        $rec($dpa, $e('Anjali Menon'), -330, ['score' => 95, 'cost' => 0]);
        $rec($elec, $e('Joseph Thomas'), -20, ['score' => 88, 'cost' => 0]);
        $rec($lead, $e('Ravi Kumar'), -120, ['cost' => 2400, 'cert' => 'GTA-HTS-SUP-0412']);
        // cancelled session
        $sess($dpa, -10, ['status' => 'cancelled', 'cancel' => 'Trainer unwell – e-learning module used instead', 'start' => '14:00', 'end' => '15:00']);

        // ── through the service: last week's trafficking-awareness session (one no-show), scheduled and completed
        $traS = $LS::schedule(['training_course_id' => $traf->id, 'date' => $d(-3), 'start_time' => '10:00', 'end_time' => '11:30', 'venue' => 'Conference Room B', 'capacity' => 12, 'cost' => 0,
            'trainer_name' => 'Insp. A. Mensah, AHTU', 'employees' => array_values(array_filter([$e('Deepa Nair')?->id, $e('Ravi Kumar')?->id, $e('Kiran Patil')?->id]))], $hr);
        $LS::complete($traS, [$e('Deepa Nair')?->id => ['status' => 'attended'], $e('Ravi Kumar')?->id => ['status' => 'attended'], $e('Kiran Patil')?->id => ['status' => 'no_show']], $hr);
        // yesterday's service session – not recorded yet (flagged)
        $gseS = $LS::schedule(['training_course_id' => $gse->id, 'date' => $d(-1), 'start_time' => '15:00', 'end_time' => '18:00', 'venue' => 'Training Room, 1st floor', 'capacity' => 10,
            'trainer_id' => $u('fom')?->id, 'employees' => array_values(array_filter([$e('Efua Asante')?->id, $e('Kofi Boateng')?->id]))], $hr);
        // upcoming refreshers
        $fireS = $LS::schedule(['training_course_id' => $fireC->id, 'date' => $d(6), 'start_time' => '09:00', 'end_time' => '11:00', 'venue' => 'Training Room, 1st floor', 'capacity' => 15, 'cost' => 900,
            'trainer_name' => 'Station Officer K. Addo, GNFS', 'employees' => array_values(array_filter([$e('Kofi Boateng')?->id, $e('Imran Ali')?->id, $e('Ramesh Gowda')?->id, $e('Lata Bai')?->id, $e('Deepa Nair')?->id]))], $hr);
        $LS::schedule(['training_course_id' => $fsh->id, 'date' => $d(12), 'start_time' => '08:30', 'end_time' => '15:00', 'venue' => 'Banquet Hall – Ashanti Room', 'capacity' => 10, 'cost' => 250,
            'trainer_name' => 'Mrs. E. Quaye (FDA-accredited)', 'employees' => array_values(array_filter([$e('Sunil Yadav')?->id]))], $hr);

        // ── SOP sign-offs
        $ack = function (Models\SopDocument $doc, array $names, int $version, string $via, int $on) use ($e, $B, $hr) {
            foreach ($names as $n) {
                if ($emp = $e($n)) {
                    Models\SopAcknowledgement::firstOrCreate(['sop_document_id' => $doc->id, 'version' => $version, 'employee_id' => $emp->id],
                        ['via' => $via, 'acknowledged_at' => $B->copy()->addDays($on)->setTime(11, 0), 'recorded_by' => $hr->id]);
                }
            }
        };
        $ack($fire, ['Ramesh Gowda', 'Lata Bai', 'Joseph Thomas', 'Imran Ali', 'Deepa Nair', 'Mohan Lal', 'Sunil Yadav', 'Ravi Kumar', 'Meera Iyer'], 1, 'sheet', -330);
        $ack($fire, ['Abena Owusu', 'Grace Mensah', 'Efua Asante', 'Anjali Menon', 'Kiran Patil'], 2, 'training', -200);
        $ack($fire, ['Ravi Kumar', 'Meera Iyer', 'Deepa Nair', 'Joseph Thomas'], 2, 'self', -150);
        $ack($checkin, ['Deepa Nair', 'Ravi Kumar'], 1, 'self', -290);
        $ack($cash, ['Deepa Nair', 'Ravi Kumar', 'Meera Iyer'], 2, 'self', -410);
        $ack($room, ['Ramesh Gowda', 'Abena Owusu'], 1, 'sheet', -140);
        $ack($lf, ['Ramesh Gowda', 'Lata Bai', 'Abena Owusu', 'Deepa Nair', 'Ravi Kumar', 'Kiran Patil'], 1, 'sheet', -240);
        $ack($allergen, ['Mohan Lal'], 1, 'self', -85);
        $ack($keys, ['Kiran Patil', 'Ramesh Gowda', 'Lata Bai', 'Abena Owusu', 'Deepa Nair', 'Ravi Kumar'], 1, 'sheet', -400);
        $ack($privacy, ['Deepa Nair', 'Ravi Kumar', 'Meera Iyer', 'Anjali Menon'], 1, 'training', -200);

        \App\Models\Setting::updateOrCreate(['key' => 'learning_notify'], ['value' => '1']);
        \App\Models\Setting::$version++;
        $LS::flush();
        $prevUser ? Auth::setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedConcierge(bool $rich): void
    {
        $CS = \App\Services\ConciergeService::class;
        $CS::ensureDefaults();
        \App\Services\ChartOfAccounts::ensure();
        if (! $rich) {
            return;
        }
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        $desk = $u('concierge') ?? $u('fom');
        Auth::setUser($desk);
        $B = bdate()->copy()->startOfDay();
        $d = fn (int $n) => $B->copy()->addDays($n)->toDateString();

        $V = fn (array $a) => Models\ActivityVendor::create($a + ['is_active' => true]);
        $sun = $V(['name' => 'Sunshine Tours Ghana', 'kind' => 'tour_operator', 'contact_name' => 'Operations desk', 'commission_pct' => 15, 'collection' => 'folio', 'licence_expires' => $d(210), 'payout_details' => 'Bank transfer – account details on the vendor file']);
        $kak = $V(['name' => 'Kakum Eco Adventures', 'kind' => 'tour_operator', 'contact_name' => 'Bookings office, Cape Coast', 'commission_pct' => 12, 'collection' => 'folio', 'licence_expires' => $d(150), 'payout_details' => 'Bank transfer']);
        $jam = $V(['name' => 'Jamestown Heritage Walks', 'kind' => 'guide', 'contact_name' => 'Community guide association', 'commission_pct' => 20, 'collection' => 'vendor', 'licence_expires' => $d(95), 'payout_details' => 'MoMo – remits commission weekly']);
        $evt = $V(['name' => 'Accra Events & Tickets', 'kind' => 'events', 'commission_pct' => 10, 'collection' => 'folio', 'payout_details' => 'Bank transfer']);
        $surf = $V(['name' => 'Labadi Surf School', 'kind' => 'water', 'contact_name' => 'Beach hut, Labadi', 'commission_pct' => 10, 'collection' => 'vendor', 'licence_expires' => $d(18), 'notes' => 'Licence renewal due – ask for the new certificate']);

        $A = fn (Models\ActivityVendor $v, array $a) => Models\Activity::create($a + ['activity_vendor_id' => $v->id, 'pricing' => 'person', 'price_child' => 0, 'child_max_age' => 11, 'min_pax' => 1,
            'schedule' => 'scheduled', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'instant' => false, 'cutoff_hours' => 12, 'free_cancel_hours' => 24, 'cancel_fee_pct' => 50, 'portal_visible' => true, 'is_active' => true]);
        $city = $A($sun, ['code' => 'CITY', 'name' => 'Accra City Tour', 'category' => 'tour', 'duration_min' => 240, 'price_adult' => 650, 'price_child' => 325, 'min_pax' => 2, 'capacity' => 12, 'times' => ['09:00', '14:00'], 'instant' => true, 'pickup_included' => true, 'sort_order' => 1,
            'highlights' => 'Independence Square, Kwame Nkrumah Memorial Park, W.E.B. Du Bois Centre, Jamestown lighthouse, Makola Market',
            'description' => 'An air-conditioned minibus tour of Accra with a licensed guide: the Black Star Gate and Independence Square, the Kwame Nkrumah Memorial Park and Mausoleum, the W.E.B. Du Bois Centre, the old colonial quarter of Jamestown and the bustle of Makola Market. Entrance fees included.',
            'bring' => 'Hat, sunscreen, water, cedis for shopping']);
        $jwalk = $A($jam, ['code' => 'JAMES', 'name' => 'Jamestown Heritage Walk', 'category' => 'culture', 'duration_min' => 150, 'price_adult' => 250, 'price_child' => 120, 'capacity' => 15, 'times' => ['10:00', '16:00'], 'cutoff_hours' => 4, 'free_cancel_hours' => 12, 'cancel_fee_pct' => 0, 'sort_order' => 2,
            'meeting_point' => 'Jamestown Lighthouse gate', 'highlights' => 'Lighthouse, Ussher Fort, fishing harbour, boxing gyms and street art with a local guide',
            'description' => 'A walking tour through Ga Mashie with a guide who grew up here – the lighthouse, Ussher Fort, the fishing harbour and the neighbourhood\'s famous boxing gyms. Tips go straight to the community.']);
        $cape = $A($kak, ['code' => 'CAPE', 'name' => 'Cape Coast, Elmina & Kakum Canopy Walk', 'category' => 'daytrip', 'duration_min' => 780, 'price_adult' => 1450, 'price_child' => 900, 'min_pax' => 2, 'capacity' => 10, 'weekdays' => [2, 4, 6], 'times' => ['06:00'], 'cutoff_hours' => 24, 'free_cancel_hours' => 48, 'cancel_fee_pct' => 50, 'pickup_included' => true, 'sort_order' => 3,
            'highlights' => 'Kakum National Park canopy walkway, Cape Coast Castle, Elmina Castle – lunch included',
            'description' => 'A full day on the Central Region coast: the canopy walkway high above the Kakum rainforest, then the UNESCO-listed Cape Coast and Elmina castles with their moving histories of the transatlantic slave trade. Lunch and entrance fees included.',
            'bring' => 'Comfortable shoes, rain jacket, passport copy']);
        $aburi = $A($sun, ['code' => 'ABURI', 'name' => 'Aburi Botanical Gardens & Crafts (private car)', 'category' => 'nature', 'duration_min' => 360, 'pricing' => 'group', 'price_adult' => 1800, 'capacity' => 4, 'schedule' => 'request', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'times' => [], 'cutoff_hours' => 24, 'sort_order' => 4, 'pickup_included' => true,
            'highlights' => 'Private car and driver-guide for up to 4 – botanical gardens, woodcarvers\' village, Akwapim ridge views',
            'description' => 'Your own car and driver-guide up the Akwapim ridge to the 1890 Aburi Botanical Gardens and the woodcarvers\' village, with time for lunch in the hills. Leave when it suits you.']);
        $surfl = $A($surf, ['code' => 'SURF', 'name' => 'Surf Lesson at Labadi Beach', 'category' => 'beach', 'duration_min' => 120, 'price_adult' => 400, 'price_child' => 300, 'child_max_age' => 15, 'capacity' => 6, 'times' => ['08:00', '15:00'], 'cutoff_hours' => 6, 'free_cancel_hours' => 12, 'cancel_fee_pct' => 0, 'sort_order' => 5,
            'meeting_point' => 'Labadi Surf School hut, Labadi Beach', 'highlights' => 'Two hours with a certified instructor – board and rash vest included', 'bring' => 'Swimwear, towel, sunscreen']);
        $high = $A($evt, ['code' => 'HIGHLIFE', 'name' => 'Friday Highlife Night – live band & dinner', 'category' => 'nightlife', 'duration_min' => 240, 'price_adult' => 350, 'capacity' => 40, 'weekdays' => [5], 'times' => ['19:30'], 'cutoff_hours' => 6, 'free_cancel_hours' => 24, 'cancel_fee_pct' => 100, 'instant' => true, 'sort_order' => 6,
            'meeting_point' => 'Hotel lobby – shared transfer at 19:15', 'highlights' => 'Live highlife band, Ghanaian dinner buffet and return transfer']);
        $shai = $A($kak, ['code' => 'SHAI', 'name' => 'Shai Hills Safari & Caves', 'category' => 'nature', 'duration_min' => 360, 'price_adult' => 950, 'price_child' => 475, 'min_pax' => 4, 'capacity' => 8, 'weekdays' => [6, 7], 'times' => ['07:00'], 'cutoff_hours' => 24, 'free_cancel_hours' => 48, 'pickup_included' => true, 'sort_order' => 7,
            'highlights' => 'Baboons, antelope and ostriches on foot, bat caves and rock climbing an hour from Accra',
            'description' => 'Walk among olive baboons, kob and ostriches in the Shai Hills Resource Reserve, explore the bat caves and climb the granite inselbergs for views to the coast.']);

        $P = fn (array $a) => Models\ConciergePlace::create($a + ['portal_visible' => true]);
        foreach ([
            ['name' => 'Kwame Nkrumah Memorial Park & Mausoleum', 'category' => 'attraction', 'area' => 'High Street', 'distance_km' => 3.2, 'hours' => 'Daily 09:00–17:00', 'description' => 'Resting place of Ghana\'s first president, with a museum of his life on the spot where independence was declared.', 'tip' => 'Go in the morning – the museum is cooler and quieter.', 'sort_order' => 1],
            ['name' => 'Independence Square (Black Star Square)', 'category' => 'attraction', 'area' => 'Osu', 'distance_km' => 3.5, 'hours' => 'Open daily', 'description' => 'The vast parade ground with the Black Star Gate and Independence Arch, facing the ocean.', 'sort_order' => 2],
            ['name' => 'W.E.B. Du Bois Centre', 'category' => 'attraction', 'area' => 'Cantonments', 'distance_km' => 2.4, 'hours' => 'Mon–Sat 09:00–16:30', 'description' => 'The home and grave of the Pan-African scholar, with his library and a small museum.', 'sort_order' => 3],
            ['name' => 'Jamestown Lighthouse', 'category' => 'attraction', 'area' => 'Jamestown', 'distance_km' => 4.8, 'description' => 'Climb the red-and-white lighthouse for views over the fishing harbour and Ussher Fort.', 'tip' => 'Best with a local guide – ask about our Jamestown Heritage Walk.', 'sort_order' => 4],
            ['name' => 'Makola Market', 'category' => 'shopping', 'area' => 'Central Accra', 'distance_km' => 3.9, 'hours' => 'Mon–Sat, early morning to dusk', 'description' => 'Accra\'s great market – fabrics, beads, spices and everything else.', 'tip' => 'Keep valuables out of sight and agree prices before buying.', 'sort_order' => 1],
            ['name' => 'Centre for National Culture (Arts Centre)', 'category' => 'shopping', 'area' => 'High Street', 'distance_km' => 3.4, 'hours' => 'Daily 08:00–18:00', 'description' => 'Rows of stalls selling kente, carvings, drums and paintings. Bargaining expected.', 'price_level' => 2, 'sort_order' => 2],
            ['name' => 'Labadi Beach', 'category' => 'beach', 'area' => 'La', 'distance_km' => 6.5, 'description' => 'Accra\'s liveliest beach, with bars, drummers and horse rides; busiest at weekends.', 'tip' => 'The sea has strong currents – swim only where the lifeguards are.', 'sort_order' => 1],
            ['name' => 'Oxford Street, Osu', 'category' => 'nightlife', 'area' => 'Osu', 'distance_km' => 2.8, 'hours' => 'Evenings until late', 'description' => 'Accra\'s busiest strip of restaurants, bars and street food.', 'price_level' => 2, 'sort_order' => 1],
            ['name' => 'Osu Night Market', 'category' => 'restaurant', 'area' => 'Osu', 'distance_km' => 2.9, 'hours' => 'Evenings', 'description' => 'Grilled tilapia, kelewele and waakye from street stalls – authentic and cheap.', 'price_level' => 1, 'sort_order' => 1],
            ['name' => '37 Military Hospital', 'category' => 'health', 'area' => 'Liberation Road', 'distance_km' => 2.1, 'hours' => '24-hour emergency department', 'description' => 'Large public hospital with a 24-hour emergency unit, close to the hotel.', 'tip' => 'The front desk can call a taxi or our car at any hour.', 'sort_order' => 1],
            ['name' => 'Kotoka International Airport (ACC)', 'category' => 'services', 'area' => 'Airport', 'distance_km' => 5.0, 'hours' => 'Open 24 hours', 'description' => 'Allow at least 3 hours before international departures from Terminal 3.', 'tip' => 'Book our airport drop on the guest portal or at the desk.', 'sort_order' => 1],
            ['name' => 'National emergency number', 'category' => 'emergency', 'phone' => '112', 'description' => 'Police, fire and ambulance from any phone in Ghana.', 'sort_order' => 1],
        ] as $p) {
            $P($p);
        }

        // stays: the in-house guests staying longest
        $stays = Models\ReservationRoom::with('guest', 'room', 'reservation.guest')->where('status', 'checked_in')->orderByDesc('departure_date')->orderBy('id')->get()->values();
        $stay = fn (int $i) => $stays[$i % max(1, $stays->count())] ?? null;
        $next = function (Models\Activity $a, int $from) use ($B) {
            for ($i = $from; $i < $from + 8; $i++) {
                if ($a->runsOn($B->copy()->addDays($i))) {
                    return $i;
                }
            }

            return $from;
        };
        // raw booking at any date (history); status transitions through the service where timing allows
        $mk = function (Models\Activity $a, array $o) use ($CS, $desk) {
            $st = $o['stay'] ?? null;
            $g = $st?->guest ?? $st?->reservation?->guest;
            $q = $CS::quote($a, $o['adults'], $o['children'] ?? 0);
            $dep = $a->isScheduled() ? $CS::departure($a, $o['date'], $o['time']) : null;
            $inStay = $st && $o['date'] >= $st->arrival_date->toDateString() && $o['date'] <= $st->departure_date->toDateString();
            $b = Models\ActivityBooking::create($q + ['number' => \App\Support\Numbering::next('activity_booking'), 'activity_id' => $a->id, 'activity_vendor_id' => $a->activity_vendor_id,
                'activity_departure_id' => $dep?->id, 'reservation_id' => $st?->reservation_id, 'reservation_room_id' => $st?->id, 'guest_id' => $g?->id,
                'guest_name' => $o['name'] ?? ($g?->full_name ?: 'Guest'), 'room_no' => $st?->room?->room_no, 'phone' => $o['phone'] ?? $g?->phone, 'email' => $g?->email,
                'date' => $o['date'], 'time' => $o['time'], 'adults' => $o['adults'], 'children' => $o['children'] ?? 0,
                'pickup_point' => $a->pickup_included ? hotel()->name.' – main lobby' : null, 'pickup_time' => $o['pickup'] ?? null, 'notes' => $o['notes'] ?? null,
                'source' => $o['source'] ?? 'desk', 'collection' => ($o['collection'] ?? $a->collectionMode()) === 'folio' && $inStay ? 'folio' : 'vendor',
                'status' => 'requested', 'vendor_ref' => $o['ref'] ?? null, 'voucher_token' => \Illuminate\Support\Str::random(40), 'created_by' => ($o['source'] ?? 'desk') === 'portal' ? null : $desk->id]);
            if (isset($o['created'])) {
                $b->forceFill(['created_at' => $o['created']])->save();
            }

            return $b;
        };
        $done = function (Models\ActivityBooking $b, string $status = 'completed') use ($CS) {
            $upd = ['status' => $status, 'confirmed_at' => $b->created_at, 'completed_at' => $b->startsAt()->copy()->addMinutes($b->activity->duration_min)];
            if ($status === 'no_show') {
                $upd += ['cancel_reason' => 'Guest did not come to the meeting point'] + ($b->collection === 'vendor' ? ['commission' => 0, 'vendor_net' => 0] : []);
            }
            $b->forceFill($upd)->save();
            $CS::recognise($b->fresh());

            return $b->fresh();
        };

        // ── last week: pay-the-vendor walks and surf lessons (non-residents and departed guests), completed / one no-show
        $walk = [
            [$jwalk, -6, '10:00', 'Emma Lawson', 2, 0], [$jwalk, -5, '16:00', 'Daniel Okafor', 3, 1], [$surfl, -5, '08:00', 'Lukas Weber', 1, 0],
            [$jwalk, -4, '10:00', 'Grace Ampofo', 2, 2], [$surfl, -3, '15:00', 'Chloe Martin', 2, 0], [$jwalk, -2, '16:00', 'Yaw Darko', 4, 0],
        ];
        $hist = [];
        foreach ($walk as $i => [$a, $off, $t, $name, $ad, $ch]) {
            $b = $mk($a, ['date' => $d($off), 'time' => $t, 'name' => $name, 'adults' => $ad, 'children' => $ch, 'collection' => 'vendor', 'created' => $B->copy()->addDays($off - 1)->setTime(11, 0)]);
            $hist[] = $done($b, $i === 4 ? 'no_show' : 'completed');
        }
        // Jamestown remitted last week's commission by MoMo into the Ecobank collections account
        $eco = Models\TreasuryAccount::where('name', 'Ecobank – Collections')->first() ?? $CS::payAccounts()->first();
        if ($eco && ($acc = $u('accounts'))) {
            $CS::settle($jam, $d(-4), $eco->id, $d(-1), 'MOMO-JHW-0927', $acc);
        }

        // ── yesterday: the morning city tour went out and is done (charge and commission dated yesterday – the open
        //    business day is never posted to the GL); today's afternoon tour is confirmed
        $s0 = $stay(0);
        $s1 = $stay(1);
        $s2 = $stay(2);
        $s3 = $stay(3);
        $sP = $stays->first(fn ($s) => $s->arrival_date->lt($B)) ?? $s0;   // a guest who was already in house yesterday
        $am = $mk($city, ['stay' => $sP, 'date' => $d(-1), 'time' => '09:00', 'adults' => 2, 'created' => $B->copy()->subDays(2)->setTime(18, 20)]);
        $am->forceFill(['status' => 'confirmed', 'confirmed_at' => $am->created_at, 'confirmed_by' => $desk->id])->save();   // (confirm() refuses starts >12 h ago)
        $CS::postCharge($am->fresh())?->forceFill(['business_date' => $d(-1)])->save();
        $CS::complete($am->fresh(), $desk);
        $pm = $mk($city, ['stay' => $s1, 'date' => $d(0), 'time' => '14:00', 'adults' => 2, 'children' => 1, 'created' => $B->copy()->subDay()->setTime(20, 5), 'notes' => 'Child seat for a 6-year-old']);
        $CS::confirm($pm, null, $desk, false);
        $mk($jwalk, ['date' => $d(0), 'time' => '16:00', 'name' => 'Priya Nair', 'adults' => 2, 'phone' => null, 'collection' => 'vendor', 'source' => 'phone']);

        // ── tomorrow: city tour confirmed (room bill); the surf school cancelled the morning session for the swell
        $tm = $mk($city, ['stay' => $s2, 'date' => $d(1), 'time' => '09:00', 'adults' => 2]);
        $CS::confirm($tm, null, $desk, false);
        $sf = $mk($surfl, ['stay' => $s3, 'date' => $d(1), 'time' => '08:00', 'adults' => 1, 'collection' => 'vendor']);
        $CS::confirm($sf, 'LSS-4471', $desk, false);
        $CS::cancelDeparture($sf->departure, 'Heavy swell forecast – the surf school has cancelled the session', $desk);
        // a late cancellation of today's afternoon tour, made yesterday evening (inside the 24 h window): half the price
        // stays on the bill, the hotel keeps its share – fee and commission dated yesterday
        $lc = $mk($city, ['stay' => $sP, 'date' => $d(0), 'time' => '14:00', 'adults' => 2, 'created' => $B->copy()->subDays(3)->setTime(10, 0)]);
        $CS::confirm($lc, null, $desk, false);
        $lc = $lc->fresh();
        $lc->forceFill(['confirmed_at' => $lc->created_at])->save();
        $fee = round((float) $lc->gross * (float) $city->cancel_fee_pct / 100, 2);
        $comm = round($fee * (float) $lc->commission_pct / 100, 2);
        \App\Services\FolioService::void($lc->folioTransaction, 'Activity cancelled '.$lc->number);
        $lc->forceFill(['folio_id' => null, 'folio_transaction_id' => null, 'status' => 'cancelled', 'cancelled_at' => $B->copy()->subDay()->setTime(20, 30), 'cancel_reason' => 'Guest has a business meeting after all',
            'cancel_fee' => $fee, 'commission' => $comm, 'vendor_net' => round($fee - $comm, 2)])->save();
        $CS::postCharge($lc, $fee, 'Cancellation fee – ')?->forceFill(['business_date' => $d(-1)])->save();
        $CS::recognise($lc->fresh(), $d(-1));

        // ── the next Cape Coast day: two confirmed (room bill), one still waiting for Kakum Eco (flagged – over 4 h)
        $cd = $next($cape, 2);
        $c1 = $mk($cape, ['stay' => $s0, 'date' => $d($cd), 'time' => '06:00', 'adults' => 2, 'ref' => 'KEA-22910', 'notes' => 'Vegetarian lunch x1']);
        $CS::confirm($c1, 'KEA-22910', $desk, false);
        $c2 = $mk($cape, ['stay' => $s1, 'date' => $d($cd), 'time' => '06:00', 'adults' => 2, 'children' => 1]);
        $CS::confirm($c2, null, $desk, false);
        $mk($cape, ['stay' => $s2, 'date' => $d($cd), 'time' => '06:00', 'adults' => 2, 'created' => now()->subHours(7)]);

        // ── the weekend Shai Hills trip is full (8 seats) with a family on the waitlist
        $sd = $next($shai, 1);
        $g1 = $mk($shai, ['name' => 'Accra International School – staff outing', 'date' => $d($sd), 'time' => '07:00', 'adults' => 6, 'collection' => 'vendor', 'phone' => null, 'source' => 'phone']);
        $CS::confirm($g1, 'KEA-22931', $desk, false);
        $g2 = $mk($shai, ['stay' => $s0, 'date' => $d($sd), 'time' => '07:00', 'adults' => 2]);
        $CS::confirm($g2, null, $desk, false);
        $w = $mk($shai, ['stay' => $s3, 'date' => $d($sd), 'time' => '07:00', 'adults' => 2, 'children' => 2]);
        $w->forceFill(['status' => 'waitlisted'])->save();

        // ── guest portal: a private Aburi request, a highlife night booked and one cancelled free
        $mk($aburi, ['stay' => $s1, 'date' => $d($s1 && $s1->departure_date->lt($B->copy()->addDays(2)) ? 1 : 2), 'time' => '09:30', 'adults' => 3, 'source' => 'portal', 'notes' => 'Would like to stop at the woodcarvers\' village']);
        $hd = $next($high, 1);
        $h1 = $mk($high, ['stay' => $s2, 'date' => $d($hd), 'time' => '19:30', 'adults' => 2, 'source' => 'portal']);
        $CS::confirm($h1, null, null, false);
        $h2 = $mk($high, ['stay' => $s0, 'date' => $d($hd), 'time' => '19:30', 'adults' => 2, 'source' => 'portal']);
        $CS::cancel($h2, 'Cancelled by the guest via the portal', null, false, false);

        $CS::saveSettings(['portal' => true, 'portal_lead' => 3, 'auto_complete' => true, 'confirm_hours' => 4, 'notify' => true, 'desk_phone' => '']);
        \App\Services\LedgerService::sync(true);
        $prevUser ? Auth::setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedOwner(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $OS = \App\Services\OwnerService::class;
        $TS = \App\Services\TreasuryService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $acc, $eng, $owner, $fom] = [$u('admin'), $u('accounts'), $u('engineer'), $u('owner'), $u('fom')];
        \App\Services\ChartOfAccounts::ensure();
        $OS::ensureDefaults();
        $m1 = bdate()->copy()->subMonthsNoOverflow(2)->startOfMonth(); // e.g. 2026-07-01 – closed by seedClose
        $m0 = bdate()->copy()->subMonthNoOverflow()->startOfMonth();   // e.g. 2026-08-01
        Auth::setUser($acc);

        $res = $TS::createAccount(['kind' => 'bank', 'name' => 'Ecobank – FF&E Reserve', 'bank_name' => 'Ecobank Ghana', 'branch' => 'Ridge, Accra', 'account_no' => '1441000611902', 'swift' => 'ECOCGHAC',
            'currency' => 'GHS', 'min_balance' => 0, 'overdraft_limit' => 0, 'in_position' => 0, 'is_active' => 1, 'notes' => 'Ring-fenced FF&E replacement reserve under the HMA – withdrawals need owner approval']);
        $OS::saveAgreement(['operator_name' => 'Nowills Hospitality Management Ltd', 'owner_name' => 'Airport City Hotel Properties Ltd', 'contract_ref' => 'HMA-2026-01',
            'effective_from' => $m1->toDateString(), 'ends_on' => $m1->copy()->addYears(15)->subDay()->toDateString(), 'base_fee_pct' => 3, 'incentive_pct' => 8, 'incentive_basis' => 'agop',
            'owner_priority' => 600000, 'ffe_schedule' => [2, 3, 4], 'reserve_account_id' => $res->id, 'pay_days' => 15, 'notes' => '15-year HMA; incentive on AGOP above the owner\'s priority return'], $admin);

        // reserve opening balance carried over from the previous operator, and the month-1 fees (accrued before the month is closed)
        $OS::addEntry(['type' => 'opening', 'amount' => 40000, 'date' => $m1->toDateString(), 'memo' => 'Balance handed over by the previous operator'], $acc);
        $OS::accrue($m1->format('Y-m'), $acc);

        // the cash: GH₵40,000 moved from Ecobank collections once the term loan landed (the month-1 contribution is still to be moved)
        $eco = Models\TreasuryAccount::where('name', 'Ecobank – Collections')->firstOrFail();
        $t = $TS::createTransfer(['kind' => 'transfer', 'from_account_id' => $eco->id, 'to_account_id' => $res->id, 'cash_amount' => 40000, 'date' => $m0->copy()->addDays(4)->toDateString(),
            'slip_no' => 'ECO-TRF-0912', 'notes' => 'Fund the FF&E reserve account – opening balance'], $acc);
        if ($t->status === 'requested') {
            Auth::setUser($admin);
            $TS::approve($t, $admin);
            Auth::setUser($acc);
        }
        $TS::dispatch($t->fresh(), $acc, $m0->copy()->addDays(4)->toDateString());
        $TS::complete($t->fresh(), $acc, $m0->copy()->addDays(5)->toDateString());

        // capex: approved AC replacement (reserve), kitchen hood awaiting the owner (owner capital), lobby Wi-Fi done from the reserve
        Auth::setUser($eng);
        $p1 = $OS::saveProject(['title' => 'Guest-room split AC replacement – 2nd floor (12 units)', 'category' => 'ffe', 'funding' => 'reserve', 'usali_dept' => 'rooms', 'budget' => 96000,
            'justification' => "Twelve 1.5 HP units on the 2nd floor are 11 years old, use R22 gas and fail weekly (38 AC complaints last quarter).\nThree quotes received; Inverter units cut power use by ~35%.", 'needed_by' => bdate()->copy()->addMonths(2)->toDateString()], $eng);
        $OS::submitProject($p1, $eng);
        Auth::setUser($owner);
        $OS::decideProject($p1->fresh(), $owner, true, 'Approved – phase over two months so rooms stay sellable; reserve to be topped up from the monthly contributions.');
        Auth::setUser($eng);
        $p2 = $OS::saveProject(['title' => 'Main kitchen extraction hood & fire suppression', 'category' => 'building', 'funding' => 'owner', 'usali_dept' => 'fnb', 'budget' => 170000,
            'justification' => "The GNFS inspection flagged the 2009 hood and the missing wet-chemical suppression system. A certificate is needed to renew the fire permit.\nContractor quote GH¢170,000 incl. ducting; 50% deposit, balance on commissioning.", 'needed_by' => bdate()->copy()->addWeeks(6)->toDateString()], $eng);
        $OS::submitProject($p2, $eng);
        Auth::setUser($admin);
        $p3 = $OS::saveProject(['title' => 'Lobby & restaurant Wi-Fi access points', 'category' => 'it', 'funding' => 'reserve', 'usali_dept' => 'it', 'budget' => 18500,
            'justification' => 'Six Wi-Fi 6 access points and cabling for the lobby, restaurant and pool deck – guest reviews score Wi-Fi 2.9 / 5.'], $admin);
        $OS::submitProject($p3, $admin);
        Auth::setUser($owner);
        $OS::decideProject($p3->fresh(), $owner, true, null);
        Auth::setUser($acc);
        $paid = $m0->copy()->addDays(20)->toDateString();
        \App\Services\LedgerService::post(['date' => $paid, 'reference' => 'NETSOL/INV/2291', 'narration' => 'Netsol Ghana – Wi-Fi access points & cabling (CPX project, paid from the FF&E reserve account)',
            'lines' => [[Models\AccountHead::sys('repairs'), 18500, 'Lobby & restaurant Wi-Fi'], [$res->account_head_id, -18500, 'Ecobank FF&E reserve']]], 'manual', null);
        $OS::addEntry(['type' => 'drawdown', 'amount' => 18500, 'date' => $paid, 'capex_project_id' => $p3->id, 'memo' => 'Netsol Ghana inv. 2291 – access points & cabling'], $acc);
        $OS::closeProject($p3->fresh(), $admin, 'completed');
        \App\Services\LedgerService::sync(true);
        Auth::setUser($prevUser ?? $admin);
    }

    /** v5.8: the closed month's owner pack, issued with commentary; the owner queried the payroll line and the operator answered */
    protected function seedOwnerPacks(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $OS = \App\Services\OwnerService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $acc, $owner] = [$u('admin'), $u('accounts'), $u('owner')];
        $m1 = bdate()->copy()->subMonthsNoOverflow(2)->startOfMonth();
        Auth::setUser($acc);
        $s = $OS::prepare($m1->format('Y-m'), $acc);
        $OS::saveCommentary($s, "Trading\nRooms revenue was carried by corporate and airline crew business; occupancy softened mid-month with the end of the conference season. Food & beverage margins held despite higher protein prices.\n\n"
            ."Costs & payroll\nPayroll includes the annual SSNIT-rate adjustment. Utilities rose with three days on the generator during ECG load-shedding.\n\n"
            ."Capital projects & FF&E\nThe 2nd-floor AC replacement (CPX) was approved and is being phased. The reserve opening balance of GH¢40,000 was handed over by the previous operator.\n\n"
            ."Outlook for next month\nAugust bookings are ahead of budget on rooms; two large banquets are contracted.");
        Auth::setUser($admin);
        $OS::issue($s->fresh(), $admin);
        Auth::setUser($owner);
        $q = $OS::raiseQuery($s->fresh(), $owner, 'Labour cost is high against revenue this month – please explain the payroll line and whether the SSNIT adjustment is a one-off.');
        Auth::setUser($acc);
        $OS::reply($q->fresh(), $acc, 'The SSNIT ceiling moved to GH¢69,000 from January and the arrears for the first half-year were settled in this payroll – about GH¢4,200 of the month\'s cost is one-off. Normal run-rate resumes next month.');
        Auth::setUser($prevUser ?? $admin);
    }

    protected function seedClose(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $CS = \App\Services\CloseService::class;
        $B = \App\Services\BudgetService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $acc] = [$u('admin'), $u('accounts')];
        $sys = fn (string $k) => Models\AccountHead::sys($k);
        \App\Services\ChartOfAccounts::ensure();

        $cur = bdate()->format('Y-m');
        $fy = $B::currentFy();
        $start = Carbon::parse($CS::fyStart($fy - 1))->format('Y-m');          // e.g. 2025-04
        $lastClosed = Carbon::parse($cur.'-01')->subMonthsNoOverflow(2)->format('Y-m'); // e.g. 2026-07
        $prevMonth = $CS::prev($cur);                                             // e.g. 2026-08 – close in progress
        $fyStartM = Carbon::parse($CS::fyStart($fy))->format('Y-m');              // e.g. 2026-04
        $yeMonth = Carbon::parse($CS::fyEnd($fy - 1))->format('Y-m');             // e.g. 2026-03
        Models\Setting::updateOrCreate(['key' => 'close_start'], ['value' => $start]);
        Models\Setting::$version++;

        Auth::setUser($acc);
        $mk = function (array $d) use ($CS, $acc, $admin) {
            $a = $CS::createAccrual($d, $acc);
            if (($d['_approve'] ?? true)) {
                Auth::setUser($admin);
                $CS::decideAccrual($a, $admin, true, $d['_note'] ?? null);
                Auth::setUser($acc);
            }

            return $a->fresh();
        };
        // the annual insurance premium, paid by the owners on the hotel's behalf, sits in prepaid expenses
        \App\Services\LedgerService::post(['date' => $fyStartM.'-02', 'reference' => 'EIC/FB/2026/0417', 'narration' => 'Fire & burglary insurance premium '.$B::fyLabel($fy).' – paid by the owners (capital contribution)',
            'lines' => [[$sys('prepayments'), 48000, 'Enterprise Insurance – policy FB-2026-0417'], [$sys('capital'), -48000, 'Owners – premium paid on the hotel\'s behalf']]], 'manual', null);
        $mk(['type' => 'prepayment', 'description' => 'Fire & burglary insurance – Enterprise Insurance ('.Carbon::parse($fyStartM.'-01')->format('M Y').' – '.Carbon::parse($fyStartM.'-01')->addMonths(11)->format('M Y').')',
            'pl_account_id' => $sys('insurance'), 'amount' => 48000, 'start_period' => $fyStartM, 'months' => 12, 'reference' => 'FB-2026-0417', '_note' => 'Agreed to the policy schedule']);
        $mk(['type' => 'accrual', 'description' => 'External audit fee '.$B::fyLabel($fy - 1).' – KPMG Ghana (engagement letter)', 'pl_account_id' => $sys('professional_fees'),
            'amount' => 30000, 'start_period' => $yeMonth, 'reference' => 'EL-2026-03']);
        $mk(['type' => 'accrual', 'description' => 'ECG electricity – '.Carbon::parse($prevMonth.'-01')->format('F').' consumption, bill due mid-month', 'pl_account_id' => $sys('utility_power'),
            'supplier_id' => Models\Supplier::where('name', 'like', 'City Power%')->value('id'), 'amount' => 18500, 'start_period' => $prevMonth, 'reference' => 'meter 0412-7781', 'notes' => 'Estimate from the daily meter readings (Utilities › Readings)']);
        $mk(['type' => 'deferral', 'description' => 'Ecobank ATM lobby space – 12 months\' rent received in advance', 'pl_account_id' => $sys('rev_space_rental'),
            'amount' => 36000, 'start_period' => $CS::next($cur), 'months' => 12, 'reference' => 'LIC-ATM-2026', '_approve' => false]);

        $rj = function (array $d, bool $approve = true) use ($CS, $acc, $admin) {
            $r = $CS::createRecurring($d, $acc);
            if ($approve) {
                Auth::setUser($admin);
                $CS::decideRecurring($r, $admin, true);
                Auth::setUser($acc);
            }

            return $r;
        };
        $rj(['name' => 'Head-office management fee – 2% of budgeted revenue', 'frequency' => 'monthly', 'start_period' => $fyStartM,
            'lines' => [['account_head_id' => $sys('professional_fees'), 'debit' => 12000, 'memo' => 'Management fee'], ['account_head_id' => $sys('accrued_expenses'), 'credit' => 12000, 'memo' => 'Due to Nowills Hospitality (head office)']]]);
        $rj(['name' => 'Standing accrual – guarding services billed a month in arrears', 'frequency' => 'monthly', 'start_period' => Carbon::parse($cur.'-01')->subMonthsNoOverflow(3)->format('Y-m'), 'auto_reverse' => true,
            'lines' => [['account_head_id' => $sys('misc_expense'), 'debit' => 9800, 'memo' => 'Guarding services'], ['account_head_id' => $sys('accrued_expenses'), 'credit' => 9800, 'memo' => 'Accrued – invoice in arrears']]]);
        $rj(['name' => 'GNFS fire certificate & extinguisher servicing', 'frequency' => 'quarterly', 'start_period' => $CS::next($cur),
            'lines' => [['account_head_id' => $sys('repairs'), 'debit' => 1500], ['account_head_id' => $sys('accrued_expenses'), 'credit' => 1500]]], false);

        $waive = [
            'depreciation' => 'Asset register taken on in June 2026 – depreciation for earlier months is in the opening NBV.',
            'payroll' => 'Payroll processed by the external bureau until July 2026 – journal on file.',
            'statutory' => 'PAYE / SSNIT remitted through the payroll bureau – GRA and SSNIT receipts on file.',
            'bank_rec' => 'Bank reconciled manually in Excel before the module went live – signed copy on file.',
            'controls' => 'Opening-balance timing difference – cleared in the following month.',
            'suspense' => 'Unidentified MoMo receipt – cleared the following month.',
            'claims' => 'Claims approved after month end – immaterial.',
            'income_audit' => 'Income audit adopted in September 2026 – day checks done on paper.',
            'gl_posted' => 'Accepted by the financial controller – see working papers.',
            'period_end' => 'Accepted by the financial controller – see working papers.',
        ];
        $manualNotes = ['Ageing reviewed – no provision needed', 'VAT / NHIL / GETFund and tourism levy agreed to the returns filed', 'GRNI listing reviewed – nothing material', 'Pack reviewed with the GM'];
        $times = [];
        $closeMonth = function (string $m, bool $reopenAfter = false) use ($CS, $acc, $admin, $waive, $manualNotes, &$times) {
            $p = $CS::period($m);
            Auth::setUser($acc);
            $CS::runPostings($p, $acc);
            $CS::runChecks($p, $acc);
            foreach ($p->tasks()->get() as $i => $t) {
                if ($t->kind === 'manual') {
                    $CS::completeTask($t, $acc, $manualNotes[$i % 4] ?? null);
                } elseif (in_array($t->status, ['fail', 'pending'], true)) {
                    $CS::waiveTask($t, $acc, $waive[$t->key] ?? 'Accepted by the financial controller – see working papers.');
                }
            }
            $CS::submit($p->fresh(), $acc, $p->isYearEnd() ? 'Year-end close – audit adjustments posted' : null);
            Auth::setUser($admin);
            $CS::approve($p->fresh(), $admin, $p->isYearEnd() ? 'Year end approved after the external audit fieldwork' : null);
            $times[$m] = ($times[$m] ?? 0) + 1;
            if ($reopenAfter) {
                Auth::setUser($acc);
                $CS::requestReopen($p->fresh(), $acc, 'ECG credit note CN-0511 for May received late – to be posted in May');
                Auth::setUser($admin);
                $CS::approveReopen($p->fresh(), $admin);
                Auth::setUser($acc);
                // the late credit note, dated in the reopened month
                \App\Services\LedgerService::post(['date' => Carbon::parse($m.'-01')->endOfMonth()->toDateString(), 'reference' => 'ECG CN-0511', 'narration' => 'ECG credit note – meter overbilling ('.Carbon::parse($m.'-01')->format('M Y').')',
                    'lines' => [[Models\AccountHead::sys('accrued_expenses'), 2350, 'ECG credit note'], [Models\AccountHead::sys('utility_power'), -2350, 'Electricity – credit note']]], 'manual', null);
            }
        };
        $reopenMonth = Carbon::parse($lastClosed.'-01')->subMonthsNoOverflow(2)->format('Y-m'); // e.g. 2026-05
        for ($m = $start; $m <= $lastClosed; $m = $CS::next($m)) {
            $closeMonth($m, $m === $reopenMonth);
            if ($m === $reopenMonth) {
                $closeMonth($m); // re-closed with the credit note in it
            }
        }

        // back-date the workflow: prepared ~5 days after month end, approved ~8 days after (the year end ~3 weeks, after the audit)
        foreach (Models\ClosePeriod::where('status', 'closed')->get() as $p) {
            $end = $p->end();
            $prep = $end->copy()->addDays($p->isYearEnd() ? 16 : 4 + ($end->month % 3))->setTime(16, 30);
            $closed = $prep->copy()->addDays($p->isYearEnd() ? 5 : 2 + ($end->month % 2))->setTime(10, 15);
            $p->forceFill(['prepared_at' => $prep, 'closed_at' => $closed, 'checked_at' => $prep])->save();
            $evs = $p->events()->reorder('id')->get();
            $n = $evs->count();
            foreach ($evs as $i => $ev) {
                // spread the trail from the first check to the final approval
                $t = $prep->copy()->subHours(3)->addMinutes((int) round(($closed->getTimestamp() - $prep->copy()->subHours(3)->getTimestamp()) / 60 * ($n > 1 ? $i / ($n - 1) : 1)));
                $ev->forceFill(['created_at' => $t])->save();
            }
            $p->tasks()->whereNotNull('done_at')->update(['done_at' => $prep->copy()->subHours(1), 'checked_at' => $prep->copy()->subHours(2)]);
        }
        foreach (Models\FiscalYearClose::all() as $y) {
            $y->forceFill(['closed_at' => Models\ClosePeriod::where('period', Carbon::parse($y->closing_date)->format('Y-m'))->value('closed_at')])->save();
        }

        // last month: checks run, two manual tasks signed off; depreciation not run yet and four period-end postings due
        Auth::setUser($acc);
        $p = $CS::period($prevMonth);
        $CS::runChecks($p, $acc);
        $tasks = $p->tasks()->where('kind', 'manual')->orderBy('sort')->get();
        $CS::completeTask($tasks[0], $acc, 'Ageing reviewed – Asante Events 75 days, chased; no provision yet');
        $CS::completeTask($tasks[1], $acc, 'Agreed to the GRA VAT return and the GTA levy return');
        $CS::period($cur);
        $prevUser ? Auth::setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedReportAutomation(bool $rich): void
    {
        if (! $rich) {
            return;
        }
        $RS = \App\Services\ReportScheduleService::class;
        $K = \App\Services\KpiService::class;
        $prevUser = Auth::user();
        $u = fn (string $n) => Models\User::where('username', $n)->first();
        [$admin, $fom, $acc] = [$u('admin'), $u('fom'), $u('accounts')];

        // saved views
        Auth::setUser($admin);
        $RS::saveView(['report_key' => 'flash', 'name' => "Yesterday's flash", 'range' => 'yesterday', 'is_pinned' => true], $admin);
        $RS::saveView(['report_key' => 'revenue', 'name' => 'Revenue – last 7 days', 'range' => 'last_7', 'is_shared' => true], $admin);
        $RS::saveView(['report_key' => 'collection', 'name' => 'MTD collections', 'range' => 'mtd', 'is_pinned' => true], $acc);
        $arr = $RS::saveView(['report_key' => 'arrivals', 'name' => 'Arrivals – next 7 days', 'range' => 'next_7', 'columns' => ['res', 'guest', 'phone', 'room', 'type', 'arr', 'nights', 'company'],
            'is_shared' => true, 'is_pinned' => true], $fom);

        // schedules
        $flash = $RS::save(['name' => 'Morning flash – GM & owner', 'report_key' => 'flash', 'range' => 'yesterday', 'frequency' => 'daily', 'send_time' => '07:00',
            'recipient_users' => [$admin->id], 'recipient_emails' => 'gm@nowills.example', 'format' => 'both',
            'message' => 'Good morning – yesterday\'s flash. Call the duty manager with any questions.'], $admin);
        $RS::save(['name' => 'Weekly revenue review', 'report_key' => 'revenue', 'range' => 'last_7', 'frequency' => 'weekly', 'weekday' => 1, 'send_time' => '08:00',
            'recipient_users' => [$admin->id], 'recipient_emails' => 'finance@nowills.example', 'format' => 'html'], $admin);
        $RS::save(['name' => 'Month-end city ledger ageing', 'report_key' => 'ar_aging', 'range' => 'last_month', 'frequency' => 'monthly', 'month_day' => 0, 'send_time' => '18:00',
            'recipient_emails' => 'accounts@nowills.example, credit@nowills.example', 'format' => 'csv'], $acc);
        $ia = $RS::save(['name' => 'Income audit exceptions', 'report_key' => 'ia_findings', 'range' => 'yesterday', 'frequency' => 'after_audit',
            'recipient_users' => [$admin->id], 'recipient_emails' => 'controller@nowills.example', 'skip_empty' => true], $admin);
        $arrS = $RS::save(['name' => 'Arrivals for the week (front office)', 'report_view_id' => $arr->id, 'frequency' => 'after_audit',
            'recipient_emails' => 'frontoffice@nowills.example', 'format' => 'both'], $fom);
        $RS::run($flash->fresh(), 'manual', $admin);
        $RS::run($arrS->fresh(), 'after_audit');
        $RS::run($ia->fresh(), 'after_audit');
        // an old schedule to a private mailbox – blocked once the domain allow-list is switched on, auto-paused after 5 failures
        $old = Models\ReportSchedule::create(['user_id' => $admin->id, 'name' => "Owner's daily pack (old)", 'report_key' => 'flash', 'range' => 'yesterday',
            'frequency' => 'daily', 'send_time' => '09:00', 'format' => 'both', 'recipient_emails' => 'hotelowner@hotmail.com', 'is_active' => true]);
        $RS::saveSettings(['rpt_email_domains' => 'nowills.example, example.com']);
        for ($i = 0; $i < 5; $i++) {
            $RS::run($old->fresh());
        }

        // KPI alerts
        $alerts = [
            ['name' => 'Low occupancy', 'metric' => 'occ', 'operator' => 'below', 'threshold' => 60, 'severity' => 'critical', 'channels' => ['email']],
            ['name' => 'ADR under target', 'metric' => 'adr', 'operator' => 'below', 'threshold' => 3000, 'severity' => 'warning', 'channels' => ['email']],
            ['name' => 'Weak daily collections', 'metric' => 'collect', 'operator' => 'below', 'threshold' => 20000, 'severity' => 'warning', 'channels' => ['email', 'sms'], 'recipient_phones' => '+233244000111'],
            ['name' => 'Income audit backlog', 'metric' => 'open_exceptions', 'operator' => 'above', 'threshold' => 5, 'severity' => 'warning', 'channels' => []],
            ['name' => 'Soft tomorrow', 'metric' => 'otb_tomorrow', 'operator' => 'below', 'threshold' => 40, 'severity' => 'info', 'channels' => []],
            ['name' => 'Rooms out of order', 'metric' => 'ooo_rooms', 'operator' => 'above', 'threshold' => 1, 'severity' => 'info', 'channels' => []],
            ['name' => 'City ledger over 60 days', 'metric' => 'ar_over_60', 'operator' => 'above', 'threshold' => 50000, 'severity' => 'critical', 'channels' => ['email'], 'recipient_emails' => 'credit@nowills.example'],
            ['name' => 'Tenant arrears', 'metric' => 'lease_arrears', 'operator' => 'above', 'threshold' => 10000, 'severity' => 'warning', 'channels' => []],
        ];
        foreach ($alerts as $a) {
            $K::save($a + ['recipient_users' => [$admin->id]], $admin);
        }
        $K::evaluate(bdate()->copy()->subDays(2));
        $K::evaluate(bdate()->copy()->subDay());
        if ($ev = Models\KpiAlertEvent::where('business_date', bdate()->copy()->subDays(2)->toDateString())->orderBy('id')->first()) {
            $K::acknowledge($ev, $fom, 'Discussed at the morning briefing – sales team calling corporate accounts.');
        }
        Auth::setUser($prevUser ?? $admin);
    }
}
