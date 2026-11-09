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
 * Sales & Business Development reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait SalesBusinessDevelopmentReports
{
    protected function r_business_sources($from, $to): array
    {
        // Rewritten against the real schema: type/commission_pct/is_active; there is no source type table.
        $rows = BusinessSource::orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'type' => $r->type, 'commission_pct' => (float) $r->commission_pct,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'type' => 'Type', 'commission_pct' => 'Commission %', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Business source register.'];
    }

    protected function r_account_production($from, $to): array
    {
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->startOfDay();
        $now = \App\Services\SalesService::production($F->copy(), $T->copy());
        $ly = \App\Services\SalesService::production($F->copy()->subYear(), $T->copy()->subYear());
        $cos = \App\Models\Company::with('accountManager')->whereIn('id', $now->keys()->merge($ly->keys())->unique()->all() ?: [0])->get();
        $rows = $cos->map(function ($c) use ($now, $ly) {
            $n = $now[$c->id] ?? ['stays' => 0, 'nights' => 0, 'revenue' => 0, 'otb_nights' => 0, 'otb_revenue' => 0];
            $l = $ly[$c->id] ?? ['nights' => 0, 'revenue' => 0];

            return ['account' => $c->name, 'type' => $c->typeLabel(), 'tier' => $c->tierLabel(), 'mgr' => (string) $c->accountManager?->name, 'stays' => $n['stays'], 'rn' => $n['nights'],
                'rev' => (float) $n['revenue'], 'adr' => $n['nights'] ? round($n['revenue'] / $n['nights'], 2) : 0.0, 'otb' => $n['otb_nights'], 'otb_rev' => (float) $n['otb_revenue'],
                'ly_rn' => $l['nights'], 'ly_rev' => (float) $l['revenue'], 'var' => $l['revenue'] > 0 ? round(($n['revenue'] - $l['revenue']) * 100 / $l['revenue'], 1) : '',
                '_class' => $l['revenue'] > 0 && $n['revenue'] < $l['revenue'] * 0.8 ? 'row-warn' : ''];
        })->sortByDesc('rev')->values();

        return ['cols' => ['account' => 'Account', 'type' => 'Type', 'tier' => 'Tier', 'mgr' => 'Account manager', 'stays' => 'Stays', 'rn' => 'Room nights', 'rev' => 'Room revenue', 'adr' => 'ADR',
            'otb' => 'OTB nights', 'otb_rev' => 'OTB revenue', 'ly_rn' => 'LY nights', 'ly_rev' => 'LY revenue', 'var' => 'Revenue vs LY %'],
            'rows' => $rows, 'money' => ['rev', 'adr', 'otb_rev', 'ly_rev'], 'sum' => ['stays', 'rn', 'rev', 'otb', 'otb_rev', 'ly_rn', 'ly_rev'],
            'summary' => ['Accounts' => (string) $rows->count(), 'Room nights' => number_format($rows->sum('rn')), 'Room revenue' => money($rows->sum('rev')), 'Last year' => money($rows->sum('ly_rev')),
                'On the books' => number_format($rows->sum('otb')).' RN'],
            'note' => 'Stays linked to a company, overlapping the period (checked in / out). Room revenue = room charges posted to their folios in the period. OTB = reserved stays from the business date on at the booked rate. Highlighted = more than 20% below last year.'];
    }

    protected function r_agent_commissions($from, $to): array
    {
        $rows = AgentCommission::with('agent.commissionType.reservation.guest')->whereBetween('earned_at', [$from, $to])->get()->map(fn ($r) => [
            'agent' => optional($r->agent)->name, 'type' => optional($r->commissionType)->name,
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'amount' => $r->commission_amount, 'rate' => $r->commission_rate, 'earned' => $r->earned_at, 'status' => $r->status,
        ]);
        return ['cols' => ['agent' => 'Agent', 'type' => 'Type', 'res' => 'Reservation', 'guest' => 'Guest', 'amount' => 'Amount', 'rate' => 'Rate', 'earned' => 'Earned', 'status' => 'Status'], 'rows' => $rows, 'money' => ['commission_amount'], 'sum' => ['commission_amount'], 'note' => 'Agent commission register.'];
    }

    protected function r_bd_benchmark($from, $to): array
    {
        $BD = \App\Services\BusinessDevService::class;
        $m = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth()->min(bdate()->copy()->startOfMonth()->subMonthNoOverflow());
        $rows = collect();
        for (; $m->lte($end); $m->addMonthNoOverflow()) {
            $x = $BD::indices($m->copy());
            $rows->push(['month' => $m->format('M Y'), 'occ' => round($x['own']['occupancy'], 1), 'adr' => round($x['own']['adr'], 2), 'revpar' => round($x['own']['revpar'], 2),
                'c_occ' => $x['comp'] ? round($x['comp']['occupancy'], 1) : null, 'c_adr' => $x['comp']['adr'] ?? null, 'c_revpar' => $x['comp']['revpar'] ?? null,
                'mpi' => $x['mpi'] ?? '', 'ari' => $x['ari'] ?? '', 'rgi' => $x['rgi'] ?? '',
                'src' => $x['benchmark'] ? (\App\Models\BdBenchmark::SOURCES[$x['benchmark']->source] ?? '').($x['own']['source'] === 'report' ? ' (own figures from report)' : '') : 'No benchmark',
                '_class' => $x['rgi'] !== null && $x['rgi'] < 95 ? 'row-amber' : '']);
        }
        $with = $rows->filter(fn ($r) => $r['rgi'] !== '');
        $avg = fn ($k) => $with->count() ? round($with->avg($k), 1) : '—';

        return ['cols' => ['month' => 'Month', 'occ' => 'Occ % (us)', 'adr' => 'ADR (us)', 'revpar' => 'RevPAR (us)', 'c_occ' => 'Occ % (comp)', 'c_adr' => 'ADR (comp)', 'c_revpar' => 'RevPAR (comp)',
            'mpi' => 'MPI', 'ari' => 'ARI', 'rgi' => 'RGI', 'src' => 'Source'],
            'rows' => $rows, 'money' => ['adr', 'revpar', 'c_adr', 'c_revpar'],
            'summary' => ['Months benchmarked' => $with->count().' / '.$rows->count(), 'Average MPI' => (string) $avg('mpi'), 'Average ARI' => (string) $avg('ari'), 'Average RGI' => (string) $avg('rgi')],
            'note' => 'Completed months only. MPI = our occupancy ÷ comp-set occupancy × 100, ARI = ADR index, RGI = RevPAR index (100 = fair share). Months with an RGI below 95 are highlighted. Our figures come from the report’s “My Property” line when entered, otherwise from the PMS closed days.'];
    }

    protected function r_bd_competitors($from, $to): array
    {
        // Rewritten against the real schema: stars/rooms/positioning/location/distance_km/website/is_active; there is no competitor type.
        $rows = BdCompetitor::orderBy('sort')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'stars' => $r->stars, 'rooms' => $r->rooms,
            'positioning' => $r->positioning, 'location' => $r->location, 'distance_km' => $r->distance_km,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'stars' => 'Stars', 'rooms' => 'Rooms', 'positioning' => 'Positioning', 'location' => 'Location', 'distance_km' => 'Distance (km)', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Business development competitor register.'];
    }

    protected function r_bd_initiatives($from, $to): array
    {
        $I = \App\Models\BdInitiative::class;
        $rows = $I::with('owner', 'milestones', 'results')->orderByRaw("FIELD(stage, 'live', 'implementation', 'approved', 'business_case', 'assessment', 'idea', 'closed', 'rejected')")->orderByDesc('annual_revenue')->get()
            ->map(function ($i) use ($I) {
                $s = \App\Services\BusinessDevService::initiativeStats($i);

                return ['no' => $i->number, 'name' => $i->name, 'category' => $I::CATEGORIES[$i->category] ?? '', 'stage' => $i->stageLabel(), 'owner' => $i->owner?->name, 'investment' => (float) $i->investment,
                    'revenue' => (float) $i->annual_revenue, 'net' => $s['net'], 'payback' => $s['payback_months'] ?? '', 'npv' => $s['npv'], 'actual' => $s['months'] ? $s['actual_net'] : null,
                    'vs' => $s['achievement'] ?? '', 'ms' => $s['milestones'] ? $s['milestones_done'].'/'.$s['milestones'].($s['milestones_overdue'] ? ' ('.$s['milestones_overdue'].' late)' : '') : '',
                    '_class' => $s['milestones_overdue'] ? 'row-amber' : ''];
            });
        $committed = $rows->filter(fn ($r) => in_array($r['stage'], ['Approved', 'Implementation', 'Live'], true));

        return ['cols' => ['no' => 'No.', 'name' => 'Initiative', 'category' => 'Category', 'stage' => 'Stage', 'owner' => 'Owner', 'investment' => 'Investment', 'revenue' => 'Annual revenue',
            'net' => 'Annual net', 'payback' => 'Payback (months)', 'npv' => 'NPV', 'actual' => 'Actual net to date', 'vs' => 'vs plan %', 'ms' => 'Milestones'],
            'rows' => $rows, 'money' => ['investment', 'revenue', 'net', 'npv', 'actual'], 'sum' => ['investment', 'revenue', 'net', 'npv', 'actual'],
            'summary' => ['Initiatives' => (string) $rows->count(), 'Committed annual revenue' => money($committed->sum('revenue')), 'Committed investment' => money($committed->sum('investment')),
                'Awaiting approval' => (string) $rows->where('stage', 'Awaiting approval')->count()],
            'note' => 'NPV at the '.\App\Services\BusinessDevService::cfg()['discount_rate'].'% hurdle rate over each initiative’s horizon. Actual net = results recorded since going live; vs plan = against the business case pro rata. Rows with overdue milestones are highlighted.'];
    }

    protected function r_bd_market_mix($from, $to): array
    {
        $BD = \App\Services\BusinessDevService::class;
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->startOfDay();
        $mk = fn ($by, $label) => $BD::marketMix($F, $T, $by)->map(fn ($x) => ['dim' => $label, 'key' => $x['label'], 'bookings' => $x['bookings'], 'nights' => $x['nights'], 'adr' => $x['adr'],
            'revenue' => $x['revenue'], 'share' => $x['share'], 'ly_nights' => $x['ly_nights'], 'ly_revenue' => $x['ly_revenue'], 'growth' => $x['growth'] ?? ($x['revenue'] > 0 ? 'new' : 'lost')]);
        $nat = $mk('nationality', 'Source market');
        $seg = $mk('segment', 'Segment');
        $tot = $nat->sum('revenue');
        $ly = $nat->sum('ly_revenue');

        return ['cols' => ['dim' => 'View', 'key' => 'Market / segment', 'bookings' => 'Bookings', 'nights' => 'Nights', 'adr' => 'ADR', 'revenue' => 'Room revenue', 'share' => 'Share %',
            'ly_nights' => 'LY nights', 'ly_revenue' => 'LY revenue', 'growth' => 'Growth %'],
            'rows' => $nat->concat($seg)->values(), 'money' => ['adr', 'revenue', 'ly_revenue'],
            'summary' => ['Room revenue' => money($tot), 'Last year' => money($ly), 'Growth' => $ly > 0 ? round(100 * ($tot - $ly) / $ly, 1).'%' : '—', 'Top market' => (string) ($nat->first()['key'] ?? '—')],
            'note' => 'Stays arriving in the period by guest nationality and by segment (each view totals 100%), cancelled excluded; room revenue = booked rate × nights; last year = the same dates a year earlier.'];
    }

    protected function r_bd_milestones($from, $to): array
    {
        $rows = BdMilestone::with('businessPlan.milestoneType')->whereBetween('target_date', [$from, $to])->get()->map(fn ($r) => [
            'plan' => optional($r->businessPlan)->name, 'type' => optional($r->milestoneType)->name,
            'milestone' => $r->milestone_name, 'target' => $r->target_date, 'achieved' => $r->achieved_date,
            'owner' => $r->owner_name, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['plan' => 'Plan', 'type' => 'Type', 'milestone' => 'Milestone', 'target' => 'Target', 'achieved' => 'Achieved', 'owner' => 'Owner', 'status' => 'Status'], 'rows' => $rows, 'note' => 'BD milestone tracking.'];
    }

    protected function r_bd_partners($from, $to): array
    {
        $BD = \App\Services\BusinessDevService::class;
        $P = \App\Models\BdPartner::class;
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->startOfDay();
        $rows = $P::with('owner', 'source', 'promo', 'company')->where('status', '!=', 'prospect')->orderBy('name')->get()->map(function ($p) use ($BD, $F, $T, $P) {
            $v = $BD::partnerProduction($p, $F, $T);
            $flags = collect($BD::partnerFlags($p))->pluck(1)->implode('; ');

            return ['no' => $p->number, 'name' => $p->name, 'type' => $p->typeLabel(), 'agreement' => $P::AGREEMENTS[$p->agreement] ?? '', 'status' => $p->statusLabel(),
                'end' => $p->end_date?->format('d M Y') ?? '', 'bookings' => $v['bookings'], 'nights' => $v['nights'], 'revenue' => $v['revenue'], 'commission' => $v['commission'], 'net' => $v['net'],
                'flags' => $flags, '_class' => str_contains($flags, 'Past its end') ? 'row-red' : ($flags !== '' ? 'row-amber' : '')];
        })->sortByDesc('revenue')->values();

        return ['cols' => ['no' => 'No.', 'name' => 'Partner', 'type' => 'Type', 'agreement' => 'Agreement', 'status' => 'Status', 'end' => 'Ends', 'bookings' => 'Bookings', 'nights' => 'Nights',
            'revenue' => 'Room revenue', 'commission' => 'Commission', 'net' => 'Net revenue', 'flags' => 'Attention'],
            'rows' => $rows, 'money' => ['revenue', 'commission', 'net'], 'sum' => ['bookings', 'nights', 'revenue', 'commission', 'net'],
            'summary' => ['Active partnerships' => (string) $rows->where('status', 'Active')->count(), 'Room revenue' => money($rows->sum('revenue')), 'Commission' => money($rows->sum('commission')),
                'Needing attention' => (string) $rows->filter(fn ($r) => $r['flags'] !== '')->count()],
            'note' => 'Stays arriving in the period (cancelled excluded) credited to each partnership through its business source, promo code or company; room revenue = booked rate × nights; commission at the agreed %. Prospects are left out.'];
    }

    protected function r_bd_rate_shop($from, $to): array
    {
        $CH = \App\Models\BdRateShop::CHANNELS;
        $shops = \App\Models\BdRateShop::with('lines.competitor')->whereDate('stay_date', '>=', Carbon::parse($from)->toDateString())->whereDate('stay_date', '<=', Carbon::parse($to)->toDateString())->orderBy('stay_date')->orderBy('channel')->get();
        $rows = $shops->map(function ($s) use ($CH) {
            $rated = $s->lines->where('sold_out', false)->whereNotNull('rate');
            $avg = $s->compAverage();
            $diff = $avg && $s->own_rate !== null ? round(100 * ((float) $s->own_rate - $avg) / $avg, 1) : '';
            $cheap = $rated->sortBy('rate')->first();

            return ['date' => $s->stay_date->format('D d M Y'), 'channel' => $CH[$s->channel] ?? $s->channel, 'own' => $s->own_sold_out ? null : $s->own_rate, 'avg' => $avg !== null ? round($avg, 2) : null,
                'diff' => $diff, 'rank' => ($r = $s->ownRank()) ? $r.' / '.($rated->count() + 1) : '', 'min' => $cheap ? (float) $cheap->rate : null, 'min_by' => $cheap?->competitor?->name ?? '',
                'max' => $rated->count() ? (float) $rated->max('rate') : null, 'sold' => $s->lines->where('sold_out', true)->count() ?: '', 'shopped' => $s->shopped_on->format('d M'),
                '_class' => $diff !== '' && abs($diff) > 25 ? 'row-amber' : ''];
        });
        $d = $rows->filter(fn ($r) => $r['diff'] !== '');

        return ['cols' => ['date' => 'Stay date', 'channel' => 'Channel', 'own' => 'Our rate', 'avg' => 'Comp average', 'diff' => 'Us vs avg %', 'rank' => 'Price rank', 'min' => 'Lowest comp',
            'min_by' => 'Lowest by', 'max' => 'Highest comp', 'sold' => 'Comps sold out', 'shopped' => 'Shopped'],
            'rows' => $rows, 'money' => ['own', 'avg', 'min', 'max'],
            'summary' => ['Shops' => (string) $rows->count(), 'Average position' => $d->count() ? (($p = round($d->avg('diff'), 1)) > 0 ? '+' : '').$p.'% vs comp set' : '—',
                'Days we are cheaper' => (string) $d->filter(fn ($r) => $r['diff'] < 0)->count(), 'Days we are dearer' => (string) $d->filter(fn ($r) => $r['diff'] > 0)->count()],
            'note' => 'Public rates of the competitive set by stay date (lowest bookable rate, 1 night, 2 adults). Price rank 1 = the dearest hotel of the set. Gaps of more than 25% to the comp-set average are highlighted.'];
    }

    protected function r_bd_rate_shop_lines($from, $to): array
    {
        // Rewritten against the real schema: shop()/competitor() relations; the parent shop carries stay_date/channel/own_rate/shopped_on/shopped_by.
        $rows = BdRateShopLine::with('shop', 'competitor')->whereHas('shop', fn ($q) => $q->whereBetween('shopped_on', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'shopped_on' => optional($r->shop)->shopped_on, 'stay_date' => optional($r->shop)->stay_date,
            'channel' => optional($r->shop)->channel, 'competitor' => optional($r->competitor)->name,
            'own_rate' => optional($r->shop)->own_rate ? (float) optional($r->shop)->own_rate : null,
            'rate' => (float) $r->rate, 'sold_out' => $r->sold_out ? 'Yes' : 'No',
        ]);
        return ['cols' => ['shopped_on' => 'Shopped On', 'stay_date' => 'Stay Date', 'channel' => 'Channel', 'competitor' => 'Competitor', 'own_rate' => 'Own Rate', 'rate' => 'Rate', 'sold_out' => 'Sold Out'], 'rows' => $rows, 'money' => ['own_rate', 'rate'], 'note' => 'BD rate shop line items.'];
    }

    protected function r_bd_results($from, $to): array
    {
        $rows = BdResult::with('businessPlan.resultType.department')->whereBetween('period_start', [$from, $to])->get()->map(fn ($r) => [
            'plan' => optional($r->businessPlan)->name, 'type' => optional($r->resultType)->name,
            'dept' => optional($r->department)->name, 'metric' => $r->metric_name, 'target' => $r->target_value,
            'actual' => $r->actual_value, 'variance' => $r->variance, 'period' => $r->period_start . ' to ' . $r->period_end, 'status' => $r->status,
        ]);
        return ['cols' => ['plan' => 'Plan', 'type' => 'Type', 'dept' => 'Dept', 'metric' => 'Metric', 'target' => 'Target', 'actual' => 'Actual', 'variance' => 'Variance', 'status' => 'Status'], 'rows' => $rows, 'note' => 'BD result tracking with target vs actual.'];
    }

    protected function r_sales_activity($from, $to): array
    {
        $A = \App\Models\SalesActivity::class;
        $acts = $A::with('owner', 'company')->where('status', 'done')->whereBetween('done_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $rows = $acts->groupBy(fn ($a) => $a->owner?->name ?? '—')->sortKeys()->map(function ($g, $who) use ($A) {
            $r = ['who' => $who];
            foreach ($A::TYPES as $k => $x) {
                $r[$k] = $g->where('type', $k)->count();
            }

            return $r + ['total' => $g->count(), 'accounts' => $g->pluck('company_id')->filter()->unique()->count(), 'cost' => round((float) $g->sum('cost'), 2)];
        })->values();
        $cols = ['who' => 'Salesperson'];
        foreach ($A::TYPES as $k => [$l]) {
            $cols[$k] = $l;
        }

        return ['cols' => $cols + ['total' => 'Total', 'accounts' => 'Accounts touched', 'cost' => 'Spend'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => array_merge(array_keys($A::TYPES), ['total', 'cost']),
            'summary' => ['Activities' => (string) $acts->count(), 'Sales visits' => (string) $acts->where('type', 'visit')->count(), 'Site inspections' => (string) $acts->where('type', 'site_inspection')->count(),
                'Accounts touched' => (string) $acts->pluck('company_id')->filter()->unique()->count(), 'Spend' => money($acts->sum('cost'))],
            'note' => 'Completed activities in the period, by completion date. Spend is the entertainment / travel cost recorded on each activity.'];
    }

    protected function r_sales_contacts($from, $to): array
    {
        $rows = SalesContact::with('salesRep')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'name' => $r->name, 'company' => $r->company, 'email' => $r->email, 'phone' => $r->phone,
            'address' => $r->address, 'city' => $r->city, 'country' => $r->country,
            'type' => $r->contact_type, 'rep' => optional($r->salesRep)->name,
            'source' => $r->source, 'created' => $r->created_at, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['name' => 'Name', 'company' => 'Company', 'email' => 'Email', 'phone' => 'Phone', 'city' => 'City', 'type' => 'Type', 'rep' => 'Rep', 'source' => 'Source', 'created' => 'Created', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Sales contact register with assignment and source tracking.'];
    }

    protected function r_sales_opportunities($from, $to): array
    {
        // Rewritten against the real schema: number/name/type/source/stage/probability/expected_close/total(); owner() is the rep, contact() the person.
        $rows = SalesOpportunity::with('company', 'contact', 'owner')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'number' => $r->number, 'name' => $r->name, 'company' => optional($r->company)->name,
            'contact' => optional($r->contact)->name, 'owner' => optional($r->owner)->name,
            'type' => $r->typeLabel(), 'source' => $r->source, 'stage' => $r->stageLabel(),
            'probability' => $r->probability, 'expected_close' => $r->expected_close,
            'total' => $r->total(),
        ]);
        return ['cols' => ['number' => 'Number', 'name' => 'Name', 'company' => 'Company', 'contact' => 'Contact', 'owner' => 'Owner', 'type' => 'Type', 'source' => 'Source', 'stage' => 'Stage', 'probability' => 'Probability', 'expected_close' => 'Expected Close', 'total' => 'Total'], 'rows' => $rows, 'money' => ['total'], 'sum' => ['total'], 'note' => 'Sales opportunity pipeline with stage and expected value.'];
    }

    protected function r_sales_pipeline($from, $to): array
    {
        $O = \App\Models\SalesOpportunity::class;
        $opps = $O::with('company', 'owner')->whereIn('stage', $O::OPEN)->orderByRaw("FIELD(stage, 'negotiation', 'proposal', 'qualified', 'prospect')")->orderBy('expected_close')->get();
        $today = bdate()->copy()->startOfDay();
        $rows = collect();
        foreach ($O::OPEN as $s) {
            $g = $opps->where('stage', $s)->sortByDesc('stage')->values();
            foreach ($opps->where('stage', $s) as $o) {
                $rows->push(['no' => $o->number, 'name' => $o->name, 'account' => $o->company->name, 'type' => $o->typeLabel(), 'owner' => (string) $o->owner?->name, 'stage' => $o->stageLabel(),
                    'close' => $o->expected_close->format('d M Y'), 'rn' => (int) $o->room_nights, 'value' => $o->total(), 'prob' => $o->probability, 'weighted' => $o->weighted(),
                    '_class' => $o->expected_close->lt($today) ? 'row-warn' : '']);
            }
            if ($g->count()) {
                $rows->push(['no' => '', 'name' => $O::STAGES[$s][0].' total', 'account' => '', 'type' => '', 'owner' => '', 'stage' => '', 'close' => '', 'rn' => (int) $g->sum('room_nights'),
                    'value' => round($g->sum(fn ($o) => $o->total()), 2), 'prob' => '', 'weighted' => round($g->sum(fn ($o) => $o->weighted()), 2), '_class' => 'bold-row']);
            }
        }

        return ['cols' => ['no' => 'No.', 'name' => 'Opportunity', 'account' => 'Account', 'type' => 'Type', 'owner' => 'Owner', 'stage' => 'Stage', 'close' => 'Expected close', 'rn' => 'Room nights',
            'value' => 'Value', 'prob' => 'Prob. %', 'weighted' => 'Weighted'],
            'rows' => $rows, 'money' => ['value', 'weighted'],
            'summary' => ['Open deals' => (string) $opps->count(), 'Pipeline' => money($opps->sum(fn ($o) => $o->total())), 'Weighted' => money($opps->sum(fn ($o) => $o->weighted())),
                'Room nights' => number_format($opps->sum('room_nights')), 'Past close date' => (string) $opps->filter(fn ($o) => $o->expected_close->lt($today))->count()],
            'note' => 'Open opportunities as of today. Weighted = value × win probability. Highlighted rows are past their expected close date.'];
    }

    protected function r_sales_targets($from, $to): array
    {
        $rows = collect();
        for ($m = Carbon::parse($from)->startOfMonth(); $m->lte(Carbon::parse($to)); $m->addMonthNoOverflow()) {
            foreach (\App\Services\SalesService::targets($m->format('Y-m')) as $r) {
                $t = $r['target'];
                $rows->push(['month' => $m->format('M Y'), 'who' => $r['user']->name, 'accounts' => $r['accounts'], 'rn_t' => (int) ($t?->room_nights), 'rn' => $r['nights'] + $r['otb_nights'],
                    'rn_pct' => $r['pct_nights'] ?? '', 'rev_t' => (float) ($t?->revenue), 'rev' => round($r['revenue'] + $r['otb_revenue'], 2), 'rev_pct' => $r['pct_revenue'] ?? '',
                    'act_t' => (int) ($t?->activities), 'act' => $r['activities'], 'won' => $r['won'], '_class' => $r['pct_revenue'] !== null && $r['pct_revenue'] < 75 ? 'row-warn' : '']);
            }
        }

        return ['cols' => ['month' => 'Month', 'who' => 'Salesperson', 'accounts' => 'Accounts', 'rn_t' => 'RN target', 'rn' => 'RN actual + OTB', 'rn_pct' => 'RN %', 'rev_t' => 'Revenue target',
            'rev' => 'Revenue actual + OTB', 'rev_pct' => 'Revenue %', 'act_t' => 'Activity target', 'act' => 'Activities', 'won' => 'Business won'],
            'rows' => $rows, 'money' => ['rev_t', 'rev', 'won'], 'sum' => ['rn_t', 'rn', 'rev_t', 'rev', 'act_t', 'act', 'won'],
            'summary' => ['Revenue target' => money($rows->sum('rev_t')), 'Actual + OTB' => money($rows->sum('rev')), 'Attainment' => $rows->sum('rev_t') > 0 ? round($rows->sum('rev') * 100 / $rows->sum('rev_t'), 1).'%' : '—'],
            'note' => 'Production is credited to the account manager of each account. Current and future months include reserved business on the books. Highlighted = below 75% of the revenue target.'];
    }

    protected function r_sales_win_loss($from, $to): array
    {
        $O = \App\Models\SalesOpportunity::class;
        $opps = $O::with('owner')->whereIn('stage', ['won', 'lost'])->whereDate('closed_on', '>=', Carbon::parse($from)->toDateString())->whereDate('closed_on', '<=', Carbon::parse($to)->toDateString())->get();
        $rows = collect();
        $line = function ($label, $g, $cls = '') {
            $w = $g->where('stage', 'won');
            $l = $g->where('stage', 'lost');

            return ['dim' => $label, 'won' => $w->count(), 'lost' => $l->count(), 'rate' => $g->count() ? round($w->count() * 100 / $g->count(), 1) : 0.0,
                'won_value' => round($w->sum(fn ($o) => $o->total()), 2), 'lost_value' => round($l->sum(fn ($o) => $o->total()), 2), 'rn' => (int) $w->sum('room_nights'), '_class' => $cls];
        };
        foreach (['type' => ['By type', $O::TYPES], 'source' => ['By source', $O::SOURCES]] as $k => [$title, $labels]) {
            $rows->push(['dim' => $title, 'won' => '', 'lost' => '', 'rate' => '', 'won_value' => '', 'lost_value' => '', 'rn' => '', '_class' => 'bold-row']);
            foreach ($opps->groupBy($k) as $v => $g) {
                $rows->push($line('   '.($labels[$v] ?? $v), $g));
            }
        }
        $rows->push(['dim' => 'By salesperson', 'won' => '', 'lost' => '', 'rate' => '', 'won_value' => '', 'lost_value' => '', 'rn' => '', '_class' => 'bold-row']);
        foreach ($opps->groupBy(fn ($o) => $o->owner?->name ?? '—') as $v => $g) {
            $rows->push($line('   '.$v, $g));
        }
        $rows->push(['dim' => 'Lost – reasons', 'won' => '', 'lost' => '', 'rate' => '', 'won_value' => '', 'lost_value' => '', 'rn' => '', '_class' => 'bold-row']);
        foreach ($opps->where('stage', 'lost')->groupBy('lost_reason') as $v => $g) {
            $rows->push(['dim' => '   '.($O::LOST_REASONS[$v] ?? $v), 'won' => '', 'lost' => $g->count(), 'rate' => '', 'won_value' => '', 'lost_value' => round($g->sum(fn ($o) => $o->total()), 2), 'rn' => '', '_class' => '']);
        }
        $rows->push($line('Total', $opps, 'bold-row'));
        $won = $opps->where('stage', 'won');

        return ['cols' => ['dim' => 'Breakdown', 'won' => 'Won', 'lost' => 'Lost', 'rate' => 'Win rate %', 'won_value' => 'Value won', 'lost_value' => 'Value lost', 'rn' => 'Room nights won'],
            'rows' => $rows, 'money' => ['won_value', 'lost_value'],
            'summary' => ['Decided' => (string) $opps->count(), 'Won' => (string) $won->count(), 'Win rate' => ($opps->count() ? round($won->count() * 100 / $opps->count(), 1) : 0).'%',
                'Value won' => money($won->sum(fn ($o) => $o->total())), 'Value lost' => money($opps->where('stage', 'lost')->sum(fn ($o) => $o->total()))],
            'note' => 'Opportunities closed (won or lost) in the period, by close date.'];
    }

}
