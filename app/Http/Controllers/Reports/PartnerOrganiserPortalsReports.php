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
 * Partner & Organiser Portals reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait PartnerOrganiserPortalsReports
{
    protected function r_organiser_access($from, $to): array
    {
        $list = \App\Models\OrganiserAudit::with('company', 'organiserUser')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($x) => ['at' => $x->created_at->format('d-m-Y H:i:s'), 'org' => $x->company?->name ?? '—', 'user' => $x->organiserUser ? $x->organiserUser->name.' <'.$x->organiserUser->email.'>' : 'unknown',
            'action' => str_replace('_', ' ', $x->action), 'detail' => $x->detail, 'ip' => $x->ip,
            '_class' => in_array($x->action, ['locked', 'login_blocked']) ? 'row-red' : ($x->action === 'login_failed' ? 'row-amber' : '')]);

        return ['cols' => ['at' => 'When', 'org' => 'Organiser', 'user' => 'Login', 'action' => 'Event', 'detail' => 'Detail', 'ip' => 'IP address'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Sign-ins' => (string) $list->where('action', 'login')->count(), 'Failed passwords' => (string) $list->where('action', 'login_failed')->count(),
                'Lock-outs' => (string) $list->where('action', 'locked')->count(), 'Distinct IPs' => (string) $list->pluck('ip')->filter()->unique()->count()]];
    }

    protected function r_organiser_events($from, $to): array
    {
        $OS = \App\Services\OrganiserService::class;
        $list = \App\Models\BanquetEvent::with('company', 'functions')->where('portal_enabled', true)->get()
            ->sortBy(fn ($e) => [$e->functions->min('date')?->toDateString() ?? '9999', $e->event_no])->values();
        $online = \App\Models\OnlinePayment::where('purpose', 'banquet')->where('status', 'paid')->whereIn('banquet_event_id', $list->pluck('id'))
            ->selectRaw('banquet_event_id, sum(amount_received) s')->groupBy('banquet_event_id')->pluck('s', 'banquet_event_id');
        $rows = $list->map(function ($e) use ($OS, $online) {
            $m = $OS::money($e);

            return ['no' => $e->event_no, 'org' => $e->company?->name ?? \App\Models\OrganiserUser::where('banquet_event_id', $e->id)->value('name'),
                'title' => $e->title, 'n' => $e->functions->count(), 'dates' => ($e->functions->min('date')?->format('d-m-Y') ?? '—').($e->functions->count() > 1 ? ' → '.($e->functions->max('date')?->format('d-m-Y') ?? '') : ''),
                'status' => ucfirst($e->status), 'logins' => \App\Models\OrganiserUser::where('is_active', true)->whereNotNull('password')
                    ->where(fn ($q) => $q->when($e->company_id, fn ($w) => $w->orWhere('company_id', $e->company_id))->orWhere('banquet_event_id', $e->id))->count(),
                'beo' => $e->beo_approved_at ? 'Signed' : ($e->beo_published_at ? 'Published' : 'Draft'),
                'paid' => $m['deposits'], 'online' => round((float) ($online[$e->id] ?? 0), 2), 'bal' => $m['balance'],
                '_class' => $e->beo_published_at && ! $e->beo_approved_at ? 'row-amber' : ''];
        });

        return ['cols' => ['no' => 'Event', 'org' => 'Organiser', 'title' => 'Title', 'n' => 'Functions', 'dates' => 'Dates', 'status' => 'Status', 'logins' => 'Active logins', 'beo' => 'BEO', 'paid' => 'Deposits', 'online' => 'Paid online', 'bal' => 'Balance'],
            'rows' => $rows, 'money' => ['paid', 'online', 'bal'], 'sum' => ['n', 'logins', 'paid', 'online', 'bal'],
            'summary' => ['Events on the portal' => (string) $list->count(), 'BEO signed' => (string) $list->whereNotNull('beo_approved_at')->count(),
                'Awaiting signature' => (string) $list->whereNotNull('beo_published_at')->whereNull('beo_approved_at')->count(),
                'Paid online' => money($rows->sum('online')), 'Still owed' => money($rows->sum('bal'))],
            'note' => 'Current portal book – every event shared with an organiser, whatever the date range.'];
    }

    protected function r_organiser_payments($from, $to): array
    {
        $list = \App\Models\OnlinePayment::with('event')->where('purpose', 'banquet')->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('paid_at')->get();
        $rows = $list->map(fn ($p) => ['at' => $p->paid_at->format('d-m-Y H:i'), 'event' => $p->event?->event_no, 'title' => \Illuminate\Support\Str::limit((string) $p->event?->title, 40),
            'payer' => $p->payer_name, 'channel' => str_replace('_', ' ', (string) $p->channel), 'amount' => (float) $p->received(), 'ref' => $p->provider_payment_id,
            'status' => ucfirst($p->status !== 'paid' ? $p->status : ($p->refunded_amount > 0 ? 'refunded' : 'paid')),
            '_class' => $p->status === 'refunded' || $p->status === 'disputed' ? 'row-red' : '']);

        return ['cols' => ['at' => 'Paid', 'event' => 'Event', 'title' => 'Title', 'payer' => 'Paid by', 'channel' => 'Channel', 'amount' => 'Amount', 'ref' => 'Gateway reference', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => ['Online payments' => (string) $list->count(), 'Collected' => money($list->sum(fn ($p) => $p->received())),
                'MoMo' => money($list->where('channel', 'mobile_money')->sum(fn ($p) => $p->received())), 'Card' => money($list->where('channel', 'card')->sum(fn ($p) => $p->received()))]];
    }

    protected function r_organiser_requests($from, $to): array
    {
        $list = \App\Models\OrganiserRequest::with('company', 'organiserUser', 'event', 'decider')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($q) => ['no' => $q->number, 'at' => $q->created_at->format('d-m-Y H:i'), 'type' => \App\Models\OrganiserRequest::TYPES[$q->type],
            'org' => $q->company?->name ?? $q->organiserUser?->name, 'by' => $q->organiserUser?->name, 'event' => $q->event?->event_no,
            'what' => \Illuminate\Support\Str::limit($q->details ?: ($q->payload['title'] ?? ''), 110), 'status' => \App\Models\OrganiserRequest::STATUSES[$q->status],
            'decider' => $q->decider?->name, 'note' => \Illuminate\Support\Str::limit((string) $q->decision_note, 90), 'hours' => $q->responseHours(),
            '_class' => $q->status === 'pending' && $q->responseHours() > 24 ? 'row-red' : ($q->status === 'pending' ? 'row-amber' : '')]);
        $answered = $list->whereNotIn('status', ['pending', 'withdrawn']);

        return ['cols' => ['no' => 'Request', 'at' => 'Received', 'type' => 'Type', 'org' => 'Organiser', 'by' => 'From', 'event' => 'Event', 'what' => 'Request', 'status' => 'Status', 'decider' => 'Answered by', 'note' => 'Answer', 'hours' => 'Hours'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Requests' => (string) $list->count(), 'Still open' => (string) $list->where('status', 'pending')->count(),
                'Average answer time' => $answered->count() ? round($answered->avg(fn ($q) => $q->responseHours()), 1).' h' : '—']];
    }

    protected function r_partner_access($from, $to): array
    {
        $list = \App\Models\PartnerAudit::with('company', 'partnerUser')->whereIn('action', ['login', 'login_failed', 'login_blocked', 'locked', 'unlocked', 'logout', 'timeout', 'password', 'reset_requested', 'user'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($x) => ['at' => $x->created_at->format('d-m-Y H:i:s'), 'partner' => $x->company?->name ?? '—', 'user' => $x->partnerUser ? $x->partnerUser->name.' <'.$x->partnerUser->email.'>' : 'unknown',
            'action' => str_replace('_', ' ', $x->action), 'detail' => $x->detail, 'ip' => $x->ip,
            '_class' => in_array($x->action, ['locked', 'login_blocked']) ? 'row-red' : ($x->action === 'login_failed' ? 'row-amber' : '')]);

        return ['cols' => ['at' => 'When', 'partner' => 'Partner', 'user' => 'Login', 'action' => 'Event', 'detail' => 'Detail', 'ip' => 'IP address'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Sign-ins' => (string) $list->where('action', 'login')->count(), 'Failed passwords' => (string) $list->where('action', 'login_failed')->count(),
                'Lock-outs' => (string) $list->where('action', 'locked')->count(), 'Distinct IPs' => (string) $list->pluck('ip')->filter()->unique()->count()]];
    }

    protected function r_partner_bookings($from, $to): array
    {
        $PS = \App\Services\PartnerService::class;
        $list = \App\Models\Reservation::with('company', 'partnerUser', 'guest', 'rooms.roomType')->whereNotNull('partner_user_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(function ($r) use ($PS) {
            $live = $r->rooms->where('status', '!=', 'cancelled');
            $set = $live->isEmpty() ? $r->rooms : $live;

            return ['no' => $r->reservation_no, 'at' => $r->created_at->format('d-m-Y H:i'), 'partner' => $r->company?->name, 'booker' => $r->partnerUser?->name, 'guest' => $r->guest?->full_name,
                'dates' => $r->arrival_date->format('d M').' – '.$r->departure_date->format('d M Y'), 'rooms' => $set->count(), 'type' => $set->first()?->roomType?->name,
                'nights' => $r->status === 'cancelled' ? 0 : $live->sum(fn ($x) => $x->nights), 'value' => $r->status === 'cancelled' ? 0 : round($live->sum(fn ($x) => $PS::stayValue($x)), 2),
                'bill' => $r->billing_instruction === 'company' ? 'Company' : 'Traveller', 'ref' => $r->partner_ref, 'status' => ['tentative' => 'Held for approval'][$r->status] ?? ucfirst(str_replace('_', ' ', $r->status)),
                '_class' => $r->status === 'tentative' ? 'row-amber' : ($r->status === 'cancelled' ? 'row-red' : '')];
        });
        $live = $list->where('status', '!=', 'cancelled');

        return ['cols' => ['no' => 'Booking', 'at' => 'Booked', 'partner' => 'Partner', 'booker' => 'Booker', 'guest' => 'Traveller', 'dates' => 'Stay', 'rooms' => 'Rooms', 'type' => 'Room type', 'nights' => 'Room nights', 'value' => 'Stay value', 'bill' => 'Bill to', 'ref' => 'Reference', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rooms', 'nights', 'value'],
            'summary' => ['Portal bookings' => (string) $list->count(), 'Held for approval' => (string) $list->where('status', 'tentative')->count(), 'Cancelled' => (string) $list->where('status', 'cancelled')->count(),
                'Billed to company' => $live->count() ? round($live->where('billing_instruction', 'company')->count() * 100 / $live->count()).'%' : '—']];
    }

    protected function r_partner_production($from, $to): array
    {
        $PS = \App\Services\PartnerService::class;
        $a = $from->copy()->startOfDay();
        $b = $to->copy()->endOfDay();
        $companies = \App\Models\Company::whereIn('type', ['corporate', 'travel_agent'])->where(fn ($q) => $q->where('portal_enabled', true)->orWhereHas('partnerUsers'))->orderBy('name')->get();
        $rows = $companies->map(function ($c) use ($a, $b, $PS) {
            $all = \App\Models\Reservation::with('rooms')->where('company_id', $c->id)->whereBetween('created_at', [$a, $b])->get();
            $portal = $all->whereNotNull('partner_user_id');
            $live = $portal->where('status', '!=', 'cancelled');

            return ['partner' => $c->name, 'type' => $c->type === 'travel_agent' ? 'Travel agent' : 'Corporate', 'portal' => $c->portal_enabled ? 'On' : 'Off',
                'users' => \App\Models\PartnerUser::where('company_id', $c->id)->where('is_active', true)->whereNotNull('password')->count(),
                'logins' => \App\Models\PartnerAudit::where('company_id', $c->id)->where('action', 'login')->whereBetween('created_at', [$a, $b])->count(),
                'bookings' => $portal->count(), 'nights' => $live->sum(fn ($r) => $r->rooms->where('status', '!=', 'cancelled')->sum(fn ($x) => $x->nights)),
                'value' => round($live->sum(fn ($r) => $r->rooms->where('status', '!=', 'cancelled')->sum(fn ($x) => $PS::stayValue($x))), 2),
                'cancelled' => $portal->where('status', 'cancelled')->count(), 'cxl' => $portal->count() ? round($portal->where('status', 'cancelled')->count() * 100 / $portal->count()).'%' : '—',
                'held' => $portal->where('status', 'tentative')->count(), 'online' => $all->count() ? round($portal->count() * 100 / $all->count()).'%' : '—'];
        })->filter(fn ($r) => $r['portal'] === 'On' || $r['bookings'] > 0)->sortByDesc('value')->values();

        return ['cols' => ['partner' => 'Partner', 'type' => 'Type', 'portal' => 'Portal', 'users' => 'Active logins', 'logins' => 'Sign-ins', 'bookings' => 'Portal bookings', 'nights' => 'Room nights', 'value' => 'Stay value', 'cancelled' => 'Cancelled', 'cxl' => 'Cancel %', 'held' => 'Held', 'online' => 'Booked online %'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['users', 'logins', 'bookings', 'nights', 'value', 'cancelled', 'held'],
            'summary' => ['Partners on the portal' => (string) $companies->where('portal_enabled', true)->count(), 'Portal bookings' => (string) $rows->sum('bookings'), 'Stay value' => money($rows->sum('value'))]];
    }

    protected function r_partner_requests($from, $to): array
    {
        $list = \App\Models\PartnerRequest::with('company', 'partnerUser', 'reservation', 'decider')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('created_at')->get();
        $rows = $list->map(fn ($q) => ['no' => $q->number, 'at' => $q->created_at->format('d-m-Y H:i'), 'type' => \App\Models\PartnerRequest::TYPES[$q->type], 'partner' => $q->company?->name, 'by' => $q->partnerUser?->name,
            'booking' => $q->reservation?->reservation_no, 'what' => \Illuminate\Support\Str::limit($q->reason ?: $q->details, 110), 'status' => \App\Models\PartnerRequest::STATUSES[$q->status],
            'decider' => $q->decider?->name, 'note' => \Illuminate\Support\Str::limit((string) $q->decision_note, 90), 'hours' => $q->responseHours(),
            '_class' => $q->status === 'pending' && $q->responseHours() > 24 ? 'row-red' : ($q->status === 'pending' ? 'row-amber' : '')]);
        $answered = $list->whereNotIn('status', ['pending', 'withdrawn']);

        return ['cols' => ['no' => 'Request', 'at' => 'Received', 'type' => 'Type', 'partner' => 'Partner', 'by' => 'From', 'booking' => 'Booking', 'what' => 'Reason / request', 'status' => 'Status', 'decider' => 'Answered by', 'note' => 'Answer', 'hours' => 'Hours'],
            'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Requests' => (string) $list->count(), 'Still open' => (string) $list->where('status', 'pending')->count(),
                'Approvals approved' => $list->where('type', 'approval')->whereIn('status', ['approved', 'declined'])->count() ? round($list->where('type', 'approval')->where('status', 'approved')->count() * 100 / $list->where('type', 'approval')->whereIn('status', ['approved', 'declined'])->count()).'%' : '—',
                'Average answer time' => $answered->count() ? round($answered->avg(fn ($q) => $q->responseHours()), 1).' h' : '—']];
    }

}
