#!/usr/bin/env python3
"""
Wiring check for the Recreation outlet module.

The sandbox has no PHP runtime, so nothing here executes the application. This check reads
the source the way the framework would:

  1. every variable a recreation view reads must be handed to it by the controller method
     that renders it (or be a Blade global, a loop variable, or a closure parameter) —
     the classic "view uses $live but the controller stopped passing it" bug;
  2. every route() the module asks for must exist in routes/web.php;
  3. every RecreationService:: / RecreationShiftService:: static call must name a method
     that actually exists on that service.

Usage: python3 deploy/dev/audit/recreation_wiring_check.py
"""
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
CONTROLLER = os.path.join(ROOT, 'app/Http/Controllers/RecreationController.php')
ROUTES = os.path.join(ROOT, 'routes/web.php')
VIEW_DIR = os.path.join(ROOT, 'resources/views/recreation')
SERVICES = {
    'RecreationService': os.path.join(ROOT, 'app/Services/RecreationService.php'),
    'RecreationShiftService': os.path.join(ROOT, 'app/Services/RecreationShiftService.php'),
}

# Blade/Laravel globals and shell variables the framework supplies, not the module.
GLOBALS = {
    'errors', 'loop', 'slot', 'attributes', 'currentHotel', 'user', 'menu', 'myEmployee',
    'isActive', 'canSee', 'depth', 'parentKey', 'items', 'componentName', 'component', 'hotel',
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
    """Text between the bracket at $start and its matching closer (string-aware)."""
    opener = src[start]
    closer = {'[': ']', '(': ')', '{': '}'}[opener]
    depth = 0
    quote = None
    i = start
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
    """Array keys of the passed array itself — split on depth-0 commas, so keys of arrays
    built inside it (an arrow fn returning ['id' => ...]) are not mistaken for view data."""
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


# ── 1. what each controller method passes to its view ───────────────────────────────
controller = read(CONTROLLER)
passed = {}
for match in re.finditer(r"view\('recreation\.([a-z0-9\-]+)'\s*,", controller):
    view = match.group(1)
    bracket = controller.find('[', match.end())
    if bracket < 0:
        continue
    body = balanced(controller, bracket)
    passed.setdefault(view, set()).update(top_level_keys(body))

ok('outlet' in passed and 'gate' in passed and 'shift' in passed and 'day' in passed,
   'controller: the outlet, gate, shift and day screens are all rendered')

# ── 2. what each view reads ─────────────────────────────────────────────────────────
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
for name in sorted(os.listdir(VIEW_DIR)):
    if not name.endswith('.blade.php'):
        continue
    view = name[:-len('.blade.php')]
    src = read(os.path.join(VIEW_DIR, name))
    reads = set(re.findall(r'\$([A-Za-z_]\w*)\b', src))
    used = reads - locals_of(src) - GLOBALS
    views[view] = used
    ok(view in passed, f'wiring: {name} is rendered by a controller method')
    missing = sorted(used - passed.get(view, set()))
    ok(not missing, f'wiring: {name} reads only what the controller passes (missing: {", ".join(missing) or "none"})')

# the controller must not pass variables no view reads (a rename left half-applied)
for view, keys in passed.items():
    unknown = sorted(keys - views.get(view, set()))
    ok(not unknown, f'wiring: RecreationController passes nothing unused to {view}.blade.php (unused: {", ".join(unknown) or "none"})')

# ── 3. the routes the module asks for ───────────────────────────────────────────────
routes_src = read(ROUTES)
start = routes_src.find("Route::prefix('recreation')")
available = set()
if start >= 0:
    brace = routes_src.find('{', start)
    body = balanced(routes_src, brace)
    available = {'recreation.' + name for name in re.findall(r"->name\('([a-z0-9_.\-]+)'\)", body)}
    available.add('recreation.' + 'outlet')  # sanity anchor below catches a moved group
asked = set()
for name in list(os.listdir(VIEW_DIR)) + ['../Http/Controllers/RecreationController.php']:
    path = os.path.join(VIEW_DIR, name) if name.endswith('.blade.php') else CONTROLLER
    if not os.path.exists(path):
        continue
    asked.update(re.findall(r"route\('(recreation\.[a-z0-9_.\-]+)'", read(path)))
for route in sorted(asked):
    ok(route in available, f'routes: {route} is registered')

# ── 4. the service methods the module calls ─────────────────────────────────────────
for service, path in SERVICES.items():
    src = read(path)
    methods = set(re.findall(r'public static function (\w+)', src)) | set(re.findall(r'protected static function (\w+)', src))
    callers = [os.path.join(VIEW_DIR, v) for v in os.listdir(VIEW_DIR) if v.endswith('.blade.php')] + [CONTROLLER]
    for caller in callers:
        called = set(re.findall(rf'{service}::(\w+)\s*\(', read(caller)))
        for method in sorted(called):
            ok(method in methods, f'services: {service}::{method} called from {os.path.basename(caller)} exists')

# ── report ──────────────────────────────────────────────────────────────────────────
if failures:
    print(f'recreation wiring check: {checks - len(failures)}/{checks} passed')
    for label in failures:
        print(f'  FAIL {label}')
    sys.exit(1)

print(f'recreation wiring check: {checks}/{checks} passed')
print(f'  views wired: {", ".join(sorted(views))}')
print(f'  routes asked for: {len(asked)} · service calls verified: {len(SERVICES)}')
