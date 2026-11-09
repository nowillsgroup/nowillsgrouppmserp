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
trait SeedGrowthConcern
{
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
            'investment' => 60000, 'annual_revenue' => 540000, 'annual_cost' => 150000, 'horizon_years' => 3, 'launch_date' => $B->copy()->startOfMonth()->subMonthsNoOverflow(4)->toDateString()],
            'live', ['submitted_at' => $B->copy()->subMonthsNoOverflow(6), 'decided_by' => $admin->id, 'decided_at' => $B->copy()->subMonthsNoOverflow(6)->addDays(2), 'live_date' => $B->copy()->startOfMonth()->subMonthsNoOverflow(4)->toDateString()],
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

}
