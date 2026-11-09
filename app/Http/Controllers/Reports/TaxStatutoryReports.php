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
 * Tax & Statutory reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait TaxStatutoryReports
{
    protected function r_credit_notes($from, $to): array
    {
        $rows = Folio::with('original:id,invoice_no,invoice_date', 'guest')->where('type', 'credit_note')->whereNotNull('invoice_no')
            ->whereDate('invoice_date', '>=', Carbon::parse($from)->toDateString())->whereDate('invoice_date', '<=', Carbon::parse($to)->toDateString())->orderBy('invoice_date')->orderBy('id')->get()
            ->map(function ($n) {
                $x = $n->status === 'cancelled';
                $c = \App\Services\GstService::componentTotals(\App\Services\GstService::lines($n));
                $t = $n->gstTotals();
                $set = $n->transactions()->whereIn('type', ['payment', 'refund'])->first();

                return ['no' => $n->invoice_no, 'date' => $n->invoice_date->format('d-m-Y'), 'inv' => $n->original?->invoice_no, 'invdt' => $n->original?->invoice_date?->format('d-m-Y'),
                    'buyer' => $n->buyer_name ?? $n->guest?->full_name, 'gstin' => $n->buyer_gstin, 'reason' => Folio::NOTE_REASONS[$n->note_reason] ?? $n->note_reason,
                    'taxable' => $x ? 0 : abs($t['taxable']), 'vat' => $x ? 0 : abs($c['vat']), 'levies' => $x ? 0 : abs($c['nhil'] + $c['getfund']), 'tl' => $x ? 0 : abs($c['tl']),
                    'value' => $x ? 0 : abs($t['value']), 'settle' => $set ? ($set->type === 'refund' ? 'Refund' : 'Company account') : '',
                    'status' => $x ? 'CANCELLED' : ($n->irn_status === 'generated' ? 'issued · E-VAT stamped' : 'issued')];
            });
        $m = ['taxable', 'vat', 'levies', 'tl', 'value'];

        return ['cols' => ['no' => 'Credit note', 'date' => 'Date', 'inv' => 'Invoice', 'invdt' => 'Invoice date', 'buyer' => 'Buyer', 'gstin' => 'TIN', 'reason' => 'Reason', 'taxable' => 'Taxable', 'vat' => 'VAT', 'levies' => 'NHIL + GETFund', 'tl' => 'Tourism levy', 'value' => 'Value', 'settle' => 'Settled by', 'status' => 'Status'],
            'rows' => $rows->values(), 'money' => $m, 'sum' => $m, 'note' => 'Cancelled credit notes are listed with zero value.'];
    }

    protected function r_einvoice_logs($from, $to): array
    {
        // Rewritten against the real schema: doc_no/action/driver/ok/error_code/message/duration_ms; the e-invoice driver is on the log row itself.
        $rows = EinvoiceLog::with('folio.guest', 'user')->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->get()->map(fn ($r) => [
            'folio' => optional($r->folio)->folio_no, 'guest' => optional(optional($r->folio)->guest)->name,
            'doc_no' => $r->doc_no, 'action' => $r->action, 'driver' => $r->driver,
            'ok' => $r->ok ? 'Yes' : 'No', 'error_code' => $r->error_code,
            'duration_ms' => $r->duration_ms, 'created' => $r->created_at,
        ]);
        return ['cols' => ['folio' => 'Folio', 'guest' => 'Guest', 'doc_no' => 'Doc No', 'action' => 'Action', 'driver' => 'Driver', 'ok' => 'OK', 'error_code' => 'Error', 'duration_ms' => 'ms', 'created' => 'Created'], 'rows' => $rows, 'note' => 'E-invoice creation log.'];
    }

    protected function r_einvoice_register($from, $to): array
    {
        $rows = Folio::whereNotNull('invoice_no')->whereIn('status', ['closed', 'cancelled'])
            ->whereDate('invoice_date', '>=', Carbon::parse($from)->toDateString())->whereDate('invoice_date', '<=', Carbon::parse($to)->toDateString())->orderBy('invoice_date')->orderBy('id')->get()
            ->map(function ($f) {
                $t = $f->gstTotals();

                return ['no' => $f->invoice_no, 'typ' => $f->isCreditNote() ? 'REFUND' : 'INVOICE', 'date' => $f->invoice_date->format('d-m-Y'), 'sup' => $f->supply_type ?: 'B2C', 'gstin' => $f->buyer_gstin, 'buyer' => $f->buyer_name,
                    'taxable' => abs($t['taxable']), 'tax' => abs($t['tax']), 'value' => abs($t['value']),
                    'status' => $f->status === 'cancelled' ? 'document cancelled' : (['generated' => 'stamped'][$f->irn_status] ?? ($f->irn_status ?? 'not stamped')),
                    'ack' => $f->irn_ack_no ? $f->irn_ack_no.' · '.$f->irn_ack_at?->format('d-m-Y H:i') : '',
                    'irn' => $f->irn ?: \Illuminate\Support\Str::limit((string) $f->irn_error, 80)];
            });
        $gen = $rows->where('status', 'stamped')->count();

        return ['cols' => ['no' => 'Document', 'typ' => 'Flag', 'date' => 'Date', 'sup' => 'Supply', 'gstin' => 'Buyer TIN', 'buyer' => 'Buyer', 'taxable' => 'Taxable', 'tax' => 'Taxes', 'value' => 'Value', 'status' => 'E-VAT status', 'ack' => 'Receipt no. / time', 'irn' => 'SDC ID / error'],
            'rows' => $rows->values(), 'money' => ['taxable', 'tax', 'value'], 'sum' => ['taxable', 'tax', 'value'],
            'note' => $rows->count().' document(s) · '.$gen.' stamped by E-VAT · '.$rows->whereIn('status', ['failed', 'pending', 'not stamped'])->count().' not stamped.'.(\App\Services\EInvoiceService::enabled() ? '' : ' E-VAT is switched off.')];
    }

    protected function r_gst_cancelled_docs($from, $to): array
    {
        $rows = GstCancelledDoc::with('gstPeriod.documentType')->whereBetween('cancelled_at', [$from, $to])->get()->map(fn ($r) => [
            'period' => optional($r->gstPeriod)->name, 'type' => optional($r->documentType)->name,
            'cancelled' => $r->cancelled_at, 'document' => $r->document_no, 'amount' => $r->amount,
            'reason' => $r->cancellation_reason, 'status' => $r->status,
        ]);
        return ['cols' => ['period' => 'Period', 'type' => 'Type', 'cancelled' => 'Cancelled', 'document' => 'Document', 'amount' => 'Amount', 'reason' => 'Reason', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'GST cancelled document register.'];
    }

    protected function r_gst_periods($from, $to): array
    {
        $rows = GstPeriod::with('gstRegistration')->whereBetween('period_start', [$from, $to])->get()->map(fn ($r) => [
            'name' => $r->name, 'registration' => optional($r->gstRegistration)->name,
            'period_start' => $r->period_start, 'period_end' => $r->period_end,
            'output_tax' => $r->output_tax, 'input_tax' => $r->input_tax, 'net_tax' => $r->net_tax,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['name' => 'Name', 'registration' => 'Registration', 'period_start' => 'Start', 'period_end' => 'End', 'output_tax' => 'Output Tax', 'input_tax' => 'Input Tax', 'net_tax' => 'Net Tax', 'status' => 'Status'], 'rows' => $rows, 'money' => ['output_tax', 'input_tax', 'net_tax'], 'sum' => ['output_tax', 'input_tax', 'net_tax'], 'note' => 'GST period summaries.'];
    }

    protected function r_hsn_summary($from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();
        $acc = [];
        $keys = ['taxable', 'vat', 'nhil', 'getfund', 'tl', 'total'];
        $add = function (string $tab, array $l) use (&$acc, $keys) {
            $k = $l['desc'].'|'.$l['rate'].'|'.$tab;
            $acc[$k] ??= ['desc' => $l['desc'], 'tab' => $tab, 'rate' => $l['rate'], 'n' => 0] + array_fill_keys($keys, 0.0);
            $acc[$k]['n'] += $l['count'] ?? 1;
            foreach ($keys as $x) {
                $acc[$k][$x] = round($acc[$k][$x] + $l[$x], 2);
            }
        };
        foreach (Folio::where('status', 'closed')->whereNotNull('invoice_no')->whereDate('invoice_date', '>=', $f)->whereDate('invoice_date', '<=', $t)->get() as $d) {
            foreach (\App\Services\GstService::lines($d) as $l) {
                $add($d->supply_type === 'B2B' ? 'B2B' : 'B2C', $l);
            }
        }
        foreach (\App\Services\GstService::posLinesBetween($f, $t) as $p) {
            $add('B2C', ['desc' => 'Restaurant bills', 'total' => round($p['taxable'] + $p['tax'], 2)] + $p);
        }
        $rows = collect($acc)->sortBy(fn ($r) => $r['tab'].$r['desc'].str_pad((string) $r['rate'], 5, '0', STR_PAD_LEFT))->values();
        $m = ['taxable', 'vat', 'nhil', 'getfund', 'tl', 'total'];

        return ['cols' => ['tab' => 'Supply', 'desc' => 'Category', 'rate' => 'Rate %', 'n' => 'Lines', 'taxable' => 'Taxable value', 'vat' => 'VAT', 'nhil' => 'NHIL', 'getfund' => 'GETFund', 'tl' => 'Tourism levy', 'total' => 'Total value'],
            'rows' => $rows, 'money' => $m, 'sum' => $m,
            'note' => 'Invoices and credit notes (negative) by document date, plus restaurant bills settled at the outlet. Tax rates are set in Masters › Taxes.'];
    }

    protected function r_wht_periods($from, $to): array
    {
        $rows = WhtPeriod::with('whtRegistration')->whereBetween('period_start', [$from, $to])->get()->map(fn ($r) => [
            'name' => $r->name, 'registration' => optional($r->whtRegistration)->name,
            'period_start' => $r->period_start, 'period_end' => $r->period_end,
            'tax_deducted' => $r->tax_deducted, 'tax_paid' => $r->tax_paid, 'net_due' => $r->net_tax_due,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['name' => 'Name', 'registration' => 'Registration', 'period_start' => 'Start', 'period_end' => 'End', 'tax_deducted' => 'Tax Deducted', 'tax_paid' => 'Tax Paid', 'net_due' => 'Net Due', 'status' => 'Status'], 'rows' => $rows, 'money' => ['tax_deducted', 'tax_paid', 'net_tax_due'], 'sum' => ['tax_deducted', 'tax_paid', 'net_tax_due'], 'note' => 'WHT period summaries.'];
    }

    /** v4.4 */
    protected function r_wht_register($from, $to): array
    {
        $rows = \App\Models\SupplierPayment::with('supplier:id,name,tax_number')->whereNull('cancelled_at')
            ->whereDate('date', '>=', Carbon::parse($from)->toDateString())->whereDate('date', '<=', Carbon::parse($to)->toDateString())->orderBy('date')->orderBy('id')->get()
            ->map(fn ($p) => ['date' => $p->date->format('d-m-Y'), 'no' => $p->payment_no, 'supplier' => $p->supplier?->name, 'tin' => $p->supplier?->tax_number,
                'nature' => \App\Services\WhtService::label($p->wht_category), 'gross' => (float) $p->amount, 'base' => (float) $p->wht_base,
                'rate' => (float) $p->wht_amount > 0 ? rtrim(rtrim(number_format($p->wht_rate, 2), '0'), '.').'%' : '', 'wht' => (float) $p->wht_amount, 'wvat' => (float) $p->wvat_amount,
                'net' => $p->netPaid(), 'cert' => $p->wht_cert_no ?: (string) $p->wht_note]);

        return ['cols' => ['date' => 'Date', 'no' => 'Payment', 'supplier' => 'Supplier', 'tin' => 'Supplier TIN', 'nature' => 'Nature of payment', 'gross' => 'Gross settled', 'base' => 'Taxable (excl. VAT)', 'rate' => 'Rate', 'wht' => 'WHT', 'wvat' => 'Withholding VAT', 'net' => 'Net paid', 'cert' => 'Certificate / note'],
            'rows' => $rows->values(), 'money' => ['gross', 'base', 'wht', 'wvat', 'net'], 'sum' => ['gross', 'base', 'wht', 'wvat', 'net'],
            'note' => $rows->count().' payment(s) · '.money($rows->sum('wht')).' withheld. WHT is due to GRA by the 15th of the following month (Accounts › Withholding Tax).'];
    }

}
