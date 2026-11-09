<?php

namespace App\Http\Controllers;

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
 * Config-driven report engine. Every report returns columns + rows and is
 * rendered by one view with print and CSV export.
 */
class ReportController extends Controller
{
    use AccountsReports,
        BanquetEventsReports,
        BillingReports,
        BudgetPlanningReports,
        CentralReservationsReports,
        ChannelManagerReports,
        ComplianceHseReports,
        ConciergeActivitiesReports,
        FeedbackReputationReports,
        FixedAssetsEngineeringReports,
        FnBPosReports,
        FoodSafetyReports,
        ForeignGuestsForexReports,
        FrontOfficeReports,
        GeneralLedgerReports,
        GuestMessagingReports,
        HealthClubSpaReports,
        HotelGroupOwnerReportingReports,
        HotelInterfacesReports,
        HousekeepingReports,
        HrPeopleReports,
        InventoryPurchasingReports,
        IptvGuestTechnologyReports,
        LaundryLinenReports,
        LearningDevelopmentReports,
        ManagementMisReports,
        MarketingCrmReports,
        PartnerOrganiserPortalsReports,
        PayrollReports,
        PropertyUtilitiesReports,
        QualityAssuranceReports,
        RevenueManagementReports,
        SalesBusinessDevelopmentReports,
        SecurityReports,
        SelfServiceKioskReports,
        SustainabilityEsgReports,
        SystemAdministrationReports,
        TalentManagementReports,
        TaxStatutoryReports,
        TransportParkingReports,
        TreasuryPaymentsReports;
    /** slug => [group, title, description, filter mode] */
        public const REPORTS = [
        'allotment_utilisation' => ['Accounts', 'Allotment Utilisation', 'Per allotment line: room nights allotted in the period, picked up, released unsold and still held – is the account using what it asked for?', 'range', 'contracts.view'],
        'ap_ageing' => ['Accounts', 'AP Ageing (Suppliers)', 'Supplier balances by days past due, with unbilled goods receipts and unapplied credits', 'date', 'ap.view'],
        'ap_allocations' => ['Accounts', 'AP Allocation Register', 'Every AP allocation of a payment to invoices', 'range', 'accounts.view'],
        'ap_register' => ['Accounts', 'Supplier Bill Register', 'Bills & debit notes in the period: match variance, paid and outstanding', 'range', 'ap.view'],
        'ar_aging' => ['Accounts', 'AR Ageing (City Ledger)', 'Company outstanding by age bucket, FIFO against receipts', 'date'],
        'bank_reconciliation' => ['Accounts', 'Bank Reconciliation Summary', 'Per bank / Mobile Money / clearing account at a date: book balance, latest statement balance, deposits in transit, unpresented payments, statement items not in the books and the unexplained difference', 'date', 'bankrec.view'],
        'bank_statement_lines' => ['Accounts', 'Bank Statement Lines', 'Imported statement lines in the period: statement, date, details, money in / out, status, how matched and the book entries they cleared', 'range', 'bankrec.view'],
        'billing_reconciliation_items' => ['Accounts', 'Billing Reconciliation Item Register', 'Every folio/invoice reconciled against cash and city ledger', 'range', 'accounts.view'],
        'billing_reconciliations' => ['Accounts', 'Billing Reconciliation Register', 'Every billing reconciliation period with preparer and reviewer', 'range', 'accounts.view'],
        'close_events' => ['Accounts', 'Close Event Register', 'Every period-end close event', 'range', 'accounts.view'],
        'contract_production' => ['Accounts', 'Contract Production', 'Per rate contract: bookings, room nights and revenue for stays in the period, ADR vs public rate, cancellations and progress against the room-night target', 'range', 'contracts.view'],
        'credit_exposure' => ['Accounts', 'Credit Exposure', 'Every direct-billing account: limit, city ledger, overdue, in-house balances, total exposure, available credit and hold status', 'none', 'credit.view'],
        'expense_claim_lines' => ['Accounts', 'Expense Claim Line Register', 'Every expense claim line with amount and VAT', 'range', 'accounts.view'],
        'expense_claims' => ['Accounts', 'Staff Expense Claims Register', 'Claims submitted in the period: claimant, department, title, amount, input VAT, status, approver, payment date and method', 'range', 'claims.approve'],
        'expenses' => ['Accounts', 'Expense Report', 'Payment vouchers by head', 'range'],
        'petty_cash_book' => ['Accounts', 'Petty Cash Book', 'Every posted petty cash movement in the period per float: top-ups, vouchers, IOUs and settlements, count differences and claims paid in cash, with opening, running and closing balances', 'range', 'petty.view'], // v4.7
        'petty_counts' => ['Accounts', 'Petty Cash Count Register', 'Every petty cash count with counted amount and variance', 'none', 'accounts.view'],
        'petty_expenses' => ['Accounts', 'Petty Cash Spending by Account', 'Vouchers and settled IOUs in the period by expense account and float, with input VAT, NHIL & GETFund recovered on VAT invoices', 'range', 'petty.view'],
        'petty_replenishments' => ['Accounts', 'Petty Cash Replenishment Register', 'Every petty cash replenishment request', 'none', 'accounts.view'],
        'supplier_paid_history_archives' => ['Accounts', 'Supplier Paid History Archive Register', 'Every archived supplier payment from pre-cutover data', 'range', 'accounts.view'],
        'uncleared_items' => ['Accounts', 'Uncleared Items', 'Book entries not yet on a bank statement and statement lines not in the books, at a date, with days outstanding', 'date', 'bankrec.view'],
        'banquet' => ['Banquet & Events', 'Banquet Event Report', 'Events in the period: functions, venues, peak pax, value, deposits and status', 'range', 'banquet.view'],
        'banquet_pipeline' => ['Banquet & Events', 'Banquet Sales Pipeline', 'Enquiries, tentative holds, definite and lost business by event month – count, value and conversion', 'range', 'banquet.view'],
        'function_forecast' => ['Banquet & Events', 'Function Forecast (Kitchen & Ops)', 'Upcoming tentative and definite functions with covers, menu and set-up – for purchasing and staffing', 'range', 'banquet.view'],
        'organiser_attendees' => ['Banquet & Events', 'Organiser Attendee Register', 'Every banquet event attendee with tag and contact', 'none', 'banquet.view'],
        'venue_utilisation' => ['Banquet & Events', 'Venue Utilisation', 'Per venue: functions, days and hours booked, occupancy of a 16-hour day, covers and revenue', 'range', 'banquet.view'],
        'cashier_movements' => ['Billing', 'Cashier Movement Register', 'Cash in and cash out by cashier, shift, time, reason and reference', 'range', 'folio.shift_all'],
        'cashier_shifts' => ['Billing', 'Cashier Shift Register', 'Individual cashier close-outs with collections by payment mode, expected cash, counted cash and variance', 'range', 'folio.shift_all'],
        'cashier_day_summary' => ['Billing', 'Daily Cashier Shift Roll-up', 'Department/day totals across cashier shifts, collections, drawer expectations and reconciled variances', 'range', 'folio.shift_all'],
        'collection' => ['Billing', 'Collection Report', 'Payments by mode and cashier', 'range'],
        'credit_logs' => ['Billing', 'Credit Log Register', 'Every credit issued to a guest folio', 'range', 'frontoffice.view'],
        'invoices' => ['Billing', 'Invoice Register', 'Closed folios / invoices', 'range'],
        'online_payments' => ['Billing', 'Online Payments', 'Payment links paid, refunded, pending or expired in the period, with gateway transaction, fees and payouts', 'range', 'payments.view'],
        'outstanding' => ['Billing', 'Guest Ledger / Outstanding', 'Open folios with balances', 'none'],
        'revenue' => ['Billing', 'Daily Revenue Report', 'Revenue by charge category', 'range'],
        'tax' => ['Billing', 'Tax (VAT & Levies) Report', 'Taxable value with VAT, NHIL, GETFund and Tourism Levy by category and rate', 'range'],
        'voids' => ['Billing', 'Void & Discount Report', 'Voided transactions and discounts (audit)', 'range'],
        'budget_annual' => ['Budget & Planning', 'Annual Budget – Monthly Phasing', 'The active budget for the fiscal year: every income and expense account by month with section totals, GOP and net profit', 'date', 'budget.view'],
        'budget_events' => ['Budget & Planning', 'Budget Event Register', 'Every budget adjustment event', 'none', 'budget.view'],
        'budget_kpis' => ['Budget & Planning', 'Rooms KPIs vs Budget', 'Per fiscal month: occupancy, ADR, RevPAR, room-nights and room revenue – budget against actual', 'date', 'budget.view'],
        'budget_lines' => ['Budget & Planning', 'Budget Line Register', 'Every budget line by department and category', 'none', 'budget.view'],
        'budget_stats' => ['Budget & Planning', 'Budget Stats Register', 'Monthly budget vs actual by department', 'none', 'budget.view'],
        'budget_vs_actual' => ['Budget & Planning', 'Budget vs Actual (P&L)', 'Month and year-to-date actuals from the general ledger against the active budget (current month pro-rated), variances, % and full-year budget / forecast', 'date', 'budget.view'],
        'forecast_vs_budget' => ['Budget & Planning', 'Forecast vs Budget', 'Full-year active re-forecast against the approved budget per account, with actuals to date and the remaining forecast', 'date', 'budget.view'],
        'variance_commentary' => ['Budget & Planning', 'Variance Commentary Log', 'Explanations recorded for budget variances in the period: month, account, actual, budget, variance, comment and author', 'range', 'budget.view'],
        'cro_agents' => ['Central Reservations', 'CRO Agent Performance', 'Per reservations agent: enquiries, bookings, referrals, denials, regrets, conversion vs target, booked and lost revenue, average call length', 'range', 'cro.view'],
        'cro_demand' => ['Central Reservations', 'Unconstrained Demand', 'By stay date: capacity, rooms on the books, rooms denied and regretted, and occupancy with and without denials – the demand revenue management should price for', 'range', 'cro.view'],
        'cro_enquiries' => ['Central Reservations', 'Reservations Enquiry Register', 'Every enquiry logged in the period with the stay asked, channel, agent, value and outcome – booked, referred to a sister hotel, or lost (denial / regret, reason, competitor)', 'range', 'cro.view'],
        'cro_lost_business' => ['Central Reservations', 'Lost Business (Denials & Regrets)', 'Lost enquiries by reason: denials (could not offer) and regrets (guest declined) with count, room nights, value, share, and turnaways kept in the group by referral', 'range', 'cro.view'],
        'cro_referrals' => ['Central Reservations', 'Sister-Hotel Referrals', 'Turnaways referred between the hotels of the group: origin and receiving hotel, guest, stay, reason and room revenue kept in the group', 'range', 'cro.view'],
        'channel_ari_states' => ['Channel Manager', 'Channel ADR/Index Register', 'Daily ADR and index by channel', 'none', 'channel.view'],
        'channel_messages' => ['Channel Manager', 'Channel Message Register', 'Every message sent to or received from a channel', 'none', 'channel.view'],
        'channel_parity' => ['Channel Manager', 'Rate Parity Exceptions', 'Every room-night where what a channel last acknowledged (availability, restrictions, prices) differs from what the PMS publishes now', 'range', 'channels.view'],
        'channel_production' => ['Channel Manager', 'Channel Production', 'Per OTA connection: bookings received in the period, room nights, gross revenue, commission, net revenue, ADR, lead time, length of stay, cancellations and prepaid share', 'range', 'channels.view'],
        'channel_rate_maps' => ['Channel Manager', 'Channel Rate Map Register', 'Every rate mapping between PMS and channel', 'none', 'channel.view'],
        'channel_restrictions' => ['Channel Manager', 'Channel Restriction Register', 'Every channel availability restriction by date and room type', 'none', 'channel.view'],
        'channel_room_maps' => ['Channel Manager', 'Channel Room Map Register', 'Every room type mapping between PMS and channel', 'none', 'channel.view'],
        'channel_sync_log' => ['Channel Manager', 'Channel Sync Log', 'ARI pushes per channel and day: deltas and full refreshes, room-nights sent, failures, average duration and the last error', 'range', 'channels.view'],
        'ota_channels' => ['Channel Manager', 'OTA Channel Register', 'Every OTA channel with integration status and mapping', 'none', 'channel.view'],
        'compliance_events' => ['Compliance & HSE', 'Compliance Event Register', 'Every compliance event', 'none', 'compliance.view'],
        'compliance_register' => ['Compliance & HSE', 'Licences & Compliance Register', 'Every licence, permit, certificate, insurance, inspection and AMC with expiry / next due, days left and status as at a date', 'date', 'compliance.view'],
        'data_breaches' => ['Compliance & HSE', 'Data Breach Register', 'Every reported data breach with severity and closure tracking', 'none', 'compliance.view'],
        'hse_accidents' => ['Compliance & HSE', 'Accident & Near-Miss Register', 'Accidents, injuries, near misses and dangerous occurrences with the person, injury, days lost, Act 328 notice and investigation status; LTIFR for the period', 'range', 'hse.view'],
        'hse_checks' => ['Compliance & HSE', 'Fire Equipment Inspection Log', 'Visual checks and contractor services in the period: item, result, faults found, certificate, engineering ticket and who checked', 'range', 'hse.view'],
        'hse_drills' => ['Compliance & HSE', 'Fire Drill Log', 'Drills in the period by shift: evacuation time against the target, roll call, guests evacuated, result and improvements', 'range', 'hse.view'],
        'hse_equipment' => ['Compliance & HSE', 'Fire Equipment Register', 'Every fire and life-safety item with GNFS tag, location, last and next visual check, contractor service due, expiry and state (in order / due / overdue / defective / expired)', 'none', 'hse.view'],
        'hse_permits' => ['Compliance & HSE', 'Permit-to-Work Register', 'Permits requested in the period by type: location, contractor, period, requester and issuer, fire watch / gas test / isolation, close-out time and late close-outs', 'range', 'hse.view'],
        'hse_risks' => ['Compliance & HSE', 'Risk Register', 'Live risk assessments: hazard, who is at risk, initial and residual rating on the 5 × 5 matrix, owner, acceptance and review date', 'none', 'hse.view'],
        'hse_wardens' => ['Compliance & HSE', 'HSE Warden Register', 'Every fire warden, first aider and fire team member with training status', 'none', 'compliance.view'],
        'pii_access' => ['Compliance & HSE', 'PII Access Log', 'Every reveal of masked identifiers, ID-document and e-signature view and personal-data export in the period, by user', 'range', 'privacy.view'],
        'pm_compliance' => ['Compliance & HSE', 'Preventive Maintenance Compliance', 'PM tickets that fell due in the period: done on time, late or still open, with days late and technician', 'range', 'maintenance.manage'],
        'privacy_request_events' => ['Compliance & HSE', 'Privacy Request Event Register', 'Every event in a data privacy request lifecycle', 'none', 'compliance.view'],
        'privacy_requests' => ['Compliance & HSE', 'Privacy Request Register', 'Data-subject requests received in the period: type, channel, identity check, deadline, days taken, on time / late / open and the outcome', 'range', 'privacy.view'],
        'privacy_retention' => ['Compliance & HSE', 'Retention & Erasure Log', 'Per day: ID scans and signatures deleted, message contents purged, profiles anonymised or restricted (on request / by policy), legal holds completed', 'range', 'privacy.view'],
        'activity_departures' => ['Concierge & Activities', 'Activity Departure Register', 'Every activity departure with bookings and seat availability', 'range', 'activity.view'],
        'concierge_bookings' => ['Concierge & Activities', 'Activity Bookings Register', 'Every activity / excursion booking in the period: operator, guest, party, source, paid to hotel or operator, sold value, commission, status and settlement', 'range', 'concierge.view'], // v5.9
        'concierge_sales' => ['Concierge & Activities', 'Activity Sales & Commission', 'Per activity: bookings, guests, sold value, average booking, commission earned, cancellations, no-shows, portal share and take-up', 'range', 'concierge.view'],
        'concierge_staff' => ['Concierge & Activities', 'Concierge Sales by Staff', 'Bookings, guests, sold value and commission by the staff member (or guest portal) that made them – the base for concierge incentives', 'range', 'concierge.view'],
        'concierge_vendors' => ['Concierge & Activities', 'Operator Statement & Balances', 'Per tour operator: finished bookings, commission, settlements paid / received and unsettled balances owed each way, with licence expiry', 'range', 'concierge.view'],
        'logbook_register' => ['Front Office', 'Shift Log Book Register', 'Every log book entry in the period by date and shift: category, priority, department, author, open / resolved, time to resolve and how many staff read it', 'range', 'logbook.view'],
        'lost_found_register' => ['Front Office', 'Lost & Found Register', 'Items found in the period: item no., category, where found, finder, storage, probable owner and the outcome (returned with ID / courier, disposed with witness)', 'range', 'lostfound.manage'],
        'traces_register' => ['Front Office', 'Traces Register', 'Traces due in the period by department: text, booking, completed on time, carried forward by the night audit or cancelled', 'range', 'desk.traces'],
        'wakeup_calls' => ['Front Office', 'Wake-up Call Performance', 'Every wake-up call due in the period: room, guest, source, attempts, answered / no answer / missed / cancelled and minutes late for the first attempt', 'range', 'desk.wakeup'],
        'banquet_event_items' => ['F&B & POS', 'Banquet Event Item Register', 'Every item line on a banquet event', 'none', 'fnb.view'],
        'banquet_packages' => ['F&B & POS', 'Banquet Package Register', 'Every banquet package with items, price and active', 'none', 'fnb.view'],
        'banquet_services' => ['F&B & POS', 'Banquet Service Register', 'Every banquet service offered: name, price, pax and active', 'none', 'fnb.view'],
        'fb_cost_snapshots' => ['F&B & POS', 'F&B Cost Snapshot Register', 'Every periodic food cost snapshot', 'none', 'fnb.view'],
        'fb_recipe_cards' => ['F&B & POS', 'F&B Recipe Card Register', 'Every recipe card with ingredients, quantities and cost', 'none', 'fnb.view'],
        'fb_staff_meals' => ['F&B & POS', 'F&B Staff Meal Register', 'Every staff meal served with cost per cover and total', 'none', 'fnb.view'],
        'fb_yield_tests' => ['F&B & POS', 'F&B Yield Test Register', 'Every yield test with gross/trim/loss/usable quantities', 'none', 'fnb.view'],
        'kot_print_jobs' => ['F&B & POS', 'KOT Print Job Register', 'Every kitchen order ticket print job', 'range', 'fnb.view'],
        'kot_printers' => ['F&B & POS', 'KOT Printer Register', 'Every kitchen order ticket printer with configuration and usage', 'range', 'fnb.view'],
        'menu_categories' => ['F&B & POS', 'Menu Category Register', 'Every menu category with item count', 'none', 'fnb.view'],
        'menu_item_recipes' => ['F&B & POS', 'Menu Item Recipes Register', 'Detailed register of Menu Item Recipes records', 'none', 'fnb.view'],
        'menu_items' => ['F&B & POS', 'Menu Item Register', 'Every menu item with category, price, cost and active status', 'none', 'fnb.view'],
        'menu_modifier_groups' => ['F&B & POS', 'Menu Modifier Groups Register', 'Detailed register of Menu Modifier Groups records', 'none', 'fnb.view'],
        'menu_modifiers' => ['F&B & POS', 'Menu Modifiers Register', 'Detailed register of Menu Modifiers records', 'none', 'fnb.view'],
        'outlet_service_periods' => ['F&B & POS', 'Outlet Service Period Register', 'Every F&B outlet service period by day', 'none', 'fnb.view'],
        'package_allocation' => ['F&B & POS', 'Package Allocation & Breakage', 'Per package: value allocated out of room revenue and add-ons, used at the outlets, expired as breakage, still open, extras charged; take-up rate', 'range', 'packages.view'],
        'package_consumption' => ['F&B & POS', 'Package Consumption Register', 'Every cover / allowance recorded at the Meal Desk: room, guest, package, covers, value, extras and how they were settled, voids', 'range', 'packages.view'],
        'package_forecast' => ['F&B & POS', 'Meal Cover Forecast', 'Breakfast / lunch / dinner covers per service date: actual entitlements for past days, on-the-books forecast from today (in-house + confirmed reservations)', 'range', 'packages.view'], // v5.5
        'pos_bills' => ['F&B & POS', 'Bill Register', 'All settled / void bills', 'range'],
        'pos_comps' => ['F&B & POS', 'Complimentary / Non-Chargeable Items', 'Every complimentary POS item in the period, including item-level comps and whole-bill non-chargeable settlements, with value, reason, authorizer and bill context', 'range', 'pos.view'],
        'pos_evat_register' => ['F&B & POS', 'Restaurant Bills E-VAT Register', 'Bills settled at the outlets in the period: customer TIN, taxable value, VAT and levies, E-VAT receipt number and status', 'range', 'gst.view'],
        'pos_items' => ['F&B & POS', 'Item-wise Sales', 'Quantity and value by menu item', 'range'],
        'pos_payments' => ['F&B & POS', 'POS Tender Register', 'Every POS payment and refund by tender, reference, cashier and bill', 'range', 'pos.view'],
        'pos_sales' => ['F&B & POS', 'Outlet Sales Summary', 'Bills, covers and sales by outlet', 'range'],
        'pos_tables' => ['F&B & POS', 'POS Table Register', 'Every POS table with outlet, capacity and active', 'none', 'fnb.view'],
        'spa_rooms' => ['F&B & POS', 'Spa Room Register', 'Every spa room with type, capacity and active', 'range', 'fnb.view'],
        'table_bookings' => ['F&B & POS', 'Table Booking Register', 'Every restaurant booking, walk-in and waitlist party in the period: time, party, table, source, occasion, status, minutes late, time at the table and the bill', 'range', 'tables.view'],
        'table_covers' => ['F&B & POS', 'Covers & No-Shows by Day', 'Day by day: bookings, booked / walk-in / waitlist covers seated, no-shows, cancellations, no-show rate and online share', 'range', 'tables.view'],
        'table_turns' => ['F&B & POS', 'Table Turns & Utilisation', 'Per table: parties, covers, average party, average time at the table, turns per day, seat utilisation and linked sales', 'range', 'tables.view'],
        'table_waitlist' => ['F&B & POS', 'Waitlist Performance', 'Day by day: parties waitlisted, seated and walked away, average quoted and actual wait and the share seated within the quote', 'range', 'tables.view'],
        'fbc_food_cost' => ['F&B & POS', 'Daily Food & Beverage Cost Flash', 'Per day: food and beverage revenue, theoretical cost, cost consumed, employee meals and officers\' checks credited, cost of sales and cost % against target', 'range', 'fbc.view'],
        'fbc_menu_engineering' => ['F&B & POS', 'Menu Engineering Analysis', 'Kasavana & Smith worksheet for the period: items sold, menu mix %, average price, plate cost, contribution margin and class (Star / Plowhorse / Puzzle / Dog) with the action', 'range', 'fbc.view'],
        'fbc_price_changes' => ['F&B & POS', 'Menu Price Change Log', 'Menu price changes in the period with old and new price, plate cost at the time, cost % before and after, reason and user', 'range', 'fbc.view'],
        'fbc_recipe_costs' => ['F&B & POS', 'Recipe Costing Register', 'Every menu item with price, plate cost at the current costing basis and trim yields, gross profit, cost % against its target, suggested price and recipe-card status', 'none', 'fbc.view'], // v6.8
        'fbc_variance' => ['F&B & POS', 'Ideal vs Actual Usage Variance', 'Per ingredient: ideal usage from portions sold × recipe (grossed up for yield) against consumption, issues, waste and count adjustments from the kitchen, bar and outlet stores, with the value', 'range', 'fbc.view'],
        'dept_scores' => ['Feedback & Reputation', 'Department Scorecard', 'Per department: average survey rating, positive and negative mentions in comments and reviews, net sentiment, cases, high-severity and past-SLA cases', 'range', 'feedback.view'],
        'feedback_answers' => ['Feedback & Reputation', 'Feedback Answer Register', 'Every survey answer given by a guest', 'none', 'feedback.view'],
        'feedback_case_events' => ['Feedback & Reputation', 'Feedback Case Event Register', 'Every event in the lifecycle of a feedback case', 'none', 'feedback.view'],
        'feedback_cases' => ['Feedback & Reputation', 'Service Recovery Cases', 'Cases opened in the period: department, severity, owner, hours to first contact and to resolve, SLA, recovery action, goodwill points and outcome', 'range', 'feedback.view'],
        'feedback_register' => ['Feedback & Reputation', 'Guest Feedback Register', 'Every survey, portal and web feedback response in the period with recommend score, overall rating, sentiment, complaint areas, comments and the recovery case', 'range', 'feedback.view'],
        'nps_trend' => ['Feedback & Reputation', 'NPS & Satisfaction Trend', 'Month by month: surveys sent, response rate, promoters / passives / detractors, NPS, average overall rating, negative comments, online rating and review response rate', 'range', 'feedback.view'],
        'online_reviews' => ['Feedback & Reputation', 'Online Review Register', 'Reviews from TripAdvisor, Google, Booking.com and others by review date: rating, sentiment, matched guest, response status, who posted it and hours to respond', 'range', 'feedback.view'],
        'survey_invitations' => ['Feedback & Reputation', 'Survey Invitation Register', 'Every survey invitation sent to a guest', 'none', 'feedback.view'],
        'survey_questions' => ['Feedback & Reputation', 'Survey Question Register', 'Every survey question by template', 'none', 'feedback.view'],
        'surveys' => ['Feedback & Reputation', 'Survey Register', 'Every guest survey with template, period and response count', 'range', 'feedback.view'],
        'asset_categories' => ['Fixed Assets & Engineering', 'Asset Category Register', 'Every fixed asset category with depreciation method', 'none', 'assets.view'],
        'asset_dep_runs' => ['Fixed Assets & Engineering', 'Asset Depreciation Run Register', 'Every depreciation run', 'range', 'assets.view'],
        'asset_register' => ['Fixed Assets & Engineering', 'Fixed Asset Register', 'Every asset held at a date: category, location, cost, accumulated depreciation and net book value', 'date', 'assets.view'],
        'asset_schedule' => ['Fixed Assets & Engineering', 'Fixed Asset Schedule (Roll-forward)', 'Per asset for the period: opening cost, additions, disposals, depreciation charge and closing net book value – ties to the balance sheet', 'range', 'assets.view'],
        'pm_schedules' => ['Fixed Assets & Engineering', 'PM Schedule Register', 'Every preventive maintenance schedule by asset and frequency', 'range', 'assets.view'],
        'food_waste' => ['Food Safety', 'Food Waste Analysis', 'Food waste by item and reason with quantity and cost; raw & unserved dishes (posted to 5042 Food Waste) and plate waste (analysis only)', 'range', 'foodsafety.view'],
        'fs_certificates' => ['Food Safety', 'FS Certificate Register', 'Every food safety certificate with expiry and status', 'none', 'foodsafety.view'],
        'fs_equipment' => ['Food Safety', 'FS Equipment Register', 'Every food safety equipment with calibration dates', 'none', 'foodsafety.view'],
        'fs_labels' => ['Food Safety', 'FS Label Register', 'Every food safety label with allergen information', 'none', 'foodsafety.view'],
        'haccp_checklists' => ['Food Safety', 'HACCP Checklist Compliance', 'Checklist periods due in the range: completed on time or missed, failed items and sign-off', 'range', 'foodsafety.view'],
        'haccp_temperatures' => ['Food Safety', 'HACCP Temperature Log', 'Every temperature reading in the period – equipment checks, receiving, cooking, reheating and cooling – with critical limit, result, corrective action and reviewer', 'range', 'foodsafety.view'], // v5.0
        'foreign_arrivals' => ['Foreign Guests & Forex', 'Foreign Arrivals by Nationality', 'Per nationality: guests, stays, room nights, average stay, share of foreign arrivals and Form C filed on time', 'range', 'formc.view'],
        'forex_position' => ['Foreign Guests & Forex', 'Currency Position & Surrenders', 'Per currency: opening balance, bought, surrendered to the bank, closing balance, cost, realised value and exchange gain / loss', 'range', 'forex.view'],
        'forex_rates' => ['Foreign Guests & Forex', 'Forex Rate Board History', 'Every posted forex rate with buy rate, margin and setter', 'range', 'forex.view'],
        'forex_register' => ['Foreign Guests & Forex', 'Currency Exchange Register', 'Every foreign currency purchase: guest, passport, currency, amount, rate, cedi value, amount paid, cash or bill credit and voids (RBI money-changing register)', 'range', 'forex.view'],
        'forex_surrenders' => ['Foreign Guests & Forex', 'Forex Surrender Register', 'Foreign exchange surrendered to the bank with realised gain/loss', 'range', 'forex.view'],
        'formc_register' => ['Foreign Guests & Forex', 'Form C (FRRO) Register', 'Every foreign national who checked in: nationality, passport, visa, room, deadline, when the Form C was filed, FRRO reference, hours taken and the departure report', 'range', 'formc.view'],
        'activity_bookings' => ['Front Office', 'Activity Booking Register', 'Every activity booking with guest, date and status', 'range', 'frontoffice.view'],
        'arrivals' => ['Front Office', 'Arrival List', 'Expected arrivals for the period', 'range'],
        'cancellations' => ['Front Office', 'Cancellation & No-Show', 'Cancelled and no-show reservations', 'range'],
        'company' => ['Front Office', 'Company-wise Business', 'Room nights & revenue by company/agent', 'range'],
        'departures' => ['Front Office', 'Departure List', 'Expected departures for the period', 'range'],
        'guest_documents' => ['Front Office', 'Guest Document Register', 'ID scans, photos, visas and other guest documents', 'range', 'frontoffice.view'],
        'guest_requests' => ['Front Office', 'Guest Requests & Response Times', 'Portal / phone requests by type: volumes, response & completion times, SLA breaches, ratings', 'range'],
        'inhouse' => ['Front Office', 'In-House Guest List', 'All guests currently checked in', 'none'],
        'loyalty_entries' => ['Front Office', 'Loyalty Entry Register', 'Every loyalty point earning and redemption', 'range', 'frontoffice.view'],
        'nationality' => ['Front Office', 'Nationality Report', 'Guest arrivals by nationality', 'range'],
        'occupancy' => ['Front Office', 'Occupancy Statistics', 'Day-wise occupancy, ADR and RevPAR', 'range'],
        'police' => ['Front Office', 'Police / Form-C Report', 'Foreign national check-ins', 'range'],
        'reservation_amendments' => ['Front Office', 'Reservation Amendment Log', 'Every amendment to a reservation', 'range', 'frontoffice.view'],
        'reservation_desk_views' => ['Front Office', 'Reservation Desk View Register', 'Every saved reservation desk view', 'range', 'frontoffice.view'],
        'reservation_waitlist' => ['Front Office', 'Reservation Waitlist Register', 'Every waitlist entry with dates, party and status', 'range', 'frontoffice.view'],
        'reservations' => ['Front Office', 'Reservation Register', 'Reservations created in the period', 'range'],
        'room_amenities' => ['Front Office', 'Room Amenity Register', 'Every room amenity with name, type and active', 'none', 'frontoffice.view'],
        'room_amenity_links' => ['Front Office', 'Room Amenity Link Register', 'Every room-to-amenity mapping', 'none', 'frontoffice.view'],
        'room_block_lines' => ['Front Office', 'Room Block Line Register', 'Every room block allocation by room type', 'none', 'frontoffice.view'],
        'room_blocks' => ['Front Office', 'Room Block Register', 'Every room block allocation: group, room type, dates, rooms and status', 'range', 'frontoffice.view'],
        'room_counts' => ['Front Office', 'Room Count Register', 'Daily room count by type and status — available, occupied, out of order, blocked', 'range', 'frontoffice.view'],
        'room_images' => ['Front Office', 'Room Image Register', 'Every room photo uploaded to the system', 'none', 'frontoffice.view'],
        'room_moves' => ['Front Office', 'Room Move Register', 'Every guest room move with from, to and reason', 'range', 'frontoffice.view'],
        'room_outages' => ['Front Office', 'Room Outage Register', 'Every room outage with reason, dates and status', 'range', 'frontoffice.view'],
        'source' => ['Front Office', 'Business Source Analysis', 'Room nights & revenue by source', 'range'],
        'stay_fixed_charges' => ['Front Office', 'Stay Fixed Charge Register', 'Every stay fixed charge applied to a reservation', 'range', 'frontoffice.view'],
        'upsell_offers' => ['Front Office', 'Upsell Offer Register', 'Every upsell offer with type, value and active', 'range', 'frontoffice.view'],
        'wakeup_attempts' => ['Front Office', 'Wakeup Attempt Register', 'Every wakeup call attempt with status', 'range', 'frontoffice.view'],
        'accrual_register' => ['General Ledger', 'Accruals & Prepayments Register', 'Accrued expenses, prepaid expenses and deferred income: amount, posted, balance remaining and next month to post', 'date', 'close.view'],
        'balance_sheet' => ['General Ledger', 'Balance Sheet', 'Assets, liabilities and equity as at a date', 'date', 'gl.view'],
        'close_status' => ['General Ledger', 'Period Close Status', 'Month-end close by period: checklist cleared, waivers, preparer and approver, days taken to close', 'range', 'close.view'], // v5.6
        'fiscal_year_closes' => ['General Ledger', 'Fiscal Year Close Register', 'Every fiscal year close with closing journal and performer', 'range', 'accounts.view'],
        'gl_journals' => ['General Ledger', 'General Ledger Journals', 'Every GL journal with entry, period and status', 'range', 'accounts.view'],
        'gl_pnl' => ['General Ledger', 'Profit & Loss (GL)', 'Income statement from the double-entry ledger, by account group', 'range', 'gl.view'],
        'ia_authorisations' => ['General Ledger', 'Comps, Discounts & Rate Authorisations', 'Complimentary and house-use rooms, below-expected rates and folio discounts requested in the period, the revenue given away, who asked and who approved', 'range', 'income_audit.view'],
        'ia_findings' => ['General Ledger', 'Audit Exceptions Register', 'Every income-audit exception raised for business dates in the period: check, severity, room / document, value and how it was resolved (explained, corrected, escalated, accepted)', 'range', 'income_audit.view'], // v5.3
        'ia_rate_variance' => ['General Ledger', 'Room Rate Variance', 'Each occupied room night: tariff charged against the expected rate (rate plan, contract, group block or rack less booking discount), variance and the authorisation covering it (max 31 days)', 'range', 'income_audit.view'],
        'ia_reconciliation' => ['General Ledger', 'Daily Revenue Reconciliation', 'Per business date: night-audit report vs the baseline at close vs now for room / other revenue, tax and collections, discounts, GL income, open exceptions and sign-off', 'range', 'income_audit.view'],
        'journal_register' => ['General Ledger', 'Journal Register (Day Book)', 'Every journal line posted in the period', 'range', 'gl.view'],
        'period_end_postings' => ['General Ledger', 'Period-end Postings', 'Journals made from schedules and recurring templates in the period, with auto-reversals', 'range', 'close.view'],
        'recurring_journals' => ['General Ledger', 'Recurring Journal Register', 'Every recurring journal template', 'none', 'accounts.view'],
        'trial_balance' => ['General Ledger', 'Trial Balance', 'Opening, period movement and closing balance of every account', 'range', 'gl.view'],
        'guest_messages' => ['Guest Messaging', 'Guest Messages & Parcels', 'Messages and parcels taken for guests: from, channel, how and when the guest was told, delivery time and outcome', 'range', 'desk.messages'],
        'inbox_agents' => ['Guest Messaging', 'Messaging Team Performance', 'Per staff member: conversations, replies, internal notes, average first response, share within target, conversations closed and average guest rating', 'range', 'inbox.view'],
        'inbox_broadcasts' => ['Guest Messaging', 'Inbox Broadcasts Register', 'Detailed register of Inbox Broadcasts records', 'range', 'inbox.view'],
        'inbox_conversations' => ['Guest Messaging', 'Guest Conversations', 'Every guest conversation started in the period: channel (portal chat, WhatsApp, SMS, e-mail), guest, room, team, assigned staff, messages each way, first response time, guest rating and status', 'range', 'inbox.view'], // v7.3
        'inbox_quick_replies' => ['Guest Messaging', 'Inbox Quick Replies Register', 'Detailed register of Inbox Quick Replies records', 'range', 'inbox.view'],
        'inbox_response' => ['Guest Messaging', 'Messaging Response Times by Day', 'Per day: conversations, guest messages, staff, automatic and broadcast replies, average and slowest response and the share answered within the response-time target', 'range', 'inbox.view'],
        'inbox_topics' => ['Guest Messaging', 'Messaging Topics & Automatic Answers', 'What guests write about: conversations per team with channel mix and rating, and how often each keyword rule answered automatically', 'range', 'inbox.view'],
        'hr_announcements' => ['HR & People', 'HR Announcement Register', 'Every internal HR announcement', 'none', 'hr.view'],
        'hr_documents' => ['HR & People', 'HR Document Register', 'Every employee document with verification status', 'none', 'hr.view'],
        'hr_profile_updates' => ['HR & People', 'HR Profile Update Register', 'Every employee profile change request with approval status', 'none', 'hr.view'],
        'club_attendance' => ['Health Club & Spa', 'Club Attendance & Utilisation', 'Visits by day and facility (members, hotel guests, day passes, members\' guests), refusals, overrides and class attendance', 'range', 'club.view'],
        'club_classes' => ['Health Club & Spa', 'Club Class Schedule', 'Every club class with instructor, capacity and bookings', 'none', 'club.view'],
        'club_members' => ['Health Club & Spa', 'Membership Register', 'Memberships current in the period by plan, with term, card holders, balance and visits; joins, renewals and leavers', 'range', 'club.view'], // v5.1
        'club_revenue' => ['Health Club & Spa', 'Club Revenue & Deferred Fees', 'Membership fees earned day by day, joining fees, guest & day passes, credit notes, receipts and the fees still held in advance', 'range', 'club.view'],
        'club_ticket_payments' => ['Health Club & Spa', 'Club Ticket Payment Register', 'Every payment on a club ticket sale', 'none', 'club.view'],
        'club_ticket_sale_items' => ['Health Club & Spa', 'Club Ticket Sale Item Register', 'Every line on a club ticket sale', 'none', 'club.view'],
        'club_ticket_sales' => ['Health Club & Spa', 'Club Ticket Sale Register', 'Every club ticket sale with type, price and guest', 'none', 'club.view'],
        'club_ticket_types' => ['Health Club & Spa', 'Club Ticket Type Register', 'Every club ticket type with category, price and active', 'none', 'club.view'],
        'spa_revenue' => ['Health Club & Spa', 'Spa & Activity Revenue', 'Appointments and revenue by service and therapist', 'range'],
        'ffe_reserve' => ['Hotel Group & Owner Reporting', 'FF&E Reserve Ledger & Capex', 'Reserve opening balance, contributions, interest, capex drawdowns and adjustments with a running balance, and each capital project\'s budget and spend', 'range', 'owner.view'],
        'hotel_groups' => ['Hotel Group & Owner Reporting', 'Hotel Group Register', 'The groups behind the properties you may open, each with its property and room counts and how many of its properties are licensed for nothing', 'none', 'hotels.view'],
        // v10.9 the consolidated view could not leave the screen: no print through the report engine,
        // no CSV, no schedule. This is that view as a report, read by the same service the dashboard
        // reads, with the same rule: a property licensed for nothing is out of the totals and named.
        'group_operating_summary' => ['Hotel Group & Owner Reporting', 'Consolidated Operating Summary', 'Every property you may open, each on its own business date: sellable rooms, occupancy, in-house guests, arrivals and departures, revenue today and month to date, ADR and collections, and how each is licensed — a property licensed for nothing is left out of the totals and named beneath them', 'none', 'hotels.view'],
        'hotel_kpis' => ['Hotel Group & Owner Reporting', 'Hotel KPIs by Month', 'Per calendar month: occupancy, ADR, RevPAR, TRevPAR, GOPPAR, cost per occupied room, GOP and EBITDA margins, labour cost % and F&B cost %', 'range', 'owner.view'],
        'management_agreements' => ['Hotel Group & Owner Reporting', 'Management Agreement Register', 'Every hotel management agreement with fee structure', 'none', 'hotels.view'],
        'management_fees' => ['Hotel Group & Owner Reporting', 'Management Fee Calculation', 'Monthly base fee, FF&E contribution, adjusted GOP, fee-year AGOP against the owner\'s priority, incentive fee, status and payment', 'range', 'owner.view'],
        'owner_queries' => ['Hotel Group & Owner Reporting', 'Owner Query Register', 'Every query raised by an owner on a statement, with replies', 'none', 'hotels.view'],
        'owner_statements' => ['Hotel Group & Owner Reporting', 'Owner Statement Register', 'Every issued owner statement with status and acknowledgement', 'none', 'hotels.view'],
        'usali_departments' => ['Hotel Group & Owner Reporting', 'USALI Departmental Schedules', 'Every ledger account under its USALI department and category (revenue, cost of sales, labour, other) with departmental profit and % of the department\'s revenue', 'range', 'owner.view'],
        'usali_summary' => ['Hotel Group & Owner Reporting', 'USALI Summary Operating Statement', 'USALI 11th edition statement for any period: revenue by department, departmental and undistributed expenses, GOP, management fees, non-operating items, EBITDA, replacement reserve and net income with % of revenue', 'range', 'owner.view'], // v5.8
        'admin_call_usage' => ['Hotel Interfaces', 'Administrative Call Usage', 'Calls from administrative and public extensions by department and extension: calls, minutes and carrier cost', 'range', 'calls.view'],
        'api_usage' => ['Hotel Interfaces', 'API Usage by Client', 'Per API client and call: requests, successes, client (4xx) and server (5xx) errors, error rate, average response time and the last call – plus calls refused for want of a valid key', 'range', 'api.view'],
        'call_log' => ['Hotel Interfaces', 'Call Accounting Log', 'Every call from the PABX in the period: time, extension, room / guest, number (masked), class, duration, carrier cost, guest charge and status', 'range', 'calls.view'],
        'call_revenue' => ['Hotel Interfaces', 'Telephone Revenue & Margin', 'Guest calls by class: calls, minutes, carrier cost, amount charged and margin – plus unbilled (pending / waived) calls', 'range', 'calls.view'],
        'interface_commands' => ['Hotel Interfaces', 'Interface Command Register', 'Every interface command sent to a hotel system', 'range', 'interfaces.view'],
        'key_card_audit' => ['Hotel Interfaces', 'Key Card Audit', 'Guest key cards encoded in the period: room, holder, type, valid until, status, who issued and lost-card fees', 'range', 'keys.view'],
        'master_key_register' => ['Hotel Interfaces', 'Master Key Register', 'Master-key sign-outs in the period: key, holder, purpose, out, due, returned, late / lost', 'range', 'keys.view'],
        'webhook_deliveries' => ['Hotel Interfaces', 'Webhook Delivery Performance', 'Per endpoint and event: events queued, delivered, still retrying and given up, first-attempt success, average attempts and response time', 'range', 'api.view'],
        'hk_productivity' => ['Housekeeping', 'Attendant Productivity', 'Rooms, credits, minutes per room, DND and re-cleans per attendant', 'range'],
        'maintenance' => ['Housekeeping', 'Maintenance Report', 'Tickets raised in the period', 'range'],
        'minibar' => ['Housekeeping', 'Minibar Sales', 'Minibar consumption posted to guest folios', 'range'],
        'minibar_items' => ['Housekeeping', 'Minibar Item Register', 'Every minibar item with price, stock and active', 'range', 'housekeeping.view'],
        'room_status' => ['Housekeeping', 'Room Status Report', 'Current occupancy and housekeeping status', 'none'],
        'iptv_channels' => ['IPTV & Guest Technology', 'Channel Line-up & Viewing', 'Every channel: number, category, language, stream, guide id, programmes still ahead in the guide and views in the last 30 days', 'none', 'iptv.view'],
        'iptv_devices' => ['IPTV & Guest Technology', 'TV & Screen Register', 'Every in-room TV and lobby screen: room / location, platform, model, MAC, serial, status, last heartbeat, online now, paired date and the guest in the room', 'none', 'iptv.view'], // v7.1
        'iptv_engagement' => ['IPTV & Guest Technology', 'TV Engagement by Day', 'Per day: TVs used, screen actions, welcome screens seen, channel views, movie rentals, dining orders and service requests from the TV, with the share of stays using the TV portal', 'range', 'iptv.view'],
        'iptv_messages' => ['IPTV & Guest Technology', 'TV Message & Emergency Log', 'Room messages, broadcasts and emergency alerts sent to TVs in the period: audience, timing, sender, reads, withdrawal / all-clear note', 'range', 'iptv.view'],
        'iptv_orders' => ['IPTV & Guest Technology', 'Orders & Requests from the TV', 'In-room dining orders, service requests, express check-outs and wake-up calls guests placed on the room TV, with status and value', 'range', 'iptv.view'],
        'iptv_vod' => ['IPTV & Guest Technology', 'Movie Rentals (Pay TV)', 'Movie rentals in the period: room, guest, title, amount before tax, VAT & levies, total charged to the folio, refunds with reason and approver', 'range', 'iptv.view'],
        'tv_commands' => ['IPTV & Guest Technology', 'TV Command Register', 'Every IPTV command sent to a device', 'none', 'iptv.view'],
        'tv_message_reads' => ['IPTV & Guest Technology', 'TV Message Read Register', 'Every IPTV message read on a device', 'none', 'iptv.view'],
        'tv_movies' => ['IPTV & Guest Technology', 'TV Movie Register', 'Every movie available on the IPTV system', 'none', 'iptv.view'],
        'tv_pages' => ['IPTV & Guest Technology', 'TV Page Register', 'Every IPTV page: name, type, content and active', 'none', 'iptv.view'],
        'tv_programmes' => ['IPTV & Guest Technology', 'TV Programme Register', 'Every TV programme with channel, time and duration', 'none', 'iptv.view'],
        'tv_sessions' => ['IPTV & Guest Technology', 'TV Session Register', 'Every IPTV viewing session with guest and device', 'none', 'iptv.view'],
        'tv_welcome_templates' => ['IPTV & Guest Technology', 'TV Welcome Template Register', 'Every guest welcome template on the TV system', 'none', 'iptv.view'],
        'consumption' => ['Inventory & Purchasing', 'Consumption / Issue Report', 'Issues and wastage by item', 'range'],
        'dept_consumption' => ['Inventory & Purchasing', 'Department Consumption vs Budget', 'Stock issued to each department in the period (expensed, transferred to its store, ad-hoc) against its pro-rated budget, with cost per occupied room', 'range', 'requisitions.view'],
        'goods_received' => ['Inventory & Purchasing', 'Goods Received Register', 'Every delivery in the period by GRN and item: delivered, rejected (with reason) and accepted quantities, PO rate and accepted value – single-step receipts included', 'range'], // v4.8
        'grn_lines' => ['Inventory & Purchasing', 'Goods Receipt Line Register', 'Every goods receipt line with quantity and cost', 'range', 'inventory.view'],
        'historical_stock_movement_archives' => ['Inventory & Purchasing', 'Historical Stock Movement Archive', 'Archived stock movements', 'range', 'inventory.view'],
        'inventory_items' => ['Inventory & Purchasing', 'Inventory Item Register', 'Every inventory item: name, category, unit, par, stock, value and status', 'none', 'inventory.view'],
        'item_categories' => ['Inventory & Purchasing', 'Item Category Register', 'Every inventory item category', 'none', 'inventory.view'],
        'open_purchase_orders' => ['Inventory & Purchasing', 'Open Purchase Orders & Overdue Deliveries', 'Approved and part-received PO lines still to be delivered on the date: ordered, received, outstanding quantity and value, expected date and days overdue', 'date'],
        'po_items' => ['Inventory & Purchasing', 'Purchase Order Item Register', 'Every item line on a purchase order', 'range', 'inventory.view'],
        'purchases' => ['Inventory & Purchasing', 'Purchase Register', 'Purchase orders in the period', 'range'],
        'req_pending' => ['Inventory & Purchasing', 'Outstanding Requisition Lines', 'Approved quantities not yet issued: balance, stock in the issuing store and age – the store\'s to-do list', 'none', 'requisitions.view'],
        'req_register' => ['Inventory & Purchasing', 'Requisition Register', 'Store requisitions raised in the period: department, status, requested / approved / issued value, fill rate and turnaround', 'range', 'requisitions.view'],
        'requisition_lines' => ['Inventory & Purchasing', 'Requisition Line Register', 'Every line on a purchase requisition', 'range', 'inventory.view'],
        'rfq_lines' => ['Inventory & Purchasing', 'RFQ Line Register', 'Every item line on a request for quotation', 'range', 'inventory.view'],
        'rfq_quote_lines' => ['Inventory & Purchasing', 'RFQ Quote Line Register', 'Every line on an RFQ quote', 'range', 'inventory.view'],
        'rfq_quotes' => ['Inventory & Purchasing', 'RFQ Quote Register', 'Every quote received on an RFQ', 'range', 'inventory.view'],
        'rfq_register' => ['Inventory & Purchasing', 'RFQ Register', 'Every request for quotation with status and totals', 'range', 'inventory.view'],
        'rfq_suppliers' => ['Inventory & Purchasing', 'RFQ Supplier Register', 'Every supplier invited to an RFQ', 'range', 'inventory.view'],
        'stock_take_lines' => ['Inventory & Purchasing', 'Stock Take Line Register', 'Every line on a stock take', 'range', 'inventory.view'],
        'stock_takes' => ['Inventory & Purchasing', 'Stock Take Register', 'Every stock take with period, status and variances', 'range', 'inventory.view'],
        'store_issue_lines' => ['Inventory & Purchasing', 'Store Issue Line Register', 'Every line on a store issue slip', 'range', 'inventory.view'],
        'store_pars' => ['Inventory & Purchasing', 'Store Par Register', 'Every store par level by item and store', 'range', 'inventory.view'],
        'supplier_invoice_lines' => ['Inventory & Purchasing', 'Supplier Invoice Line Register', 'Every line on a supplier invoice', 'range', 'inventory.view'],
        'supplier_prices' => ['Inventory & Purchasing', 'Supplier Price Register', 'Every supplier price by item and currency', 'range', 'inventory.view'],
        'supplier_scorecard' => ['Inventory & Purchasing', 'Supplier Scorecard', 'Supplier performance in the period: on-time delivery, fill rate, quality (rejections), price compliance against the agreed price list, RFQ response rate, lead time and overall grade', 'range'],
        'laundry_items' => ['Laundry & Linen', 'Laundry Items Register', 'Detailed register of Laundry Items records', 'none', 'inventory.view'],
        'laundry_order_lines' => ['Laundry & Linen', 'Laundry Order Lines Register', 'Detailed register of Laundry Order Lines records', 'none', 'inventory.view'],
        'laundry_register' => ['Laundry & Linen', 'Laundry Register', 'Guest & walk-in laundry orders collected in the period: pieces, express surcharge, amount, status and where it was charged', 'range', 'laundry.view'],
        'linen_batch_lines' => ['Laundry & Linen', 'Linen Batch Lines Register', 'Detailed register of Linen Batch Lines records', 'none', 'inventory.view'],
        'linen_batches' => ['Laundry & Linen', 'Linen Batch Register', 'Every linen batch: type, quantity, status, laundry cycle and outstanding', 'none', 'inventory.view'],
        'linen_losses' => ['Laundry & Linen', 'Linen Losses & Write-offs', 'Linen discarded as damaged or lost (on the floors or by the laundry) in the period, at cost', 'range', 'laundry.view'],
        'linen_par' => ['Laundry & Linen', 'Linen Stock & Par', 'Every linen type by location (clean, floors, soiled, at laundry) against par, with shortfall and stock value', 'none', 'laundry.view'],
        'sop_acknowledgement' => ['Learning & Development', 'SOP Sign-off Status', 'Every published SOP that staff must sign: version, effective date, review due, staff in scope, signed, outstanding and who has not signed', 'none', 'learning.view'],
        'sop_acknowledgements' => ['Learning & Development', 'SOP Acknowledgement Register', 'Every employee acknowledgement of a SOP', 'none', 'training.view'],
        'sop_documents' => ['Learning & Development', 'SOP Document Register', 'Every SOP document, its version, owner and next review date', 'none', 'training.view'],
        'sop_versions' => ['Learning & Development', 'SOP Version Register', 'Every version of a SOP', 'none', 'training.view'],
        'training_compliance' => ['Learning & Development', 'Training Matrix Compliance', 'Every employee against the training their department / job title requires: current, expiring, expired, overdue, new-starter due and compliance %', 'none', 'learning.view'],
        'training_courses' => ['Learning & Development', 'Training Course Register', 'Every training course with hours and active', 'none', 'training.view'],
        'training_expiry' => ['Learning & Development', 'Certificates Expiring', 'Staff certificates (fire, first aid, food hygiene…) expiring in the date range – whether a renewal session is booked – so refreshers can be planned', 'range', 'learning.view'],
        'training_hours' => ['Learning & Development', 'Training Hours & Spend by Department', 'Training hours delivered, staff trained, hours per head, cost and cost per head by department, with the courses taken', 'range', 'learning.view'],
        'training_records' => ['Learning & Development', 'Training Records Register', 'Every training outcome in the period: employee, course, in-house session or external, result, score, hours, cost and certificate number', 'range', 'learning.view'],
        'training_requirements' => ['Learning & Development', 'Training Requirement Register', 'Every training requirement by course and department', 'none', 'training.view'],
        'training_sessions' => ['Learning & Development', 'Training Session Register', 'Every training session scheduled and attended', 'none', 'training.view'],
        'email_logs' => ['Management & MIS', 'Email Log Register', 'Every email sent from the system', 'range'],
        'flash' => ['Management & MIS', 'Flash Report (Day / MTD / YTD)', "Manager's daily flash: occupancy, ADR, RevPAR, revenue, collections", 'date'],
        'guest_history' => ['Management & MIS', 'Top Guests', 'Repeat guests by stays and revenue', 'range'],
        'kpi_alert_log' => ['Management & MIS', 'KPI Alert Log', 'KPI threshold breaches by business date, actual vs threshold, acknowledgement', 'range', 'reports.alerts'], // v5.4
        'kpi_alerts' => ['Management & MIS', 'KPI Alert Register', 'Every KPI threshold breach alert', 'none'],
        'message_logs' => ['Management & MIS', 'Message Log Register', 'Every user activity message log', 'range'],
        'mis' => ['Management & MIS', 'Night Audit MIS', 'Night audit statistics', 'range'],
        'report_schedules' => ['Management & MIS', 'Report Schedule Register', 'Every scheduled report with frequency and recipients', 'none'],
        'report_views' => ['Management & MIS', 'Report View Register', 'Every saved report view', 'none'],
        'rpt_deliveries' => ['Management & MIS', 'Scheduled Report Deliveries', 'Every scheduled / sent-now report e-mail: period, recipients, rows, failures', 'range', 'reports.schedule'], // v5.4
        'user_activity' => ['Management & MIS', 'User Activity Summary', 'Actions per user and module', 'range'],
        'concierge_places' => ['Marketing & CRM', 'Concierge Place Register', 'Every concierge recommendation with category and rating', 'none', 'marketing.view'],
        'crm_campaigns' => ['Marketing & CRM', 'Campaign & Automation Performance', 'Per campaign / automation: recipients, delivered, skipped, clicks, CTR, unsubscribes, attributed bookings, room nights and revenue', 'range', 'crm.view'],
        'crm_guest_value' => ['Marketing & CRM', 'Guest Value (RFM)', 'Every guest with a stay: recency, frequency and monetary scores, value segment, consent and last stay – who to win back, who to reward', 'none', 'crm.view'],
        'crm_promos' => ['Marketing & CRM', 'Promo Code Redemptions', 'Bookings made with each promo code in the period: room nights, revenue after discount, discount given and cancellations', 'range', 'crm.view'],
        'cro_contacts' => ['Marketing & CRM', 'CRO Contact Register', 'Every CRO contact with company, role and status', 'none', 'marketing.view'],
        'digital_content' => ['Marketing & CRM', 'Content Calendar & Approvals', 'Posts scheduled in the period with channels, status, approver, approval turnaround, reach and engagement; missed slots highlighted', 'range', 'digital.view'],
        'digital_influencers' => ['Marketing & CRM', 'Influencer Collaborations', 'Creator collaborations: fee, complimentary value, reach, engagements, cost per engagement, bookings via link and rating', 'range', 'digital.view'],
        'digital_links' => ['Marketing & CRM', 'Tracked Link Attribution', 'Clicks, unique visitors, device mix and credited direct bookings, nights and revenue per tracked link and UTM source / medium', 'range', 'digital.view'],
        'digital_paid' => ['Marketing & CRM', 'Paid Media Performance', 'Paid campaigns: spend vs budget, impressions, clicks, CTR, CPC, platform conversions & ROAS, and bookings / revenue attributed in the PMS', 'range', 'digital.view'],
        'digital_social' => ['Marketing & CRM', 'Social Media Performance', 'Followers at start / end, growth, reach, impressions, engagements, engagement rate and posts per social channel', 'range', 'digital.view'], // v6.2
        'dm_ad_stats' => ['Marketing & CRM', 'Digital Marketing Ad Stats', 'Daily ad performance by channel: impressions, clicks, cost and conversions', 'range', 'marketing.view'],
        'dm_link_clicks' => ['Marketing & CRM', 'Digital Marketing Link Clicks', 'Every tracked link click with source and device', 'range', 'marketing.view'],
        'marketing_budget' => ['Marketing & CRM', 'Marketing Budget vs Actual', 'Marketing budget by line for the months in the period against actual spend and committed (planned) events', 'range', 'sales.view'],
        'marketing_collateral' => ['Marketing & CRM', 'Marketing Collateral Register', 'Every brochure, video, photo and other marketing asset', 'none', 'marketing.view'],
        'marketing_consent_logs' => ['Marketing & CRM', 'Marketing Consent Log Register', 'Every marketing consent change recorded', 'none', 'marketing.view'],
        'marketing_messages' => ['Marketing & CRM', 'Marketing Message Register', 'Every marketing message with delivery and engagement status', 'none', 'marketing.view'],
        'marketing_roi' => ['Marketing & CRM', 'Marketing Event ROI', 'Marketing events starting in the period: budget, actual cost, leads, cost per lead, opportunities and revenue won, ROI', 'range', 'sales.view'],
        'marketing_segments' => ['Marketing & CRM', 'Marketing Segment Register', 'Every guest segment with campaign and journey counts', 'none', 'marketing.view'],
        'bank_matches' => ['Treasury & Payments', 'Bank Reconciliation Match Register', 'Every bank transaction matched to a payment', 'range'],
        'business_sources' => ['Sales & Business Development', 'Business Source Register', 'Every business source: name, type, commission and active', 'none'],
        'organiser_access' => ['Partner & Organiser Portals', 'Organiser Portal Access Audit', 'Security trail of organiser logins: sign-ins, failed passwords, lock-outs, sign-outs, password changes / resets and user administration, with IP address; failures and lock-outs highlighted', 'range', 'organiser.view'],
        'organiser_events' => ['Partner & Organiser Portals', 'Organiser Portal Events', 'Every event the hotel shares online right now: organiser, functions, status, active portal logins, BEO state (draft / published / signed), deposits paid, portal online payments and the balance still owed', 'none', 'organiser.view'], // v7.5
        'organiser_payments' => ['Partner & Organiser Portals', 'Organiser Portal Online Payments', 'Every online deposit and balance payment event organisers made in the portal during the period: event, payer, channel, amount, gateway reference and status', 'range', 'organiser.view'],
        'organiser_requests' => ['Partner & Organiser Portals', 'Organiser Requests & Response Times', 'Change requests, cancellations and quote requests received in the period with who answered, the answer and the hours the organiser waited; open requests past 24 hours highlighted', 'range', 'organiser.view'],
        'partner_access' => ['Partner & Organiser Portals', 'Partner Portal Access Audit', 'Security trail of partner logins: sign-ins, failed passwords, lock-outs, sign-outs, password changes / resets and user administration, with IP address; failures and lock-outs highlighted', 'range', 'partner.view'],
        'partner_bookings' => ['Partner & Organiser Portals', 'Partner Portal Bookings', 'Every booking corporate clients and travel agents made in the partner portal during the period: partner, booker, traveller, dates, rooms, room nights, stay value, bill-to, reference and status (held for approval / confirmed / cancelled)', 'range', 'partner.view'], // v7.4
        'partner_production' => ['Partner & Organiser Portals', 'Partner Portal Production by Partner', 'Per corporate client / travel agent: active logins, sign-ins, portal bookings, room nights, stay value, cancellations and cancellation rate, held for approval and share of the partner\'s bookings made online', 'range', 'partner.view'],
        'partner_requests' => ['Partner & Organiser Portals', 'Partner Requests & Response Times', 'Booking approvals, change requests and group quotes received in the period with who answered, the answer and the hours the partner waited; open requests past 24 hours highlighted', 'range', 'partner.view'],
        'attendance' => ['Payroll', 'Attendance Register', 'Daily attendance summary by employee', 'range', 'payroll.view'],
        'employee_pay_components' => ['Payroll', 'Employee Pay Component Register', 'Every pay component for each employee', 'range', 'payroll.view'],
        'leave_register' => ['Payroll', 'Leave Register', 'Leave requests overlapping the period: employee, type, dates, days, status and approver', 'range', 'hr.leave'],
        'leave_types' => ['Payroll', 'Leave Type Register', 'Every leave type: name, days, accrual rate and active', 'none', 'payroll.view'],
        'pay_components' => ['Payroll', 'Pay Components Register', 'Detailed register of Pay Components records', 'range', 'payroll.view'],
        'paye_schedule' => ['Payroll', 'PAYE Monthly Schedule (GRA)', 'Per approved payroll month and employee: TIN / Ghana Card, basic, allowances, gross, SSNIT relief, chargeable income and PAYE withheld', 'range', 'payroll.view'],
        'payroll_register' => ['Payroll', 'Payroll Register', 'Per employee and month: paid days, LOP, overtime, earnings, each statutory deduction, net pay and employer cost', 'range', 'payroll.view'],
        'payroll_statutory' => ['Payroll', 'Statutory Dues Summary', 'Per month and liability: employee deductions + employer contributions owed (SSNIT Tier 1, Tier 2 pension, PAYE)', 'range', 'payroll.view'],
        'roster_vs_actual' => ['Payroll', 'Roster vs Actual', 'Per employee: rostered shifts and planned hours against time-clock hours worked, late arrivals, early departures, no-shows, unrostered work and manager corrections', 'range', 'roster.view'], // v4.9
        'roster_weeks' => ['Payroll', 'Roster Week Register', 'Every roster week with shift assignments and coverage', 'none', 'payroll.view'],
        'sc_distribution_lines' => ['Payroll', 'Payroll Distribution Line Register', 'Every payslip line distributed to an employee', 'range', 'payroll.view'],
        'service_charge_distribution' => ['Payroll', 'Service Charge Distribution', 'Service charge & tips shared with staff for distributions in the period: points, days worked, amount, status and the payroll run that paid it', 'range', 'servicecharge.manage'],
        'shift_swaps' => ['Payroll', 'Shift Swap Register', 'Every approved or requested shift swap between employees', 'none', 'payroll.view'],
        'shift_templates' => ['Payroll', 'Shift Templates Register', 'Detailed register of Shift Templates records', 'none', 'payroll.view'],
        'ssnit_schedule' => ['Payroll', 'SSNIT Contribution Schedule', 'Per approved payroll month and employee: SSNIT number, Ghana Card, basic salary, insurable earnings, Tier 1 (employee 5.5% + employer 8%) and Tier 2 (5%)', 'range', 'payroll.view'],
        'staff_advances' => ['Payroll', 'Staff Advance Register', 'Every staff advance with amount, repayment and balance', 'range', 'payroll.view'],
        'statutory_remittances' => ['Payroll', 'Statutory Remittance Register', 'SSNIT, Tier 2 and PAYE payments made in the period: month, payee, amount, due date, days late, reference and payment mode', 'range', 'payroll.view'],
        'time_punches_detail' => ['Payroll', 'Time Punch Detail', 'Every time punch: employee, in/out, hours and exceptions', 'range', 'payroll.view'],
        'timesheet' => ['Payroll', 'Timesheet (Time Clock)', 'Every time punch in the period: rostered shift, clock-in / out, source (self-service, kiosk, manager), hours worked, late / early minutes, corrections and approval', 'range', 'roster.view'],
        'tip_collections' => ['Payroll', 'Tip Collection Register', 'Every tip collection by employee and shift', 'range', 'payroll.view'],
        'energy_kpis' => ['Property & Utilities', 'Energy & Water KPIs', 'Per day: occupied rooms, guests, kWh, kWh per occupied room, water per guest night, utility cost per room and as % of revenue, CO₂ per room', 'range', 'utilities.view'],
        'lease_arrears' => ['Property & Utilities', 'Tenant Arrears Ageing', 'Overdue tenant invoices after the grace period by age (0–30, 31–60, 61–90, 90+ days) with the deposit held against each', 'range', 'leases.view'],
        'lease_expiry' => ['Property & Utilities', 'Lease Expiry & Renewals', 'Leases ending, on notice or due for a rent review in the period (from the start date to the end date), with the action needed', 'range', 'leases.view'],
        'lease_inspections' => ['Property & Utilities', 'Lease Inspection Register', 'Every property inspection with condition and damage costs', 'none', 'property.view'],
        'lease_meter_readings' => ['Property & Utilities', 'Lease Meter Reading Register', 'Every lease utility meter reading with consumption and charge', 'none', 'property.view'],
        'lease_rent_roll' => ['Property & Utilities', 'Rent Roll', 'Every unit with its tenant, term, cycle, monthly rent & service charge, deposit held, balance and rent invoiced / collected in the period', 'range', 'leases.view'], // v5.2
        'meter_readings' => ['Property & Utilities', 'Meter Reading Register', 'Every utility meter reading with consumption, cost and anomaly flags', 'none', 'property.view'],
        'utility_bill_reconciliation' => ['Property & Utilities', 'Utility Bill Reconciliation', 'Bills dated in the period: units billed vs metered, variance, charges, effective rate vs tariff and the AP document', 'range', 'utilities.view'],
        'utility_bills' => ['Property & Utilities', 'Utility Bill Register', 'Every utility bill with meter, period and amount', 'range', 'property.view'],
        'utility_consumption' => ['Property & Utilities', 'Utility Consumption by Meter', 'Per meter: readings, days covered, consumption, daily average, cost, estimated readings and anomalies – with sub-meter coverage of each main meter', 'range', 'utilities.view'],
        'qa_actions' => ['Quality Assurance', 'Corrective Action Log', 'Corrective actions raised in the period with owner, priority, due date, status, days to close and whether they closed on time or were reopened', 'range', 'qa.view'],
        'qa_audit_items' => ['Quality Assurance', 'QA Audit Item Register', 'Every audit finding with result, criticality and corrective action', 'none', 'qa.view'],
        'qa_audits' => ['Quality Assurance', 'QA Audit Register', 'Every audit completed in the period with checklist, department, auditor, score against the pass mark, findings (critical / repeat) and whether the department acknowledged it', 'range', 'qa.view'],
        'qa_department_scores' => ['Quality Assurance', 'QA Scores by Department', 'Average audit score per department and checklist type against the pass mark and target, pass rate, findings and open / overdue corrective actions', 'range', 'qa.view'],
        'qa_standard_failures' => ['Quality Assurance', 'Standards Failure Analysis', 'Every standard failed in the period: times checked, times failed, fail rate, repeat findings and critical flag – where to focus training', 'range', 'qa.view'],
        'qa_standards' => ['Quality Assurance', 'QA Standard Register', 'Every quality audit standard with department and criticality', 'none', 'qa.view'],
        'qa_template_compliance' => ['Quality Assurance', 'Audit Schedule Compliance', 'Each recurring checklist: audits required by its frequency in the period, audits done, compliance %, average score, last audit and next due date', 'range', 'qa.view'],
        'review_templates' => ['Quality Assurance', 'Review Template Register', 'Every guest review survey template', 'none', 'qa.view'],
        'rate_contract_blackouts' => ['Revenue Management', 'Rate Contract Blackout Register', 'Every blackout date on a rate contract', 'none', 'revenue.view'],
        'rate_contract_rates' => ['Revenue Management', 'Rate Contract Rate Register', 'Every contracted rate from OTA and channel manager', 'none', 'revenue.view'],
        'rate_overrides' => ['Revenue Management', 'Rate Override Register', 'Every rate override applied to a plan, date range or room type', 'none', 'revenue.view'],
        'rate_plans' => ['Revenue Management', 'Rate Plan Register', 'Every rate plan: name, room type, base rate, channels and active', 'none', 'revenue.view'],
        'rm_forecast' => ['Revenue Management', 'Forecast & On the Books', 'Per night: rooms booked & held, ADR, revenue, 7-day pickup and forecast occupancy / revenue', 'range', 'revenue.view'],
        'rm_pickup' => ['Revenue Management', 'Booking Pace (Pickup)', 'Per booking date: new bookings, room nights, revenue, average lead time & rate, cancellations', 'range', 'revenue.view'],
        'rm_snapshots' => ['Revenue Management', 'RM Snapshot Register', 'Every revenue management snapshot with revenue', 'range', 'revenue.view'],
        'yield_rules' => ['Revenue Management', 'Yield Rule Register', 'Every yield management rule with conditions, days and priority', 'none', 'revenue.view'],
        'account_production' => ['Sales & Business Development', 'Account Production', 'Room nights, stays and room revenue per corporate / agency account for the period vs the same period last year, with on-the-books business and account manager', 'range', 'sales.view'],
        'agent_commissions' => ['Sales & Business Development', 'Agent Commission Register', 'Every agent commission earned and paid', 'none', 'sales.view'],
        'bd_benchmark' => ['Sales & Business Development', 'Comp-Set Benchmark (MPI / ARI / RGI)', 'Monthly occupancy, ADR and RevPAR against the competitive set with the penetration, rate and revenue generation indices', 'range', 'bd.view'],
        'bd_competitors' => ['Sales & Business Development', 'BD Competitor Register', 'Every tracked competitor hotel', 'none', 'sales.view'],
        'bd_initiatives' => ['Sales & Business Development', 'Initiative Portfolio', 'Business development initiatives by stage: investment, annual revenue & net, payback, NPV, actual results vs plan and milestones', 'none', 'bd.view'],
        'bd_market_mix' => ['Sales & Business Development', 'Market Mix', 'Room nights and revenue by source market (guest nationality) and segment with share and growth vs the same period last year', 'range', 'bd.view'],
        'bd_milestones' => ['Sales & Business Development', 'BD Milestone Register', 'Every milestone with initiative, owner and overdue status', 'none', 'sales.view'],
        'bd_partners' => ['Sales & Business Development', 'Partnership Production', 'Bookings, room nights, revenue and commission credited to each strategic partnership, with renewal and review flags', 'range', 'bd.view'],
        'bd_rate_shop' => ['Sales & Business Development', 'Rate Shop', 'Our public rate vs the competitive set by stay date and channel: comp average, gap, price rank, lowest / highest competitor and sold-outs', 'range', 'bd.view'],
        'bd_rate_shop_lines' => ['Sales & Business Development', 'BD Rate Shop Line Register', 'Every competitor rate captured during a shop', 'none', 'sales.view'],
        'bd_results' => ['Sales & Business Development', 'BD Result Register', 'Monthly revenue and cost by initiative', 'none', 'sales.view'],
        'sales_activity' => ['Sales & Business Development', 'Sales Activity Report', 'Completed sales calls, visits, site inspections, meetings and entertainment in the period by salesperson, with spend', 'range', 'sales.view'],
        'sales_contacts' => ['Sales & Business Development', 'Sales Contact Register', 'Every sales contact with company, role and status', 'range', 'sales.view'],
        'sales_opportunities' => ['Sales & Business Development', 'Sales Opportunity Register', 'Every sales opportunity with value, stage and close date', 'range', 'sales.view'],
        'sales_pipeline' => ['Sales & Business Development', 'Sales Pipeline', 'Every open opportunity: account, owner, stage, expected close, room nights, value, probability and weighted value – with stage totals', 'none', 'sales.view'], // v6.1
        'sales_targets' => ['Sales & Business Development', 'Sales Targets vs Actual', 'Each salesperson per month in the period: room-night, revenue and activity targets against production of their accounts', 'range', 'sales.view'],
        'sales_win_loss' => ['Sales & Business Development', 'Win / Loss Analysis', 'Opportunities won and lost in the period by type, source and salesperson; win rate, value won and the reasons business was lost', 'range', 'sales.view'],
        'gate_pass_items' => ['Security', 'Gate Pass Item Register', 'Every item issued on a gate pass with outstanding value', 'range', 'security.view'],
        'gate_pass_returns' => ['Security', 'Gate Pass Return Register', 'Every returned gate pass with receiver and line count', 'range', 'security.view'],
        'security_gatepasses' => ['Security', 'Gate Pass Register', 'Gate passes raised in the period with party, purpose, items, value, approval, gate-out time and return status', 'range', 'security.view'],
        'security_incident_updates' => ['Security', 'Security Incident Update Register', 'Every update on a security incident', 'range', 'security.view'],
        'security_incidents' => ['Security', 'Security Incident Register', 'Incidents in the period by type and severity with location, guest, injuries, loss, police reference, status and resolution', 'range', 'security.view'],
        'security_outstanding' => ['Security', 'Goods Outside the Property', 'Returnable gate-pass items still out at a date: party, quantity outstanding, estimated value, due date and days overdue', 'date', 'security.view'],
        'security_visitors' => ['Security', 'Visitor Register', 'Every visitor in the period: pass, name, masked ID, whom they visited, purpose, time in / out, who let them in, overrides and watch-list matches', 'range', 'security.view'],
        'security_watch' => ['Security', 'Security Watch Register', 'Every security watch assignment', 'range', 'security.view'],
        'kiosk_adoption' => ['Self-Service Kiosk', 'Kiosk Adoption by Day', 'Per day: check-ins, how many were done at the kiosk and the share, check-outs at the kiosk, walk-in bookings, visits handed to the desk and average check-in time', 'range', 'kiosk.view'],
        'kiosk_assist' => ['Self-Service Kiosk', 'Kiosk Assistance Requests', 'Visits the kiosk handed to the front desk: reason, note, when, how long until someone dealt with it and who – with a summary by reason', 'range', 'kiosk.view'],
        'kiosk_payments' => ['Self-Service Kiosk', 'Kiosk Payments', 'Deposits, walk-in stays and bill balances guests paid by QR at the kiosk: payment link, booking, guest, purpose, amount and gateway status', 'range', 'kiosk.view'],
        'kiosk_sessions' => ['Self-Service Kiosk', 'Kiosk Guest Visits', 'Every kiosk visit in the period: kiosk, service (check-in / check-out / walk-in), guest, booking, room, how far it got, time taken, amount paid and outcome', 'range', 'kiosk.view'], // v7.2
        'kiosks' => ['Self-Service Kiosk', 'Kiosk Register', 'Every self-service kiosk with location and active status', 'none', 'kiosk.view'],
        'carbon_footprint' => ['Sustainability & ESG', 'Carbon Footprint (Scope 1 & 2)', 'Consumption × emission factor per energy source: t CO₂e, share, per occupied room and emissions avoided by solar', 'range', 'utilities.view'],
        'esg_carbon' => ['Sustainability & ESG', 'Carbon Inventory by Scope (GHG Protocol / HCMI)', 'Scope 1 fuels and refrigerant leaks, Scope 2 grid electricity, Scope 3 water, waste by route and outsourced laundry: activity, factor and kg CO₂e, with the HCMI footprint per occupied room and per m² of meeting space per hour', 'range', 'esg.view'], // v7.0
        'esg_checklist_items' => ['Sustainability & ESG', 'ESG Checklist Item Register', 'Every ESG checklist item', 'none', 'esg.view'],
        'esg_communities' => ['Sustainability & ESG', 'ESG Community Register', 'Every community engagement', 'none', 'esg.view'],
        'esg_green' => ['Sustainability & ESG', 'Green-Stay Nights', 'Nights on which a green-stay room was not serviced: room, guest, loyalty points and the estimated energy, water and carbon saved, with uptake against occupied room-nights', 'range', 'esg.view'],
        'esg_green_stays' => ['Sustainability & ESG', 'ESG Green Stay Register', 'Every green stay initiative', 'none', 'esg.view'],
        'esg_initiatives' => ['Sustainability & ESG', 'Green Initiative Register', 'Every initiative: category, owner, status, capital cost, annual saving, simple payback, energy / water / waste / carbon savings, approver and completion', 'none', 'esg.view'],
        'esg_laundry_months' => ['Sustainability & ESG', 'ESG Laundry Month Register', 'Monthly laundry consumption', 'none', 'esg.view'],
        'esg_local_spend' => ['Sustainability & ESG', 'Local Sourcing Spend', 'Supplier bills in the period by supplier (net of debit notes, before tax) with the local flag and the share of spend with local suppliers', 'range', 'esg.view'],
        'esg_policies' => ['Sustainability & ESG', 'ESG Policy Register', 'Every ESG policy with its owner, review date and approval status', 'none', 'esg.view'],
        'esg_refrigerants' => ['Sustainability & ESG', 'F-gas (Refrigerant) Log', 'Refrigerant added and recovered in the period by system: gas, GWP, work done, technician and certificate, kg CO₂e, with the annual leak rate per system', 'range', 'esg.view'],
        'esg_targets' => ['Sustainability & ESG', 'ESG Target Register', 'Every ESG target with its baseline, target year and progress to date', 'none', 'esg.view'],
        'esg_waste' => ['Sustainability & ESG', 'Waste Register & Diversion', 'Waste consignments in the period: stream, disposal route, weight, department, contractor, waste transfer note, cost and income, kg CO₂e, with the diversion rate and kg per occupied room', 'range', 'esg.view'],
        'api_idempotency_keys' => ['System & Administration', 'API Idempotency Key Register', 'Every API idempotency key recorded', 'none', 'system.view'],
        'data_migration_batches' => ['System & Administration', 'Data Migration Batch Register', 'Every data migration batch with row counts', 'none', 'system.view'],
        'data_migration_rows' => ['System & Administration', 'Data Migration Row Register', 'Every migrated row, the batch it came from and whether it was accepted or skipped', 'none', 'system.view'],
        'log_entry_comments' => ['System & Administration', 'Log Entry Comment Register', 'Every comment added to an activity log entry', 'none', 'system.view'],
        'log_entry_reads' => ['System & Administration', 'Log Entry Read Register', 'Every time a user read an activity log entry', 'none', 'system.view'],
        'offline_sync_operations' => ['System & Administration', 'Offline Sync Operation Register', 'Every offline sync operation with payload and result', 'none', 'system.view'],
        'permissions' => ['System & Administration', 'Permission Register', 'Every system permission and its assigned roles', 'none', 'system.view'],
        'settings' => ['System & Administration', 'System Setting Register', 'Every system configuration setting', 'none', 'system.view'],
        'talent_appraisals' => ['Talent Management', 'Appraisal Results', 'Appraisals in cycles ending in the period: reviewer, goals and competency scores, overall rating and band, performance improvement plans, sign-off and acknowledgement', 'range', 'talent.appraise'],
        'talent_cycles' => ['Talent Management', 'Talent Cycle Register', 'Every recruitment cycle: position, department, status and pipeline', 'none', 'hr.view'],
        'talent_discipline' => ['Talent Management', 'Discipline & Grievance Register', 'Disciplinary cases and grievances in the period: category, severity, query and hearing dates, finding, sanction and warning expiry (confidential grievances masked unless you may see them)', 'range', 'talent.discipline'],
        'talent_exits' => ['Talent Management', 'Leavers & Turnover', 'Leavers with a last day in the period: type, length of service, statutory notice, pay in lieu, final-pay date, exit-interview reason, regretted and re-hire flags, with turnover rates', 'range', 'talent.discipline'],
        'talent_headcount' => ['Talent Management', 'Headcount vs Establishment', 'Per department on a date: approved establishment, staff in post, gap, open posts, starters and leavers in the last 12 months, turnover and staff on probation', 'date', 'talent.view'],
        'talent_interviews' => ['Talent Management', 'Talent Interview Register', 'Every interview scheduled with candidate, panel and outcome', 'none', 'hr.view'],
        'talent_offers' => ['Talent Management', 'Talent Offer Register', 'Every offer letter issued with position, salary and status', 'none', 'hr.view'],
        'talent_pipeline' => ['Talent Management', 'Recruitment Funnel & Sources', 'Applicants received in the period by vacancy: screened, interviewed, offered, hired and rejected, conversion, average interview score, and applications / hires by source', 'range', 'talent.recruit'],
        'talent_probations' => ['Talent Management', 'Talent Probation Register', 'Every probationary employee with completion status', 'none', 'hr.view'],
        'talent_scorecards' => ['Talent Management', 'Talent Scorecard Register', 'Every performance scorecard with rating and reviewer', 'none', 'hr.view'],
        'talent_tasks' => ['Talent Management', 'Talent Task Register', 'Every recruitment task with assignee, due date and status', 'none', 'hr.view'],
        'talent_vacancies' => ['Talent Management', 'Vacancy Register & Time to Fill', 'Vacancies raised in the period: department, posts, salary range, reason, requester and approver, applicants, hires and days from approval to filled', 'range', 'talent.view'], // v6.9
        'credit_notes' => ['Tax & Statutory', 'Credit Note Register', 'Credit notes issued in the period against earlier invoices: reason, taxable value, VAT / NHIL / GETFund / Tourism Levy, settlement (refund or company account) and E-VAT stamp', 'range', 'gst.view'],
        'einvoice_logs' => ['Tax & Statutory', 'Einvoice Logs Register', 'Detailed register of Einvoice Logs records', 'none', 'tax.view'],
        'einvoice_register' => ['Tax & Statutory', 'E-VAT Register', 'Invoices and credit notes dated in the period: buyer TIN, value, E-VAT SDC ID, receipt number and status – stamped, failed or not stamped', 'range', 'gst.view'],
        'gst_cancelled_docs' => ['Tax & Statutory', 'Gst Cancelled Docs Register', 'Detailed register of Gst Cancelled Docs records', 'none', 'tax.view'],
        'gst_periods' => ['Tax & Statutory', 'Gst Periods Register', 'Detailed register of Gst Periods records', 'none', 'tax.view'],
        'hsn_summary' => ['Tax & Statutory', 'VAT & Levies by Category', 'Outward supplies by charge category and rate for the period: taxable value, VAT, NHIL, GETFund, Tourism Levy, B2B and B2C', 'range', 'gst.view'],
        'wht_periods' => ['Tax & Statutory', 'Wht Periods Register', 'Detailed register of Wht Periods records', 'none', 'tax.view'],
        'wht_register' => ['Tax & Statutory', 'Withholding Tax Register (GRA)', 'Supplier payments in the period with the tax withheld: supplier TIN, nature of payment, taxable amount, rate, WHT, withholding VAT, net paid and certificate', 'range', 'ap.view'],
        'car_rentals' => ['Transport & Parking', 'Car Rental Register', 'Every car rental with guest, vehicle and dates', 'range', 'transport.view'],
        'parking_occupancy' => ['Transport & Parking', 'Car Park Occupancy by Zone', 'Each zone: bays, tickets by guest / visitor / permit, average stay, peak vehicles parked at once, bay utilisation and vehicles parked now', 'range', 'parking.view'],
        'parking_permits' => ['Transport & Parking', 'Parking Permit Register', 'Staff, tenant and contractor permits with vehicle, zone, validity, days left, fee, state and how often each was used', 'none', 'parking.view'],
        'parking_revenue' => ['Transport & Parking', 'Parking Revenue by Day', 'Parking revenue per business date: guest nights posted to room folios, visitor exit fees and valet, lost-ticket fees and amounts waived', 'range', 'parking.view'],
        'parking_sessions' => ['Transport & Parking', 'Parking Ticket Register', 'Every parking ticket issued in the period: plate, guest / visitor / permit, valet, zone, time in and out, duration, validation, lost tickets and what was charged or waived at the exit', 'range', 'parking.view'],
        'parking_valet' => ['Transport & Parking', 'Valet Retrieval Performance', 'Valet cars brought round per runner: average and longest minutes from request to the door, share within the target and how guests asked (QR, phone, desk)', 'range', 'parking.view'],
        'parking_vehicles' => ['Transport & Parking', 'Parking Vehicle Register', 'Every parking vehicle entry', 'range', 'transport.view'],
        'rental_vehicles' => ['Transport & Parking', 'Rental Vehicle Register', 'Every rental vehicle with type, capacity and active', 'range', 'transport.view'],
        'transport_drivers' => ['Transport & Parking', 'Driver Performance', 'Per driver: trips, km, hours on duty, on-time departures, no-shows, revenue and last trip', 'range', 'transport.view'],
        'transport_revenue' => ['Transport & Parking', 'Transport Revenue by Day', 'Day by day: pick-ups, drops and transfers, fares to the room bill, direct and complimentary, extras, no-shows and cancellations', 'range', 'transport.view'],
        'transport_tariffs' => ['Transport & Parking', 'Transport Tariff Register', 'Every airport, local and tour transport tariff', 'none', 'transport.view'],
        'transport_trips' => ['Transport & Parking', 'Transfer Register', 'Every pick-up, drop and local transfer in the period: time, guest, route, flight, car, driver, km, minutes late, fare, billing and status', 'range', 'transport.view'],
        'transport_vehicles' => ['Transport & Parking', 'Vehicle Utilisation & Running Cost', 'Per vehicle: trips, km driven, hours on the road, revenue, fuel & other running costs, cost per km, km per litre and margin', 'range', 'transport.view'],
        'fleet_maintenance' => ['Transport & Parking', 'Fleet Maintenance & Service Compliance', 'Per vehicle: planned and repair jobs completed, supplier cost, jobs still open, services overdue and the next due date or odometer', 'range', 'transport.fleet'],
        'fleet_gps' => ['Transport & Parking', 'Fleet GPS Activity', 'Per vehicle: fixes received, distance measured from the GPS trail, moving / parked share, last signal and trackers that are offline', 'range', 'transport.track'],
        'cash_flow' => ['Treasury & Payments', 'Cash-flow Statement (IAS 7)', 'Indirect method: profit, non-cash add-backs and working capital, investing and financing flows; opening + net = closing cash', 'range', 'treasury.view'], // v5.7
        'cash_forecast_lines' => ['Treasury & Payments', 'Cash Forecast Line Register', 'Every cash forecast line by date and source', 'range', 'accounts.view'],
        'cash_position' => ['Treasury & Payments', 'Cash Position', 'Book balance per bank, MoMo, cash box, float and clearing account as at a date, with minimums, unpresented cheques and last reconciliation', 'date', 'treasury.view'],
        'cheque_register' => ['Treasury & Payments', 'Cheque Register', 'Cheques received (post-dated, in hand, banked, cleared, bounced) and cheques written in the period', 'range', 'treasury.view'],
        'gateway_payouts' => ['Treasury & Payments', 'Gateway Payout Register', 'Every gateway payout with amount, fees and status', 'range', 'accounts.view'],
        'gift_voucher_register' => ['Treasury & Payments', 'Gift Voucher Register', 'Every voucher issued in the period: type, sold / promotional, purchaser & recipient, value, redeemed, balance and status', 'range', 'vouchers.view'],
        'online_payment_events' => ['Treasury & Payments', 'Online Payment Event Register', 'Every online payment webhook event received', 'range', 'accounts.view'],
        'online_refunds' => ['Treasury & Payments', 'Online Refund Register', 'Every online refund with payment, amount and reason', 'range', 'accounts.view'],
        'payment_modes' => ['Treasury & Payments', 'Payment Mode Register', 'Every payment mode: name, type, active and config', 'none', 'accounts.view'],
        'transfer_register' => ['Treasury & Payments', 'Banking & Transfer Register', 'Cash / cheque bankings, settlement sweeps and bank transfers: requested, approved, sent, credited, fees and short / over banking', 'range', 'treasury.view'],
        'voucher_breakage' => ['Treasury & Payments', 'Voucher Breakage & Cancellations', 'Balances that lapsed at expiry (breakage income / promotional cost released) and cancelled / refunded vouchers', 'range', 'vouchers.view'],
        'voucher_liability' => ['Treasury & Payments', 'Voucher Liability (Outstanding)', 'Unspent voucher balances as at a date by expiry bucket – reconciled to GL 2120 Gift Vouchers Outstanding', 'date', 'vouchers.view'],
        'voucher_redemptions' => ['Treasury & Payments', 'Voucher Redemptions', 'Every redemption and reversal: where it was spent (front office folio / outlet bill), amount and cashier', 'range', 'vouchers.view'],
        'voucher_sales' => ['Treasury & Payments', 'Voucher Sales Summary', 'Per voucher type: vouchers sold and given away, value sold, reloads, redeemed and lapsed – plus sales by payment mode', 'range', 'vouchers.view'],
        'upsell_conversion' => ['Front Office', 'Upsell Conversion by Channel', 'Guests shown offers per channel, how many opened them and how many bought – conversion rate, orders and revenue per guest offered', 'range', 'upsell.view'],
        'upsell_incentives' => ['Front Office', 'Staff Upsell Incentives', 'Per staff member: desk upsells confirmed, sales value, incentive earned and the offers sold – for the payroll incentive run', 'range', 'upsell.view'],
        'upsell_revenue' => ['Front Office', 'Upsell Revenue by Offer', 'Per offer: confirmed orders and revenue split by channel (pre-arrival, desk, in-house portal), average sale, share of upsell revenue, plus declined and cancelled orders', 'range', 'upsell.view'],
    ];

    /** The icon each report area shows in the library, and how a period reads. */
    /**
     * v10.11 the report parameters a view or a schedule may remember. A whitelist, because a saved
     * view posts a form and the last thing this engine needs is a form deciding what a report reads.
     *
     * v10.12 `by` joins the slice, and `budget`/`month` join both: the Budget vs Actual screen has always
     * linked to its own Print / CSV with the budget and month it was showing, and those two were read
     * straight off the request — so the link worked and the saved view and the scheduled run quietly
     * reported on a different month. A parameter a screen can show is a parameter the engine must read
     * through the same door, or the file is not the screen.
     */
    public const REPORT_PARAMS = ['slice', 'value', 'by', 'budget', 'month'];

    /** The parameters of the run in progress — set by the screen (from the URL or a view) or a schedule. */
    protected array $params = [];

    /** Run the next report with these parameters (a slice, a scope) instead of the URL's. */
    public function withParams(array $params): static
    {
        $this->params = array_filter($params, fn ($v) => is_string($v) && $v !== '');

        return $this;
    }

    /** One parameter of this run: what the caller set, else what the URL asked for. */
    protected function param(string $key): ?string
    {
        $value = $this->params[$key] ?? request()->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The parameters a form may hand us, kept to the whitelist and dropped when empty. */
    public static function paramsFrom(array $input): ?array
    {
        $params = array_filter(
            array_intersect_key($input, array_flip(self::REPORT_PARAMS)),
            fn ($v) => is_string($v) && $v !== ''
        );

        return $params ?: null;
    }

    public const GROUP_ICONS = [
        'Accounts' => 'wallet', 'Banquet & Events' => 'cup', 'Billing' => 'receipt', 'Budget & Planning' => 'chart',
        'Central Reservations' => 'phone', 'Channel Manager' => 'layers', 'Compliance & HSE' => 'shield',
        'Concierge & Activities' => 'plane', 'Front Office' => 'bed', 'F&B & POS' => 'coffee',
        'Feedback & Reputation' => 'star', 'Fixed Assets & Engineering' => 'tool', 'Food Safety' => 'thermo',
        'Foreign Guests & Forex' => 'globe', 'General Ledger' => 'book', 'Guest Messaging' => 'message',
        'HR & People' => 'users', 'Health Club & Spa' => 'spa', 'Hotel Group & Owner Reporting' => 'building',
        'Hotel Interfaces' => 'link', 'Housekeeping' => 'broom', 'IPTV & Guest Technology' => 'wifi',
        'Inventory & Purchasing' => 'box', 'Laundry & Linen' => 'shirt', 'Learning & Development' => 'check',
        'Management & MIS' => 'trend', 'Marketing & CRM' => 'mail', 'Treasury & Payments' => 'money',
        'Sales & Business Development' => 'trend', 'Partner & Organiser Portals' => 'external', 'Payroll' => 'id',
        'Property & Utilities' => 'house', 'Quality Assurance' => 'check', 'Revenue Management' => 'chart',
        'Security' => 'lock', 'Self-Service Kiosk' => 'qr', 'Sustainability & ESG' => 'tree',
        'System & Administration' => 'settings', 'Talent Management' => 'user', 'Tax & Statutory' => 'receipt',
        'Transport & Parking' => 'car',
    ];

    public const MODE_LABELS = ['range' => 'Period', 'date' => 'As on date', 'none' => 'Live'];

    /**
     * The report library.
     *
     * Half a thousand reports live behind this page, so it is built as a catalogue
     * rather than a link list: every report keeps its area, its one-line promise and
     * the period it answers on, and the page carries the saved views the reader can
     * open. Only reports the reader may open are listed.
     */
    public function index(Request $request)
    {
        $visible = collect(self::REPORTS)->filter(fn ($r) => can_do($r[4] ?? 'reports.view'));

        $groups = $visible->groupBy(fn ($r) => $r[0], true)
            ->map(fn ($reports, $group) => [
                'name' => $group,
                'icon' => self::GROUP_ICONS[$group] ?? 'chart',
                'reports' => $reports->map(fn ($r, $slug) => [
                    'slug' => $slug,
                    'title' => $r[1],
                    'desc' => $r[2],
                    'mode' => self::MODE_LABELS[$r[3] ?? 'range'] ?? 'Period',
                ])->values()->all(),
            ])
            ->sortKeys();

        $u = $request->user();
        $views = \App\Services\ReportScheduleService::viewsFor($u);

        return view('reports.index', [
            'groups' => $groups,
            'total' => $visible->count(),
            'views' => $views,
            'pinned' => $views->where('is_pinned', true)->where('user_id', $u->id)->values(),
            'mine' => $views->where('user_id', $u->id)->count(),
            'shared' => $views->where('user_id', '!=', $u->id)->count(),
            'schedules' => can_do('reports.schedule') ? \App\Models\ReportSchedule::where('user_id', $u->id)->count() : null,
        ]);
    }

    public function show(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        [$group, $title, $desc, $mode] = self::REPORTS[$report];
        abort_unless(can_do(self::REPORTS[$report][4] ?? 'reports.view'), 403);
        $defaultFrom = match ($report) { 'rm_forecast', 'channel_parity', 'lease_expiry', 'training_expiry', 'bd_rate_shop', 'cro_demand' => bdate(), 'bd_benchmark' => bdate()->copy()->subMonthsNoOverflow(12)->startOfMonth(), 'bd_partners', 'bd_market_mix' => bdate()->copy()->startOfYear(), 'hse_checks', 'hse_accidents', 'hse_permits', 'hse_drills' => bdate()->copy()->subMonthsNoOverflow(6)->startOfMonth(), 'esg_carbon' => Carbon::parse(\App\Services\EsgService::window()[0]), 'esg_waste', 'esg_green', 'esg_local_spend' => bdate()->copy()->startOfYear(), 'esg_refrigerants' => bdate()->copy()->subYear()->addDay(), 'iptv_engagement', 'iptv_orders', 'kiosk_sessions', 'kiosk_adoption', 'kiosk_payments', 'kiosk_assist', 'inbox_conversations', 'inbox_response', 'inbox_agents', 'inbox_topics', 'partner_bookings', 'partner_requests', 'partner_access', 'organiser_payments', 'organiser_requests', 'organiser_access' => bdate()->copy()->subDays(13), 'partner_production' => bdate()->copy()->subDays(29), 'talent_vacancies', 'talent_pipeline', 'talent_appraisals', 'talent_discipline' => bdate()->copy()->startOfYear(), 'talent_exits' => bdate()->copy()->subYear()->addDay(), 'talent_headcount' => bdate(), 'qa_audits', 'qa_department_scores', 'qa_standard_failures', 'qa_actions', 'qa_template_compliance' => bdate()->copy()->subDays(179), 'parking_sessions', 'parking_revenue', 'parking_occupancy', 'parking_valet' => bdate()->copy()->subDays(13), 'rm_pickup' => bdate()->copy()->subDays(13), 'payroll_register', 'payroll_statutory', 'ssnit_schedule', 'paye_schedule' => bdate()->copy()->subMonths(2)->startOfMonth(), default => bdate()->copy()->startOfMonth() };
        // v5.4 saved view: relative range resolved against today's business date (explicit from/to still win)
        $activeView = null;
        if ($request->filled('view')) {
            $activeView = \App\Models\ReportView::where('report_key', $report)->find($request->integer('view'));
            abort_unless($activeView && \App\Services\ReportScheduleService::canOpen($activeView, $request->user()), 404);
            [$vf, $vt] = \App\Services\ReportScheduleService::resolve($activeView->range, $activeView->date_from, $activeView->date_to);
            $defaultFrom = $vf;
            $viewTo = $vt;
            $activeView->forceFill(['last_opened_at' => now(), 'opens' => $activeView->opens + 1])->saveQuietly();
        }
        $from = Carbon::parse($request->input('from', $defaultFrom->toDateString()))->startOfDay();
        $defaultTo = in_array($report, ['lease_expiry', 'training_expiry'], true) ? bdate()->copy()->addDays(90) : ($report === 'rm_forecast' ? bdate()->copy()->addDays(29) : (in_array($report, ['channel_parity', 'bd_rate_shop', 'cro_demand'], true) ? bdate()->copy()->addDays(29) : (in_array($report, ['flash', 'trial_balance', 'gl_pnl', 'balance_sheet', 'journal_register', 'budget_vs_actual', 'budget_annual', 'forecast_vs_budget', 'budget_kpis']) ? bdate()->copy()->subDay() : bdate()))); // GL: last closed day
        $to = Carbon::parse($request->input('to', ($viewTo ?? $defaultTo)->toDateString()))->startOfDay();
        if ((self::REPORTS[$report][3] ?? '') === 'date') {
            $from = $to->copy(); // "as at" reports only use the date
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }
        // v10.11 a view remembers what the report was showing, not only when. The URL wins when it
        // names a parameter, so clicking another slice chip is not overridden by the view underneath.
        $requested = array_intersect_key($request->query(), array_flip(self::REPORT_PARAMS));
        $this->withParams(array_merge($activeView?->params ?? [], $requested));

        $data = $this->{'r_'.$report}($from, $to) + ['money' => [], 'sum' => []];
        $data['rows'] = collect($data['rows'])->values();
        $allCols = $data['cols'];
        if ($activeView) {
            $data = \App\Services\ReportScheduleService::shape($data, $activeView->columns, $activeView->filter);
        }

        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($data) {
                $out = fopen('php://output', 'w');
                fputcsv($out, array_values($data['cols']));
                foreach ($data['rows'] as $row) {
                    fputcsv($out, array_map(fn ($k) => $this->csvSafe($row[$k] ?? ''), array_keys($data['cols'])));
                }
                fclose($out);
            // an "as on" report is one day, so its file is named for that day, not a span
            }, $report.'_'.($mode === 'date' ? $to->format('Ymd') : $from->format('Ymd').'_'.$to->format('Ymd')).'.csv', ['Content-Type' => 'text/csv']);
        }

        $views = \App\Services\ReportScheduleService::viewsFor($request->user(), $report);
        $range = $activeView && ! $request->hasAny(['from', 'to']) ? $activeView->range : \App\Services\ReportScheduleService::guess($report, $from, $to);

        return view('reports.show', compact('report', 'title', 'desc', 'mode', 'from', 'to', 'data', 'activeView', 'views', 'allCols', 'range'));
    }


    /** Neutralise spreadsheet formula injection. */
    protected function csvSafe($v)
    {
        $v = (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
    }

    protected function stayRow(ReservationRoom $s): array
    {
        $g = $s->guest ?? $s->reservation->guest;

        return ['res' => $s->reservation->reservation_no, 'guest' => $g?->full_name, 'phone' => $g?->phone, 'room' => $s->room?->room_no ?? '—', 'type' => $s->roomType?->name,
            'arr' => $s->arrival_date->format('d-m-Y'), 'dep' => $s->departure_date->format('d-m-Y'), 'nights' => $s->nights, 'pax' => $s->adults.'/'.$s->children,
            'rate' => (float) $s->rate, 'company' => $s->reservation->company?->name, 'status' => str_replace('_', ' ', $s->status)];
    }

    protected function stayCols(): array
    {
        return ['res' => 'Res. No', 'guest' => 'Guest', 'phone' => 'Phone', 'room' => 'Room', 'type' => 'Room type', 'arr' => 'Arrival', 'dep' => 'Departure', 'nights' => 'Nights', 'pax' => 'A/C', 'rate' => 'Rate', 'company' => 'Company', 'status' => 'Status'];
    }


    /** Approved / paid payroll months whose period starts in the range (YYYY-MM list). */
    protected function statMonths($from, $to): array
    {
        return \App\Models\PayrollRun::whereBetween('period_start', [Carbon::parse($from)->startOfMonth()->toDateString(), Carbon::parse($to)->toDateString()])
            ->whereIn('status', ['approved', 'paid'])->orderBy('period_start')->get()->map(fn ($r) => $r->period_start->format('Y-m'))->all();
    }


    /** v4.6: latest statement of each account ending on or before the date */
    protected function recStatements($to)
    {
        return \App\Services\BankRecService::accounts()->map(fn ($a) => [$a, \App\Models\BankStatement::where('account_head_id', $a->id)->whereDate('period_to', '<=', $to->toDateString())
            ->orderByDesc('period_to')->orderByDesc('id')->first()]);
    }


    /** Accumulated depreciation per asset id up to (and including) a date: brought in + posted runs. */
    protected function assetAcc(string $asOf, ?string $after = null): \Illuminate\Support\Collection
    {
        return \App\Models\AssetDepLine::query()->join('asset_dep_runs as r', 'r.id', '=', 'asset_dep_lines.asset_dep_run_id')
            ->where('r.status', 'posted')->whereDate('r.period_end', '<=', $asOf)->when($after, fn ($q) => $q->whereDate('r.period_end', '>', $after))
            ->groupBy('asset_dep_lines.asset_id')->selectRaw('asset_dep_lines.asset_id, SUM(asset_dep_lines.amount) amt')->pluck('amt', 'asset_id');
    }


    /** How far the general ledger has been posted – appended to every ledger report. */
    protected function glNote(): string
    {
        $cut = setting('gl_cutoff');

        return 'Ledger '.($cut ? 'posted through '.Carbon::parse($cut)->format('d M Y') : 'not yet synced').'. Closed business days are posted automatically by the night audit.'
            .(setting('gl_lock_date') ? ' Period locked up to '.Carbon::parse(setting('gl_lock_date'))->format('d M Y').'.' : '');
    }


    // ---- v5.7 treasury


    // ---- v5.8 owner reporting


    // ---- v5.6 financial close


    // ---- v3.6 budget & planning

    /** the budget a report is about: ?budget= (approved, this hotel) or the active budget of the date's fiscal year */
    protected function reportBudget(Carbon $to, string $kind = 'budget'): ?\App\Models\Budget
    {
        $id = (int) $this->param('budget');
        if ($id && ($b = \App\Models\Budget::where('status', 'approved')->find($id))) {
            return $b;
        }

        return \App\Services\BudgetService::active(\App\Services\BudgetService::fyFor($to), $kind);
    }

    protected function noBudget(array $cols, string $what = 'approved, active budget'): array
    {
        return ['cols' => $cols, 'rows' => collect(), 'note' => "There is no {$what} for this fiscal year – see Accounts › Budgets."];
    }


    // ---- v3.7 hotel interfaces


    // ───────────────────────── v3.8 gift vouchers ─────────────────────────


    // ───── v3.9 engineering ─────


    // ───────── v4.2 security ─────────


    protected function upsellOrdersIn($from, $to)
    {
        return \App\Models\UpsellOrder::with('offer')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
    }


    protected function roomNightsBy($from, $to, string $key): Collection
    {
        return ReservationRoom::with('reservation.source', 'reservation.company', 'folio.transactions')->whereIn('status', ['checked_in', 'checked_out'])
            ->whereDate('arrival_date', '<=', $to)->whereDate('departure_date', '>', $from)->get()
            ->groupBy(fn ($s) => ($key === 'source' ? $s->reservation->source?->name : $s->reservation->company?->name) ?? ($key === 'source' ? 'Direct / Walk-in' : 'Individual (FIT)'))
            ->map(function ($g, $name) use ($from, $to) {
                $end = $to->copy()->addDay();
                $nights = $g->sum(fn ($s) => max(0, (int) ($s->arrival_date->gt($from) ? $s->arrival_date : $from)->diffInDays($s->departure_date->lt($end) ? $s->departure_date : $end)));
                $rev = $g->sum(fn ($s) => $s->folio ? $s->folio->transactions->where('is_void', false)->where('type', 'charge')->where('category', 'room')
                    // a loaded relation is a Collection: compare the cast dates as days, not with whereDate()
                    ->filter(fn ($t) => ($d = $t->business_date?->toDateString()) !== null && $d >= $from->toDateString() && $d <= $to->toDateString())
                    ->sum('amount') : 0);

                return ['name' => $name, 'stays' => $g->count(), 'nights' => (int) $nights, 'revenue' => $rev, 'adr' => $nights ? round($rev / $nights, 2) : 0];
            })->sortByDesc('revenue')->values();
    }


    // ------------------------------------------------------------------ v3.1 feedback & reputation


    // ------------------------------------------------------------------ v3.2 table reservations

    protected function tbRows($from, $to)
    {
        return \App\Models\TableReservation::with('outlet', 'tables', 'order')->whereDate('date', '>=', Carbon::parse($from)->toDateString())->whereDate('date', '<=', Carbon::parse($to)->toDateString())
            ->orderBy('start_at')->get();
    }


    // ───────── v3.3 transport ─────────

    protected function trRows($from, $to)
    {
        return \App\Models\TransportTrip::with('vehicle', 'driver', 'stay.room')->whereBetween('scheduled_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('scheduled_at')->get();
    }

    // ─────────────── v3.4 foreign guests ───────────────

    // ───────────────────────── v3.5 desk operations ─────────────────────────


    // ───────────────────────── v4.9 workforce ─────────────────────────


    // ---------------------------------------------------------------- v5.0 food safety


    // ───────────────────────── v5.1 health club ─────────────────────────


    // ───────────── v5.3 income audit ─────────────


    // ───────── v6.0 learning & development ─────────


    // ───────── v6.1 Sales & marketing ─────────


    // ---------------------------------------------------------------- v6.2 digital marketing


    // ------------------------------------------------------------------ v6.4 central reservations


    // ------------------------------------------------------------------ v6.5 quality assurance


    // ───────── v5.9 concierge ─────────

    protected function cgRows($from, $to)
    {
        return \App\Models\ActivityBooking::with('activity', 'vendor', 'creator')->whereDate('date', '>=', Carbon::parse($from)->toDateString())->whereDate('date', '<=', Carbon::parse($to)->toDateString())
            ->orderBy('date')->orderBy('time')->orderBy('id')->get();
    }


    // ------------------------------------------------------------------ v6.6 parking & valet


    // ------------------------------------------------------------------ v6.7 health, safety & fire


    // ───────────── v6.9 talent management ─────────────


    // ───────── v7.0 sustainability & ESG


    // ───────── v7.1 IPTV & guest welcome ─────────


    // ---------------------------------------------------------------- v7.4 partner portal


    // ---------------------------------------------------------------- v7.5 organiser portal


    // ------------------------------------------------------------------ v6.8 F&B cost control


    // ════════════════════════════════════════════════════════════════════
    // BATCH 1: RESERVATIONS, HOUSEKEEPING, INVENTORY, F&B, BANQUET, ACCOUNTS, TREASURY, PAYROLL, MARKETING, SALES, COMPLIANCE
    // ════════════════════════════════════════════════════════════════════


    // ════════════════════════════════════════════════════════════════════
    // BATCH 2: INVENTORY, PURCHASING, F&B, BANQUET, HR, BUDGET, CRM
    // ════════════════════════════════════════════════════════════════════


}
