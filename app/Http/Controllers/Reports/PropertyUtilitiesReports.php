<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Reports\AccountsReports;
use App\Http\Controllers\Reports\BanquetEventsReports;
use App\Http\Controllers\Reports\BillingReports;
use App\Http\Controllers\Reports\BudgetPlanningReports;
use App\Http\Controllers\Reports\CentralReservationsReports;
use App\Http\Controllers\Reports\ChannelManagerReports;
use App\Http\Controllers\Reports\ComplianceHseReports;
use App\Http\Controllers\Reports\ConciergeActivitiesReports;
use App\Http\Controllers\Reports\FeedbackReputationReports;
use App\Http\Controllers\Reports\FixedAssetsEngineeringReports;
use App\Http\Controllers\Reports\FnBPosReports;
use App\Http\Controllers\Reports\FoodSafetyReports;
use App\Http\Controllers\Reports\ForeignGuestsForexReports;
use App\Http\Controllers\Reports\FrontOfficeReports;
use App\Http\Controllers\Reports\GeneralLedgerReports;
use App\Http\Controllers\Reports\GuestMessagingReports;
use App\Http\Controllers\Reports\HealthClubSpaReports;
use App\Http\Controllers\Reports\HotelGroupOwnerReportingReports;
use App\Http\Controllers\Reports\HotelInterfacesReports;
use App\Http\Controllers\Reports\HousekeepingReports;
use App\Http\Controllers\Reports\HrPeopleReports;
use App\Http\Controllers\Reports\InventoryPurchasingReports;
use App\Http\Controllers\Reports\IptvGuestTechnologyReports;
use App\Http\Controllers\Reports\LaundryLinenReports;
use App\Http\Controllers\Reports\LearningDevelopmentReports;
use App\Http\Controllers\Reports\ManagementMisReports;
use App\Http\Controllers\Reports\MarketingCrmReports;
use App\Http\Controllers\Reports\PartnerOrganiserPortalsReports;
use App\Http\Controllers\Reports\PayrollReports;
use App\Http\Controllers\Reports\PropertyUtilitiesReports;
use App\Http\Controllers\Reports\QualityAssuranceReports;
use App\Http\Controllers\Reports\RevenueManagementReports;
use App\Http\Controllers\Reports\SalesBusinessDevelopmentReports;
use App\Http\Controllers\Reports\SecurityReports;
use App\Http\Controllers\Reports\SelfServiceKioskReports;
use App\Http\Controllers\Reports\SustainabilityEsgReports;
use App\Http\Controllers\Reports\SystemAdministrationReports;
use App\Http\Controllers\Reports\TalentManagementReports;
use App\Http\Controllers\Reports\TaxStatutoryReports;
use App\Http\Controllers\Reports\TransportParkingReports;
use App\Http\Controllers\Reports\TreasuryPaymentsReports;
use App\Services\PropertyAccessService;
use App\Services\ShiftService;
use App\Models\ActivityLog;
use App\Models\BanquetEvent;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\MaintenanceRequest;
use App\Models\NightAudit;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\PurchaseOrder;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Activity;
use App\Models\ActivityBooking;
use App\Models\ActivityDeparture;
use App\Models\AgentCommission;
use App\Models\ApAllocation;
use App\Models\ApiClient;
use App\Models\ApiIdempotencyKey;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDepRun;
use App\Models\Attendance;
use App\Models\BankMatch;
use App\Models\BanquetEventItem;
use App\Models\BanquetHall;
use App\Models\BanquetPackage;
use App\Models\BanquetService;
use App\Models\BdCompetitor;
use App\Models\BdMilestone;
use App\Models\BdRateShopLine;
use App\Models\BdResult;
use App\Models\BillingReconciliation;
use App\Models\BillingReconciliationItem;
use App\Models\Budget;
use App\Models\BudgetEvent;
use App\Models\BudgetLine;
use App\Models\BudgetStat;
use App\Models\BusinessSource;
use App\Models\CarRental;
use App\Models\CashForecastLine;
use App\Models\CashierMovement;
use App\Models\CashierShift;
use App\Models\ChannelAriState;
use App\Models\ChannelMessage;
use App\Models\ChannelRateMap;
use App\Models\ChannelRestriction;
use App\Models\ChannelRoomMap;
use App\Models\CloseEvent;
use App\Models\ClubClass;
use App\Models\ClubPlan;
use App\Models\ClubTicketPayment;
use App\Models\ClubTicketSale;
use App\Models\ClubTicketSaleItem;
use App\Models\ClubTicketType;
use App\Models\ComplianceEvent;
use App\Models\ConciergePlace;
use App\Models\CreditLog;
use App\Models\CroContact;
use App\Models\Currency;
use App\Models\DataBreach;
use App\Models\DataMigrationBatch;
use App\Models\DataMigrationRow;
use App\Models\Department;
use App\Models\DmAdStat;
use App\Models\DmChannel;
use App\Models\DmLinkClick;
use App\Models\EinvoiceLog;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\EmployeePayComponent;
use App\Models\EsgChecklistItem;
use App\Models\EsgCommunity;
use App\Models\EsgGreenStay;
use App\Models\EsgInitiative;
use App\Models\EsgLaundryMonth;
use App\Models\EsgPolicy;
use App\Models\EsgTarget;
use App\Models\ExpenseClaimLine;
use App\Models\FbCostSnapshot;
use App\Models\FbRecipeCard;
use App\Models\FbStaffMeal;
use App\Models\FbYieldTest;
use App\Models\FeedbackAnswer;
use App\Models\FeedbackCase;
use App\Models\FeedbackCaseEvent;
use App\Models\FiscalYearClose;
use App\Models\Floor;
use App\Models\ForexRate;
use App\Models\ForexSurrender;
use App\Models\FsCertificate;
use App\Models\FsEquipment;
use App\Models\FsLabel;
use App\Models\GatePass;
use App\Models\GatePassItem;
use App\Models\GatePassReturn;
use App\Models\GatewayPayout;
use App\Models\GlJournal;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\GstCancelledDoc;
use App\Models\GstPeriod;
use App\Models\GuestDocument;
use App\Models\HistoricalStockMovementArchive;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\HrAnnouncement;
use App\Models\HrDocument;
use App\Models\HrProfileUpdate;
use App\Models\HseWarden;
use App\Models\InboxBroadcast;
use App\Models\InboxQuickReply;
use App\Models\InterfaceCommand;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\Kiosk;
use App\Models\KotPrintJob;
use App\Models\KotPrinter;
use App\Models\KpiAlert;
use App\Models\LaundryItem;
use App\Models\LaundryOrder;
use App\Models\LaundryOrderLine;
use App\Models\Lease;
use App\Models\LeaseInspection;
use App\Models\LeaseMeterReading;
use App\Models\LeaseUnit;
use App\Models\LeaveType;
use App\Models\LinenBatch;
use App\Models\LinenBatchLine;
use App\Models\LinenType;
use App\Models\LogEntry;
use App\Models\LogEntryComment;
use App\Models\LogEntryRead;
use App\Models\LoyaltyEntry;
use App\Models\ManagementAgreement;
use App\Models\MarketingCollateral;
use App\Models\MarketingConsentLog;
use App\Models\MarketingMessage;
use App\Models\MarketingSegment;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemRecipe;
use App\Models\MenuModifier;
use App\Models\MenuModifierGroup;
use App\Models\MessageLog;
use App\Models\MeterReading;
use App\Models\MinibarItem;
use App\Models\OfflineSyncOperation;
use App\Models\OnlinePayment;
use App\Models\OnlinePaymentEvent;
use App\Models\OnlineRefund;
use App\Models\OrganiserAttendee;
use App\Models\OtaChannel;
use App\Models\Outlet;
use App\Models\OutletServicePeriod;
use App\Models\OwnerQuery;
use App\Models\OwnerStatement;
use App\Models\ParkingVehicle;
use App\Models\PaymentMode;
use App\Models\Permission;
use App\Models\PettyCount;
use App\Models\PettyReplenishment;
use App\Models\PmSchedule;
use App\Models\PosTable;
use App\Models\PrivacyRequestEvent;
use App\Models\PurchaseOrderItem;
use App\Models\QaAuditItem;
use App\Models\QaStandard;
use App\Models\RateContract;
use App\Models\RateContractBlackout;
use App\Models\RateContractRate;
use App\Models\RateOverride;
use App\Models\RatePlan;
use App\Models\RecurringJournal;
use App\Models\RentalVehicle;
use App\Models\ReportSchedule;
use App\Models\ReportView;
use App\Models\Requisition;
use App\Models\RequisitionLine;
use App\Models\ReservationAmendment;
use App\Models\ReservationDeskView;
use App\Models\ReservationWaitlist;
use App\Models\ReviewTemplate;
use App\Models\Rfq;
use App\Models\RfqLine;
use App\Models\RfqQuote;
use App\Models\RfqQuoteLine;
use App\Models\RfqSupplier;
use App\Models\RmSnapshot;
use App\Models\RoomAmenity;
use App\Models\RoomAmenityLink;
use App\Models\RoomBlock;
use App\Models\RoomBlockLine;
use App\Models\RoomCount;
use App\Models\RoomImage;
use App\Models\RoomMove;
use App\Models\RoomOutage;
use App\Models\RoomType;
use App\Models\RosterWeek;
use App\Models\SalesContact;
use App\Models\SalesOpportunity;
use App\Models\ScDistributionLine;
use App\Models\SecurityIncidentUpdate;
use App\Models\SecurityWatch;
use App\Models\Setting;
use App\Models\ShiftSwap;
use App\Models\ShiftTemplate;
use App\Models\SopAcknowledgement;
use App\Models\SopDocument;
use App\Models\SopVersion;
use App\Models\SpaRoom;
use App\Models\StaffAdvance;
use App\Models\StayFixedCharge;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Models\StoreIssueLine;
use App\Models\StorePar;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierPaidHistoryArchive;
use App\Models\SupplierPrice;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Models\TalentCycle;
use App\Models\TalentInterview;
use App\Models\TalentOffer;
use App\Models\TalentProbation;
use App\Models\TalentScorecard;
use App\Models\TalentTask;
use App\Models\TimePunch;
use App\Models\TipCollection;
use App\Models\TrainingCourse;
use App\Models\TrainingRequirement;
use App\Models\TrainingSession;
use App\Models\TransportTariff;
use App\Models\TvCommand;
use App\Models\TvMessage;
use App\Models\TvMessageRead;
use App\Models\TvMovie;
use App\Models\TvPage;
use App\Models\TvProgramme;
use App\Models\TvSession;
use App\Models\TvWelcomeTemplate;
use App\Models\UpsellOffer;
use App\Models\UtilityBill;
use App\Models\Vehicle;
use App\Models\WakeupAttempt;
use App\Models\WakeupCall;
use App\Models\WhtPeriod;
use App\Models\YieldRule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Property & Utilities reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait PropertyUtilitiesReports
{
    protected function r_energy_kpis($from, $to): array
    {
        $k = \App\Services\UtilityService::kpis($from->toDateString(), $to->toDateString());
        $f = fn ($v, $d = 1) => $v === null ? '—' : number_format($v, $d);
        $rows = collect($k['rows'])->map(fn ($r, $d) => ['date' => Carbon::parse($d)->format('d-m-Y D'), 'rooms' => $r['rooms'], 'guests' => $r['guests'], 'kwh' => round($r['kwh'], 1),
            'solar' => round($r['solar'], 1), 'kwh_por' => $f($r['kwh_por']), 'water' => round($r['water'], 2), 'water_lgn' => $f($r['water_lgn'], 0), 'cost' => $r['cost'],
            'cost_por' => $r['cost_por'] === null ? '—' : money($r['cost_por']), 'cost_pct' => $r['cost_pct'] === null ? '—' : $r['cost_pct'].'%', 'co2' => round($r['co2'], 1), 'co2_por' => $f($r['co2_por'])])->values();
        $t = $k['total'];

        return ['cols' => ['date' => 'Date', 'rooms' => 'Occ. rooms', 'guests' => 'Guests', 'kwh' => 'kWh', 'solar' => 'of which solar', 'kwh_por' => 'kWh / room', 'water' => 'Water m³', 'water_lgn' => 'L / guest',
            'cost' => 'Cost', 'cost_por' => 'Cost / room', 'cost_pct' => '% revenue', 'co2' => 'kg CO₂e', 'co2_por' => 'kg CO₂ / room'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['rooms', 'guests', 'kwh', 'solar', 'water', 'cost', 'co2'],
            'summary' => ['kWh per occupied room' => $f($t['kwh_por']), 'Water per guest night' => $f($t['water_lgn'], 0).' L', 'Utility cost per occupied room' => $t['cost_por'] === null ? '—' : money($t['cost_por']),
                'Utility cost % of revenue' => $t['cost_pct'] === null ? '—' : $t['cost_pct'].'%', 'CO₂ per occupied room' => $f($t['co2_por']).' kg', 'Solar share' => $t['solar_share'].'%'],
            'note' => 'Main meters only. Occupied rooms and revenue come from the night audit (in-house stays for days not yet closed); guest-nights count adults + children in house.'];
    }

    protected function r_lease_arrears($from, $to): array
    {
        $t = $to->toDateString();
        $LB = \App\Services\LeaseBillingService::class;
        $rows = $LB::arrears($t)->map(fn ($a) => ['lease' => $a['lease']->lease_no, 'unit' => $a['lease']->unit->code, 'tenant' => $a['lease']->tenant_name, 'payer' => $a['lease']->company?->name ?? 'Individual',
            'phone' => $a['lease']->phone, 'since' => dmy($a['since']), 'b0' => $a['buckets'][0], 'b1' => $a['buckets'][1], 'b2' => $a['buckets'][2], 'b3' => $a['buckets'][3], 'overdue' => $a['amount'],
            'balance' => $a['balance'], 'deposit' => $LB::depositHeld($a['lease'], $t), 'status' => \App\Models\Lease::STATUSES[$a['lease']->status]]);

        return ['cols' => ['lease' => 'Lease', 'unit' => 'Unit', 'tenant' => 'Tenant', 'payer' => 'Payer', 'phone' => 'Phone', 'since' => 'Oldest due', 'b0' => '0–30 days', 'b1' => '31–60', 'b2' => '61–90', 'b3' => '90+',
            'overdue' => 'Overdue', 'balance' => 'Account balance', 'deposit' => 'Deposit held', 'status' => 'Lease'],
            'rows' => $rows, 'money' => ['b0', 'b1', 'b2', 'b3', 'overdue', 'balance', 'deposit'], 'sum' => ['b0', 'b1', 'b2', 'b3', 'overdue', 'balance', 'deposit'],
            'note' => 'As at '.dmy($t).', after '.(int) \App\Services\LeaseService::cfg('lease_grace_days').' days\' grace. Tenant receivables in total: '.money($LB::controlBalance($t)).'. The deposit is security, not a payment – apply it only at move-out or with the tenant\'s agreement.'];
    }

    protected function r_lease_expiry($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $rows = collect();
        foreach (\App\Models\Lease::with('unit', 'company')->whereIn('status', ['active', 'notice'])->get() as $l) {
            $end = $l->lastDay()->toDateString();
            if ($end >= $f && $end <= $t) {
                $rows->push(['date' => $end, 'event' => $l->status === 'notice' ? 'Move-out (notice by '.$l->notice_by.')' : ($l->auto_renew ? 'Term ends – auto-renews' : 'Term ends'), 'l' => $l,
                    'action' => $l->status === 'notice' ? 'Book the move-out inspection & meter readings; market the unit' : ($l->auto_renew ? 'Renews 30 days before the end' : 'Agree renewal or take notice')]);
            }
            if ($l->next_review_on && $l->next_review_on->toDateString() >= $f && $l->next_review_on->toDateString() <= $t && $l->next_review_on->lte($l->end_date)) {
                $rows->push(['date' => $l->next_review_on->toDateString(), 'event' => 'Rent review +'.rtrim(rtrim(number_format($l->escalation_pct, 2), '0'), '.').'%', 'l' => $l,
                    'action' => 'New rent '.money(round($l->rent * (1 + $l->escalation_pct / 100), 2)).' applied automatically – notify the tenant']);
            }
        }
        foreach (\App\Models\Lease::with('unit', 'company')->where('status', 'draft')->whereDate('start_date', '>=', $f)->whereDate('start_date', '<=', $t)->get() as $l) {
            $rows->push(['date' => $l->start_date->toDateString(), 'event' => 'Move-in', 'l' => $l, 'action' => $l->signed_at ? 'Prepare the unit, keys & opening meter readings' : 'Get the agreement signed first']);
        }
        $rows = $rows->sortBy('date')->values()->map(fn ($r) => ['date' => dmy($r['date']), 'event' => $r['event'], 'lease' => $r['l']->lease_no, 'unit' => $r['l']->unit->code, 'tenant' => $r['l']->tenant_name.($r['l']->company ? ' ('.$r['l']->company->name.')' : ''),
            'rent' => (float) $r['l']->rent, 'action' => $r['action']]);

        return ['cols' => ['date' => 'Date', 'event' => 'Event', 'lease' => 'Lease', 'unit' => 'Unit', 'tenant' => 'Tenant', 'rent' => 'Rent / mo', 'action' => 'Action'], 'rows' => $rows, 'money' => ['rent'],
            'note' => 'Pick a forward date range (e.g. the next 90 days) to plan renewals and move-outs.'];
    }

    protected function r_lease_inspections($from, $to): array
    {
        $rows = LeaseInspection::with('lease.leaseUnit.inspectedBy')->whereBetween('inspected_at', [$from, $to])->get()->map(fn ($r) => [
            'lease' => optional($r->lease)->name, 'unit' => optional($r->leaseUnit)->name,
            'inspected' => $r->inspected_at, 'result' => $r->result, 'findings' => $r->findings,
            'status' => $r->status, 'inspected_by' => optional($r->inspectedBy)->name,
        ]);
        return ['cols' => ['lease' => 'Lease', 'unit' => 'Unit', 'inspected' => 'Inspected', 'result' => 'Result', 'findings' => 'Findings', 'status' => 'Status', 'inspected_by' => 'Inspected By'], 'rows' => $rows, 'note' => 'Lease inspection register.'];
    }

    protected function r_lease_meter_readings($from, $to): array
    {
        $rows = LeaseMeterReading::with('lease.meterType')->whereBetween('read_date', [$from, $to])->get()->map(fn ($r) => [
            'lease' => optional($r->lease)->name, 'meter_type' => optional($r->meterType)->name,
            'read_date' => $r->read_date, 'previous' => $r->previous_reading, 'current' => $r->current_reading,
            'consumption' => $r->consumption, 'unit' => $r->unit, 'rate' => $r->rate, 'amount' => $r->amount, 'status' => $r->status,
        ]);
        return ['cols' => ['lease' => 'Lease', 'meter' => 'Meter Type', 'read_date' => 'Read Date', 'previous' => 'Previous', 'current' => 'Current', 'consumption' => 'Consumption', 'rate' => 'Rate', 'amount' => 'Amount', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate', 'amount'], 'sum' => ['amount'], 'note' => 'Lease meter readings with consumption.'];
    }

    protected function r_lease_rent_roll($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $LB = \App\Services\LeaseBillingService::class;
        $leases = \App\Models\Lease::whereIn('status', ['active', 'notice', 'ended', 'terminated'])->whereDate('start_date', '<=', $t)->get()
            ->filter(fn ($l) => $l->lastDay()->toDateString() >= $f)->groupBy('lease_unit_id');
        $rows = \App\Models\LeaseUnit::where('is_active', true)->orderBy('code')->get()->map(function ($u) use ($leases, $f, $t, $LB) {
            $l = ($leases[$u->id] ?? collect())->sortByDesc('start_date')->first();
            $inv = $l ? (float) \App\Models\LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->whereIn('type', ['rent', 'service_charge'])->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->sum('amount')
                + (float) \App\Models\LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->where('type', 'credit')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->whereHas('creditOf', fn ($q) => $q->whereIn('type', ['rent', 'service_charge']))->sum('amount') : 0;
            $col = $l ? (float) \App\Models\LeasePayment::where('lease_id', $l->id)->whereNull('voided_at')->where('kind', 'payment')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->selectRaw('SUM(amount + wht_amount) s')->value('s') : 0;

            return ['unit' => $u->code, 'type' => \App\Models\LeaseUnit::TYPES[$u->type], 'tenant' => $l ? $l->tenant_name.($l->company_id ? ' ('.$l->company?->name.')' : '') : '— '.strtolower(\App\Models\LeaseUnit::STATUSES[$u->status]).' —',
                'class' => $l ? ucfirst($l->tax_class) : ucfirst($u->tax_class), 'term' => $l ? dmy($l->start_date).' – '.dmy($l->lastDay()) : '', 'cycle' => $l ? \App\Models\Lease::CYCLES[$l->cycle_months] : '',
                'rent' => $l ? (float) $l->rent : 0, 'sc' => $l ? (float) $l->service_charge : 0, 'deposit' => $l ? $LB::depositHeld($l, $t) : 0,
                'invoiced' => round($inv, 2), 'collected' => round($col, 2), 'balance' => $l ? $LB::balance($l, $t) : 0,
                'status' => $l ? \App\Models\Lease::STATUSES[$l->status] : \App\Models\LeaseUnit::STATUSES[$u->status]];
        });
        $let = $rows->filter(fn ($r) => in_array($r['status'], ['Active', 'Notice given'], true))->count();
        $vac = $rows->where('status', 'Vacant')->count();

        return ['cols' => ['unit' => 'Unit', 'type' => 'Type', 'tenant' => 'Tenant', 'class' => 'Tax class', 'term' => 'Term', 'cycle' => 'Cycle', 'rent' => 'Rent / mo', 'sc' => 'Service / mo', 'deposit' => 'Deposit held',
            'invoiced' => 'Invoiced (net)', 'collected' => 'Collected', 'balance' => 'Balance', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['rent', 'sc', 'deposit', 'invoiced', 'collected', 'balance'], 'sum' => ['rent', 'sc', 'deposit', 'invoiced', 'collected', 'balance'],
            'note' => "{$let} of {$rows->count()} units let (".($rows->count() ? round($let * 100 / $rows->count(), 1) : 0)."%), {$vac} vacant. Invoiced = rent & service charge net of tax and credit notes; collected includes tax withheld by company tenants."];
    }

    protected function r_meter_readings($from, $to): array
    {
        $rows = MeterReading::with('meter.meterType.location')->whereBetween('read_date', [$from, $to])->get()->map(fn ($r) => [
            'meter' => optional($r->meter)->name, 'type' => optional($r->meterType)->name,
            'location' => optional($r->location)->name, 'read_date' => $r->read_date,
            'previous' => $r->previous_reading, 'current' => $r->current_reading, 'consumption' => $r->consumption,
            'unit' => $r->unit, 'rate' => $r->rate, 'amount' => $r->amount, 'status' => $r->status,
        ]);
        return ['cols' => ['meter' => 'Meter', 'type' => 'Type', 'location' => 'Location', 'read_date' => 'Read Date', 'previous' => 'Previous', 'current' => 'Current', 'consumption' => 'Consumption', 'rate' => 'Rate', 'amount' => 'Amount', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate', 'amount'], 'sum' => ['amount'], 'note' => 'Utility meter readings with consumption.'];
    }

    protected function r_utility_bill_reconciliation($from, $to): array
    {
        $rows = \App\Models\UtilityBill::with('meter', 'supplierInvoice')->whereDate('bill_date', '>=', $from->toDateString())->whereDate('bill_date', '<=', $to->toDateString())->orderBy('bill_date')->get()
            ->map(fn ($b) => ['bill' => $b->bill_no, 'date' => $b->bill_date->format('d-m-Y'), 'meter' => $b->meter->code, 'period' => $b->period_from->format('d-m').' – '.$b->period_to->format('d-m-Y'),
                'billed' => round($b->units_billed, 1), 'metered' => round($b->metered_units, 1), 'var' => round($b->varianceUnits(), 1), 'var_pct' => $b->variancePct() === null ? '—' : $b->variancePct().'%',
                'energy' => $b->energy_charges, 'fixed' => $b->fixed_charges, 'taxes' => $b->taxes, 'total' => $b->total, 'rate' => number_format($b->effectiveRate(), 2), 'tariff' => number_format($b->meter->rate, 2),
                'ap' => $b->supplierInvoice?->doc_no ?? 'not booked']);
        $m = ['energy', 'fixed', 'taxes', 'total'];

        return ['cols' => ['bill' => 'Bill', 'date' => 'Date', 'meter' => 'Meter', 'period' => 'Period', 'billed' => 'Billed', 'metered' => 'Metered', 'var' => 'Variance', 'var_pct' => 'Var %',
            'energy' => 'Energy', 'fixed' => 'Fixed', 'taxes' => 'Taxes', 'total' => 'Total', 'rate' => 'Eff. rate', 'tariff' => 'Tariff', 'ap' => 'AP bill'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'summary' => ['Bills' => (string) $rows->count(), 'Total billed' => money($rows->sum('total')), 'Booked to AP' => money($rows->where('ap', '!=', 'not booked')->sum('total')),
                'Over ±3% variance' => (string) $rows->filter(fn ($r) => $r['var_pct'] !== '—' && abs((float) $r['var_pct']) > 3)->count()],
            'note' => 'Metered = consumption of readings after the period start up to the period end. A variance above ±3% usually means a missed reading, a wrong multiplier or a billing error.'];
    }

    protected function r_utility_bills($from, $to): array
    {
        // Rewritten against the real schema: meter/supplier relations; units_billed/energy+fixed+taxes/total; there is no utilityType table.
        $rows = UtilityBill::with('meter', 'supplier')->whereBetween('bill_date', [$from, $to])->orderByDesc('bill_date')->get()->map(fn ($r) => [
            'meter' => optional($r->meter)->name, 'bill_no' => $r->bill_no, 'date' => $r->bill_date,
            'period_from' => $r->period_from, 'period_to' => $r->period_to,
            'units' => (float) $r->units_billed, 'energy' => (float) $r->energy_charges,
            'fixed' => (float) $r->fixed_charges, 'taxes' => (float) $r->taxes,
            'total' => (float) $r->total, 'supplier' => optional($r->supplier)->name,
        ]);
        return ['cols' => ['meter' => 'Meter', 'bill_no' => 'Bill No', 'date' => 'Date', 'period_from' => 'Period From', 'period_to' => 'Period To', 'units' => 'Units', 'energy' => 'Energy', 'fixed' => 'Fixed', 'taxes' => 'Taxes', 'total' => 'Total', 'supplier' => 'Supplier'], 'rows' => $rows, 'money' => ['energy', 'fixed', 'taxes', 'total'], 'sum' => ['total'], 'note' => 'Utility bill register.'];
    }

    protected function r_utility_consumption($from, $to): array
    {
        $rows = \App\Services\UtilityService::consumption($from->toDateString(), $to->toDateString());
        $byId = $rows->keyBy(fn ($r) => $r['meter']->id);
        $out = $rows->map(function ($r) use ($byId) {
            $m = $r['meter'];
            $kids = $byId->filter(fn ($k) => $k['meter']->parent_id === $m->id);

            return ['code' => $m->code, 'meter' => $m->name, 'utility' => $m->utilityLabel(), 'area' => $m->area, 'type' => $m->is_main ? 'Main' : 'Sub-meter of '.($m->parent?->code ?? '—'),
                'n' => $r['n'], 'days' => $r['days'], 'cons' => number_format($r['cons'], 1).' '.$m->unit, 'per_day' => $r['days'] ? number_format($r['cons'] / $r['days'], 1) : '—',
                'sub' => $kids->count() && $r['cons'] > 0 ? round($kids->sum('cons') / $r['cons'] * 100, 1).'%' : '', 'est' => $r['est'], 'flags' => $r['flags'], 'cost' => $r['cost']];
        })->values();
        $summary = [];
        foreach (\App\Services\UtilityService::byUtility($from->toDateString(), $to->toDateString()) as $u => $v) {
            if ($v['cons'] > 0) {
                $summary[\App\Models\UtilityMeter::UTILITIES[$u][0]] = number_format($v['cons'], 1).' '.\App\Models\UtilityMeter::UTILITIES[$u][1].' · '.money($v['cost']);
            }
        }
        $summary['Total cost (main meters)'] = money($rows->filter(fn ($r) => $r['meter']->is_main)->sum('cost'));

        return ['cols' => ['code' => 'Meter', 'meter' => 'Name', 'utility' => 'Utility', 'area' => 'Area', 'type' => 'Type', 'n' => 'Readings', 'days' => 'Days', 'cons' => 'Consumption', 'per_day' => 'Per day',
            'sub' => 'Sub-metered', 'est' => 'Estimated', 'flags' => 'Anomalies', 'cost' => 'Cost'], 'rows' => $out, 'money' => ['cost'], 'sum' => ['n', 'est', 'flags'], 'summary' => $summary,
            'note' => 'Consumption = (reading − previous reading) × multiplier, with register roll-over and meter replacement handled. Sub-meter cost is informational – the hotel total counts main meters only. "Sub-metered" = share of the main meter measured by its sub-meters.'];
    }

}
