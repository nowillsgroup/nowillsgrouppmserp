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
 * Compliance & HSE reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait ComplianceHseReports
{
    protected function r_compliance_events($from, $to): array
    {
        $rows = ComplianceEvent::with('item.department')->whereBetween('event_date', [$from, $to])->get()->map(fn ($r) => [
            'item' => optional($r->item)->description, 'dept' => optional($r->department)->name,
            'date' => $r->event_date, 'type' => $r->event_type, 'description' => $r->description,
            'status' => $r->status, 'user' => $r->user_id, 'notes' => $r->notes,
        ]);
        return ['cols' => ['item' => 'Item', 'dept' => 'Department', 'date' => 'Date', 'type' => 'Type', 'description' => 'Description', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Compliance event register with status tracking.'];
    }

    protected function r_compliance_register($from, $to): array
    {
        $rows = \App\Services\ComplianceService::register($to->toDateString())->map(fn ($r) => ['item' => $r['item']->name, 'category' => $r['item']->categoryLabel(), 'department' => $r['item']->department,
            'authority' => $r['item']->authority, 'ref' => $r['item']->reference_no, 'issued' => $r['item']->issued_on?->format('d-m-Y'), 'expires' => $r['item']->expires_on?->format('d-m-Y') ?? 'No expiry',
            'days' => $r['days'], 'status' => \App\Models\ComplianceItem::STATES[$r['state']][0], 'critical' => $r['item']->is_critical ? 'Yes' : '', 'owner' => $r['item']->owner?->name, 'fee' => $r['item']->fee]);
        $by = $rows->groupBy('status');

        return ['cols' => ['item' => 'Item', 'category' => 'Category', 'department' => 'Department', 'authority' => 'Authority', 'ref' => 'Reference', 'issued' => 'Issued / done', 'expires' => 'Valid until / due',
            'days' => 'Days left', 'status' => 'Status', 'critical' => 'Critical', 'owner' => 'Responsible', 'fee' => 'Fee'],
            'rows' => $rows, 'money' => ['fee'], 'sum' => ['fee'],
            'summary' => collect(\App\Models\ComplianceItem::STATES)->mapWithKeys(fn ($s) => [$s[0] => (string) ($by[$s[0]] ?? collect())->count()])->filter(fn ($v) => $v !== '0')->all()
                + ['Critical expired' => (string) $rows->where('critical', 'Yes')->where('status', 'Expired / overdue')->count()],
            'note' => 'Status as at the report date: due soon = inside the item\'s renewal lead time; renewal applied = application submitted, awaiting the authority. Archived items are excluded.'];
    }

    protected function r_data_breaches($from, $to): array
    {
        $rows = \App\Models\DataBreach::with('department')->whereBetween('reported_at', [$from, $to])->get()->map(fn ($r) => [
            'dept' => optional($r->department)->name, 'reported' => $r->reported_at,
            'type' => $r->breach_type, 'description' => $r->description, 'data_affected' => $r->data_affected,
            'status' => $r->status, 'reported_to' => $r->reported_to, 'notes' => $r->notes,
        ]);
        return ['cols' => ['dept' => 'Department', 'reported' => 'Reported', 'type' => 'Type', 'description' => 'Description', 'data_affected' => 'Data Affected', 'status' => 'Status', 'reported_to' => 'Reported To'], 'rows' => $rows, 'note' => 'Data breach register with reporting details.'];
    }

    protected function r_hse_accidents($from, $to): array
    {
        $list = \App\Models\HseAccident::with(['employee', 'guest'])->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('occurred_at')->get();
        $rows = $list->map(fn ($a) => ['no' => $a->number, 'when' => $a->occurred_at->format('d M Y H:i'), 'kind' => $a->kindLabel().($a->occurrence ? ' – '.\App\Models\HseAccident::OCCURRENCES[$a->occurrence] : ''),
            'where' => $a->location, 'person' => $a->personLabel(), 'ptype' => $a->person_type ? \App\Models\HseAccident::PERSONS[$a->person_type] : '',
            'injury' => trim(($a->injury_type ? \App\Models\HseAccident::INJURIES[$a->injury_type] : '').($a->body_part ? ' · '.\App\Models\HseAccident::BODY[$a->body_part] : '')),
            'outcome' => $a->severityLabel(), 'lost' => $a->lost_days ?: null, 'notice' => $a->reportable ? ($a->notified_on ? 'sent '.$a->notified_on->format('d M') : 'DUE') : '',
            'status' => \App\Models\HseAccident::STATUSES[$a->status], '_class' => $a->reportable && ! $a->notified_on ? 'row-red' : ($a->isLostTime() ? 'row-amber' : '')]);
        $ltis = $list->filter(fn ($a) => $a->kind === 'injury' && in_array($a->person_type, ['staff', 'contractor'], true) && $a->isLostTime())->count();
        $months = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->endOfDay(), true) / 30.44);
        $hours = \App\Models\Employee::where('is_active', true)->count() * \App\Services\HseService::cfg()['hours_month'] * $months;

        return ['cols' => ['no' => 'Ref', 'when' => 'When', 'kind' => 'Kind', 'where' => 'Where', 'person' => 'Person', 'ptype' => 'Type', 'injury' => 'Injury', 'outcome' => 'Outcome',
            'lost' => 'Days lost', 'notice' => 'Act 328 notice', 'status' => 'Status'], 'rows' => $rows, 'money' => [], 'sum' => ['lost'],
            'summary' => ['Injuries' => (string) $list->where('kind', 'injury')->count(), 'Near misses' => (string) $list->where('kind', 'near_miss')->count(),
                'Dangerous occurrences' => (string) $list->where('kind', 'dangerous')->count(), 'Lost-time injuries' => (string) $ltis,
                'LTIFR' => $hours ? (string) round($ltis * 1000000 / $hours, 2) : '—'],
            'note' => 'LTIFR = lost-time injuries to staff and contractors × 1,000,000 ÷ hours worked (active employees × the hours per month in HSE settings). Act 328 s. 10: deaths and injuries disabling a person employed for more than 3 days; s. 11: every dangerous occurrence – notify the Factories Inspectorate forthwith.'];
    }

    protected function r_hse_checks($from, $to): array
    {
        $rows = \App\Models\HseCheck::with(['equipment', 'user', 'ticket'])->whereDate('checked_on', '>=', $from->toDateString())->whereDate('checked_on', '<=', $to->toDateString())->orderByDesc('checked_on')->orderByDesc('id')->get()
            ->map(fn ($c) => ['date' => $c->checked_on->format('d M Y'), 'code' => $c->equipment?->code, 'type' => $c->equipment?->typeLabel(), 'location' => $c->equipment?->location,
                'kind' => $c->kind === 'service' ? 'Service' : 'Visual', 'result' => $c->result === 'pass' ? 'Pass' : 'FAIL', 'faults' => $c->faults ? implode('; ', $c->faults) : '',
                'cert' => $c->certificate_no ?? '', 'ticket' => $c->ticket?->ticket_no ?? '', 'by' => $c->contractor ?? $c->user?->name, '_class' => $c->result === 'fail' ? 'row-red' : '']);

        return ['cols' => ['date' => 'Date', 'code' => 'Item', 'type' => 'Type', 'location' => 'Location', 'kind' => 'Kind', 'result' => 'Result', 'faults' => 'Faults', 'cert' => 'Certificate',
            'ticket' => 'Ticket', 'by' => 'Checked by'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Checks' => (string) $rows->count(), 'Failed' => (string) $rows->where('result', 'FAIL')->count(), 'Services' => (string) $rows->where('kind', 'Service')->count()]];
    }

    protected function r_hse_drills($from, $to): array
    {
        $list = \App\Models\HseDrill::with('leader')->whereBetween('scheduled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('scheduled_at')->get();
        $rows = $list->map(fn ($x) => ['no' => $x->number, 'type' => $x->typeLabel(), 'shift' => ucfirst($x->shift), 'when' => ($x->alarm_at ?? $x->scheduled_at)->format('d M Y H:i'),
            'evac' => $x->evacLabel(), 'target' => intdiv($x->target_seconds, 60).' min'.($x->target_seconds % 60 ? ' '.($x->target_seconds % 60).' s' : ''),
            'roll' => $x->staff_expected ? $x->staff_accounted.' / '.$x->staff_expected : '', 'guests' => $x->guests_evacuated, 'result' => $x->status === 'done' ? strtoupper($x->result) : ucfirst($x->status),
            'lead' => $x->leader?->name, 'improve' => $x->improvements ?? '', '_class' => $x->result === 'fail' ? 'row-red' : '']);
        $done = $list->where('status', 'done');

        return ['cols' => ['no' => 'Ref', 'type' => 'Type', 'shift' => 'Shift', 'when' => 'When', 'evac' => 'Evacuation', 'target' => 'Target', 'roll' => 'Roll call', 'guests' => 'Guests',
            'result' => 'Result', 'lead' => 'Led by', 'improve' => 'Improvements'], 'rows' => $rows, 'money' => [], 'sum' => ['guests'],
            'summary' => ['Drills done' => (string) $done->count(), 'Passed' => (string) $done->where('result', 'pass')->count(),
                'Average evacuation' => $done->whereNotNull('evac_seconds')->count() ? gmdate('i:s', (int) round($done->avg('evac_seconds'))) : '—']];
    }

    protected function r_hse_equipment($from, $to): array
    {
        $lab = ['ok' => 'In order', 'due' => 'Due soon', 'overdue' => 'Overdue', 'defective' => 'Defective', 'expired' => 'Expired', 'retired' => 'Retired'];
        $rows = \App\Models\HseEquipment::with('ticket')->where('status', '!=', 'retired')->orderBy('code')->get()->map(function ($e) use ($lab) {
            $st = $e->state();

            return ['code' => $e->code, 'type' => $e->typeLabel(), 'size' => $e->capacity ?? '', 'location' => $e->location, 'floor' => $e->floor ?? '', 'tag' => $e->gnfs_tag ?? '',
                'last' => $e->last_checked_on?->format('d M Y') ?? '', 'next' => $e->next_check_on?->format('d M Y') ?? 'now', 'service' => $e->next_service_on?->format('d M Y') ?? '',
                'expires' => $e->expires_on?->format('d M Y') ?? '', 'state' => $lab[$st].($e->ticket && $e->ticket->isOpen() ? ' · '.$e->ticket->ticket_no : ''),
                '_class' => in_array($st, ['overdue', 'defective', 'expired'], true) ? 'row-red' : ($st === 'due' ? 'row-amber' : '')];
        });
        $eq = \App\Services\HseService::equipmentStats();

        return ['cols' => ['code' => 'Code', 'type' => 'Type', 'size' => 'Size', 'location' => 'Location', 'floor' => 'Floor', 'tag' => 'GNFS tag', 'last' => 'Last check', 'next' => 'Next check',
            'service' => 'Service due', 'expires' => 'Expires', 'state' => 'State'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Items' => (string) $eq['total'], 'In order' => ($eq['pct'] ?? 0).'%', 'Overdue' => (string) $eq['overdue'], 'Defective' => (string) $eq['defective'], 'Expired' => (string) $eq['expired']],
            'note' => 'Fire Precaution (Premises) Regulations 2003 (L.I. 1724): keep fire-fighting equipment serviceable and ready for the GNFS inspection. Tag numbers are the GNFS equipment tags (AFCAS).'];
    }

    protected function r_hse_permits($from, $to): array
    {
        $list = \App\Models\HsePermit::with(['requester', 'issuer', 'closer'])->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('starts_at')->get();
        $rows = $list->map(function ($p) {
            $late = $p->closed_at && $p->status === 'closed' && $p->closed_at->gt($p->ends_at);
            $extra = array_filter([$p->fire_watch ? 'fire watch '.$p->fire_watch : null, $p->detectors_isolated ? 'detectors off: '.$p->detectors_isolated : null,
                $p->gas_o2 !== null ? 'O₂ '.number_format($p->gas_o2, 1).'%' : null, $p->isolation_points ? 'LOTO '.$p->isolation_points : null]);

            return ['no' => $p->number, 'type' => $p->typeLabel(), 'where' => $p->location, 'work' => $p->work, 'who' => $p->contractor ?? 'in-house',
                'period' => $p->starts_at->format('d M H:i').' – '.$p->ends_at->format('H:i'), 'req' => $p->requester?->name, 'issued' => $p->issuer?->name ?? '',
                'checks' => implode(' · ', $extra), 'closed' => $p->closed_at?->format('d M H:i') ?? '', 'status' => ($p->isOverdue() ? 'PAST END – open' : $p->statusLabel()).($late ? ' (late)' : ''),
                '_class' => $p->isOverdue() ? 'row-red' : ($late ? 'row-amber' : '')];
        });

        return ['cols' => ['no' => 'Permit', 'type' => 'Type', 'where' => 'Location', 'work' => 'Work', 'who' => 'Contractor', 'period' => 'Period', 'req' => 'Requested by', 'issued' => 'Issued by',
            'checks' => 'Fire watch / gas / isolation', 'closed' => 'Closed', 'status' => 'Status'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Permits' => (string) $list->count(), 'Hot work' => (string) $list->where('type', 'hot_work')->count(), 'Closed' => (string) $list->where('status', 'closed')->count(),
                'Open past end' => (string) $list->filter(fn ($p) => $p->isOverdue())->count(), 'Closed late' => (string) $rows->filter(fn ($r) => str_contains($r['status'], '(late)'))->count()]];
    }

    protected function r_hse_risks($from, $to): array
    {
        $rows = \App\Models\HseRisk::with(['owner', 'acceptor', 'department'])->where('status', '!=', 'archived')->orderBy('number')->get()->map(function ($x) {
            [, $il] = \App\Models\HseRisk::band($x->score());
            [$rk, $rl] = \App\Models\HseRisk::band($x->residual());
            $late = $x->review_on->toDateString() < bdate()->toDateString();

            return ['no' => $x->number, 'title' => $x->title, 'area' => $x->area, 'hazard' => \Illuminate\Support\Str::limit($x->hazard, 120), 'who' => $x->who_at_risk,
                'init' => $x->score().' '.$il, 'controls' => \Illuminate\Support\Str::limit($x->controls, 140), 'res' => $x->residual().' '.$rl, 'owner' => $x->owner?->name ?? '',
                'accepted' => $x->accepted_at ? $x->acceptor?->name.' '.$x->accepted_at->format('d M Y') : ($x->status === 'pending' ? 'AWAITING' : ''),
                'review' => $x->review_on->format('d M Y').($late ? ' – overdue' : ''), '_class' => $late ? 'row-red' : ($rk === 'high' ? 'row-amber' : '')];
        });

        return ['cols' => ['no' => 'Ref', 'title' => 'Activity', 'area' => 'Area', 'hazard' => 'Hazard', 'who' => 'Who is at risk', 'init' => 'Risk', 'controls' => 'Controls', 'res' => 'Residual',
            'owner' => 'Owner', 'accepted' => 'Accepted', 'review' => 'Review'], 'rows' => $rows, 'money' => [], 'sum' => [],
            'summary' => ['Assessments' => (string) $rows->count(), 'High residual' => (string) $rows->filter(fn ($r) => str_contains($r['res'], 'High'))->count(),
                'Reviews overdue' => (string) $rows->where('_class', 'row-red')->count()],
            'note' => 'Risk = likelihood × severity (1–5 each): 1–4 low, 5–9 medium, 10–14 high (accepted by a manager other than the assessor), 15–25 very high (activity must not go ahead).'];
    }

    protected function r_hse_wardens($from, $to): array
    {
        // Rewritten against the real schema: role/area/shift/trained_on/trained_until; there is no department FK on wardens.
        $rows = HseWarden::with('employee')->get()->map(fn ($r) => [
            'warden' => optional($r->employee)->name, 'role' => $r->roleLabel(), 'area' => $r->area,
            'shift' => $r->shift, 'trained_on' => $r->trained_on, 'trained_until' => $r->trained_until,
            'current' => $r->trainingValid() ? 'Yes' : 'No', 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['warden' => 'Warden', 'role' => 'Role', 'area' => 'Area', 'shift' => 'Shift', 'trained_on' => 'Trained On', 'trained_until' => 'Valid Until', 'current' => 'Current', 'active' => 'Active'], 'rows' => $rows, 'note' => 'HSE warden register with certification status.'];
    }

    protected function r_pii_access($from, $to): array
    {
        $rows = \App\Models\PiiAccessLog::with('user:id,name', 'guest:id,first_name,last_name,title,privacy_status')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('id')->get()->map(fn ($l) => ['at' => $l->created_at->format('d M Y H:i'), 'user' => $l->user?->name ?? 'system', 'action' => \App\Models\PiiAccessLog::ACTIONS[$l->action] ?? $l->action,
                'guest' => $l->guest ? $l->guest->full_name.' (G-'.$l->guest_id.')' : '—', 'detail' => $l->detail, 'reason' => $l->reason, 'ip' => $l->ip]);

        return ['cols' => ['at' => 'When', 'user' => 'Staff', 'action' => 'Action', 'guest' => 'Guest', 'detail' => 'Detail', 'reason' => 'Reason', 'ip' => 'IP'], 'rows' => $rows->values(), 'sum' => [],
            'chart' => null, 'note' => $rows->count().' access event(s) by '.$rows->pluck('user')->unique()->count().' user(s) on '.$rows->pluck('guest')->unique()->count().' guest profile(s).'];
    }

    protected function r_pm_compliance($from, $to): array
    {
        $c = \App\Services\PmService::compliance($from->toDateString(), $to->toDateString());
        $rows = $c['rows']->sortBy('due_date')->map(function ($tk) {
            $done = $tk->resolved_at ? Carbon::parse($tk->resolved_at)->startOfDay() : null;
            $late = $done ? max(0, (int) $tk->due_date->diffInDays($done, false)) : max(0, (int) $tk->due_date->diffInDays(bdate(), false));

            return ['ticket' => $tk->ticket_no, 'task' => $tk->schedule?->title ?? $tk->title, 'asset' => $tk->asset ? $tk->asset->asset_no.' '.$tk->asset->name : ($tk->location ?? ''),
                'freq' => $tk->schedule?->frequency(), 'due' => $tk->due_date->format('d-m-Y'), 'done' => $done?->format('d-m-Y') ?? '',
                'result' => ! $done ? 'Open' : ($late ? 'Late' : 'On time'), 'late' => $late, 'tech' => $tk->employee?->name ?? $tk->schedule?->vendor, 'cost' => (float) $tk->cost];
        })->values();

        return ['cols' => ['ticket' => 'Ticket', 'task' => 'Task', 'asset' => 'Asset / location', 'freq' => 'Frequency', 'due' => 'Due', 'done' => 'Done', 'result' => 'Result',
            'late' => 'Days late', 'tech' => 'Technician / vendor', 'cost' => 'Cost'], 'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost'],
            'summary' => ['PM tasks due' => (string) $c['total'], 'On time' => (string) $c['on_time'], 'Late' => (string) $c['late'], 'Still open' => (string) $c['open'], 'On-time %' => $c['pct'] === null ? '—' : $c['pct'].' %']];
    }

    protected function r_privacy_request_events($from, $to): array
    {
        // Rewritten against the real schema: action/note/user_id/privacy_request_id (request() relation added to the model).
        $rows = \App\Models\PrivacyRequestEvent::with('request', 'user')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'request' => optional($r->request)->request_no, 'action' => $r->action,
            'note' => $r->note, 'user' => optional($r->user)->name, 'created' => $r->created_at,
        ]);
        return ['cols' => ['request' => 'Request', 'action' => 'Action', 'note' => 'Note', 'user' => 'User', 'created' => 'Created'], 'rows' => $rows, 'note' => 'Privacy request event audit trail.'];
    }

    protected function r_privacy_requests($from, $to): array
    {
        $rows = \App\Models\PrivacyRequest::with('guest:id', 'completer:id,name')->whereBetween('received_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('received_at')->get()->map(function ($r) {
                $days = $r->completed_at ? $r->received_at->copy()->startOfDay()->diffInDays($r->completed_at->copy()->startOfDay()) : null;
                $sla = match (true) {
                    $r->status === 'unverified' => 'awaiting e-mail',
                    $r->status === 'open' => $r->isOverdue() ? 'OVERDUE' : 'open',
                    default => $r->completed_at && $r->completed_at->toDateString() > $r->due_on->toDateString() ? 'LATE' : 'on time',
                };

                return ['no' => $r->request_no, 'type' => \App\Models\PrivacyRequest::SHORT[$r->type], 'ch' => \App\Models\PrivacyRequest::CHANNELS[$r->channel] ?? $r->channel,
                    'recv' => $r->received_at->format('d M Y'), 'due' => $r->due_on->format('d M Y').($r->extended ? ' (ext.)' : ''),
                    'id' => $r->verified_at ? (\App\Models\PrivacyRequest::METHODS[$r->verification_method] ?? 'yes') : ($r->needsIdentity() ? 'no' : 'n/a'),
                    'status' => $r->status, 'days' => $days, 'sla' => $sla, 'out' => $r->status === 'rejected' ? 'Rejected: '.$r->rejection_reason : \Illuminate\Support\Str::limit((string) $r->outcome, 90)];
            });
        $closed = $rows->whereIn('sla', ['on time', 'LATE']);

        return ['cols' => ['no' => 'Request', 'type' => 'Type', 'ch' => 'Channel', 'recv' => 'Received', 'due' => 'Due', 'id' => 'Identity check', 'status' => 'Status', 'days' => 'Days taken', 'sla' => 'Deadline', 'out' => 'Outcome'],
            'rows' => $rows->values(), 'sum' => [],
            'note' => $rows->count().' request(s) · '.$closed->where('sla', 'on time')->count().' answered on time, '.$closed->where('sla', 'LATE')->count().' late, '.$rows->where('sla', 'OVERDUE')->count().' overdue and open. Deadline = '
                .\App\Services\PrivacyService::settings()['privacy_response_days'].' days from receipt (or from e-mail confirmation for web requests), plus any one-off extension.'];
    }

    protected function r_privacy_retention($from, $to): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $days = [];
        foreach (\App\Models\PrivacyRetentionRun::whereBetween('created_at', [$f, $t])->get() as $r) {
            $d = $r->created_at->toDateString();
            foreach ($r->counts as $k => $n) {
                $days[$d][$k] = ($days[$d][$k] ?? 0) + (int) $n;
            }
            $days[$d]['runs'] = ($days[$d]['runs'] ?? 0) + 1;
        }
        foreach (\App\Models\PrivacyRequest::where('type', 'erasure')->where('status', 'completed')->whereBetween('completed_at', [$f, $t])->get() as $r) {
            $d = $r->completed_at->toDateString();
            $days[$d]['requests'] = ($days[$d]['requests'] ?? 0) + 1;
        }
        ksort($days);
        $rows = collect($days)->map(fn ($v, $d) => ['date' => Carbon::parse($d)->format('d M Y'), 'runs' => $v['runs'] ?? 0, 'requests' => $v['requests'] ?? 0, 'documents' => $v['documents'] ?? 0,
            'signatures' => $v['signatures'] ?? 0, 'messages' => ($v['messages'] ?? 0) + ($v['emails'] ?? 0), 'inactive' => $v['inactive'] ?? 0, 'holds' => $v['holds_ended'] ?? 0,
            'unverified' => $v['unverified'] ?? 0])->values();

        return ['cols' => ['date' => 'Date', 'runs' => 'Retention runs', 'requests' => 'Erasures on request', 'documents' => 'ID scans deleted', 'signatures' => 'Signatures deleted',
            'messages' => 'Message contents purged', 'inactive' => 'Inactive profiles anonymised', 'holds' => 'Legal holds completed', 'unverified' => 'Web requests closed'],
            'rows' => $rows, 'sum' => ['runs', 'requests', 'documents', 'signatures', 'messages', 'inactive', 'holds', 'unverified'],
            'note' => 'Profiles: '.\App\Models\Guest::where('privacy_status', 'erased')->count().' erased and '.\App\Models\Guest::where('privacy_status', 'restricted')->count().' restricted under a legal hold (all time).'];
    }

}
