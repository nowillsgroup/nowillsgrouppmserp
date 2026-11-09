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
 * IPTV & Guest Technology reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait IptvGuestTechnologyReports
{
    protected function r_iptv_channels($from, $to): array
    {
        $C = \App\Models\TvChannel::class;
        $list = $C::withCount(['programmes as ahead' => fn ($q) => $q->where('ends_at', '>', now())])->orderBy('number')->get();
        $views = \App\Models\TvEvent::where('event', 'channel')->where('created_at', '>=', now()->subDays(30))->selectRaw('target, COUNT(*) n')->groupBy('target')->pluck('n', 'target');
        $rows = $list->map(fn ($c) => ['no' => $c->number, 'name' => $c->name, 'cat' => $c->categoryLabel(), 'lang' => $c->language, 'radio' => $c->is_radio ? 'Radio' : '', 'stream' => $c->stream_url,
            'epg' => $c->epg_id, 'ahead' => $c->epg_id ? $c->ahead : null, 'views' => (int) ($views[$c->number.' '.$c->name] ?? 0) ?: null, 'active' => $c->is_active ? 'Yes' : 'No', '_class' => $c->is_active ? '' : 'muted']);

        return ['cols' => ['no' => 'No.', 'name' => 'Channel', 'cat' => 'Category', 'lang' => 'Language', 'radio' => 'Radio', 'stream' => 'Stream', 'epg' => 'Guide id', 'ahead' => 'Programmes ahead', 'views' => 'Views (30 days)', 'active' => 'In line-up'],
            'rows' => $rows, 'money' => [], 'sum' => ['views'],
            'summary' => ['Channels' => (string) $list->where('is_active', true)->count(), 'With a guide' => (string) $list->whereNotNull('epg_id')->count(), 'Most watched' => (string) ($views->sortDesc()->keys()->first() ?? '—')]];
    }

    protected function r_iptv_devices($from, $to): array
    {
        $D = \App\Models\TvDevice::class;
        $list = $D::with('room')->where('status', '!=', 'pending')->orderBy('kind')->orderBy('name')->get();
        $stays = \App\Models\ReservationRoom::with(['guest', 'reservation.guest'])->where('status', 'checked_in')->whereIn('room_id', $list->pluck('room_id')->filter())->get()->keyBy('room_id');
        $rows = $list->map(function ($d) use ($D, $stays) {
            $st = $d->room_id ? ($stays[$d->room_id] ?? null) : null;
            $g = $st ? ($st->guest ?? $st->reservation?->guest) : null;

            return ['name' => $d->name, 'kind' => $D::KINDS[$d->kind] ?? $d->kind, 'where' => $d->place(), 'platform' => $D::PLATFORMS[$d->platform] ?? $d->platform, 'model' => $d->model,
                'mac' => $d->mac, 'serial' => $d->serial, 'status' => $d->stateLabel(), 'seen' => $d->last_seen_at?->format('d-m-Y H:i'), 'paired' => $d->paired_at?->format('d-m-Y'),
                'guest' => $g ? trim($g->first_name.' '.$g->last_name) : null, '_class' => $d->status === 'active' && ! $d->isOnline() ? 'row-red' : ''];
        });
        $active = $list->where('status', 'active');

        return ['cols' => ['name' => 'TV', 'kind' => 'Type', 'where' => 'Room / location', 'platform' => 'Platform', 'model' => 'Model', 'mac' => 'MAC', 'serial' => 'Serial', 'status' => 'Status',
            'seen' => 'Last heartbeat', 'paired' => 'Paired', 'guest' => 'Guest now'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['TVs' => (string) $list->count(), 'Active' => (string) $active->count(), 'Online now' => (string) $active->filter->isOnline()->count(),
                'Rooms covered' => $active->where('kind', 'room')->pluck('room_id')->filter()->unique()->count().' of '.\App\Models\Room::where('is_active', true)->count()]];
    }

    protected function r_iptv_engagement($from, $to): array
    {
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $ev = \App\Models\TvEvent::whereBetween('created_at', [$a, $b])->selectRaw("DATE(created_at) d, COUNT(*) n, COUNT(DISTINCT tv_device_id) tvs, SUM(event = 'welcome') w, SUM(event = 'channel') ch, SUM(event = 'rent') r, SUM(event = 'order') o, SUM(event IN ('request', 'wakeup', 'checkout')) q")
            ->groupBy('d')->get()->keyBy('d');
        $rows = collect();
        for ($d = $a->copy(); $d->lte($b) && $rows->count() < 400; $d->addDay()) {
            $x = $ev->get($d->toDateString());
            $rows->push(['date' => $d->format('d-m-Y'), 'tvs' => $x ? (int) $x->tvs : null, 'n' => $x ? (int) $x->n : null, 'w' => $x && $x->w ? (int) $x->w : null, 'ch' => $x && $x->ch ? (int) $x->ch : null,
                'r' => $x && $x->r ? (int) $x->r : null, 'o' => $x && $x->o ? (int) $x->o : null, 'q' => $x && $x->q ? (int) $x->q : null]);
        }
        $an = \App\Services\IptvService::analytics($from->toDateString(), $to->toDateString());

        return ['cols' => ['date' => 'Date', 'tvs' => 'TVs used', 'n' => 'Screen actions', 'w' => 'Welcomes', 'ch' => 'Channel views', 'r' => 'Rentals', 'o' => 'Dining orders', 'q' => 'Requests & wake-ups'],
            'rows' => $rows, 'money' => [], 'sum' => ['n', 'w', 'ch', 'r', 'o', 'q'],
            'summary' => ['Stays using the TV portal' => $an['adoption'] !== null ? $an['adoption'].'% ('.$an['stays_used'].' of '.$an['stays'].')' : '—', 'Screen actions' => number_format($an['events']),
                'Rental revenue' => money($an['rental_rev']), 'Dining ordered' => money($an['order_value'])]];
    }

    protected function r_iptv_messages($from, $to): array
    {
        $M = \App\Models\TvMessage::class;
        $list = $M::with(['sender', 'closer'])->withCount('reads')->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('starts_at')->get();
        $rows = $list->map(fn ($m) => ['no' => $m->number, 'kind' => $M::KINDS[$m->kind] ?? $m->kind, 'to' => $m->targetLabel(), 'title' => $m->title, 'prio' => $m->priority === 'high' ? 'High' : '',
            'from' => $m->starts_at->format('d-m-Y H:i'), 'until' => $m->ends_at?->format('d-m-Y H:i') ?? ($m->closed_at?->format('d-m-Y H:i')), 'by' => $m->sender?->name, 'reads' => $m->reads_count ?: null,
            'state' => $m->state(), 'note' => $m->close_note ? $m->close_note.' – '.($m->closer?->name ?? '') : null, '_class' => $m->kind === 'emergency' ? 'row-red' : '']);
        $em = $list->where('kind', 'emergency');

        return ['cols' => ['no' => 'Ref', 'kind' => 'Kind', 'to' => 'Audience', 'title' => 'Title', 'prio' => 'Priority', 'from' => 'From', 'until' => 'Until', 'by' => 'Sent by', 'reads' => 'Reads', 'state' => 'State', 'note' => 'Withdrawn / all clear'],
            'rows' => $rows, 'money' => [], 'sum' => ['reads'],
            'summary' => ['Messages' => (string) $list->where('kind', '!=', 'emergency')->count(), 'Emergency alerts' => (string) $em->count(),
                'Longest alert' => $em->filter(fn ($m) => $m->closed_at)->map(fn ($m) => (int) round($m->starts_at->diffInMinutes($m->closed_at)))->max() !== null ? $em->filter(fn ($m) => $m->closed_at)->map(fn ($m) => (int) round($m->starts_at->diffInMinutes($m->closed_at)))->max().' min' : '—']];
    }

    protected function r_iptv_orders($from, $to): array
    {
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $rows = collect();
        foreach (\App\Models\PosOrder::with(['stay.room', 'items'])->where('source', 'tv')->whereBetween('created_at', [$a, $b])->get() as $o) {
            $items = $o->items->where('is_cancelled', false);
            $rows->push(['at' => $o->created_at, 'type' => 'Dining order', 'ref' => $o->order_no, 'room' => $o->stay?->room?->room_no, 'detail' => $items->map(fn ($i) => $i->qty.'× '.$i->item_name)->implode(', '),
                'status' => ucfirst((string) $o->status), 'value' => round((float) $items->sum('amount'), 2) ?: null]);
        }
        foreach (\App\Models\GuestRequest::with('stay.room')->where('source', 'tv')->whereBetween('created_at', [$a, $b])->get() as $r) {
            $rows->push(['at' => $r->created_at, 'type' => $r->category === 'checkout' ? 'Express check-out' : 'Service request', 'ref' => $r->request_no, 'room' => $r->stay?->room?->room_no,
                'detail' => trim($r->categoryLabel().' · '.$r->item, ' ·'), 'status' => \App\Models\GuestRequest::STATUSES[$r->status] ?? $r->status, 'value' => null]);
        }
        foreach (\App\Models\WakeupCall::with('stay.room')->where('source', 'tv')->whereBetween('created_at', [$a, $b])->get() as $w) {
            $rows->push(['at' => $w->created_at, 'type' => 'Wake-up call', 'ref' => null, 'room' => $w->stay?->room?->room_no, 'detail' => $w->call_at->format('d-m-Y H:i').($w->daily ? ' · daily' : ''),
                'status' => $w->statusLabel(), 'value' => null]);
        }
        $rows = $rows->sortBy('at')->values()->map(fn ($r) => ['at' => $r['at']->format('d-m-Y H:i')] + $r);

        return ['cols' => ['at' => 'Placed', 'type' => 'Type', 'ref' => 'Ref', 'room' => 'Room', 'detail' => 'Detail', 'status' => 'Status', 'value' => 'Value'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'summary' => collect(['Dining order', 'Service request', 'Express check-out', 'Wake-up call'])->mapWithKeys(fn ($t) => [$t.'s' => (string) $rows->where('type', $t)->count()])->all()];
    }

    protected function r_iptv_vod($from, $to): array
    {
        $list = \App\Models\TvPurchase::with(['room', 'stay.guest', 'stay.reservation.guest', 'refunder'])->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('starts_at')->get();
        $rows = $list->map(function ($p) {
            $g = $p->stay?->guest ?? $p->stay?->reservation?->guest;

            return ['no' => $p->number, 'date' => $p->starts_at->format('d-m-Y H:i'), 'room' => $p->room?->room_no, 'guest' => $g ? trim($g->first_name.' '.$g->last_name) : null, 'title' => $p->title,
                'amount' => (float) $p->amount, 'tax' => (float) $p->tax ?: null, 'total' => (float) $p->total, 'status' => $p->state(),
                'refund' => $p->refund_reason ? $p->refund_reason.' ('.($p->refunder?->name ?? '').')' : null, '_class' => $p->status === 'refunded' ? 'muted' : ''];
        });
        $live = $list->where('status', '!=', 'refunded');

        return ['cols' => ['no' => 'Ref', 'date' => 'Started', 'room' => 'Room', 'guest' => 'Guest', 'title' => 'Movie', 'amount' => 'Before tax', 'tax' => 'VAT & levies', 'total' => 'Total', 'status' => 'Status', 'refund' => 'Refund'],
            'rows' => $rows, 'money' => ['amount', 'tax', 'total'], 'sum' => [],
            'summary' => ['Rentals' => (string) $live->count(), 'Revenue before tax' => money($live->sum('amount')), 'Charged incl. tax' => money($live->sum('total')),
                'Refunded' => $list->where('status', 'refunded')->count().' · '.money($list->where('status', 'refunded')->sum('total'))],
            'note' => 'Posted to room folios as “Pay TV / Movies” (GL 4047 In-room Entertainment Revenue). Refunded rentals are voided on the folio and excluded from the totals.'];
    }

    protected function r_tv_commands($from, $to): array
    {
        // Rewritten against the real schema: command/payload/status/result/sent_at; there is no command type table.
        $rows = TvCommand::with('device', 'issuer')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'device' => optional($r->device)->name, 'command' => $r->command,
            'sent' => $r->sent_at, 'status' => ucfirst((string) $r->status),
            'result' => $r->result, 'issued_by' => optional($r->issuer)->name,
        ]);
        return ['cols' => ['device' => 'Device', 'command' => 'Command', 'sent' => 'Sent', 'status' => 'Status', 'result' => 'Result', 'issued_by' => 'Issued By'], 'rows' => $rows, 'note' => 'IPTV command send log.'];
    }

    protected function r_tv_message_reads($from, $to): array
    {
        $rows = TvMessageRead::with('message.device.guest')->whereBetween('read_at', [$from, $to])->get()->map(fn ($r) => [
            'message' => optional($r->message)->subject, 'device' => optional($r->device)->device_name,
            'guest' => optional($r->guest)->name, 'read' => $r->read_at, 'status' => $r->status,
        ]);
        return ['cols' => ['message' => 'Message', 'device' => 'Device', 'guest' => 'Guest', 'read' => 'Read At', 'status' => 'Status'], 'rows' => $rows, 'note' => 'IPTV message read receipts.'];
    }

    protected function r_tv_movies($from, $to): array
    {
        // Rewritten against the real schema: genre/rating/duration_min/year/language/price/is_active; no lookup tables.
        $rows = TvMovie::orderBy('sort')->orderBy('title')->get()->map(fn ($r) => [
            'title' => $r->title, 'genre' => $r->genre, 'language' => $r->language,
            'duration' => $r->duration_min, 'rating' => $r->rating, 'year' => $r->year,
            'price' => (float) $r->price, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['title' => 'Title', 'genre' => 'Genre', 'language' => 'Language', 'duration' => 'Duration', 'rating' => 'Rating', 'year' => 'Year', 'price' => 'Price', 'active' => 'Active'], 'rows' => $rows, 'money' => ['price'], 'note' => 'IPTV movie catalog.'];
    }

    protected function r_tv_pages($from, $to): array
    {
        // Rewritten against the real schema: title/category/icon/language/body/sort/is_active; no lookup tables.
        $rows = TvPage::orderBy('sort')->orderBy('title')->get()->map(fn ($r) => [
            'title' => $r->title, 'category' => $r->category, 'language' => $r->language,
            'icon' => $r->icon, 'sort' => $r->sort, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['title' => 'Title', 'category' => 'Category', 'language' => 'Language', 'icon' => 'Icon', 'sort' => 'Sort', 'active' => 'Active'], 'rows' => $rows, 'note' => 'IPTV page configuration.'];
    }

    protected function r_tv_programmes($from, $to): array
    {
        $rows = TvProgramme::with('channel.programmeType')->whereBetween('air_date', [$from, $to])->get()->map(fn ($r) => [
            'name' => $r->name, 'channel' => optional($r->channel)->name,
            'type' => optional($r->programmeType)->name, 'air_date' => $r->air_date,
            'start' => $r->start_time, 'end' => $r->end_time, 'duration' => $r->duration_minutes,
            'status' => $r->status, 'description' => $r->description,
        ]);
        return ['cols' => ['name' => 'Name', 'channel' => 'Channel', 'type' => 'Type', 'air_date' => 'Air Date', 'start' => 'Start', 'end' => 'End', 'duration' => 'Duration', 'status' => 'Status'], 'rows' => $rows, 'note' => 'IPTV programme schedule.'];
    }

    protected function r_tv_sessions($from, $to): array
    {
        // Rewritten against the real schema: sessions link a device, stay and welcome template; there is no guest on the session.
        $rows = TvSession::with('device', 'stay.guest', 'template')->whereBetween('started_at', [$from, $to])->get()->map(fn ($r) => [
            'device' => optional($r->device)->name,
            'guest' => optional(optional($r->stay)->guest)->name,
            'template' => optional($r->template)->name,
            'started' => $r->started_at, 'welcomed' => $r->welcomed_at, 'ended' => $r->ended_at,
            'end_reason' => $r->end_reason,
        ]);
        return ['cols' => ['device' => 'Device', 'guest' => 'Guest', 'template' => 'Template', 'started' => 'Started', 'welcomed' => 'Welcomed', 'ended' => 'Ended', 'end_reason' => 'Ended Why'], 'rows' => $rows, 'note' => 'IPTV guest session log.'];
    }

    protected function r_tv_welcome_templates($from, $to): array
    {
        // Rewritten against the real schema: segment/priority/headline/body/theme/valid_from/valid_to; no lookup tables.
        $rows = TvWelcomeTemplate::orderByDesc('priority')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'language' => $r->language, 'segment' => $r->segment,
            'priority' => $r->priority, 'headline' => $r->headline,
            'valid_from' => $r->valid_from, 'valid_to' => $r->valid_to,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'language' => 'Language', 'segment' => 'Segment', 'priority' => 'Priority', 'headline' => 'Headline', 'valid_from' => 'Valid From', 'valid_to' => 'Valid To', 'active' => 'Active'], 'rows' => $rows, 'note' => 'IPTV welcome message templates.'];
    }

}
