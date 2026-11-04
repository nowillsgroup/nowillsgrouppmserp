#!/usr/bin/env python3
"""Static wiring audit for the tenant-scoped POS dashboard."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/PosController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
NAV = (ROOT / "resources/views/pos/_workspace-nav.blade.php").read_text()
VIEW = (ROOT / "resources/views/pos/dashboard.blade.php").read_text()
CSS = (ROOT / "public/css/app.css").read_text()
ICONS = (ROOT / "resources/views/partials/icons.blade.php").read_text()
ACTIVITY_LOG_CONTROLLER = (ROOT / "app/Http/Controllers/ActivityLogController.php").read_text()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"FAIL: {message}")
    print(f"PASS: {message}")


require("Route::get('/dashboard', 'dashboard')->middleware('perm:pos.view')->name('dashboard');" in ROUTES,
        "dashboard route is protected by POS view permission")
require("route('pos.dashboard'" in NAV and "route('pos.index'" in NAV,
        "workspace navigation keeps Dashboard and the existing terminal")
require("whereBetween('business_date', $dateRange)" in CONTROLLER and "diffInDays($to) > 30" in CONTROLLER,
        "date-range reporting is bounded to 31 days")
require("$previousDateRange = [$previousFrom->toDateString(), $previousTo->toDateString()]" in CONTROLLER
        and "'sales' => $percentChange" in CONTROLLER and "'turnaround' => $percentChange" in CONTROLLER
        and "$previousCollections" in CONTROLLER and "$previousComplimentaryChecks" in CONTROLLER
        and "$previousSummary = $summaryQuery($previousOrders)" in CONTROLLER,
        "KPI percentage changes compare outlet-scoped values with the immediately preceding equal-length period")
require(all(f"$kpiChanges['{metric}']" in VIEW for metric in ['sales', 'collections', 'orders', 'complimentary', 'turnaround', 'discounts'])
        and VIEW.count('vs prior period') >= 6 and "is_null($kpiChanges['sales'])" in VIEW,
        "all six summary cards show signed percentage changes and handle a zero comparison baseline")
require("$selectedOutlet = $outletId ? $outlets->firstWhere('id', $outletId) : null;" in CONTROLLER
        and "is_string($value) ? $value : ''" in CONTROLLER,
        "outlet and date filters safely resolve against tenant-owned inputs")
require("DEMO PREVIEW ·%" in CONTROLLER,
        "local preview fixtures are excluded from operational analytics")
require("whereIn('kind', ['payment', 'refund'])" in CONTROLLER
        and "whereDoesntHave('paymentMode', fn ($q) => $q->where('type', 'city_ledger'))" in CONTROLLER
        and "SUM(CASE WHEN kind = 'refund' THEN -amount ELSE amount END) as net_amount" in CONTROLLER,
        "collections and payment-mode graph net refunds in the database aggregate")
require("$complimentaryBillIds->union($itemCompOrderIds)" in CONTROLLER
        and "fromSub($complimentaryBillIds->union($itemCompOrderIds), 'complimentary_orders')" in CONTROLLER,
        "complimentary checks combine bill-level and item-level comps without loading all IDs")
require("'with_comps' => 'nullable|in:1'" in CONTROLLER and 'name="with_comps"' in (ROOT / "resources/views/pos/orders.blade.php").read_text()
        and "$compBillsUrl = route('pos.orders'" in VIEW and "'with_comps' => '1'" in VIEW
        and "->when($withComps, fn ($q) => $q->where('status', 'settled')" in CONTROLLER
        and "where('is_complimentary', true)->where('is_cancelled', false)" in CONTROLLER,
        "complimentary KPI drills into settled checks with bill- or item-level comps")
require("whereColumn('served_at', '>=', 'created_at')" in CONTROLLER and "chunkById(1000" in CONTROLLER
        and "'turnaroundItemsCount' => $turnaroundCount" in CONTROLLER and "served items" in VIEW
        and "'with_served' => 'nullable|in:1'" in CONTROLLER and 'name="with_served"' in (ROOT / "resources/views/pos/orders.blade.php").read_text()
        and "$servedBillsUrl = route('pos.orders'" in VIEW and "@else—@endif" in VIEW
        and "->when($withServed" in CONTROLLER and "whereNotNull('served_at')" in CONTROLLER
        and "whereNotNull('created_at')->whereNotNull('served_at')" in CONTROLLER,
        "turnaround reports a bounded-memory sample size, avoids a false zero average, and drills into matching orders")
require("['void', 'cancel_item', 'comp_item', 'refund']" in CONTROLLER,
        "revenue-control counters map to recorded POS audit events")
require("$previousFrom = $previousTo->copy()->subDays($periodDays - 1)->startOfDay()" in CONTROLLER
        and "['cancel_item', 'invoice_reprint', 'void', 'comp_item', 'comp_undo', 'invoice_delete']" in CONTROLLER
        and "'items_changed'" in CONTROLLER and "'invoices_deleted'" in CONTROLLER
        and "'leakageWatch' => $leakageWatch" in CONTROLLER,
        "leakage watch compares scoped POS audit events to the previous equal-length period")
require("$printedBefore ? 'invoice_reprint' : 'invoice_print'" in CONTROLLER
        and "Reprinted POS invoice" in CONTROLLER,
        "invoice reprints are logged distinctly and without counting the first print as a reprint")
require("Leakage watch" in VIEW and "Things to check for lost revenue" in VIEW
        and all(label in CONTROLLER for label in ['Items removed', 'Invoices re-printed', 'Orders cancelled', 'Items changed', 'Invoices deleted'])
        and "was {{ number_format($metric['previous']) }}" in VIEW,
        "dashboard renders the five leakage-watch indicators and previous-period comparison")
require("can_do('logs.view') ? route('logs.index', $leakageFilters) : null" in VIEW
        and "$leakageFilters = ['from' => $from, 'to' => $to, 'module' => 'pos']" in VIEW,
        "leakage indicators drill into the selected-period POS audit log only for authorized users")
require("can_do('logs.view')" in VIEW and "route('logs.index'" in VIEW
        and "'q' => $control['search']" in VIEW,
        "revenue-control counters drill into permission-gated, action-filtered audit logs")
require("where('description', 'like', \"%$t%\")" in ACTIVITY_LOG_CONTROLLER
        and all(CONTROLLER.count(term) >= 2 for term in ['Voided order', 'Cancelled', 'Complimentary', 'Refunded']),
        "action-filter terms match the POS audit descriptions")
require("where('poi.is_complimentary', false)->where('poi.amount', '>', 0)" in CONTROLLER
        and "whereNotIn('poi.item_name', $topItemNames)" in CONTROLLER
        and "All sold items are already in the top list" in VIEW,
        "item rankings omit invalid lines and avoid overlapping high/low lists")
require("'status' => 'settled'" in VIEW and "'outlet' => $segment['id']" in VIEW and "'from' => $day['date']" in VIEW,
        "stacked outlet/day chart drills into the matching settled bills")
require("$settledBillsUrl = route('pos.orders'" in VIEW and "$allOrdersUrl = route('pos.orders'" in VIEW
        and "$voidedBillsUrl = route('pos.orders', $billFilters + ['status' => 'void'])" in VIEW,
        "sales, discount, order, and voided KPIs link to the filtered bills register")
orders_view = (ROOT / "resources/views/pos/orders.blade.php").read_text()
require("where('payment_mode_id', $mode)" in CONTROLLER and "owned:payment_modes" in CONTROLLER
        and 'name="payment_mode_id"' in orders_view and "payment_mode_id' => $payment['id']" in VIEW
        and "number_format($payment['checks'])" in VIEW,
        "payment-mode bars drill into tenant-scoped bills filtered by tender")
require("'order_type' => 'nullable|in:dine_in,takeaway,room_service,delivery,other'" in CONTROLLER
        and "$otherTypeRows = $typeRows->except(array_keys($typeLabels))" in CONTROLLER
        and "$type === 'other'" in CONTROLLER
        and "'other' => 'Other'" in orders_view and 'name="order_type"' in orders_view
        and "'order_type' => $type['type']" in VIEW,
        "order-type legend drills into the filtered bills register")
require("'item_name' => 'nullable|string|max:120'" in CONTROLLER
        and "->where('item_name', $name)" in CONTROLLER and 'name="item_name"' in orders_view
        and "'item_name' => $item->item_name" in VIEW,
        "top/low-selling items drill into matching settled bills")
require("Sales by order type" in VIEW and "Collections by payment mode" in VIEW
        and "number_format($type['checks'])" in VIEW and "number_format((float) $item->qty, 2)" in VIEW
        and "Top selling items" in VIEW and "Low selling items" in VIEW,
        "dashboard includes the requested graph and ranked-item panels")
require(".pos-dash-stats { display: grid; grid-template-columns: repeat(6" in CSS
        and "@media (max-width: 650px)" in CSS,
        "six summary cards collapse responsively")
used = set(re.findall(r'<x-icon\s+name="([a-z-]+)"', VIEW)) | {"trash", "x", "cup", "refresh"}
available = set(re.findall(r'<symbol\s+id="i-([^"]+)"', ICONS))
require(used <= available, f"all dashboard icons exist ({', '.join(sorted(used))})")
print("POS dashboard static audit passed.")
