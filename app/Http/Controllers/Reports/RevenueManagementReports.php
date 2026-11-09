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
 * Revenue Management reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait RevenueManagementReports
{
    protected function r_rate_contract_blackouts($from, $to): array
    {
        $rows = RateContractBlackout::with('rateContract.blackoutType')->whereBetween('from_date', [$from, $to])->get()->map(fn ($r) => [
            'contract' => optional($r->rateContract)->name, 'type' => optional($r->blackoutType)->name,
            'from' => $r->from_date, 'to' => $r->to_date, 'reason' => $r->reason, 'status' => $r->status,
        ]);
        return ['cols' => ['contract' => 'Contract', 'type' => 'Type', 'from' => 'From', 'to' => 'To', 'reason' => 'Reason', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Rate contract blackout dates.'];
    }

    protected function r_rate_contract_rates($from, $to): array
    {
        $rows = RateContractRate::with('rateContract.roomType.ratePlan')->whereBetween('effective_from', [$from, $to])->get()->map(fn ($r) => [
            'contract' => optional($r->rateContract)->name, 'room_type' => optional($r->roomType)->name,
            'plan' => optional($r->ratePlan)->name, 'rate' => $r->rate, 'currency' => $r->currency,
            'meal' => $r->meal_plan, 'effective' => $r->effective_from, 'status' => $r->status,
        ]);
        return ['cols' => ['contract' => 'Contract', 'room_type' => 'Room Type', 'plan' => 'Plan', 'rate' => 'Rate', 'currency' => 'Currency', 'meal' => 'Meal', 'effective' => 'Effective', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate'], 'note' => 'Rate contract rate mappings.'];
    }

    protected function r_rate_overrides($from, $to): array
    {
        $rows = RateOverride::with('roomType.ratePlan.overrideType')->whereBetween('effective_from', [$from, $to])->get()->map(fn ($r) => [
            'room_type' => optional($r->roomType)->name, 'plan' => optional($r->ratePlan)->name,
            'type' => optional($r->overrideType)->name, 'override' => $r->override_value,
            'effective' => $r->effective_from, 'reason' => $r->reason, 'status' => $r->status,
        ]);
        return ['cols' => ['room_type' => 'Room Type', 'plan' => 'Rate Plan', 'type' => 'Type', 'override' => 'Override', 'effective' => 'Effective', 'reason' => 'Reason', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Rate override register.'];
    }

    protected function r_rate_plans($from, $to): array
    {
        // Rewritten against the real schema: meal_plan is a string column; rate/extra_adult_rate/extra_child_rate/tax_inclusive/is_active.
        $rows = RatePlan::with('roomType')->orderBy('name')->get()->map(fn ($r) => [
            'code' => $r->code, 'name' => $r->name, 'room_type' => optional($r->roomType)->name,
            'meal' => $r->meal_plan, 'rate' => (float) $r->rate,
            'extra_adult' => (float) $r->extra_adult_rate, 'extra_child' => (float) $r->extra_child_rate,
            'tax_inclusive' => $r->tax_inclusive ? 'Yes' : 'No', 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['code' => 'Code', 'name' => 'Name', 'room_type' => 'Room Type', 'meal' => 'Meal Plan', 'rate' => 'Rate', 'extra_adult' => 'Extra Adult', 'extra_child' => 'Extra Child', 'tax_inclusive' => 'Tax Incl', 'active' => 'Active'], 'rows' => $rows, 'money' => ['rate', 'extra_adult', 'extra_child'], 'note' => 'Rate plan catalog.'];
    }

    protected function r_rm_forecast($from, $to): array
    {
        $rows = collect(\App\Services\RevenueService::forecast($from, $from->diffInDays($to) + 1))->map(fn ($r) => [
            'date' => Carbon::parse($r['date'])->format('d-m-Y'), 'dow' => $r['dow'], 'lead' => $r['lead'], 'rooms' => $r['rooms'], 'held' => $r['held'], 'occ' => $r['occ'],
            'adr' => $r['adr'], 'revenue' => $r['revenue'], 'pickup7' => $r['pickup7'] ?? '', 'fc_rooms' => $r['fc_rooms'], 'fc_occ' => $r['fc_occ'], 'fc_revenue' => $r['fc_revenue'],
        ])->values();

        return ['cols' => ['date' => 'Date', 'dow' => 'Day', 'lead' => 'Lead (d)', 'rooms' => 'Booked', 'held' => 'Held', 'occ' => 'OTB occ %', 'adr' => 'ADR', 'revenue' => 'Revenue',
            'pickup7' => 'Pickup 7d', 'fc_rooms' => 'Forecast rooms', 'fc_occ' => 'Forecast occ %', 'fc_revenue' => 'Forecast revenue'],
            'rows' => $rows, 'money' => ['adr', 'revenue', 'fc_revenue'], 'sum' => ['rooms', 'held', 'revenue', 'fc_rooms', 'fc_revenue'],
            'chart' => ['label' => 'Forecast occupancy %', 'key' => 'fc_occ', 'x' => 'date']];
    }

    protected function r_rm_pickup($from, $to): array
    {
        $res = Reservation::with('rooms')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()->groupBy(fn ($r) => $r->created_at->toDateString());
        $cx = Reservation::with('rooms')->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()->groupBy(fn ($r) => $r->cancelled_at->toDateString());
        $nights = fn ($g) => $g->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights));
        $value = fn ($g) => $g->sum(fn ($r) => $r->rooms->sum(fn ($s) => $s->nights * $s->nightlyTotal()));
        $rows = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $g = $res[$d->toDateString()] ?? collect();
            $c = $cx[$d->toDateString()] ?? collect();
            $n = $nights($g);
            $rows[] = ['date' => $d->format('d-m-Y'), 'dow' => $d->format('D'), 'bookings' => $g->count(), 'nights' => $n, 'revenue' => round($value($g), 2),
                'rate' => $n ? round($value($g) / $n, 2) : 0, 'lead' => $g->count() ? round($g->avg(fn ($r) => max(0, $r->created_at->copy()->startOfDay()->diffInDays($r->arrival_date, false))), 1) : '',
                'cx' => $c->count(), 'cx_nights' => $nights($c), 'cx_revenue' => round($value($c), 2), 'net' => $n - $nights($c)];
        }

        return ['cols' => ['date' => 'Booked on', 'dow' => 'Day', 'bookings' => 'Bookings', 'nights' => 'Room nights', 'revenue' => 'Room revenue', 'rate' => 'Avg rate', 'lead' => 'Avg lead (d)',
            'cx' => 'Cancellations', 'cx_nights' => 'Nights lost', 'cx_revenue' => 'Revenue lost', 'net' => 'Net nights'],
            'rows' => collect($rows), 'money' => ['revenue', 'rate', 'cx_revenue'], 'sum' => ['bookings', 'nights', 'revenue', 'cx', 'cx_nights', 'cx_revenue', 'net'],
            'chart' => ['label' => 'Room nights booked', 'key' => 'nights', 'x' => 'date']];
    }

    protected function r_rm_snapshots($from, $to): array
    {
        // Rewritten against the real schema: rooms/revenue per snapshot_date + stay_date + room_type; roomType() relation added to the model.
        $rows = \App\Models\RmSnapshot::with('roomType')->whereBetween('snapshot_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->orderBy('snapshot_date')->orderBy('stay_date')->get()->map(fn ($r) => [
            'snapshot_date' => $r->snapshot_date, 'stay_date' => $r->stay_date,
            'room_type' => optional($r->roomType)->name, 'rooms' => $r->rooms,
            'revenue' => (float) $r->revenue,
        ]);
        return ['cols' => ['snapshot_date' => 'Snapshot', 'stay_date' => 'Stay Date', 'room_type' => 'Room Type', 'rooms' => 'Rooms', 'revenue' => 'Revenue'], 'rows' => $rows, 'money' => ['revenue'], 'sum' => ['revenue'], 'note' => 'Revenue management on-the-books snapshots by stay date.'];
    }

    protected function r_yield_rules($from, $to): array
    {
        $rows = YieldRule::with('roomType.ratePlan.yieldRuleType')->get()->map(fn ($r) => [
            'room_type' => optional($r->roomType)->name, 'plan' => optional($r->ratePlan)->name,
            'type' => optional($r->yieldRuleType)->name, 'threshold_pct' => $r->threshold_pct,
            'adjustment' => $r->adjustment_pct, 'min_stay' => $r->min_stay, 'status' => $r->status,
        ]);
        return ['cols' => ['room_type' => 'Room Type', 'plan' => 'Plan', 'type' => 'Type', 'threshold' => 'Threshold %', 'adjustment' => 'Adjustment %', 'min_stay' => 'Min Stay', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Revenue management yield rules.'];
    }

}
