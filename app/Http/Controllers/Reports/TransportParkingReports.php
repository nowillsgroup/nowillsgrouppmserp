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
 * Transport & Parking reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait TransportParkingReports
{
    protected function r_car_rentals($from, $to): array
    {
        $rows = CarRental::with('reservation.guest.vehicle')->whereBetween('pickup_date', [$from, $to])->get()->map(fn ($r) => [
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'vehicle' => optional($r->vehicle)->plate_number, 'pickup' => $r->pickup_date, 'dropoff' => $r->dropoff_date,
            'days' => $r->days, 'rate' => $r->daily_rate, 'amount' => $r->total_amount, 'status' => $r->status,
        ]);
        return ['cols' => ['res' => 'Reservation', 'guest' => 'Guest', 'vehicle' => 'Vehicle', 'pickup' => 'Pickup', 'dropoff' => 'Dropoff', 'days' => 'Days', 'rate' => 'Daily Rate', 'amount' => 'Total', 'status' => 'Status'], 'rows' => $rows, 'money' => ['daily_rate', 'total_amount'], 'sum' => ['total_amount'], 'note' => 'Car rental reservations.'];
    }

    protected function r_parking_occupancy($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $days = (int) $f->diffInDays($t->copy()->startOfDay()) + 1;
        $rows = \App\Models\ParkingZone::orderBy('sort')->orderBy('code')->get()->map(function ($z) use ($f, $t, $days) {
            $ss = \App\Models\ParkingSession::where('parking_zone_id', $z->id)->where('status', '!=', 'cancelled')->where('entered_at', '<=', $t)
                ->where(fn ($q) => $q->whereNull('exited_at')->orWhere('exited_at', '>=', $f))->get(['id', 'entered_at', 'exited_at', 'kind', 'is_valet', 'fee']);
            $ev = [];
            $bayMinutes = 0;
            foreach ($ss as $s) {
                $a = $s->entered_at->max($f);
                $b = ($s->exited_at ?? now())->min($t);
                $ev[] = [$a->timestamp, 1];
                $ev[] = [$b->timestamp, -1];
                $bayMinutes += max(0, $a->diffInSeconds($b, true) / 60);
            }
            usort($ev, fn ($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
            $cur = $peak = 0;
            foreach ($ev as [, $d]) {
                $cur += $d;
                $peak = max($peak, $cur);
            }
            $inPeriod = $ss->filter(fn ($s) => $s->entered_at->between($f, $t));
            $closed = $inPeriod->whereNotNull('exited_at');
            $avg = $closed->count() ? $closed->avg(fn ($s) => $s->entered_at->diffInSeconds($s->exited_at, true) / 60) : null;
            $util = $z->capacity ? round($bayMinutes / ($z->capacity * $days * 1440) * 100, 1) : null;

            return ['zone' => $z->code.' · '.$z->name, 'type' => $z->typeLabel(), 'bays' => $z->capacity ?: null, 'tickets' => $inPeriod->count(), 'guests' => $inPeriod->where('kind', 'guest')->count() ?: null,
                'visitors' => $inPeriod->where('kind', 'visitor')->count() ?: null, 'staff' => $inPeriod->where('kind', 'staff')->count() ?: null,
                'avg' => $avg !== null ? (floor($avg / 60) ? floor($avg / 60).'h ' : '').round(fmod($avg, 60)).'m' : '', 'peak' => $peak, 'peak_pct' => $z->capacity ? round($peak / $z->capacity * 100).'%' : '',
                'util' => $util !== null ? $util.'%' : '', 'now' => \App\Models\ParkingSession::where('parking_zone_id', $z->id)->whereIn('status', \App\Models\ParkingSession::ACTIVE)->count(),
                '_class' => $z->capacity && $peak >= $z->capacity ? 'row-red' : ''];
        });

        return ['cols' => ['zone' => 'Zone', 'type' => 'Type', 'bays' => 'Bays', 'tickets' => 'Tickets', 'guests' => 'Guests', 'visitors' => 'Visitors', 'staff' => 'Permit', 'avg' => 'Avg stay',
            'peak' => 'Peak parked', 'peak_pct' => 'Peak %', 'util' => 'Bay utilisation', 'now' => 'Parked now'], 'rows' => $rows, 'money' => [], 'sum' => ['bays', 'tickets', 'guests', 'visitors', 'staff', 'now'],
            'summary' => ['Tickets' => (string) $rows->sum('tickets'), 'Bays' => (string) $rows->sum('bays'), 'Zones at capacity' => (string) $rows->where('_class', 'row-red')->count()],
            'note' => 'Peak = most vehicles parked at the same time in the period. Bay utilisation = vehicle-minutes ÷ (bays × minutes in the period).'];
    }

    protected function r_parking_permits($from, $to): array
    {
        $rows = \App\Models\ParkingPermit::with(['vehicle', 'zone', 'employee'])->orderByRaw("status = 'revoked'")->orderBy('valid_to')->get()->map(function ($p) {
            [$state] = $p->stateBadge();

            return ['no' => $p->number, 'holder' => $p->holder_name, 'type' => \App\Models\ParkingPermit::HOLDERS[$p->holder_type] ?? $p->holder_type, 'emp' => $p->employee?->emp_code ?? '',
                'plate' => $p->vehicle->plate_display, 'vehicle' => $p->vehicle->description(), 'zone' => $p->zone?->code ?? 'any', 'from' => $p->valid_from->format('d M Y'), 'to' => $p->valid_to->format('d M Y'),
                'days' => $p->state() === 'active' ? (int) bdate()->diffInDays($p->valid_to) : null, 'fee' => (float) $p->fee ?: null, 'state' => $state.($p->revoke_reason ? ' – '.$p->revoke_reason : ''),
                'uses' => \App\Models\ParkingSession::where('parking_permit_id', $p->id)->count() ?: null,
                '_class' => $p->state() === 'active' && $p->valid_to->lte(bdate()->copy()->addDays(7)) ? 'row-amber' : ($p->state() === 'revoked' ? 'row-red' : '')];
        });

        return ['cols' => ['no' => 'Permit', 'holder' => 'Holder', 'type' => 'Type', 'emp' => 'Emp code', 'plate' => 'Plate', 'vehicle' => 'Vehicle', 'zone' => 'Zone', 'from' => 'From', 'to' => 'To',
            'days' => 'Days left', 'fee' => 'Fee', 'state' => 'State', 'uses' => 'Times parked'], 'rows' => $rows, 'money' => ['fee'], 'sum' => ['fee', 'uses'],
            'summary' => ['Permits' => (string) $rows->count(), 'Valid today' => (string) $rows->filter(fn ($r) => str_starts_with($r['state'], 'Active'))->count(),
                'Expiring in 7 days' => (string) $rows->where('_class', 'row-amber')->count()],
            'note' => 'Permit fees are recorded here only – bill them through the tenant lease, payroll deduction or a non-resident folio.'];
    }

    protected function r_parking_revenue($from, $to): array
    {
        $ch = \App\Models\ParkingCharge::with('session')->whereDate('business_date', '>=', Carbon::parse($from)->toDateString())->whereDate('business_date', '<=', Carbon::parse($to)->toDateString())->get();
        $waived = \App\Models\ParkingSession::where('fee_waived', '>', 0)->whereBetween('exited_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $rows = $ch->groupBy(fn ($c) => $c->business_date->toDateString())->sortKeys()->map(function ($g, $d) use ($waived) {
            $n = $g->where('kind', 'night');
            $x = $g->where('kind', 'exit');
            $vis = $x->filter(fn ($c) => $c->session?->kind === 'visitor');
            $w = $waived->filter(fn ($s) => $s->exited_at->toDateString() === $d);

            return ['date' => Carbon::parse($d)->format('D d M Y'), 'nights' => $n->count() ?: null, 'night_amt' => (float) $n->sum('amount') ?: null,
                'exits' => $vis->pluck('parking_session_id')->unique()->count() ?: null, 'exit_amt' => (float) $x->sum('amount') ?: null,
                'lost' => (float) $g->where('kind', 'lost')->sum('amount') ?: null, 'total' => (float) $g->sum('amount'), 'waived' => (float) $w->sum('fee_waived') ?: null];
        })->values();

        return ['cols' => ['date' => 'Business date', 'nights' => 'Guest nights', 'night_amt' => 'Room-folio parking', 'exits' => 'Paid exits', 'exit_amt' => 'Exit fees & valet', 'lost' => 'Lost tickets',
            'total' => 'Total revenue', 'waived' => 'Waived'], 'rows' => $rows, 'money' => ['night_amt', 'exit_amt', 'lost', 'total', 'waived'], 'sum' => ['nights', 'night_amt', 'exits', 'exit_amt', 'lost', 'total', 'waived'],
            'summary' => ['Revenue' => money($rows->sum('total')), 'Room folios' => money($rows->sum('night_amt')), 'Paid at exit' => money($rows->sum('exit_amt') + $rows->sum('lost')), 'Waived' => money($rows->sum('waived'))],
            'note' => 'Net of VAT, NHIL and GETFund; posted under the Parking & Valet charge category (GL 4075). Waived amounts were never charged.'];
    }

    protected function r_parking_sessions($from, $to): array
    {
        $rows = \App\Models\ParkingSession::with(['zone', 'stay.room'])->whereBetween('entered_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('entered_at')->orderBy('id')->get()
            ->map(fn ($s) => ['in' => $s->entered_at->format('d M Y H:i'), 'no' => $s->number, 'plate' => $s->plate, 'type' => $s->kindLabel(), 'zone' => $s->zone?->code,
                'who' => trim(($s->stay?->room ? 'Rm '.$s->stay->room->room_no.' · ' : '').($s->guest_name ?? '')), 'out' => $s->exited_at?->format('d M Y H:i') ?? '', 'dur' => $s->durationLabel(),
                'valid' => $s->validation_hours ?: null, 'lost' => $s->lost_ticket ? 'Yes' : '', 'fee' => (float) $s->fee ?: null, 'waived' => (float) $s->fee_waived ?: null, 'status' => $s->statusLabel(),
                '_class' => $s->lost_ticket || (float) $s->fee_waived > 0 ? 'row-amber' : ($s->status === 'cancelled' ? 'row-red' : '')]);

        return ['cols' => ['in' => 'In', 'no' => 'Ticket', 'plate' => 'Plate', 'type' => 'Type', 'zone' => 'Zone', 'who' => 'Driver / room', 'out' => 'Out', 'dur' => 'Duration', 'valid' => 'Validated h',
            'lost' => 'Lost ticket', 'fee' => 'Charged at exit', 'waived' => 'Waived', 'status' => 'Status'], 'rows' => $rows, 'money' => ['fee', 'waived'], 'sum' => ['fee', 'waived'],
            'summary' => ['Tickets' => (string) $rows->count(), 'Valet' => (string) $rows->filter(fn ($r) => str_contains($r['type'], 'valet'))->count(), 'Lost tickets' => (string) $rows->where('lost', 'Yes')->count(),
                'Charged at exit' => money($rows->sum('fee'))],
            'note' => 'Guest nights posted to room folios by the night audit are not in "charged at exit" – see Parking Revenue by Day. Amounts are net of VAT / levies.'];
    }

    protected function r_parking_valet($from, $to): array
    {
        $sla = \App\Services\ParkingService::cfg()['sla_minutes'];
        $ss = \App\Models\ParkingSession::with('runner')->where('is_valet', true)->whereNotNull('requested_at')->whereNotNull('ready_at')
            ->whereBetween('requested_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get();
        $mk = function ($g, string $name) use ($sla) {
            $w = $g->map(fn ($s) => $s->requested_at->diffInSeconds($s->ready_at, true) / 60);

            return ['runner' => $name, 'n' => $g->count(), 'avg' => $g->count() ? round($w->avg(), 1) : null, 'max' => $g->count() ? round($w->max(), 1) : null,
                'sla' => $g->count() ? round($w->filter(fn ($x) => $x <= $sla)->count() / $g->count() * 100, 1).'%' : '', 'late' => $w->filter(fn ($x) => $x > $sla)->count() ?: null,
                'qr' => $g->where('requested_via', 'qr')->count() ?: null, 'phone' => $g->where('requested_via', 'phone')->count() ?: null, 'desk' => $g->where('requested_via', 'desk')->count() ?: null];
        };
        $rows = $ss->groupBy(fn ($s) => $s->runner?->name ?? '—')->sortKeys()->map(fn ($g, $n) => $mk($g, $n))->values();
        if ($ss->count()) {
            $rows->push($mk($ss, 'All runners') + ['_class' => 'bold-row']);
        }

        return ['cols' => ['runner' => 'Runner', 'n' => 'Retrievals', 'avg' => 'Avg min to door', 'max' => 'Longest', 'sla' => "Within {$sla} min", 'late' => 'Over target', 'qr' => 'Via QR', 'phone' => 'Via phone', 'desk' => 'At the desk'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Retrievals' => (string) $ss->count(), 'Average' => $ss->count() ? round($ss->avg(fn ($s) => $s->requested_at->diffInSeconds($s->ready_at, true) / 60), 1).' min' : '—', 'Target' => $sla.' min'],
            'note' => 'Minutes from the request (desk, phone or the guest scanning the QR on the ticket) until the car is at the door.'];
    }

    protected function r_parking_vehicles($from, $to): array
    {
        $rows = ParkingVehicle::with('zone.guest')->whereBetween('entered_at', [$from, $to])->get()->map(fn ($r) => [
            'plate' => $r->plate_number, 'zone' => optional($r->zone)->name, 'guest' => optional($r->guest)->name,
            'entered' => $r->entered_at, 'exited' => $r->exited_at, 'duration' => $r->duration_minutes,
            'rate' => $r->rate, 'amount' => $r->amount, 'payment' => $r->payment_method, 'status' => $r->status,
        ]);
        return ['cols' => ['plate' => 'Plate', 'zone' => 'Zone', 'guest' => 'Guest', 'entered' => 'Entered', 'exited' => 'Exited', 'duration' => 'Duration (min)', 'rate' => 'Rate', 'amount' => 'Amount', 'payment' => 'Payment', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate', 'amount'], 'sum' => ['amount'], 'note' => 'Parking vehicle register.'];
    }

    protected function r_rental_vehicles($from, $to): array
    {
        $rows = RentalVehicle::with('vehicleType')->get()->map(fn ($r) => [
            'plate' => $r->plate_number, 'make' => $r->make, 'model' => $r->model,
            'year' => $r->year, 'type' => optional($r->vehicleType)->name, 'capacity' => $r->capacity,
            'seats' => $r->seats, 'transmission' => $r->transmission, 'ac' => $r->has_ac,
            'rate' => $r->daily_rate, 'currency' => $r->currency, 'status' => $r->status,
        ]);
        return ['cols' => ['plate' => 'Plate', 'make' => 'Make', 'model' => 'Model', 'year' => 'Year', 'type' => 'Type', 'capacity' => 'Capacity', 'seats' => 'Seats', 'rate' => 'Daily Rate', 'status' => 'Status'], 'rows' => $rows, 'money' => ['daily_rate'], 'note' => 'Rental vehicle fleet register.'];
    }

    protected function r_transport_drivers($from, $to): array
    {
        $all = $this->trRows($from, $to)->whereNotNull('driver_id')->groupBy('driver_id');
        $rows = \App\Models\Driver::orderBy('name')->get()->map(function ($d) use ($all) {
            $t = $all[$d->id] ?? collect();
            if (! $t->count()) {
                return null;
            }
            $done = $t->where('status', 'completed');
            $started = $done->filter(fn ($x) => $x->started_at);
            $onTime = $started->filter(fn ($x) => $x->started_at->lte($x->busy_from->copy()->addMinutes(10)))->count();

            return ['driver' => $d->name, 'trips' => $done->count(), 'pax' => (int) $done->sum('pax'), 'km' => (int) $done->sum(fn ($x) => $x->km() ?? 0),
                'hours' => round($done->sum(fn ($x) => $x->started_at && $x->completed_at ? $x->started_at->diffInMinutes($x->completed_at) : $x->duration_min) / 60, 1),
                'ontime' => $started->count() ? round($onTime / $started->count() * 100) : '', 'avglate' => $started->count() ? round($started->avg(fn ($x) => $x->lateMinutes())) : '',
                'ns' => $t->where('status', 'no_show')->count(), 'rev' => (float) $done->where('billing', '!=', 'complimentary')->sum('total'),
                'last' => $done->last()?->scheduled_at->format('d M H:i') ?? '', '_class' => $started->count() && $onTime / $started->count() < .8 ? 'row-alert' : ''];
        })->filter()->values();
        $st = $rows->where('ontime', '!==', '');

        return ['cols' => ['driver' => 'Driver', 'trips' => 'Trips', 'pax' => 'Passengers', 'km' => 'km', 'hours' => 'Hours driving', 'ontime' => 'On time %', 'avglate' => 'Avg min late',
            'ns' => 'No-shows', 'rev' => 'Revenue', 'last' => 'Last trip'],
            'rows' => $rows, 'money' => ['rev'], 'sum' => ['trips', 'pax', 'km', 'hours', 'ns', 'rev'],
            'summary' => ['Trips' => (string) $rows->sum('trips'), 'Drivers' => (string) $rows->count(), 'On time (fleet)' => $st->count() ? round($st->avg('ontime')).'%' : '–'],
            'note' => 'On time = the car left no more than 10 minutes after the planned departure (pick-ups leave before the landing time by the lead set in Transport Settings). Drivers below 80% on time are highlighted. No-shows = trips where the guest was not found.'];
    }

    protected function r_transport_revenue($from, $to): array
    {
        $rows = $this->trRows($from, $to)->groupBy(fn ($t) => $t->scheduled_at->toDateString())->map(function ($d, $day) {
            $done = $d->where('status', 'completed');
            $sum = fn ($b) => (float) $done->where('billing', $b)->sum('total');

            return ['day' => Carbon::parse($day)->format('D d M Y'), 'pu' => $done->where('type', 'pickup')->count(), 'dr' => $done->where('type', 'drop')->count(), 'tf' => $done->where('type', 'transfer')->count(),
                'room' => $sum('room'), 'direct' => $sum('direct'), 'comp' => $sum('complimentary'), 'extra' => (float) $done->where('billing', '!=', 'complimentary')->sum('extra_amount'),
                'nsfee' => (float) $d->where('status', 'no_show')->whereNotNull('folio_transaction_id')->sum('total'),
                'ns' => $d->where('status', 'no_show')->count(), 'cx' => $d->where('status', 'cancelled')->count(), 'total' => $sum('room') + $sum('direct')
                    + (float) $d->where('status', 'no_show')->whereNotNull('folio_transaction_id')->sum('total')];
        })->values();

        return ['cols' => ['day' => 'Day', 'pu' => 'Pick-ups', 'dr' => 'Drops', 'tf' => 'Transfers', 'room' => 'To room bill', 'direct' => 'Paid direct', 'extra' => 'of which extras',
            'nsfee' => 'No-show fees', 'comp' => 'Complimentary value', 'ns' => 'No-shows', 'cx' => 'Cancelled', 'total' => 'Revenue'],
            'rows' => $rows, 'money' => ['room', 'direct', 'extra', 'nsfee', 'comp', 'total'], 'sum' => ['pu', 'dr', 'tf', 'room', 'direct', 'extra', 'nsfee', 'comp', 'ns', 'cx', 'total'],
            'summary' => ['Revenue' => money($rows->sum('total')), 'Trips' => (string) ($rows->sum('pu') + $rows->sum('dr') + $rows->sum('tf')),
                'Given free' => money($rows->sum('comp')), 'Avg fare' => ($n = $rows->sum('pu') + $rows->sum('dr') + $rows->sum('tf')) ? money(($rows->sum('room') + $rows->sum('direct')) / $n) : '–'],
            'note' => 'Completed trips by scheduled date, fares before tax. Room-billed fares post to the guest folio (GL 4070 Transport Revenue); direct fares are invoiced and paid at completion. Complimentary value is not revenue – it shows what was given away.'];
    }

    protected function r_transport_tariffs($from, $to): array
    {
        // Rewritten against the real schema: name/kind/vehicle_type/flat_rate/included_km/per_km/wait_per_hour/duration_min; there is no transport type table.
        $rows = TransportTariff::orderBy('sort_order')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'kind' => $r->kind, 'vehicle_type' => $r->vehicle_type,
            'flat_rate' => (float) $r->flat_rate, 'included_km' => $r->included_km,
            'per_km' => (float) $r->per_km, 'wait_per_hour' => (float) $r->wait_per_hour,
            'duration_min' => $r->duration_min, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'kind' => 'Kind', 'vehicle_type' => 'Vehicle', 'flat_rate' => 'Flat Rate', 'included_km' => 'Included KM', 'per_km' => 'Per KM', 'wait_per_hour' => 'Wait/Hour', 'duration_min' => 'Duration (min)', 'active' => 'Active'], 'rows' => $rows, 'money' => ['flat_rate', 'per_km', 'wait_per_hour'], 'note' => 'Transport tariff rate card.'];
    }

    protected function r_transport_trips($from, $to): array
    {
        $T = \App\Models\TransportTrip::class;
        $rows = $this->trRows($from, $to)->map(fn ($t) => ['when' => $t->scheduled_at->format('d M Y H:i'), 'ref' => $t->trip_no, 'type' => $t->typeLabel(),
            'guest' => $t->passenger_name.($t->stay?->room ? ' · '.$t->stay->room->room_no : ''), 'pax' => (int) $t->pax, 'route' => $t->route(), 'flight' => (string) $t->flight_no,
            'car' => (string) $t->vehicle?->code, 'driver' => (string) $t->driver?->name, 'km' => $t->status === 'completed' ? ($t->km() ?? '') : '', 'late' => $t->status === 'completed' ? ($t->lateMinutes() ?? '') : '',
            'fare' => in_array($t->status, ['cancelled'], true) ? 0 : (float) $t->total, 'billing' => $T::BILLING[$t->billing] ?? $t->billing, 'status' => $t->statusLabel(),
            '_class' => in_array($t->status, ['no_show', 'cancelled'], true) ? 'row-alert' : ''])->values();
        $done = $rows->where('status', 'Completed');

        return ['cols' => ['when' => 'Date & time', 'ref' => 'Trip', 'type' => 'Type', 'guest' => 'Guest · room', 'pax' => 'Pax', 'route' => 'Route', 'flight' => 'Flight / train',
            'car' => 'Vehicle', 'driver' => 'Driver', 'km' => 'km', 'late' => 'Min late', 'fare' => 'Fare', 'billing' => 'Billing', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['fare'], 'sum' => ['pax', 'fare'],
            'summary' => ['Trips' => (string) $rows->count(), 'Completed' => (string) $done->count(), 'Passengers carried' => (string) $done->sum('pax'),
                'km driven' => number_format((int) $done->sum(fn ($r) => (int) $r['km'])), 'No-shows / cancelled' => $rows->where('status', 'No-show')->count().' / '.$rows->where('status', 'Cancelled')->count()],
            'note' => 'By the scheduled time (landing time for pick-ups, leaving time for drops). Fare = tariff + extras before tax (complimentary trips show the value given away). "Min late" = minutes the car left after the planned departure.'];
    }

    protected function r_transport_vehicles($from, $to): array
    {
        $trips = $this->trRows($from, $to)->where('status', 'completed')->whereNotNull('vehicle_id')->groupBy('vehicle_id');
        $exp = \App\Models\VehicleExpense::whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()])->get()->groupBy('vehicle_id');
        $rows = \App\Models\Vehicle::orderBy('code')->get()->map(function ($v) use ($trips, $exp) {
            $t = $trips[$v->id] ?? collect();
            $e = $exp[$v->id] ?? collect();
            $km = (int) $t->sum(fn ($x) => $x->km() ?? 0);
            $hours = round($t->sum(fn ($x) => $x->started_at && $x->completed_at ? $x->started_at->diffInMinutes($x->completed_at) : $x->duration_min) / 60, 1);
            $rev = (float) $t->where('billing', '!=', 'complimentary')->sum('total');
            $fuel = (float) $e->where('kind', 'fuel')->sum('amount');
            $cost = (float) $e->sum('amount');
            $lit = (float) $e->where('kind', 'fuel')->sum('litres');
            if (! $t->count() && ! $e->count() && ! $v->is_active) {
                return null;
            }

            return ['car' => $v->code.' · '.$v->reg_no, 'type' => \App\Models\Vehicle::TYPES[$v->type] ?? $v->type, 'trips' => $t->count(), 'km' => $km, 'hours' => $hours,
                'rev' => $rev, 'comp' => (float) $t->where('billing', 'complimentary')->sum('total'), 'fuel' => $fuel, 'other' => round($cost - $fuel, 2),
                'cpk' => $km ? round($cost / $km, 2) : '', 'kpl' => $lit > 0 && $km ? round($km / $lit, 1) : '', 'margin' => round($rev - $cost, 2),
                '_class' => $rev - $cost < 0 ? 'row-alert' : ''];
        })->filter()->values();

        return ['cols' => ['car' => 'Vehicle', 'type' => 'Type', 'trips' => 'Trips', 'km' => 'km', 'hours' => 'Hours on road', 'rev' => 'Revenue', 'comp' => 'Complimentary value',
            'fuel' => 'Fuel', 'other' => 'Service, tolls & other', 'cpk' => 'Cost / km', 'kpl' => 'km / litre', 'margin' => 'Margin'],
            'rows' => $rows, 'money' => ['rev', 'comp', 'fuel', 'other', 'cpk', 'margin'], 'sum' => ['trips', 'km', 'hours', 'rev', 'comp', 'fuel', 'other', 'margin'],
            'summary' => ['km driven' => number_format($rows->sum('km')), 'Running cost' => money($rows->sum('fuel') + $rows->sum('other')),
                'Cost per km' => $rows->sum('km') ? money(($rows->sum('fuel') + $rows->sum('other')) / $rows->sum('km')) : '–', 'Margin' => money($rows->sum('margin'))],
            'note' => 'Completed trips by scheduled date; km from the odometer readings entered at start and completion. Running cost = vehicle expenses dated in the period (fuel, service, repairs, tolls, parking). Driver salaries are in payroll and not included. Vehicles running at a loss are highlighted.'];
    }

    protected function r_fleet_maintenance($from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();
        $records = \App\Models\VehicleMaintenanceRecord::whereDate('completed_at', '>=', $f)->whereDate('completed_at', '<=', $t)->get()->groupBy('vehicle_id');
        $rows = \App\Models\Vehicle::orderBy('code')->get()->map(function ($v) use ($records, $f, $t) {
            $done = $records[$v->id] ?? collect();
            $state = \App\Services\FleetService::maintenanceState($v);
            if (! $done->count() && ! $state['open']->count() && ! $state['overdue']->count() && ! $v->is_active) {
                return null;
            }
            $next = $state['plans']->filter(fn ($p) => $p->due_date)->sortBy('due_date')->first();

            return ['car' => $v->code.' · '.$v->reg_no, 'done' => $done->count(),
                'planned' => $done->whereIn('category', ['preventive', 'oil', 'tyres'])->count(), 'repairs' => $done->where('category', 'repair')->count(),
                'cost' => round((float) $done->sum('actual_cost'), 2), 'open' => $state['open']->count(), 'overdue' => $state['overdue']->count(),
                'last' => $done->sortByDesc('completed_at')->first()?->completed_at?->format('d M Y') ?? '',
                'next' => $next?->due_date?->format('d M Y') ?? ($next?->due_odometer ? 'at '.number_format($next->due_odometer).' km' : ''),
                '_class' => $state['overdue']->count() ? 'row-alert' : ''];
        })->filter()->values();

        return ['cols' => ['car' => 'Vehicle', 'done' => 'Jobs completed', 'planned' => 'Planned service', 'repairs' => 'Repairs', 'cost' => 'Cost',
            'open' => 'Still open', 'overdue' => 'Service overdue', 'last' => 'Last completed', 'next' => 'Next due'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['done', 'planned', 'repairs', 'cost', 'open', 'overdue'],
            'summary' => ['Jobs completed' => (string) $rows->sum('done'), 'Maintenance cost' => money($rows->sum('cost')),
                'Still open' => (string) $rows->sum('open'), 'Overdue services' => (string) $rows->sum('overdue')],
            'note' => 'Completed work orders in the period, by completion date. Cost is the invoice amount captured on the work order; when the same amount is also logged to running expenses it appears once in the Vehicle Utilisation report under service & repair. Overdue counts service-plan intervals whose date or odometer threshold has passed. Vehicles with an overdue service are highlighted.'];
    }

    protected function r_fleet_gps($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $limit = (int) \App\Services\FleetService::cfg()['speed_limit_kph'];
        $fixes = \App\Models\VehiclePosition::whereBetween('recorded_at', [$f, $t])
            ->selectRaw("vehicle_id, COUNT(*) n, MAX(recorded_at) last_at, AVG(CASE WHEN speed_kph IS NULL THEN 0 ELSE speed_kph END) avg_speed,
                SUM(CASE WHEN speed_kph > 5 THEN 1 ELSE 0 END) moving, SUM(CASE WHEN speed_kph > {$limit} THEN 1 ELSE 0 END) overlimit")
            ->groupBy('vehicle_id')->get()->keyBy('vehicle_id');
        $rows = \App\Models\Vehicle::orderBy('code')->get()->map(function ($v) use ($fixes, $f, $t) {
            $fx = $fixes[$v->id] ?? null;
            $tracker = \App\Models\VehicleTracker::where('vehicle_id', $v->id)->orderByDesc('is_active')->first();
            if (! $fx && ! $tracker && ! $v->is_active) {
                return null;
            }
            $km = $fx ? \App\Services\FleetService::gpsKm($v->id, $f, $t) : 0;
            $state = $tracker ? (\App\Services\FleetService::STATES[\App\Services\FleetService::trackerState($tracker)] ?? ['-']) : ['No device'];

            return ['car' => $v->code.' · '.$v->reg_no, 'device' => $tracker ? ($tracker->provider.' · '.$tracker->device_uid) : '—',
                'state' => $state[0], 'fixes' => (int) ($fx->n ?? 0), 'km' => $km, 'avgspeed' => $fx ? round((float) $fx->avg_speed, 1) : '',
                'moving' => $fx && $fx->n ? round((int) $fx->moving / (int) $fx->n * 100).'%' : '',
                'overlimit' => (int) ($fx->overlimit ?? 0),
                'last' => $fx ? Carbon::parse($fx->last_at)->format('d M H:i') : 'No fix',
                '_class' => $state[0] === 'Offline' ? 'row-alert' : ''];
        })->filter()->values();
        $tracked = $rows->filter(fn ($r) => $r['fixes'] > 0);

        return ['cols' => ['car' => 'Vehicle', 'device' => 'Tracker', 'state' => 'Now', 'fixes' => 'Fixes', 'km' => 'km (GPS)', 'avgspeed' => 'Avg km/h',
            'moving' => 'Moving', 'overlimit' => 'Over limit', 'last' => 'Last fix'],
            'rows' => $rows, 'money' => [], 'sum' => ['fixes', 'km'],
            'summary' => ['Distance' => number_format($rows->sum('km')).' km', 'Fixes received' => number_format($rows->sum('fixes')),
                'Vehicles tracked' => $tracked->count().' / '.$rows->count(), 'Offline now' => (string) $rows->where('state', 'Offline')->count(),
                'Fixes over '.$limit.' km/h' => number_format($rows->sum('overlimit'))],
            'note' => 'Distance is measured from consecutive GPS fixes at most 10 minutes apart, ignoring fixes closer than 25 m (jitter) and single hops above 5 km (lost signal). “Over limit” counts fixes above the speeding limit set in Fleet settings. Trackers that are offline at the time the report runs are highlighted. GPS history is pruned nightly after the retention window set in Fleet settings.'];
    }

}
