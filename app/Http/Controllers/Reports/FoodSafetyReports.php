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
 * Food Safety reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait FoodSafetyReports
{
    protected function r_food_waste($from, $to): array
    {
        $rows = \App\Models\FsWaste::with('item', 'menuItem', 'store', 'outlet', 'user')->whereNull('cancelled_at')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->orderBy('date')->get()
            ->map(fn ($w) => ['no' => $w->waste_no, 'date' => dmy($w->date), 'item' => $w->name(), 'type' => \App\Models\FsWaste::SOURCES[$w->source], 'qty' => rtrim(rtrim(number_format((float) $w->qty, 3), '0'), '.').' '.$w->unit,
                'reason' => \App\Models\FsWaste::REASONS[$w->reason], 'where' => trim(($w->store?->name ?? '').($w->outlet ? ' / '.$w->outlet->name : ''), ' /'), 'cost' => (float) $w->cost, 'by' => $w->user?->name]);
        $s = \App\Services\FoodWasteService::summary($from->toDateString(), $to->toDateString());

        return ['cols' => ['no' => 'No.', 'date' => 'Date', 'item' => 'Item', 'type' => 'Type', 'qty' => 'Quantity', 'reason' => 'Reason', 'where' => 'Store / outlet', 'cost' => 'Cost', 'by' => 'By'],
            'rows' => $rows, 'money' => ['cost'], 'sum' => ['cost'], 'note' => 'Taken out of stock '.money($s['stock']).' (5042 Food Waste)'.($s['pct'] !== null ? ' = '.$s['pct'].'% of food & beverage cost' : '').'; plate waste '.money($s['plate']).' (analysis only).'];
    }

    protected function r_fs_certificates($from, $to): array
    {
        $rows = \App\Models\FsCertificate::with('equipment.department')->whereBetween('expiry_date', [$from, $to])->get()->map(fn ($r) => [
            'equipment' => optional($r->equipment)->name, 'dept' => optional($r->department)->name,
            'cert_no' => $r->certificate_no, 'issued' => $r->issued_date, 'expiry' => $r->expiry_date,
            'issuer' => $r->issuing_body, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['equipment' => 'Equipment', 'dept' => 'Dept', 'cert_no' => 'Cert No', 'issued' => 'Issued', 'expiry' => 'Expiry', 'issuer' => 'Issuer', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Food safety certificate register with expiry tracking.'];
    }

    protected function r_fs_equipment($from, $to): array
    {
        // Rewritten against the real schema: type/location/outlet_id/min_c/max_c/checks_per_day; there is no department or equipment type.
        $rows = \App\Models\FsEquipment::with('outlet')->orderBy('name')->get()->map(fn ($r) => [
            'name' => $r->name, 'type' => $r->type, 'location' => $r->location,
            'outlet' => optional($r->outlet)->name,
            'min_c' => $r->min_c, 'max_c' => $r->max_c, 'checks_per_day' => $r->checks_per_day,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['name' => 'Name', 'type' => 'Type', 'location' => 'Location', 'outlet' => 'Outlet', 'min_c' => 'Min °C', 'max_c' => 'Max °C', 'checks_per_day' => 'Checks/Day', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Food safety equipment register with temperature limits.'];
    }

    protected function r_fs_labels($from, $to): array
    {
        // Rewritten against the real schema: FS labels are date/safety labels per batch — item/storage/prepared_at/use_by/qty/allergens.
        $rows = \App\Models\FsLabel::with('user')->orderByDesc('use_by')->get()->map(fn ($r) => [
            'item' => $r->item, 'batch' => $r->batch, 'qty' => $r->qty,
            'storage' => $r->storage, 'prepared_at' => $r->prepared_at, 'use_by' => $r->use_by,
            'allergens' => implode(', ', (array) $r->allergens), 'by' => optional($r->user)->name,
        ]);
        return ['cols' => ['item' => 'Item', 'batch' => 'Batch', 'qty' => 'Qty', 'storage' => 'Storage', 'prepared_at' => 'Prepared', 'use_by' => 'Use By', 'allergens' => 'Allergens', 'by' => 'By'], 'rows' => $rows, 'note' => 'Food safety date/safety labels per batch.'];
    }

    protected function r_haccp_checklists($from, $to): array
    {
        $FS = \App\Services\FoodSafetyService::class;
        $runs = \App\Models\FsChecklistRun::with('completer', 'signer')->whereDate('period', '>=', $from->copy()->subMonth()->toDateString())->whereDate('period', '<=', $to->toDateString())->get()->keyBy(fn ($r) => $r->fs_checklist_id.'|'.$r->period->toDateString());
        $rows = collect();
        foreach (\App\Models\FsChecklist::orderBy('name')->get() as $c) {
            $seen = [];
            for ($d = $from->copy(); $d->lte($to) && $d->lte(now()); $d->addDay()) {
                $p = $FS::periodFor($c, $d);
                if (isset($seen[$p->toDateString()]) || ($c->created_at && $c->created_at->toDateString() > $p->toDateString()) || (! $c->is_active && ! isset($runs[$c->id.'|'.$p->toDateString()]))) {
                    continue;
                }
                $seen[$p->toDateString()] = true;
                $r = $runs[$c->id.'|'.$p->toDateString()] ?? null;
                $current = $p->toDateString() === $FS::periodFor($c)->toDateString();
                $rows->push(['period' => $FS::periodLabel($c, $p), 'list' => $c->name, 'area' => $c->area, 'status' => $r ? ($r->failed ? 'completed – '.$r->failed.' failed' : 'completed') : ($current ? 'due' : 'MISSED'),
                    'by' => $r ? $r->completer?->name.' '.$r->completed_at->format('d/m H:i') : '', 'failed' => $r ? (int) $r->failed : 0,
                    'issues' => $r ? collect($r->answers)->where('ok', false)->map(fn ($a) => $a['item'].': '.$a['note'])->implode('; ') : '', 'signed' => $r?->signer?->name ?? ($r && $r->failed ? 'awaiting' : '')]);
            }
        }
        $missed = $rows->where('status', 'MISSED')->count();

        return ['cols' => ['period' => 'Period', 'list' => 'Checklist', 'area' => 'Area', 'status' => 'Status', 'by' => 'Completed by', 'failed' => 'Failed items', 'issues' => 'Issues', 'signed' => 'Signed off by'],
            'rows' => $rows, 'money' => [], 'sum' => ['failed'], 'note' => $rows->count().' checklist periods, '.$missed.' missed.'];
    }

    protected function r_haccp_temperatures($from, $to): array
    {
        $rows = \App\Models\FsTempLog::with('equipment', 'user', 'reviewer', 'supplier')->whereBetween('recorded_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('recorded_at')->get()
            ->map(fn ($l) => ['at' => $l->recorded_at->format('d/m/Y H:i'), 'kind' => \App\Models\FsTempLog::KINDS[$l->kind], 'what' => $l->subject().($l->supplier ? ' – '.$l->supplier->name : ''),
                'reading' => rtrim(rtrim(number_format((float) $l->reading_c, 1), '0'), '.').($l->reading2_c !== null ? ' → '.rtrim(rtrim(number_format((float) $l->reading2_c, 1), '0'), '.') : ''),
                'limit' => \App\Services\FoodSafetyService::limitText($l->min_c, $l->max_c), 'result' => $l->in_range ? ($l->coolingOpen() ? 'cooling' : 'OK') : 'OUT OF RANGE',
                'action' => $l->corrective_action, 'by' => $l->user?->name, 'reviewed' => $l->reviewer ? $l->reviewer->name.' '.$l->reviewed_at->format('d/m') : ($l->in_range ? '' : 'pending')]);
        $bad = $rows->where('result', 'OUT OF RANGE')->count();

        return ['cols' => ['at' => 'Date / time', 'kind' => 'Type', 'what' => 'Equipment / food', 'reading' => '°C', 'limit' => 'Critical limit', 'result' => 'Result', 'action' => 'Corrective action', 'by' => 'Recorded by', 'reviewed' => 'Reviewed'],
            'rows' => $rows, 'money' => [], 'sum' => [], 'note' => $rows->count().' readings, '.$bad.' outside the critical limit. Keep HACCP records for at least 2 years (Ghana FDA inspections).'];
    }

}
