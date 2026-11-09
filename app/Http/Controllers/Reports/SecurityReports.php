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
 * Security reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait SecurityReports
{
    protected function r_gate_pass_items($from, $to): array
    {
        // Rewritten against the real schema: items link pass()/asset(); the parent carries pass_no/party_name/created_at/out_at/status; est_value is the item value.
        $rows = GatePassItem::with('pass', 'asset')->whereHas('pass', fn ($q) => $q->whereBetween('created_at', [$from, $to]))->get()->map(fn ($r) => [
            'pass_no' => optional($r->pass)->pass_no, 'party' => optional($r->pass)->party_name,
            'type' => optional($r->pass)->type, 'description' => $r->description,
            'asset' => optional($r->asset)->name, 'quantity' => $r->quantity, 'unit' => $r->unit,
            'est_value' => (float) $r->est_value, 'returned_qty' => $r->returned_qty,
            'status' => optional($r->pass)->status,
        ]);
        return ['cols' => ['pass_no' => 'Pass No', 'party' => 'Party', 'type' => 'Type', 'description' => 'Description', 'asset' => 'Asset', 'quantity' => 'Qty', 'unit' => 'Unit', 'est_value' => 'Est Value', 'returned_qty' => 'Returned', 'status' => 'Status'], 'rows' => $rows, 'money' => ['est_value'], 'sum' => ['est_value'], 'note' => 'Gate pass line items.'];
    }

    protected function r_gate_pass_returns($from, $to): array
    {
        $rows = GatePassReturn::with('gatePass.guest')->whereBetween('returned_at', [$from, $to])->get()->map(fn ($r) => [
            'pass_no' => optional($r->gatePass)->pass_no, 'guest' => optional($r->guest)->name,
            'returned' => $r->returned_at, 'deposit' => $r->deposit_amount, 'refund' => $r->refund_amount,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['pass_no' => 'Pass No', 'guest' => 'Guest', 'returned' => 'Returned', 'deposit' => 'Deposit', 'refund' => 'Refund', 'status' => 'Status'], 'rows' => $rows, 'money' => ['deposit_amount', 'refund_amount'], 'sum' => ['refund_amount'], 'note' => 'Gate pass return register with deposit refunds.'];
    }

    protected function r_security_gatepasses($from, $to): array
    {
        $rows = \App\Models\GatePass::with('items', 'department', 'requester', 'approver')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get();

        return ['cols' => ['pass' => 'Pass', 'date' => 'Raised', 'type' => 'Type', 'purpose' => 'Purpose', 'dept' => 'Department', 'party' => 'Party', 'items' => 'Items',
            'value' => 'Est. value', 'by' => 'Raised by', 'approved' => 'Approved by', 'out' => 'Out', 'due' => 'Due back', 'status' => 'Status'],
            'rows' => $rows->map(fn ($p) => ['pass' => $p->pass_no, 'date' => $p->created_at->format('d M Y'), 'type' => \App\Models\GatePass::TYPES[$p->type],
                'purpose' => \App\Models\GatePass::PURPOSES[$p->purpose] ?? $p->purpose, 'dept' => $p->department?->name, 'party' => $p->party_name,
                'items' => $p->items->map(fn ($i) => ((float) $i->quantity + 0).' '.$i->description)->join(', '), 'value' => (float) $p->items->sum('est_value'),
                'by' => $p->requester?->name, 'approved' => $p->approver?->name, 'out' => $p->out_at?->format('d M H:i'), 'due' => $p->expected_return_date?->format('d M Y'),
                'status' => $p->statusLabel().($p->isOverdue() ? ' – '.$p->daysOverdue().' d overdue' : '')]),
            'money' => ['value'], 'sum' => ['value'],
            'summary' => ['Gate passes' => (string) $rows->count(), 'Returnable' => (string) $rows->where('type', 'returnable')->count(),
                'Non-returnable' => (string) $rows->where('type', 'non_returnable')->count(), 'Rejected' => (string) $rows->where('status', 'rejected')->count(),
                'Overdue now' => (string) $rows->filter->isOverdue()->count()]];
    }

    protected function r_security_incident_updates($from, $to): array
    {
        $rows = SecurityIncidentUpdate::with('incident.user')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'incident' => optional($r->incident)->incident_no, 'type' => $r->update_type,
            'description' => $r->description, 'created' => $r->created_at, 'user' => optional($r->user)->name, 'status' => $r->status,
        ]);
        return ['cols' => ['incident' => 'Incident', 'type' => 'Type', 'description' => 'Description', 'created' => 'Created', 'user' => 'User', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Security incident update audit trail.'];
    }

    protected function r_security_incidents($from, $to): array
    {
        $rows = \App\Models\SecurityIncident::with('room', 'guest', 'assignee')->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('occurred_at')->get();

        return ['cols' => ['no' => 'Incident', 'when' => 'When', 'type' => 'Type', 'severity' => 'Severity', 'title' => 'Summary', 'where' => 'Location', 'guest' => 'Guest',
            'injury' => 'Injury', 'loss' => 'Loss', 'police' => 'Police ref', 'officer' => 'Officer', 'status' => 'Status', 'resolution' => 'Resolution'],
            'rows' => $rows->map(fn ($i) => ['no' => $i->incident_no, 'when' => $i->occurred_at->format('d M Y H:i'), 'type' => $i->typeLabel(), 'severity' => $i->severityLabel(),
                'title' => $i->title, 'where' => $i->location.($i->room ? ' · '.$i->room->room_no : ''), 'guest' => $i->guest?->full_name, 'injury' => $i->injuries ? 'Yes' : '',
                'loss' => (float) $i->loss_amount, 'police' => $i->police_informed ? ($i->police_ref ?: 'informed') : '', 'officer' => $i->assignee?->name,
                'status' => $i->statusLabel(), 'resolution' => $i->resolution]),
            'money' => ['loss'], 'sum' => ['loss'],
            'summary' => ['Incidents' => (string) $rows->count(), 'High / critical' => (string) $rows->whereIn('severity', ['high', 'critical'])->count(),
                'Still open' => (string) $rows->where('status', '!=', 'closed')->count(), 'With injuries' => (string) $rows->where('injuries', true)->count(),
                'Police informed' => (string) $rows->where('police_informed', true)->count(), 'Loss / damage' => money($rows->sum('loss_amount'))]];
    }

    protected function r_security_outstanding($from, $to): array
    {
        $asAt = $to->copy()->endOfDay();
        $passes = \App\Models\GatePass::with('items', 'department', 'returns')->where('type', 'returnable')->whereNotNull('out_at')->where('out_at', '<=', $asAt)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>', $asAt))->orderBy('expected_return_date')->get();
        $rows = collect();
        foreach ($passes as $p) {
            foreach ($p->items as $it) {
                $back = $p->returns->filter(fn ($r) => $r->returned_at->lte($asAt))->flatMap(fn ($r) => $r->lines)->where('item_id', $it->id)->sum('qty');
                $left = round((float) $it->quantity - (float) $back, 2);
                if ($left <= 0) {
                    continue;
                }
                $late = $p->expected_return_date && $p->expected_return_date->lt($to->copy()->startOfDay()) ? (int) $p->expected_return_date->diffInDays($to->copy()->startOfDay()) : 0;
                $rows->push(['pass' => $p->pass_no, 'party' => $p->party_name, 'dept' => $p->department?->name, 'item' => $it->description, 'out' => $p->out_at->format('d M Y'),
                    'qty' => $left.' '.$it->unit, 'value' => $it->est_value ? round((float) $it->est_value * $left / max(0.01, (float) $it->quantity), 2) : 0,
                    'due' => $p->expected_return_date?->format('d M Y'), 'late' => $late]);
            }
        }

        return ['cols' => ['pass' => 'Pass', 'party' => 'With', 'dept' => 'Department', 'item' => 'Item', 'out' => 'Went out', 'qty' => 'Still out', 'value' => 'Est. value', 'due' => 'Due back', 'late' => 'Days overdue'],
            'rows' => $rows->sortByDesc('late')->values(), 'money' => ['value'], 'sum' => ['value'],
            'summary' => ['Items out' => (string) $rows->count(), 'Estimated value' => money($rows->sum('value')), 'Overdue items' => (string) $rows->where('late', '>', 0)->count()],
            'note' => 'Returnable goods that had left through the gate and were not back (or written off) at the end of the chosen date.'];
    }

    protected function r_security_visitors($from, $to): array
    {
        $rows = \App\Models\Visitor::with('room', 'inBy', 'outBy', 'watch')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')->get();

        return ['cols' => ['pass' => 'Pass', 'name' => 'Visitor', 'phone' => 'Mobile', 'id' => 'ID shown', 'host' => 'Visiting', 'purpose' => 'Purpose', 'persons' => 'Persons',
            'in' => 'In', 'out' => 'Out', 'mins' => 'Minutes', 'by' => 'Let in by', 'status' => 'Status', 'flags' => 'Flags'],
            'rows' => $rows->map(fn ($v) => ['pass' => $v->pass_no, 'name' => $v->name, 'phone' => $v->phone, 'id' => $v->maskedId(), 'host' => $v->hostLabel(),
                'purpose' => \App\Models\Visitor::PURPOSES[$v->purpose] ?? $v->purpose, 'persons' => $v->persons, 'in' => $v->checked_in_at?->format('d M H:i'),
                'out' => $v->checked_out_at?->format('d M H:i'), 'mins' => $v->checked_in_at && $v->checked_out_at ? (int) $v->checked_in_at->diffInMinutes($v->checked_out_at) : null,
                'by' => $v->inBy?->name, 'status' => $v->statusLabel(),
                'flags' => collect([$v->watchlist_id ? 'watch list' : null, $v->override_reason ? 'override: '.$v->override_reason : null,
                    $v->checked_out_at && $v->valid_until && $v->checked_out_at->gt($v->valid_until) ? 'overstayed' : null, $v->source === 'portal' ? 'pre-registered' : null])->filter()->join('; ')]),
            'sum' => ['persons'],
            'summary' => ['Visitors' => (string) $rows->count(), 'People' => (string) $rows->sum('persons'), 'To guest rooms' => (string) $rows->where('host_type', 'guest')->count(),
                'Turned away' => (string) $rows->where('status', 'denied')->count(), 'Watch-list matches' => (string) $rows->whereNotNull('watchlist_id')->count(),
                'Overrides' => (string) $rows->whereNotNull('override_reason')->count()],
            'note' => 'Registered in the period. ID numbers are never stored – only the type and last 4 characters. Records older than the retention setting show as anonymised.'];
    }

    protected function r_security_watch($from, $to): array
    {
        $rows = SecurityWatch::with('employee.department')->whereBetween('watch_date', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'dept' => optional(optional($r->employee)->department)->name,
            'date' => $r->watch_date, 'shift' => $r->shift, 'location' => $r->location,
            'duty' => $r->duty, 'start' => $r->start_time, 'end' => $r->end_time, 'status' => $r->status,
        ]);
        return ['cols' => ['employee' => 'Employee', 'dept' => 'Dept', 'date' => 'Date', 'shift' => 'Shift', 'location' => 'Location', 'duty' => 'Duty', 'start' => 'Start', 'end' => 'End', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Security watch/duty roster.'];
    }

}
