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
 * Channel Manager reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait ChannelManagerReports
{
    protected function r_channel_ari_states($from, $to): array
    {
        $rows = ChannelAriState::with('channel')->whereBetween('state_date', [$from, $to])->get()->map(fn ($r) => [
            'channel' => optional($r->channel)->name, 'date' => $r->state_date,
            'rooms_sold' => $r->rooms_sold, 'rooms_available' => $r->rooms_available,
            'occupancy' => $r->occupancy_pct, 'rate_par' => $r->rate_par, 'rate_achieved' => $r->rate_achieved,
            'revpar' => $r->revpar, 'state' => $r->ari_state,
        ]);
        return ['cols' => ['channel' => 'Channel', 'date' => 'Date', 'rooms_sold' => 'Rooms Sold', 'rooms_avail' => 'Available', 'occupancy' => 'Occ %', 'rate_par' => 'PAR', 'rate_ach' => 'Achieved', 'revpar' => 'RevPAR', 'state' => 'ARI State'], 'rows' => $rows, 'money' => ['rate_par', 'rate_achieved', 'revpar'], 'note' => 'Channel ARI (available rate index) state snapshots.'];
    }

    protected function r_channel_messages($from, $to): array
    {
        $rows = ChannelMessage::with('channel')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'channel' => optional($r->channel)->name, 'sent' => $r->sent_at,
            'direction' => $r->direction, 'content' => $r->content, 'guest' => $r->guest_id,
            'status' => $r->status, 'direction' => $r->direction,
        ]);
        return ['cols' => ['channel' => 'Channel', 'sent' => 'Sent At', 'direction' => 'Direction', 'content' => 'Content', 'guest' => 'Guest', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Channel messaging log.'];
    }

    protected function r_channel_parity($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay()->max(bdate()->copy()->startOfDay());
        $days = min(60, max(1, (int) $f->diffInDays(Carbon::parse($to)->startOfDay()) + 1));
        $types = \App\Models\RoomType::pluck('name', 'id');
        $rows = collect();
        foreach (\App\Models\ChannelConnection::where('is_active', true)->orderBy('name')->get() as $c) {
            foreach (\App\Services\ChannelService::parity($c, $f, $days) as $typeId => $byDate) {
                foreach ($byDate as $date => $cell) {
                    if (! $cell['out_of_sync']) {
                        continue;
                    }
                    $e = $cell['expected'];
                    $h = $cell['has'];
                    $rates = collect($e['rates'])->filter(fn ($p, $code) => ! isset($h['rates'][$code]) || abs((float) $h['rates'][$code] - $p) > 0.004)
                        ->map(fn ($p, $code) => $code.' '.number_format($p).' vs '.(isset($h['rates'][$code]) ? number_format($h['rates'][$code]) : '–'))->implode('; ');
                    $rows->push(['date' => Carbon::parse($date)->format('D d M'), 'ch' => $c->name, 'room' => $types[$typeId] ?? '?', 'pms' => $e['stop_sell'] ? 'closed' : $e['available'],
                        'has' => $h ? ($h['stop_sell'] ? 'closed' : $h['available']) : 'never pushed', 'rates' => $rates ?: '—',
                        'minstay' => $h && $h['min_stay'] !== $e['min_stay'] ? $e['min_stay'].' vs '.$h['min_stay'] : '', 'pushed' => $cell['pushed_at']?->format('d M H:i') ?? '—']);
                }
            }
        }

        return ['cols' => ['date' => 'Night', 'ch' => 'Channel', 'room' => 'Room type', 'pms' => 'PMS availability', 'has' => 'Channel has', 'rates' => 'Prices (PMS vs channel)', 'minstay' => 'Min stay (PMS vs channel)', 'pushed' => 'Last pushed'],
            'rows' => $rows->values(), 'note' => "Active connections, {$days} night(s) from ".$f->format('d M Y').' (max 60). Differences are normal for a minute after a change (the next push fixes them); persistent ones point to a failing connection.'];
    }

    protected function r_channel_production($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $res = \App\Models\Reservation::whereNotNull('channel_connection_id')->whereBetween('created_at', [$f, $t])
            ->get(['id', 'channel_connection_id', 'status', 'arrival_date', 'departure_date', 'created_at', 'channel_amount', 'channel_commission', 'channel_payment'])->groupBy('channel_connection_id');
        $rows = \App\Models\ChannelConnection::orderBy('name')->get()->map(function ($c) use ($res) {
            $all = $res->get($c->id, collect());
            $live = $all->where('status', '!=', 'cancelled');
            $nights = $live->sum(fn ($r) => $r->arrival_date->diffInDays($r->departure_date));
            $gross = round((float) $live->sum('channel_amount'), 2);
            $comm = round((float) $live->sum('channel_commission'), 2);

            return ['ch' => $c->name, 'code' => $c->code, 'book' => $live->count(), 'nights' => $nights, 'gross' => $gross, 'comm' => $comm, 'net' => round($gross - $comm, 2),
                'adr' => $nights ? round($gross / $nights, 2) : 0, 'lead' => $live->count() ? round($live->avg(fn ($r) => max(0, $r->created_at->copy()->startOfDay()->diffInDays($r->arrival_date, false))), 1) : null,
                'los' => $live->count() ? round($nights / $live->count(), 1) : null, 'cxl' => $all->where('status', 'cancelled')->count(),
                'cxlp' => $all->count() ? round($all->where('status', 'cancelled')->count() / $all->count() * 100, 1) : null,
                'pre' => $live->count() ? round($live->where('channel_payment', 'channel_collect')->count() / $live->count() * 100) : null];
        })->filter(fn ($r) => $r['book'] || $r['cxl'])->values();

        return ['cols' => ['ch' => 'Channel', 'code' => 'Code', 'book' => 'Bookings', 'nights' => 'Room nights', 'gross' => 'Gross revenue', 'comm' => 'Commission', 'net' => 'Net revenue',
            'adr' => 'ADR (gross)', 'lead' => 'Avg lead (days)', 'los' => 'Avg LOS', 'cxl' => 'Cancelled', 'cxlp' => 'Cancel %', 'pre' => 'Prepaid %'],
            'rows' => $rows, 'money' => ['gross', 'comm', 'net', 'adr'], 'sum' => ['book', 'nights', 'gross', 'comm', 'net', 'cxl'],
            'chart' => ['label' => 'Net revenue', 'key' => 'net', 'x' => 'ch'],
            'note' => 'Bookings received (created) in the period through two-way connections. Gross = amount sent by the channel; commission as sent or at the contract rate; net = gross − commission. Lead time = arrival − booking date.'];
    }

    protected function r_channel_rate_maps($from, $to): array
    {
        $rows = ChannelRateMap::with('channel.roomType.ratePlan')->whereBetween('effective_from', [$from, $to])->get()->map(fn ($r) => [
            'channel' => optional($r->channel)->name, 'room_type' => optional($r->roomType)->name,
            'plan' => optional($r->ratePlan)->name, 'rate' => $r->rate, 'currency' => $r->currency,
            'meal' => $r->meal_plan, 'rooms_left' => $r->rooms_left, 'min_stay' => $r->min_stay,
            'max_stay' => $r->max_stay, 'effective' => $r->effective_from, 'status' => $r->status,
        ]);
        return ['cols' => ['channel' => 'Channel', 'room_type' => 'Room Type', 'plan' => 'Rate Plan', 'rate' => 'Rate', 'currency' => 'Currency', 'meal' => 'Meal Plan', 'rooms_left' => 'Rooms Left', 'min_stay' => 'Min Stay', 'effective' => 'Effective'], 'rows' => $rows, 'money' => ['rate'], 'sum' => ['rate'], 'note' => 'Channel rate mappings with availability and stay restrictions.'];
    }

    protected function r_channel_restrictions($from, $to): array
    {
        $rows = ChannelRestriction::with('channel.roomType')->whereBetween('effective_from', [$from, $to])->get()->map(fn ($r) => [
            'channel' => optional($r->channel)->name, 'room_type' => optional($r->roomType)->name,
            'type' => $r->restriction_type, 'value' => $r->restriction_value,
            'from' => $r->effective_from, 'to' => $r->effective_to, 'status' => $r->status,
        ]);
        return ['cols' => ['channel' => 'Channel', 'room_type' => 'Room Type', 'type' => 'Type', 'value' => 'Value', 'from' => 'From', 'to' => 'To', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Channel inventory restrictions and closures.'];
    }

    protected function r_channel_room_maps($from, $to): array
    {
        $rows = ChannelRoomMap::with('channel.roomType')->whereBetween('effective_from', [$from, $to])->get()->map(fn ($r) => [
            'channel' => optional($r->channel)->name, 'room_type' => optional($r->roomType)->name,
            'sellable' => $r->sellable, 'capacity' => $r->capacity, 'rate' => $r->rate,
            'currency' => $r->currency, 'inventory_type' => $r->inventory_type,
            'effective' => $r->effective_from, 'status' => $r->status,
        ]);
        return ['cols' => ['channel' => 'Channel', 'room_type' => 'Room Type', 'sellable' => 'Sellable', 'capacity' => 'Capacity', 'rate' => 'Rate', 'currency' => 'Currency', 'effective' => 'Effective'], 'rows' => $rows, 'money' => ['rate'], 'sum' => ['rate'], 'note' => 'Channel room type mappings with sellable flags.'];
    }

    protected function r_channel_sync_log($from, $to): array
    {
        $rows = \App\Models\ChannelPush::with('connection:id,name')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('id')->get()
            ->groupBy(fn ($p) => $p->created_at->toDateString().'|'.$p->channel_connection_id)
            ->map(function ($g) {
                $failed = $g->where('status', 'failed');

                return ['date' => $g->first()->created_at->format('d M Y'), 'ch' => $g->first()->connection?->name, 'pushes' => $g->count(), 'full' => $g->where('kind', 'full')->count(),
                    'ok' => $g->where('status', 'ok')->count(), 'failed' => $failed->count(), 'rn' => (int) $g->where('status', 'ok')->sum('room_days'),
                    'ms' => (int) round($g->avg('duration_ms')), 'err' => $failed->last()?->error];
            })->sortByDesc(fn ($r, $k) => $k)->values();

        return ['cols' => ['date' => 'Date', 'ch' => 'Channel', 'pushes' => 'Pushes', 'full' => 'Full refreshes', 'ok' => 'OK', 'failed' => 'Failed', 'rn' => 'Room-nights sent', 'ms' => 'Avg ms', 'err' => 'Last error'],
            'rows' => $rows, 'sum' => ['pushes', 'full', 'ok', 'failed', 'rn'],
            'note' => 'Failed pushes retry automatically (1, 5, 15, 30, then every 60 minutes) and the next successful push carries every pending change. Push history is kept for 90 days.'];
    }

    protected function r_ota_channels($from, $to): array
    {
        // Rewritten against the real schema: channels map to room type + business source; there is no channel type table.
        $rows = OtaChannel::with('roomType', 'source')->get()->map(fn ($r) => [
            'name' => $r->name, 'room_type' => optional($r->roomType)->name,
            'source' => optional($r->source)->name,
            'active' => $r->is_active ? 'Yes' : 'No', 'last_sync' => $r->last_sync_at,
            'sync_status' => ucfirst((string) $r->last_sync_status),
        ]);
        return ['cols' => ['name' => 'Name', 'room_type' => 'Room Type', 'source' => 'Source', 'active' => 'Active', 'last_sync' => 'Last Sync', 'sync_status' => 'Sync Status'], 'rows' => $rows, 'note' => 'OTA channel configuration register.'];
    }

}
