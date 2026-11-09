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
 * Quality Assurance reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait QualityAssuranceReports
{
    protected function r_qa_actions($from, $to): array
    {
        $X = \App\Models\QaAction::class;
        $rows = $X::with(['department', 'owner', 'audit'])->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(function ($x) {
                $closed = in_array($x->status, ['done', 'verified'], true) && $x->completed_at;

                return ['raised' => $x->created_at->format('d M Y'), 'no' => $x->number, 'audit' => $x->audit?->number ?? 'walk-round', 'title' => $x->title, 'dept' => $x->department?->name ?? '—',
                    'owner' => $x->owner?->name ?? 'unassigned', 'pri' => $x->priorityLabel(), 'due' => $x->due_date->format('d M Y'), 'status' => $x->statusLabel(),
                    'days' => $closed ? (int) $x->created_at->copy()->startOfDay()->diffInDays($x->completed_at->copy()->startOfDay()) : '',
                    'ontime' => $closed ? ($x->completed_at->toDateString() <= $x->due_date->toDateString() ? 'Yes' : 'Late') : ($x->isOverdue() ? 'Overdue' : ''),
                    'reopened' => $x->reopened ?: null, '_class' => $x->isOverdue() ? 'row-red' : ($closed && $x->completed_at->toDateString() > $x->due_date->toDateString() ? 'row-amber' : '')];
            });
        $closed = $rows->whereIn('ontime', ['Yes', 'Late']);

        return ['cols' => ['raised' => 'Raised', 'no' => 'Action', 'audit' => 'Source', 'title' => 'Finding', 'dept' => 'Department', 'owner' => 'Owner', 'pri' => 'Priority', 'due' => 'Due',
            'status' => 'Status', 'days' => 'Days to fix', 'ontime' => 'On time', 'reopened' => 'Reopened'], 'rows' => $rows, 'money' => [], 'sum' => ['reopened'],
            'summary' => ['Raised' => (string) $rows->count(), 'Closed' => (string) $closed->count(), 'On time' => $closed->count() ? round($closed->where('ontime', 'Yes')->count() / $closed->count() * 100, 1).'%' : '—',
                'Overdue now' => (string) $rows->where('ontime', 'Overdue')->count()],
            'note' => 'Days to fix = raised to marked done. Done actions still need verification by a quality manager other than the owner.'];
    }

    protected function r_qa_audit_items($from, $to): array
    {
        // Rewritten against the real schema: audit()/standard() relations; code/area/title/weight/result/note/is_repeat; the parent's date is scheduled_for and its number is `number`.
        $rows = QaAuditItem::with('audit.department', 'standard')->whereHas('audit', fn ($q) => $q->whereBetween('scheduled_for', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->orderBy('qa_audit_id')->orderBy('sort')->get()->map(fn ($r) => [
            'audit' => optional($r->audit)->number, 'dept' => optional(optional($r->audit)->department)->name,
            'standard' => optional($r->standard)->title,
            'area' => $r->area, 'title' => $r->title,
            'weight' => $r->weight, 'result' => $r->result,
            'note' => $r->note, 'repeat' => $r->is_repeat ? 'Yes' : 'No',
        ]);
        return ['cols' => ['audit' => 'Audit No', 'dept' => 'Dept', 'standard' => 'Standard', 'area' => 'Area', 'title' => 'Item', 'weight' => 'Weight', 'result' => 'Result', 'note' => 'Note', 'repeat' => 'Repeat'], 'rows' => $rows, 'note' => 'QA audit line items.'];
    }

    protected function r_qa_audits($from, $to): array
    {
        $rows = \App\Models\QaAudit::with(['template', 'department', 'auditor', 'acknowledger'])->where('status', 'completed')
            ->whereBetween('completed_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('completed_at')->get()
            ->map(fn ($a) => ['date' => $a->completed_at->format('d M Y'), 'no' => $a->number, 'checklist' => $a->template?->name ?? $a->title, 'type' => $a->typeLabel(),
                'dept' => $a->department?->name ?? 'Whole hotel', 'auditor' => $a->auditorName(), 'score' => (float) $a->score_pct, 'pass' => (float) $a->pass_mark,
                'result' => $a->passed ? 'Pass' : ($a->critical_fails && $a->score_pct >= $a->pass_mark ? 'Fail (critical)' : 'Fail'), 'fails' => $a->fails, 'crit' => $a->critical_fails ?: null,
                'rep' => $a->repeat_fails ?: null, 'ack' => $a->acknowledged_at ? $a->acknowledger?->name.' '.$a->acknowledged_at->format('d M') : '', '_class' => $a->passed ? '' : 'row-red'])->values();

        return ['cols' => ['date' => 'Completed', 'no' => 'Audit', 'checklist' => 'Checklist', 'type' => 'Type', 'dept' => 'Department', 'auditor' => 'Auditor', 'score' => 'Score %', 'pass' => 'Pass mark %',
            'result' => 'Result', 'fails' => 'Findings', 'crit' => 'Critical', 'rep' => 'Repeat', 'ack' => 'Acknowledged'], 'rows' => $rows, 'money' => [], 'sum' => ['fails', 'crit', 'rep'],
            'summary' => ['Audits' => (string) $rows->count(), 'Average score' => $rows->count() ? number_format($rows->avg('score'), 1).'%' : '—',
                'Passed' => $rows->where('result', 'Pass')->count().' of '.$rows->count()],
            'note' => 'Score = points of passed standards ÷ points of standards checked (n/a excluded).'.(\App\Services\QaService::cfg()['critical_autofail'] ? ' A failed critical standard fails the audit whatever the score.' : '')];
    }

    protected function r_qa_department_scores($from, $to): array
    {
        $cfg = \App\Services\QaService::cfg();
        $audits = \App\Models\QaAudit::with('department')->where('status', 'completed')->whereBetween('completed_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $open = \App\Models\QaAction::whereIn('status', \App\Models\QaAction::OPEN)->get(['department_id', 'due_date'])->groupBy(fn ($x) => (int) $x->department_id);
        $rows = $audits->groupBy(fn ($a) => (int) $a->department_id)->map(function ($g, $id) use ($cfg, $open) {
            $avg = round((float) $g->avg('score_pct'), 1);
            $int = $g->whereIn('type', ['internal', 'walkthrough']);
            $ext = $g->whereIn('type', ['mystery', 'brand']);
            $o = $open[$id] ?? collect();

            return ['dept' => $g->first()->department?->name ?? 'Whole hotel', 'audits' => $g->count(), 'avg' => $avg, 'internal' => $int->count() ? round((float) $int->avg('score_pct'), 1) : '',
                'external' => $ext->count() ? round((float) $ext->avg('score_pct'), 1) : '', 'best' => (float) $g->max('score_pct'), 'worst' => (float) $g->min('score_pct'),
                'rate' => round($g->where('passed', true)->count() / $g->count() * 100, 1), 'fails' => (int) $g->sum('fails'), 'crit' => (int) $g->sum('critical_fails') ?: null,
                'open' => $o->count() ?: null, 'overdue' => $o->filter(fn ($x) => $x->due_date->lt(bdate()->copy()->startOfDay()))->count() ?: null,
                '_class' => $avg < $cfg['pass_mark'] ? 'row-red' : ($avg < $cfg['target_score'] ? 'row-amber' : '')];
        })->sortBy('avg')->values();

        return ['cols' => ['dept' => 'Department', 'audits' => 'Audits', 'avg' => 'Average %', 'internal' => 'Internal %', 'external' => 'Mystery / brand %', 'best' => 'Best %', 'worst' => 'Worst %',
            'rate' => 'Pass rate %', 'fails' => 'Findings', 'crit' => 'Critical', 'open' => 'Open actions', 'overdue' => 'Overdue actions'], 'rows' => $rows, 'money' => [], 'sum' => ['audits', 'fails', 'crit', 'open', 'overdue'],
            'summary' => ['Hotel average' => $audits->count() ? number_format($audits->avg('score_pct'), 1).'%' : '—', 'Target' => $cfg['target_score'].'%', 'Default pass mark' => $cfg['pass_mark'].'%'],
            'note' => 'Red: average below the pass mark; amber: below the target. Open / overdue actions are as of today.'];
    }

    protected function r_qa_standard_failures($from, $to): array
    {
        $rows = \App\Services\QaService::topFailures(Carbon::parse($from), Carbon::parse($to), 1000)->map(fn ($f) => ['code' => $f['standard']->code, 'std' => $f['standard']->title,
            'area' => $f['standard']->area, 'dept' => $f['standard']->department?->name ?? '—', 'weight' => $f['standard']->weight, 'crit' => $f['standard']->is_critical ? 'Yes' : '',
            'checks' => $f['checks'], 'fails' => $f['fails'], 'rate' => $f['rate'], 'rep' => $f['repeats'] ?: null, 'open' => $f['open'] ?: null,
            '_class' => $f['standard']->is_critical ? 'row-red' : ($f['repeats'] ? 'row-amber' : '')]);

        return ['cols' => ['code' => 'Code', 'std' => 'Standard', 'area' => 'Area', 'dept' => 'Department', 'weight' => 'Weight', 'crit' => 'Critical', 'checks' => 'Checked', 'fails' => 'Failed',
            'rate' => 'Fail rate %', 'rep' => 'Repeat', 'open' => 'Open actions'], 'rows' => $rows, 'money' => [], 'sum' => ['fails', 'rep', 'open'],
            'summary' => ['Standards failed' => (string) $rows->count(), 'Critical standards failed' => (string) $rows->where('crit', 'Yes')->count()],
            'note' => 'Checked counts pass + fail only (n/a excluded). Repeat = failed again after failing on the previous audit of the same checklist.'];
    }

    protected function r_qa_standards($from, $to): array
    {
        // Rewritten against the real schema: code/department_id/area/title/weight/is_critical/is_active; there is no QA category.
        $rows = QaStandard::with('department')->orderBy('sort')->orderBy('title')->get()->map(fn ($r) => [
            'code' => $r->code, 'title' => $r->title, 'area' => $r->area,
            'dept' => optional($r->department)->name, 'weight' => $r->weight,
            'critical' => $r->is_critical ? 'Yes' : 'No', 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['code' => 'Code', 'title' => 'Title', 'area' => 'Area', 'dept' => 'Dept', 'weight' => 'Weight', 'critical' => 'Critical', 'active' => 'Active'], 'rows' => $rows, 'note' => 'QA audit standard definitions.'];
    }

    protected function r_qa_template_compliance($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $days = (int) $f->diffInDays($t->copy()->startOfDay()) + 1;
        $due = \App\Services\QaService::due()->keyBy(fn ($d) => $d['template']->id);
        $rows = \App\Models\QaTemplate::with('department')->where('is_active', true)->orderBy('name')->get()->map(function ($tp) use ($f, $t, $days, $due) {
            $done = \App\Models\QaAudit::where('qa_template_id', $tp->id)->where('status', 'completed')->whereBetween('completed_at', [$f, $t])->get(['score_pct', 'passed', 'completed_at']);
            $req = $tp->frequency_days ? max(1, (int) floor($days / $tp->frequency_days)) : null;
            $pct = $req ? min(100, round($done->count() / $req * 100, 1)) : '';
            $d = $due[$tp->id] ?? null;

            return ['name' => $tp->name, 'type' => $tp->typeLabel(), 'dept' => $tp->department?->name ?? 'Whole hotel', 'freq' => $tp->frequency_days ? 'every '.$tp->frequency_days.' d' : 'on demand',
                'req' => $req, 'done' => $done->count(), 'pct' => $pct, 'avg' => $done->count() ? round((float) $done->avg('score_pct'), 1) : '', 'passed' => $done->where('passed', true)->count() ?: null,
                'last' => $done->max('completed_at')?->format('d M Y') ?? '', 'next' => $d ? ($d['pending'] ? $d['pending']->scheduled_for->format('d M Y').' (booked)' : $d['next']->format('d M Y')) : '',
                '_class' => $req && $pct < 80 ? 'row-red' : ($d && in_array($d['state'], ['due', 'late'], true) ? 'row-amber' : '')];
        });

        return ['cols' => ['name' => 'Checklist', 'type' => 'Type', 'dept' => 'Department', 'freq' => 'Frequency', 'req' => 'Required', 'done' => 'Done', 'pct' => 'Compliance %', 'avg' => 'Avg score %',
            'passed' => 'Passed', 'last' => 'Last audit', 'next' => 'Next due'], 'rows' => $rows, 'money' => [], 'sum' => ['req', 'done', 'passed'],
            'summary' => ['Checklists' => (string) $rows->count(), 'Period' => $days.' days'],
            'note' => 'Required = whole frequency cycles in the period. Red: under 80 % of the required audits done; amber: due or late now.'];
    }

    protected function r_review_templates($from, $to): array
    {
        // Rewritten against the real schema: name/sentiment/body/is_active; there is no review type table.
        $rows = ReviewTemplate::orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'sentiment' => ucfirst((string) $r->sentiment),
            'body' => $r->body, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'sentiment' => 'Sentiment', 'body' => 'Body', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Review response template definitions.'];
    }

}
