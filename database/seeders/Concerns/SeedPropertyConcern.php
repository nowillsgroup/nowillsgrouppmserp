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
trait SeedPropertyConcern
{
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

}
