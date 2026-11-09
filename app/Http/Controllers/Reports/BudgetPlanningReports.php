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
 * Budget & Planning reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait BudgetPlanningReports
{
    protected function r_budget_annual($from, $to): array
    {
        $B = \App\Services\BudgetService::class;
        $b = $this->reportBudget($to);
        $labels = $B::monthLabels($b?->fiscal_year ?? $B::fyFor($to));
        $cols = ['section' => 'Section', 'code' => 'Code', 'account' => 'Account'];
        foreach ($labels as $i => $l) {
            $cols['m'.$i] = $l;
        }
        $cols['total'] = 'Total';
        if (! $b) {
            return $this->noBudget($cols);
        }
        $acc = $B::accounts()->keyBy('id');
        $lines = $b->lines()->get()->filter(fn ($l) => $acc->has($l->account_head_id));
        $rows = collect();
        $secSum = [];
        foreach ($B::SECTIONS as $k => $label) {
            $ls = $lines->filter(fn ($l) => $acc[$l->account_head_id]->section === $k)->sortBy(fn ($l) => $acc[$l->account_head_id]->code);
            $sum = array_fill(1, 12, 0.0);
            foreach ($ls as $l) {
                if (abs($l->total()) < 0.005) {
                    continue;
                }
                $r = ['section' => $label, 'code' => $acc[$l->account_head_id]->code, 'account' => $acc[$l->account_head_id]->name];
                foreach (range(1, 12) as $m) {
                    $r['m'.$m] = $l->month($m);
                    $sum[$m] += $l->month($m);
                }
                $rows->push($r + ['total' => $l->total()]);
            }
            $secSum[$k] = $sum;
            $t = ['section' => $label, 'code' => '', 'account' => 'Total '.strtolower($label), '_class' => 'bold-row'];
            foreach (range(1, 12) as $m) {
                $t['m'.$m] = round($sum[$m], 2);
            }
            $rows->push($t + ['total' => round(array_sum($sum), 2)]);
        }
        foreach (['Gross operating profit (GOP)' => [['revenue'], ['direct', 'opex']], 'Net profit' => [['revenue', 'other_income'], ['direct', 'opex', 'nonop']]] as $name => [$plus, $minus]) {
            $t = ['section' => 'Result', 'code' => '', 'account' => $name, '_class' => 'bold-row'];
            $tot = 0;
            foreach (range(1, 12) as $m) {
                $v = array_sum(array_map(fn ($k) => $secSum[$k][$m], $plus)) - array_sum(array_map(fn ($k) => $secSum[$k][$m], $minus));
                $t['m'.$m] = round($v, 2);
                $tot += $v;
            }
            $rows->push($t + ['total' => round($tot, 2)]);
        }
        $tt = $B::totals($b);

        return ['cols' => $cols, 'rows' => $rows, 'money' => array_merge(array_map(fn ($m) => 'm'.$m, range(1, 12)), ['total']),
            'summary' => ['Budget' => $b->budget_no.' · '.$b->fyLabel(), 'Revenue' => $tt['income'], 'Expenses' => $tt['expense'], 'Net profit' => $tt['net']],
            'note' => "{$b->name} – {$b->statusLabel()}".($b->approver ? ' by '.$b->approver->name : '').'.'];
    }

    protected function r_budget_events($from, $to): array
    {
        $rows = BudgetEvent::with('budget.eventType')->whereBetween('event_date', [$from, $to])->get()->map(fn ($r) => [
            'budget' => optional($r->budget)->name, 'type' => optional($r->eventType)->name,
            'date' => $r->event_date, 'description' => $r->description, 'budgeted' => $r->budgeted_amount,
            'actual' => $r->actual_amount, 'variance' => $r->variance, 'status' => $r->status,
        ]);
        return ['cols' => ['budget' => 'Budget', 'type' => 'Type', 'date' => 'Date', 'description' => 'Description', 'budgeted' => 'Budgeted', 'actual' => 'Actual', 'variance' => 'Variance', 'status' => 'Status'], 'rows' => $rows, 'money' => ['budgeted_amount', 'actual_amount', 'variance'], 'sum' => ['budgeted_amount', 'actual_amount', 'variance'], 'note' => 'Budget event tracking with budgeted vs actual.'];
    }

    protected function r_budget_kpis($from, $to): array
    {
        $B = \App\Services\BudgetService::class;
        $cols = ['month' => 'Month', 'b_occ' => 'Budget occ. %', 'a_occ' => 'Actual occ. %', 'b_adr' => 'Budget ADR', 'a_adr' => 'Actual ADR', 'b_revpar' => 'Budget RevPAR', 'a_revpar' => 'Actual RevPAR',
            'b_nights' => 'Budget room-nights', 'a_nights' => 'Actual room-nights', 'b_rev' => 'Budget room revenue', 'a_rev' => 'Actual room revenue'];
        $b = $this->reportBudget($to);
        if (! $b) {
            return $this->noBudget($cols);
        }
        $as = $B::actualStats($b->fiscal_year);
        $st = $b->stats()->get()->keyBy('month');
        $rows = collect();
        foreach ($B::monthLabels($b->fiscal_year) as $m => $l) {
            $s = $st[$m] ?? null;
            $a = $as[$m];
            $rows->push(['month' => $l, 'b_occ' => number_format($s?->occupancy ?? 0, 1), 'a_occ' => $a['available'] ? number_format($a['occupancy'], 1) : '—',
                'b_adr' => $s?->adr ?? 0, 'a_adr' => $a['adr'], 'b_revpar' => $s?->revpar() ?? 0, 'a_revpar' => $a['revpar'],
                'b_nights' => $s?->roomsSold() ?? 0, 'a_nights' => $a['sold'], 'b_rev' => $s?->roomRevenue() ?? 0, 'a_rev' => $a['revenue']]);
        }

        return ['cols' => $cols, 'rows' => $rows, 'money' => ['b_adr', 'a_adr', 'b_revpar', 'a_revpar', 'b_rev', 'a_rev'], 'sum' => ['b_nights', 'a_nights', 'b_rev', 'a_rev'],
            'summary' => ['Budget' => $b->budget_no.' · '.$b->fyLabel(), 'Budget room revenue' => $rows->sum('b_rev'), 'Actual room revenue to date' => $rows->sum('a_rev')],
            'note' => 'Actuals: room-nights of checked-in / checked-out stays on closed days; available = active rooms × days; room revenue from the general ledger.'];
    }

    protected function r_budget_lines($from, $to): array
    {
        // Rewritten against the real schema: lines carry account_head_id and twelve fiscal months m1..m12;
        // the parent carries fiscal_year and budget_no. Filter on the parent with whereHas.
        $rows = BudgetLine::with('budget', 'account')
            ->whereHas('budget', fn ($q) => $q->whereBetween('fiscal_year', [(int) substr((string) $from, 0, 4), (int) substr((string) $to, 0, 4)]))
            ->orderBy('budget_id')->orderBy('account_head_id')
            ->get()
            ->map(fn ($r) => [
                'budget' => optional($r->budget)->budget_no,
                'account' => optional($r->account)->name,
                'type' => ucfirst((string) optional($r->account)->type),
                'total' => $r->total(),
                'note' => $r->note,
            ]);
        return ['cols' => ['budget' => 'Budget', 'account' => 'Account', 'type' => 'Type', 'total' => 'Annual', 'note' => 'Note'], 'rows' => $rows, 'money' => ['total'], 'sum' => ['total'], 'note' => 'Budget line totals (twelve fiscal months summed).'];
    }

    protected function r_budget_stats($from, $to): array
    {
        // Rewritten against the real schema: budget_stats has no budget() relation; iterate budgets between FYs.
        $rows = Budget::with('stats', 'department')
            ->whereBetween('fiscal_year', [(int) substr((string) $from, 0, 4), (int) substr((string) $to, 0, 4)])
            ->get()
            ->flatMap(fn ($b) => $b->stats->sortBy('month')->map(fn ($s) => [
                'budget' => $b->budget_no,
                'fy' => $b->fyLabel(),
                'dept' => optional($b->department)->name,
                'month' => $s->month,
                'rooms_available' => (int) $s->rooms_available,
                'occupancy' => (float) $s->occupancy,
                'adr' => (float) $s->adr,
                'room_revenue' => $s->roomRevenue(),
            ]))
            ->values();
        return ['cols' => ['budget' => 'Budget', 'fy' => 'FY', 'dept' => 'Department', 'month' => 'Month', 'rooms_available' => 'Rooms Avail', 'occupancy' => 'Occ %', 'adr' => 'ADR', 'room_revenue' => 'Room Revenue'], 'rows' => $rows, 'money' => ['adr', 'room_revenue'], 'sum' => ['rooms_available', 'room_revenue'], 'note' => 'Budget rooms statistics by month: occupancy, ADR and revenue.'];
    }

    protected function r_budget_vs_actual($from, $to): array
    {
        $cols = ['section' => 'Section', 'code' => 'Code', 'account' => 'Account', 'actual' => 'Actual (month)', 'budget' => 'Budget (month)', 'var' => 'Variance', 'var_pct' => 'Var %',
            'ytd_actual' => 'Actual YTD', 'ytd_budget' => 'Budget YTD', 'ytd_var' => 'Variance YTD', 'ytd_var_pct' => 'YTD var %', 'year_budget' => 'Full-year budget', 'forecast' => 'Forecast', 'flag' => 'Explain?'];
        $b = $this->reportBudget($to);
        if (! $b) {
            return $this->noBudget($cols);
        }
        $B = \App\Services\BudgetService::class;
        $month = $B::fyFor($to) === $b->fiscal_year ? $B::monthIndex($to) : 12;
        // Read through param(), so the month the screen was showing is the month the file, the saved view
        // and the scheduled run all report on.
        if ($this->param('month') !== null) {
            $month = min(12, max(1, (int) $this->param('month')));
        }
        $d = $B::bva($b, $month, $B::active($b->fiscal_year, 'forecast'));
        $fmt = fn ($r, $sec, $code = '') => ['section' => $sec, 'code' => $code, 'account' => $r['name'], 'actual' => $r['actual'], 'budget' => $r['budget'], 'var' => $r['var'],
            'var_pct' => $r['var_pct'] === null ? '—' : number_format($r['var_pct'], 1), 'ytd_actual' => $r['ytd_actual'], 'ytd_budget' => $r['ytd_budget'], 'ytd_var' => $r['ytd_var'],
            'ytd_var_pct' => $r['ytd_var_pct'] === null ? '—' : number_format($r['ytd_var_pct'], 1), 'year_budget' => $r['year_budget'], 'forecast' => $r['forecast'] ?? null, 'flag' => ! empty($r['flag']) ? 'yes' : '', '_class' => $code === '' ? 'bold-row' : ''];
        $rows = collect();
        $byS = $d['rows']->groupBy('section');
        foreach ($B::SECTIONS as $k => $label) {
            if (! isset($byS[$k])) {
                continue;
            }
            foreach ($byS[$k] as $r) {
                $rows->push($fmt($r, $label, $r['code']));
            }
            $rows->push($fmt($d['sections'][$k], $label));
            if ($k === 'opex') {
                $rows->push($fmt($d['totals']['gop'], 'Result'));
            }
        }
        $rows->push($fmt($d['totals']['net'], 'Result'));
        $m = $d['meta'];

        return ['cols' => $cols, 'rows' => $rows, 'money' => ['actual', 'budget', 'var', 'ytd_actual', 'ytd_budget', 'ytd_var', 'year_budget', 'forecast'],
            'summary' => ['Budget' => $b->budget_no.' · '.$b->fyLabel(), 'Revenue – month' => $d['totals']['revenue']['actual'], 'Revenue budget' => $d['totals']['revenue']['budget'],
                'GOP – month' => $d['totals']['gop']['actual'], 'GOP budget' => $d['totals']['gop']['budget'], 'Net profit YTD' => $d['totals']['net']['ytd_actual']],
            'note' => $m['start']->format('F Y').': actuals from the general ledger to '.$m['cutoff']->format('d M Y').'.'.($m['partial'] ? ' Month in progress – budget pro-rated to '.round($m['factor'] * 100).' %.' : '')
                .($m['pre_live'] ? ' Month is before go-live – not compared.' : '').' Variance = actual − budget; for expenses a positive variance is adverse.'];
    }

    protected function r_forecast_vs_budget($from, $to): array
    {
        $B = \App\Services\BudgetService::class;
        $cols = ['section' => 'Section', 'code' => 'Code', 'account' => 'Account', 'budget' => 'Budget (year)', 'forecast' => 'Forecast (year)', 'var' => 'Forecast − budget', 'var_pct' => 'Var %',
            'actual' => 'Actual to date', 'remaining' => 'Remaining forecast'];
        $b = $this->reportBudget($to);
        $f = $b ? $B::active($b->fiscal_year, 'forecast') : null;
        if (! $b || ! $f) {
            return $this->noBudget($cols, $b ? 'approved, active re-forecast' : 'approved, active budget');
        }
        $acc = $B::accounts()->keyBy('id');
        $bl = $b->lines()->get()->keyBy('account_head_id');
        $fl = $f->lines()->get()->keyBy('account_head_id');
        $act = $B::actuals($b->fiscal_year);
        $rows = collect();
        $tot = ['income' => [0, 0], 'expense' => [0, 0]];
        foreach ($acc->sortBy(fn ($a) => array_search($a->section, array_keys($B::SECTIONS)).'-'.$a->code) as $a) {
            $bv = isset($bl[$a->id]) ? $bl[$a->id]->total() : 0.0;
            $fv = isset($fl[$a->id]) ? $fl[$a->id]->total() : 0.0;
            $av = round(array_sum($act[$a->id] ?? []), 2);
            if (abs($bv) + abs($fv) + abs($av) < 0.005) {
                continue;
            }
            $tot[$a->type][0] += $bv;
            $tot[$a->type][1] += $fv;
            $rows->push(['section' => $B::SECTIONS[$a->section], 'code' => $a->code, 'account' => $a->name, 'budget' => $bv, 'forecast' => $fv, 'var' => round($fv - $bv, 2),
                'var_pct' => abs($bv) >= 0.01 ? number_format(($fv - $bv) * 100 / abs($bv), 1) : '—', 'actual' => $av, 'remaining' => round($fv - $av, 2)]);
        }
        $netB = $tot['income'][0] - $tot['expense'][0];
        $netF = $tot['income'][1] - $tot['expense'][1];

        return ['cols' => $cols, 'rows' => $rows, 'money' => ['budget', 'forecast', 'var', 'actual', 'remaining'],
            'summary' => ['Forecast' => $f->budget_no.' (actuals to '.$f->actuals_through?->format('M Y').')', 'Revenue: forecast − budget' => round($tot['income'][1] - $tot['income'][0], 2),
                'Expenses: forecast − budget' => round($tot['expense'][1] - $tot['expense'][0], 2), 'Net profit: forecast' => round($netF, 2), 'Net profit: budget' => round($netB, 2)],
            'note' => "Budget {$b->budget_no} vs re-forecast {$f->budget_no}. For expense accounts a positive difference means more cost than budgeted."];
    }

    protected function r_variance_commentary($from, $to): array
    {
        $rows = \App\Models\BudgetComment::with('account', 'user', 'budgetVersion')->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('period')->orderBy('id')->get()
            ->map(fn ($c) => ['period' => \Illuminate\Support\Carbon::parse($c->period.'-01')->format('M Y'), 'budget_no' => $c->budgetVersion?->budget_no, 'account' => $c->account ? $c->account->code.' '.$c->account->name : 'General (whole month)',
                'actual' => $c->actual, 'budget' => $c->budget, 'var' => $c->actual !== null && $c->budget !== null ? round($c->actual - $c->budget, 2) : null, 'comment' => $c->body,
                'by' => $c->user?->name, 'at' => $c->created_at->format('d-m-Y H:i')]);

        return ['cols' => ['period' => 'Month', 'budget_no' => 'Budget', 'account' => 'Account', 'actual' => 'Actual', 'budget' => 'Budget', 'var' => 'Variance', 'comment' => 'Explanation', 'by' => 'By', 'at' => 'Recorded'],
            'rows' => $rows, 'money' => ['actual', 'budget', 'var'], 'summary' => ['Comments' => (string) $rows->count(), 'Accounts explained' => (string) $rows->pluck('account')->unique()->count()]];
    }

}
