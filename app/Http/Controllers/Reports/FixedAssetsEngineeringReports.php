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
 * Fixed Assets & Engineering reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait FixedAssetsEngineeringReports
{
    protected function r_asset_categories($from, $to): array
    {
        // Rewritten against the real schema: code/method/life_months/wdv_rate/residual_pct/is_active; there is no parent or asset type.
        $rows = AssetCategory::orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'code' => $r->code, 'method' => ucfirst((string) $r->method),
            'life_months' => $r->life_months, 'wdv_rate' => (float) $r->wdv_rate,
            'residual_pct' => (float) $r->residual_pct, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'code' => 'Code', 'method' => 'Dep Method', 'life_months' => 'Life (months)', 'wdv_rate' => 'WDV %', 'residual_pct' => 'Residual %', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Asset categories with depreciation settings.'];
    }

    protected function r_asset_dep_runs($from, $to): array
    {
        $rows = AssetDepRun::with('fiscalYear')->whereBetween('run_date', [$from, $to])->get()->map(fn ($r) => [
            'fiscal_year' => optional($r->fiscalYear)->name, 'run_date' => $r->run_date,
            'assets_processed' => $r->assets_processed, 'total_dep' => $r->total_depreciation,
            'status' => $r->status, 'user' => $r->user_id, 'notes' => $r->notes,
        ]);
        return ['cols' => ['fiscal_year' => 'Fiscal Year', 'run_date' => 'Run Date', 'assets' => 'Assets Processed', 'total_dep' => 'Total Depreciation', 'status' => 'Status'], 'rows' => $rows, 'money' => ['total_depreciation'], 'sum' => ['total_depreciation'], 'note' => 'Fixed asset depreciation run summaries.'];
    }

    protected function r_asset_register($from, $to): array
    {
        $d = $to->toDateString();
        $runs = $this->assetAcc($d);
        $rows = \App\Models\Asset::with('category', 'room', 'custodian')->whereDate('purchase_date', '<=', $d)
            ->where(fn ($q) => $q->whereNull('disposed_date')->orWhereDate('disposed_date', '>', $d))->orderBy('asset_no')->get()
            ->map(function ($a) use ($runs) {
                $acc = round((float) $a->opening_acc_dep + (float) ($runs[$a->id] ?? 0), 2);

                return ['no' => $a->asset_no, 'name' => $a->name, 'category' => $a->category?->name, 'location' => $a->placeLabel(), 'dept' => $a->department, 'serial' => $a->serial_no,
                    'purchased' => $a->purchase_date->format('d-m-Y'), 'method' => $a->method === 'wdv' ? 'WDV '.(float) $a->wdv_rate.'%' : 'SLM '.$a->life_months.'m',
                    'cost' => (float) $a->cost, 'acc' => $acc, 'nbv' => round((float) $a->cost - $acc, 2), 'warranty' => $a->warranty_until?->format('d-m-Y'), 'custodian' => $a->custodian?->name];
            })->values();

        return ['cols' => ['no' => 'Asset no', 'name' => 'Asset', 'category' => 'Category', 'location' => 'Location', 'dept' => 'Department', 'serial' => 'Serial', 'purchased' => 'Purchased',
            'method' => 'Method', 'cost' => 'Cost', 'acc' => 'Acc. depreciation', 'nbv' => 'Net book value', 'warranty' => 'Warranty to', 'custodian' => 'Custodian'],
            'rows' => $rows, 'money' => ['cost', 'acc', 'nbv'], 'sum' => ['cost', 'acc', 'nbv'],
            'summary' => ['Assets' => (string) $rows->count(), 'Cost' => money($rows->sum('cost')), 'Accumulated depreciation' => money($rows->sum('acc')), 'Net book value' => money($rows->sum('nbv'))],
            'note' => 'As at '.$to->format('d M Y').'. Accumulated depreciation = amount brought in at go-live + depreciation runs posted for months ending on or before this date.'];
    }

    protected function r_asset_schedule($from, $to): array
    {
        $f = $from->toDateString();
        $t = $to->toDateString();
        $before = $from->copy()->subDay()->toDateString();
        $accOpen = $this->assetAcc($before);
        $charge = $this->assetAcc($t, $before);
        $rows = \App\Models\Asset::with('category')->whereDate('purchase_date', '<=', $t)
            ->where(fn ($q) => $q->whereNull('disposed_date')->orWhereDate('disposed_date', '>=', $f))->orderBy('asset_no')->get()
            ->map(function ($a) use ($f, $t, $accOpen, $charge) {
                $isNew = $a->purchase_date->toDateString() >= $f;
                $gone = $a->disposed_date && $a->disposed_date->toDateString() <= $t;
                $openCost = $isNew ? 0.0 : (float) $a->cost;
                $add = $isNew ? (float) $a->cost : 0.0;
                $dispCost = $gone ? (float) $a->cost : 0.0;
                $openAcc = $isNew ? 0.0 : round((float) $a->opening_acc_dep + (float) ($accOpen[$a->id] ?? 0), 2);
                $chg = round((float) ($charge[$a->id] ?? 0) + ($isNew ? (float) $a->opening_acc_dep : 0), 2);
                $dispAcc = $gone ? round($openAcc + $chg, 2) : 0.0;
                $closeCost = round($openCost + $add - $dispCost, 2);
                $closeAcc = round($openAcc + $chg - $dispAcc, 2);

                return ['no' => $a->asset_no, 'name' => $a->name, 'category' => $a->category?->name, 'open_cost' => $openCost, 'additions' => $add, 'disposals' => $dispCost,
                    'close_cost' => $closeCost, 'open_acc' => $openAcc, 'charge' => $chg, 'disp_acc' => $dispAcc, 'close_acc' => $closeAcc, 'nbv' => round($closeCost - $closeAcc, 2),
                    'gain' => $gone ? round((float) $a->disposal_proceeds - (float) $a->disposal_nbv, 2) : 0.0];
            })->values();
        $m = ['open_cost', 'additions', 'disposals', 'close_cost', 'open_acc', 'charge', 'disp_acc', 'close_acc', 'nbv', 'gain'];

        return ['cols' => ['no' => 'Asset no', 'name' => 'Asset', 'category' => 'Category', 'open_cost' => 'Opening cost', 'additions' => 'Additions', 'disposals' => 'Disposals',
            'close_cost' => 'Closing cost', 'open_acc' => 'Opening acc. dep.', 'charge' => 'Charge for period', 'disp_acc' => 'On disposals', 'close_acc' => 'Closing acc. dep.',
            'nbv' => 'Closing NBV', 'gain' => 'Gain / (loss)'], 'rows' => $rows, 'money' => $m, 'sum' => $m,
            'note' => 'Charge for the period = depreciation runs posted for months ending in the range (for assets added in the period, it also includes any depreciation brought in). Closing cost and closing accumulated depreciation tie to accounts 1510 and 1590 on the balance sheet.'];
    }

    protected function r_pm_schedules($from, $to): array
    {
        $rows = PmSchedule::with('asset.assetCategory')->whereBetween('scheduled_date', [$from, $to])->get()->map(fn ($r) => [
            'asset' => optional($r->asset)->name, 'category' => optional(optional($r->asset)->assetCategory)->name,
            'scheduled' => $r->scheduled_date, 'completed' => $r->completed_date,
            'type' => $r->pm_type, 'description' => $r->description, 'cost' => $r->estimated_cost,
            'status' => $r->status, 'technician' => $r->technician_name, 'notes' => $r->notes,
        ]);
        return ['cols' => ['asset' => 'Asset', 'category' => 'Category', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'type' => 'PM Type', 'cost' => 'Est Cost', 'status' => 'Status', 'technician' => 'Technician'], 'rows' => $rows, 'money' => ['estimated_cost'], 'sum' => ['estimated_cost'], 'note' => 'Preventive maintenance schedule with completion tracking.'];
    }

}
