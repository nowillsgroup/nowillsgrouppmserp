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
 * Accounts reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait AccountsReports
{
    protected function r_allotment_utilisation($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->startOfDay();
        $today = bdate()->copy()->startOfDay();
        $rows = \App\Models\ContractAllotment::with('contract.company', 'roomType')->whereDate('date_from', '<=', $t)->whereDate('date_to', '>=', $f)
            ->whereHas('contract', fn ($q) => $q->whereIn('status', ['active', 'suspended']))->get()
            ->map(function ($a) use ($f, $t, $today) {
                $stays = \App\Models\ReservationRoom::where('room_type_id', $a->room_type_id)->whereIn('status', ['reserved', 'checked_in', 'checked_out'])
                    ->whereHas('reservation', fn ($q) => $q->where('rate_contract_id', $a->rate_contract_id)->where('status', '!=', 'cancelled'))
                    ->whereDate('arrival_date', '<=', min($t, $a->date_to))->whereDate('departure_date', '>', max($f, $a->date_from))->get(['arrival_date', 'departure_date']);
                $allotted = $picked = $released = $held = 0;
                for ($d = max($f, $a->date_from)->copy(); $d->lte(min($t, $a->date_to)); $d->addDay()) {
                    $p = min($a->rooms, $stays->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count());
                    $allotted += $a->rooms;
                    $picked += $p;
                    if ($today->diffInDays($d, false) < $a->release_days) {
                        $released += $a->rooms - $p;
                    } else {
                        $held += $a->rooms - $p;
                    }
                }

                return ['contract' => $a->contract->code, 'company' => $a->contract->company->name, 'type' => $a->roomType?->name, 'rooms' => $a->rooms, 'rel' => $a->release_days.' d',
                    'period' => $a->date_from->format('d M').' – '.$a->date_to->format('d M Y'), 'allot' => $allotted, 'picked' => $picked, 'released' => $released, 'held' => $held,
                    'pct' => $allotted ? round($picked / $allotted * 100, 1) : 0];
            })->sortBy('contract')->values();

        return ['cols' => ['contract' => 'Contract', 'company' => 'Company', 'type' => 'Room type', 'rooms' => 'Rooms / night', 'rel' => 'Release', 'period' => 'Allotment period',
            'allot' => 'Allotted RN', 'picked' => 'Picked up', 'released' => 'Released unsold', 'held' => 'Still held', 'pct' => 'Utilisation %'],
            'rows' => $rows, 'sum' => ['allot', 'picked', 'released', 'held'],
            'note' => 'Nights in the selected period only. Released = nights inside the release window (or past) that were not picked up – those rooms went back to general sale. Still held = future nights not yet released.'];
    }

    protected function r_ap_ageing($from, $to): array
    {
        $rows = \App\Services\ApService::ageing($to->toDateString())->map(fn ($r) => $r + ['overdue' => round($r['d30'] + $r['d60'] + $r['d90'] + $r['d90p'], 2)]);

        return ['cols' => ['supplier' => 'Supplier', 'terms' => 'Terms (days)', 'current' => 'Not due', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'd90p' => '> 90',
            'overdue' => 'Total overdue', 'credit' => 'Unapplied credit', 'balance' => 'Balance', 'unbilled' => 'Of which unbilled', 'oldest' => 'Oldest (days past due)'],
            'rows' => $rows, 'money' => ['current', 'd30', 'd60', 'd90', 'd90p', 'overdue', 'credit', 'balance', 'unbilled'], 'sum' => ['current', 'd30', 'd60', 'd90', 'd90p', 'overdue', 'credit', 'balance', 'unbilled']];
    }

    protected function r_ap_allocations($from, $to): array
    {
        $rows = \App\Models\ApAllocation::with('payment.supplier')->whereBetween('allocated_at', [$from, $to])->get()->map(fn ($r) => [
            'payment' => optional($r->payment)->reference, 'supplier' => optional($r->payment->supplier)->name,
            'invoice' => $r->invoice_no, 'amount' => $r->allocated_amount, 'date' => $r->allocated_at,
            'type' => $r->allocation_type, 'status' => $r->status, 'user' => $r->user_id,
        ]);
        return ['cols' => ['payment' => 'Payment', 'supplier' => 'Supplier', 'invoice' => 'Invoice', 'amount' => 'Amount', 'date' => 'Date', 'type' => 'Type', 'status' => 'Status'], 'rows' => $rows, 'money' => ['allocated_amount'], 'sum' => ['allocated_amount'], 'note' => 'AP payment allocations to invoices.'];
    }

    protected function r_ap_register($from, $to): array
    {
        $rows = \App\Models\SupplierInvoice::with('supplier', 'purchaseOrder')->whereDate('doc_date', '>=', $from)->whereDate('doc_date', '<=', $to)->orderBy('doc_date')->orderBy('id')->get()
            ->map(fn ($b) => ['doc' => $b->doc_no, 'date' => $b->doc_date->format('d-m-Y'), 'type' => $b->isDebitNote() ? 'Debit note' : ($b->kind === 'goods' ? 'Goods' : 'Expense'),
                'supplier' => $b->supplier?->name, 'ref' => $b->supplier_ref, 'po' => $b->purchaseOrder?->po_no, 'due' => $b->isDebitNote() ? '' : $b->due_date?->format('d-m-Y'),
                'subtotal' => ($b->isDebitNote() ? -1 : 1) * (float) $b->subtotal, 'tax' => ($b->isDebitNote() ? -1 : 1) * (float) $b->tax, 'total' => ($b->isDebitNote() ? -1 : 1) * (float) $b->total,
                'variance' => $b->variance(), 'allocated' => (float) $b->allocated, 'outstanding' => $b->isDebitNote() ? 0 : $b->outstanding(),
                'status' => \App\Models\SupplierInvoice::STATUSES[$b->status] ?? $b->status]);

        return ['cols' => ['doc' => 'Document', 'date' => 'Date', 'type' => 'Type', 'supplier' => 'Supplier', 'ref' => 'Supplier inv.', 'po' => 'PO', 'due' => 'Due',
            'subtotal' => 'Subtotal', 'tax' => 'Tax', 'total' => 'Total', 'variance' => 'Variance', 'allocated' => 'Paid / applied', 'outstanding' => 'Outstanding', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['subtotal', 'tax', 'total', 'variance', 'allocated', 'outstanding'], 'sum' => ['subtotal', 'tax', 'total', 'variance', 'outstanding']];
    }

    protected function r_ar_aging($from, $to): array
    {
        $rows = \App\Services\ManagementReportService::arAgeing($to);
        $m = ['billed', 'received', 'b0', 'b30', 'b60', 'b90', 'unapplied', 'outstanding', 'limit'];

        return ['cols' => ['company' => 'Company', 'type' => 'Type', 'billed' => 'Billed', 'received' => 'Received', 'b0' => '0–30 days', 'b30' => '31–60 days', 'b60' => '61–90 days', 'b90' => 'Over 90 days', 'unapplied' => 'Unapplied credit', 'outstanding' => 'Outstanding', 'limit' => 'Credit limit', 'oldest' => 'Oldest', 'flag' => 'Flag'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'note' => 'Company outstanding aged FIFO against receipts up to the date. '.$this->glNote()];
    }

    protected function r_bank_reconciliation($from, $to): array
    {
        $rows = $this->recStatements($to)->map(function ($p) use ($to) {
            [$a, $s] = $p;
            $book = \App\Services\BankRecService::glBalance($a->id, $to->toDateString());
            if (! $s) {
                return ['account' => $a->code.' '.$a->name, 'book' => $book, 'statement' => 'None yet', 'stmt_bal' => null, 'in' => null, 'out' => null, 'bank_only' => null, 'diff' => null, 'status' => 'Not started'];
            }
            $x = \App\Services\BankRecService::summary($s);

            return ['account' => $a->code.' '.$a->name, 'book' => $book, 'statement' => $s->statement_no.' to '.$s->period_to->format('d M Y'), 'stmt_bal' => $x['closing'],
                'in' => $x['uncleared_in'], 'out' => -$x['uncleared_out'], 'bank_only' => $x['bank_only'], 'diff' => $x['difference'],
                'status' => ($s->isDraft() ? 'In progress' : 'Reconciled').($s->needs_review ? ' – review' : '').($s->period_to->lt($to) ? ' (book moved on '.$s->period_to->diffInDays($to).' day(s))' : '')];
        })->values();

        return ['cols' => ['account' => 'Account', 'book' => 'Book balance', 'statement' => 'Latest statement', 'stmt_bal' => 'Statement balance', 'in' => 'Deposits in transit', 'out' => 'Unpresented payments',
            'bank_only' => 'Statement items not in books', 'diff' => 'Difference', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['book', 'stmt_bal', 'in', 'out', 'bank_only', 'diff'], 'sum' => ['book'],
            'note' => 'Deposits in transit, unpresented payments and statement-only items are as at each statement’s end date; the book balance is at '.$to->format('d M Y').'.'];
    }

    protected function r_bank_statement_lines($from, $to): array
    {
        $types = ['reference' => 'Auto – reference', 'amount' => 'Auto – amount', 'group' => 'Auto – batch', 'manual' => 'By hand', 'entry' => 'Booked from statement'];
        $rows = \App\Models\BankStatementLine::with('statement.account', 'glLines.journal')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')->orderBy('id')->get()->map(fn ($l) => ['date' => $l->date->format('d M Y'), 'account' => $l->statement->account->code, 'statement' => $l->statement->statement_no,
                'details' => $l->description, 'ref' => $l->reference, 'in' => $l->amount > 0 ? (float) $l->amount : null, 'out' => $l->amount < 0 ? -(float) $l->amount : null,
                'status' => ucfirst($l->status), 'how' => $types[$l->match_type] ?? ($l->status === 'ignored' ? $l->ignore_reason : ''), 'journals' => $l->glLines->map(fn ($g) => $g->journal->journal_no)->unique()->implode(', ')]);

        return ['cols' => ['date' => 'Date', 'account' => 'Account', 'statement' => 'Statement', 'details' => 'Details', 'ref' => 'Reference', 'in' => 'In', 'out' => 'Out', 'status' => 'Status', 'how' => 'Matched how', 'journals' => 'Book entries'],
            'rows' => $rows, 'money' => ['in', 'out'], 'sum' => ['in', 'out'],
            'note' => $rows->count().' line(s): '.$rows->where('status', 'Matched')->count().' matched, '.$rows->where('status', 'Unmatched')->count().' open, '.$rows->where('status', 'Ignored')->count().' ignored.'];
    }

    protected function r_billing_reconciliation_items($from, $to): array
    {
        // Rewritten against the real schema: line items carry document_date, document_no, folio_no,
        // document_amount, review_status and source_status; there is no reconciled_at on the parent.
        $rows = BillingReconciliationItem::with('reconciliation', 'folio')
            ->whereBetween('document_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('document_date')->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'recon' => optional($r->reconciliation)->reconciliation_no,
                'folio' => $r->folio_no,
                'document' => $r->document_no,
                'type' => ucfirst((string) $r->document_type),
                'date' => $r->document_date?->format('d-m-Y'),
                'customer' => $r->customer_name,
                'amount' => (float) $r->document_amount,
                'city_ledger' => (float) $r->city_ledger_amount,
                'review' => ucfirst((string) $r->review_status),
                'source' => ucfirst((string) $r->source_status),
            ]);
        return ['cols' => ['recon' => 'Reconciliation', 'folio' => 'Folio', 'document' => 'Document', 'type' => 'Type', 'date' => 'Date', 'customer' => 'Customer', 'amount' => 'Amount', 'city_ledger' => 'City Ledger', 'review' => 'Review', 'source' => 'Source'], 'rows' => $rows, 'money' => ['amount', 'city_ledger'], 'sum' => ['amount', 'city_ledger'], 'note' => 'Billing vs folio reconciliation line items.'];
    }

    protected function r_billing_reconciliations($from, $to): array
    {
        $rows = BillingReconciliation::with('user')->whereBetween('reconciled_at', [$from, $to])->get()->map(fn ($r) => [
            'name' => $r->name, 'period' => $r->period_start . ' to ' . $r->period_end,
            'billing_total' => $r->billing_total, 'folio_total' => $r->folio_total, 'variance' => $r->variance,
            'items' => $r->item_count, 'status' => $r->status, 'reconciled' => $r->reconciled_at, 'user' => optional($r->user)->name,
        ]);
        return ['cols' => ['name' => 'Name', 'period' => 'Period', 'billing' => 'Billing Total', 'folio' => 'Folio Total', 'variance' => 'Variance', 'items' => 'Items', 'status' => 'Status', 'reconciled' => 'Reconciled'], 'rows' => $rows, 'money' => ['billing_total', 'folio_total', 'variance'], 'sum' => ['billing_total', 'folio_total', 'variance'], 'note' => 'Billing reconciliation summaries with variance totals.'];
    }

    protected function r_close_events($from, $to): array
    {
        $rows = CloseEvent::with('period.closeType')->whereBetween('closed_at', [$from, $to])->get()->map(fn ($r) => [
            'period' => optional($r->period)->name, 'type' => optional($r->closeType)->name,
            'closed' => $r->closed_at, 'user' => optional($r->user)->name,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['period' => 'Period', 'type' => 'Type', 'closed' => 'Closed At', 'user' => 'User', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Period close events with audit trail.'];
    }

    protected function r_contract_production($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->startOfDay()->addDay();
        $pub = \App\Models\RoomType::pluck('base_rate', 'id');
        $stays = \App\Models\ReservationRoom::with('reservation:id,rate_contract_id,status')->whereHas('reservation', fn ($q) => $q->whereNotNull('rate_contract_id'))
            ->whereDate('arrival_date', '<', $t)->whereDate('departure_date', '>', $f)->get()->groupBy(fn ($s) => $s->reservation->rate_contract_id);
        $rows = \App\Models\RateContract::with('company')->orderBy('code')->get()->map(function ($c) use ($stays, $f, $t, $pub) {
            $all = $stays->get($c->id, collect());
            $live = $all->where('status', '!=', 'cancelled')->filter(fn ($s) => $s->reservation->status !== 'cancelled');
            $n = fn ($s) => max(0, (int) max($s->arrival_date, $f)->diffInDays(min($s->departure_date, $t)));
            $nights = $live->sum($n);
            $rev = round($live->sum(fn ($s) => (float) $s->rate * $n($s)), 2);
            $public = round($live->sum(fn ($s) => (float) ($pub[$s->room_type_id] ?? 0) * $n($s)), 2);
            $p = \App\Services\ContractService::production($c);

            return ['code' => $c->code, 'company' => $c->company->name, 'basis' => $c->basisLabel(), 'status' => ucfirst($c->state()),
                'book' => $live->pluck('reservation_id')->unique()->count(), 'nights' => $nights, 'rev' => $rev, 'adr' => $nights ? round($rev / $nights, 2) : 0,
                'pub' => $nights ? round($public / $nights, 2) : 0, 'disc' => $public > 0 ? round((1 - $rev / $public) * 100, 1) : null,
                'cxl' => $all->filter(fn ($s) => $s->status === 'cancelled' || $s->reservation->status === 'cancelled')->pluck('reservation_id')->unique()->count(),
                'target' => $p['target'] ?: null, 'tpct' => $p['target_pct'], 'pace' => $p['pace_pct']];
        })->filter(fn ($r) => $r['nights'] > 0 || $r['cxl'] > 0 || $r['status'] === 'Active')->values();

        return ['cols' => ['code' => 'Contract', 'company' => 'Company', 'basis' => 'Pricing', 'status' => 'Status', 'book' => 'Bookings', 'nights' => 'Room nights', 'rev' => 'Room revenue',
            'adr' => 'ADR', 'pub' => 'Public ADR', 'disc' => 'Discount %', 'cxl' => 'Cancelled', 'target' => 'RN target', 'tpct' => 'Target % (to date)', 'pace' => 'Period elapsed %'],
            'rows' => $rows, 'money' => ['rev', 'adr', 'pub'], 'sum' => ['book', 'nights', 'rev', 'cxl'],
            'note' => 'Room nights and revenue count the nights of each stay that fall in the period (rate × nights, before tax). Public ADR = rack rate of the same room types. Target % uses all bookings on the contract to date.'];
    }

    protected function r_credit_exposure($from, $to): array
    {
        $rows = \App\Services\CreditService::positions()->filter(fn ($p) => $p['company']->is_active || $p['outstanding'] != 0)->sortByDesc('exposure')
            ->map(fn ($p) => ['co' => $p['company']->name, 'type' => ucwords(str_replace('_', ' ', $p['company']->type)),
                'terms' => $p['company']->direct_billing ? $p['company']->credit_days.' days' : 'Pay direct', 'limit' => $p['limit'], 'out' => $p['outstanding'], 'od' => $p['overdue'],
                'odd' => $p['overdue_days'] ?: null, 'pend' => $p['pending'], 'exp' => $p['exposure'], 'avail' => $p['available'], 'used' => $p['used_pct'],
                'status' => $p['company']->onHold() ? 'HOLD'.($p['company']->credit_hold_auto ? ' (auto)' : '') : ($p['over_limit'] ? 'Over limit' : ($p['company']->direct_billing ? 'Open' : 'No credit'))])->values();

        return ['cols' => ['co' => 'Account', 'type' => 'Type', 'terms' => 'Terms', 'limit' => 'Credit limit', 'out' => 'City ledger', 'od' => 'Overdue', 'odd' => 'Oldest overdue (days)',
            'pend' => 'In-house', 'exp' => 'Exposure', 'avail' => 'Available', 'used' => 'Limit used %', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['limit', 'out', 'od', 'pend', 'exp', 'avail'], 'sum' => ['out', 'od', 'pend', 'exp'],
            'note' => 'As on business date '.bdate()->format('d M Y').'. Receipts are applied to the oldest bills first; a bill is overdue once its credit days have passed. Exposure = city ledger + balances of open folios billed to the account.'];
    }

    protected function r_expense_claim_lines($from, $to): array
    {
        // Rewritten against the real schema: lines have date/category/amount/vat_amount and a
        // head (account_heads); the parent claim carries claim_no/submitted_at/status/employee.
        // The previous version referenced columns and relations that never existed.
        $rows = ExpenseClaimLine::with('claim.employee', 'head')
            ->whereHas('claim', fn ($q) => $q->where('submitted_at', '>=', $from->copy()->startOfDay())->where('submitted_at', '<=', $to->copy()->endOfDay()))
            ->get()
            ->sortBy(fn ($r) => [optional($r->claim)->submitted_at, $r->date])
            ->values()
            ->map(fn ($r) => [
                'employee' => optional(optional($r->claim)->employee)->name,
                'type' => $r->category ?: optional($r->head)->name,
                'description' => $r->description,
                'amount' => (float) $r->amount,
                'vat' => (float) $r->vat_amount,
                'date' => $r->date?->format('d-m-Y'),
                'claim' => optional($r->claim)->claim_no,
                'status' => ucfirst((string) optional($r->claim)->status),
            ]);

        return ['cols' => ['employee' => 'Employee', 'type' => 'Type', 'description' => 'Description', 'amount' => 'Amount', 'vat' => 'VAT', 'date' => 'Date', 'claim' => 'Claim Ref', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount', 'vat'], 'sum' => ['amount', 'vat'], 'note' => 'Every expense claim line with amount and VAT.'];
    }

    protected function r_expense_claims($from, $to): array
    {
        $rows = \App\Models\ExpenseClaim::with('employee.department', 'approver', 'paymentMode', 'pettyTxn.float')->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('submitted_at')->get()
            ->map(fn ($c) => ['claim' => $c->claim_no, 'submitted' => $c->submitted_at->format('d M Y'), 'staff' => $c->employee?->name, 'dept' => $c->employee?->department?->name, 'title' => $c->title,
                'total' => (float) $c->total, 'vat' => (float) $c->vat_total ?: null, 'status' => ucfirst($c->status).($c->status === 'rejected' && $c->reject_reason ? ' – '.$c->reject_reason : ''),
                'approver' => in_array($c->status, ['approved', 'paid']) ? $c->approver?->name : '', 'paid' => $c->paid_on?->format('d M Y'),
                'method' => $c->status === 'paid' ? ($c->pay_method === 'petty' ? 'Petty cash – '.$c->pettyTxn?->float?->code : $c->paymentMode?->name) : '']);

        return ['cols' => ['claim' => 'Claim', 'submitted' => 'Submitted', 'staff' => 'Staff', 'dept' => 'Department', 'title' => 'Title', 'total' => 'Amount', 'vat' => 'Input VAT', 'status' => 'Status', 'approver' => 'Approved by', 'paid' => 'Paid', 'method' => 'Paid by'],
            'rows' => $rows, 'money' => ['total', 'vat'], 'sum' => ['total', 'vat'],
            'note' => $rows->count().' claim(s): '.money($rows->where('paid', '!=', null)->sum('total')).' paid, '.money($rows->filter(fn ($r) => str_starts_with($r['status'], 'Approved'))->sum('total')).' approved and unpaid (2260 Staff Expense Claims Payable).'];
    }

    protected function r_expenses($from, $to): array
    {
        $rows = Voucher::with('head', 'paymentMode', 'supplier')->where('type', 'payment')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderBy('date')->get()
            ->map(fn ($v) => ['no' => $v->voucher_no, 'date' => $v->date->format('d-m-Y'), 'head' => $v->head->name, 'party' => $v->supplier?->name ?? $v->party_name, 'mode' => $v->paymentMode?->name, 'narr' => $v->narration, 'amount' => (float) $v->amount]);

        return ['cols' => ['no' => 'Voucher', 'date' => 'Date', 'head' => 'Head', 'party' => 'Party', 'mode' => 'Mode', 'narr' => 'Narration', 'amount' => 'Amount'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'summary' => $rows->groupBy('head')->map->sum('amount')];
    }

    protected function r_petty_cash_book($from, $to): array
    {
        $rows = collect();
        $sumIn = $sumOut = 0;
        foreach (\App\Models\PettyFloat::orderBy('code')->get() as $f) {
            [$open, $txns, $close] = \App\Services\PettyCashService::book($f, $from->toDateString(), $to->toDateString());
            if ($txns->isEmpty() && abs($open) < 0.005) {
                continue;
            }
            $rows->push(['date' => $from->format('d M Y'), 'float' => $f->code, 'no' => '', 'type' => 'Opening balance', 'party' => '', 'account' => '', 'in' => null, 'out' => null, 'vat' => null, 'balance' => $open]);
            foreach ($txns as $t) {
                $e = (float) $t->effect;
                $e > 0 ? $sumIn += $e : $sumOut -= $e;
                $rows->push(['date' => $t->date->format('d M Y'), 'float' => $f->code, 'no' => $t->txn_no, 'type' => $t->typeLabel(), 'party' => trim(($t->party() ?? '').($t->description ? ' – '.\Illuminate\Support\Str::limit($t->description, 50) : ''), ' –'),
                    'account' => $t->head?->label, 'in' => $e > 0 ? $e : null, 'out' => $e < 0 ? -$e : null, 'vat' => (float) $t->vat_amount ?: null, 'balance' => $t->running]);
            }
            $rows->push(['date' => $to->format('d M Y'), 'float' => $f->code, 'no' => '', 'type' => 'Closing balance', 'party' => $f->name, 'account' => '', 'in' => null, 'out' => null, 'vat' => null, 'balance' => $close]);
        }

        return ['cols' => ['date' => 'Date', 'float' => 'Float', 'no' => 'No.', 'type' => 'Type', 'party' => 'Paid to / details', 'account' => 'Account', 'in' => 'In', 'out' => 'Out', 'vat' => 'Input VAT', 'balance' => 'Balance'],
            'rows' => $rows, 'money' => ['in', 'out', 'vat', 'balance'], 'sum' => ['in', 'out', 'vat'],
            'note' => 'Cash in '.money($sumIn).', out '.money($sumOut).'. Pending (unapproved), rejected and cancelled items are left out.'];
    }

    protected function r_petty_counts($from, $to): array
    {
        $rows = PettyCount::with('float.keeper')->whereBetween('counted_at', [$from, $to])->get()->map(fn ($r) => [
            'float' => optional($r->float)->name, 'keeper' => optional($r->keeper)->name,
            'counted' => $r->counted_at, 'expected' => $r->expected_amount, 'actual' => $r->actual_amount,
            'variance' => $r->variance, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['float' => 'Float', 'keeper' => 'Keeper', 'counted' => 'Counted At', 'expected' => 'Expected', 'actual' => 'Actual', 'variance' => 'Variance', 'status' => 'Status'], 'rows' => $rows, 'money' => ['expected_amount', 'actual_amount', 'variance'], 'sum' => ['expected_amount', 'actual_amount', 'variance'], 'note' => 'Petty cash count verifications.'];
    }

    protected function r_petty_expenses($from, $to): array
    {
        $floats = \App\Models\PettyFloat::orderBy('code')->get();
        $txns = \App\Models\PettyTxn::with('head')->where('status', 'posted')->whereIn('type', ['expense', 'settle'])->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get()
            ->filter(fn ($t) => $t->type === 'expense' || (float) $t->spent > 0);
        $rows = $txns->groupBy('expense_head_id')->map(function ($g) use ($floats) {
            $r = ['account' => $g->first()->head?->label ?? '—'];
            foreach ($floats as $f) {
                $r['f'.$f->id] = round((float) $g->where('petty_float_id', $f->id)->sum(fn ($t) => $t->type === 'settle' ? (float) $t->spent : (float) $t->amount), 2) ?: null;
            }
            $r['count'] = $g->count();
            $r['vat'] = round((float) $g->sum('vat_amount'), 2) ?: null;
            $r['total'] = round((float) $g->sum(fn ($t) => $t->type === 'settle' ? (float) $t->spent : (float) $t->amount), 2);

            return $r;
        })->sortByDesc('total')->values();
        $cols = ['account' => 'Expense account'] + $floats->mapWithKeys(fn ($f) => ['f'.$f->id => $f->name])->all() + ['count' => 'Items', 'vat' => 'Input VAT', 'total' => 'Total'];
        $money = array_merge($floats->map(fn ($f) => 'f'.$f->id)->all(), ['vat', 'total']);

        return ['cols' => $cols, 'rows' => $rows, 'money' => $money, 'sum' => array_merge($money, ['count']),
            'note' => 'Gross amounts paid (VAT-inclusive). Expense claims paid from petty cash are in the claims register, not here.'];
    }

    protected function r_petty_replenishments($from, $to): array
    {
        $rows = PettyReplenishment::with('float.keeper')->whereBetween('replenished_at', [$from, $to])->get()->map(fn ($r) => [
            'float' => optional($r->float)->name, 'keeper' => optional($r->keeper)->name,
            'replenished' => $r->replenished_at, 'spent' => $r->amount_spent, 'replenished_amount' => $r->replenishment_amount,
            'vouchers' => $r->voucher_count, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['float' => 'Float', 'keeper' => 'Keeper', 'replenished' => 'Replenished', 'spent' => 'Spent', 'replenished_amt' => 'Replenished', 'vouchers' => 'Vouchers', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount_spent', 'replenishment_amount'], 'sum' => ['amount_spent', 'replenishment_amount'], 'note' => 'Petty cash replenishment register.'];
    }

    protected function r_supplier_paid_history_archives($from, $to): array
    {
        $rows = SupplierPaidHistoryArchive::with('supplier')->whereBetween('archived_at', [$from, $to])->get()->map(fn ($r) => [
            'supplier' => optional($r->supplier)->name, 'archived' => $r->archived_at,
            'invoice' => $r->invoice_no, 'amount' => $r->amount, 'payment_ref' => $r->payment_reference,
            'paid_at' => $r->paid_at, 'method' => $r->payment_method, 'status' => $r->status,
        ]);
        return ['cols' => ['supplier' => 'Supplier', 'archived' => 'Archived', 'invoice' => 'Invoice', 'amount' => 'Amount', 'payment_ref' => 'Payment Ref', 'paid_at' => 'Paid At', 'method' => 'Method', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Archived supplier payment history for audit.'];
    }

    protected function r_uncleared_items($from, $to): array
    {
        $rows = collect();
        foreach ($this->recStatements($to) as [$a, $s]) {
            if (! $s) {
                continue;
            }
            $x = \App\Services\BankRecService::summary($s);
            $end = $s->period_to;
            foreach ($x['U'] as $g) {
                $v = round((float) $g->debit - (float) $g->credit, 2);
                $rows->push(['account' => $a->code.' '.$a->name, 'kind' => $v > 0 ? 'Deposit in transit' : 'Unpresented payment', 'date' => $g->date->format('d M Y'), 'sort' => $g->date->toDateString(),
                    'ref' => $g->journal->journal_no, 'details' => \Illuminate\Support\Str::limit($g->journal->narration, 80), 'amount' => $v, 'days' => (int) $g->date->diffInDays($end), 'statement' => $s->statement_no]);
            }
            foreach ($x['S'] as $l) {
                $rows->push(['account' => $a->code.' '.$a->name, 'kind' => $l->status === 'ignored' ? 'Statement only (ignored)' : 'Statement only', 'date' => $l->date->format('d M Y'), 'sort' => $l->date->toDateString(),
                    'ref' => $l->reference, 'details' => trim($l->description.($l->ignore_reason ? ' – '.$l->ignore_reason : '')), 'amount' => (float) $l->amount, 'days' => (int) $l->date->diffInDays($end), 'statement' => $s->statement_no]);
            }
        }
        $rows = $rows->sortBy([['account', 'asc'], ['kind', 'asc'], ['sort', 'asc']])->values()->map(fn ($r) => \Illuminate\Support\Arr::except($r, 'sort'));

        return ['cols' => ['account' => 'Account', 'kind' => 'Item', 'date' => 'Date', 'ref' => 'Reference', 'details' => 'Details', 'amount' => 'Amount', 'days' => 'Days at statement end', 'statement' => 'Statement'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'note' => $rows->count().' item(s); '.$rows->where('days', '>', 30)->count().' more than 30 days old.'];
    }

}
