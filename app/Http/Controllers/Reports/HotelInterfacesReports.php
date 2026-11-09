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
 * Hotel Interfaces reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait HotelInterfacesReports
{
    protected function r_admin_call_usage($from, $to): array
    {
        $rows = \App\Models\CallRecord::with('phoneExtension')->where('status', 'admin')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()
            ->groupBy('extension')->map(fn ($g, $ext) => ['dept' => $g->first()->phoneExtension?->department ?: (\App\Models\PhoneExtension::TYPES[$g->first()->phoneExtension?->type ?? ''] ?? '—'),
                'ext' => (string) $ext, 'label' => $g->first()->phoneExtension?->label, 'calls' => $g->count(), 'minutes' => (int) round($g->sum('duration_secs') / 60),
                'isd' => $g->where('call_class', 'isd')->count(), 'cost' => round((float) $g->sum('cost'), 2)])
            ->sortBy(fn ($r) => $r['dept'].'-'.str_pad($r['ext'], 10, '0', STR_PAD_LEFT))->values();

        return ['cols' => ['dept' => 'Department', 'ext' => 'Extension', 'label' => 'Label', 'calls' => 'Calls', 'minutes' => 'Minutes', 'isd' => 'International', 'cost' => 'Carrier cost'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['calls', 'minutes', 'isd', 'cost'],
            'summary' => ['Administrative call cost' => money($rows->sum('cost')), 'Departments' => (string) $rows->pluck('dept')->unique()->count()]];
    }

    protected function r_api_usage($from, $to): array
    {
        $q = \App\Models\ApiRequestLog::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $names = \App\Models\ApiClient::pluck('name', 'id');
        $rows = (clone $q)->whereNotNull('api_client_id')->groupBy('api_client_id', 'route')
            ->selectRaw('api_client_id, route, count(*) n, sum(status < 400) ok, sum(status between 400 and 499) c4, sum(status >= 500) c5, round(avg(duration_ms)) ms, max(created_at) last_at')
            ->get()->map(fn ($r) => ['client' => $names[$r->api_client_id] ?? '#'.$r->api_client_id, 'route' => $r->route ?? '(unmatched path)', 'calls' => (int) $r->n, 'ok' => (int) $r->ok,
                'c4' => (int) $r->c4, 'c5' => (int) $r->c5, 'rate' => $r->n ? round(($r->c4 + $r->c5) * 100 / $r->n, 1) : 0, 'ms' => (int) $r->ms,
                'last' => \Illuminate\Support\Carbon::parse($r->last_at)->format('d-m-Y H:i')])
            ->sortBy([['client', 'asc'], ['calls', 'desc']])->values();
        $refused = \App\Models\ApiRequestLog::withoutGlobalScope('hotel')->whereNull('hotel_id')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();
        $top = $rows->groupBy('client')->map->sum('calls')->sortDesc();

        return ['cols' => ['client' => 'Client', 'route' => 'Call', 'calls' => 'Requests', 'ok' => 'Successful', 'c4' => '4xx', 'c5' => '5xx', 'rate' => 'Error %', 'ms' => 'Avg ms', 'last' => 'Last call'],
            'rows' => $rows, 'sum' => ['calls', 'ok', 'c4', 'c5'],
            'summary' => ['Requests' => number_format($rows->sum('calls')), 'Errors' => number_format($rows->sum('c4') + $rows->sum('c5')), 'Clients calling' => (string) $top->count(),
                'Busiest client' => $top->keys()->first() ?? '–', 'Refused (no valid key, all hotels)' => (string) $refused],
            'chart' => ['label' => 'Requests', 'key' => 'calls', 'x' => 'route'],
            'note' => 'From the API request log (kept for '.\App\Services\ApiService::cfg()['log_days'].' days – see Administration › API & Webhooks › Settings). 4xx are caller mistakes (validation, missing scope, rate limit); 5xx need investigating – quote the request id.'];
    }

    protected function r_call_log($from, $to): array
    {
        $rows = \App\Models\CallRecord::with('room', 'stay.guest', 'stay.reservation.guest')->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('started_at')->get()
            ->map(fn ($c) => ['at' => $c->started_at->format('d-m-Y H:i'), 'ext' => $c->extension, 'room' => $c->room?->room_no, 'guest' => ($c->stay?->guest ?? $c->stay?->reservation?->guest)?->full_name,
                'number' => $c->maskedNumber(), 'class' => \App\Models\CallTariff::CLASSES[$c->call_class] ?? ucfirst((string) $c->call_class), 'duration' => $c->durationLabel(), 'secs' => $c->duration_secs,
                'cost' => (float) $c->cost, 'charge' => (float) $c->charge, 'status' => $c->statusLabel(), 'note' => $c->note]);

        return ['cols' => ['at' => 'Time', 'ext' => 'Ext', 'room' => 'Room', 'guest' => 'Guest', 'number' => 'Number', 'class' => 'Class', 'duration' => 'Duration', 'cost' => 'Carrier cost', 'charge' => 'Charge', 'status' => 'Status', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['cost', 'charge'], 'sum' => ['cost', 'charge'],
            'summary' => ['Calls' => (string) $rows->count(), 'Minutes' => (string) round($rows->sum('secs') / 60), 'Posted to folios' => money($rows->where('status', 'Posted')->sum('charge')), 'Awaiting review' => (string) $rows->where('status', 'Review')->count()],
            'note' => 'Numbers are masked (last digits hidden) as on the guest bill. Charge is shown for every rated call; only “Posted” calls are billed.'];
    }

    protected function r_call_revenue($from, $to): array
    {
        $calls = \App\Models\CallRecord::whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->whereNotIn('status', ['admin'])->get();
        $rows = $calls->groupBy('call_class')->map(function ($g, $cls) {
            $posted = $g->where('status', 'posted');

            return ['class' => \App\Models\CallTariff::CLASSES[$cls] ?? ucfirst((string) ($cls ?: 'internal')), 'calls' => $g->count(), 'posted' => $posted->count(), 'minutes' => (int) round($g->sum('duration_secs') / 60),
                'cost' => round((float) $posted->sum('cost'), 2), 'charged' => round((float) $posted->sum('charge'), 2), 'margin' => round((float) $posted->sum('charge') - (float) $posted->sum('cost'), 2),
                'unbilled' => round((float) $g->whereIn('status', ['pending', 'waived'])->sum('charge'), 2)];
        })->sortByDesc('charged')->values();

        return ['cols' => ['class' => 'Class', 'calls' => 'Calls', 'posted' => 'Billed', 'minutes' => 'Minutes', 'cost' => 'Carrier cost (billed)', 'charged' => 'Charged', 'margin' => 'Margin', 'unbilled' => 'Unbilled (review / waived)'],
            'rows' => $rows, 'money' => ['cost', 'charged', 'margin', 'unbilled'], 'sum' => ['calls', 'posted', 'minutes', 'cost', 'charged', 'margin', 'unbilled'],
            'summary' => ['Telephone revenue' => money($rows->sum('charged')), 'Margin' => money($rows->sum('margin')), 'Unbilled' => money($rows->sum('unbilled'))],
            'chart' => ['label' => 'Charged', 'key' => 'charged', 'x' => 'class']];
    }

    protected function r_interface_commands($from, $to): array
    {
        $rows = InterfaceCommand::with('interface.commandType')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'interface' => optional($r->interface)->name, 'type' => optional($r->commandType)->name,
            'sent' => $r->sent_at, 'command' => $r->command_payload, 'status' => $r->status,
            'response' => $r->response_payload, 'user' => $r->user_id, 'notes' => $r->notes,
        ]);
        return ['cols' => ['interface' => 'Interface', 'type' => 'Type', 'sent' => 'Sent', 'command' => 'Command', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Hotel interface command log.'];
    }

    protected function r_key_card_audit($from, $to): array
    {
        $rows = \App\Models\KeyCard::with('room', 'issuer', 'fee')->whereDate('valid_from', '>=', $from->copy()->startOfDay())->whereDate('valid_from', '<=', $to->copy()->endOfDay())->orderBy('valid_from')->get()
            ->map(fn ($k) => ['card' => $k->card_no, 'room' => $k->room?->room_no, 'holder' => $k->holder, 'type' => \App\Models\KeyCard::TYPES[$k->type] ?? $k->type, 'serial' => $k->serial,
                'issued' => $k->valid_from->format('d-m-Y H:i'), 'until' => $k->valid_until->format('d-m-Y H:i'), 'status' => $k->statusLabel(), 'note' => $k->status_note,
                'by' => $k->issuer?->name, 'fee' => $k->fee && ! $k->fee->is_void ? (float) $k->fee->amount : null]);

        return ['cols' => ['card' => 'Card', 'room' => 'Room', 'holder' => 'Holder', 'type' => 'Type', 'serial' => 'Serial', 'issued' => 'Encoded', 'until' => 'Valid until', 'status' => 'Status', 'note' => 'Note', 'by' => 'Issued by', 'fee' => 'Lost fee'],
            'rows' => $rows, 'money' => ['fee'], 'sum' => ['fee'],
            'summary' => ['Cards encoded' => (string) $rows->count(), 'Duplicates' => (string) $rows->where('type', 'Duplicate')->count(), 'Lost' => (string) $rows->where('status', 'Lost')->count(), 'Lost-card fees' => money($rows->sum('fee'))]];
    }

    protected function r_master_key_register($from, $to): array
    {
        $rows = \App\Models\KeyLoan::with('key', 'issuer', 'receiver')->whereBetween('out_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('out_at')->get()
            ->map(fn ($l) => ['key' => $l->key?->key_no, 'name' => $l->key?->name, 'level' => \App\Models\MasterKey::LEVELS[$l->key?->level ?? ''] ?? '', 'holder' => $l->holder, 'purpose' => $l->purpose,
                'out' => $l->out_at->format('d-m-Y H:i'), 'due' => $l->due_at->format('d-m-Y H:i'), 'in' => $l->in_at?->format('d-m-Y H:i'),
                'status' => $l->status === 'out' ? ($l->isOverdue() ? 'OVERDUE' : 'Out') : ($l->status === 'lost' ? 'LOST' : ($l->in_at && $l->in_at->gt($l->due_at) ? 'Returned late' : 'Returned')),
                'by' => $l->issuer?->name, 'received' => $l->receiver?->name, 'note' => $l->note]);

        return ['cols' => ['key' => 'Key', 'name' => 'Name', 'level' => 'Level', 'holder' => 'Holder', 'purpose' => 'Purpose', 'out' => 'Out', 'due' => 'Due', 'in' => 'Returned', 'status' => 'Status', 'by' => 'Issued by', 'received' => 'Received by', 'note' => 'Note'],
            'rows' => $rows, 'summary' => ['Sign-outs' => (string) $rows->count(), 'Still out' => (string) $rows->whereIn('status', ['Out', 'OVERDUE'])->count(), 'Overdue' => (string) $rows->where('status', 'OVERDUE')->count(), 'Lost' => (string) $rows->where('status', 'LOST')->count()]];
    }

    protected function r_webhook_deliveries($from, $to): array
    {
        $names = \App\Models\WebhookEndpoint::pluck('name', 'id');
        $rows = \App\Models\WebhookDelivery::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get()
            ->groupBy(fn ($d) => $d->webhook_endpoint_id.'|'.$d->event)->map(function ($g) use ($names) {
                $done = $g->where('status', 'success');

                return ['endpoint' => $names[$g->first()->webhook_endpoint_id] ?? '(deleted)', 'event' => $g->first()->event, 'queued' => $g->count(), 'delivered' => $done->count(),
                    'pending' => $g->where('status', 'pending')->count(), 'failed' => $g->where('status', 'failed')->count(),
                    'first' => $g->count() ? round($done->where('attempts', 1)->count() * 100 / $g->count(), 1) : 0,
                    'attempts' => round($g->avg('attempts'), 2), 'ms' => (int) round($done->avg('duration_ms') ?? 0)];
            })->sortBy([['endpoint', 'asc'], ['queued', 'desc']])->values();
        $q = $rows->sum('queued');

        return ['cols' => ['endpoint' => 'Endpoint', 'event' => 'Event', 'queued' => 'Queued', 'delivered' => 'Delivered', 'pending' => 'Retrying', 'failed' => 'Gave up',
            'first' => '1st-try %', 'attempts' => 'Avg attempts', 'ms' => 'Avg ms'],
            'rows' => $rows, 'sum' => ['queued', 'delivered', 'pending', 'failed'],
            'summary' => ['Events queued' => (string) $q, 'Delivered' => $q ? round($rows->sum('delivered') * 100 / $q, 1).'%' : '–', 'Still retrying' => (string) $rows->sum('pending'),
                'Gave up' => (string) $rows->sum('failed')],
            'note' => 'Deliveries created in the period. A delivery is retried up to '.\App\Services\WebhookService::MAX_ATTEMPTS.' times with growing back-off (1 min → 24 h) before it gives up; an endpoint is switched off after '.\App\Services\ApiService::cfg()['disable_after'].' failures in a row.'];
    }

}
