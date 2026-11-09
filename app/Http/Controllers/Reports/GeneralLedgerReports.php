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
 * General Ledger reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait GeneralLedgerReports
{
    protected function r_accrual_register($from, $to): array
    {
        $A = \App\Models\Accrual::class;
        $asOf = $to->format('Y-m');
        $rows = $A::with('plAccount', 'balanceAccount', 'supplier')->whereIn('status', ['active', 'completed', 'cancelled'])->where('start_period', '<=', $asOf)->orderBy('type')->orderBy('number')->get()
            ->map(function ($a) use ($asOf, $A) {
                $posted = (float) \App\Models\PeriodPosting::where('source', 'accrual')->where('source_id', $a->id)->where('kind', 'post')->where('period', '<=', $asOf)->sum('amount');
                $next = $a->status === 'active' ? collect($a->schedule())->keys()->first(fn ($m) => $m > ($a->last_period ?? '0000-00')) : null;

                return ['no' => $a->number, 'type' => $A::TYPES[$a->type], 'description' => $a->description, 'party' => $a->supplier?->name, 'pl' => $a->plAccount?->code.' '.$a->plAccount?->name,
                    'bs' => $a->balanceAccount?->code, 'months' => Carbon::parse($a->start_period.'-01')->format('M y').($a->months > 1 ? ' – '.Carbon::parse($a->endPeriod().'-01')->format('M y') : ''),
                    'amount' => (float) $a->amount, 'posted' => round($posted, 2), 'remaining' => $a->status === 'cancelled' ? 0.0 : round((float) $a->amount - $posted, 2),
                    'next' => $next ? Carbon::parse($next.'-01')->format('M Y') : '', 'status' => $A::STATUSES[$a->status]];
            });

        return ['cols' => ['no' => 'No.', 'type' => 'Type', 'description' => 'Description', 'party' => 'Supplier / customer', 'pl' => 'Expense / income account', 'bs' => 'B/S account', 'months' => 'Months', 'amount' => 'Amount', 'posted' => 'Posted to date', 'remaining' => 'Remaining', 'next' => 'Next posting', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['amount', 'posted', 'remaining'], 'sum' => ['amount', 'posted', 'remaining'],
            'summary' => collect($A::TYPES)->mapWithKeys(fn ($l, $k) => [$l.' remaining' => $rows->where('type', $l)->sum('remaining')])->all(),
            'note' => 'Posted to date counts postings for months up to '.$to->format('M Y').'. Accrued expenses reverse on the 1st of the following month.'];
    }

    protected function r_balance_sheet($from, $to): array
    {
        $b = \App\Services\GlReportService::balanceSheet($to->toDateString());

        return ['cols' => ['section' => 'Section', 'group' => 'Group', 'code' => 'Code', 'account' => 'Account', 'amount' => 'Amount'], 'rows' => $b['rows'], 'money' => ['amount'],
            'summary' => ['Total assets' => $b['assets'], 'Liabilities' => $b['liabilities'], 'Equity incl. earnings' => $b['equity'], 'Difference' => $b['difference']],
            'note' => $this->glNote().' As at '.$to->format('d M Y').'. Difference must be zero.'];
    }

    protected function r_close_status($from, $to): array
    {
        $CP = \App\Models\ClosePeriod::class;
        $rows = $CP::with('preparer', 'closer')->withCount(['tasks', 'tasks as cleared_count' => fn ($q) => $q->whereIn('status', \App\Models\CloseTask::CLEARED), 'tasks as waived_count' => fn ($q) => $q->where('status', 'waived')])
            ->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('period')->get() // period is a 'Y-m' string
            ->map(fn ($p) => ['period' => $p->label(), 'fy' => \App\Services\BudgetService::fyLabel($p->fiscal_year).' · M'.$p->fiscal_month, 'status' => $CP::STATUSES[$p->status],
                'tasks' => $p->cleared_count.' / '.$p->tasks_count, 'waived' => $p->waived_count, 'prepared' => $p->preparer ? $p->preparer->name.' · '.$p->prepared_at?->format('d M Y') : '',
                'closed' => $p->closer ? $p->closer->name.' · '.$p->closed_at?->format('d M Y') : '', 'days' => $p->closed_at ? max(0, (int) $p->end()->diffInDays($p->closed_at->copy()->startOfDay())) : null,
                'reopened' => $p->reopen_count ?: '', '_class' => $p->status === 'closed' ? '' : 'bold-row']);
        $closed = $rows->whereNotNull('days');

        return ['cols' => ['period' => 'Period', 'fy' => 'Fiscal', 'status' => 'Status', 'tasks' => 'Checklist cleared', 'waived' => 'Waived', 'prepared' => 'Prepared', 'closed' => 'Approved & closed', 'days' => 'Days to close', 'reopened' => 'Reopened'],
            'rows' => $rows, 'summary' => ['Periods' => $rows->count(), 'Closed' => $closed->count(), 'Average days to close' => $closed->count() ? round($closed->avg('days'), 1) : '—', 'Waivers' => $rows->sum('waived')],
            'note' => 'Books locked up to '.(\App\Services\LedgerService::lockDate() ? Carbon::parse(\App\Services\LedgerService::lockDate())->format('d M Y') : '—').'. Days to close = approval date − month end.'];
    }

    protected function r_fiscal_year_closes($from, $to): array
    {
        $rows = FiscalYearClose::with('fiscalYear')->whereBetween('closed_at', [$from, $to])->get()->map(fn ($r) => [
            'fiscal_year' => optional($r->fiscalYear)->name, 'closed' => $r->closed_at,
            'user' => optional($r->user)->name, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['fiscal_year' => 'Fiscal Year', 'closed' => 'Closed', 'user' => 'User', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Fiscal year close events.'];
    }

    protected function r_gl_journals($from, $to): array
    {
        $rows = GlJournal::with('journalType.account.department')->whereBetween('posted_at', [$from, $to])->get()->map(fn ($r) => [
            'ref' => $r->journal_no, 'type' => optional($r->journalType)->name,
            'account' => optional($r->account)->name, 'dept' => optional($r->department)->name,
            'debit' => $r->debit_amount, 'credit' => $r->credit_amount, 'posted' => $r->posted_at,
            'status' => $r->status, 'description' => $r->description,
        ]);
        return ['cols' => ['ref' => 'Reference', 'type' => 'Type', 'account' => 'Account', 'dept' => 'Dept', 'debit' => 'Debit', 'credit' => 'Credit', 'posted' => 'Posted', 'status' => 'Status'], 'rows' => $rows, 'money' => ['debit_amount', 'credit_amount'], 'sum' => ['debit_amount', 'credit_amount'], 'note' => 'General ledger journal line register.'];
    }

    protected function r_gl_pnl($from, $to): array
    {
        $p = \App\Services\GlReportService::profitLoss($from->toDateString(), $to->toDateString());

        return ['cols' => ['section' => 'Section', 'group' => 'Group', 'code' => 'Code', 'account' => 'Account', 'amount' => 'Amount'], 'rows' => $p['rows'], 'money' => ['amount'],
            'summary' => ['Income' => $p['income'], 'Expenses' => $p['expense'], 'Net profit / (loss)' => $p['net']], 'note' => $this->glNote().' Discounts & allowances reduce income. Year-end closing entries are excluded.'];
    }

    protected function r_ia_authorisations($from, $to): array
    {
        $rows = \App\Models\RevenueAuthorisation::with('stay.room', 'stay.guest', 'stay.reservation', 'folio', 'requester', 'decider')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get()
            ->map(fn ($a) => ['no' => $a->auth_no, 'date' => $a->created_at->format('d M Y'), 'kind' => \App\Models\RevenueAuthorisation::KINDS[$a->kind], 'for' => $a->subject(),
                'nights' => $a->date_from ? dmy($a->date_from, 'd M').' – '.dmy($a->date_to, 'd M').' ('.$a->nights().')' : '', 'value' => \App\Services\RevenueAuthService::value($a),
                'reason' => $a->reason, 'req' => $a->requester?->name, 'status' => $a->status === 'cancelled' ? 'withdrawn' : $a->status, 'dec' => $a->decider?->name, 'note' => $a->decision_note]);

        return ['cols' => ['no' => 'No.', 'date' => 'Requested', 'kind' => 'Type', 'for' => 'For', 'nights' => 'Nights', 'value' => 'Revenue given away', 'reason' => 'Reason', 'req' => 'Requested by', 'status' => 'Status', 'dec' => 'Decided by', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'note' => 'Approved: '.money($rows->where('status', 'approved')->sum('value')).' given away · '.$rows->where('status', 'pending')->count().' pending · '.$rows->where('status', 'declined')->count().' declined.'];
    }

    protected function r_ia_findings($from, $to): array
    {
        $checks = \App\Services\IncomeAuditService::CHECKS;
        $rows = \App\Models\AuditFinding::with('day', 'resolver')->where('status', '!=', 'cleared')
            ->whereHas('day', fn ($q) => $q->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString()))->get()
            ->sortBy(fn ($f) => [$f->day->business_date->toDateString(), array_search($f->check, array_keys($checks))])->values()
            ->map(fn ($f) => ['date' => dmy($f->day->business_date), 'check' => $checks[$f->check][0] ?? $f->check, 'sev' => $f->severity, 'room' => $f->room_no, 'ref' => $f->ref_label,
                'detail' => $f->detail, 'amount' => (float) $f->amount, 'status' => \App\Models\AuditFinding::STATUSES[$f->status], 'resolution' => $f->resolution, 'by' => $f->resolver?->name]);

        return ['cols' => ['date' => 'Date', 'check' => 'Check', 'sev' => 'Severity', 'room' => 'Room', 'ref' => 'Reference', 'detail' => 'Found', 'amount' => 'Value', 'status' => 'Status', 'resolution' => 'Resolution', 'by' => 'By'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'note' => $rows->count().' exception(s); '.$rows->whereIn('status', ['Open', 'Escalated'])->count().' still open or escalated. Exceptions put right before sign-off (cleared by a re-check) are not listed.'];
    }

    protected function r_ia_rate_variance($from, $to): array
    {
        $to = $to->copy()->min($from->copy()->addDays(30))->min(bdate()->copy()->subDay());
        $tol = (float) \App\Services\IncomeAuditService::cfg('ia_rate_tolerance_pct');
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            $stays = \App\Services\IncomeAuditService::stayNights($date);
            $folioStay = \App\Models\Folio::whereIn('reservation_room_id', $stays->pluck('id'))->pluck('reservation_room_id', 'id');
            $posted = \App\Models\FolioTransaction::where('source_type', 'night')->where('reference', $date)->where('is_void', false)->where('category', 'room')
                ->whereIn('folio_id', $folioStay->keys())->get()->groupBy(fn ($t) => $folioStay[$t->folio_id]);
            foreach ($stays as $s) {
                $exp = \App\Services\IncomeAuditService::expectedRate($s, $date);
                $charged = isset($posted[$s->id]) ? (float) $posted[$s->id]->first()->amount : null;
                $var = $exp && $charged !== null ? round($charged - $exp['rate'], 2) : null;
                $auth = $var !== null && $var < 0 ? \App\Services\RevenueAuthService::match(['comp', 'house_use', 'rate'], $s->id, null, $date, (float) $charged) : null;
                $rows->push(['date' => dmy($date), 'room' => $s->room?->room_no, 'guest' => $s->guest?->full_name, 'res' => $s->reservation?->reservation_no, 'basis' => $exp['basis'] ?? 'channel rate – not checked',
                    'expected' => $exp['rate'] ?? null, 'charged' => $charged, 'variance' => $var, 'pct' => $var !== null && $exp['rate'] > 0 ? round($var / $exp['rate'] * 100, 1).'%' : '',
                    'flag' => $charged === null ? 'no tariff' : ($var !== null && $exp['rate'] > 0 && abs($var) / $exp['rate'] * 100 > $tol ? ($auth ? 'authorised '.$auth->auth_no : 'outside tolerance') : ''),
                ]);
            }
        }

        return ['cols' => ['date' => 'Night', 'room' => 'Room', 'guest' => 'Guest', 'res' => 'Reservation', 'basis' => 'Expected basis', 'expected' => 'Expected', 'charged' => 'Charged', 'variance' => 'Variance', 'pct' => '%', 'flag' => 'Flag'],
            'rows' => $rows, 'money' => ['expected', 'charged', 'variance'], 'sum' => ['expected', 'charged', 'variance'],
            'note' => 'Tolerance ±'.$tol.'% (Income Audit settings). Up to 31 nights, ending on the last closed business date. Expected rate: rate plan → group block → corporate contract → rack rate less the booking discount.'];
    }

    protected function r_ia_reconciliation($from, $to): array
    {
        $to = $to->copy()->min(bdate()->copy()->subDay());
        $days = \App\Models\AuditDay::with('signer', 'closer')->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->get()->keyBy(fn ($d) => $d->business_date->toDateString());
        $rows = collect();
        for ($d = $from->copy(); $d->lte($to) && $rows->count() < 92; $d->addDay()) {
            $date = $d->toDateString();
            $day = $days[$date] ?? null;
            $f = \App\Services\IncomeAuditService::figures($date);
            $base = $day?->figures['baseline'] ?? null;
            $rows->push(['date' => dmy($date), 'status' => $day ? \App\Models\AuditDay::STATUSES[$day->status] : 'not started',
                'na_total' => $f['reported'] ? round(array_sum($f['reported']) - $f['reported']['collections'], 2) : null,
                'room' => $f['now']['room'], 'other' => $f['now']['other'], 'tax' => $f['now']['tax'], 'coll' => $f['now']['collections'], 'disc' => $f['discounts'],
                'change' => $base ? round(($f['now']['room'] + $f['now']['other'] + $f['now']['tax']) - (($base['room'] ?? 0) + ($base['other'] ?? 0) + ($base['tax'] ?? 0)), 2) : null,
                'gl' => $f['gl_income'], 'open' => $day?->findings_open, 'signed' => $day?->signer?->name, 'closed' => $day?->closer?->name]);
        }

        return ['cols' => ['date' => 'Date', 'status' => 'Audit', 'na_total' => 'NA report (rev + tax)', 'room' => 'Room rev', 'other' => 'Other rev', 'tax' => 'Tax', 'coll' => 'Collections', 'disc' => 'Discounts',
            'change' => 'Changed since close', 'gl' => 'GL income', 'open' => 'Open exc.', 'signed' => 'Signed', 'closed' => 'Closed'],
            'rows' => $rows, 'money' => ['na_total', 'room', 'other', 'tax', 'coll', 'disc', 'change', 'gl'], 'sum' => ['room', 'other', 'tax', 'coll', 'disc', 'gl'],
            'note' => 'Revenue and collections recomputed now from folio postings and settled POS bills. "Changed since close" compares with the baseline taken when the day entered income audit – any figure there means back-dated postings or voids.'];
    }

    protected function r_journal_register($from, $to): array
    {
        $rows = \App\Services\GlReportService::journalRegister($from->toDateString(), $to->toDateString());

        return ['cols' => ['date' => 'Date', 'jv' => 'Journal', 'source' => 'Source', 'ref' => 'Reference', 'narration' => 'Narration', 'account' => 'Account', 'memo' => 'Memo', 'debit' => 'Debit', 'credit' => 'Credit'],
            'rows' => $rows, 'money' => ['debit', 'credit'], 'sum' => ['debit', 'credit'], 'note' => $this->glNote()];
    }

    protected function r_period_end_postings($from, $to): array
    {
        $rows = \App\Models\PeriodPosting::with('journal', 'accrual', 'recurring')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->orderBy('id')->get()
            ->map(fn ($p) => ['date' => $p->date->format('d-m-Y'), 'source' => $p->source === 'accrual' ? \App\Models\Accrual::TYPES[$p->accrual?->type ?? 'accrual'] : 'Recurring journal',
                'item' => $p->sourceLabel(), 'period' => Carbon::parse($p->period.'-01')->format('M Y'), 'kind' => $p->kind === 'reverse' ? 'Auto-reversal' : 'Posting', 'amount' => (float) $p->amount, 'journal' => $p->journal?->journal_no]);

        return ['cols' => ['date' => 'Date', 'source' => 'Source', 'item' => 'Schedule / template', 'period' => 'For month', 'kind' => 'Kind', 'amount' => 'Amount', 'journal' => 'Journal'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => ['Postings' => $rows->where('kind', 'Posting')->sum('amount'), 'Auto-reversals' => $rows->where('kind', 'Auto-reversal')->sum('amount')],
            'note' => 'Made by Period close › Run period-end postings, or automatically when the night audit closes the last day of a month.'];
    }

    protected function r_recurring_journals($from, $to): array
    {
        $rows = RecurringJournal::with('journalType.account')->whereBetween('last_posted_at', [$from, $to])->get()->map(fn ($r) => [
            'ref' => $r->template_no, 'type' => optional($r->journalType)->name,
            'account' => optional($r->account)->name, 'debit' => $r->debit_amount, 'credit' => $r->credit_amount,
            'frequency' => $r->frequency, 'last_posted' => $r->last_posted_at, 'next_posting' => $r->next_posting_date,
            'status' => $r->status,
        ]);
        return ['cols' => ['ref' => 'Template No', 'type' => 'Type', 'account' => 'Account', 'debit' => 'Debit', 'credit' => 'Credit', 'frequency' => 'Frequency', 'last_posted' => 'Last Posted', 'next_posting' => 'Next Posting', 'status' => 'Status'], 'rows' => $rows, 'money' => ['debit_amount', 'credit_amount'], 'note' => 'Recurring journal templates with posting schedule.'];
    }

    protected function r_trial_balance($from, $to): array
    {
        $rows = \App\Services\GlReportService::trialBalance($from->toDateString(), $to->toDateString());
        $m = ['open_dr', 'open_cr', 'dr', 'cr', 'close_dr', 'close_cr'];

        return ['cols' => ['code' => 'Code', 'account' => 'Account', 'type' => 'Type', 'open_dr' => 'Opening Dr', 'open_cr' => 'Opening Cr', 'dr' => 'Debit', 'cr' => 'Credit', 'close_dr' => 'Closing Dr', 'close_cr' => 'Closing Cr'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'summary' => ['Period debits' => $rows->sum('dr'), 'Period credits' => $rows->sum('cr'), 'Closing Dr' => $rows->sum('close_dr'), 'Closing Cr' => $rows->sum('close_cr')],
            'note' => $this->glNote().' Pre-closing trial balance: year-end closing entries dated inside the range are left out of the movement (earlier years\' closings are in the opening balances).'];
    }

}
