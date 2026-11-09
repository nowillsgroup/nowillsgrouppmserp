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
 * Self-Service Kiosk reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait SelfServiceKioskReports
{
    protected function r_kiosk_adoption($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $sess = $S::whereBetween('started_at', [$a, $b])->get();
        $has = fn ($s, $step) => collect($s->trail)->contains(fn ($t) => ($t[1] ?? '') === $step);
        $ins = \App\Models\ReservationRoom::whereBetween('checked_in_at', [$a, $b])->get(['checked_in_at'])->groupBy(fn ($r) => $r->checked_in_at->toDateString());
        $outs = \App\Models\ReservationRoom::whereBetween('checked_out_at', [$a, $b])->get(['checked_out_at'])->groupBy(fn ($r) => $r->checked_out_at->toDateString());
        $rows = collect();
        $tot = ['in' => 0, 'kin' => 0];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $k = $d->toDateString();
            $day = $sess->filter(fn ($s) => $s->started_at->toDateString() === $k);
            $kin = $day->where('kind', '!=', 'checkout')->filter(fn ($s) => $has($s, 'room'));
            $all = count($ins[$k] ?? []);
            $tot['in'] += $all;
            $tot['kin'] += $kin->count();
            $fin = $day->where('kind', 'checkin')->where('status', 'completed');
            $rows->push(['day' => $d->format('d-m-Y D'), 'in' => $all ?: null, 'kin' => $kin->count() ?: null, 'share' => $all ? round($kin->count() * 100 / $all).'%' : null,
                'out' => count($outs[$k] ?? []) ?: null, 'kout' => $day->where('kind', 'checkout')->filter(fn ($s) => $has($s, 'checkout'))->count() ?: null,
                'walk' => $day->where('kind', 'walkin')->filter(fn ($s) => $has($s, 'booked'))->count() ?: null, 'assist' => $day->where('status', 'assist')->count() ?: null,
                'avg' => $fin->count() ? gmdate('i:s', (int) round($fin->avg(fn ($s) => $s->seconds()))) : null]);
        }

        return ['cols' => ['day' => 'Day', 'in' => 'Check-ins', 'kin' => 'At the kiosk', 'share' => 'Kiosk share', 'out' => 'Check-outs', 'kout' => 'At the kiosk', 'walk' => 'Walk-ins booked', 'assist' => 'Handed to desk', 'avg' => 'Avg check-in (m:s)'],
            'rows' => $rows, 'money' => [], 'sum' => ['in', 'kin', 'out', 'kout', 'walk', 'assist'],
            'summary' => ['Kiosk share of check-ins' => $tot['in'] ? round($tot['kin'] * 100 / $tot['in']).'%' : '—', 'Kiosk visits' => (string) $sess->count()]];
    }

    protected function r_kiosk_assist($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        $list = $S::with('kiosk', 'guest', 'reservation', 'resolvedBy')->where('status', 'assist')->whereBetween('assist_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('assist_at')->get();
        $rows = $list->map(fn ($s) => ['at' => $s->assist_at->format('d-m-Y H:i'), 'no' => $s->number, 'kiosk' => $s->kiosk?->name, 'kind' => $s->kindLabel(), 'guest' => $s->guest?->full_name,
            'res' => $s->reservation?->reservation_no, 'reason' => $s->assistLabel(), 'note' => $s->assist_note, 'wait' => $s->resolved_at ? (int) round($s->assist_at->diffInMinutes($s->resolved_at)) : null,
            'by' => $s->resolvedBy?->name, 'done' => $s->resolution, '_class' => $s->resolved_at ? '' : 'row-red']);
        $resolved = $list->whereNotNull('resolved_at');

        return ['cols' => ['at' => 'When', 'no' => 'Visit', 'kiosk' => 'Kiosk', 'kind' => 'Service', 'guest' => 'Guest', 'res' => 'Booking', 'reason' => 'Reason', 'note' => 'Note', 'wait' => 'Minutes to help', 'by' => 'Dealt with by', 'done' => 'What was done'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Requests' => (string) $list->count(), 'Still open' => (string) ($list->count() - $resolved->count()),
                'Average minutes to help' => $resolved->count() ? (string) round($resolved->avg(fn ($s) => $s->assist_at->diffInMinutes($s->resolved_at))) : '—']
                + $list->groupBy(fn ($s) => $s->assistLabel())->map(fn ($g) => (string) $g->count())->sortDesc()->take(4)->all()];
    }

    protected function r_kiosk_payments($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        $list = $S::with('kiosk', 'guest', 'reservation')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->where('amount_paid', '>', 0)->orderBy('started_at')->get();
        $pays = \App\Models\OnlinePayment::whereIn('id', $list->pluck('online_payment_id')->filter())->get()->keyBy('id');
        $rows = $list->map(function ($s) use ($pays) {
            $p = $s->online_payment_id ? ($pays[$s->online_payment_id] ?? null) : null;
            $ref = collect($s->trail)->filter(fn ($t) => ($t[1] ?? '') === 'paid')->map(fn ($t) => strtok((string) $t[2], ' '))->implode(', ');

            return ['at' => $s->started_at->format('d-m-Y H:i'), 'no' => $s->number, 'kiosk' => $s->kiosk?->name, 'kind' => $s->kindLabel(), 'guest' => $s->guest?->full_name, 'res' => $s->reservation?->reservation_no,
                'link' => $ref ?: $p?->pay_no, 'purpose' => $s->kind === 'checkout' ? 'Bill balance' : ($s->kind === 'walkin' ? 'Walk-in stay' : 'Check-in deposit'), 'amount' => (float) $s->amount_paid, 'gw' => $p ? ucfirst($p->provider).' · '.$p->status : 'paid'];
        });

        return ['cols' => ['at' => 'When', 'no' => 'Visit', 'kiosk' => 'Kiosk', 'kind' => 'Service', 'guest' => 'Guest', 'res' => 'Booking', 'link' => 'Payment link', 'purpose' => 'For', 'amount' => 'Amount', 'gw' => 'Gateway'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => collect(['Check-in deposit', 'Walk-in stay', 'Bill balance'])->mapWithKeys(fn ($t) => [$t.'s' => money($rows->where('purpose', $t)->sum('amount'))])->all()];
    }

    protected function r_kiosk_sessions($from, $to): array
    {
        $S = \App\Models\KioskSession::class;
        $list = $S::with('kiosk', 'guest', 'reservation', 'room')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('started_at')->get();
        $rows = $list->map(fn ($s) => ['no' => $s->number, 'at' => $s->started_at->format('d-m-Y H:i'), 'kiosk' => $s->kiosk?->name, 'kind' => $s->kindLabel(), 'guest' => $s->guest?->full_name,
            'res' => $s->reservation?->reservation_no, 'room' => $s->room?->room_no, 'step' => $s->stepLabel(), 'secs' => $s->durationLabel(), 'paid' => (float) $s->amount_paid ?: null,
            'status' => $s->statusLabel().($s->status === 'assist' ? ' – '.$s->assistLabel() : ''), '_class' => $s->status === 'assist' && ! $s->resolved_at ? 'row-red' : '']);
        $done = $list->where('status', 'completed');

        return ['cols' => ['no' => 'Visit', 'at' => 'Started', 'kiosk' => 'Kiosk', 'kind' => 'Service', 'guest' => 'Guest', 'res' => 'Booking', 'room' => 'Room', 'step' => 'Reached', 'secs' => 'Time', 'paid' => 'Paid', 'status' => 'Outcome'],
            'rows' => $rows, 'money' => ['paid'], 'sum' => ['paid'],
            'summary' => ['Visits' => (string) $list->count(), 'Finished at the kiosk' => $done->count().($list->count() ? ' ('.round($done->count() * 100 / $list->count()).'%)' : ''),
                'Handed to the desk' => (string) $list->where('status', 'assist')->count(), 'Walked away / started over' => (string) $list->whereIn('status', ['abandoned', 'cancelled'])->count()]];
    }

    protected function r_kiosks($from, $to): array
    {
        // Rewritten against the real schema: location/serial/status/pairing state/last_seen_at; no kiosk type or floor table.
        $rows = Kiosk::orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'location' => $r->location, 'serial' => $r->serial,
            'checkin' => $r->allow_checkin ? 'Yes' : 'No', 'checkout' => $r->allow_checkout ? 'Yes' : 'No',
            'walkin' => $r->allow_walkin ? 'Yes' : 'No',
            'state' => $r->stateLabel(), 'last_seen' => $r->last_seen_at,
        ]);
        return ['cols' => ['name' => 'Name', 'location' => 'Location', 'serial' => 'Serial', 'checkin' => 'Check-in', 'checkout' => 'Check-out', 'walkin' => 'Walk-in', 'state' => 'State', 'last_seen' => 'Last Seen'], 'rows' => $rows, 'note' => 'Self-service kiosk register.'];
    }

}
