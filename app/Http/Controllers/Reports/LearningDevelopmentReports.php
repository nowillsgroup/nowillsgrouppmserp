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
 * Learning & Development reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait LearningDevelopmentReports
{
    protected function r_sop_acknowledgement($from, $to): array
    {
        $rows = \App\Services\LearningService::sopOverview()->map(function ($r) {
            $d = $r['doc'];
            $out = $r['rows']->filter(fn ($x) => ! $x['ack'])->map(fn ($x) => $x['employee']->name);

            return ['code' => $d->code, 'title' => $d->title, 'dept' => $d->department?->name ?? 'Hotel-wide', 'version' => 'v'.$d->version.($r['version'] < $d->version ? ' (sign v'.$r['version'].'+)' : ''),
                'effective' => $d->effective_date?->format('d M Y') ?? '', 'review' => $d->review_due?->format('d M Y') ?? '', 'required' => $r['required'], 'signed' => $r['signed'],
                'outstanding' => $r['required'] - $r['signed'], 'pct' => $r['pct'] === null ? '—' : $r['pct'].'%', 'who' => $out->take(8)->implode(', ').($out->count() > 8 ? ' +'.($out->count() - 8) : ''),
                '_class' => $d->reviewOverdue() ? 'row-alert' : ($r['required'] > $r['signed'] ? 'row-warn' : '')];
        })->values();
        $req = $rows->sum('required');

        return ['cols' => ['code' => 'SOP', 'title' => 'Title', 'dept' => 'Department', 'version' => 'Version', 'effective' => 'Effective', 'review' => 'Review due', 'required' => 'Staff in scope',
            'signed' => 'Signed', 'outstanding' => 'Outstanding', 'pct' => 'Signed %', 'who' => 'Not signed yet'],
            'rows' => $rows, 'sum' => ['required', 'signed', 'outstanding'],
            'summary' => ['SOPs' => (string) $rows->count(), 'Signatures needed' => (string) $req, 'Signed' => $req ? round($rows->sum('signed') * 100 / $req, 1).'%' : '—',
                'Fully signed SOPs' => (string) $rows->where('outstanding', 0)->count()],
            'note' => 'Published SOPs that staff must sign, as at today. Signatures on earlier versions still count when later versions were minor changes; a version published with "staff must sign again" resets them.'];
    }

    protected function r_sop_acknowledgements($from, $to): array
    {
        $rows = SopAcknowledgement::with('sopVersion.employee.department')->whereBetween('acknowledged_at', [$from, $to])->get()->map(fn ($r) => [
            'sop' => optional($r->sopVersion->sopDocument)->title, 'employee' => optional($r->employee)->name,
            'dept' => optional($r->department)->name, 'acknowledged' => $r->acknowledged_at,
            'acknowledged_by' => optional($r->employee)->name, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['sop' => 'SOP', 'employee' => 'Employee', 'dept' => 'Dept', 'acknowledged' => 'Acknowledged', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'SOP acknowledgement register.'];
    }

    protected function r_sop_documents($from, $to): array
    {
        // Rewritten against the real schema: code/title/category/version/status/owner_id/created_by; no sopType relation.
        $rows = \App\Models\SopDocument::with('department', 'owner')->orderBy('title')->get()->map(fn ($r) => [
            'code' => $r->code, 'title' => $r->title, 'dept' => optional($r->department)->name,
            'category' => $r->categoryLabel(), 'version' => $r->version,
            'owner' => optional($r->owner)->name, 'status' => $r->statusLabel(),
            'review_due' => $r->review_due,
        ]);
        return ['cols' => ['code' => 'Code', 'title' => 'Title', 'dept' => 'Dept', 'category' => 'Category', 'version' => 'Version', 'owner' => 'Owner', 'status' => 'Status', 'review_due' => 'Review Due'], 'rows' => $rows, 'note' => 'SOP document register.'];
    }

    protected function r_sop_versions($from, $to): array
    {
        // Rewritten against the real schema: document() relation; published_by is a user id, publisher() resolves it.
        $rows = \App\Models\SopVersion::with('document.department', 'publisher')->orderByDesc('published_at')->get()->map(fn ($r) => [
            'sop' => optional($r->document)->title, 'dept' => optional(optional($r->document)->department)->name,
            'version' => $r->version, 'published' => $r->published_at,
            'publisher' => optional($r->publisher)->name, 'change_note' => $r->change_note,
        ]);
        return ['cols' => ['sop' => 'SOP', 'dept' => 'Dept', 'version' => 'Version', 'published' => 'Published', 'publisher' => 'Publisher', 'change_note' => 'Change Note'], 'rows' => $rows, 'note' => 'SOP version history.'];
    }

    protected function r_training_compliance($from, $to): array
    {
        $m = \App\Services\LearningService::matrix();
        $rows = $m['employees']->map(function ($e) use ($m) {
            $r = $m['rows'][$e->id];
            $gaps = collect($m['cells'][$e->id] ?? [])->filter(fn ($c) => in_array($c['state'], ['overdue', 'expired'], true))->keys()
                ->map(fn ($cid) => $m['courses']->firstWhere('id', $cid)?->code)->implode(', ');

            return ['emp' => $e->name, 'code' => $e->emp_code, 'dept' => $e->department?->name ?? '—', 'job' => (string) $e->designation, 'required' => $r['required'], 'current' => $r['current'],
                'expiring' => $r['expiring'], 'expired' => $r['expired'], 'overdue' => $r['overdue'], 'due' => $r['due'], 'pct' => $r['pct'] === null ? '—' : $r['pct'].'%', 'gaps' => $gaps,
                '_class' => $r['expired'] + $r['overdue'] ? 'row-alert' : ''];
        })->values();
        $t = $m['totals'];

        return ['cols' => ['emp' => 'Employee', 'code' => 'Code', 'dept' => 'Department', 'job' => 'Job title', 'required' => 'Required', 'current' => 'Current', 'expiring' => 'Expiring',
            'expired' => 'Expired', 'overdue' => 'Overdue', 'due' => 'New-starter due', 'pct' => 'Compliance', 'gaps' => 'Missing / lapsed'],
            'rows' => $rows, 'sum' => ['required', 'current', 'expiring', 'expired', 'overdue', 'due'],
            'summary' => ['Staff' => (string) $rows->count(), 'Compliance' => $t['pct'] === null ? '—' : $t['pct'].'%', 'Overdue' => (string) $t['overdue'], 'Expired' => (string) $t['expired'],
                'Expiring' => (string) $t['expiring']],
            'note' => 'As at the business date '.bdate()->format('d M Y').'. Compliance = current + expiring ÷ required (new starters still inside their grace period are left out). Expiring = within '.\App\Services\LearningService::cfg()['expiry_warn_days'].' days.'];
    }

    protected function r_training_courses($from, $to): array
    {
        // Rewritten against the real schema: category/delivery/duration_hours/cost_per_head/provider/is_active; there is no training type or instructor on the course.
        $rows = TrainingCourse::orderBy('name')->get()->map(fn ($r) => [
            'code' => $r->code, 'name' => $r->name, 'category' => $r->categoryLabel(),
            'delivery' => $r->delivery, 'duration' => $r->duration_hours,
            'cost' => (float) $r->cost_per_head, 'provider' => $r->provider,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['code' => 'Code', 'name' => 'Name', 'category' => 'Category', 'delivery' => 'Delivery', 'duration' => 'Duration (hrs)', 'cost' => 'Cost/Head', 'provider' => 'Provider', 'active' => 'Active'], 'rows' => $rows, 'money' => ['cost'], 'note' => 'Training course catalog.'];
    }

    protected function r_training_expiry($from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();
        $recs = \App\Models\TrainingRecord::with('course', 'employee.department')->whereIn('status', ['passed', 'exempt'])->whereNotNull('expires_on')->whereDate('expires_on', '>=', $f)->whereDate('expires_on', '<=', $t)
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))->orderBy('expires_on')->get();
        $booked = \App\Models\TrainingRecord::with('session')->where('status', 'enrolled')->whereHas('session', fn ($q) => $q->where('status', 'scheduled'))->get()
            ->mapWithKeys(fn ($r) => [$r->employee_id.'-'.$r->training_course_id => $r->session]);
        $rows = $recs->filter(function ($r) {   // only the latest certificate per employee & course (a renewal supersedes it)
            return ! \App\Models\TrainingRecord::where('employee_id', $r->employee_id)->where('training_course_id', $r->training_course_id)->whereIn('status', ['passed', 'exempt'])
                ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>', $r->expires_on->toDateString()))->exists();
        })->map(function ($r) use ($booked) {
            $s = $booked[$r->employee_id.'-'.$r->training_course_id] ?? null;
            $days = (int) bdate()->diffInDays($r->expires_on, false);

            return ['expires' => $r->expires_on->format('d M Y'), 'days' => $days, 'emp' => $r->employee->name, 'dept' => $r->employee->department?->name ?? '—', 'course' => $r->course->name,
                'cert' => (string) $r->certificate_no, 'completed' => $r->completed_on?->format('d M Y') ?? '', 'renewal' => $s ? $s->number.' '.$s->date->format('d M') : '',
                '_class' => $days < 0 ? 'row-alert' : ($s ? '' : 'row-warn')];
        })->values();

        return ['cols' => ['expires' => 'Expires', 'days' => 'Days left', 'emp' => 'Employee', 'dept' => 'Department', 'course' => 'Course', 'cert' => 'Certificate', 'completed' => 'Completed', 'renewal' => 'Renewal booked'],
            'rows' => $rows, 'summary' => ['Expiring' => (string) $rows->count(), 'Already expired' => (string) $rows->where('days', '<', 0)->count(), 'Renewal booked' => (string) $rows->where('renewal', '!=', '')->count(),
                'Not booked' => (string) $rows->where('renewal', '')->count()],
            'note' => 'Certificates of current staff with an expiry date in the range – the latest certificate per person and course only. Book a refresher before the expiry date to stay compliant.'];
    }

    protected function r_training_hours($from, $to): array
    {
        $recs = \App\Models\TrainingRecord::with('course', 'employee.department')->whereIn('status', ['passed', 'failed'])
            ->whereDate('completed_on', '>=', Carbon::parse($from)->toDateString())->whereDate('completed_on', '<=', Carbon::parse($to)->toDateString())->get();
        $staff = \App\Services\LearningService::employees()->groupBy(fn ($e) => $e->department?->name ?? '—');
        $rows = $staff->keys()->merge($recs->map(fn ($r) => $r->employee->department?->name ?? '—'))->unique()->sort()->map(function ($d) use ($recs, $staff) {
            $g = $recs->filter(fn ($r) => ($r->employee->department?->name ?? '—') === $d);
            $n = $staff->get($d, collect())->count();
            $hours = round($g->where('status', 'passed')->sum(fn ($r) => (float) $r->course->duration_hours), 1);
            $cost = round($g->sum(fn ($r) => (float) $r->cost), 2);

            return ['dept' => $d, 'staff' => $n, 'trained' => $g->pluck('employee_id')->unique()->count(), 'outcomes' => $g->count(), 'hours' => $hours,
                'per_head' => $n ? round($hours / $n, 1) : 0.0, 'cost' => $cost, 'cost_head' => $n ? round($cost / $n, 2) : 0.0,
                'courses' => $g->groupBy(fn ($r) => $r->course->code)->map(fn ($x, $k) => $k.' ×'.$x->count())->implode(', ')];
        })->values();
        $n = $rows->sum('staff');

        return ['cols' => ['dept' => 'Department', 'staff' => 'Staff', 'trained' => 'Staff trained', 'outcomes' => 'Course outcomes', 'hours' => 'Hours', 'per_head' => 'Hours / head',
            'cost' => 'Cost', 'cost_head' => 'Cost / head', 'courses' => 'Courses'],
            'rows' => $rows, 'money' => ['cost', 'cost_head'], 'sum' => ['staff', 'trained', 'outcomes', 'hours', 'cost'],
            'summary' => ['Hours' => number_format($rows->sum('hours'), 1), 'Hours per head' => $n ? number_format($rows->sum('hours') / $n, 1) : '0', 'Spend' => money($rows->sum('cost')),
                'Spend per head' => money($n ? $rows->sum('cost') / $n : 0)],
            'note' => 'Completed training (passed or failed) in the period. Hours count passed courses at the course duration; "per head" divides by current staff in the department.'];
    }

    protected function r_training_records($from, $to): array
    {
        $R = \App\Models\TrainingRecord::class;
        $rows = \App\Models\TrainingRecord::with('course', 'employee.department', 'session')->whereIn('status', ['passed', 'failed', 'no_show'])
            ->whereDate('completed_on', '>=', Carbon::parse($from)->toDateString())->whereDate('completed_on', '<=', Carbon::parse($to)->toDateString())->orderBy('completed_on')->orderBy('id')->get()
            ->map(fn ($r) => ['date' => $r->completed_on->format('d M Y'), 'emp' => $r->employee->name, 'dept' => $r->employee->department?->name ?? '—', 'course' => $r->course->name,
                'source' => $r->session ? $r->session->number : ($R::SOURCES[$r->source] ?? $r->source), 'provider' => (string) ($r->provider ?: ($r->session?->trainerLabel() ?? '')),
                'result' => $r->statusLabel(), 'score' => $r->score !== null ? (float) $r->score : '', 'hours' => $r->status === 'passed' ? (float) $r->course->duration_hours : 0.0,
                'cost' => (float) $r->cost, 'cert' => (string) $r->certificate_no, '_class' => $r->status === 'passed' ? '' : 'row-alert'])->values();

        return ['cols' => ['date' => 'Date', 'emp' => 'Employee', 'dept' => 'Department', 'course' => 'Course', 'source' => 'Session / source', 'provider' => 'Trainer / provider', 'result' => 'Result',
            'score' => 'Score %', 'hours' => 'Hours', 'cost' => 'Cost', 'cert' => 'Certificate'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['hours', 'cost'],
            'summary' => ['Outcomes' => (string) $rows->count(), 'Passed' => (string) $rows->where('result', 'Passed')->count(), 'Failed' => (string) $rows->where('result', 'Failed')->count(),
                'No-shows' => (string) $rows->where('result', 'No-show')->count(), 'Hours' => number_format($rows->sum('hours'), 1), 'Cost' => money($rows->sum('cost'))],
            'note' => 'By completion date. Hours count passed courses only; cost is the session cost shared across attendees (or the per-head rate) plus external fees.'];
    }

    protected function r_training_requirements($from, $to): array
    {
        // Rewritten against the real schema: requirements link a course to a department + designation with a due window.
        $rows = TrainingRequirement::with('course', 'department')->orderBy('department_id')->get()->map(fn ($r) => [
            'dept' => optional($r->department)->name, 'designation' => $r->designation,
            'course' => optional($r->course)->name, 'due_days' => $r->due_days,
        ]);
        return ['cols' => ['dept' => 'Dept', 'designation' => 'Designation', 'course' => 'Course', 'due_days' => 'Due (days)'], 'rows' => $rows, 'note' => 'Training requirement matrix.'];
    }

    protected function r_training_sessions($from, $to): array
    {
        $rows = TrainingSession::with('course.instructor')->whereBetween('session_date', [$from, $to])->get()->map(fn ($r) => [
            'course' => optional($r->course)->name, 'instructor' => optional($r->instructor)->name,
            'date' => $r->session_date, 'start' => $r->start_time, 'end' => $r->end_time,
            'location' => $r->location, 'capacity' => $r->capacity, 'enrolled' => $r->enrolled,
            'type' => $r->session_type, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['course' => 'Course', 'instructor' => 'Instructor', 'date' => 'Date', 'start' => 'Start', 'end' => 'End', 'location' => 'Location', 'capacity' => 'Capacity', 'enrolled' => 'Enrolled', 'status' => 'Status'], 'rows' => $rows, 'sum' => ['capacity', 'enrolled'], 'note' => 'Training session schedule with enrollment.'];
    }

}
