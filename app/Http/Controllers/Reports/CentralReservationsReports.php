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
 * Central Reservations reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait CentralReservationsReports
{
    protected function r_cro_agents($from, $to): array
    {
        $rows = \App\Services\CroService::breakdown(Carbon::parse($from), Carbon::parse($to), 'agent')->map(fn ($a) => ['agent' => $a['label'], 'total' => $a['total'], 'booked' => $a['booked'],
            'referred' => $a['referred'] ?: null, 'denials' => $a['denials'] ?: null, 'regrets' => $a['regrets'] ?: null, 'open' => $a['open'] ?: null, 'conv' => $a['conversion'] ?? '',
            'revenue' => $a['revenue'], 'kept' => $a['referred_revenue'] ?: null, 'lost' => $a['lost_revenue'] ?: null, 'call' => $a['avg_handle'] !== null ? gmdate('i:s', $a['avg_handle']) : '',
            '_class' => $a['conversion'] !== null && ! $a['on_target'] ? 'row-amber' : '']);

        return ['cols' => ['agent' => 'Agent', 'total' => 'Enquiries', 'booked' => 'Booked', 'referred' => 'Referred', 'denials' => 'Denials', 'regrets' => 'Regrets', 'open' => 'Open', 'conv' => 'Conversion %',
            'revenue' => 'Booked revenue', 'kept' => 'Referred revenue', 'lost' => 'Lost value', 'call' => 'Avg call (m:s)'], 'rows' => $rows, 'money' => ['revenue', 'kept', 'lost'],
            'sum' => ['total', 'booked', 'referred', 'denials', 'regrets', 'open', 'revenue', 'kept', 'lost'],
            'summary' => ['Agents' => (string) $rows->count(), 'Conversion target' => \App\Services\CroService::cfg()['conversion_target'].'%'],
            'note' => 'Conversion = booked ÷ closed (booked + referred + lost). Agents below the target are highlighted.'];
    }

    protected function r_cro_demand($from, $to): array
    {
        $F = Carbon::parse($from)->startOfDay();
        $days = max(1, min(92, (int) $F->diffInDays(Carbon::parse($to)->startOfDay()) + 1));
        $rows = \App\Services\CroService::demand($F, $days)->map(fn ($d) => ['date' => $d['date']->format('D d M Y'), 'capacity' => $d['capacity'], 'sold' => $d['sold'], 'denied' => $d['denied'] ?: null,
            'regret' => $d['regret'] ?: null, 'unconstrained' => $d['unconstrained'], 'occ' => $d['occ'], 'uocc' => $d['uocc'], '_class' => $d['uocc'] > 100 ? 'row-red' : ($d['denied'] ? 'row-amber' : '')]);

        return ['cols' => ['date' => 'Night', 'capacity' => 'Capacity', 'sold' => 'On the books', 'denied' => 'Denied', 'regret' => 'Regretted', 'unconstrained' => 'Unconstrained demand', 'occ' => 'Occupancy %', 'uocc' => 'Unconstrained occ. %'],
            'rows' => $rows, 'money' => [], 'sum' => ['sold', 'denied', 'regret', 'unconstrained'],
            'summary' => ['Nights' => (string) $rows->count(), 'Room nights denied' => (string) $rows->sum('denied'), 'Nights with denials' => (string) $rows->filter(fn ($r) => $r['denied'])->count(),
                'Nights over capacity' => (string) $rows->filter(fn ($r) => $r['uocc'] > 100)->count()],
            'note' => 'Unconstrained demand = rooms on the books + rooms denied for that night (lost or referred for lack of availability / restrictions). Up to 92 nights. Regrets are shown but not added – the guest was offered a room.'];
    }

    protected function r_cro_enquiries($from, $to): array
    {
        $E = \App\Models\CroEnquiry::class;
        $rows = $E::with('agent', 'roomType', 'reservation', 'referredHotel', 'referredFrom', 'competitor')
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(fn ($e) => ['no' => $e->number, 'at' => $e->created_at->format('d M Y H:i'), 'channel' => $e->channelLabel(), 'caller' => $e->caller_name,
                'stay' => $e->arrival_date->format('d M').' – '.$e->departure_date->format('d M Y'), 'rn' => $e->roomNights(), 'type' => $e->roomType?->name ?? 'Any',
                'value' => (float) ($e->booked_value ?? $e->quoted_total ?? 0) ?: null, 'outcome' => $e->statusLabel().($e->loss_type ? ' – '.$E::LOSS_TYPES[$e->loss_type][0] : ''),
                'detail' => match ($e->status) { 'booked' => ($e->reservation?->reservation_no ?? '').($e->referredFrom ? ' (from '.$e->referredFrom->name.')' : ''),
                    'referred' => '→ '.$e->referredHotel?->name.' '.$e->referred_reservation_no.' · '.$e->reasonLabel(), 'lost' => $e->reasonLabel().($e->competitor ? ' – '.$e->competitor->name : ''),
                    default => $e->follow_up_at ? 'follow up '.$e->follow_up_at->format('d M H:i') : '' },
                'agent' => $e->agent?->name, '_class' => $e->status === 'lost' ? 'row-red' : ($e->status === 'open' ? 'row-amber' : '')]);
        $k = \App\Services\CroService::kpis(Carbon::parse($from), Carbon::parse($to));

        return ['cols' => ['no' => 'No.', 'at' => 'Logged', 'channel' => 'Channel', 'caller' => 'Caller', 'stay' => 'Stay', 'rn' => 'Room nights', 'type' => 'Room type', 'value' => 'Value',
            'outcome' => 'Outcome', 'detail' => 'Detail', 'agent' => 'Agent'], 'rows' => $rows, 'money' => ['value'], 'sum' => ['rn', 'value'],
            'summary' => ['Enquiries' => (string) $k['total'], 'Booked' => $k['booked'].' ('.money($k['booked_revenue']).')', 'Referred' => $k['referred'].' ('.money($k['referred_revenue']).')',
                'Lost' => $k['lost'].' ('.money($k['lost_revenue']).')', 'Conversion' => $k['conversion'] !== null ? $k['conversion'].'%' : '—', 'Group retention' => $k['retention'] !== null ? $k['retention'].'%' : '—'],
            'note' => 'Value = room revenue booked (booked / referred) or the quote at stake (open / lost). Conversion = booked ÷ closed enquiries; group retention adds referrals to sister hotels.'];
    }

    protected function r_cro_lost_business($from, $to): array
    {
        $F = Carbon::parse($from);
        $T = Carbon::parse($to);
        $E = \App\Models\CroEnquiry::class;
        $rows = \App\Services\CroService::lostReasons($F, $T)->sortBy(fn ($x) => ($x['type'] === 'denial' ? 0 : 1).str_pad((string) (99999 - $x['count']), 5, '0', STR_PAD_LEFT))->values()
            ->map(fn ($x) => ['type' => $E::LOSS_TYPES[$x['type']][0] ?? '', 'reason' => $x['label'], 'count' => $x['count'], 'share' => $x['count'] ? $x['share'] : null, 'nights' => $x['nights'],
                'value' => $x['value'], 'referred' => $x['referred'] ?: null, 'kept' => $x['kept'] ?: null]);
        $k = \App\Services\CroService::kpis($F, $T);

        return ['cols' => ['type' => 'Type', 'reason' => 'Reason', 'count' => 'Lost', 'share' => 'Share of lost %', 'nights' => 'Room nights', 'value' => 'Value lost', 'referred' => 'Referred instead', 'kept' => 'Kept in group'],
            'rows' => $rows, 'money' => ['value', 'kept'], 'sum' => ['count', 'nights', 'value', 'referred', 'kept'],
            'summary' => ['Denials' => $k['denials'].' ('.number_format($k['denied_nights']).' room nights)', 'Regrets' => $k['regrets'].' ('.number_format($k['regret_nights']).' room nights)',
                'Value lost' => money($k['lost_revenue']), 'Kept in the group' => money($k['referred_revenue'])],
            'note' => 'Denial = the hotel could not offer (sold out, room type full, restrictions, group / party size) – true unconstrained demand. Regret = we offered and the guest declined. Denials that were booked at a sister hotel appear under “Referred instead”.'];
    }

    protected function r_cro_referrals($from, $to): array
    {
        $hotels = \App\Services\CroService::groupHotels(auth()->user())->keyBy('id');
        $E = \App\Models\CroEnquiry::class;
        $rows = $E::withoutGlobalScope('hotel')->whereIn('hotel_id', $hotels->keys())->where('status', 'referred')->whereIn('referred_hotel_id', $hotels->keys())
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('created_at')->get()
            ->map(fn ($e) => ['at' => $e->created_at->format('d M Y'), 'no' => $e->number, 'from' => $hotels[$e->hotel_id]->name, 'to' => $hotels[$e->referred_hotel_id]->name, 'res' => $e->referred_reservation_no,
                'guest' => $e->caller_name, 'stay' => $e->arrival_date->format('d M').' – '.$e->departure_date->format('d M Y'), 'rn' => $e->roomNights(), 'why' => $e->reasonLabel(), 'value' => (float) $e->booked_value]);

        return ['cols' => ['at' => 'Date', 'no' => 'Enquiry', 'from' => 'From hotel', 'to' => 'Booked at', 'res' => 'Reservation', 'guest' => 'Guest', 'stay' => 'Stay', 'rn' => 'Room nights', 'why' => 'Why not kept', 'value' => 'Room revenue kept'],
            'rows' => $rows, 'money' => ['value'], 'sum' => ['rn', 'value'], 'summary' => ['Referrals' => (string) $rows->count(), 'Revenue kept in the group' => money($rows->sum('value'))],
            'note' => $hotels->count() > 1 ? 'All hotels of the group in your central reservations scope.' : 'Only this hotel is in your scope – group reservations agents see referrals between every hotel of the group.'];
    }

}
