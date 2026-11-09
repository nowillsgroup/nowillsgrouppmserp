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
 * Talent Management reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait TalentManagementReports
{
    protected function r_talent_appraisals($from, $to): array
    {
        $list = \App\Models\TalentAppraisal::with(['cycle', 'employee.department', 'reviewer', 'signer'])
            ->whereHas('cycle', fn ($q) => $q->whereDate('period_to', '>=', $from->toDateString())->whereDate('period_to', '<=', $to->toDateString()))->get()->sortBy(fn ($a) => $a->cycle_id.'|'.$a->employee->name)->values();
        $rows = $list->map(fn ($a) => ['cycle' => $a->cycle->name, 'emp' => $a->employee->name, 'dept' => $a->employee->department?->name ?? '', 'rev' => $a->reviewer?->name ?? '',
            'goals' => $a->goals_score !== null ? (float) $a->goals_score : null, 'comp' => $a->comp_score !== null ? (float) $a->comp_score : null,
            'overall' => $a->overall !== null ? number_format($a->overall, 2) : '', 'band' => $a->bandLabel() ?? '', 'pip' => $a->pip ? 'PIP' : '',
            'status' => $a->statusLabel(), 'signed' => $a->signed_at ? $a->signer?->name.' · '.$a->signed_at->format('d M') : '', 'ack' => $a->acknowledged_at?->format('d M Y') ?? '',
            '_class' => $a->pip ? 'row-red' : ($a->status !== 'signed' ? 'row-amber' : '')]);
        $scored = $list->whereNotNull('overall');

        return ['cols' => ['cycle' => 'Cycle', 'emp' => 'Employee', 'dept' => 'Department', 'rev' => 'Reviewer', 'goals' => 'Goals', 'comp' => 'Competencies', 'overall' => 'Overall',
            'band' => 'Band', 'pip' => 'PIP', 'status' => 'Status', 'signed' => 'Signed off', 'ack' => 'Acknowledged'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Appraisals' => (string) $list->count(), 'Signed off' => (string) $list->where('status', 'signed')->count(),
                'Average overall' => $scored->isNotEmpty() ? number_format($scored->avg('overall'), 2) : '—', 'On a PIP' => (string) $list->where('pip', true)->count(),
                'Exceeds or better' => (string) $scored->where('band', '>=', 4)->count()]];
    }

    protected function r_talent_cycles($from, $to): array
    {
        $rows = TalentCycle::with('cycleType.department')->whereBetween('cycle_start', [$from, $to])->get()->map(fn ($r) => [
            'name' => $r->name, 'type' => optional($r->cycleType)->name, 'dept' => optional($r->department)->name,
            'start' => $r->cycle_start, 'end' => $r->cycle_end, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['name' => 'Name', 'type' => 'Type', 'dept' => 'Dept', 'start' => 'Start', 'end' => 'End', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Talent review cycle register.'];
    }

    protected function r_talent_discipline($from, $to): array
    {
        $u = auth()->user();
        $list = \App\Models\TalentCase::with(['employee.department', 'raiser', 'decider'])->whereDate('incident_on', '>=', $from->toDateString())->whereDate('incident_on', '<=', $to->toDateString())->orderByDesc('incident_on')->get();
        $rows = $list->map(function ($c) use ($u) {
            $see = \App\Services\TalentService::canSeeCase($c, $u);

            return ['no' => $c->number, 'kind' => ucfirst($c->kind), 'emp' => $see ? $c->employee->name : 'Confidential', 'dept' => $c->employee->department?->name ?? '',
                'cat' => $see ? $c->categoryLabel() : 'Confidential', 'sev' => $c->severity ? \App\Models\TalentCase::SEVERITY[$c->severity] : '', 'on' => $c->incident_on->format('d M Y'),
                'query' => $c->query_issued_on ? $c->query_issued_on->format('d M').' → '.($c->replied_on ? 'replied '.$c->replied_on->format('d M') : 'reply by '.$c->reply_due->format('d M')) : '',
                'hearing' => $c->hearing_at ? $c->hearing_at->format('d M').($c->hearing_held ? ' · held' : '') : '', 'finding' => $c->finding ? ($c->finding === 'proven' ? 'Proven' : 'Not proven') : '',
                'outcome' => $see ? ($c->outcomeLabel() ?? ($c->kind === 'grievance' && $c->resolution ? 'Resolved' : '')) : '', 'expires' => $c->expires_on?->format('d M Y') ?? '',
                'status' => $c->statusLabel(), '_class' => $c->outcome === 'dismissal' ? 'row-red' : ''];
        });
        $disc = $list->where('kind', 'discipline');

        return ['cols' => ['no' => 'Case', 'kind' => 'Type', 'emp' => 'Employee', 'dept' => 'Department', 'cat' => 'Category', 'sev' => 'Severity', 'on' => 'Incident', 'query' => 'Query',
            'hearing' => 'Hearing', 'finding' => 'Finding', 'outcome' => 'Sanction / resolution', 'expires' => 'On file until', 'status' => 'Status'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Disciplinary cases' => (string) $disc->count(), 'Proven' => (string) $disc->where('finding', 'proven')->count(), 'Dismissals' => (string) $disc->where('outcome', 'dismissal')->count(),
                'Grievances' => (string) $list->where('kind', 'grievance')->count(), 'Open' => (string) $list->whereNotIn('status', ['closed', 'withdrawn', 'decided', 'resolved'])->count()]];
    }

    protected function r_talent_exits($from, $to): array
    {
        $list = \App\Models\TalentExit::with('employee.department')->where('status', '!=', 'withdrawn')->whereDate('last_day', '>=', $from->toDateString())->whereDate('last_day', '<=', $to->toDateString())->orderBy('last_day')->get();
        $rows = $list->map(fn ($x) => ['no' => $x->number, 'emp' => $x->employee->name, 'dept' => $x->employee->department?->name ?? '', 'type' => $x->typeLabel(),
            'service' => number_format(\App\Services\TalentService::serviceYears($x->employee, $x->last_day), 1), 'notice' => $x->notice_days, 'last' => $x->last_day->format('d M Y'),
            'pay' => $x->final_pay_due->format('d M Y'), 'pilon' => $x->pilon ? (float) $x->pilon_amount : null,
            'why' => isset($x->interview['primary']) ? (\App\Services\TalentService::EXIT_REASONS[$x->interview['primary']] ?? '') : '', 'reg' => $x->regretted ? 'Yes' : '', 'rehire' => $x->rehire ? 'Yes' : 'No',
            'status' => $x->statusLabel(), '_class' => $x->status === 'notice' ? 'row-amber' : '']);
        $t = \App\Services\TalentService::turnover($to->copy());

        return ['cols' => ['no' => 'Ref', 'emp' => 'Employee', 'dept' => 'Department', 'type' => 'Type', 'service' => 'Service (yrs)', 'notice' => 'Notice (days)', 'last' => 'Last day',
            'pay' => 'Final pay by', 'pilon' => 'Pay in lieu', 'why' => 'Main reason', 'reg' => 'Regretted', 'rehire' => 'Re-hire', 'status' => 'Status'], 'rows' => $rows,
            'money' => ['pilon'], 'sum' => ['pilon'],
            'summary' => ['Leavers' => (string) $list->count(), 'Voluntary' => (string) $list->filter->isVoluntary()->count(), 'Regretted' => (string) $list->where('regretted', true)->count(),
                '12-month turnover to '.$to->format('d M Y') => $t['rate'].'%', 'Voluntary turnover' => $t['voluntary_rate'].'%']];
    }

    protected function r_talent_headcount($from, $to): array
    {
        $d = $to->toDateString();
        $y = $to->copy()->subYear()->addDay()->toDateString();
        $emps = \App\Models\Employee::get();
        $inPost = fn ($e, $on) => (! $e->joining_date || $e->joining_date->toDateString() <= $on) && ($e->leaving_date ? $e->leaving_date->toDateString() >= $on : $e->is_active);
        $vac = \App\Models\TalentVacancy::whereIn('status', ['open', 'on_hold', 'pending'])->get()->groupBy('department_id');
        $rows = \App\Models\Department::orderBy('name')->get()->map(function ($dep) use ($emps, $inPost, $d, $y, $vac) {
            $mine = $emps->where('department_id', $dep->id);
            $now = $mine->filter(fn ($e) => $inPost($e, $d))->count();
            $start = $mine->filter(fn ($e) => $inPost($e, $y))->count();
            $leavers = $mine->filter(fn ($e) => $e->leaving_date && $e->leaving_date->toDateString() >= $y && $e->leaving_date->toDateString() <= $d)->count();
            $avg = ($now + $start) / 2;

            return ['dept' => $dep->name, 'est' => $dep->establishment, 'staff' => $now, 'gap' => $dep->establishment !== null ? $dep->establishment - $now : null,
                'open' => $vac->get($dep->id, collect())->sum(fn ($v) => $v->remaining()) ?: null,
                'starters' => $mine->filter(fn ($e) => $e->joining_date && $e->joining_date->toDateString() >= $y && $e->joining_date->toDateString() <= $d)->count(),
                'leavers' => $leavers, 'turn' => $avg > 0 ? number_format($leavers / $avg * 100, 1).'%' : '',
                'prob' => $mine->filter(fn ($e) => $inPost($e, $d) && $e->probation_end && ! $e->confirmed_on)->count(),
                '_class' => $dep->establishment !== null && $now > $dep->establishment ? 'row-amber' : ''];
        });

        return ['cols' => ['dept' => 'Department', 'est' => 'Establishment', 'staff' => 'In post', 'gap' => 'Gap', 'open' => 'Open posts', 'starters' => 'Starters (12 m)', 'leavers' => 'Leavers (12 m)',
            'turn' => 'Turnover', 'prob' => 'On probation'], 'rows' => $rows, 'money' => [], 'sum' => ['est', 'staff', 'gap', 'open', 'starters', 'leavers', 'prob'],
            'summary' => ['In post' => (string) $rows->sum('staff'), 'Establishment' => (string) $rows->sum('est'), 'Open posts' => (string) $rows->sum('open'),
                'Starters (12 m)' => (string) $rows->sum('starters'), 'Leavers (12 m)' => (string) $rows->sum('leavers')]];
    }

    protected function r_talent_interviews($from, $to): array
    {
        $rows = TalentInterview::with('vacancy.interviewer.applicant')->whereBetween('interview_date', [$from, $to])->get()->map(fn ($r) => [
            'vacancy' => optional($r->vacancy)->title, 'applicant' => optional($r->applicant)->name,
            'interviewer' => optional($r->interviewer)->name, 'date' => $r->interview_date,
            'time' => $r->interview_time, 'location' => $r->location, 'format' => $r->interview_format,
            'result' => $r->result, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['vacancy' => 'Vacancy', 'applicant' => 'Applicant', 'interviewer' => 'Interviewer', 'date' => 'Date', 'time' => 'Time', 'location' => 'Location', 'format' => 'Format', 'result' => 'Result', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Talent interview schedule.'];
    }

    protected function r_talent_offers($from, $to): array
    {
        $rows = TalentOffer::with('vacancy.applicant.offeredBy')->whereBetween('offered_at', [$from, $to])->get()->map(fn ($r) => [
            'vacancy' => optional($r->vacancy)->title, 'applicant' => optional($r->applicant)->name,
            'salary' => $r->salary_offered, 'start_date' => $r->start_date, 'offered' => $r->offered_at,
            'accepted' => $r->accepted_at, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['vacancy' => 'Vacancy', 'applicant' => 'Applicant', 'salary' => 'Salary', 'start_date' => 'Start Date', 'offered' => 'Offered', 'accepted' => 'Accepted', 'status' => 'Status'], 'rows' => $rows, 'money' => ['salary_offered'], 'note' => 'Talent offer register.'];
    }

    protected function r_talent_pipeline($from, $to): array
    {
        $apps = \App\Models\TalentApplicant::with(['vacancy.department', 'interviews.scorecards', 'offers'])->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $rows = $apps->groupBy('vacancy_id')->map(function ($g) {
            $v = $g->first()->vacancy;
            $iv = $g->filter(fn ($a) => $a->interviews->isNotEmpty());
            $sc = $g->flatMap(fn ($a) => $a->interviews->flatMap->scorecards);
            $hired = $g->where('stage', 'hired')->count();

            return ['vac' => $v->number.' '.$v->title, 'dept' => $v->department->name, 'apps' => $g->count(),
                'screened' => $g->filter(fn ($a) => $a->screen_rating || $a->interviews->isNotEmpty() || in_array($a->stage, ['screening', 'interview', 'offer', 'hired'], true))->count(),
                'interviewed' => $iv->count(), 'offered' => $g->filter(fn ($a) => $a->offers->isNotEmpty())->count(),
                'hired' => $hired, 'rejected' => $g->whereIn('stage', ['rejected', 'withdrawn'])->count(), 'conv' => $g->count() ? number_format($hired / $g->count() * 100, 1).'%' : '',
                'score' => $sc->isNotEmpty() ? number_format($sc->avg('average'), 2) : ''];
        })->values();
        $src = $apps->groupBy('source')->map(fn ($g, $k) => (\App\Models\TalentApplicant::SOURCES[$k] ?? $k).' '.$g->count().' ('.$g->where('stage', 'hired')->count().' hired)')->implode(' · ');

        return ['cols' => ['vac' => 'Vacancy', 'dept' => 'Department', 'apps' => 'Applicants', 'screened' => 'Screened', 'interviewed' => 'Interviewed', 'offered' => 'Offered', 'hired' => 'Hired',
            'rejected' => 'Rejected / withdrawn', 'conv' => 'Applicant → hire', 'score' => 'Avg interview score'], 'rows' => $rows, 'money' => [],
            'sum' => ['apps', 'screened', 'interviewed', 'offered', 'hired', 'rejected'],
            'summary' => ['Applicants' => (string) $apps->count(), 'Hired' => (string) $apps->where('stage', 'hired')->count(),
                'Still in progress' => (string) $apps->whereIn('stage', \App\Models\TalentApplicant::OPEN)->count(), 'Anonymised' => (string) $apps->whereNotNull('anonymised_at')->count()],
            'note' => $src ? 'By source: '.$src : null];
    }

    protected function r_talent_probations($from, $to): array
    {
        $rows = TalentProbation::with('employee.department.probationer')->whereBetween('probation_start', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'dept' => optional($r->department)->name,
            'start' => $r->probation_start, 'end' => $r->probation_end, 'review_date' => $r->review_date,
            'status' => $r->status, 'result' => $r->result, 'notes' => $r->notes,
        ]);
        return ['cols' => ['employee' => 'Employee', 'dept' => 'Dept', 'start' => 'Start', 'end' => 'End', 'review_date' => 'Review Date', 'result' => 'Result', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Talent probation tracking.'];
    }

    protected function r_talent_scorecards($from, $to): array
    {
        $rows = TalentScorecard::with('employee.cycle.scorer')->whereBetween('scored_at', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'cycle' => optional($r->cycle)->name,
            'scorer' => optional($r->scorer)->name, 'scored' => $r->scored_at,
            'overall_score' => $r->overall_score, 'rating' => $r->rating, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['employee' => 'Employee', 'cycle' => 'Cycle', 'scorer' => 'Scorer', 'scored' => 'Scored', 'overall' => 'Overall Score', 'rating' => 'Rating', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Talent scorecard register.'];
    }

    protected function r_talent_tasks($from, $to): array
    {
        $rows = TalentTask::with('employee.taskType.assignedBy')->whereBetween('assigned_at', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'type' => optional($r->taskType)->name,
            'assigned_by' => optional($r->assignedBy)->name, 'description' => $r->description,
            'due_date' => $r->due_date, 'completed' => $r->completed_at, 'status' => $r->status,
        ]);
        return ['cols' => ['employee' => 'Employee', 'type' => 'Type', 'assigned_by' => 'Assigned By', 'description' => 'Description', 'due' => 'Due Date', 'completed' => 'Completed', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Talent development task register.'];
    }

    protected function r_talent_vacancies($from, $to): array
    {
        $list = \App\Models\TalentVacancy::with(['department', 'requester', 'approver'])->withCount(['applicants', 'applicants as hires_count' => fn ($q) => $q->where('stage', 'hired')])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('id')->get();
        $rows = $list->map(function ($v) {
            $days = $v->approved_at && $v->filled_on ? (int) $v->approved_at->copy()->startOfDay()->diffInDays($v->filled_on) : null;

            return ['no' => $v->number, 'title' => $v->title, 'dept' => $v->department->name, 'type' => $v->typeLabel(), 'posts' => $v->filled.'/'.$v->headcount,
                'min' => (float) $v->salary_min, 'max' => (float) $v->salary_max, 'reason' => \App\Models\TalentVacancy::REASONS[$v->reason], 'req' => $v->requester?->name ?? '',
                'appr' => $v->approver ? $v->approver->name.' · '.$v->approved_at->format('d M') : '', 'apps' => $v->applicants_count, 'hires' => $v->hires_count,
                'days' => $days, 'status' => $v->statusLabel(), '_class' => $v->status === 'pending' ? 'row-amber' : ''];
        });
        $filled = $rows->whereNotNull('days');

        return ['cols' => ['no' => 'Ref', 'title' => 'Vacancy', 'dept' => 'Department', 'type' => 'Type', 'posts' => 'Filled', 'min' => 'Salary from', 'max' => 'Salary to', 'reason' => 'Reason',
            'req' => 'Requested by', 'appr' => 'Approved', 'apps' => 'Applicants', 'hires' => 'Hired', 'days' => 'Days to fill', 'status' => 'Status'], 'rows' => $rows,
            'money' => ['min', 'max'], 'sum' => ['apps', 'hires'],
            'summary' => ['Vacancies' => (string) $list->count(), 'Posts' => (string) $list->sum('headcount'), 'Filled' => (string) $list->sum('filled'),
                'Open' => (string) $list->where('status', 'open')->count(), 'Average days to fill' => $filled->isNotEmpty() ? number_format($filled->avg('days'), 0) : '—']];
    }

}
