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
 * Foreign Guests & Forex reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait ForeignGuestsForexReports
{
    protected function r_foreign_arrivals($from, $to): array
    {
        $F = \App\Services\FormCService::class;
        $f0 = Carbon::parse($from)->startOfDay();
        $t0 = Carbon::parse($to)->endOfDay();
        $stays = \App\Models\ReservationRoom::with('reservation.guest', 'guest')->whereIn('status', ['checked_in', 'checked_out'])->whereBetween('checked_in_at', [$f0, $t0])->get()
            ->map(fn ($s) => ['s' => $s, 'g' => $F::stayGuest($s)])->filter(fn ($r) => $F::isForeign($r['g']));
        $forms = \App\Models\FormCRecord::whereBetween('checked_in_at', [$f0, $t0])->where('is_companion', false)->get()->keyBy('reservation_room_id');
        $total = $stays->count();
        $rows = $stays->groupBy(fn ($r) => ucwords(mb_strtolower(trim($r['g']->nationality))))->map(function ($grp, $nat) use ($forms, $total) {
            $nights = $grp->sum(fn ($r) => max(1, $r['s']->checked_in_at->copy()->startOfDay()->diffInDays(($r['s']->checked_out_at ?? $r['s']->departure_date)->copy()->startOfDay())));
            $fc = $grp->map(fn ($r) => $forms[$r['s']->id] ?? null)->filter();
            $filed = $fc->whereNotNull('submitted_at');

            return ['nat' => $nat, 'guests' => $grp->pluck('g.id')->unique()->count(), 'stays' => $grp->count(), 'nights' => (int) $nights,
                'avg' => round($nights / max(1, $grp->count()), 1), 'share' => $total ? round($grp->count() / $total * 100, 1).'%' : '',
                'ontime' => $filed->count() ? round($filed->filter(fn ($f) => ! $f->isLate())->count() / $filed->count() * 100).'%' : '—'];
        })->sortByDesc('stays')->values();

        return ['cols' => ['nat' => 'Nationality', 'guests' => 'Guests', 'stays' => 'Stays', 'nights' => 'Room nights', 'avg' => 'Avg nights', 'share' => 'Share', 'ontime' => 'Form C on time'],
            'rows' => $rows, 'money' => [], 'sum' => ['guests', 'stays', 'nights'],
            'summary' => ['Foreign stays' => (string) $total, 'Nationalities' => (string) $rows->count(), 'Top market' => (string) ($rows->first()['nat'] ?? '—'),
                'Room nights' => (string) $rows->sum('nights')],
            'note' => 'Stays checked in during the period whose guest is a foreign national (nationality other than the property\'s country). Room nights run to the check-out, or the planned departure for guests still in house.'];
    }

    protected function r_forex_position($from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();
        $buys = \App\Models\ForexExchange::whereNull('voided_at')->whereDate('business_date', '<=', $t)->get(['currency_id', 'business_date', 'fc_amount', 'inr_amount'])->groupBy('currency_id');
        $outs = \App\Models\ForexSurrenderLine::with('surrender')->whereHas('surrender', fn ($q) => $q->where('hotel_id', \App\Support\Tenant::id())->whereDate('date', '<=', $t))->get()->groupBy('currency_id');
        $rows = \App\Models\Currency::orderBy('sort_order')->get()->map(function ($c) use ($buys, $outs, $f) {
            $b = $buys[$c->id] ?? collect();
            $o = $outs[$c->id] ?? collect();
            if ($b->isEmpty() && $o->isEmpty()) {
                return null;
            }
            $before = fn ($x, $col) => (float) $x->filter(fn ($r) => ($r->business_date ?? $r->surrender->date)->toDateString() < $f)->sum($col);
            $in = fn ($x, $col) => (float) $x->filter(fn ($r) => ($r->business_date ?? $r->surrender->date)->toDateString() >= $f)->sum($col);
            $open = $before($b, 'fc_amount') - $before($o, 'fc_amount');
            $bought = $in($b, 'fc_amount');
            $sold = $in($o, 'fc_amount');
            $cost = $in($o, 'cost');
            $real = $in($o, 'realised');

            return ['cur' => $c->code, 'open' => round($open, 2), 'bought' => round($bought, 2), 'bought_inr' => round($in($b, 'inr_amount'), 2), 'sold' => round($sold, 2),
                'close' => round($open + $bought - $sold, 2), 'cost' => round($cost, 2), 'real' => round($real, 2), 'gain' => round($real - $cost, 2),
                '_class' => $real - $cost < 0 ? 'row-alert' : ''];
        })->filter()->values();

        return ['cols' => ['cur' => 'Currency', 'open' => 'Opening', 'bought' => 'Bought', 'bought_inr' => 'Bought '.hotel()->currency_symbol, 'sold' => 'Surrendered', 'close' => 'Closing',
            'cost' => 'Cost surrendered '.hotel()->currency_symbol, 'real' => 'Realised '.hotel()->currency_symbol, 'gain' => 'Gain / loss'],
            'rows' => $rows, 'money' => ['bought_inr', 'cost', 'real', 'gain'], 'sum' => ['bought_inr', 'cost', 'real', 'gain'],
            'summary' => ['Currencies' => (string) $rows->count(), 'Bought' => money($rows->sum('bought_inr')), 'Realised' => money($rows->sum('real')), 'Exchange gain' => money($rows->sum('gain'))],
            'note' => 'Foreign-currency amounts per currency; cost = average purchase cost of what was surrendered. Authorised money changers surrender purchases to their bank / authorised dealer promptly (default reminder: 7 days). Gains and losses post to GL 4986.'];
    }

    protected function r_forex_rates($from, $to): array
    {
        $rows = ForexRate::with('currency.board')->whereBetween('rate_date', [$from, $to])->get()->map(fn ($r) => [
            'currency' => optional($r->currency)->name, 'board' => optional($r->board)->name,
            'rate_date' => $r->rate_date, 'buy' => $r->buy_rate, 'sell' => $r->sell_rate,
            'mid' => $r->mid_rate, 'source' => $r->source, 'status' => $r->status,
        ]);
        return ['cols' => ['currency' => 'Currency', 'board' => 'Board', 'date' => 'Date', 'buy' => 'Buy Rate', 'sell' => 'Sell Rate', 'mid' => 'Mid Rate', 'source' => 'Source', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Daily forex rate board with buy/sell/mid rates.'];
    }

    protected function r_forex_register($from, $to): array
    {
        $xs = \App\Models\ForexExchange::with('currency', 'stay.room', 'creator')->whereDate('business_date', '>=', Carbon::parse($from)->toDateString())->whereDate('business_date', '<=', Carbon::parse($to)->toDateString())
            ->orderBy('business_date')->orderBy('id')->get();
        $rows = $xs->map(function ($x) {
            $v = (bool) $x->voided_at;

            return ['date' => $x->business_date->format('d M Y'), 'ref' => $x->exchange_no, 'guest' => $x->guest_name.($x->stay?->room ? ' · '.$x->stay->room->room_no : ''),
                'nat' => (string) $x->nationality, 'passport' => \App\Models\Guest::mask($x->passport_no), 'cur' => $x->currency?->code.($x->instrument === 'tc' ? ' (TC)' : ''),
                'fc' => number_format((float) $x->fc_amount, 2), 'rate' => (float) $x->rate.($x->unit > 1 ? ' / '.$x->unit : ''),
                'inr' => $v ? 0.0 : (float) $x->inr_amount, 'gst' => $v ? 0.0 : (float) $x->gst, 'net' => $v ? 0.0 : (float) $x->net_amount,
                'usd' => $v ? 0.0 : (float) $x->usd_equiv, 'how' => $x->payout === 'cash' ? 'Cash' : 'Room bill', 'by' => (string) $x->creator?->name,
                'status' => $v ? 'Void – '.$x->void_reason : 'OK', '_class' => $v ? 'row-alert' : ''];
        })->values();
        $ok = $xs->whereNull('voided_at');

        return ['cols' => ['date' => 'Date', 'ref' => 'Ref', 'guest' => 'Guest · room', 'nat' => 'Nationality', 'passport' => 'Passport', 'cur' => 'Currency', 'fc' => 'Amount',
            'rate' => 'Rate '.hotel()->currency_symbol, 'inr' => 'Cedi value', 'net' => 'Paid / credited', 'usd' => 'USD equiv.', 'how' => 'How', 'by' => 'Cashier', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['inr', 'net'], 'sum' => ['inr', 'net', 'usd'],
            'summary' => ['Purchases' => (string) $ok->count(), 'Cedi value' => money($ok->sum('inr_amount')), 'Paid in cash' => money($ok->where('payout', 'cash')->sum('net_amount')),
                'Credited to bills' => money($ok->where('payout', 'folio')->sum('net_amount')), 'Voided' => (string) $xs->whereNotNull('voided_at')->count()],
            'note' => 'Bank of Ghana rules for licensed buying: the desk only buys foreign currency, pays cash up to the house limit per foreign visitor (counted in USD), records the passport for every purchase and keeps a copy from the configured cedi value. Money changing is VAT-exempt. Voided purchases show zero.'];
    }

    protected function r_forex_surrenders($from, $to): array
    {
        $rows = ForexSurrender::with('guest.currency.user')->whereBetween('surrendered_at', [$from, $to])->get()->map(fn ($r) => [
            'guest' => optional($r->guest)->name, 'currency' => optional($r->currency)->name,
            'surrendered' => $r->surrendered_at, 'amount' => $r->amount, 'rate' => $r->rate,
            'ghs_amount' => $r->ghs_amount, 'fee' => $r->fee, 'status' => $r->status, 'user' => optional($r->user)->name,
        ]);
        return ['cols' => ['guest' => 'Guest', 'currency' => 'Currency', 'surrendered' => 'Surrendered', 'amount' => 'Amount', 'rate' => 'Rate', 'ghs_amount' => 'GHS Amount', 'fee' => 'Fee', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount', 'ghs_amount', 'fee'], 'sum' => ['amount', 'ghs_amount', 'fee'], 'note' => 'Foreign currency surrender register.'];
    }

    protected function r_formc_register($from, $to): array
    {
        $recs = \App\Models\FormCRecord::with('stay.room')->whereBetween('checked_in_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('checked_in_at')->orderBy('id')->get();
        $V = \App\Models\FormCRecord::VISA_TYPES;
        $rows = $recs->map(fn ($f) => ['in' => $f->checked_in_at?->format('d M Y H:i'), 'form' => $f->form_no, 'guest' => $f->name().($f->is_companion ? ' (companion)' : ''),
            'nat' => (string) $f->nationality, 'passport' => \App\Models\Guest::mask($f->passport_no), 'visa' => (string) ($V[$f->visa_type] ?? ''), 'room' => (string) $f->stay?->room?->room_no,
            'deadline' => $f->deadline_at?->format('d M H:i'), 'filed' => $f->submitted_at?->format('d M H:i') ?? '', 'ref' => (string) $f->frro_ref,
            'hours' => $f->submitted_at && $f->checked_in_at ? round($f->checked_in_at->diffInMinutes($f->submitted_at) / 60, 1) : '',
            'out' => $f->departed_at?->format('d M H:i') ?? '', 'dep' => $f->departure_submitted_at?->format('d M H:i') ?? ($f->departurePending() ? 'pending' : ''),
            'status' => match ($f->state()) { 'overdue' => 'Overdue', 'due' => 'To submit', 'departure' => 'Departure to report', 'done' => 'Closed', 'exempt' => 'Exempt', default => 'Submitted' }
                .($f->isLate() ? ' (late)' : ''),
            '_class' => $f->isOverdue() || $f->isLate() ? 'row-alert' : ''])->values();
        $filed = $recs->whereNotNull('submitted_at');
        $onTime = $filed->filter(fn ($f) => ! $f->isLate())->count();

        return ['cols' => ['in' => 'Checked in', 'form' => 'Form C', 'guest' => 'Name', 'nat' => 'Nationality', 'passport' => 'Passport', 'visa' => 'Visa', 'room' => 'Room',
            'deadline' => 'Deadline', 'filed' => 'Filed', 'ref' => 'FRRO ref', 'hours' => 'Hours taken', 'out' => 'Checked out', 'dep' => 'Departure reported', 'status' => 'Status'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Foreign nationals' => (string) $recs->count(), 'Filed' => (string) $filed->count(), 'On time' => $filed->count() ? round($onTime / $filed->count() * 100).'%' : '—',
                'Late' => (string) ($filed->count() - $onTime), 'Still to file' => (string) $recs->where('status', 'pending')->count(),
                'Departures to report' => (string) $recs->filter(fn ($f) => $f->departurePending())->count(), 'Exempt' => (string) $recs->where('status', 'exempt')->count()],
            'note' => 'Rule 14 of the Registration of Foreigners Rules 1992: the arrival of every foreign national is reported on indianfrro.gov.in within 24 hours; departures after check-out. Passport numbers are masked – the full register is in Foreign Guests › Form C › Export (logged).'];
    }

}
