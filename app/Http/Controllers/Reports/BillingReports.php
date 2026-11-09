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
 * Billing reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait BillingReports
{
    protected function r_cashier_movements($from, $to): array
    {
        $rows = CashierMovement::with(['user', 'shift.user'])->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()->map(fn ($movement) => [
            'cashier' => $movement->shift?->user?->name ?? 'Former staff member', 'shift' => '#'.$movement->cashier_shift_id,
            'business_date' => $movement->business_date, 'moved' => $movement->created_at,
            'type' => $movement->kindLabel(), 'amount' => (float) $movement->amount,
            'reason' => $movement->reason, 'reference' => $movement->reference,
            'entered_by' => $movement->user?->name ?? 'Former staff member',
        ]);

        return ['cols' => ['cashier' => 'Cashier', 'shift' => 'Shift', 'business_date' => 'Business date', 'moved' => 'Recorded at',
            'type' => 'Type', 'amount' => 'Amount', 'reason' => 'Reason', 'reference' => 'Reference', 'entered_by' => 'Recorded by'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Auditable cashier drawer cash-in / cash-out movements.'];
    }

    protected function r_cashier_shifts($from, $to): array
    {
        $rows = CashierShift::with('user')->whereDate('business_date', '>=', Carbon::parse($from)->toDateString())->whereDate('business_date', '<=', Carbon::parse($to)->toDateString())->orderBy('business_date')->orderBy('opened_at')->get()->map(function ($shift) {
            $summary = ShiftService::summary($shift);
            $modes = collect($summary['modes'] ?? []);
            $variance = $shift->variance !== null ? (float) $shift->variance : null;

            return [
                '_cashier_id' => (int) $shift->user_id, 'cashier' => $shift->user?->name ?? 'Former staff member', 'business_date' => $shift->business_date,
                'opened' => $shift->opened_at, 'closed' => $shift->closed_at,
                'opening_float' => (float) $shift->opening_float,
                'cash_received' => (float) ($summary['cash_in'] ?? 0), 'cash_out' => (float) ($summary['cash_out'] ?? 0),
                'non_cash_received' => $modes->filter(fn ($mode) => empty($mode['is_cash']))->sum(fn ($mode) => (float) ($mode['in'] ?? 0)),
                'total_collections' => (float) ($summary['total_in'] ?? 0), 'refunds_and_out' => (float) ($summary['total_out'] ?? 0),
                'expected_cash' => $shift->expected_cash !== null ? (float) $shift->expected_cash : (float) ($summary['expected_cash'] ?? 0),
                'counted_cash' => $shift->counted_cash !== null ? (float) $shift->counted_cash : null,
                'variance' => $variance, 'surplus' => $variance === null ? null : max(0, $variance),
                'shortage' => $variance === null ? null : max(0, -$variance), 'status' => $shift->status,
                'close_notes' => $shift->notes,
            ];
        });

        return ['cols' => ['cashier' => 'Cashier', 'business_date' => 'Business date', 'opened' => 'Opened', 'closed' => 'Closed',
            'opening_float' => 'Opening float', 'cash_received' => 'Cash in', 'cash_out' => 'Cash out',
            'non_cash_received' => 'Non-cash received', 'total_collections' => 'Total inflows', 'refunds_and_out' => 'Total outflows',
            'expected_cash' => 'Expected cash', 'counted_cash' => 'Counted cash', 'variance' => 'Variance', 'surplus' => 'Surplus', 'shortage' => 'Shortage', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['opening_float', 'cash_received', 'cash_out', 'non_cash_received', 'total_collections', 'refunds_and_out', 'expected_cash', 'counted_cash', 'variance', 'surplus', 'shortage'],
            'sum' => ['total_collections', 'refunds_and_out', 'surplus', 'shortage'],
            'note' => 'Cashier close-outs by business date. Closed totals use the saved shift snapshot; open shifts are provisional.'];
    }

    /** Department-level cashier reconciliation, grouped to one business-date row. */
    protected function r_cashier_day_summary($from, $to): array
    {
        $register = $this->r_cashier_shifts($from, $to)['rows'];
        $rows = $register->groupBy(fn ($row) => Carbon::parse($row['business_date'])->toDateString())->map(function ($shifts, $date) {
            $closed = $shifts->where('status', 'closed');

            return [
                'business_date' => Carbon::parse($date),
                'cashiers' => $shifts->pluck('_cashier_id')->filter()->unique()->count(),
                'shifts' => $shifts->count(),
                'closed_shifts' => $closed->count(),
                'open_shifts' => $shifts->where('status', 'open')->count(),
                'cash_received' => round((float) $shifts->sum('cash_received'), 2),
                'cash_out' => round((float) $shifts->sum('cash_out'), 2),
                'non_cash_received' => round((float) $shifts->sum('non_cash_received'), 2),
                'total_collections' => round((float) $shifts->sum('total_collections'), 2),
                'expected_cash' => round((float) $shifts->sum('expected_cash'), 2),
                'counted_cash' => round((float) $closed->sum('counted_cash'), 2),
                'variance' => round((float) $closed->sum('variance'), 2),
                'surplus' => round((float) $closed->sum('surplus'), 2),
                'shortage' => round((float) $closed->sum('shortage'), 2),
            ];
        })->sortKeys()->values();

        return [
            'cols' => ['business_date' => 'Business date', 'cashiers' => 'Cashiers', 'shifts' => 'Shifts', 'closed_shifts' => 'Closed', 'open_shifts' => 'Open',
                'cash_received' => 'Cash in', 'cash_out' => 'Cash out', 'non_cash_received' => 'Non-cash received',
                'total_collections' => 'Total inflows', 'expected_cash' => 'Expected cash', 'counted_cash' => 'Counted cash',
                'variance' => 'Net variance', 'surplus' => 'Surplus', 'shortage' => 'Shortage'],
            'rows' => $rows, 'money' => ['cash_received', 'cash_out', 'non_cash_received', 'total_collections', 'expected_cash', 'counted_cash', 'variance', 'surplus', 'shortage'],
            'sum' => ['shifts', 'closed_shifts', 'open_shifts', 'cash_received', 'cash_out', 'non_cash_received', 'total_collections', 'expected_cash', 'counted_cash', 'variance', 'surplus', 'shortage'],
            'note' => 'One row per business date. Open shifts are provisional; counted cash and variance include closed cashier shifts only. A cashier with multiple sessions on the same date is counted once in Cashiers.',
        ];
    }

    protected function r_collection($from, $to): array
    {
        $rows = FolioTransaction::with('folio.guest', 'paymentMode', 'poster')->where('is_void', false)->whereIn('type', ['payment', 'refund'])->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->get()
            ->map(fn ($t) => ['date' => $t->business_date->format('d-m-Y'), 'folio' => $t->folio->folio_no, 'guest' => $t->folio->guest?->full_name, 'desc' => $t->description,
                'mode' => $t->category === 'city_ledger' ? 'City Ledger' : $t->paymentMode?->name, 'ref' => $t->reference, 'amount' => $t->type === 'refund' ? -(float) $t->amount : (float) $t->amount, 'user' => $t->poster?->username]);

        return ['cols' => ['date' => 'Date', 'folio' => 'Folio', 'guest' => 'Guest', 'desc' => 'Description', 'mode' => 'Mode', 'ref' => 'Reference', 'amount' => 'Amount', 'user' => 'Cashier'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => $rows->groupBy('mode')->map->sum('amount')];
    }

    protected function r_credit_logs($from, $to): array
    {
        // Rewritten against the real schema: action/detail/folio_id/user_id; there is no reason/reference/status/description.
        $rows = CreditLog::with('folio.guest', 'company', 'user')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'folio' => optional($r->folio)->folio_no, 'guest' => optional(optional($r->folio)->guest)->name,
            'company' => optional($r->company)->name,
            'action' => $r->action, 'amount' => (float) $r->amount, 'detail' => $r->detail,
            'user' => optional($r->user)->name, 'created' => $r->created_at,
        ]);
        return ['cols' => ['folio' => 'Folio', 'guest' => 'Guest', 'company' => 'Company', 'action' => 'Action', 'amount' => 'Amount', 'detail' => 'Detail', 'user' => 'User', 'created' => 'Created'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Credit note entries with folio reference.'];
    }

    protected function r_invoices($from, $to): array
    {
        $rows = Folio::with('guest', 'company', 'activeTransactions')->where('status', 'closed')->whereBetween('closed_at', [$from, $to->copy()->endOfDay()])->orderBy('closed_at')->get()
            ->map(function ($f) {
                $t = $f->totals();

                return ['inv' => $f->invoice_no, 'date' => $f->closed_at->format('d-m-Y'), 'folio' => $f->folio_no, 'guest' => $f->guest?->full_name, 'gstin' => $f->buyer_gstin ?? ($f->company?->tax_number ?? $f->guest?->tax_number),
                    'taxable' => $t['charges'] - $t['tax'] - $t['discount'], 'tax' => $t['tax'], 'total' => $t['charges'] - $t['discount'], 'paid' => $t['payments'] - $t['refunds']];
            });

        return ['cols' => ['inv' => 'Invoice', 'date' => 'Date', 'folio' => 'Folio', 'guest' => 'Guest', 'gstin' => 'TIN', 'taxable' => 'Taxable', 'tax' => 'Tax', 'total' => 'Invoice total', 'paid' => 'Settled'],
            'rows' => $rows, 'money' => ['taxable', 'tax', 'total', 'paid'], 'sum' => ['taxable', 'tax', 'total', 'paid']];
    }

    protected function r_online_payments($from, $to): array
    {
        $f = $from->copy()->startOfDay();
        $t = $to->copy()->endOfDay();
        $rows = \App\Models\OnlinePayment::with('reservation', 'folioTransaction.folio')
            ->where(fn ($q) => $q->whereBetween('created_at', [$f, $t])->orWhereBetween('paid_at', [$f, $t]))->orderBy('id')->get()
            ->map(fn ($p) => ['no' => $p->pay_no, 'created' => $p->created_at->format('d-m-Y H:i'), 'purpose' => \App\Models\OnlinePayment::PURPOSES[$p->purpose] ?? $p->purpose,
                'guest' => $p->payer_name, 'res' => $p->reservation?->reservation_no, 'folio' => $p->folioTransaction?->folio?->folio_no, 'gateway' => ucfirst($p->provider),
                'txn' => $p->provider_payment_id, 'channel' => $p->channel ? str_replace('_', ' ', $p->channel) : '', 'status' => $p->statusLabel(),
                'paid_at' => $p->paid_at?->format('d-m-Y H:i'), 'amount' => (float) $p->amount,
                'received' => in_array($p->status, ['paid', 'review']) ? $p->received() : 0.0, 'refunded' => (float) $p->refunded_amount,
                'net' => in_array($p->status, ['paid', 'review']) ? round($p->received() - (float) $p->refunded_amount, 2) : 0.0, 'fee' => (float) $p->fee]);

        return ['cols' => ['no' => 'Link', 'created' => 'Created', 'purpose' => 'Purpose', 'guest' => 'Guest', 'res' => 'Reservation', 'folio' => 'Folio', 'gateway' => 'Gateway',
            'txn' => 'Transaction', 'channel' => 'Channel', 'status' => 'Status', 'paid_at' => 'Paid at', 'amount' => 'Requested', 'received' => 'Received', 'refunded' => 'Refunded',
            'net' => 'Net', 'fee' => 'Gateway fee'], 'rows' => $rows, 'money' => ['amount', 'received', 'refunded', 'net', 'fee'], 'sum' => ['received', 'refunded', 'net', 'fee']];
    }

    protected function r_outstanding($from, $to): array
    {
        $rows = Folio::with('guest', 'stay.room', 'company')->where('status', 'open')->get()
            ->map(function ($f) {
                $t = $f->totals();

                return ['folio' => $f->folio_no, 'type' => $f->type, 'room' => $f->stay?->room?->room_no, 'guest' => $f->guest?->full_name, 'company' => $f->company?->name, 'opened' => $f->created_at->format('d-m-Y'),
                    'charges' => $t['charges'] - $t['discount'], 'paid' => $t['payments'] - $t['refunds'], 'balance' => $t['balance']];
            })->filter(fn ($r) => abs($r['balance']) > 0.009)->sortByDesc('balance')->values();

        return ['cols' => ['folio' => 'Folio', 'type' => 'Type', 'room' => 'Room', 'guest' => 'Guest', 'company' => 'Company', 'opened' => 'Opened', 'charges' => 'Charges', 'paid' => 'Paid', 'balance' => 'Balance'], 'rows' => $rows, 'money' => ['charges', 'paid', 'balance'], 'sum' => ['charges', 'paid', 'balance']];
    }

    protected function r_revenue($from, $to): array
    {
        $tx = FolioTransaction::where('is_void', false)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->whereIn('type', ['charge', 'discount', 'allowance'])->get();
        $cats = config('pms.charge_categories');
        $rows = $tx->groupBy(fn ($t) => $t->business_date->toDateString())->sortKeys()->map(function ($g, $d) use ($cats) {
            $row = ['date' => Carbon::parse($d)->format('d-m-Y')];
            foreach ($cats as $k => $l) {
                $row[$k] = $g->where('type', 'charge')->where('category', $k)->sum('amount');
            }
            $row['discount'] = -$g->whereIn('type', ['discount', 'allowance'])->sum('amount');
            $row['tax'] = $g->sum('tax_amount');
            $row['total'] = $g->where('type', 'charge')->sum('amount') + $row['discount'] + $row['tax'];

            return $row;
        })->values();
        $used = collect($cats)->filter(fn ($l, $k) => $rows->sum($k) != 0)->all();
        $keys = array_merge(array_keys($used), ['discount', 'tax', 'total']);

        return ['cols' => ['date' => 'Date'] + $used + ['discount' => 'Discount', 'tax' => 'Tax', 'total' => 'Total'], 'rows' => $rows, 'money' => $keys, 'sum' => $keys];
    }

    protected function r_tax($from, $to): array
    {
        $split = function (string $src, string $cat, $rate, float $taxable, float $tax, ?string $group) {
            $b = \App\Services\TaxService::breakdown($tax, $group, (float) $rate);

            return ['src' => $src, 'cat' => $cat, 'rate' => $rate, 'taxable' => round($taxable, 2), 'vat' => $b['VAT'], 'nhil' => $b['NHIL'], 'getfund' => $b['GETFUND'], 'tl' => $b['TL'], 'tax' => round($tax, 2)];
        };
        $rows = FolioTransaction::where('is_void', false)->where('type', 'charge')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get()
            ->groupBy(fn ($t) => $t->category.'|'.(float) $t->tax_rate)
            ->map(function ($g, $k) use ($split) {
                [$cat, $rate] = explode('|', $k);

                return $split('Front Office', config('pms.charge_categories')[$cat] ?? $cat, $rate, (float) $g->sum('amount'), (float) $g->sum('tax_amount'), $cat);
            })->values();
        $pos = PosOrderItem::whereHas('order', fn ($q) => $q->where('status', 'settled')->where('settlement', '!=', 'room_post')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to))->where('is_cancelled', false)->get()
            ->groupBy(fn ($i) => (float) $i->tax_rate)
            ->map(fn ($g, $rate) => $split('POS (direct)', 'Food & Beverage', $rate, (float) $g->sum('amount'), round($g->sum(fn ($i) => $i->amount * $i->tax_rate / 100), 2), 'fnb'))->values();
        $m = ['taxable', 'vat', 'nhil', 'getfund', 'tl', 'tax'];

        return ['cols' => ['src' => 'Source', 'cat' => 'Category', 'rate' => 'Rate %', 'taxable' => 'Taxable value', 'vat' => 'VAT', 'nhil' => 'NHIL', 'getfund' => 'GETFund', 'tl' => 'Tourism levy', 'tax' => 'Total tax'],
            'rows' => $rows->concat($pos)->values(), 'money' => $m, 'sum' => $m,
            'note' => 'VAT, NHIL and GETFund are payable to GRA (VAT return); the Tourism Levy to the Ghana Tourism Authority. POS bills posted to room are included under Front Office (F&B). Taxable value for POS is before bill-level discount.'];
    }

    protected function r_voids($from, $to): array
    {
        $rows = FolioTransaction::with('folio.guest', 'poster')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where(fn ($q) => $q->where('is_void', true)->orWhereIn('type', ['discount', 'allowance']))->orderBy('business_date')->get()
            ->map(fn ($t) => ['date' => $t->business_date->format('d-m-Y'), 'folio' => $t->folio->folio_no, 'guest' => $t->folio->guest?->full_name, 'kind' => $t->is_void ? 'VOID '.$t->type : strtoupper($t->type),
                'desc' => $t->description, 'amount' => (float) $t->amount + (float) $t->tax_amount, 'reason' => $t->void_reason, 'user' => $t->poster?->username]);
        $pos = PosOrder::with('outlet', 'user')->where('status', 'void')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get()
            ->map(fn ($o) => ['date' => $o->business_date->format('d-m-Y'), 'folio' => $o->order_no, 'guest' => $o->outlet->name, 'kind' => 'VOID POS', 'desc' => $o->customer_name, 'amount' => (float) $o->total, 'reason' => $o->void_reason, 'user' => $o->user?->username]);

        return ['cols' => ['date' => 'Date', 'folio' => 'Folio / Order', 'guest' => 'Guest / Outlet', 'kind' => 'Type', 'desc' => 'Description', 'amount' => 'Amount', 'reason' => 'Reason', 'user' => 'User'], 'rows' => $rows->concat($pos)->values(), 'money' => ['amount'], 'sum' => ['amount']];
    }

}
