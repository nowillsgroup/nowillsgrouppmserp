<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\BusinessSource;
use App\Models\Department;
use App\Models\Hotel;
use App\Models\PaymentMode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tax;
use App\Support\Tenant;

/**
 * Creates default roles, permissions and base masters for a new property.
 */
class HotelProvisioner
{
    public const ROLES = [
        'Administrator' => ['*'],
        'Front Office Manager' => ['partner.view', 'partner.approve', 'organiser.view', 'organiser.approve', 'inbox.', 'kiosk.', 'iptv.', 'esg.view', 'esg.green', 'talent.view', 'talent.recruit', 'hse.view', 'hse.check', 'parking.', 'qa.view', 'qa.audit', 'cro.', 'sales.view', 'learning.view', 'learning.train', 'learning.sop', 'concierge.', 'treasury.view', 'treasury.operate', 'packages.', 'income_audit.view', 'income_audit.request', 'income_audit.authorise', 'income_audit.count', 'leases.view', 'leases.manage', 'leases.billing', 'club.view', 'club.desk', 'roster.view', 'roster.manage', 'timeclock.approve', 'petty.view', 'petty.manage', 'security.', 'upsell.', 'utilities.view', 'utilities.record', 'compliance.view', 'vouchers.', 'budget.view', 'calls.', 'keys.', 'interfaces.manage', 'logbook.', 'desk.', 'lostfound.', 'formc.', 'forex.', 'transport.view', 'transport.book', 'rentals.view', 'rentals.manage', 'robita.manage', 'offline.desktop.view', 'offline.desktop.sync', 'tables.view', 'tables.book', 'gst.view', 'pii.reveal', 'privacy.view', 'privacy.handle', 'privacy.breach', 'contracts.view', 'contracts.approve', 'credit.view', 'credit.override', 'requisitions.view', 'requisitions.raise', 'requisitions.approve', 'laundry.view', 'laundry.orders', 'payments.view', 'payments.request', 'payments.refund', 'dashboard.', 'reservations.', 'frontdesk.', 'folio.', 'pos.view', 'pos.refund', 'guests.', 'feedback.', 'housekeeping.view', 'reports.', 'nightaudit.', 'banquet.view', 'crm.view', 'crm.manage', 'channels.', 'spa.view', 'messages.', 'requests.', 'revenue.', 'hr.leave', 'hr.self', 'claims.submit'],
        'Receptionist' => ['inbox.view', 'inbox.reply', 'kiosk.view', 'kiosk.assist', 'iptv.view', 'iptv.message', 'esg.green', 'parking.view', 'parking.operate', 'cro.view', 'cro.book', 'concierge.view', 'concierge.book', 'packages.view', 'packages.sell', 'packages.redeem', 'income_audit.request', 'leases.view', 'club.view', 'club.desk', 'security.view', 'security.visitors', 'security.incidents', 'upsell.view', 'upsell.sell', 'vouchers.view', 'vouchers.sell', 'vouchers.redeem', 'calls.view', 'keys.view', 'keys.issue', 'logbook.view', 'logbook.write', 'desk.', 'lostfound.manage', 'formc.view', 'formc.manage', 'forex.view', 'forex.exchange', 'transport.view', 'transport.book', 'rentals.view', 'rentals.manage', 'offline.desktop.view', 'offline.desktop.sync', 'tables.view', 'tables.book', 'feedback.cases', 'channels.view', 'channels.resolve', 'contracts.view', 'laundry.view', 'laundry.orders', 'payments.view', 'payments.request', 'dashboard.view', 'reservations.view', 'reservations.create', 'reservations.edit', 'frontdesk.', 'folio.view', 'folio.post', 'folio.payment', 'folio.shift', 'guests.', 'housekeeping.view', 'spa.', 'messages.send', 'requests.', 'hr.self', 'claims.submit'],
        'Housekeeping Supervisor' => ['inbox.view', 'esg.view', 'esg.record', 'esg.green', 'talent.view', 'talent.recruit', 'hse.view', 'hse.check', 'qa.view', 'qa.audit', 'learning.view', 'learning.train', 'learning.sop', 'income_audit.count', 'roster.view', 'roster.manage', 'timeclock.approve', 'security.gatepass', 'security.incidents', 'keys.view', 'keys.master', 'logbook.view', 'logbook.write', 'feedback.cases', 'banquet.view', 'requisitions.view', 'requisitions.raise', 'requisitions.approve', 'assets.view', 'laundry.', 'dashboard.view', 'housekeeping.', 'maintenance.', 'lostfound.', 'inventory.view', 'requests.', 'hr.self', 'claims.submit'],
        'Restaurant Cashier' => ['parking.view', 'parking.validate', 'packages.view', 'packages.redeem', 'foodsafety.view', 'foodsafety.record', 'vouchers.view', 'vouchers.redeem', 'tables.view', 'tables.book', 'requisitions.view', 'requisitions.raise', 'dashboard.view', 'pos.', 'folio.shift', 'hr.self', 'claims.submit'],
        'Banquet Manager' => ['organiser.', 'hse.view', 'hse.check', 'parking.view', 'parking.validate', 'sales.view', 'sales.edit', 'learning.view', 'learning.train', 'security.gatepass', 'logbook.view', 'logbook.write', 'requisitions.view', 'requisitions.raise', 'dashboard.view', 'banquet.', 'guests.view', 'reports.view', 'hr.self', 'claims.submit'],
        'Accountant' => ['partner.view', 'partner.approve', 'esg.view', 'fbc.view', 'parking.view', 'concierge.view', 'concierge.settle', 'owner.view', 'owner.operate', 'owner.manage', 'treasury.view', 'treasury.operate', 'treasury.manage', 'close.view', 'close.prepare', 'packages.view', 'income_audit.view', 'income_audit.audit', 'income_audit.request', 'leases.view', 'leases.billing', 'leases.approve', 'club.view', 'club.members', 'foodsafety.view', 'roster.view', 'servicecharge.manage', 'servicecharge.approve', 'purchasing.approve', 'petty.', 'claims.', 'bankrec.', 'billingrec.', 'security.view', 'upsell.view', 'utilities.view', 'utilities.manage', 'compliance.view', 'vouchers.view', 'vouchers.manage', 'budget.view', 'budget.manage', 'calls.view', 'calls.manage', 'logbook.view', 'forex.view', 'forex.manage', 'formc.view', 'gst.', 'folio.credit_note', 'channels.view', 'contracts.view', 'credit.', 'banquet.view', 'banquet.billing', 'requisitions.', 'assets.', 'laundry.view', 'payments.view', 'payments.refund', 'payments.settle', 'dashboard.view', 'accounts.', 'ap.', 'gl.', 'folio.view', 'folio.shift', 'folio.shift_all', 'pos.view', 'pos.refund', 'reports.', 'inventory.view', 'revenue.view', 'payroll.view', 'payroll.approve', 'hr.self', 'claims.submit'],
        'Store Keeper' => ['fbc.view', 'foodsafety.view', 'foodsafety.record', 'purchasing.rfq', 'purchasing.prices', 'security.gatepass', 'requisitions.view', 'requisitions.issue', 'assets.view', 'dashboard.view', 'inventory.', 'hr.self', 'claims.submit'],
        'Kitchen / Chef' => ['esg.view', 'esg.record', 'talent.view', 'fbc.view', 'fbc.cost', 'hse.view', 'hse.check', 'qa.view', 'learning.view', 'learning.train', 'learning.sop', 'packages.view', 'foodsafety.', 'roster.view', 'roster.manage', 'timeclock.approve', 'petty.view', 'petty.manage', 'compliance.view', 'banquet.view', 'requisitions.view', 'requisitions.raise', 'pos.kitchen', 'hr.self', 'claims.submit'],
        'Room Attendant' => ['housekeeping.attendant', 'hr.self', 'claims.submit'],
        'Spa Desk / Therapist' => ['hse.check', 'parking.view', 'parking.validate', 'club.view', 'club.desk', 'club.members', 'requisitions.view', 'requisitions.raise', 'dashboard.view', 'spa.', 'guests.view', 'hr.self', 'claims.submit'],
        'Laundry Attendant' => ['hse.check', 'security.gatepass', 'requisitions.view', 'requisitions.raise', 'laundry.view', 'laundry.orders', 'laundry.linen', 'hr.self', 'claims.submit'],
        'HR Manager' => ['esg.view', 'talent.', 'hse.view', 'learning.', 'foodsafety.view', 'foodsafety.certs', 'roster.', 'timeclock.', 'servicecharge.manage', 'claims.submit', 'dashboard.view', 'hr.', 'payroll.view', 'payroll.manage', 'reports.view'],
        'Sales & Marketing' => ['partner.', 'iptv.view', 'iptv.message', 'cro.view', 'sales.', 'digital.', 'bd.view', 'bd.edit', 'concierge.view', 'packages.view', 'packages.manage', 'upsell.view', 'upsell.manage', 'api.view', 'vouchers.view', 'vouchers.sell', 'vouchers.manage', 'budget.view', 'channels.view', 'contracts.view', 'contracts.manage', 'credit.view', 'dashboard.view', 'crm.', 'guests.view', 'feedback.view', 'feedback.respond', 'feedback.manage', 'banquet.view', 'reports.view', 'reports.schedule', 'messages.send', 'hr.self', 'claims.submit'],
        'F&B Manager' => ['esg.view', 'esg.record', 'talent.view', 'talent.recruit', 'fbc.', 'hse.view', 'hse.check', 'parking.view', 'parking.validate', 'qa.view', 'qa.audit', 'learning.view', 'learning.train', 'learning.sop', 'packages.', 'foodsafety.', 'roster.view', 'roster.manage', 'timeclock.approve', 'servicecharge.manage', 'purchasing.approve', 'security.gatepass', 'security.incidents', 'compliance.view', 'vouchers.view', 'vouchers.redeem', 'budget.view', 'dashboard.view', 'pos.', 'tables.', 'guests.view', 'feedback.cases', 'requisitions.view', 'requisitions.raise', 'requisitions.approve', 'inventory.view', 'reports.view', 'reports.schedule', 'hr.self', 'claims.submit'],
        'Transport Desk / Concierge' => ['inbox.view', 'inbox.reply', 'parking.view', 'parking.operate', 'concierge.view', 'concierge.book', 'security.view', 'security.visitors', 'logbook.view', 'logbook.write', 'desk.messages', 'desk.wakeup', 'desk.traces', 'lostfound.manage', 'dashboard.view', 'transport.', 'guests.view', 'requests.view', 'reports.view', 'messages.send', 'hr.self', 'claims.submit'],
        'Driver' => ['transport.drive', 'hr.self', 'claims.submit'],
        'Security Officer' => ['iptv.view', 'iptv.emergency', 'hse.view', 'hse.check', 'parking.view', 'parking.operate', 'learning.view', 'timeclock.kiosk', 'security.view', 'security.visitors', 'security.gate', 'security.incidents', 'logbook.view', 'logbook.write', 'lostfound.manage', 'dashboard.view', 'guests.view', 'hr.self', 'claims.submit'],
        'Valet / Parking Attendant' => ['parking.view', 'parking.operate', 'hr.self', 'claims.submit'], // v6.6
        'Quality Manager' => ['inbox.view', 'esg.view', 'hse.view', 'qa.', 'learning.view', 'learning.sop', 'feedback.view', 'foodsafety.view', 'housekeeping.view', 'dashboard.view', 'reports.view', 'reports.schedule', 'hr.self', 'claims.submit'], // v6.5
        'Reservations Agent (CRO)' => ['partner.view', 'partner.approve', 'inbox.view', 'inbox.reply', 'cro.view', 'cro.book', 'reservations.view', 'reservations.create', 'reservations.edit', 'guests.view', 'dashboard.view', 'reports.view', 'hr.self', 'claims.submit'], // v6.4
        'Business Development Manager' => ['partner.view', 'cro.view', 'bd.', 'sales.view', 'sales.edit', 'digital.view', 'crm.view', 'feedback.view', 'revenue.view', 'dashboard.view', 'reports.view', 'reports.schedule', 'hr.self', 'claims.submit'], // v6.3
        'Digital Marketing Executive' => ['digital.view', 'digital.content', 'sales.view', 'crm.view', 'feedback.view', 'dashboard.view', 'reports.view', 'hr.self', 'claims.submit'], // v6.2
        "Owner's Representative" => ['esg.view', 'sales.view', 'owner.view', 'owner.approve', 'bd.view', 'bd.approve'], // v5.8: the owner's asset manager – owner packs, capex approval
        'Cost Controller' => ['fbc.', 'inventory.view', 'foodsafety.view', 'pos.view', 'reports.view', 'dashboard.view', 'hr.self', 'claims.submit'], // v6.8
        'Health & Safety Officer' => ['iptv.view', 'iptv.emergency', 'esg.view', 'esg.record', 'hse.', 'reports.view', 'dashboard.view', 'learning.view', 'learning.sop', 'qa.view', 'security.view', 'logbook.view', 'hr.self', 'claims.submit'], // v6.7
        'Sustainability Manager' => ['esg.', 'utilities.view', 'hse.view', 'learning.view', 'feedback.view', 'reports.view', 'reports.schedule', 'dashboard.view', 'hr.self', 'claims.submit'], // v7.0
        'Chief Engineer' => ['iptv.view', 'iptv.manage', 'esg.view', 'esg.record', 'talent.view', 'hse.view', 'hse.check', 'hse.permit', 'qa.view', 'qa.audit', 'foodsafety.view', 'purchasing.rfq', 'security.view', 'security.gatepass', 'security.approve', 'security.incidents', 'utilities.', 'compliance.', 'logbook.view', 'logbook.write', 'keys.view', 'feedback.cases', 'banquet.view', 'requisitions.view', 'requisitions.raise', 'requisitions.approve', 'dashboard.view', 'maintenance.', 'assets.view', 'assets.manage', 'inventory.view', 'housekeeping.view', 'reports.view', 'hr.self', 'claims.submit'],
    ];

    public static function syncPermissions(): void
    {
        foreach (config('pms.permissions') as $module => $perms) {
            foreach ($perms as $key => $label) {
                Permission::updateOrCreate(['key' => $key], ['module' => $module, 'label' => $label]);
            }
        }
    }

    /**
     * Upgrade helper: register new permissions and grant them to the built-in roles that match,
     * without removing anything an administrator customised. Safe to run repeatedly.
     */
    public static function upgrade(Hotel $hotel): int
    {
        $prev = Tenant::hotel();
        Tenant::set($hotel);
        self::syncPermissions();
        $all = Permission::pluck('id', 'key');
        $added = 0;
        foreach (self::ROLES as $name => $patterns) {
            $role = Role::firstOrCreate(['name' => $name], ['description' => $name.' role', 'is_system' => true]);
            $ids = $all->filter(fn ($id, $key) => collect($patterns)->contains(fn ($p) => $p === '*' || $key === $p || (str_ends_with($p, '.') && str_starts_with($key, $p))))->values();
            $added += count($role->permissions()->syncWithoutDetaching($ids)['attached']);
        }
        \App\Models\Department::firstOrCreate(['name' => 'Spa & Wellness']); // v1.5: therapists live here
        ChartOfAccounts::ensure(); // v1.6: general ledger chart of accounts
        GhanaTaxSetup::apply(); // v4.3: GRA VAT / NHIL / GETFund + GTA tourism levy, SSNIT & PAYE, cedi accounts
        PayrollService::ensureDefaults(); // v2.0: pay components & leave types
        OnlinePaymentService::ensureDefaults(); // v2.1: Online Payment mode + gateway accounts
        LaundryService::ensureDefaults(); // v2.2: guest-laundry price list
        LinenService::ensureDefaults(); // v2.2: linen types
        AssetService::ensureDefaults(); // v2.3: asset categories & depreciation rules
        RequisitionService::ensureDefaults(); // v2.4: department cost accounts, sub-stores & requisition rules
        BanquetSalesService::ensureDefaults(); // v2.5: banquet guarantee / cancellation / deposit rules
        CrmService::ensureDefaults(); // v2.6: marketing frequency cap, attribution window, consent wording
        ContractService::ensureDefaults(); // v2.7: credit auto-hold, grace days, contract expiry alert window
        GstService::ensureDefaults(); // v4.3: GRA E-VAT settings (off until switched on)
        GstService::backfill(); // v3.0: invoice date + buyer snapshot on invoices issued before the upgrade
        FeedbackService::ensureDefaults(); // v3.1: default post-stay survey, alert rules, review response templates
        FeedbackService::backfill(); // v3.1: score / sentiment / departments on feedback received before the upgrade
        TransportService::ensureDefaults(); // v3.3: transport desk settings & tariff card
        FormCService::ensureDefaults(); // v3.4: Form C (FRRO) settings
        ForexService::ensureDefaults(); // v3.4: forex desk settings, currencies, forex payment mode
        FrontDeskOpsService::ensureDefaults(); // v3.5: shifts, wake-up escalation, message notification, lost & found retention
        BudgetService::ensureDefaults(); // v3.6: fiscal year, variance thresholds, spend control
        InterfaceService::ensureDefaults(); // v3.7: PABX / door-lock interface settings, PBX push token
        GiftVoucherService::ensureDefaults(); // v3.8: voucher settings, Gift Voucher payment mode, starter voucher types
        BankRecService::ensureDefaults(); // v4.6: bank reconciliation matching window, overdue alert, segregation
        PettyCashService::ensureDefaults(); // v4.7: receipt threshold, IOU days, count frequency, variance tolerance
        ExpenseClaimService::ensureDefaults(); // v4.7: mileage rate, receipt threshold, claim age limit
        FoodSafetyService::ensureDefaults(); // v5.0: standard HACCP checklist templates
        ClubService::ensureDefaults(); // v5.1: health club plans, weekly classes & club settings
        LeaseService::ensureDefaults(); // v5.2: lease billing settings
        IncomeAuditService::ensureDefaults(); // v5.3: income audit tolerances & authority limits
        PackageService::ensureDefaults(); // v5.5: package settings + starter catalogue (not attached to any rate)
        TreasuryService::ensureDefaults(); // v5.7: Cheque payment mode, cheques-in-hand / cash-in-transit / bank-loan accounts, treasury register
        OwnerService::ensureDefaults(); // v5.8: management-fee accounts, USALI department of each HR department
        ConciergeService::ensureDefaults(); // v5.9: concierge desk settings
        SalesService::ensureDefaults(); // v6.1: stage probabilities, stale / dormant windows, follow-up & birthday reminders
        DigitalMarketingService::ensureDefaults(); // v6.2: post approval, attribution window, listing audit interval
        CroService::ensureDefaults(); // v6.4: follow-up hours, enquiry lapse days, conversion target
        BusinessDevService::ensureDefaults(); // v6.3: hurdle rate, rate-shop staleness, partnership renewal notice, benchmark room type
        TalentService::ensureDefaults(); // v6.9: probation, query reply days, warning expiry, applicant retention, maternity leave type
        FbCostService::ensureDefaults(); // v6.8: beverage cost target, costing basis, menu-engineering popularity factor, cost-creep window, food / beverage classes
        EsgService::ensureDefaults(); // v7.0: building areas, Scope 3 factors, green-stay rules, starter certification checklist & draft policy register; Ghana grid factor
        IptvService::ensureDefaults(); // v7.1: TV settings, starter welcome screens (en / fr, VIP, returning, company, birthday) and information pages
        HseService::ensureDefaults(); // v6.7: drill interval, evacuation target, review interval, Factories Inspectorate office
        ParkingService::ensureDefaults(); // v6.6: parking tariff, valet settings, starter zones
        QaService::ensureDefaults(); // v6.5: pass mark, target score, critical auto-fail, corrective-action due days
        LearningService::ensureDefaults(); // v6.0: training & SOP settings (expiry / review warning windows, self sign-off)
        $prev ? Tenant::set($prev) : null;

        return $added;
    }

    public static function provision(Hotel $hotel): void
    {
        $prev = Tenant::hotel();
        Tenant::set($hotel);
        self::syncPermissions();
        $all = Permission::pluck('id', 'key');

        foreach (self::ROLES as $name => $patterns) {
            $role = Role::firstOrCreate(['name' => $name], ['description' => $name.' role', 'is_system' => true]);
            $ids = $all->filter(function ($id, $key) use ($patterns) {
                foreach ($patterns as $p) {
                    if ($p === '*' || $key === $p || (str_ends_with($p, '.') && str_starts_with($key, $p))) {
                        return true;
                    }
                }

                return false;
            })->values();
            $role->permissions()->sync($ids);
        }

        if (Tax::count() === 0) { // v4.3 Ghana: VAT 15% + NHIL 2.5% + GETFund 2.5% (GRA) and Tourism Levy 1% (GTA)
            foreach (TaxService::defaultRows() as $row) {
                Tax::create($row + ['slab_from' => 0, 'slab_to' => null, 'is_active' => true]);
            }
        }
        if (PaymentMode::count() === 0) {
            foreach ([['Cash', 'cash'], ['Credit / Debit Card', 'card'], ['Bank Transfer', 'bank'], ['Mobile Money (MoMo)', 'wallet'], ['Bill to Company', 'city_ledger']] as [$n, $t]) {
                PaymentMode::create(['name' => $n, 'type' => $t]);
            }
        }
        if (BusinessSource::count() === 0) {
            foreach ([['Walk-in', 'walk_in', 0], ['Direct / Phone', 'direct', 0], ['Booking Engine', 'booking_engine', 0], ['Booking.com', 'ota', 15], ['Expedia', 'ota', 18], ['MakeMyTrip', 'ota', 15], ['Agoda', 'ota', 15], ['Airbnb', 'ota', 15], ['Corporate', 'corporate', 0], ['Travel Agent', 'travel_agent', 10]] as [$n, $t, $c]) {
                BusinessSource::create(['name' => $n, 'type' => $t, 'commission_pct' => $c]);
            }
        }
        if (AccountHead::count() === 0) {
            foreach ([['Room Revenue', 'income'], ['F&B Revenue', 'income'], ['Banquet Revenue', 'income'], ['Other Income', 'income'], ['Salaries & Wages', 'expense'], ['Electricity', 'expense'], ['Water', 'expense'], ['Repairs & Maintenance', 'expense'], ['Purchases – Food', 'expense'], ['Purchases – Beverages', 'expense'], ['Housekeeping Supplies', 'expense'], ['Marketing & OTA Commission', 'expense'], ['Rent', 'expense'], ['Internet & Telephone', 'expense'], ['Miscellaneous Expense', 'expense'], ['Bank Account', 'asset'], ['Cash in Hand', 'asset']] as [$n, $t]) {
                AccountHead::create(['name' => $n, 'type' => $t]);
            }
        }
        ChartOfAccounts::ensure();
        if (Department::count() === 0) {
            foreach (['Front Office', 'Housekeeping', 'F&B Service', 'Kitchen', 'Maintenance', 'Accounts', 'Security', 'Management', 'Spa & Wellness'] as $d) {
                Department::create(['name' => $d]);
            }
        }
        if (Store::count() === 0) {
            foreach ([['Main Store', 'main'], ['Kitchen Store', 'kitchen'], ['Bar Store', 'bar'], ['Housekeeping Store', 'housekeeping']] as [$n, $t]) {
                Store::create(['name' => $n, 'type' => $t]);
            }
        }
        GhanaTaxSetup::apply(); // v4.3 (no-op on a fresh property apart from account names)
        PayrollService::ensureDefaults();
        OnlinePaymentService::ensureDefaults();
        LaundryService::ensureDefaults();
        LinenService::ensureDefaults();
        AssetService::ensureDefaults();
        RequisitionService::ensureDefaults();
        BanquetSalesService::ensureDefaults();
        CrmService::ensureDefaults();
        ContractService::ensureDefaults();
        GstService::ensureDefaults();
        FeedbackService::ensureDefaults();
        TransportService::ensureDefaults();
        FormCService::ensureDefaults();
        ForexService::ensureDefaults();
        FrontDeskOpsService::ensureDefaults();
        BudgetService::ensureDefaults();
        InterfaceService::ensureDefaults();
        GiftVoucherService::ensureDefaults(); // v3.8
        BankRecService::ensureDefaults(); // v4.6
        PettyCashService::ensureDefaults(); // v4.7
        ExpenseClaimService::ensureDefaults();
        FoodSafetyService::ensureDefaults();
        ClubService::ensureDefaults(); // v5.1: health club plans, weekly classes & club settings
        LeaseService::ensureDefaults(); // v5.2
        IncomeAuditService::ensureDefaults(); // v5.3
        PackageService::ensureDefaults(); // v5.5
        TreasuryService::ensureDefaults(); // v5.7
        OwnerService::ensureDefaults(); // v5.8
        Tenant::set($prev);
    }
}
