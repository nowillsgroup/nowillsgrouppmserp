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
 * F&B & POS reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait FnBPosReports
{
    protected function r_banquet_event_items($from, $to): array
    {
        // banquet_event_items links to banquet_functions (which carries the date and
        // belongs to a banquet_events record) — the previous version queried
        // 'event.event_date' as a column path, which never resolved and 500'd.
        $rows = BanquetEventItem::with('function.event', 'service')
            ->get()
            ->sortBy(fn ($r) => optional($r->function)->date)
            ->values()
            ->map(fn ($r) => [
                'event' => optional(optional($r->function)->event)->title,
                'date' => optional($r->function)->date?->format('d-m-Y'),
                'item' => optional($r->service)->name ?? $r->description,
                'quantity' => $r->qty,
                'unit_price' => $r->unit_price,
                'amount' => round(((float) $r->qty) * ((float) $r->unit_price), 2),
                'tax_rate' => $r->tax_rate,
            ]);

        return ['cols' => ['event' => 'Event', 'date' => 'Function date', 'item' => 'Item / description', 'quantity' => 'Qty', 'unit_price' => 'Unit price', 'amount' => 'Total', 'tax_rate' => 'Tax rate %'], 'rows' => $rows, 'money' => ['unit_price', 'amount'], 'sum' => ['quantity', 'amount'], 'note' => 'Every item line on a banquet event, with its function date.'];
    }

    protected function r_banquet_packages($from, $to): array
    {
        // Rewritten against the real schema: type/style/price_per_pax/min_pax/items/is_active/sort.
        $rows = BanquetPackage::orderBy('sort')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'type' => $r->type, 'style' => $r->style,
            'price_pp' => (float) $r->price_per_pax, 'min_pax' => $r->min_pax,
            'tax_rate' => (float) $r->tax_rate,
            'includes' => implode(', ', $r->itemList()),
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'type' => 'Type', 'style' => 'Style', 'price_pp' => 'Price/Pax', 'min_pax' => 'Min Pax', 'tax_rate' => 'Tax %', 'includes' => 'Includes', 'active' => 'Active'], 'rows' => $rows, 'money' => ['price_pp'], 'note' => 'Banquet package catalog with pricing and capacity.'];
    }

    protected function r_banquet_services($from, $to): array
    {
        // Rewritten against the real schema: category/unit/price/tax_rate/department_id/is_active.
        $rows = BanquetService::with('department')->orderBy('category')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'category' => $r->category, 'unit' => $r->unit,
            'price' => (float) $r->price, 'tax_rate' => (float) $r->tax_rate,
            'department' => optional($r->department)->name, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'category' => 'Category', 'unit' => 'Unit', 'price' => 'Price', 'tax_rate' => 'Tax %', 'department' => 'Department', 'active' => 'Active'], 'rows' => $rows, 'money' => ['price'], 'sum' => ['price'], 'note' => 'Banquet service price list.'];
    }

    protected function r_fb_cost_snapshots($from, $to): array
    {
        $rows = FbCostSnapshot::with('outlet.period')->whereBetween('snapshot_date', [$from, $to])->get()->map(fn ($r) => [
            'outlet' => optional($r->outlet)->name, 'date' => $r->snapshot_date, 'period' => optional($r->period)->name,
            'revenue' => $r->revenue, 'food_cost' => $r->food_cost, 'beverage_cost' => $r->beverage_cost,
            'labor_cost' => $r->labor_cost, 'total_cost' => $r->total_cost, 'cost_pct' => $r->cost_percentage,
            'guest_count' => $r->guest_count, 'avg_check' => $r->average_check,
        ]);
        return ['cols' => ['outlet' => 'Outlet', 'date' => 'Date', 'revenue' => 'Revenue', 'food_cost' => 'Food Cost', 'bev_cost' => 'Beverage Cost', 'labor' => 'Labor', 'total_cost' => 'Total Cost', 'cost_pct' => 'Cost %', 'guests' => 'Guests', 'avg_check' => 'Avg Check'], 'rows' => $rows, 'money' => ['revenue', 'food_cost', 'beverage_cost', 'labor_cost', 'total_cost', 'average_check'], 'sum' => ['revenue', 'food_cost', 'beverage_cost', 'labor_cost', 'total_cost'], 'note' => 'Daily F&B cost snapshots with cost percentage.'];
    }

    protected function r_fb_recipe_cards($from, $to): array
    {
        $rows = FbRecipeCard::with('menuItem.outlet')->get()->map(fn ($r) => [
            'item' => optional($r->menuItem)->name, 'outlet' => optional($r->outlet)->name,
            'yield' => $r->yield_pct, 'prep_time' => $r->prep_time_minutes, 'cost' => $r->cost_per_unit,
            'status' => $r->status, 'version' => $r->version, 'last_updated' => $r->updated_at,
            'notes' => $r->notes,
        ]);
        return ['cols' => ['item' => 'Item', 'outlet' => 'Outlet', 'yield' => 'Yield %', 'prep_time' => 'Prep Time', 'cost' => 'Cost/Unit', 'version' => 'Version', 'last_updated' => 'Updated', 'status' => 'Status'], 'rows' => $rows, 'money' => ['cost_per_unit'], 'note' => 'Recipe card register with costing details.'];
    }

    protected function r_fb_staff_meals($from, $to): array
    {
        $rows = FbStaffMeal::with('employee.outlet')->whereBetween('meal_date', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'outlet' => optional($r->outlet)->name,
            'date' => $r->meal_date, 'meal_type' => $r->meal_type, 'cost' => $r->cost,
            'status' => $r->status, 'shift' => $r->shift_label,
        ]);
        return ['cols' => ['employee' => 'Employee', 'outlet' => 'Outlet', 'date' => 'Date', 'meal_type' => 'Meal Type', 'cost' => 'Cost', 'shift' => 'Shift', 'status' => 'Status'], 'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost'], 'note' => 'Staff meal consumption register.'];
    }

    protected function r_fb_yield_tests($from, $to): array
    {
        $rows = FbYieldTest::with('menuItem.outlet')->whereBetween('test_date', [$from, $to])->get()->map(fn ($r) => [
            'item' => optional($r->menuItem)->name, 'outlet' => optional($r->outlet)->name,
            'date' => $r->test_date, 'raw_weight' => $r->raw_weight, 'cooked_weight' => $r->cooked_weight,
            'yield_pct' => $r->yield_pct, 'cost_raw' => $r->cost_raw, 'cost_cooked' => $r->cost_cooked,
            'status' => $r->status,
        ]);
        return ['cols' => ['item' => 'Item', 'outlet' => 'Outlet', 'date' => 'Date', 'raw_weight' => 'Raw Weight', 'cooked_weight' => 'Cooked Weight', 'yield_pct' => 'Yield %', 'cost_raw' => 'Cost Raw', 'cost_cooked' => 'Cost Cooked', 'status' => 'Status'], 'rows' => $rows, 'money' => ['cost_raw', 'cost_cooked'], 'note' => 'Recipe yield test results.'];
    }

    protected function r_kot_print_jobs($from, $to): array
    {
        $rows = KotPrintJob::with('kotPrinter.outlet')->whereBetween('printed_at', [$from, $to])->get()->map(fn ($r) => [
            'printer' => optional($r->kotPrinter)->name, 'outlet' => optional($r->outlet)->name,
            'printed' => $r->printed_at, 'order_ref' => $r->order_reference, 'items' => $r->item_count,
            'status' => $r->status, 'copies' => $r->copies, 'notes' => $r->notes,
        ]);
        return ['cols' => ['printer' => 'Printer', 'outlet' => 'Outlet', 'printed' => 'Printed', 'order_ref' => 'Order Ref', 'items' => 'Items', 'copies' => 'Copies', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Kitchen order ticket print job log.'];
    }

    protected function r_kot_printers($from, $to): array
    {
        // Rewritten against the real schema: station/role/connection/host per printer; no outlet.kitchen relation.
        $rows = KotPrinter::with('outlet')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'code' => $r->code, 'outlet' => optional($r->outlet)->name ?? 'All outlets',
            'station' => $r->station, 'role' => ucfirst((string) $r->role),
            'connection' => ucfirst((string) $r->connection), 'host' => $r->host, 'port' => $r->port,
            'fallback' => $r->is_fallback ? 'Yes' : 'No', 'active' => $r->is_active ? 'Yes' : 'No', 'notes' => $r->notes,
        ]);
        return ['cols' => ['name' => 'Name', 'code' => 'Code', 'outlet' => 'Outlet', 'station' => 'Station', 'role' => 'Role', 'connection' => 'Connection', 'host' => 'Host', 'port' => 'Port', 'fallback' => 'Fallback', 'active' => 'Active', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Kitchen order ticket printer configuration.'];
    }

    protected function r_menu_categories($from, $to): array
    {
        // Rewritten against the real schema: kitchen_station/fb_class/kot_printer_id; outlets have no parent or floor.
        $rows = MenuCategory::with('outlet', 'printer')->orderBy('sort_order')->get()->map(fn ($r) => [
            'name' => $r->name, 'outlet' => optional($r->outlet)->name,
            'station' => $r->kitchen_station, 'fb_class' => $r->fb_class,
            'printer' => optional($r->printer)->name, 'sort' => $r->sort_order,
        ]);
        return ['cols' => ['name' => 'Name', 'outlet' => 'Outlet', 'station' => 'Station', 'fb_class' => 'F&B Class', 'printer' => 'KOT Printer', 'sort' => 'Sort'], 'rows' => $rows, 'note' => 'Menu category hierarchy.'];
    }

    protected function r_menu_item_recipes($from, $to): array
    {
        $rows = MenuItemRecipe::with('menuItem.outlet')->get()->map(fn ($r) => [
            'item' => optional($r->menuItem)->name, 'outlet' => optional($r->outlet)->name,
            'ingredient' => $r->ingredient_name, 'quantity' => $r->quantity, 'unit' => $r->unit,
            'cost' => $r->cost, 'percentage' => $r->percentage_of_cost, 'sort' => $r->sort_order,
            'status' => $r->status,
        ]);
        return ['cols' => ['item' => 'Item', 'outlet' => 'Outlet', 'ingredient' => 'Ingredient', 'quantity' => 'Qty', 'unit' => 'Unit', 'cost' => 'Cost', 'pct' => 'Pct of Cost', 'status' => 'Status'], 'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost'], 'note' => 'Menu item recipe ingredients with cost breakdown.'];
    }

    protected function r_menu_items($from, $to): array
    {
        // Rewritten against the real schema: code/food_type/tax_rate/is_available; there is no menuCategory relation on MenuItem (it is category()).
        $rows = MenuItem::with('category', 'outlet')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'code' => $r->code, 'category' => optional($r->category)->name,
            'outlet' => optional($r->outlet)->name, 'price' => (float) $r->price,
            'tax_rate' => (float) $r->tax_rate, 'food_type' => $r->food_type,
            'available' => $r->is_available ? 'Yes' : 'No', 'description' => $r->description,
        ]);
        return ['cols' => ['name' => 'Name', 'code' => 'Code', 'category' => 'Category', 'outlet' => 'Outlet', 'price' => 'Price', 'tax_rate' => 'Tax %', 'food_type' => 'Type', 'available' => 'Available', 'description' => 'Description'], 'rows' => $rows, 'money' => ['price'], 'sum' => ['price'], 'note' => 'Full menu item register.'];
    }

    protected function r_menu_modifier_groups($from, $to): array
    {
        $rows = MenuModifierGroup::with('outlet')->get()->map(fn ($r) => [
            'name' => $r->name, 'outlet' => optional($r->outlet)->name,
            'required' => $r->is_required, 'min_select' => $r->minimum_select, 'max_select' => $r->maximum_select,
            'status' => $r->status, 'description' => $r->description,
        ]);
        return ['cols' => ['name' => 'Name', 'outlet' => 'Outlet', 'required' => 'Required', 'min_select' => 'Min Select', 'max_select' => 'Max Select', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Menu modifier group definitions.'];
    }

    protected function r_menu_modifiers($from, $to): array
    {
        $rows = MenuModifier::with('modifierGroup.outlet')->get()->map(fn ($r) => [
            'name' => $r->name, 'group' => optional($r->modifierGroup)->name,
            'outlet' => optional($r->outlet)->name, 'price' => $r->price, 'status' => $r->status,
            'sort' => $r->sort_order, 'description' => $r->description,
        ]);
        return ['cols' => ['name' => 'Name', 'group' => 'Group', 'outlet' => 'Outlet', 'price' => 'Price', 'sort' => 'Sort', 'status' => 'Status'], 'rows' => $rows, 'money' => ['price'], 'note' => 'Menu modifier price list.'];
    }

    protected function r_outlet_service_periods($from, $to): array
    {
        $rows = \App\Models\OutletServicePeriod::with('outlet.serviceType')->whereBetween('service_date', [$from, $to])->get()->map(fn ($r) => [
            'outlet' => optional($r->outlet)->name, 'type' => optional($r->serviceType)->name,
            'date' => $r->service_date, 'start' => $r->start_time, 'end' => $r->end_time,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['outlet' => 'Outlet', 'type' => 'Type', 'date' => 'Date', 'start' => 'Start', 'end' => 'End', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Outlet service period schedule.'];
    }

    protected function r_package_allocation($from, $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $pkgs = \App\Models\Package::orderBy('sort')->orderBy('name')->get();
        $alloc = \App\Models\PackageEntitlement::whereDate('night_date', '>=', $range[0])->whereDate('night_date', '<=', $range[1])->where('status', '!=', 'cancelled')
            ->selectRaw("package_id, SUM(CASE WHEN source='included' THEN amount ELSE 0 END) inc, SUM(CASE WHEN source='addon' THEN amount ELSE 0 END) addon, SUM(qty) covers, COUNT(*) n")
            ->groupBy('package_id')->get()->keyBy('package_id');
        $used = \App\Models\PackageRedemption::whereDate('business_date', '>=', $range[0])->whereDate('business_date', '<=', $range[1])->whereNull('voided_at')
            ->selectRaw("package_id, SUM(amount) used, SUM(covers) cov, SUM(CASE WHEN overage_mode='folio' THEN extra_amount ELSE 0 END) extras, SUM(extra_covers) xcov")
            ->groupBy('package_id')->get()->keyBy('package_id');
        $fixedUsed = \App\Models\PackageEntitlement::whereDate('night_date', '>=', $range[0])->whereDate('night_date', '<=', $range[1])->where('status', 'consumed')->where('is_allowance', false)
            ->selectRaw('package_id, SUM(amount) v, SUM(qty) q')->groupBy('package_id')->get()->keyBy('package_id');
        $brk = \App\Models\PackageEntitlement::whereDate('expired_date', '>=', $range[0])->whereDate('expired_date', '<=', $range[1])->selectRaw('package_id, SUM(breakage_amount) b, SUM(qty - consumed_qty) q')->groupBy('package_id')->get()->keyBy('package_id');
        $open = \App\Models\PackageEntitlement::whereIn('status', ['open', 'partial'])->where('is_allowance', true)->whereDate('night_date', '<=', $range[1])
            ->selectRaw('package_id, SUM(amount - consumed_amount) o')->groupBy('package_id')->get()->keyBy('package_id');
        $rows = $pkgs->map(function ($p) use ($alloc, $used, $brk, $open, $fixedUsed) {
            $a = $alloc[$p->id] ?? null;
            $u = $used[$p->id] ?? null;
            $usedV = (float) ($u->used ?? 0) + (float) ($fixedUsed[$p->id]->v ?? 0);
            $covers = (int) ($a->covers ?? 0);
            $usedQ = (int) ($u->cov ?? 0) + (int) ($fixedUsed[$p->id]->q ?? 0);

            return ['package' => $p->name, 'code' => $p->code, 'type' => $p->is_allowance ? ($p->redeem_mode === 'amount' ? 'Allowance' : 'Covers') : 'Fixed',
                'nights' => (int) ($a->n ?? 0), 'included' => round((float) ($a->inc ?? 0), 2), 'addon' => round((float) ($a->addon ?? 0), 2), 'covers' => $covers,
                'used' => round($usedV, 2), 'breakage' => round((float) ($brk[$p->id]->b ?? 0), 2), 'open' => round((float) ($open[$p->id]->o ?? 0), 2),
                'extras' => round((float) ($u->extras ?? 0), 2), 'takeup' => $covers > 0 ? round(min($usedQ, $covers) * 100 / $covers, 1).'%' : ''];
        })->filter(fn ($r) => $r['nights'] || $r['used'] || $r['breakage'] || $r['extras'] || $r['open'])->values();

        return ['cols' => ['package' => 'Package', 'code' => 'Code', 'type' => 'Type', 'nights' => 'Entitlements', 'included' => 'Allocated from room', 'addon' => 'Add-on sales',
            'covers' => 'Covers', 'used' => 'Used', 'breakage' => 'Breakage', 'open' => 'Open (liability)', 'extras' => 'Extras charged', 'takeup' => 'Take-up'],
            'rows' => $rows, 'money' => ['included', 'addon', 'used', 'breakage', 'open', 'extras'], 'sum' => ['nights', 'included', 'addon', 'covers', 'used', 'breakage', 'open', 'extras'],
            'summary' => ['Allocated from room revenue' => money($rows->sum('included')), 'Breakage' => money($rows->sum('breakage')), 'Open liability' => money(\App\Services\PackageService::openLiability())],
            'note' => 'Allocations by stay night, usage by redemption date, breakage by expiry date. Fixed inclusions (Wi-Fi, transfers) count as used when allocated. The GL posts the same figures daily (source “Package allocation”).'];
    }

    protected function r_package_consumption($from, $to): array
    {
        $rows = \App\Models\PackageRedemption::with('package', 'stay.room', 'stay.guest', 'outlet', 'recorder', 'voider')
            ->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(fn ($r) => ['no' => $r->redemption_no, 'date' => $r->business_date->format('d-m-Y'), 'time' => $r->created_at?->format('H:i'), 'room' => $r->stay?->room?->room_no,
                'guest' => $r->stay?->guest?->full_name, 'package' => $r->package?->name, 'outlet' => $r->outlet?->name, 'covers' => $r->voided_at ? 0 : $r->covers,
                'amount' => $r->voided_at ? 0 : (float) $r->amount, 'extra_covers' => $r->voided_at ? 0 : $r->extra_covers, 'extra' => $r->voided_at ? 0 : (float) $r->extra_amount,
                'settle' => (float) $r->extra_amount > 0 ? \App\Models\PackageRedemption::OVERAGE[$r->overage_mode] : '', 'by' => $r->recorder?->name,
                'status' => $r->voided_at ? 'Voided by '.$r->voider?->name.': '.$r->void_reason : 'OK', '_class' => $r->voided_at ? 'row-red' : ''])->values();

        return ['cols' => ['no' => 'No.', 'date' => 'Date', 'time' => 'Time', 'room' => 'Room', 'guest' => 'Guest', 'package' => 'Package', 'outlet' => 'Outlet', 'covers' => 'Covers',
            'amount' => 'Allowance used', 'extra_covers' => 'Extra covers', 'extra' => 'Extra value', 'settle' => 'Extras settled', 'by' => 'Recorded by', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['amount', 'extra'], 'sum' => ['covers', 'amount', 'extra_covers', 'extra'],
            'summary' => ['Redemptions' => (string) $rows->where('status', 'OK')->count(), 'Covers' => (string) ($rows->sum('covers') + $rows->sum('extra_covers')),
                'Voided' => (string) $rows->where('status', '!=', 'OK')->count()]];
    }

    protected function r_package_forecast($from, $to): array
    {
        $pkgs = \App\Models\Package::where('is_allowance', true)->orderBy('sort')->orderBy('name')->get()->keyBy('id');
        $bd = bdate()->toDateString();
        $rows = collect();
        $actual = \App\Models\PackageEntitlement::whereDate('service_date', '>=', $from->toDateString())->whereDate('service_date', '<=', min($to->toDateString(), bdate()->copy()->subDay()->toDateString()))
            ->where('status', '!=', 'cancelled')->where('is_allowance', true)
            ->selectRaw('service_date, package_id, COUNT(DISTINCT reservation_room_id) rooms, SUM(adults) adults, SUM(children) children, SUM(qty) covers, SUM(consumed_qty) used, SUM(amount) value')
            ->groupBy('service_date', 'package_id')->get();
        foreach ($actual as $a) {
            $rows->push(['date' => Carbon::parse($a->service_date)->format('d-m-Y'), '_d' => Carbon::parse($a->service_date)->toDateString(), 'package' => $pkgs[$a->package_id]->name ?? '#'.$a->package_id,
                'basis' => 'Actual', 'rooms' => (int) $a->rooms, 'adults' => (int) $a->adults, 'children' => (int) $a->children, 'covers' => (int) $a->covers, 'used' => (int) $a->used, 'value' => round((float) $a->value, 2)]);
        }
        if ($to->toDateString() >= $bd) {
            foreach (\App\Services\PackageService::forecast($from->copy()->max(bdate()), $to->copy()) as $d => $cells) {
                foreach ($cells as $pid => $c) {
                    $rows->push(['date' => Carbon::parse($d)->format('d-m-Y'), '_d' => $d, 'package' => $pkgs[$pid]->name ?? '#'.$pid, 'basis' => $d === $bd ? 'Today (on the books)' : 'Forecast',
                        'rooms' => $c['rooms'], 'adults' => $c['adults'], 'children' => $c['children'], 'covers' => $c['covers'], 'used' => $d === $bd ? (int) \App\Models\PackageEntitlement::whereDate('service_date', $d)->where('package_id', $pid)->sum('consumed_qty') : '',
                        'value' => round($c['value'], 2), '_class' => $d === $bd ? 'row-amber' : '']);
                }
            }
        }
        $rows = $rows->sortBy(fn ($r) => $r['_d'].$r['package'])->values()->map(function ($r) {
            unset($r['_d']);

            return $r;
        });

        return ['cols' => ['date' => 'Service date', 'package' => 'Package', 'basis' => 'Basis', 'rooms' => 'Rooms', 'adults' => 'Adults', 'children' => 'Children', 'covers' => 'Covers', 'used' => 'Served', 'value' => 'Value'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rooms', 'covers', 'value'],
            'summary' => ['Covers' => (string) $rows->sum('covers'), 'Forecast days' => (string) $rows->where('basis', 'Forecast')->pluck('date')->unique()->count()],
            'note' => 'Breakfast is counted on the morning after the night that includes it. Forecast rows come from confirmed reservations and in-house stays with the package included in their rate or added on.'];
    }

    protected function r_pos_bills($from, $to): array
    {
        $rows = PosOrder::with('outlet', 'table', 'paymentMode', 'payments.paymentMode', 'stay.room', 'user')->whereIn('status', ['settled', 'void'])->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($o) {
                $tenders = $o->payments->where('kind', 'payment')->map(fn ($p) => $p->paymentMode?->name)->filter()->unique()->join(', ');

                return ['date' => $o->business_date->format('d-m-Y'), 'bill' => $o->bill_no ?? $o->order_no, 'outlet' => $o->outlet->name, 'table' => $o->table?->table_no ?? ($o->stay?->room ? 'Room '.$o->stay->room->room_no : $o->order_type),
                    'covers' => $o->covers, 'sub' => (float) $o->subtotal, 'disc' => (float) $o->discount, 'tax' => (float) $o->tax, 'total' => $o->status === 'void' ? 0 : (float) $o->total,
                    'settle' => $o->status === 'void' ? 'VOID' : ($o->settlement === 'payment' ? ($tenders ?: ($o->paymentMode?->name ?? 'Payment')) : str_replace('_', ' ', $o->settlement)), 'user' => $o->user?->username];
            });

        return ['cols' => ['date' => 'Date', 'bill' => 'Bill', 'outlet' => 'Outlet', 'table' => 'Table / Room', 'covers' => 'Covers', 'sub' => 'Subtotal', 'disc' => 'Discount', 'tax' => 'Tax', 'total' => 'Total', 'settle' => 'Settlement', 'user' => 'User'],
            'rows' => $rows, 'money' => ['sub', 'disc', 'tax', 'total'], 'sum' => ['covers', 'sub', 'disc', 'tax', 'total']];
    }

    protected function r_pos_comps($from, $to): array
    {
        // Keep whole-bill complimentary settlements and item-level complimentary
        // dishes in one audit. A line is represented once in the value totals;
        // when both mechanisms were used, its item-level event wins and the
        // remaining lines carry the whole-bill settlement context.
        $orders = PosOrder::with([
            'outlet', 'table', 'stay.room', 'user', 'settledBy',
            'items.menuItem.category', 'items.compBy',
        ])->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())
            ->whereNotIn('status', ['merged'])
            ->where(function ($q) {
                $q->where('settlement', 'complimentary')
                    ->orWhereHas('items', fn ($items) => $items->where('is_complimentary', true));
            })->orderBy('business_date')->orderBy('id')->get();

        $rows = collect();
        foreach ($orders as $order) {
            $liveItems = $order->items->where('is_cancelled', false)->where('is_pending', false)->values();
            $wholeBill = $order->settlement === 'complimentary';
            if ($wholeBill) {
                $itemComps = $liveItems->where('is_complimentary', true)->values();
                $billItems = $liveItems->where('is_complimentary', false)->values();
                // If both mechanisms were used, retain the item-level audit
                // event and let the bill-level rows cover only the other lines.
                // This preserves both sources without counting a line twice.
                $included = $itemComps->map(fn ($item) => [$item, 'Item'])
                    ->concat($billItems->map(fn ($item) => [$item, 'Whole bill']))->values();
                // Keep the bill-level settlement visible even when every live
                // line was already marked complimentary (or lines were removed).
                if ($billItems->isEmpty()) {
                    $included->push([null, 'Whole bill']);
                }
            } else {
                $included = $liveItems->where('is_complimentary', true)->values()->map(fn ($item) => [$item, 'Item']);
            }

            foreach ($included as [$item, $kind]) {
                $quantity = (float) ($item?->qty ?? 0);
                $rate = (float) ($item?->rate ?? 0);
                $value = round($quantity * $rate, 2);
                $tax = round($value * (float) ($item?->tax_rate ?? 0) / 100, 2);
                $isItemComp = $kind === 'Item';
                $authorizer = $isItemComp ? ($item?->compBy ?? $order->user) : ($order->settledBy ?? $order->user);
                $actionAt = $isItemComp ? ($item?->comp_at ?? $item?->updated_at) : ($order->settled_at ?? $order->updated_at);
                $location = $order->table?->table_no
                    ? 'Table '.$order->table->table_no
                    : ($order->stay?->room ? 'Room '.$order->stay->room->room_no : ucwords(str_replace('_', ' ', (string) $order->order_type)));

                $rows->push([
                    'date' => $order->business_date->format('d-m-Y'),
                    'time' => $actionAt?->format('H:i'),
                    'kind' => $kind,
                    'bill' => $order->bill_no ?? $order->order_no,
                    'order' => $order->order_no,
                    'outlet' => $order->outlet?->name,
                    'location' => $location,
                    'guest' => $order->customer_name,
                    'item' => $item?->item_name ?? ($liveItems->isNotEmpty() ? '(Whole-bill settlement; item lines above)' : '(No live items recorded)'),
                    'cat' => $item?->menuItem?->category?->name,
                    'qty' => $quantity,
                    'rate' => $rate,
                    'value' => $value,
                    'tax' => $tax,
                    'gross' => round($value + $tax, 2),
                    'reason' => $isItemComp ? $item?->comp_reason : $order->comp_reason,
                    'authorized' => $authorizer?->name ?? $authorizer?->username,
                    'cashier' => $order->user?->name ?? $order->user?->username,
                    'action_at' => $actionAt?->format('d-m-Y H:i'),
                    'settlement' => $order->settlement ? ucwords(str_replace('_', ' ', $order->settlement)) : 'Not settled',
                    'status' => ucwords(str_replace('_', ' ', (string) $order->status)),
                ]);
            }
        }

        // The order query is already chronological; preserve that order so dates
        // continue to sort correctly across month boundaries.
        $rows = $rows->values();
        $money = ['rate', 'value', 'tax', 'gross'];

        return [
            'cols' => [
                'date' => 'Business date', 'time' => 'Time', 'kind' => 'Comp type', 'bill' => 'Bill', 'order' => 'Order',
                'outlet' => 'Outlet', 'location' => 'Table / Room', 'guest' => 'Guest', 'item' => 'Item', 'cat' => 'Category',
                'qty' => 'Qty', 'rate' => 'Original rate', 'value' => 'Original value', 'tax' => 'Tax reference', 'gross' => 'Value incl. tax',
                'reason' => 'Reason', 'authorized' => 'Authorised by', 'cashier' => 'Order user', 'action_at' => 'Comp / settlement at',
                'settlement' => 'Settlement', 'status' => 'Order status',
            ],
            'rows' => $rows,
            'money' => $money,
            'sum' => ['qty', 'value', 'tax', 'gross'],
            'summary' => [
                'Rows' => (string) $rows->count(),
                'Item-level rows' => (string) $rows->where('kind', 'Item')->count(),
                'Whole-bill rows' => (string) $rows->where('kind', 'Whole bill')->count(),
                'Units given away' => (string) $rows->sum('qty'),
                'Original value' => money($rows->sum('value')),
            ],
            'note' => 'Original value is quantity × the menu rate before the complimentary adjustment. Whole-bill settlements show each live POS item once. If a bill also contains item-level comp flags, those lines remain Item rows and the other lines are Whole bill rows; no line is counted twice. A zero-value Whole bill row preserves the settlement audit record when every line is item-level or no live lines remain. Tax reference is calculated at each item tax rate and is provided for audit context, not as a tax posting.',
        ];
    }

    /** v4.4 */
    protected function r_pos_evat_register($from, $to): array
    {
        $rows = \App\Models\PosOrder::with('outlet:id,name')->where('status', 'settled')->where('settlement', 'payment')->where('total', '>', 0)
            ->whereDate('business_date', '>=', Carbon::parse($from)->toDateString())->whereDate('business_date', '<=', Carbon::parse($to)->toDateString())->orderBy('business_date')->orderBy('id')->get()
            ->map(function ($o) {
                $l = \App\Services\PosEvatService::lines($o);

                return ['no' => $o->bill_no, 'date' => $o->business_date->format('d-m-Y'), 'outlet' => $o->outlet?->name, 'tin' => $o->buyer_tin, 'buyer' => $o->buyer_name ?: 'Cash customer',
                    'taxable' => round($l->sum('taxable') + (float) $o->service_charge, 2), 'vat' => round($l->sum('vat'), 2), 'levies' => round($l->sum(fn ($x) => $x['nhil'] + $x['getfund'] + $x['tl']), 2),
                    'total' => (float) $o->total, 'status' => ['generated' => 'stamped'][$o->evat_status] ?? ($o->evat_status ?? 'not stamped'),
                    'rec' => $o->evat_rec_num ? $o->evat_rec_num.' · '.$o->evat_at?->format('d-m-Y H:i') : \Illuminate\Support\Str::limit((string) $o->evat_error, 80)];
            });

        return ['cols' => ['no' => 'Bill', 'date' => 'Date', 'outlet' => 'Outlet', 'tin' => 'Customer TIN', 'buyer' => 'Customer', 'taxable' => 'Taxable', 'vat' => 'VAT', 'levies' => 'NHIL + GETFund + Tourism', 'total' => 'Bill total', 'status' => 'E-VAT status', 'rec' => 'Receipt no. / error'],
            'rows' => $rows->values(), 'money' => ['taxable', 'vat', 'levies', 'total'], 'sum' => ['taxable', 'vat', 'levies', 'total'],
            'note' => $rows->count().' bill(s) paid at the outlets · '.$rows->where('status', 'stamped')->count().' stamped by E-VAT. Bills posted to rooms are on the guest invoices.'.(\App\Services\PosEvatService::enabled() ? '' : ' E-VAT for restaurant bills is switched off.')];
    }

    protected function r_pos_items($from, $to): array
    {
        $rows = PosOrderItem::with('order.outlet', 'menuItem.category')->where('is_cancelled', false)->whereHas('order', fn ($q) => $q->where('status', 'settled')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to))->get()
            ->groupBy(fn ($i) => $i->order->outlet->name.'|'.$i->item_name)
            ->map(function ($g, $k) {
                [$o, $n] = explode('|', $k, 2);

                return ['outlet' => $o, 'item' => $n, 'cat' => $g->first()->menuItem?->category?->name, 'qty' => $g->sum('qty'), 'amount' => $g->sum('amount'), 'avg' => $g->sum('qty') ? round($g->sum('amount') / $g->sum('qty'), 2) : 0];
            })->sortByDesc('amount')->values();

        return ['cols' => ['outlet' => 'Outlet', 'item' => 'Item', 'cat' => 'Category', 'qty' => 'Qty sold', 'amount' => 'Amount', 'avg' => 'Avg rate'], 'rows' => $rows, 'money' => ['amount', 'avg'], 'sum' => ['qty', 'amount']];
    }

    protected function r_pos_payments($from, $to): array
    {
        $rows = PosPayment::with('order.outlet', 'paymentMode', 'receiver')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderBy('business_date')->orderBy('id')->get()
            ->map(fn ($p) => ['date' => $p->business_date->format('d-m-Y'), 'time' => $p->received_at?->format('H:i'), 'kind' => $p->kind === 'refund' ? 'REFUND' : 'PAYMENT',
                'bill' => $p->order?->bill_no ?? $p->order?->order_no, 'outlet' => $p->order?->outlet?->name, 'mode' => $p->paymentMode?->name,
                'amount' => $p->kind === 'refund' ? -(float) $p->amount : (float) $p->amount, 'tendered' => (float) ($p->tendered_amount ?? 0),
                'change' => (float) $p->change_amount, 'reference' => $p->reference, 'cheque' => trim(implode(' · ', array_filter([$p->cheque_bank_name, $p->cheque_number, $p->cheque_bank_branch]))) ?: null,
                'authorisation' => $p->card_authorisation, 'mobile' => trim(implode(' · ', array_filter([$p->mobile_network, $p->mobile_phone]))) ?: null,
                'cashier' => $p->receiver?->username ?? $p->receiver?->name, 'notes' => $p->notes]);

        return ['cols' => ['date' => 'Date', 'time' => 'Time', 'kind' => 'Type', 'bill' => 'Bill', 'outlet' => 'Outlet', 'mode' => 'Tender', 'amount' => 'Net amount', 'tendered' => 'Tendered',
            'change' => 'Change', 'reference' => 'Reference', 'cheque' => 'Cheque details', 'authorisation' => 'Card authorisation', 'mobile' => 'Mobile details', 'cashier' => 'Cashier', 'notes' => 'Notes'],
            'rows' => $rows, 'money' => ['amount', 'tendered', 'change'], 'sum' => ['amount', 'tendered', 'change'],
            'note' => 'Payments are positive and refunds are negative. Cash tendered and change are retained for drawer reconciliation.'];
    }

    protected function r_pos_sales($from, $to): array
    {
        $rows = PosOrder::with('outlet')->where('status', 'settled')->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->get()->groupBy('outlet.name')
            ->map(fn ($g, $o) => ['outlet' => $o, 'bills' => $g->count(), 'covers' => $g->sum('covers'), 'gross' => $g->sum('subtotal'), 'discount' => $g->sum('discount'), 'sc' => $g->sum('service_charge'), 'tax' => $g->sum('tax'), 'total' => $g->sum('total'),
                'cash' => $g->where('settlement', 'payment')->sum('total'), 'room' => $g->where('settlement', 'room_post')->sum('total'), 'comp' => $g->where('settlement', 'complimentary')->sum('subtotal'), 'apc' => $g->sum('covers') ? round($g->sum('total') / $g->sum('covers'), 2) : 0])->values();
        $m = ['gross', 'discount', 'sc', 'tax', 'total', 'cash', 'room', 'comp', 'apc'];

        return ['cols' => ['outlet' => 'Outlet', 'bills' => 'Bills', 'covers' => 'Covers', 'gross' => 'Gross', 'discount' => 'Discount', 'sc' => 'Service chg', 'tax' => 'Tax', 'total' => 'Net sales', 'cash' => 'Direct settled', 'room' => 'Posted to room', 'comp' => 'Complimentary', 'apc' => 'Avg / cover'],
            'rows' => $rows, 'money' => $m, 'sum' => ['bills', 'covers', 'gross', 'discount', 'sc', 'tax', 'total', 'cash', 'room', 'comp'], 'chart' => ['label' => 'Net sales', 'key' => 'total', 'x' => 'outlet']];
    }

    protected function r_pos_tables($from, $to): array
    {
        // Rewritten against the real schema: table_no/section/shape/capacity/is_active; outlets have no floor relation.
        $rows = \App\Models\PosTable::with('outlet')->orderBy('table_no')->get()->map(fn ($r) => [
            'table' => $r->table_no, 'outlet' => optional($r->outlet)->name, 'section' => $r->section,
            'capacity' => $r->capacity, 'shape' => $r->shape, 'combinable' => $r->combinable ? 'Yes' : 'No',
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['table' => 'Table', 'outlet' => 'Outlet', 'section' => 'Section', 'capacity' => 'Capacity', 'shape' => 'Shape', 'combinable' => 'Combinable', 'active' => 'Active'], 'rows' => $rows, 'note' => 'POS table/seat configuration.'];
    }

    protected function r_spa_rooms($from, $to): array
    {
        // Rewritten against the real schema: spa_rooms holds only name/is_active.
        $rows = SpaRoom::orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Spa treatment room list.'];
    }

    protected function r_table_bookings($from, $to): array
    {
        $T = \App\Models\TableReservation::class;
        $rows = $this->tbRows($from, $to)->map(function ($r) use ($T) {
            $late = $r->type === 'booking' && $r->seated_at ? (int) round($r->start_at->diffInMinutes($r->seated_at, false)) : null;

            return ['when' => $r->start_at->format('d M Y H:i'), 'ref' => $r->booking_no, 'outlet' => $r->outlet?->name, 'guest' => $r->name, 'pax' => (int) $r->party_size,
                'table' => $r->tableLabel() === '—' ? '' : $r->tableLabel(), 'type' => ['booking' => 'Booking', 'walkin' => 'Walk-in', 'waitlist' => 'Waitlist'][$r->type] ?? $r->type,
                'src' => $T::SOURCES[$r->source] ?? $r->source, 'occ' => $T::OCCASIONS[$r->occasion] ?? '', 'status' => ucwords(str_replace('_', ' ', $r->status)),
                'late' => $late === null ? '' : max(0, $late), 'dined' => $r->dinedMinutes() ?? '', 'bill' => $r->order && $r->order->status !== 'void' ? (float) $r->order->total : 0,
                '_class' => $r->status === 'no_show' ? 'row-alert' : ''];
        })->values();
        $bk = $rows->where('type', 'Booking');
        $due = $bk->whereIn('status', ['Completed', 'Seated', 'No Show']);

        return ['cols' => ['when' => 'Date & time', 'ref' => 'Ref', 'outlet' => 'Outlet', 'guest' => 'Guest', 'pax' => 'Pax', 'table' => 'Table', 'type' => 'Type', 'src' => 'Source',
            'occ' => 'Occasion', 'status' => 'Status', 'late' => 'Min late', 'dined' => 'Min at table', 'bill' => 'Bill'],
            'rows' => $rows, 'money' => ['bill'], 'sum' => ['pax', 'bill'],
            'summary' => ['Parties' => (string) $rows->count(), 'Covers seated' => (string) $rows->whereIn('status', ['Completed', 'Seated'])->sum('pax'),
                'No-show rate' => $due->count() ? round($due->where('status', 'No Show')->count() / $due->count() * 100, 1).'%' : '–',
                'Avg time at table' => $rows->where('dined', '!==', '')->count() ? round($rows->where('dined', '!==', '')->avg('dined')).' min' : '–'],
            'note' => 'By booking date. "Min late" = minutes between the booked time and seating; "Min at table" = seating to bill settlement. No-show rate = no-shows ÷ bookings that were due (cancellations excluded). Bill = the POS order opened when the party was seated.'];
    }

    protected function r_table_covers($from, $to): array
    {
        $all = $this->tbRows($from, $to);
        $seated = fn ($c) => $c->whereIn('status', ['seated', 'completed']);
        $rows = $all->groupBy(fn ($r) => $r->date->toDateString())->map(function ($d, $day) use ($seated) {
            $bk = $d->where('type', 'booking');
            $due = $bk->whereIn('status', ['seated', 'completed', 'no_show']);
            $ns = $bk->where('status', 'no_show');
            $cv = $seated($d)->sum('party_size');

            return ['day' => Carbon::parse($day)->format('D d M Y'), 'bk' => $bk->count(), 'bkc' => (int) $seated($bk)->sum('party_size'), 'wic' => (int) $seated($d->where('type', 'walkin'))->sum('party_size'),
                'wlc' => (int) $seated($d->where('type', 'waitlist'))->sum('party_size'), 'cov' => (int) $cv, 'ns' => $ns->count(), 'nsc' => (int) $ns->sum('party_size'),
                'cx' => $bk->where('status', 'cancelled')->count(), 'nsr' => $due->count() ? round($ns->count() / $due->count() * 100, 1) : '',
                'onl' => $bk->count() ? round($bk->where('source', 'online')->count() / $bk->count() * 100) : '', '_class' => $due->count() && $ns->count() / $due->count() > .15 ? 'row-alert' : ''];
        })->values();
        $bk = $all->where('type', 'booking');
        $due = $bk->whereIn('status', ['seated', 'completed', 'no_show']);

        return ['cols' => ['day' => 'Day', 'bk' => 'Bookings', 'bkc' => 'Booked covers seated', 'wic' => 'Walk-in covers', 'wlc' => 'Waitlist covers', 'cov' => 'Total covers',
            'ns' => 'No-shows', 'nsc' => 'No-show covers', 'cx' => 'Cancelled', 'nsr' => 'No-show %', 'onl' => 'Online bookings %'],
            'rows' => $rows, 'sum' => ['bk', 'bkc', 'wic', 'wlc', 'cov', 'ns', 'nsc', 'cx'],
            'summary' => ['Covers' => (string) $rows->sum('cov'), 'Avg covers / day' => $rows->count() ? (string) round($rows->avg('cov'), 1) : '–',
                'No-show rate' => $due->count() ? round($due->where('status', 'no_show')->count() / $due->count() * 100, 1).'%' : '–',
                'Covers lost to no-shows' => (string) $rows->sum('nsc')],
            'note' => 'Covers are counted when the party is seated. No-show % = no-shows ÷ bookings that were due; days above 15% are highlighted. Online % = share of bookings made on the public booking page.'];
    }

    protected function r_table_turns($from, $to): array
    {
        $all = $this->tbRows($from, $to)->whereIn('status', ['seated', 'completed']);
        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $by = [];
        foreach ($all as $r) {
            $share = max(1, $r->tables->count());
            foreach ($r->tables as $t) {
                $by[$t->id]['t'] = $t;
                $by[$t->id]['outlet'] = $r->outlet?->name;
                $by[$t->id]['p'][] = $r;
                $by[$t->id]['cov'] = ($by[$t->id]['cov'] ?? 0) + $r->party_size / $share;
                $by[$t->id]['sales'] = ($by[$t->id]['sales'] ?? 0) + ($r->order && $r->order->status !== 'void' ? (float) $r->order->total / $share : 0);
            }
        }
        $rows = collect($by)->map(function ($x) use ($days) {
            $p = collect($x['p']);
            $dined = $p->map->dinedMinutes()->filter();
            $cap = $x['t']->capacity * $p->count();

            return ['outlet' => $x['outlet'], 'table' => $x['t']->table_no, 'sec' => $x['t']->section, 'seats' => (int) $x['t']->capacity, 'parties' => $p->count(), 'cov' => (int) round($x['cov']),
                'avg' => round($x['cov'] / max(1, $p->count()), 1), 'dined' => $dined->count() ? (int) round($dined->avg()) : '', 'turns' => round($p->count() / $days, 2),
                'util' => $cap ? round($x['cov'] / $cap * 100) : '', 'sales' => round($x['sales'], 2), 'per' => $x['cov'] ? round($x['sales'] / $x['cov'], 2) : 0,
                '_class' => $cap && $x['cov'] / $cap < .5 ? 'row-alert' : ''];
        })->sortBy(fn ($r) => $r['outlet'].str_pad(preg_replace('/\D/', '', $r['table']), 4, '0', STR_PAD_LEFT))->values();

        return ['cols' => ['outlet' => 'Outlet', 'table' => 'Table', 'sec' => 'Section', 'seats' => 'Seats', 'parties' => 'Parties', 'cov' => 'Covers', 'avg' => 'Avg party',
            'dined' => 'Avg min at table', 'turns' => 'Turns / day', 'util' => 'Seat utilisation %', 'sales' => 'Sales', 'per' => 'Sales / cover'],
            'rows' => $rows, 'money' => ['sales', 'per'], 'sum' => ['parties', 'cov', 'sales'],
            'summary' => ['Tables used' => (string) $rows->count(), 'Avg turns / day' => $rows->count() ? (string) round($rows->avg('turns'), 2) : '–',
                'Seat utilisation' => $rows->sum('seats') ? round($rows->sum('cov') / max(1, $rows->sum(fn ($r) => $r['seats'] * $r['parties'])) * 100).'%' : '–'],
            'note' => "Seated and completed parties over {$days} day(s). A party on joined tables counts once per table, with covers and sales shared between them. Seat utilisation = covers ÷ seats offered to the parties seated there; under 50% (a 2-top on a 6-seat table) is highlighted."];
    }

    protected function r_table_waitlist($from, $to): array
    {
        $all = $this->tbRows($from, $to)->where('type', 'waitlist');
        $rows = $all->groupBy(fn ($r) => $r->date->toDateString())->map(function ($d, $day) {
            $s = $d->whereIn('status', ['seated', 'completed']);
            $w = $s->map->waitedMinutes()->filter(fn ($v) => $v !== null);
            $within = $s->filter(fn ($r) => $r->quoted_minutes && $r->waitedMinutes() !== null && $r->waitedMinutes() <= $r->quoted_minutes)->count();
            $quoted = $s->filter(fn ($r) => $r->quoted_minutes)->count();

            return ['day' => Carbon::parse($day)->format('D d M Y'), 'n' => $d->count(), 'cov' => (int) $d->sum('party_size'), 'seated' => $s->count(), 'left' => $d->where('status', 'left')->count(),
                'waiting' => $d->whereIn('status', ['waiting', 'notified'])->count(), 'q' => $d->whereNotNull('quoted_minutes')->count() ? (int) round($d->whereNotNull('quoted_minutes')->avg('quoted_minutes')) : '',
                'w' => $w->count() ? (int) round($w->avg()) : '', 'within' => $quoted ? round($within / $quoted * 100) : '', '_class' => $d->where('status', 'left')->count() > $s->count() ? 'row-alert' : ''];
        })->values();
        $s = $all->whereIn('status', ['seated', 'completed']);
        $w = $s->map->waitedMinutes()->filter(fn ($v) => $v !== null);

        return ['cols' => ['day' => 'Day', 'n' => 'Parties', 'cov' => 'Covers', 'seated' => 'Seated', 'left' => 'Walked away', 'waiting' => 'Still waiting', 'q' => 'Avg quoted (min)',
            'w' => 'Avg actual wait (min)', 'within' => 'Seated within quote %'],
            'rows' => $rows, 'sum' => ['n', 'cov', 'seated', 'left'],
            'summary' => ['Parties' => (string) $all->count(), 'Conversion' => $all->count() ? round($s->count() / $all->count() * 100).'%' : '–',
                'Avg wait' => $w->count() ? round($w->avg()).' min' : '–', 'Walked away' => (string) $all->where('status', 'left')->count()],
            'note' => 'Waitlist parties by day. Actual wait = time added to the list until seated. Conversion = parties seated ÷ parties added. Days where more parties left than were seated are highlighted.'];
    }

    protected function r_fbc_food_cost($from, $to): array
    {
        $daily = \App\Services\FbCostService::daily($from->toDateString(), $to->toDateString());
        $cfg = \App\Services\FbCostService::cfg();
        $rows = collect($daily)->map(fn ($d, $dt) => ['date' => Carbon::parse($dt)->format('D d M Y'), 'f_rev' => $d['food']['revenue'], 'f_theo' => $d['food']['theoretical'],
            'f_act' => $d['food']['actual'], 'f_meals' => $d['food']['staff_meals'] ?: null, 'f_comps' => $d['food']['comps'] ?: null, 'f_cos' => $d['food']['cost_of_sales'],
            'f_pct' => $d['food']['pct'] !== null ? $d['food']['pct'].'%' : '', 'b_rev' => $d['beverage']['revenue'], 'b_cos' => $d['beverage']['cost_of_sales'],
            'b_pct' => $d['beverage']['pct'] !== null ? $d['beverage']['pct'].'%' : '', '_class' => $d['food']['pct'] !== null && $d['food']['pct'] > $cfg['food_target'] + 5 ? 'row-amber' : ''])->values();
        $f = \App\Services\FbCostService::statement($from->toDateString(), $to->toDateString(), 'food');
        $b = \App\Services\FbCostService::statement($from->toDateString(), $to->toDateString(), 'beverage');

        return ['cols' => ['date' => 'Date', 'f_rev' => 'Food revenue', 'f_theo' => 'Theoretical', 'f_act' => 'Cost consumed', 'f_meals' => 'Staff meals', 'f_comps' => 'Officers\' checks',
            'f_cos' => 'Cost of food sold', 'f_pct' => 'Food %', 'b_rev' => 'Beverage revenue', 'b_cos' => 'Cost of beverage sold', 'b_pct' => 'Beverage %'], 'rows' => $rows,
            'money' => ['f_rev', 'f_theo', 'f_act', 'f_meals', 'f_comps', 'f_cos', 'b_rev', 'b_cos'], 'sum' => ['f_rev', 'f_theo', 'f_act', 'f_meals', 'f_comps', 'f_cos', 'b_rev', 'b_cos'],
            'summary' => ['Food cost %' => ($f['pct'] ?? '—').'% (target '.$cfg['food_target'].'%)', 'Theoretical food %' => ($f['theoretical_pct'] ?? '—').'%', 'Food variance' => money($f['variance']),
                'Beverage cost %' => ($b['pct'] ?? '—').'% (target '.$cfg['bev_target'].'%)', 'Beverage variance' => money($b['variance'])],
            'note' => 'Cost consumed = recipe consumption + issues + waste + count adjustments from the kitchen, bar and outlet stores. USALI: employee meals and officers\' checks are transferred out to reach the cost of sales. Revenue is net of taxes and discounts.'];
    }

    protected function r_fbc_menu_engineering($from, $to): array
    {
        $me = \App\Services\FbCostService::menuEngineering($from->toDateString(), $to->toDateString());
        $C = \App\Services\FbCostService::CLASSES;
        $rows = $me['rows']->map(fn ($r) => ['dish' => $r->item->name, 'category' => $r->item->category?->name, 'sold' => $r->n, 'mix' => $r->mix.'%', 'price' => $r->price, 'cost' => $r->cost,
            'cm' => $r->cm, 'revenue' => $r->revenue, 'total_cost' => $r->total_cost, 'total_cm' => $r->total_cm, 'class' => $C[$r->cls][0], 'action' => $C[$r->cls][2],
            '_class' => $r->cls === 'dog' ? 'row-red' : ($r->cls === 'plowhorse' ? 'row-amber' : '')]);

        return ['cols' => ['dish' => 'Menu item', 'category' => 'Category', 'sold' => 'Sold', 'mix' => 'Mix %', 'price' => 'Avg price', 'cost' => 'Plate cost', 'cm' => 'CM', 'revenue' => 'Revenue',
            'total_cost' => 'Total cost', 'total_cm' => 'Total CM', 'class' => 'Class', 'action' => 'Action'], 'rows' => $rows, 'money' => ['price', 'cost', 'cm', 'revenue', 'total_cost', 'total_cm'],
            'sum' => ['sold', 'revenue', 'total_cost', 'total_cm'],
            'summary' => ['Items sold' => number_format($me['n']), 'Average CM' => money($me['avg_cm']), 'Popularity threshold' => $me['threshold'].'%', 'Menu cost %' => ($me['pct'] ?? '—').'%',
                'Stars' => (string) $me['counts']['star'], 'Plowhorses' => (string) $me['counts']['plowhorse'], 'Puzzles' => (string) $me['counts']['puzzle'], 'Dogs' => (string) $me['counts']['dog']],
            'note' => 'Popular = menu mix ≥ '.\App\Services\FbCostService::cfg()['popularity'].'% of an equal share; profitable = CM ≥ the weighted average CM. Dishes without a recipe ('.$me['uncosted']->count().') are not analysed.'];
    }

    protected function r_fbc_price_changes($from, $to): array
    {
        $list = \App\Models\FbPriceChange::with(['menuItem', 'user'])->whereDate('effective_on', '>=', $from->toDateString())->whereDate('effective_on', '<=', $to->toDateString())->orderByDesc('effective_on')->orderByDesc('id')->get();
        $rows = $list->map(fn ($c) => ['date' => $c->effective_on->format('d M Y'), 'dish' => $c->menuItem?->name, 'old' => $c->old_price, 'new' => $c->new_price,
            'change' => $c->old_price > 0 ? round(($c->new_price - $c->old_price) / $c->old_price * 100, 1).'%' : '', 'cost' => $c->cost_at,
            'before' => $c->old_price > 0 && $c->cost_at > 0 ? round($c->cost_at / $c->old_price * 100, 1).'%' : '', 'after' => $c->new_price > 0 && $c->cost_at > 0 ? round($c->cost_at / $c->new_price * 100, 1).'%' : '',
            'reason' => $c->reason, 'by' => $c->user?->name]);

        return ['cols' => ['date' => 'Date', 'dish' => 'Dish', 'old' => 'Old price', 'new' => 'New price', 'change' => 'Change', 'cost' => 'Plate cost', 'before' => 'Cost % before', 'after' => 'Cost % after',
            'reason' => 'Reason', 'by' => 'By'], 'rows' => $rows, 'money' => ['old', 'new', 'cost'], 'sum' => [],
            'summary' => ['Changes' => (string) $rows->count(), 'Increases' => (string) $list->filter(fn ($c) => $c->new_price > $c->old_price)->count(),
                'Decreases' => (string) $list->filter(fn ($c) => $c->new_price < $c->old_price)->count()]];
    }

    protected function r_fbc_recipe_costs($from, $to): array
    {
        $rows = \App\Services\FbCostService::dishes()->map(fn ($m) => ['code' => $m->code, 'dish' => $m->name, 'category' => $m->category?->name, 'class' => ucfirst($m->cls),
            'price' => (float) $m->price, 'cost' => $m->cost, 'gp' => $m->gp, 'pct' => $m->pct !== null ? $m->pct.'%' : 'no recipe', 'target' => $m->target.'%',
            'suggested' => $m->suggested, 'creep' => $m->creep ? ($m->creep['pct'] > 0 ? '+' : '').$m->creep['pct'].'%' : '',
            'card' => $m->card ? 'v'.$m->card->version.($m->card->approved_at ? ' approved' : ' draft') : '',
            '_class' => $m->st === 'over' ? 'row-amber' : ($m->st === 'no_recipe' ? 'row-red' : '')]);
        $c = $rows->filter(fn ($r) => $r['cost'] !== null);

        return ['cols' => ['code' => 'Code', 'dish' => 'Dish', 'category' => 'Category', 'class' => 'Class', 'price' => 'Price', 'cost' => 'Plate cost', 'gp' => 'GP', 'pct' => 'Cost %',
            'target' => 'Target', 'suggested' => 'Price at target', 'creep' => 'Cost creep', 'card' => 'Recipe card'], 'rows' => $rows->values(), 'money' => ['price', 'cost', 'gp', 'suggested'], 'sum' => [],
            'summary' => ['Dishes' => (string) $rows->count(), 'Costed' => (string) $c->count(), 'Above target' => (string) $rows->where('_class', 'row-amber')->count(),
                'No recipe' => (string) $rows->where('_class', 'row-red')->count()],
            'note' => 'Plate cost = net quantity ÷ trim yield × '.(\App\Services\FbCostService::cfg()['basis'] === 'average' ? '90-day weighted average purchase cost' : 'last purchase cost').'. Prices exclude VAT, NHIL, GETFund and the tourism levy.'];
    }

    protected function r_fbc_variance($from, $to): array
    {
        $q = fn ($v) => round($v, 3) ?: null;
        $list = \App\Services\FbCostService::usage($from->toDateString(), $to->toDateString());
        $rows = $list->map(fn ($r) => ['item' => $r->item->name, 'class' => ucfirst($r->item->category?->fb_class ?? ''), 'unit' => $r->item->unit, 'ideal' => $q($r->ideal), 'consumed' => $q($r->consumed),
            'issued' => $q($r->issued), 'wasted' => $q($r->wasted), 'counted' => $q($r->counted), 'actual' => $q($r->actual), 'variance' => $q($r->variance),
            'pct' => $r->pct !== null ? $r->pct.'%' : '', 'unit_cost' => $r->unit_cost, 'value' => $r->value, '_class' => $r->pct !== null && $r->pct > 10 && $r->value > 0 ? 'row-amber' : '']);

        return ['cols' => ['item' => 'Ingredient', 'class' => 'Class', 'unit' => 'Unit', 'ideal' => 'Ideal', 'consumed' => 'Recipe consumption', 'issued' => 'Issued', 'wasted' => 'Wasted',
            'counted' => 'Count adj.', 'actual' => 'Actual', 'variance' => 'Variance', 'pct' => '%', 'unit_cost' => 'Unit cost', 'value' => 'Value'], 'rows' => $rows,
            'money' => ['unit_cost', 'value'], 'sum' => ['value'],
            'summary' => ['Ingredients' => (string) $rows->count(), 'Over-usage' => money($list->where('value', '>', 0)->sum('value')), 'Net variance' => money($list->sum('value'))],
            'note' => 'Ideal = portions sold and complimentary × net recipe quantity ÷ trim yield. Actual = outflows from the kitchen, bar and outlet stores (consumption, issues, wastage and stock-count adjustments).'];
    }

}
