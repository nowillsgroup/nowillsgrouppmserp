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
 * Front Office reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait FrontOfficeReports
{
    protected function r_logbook_register($from, $to): array
    {
        $E = \App\Models\LogEntry::class;
        $list = $E::with('author', 'resolver', 'stay.room', 'room')->withCount('reads')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->orderBy('id')->get();
        $rows = $list->map(fn ($e) => ['date' => $e->business_date->format('d M Y'), 'time' => $e->created_at->format('H:i'), 'shift' => $E::SHIFTS[$e->shift] ?? $e->shift,
            'dept' => $E::DEPARTMENTS[$e->department] ?? $e->department, 'cat' => $e->categoryLabel(), 'prio' => $E::PRIORITIES[$e->priority] ?? $e->priority,
            'title' => $e->title, 'room' => (string) ($e->stay?->room?->room_no ?? $e->room?->room_no), 'by' => $e->source === 'system' ? 'System' : (string) $e->author?->name,
            'status' => ['note' => 'Note', 'open' => 'Open', 'resolved' => 'Resolved'][$e->status] ?? $e->status,
            'hours' => $e->resolved_at ? round($e->created_at->diffInMinutes($e->resolved_at) / 60, 1) : '', 'reads' => (int) $e->reads_count,
            '_class' => $e->isOpen() && $e->priority === 'urgent' ? 'row-alert' : ''])->values();
        $res = $list->whereNotNull('resolved_at');

        return ['cols' => ['date' => 'Date', 'time' => 'Time', 'shift' => 'Shift', 'dept' => 'Department', 'cat' => 'Category', 'prio' => 'Priority', 'title' => 'Entry', 'room' => 'Room',
            'by' => 'By', 'status' => 'Status', 'hours' => 'Hours to resolve', 'reads' => 'Read by'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Entries' => (string) $list->count(), 'Needing action' => (string) $list->whereIn('status', ['open', 'resolved'])->count(), 'Resolved' => (string) $res->count(),
                'Still open' => (string) $list->where('status', 'open')->count(), 'Urgent' => (string) $list->where('priority', 'urgent')->count(),
                'Avg hours to resolve' => $res->count() ? (string) round($res->avg(fn ($e) => $e->created_at->diffInMinutes($e->resolved_at)) / 60, 1) : '—',
                'Complaints / incidents' => (string) $list->whereIn('category', ['complaint', 'incident'])->count()],
            'note' => 'Complaints, incidents, maintenance and urgent entries are always open until someone records how they were resolved.'];
    }

    protected function r_lost_found_register($from, $to): array
    {
        $I = \App\Models\LostFoundItem::class;
        $list = $I::with('room', 'guest', 'releaser', 'disposer', 'witness', 'report')->whereDate('found_date', '>=', $from)->whereDate('found_date', '<=', $to)->orderBy('found_date')->orderBy('id')->get();
        $rows = $list->map(fn ($i) => ['no' => (string) $i->item_no, 'found' => $i->found_date->format('d M Y'), 'item' => $i->item_name, 'cat' => $i->categoryLabel(),
            'val' => $i->valuable ? 'Yes' : '', 'where' => (string) $i->location, 'finder' => (string) $i->found_by, 'store' => (string) $i->storage_location,
            'owner' => (string) ($i->guest?->full_name ?? ''), 'report' => (string) $i->report?->report_no, 'status' => $i->statusLabel(),
            'outcome' => match ($i->status) {
                'returned' => ($I::RETURN_METHODS[$i->return_method] ?? 'Returned').' – '.$i->returned_to.' '.$i->returned_date?->format('d M').($i->claimant_id_type ? ' (ID checked)' : ''),
                'disposed' => ($I::DISPOSAL[$i->disposal_method] ?? 'Disposed').' '.$i->disposed_date?->format('d M').($i->witness ? ' · witness '.$i->witness->name : ''),
                default => 'keep until '.$i->retain_until?->format('d M Y'),
            }, '_class' => $i->isDue() ? 'row-alert' : ''])->values();
        $ret = $list->where('status', 'returned');

        return ['cols' => ['no' => 'Item no.', 'found' => 'Found', 'item' => 'Item', 'cat' => 'Category', 'val' => 'Valuable', 'where' => 'Where', 'finder' => 'Found by', 'store' => 'Stored',
            'owner' => 'Probable owner', 'report' => 'Loss report', 'status' => 'Status', 'outcome' => 'Outcome'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Items found' => (string) $list->count(), 'Valuables' => (string) $list->where('valuable', true)->count(), 'Returned' => (string) $ret->count(),
                'Return rate' => $list->count() ? round($ret->count() / $list->count() * 100).'%' : '—', 'Disposed' => (string) $list->where('status', 'disposed')->count(),
                'Still in store' => (string) $list->where('status', 'stored')->count(), 'Past retention' => (string) $list->filter->isDue()->count(),
                'Loss reports in period' => (string) \App\Models\LostReport::whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->count()],
            'note' => 'Valuables are released only against photo ID by a supervisor and disposed of only with a second member of staff as witness.'];
    }

    protected function r_traces_register($from, $to): array
    {
        $D = \App\Services\TraceService::DEPARTMENTS;
        $list = \App\Models\Trace::with('reservation', 'stay.room', 'guest', 'creator', 'doer')
            ->where(fn ($q) => $q->whereDate('trace_date', '>=', $from)->whereDate('trace_date', '<=', $to)->orWhere(fn ($b) => $b->whereDate('carried_from', '>=', $from)->whereDate('carried_from', '<=', $to)))->orderBy('trace_date')->orderBy('due_time')->get();
        $rows = $list->map(fn ($t) => ['date' => ($t->carried_from ?? $t->trace_date)->format('d M Y'), 'time' => $t->due_time ? substr($t->due_time, 0, 5) : '',
            'dept' => $D[$t->department] ?? $t->department, 'text' => $t->text, 'res' => (string) $t->reservation?->reservation_no, 'room' => (string) $t->stay?->room?->room_no,
            'carried' => $t->carried_from ? 'to '.$t->trace_date->format('d M') : '', 'status' => ucfirst($t->status), 'done' => $t->done_at?->format('d M H:i') ?? '',
            'by' => (string) ($t->doer?->name ?? ''), 'set' => (string) $t->creator?->name, '_class' => $t->isOverdue() ? 'row-alert' : ''])->values();
        $done = $list->where('status', 'done');

        return ['cols' => ['date' => 'Due', 'time' => 'By', 'dept' => 'Department', 'text' => 'Trace', 'res' => 'Booking', 'room' => 'Room', 'carried' => 'Carried forward',
            'status' => 'Status', 'done' => 'Completed', 'by' => 'Done by', 'set' => 'Set by'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Traces' => (string) $list->count(), 'Done' => (string) $done->count(),
                'Done on the day' => (string) $done->filter(fn ($t) => $t->done_at && $t->done_at->toDateString() <= ($t->carried_from ?? $t->trace_date)->toDateString())->count(),
                'Carried forward' => (string) $list->whereNotNull('carried_from')->count(), 'Open' => (string) $list->where('status', 'open')->count(), 'Cancelled' => (string) $list->where('status', 'cancelled')->count()],
            'note' => 'Open traces are moved to the next business day by the night audit; the original date stays in "Due".'];
    }

    protected function r_wakeup_calls($from, $to): array
    {
        $W = \App\Models\WakeupCall::class;
        $list = $W::with('stay.room', 'stay.guest', 'stay.reservation.guest', 'room', 'attemptsLog', 'completer')
            ->whereBetween('call_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('call_at')->get();
        $rows = $list->map(function ($w) use ($W) {
            $first = $w->attemptsLog->first();
            $late = $first ? (int) max(0, round($w->call_at->diffInMinutes($first->attempted_at, false))) : null;

            return ['time' => $w->call_at->format('d M Y H:i'), 'room' => (string) ($w->stay?->room?->room_no ?? $w->room?->room_no),
                'guest' => (string) \App\Services\GuestMessageService::stayGuest($w->stay)?->full_name, 'source' => $W::SOURCES[$w->source] ?? $w->source,
                'daily' => $w->daily ? 'Yes' : '', 'attempts' => (int) $w->attempts, 'late' => $late ?? '', 'result' => $w->statusLabel(), 'by' => (string) $w->completer?->name,
                '_class' => in_array($w->status, ['no_answer', 'missed']) || ($late ?? 0) > 5 ? 'row-alert' : ''];
        })->values();
        $made = $list->whereIn('status', ['done', 'no_answer']);
        $firsts = $list->map(fn ($w) => $w->attemptsLog->first() ? max(0, $w->call_at->diffInMinutes($w->attemptsLog->first()->attempted_at, false)) : null)->filter(fn ($x) => $x !== null);

        return ['cols' => ['time' => 'Call time', 'room' => 'Room', 'guest' => 'Guest', 'source' => 'Booked via', 'daily' => 'Daily', 'attempts' => 'Attempts', 'late' => 'Min. late', 'result' => 'Result', 'by' => 'Logged by'],
            'rows' => $rows, 'money' => [], 'sum' => ['attempts'],
            'summary' => ['Calls' => (string) $list->count(), 'Answered' => (string) $list->where('status', 'done')->count(), 'No answer' => (string) $list->where('status', 'no_answer')->count(),
                'Missed (not called)' => (string) $list->where('status', 'missed')->count(), 'Cancelled' => (string) $list->where('status', 'cancelled')->count(),
                'Made on time (≤ 5 min)' => $firsts->count() ? round($firsts->filter(fn ($m) => $m <= 5)->count() / $firsts->count() * 100).'%' : '—',
                'Via guest portal' => (string) $list->where('source', 'portal')->count()],
            'note' => 'A missed call is one nobody logged within two hours – it was escalated to the duty manager through the log book when it was '.\App\Services\FrontDeskOpsService::cfg()['wakeup_grace'].' minutes late.'];
    }

    protected function r_activity_bookings($from, $to): array
    {
        $rows = ActivityBooking::with('activity.guest.bookedBy')->whereBetween('booked_at', [$from, $to])->get()->map(fn ($r) => [
            'activity' => optional($r->activity)->name, 'guest' => optional($r->guest)->name,
            'date' => $r->activity_date, 'quantity' => $r->quantity, 'price' => $r->unit_price,
            'amount' => $r->total_amount, 'status' => $r->status, 'booked' => $r->booked_at,
            'booked_by' => optional($r->bookedBy)->name, 'notes' => $r->notes,
        ]);
        return ['cols' => ['activity' => 'Activity', 'guest' => 'Guest', 'date' => 'Date', 'quantity' => 'Qty', 'price' => 'Unit Price', 'amount' => 'Total', 'status' => 'Status', 'booked' => 'Booked'], 'rows' => $rows, 'money' => ['unit_price', 'total_amount'], 'sum' => ['total_amount'], 'note' => 'Guest activity bookings with pricing.'];
    }

    protected function r_arrivals($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType')->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)
            ->whereIn('status', ['reserved', 'checked_in', 'checked_out'])->orderBy('arrival_date')->get()->map(fn ($s) => $this->stayRow($s));

        return ['cols' => $this->stayCols(), 'rows' => $rows, 'money' => ['rate'], 'sum' => ['nights']];
    }

    protected function r_cancellations($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType')->whereIn('status', ['cancelled', 'no_show'])
            ->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)->get()->map(fn ($s) => $this->stayRow($s) + ['reason' => $s->reservation->cancel_reason]);

        return ['cols' => $this->stayCols() + ['reason' => 'Reason'], 'rows' => $rows, 'money' => ['rate'], 'sum' => ['nights']];
    }

    protected function r_company($from, $to): array
    {
        return ['cols' => ['name' => 'Company / Agent', 'stays' => 'Stays', 'nights' => 'Room nights', 'revenue' => 'Room revenue', 'adr' => 'ADR'], 'rows' => $this->roomNightsBy($from, $to, 'company'), 'money' => ['revenue', 'adr'], 'sum' => ['stays', 'nights', 'revenue']];
    }

    protected function r_departures($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType')->whereDate('departure_date', '>=', $from)->whereDate('departure_date', '<=', $to)
            ->whereIn('status', ['checked_in', 'checked_out'])->orderBy('departure_date')->get()->map(fn ($s) => $this->stayRow($s));

        return ['cols' => $this->stayCols(), 'rows' => $rows, 'money' => ['rate'], 'sum' => ['nights']];
    }

    protected function r_guest_documents($from, $to): array
    {
        $rows = \App\Models\GuestDocument::with('guest.documentType')->whereBetween('uploaded_at', [$from, $to])->get()->map(fn ($r) => [
            'guest' => optional($r->guest)->name, 'type' => optional($r->documentType)->name,
            'filename' => $r->filename, 'uploaded' => $r->uploaded_at,
            'expiry' => $r->expiry_date, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['guest' => 'Guest', 'type' => 'Type', 'filename' => 'Filename', 'uploaded' => 'Uploaded', 'expiry' => 'Expiry', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Guest document register with expiry tracking.'];
    }

    protected function r_guest_requests($from, $to): array
    {
        $sla = max(1, (int) setting('portal_sla_minutes', 15));
        $all = \App\Models\GuestRequest::whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get();
        $rows = $all->groupBy('category')->map(function ($g, $cat) use ($sla) {
            $resp = $g->filter(fn ($r) => $r->acknowledged_at)->map(fn ($r) => $r->created_at->diffInMinutes($r->acknowledged_at));
            $comp = $g->where('status', 'done')->filter(fn ($r) => $r->completed_at)->map(fn ($r) => $r->created_at->diffInMinutes($r->completed_at));

            return ['cat' => \App\Models\GuestRequest::CATEGORIES[$cat][0] ?? $cat, 'total' => $g->count(), 'portal' => $g->where('source', 'portal')->count(),
                'done' => $g->where('status', 'done')->count(), 'declined' => $g->where('status', 'declined')->count(), 'open' => $g->whereIn('status', \App\Models\GuestRequest::OPEN)->count(),
                'resp' => $resp->count() ? round($resp->avg(), 1) : null, 'comp' => $comp->count() ? round($comp->avg(), 1) : null,
                'breach' => $g->filter(fn ($r) => $r->created_at->diffInMinutes($r->acknowledged_at ?? ($r->isOpen() ? now() : $r->created_at)) >= $sla)->count(),
                'rating' => $g->whereNotNull('rating')->count() ? round($g->whereNotNull('rating')->avg('rating'), 1) : null];
        })->sortByDesc('total')->values();

        return ['cols' => ['cat' => 'Request type', 'total' => 'Requests', 'portal' => 'Via portal', 'done' => 'Done', 'declined' => 'Declined', 'open' => 'Still open',
            'resp' => 'Avg response (min)', 'comp' => 'Avg completion (min)', 'breach' => "SLA breaches (> {$sla} min)", 'rating' => 'Avg rating ★'],
            'rows' => $rows, 'sum' => ['total', 'portal', 'done', 'declined', 'open', 'breach'], 'chart' => ['label' => 'Requests', 'key' => 'total', 'x' => 'cat'],
            'note' => 'Response = raised → acknowledged. Completion = raised → done. Breaches count requests left unanswered longer than the SLA set in Settings.'];
    }

    protected function r_inhouse($from, $to): array
    {
        $stays = ReservationRoom::with('reservation.guest', 'reservation.company', 'guest', 'room', 'roomType', 'folio')->where('status', 'checked_in')->get()->sortBy('room.room_no');
        $rows = $stays->map(fn ($s) => $this->stayRow($s) + ['balance' => $s->folio ? $s->folio->balance() : 0]);

        return ['cols' => $this->stayCols() + ['balance' => 'Balance'], 'rows' => $rows->values(), 'money' => ['rate', 'balance'], 'sum' => ['balance']];
    }

    protected function r_loyalty_entries($from, $to): array
    {
        $rows = LoyaltyEntry::with('guest.loyaltyTier.reward')->whereBetween('earned_at', [$from, $to])->get()->map(fn ($r) => [
            'guest' => optional($r->guest)->name, 'tier' => optional($r->loyaltyTier)->name,
            'type' => $r->entry_type, 'points' => $r->points, 'reward' => optional($r->reward)->name,
            'reward_value' => $r->reward_value, 'earned' => $r->earned_at, 'expires' => $r->expires_at, 'status' => $r->status,
        ]);
        return ['cols' => ['guest' => 'Guest', 'tier' => 'Tier', 'type' => 'Type', 'points' => 'Points', 'reward' => 'Reward', 'reward_value' => 'Reward Value', 'earned' => 'Earned', 'expires' => 'Expires', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Loyalty point transactions with earn/redeem/expiry.'];
    }

    protected function r_nationality($from, $to): array
    {
        $rows = ReservationRoom::with('reservation.guest', 'guest')->whereIn('status', ['checked_in', 'checked_out'])->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)->get()
            ->groupBy(fn ($s) => ($s->guest ?? $s->reservation->guest)?->nationality ?: 'Unknown')
            ->map(fn ($g, $n) => ['nat' => $n, 'arrivals' => $g->count(), 'pax' => $g->sum('adults') + $g->sum('children'), 'nights' => $g->sum(fn ($s) => $s->nights)])->sortByDesc('arrivals')->values();

        return ['cols' => ['nat' => 'Nationality', 'arrivals' => 'Arrivals', 'pax' => 'Pax', 'nights' => 'Room nights'], 'rows' => $rows, 'sum' => ['arrivals', 'pax', 'nights'], 'chart' => ['label' => 'Arrivals', 'key' => 'arrivals', 'x' => 'nat']];
    }

    /** Room nights and revenue per day computed from stays + posted tariff. */
    protected function r_occupancy($from, $to): array
    {
        $roomRows = Room::where('is_active', true)->get(['id', 'status']);
        $days = (int) $from->diffInDays($to) + 1;
        $sellableByDay = \App\Services\AvailabilityService::sellableRoomCounts($from, $days, $roomRows);
        $stays = ReservationRoom::whereIn('status', ['checked_in', 'checked_out'])->whereDate('arrival_date', '<=', $to)->whereDate('departure_date', '>', $from)->get(['arrival_date', 'departure_date', 'adults', 'children']);
        $rev = FolioTransaction::where('category', 'room')->where('type', 'charge')->where('is_void', false)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->get(['business_date', 'amount'])->groupBy(fn ($t) => $t->business_date->toDateString())->map->sum('amount');
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $total = $sellableByDay[$key] ?? 0;
            $occ = $stays->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d));
            $sold = $occ->count(); $r = (float) ($rev[$key] ?? 0);
            $rows->push(['date' => $d->format('d-m-Y D'), 'total' => $total, 'sold' => $sold, 'vacant' => max(0, $total - $sold), 'pax' => $occ->sum('adults') + $occ->sum('children'),
                'occ' => $total ? round($sold * 100 / $total, 1) : 0, 'revenue' => $r, 'adr' => $sold ? round($r / $sold, 2) : 0, 'revpar' => $total ? round($r / $total, 2) : 0]);
        }

        return ['cols' => ['date' => 'Date', 'total' => 'Sellable rooms', 'sold' => 'Sold', 'vacant' => 'Vacant', 'pax' => 'Pax', 'occ' => 'Occ %', 'revenue' => 'Room revenue', 'adr' => 'ADR', 'revpar' => 'RevPAR'],
            'rows' => $rows, 'money' => ['revenue', 'adr', 'revpar'], 'sum' => ['sold', 'pax', 'revenue'], 'chart' => ['label' => 'Occupancy %', 'key' => 'occ', 'x' => 'date']];
    }

    protected function r_police($from, $to): array
    {
        $home = strtolower(hotel()->country ?? 'india');
        $rows = ReservationRoom::with('reservation.guest', 'guest', 'room')->whereIn('status', ['checked_in', 'checked_out'])->whereBetween('checked_in_at', [$from, $to->copy()->endOfDay()])->get()
            ->filter(fn ($s) => ($n = strtolower((string) ($s->guest ?? $s->reservation->guest)?->nationality)) && ! in_array($n, [$home, 'indian', 'india']))
            ->map(function ($s) {
                $g = $s->guest ?? $s->reservation->guest;

                return ['name' => $g->full_name, 'nat' => $g->nationality, 'passport' => $g->passport_no ?: $g->id_number, 'visa' => optional($g->visa_expiry)->format('d-m-Y'), 'dob' => optional($g->dob)->format('d-m-Y'),
                    'address' => trim($g->address.' '.$g->city.' '.$g->country), 'room' => $s->room?->room_no, 'in' => $s->checked_in_at?->format('d-m-Y H:i'), 'dep' => $s->departure_date->format('d-m-Y'), 'purpose' => $s->reservation->purpose];
            })->values();

        return ['cols' => ['name' => 'Guest', 'nat' => 'Nationality', 'passport' => 'Passport / ID', 'visa' => 'Visa expiry', 'dob' => 'DOB', 'address' => 'Address', 'room' => 'Room', 'in' => 'Checked in', 'dep' => 'Departure', 'purpose' => 'Purpose'], 'rows' => $rows];
    }

    protected function r_reservation_amendments($from, $to): array
    {
        $rows = ReservationAmendment::with('reservation.guest')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'type' => $r->change_type, 'field' => $r->field_name, 'old_val' => $r->old_value, 'new_val' => $r->new_value,
            'reason' => $r->reason, 'charge' => $r->charge_amount, 'user' => $r->user_id, 'created' => $r->created_at,
        ]);
        return ['cols' => ['res' => 'Reservation', 'guest' => 'Guest', 'type' => 'Type', 'field' => 'Field', 'old_val' => 'Old', 'new_val' => 'New', 'reason' => 'Reason', 'charge' => 'Charge', 'created' => 'Created'], 'rows' => $rows, 'money' => ['charge_amount'], 'sum' => ['charge_amount'], 'note' => 'Reservation amendment history.'];
    }

    protected function r_reservation_desk_views($from, $to): array
    {
        $rows = ReservationDeskView::with('reservation.guest')->whereBetween('viewed_at', [$from, $to])->get()->map(fn ($r) => [
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'viewed' => $r->viewed_at, 'duration' => $r->view_duration_seconds,
            'user' => $r->user_id, 'ip' => $r->ip_address, 'action' => $r->action_taken,
        ]);
        return ['cols' => ['res' => 'Reservation', 'guest' => 'Guest', 'viewed' => 'Viewed', 'duration' => 'Duration (s)', 'user' => 'User', 'ip' => 'IP', 'action' => 'Action'], 'rows' => $rows, 'note' => 'Reservation desk view audit log.'];
    }

    protected function r_reservation_waitlist($from, $to): array
    {
        $rows = ReservationWaitlist::with('guest.requestedBy')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'guest' => optional($r->guest)->name, 'date' => $r->date_needed,
            'room_type' => $r->room_type_id, 'nights' => $r->nights, 'rate' => $r->rate_offered,
            'priority' => $r->priority, 'reason' => $r->reason, 'created' => $r->created_at,
            'converted' => $r->converted_to_reservation, 'status' => $r->status,
        ]);
        return ['cols' => ['guest' => 'Guest', 'date' => 'Date Needed', 'room_type' => 'Room Type', 'nights' => 'Nights', 'rate' => 'Rate', 'priority' => 'Priority', 'converted' => 'Converted', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate_offered'], 'note' => 'Reservation waitlist with conversion tracking.'];
    }

    protected function r_reservations($from, $to): array
    {
        $rows = Reservation::with('guest', 'company', 'source', 'rooms', 'creator')->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->latest()->get()
            ->map(fn ($r) => ['no' => $r->reservation_no, 'date' => $r->created_at->format('d-m-Y'), 'guest' => $r->guest?->full_name, 'arr' => $r->arrival_date->format('d-m-Y'), 'dep' => $r->departure_date->format('d-m-Y'),
                'rooms' => $r->rooms->count(), 'nights' => $r->nights, 'value' => $r->rooms->sum(fn ($s) => $s->rate * $s->nights), 'source' => $r->source?->name, 'company' => $r->company?->name, 'status' => $r->status, 'by' => $r->creator?->username]);

        return ['cols' => ['no' => 'Res. No', 'date' => 'Booked on', 'guest' => 'Guest', 'arr' => 'Arrival', 'dep' => 'Departure', 'rooms' => 'Rooms', 'nights' => 'Nights', 'value' => 'Room value', 'source' => 'Source', 'company' => 'Company', 'status' => 'Status', 'by' => 'By'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rooms', 'value']];
    }

    protected function r_room_amenities($from, $to): array
    {
        // Rewritten against the real schema: the catalog is name/category/show flags; there is no roomType/amenityType relation.
        $rows = RoomAmenity::orderBy('sort_order')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'category' => $r->category, 'description' => $r->description,
            'desk' => $r->show_on_desk ? 'Yes' : 'No', 'online' => $r->show_online ? 'Yes' : 'No',
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Amenity', 'category' => 'Category', 'description' => 'Description', 'desk' => 'On Desk', 'online' => 'Online', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Room amenity catalog.'];
    }

    protected function r_room_amenity_links($from, $to): array
    {
        $rows = RoomAmenityLink::with('room.roomType.amenityType')->get()->map(fn ($r) => [
            'room' => optional($r->room)->room_no, 'type' => optional($r->roomType)->name,
            'amenity' => $r->amenity_name, 'amenity_type' => optional($r->amenityType)->name,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['room' => 'Room', 'type' => 'Room Type', 'amenity' => 'Amenity', 'amenity_type' => 'Type', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Individual room amenity assignments.'];
    }

    protected function r_room_block_lines($from, $to): array
    {
        // Rewritten against the real schema: block()/roomType() relations; block_no/name/arrival/departure/status on the parent; the line is room_type_id + rooms.
        $rows = RoomBlockLine::with('block', 'roomType')->whereHas('block', fn ($q) => $q->whereBetween('arrival_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'block' => optional($r->block)->block_no, 'block_name' => optional($r->block)->name,
            'room_type' => optional($r->roomType)->name, 'rooms' => $r->rooms,
            'arrival' => optional($r->block)->arrival_date, 'departure' => optional($r->block)->departure_date,
            'status' => optional($r->block)->status,
        ]);
        return ['cols' => ['block' => 'Block', 'block_name' => 'Block Name', 'room_type' => 'Room Type', 'rooms' => 'Rooms', 'arrival' => 'Arrival', 'departure' => 'Departure', 'status' => 'Status'], 'rows' => $rows, 'sum' => ['rooms'], 'note' => 'Room block allocations by room type.'];
    }

    protected function r_room_blocks($from, $to): array
    {
        $rows = RoomBlock::with('blockType.roomType')->whereBetween('blocked_from', [$from, $to])->get()->map(fn ($r) => [
            'room_type' => optional($r->roomType)->name, 'type' => optional($r->blockType)->name,
            'from' => $r->blocked_from, 'to' => $r->blocked_to, 'reason' => $r->reason,
            'status' => $r->status, 'user' => $r->user_id, 'notes' => $r->notes,
        ]);
        return ['cols' => ['room_type' => 'Room Type', 'type' => 'Block Type', 'from' => 'From', 'to' => 'To', 'reason' => 'Reason', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Room block summary by room type.'];
    }

    protected function r_room_counts($from, $to): array
    {
        $rows = RoomCount::whereBetween('count_date', [$from, $to])->get()->map(fn ($r) => [
            'date' => $r->count_date, 'room_type' => $r->room_type_id, 'total' => $r->total_rooms,
            'clean' => $r->clean, 'dirty' => $r->dirty, 'out_of_order' => $r->out_of_order,
            'available' => $r->available, 'occupied' => $r->occupied, 'arrivals' => $r->arrivals, 'departures' => $r->departures,
        ]);
        return ['cols' => ['date' => 'Date', 'room_type' => 'Room Type', 'total' => 'Total', 'clean' => 'Clean', 'dirty' => 'Dirty', 'ooo' => 'OOO', 'available' => 'Available', 'occupied' => 'Occupied', 'arrivals' => 'Arrivals', 'departures' => 'Departures'], 'rows' => $rows, 'sum' => ['total_rooms', 'clean', 'dirty', 'out_of_order', 'available', 'occupied', 'arrivals', 'departures'], 'note' => 'Daily room count sheet.'];
    }

    protected function r_room_images($from, $to): array
    {
        // room_images keeps path / caption / is_primary / is_active / timestamps — the
        // register reads those, not columns the table never had.
        $rows = RoomImage::with('room.roomType', 'roomType')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'room' => optional($r->room)->room_no ?: optional($r->roomType)->name,
            'type' => optional(optional($r->room)->roomType ?? $r->roomType)->name,
            'filename' => basename((string) $r->path), 'uploaded' => $r->created_at,
            'caption' => $r->caption,
            'status' => $r->is_primary ? 'Primary' : ($r->is_active ? 'Active' : 'Hidden'),
        ]);
        return ['cols' => ['room' => 'Room', 'type' => 'Room Type', 'filename' => 'Filename', 'uploaded' => 'Uploaded', 'caption' => 'Caption', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Room image register.'];
    }

    protected function r_room_moves($from, $to): array
    {
        $rows = RoomMove::with('reservation.guest')->whereBetween('moved_at', [$from, $to])->get()->map(fn ($r) => [
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'from_room' => $r->from_room_id, 'to_room' => $r->to_room_id,
            'from_type' => $r->from_room_type_id, 'to_type' => $r->to_room_type_id,
            'reason' => $r->reason, 'moved' => $r->moved_at, 'user' => $r->user_id,
            'balance_adjust' => $r->balance_adjustment, 'notes' => $r->notes,
        ]);
        return ['cols' => ['res' => 'Reservation', 'guest' => 'Guest', 'from_room' => 'From Room', 'to_room' => 'To Room', 'reason' => 'Reason', 'moved' => 'Moved At', 'balance_adjust' => 'Balance Adj'], 'rows' => $rows, 'money' => ['balance_adjust'], 'sum' => ['balance_adjust'], 'note' => 'Room moves with reason and balance adjustments.'];
    }

    protected function r_room_outages($from, $to): array
    {
        // Room outages are dated [starts_on, ends_on) blocks. Include any
        // block that overlaps the inclusive report window rather than only
        // outages that began inside it.
        $rows = RoomOutage::with(['room.roomType', 'creator'])
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>', $from)
            ->orderBy('starts_on')->orderBy('room_id')->get()->map(fn ($r) => [
                'room' => optional($r->room)->room_no,
                'type' => optional($r->room?->roomType)->name,
                'from' => $r->starts_on,
                'to' => $r->ends_on,
                'category' => RoomOutage::REASONS[$r->reason] ?? $r->reason,
                'status' => $r->status,
                'user' => $r->creator?->name ?? ($r->created_by ? 'User #'.$r->created_by : '—'),
                'notes' => $r->notes,
            ]);

        return ['cols' => ['room' => 'Room', 'type' => 'Room Type', 'from' => 'From', 'to' => 'To', 'category' => 'Reason', 'status' => 'Status', 'user' => 'Created By', 'notes' => 'Notes'], 'rows' => $rows, 'money' => [], 'sum' => [], 'note' => 'Room blocks overlapping the selected period. End dates are exclusive: the room returns that morning.'];
    }

    protected function r_source($from, $to): array
    {
        return ['cols' => ['name' => 'Source', 'stays' => 'Stays', 'nights' => 'Room nights', 'revenue' => 'Room revenue', 'adr' => 'ADR'], 'rows' => $this->roomNightsBy($from, $to, 'source'), 'money' => ['revenue', 'adr'], 'sum' => ['stays', 'nights', 'revenue'], 'chart' => ['label' => 'Revenue', 'key' => 'revenue', 'x' => 'name']];
    }

    protected function r_stay_fixed_charges($from, $to): array
    {
        $rows = \App\Models\StayFixedCharge::with('reservation.guest.chargeType')->whereBetween('charged_at', [$from, $to])->get()->map(fn ($r) => [
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'type' => optional($r->chargeType)->name, 'description' => $r->description,
            'amount' => $r->amount, 'charged' => $r->charged_at, 'status' => $r->status,
        ]);
        return ['cols' => ['res' => 'Reservation', 'guest' => 'Guest', 'type' => 'Type', 'description' => 'Description', 'amount' => 'Amount', 'charged' => 'Charged', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Stay fixed charge register (parking, safe, etc.).'];
    }

    protected function r_upsell_offers($from, $to): array
    {
        $rows = UpsellOffer::with('roomType.guestType')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'title' => $r->title, 'room_type' => optional($r->roomType)->name,
            'guest_type' => optional($r->guestType)->name, 'description' => $r->description,
            'price' => $r->price, 'cost' => $r->cost, 'margin' => $r->margin_pct,
            'valid_from' => $r->valid_from, 'valid_to' => $r->valid_to, 'status' => $r->status,
        ]);
        return ['cols' => ['title' => 'Title', 'room_type' => 'Room Type', 'guest_type' => 'Guest Type', 'price' => 'Price', 'cost' => 'Cost', 'margin' => 'Margin %', 'valid_from' => 'Valid From', 'valid_to' => 'Valid To', 'status' => 'Status'], 'rows' => $rows, 'money' => ['price', 'cost'], 'note' => 'Upsell offer catalog with pricing and validity.'];
    }

    protected function r_wakeup_attempts($from, $to): array
    {
        $rows = WakeupAttempt::with('wakeupCall.guest')->whereBetween('attempted_at', [$from, $to])->get()->map(fn ($r) => [
            'guest' => optional($r->wakeupCall->guest)->name, 'room' => optional($r->wakeupCall->reservation->room)->room_no,
            'attempted' => $r->attempted_at, 'result' => $r->result, 'notes' => $r->notes, 'status' => $r->status,
        ]);
        return ['cols' => ['guest' => 'Guest', 'room' => 'Room', 'attempted' => 'Attempted', 'result' => 'Result', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Wakeup call attempt log.'];
    }

    protected function r_upsell_conversion($from, $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $pres = \App\Models\UpsellPresentation::whereBetween('created_at', $range)->groupBy('channel')
            ->selectRaw('channel, count(*) n, sum(opened_at is not null) opened, sum(converted_at is not null) conv')->get()->keyBy('channel');
        $orders = $this->upsellOrdersIn($from, $to);
        $rows = collect(\App\Models\UpsellOrder::CHANNELS)->map(function ($label, $ch) use ($pres, $orders) {
            $p = $pres[$ch] ?? null;
            $c = $orders->where('channel', $ch)->where('status', 'confirmed');
            $n = (int) ($p->n ?? 0);

            return ['channel' => $label, 'offered' => $n, 'opened' => (int) ($p->opened ?? 0), 'converted' => (int) ($p->conv ?? 0),
                'rate' => $n ? round(($p->conv ?? 0) * 100 / $n, 1) : 0, 'orders' => $c->count(), 'amount' => (float) $c->sum('amount'),
                'per' => $n ? round($c->sum('amount') / $n, 2) : 0];
        })->values();
        $n = $rows->sum('offered');

        return ['cols' => ['channel' => 'Channel', 'offered' => 'Guests offered', 'opened' => 'Opened', 'converted' => 'Bought', 'rate' => 'Conversion %',
            'orders' => 'Orders', 'amount' => 'Revenue', 'per' => 'Revenue / guest offered'],
            'rows' => $rows, 'money' => ['amount', 'per'], 'sum' => ['offered', 'opened', 'converted', 'orders', 'amount'],
            'summary' => ['Guests offered' => (string) $n, 'Overall conversion' => $n ? round($rows->sum('converted') * 100 / $n, 1).'%' : '–',
                'Revenue per guest offered' => $n ? money($rows->sum('amount') / $n) : '–'],
            'chart' => ['label' => 'Conversion %', 'key' => 'rate', 'x' => 'channel'],
            'note' => 'A guest counts once per channel: pre-arrival = offers e-mailed or the offers link opened before arrival; front desk = offers shown on the check-in screen; in-house = offers page opened from the guest portal. “Bought” = at least one order placed through that channel.'];
    }

    protected function r_upsell_incentives($from, $to): array
    {
        $users = \App\Models\User::visibleToHotel()->pluck('name', 'id');
        $rows = $this->upsellOrdersIn($from, $to)->whereNotNull('sold_by')->where('status', 'confirmed')->groupBy('sold_by')
            ->map(fn ($g, $uid) => ['staff' => $users[$uid] ?? '#'.$uid, 'orders' => $g->count(), 'amount' => (float) $g->sum('amount'),
                'incentive' => (float) $g->sum('incentive_amount'),
                'offers' => $g->groupBy(fn ($o) => $o->offer?->name)->map->count()->sortDesc()->map(fn ($n, $k) => "{$k} ×{$n}")->implode(', ')])
            ->sortByDesc('incentive')->values();

        return ['cols' => ['staff' => 'Staff', 'orders' => 'Upsells', 'amount' => 'Sales', 'incentive' => 'Incentive earned', 'offers' => 'Offers sold'],
            'rows' => $rows, 'money' => ['amount', 'incentive'], 'sum' => ['orders', 'amount', 'incentive'],
            'summary' => ['Incentives earned' => money($rows->sum('incentive')), 'Desk upsell sales' => money($rows->sum('amount')), 'Staff selling' => (string) $rows->count()],
            'chart' => ['label' => 'Incentive', 'key' => 'incentive', 'x' => 'staff'],
            'note' => 'Desk sales confirmed in the period (orders placed on the check-in screen or the reservation). Incentive rates are set per offer; a cancelled upsell earns nothing. Add the totals as a one-off earning in the payroll run.'];
    }

    protected function r_upsell_revenue($from, $to): array
    {
        $all = $this->upsellOrdersIn($from, $to);
        $total = (float) $all->where('status', 'confirmed')->sum('amount');
        $rows = $all->groupBy('upsell_offer_id')->map(function ($g) use ($total) {
            $c = $g->where('status', 'confirmed');
            $amt = (float) $c->sum('amount');

            return ['offer' => $g->first()->offer?->name ?? '(deleted)', 'kind' => $g->first()->offer?->kindLabel() ?? $g->first()->kind, 'orders' => $c->count(),
                'pre' => (float) $c->where('channel', 'pre_arrival')->sum('amount'), 'desk' => (float) $c->where('channel', 'desk')->sum('amount'),
                'portal' => (float) $c->where('channel', 'portal')->sum('amount'), 'amount' => $amt, 'avg' => $c->count() ? round($amt / $c->count(), 2) : 0,
                'share' => $total ? round($amt * 100 / $total, 1) : 0, 'declined' => $g->where('status', 'declined')->count(),
                'cancelled' => $g->whereIn('status', ['cancelled', 'expired'])->count()];
        })->sortByDesc('amount')->values();

        return ['cols' => ['offer' => 'Offer', 'kind' => 'Type', 'orders' => 'Confirmed', 'pre' => 'Pre-arrival', 'desk' => 'Front desk', 'portal' => 'In-house',
            'amount' => 'Revenue', 'avg' => 'Avg sale', 'share' => 'Share %', 'declined' => 'Declined', 'cancelled' => 'Cancelled / expired'],
            'rows' => $rows, 'money' => ['pre', 'desk', 'portal', 'amount', 'avg'], 'sum' => ['orders', 'pre', 'desk', 'portal', 'amount', 'declined', 'cancelled'],
            'summary' => ['Upsell revenue' => money($total), 'Confirmed orders' => (string) $rows->sum('orders'),
                'Room upgrades' => money($all->where('status', 'confirmed')->where('kind', 'upgrade')->sum('amount')),
                'Add-ons' => money($all->where('status', 'confirmed')->where('kind', 'addon')->sum('amount')),
                'Early / late' => money($all->where('status', 'confirmed')->whereIn('kind', ['early_checkin', 'late_checkout'])->sum('amount'))],
            'chart' => ['label' => 'Revenue', 'key' => 'amount', 'x' => 'offer'],
            'note' => 'Orders placed in the period, before tax. Revenue is the value booked on confirmation; it reaches the folio as fixed charges (nightly items with the night audit, one-off items at check-in / check-out). Cancelled orders stop their charge.'];
    }

}
