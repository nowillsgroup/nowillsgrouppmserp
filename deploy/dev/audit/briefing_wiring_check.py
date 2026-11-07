#!/usr/bin/env python3
"""
Wiring check for the daily briefings module (department packs + GM/CEO brief).

There is no PHP runtime in this sandbox, so nothing here executes the application. The
check reads the source the way the framework would:

  1. every variable a briefing view (or one of its partials) reads is handed to it by the
     controller method that renders it, and the controller passes nothing a view ignores;
  2. every pack declared in BriefingService::PACKS has a builder that exists, a permission
     that is in the catalogue, and is reachable from the sidebar (menu route + permission);
  3. every route('briefings.*') the module asks for is declared in the briefings group;
  4. every BriefingService:: / ManagementReportService:: / RecreationShiftService:: static
     call names a method that exists;
  5. the executive brief is guarded by a permission that exists and is offered in the menu.

Usage: python3 deploy/dev/audit/briefing_wiring_check.py
"""
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
CONTROLLER = os.path.join(ROOT, 'app/Http/Controllers/BriefingController.php')
SERVICE = os.path.join(ROOT, 'app/Services/BriefingService.php')
ROUTES = os.path.join(ROOT, 'routes/web.php')
CONFIG = os.path.join(ROOT, 'config/pms.php')
VIEW_DIR = os.path.join(ROOT, 'resources/views/briefings')
SERVICES = {
    'BriefingService': SERVICE,
    'ManagementReportService': os.path.join(ROOT, 'app/Services/ManagementReportService.php'),
    'RecreationShiftService': os.path.join(ROOT, 'app/Services/RecreationShiftService.php'),
    'StockTakeService': os.path.join(ROOT, 'app/Services/StockTakeService.php'),
    'BudgetService': os.path.join(ROOT, 'app/Services/BudgetService.php'),
}

# Supplied by Blade/Laravel or by @include, not by the controller.
GLOBALS = {
    'errors', 'loop', 'slot', 'attributes', 'currentHotel', 'user', 'menu', 'myEmployee',
    'isActive', 'canSee', 'depth', 'parentKey', 'items', 'componentName', 'component', 'hotel',
    # @include parameters the parent view hands down
    'section', 'pack', 'isExec', 'signed', 'packCount', 'byKey', 'scorecard', 'flash',
    'revenue', 'cash', 'risk', 'trend', 'ar', 'actions', 'signoff',
}

failures = []
checks = 0


def ok(condition, label):
    global checks
    checks += 1
    if not condition:
        failures.append(label)


def read(path):
    with open(path, encoding='utf-8') as handle:
        return handle.read()


def balanced(src, start):
    opener = src[start]
    closer = {'[': ']', '(': ')', '{': '}'}[opener]
    depth, quote, i = 0, None, start
    while i < len(src):
        ch = src[i]
        if quote:
            if ch == '\\':
                i += 2
                continue
            if ch == quote:
                quote = None
        elif ch in '"\'':
            quote = ch
        elif ch == opener:
            depth += 1
        elif ch == closer:
            depth -= 1
            if depth == 0:
                return src[start + 1:i]
        i += 1
    return ''


def top_level_keys(body):
    keys, depth, quote, start, i = set(), 0, None, 0, 0
    while i < len(body):
        ch = body[i]
        if quote:
            if ch == '\\':
                i += 2
                continue
            if ch == quote:
                quote = None
        elif ch in '"\'':
            quote = ch
        elif ch in '[({':
            depth += 1
        elif ch in '])}':
            depth -= 1
        elif ch == ',' and depth == 0:
            match = re.match(r"\s*'([A-Za-z_]\w*)'\s*=>", body[start:i])
            if match:
                keys.add(match.group(1))
            start = i + 1
        i += 1
    match = re.match(r"\s*'([A-Za-z_]\w*)'\s*=>", body[start:])
    if match:
        keys.add(match.group(1))
    return keys


# ── 1. what each controller method passes ───────────────────────────────────────────
controller = read(CONTROLLER)
passed = {}
for match in re.finditer(r"view\('briefings\.([a-z]+)'\s*,", controller):
    view = match.group(1)
    bracket = controller.find('[', match.end())
    if bracket < 0:
        continue
    passed.setdefault(view, set()).update(top_level_keys(balanced(controller, bracket)))

ok({'index', 'show', 'exec', 'print', 'book'} <= set(passed),
   'controller: the hub, department pack, executive brief, print sheet and the book are all rendered')

# ── 2. what each view (and partial) reads ───────────────────────────────────────────
def locals_of(src):
    names = set()
    for match in re.finditer(r'@(?:foreach|forelse)\s*\((.*?)\)', src, re.S):
        head = match.group(1)
        tail = head.split(' as ', 1)[1] if ' as ' in head else ''
        for part in tail.split('=>'):
            for name in re.findall(r'\$([A-Za-z_]\w*)', part):
                names.add(name)
    for match in re.finditer(r'@php(.*?)@endphp', src, re.S):
        for name in re.findall(r'\$([A-Za-z_]\w*)\s*=(?![=>])', match.group(1)):
            names.add(name)
    for match in re.finditer(r'\bfn\s*\(([^)]*)\)|\bfunction\s*\(([^)]*)\)', src):
        for name in re.findall(r'\$([A-Za-z_]\w*)', (match.group(1) or '') + (match.group(2) or '')):
            names.add(name)
    for match in re.finditer(r'use\s*\(([^)]*)\)\s*[{:]', src):
        for name in re.findall(r'\$([A-Za-z_]\w*)', match.group(1)):
            names.add(name)
    return names


views = {}
VIEWS = [('', 'index.blade.php'), ('', 'show.blade.php'), ('', 'exec.blade.php'), ('', 'print.blade.php'), ('', 'book.blade.php'),
         ('partials', 'band.blade.php'), ('partials', 'masthead.blade.php'), ('partials', 'attention.blade.php')]

for folder, file in VIEWS:
    path = os.path.join(VIEW_DIR, folder, file)
    name = 'partials/' + file if folder else file
    ok(os.path.exists(path), f'views: {name} exists')
    if not os.path.exists(path):
        continue
    src = read(path)
    used = set(re.findall(r'\$([A-Za-z_]\w*)\b', src)) - locals_of(src) - GLOBALS
    views[name] = used

for name, used in views.items():
    # partials are included from the controllers' views; their own reads are checked
    # against the union of what the controllers pass, which is what @include carries.
    allowed = set().union(*passed.values()) if name.startswith('partials/') else passed.get(name.split('.')[0], set())
    missing = sorted(used - allowed)
    ok(not missing, f'wiring: {name} reads only what the controller passes (missing: {", ".join(missing) or "none"})')

for view, keys in passed.items():
    unknown = sorted(keys - views.get(f'{view}.blade.php', set()) - GLOBALS)
    ok(not unknown, f'wiring: BriefingController passes nothing unused to {view}.blade.php (unused: {", ".join(unknown) or "none"})')

# ── 3. the pack registry ────────────────────────────────────────────────────────────
service = read(SERVICE)
registry = service[service.find('public const PACKS'):service.find('public const EXEC')]
packs = {}
for match in re.finditer(r"'([a-z]+)'\s*=>\s*\[(.*?)\],\s*(?='[a-z]+'\s*=>|\];)", registry, re.S):
    key, body = match.group(1), match.group(2)
    build = re.search(r"'build'\s*=>\s*'(\w+)'", body)
    perm = re.search(r"'perm'\s*=>\s*'([\w.]+)'", body)
    icon = re.search(r"'icon'\s*=>\s*'([\w-]+)'", body)
    role = re.search(r"'role'\s*=>\s*'([^']+)'", body)
    packs[key] = {'build': build.group(1) if build else None, 'perm': perm.group(1) if perm else None,
                  'icon': icon.group(1) if icon else None, 'role': role.group(1) if role else None}

ok(len(packs) >= 16, f'registry: the property has {len(packs)} department packs')
methods = set(re.findall(r'private static function (\w+)', service)) | set(re.findall(r'public static function (\w+)', service))
for key, pack in packs.items():
    ok(bool(pack['build']) and pack['build'] in methods, f'registry: pack {key} builds with an existing method ({pack["build"]})')
    ok(bool(pack['perm']), f'registry: pack {key} declares a permission')
    ok(bool(pack['role']), f'registry: pack {key} names the head who owns it')
    ok(bool(pack['icon']), f'registry: pack {key} declares an icon')

# permissions must be grantable: present in the catalogue
catalogue = read(CONFIG)
catalogue_block = catalogue[catalogue.find("'permissions'"):catalogue.find("'menu'")]
catalogue_keys = set(re.findall(r"'([a-z]+\.[a-z_]+)'\s*=>", catalogue_block))
for key, pack in packs.items():
    ok(pack['perm'] in catalogue_keys, f'permissions: {pack["perm"]} (pack {key}) is in the catalogue')
ok('reports.exec' in catalogue_keys, 'permissions: reports.exec is in the catalogue')

# icons must exist in the sprite
sprite = read(os.path.join(ROOT, 'resources/views/partials/icons.blade.php'))
symbols = set(re.findall(r'<symbol id="i-([\w-]+)"', sprite))
for key, pack in packs.items():
    ok(pack['icon'] in symbols, f'icons: {pack["icon"]} (pack {key}) exists in the sprite')

# the sidebar must offer the hub to every head: the menu item's perm list is any-of,
# so it has to contain every pack permission plus the executive key.
menu = catalogue[catalogue.find("'menu'"):]
hub = re.search(r"\['label' => 'Daily Briefings', 'route' => 'briefings\.index', 'perm' => '([^']+)'\]", menu)
ok(hub is not None, 'menu: the hub is in the sidebar')
if hub:
    offered = {p.strip() for p in hub.group(1).split(',')}
    for key, pack in packs.items():
        ok(pack['perm'] in offered, f'menu: a head with only {pack["perm"]} can see the hub')
    ok('reports.exec' in offered, 'menu: an executive with only reports.exec can see the hub')
ok(re.search(r"\['label' => 'Executive Brief', 'route' => 'briefings\.exec', 'perm' => 'reports\.exec'\]", menu) is not None,
   'menu: the executive brief is in the sidebar behind reports.exec')

# ── 4. the routes the module asks for ───────────────────────────────────────────────
routes_src = read(ROUTES)
start = routes_src.find("->prefix('briefings')")
ok(start >= 0, 'routes: the briefings group is declared')
available = set()
if start >= 0:
    body = balanced(routes_src, routes_src.find('{', start))
    available = {'briefings.' + name for name in re.findall(r"->name\('([a-z0-9_.\-]+)'\)", body)}
asked = set()
for folder, file in VIEWS:
    path = os.path.join(VIEW_DIR, folder, file)
    if os.path.exists(path):
        asked.update(re.findall(r"route\('(briefings\.[a-z0-9_.\-]+)'", read(path)))
asked.update(re.findall(r"route\('(briefings\.[a-z0-9_.\-]+)'", controller))
for route in sorted(asked):
    ok(route in available, f'routes: {route} is registered')

# every pack must be openable through the show route
ok('briefings.show' in available, 'routes: briefings.show exists, so every pack in the registry opens')

# ── 5. service calls ────────────────────────────────────────────────────────────────
for name, path in SERVICES.items():
    src = read(path)
    methods_here = set(re.findall(r'(?:public|protected|private) static function (\w+)', src))
    callers = [CONTROLLER, SERVICE] + [os.path.join(VIEW_DIR, f) for f in os.listdir(VIEW_DIR) if f.endswith('.blade.php')] \
        + [os.path.join(VIEW_DIR, 'partials', f) for f in os.listdir(os.path.join(VIEW_DIR, 'partials')) if f.endswith('.blade.php')]
    for caller in callers:
        for method in sorted(set(re.findall(rf'{name}::(\w+)\s*\(', read(caller)))):
            ok(method in methods_here, f'services: {name}::{method} called from {os.path.basename(caller)} exists')

# ── 6. the book and the two exports ─────────────────────────────────────────────────
book = read(os.path.join(VIEW_DIR, 'book.blade.php'))
attention = read(os.path.join(VIEW_DIR, 'partials', 'attention.blade.php'))
ok("@include('briefings.partials.attention'" in book, 'book: each part renders its open items through the shared partial')
ok("@include('briefings.partials.band'" in book, 'book: the book prints the same blocks as the pack screen')
ok('book-exec' in book and "book-{{ $p['key'] }}" in book, 'book: the contents links to a page per part')
ok("'exec' => BriefingService::exec($date)" in controller, 'book: the executive brief heads the book')

methods_in_controller = set(re.findall(r'(?:public|private) function (\w+)', controller))
for method in ['book', 'packCsv', 'execCsv', 'streamCsv', 'index', 'show', 'print', 'exec', 'execPrint', 'sign']:
    ok(method in methods_in_controller, f'controller: {method}() exists')
ok(len(re.findall(r'\$this->streamCsv\(', controller)) == 2, 'csv: both exports are written by the one routine')
ok(re.search(r"public function packCsv\(Request \$request, string \$pack\): StreamedResponse\s*\{\s*abort_unless\(isset\(BriefingService::PACKS\[\$pack\]\)", controller) is not None,
   'csv: an unknown pack is a 404, not an empty file')
ok(re.search(r"BriefingService::can\(\$pack, \$request->user\(\)\)", controller) is not None,
   'csv: a head can only export a pack they may read')

# the book is a management document: only a reader of the executive brief may open it
book_route = re.search(r"Route::get\('/book', 'book'\)->name\('book'\)", routes_src)
ok(book_route is not None, "routes: the book is served at /briefings/book")
ok(re.search(r"public function book\(Request \$request\)\s*\{\s*abort_unless\(BriefingService::can\('exec'", controller) is not None,
   'book: only a reader of the executive brief may open the whole book')

# the book prints every pack the reader may open
ok('->filter(fn ($key) => BriefingService::can($key, $request->user()))' in controller,
   'book: the book carries every pack the reader is allowed to see')
ok("collect(BriefingService::PACKS)->keys()" in controller, 'book: the book walks the whole registry, so a new department appears in it')

# styles the book relies on
css = read(os.path.join(ROOT, 'public/css/app.css'))
for rule in ['.brf-book-list', '.brf-book-part', '.brf-book-head', '.brf-book-state', '.brf-book-toc']:
    ok(rule in css, f'css: {rule} is styled')
ok('break-before: page' in css and '.brf-book-part' in css, 'css: each part starts a new printed page')

# the preview screens behind the book
build = read(os.path.join(ROOT, 'deploy/dev/build-briefing-preview.mjs'))
server = read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
gallery = read(os.path.join(ROOT, 'deploy/dev/previews/index.html'))
checker = read(os.path.join(ROOT, 'deploy/dev/audit/briefing_preview_check.js'))
sample = read(os.path.join(ROOT, 'deploy/dev/preview/briefing-sample.mjs'))
ok("view: 'briefings/book'" in build and 'briefing-book.html' in build, 'preview: the build renders the book')
ok("'/briefing-book'" in server, 'preview: the book has a preview address')
ok('/__preview/briefing-book' in gallery, 'preview: the book is in the gallery')
ok('briefing-book.html' in checker, 'preview: the book is under the behavioural check')
ok('briefingBookContext' in sample, 'preview: the book has sample data of its own')

# ── 7. sign-off compliance over the week ────────────────────────────────────────────
ok('public static function compliance(Carbon $date, int $days = 7): Collection' in service,
   'compliance: BriefingService::compliance() is declared with a window')
ok(service.count('self::compliance(') >= 2, 'compliance: both the hub and the executive brief read it')
ok(re.search(r"BriefingSignoff::whereDate\('business_date', '>=', \$start->toDateString\(\)\)", service) is not None,
   'compliance: the week is read in one query, not one per department')
ok("self::table('compliance'" in service, 'compliance: the executive brief prints a compliance band')
ok("'compliance' => $compliance->all()" in service, 'compliance: the executive brief hands the band to its views')
ok(re.search(r"for \(\$i = 0; \$i < \$days; \$i\+\+\) \{\s*if \(! \$signed->has\(", service) is not None,
   'compliance: the run of days stops at the first business date the head did not sign')

index_view = read(os.path.join(VIEW_DIR, 'index.blade.php'))
ok("$p['week_signed']" in index_view and "$p['week_days']" in index_view,
   'compliance: the hub card states the week the head has signed')
ok("'week_signed' => $week[$key]['signed'] ?? 0" in service, 'compliance: forUser carries each pack its own week figure')
ok('.brf-card-week' in css and '.brf-card-week.is-thin' in css, 'compliance: a thin signing record is styled apart')
ok('week_signed: WEEK[key]' in sample, 'compliance: the previews carry a week of sign-offs to read')

# ── 8. profit and the budget comparison ─────────────────────────────────────────────
ok('private static function profit(Carbon $d): array' in service, 'profit: BriefingService::profit() is declared')
ok('private static function againstBudget(Carbon $d): ?array' in service, 'budget: BriefingService::againstBudget() is declared')
ok(service.count('self::profit($d)') >= 1 and service.count('self::againstBudget($d)') >= 1,
   'profit: the executive brief computes both the P&L and the budget comparison')
ok(re.search(r"if \(! \$budget\) \{\s*return null;", service) is not None,
   'budget: with no approved budget the brief returns nothing to compare, rather than a fabricated one')
ok("->where('closing', false)" in service,
   'profit: closing entries are left out of the trading figures')
ok("BudgetService::SECTIONS" in service and "BudgetService::accounts()" in service,
   'profit: the brief classifies the ledger exactly as the budget does')
ok("BudgetService::active($fy)" in service, 'budget: only an approved, activated budget is compared against')
ok("self::table('pl'," in service and "self::table('budget'," in service,
   'profit: both the P&L and the budget comparison print as bands')
ok("'pl' => [" in service and "'budget' => $budget," in service,
   'profit: the executive brief hands both to its views and to the exports')

# ── 9. the profit and budget bands are shared, not copied ───────────────────────────
ok('private static function plSection(array $pl): array' in service, 'profit: plSection() is declared')
ok('private static function budgetSection(?array $budget, string $fy): array' in service, 'budget: budgetSection() is declared')
ok(service.count('self::plSection($pl)') == 2, 'profit: the GM brief and the finance pack print the same band')
ok(service.count('self::budgetSection($budget, $pl[\'fy\'])') == 2, 'budget: both packs compare the month against the same budget')
ok(service.count("self::table('pl',") == 1 and service.count("self::table('budget',") == 1,
   'profit: each band is defined once, so the two packs cannot drift apart')

finance = service[service.find('private static function buildFinance'):]
finance = finance[:finance.find('private static function buildGuestExp')]
ok('self::plSection($pl)' in finance and 'self::budgetSection($budget, $pl[\'fy\'])' in finance,
   'finance: the controller\'s pack carries the profit lines and the budget comparison')
ok('$pl = self::profit($d);' in finance and '$budget = self::againstBudget($d);' in finance,
   'finance: the finance pack reads the same ledger helpers')

ok("view: 'briefings/show', file: 'briefing-finance.html'" in build, 'preview: the build renders the finance pack')
ok("'/briefing-finance'" in server, 'preview: the finance pack has a preview address')
ok('/__preview/briefing-finance' in gallery, 'preview: the finance pack is in the gallery')
ok('briefing-finance.html' in checker, 'preview: the finance pack is under the behavioural check')
ok('briefingFinanceContext' in sample, 'preview: the finance pack has sample data of its own')

# ── 10. the thin packs now carry the detail their head needs ────────────────────────
def builder(name):
    body = service[service.find('private static function '+name+'('):]
    return body[:body.find('private static function ', 10)]

hr = builder('buildHr')
for band_key, label in [('departments', 'attendance by department'), ('leave', 'the leave queue'), ('ending', 'contracts and probation ending')]:
    ok("self::table('%s'" % band_key in hr, f'hr: the pack prints {label}')
ok("Attendance::whereDate('date', $ds)->get(['employee_id', 'status'])" in hr,
   'hr: attendance is read per employee, so it can be compared department by department')
ok('RosterShift::whereDate' in hr, 'hr: the roster is read beside the clock')

parking = builder('buildParking')
for band_key, label in [('zones', 'the bays by zone'), ('curve', 'the drop-off curve'), ('permits', 'permits falling due')]:
    ok("self::table('%s'" % band_key in parking, f'parking: the pack prints {label}')
ok("'parking_zone_id', 'entered_at'" in parking, 'parking: sessions carry the zone and the time in, so both can be reported')

for screen, context in [('briefing-hr.html', 'briefingHrContext'), ('briefing-parking.html', 'briefingParkingContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-hr'" in server and "'/briefing-parking'" in server, 'preview: both packs have a preview address')
ok('/__preview/briefing-hr' in gallery and '/__preview/briefing-parking' in gallery, 'preview: both packs are in the gallery')

# ── 11. front office and security carry the lists their heads work from ─────────────
fo = builder('buildFrontOffice')
for band_key, label in [('arriving', 'the arrivals still to run'), ('departing', 'the departures to settle')]:
    ok("self::table('%s'" % band_key in fo, f'frontoffice: the pack prints {label}')
ok("Folio::with('activeTransactions')" in fo, 'frontoffice: the folio balance is read for the departures')
ok('$guestName' in fo, 'frontoffice: guest names are read once, not five times over')

sec = builder('buildSecurity')
for band_key, label in [('onsite', 'who is on site now'), ('keys', 'the master keys signed out')]:
    ok("self::table('%s'" % band_key in sec, f'security: the pack prints {label}')
ok("\\App\\Models\\KeyLoan::with('key')" in sec, 'security: the key loan reads the key it is for')
ok("Visitor::where('status', 'in')" in sec, 'security: who is inside is read from the door, not inferred')

for screen, context in [('briefing-frontoffice.html', 'briefingFrontOfficeContext'), ('briefing-security.html', 'briefingSecurityContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-frontoffice'" in server and "'/briefing-security'" in server, 'preview: both packs have a preview address')
ok('/__preview/briefing-frontoffice' in gallery and '/__preview/briefing-security' in gallery, 'preview: both packs are in the gallery')

# ── 12. kitchen and stores carry the lists their heads work from ────────────────────
kit = builder('buildKitchen')
for band_key, label in [('pass', 'what is still on the pass'), ('allergens', 'the allergen lines to verify'), ('temps', 'the readings outside the safe range')]:
    ok("self::table('%s'" % band_key in kit, f'kitchen: the pack prints {label}')
ok("->where('in_range', false)" in kit, 'kitchen: only the readings that failed are listed')

st = builder('buildStores')
for band_key, label in [('queue', 'the counter queue'), ('deliveries', 'the deliveries received')]:
    ok("self::table('%s'" % band_key in st, f'stores: the pack prints {label}')
ok("\\App\\Models\\Requisition::with('department:id,name')" in st, 'stores: the queue names the department waiting')
ok("\\App\\Models\\GoodsReceipt::with('supplier:id,name')" in st, 'stores: a delivery names its supplier')

for screen, context in [('briefing-kitchen.html', 'briefingKitchenContext'), ('briefing-stores.html', 'briefingStoresContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-kitchen'" in server and "'/briefing-stores'" in server, 'preview: both packs have a preview address')
ok('/__preview/briefing-kitchen' in gallery and '/__preview/briefing-stores' in gallery, 'preview: both packs are in the gallery')

# ── 13. revenue and engineering carry the decisions and the delays ──────────────────
rev = builder('buildRevenue')
for band_key, label in [('auth', 'the rate decisions waiting'), ('leakage', 'what was given away today')]:
    ok("self::table('%s'" % band_key in rev, f'revenue: the pack prints {label}')
ok("\\App\\Models\\RevenueAuthorisation::with('requester:id,name')" in rev, 'revenue: a request names who asked for it')
ok("whereIn('type', ['discount', 'allowance'])" in rev, 'revenue: leakage is read from the discount and allowance postings')

mnt = builder('buildMaintenance')
for band_key, label in [('overdue', 'the jobs past their promised date'), ('soon', 'planned work for the next seven days')]:
    ok("self::table('%s'" % band_key in mnt, f'maintenance: the pack prints {label}')
ok("get(['ticket_no', 'due_date', 'priority', 'title', 'location', 'room_id', 'status'])" in mnt,
   'maintenance: an open job carries its ticket and where it is')

for screen, context in [('briefing-revenue.html', 'briefingRevenueContext'), ('briefing-maintenance.html', 'briefingMaintenanceContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-revenue'" in server and "'/briefing-maintenance'" in server, 'preview: both packs have a preview address')
ok('/__preview/briefing-revenue' in gallery and '/__preview/briefing-maintenance' in gallery, 'preview: both packs are in the gallery')

# ── 14. sales shows the quiet enquiries and the losses; guest experience the cases ──
sal = builder('buildSales')
for band_key, label in [('quiet', 'the enquiries that have gone quiet'), ('won', 'what was won today'), ('lost_detail', 'what was lost and why')]:
    ok("self::table('%s'" % band_key in sal, f'sales: the pack prints {label}')
ok("subDays(14)" in sal and "stage_changed_at" in sal, 'sales: going quiet means nothing moved for a fortnight')
ok("$o->lost_reason" in sal and "'Not recorded'" in sal, 'sales: a loss with no reason is called out')

gex = builder('buildGuestExp')
for band_key, label in [('urgent', 'the cases that need an answer'), ('written', 'what the guests wrote')]:
    ok("self::table('%s'" % band_key in gex, f'guest experience: the pack prints {label}')
ok("Str::limit((string) $f->comments" in gex, 'guest experience: the guest comment is quoted, not summarised')
ok("orderByRaw(\"case when severity = 'high' then 0 else 1 end\")" in gex, 'guest experience: the most severe case comes first')

for screen, context in [('briefing-sales.html', 'briefingSalesContext'), ('briefing-guestexp.html', 'briefingGuestExpContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-sales'" in server and "'/briefing-guestexp'" in server, 'preview: both packs have a preview address')
ok('/__preview/briefing-sales' in gallery and '/__preview/briefing-guestexp' in gallery, 'preview: both packs are in the gallery')

# ── 15. banquet publishes before service; IT names the failure and the endpoint ─────
ban = builder('buildBanquet')
for band_key, label in [('beos', 'the event orders still unpublished'), ('unbilled', 'the closed events not yet billed')]:
    ok("self::table('%s'" % band_key in ban, f'banquet: the pack prints {label}')
ok("! $e->beo_published_at" in ban, 'banquet: unpublished means the order has never been published')
ok("whereNull('billed_at')" in ban and "whereNotNull('closed_at')" in ban,
   'banquet: unbilled means the event has closed and the bill has not been raised')

itp = builder('buildIt')
for band_key, label in [('broken', 'every command that failed today'), ('endpoints', 'the endpoints not answering')]:
    ok("self::table('%s'" % band_key in itp, f'it: the pack prints {label}')
ok("whereIn('status', ['failed', 'error'])" in itp, 'it: a failed command is read, not just counted')
ok("with('endpoint:id,name')" in itp, 'it: a failing endpoint is named rather than numbered')

for screen, context in [('briefing-banquet.html', 'briefingBanquetContext'), ('briefing-it.html', 'briefingItContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-banquet'" in server and "'/briefing-it'" in server, 'preview: both packs have a preview address')
ok('/__preview/briefing-banquet' in gallery and '/__preview/briefing-it' in gallery, 'preview: both packs are in the gallery')

# ── 16. fleet reports the board, the crew, the money and the workshop ───────────────
# The pack that was one table about trips now answers the supervisor's whole morning:
# which car may legally move, who may drive it, what each trip earned and whether the
# money reached a folio, what the cars cost against the kilometres they covered, and
# what is in the workshop. It reads the transport desk's own summary rather than
# recomputing it, so the briefing and the transport screen cannot disagree.
flt = builder('buildFleet')
for band_key, kind, label in [('mv', 'table', 'the movements by type'), ('board', 'table', 'the board, car by car'),
        ('crew', 'table', 'the drivers and their licences'), ('billing', 'table', 'how each trip was billed'),
        ('cost', 'table', 'fuel and upkeep against kilometres'), ('workshop', 'table', 'the workshop queue'),
        ('faults', 'table', 'defects, incidents and failed inspections'), ('rentals', 'table', 'the self-drive rentals'),
        ('desk', 'items', 'what the desk must fix')]:
    ok(f"self::{kind}('{band_key}'" in flt, f'fleet: the pack prints {label}')

ok('TransportService::dayStats($d)' in flt and 'TransportService::alerts()' in flt,
   'fleet: the pack reads the desk\'s own day and alert counts instead of recomputing them')
ok("VehicleExpense::whereDate('date', $ds)" in flt, 'fleet: running cost is read from the expense entries for the date')
ok("Vehicle::where('is_active', true)" in flt and 'expiredPapers(' in flt and 'expiringPapers(' in flt,
   'fleet: the board asks the vehicle what its papers say, it does not re-read four columns')
ok("Driver::where('is_active', true)" in flt and 'licenceExpired(' in flt,
   'fleet: the crew list asks the driver whether the licence is still good')
ok("CarRental::whereIn('status', ['reserved', 'picked_up'])" in flt, 'fleet: the rentals on the road are the open agreements')
ok("VehicleMaintenanceRecord::whereIn('status', ['scheduled', 'in_progress'])" in flt, 'fleet: the workshop is the open work orders')
ok("VehicleIncident::whereIn('status', ['open', 'investigating'])" in flt, 'fleet: only open incidents are reported')
ok('folio_transaction_id' in flt and 'check-out' in flt,
   'fleet: a room trip that has not reached a folio says it posts at check-out, which is what the code does')
ok('which car can legally move' in service, 'fleet: the pack brief says what the pack now holds')

for screen, context in [('briefing-fleet.html', 'briefingFleetContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-fleet'" in server, 'preview: the fleet pack has a preview address')
ok('/__preview/briefing-fleet' in gallery, 'preview: the fleet pack is in the gallery')

# the executive brief names the tables it actually reads — the P&L and the budget
# comparison were added to the brief and the sources line still said "all department tables"
exec_pack = builder('buildExec')
src_line = re.search(r"'sources' => '((?:[^'\\]|\\.)*)'", exec_pack)
ok(src_line is not None, 'exec: the brief declares where its figures come from')
sources = src_line.group(1) if src_line else ''
for table in ('gl_lines', 'budgets', 'budget_lines', 'night_audits', 'folio_transactions', 'pos_orders'):
    ok(table in sources, f'exec: the sources line names {table}, which the brief actually reads')
ok('all department tables' not in sources.replace('and every department pack above', ''),
   'exec: the sources line no longer hides behind "all department tables"')

# ── 17. recreation reports the till roll, the gate, the drawer and the tax split ────
# The outlet is a revenue department, so its pack has to answer what a Z-report answers:
# what was rung, how it was paid, which passes the gate actually saw, who held the float
# and whether the drawer balanced, and how the tax splits for the return. It reads the
# outlet's own day report rather than recomputing it, so the briefing and the close of
# shift cannot disagree.
rec = builder('buildRecreation')
for band_key, kind, label in [('tender', 'table', 'takings by tender'), ('type', 'table', 'what sold line by line'),
        ('passes', 'table', 'the passes sold and whether the gate saw them'), ('sales', 'table', 'every sale on the till roll'),
        ('category', 'table', 'the category split the Z-report uses'), ('shift', 'table', 'the shift drawer'),
        ('tax', 'table', 'the tax split'), ('gate', 'items', 'the gate and drawer reconciliation'),
        ('cashiers', 'table', 'who sold what')]:
    ok(f"self::{kind}('{band_key}'" in rec, f'recreation: the pack prints {label}')

ok('RecreationShiftService::dayReport($ds)' in rec,
   'recreation: the pack reads the outlet\'s own day report instead of recomputing it')
ok('TaxService::breakdown(' in rec and 'TaxService::COMPONENTS' in rec,
   'recreation: the tax is split by the statutory components, not invented')
ok("ClubDayPass::whereDate('date', $ds)" in rec, 'recreation: the gate is read from the passes sold for the date')
ok("'redeemed_at'" in rec and 'Not presented' in rec,
   'recreation: a pass the gate has not seen is called out, which is the gap between sales and admissions')
ok("$s->counted_cash === null" in rec and "expected_cash" in rec,
   'recreation: the drawer is reported from the session close, not from a fresh count')
ok("$s->variance === null ? '—'" in rec, 'recreation: a shift that has not been counted shows no variance rather than zero')
ok('from the drawer' in service, 'recreation: change given is explained, not left as a bare figure')

for screen, context in [('briefing-recreation.html', 'briefingRecreationContext')]:
    ok(screen in build, f'preview: {screen} is built')
    ok(screen in checker, f'preview: {screen} is under the behavioural check')
    ok(context in sample, f'preview: {context} supplies its sample data')
ok("'/briefing-recreation'" in server, 'preview: the recreation pack has a preview address')
ok('/__preview/briefing-recreation' in gallery, 'preview: the recreation pack is in the gallery')
ok('the tax split the way the return is filed' in service, 'recreation: the pack brief says what the pack now holds')

# ── report ──────────────────────────────────────────────────────────────────────────
if failures:
    print(f'briefing wiring check: {checks - len(failures)}/{checks} passed')
    for label in failures:
        print(f'  FAIL {label}')
    sys.exit(1)

print(f'briefing wiring check: {checks}/{checks} passed')
print(f'  packs: {len(packs)} · views wired: {", ".join(sorted(views))}')
print(f'  routes asked for: {len(asked)} · permissions checked: {len(packs) + 1}')
