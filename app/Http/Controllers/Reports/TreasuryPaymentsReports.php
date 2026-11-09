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
 * Treasury & Payments reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait TreasuryPaymentsReports
{
    protected function r_bank_matches($from, $to): array
    {
        $rows = BankMatch::with('bankTransaction.payment')->whereBetween('matched_at', [$from, $to])->get()->map(fn ($r) => [
            'bank_txn' => optional($r->bankTransaction)->reference, 'payment' => optional($r->bankTransaction->payment)->reference,
            'amount' => $r->amount, 'matched' => $r->matched_at, 'type' => $r->match_type,
            'confidence' => $r->confidence, 'status' => $r->status, 'user' => $r->user_id, 'notes' => $r->notes,
        ]);
        return ['cols' => ['bank_txn' => 'Bank Txn', 'payment' => 'Payment', 'amount' => 'Amount', 'matched' => 'Matched', 'type' => 'Type', 'confidence' => 'Confidence', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Bank transaction matches with payment references.'];
    }

    protected function r_cash_flow($from, $to): array
    {
        $cf = \App\Services\TreasuryService::cashFlow($from->toDateString(), $to->toDateString());
        $rows = collect();
        $h = fn ($t) => $rows->push(['section' => $t, 'line' => '', 'amount' => null, '_class' => 'bold-row']);
        $h('Operating activities');
        $rows->push(['section' => '', 'line' => 'Net profit for the period', 'amount' => $cf['profit']]);
        foreach (array_merge($cf['noncash'], $cf['working']) as $l) {
            $rows->push(['section' => '', 'line' => trim(($l['code'] ? $l['code'].' ' : '').$l['label']), 'amount' => $l['amount']]);
        }
        $rows->push(['section' => '', 'line' => 'Net cash from operating activities', 'amount' => $cf['operating'], '_class' => 'bold-row']);
        foreach (['investing' => 'Investing activities', 'financing' => 'Financing activities'] as $k => $t) {
            $h($t);
            foreach ($cf[$k] as $l) {
                $rows->push(['section' => '', 'line' => trim(($l['code'] ? $l['code'].' ' : '').$l['label']), 'amount' => $l['amount']]);
            }
            $rows->push(['section' => '', 'line' => 'Net cash from '.strtolower($t), 'amount' => $cf[$k.'_total'], '_class' => 'bold-row']);
        }
        $rows->push(['section' => 'Net change in cash', 'line' => '', 'amount' => $cf['net'], '_class' => 'bold-row']);
        $rows->push(['section' => 'Cash at start', 'line' => '', 'amount' => $cf['opening']]);
        $rows->push(['section' => 'Cash at end', 'line' => '', 'amount' => $cf['closing'], '_class' => 'bold-row']);

        return ['cols' => ['section' => 'Section', 'line' => 'Line', 'amount' => 'Amount'], 'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Operating' => $cf['operating'], 'Investing' => $cf['investing_total'], 'Financing' => $cf['financing_total'], 'Net change' => $cf['net'], 'Difference' => $cf['difference']],
            'note' => $this->glNote().' Indirect method (IAS 7). Cash = accounts classed as cash & cash equivalents under Treasury › Bank accounts. Difference must be zero.'];
    }

    protected function r_cash_forecast_lines($from, $to): array
    {
        // Rewritten against the real schema: a line is a recurring direction/category/amount; there is no forecasts table.
        $rows = \App\Models\CashForecastLine::with('creator')->whereBetween('first_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->orderBy('first_date')->get()->map(fn ($r) => [
            'description' => $r->description, 'direction' => ucfirst((string) $r->direction), 'category' => $r->category,
            'amount' => (float) $r->amount, 'first_date' => $r->first_date, 'frequency' => $r->frequency,
            'until_date' => $r->until_date, 'active' => $r->is_active ? 'Yes' : 'No',
            'created_by' => optional($r->creator)->name,
        ]);
        return ['cols' => ['description' => 'Description', 'direction' => 'Direction', 'category' => 'Category', 'amount' => 'Amount', 'first_date' => 'First', 'frequency' => 'Frequency', 'until_date' => 'Until', 'active' => 'Active', 'created_by' => 'Created By'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Cash forecast lines starting in the period.'];
    }

    protected function r_cash_position($from, $to): array
    {
        $p = \App\Services\TreasuryService::position($to->toDateString());
        $TA = \App\Models\TreasuryAccount::class;
        $rows = $p['rows']->map(fn ($r) => ['code' => $r['account']->head?->code, 'account' => $r['account']->name, 'kind' => $TA::KINDS[$r['account']->kind], 'no' => $r['account']->maskedNo(),
            'balance' => $r['balance'], 'min' => (float) $r['account']->min_balance ?: null, 'unpresented' => $r['unpresented'] ?: null, 'rec' => $r['reconciled_to'] ? $r['reconciled_to']->format('d M Y') : '',
            'in' => $r['account']->in_position ? 'Yes' : 'No', 'flag' => $r['overdrawn'] ? 'Overdrawn' : ($r['below_min'] ? 'Below minimum' : ''), '_class' => $r['overdrawn'] || $r['below_min'] ? 'bold-row' : '']);

        return ['cols' => ['code' => 'Code', 'account' => 'Account', 'kind' => 'Type', 'no' => 'Account no.', 'balance' => 'Book balance', 'min' => 'Minimum', 'unpresented' => 'Cheques not presented', 'rec' => 'Reconciled to', 'in' => 'In cash position', 'flag' => 'Flag'],
            'rows' => $rows, 'money' => ['balance', 'min', 'unpresented'],
            'summary' => ['Cash position' => $p['cash'], 'Banks & MoMo' => $p['banks'], 'On hand' => $p['on_hand'], 'In transit' => $p['transit'], 'Clearing (due from acquirers)' => $p['clearing']],
            'note' => 'Book balances from the general ledger as at '.$to->format('d M Y').'.'];
    }

    protected function r_cheque_register($from, $to): array
    {
        $F = $from->toDateString();
        $T = $to->toDateString();
        $CR = \App\Models\ChequeReceived::class;
        $CI = \App\Models\ChequeIssued::class;
        $in = $CR::with('transfer')->whereDate('received_on', '>=', $F)->whereDate('received_on', '<=', $T)->orderBy('received_on')->get()->map(fn ($c) => ['dir' => 'Received', 'date' => $c->received_on->format('d M Y'), 'no' => $c->cheque_no,
            'party' => $c->party(), 'bank' => $c->bank_name, 'cheque_date' => $c->cheque_date->format('d M Y'), 'amount' => (float) $c->amount, 'status' => $CR::STATUSES[$c->status],
            'ref' => trim($c->number.' '.($c->transfer?->number ?? '')), 'note' => $c->bounce_reason, '_class' => $c->status === 'bounced' ? 'bold-row' : '']);
        $out = $CI::with('book.account')->whereDate('cheque_date', '>=', $F)->whereDate('cheque_date', '<=', $T)->orderBy('cheque_date')->get()->map(fn ($c) => ['dir' => 'Written', 'date' => $c->cheque_date->format('d M Y'), 'no' => $c->cheque_no,
            'party' => $c->payee ?: '(spoilt)', 'bank' => $c->book?->account?->name, 'cheque_date' => $c->cheque_date->format('d M Y'), 'amount' => -(float) $c->amount, 'status' => $CI::STATUSES[$c->status],
            'ref' => $c->payment?->payment_no, 'note' => $c->void_reason ?: $c->memo, '_class' => '']);
        $rows = $in->concat($out)->values();

        return ['cols' => ['dir' => 'Direction', 'date' => 'Date', 'no' => 'Cheque no.', 'party' => 'Drawer / payee', 'bank' => 'Bank', 'cheque_date' => 'Cheque date', 'amount' => 'Amount', 'status' => 'Status', 'ref' => 'Reference', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Received' => round($in->sum('amount'), 2), 'Bounced' => round($in->where('status', $CR::STATUSES['bounced'])->sum('amount'), 2), 'Written' => round(-$out->sum('amount'), 2), 'Not presented' => round((float) $CI::where('status', 'issued')->sum('amount'), 2)],
            'note' => 'Received by date received; written by cheque date. Written cheques shown negative.'];
    }

    protected function r_gateway_payouts($from, $to): array
    {
        $rows = GatewayPayout::with('gateway')->whereBetween('requested_at', [$from, $to])->get()->map(fn ($r) => [
            'gateway' => optional($r->gateway)->name, 'requested' => $r->requested_at, 'paid' => $r->paid_at,
            'amount' => $r->amount, 'fee' => $r->fee_amount, 'net' => $r->net_amount,
            'currency' => $r->currency, 'status' => $r->status, 'reference' => $r->reference, 'notes' => $r->notes,
        ]);
        return ['cols' => ['gateway' => 'Gateway', 'requested' => 'Requested', 'paid' => 'Paid', 'amount' => 'Amount', 'fee' => 'Fee', 'net' => 'Net', 'currency' => 'Currency', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount', 'fee', 'net'], 'sum' => ['amount', 'fee', 'net'], 'note' => 'Gateway payout register with fee breakdown.'];
    }

    protected function r_gift_voucher_register($from, $to): array
    {
        $rows = \App\Models\GiftVoucher::with('type', 'paymentMode', 'issuer', 'transactions')->whereDate('issued_on', '>=', $from->toDateString())->whereDate('issued_on', '<=', $to->toDateString())->orderBy('issued_on')->orderBy('id')->get()
            ->map(fn ($v) => ['code' => $v->code, 'type' => $v->type?->name, 'source' => $v->source === 'promo' ? 'Promo'.($v->campaign ? ': '.$v->campaign : '') : 'Sold', 'purchaser' => $v->purchaser_name, 'recipient' => $v->recipient_name,
                'issued' => $v->issued_on->format('d-m-Y'), 'expires' => $v->expires_on->format('d-m-Y'), 'mode' => $v->paymentMode?->name, 'value' => $v->face_value,
                'reloads' => round((float) $v->transactions->where('type', 'reload')->sum('amount'), 2), 'redeemed' => round((float) $v->transactions->where('type', 'redeem')->sum('amount') - (float) $v->transactions->where('type', 'reversal')->sum('amount'), 2),
                'balance' => $v->balance, 'status' => $v->statusLabel(), 'by' => $v->issuer?->name]);
        $m = ['value', 'reloads', 'redeemed', 'balance'];

        return ['cols' => ['code' => 'Code', 'type' => 'Type', 'source' => 'Source', 'purchaser' => 'Purchaser', 'recipient' => 'Recipient', 'issued' => 'Issued', 'expires' => 'Expires', 'mode' => 'Paid by', 'value' => 'Value', 'reloads' => 'Reloads', 'redeemed' => 'Redeemed', 'balance' => 'Balance', 'status' => 'Status', 'by' => 'Issued by'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'summary' => ['Vouchers' => (string) $rows->count(), 'Sold' => money($rows->where('source', 'Sold')->sum('value')), 'Promotional' => money($rows->where('source', '!=', 'Sold')->sum('value')), 'Still outstanding' => money($rows->sum('balance'))]];
    }

    protected function r_online_payment_events($from, $to): array
    {
        // Rewritten against the real schema: payment() relation; provider/event/result/signature_ok; the event payload carries any provider detail.
        $rows = OnlinePaymentEvent::with('payment', 'user')->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->get()->map(fn ($r) => [
            'payment' => optional($r->payment)->pay_no, 'provider' => $r->provider,
            'event' => $r->event, 'source' => $r->source,
            'signature_ok' => $r->signature_ok ? 'Yes' : 'No', 'result' => $r->result,
            'user' => optional($r->user)->name, 'created' => $r->created_at,
        ]);
        return ['cols' => ['payment' => 'Payment', 'provider' => 'Provider', 'event' => 'Event', 'source' => 'Source', 'signature_ok' => 'Signature OK', 'result' => 'Result', 'user' => 'User', 'created' => 'Created'], 'rows' => $rows, 'note' => 'Online payment event log.'];
    }

    protected function r_online_refunds($from, $to): array
    {
        $rows = OnlineRefund::with('reservation.guest.gateway.payment')->whereBetween('refunded_at', [$from, $to])->get()->map(fn ($r) => [
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'gateway' => optional($r->gateway)->name, 'payment_ref' => optional($r->payment)->reference,
            'original' => $r->original_amount, 'refund' => $r->refund_amount, 'fee' => $r->fee_amount,
            'reason' => $r->reason, 'refunded' => $r->refunded_at, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['res' => 'Reservation', 'guest' => 'Guest', 'gateway' => 'Gateway', 'original' => 'Original', 'refund' => 'Refund', 'fee' => 'Fee', 'reason' => 'Reason', 'refunded' => 'Refunded', 'status' => 'Status'], 'rows' => $rows, 'money' => ['original', 'refund', 'fee'], 'sum' => ['refund', 'fee'], 'note' => 'Online payment refunds with gateway fee breakdown.'];
    }

    protected function r_payment_modes($from, $to): array
    {
        // Rewritten against the real schema: name/type/is_active/account_head_id; there is no payment mode type table.
        $rows = PaymentMode::orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'type' => $r->type,
            'account_head_id' => $r->account_head_id,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'type' => 'Type', 'account_head_id' => 'Account Head #', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Payment mode register.'];
    }

    protected function r_transfer_register($from, $to): array
    {
        $TT = \App\Models\TreasuryTransfer::class;
        $rows = $TT::with('from', 'to', 'requester', 'approver')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->orderBy('id')->get()
            ->map(fn ($t) => ['no' => $t->number, 'date' => $t->date->format('d M Y'), 'kind' => $TT::KINDS[$t->kind], 'from' => $t->from?->name, 'to' => $t->to?->name, 'amount' => (float) $t->amount,
                'fee' => (float) $t->fee ?: null, 'variance' => abs((float) $t->variance) >= 0.005 ? (float) $t->variance : null, 'status' => $TT::STATUSES[$t->status],
                'sent' => $t->dispatched_on?->format('d M'), 'credited' => $t->completed_on && $t->status === 'completed' ? $t->completed_on->format('d M') : '',
                'people' => trim(($t->requester?->name ?? '').($t->approver ? ' / '.$t->approver->name : '')), 'slip' => $t->slip_no, '_class' => abs((float) $t->variance) >= 0.005 ? 'bold-row' : '']);
        $done = $rows->where('status', $TT::STATUSES['completed']);

        return ['cols' => ['no' => 'No.', 'date' => 'Date', 'kind' => 'Type', 'from' => 'From', 'to' => 'To', 'amount' => 'Amount', 'fee' => 'Fee', 'variance' => 'Short / over', 'status' => 'Status', 'sent' => 'Sent', 'credited' => 'Credited', 'people' => 'Requested / approved', 'slip' => 'Slip'],
            'rows' => $rows, 'money' => ['amount', 'fee', 'variance'],
            'summary' => ['Transfers' => $rows->count(), 'Completed' => round($done->sum('amount'), 2), 'Fees' => round($rows->sum('fee'), 2), 'Short / over' => round($rows->sum('variance'), 2)],
            'note' => 'Transfers from '.money((float) \App\Services\TreasuryService::cfg('treasury_approval_threshold')).' need a second person\'s approval.'];
    }

    protected function r_voucher_breakage($from, $to): array
    {
        $rows = \App\Models\GiftVoucherTransaction::with('voucher.type', 'paymentMode', 'user')->whereIn('type', ['expire', 'cancel'])
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($t) {
                $promo = $t->voucher?->source === 'promo';
                $kind = $t->type === 'expire' ? ($promo ? 'Promo lapsed' : 'Breakage') : ($t->payment_mode_id ? 'Refunded' : 'Withdrawn');

                return ['date' => $t->business_date->format('d-m-Y'), 'code' => $t->voucher?->code, 'type' => $t->voucher?->type?->name, 'source' => $promo ? 'Promo' : 'Sold', 'kind' => $kind,
                    'reason' => $t->note, 'mode' => $t->paymentMode?->name, 'amount' => $t->amount, 'by' => $t->user?->name ?? 'night audit'];
            });

        return ['cols' => ['date' => 'Date', 'code' => 'Voucher', 'type' => 'Type', 'source' => 'Source', 'kind' => 'Outcome', 'reason' => 'Reason', 'mode' => 'Refunded by', 'amount' => 'Amount', 'by' => 'By'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'],
            'summary' => ['Breakage income (4087)' => money($rows->where('kind', 'Breakage')->sum('amount')), 'Promotional cost released (6150)' => money($rows->whereIn('kind', ['Promo lapsed', 'Withdrawn'])->sum('amount')),
                'Refunded to purchasers' => money($rows->where('kind', 'Refunded')->sum('amount')), 'Vouchers' => (string) $rows->pluck('code')->unique()->count()],
            'note' => 'Breakage on unredeemed vouchers is not a supply of goods or services – no VAT is charged on it.'];
    }

    protected function r_voucher_liability($from, $to): array
    {
        $d = $to->toDateString();
        $bal = \App\Models\GiftVoucherTransaction::whereDate('business_date', '<=', $d)->selectRaw("gift_voucher_id, SUM(CASE WHEN type IN ('issue','reload','reversal') THEN amount ELSE -amount END) b")
            ->groupBy('gift_voucher_id')->havingRaw('ABS(b) > 0.004')->pluck('b', 'gift_voucher_id');
        $rows = \App\Models\GiftVoucher::with('type')->whereIn('id', $bal->keys())->orderBy('expires_on')->get()->map(function ($v) use ($bal, $to) {
            $days = (int) $to->diffInDays($v->expires_on, false);
            $bucket = $days < 0 ? 'Past expiry' : ($days <= 30 ? '0–30 days' : ($days <= 90 ? '31–90 days' : ($days <= 180 ? '91–180 days' : 'Over 180 days')));

            return ['code' => $v->code, 'type' => $v->type?->name, 'source' => $v->source === 'promo' ? 'Promo' : 'Sold', 'holder' => $v->recipient_name ?: $v->purchaser_name, 'issued' => $v->issued_on->format('d-m-Y'),
                'expires' => $v->expires_on->format('d-m-Y'), 'days' => $days, 'bucket' => $bucket, 'status' => $v->statusLabel(), 'balance' => round((float) $bal[$v->id], 2)];
        });
        $gl = 0.0;
        try {
            $gl = -(float) \App\Models\GlLine::where('account_head_id', \App\Models\AccountHead::sys('voucher_liability'))->whereDate('date', '<=', $d)->sum(\DB::raw('debit - credit'));
        } catch (\Throwable) {
        }
        $total = round($rows->sum('balance'), 2);
        $summary = ['Outstanding' => money($total), 'Vouchers' => (string) $rows->count(), 'Sold' => money($rows->where('source', 'Sold')->sum('balance')), 'Promotional' => money($rows->where('source', 'Promo')->sum('balance'))];
        foreach (['0–30 days', '31–90 days', '91–180 days', 'Over 180 days', 'Past expiry'] as $b) {
            if ($x = $rows->where('bucket', $b)->sum('balance')) {
                $summary['Expiring '.$b] = money($x);
            }
        }
        $summary['GL 2120 balance'] = money(round($gl, 2));

        return ['cols' => ['code' => 'Code', 'type' => 'Type', 'source' => 'Source', 'holder' => 'Holder', 'issued' => 'Issued', 'expires' => 'Expires', 'days' => 'Days left', 'bucket' => 'Bucket', 'status' => 'Status', 'balance' => 'Balance'],
            'rows' => $rows, 'money' => ['balance'], 'sum' => ['balance'], 'summary' => $summary,
            'note' => 'Balances rebuilt from voucher transactions up to the date. GL 2120 includes only days already posted to the ledger – '.$this->glNote().' Promotional vouchers are carried at face value against 6150 Promotional Vouchers.'];
    }

    protected function r_voucher_redemptions($from, $to): array
    {
        $rows = \App\Models\GiftVoucherTransaction::with('voucher.type', 'user', 'folioTransaction.folio', 'posOrder')->whereIn('type', ['redeem', 'reversal'])
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(fn ($t) => ['date' => $t->business_date->format('d-m-Y'), 'code' => $t->voucher?->code, 'type' => $t->voucher?->type?->name, 'movement' => $t->type === 'redeem' ? 'Redeemed' : 'Reversed',
                'where' => $t->outlet ?: ($t->folio_transaction_id ? 'Front office' : '—'), 'doc' => $t->folioTransaction?->folio?->folio_no ?? $t->posOrder?->bill_no ?? $t->reference,
                'amount' => $t->type === 'redeem' ? $t->amount : -$t->amount, 'balance' => $t->balance_after, 'by' => $t->user?->name, 'note' => $t->note]);
        $summary = ['Net redeemed' => money($rows->sum('amount')), 'Redemptions' => (string) $rows->where('movement', 'Redeemed')->count(), 'Reversals' => (string) $rows->where('movement', 'Reversed')->count()];
        foreach ($rows->groupBy('where') as $w => $g) {
            $summary[$w] = money($g->sum('amount'));
        }

        return ['cols' => ['date' => 'Date', 'code' => 'Voucher', 'type' => 'Type', 'movement' => 'Movement', 'where' => 'Where', 'doc' => 'Folio / bill', 'amount' => 'Amount', 'balance' => 'Balance after', 'by' => 'By', 'note' => 'Note'],
            'rows' => $rows, 'money' => ['amount', 'balance'], 'sum' => ['amount'], 'summary' => $summary];
    }

    protected function r_voucher_sales($from, $to): array
    {
        $tx = \App\Models\GiftVoucherTransaction::with('voucher', 'paymentMode')->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->get();
        $rows = \App\Models\GiftVoucherType::orderBy('name')->get()->map(function ($type) use ($tx) {
            $t = $tx->filter(fn ($x) => $x->voucher?->gift_voucher_type_id === $type->id);
            $issue = $t->where('type', 'issue');

            return ['type' => $type->name, 'kind' => \App\Models\GiftVoucherType::KINDS[$type->kind] ?? $type->kind,
                'sold_n' => $issue->filter(fn ($x) => $x->voucher->source === 'sold')->count(), 'sold' => round($issue->filter(fn ($x) => $x->voucher->source === 'sold')->sum('amount'), 2),
                'promo_n' => $issue->filter(fn ($x) => $x->voucher->source === 'promo')->count(), 'promo' => round($issue->filter(fn ($x) => $x->voucher->source === 'promo')->sum('amount'), 2),
                'reloads' => round($t->where('type', 'reload')->sum('amount'), 2), 'redeemed' => round($t->where('type', 'redeem')->sum('amount') - $t->where('type', 'reversal')->sum('amount'), 2),
                'lapsed' => round($t->where('type', 'expire')->sum('amount'), 2), 'cancelled' => round($t->where('type', 'cancel')->sum('amount'), 2)];
        })->filter(fn ($r) => $r['sold_n'] + $r['promo_n'] || $r['reloads'] || $r['redeemed'] || $r['lapsed'] || $r['cancelled'])->values();
        $m = ['sold', 'promo', 'reloads', 'redeemed', 'lapsed', 'cancelled'];
        $summary = ['Cash in (sales + reloads)' => money($rows->sum('sold') + $rows->sum('reloads')), 'Given away' => money($rows->sum('promo')), 'Redeemed' => money($rows->sum('redeemed'))];
        foreach ($tx->whereIn('type', ['issue', 'reload'])->whereNotNull('payment_mode_id')->groupBy(fn ($x) => $x->paymentMode?->name ?? '—') as $mode => $g) {
            $summary['By '.$mode] = money($g->sum('amount'));
        }

        return ['cols' => ['type' => 'Voucher type', 'kind' => 'Kind', 'sold_n' => 'Sold', 'sold' => 'Value sold', 'promo_n' => 'Promo', 'promo' => 'Promo value', 'reloads' => 'Reloads', 'redeemed' => 'Redeemed', 'lapsed' => 'Lapsed', 'cancelled' => 'Cancelled'],
            'rows' => $rows, 'money' => $m, 'sum' => array_merge($m, ['sold_n', 'promo_n']), 'summary' => $summary];
    }

}
