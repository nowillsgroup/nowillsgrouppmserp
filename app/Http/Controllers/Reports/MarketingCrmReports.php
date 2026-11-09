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
 * Marketing & CRM reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait MarketingCrmReports
{
    protected function r_concierge_places($from, $to): array
    {
        // Rewritten against the real schema: category/area/address/phone/website/distance_km/hours/price_level/tip/portal_visible.
        $rows = ConciergePlace::orderBy('sort_order')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'category' => $r->category, 'area' => $r->area,
            'address' => $r->address, 'phone' => $r->phone, 'distance_km' => $r->distance_km,
            'price_level' => $r->price_level, 'portal' => $r->portal_visible ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'category' => 'Category', 'area' => 'Area', 'address' => 'Address', 'phone' => 'Phone', 'distance_km' => 'Distance (km)', 'price_level' => 'Price Level', 'portal' => 'On Portal'], 'rows' => $rows, 'note' => 'Concierge place/vendor directory.'];
    }

    protected function r_crm_campaigns($from, $to): array
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $row = fn ($name, $kind, $ch, $st) => ['name' => $name, 'kind' => $kind, 'ch' => \App\Services\CrmService::CHANNELS[$ch] ?? $ch, 'rcpt' => $st['recipients'], 'sent' => $st['sent'],
            'skip' => $st['skipped'], 'fail' => $st['failed'], 'clicks' => $st['clicks'], 'ctr' => $st['ctr'], 'unsub' => $st['unsubscribes'],
            'book' => $st['bookings'], 'nights' => $st['nights'], 'rev' => $st['revenue'], 'disc' => $st['discount']];
        $rows = \App\Models\MarketingCampaign::whereIn('status', ['sent', 'sending'])->whereBetween('sent_at', [$f->copy()->startOfDay(), $t->copy()->endOfDay()])->orderBy('sent_at')->get()
            ->map(fn ($c) => $row($c->name, 'Campaign · '.$c->sent_at?->format('d M Y'), $c->channel, \App\Services\CrmService::stats($c)))
            ->concat(\App\Models\MarketingJourney::orderBy('name')->get()->map(fn ($j) => $row($j->name, 'Automation · '.$j->triggerLabel(), $j->channel, \App\Services\CrmService::stats($j, $f, $t)))
                ->filter(fn ($r) => $r['rcpt'] > 0))->values();

        return ['cols' => ['name' => 'Campaign / automation', 'kind' => 'Type', 'ch' => 'Channel', 'rcpt' => 'Recipients', 'sent' => 'Delivered', 'skip' => 'Skipped', 'fail' => 'Failed',
            'clicks' => 'Clicks', 'ctr' => 'CTR %', 'unsub' => 'Unsubs', 'book' => 'Bookings', 'nights' => 'Room nights', 'rev' => 'Room revenue', 'disc' => 'Promo discount'],
            'rows' => $rows, 'money' => ['rev', 'disc'], 'sum' => ['rcpt', 'sent', 'skip', 'fail', 'clicks', 'unsub', 'book', 'nights', 'rev', 'disc'],
            'note' => 'Campaigns sent in the period; automations count messages sent in the period. Bookings are credited when made within '.setting('crm_attribution_days', '30').' days of a message, through its link, or with its promo code; cancelled bookings are excluded. Revenue = room rate × nights before tax.'];
    }

    protected function r_crm_guest_value($from, $to): array
    {
        $guests = Guest::where('stays_count', '>', 0)->orderByDesc('total_revenue')->get();
        $rows = $guests->map(function ($g) {
            $s = \App\Services\CrmService::rfm($g);

            return ['guest' => $g->full_name, 'country' => $g->country, 'stays' => $g->stays_count, 'nights' => $g->nights_count, 'rev' => (float) $g->total_revenue,
                'last' => $g->last_stay_on?->format('d-m-Y'), 'days' => $g->last_stay_on ? (int) $g->last_stay_on->diffInDays(bdate()) : null,
                'rfm' => $s['r'].'-'.$s['f'].'-'.$s['m'], 'seg' => $s['label'],
                'consent' => collect(['email' => 'E', 'sms' => 'S', 'whatsapp' => 'W'])->filter(fn ($l, $ch) => $g->{$ch.'_opt_in'})->implode('') ?: '—',
                'tags' => $g->tags];
        });

        return ['cols' => ['guest' => 'Guest', 'country' => 'Country', 'stays' => 'Stays', 'nights' => 'Nights', 'rev' => 'Revenue', 'last' => 'Last stay', 'days' => 'Days since',
            'rfm' => 'R-F-M', 'seg' => 'Segment', 'consent' => 'Consent', 'tags' => 'Tags'], 'rows' => $rows, 'money' => ['rev'], 'sum' => ['stays', 'nights', 'rev'],
            'note' => 'Scores 1–5. Recency: ≤90 / 180 / 365 / 730 days. Frequency: 1, 2, 3–4, 5–9, 10+ stays. Monetary: net revenue ≥ 25k / 75k / 150k / 400k. Consent: E = e-mail, S = SMS, W = WhatsApp. Statistics refresh at the night audit.'];
    }

    protected function r_crm_promos($from, $to): array
    {
        $res = Reservation::with('rooms')->whereNotNull('promo_code_id')->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->get()->groupBy('promo_code_id');
        $rows = \App\Models\PromoCode::orderBy('code')->get()->map(function ($p) use ($res) {
            $all = $res->get($p->id, collect());
            $live = $all->where('status', '!=', 'cancelled');
            $nights = $live->sum(fn ($r) => $r->rooms->sum(fn ($x) => $x->arrival_date->diffInDays($x->departure_date)));

            return ['code' => $p->code, 'name' => $p->name, 'offer' => $p->offerLabel(), 'status' => $p->is_active ? 'Active' : 'Inactive', 'book' => $live->count(), 'cxl' => $all->count() - $live->count(),
                'nights' => $nights, 'rev' => round($live->sum(fn ($r) => $r->rooms->sum(fn ($x) => (float) $x->rate * $x->arrival_date->diffInDays($x->departure_date))), 2),
                'disc' => round((float) $live->sum('promo_discount'), 2), 'uses' => $p->usesCount().($p->max_uses ? ' / '.$p->max_uses : '')];
        })->filter(fn ($r) => $r['book'] + $r['cxl'] > 0 || $r['status'] === 'Active')->values();

        return ['cols' => ['code' => 'Code', 'name' => 'Offer name', 'offer' => 'Discount', 'status' => 'Status', 'book' => 'Bookings', 'cxl' => 'Cancelled', 'nights' => 'Room nights',
            'rev' => 'Revenue (after discount)', 'disc' => 'Discount given', 'uses' => 'Uses (all time)'], 'rows' => $rows, 'money' => ['rev', 'disc'], 'sum' => ['book', 'cxl', 'nights', 'rev', 'disc']];
    }

    protected function r_cro_contacts($from, $to): array
    {
        // Rewritten against the real schema: CRO contacts are notes on an enquiry (type/note/user_id/created_at).
        $rows = CroContact::with('enquiry', 'user')->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->get()->map(fn ($r) => [
            'enquiry' => optional($r->enquiry)->number, 'type' => $r->typeLabel(),
            'note' => $r->note, 'user' => optional($r->user)->name, 'created' => $r->created_at,
        ]);
        return ['cols' => ['enquiry' => 'Enquiry', 'type' => 'Type', 'note' => 'Note', 'user' => 'User', 'created' => 'Created'], 'rows' => $rows, 'note' => 'CRO contact log.'];
    }

    protected function r_digital_content($from, $to): array
    {
        $P = \App\Models\DmPost::class;
        $F = Carbon::parse($from)->startOfDay();
        $T = Carbon::parse($to)->endOfDay();
        $names = \App\Models\DmChannel::pluck('name', 'id');
        $rows = $P::with('author', 'reviewer')->whereBetween('scheduled_at', [$F, $T])->where('status', '!=', 'cancelled')->orderBy('scheduled_at')->get()->map(fn ($p) => [
            'slot' => $p->scheduled_at->format('d M Y H:i'), 'title' => $p->title, 'channels' => collect((array) $p->channels)->map(fn ($id) => $names[$id] ?? '')->implode(', '), 'pillar' => $P::PILLARS[$p->pillar] ?? '',
            'status' => $p->isMissed() ? 'Missed slot' : $p->statusLabel(), 'author' => $p->author?->name, 'approver' => $p->reviewer?->name ?? '',
            'turnaround' => $p->submitted_at && $p->reviewed_at ? round($p->submitted_at->diffInMinutes($p->reviewed_at) / 60, 1) : '',
            'reach' => $p->status === 'published' ? $p->reach : null, 'eng' => $p->status === 'published' ? $p->engagements : null, 'er' => $p->status === 'published' ? ($p->engagementRate() ?? '') : '',
            'clicks' => $p->status === 'published' ? $p->clicks : null, '_class' => $p->isMissed() ? 'row-alert' : ''])->values();
        $pub = $rows->where('status', 'Published');

        return ['cols' => ['slot' => 'Slot', 'title' => 'Post', 'channels' => 'Channels', 'pillar' => 'Pillar', 'status' => 'Status', 'author' => 'Author', 'approver' => 'Approved by', 'turnaround' => 'Approval (h)',
            'reach' => 'Reach', 'eng' => 'Engagements', 'er' => 'Eng. %', 'clicks' => 'Clicks'],
            'rows' => $rows, 'sum' => ['reach', 'eng', 'clicks'],
            'summary' => ['Posts' => (string) $rows->count(), 'Published' => (string) $pub->count(), 'Missed slots' => (string) $rows->where('status', 'Missed slot')->count(),
                'Avg approval' => $rows->where('turnaround', '!==', '')->count() ? round($rows->where('turnaround', '!==', '')->avg('turnaround'), 1).' h' : '—',
                'Engagement rate' => $pub->sum('reach') ? round(100 * $pub->sum('eng') / $pub->sum('reach'), 2).'%' : '—'],
            'note' => 'Posts scheduled in the period (cancelled excluded). Approval time = sent for approval → decision. Missed = approved but not marked published an hour after the slot.'];
    }

    protected function r_digital_influencers($from, $to): array
    {
        $I = \App\Models\DmInfluencer::class;
        $F = Carbon::parse($from)->toDateString();
        $T = Carbon::parse($to)->toDateString();
        $rows = $I::where(fn ($q) => $q->whereDate('stay_from', '>=', $F)->whereDate('stay_from', '<=', $T)->orWhere(fn ($w) => $w->whereNull('stay_from')->whereBetween('created_at', [$F.' 00:00:00', $T.' 23:59:59'])))
            ->orderBy('stay_from')->get()->map(function ($i) use ($I) {
                $s = \App\Services\DigitalMarketingService::influencerStats($i);

                return ['no' => $i->number, 'name' => $i->name, 'handle' => $i->handle, 'platform' => \App\Models\DmChannel::PLATFORMS[$i->platform][0] ?? $i->platform, 'followers' => $i->followers,
                    'stay' => $i->stay_from?->format('d M Y') ?? '', 'status' => $i->isOverdue() ? 'Content overdue' : $i->statusLabel(), 'fee' => (float) $i->fee, 'comp' => (float) $i->comp_value, 'cost' => $s['cost'],
                    'reach' => $i->reach, 'eng' => $i->engagements, 'cpe' => $s['cpe'] ?? '', 'bookings' => $s['bookings'], 'revenue' => $s['revenue'], 'rating' => $i->rating ?? '',
                    '_class' => $i->isOverdue() ? 'row-alert' : ''];
            })->values();

        return ['cols' => ['no' => 'No.', 'name' => 'Creator', 'handle' => 'Handle', 'platform' => 'Platform', 'followers' => 'Followers', 'stay' => 'Stay', 'status' => 'Status', 'fee' => 'Fee', 'comp' => 'Comp value',
            'cost' => 'Total cost', 'reach' => 'Reach', 'eng' => 'Engagements', 'cpe' => 'Cost / eng.', 'bookings' => 'Bookings', 'revenue' => 'Revenue', 'rating' => 'Rating'],
            'rows' => $rows, 'money' => ['fee', 'comp', 'cost', 'cpe', 'revenue'], 'sum' => ['fee', 'comp', 'cost', 'reach', 'eng', 'bookings', 'revenue'],
            'summary' => ['Collaborations' => (string) $rows->count(), 'Total cost' => money($rows->sum('cost')), 'Reach' => number_format($rows->sum('reach')), 'Revenue via links' => money($rows->sum('revenue'))],
            'note' => 'Collaborations with a stay in the period (or proposed in the period without dates). Cost = cash fee + complimentary value. Revenue = bookings through the creator\'s tracked link.'];
    }

    protected function r_digital_links($from, $to): array
    {
        $F = Carbon::parse($from);
        $T = Carbon::parse($to);
        $rows = \App\Models\DmLink::with('campaign')->orderBy('name')->get()->map(function ($l) use ($F, $T) {
            $s = \App\Services\DigitalMarketingService::linkStats($l, $F, $T);

            return ['code' => '/go/'.$l->code, 'name' => $l->name, 'source' => $l->utm_source, 'medium' => $l->utm_medium, 'campaign' => $l->utm_campaign, 'clicks' => $s['clicks'], 'unique' => $s['unique'],
                'mobile' => $s['clicks'] ? round(100 * ($s['devices']['mobile'] ?? 0) / $s['clicks']) : '', 'bookings' => $s['bookings'], 'nights' => $s['nights'], 'revenue' => $s['revenue'], 'conv' => $s['conversion'] ?? ''];
        })->filter(fn ($r) => $r['clicks'] || $r['bookings'])->sortByDesc('revenue')->values();
        $bySource = $rows->groupBy(fn ($r) => $r['source'].' / '.$r['medium'])->map(fn ($g) => money($g->sum('revenue')))->sortDesc()->take(3)->all();

        return ['cols' => ['code' => 'Link', 'name' => 'Name', 'source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign', 'clicks' => 'Clicks', 'unique' => 'Unique',
            'mobile' => 'Mobile %', 'bookings' => 'Bookings', 'nights' => 'Nights', 'revenue' => 'Room revenue', 'conv' => 'Conversion %'],
            'rows' => $rows, 'money' => ['revenue'], 'sum' => ['clicks', 'unique', 'bookings', 'nights', 'revenue'],
            'summary' => ['Clicks' => number_format($rows->sum('clicks')), 'Bookings' => (string) $rows->sum('bookings'), 'Room revenue' => money($rows->sum('revenue'))] + array_combine(array_map(fn ($k) => 'Top: '.$k, array_keys($bySource)), array_values($bySource)),
            'note' => 'Clicks on tracked links in the period (bots and repeat clicks within 30 s excluded) and booking-engine bookings made in the period that were credited to each link.'];
    }

    protected function r_digital_paid($from, $to): array
    {
        $F = Carbon::parse($from);
        $T = Carbon::parse($to);
        $C = \App\Models\DmCampaign::class;
        $rows = $C::with('channel')->whereDate('start_date', '<=', $T->toDateString())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $F->toDateString()))
            ->where('status', '!=', 'draft')->orderBy('start_date')->get()->map(function ($c) use ($F, $T, $C) {
                $s = \App\Services\DigitalMarketingService::campaignStats($c, $F, $T);

                return ['no' => $c->number, 'name' => $c->name, 'platform' => $c->channel?->platformLabel() ?? '', 'objective' => $C::OBJECTIVES[$c->objective], 'status' => $c->statusLabel(),
                    'budget' => (float) $c->budget, 'spend' => $s['spend'], 'impressions' => $s['impressions'], 'clicks' => $s['clicks'], 'ctr' => $s['ctr'] ?? '', 'cpc' => $s['cpc'] ?? '',
                    'conv' => $s['conversions'], 'value' => $s['conversion_value'], 'roas' => $s['roas'] ?? '', 'bookings' => $s['bookings'], 'revenue' => $s['revenue'], 'pms_roas' => $s['pms_roas'] ?? '',
                    '_class' => $c->target_roas && $s['pms_roas'] !== null && $s['pms_roas'] < (float) $c->target_roas ? 'row-alert' : ''];
            })->values();
        $spend = $rows->sum('spend');

        return ['cols' => ['no' => 'No.', 'name' => 'Campaign', 'platform' => 'Platform', 'objective' => 'Objective', 'status' => 'Status', 'budget' => 'Budget', 'spend' => 'Spend', 'impressions' => 'Impressions',
            'clicks' => 'Clicks', 'ctr' => 'CTR %', 'cpc' => 'CPC', 'conv' => 'Platform conv.', 'value' => 'Platform value', 'roas' => 'Platform ROAS', 'bookings' => 'PMS bookings', 'revenue' => 'PMS revenue', 'pms_roas' => 'PMS ROAS'],
            'rows' => $rows, 'money' => ['budget', 'spend', 'cpc', 'value', 'revenue'], 'sum' => ['budget', 'spend', 'impressions', 'clicks', 'conv', 'value', 'bookings', 'revenue'],
            'summary' => ['Spend' => money($spend), 'Clicks' => number_format($rows->sum('clicks')), 'PMS bookings' => (string) $rows->sum('bookings'), 'PMS revenue' => money($rows->sum('revenue')),
                'Blended ROAS' => $spend > 0 ? round($rows->sum('revenue') / $spend, 2).'×' : '—'],
            'note' => 'Spend and delivery for the days in the period. PMS bookings / revenue = booking-engine bookings made in the period through the campaign\'s tracked links (room rate × nights, cancelled excluded). Highlighted: PMS ROAS below the campaign\'s target.'];
    }

    protected function r_digital_social($from, $to): array
    {
        $F = Carbon::parse($from)->startOfMonth();
        $T = Carbon::parse($to)->startOfMonth();
        $rows = \App\Models\DmChannel::orderBy('name')->get()->filter(fn ($c) => $c->kind() === 'social')->map(function ($c) use ($F, $T) {
            $st = \App\Models\DmChannelStat::where('channel_id', $c->id)->whereDate('month', '>=', $F->toDateString())->whereDate('month', '<=', $T->toDateString())->orderBy('month')->get();
            $before = \App\Models\DmChannelStat::where('channel_id', $c->id)->whereDate('month', '<', $F->toDateString())->orderByDesc('month')->value('followers');
            $start = $before ?? $st->first()?->followers;
            $end = $st->last()?->followers;
            $posts = \App\Models\DmPost::whereJsonContains('channels', $c->id)->where('status', 'published')->whereBetween('published_at', [$F, $T->copy()->endOfMonth()])->count();

            return ['channel' => $c->name, 'platform' => $c->platformLabel(), 'start' => $start ?? '', 'end' => $end ?? '', 'growth' => $start && $end !== null ? $end - $start : null,
                'growth_pct' => $start && $end !== null ? round(100 * ($end - $start) / $start, 1) : '', 'reach' => (int) $st->sum('reach'), 'impressions' => (int) $st->sum('impressions'),
                'engagements' => (int) $st->sum('engagements'), 'er' => $st->sum('reach') ? round(100 * $st->sum('engagements') / $st->sum('reach'), 2) : '', 'clicks' => (int) $st->sum('clicks'), 'posts' => $posts,
                '_class' => $st->isEmpty() ? 'row-alert' : ''];
        })->values();

        return ['cols' => ['channel' => 'Channel', 'platform' => 'Platform', 'start' => 'Followers at start', 'end' => 'Followers at end', 'growth' => 'Growth', 'growth_pct' => 'Growth %',
            'reach' => 'Reach', 'impressions' => 'Impressions', 'engagements' => 'Engagements', 'er' => 'Engagement %', 'clicks' => 'Clicks', 'posts' => 'Posts published'],
            'rows' => $rows, 'sum' => ['growth', 'reach', 'impressions', 'engagements', 'clicks', 'posts'],
            'summary' => ['Channels' => (string) $rows->count(), 'Followers now' => number_format((int) $rows->sum(fn ($r) => (int) $r['end'])), 'Reach' => number_format($rows->sum('reach')),
                'Engagement rate' => $rows->sum('reach') ? round(100 * $rows->sum('engagements') / $rows->sum('reach'), 2).'%' : '—'],
            'note' => 'Monthly figures as recorded from each platform\'s insights for the months in the period. Highlighted channels have no figures for the period.'];
    }

    protected function r_dm_ad_stats($from, $to): array
    {
        $rows = DmAdStat::with('dmChannel.adCampaign')->whereBetween('stat_date', [$from, $to])->get()->map(fn ($r) => [
            'channel' => optional($r->dmChannel)->name, 'campaign' => optional($r->adCampaign)->name,
            'date' => $r->stat_date, 'impressions' => $r->impressions, 'clicks' => $r->clicks,
            'ctr' => $r->ctr_pct, 'cost' => $r->cost, 'status' => $r->status,
        ]);
        return ['cols' => ['channel' => 'Channel', 'campaign' => 'Campaign', 'date' => 'Date', 'impressions' => 'Impressions', 'clicks' => 'Clicks', 'ctr' => 'CTR %', 'cost' => 'Cost', 'status' => 'Status'], 'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost'], 'note' => 'Digital marketing ad statistics.'];
    }

    protected function r_dm_link_clicks($from, $to): array
    {
        // Rewritten against the real schema: clicks reference a link (link()); device/referrer_host/ip_hash are stored.
        $rows = DmLinkClick::with('link')->whereBetween('clicked_at', [$from, $to])->orderByDesc('clicked_at')->get()->map(fn ($r) => [
            'link' => optional($r->link)->name, 'code' => optional($r->link)->code,
            'campaign' => optional($r->link)->utm_campaign,
            'clicked' => $r->clicked_at, 'device' => $r->device, 'referrer' => $r->referrer_host,
        ]);
        return ['cols' => ['link' => 'Link', 'code' => 'Code', 'campaign' => 'Campaign', 'clicked' => 'Clicked', 'device' => 'Device', 'referrer' => 'Referrer'], 'rows' => $rows, 'note' => 'Digital marketing link click tracking.'];
    }

    protected function r_marketing_budget($from, $to): array
    {
        $F = Carbon::parse($from)->startOfMonth();
        $T = Carbon::parse($to);
        $acc = [];
        for ($m = $F->copy(); $m->lte($T); $m->addMonthNoOverflow()) {
            $bva = $acc[$m->year] ??= \App\Services\MarketingPlanService::budgetVsActual($m->year);
        }
        $rows = collect(\App\Models\MarketingEvent::budgetLines())->map(function ($label, $k) use ($F, $T, $acc) {
            $b = $a = $c = 0.0;
            for ($m = $F->copy(); $m->lte($T); $m->addMonthNoOverflow()) {
                $r = $acc[$m->year][$k];
                $b += $r['budget'][$m->month];
                $a += $r['actual'][$m->month];
                $c += $r['committed'][$m->month];
            }

            return ['line' => $label, 'budget' => round($b, 2), 'actual' => round($a, 2), 'committed' => round($c, 2), 'var' => round($b - $a, 2), 'pct' => $b > 0 ? round($a * 100 / $b, 1) : '',
                '_class' => $b > 0 && $a > $b ? 'row-alert' : ''];
        })->filter(fn ($r) => $r['budget'] || $r['actual'] || $r['committed'])->values();

        return ['cols' => ['line' => 'Budget line', 'budget' => 'Budget', 'actual' => 'Actual', 'committed' => 'Committed', 'var' => 'Variance', 'pct' => '% used'],
            'rows' => $rows, 'money' => ['budget', 'actual', 'committed', 'var'], 'sum' => ['budget', 'actual', 'committed', 'var'],
            'summary' => ['Budget' => money($rows->sum('budget')), 'Actual' => money($rows->sum('actual')), 'Committed' => money($rows->sum('committed')), 'Remaining' => money($rows->sum('budget') - $rows->sum('actual') - $rows->sum('committed'))],
            'note' => 'Whole months in the period. Actual = closed / cancelled marketing events (by start month), client entertainment on completed sales activities, digital spend. Committed = planned / confirmed events not yet closed.'];
    }

    protected function r_marketing_collateral($from, $to): array
    {
        // Rewritten against the real schema: title/kind/language/version/valid_until/file_name/downloads/is_active; no collateral type table.
        $rows = MarketingCollateral::with('uploader')->orderBy('title')->get()->map(fn ($r) => [
            'title' => $r->title, 'kind' => $r->kind, 'language' => $r->language,
            'version' => $r->version, 'valid_until' => $r->valid_until,
            'file' => $r->file_name, 'downloads' => $r->downloads,
            'uploaded_by' => optional($r->uploader)->name, 'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['title' => 'Title', 'kind' => 'Kind', 'language' => 'Language', 'version' => 'Version', 'valid_until' => 'Valid Until', 'file' => 'File', 'downloads' => 'Downloads', 'uploaded_by' => 'Uploaded By', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Marketing collateral register.'];
    }

    protected function r_marketing_consent_logs($from, $to): array
    {
        $rows = MarketingConsentLog::with('guest.consentType')->whereBetween('logged_at', [$from, $to])->get()->map(fn ($r) => [
            'guest' => optional($r->guest)->name, 'type' => optional($r->consentType)->name,
            'logged' => $r->logged_at, 'consented' => $r->consented, 'source' => $r->source,
            'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['guest' => 'Guest', 'type' => 'Type', 'logged' => 'Logged', 'consented' => 'Consented', 'source' => 'Source', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Marketing consent audit log.'];
    }

    protected function r_marketing_messages($from, $to): array
    {
        $rows = MarketingMessage::with('campaign.messageType.channelType')->whereBetween('sent_at', [$from, $to])->get()->map(fn ($r) => [
            'campaign' => optional($r->campaign)->name, 'type' => optional($r->messageType)->name,
            'channel' => optional($r->channelType)->name, 'subject' => $r->subject,
            'sent' => $r->sent_at, 'recipients' => $r->recipient_count, 'status' => $r->status,
        ]);
        return ['cols' => ['campaign' => 'Campaign', 'type' => 'Type', 'channel' => 'Channel', 'subject' => 'Subject', 'sent' => 'Sent', 'recipients' => 'Recipients', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Marketing message send log.'];
    }

    protected function r_marketing_roi($from, $to): array
    {
        $M = \App\Models\MarketingEvent::class;
        $events = $M::with('owner')->whereDate('start_date', '>=', Carbon::parse($from)->toDateString())->whereDate('start_date', '<=', Carbon::parse($to)->toDateString())->orderBy('start_date')->get();
        $roi = \App\Services\MarketingPlanService::roi($events);
        $rows = $events->map(fn ($e) => ['no' => $e->number, 'name' => $e->name, 'type' => $e->typeLabel(), 'dates' => $e->start_date->format('d M Y'), 'segment' => $M::SEGMENTS[$e->segment] ?? '',
            'market' => $M::MARKETS[$e->market] ?? '', 'status' => $e->statusLabel(), 'budget' => (float) $e->budget, 'cost' => in_array($e->status, ['done', 'cancelled']) ? (float) $e->actual_cost : 0.0,
            'leads' => $e->leads, 'cpl' => $roi[$e->id]['cpl'] ?? '', 'opps' => $roi[$e->id]['opps'], 'won' => $roi[$e->id]['won_value'], 'roi' => $roi[$e->id]['roi'] ?? '',
            '_class' => $e->status === 'cancelled' ? 'row-alert' : ''])->values();
        $cost = $rows->sum('cost');

        return ['cols' => ['no' => 'No.', 'name' => 'Event', 'type' => 'Type', 'dates' => 'Starts', 'segment' => 'Segment', 'market' => 'Market', 'status' => 'Status', 'budget' => 'Budget', 'cost' => 'Actual cost',
            'leads' => 'Leads', 'cpl' => 'Cost / lead', 'opps' => 'Opportunities', 'won' => 'Revenue won', 'roi' => 'ROI %'],
            'rows' => $rows, 'money' => ['budget', 'cost', 'cpl', 'won'], 'sum' => ['budget', 'cost', 'leads', 'opps', 'won'],
            'summary' => ['Events' => (string) $rows->count(), 'Spent' => money($cost), 'Leads' => (string) $rows->sum('leads'), 'Revenue won' => money($rows->sum('won')),
                'Overall ROI' => $cost > 0 ? round(($rows->sum('won') - $cost) * 100 / $cost, 1).'%' : '—'],
            'note' => 'Revenue won = opportunities whose source marketing event is this event and that are won. ROI = (revenue won − actual cost) ÷ actual cost, for closed events.'];
    }

    protected function r_marketing_segments($from, $to): array
    {
        // Rewritten against the real schema: name/description/match/rules/is_active/created_by; there is no segment type table.
        $rows = MarketingSegment::with('creator')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'description' => $r->description, 'match' => $r->match,
            'rules' => $r->describe(), 'created_by' => optional($r->creator)->name,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'description' => 'Description', 'match' => 'Match', 'rules' => 'Rules', 'created_by' => 'Created By', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Marketing segment definitions.'];
    }

}
