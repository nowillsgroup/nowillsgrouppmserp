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
 * Health Club & Spa reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait HealthClubSpaReports
{
    protected function r_club_attendance($from, $to): array
    {
        $v = \App\Models\ClubVisit::whereBetween('checked_in_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $day = $v->filter(fn ($x) => $x->checked_in_at->isSameDay($d));
            $ok = $day->where('denied', false);
            $row = ['date' => $d->format('D d M')];
            foreach (\App\Models\ClubPlan::FACILITIES as $k => $l) {
                $row[$k] = (int) $ok->where('facility', $k)->sum('pax');
            }
            foreach (['member', 'resident', 'day_pass', 'guest'] as $ty) {
                $row['t_'.$ty] = (int) $ok->where('visitor_type', $ty)->sum('pax');
            }
            $row['total'] = (int) $ok->sum('pax');
            $row['denied'] = $day->where('denied', true)->count();
            $row['override'] = $ok->whereNotNull('override_reason')->count();
            $row['classes'] = \App\Models\ClubBooking::where('date', $d->toDateString())->where('status', 'attended')->count();
            $rows->push($row);
        }
        $cols = ['date' => 'Date'] + collect(\App\Models\ClubPlan::FACILITIES)->except('classes')->all()
            + ['t_member' => 'Members', 't_resident' => 'Hotel guests', 't_day_pass' => 'Day passes', 't_guest' => "Members' guests", 'total' => 'Total visits', 'denied' => 'Refused', 'override' => 'Overrides', 'classes' => 'Class attendances'];
        $rows = $rows->map(fn ($r) => array_diff_key($r, ['classes_fac' => 1]));
        $mins = $v->where('denied', false)->filter(fn ($x) => $x->checked_out_at)->map(fn ($x) => $x->checked_in_at->diffInMinutes($x->checked_out_at));

        return ['cols' => $cols, 'rows' => $rows, 'sum' => array_values(array_diff(array_keys($cols), ['date'])),
            'note' => 'Average stay '.($mins->count() ? round($mins->avg()).' min' : '—').'. Visits count people (a family check-in of 3 counts 3).'];
    }

    protected function r_club_classes($from, $to): array
    {
        // Rewritten against the real schema: facility/employee_id/instructor/weekday/start_time/duration_min/capacity/residents.
        $rows = \App\Models\ClubClass::with('employee')->orderBy('weekday')->orderBy('start_time')->get()->map(fn ($r) => [
            'name' => $r->name, 'facility' => $r->facility, 'weekday' => $r->weekday,
            'start_time' => $r->start_time, 'duration' => $r->duration_min,
            'instructor' => $r->instructorName(), 'capacity' => $r->capacity,
            'residents' => $r->residents ? 'Yes' : 'No', 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'facility' => 'Facility', 'weekday' => 'Day', 'start_time' => 'Start', 'duration' => 'Min', 'instructor' => 'Instructor', 'capacity' => 'Capacity', 'residents' => 'Residents', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Club class schedule.'];
    }

    protected function r_club_members($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $ms = \App\Models\ClubMembership::with('plan', 'company')->withCount(['members' => fn ($q) => $q->where('is_active', true)])
            ->whereDate('start_date', '<=', $t)->where(fn ($q) => $q->whereDate('end_date', '>=', $f)->orWhereIn('status', ['active', 'frozen']))->orderBy('membership_no')->get();
        $visits = \App\Models\ClubVisit::join('club_members as cm', 'cm.id', '=', 'club_visits.club_member_id')->where('club_visits.denied', false)
            ->whereBetween('club_visits.checked_in_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->selectRaw('cm.club_membership_id m, COUNT(*) n')->groupBy('cm.club_membership_id')->pluck('n', 'm');
        $rows = $ms->map(fn ($m) => ['no' => $m->membership_no, 'holder' => $m->holder_name.($m->company ? ' ('.$m->company->name.')' : ''), 'plan' => $m->plan->name, 'cards' => $m->members_count,
            'start' => dmy($m->start_date), 'end' => dmy($m->end_date), 'status' => \App\Models\ClubMembership::STATUSES[$m->status].($m->cancel_mode === 'term_end' && $m->status !== 'cancelled' ? ' (notice)' : ''),
            'visits' => (int) ($visits[$m->id] ?? 0), 'balance' => \App\Services\ClubBillingService::balance($m)]);
        $joined = $ms->filter(fn ($m) => $m->start_date->between($from, $to) && ! \App\Models\ClubCharge::where('club_membership_id', $m->id)->where('type', 'subscription')->whereDate('period_start', '<', $m->start_date)->exists())->count();
        $renewed = \App\Models\ClubCharge::where('type', 'subscription')->whereNull('cancelled_at')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->where('description', 'like', '%renewal%')->count();
        $left = $ms->filter(fn ($m) => ($m->status === 'cancelled' && $m->cancelled_on && $m->cancelled_on->between($from, $to)) || ($m->status === 'expired' && $m->end_date->between($from, $to)))->count();

        return ['cols' => ['no' => 'Membership', 'holder' => 'Holder', 'plan' => 'Plan', 'cards' => 'Cards', 'start' => 'Since', 'end' => 'Term ends', 'status' => 'Status', 'visits' => 'Visits', 'balance' => 'Balance'],
            'rows' => $rows, 'money' => ['balance'], 'sum' => ['cards', 'visits', 'balance'], 'note' => "{$joined} joined, {$renewed} renewal invoice(s), {$left} left or lapsed in the period. Balance: positive = owed by the member."];
    }

    protected function r_club_revenue($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $ch = \App\Models\ClubCharge::with('creditOf')->whereNull('cancelled_at')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->get();
        $dp = \App\Models\ClubDayPass::whereNull('cancelled_at')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->get();
        $pay = \App\Models\ClubPayment::whereNull('voided_at')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->get();
        $subCredits = $ch->where('type', 'credit')->filter(fn ($c) => $c->creditOf?->type === 'subscription');
        $otherCredits = $ch->where('type', 'credit')->reject(fn ($c) => $c->creditOf?->type === 'subscription');
        $rows = collect([
            ['line' => 'Subscriptions invoiced (deferred to 2130)', 'net' => (float) $ch->where('type', 'subscription')->sum('amount'), 'tax' => (float) $ch->where('type', 'subscription')->sum('tax'), 'count' => $ch->where('type', 'subscription')->count()],
            ['line' => 'Credit notes on subscriptions', 'net' => (float) $subCredits->sum('amount'), 'tax' => (float) $subCredits->sum('tax'), 'count' => $subCredits->count()],
            ['line' => 'Membership revenue earned (4042)', 'net' => \App\Services\ClubBillingService::recognised($f, $t), 'tax' => 0, 'count' => null],
            ['line' => 'Joining fees (4043)', 'net' => (float) $ch->where('type', 'joining')->sum('amount'), 'tax' => (float) $ch->where('type', 'joining')->sum('tax'), 'count' => $ch->where('type', 'joining')->count()],
            ['line' => 'Guest passes & other charges (4043)', 'net' => (float) $ch->whereIn('type', ['guest_pass', 'other'])->sum('amount') + (float) $otherCredits->sum('amount'), 'tax' => (float) $ch->whereIn('type', ['guest_pass', 'other'])->sum('tax') + (float) $otherCredits->sum('tax'), 'count' => $ch->whereIn('type', ['guest_pass', 'other'])->count()],
            ['line' => 'Day passes paid at the desk (4043)', 'net' => (float) $dp->whereNull('reservation_room_id')->sum('amount'), 'tax' => (float) $dp->whereNull('reservation_room_id')->sum('tax'), 'count' => $dp->whereNull('reservation_room_id')->count()],
            ['line' => 'Day passes charged to rooms (via folio)', 'net' => (float) $dp->whereNotNull('reservation_room_id')->sum('amount'), 'tax' => (float) $dp->whereNotNull('reservation_room_id')->sum('tax'), 'count' => $dp->whereNotNull('reservation_room_id')->count()],
            ['line' => 'Member receipts', 'net' => (float) $pay->where('kind', 'payment')->sum('amount'), 'tax' => null, 'count' => $pay->where('kind', 'payment')->count()],
            ['line' => 'Refunds to members', 'net' => -(float) $pay->where('kind', 'refund')->sum('amount'), 'tax' => null, 'count' => $pay->where('kind', 'refund')->count()],
        ])->map(fn ($r) => $r + ['total' => $r['tax'] === null ? null : round($r['net'] + $r['tax'], 2)]);
        $earned = \App\Services\ClubBillingService::recognised($f, $t) + $rows[3]['net'] + $rows[4]['net'] + $rows[5]['net'] + $rows[6]['net'];

        return ['cols' => ['line' => 'Line', 'count' => 'Documents', 'net' => 'Net', 'tax' => 'Tax', 'total' => 'Total'], 'rows' => $rows, 'money' => ['net', 'tax', 'total'],
            'note' => 'Club revenue earned in the period '.money($earned).' (net of tax). Membership fees received in advance at '.dmy($t).': '.money(\App\Services\ClubBillingService::unearned($t)).'. Members receivable: '.money(\App\Services\ClubBillingService::controlBalance($t)).'.'];
    }

    protected function r_club_ticket_payments($from, $to): array
    {
        $rows = ClubTicketPayment::with('ticketSale.paymentMode')->whereBetween('paid_at', [$from, $to])->get()->map(fn ($r) => [
            'sale' => optional($r->ticketSale)->reference, 'mode' => optional($r->paymentMode)->name,
            'amount' => $r->amount, 'paid' => $r->paid_at, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['sale' => 'Sale Ref', 'mode' => 'Payment Mode', 'amount' => 'Amount', 'paid' => 'Paid', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Club ticket payment register.'];
    }

    protected function r_club_ticket_sale_items($from, $to): array
    {
        // Rewritten against the real schema: sale()/type() relations; the parent carries sale_no/sold_at/guest_name/status; lines are qty/unit_price/line_total.
        $rows = ClubTicketSaleItem::with('sale', 'type')->whereHas('sale', fn ($q) => $q->whereBetween('sold_at', [$from, $to]))->get()->map(fn ($r) => [
            'sale' => optional($r->sale)->sale_no, 'guest' => optional($r->sale)->guest_name,
            'type' => optional($r->type)->name, 'description' => $r->description,
            'quantity' => $r->qty, 'unit_price' => (float) $r->unit_price,
            'amount' => (float) $r->line_total, 'status' => optional($r->sale)->status,
        ]);
        return ['cols' => ['sale' => 'Sale No', 'guest' => 'Guest', 'type' => 'Type', 'description' => 'Description', 'quantity' => 'Qty', 'unit_price' => 'Unit Price', 'amount' => 'Total', 'status' => 'Status'], 'rows' => $rows, 'money' => ['unit_price', 'amount'], 'sum' => ['amount'], 'note' => 'Club ticket sale line items.'];
    }

    protected function r_club_ticket_sales($from, $to): array
    {
        $rows = ClubTicketSale::with('guest.ticketType.paymentMode')->whereBetween('sold_at', [$from, $to])->get()->map(fn ($r) => [
            'guest' => optional($r->guest)->name, 'type' => optional($r->ticketType)->name,
            'mode' => optional($r->paymentMode)->name, 'quantity' => $r->quantity,
            'amount' => $r->total_amount, 'sold' => $r->sold_at, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['guest' => 'Guest', 'type' => 'Type', 'mode' => 'Payment Mode', 'quantity' => 'Qty', 'amount' => 'Amount', 'sold' => 'Sold', 'status' => 'Status'], 'rows' => $rows, 'money' => ['total_amount'], 'sum' => ['total_amount'], 'note' => 'Club ticket sales register.'];
    }

    protected function r_club_ticket_types($from, $to): array
    {
        // Rewritten against the real schema: club_ticket_types (code, category, pax_type, price, tax_rate, issues_pass, is_active, sort) — no clubPlan relation exists.
        $rows = ClubTicketType::orderBy('sort')->orderBy('name')->get()->map(fn ($r) => [
            'code' => $r->code, 'name' => $r->name,
            'category' => ClubTicketType::CATEGORIES[$r->category] ?? $r->category,
            'pax_type' => ClubTicketType::PAX_TYPES[$r->pax_type] ?? $r->pax_type,
            'price' => (float) $r->price, 'tax_rate' => $r->tax_rate !== null ? (float) $r->tax_rate : null,
            'issues_pass' => $r->issues_pass ? 'Yes' : 'No', 'status' => $r->is_active ? 'Active' : 'Inactive',
        ]);
        return ['cols' => ['code' => 'Code', 'name' => 'Name', 'category' => 'Category', 'pax_type' => 'Pax', 'price' => 'Price', 'tax_rate' => 'Tax %', 'issues_pass' => 'Issues Pass', 'status' => 'Status'], 'rows' => $rows, 'money' => ['price'], 'note' => 'Club ticket type catalog.'];
    }

    protected function r_spa_revenue($from, $to): array
    {
        $appts = \App\Models\SpaAppointment::with('service', 'therapist')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get();
        $rows = $appts->groupBy(fn ($a) => $a->service->name.'|'.($a->therapist?->name ?? '—'))->map(function ($g) {
            $done = $g->where('status', 'completed');
            $paid = $done->where('settlement', '!=', 'complimentary');

            return ['service' => $g->first()->service->name, 'cat' => \App\Models\SpaService::CATEGORIES[$g->first()->service->category] ?? '', 'therapist' => $g->first()->therapist?->name ?? '—',
                'booked' => $g->count(), 'done' => $done->count(), 'pax' => (int) $done->sum('pax'), 'lost' => $g->whereIn('status', ['cancelled', 'no_show'])->count(),
                'room' => round($paid->where('settlement', 'room_post')->sum(fn ($a) => $a->net()), 2), 'direct' => round($paid->where('settlement', 'payment')->sum(fn ($a) => $a->net()), 2),
                'comp' => round($done->where('settlement', 'complimentary')->sum(fn ($a) => $a->net()), 2), 'revenue' => round($paid->sum(fn ($a) => $a->net()), 2)];
        })->sortByDesc('revenue')->values();

        return ['cols' => ['service' => 'Service', 'cat' => 'Category', 'therapist' => 'Therapist', 'booked' => 'Bookings', 'done' => 'Completed', 'pax' => 'Guests', 'lost' => 'Cancelled / no-show',
            'room' => 'Posted to room', 'direct' => 'Direct paid', 'comp' => 'Complimentary (value)', 'revenue' => 'Revenue'],
            'rows' => $rows, 'money' => ['room', 'direct', 'comp', 'revenue'], 'sum' => ['booked', 'done', 'pax', 'lost', 'room', 'direct', 'comp', 'revenue'],
            'chart' => ['label' => 'Revenue', 'key' => 'revenue', 'x' => 'service'], 'note' => 'Revenue is before tax; tax is shown on the folio / invoice.'];
    }

}
