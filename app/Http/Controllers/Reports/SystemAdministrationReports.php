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
 * System & Administration reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait SystemAdministrationReports
{
    protected function r_api_idempotency_keys($from, $to): array
    {
        $rows = ApiIdempotencyKey::with('apiClient.endpoint')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'client' => optional($r->apiClient)->name, 'endpoint' => $r->endpoint,
            'key' => $r->idempotency_key, 'created' => $r->created_at, 'used' => $r->used_at,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['client' => 'Client', 'endpoint' => 'Endpoint', 'key' => 'Key', 'created' => 'Created', 'used' => 'Used', 'status' => 'Status'], 'rows' => $rows, 'note' => 'API idempotency key register.'];
    }

    protected function r_data_migration_batches($from, $to): array
    {
        $rows = DataMigrationBatch::with('migrationType')->whereBetween('started_at', [$from, $to])->get()->map(fn ($r) => [
            'batch_no' => $r->batch_no, 'type' => optional($r->migrationType)->name,
            'started' => $r->started_at, 'completed' => $r->completed_at,
            'records_processed' => $r->records_processed, 'records_failed' => $r->records_failed,
            'status' => $r->status, 'user' => $r->user_id, 'notes' => $r->notes,
        ]);
        return ['cols' => ['batch_no' => 'Batch No', 'type' => 'Type', 'started' => 'Started', 'completed' => 'Completed', 'processed' => 'Processed', 'failed' => 'Failed', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Data migration batch summaries.'];
    }

    protected function r_data_migration_rows($from, $to): array
    {
        // Rewritten against the real schema: batch() relation; the parent carries code/dataset/source_name/status/rows_total/rows_created/rows_failed; the row carries row_no/external_id/status/message/model_type.
        $rows = DataMigrationRow::with('batch')->whereHas('batch', fn ($q) => $q->whereBetween('created_at', [$from, $to]))->orderBy('batch_id')->orderBy('row_no')->get()->map(fn ($r) => [
            'batch' => optional($r->batch)->code, 'dataset' => optional($r->batch)->dataset,
            'row_no' => $r->row_no, 'external_id' => $r->external_id,
            'model' => $r->model_type, 'status' => ucfirst((string) $r->status), 'message' => $r->message,
        ]);
        return ['cols' => ['batch' => 'Batch', 'dataset' => 'Dataset', 'row_no' => 'Row', 'external_id' => 'External ID', 'model' => 'Model', 'status' => 'Status', 'message' => 'Message'], 'rows' => $rows, 'note' => 'Data migration row-level results.'];
    }

    protected function r_log_entry_comments($from, $to): array
    {
        $rows = LogEntryComment::with('logEntry.user')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'log' => optional($r->logEntry)->action, 'comment' => $r->comment, 'created' => $r->created_at,
            'user' => optional($r->user)->name, 'status' => $r->status,
        ]);
        return ['cols' => ['log' => 'Log Entry', 'comment' => 'Comment', 'created' => 'Created', 'user' => 'User', 'status' => 'Status'], 'rows' => $rows, 'note' => 'System log entry comments.'];
    }

    protected function r_log_entry_reads($from, $to): array
    {
        $rows = LogEntryRead::with('logEntry.user')->whereBetween('read_at', [$from, $to])->get()->map(fn ($r) => [
            'log' => optional($r->logEntry)->action, 'read' => $r->read_at,
            'user' => optional($r->user)->name, 'ip' => $r->ip_address,
        ]);
        return ['cols' => ['log' => 'Log Entry', 'read' => 'Read At', 'user' => 'User', 'ip' => 'IP'], 'rows' => $rows, 'note' => 'System log entry read receipts.'];
    }

    protected function r_offline_sync_operations($from, $to): array
    {
        $rows = OfflineSyncOperation::with('syncType.device.syncStatus')->whereBetween('synced_at', [$from, $to])->get()->map(fn ($r) => [
            'type' => optional($r->syncType)->name, 'device' => optional($r->device)->device_name,
            'synced' => $r->synced_at, 'records' => $r->records_synced, 'duration_ms' => $r->duration_ms,
            'status' => optional($r->syncStatus)->name, 'notes' => $r->notes,
        ]);
        return ['cols' => ['type' => 'Type', 'device' => 'Device', 'synced' => 'Synced', 'records' => 'Records', 'duration_ms' => 'Duration (ms)', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Offline sync operation log.'];
    }

    protected function r_permissions($from, $to): array
    {
        // Rewritten against the real schema: permissions carry key/module/label; there is no group or guard table.
        $rows = Permission::orderBy('module')->orderBy('key')->get()->map(fn ($r) => [
            'key' => $r->key, 'module' => $r->module, 'label' => $r->label,
        ]);
        return ['cols' => ['key' => 'Key', 'module' => 'Module', 'label' => 'Label'], 'rows' => $rows, 'note' => 'System permission definitions.'];
    }

    protected function r_settings($from, $to): array
    {
        // Rewritten against the real schema: settings carry key/value only.
        $rows = Setting::orderBy('key')->get()->map(fn ($r) => [
            'key' => $r->key, 'value' => $r->value,
        ]);
        return ['cols' => ['key' => 'Key', 'value' => 'Value'], 'rows' => $rows, 'note' => 'System settings register.'];
    }

}
