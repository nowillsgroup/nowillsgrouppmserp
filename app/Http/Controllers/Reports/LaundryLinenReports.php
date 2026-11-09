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
 * Laundry & Linen reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait LaundryLinenReports
{
    protected function r_laundry_items($from, $to): array
    {
        // Rewritten against the real schema: category + wash_rate/dryclean_rate/press_rate; there is no laundry type table.
        $rows = LaundryItem::orderBy('sort_order')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'category' => $r->category,
            'wash_rate' => (float) $r->wash_rate, 'dryclean_rate' => (float) $r->dryclean_rate,
            'press_rate' => (float) $r->press_rate, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'category' => 'Category', 'wash_rate' => 'Wash', 'dryclean_rate' => 'Dry Clean', 'press_rate' => 'Press', 'active' => 'Active'], 'rows' => $rows, 'money' => ['wash_rate', 'dryclean_rate', 'press_rate'], 'note' => 'Laundry item price list.'];
    }

    protected function r_laundry_order_lines($from, $to): array
    {
        // Rewritten against the real schema: order()/item() relations; item_name/service/qty/rate/amount/remark; the order carries collected_at and guest_name.
        $rows = LaundryOrderLine::with('order', 'item')->whereHas('order', fn ($q) => $q->whereBetween('collected_at', [$from, $to]))->get()->map(fn ($r) => [
            'order_no' => optional($r->order)->order_no,
            'item' => optional($r->item)->name ?? $r->item_name,
            'guest' => optional($r->order)->guest_name,
            'service' => $r->service, 'quantity' => (float) $r->qty,
            'rate' => (float) $r->rate, 'amount' => (float) $r->amount,
            'status' => optional($r->order)->status,
        ]);
        return ['cols' => ['order_no' => 'Order No', 'item' => 'Item', 'guest' => 'Guest', 'service' => 'Service', 'quantity' => 'Qty', 'rate' => 'Rate', 'amount' => 'Amount', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate', 'amount'], 'sum' => ['amount'], 'note' => 'Laundry order line items.'];
    }

    protected function r_laundry_register($from, $to): array
    {
        $rows = \App\Models\LaundryOrder::with('room', 'folio', 'paymentMode')
            ->whereBetween('collected_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('collected_at')->orderBy('id')->get()
            ->map(fn ($o) => ['no' => $o->order_no, 'collected' => $o->collected_at?->format('d-m-Y H:i'), 'type' => $o->type === 'walk_in' ? 'Walk-in' : 'Guest',
                'room' => $o->room?->room_no, 'guest' => $o->guest_name, 'speed' => \App\Services\LaundryService::SPEEDS[$o->speed] ?? $o->speed, 'pieces' => (int) $o->pieces,
                'subtotal' => (float) $o->subtotal, 'express' => (float) $o->express_charge, 'total' => $o->status === 'cancelled' ? 0.0 : (float) $o->total,
                'status' => $o->statusLabel().($o->isOverdue() ? ' (overdue)' : ''), 'delivered' => $o->delivered_at?->format('d-m-Y H:i'),
                'charged' => $o->folio ? ($o->type === 'walk_in' ? 'Invoice '.$o->folio->invoice_no.($o->paymentMode ? ' – '.$o->paymentMode->name : '') : 'Folio '.$o->folio->folio_no) : ''])->values();

        return ['cols' => ['no' => 'Order', 'collected' => 'Collected', 'type' => 'Type', 'room' => 'Room', 'guest' => 'Guest / customer', 'speed' => 'Service', 'pieces' => 'Pieces',
            'subtotal' => 'Items', 'express' => 'Express', 'total' => 'Amount', 'status' => 'Status', 'delivered' => 'Delivered', 'charged' => 'Charged to'],
            'rows' => $rows, 'money' => ['subtotal', 'express', 'total'], 'sum' => ['pieces', 'subtotal', 'express', 'total']];
    }

    protected function r_linen_batch_lines($from, $to): array
    {
        // Rewritten against the real schema: batch()/linen() relations; qty_sent/qty_returned/qty_damaged/qty_lost/rate; the batch carries sent_date/expected_date/closed_date/status.
        $rows = LinenBatchLine::with('batch', 'linen')->whereHas('batch', fn ($q) => $q->whereBetween('sent_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'batch_no' => optional($r->batch)->batch_no, 'type' => optional($r->linen)->name,
            'sent_date' => optional($r->batch)->sent_date,
            'qty_sent' => $r->qty_sent, 'qty_returned' => $r->qty_returned,
            'qty_damaged' => $r->qty_damaged, 'qty_lost' => $r->qty_lost,
            'rate' => (float) $r->rate, 'status' => optional($r->batch)->status,
        ]);
        return ['cols' => ['batch_no' => 'Batch No', 'type' => 'Linen Type', 'sent_date' => 'Sent', 'qty_sent' => 'Sent', 'qty_returned' => 'Returned', 'qty_damaged' => 'Damaged', 'qty_lost' => 'Lost', 'rate' => 'Rate', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate'], 'note' => 'Linen batch line items.'];
    }

    protected function r_linen_batches($from, $to): array
    {
        $rows = LinenBatch::with('store.linenType')->whereBetween('washed_at', [$from, $to])->get()->map(fn ($r) => [
            'batch_no' => $r->batch_no, 'store' => optional($r->store)->name,
            'type' => optional($r->linenType)->name, 'washed' => $r->washed_at,
            'total_qty' => $r->total_quantity, 'damaged' => $r->damaged_count, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['batch_no' => 'Batch No', 'store' => 'Store', 'type' => 'Type', 'washed' => 'Washed', 'total_qty' => 'Total Qty', 'damaged' => 'Damaged', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Linen batch wash summaries.'];
    }

    protected function r_linen_losses($from, $to): array
    {
        $rows = \App\Models\LinenMovement::with('linen', 'batch.supplier', 'user')->whereIn('type', ['discard', 'lost'])
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->orderBy('id')->get()
            ->map(fn ($m) => ['date' => $m->date->format('d-m-Y'), 'code' => $m->linen?->code, 'name' => $m->linen?->name, 'type' => \App\Models\LinenMovement::TYPES[$m->type],
                'where' => \App\Models\LinenType::LOCATIONS[$m->from_loc] ?? $m->from_loc, 'batch' => $m->batch ? $m->batch->batch_no.' – '.($m->batch->supplier?->name ?? 'in-house') : '',
                'qty' => (int) $m->qty, 'value' => (float) $m->value, 'reason' => $m->reason, 'by' => $m->user?->name])->values();

        return ['cols' => ['date' => 'Date', 'code' => 'Code', 'name' => 'Linen', 'type' => 'Write-off', 'where' => 'From', 'batch' => 'Laundry batch', 'qty' => 'Pieces',
            'value' => 'Value at cost', 'reason' => 'Reason', 'by' => 'Recorded by'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['qty', 'value']];
    }

    protected function r_linen_par($from, $to): array
    {
        $rows = \App\Services\LinenService::parReport()->map(fn ($t) => ['code' => $t->code, 'name' => $t->name, 'per_room' => (float) $t->per_room, 'par' => $t->par_qty,
            'store' => (int) $t->qty_store, 'floor' => (int) $t->qty_floor, 'soiled' => (int) $t->qty_soiled, 'laundry' => (int) $t->qty_laundry, 'total' => $t->total_qty,
            'short' => $t->short, 'cover' => $t->cover === null ? '' : $t->cover.'%', 'cost' => (float) $t->unit_cost, 'value' => round($t->total_qty * (float) $t->unit_cost, 2)])->values();

        return ['cols' => ['code' => 'Code', 'name' => 'Linen', 'per_room' => 'Per room', 'par' => 'Par', 'store' => 'Clean (store)', 'floor' => 'Floors', 'soiled' => 'Soiled',
            'laundry' => 'At laundry', 'total' => 'Total', 'short' => 'Short of par', 'cover' => 'Cover', 'cost' => 'Unit cost', 'value' => 'Stock value'],
            'rows' => $rows, 'money' => ['cost', 'value'], 'sum' => ['par', 'store', 'floor', 'soiled', 'laundry', 'total', 'short', 'value']];
    }

}
