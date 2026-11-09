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
 * Feedback & Reputation reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait FeedbackReputationReports
{
    protected function r_dept_scores($from, $to): array
    {
        $a = Carbon::parse($from)->startOfDay();
        $b = Carbon::parse($to)->endOfDay();
        $s = \App\Services\FeedbackService::stats($a, $b);
        $cases = \App\Models\FeedbackCase::whereBetween('created_at', [$a, $b])->get();
        $rows = collect($s['departments'])->map(function ($d, $k) use ($cases) {
            $c = $cases->where('department', $k);
            $res = $c->filter(fn ($x) => $x->resolved_at)->map(fn ($x) => $x->created_at->diffInMinutes($x->resolved_at) / 60);

            return ['dept' => $d['label'], 'avg' => $d['avg'] ?? '', 'n' => $d['n'], 'pos' => $d['pos'], 'neg' => $d['neg'],
                'net' => $d['pos'] + $d['neg'] ? round(($d['pos'] - $d['neg']) / ($d['pos'] + $d['neg']) * 100) : '', 'cases' => $c->count(),
                'high' => $c->where('severity', 'high')->count(), 'late' => $c->filter(fn ($x) => ($x->resolved_at ?? now())->gt($x->due_at))->count(),
                'hrs' => $res->count() ? round($res->avg(), 1) : '', '_class' => $d['avg'] !== null && $d['avg'] < 3.5 || $d['neg'] > $d['pos'] ? 'row-alert' : ''];
        })->filter(fn ($r) => $r['n'] || $r['pos'] || $r['neg'] || $r['cases'])->sortBy(fn ($r) => $r['avg'] === '' ? 9 : $r['avg'])->values();

        return ['cols' => ['dept' => 'Department', 'avg' => 'Avg rating (1-5)', 'n' => 'Ratings', 'pos' => 'Positive mentions', 'neg' => 'Negative mentions', 'net' => 'Net sentiment %',
            'cases' => 'Cases', 'high' => 'High severity', 'late' => 'Past SLA', 'hrs' => 'Avg hours to resolve'], 'rows' => $rows, 'sum' => ['n', 'pos', 'neg', 'cases', 'high', 'late'],
            'note' => 'Ratings come from survey questions linked to a department (and the legacy feedback form); mentions from the keyword analysis of survey comments and online reviews. Weakest departments first; rows under 3.5★ or with more negative than positive mentions are highlighted.'];
    }

    protected function r_feedback_answers($from, $to): array
    {
        $rows = FeedbackAnswer::with('feedbackCase.question')->whereBetween('answered_at', [$from, $to])->get()->map(fn ($r) => [
            'case' => optional($r->feedbackCase)->reference, 'question' => optional($r->question)->text,
            'answer' => $r->answer, 'score' => $r->score, 'comment' => $r->comment,
            'answered' => $r->answered_at, 'guest' => optional($r->feedbackCase)->guest_id,
        ]);
        return ['cols' => ['case' => 'Case', 'question' => 'Question', 'answer' => 'Answer', 'score' => 'Score', 'comment' => 'Comment', 'answered' => 'Answered'], 'rows' => $rows, 'note' => 'Survey feedback answers with scores.'];
    }

    protected function r_feedback_case_events($from, $to): array
    {
        // Rewritten against the real schema: case_id/user_id/type/note; there is no feedbackCase relation on the event model.
        $rows = FeedbackCaseEvent::with('user')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'case_id' => $r->case_id, 'type' => $r->type,
            'note' => $r->note, 'created' => $r->created_at, 'user' => optional($r->user)->name,
        ]);
        return ['cols' => ['case_id' => 'Case #', 'type' => 'Type', 'note' => 'Note', 'user' => 'User', 'created' => 'Created'], 'rows' => $rows, 'note' => 'Feedback case event audit trail.'];
    }

    protected function r_feedback_cases($from, $to): array
    {
        $h = fn ($a, $b) => $a && $b ? round($a->diffInMinutes($b) / 60, 1) : '';
        $rows = \App\Models\FeedbackCase::with('assignee', 'guest')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(function ($c) use ($h) {
                $done = $c->resolved_at ?? $c->closed_at;
                $onTime = $done ? ($done->lte($c->due_at) ? 'Yes' : 'Late') : ($c->due_at->isPast() ? 'Overdue' : 'Open');

                return ['no' => $c->case_no, 'opened' => $c->created_at->format('d M Y H:i'), 'src' => ucfirst($c->source), 'dept' => \App\Services\FeedbackService::DEPTS[$c->department] ?? $c->department,
                    'sev' => ucfirst($c->severity), 'guest' => $c->guest?->full_name, 'title' => \Illuminate\Support\Str::limit($c->title, 90), 'owner' => $c->assignee?->name ?? 'Unassigned',
                    'status' => \App\Models\FeedbackCase::STATUSES[$c->status], 'first' => $h($c->created_at, $c->first_response_at), 'res' => $h($c->created_at, $done), 'sla' => $onTime,
                    'action' => \App\Models\FeedbackCase::ACTIONS[$c->recovery_action] ?? '', 'pts' => (int) $c->goodwill_points, 'outcome' => \App\Models\FeedbackCase::OUTCOMES[$c->outcome] ?? '',
                    '_class' => in_array($onTime, ['Late', 'Overdue']) ? 'row-alert' : ''];
            })->values();
        $closed = $rows->whereNotIn('outcome', ['']);
        $rec = $closed->where('outcome', \App\Models\FeedbackCase::OUTCOMES['recovered'])->count();
        $done = $rows->whereIn('sla', ['Yes', 'Late']);

        return ['cols' => ['no' => 'Case', 'opened' => 'Opened', 'src' => 'From', 'dept' => 'Department', 'sev' => 'Severity', 'guest' => 'Guest', 'title' => 'Subject', 'owner' => 'Owner',
            'status' => 'Status', 'first' => 'Hours to first contact', 'res' => 'Hours to resolve', 'sla' => 'Within SLA', 'action' => 'Recovery action', 'pts' => 'Goodwill points', 'outcome' => 'Outcome'],
            'rows' => $rows, 'sum' => ['pts'],
            'summary' => ['Cases' => (string) $rows->count(), 'Resolved within SLA' => $done->count() ? round($done->where('sla', 'Yes')->count() / $done->count() * 100).'%' : '–',
                'Recovery rate' => $closed->count() ? round($rec / $closed->count() * 100).'%' : '–', 'Still open' => (string) $rows->whereIn('sla', ['Open', 'Overdue'])->count()],
            'note' => 'Cases opened in the period. SLA hours by severity (and a shorter target while the guest is in the hotel) are set in Feedback settings. Recovery rate = closed cases where the guest was satisfied.'];
    }

    protected function r_feedback_register($from, $to): array
    {
        $rows = \App\Models\GuestFeedback::with('recoveryCase', 'stay.room', 'reservation')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(fn ($f) => ['date' => $f->created_at->format('d M Y H:i'), 'guest' => $f->guest_name ?: 'Anonymous', 'res' => $f->reservation?->reservation_no, 'room' => $f->stay?->room?->room_no,
                'src' => \App\Models\GuestFeedback::SOURCES[$f->source] ?? $f->source, 'nps' => $f->nps ?? '', 'grp' => $f->nps_group ? ucfirst($f->nps_group) : '', 'overall' => $f->rating_overall,
                'sent' => ucfirst((string) $f->sentiment), 'neg' => collect((array) $f->aspects)->filter(fn ($s) => in_array($s, ['negative', 'mixed']))->keys()->map(fn ($d) => \App\Services\FeedbackService::DEPTS[$d] ?? $d)->implode(', '),
                'comments' => \Illuminate\Support\Str::limit((string) $f->comments, 200), 'case' => $f->recoveryCase?->case_no, 'status' => $f->status === 'new' ? 'Unread' : 'Read',
                '_class' => $f->nps_group === 'detractor' || $f->sentiment === 'negative' ? 'row-alert' : ''])->values();

        return ['cols' => ['date' => 'Received', 'guest' => 'Guest', 'res' => 'Reservation', 'room' => 'Room', 'src' => 'Source', 'nps' => 'Recommend (0-10)', 'grp' => 'NPS group', 'overall' => 'Overall (1-5)',
            'sent' => 'Sentiment', 'neg' => 'Complaints about', 'comments' => 'Comments', 'case' => 'Case', 'status' => 'Status'], 'rows' => $rows, 'sum' => [],
            'note' => 'Sentiment and complaint areas come from the ratings and a keyword analysis of the comments. Detractors and negative responses are highlighted.'];
    }

    protected function r_nps_trend($from, $to): array
    {
        $f = Carbon::parse($from)->startOfMonth();
        $t = Carbon::parse($to);
        $rows = collect();
        for ($m = $f->copy(); $m->lte($t); $m->addMonth()) {
            $a = $m->copy()->max(Carbon::parse($from));
            $b = $m->copy()->endOfMonth()->min($t);
            $s = \App\Services\FeedbackService::stats($a, $b);
            $rows->push(['m' => $m->format('M Y'), 'inv' => $s['invited'], 'resp' => $s['responses'], 'rate' => $s['response_rate'] !== null ? $s['response_rate'].'%' : '',
                'pro' => $s['nps']['promoters'], 'pas' => $s['nps']['passives'], 'det' => $s['nps']['detractors'], 'nps' => $s['nps']['nps'] ?? '', 'npsv' => (int) ($s['nps']['nps'] ?? 0),
                'csat' => $s['csat'] ?? '', 'neg' => $s['sentiment']['negative'], 'rev' => $s['reviews'], 'ravg' => $s['review_avg'] ?? '',
                'rrr' => $s['review_response_rate'] !== null ? $s['review_response_rate'].'%' : '', 'rh' => $s['review_response_hours'] ?? '']);
        }
        $all = \App\Services\FeedbackService::stats(Carbon::parse($from), $t);

        return ['cols' => ['m' => 'Month', 'inv' => 'Surveys sent', 'resp' => 'Responses', 'rate' => 'Response rate', 'pro' => 'Promoters', 'pas' => 'Passives', 'det' => 'Detractors',
            'nps' => 'NPS', 'csat' => 'Avg overall (1-5)', 'neg' => 'Negative comments', 'rev' => 'Online reviews', 'ravg' => 'Online rating (/5)', 'rrr' => 'Reviews answered', 'rh' => 'Avg hours to answer'],
            'rows' => $rows, 'sum' => ['inv', 'resp', 'pro', 'pas', 'det', 'neg', 'rev'],
            'summary' => ['NPS' => $all['nps']['nps'] === null ? '–' : (string) $all['nps']['nps'], 'Responses' => (string) $all['responses'],
                'Avg overall' => $all['csat'] ? $all['csat'].' / 5' : '–', 'Online rating' => $all['review_avg'] ? $all['review_avg'].' / 5' : '–'],
            'chart' => $rows->count() > 1 ? ['key' => 'resp', 'x' => 'm', 'label' => 'Responses per month'] : null,
            'note' => 'NPS = % promoters (9-10) − % detractors (0-6) among responses with a recommend score. Responses include post-stay surveys, the guest portal and the public feedback form. Online reviews by review date, ratings normalised to 5; "answered" excludes reviews marked "no response".'];
    }

    protected function r_online_reviews($from, $to): array
    {
        $rows = \App\Models\OnlineReview::with('guest', 'poster', 'recoveryCase')->whereDate('review_date', '>=', Carbon::parse($from)->toDateString())->whereDate('review_date', '<=', Carbon::parse($to)->toDateString())->orderBy('review_date')->get()
            ->map(fn ($r) => ['date' => $r->review_date->format('d M Y'), 'site' => $r->sourceName(), 'rev' => $r->reviewer_name, 'rating' => rtrim(rtrim(number_format((float) $r->rating, 1), '0'), '.').' / '.$r->rating_scale,
                'r5' => (float) $r->rating5, 'title' => \Illuminate\Support\Str::limit((string) ($r->title ?: $r->body), 80), 'sent' => ucfirst((string) $r->sentiment), 'guest' => $r->guest?->full_name,
                'status' => \App\Models\OnlineReview::STATUSES[$r->status], 'by' => $r->poster?->name, 'hrs' => $r->responseHours() ?? '', 'case' => $r->recoveryCase?->case_no,
                '_class' => (float) $r->rating5 <= 2.5 ? 'row-alert' : ''])->values();
        $answerable = $rows->where('status', '!=', \App\Models\OnlineReview::STATUSES['ignored']);

        return ['cols' => ['date' => 'Review date', 'site' => 'Site', 'rev' => 'Reviewer', 'rating' => 'Rating', 'r5' => 'Out of 5', 'title' => 'Review', 'sent' => 'Sentiment', 'guest' => 'Matched guest',
            'status' => 'Response', 'by' => 'Posted by', 'hrs' => 'Hours to respond', 'case' => 'Case'], 'rows' => $rows, 'sum' => [],
            'summary' => ['Reviews' => (string) $rows->count(), 'Average' => $rows->count() ? round($rows->avg('r5'), 2).' / 5' : '–',
                'Responded' => $answerable->count() ? round($answerable->where('status', \App\Models\OnlineReview::STATUSES['posted'])->count() / $answerable->count() * 100).'%' : '–',
                'Critical (≤ 2.5)' => (string) $rows->where('r5', '<=', 2.5)->count()],
            'note' => 'Reviews imported or entered for the period (by review date). Booking.com-style ratings out of 10 are halved for the 5-point average. Hours to respond run from when the review reached the inbox to when the response was marked as posted.'];
    }

    protected function r_survey_invitations($from, $to): array
    {
        $rows = SurveyInvitation::with('survey.guest')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'survey' => optional($r->survey)->name, 'guest' => optional($r->guest)->name,
            'sent' => $r->sent_at, 'responded' => $r->responded_at, 'status' => $r->status,
        ]);
        return ['cols' => ['survey' => 'Survey', 'guest' => 'Guest', 'sent' => 'Sent', 'responded' => 'Responded', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Survey invitation tracking.'];
    }

    protected function r_survey_questions($from, $to): array
    {
        // Rewritten against the real schema: label/type/department/options/required/sort/is_active; there is no question type table.
        $rows = SurveyQuestion::with('survey')->orderBy('survey_id')->orderBy('sort')->get()->map(fn ($r) => [
            'survey' => optional($r->survey)->name, 'question' => $r->label,
            'type' => $r->type, 'department' => $r->department, 'required' => $r->required ? 'Yes' : 'No',
            'sort' => $r->sort, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['survey' => 'Survey', 'question' => 'Question', 'type' => 'Type', 'department' => 'Department', 'required' => 'Required', 'sort' => 'Sort', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Survey question definitions.'];
    }

    protected function r_surveys($from, $to): array
    {
        $rows = Survey::with('surveyType.department')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'name' => $r->name, 'type' => optional($r->surveyType)->name,
            'dept' => optional($r->department)->name, 'status' => $r->status,
            'created' => $r->created_at, 'opens' => $r->open_date, 'closes' => $r->close_date,
            'responses' => $r->response_count, 'notes' => $r->description,
        ]);
        return ['cols' => ['name' => 'Name', 'type' => 'Type', 'dept' => 'Dept', 'status' => 'Status', 'created' => 'Created', 'opens' => 'Opens', 'closes' => 'Closes', 'responses' => 'Responses'], 'rows' => $rows, 'note' => 'Survey catalog with response counts.'];
    }

}
