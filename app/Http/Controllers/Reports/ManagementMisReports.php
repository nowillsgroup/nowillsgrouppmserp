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
 * Management & MIS reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait ManagementMisReports
{
    protected function r_email_logs($from, $to): array
    {
        $rows = EmailLog::with('emailTemplate.user.guest')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'template' => optional($r->emailTemplate)->name, 'subject' => $r->subject,
            'guest' => optional($r->guest)->name, 'sent' => $r->sent_at, 'status' => $r->status,
            'error' => $r->error_message, 'user' => optional($r->user)->name,
        ]);
        return ['cols' => ['template' => 'Template', 'subject' => 'Subject', 'guest' => 'Guest', 'sent' => 'Sent', 'status' => 'Status', 'error' => 'Error'], 'rows' => $rows, 'note' => 'Email send log.'];
    }

    protected function r_flash($from, $to): array
    {
        [, $rows] = \App\Services\ManagementReportService::flash($to);

        return ['cols' => ['metric' => 'Metric', 'day' => 'Day · '.$to->format('d M Y'), 'mtd' => 'Month to date', 'ytd' => 'Year to date'], 'rows' => $rows,
            'note' => 'Computed from posted transactions. Day = the business date selected (by default the last audited day). Collections exclude city-ledger transfers.'];
    }

    protected function r_guest_history($from, $to): array
    {
        $rows = Guest::withCount(['reservations as stays' => fn ($q) => $q->whereIn('status', ['checked_in', 'checked_out', 'partially_checked_in'])->whereDate('arrival_date', '>=', $from)->whereDate('arrival_date', '<=', $to)])
            ->with(['folios' => fn ($q) => $q->where('status', 'closed')->whereBetween('closed_at', [$from, $to->copy()->endOfDay()])])->get()
            ->filter(fn ($g) => $g->stays > 0)
            ->map(fn ($g) => ['guest' => $g->full_name, 'phone' => $g->phone, 'city' => $g->city, 'vip' => $g->is_vip ? 'VIP' : '', 'stays' => $g->stays, 'revenue' => $g->folios->sum(fn ($f) => $f->totals()['charges'] - $f->totals()['discount']), 'points' => $g->loyalty_points])
            ->sortByDesc('revenue')->take(100)->values();

        return ['cols' => ['guest' => 'Guest', 'phone' => 'Phone', 'city' => 'City', 'vip' => 'VIP', 'stays' => 'Stays', 'revenue' => 'Billed', 'points' => 'Loyalty pts'], 'rows' => $rows, 'money' => ['revenue'], 'sum' => ['stays', 'revenue']];
    }

    protected function r_kpi_alert_log($from, $to): array
    {
        $rows = \App\Models\KpiAlertEvent::with('alert', 'acknowledger')->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())
            ->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($e) {
                $fmt = \App\Services\KpiService::METRICS[$e->alert?->metric][1] ?? 'int';

                return ['date' => $e->business_date->format('d-m-Y'), 'alert' => $e->alert?->name, 'metric' => \App\Services\KpiService::metricLabel((string) $e->alert?->metric),
                    'severity' => $e->severity, 'actual' => \App\Services\KpiService::format((float) $e->value, $fmt),
                    'threshold' => ($e->alert?->operator === 'below' ? '< ' : '> ').\App\Services\KpiService::format((float) $e->threshold, $fmt),
                    'notified' => $e->notified, 'ack' => $e->acknowledger?->name ?? '', 'ack_at' => $e->acknowledged_at?->format('d-m-Y H:i') ?? '', 'note' => $e->ack_note,
                    '_class' => $e->acknowledged_at ? '' : ($e->severity === 'critical' ? 'row-red' : 'row-amber')];
            });

        return ['cols' => ['date' => 'Business date', 'alert' => 'Alert', 'metric' => 'Metric', 'severity' => 'Severity', 'actual' => 'Actual', 'threshold' => 'Threshold',
            'notified' => 'Messages sent', 'ack' => 'Acknowledged by', 'ack_at' => 'Acknowledged at', 'note' => 'Note'],
            'rows' => $rows, 'sum' => ['notified'],
            'summary' => ['Breaches' => (string) $rows->count(), 'Critical' => (string) $rows->where('severity', 'critical')->count(),
                'Unacknowledged' => (string) $rows->where('ack', '')->count()]];
    }

    protected function r_kpi_alerts($from, $to): array
    {
        $rows = KpiAlert::with('kpi.department')->whereBetween('triggered_at', [$from, $to])->get()->map(fn ($r) => [
            'kpi' => optional($r->kpi)->name, 'dept' => optional($r->department)->name,
            'triggered' => $r->triggered_at, 'value' => $r->kpi_value, 'threshold' => $r->threshold_value,
            'direction' => $r->alert_direction, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['kpi' => 'KPI', 'dept' => 'Dept', 'triggered' => 'Triggered', 'value' => 'Value', 'threshold' => 'Threshold', 'direction' => 'Direction', 'status' => 'Status'], 'rows' => $rows, 'note' => 'KPI alert trigger log.'];
    }

    protected function r_message_logs($from, $to): array
    {
        $rows = MessageLog::with('messageType.guest.user')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'type' => optional($r->messageType)->name, 'guest' => optional($r->guest)->name,
            'sent' => $r->sent_at, 'channel' => $r->channel, 'content' => $r->content,
            'status' => $r->status, 'user' => optional($r->user)->name,
        ]);
        return ['cols' => ['type' => 'Type', 'guest' => 'Guest', 'sent' => 'Sent', 'channel' => 'Channel', 'content' => 'Content', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Guest message send log.'];
    }

    protected function r_mis($from, $to): array
    {
        $rows = NightAudit::whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->get()
            ->map(fn ($a) => ['date' => $a->business_date->format('d-m-Y'), 'sold' => $a->rooms_occupied.'/'.$a->rooms_total, 'occ' => (float) $a->occupancy_pct, 'arr' => $a->arrivals, 'dep' => $a->departures, 'ns' => $a->no_shows,
                'room' => (float) $a->room_revenue, 'other' => (float) $a->other_revenue, 'tax' => (float) $a->tax_collected, 'coll' => (float) $a->collections, 'adr' => (float) $a->adr, 'revpar' => (float) $a->revpar]);
        $m = ['room', 'other', 'tax', 'coll', 'adr', 'revpar'];

        return ['cols' => ['date' => 'Date', 'sold' => 'Sold/Total', 'occ' => 'Occ %', 'arr' => 'Arr', 'dep' => 'Dep', 'ns' => 'No-show', 'room' => 'Room rev.', 'other' => 'Other rev.', 'tax' => 'Tax', 'coll' => 'Collections', 'adr' => 'ADR', 'revpar' => 'RevPAR'],
            'rows' => $rows, 'money' => $m, 'sum' => ['arr', 'dep', 'ns', 'room', 'other', 'tax', 'coll'], 'chart' => ['label' => 'Occupancy %', 'key' => 'occ', 'x' => 'date']];
    }

    protected function r_report_schedules($from, $to): array
    {
        // Rewritten against the real schema: name/report_key/range/frequency/send_time/format/recipient_*/is_active/next_run_at/last_run_at/last_status.
        $rows = ReportSchedule::with('user', 'view')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'report' => $r->report_key, 'range' => $r->range,
            'frequency' => $r->frequency, 'send_time' => $r->send_time, 'format' => $r->format,
            'owner' => optional($r->user)->name, 'active' => $r->is_active ? 'Yes' : 'No',
            'next_run' => $r->next_run_at, 'last_run' => $r->last_run_at, 'last_status' => $r->last_status,
        ]);
        return ['cols' => ['name' => 'Name', 'report' => 'Report', 'range' => 'Range', 'frequency' => 'Frequency', 'send_time' => 'Send Time', 'format' => 'Format', 'owner' => 'Owner', 'active' => 'Active', 'next_run' => 'Next Run', 'last_run' => 'Last Run', 'last_status' => 'Last Status'], 'rows' => $rows, 'note' => 'Report schedule definitions.'];
    }

    protected function r_report_views($from, $to): array
    {
        $rows = ReportView::with('report.user')->whereBetween('viewed_at', [$from, $to])->get()->map(fn ($r) => [
            'report' => optional($r->report)->name, 'viewed' => $r->viewed_at,
            'duration' => $r->view_duration_seconds, 'user' => optional($r->user)->name,
            'ip' => $r->ip_address, 'filters' => $r->filter_json,
        ]);
        return ['cols' => ['report' => 'Report', 'viewed' => 'Viewed', 'duration' => 'Duration (s)', 'user' => 'User', 'ip' => 'IP'], 'rows' => $rows, 'note' => 'Report view audit log.'];
    }

    protected function r_rpt_deliveries($from, $to): array
    {
        $rows = \App\Models\ReportDelivery::with('schedule.user', 'user')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get()
            ->map(fn ($d) => ['at' => $d->created_at->format('d-m-Y H:i'), 'schedule' => $d->title, 'report' => self::REPORTS[$d->report_key][1] ?? $d->report_key,
                'period' => $d->period_from ? $d->period_from->format('d-m-Y').($d->period_from->equalTo($d->period_to) ? '' : ' – '.$d->period_to->format('d-m-Y')) : '',
                'trigger' => ['schedule' => 'Scheduled', 'after_audit' => 'After audit', 'manual' => 'Sent now'][$d->trigger] ?? $d->trigger,
                'owner' => $d->schedule?->user?->name, 'status' => $d->status, 'rows' => $d->rows, 'recipients' => $d->recipients, 'delivered' => $d->delivered,
                'error' => $d->error, '_class' => $d->status === 'failed' ? 'row-red' : '']);

        return ['cols' => ['at' => 'Run at', 'schedule' => 'Schedule', 'report' => 'Report', 'period' => 'Period', 'trigger' => 'Trigger', 'owner' => 'Owner', 'status' => 'Status',
            'rows' => 'Rows', 'recipients' => 'Recipients', 'delivered' => 'Delivered', 'error' => 'Detail'],
            'rows' => $rows, 'sum' => ['rows', 'recipients', 'delivered'],
            'summary' => ['Runs' => (string) $rows->count(), 'Delivered' => (string) $rows->whereIn('status', ['sent', 'partial'])->count(), 'Failed' => (string) $rows->where('status', 'failed')->count(),
                'E-mails sent' => (string) $rows->sum('delivered')],
            'note' => 'Deliveries do not keep a copy of the report – download re-generates the same period from the Scheduled Reports page.'];
    }

    protected function r_user_activity($from, $to): array
    {
        $users = User::visibleToHotel()->pluck('name', 'id');
        $rows = ActivityLog::whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get(['user_id', 'module'])->groupBy(fn ($l) => $l->user_id.'|'.$l->module)
            ->map(function ($g, $k) use ($users) {
                [$u, $m] = explode('|', $k);

                return ['user' => $users[$u] ?? 'System', 'module' => $m, 'actions' => $g->count()];
            })->sortByDesc('actions')->values();

        return ['cols' => ['user' => 'User', 'module' => 'Module', 'actions' => 'Actions'], 'rows' => $rows, 'sum' => ['actions']];
    }

}
