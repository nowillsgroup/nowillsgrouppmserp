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
 * Guest Messaging reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait GuestMessagingReports
{
    protected function r_guest_messages($from, $to): array
    {
        $M = \App\Models\GuestMessage::class;
        $list = $M::with('stay.room', 'guest', 'taker', 'deliverer')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('id')->get();
        $rows = $list->map(fn ($m) => ['no' => $m->message_no, 'at' => $m->created_at->format('d M H:i'), 'type' => $m->isParcel() ? 'Parcel' : 'Message',
            'room' => (string) $m->stay?->room?->room_no, 'guest' => (string) $m->guest?->full_name, 'from' => $m->from_name.($m->from_company ? ', '.$m->from_company : ''),
            'channel' => $M::CHANNELS[$m->channel] ?? $m->channel, 'told' => $m->notified_at ? strtoupper((string) $m->notified_via).' '.$m->notified_at->format('H:i') : '',
            'mins' => $m->delivered_at && $m->status === 'delivered' ? (int) $m->created_at->diffInMinutes($m->delivered_at) : '', 'status' => $m->statusLabel(),
            'note' => (string) ($m->collected_by ? 'by '.$m->collected_by : ($m->delivery_note ?? $m->close_reason)), 'taken' => (string) $m->taker?->name,
            '_class' => $m->isOpen() || $m->status === 'cancelled' && $m->close_reason === 'Guest checked out before delivery' ? 'row-alert' : ''])->values();
        $del = $list->where('status', 'delivered');

        return ['cols' => ['no' => 'No.', 'at' => 'Received', 'type' => 'Type', 'room' => 'Room', 'guest' => 'Guest', 'from' => 'From', 'channel' => 'Via', 'told' => 'Guest told',
            'mins' => 'Minutes to deliver', 'status' => 'Status', 'note' => 'Delivery', 'taken' => 'Taken by'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Messages' => (string) $list->where('kind', 'message')->count(), 'Parcels' => (string) $list->where('kind', 'parcel')->count(), 'Delivered' => (string) $del->count(),
                'Median minutes to deliver' => $del->count() ? (string) $del->map(fn ($m) => $m->created_at->diffInMinutes($m->delivered_at))->median() : '—',
                'Lapsed at check-out' => (string) $list->where('close_reason', 'Guest checked out before delivery')->count(), 'Still waiting' => (string) $list->filter->isOpen()->count(),
                'Read on guest portal' => (string) $list->whereNotNull('portal_read_at')->count()],
            'note' => 'Rows in red are still waiting or lapsed because the guest left before the message reached them.'];
    }

    protected function r_inbox_agents($from, $to): array
    {
        $M = \App\Models\ConversationMessage::class;
        $IS = \App\Services\InboxService::class;
        $sla = $IS::cfg()['sla'] * 60;
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $out = $M::with('user')->where('direction', 'out')->where('auto', false)->whereNotNull('user_id')->whereNull('broadcast_id')->whereBetween('created_at', [$a, $b])->get();
        $notes = $M::where('direction', 'note')->whereBetween('created_at', [$a, $b])->get(['user_id'])->countBy('user_id');
        $closed = \App\Models\Conversation::whereBetween('closed_at', [$a, $b])->get(['closed_by', 'csat']);
        $rows = $out->groupBy('user_id')->map(function ($g, $uid) use ($IS, $sla, $notes, $closed) {
            $r = $g->whereNotNull('response_sec');
            $cl = $closed->where('closed_by', $uid);
            $rated = $cl->whereNotNull('csat');

            return ['who' => $g->first()->user?->name, 'conv' => $g->pluck('conversation_id')->unique()->count(), 'replies' => $g->count(), 'notes' => $notes[$uid] ?? null,
                'first' => $r->count() ?: null, 'avg' => $r->count() ? $IS::fmtSecs((int) round($r->avg('response_sec'))) : null,
                'sla' => $r->count() ? round($r->filter(fn ($m) => $m->response_sec <= $sla)->count() * 100 / $r->count()).'%' : null, 'closed' => $cl->count() ?: null,
                'csat' => $rated->count() ? round($rated->avg('csat'), 1) : null, '_class' => $r->count() && $r->avg('response_sec') > $sla ? 'row-amber' : ''];
        })->sortByDesc('replies')->values();

        return ['cols' => ['who' => 'Staff member', 'conv' => 'Conversations', 'replies' => 'Replies', 'notes' => 'Internal notes', 'first' => 'First responses', 'avg' => 'Avg response', 'sla' => 'Within target', 'closed' => 'Closed', 'csat' => 'Avg rating'],
            'rows' => $rows, 'money' => [], 'sum' => ['replies', 'notes', 'closed'],
            'summary' => ['Staff who replied' => (string) $rows->count(), 'Replies' => (string) $out->count(), 'Target' => ($sla / 60).' minutes']];
    }

    protected function r_inbox_broadcasts($from, $to): array
    {
        $rows = InboxBroadcast::with('user.channelType')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'title' => $r->title, 'sent' => $r->sent_at, 'channel' => optional($r->channelType)->name,
            'target' => $r->target_audience, 'recipients' => $r->recipient_count,
            'status' => $r->status, 'user' => optional($r->user)->name,
        ]);
        return ['cols' => ['title' => 'Title', 'sent' => 'Sent', 'channel' => 'Channel', 'target' => 'Target', 'recipients' => 'Recipients', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Inbox broadcast message log.'];
    }

    protected function r_inbox_conversations($from, $to): array
    {
        $C = \App\Models\Conversation::class;
        $IS = \App\Services\InboxService::class;
        $list = $C::with('guest', 'stay.room', 'assignee')->withCount(['messages as n_in' => fn ($q) => $q->where('direction', 'in'), 'messages as n_out' => fn ($q) => $q->where('direction', 'out')])
            ->whereBetween('opened_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('opened_at')->get();
        $first = \App\Models\ConversationMessage::whereIn('conversation_id', $list->pluck('id'))->whereNotNull('response_sec')->orderBy('id')->get(['conversation_id', 'response_sec'])->groupBy('conversation_id')->map(fn ($g) => $g->first()->response_sec);
        $sla = $IS::cfg()['sla'] * 60;
        $rows = $list->map(fn ($c) => ['no' => $c->number, 'at' => $c->opened_at?->format('d-m-Y H:i'), 'ch' => $c->channelLabel(), 'guest' => $c->displayName(), 'room' => $c->roomNo(),
            'dept' => $c->departmentLabel(), 'agent' => $c->assignee?->name, 'msgs' => $c->n_in.' / '.$c->n_out, 'first' => isset($first[$c->id]) ? $IS::fmtSecs($first[$c->id]) : null,
            'csat' => $c->csat ? str_repeat('★', $c->csat) : null, 'status' => $c->statusLabel(),
            '_class' => $c->isOverdue($IS::cfg()['sla']) ? 'row-red' : (isset($first[$c->id]) && $first[$c->id] > $sla ? 'row-amber' : '')]);
        $rated = $list->whereNotNull('csat');

        return ['cols' => ['no' => 'Conversation', 'at' => 'Started', 'ch' => 'Channel', 'guest' => 'Guest', 'room' => 'Room', 'dept' => 'Team', 'agent' => 'Assigned', 'msgs' => 'Guest / staff msgs', 'first' => 'First response', 'csat' => 'Rating', 'status' => 'Status'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Conversations' => (string) $list->count(), 'Still open' => (string) $list->whereIn('status', $C::ACTIVE)->count(),
                'Answered within target' => $first->count() ? round($first->filter(fn ($s) => $s <= $sla)->count() * 100 / $first->count()).'%' : '—',
                'Average rating' => $rated->count() ? round($rated->avg('csat'), 1).' / 5 ('.$rated->count().')' : '—']];
    }

    protected function r_inbox_quick_replies($from, $to): array
    {
        // Rewritten against the real schema: shortcut/title/body/department/is_active/uses/created_by; there is no user.topic relation.
        $rows = InboxQuickReply::with('creator')->orderBy('title')->get()->map(fn ($r) => [
            'title' => $r->title, 'shortcut' => $r->shortcut, 'department' => $r->department,
            'body' => $r->body, 'uses' => $r->uses, 'active' => $r->is_active ? 'Yes' : 'No',
            'created_by' => optional($r->creator)->name,
        ]);
        return ['cols' => ['title' => 'Title', 'shortcut' => 'Shortcut', 'department' => 'Department', 'body' => 'Body', 'uses' => 'Uses', 'active' => 'Active', 'created_by' => 'Created By'], 'rows' => $rows, 'note' => 'Inbox quick reply templates.'];
    }

    protected function r_inbox_response($from, $to): array
    {
        $M = \App\Models\ConversationMessage::class;
        $IS = \App\Services\InboxService::class;
        $sla = $IS::cfg()['sla'] * 60;
        $msgs = $M::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->whereIn('direction', ['in', 'out'])->get(['conversation_id', 'direction', 'auto', 'response_sec', 'created_at', 'broadcast_id']);
        $rows = collect();
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $day = $msgs->filter(fn ($m) => $m->created_at->isSameDay($d));
            $resp = $day->whereNotNull('response_sec');
            $rows->push(['day' => $d->format('d-m-Y D'), 'conv' => $day->pluck('conversation_id')->unique()->count() ?: null, 'in' => $day->where('direction', 'in')->count() ?: null,
                'out' => $day->where('direction', 'out')->where('auto', false)->whereNull('broadcast_id')->count() ?: null, 'auto' => $day->where('direction', 'out')->where('auto', true)->count() ?: null,
                'bc' => $day->whereNotNull('broadcast_id')->count() ?: null, 'avg' => $resp->count() ? $IS::fmtSecs((int) round($resp->avg('response_sec'))) : null,
                'max' => $resp->count() ? $IS::fmtSecs((int) $resp->max('response_sec')) : null,
                'sla' => $resp->count() ? round($resp->filter(fn ($m) => $m->response_sec <= $sla)->count() * 100 / $resp->count()).'%' : null]);
        }
        $all = $msgs->whereNotNull('response_sec');

        return ['cols' => ['day' => 'Day', 'conv' => 'Conversations', 'in' => 'Guest messages', 'out' => 'Staff replies', 'auto' => 'Automatic', 'bc' => 'Broadcast', 'avg' => 'Avg response', 'max' => 'Slowest', 'sla' => 'Within target'],
            'rows' => $rows, 'money' => [], 'sum' => ['in', 'out', 'auto', 'bc'],
            'summary' => ['Guest messages' => (string) $msgs->where('direction', 'in')->count(), 'Average response' => $all->count() ? $IS::fmtSecs((int) round($all->avg('response_sec'))) : '—',
                'Within the '.($sla / 60).'-minute target' => $all->count() ? round($all->filter(fn ($m) => $m->response_sec <= $sla)->count() * 100 / $all->count()).'%' : '—',
                'Answered automatically' => (string) $msgs->where('direction', 'out')->where('auto', true)->count()]];
    }

    protected function r_inbox_topics($from, $to): array
    {
        $C = \App\Models\Conversation::class;
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $conv = $C::whereBetween('opened_at', [$a, $b])->get(['id', 'department', 'channel', 'csat', 'status']);
        $ruleHits = \App\Models\ConversationMessage::with('rule')->whereNotNull('rule_id')->whereBetween('created_at', [$a, $b])->get(['rule_id'])->countBy('rule_id');
        $rows = collect();
        foreach ($C::DEPARTMENTS as $k => $l) {
            $g = $conv->where('department', $k);
            if ($g->isEmpty()) {
                continue;
            }
            $rated = $g->whereNotNull('csat');
            $rows->push(['kind' => 'Team', 'name' => $l, 'n' => $g->count(), 'share' => round($g->count() * 100 / max(1, $conv->count())).'%',
                'detail' => collect($C::CHANNELS)->map(fn ($cl, $ck) => $g->where('channel', $ck)->count() ? $cl.' '.$g->where('channel', $ck)->count() : null)->filter()->implode(' · '),
                'csat' => $rated->count() ? round($rated->avg('csat'), 1) : null]);
        }
        foreach (\App\Models\InboxRule::orderBy('sort')->get() as $r) {
            if (! ($n = $ruleHits[$r->id] ?? 0)) {
                continue;
            }
            $rows->push(['kind' => 'Automatic answer', 'name' => $r->name, 'n' => $n, 'share' => null, 'detail' => $r->actionsLabel(), 'csat' => null]);
        }

        return ['cols' => ['kind' => 'Type', 'name' => 'Team / rule', 'n' => 'Conversations', 'share' => 'Share', 'detail' => 'Detail', 'csat' => 'Avg rating'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Conversations' => (string) $conv->count(), 'Busiest team' => ($top = $conv->countBy('department')->sortDesc()->keys()->first()) ? ($C::DEPARTMENTS[$top] ?? $top) : '—',
                'Rule answers' => (string) $ruleHits->sum()]];
    }

}
