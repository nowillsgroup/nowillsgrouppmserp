<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\KotPrintJob;
use App\Models\KotPrinter;
use App\Models\Company;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Driver;
use App\Models\Guest;
use App\Models\Outlet;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\PosTable;
use App\Models\ReservationRoom;
use App\Services\ActivityLogger;
use App\Services\DepartmentShiftService;
use App\Services\FolioService;
use App\Services\KotPrintService;
use App\Services\PosMenuVariantService;
use App\Services\PosModifierService;
use App\Services\TaxService;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function dashboard(Request $request)
    {
        $businessDate = bdate()->toDateString();
        $parseDate = static function ($value, string $fallback): \Illuminate\Support\Carbon {
            $value = is_string($value) ? $value : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                return \Illuminate\Support\Carbon::parse($value)->startOfDay();
            }

            return \Illuminate\Support\Carbon::parse($fallback)->startOfDay();
        };
        $to = $parseDate($request->query('to'), $businessDate);
        $presetDays = $request->query('range_days');
        $from = is_string($presetDays) && in_array($presetDays, ['1', '7', '30'], true)
            ? $to->copy()->subDays((int) $presetDays - 1)
            : $parseDate($request->query('from'), \Illuminate\Support\Carbon::parse($businessDate)->subDays(2)->toDateString());
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }
        // Keep daily charts readable and dashboard queries bounded to at most 31 days.
        if ($from->diffInDays($to) > 30) {
            $from = $to->copy()->subDays(30);
        }
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $dateRange = [$fromDate, $toDate];
        $periodDays = $from->diffInDays($to) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($periodDays - 1)->startOfDay();
        $previousDateRange = [$previousFrom->toDateString(), $previousTo->toDateString()];

        $outlets = Outlet::orderBy('name')->get();
        $outletQuery = $request->query('outlet');
        $outletId = is_string($outletQuery) && preg_match('/^\d+$/', $outletQuery) ? (int) $outletQuery : null;
        $selectedOutlet = $outletId ? $outlets->firstWhere('id', $outletId) : null;
        $ordersForRange = fn (array $range) => PosOrder::query()->whereBetween('business_date', $range)
            ->where(fn ($q) => $q->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
            ->when($selectedOutlet, fn ($q) => $q->where('outlet_id', $selectedOutlet->id));
        $orders = $ordersForRange($dateRange);
        $previousOrders = $ordersForRange($previousDateRange);
        $summaryQuery = static fn ($query) => (clone $query)->reorder()->selectRaw(
            "SUM(CASE WHEN status IN ('open', 'billed', 'settled') THEN 1 ELSE 0 END) as order_count, " .
            "SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) as cancelled_count, " .
            "COALESCE(SUM(CASE WHEN status = 'settled' THEN total ELSE 0 END), 0) as total_sales, " .
            "COALESCE(SUM(CASE WHEN status = 'settled' THEN discount ELSE 0 END), 0) as discounts, " .
            "SUM(CASE WHEN status = 'settled' AND discount > 0 THEN 1 ELSE 0 END) as discounted_invoices"
        )->first();
        $summary = $summaryQuery($orders);
        $previousSummary = $summaryQuery($previousOrders);

        $complimentaryChecksFor = function (array $range) use ($selectedOutlet) {
            $itemCompOrderIds = PosOrderItem::query()->where('is_complimentary', true)->where('is_cancelled', false)
                ->whereHas('order', fn ($q) => $q->whereBetween('business_date', $range)->where('status', 'settled')
                    ->where(fn ($w) => $w->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                    ->when($selectedOutlet, fn ($w) => $w->where('outlet_id', $selectedOutlet->id)))
                ->selectRaw('pos_order_id as order_id');
            $complimentaryBillIds = PosOrder::query()->whereBetween('business_date', $range)->where('status', 'settled')
                ->where(fn ($q) => $q->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                ->when($selectedOutlet, fn ($q) => $q->where('outlet_id', $selectedOutlet->id))
                ->where('settlement', 'complimentary')->selectRaw('id as order_id');

            return DB::query()->fromSub($complimentaryBillIds->union($itemCompOrderIds), 'complimentary_orders')->count('order_id');
        };
        $complimentaryChecks = $complimentaryChecksFor($dateRange);
        $previousComplimentaryChecks = $complimentaryChecksFor($previousDateRange);

        $trendRows = PosOrder::query()->whereBetween('business_date', $dateRange)->where('status', 'settled')
            ->where(fn ($q) => $q->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
            ->when($selectedOutlet, fn ($q) => $q->where('outlet_id', $selectedOutlet->id))
            ->reorder()->selectRaw('business_date, outlet_id, SUM(total) as sales')
            ->groupBy('business_date', 'outlet_id')->get();
        $trendLookup = $trendRows->keyBy(fn ($row) => \Illuminate\Support\Carbon::parse($row->business_date)->toDateString().'|'.$row->outlet_id);
        $palette = ['#5377ef', '#27b899', '#f59e0b', '#9b59b6', '#ec5367', '#268bd2', '#16a085', '#e67e22', '#64748b', '#be185d'];
        $salesOutletIds = $trendRows->pluck('outlet_id')->unique()->values();
        $chartOutlets = ($selectedOutlet ? collect([$selectedOutlet]) : $outlets->whereIn('id', $salesOutletIds)->values())
            ->values()->map(fn ($outlet, $index) => ['id' => $outlet->id, 'name' => $outlet->name, 'color' => $palette[$index % count($palette)]]);
        $dailyTrend = collect();
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $day = $cursor->toDateString();
            $segments = $chartOutlets->map(function ($outlet) use ($trendLookup, $day) {
                $row = $trendLookup->get($day.'|'.$outlet['id']);

                return $outlet + ['sales' => (float) ($row->sales ?? 0)];
            });
            $dailyTrend->push(['date' => $day, 'label' => $cursor->format('d M'), 'total' => (float) $segments->sum('sales'), 'segments' => $segments]);
            $cursor->addDay();
        }
        $maxDailySales = max(0.0, (float) $dailyTrend->max('total'));
        $dailyTrend = $dailyTrend->map(function ($day) use ($maxDailySales) {
            $day['segments'] = $day['segments']->map(fn ($segment) => $segment + [
                'height' => $maxDailySales > 0 ? round($segment['sales'] / $maxDailySales * 100, 2) : 0,
            ]);

            return $day;
        });
        $currencySymbol = \App\Support\Tenant::hotel()?->currency_symbol ?? 'GH₵';
        $trendAxis = collect([1, .75, .5, .25, 0])->map(function ($factor) use ($maxDailySales, $currencySymbol) {
            $amount = $maxDailySales * $factor;
            $label = $amount >= 999500 ? rtrim(rtrim(number_format($amount / 1000000, 1), '0'), '.').'m'
                : ($amount >= 1000 ? rtrim(rtrim(number_format($amount / 1000, $amount >= 10000 ? 0 : 1), '0'), '.').'k' : number_format($amount, 0));

            return $currencySymbol.' '.$label;
        });

        $typeRows = (clone $orders)->where('status', 'settled')->reorder()
            ->selectRaw('order_type, COUNT(*) as checks, SUM(total) as sales')->groupBy('order_type')->get()->keyBy('order_type');
        $typePalette = ['dine_in' => '#f28c28', 'room_service' => '#25bd9a', 'delivery' => '#e9535c', 'takeaway' => '#5377ef'];
        $typeLabels = ['dine_in' => 'Dine-in', 'room_service' => 'Room service', 'delivery' => 'Delivery', 'takeaway' => 'Take Away'];
        $typeTotal = (float) $typeRows->sum('sales');
        $orderTypes = collect($typeLabels)->map(function ($label, $type) use ($typeRows, $typeTotal, $typePalette) {
            $row = $typeRows->get($type);
            $sales = (float) ($row->sales ?? 0);

            return ['type' => $type, 'label' => $label, 'sales' => $sales, 'checks' => (int) ($row->checks ?? 0),
                'percent' => $typeTotal > 0 ? round($sales / $typeTotal * 100, 2) : 0, 'color' => $typePalette[$type]];
        })->filter(fn ($type) => $type['sales'] > 0)->values();
        $otherTypeRows = $typeRows->except(array_keys($typeLabels));
        if ($otherTypeRows->isNotEmpty()) {
            $otherSales = (float) $otherTypeRows->sum('sales');
            if ($otherSales > 0) {
                $orderTypes->push(['type' => 'other', 'label' => 'Other', 'sales' => $otherSales,
                    'checks' => (int) $otherTypeRows->sum('checks'),
                    'percent' => $typeTotal > 0 ? round($otherSales / $typeTotal * 100, 2) : 0, 'color' => '#64748b']);
            }
        }
        $donutStops = [];
        $donutOffset = 0.0;
        foreach ($orderTypes as $type) {
            if ($type['percent'] > 0) {
                $donutEnd = min(100, $donutOffset + $type['percent']);
                $donutStops[] = $type['color'].' '.$donutOffset.'% '.$donutEnd.'%';
                $donutOffset = $donutEnd;
            }
        }
        $donutStyle = $donutStops ? 'conic-gradient('.implode(', ', $donutStops).')' : 'conic-gradient(#e7ebf0 0 100%)';

        $paymentAggregates = PosPayment::query()->whereBetween('business_date', $dateRange)->whereIn('kind', ['payment', 'refund'])
            ->whereDoesntHave('paymentMode', fn ($q) => $q->where('type', 'city_ledger'))
            ->whereHas('order', fn ($q) => $q->whereBetween('business_date', $dateRange)
                ->where(fn ($w) => $w->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                ->when($selectedOutlet, fn ($w) => $w->where('outlet_id', $selectedOutlet->id)))
            ->selectRaw("payment_mode_id, SUM(CASE WHEN kind = 'refund' THEN -amount ELSE amount END) as net_amount, COUNT(DISTINCT pos_order_id) as check_count")
            ->groupBy('payment_mode_id')->with('paymentMode')->get();
        $collections = (float) $paymentAggregates->sum('net_amount');
        $previousCollections = (float) (PosPayment::query()->whereBetween('business_date', $previousDateRange)->whereIn('kind', ['payment', 'refund'])
            ->whereDoesntHave('paymentMode', fn ($q) => $q->where('type', 'city_ledger'))
            ->whereHas('order', fn ($q) => $q->whereBetween('business_date', $previousDateRange)
                ->where(fn ($w) => $w->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                ->when($selectedOutlet, fn ($w) => $w->where('outlet_id', $selectedOutlet->id)))
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'refund' THEN -amount ELSE amount END), 0) as net_amount")
            ->first()?->net_amount ?? 0);
        $paymentMix = $paymentAggregates->map(fn ($payment) => ['id' => $payment->payment_mode_id,
            'name' => $payment->paymentMode?->name ?: 'Other / legacy', 'amount' => (float) $payment->net_amount,
            'checks' => (int) $payment->check_count])
            ->filter(fn ($payment) => $payment['amount'] > 0.005)->sortByDesc('amount')->take(12)->values();
        $maxPayment = max(1, (float) $paymentMix->max('amount'));
        $paymentMix = $paymentMix->map(fn ($payment) => $payment + ['width' => max(3, (int) round($payment['amount'] / $maxPayment * 100))]);

        $settledOrderIds = (clone $orders)->where('status', 'settled')->select('id');
        $itemSalesQuery = DB::table('pos_order_items as poi')->whereIn('poi.pos_order_id', $settledOrderIds)
            ->where('poi.is_cancelled', false)->where('poi.is_pending', false)->where('poi.is_complimentary', false)->where('poi.amount', '>', 0);
        $topItems = (clone $itemSalesQuery)->selectRaw('poi.item_name, SUM(poi.qty) as qty, SUM(poi.amount) as sales')
            ->groupBy('poi.item_name')->orderByDesc('sales')->orderBy('poi.item_name')->limit(5)->get();
        $topItemNames = $topItems->pluck('item_name');
        $lowItems = (clone $itemSalesQuery)->when($topItemNames->isNotEmpty(), fn ($q) => $q->whereNotIn('poi.item_name', $topItemNames))
            ->selectRaw('poi.item_name, SUM(poi.qty) as qty, SUM(poi.amount) as sales')
            ->groupBy('poi.item_name')->orderBy('sales')->orderBy('poi.item_name')->limit(5)->get();

        $turnaroundForRange = function (array $range) use ($selectedOutlet) {
            $seconds = 0.0;
            $count = 0;
            PosOrderItem::query()->where('is_cancelled', false)->where('is_pending', false)->whereNotNull('created_at')->whereNotNull('served_at')
                ->whereColumn('served_at', '>=', 'created_at')
                ->whereHas('order', fn ($q) => $q->whereBetween('business_date', $range)
                    ->where(fn ($w) => $w->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                    ->when($selectedOutlet, fn ($w) => $w->where('outlet_id', $selectedOutlet->id)))
                ->select(['id', 'created_at', 'served_at'])->chunkById(1000, function ($items) use (&$seconds, &$count) {
                    foreach ($items as $item) {
                        $seconds += $item->created_at->diffInSeconds($item->served_at);
                        $count++;
                    }
                });

            return ['count' => $count, 'minutes' => round($count ? $seconds / $count / 60 : 0, 2)];
        };
        $turnaround = $turnaroundForRange($dateRange);
        $previousTurnaround = $turnaroundForRange($previousDateRange);
        $turnaroundCount = $turnaround['count'];
        $turnaroundMinutes = $turnaround['minutes'];

        $rangeOrderIds = (clone $orders)->select('id');
        $rangePaymentIds = PosPayment::query()->whereBetween('business_date', $dateRange)
            ->whereHas('order', fn ($q) => $q->whereBetween('business_date', $dateRange)
                ->where(fn ($w) => $w->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                ->when($selectedOutlet, fn ($w) => $w->where('outlet_id', $selectedOutlet->id)))->select('id');
        $controlCounts = ActivityLog::query()->where('module', 'pos')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereIn('action', ['void', 'cancel_item', 'comp_item', 'refund'])
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', 'PosOrder')->whereIn('subject_id', $rangeOrderIds))
                ->orWhere(fn ($w) => $w->where('subject_type', 'PosPayment')->whereIn('subject_id', $rangePaymentIds)))
            ->selectRaw('action, COUNT(*) as events')->groupBy('action')->pluck('events', 'action');
        $revenueControls = collect([
            ['action' => 'void', 'label' => 'Bills voided', 'icon' => 'trash', 'search' => 'Voided order'],
            ['action' => 'cancel_item', 'label' => 'Order items removed', 'icon' => 'x', 'search' => 'Cancelled'],
            ['action' => 'comp_item', 'label' => 'Complimentary items', 'icon' => 'cup', 'search' => 'Complimentary'],
            ['action' => 'refund', 'label' => 'Refunds recorded', 'icon' => 'refresh', 'search' => 'Refunded'],
        ])->map(fn ($control) => $control + ['count' => (int) ($controlCounts->get($control['action']) ?? 0)]);

        $percentChange = static function (float $current, float $previous): ?float {
            if (abs($previous) < 0.00001) {
                return abs($current) < 0.00001 ? 0.0 : null;
            }

            return round(($current - $previous) / abs($previous) * 100, 1);
        };
        $kpiChanges = [
            'sales' => $percentChange((float) ($summary->total_sales ?? 0), (float) ($previousSummary->total_sales ?? 0)),
            'collections' => $percentChange($collections, $previousCollections),
            'orders' => $percentChange((float) ($summary->order_count ?? 0), (float) ($previousSummary->order_count ?? 0)),
            'complimentary' => $percentChange((float) $complimentaryChecks, (float) $previousComplimentaryChecks),
            'turnaround' => $turnaroundCount > 0 && $previousTurnaround['count'] > 0
                ? $percentChange((float) $turnaroundMinutes, (float) $previousTurnaround['minutes'])
                : null,
            'discounts' => $percentChange((float) ($summary->discounts ?? 0), (float) ($previousSummary->discounts ?? 0)),
        ];

        // Leakage-watch comparisons use the same-length immediately preceding period.
        $leakageCountsFor = function ($periodFrom, $periodTo) use ($selectedOutlet) {
            $periodOrderIds = PosOrder::query()
                ->where(fn ($q) => $q->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                ->when($selectedOutlet, fn ($q) => $q->where('outlet_id', $selectedOutlet->id))
                ->select('id');

            return ActivityLog::query()->where('module', 'pos')
                ->whereBetween('created_at', [$periodFrom->copy()->startOfDay(), $periodTo->copy()->endOfDay()])
                ->where('subject_type', 'PosOrder')->whereIn('subject_id', $periodOrderIds)
                ->whereIn('action', ['cancel_item', 'invoice_reprint', 'void', 'comp_item', 'comp_undo', 'invoice_delete'])
                ->selectRaw('action, COUNT(*) as events')->groupBy('action')->pluck('events', 'action');
        };
        $currentLeakageCounts = $leakageCountsFor($from, $to);
        $previousLeakageCounts = $leakageCountsFor($previousFrom, $previousTo);
        $leakageWatch = collect([
            ['key' => 'items_removed', 'label' => 'Items removed', 'icon' => 'minus', 'actions' => ['cancel_item'], 'search' => 'Cancelled'],
            ['key' => 'invoices_reprinted', 'label' => 'Invoices re-printed', 'icon' => 'print', 'actions' => ['invoice_reprint'], 'search' => 'Reprinted POS invoice'],
            ['key' => 'orders_cancelled', 'label' => 'Orders cancelled', 'icon' => 'x', 'actions' => ['void'], 'search' => 'Voided order'],
            ['key' => 'items_changed', 'label' => 'Items changed', 'icon' => 'edit', 'actions' => ['comp_item', 'comp_undo'], 'search' => null,
                'title' => 'Item lines changed between complimentary and charged states.'],
            ['key' => 'invoices_deleted', 'label' => 'Invoices deleted', 'icon' => 'trash', 'actions' => ['invoice_delete'], 'search' => null,
                'title' => 'POS invoices are retained and cannot be deleted; voids are tracked separately.'],
        ])->map(function ($metric) use ($currentLeakageCounts, $previousLeakageCounts) {
            $metric['count'] = collect($metric['actions'])->sum(fn ($action) => (int) ($currentLeakageCounts->get($action) ?? 0));
            $metric['previous'] = collect($metric['actions'])->sum(fn ($action) => (int) ($previousLeakageCounts->get($action) ?? 0));
            $metric['increased'] = $metric['count'] > $metric['previous'];

            return $metric;
        });

        return view('pos.dashboard', [
            'outlets' => $outlets, 'selectedOutlet' => $selectedOutlet,
            'from' => $fromDate, 'to' => $toDate, 'rangeDays' => $from->diffInDays($to) + 1,
            'previousFrom' => $previousFrom->toDateString(), 'previousTo' => $previousTo->toDateString(),
            'previousTurnaroundItemsCount' => $previousTurnaround['count'],
            'summary' => $summary, 'collections' => $collections, 'complimentaryChecks' => $complimentaryChecks, 'kpiChanges' => $kpiChanges,
            'turnaroundMinutes' => $turnaroundMinutes, 'turnaroundItemsCount' => $turnaroundCount, 'paymentMix' => $paymentMix,
            'dailyTrend' => $dailyTrend, 'chartOutlets' => $chartOutlets, 'trendAxis' => $trendAxis,
            'hasDailySales' => $maxDailySales > 0, 'hasOrderTypeSales' => $typeTotal > 0,
            'orderTypes' => $orderTypes, 'donutStyle' => $donutStyle, 'topItems' => $topItems, 'lowItems' => $lowItems,
            'revenueControls' => $revenueControls, 'leakageWatch' => $leakageWatch,
        ]);
    }

    public function index(Request $request)
    {
        $outlets = Outlet::where('is_active', true)->get();
        $outlet = $outlets->firstWhere('id', $request->integer('outlet')) ?? $outlets->first();

        $running = $outlet ? PosOrder::with('table', 'stay.room')->where('outlet_id', $outlet->id)->whereIn('status', ['open', 'billed'])->latest()->get() : collect();
        $pending = PosOrderItem::whereIn('pos_order_id', $running->pluck('id'))->where('is_pending', true)->where('is_cancelled', false)
            ->groupBy('pos_order_id')->selectRaw('pos_order_id, count(*) as n')->pluck('n', 'pos_order_id');

        // v3.2: tables reserved within the next 90 minutes (or late arrivals still being held)
        $reservedSoon = collect();
        if ($outlet?->tb_enabled) {
            foreach (\App\Models\TableReservation::with('tables')->where('outlet_id', $outlet->id)->whereIn('status', \App\Models\TableReservation::UPCOMING)
                ->whereBetween('start_at', [now()->subHours(2), now()->addMinutes(90)])->orderBy('start_at')->get() as $r) {
                foreach ($r->tables as $t) {
                    $reservedSoon[$t->id] ??= $r;
                }
            }
        }

        $tables = $outlet ? PosTable::with('openOrder.items')->where('outlet_id', $outlet->id)->where('is_active', true)->orderBy('id')->get() : collect();
        $waiterTerminal = DepartmentShiftService::requiresPersonalShift($request->user())
            && DepartmentShiftService::activeForUser((int) $request->user()->id) !== null;

        return view('pos.index', [
            'outlets' => $outlets, 'outlet' => $outlet, 'pending' => $pending, 'reservedSoon' => $reservedSoon,
            'waiterTerminal' => $waiterTerminal,
            'tables' => $tables, 'freeTables' => $tables->filter(fn ($table) => ! $table->openOrder),
            'running' => $running,
            'inHouse' => ReservationRoom::with('room', 'reservation.guest')->where('status', 'checked_in')->get(),
        ]);
    }

    public function guestSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(Guest::whereNull('privacy_status')->search($q)->orderBy('first_name')->orderBy('last_name')->limit(8)->get()
            ->map(fn ($guest) => ['id' => $guest->id, 'name' => $guest->full_name, 'phone' => $guest->phone, 'email' => $guest->email, 'vip' => (bool) $guest->is_vip])->values());
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'outlet_id' => 'required|integer|owned:outlets', 'pos_table_id' => 'nullable|integer|owned:pos_tables',
            'order_type' => 'required|in:dine_in,takeaway,room_service,delivery', 'reservation_room_id' => 'nullable|required_if:order_type,room_service|integer|owned:reservation_rooms',
            'covers' => 'nullable|integer|min:1|max:50', 'customer_name' => 'nullable|string|max:120', 'customer_phone' => 'nullable|string|max:30',
            'delivery_address' => 'nullable|required_if:order_type,delivery|string|max:255', 'delivery_fee' => 'nullable|required_if:order_type,delivery|numeric|min:0|max:100000',
            'delivery_notes' => 'nullable|string|max:190', 'steward' => 'nullable|string|max:60',
        ]);
        if (! empty($data['pos_table_id'])) {
            $table = PosTable::findOrFail($data['pos_table_id']);
            if (! $table->is_active || (int) $table->outlet_id !== (int) $data['outlet_id']) {
                throw ValidationException::withMessages(['pos_table_id' => 'Choose an active table in the selected outlet.']);
            }
            $existing = PosOrder::where('pos_table_id', $data['pos_table_id'])->whereIn('status', ['open', 'billed'])->first();
            if ($existing) {
                return redirect()->route('pos.terminal', $existing);
            }
        }
        if (! empty($data['reservation_room_id'])) {
            $stay = ReservationRoom::with('reservation.guest')->findOrFail($data['reservation_room_id']);
            abort_unless($stay->status === 'checked_in', 422, 'Room is not in-house.');
            $data['customer_name'] ??= $stay->reservation->guest->full_name;
        }
        $data['steward'] = filled($data['steward'] ?? null) ? trim($data['steward']) : (auth()->user()?->name ?: null);
        $order = PosOrder::create($data + ['order_no' => Numbering::next('pos_order'), 'business_date' => bdate(), 'status' => 'open', 'user_id' => auth()->id(), 'covers' => $data['covers'] ?? 1,
            'department_shift_session_id' => DepartmentShiftService::activeForUser((int) auth()->id())?->id,
            'delivery_status' => ($data['order_type'] ?? null) === 'delivery' ? 'pending' : 'not_applicable', 'delivery_fee' => $data['delivery_fee'] ?? 0]);

        return redirect()->route('pos.terminal', $order);
    }

    public function terminal(PosOrder $order)
    {
        $order->load('outlet', 'table', 'stay.room', 'items', 'paymentMode', 'payments.paymentMode', 'deliveryDriver', 'folio');
        $cats = MenuCategory::where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->orderBy('sort_order')->get();
        $items = MenuItem::with(['category', 'variants'])->with(['modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->with('modifiers')])
            ->where('is_available', true)->whereIn('menu_category_id', $cats->pluck('id'))
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->orderBy('name')->get();
        $kotPrint = app(KotPrintService::class);
        $kotPrinters = $kotPrint->forOutlet($order->outlet_id);
        $printerMap = [];
        foreach ($items as $item) {
            $fake = new PosOrderItem(['menu_item_id' => $item->id]);
            $fake->setRelation('menuItem', $item);
            $printerMap[$item->id] = $kotPrint->resolve($fake, $kotPrinters, $order->outlet_id)?->name ?? 'Unrouted';
        }
        $flashKot = session('print_kot');
        $flashJobs = $flashKot ? KotPrintJob::with('printer')->where('pos_order_id', $order->id)->where('kot_no', $flashKot)->latest('id')->limit(12)->get() : collect();
        $autoPrintUrl = null;
        if ($flashKot && setting('pos_auto_print_kot', '1') === '1' && ! session('print_void') && ! session('print_ask')) {
            $cap = $flashJobs->first(fn ($j) => $j->kind === 'captain' && $j->printer?->connection === 'browser' && $j->status !== 'failed');
            if ($cap) {
                $autoPrintUrl = route('pos.printkot', [$order, $flashKot]).'?job='.$cap->id.'&autoprint=1';
            } elseif ($flashJobs->isEmpty()) {
                $autoPrintUrl = route('pos.printkot', [$order, $flashKot]).'?autoprint=1';
            } elseif ($comb = $flashJobs->firstWhere('kind', 'combined')) {
                $autoPrintUrl = route('pos.printkot', [$order, $flashKot]).'?job='.$comb->id.'&autoprint=1';
            }
        }

        return view('pos.terminal', [
            'order' => $order, 'cats' => $cats, 'items' => $items, 'allergenMap' => \App\Services\AllergenService::map($items), // v5.0
            'kotPrinters' => $kotPrinters, 'printerMap' => $printerMap, 'printerNames' => KotPrinter::orderBy('name')->pluck('name', 'id'),
            'flashJobs' => $flashJobs, 'autoPrintUrl' => $autoPrintUrl,
            'paymentModes' => PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->pluck('name', 'id'),
            'complimentaryCompanies' => Company::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'inHouse' => ReservationRoom::with('room', 'reservation.guest')->where('status', 'checked_in')->get(),
            'freeTables' => PosTable::where('outlet_id', $order->outlet_id)->where('is_active', true)->whereDoesntHave('openOrder')->orderBy('id')->get(),
            'otherOrders' => PosOrder::with('table')->where('outlet_id', $order->outlet_id)->whereIn('status', ['open', 'billed'])->whereKeyNot($order->id)->latest()->get(),
            'deliveryDrivers' => Driver::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    protected function assertNoPending(PosOrder $order): void
    {
        if ($order->items()->where('is_pending', true)->where('is_cancelled', false)->exists()) {
            throw ValidationException::withMessages(['order' => 'This order has guest QR items awaiting confirmation. Accept or reject them first.']);
        }
    }

    /** Accept guest QR items: they get a KOT number, go to the kitchen and join the bill. */
    public function acceptGuest(PosOrder $order)
    {
        $this->assertEditable($order);
        $kot = null;
        DB::transaction(function () use ($order, &$kot) {
            $pending = $order->items()->where('is_pending', true)->where('is_cancelled', false)->lockForUpdate()->get();
            if ($pending->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'No pending guest items.']);
            }
            $kot = (int) Numbering::next('kot');
            $order->items()->whereIn('id', $pending->pluck('id'))->update(['is_pending' => false, 'kot_no' => $kot]);
            $order->user_id ??= auth()->id();
            if (blank($order->steward)) {
                $order->steward = auth()->user()?->name;
            }
            $order->department_shift_session_id ??= DepartmentShiftService::activeForUser((int) auth()->id())?->id;
            $order->save();
            $order->recalculate();
            ActivityLogger::log('pos', 'qr_accept', "Accepted {$pending->count()} guest QR item(s) on {$order->order_no} as KOT #{$kot}", $order);
        });
        $fired = $this->fireKot($order->fresh(), (int) $kot);

        return redirect()->route('pos.terminal', $order)->with('success', 'Guest order accepted – '.$fired['message'])->with('print_kot', $kot)->with('print_ask', $fired['ask']);
    }

    public function rejectGuest(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $n = $order->items()->where('is_pending', true)->where('is_cancelled', false)->update(['is_cancelled' => true, 'notes' => 'Rejected by staff']);
        ActivityLogger::log('pos', 'qr_reject', "Rejected {$n} guest QR item(s) on {$order->order_no}", $order);
        if ($order->items()->where('is_cancelled', false)->doesntExist()) {
            $order->forceFill(['status' => 'void', 'void_reason' => 'Guest QR order rejected'])->save();

            return redirect()->route('pos.index', ['outlet' => $order->outlet_id])->with('success', 'Guest order rejected.');
        }

        return back()->with('success', "{$n} guest item(s) rejected.");
    }

    /** Printable sheet of QR codes – one per table – linking to the public menu. */
    public function qrSheet(Request $request)
    {
        $outlets = Outlet::where('is_active', true)->get();
        $outlet = $outlets->firstWhere('id', $request->integer('outlet')) ?? $outlets->first();
        $tables = $outlet ? PosTable::where('outlet_id', $outlet->id)->where('is_active', true)->orderBy('id')->get() : collect();
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false, 'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);
        $codes = $tables->mapWithKeys(function ($t) use ($opts) {
            $url = route('qrmenu.show', $t->ensureQrToken());

            return [$t->id => ['url' => $url, 'svg' => (new \chillerlan\QRCode\QRCode($opts))->render($url)]];
        });

        return view('pos.qr', ['outlets' => $outlets, 'outlet' => $outlet, 'tables' => $tables, 'codes' => $codes,
            'enabled' => setting('qr_menu_enabled', '1') === '1']);
    }

    public function qrRegenerate(PosTable $table)
    {
        $table->regenerateQrToken();
        ActivityLogger::log('pos', 'qr_regenerate', "Regenerated QR code for table {$table->table_no}", $table);

        return back()->with('success', "New QR code issued for table {$table->table_no}. Old printed code no longer works.");
    }

    protected function assertEditable(PosOrder $order): void
    {
        if (! in_array($order->status, ['open', 'billed'])) {
            throw ValidationException::withMessages(['order' => 'Order is '.$order->status.' and cannot be modified.']);
        }
    }

    protected function assertPaidAmountFits(PosOrder $order): void
    {
        $paid = $order->paidAmount();
        if ($paid > (float) $order->total + 0.005) {
            throw ValidationException::withMessages(['order' => 'This change would reduce the bill below payments already received ('.money($paid).'). Refund or reverse the payment first.']);
        }
    }

    public function kot(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate([
            'items' => 'nullable|array|max:40', 'items.*.id' => 'required|integer|owned:menu_items', 'items.*.qty' => 'required|numeric|min:0.5|max:999', 'items.*.notes' => 'nullable|string|max:120', 'items.*.modifiers' => 'nullable|array|max:20', 'items.*.modifiers.*' => 'integer|owned:menu_modifiers', 'items.*.variants' => 'nullable|array|max:30', 'items.*.variants.*' => 'integer|owned:menu_item_variants', 'items.*.allergy_ack' => 'nullable|boolean', 'items.*.hold' => 'nullable|boolean',
            'open' => 'nullable|array|max:12', 'open.*.name' => 'required|string|max:80', 'open.*.price' => 'required|numeric|min:0|max:100000', 'open.*.qty' => 'required|numeric|min:0.5|max:99', 'open.*.notes' => 'nullable|string|max:200', 'open.*.station' => 'required|string|max:40', 'open.*.food_type' => 'required|in:veg,non_veg,egg,beverage', 'open.*.allergy_ack' => 'nullable|boolean', 'open.*.hold' => 'nullable|boolean',
            'print_to' => 'nullable|in:auto,selected,combined', 'printers' => 'nullable|array|max:12', 'printers.*' => 'integer', 'force_printers' => 'nullable|boolean',
        ]);
        $menuLines = array_values($data['items'] ?? []);
        $openLines = [];
        foreach (array_values($data['open'] ?? []) as $k => $line) {
            $name = trim(preg_replace('/\s+/', ' ', strip_tags($line['name'])));
            if (mb_strlen($name) < 2) {
                throw ValidationException::withMessages(["open.$k.name" => 'Enter the dish the kitchen should prepare.']);
            }
            $line['name'] = mb_substr($name, 0, 80);
            $line['notes'] = filled($line['notes'] ?? null) ? mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($line['notes']))), 0, 200) : null;
            $openLines[] = $line;
        }
        if ($menuLines === [] && $openLines === []) {
            throw ValidationException::withMessages(['items' => 'Add a menu item or an open item.']);
        }
        if (($data['print_to'] ?? 'auto') === 'selected' && empty($data['printers'])) {
            throw ValidationException::withMessages(['printers' => 'Select at least one printer, or use auto-route.']);
        }
        $stations = app(KotPrintService::class)->forOutlet($order->outlet_id)->where('role', 'station')->pluck('station')->filter()->unique()->values();
        if (! $stations->contains(fn ($s) => strcasecmp((string) $s, 'Kitchen') === 0)) {
            $stations->push('Kitchen');
        }
        foreach ($openLines as $k => $line) {
            $hit = $stations->first(fn ($s) => strcasecmp((string) $s, $line['station']) === 0);
            if (! $hit) {
                throw ValidationException::withMessages(["open.$k.station" => 'Choose a kitchen station.']);
            }
            $openLines[$k]['station'] = $hit;
        }
        // v5.0 guest allergies: a dish that contains (or may contain) a declared allergen needs an explicit confirmation
        $guest = \App\Services\AllergenService::clean($order->allergens);
        $flags = [];
        $modifierSnapshots = [];
        $modifierAmounts = [];
        foreach ($menuLines as $k => $line) {
            $m = MenuItem::with(['recipes.inventoryItem', 'variants', 'modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $order->outlet_id))->with('modifiers')])->findOrFail($line['id']);
            if (! $m->is_available || ($m->outlet_id && (int) $m->outlet_id !== (int) $order->outlet_id)) {
                throw ValidationException::withMessages(["items.{$k}.id" => "{$m->name} is not available at this outlet."]);
            }
            // v9.30 the dish can insist on its side dish: the terminal asks for it, and a line that leaves it out is
            // refused here, because a request is not always made by our own screen.
            if ($m->side_dish_required && empty($line['variants'])) {
                throw ValidationException::withMessages(["items.{$k}.variants" => "{$m->name}: choose the side dish that comes with it."]);
            }
            $flags[$k] = $guest ? \App\Services\AllergenService::flagText(\App\Services\AllergenService::conflicts($m, $guest)) : null;
            if ($flags[$k] && empty($line['allergy_ack'])) {
                throw ValidationException::withMessages(['items' => "{$m->name}: {$flags[$k]} – the guest declared this allergy. Check with the chef, then confirm the item to send it."]);
            }
            [$modifierSnapshots[$k], $modifierAmounts[$k]] = PosModifierService::snapshot($m, $line['modifiers'] ?? [], $k);
            [$sideDishSnapshots, $sideDishAmount] = PosMenuVariantService::snapshot($m, $line['variants'] ?? [], $k);
            $modifierSnapshots[$k] = array_merge($modifierSnapshots[$k], $sideDishSnapshots);
            $modifierAmounts[$k] = round($modifierAmounts[$k] + $sideDishAmount, 2);
        }
        $openFlags = [];
        if ($guest) {
            $openFlags['text'] = mb_substr('SPECIAL – guest allergies: '.\App\Services\AllergenService::names($guest).'. Chef confirmed.', 0, 160);
            foreach ($openLines as $k => $line) {
                if (empty($line['allergy_ack'])) {
                    throw ValidationException::withMessages(['open' => 'This guest has allergies. Confirm with the chef before sending a special order.']);
                }
            }
        }
        $kot = null;
        DB::transaction(function () use ($menuLines, $openLines, $order, &$kot, $flags, $openFlags, $modifierSnapshots, $modifierAmounts) {
            $kot = (int) Numbering::next('kot');
            foreach ($menuLines as $k => $line) {
                $m = MenuItem::findOrFail($line['id']);
                $rate = round((float) $m->price + (float) ($modifierAmounts[$k] ?? 0), 2);
                $rate = TaxService::splitPrice($rate, (float) $m->tax_rate, 'fnb')['net']; // v7.39 tax-inclusive support
                $order->items()->create(['menu_item_id' => $m->id, 'item_name' => $m->name, 'qty' => $line['qty'], 'rate' => $rate, 'modifier_amount' => $modifierAmounts[$k] ?? 0, 'modifiers' => $modifierSnapshots[$k] ?? [], 'tax_rate' => $m->tax_rate, 'amount' => round($rate * $line['qty'], 2), 'kot_no' => $kot, 'notes' => $line['notes'] ?? null, 'allergy_flag' => $flags[$k], 'kitchen_status' => ! empty($line['hold']) ? 'held' : 'new']);
            }
            foreach ($openLines as $line) {
                $rate = round((float) $line['price'], 2);
                $qty = (float) $line['qty'];
                $openRate = TaxService::rateFor('fnb', $rate);
                $rate = TaxService::splitPrice($rate, $openRate, 'fnb')['net']; // v7.39 tax-inclusive support
                $order->items()->create([
                    'menu_item_id' => null, 'item_name' => $line['name'], 'qty' => $qty, 'rate' => $rate,
                    'tax_rate' => $openRate, 'amount' => round($rate * $qty, 2), 'kot_no' => $kot,
                    'notes' => $line['notes'], 'allergy_flag' => $openFlags['text'] ?? null, 'is_open' => true,
                    'kitchen_station' => $line['station'], 'food_type' => $line['food_type'],
                    'kitchen_status' => ! empty($line['hold']) ? 'held' : 'new',
                ]);
            }
            if ($openLines) {
                ActivityLogger::log('pos', 'open_item', 'Special order on '.$order->order_no.': '.collect($openLines)->pluck('name')->implode(', '), $order);
            }
            $order->status = 'open';
            $order->save();
            $order->recalculate();
        });
        $opt = ['mode' => 'auto'];
        if (($data['print_to'] ?? 'auto') === 'combined') {
            $opt['combined'] = true;
        }
        if (($data['print_to'] ?? 'auto') === 'selected') {
            $opt = ['mode' => 'manual', 'printers' => $data['printers'], 'force' => $request->boolean('force_printers'), 'captain' => false];
        }
        $fired = $this->fireKot($order->fresh(), (int) $kot, $opt);
        if ($openLines) {
            $fired['message'] .= ' Special order'.(count($openLines) > 1 ? 's' : '').' included.';
        }
        $held = collect($menuLines)->merge($openLines)->filter(fn ($l) => ! empty($l['hold']))->count();
        if ($held) {
            $fired['message'] .= ' '.$held.' on hold — fire when the table is ready.';
        }

        return redirect()->route('pos.terminal', $order)->with('success', $fired['message'])->with('print_kot', $kot)->with('print_ask', $fired['ask']);
    }

    /** v5.0 Record the guests' allergies on the order (flags lines already sent). */
    public function allergies(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate(['allergens' => 'nullable|array', 'allergens.*' => 'string|in:'.implode(',', array_keys(\App\Services\AllergenService::ALLERGENS)), 'allergy_note' => 'nullable|string|max:160']);
        \App\Services\AllergenService::setOrderAllergies($order, $data['allergens'] ?? [], $data['allergy_note'] ?? null);
        $flagged = $order->items()->where('is_cancelled', false)->whereNotNull('allergy_flag')->count();

        return back()->with($flagged ? 'warning' : 'success', ($order->allergens ? 'Guest allergies recorded: '.\App\Services\AllergenService::names($order->allergens).'.' : 'Guest allergies cleared.')
            .($flagged ? " {$flagged} item(s) already sent contain them – tell the kitchen now." : ''));
    }

    public function updateDetails(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120',
            'customer_phone' => 'nullable|string|max:30',
            'covers' => 'required|integer|min:1|max:50',
            'steward' => 'nullable|string|max:60',
        ]);
        $order->forceFill($data)->save();
        ActivityLogger::log('pos', 'details', "Updated guest and service details on {$order->order_no}", $order);

        return back()->with('success', 'Order details updated.');
    }

    public function cancelItem(Request $request, PosOrder $order, PosOrderItem $line)
    {
        abort_unless($line->pos_order_id === $order->id, 404);
        $this->assertEditable($order);
        abort_if($line->is_cancelled, 422, 'This item is already cancelled.');
        abort_if($line->is_pending, 422, 'Pending guest items must be accepted or rejected before they can be cancelled.');
        $request->validate(['reason' => 'required|string|max:120']);
        DB::transaction(function () use ($request, $order, $line) {
            $line->update(['is_cancelled' => true, 'notes' => trim(($line->notes ?? '').' [Cancelled: '.$request->input('reason').']')]);
            $order->recalculate();
            $this->assertPaidAmountFits($order);
            ActivityLogger::log('pos', 'cancel_item', "Cancelled {$line->item_name} on {$order->order_no}", $order);
        });
        $voids = $line->kot_no ? app(KotPrintService::class)->voidLine($order, $line->fresh()) : collect();
        $msg = 'Item cancelled.'.($voids->isNotEmpty() ? ' Void slip — '.app(KotPrintService::class)->jobSummary($voids).'.' : '');

        return back()->with('success', $msg)->with('print_kot', $line->kot_no)->with('print_void', $line->kot_no ? 1 : null);
    }

    /** Give a fired dish away. The kitchen still prepares it; it drops off the guest's bill. */
    public function compItem(Request $request, PosOrder $order, PosOrderItem $line)
    {
        abort_unless($line->pos_order_id === $order->id, 404);
        $this->assertEditable($order);
        abort_if($line->is_cancelled || $line->is_pending, 422, 'Only a dish that has been sent to the kitchen can be complimentary.');
        if ($request->boolean('undo')) {
            abort_unless($line->is_complimentary, 422, 'This item is not complimentary.');
            DB::transaction(function () use ($order, $line) {
                $line->forceFill(['is_complimentary' => false, 'comp_reason' => null, 'comp_by' => null, 'comp_at' => null, 'amount' => round((float) $line->qty * (float) $line->rate, 2)])->save();
                $order->recalculate();
                $this->assertPaidAmountFits($order);
                ActivityLogger::log('pos', 'comp_undo', "Charged {$line->item_name} again on {$order->order_no}", $order);
            });

            return back()->with('success', "{$line->item_name} is charged again.");
        }
        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', array_keys(PosOrder::COMP_REASONS)),
            'note' => 'nullable|string|max:80',
        ]);
        $reason = PosOrder::COMP_REASONS[$data['reason']].(filled($data['note'] ?? null) ? ' – '.$data['note'] : '');
        DB::transaction(function () use ($order, $line, $reason) {
            $line->forceFill(['is_complimentary' => true, 'comp_reason' => $reason, 'comp_by' => auth()->id(), 'comp_at' => now(), 'amount' => 0])->save();
            $order->recalculate();
            $this->assertPaidAmountFits($order);
            ActivityLogger::log('pos', 'comp_item', "Complimentary {$line->item_name} on {$order->order_no}: {$reason}", $order);
        });

        return back()->with('success', "{$line->item_name} is complimentary ({$reason}). The kitchen still prepares it.");
    }

    public function moveTable(Request $request, PosOrder $order)
    {
        $data = $request->validate(['pos_table_id' => 'required|integer|owned:pos_tables']);

        try {
            \App\Services\PosOpsService::moveTable($order, PosTable::findOrFail($data['pos_table_id']));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order moved to table '.$order->fresh('table')->table->table_no.'.');
    }

    public function merge(Request $request, PosOrder $order)
    {
        $data = $request->validate(['source_order_id' => 'required|integer|owned:pos_orders']);
        $source = PosOrder::findOrFail($data['source_order_id']);

        try {
            \App\Services\PosOpsService::merge($order, $source);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.terminal', $order)->with('success', "Order {$source->order_no} merged into this bill.");
    }

    public function split(Request $request, PosOrder $order)
    {
        $data = $request->validate(['lines' => 'required|array', 'lines.*' => 'nullable|numeric|min:0|max:999']);

        try {
            $new = \App\Services\PosOpsService::split($order, $data['lines']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.terminal', $new)->with('success', "Split bill {$new->order_no} created. The original is {$order->order_no}.");
    }

    /** Release held dishes so the kitchen can start. One line, or every dish still on hold. */
    public function fireHeld(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate(['item_id' => 'nullable|integer']);
        $q = $order->items()->where('kitchen_status', 'held')->where('is_cancelled', false)->where('is_pending', false);
        if (! empty($data['item_id'])) {
            $q->whereKey($data['item_id']);
        }
        $lines = $q->get();
        abort_if($lines->isEmpty(), 422, 'Nothing is on hold.');
        foreach ($lines as $line) {
            $line->forceFill(['kitchen_status' => 'new', 'fired_at' => now()])->save();
        }
        $jobs = app(KotPrintService::class)->release($order->fresh(), $lines);
        ActivityLogger::log('pos', 'kot_fire', 'Fired '.$lines->pluck('item_name')->implode(', ').' on '.$order->order_no, $order);
        $summary = app(KotPrintService::class)->jobSummary($jobs);

        return back()->with('success', 'Fired '.$lines->count().' held dish'.($lines->count() === 1 ? '' : 'es').'. The kitchen can start.'.($summary ? ' — '.$summary.'.' : ''))
            ->with('print_kot', $lines->first()->kot_no);
    }

    /** Steward marks a ready (or any) kitchen item as served. */
    public function served(Request $request, PosOrder $order, PosOrderItem $line)
    {
        abort_unless($line->pos_order_id === $order->id && ! $line->is_cancelled, 404);
        $this->assertEditable($order);
        abort_if($line->is_pending, 422, 'Pending guest items must be accepted before they can be served.');
        $line->forceFill(['kitchen_status' => 'served', 'served_at' => now(), 'ready_at' => $line->ready_at ?? now()])->save();

        return $request->expectsJson() ? response()->json(['ok' => true, 'item_id' => $line->id, 'status' => 'served']) : back();
    }

    public function bill(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate([
            'discount' => 'nullable|numeric|min:0', 'discount_type' => 'nullable|in:amount,percent', 'member_code' => 'nullable|string|max:40',
            'return_to' => 'nullable|in:unsettled', 'return_outlet' => 'nullable|integer|owned:outlets',
            'return_waiter' => 'nullable|string|max:60', 'return_q' => 'nullable|string|max:120', 'return_stage' => 'nullable|in:open,billed',
        ]);
        $this->assertNoPending($order);
        abort_if($order->items()->where('is_cancelled', false)->where('is_pending', false)->count() === 0, 422, 'No items on order.');
        $disc = (float) ($data['discount'] ?? 0);
        if (($data['discount_type'] ?? 'amount') === 'percent') {
            $disc = round($order->items()->where('is_cancelled', false)->where('is_pending', false)->sum('amount') * min(100, $disc) / 100, 2);
        }
        // v5.1 health club member: the plan's F&B discount (the better of it and any manual discount)
        $memberNote = '';
        $order->club_member_id = null;
        if (filled($data['member_code'] ?? null)) {
            $mem = \App\Services\ClubService::memberDiscount(trim($data['member_code']));
            if (! $mem['member']) {
                return back()->with('error', $mem['reason']);
            }
            $order->club_member_id = $mem['member']->id;
            $sub = (float) $order->items()->where('is_cancelled', false)->where('is_pending', false)->sum('amount');
            $md = round($sub * $mem['pct'] / 100, 2);
            if ($md > $disc) {
                $disc = $md;
                $memberNote = " Member discount {$mem['pct']}% for {$mem['member']->name}.";
            } else {
                $memberNote = " Member {$mem['member']->name} recorded".($mem['pct'] > 0 ? ' (manual discount is higher).' : ' – the plan has no F&B discount.');
            }
        }
        DB::transaction(function () use ($order, $disc) {
            $order->discount = $disc;
            $order->bill_no ??= Numbering::next('pos_bill');
            $order->status = 'billed';
            $order->save();
            $order->recalculate();
            $this->assertPaidAmountFits($order);
        });

        $message = "Bill {$order->bill_no} generated.".$memberNote;
        if (($data['return_to'] ?? null) === 'unsettled') {
            $returnFilters = array_filter([
                'outlet' => $data['return_outlet'] ?? null,
                'waiter' => $data['return_waiter'] ?? null,
                'q' => $data['return_q'] ?? null,
                'stage' => ($data['return_stage'] ?? null) === 'open' ? 'billed' : ($data['return_stage'] ?? null),
            ], fn ($value) => $value !== null && $value !== '');

            return redirect()->to(route('pos.unsettled', $returnFilters, false))->with('success', $message);
        }

        return redirect()->route('pos.terminal', $order)->with('success', $message);
    }

    public function settle(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        abort_unless(! $request->boolean('print_bill') || can_do('pos.view'), 403);
        $this->assertNoPending($order);
        if ($request->input('settlement') === 'payment') {
            try {
                \App\Services\ShiftService::assertOpen();
            } catch (\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }
        }
        if ($request->input('settlement') === 'complimentary' && ! $request->filled('comp_reason') && $request->filled('reason')) {
            $request->merge(['comp_reason' => $request->input('reason')]);
        }
        $data = $request->validate([
            'settlement' => 'required|in:payment,room_post,complimentary',
            'print_bill' => 'nullable|boolean',
            'return_to' => 'nullable|in:unsettled',
            'return_order' => 'nullable|integer|owned:pos_orders',
            'return_outlet' => 'nullable|integer|owned:outlets',
            'return_waiter' => 'nullable|string|max:60',
            'return_q' => 'nullable|string|max:120',
            'return_stage' => 'nullable|in:open,billed',
            'comp_reason' => 'nullable|required_if:settlement,complimentary|string|min:3|max:120',
            'comp_department' => 'nullable|required_if:settlement,complimentary|string|min:2|max:100',
            'comp_manager_name' => 'nullable|required_if:settlement,complimentary|string|min:2|max:120',
            'comp_receiver_name' => 'nullable|required_if:settlement,complimentary|string|min:2|max:120',
            'comp_post_to' => 'nullable|required_if:settlement,complimentary|in:city_ledger,guest_folio',
            'comp_post_company_id' => 'nullable|required_if:comp_post_to,city_ledger|integer|owned:companies',
            'comp_post_reservation_room_id' => 'nullable|required_if:comp_post_to,guest_folio|integer|owned:reservation_rooms',
            'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'reservation_room_id' => 'nullable|required_if:settlement,room_post|integer|owned:reservation_rooms',
            'amount' => 'nullable|numeric|min:0.01',
            'tendered_amount' => 'nullable|numeric|min:0',
            'allocations' => 'nullable|array|max:10',
            'allocations.*.payment_mode_id' => 'required|integer|owned:payment_modes',
            'allocations.*.amount' => 'required|numeric|min:0.01',
            'allocations.*.tendered_amount' => 'nullable|numeric|min:0',
            'allocations.*.reference' => 'nullable|string|max:80',
            'allocations.*.cheque_bank_name' => 'nullable|string|max:100',
            'allocations.*.cheque_number' => 'nullable|string|max:30',
            'allocations.*.cheque_bank_branch' => 'nullable|string|max:100',
            'allocations.*.card_authorisation' => 'nullable|string|max:80',
            'allocations.*.mobile_network' => 'nullable|string|max:30',
            'allocations.*.mobile_phone' => 'nullable|string|max:30',
            'allocations.*.payment_notes' => 'nullable|string|max:190',
            'reference' => 'nullable|string|max:80',
            'cheque_bank_name' => 'nullable|string|max:100',
            'cheque_number' => 'nullable|string|max:30',
            'cheque_bank_branch' => 'nullable|string|max:100',
            'card_authorisation' => 'nullable|string|max:80',
            'mobile_network' => 'nullable|string|max:30',
            'mobile_phone' => 'nullable|string|max:30',
            'payment_notes' => 'nullable|string|max:190',
            'voucher_code' => 'nullable|string|max:20',
            'buyer_tin' => ['nullable', 'string', 'max:20', function ($a, $v, $fail) { if (filled($v) && ! \App\Services\GstService::validTin($v)) { $fail('Enter a valid Ghana TIN (e.g. C0012345678) or Ghana Card PIN (GHA-123456789-0).'); } }],
            'buyer_name' => 'nullable|required_with:buyer_tin|string|max:120',
        ]);
        $result = ['status' => 'settled', 'amount' => 0.0, 'change' => 0.0];
        try {
            DB::transaction(function () use ($data, $order, &$result) {
            // Settlement is a financial write: serialize concurrent cashier requests before calculating the balance.
            $order = PosOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($order);
            $this->assertNoPending($order);
            $order->bill_no ??= Numbering::next('pos_bill');
            $order->recalculate();
            $isComplimentary = $data['settlement'] === 'complimentary';
            $order->comp_reason = $isComplimentary ? trim($data['comp_reason']) : null;
            $order->comp_department = $isComplimentary ? trim($data['comp_department']) : null;
            $order->comp_manager_name = $isComplimentary ? trim($data['comp_manager_name']) : null;
            $order->comp_receiver_name = $isComplimentary ? trim($data['comp_receiver_name']) : null;
            $order->comp_post_to = $isComplimentary ? $data['comp_post_to'] : null;
            $order->comp_post_company_id = $isComplimentary && $data['comp_post_to'] === 'city_ledger' ? $data['comp_post_company_id'] : null;
            $order->comp_post_reservation_room_id = $isComplimentary && $data['comp_post_to'] === 'guest_folio' ? $data['comp_post_reservation_room_id'] : null;
            $order->settlement = $data['settlement'];
            if ($data['settlement'] === 'payment') {
                $usingAllocations = ! empty($data['allocations']);
                if (! $usingAllocations && empty($data['payment_mode_id'])) {
                    throw ValidationException::withMessages(['payment_mode_id' => 'Choose a payment mode.']);
                }
                $allocations = $usingAllocations ? array_values($data['allocations']) : [[
                    'payment_mode_id' => $data['payment_mode_id'],
                    'amount' => $data['amount'] ?? null,
                    'tendered_amount' => $data['tendered_amount'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'cheque_bank_name' => $data['cheque_bank_name'] ?? null,
                    'cheque_number' => $data['cheque_number'] ?? null,
                    'cheque_bank_branch' => $data['cheque_bank_branch'] ?? null,
                    'card_authorisation' => $data['card_authorisation'] ?? null,
                    'mobile_network' => $data['mobile_network'] ?? null,
                    'mobile_phone' => $data['mobile_phone'] ?? null,
                    'payment_notes' => $data['payment_notes'] ?? null,
                ]];
                $due = $order->balanceDue();
                if ($due <= 0.005) {
                    throw ValidationException::withMessages(['payment_mode_id' => 'This bill is already fully paid.']);
                }
                $allocated = 0.0;
                $change = 0.0;
                $voucherModeId = null;
                foreach ($allocations as $index => $allocation) {
                    $mode = PaymentMode::findOrFail($allocation['payment_mode_id']);
                    if (! $mode->is_active || $mode->type === 'city_ledger') {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.payment_mode_id" : 'payment_mode_id' => 'Choose an active direct POS payment mode.']);
                    }
                    $amount = round((float) ($allocation['amount'] ?? ($due - $allocated)), 2);
                    if ($amount <= 0) {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.amount" : 'amount' => 'Enter an amount greater than zero.']);
                    }
                    if ($allocated + $amount > $due + 0.005) {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.amount" : 'amount' => 'The payment allocations cannot exceed the remaining balance.']);
                    }
                    $hasTendered = array_key_exists('tendered_amount', $allocation) && $allocation['tendered_amount'] !== null;
                    $tendered = $hasTendered ? round((float) $allocation['tendered_amount'], 2) : $amount;
                    $allocationChange = 0.0;
                    if ($mode->type === 'cash') {
                        if ($tendered + 0.005 < $amount) {
                            throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.tendered_amount" : 'tendered_amount' => 'Cash received is less than the amount being paid.']);
                        }
                        $allocationChange = round($tendered - $amount, 2);
                    } elseif ($hasTendered && abs($tendered - $amount) > 0.005) {
                        throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.tendered_amount" : 'tendered_amount' => 'Tendered amount must equal the payment for non-cash tenders.']);
                    }
                    if (\App\Services\GiftVoucherService::isVoucherMode((int) $mode->id)) {
                        if (count($allocations) !== 1 || abs($amount - $due) > 0.005) {
                            throw ValidationException::withMessages([$usingAllocations ? "allocations.{$index}.amount" : 'amount' => 'A gift voucher must cover the remaining bill in one payment.']);
                        }
                        $voucherModeId = (int) $mode->id;
                    }
                    $order->settlement = 'payment';
                    if (filled($data['buyer_tin'] ?? null)) { // v4.4: business customer's TIN on the E-VAT stamped bill
                        $order->buyer_tin = \App\Services\GstService::normaliseTin($data['buyer_tin']);
                        $order->buyer_name = $data['buyer_name'];
                    }
                    $order->payments()->create([
                        'payment_mode_id' => $mode->id, 'kind' => 'payment', 'amount' => $amount,
                        'tendered_amount' => $mode->type === 'cash' ? $tendered : null, 'change_amount' => $allocationChange,
                        'reference' => filled($allocation['reference'] ?? null) ? trim($allocation['reference']) : null,
                        'cheque_bank_name' => $allocation['cheque_bank_name'] ?? null, 'cheque_number' => $allocation['cheque_number'] ?? null,
                        'cheque_bank_branch' => $allocation['cheque_bank_branch'] ?? null, 'card_authorisation' => $allocation['card_authorisation'] ?? null,
                        'mobile_network' => $allocation['mobile_network'] ?? null, 'mobile_phone' => $allocation['mobile_phone'] ?? null,
                        'notes' => $allocation['payment_notes'] ?? null, 'business_date' => $order->business_date?->toDateString() ?? bdate()->toDateString(),
                        'received_by' => auth()->id(), 'received_at' => now(),
                    ]);
                    $allocated = round($allocated + $amount, 2);
                    $change = round($change + $allocationChange, 2);
                }
                $paid = round($order->paidAmount(), 2);
                $result = ['status' => $paid + 0.005 >= (float) $order->total ? 'settled' : 'partial', 'amount' => $allocated, 'change' => $change];
                $modes = $order->payments()->where('kind', 'payment')->pluck('payment_mode_id')->unique();
                $order->payment_mode_id = $modes->count() === 1 ? $modes->first() : null;
                if ($result['status'] === 'settled') {
                    $order->status = 'settled';
                    $order->settled_at = now();
                    $order->settled_by = auth()->id();
                    if ($voucherModeId) {
                        \App\Services\GiftVoucherService::posPayment($order, $data['voucher_code'] ?? null, $allocated);
                    }
                    \App\Services\RecipeService::consume($order);
                } else {
                    $order->status = 'billed';
                    $order->settled_at = null;
                    $order->settled_by = null;
                }
            } elseif ($data['settlement'] === 'room_post') {
                $stay = ReservationRoom::findOrFail($data['reservation_room_id']);
                \App\Services\PosOpsService::postToRoom($order, $stay);
            } else {
                $order->status = 'settled';
                $order->settled_at = now();
                $order->settled_by = auth()->id();
                \App\Services\RecipeService::consume($order);
                $memo = mb_substr('Complimentary from hotel — POS '.$order->order_no.' — Receiver: '.$order->comp_receiver_name, 0, 255);
                if ($data['comp_post_to'] === 'city_ledger') {
                    $company = Company::whereKey($data['comp_post_company_id'])->where('is_active', true)->first();
                    if (! $company) {
                        throw ValidationException::withMessages(['comp_post_company_id' => 'Choose an active City Ledger company.']);
                    }
                    $folio = FolioService::openStandalone(['company_id' => $company->id, 'type' => 'walk_in']);
                    $folio->transactions()->create([
                        'hotel_id' => $folio->hotel_id, 'business_date' => $order->business_date?->toDateString() ?? bdate()->toDateString(),
                        'type' => 'charge', 'category' => 'city_ledger', 'description' => $memo, 'amount' => 0, 'tax_amount' => 0, 'tax_rate' => 0,
                        'company_id' => $company->id, 'reference' => $order->order_no,
                        'source_type' => 'pos_complimentary', 'source_id' => $order->id, 'posted_by' => auth()->id(),
                    ]);
                } else {
                    $stay = ReservationRoom::whereKey($data['comp_post_reservation_room_id'])->lockForUpdate()->first();
                    if (! $stay || $stay->status !== 'checked_in') {
                        throw ValidationException::withMessages(['comp_post_reservation_room_id' => 'Choose a room with a current in-house guest folio.']);
                    }
                    $folio = FolioService::openForStay($stay);
                    FolioService::charge($folio, 'fnb', $memo, 0, false, [
                        'source_type' => 'pos_complimentary', 'source_id' => $order->id, 'reference' => $order->order_no,
                    ]);
                }
                $order->folio_id = $folio->id;
            }
            $order->save();
            $label = $result['status'] === 'partial' ? 'Part payment' : 'Settled';
            ActivityLogger::log('pos', 'settle', "{$label} {$order->bill_no} ".money($result['amount'] ?: $order->total)." via {$data['settlement']}", $order);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $order->refresh();
        $ev = match ($order->evat_status) { // v4.4 GRA E-VAT
            'generated' => " Stamped by GRA E-VAT (receipt {$order->evat_rec_num}).",
            'failed' => ' E-VAT stamping failed – see Billing › VAT › E-VAT; it will be retried automatically.',
            'pending' => ' Queued for GRA E-VAT.',
            default => '',
        };
        $message = $result['status'] === 'partial'
            ? 'Part payment of '.money($result['amount']).' recorded. Balance due: '.money($order->balanceDue()).'.'.($request->boolean('print_bill') ? ' Bill was not printed because a balance remains.' : '')
            : 'Bill '.$order->bill_no.' settled.'.($result['change'] > 0 ? ' Change: '.money($result['change']).'.' : '').$ev;

        if ($request->boolean('print_bill') && $result['status'] === 'settled') {
            return redirect()->to(route('pos.print', $order, false).'?autoprint=1')->with($order->evat_status === 'failed' ? 'warning' : 'success', $message);
        }

        if (($data['return_to'] ?? null) === 'unsettled') {
            $returnFilters = array_filter([
                'outlet' => $data['return_outlet'] ?? null,
                'waiter' => $data['return_waiter'] ?? null,
                'q' => $data['return_q'] ?? null,
                'stage' => $data['return_stage'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
            $redirect = redirect()->to(route('pos.unsettled', $returnFilters, false));
        } else {
            $redirect = $result['status'] === 'partial'
                ? redirect()->route('pos.terminal', $order)
                : redirect()->route('pos.index', ['outlet' => $order->outlet_id]);
        }

        return $redirect->with($order->evat_status === 'failed' ? 'warning' : 'success', $message);
    }

    public function deliveryStatus(Request $request, PosOrder $order)
    {
        abort_unless($order->order_type === 'delivery', 422, 'This order is not a delivery order.');
        $this->assertEditable($order);
        $data = $request->validate([
            'delivery_status' => 'required|in:pending,preparing,out_for_delivery,delivered,failed,cancelled',
            'delivery_driver_id' => 'nullable|integer|owned:drivers',
            'delivery_address' => 'required|string|max:255',
            'delivery_notes' => 'nullable|string|max:190',
        ]);
        $driverId = $data['delivery_driver_id'] ?? $order->delivery_driver_id;
        if (in_array($data['delivery_status'], ['out_for_delivery', 'delivered'], true) && empty($driverId)) {
            throw ValidationException::withMessages(['delivery_driver_id' => 'Assign a driver before dispatching or completing the delivery.']);
        }
        if ($driverId && in_array($data['delivery_status'], ['out_for_delivery', 'delivered'], true) && ! Driver::whereKey($driverId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['delivery_driver_id' => 'The selected delivery driver is not active.']);
        }
        $was = $order->delivery_status;
        $order->forceFill([
            'delivery_status' => $data['delivery_status'], 'delivery_driver_id' => $data['delivery_driver_id'] ?? $order->delivery_driver_id,
            'delivery_address' => trim($data['delivery_address']), 'delivery_notes' => filled($data['delivery_notes'] ?? null) ? trim($data['delivery_notes']) : null,
            'delivered_at' => $data['delivery_status'] === 'delivered' ? ($order->delivered_at ?? now()) : null,
        ])->save();
        ActivityLogger::log('pos', 'delivery_status', "Delivery {$order->order_no}: {$was} → {$order->delivery_status}", $order);

        return back()->with('success', 'Delivery status updated.');
    }

    public function refund(Request $request, PosOrder $order)
    {
        abort_unless($order->status === 'settled' && $order->settlement === 'payment', 422, 'Only a settled direct POS payment can be refunded.');
        try {
            \App\Services\ShiftService::assertOpen();
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $data = $request->validate([
            'payment_id' => 'required|integer|owned:pos_payments',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:190',
            'reference' => 'nullable|string|max:80',
        ]);
        try {
            DB::transaction(function () use ($data, $order) {
                $payment = PosPayment::whereKey($data['payment_id'])->where('pos_order_id', $order->id)->where('kind', 'payment')->lockForUpdate()->first();
                if (! $payment) {
                    throw ValidationException::withMessages(['payment_id' => 'Choose an original payment on this order.']);
                }
                $remaining = $payment->refundableAmount();
                $amount = round((float) $data['amount'], 2);
                if ($amount < 0.01) {
                    throw ValidationException::withMessages(['amount' => 'The refund must be at least 0.01.']);
                }
                if ($amount > $remaining + 0.005) {
                    throw ValidationException::withMessages(['amount' => 'The refund exceeds the remaining amount on this payment ('.money($remaining).').']);
                }
                if (\App\Services\GiftVoucherService::isVoucherMode((int) $payment->payment_mode_id)) {
                    \App\Services\GiftVoucherService::posRefund($order, $amount);
                }
                $refund = $order->payments()->create([
                    'refund_of_id' => $payment->id, 'payment_mode_id' => $payment->payment_mode_id, 'kind' => 'refund',
                    'amount' => $amount, 'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'notes' => trim($data['reason']), 'business_date' => $order->business_date?->toDateString() ?? bdate()->toDateString(),
                    'received_by' => auth()->id(), 'received_at' => now(),
                ]);
                // The POS order is the GL source; touch it so a post-settlement refund is picked up by incremental ledger sync.
                $order->touch();
                ActivityLogger::log('pos', 'refund', 'Refunded '.money($amount).' from '.$order->bill_no.': '.$data['reason'], $refund);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.terminal', $order)->with('success', 'POS refund of '.money((float) $data['amount']).' recorded.');
    }

    public function void(Request $request, PosOrder $order)
    {
        $this->assertEditable($order);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $order->update(['status' => 'void', 'void_reason' => $data['reason']]);
        $svc = app(KotPrintService::class);
        foreach ($order->items()->whereNotNull('kot_no')->where('is_cancelled', false)->pluck('kot_no')->unique() as $kotNo) {
            $svc->voidKot($order->fresh(), (int) $kotNo);
        }
        ActivityLogger::log('pos', 'void', "Voided order {$order->order_no}: {$data['reason']}", $order);

        return redirect()->route('pos.index', ['outlet' => $order->outlet_id])->with('success', 'Order voided.');
    }

    /** Cashier worklist for all open checks and billed POS invoices, across all business dates. */
    public function unsettledInvoices(Request $request)
    {
        $filters = $request->validate([
            'outlet' => 'nullable|integer|owned:outlets',
            'waiter' => 'nullable|string|max:60',
            'q' => 'nullable|string|max:120',
            'stage' => 'nullable|in:open,billed',
        ]);
        // Local Live Orders preview fixtures are intentionally excluded from the cashier invoice worklist.
        $query = PosOrder::query()->whereIn('status', ['open', 'billed'])
            ->where(fn ($q) => $q->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
            ->when(! empty($filters['outlet']), fn ($q) => $q->where('outlet_id', $filters['outlet']))
            ->when(! empty($filters['waiter']), function ($q) use ($filters) {
                if ($filters['waiter'] === '__unassigned') {
                    $q->where(fn ($unassigned) => $unassigned->whereNull('steward')->orWhere('steward', ''));
                } else {
                    $q->where('steward', $filters['waiter']);
                }
            })
            ->when(! empty($filters['stage']), fn ($q) => $q->where('status', $filters['stage']))
            ->when(! empty($filters['q']), function ($q) use ($filters) {
                $like = '%'.trim($filters['q']).'%';
                $q->where(fn ($match) => $match->where('order_no', 'like', $like)
                    ->orWhere('bill_no', 'like', $like)->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like)->orWhere('steward', 'like', $like));
            });

        $netPaidSql = "(SELECT COALESCE(SUM(CASE WHEN pos_payments.kind = 'payment' THEN pos_payments.amount WHEN pos_payments.kind = 'refund' THEN -pos_payments.amount ELSE 0 END), 0) FROM pos_payments WHERE pos_payments.pos_order_id = pos_orders.id AND pos_payments.hotel_id = pos_orders.hotel_id)";
        $balanceSql = "CASE WHEN (pos_orders.total - {$netPaidSql}) > 0 THEN (pos_orders.total - {$netPaidSql}) ELSE 0 END";
        $summary = (clone $query)->reorder()->selectRaw(
            "COUNT(*) as invoice_count, COALESCE(SUM({$balanceSql}), 0) as balance_due, " .
            "SUM(CASE WHEN pos_orders.status = 'open' THEN 1 ELSE 0 END) as open_count, " .
            "SUM(CASE WHEN pos_orders.status = 'billed' THEN 1 ELSE 0 END) as billed_count, " .
            "COUNT(DISTINCT NULLIF(TRIM(pos_orders.steward), '')) as waiter_count"
        )->first();
        $waiterSummary = (clone $query)->reorder()->selectRaw(
            "COALESCE(NULLIF(TRIM(pos_orders.steward), ''), 'Unassigned') as waiter, " .
            "COUNT(*) as invoice_count, COALESCE(SUM({$balanceSql}), 0) as balance_due"
        )->groupBy('pos_orders.steward')->orderByDesc('balance_due')->orderBy('waiter')->get();
        $orders = (clone $query)
            ->with('outlet', 'table', 'stay.room')
            ->withCount([
                'items as pending_items_count' => fn ($q) => $q->where('is_pending', true)->where('is_cancelled', false),
                'items as accepted_items_count' => fn ($q) => $q->where('is_pending', false)->where('is_cancelled', false),
            ])
            ->withSum(['payments as paid_amount' => fn ($q) => $q->where('kind', 'payment')], 'amount')
            ->withSum(['payments as refunded_amount' => fn ($q) => $q->where('kind', 'refund')], 'amount')
            ->oldest('created_at')->oldest('id')->paginate(40)->withQueryString();
        $outlets = Outlet::orderBy('name')->pluck('name', 'id');
        $waiters = PosOrder::whereIn('status', ['open', 'billed'])
            ->where(fn ($q) => $q->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
            ->whereNotNull('steward')->where('steward', '<>', '')
            ->distinct()->orderBy('steward')->pluck('steward');
        $paymentModes = PaymentMode::where('is_active', true)->where('type', '!=', 'city_ledger')->orderBy('name')->pluck('name', 'id');

        return view('pos.unsettled-invoices', [
            'orders' => $orders, 'summary' => $summary, 'waiters' => $waiters, 'waiterSummary' => $waiterSummary, 'outlets' => $outlets,
            'paymentModes' => $paymentModes, 'evatEnabled' => \App\Services\PosEvatService::enabled(),
            'filters' => $filters + ['stage' => ''],
        ]);
    }

    public function orders(Request $request)
    {
        $filters = $request->validate([
            'payment_mode_id' => 'nullable|integer|owned:payment_modes',
            'order_type' => 'nullable|in:dine_in,takeaway,room_service,delivery,other',
            'item_name' => 'nullable|string|max:120',
            'with_comps' => 'nullable|in:1',
            'with_served' => 'nullable|in:1',
        ]);
        $paymentModeId = $filters['payment_mode_id'] ?? null;
        $orderType = $filters['order_type'] ?? null;
        $itemName = $filters['item_name'] ?? null;
        $withComps = $filters['with_comps'] ?? null;
        $withServed = $filters['with_served'] ?? null;
        $from = $request->input('from', bdate()->toDateString());
        $to = $request->input('to', bdate()->toDateString());
        $search = trim((string) $request->input('q', ''));
        $ordersQuery = PosOrder::query()
            ->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($match) use ($like) {
                    $match->where('order_no', 'like', $like)
                        ->orWhere('bill_no', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('steward', 'like', $like);
                });
            })
            ->when($request->outlet, fn ($q, $o) => $q->where('outlet_id', $o))
            ->when($request->status, function ($q, $status) {
                $status === 'unsettled' ? $q->whereIn('status', ['open', 'billed']) : $q->where('status', $status);
            })
            ->when($paymentModeId, fn ($q, $mode) => $q->whereHas('payments', fn ($p) => $p->whereIn('kind', ['payment', 'refund'])->where('payment_mode_id', $mode)))
            ->when($orderType, fn ($q, $type) => $type === 'other'
                ? $q->where(fn ($types) => $types->whereNull('order_type')->orWhereNotIn('order_type', ['dine_in', 'takeaway', 'room_service', 'delivery']))
                : $q->where('order_type', $type))
            ->when($itemName, fn ($q, $name) => $q->whereHas('items', fn ($items) => $items->where('item_name', $name)
                ->where('is_cancelled', false)->where('is_pending', false)->where('is_complimentary', false)->where('amount', '>', 0)))
            ->when($withComps, fn ($q) => $q->where('status', 'settled')
                ->where(fn ($checks) => $checks->where('settlement', 'complimentary')
                    ->orWhereHas('items', fn ($items) => $items->where('is_complimentary', true)->where('is_cancelled', false)))
                ->where(fn ($checks) => $checks->whereNull('customer_name')->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%')))
            ->when($withServed, fn ($q) => $q->where(fn ($checks) => $checks->whereNull('customer_name')
                    ->orWhere('customer_name', 'not like', 'DEMO PREVIEW ·%'))
                ->whereHas('items', fn ($items) => $items->where('is_cancelled', false)->where('is_pending', false)
                    ->whereNotNull('created_at')->whereNotNull('served_at')->whereColumn('served_at', '>=', 'created_at')));

        $summary = (clone $ordersQuery)->selectRaw(
            'COUNT(CASE WHEN status = ? THEN 1 END) as settled_count,
             COALESCE(SUM(CASE WHEN status = ? THEN total - tax ELSE 0 END), 0) as sales_ex_tax,
             COALESCE(SUM(CASE WHEN status = ? THEN tax ELSE 0 END), 0) as tax_total,
             COALESCE(SUM(CASE WHEN settlement = ? THEN total ELSE 0 END), 0) as room_post_total',
            ['settled', 'settled', 'settled', 'room_post']
        )->first();
        $orders = $ordersQuery->with('outlet', 'table', 'paymentMode', 'payments.paymentMode', 'stay.room', 'user')
            ->latest()->paginate(50)->withQueryString();

        return view('pos.orders', ['orders' => $orders, 'summary' => $summary, 'from' => $from, 'to' => $to, 'q' => $search,
            'outlets' => Outlet::pluck('name', 'id'), 'paymentModes' => PaymentMode::orderBy('name')->pluck('name', 'id')]);
    }

    public function liveOrders(Request $request)
    {
        $outlets = Outlet::where('is_active', true)->orderBy('name')->pluck('name', 'id');
        $selectedOutlet = $request->integer('outlet') ?: '';
        $defaultScope = auth()->user()?->hasPermission('pos.settle') ? 'all' : 'mine';
        $scope = $request->query('scope', $defaultScope);
        if (! in_array($scope, ['mine', 'all'], true)) {
            $scope = $defaultScope;
        }

        return view('pos.live-orders', [
            'outlets' => $outlets, 'selectedOutlet' => $selectedOutlet, 'scope' => $scope,
            'canServe' => auth()->user()?->hasPermission('pos.order') ?? false,
            'feedUrl' => route('pos.live-feed', [], false), 'currencySymbol' => hotel()?->currency_symbol ?? 'GH₵',
        ]);
    }

    /** Live waiter-facing order feed; ready item transitions are announced by the browser. */
    public function liveOrdersFeed(Request $request)
    {
        $filters = $request->validate([
            'outlet' => 'nullable|integer|owned:outlets',
            'scope' => 'nullable|in:mine,all',
        ]);
        $scope = $filters['scope'] ?? 'mine';
        $user = auth()->user();
        $nameParts = preg_split('/\\s+/', trim((string) ($user?->name ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $identities = collect([$user?->name, $user?->username, $nameParts[0] ?? null])->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => mb_strtolower(trim($value)))->unique()->values();
        $mine = function ($query) use ($user, $identities) {
            $query->where(function ($w) use ($user, $identities) {
                if ($identities->isNotEmpty()) {
                    foreach ($identities as $index => $identity) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $w->{$method}("LOWER(TRIM(COALESCE(steward, ''))) = ?", [$identity]);
                    }
                }
                if ($user) {
                    $w->orWhere(function ($unassigned) use ($user, $identities) {
                        $unassigned->where('user_id', $user->id);
                        if ($identities->isNotEmpty()) {
                            $unassigned->where(fn ($blank) => $blank->whereNull('steward')->orWhereRaw("TRIM(COALESCE(steward, '')) = ''"));
                        }
                    });
                }
            });
        };
        $base = PosOrder::whereIn('status', ['open', 'billed'])
            ->when(! empty($filters['outlet']), fn ($q) => $q->where('outlet_id', $filters['outlet']));
        $totalCount = (clone $base)->count();
        $myCount = (clone $base)->where($mine)->count();
        $readyCount = PosOrderItem::where('is_cancelled', false)->where('is_pending', false)->where('kitchen_status', 'ready')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['open', 'billed'])
                ->when(! empty($filters['outlet']), fn ($w) => $w->where('outlet_id', $filters['outlet'])))->distinct('pos_order_id')->count('pos_order_id');
        $ordersQuery = clone $base;
        if ($scope === 'mine') {
            $ordersQuery->where($mine);
        }
        $orders = $ordersQuery->with([
            'outlet', 'table', 'stay.room',
            'items' => fn ($q) => $q->where('is_cancelled', false)->orderBy('id'),
        ])->latest('created_at')->limit(150)->get();
        $itemsByOrder = $orders->mapWithKeys(fn ($order) => [$order->id => $order->items]);
        $rows = $orders->map(function ($order) use ($itemsByOrder) {
            $items = $itemsByOrder[$order->id];
            $ready = $items->where('is_pending', false)->where('kitchen_status', 'ready');
            $accepted = $items->where('is_pending', false);
            $where = $order->table?->table_no ? 'Table '.$order->table->table_no
                : ($order->stay?->room?->room_no ? 'Room '.$order->stay->room->room_no : ucwords(str_replace('_', ' ', $order->order_type)));

            return [
                'id' => $order->id, 'order_no' => $order->order_no, 'status' => $order->status,
                'where' => $where, 'guest' => $order->customer_name ?: 'Walk-in guest', 'waiter' => $order->steward ?: 'Unassigned',
                'outlet' => $order->outlet?->name ?? 'Outlet', 'covers' => (int) ($order->covers ?: 1), 'order_type' => $order->order_type,
                'created_at' => $order->created_at?->toIso8601String(), 'time' => $order->created_at?->format('H:i'),
                'minutes' => $order->created_at ? (int) $order->created_at->diffInMinutes(now()) : 0,
                'total' => (float) $order->total, 'ready_count' => $ready->count(), 'active_count' => $accepted->count(),
                'pending_count' => $items->where('is_pending', true)->count(),
                'all_ready' => $accepted->isNotEmpty() && $accepted->every(fn ($item) => in_array($item->kitchen_status, ['ready', 'served'], true)),
                'url' => route('pos.terminal', $order, false), 'can_claim' => blank($order->steward) && (auth()->user()?->hasPermission('pos.order') ?? false),
                'claim_url' => route('pos.claim', $order, false),
                'items' => $items->map(fn ($item) => [
                    'id' => $item->id, 'name' => $item->item_name, 'qty' => (float) $item->qty,
                    'status' => $item->is_pending ? 'pending' : $item->kitchen_status,
                    'ready_at' => $item->ready_at?->toIso8601String() ?? ($item->kitchen_status === 'ready' ? $item->updated_at?->toIso8601String() : null),
                    'notes' => $item->notes,
                    // a doubled side dish shows its count on the board too (v9.31), not just here
                    'modifiers' => collect($item->modifiers ?? [])->map(fn ($m) => $m['name'].(((int) ($m['qty'] ?? 1)) > 1 ? ' ×'.(int) $m['qty'] : ''))->values()->all(),
                    'serve_url' => route('pos.item.served', [$order, $item], false),
                ])->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'orders' => $rows, 'scope' => $scope, 'my_count' => $myCount, 'total_count' => $totalCount,
            'ready_count' => $readyCount, 'can_serve' => auth()->user()?->hasPermission('pos.order') ?? false,
            'at' => now()->format('H:i:s'),
        ]);
    }

    /** Claim an unassigned live check so it appears in the waiter’s My orders view. */
    public function claim(Request $request, PosOrder $order)
    {
        $user = auth()->user();
        $waiter = mb_substr(trim((string) ($user?->name ?: $user?->username)), 0, 60);
        if ($waiter === '') {
            throw ValidationException::withMessages(['order' => 'Your staff profile needs a name before you can claim an order.']);
        }
        DB::transaction(function () use ($order, $waiter) {
            $locked = PosOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);
            if (filled($locked->steward)) {
                throw ValidationException::withMessages(['order' => 'This order is already assigned to '.$locked->steward.'.']);
            }
            $locked->steward = $waiter;
            $locked->save();
            ActivityLogger::log('pos', 'claim', "{$waiter} claimed {$locked->order_no}", $locked);
        });

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'order_id' => $order->id, 'waiter' => $waiter])
            : back()->with('success', "{$order->order_no} is now assigned to you.");
    }

    public function printBill(PosOrder $order)
    {
        if ($order->bill_no) {
            $printedBefore = ActivityLog::query()->where('module', 'pos')->where('subject_type', 'PosOrder')
                ->where('subject_id', $order->getKey())->whereIn('action', ['invoice_print', 'invoice_reprint'])->exists();
            ActivityLogger::log('pos', $printedBefore ? 'invoice_reprint' : 'invoice_print',
                ($printedBefore ? 'Reprinted' : 'Printed').' POS invoice '.$order->bill_no, $order,
                ['bill_no' => $order->bill_no, 'outlet_id' => $order->outlet_id]);
        }
        $order->load('outlet', 'table', 'items', 'paymentMode', 'payments.paymentMode', 'stay.room', 'user', 'compPostCompany', 'compPostStay.room', 'compPostStay.reservation.guest');

        return view('pos.bill', compact('order'));
    }

    public function printKot(Request $request, PosOrder $order, int $kot)
    {
        $order->load('outlet', 'table', 'stay.room', 'items.menuItem.category');
        $svc = app(KotPrintService::class);
        $printers = KotPrinter::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $all = $order->items->where('kot_no', $kot)->values();
        if ($jobId = $request->integer('job')) {
            $job = KotPrintJob::with('printer')->where('pos_order_id', $order->id)->where('kot_no', $kot)->findOrFail($jobId);
            $items = $all->whereIn('id', $job->item_ids ?? [])->values();
            if ($items->isEmpty()) {
                $items = PosOrderItem::whereIn('id', $job->item_ids ?? [])->orderBy('id')->get();
            }
            $slips = [[
                'printer' => $job->printer, 'kind' => $job->kind, 'items' => $items,
                'copies' => max(1, (int) $job->copies), 'sections' => [], 'void' => $job->kind === 'cancel',
            ]];
            $picked = collect([$job->kot_printer_id])->filter()->values();
        } else {
            $raw = $request->query('printers', []);
            $picked = collect(is_array($raw) ? $raw : explode(',', (string) $raw))->filter()->map(fn ($v) => (int) $v);
            if ($request->integer('printer')) {
                $picked->push($request->integer('printer'));
            }
            $picked = $picked->unique()->values();
            $slips = $svc->plan($order, $kot, [
                'printers' => $picked->all(),
                'combined' => $request->boolean('combined'),
                'void' => $request->boolean('void'),
                'force' => $request->boolean('force'),
                'captain' => $picked->isEmpty() && ! $request->boolean('combined') && ! $request->boolean('void'),
            ]);
        }

        return view('pos.kot', compact('order', 'kot', 'slips', 'printers', 'all', 'picked'));
    }

    /** Reprint, or release an ask-mode KOT, to the printers the user ticked. */
    public function reprintKot(Request $request, PosOrder $order, int $kot)
    {
        $data = $request->validate([
            'printers' => 'nullable|array|max:12', 'printers.*' => 'integer',
            'combined' => 'nullable|boolean', 'force' => 'nullable|boolean', 'void' => 'nullable|boolean',
        ]);
        if (empty($data['printers']) && ! $request->boolean('combined')) {
            throw ValidationException::withMessages(['printers' => 'Select a printer, or choose the combined sheet.']);
        }
        $svc = app(KotPrintService::class);
        $jobs = $svc->fire($order, $kot, [
            'mode' => 'reprint',
            'printers' => $data['printers'] ?? [],
            'combined' => $request->boolean('combined') && empty($data['printers']),
            'force' => $request->boolean('force'),
            'void' => $request->boolean('void'),
            'captain' => false,
        ]);
        $summary = $svc->jobSummary($jobs);

        return back()->with($jobs->contains(fn ($j) => $j->status === 'failed') ? 'warning' : 'success', $summary ? 'Print sent — '.$summary.'.' : 'Nothing to print for that choice.');
    }

    /** @return array{message: string, ask: ?int} */
    protected function fireKot(PosOrder $order, int $kot, array $opt = []): array
    {
        $svc = app(KotPrintService::class);
        $jobs = $svc->fire($order, $kot, $opt);
        $ask = $jobs->isEmpty() && ($opt['mode'] ?? 'auto') === 'auto' && empty($opt['combined'])
            && setting('pos_kot_print_mode', 'split') === 'ask' && $svc->forOutlet($order->outlet_id)->isNotEmpty();
        $summary = $svc->jobSummary($jobs);
        $message = $ask
            ? "KOT #{$kot} is on the kitchen display. Choose which printers to print."
            : ("KOT #{$kot} sent to kitchen".($summary ? " — {$summary}" : '').'.');

        return ['message' => $message, 'ask' => $ask ? 1 : null];
    }
}
