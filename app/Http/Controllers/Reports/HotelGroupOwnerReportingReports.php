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
 * Hotel Group & Owner Reporting reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait HotelGroupOwnerReportingReports
{
    protected function r_ffe_reserve($from, $to): array
    {
        $FE = \App\Models\FfeEntry::class;
        $CP = \App\Models\CapexProject::class;
        $l = \App\Services\OwnerService::ledger($from->toDateString(), $to->toDateString());
        $rows = collect([['date' => $from->format('d M Y'), 'type' => 'Opening balance', 'detail' => '', 'in' => null, 'out' => null, 'balance' => $l['opening'], '_class' => 'bold-row']]);
        foreach ($l['rows'] as $r) {
            $e = $r['entry'];
            $rows->push(['date' => $e->date->format('d M Y'), 'type' => $FE::TYPES[$e->type], 'detail' => trim(($e->project?->number ?? '').' '.$e->memo), 'in' => $e->amount > 0 ? (float) $e->amount : null, 'out' => $e->amount < 0 ? -(float) $e->amount : null, 'balance' => $r['balance'], '_class' => '']);
        }
        $rows->push(['date' => $to->format('d M Y'), 'type' => 'Closing balance', 'detail' => '', 'in' => null, 'out' => null, 'balance' => $l['closing'], '_class' => 'bold-row']);
        $rows->push(['date' => '', 'type' => 'Capital projects', 'detail' => '', 'in' => null, 'out' => null, 'balance' => null, '_class' => 'bold-row']);
        foreach ($CP::with('assets', 'entries')->orderBy('number')->get() as $p) {
            $rows->push(['date' => $p->number, 'type' => $CP::STATUSES[$p->status], 'detail' => $p->title.' · '.$CP::FUNDING[$p->funding].' · budget '.money((float) $p->budget), 'in' => null, 'out' => $p->spent() ?: null, 'balance' => null, '_class' => '']);
        }
        $all = collect($l['rows'])->pluck('entry');
        $funded = \App\Services\OwnerService::reserveFunded(null, $to->toDateString());

        return ['cols' => ['date' => 'Date / project', 'type' => 'Type', 'detail' => 'Detail', 'in' => 'In', 'out' => 'Out / spent', 'balance' => 'Balance'], 'rows' => $rows, 'money' => ['in', 'out', 'balance'],
            'summary' => ['Opening' => $l['opening'], 'Contributions' => round($all->where('type', 'contribution')->sum('amount'), 2), 'Drawdowns' => round(-$all->where('type', 'drawdown')->sum('amount'), 2), 'Closing' => $l['closing']] + ($funded !== null ? ['Cash in reserve account' => $funded] : []),
            'note' => 'The reserve is a memorandum fund kept with the management fees; the cash is held in the designated reserve bank account.'];
    }

    protected function r_hotel_groups($from, $to): array
    {
        // v10.0 reads the group account's real columns (identity, entitlement, live property count)
        // rather than the non-existent managementCompany relation and aggregates it used to guess at.
        //
        // v10.7 the group IS the tenant boundary, so "every management group" was every tenant's
        // group: a property administrator holding hotels.view could read the names, room counts and
        // property counts of other companies running this software. The register now lists the groups
        // the reader can actually reach — the ones behind the properties they may open — and a
        // platform administrator, who may open them all, still sees them all.
        $user = Auth::user();
        $groupIds = $user
            ? app(PropertyAccessService::class)->accessible($user)->pluck('hotel_group_id')->filter()->unique()->values()
            // A scheduled run has no signed-in reader: it serves one tenant, set before the report
            // runs, so that tenant's own group is the only group it may name.
            : collect([\App\Support\Tenant::hotel()?->hotel_group_id])->filter()->unique()->values();

        $rows = HotelGroup::whereIn('id', $groupIds->all())->orderBy('name')->get()->map(function ($r) {
            $properties = $r->hotels()->notArchived()->get();
            return [
                'name' => $r->name, 'code' => $r->code, 'mc' => $r->managementCompanyName(),
                'hotels' => $properties->count(),
                'closed' => $properties->filter(fn ($h) => $h->licenceIsEmpty())->count(),
                'rooms' => $r->roomCount(), 'contact' => $r->contact_email,
                'status' => $r->statusLabel(), 'description' => $r->description,
            ];
        });
        return ['cols' => ['name' => 'Name', 'code' => 'Code', 'mc' => 'Management Co', 'hotels' => 'Properties', 'closed' => 'Nothing licensed', 'rooms' => 'Rooms', 'contact' => 'Contact', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Hotel group register — the groups behind the properties you may open. \'Nothing licensed\' counts properties whose module licence is empty: they still belong to the group, but nobody can post in them.'];
    }

    protected function r_group_operating_summary($from, $to): array
    {
        // The period arguments are not used: every property is read on its OWN business date, and a
        // report that silently picked one date for four properties on four different dates would be
        // the kind of number that is argued about a month later. The header says so instead.
        $user = Auth::user();
        $hotels = $user
            ? app(PropertyAccessService::class)->accessible($user)
            // A scheduled run has no reader: it serves one tenant, so that tenant's group is the
            // portfolio it reports on.
            : collect([\App\Support\Tenant::hotel()])->filter()
                ->flatMap(fn ($h) => $h->group ? $h->group->hotels()->notArchived()->get() : [$h]);

        // v10.10 the portfolio can be sliced: a region or a brand wants its own line of this report, and
        // asking for "all of it" was the only thing the screen could do. The slice is a query parameter,
        // so a slice is a URL — bookmarked, printed and scheduled like any other state of a report.
        // Read through param(), so the same slice works from the URL, from a saved view and from a
        // schedule that has no browser at all.
        $dimension = (string) $this->param('slice');
        $value = (string) $this->param('value');
        $slice = in_array($dimension, ['brand', 'city'], true) && $value !== '' ? [$dimension, $value] : null;
        $portfolio = $hotels;

        if ($slice) {
            $hotels = $hotels->filter(fn ($h) => (string) ($slice[0] === 'brand' ? $h->brand : $h->city) === $slice[1])->values();
        }

        // v10.12 two questions, two rows of chips: which properties ("Show") and whether they are
        // compared side by side or listed one by one ("Compare"). A slice answers "one of these"; a
        // grouping answers "these beside each other", which is the question a portfolio is read for.
        $by = in_array((string) $this->param('by'), ['brand', 'city'], true) ? (string) $this->param('by') : null;

        // Both keys are always written, empty for "all": an empty parameter is how a sliced view is
        // told "no slice", where a missing one would leave the view's own slice in force.
        $show = [['label' => 'All properties', 'url' => request()->fullUrlWithQuery(['slice' => '', 'value' => '']),
            'count' => $portfolio->count(), 'active' => $slice === null]];

        foreach (['brand' => 'Brand', 'city' => 'City'] as $field => $label) {
            foreach ($portfolio->pluck($field)->filter()->unique()->sort()->values() as $name) {
                $show[] = ['label' => (string) $name, 'url' => request()->fullUrlWithQuery(['slice' => $field, 'value' => $name]),
                    'count' => $portfolio->filter(fn ($h) => (string) $h->{$field} === (string) $name)->count(),
                    'active' => $slice === [$field, (string) $name]];
            }
        }

        $compare = [
            ['label' => 'One by one', 'url' => request()->fullUrlWithQuery(['by' => '']), 'count' => $hotels->count(), 'active' => $by === null],
            ['label' => 'By brand', 'url' => request()->fullUrlWithQuery(['by' => 'brand']), 'count' => $hotels->pluck('brand')->filter()->unique()->count(), 'active' => $by === 'brand'],
            ['label' => 'By city', 'url' => request()->fullUrlWithQuery(['by' => 'city']), 'count' => $hotels->pluck('city')->filter()->unique()->count(), 'active' => $by === 'city'],
        ];

        $choices = [['label' => 'Show', 'choices' => $show], ['label' => 'Compare', 'choices' => $compare]];

        $metrics = app(\App\Services\GroupMetricsService::class);
        $rows = collect();
        $closed = [];

        foreach ($hotels as $hotel) {
            // Same rule as the dashboard: a property nobody can post in is not added to the group's
            // occupancy or revenue. It is named underneath instead, because a reader who counted the
            // properties in the group would otherwise find one missing with no explanation.
            if ($hotel->licenceIsEmpty()) {
                $closed[] = $hotel;

                continue;
            }

            $m = $metrics->row($hotel);
            $rows->push([
                // carried for the comparison, not printed one by one: the columns decide what shows
                '_brand' => (string) $hotel->brand, '_city' => (string) $hotel->city,
                'property' => $hotel->name, 'code' => $hotel->code, 'status' => $hotel->statusLabel(),
                'date' => $m['business_date']->format('d-m-Y'),
                'rooms' => $m['rooms'], 'occupied' => $m['occupied'], 'occ' => number_format($m['occ'], 1).'%',
                'in_house' => $m['in_house'], 'arrivals' => $m['arrivals'], 'departures' => $m['departures'],
                'rev_today' => (float) $m['rev_today'], 'adr' => (float) $m['adr'], 'rev_mtd' => (float) $m['rev_mtd'],
                'collections' => (float) $m['collections'], 'open_pos' => $m['open_pos'],
                'licence' => $metrics->licenceLabel($hotel),
            ]);
        }

        // v10.12 the comparison: the same figures gathered by brand or by city, so two of them can be
        // read against each other instead of one at a time. Occupancy and ADR are recomputed from the
        // parts, never averaged — an average of four occupancies is a number about nothing.
        if ($by !== null) {
            $rows = $rows->groupBy(fn ($r) => (string) ($r['_'.$by] ?? '') ?: 'Unassigned '.$by)
                ->map(function ($group, $name) use ($rows) {
                    $rooms = (int) $group->sum('rooms');
                    $occupied = (int) $group->sum('occupied');
                    $revMtd = (float) $group->sum('rev_mtd');
                    $roomRevToday = $group->sum(fn ($r) => $r['adr'] * $r['occupied']);

                    return [
                        'group' => $name, 'properties' => $group->count(),
                        'rooms' => $rooms, 'occupied' => $occupied,
                        'occ' => number_format($rooms ? $occupied * 100 / $rooms : 0, 1).'%',
                        'in_house' => (int) $group->sum('in_house'), 'arrivals' => (int) $group->sum('arrivals'),
                        'departures' => (int) $group->sum('departures'),
                        'rev_today' => (float) $group->sum('rev_today'),
                        'adr' => (float) ($occupied ? $roomRevToday / $occupied : 0),
                        'rev_mtd' => $revMtd,
                        // a share of the group's month, so "which brand carries the portfolio" is a column
                        'share' => number_format($rows->sum('rev_mtd') ? $revMtd * 100 / $rows->sum('rev_mtd') : 0, 1).'%',
                        'collections' => (float) $group->sum('collections'),
                        'open_pos' => (int) $group->sum('open_pos'),
                    ];
                })->sortByDesc('rev_mtd')->values();

            return [
                'cols' => [
                    'group' => $by === 'brand' ? 'Brand' : 'City', 'properties' => 'Properties',
                    'rooms' => 'Sellable', 'occupied' => 'Occupied', 'occ' => 'Occupancy',
                    'in_house' => 'In-house', 'arrivals' => 'Arr', 'departures' => 'Dep',
                    'rev_today' => 'Revenue today', 'adr' => 'ADR', 'rev_mtd' => 'Revenue MTD', 'share' => 'Share of MTD',
                    'collections' => 'Collections', 'open_pos' => 'Open POS',
                ],
                'rows' => $rows,
                'money' => ['rev_today', 'adr', 'rev_mtd', 'collections'],
                // 'share' is deliberately not summed: percentages do not add up to a portfolio
                'sum' => ['properties', 'rooms', 'occupied', 'in_house', 'arrivals', 'departures', 'rev_today', 'rev_mtd', 'collections', 'open_pos'],
                'summary' => [
                    'Places compared' => (string) $rows->count(),
                    'Properties trading' => (string) $rows->sum('properties'),
                    'Group occupancy' => number_format($rows->sum('rooms') ? $rows->sum('occupied') * 100 / $rows->sum('rooms') : 0, 1).'%',
                    'Revenue MTD' => (float) $rows->sum('rev_mtd'),
                    'Properties shut' => (string) count($closed),
                ],
                'choices' => $choices,
                'note' => 'The properties you may open, gathered by '.$by.' and each read on its own business date.'
                    .($slice ? ' Within '.$slice[1].' only.' : '')
                    .($closed === [] ? '' : ' Not in these totals, because nobody can post there: '
                        .implode(', ', array_map(fn ($h) => $h->name.' ('.$h->code.')', $closed)).'.'),
            ];
        }

        $rooms = (int) $rows->sum('rooms');
        $occupied = (int) $rows->sum('occupied');

        return [
            'cols' => [
                'property' => 'Property', 'code' => 'Code', 'status' => 'Status', 'date' => 'Business date',
                'rooms' => 'Sellable', 'occupied' => 'Occupied', 'occ' => 'Occupancy',
                'in_house' => 'In-house', 'arrivals' => 'Arr', 'departures' => 'Dep',
                'rev_today' => 'Revenue today', 'adr' => 'ADR', 'rev_mtd' => 'Revenue MTD',
                'collections' => 'Collections', 'open_pos' => 'Open POS', 'licence' => 'Licence',
            ],
            'rows' => $rows,
            // Occupancy and ADR are ratios: a total of them would be a number nobody can act on.
            'money' => ['rev_today', 'adr', 'rev_mtd', 'collections'],
            'sum' => ['rooms', 'occupied', 'in_house', 'arrivals', 'departures', 'rev_today', 'rev_mtd', 'collections', 'open_pos'],
            'summary' => [
                'Properties trading' => (string) $rows->count(),
                'Group occupancy' => number_format($rooms ? $occupied * 100 / $rooms : 0, 1).'%',
                'Revenue today' => (float) $rows->sum('rev_today'),
                'Revenue MTD' => (float) $rows->sum('rev_mtd'),
                'Properties shut' => (string) count($closed),
            ],
            'choices' => $choices,
            'note' => 'Each property is read on its own business date, in its own books.'
                .($slice ? ' Showing '.$slice[1].' — '.$hotels->count().' of '.$portfolio->count()
                    .' properties you may open; the chip above puts the rest back in.' : '')
                .($closed === [] ? '' : ' Not in these totals, because nobody can post there: '
                    .implode(', ', array_map(fn ($h) => $h->name.' ('.$h->code.')', $closed))
                    .'. Its history is untouched — licensing a module again puts it straight back in this report.'),
        ];
    }

    protected function r_hotel_kpis($from, $to): array
    {
        $U = \App\Services\UsaliService::class;
        $rows = collect();
        $tot = ['available' => 0, 'sold' => 0];
        $yms = [];
        for ($c = $from->copy()->startOfMonth(); $c->lte($to); $c->addMonthNoOverflow()) {
            $ym = $c->format('Y-m');
            $yms[] = $ym;
            $st = $U::roomStats([$ym]);
            $k = $U::kpis($U::month($ym), $st);
            $tot['available'] += $st['available'];
            $tot['sold'] += $st['sold'];
            $rows->push(['month' => $c->format('M Y')] + array_intersect_key($k, array_flip(['available', 'sold', 'occupancy', 'adr', 'revpar', 'trevpar', 'goppar', 'cpor', 'gop_margin', 'ebitda_margin', 'labor_pct', 'fnb_cost_pct'])));
        }
        $all = $U::kpis($U::range($yms[0].'-01', \Illuminate\Support\Carbon::parse(end($yms).'-01')->endOfMonth()->toDateString())['summary'], $tot);
        $rows->push(['month' => 'Period', '_class' => 'bold-row'] + array_intersect_key($all, array_flip(['available', 'sold', 'occupancy', 'adr', 'revpar', 'trevpar', 'goppar', 'cpor', 'gop_margin', 'ebitda_margin', 'labor_pct', 'fnb_cost_pct'])));

        return ['cols' => ['month' => 'Month', 'available' => 'Rooms avail.', 'sold' => 'Sold', 'occupancy' => 'Occ. %', 'adr' => 'ADR', 'revpar' => 'RevPAR', 'trevpar' => 'TRevPAR', 'goppar' => 'GOPPAR', 'cpor' => 'CPOR',
            'gop_margin' => 'GOP %', 'ebitda_margin' => 'EBITDA %', 'labor_pct' => 'Labour %', 'fnb_cost_pct' => 'F&B cost %'], 'rows' => $rows, 'money' => ['adr', 'revpar', 'trevpar', 'goppar', 'cpor'],
            'summary' => ['Occupancy %' => $all['occupancy'], 'ADR' => $all['adr'], 'RevPAR' => $all['revpar'], 'GOPPAR' => $all['goppar']],
            'note' => 'Whole calendar months touched by the range. Rooms available / sold from the booking data; revenue and costs from the general ledger (USALI). CPOR = rooms departmental expenses per occupied room.'];
    }

    protected function r_management_agreements($from, $to): array
    {
        // v10.0 the register reads the agreement's own dates and fee column, and the group through
        // the hotel's group relation, instead of four attributes that do not exist.
        $rows = ManagementAgreement::with('hotel.group')->get()->map(fn ($r) => [
            'hotel' => optional($r->hotel)->name, 'group' => optional(optional($r->hotel)->group)->name,
            'mc' => optional(optional($r->hotel)->group)->managementCompanyName(), 'start' => $r->effective_from,
            'end' => $r->ends_on, 'fee_pct' => $r->base_fee_pct,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['hotel' => 'Hotel', 'group' => 'Group', 'mc' => 'Management Co', 'start' => 'Start', 'end' => 'End', 'fee_pct' => 'Fee %', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Management agreement register.'];
    }

    protected function r_management_fees($from, $to): array
    {
        $F = \App\Models\OwnerFee::class;
        $fees = $F::with('paidAccount')->whereBetween('period', [$from->format('Y-m'), $to->format('Y-m')])->orderBy('period')->get(); // period is a 'Y-m' string
        $rows = $fees->map(fn ($f) => ['month' => $f->label(), 'revenue' => (float) $f->total_revenue, 'base' => (float) $f->base_fee, 'ffe' => (float) $f->ffe_amount, 'gop' => (float) $f->gop, 'agop' => (float) $f->agop,
            'agop_ytd' => (float) $f->agop_ytd, 'priority' => (float) $f->priority_ytd, 'incentive' => (float) $f->incentive_fee, 'total' => (float) $f->total_fee, 'status' => $F::STATUSES[$f->status],
            'paid' => $f->status === 'paid' ? $f->paid_on?->format('d M Y').' '.$f->paidAccount?->name : ($f->status === 'accrued' ? 'due '.$f->dueOn()->format('d M Y') : $f->reversal_note), '_class' => $f->status === 'reversed' ? 'muted' : '']);
        $live = $fees->where('status', '!=', 'reversed');

        return ['cols' => ['month' => 'Month', 'revenue' => 'Revenue', 'base' => 'Base fee', 'ffe' => 'FF&E', 'gop' => 'GOP', 'agop' => 'AGOP', 'agop_ytd' => 'AGOP fee YTD', 'priority' => 'Owner priority YTD', 'incentive' => 'Incentive', 'total' => 'Total fee', 'status' => 'Status', 'paid' => 'Paid / due'],
            'rows' => $rows, 'money' => ['revenue', 'base', 'ffe', 'gop', 'agop', 'agop_ytd', 'priority', 'incentive', 'total'],
            'summary' => ['Base fees' => round($live->sum('base_fee'), 2), 'Incentive fees' => round($live->sum('incentive_fee'), 2), 'Total fees' => round($live->sum('total_fee'), 2), 'Unpaid' => round($live->where('status', 'accrued')->sum('total_fee'), 2), 'FF&E contributions' => round($live->sum('ffe_amount'), 2)],
            'note' => ($a = \App\Services\OwnerService::agreement()) ? $a->operator_name.' · base '.(float) $a->base_fee_pct.'% of revenue, incentive '.(float) $a->incentive_pct.'% of '.strtoupper($a->incentive_basis).' above the owner\'s priority, FF&E '.$a->scheduleLabel().'.' : 'No management agreement set up.'];
    }

    protected function r_owner_queries($from, $to): array
    {
        $rows = OwnerQuery::with('owner.hotel')->whereBetween('created_at', [$from, $to])->get()->map(fn ($r) => [
            'owner' => optional($r->owner)->name, 'hotel' => optional($r->hotel)->name,
            'subject' => $r->subject, 'query' => $r->query_text, 'created' => $r->created_at,
            'status' => $r->status, 'response' => $r->response_text,
        ]);
        return ['cols' => ['owner' => 'Owner', 'hotel' => 'Hotel', 'subject' => 'Subject', 'created' => 'Created', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Owner query register.'];
    }

    protected function r_owner_statements($from, $to): array
    {
        $rows = OwnerStatement::with('owner.hotel')->whereBetween('period_start', [$from, $to])->get()->map(fn ($r) => [
            'owner' => optional($r->owner)->name, 'hotel' => optional($r->hotel)->name,
            'period' => $r->period_start . ' to ' . $r->period_end, 'revenue' => $r->revenue_total,
            'expenses' => $r->expense_total, 'net' => $r->net_total, 'fees' => $r->management_fees,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['owner' => 'Owner', 'hotel' => 'Hotel', 'period' => 'Period', 'revenue' => 'Revenue', 'expenses' => 'Expenses', 'net' => 'Net', 'fees' => 'Mgmt Fees', 'status' => 'Status'], 'rows' => $rows, 'money' => ['revenue_total', 'expense_total', 'net_total', 'management_fees'], 'sum' => ['revenue_total', 'expense_total', 'net_total', 'management_fees'], 'note' => 'Owner statement summaries.'];
    }

    protected function r_usali_departments($from, $to): array
    {
        $U = \App\Services\UsaliService::class;
        $acc = $U::range($from->toDateString(), $to->toDateString())['acc'];
        $rows = collect();
        foreach (array_merge(array_keys($U::OPERATED), ['ood', 'misc'], array_keys($U::UNDISTRIBUTED), ['mgmt', 'nonop', 'below']) as $code) {
            foreach ($U::schedule($code, $acc) as $sec) {
                if (! $sec['cats']) {
                    continue;
                }
                $rows->push(['dept' => $sec['label'], 'cat' => '', 'account' => '', 'amount' => null, 'pct' => null, '_class' => 'bold-row']);
                foreach ($sec['cats'] as $c) {
                    foreach ($c['lines'] as $l) {
                        $rows->push(['dept' => '', 'cat' => $c['label'], 'account' => trim($l['code'].' '.$l['name']).($l['split'] ? ' (payroll split)' : ''), 'amount' => $l['amount'],
                            'pct' => $sec['revenue'] > 0 ? round($l['amount'] * 100 / $sec['revenue'], 1) : null, '_class' => '']);
                    }
                    $rows->push(['dept' => '', 'cat' => 'Total '.strtolower($c['label']), 'account' => '', 'amount' => $c['total'], 'pct' => null, '_class' => 'bold-row']);
                }
                if ($sec['revenue'] != 0 && $sec['expense'] != 0) {
                    $rows->push(['dept' => '', 'cat' => 'Departmental profit', 'account' => '', 'amount' => $sec['profit'], 'pct' => $sec['revenue'] > 0 ? round($sec['profit'] * 100 / $sec['revenue'], 1) : null, '_class' => 'bold-row']);
                }
            }
        }
        $sum = $U::summarise($acc);

        return ['cols' => ['dept' => 'Department', 'cat' => 'Category', 'account' => 'Account', 'amount' => 'Amount', 'pct' => '% of dept. revenue'], 'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Departmental profit' => $sum['tdp'], 'Undistributed' => $sum['tue'], 'GOP' => $sum['gop'], 'Labour' => $sum['labor']],
            'note' => $this->glNote().' Mapping under Owner Reporting › Agreement & mapping.'];
    }

    protected function r_usali_summary($from, $to): array
    {
        $U = \App\Services\UsaliService::class;
        $s = $U::range($from->toDateString(), $to->toDateString())['summary'];
        $layout = $U::layout('operator');
        foreach ($U::layout('owner') as $r) {
            if (in_array($r[0], ['h_ida', 'b_interest', 'b_depreciation', 'b_amortization', 'tida', 'ibit', 'b_income_tax', 'net'], true)) {
                $layout[] = $r;
            }
        }
        $rows = collect($layout)->map(function ($r) use ($s) {
            [$k, $label, $kind] = $r;
            if ($kind === 'head') {
                return ['line' => $label, 'amount' => null, 'pct' => null, '_class' => 'bold-row'];
            }
            $base = $s[$r[3] ?? 'tor'] ?? 0;

            return ['line' => ($kind === 'line' ? '   ' : '').$label, 'amount' => $s[$k] ?? 0, 'pct' => $base != 0 ? round(($s[$k] ?? 0) * 100 / $base, 1) : null, '_class' => in_array($kind, ['total', 'strong'], true) ? 'bold-row' : ''];
        });

        return ['cols' => ['line' => 'Line', 'amount' => 'Amount', 'pct' => '% of revenue'], 'rows' => $rows, 'money' => ['amount'],
            'summary' => ['Total operating revenue' => $s['tor'], 'GOP' => $s['gop'], 'EBITDA' => $s['ebitda'], 'Net income' => $s['net'], 'Ledger profit' => $s['gl_net']],
            'note' => $this->glNote().' Departmental expenses are a % of their department\'s revenue; other lines a % of total operating revenue. Replacement reserve = FF&E contributions accrued with the management fees. Payroll is split across departments by each month\'s payroll.'];
    }

}
