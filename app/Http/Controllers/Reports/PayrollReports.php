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
 * Payroll reports — split out of ReportController, dispatched as $this->r_<slug>().
 */
trait PayrollReports
{
    protected function r_attendance($from, $to): array
    {
        $rows = Attendance::with('employee.department')->whereBetween('attendance_date', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'dept' => optional(optional($r->employee)->department)->name,
            'date' => $r->attendance_date, 'hours_sched' => $r->hours_scheduled, 'hours_worked' => $r->hours_worked,
            'overtime' => $r->overtime_hours, 'late' => $r->late_minutes, 'absent' => $r->absent, 'leave' => $r->leave_hours, 'status' => $r->status,
        ]);
        return ['cols' => ['employee' => 'Employee', 'dept' => 'Dept', 'date' => 'Date', 'hours_sched' => 'Sched Hrs', 'hours_worked' => 'Worked Hrs', 'overtime' => 'OT Hrs', 'late' => 'Late (min)', 'absent' => 'Absent', 'leave' => 'Leave Hrs', 'status' => 'Status'], 'rows' => $rows, 'sum' => ['hours_scheduled', 'hours_worked', 'overtime_hours', 'late_minutes', 'leave_hours'], 'note' => 'Daily attendance summary.'];
    }

    protected function r_employee_pay_components($from, $to): array
    {
        $rows = EmployeePayComponent::with('employee')->whereBetween('period_start', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'period' => $r->period_start . ' to ' . $r->period_end,
            'type' => $r->component_type, 'gross' => $r->gross_amount, 'basic' => $r->basic_salary,
            'overtime' => $r->overtime_amount, 'allowance' => $r->allowance_amount, 'deduction' => $r->deduction_amount, 'net' => $r->net_amount, 'status' => $r->status,
        ]);
        return ['cols' => ['employee' => 'Employee', 'period' => 'Period', 'type' => 'Type', 'gross' => 'Gross', 'basic' => 'Basic', 'overtime' => 'OT', 'allowance' => 'Allowance', 'deduction' => 'Deduction', 'net' => 'Net'], 'rows' => $rows, 'money' => ['gross_amount', 'basic_salary', 'overtime_amount', 'allowance_amount', 'deduction_amount', 'net_amount'], 'sum' => ['gross_amount', 'basic_salary', 'overtime_amount', 'allowance_amount', 'deduction_amount', 'net_amount'], 'note' => 'Employee pay component breakdown.'];
    }

    protected function r_leave_register($from, $to): array
    {
        $rows = \App\Models\LeaveRequest::with('employee.department', 'leaveType', 'decider')
            ->whereDate('date_from', '<=', $to->toDateString())->whereDate('date_to', '>=', $from->toDateString())->orderBy('date_from')->get()
            ->map(fn ($r) => ['employee' => $r->employee->name, 'code' => $r->employee->emp_code, 'dept' => $r->employee->department?->name, 'type' => $r->leaveType->code.' – '.$r->leaveType->name,
                'paid' => $r->leaveType->is_paid ? 'Paid' : 'Unpaid', 'from' => $r->date_from->format('d-m-Y'), 'to' => $r->date_to->format('d-m-Y'), 'days' => (float) $r->days,
                'status' => ucfirst($r->status), 'reason' => $r->reason, 'decided' => $r->decider ? $r->decider->name.' '.$r->decided_at?->format('d-m-Y') : '', 'note' => $r->decision_note]);

        return ['cols' => ['employee' => 'Employee', 'code' => 'Code', 'dept' => 'Department', 'type' => 'Leave type', 'paid' => 'Paid?', 'from' => 'From', 'to' => 'To', 'days' => 'Days',
            'status' => 'Status', 'reason' => 'Reason', 'decided' => 'Decided by', 'note' => 'Note'], 'rows' => $rows, 'sum' => ['days']];
    }

    protected function r_leave_types($from, $to): array
    {
        // Rewritten against the real schema: code/days_per_year/is_paid/carry_forward_max/is_active; there is no department on leave types.
        $rows = LeaveType::orderBy('name')->get()->map(fn ($r) => [
            'code' => $r->code, 'name' => $r->name, 'days_per_year' => $r->days_per_year,
            'paid' => $r->is_paid ? 'Yes' : 'No', 'carry_forward_max' => $r->carry_forward_max,
            'active' => $r->is_active ? 'Yes' : 'No',
        ]);
        return ['cols' => ['code' => 'Code', 'name' => 'Name', 'days_per_year' => 'Days/Year', 'paid' => 'Paid', 'carry_forward_max' => 'Carry Forward Max', 'active' => 'Active'], 'rows' => $rows, 'note' => 'Leave type definitions.'];
    }

    protected function r_pay_components($from, $to): array
    {
        $rows = EmployeePayComponent::with('employee')->whereBetween('period_start', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'period' => $r->period_start . ' to ' . $r->period_end,
            'type' => $r->component_type, 'description' => $r->description,
            'gross' => $r->gross_amount, 'basic' => $r->basic_salary, 'overtime' => $r->overtime_amount,
            'allowance' => $r->allowance_amount, 'deduction' => $r->deduction_amount,
            'net' => $r->net_amount, 'status' => $r->status,
        ]);
        return ['cols' => ['employee' => 'Employee', 'period' => 'Period', 'type' => 'Type', 'gross' => 'Gross', 'basic' => 'Basic', 'overtime' => 'OT', 'allowance' => 'Allowance', 'deduction' => 'Deduction', 'net' => 'Net'], 'rows' => $rows, 'money' => ['gross', 'basic', 'overtime', 'allowance', 'deduction', 'net'], 'sum' => ['gross', 'basic', 'overtime', 'allowance', 'deduction', 'net'], 'note' => 'Pay component breakdown per employee per period.'];
    }

    protected function r_paye_schedule($from, $to): array
    {
        $rows = collect();
        foreach ($this->statMonths($from, $to) as $p) {
            foreach (\App\Services\StatutoryService::build('paye', $p)['rows'] as $x) {
                $rows->push(['month' => Carbon::parse($p.'-01')->format('M Y'), 'code' => $x['emp_code'], 'name' => $x['name'], 'tin' => (string) $x['ghana_card'], 'position' => (string) $x['position'],
                    'basic' => $x['basic'], 'allowances' => $x['allowances'], 'overtime' => $x['overtime'], 'gross' => $x['gross'], 'relief' => $x['relief'], 'chargeable' => $x['chargeable'], 'paye' => $x['total']]);
            }
        }

        return ['cols' => ['month' => 'Month', 'code' => 'Emp. code', 'name' => 'Employee', 'tin' => 'TIN / Ghana Card', 'position' => 'Position', 'basic' => 'Basic', 'allowances' => 'Allowances',
            'overtime' => 'Overtime', 'gross' => 'Gross', 'relief' => 'SSNIT relief', 'chargeable' => 'Chargeable income', 'paye' => 'PAYE'],
            'rows' => $rows, 'money' => ['basic', 'allowances', 'overtime', 'gross', 'relief', 'chargeable', 'paye'], 'sum' => ['basic', 'allowances', 'overtime', 'gross', 'relief', 'chargeable', 'paye'],
            'note' => $rows->count().' line(s) · '.money($rows->sum('paye')).' PAYE withheld. PAYE is due to GRA by the 15th of the following month.'];
    }

    protected function r_payroll_register($from, $to): array
    {
        // BONUS and BONUS_TAX are kept out of "allowances & other" and "other deductions": GRA taxes a bonus apart from
        // the bands, so the register shows it apart too (v7.45).
        $std = ['BASIC', 'OT', 'BONUS', 'SSNIT', 'PAYE', 'BONUS_TAX', 'ADV'];
        $rows = \App\Models\Payslip::with('employee.department', 'run', 'lines')
            ->whereHas('run', fn ($q) => $q->whereDate('period_start', '>=', $from->copy()->startOfMonth()->toDateString())->whereDate('period_start', '<=', $to->toDateString()))
            ->get()->sortBy(fn ($p) => $p->run->period_start->format('Ymd').$p->employee->emp_code)->values()
            ->map(function ($p) use ($std) {
                $by = $p->lines->groupBy('code')->map(fn ($g) => (float) $g->sum('amount'));
                $earn = $p->lines->where('kind', 'earning');
                $ded = $p->lines->where('kind', 'deduction');

                return ['month' => $p->run->period_start->format('M Y'), 'status' => ucfirst($p->run->status), 'code' => $p->employee->emp_code, 'employee' => $p->employee->name,
                    'dept' => $p->employee->department?->name, 'paid_days' => (float) $p->paid_days, 'lop' => (float) $p->lop_days, 'ot_hours' => (float) $p->ot_hours,
                    'basic' => $by['BASIC'] ?? 0, 'allow' => round((float) $earn->whereNotIn('code', $std)->sum('amount'), 2), 'ot' => $by['OT'] ?? 0,
                    'bonus' => $by['BONUS'] ?? 0, 'gross' => (float) $p->gross,
                    'ssnit' => $by['SSNIT'] ?? 0, 'paye' => $by['PAYE'] ?? 0, 'bonus_tax' => $by['BONUS_TAX'] ?? 0, 'adv' => $by['ADV'] ?? 0,
                    'other_ded' => round((float) $ded->whereNotIn('code', $std)->sum('amount'), 2), 'net' => (float) $p->net, 'employer' => (float) $p->employer_cost];
            });
        $m = ['basic', 'allow', 'ot', 'bonus', 'gross', 'ssnit', 'paye', 'bonus_tax', 'adv', 'other_ded', 'net', 'employer'];

        return ['cols' => ['month' => 'Month', 'status' => 'Run', 'code' => 'Code', 'employee' => 'Employee', 'dept' => 'Department', 'paid_days' => 'Paid days', 'lop' => 'LOP', 'ot_hours' => 'OT h',
            'basic' => 'Basic', 'allow' => 'Allowances & other', 'ot' => 'Overtime', 'bonus' => 'Bonus / ex-gratia', 'gross' => 'Gross', 'ssnit' => 'SSNIT 5.5%', 'paye' => 'PAYE',
            'bonus_tax' => 'Tax on bonus',
            'adv' => 'Advance recovery', 'other_ded' => 'Other deductions', 'net' => 'Net pay', 'employer' => 'Employer contrib.'],
            'rows' => $rows, 'money' => $m, 'sum' => array_merge(['lop', 'ot_hours'], $m)];
    }

    protected function r_payroll_statutory($from, $to): array
    {
        $lines = \App\Models\PayslipLine::with('payslip.run')->whereIn('kind', ['deduction', 'employer'])->whereNull('staff_advance_id')->where('is_manual', false)
            ->whereHas('payslip.run', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->whereDate('period_start', '>=', $from->copy()->startOfMonth()->toDateString())->whereDate('period_start', '<=', $to->toDateString()))->get();
        $heads = \App\Models\AccountHead::pluck('name', 'id');
        $rows = $lines->groupBy(fn ($l) => $l->payslip->run->period_start->format('Y-m').'|'.$l->liability_head_id)->map(function ($g) use ($heads) {
            $run = $g->first()->payslip->run;
            $emp = (float) $g->where('kind', 'deduction')->sum('amount');
            $er = (float) $g->where('kind', 'employer')->sum('amount');

            return ['month' => $run->period_start->format('M Y'), 'sort' => $run->period_start->format('Ym'), 'liability' => $heads[$g->first()->liability_head_id] ?? '—',
                'components' => $g->pluck('code')->unique()->implode(', '), 'employees' => $g->pluck('payslip.employee_id')->unique()->count(),
                'employee_share' => round($emp, 2), 'employer_share' => round($er, 2), 'total' => round($emp + $er, 2), 'status' => ucfirst($run->status)];
        })->sortBy(fn ($r) => $r['sort'].$r['liability'])->values()->map(fn ($r) => \Illuminate\Support\Arr::except($r, 'sort'));

        return ['cols' => ['month' => 'Month', 'liability' => 'Payable to (account)', 'components' => 'Components', 'employees' => 'Employees', 'employee_share' => 'Deducted from staff',
            'employer_share' => 'Employer contribution', 'total' => 'Total due', 'status' => 'Run'],
            'rows' => $rows, 'money' => ['employee_share', 'employer_share', 'total'], 'sum' => ['employee_share', 'employer_share', 'total']];
    }

    protected function r_roster_vs_actual($from, $to): array
    {
        $f = $from->toDateString();
        $t = min($to->toDateString(), now()->toDateString());
        $shifts = \App\Models\RosterShift::with('template')->where('kind', 'shift')->whereDate('date', '>=', $f)->whereDate('date', '<=', $t)->get()->groupBy('employee_id');
        $punches = \App\Models\TimePunch::whereDate('work_date', '>=', $f)->whereDate('work_date', '<=', $t)->get()->groupBy('employee_id');
        $ids = $shifts->keys()->merge($punches->keys())->unique();
        $leave = \App\Services\RosterService::leaveMap($ids, $f, $t);
        $rows = \App\Models\Employee::with('department')->whereIn('id', $ids)->orderBy('department_id')->orderBy('name')->get()->map(function ($e) use ($shifts, $punches, $leave) {
            $ss = $shifts[$e->id] ?? collect();
            $ps = $punches[$e->id] ?? collect();
            $punchDays = $ps->pluck('work_date')->map(fn ($d) => $d->toDateString())->unique()->flip();
            $noShow = $ss->filter(fn ($s) => ! isset($punchDays[$s->date->toDateString()]) && ! isset($leave[$e->id][$s->date->toDateString()]) && $s->startsAt()->lt(now()))->count();
            $unrost = $ps->filter(fn ($p) => ! $p->roster_shift_id)->count();
            $planned = round($ss->sum(fn ($s) => $s->paidMinutes()) / 60, 2);
            $worked = round($ps->sum('worked_minutes') / 60, 2);

            return ['emp' => $e->name.' ('.$e->emp_code.')', 'dept' => $e->department?->name, 'shifts' => $ss->count(), 'planned' => $planned, 'days' => $punchDays->count(), 'worked' => $worked,
                'variance' => round($worked - $planned, 2), 'late' => $ps->where('late_minutes', '>', 0)->count() ?: null, 'late_min' => (int) $ps->sum('late_minutes') ?: null,
                'early' => $ps->where('early_minutes', '>', 0)->count() ?: null, 'no_show' => $noShow ?: null, 'unrostered' => $unrost ?: null,
                'edited' => $ps->whereNotNull('edited_by')->count() ?: null, 'open' => $ps->where('status', 'open')->count() ?: null,
                'punctual' => $ps->whereNotNull('roster_shift_id')->count() ? round($ps->whereNotNull('roster_shift_id')->where('late_minutes', 0)->count() / $ps->whereNotNull('roster_shift_id')->count() * 100, 1) : null];
        });

        return ['cols' => ['emp' => 'Employee', 'dept' => 'Department', 'shifts' => 'Shifts', 'planned' => 'Planned h', 'days' => 'Days worked', 'worked' => 'Worked h', 'variance' => 'Variance h',
            'late' => 'Late', 'late_min' => 'Late min', 'early' => 'Left early', 'no_show' => 'No-shows', 'unrostered' => 'Unrostered', 'edited' => 'Corrected', 'open' => 'Open', 'punctual' => 'On time %'],
            'rows' => $rows, 'sum' => ['shifts', 'planned', 'days', 'worked', 'variance', 'late', 'late_min', 'early', 'no_show', 'unrostered', 'edited', 'open'],
            'note' => 'Planned hours are rostered shift hours less the unpaid break; worked hours come from closed time punches (break deducted). A no-show is a rostered shift that has started with no punch and no approved leave. Future days are ignored.'];
    }

    protected function r_roster_weeks($from, $to): array
    {
        // Rewritten against the real schema: department_id/week_start/published_at/published_by/changed_at; there is no roster type.
        $rows = RosterWeek::with('department', 'publisher')->whereBetween('week_start', [$from, $to])->orderByDesc('week_start')->get()->map(fn ($r) => [
            'week_start' => $r->week_start, 'dept' => optional($r->department)->name,
            'published_at' => $r->published_at, 'published_by' => optional($r->publisher)->name,
            'state' => $r->state(),
        ]);
        return ['cols' => ['week_start' => 'Week Start', 'dept' => 'Dept', 'published_at' => 'Published', 'published_by' => 'Published By', 'state' => 'State'], 'rows' => $rows, 'note' => 'Roster week publication log.'];
    }

    protected function r_sc_distribution_lines($from, $to): array
    {
        $rows = ScDistributionLine::with('distribution.department.account')->whereBetween('distributed_at', [$from, $to])->get()->map(fn ($r) => [
            'distribution' => optional($r->distribution)->name, 'dept' => optional($r->department)->name,
            'account' => optional($r->account)->name, 'amount' => $r->amount,
            'period' => $r->period, 'status' => $r->status,
        ]);
        return ['cols' => ['distribution' => 'Distribution', 'dept' => 'Dept', 'account' => 'Account', 'amount' => 'Amount', 'period' => 'Period', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Service charge distribution line items.'];
    }

    protected function r_service_charge_distribution($from, $to): array
    {
        $rows = collect();
        foreach (\App\Models\ScDistribution::with('lines.employee', 'lines.department', 'run')->where('status', '!=', 'cancelled')->whereDate('period_start', '<=', $to->toDateString())->whereDate('period_end', '>=', $from->toDateString())->orderBy('period_start')->get() as $d) {
            foreach ($d->lines->sortBy(fn ($l) => [$l->department?->name, $l->employee?->name]) as $l) {
                $rows->push(['dist' => $d->dist_no, 'period' => $d->label(), 'emp' => $l->employee?->name.' ('.$l->employee?->emp_code.')', 'dept' => $l->department?->name,
                    'points' => (float) $l->points, 'days' => (float) $l->days, 'amount' => (float) $l->amount, 'note' => $l->excluded ? 'excluded: '.$l->exclude_reason : '',
                    'status' => $d->status, 'run' => $d->run?->run_no]);
            }
        }

        return ['cols' => ['dist' => 'Distribution', 'period' => 'Period', 'emp' => 'Employee', 'dept' => 'Department', 'points' => 'Points', 'days' => 'Days', 'amount' => 'Amount', 'note' => 'Note', 'status' => 'Status', 'run' => 'Payroll run'],
            'rows' => $rows, 'money' => ['amount'], 'sum' => ['amount'], 'note' => 'Shares are paid through payroll as a taxable earning. The house retention is posted to 4095 Service Charge Retained.'];
    }

    protected function r_shift_swaps($from, $to): array
    {
        $rows = ShiftSwap::with('shift1.employee1.shift2.employee2')->whereBetween('requested_at', [$from, $to])->get()->map(fn ($r) => [
            'employee1' => optional($r->employee1)->name, 'shift1' => optional($r->shift1)->name,
            'employee2' => optional($r->employee2)->name, 'shift2' => optional($r->shift2)->name,
            'requested' => $r->requested_at, 'status' => $r->status, 'notes' => $r->notes,
        ]);
        return ['cols' => ['employee1' => 'Employee 1', 'shift1' => 'Shift 1', 'employee2' => 'Employee 2', 'shift2' => 'Shift 2', 'requested' => 'Requested', 'status' => 'Status', 'notes' => 'Notes'], 'rows' => $rows, 'note' => 'Shift swap request register.'];
    }

    protected function r_shift_templates($from, $to): array
    {
        $rows = ShiftTemplate::with('department')->get()->map(fn ($r) => [
            'name' => $r->name, 'dept' => optional($r->department)->name, 'start' => $r->start_time,
            'end' => $r->end_time, 'break' => $r->break_minutes, 'hours' => $r->hours,
            'overtime_from' => $r->overtime_from, 'color' => $r->color, 'status' => $r->status,
        ]);
        return ['cols' => ['name' => 'Name', 'dept' => 'Department', 'start' => 'Start', 'end' => 'End', 'break' => 'Break (min)', 'hours' => 'Hours', 'overtime_from' => 'OT From'], 'rows' => $rows, 'note' => 'Shift template definitions with break and overtime rules.'];
    }

    protected function r_ssnit_schedule($from, $to): array
    {
        $rows = collect();
        foreach ($this->statMonths($from, $to) as $p) {
            $t1 = \App\Services\StatutoryService::build('ssnit', $p)['rows']->keyBy('employee_id');
            $t2 = \App\Services\StatutoryService::build('tier2', $p)['rows']->keyBy('employee_id');
            foreach ($t1->keys()->merge($t2->keys())->unique() as $id) {
                $a = $t1[$id] ?? null;
                $b = $t2[$id] ?? null;
                $x = $a ?? $b;
                $rows->push(['month' => Carbon::parse($p.'-01')->format('M Y'), 'code' => $x['emp_code'], 'name' => $x['name'], 'ssnit' => (string) $x['ssnit_no'], 'card' => (string) $x['ghana_card'],
                    'basic' => $x['basic'], 'insurable' => $x['insurable'], 't1_ee' => $a['ee'] ?? 0, 't1_er' => $a['er'] ?? 0, 't1' => $a['total'] ?? 0, 't2' => $b['total'] ?? 0,
                    'total' => round(($a['total'] ?? 0) + ($b['total'] ?? 0), 2)]);
            }
        }

        return ['cols' => ['month' => 'Month', 'code' => 'Emp. code', 'name' => 'Employee', 'ssnit' => 'SSNIT no.', 'card' => 'Ghana Card', 'basic' => 'Basic salary', 'insurable' => 'Insurable earnings',
            't1_ee' => 'Employee 5.5%', 't1_er' => 'Employer 8%', 't1' => 'Tier 1 (SSNIT)', 't2' => 'Tier 2 (5%)', 'total' => 'Total'],
            'rows' => $rows, 'money' => ['basic', 'insurable', 't1_ee', 't1_er', 't1', 't2', 'total'], 'sum' => ['basic', 'insurable', 't1_ee', 't1_er', 't1', 't2', 'total'],
            'note' => $rows->count().' contribution line(s). Tier 1 is paid to SSNIT and Tier 2 to the corporate trustee by the 14th of the following month (HR & Payroll › Statutory Returns).'];
    }

    protected function r_staff_advances($from, $to): array
    {
        $rows = StaffAdvance::with('employee')->whereBetween('advanced_at', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'date' => $r->advanced_at, 'amount' => $r->amount,
            'purpose' => $r->purpose, 'repaid' => $r->repaid_amount, 'balance' => $r->balance,
            'interest' => $r->interest_rate, 'due_date' => $r->due_date, 'status' => $r->status, 'user' => $r->user_id,
        ]);
        return ['cols' => ['employee' => 'Employee', 'date' => 'Date', 'amount' => 'Amount', 'purpose' => 'Purpose', 'repaid' => 'Repaid', 'balance' => 'Balance', 'interest' => 'Interest %', 'due_date' => 'Due', 'status' => 'Status'], 'rows' => $rows, 'money' => ['amount', 'repaid', 'balance'], 'sum' => ['amount', 'repaid', 'balance'], 'note' => 'Staff advance ledger with repayment tracking.'];
    }

    protected function r_statutory_remittances($from, $to): array
    {
        $rows = \App\Models\StatutoryRemittance::with('paymentMode', 'creator')->whereDate('paid_on', '>=', Carbon::parse($from)->toDateString())->whereDate('paid_on', '<=', Carbon::parse($to)->toDateString())
            ->orderBy('paid_on')->orderBy('id')->get()->map(function ($r) {
                $due = \App\Services\StatutoryService::due($r->kind, $r->period);
                $late = $r->paid_on->gt($due) ? (int) $due->diffInDays($r->paid_on) : 0;

                return ['paid' => $r->paid_on->format('d M Y'), 'no' => $r->remit_no, 'return' => \App\Services\StatutoryService::label($r->kind), 'month' => Carbon::parse($r->period.'-01')->format('M Y'),
                    'payee' => $r->payee, 'employees' => $r->employees, 'ee' => (float) $r->employee_share, 'er' => (float) $r->employer_share, 'amount' => $r->isCancelled() ? 0 : (float) $r->amount,
                    'due' => $due->format('d M Y'), 'late' => $late ?: '', 'ref' => $r->reference, 'mode' => $r->paymentMode?->name, 'status' => $r->isCancelled() ? 'Cancelled: '.$r->cancel_reason : ($late ? 'Late' : 'On time')];
            });

        return ['cols' => ['paid' => 'Paid on', 'no' => 'No.', 'return' => 'Return', 'month' => 'Payroll month', 'payee' => 'Paid to', 'employees' => 'Employees', 'ee' => 'Employee share', 'er' => 'Employer share',
            'amount' => 'Amount', 'due' => 'Due', 'late' => 'Days late', 'ref' => 'Reference', 'mode' => 'Paid from', 'status' => 'Status'],
            'rows' => $rows, 'money' => ['ee', 'er', 'amount'], 'sum' => ['amount'],
            'note' => $rows->count().' payment(s) · '.money($rows->sum('amount')).' remitted'.(($l = $rows->where('status', 'Late')->count()) ? " · {$l} paid late" : '').'.'];
    }

    protected function r_time_punches_detail($from, $to): array
    {
        $rows = TimePunch::with('employee')->whereBetween('punched_at', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'punch_in' => $r->punched_in, 'punch_out' => $r->punched_out,
            'hours' => $r->hours_worked, 'type' => $r->punch_type, 'location' => $r->location, 'device' => $r->device_id, 'status' => $r->status,
        ]);
        return ['cols' => ['employee' => 'Employee', 'punch_in' => 'Punch In', 'punch_out' => 'Punch Out', 'hours' => 'Hours', 'type' => 'Type', 'location' => 'Location', 'device' => 'Device', 'status' => 'Status'], 'rows' => $rows, 'note' => 'Detailed time punch register.'];
    }

    protected function r_timesheet($from, $to): array
    {
        $rows = \App\Models\TimePunch::with('employee.department', 'shift.template', 'editor', 'approver')->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->orderBy('work_date')->orderBy('clock_in')->get()->map(fn ($p) => ['date' => $p->work_date->format('d M Y'), 'emp' => $p->employee?->name.' ('.$p->employee?->emp_code.')', 'dept' => $p->employee?->department?->name,
                'shift' => $p->shift ? $p->shift->code().' '.$p->shift->label() : 'not rostered', 'in' => $p->clock_in->format('H:i'), 'out' => $p->clock_out ? $p->clock_out->format($p->clock_out->isSameDay($p->clock_in) ? 'H:i' : 'd M H:i') : 'open',
                'hours' => $p->clock_out ? round($p->worked_minutes / 60, 2) : null, 'late' => $p->late_minutes ?: null, 'early' => $p->early_minutes ?: null, 'source' => $p->in_source.($p->out_source && $p->out_source !== $p->in_source ? ' / '.$p->out_source : ''),
                'edited' => $p->edit_reason ? ($p->editor?->name.': '.$p->edit_reason) : '', 'status' => $p->status.($p->approver ? ' ('.$p->approver->name.')' : '')]);

        return ['cols' => ['date' => 'Date', 'emp' => 'Employee', 'dept' => 'Department', 'shift' => 'Rostered', 'in' => 'In', 'out' => 'Out', 'hours' => 'Hours', 'late' => 'Late min', 'early' => 'Early min', 'source' => 'Source', 'edited' => 'Correction', 'status' => 'Status'],
            'rows' => $rows, 'sum' => ['hours', 'late', 'early'], 'note' => 'Sources: self = self-service, kiosk = shared terminal with PIN, manager = entered or corrected by a supervisor.'];
    }

    protected function r_tip_collections($from, $to): array
    {
        $rows = TipCollection::with('employee')->whereBetween('collected_at', [$from, $to])->get()->map(fn ($r) => [
            'employee' => optional($r->employee)->name, 'date' => $r->collected_at, 'shift' => $r->shift_label,
            'cash' => $r->cash_amount, 'card' => $r->card_amount, 'total' => $r->total_amount, 'distributed' => $r->distributed_amount, 'status' => $r->status,
        ]);
        return ['cols' => ['employee' => 'Employee', 'date' => 'Date', 'shift' => 'Shift', 'cash' => 'Cash', 'card' => 'Card', 'total' => 'Total', 'distributed' => 'Distributed', 'status' => 'Status'], 'rows' => $rows, 'money' => ['cash_amount', 'card_amount', 'total_amount', 'distributed_amount'], 'sum' => ['cash_amount', 'card_amount', 'total_amount', 'distributed_amount'], 'note' => 'Tip collection register.'];
    }

}
