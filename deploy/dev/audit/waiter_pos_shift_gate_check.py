#!/usr/bin/env python3
"""Static security-wiring audit for waiter POS access tied to personal F&B shifts."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CONFIG = (ROOT / "config/pms.php").read_text()
PROVISIONER = (ROOT / "app/Services/HotelProvisioner.php").read_text()
SERVICE = (ROOT / "app/Services/DepartmentShiftService.php").read_text()
MIDDLEWARE = (ROOT / "app/Http/Middleware/RequireWaiterServiceShift.php").read_text()
BOOTSTRAP = (ROOT / "bootstrap/app.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
POS = (ROOT / "app/Http/Controllers/PosController.php").read_text()
TABLES = (ROOT / "app/Services/TableBookingService.php").read_text()
SHIFT_CONTROLLER = (ROOT / "app/Http/Controllers/DepartmentShiftController.php").read_text()
SHIFT_VIEW = (ROOT / "resources/views/department-shifts/index.blade.php").read_text()
MY_SHIFT_VIEW = (ROOT / "resources/views/my-shift/index.blade.php").read_text()
POS_TERMINAL_VIEW = (ROOT / "resources/views/pos/index.blade.php").read_text()
APP_LAYOUT = (ROOT / "resources/views/layouts/app.blade.php").read_text()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"FAIL: {message}")
    print(f"PASS: {message}")


require("'service_shift.waiter_required'" in CONFIG
        and "'service_shift.waiter_required'" in PROVISIONER.split("'Restaurant Waiter' =>", 1)[1].split("\n", 1)[0],
        "waiter shift gate is a registered permission granted to the default Waiter role")
require("requiresPersonalShift" in SERVICE and "service_shift.waiter_required" in SERVICE
        and "! $actor->hasPermission('pos.settle')" in SERVICE
        and "! $actor->hasPermission('pos.shift_view_all')" in SERVICE,
        "gate is scoped to waiter-level POS access, not cashier, manager, or admin roles")
require("DepartmentShiftService::requiresPersonalShift($user)" in MIDDLEWARE
        and "DepartmentShiftService::activeForUser((int) $user->id)" in MIDDLEWARE
        and "route('department-shifts.index')" in MIDDLEWARE,
        "closed-shift requests are redirected before POS terminal work is served")
require("'waiter.shift' => \\App\\Http\\Middleware\\RequireWaiterServiceShift::class" in BOOTSTRAP,
        "waiter shift middleware is registered")

pos_start = ROUTES.index("    Route::prefix('pos')->name('pos.'")
pos_end = ROUTES.index("    // ---- Banquet", pos_start)
pos_routes = ROUTES[pos_start:pos_end]
protected_names = ['index', 'guests', 'open', 'terminal', 'claim', 'kot', 'fire', 'item.cancel', 'item.comp',
                   'allergies', 'details', 'move', 'merge', 'split', 'item.served', 'bill', 'settle',
                   'delivery.status', 'refund', 'void', 'printkot', 'printkot.send', 'accept', 'reject']
for name in protected_names:
    lines = [line for line in pos_routes.splitlines() if f"->name('{name}')" in line]
    require(len(lines) == 1 and "middleware('waiter.shift')" in lines[0],
            f"POS workflow route {name} requires an open waiter shift")
require("Route::get('/orders', 'orders')->middleware('perm:pos.view')->name('orders')" in pos_routes
        and "Route::get('/dashboard', 'dashboard')->middleware('perm:pos.view')->name('dashboard')" in pos_routes,
        "read-only register and dashboard remain accessible without an open shift")
require("Route::post('/bookings/{tres}/seat', 'seat')->middleware('waiter.shift')" in ROUTES
        and "Route::post('/walk-in', 'walkIn')->middleware('waiter.shift')" in ROUTES,
        "table seating and walk-in order creation cannot bypass the shift gate")
require("DepartmentShiftService::activeForUser((int) auth()->id())?->id" in POS
        and "'department_shift_session_id' => $serviceShiftId" in TABLES,
        "orders created in POS or through table seating remain attributed to the active service shift")
require("redirect()->route('pos.index')" in SHIFT_CONTROLLER
        and "Open POS Terminal" in SHIFT_VIEW and "Open POS Terminal" in MY_SHIFT_VIEW
        and "locks the terminal until you open another shift" in SHIFT_VIEW,
        "opening a shift leads to the terminal and the shift page explains close behavior")
require("$waiterTerminal = DepartmentShiftService::requiresPersonalShift($request->user())" in POS
        and "DepartmentShiftService::activeForUser((int) $request->user()->id) !== null" in POS
        and "@section('waiterTerminal', '1')" in POS_TERMINAL_VIEW
        and "body.waiter-pos-fullscreen .sidebar" in POS_TERMINAL_VIEW
        and 'name="pos_table_id" value="{{ $t->id }}"' in POS_TERMINAL_VIEW
        and "Choose a table" in POS_TERMINAL_VIEW
        and "class=\"btn btn-primary\" href=\"{{ route('department-shifts.index') }}\"" in POS_TERMINAL_VIEW
        and "@hasSection('waiterTerminal') class=\"waiter-pos-fullscreen\"" in APP_LAYOUT,
        "waiters with an open shift land in a full-screen table grid with one-tap table order entry and shift controls")
print("Waiter POS service-shift gate audit passed.")
