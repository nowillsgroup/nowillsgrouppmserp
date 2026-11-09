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
 * Inventory & Purchasing reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait InventoryPurchasingReports
{
    protected function r_consumption($from, $to): array
    {
        $rows = StockMovement::with('item')->whereIn('type', ['issue', 'wastage', 'consumption'])->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()
            ->groupBy(fn ($m) => $m->item->name.'|'.($m->department ?: '—'))
            ->map(function ($g, $k) {
                [$i, $d] = explode('|', $k, 2);

                return ['item' => $i, 'unit' => $g->first()->item->unit, 'dept' => $d, 'issued' => abs($g->whereIn('type', ['issue', 'consumption'])->sum('qty')), 'wastage' => abs($g->where('type', 'wastage')->sum('qty')), 'value' => abs($g->sum(fn ($m) => $m->qty * $m->rate))];
            })->sortByDesc('value')->values();

        return ['cols' => ['item' => 'Item', 'unit' => 'Unit', 'dept' => 'Department', 'issued' => 'Issued', 'wastage' => 'Wastage', 'value' => 'Value'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['value']];
    }

    protected function r_dept_consumption($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $issues = \App\Models\StoreIssue::whereBetween('date', [$f, $t])->get()->groupBy('department_id');
        $adhoc = \App\Models\StockMovement::with('item')->where('type', 'issue')->whereNull('store_issue_id')->whereNotNull('department_id')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->get()
            ->groupBy('department_id')->map(fn ($g) => round($g->sum(fn ($m) => -(float) $m->qty * ((float) $m->rate ?: (float) $m->item?->last_cost)), 2));
        $reqCount = \App\Models\Requisition::whereDate('req_date', '>=', $f)->whereDate('req_date', '<=', $t)->whereNotIn('status', ['draft', 'cancelled'])->selectRaw('department_id, COUNT(*) n')->groupBy('department_id')->pluck('n', 'department_id');
        $occupied = (int) \App\Models\NightAudit::whereDate('business_date', '>=', $f)->whereDate('business_date', '<=', $t)->sum('rooms_occupied');
        // budget pro-rated by the days of each month the period covers
        $factor = 0.0;
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            $a = $m->copy()->max($from);
            $b = $m->copy()->endOfMonth()->min($to);
            $factor += ($a->diffInDays($b) + 1) / $m->daysInMonth;
        }
        $rows = \App\Models\Department::with('expenseHead')->orderBy('name')->get()->map(function ($d) use ($issues, $adhoc, $reqCount, $occupied, $factor) {
            $g = $issues[$d->id] ?? collect();
            $direct = round((float) $g->whereNull('to_store_id')->sum('value'), 2);
            $transfer = round((float) $g->whereNotNull('to_store_id')->sum('value'), 2);
            $other = (float) ($adhoc[$d->id] ?? 0);
            $total = round($direct + $transfer + $other, 2);
            $budget = $d->monthly_budget ? round((float) $d->monthly_budget * $factor, 2) : null;

            return ['dept' => $d->name, 'account' => $d->expenseHead ? $d->expenseHead->code.' '.$d->expenseHead->name : 'by store type', 'reqs' => (int) ($reqCount[$d->id] ?? 0),
                'direct' => $direct, 'transfer' => $transfer, 'other' => $other, 'total' => $total, 'budget' => $budget,
                'variance' => $budget === null ? null : round($budget - $total, 2), 'used' => $budget ? round($total / $budget * 100, 1).' %' : '',
                'per_room' => $occupied ? round($total / $occupied, 2) : null];
        })->filter(fn ($r) => $r['total'] > 0 || $r['budget'] !== null || $r['reqs'])->values();

        return ['cols' => ['dept' => 'Department', 'account' => 'Cost account', 'reqs' => 'Requisitions', 'direct' => 'Issued (expensed)', 'transfer' => 'To own store',
            'other' => 'Ad-hoc issues', 'total' => 'Total', 'budget' => 'Budget (pro-rated)', 'variance' => 'Under / (over)', 'used' => 'Used', 'per_room' => 'Per occupied room'],
            'rows' => $rows, 'money' => ['direct', 'transfer', 'other', 'total', 'budget', 'variance', 'per_room'], 'sum' => ['direct', 'transfer', 'other', 'total', 'budget', 'variance'],
            'summary' => ['Issued to departments' => money($rows->sum('total')), 'Budget for the period' => money($rows->sum('budget')), 'Occupied room nights' => (string) $occupied,
                'Cost per occupied room' => $occupied ? money($rows->sum('total') / $occupied) : '—'],
            'note' => 'At cost. "To own store" is stock transferred into the department\'s store (kitchen, bar…) – it is expensed there when used. Budgets are monthly, pro-rated by the days of each month in the period. Occupied room nights come from the night audits.'];
    }

    /** v4.8 */
    protected function r_goods_received($from, $to): array
    {
        $rows = collect();
        foreach (\App\Models\GoodsReceipt::with('lines.item', 'order', 'supplier')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->orderBy('id')->get() as $g) {
            foreach ($g->lines as $l) {
                $rows->push(['date' => $g->date->format('d-m-Y'), 'grn' => $g->grn_no, 'po' => $g->order?->po_no, 'supplier' => $g->supplier->name, 'item' => $l->item->name, 'unit' => $l->item->unit,
                    'del' => (float) $l->qty_delivered, 'rej' => (float) $l->qty_rejected ?: null, 'reason' => $l->reject_reason, 'acc' => (float) $l->qty_accepted, 'rate' => (float) $l->rate,
                    'value' => (float) $l->amount, 'late' => $g->onTime() ? '' : 'late']);
            }
        }
        foreach (PurchaseOrder::with('items.item', 'supplier')->where('status', 'received')->whereDoesntHave('receipts')
            ->whereBetween('received_at', [$from->toDateString().' 00:00:00', $to->toDateString().' 23:59:59'])->get() as $po) {
            foreach ($po->items as $l) {
                $rows->push(['date' => $po->received_at->format('d-m-Y'), 'grn' => '(single-step)', 'po' => $po->po_no, 'supplier' => $po->supplier->name, 'item' => $l->item->name, 'unit' => $l->item->unit,
                    'del' => (float) $l->qty, 'rej' => null, 'reason' => null, 'acc' => (float) $l->qty, 'rate' => (float) $l->rate, 'value' => (float) $l->amount,
                    'late' => $po->expected_date && $po->received_at->toDateString() > $po->expected_date->toDateString() ? 'late' : '']);
            }
        }
        $rows = $rows->sortBy(fn ($r) => \Carbon\Carbon::createFromFormat('d-m-Y', $r['date'])->format('Ymd').$r['grn'])->values();
        $rejQ = $rows->sum('rej');

        return ['cols' => ['date' => 'Date', 'grn' => 'GRN', 'po' => 'PO', 'supplier' => 'Supplier', 'item' => 'Item', 'unit' => 'Unit', 'del' => 'Delivered', 'rej' => 'Rejected', 'reason' => 'Reject reason',
            'acc' => 'Accepted', 'rate' => 'Rate', 'value' => 'Accepted value', 'late' => 'Late'], 'rows' => $rows, 'money' => ['rate', 'value'], 'sum' => ['value'],
            'note' => $rows->pluck('grn')->unique()->count().' receipt(s)'.($rejQ ? ', '.$rows->where('rej', '>', 0)->count().' line(s) with rejections' : ', no rejections').'. Values before tax at the PO rate.'];
    }

    protected function r_grn_lines($from, $to): array
    {
        // Rewritten against the real schema: receipt()/item() relations; qty_delivered/qty_accepted/qty_rejected/rate/amount; the parent's audit date is `date`.
        $rows = GoodsReceiptLine::with('receipt.supplier', 'item')->whereHas('receipt', fn ($q) => $q->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'grn_no' => optional($r->receipt)->grn_no, 'supplier' => optional(optional($r->receipt)->supplier)->name,
            'item' => optional($r->item)->name, 'qty' => (float) $r->qty_delivered,
            'accepted' => (float) $r->qty_accepted, 'rejected' => (float) $r->qty_rejected,
            'rate' => (float) $r->rate, 'amount' => (float) $r->amount, 'tax_rate' => (float) $r->tax_rate,
        ]);
        return ['cols' => ['grn_no' => 'GRN No', 'supplier' => 'Supplier', 'item' => 'Item', 'qty' => 'Qty', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'rate' => 'Rate', 'amount' => 'Amount', 'tax_rate' => 'Tax %'], 'rows' => $rows, 'money' => ['rate', 'amount'], 'sum' => ['amount'], 'note' => 'Goods receipt note lines with accept/reject tracking.'];
    }

    protected function r_historical_stock_movement_archives($from, $to): array
    {
        $rows = HistoricalStockMovementArchive::with('store.item.itemCategory')->whereBetween('archived_at', [$from, $to])->get()->map(fn ($r) => [
            'store' => optional($r->store)->name, 'item' => optional($r->item)->name,
            'category' => optional(optional($r->item)->itemCategory)->name,
            'movement_type' => $r->movement_type, 'quantity' => $r->quantity, 'rate' => $r->unit_rate,
            'amount' => $r->total_amount, 'archived' => $r->archived_at, 'status' => $r->status,
        ]);
        return ['cols' => ['store' => 'Store', 'item' => 'Item', 'category' => 'Category', 'type' => 'Type', 'quantity' => 'Qty', 'rate' => 'Rate', 'amount' => 'Amount', 'archived' => 'Archived', 'status' => 'Status'], 'rows' => $rows, 'money' => ['unit_rate', 'total_amount'], 'sum' => ['total_amount'], 'note' => 'Archived historical stock movements.'];
    }

    protected function r_inventory_items($from, $to): array
    {
        // Rewritten against the real schema: code/unit/reorder_level/last_cost/tax_rate/is_active/allergens/yield_pct; category() is the only relation used.
        $rows = InventoryItem::with('category')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'code' => $r->code, 'category' => optional($r->category)->name,
            'unit' => $r->unit, 'cost_price' => (float) $r->last_cost, 'tax_rate' => (float) $r->tax_rate,
            'reorder_level' => $r->reorder_level, 'yield_pct' => $r->yield_pct,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'code' => 'Code', 'category' => 'Category', 'unit' => 'Unit', 'cost' => 'Cost', 'tax_rate' => 'Tax %', 'reorder' => 'Reorder Level', 'yield_pct' => 'Yield %', 'active' => 'Active'], 'rows' => $rows, 'money' => ['cost_price'], 'note' => 'Inventory item master register.'];
    }

    protected function r_item_categories($from, $to): array
    {
        // Rewritten against the real schema: item_categories holds name and fb_class only; there is no parent or type.
        $rows = ItemCategory::orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'fb_class' => $r->fb_class,
            'items' => $r->items()->count(),
        ]);
        return ['cols' => ['name' => 'Name', 'fb_class' => 'F&B Class', 'items' => 'Items'], 'rows' => $rows, 'note' => 'Inventory item categories.'];
    }

    protected function r_open_purchase_orders($d): array
    {
        $on = $d->toDateString();
        $rows = collect();
        foreach (PurchaseOrder::with('items.item', 'supplier')->whereIn('status', ['approved', 'partial'])->whereDate('po_date', '<=', $on)->orderBy('expected_date')->get() as $po) {
            foreach ($po->items as $l) {
                if ($l->outstanding() <= 0) {
                    continue;
                }
                $late = $po->expected_date && $po->expected_date->toDateString() < $on ? (int) $po->expected_date->diffInDays($d) : null;
                $rows->push(['po' => $po->po_no, 'date' => $po->po_date->format('d-m-Y'), 'supplier' => $po->supplier->name, 'item' => $l->item->name, 'ordered' => (float) $l->qty,
                    'received' => (float) $l->received_qty ?: null, 'out' => $l->outstanding(), 'value' => round($l->outstanding() * (float) $l->rate, 2),
                    'expected' => $po->expected_date?->format('d-m-Y') ?? '—', 'late' => $late, 'status' => $po->status]);
            }
        }

        return ['cols' => ['po' => 'PO', 'date' => 'PO date', 'supplier' => 'Supplier', 'item' => 'Item', 'ordered' => 'Ordered', 'received' => 'Received', 'out' => 'Outstanding', 'value' => 'Outstanding value',
            'expected' => 'Expected', 'late' => 'Days overdue', 'status' => 'Status'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'note' => $rows->pluck('po')->unique()->count().' open PO(s); '.$rows->whereNotNull('late')->pluck('po')->unique()->count().' overdue. Current position – receipts after the date are not backed out.'];
    }

    protected function r_po_items($from, $to): array
    {
        // Rewritten against the real schema: order()/item() relations; qty/rate/amount/received_qty; the parent's date is po_date.
        $rows = PurchaseOrderItem::with('order.store', 'item')->whereHas('order', fn ($q) => $q->whereBetween('po_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'po_no' => optional($r->order)->po_no, 'item' => optional($r->item)->name,
            'store' => optional(optional($r->order)->store)->name,
            'quantity' => (float) $r->qty, 'rate' => (float) $r->rate, 'tax_rate' => (float) $r->tax_rate,
            'amount' => (float) $r->amount, 'received' => (float) $r->received_qty,
            'status' => optional($r->order)->status,
        ]);
        return ['cols' => ['po_no' => 'PO No', 'item' => 'Item', 'store' => 'Store', 'quantity' => 'Qty', 'rate' => 'Rate', 'tax_rate' => 'Tax %', 'amount' => 'Amount', 'received' => 'Received', 'status' => 'Status'], 'rows' => $rows, 'money' => ['rate', 'amount'], 'sum' => ['amount'], 'note' => 'Purchase order line items.'];
    }

    protected function r_purchases($from, $to): array
    {
        $rows = PurchaseOrder::with('supplier', 'store')->whereDate('po_date', '>=', $from)->whereDate('po_date', '<=', $to)->orderBy('po_date')->get()
            ->map(fn ($p) => ['po' => $p->po_no, 'date' => $p->po_date->format('d-m-Y'), 'supplier' => $p->supplier->name, 'store' => $p->store->name, 'inv' => $p->invoice_no, 'sub' => (float) $p->subtotal, 'tax' => (float) $p->tax, 'total' => (float) $p->total, 'status' => $p->status]);

        return ['cols' => ['po' => 'PO No', 'date' => 'Date', 'supplier' => 'Supplier', 'store' => 'Store', 'inv' => 'Supplier inv.', 'sub' => 'Subtotal', 'tax' => 'Tax', 'total' => 'Total', 'status' => 'Status'], 'rows' => $rows, 'money' => ['sub', 'tax', 'total'], 'sum' => ['sub', 'tax', 'total']];
    }

    protected function r_req_pending($from, $to): array
    {
        $qf = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $reqs = \App\Models\Requisition::with('department', 'fromStore', 'lines.item')->whereIn('status', \App\Models\Requisition::OPEN_FOR_ISSUE)->orderBy('req_date')->get();
        $stock = \App\Models\StockMovement::groupBy('inventory_item_id', 'store_id')->selectRaw('inventory_item_id, store_id, SUM(qty) q')->get()->keyBy(fn ($m) => $m->inventory_item_id.':'.$m->store_id);
        $rows = collect();
        foreach ($reqs as $r) {
            foreach ($r->lines as $l) {
                if (($out = $l->outstanding()) <= 0) {
                    continue;
                }
                $have = round((float) ($stock[$l->inventory_item_id.':'.$r->from_store_id]->q ?? 0), 2);
                $rows->push(['no' => $r->req_no, 'date' => $r->req_date->format('d-m-Y'), 'dept' => $r->department?->name, 'store' => $r->fromStore?->name,
                    'item' => $l->item?->name, 'unit' => $l->item?->unit, 'approved' => $qf($l->qty_approved), 'issued' => $qf($l->qty_issued), 'balance' => $qf($out),
                    'stock' => $qf($have), 'state' => $have + 0.0001 >= $out ? 'Can issue now' : ($have > 0 ? 'Part in stock' : 'Out of stock'),
                    'value' => round($out * (float) $l->item?->last_cost, 2), 'age' => (int) $r->req_date->diffInDays(bdate()), 'needed' => $r->required_by?->format('d-m-Y')]);
            }
        }

        return ['cols' => ['no' => 'Requisition', 'date' => 'Date', 'dept' => 'Department', 'store' => 'Issuing store', 'item' => 'Item', 'unit' => 'Unit', 'approved' => 'Approved',
            'issued' => 'Issued', 'balance' => 'Balance', 'stock' => 'In store', 'state' => 'Status', 'value' => 'Balance value', 'age' => 'Age (days)', 'needed' => 'Needed by'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['value'],
            'summary' => ['Open lines' => (string) $rows->count(), 'Can issue now' => (string) $rows->where('state', 'Can issue now')->count(), 'Out of stock' => (string) $rows->where('state', 'Out of stock')->count(), 'Balance value' => money($rows->sum('value'))],
            'note' => 'Every approved or part-issued requisition line with a balance, as at now.'];
    }

    protected function r_req_register($from, $to): array
    {
        $qf = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $reqs = \App\Models\Requisition::with('department', 'fromStore', 'toStore', 'raiser', 'approver', 'lines.item', 'issues')
            ->whereDate('req_date', '>=', $from->toDateString())->whereDate('req_date', '<=', $to->toDateString())->orderBy('req_date')->orderBy('id')->get();
        $rows = $reqs->map(function ($r) {
            $approvedQty = $r->lines->sum(fn ($l) => (float) ($l->qty_approved ?? 0));
            $issuedQty = $r->lines->sum(fn ($l) => (float) $l->qty_issued);

            return ['no' => $r->req_no, 'date' => $r->req_date->format('d-m-Y'), 'dept' => $r->department?->name, 'from' => $r->fromStore?->name, 'to' => $r->destination(),
                'status' => $r->statusLabel(), 'items' => $r->lines->count(), 'requested' => \App\Services\RequisitionService::valueOf($r->lines, 'qty_requested'),
                'approved' => $r->approved_at && $r->status !== 'rejected' ? (float) $r->est_value : 0.0, 'issued' => round((float) $r->issues->sum('value'), 2),
                'fill' => $approvedQty > 0 ? round($issuedQty / $approvedQty * 100, 1).' %' : '', 'raised' => $r->raiser?->name,
                'approver' => $r->status === 'rejected' ? 'Rejected – '.$r->approver?->name : ($r->approver?->name ?? ($r->approved_at ? 'Auto' : '')),
                'tat' => $r->turnaroundHours(), 'over' => $r->over_budget ? 'Yes' : ''];
        })->values();
        $tats = $rows->pluck('tat')->filter(fn ($v) => $v !== null);
        $appr = $reqs->filter(fn ($r) => $r->approved_at && $r->status !== 'rejected');

        return ['cols' => ['no' => 'Requisition', 'date' => 'Date', 'dept' => 'Department', 'from' => 'From', 'to' => 'To', 'status' => 'Status', 'items' => 'Items',
            'requested' => 'Requested value', 'approved' => 'Approved value', 'issued' => 'Issued value', 'fill' => 'Fill rate', 'raised' => 'Raised by', 'approver' => 'Approved by',
            'tat' => 'Turnaround (h)', 'over' => 'Over budget'],
            'rows' => $rows, 'money' => ['requested', 'approved', 'issued'], 'sum' => ['requested', 'approved', 'issued'],
            'summary' => ['Requisitions' => (string) $rows->count(), 'Approved' => (string) $appr->count(), 'Rejected' => (string) $reqs->where('status', 'rejected')->count(),
                'Issued value' => money($rows->sum('issued')), 'Fill rate (approved qty issued)' => ($q = $appr->sum(fn ($r) => $r->lines->sum(fn ($l) => (float) $l->qty_approved))) > 0 ? round($appr->sum(fn ($r) => $r->lines->sum('qty_issued')) / $q * 100, 1).' %' : '—',
                'Average turnaround' => $tats->count() ? round($tats->avg(), 1).' h' : '—'],
            'note' => 'Turnaround = hours from submission to the first issue note. Fill rate = quantity issued ÷ quantity approved.'];
    }

    protected function r_requisition_lines($from, $to): array
    {
        // Rewritten against the real schema: requisition()/item() relations; qty_requested/qty_approved/qty_issued; the parent's date is req_date and the requester is raised_by.
        $rows = RequisitionLine::with('requisition.raiser', 'item')->whereHas('requisition', fn ($q) => $q->whereBetween('req_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'req_no' => optional($r->requisition)->req_no, 'item' => optional($r->item)->name,
            'requested_by' => optional(optional($r->requisition)->raiser)->name,
            'qty_requested' => (float) $r->qty_requested, 'qty_approved' => (float) $r->qty_approved,
            'qty_issued' => (float) $r->qty_issued, 'remarks' => $r->remarks,
            'status' => optional($r->requisition)->status,
        ]);
        return ['cols' => ['req_no' => 'Req No', 'item' => 'Item', 'requested_by' => 'Requested By', 'qty_requested' => 'Qty Requested', 'qty_approved' => 'Qty Approved', 'qty_issued' => 'Qty Issued', 'remarks' => 'Remarks', 'status' => 'Status'], 'rows' => $rows, 'sum' => ['qty_requested', 'qty_approved', 'qty_issued'], 'note' => 'Requisition line items.'];
    }

    protected function r_rfq_lines($from, $to): array
    {
        // Rewritten against the real schema: rfq()/item() relations; lines carry qty + specification; store is on the parent rfq.
        $rows = RfqLine::with('rfq.store', 'item')->orderBy('rfq_id')->get()->map(fn ($r) => [
            'rfq_no' => optional($r->rfq)->rfq_no, 'item' => optional($r->item)->name,
            'store' => optional(optional($r->rfq)->store)->name,
            'qty' => (float) $r->qty, 'specification' => $r->specification,
            'status' => optional($r->rfq)->status,
        ]);
        return ['cols' => ['rfq_no' => 'RFQ No', 'item' => 'Item', 'store' => 'Store', 'qty' => 'Qty', 'specification' => 'Specification', 'status' => 'Status'], 'rows' => $rows, 'sum' => ['qty'], 'note' => 'RFQ line items.'];
    }

    protected function r_rfq_quote_lines($from, $to): array
    {
        // Rewritten against the real schema: quote()/line() relations; rate/tax_rate/qty_offered; the parent quote carries quote_date and quote_ref.
        $rows = RfqQuoteLine::with('quote.rfq', 'line.item')->whereHas('quote', fn ($q) => $q->whereBetween('quote_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'quote' => optional($r->quote)->quote_ref, 'rfq_no' => optional(optional($r->quote)->rfq)->rfq_no,
            'item' => optional(optional($r->line)->item)->name,
            'qty_offered' => (float) $r->qty_offered, 'rate' => (float) $r->rate,
            'tax_rate' => (float) $r->tax_rate, 'remarks' => $r->remarks,
        ]);
        return ['cols' => ['quote' => 'Quote Ref', 'rfq_no' => 'RFQ No', 'item' => 'Item', 'qty_offered' => 'Qty Offered', 'rate' => 'Rate', 'tax_rate' => 'Tax %', 'remarks' => 'Remarks'], 'rows' => $rows, 'money' => ['rate'], 'note' => 'RFQ quote line items.'];
    }

    protected function r_rfq_quotes($from, $to): array
    {
        $rows = RfqQuote::with('rfq.supplier')->whereBetween('quoted_at', [$from, $to])->get()->map(fn ($r) => [
            'rfq_no' => optional($r->rfq)->rfq_no, 'supplier' => optional($r->supplier)->name,
            'quoted' => $r->quoted_at, 'total_amount' => $r->total_amount,
            'valid_days' => $r->valid_days, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['rfq_no' => 'RFQ No', 'supplier' => 'Supplier', 'quoted' => 'Quoted', 'total' => 'Total', 'valid_days' => 'Valid Days', 'status' => 'Status'], 'rows' => $rows, 'money' => ['total_amount'], 'sum' => ['total_amount'], 'note' => 'RFQ quote summaries.'];
    }

    protected function r_rfq_register($from, $to): array
    {
        // Rewritten against the real schema: rfq_no/title/store_id/rfq_date/respond_by/needed_by/status/created_by; there is no requester or budget.
        $rows = Rfq::with('store', 'creator')->whereBetween('rfq_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->orderByDesc('rfq_date')->get()->map(fn ($r) => [
            'rfq_no' => $r->rfq_no, 'title' => $r->title, 'store' => optional($r->store)->name,
            'rfq_date' => $r->rfq_date, 'respond_by' => $r->respond_by, 'needed_by' => $r->needed_by,
            'created_by' => optional($r->creator)->name, 'status' => $r->status,
        ]);
        return ['cols' => ['rfq_no' => 'RFQ No', 'title' => 'Title', 'store' => 'Store', 'rfq_date' => 'RFQ Date', 'respond_by' => 'Respond By', 'needed_by' => 'Needed By', 'created_by' => 'Created By', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Request for quotation register.'];
    }

    protected function r_rfq_suppliers($from, $to): array
    {
        // Rewritten against the real schema: rfq()/supplier() relations + state(); email_status/invited_at/viewed_at/responded_at/declined_at are the audit columns.
        $rows = RfqSupplier::with('rfq', 'supplier')->whereHas('rfq', fn ($q) => $q->whereBetween('rfq_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->orderBy('rfq_id')->get()->map(fn ($r) => [
            'rfq_no' => optional($r->rfq)->rfq_no, 'supplier' => optional($r->supplier)->name,
            'email_status' => $r->email_status, 'invited_at' => $r->invited_at,
            'viewed_at' => $r->viewed_at, 'responded_at' => $r->responded_at,
            'state' => $r->state(),
        ]);
        return ['cols' => ['rfq_no' => 'RFQ No', 'supplier' => 'Supplier', 'email_status' => 'Email Status', 'invited_at' => 'Invited', 'viewed_at' => 'Viewed', 'responded_at' => 'Responded', 'state' => 'State'], 'rows' => $rows, 'note' => 'RFQ supplier selections.'];
    }

    protected function r_stock_take_lines($from, $to): array
    {
        // Rewritten against the real schema: stockTake()/item() relations; system_qty/counted_qty/unit_cost; variance from the model methods; the parent's date is count_date.
        $rows = StockTakeLine::with('stockTake.store', 'item')->whereHas('stockTake', fn ($q) => $q->whereBetween('count_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'take_no' => optional($r->stockTake)->take_no, 'store' => optional(optional($r->stockTake)->store)->name,
            'item' => optional($r->item)->name,
            'system_qty' => (float) $r->system_qty, 'counted_qty' => $r->counted_qty !== null ? (float) $r->counted_qty : null,
            'variance_qty' => $r->varianceQty(), 'unit_cost' => (float) $r->unit_cost,
            'variance_value' => $r->varianceValue(), 'remarks' => $r->remarks,
            'status' => optional($r->stockTake)->status,
        ]);
        return ['cols' => ['take_no' => 'Take No', 'store' => 'Store', 'item' => 'Item', 'system_qty' => 'Book Qty', 'counted_qty' => 'Counted Qty', 'variance_qty' => 'Diff', 'unit_cost' => 'Rate', 'variance_value' => 'Diff Value', 'remarks' => 'Remarks', 'status' => 'Status'], 'rows' => $rows, 'money' => ['unit_cost', 'variance_value'], 'sum' => ['variance_value'], 'note' => 'Line-level stock take details with variance.'];
    }

    protected function r_stock_takes($from, $to): array
    {
        $rows = StockTake::with('store.storeType')->whereBetween('taken_at', [$from, $to])->get()->map(fn ($r) => [
            'store' => optional($r->store)->name, 'taken' => $r->taken_at, 'user' => $r->user_id,
            'items' => $r->item_count, 'book_qty' => $r->book_quantity, 'actual_qty' => $r->actual_quantity,
            'value_book' => $r->value_book, 'value_actual' => $r->value_actual, 'diff_value' => $r->diff_value,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['store' => 'Store', 'taken' => 'Taken', 'items' => 'Items', 'book_qty' => 'Book Qty', 'actual_qty' => 'Actual Qty', 'diff_qty' => 'Diff Qty', 'value_book' => 'Book Value', 'value_actual' => 'Actual Value', 'diff_value' => 'Diff Value', 'status' => 'Status'], 'rows' => $rows, 'money' => ['value_book', 'value_actual', 'diff_value'], 'sum' => ['value_book', 'value_actual', 'diff_value'], 'note' => 'Stock take summaries with variance.'];
    }

    protected function r_store_issue_lines($from, $to): array
    {
        // Rewritten against the real schema: issue()/item() relations; qty/rate/amount; the parent's date is `date`; stores via fromStore()/toStore().
        $rows = StoreIssueLine::with('issue.fromStore', 'issue.toStore', 'item')->whereHas('issue', fn ($q) => $q->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'issue_no' => optional($r->issue)->issue_no,
            'from_store' => optional(optional($r->issue)->fromStore)->name,
            'to_store' => optional(optional($r->issue)->toStore)->name,
            'item' => optional($r->item)->name,
            'quantity' => (float) $r->qty, 'rate' => (float) $r->rate, 'amount' => (float) $r->amount,
        ]);
        return ['cols' => ['issue_no' => 'Issue No', 'from_store' => 'From Store', 'to_store' => 'To Store', 'item' => 'Item', 'quantity' => 'Qty', 'rate' => 'Rate', 'amount' => 'Amount'], 'rows' => $rows, 'money' => ['rate', 'amount'], 'sum' => ['amount'], 'note' => 'Store issue line items.'];
    }

    protected function r_store_pars($from, $to): array
    {
        $rows = StorePar::with('store.parType')->whereBetween('filled_at', [$from, $to])->get()->map(fn ($r) => [
            'store' => optional($r->store)->name, 'date' => $r->filled_at, 'user' => $r->user_id,
            'par_value' => $r->par_value, 'actual_value' => $r->actual_value, 'variance' => $r->variance,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['store' => 'Store', 'date' => 'Date', 'par_value' => 'Par Value', 'actual_value' => 'Actual Value', 'variance' => 'Variance', 'status' => 'Status'], 'rows' => $rows, 'money' => ['par_value', 'actual_value', 'variance'], 'sum' => ['variance'], 'note' => 'Daily store par fills with variance.'];
    }

    protected function r_supplier_invoice_lines($from, $to): array
    {
        // Rewritten against the real schema: invoice()/head() relations; lines are account-head expenses with amount/tax_rate/tax_amount; the parent's date is doc_date and its number is doc_no.
        $rows = SupplierInvoiceLine::with('invoice.supplier', 'head')->whereHas('invoice', fn ($q) => $q->whereBetween('doc_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]))->get()->map(fn ($r) => [
            'invoice_no' => optional($r->invoice)->doc_no, 'supplier' => optional(optional($r->invoice)->supplier)->name,
            'head' => optional($r->head)->name, 'description' => $r->description,
            'amount' => (float) $r->amount, 'tax_rate' => (float) $r->tax_rate, 'tax_amount' => (float) $r->tax_amount,
            'status' => optional($r->invoice)->status,
        ]);
        return ['cols' => ['invoice_no' => 'Invoice No', 'supplier' => 'Supplier', 'head' => 'Account Head', 'description' => 'Description', 'amount' => 'Amount', 'tax_rate' => 'Tax %', 'tax_amount' => 'Tax Amt', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount', 'tax_amount'], 'sum' => ['amount', 'tax_amount'], 'note' => 'Supplier invoice line items.'];
    }

    protected function r_supplier_prices($from, $to): array
    {
        $rows = SupplierPrice::with('supplier.item.itemCategory')->whereBetween('effective_from', [$from, $to])->get()->map(fn ($r) => [
            'supplier' => optional($r->supplier)->name, 'item' => optional($r->item)->name,
            'category' => optional(optional($r->item)->itemCategory)->name,
            'old_price' => $r->old_price, 'new_price' => $r->new_price, 'change' => $r->price_change,
            'old_tax' => $r->old_tax_rate, 'new_tax' => $r->new_tax_rate,
            'unit' => $r->unit, 'effective' => $r->effective_from, 'user' => $r->user_id, 'notes' => $r->notes,
        ]);
        return ['cols' => ['supplier' => 'Supplier', 'item' => 'Item', 'category' => 'Category', 'old_price' => 'Old Price', 'new_price' => 'New Price', 'change' => 'Change', 'unit' => 'Unit', 'effective' => 'Effective'], 'rows' => $rows, 'money' => ['old_price', 'new_price', 'price_change'], 'sum' => ['price_change'], 'note' => 'Supplier price change history with tax rate updates.'];
    }

    protected function r_supplier_scorecard($from, $to): array
    {
        $rows = \App\Services\ProcurementService::scorecard($from->toDateString(), $to->toDateString())->map(fn ($r) => ['supplier' => $r['supplier'], 'orders' => $r['orders'], 'value' => $r['value'],
            'deliveries' => $r['deliveries'], 'on_time' => $r['on_time'], 'fill' => $r['fill'], 'quality' => $r['quality'], 'price' => $r['price'], 'response' => $r['response'],
            'lead' => $r['lead_days'], 'score' => $r['score'], 'grade' => $r['grade']]);

        return ['cols' => ['supplier' => 'Supplier', 'orders' => 'POs', 'value' => 'PO value', 'deliveries' => 'Deliveries', 'on_time' => 'On time %', 'fill' => 'Fill rate %', 'quality' => 'Quality %',
            'price' => 'Price compliance %', 'response' => 'RFQ response %', 'lead' => 'Avg lead (days)', 'score' => 'Score', 'grade' => 'Grade'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['orders', 'value', 'deliveries'],
            'note' => 'Score weights: on-time 35, fill rate 25, quality 25, price compliance 15 – averaged over the measures available. Grades A ≥ 90, B ≥ 75, C ≥ 60, D below.'];
    }

}
