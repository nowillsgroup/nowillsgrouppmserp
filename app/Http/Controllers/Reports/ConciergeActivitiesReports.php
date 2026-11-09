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
 * Concierge & Activities reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait ConciergeActivitiesReports
{
    protected function r_activity_departures($from, $to): array
    {
        $rows = ActivityDeparture::with('activity.guest.activityBooking')->whereBetween('departure_date', [$from, $to])->get()->map(fn ($r) => [
            'activity' => optional($r->activity)->name, 'guest' => optional($r->guest)->name,
            'booking' => optional($r->activityBooking)->reference, 'departure' => $r->departure_date,
            'location' => $r->location, 'transport' => $r->transport_type, 'status' => $r->status,
        ]);
        return ['cols' => ['activity' => 'Activity', 'guest' => 'Guest', 'booking' => 'Booking', 'departure' => 'Departure', 'location' => 'Location', 'transport' => 'Transport', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Activity departure tracking.'];
    }

    protected function r_concierge_bookings($from, $to): array
    {
        $B = \App\Models\ActivityBooking::class;
        $rows = $this->cgRows($from, $to)->map(fn ($b) => ['date' => $b->date->format('d M Y').' '.$b->time, 'no' => $b->number, 'activity' => $b->activity->name, 'vendor' => $b->vendor->name,
            'guest' => $b->guest_name.($b->room_no ? ' · '.$b->room_no : ''), 'pax' => $b->pax(), 'source' => $B::SOURCES[$b->source] ?? $b->source, 'paid' => $b->collection === 'folio' ? 'Room bill' : 'Vendor',
            'gross' => $b->status === 'cancelled' ? (float) $b->cancel_fee : (in_array($b->status, ['completed', 'no_show', 'confirmed'], true) ? (float) $b->gross : 0.0),
            'commission' => in_array($b->status, ['requested', 'waitlisted'], true) ? 0.0 : (float) $b->commission, 'status' => $b->statusLabel(), 'by' => (string) ($b->creator?->name ?? 'Guest portal'),
            'settled' => $b->activity_settlement_id ? 'Yes' : '', '_class' => in_array($b->status, ['no_show', 'cancelled'], true) ? 'row-alert' : ''])->values();

        return ['cols' => ['date' => 'Date & time', 'no' => 'Booking', 'activity' => 'Activity', 'vendor' => 'Operator', 'guest' => 'Guest · room', 'pax' => 'Pax', 'source' => 'Source', 'paid' => 'Paid to',
            'gross' => 'Sold', 'commission' => 'Commission', 'status' => 'Status', 'by' => 'Booked by', 'settled' => 'Settled'],
            'rows' => $rows, 'money' => ['gross', 'commission'], 'sum' => ['pax', 'gross', 'commission'],
            'summary' => ['Bookings' => (string) $rows->count(), 'Completed' => (string) $rows->where('status', 'Completed')->count(), 'Guests' => (string) $rows->whereIn('status', ['Completed', 'Confirmed', 'No-show'])->sum('pax'),
                'Sold' => money($rows->sum('gross')), 'Commission' => money($rows->sum('commission'))],
            'note' => 'By activity date. Sold = price of confirmed / completed / no-show bookings plus late-cancellation fees (free cancellations and unconfirmed requests show 0). Commission on confirmed bookings is not earned until the activity takes place.'];
    }

    protected function r_concierge_sales($from, $to): array
    {
        $A = \App\Models\Activity::class;
        $rows = $this->cgRows($from, $to)->groupBy('activity_id')->map(function ($g) use ($A) {
            $a = $g->first()->activity;
            $sold = $g->whereIn('status', ['completed', 'no_show', 'confirmed']);
            $done = $g->whereIn('status', ['completed', 'no_show']);
            $earned = $g->whereNotNull('recognised_on');

            return ['activity' => $a->name, 'cat' => $A::CATEGORIES[$a->category] ?? $a->category, 'vendor' => $g->first()->vendor->name, 'bookings' => $g->count(), 'sold' => $sold->count(),
                'pax' => $sold->sum(fn ($b) => $b->pax()), 'gross' => round($sold->sum(fn ($b) => (float) $b->gross) + $g->where('status', 'cancelled')->sum(fn ($b) => (float) $b->cancel_fee), 2),
                'avg' => $sold->count() ? round($sold->sum(fn ($b) => (float) $b->gross) / $sold->count(), 2) : 0.0, 'commission' => round($earned->sum(fn ($b) => (float) $b->commission), 2),
                'cancel' => $g->where('status', 'cancelled')->count(), 'noshow' => $g->where('status', 'no_show')->count(), 'portal' => $g->where('source', 'portal')->count(),
                'conv' => $g->count() ? round($done->count() * 100 / $g->count(), 1) : 0];
        })->sortByDesc('gross')->values();

        return ['cols' => ['activity' => 'Activity', 'cat' => 'Category', 'vendor' => 'Operator', 'bookings' => 'Bookings', 'sold' => 'Confirmed / done', 'pax' => 'Guests', 'gross' => 'Sold',
            'avg' => 'Avg booking', 'commission' => 'Commission earned', 'cancel' => 'Cancelled', 'noshow' => 'No-shows', 'portal' => 'Via portal', 'conv' => 'Took place %'],
            'rows' => $rows, 'money' => ['gross', 'avg', 'commission'], 'sum' => ['bookings', 'sold', 'pax', 'gross', 'commission', 'cancel', 'noshow', 'portal'],
            'summary' => ['Activities sold' => (string) $rows->count(), 'Sold' => money($rows->sum('gross')), 'Commission earned' => money($rows->sum('commission')),
                'Top seller' => (string) ($rows->first()['activity'] ?? '–')],
            'note' => 'Bookings with an activity date in the period. Commission earned = recognised in the ledger (completed, no-show and late-cancellation fees); "Took place %" = completed + no-show ÷ all bookings.'];
    }

    protected function r_concierge_staff($from, $to): array
    {
        $rows = $this->cgRows($from, $to)->groupBy(fn ($b) => $b->source === 'portal' ? 'portal' : 'u'.$b->created_by)->map(function ($g) {
            $sold = $g->whereIn('status', ['completed', 'no_show', 'confirmed']);

            return ['who' => $g->first()->source === 'portal' ? 'Guest portal (self-service)' : (string) ($g->first()->creator?->name ?? '—'), 'bookings' => $g->count(), 'sold' => $sold->count(),
                'pax' => $sold->sum(fn ($b) => $b->pax()), 'gross' => round($sold->sum(fn ($b) => (float) $b->gross), 2), 'commission' => round($sold->sum(fn ($b) => (float) $b->commission), 2),
                'inhouse' => $g->whereNotNull('reservation_room_id')->count(), 'walkin' => $g->whereNull('reservation_room_id')->count(), 'cancel' => $g->where('status', 'cancelled')->count()];
        })->sortByDesc('commission')->values();

        return ['cols' => ['who' => 'Sold by', 'bookings' => 'Bookings', 'sold' => 'Confirmed / done', 'pax' => 'Guests', 'gross' => 'Sold', 'commission' => 'Commission', 'inhouse' => 'Staying guests', 'walkin' => 'Non-residents', 'cancel' => 'Cancelled'],
            'rows' => $rows, 'money' => ['gross', 'commission'], 'sum' => ['bookings', 'sold', 'pax', 'gross', 'commission', 'inhouse', 'walkin', 'cancel'],
            'note' => 'Bookings with an activity date in the period by the user who made them. Commission includes confirmed bookings not yet taken place (the incentive base for concierge staff).'];
    }

    protected function r_concierge_vendors($from, $to): array
    {
        $to = Carbon::parse($to)->toDateString();
        $V = \App\Models\ActivityVendor::class;
        $done = $this->cgRows($from, $to)->whereIn('status', ['completed', 'no_show', 'cancelled'])->groupBy('activity_vendor_id');
        $open = \App\Services\ConciergeService::unsettled(null, $to)->groupBy('activity_vendor_id');
        $paid = \App\Models\ActivitySettlement::where('status', 'posted')->whereDate('date', '>=', Carbon::parse($from)->toDateString())->whereDate('date', '<=', $to)->get()->groupBy('activity_vendor_id');
        $rows = \App\Models\ActivityVendor::orderBy('name')->get()->map(function ($v) use ($done, $open, $paid, $V) {
            $d = $done[$v->id] ?? collect();
            $o = $open[$v->id] ?? collect();
            $p = $paid[$v->id] ?? collect();
            if ($d->isEmpty() && $o->isEmpty() && $p->isEmpty()) {
                return null;
            }
            $owe = round($o->where('collection', 'folio')->sum(fn ($b) => (float) $b->vendor_net), 2);
            $due = round($o->where('collection', 'vendor')->sum(fn ($b) => (float) $b->commission), 2);

            return ['vendor' => $v->name, 'kind' => $V::KINDS[$v->kind] ?? $v->kind, 'pct' => (float) $v->commission_pct, 'bookings' => $d->count(),
                'gross' => round($d->sum(fn ($b) => (float) ($b->status === 'cancelled' ? $b->cancel_fee : $b->gross)), 2), 'commission' => round($d->sum(fn ($b) => (float) $b->commission), 2),
                'settled' => $p->count(), 'paid' => round($p->sum(fn ($s) => max(0, (float) $s->net)), 2), 'received' => round($p->sum(fn ($s) => max(0, -(float) $s->net)), 2),
                'owe' => $owe, 'due' => $due, 'net' => round($owe - $due, 2), 'oldest' => $o->isEmpty() ? '' : $o->min(fn ($b) => $b->date)->format('d M Y'),
                'licence' => $v->licence_expires ? ($v->licenceExpired() ? 'EXPIRED ' : '').$v->licence_expires->format('d M Y') : '',
                '_class' => $v->licenceExpired() ? 'row-alert' : ''];
        })->filter()->values();

        return ['cols' => ['vendor' => 'Operator', 'kind' => 'Type', 'pct' => 'Comm. %', 'bookings' => 'Finished bookings', 'gross' => 'Sold', 'commission' => 'Commission', 'settled' => 'Settlements',
            'paid' => 'Paid out', 'received' => 'Received', 'owe' => 'Hotel owes (unsettled)', 'due' => 'Operator owes (unsettled)', 'net' => 'Net payable', 'oldest' => 'Oldest unsettled', 'licence' => 'Licence expires'],
            'rows' => $rows, 'money' => ['gross', 'commission', 'paid', 'received', 'owe', 'due', 'net'], 'sum' => ['bookings', 'gross', 'commission', 'settled', 'paid', 'received', 'owe', 'due', 'net'],
            'summary' => ['Owed to operators' => money($rows->sum('owe')), 'Commission due' => money($rows->sum('due')), 'Net' => money($rows->sum('net')), 'Paid out in period' => money($rows->sum('paid'))],
            'note' => 'Finished = completed, no-show and cancelled bookings with an activity date in the period; settlements dated in the period. Unsettled balances are every finished booking up to the end date not yet in a settlement.'];
    }

}
