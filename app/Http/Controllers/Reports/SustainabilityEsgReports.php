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
 * Sustainability & ESG reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait SustainabilityEsgReports
{
    protected function r_carbon_footprint($from, $to): array
    {
        $c = \App\Services\UtilityService::carbon($from->toDateString(), $to->toDateString());
        $tot = max(0.001, $c['total_kg']);
        $rows = collect($c['rows'])->map(fn ($r) => ['source' => $r['label'], 'scope' => $r['scope'] ? 'Scope '.$r['scope'] : '—', 'cons' => round($r['cons'], 1), 'unit' => $r['unit'],
            'factor' => $r['factor'], 'kg' => round($r['kg'], 1), 't' => round($r['kg'] / 1000, 3), 'share' => round($r['kg'] / $tot * 100, 1).'%'])->values();
        $s1 = collect($c['rows'])->where('scope', 1)->sum('kg');
        $s2 = collect($c['rows'])->where('scope', 2)->sum('kg');

        return ['cols' => ['source' => 'Source', 'scope' => 'Scope', 'cons' => 'Consumption', 'unit' => 'Unit', 'factor' => 'kg CO₂e / unit', 'kg' => 'kg CO₂e', 't' => 't CO₂e', 'share' => 'Share'],
            'rows' => $rows, 'sum' => ['kg', 't'],
            'summary' => ['Total' => number_format($c['total_kg'] / 1000, 2).' t CO₂e', 'Scope 1 (fuels on site)' => number_format($s1 / 1000, 2).' t', 'Scope 2 (grid electricity)' => number_format($s2 / 1000, 2).' t',
                'Per occupied room' => $c['per_room'] === null ? '—' : number_format($c['per_room'], 1).' kg', 'Occupied room-nights' => (string) $c['rooms'], 'Avoided by solar' => number_format($c['avoided_kg'] / 1000, 2).' t'],
            'note' => 'Emission factors are set under Utilities › Settings (grid: CEA CO₂ Baseline Database; fuels: IPCC defaults). Main meters only; DG kWh are zero when the diesel they burn is metered.'];
    }

    protected function r_esg_carbon($from, $to): array
    {
        $ES = \App\Services\EsgService::class;
        $cb = $ES::carbon($from->toDateString(), $to->toDateString());
        $h = $ES::hcmi($from->toDateString(), $to->toDateString());
        $rows = collect($cb['rows'])->sortBy('scope')->map(fn ($r) => ['scope' => 'Scope '.$r['scope'], 'source' => $r['label'], 'qty' => round($r['qty'], 1), 'unit' => $r['unit'],
            'factor' => $r['factor'] ?: null, 'kg' => round($r['kg'], 1), 'share' => $cb['total_kg'] > 0 ? number_format($r['kg'] / $cb['total_kg'] * 100, 1).'%' : '', 'note' => $r['note']])->values();

        return ['cols' => ['scope' => 'Scope', 'source' => 'Source', 'qty' => 'Activity', 'unit' => 'Unit', 'factor' => 'kg CO₂e / unit', 'kg' => 'kg CO₂e', 'share' => 'Share', 'note' => 'Note'],
            'rows' => $rows, 'money' => [], 'sum' => ['kg'],
            'summary' => ['Total t CO₂e' => number_format($cb['total_kg'] / 1000, 2), 'Scope 1 t' => number_format($cb['scopes'][1] / 1000, 2), 'Scope 2 t' => number_format($cb['scopes'][2] / 1000, 2),
                'Scope 3 t' => number_format($cb['scopes'][3] / 1000, 2), 'HCMI kg / occupied room' => $h['per_room'] !== null ? number_format($h['per_room'], 2) : '—',
                'HCMI kg / m²·h meeting' => $h['per_m2h'] !== null ? number_format($h['per_m2h'], 4) : '—', 'HWMI L / occupied room' => $h['hwmi_room'] !== null ? number_format($h['hwmi_room']) : '—',
                'Occupied rooms' => number_format($h['occupied'])],
            'note' => 'HCMI boundary = Scope 1 + 2 + outsourced laundry, less private area, split rooms : meeting space by floor area. Grid factor '.\App\Services\UtilityService::cfg()['ef']['electricity'].' kg/kWh.'];
    }

    protected function r_esg_checklist_items($from, $to): array
    {
        // Rewritten against the real schema: code/section/requirement/guidance/is_mandatory/status/evidence/owner_user_id/reviewed_on.
        $rows = EsgChecklistItem::with('owner', 'reviewer')->orderBy('sort')->get()->map(fn ($r) => [
            'code' => $r->code, 'section' => $r->section, 'requirement' => $r->requirement,
            'mandatory' => $r->is_mandatory ? 'Yes' : 'No', 'status' => ucfirst((string) $r->status),
            'owner' => optional($r->owner)->name, 'reviewed_on' => $r->reviewed_on,
        ]);
        return ['cols' => ['code' => 'Code', 'section' => 'Section', 'requirement' => 'Requirement', 'mandatory' => 'Mandatory', 'status' => 'Status', 'owner' => 'Owner', 'reviewed_on' => 'Reviewed'], 'rows' => $rows, 'note' => 'ESG checklist items.'];
    }

    protected function r_esg_communities($from, $to): array
    {
        // Rewritten against the real schema: the table is esg_community — number/date/kind/beneficiary/amount/hours/volunteers/people_reached.
        $rows = EsgCommunity::with('user')->orderByDesc('date')->get()->map(fn ($r) => [
            'number' => $r->number, 'date' => $r->date, 'kind' => $r->kindLabel(),
            'beneficiary' => $r->beneficiary, 'amount' => (float) $r->amount,
            'hours' => $r->hours, 'volunteers' => $r->volunteers, 'people_reached' => $r->people_reached,
            'by' => optional($r->user)->name,
        ]);
        return ['cols' => ['number' => 'Number', 'date' => 'Date', 'kind' => 'Kind', 'beneficiary' => 'Beneficiary', 'amount' => 'Amount', 'hours' => 'Hours', 'volunteers' => 'Volunteers', 'people_reached' => 'People Reached', 'by' => 'By'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'ESG community engagement register.'];
    }

    protected function r_esg_green($from, $to): array
    {
        $list = \App\Models\EsgGreenNight::with(['room', 'guest', 'greenStay'])->whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString())->orderBy('business_date')->orderBy('room_id')->get();
        $s = \App\Services\EsgService::greenStats($from->toDateString(), $to->toDateString());
        $rows = $list->map(fn ($n) => ['date' => $n->business_date->format('d-m-Y'), 'room' => $n->room?->room_no, 'guest' => $n->guest?->full_name, 'source' => $n->greenStay?->source === 'portal' ? 'Guest portal' : 'Front desk',
            'points' => $n->points ?: null, 'kwh' => $n->kwh, 'litres' => $n->litres, 'co2' => $n->co2_kg]);

        return ['cols' => ['date' => 'Date', 'room' => 'Room', 'guest' => 'Guest', 'source' => 'Chosen at', 'points' => 'Points', 'kwh' => 'kWh saved', 'litres' => 'Litres saved', 'co2' => 'kg CO₂e saved'],
            'rows' => $rows, 'money' => [], 'sum' => ['points', 'kwh', 'litres', 'co2'],
            'summary' => ['Green nights' => (string) $s['nights'], 'Uptake' => $s['uptake'] !== null ? $s['uptake'].'% of occupied room-nights' : '—', 'Points' => number_format($s['points']),
                'kg CO₂e saved' => number_format($s['co2'], 1)], 'note' => 'Savings are estimates from the per-night kWh and litres in ESG settings.'];
    }

    protected function r_esg_green_stays($from, $to): array
    {
        $rows = EsgGreenStay::with('reservation.guest.roomType')->whereBetween('booked_at', [$from, $to])->get()->map(fn ($r) => [
            'res' => optional($r->reservation)->reservation_no, 'guest' => optional($r->reservation->guest)->name,
            'room_type' => optional($r->roomType)->name, 'booked' => $r->booked_at,
            'nights' => $r->nights, 'carbon_saved' => $r->carbon_saved_kg, 'cost_saved' => $r->cost_saved,
            'status' => $r->status,
        ]);
        return ['cols' => ['res' => 'Reservation', 'guest' => 'Guest', 'room_type' => 'Room Type', 'booked' => 'Booked', 'nights' => 'Nights', 'carbon_saved' => 'Carbon Saved (kg)', 'cost_saved' => 'Cost Saved', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Green stay program tracking.'];
    }

    protected function r_esg_initiatives($from, $to): array
    {
        $I = \App\Models\EsgInitiative::class;
        $list = $I::with(['owner', 'approver'])->orderBy('number')->get();
        $rows = $list->map(fn ($i) => ['no' => $i->number, 'title' => $i->title, 'cat' => $I::CATEGORIES[$i->category] ?? $i->category, 'owner' => $i->owner?->name, 'status' => $i->statusLabel(),
            'capex' => (float) $i->capex, 'saving' => (float) $i->saving_ghs, 'payback' => $i->payback() !== null ? $i->payback().' yrs' : '', 'kwh' => $i->saving_kwh ?: null, 'm3' => $i->saving_m3 ?: null,
            'waste' => $i->saving_waste_kg ?: null, 'co2' => $i->saving_co2_kg, 'approver' => $i->approver?->name, 'due' => $i->due_on?->format('d-m-Y'), 'done' => $i->completed_on?->format('d-m-Y'),
            '_class' => $i->isLate() ? 'row-red' : '']);
        $live = $list->whereIn('status', ['approved', 'in_progress', 'done']);

        return ['cols' => ['no' => 'Ref', 'title' => 'Initiative', 'cat' => 'Category', 'owner' => 'Owner', 'status' => 'Status', 'capex' => 'Capex', 'saving' => 'Saving / yr', 'payback' => 'Payback',
            'kwh' => 'kWh / yr', 'm3' => 'm³ / yr', 'waste' => 'Waste kg / yr', 'co2' => 'kg CO₂e / yr', 'approver' => 'Approved by', 'due' => 'Due', 'done' => 'Completed'],
            'rows' => $rows, 'money' => ['capex', 'saving'], 'sum' => ['capex', 'saving', 'kwh', 'm3', 'waste', 'co2'],
            'summary' => ['Approved capex' => money($live->sum('capex')), 'Savings / yr' => money($live->sum('saving_ghs')), 't CO₂e / yr' => number_format($live->sum('saving_co2_kg') / 1000, 1),
                'Awaiting decision' => (string) $list->where('status', 'idea')->count()]];
    }

    protected function r_esg_laundry_months($from, $to): array
    {
        $rows = EsgLaundryMonth::with('department')->whereBetween('period_start', [$from, $to])->get()->map(fn ($r) => [
            'dept' => optional($r->department)->name, 'period' => $r->period_start . ' to ' . $r->period_end,
            'linens_washed' => $r->linens_washed, 'water_saved_litres' => $r->water_saved_litres,
            'energy_saved_kwh' => $r->energy_saved_kwh, 'status' => $r->status,
        ]);
        return ['cols' => ['dept' => 'Dept', 'period' => 'Period', 'linens' => 'Linens Washed', 'water_saved' => 'Water Saved (L)', 'energy_saved' => 'Energy Saved (kWh)', 'status' => 'Status'], 'rows' => $rows, 'note' => 'ESG laundry monthly conservation metrics.'];
    }

    protected function r_esg_local_spend($from, $to): array
    {
        $s = \App\Services\EsgService::localSpend($from->toDateString(), $to->toDateString());
        $rows = collect($s['suppliers'])->map(fn ($x) => ['supplier' => $x['name'], 'local' => $x['local'] ? 'Local' : '', 'bills' => $x['bills'], 'spend' => $x['spend'],
            'share' => $s['total'] > 0 ? number_format($x['spend'] / $s['total'] * 100, 1).'%' : '']);

        return ['cols' => ['supplier' => 'Supplier', 'local' => 'Local', 'bills' => 'Documents', 'spend' => 'Spend (net)', 'share' => 'Share'], 'rows' => $rows, 'money' => ['spend'], 'sum' => ['bills', 'spend'],
            'summary' => ['Total spend' => money($s['total']), 'With local suppliers' => money($s['local']), 'Local share' => $s['pct'] !== null ? $s['pct'].'%' : '—']];
    }

    protected function r_esg_policies($from, $to): array
    {
        // Rewritten against the real schema: code/title/pillar/version/approved_*/review_months/next_review_on/status; there is no policy type table.
        $rows = EsgPolicy::with('owner')->orderBy('title')->get()->map(fn ($r) => [
            'code' => $r->code, 'title' => $r->title, 'pillar' => $r->pillar,
            'version' => $r->version, 'approved_on' => $r->approved_on,
            'approved_by' => $r->approved_by_name, 'owner' => optional($r->owner)->name,
            'next_review_on' => $r->next_review_on,
            'overdue' => $r->isOverdue() ? 'Yes' : 'No',
            'status' => ucfirst((string) $r->status),
        ]);
        return ['cols' => ['code' => 'Code', 'title' => 'Title', 'pillar' => 'Pillar', 'version' => 'Version', 'approved_on' => 'Approved', 'approved_by' => 'Approved By', 'owner' => 'Owner', 'next_review_on' => 'Next Review', 'overdue' => 'Overdue', 'status' => 'Status'], 'rows' => $rows, 'note' => 'ESG policy register.'];
    }

    protected function r_esg_refrigerants($from, $to): array
    {
        $R = \App\Models\EsgRefrigerantLog::class;
        $list = $R::with(['asset', 'ticket'])->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->get();
        $leaks = collect(\App\Services\EsgService::leakRates())->keyBy(fn ($l) => mb_strtolower($l['equipment']));
        $rows = $list->map(fn ($r) => ['no' => $r->number, 'date' => $r->date->format('d-m-Y'), 'system' => $r->equipment, 'asset' => $r->asset?->asset_no, 'gas' => $r->gas, 'gwp' => $R::gwp($r->gas),
            'work' => $R::REASONS[$r->reason] ?? $r->reason, 'added' => (float) $r->kg_added ?: null, 'recovered' => (float) $r->kg_recovered ?: null, 'co2' => $r->co2e(),
            'rate' => ($l = $leaks->get(mb_strtolower($r->equipment))) && $l['rate'] !== null ? $l['rate'].'%' : '', 'tech' => $r->technician, 'cert' => $r->certificate_no, 'ticket' => $r->ticket?->ticket_no,
            '_class' => ($l['flag'] ?? false) ? 'row-red' : '']);

        return ['cols' => ['no' => 'Ref', 'date' => 'Date', 'system' => 'System', 'asset' => 'Asset', 'gas' => 'Gas', 'gwp' => 'GWP', 'work' => 'Work', 'added' => 'kg added', 'recovered' => 'kg recovered',
            'co2' => 'kg CO₂e', 'rate' => 'Leak rate (12 m)', 'tech' => 'Technician', 'cert' => 'Certificate', 'ticket' => 'Ticket'], 'rows' => $rows, 'money' => [], 'sum' => ['added', 'recovered', 'co2'],
            'summary' => ['t CO₂e' => number_format($rows->sum('co2') / 1000, 2), 'kg recovered' => number_format($rows->sum('recovered'), 2), 'Systems above alert' => (string) $leaks->where('flag', true)->count()]];
    }

    protected function r_esg_targets($from, $to): array
    {
        // Rewritten against the real schema: metric/baseline_year/baseline_value/target_year/target_value/notes/is_active; there is no target type table.
        $rows = EsgTarget::orderBy('metric')->get()->map(fn ($r) => [
            'metric' => $r->metric, 'baseline_year' => $r->baseline_year, 'baseline_value' => $r->baseline_value,
            'target_year' => $r->target_year, 'target_value' => $r->target_value,
            'notes' => $r->notes, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['metric' => 'Metric', 'baseline_year' => 'Baseline Year', 'baseline_value' => 'Baseline', 'target_year' => 'Target Year', 'target_value' => 'Target', 'notes' => 'Notes', 'active' => 'Active'], 'rows' => $rows, 'note' => 'ESG sustainability targets.'];
    }

    protected function r_esg_waste($from, $to): array
    {
        $ES = \App\Services\EsgService::class;
        $list = \App\Models\EsgWasteLog::live()->with(['department', 'supplier'])->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->orderBy('id')->get();
        $rows = $list->map(fn ($w) => ['no' => $w->number, 'date' => $w->date->format('d-m-Y'), 'stream' => $w->streamLabel(), 'route' => $w->disposalLabel(), 'diverted' => $w->isDiverted() ? 'Yes' : 'No',
            'kg' => (float) $w->weight_kg, 'dept' => $w->department?->name, 'contractor' => $w->supplier?->name ?? $w->contractor, 'wtn' => $w->manifest_no,
            'cost' => (float) $w->cost ?: null, 'income' => (float) $w->income ?: null, 'co2' => round((float) $w->weight_kg * $ES::wasteFactor($w->stream, $w->disposal), 1)]);
        $s = $ES::waste($from->toDateString(), $to->toDateString());

        return ['cols' => ['no' => 'Ref', 'date' => 'Date', 'stream' => 'Stream', 'route' => 'Route', 'diverted' => 'Diverted', 'kg' => 'kg', 'dept' => 'Department', 'contractor' => 'Contractor',
            'wtn' => 'Transfer note', 'cost' => 'Cost', 'income' => 'Income', 'co2' => 'kg CO₂e'], 'rows' => $rows, 'money' => ['cost', 'income'], 'sum' => ['kg', 'cost', 'income', 'co2'],
            'summary' => ['Total kg' => number_format($s['total'], 1), 'Diverted' => $s['diversion'] !== null ? $s['diversion'].'%' : '—', 'Food waste kg' => number_format($s['food'], 1),
                'kg per occupied room' => $s['per_room'] !== null ? (string) $s['per_room'] : '—', 'kg CO₂e' => number_format($s['co2'], 1), 'Avoided vs landfill kg CO₂e' => number_format($s['avoided'], 1)]];
    }

}
