<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\AuditFinding;
use App\Models\BanquetEvent;
use App\Models\CarRental;
use App\Models\Driver;
use App\Models\BriefingSignoff;
use App\Models\ClubDayPass;
use App\Models\ClubTicketSale;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GatePass;
use App\Models\GlLine;
use App\Models\Guest;
use App\Models\GuestFeedback;
use App\Models\GuestRequest;
use App\Models\HousekeepingTask;
use App\Models\InterfaceCommand;
use App\Models\InventoryItem;
use App\Models\KotPrintJob;
use App\Models\LaundryOrder;
use App\Models\LeaveRequest;
use App\Models\LinenMovement;
use App\Models\LostFoundItem;
use App\Models\MaintenanceRequest;
use App\Models\OnlineReview;
use App\Models\ParkingSession;
use App\Models\PmSchedule;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomMove;
use App\Models\RoomOutage;
use App\Models\RosterShift;
use App\Models\SalesActivity;
use App\Models\SalesOpportunity;
use App\Models\SalesTarget;
use App\Models\SecurityIncident;
use App\Models\SupplierInvoice;
use App\Models\Trace;
use App\Models\TransportTrip;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIncident;
use App\Models\VehicleInspection;
use App\Models\VehicleMaintenanceRecord;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Voucher;
use App\Models\WakeupCall;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Daily briefings — one screen per department head, plus the consolidated pack the
 * General Manager and CEO read.
 *
 * Every figure is read from source transactions for a single business date, so a
 * briefing still adds up on a day whose night audit has not run. Departments are
 * declared once in PACKS: the key is the route segment, the permission is the same
 * area permission that already guards that department's own screens, and the
 * builder is the method named in `build`.
 *
 * Nothing here writes: the only write is signOff(), which records that a head has
 * read the pack and the headline figures they saw, so the GM can see who has
 * signed and what they signed against.
 */
class BriefingService
{
    /** Department packs. Order is the order of the briefing hub and the exec scorecard. */
    public const PACKS = [
        'frontoffice' => ['label' => 'Front Office', 'role' => 'Front Office Manager', 'perm' => 'frontdesk.view', 'icon' => 'bell', 'build' => 'buildFrontOffice',
            'brief' => 'Arrivals still to run and departures with the balance their folio still carries, the house tonight, cashiering at the desk and the guest requests the desk still owns.'],
        'housekeeping' => ['label' => 'Housekeeping', 'role' => 'Executive Housekeeper', 'perm' => 'housekeeping.view', 'icon' => 'broom', 'build' => 'buildHousekeeping',
            'brief' => 'Where the rooms stand, what the floor still has to turn, inspections, linen and lost property.'],
        'fnb' => ['label' => 'Food & Beverage', 'role' => 'F&B Manager', 'perm' => 'fnb.view', 'icon' => 'cup', 'build' => 'buildFnb',
            'brief' => 'Outlet sales and covers, how guests paid, leakage through voids, comps and discounts, and the open bills.'],
        'kitchen' => ['label' => 'Kitchen', 'role' => 'Executive Chef', 'perm' => 'pos.kitchen', 'icon' => 'coffee', 'build' => 'buildKitchen',
            'brief' => 'Tickets fired and cleared, what is still on the pass, dish times, what sold, the allergen lines a chef must verify, the cold chain and the kitchen checklists.'],
        'banquet' => ['label' => 'Banquet & Events', 'role' => 'Banquet Manager', 'perm' => 'banquet.view', 'icon' => 'layers', 'build' => 'buildBanquet',
            'brief' => 'Events on the floor today, the event orders still unpublished, the closed events not yet billed and what is chasing on deposits.'],
        'sales' => ['label' => 'Sales & Marketing', 'role' => 'Director of Sales & Marketing', 'perm' => 'sales.view', 'icon' => 'trend', 'build' => 'buildSales',
            'brief' => 'Pipeline by stage, the enquiries that have gone quiet, what was won and lost today with the reason recorded, and each seller against target.'],
        'revenue' => ['label' => 'Revenue', 'role' => 'Revenue Manager', 'perm' => 'revenue.view', 'icon' => 'chart', 'build' => 'buildRevenue',
            'brief' => 'The fortnight on the books, the mix against each base rate, the rate decisions waiting for a ruling and every discount or allowance given away today.'],
        'recreation' => ['label' => 'Spa & Recreation', 'role' => 'Recreation Manager', 'perm' => 'club.view', 'icon' => 'dumbbell', 'build' => 'buildRecreation',
            'brief' => 'Takings by tender and line by line, every sale on the till roll, the passes sold against the guests the gate actually admitted, the shift drawer with its float, expected cash and variance, and the tax split the way the return is filed.'],
        'laundry' => ['label' => 'Laundry & Linen', 'role' => 'Laundry Manager', 'perm' => 'laundry.view', 'icon' => 'shirt', 'build' => 'buildLaundry',
            'brief' => 'Orders taken and delivered, the wash still in hand, guest vs house linen and the money on the desk.'],
        'maintenance' => ['label' => 'Engineering', 'role' => 'Chief Engineer', 'perm' => 'maintenance.manage', 'icon' => 'tool', 'build' => 'buildMaintenance',
            'brief' => 'Jobs raised and closed, what is past its promise with the days late, the work already planned for the week and the rooms held out of the sale.'],
        'security' => ['label' => 'Security', 'role' => 'Security Manager', 'perm' => 'security.view', 'icon' => 'shield', 'build' => 'buildSecurity',
            'brief' => 'Visitors on the door and everyone still inside, gate passes out and back, incidents logged and every master key off the board.'],
        'hr' => ['label' => 'People & HR', 'role' => 'Human Resources Manager', 'perm' => 'hr.view', 'icon' => 'users', 'build' => 'buildHr',
            'brief' => 'Who is on against the roster, department by department, the leave queue, the contracts and probation running out, and the people decisions waiting.'],
        'finance' => ['label' => 'Finance & Accounts', 'role' => 'Financial Controller', 'perm' => 'accounts.view', 'icon' => 'wallet', 'build' => 'buildFinance',
            'brief' => 'Money taken and how, receipts issued, receivables ageing, payables due, the day\'s profit against budget and the audit exceptions open.'],
        'guestexp' => ['label' => 'Guest Experience', 'role' => 'Guest Relations Manager', 'perm' => 'feedback.view', 'icon' => 'star', 'build' => 'buildGuestExp',
            'brief' => 'Scores and net promoter, whether every review was answered, the recovery cases ranked by severity and what the guests actually wrote.'],
        'it' => ['label' => 'IT & Systems', 'role' => 'IT Manager', 'perm' => 'interfaces.view', 'icon' => 'wifi', 'build' => 'buildIt',
            'brief' => 'Every interface command that failed today and what came back, the endpoints not taking our updates, kitchen tickets that never printed and the night run.'],
        'stores' => ['label' => 'Purchasing & Stores', 'role' => 'Purchasing & Stores Manager', 'perm' => 'inventory.view', 'icon' => 'box', 'build' => 'buildStores',
            'brief' => 'What the stores issued, stock at re-order level with the quantity suggested, requisitions waiting at the counter and the deliveries that came in.'],
        'fleet' => ['label' => 'Transport & Fleet', 'role' => 'Transport Supervisor', 'perm' => 'transport.view', 'icon' => 'car', 'build' => 'buildFleet',
            'brief' => 'Trips run and still to run with the passengers on them, which car can legally move, the drivers and their licences, how each trip was billed and what is still to post, fuel against kilometres, the workshop and open defects, and the rentals out on the road.'],
        'parking' => ['label' => 'Parking & Valet', 'role' => 'Parking Supervisor', 'perm' => 'parking.view', 'icon' => 'truck', 'build' => 'buildParking',
            'brief' => 'Cars in the bays zone by zone, when they came in, valet calls answered, revenue collected and permits expiring.'],
    ];

    public const EXEC = ['label' => 'Executive brief', 'role' => 'General Manager & CEO', 'perm' => 'reports.exec', 'icon' => 'hotel'];

    private static array $coreCache = [];

    // ─────────────────────────────────────────────────────────────────────────────
    // Public entry points
    // ─────────────────────────────────────────────────────────────────────────────

    /** Department packs this user may open, in registry order, each with today's status. */
    public static function forUser(?User $user = null): Collection
    {
        $user ??= auth()->user();
        $today = bdate();
        $signs = self::signoffs($today);   // one query for every department
        $week = self::compliance($today);  // and one for the week behind it

        return collect(self::PACKS)->map(function ($p, $key) use ($user, $signs, $week) {
            $mine = $user?->hasPermission($p['perm']) ?? false;
            $sign = $signs[$key] ?? null;

            return [
                'key' => $key, 'label' => $p['label'], 'role' => $p['role'], 'perm' => $p['perm'],
                'icon' => $p['icon'], 'brief' => $p['brief'], 'can' => $mine,
                'signed' => $sign !== null, 'signed_by' => $sign?->user?->name, 'signed_at' => $sign?->signed_at?->format('H:i'),
                'note' => $sign?->note,
                'week_signed' => $week[$key]['signed'] ?? 0, 'week_days' => $week[$key]['days'] ?? 7,
            ];
        })->values();
    }

    public static function can(string $key, ?User $user = null): bool
    {
        $user ??= auth()->user();
        $perm = $key === 'exec' ? self::EXEC['perm'] : (self::PACKS[$key]['perm'] ?? null);
        if ($perm === null) {
            return false;
        }

        // The executive brief is a management view: whoever may read the whole
        // property's numbers for a day may read it, plus anyone granted the key.
        return $user?->hasPermission($perm) ?? false;
    }

    public static function pack(string $key, Carbon $date): array
    {
        if (! isset(self::PACKS[$key])) {
            throw new RuntimeException('Unknown briefing '.$key);
        }
        $p = self::PACKS[$key];
        $d = $date->copy()->startOfDay();
        $core = self::core($d);
        $build = $p['build'];
        $body = self::$build($d, $core);

        return array_merge([
            'key' => $key, 'label' => $p['label'], 'role' => $p['role'], 'icon' => $p['icon'],
            'perm' => $p['perm'], 'title' => $p['role'].' — daily briefing', 'date' => $d,
            'house' => self::houseSection($core),
            'signoff' => self::signoffFor($key, $d),
            'is_exec' => false,
        ], $body);
    }

    public static function exec(Carbon $date): array
    {
        $d = $date->copy()->startOfDay();
        $core = self::core($d);

        return array_merge([
            'key' => 'exec', 'label' => self::EXEC['label'], 'role' => self::EXEC['role'], 'icon' => self::EXEC['icon'],
            'perm' => self::EXEC['perm'], 'title' => 'Daily executive brief — General Manager & CEO', 'date' => $d,
            'is_exec' => true,
            // the print view always renders the house band first — same contract as the department packs
            'house' => self::houseSection($core),
        ], self::buildExec($d, $core));
    }

    /** Record that a head has read today's pack. Returns the sign-off row. */
    public static function signOff(string $key, Carbon $date, User $user, ?string $note, array $headline): BriefingSignoff
    {
        if (! isset(self::PACKS[$key])) {
            throw new RuntimeException('Unknown briefing '.$key);
        }
        $d = $date->copy()->startOfDay();
        if ($d->toDateString() !== bdate()->toDateString()) {
            throw new RuntimeException('Only today\'s briefing can be signed off.');
        }

        $row = BriefingSignoff::firstOrNew([
            'business_date' => $d->toDateString(),
            'department_key' => $key,
        ]);
        $row->user_id = $user->id;
        $row->note = $note;
        $row->headline = collect($headline)->map(fn ($h) => ['label' => $h['label'], 'value' => $h['value']])->all();
        $row->signed_at = now();
        $row->save();

        ActivityLogger::log('reports', 'briefing_signed',
            'Signed the '.self::PACKS[$key]['label'].' briefing for '.$d->toDateString().($note ? ' — '.$note : ''), $row);

        return $row;
    }

    public static function signoffFor(string $key, Carbon $date): ?BriefingSignoff
    {
        return BriefingSignoff::with('user')->where('department_key', $key)->whereDate('business_date', $date->toDateString())->first();
    }

    /** Sign-offs for every pack on a date, keyed by pack. */
    public static function signoffs(Carbon $date): Collection
    {
        return BriefingSignoff::with('user')->whereDate('business_date', $date->toDateString())->get()->keyBy('department_key');
    }

    /**
     * Sign-off compliance over a window ending on $date: how many of the last N business
     * dates each head signed, the last date they signed and the run they are on. One query
     * for the whole property, so the hub and the executive brief can both afford it.
     */
    public static function compliance(Carbon $date, int $days = 7): Collection
    {
        $end = $date->copy()->startOfDay();
        $start = $end->copy()->subDays($days - 1);
        $rows = BriefingSignoff::whereDate('business_date', '>=', $start->toDateString())
            ->whereDate('business_date', '<=', $end->toDateString())
            ->get(['department_key', 'business_date'])
            ->groupBy('department_key');

        return collect(self::PACKS)->map(function ($p, $key) use ($rows, $start, $end, $days) {
            $signed = ($rows[$key] ?? collect())->map(fn ($r) => $r->business_date->toDateString())->flip();
            $strip = collect(range(0, $days - 1))->map(function ($i) use ($start, $signed) {
                $d = $start->copy()->addDays($i);

                return ['label' => substr(self::dayName($d), 0, 3), 'date' => $d->toDateString(), 'signed' => $signed->has($d->toDateString())];
            });
            $streak = 0;
            for ($i = 0; $i < $days; $i++) {
                if (! $signed->has($end->copy()->subDays($i)->toDateString())) {
                    break;
                }
                $streak++;
            }
            $last = $signed->keys()->sort()->last();

            return ['key' => $key, 'label' => $p['label'], 'role' => $p['role'],
                'signed' => $signed->count(), 'days' => $days, 'streak' => $streak,
                'last' => $last ? Carbon::parse($last) : null, 'strip' => $strip->all()];
        });
    }

    /** The profit band: the same lines for the GM, the finance pack and the book. */
    private static function plSection(array $pl): array
    {
        return self::table('pl', 'Profit, day · month · year', 'Income and cost as the general ledger holds them, for '.$pl['fy'].' — the same classification the budget uses.',
            ['metric' => 'Line', 'day' => 'Today', 'mtd' => 'Month to date', 'ytd' => 'Fiscal year to date'],
            (function () use ($pl) {
                $row = fn ($label, $day, $mtd, $ytd, $total = false) => [
                    'metric' => $total ? ['text' => $label, 'tone' => 'slate'] : $label,
                    'day' => ['text' => money($day, false), 'tone' => $total ? ($day >= 0 ? 'green' : 'red') : 'slate'],
                    'mtd' => ['text' => money($mtd, false), 'tone' => $total ? ($mtd >= 0 ? 'green' : 'red') : 'slate'],
                    'ytd' => ['text' => money($ytd, false), 'tone' => $total ? ($ytd >= 0 ? 'green' : 'red') : 'slate'],
                ];
                $sec = fn ($s) => [$pl['day']['sections'][$s], $pl['mtd']['sections'][$s], $pl['ytd']['sections'][$s]];

                return [
                    $row('Operating revenue', ...$sec('revenue')),
                    $row('Cost of sales', ...$sec('direct')),
                    $row('Operating expenses', ...$sec('opex')),
                    $row('Gross operating profit', $pl['day']['gop'], $pl['mtd']['gop'], $pl['ytd']['gop'], true),
                    $row('Other income', ...$sec('other_income')),
                    $row('Non-operating expenses', ...$sec('nonop')),
                    $row('Net profit', $pl['day']['net'], $pl['mtd']['net'], $pl['ytd']['net'], true),
                    ['metric' => 'Net margin', 'day' => $pl['day']['margin'] === null ? '—' : self::pct($pl['day']['margin']),
                        'mtd' => $pl['mtd']['margin'] === null ? '—' : self::pct($pl['mtd']['margin']),
                        'ytd' => $pl['ytd']['margin'] === null ? '—' : self::pct($pl['ytd']['margin'])],
                ];
            })(),
            'Nothing has been posted to the general ledger for this date.', ['full' => true]);
    }

    /** The month against the approved budget, or an honest explanation when none is active. */
    private static function budgetSection(?array $budget, string $fy): array
    {
        return self::table('budget', 'Budget against actual, month to date', $budget
                ? 'Approved budget '.$budget['no'].' · '.$budget['name'].' · '.$budget['month'].'. Positive variance is favourable: revenue above budget, cost below it.'
                : 'No budget has been approved and activated for '.$fy.', so there is nothing to compare the month against.',
            ['section' => 'Section', 'budget' => 'Budget MTD', 'actual' => 'Actual MTD', 'variance' => 'Variance', 'pct' => 'Variance %'],
            $budget ? (function () use ($budget) {
                $costs = ['direct', 'opex', 'nonop'];
                $rows = [];
                $bNet = $aNet = 0.0;
                foreach (BudgetService::SECTIONS as $section => $label) {
                    $b = (float) $budget['mtd']['budget'][$section];
                    $a = (float) $budget['mtd']['actual'][$section];
                    $favourable = in_array($section, $costs, true) ? $b - $a : $a - $b;
                    $income = in_array($section, ['revenue', 'other_income'], true);
                    $bNet += $income ? $b : -$b;
                    $aNet += $income ? $a : -$a;
                    $rows[] = ['section' => $label, 'budget' => money($b, false), 'actual' => money($a, false),
                        'variance' => ['text' => ($favourable >= 0 ? '+' : '−').money(abs($favourable), false), 'tone' => abs($b) < 0.005 ? 'slate' : ($favourable >= 0 ? 'green' : 'red')],
                        'pct' => abs($b) < 0.005 ? '—' : ['text' => ($favourable >= 0 ? '+' : '−').self::pct(abs($favourable) * 100 / abs($b)), 'tone' => $favourable >= 0 ? 'green' : 'red']];
                }
                $favourable = $aNet - $bNet;
                $rows[] = ['section' => ['text' => 'Net profit', 'tone' => 'slate'],
                    'budget' => money($bNet, false), 'actual' => ['text' => money($aNet, false), 'tone' => $aNet >= 0 ? 'green' : 'red'],
                    'variance' => ['text' => ($favourable >= 0 ? '+' : '−').money(abs($favourable), false), 'tone' => $favourable >= 0 ? 'green' : 'red'],
                    'pct' => abs($bNet) < 0.005 ? '—' : ($favourable >= 0 ? '+' : '−').self::pct(abs($favourable) * 100 / abs($bNet))];

                return $rows;
            })() : [],
            'No approved budget is active for this fiscal year, so the month cannot be compared with one.', ['full' => true]);
    }

    /**
     * Profit and loss straight out of the general ledger, classified exactly the way the
     * budget is (BudgetService::SECTIONS), so the day's trading and the month's budget can
     * be read side by side. Fiscal, because that is the year the budget is written for.
     */
    private static function profit(Carbon $d): array
    {
        $fy = BudgetService::fyFor($d);
        $opening = BudgetService::months($fy)[1]->copy();
        $accounts = BudgetService::accounts(); // income + expense, each carrying ->section

        $sum = function (Carbon $from, Carbon $to) use ($accounts) {
            $rows = GlLine::whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->where('closing', false)
                ->whereIn('account_head_id', $accounts->pluck('id'))
                ->selectRaw('account_head_id, SUM(debit) dr, SUM(credit) cr')
                ->groupBy('account_head_id')->get()->keyBy('account_head_id');
            $out = array_fill_keys(array_keys(BudgetService::SECTIONS), 0.0);
            foreach ($accounts as $a) {
                $row = $rows[$a->id] ?? null;
                if (! $row) {
                    continue;
                }
                $out[$a->section] += $a->isDebitNature()
                    ? (float) $row->dr - (float) $row->cr
                    : (float) $row->cr - (float) $row->dr;
            }
            $gop = $out['revenue'] - $out['direct'] - $out['opex'];
            $net = $gop + $out['other_income'] - $out['nonop'];

            return ['sections' => array_map(fn ($v) => round($v, 2), $out),
                'gop' => round($gop, 2), 'net' => round($net, 2),
                'margin' => abs($out['revenue']) > 0.004 ? round($net * 100 / $out['revenue'], 1) : null];
        };

        return ['fy' => BudgetService::fyLabel($fy), 'opening' => $opening,
            'day' => $sum($d->copy()->startOfDay(), $d->copy()->startOfDay()),
            'mtd' => $sum($d->copy()->startOfMonth(), $d->copy()),
            'ytd' => $sum($opening, $d->copy())];
    }

    /**
     * The active approved budget for the fiscal year, against the ledger, month to date and
     * year to date. Null when no budget has been approved and activated — the brief then
     * says so rather than inventing a comparison.
     */
    private static function againstBudget(Carbon $d): ?array
    {
        $fy = BudgetService::fyFor($d);
        $budget = BudgetService::active($fy);
        if (! $budget) {
            return null;
        }
        $month = BudgetService::monthIndex($d);
        $accounts = BudgetService::accounts()->keyBy('id');
        $actual = BudgetService::actuals($fy, $d->toDateString());
        $zero = fn () => array_fill_keys(array_keys(BudgetService::SECTIONS), 0.0);
        [$bud, $act, $budY, $actY] = [$zero(), $zero(), $zero(), $zero()];

        foreach ($accounts as $id => $a) {
            for ($m = 1; $m <= $month; $m++) {
                $actY[$a->section] += (float) ($actual[$id][$m] ?? 0);
            }
            $act[$a->section] += (float) ($actual[$id][$month] ?? 0);
        }
        foreach ($budget->lines()->get() as $line) {
            $a = $accounts[$line->account_head_id] ?? null;
            if (! $a) {
                continue;
            }
            for ($m = 1; $m <= $month; $m++) {
                $budY[$a->section] += $line->month($m);
            }
            $bud[$a->section] += $line->month($month);
        }

        return ['no' => $budget->budget_no, 'name' => $budget->name, 'fy' => BudgetService::fyLabel($fy),
            'month' => BudgetService::monthLabels($fy)[$month] ?? ('Month '.$month),
            'mtd' => ['budget' => $bud, 'actual' => $act], 'ytd' => ['budget' => $budY, 'actual' => $actY]];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Shared core: the manager's flash figures for the day, last week and last month,
    // computed once per request and handed to every builder.
    // ─────────────────────────────────────────────────────────────────────────────

    private static function core(Carbon $date): array
    {
        $k = $date->toDateString();
        if (isset(self::$coreCache[$k])) {
            return self::$coreCache[$k];
        }
        [$m, $flashRows] = ManagementReportService::flash($date);
        [$lw] = ManagementReportService::flash($date->copy()->subWeek());
        [$pm] = ManagementReportService::flash($date->copy()->subMonthNoOverflow());

        $c = [
            'date' => $date,
            'day' => $m['day'], 'mtd' => $m['mtd'], 'ytd' => $m['ytd'],
            'lw' => $lw['day'], 'pm_day' => $pm['day'], 'pm_mtd' => $pm['mtd'],
            'flash_rows' => $flashRows,
        ];
        $c['inhouse'] = ReservationRoom::where('status', 'checked_in')->count();
        $c['inhouse_pax'] = (int) ReservationRoom::where('status', 'checked_in')->sum(DB::raw('adults + children'));
        $c['rooms_total'] = Room::where('is_active', true)->count();
        $c['ooo'] = Room::where('is_active', true)->whereIn('status', ['out_of_order', 'out_of_service'])->count();
        $c['dirty'] = Room::where('is_active', true)->whereIn('hk_status', ['dirty', 'touch_up'])->count();
        $c['clean'] = Room::where('is_active', true)->whereIn('hk_status', ['clean', 'inspected'])->count();
        $c['inhouse_balance'] = (float) FolioTransaction::where('is_void', false)->whereHas('folio', fn ($q) => $q->where('status', 'open'))
            ->selectRaw('SUM(CASE WHEN type IN ("charge","service_charge","tax") THEN amount ELSE -amount END) as bal')->value('bal');

        return self::$coreCache[$k] = $c;
    }

    /** The house in one band: every head needs the same context before their own numbers. */
    private static function houseSection(array $c): array
    {
        return self::stats('house', 'The house today', 'Room nights sold and revenue as posted on the business date.', [
            self::st('Occupancy', self::pct($c['day']['occ']), self::cmp($c['day']['occ'], $c['lw']['occ'], 'pct'), 'blue', 'bed'),
            self::st('Rooms sold', number_format($c['day']['sold']), 'of '.number_format($c['day']['available']).' available', 'slate', 'bed'),
            self::st('Guests in house', number_format($c['inhouse_pax']), number_format($c['inhouse']).' rooms occupied', 'purple', 'users'),
            self::st('Room revenue', money($c['day']['room']), self::cmp($c['day']['room'], $c['lw']['room'], 'money'), 'green', 'money'),
            self::st('Arrivals / departures', number_format($c['day']['arrivals']).' / '.number_format($c['day']['departures']), $c['day']['noshow'].' no-show, '.$c['day']['cancel'].' cancelled', 'amber', 'swap'),
            self::st('F&B revenue', money($c['day']['fnb']), self::cmp($c['day']['fnb'], $c['lw']['fnb'], 'money'), 'teal', 'cup'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Shape helpers — every builder returns the same skeleton so one view renders all.
    // ─────────────────────────────────────────────────────────────────────────────

    private static function st(string $label, string $value, ?string $sub = null, string $tone = 'slate', string $icon = 'grid'): array
    {
        return ['label' => $label, 'value' => $value, 'sub' => $sub, 'tone' => $tone, 'icon' => $icon];
    }

    private static function stats(string $key, string $title, ?string $note, array $stats, ?string $empty = null): array
    {
        return ['key' => $key, 'title' => $title, 'note' => $note, 'kind' => 'stats', 'stats' => $stats, 'empty' => $empty];
    }

    /**
     * @param  array<string,string>  $cols  key => heading, kept in the order the table reads.
     *                                      Stored as a list of ['key'=>…, 'label'=>…] so the view,
     *                                      the CSV and any consumer walk exactly the same shape.
     */
    private static function table(string $key, string $title, ?string $note, array $cols, array $rows, string $empty, array $opts = []): array
    {
        $headings = [];
        foreach ($cols as $column => $label) {
            $headings[] = ['key' => $column, 'label' => $label];
        }

        // Every cell is { text, tone } — a plain string becomes a cell with no tone, and a
        // [text, tone] pair becomes a badge. One shape means the view, the print sheet and
        // the CSV never have to ask what kind of value they are looking at.
        $tidy = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_keys($cols) as $column) {
                $cell = $row[$column] ?? '—';
                if (is_array($cell)) {
                    $cells[$column] = ['text' => (string) ($cell['text'] ?? '—'), 'tone' => $cell['tone'] ?? null];
                } else {
                    $cells[$column] = ['text' => (string) $cell, 'tone' => null];
                }
            }
            $tidy[] = $cells;
        }

        return array_merge(['key' => $key, 'title' => $title, 'note' => $note, 'kind' => 'table',
            'cols' => $headings, 'rows' => $tidy, 'empty' => $empty], $opts);
    }

    private static function items(string $key, string $title, ?string $note, array $items, string $empty): array
    {
        return ['key' => $key, 'title' => $title, 'note' => $note, 'kind' => 'list', 'items' => array_values($items), 'empty' => $empty];
    }

    /** A list entry: text, an optional right-hand value and a tone. */
    private static function li(string $text, ?string $value = null, string $tone = 'slate'): array
    {
        return ['text' => $text, 'value' => $value, 'tone' => $tone];
    }

    private static function pct($v, int $dec = 1): string
    {
        return number_format((float) $v, $dec).' %';
    }

    private static function pctp($v): string
    {
        return number_format((float) $v, 1).' pts';
    }

    private static function cmp($now, $prev, string $fmt = 'num', bool $lowerIsBetter = false): ?string
    {
        if ($prev === null || (float) $prev == 0.0) {
            return null;
        }
        $now = (float) $now;
        $prev = (float) $prev;
        $diff = $now - $prev;
        if (abs($diff) < 0.005) {
            return 'level with last week';
        }
        $sign = $diff > 0 ? '+' : '−';
        $arrow = ($diff > 0) xor $lowerIsBetter ? '▲' : '▼';

        return match ($fmt) {
            'money' => $arrow.' '.money(abs($diff), false),
            'pct' => $arrow.' '.number_format(abs($diff), 1).' pts',
            'int' => $arrow.' '.number_format(abs($diff)),
            default => $arrow.' '.number_format(abs($diff), 1),
        }.' vs last week';
    }

    /** Badge tone for a comparison string produced by cmp(). */
    private static function tone($cmp, bool $lowerIsBetter = false): string
    {
        if (! $cmp || str_starts_with($cmp, 'level')) {
            return 'slate';
        }
        $up = str_starts_with($cmp, '▲');

        return ($up xor $lowerIsBetter) ? 'green' : 'red';
    }

    /** Group an amount by the payment mode it came in on. */
    private static function byMode(Collection $payments, string $amountKey = 'amount'): Collection
    {
        return $payments->groupBy(fn ($p) => $p->paymentMode?->name ?? 'Unspecified')
            ->map(fn ($g, $n) => ['mode' => $n, 'count' => $g->count(), 'amount' => (float) $g->sum($amountKey)])
            ->sortByDesc('amount')->values();
    }

    private static function dayName(Carbon $d): string
    {
        return $d->format('D j M');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Front Office
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildFrontOffice(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $arrDue = ReservationRoom::whereDate('arrival_date', $ds)->where('status', 'reserved')->count();
        $arrIn = ReservationRoom::whereDate('arrival_date', $ds)->where('status', 'checked_in')->count();
        $arrUnroomed = ReservationRoom::whereDate('arrival_date', $ds)->where('status', 'reserved')->whereNull('room_id')->count();
        $arrOverdue = ReservationRoom::whereDate('arrival_date', '<', $ds)->where('status', 'reserved')->count();
        $depDue = ReservationRoom::whereDate('departure_date', $ds)->whereIn('status', ['reserved', 'checked_in'])->count();
        $depOut = ReservationRoom::whereDate('departure_date', $ds)->where('status', 'checked_out')->count();
        $lateOut = ReservationRoom::whereDate('departure_date', $ds)->where('status', 'checked_in')->whereNotNull('late_checkout_until')->count();
        $moves = RoomMove::whereDate('created_at', $ds)->count();

        $payments = FolioTransaction::with('paymentMode')->where('is_void', false)->whereDate('business_date', $ds)
            ->where('type', 'payment')->get(['payment_mode_id', 'amount']);
        $refunds = (float) FolioTransaction::where('is_void', false)->whereDate('business_date', $ds)->where('type', 'refund')->sum('amount');
        $cityLedger = (float) FolioTransaction::where('is_void', false)->whereDate('business_date', $ds)->where('category', 'city_ledger')->sum('amount');
        $cashiered = (float) $payments->sum('amount') - $refunds;

        $openReq = GuestRequest::whereIn('status', GuestRequest::OPEN)->count();
        $overdueReq = GuestRequest::whereIn('status', GuestRequest::OPEN)->whereNotNull('preferred_at')->where('preferred_at', '<', now())->count();
        $tracesDue = Trace::whereDate('trace_date', $ds)->where('status', 'open')->count();
        $wakeups = WakeupCall::with([])->whereDate('call_at', $ds)->get(['status']);
        $wakeMissed = $wakeups->whereIn('status', ['no_answer', 'missed', 'failed'])->count();

        $vipStays = ReservationRoom::with(['room', 'reservation.guest'])
            ->where('status', 'checked_in')->whereHas('reservation.guest', fn ($q) => $q->where('is_vip', true))->get();
        $vipArrivals = ReservationRoom::with(['room', 'reservation.guest'])->whereDate('arrival_date', $ds)->where('status', 'reserved')
            ->whereHas('reservation.guest', fn ($q) => $q->where('is_vip', true))->get();

        $arriving = ReservationRoom::with(['room', 'reservation.guest'])->whereDate('arrival_date', $ds)->where('status', 'reserved')
            ->orderBy('id')->take(8)->get(['id', 'room_id', 'guest_id', 'arrival_date', 'departure_date', 'rate']);
        $departing = ReservationRoom::with(['room', 'reservation.guest'])->whereDate('departure_date', $ds)
            ->whereIn('status', ['checked_in', 'checked_out'])->take(8)
            ->get(['id', 'room_id', 'guest_id', 'arrival_date', 'departure_date', 'status', 'late_checkout_until']);
        $folioBalance = Folio::with('activeTransactions')->whereIn('reservation_room_id', $departing->pluck('id'))->get(['id', 'reservation_room_id'])
            ->groupBy('reservation_room_id')->map(fn ($g) => $g->sum(fn ($f) => $f->balance()));
        $guestName = fn ($s) => trim(($s->reservation?->guest?->first_name ?? '').' '.($s->reservation?->guest?->last_name ?? ''));
        $modes = self::byMode($payments);

        return [
            'headline' => [
                self::st('In house', number_format($c['inhouse']).' rooms', number_format($c['inhouse_pax']).' guests', 'blue', 'bed'),
                self::st('Arrivals', number_format($arrIn).' of '.number_format($arrDue + $arrIn), $arrUnroomed.' still to be roomed', $arrUnroomed ? 'amber' : 'green', 'login'),
                self::st('Departures', number_format($depOut).' of '.number_format($depDue + $depOut), $lateOut.' late check-out agreed', 'purple', 'logout'),
                self::st('Cashiered at the desk', money($cashiered), money($refunds).' refunded', 'green', 'wallet'),
                self::st('No-shows', number_format($c['day']['noshow']), $c['day']['cancel'].' cancellations today', $c['day']['noshow'] ? 'amber' : 'slate', 'alert'),
                self::st('Open guest requests', number_format($openReq), $overdueReq.' past their promised time', $overdueReq ? 'red' : 'slate', 'message'),
            ],
            'sections' => [
                self::stats('movement', 'Movement across the desk', 'Stays as they stand on the business date.', [
                    self::st('Arrivals expected', number_format($arrDue), $arrOverdue.' overdue from earlier days', $arrOverdue ? 'amber' : 'slate', 'login'),
                    self::st('Arrived', number_format($arrIn), 'checked in today', 'green', 'check'),
                    self::st('Departures due', number_format($depDue), 'still in house', 'amber', 'logout'),
                    self::st('Checked out', number_format($depOut), 'folio closed', 'green', 'check'),
                    self::st('Room moves', number_format($moves), 'recorded today', 'slate', 'swap'),
                    self::st('Rooms occupied', number_format($c['inhouse']).' / '.number_format($c['rooms_total']), $c['ooo'].' out of order', 'blue', 'bed'),
                ]),
                self::table('arriving', 'Arrivals still to run', 'The rest of today\'s arrivals, and whether a room is waiting for them.',
                    ['guest' => 'Guest', 'room' => 'Room', 'nights' => 'Nights', 'rate' => 'Rate'],
                    $arriving->map(fn ($s) => ['guest' => $guestName($s),
                        'room' => $s->room?->room_no ? ['text' => $s->room->room_no, 'tone' => 'green'] : ['text' => 'Not roomed', 'tone' => 'amber'],
                        'nights' => number_format($s->arrival_date->diffInDays($s->departure_date)),
                        'rate' => money((float) $s->rate, false)])->all(),
                    'No arrival is still to run for this date.'),
                self::table('departing', 'Departures to settle', 'Guests leaving today and where their folio stands before they go.',
                    ['guest' => 'Guest', 'room' => 'Room', 'balance' => 'Balance', 'state' => 'At the desk'],
                    $departing->map(function ($s) use ($folioBalance, $guestName) {
                        $balance = (float) ($folioBalance[$s->id] ?? 0);

                        return ['guest' => $guestName($s), 'room' => $s->room?->room_no ?? '—',
                            'balance' => ['text' => money(abs($balance), false).($balance < -0.004 ? ' credit' : ''), 'tone' => $balance > 0.004 ? 'red' : 'green'],
                            'state' => $s->status === 'checked_out' ? 'Checked out' : ($s->late_checkout_until ? 'Late check-out' : 'In house')];
                    })->all(),
                    'No guest is due to leave on this date.'),
                self::table('cashier', 'Cashiered at the desk', 'Folio payments posted on the business date, by the mode the guest paid with.',
                    ['mode' => 'Payment mode', 'bills' => 'Receipts', 'amount' => 'Amount'],
                    $modes->map(fn ($m) => ['mode' => $m['mode'], 'bills' => number_format($m['count']), 'amount' => money($m['amount'])])->all(),
                    'Nothing has been received at the desk on this date.'),
                self::items('desk', 'Work the desk still owns', 'Open items anywhere in the property that the front office has to close.',
                    array_values(array_filter([
                        $openReq ? self::li(number_format($openReq).' guest requests open', $overdueReq ? $overdueReq.' overdue' : 'within promise', $overdueReq ? 'red' : 'amber') : null,
                        $tracesDue ? self::li(number_format($tracesDue).' traces due today', 'not yet done', 'amber') : null,
                        $wakeMissed ? self::li(number_format($wakeMissed).' wake-up calls not answered', 're-attempt and log the guest', 'red') : null,
                        $arrOverdue ? self::li(number_format($arrOverdue).' arrivals still not checked in from earlier dates', 'call the guest', 'red') : null,
                        $arrUnroomed ? self::li(number_format($arrUnroomed).' of today\'s arrivals have no room assigned', 'assign before the guest reaches the desk', 'amber') : null,
                        $lateOut ? self::li(number_format($lateOut).' late check-outs agreed', 'housekeeping has been told', 'slate') : null,
                    ])),
                    'The desk has nothing open.'),
                self::table('vip', 'Guests to look after personally', 'VIPs staying tonight and VIPs still to arrive.',
                    ['guest' => 'Guest', 'room' => 'Room', 'when' => 'Stay', 'flag' => 'Status'],
                    $vipStays->map(fn ($s) => ['guest' => trim(($s->reservation?->guest?->first_name ?? '').' '.($s->reservation?->guest?->last_name ?? '')),
                        'room' => $s->room?->room_no ?? '—', 'when' => $s->arrival_date?->format('d M').' → '.$s->departure_date?->format('d M'),
                        'flag' => ['text' => 'In house', 'tone' => 'green']])
                        ->concat($vipArrivals->map(fn ($s) => ['guest' => trim(($s->reservation?->guest?->first_name ?? '').' '.($s->reservation?->guest?->last_name ?? '')),
                            'room' => $s->room?->room_no ?? '—', 'when' => $s->arrival_date?->format('d M').' → '.$s->departure_date?->format('d M'),
                            'flag' => ['text' => 'Arriving', 'tone' => 'blue']]))->take(8)->all(),
                    'No VIP on the books for this date.'),
            ],
            'watch' => array_values(array_filter([
                $overdueReq ? number_format($overdueReq).' guest requests are past the time the guest was promised' : null,
                $arrOverdue ? number_format($arrOverdue).' arrivals from earlier dates have still not checked in' : null,
                $c['dirty'] ? number_format($c['dirty']).' rooms are still dirty while the desk is selling rooms' : null,
                $wakeMissed ? number_format($wakeMissed).' wake-up calls went unanswered' : null,
            ])),
            'todo' => array_values(array_filter([
                $arrUnroomed ? 'Room the '.$arrUnroomed.' arrivals without a room number.' : null,
                $cityLedger > 0 ? 'City-ledger postings today: '.money($cityLedger).' — confirm each is authorised.' : null,
                'Settle the cashier float and hand over to the next shift.',
            ])),
            'sources' => 'reservation_rooms, folios, folio_transactions, guest_requests, traces, wakeup_calls, room_moves',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Housekeeping
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildHousekeeping(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $tasks = HousekeepingTask::whereDate('task_date', $ds)->get(['task_type', 'status', 'credits', 'inspection_status', 'room_id', 'dnd_at', 'reclean_count', 'started_at', 'completed_at']);
        $byType = $tasks->groupBy('task_type')->map(fn ($g, $t) => [
            'type' => HousekeepingTask::TYPES[$t] ?? $t,
            'due' => $g->count(),
            'done' => $g->where('status', 'completed')->count(),
            'open' => $g->whereIn('status', ['pending', 'in_progress'])->count(),
            'credits' => (float) $g->sum('credits'),
        ])->sortByDesc('due')->values();

        $awaitInspection = $tasks->filter(fn ($t) => $t->status === 'completed' && $t->room_id && $t->inspection_status === null && $t->task_type !== 'inspection')->count();
        $inspected = $tasks->where('inspection_status', 'passed')->count();
        $failed = $tasks->where('inspection_status', 'failed')->count();
        $dnd = $tasks->whereNotNull('dnd_at')->count();
        $recleans = $tasks->sum('reclean_count');
        $credits = (float) $tasks->sum('credits');
        $minutes = $tasks->filter(fn ($t) => $t->started_at && $t->completed_at)->avg(fn ($t) => $t->started_at->diffInMinutes($t->completed_at));

        $linen = LinenMovement::whereDate('date', $ds)->get(['type', 'qty']);
        $linenRows = $linen->groupBy('type')->map(fn ($g, $t) => ['type' => LinenMovement::TYPES[$t] ?? $t, 'moves' => $g->count(), 'qty' => (float) $g->sum('qty')])
            ->sortByDesc('qty')->values();

        $found = LostFoundItem::whereDate('found_date', $ds)->count();
        $inStore = LostFoundItem::where('status', 'stored')->count();
        $valuable = LostFoundItem::where('status', 'stored')->where('valuable', true)->count();

        return [
            'headline' => [
                self::st('Rooms ready', number_format($c['clean']), 'clean or inspected of '.number_format($c['rooms_total']), 'green', 'bed'),
                self::st('Still to turn', number_format($c['dirty']), 'dirty or touch-up', $c['dirty'] ? 'amber' : 'green', 'broom'),
                self::st('Tasks closed', number_format($tasks->where('status', 'completed')->count()).' / '.number_format($tasks->count()), $inspected.' inspected clean', 'blue', 'check'),
                self::st('Awaiting inspection', number_format($awaitInspection), $failed.' failed inspection today', $awaitInspection || $failed ? 'amber' : 'slate', 'eye'),
                self::st('Out of order', number_format($c['ooo']), 'rooms withdrawn from sale', $c['ooo'] ? 'red' : 'slate', 'alert'),
                self::st('Credits earned', number_format($credits, 1), $minutes ? number_format($minutes).' min average per room' : 'no timed tasks yet', 'purple', 'star'),
            ],
            'sections' => [
                self::table('types', 'The floor by task type', 'Every task raised for the business date, and how much of it is closed.',
                    ['type' => 'Task', 'due' => 'Raised', 'done' => 'Closed', 'open' => 'Open', 'credits' => 'Credits'],
                    $byType->map(fn ($t) => ['type' => $t['type'], 'due' => number_format($t['due']), 'done' => number_format($t['done']),
                        'open' => ['text' => number_format($t['open']), 'tone' => $t['open'] ? 'amber' : 'green'], 'credits' => number_format($t['credits'], 1)])->all(),
                    'No housekeeping task has been raised for this date.'),
                self::table('linen', 'Linen moved', 'Par stock in and out of the floors and the laundry on the business date.',
                    ['type' => 'Movement', 'moves' => 'Entries', 'qty' => 'Pieces'],
                    $linenRows->map(fn ($l) => ['type' => $l['type'], 'moves' => number_format($l['moves']), 'qty' => number_format($l['qty'])])->all(),
                    'No linen movement has been recorded on this date.'),
                self::items('watch', 'What the housekeeper watches', 'The exceptions that decide whether the block can be sold tonight.',
                    array_values(array_filter([
                        $dnd ? self::li(number_format($dnd).' rooms on do-not-disturb', 'leave until the guest clears it', 'amber') : null,
                        $recleans ? self::li(number_format($recleans).' re-cleans', 'room failed inspection after cleaning', 'amber') : null,
                        $failed ? self::li(number_format($failed).' inspections failed today', 'coach and re-inspect', 'red') : null,
                        $found ? self::li(number_format($found).' lost-property items found today', 'log and store', 'slate') : null,
                        $inStore ? self::li(number_format($inStore).' items still in lost property', $valuable.' of them valuable', 'slate') : null,
                        $c['ooo'] ? self::li(number_format($c['ooo']).' rooms out of order', 'engineering and housekeeping to release', 'red') : null,
                    ])),
                    'Nothing flagged for the housekeeper.'),
            ],
            'watch' => array_values(array_filter([
                $c['dirty'] ? number_format($c['dirty']).' rooms are not ready for sale' : null,
                $awaitInspection ? number_format($awaitInspection).' cleaned rooms are waiting for an inspection' : null,
                $failed ? number_format($failed).' rooms failed inspection today' : null,
            ])),
            'todo' => array_values(array_filter([
                $awaitInspection ? 'Inspect the '.$awaitInspection.' rooms waiting, then release them.' : null,
                'Confirm the linen par stock for tomorrow\'s arrivals.',
                'Hand the dirty-room list to the evening shift with the departures sheet.',
            ])),
            'sources' => 'rooms, housekeeping_tasks, linen_movements, lost_found_items, room_outages',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Food & Beverage
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildFnb(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $orders = PosOrder::with('outlet')->whereDate('business_date', $ds)
            ->get(['outlet_id', 'status', 'settlement', 'covers', 'subtotal', 'discount', 'service_charge', 'tax', 'total', 'void_reason']);
        $settled = $orders->where('status', 'settled');
        $voids = $orders->where('status', 'void');
        $open = $orders->whereIn('status', ['open', 'billed']);

        $byOutlet = $settled->groupBy(fn ($o) => $o->outlet?->name ?? 'Unassigned outlet')->map(fn ($g, $name) => [
            'outlet' => $name,
            'bills' => $g->count(),
            'covers' => (int) $g->sum('covers'),
            'gross' => (float) $g->sum('subtotal'),
            'discount' => (float) $g->sum('discount'),
            'service' => (float) $g->sum('service_charge'),
            'net' => (float) $g->sum('total'),
            'avg' => $g->sum('covers') ? (float) $g->sum('total') / $g->sum('covers') : 0,
        ])->sortByDesc('net')->values();

        $items = PosOrderItem::where('is_cancelled', false)
            ->whereHas('order', fn ($q) => $q->where('status', 'settled')->whereDate('business_date', $ds))
            ->get(['item_name', 'qty', 'amount']);
        $top = $items->groupBy('item_name')->map(fn ($g, $n) => ['item' => $n, 'qty' => (float) $g->sum('qty'), 'amount' => (float) $g->sum('amount')])
            ->sortByDesc('amount')->take(8)->values();

        $payments = PosPayment::with('paymentMode')->where('kind', 'payment')->whereDate('business_date', $ds)->get(['payment_mode_id', 'amount']);
        $modes = self::byMode($payments);

        $cancelledItems = PosOrderItem::where('is_cancelled', true)
            ->whereHas('order', fn ($q) => $q->whereDate('business_date', $ds))
            ->get(['item_name', 'qty', 'amount']);
        $comps = (float) $settled->where('settlement', 'complimentary')->sum('total');
        $discount = (float) $settled->sum('discount');
        $staffMeals = \App\Models\FbStaffMeal::whereDate('business_date', $ds)->get(['covers', 'total']);
        $covers = (int) $settled->sum('covers');
        $net = (float) $settled->sum('total');

        return [
            'headline' => [
                self::st('Net sales', money($net), self::cmp($net, $c['lw']['fnb'], 'money'), 'green', 'money'),
                self::st('Covers', number_format($covers), number_format($settled->count()).' bills', 'blue', 'users'),
                self::st('Average cover', money($covers ? $net / $covers : 0), 'net of service and tax', 'purple', 'receipt'),
                self::st('Taken directly', money((float) $settled->where('settlement', 'payment')->sum('total')), 'cash, card, MoMo at the till', 'teal', 'wallet'),
                self::st('Charged to rooms', money((float) $settled->where('settlement', 'room_post')->sum('total')), 'posted to guest folios', 'slate', 'bed'),
                self::st('Leakage', money($discount + $comps), number_format($voids->count()).' bills voided', ($discount + $comps) > 0 ? 'amber' : 'green', 'alert'),
            ],
            'sections' => [
                self::table('outlets', 'By outlet', 'Settled bills on the business date, net of discount and including service charge and tax.',
                    ['outlet' => 'Outlet', 'bills' => 'Bills', 'covers' => 'Covers', 'gross' => 'Gross', 'discount' => 'Discount', 'service' => 'Service', 'net' => 'Net', 'avg' => 'Avg / cover'],
                    $byOutlet->map(fn ($o) => ['outlet' => $o['outlet'], 'bills' => number_format($o['bills']), 'covers' => number_format($o['covers']),
                        'gross' => money($o['gross'], false), 'discount' => money($o['discount'], false), 'service' => money($o['service'], false),
                        'net' => money($o['net'], false), 'avg' => money($o['avg'], false)])->all(),
                    'No outlet bill has been settled on this date.'),
                self::table('tender', 'How guests paid', 'Payments taken at the outlets. Room charges are collected through the folio instead.',
                    ['mode' => 'Mode', 'count' => 'Payments', 'amount' => 'Amount'],
                    $modes->map(fn ($m) => ['mode' => $m['mode'], 'count' => number_format($m['count']), 'amount' => money($m['amount'], false)])->all(),
                    'No direct payment was taken at the outlets on this date.'),
                self::table('top', 'What sold', 'The dishes and drinks that carried the day, by value.',
                    ['item' => 'Item', 'qty' => 'Qty', 'amount' => 'Value'],
                    $top->map(fn ($t) => ['item' => $t['item'], 'qty' => number_format($t['qty'], 2), 'amount' => money($t['amount'], false)])->all(),
                    'No item was sold on this date.'),
                self::items('leakage', 'Leakage and open bills', 'Where margin goes and what is still unpaid at the outlets.',
                    array_values(array_filter([
                        $voids->count() ? self::li(number_format($voids->count()).' bills voided', money((float) $voids->sum('total')), 'red') : null,
                        $cancelledItems->count() ? self::li(number_format($cancelledItems->sum('qty')).' items cancelled after ordering', money((float) $cancelledItems->sum('amount')), 'amber') : null,
                        $comps > 0 ? self::li('Complimentary bills', money($comps), 'amber') : null,
                        $discount > 0 ? self::li('Discounts given', money($discount), 'amber') : null,
                        $open->count() ? self::li(number_format($open->count()).' bills still open', money((float) $open->sum('total')), 'red') : null,
                        $staffMeals->count() ? self::li('Staff meals', number_format($staffMeals->sum('covers')).' covers · '.money((float) $staffMeals->sum('total')), 'slate') : null,
                    ])),
                    'No leakage recorded and no bill is open.'),
            ],
            'watch' => array_values(array_filter([
                $open->count() ? number_format($open->count()).' outlet bills are still open at '.money((float) $open->sum('total')) : null,
                $voids->count() ? number_format($voids->count()).' bills were voided — check the reasons before close' : null,
                $discount > 0 && $net > 0 && $discount / $net > 0.05 ? 'Discounts are above 5 % of net sales' : null,
            ])),
            'todo' => array_values(array_filter([
                $open->count() ? 'Close or post the '.$open->count().' open bills.' : null,
                'Check the void sheets against the till before the shift closes.',
                'Reconcile the direct takings to the cashier shift drawer.',
            ])),
            'sources' => 'pos_orders, pos_order_items, pos_payments, fb_staff_meals, outlets',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Kitchen
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildKitchen(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $items = PosOrderItem::with('order')
            ->whereHas('order', fn ($q) => $q->whereDate('business_date', $ds)->whereIn('status', ['billed', 'settled']))
            ->get(['pos_order_id', 'item_name', 'qty', 'amount', 'kitchen_status', 'is_cancelled', 'allergy_flag', 'fired_at', 'ready_at', 'served_at']);

        $live = $items->where('is_cancelled', false);
        $timed = $live->filter(fn ($i) => $i->fired_at && $i->ready_at);
        $avgDish = $timed->count() ? $timed->avg(fn ($i) => $i->fired_at->diffInMinutes($i->ready_at)) : null;
        $stillCooking = $live->whereIn('kitchen_status', ['new', 'held', 'preparing'])->count();
        $cancelled = $items->where('is_cancelled', true);
        $allergens = $live->where('allergy_flag', true)->count();
        $verified = $live->where('allergy_flag', true)->whereNotNull('allergens_verified_by')->count();

        $kots = KotPrintJob::whereDate('created_at', $ds)->get(['status', 'kot_no']);
        $printFails = $kots->whereIn('status', ['failed', 'skipped'])->count();

        $top = $live->groupBy('item_name')->map(fn ($g, $n) => ['item' => $n, 'qty' => (float) $g->sum('qty'), 'amount' => (float) $g->sum('amount')])
            ->sortByDesc('qty')->take(8)->values();

        $temps = \App\Models\FsTempLog::whereDate('recorded_at', $ds)->get(['reading_c', 'in_range', 'kind', 'item', 'min_c', 'max_c', 'recorded_at']);
        $unverified = $live->where('allergy_flag', true)->filter(fn ($i) => empty($i->allergens_verified_by))->take(8);
        $onPass = $live->whereIn('kitchen_status', ['new', 'held', 'preparing'])->filter(fn ($i) => $i->fired_at)
            ->sortBy('fired_at')->take(8);
        $outOfRange = $temps->where('in_range', false)->count();
        $checklists = \App\Models\FsChecklistRun::whereDate('completed_at', $ds)->get(['failed', 'completed_at']);

        return [
            'headline' => [
                self::st('Tickets fired', number_format($kots->count()), $printFails ? $printFails.' failed to print' : 'all printed', $printFails ? 'red' : 'green', 'print'),
                self::st('Dishes served', number_format((float) $live->sum('qty')), number_format($live->count()).' lines', 'blue', 'cup'),
                self::st('Average dish time', $avgDish ? number_format($avgDish).' min' : '—', 'fired to ready', ($avgDish ?? 0) > 25 ? 'amber' : 'green', 'clock'),
                self::st('Still on the pass', number_format($stillCooking), 'lines not yet ready', $stillCooking ? 'amber' : 'green', 'coffee'),
                self::st('Allergen lines', number_format($allergens), $verified.' verified by the chef', $allergens > $verified ? 'red' : 'slate', 'alert'),
                self::st('Temperature checks', number_format($temps->count()), $outOfRange.' out of range', $outOfRange ? 'red' : 'green', 'thermo'),
            ],
            'sections' => [
                self::table('dishes', 'What the pass sent out', 'The busiest lines of the day.',
                    ['item' => 'Dish', 'qty' => 'Qty', 'amount' => 'Value'],
                    $top->map(fn ($t) => ['item' => $t['item'], 'qty' => number_format($t['qty'], 2), 'amount' => money($t['amount'], false)])->all(),
                    'Nothing has been sent from the pass on this date.'),
                self::table('pass', 'Still on the pass', 'Lines fired and not yet ready, longest wait first.',
                    ['order' => 'Order', 'item' => 'Dish', 'qty' => 'Qty', 'fired' => 'Fired', 'wait' => 'Waiting'],
                    $onPass->map(fn ($i) => ['order' => '#'.$i->pos_order_id, 'item' => $i->item_name, 'qty' => number_format((float) $i->qty, 2),
                        'fired' => $i->fired_at->format('H:i'),
                        'wait' => ['text' => number_format($i->fired_at->diffInMinutes(now())).' min', 'tone' => $i->fired_at->diffInMinutes(now()) > 20 ? 'red' : 'amber']])->all(),
                    'Nothing is waiting on the pass.'),
                self::table('allergens', 'Allergen lines to verify', 'A chef signs every line that carries an allergen flag before it leaves the pass.',
                    ['order' => 'Order', 'item' => 'Dish', 'qty' => 'Qty', 'state' => 'State'],
                    $unverified->map(fn ($i) => ['order' => '#'.$i->pos_order_id, 'item' => $i->item_name, 'qty' => number_format((float) $i->qty, 2),
                        'state' => ['text' => 'Not verified', 'tone' => 'red']])->all(),
                    'Every allergen line has been verified by a chef.'),
                self::table('temps', 'Readings outside the safe range', 'Cold rooms and hot holding that failed their check today.',
                    ['point' => 'Reading point', 'reading' => 'Reading', 'range' => 'Safe range', 'at' => 'Taken'],
                    $temps->where('in_range', false)->map(fn ($t) => ['point' => $t->item ?: ucfirst((string) $t->kind),
                        'reading' => ['text' => number_format((float) $t->reading_c, 1).' °C', 'tone' => 'red'],
                        'range' => number_format((float) $t->min_c, 1).' – '.number_format((float) $t->max_c, 1).' °C',
                        'at' => $t->recorded_at?->format('H:i') ?? '—'])->values()->all(),
                    'Every reading taken today was inside its safe range.'),
                self::table('prints', 'KOT printing', 'Kitchen tickets and how the printers handled them.',
                    ['status' => 'Printer status', 'count' => 'Tickets'],
                    $kots->groupBy('status')->map(fn ($g, $s) => ['status' => ucfirst($s), 'count' => number_format($g->count())])->values()->all(),
                    'No kitchen ticket has been printed on this date.'),
                self::items('checks', 'Food safety and the pass', 'What the kitchen has to answer for today.',
                    array_values(array_filter([
                        $outOfRange ? self::li(number_format($outOfRange).' cold-room or hot-holding readings out of range', 'corrective action needed', 'red') : null,
                        $temps->count() ? self::li(number_format($temps->count()).' temperature readings taken', $temps->count().' logged', 'green') : self::li('No temperature reading logged today', 'required before service', 'red'),
                        $checklists->count() ? self::li(number_format($checklists->count()).' checklists completed', $checklists->where('failed', '>', 0)->count().' with failures', $checklists->where('failed', '>', 0)->count() ? 'amber' : 'green') : null,
                        $allergens > $verified ? self::li(number_format($allergens - $verified).' allergen lines not verified', 'a chef must check every one', 'red') : null,
                        $cancelled->count() ? self::li(number_format($cancelled->count()).' lines cancelled after firing', money((float) $cancelled->sum('amount')), 'amber') : null,
                    ])),
                    'Nothing open for the kitchen.'),
            ],
            'watch' => array_values(array_filter([
                $printFails ? number_format($printFails).' kitchen tickets failed to print — dishes may be missed' : null,
                $outOfRange ? number_format($outOfRange).' temperature readings are outside the safe range' : null,
                $allergens > $verified ? number_format($allergens - $verified).' allergen lines have not been verified' : null,
            ])),
            'todo' => array_values(array_filter([
                $allergens > $verified ? 'Verify the open allergen lines before the next service.' : null,
                'Reconcile the dishes sent against the menu mix at the end of service.',
                'Close the kitchen checklist and log the waste.',
            ])),
            'sources' => 'pos_order_items, pos_orders, kot_print_jobs, fs_temp_logs, fs_checklist_runs',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Banquet & Events
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildBanquet(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        // the day's floor reads down the clock: two events at the same hour keep the order they were keyed in (v10.35)
        $events = BanquetEvent::with(['hall', 'company'])->whereDate('event_date', $ds)->orderBy('start_time')->orderBy('id')->get();
        $live = $events->whereNotIn('status', ['cancelled', 'lost']);
        $value = $live->sum(fn ($e) => $e->total());

        $week = BanquetEvent::whereDate('event_date', '>', $ds)->whereDate('event_date', '<=', $d->copy()->addDays(7)->toDateString())
            ->whereNotIn('status', ['cancelled', 'lost'])->get(['expected_pax', 'guaranteed_pax', 'rate_per_pax', 'hall_charge', 'other_charges', 'status', 'event_date']);

        $pendingBills = BanquetEvent::with(['hall', 'company'])->whereNotIn('status', ['cancelled', 'lost'])
            ->whereDate('event_date', '<=', $ds)->whereNull('billed_at')->whereNotNull('closed_at')->get();

        $depositsDue = $live->filter(fn ($e) => $e->deposit_due_date && $e->deposit_due_date->lte($d) && $e->depositsReceived() <= 0);
        $beoMissing = $live->whereNull('beo_published_at')->count();
        $pax = (int) $live->sum(fn ($e) => $e->pax());
        $unpublished = $live->filter(fn ($e) => ! $e->beo_published_at)->sortBy(fn ($e) => $e->start_time.' · '.str_pad((string) $e->id, 10, '0', STR_PAD_LEFT));

        return [
            'headline' => [
                self::st('Events today', number_format($live->count()), $events->count() - $live->count().' cancelled', 'blue', 'layers'),
                self::st('Guests expected', number_format($pax), 'across the function space', 'purple', 'users'),
                self::st('Value on the floor', money($value), 'net of tax', 'green', 'money'),
                self::st('Deposits outstanding', number_format($depositsDue->count()), money($depositsDue->sum(fn ($e) => (float) $e->advance)), $depositsDue->count() ? 'amber' : 'green', 'wallet'),
                self::st('BEO not published', number_format($beoMissing), 'event orders still with the planner', $beoMissing ? 'amber' : 'green', 'book'),
                self::st('Next 7 days', number_format($week->count()).' events', number_format((int) $week->sum('expected_pax')).' guests expected', 'teal', 'calendar'),
            ],
            'sections' => [
                self::table('today', 'On the floor today', 'Every event on the business date, its space, the covers and who it is for.',
                    ['event' => 'Event', 'title' => 'What it is', 'hall' => 'Space', 'pax' => 'Covers', 'value' => 'Value', 'status' => 'Status'],
                    $events->map(fn ($e) => ['event' => $e->event_no, 'title' => $e->title, 'hall' => $e->hall?->name ?? '—',
                        'pax' => number_format($e->pax()), 'value' => money($e->total(), false),
                        'status' => ['text' => BanquetEvent::STATUSES[$e->status] ?? ucfirst($e->status), 'tone' => $e->status === 'definite' ? 'green' : ($e->status === 'cancelled' ? 'red' : 'amber')]])->all(),
                    'No event is scheduled for this date.'),
                self::table('beos', 'Event orders still to publish', 'Nothing reaches the kitchen or the floor until the order is published.',
                    ['event' => 'Event', 'title' => 'What it is', 'hall' => 'Space', 'starts' => 'Starts', 'pax' => 'Covers'],
                    $unpublished->map(fn ($e) => ['event' => $e->event_no, 'title' => $e->title, 'hall' => $e->hall?->name ?? '—',
                        'starts' => ['text' => $e->start_time ? Carbon::parse($e->start_time)->format('H:i') : '—', 'tone' => 'amber'],
                        'pax' => number_format($e->pax())])->values()->all(),
                    'Every event order on the floor is published.'),
                self::table('unbilled', 'Finished, not yet billed', 'Events that have closed and left the building with the bill still to raise.',
                    ['event' => 'Event', 'client' => 'Client', 'closed' => 'Closed', 'pax' => 'Guaranteed', 'value' => 'Value'],
                    $pendingBills->map(fn ($e) => ['event' => $e->event_no, 'client' => $e->company?->name ?? $e->contact_name ?? '—',
                        'closed' => $e->closed_at?->format('d M') ?? '—',
                        'pax' => number_format((int) ($e->guaranteed_pax ?: $e->expected_pax)),
                        'value' => ['text' => money($e->total(), false), 'tone' => 'amber']])->values()->all(),
                    'Every closed event has been billed.'),
                self::items('settle', 'Money and paperwork', 'What the banquet manager has to close today.',
                    array_values(array_filter([
                        $depositsDue->count() ? self::li(number_format($depositsDue->count()).' events past their deposit date with nothing received', 'chase the client today', 'red') : null,
                        $beoMissing ? self::li(number_format($beoMissing).' event orders not yet published', 'kitchen and service are working blind', 'amber') : null,
                        $pendingBills->count() ? self::li(number_format($pendingBills->count()).' finished events not yet billed', money($pendingBills->sum(fn ($e) => $e->total())), 'amber') : null,
                        $live->where('status', 'tentative')->count() ? self::li(number_format($live->where('status', 'tentative')->count()).' tentative events today', 'confirm or release the space', 'amber') : null,
                    ])),
                    'Nothing outstanding on the floor.'),
                self::table('week', 'The week ahead', 'Events already contracted, so the kitchen and the floor can plan.',
                    ['when' => 'Date', 'count' => 'Events', 'pax' => 'Guests'],
                    $week->groupBy(fn ($e) => $e->event_date->toDateString())->map(fn ($g, $day) => ['when' => Carbon::parse($day)->format('D j M'),
                        'count' => number_format($g->count()), 'pax' => number_format((int) $g->sum('expected_pax'))])->values()->all(),
                    'No event is booked in the next seven days.'),
            ],
            'watch' => array_values(array_filter([
                $depositsDue->count() ? number_format($depositsDue->count()).' events are past their deposit date' : null,
                $pendingBills->count() ? number_format($pendingBills->count()).' closed events are still unbilled' : null,
            ])),
            'todo' => array_values(array_filter([
                $beoMissing ? 'Publish the '.$beoMissing.' missing event order(s).' : null,
                'Walk the floor with the chef before the first event sits down.',
                'Bill the events that closed, against the guaranteed covers.',
            ])),
            'sources' => 'banquet_events, banquet_halls, banquet_event_items, companies',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Sales & Marketing
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildSales(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $open = SalesOpportunity::with(['company', 'owner'])->whereIn('stage', SalesOpportunity::OPEN)->get();
        $pipeline = (float) $open->sum(fn ($o) => (float) $o->rooms_revenue + (float) $o->fb_revenue + (float) $o->other_revenue);
        $wonToday = SalesOpportunity::with('company')->whereDate('closed_on', $ds)->where('stage', 'won')->get();
        $lostToday = SalesOpportunity::with('company')->whereDate('closed_on', $ds)->where('stage', 'lost')->get();

        $byStage = $open->groupBy('stage')->map(fn ($g, $s) => ['stage' => SalesOpportunity::STAGES[$s][0] ?? $s,
            'count' => $g->count(), 'value' => (float) $g->sum(fn ($o) => (float) $o->rooms_revenue + (float) $o->fb_revenue + (float) $o->other_revenue),
            'nights' => (int) $g->sum('room_nights')])->values();

        $activities = SalesActivity::with(['owner', 'company'])->whereDate('due_at', $ds)->orderBy('due_at')->get();
        $overdue = SalesActivity::with(['owner', 'company'])->where('status', 'planned')->whereDate('due_at', '<', $ds)->get();

        $targets = SalesTarget::with('user')->where('period', $d->format('Y-m'))->get();

        $stale = $open->filter(fn ($o) => $o->stage_changed_at && $o->stage_changed_at->lt($d->copy()->subDays(14)))
            ->sortBy('stage_changed_at')->take(8);
        $pickup = Reservation::whereDate('created_at', $ds)->whereNotIn('status', ['cancelled'])->get(['id', 'adults', 'children']);
        $arrivalsTonight = ReservationRoom::whereDate('arrival_date', $ds)->where('status', 'reserved')->count();

        return [
            'headline' => [
                self::st('Open pipeline', money($pipeline), number_format($open->count()).' live enquiries', 'blue', 'trend'),
                self::st('Won today', number_format($wonToday->count()), money($wonToday->sum(fn ($o) => (float) $o->rooms_revenue + (float) $o->fb_revenue + (float) $o->other_revenue)), 'green', 'check'),
                self::st('Lost today', number_format($lostToday->count()), 'record the reason on each', $lostToday->count() ? 'red' : 'slate', 'x'),
                self::st('Bookings created', number_format($pickup->count()), $arrivalsTonight.' arrivals still expected', 'purple', 'calendar'),
                self::st('Activities due today', number_format($activities->count()), $overdue->count().' overdue', $overdue->count() ? 'red' : 'green', 'phone'),
                self::st('Targets live', number_format($targets->count()), $targets->count().' sellers on this month', 'teal', 'users'),
            ],
            'sections' => [
                self::table('pipeline', 'The pipeline by stage', 'Live enquiries and what they are worth if they convert.',
                    ['stage' => 'Stage', 'count' => 'Enquiries', 'nights' => 'Room nights', 'value' => 'Value'],
                    $byStage->map(fn ($s) => ['stage' => $s['stage'], 'count' => number_format($s['count']), 'nights' => number_format($s['nights']), 'value' => money($s['value'], false)])->all(),
                    'Nothing in the pipeline.'),
                self::table('agenda', 'Today\'s calls and visits', 'What the team said it would do today.',
                    ['when' => 'Time', 'subject' => 'Subject', 'who' => 'Account', 'owner' => 'Owner', 'status' => 'Status'],
                    $activities->map(fn ($a) => ['when' => $a->due_at?->format('H:i') ?? '—', 'subject' => $a->subject,
                        'who' => $a->company?->name ?? '—', 'owner' => $a->owner?->name ?? '—',
                        'status' => ['text' => $a->statusLabel(), 'tone' => $a->status === 'done' ? 'green' : ($a->isOverdue() ? 'red' : 'amber')]])->all(),
                    'No selling activity is scheduled for today.'),
                self::table('targets', 'What each seller carries this month', 'Targets set for the period and the activity behind them.',
                    ['seller' => 'Seller', 'nights' => 'Room nights', 'revenue' => 'Revenue', 'activities' => 'Activities'],
                    $targets->map(fn ($t) => ['seller' => $t->user?->name ?? '—', 'nights' => number_format((int) $t->room_nights),
                        'revenue' => money((float) $t->revenue, false), 'activities' => number_format((int) $t->activities)])->all(),
                    'No target has been set for this month.'),
                self::table('quiet', 'Enquiries that have gone quiet', 'Still open, but nothing has moved on them for a fortnight.',
                    ['number' => 'Enquiry', 'account' => 'Account', 'stage' => 'Stage', 'value' => 'Value', 'moved' => 'Last moved'],
                    $stale->map(fn ($o) => ['number' => $o->number, 'account' => $o->company?->name ?? $o->name,
                        'stage' => SalesOpportunity::STAGES[$o->stage][0] ?? $o->stage,
                        'value' => money((float) $o->rooms_revenue + (float) $o->fb_revenue + (float) $o->other_revenue, false),
                        'moved' => ['text' => $o->stage_changed_at->diffInDays($d).' d ago', 'tone' => 'amber']])->values()->all(),
                    'Nothing has been left sitting.'),
                self::table('won', 'What was won today', 'The enquiries that turned into business on the business date.',
                    ['number' => 'Enquiry', 'account' => 'Account', 'nights' => 'Room nights', 'value' => 'Value', 'owner' => 'Owner'],
                    $wonToday->map(fn ($o) => ['number' => $o->number, 'account' => $o->company?->name ?? $o->name,
                        'nights' => number_format((int) $o->room_nights),
                        'value' => ['text' => money((float) $o->rooms_revenue + (float) $o->fb_revenue + (float) $o->other_revenue, false), 'tone' => 'green'],
                        'owner' => $o->owner?->name ?? '—'])->values()->all(),
                    'Nothing was won on this date.'),
                self::table('lost_detail', 'What was lost today, and why', 'Each enquiry lost, with the reason the team recorded.',
                    ['number' => 'Enquiry', 'account' => 'Account', 'value' => 'Value', 'reason' => 'Reason'],
                    $lostToday->map(fn ($o) => ['number' => $o->number, 'account' => $o->company?->name ?? $o->name,
                        'value' => money((float) $o->rooms_revenue + (float) $o->fb_revenue + (float) $o->other_revenue, false),
                        'reason' => $o->lost_reason
                            ? ['text' => $o->lost_reason, 'tone' => 'amber']
                            : ['text' => 'Not recorded', 'tone' => 'red']])->values()->all(),
                    'Nothing was lost on this date.'),
                self::items('lost', 'Behind the numbers', 'Enquiries slipping and the reasons being recorded.',
                    array_values(array_filter([
                        $overdue->count() ? self::li(number_format($overdue->count()).' activities are overdue', 'oldest '.($overdue->min('due_at')?->format('d M') ?? '—'), 'red') : null,
                        $lostToday->count() ? self::li(number_format($lostToday->count()).' lost today', $lostToday->pluck('lost_reason')->filter()->unique()->join(', ') ?: 'no reason recorded', 'amber') : null,
                        $open->where('probability', '<', 25)->count() ? self::li(number_format($open->where('probability', '<', 25)->count()).' enquiries below 25 % confidence', 'qualify or close them', 'amber') : null,
                    ])),
                    'Nothing behind the numbers today.'),
            ],
            'watch' => array_values(array_filter([
                $overdue->count() ? number_format($overdue->count()).' sales activities are overdue' : null,
                $lostToday->whereNull('lost_reason')->count() ? number_format($lostToday->whereNull('lost_reason')->count()).' enquiries were lost without a reason' : null,
            ])),
            'todo' => [
                'Work the overdue activities first.',
                'Update every enquiry that moved stage today.',
                'Hand the week\'s arrivals list to the front office.',
            ],
            'sources' => 'sales_opportunities, sales_activities, sales_targets, reservations, companies, users',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Revenue
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildRevenue(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $stays = ReservationRoom::whereIn('status', ['reserved', 'checked_in'])
            ->whereDate('arrival_date', '<=', $d->copy()->addDays(29)->toDateString())
            ->whereDate('departure_date', '>', $ds)->get(['arrival_date', 'departure_date', 'rate', 'room_type_id']);

        $night = function (Carbon $day) use ($stays) {
            $in = $stays->filter(fn ($s) => $s->arrival_date->lte($day) && $s->departure_date->gt($day));

            return ['rooms' => $in->count(), 'revenue' => (float) $in->sum('rate')];
        };

        $sellable = max(1, (int) ($c['rooms_total'] - $c['ooo']));
        $rows = [];
        foreach (range(0, 13) as $i) {
            $day = $d->copy()->addDays($i);
            $n = $night($day);
            $rows[] = ['day' => $i === 0 ? 'Tonight' : $day->format('D j M'), 'rooms' => number_format($n['rooms']),
                'occ' => ['text' => self::pct($n['rooms'] * 100 / $sellable), 'tone' => $n['rooms'] * 100 / $sellable >= 80 ? 'green' : ($n['rooms'] * 100 / $sellable >= 50 ? 'amber' : 'red')],
                'adr' => $n['rooms'] ? money($n['revenue'] / $n['rooms'], false) : '—',
                'revpar' => money($n['revenue'] / $sellable, false)];
        }

        $tonight = $night($d);
        $tomorrow = $night($d->copy()->addDay());
        $sevenNightAvg = collect(range(1, 7))->avg(fn ($i) => $night($d->copy()->addDay($i))['rooms']) * 100 / $sellable;

        $typeMix = $stays->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))
            ->groupBy('room_type_id')->map(fn ($g) => ['rooms' => $g->count(), 'revenue' => (float) $g->sum('rate')]);
        $types = \App\Models\RoomType::whereIn('id', $typeMix->keys())->get(['id', 'name', 'base_rate'])->keyBy('id');

        $pendingAuth = \App\Models\RevenueAuthorisation::with('requester:id,name')->where('status', 'pending')
            ->orderBy('date_from')->take(8)->get(['auth_no', 'kind', 'date_from', 'date_to', 'amount', 'reason', 'requested_by']);
        $given = FolioTransaction::where('is_void', false)->whereDate('business_date', $ds)
            ->whereIn('type', ['discount', 'allowance'])->get(['type', 'description', 'category', 'amount']);
        $sources = Reservation::with('businessSource')->whereDate('arrival_date', $ds)->whereNotIn('status', ['cancelled'])
            ->get(['business_source_id'])->groupBy(fn ($r) => $r->businessSource?->name ?? 'Direct / not recorded')
            ->map(fn ($g, $n) => ['source' => $n, 'bookings' => $g->count()])->sortByDesc('bookings')->values();

        return [
            'headline' => [
                self::st('Tonight', self::pct($tonight['rooms'] * 100 / $sellable), number_format($tonight['rooms']).' of '.number_format($sellable).' sellable', 'blue', 'bed'),
                self::st('ADR on the books', money($tonight['rooms'] ? $tonight['revenue'] / $tonight['rooms'] : 0), 'tonight, before extras', 'green', 'money'),
                self::st('RevPAR on the books', money($tonight['revenue'] / $sellable), 'per sellable room tonight', 'teal', 'chart'),
                self::st('Tomorrow', self::pct($tomorrow['rooms'] * 100 / $sellable), number_format($tomorrow['rooms']).' rooms held', 'purple', 'calendar'),
                self::st('Next 7 nights', self::pct($sevenNightAvg), 'average occupancy on the books', 'amber', 'trend'),
                self::st('Arrivals booked today', number_format(Reservation::whereDate('created_at', $ds)->count()), 'new bookings on the business date', 'slate', 'plus'),
            ],
            'sections' => [
                self::table('pace', 'The next fortnight', 'What is on the books tonight and every night after it.',
                    ['day' => 'Night', 'rooms' => 'Rooms', 'occ' => 'Occupancy', 'adr' => 'ADR', 'revpar' => 'RevPAR'],
                    $rows, 'No room is on the books.'),
                self::table('mix', 'Who is in the house tonight', 'Room types sold tonight against the published base rate.',
                    ['type' => 'Room type', 'rooms' => 'Rooms', 'adr' => 'ADR', 'base' => 'Base rate', 'position' => 'Position'],
                    $typeMix->map(function ($m, $id) use ($types) {
                        $t = $types[$id] ?? null;
                        $adr = $m['rooms'] ? $m['revenue'] / $m['rooms'] : 0;
                        $base = (float) ($t?->base_rate ?? 0);

                        return ['type' => $t?->name ?? '—', 'rooms' => number_format($m['rooms']), 'adr' => money($adr, false),
                            'base' => $base ? money($base, false) : '—',
                            'position' => $base ? ['text' => number_format(($adr / $base - 1) * 100, 1).' %', 'tone' => $adr >= $base ? 'green' : 'red'] : ['text' => '—', 'tone' => 'slate']];
                    })->values()->all(),
                    'Nothing is sold tonight.'),
                self::table('auth', 'Rate decisions waiting', 'Discounts, comps and house use the revenue manager has to decide on.',
                    ['auth' => 'Request', 'kind' => 'Kind', 'dates' => 'Dates', 'amount' => 'Value', 'asked' => 'Asked by'],
                    $pendingAuth->map(fn ($a) => ['auth' => $a->auth_no,
                        'kind' => \App\Models\RevenueAuthorisation::KINDS[$a->kind] ?? ucfirst((string) $a->kind),
                        'dates' => ($a->date_from?->format('d M') ?? '—').($a->date_to && $a->date_to->ne($a->date_from) ? ' → '.$a->date_to->format('d M') : ''),
                        'amount' => ['text' => money((float) $a->amount, false), 'tone' => 'amber'],
                        'asked' => $a->requester?->name ?? ($a->reason ?: '—')])->all(),
                    'Nothing is waiting for a revenue decision.'),
                self::table('leakage', 'What was given away today', 'Every discount and allowance posted on the business date, with what it was for.',
                    ['type' => 'Type', 'for' => 'What for', 'area' => 'Area', 'amount' => 'Value'],
                    $given->map(fn ($g) => ['type' => ucfirst((string) $g->type), 'for' => $g->description ?: '—',
                        'area' => $g->category ? ucfirst(str_replace('_', ' ', (string) $g->category)) : '—',
                        'amount' => ['text' => money((float) $g->amount, false), 'tone' => 'amber']])->take(8)->values()->all(),
                    'Nothing was discounted or allowed on this date.'),
                self::table('source', 'Where tonight came from', 'Business source on the reservations arriving today.',
                    ['source' => 'Source', 'bookings' => 'Bookings'],
                    $sources->map(fn ($s) => ['source' => $s['source'], 'bookings' => number_format($s['bookings'])])->all(),
                    'No reservation arrives today.'),
                self::items('actions', 'Revenue actions', 'What the revenue manager does with this position.',
                    array_values(array_filter([
                        $tonight['rooms'] * 100 / $sellable < 60 ? self::li('Tonight is below 60 % — release or discount the remaining rooms', 'check the rate floors first', 'amber') : null,
                        $tomorrow['rooms'] * 100 / $sellable < 50 ? self::li('Tomorrow is below 50 %', 'open the lower rate plans for the day', 'amber') : null,
                        self::li('Review the price against the channel for the next weekend', 'rate parity', 'slate'),
                    ])),
                    'No action is needed.'),
            ],
            'watch' => array_values(array_filter([
                $tonight['rooms'] * 100 / $sellable < 50 ? 'Tonight is under 50 % occupied with '.number_format($sellable - $tonight['rooms']).' rooms unsold' : null,
                $tomorrow['rooms'] * 100 / $sellable < 50 ? 'Tomorrow is under 50 % occupied' : null,
            ])),
            'todo' => [
                'Check the rate position on every channel before the day sells out.',
                'Watch the pickup against last week before releasing distressed inventory.',
                'Send the weekend forecast to the F&B and housekeeping managers.',
            ],
            'sources' => 'reservation_rooms, reservations, room_types, rooms, revenue_authorisations, folio_transactions',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Spa & Recreation
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildRecreation(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        // the outlet's own day report, so the briefing and the close-of-shift screen agree
        $r = RecreationShiftService::dayReport($ds);
        $adm = $r['admissions'];

        $tenderRows = collect($r['tenders'])->map(fn ($t, $n) => ['mode' => $n, 'count' => number_format($t['count']), 'amount' => money($t['amount'], false),
            'cash' => $t['is_cash'] ? ['text' => 'Cash', 'tone' => 'green'] : ['text' => 'Other', 'tone' => 'slate']])->values();
        $typeRows = collect($r['by_type'])->map(fn ($t, $n) => ['item' => $n, 'qty' => number_format($t['qty']), 'net' => money($t['net'], false),
            'tax' => money($t['tax'], false), 'total' => money($t['total'], false)])->values();
        $categoryRows = collect($r['by_category'])->map(fn ($t, $n) => ['category' => ucfirst($n), 'qty' => number_format($t['qty']), 'net' => money($t['net'], false), 'tax' => money($t['tax'], false)])->values();
        $cashiers = collect($r['cashiers'])->map(fn ($x) => ['name' => $x['name'], 'sales' => number_format($x['sales']), 'total' => money($x['total'], false)]);

        // every sale rung on the counter, newest last so the sheet reads as the day ran
        $sales = $r['sales_list'];
        $saleRows = $sales->map(function ($sale) {
            $pax = (int) $sale->passes->sum(fn ($p) => (int) $p->adults + (int) $p->children);
            $modes = $sale->payments->map(fn ($p) => $p->paymentMode?->name)->filter()->unique()->values();

            return ['sale' => $sale->sale_no,
                'time' => $sale->sold_at?->format('H:i') ?? '—',
                'guest' => $sale->guest_name ?: 'Walk-in',
                'pax' => $pax ? number_format($pax) : '—',
                'tender' => $modes->isEmpty() ? '—' : $modes->implode(' + '),
                'total' => money((float) $sale->total, false),
                'state' => ['text' => $sale->status === 'void' ? 'Void' : 'Paid', 'tone' => $sale->status === 'void' ? 'red' : 'green']];
        })->values();

        // the passes the counter sold, and whether the gate has seen them
        $passes = ClubDayPass::whereDate('date', $ds)->get(['pass_no', 'name', 'kind', 'adults', 'children', 'total', 'cancelled_at', 'redeemed_at']);
        $passRows = $passes->map(fn ($p) => ['pass' => $p->pass_no, 'guest' => $p->name,
            'kind' => $p->kind === 'guest' ? 'Guest of a member' : 'Day pass',
            'pax' => (int) $p->adults + (int) $p->children,
            'value' => money((float) $p->total, false),
            'state' => $p->cancelled_at ? ['text' => 'Cancelled', 'tone' => 'red']
                : ($p->redeemed_at ? ['text' => 'Admitted '.$p->redeemed_at->format('H:i'), 'tone' => 'green']
                : ['text' => 'Not presented', 'tone' => 'amber'])]);

        // the outlet shift — the drawer the manager has to sign for
        $shiftRows = collect($r['shifts'])->map(function ($row) {
            $s = $row['session'];
            $sum = $row['summary'];

            return ['shift' => ucfirst((string) $s->shift).' · '.($s->user?->name ?? 'Counter'),
                'opened' => $s->opened_at?->format('H:i') ?? '—',
                'closed' => $s->closed_at?->format('H:i') ?? ['text' => 'Still open', 'tone' => 'amber'],
                'float' => money((float) ($s->opening_float ?? 0), false),
                'expected' => $sum ? money((float) ($sum['expected_cash'] ?? 0), false) : '—',
                'counted' => $s->counted_cash === null ? '—' : money((float) $s->counted_cash, false),
                'variance' => $s->variance === null ? '—' : ['text' => money((float) $s->variance, false), 'tone' => abs((float) $s->variance) < 0.005 ? 'green' : 'red']];
        })->values();

        // the tax on what sold, split the way the return has to be filed
        $split = TaxService::breakdown((float) $r['tax'], 'club', (float) $r['rate']);
        $taxRows = collect(TaxService::COMPONENTS)->map(function ($meta, $code) use ($split, $r) {
            $portion = (float) ($split[$code] ?? 0);

            return ['tax' => $meta[0], 'authority' => TaxService::AUTHORITIES[$meta[1]] ?? $meta[1],
                'rate' => number_format((float) $r['rate'] * 100, 2).' %',
                'amount' => money($portion, false)];
        })->values();

        return [
            'headline' => [
                self::st('Takings', money($r['total']), $r['sales'].' tickets sold', 'green', 'money'),
                self::st('Guests through the gate', number_format($adm['pax_admitted']).' / '.number_format($adm['pax_issued']),
                    number_format($adm['waiting']).' passes still to present', $adm['waiting'] ? 'amber' : 'green', 'check'),
                self::st('Net of tax', money($r['net']), money($r['tax']).' tax collected', 'blue', 'receipt'),
                self::st('Charged to rooms', money($r['room_total']), 'the rest was paid at the counter', 'slate', 'bed'),
                self::st('Cash in the drawer', money($r['cash_in']), money($r['change']).' change given', 'teal', 'wallet'),
                self::st('Voids', number_format($r['void_sales']), money($r['void_total']), $r['void_sales'] ? 'red' : 'slate', 'alert'),
            ],
            'sections' => [
                self::table('tender', 'Takings by tender', 'Every payment the counter took, split by the mode it came in on.',
                    ['mode' => 'Mode', 'count' => 'Payments', 'amount' => 'Amount', 'cash' => 'Kind'],
                    $tenderRows->all(), 'No payment was taken at the outlet on this date.'),

                self::table('type', 'What sold, line by line', 'The tickets, day rates and services behind the total, so the manager can see what is earning.',
                    ['item' => 'Item', 'qty' => 'Qty', 'net' => 'Net', 'tax' => 'Tax', 'total' => 'Charged'],
                    $typeRows->all(), 'Nothing was sold on this date.'),

                self::table('passes', 'Through the gate', 'Every pass the counter sold today and whether the gate has seen the guest.',
                    ['pass' => 'Pass', 'guest' => 'Guest', 'kind' => 'Kind', 'pax' => 'Guests', 'value' => 'Paid', 'state' => 'At the gate'],
                    $passRows->take(10)->values()->all(), 'No pass was sold for this date.'),

                self::table('sales', 'Every sale at the counter', 'The till roll: what was rung, by whom it was paid and what state it is in.',
                    ['sale' => 'Sale', 'time' => 'Time', 'guest' => 'Guest', 'pax' => 'Guests', 'tender' => 'Tender', 'total' => 'Total', 'state' => 'State'],
                    $saleRows->take(12)->values()->all(), 'No sale was rung on this date.'),

                self::table('category', 'What sold by category', 'The same sales grouped the way the Z-report groups them.',
                    ['category' => 'Category', 'qty' => 'Qty', 'net' => 'Net', 'tax' => 'Tax'],
                    $categoryRows->all(), 'Nothing was sold on this date.'),

                self::table('shift', 'The outlet shift and the drawer', 'Who held the float, what the till said it should hold, and what was counted.',
                    ['shift' => 'Shift', 'opened' => 'Opened', 'closed' => 'Closed', 'float' => 'Float', 'expected' => 'Expected cash', 'counted' => 'Counted', 'variance' => 'Variance'],
                    $shiftRows->all(), 'The outlet has not opened a shift on this date.'),

                self::table('tax', 'The tax on what sold', 'A VAT-registered outlet files each component separately, so the briefing splits the tax the same way.',
                    ['tax' => 'Tax', 'authority' => 'Payable to', 'rate' => 'Rate', 'amount' => 'Collected'],
                    $taxRows->all(), 'No tax was charged on this date.'),

                self::items('gate', 'The gate and the drawer', 'Admissions reconciliation and what the shift still owes.',
                    array_values(array_filter([
                        self::li(number_format($adm['issued']).' tickets issued', number_format($adm['pax_issued']).' guests', 'slate'),
                        self::li(number_format($adm['admitted']).' admitted at the gate', number_format($adm['pax_admitted']).' guests in', $adm['admitted'] ? 'green' : 'amber'),
                        $adm['waiting'] ? self::li(number_format($adm['waiting']).' tickets not yet presented', 'valid on their own date only', 'amber') : null,
                        $adm['cancelled'] ? self::li(number_format($adm['cancelled']).' cancelled', 'refund or credit', 'red') : null,
                        $r['void_sales'] ? self::li(number_format($r['void_sales']).' sales voided', money($r['void_total']), 'red') : null,
                        $r['change'] > 0 ? self::li(money($r['change']).' change given from the drawer', 'expected cash is the float plus takings less change', 'slate') : null,
                    ])),
                    'Nothing to reconcile.'),

                self::table('cashiers', 'On the counter', 'Who sold what, for the shift hand-over.',
                    ['name' => 'Cashier', 'sales' => 'Sales', 'total' => 'Taken'],
                    $cashiers->all(), 'No sale was rung on this date.'),
            ],
            'watch' => array_values(array_filter([
                $r['void_sales'] ? number_format($r['void_sales']).' outlet sales were voided' : null,
                $adm['issued'] > 0 && $adm['admitted'] === 0 ? 'No ticket has been admitted at the gate yet' : null,
                $adm['waiting'] ? number_format($adm['waiting']).' passes sold today have not been presented' : null,
                collect($r['shifts'])->contains(fn ($x) => $x['session']->status === 'open') ? 'A shift is still open — the drawer has not been counted' : null,
                collect($r['shifts'])->contains(fn ($x) => $x['session']->variance !== null && abs((float) $x['session']->variance) > 0.005) ? 'A closed shift does not balance' : null,
            ])),
            'todo' => array_values(array_filter([
                'Count the drawer and close the outlet shift.',
                $adm['waiting'] ? 'Check the '.$adm['waiting'].' unpresented tickets before the gate closes.' : null,
                'Print the day report and file it with the Z.',
            ])),
            'sources' => 'club_ticket_sales, club_ticket_items, club_ticket_payments, club_day_passes, department_shift_sessions, tax components',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Laundry & Linen
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildLaundry(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $orders = LaundryOrder::whereDate('created_at', $ds)->get(['status', 'type', 'pieces', 'total', 'express_charge', 'delivered_at', 'payment_mode_id', 'folio_transaction_id', 'promised_at']);
        $delivered = LaundryOrder::whereDate('delivered_at', $ds)->get(['total', 'pieces']);
        $hand = LaundryOrder::whereIn('status', ['collected', 'processing', 'ready'])->get(['status', 'total', 'promised_at']);
        $cash = LaundryOrder::whereDate('created_at', $ds)->whereNotNull('payment_mode_id')->get(['total']);
        $unsettled = LaundryOrder::whereIn('status', ['delivered', 'ready'])->whereNull('payment_mode_id')->whereNull('folio_transaction_id')->get(['total']);

        $linen = LinenMovement::whereDate('date', $ds)->get(['type', 'qty']);
        $pieces = (int) $orders->sum('pieces');
        $revenue = (float) $orders->sum('total');
        $guestOrders = $orders;
        $express = (float) $orders->sum('express_charge');

        return [
            'headline' => [
                self::st('Orders taken', number_format($guestOrders->count()), number_format($pieces).' pieces, '.$orders->where('type', 'walk_in')->count().' walk-in', 'blue', 'shirt'),
                self::st('Delivered today', number_format($delivered->count()), number_format((int) $delivered->sum('pieces')).' pieces', 'green', 'check'),
                self::st('Still in hand', number_format($hand->count()), money((float) $hand->sum('total')).' of work', $hand->count() ? 'amber' : 'green', 'clock'),
                self::st('Revenue', money($revenue), 'on the orders taken today', 'teal', 'money'),
                self::st('Paid at the desk', money((float) $cash->sum('total')), 'the rest charged to rooms', 'purple', 'wallet'),
                self::st('Unsettled', number_format($unsettled->count()), money((float) $unsettled->sum('total')).' collected but unpaid', $unsettled->count() ? 'red' : 'green', 'alert'),
            ],
            'sections' => [
                self::table('queue', 'The wash in hand', 'Orders taken but not yet back with the guest.',
                    ['status' => 'Stage', 'orders' => 'Orders', 'value' => 'Value'],
                    $hand->groupBy('status')->map(fn ($g, $s) => ['status' => LaundryOrder::STATUSES[$s] ?? ucfirst($s),
                        'orders' => number_format($g->count()), 'value' => money((float) $g->sum('total'), false)])->values()->all(),
                    'Nothing is in the wash.'),
                self::table('linen', 'House linen through the laundry', 'What the floors and the laundry moved on the business date.',
                    ['type' => 'Movement', 'entries' => 'Entries', 'qty' => 'Pieces'],
                    $linen->groupBy('type')->map(fn ($g, $t) => ['type' => LinenMovement::TYPES[$t] ?? ucfirst($t),
                        'entries' => number_format($g->count()), 'qty' => number_format((float) $g->sum('qty'))])->values()->all(),
                    'No linen movement recorded on this date.'),
                self::items('desk', 'The desk side', 'What the guest is told and what the cashier has to collect.',
                    array_values(array_filter([
                        $express > 0 ? self::li('Express surcharge taken', money($express), 'green') : null,
                        $unsettled->count() ? self::li(number_format($unsettled->count()).' orders delivered without payment or room charge', money((float) $unsettled->sum('total')), 'red') : null,
                        $hand->where('status', 'ready')->count() ? self::li(number_format($hand->where('status', 'ready')->count()).' orders ready and waiting for collection', 'call the rooms', 'amber') : null,
                    ])),
                    'Nothing for the desk to chase.'),
            ],
            'watch' => array_values(array_filter([
                $unsettled->count() ? number_format($unsettled->count()).' laundry orders are not settled' : null,
                $hand->count() ? number_format($hand->count()).' orders are still in the wash' : null,
            ])),
            'todo' => [
                'Check the promised times on every order still in hand.',
                'Settle or post the orders the guest has collected.',
                'Reconcile the linen count for the floors before the shift ends.',
            ],
            'sources' => 'laundry_orders, laundry_order_lines, linen_movements',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Engineering & Maintenance
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildMaintenance(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $raised = MaintenanceRequest::whereDate('created_at', $ds)->get(['priority', 'status', 'cost', 'room_id']);
        $closed = MaintenanceRequest::whereDate('resolved_at', $ds)->get(['cost', 'priority']);
        $open = MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->get(['ticket_no', 'due_date', 'priority', 'title', 'location', 'room_id', 'status']);
        $overdue = $open->filter(fn ($r) => $r->due_date && $r->due_date->lt($d));
        $pm = PmSchedule::where('is_active', true)->whereDate('next_due', '<=', $ds)->get(['title', 'next_due']);
        $pmWeek = PmSchedule::where('is_active', true)->whereDate('next_due', '>', $ds)->whereDate('next_due', '<=', $d->copy()->addDays(7)->toDateString())->count();
        $outages = RoomOutage::where('status', 'active')->get(['room_id', 'starts_on', 'ends_on', 'reason']);
        $pmSoon = PmSchedule::where('is_active', true)->whereDate('next_due', '>=', $ds)
            ->whereDate('next_due', '<=', $d->copy()->addDays(7)->toDateString())->orderBy('next_due')
            ->take(8)->get(['title', 'next_due', 'vendor', 'priority']);

        return [
            'headline' => [
                self::st('Jobs raised', number_format($raised->count()), $raised->where('priority', 'urgent')->count().' urgent', $raised->where('priority', 'urgent')->count() ? 'red' : 'blue', 'tool'),
                self::st('Jobs closed', number_format($closed->count()), money((float) $closed->sum('cost')).' spent', 'green', 'check'),
                self::st('Open jobs', number_format($open->count()), number_format($overdue->count()).' past the promised date', $overdue->count() ? 'red' : 'slate', 'alert'),
                self::st('Planned maintenance due', number_format($pm->count()), $pmWeek.' more in the next 7 days', $pm->count() ? 'amber' : 'green', 'calendar'),
                self::st('Rooms out of order', number_format($c['ooo']), $outages->count().' outage records open', $c['ooo'] ? 'red' : 'green', 'bed'),
                self::st('Cost today', money((float) $raised->sum('cost') + (float) $closed->sum('cost')), 'posted against jobs', 'purple', 'money'),
            ],
            'sections' => [
                self::table('priority', 'Open work by priority', 'Everything the engineering team still has to close.',
                    ['priority' => 'Priority', 'open' => 'Open', 'oldest' => 'Oldest due'],
                    $open->groupBy('priority')->map(fn ($g, $p) => ['priority' => ucfirst($p), 'open' => number_format($g->count()),
                        'oldest' => $g->map(fn ($r) => $r->due_date)->filter()->min()?->format('d M') ?? '—'])->values()->all(),
                    'No job is open.'),
                self::table('overdue', 'Past the promised date', 'Jobs the team has promised and not closed, worst first.',
                    ['ticket' => 'Ticket', 'job' => 'Job', 'where' => 'Where', 'due' => 'Promised', 'late' => 'Days late'],
                    $overdue->sortBy('due_date')->take(8)->map(fn ($r) => ['ticket' => $r->ticket_no,
                        'job' => $r->title, 'where' => $r->location ?: ($r->room_id ? 'Room '.$r->room_id : '—'),
                        'due' => $r->due_date?->format('d M') ?? '—',
                        'late' => ['text' => number_format($r->due_date->diffInDays($d)).' d', 'tone' => 'red']])->values()->all(),
                    'Nothing is past its promised date.'),
                self::table('soon', 'Planned maintenance in the next seven days', 'Work already scheduled, so the team can plan around it.',
                    ['schedule' => 'Schedule', 'due' => 'Due', 'owner' => 'Owner', 'priority' => 'Priority'],
                    $pmSoon->map(fn ($s) => ['schedule' => $s->title, 'due' => $s->next_due?->format('D j M') ?? '—',
                        'owner' => $s->vendor ?: 'In house', 'priority' => ucfirst((string) $s->priority)])->all(),
                    'Nothing is planned for the next seven days.'),
                self::table('pm', 'Planned maintenance due now', 'Schedules that have reached their due date — statutory and plant maintenance.',
                    ['title' => 'Schedule', 'due' => 'Was due'],
                    $pm->sortBy('next_due')->take(8)->map(fn ($s) => ['title' => $s->title, 'due' => ['text' => $s->next_due?->format('d M') ?? '—', 'tone' => 'red']])->values()->all(),
                    'Nothing is due.'),
                self::items('out', 'Rooms out of the sale', 'What housekeeping cannot sell because engineering has not released it.',
                    $outages->map(fn ($o) => self::li('Room '.($o->room_id ?: '—').' — '.($o->reason ?: 'out of order'),
                        $o->ends_on ? 'to '.$o->ends_on->format('d M') : 'no return date', 'red'))->values()->all(),
                    'No room is withdrawn from sale.'),
                self::table('raised', 'Raised today', 'Where the calls came from.',
                    ['priority' => 'Priority', 'jobs' => 'Jobs', 'cost' => 'Cost'],
                    $raised->groupBy('priority')->map(fn ($g, $p) => ['priority' => ucfirst($p), 'jobs' => number_format($g->count()), 'cost' => money((float) $g->sum('cost'), false)])->values()->all(),
                    'No job was raised on this date.'),
            ],
            'watch' => array_values(array_filter([
                $overdue->count() ? number_format($overdue->count()).' maintenance jobs are past their promised date' : null,
                $pm->count() ? number_format($pm->count()).' planned maintenance schedules are due' : null,
                $c['ooo'] ? number_format($c['ooo']).' rooms are out of order and cannot be sold' : null,
            ])),
            'todo' => array_values(array_filter([
                $overdue->count() ? 'Clear or re-date the '.$overdue->count().' overdue jobs.' : null,
                $pm->count() ? 'Sign off the '.$pm->count().' planned maintenance schedules.' : null,
                'Walk the plant rooms and log the readings.',
            ])),
            'sources' => 'maintenance_requests, pm_schedules, room_outages, rooms',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Security
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildSecurity(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $visitors = Visitor::whereDate('created_at', $ds)->get(['status', 'purpose']);
        $onSite = Visitor::where('status', 'in')->count();
        $passes = GatePass::whereDate('created_at', $ds)->get(['status', 'type']);
        $outNow = GatePass::whereIn('status', ['out', 'partial'])->get(['expected_return_date', 'pass_no', 'party_name']);
        $overduePasses = $outNow->filter(fn ($p) => $p->expected_return_date && $p->expected_return_date->lt($d));
        $incidents = SecurityIncident::whereDate('occurred_at', $ds)->get(['type', 'severity', 'status', 'title', 'location']);
        $openIncidents = SecurityIncident::whereIn('status', ['open', 'investigating'])->count();
        $denied = $visitors->where('status', 'denied')->count();
        $keysOut = \App\Models\KeyLoan::whereNull('in_at')->get(['master_key_id', 'due_at', 'holder']);
        $onSiteList = Visitor::where('status', 'in')->orderBy('checked_in_at')->take(8)
            ->get(['name', 'company', 'host_name', 'purpose', 'checked_in_at']);
        $keyList = \App\Models\KeyLoan::with('key')->whereNull('in_at')->orderBy('due_at')->take(8)
            ->get(['master_key_id', 'holder', 'out_at', 'due_at']);

        return [
            'headline' => [
                self::st('Visitors booked in', number_format($visitors->count()), number_format($onSite).' on site now', 'blue', 'id'),
                self::st('Turned away', number_format($denied), $denied ? 'watch list or refusal' : 'nobody refused', $denied ? 'red' : 'green', 'shield'),
                self::st('Gate passes raised', number_format($passes->count()), $outNow->count().' still out', $outNow->count() ? 'amber' : 'green', 'box'),
                self::st('Incidents today', number_format($incidents->count()), number_format($openIncidents).' open in total', $incidents->count() ? 'red' : 'green', 'alert'),
                self::st('Returns overdue', number_format($overduePasses->count()), 'goods past their return date', $overduePasses->count() ? 'red' : 'green', 'clock'),
                self::st('On site now', number_format($onSite), 'visitors not yet checked out', 'purple', 'users'),
            ],
            'sections' => [
                self::table('visitors', 'The visitor register', 'Everyone booked through the door on the business date.',
                    ['status' => 'Status', 'count' => 'Visitors'],
                    $visitors->groupBy('status')->map(fn ($g, $s) => ['status' => Visitor::STATUSES[$s][0] ?? ucfirst($s), 'count' => number_format($g->count()),
                        'tone' => Visitor::STATUSES[$s][1] ?? 'slate'])->values()->all(),
                    'No visitor was booked in on this date.'),
                self::table('onsite', 'On site now', 'Everyone still inside the property, longest visit first.',
                    ['guest' => 'Visitor', 'host' => 'Host', 'purpose' => 'Purpose', 'since' => 'In since'],
                    $onSiteList->map(fn ($v) => ['guest' => $v->name, 'host' => $v->host_name ?? $v->company ?? '—',
                        'purpose' => $v->purpose ?? '—', 'since' => $v->checked_in_at?->format('H:i') ?? '—'])->all(),
                    'No visitor is inside the property.'),
                self::table('keys', 'Master keys signed out', 'Every key off the board, and when it is due back.',
                    ['key' => 'Key', 'holder' => 'Holder', 'out' => 'Out since', 'due' => 'Due back'],
                    $keyList->map(fn ($k) => ['key' => $k->key?->name ?? ('Key '.$k->master_key_id), 'holder' => $k->holder ?? '—',
                        'out' => $k->out_at?->format('H:i') ?? '—',
                        'due' => $k->due_at ? ['text' => $k->due_at->format('H:i'), 'tone' => $k->due_at->lt(now()) ? 'red' : 'slate'] : '—'])->all(),
                    'Every key is on the board.'),
                self::table('passes', 'Goods out on a gate pass', 'Returnable property that has left the building.',
                    ['pass' => 'Pass', 'party' => 'Party', 'due' => 'Due back', 'flag' => 'Position'],
                    $outNow->take(8)->map(fn ($p) => ['pass' => $p->pass_no, 'party' => $p->party_name ?? '—',
                        'due' => $p->expected_return_date?->format('d M') ?? '—',
                        'flag' => ['text' => $p->expected_return_date && $p->expected_return_date->lt($d) ? 'Overdue' : 'Out', 'tone' => $p->expected_return_date && $p->expected_return_date->lt($d) ? 'red' : 'amber']])->values()->all(),
                    'Nothing is out on a gate pass.'),
                self::table('incidents', 'Incidents', 'What was reported and where it stands.',
                    ['title' => 'Incident', 'type' => 'Type', 'place' => 'Where', 'status' => 'Status'],
                    $incidents->map(fn ($i) => ['title' => $i->title, 'type' => SecurityIncident::TYPES[$i->type] ?? ucfirst($i->type), 'place' => $i->location ?? '—',
                        'status' => ['text' => SecurityIncident::STATUSES[$i->status][0] ?? ucfirst($i->status), 'tone' => SecurityIncident::STATUSES[$i->status][1] ?? 'slate']])->all(),
                    'No incident was reported on this date.'),
                self::items('duty', 'On the door', 'What the duty officer has to close today.',
                    array_values(array_filter([
                        $onSite ? self::li(number_format($onSite).' visitors have not checked out', 'confirm before the shift ends', 'amber') : null,
                        $overduePasses->count() ? self::li(number_format($overduePasses->count()).' gate passes are overdue', 'call the parties', 'red') : null,
                        $openIncidents ? self::li(number_format($openIncidents).' incidents remain open', 'follow up and close', 'red') : null,
                        $denied ? self::li(number_format($denied).' visitors were refused entry', 'file the reason', 'slate') : null,
                        $keysOut->count() ? self::li(number_format($keysOut->count()).' master keys are signed out', $keysOut->filter(fn ($k) => $k->due_at && $k->due_at->lt(now()))->count().' past their return time', 'amber') : null,
                    ])),
                    'The door is clear.'),
            ],
            'watch' => array_values(array_filter([
                $overduePasses->count() ? number_format($overduePasses->count()).' gate passes are past their return date' : null,
                $incidents->count() ? number_format($incidents->count()).' incidents reported today' : null,
                $onSite ? number_format($onSite).' visitors are still on site' : null,
            ])),
            'todo' => [
                'Walk the perimeter and log the patrol.',
                'Check every overnight visitor out before the shift changes.',
                'Chase the property that has not come back.',
            ],
            'sources' => 'visitors, gate_passes, security_incidents, key_loans',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // People & HR
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildHr(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $people = Employee::where('is_active', true)->get(['id', 'name', 'department_id', 'contract_end', 'probation_end']);
        $staff = $people->count();
        // attendances.status is a single letter: P present, A absent, L leave, H half day, WO week off.
        $attendance = Attendance::whereDate('date', $ds)->get(['employee_id', 'status']);
        $statusOf = $attendance->groupBy('employee_id')->map(fn ($g) => $g->first()->status);
        $rostered = RosterShift::whereDate('date', $ds)->get(['employee_id'])->pluck('employee_id')->unique();
        $deptOf = $people->pluck('department_id', 'id');
        $deptNames = Department::whereIn('id', $people->pluck('department_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        $byDept = $people->groupBy('department_id')->map(function ($group, $deptId) use ($statusOf, $rostered, $deptNames) {
            $on = $group->filter(fn ($e) => in_array($statusOf[$e->id] ?? null, ['P', 'H'], true))->count();
            $off = $group->filter(fn ($e) => ($statusOf[$e->id] ?? null) === 'A')->count();
            $shifts = $group->filter(fn ($e) => $rostered->contains($e->id))->count();

            return ['dept' => $deptNames[$deptId] ?? 'Unassigned', 'staff' => number_format($group->count()),
                'shifts' => number_format($shifts), 'on' => number_format($on),
                'off' => ['text' => number_format($off), 'tone' => $off ? 'red' : 'green']];
        })->sortByDesc(fn ($r) => (int) str_replace(',', '', $r['staff']))->values();
        $leaveQueue = LeaveRequest::with(['employee:id,name', 'leaveType:id,name'])->where('status', 'pending')
            ->orderBy('date_from')->take(8)->get(['employee_id', 'leave_type_id', 'date_from', 'date_to', 'days']);
        $ending = $people->filter(fn ($e) => $e->contract_end || $e->probation_end)->map(function ($e) use ($d) {
            $contract = $e->contract_end ? Carbon::parse($e->contract_end) : null;
            $probation = $e->probation_end ? Carbon::parse($e->probation_end) : null;
            $date = $contract ?? $probation;
            $kind = $contract ? 'Contract' : 'Probation';

            return ['employee' => $e->name, 'kind' => $kind, 'ends' => $date, 'days' => (int) $d->diffInDays($date, false)];
        })->filter(fn ($e) => $e['days'] <= 60)->sortBy('days')->take(8)->values();
        $onLeave = LeaveRequest::where('status', 'approved')->whereDate('date_from', '<=', $ds)->whereDate('date_to', '>=', $ds)->count();
        $newLeave = LeaveRequest::where('status', 'pending')->count();
        $roster = RosterShift::whereDate('date', $ds)->count();
        $probation = Employee::where('is_active', true)->whereNotNull('probation_end')->whereDate('probation_end', '<=', $d->copy()->addDays(30)->toDateString())->count();
        $contracts = Employee::where('is_active', true)->whereNotNull('contract_end')->whereDate('contract_end', '<=', $d->copy()->addDays(60)->toDateString())->count();
        $present = $attendance->whereIn('status', ['P', 'H'])->count();
        $absent = $attendance->where('status', 'A')->count();
        $halfDay = $attendance->where('status', 'H')->count();

        return [
            'headline' => [
                self::st('Headcount', number_format($staff), 'active employees', 'blue', 'users'),
                self::st('On duty today', number_format($present), $roster ? number_format($roster).' rostered shifts' : 'no roster published', 'green', 'check'),
                self::st('Absent', number_format($absent), number_format($halfDay).' on a half day', $absent ? 'red' : 'green', 'alert'),
                self::st('On leave', number_format($onLeave), 'approved for today', 'purple', 'calendar'),
                self::st('Leave awaiting decision', number_format($newLeave), 'requests in the queue', $newLeave ? 'amber' : 'green', 'mail'),
                self::st('Contracts to watch', number_format($contracts), $probation.' on probation ending soon', ($contracts || $probation) ? 'amber' : 'slate', 'id'),
            ],
            'sections' => [
                self::table('attendance', 'Attendance', 'How the property clocked in on the business date.',
                    ['status' => 'Status', 'people' => 'People'],
                    $attendance->groupBy('status')->map(fn ($g, $s) => ['status' => ucfirst($s), 'people' => number_format($g->count())])->values()->all(),
                    'No attendance was recorded on this date — check the time clock.'),
                self::table('departments', 'Attendance by department', 'Who was on the roster, who clocked in and who did not, department by department.',
                    ['dept' => 'Department', 'staff' => 'On the books', 'shifts' => 'Rostered', 'on' => 'On duty', 'off' => 'Absent'],
                    $byDept->all(),
                    'No employee is on the books, so there is nothing to compare.'),
                self::table('leave', 'The leave queue', 'Requests waiting for a decision, oldest dates first.',
                    ['employee' => 'Employee', 'type' => 'Type', 'from' => 'From', 'to' => 'To', 'days' => 'Days'],
                    $leaveQueue->map(fn ($l) => ['employee' => $l->employee?->name ?? '—', 'type' => $l->leaveType?->name ?? 'Leave',
                        'from' => $l->date_from?->format('D j M'), 'to' => $l->date_to?->format('D j M'), 'days' => number_format((float) $l->days, 1)])->all(),
                    'No leave request is waiting for a decision.'),
                self::table('ending', 'Contracts and probation running out', 'The next sixty days, soonest first.',
                    ['employee' => 'Employee', 'kind' => 'Kind', 'ends' => 'Ends', 'days' => 'Days left'],
                    $ending->map(fn ($e) => ['employee' => $e['employee'], 'kind' => $e['kind'], 'ends' => $e['ends']->format('D j M'),
                        'days' => ['text' => number_format($e['days']), 'tone' => $e['days'] <= 14 ? 'red' : 'amber']])->all(),
                    'No contract or probation period ends in the next sixty days.'),
                self::items('people', 'People decisions waiting', 'What HR has to act on today.',
                    array_values(array_filter([
                        $newLeave ? self::li(number_format($newLeave).' leave requests waiting for a decision', 'approve or decline today', 'amber') : null,
                        $absent ? self::li(number_format($absent).' absentees', 'cover the shift before service', 'red') : null,
                        $probation ? self::li(number_format($probation).' staff reach the end of probation within 30 days', 'confirm or extend', 'amber') : null,
                        $contracts ? self::li(number_format($contracts).' contracts expire within 60 days', 'renew or release', 'slate') : null,
                    ])),
                    'Nothing waiting on HR.'),
            ],
            'watch' => array_values(array_filter([
                $absent ? number_format($absent).' staff did not report for duty' : null,
                $newLeave ? number_format($newLeave).' leave requests are undecided' : null,
            ])),
            'todo' => [
                'Reconcile the time clock against the roster.',
                'Decide the leave queue before the next roster is published.',
                'Send the headcount and absentee list to the GM.',
            ],
            'sources' => 'employees, departments, attendances, leave_requests, roster_shifts',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Finance & Accounts
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildFinance(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $folioPayments = FolioTransaction::with('paymentMode')->where('is_void', false)->whereDate('business_date', $ds)->where('type', 'payment')->get(['payment_mode_id', 'amount']);
        $posPayments = PosPayment::with('paymentMode')->where('kind', 'payment')->whereDate('business_date', $ds)->get(['payment_mode_id', 'amount']);
        $combined = $folioPayments->map(fn ($p) => ['payment_mode_id' => $p->payment_mode_id, 'amount' => (float) $p->amount, 'paymentMode' => $p->paymentMode])
            ->concat($posPayments->map(fn ($p) => ['payment_mode_id' => $p->payment_mode_id, 'amount' => (float) $p->amount, 'paymentMode' => $p->paymentMode]));
        $byMode = collect($combined->all())->groupBy(fn ($p) => $p['paymentMode']?->name ?? 'Unspecified')
            ->map(fn ($g, $n) => ['mode' => $n, 'count' => $g->count(), 'amount' => (float) $g->sum('amount')])->sortByDesc('amount')->values();

        $refunds = (float) FolioTransaction::where('is_void', false)->whereDate('business_date', $ds)->where('type', 'refund')->sum('amount');
        $receipts = Voucher::where('type', 'receipt')->whereDate('date', $ds)->get(['amount']);
        $ageing = ManagementReportService::arAgeing($d);
        $ar = ['b0' => (float) $ageing->sum('b0'), 'b30' => (float) $ageing->sum('b30'), 'b60' => (float) $ageing->sum('b60'), 'b90' => (float) $ageing->sum('b90'), 'outstanding' => (float) $ageing->sum('outstanding')];
        $ap = SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])->get(['due_date', 'total']);
        $apDue = $ap->filter(fn ($i) => $i->due_date && $i->due_date->lte($d->copy()->addDays(7)));
        $apOverdue = $ap->filter(fn ($i) => $i->due_date && $i->due_date->lt($d));
        $auditDay = \App\Models\AuditDay::whereDate('business_date', $ds)->first();
        $findings = AuditFinding::where('status', 'open')->count();
        $nightAudit = \App\Models\NightAudit::whereDate('business_date', $ds)->first();
        $pl = self::profit($d);
        $budget = self::againstBudget($d);

        return [
            'headline' => [
                self::st('Collected', money((float) $combined->sum('amount')), number_format($combined->count()).' receipts', 'green', 'wallet'),
                self::st('Tax collected', money($c['day']['tax']), 'on today\'s revenue', 'blue', 'receipt'),
                self::st('Receivables', money($ar['outstanding']), money($ar['b90']).' over 90 days', $ar['b90'] > 0 ? 'red' : 'slate', 'money'),
                self::st('Payables due', money((float) $apDue->sum('total')), $apOverdue->count().' invoices overdue', $apOverdue->count() ? 'red' : 'green', 'trend'),
                self::st('Audit exceptions', number_format($findings), $auditDay ? 'day '.$auditDay->status : 'no audit day opened', $findings ? 'red' : 'green', 'alert'),
                self::st('Discounts & allowances', money($c['day']['disc']), 'given today', $c['day']['disc'] > 0 ? 'amber' : 'slate', 'minus'),
            ],
            'sections' => [
                self::table('collected', 'Money in today', 'Every receipt, at the desk and at the outlets, by the mode it came in on.',
                    ['mode' => 'Mode', 'count' => 'Receipts', 'amount' => 'Amount'],
                    $byMode->map(fn ($m) => ['mode' => $m['mode'], 'count' => number_format($m['count']), 'amount' => money($m['amount'], false)])->all(),
                    'Nothing has been collected on this date.'),
                self::table('ageing', 'Receivables ageing', 'City-ledger bills still open, bucketed by how long they are past due.',
                    ['bucket' => 'Bucket', 'amount' => 'Amount'],
                    [
                        ['bucket' => 'Not yet due', 'amount' => money($ar['b0'], false)],
                        ['bucket' => '1 – 30 days', 'amount' => money($ar['b30'], false)],
                        ['bucket' => '31 – 60 days', 'amount' => money($ar['b60'], false)],
                        ['bucket' => 'Over 60 days', 'amount' => ['text' => money($ar['b90'], false), 'tone' => $ar['b90'] > 0 ? 'red' : 'green']],
                    ],
                    'No company bill is outstanding.'),
                self::plSection($pl),
                self::budgetSection($budget, $pl['fy']),
                self::items('close', 'Close and control', 'What stands between today and a clean close.',
                    array_values(array_filter([
                        $nightAudit ? self::li('Night audit run for this date', $nightAudit->occupancy_pct ? 'occupancy '.number_format((float) $nightAudit->occupancy_pct, 1).' %' : 'figures posted', 'green') : self::li('Night audit has not run for this date', 'figures are still open', 'red'),
                        $refunds > 0 ? self::li('Refunds paid today', money($refunds), 'amber') : null,
                        $receipts->count() ? self::li(number_format($receipts->count()).' receipts issued', money((float) $receipts->sum('amount')), 'green') : null,
                        $apOverdue->count() ? self::li(number_format($apOverdue->count()).' supplier invoices overdue', money((float) $apOverdue->sum('total')), 'red') : null,
                        $findings ? self::li(number_format($findings).' income-audit findings unresolved', 'clear before the day closes', 'red') : null,
                    ])),
                    'Nothing is holding the close.'),
            ],
            'watch' => array_values(array_filter([
                $ar['b90'] > 0 ? money($ar['b90']).' of receivables is over 60 days old' : null,
                $apOverdue->count() ? number_format($apOverdue->count()).' supplier invoices are overdue' : null,
                $findings ? number_format($findings).' income-audit findings are unresolved' : null,
                ! $nightAudit ? 'The night audit has not been run for this business date' : null,
            ])),
            'todo' => [
                'Recount the cashier drawers against the receipts.',
                'Bank the takings and post the deposit.',
                'Chase the oldest city-ledger bills with the sales team.',
            ],
            'sources' => 'folio_transactions, pos_payments, vouchers, supplier_invoices, audit_days, audit_findings, night_audits',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Guest Experience
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildGuestExp(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $feedback = GuestFeedback::whereDate('created_at', $ds)->get(['rating_overall', 'nps', 'sentiment', 'comments']);
        $reviews = OnlineReview::whereDate('review_date', $ds)->get(['source', 'rating', 'status', 'response_text', 'sentiment']);
        $awaiting = OnlineReview::whereIn('status', ['new', 'draft', 'pending'])->count();
        $negative = OnlineReview::where('rating', '<=', 3)->whereIn('status', ['new', 'draft', 'pending'])->count();
        $cases = \App\Models\FeedbackCase::whereIn('status', ['open', 'in_progress'])->get(['status', 'severity']);
        $urgent = \App\Models\FeedbackCase::with('assignee:id,name')->whereIn('status', ['open', 'in_progress'])
            ->orderByRaw("case when severity = 'high' then 0 else 1 end")->orderBy('due_at')->take(8)
            ->get(['case_no', 'title', 'severity', 'department', 'due_at', 'assigned_to', 'status']);
        $latest = GuestFeedback::whereDate('created_at', '>=', $d->copy()->subDays(3)->toDateString())
            ->orderByDesc('created_at')->take(6)->get(['guest_name', 'rating_overall', 'sentiment', 'comments']);
        $avgRating = $feedback->whereNotNull('rating_overall')->avg('rating_overall');
        $nps = $feedback->whereNotNull('nps')->avg('nps');
        $reviewAvg = $reviews->whereNotNull('rating')->avg('rating');
        $promoters = $feedback->where('nps', '>=', 9)->count();
        $detractors = $feedback->where('nps', '<=', 6)->count();

        return [
            'headline' => [
                self::st('Feedback received', number_format($feedback->count()), $avgRating ? number_format($avgRating, 1).' / 5 average' : 'no ratings yet', 'blue', 'star'),
                self::st('Net promoter', $nps ? number_format((float) $nps, 1) : '—', $promoters.' promoters, '.$detractors.' detractors', $nps && $nps < 0 ? 'red' : 'green', 'trend'),
                self::st('Reviews in', number_format($reviews->count()), $reviewAvg ? number_format($reviewAvg, 1).' / 5 average' : 'none on this date', 'purple', 'globe'),
                self::st('Awaiting a reply', number_format($awaiting), $negative.' of them are complaints', $negative ? 'red' : 'amber', 'mail'),
                self::st('Open cases', number_format($cases->count()), $cases->where('severity', 'high')->count().' high severity', $cases->count() ? 'amber' : 'green', 'alert'),
                self::st('Compliments', number_format($feedback->where('sentiment', 'positive')->count()), 'guests who wrote in happy', 'teal', 'check'),
            ],
            'sections' => [
                self::table('reviews', 'Reviews on the business date', 'What the review sites showed and whether the property has answered.',
                    ['source' => 'Source', 'rating' => 'Rating', 'status' => 'Status', 'replied' => 'Replied'],
                    $reviews->map(fn ($r) => ['source' => ucfirst((string) $r->source), 'rating' => $r->rating ? number_format((float) $r->rating, 1) : '—',
                        'status' => ['text' => OnlineReview::STATUSES[$r->status] ?? ucfirst((string) $r->status), 'tone' => in_array($r->status, ['posted', 'ignored'], true) ? 'green' : 'blue'],
                        'replied' => $r->response_text ? ['text' => 'Yes', 'tone' => 'green'] : ['text' => 'No', 'tone' => 'red']])->all(),
                    'No review was left on this date.'),
                self::table('sources', 'Where the feedback came from', 'Score and volume by channel.',
                    ['source' => 'Channel', 'count' => 'Responses', 'avg' => 'Average'],
                    $feedback->groupBy(fn ($f) => $f->sentiment ?: 'unscored')->map(fn ($g, $s) => ['source' => ucfirst((string) $s), 'count' => number_format($g->count()),
                        'avg' => $g->whereNotNull('rating_overall')->avg('rating_overall') ? number_format($g->whereNotNull('rating_overall')->avg('rating_overall'), 1) : '—'])->values()->all(),
                    'No feedback was captured on this date.'),
                self::table('urgent', 'Cases that need an answer', 'Open recovery cases, the most severe and the soonest due first.',
                    ['case' => 'Case', 'about' => 'About', 'area' => 'Department', 'due' => 'Due', 'owner' => 'Owner'],
                    $urgent->map(fn ($c) => ['case' => $c->case_no,
                        'about' => ['text' => $c->title, 'tone' => $c->severity === 'high' ? 'red' : 'amber'],
                        'area' => $c->department ? ucfirst(str_replace('_', ' ', (string) $c->department)) : '—',
                        'due' => $c->due_at ? ['text' => $c->due_at->format('D j M H:i'), 'tone' => $c->due_at->lt($d) ? 'red' : 'slate'] : '—',
                        'owner' => $c->assignee?->name ?? 'Unassigned'])->all(),
                    'No case is open.'),
                self::table('written', 'What the guests wrote', 'The last few days of comments, unedited.',
                    ['guest' => 'Guest', 'score' => 'Score', 'mood' => 'Mood', 'said' => 'They said'],
                    $latest->map(fn ($f) => ['guest' => $f->guest_name ?: 'Guest',
                        'score' => $f->rating_overall ? number_format((float) $f->rating_overall, 1) : '—',
                        'mood' => ['text' => ucfirst((string) ($f->sentiment ?: 'unscored')),
                            'tone' => $f->sentiment === 'positive' ? 'green' : ($f->sentiment === 'negative' ? 'red' : 'slate')],
                        'said' => \Illuminate\Support\Str::limit((string) $f->comments, 90) ?: '—'])->all(),
                    'No guest has written in for three days.'),
                self::items('cases', 'Complaints still open', 'Cases being worked, and who owes the guest an answer.',
                    array_values(array_filter([
                        $negative ? self::li(number_format($negative).' negative reviews still unanswered', 'the guest is waiting', 'red') : null,
                        $cases->count() ? self::li(number_format($cases->count()).' recovery cases open', $cases->where('severity', 'high')->count().' high severity', 'amber') : null,
                        $awaiting ? self::li(number_format($awaiting).' reviews awaiting approval or posting', 'approve and reply', 'amber') : null,
                    ])),
                    'Every guest has been answered.'),
            ],
            'watch' => array_values(array_filter([
                $negative ? number_format($negative).' negative reviews have no reply' : null,
                $cases->where('severity', 'high')->count() ? number_format($cases->where('severity', 'high')->count()).' high-severity recovery cases are open' : null,
            ])),
            'todo' => [
                'Answer every review, starting with the lowest score.',
                'Close the recovery cases that have been compensated.',
                'Send the day\'s complaint themes to the heads of department.',
            ],
            'sources' => 'guest_feedback, online_reviews, feedback_cases, guests, users',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // IT & Systems
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildIt(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $commands = InterfaceCommand::whereDate('created_at', $ds)->get(['system', 'command', 'status']);
        $failed = $commands->where('status', 'failed');
        $queued = InterfaceCommand::whereIn('status', ['queued', 'sent'])->count();
        $deliveries = WebhookDelivery::whereDate('created_at', $ds)->get(['status', 'duration_ms']);
        $webFailed = $deliveries->where('status', 'failed');
        $kots = KotPrintJob::whereDate('created_at', $ds)->get(['status']);
        $audit = \App\Models\AuditDay::whereDate('business_date', $ds)->first();
        $kiosk = \App\Models\KioskSession::whereDate('created_at', $ds)->count();
        $broke = InterfaceCommand::whereDate('created_at', $ds)->whereIn('status', ['failed', 'error'])
            ->orderBy('created_at')->take(8)->get(['system', 'command', 'status', 'attempts', 'response', 'created_at']);
        $endpoints = WebhookDelivery::with('endpoint:id,name')->whereDate('created_at', $ds)
            ->where('status', 'failed')->get(['webhook_endpoint_id', 'event', 'response_code', 'attempts']);

        return [
            'headline' => [
                self::st('Interface commands', number_format($commands->count()), $failed->count().' failed', $failed->count() ? 'red' : 'green', 'link'),
                self::st('Commands waiting', number_format($queued), 'queued or sent, no answer', $queued ? 'amber' : 'green', 'clock'),
                self::st('Webhooks delivered', number_format($deliveries->count() - $webFailed->count()), $webFailed->count().' failed', $webFailed->count() ? 'red' : 'green', 'globe'),
                self::st('Kitchen tickets', number_format($kots->count()), $kots->whereIn('status', ['failed', 'skipped'])->count().' never printed', $kots->whereIn('status', ['failed', 'skipped'])->count() ? 'red' : 'green', 'print'),
                self::st('Night audit', $audit ? ucfirst($audit->status) : 'Not run', $audit ? 'business date '.$ds : 'the day has not been closed', $audit ? 'green' : 'red', 'moon'),
                self::st('Kiosk check-ins', number_format($kiosk), 'self-service sessions today', 'blue', 'qr'),
            ],
            'sections' => [
                self::table('interfaces', 'Interfaces', 'What the PMS told the locks, the PBX and the TVs today.',
                    ['system' => 'System', 'commands' => 'Commands', 'failed' => 'Failed'],
                    $commands->groupBy(fn ($c) => $c->system ?: 'other')->map(fn ($g, $s) => ['system' => ucfirst((string) $s), 'commands' => number_format($g->count()),
                        'failed' => ['text' => number_format($g->where('status', 'failed')->count()), 'tone' => $g->where('status', 'failed')->count() ? 'red' : 'green']])->values()->all(),
                    'No interface command was issued on this date.'),
                self::table('webhooks', 'Webhook deliveries', 'Outbound events and how the far end answered.',
                    ['status' => 'Result', 'count' => 'Deliveries', 'ms' => 'Average ms'],
                    $deliveries->groupBy('status')->map(fn ($g, $s) => ['status' => WebhookDelivery::STATUSES[$s][0] ?? ucfirst((string) $s), 'count' => number_format($g->count()),
                        'ms' => number_format((float) $g->avg('duration_ms'))])->values()->all(),
                    'Nothing was delivered on this date.'),
                self::table('broken', 'What failed today', 'The commands that did not get through, oldest first.',
                    ['system' => 'System', 'command' => 'Command', 'when' => 'When', 'tries' => 'Tries', 'answer' => 'What came back'],
                    $broke->map(fn ($b) => ['system' => ucfirst((string) $b->system), 'command' => $b->command,
                        'when' => ['text' => $b->created_at?->format('H:i') ?? '—', 'tone' => 'red'],
                        'tries' => number_format((int) $b->attempts),
                        'answer' => \Illuminate\Support\Str::limit((string) $b->response, 48) ?: 'no answer'])->all(),
                    'Nothing failed on this date.'),
                self::table('endpoints', 'Endpoints not answering', 'Who is not taking our updates, and what they said.',
                    ['endpoint' => 'Endpoint', 'event' => 'Event', 'code' => 'Code', 'tries' => 'Tries'],
                    $endpoints->groupBy('webhook_endpoint_id')->map(function ($g) {
                        $e = $g->first()->endpoint;

                        return ['endpoint' => $e?->name ?? 'Endpoint #'.$g->first()->webhook_endpoint_id,
                            'event' => $g->first()->event,
                            'code' => ['text' => (string) ($g->first()->response_code ?: 'none'), 'tone' => 'red'],
                            'tries' => number_format((int) $g->sum('attempts'))];
                    })->values()->take(8)->all(),
                    'Every endpoint is answering.'),
                self::items('queue', 'What needs a hand', 'Failures and queues the IT desk has to clear.',
                    array_values(array_filter([
                        $failed->count() ? self::li(number_format($failed->count()).' interface commands failed', 'retry from the interface screen', 'red') : null,
                        $webFailed->count() ? self::li(number_format($webFailed->count()).' webhook deliveries failed', 'check the endpoint and retry', 'red') : null,
                        $queued ? self::li(number_format($queued).' commands are waiting for an answer', 'check the interface PC', 'amber') : null,
                        $kots->whereIn('status', ['failed', 'skipped'])->count() ? self::li(number_format($kots->whereIn('status', ['failed', 'skipped'])->count()).' kitchen tickets were never printed', 'printer or paper', 'red') : null,
                        ! $audit ? self::li('The night audit has not run', 'nothing rolls until it does', 'red') : null,
                    ])),
                    'Everything is clean.'),
            ],
            'watch' => array_values(array_filter([
                $failed->count() ? number_format($failed->count()).' interface failures today' : null,
                $webFailed->count() ? number_format($webFailed->count()).' failed webhook deliveries' : null,
                ! $audit ? 'The night audit has not been run for this business date' : null,
            ])),
            'todo' => [
                'Clear the failed interface commands and note what caused them.',
                'Check the printers before the next service.',
                'Confirm the backup completed overnight.',
            ],
            'sources' => 'interface_commands, webhook_deliveries, webhook_endpoints, kot_print_jobs, audit_days, kiosk_sessions',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Purchasing & Stores
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildStores(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $issues = \App\Models\StoreIssue::whereDate('date', $ds)->get(['value', 'department_id']);
        $requisitions = \App\Models\Requisition::whereIn('status', ['submitted', 'approved', 'partial'])->get(['status', 'est_value', 'required_by']);
        $pending = $requisitions->where('status', 'submitted');
        $pos = \App\Models\PurchaseOrder::whereDate('po_date', $ds)->get(['total', 'status']);
        $grns = \App\Models\GoodsReceipt::whereDate('date', $ds)->get(['total']);
        $queue = \App\Models\Requisition::with('department:id,name')->whereIn('status', ['submitted', 'approved', 'partial'])
            ->orderBy('required_by')->take(8)->get(['req_no', 'department_id', 'required_by', 'est_value', 'status']);
        $deliveries = \App\Models\GoodsReceipt::with('supplier:id,name')->whereDate('date', $ds)->orderByDesc('id')->take(8)
            ->get(['grn_no', 'supplier_id', 'total', 'rejected_value']);
        $low = StockTakeService::reorderSuggestions();

        return [
            'headline' => [
                self::st('Issued today', money((float) $issues->sum('value')), number_format($issues->count()).' store issues', 'green', 'box'),
                self::st('Requisitions waiting', number_format($pending->count()), money((float) $pending->sum('est_value')).' estimated', $pending->count() ? 'amber' : 'green', 'mail'),
                self::st('At re-order level', number_format($low->count()), 'items to buy', $low->count() ? 'red' : 'green', 'alert'),
                self::st('Orders raised', number_format($pos->count()), money((float) $pos->sum('total')).' committed', 'blue', 'receipt'),
                self::st('Goods received', money((float) $grns->sum('total')), number_format($grns->count()).' GRNs', 'teal', 'truck'),
                self::st('Approved to issue', number_format($requisitions->whereIn('status', ['approved', 'partial'])->count()), 'waiting at the counter', 'purple', 'check'),
            ],
            'sections' => [
                self::table('low', 'Buy these', 'Items at or below their re-order level, with the quantity the system suggests.',
                    ['item' => 'Item', 'stock' => 'In stock', 'level' => 'Re-order at', 'suggest' => 'Suggested'],
                    $low->take(10)->map(fn ($r) => ['item' => $r->item->name, 'stock' => number_format($r->stock, 2), 'level' => number_format($r->level, 2),
                        'suggest' => ['text' => number_format($r->suggest, 2), 'tone' => 'red']])->all(),
                    'Every item is above its re-order level.'),
                self::table('queue', 'Waiting at the counter', 'Requisitions still to be issued, soonest needed first.',
                    ['req' => 'Requisition', 'dept' => 'Department', 'need' => 'Needed by', 'state' => 'Position', 'value' => 'Estimate'],
                    $queue->map(fn ($r) => ['req' => $r->req_no, 'dept' => $r->department?->name ?? '—',
                        'need' => $r->required_by?->format('D j M') ?? '—',
                        'state' => ['text' => \App\Models\Requisition::STATUSES[$r->status] ?? ucfirst($r->status),
                            'tone' => $r->status === 'submitted' ? 'amber' : 'green'],
                        'value' => money((float) $r->est_value, false)])->all(),
                    'Nothing is waiting at the counter.'),
                self::table('deliveries', 'What came in today', 'Deliveries received at the store, with anything rejected on the dock.',
                    ['grn' => 'GRN', 'supplier' => 'Supplier', 'value' => 'Value', 'rejected' => 'Rejected'],
                    $deliveries->map(fn ($g) => ['grn' => $g->grn_no, 'supplier' => $g->supplier?->name ?? '—', 'value' => money((float) $g->total, false),
                        'rejected' => ['text' => money((float) $g->rejected_value, false), 'tone' => (float) $g->rejected_value > 0.004 ? 'red' : 'slate']])->all(),
                    'No delivery was received on this date.'),
                self::table('req', 'Requisitions in the queue', 'Departments waiting on the store.',
                    ['status' => 'Status', 'count' => 'Requisitions', 'value' => 'Value'],
                    $requisitions->groupBy('status')->map(fn ($g, $s) => ['status' => \App\Models\Requisition::STATUSES[$s] ?? ucfirst($s), 'count' => number_format($g->count()),
                        'value' => money((float) $g->sum('est_value'), false)])->values()->all(),
                    'No requisition is open.'),
                self::items('store', 'The store today', 'What went out and what came in.',
                    array_values(array_filter([
                        $issues->count() ? self::li(number_format($issues->count()).' issues to departments', money((float) $issues->sum('value')), 'green') : self::li('Nothing was issued from the stores', 'check the requisitions', 'amber'),
                        $grns->count() ? self::li(number_format($grns->count()).' deliveries received', money((float) $grns->sum('total')), 'green') : null,
                        $low->count() ? self::li(number_format($low->count()).' items need a purchase order', 'raise them before service', 'red') : null,
                    ])),
                    'The store is quiet.'),
            ],
            'watch' => array_values(array_filter([
                $low->count() ? number_format($low->count()).' items are at or below re-order level' : null,
                $pending->count() ? number_format($pending->count()).' requisitions are waiting for approval' : null,
            ])),
            'todo' => [
                'Raise the purchase orders for the items at re-order level.',
                'Approve or return the requisitions in the queue.',
                'Reconcile the GRNs against the invoices before they are posted.',
            ],
            'sources' => 'store_issues, requisitions, purchase_orders, goods_receipts, stock_movements',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Transport & Fleet
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildFleet(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        // the desk's own numbers, so the briefing and the transport screen can never disagree
        $day = TransportService::dayStats($d);
        $alerts = TransportService::alerts();
        $paperDays = max(1, (int) TransportService::cfg()['paper_days']);
        $asOf = $d->isToday() ? now() : $d->copy()->endOfDay();

        $trips = TransportTrip::whereDate('scheduled_at', $ds)->get(['trip_no', 'type', 'status', 'pax', 'billing', 'amount',
            'extra_amount', 'total', 'vehicle_id', 'driver_id', 'scheduled_at', 'busy_from', 'busy_to', 'pickup_point',
            'dropoff_point', 'flight_no', 'passenger_name', 'folio_transaction_id', 'odo_start', 'odo_end']);
        $live = $trips->whereNotIn('status', ['cancelled', 'no_show']);
        $running = $live->filter(fn ($t) => in_array($t->status, TransportTrip::ACTIVE, true));
        $overdue = $live->filter(fn ($t) => in_array($t->status, array_merge(TransportTrip::ACTIVE, ['assigned']), true)
            && $t->busy_to && $t->busy_to->lt($asOf->copy()->subMinutes(30)));
        $complimentary = (float) $live->where('billing', 'complimentary')->sum('total');

        // the fleet itself
        $vehicles = Vehicle::where('is_active', true)->orderBy('code')->get();
        $expired = [];
        $due = [];
        foreach ($vehicles as $v) {
            $expired[$v->id] = $v->expiredPapers($d);
            $due[$v->id] = $v->expiringPapers($paperDays);
        }
        $paperFaults = $vehicles->filter(fn ($v) => $expired[$v->id] || $due[$v->id]);
        $inWorkshop = $vehicles->where('status', 'maintenance');
        $byVehicle = $live->whereNotNull('vehicle_id')->groupBy('vehicle_id');

        // the crew
        $drivers = Driver::where('is_active', true)->orderBy('name')->get();
        $byDriver = $live->whereNotNull('driver_id')->groupBy('driver_id');
        $licenceOut = $drivers->filter(fn ($x) => $x->licenceExpired($d));
        $licenceDue = $drivers->filter(fn ($x) => $x->licence_until && ! $x->licenceExpired($d) && $x->licence_until->lte($d->copy()->addDays($paperDays)));
        $unassigned = $live->whereIn('status', ['requested', 'confirmed']);

        // running cost and the money the trips made
        $spend = VehicleExpense::whereDate('date', $ds)->get(['kind', 'litres', 'amount', 'vehicle_id', 'vendor']);
        $spendMtd = (float) VehicleExpense::whereDate('date', '>=', $d->copy()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $ds)->sum('amount');
        $fuelLitres = (float) $spend->where('kind', 'fuel')->sum('litres');
        $km = (int) $live->sum(fn ($t) => ($t->odo_end && $t->odo_start) ? max(0, (int) $t->odo_end - (int) $t->odo_start) : 0);
        $spendDay = (float) $spend->sum('amount');
        $unposted = $live->where('billing', 'room')->where('status', 'completed')
            ->filter(fn ($t) => empty($t->folio_transaction_id) && (float) $t->total > 0);

        // workshop, inspections, incidents
        $works = VehicleMaintenanceRecord::whereIn('status', ['scheduled', 'in_progress'])
            ->get(['title', 'category', 'status', 'priority', 'due_date', 'due_odometer', 'vehicle_id', 'estimated_cost']);
        $worksLate = $works->filter(fn ($w) => $w->due_date && $w->due_date->lt($d));
        $inspections = VehicleInspection::whereDate('inspected_at', $ds)->where('result', '!=', 'pass')
            ->get(['result', 'defects', 'vehicle_id', 'inspected_at']);
        $incidents = VehicleIncident::whereIn('status', ['open', 'investigating'])
            ->get(['kind', 'severity', 'occurred_at', 'vehicle_id', 'driver_id', 'status', 'estimated_cost']);

        // self-drive rentals, which share the road and the workshop
        $rentals = CarRental::whereIn('status', ['reserved', 'picked_up'])
            ->get(['reference', 'customer_name', 'rental_vehicle_id', 'pickup_at', 'return_due_at', 'total', 'deposit', 'status']);
        $rentalsLate = $rentals->filter(fn ($r) => $r->status === 'picked_up' && $r->return_due_at && $r->return_due_at->lt($asOf));

        $name = fn ($id) => optional($vehicles->firstWhere('id', $id))->label() ?? '—';

        return [
            'headline' => [
                self::st('Trips today', number_format($day['trips']),
                    number_format($day['pax']).' passengers · '.number_format($day['pickups']).' pick-ups', 'blue', 'car'),
                self::st('On the road now', number_format($day['on_road']),
                    number_format($day['done']).' completed · '.number_format($day['lost']).' lost', $day['on_road'] ? 'amber' : 'green', 'truck'),
                self::st('Not yet assigned', number_format($day['unassigned']), 'no vehicle or driver', $day['unassigned'] ? 'red' : 'green', 'alert'),
                self::st('Trip revenue', money($day['revenue']),
                    $complimentary > 0 ? money($complimentary).' given complimentary' : 'all of it chargeable', 'green', 'money'),
                self::st('Vehicles available', number_format($vehicles->where('status', 'available')->count()),
                    number_format($inWorkshop->count()).' in the workshop of '.number_format($vehicles->count()), $inWorkshop->count() ? 'amber' : 'green', 'tool'),
                self::st('Papers and licences', number_format($alerts['papers_expired'] + $licenceOut->count()),
                    number_format($alerts['papers_due'] + $licenceDue->count()).' more due within '.$paperDays.' days',
                    ($alerts['papers_expired'] + $licenceOut->count()) ? 'red' : 'green', 'id'),
            ],
            'sections' => [
                self::table('mv', 'The day\'s movements', 'Every transfer, pick-up and drop on the business date, by type.',
                    ['type' => 'Type', 'trips' => 'Trips', 'pax' => 'Passengers', 'done' => 'Completed', 'open' => 'Still to run', 'value' => 'Revenue'],
                    $live->groupBy('type')->map(function ($g, $t) {
                        $open = $g->whereIn('status', TransportTrip::OPEN)->count();

                        return ['type' => TransportTrip::TYPES[$t] ?? ucfirst((string) $t), 'trips' => number_format($g->count()),
                            'pax' => number_format((int) $g->sum('pax')), 'done' => number_format($g->where('status', 'completed')->count()),
                            'open' => ['text' => number_format($open), 'tone' => $open ? 'amber' : 'green'],
                            'value' => money((float) $g->where('billing', '!=', 'complimentary')->sum('total'), false)];
                    })->values()->all(),
                    'No movement is scheduled for this date.'),

                self::table('board', 'The board — which car can move', 'Each vehicle as the dispatcher sees it: what it did today and whether its papers would stop it.',
                    ['vehicle' => 'Vehicle', 'type' => 'Type', 'seats' => 'Seats', 'trips' => 'Trips today', 'km' => 'Km today', 'state' => 'Papers'],
                    $vehicles->map(function ($v) use ($byVehicle, $expired, $due) {
                        $mine = $byVehicle[$v->id] ?? collect();
                        $km = (int) $mine->sum(fn ($t) => ($t->odo_end && $t->odo_start) ? max(0, (int) $t->odo_end - (int) $t->odo_start) : 0);
                        $state = $expired[$v->id] ? ['text' => implode(', ', array_keys($expired[$v->id])).' expired', 'tone' => 'red']
                            : ($due[$v->id] ? ['text' => implode(', ', array_keys($due[$v->id])).' due', 'tone' => 'amber']
                            : ['text' => 'In order', 'tone' => 'green']);

                        return ['vehicle' => $v->label().($v->status === 'maintenance' ? ' · workshop' : ''), 'type' => Vehicle::TYPES[$v->type] ?? ucfirst((string) $v->type),
                            'seats' => (string) $v->seats, 'trips' => number_format($mine->count()), 'km' => number_format($km), 'state' => $state];
                    })->take(10)->values()->all(),
                    'No vehicle is on the books.'),

                self::table('crew', 'The drivers', 'Who is on, what they drove and how long they were out — with the licence that would ground them.',
                    ['driver' => 'Driver', 'licence' => 'Licence', 'trips' => 'Trips', 'out' => 'Time out', 'state' => 'Standing'],
                    $drivers->map(function ($x) use ($byDriver) {
                        $mine = $byDriver[$x->id] ?? collect();
                        $mins = (int) $mine->sum(fn ($t) => ($t->busy_from && $t->busy_to) ? $t->busy_from->diffInMinutes($t->busy_to) : 0);
                        $state = $x->licenceExpired(now()) ? ['text' => 'Licence expired', 'tone' => 'red']
                            : (! $x->licence_until ? ['text' => 'No licence on file', 'tone' => 'amber'] : ['text' => 'In order', 'tone' => 'green']);

                        return ['driver' => $x->name, 'licence' => $x->licence_until?->format('d M Y') ?? '—',
                            'trips' => number_format($mine->count()), 'out' => $mins ? intdiv($mins, 60).'h '.str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT).'m' : '—',
                            'state' => $state];
                    })->take(10)->values()->all(),
                    'No driver is on the roster.'),

                self::table('billing', 'What the trips earned, and how it was billed', 'A room charge is money on a folio; a direct trip is cash the driver must hand in; a complimentary trip is a cost dressed as revenue.',
                    ['how' => 'Billing', 'trips' => 'Trips', 'value' => 'Value', 'note' => 'What it means'],
                    $live->groupBy('billing')->map(function ($g, $k) {
                        $pending = $k === 'room' ? $g->where('status', 'completed')->filter(fn ($t) => empty($t->folio_transaction_id) && (float) $t->total > 0)->count() : 0;

                        return ['how' => TransportTrip::BILLING[$k] ?? ucfirst((string) $k), 'trips' => number_format($g->count()),
                            'value' => money((float) $g->sum('total'), false),
                            'note' => $k === 'room' ? ($pending ? number_format($pending).' still to post at check-out' : 'posted to the guest folio')
                                : ($k === 'direct' ? 'paid to the driver or the desk' : 'never charged — carried at fare value')];
                    })->values()->all(),
                    'No trip carried a price on this date.'),

                self::table('cost', 'Fuel and upkeep today', 'What the cars cost to run on the business date, against the kilometres they actually covered.',
                    ['kind' => 'Cost', 'count' => 'Entries', 'litres' => 'Litres', 'amount' => 'Spent'],
                    collect(VehicleExpense::KINDS)
                        ->filter(fn ($label, $kind) => $spend->where('kind', $kind)->isNotEmpty())
                        ->map(function ($label, $kind) use ($spend) {
                            $g = $spend->where('kind', $kind);

                            return ['kind' => $label, 'count' => number_format($g->count()),
                                'litres' => (float) $g->sum('litres') > 0 ? number_format((float) $g->sum('litres'), 1) : '—',
                                'amount' => money((float) $g->sum('amount'), false)];
                        })->values()->all(),
                    'Nothing was spent on the fleet on this date.'),

                self::table('workshop', 'In the workshop, and what is due', 'Work orders still open, worst first — a car in the workshop cannot take the morning airport run.',
                    ['vehicle' => 'Vehicle', 'job' => 'Job', 'due' => 'Due', 'priority' => 'Priority', 'est' => 'Estimate'],
                    $works->sortBy(fn ($w) => $w->due_date?->timestamp ?? PHP_INT_MAX)->map(fn ($w) => ['vehicle' => $name($w->vehicle_id),
                        'job' => $w->title.' ('.(VehicleMaintenanceRecord::CATEGORIES[$w->category] ?? $w->category).')',
                        'due' => $w->due_date ? ['text' => $w->due_date->format('d M'), 'tone' => $w->due_date->lt($d) ? 'red' : 'slate'] : '—',
                        'priority' => ucfirst((string) $w->priority), 'est' => money((float) $w->estimated_cost, false)])->take(8)->values()->all(),
                    'Nothing is in the workshop.'),

                self::table('faults', 'Defects, incidents and failed inspections', 'The record behind every car that should not be handed to a guest.',
                    ['when' => 'When', 'what' => 'What', 'vehicle' => 'Vehicle', 'state' => 'State'],
                    $incidents->sortByDesc('occurred_at')->map(fn ($i) => ['when' => $i->occurred_at?->format('d M') ?? '—',
                        'what' => (VehicleIncident::KINDS[$i->kind] ?? ucfirst((string) $i->kind)).' · '.($i->severity ? ucfirst($i->severity) : 'minor'),
                        'vehicle' => $name($i->vehicle_id),
                        'state' => ['text' => VehicleIncident::STATUSES[$i->status] ?? $i->status, 'tone' => VehicleIncident::TONES[$i->severity] ?? 'amber']])
                        ->concat($inspections->map(fn ($i) => ['when' => $i->inspected_at?->format('d M') ?? '—',
                            'what' => 'Inspection: '.ucfirst((string) $i->result).($i->defects ? ' — '.$i->defects : ''),
                            'vehicle' => $name($i->vehicle_id), 'state' => ['text' => 'Open defect', 'tone' => 'red']]))
                        ->take(8)->values()->all(),
                    'No defect or incident is on record.'),

                self::table('rentals', 'Self-drive rentals', 'Cars out on a rental agreement, and what is due back — the same fleet, on a different promise.',
                    ['rental' => 'Agreement', 'customer' => 'Customer', 'due' => 'Due back', 'value' => 'Value', 'state' => 'State'],
                    $rentals->map(fn ($r) => ['rental' => $r->reference, 'customer' => $r->customer_name,
                        'due' => $r->return_due_at ? ['text' => $r->return_due_at->format('d M H:i'), 'tone' => $r->return_due_at->lt($asOf) ? 'red' : 'slate'] : '—',
                        'value' => money((float) $r->total, false),
                        'state' => ['text' => CarRental::STATUSES[$r->status][0] ?? $r->status, 'tone' => CarRental::STATUSES[$r->status][1] ?? 'slate']])
                        ->take(8)->values()->all(),
                    'No rental is out or booked.'),

                self::items('desk', 'The transport desk', 'What has to be fixed before the cars move.',
                    array_values(array_filter([
                        $day['unassigned'] ? self::li(number_format($day['unassigned']).' trips have no vehicle or driver', 'assign before the pickup', 'red') : null,
                        $overdue->count() ? self::li(number_format($overdue->count()).' trips are past their window', 'call the driver and close the trip', 'red') : null,
                        $paperFaults->count() ? self::li(number_format($paperFaults->count()).' vehicles have a paper expired or due', 'a lapsed permit grounds the car', 'red') : null,
                        $licenceOut->count() ? self::li(number_format($licenceOut->count()).' drivers have an expired licence', 'they cannot be assigned', 'red') : null,
                        $worksLate->count() ? self::li(number_format($worksLate->count()).' workshop jobs are past their due date', 'the car stays in the yard', 'amber') : null,
                        $incidents->count() ? self::li(number_format($incidents->count()).' incidents are still open', 'insurance cannot be claimed until they close', 'amber') : null,
                        $rentalsLate->count() ? self::li(number_format($rentalsLate->count()).' rentals are past their return time', 'the car is on someone else\'s promise', 'red') : null,
                        $unposted->count() ? self::li(number_format($unposted->count()).' room trips are not yet on a folio', money((float) $unposted->sum('total')).' posts at check-out', 'amber') : null,
                        $km > 0 && $spendDay > 0 ? self::li('The cars ran '.number_format($km).' km on '.money($spendDay).' of fuel', 'about '.money($spendDay / max(1, $km)).' a kilometre', 'slate') : null,
                    ])),
                    'The desk is clear.'),
            ],
            'watch' => array_values(array_filter([
                $day['unassigned'] ? number_format($day['unassigned']).' trips are unassigned' : null,
                $overdue->count() ? number_format($overdue->count()).' trips are past their window' : null,
                $alerts['papers_expired'] ? number_format($alerts['papers_expired']).' vehicles have an expired paper' : null,
                $licenceOut->count() ? number_format($licenceOut->count()).' drivers have an expired licence' : null,
                $worksLate->count() ? number_format($worksLate->count()).' workshop jobs are overdue' : null,
                $rentalsLate->count() ? number_format($rentalsLate->count()).' rentals are past their return time' : null,
                $incidents->count() ? number_format($incidents->count()).' vehicle incidents are open' : null,
            ])),
            'todo' => array_values(array_filter([
                $day['unassigned'] ? 'Assign the '.$day['unassigned'].' unassigned trips and tell the guests their driver.' : null,
                $paperFaults->count() ? 'Renew the papers on the '.$paperFaults->count().' vehicles that cannot legally move.' : null,
                $licenceOut->count() ? 'Take the '.$licenceOut->count().' drivers with a lapsed licence off the roster today.' : null,
                $worksLate->count() ? 'Close or firm up the '.$worksLate->count().' overdue workshop jobs.' : null,
                $rentalsLate->count() ? 'Chase the '.$rentalsLate->count().' rentals that are past their return time.' : null,
                'Check tomorrow\'s flight times against the pick-up list.',
            ])),
            'sources' => 'transport_trips, vehicles, drivers, vehicle_expenses, vehicle_maintenance_records, vehicle_inspections, vehicle_incidents, car_rentals',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Parking & Valet
    // ─────────────────────────────────────────────────────────────────────────────

    private static function buildParking(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $sessions = ParkingSession::whereDate('entered_at', $ds)->get(['status', 'fee', 'kind', 'lost_ticket', 'is_valet', 'plate', 'parking_zone_id', 'entered_at']);
        $active = ParkingSession::whereIn('status', ParkingSession::ACTIVE)->count();
        $valet = ParkingSession::whereIn('status', ['requested', 'ready'])->count();
        $zones = \App\Models\ParkingZone::where('is_active', true)->get(['id', 'name', 'capacity']);
        $zoneRows = $zones->map(function ($z) use ($sessions) {
            $here = $sessions->where('parking_zone_id', $z->id);
            $taken = $here->whereIn('status', ParkingSession::ACTIVE)->count();

            return ['zone' => $z->name, 'cars' => number_format($here->count()),
                'bays' => ['text' => number_format($taken).' / '.number_format($z->capacity), 'tone' => $taken >= $z->capacity ? 'red' : 'slate'],
                'value' => money((float) $here->where('status', 'out')->sum('fee'), false)];
        })->values();
        $buckets = [['06:00 – 09:00', 6, 9], ['09:00 – 12:00', 9, 12], ['12:00 – 15:00', 12, 15], ['15:00 – 18:00', 15, 18],
            ['18:00 – 21:00', 18, 21], ['21:00 – 06:00', 21, 30]];
        $curve = collect($buckets)->map(function ($b) use ($sessions) {
            $cars = $sessions->filter(function ($s) use ($b) {
                $hour = $s->entered_at ? $s->entered_at->hour : 0;
                $hour = $hour < 6 ? $hour + 24 : $hour;

                return $hour >= $b[1] && $hour < $b[2];
            })->count();

            return ['hour' => $b[0], 'cars' => number_format($cars)];
        })->all();
        $due = \App\Models\ParkingPermit::where('status', 'active')
            ->whereDate('valid_to', '<=', $d->copy()->addDays(30)->toDateString())
            ->orderBy('valid_to')->take(8)->get(['holder_name', 'holder_type', 'valid_to', 'fee']);
        $capacity = (int) $zones->sum('capacity');
        $paid = (float) $sessions->where('status', 'out')->sum('fee');
        $permits = \App\Models\ParkingPermit::where('status', 'active')->whereDate('valid_to', '<=', $d->copy()->addDays(30)->toDateString())->count();
        $lost = $sessions->where('lost_ticket', true)->count();

        return [
            'headline' => [
                self::st('Cars in today', number_format($sessions->count()), $active.' in the bays now', 'blue', 'car'),
                self::st('Bays in use', $capacity ? self::pct($active * 100 / $capacity) : '—', number_format($capacity).' bays in total', $capacity && $active >= $capacity ? 'red' : 'green', 'home'),
                self::st('Revenue', money($paid), 'charged on exit', 'green', 'money'),
                self::st('Valet calls', number_format($valet), 'cars requested or waiting at the door', $valet ? 'amber' : 'green', 'bell'),
                self::st('Lost tickets', number_format($lost), 'fee applied or waived', $lost ? 'amber' : 'slate', 'alert'),
                self::st('Permits expiring', number_format($permits), 'within 30 days', $permits ? 'amber' : 'green', 'id'),
            ],
            'sections' => [
                self::table('kinds', 'Who parked today', 'Guest, visitor and staff cars.',
                    ['kind' => 'Kind', 'cars' => 'Cars', 'value' => 'Charged'],
                    $sessions->groupBy('kind')->map(fn ($g, $k) => ['kind' => ParkingSession::KINDS[$k] ?? ucfirst((string) $k), 'cars' => number_format($g->count()),
                        'value' => money((float) $g->sum('fee'), false)])->values()->all(),
                    'No car was parked on this date.'),
                self::table('zones', 'The bays by zone', 'How each part of the yard is filling, and what it has charged.',
                    ['zone' => 'Zone', 'cars' => 'Cars today', 'bays' => 'In use', 'value' => 'Charged'],
                    $zoneRows->all(),
                    'No parking zone is active, so there are no bays to report on.'),
                self::table('curve', 'When they came', 'Cars by the time they entered, so the drive can be staffed for the peak.',
                    ['hour' => 'Window', 'cars' => 'Cars'], $curve,
                    'No car entered the yard on this date.'),
                self::table('permits', 'Permits falling due', 'Active permits that expire in the next thirty days, soonest first.',
                    ['holder' => 'Holder', 'type' => 'Type', 'expires' => 'Expires', 'fee' => 'Charge'],
                    $due->map(fn ($p) => ['holder' => $p->holder_name, 'type' => ucfirst((string) $p->holder_type),
                        'expires' => $p->valid_to?->format('D j M'), 'fee' => money((float) $p->fee, false)])->all(),
                    'No permit expires in the next thirty days.'),
                self::items('valet', 'The valet desk', 'What is on the drive and what the cashier has to collect.',
                    array_values(array_filter([
                        $valet ? self::li(number_format($valet).' cars requested at the door', 'run them promptly', 'amber') : null,
                        $lost ? self::li(number_format($lost).' lost-ticket fees', 'record the waiver reason', 'amber') : null,
                        $capacity ? self::li(number_format($active).' of '.number_format($capacity).' bays in use', $capacity - $active.' free', $active >= $capacity ? 'red' : 'green') : null,
                    ])),
                    'Nothing on the drive.'),
            ],
            'watch' => array_values(array_filter([
                $capacity && $active >= $capacity ? 'The car park is full' : null,
                $valet ? number_format($valet).' valet calls are outstanding' : null,
            ])),
            'todo' => [
                'Collect and bank the parking cash.',
                'Release the spaces held for arrivals tonight.',
                'Check the permits that fall due this month.',
            ],
            'sources' => 'parking_sessions, parking_zones, parking_permits',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // The executive brief — General Manager & CEO
    // ─────────────────────────────────────────────────────────────────────────────

    private static array $packCache = [];

    /** One line from a department pack, for the executive scorecard. */
    private static function packSummary(string $key, Carbon $d): array
    {
        $ck = $key.'|'.$d->toDateString();
        if (isset(self::$packCache[$ck])) {
            return self::$packCache[$ck];
        }
        $p = self::pack($key, $d);
        $top = $p['headline'][0] ?? null;

        return self::$packCache[$ck] = [
            'label' => $p['label'], 'role' => $p['role'], 'icon' => $p['icon'], 'key' => $key,
            'metric' => $top['label'] ?? '—', 'value' => $top['value'] ?? '—', 'tone' => $top['tone'] ?? 'slate',
            'watch' => $p['watch'], 'todo' => count($p['todo']),
        ];
    }

    private static function buildExec(Carbon $d, array $c): array
    {
        $ds = $d->toDateString();
        $day = $c['day'];
        $mtd = $c['mtd'];

        // Every department pack, rolled into one scorecard.
        $summaries = collect(self::PACKS)->keys()->map(fn ($k) => self::packSummary($k, $d));
        $signoffs = self::signoffs($d);
        $compliance = self::compliance($d);
        $pl = self::profit($d);
        $budget = self::againstBudget($d);

        // Revenue by the department that produced it.
        $rec = RecreationShiftService::dayReport($ds);
        $laundry = (float) LaundryOrder::whereDate('created_at', $ds)->sum('total');
        $parking = (float) ParkingSession::whereDate('exited_at', $ds)->sum('fee');
        $transport = (float) TransportTrip::whereDate('scheduled_at', $ds)->sum('total');
        $banquet = (float) BanquetEvent::whereDate('event_date', $ds)->whereNotIn('status', ['cancelled', 'lost'])->get()->sum(fn ($e) => $e->total());
        $sources = collect([
            ['source' => 'Rooms', 'amount' => (float) $day['room'], 'note' => number_format($day['sold']).' room nights'],
            ['source' => 'Food & beverage', 'amount' => (float) $day['fnb'], 'note' => number_format($day['covers']).' bills'],
            ['source' => 'Banquet & events', 'amount' => $banquet, 'note' => BanquetEvent::whereDate('event_date', $ds)->count().' events'],
            ['source' => 'Spa & recreation', 'amount' => (float) $rec['net'], 'note' => $rec['sales'].' sales'],
            ['source' => 'Laundry', 'amount' => $laundry, 'note' => 'guest laundry'],
            ['source' => 'Parking', 'amount' => $parking, 'note' => 'cars charged on exit'],
            ['source' => 'Transport', 'amount' => $transport, 'note' => 'trips billed'],
            ['source' => 'Other revenue', 'amount' => (float) $day['other'], 'note' => 'telephone, misc.'],
        ])->filter(fn ($r) => abs($r['amount']) > 0.004)->sortByDesc('amount')->values();
        $deptTotal = (float) $sources->sum('amount');

        // Cash: everything that came in, plus the drawers that were counted.
        $folioPay = FolioTransaction::with('paymentMode')->where('is_void', false)->whereDate('business_date', $ds)->where('type', 'payment')->get(['payment_mode_id', 'amount']);
        $posPay = PosPayment::with('paymentMode')->where('kind', 'payment')->whereDate('business_date', $ds)->get(['payment_mode_id', 'amount']);
        $modes = $folioPay->map(fn ($p) => ['payment_mode_id' => $p->payment_mode_id, 'amount' => (float) $p->amount, 'paymentMode' => $p->paymentMode])
            ->concat($posPay->map(fn ($p) => ['payment_mode_id' => $p->payment_mode_id, 'amount' => (float) $p->amount, 'paymentMode' => $p->paymentMode]))
            ->groupBy(fn ($p) => $p['paymentMode']?->name ?? 'Unspecified')
            ->map(fn ($g, $n) => ['mode' => $n, 'count' => $g->count(), 'amount' => (float) $g->sum('amount')])->sortByDesc('amount')->values();
        $refunds = (float) FolioTransaction::where('is_void', false)->whereDate('business_date', $ds)->where('type', 'refund')->sum('amount');
        $shifts = \App\Models\CashierShift::whereDate('business_date', $ds)->where('status', 'closed')->get(['variance', 'counted_cash', 'user_id']);
        $variance = (float) $shifts->sum('variance');

        // Receivables
        $ageing = ManagementReportService::arAgeing($d);
        $ar = ['b0' => (float) $ageing->sum('b0'), 'b30' => (float) $ageing->sum('b30'), 'b60' => (float) $ageing->sum('b60'), 'b90' => (float) $ageing->sum('b90'), 'out' => (float) $ageing->sum('outstanding')];
        $topDebtors = $ageing->sortByDesc('outstanding')->take(5);

        // People
        // attendances.status is a single letter: P present, A absent, L leave, H half day, WO week off.
        $attendance = Attendance::whereDate('date', $ds)->get(['status']);
        $present = $attendance->whereIn('status', ['P', 'H'])->count();
        $absent = $attendance->where('status', 'A')->count();
        $onLeave = LeaveRequest::where('status', 'approved')->whereDate('date_from', '<=', $ds)->whereDate('date_to', '>=', $ds)->count();
        $leaveQueue = LeaveRequest::where('status', 'pending')->count();

        // Operations
        $oo = $c['ooo'];
        $jobs = MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->count();
        $jobsOver = MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->whereDate('due_date', '<', $ds)->count();
        $incidents = SecurityIncident::whereIn('status', ['open', 'investigating'])->count();
        $requests = GuestRequest::whereIn('status', GuestRequest::OPEN)->count();
        $dirty = $c['dirty'];

        // Commercial
        $pipeline = (float) SalesOpportunity::whereIn('stage', SalesOpportunity::OPEN)->get()->sum(fn ($o) => (float) $o->rooms_revenue + (float) $o->fb_revenue + (float) $o->other_revenue);
        $otb = collect(range(1, 7))->avg(function ($i) use ($d, $c) {
            $night = $d->copy()->addDay($i);
            $rooms = ReservationRoom::whereIn('status', ['reserved', 'checked_in'])->whereDate('arrival_date', '<=', $night->toDateString())->whereDate('departure_date', '>', $night->toDateString())->count();

            return $rooms * 100 / max(1, $c['rooms_total'] - $c['ooo']);
        });
        $pickup = Reservation::whereDate('created_at', $ds)->count();

        // Experience and risk
        $feedback = GuestFeedback::whereDate('created_at', $ds)->get(['rating_overall', 'nps']);
        $reviews = OnlineReview::whereDate('review_date', $ds)->get(['rating']);
        $unanswered = OnlineReview::whereIn('status', ['new', 'draft', 'pending'])->count();
        $findings = AuditFinding::where('status', 'open')->count();
        $pendingAuth = \App\Models\RevenueAuthorisation::where('status', 'pending')->count();
        $failedInterfaces = InterfaceCommand::whereDate('created_at', $ds)->where('status', 'failed')->count();
        $lowStock = StockTakeService::reorderSuggestions()->count();
        $auditDay = \App\Models\AuditDay::whereDate('business_date', $ds)->first();

        $trend = \App\Models\NightAudit::whereDate('business_date', '<=', $ds)->whereDate('business_date', '>=', $d->copy()->subDays(6)->toDateString())
            ->orderBy('business_date')->get(['business_date', 'rooms_occupied', 'rooms_total', 'occupancy_pct', 'adr', 'revpar', 'room_revenue', 'collections']);

        $riskItems = collect([
            $findings ? [number_format($findings).' income-audit findings are open', 'Finance', 'red'] : null,
            $pendingAuth ? [number_format($pendingAuth).' discounts or comps await authorisation', 'Revenue', 'amber'] : null,
            $jobsOver ? [number_format($jobsOver).' maintenance jobs are past due', 'Engineering', 'red'] : null,
            $incidents ? [number_format($incidents).' security incidents are open', 'Security', 'red'] : null,
            $unanswered ? [number_format($unanswered).' guest reviews have no reply', 'Guest Experience', 'amber'] : null,
            $failedInterfaces ? [number_format($failedInterfaces).' interface commands failed today', 'IT', 'red'] : null,
            $lowStock ? [number_format($lowStock).' items are at re-order level', 'Stores', 'amber'] : null,
            $absent ? [number_format($absent).' staff did not report for duty', 'HR', 'amber'] : null,
            $leaveQueue ? [number_format($leaveQueue).' leave requests await a decision', 'HR', 'slate'] : null,
            $dirty ? [number_format($dirty).' rooms are not ready for sale', 'Housekeeping', 'amber'] : null,
            ! $auditDay ? ['The night audit has not been run for this business date', 'Front Office', 'red'] : null,
        ])->filter()->values();

        $headline = [
            self::st('Occupancy', self::pct($day['occ']), self::cmp($day['occ'], $c['lw']['occ'], 'pct'), self::tone(self::cmp($day['occ'], $c['lw']['occ'], 'pct')), 'bed'),
            self::st('ADR', money($day['adr']), self::cmp($day['adr'], $c['lw']['adr'], 'money'), self::tone(self::cmp($day['adr'], $c['lw']['adr'], 'money')), 'money'),
            self::st('RevPAR', money($day['revpar']), self::cmp($day['revpar'], $c['lw']['revpar'], 'money'), self::tone(self::cmp($day['revpar'], $c['lw']['revpar'], 'money')), 'chart'),
            self::st('Net revenue today', money($day['net']), self::cmp($day['net'], $c['lw']['net'], 'money'), self::tone(self::cmp($day['net'], $c['lw']['net'], 'money')), 'trend'),
            self::st('Month to date', money($mtd['net']), self::cmp($mtd['net'], $c['pm_mtd']['net'], 'money'), self::tone(self::cmp($mtd['net'], $c['pm_mtd']['net'], 'money')), 'layers'),
            self::st('Collected today', money((float) $modes->sum('amount') - $refunds), number_format($modes->count()).' receipts', 'green', 'wallet'),
            self::st('Receivables', money($ar['out']), money($ar['b90'] + $ar['b60']).' older than 30 days', ($ar['b60'] + $ar['b90']) > 0 ? 'amber' : 'green', 'receipt'),
            self::st('Guest score', $feedback->whereNotNull('rating_overall')->avg('rating_overall') ? number_format((float) $feedback->whereNotNull('rating_overall')->avg('rating_overall'), 1).' / 5' : ($reviews->avg('rating') ? number_format((float) $reviews->avg('rating'), 1).' / 5' : '—'), number_format($feedback->count() + $reviews->count()).' responses today', 'purple', 'star'),
        ];

        $sections = [
            self::table('flash', 'The numbers, day · month · year', 'The manager\'s flash report for the business date, cut from source transactions.',
                ['metric' => 'Metric', 'day' => 'Today', 'mtd' => 'Month to date', 'ytd' => 'Year to date'],
                $c['flash_rows']->map(fn ($r) => ['metric' => $r['metric'], 'day' => $r['day'], 'mtd' => $r['mtd'], 'ytd' => $r['ytd']])->all(),
                'No figure has been posted yet.', ['full' => true]),

            self::table('depts', 'Department scorecard', 'Each head\'s day in one line, and whether they have signed for it.',
                ['dept' => 'Department', 'metric' => 'Headline', 'value' => 'Today', 'watch' => 'Open', 'signed' => 'Signed off'],
                $summaries->map(function ($s) use ($signoffs) {
                    $sign = $signoffs[$s['key']] ?? null;

                    return ['dept' => $s['label'], 'metric' => $s['metric'],
                        'value' => ['text' => $s['value'], 'tone' => $s['tone']],
                        'watch' => ['text' => (string) count($s['watch']), 'tone' => count($s['watch']) ? 'amber' : 'green'],
                        'signed' => $sign ? ['text' => ($sign->user?->name ?? 'Signed').' · '.$sign->signed_at?->format('H:i'), 'tone' => 'green'] : ['text' => 'Not yet', 'tone' => 'red']];
                })->all(),
                'No department has filed a briefing.', ['full' => true]),

            self::table('compliance', 'Sign-off compliance, last seven business dates', 'Whether each head has signed their briefing over the week, not only today.',
                ['dept' => 'Department', 'week' => 'Days signed', 'last' => 'Last signed', 'state' => 'Standing'],
                $compliance->map(function ($c) {
                    $all = $c['signed'] === $c['days'];
                    $none = $c['signed'] === 0;

                    return [
                        'dept' => $c['label'],
                        'week' => ['text' => $c['signed'].' / '.$c['days'], 'tone' => $all ? 'green' : ($none ? 'red' : 'amber')],
                        'last' => $c['last'] ? $c['last']->format('D j M') : ['text' => 'Never', 'tone' => 'red'],
                        'state' => $all ? ['text' => 'Every day', 'tone' => 'green']
                            : ($none ? ['text' => 'Never signed', 'tone' => 'red']
                            : ($c['streak'] > 0 ? ['text' => $c['streak'].' days running', 'tone' => 'amber'] : ['text' => 'Lapsed', 'tone' => 'red'])),
                    ];
                })->all(),
                'No head has signed a briefing in the last week.', ['full' => true]),

            self::table('revenue', 'Where the revenue came from', 'Every revenue-producing department on the business date, net of tax.',
                ['source' => 'Department', 'amount' => 'Revenue', 'share' => 'Share', 'note' => 'Volume'],
                $sources->map(fn ($s) => ['source' => $s['source'], 'amount' => money($s['amount'], false),
                    'share' => $deptTotal ? number_format($s['amount'] * 100 / $deptTotal, 1).' %' : '—', 'note' => $s['note']])->all(),
                'No revenue has been posted today.'),

            self::plSection($pl),

            self::budgetSection($budget, $pl['fy']),

            self::table('cash', 'Cash and banking', 'What came in, how, and whether the drawers balanced.',
                ['mode' => 'Mode', 'count' => 'Receipts', 'amount' => 'Amount'],
                $modes->map(fn ($m) => ['mode' => $m['mode'], 'count' => number_format($m['count']), 'amount' => money($m['amount'], false)])->all(),
                'No money was taken on this date.'),

            self::table('trend', 'The last seven business dates', 'Occupancy and revenue as recorded by the night audit.',
                ['date' => 'Business date', 'rooms' => 'Rooms', 'occ' => 'Occupancy', 'adr' => 'ADR', 'revpar' => 'RevPAR', 'revenue' => 'Room revenue', 'collected' => 'Collected'],
                $trend->map(fn ($n) => ['date' => $n->business_date->format('D j M'), 'rooms' => number_format($n->rooms_occupied).' / '.number_format($n->rooms_total),
                    'occ' => self::pct($n->occupancy_pct), 'adr' => money($n->adr, false), 'revpar' => money($n->revpar, false),
                    'revenue' => money($n->room_revenue, false), 'collected' => money($n->collections, false)])->all(),
                'No night audit has been run in the last week.'),

            self::table('ar', 'Receivables', 'City-ledger balances still open, with the largest accounts.',
                ['account' => 'Debtor', 'b0' => 'Current', 'b30' => '1–30 d', 'b60' => '31–60 d', 'b90' => '60 d +', 'total' => 'Outstanding'],
                collect([['account' => 'All accounts', 'b0' => money($ar['b0'], false), 'b30' => money($ar['b30'], false), 'b60' => money($ar['b60'], false),
                    'b90' => ['text' => money($ar['b90'], false), 'tone' => $ar['b90'] > 0 ? 'red' : 'green'], 'total' => money($ar['out'], false)]])
                    ->concat($topDebtors->map(fn ($r) => ['account' => $r['company'], 'b0' => money($r['b0'], false), 'b30' => money($r['b30'], false),
                        'b60' => money($r['b60'], false), 'b90' => ['text' => money($r['b90'], false), 'tone' => $r['b90'] > 0 ? 'red' : 'slate'],
                        'total' => money($r['outstanding'], false)]))->all(),
                'Nothing is owed by any company.'),

            self::stats('risk', 'What needs the GM today', 'Open items that carry money, safety or reputation.',
                [
                    self::st('Drawer variance', money($variance), number_format($shifts->count()).' drawers counted and closed', abs($variance) > 0.004 ? 'red' : 'green', 'wallet'),
                    self::st('Open audit findings', number_format($findings), $auditDay ? 'audit day '.$auditDay->status : 'no audit day', $findings ? 'red' : 'green', 'alert'),
                    self::st('Maintenance open', number_format($jobs), $jobsOver.' past due', $jobsOver ? 'red' : 'amber', 'tool'),
                    self::st('Rooms out / dirty', number_format($oo).' / '.number_format($dirty), 'cannot be sold as they stand', ($oo || $dirty) ? 'amber' : 'green', 'bed'),
                    self::st('Guest requests open', number_format($requests), 'across the property', $requests ? 'amber' : 'green', 'message'),
                    self::st('Staff on duty', number_format($present), $absent.' absent, '.$onLeave.' on leave', $absent ? 'amber' : 'green', 'users'),
                ]),

            self::items('actions', 'The day\'s open items', 'Pulled from every head of department\'s briefing, worst first.',
                $riskItems->take(10)->map(fn ($r) => self::li($r[0], $r[1], $r[2]))->all(),
                'Nothing is outstanding across the property.'),

            self::table('signoff', 'Who has signed for their numbers', 'Each head confirms their briefing before the GM reads it.',
                ['dept' => 'Department', 'head' => 'Head', 'by' => 'Signed by', 'at' => 'At', 'note' => 'Note'],
                $summaries->map(function ($s) use ($signoffs) {
                    $sign = $signoffs[$s['key']] ?? null;

                    return ['dept' => $s['label'], 'head' => $s['role'],
                        'by' => $sign ? ($sign->user?->name ?? '—') : ['text' => 'Not signed', 'tone' => 'red'],
                        'at' => $sign?->signed_at?->format('H:i') ?? '—', 'note' => $sign?->note ?: '—'];
                })->all(),
                'No head has signed today.', ['full' => true]),
        ];

        return [
            'headline' => $headline,
            'sections' => $sections,
            'watch' => $riskItems->where(fn ($r) => $r[2] === 'red')->map(fn ($r) => $r[0])->values()->all(),
            'todo' => [
                'Read the departments with items in red before the morning meeting.',
                'Chase the heads who have not signed their briefing.',
                'Sign off the day once the night audit has run.',
            ],
            'signed_count' => $signoffs->count(),
            'pack_count' => count(self::PACKS),
            'sources' => 'night_audits (flash and the seven-day trend), folio_transactions, pos_orders, pos_payments, cashier_shifts, gl_lines (the profit and loss), budgets and budget_lines (the month against budget), city-ledger ageing, attendances, leave_requests, maintenance_requests, security_incidents, guest_requests, sales_opportunities, guest_feedback, online_reviews, interface_commands, stock re-order levels, audit_findings, revenue_authorisations, audit_days, and every department pack above',
            'departments' => $summaries->all(),
            'compliance' => $compliance->all(),
            'pl' => ['fy' => $pl['fy'], 'day' => $pl['day'], 'mtd' => $pl['mtd'], 'ytd' => $pl['ytd']],
            'budget' => $budget,
            'otb7' => $otb,
            'pipeline' => $pipeline,
            'pickup' => $pickup,
            'ar' => $ar,
            'modes' => $modes->all(),
            'variance' => $variance,
        ];
    }
}
