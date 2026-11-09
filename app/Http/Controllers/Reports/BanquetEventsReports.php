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
 * Banquet & Events reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait BanquetEventsReports
{
    protected function r_banquet($from, $to): array
    {
        $rows = BanquetEvent::with('functions.hall', 'functions.items', 'company', 'salesUser')->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->orderBy('event_date')->get()
            ->map(fn ($e) => ['no' => $e->event_no, 'date' => $e->event_date->format('d-m-Y'), 'title' => $e->title, 'type' => BanquetEvent::TYPES[$e->event_type] ?? $e->event_type,
                'client' => $e->company?->name ?? $e->contact_name, 'venues' => $e->functions->pluck('hall.name')->unique()->join(', '), 'fns' => $e->functions->count(),
                'pax' => $e->pax(), 'net' => $e->net(), 'tax' => $e->tax(), 'deposit' => $e->depositsReceived(), 'sales' => $e->salesUser?->name,
                'status' => (BanquetEvent::STATUSES[$e->status] ?? $e->status).($e->status === 'cancelled' && $e->cancellation_fee > 0 ? ' (fee '.number_format((float) $e->cancellation_fee, 2).')' : '')]);

        return ['cols' => ['no' => 'Event', 'date' => 'Date', 'title' => 'Title', 'type' => 'Type', 'client' => 'Client', 'venues' => 'Venue(s)', 'fns' => 'Functions', 'pax' => 'Pax',
            'net' => 'Value', 'tax' => 'Tax', 'deposit' => 'Deposits', 'sales' => 'Sales', 'status' => 'Status'], 'rows' => $rows, 'money' => ['net', 'tax', 'deposit'], 'sum' => ['fns', 'pax', 'net', 'tax', 'deposit']];
    }

    protected function r_banquet_pipeline($from, $to): array
    {
        $rows = BanquetEvent::with('functions.items')->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to)->orderBy('event_date')->get()
            ->groupBy(fn ($e) => $e->event_date->format('Y-m'))
            ->map(function ($g, $m) {
                $by = fn ($st) => $g->whereIn('status', (array) $st);
                $won = $by(['definite', 'completed', 'cancelled'])->count();
                $decided = $won + $by('lost')->count();

                return ['month' => Carbon::parse($m.'-01')->format('M Y'), 'enq_n' => $by('enquiry')->count(), 'enq_v' => $by('enquiry')->sum(fn ($e) => $e->net()),
                    'ten_n' => $by('tentative')->count(), 'ten_v' => $by('tentative')->sum(fn ($e) => $e->net()),
                    'def_n' => $by(['definite', 'completed'])->count(), 'def_v' => $by(['definite', 'completed'])->sum(fn ($e) => $e->net()),
                    'lost_n' => $by('lost')->count(), 'lost_v' => $by('lost')->sum(fn ($e) => $e->net()),
                    'can_n' => $by('cancelled')->count(), 'fees' => (float) $by('cancelled')->sum('cancellation_fee'),
                    'conv' => $decided ? round($won / $decided * 100, 1) : null];
            })->values();

        return ['cols' => ['month' => 'Event month', 'enq_n' => 'Enquiries', 'enq_v' => 'Enquiry value', 'ten_n' => 'Tentative', 'ten_v' => 'Tentative value', 'def_n' => 'Definite',
            'def_v' => 'Definite value', 'lost_n' => 'Lost', 'lost_v' => 'Lost value', 'can_n' => 'Cancelled', 'fees' => 'Cancel fees', 'conv' => 'Conversion %'],
            'rows' => $rows, 'money' => ['enq_v', 'ten_v', 'def_v', 'lost_v', 'fees'], 'sum' => ['enq_n', 'enq_v', 'ten_n', 'ten_v', 'def_n', 'def_v', 'lost_n', 'lost_v', 'can_n', 'fees']];
    }

    protected function r_function_forecast($from, $to): array
    {
        $rows = \App\Models\BanquetFunction::with('event.company', 'hall', 'package', 'barPackage', 'items')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['tentative', 'definite']))->inTimeOrder()->get()
            ->map(fn ($f) => ['date' => $f->date->format('d-m-Y D'), 'time' => $f->timeRange(), 'venue' => $f->hall->name, 'event' => $f->event->event_no.' '.$f->event->title,
                'fn' => $f->name, 'setup' => \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style, 'pax' => $f->guaranteed_pax ?? $f->expected_pax,
                'guar' => $f->guaranteed_pax !== null ? 'Yes' : 'due '.$f->guaranteeCutoff()->format('d M'), 'menu' => ($f->package?->name ?? ($f->rate_per_pax > 0 ? 'Custom' : '—')).($f->barPackage ? ' + '.$f->barPackage->name : ''),
                'extras' => $f->items->pluck('description')->join(', '), 'fnb' => $f->fnbAmount() + $f->barAmount(), 'status' => BanquetEvent::STATUSES[$f->event->status] ?? $f->event->status]);

        return ['cols' => ['date' => 'Date', 'time' => 'Time', 'venue' => 'Venue', 'event' => 'Event', 'fn' => 'Function', 'setup' => 'Set-up', 'pax' => 'Covers', 'guar' => 'Guaranteed',
            'menu' => 'Menu', 'extras' => 'Services / equipment', 'fnb' => 'F&B value', 'status' => 'Status'], 'rows' => $rows, 'money' => ['fnb'], 'sum' => ['pax', 'fnb']];
    }

    protected function r_organiser_attendees($from, $to): array
    {
        $rows = OrganiserAttendee::with('event.organiser')->whereBetween('registered_at', [$from, $to])->get()->map(fn ($r) => [
            'event' => optional($r->event)->name, 'organiser' => optional($r->event->organiser)->name,
            'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone,
            'company' => $r->company, 'country' => $r->country, 'role' => $r->role,
            'registered' => $r->registered_at, 'status' => $r->status,
        ]);
        return ['cols' => ['event' => 'Event', 'organiser' => 'Organiser', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'company' => 'Company', 'role' => 'Role', 'registered' => 'Registered', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Event attendee registrations by organiser.'];
    }

    protected function r_venue_utilisation($from, $to): array
    {
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $fns = \App\Models\BanquetFunction::with('items', 'event')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['tentative', 'definite', 'completed']))->inTimeOrder()->get()->groupBy('banquet_hall_id');
        $rows = \App\Models\BanquetHall::orderBy('sort')->orderBy('name')->get()->map(function ($h) use ($fns, $days) {
            $all = $fns->get($h->id, collect());
            $firm = $all->filter(fn ($f) => $f->event->status !== 'tentative');
            $hours = $firm->sum(fn ($f) => $f->hours());

            return ['hall' => $h->name, 'cap' => $h->capacity, 'fns' => $firm->count(), 'tent' => $all->count() - $firm->count(), 'days' => $firm->pluck('date')->map->toDateString()->unique()->count(),
                'hours' => round($hours, 1), 'occ' => round($hours / ($days * 16) * 100, 1), 'covers' => $firm->sum(fn ($f) => $f->billablePax()),
                'rent' => round($firm->sum(fn ($f) => (float) $f->hall_rate), 2), 'fnb' => round($firm->sum(fn ($f) => $f->fnbAmount() + $f->barAmount()), 2), 'total' => round($firm->sum(fn ($f) => $f->net()), 2)];
        });

        return ['cols' => ['hall' => 'Venue', 'cap' => 'Capacity', 'fns' => 'Functions', 'tent' => '+ Tentative', 'days' => 'Days used', 'hours' => 'Hours', 'occ' => 'Occupancy %',
            'covers' => 'Covers', 'rent' => 'Venue rent', 'fnb' => 'F&B', 'total' => 'Total revenue'],
            'rows' => $rows, 'money' => ['rent', 'fnb', 'total'], 'sum' => ['fns', 'tent', 'days', 'hours', 'covers', 'rent', 'fnb', 'total'],
            'note' => "Definite and completed functions; occupancy = hours booked ÷ ({$days} days × 16 h). Tentative holds are counted separately."];
    }

}
