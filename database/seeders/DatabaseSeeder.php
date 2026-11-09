<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedPropertyConcern;
use Database\Seeders\Concerns\SeedFrontOfficeConcern;
use Database\Seeders\Concerns\SeedFnBConcern;
use Database\Seeders\Concerns\SeedFinanceConcern;
use Database\Seeders\Concerns\SeedPeopleConcern;
use Database\Seeders\Concerns\SeedGrowthConcern;
use Database\Seeders\Concerns\SeedComplianceConcern;
use Database\Seeders\Concerns\SeedPlatformConcern;
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
    use SeedPropertyConcern,
        SeedFrontOfficeConcern,
        SeedFnBConcern,
        SeedFinanceConcern,
        SeedPeopleConcern,
        SeedGrowthConcern,
        SeedComplianceConcern,
        SeedPlatformConcern;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'DatabaseSeeder creates synthetic development/demo records and is disabled in production.'
            );
        }

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

    /**
     * v6.8 F&B cost control: bar & kitchen stock items (a Liquor category), recipes for the 21 dishes that had none (the 9 original recipes are
     * left as they are), recipe-card headers (most approved), 4 applied yield tests, 28 days of settled restaurant & bar bills with a realistic
     * popularity mix and recipe consumption, officers' checks, staff canteen issues with the staff-meal log, a posted kitchen / bar stock count
     * with shrinkage, two historic price changes and plate-cost snapshots (four dishes creeping up). Runs before seedParking and relies on its
     * LedgerService::sync(true) for the back-dated stock days and bills. All dates are on or before the day before the business date.
     */

    /**
     * v6.9 talent demo: establishment and line managers; vacancies in every state (Guest Service Agent ×2 open with a full
     * pipeline – scorecards, an issued offer and an above-range offer awaiting approval; Fitness Instructor filled by Kofi;
     * Room Attendant awaiting approval to replace Lata; a cancelled Night Auditor; a draft Commis Chef); new starters (Abena's
     * probation review and written statement overdue, Kofi mid-onboarding); the 2026 mid-year appraisal cycle at every stage;
     * discipline (a live verbal warning, an expired written warning, a query whose reply is overdue, a dismissal) and a
     * confidential grievance; Lata working her notice and two former employees who left before the first seeded payroll.
     * No new active employees – payroll comparisons in earlier phases depend on the headcount.
     */

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


    /**
     * v7.0 sustainability & ESG – runs after seedTalent (needs its employees). Everything is dated relative to the business date and written
     * straight to the tables (no GL impact).
     */


    /** v6.4 demo: the Grand Hotel's reservations enquiry log for the last three weeks – every outcome, reason and state. */

    /** v6.4 demo: the Grand Hotel referred three turnaways to the Lake Resort (linked to the resort's bookings), plus one resort regret. */

    /** v2.5 demo: ten events across the whole pipeline, driven through the service with back-dated business days. */


    /** v7.5 event organiser portal demo. Logins use the password Organiser2026. */

    /** v7.4 corporate & travel-agent portal demo. Logins use the password Partner2026. */

    /**
     * v3.4 foreign-guest demo: Form C for every foreign stay of the last three weeks – departed guests filed and their departures
     * reported (one filed late with a reason, the latest departure still to report); in house: one filed, one overdue, one complete
     * and due (with a companion sharing the room) and one incomplete (new visa not yet scanned). Forex: two weeks of daily rates
     * (today's CHF carried by the night audit), cash encashments from foreign guests (one voided), a surrender to the bank five
     * days ago that leaves a JPY lot held too long, and a bill credit in US dollars through the service.
     */

    /**
     * v3.6 – budgeting demo: the FY budget sized to the demo hotel (24 rooms), prepared by the accountant and approved by the
     * administrator (four-eyes); statistics drive room revenue; the ledger is compared from the first of this month (go-live);
     * September variances explained; a re-forecast after August awaiting approval; next year's draft copied at +8 % / +6 %.
     */

    /**
     * v3.8 gift vouchers: a spread of sold vouchers (partly redeemed on a folio, a dinner voucher used in the restaurant,
     * a reloaded stored-value card, one expiring soon, one lapsed, one suspended, one cancelled & refunded) and a promotional
     * "Diwali 2026" campaign. Dates are back-dated after issue so the liability, breakage and sales reports have history.
     */

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

    /**
     * v4.6 bank reconciliation demo (hotel 1): last month's bank statement reconciled (with a bank charge booked from it and two
     * items still in transit), this month's bank statement in progress (auto-matched; charges, interest and an unidentified credit
     * still to deal with, two cheques not yet presented) and a Mobile Money merchant statement with daily batched collections and fees.
     */
    /** v4.7 A small PNG "till receipt" so the demo vouchers and claims have something to open. */

    /** v4.7 petty cash floats and staff expense claims (cash only – nothing touches the bank statements being reconciled). */
    /** v4.8 Procurement II demo data (hotel 1). */


    /**
     * v3.5 front desk operations demo: a day and a half of log book entries across shifts (a resolved complaint, a pinned VIP
     * arrival with a follow-up, an open high-priority leak with comments whose follow-up is due, the automatic night-audit
     * hand-over, a cash-float note), wake-up calls (a daily one, one booked through the guest portal, one due in ten minutes,
     * yesterday's answered and unanswered calls with the escalation), messages and parcels (notified by SMS, a parcel on the
     * shelf, a message waiting for an arriving guest, a parcel left behind by a departed guest), traces (today, carried from
     * yesterday, tomorrow, done) and the lost & found store (valuables in the safe, items past retention, a returned and a
     * disposed item, loss reports – one with a likely match).
     */

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

    /** v3.2 restaurant table reservations: floor layout, service periods, 30 days of history, a live floor today and the coming week. */


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

    /** v2.9 privacy demo: DSR requests in every state, a restricted + an erased profile, retention history & due items, PII access log, breaches. */


    /**
     * v2.7 contracts & credit: Infosys on a net-rate LRA contract with seasons, a Christmas blackout and a Deluxe
     * allotment (its bookings linked), TCS on 15% off expiring soon, a Thomas Cook winter contract awaiting approval,
     * an expired Yatra contract; credit terms per account, a past override and statement, and the review that puts
     * Thomas Cook (bills ~3 months old on 30-day terms) on automatic hold.
     */

    /**
     * v2.6 guest marketing: imported history for a few lapsed guests, consent (with audit trail), tags, segments, promo
     * codes, a sent campaign with clicks / an unsubscribe / attributed bookings, a scheduled and a draft campaign, and
     * automations that have been running for two weeks.
     */

    /**
     * v1.9 revenue management: realistic booking dates (lead times) on the history, a forward book that builds
     * towards arrival (weekend-heavy), pricing rules, floors/ceilings and 14 days of on-the-books snapshots.
     */

    /** v1.7: portal settings, links for every current stay, a live request queue and a pending in-room dining order. */

    /** v1.6: opening balances + fixed assets as manual journals, then post every closed day to the GL. */
    /** v1.8: accounts payable – received POs, matched / variance / held bills, expense bills, a debit note, payments and an advance. */

    /**
     * v7.53 — two supplier bills in the supplier's own currency, booked in cedis at the forex board for their invoice
     * date, with the supplier's own document kept on the header. Called after seedForeign() because the board has to
     * exist before a bill can be converted at it; a property with no board keeps a plain cedi ledger, and a refusal is
     * not demo data, so this quietly does nothing there.
     */

    /** Attendance from 1 July (or joining): a fixed weekly off, occasional absences and some overtime; deterministic per employee/day. */

    /**
     * v2.0 HR & payroll: leave (approved / pending / rejected), salary advances and three payroll runs –
     * July paid, August approved (salaries payable) and September in draft.
     */

    /**
     * v2.1 online payments (built-in test gateway): paid deposits (one partly refunded), a portal bill payment,
     * a link awaiting payment, expired / cancelled links, one under review, an online booking on hold and a payout.
     */

    /**
     * v2.2 laundry & linen: a week of guest / walk-in laundry orders in every state (one from a portal request),
     * linen stock with par gaps, and laundry batches – billed, closed short, part-returned, open and in-house.
     */

    /**
     * v2.3 fixed assets & preventive maintenance: existing assets taken on at the June go-live with the depreciation
     * already charged, June and July depreciation posted (August is left for the demo), a direct purchase, an asset
     * bought on a supplier bill, one sold and one scrapped, and PM schedules with a history of on-time / late service.
     */

    /** v2.4: main-store stock, department heads / budgets / cost accounts, sub-store pars and a month of requisitions. */


    /**
     * v4.9 workforce: shift templates; rosters for last / this / next week (next week's spa roster still a draft); a week of
     * time punches through today (late arrivals, a no-show, a missed clock-out, an early departure, a corrected punch and
     * unrostered work) with D-6 … D-2 approved into attendance; swaps waiting for a colleague / a manager and one approved;
     * kiosk PINs; service charge points; August & September tips and the August distribution approved, ready for payroll.
     */


    /** v5.1 Health club demo: ~14 memberships in every state, two weeks of visits, classes with a waitlist, day passes. */

    /**
     * v5.2 Serviced apartments & leases demo (standalone units – no hotel rooms are taken off sale): a corporate 2-bed with
     * 8% WHT and metered electricity, an expat paid half-yearly in advance, a residential 3-bed quarterly in arrears, an office
     * suite on a yearly cycle already reviewed +10%, a kiosk in arrears with a late fee, a studio on notice, an ended lease
     * with damages and its deposit settled, a signed draft starting next week, one vacant unit and one under maintenance.
     */

    /** v5.3 Income audit: five closed days, one awaiting the controller, yesterday in audit with exceptions; authorisations; a room count. */

    /** v5.4 Saved report views, scheduled report e-mails (with a delivery history and one auto-paused schedule), KPI alerts. */
    /**
     * v5.5 packages: breakfast included in every CP rate (breakfast + dinner in MAP, Wi-Fi in the suites); the posted nights of
     * the last 9 days allocate their entitlements, most breakfasts were eaten (some partly, some skipped = breakage), a dinner
     * add-on used partly last night, an airport drop and a spa credit sold for tonight, and this morning's breakfast half served
     * at the Meal Desk (one extra cover charged to the room, one entry voided).
     */

    /**
     * v5.6 financial close demo, run through the real workflow: schedules and recurring journals posted month by month,
     * checks run, gaps waived with reasons, manual tasks signed off, submitted by the accountant and approved by the
     * administrator (four eyes) – which locks the ledger and, for March, posts the FY closing entry. May is reopened
     * once for a late credit note. Last month is left open with its period-end postings due; timestamps are back-dated.
     */
    /** v5.7 treasury: all activity dated from the 1st of last month, into a new Ecobank account (never the reconciled GCB / MoMo heads). */

    /** v5.8 owner reporting: HMA from the first closed month, reserve account funded from Ecobank, fees accrued for the month that is about to be closed, capex pipeline */
    /**
     * v5.9 concierge (Accra): five licensed operators, seven activities (city tour twice daily, Jamestown walk, Cape Coast / Elmina / Kakum
     * day trip, private Aburi trip on request, surf lessons, Friday highlife night, weekend Shai Hills) and the local guide. Last week's
     * pay-the-vendor bookings completed (one no-show) and Jamestown's commission settled into Ecobank; today's morning city tour done
     * and charged to the room; tomorrow's tours confirmed; a day trip still waiting for the operator; a full weekend departure with a
     * waitlist; a portal request; a free and a late (fee) cancellation; a surf session cancelled by the school for the swell.
     */
    /** v6.0 learning & development: courses and the training matrix, session history, an overdue session awaiting results, upcoming refreshers, SOP library with versions and sign-offs */

    /** v6.3 demo: a six-hotel competitive set with a fortnight of rate shops, six months of benchmarks, partnerships in every state and initiatives across the stage gate. */


    /** v5.8: the closed month's owner pack, issued with commentary; the owner queried the payroll line and the operator answered */


}
