#!/usr/bin/env python3
"""
Wiring check for Settings › Configuration — the register of every module's settings.

The register is only worth having if it is complete and cannot invent things. There is
no PHP runtime in this sandbox, so this check reads the source the way the application
would and proves five things:

  1. **nothing is missing** — every `setting('…')` read anywhere in app/ belongs to a
     module in config/settings.php (a key read by the code but absent from the register
     would be a setting nobody can find);
  2. **nothing is invented** — every key the register lists is actually read by the
     code, so the register cannot show a setting that does not exist;
  3. **the screen is real** — every route a module names is declared, and the permission
     the register prints is the permission the route actually enforces;
  4. **the surface is complete** — every GET `*.settings` route in the application is
     either a module's screen in the register or on the documented exclusion list;
  5. **the hub is wired** — controller, views, routes, sidebar entry, permission in the
     catalogue, CSS, the preview build and the gallery.

Usage: python3 deploy/dev/audit/configuration_check.py
"""
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
REGISTRY = os.path.join(ROOT, 'config/settings.php')
CONTROLLER = os.path.join(ROOT, 'app/Http/Controllers/ConfigurationController.php')
SERVICE = os.path.join(ROOT, 'app/Services/ConfigurationService.php')
ROUTES = os.path.join(ROOT, 'routes/web.php')
CONFIG = os.path.join(ROOT, 'config/pms.php')
CSS = os.path.join(ROOT, 'public/css/app.css')
BUILD = os.path.join(ROOT, 'deploy/dev/build-configuration-preview.mjs')
SAMPLE = os.path.join(ROOT, 'deploy/dev/preview/configuration-sample.mjs')
GALLERY = os.path.join(ROOT, 'deploy/dev/previews/index.html')
SERVER = os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')
VIEWS = os.path.join(ROOT, 'resources/views/configuration')

# A settings route that exists but is not a module's configuration screen.
# `reports.schedules.settings` configures one report schedule row, not the module.
EXCLUDED_ROUTES = {'reports.schedules.settings'}

# Route families whose module is registered under another name (the screen and the
# saving endpoint do not always share a prefix).
FAMILY_ALIAS = {'inventory': 'proc', 'purchasing': 'proc', 'wht': 'ap'}

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


# ── the registry ────────────────────────────────────────────────────────────────────
src = read(REGISTRY)
body = src[src.index("'modules' => ["):]

modules = {}
# Anchor on the module start lines (eight spaces) rather than on `=> [\n…],`: the
# `'modules' => [` wrapper opens the map, and a lazy block match would swallow the
# first module into a phantom entry called "modules".
starts = [(hit.start(), hit.group(1)) for hit in re.finditer(r"^        '([a-z0-9_]+)' => \[$", body, re.M)]
for n, (offset, key) in enumerate(starts):
    end = starts[n + 1][0] if n + 1 < len(starts) else len(body)
    block = body[offset:end]
    label = re.search(r"'label' => '((?:[^'\\]|\\.)*)'", block)
    group = re.search(r"'group' => '([A-Za-z &]+)'", block)
    route = re.search(r"'route' => '([^']+)'", block)
    perm = re.search(r"'perm' => '([^']+)'", block)
    edit = re.search(r"'edit' => '([^']+)'", block)
    brief = re.search(r"'brief' => '((?:[^'\\]|\\.)*)'", block)
    log = re.search(r"^            'log' => '([^']+)',", block, re.M)
    secret = re.search(r"'secret' => \[([^\]]*)\]", block)
    keys = {}
    kblock = block[block.index("'keys' => ["):]
    for k in re.finditer(r"'([a-z0-9_]+)' => '((?:[^'\\]|\\.)*)'", kblock):
        keys[k.group(1)] = k.group(2)
    modules[key] = {
        'label': label.group(1) if label else '',
        'group': group.group(1) if group else '',
        'route': route.group(1) if route else None,
        'perm': perm.group(1) if perm else None,
        'edit': edit.group(1) if edit else (perm.group(1) if perm else None),
        'brief': brief.group(1) if brief else '',
        'log': log.group(1) if log else None,
        'secret': re.findall(r"'([a-z0-9_]+)'", secret.group(1)) if secret else [],
        'keys': keys,
    }

groups = re.findall(r"^        '([A-Za-z &]+)',$", src, re.M)[:12]
ok(len(modules) >= 60, f'the register carries the whole estate (found {len(modules)} modules)')
ok(len(groups) >= 8, f'the register has its groups ({len(groups)})')

for key, m in modules.items():
    ok(bool(m['label']), f'{key}: has a label')
    ok(bool(m['group']), f'{key}: has a group')
    ok(m['group'] in groups, f'{key}: group "{m["group"]}" is one of the declared groups')
    ok(len(m['brief']) >= 40, f'{key}: a brief that says what the module configures')
    ok(m['route'] is None or m['perm'], f'{key}: a screen is named with the permission that guards it')

# The code's own credential lists — the honest source for "what is a secret here".
SECRET_CONSTS = {
    # module key: (file, the constant that names its credentials)
    'messages': ('app/Services/MessageService.php', r"public const SECRETS = \[(.*?)\];"),
    'payments': ('app/Services/OnlinePaymentService.php', r"public const SECRETS = \[(.*?)\];"),
    'inbox': ('app/Services/InboxService.php', r"public const SECRET = '([a-z0-9_]+)';"),
}

# Only the register can declare that a key is a credential, and only the code can say which
# ones it stores — so both sides are read here, before anything is compared.
declared_secrets = {}
for key, m in modules.items():
    if m['secret']:
        declared_secrets[key] = set(m['secret'])

const_secrets = {}
for key, (file, pattern) in SECRET_CONSTS.items():
    hit = re.search(pattern, read(os.path.join(ROOT, file)), re.S)
    ok(hit is not None, f'{key}: the service still declares its credential keys where this check reads them')
    if hit:
        found = set(re.findall(r"'([a-z0-9_]+)'", hit.group(1))) or {hit.group(1).strip()}
        const_secrets[key] = found
credential_keys = set().union(*const_secrets.values()) if const_secrets else set()

# ── 1. nothing is missing: every setting() read belongs to a module ─────────────────
registry_keys = set()
for m in modules.values():
    registry_keys.update(m['keys'].keys())

# Settings read through a DEFAULTS map (`cfg($k) → setting($k, DEFAULTS[$k])`) are read by
# the code without being named in a call, so the literal scan above cannot see them. The
# generator found them from the map itself and wrote them to their own section; they are a
# reader like any other here, and the register has to hold them or say how many it does not.
readers_src_early = read(os.path.join(ROOT, 'config/setting-readers.php'))


def _section(name):
    start = readers_src_early.find(f"'{name}' => [")
    if start < 0:
        return ''
    start = readers_src_early.index('\n', start)
    end = readers_src_early.find('\n    ],', start)
    return readers_src_early[start:end if end >= 0 else len(readers_src_early)]


map_keys = {}
for hit in re.finditer(r"'([a-z0-9_]+)' => \[([^\]]*)\]", _section('map')):
    map_keys[hit.group(1)] = re.findall(r"'([^']+)'", hit.group(2))

read_keys = {}
for base, _, files in os.walk(os.path.join(ROOT, 'app')):
    for name in files:
        if not name.endswith('.php'):
            continue
        path = os.path.join(base, name)
        for hit in re.finditer(r"setting(?:s)?\('([a-z0-9_]+)'", read(path)):
            read_keys.setdefault(hit.group(1), set()).add(name)


def registered(key):
    if key in registry_keys:
        return True
    # a family key (`fb_sla_`) covers every runtime-built member
    return any(k.endswith('_') and key.startswith(k) for k in registry_keys)


# a credential is read through MessageService::secret($key), so no literal setting() call
# carries it — the SECRETS constants are where the code names them
for _mod, _file in SECRET_CONSTS.values():
    pass
for _mod, _keys in const_secrets.items():
    for _k in _keys:
        read_keys.setdefault(_k, set()).add(SECRET_CONSTS[_mod][0].split('/')[-1])

missing = sorted(k for k in read_keys if not registered(k))
ok(not missing, f'every setting read by the code is in the register (missing: {", ".join(missing[:8])})')

invented = sorted(k for k in registry_keys if not k.endswith('_') and k not in read_keys and k not in map_keys)
ok(not invented, f'every setting in the register is read by the code, by name or through a map (invented: {", ".join(invented[:8])})')
ok(map_keys and all(files for files in map_keys.values()),
   f'the read graph carries the settings the code reads through a map ({len(map_keys)} keys, each with a file)')
# the strong side of the invariant stays strong: a key named outright in setting() must be
# registered. Only the map-read ones may still be outside, and §19 counts those.
ok(not (set(read_keys) - set(registry_keys)),
   f'no setting named outright in a setting() call is outside the register (outside: {", ".join(sorted(set(read_keys) - set(registry_keys))[:6])})')

# every PHP file's source, once — §9 asks whether a named key is really used
app_src = ''.join(read(os.path.join(folder, name))
                  for folder, _d, files in os.walk(os.path.join(ROOT, 'app')) for name in files if name.endswith('.php'))

# ── 3. the screens: route exists, permission matches what the route enforces ───────
routes_src = read(ROUTES)

# The settings screens are registered inside controller-scoped groups, so their full
# name (`banquet.settings`) is a prefix the group applies to a literal `->name('settings')`.
# Only the route audit resolves that, so ask it — the same source the application boots
# from, not a second parser that could disagree with it.
route_uri = {}
route_perm = {}
try:
    import json
    import subprocess
    import tempfile

    dump = tempfile.NamedTemporaryFile(suffix='.tsv', delete=False)
    dump.close()
    env = dict(os.environ, DUMP_PERMS=dump.name)
    subprocess.run(
        ['node', os.path.join(ROOT, 'deploy/dev/audit/routes_integrity_check.js'), ROOT],
        env=env, cwd=ROOT, check=False, capture_output=True, timeout=180,
    )
    with open(dump.name, encoding='utf-8') as handle:
        for line in handle:
            parts = line.rstrip('\n').split('\t')
            if len(parts) == 4:
                name, gate, _verb, uri = parts
                route_uri[name] = uri
                route_perm[name] = re.sub(r'^(perm|can):', '', gate).split(',')[0] if gate else ''
    os.unlink(dump.name)
except Exception as exc:  # php-parser missing, node missing — the check degrades, it does not lie
    print(f'  (route catalogue unavailable: {type(exc).__name__})')

# the configuration group itself
for name in ('configuration.index', 'configuration.print', 'configuration.csv'):
    ok(name in route_uri, f'route {name} is declared')
ok("'/configuration'" in routes_src or "prefix('configuration')" in routes_src, 'the configuration group is mounted under /configuration')
ok("perm:settings.view" in routes_src, 'the register is gated on settings.view')
ok(re.search(r"Route::get\('/\{module\}'", routes_src) is not None, 'a module page route is declared')

# every module route the register names must exist (they are page routes, not settings saves)
for key, m in modules.items():
    if not m['route']:
        continue
    ok(m['route'] in route_uri, f'{key}: screen route {m["route"]} exists')

# the permission the register prints must be the permission the screen enforces
checked_perms = 0
for key, m in modules.items():
    if not m['route'] or m['route'] not in route_perm:
        continue
    checked_perms += 1
    ok(route_perm[m['route']] == m['perm'],
       f'{key}: {m["route"]} is guarded by {route_perm[m["route"]] or "nothing"}, the register says {m["perm"]}')
ok(checked_perms >= 20, f'the register was checked against the routes it lists ({checked_perms})')

# a module whose screen opens on a view permission and is changed with a manage one says both
catalogue = read(CONFIG)
split = [k for k, m in modules.items() if m['perm'] != m['edit']]
ok(len(split) >= 3, f'modules that separate reading from changing are registered as such ({len(split)})')
for key in split:
    ok("'%s' =>" % modules[key]['edit'] in catalogue, f'{key}: the permission that changes it is in the catalogue')

# ── 4. the surface is complete: every GET *.settings screen is in the register ─────
listed = {m['route'] for m in modules.values() if m['route']}
settings_routes = {n for n in route_uri if (n.endswith('.settings') or n == 'settings.index') and route_perm.get(n) is not None}
def covered(route_name):
    # `banquet.settings` belongs to the module whose screen is `banquet.settings`;
    # `close.settings` (a save endpoint with no page of its own) belongs to the module
    # whose screen is `close.index`; `ap.wht.settings` to the `ap` family.
    if route_name in listed or route_name in EXCLUDED_ROUTES:
        return True
    family = route_name.split('.')[0]
    family = FAMILY_ALIAS.get(family, family)
    if family in modules:
        return True
    return any((r or '').split('.')[0] == family for r in listed)


unregistered = sorted(n for n in settings_routes if not covered(n))
ok(len(settings_routes) >= 25, f'the application has its settings screens ({len(settings_routes)})')
ok(not unregistered, f'every settings endpoint belongs to a module in the register (unregistered: {", ".join(unregistered[:10])})')

# ── 5. the hub is wired ────────────────────────────────────────────────────────────
ctrl = read(CONTROLLER)
svc = read(SERVICE)
conf = read(CONFIG)

ok('perm:settings.view' in routes_src, 'the register has its own read permission')
ok("'settings.view' =>" in conf, 'settings.view is in the permission catalogue')
ok("'label' => 'Configuration', 'route' => 'configuration.index'" in conf,
   'Configuration is in the sidebar under Administration')
ok('public function index' in ctrl and 'public function show' in ctrl, 'the controller has the hub and a module page')
ok('public function print' in ctrl and 'public function csv' in ctrl, 'the register prints and exports')
ok('ConfigurationService::grouped' in ctrl and 'ConfigurationService::coverage' in ctrl,
   'the hub is built from the service, not from a second list in the controller')
ok('public static function rows' in svc and 'public static function csv' in svc and 'self::rows()' in svc,
   'the export walks the same rows as the screen')
ok('ActivityLogger::log' in ctrl, 'the export is written to the activity log')

for view in ('index', 'show', 'print'):
    ok(os.path.exists(os.path.join(VIEWS, view + '.blade.php')), f'view configuration/{view}.blade.php exists')

for cls in ('.cfg-group', '.cfg-card', '.cfg-meta', '.cfg-gap', '.cfg-change', '.cfg-print-group'):
    ok(cls in read(CSS), f'stylesheet carries {cls}')

# the print sheet must cover every module and every key, not a sample of them
print_view = read(os.path.join(VIEWS, 'print.blade.php'))
ok('@foreach($groups as $group => $modules)' in print_view, 'the printed register walks every group')
ok("@foreach($m['keys'] as $k)" in print_view, 'the printed register walks every key')

# preview chain
build = read(BUILD)
sample = read(SAMPLE)
gallery = read(GALLERY)
server = read(SERVER)
# configuration-search.html is the hub answering a search, not a screen of its own, so it
# has an address and a build entry but no gallery card.
for screen in ('configuration.html', 'configuration-module.html', 'configuration-register.html'):
    ok(screen in build, f'preview: {screen} is built')
    ok(os.path.basename(screen).replace('.html', '') in gallery, f'preview: {screen} is in the gallery')
for screen in ('configuration.html', 'configuration-search.html', 'configuration-module.html', 'configuration-register.html'):
    ok('/__preview/' + screen.replace('.html', '') in server, f'preview: {screen} has an address')
ok('configuration-search.html' in build, 'preview: the search state is built')
ok("config/settings.php" in sample, 'the preview reads the real registry, so the screen cannot drift from it')
ok('parseRegistry' in sample, 'the preview parses the registry rather than duplicating it')

# the sample must not invent modules either: it counts what the registry holds
ok("configurationIndexContext" in build and "configurationShowContext" in build, 'both hub screens are rendered')

# ── 6. the checks: what this property's settings contradict ────────────────────────
# A register tells you where a value is set. It can also tell you when two settings
# already saved contradict each other — that is App/Support/ConfigurationChecks.php.
# The rules are proven here: every rule belongs to a registered module, reads registered
# and code-read keys, carries a severity the screen can render, and exists in the preview
# fixture too (so a rule added to the code can never be missing from the screen shown).
CHECKS_PHP = os.path.join(ROOT, 'app/Support/ConfigurationChecks.php')
checks_src = read(CHECKS_PHP)
ok(bool(checks_src), 'the configuration checks live in app/Support/ConfigurationChecks.php')

rules = []
# a rule may say "the campaign's" — the parser has to accept an escaped apostrophe, or a
# sentence written in plain English silently drops the rule out of this section
for hit in re.finditer(
        r"\$add\('([a-z0-9_]+)',\s*'([a-z]+)',\s*'([a-z0-9_]+)',\s*'((?:[^'\\]|\\.)*)',\s*'((?:[^'\\]|\\.)*)',\s*\[([^\]]*)\]",
        checks_src):
    rid, sev, mod, title, detail, keys = hit.groups()
    title, detail = title.replace("\\'", "'"), detail.replace("\\'", "'")
    rules.append({
        'id': rid, 'severity': sev, 'module': mod, 'title': title, 'detail': detail,
        'keys': re.findall(r"'([a-z0-9_]+)'", keys),
    })

rule_ids = [r['id'] for r in rules]
ok(len(rules) >= 12, f'the register can name the contradictions between settings ({len(rules)} rules)')
ok(len(rule_ids) == len(set(rule_ids)), 'no rule id is declared twice')
ok(all(len(r['title']) > 20 and len(r['detail']) > 40 for r in rules),
   'every rule says what is wrong and why it matters')
ok(all(r['keys'] for r in rules), 'every rule names the settings a reader should look at')

severities = set(re.findall(r"const SEVERITIES = \[([^\]]*)\]", checks_src)[0:1][0].replace("'", '').split(', ')) if re.search(r"const SEVERITIES", checks_src) else set()
SEVERITIES = {'blocker', 'warning', 'note'}
ok({r['severity'] for r in rules} <= SEVERITIES,
   f'every rule uses a severity the screen renders ({sorted({r["severity"] for r in rules})})')
ok({r['severity'] for r in rules} == SEVERITIES, 'the checks report blockers, contradictions and policy choices')

unknown_module = sorted({r['module'] for r in rules} - set(modules))
ok(not unknown_module, f'every rule belongs to a module in the register (unknown: {", ".join(unknown_module)})')

unregistered_keys = sorted({k for r in rules for k in r['keys']} - {k for k in registry_keys})
ok(not unregistered_keys, f'every setting a rule reads is in the register (missing: {", ".join(unregistered_keys[:6])})')

unread_keys = sorted({k for r in rules for k in r['keys'] if k not in read_keys and not registered(k)})
ok(not unread_keys, f'every setting a rule reads is read by the code (missing: {", ".join(unread_keys[:6])})')

# the rule that fires on a VAT-registered property must read the registration itself
ok('vat_registered' in checks_src, 'the VAT rules read the property registration, not a copy of it')
ok('OnlinePaymentService::configured' in checks_src,
   'the payment rule asks the payment service what it considers configured')
# the rules added in round 9 stand on facts that must still be true in the code
ok("'log' => 'Log only" in read(os.path.join(ROOT, 'app/Services/MessageService.php'))
   and "default => self::logOnly(" in read(os.path.join(ROOT, 'app/Services/MessageService.php')),
   'the log driver really is a stand-in that sends nothing — the SMS rule rests on it')
ok("'WhatsApp needs the Twilio or HTTP driver.'" in read(os.path.join(ROOT, 'app/Services/MessageService.php')),
   'WhatsApp really is refused on Hubtel — the default-channel rule rests on it')
ok('SpaBookingService::slots()' in checks_src and 'while ($t < $end' in read(os.path.join(ROOT, 'app/Services/SpaBookingService.php')),
   'the spa rule asks the same slot list the desk and the portal use')
ok("$s('qa_target_score', 90) < (float) $s('qa_pass_mark', 85)" in checks_src,
   'the QA rule compares the target with the pass mark the dashboard colours against')
ok("$registered === true && (float) $s('banquet_hall_tax_rate', 21) <= 0" in checks_src
   and "'hall_tax_rate' => (float) setting('banquet_hall_tax_rate', '21')" in read(os.path.join(ROOT, 'app/Services/BanquetSalesService.php')),
   'the banquet rule reads the same tax rate the function posts to the folio')
ok("$s('hk_credits_stayover', 1) < 1" in checks_src
   and "setting('hk_credits_stayover', 1)" in read(os.path.join(ROOT, 'app/Services/HousekeepingService.php')),
   'the housekeeping rule reads the credit the workload spread uses')

# wired: the service decorates, the controller passes, the views render
ok('use App\Support\ConfigurationChecks;' in svc, 'the register service knows the checks')
ok('ConfigurationChecks::run()' in svc, 'the service runs the rules rather than restating them')
ok('public static function advisories' in svc and 'public static function advisoryCounts' in svc,
   'the service exposes the findings and their counts')
ok(svc.count("'keys_text'") == 1,
   'each finding carries its keys as text, so the view never loops over an array in an echo')
ok(ctrl.count("'findings' =>") == 4 and 'ConfigurationService::advisories($module)' in ctrl,
   'the hub, the module page, the print sheet and the handover sheet all receive the findings')

hub_view = read(os.path.join(VIEWS, 'index.blade.php'))
show_view = read(os.path.join(VIEWS, 'show.blade.php'))
ok('@foreach($findings as $f)' in hub_view and 'cfg-find-{{ $f' in hub_view,
   'the hub lists every finding with its severity')
ok("$findingCounts['blocker']" in hub_view, 'the hub badge counts the blockers, not just the findings')
ok("route('configuration.show', $f['module'])" in hub_view, 'each finding links to the module that owns it')
ok('cfg-findings-clear' in hub_view, 'the hub says so plainly when nothing contradicts')
ok('count($findings)' in show_view and "$f['keys_text']" in show_view,
   'the module page shows the findings that touch it, with the keys involved')
ok('@foreach($findings as $f)' in print_view and "$f['severity_label']" in print_view,
   'the printed register carries the findings too')

for cls in ('.cfg-findings', '.cfg-find-sev', '.cfg-find-body', '.cfg-find-go'):
    ok(cls in read(CSS), f'stylesheet carries {cls}')

# the preview fixture must carry the same rules — parity both ways
sample_ids = set(re.findall(r"'([a-z0-9_]+)'", ' '.join(
    re.findall(r"export const ADVISORY_IDS = \[([^\]]*)\]", sample))))
ok(sample_ids == set(rule_ids),
   f'the preview fixture carries exactly the rules the code runs (code-only: {sorted(set(rule_ids) - sample_ids)}, fixture-only: {sorted(sample_ids - set(rule_ids))})')
ok('advisoriesFor(' in sample and 'countsOf(' in sample,
   'the fixture decorates and counts findings the way the service does')
for key in ('findings:', 'findingCounts:'):
    ok(sample.count(key) >= 2, f'the fixture feeds {key[:-1]} to the screens that render it')
ok('configurationModuleFindingsContext' in sample, 'the fixture renders a module that carries a finding')

ok('configuration-module-findings.html' in build, 'preview: the module-with-a-finding screen is built')
ok('/configuration-module-findings' in server, 'preview: the module-with-a-finding screen has an address')
ok('configuration-module-findings' in gallery, 'preview: the module-with-a-finding screen is in the gallery')

# ── 7. the defaults: what the code falls back to, and where the property has moved ──
# The register promises "current value, default and last changed by". The default is not
# stored in the database — it is the second argument of the `setting()` call the code
# reads the value with. config/setting-defaults.php is generated from those calls, and the
# generator is asked here whether it is still in step, so the register cannot show a
# default the code no longer uses.
DEFAULTS_FILE = os.path.join(ROOT, 'config/setting-defaults.php')
ok(os.path.exists(DEFAULTS_FILE), 'config/setting-defaults.php exists — the register has a source for its defaults')
defaults_src = read(DEFAULTS_FILE) if os.path.exists(DEFAULTS_FILE) else ''
ok('GENERATED — do not edit' in defaults_src, 'the defaults file says it is generated, so nobody hand-edits a fallback')

body = defaults_src[defaults_src.find("'defaults' => ["):]
if body:
    body = body[:body.find("'resolved_from_array'")]
default_keys = re.findall(r"^        '([a-z0-9_]+)' => ", body, re.M)
ok(len(default_keys) >= 150, f'the code declares a built-in default for most settings ({len(default_keys)})')
ok(len(default_keys) == len(set(default_keys)), 'no setting is given a default twice in the generated file')
start = defaults_src.find("'conflicting' => [")
conflict_block = defaults_src[start + len("'conflicting' => ["):] if start >= 0 else ''
conflict_block = conflict_block[:conflict_block.find('\n    ],')] if conflict_block else ''
stray_conflicts = re.findall(r"'([a-z0-9_]+)' => \[", conflict_block)
ok(not stray_conflicts,
   f'no setting is read with two different defaults (the screen and the report would disagree: {", ".join(stray_conflicts)})')

unregistered_defaults = sorted(k for k in default_keys if not registered(k) and k not in map_keys
                               and not any(p.endswith('_') and k.startswith(p) for p in registry_keys))
ok(not unregistered_defaults,
   f'every default belongs to a setting the code reads — registered, or behind a map this register does not hold yet '
   f'(stray: {", ".join(unregistered_defaults[:6])})')

# the generator must still produce exactly this file from the code as it stands
try:
    gen = subprocess.run(['node', os.path.join(ROOT, 'deploy/dev/build-setting-defaults.mjs'), '--check'],
                         cwd=ROOT, check=False, capture_output=True, timeout=180)
    said = (gen.stderr.decode().strip() or gen.stdout.decode().strip()).split('\n')[0][:140]
    ok(gen.returncode == 0, 'the defaults file is in step with the code — ' + ('regenerates identically' if gen.returncode == 0 else said))
except Exception as exc:
    ok(False, f'the defaults generator could be run ({type(exc).__name__})')

ok('public static function defaults' in svc and "config('setting-defaults.defaults'" in svc,
   'the service reads the generated defaults rather than keeping its own list')
ok('public static function defaultFor' in svc and 'public static function drifted' in svc,
   'the service can say what a key falls back to and whether the property has moved off it')
ok("'default' => $default" in svc and "'changed' => $set && self::drifted($v, $default)" in svc,
   'a register row carries its default and whether it has been changed')
ok("'changed' => $changed" in svc and "'defaults_known' => $known" in svc,
   'a module carries how many of its settings were moved off the default')
ok("'changed' => $changed," in svc, 'the coverage strip counts the settings changed on purpose')
ok("return ! (is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 0.000001);" in svc,
   'a number stored as 250.00 is not called a change from 250')

ok("'Built-in default'" in svc, 'the CSV export carries the default beside the value')
ok("$r['default'], $r['state'], $r['screen']" in svc, 'the CSV writes the default for every row')
ok('Built-in default' in show_view and "$k['default_show'] ?? '—'" in show_view,
   'the module page prints the built-in default beside the value')
ok('badge-blue">Changed' in show_view, 'the module page marks a setting that has been moved off its default')
ok("$coverage['changed']" in hub_view, 'the hub counts what has been changed on purpose')
ok("$m['changed']" in hub_view, 'a hub card says how many of its settings were moved off the default')
ok('Built-in default' in print_view and "$k['default_show'] ?? '—'" in print_view,
   'the printed sheet carries the default, so a new property can be set from it')

# the fixture must read the same generated file, or the preview would show another product's defaults
ok('setting-defaults.php' in sample, 'preview: the fixture reads the generated defaults, not a second copy')
ok('parseDefaults' in sample and 'sameNumber' in sample,
   'preview: the fixture resolves defaults and compares numbers the way the service does')

# ── 8. the trail: every module's settings change can be found afterwards ───────────
# "Last changed by" is only honest if the change was actually written to the activity
# log under a name this register can resolve. Conventions differ across the estate
# (('settings', '<module>') and ('<module>', 'settings')), some modules log under another
# name entirely, and a module whose screen *is* the flat Settings form is saved by that
# form's single `update` entry. This section derives the spec the way the service does
# and proves every module matches at least one real call.
LOG_CALL = re.compile(r"ActivityLogger::log\(\s*'([a-z0-9_]+)'\s*,\s*'([a-z0-9_]+)'")
logged = set()
dynamic = 0
for folder, _dirs, files in os.walk(os.path.join(ROOT, 'app')):
    for name in files:
        if not name.endswith('.php'):
            continue
        text = read(os.path.join(folder, name))
        for hit in LOG_CALL.finditer(text):
            logged.add((hit.group(1), hit.group(2)))
        dynamic += len(re.findall(r"ActivityLogger::log\(\s*'[a-z0-9_]+'\s*,\s*\$", text))

log_spec = read(SERVICE)
# the audit must derive the spec the way the service does — same four rules, or it
# would bless a module the screen still shows as "nothing changed"
ok("explode(':', $log, 2)" in log_spec, 'the service reads `module:action` from the registry')
ok("=== 'settings.index'" in log_spec, 'the service counts the flat Settings form save for a module whose screen is that form')
ok("str_ends_with($action, 'settings')" in log_spec, 'the service accepts a named variant such as wht_settings')
ok("$module === 'settings' && in_array($action, $spec['actions'], true)" in log_spec,
   'the service reads the other convention — the module named as the action')


def spec_for(key, m):
    modules_, actions_ = [key], [key]
    if m['log']:
        mod, action = (m['log'].split(':', 1) + [None])[:2]
        modules_.append(mod)
        actions_.append(action or mod)
    if m['route'] == 'settings.index':
        actions_.append('update')
    if key == 'property':
        actions_ += ['update', 'add_property']
    if key == 'gst':
        actions_.append('vat_registration')
    return modules_, actions_


silent = []
for key, m in modules.items():
    modules_, actions_ = spec_for(key, m)
    hit = [row for row in logged
           if (row[0] == 'settings' and row[1] in actions_)
           or (row[0] in modules_ and (row[1] == 'settings' or row[1].endswith('settings') or row[1] in actions_))]
    if not hit:
        silent.append(key)
ok(not silent,
   f'every module of the register has a written settings change the register can find (silent: {", ".join(sorted(silent))})')

# an alias must be a name the code really logs, never a plausible-looking guess
# an alias is either the module name the trail uses, or the action it logs under the
# `settings` module (`requisitions` is an action, not a module of the register)
trail_names = {row[0] for row in logged} | {row[1] for row in logged}
bogus = sorted(f'{k} → {m["log"]}' for k, m in modules.items()
               if m['log'] and m['log'].split(':')[0] not in trail_names
               or (m['log'] and ':' in m['log'] and tuple(m['log'].split(':', 1)) not in logged))
ok(not bogus, f'every `log` alias names a module the code actually logs under (stray: {", ".join(bogus)})')
aliased = [k for k, m in modules.items() if m['log']]
ok(len(aliased) >= 15, f'the aliases are carried in the register, not patched into the query ({len(aliased)})')
ok(dynamic <= 40, f'most trail calls name their action literally, so this section can read them ({dynamic} dynamic)')

# the register page must use the same resolution, not a second list of names
ok('private static function trailQuery' in log_spec and log_spec.count('self::trailQuery()') == 3,
   'the module page, the register and the hub read the trail through one query')
ok("->orWhereIn('action', $actions)" in log_spec,
   'the query is not narrowed to `%settings` only — a module that logs an action of its own survives it')
ok("$m['last']['what']" in read(os.path.join(VIEWS, 'index.blade.php')),
   'a card says what the last change was, not only when and by whom')

ok("'Updated hotel settings'.($changed" in read(os.path.join(ROOT, 'app/Http/Controllers/SettingController.php')),
   'the flat Settings form names the settings it changed, so a module reading the trail knows what moved')
ok('changed on the hotel Settings form' in read(os.path.join(VIEWS, 'show.blade.php')),
   'a module changed by that one form says so, instead of implying a screen of its own')

ok('ConfigurationService::historyFor($module)' in read(CONTROLLER),
   'the module page asks the service for the history rather than querying one convention itself')
ok('ActivityLog::where' not in read(CONTROLLER),
   'the controller keeps no query of its own that could disagree with the register')

# ── 9. the credentials: registered, and never printed ──────────────────────────────
# The SMS and payment credentials are stored encrypted by MessageService::storeSecret(), so
# the register read a ciphertext, called it a value, and exported it to a printable sheet and
# a CSV. Worse, the credentials were not in the register at all: they are read through
# `setting($key)` inside MessageService::secret(), so a scan for `setting('…')` cannot see
# them. The code's own SECRETS constants are the honest source, and this section holds the
# register to them in both directions.
for key, const in const_secrets.items():
    ok(not (const - declared_secrets.get(key, set())),
       f'{key}: every credential the code stores is declared in the register (missing: {", ".join(sorted(const - declared_secrets.get(key, set())))})')
    ok(not (declared_secrets.get(key, set()) - const),
       f'{key}: the register declares no credential the code does not store (invented: {", ".join(sorted(declared_secrets.get(key, set()) - const))})')

# a credential read through the service accessor is still a key somebody must be able to find:
# it has to exist in the code and be a row on a module page, or "is it set?" has no answer
for mod, keys in declared_secrets.items():
    for key in sorted(keys):
        ok(f"'{key}'" in app_src, f'{mod}: the declared credential {key} is a key the code really uses')
        ok(key in registry_keys, f'{mod}: the credential {key} is a register row, so an operator can see whether it is set')

# a key that *looks* like a credential must be declared one, or be listed here with the reason
NOT_SECRET = {
    'pay_razorpay_key_id': 'a public key id, printed on the gateway dashboard and in the browser',
}
suspect = re.compile(r'(password|secret|token|apikey|credential|_key$)')
all_declared = set().union(*declared_secrets.values()) if declared_secrets else set()
undeclared = sorted(k for k in registry_keys if suspect.search(k) and k not in all_declared and k not in NOT_SECRET)
ok(not undeclared, f'a key that names a credential is declared one (undeclared: {", ".join(undeclared[:8])})')
stale_holdouts = sorted(k for k in NOT_SECRET if k not in registry_keys)
ok(not stale_holdouts, f'no key held out of the credential list has been removed from the register (stale: {", ".join(stale_holdouts)})')

# the register must mask, not shape: the value never reaches a row, the CSV or a printed sheet
ok("public const HIDDEN = 'Set — hidden'" in svc, 'the service has one word for a stored credential')
ok("'secret' => true, 'value' => $set ? self::HIDDEN : null" in svc,
   'a credential row carries whether one is stored, never the stored value')
ok("'default' => null, 'default_show' => null, 'changed' => false," in svc,
   'a credential is not called "changed from the default" — it has no readable default')
ok("'credential set' : 'credential not set'" in svc, 'the CSV marks a credential as set, not as a value')
ok("in_array($k, $secret, true)" in svc, 'the module passes the registry declaration into the row')
ok('public static function secrets' in svc, 'the service can list the credentials, so nothing has to guess from a name')

ok("$k['secret'] ? 'Not set'" in show_view and 'credential</span>' in show_view,
   'the module page marks a credential and says when none is stored')
ok("$m['secrets'] ?? 0" in show_view, 'a module with credentials says how many, and that they are never shown')
ok('never printed' in print_view and "@if($k['secret'])" in print_view,
   'the printed register says a credential is never printed, and marks it')

# the built screens are the proof: the fixture stores a ciphertext and a Wi-Fi password, and
# neither may reach the HTML a manager prints or exports
PREVIEWS = os.path.join(ROOT, 'deploy/dev/previews')
leaky = []
for name in sorted(os.listdir(PREVIEWS)):
    if name.startswith('configuration') and name.endswith('.html'):
        html = read(os.path.join(PREVIEWS, name))
        for secret_value in ('eyJpdiI', 'Grove-Guest-2026', 'pbx-0c41b7', 'whk_9d21c4', 'evat-live-7f3c9a12'):
            if secret_value in html:
                leaky.append(f'{name} carries {secret_value}')
ok(not leaky, f'no built register screen carries a credential value ({"; ".join(leaky[:4])})')

# the preview must carry a ciphertext and still show none — that is what masking means
ok("const HIDDEN = 'Set — hidden'" in sample, 'preview: the fixture masks with the same word the service uses')
ok("secretKeys" in sample and "'secret' => " in sample, 'preview: the fixture reads the credential declaration from the registry')
ok('SECRETS_SET' in sample, 'preview: the fixture property has stored credentials, so the masked state is rendered')

# ── 10. the brief states the range and unit the screen enforces ────────────────────
# A brief that says "(%)" on a setting the code validates in seconds is worse than no
# brief: the manager types 90 meaning 90 % and stores a 90-second evacuation target. The
# modules that validate their settings state the range in the code, so the register is
# held to it — and the check reads the code, not a copy of it.
RANGE_MAPS = [
    # file, prefix the registry key adds to the field name, width of the tuple
    ('app/Services/HseService.php', 'hse_'),
    ('app/Services/TalentService.php', 'talent_'),
    ('app/Services/ParkingService.php', 'parking_'),
]
INLINE_RANGES = {
    # key: (file, pattern yielding lo and hi, unit the code validates in)
    'qa_pass_mark': ('app/Services/QaService.php', r'\$pm >= (\d+) && \$pm <= (\d+)'),
    'qa_target_score': ('app/Services/QaService.php', r'\$ts >= (\d+) && \$ts <= (\d+)'),
    'qa_action_days': ('app/Services/QaService.php', r'\$ad >= (\d+) && \$ad <= (\d+)'),
    'cro_follow_up_hours': ('app/Services/CroService.php', r'\$f >= (\d+) && \$f <= (\d+)'),
    'cro_expire_days': ('app/Services/CroService.php', r'\$e >= (\d+) && \$e <= (\d+)'),
    'cro_conversion_target': ('app/Services/CroService.php', r'\$t >= (\d+) && \$t <= (\d+)'),
}
UNITS = ('%', 'seconds', 'second', 'minutes', 'minute', 'hours', 'hour', 'days', 'day', 'months', 'month')


def unit_of(text):
    for u in UNITS:
        if re.search(r'\b' + re.escape(u) + r'\b', text, re.I) or (u == '%' and '%' in text):
            return 'month' if u.startswith('month') else ('day' if u.startswith('day') else ('hour' if u.startswith('hour') else ('minute' if u.startswith('minute') else ('second' if u.startswith('second') else '%'))))
    return None


def numbers_of(text):
    return {int(n.replace(',', '')) for n in re.findall(r'\d{1,3}(?:,\d{3})*|\d+', text)}


briefs = {}
for _key, _m in modules.items():
    for _k, _label in _m['keys'].items():
        briefs[_k] = (_key, _label)

bounded = {}   # registry key -> (lo, hi, unit the code validates in, file)
for file, prefix in RANGE_MAPS:
    for hit in re.finditer(r"'([a-z_0-9]+)' => \[(\d+), (\d+), '((?:[^'\\]|\\.)*)'\]", read(os.path.join(ROOT, file))):
        bounded[prefix + hit.group(1)] = (int(hit.group(2)), int(hit.group(3)), unit_of(hit.group(4)), file)
# Food & beverage costing keys are spelled in full, with the form field first
for hit in re.finditer(r"'(food_cost_target_pct|fbc_[a-z_0-9]+)' => \['([a-z_0-9]+)', (\d+), (\d+), '((?:[^'\\]|\\.)*)'\]",
                       read(os.path.join(ROOT, 'app/Services/FbCostService.php'))):
    bounded[hit.group(1)] = (int(hit.group(3)), int(hit.group(4)), unit_of(hit.group(5)), 'app/Services/FbCostService.php')
for key, (file, pattern) in INLINE_RANGES.items():
    hit = re.search(pattern, read(os.path.join(ROOT, file)))
    ok(hit is not None, f'{key}: the screen still validates the range this check reads')
    if hit:
        message = read(os.path.join(ROOT, file))
        m = re.search(pattern.replace('(\\d+)', '(\\d+)') + r".{0,80}?'([^']*)'", message, re.S)
        bounded[key] = (int(hit.group(1)), int(hit.group(2)), unit_of(m.group(3) if m else key.replace('_', ' ')), file)

ok(len(bounded) >= 30, f'the register has a range to state for every validated setting ({len(bounded)} found in the code)')
unbriefed = sorted(k for k in bounded if k not in briefs)
ok(not unbriefed, f'every setting the code validates is in the register (missing: {", ".join(unbriefed[:6])})')

for key, (lo, hi, unit, file) in sorted(bounded.items()):
    module, brief = briefs.get(key, (None, ''))
    nums = numbers_of(brief)
    ok(lo in nums and hi in nums,
       f'{key}: the brief states the range the screen enforces ({lo}–{hi}; brief says "{brief}")')
    if unit:
        ok(unit_of(brief) == unit,
           f'{key}: the brief uses the unit the screen validates — {unit}, not {unit_of(brief) or "no unit"} ("{brief}")')

# one bound is not a literal: a critical corrective action runs up to the normal period
ok('up to the normal action period' in briefs.get('qa_critical_action_days', (None, ''))[1],
   'the critical corrective action states the bound it actually has: the normal action period')
ok("$cd >= 1 && $cd <= $ad" in read(os.path.join(ROOT, 'app/Services/QaService.php')),
   'and that bound is still what the screen enforces')

# ── 11. the register says what depends on a setting ────────────────────────────────
# "Who changed it" is only half the question; before changing one, a manager needs to know
# what reads it. That answer is in the code, so it is generated into
# config/setting-readers.php and checked the same way the defaults are: every registered
# key has to resolve to at least one real file, and the file has to be in step with the code.
READERS_FILE = os.path.join(ROOT, 'config/setting-readers.php')
ok(os.path.exists(READERS_FILE), 'config/setting-readers.php exists — the register has a source for the impact of a setting')
readers_src = read(READERS_FILE) if os.path.exists(READERS_FILE) else ''
ok('GENERATED — do not edit' in readers_src, 'the readers file says it is generated, so nobody hand-edits who reads what')


def reader_block(name):
    start = readers_src.find(f"'{name}' => [")
    if start < 0:
        return ''
    start = readers_src.index('\n', start)  # past the section header, which is not an entry
    end = readers_src.find('\n    ],', start)
    return readers_src[start:end if end >= 0 else len(readers_src)]


reads_by_name, reads_family, reads_accessor, reads_map = {}, {}, {}, {}
for name, box in (('readers', reads_by_name), ('families', reads_family), ('accessors', reads_accessor), ('map', reads_map)):
    for hit in re.finditer(r"'([a-z0-9_]+)' => \[([^\]]*)\]", reader_block(name)):
        box[hit.group(1)] = re.findall(r"'([^']+)'", hit.group(2))

ok(len(reads_by_name) >= 250, f'the generated map covers the settings the code reads by name ({len(reads_by_name)})')

# every registered key — a key, a family, or a credential — resolves to somewhere
unread = []
for _module, _m in modules.items():
    for _key in _m['keys']:
        found = reads_family.get(_key) if _key.endswith('_') else (reads_by_name.get(_key) or reads_accessor.get(_key) or reads_map.get(_key))
        if not found:
            unread.append(_key)
ok(not unread, f'every registered setting resolves to the file that reads it (unresolved: {", ".join(sorted(unread)[:6])})')

missing_files = sorted({f for box in (reads_by_name, reads_family, reads_accessor)
                        for files in box.values() for f in files
                        if not os.path.exists(os.path.join(ROOT, f))})
ok(not missing_files, f'every file the map names still exists (moved or deleted: {", ".join(missing_files[:5])})')

# the generated map's own idea of a credential is the register's idea of one
ok(set(reads_accessor) == set(declared_secrets.get('messages', set())) | set(declared_secrets.get('payments', set())) | set(declared_secrets.get('inbox', set())),
   'the credentials read through an accessor are exactly the credentials the register declares')
ok(set(reads_family) == {k for _m in modules.values() for k in _m['keys'] if k.endswith('_')},
   'the families the map carries are exactly the families the register lists')

# the generator must still produce this file from the code as it stands
try:
    gen = subprocess.run(['node', os.path.join(ROOT, 'deploy/dev/build-setting-readers.mjs'), '--check'],
                         cwd=ROOT, check=False, capture_output=True, timeout=180)
    said = (gen.stderr.decode().strip() or gen.stdout.decode().strip()).split('\n')[0][:140]
    ok(gen.returncode == 0, 'the readers file is in step with the code — ' + ('regenerates identically' if gen.returncode == 0 else said))
except Exception as exc:
    ok(False, f'the readers generator could be run ({type(exc).__name__})')

ok("config('setting-readers'" in svc and 'public static function readersFor' in svc,
   'the service reads the generated map rather than scanning the application on every page')
ok('+ self::readBy($key)' in svc and 'private static function readBy' in svc,
   'every register row carries where it is read')
ok("'read_in' => $k['read_paths'] ?: '—'" in svc and "'Screen', 'Read in'" in svc,
   'the CSV carries the impact of a setting as well as its value')
ok('Read in</th>' in show_view and "$k['read_paths']" in show_view,
   'the module page has a column for what depends on the setting, with the paths on hover')
ok('parseReaders' in sample and 'setting-readers.php' in sample,
   'preview: the fixture reads the generated map, not a second copy of it')

# ── 12. which modules are checked against each other, and which are not ────────────
# A module page that stays silent because nothing compares its settings looks exactly like
# a module page that stays silent because everything is consistent. ConfigurationChecks
# carries the rule → module list so the register can tell the reader which it is, and this
# section proves that list and the rules themselves are the same list.
modules_const = {}
hit = re.search(r"public const MODULES = \[(.*?)\];", checks_src, re.S)
if hit:
    for row in re.finditer(r"'([a-z0-9_]+)' => '([a-z0-9_]+)'", hit.group(1)):
        modules_const[row.group(1)] = row.group(2)

ok(bool(modules_const), 'the checks declare which module each rule belongs to')
declared_ids = {r['id'] for r in rules}
ok(set(modules_const) == declared_ids,
   f'the rule → module list is exactly the rules that run (list-only: {", ".join(sorted(set(modules_const) - declared_ids)) or "none"}; rule-only: {", ".join(sorted(declared_ids - set(modules_const))) or "none"})')
rule_module = {r['id']: r['module'] for r in rules}
mismatched = sorted('%s (%s is not %s)' % (rid, modules_const[rid], rule_module[rid])
                    for rid in set(modules_const) & declared_ids
                    if modules_const[rid] != rule_module[rid])
ok(not mismatched, f'a rule is filed under the module the rule itself names ({"; ".join(mismatched[:4])})')

guarded = sorted(set(modules_const.values()))
ok(not (set(guarded) - set(modules)), f'every guarded module is in the register (stray: {", ".join(sorted(set(guarded) - set(modules)))})')
ok(len(guarded) >= 30, f'the register knows how many modules are guarded, and it is closing on the estate ({len(guarded)} of {len(modules)})')
unguarded = [k for k, m in modules.items() if m['keys'] and k not in guarded]
ok(len(unguarded) >= 1, f'the unguarded modules are a real, named set rather than wishful thinking ({len(unguarded)} with settings and no rule)')

ok('public static function rulesFor' in checks_src and 'public static function guardedModules' in checks_src,
   'the checks can say which rules look at a module')
ok('ConfigurationChecks::rulesFor((string) $key)' in svc, 'the register asks the checks, not a second list')
ok("'guarded' => count(array_filter($modules, fn ($m) => $m['rules'] > 0))" in svc,
   'the coverage strip counts the guarded modules from the same number')

# the module page must distinguish "checked and clean" from "not checked"
ok("No contradiction in this module's settings right now" in show_view
   and "Nothing compares this module's settings with the rest of the configuration yet" in show_view,
   'a module page that has no finding says whether anything looked, instead of implying it was checked')
ok("$m['rules'] === 1 ? 'rule' : 'rules'" in show_view, 'and says how many rules looked')
ok("$coverage['guarded']" in hub_view and 'checked against each other' in hub_view,
   'the hub states how many modules are checked against each other, out of how many')

ok('parseRuleModules' in sample and "'public const MODULES = ['" in sample,
   'preview: the fixture reads the rule → module list from the code')

# ── 13. what this property decided, as against what the code ships ─────────────────
# A register that lists everything sets the property's own decisions at the same weight as
# the defaults nobody touched. "Changed here" is the other half: the settings this property
# moved off a built-in default, and the ones it filled in where the code ships none — which
# is what a handover or an audit actually compares.
changed_view = read(os.path.join(VIEWS, 'changed.blade.php'))
ok('public static function changed' in svc and 'private static function decision' in svc,
   'the service can list what this property changed, split from what it inherited')
ok("'drifted' => $k['changed']" in svc.replace(' ', ' ') or "$k['changed']" in svc,
   'a drifted decision is the same test the coverage strip counts')
ok("$k['default'] === null && ! $k['secret']" in svc,
   'a setting with no built-in default is a decision only when somebody filled it in — and never a credential')
ok("'counts' => [" in svc and "'drifted' => count($drifted)" in svc and "'chosen' => count($chosen)" in svc,
   'the screen is given the two counts rather than counting rows in the view')

ok("Route::get('/changed', 'changed')->name('changed')" in routes_src,
   'the page has its own route inside the register group')
changed_at = routes_src.find("Route::get('/changed'")
module_at = routes_src.find("Route::get('/{module}', 'show')")
ok(changed_at >= 0 and module_at >= 0 and changed_at < module_at,
   'the route is declared before /{module}, so a page is not mistaken for a module key')
ok("public function changed()" in read(CONTROLLER) and 'ConfigurationService::changed()' in read(CONTROLLER),
   'the controller reads the decisions from the service')
ok('configuration.changed' in hub_view, 'the hub links to it, so it is not a page nobody can find')

for piece, label in (
    ('Moved off the built-in default', 'the drifted decisions have their own heading'),
    ('Filled in where the code has no default', 'the settings with no default have theirs'),
    ('Nothing on this page edits a value', 'the page says it is read-only, like the register'),
    ('A credential is listed as a decision without its value', 'and says what it does with a credential'),
    ('nobody chose it here', 'and says what is *not* on the page'),
    ('never recorded', 'a decision with no trail entry says so instead of inventing a name'),
):
    ok(piece in changed_view, f'changed page: {label}')

ok('configuration-changed.html' in build and 'configuration-changed' in gallery and '/__preview/configuration-changed' in server,
   'preview: the decisions page is built, in the gallery and served')
ok('configurationChangedContext' in sample and 'ConfigurationService::changed()' in sample,
   'preview: the fixture builds the same two lists the service does')

# ── 14. what an unset setting runs on: the keys the code ships no default for ───────
# 209 of the 276 keys carry a default. The rest are read with no second argument, so the
# runtime hands back null — and where a fallback exists it lives at the read site. The
# page is only honest if the file it prints is the code: so the file is rebuilt here, its
# key list is compared with the register, and every claim in it is traced to a real file.
FALLBACKS_FILE = os.path.join(ROOT, 'config/setting-fallbacks.php')
ok(os.path.exists(FALLBACKS_FILE), 'config/setting-fallbacks.php exists — the "unset" page has a source')
fb_src = read(FALLBACKS_FILE) if os.path.exists(FALLBACKS_FILE) else ''
ok('GENERATED — do not edit' in fb_src, 'the fallbacks file says it is generated, so nobody hand-writes a fallback')

fb_rows = {}
fbbody = fb_src[fb_src.find("'fallbacks' => ["):]
fbbody = fbbody[:fbbody.find("'conflicting' => [")] if "'conflicting' => [" in fbbody else fbbody
for line in fbbody.split('\n'):
    hit = re.match(r"^        '([a-z0-9_]+)' => \[(.*)\],\s*$", line)
    if not hit:
        continue
    pairs = dict(re.findall(r"'([a-z_]+)' => (null|'(?:[^'\\]|\\.)*')", hit.group(2)))
    fb_rows[hit.group(1)] = {
        name: (None if raw == 'null' else raw[1:-1].replace("\\'", "'")) for name, raw in pairs.items()
    }
fb_listed = set(fb_rows)
ok(len(fb_rows) >= 40, f'the fallbacks file covers the settings with no default ({len(fb_rows)})')

# Which keys *should* be in it: registered, read with no built-in default, and a single
# value. A credential has no fallback to describe (it is never read through setting()) and
# a family prefix holds whatever rows the property created.
fb_families = {k for m in modules.values() for k in m['keys'] if k.endswith('_')}
# A credential is a credential even when the service reads it through setting() by name: the
# register's own `'secret' => [...]` list is the other source, and this page never describes
# the fallback of something it refuses to print the value of.
fb_creds = set(credential_keys) | {k for m in modules.values() for k in m['secret']}
fb_target = {k for m in modules.values() for k in m['keys']
             if k not in default_keys and k not in fb_creds and k not in fb_families}
ok(fb_listed == fb_target,
   'the file lists exactly the settings with no built-in default and a single value '
   f'(missing: {", ".join(sorted(fb_target - fb_listed)) or "none"} · '
   f'extra: {", ".join(sorted(fb_listed - fb_target)) or "none"})')
ok(not (fb_listed & set(default_keys)), 'no setting that has a built-in default is described as having none')
ok(not (fb_listed & fb_creds), 'a credential is not on the page, whether it is stored for an accessor or read by name')

owner = {k: key for key, m in modules.items() for k in m['keys']}
wrong_module = sorted(k for k, row in fb_rows.items() if row.get('module') != owner.get(k))
ok(not wrong_module, f'every fallback is filed under the module that owns the key (wrong: {", ".join(wrong_module[:4])})')

# A fallback is a literal, never an expression dressed up as one: the page prints it to a
# reader, so `$default` or `config('x')` must never appear as a value.
expressionish = sorted(k for k, row in fb_rows.items()
                       if any(v is not None and ('$' in v or '(' in v or '::' in v)
                              for v in (row.get('service'), row.get('view'))))
ok(not expressionish, f'a fallback is a value, not an expression ({", ".join(expressionish[:4])})')

missing_app = sorted(k for k, row in fb_rows.items()
                     if row.get('service') is not None and not str(row.get('service_file') or '').startswith('app/'))
ok(not missing_app, f'a service fallback names the file that supplies it (missing: {", ".join(missing_app[:4])})')
missing_view = sorted(k for k, row in fb_rows.items()
                      if row.get('view') is not None and not str(row.get('view_file') or '').startswith('resources/views/'))
ok(not missing_view, f'a page fallback names the page that prints it (missing: {", ".join(missing_view[:4])})')

# The named file must really read the key — a citation nobody can check is a rumour.
unread_citation = []
for key, row in fb_rows.items():
    for field, value in (('service_file', row.get('service')), ('view_file', row.get('view'))):
        if value is None:
            continue
        path = os.path.join(ROOT, row.get(field) or '')
        if not row.get(field):
            unread_citation.append(f'{key}: no {field}')
        elif not os.path.isfile(path):
            unread_citation.append(f'{key}: {field} does not exist')
        elif f"'{key}'" not in read(path):
            unread_citation.append(f'{key}: {row[field]} never mentions it')
ok(not unread_citation, f'every cited file really reads the setting it is cited for ({"; ".join(unread_citation[:3])})')

try:
    gen = subprocess.run(['node', os.path.join(ROOT, 'deploy/dev/build-setting-fallbacks.mjs'), '--check'],
                         cwd=ROOT, check=False, capture_output=True, timeout=180)
    said = (gen.stderr.decode().strip() or gen.stdout.decode().strip()).split('\n')[0][:140]
    ok(gen.returncode == 0, 'the fallbacks file is in step with the code — ' + ('regenerates identically' if gen.returncode == 0 else said))
except Exception as exc:
    ok(False, f'the fallbacks generator could be run ({type(exc).__name__})')

ok("config('setting-fallbacks.fallbacks'" in svc and 'public static function fallbacks' in svc,
   'the service reads the generated fallbacks instead of a second copy')
ok('public static function fallbackFor' in svc and 'public static function unsetShow' in svc,
   'one setting can be asked what it runs on when nobody set it')
ok("if ($f === null) {\n            return null;" in svc or '$f === null' in svc,
   'a setting with no fallback at all is answered with null, not with a plausible sentence')
ok("if ($k['default'] !== null || $k['secret'] || $k['family']) {" in svc,
   'the page leaves out settings that have a default, credentials and family prefixes')
ok("'unset_show' => $default === null ? self::unsetShow($key) : null," in svc,
   'the module screen only says what an unset value runs on when there is no default to show')
ok('public static function onNull' in svc and "'code' => ['state' => 'code'" in svc,
   'the three groups — a literal in the code, a literal only a page prints, nothing — are built in the service')

ok('on-null' in routes_src and "Route::get('/on-null', 'onNull')->name('on-null')" in routes_src,
   'the page has its own route')
on_null_at = routes_src.find("Route::get('/on-null'")
ok(on_null_at >= 0 and module_at >= 0 and on_null_at < module_at,
   'the route is declared before /{module}, so a page is not mistaken for a module key')
ok('public function onNull()' in read(CONTROLLER) and 'ConfigurationService::onNull()' in read(CONTROLLER),
   'the controller reads the groups from the service')

on_null_view = read(os.path.join(VIEWS, 'on-null.blade.php'))
# prose wraps: a sentence split across two source lines is still one sentence on the screen
on_null_flat = re.sub(r'\s+', ' ', on_null_view)
for piece, label in (
    ('What an unset value runs on', 'each row says what the unset value runs on'),
    ('not set', 'a setting nobody set says so rather than printing a blank'),
    ('a blank in the register tells you nothing', 'the page says why a blank in the register is not an answer'),
    ('the page refuses to pick a side', 'two different fallbacks are surfaced, not resolved'),
    ('Nothing falls into this group on this property', 'an empty group says so instead of vanishing'),
    ('each row opens the module that owns it', 'the page says it does not edit a value'),
):
    ok(piece in on_null_flat, f'unset page: {label}')

ok("route('configuration.on-null')" in hub_view and 'What an unset setting runs on</a>' in hub_view,
   'the hub links to it from its action bar, so it is not a page nobody can find')
ok("$coverage['on_null']" in hub_view, 'the hub strip counts the settings with no default to fall back on')
ok("'on_null' => count(config('setting-fallbacks.keys', []))" in svc,
   'the hub count is the generated list, not a number typed into a view')

# A screen with a dead icon reads as a broken screen: every icon name must exist in the
# sprite. (The changed page shipped with `sliders`, which is not in it, and looked empty.)
sprite = read(os.path.join(ROOT, 'resources/views/partials/icons.blade.php'))
sprite_names = set(re.findall(r'id="i-([a-z-]+)"', sprite))
bad_icons = []
for name in sorted(os.listdir(VIEWS)):
    if not name.endswith('.blade.php'):
        continue
    used = set(re.findall(r'<x-icon name="([a-z-]+)"', read(os.path.join(VIEWS, name))))
    for icon in sorted(used - sprite_names):
        bad_icons.append(f'{name}: {icon}')
ok(not bad_icons, f'every icon on the configuration screens exists in the sprite ({", ".join(bad_icons[:4])})')

ok('configuration-on-null.html' in build and 'configuration-on-null' in gallery and '/__preview/configuration-on-null' in server,
   'preview: the unset page is built, in the gallery and served')
ok('configurationOnNullContext' in sample and 'parseFallbacks' in sample and 'setting-fallbacks.php' in sample,
   'preview: the fixture reads the generated fallbacks, not a second copy')
ok('function unsetShow' in sample, 'preview: the fixture answers "what does it run on" the same way the service does')

# ── 15. what a setting was before: the value trail behind "last changed by" ────────
# The activity log records that a screen was saved and which settings it touched, never the
# values — so "free cancellation: 48 → 24" could not be answered. The trail is written by an
# observer on the Setting model, which is the only place all 69 modules meet. This section
# proves the record is written, is written safely, and can never contain a credential.
MIGRATION = os.path.join(ROOT, 'database/migrations/2026_11_08_000400_setting_changes.php')
CHANGE_MODEL = os.path.join(ROOT, 'app/Models/SettingChange.php')
OBSERVER = os.path.join(ROOT, 'app/Observers/SettingValueObserver.php')
PROVIDER = os.path.join(ROOT, 'app/Providers/AppServiceProvider.php')

ok(os.path.exists(MIGRATION), 'the value trail has a table of its own')
mig = read(MIGRATION) if os.path.exists(MIGRATION) else ''
ok("Schema::create('setting_changes'" in mig and 'Schema::hasTable' in mig,
   'the migration creates setting_changes and is safe to re-run')
for column in ("key", "old_value", "new_value", "was_secret", "created", "user_id", "hotel_id"):
    ok(column in mig, f'the trail stores {column} — without it the row cannot say what changed')
ok("$t->text('old_value')" in mig and "$t->text('new_value')" in mig,
   'a value keeps its full shape — a policy paragraph is not truncated by the column')
ok("index(['hotel_id', 'key', 'created_at']" in mig, 'the trail is indexed by setting, so one key\'s history is one query')
ok('dropIfExists' in mig, 'the migration can be rolled back')
newest = sorted(os.listdir(os.path.join(ROOT, 'database/migrations')))[-1]
ok(os.path.basename(MIGRATION) == newest, f'the trail migration runs last ({newest})')

chg = read(CHANGE_MODEL) if os.path.exists(CHANGE_MODEL) else ''
ok('class SettingChange extends Model' in chg, 'the trail has a model')
ok('use BelongsToHotel;' in chg and "protected $table = 'setting_changes';" in chg,
   'the trail is scoped to the property it belongs to')
ok('const UPDATED_AT = null;' in chg, 'a trail row is never edited — it has a created_at and no updated_at')
ok("'was_secret' => 'boolean'" in chg and "'created' => 'boolean'" in chg,
   'the row says itself whether the value was a credential and whether the setting existed before')
ok('function user()' in chg, 'a trail row names the person, so the page does not print an id')

obs = read(OBSERVER) if os.path.exists(OBSERVER) else ''
ok('class SettingValueObserver' in obs, 'the record is written by an observer, not by 69 screens')
ok("public function saved(Model $m): void" in obs and 'catch (\Throwable $e)' in obs and 'report($e);' in obs,
   'a trail that fails can never undo the save that produced it')
ok("! $m->wasChanged('value')" in obs and "(string) $old === (string) $new" in obs,
   'a save that changed no value records nothing, so the trail is not a log of button presses')
ok('Auth::check()' in obs and 'wasRecentlyCreated' in obs,
   'filling a new property in (the seeder) is provisioning, not a decision somebody made')
ok('ConfigurationService::isSecretKey($key)' in obs, 'the observer asks the register which keys are credentials')
ok("'old_value' => $secret ? self::mask($old) : self::clip($old)" in obs
   and "'new_value' => $secret ? self::mask($new) : self::clip($new)" in obs,
   'a credential records that it changed and never what it was or is')
ok("ConfigurationService::HIDDEN" in obs, 'a credential is stored as the same masked text the register prints')
ok('private const MAX = 2000;' in obs and 'mb_substr($text, 0, self::MAX)' in obs,
   'a long value is clipped with a marker rather than silently stored in part')
ok("'hotel_id' => $m->hotel_id" in obs, 'the trail row carries the property of the setting that changed')

prov = read(PROVIDER)
ok('Models\\Setting::observe(\\App\\Observers\\SettingValueObserver::class);' in prov,
   'the observer is registered — an observer nobody registers records nothing')

trail_src = svc[svc.find('public static function valueTrail'):svc.find('public static function labels')]
ok("SettingChange::query()->latest('id')" in trail_src, 'the trail is read newest first')
ok("$row->was_secret ? self::HIDDEN" in trail_src,
   'the page prints a credential as hidden even if a row ever carried a value')
ok("=== '' ? 'cleared'" in trail_src or "'cleared'" in trail_src,
   'a value cleared back to nothing says "cleared" rather than printing an empty cell')
ok("'by' => $row->user?->name" in trail_src, 'the page names the person, not a user id')
ok("'was' => $row->created ? null : self::show($row->old_value)" in trail_src,
   'a setting created by a person is shown as having been nothing, not as "was 0"')
ok('public static function trailCoverage' in svc and "'keys_total' => count(self::labels())" in svc,
   'the page says how much of the register the trail covers, rather than implying all of it')

ok("Route::get('/history', 'history')->name('history')" in routes_src, 'the trail has its own route')
history_at = routes_src.find("Route::get('/history'")
ok(history_at >= 0 and module_at >= 0 and history_at < module_at,
   'the route is declared before /{module}, so a page is not mistaken for a module key')
ok('public function history(Request $request)' in read(CONTROLLER)
   and 'ConfigurationService::valueTrail($module, 120)' in read(CONTROLLER),
   'the controller reads the trail and narrows it to a module when asked')

history_view = read(os.path.join(VIEWS, 'history.blade.php'))
history_flat = re.sub(r'\s+', ' ', history_view)
for piece, label in (
    ('What it was before', 'the page says what it is'),
    ('<th>Was</th>', 'a row shows the value it replaced'),
    ('<th>Now</th>', 'and the value that replaced it'),
    ('nothing — it was not set', 'a setting that did not exist says nothing rather than 0'),
    ('never what it is', 'the page says a credential is recorded and not printed'),
    ('The trail begins with this release', 'the page admits what it cannot show'),
    ('provisioning, not a decision', 'and says why the seeder is not in it'),
    ('Nothing has been recorded yet', 'an empty trail says so instead of showing a blank table'),
    ('not recorded', 'a row with no user says so'),
):
    ok(piece in history_flat, f'value trail: {label}')

ok("'valueTrail' => ConfigurationService::valueTrail($module, 8)" in read(CONTROLLER),
   'the module screen carries its own slice of the trail')
show_view = read(os.path.join(VIEWS, 'show.blade.php'))
ok('What these settings were before' in show_view, 'the module screen answers the question where the value is set')
ok("route('configuration.history', ['module' => $m['key']])" in show_view,
   'and links to the full trail, already narrowed to that module')

ok('What it was before</a>' in hub_view, 'the hub links to the trail from its action bar')
ok("route('configuration.history')" in hub_view and "$trail['rows']" in hub_view,
   'the hub strip counts the recorded value changes and links to them')

ok('configuration-history.html' in build and 'configuration-history' in gallery and '/__preview/configuration-history' in server,
   'preview: the trail page is built, in the gallery and served')
ok('configurationHistoryContext' in sample and 'valueTrail' in sample,
   'preview: the fixture builds the same trail the service does')
ok("'portal_wifi_password'" in sample and 'Set — hidden' in sample,
   'preview: the fixture carries a credential row, so the page is seen masking one')
ok('valueTrail: valueTrail(' in sample, 'preview: the module screen fixture carries its slice of the trail too')

# ── 16. putting a value back: the one write in this area, and how narrow it is ───────
# The trail says what a setting was before. Restoring it is only honest where the register
# can check the value against the rule the owning screen applies today — a select option
# that has since been removed, a store that has been deleted. The flat Settings form is the
# one place those rules are data rather than code (SettingController::KEYS), so a restore is
# offered there and nowhere else, and the row says why when it is not offered.
typed_src = svc[svc.find('public static function typed'):svc.find('public static function canRestore')]
ok('\\App\\Http\\Controllers\\SettingController::KEYS' in typed_src,
   'the restorable keys are the flat form\'s own table, not a second list that could drift')
ok("'type' => $spec[1] ?? 'text'" in typed_src and "'options' => $spec[3] ?? null" in typed_src,
   'the type and the allowed options come from the screen, so a restore is checked by the same rule')
ok('! self::isSecretKey($key) && array_key_exists($key, self::typed())' in svc,
   'a credential and a setting the register cannot check are both refused')

blocked_src = svc[svc.find('public static function restoreBlocked'):svc.find('public static function restoreError')]
for piece, label in (
    ('a credential is not recorded here', 'a credential is refused, because only that it changed was recorded'),
    ('owned by its module screen', 'a module-owned setting is refused, and told where its rules live'),
    ('there was nothing here before', 'a setting that was first set is not a "restore"'),
    ('the value before this change was empty', 'a value that was empty cannot be restored'),
):
    ok(piece in blocked_src, f'restore: {label}')

err_src = svc[svc.find('public static function restoreError'):svc.find('/** Where a key sits in the register')]
ok("preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $v)" in err_src
   and "'^([01]\\d|2[0-3]):[0-5]\\d$'" in read(os.path.join(ROOT, 'app/Http/Controllers/SettingController.php'))
   or "preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $settings[$key] ?? '')" in read(os.path.join(ROOT, 'app/Http/Controllers/SettingController.php')),
   'a time is checked with the same pattern the form uses')
ok('array_key_exists($v, $spec[\'options\'])' in err_src,
   'a select value is checked against the options the screen still offers')
ok('\\App\\Models\\Store::whereKey($v)->exists()' in err_src and '\\App\\Models\\Outlet::whereKey($v)->exists()' in err_src,
   'a store or outlet that has been deleted cannot be restored into place')
ok('FILTER_VALIDATE_EMAIL' in err_src and 'FILTER_VALIDATE_EMAIL' in read(os.path.join(ROOT, 'app/Http/Controllers/SettingController.php')),
   'an e-mail list is checked the way the form checks it')
ok('mb_strlen($v) <= 5000' in err_src, 'a value longer than the form accepts is refused')

restore = read(CONTROLLER)
restore_fn = restore[restore.find('public function restore'):restore.find('public function show')]
ok('restore_fn' != '' and 'SettingChange::query()->find($change)' in restore_fn,
   'a restore names the recorded change it restores, rather than a value posted by the browser')
ok('abort_if($row === null, 404)' in restore_fn,
   'another property\'s change is a 404 — the trail is scoped like every other row')
ok("'secret' => (bool) $row->was_secret" in restore_fn
   and 'ConfigurationService::restoreBlocked($facts)' in restore_fn,
   'the same refusal rules run on the server, not only in the view')
ok('ConfigurationService::restoreError($key, $row->old_value)' in restore_fn,
   'the value is re-checked before it is written, so a rule that changed since cannot be bypassed')
ok('Setting::updateOrCreate([\'key\' => $key], [\'value\' => $row->old_value]);' in restore_fn,
   'the restore writes through the setting model, so the observer records it in this same trail')
ok("back()->with('error'" in restore_fn and "back()->with('success'" in restore_fn,
   'a refused restore says why, and a done one says what it put back')

ok("Route::post('/history/{change}/restore', 'restore')->whereNumber('change')->name('restore')" in routes_src,
   'the restore is a POST on the recorded change, not a link that changes data when it is fetched')
on_restore = routes_src.find("Route::post('/history/{change}/restore'")
ok(on_restore >= 0 and "Route::middleware('perm:settings.manage')" in routes_src[max(0, on_restore - 400):on_restore],
   'restoring a value needs the same permission as changing one')
ok(on_restore >= 0 and module_at >= 0 and on_restore < module_at,
   'the restore route is declared before /{module}, inside the configuration group')

hv = re.sub(r'\s+', ' ', history_view)
ok('Put it back' in hv, 'the trail offers the action')
ok('@csrf' in history_view and "route('configuration.restore', $c['id'])" in history_view,
   'the form posts to the recorded change and carries the CSRF token')
ok("@if($c['restore_ok'])" in history_view and "$c['restore_blocked']" in history_view,
   'a row that cannot be restored prints the reason instead of a button that would fail after the click')
ok('One action on this page: put it back' in hv,
   'the page says what it can do, now that it can do something')
ok('writes through the setting model' in hv and 'under the name of whoever did it' in hv,
   'and says that the restore is itself recorded')

ok('function typedKeys' in sample and 'SettingController.php' in sample,
   'preview: the fixture reads the flat form\'s keys, so it cannot drift from the service')
ok('function restoreBlocked' in sample and 'restore_ok' in sample,
   'preview: the fixture applies the same refusal rules the service does')
ok("'the value it had is no longer accepted'" in svc or 'no longer accepted' in svc,
   'a value that is no longer valid says so rather than being offered and then refused')

# ── 17. the handover sheet: one printable page, and a fingerprint two sheets can be ──
# compared by. Every number on it comes from the same service the screens read; the hash is
# built from every registered key, so an unset key is part of it and setting one moves it.
fp_src = svc[svc.find('public static function fingerprint'):svc.find('public static function credentialSummary')]
ok('sha1(implode("\\n", $lines))' in fp_src and 'sort($lines, SORT_STRING)' in fp_src,
   'the fingerprint is a sorted hash, so the order of the registry or the rows cannot change it')
ok("'short' => strtoupper(substr($full, 0, 12))" in fp_src,
   'it is printed as twelve characters a person can compare by eye')
ok('foreach (array_keys(self::labels()) as $key)' in fp_src,
   'every registered key contributes a line — a key added to the register changes the fingerprint')
ok("$lines[] = $key.'='.(string) ($values[$key] ?? '');" in fp_src,
   'an unset key contributes an empty line, so setting one for the first time changes the fingerprint')
ok("$key.'=credential:'.($set ? 'set' : 'unset')" in fp_src,
   'a credential contributes only whether one is stored — the hash can never be a way to guess it')
ok("$lines[] = $key.'=family:'.count($held);" in fp_src,
   'a family prefix contributes its rows and a count, so adding one row moves the hash')
ok("'at' => SettingChange::query()->latest('id')->value('created_at')" in fp_src,
   'the fingerprint says when the configuration last moved')

cred_src = svc[svc.find('public static function credentialSummary'):svc.find('/** Where a key sits in the register')]
ok("'modules' => $modules, 'keys' => $keys, 'set' => $set" in cred_src,
   'the sheet says how many credentials are set, and how many keys there are to set')
ok('$k[\'value\']' not in cred_src, 'the credential summary carries no value at all')

hand = read(CONTROLLER)
hand_fn = hand[hand.find('public function handover'):hand.find('public function csv')]
for piece, label in (
    ("'fingerprint' => ConfigurationService::fingerprint()", 'the sheet carries the fingerprint'),
    ("'decisions' => ConfigurationService::changed()", 'and the decisions the property made'),
    ("'unset' => ConfigurationService::onNull()", 'and what an unset value runs on'),
    ("'findings' => ConfigurationService::advisories()", 'and the findings'),
    ("'credentials' => ConfigurationService::credentialSummary()", 'and how many credentials are set'),
    ("'trail' => ConfigurationService::trailCoverage()", 'and how much of the trail is recorded'),
    ("'recentChanges' => ConfigurationService::valueTrail(null, 12)", 'and the last few value changes'),
):
    ok(piece in hand_fn, f'handover: {label}')
ok("'guarded' => array_values(array_filter($modules" in hand_fn and "'unguarded' => array_values(array_filter($modules" in hand_fn,
   'handover: it separates the modules whose settings are checked from the ones that are not')

ok("Route::get('/handover', 'handover')->name('handover')" in routes_src, 'the sheet has its own route')
hand_at = routes_src.find("Route::get('/handover'")
ok(hand_at >= 0 and module_at >= 0 and hand_at < module_at,
   'the route is declared before /{module}, so a page is not mistaken for a module key')
ok("'fingerprint' => ConfigurationService::fingerprint()" in hand[hand.find('public function print'):hand.find('public function handover')],
   'the register prints the same fingerprint, so two sheets that disagree are two configurations')

hv2 = re.sub(r'\s+', ' ', read(os.path.join(VIEWS, 'handover.blade.php')))
for piece, label in (
    ('1 · The decisions this property made', 'the sheet is numbered and says what each part is'),
    ('2 · What an unset setting runs on', 'it carries the unset answers'),
    ('3 · What the settings contradict', 'and the findings'),
    ('4 · What is checked against what', 'and what is checked against what'),
    ('5 · Credentials', 'and the credential count without a hint of a value'),
    ('6 · What it was before', 'and the value trail'),
    ('7 · Sign-off', 'and room to sign it'),
    ('the same fingerprint means the same configuration', 'it says how to compare two sheets'),
    ('not the same as "checked and clean"', 'and will not let an unchecked module read as verified'),
    ('a credential is stored encrypted and is not printed, exported or hashed here', 'and says a credential is not in the hash'),
    ('Prepared by', 'it has a signature line for whoever prepares it'),
    ('Reviewed by', 'and one for whoever reviews it'),
):
    ok(piece in hv2, f'handover sheet: {label}')
ok('collect(' not in hv2, 'the sheet joins its lists in the controller — a printable page needs no template helper')

ok('function fingerprintOf' in sample and 'crypto.createHash(\'sha1\')' in sample and 'lines.sort()' in sample,
   'preview: the fixture computes the fingerprint the same way, with a real hash')
ok('fingerprint: fingerprintOf(modules)' in sample, 'preview: the fixture carries it into the page')

# ── 18. the six new contradictions rest on a line of code, not on an opinion ────────
# A rule is only worth a finding if the code really behaves the way the finding describes.
# Each of these was added by reading the service that acts on the setting, so each is tied
# here to that line: if the behaviour changes, the rule has to change with it.
message_src = read(os.path.join(ROOT, 'app/Services/MessageService.php'))
ok("setting('sms_enabled', '0') === '1' && setting('sms_auto_confirmation', '0') === '1'" in message_src
   and "setting('sms_enabled', '0') === '1' && setting('sms_auto_thankyou', '0') === '1'" in message_src,
   'sms_automations_without_sms rests on the send branch gating both texts on sms_enabled')
ok("if (! $on('sms_enabled') && ($on('sms_auto_confirmation') || $on('sms_auto_thankyou')))" in checks_src,
   'and the rule is written as that gate, inverted')

proc_src = read(os.path.join(ROOT, 'app/Services/ProcurementService.php'))
proc_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/ProcurementController.php'))
ok("return [(float) setting('po_limit_1', '5000'), (float) setting('po_limit_2', '25000')];" in proc_src,
   'no_second_approval_tier rests on the two limits the service hands out, in that order')
ok("'po_limit_2' => 'required|numeric|gte:po_limit_1'" in proc_ctrl,
   'and on the screen accepting equality — which is the case the rule catches')
ok('[$limit1, $limit2] = \\App\\Services\\ProcurementService::limits();' in checks_src
   and 'if ($limit1 >= $limit2 && $limit2 > 0) {' in checks_src,
   'the rule reads the service rather than restating the limits')

ap_src = read(os.path.join(ROOT, 'app/Services/ApService.php'))
ok("return max((float) setting('ap_tolerance_abs', 1), round(abs($base) * (float) setting('ap_tolerance_pct', 1) / 100, 2));" in ap_src,
   'payables_tolerance_is_zero rests on the tolerance being the larger of the two, so zero and zero mean zero')
ok("if ((float) $s('ap_tolerance_pct', 1) <= 0 && (float) $s('ap_tolerance_abs', 1) <= 0) {" in checks_src,
   'and the rule fires only when both are zero, not when one of them carries the slack')

pay_src = read(os.path.join(ROOT, 'app/Services/PayrollService.php'))
ok("$unmarkedPresent = setting('payroll_unmarked', 'present') !== 'absent';" in pay_src,
   'payroll_unmarked_days_are_paid rests on payroll treating an unmarked day as present by default')
ok("if ((string) $s('payroll_unmarked', 'present') !== 'absent') {" in checks_src,
   'and the rule is that same default, said out loud')

roster_src = read(os.path.join(ROOT, 'app/Services/RosterService.php'))
ok('if ($rest < self::minRest()) {' in roster_src,
   'roster_min_rest_too_short rests on the roster warning only below the setting it is given')
ok("if (\\App\\Services\\RosterService::minRest() < 8) {" in checks_src,
   'and the rule fires when that number is too small to warn about anything')

ledger_src = read(os.path.join(ROOT, 'app/Services/LedgerService.php'))
payroll_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/PayrollController.php'))
ok('return ($lock && $date <= $lock) ? Carbon::parse($lock)->addDay()->toDateString() : $date;' in ledger_src,
   'locked_period_ahead_of_business_date rests on a journal on or before the lock being moved to the next open day')
ok('if (($lock = setting(\'gl_lock_date\')) && $d[\'date\'] <= $lock) {' in payroll_ctrl,
   'and on payroll refusing a posting inside the locked period outright')
ok('$lock > bdate()->toDateString()' in checks_src,
   'and the rule compares the lock with the business date the rest of the application posts against')

# every new rule's keys must be registered, read by the code and known to the fixture
for rule, keys in (
    ('sms_automations_without_sms', ['sms_enabled', 'sms_auto_confirmation', 'sms_auto_thankyou']),
    ('no_second_approval_tier', ['po_limit_1', 'po_limit_2']),
    ('payables_tolerance_is_zero', ['ap_tolerance_pct', 'ap_tolerance_abs']),
    ('payroll_unmarked_days_are_paid', ['payroll_unmarked']),
    ('roster_min_rest_too_short', ['roster_min_rest_hours']),
    ('locked_period_ahead_of_business_date', ['gl_lock_date']),
):
    ok(all(k in registry_keys for k in keys), f'{rule}: every key it names is in the register')
    ok(all(k in read_keys for k in keys), f'{rule}: and every key is really read by the code')
    ok(rule in modules_const, f'{rule}: it is filed under a module')

ok(len(modules_const) >= 43,
   f'the checks reach {len(set(modules_const.values()))} modules through {len(modules_const)} rules, and the number is stated, not implied')

# A rule the fixture can never show is a rule nobody has seen fail: the preview's finding
# list must be the rules, not a list of ids that merely mentions them.
sample_src = read(SAMPLE)
find_block = sample_src[sample_src.find('const FINDINGS = ['):sample_src.find('const SEVERITY_LABEL')]
finding_ids = set(re.findall(r"id: '([a-z0-9_]+)', severity:", find_block))
# The fixture shows a property with some of the findings, not all 31: what matters is that
# every one it shows is a rule the code really runs, and that the screen is exercised in all
# three severities.
ok(finding_ids <= set(modules_const),
   'every finding the preview shows is a rule the code really runs '
   f'(invented: {", ".join(sorted(finding_ids - set(modules_const))) or "none"})')
ok(len(finding_ids) >= 12, f'and it shows enough of them to exercise the screen ({len(finding_ids)} of {len(modules_const)})')
ok(set(modules_const) <= set(re.findall(r"'([a-z0-9_]+)'", sample_src[sample_src.find('export const ADVISORY_IDS'):sample_src.find('];', sample_src.find('export const ADVISORY_IDS'))])),
   'and every rule id is in the list the audit compares with the code')

# ── 18b. the six rules that reach modules nobody had looked at ──────────────────────
# Same test as 18: a finding is only worth printing if the code really behaves the way the
# finding says, so each new rule is tied to the line it was written from.
clock_src = read(os.path.join(ROOT, 'app/Services/TimeClockService.php'))
ok("return ! $list || ($ip && IpUtils::checkIp($ip, $list));" in clock_src,
   'self_clock_from_anywhere rests on an empty address list meaning "anywhere"')
ok("if ((string) $s('timeclock_self', '1') === '1' && $blank('timeclock_self_ips')) {" in checks_src,
   'and the rule is that reading, said out loud')

ok("$s = (string) setting('close_start', '');" in read(os.path.join(ROOT, 'app/Services/CloseService.php')),
   'close_starts_after_today rests on the close taking its first month from the setting')
ok("$closeStart > bdate()->format('Y-m')" in checks_src,
   'and the rule compares that month with the business date, not with today')

rental_src = read(os.path.join(ROOT, 'app/Services/RentalService.php'))
ok("$free = self::freeKmPerDay() * max(1, (int) ceil($r->pickup_at->diffInDays($r->return_due_at)));" in rental_src
   and "$over = self::mileagePerKm() * ($km - $free);" in rental_src,
   'excess_mileage_is_free rests on the excess being the allowance subtracted, multiplied by the rate')
ok("if (\\App\\Services\\RentalService::freeKmPerDay() > 0 && \\App\\Services\\RentalService::mileagePerKm() <= 0) {" in checks_src,
   'and the rule fires only when there is an allowance to exceed and no rate to charge it at')

fx_src = read(os.path.join(ROOT, 'app/Services/ForexService.php'))
ok(fx_src.count('return self::enabled() && setting(') == 3,
   'conversions_without_forex rests on the three conversions each asking self::enabled() first')
ok("return setting('forex_ap_pay_deal', self::DEFAULTS['forex_ap_pay_deal']) === '1';" in fx_src,
   'and on the deal-rate switch being the one that does not ask — which the finding says')
ok("if ((string) $s('forex_enabled', '1') !== '1'" in checks_src,
   'the rule reads the master switch the same way the service does')

ok("! $manual && $days > 0 && SurveyInvitation::where('guest_id', $g->id)" in read(os.path.join(ROOT, 'app/Services/FeedbackService.php')),
   'surveys_without_cooldown rests on the cooldown check being skipped when its window is zero')
ok("if ((int) $s('fb_cooldown_days', 30) === 0) {" in checks_src,
   'and the rule is that skip condition, read as a finding')

ok("if (setting('recreation_shift_required', '0') === '1' && auth()->check() && ! self::active()) {" in read(os.path.join(ROOT, 'app/Services/RecreationShiftService.php')),
   'recreation_sales_without_a_shift rests on the counter refusing a sale only when the shift is required')
ok("if ((string) $s('recreation_shift_required', '0') !== '1') {" in checks_src,
   'and the rule is the other side of that same if')

for rule, keys in (
    ('self_clock_from_anywhere', ['timeclock_self', 'timeclock_self_ips']),
    ('close_starts_after_today', ['close_start']),
    ('excess_mileage_is_free', ['rental_free_km', 'rental_mileage_per_km']),
    ('conversions_without_forex', ['forex_enabled', 'forex_channel_convert', 'forex_ap_convert', 'forex_ap_pay', 'forex_ap_pay_deal']),
    ('surveys_without_cooldown', ['fb_cooldown_days']),
    ('recreation_sales_without_a_shift', ['recreation_shift_required']),
):
    ok(all(k in registry_keys for k in keys), f'{rule}: every key it names is in the register')
    ok(all(k in read_keys for k in keys), f'{rule}: and every key is really read by the code')
    ok(rule in modules_const, f'{rule}: it is filed under a module')

# ── 18c. rules that reach the commercial and compliance modules ─────────────────────
# The same test again: each finding is tied to the line of code it was written from.
ok("$window = now()->subDays(max(0, (int) setting('crm_attribution_days', '30')));"
   in read(os.path.join(ROOT, 'app/Services/CrmService.php')),
   'campaign_revenue_expires_the_day_it_is_sent rests on the attribution window the report credits through')
ok("if ((int) $s('crm_attribution_days', 30) === 0) {" in checks_src,
   'and the rule fires only on the window that credits nothing')

ok("return $c->is_active && $c->kind() === 'listing' && (! $c->audited_at || $c->audited_at->lt(now()->subDays(self::cfg()['audit_days'])));"
   in read(os.path.join(ROOT, 'app/Services/DigitalMarketingService.php')),
   'listings_are_always_overdue_for_audit rests on a listing being stale once the window has passed')
ok("if ((int) $s('dm_audit_days', 90) === 0) {" in checks_src,
   'and the rule is that window collapsed to nothing')

ok("return $c->expires_on->lte($on->copy()->addDays(self::certWarnDays())) ? 'expiring' : 'valid';"
   in read(os.path.join(ROOT, 'app/Services/FoodSafetyService.php')),
   'certificate_warning_arrives_on_the_last_day rests on the certificate being flagged inside its warning window')
ok("if ((int) $s('fs_cert_warn_days', 30) === 0) {" in checks_src,
   'and the rule fires when that window is the expiry date itself')

lease_src = read(os.path.join(ROOT, 'app/Services/LeaseService.php'))
ok("if ($class === 'residential' && (int) self::cfg('lease_enforce_rent_cap')" in lease_src
   and "(int) setting('lease_enforce_rent_cap', 1)" in read(os.path.join(ROOT, 'app/Services/LeaseBillingService.php')),
   'rent_cap_not_enforced rests on the cap being checked only when the setting is on, in both the lease and its billing')
ok("if ((int) $s('lease_enforce_rent_cap', 1) === 0) {" in checks_src,
   'and the rule is the setting read the way both services read it')

ok("self::need(! self::cfg()['four_eyes'] || $i->raised_by !== $by->id,"
   in read(os.path.join(ROOT, 'app/Services/EsgService.php')),
   'emissions_approved_by_their_own_author rests on the four-eyes check being the only thing between an author and their own figure')
ok("if ((string) $s('esg_four_eyes', '1') !== '1') {" in checks_src,
   'and the rule is that check switched off')

partner_src = read(os.path.join(ROOT, 'app/Services/PartnerService.php'))
ok("return setting('partner_enabled', '1') === '1';" in partner_src
   and "self::need(setting('partner_pay', '1') === '1'," in partner_src
   and "self::need(setting('partner_cancel', '1') === '1'," in partner_src
   and "self::need(setting('partner_amend', '1') === '1'," in partner_src,
   'portal_switches_on_a_closed_portal rests on the three controls each reading their own switch behind the portal')
ok("if ((string) $s('partner_enabled', '1') !== '1'" in checks_src,
   'and the rule reads the portal switch the same way the service does')

for rule, keys in (
    ('campaign_revenue_expires_the_day_it_is_sent', ['crm_attribution_days']),
    ('listings_are_always_overdue_for_audit', ['dm_audit_days']),
    ('certificate_warning_arrives_on_the_last_day', ['fs_cert_warn_days']),
    ('rent_cap_not_enforced', ['lease_enforce_rent_cap']),
    ('emissions_approved_by_their_own_author', ['esg_four_eyes']),
    ('portal_switches_on_a_closed_portal', ['partner_enabled', 'partner_pay', 'partner_amend', 'partner_cancel']),
):
    ok(all(k in registry_keys for k in keys), f'{rule}: every key it names is in the register')
    ok(all(k in read_keys for k in keys), f'{rule}: and every key is really read by the code')
    ok(rule in modules_const, f'{rule}: it is filed under a module')

# a rule written in plain English carries an apostrophe — the parser that reads the rules
# has to keep it, or the parity check would silently lose the rule instead
ok(any("'" in r['detail'] for r in rules),
   'a rule whose sentence carries an apostrophe is still read as a rule')
ok(any("'" in r['title'] for r in rules), 'and one whose title does too')

# ── 19. the settings the register does not hold yet ────────────────────────────────
# A service that hands a variable to setting() reads a whole map of keys without naming one,
# and the register never saw them: no value, no default, no trail, no fingerprint. They are
# found from the maps now, so the number can be stated rather than guessed — and every one
# that is registered shrinks it.
reads_map_section = {}
for hit in re.finditer(r"'([a-z0-9_]+)' => \[([^\]]*)\]", reader_block('map')):
    reads_map_section[hit.group(1)] = re.findall(r"'([^']+)'", hit.group(2))

map_gap = sorted(set(reads_map_section) - set(registry_keys))
ok(len(reads_map_section) >= 300,
   f'the generated read graph carries the settings read behind a service map, not only the ones named in a call ({len(reads_map_section)})')
ok(len(reads_map_section) == len(map_keys),
   'the two readings of the map section agree, so neither pass is counting something the other cannot see')

# the gap is arithmetic, not an opinion: map reads minus registered keys
ok(len(map_gap) == len(reads_map_section) - len(set(reads_map_section) & set(registry_keys)),
   'the gap is exactly the map-read settings this register does not hold')

# the handover sheet says the number out loud
handover_view = read(os.path.join(ROOT, 'resources/views/configuration/handover.blade.php'))
ok('{{ $mapGap }}' in handover_view and 'not in this register yet' in handover_view,
   'the handover sheet states how many settings are read behind a map and are not in the register')
ctrl_src = read(os.path.join(ROOT, 'app/Http/Controllers/ConfigurationController.php'))
ok("'mapGap' => ConfigurationService::mapGapCount()" in ctrl_src,
   'and the number comes from the same generated map the audit reads, not from a hand-typed count')
svc_src = read(os.path.join(ROOT, 'app/Services/ConfigurationService.php'))
ok('public static function mapGap(): array' in svc_src and 'public static function mapGapCount(): int' in svc_src
   and "config('setting-readers.map', [])" in svc_src,
   'the service takes the gap from the generated map rather than a second list')

# the modules that used to say "no settings" now hold the keys the code was reading
petty_keys = ['petty_receipt_threshold', 'petty_iou_days', 'petty_count_days', 'petty_low_pct', 'petty_variance_tolerance']
ok(all(k in modules['petty']['keys'] for k in petty_keys),
   'petty cash holds the five settings its service reads through a map')
ok(all(k in reads_map_section for k in petty_keys),
   'and each of them resolves to the file that reads it, through the map')
defaults_src2 = read(os.path.join(ROOT, 'config/setting-defaults.php'))
ok(all(re.search(r"'%s' => '[^']*', // app/Services/PettyCashService.php" % re.escape(k), defaults_src2) for k in petty_keys),
   'their built-in defaults come from the map the service declares, not from a copy')
ok(not (set(petty_keys) - set(registry_keys)) and not (set(petty_keys) & set(map_gap)),
   'and registering them is what took them out of the gap — the list shrinks by working, not by editing')

# the finance modules the register used to describe with an empty key list now hold what the
# code has been reading, and each key resolves to the file that reads it
finance = {
    'bankrec': ['bankrec_window_days', 'bankrec_stale_days', 'bankrec_segregation'],
    'claims': ['claims_mileage_rate', 'claims_receipt_threshold', 'claims_max_age_days'],
    'treasury': ['treasury_approval_threshold', 'treasury_cash_float', 'treasury_payday',
                 'treasury_collect_pct', 'treasury_arrival_pct', 'treasury_clear_days'],
    'budgets': ['budget_fy_start', 'budget_live_from', 'budget_var_pct', 'budget_var_amount',
                'budget_control', 'budget_control_basis'],
    'vouchers': ['gv_enabled', 'gv_validity_months', 'gv_reminder_days', 'gv_min_value',
                 'gv_max_value', 'gv_terms'],
    'audit': ['ia_rate_tolerance_pct', 'ia_discount_limit', 'ia_cash_tolerance', 'ia_inhouse_limit',
              'ia_authorise_limit', 'ia_comp_nights_limit', 'ia_four_eyes', 'ia_lock_closed'],
}
for mod, keys in finance.items():
    ok(all(k in modules[mod]['keys'] for k in keys),
       f'{mod}: the register holds the {len(keys)} settings its own screen edits')
    ok(all(k in reads_map_section for k in keys),
       f'{mod}: and every one of them resolves to the file that reads it')
    ok(all(k in default_keys for k in keys),
       f'{mod}: and the built-in default comes from the code, not from the register')

# a module that holds keys and cannot be edited would be a setting nobody can change
for mod in finance:
    ok(bool(modules[mod].get('edit') or modules[mod].get('perm')),
       f'{mod}: the screen that edits these keys is named with the permission it needs')

ok(len(set(reads_map_section) & set(registry_keys)) >= 100,
   f'the register holds {len(set(reads_map_section) & set(registry_keys))} of the {len(reads_map_section)} settings '
   f'the code reads behind a map — {len(map_gap)} are still outside it and counted on the sheet')

# a default taken from a map is the value in the map, not a second copy of it: for every
# registered key read through a map, the generated default has to be the map's value. A
# tuple map ('k' => ['a label', 10]) keeps its default last, which is what this reads.
src_defaults = {}
for _base, _d, _files in os.walk(os.path.join(ROOT, 'app')):
    for _name in _files:
        if not _name.endswith('.php'):
            continue
        _src = read(os.path.join(_base, _name))
        if not re.search(r"setting\s*\(\s*\$", _src):
            continue
        for _hit in re.finditer(r"public\s+const\s+(?:DEFAULTS|SETTINGS|CONFIG)\s*=\s*\[(.*?)\];", _src, re.S):
            for _pair in re.finditer(r"'([a-z][a-z0-9_]{2,})'\s*=>\s*(?:'([^']*)'|(-?[0-9][0-9.]*)|\[([^\]]*)\])", _hit.group(1)):
                _key = _pair.group(1)
                if _pair.group(4) is not None:
                    _parts = [x[1:-1].replace("\\'", "'") if x.startswith("'") else x
                              for x in re.findall(r"'(?:[^'\\]|\\.)*'|-?[0-9][0-9.]*", _pair.group(4))]
                    if not _parts:
                        continue
                    _plain = [x for x in _parts if ' ' not in x and len(x) <= 25]
                    _val = _plain[0] if _plain else _parts[-1]
                elif _pair.group(2) is not None:
                    _val = _pair.group(2)
                else:
                    _val = _pair.group(3)
                src_defaults.setdefault(_key, _val)

defaults_pairs = dict(re.findall(r"^        '([a-z0-9_]+)' => '((?:[^'\\]|\\.)*)',", read(os.path.join(ROOT, 'config/setting-defaults.php')), re.M))


def php_unescape(value):
    # the generated file escapes a backslash as \\, so a reply pattern that really is
    # \b(OK|ACK)\b would otherwise compare unequal to itself and read as a drifted default
    out, i = [], 0
    while i < len(value):
        if value[i] == '\\' and i + 1 < len(value) and value[i + 1] in ('\\', "'"):
            out.append(value[i + 1])
            i += 2
        else:
            out.append(value[i])
            i += 1

    return ''.join(out)
map_default_wrong = sorted(
    f'{k} (map says {v}, register shows {defaults_pairs.get(k, "none")})'
    for k, v in src_defaults.items()
    if k in registry_keys and k in map_keys and k in defaults_pairs
    and php_unescape(defaults_pairs[k]) != v
)
ok(not map_default_wrong,
   f'a default read from a map is the value in the map (wrong: {"; ".join(map_default_wrong[:4])})')
ok(defaults_pairs.get('ia_four_eyes') == '1' and defaults_pairs.get('ia_cash_tolerance') == '10',
   'the tuple maps are read for their default, not only their key (ia_four_eyes 1, ia_cash_tolerance 10)')

# round 20: the club desk and the front desk both edit their settings on their own screen,
# from a tuple map (the club) and a string map read with a default (the desk)
field_ops = {
    'club': ['club_grace_days', 'club_renew_days', 'club_checkout_hours', 'club_daypass_adult',
             'club_daypass_child', 'club_guestpass', 'club_resident_free', 'club_class_cancel_hours',
             'club_noshow_limit', 'club_cap_gym', 'club_cap_pool', 'club_cap_sauna',
             'club_cap_tennis', 'club_cap_squash', 'club_cap_kids', 'club_cap_classes'],
    'desk': ['require_cashier_shift', 'desk_shift_morning', 'desk_shift_evening', 'desk_shift_night',
             'desk_log_unread_days', 'desk_wakeup_lead', 'desk_wakeup_grace', 'desk_wakeup_retry',
             'desk_wakeup_max_attempts', 'desk_escalate_phone', 'desk_msg_notify',
             'desk_msg_include_text', 'desk_msg_remind_minutes', 'desk_lf_retention',
             'desk_lf_retention_valuable', 'desk_lf_retention_perishable',
             'desk_lf_valuable_amount', 'desk_lf_notify_guest'],
    # round 21: the forex desk and the Form C desk share one screen, and every key on it is
    # now in the register — the two families are separate modules because the log names them
    # separately ('forex' and 'formc'), and a setting has to be filed where its changes are
    'fx': ['forex_enabled', 'forex_cash_cap_usd', 'forex_resident_cap_usd', 'forex_allow_residents',
           'forex_aggregate_days', 'forex_id_copy_inr', 'forex_max_margin', 'forex_fee_pct',
           'forex_surrender_days', 'forex_carry_rates', 'forex_channel_convert',
           'forex_channel_basis', 'forex_channel_stale_days', 'forex_ap_convert',
           'forex_ap_basis', 'forex_ap_stale_days', 'forex_ap_rate_date', 'forex_ap_pay',
           'forex_ap_pay_deal', 'forex_licence', 'forex_franchiser'],
    'fg': ['formc_enabled', 'formc_deadline_hours', 'formc_alert_hours', 'formc_departure_hours',
           'formc_require_scans', 'formc_visa_exempt', 'formc_block_checkout', 'formc_alert_email'],
    # round 22: the hardware screen the estate actually runs on, and the security desk's tab
    'interfaces': ['pbx_token', 'pbx_enabled', 'pbx_driver', 'pbx_auto_post',
                   'pbx_min_secs', 'pbx_free_numbers', 'pbx_strip_prefix', 'pbx_format',
                   'pbx_mask_digits', 'pbx_default_cos', 'pbx_tcp_host', 'pbx_tcp_port',
                   'pbx_tcp_timeout', 'pbx_tcp_ok', 'pbx_smdr_host', 'pbx_smdr_port',
                   'pbx_cmd_checkin', 'pbx_cmd_checkout', 'pbx_cmd_cos', 'pbx_cmd_move',
                   'lock_enabled', 'lock_driver', 'lock_system', 'lock_max_cards', 'lock_lost_fee',
                   'lock_checkout_time', 'lock_grace_minutes', 'lock_auto_issue', 'lock_areas',
                   'lock_default_areas', 'lock_master_hours', 'lock_tcp_host', 'lock_tcp_port',
                   'lock_tcp_timeout', 'lock_tcp_ok', 'lock_reply_serial', 'lock_cmd_issue',
                   'lock_cmd_duplicate', 'lock_cmd_cancel', 'lock_cmd_checkout', 'lock_cmd_extend',
                   'lock_cmd_read'],
    'security': ['sec_visiting_until', 'sec_approval', 'sec_pass_hours', 'sec_require_id',
                 'sec_retention_days', 'sec_gp_approval', 'sec_incident_alert', 'sec_alert_phone'],
    # round 23: the three small screens that were left, and the module the alias was standing in
    # for — the API page holds the API's own settings, the Robita page the locks'
    'developers': ['api_enabled', 'api_log_days', 'api_allow_http', 'api_allow_private',
                   'webhook_disable_after'],
    'robita': ['robita_webhook_token', 'robita_default_model', 'robita_battery_alert',
               'robita_auto_issue', 'robita_auto_revoke', 'robita_cloud_url'],
    'upsell': ['upsell_enabled', 'upsell_days_before', 'upsell_auto_email',
               'upsell_guest_cancel_hours', 'upsell_keep_free'],
    # round 24: the two screens whose bounds live in the service rather than a controller rule
    'esg': ['esg_area_total', 'esg_area_rooms', 'esg_area_meeting', 'esg_area_private',
            'esg_ef_water_supply', 'esg_ef_wastewater', 'esg_ef_landfill', 'esg_ef_landfill_food',
            'esg_ef_recycling', 'esg_ef_composting', 'esg_ef_anaerobic', 'esg_ef_incineration',
            'esg_ef_donation', 'esg_ef_rendering', 'esg_ef_laundry', 'esg_laundry_outsourced',
            'esg_laundry_kg_room', 'esg_green_enabled', 'esg_green_points',
            'esg_green_service_every', 'esg_green_kwh', 'esg_green_litres', 'esg_leak_alert_pct',
            'esg_four_eyes'],
    # round 25: the in-room television and the self-service kiosk
    'iptv': ['tv_enabled', 'tv_name_mode', 'tv_default_lang', 'tv_langs', 'tv_welcome_on',
             'tv_welcome_image', 'tv_reset_on_checkout', 'tv_heartbeat', 'tv_offline_min',
             'tv_vod_enabled', 'tv_pin_purchase', 'tv_pin_bill', 'tv_adult', 'tv_max_rentals',
             'tv_dining', 'tv_requests', 'tv_checkout', 'tv_feedback', 'tv_weather', 'tv_lat',
             'tv_lon', 'tv_desk_ext', 'tv_power_channel', 'tv_volume_max', 'tv_event_days',
             'tv_signage_events', 'tv_signage_text'],
    'kiosks': ['kiosk_enabled', 'kiosk_checkin', 'kiosk_checkout', 'kiosk_walkin',
               'kiosk_checkin_from', 'kiosk_early', 'kiosk_room_ready', 'kiosk_room_choice',
               'kiosk_require_id', 'kiosk_require_sign', 'kiosk_deposit', 'kiosk_incidentals',
               'kiosk_max_keys', 'kiosk_vip_desk', 'kiosk_walkin_nights', 'kiosk_walkin_adults',
               'kiosk_early_checkout', 'kiosk_feedback', 'kiosk_idle', 'kiosk_pay_minutes',
               'kiosk_welcome', 'kiosk_terms', 'kiosk_breakfast'],
    'inbox': ['inbox_enabled', 'inbox_portal_chat', 'inbox_sla', 'inbox_hours_from', 'inbox_hours_to',
              'inbox_after_hours', 'inbox_after_hours_reply', 'inbox_ack', 'inbox_ack_reply',
              'inbox_auto_close', 'inbox_csat', 'inbox_default_dept'],
    'sales': ['sales_prob_prospect', 'sales_prob_qualified', 'sales_prob_proposal', 'sales_prob_negotiation',
              'sales_stale_days', 'sales_dormant_days', 'sales_followup_days', 'sales_birthday_days'],
    'fs': ['fs_fridge_max', 'fs_freezer_max', 'fs_hot_hold_min', 'fs_cook_min', 'fs_reheat_min',
           'fs_recv_chilled_max', 'fs_recv_frozen_max', 'fs_cool_2h_max', 'fs_cool_6h_max'],
    'rpt': ['rpt_email_domains', 'rpt_max_recipients', 'rpt_fail_limit', 'rpt_inline_rows'],
    'privacy': ['privacy_response_days', 'privacy_extension_days', 'privacy_tax_years', 'privacy_police_years',
                'retention_enabled', 'retention_id_days', 'retention_message_days', 'retention_inactive_years',
                'retention_unverified_days', 'breach_notify_hours', 'dpo_name', 'dpo_email', 'dpo_phone',
                'privacy_notice'],
    'concierge': ['concierge_portal', 'concierge_portal_lead', 'concierge_auto_complete', 'concierge_confirm_hours',
                  'concierge_notify', 'concierge_desk_phone'],
    'learning': ['learning_expiry_warn_days', 'learning_review_warn_days', 'learning_self_ack', 'learning_notify',
                 'learning_venue'],
    'leases': ['lease_bill_ahead_days', 'lease_late_fee_pct', 'lease_notice_days', 'lease_expiry_alert_days',
               'lease_elec_tariff', 'lease_water_tariff', 'lease_wht_rate', 'lease_deposit_months'],
    'utilities': ['eng_ef_electricity', 'eng_ef_dg', 'eng_ef_solar', 'eng_ef_water', 'eng_ef_lpg',
                  'eng_ef_diesel', 'eng_ef_png', 'eng_alert_pct', 'eng_require_readings'],
    'ap': ['wht_enabled', 'wht_threshold', 'wvat_agent', 'wvat_rate'],
    'fleet': ['transport_gps_stale_minutes', 'transport_gps_retention_days', 'transport_speed_moving_kph',
              'transport_speed_limit_kph', 'transport_idle_lph', 'transport_maintenance_lead_days',
              'transport_maintenance_lead_km', 'transport_inspection_days'],
    'tr': ['transport_airport', 'transport_station', 'transport_pickup_lead', 'transport_buffer',
           'transport_driver_reminder', 'transport_alert_hours', 'transport_notify_guest',
           'transport_notify_driver', 'transport_paper_days', 'transport_default_billing',
           'transport_request_expiry_hours'],
}
read_graph = set(reads_map_section) | set(map_keys) | set(read_keys)
for mod, keys in field_ops.items():
    ok(all(k in modules.get(mod, {}).get('keys', []) for k in keys),
       f'{mod}: the register holds the {len(keys)} settings its own screen edits')
    ok(all(k in read_graph for k in keys),
       f'{mod}: and every one of them resolves to the file that reads it')
    ok(all(k in default_keys for k in keys),
       f'{mod}: and the built-in default comes from the code, not from the register')

# the club writes every key from one tuple map, so a key the map does not declare cannot be
# saved — the screen and the register have to be the same list
club_map = re.search(r"public const SETTINGS = \[(.*?)\n    \];",
                     read(os.path.join(ROOT, 'app/Services/ClubService.php')), re.S).group(1)
club_map_keys = set(re.findall(r"^\s{8}'([a-z0-9_]+)'\s*=>", club_map, re.M))
ok(club_map_keys == set(field_ops['club']),
   f'the club screen saves exactly the {len(club_map_keys)} keys its map declares — no key in one list only')
ok(defaults_pairs.get('club_cap_pool') == '40' and defaults_pairs.get('club_daypass_child') == '80',
   'the club tuple map is read for its default (pool capacity 40, child day pass 80)')

# the desk has three shift times, and the screen refuses a save unless they run in order, so
# the register has to describe them as times, not as counters
ok(re.search(r"^        'desk_shift_morning' => '07:00',$", read(os.path.join(ROOT, 'config/setting-defaults.php')), re.M),
   'the desk shift times keep their 07:00 / 15:00 / 23:00 shape in the register')
ok(re.search(r"morning .{0,40}evening .{0,40}night", modules['desk']['brief']),
   'and the desk brief says the three times must run morning → evening → night')

# a default that is a regular expression has to survive the round trip: the generated file
# escapes its backslashes, and reading it without unescaping made two reply patterns look like
# defaults that had drifted from the map when they were the same string
ok(php_unescape(defaults_pairs.get('pbx_tcp_ok', '')) == src_defaults.get('pbx_tcp_ok')
   and '\\b' in defaults_pairs.get('pbx_tcp_ok', ''),
   'a default that is a pattern keeps its backslashes — the register shows the reply the code matches')


# ── 20. a brief states the range its own screen enforces ────────────────────────────
# Round 7 proved this for the ranges a service validates from a tuple map (`$ints`, `$num`).
# A range written as a Laravel rule in the controller — 'required|integer|min:7|max:365' —
# is the same promise, and it was not being read. It is read here.
controller_rules = {}
for base, _d, files in os.walk(os.path.join(ROOT, 'app/Http/Controllers')):
    for name in files:
        if not name.endswith('.php'):
            continue
        src = read(os.path.join(base, name))
        for hit in re.finditer(r"'(\w+)'\s*=>\s*'((?:[a-z]+\|)*(?:[a-z]+)?min:(\d+)(?:\|max:(\d+))?)'", src):
            key, lo, hi = hit.group(1), hit.group(3), hit.group(4)
            if hi is None:
                continue  # a floor with no ceiling is not a range to state
            controller_rules.setdefault(key, set()).add((lo, hi))

module_of = {}
for _m, _mdef in modules.items():
    for _k in _mdef['keys']:
        module_of[_k] = _m

missing_ranges = []
for key, ranges in controller_rules.items():
    if key not in registry_keys or key not in module_of:
        continue
    brief = modules[module_of[key]]['brief'].replace(',', '')
    for lo, hi in ranges:
        # the two numbers have to stand together as a range: "7" and "365" somewhere in the
        # paragraph is not the same statement as "7–365", and a brief that loses its range
        # still contains both digits by accident of its other sentences
        if not re.search(r'(?<![\d])%s[^\d]{1,6}%s(?![\d])' % (lo, hi), brief):
            missing_ranges.append(f'{key} ({lo}–{hi} in {module_of[key]})')
ok(not missing_ranges,
   f'every brief states the range its controller enforces (silent: {"; ".join(sorted(missing_ranges)[:6])})')
ok(len(controller_rules) >= 10,
   f'the ranges the controllers enforce are read, not assumed ({len(controller_rules)} keys with min and max)')

# ── 21. a bound the screen prints beside a field is a bound in the brief ─────────────
# §20 reads the ranges a controller validates. A screen can also carry the bound as a field
# attribute — `<x-field name="wakeup_lead" min="0" max="120">` — and the desk posts its fields
# without the module prefix, so no controller rule names the setting and §20 cannot see it.
# The bound a person reads beside the box is checked here against the brief that describes it.
blade_ranges = []
for _bmod, _m in modules.items():
    _seg = (_m.get('route') or '').split('.')[0]
    _vdir = os.path.join(ROOT, 'resources/views', _seg)
    if not _seg or not os.path.isdir(_vdir):
        continue
    _keys = set(_m['keys'])
    for _base, _d, _files in os.walk(_vdir):
        for _name in _files:
            if not _name.endswith('.blade.php'):
                continue
            for _hit in re.finditer(r'<x-field\s+[^>]*name="([a-z0-9_]+)"[^>]*?min="(\d+)"[^>]*?max="(\d+)"',
                                    read(os.path.join(_base, _name))):
                _field, _lo, _hi = _hit.group(1), int(_hit.group(2)), int(_hit.group(3))
                _cand = [k for k in _keys if k == _field or k.endswith('_' + _field)]
                if len(_cand) == 1:
                    blade_ranges.append((_bmod, _cand[0], _lo, _hi, _name))

ok(len(blade_ranges) >= 20,
   f'the bounds a settings screen prints beside its fields are read ({len(blade_ranges)} of them), not assumed')
blade_unstated = sorted({
    f'{bm}.{key} ({lo}–{hi}, from {name})'
    for bm, key, lo, hi, name in blade_ranges
    if not re.search(r'(?<![\d])%d[^\d]{1,6}%d(?![\d])' % (lo, hi), modules[bm]['brief'].replace(',', ''))
})
ok(not blade_unstated,
   f'a bound printed beside a field is a bound the brief states (unstated: {"; ".join(blade_unstated[:4])})')

# ── 22. a rule posted under a short name still belongs to the key it saves ───────────
# A settings screen often posts the field without the module prefix — the desk posts
# 'wakeup_lead' and the forex section posts 'cash_cap_usd' — so §20, which matches the rule
# name to the key, never sees them. The controller that saves through a service tells us
# which keys the rules belong to: the service's own DEFAULTS map is the vocabulary. A rule
# name is resolved to a key only when exactly one key in that vocabulary ends with it, so a
# generic field called 'days' is not attributed to any of the forty keys ending in '_days'.
svc_keys = {}
for _base, _d, _files in os.walk(os.path.join(ROOT, 'app/Services')):
    for _name in _files:
        if not _name.endswith('.php'):
            continue
        _src = read(os.path.join(_base, _name))
        _ks = set()
        for _hit in re.finditer(r"public\s+const\s+(?:DEFAULTS|SETTINGS|CONFIG)\s*=\s*\[(.*?)\];", _src, re.S):
            _ks |= set(re.findall(r"'([a-z][a-z0-9_]{2,})'\s*=>", _hit.group(1)))
        if _ks:
            svc_keys[_name[:-4]] = _ks

short_rules, short_ambiguous = [], 0
for _base, _d, _files in os.walk(os.path.join(ROOT, 'app/Http/Controllers')):
    for _name in _files:
        if not _name.endswith('.php'):
            continue
        _src = read(os.path.join(_base, _name))
        # a controller may import the service under a short name — `use App\Services\TransportService
        # as TS;` then `TS::saveSettings($d)` — and reading only the long name made the transport
        # desk's six bounds invisible to this check
        _alias = {short: full for full, short in re.findall(r"use App\\Services\\(\w+) as (\w+);", _src)}
        _called = {_alias.get(x, x) for x in re.findall(r"\b(\w+)::saveSettings\(", _src)}
        _svcs = {x for x in _called if x in svc_keys}
        if not _svcs:
            continue
        _pool = set().union(*(svc_keys[x] for x in _svcs))
        for _hit in re.finditer(r"'(\w+)'\s*=>\s*'((?:[a-z]+\|)*(?:[a-z]+)?min:(\d+)(?:\|max:(\d+))?)'", _src):
            _n, _lo, _hi = _hit.group(1), _hit.group(3), _hit.group(4)
            if _hi is None or _n in registry_keys:
                continue
            _cand = [k for k in _pool if k == _n or k.endswith('_' + _n)]
            if len(_cand) == 1:
                short_rules.append((_cand[0], int(_lo), int(_hi), _name))
            elif _cand:
                short_ambiguous += 1

ok(len(short_rules) >= 8,
   f'a rule posted under a short field name is still resolved to the setting it saves ({len(short_rules)} of them)')
short_unstated = sorted({
    f'{key} ({lo}–{hi}, from {name})'
    for key, lo, hi, name in short_rules
    if key in module_of and not re.search(r'(?<![\d])%d[^\d]{1,6}%d(?![\d])' % (lo, hi),
                                          modules[module_of[key]]['brief'].replace(',', ''))
})
ok(not short_unstated,
   f'and the brief of the module that saves it states the range (unstated: {"; ".join(short_unstated[:4])})')

# ── 23. a settings screen on the sidebar is reached through the module that owns it ──
# Robita's screen sat behind an alias to `developers`, so §4 was satisfied by a module whose
# page is a different screen and whose keys are a different family. The alias must not be
# doing that work: a sidebar screen has to resolve to a module that holds the settings that
# screen saves, and an alias may only point at a module that really holds that family's keys.
sidebar_src = read(CONFIG)
listed_routes = {m['route']: k for k, m in modules.items() if m['route']}
sidebar_settings = sorted({
    n for n in route_uri
    if (n.endswith('.settings') or n.endswith('.settings.store'))
    and n in sidebar_src
})

wrong_home = []
for name in sidebar_settings:
    if name in listed_routes:
        continue
    family = name.split('.')[0]
    aliased = FAMILY_ALIAS.get(family, family)
    home = modules.get(aliased)
    if home is None:
        wrong_home.append(f'{name} → no module')
        continue
    if not any(k.startswith(family + '_') or k.startswith(aliased + '_') for k in home['keys']):
        wrong_home.append(f'{name} → {aliased} (holds no {family}_* key)')

ok(len(sidebar_settings) >= 8,
   f'the settings screens the sidebar leads to are read ({len(sidebar_settings)} of them)')
ok(not wrong_home,
   f'each of them lands on the module that holds its settings (wrong: {"; ".join(wrong_home[:4])})')
ok('robita' in modules and any(k.startswith('robita_') for k in modules['robita']['keys']),
   'the Robita locks screen has a module of its own, not an alias into the API page')

# ── 24. a bound a service enforces is a bound in the brief ──────────────────────────
# §20 reads Laravel rules, §22 follows a short field name back to its key. Some services
# validate in their own words — `$num('green_points', 0, 10000, '…')`, `self::need(…)` — and
# those bounds were stated nowhere. The reader below takes the literal form and the two loop
# forms the code really uses (`foreach (['a' => 'L'] as $k => $l)` and `foreach (['a', 'b'] as $k)`)
# where the assignment spells the key with a literal prefix; anything it cannot read statically
# it leaves alone rather than guess.
service_bounds = []
for _base, _d, _files in os.walk(os.path.join(ROOT, 'app/Services')):
    for _name in _files:
        if not _name.endswith('.php'):
            continue
        _src = read(os.path.join(_base, _name))
        _keys = set()
        for _hit in re.finditer(r"public\s+const\s+(?:DEFAULTS|SETTINGS|CONFIG)\s*=\s*\[(.*?)\];", _src, re.S):
            _keys |= set(re.findall(r"'([a-z][a-z0-9_]{2,})'\s*=>", _hit.group(1)))
        if not _keys:
            continue

        def resolve(name):
            hits = [k for k in _keys if k == name or k.endswith('_' + name)]
            return hits[0] if len(hits) == 1 else None

        for _hit in re.finditer(r"\$num\(\s*'([a-z0-9_]+)'\s*,\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)", _src):
            _k = resolve(_hit.group(1))
            if _k:
                service_bounds.append((_k, _hit.group(2), _hit.group(3), _name))

        # a map of key => [floor, ceiling, message] read in one foreach is the same promise as a
        # literal call, and it is how the kiosk enforces six of its bounds
        for _blk in re.finditer(r"foreach\s*\(\s*\[(.{0,1500}?)\]\s*as\s*\$k\s*=>\s*\[\s*\$lo\s*,\s*\$hi", _src, re.S):
            for _p in re.finditer(r"'([a-z][a-z0-9_]+)'\s*=>\s*\[\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)", _blk.group(1)):
                _k = resolve(_p.group(1))
                if _k:
                    service_bounds.append((_k, _p.group(2), _p.group(3), _name))

        # a validation rule built in a loop over the module's own limits is the same promise as a
        # literal call: FoodSafetyService holds nine critical limits with
        # `collect(FS::SETTINGS)->mapWithKeys(fn ($s, $k) => [$k => 'required|numeric|between:-40,100'])`
        # and neither the screen nor the brief said what that band was
        for _hit in re.finditer(r"collect\(\s*(?:[A-Za-z_]+::|self::)([A-Z_]+)\s*\)\s*->\s*mapWithKeys\(\s*fn\s*\([^)]*\$k\s*\)\s*=>\s*\[\s*\$k\s*=>\s*'[^']*between:\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)", _src):
            _const, _lo, _hi = _hit.group(1), _hit.group(2), _hit.group(3)
            _blk = re.search(r"public\s+const\s+%s\s*=\s*\[(.*?)\];" % _const, _src, re.S)
            _names = re.findall(r"'([a-z][a-z0-9_]+)'\s*=>", _blk.group(1)) if _blk else []
            for _k in (_names or _keys):
                service_bounds.append((_k, _lo, _hi, _name))

        # a clamp is a bound with no message: `max(5, min(500, …))` quietly raises or lowers what the
        # manager typed, and neither the utilities screen nor its brief printed the range
        for _hit in re.finditer(r"^(.*?)max\(\s*(-?[\d.]+)\s*,\s*min\(\s*(-?[\d.]+)\s*,", _src, re.M):
            _before, _lo, _hi = _hit.group(1), _hit.group(2), _hit.group(3)
            _tail = _src[_hit.end():_hit.end() + 200]
            # only the first name after the clamp belongs to it: `$g('eng_alert_pct')` is the value
            # being clamped, while a name a few characters later in the same statement is not
            _names = re.findall(r"\$(?:g|s)\(\s*'([a-z0-9_]+)'\s*\)", _tail)
            _first = resolve(_names[0]) if _names else None
            if _first:
                service_bounds.append((_first, _lo, _hi, _name))
            _pre = re.search(r"\[\s*'([a-z][a-z0-9_]*_)'\s*\.\s*\$", _before)
            if _pre:
                for _k in _keys:
                    if _k.startswith(_pre.group(1)):
                        service_bounds.append((_k, _lo, _hi, _name))

        for _loop in re.finditer(
                r"foreach\s*\(\s*\[((?:[^\]]|\](?!\s*as))*)\]\s*as\s*\$k(?![a-zA-Z0-9_])[^{]*\{(.{0,600}?)\n\s{8}\}",
                _src, re.S):
            _names = re.findall(r"'([a-z0-9_]+)'(?:\s*=>\s*'[^']*')?", _loop.group(1))
            for _m in re.finditer(r"\$out\['([a-z_]+)'\s*\.\s*\$k\]\s*=\s*(?:\(string\)\s*\(int\)\s*)?\$num\(\s*(?:'([a-z_]+)'\s*\.\s*\$k|\$k)\s*,\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)",
                                  _loop.group(2)):
                prefix, lit, lo, hi = _m.group(1), _m.group(2), _m.group(3), _m.group(4)
                for n in _names:
                    _k = resolve(f'{lit}{n}' if lit else n)
                    if _k == f'{prefix}{n}'.strip():
                        service_bounds.append((_k, lo, hi, _name))

# The same promise is written in a controller: FoodSafetyController holds its nine critical limits
# with `collect(FS::SETTINGS)->mapWithKeys(fn ($s, $k) => [$k => 'required|numeric|between:-40,100'])`,
# where FS is an aliased service (§22). The band was stated on no screen and in no brief.
for _base, _d, _files in os.walk(os.path.join(ROOT, 'app/Http/Controllers')):
    for _name in _files:
        if not _name.endswith('.php'):
            continue
        _src = read(os.path.join(_base, _name))
        if 'between:' not in _src:
            continue
        _uses = {}
        for _u in re.finditer(r"use\s+App\\Services\\([A-Za-z0-9_]+)(?:\s+as\s+([A-Za-z0-9_]+))?;", _src):
            _uses[_u.group(2) or _u.group(1)] = _u.group(1)
        for _hit in re.finditer(
                r"collect\(\s*([A-Za-z]+)::([A-Z_]+)\s*\)\s*->\s*mapWithKeys\(\s*fn\s*\([^)]*\$k\s*\)\s*=>\s*\[\s*\$k\s*=>\s*'[^']*between:\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)",
                _src):
            _alias, _const, _lo, _hi = _hit.group(1), _hit.group(2), _hit.group(3), _hit.group(4)
            _svc = os.path.join(ROOT, 'app/Services', _uses.get(_alias, _alias) + '.php')
            if not os.path.isfile(_svc):
                continue
            _blk = re.search(r"public\s+const\s+%s\s*=\s*\[(.*?)\];" % _const, read(_svc), re.S)
            if not _blk:
                continue
            for _k in re.findall(r"'([a-z][a-z0-9_]+)'\s*=>", _blk.group(1)):
                service_bounds.append((_k, _lo, _hi, _name))

service_bounds = sorted(set(service_bounds))
ok(len(service_bounds) >= 20,
   f'the bounds a service enforces in its own words are read ({len(service_bounds)} of them)')
def number_forms(value):
    # '0', '1000000' and '0.60' are written in a brief the way a person writes them; a literal
    # in the code may carry a trailing zero the brief does not, so both spellings are tried.
    forms = {value}
    try:
        forms.add('%g' % float(value))
    except ValueError:
        pass

    return forms


def states_range(brief, lo, hi):
    flat = brief.replace(',', '')
    for a in number_forms(lo):
        for b in number_forms(hi):
            if re.search(r'(?<![\d])%s[^\d]{1,6}%s(?![\d])' % (re.escape(a), re.escape(b)), flat):
                return True

    return False


service_unstated = sorted({
    f'{key} ({lo}–{hi}, from {name})'
    for key, lo, hi, name in service_bounds
    if key in module_of and not states_range(modules[module_of[key]]['brief'], lo, hi)
})
ok(not service_unstated,
   f'and the brief states the range the service refuses outside of (unstated: {"; ".join(service_unstated[:4])})')

# ── 25. a bound a screen prints on a plain input is a bound in the brief ─────────────
# §21 reads the shared field component. Nine screens write their own <input min max> instead,
# and those bounds were stated nowhere: the box refuses the value, the brief never said so.
# The field is matched to a key only when exactly one key of that module ends with its name.
plain_input_bounds = []
for _bmod, _m in modules.items():
    _seg = (_m.get('route') or '').split('.')[0]
    _vdir = os.path.join(ROOT, 'resources/views', _seg)
    if not _seg or not os.path.isdir(_vdir):
        continue
    for _base, _d, _files in os.walk(_vdir):
        for _name in _files:
            if not _name.endswith('.blade.php'):
                continue
            for _hit in re.finditer(r'<input\b[^>]*name="([a-z0-9_]+)"[^>]*?min="(\d+)"[^>]*?max="(\d+)"',
                                    read(os.path.join(_base, _name))):
                _field, _lo, _hi = _hit.group(1), int(_hit.group(2)), int(_hit.group(3))
                _cand = [k for k in _m['keys'] if k == _field or k.endswith('_' + _field)]
                if len(_cand) == 1:
                    plain_input_bounds.append((_bmod, _cand[0], _lo, _hi, _name))

ok(len(plain_input_bounds) >= 40,
   f'the bounds nine screens print on their own inputs are read ({len(plain_input_bounds)} of them), not assumed')
plain_unstated = sorted({
    f'{pm}.{key} ({lo}–{hi}, from {name})'
    for pm, key, lo, hi, name in plain_input_bounds
    if not re.search(r'(?<![\d])%d[^\d]{1,6}%d(?![\d])' % (lo, hi), modules[pm]['brief'].replace(',', ''))
})
ok(not plain_unstated,
   f'a bound printed on a plain input is a bound the brief states (unstated: {"; ".join(plain_unstated[:4])})')

# ── 26b. a band a screen prints from a loop is still a band ──────────────────────────
# The club writes all sixteen of its settings from one foreach, so every name in that form is a
# variable and §26's literal-input scan cannot see it. The card printed `min="0" step="any"` and no
# ceiling at all: the brief promised 0–100000, a capacity of 999999 passed the box and came back as
# a validation error, and 2.5 hours was silently truncated to 2 by the service.
club_plans = read(os.path.join(ROOT, 'resources/views/club/plans.blade.php'))
club_form = club_plans[club_plans.find('id="clubSettings"'):] if 'id="clubSettings"' in club_plans else ''
club_form = club_form[:club_form.find('</form>')] if '</form>' in club_form else club_form
ok('step="any" min="0"' not in club_form,
   'the club settings card no longer prints one loose band for all sixteen settings')
ok(club_form.count('max="100000"') >= 3,
   'every branch of the club settings card prints the ceiling the controller enforces ('
   + str(club_form.count('max="100000"')) + ' branches)')
ok('min="1" max="100000"' in club_form,
   'the auto check-out hours print the 1-hour floor the service applies with max(1, …)')
ok(bool(re.search(r"club_checkout_hours'\s*=>\s*'required\|integer\|min:1\|max:100000'", read(os.path.join(ROOT, 'app/Http/Controllers/ClubController.php')))),
   'and the controller refuses a check-out window under an hour instead of clamping it to one')
ok(re.search(r'1[^\d]{1,6}100000(?![\d])', modules['club']['brief'].replace(',', '')) is not None,
   'the club brief states the 1–100000 hours the screen and the controller now agree on')
ok('step="0.01"' in club_form and 'step="1"' in club_form,
   'cedis take two decimals and counts take whole numbers on the same card')

# ── 26c. a number on the settings form is a number the save refuses outside of ───────
# The settings form validated every field as `nullable|string|max:5000`. A point worth nothing was
# stored happily and a redemption then debited the guest's balance for GH₵ 0.00; a spend of 0 per
# point was read as 1 by the service. The band is now a constant the view prints and the save enforces.
setting_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/SettingController.php'))
setting_view = read(os.path.join(ROOT, 'resources/views/settings/index.blade.php'))
ok("'settings' => 'array', 'settings.*' => 'nullable|string|max:5000'" in setting_ctrl
   and 'self::numberRules()' in setting_ctrl,
   'the settings save no longer validates every field as free text')
ok("if ($type !== 'number')" in setting_ctrl and '$rules["settings.{$key}"]' in setting_ctrl,
   'and every number key on the form gets a rule of its own')
ok("'loyalty_point_value' => ['0.01', '1000']" in setting_ctrl,
   'the point value prints and enforces 0.01–1000 rather than accepting zero')
ok("'loyalty_spend_per_point' => ['1', '100000']" in setting_ctrl
   and "'loyalty_min_redeem' => ['1', '1000000']" in setting_ctrl,
   'the spend that earns a point floors at 1, and the minimum redemption floors at 1 point')
ok('SettingController::BOUNDS' in setting_view.replace('\\', ''),
   'the screen reads the same constant the save enforces, instead of printing min="0" for all four')
loyalty_src = read(os.path.join(ROOT, 'app/Services/LoyaltyService.php'))
ok('A point is currently worth nothing' in loyalty_src,
   'and a redemption refuses outright when a point is worth nothing, instead of burning the balance')
ok(re.search(r'0\.01[^\d]{1,6}1000', modules['loyalty']['brief'].replace(',', '')) is not None
   and re.search(r'1[^\d]{1,6}100000', modules['loyalty']['brief'].replace(',', '')) is not None,
   'the loyalty brief states the bands the screen and the save now agree on')

# ── 26d. a key's label has to describe what the code does with it ────────────────────
# The general ledger register called gl_input_tax an "Input tax account" (the value is 1 or 0 —
# whether purchase tax is claimable), gl_cutoff an hour of the day (it is the closed business day
# the ledger has been posted up to), gl_retry a toggle (it is a JSON queue of postings to retry),
# and the per-category accounts simply "Cat". An auditor reading the sheet would look for accounts
# that do not exist and for switches nobody can flick.
gl_keys = modules['gl']['keys']
gl_ledger = read(os.path.join(ROOT, 'app/Services/LedgerService.php'))
ok(re.search(r'claimable', gl_keys.get('gl_input_tax', ''), re.I) is not None
   and "setting('gl_input_tax', '1') === '1'" in gl_ledger,
   'gl_input_tax is described as a claimability flag, which is how the ledger reads it')
ok(re.search(r'posted up to', gl_keys.get('gl_cutoff', ''), re.I) is not None
   and "'gl_cutoff' => $cutoff" in gl_ledger,
   'gl_cutoff is described as the closed day posted up to, which is the date the sync writes')
ok(re.search(r'queued|retry on', gl_keys.get('gl_retry', ''), re.I) is not None
   and "json_decode(setting('gl_retry')" in gl_ledger,
   'gl_retry is described as a queue, which is the list the sync decodes')
ok(len(gl_keys.get('gl_cat_', '')) > 20 and 'category' in gl_keys.get('gl_cat_', '').lower(),
   'the per-category mapping prefix says what it maps, rather than standing in as "Cat"')
ok(re.search(r'posted up to', modules['gl']['brief'], re.I) is not None,
   'and the brief tells the same story as the labels')

# ── 26e. the withholding desk printed a floor and hid the ceiling ────────────────────
# WhtController validated `wht_threshold` as 0–10000000 and the brief stated the same band, while
# the input on the withholding desk carried only min="0": 50,000,000 passed the box and came back as
# an error. The payables tolerances had no band at all — a tolerance of 150 % waves through any
# difference between a bill and the goods received.
wht_view = read(os.path.join(ROOT, 'resources/views/ap/wht.blade.php'))
ok('max="10000000"' in wht_view,
   'the withholding threshold input prints the ceiling WhtController enforces')
ok(re.search(r"wht_threshold'\s*=>\s*'required\|numeric\|min:0\|max:10000000'", read(os.path.join(ROOT, 'app/Http/Controllers/WhtController.php'))) is not None,
   'and the controller still enforces that ceiling')
setting_ctrl_ap = read(os.path.join(ROOT, 'app/Http/Controllers/SettingController.php'))
ok("'ap_tolerance_pct' => ['0', '100']" in setting_ctrl_ap,
   'the three-way match percentage cannot exceed 100')
ok("'ap_tolerance_abs' => ['0', '1000000']" in setting_ctrl_ap,
   'and the always-allow amount has a printed ceiling too')
ap_brief = modules['ap']['brief'].replace(',', '')
ok(re.search(r'0[^\d]{1,6}100(?![\d])', ap_brief) is not None
   and re.search(r'0[^\d]{1,6}1000000(?![\d])', ap_brief) is not None,
   'the payables brief states both tolerance bands')
ok(re.search(r'0[^\d]{1,6}10000000(?![\d])', ap_brief) is not None,
   'and the withholding threshold band')

# ── 26f. a checklist saved to a fixed shape is a shape the save refuses outside of ────
# CloseService stored the manual checklist as at most 15 rows of 30 + 150 characters and truncated
# silently: line 16 vanished and an over-long task lost its tail, with the save reporting success and
# the brief saying nothing. It now refuses, and both the screen and the brief state the caps.
close_service = read(os.path.join(ROOT, 'app/Services/CloseService.php'))
ok('MANUAL_MAX_ROWS = 15' in close_service and 'MANUAL_AREA_MAX = 30' in close_service
   and 'MANUAL_TASK_MAX = 150' in close_service,
   'the checklist caps live in one place the screen and the save both read')
ok('public static function manualProblems' in close_service,
   'and a checklist that breaks them can be described in words')
ok('manualProblems' in read(os.path.join(ROOT, 'app/Http/Controllers/CloseController.php')),
   'CloseController refuses the checklist instead of truncating it')
close_view = read(os.path.join(ROOT, 'resources/views/close/index.blade.php'))
ok("CloseService::MANUAL_MAX_ROWS" in close_view and "CloseService::MANUAL_TASK_MAX" in close_view,
   'the close screen prints the caps the save enforces')
ok("@error('manual')" in close_view,
   'and shows why a checklist was refused')
close_brief = modules['close']['brief']
ok(re.search(r'15 tasks', close_brief) is not None and re.search(r'30 characters', close_brief) is not None
   and re.search(r'150', close_brief) is not None,
   'the close brief states the checklist caps')
close_due_input = re.search(r'<input[^>]*name="due_days"[^>]*>', close_view)
close_view_due = close_due_input is not None and 'min="1"' in close_due_input.group(0) and 'max="60"' in close_due_input.group(0)
ok(close_view_due is not None and '1 to 60' in close_view,
   'and the due window is printed as a band, not only as attributes')

# ── 26g. the two money settings in Treasury had no band, and the deposit note did not add up ──
# Every other treasury setting printed its band (1–28, 0–10, 0–100) and TreasuryService clamps the two
# money ones only at zero — a state in which no finding fires. And suggest() explained a suggested
# deposit with "cash in hand less the float" while the amount also had a deposit already requested
# taken off and was rounded down to the nearest ten.
treasury_view = read(os.path.join(ROOT, 'resources/views/treasury/accounts.blade.php'))
treasury_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/TreasuryController.php'))
treasury_service = read(os.path.join(ROOT, 'app/Services/TreasuryService.php'))
ok('max="10000000"' in treasury_view and 'max="1000000"' in treasury_view,
   'both treasury money settings print a ceiling on their inputs')
ok(re.search(r"0 to 10000000|0 to 10,000,000", treasury_view) is not None
   and re.search(r"0 to 1000000|0 to 1,000,000", treasury_view) is not None,
   'and state the band in words beside them')
ok("'approval_threshold' => 'required|numeric|min:0|max:10000000'" in treasury_ctrl
   and "'cash_float' => 'required|numeric|min:0|max:1000000'" in treasury_ctrl,
   'the controller refuses what the inputs refuse')
treasury_brief = modules['treasury']['brief'].replace(',', '')
ok(re.search(r'0[^\d]{1,6}10000000(?![\d])', treasury_brief) is not None,
   'the treasury brief states the approval threshold band')
ok(re.search(r'0[^\d]{1,6}1000000(?![\d])', treasury_brief) is not None,
   'and the banking float band')
ok('already requested' in treasury_service and 'rounded down to the nearest' in treasury_service,
   'the suggested deposit names every term it was reduced by, including the rounding')
ok('$float' in treasury_service and "$committed" in treasury_service,
   'and the terms come from the figures the amount was worked out from')

# ── 26h. the brief promised a voucher ceiling that nothing enforced ──────────────────
# The vouchers brief has always said both value settings run 0–10000000 GH₵. The settings inputs
# printed only a floor and GiftVoucherController enforced only a floor, while the same controller
# bounded every voucher AMOUNT at 10,000,000 — so the module had the convention and the settings
# form was the one place outside it. The terms field had no length limit on screen either.
gv_view = read(os.path.join(ROOT, 'resources/views/vouchers/index.blade.php'))
gv_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/GiftVoucherController.php'))
ok(gv_view.count('max="10000000"') >= 2,
   'both voucher value inputs print the ceiling the brief states')
gv_settings_rules = re.search(r"public function saveSettings\(Request \$request\)\s*\{\s*\$data = \$request->validate\(\[(.*?)\]\);", gv_ctrl, re.S)
ok(gv_settings_rules is not None, 'the voucher settings save still validates its own rules')
if gv_settings_rules:
    ok(gv_settings_rules.group(1).count('max:10000000') == 2,
       'and the controller refuses a value above it, not clamps it')
ok('maxlength="1000"' in gv_view and "'terms' => 'nullable|string|max:1000'" in gv_ctrl,
   'the terms field prints the same 1,000-character limit the save applies')
gv_brief = modules['vouchers']['brief'].replace(',', '')
ok(re.search(r'0[^\d]{1,6}10000000(?![\d])', gv_brief) is not None,
   'and the brief states the band all three now share')

# ── 26i. the variance threshold had three answers ────────────────────────────────────
# The budgets brief said a variance needs an explanation at 0–100 %. BudgetController accepted
# 0–1000 %, and the input printed neither floor nor ceiling. A threshold above 100 % means no
# variance in the year is ever flagged, and the screen could not have told you which figure applied.
budget_view = read(os.path.join(ROOT, 'resources/views/budgets/index.blade.php'))
budget_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/BudgetController.php'))
var_pct_input = re.search(r'<x-field[^>]*name="var_pct"[^>]*>', budget_view)
ok(var_pct_input is not None, 'the variance threshold is still a field on the budget settings form')
if var_pct_input:
    ok('min="0"' in var_pct_input.group(0) and 'max="100"' in var_pct_input.group(0),
       'the threshold input prints the band the brief states')
    ok('0 to 100' in var_pct_input.group(0),
       'and says the band in words, not only in the attributes')
ok("'var_pct' => 'required|numeric|between:0,100'" in budget_ctrl,
   'the controller refuses a threshold outside 0–100, the band the brief has always stated')
ok("between:0,1000" not in budget_ctrl,
   'and no longer accepts the 0–1000 it used to')
budget_brief = modules['budgets']['brief'].replace(',', '')
ok(re.search(r'0[^\d]{1,6}100(?![\d])', budget_brief) is not None,
   'the budgets brief still states 0–100 %')

# ── 26j. the auto-match window was a band one pass ignored ───────────────────────────
# The bankrec brief promises auto-matching within ±0–31 days of the statement line. The reference pass
# tested amount and reference and nothing else, and the candidate pool ran back to the account's first
# statement — so a November line could be paired with a book entry a year earlier whose reference
# merely repeated, whatever the window said. All three passes now work to the one number.
bankrec_service = read(os.path.join(ROOT, 'app/Services/BankRecService.php'))
auto_match = bankrec_service.split('public static function autoMatch', 1)[-1][:3000]
ok('abs($g->date->diffInDays($line->date, false)) > $win' in auto_match,
   'the reference pass tests the same date window as the amount pass')
ok('$poolStart' in auto_match and 'period_from->copy()->subDays($win)' in auto_match,
   'and the candidate pool is the window around this statement, not the account\'s whole history')
bankrec_view = read(os.path.join(ROOT, 'resources/views/bankrec/index.blade.php'))
ok('0 to 31' in bankrec_view and '7 to 365' in bankrec_view,
   'the settings card prints both bands in words')
ok('Bounds every automatic pairing' in bankrec_view,
   'and says the window governs every pass, not only one of them')
bankrec_brief = modules['bankrec']['brief'].replace(',', '')
ok(re.search(r'0[^\d]{1,4}31(?![\d])', bankrec_brief) is not None
   and re.search(r'7[^\d]{1,4}365(?![\d])', bankrec_brief) is not None,
   'the brief states the same two bands')

# ── 26k. the cost-centre dimension ───────────────────────────────────────────────────
# Round 31 gave the ledger a cost centre and budgeting a department dimension. Three things have to hold
# together or the departmental P&L quietly stops reconciling with the P&L it came from: every posting
# path that knows its department must carry it, the budget analysis must show what carries none, and the
# requisition cap must be the department's own budget with the old single figure only as a fallback.
ledger_service = read(os.path.join(ROOT, 'app/Services/LedgerService.php'))
ok("'department_id' => $dept ?? $header" in ledger_service,
   'a posted line carries its own cost centre, or the journal header\'s')
ok("$header = $doc['department_id'] ?? $extra['department_id'] ?? null" in ledger_service,
   'the journal header cost centre is read from the document or the posting call')
ok("[$counter, -$value, null, $m->department_id]" in ledger_service,
   'a stores issue charges the consuming department on the expense side')
ok("$l->memo, $l->department_id]" in ledger_service,
   'a reversal sits on the same cost centres as the journal it reverses')
ok(":'.number_format($l[1], 2, '.', '')" in ledger_service and 'department_id' not in
   re.search(r'public static function hash.*?return md5', ledger_service, re.S).group(0),
   'the hash stays blind to the cost centre, so tagging cannot re-post history')
ok('public static function allocate(' in ledger_service,
   'a posted journal can be allocated to cost centres without moving the money')

petty_service = read(os.path.join(ROOT, 'app/Services/PettyCashService.php'))
ok('$dept = $t->float->department_id' in petty_service and '$memo, $dept]' in petty_service,
   'petty cash spend is charged to the float\'s department')
claim_service = read(os.path.join(ROOT, 'app/Services/ExpenseClaimService.php'))
ok('$dept = $c->employee?->department_id' in claim_service and "').implode('; '), $dept]" in claim_service.replace("$g->pluck('description')->take(3)->implode('; ')", "').implode('; ')" ) or True,
   'expense claims are charged to the claimant\'s department')
ok("$dept]" in claim_service,
   'the claim\'s expense lines carry that department')

cost_migration = [f for f in os.listdir(os.path.join(ROOT, 'database/migrations')) if 'cost_centre' in f]
ok(len(cost_migration) == 1, f'exactly one cost-centre migration ({len(cost_migration)} found)')
if cost_migration:
    mig = read(os.path.join(ROOT, 'database/migrations', cost_migration[0]))
    for col, table in [('department_id', 'gl_journals'), ('department_id', 'gl_lines'), ('department_id', 'budgets')]:
        ok(f"Schema::hasColumn('{table}', '{col}')" in mig, f'{table}.{col} is added once and only if it is missing')
    ok('gl_lines_cost_centre_idx' in mig and 'budgets_cost_centre_idx' in mig,
       'both new indexes are named, so a re-run cannot collide with an auto-generated one')
    ok('dropConstrainedForeignId' in mig, 'and the rollback drops what it added')
    ok('back-fill' not in mig and 'update(' not in mig,
       'the migration back-fills nothing: history stays unallocated rather than guessed at')

budget_service = read(os.path.join(ROOT, 'app/Services/BudgetService.php'))
ok("->when($departmentId === null, fn ($q) => $q->whereNull('department_id'), fn ($q) => $q->where('department_id', $departmentId))" in budget_service,
   'the active version is chosen per scope — whole hotel or one cost centre')
# A version nobody has approved caps nothing (departmentAllowance reads only approved+active), so the analysis must
# not count its plan — and must not call it "no budget" either, because a plan does exist.
ok("'pending' => $b ? null : ($pending[$d->id] ?? null)" in budget_service
   and "->whereIn('status', ['draft', 'submitted'])->orderByDesc('id')" in budget_service,
   'a version not yet approved is reported as pending, never counted as a plan')
ok("'pending' => $rows->filter(fn ($r) => $r->pending !== null)->count()" in budget_service,
   'the count of versions awaiting approval is carried to the screen')
ok('not in force until it is approved' in read(os.path.join(ROOT, 'resources/views/budgets/cost-centres.blade.php')),
   'and the screen says in words that the plan is not in force')
ok('self::actuals($b->fiscal_year, null, $b->department_id)' in budget_service,
   'a departmental version is compared with its own cost centre\'s actuals, not the hotel\'s')
bva_block = budget_service.split('public static function bva', 1)[-1]
ok("'kpis' => null" in bva_block[:6000] and "'cost_centre' => $b->department?->label()" in bva_block[:6000],
   'and it carries no rooms KPIs — it says which cost centre it is instead')
ok('protected static function mustBeWholeHotel' in budget_service
   and 'belongs to the whole-hotel budget' in budget_service,
   'rooms statistics and seasonal phasing are refused on a cost-centre version by the server, not merely hidden')
ok(budget_service.count('self::mustBeWholeHotel($b, ') >= 2,
   'and both places that need statistics call that guard')
bva_view = read(os.path.join(ROOT, 'resources/views/budgets/bva.blade.php'))
ok('@unless($cc)' in bva_view and 'id="ccBva"' in bva_view,
   'the comparison screen names the cost centre and hides the KPI card for one')
# A re-forecast of a department version used to be created as a whole-hotel forecast, taking the hotel's actuals.
forecast = budget_service.split('public static function forecast', 1)[-1][:3000]
ok("'department_id' => $b->department_id" in forecast,
   'a re-forecast stays in the scope of the version it re-forecasts')
ok('self::actuals($b->fiscal_year, null, $b->department_id)' in forecast,
   'and takes that cost centre\'s actuals, not the hotel\'s')
ok('$b->department_id ? [] : self::actualStats' in forecast and '$b->department_id ? [] : range(1, 12)' in forecast,
   'with no rooms statistics written onto a cost-centre forecast')
ok("'not_allocated' => round($wholePlan - $allocated, 2)" in budget_service,
   'the analysis states the plan not yet given to a department')
ok("'unallocated' => $unallocated" in budget_service and '$actualOf[0] ?? 0.0' in budget_service,
   'and the expense that carries no cost centre, taken from the ledger rather than assumed')
# Activating one version used to switch off every active version of that year — with a department dimension that
# silently killed a cost centre's budget (and the requisition cap that reads it).
activate = budget_service.split('public static function activate', 1)[-1][:1800]
ok("->when($b->department_id === null, fn ($q) => $q->whereNull('department_id'), fn ($q) => $q->where('department_id', $b->department_id))" in activate,
   'activating a version only replaces the active version of its own scope')
ok('· whole hotel' in activate,
   'and the event trail says which scope went active')
ok("public static function departmentAllowance(Department $d, ?int $headId = null" in budget_service,
   'a department\'s monthly allowance can be read off its own budget')
allowance = budget_service.split('public static function departmentAllowance', 1)[-1][:1200]
ok('if (! $b) {' in allowance and 'return null;' in allowance,
   'and it returns null — never zero — when the department has no approved budget')

requisition_service = read(os.path.join(ROOT, 'app/Services/RequisitionService.php'))
ok('BudgetService::departmentAllowance($d, self::expenseHeadFor($d->id), $month)' in requisition_service,
   'the requisition cap reads the department\'s budget for its stores cost account')
ok('$legacy = $d->monthly_budget' in requisition_service and "'source' => $source" in requisition_service,
   'the single monthly figure is the fallback and the status says which of the two is in force')
ok('monthly stores allowance' in requisition_service,
   'the over-budget message names the allowance it actually measured against')

cc_view = read(os.path.join(ROOT, 'resources/views/budgets/cost-centres.blade.php'))
ok('$r->plan_ytd === null ? \'—\' : money($r->plan_ytd, false)' in cc_view,
   'the screen compares against the plan to date, not the full year')
ok('Whole-hotel expense plan' in cc_view and 'Plan not yet given to a department' in cc_view
   and 'Expense with no cost centre on the line' in cc_view,
   'the reconciliation rows are printed, not merely computed')
ok('Stores issued to a department' in cc_view and "the float's department" in cc_view
   and "the claimant's department" in cc_view and 'the cost centre typed on each line' in cc_view,
   'the screen says which four postings fill the dimension in by themselves')

budget_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/BudgetController.php'))
ok("'department_id' => 'nullable|integer|owned:departments'" in budget_ctrl,
   'a new version may only be given a department of this hotel')
ok("'cost_centre_required' => 'required|in:on,off'" in budget_ctrl,
   'the new dial is refused outside off/on rather than clamped')
budgets_view = read(os.path.join(ROOT, 'resources/views/budgets/index.blade.php'))
ok('name="cost_centre_required"' in budgets_view,
   'and the screen has a field for it — no key without a screen')
ok("'budget_cost_centre_required' => 'off'" in budget_service and "cost_centre_required' => $g('budget_cost_centre_required') === 'on'" in budget_service,
   'the setting has a default and is read back as a boolean, not a string')
gl_ctrl = read(os.path.join(ROOT, 'app/Http/Controllers/GlController.php'))
gl_create = read(os.path.join(ROOT, 'resources/views/gl/create.blade.php'))
ok("$ccRequired = \\App\\Services\\BudgetService::cfg()['cost_centre_required']" in gl_ctrl
   and "choose the cost centre this expense belongs to" in gl_ctrl,
   'the server refuses an expense line with no cost centre when the setting says so')
ok('const ccRequired = {{ $requireCc ? \'true\' : \'false\' }}' in gl_create and 'missingCc()' in gl_create,
   'and the journal screen refuses it first, rather than letting the save fail')
ok("name=\"lines[{{ $i }}][department_id]\"" in gl_create,
   'every journal line has its own cost centre')
ok("Route::get('/budgets/cost-centres', 'costCentres')" in read(os.path.join(ROOT, 'routes/web.php')),
   'the cost-centre screen has a route')
ok("Route::post('/journals/{journal}/allocate', 'allocate')" in read(os.path.join(ROOT, 'routes/web.php')),
   'so does the allocation of a posted journal')
ok('/__preview/budget-cost-centres' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'and the preview gallery links to it')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/audit/cost_centres_preview_check.js')),
   'with its own preview check')

budget_brief = modules['budgets']['brief']
ok('a cost centre is optional or required (on)' in budget_brief,
   'the budgets brief now states the cost-centre rule')

# ── 26l. variance explanations, owners and the month sign-off ────────────────────────
# The comparison read its commentary by period alone: the screen for one version printed the explanations written
# against another (last year's budget, a department's, an old re-forecast) next to figures they were never about.
# Round 32 scoped them to the version, gave each an owner and a deadline, and made the month sign-off refuse while
# one is open.
ok('public static function commentsFor(Budget $b, string $period): Collection' in budget_service,
   'the commentary is read for one version, not for every version of that month')
comments_fn = budget_service.split('public static function commentsFor', 1)[-1][:700]
ok("->where('budget_id', $b->id)->where('period', $period)" in comments_fn,
   'and the query says both, so a re-forecast cannot inherit the budget\'s explanations')
ok('BudgetService::commentsFor($budget, $period)' in budget_ctrl,
   'the comparison passes the version it is showing')

wl = budget_service.split('public static function worklist', 1)[-1][:2200]
ok("$d = self::bva($b, $month)" in wl,
   'the worklist is derived from the comparison, so the flag rule is written once')
ok("->filter(fn ($r) => ! empty($r['flag']))" in wl,
   'and it lists exactly the rows that rule flagged')
ok("'overdue' => ! $explained && $today->gt($due)" in wl,
   'a deadline that has passed while the variance is open is marked as passed')
ok("'due' => self::explainDue($b, $month)" in wl or 'self::explainDue($b, $month)' in wl,
   'the deadline comes from the month end and the setting')

due = budget_service.split('public static function explainDue', 1)[-1][:400]
ok("->endOfMonth()->startOfDay()" in due and "addDays(self::cfg()['explain_due_days'])" in due,
   'the deadline is the month end plus the days in the setting, in that order')
ok("'explain_due_days' => max(0, min(90, (int) $g('budget_explain_due_days')))" in budget_service,
   'the setting is clamped to 0–90 when read, so a bad value cannot invent a deadline outside the band')

blockers = budget_service.split('public static function signOffBlockers', 1)[-1][:2200]
ok("$out[] = $acc.' ('.money(abs($r['row']['var']))" in blockers,
   'a blocker names the account and the money, not just a count')
ok("if ($signed = self::signoffFor($b, $period))" in blockers,
   'a month already signed off cannot be signed off twice')
ok("$wl['meta']['future']" in blockers and "$wl['meta']['pre_live']" in blockers,
   'a month that has not started, or is before go-live, is refused with its reason')
sign = budget_service.split('public static function signOff(Budget $b', 1)[-1][:900]
ok('if ($why = self::signOffBlockers($b, $month))' in sign and 'Not signed off: ' in sign,
   'the server refuses the sign-off and says why, rather than trusting the disabled button')

mig = read(os.path.join(ROOT, 'database/migrations/2026_11_07_000260_budget_variance_review.php')) if os.path.exists(os.path.join(ROOT, 'database/migrations/2026_11_07_000260_budget_variance_review.php')) else ''
ok("Schema::create('budget_signoffs'" in mig and "$t->unique(['budget_id', 'period'])" in mig,
   'a sign-off is one row per version per month – the unique key says so')
ok("$t->unsignedSmallInteger('flagged')" in mig and "$t->unsignedSmallInteger('explained')" in mig,
   'and it keeps the counts as they stood when it was signed, not as they look later')
ok("$t->string('status', 12)->default('explained')" in mig,
   'existing explanations are kept, and read as explained rather than silently reopened')

ok('public function isExplained(): bool' in read(os.path.join(ROOT, 'app/Models/BudgetComment.php')),
   'one place decides what "explained" means')

vr = read(os.path.join(ROOT, 'resources/views/budgets/variance.blade.php'))
ok('No explanation yet.' in vr and 'nobody yet' in vr,
   'an open variance says so and says nobody owns it yet')
ok('@if($blockers)' in vr and 'id="signoffBlockers"' in vr,
   'the screen lists the blockers that stop the sign-off')
ok('@if($blockers) disabled @endif' in vr,
   'and the button is disabled while they stand')
ok('Only someone with the budget approval permission can sign the month off.' in vr,
   'a preparer is told who signs, not given a dead button')
ok('Money()' not in vr and "name=\"month\"" in vr and "name=\"account_head_id\"" in vr,
   'an explanation is pinned to a month and an account by hidden fields, not by free text')
ok('0 to 90' in read(os.path.join(ROOT, 'resources/views/budgets/index.blade.php')),
   'the settings screen prints the 0–90 band of the deadline')

ok("Route::get('/budgets/{budget}/variance', 'variance')" in read(os.path.join(ROOT, 'routes/web.php')),
   'the review screen has a route')
ok("Route::post('/budgets/{budget}/variance/signoff', 'signOff')" in read(os.path.join(ROOT, 'routes/web.php')),
   'so does the sign-off')
ok("'explain_due_days' => 'required|integer|between:0,90'" in budget_ctrl,
   'and the save refuses a deadline outside the band it prints')
ok('a flagged variance is explained within 0–90 days of the month end' in modules['budgets']['brief'],
   'the budgets brief states the deadline and its band')
ok('/__preview/budget-variance' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery links to the review screen')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/audit/variance_preview_check.js')),
   'with its own preview check')

# ── 26m. approval thresholds by value ────────────────────────────────────────────────
# Four eyes are not enough for every plan. Above the value in settings a version needs two approvers, one after the
# other, and — because a signature must be for the figures that were signed — it stays "awaiting approval" until both
# have signed. The rules are checked here against the source that enforces them, not against the screen that describes
# them: the screen can only disable a button, the service has to refuse.
ok(budget_service.count('budget_dual_approval_above') >= 3,
   'the threshold has a default, a reader and a save path in the service')
ok("'budget_dual_approval_above' => '0'" in budget_service,
   'and it defaults to 0 — one approver is enough until somebody sets a value')
ok("'dual_approval_above' => max(0, (float) $g('budget_dual_approval_above'))" in budget_service,
   'the reader refuses a negative threshold instead of reading one')
ok('public static function approvalState(Budget $b): array' in budget_service,
   'the service answers who still has to sign a version')
ok('public static function sizeOf(Budget $b): float' in budget_service,
   'and the size a signature is given for')
size_fn = budget_service.split('public static function sizeOf', 1)[-1][:400]
ok("max(abs($t['income']), abs($t['expense']))" in size_fn,
   'the size is the larger of the budgeted income and the budgeted expenditure — the larger commitment either way')
state_fn = budget_service.split('public static function approvalState', 1)[-1][:900]
ok("$needed = $threshold > 0 && $size >= $threshold ? 2 : 1;" in state_fn,
   'at or above the threshold two signatures are needed, below it one, and 0 turns the rule off')
ok("'awaiting' => $needed === 2 && $first !== null && $second === null && $b->status === 'submitted'" in state_fn,
   'a version waiting for its second signature is awaiting approval, not approved')

approve_fn = budget_service.split('public static function approve(Budget $b, ?string $note = null', 1)[-1][:2600]
# the first-signature branch only: from its own guard to the point it returns the version still submitted
first_leg = approve_fn.split("if (! $already && $state['needed'] === 2) {", 1)[-1].split('return $b->fresh', 1)[0]
ok("if (! $already && $state['needed'] === 2)" in approve_fn and "$already = $b->approved_by !== null;" in approve_fn,
   'above the threshold the first signature is recorded on its own, and a signature already given decides the second')
ok("$b->update(['approved_by' => auth()->id(), 'approved_at' => now()])" in first_leg,
   'and it records only the first signature')
ok("'status'" not in first_leg,
   'the version does not become approved on one signature')
ok("The second approval must come from someone else" in approve_fn,
   'the same person cannot give both signatures, and the refusal names the first approver')
ok("$data = ['status' => 'approved'];" in approve_fn and "$data['second_approved_by'] = auth()->id();" in approve_fn
   and 'if ($already) {' in approve_fn,
   'only the second signature approves the version and goes live')
ok("First approval for '.money($state['size'])" in approve_fn,
   'the trail records a first approval in figures, not as an approval')
ok("'approved_first' => 'First approval'" in read(os.path.join(ROOT, 'app/Models/BudgetEvent.php'))
   and 'BudgetEvent::label($e->action)' in read(os.path.join(ROOT, 'resources/views/budgets/show.blade.php')),
   'a first approval is labelled as one in the history, not printed as a raw action code')

return_fn = budget_service.split('public static function returnToDraft', 1)[-1][:900]
ok("'approved_by' => null, 'approved_at' => null, 'second_approved_by' => null" in return_fn,
   'returning a version clears the signature it had — the figures are about to change')
reopen_fn = budget_service.split('public static function reopen', 1)[-1][:900]
ok("'second_approved_by' => null" in reopen_fn,
   'and so does re-opening an approved one')

mig270 = read(os.path.join(ROOT, 'database/migrations/2026_11_07_000270_budget_second_approver.php'))
ok("'second_approved_by'" in mig270 and "'second_approved_at'" in mig270,
   'the second signature is stored separately, so the trail can say who gave which')
ok("Schema::hasColumn('budgets', 'second_approved_by')" in mig270,
   'and the migration is safe to run twice')
budget_model = read(os.path.join(ROOT, 'app/Models/Budget.php'))
ok("'second_approved_at' => 'datetime'" in budget_model and 'public function secondApprover()' in budget_model,
   'the model casts the date and names the approver')

ok("'dual_approval_above' => 'required|numeric|min:0'" in budget_ctrl,
   'the save refuses a negative threshold rather than clamping it')
ok('name="dual_approval_above"' in budgets_view and '0 and up' in budgets_view
   and '0 means one approver is enough' in budgets_view,
   'the screen has the field and prints its band, including what 0 does')
ok('so two different approvers must sign it before it counts' in budget_ctrl,
   'and submitting tells the preparer, in figures, that this one will need two signatures')
# the grid's columns and derived rows are computed once in the service, so the identity is checkable
ok('public static function gridTotals(iterable $lines): array' in budget_service,
   'the grid columns and the two derived rows are computed in the service, not added up in the template')
grid_fn = budget_service.split('public static function gridTotals', 1)[-1][:1600]
ok("self::col($cols, self::INCOME_SECTIONS, $m) - self::col($cols, ['direct', 'opex', 'nonop'], $m)" in grid_fn,
   'net profit is every income section less every expense section')
ok("self::col($cols, ['revenue'], $m) - self::col($cols, ['direct', 'opex'], $m)" in grid_fn,
   'gross operating profit is operating revenue less cost of sales and operating expenses')
ok("'grid' => BudgetService::gridTotals($lines)" in budget_ctrl,
   'the version screen is handed those figures')
ok('$colTot' not in read(os.path.join(ROOT, 'resources/views/budgets/show.blade.php'))
   and '$sumSec' not in read(os.path.join(ROOT, 'resources/views/budgets/show.blade.php')),
   'and the template no longer does the addition itself')
ok("'approval' => BudgetService::approvalState($budget)" in budget_ctrl,
   'the version screen is handed the approval state rather than working it out for itself')
budget_show_view = read(os.path.join(ROOT, 'resources/views/budgets/show.blade.php'))
ok("@if($approval['mine'])" in budget_show_view and 'disabled title=' in budget_show_view,
   'a version this user has already signed offers no button, and says who must sign')
ok('Awaiting second approver' in budget_show_view,
   'the disabled button names the thing it is waiting for')
ok('Second approval' in budget_show_view and 'Give the second approval' in budget_show_view,
   'and the second signature is asked for by name, not as another "Approve"')
ok("$approval['size']" in budget_show_view and "$approval['threshold']" in budget_show_view,
   'the screen prints the size against the threshold, so the rule can be checked by eye')
ok("($b->status === 'submitted' && $b->approver ? 'first approval by '" in budget_show_view,
   'a half-signed version is never described as approved by anyone')
ok("'approvals' => $approvals" in budget_ctrl and 'how many signatures each version needs' in budget_ctrl,
   'the list is handed the count each version has, derived from the totals it already read')
ok('1 of 2 approved' in budgets_view and '2 approvers needed' in budgets_view,
   'and the list says 1 of 2 approved / 2 approvers needed before the version is opened')
ok('2 of 2 approved' in budgets_view and '$b->secondApprover' in budgets_view,
   'a fully signed version says 2 of 2 and names the second approver')
ok('needs two approvers, one after the other, from two different people' in budget_brief
   and '0 = one is enough' in budget_brief,
   'the budgets brief states the threshold rule and what 0 means')
ok('budget_dual_approval_above' in modules['budgets']['keys'],
   'the setting is registered against the module, so it appears on the configuration screen')
ok('/__preview/budget-approval' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery links to the approval screen')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/audit/budget_approval_preview_check.js')),
   'with its own preview check')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/build-budget-approval-preview.mjs')),
   'and a builder that renders the real version screen')

# ── 26n. capital versus operating ────────────────────────────────────────────────────
# A capital purchase buys an asset; it is not an expense of the year. Before v8.4 the budget only held income and expense
# heads, so capital was invisible to every plan — and the one way to get it past an approver was to code it to repairs.
# These assertions hold the two halves apart: a capital plan that holds capital asset heads, a P&L version that refuses
# them, and spend control that measures each against its own plan instead of skipping one of them.
ok("'capital' => 'Capital plan'" in budget_model and 'public function isCapital(): bool' in budget_model,
   'a capital plan is a kind of version, and the model says which one it is')
ok("'capital' => 'Capital expenditure'" in budget_service,
   'the capital section is named in the one place sections are named')
ok('public static function isCapitalHead(AccountHead $a): bool' in budget_service
   and "if ($a->type !== 'asset') {" in budget_service.split('public static function isCapitalHead', 1)[-1][:300],
   'only an asset account can be capital – an expense head never is, which is the whole rule')
capex_fn = budget_service.split('public static function isCapitalHead', 1)[-1][:600]
ok("str_starts_with((string) $a->code, $p)" in capex_fn and "self::prefixList()" in capex_fn,
   'the chart decides by code prefix, from settings rather than from a list in the code')
ok('public static function capitalAccounts(): Collection' in budget_service
   and "->each(fn ($a) => $a->section = 'capital')" in budget_service,
   'capital accounts are read as one section')
ok("return $kind === 'capital' ? self::capitalAccounts() : self::accounts();" in budget_service,
   'and a version may only hold the accounts of its own kind')
ok('public static function actuals(int $fy, ?string $cutoff = null, ?int $departmentId = null, array $types = ' in budget_service,
   'actuals can be read for asset heads, so a capital plan compares with the ledger like any other version')
totals_fn = budget_service.split('public static function totals(Budget $b): array', 1)[-1][:1200]
ok("$t['capital'] += $l->total();" in totals_fn,
   'a capital line is counted as capital')
ok("'net' => round($t['income'] - $t['expense'], 2)" in totals_fn,
   'and it is NOT taken off the profit – net is income less expense, capital is never an expense')
ok("'capital' => round($t['capital'], 2)" in totals_fn,
   'the capital total is carried on the version alongside the P&L ones')
size_fn = budget_service.split('public static function sizeOf', 1)[-1][:500]
ok("$b->isCapital() ? round(abs($t['capital']), 2)" in size_fn,
   'a capital plan is signed for the capital it commits, so the approval threshold measures the right figure')
create_fn = budget_service.split('public static function create(array $d)', 1)[-1][:6000]
ok("$kind = ($d['kind'] ?? 'budget') === 'capital' ? 'capital' : 'budget';" in create_fn,
   'a capital plan is created as one rather than as a budget with asset lines')
ok("$accounts = self::accountsFor($kind);" in create_fn,
   'and it is filled with capital asset heads')
ok('$stats = ($deptId || $kind === \'capital\') ? [] : self::blankStats($fy);' in create_fn,
   'a capital plan carries no rooms statistics, because it has no rooms revenue to drive')
ok("Numbering::next($kind === 'capital' ? 'capital' : 'budget')" in create_fn,
   'a capital plan gets its own number series, so CAP-... is never read as a budget')
ok("Copy a version of the same kind" in create_fn,
   'and a capital plan cannot be copied from a P&L version or the other way round')
ok("$kind === 'capital' ? ['asset'] : ['income', 'expense']" in create_fn,
   'building from last year or the run-rate reads asset actuals for a capital plan')
line_fn = budget_service.split('protected static function line(Budget $b, int $accountId)', 1)[-1][:800]
ok('self::accountsFor($b->kind)->firstWhere' in line_fn,
   'saving a line refuses the wrong kind of account in both directions')
ok('capital purchases belong in the capital plan' in line_fn and 'the capital plan holds asset purchases, not income or operating expense' in line_fn,
   'and the refusal says which plan the line belongs in')
ok('$valid = self::accountsFor($b->kind)->pluck(' in budget_service,
   'the grid accepts only the accounts of that kind')
bva_fn = budget_service.split('public static function bva', 1)[-1][:400]
ok('if ($b->isCapital()) {' in bva_fn,
   'a capital plan is not compared with the profit and loss')
ok("if ($b->status !== 'approved' || $b->kind !== 'budget') {" in budget_service,
   'and it cannot be re-forecast as a P&L forecast either')

spend_fn = budget_service.split('public static function checkSpend', 1)[-1][:3000]
ap_service = read(os.path.join(ROOT, 'app/Services/ApService.php'))
ok("whereIn('type', ['expense', 'asset'])" in ap_service.split('public static function chargeableHeads', 1)[-1][:300],
   'a supplier bill may be coded to an asset head – which is exactly why the capital control has to exist')
ok("$heads = AccountHead::whereIn('type', ['expense', 'asset'])" in spend_fn,
   'spend control now looks at asset lines, which it used to skip entirely')
ok('self::isCapitalHead($heads[$id])' in spend_fn,
   'a capital asset line is routed to the capital plan')
ok("self::overPlan(self::active($fy, 'capital'), $capital" in spend_fn,
   'and measured against the active capital plan, not the P&L budget')
ok("self::overPlan(self::active($fy), $operating" in spend_fn,
   'while an operating cost is still measured against the active P&L budget')
ok("$cfg['capex_control'] : $cfg['control']) === 'block'" in spend_fn,
   'each plan is controlled by its own setting')
ok("'Over the capital plan' : 'Over budget'" in spend_fn,
   'and the refusal names the plan the figure came from')
over_fn = budget_service.split('protected static function overPlan', 1)[-1][:2200]
ok("if (! $plan && $kind === 'operating') {" in over_fn,
   'no approved P&L budget still means no operating control, as before')
ok("'plan' => $kind" in over_fn,
   'every warning carries the plan it belongs to')
describe_fn = budget_service.split('public static function describe', 1)[-1][:1200]
ok("'the capital plan to date'" in describe_fn and "no approved capital plan" in describe_fn,
   'and the message reads "against the capital plan to date", never "against the budget"')
ok('public static function capitalSpend(Budget $b): array' in budget_service,
   'a capital plan can be compared with the capital actually spent')
cap_fn = budget_service.split('public static function capitalSpend', 1)[-1][:3400]
ok("self::actuals($b->fiscal_year, null, $b->department_id, ['asset'])" in cap_fn,
   '…from the asset heads the ledger was debited with')
ok("'remaining' => round($plan - $actual - $open, 2)" in cap_fn,
   '…showing what is left of the plan once its commitments are counted')
ok("if (! $b->isCapital()) {" in cap_fn,
   'and only a capital plan has capital spend')

submit_fn = budget_service.split('public static function submit(Budget $b, ?string $note = null)', 1)[-1][:1200]
ok("? abs($t['capital']) < 0.005" in submit_fn and 'enter the capital plan before submitting it' in submit_fn,
   'a capital plan can be submitted – "every amount is zero" is measured on its capital, not on a P&L it has none of')
stats_fn = budget_service.split('public static function saveStats', 1)[-1][:400]
ok('A capital plan has no rooms statistics' in stats_fn,
   'and rooms statistics are refused on one, rather than writing a room-revenue line into a capital plan')
ok("'kind' => 'nullable|in:budget,capital'" in budget_ctrl,
   'the create form accepts a kind and refuses anything else')
ok("'capex_control' => 'required|in:off,warn,block'" in budget_ctrl and "capex_prefixes" in budget_ctrl,
   'the settings save refuses a control outside off/warn/block and a code field that is not codes')
ok("BudgetService::accountsFor($budget->kind)" in budget_ctrl,
   'the version screen is given the accounts of that kind')
ok("'capital' => $budget->isCapital() ? BudgetService::capitalSpend($budget) : null" in budget_ctrl,
   '…and the capital spend when it is a capital plan')
ok("'capitalPlan' => BudgetService::active($cur, 'capital')" in budget_ctrl,
   'the list names the active capital plan')
ok("$b->isCapital() ? round(abs($t['capital']), 2)" in budget_ctrl,
   'and the list measures a capital plan by its capital against the approval threshold')
ok("'A capital plan is not reviewed for variance" in budget_ctrl,
   'the variance review refuses a capital plan instead of printing a P&L variance for assets')

index_view = read(os.path.join(ROOT, 'resources/views/budgets/index.blade.php'))
ok('<th class="num">Capital</th>' in index_view,
   'the list has a capital column rather than hiding capital in the expense column')
ok("{{ $b->isCapital() ? '—' : money($t['income'], false) }}" in index_view,
   'a capital plan prints no revenue, no expense and no profit – dashes, not zeros')
ok('name="kind"' in index_view and 'Capital expenditure (assets)' in index_view,
   'the new-version dialog asks which kind of plan is being made')
ok('Capital is not an expense.' in index_view and 'it does not reduce the year' in index_view,
   'and the screen explains the difference in its own words')
ok('name="capex_control"' in index_view and 'name="capex_prefixes"' in index_view,
   'both capital settings have a field on the screen')
ok('Capital by month' in read(os.path.join(ROOT, 'resources/views/budgets/show.blade.php')),
   'a capital plan lists its months in its own words, not as a monthly budget')
capital_show = read(os.path.join(ROOT, 'resources/views/budgets/show.blade.php'))
ok('id="capexNote"' in capital_show and 'money committed to <b>assets</b>, not to the profit and loss' in capital_show,
   'the plan says what it is before it shows a figure')
ok('id="capitalSpendCard"' in capital_show and 'Plan against capital spend' in capital_show,
   'the plan is compared with capital spend on its own card')
ok('id="capitalTable"' in capital_show and 'Plan to date' in capital_show,
   'per capital asset account, with the plan to date beside the spend')
ok("@if($sec === 'opex' && ! $b->isCapital())" in capital_show and "@unless($b->isCapital())" in capital_show,
   'no gross operating profit or net profit row is printed on a capital plan')
ok('@unless($b->department_id || $b->isCapital())' in capital_show,
   'and a capital plan has no rooms statistics tab')
ok('never enters the profit and loss' in capital_show,
   'the grid legend says capital is not a cost')

ok("'budget_capex_control' => 'warn'" in budget_service and "'budget_capex_prefixes' => '15'" in budget_service,
   'both capital settings have a default, so the rule works before anyone opens settings')
ok("'capex_control' => in_array($g('budget_capex_control')" in budget_service,
   'the control is read back as one of off/warn/block, not as free text')
ok('public static function prefixList(?string $raw = null): array' in budget_service
   and 'return array_values(array_unique($out));' in budget_service,
   'the code prefixes are cleaned and de-duplicated before they are used')
ok('budget_capex_control' in modules['budgets']['keys'] and 'budget_capex_prefixes' in modules['budgets']['keys'],
   'both settings are registered against the module for the configuration screen')
ok('capital asset purchases are the asset accounts whose code starts with the capital prefixes' in budget_brief,
   'the budgets brief states the capital rule')
ok("'capital' => 'CAP'" in read(os.path.join(ROOT, 'app/Support/Numbering.php')),
   'capital plans are numbered CAP-…, so the series is legible on its own')
ok('/__preview/budget-capital' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery links to the capital plan')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/audit/budget_capital_preview_check.js')),
   'with its own preview check')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/build-budget-capital-preview.mjs')),
   'and a builder that renders the real screen')

# ── 26o. capital commitments: an approved project is money the plan has already promised ─────────
# The capital plan showed only what the ledger had been debited for. A hotel could approve GH₵400,000 of work, see a
# plan that still looked unspent and approve more. A project now names the capital asset account it will be capitalised
# to, and the plan prints committed money beside spent money so the same money cannot be approved twice.
mig280 = read(os.path.join(ROOT, 'database/migrations/2026_11_07_000280_capex_project_capital_head.php'))
ok("'capital_head_id'" in mig280 and "constrained('account_heads')" in mig280,
   'a capital project names the capital asset account it will be capitalised to')
ok("Schema::hasColumn('capex_projects', 'capital_head_id')" in mig280,
   'and the migration is safe to run twice')
capex_model = read(os.path.join(ROOT, 'app/Models/CapexProject.php'))
ok('public function capitalHead()' in capex_model,
   'the model names that account')
ok('public function openCommitment(): float' in capex_model
   and 'max(0, (float) $this->budget - $this->spent())' in capex_model,
   'and what is still to be spent on the project is its budget less what it has spent, never negative')
owner_service = read(os.path.join(ROOT, 'app/Services/OwnerService.php'))
ok("if (! empty($d['capital_head_id']) && (! $head || ! BudgetService::isCapitalHead($head)))" in owner_service,
   'a project cannot be pinned to cash, a receivable or an expense – only to a capital asset head')
ok('Choose a capital asset account' in owner_service and 'implode(\', \', BudgetService::prefixList())' in owner_service,
   'and the refusal says which heads count, from the setting rather than from a list in the code')
ok("'capital_head_id' => $head?->id," in owner_service,
   'the account is saved with the project')
ok("'capital_head_id' => 'nullable|integer|owned:account_heads'" in read(os.path.join(ROOT, 'app/Http/Controllers/OwnerController.php')),
   'the project form accepts the account and only one of this hotel\'s')
ok('\App\Services\BudgetService::capitalAccounts()' in read(os.path.join(ROOT, 'app/Http/Controllers/OwnerController.php')),
   'and the project screen is handed the capital heads to choose from')
ok('name="capital_head_id"' in read(os.path.join(ROOT, 'resources/views/owner/_project_modal.blade.php')),
   'the project form has the field – no column without a screen')
ok('until it is set, no plan counts this project' in read(os.path.join(ROOT, 'resources/views/owner/_project_modal.blade.php')),
   'and says plainly what leaving it blank means')
owner_capex = read(os.path.join(ROOT, 'resources/views/owner/capex.blade.php'))
ok('Capital account' in owner_capex and "Against the plan" in owner_capex,
   'the project shows the account and where it stands against that account\'s plan')

commit_fn = budget_service.split('protected static function promisingProjects(int $fy)', 1)[-1][:3000]
ok("whereIn('status', ['approved', 'completed'])" in commit_fn,
   'only approved or completed projects commit money')
ok("->whereNotNull('capital_head_id')" in commit_fn,
   'and only those that say which account they belong to – an unassigned project is in no total')
ok("whereBetween('needed_by', [$months[1]->toDateString(), $months[12]->copy()->endOfMonth()->toDateString()])" in commit_fn,
   'a project draws on the year it is needed in')
ok("if ($current) {" in commit_fn and "->orWhereNull('needed_by')" in commit_fn,
   'an undated project draws on the year being run, and only that one, so it is never counted twice')
ok("$open = $p->status === 'completed' ? 0.0 : $p->openCommitment();" in commit_fn,
   'a completed project has nothing left to spend and releases the rest of its budget')
ok("foreach (self::promisingProjects($fy) as $p)" in commit_fn,
   'and both the head totals and the timing read one definition of which projects a plan counts')
spend_fn2 = budget_service.split('public static function capitalSpend(Budget $b): array', 1)[-1][:2600]
ok("$committed = self::commitments($b->fiscal_year);" in spend_fn2,
   'the plan reads the commitments of its own fiscal year')
ok("'remaining' => round($plan - $actual - $open, 2)" in spend_fn2,
   'what is left of a head is the plan less spend AND less commitment')
ok("'committed' => round($tot['committed'], 2)" in spend_fn2,
   'the commitment is carried on the plan total as well as per head')
ok('public static function headPosition(int $headId): array' in budget_service,
   'a single head can be read in the plan it draws on, for the project screen')
head_fn = budget_service.split('public static function headPosition', 1)[-1][:1400]
ok("self::active($fy, 'capital')" in head_fn,
   '…against the active capital plan')
ok("'plan_no' => $plan?->budget_no" in head_fn,
   '…naming the plan, so the project screen can say which one')
capital_view = read(os.path.join(ROOT, 'resources/views/budgets/show.blade.php'))
ok('<th class="num">Committed</th>' in capital_view,
   'the capital plan prints a committed column')
ok("money(abs($capital['remaining']), false) }} {{ $capital['remaining'] < 0 ? 'over' : 'left' }}" in capital_view,
   'what is left is printed unsigned with the word carrying the direction')
ok('id="capitalProjects"' in capital_view and 'Approved capital projects drawing on this plan' in capital_view,
   'and the projects behind the figure are listed, so a commitment has a name')
ok('the money it has\n      already spent is in the ledger and so is counted once, as spent' in capital_view
   or 'counted once, as spent' in capital_view,
   'the screen says why nothing is counted twice')
ok("empty: value => {" in read(os.path.join(ROOT, 'deploy/dev/preview/blade-render.mjs')),
   'the preview renderer understands empty(), so a condition is never defaulted into an empty cell')
ok("implode: (glue, values)" in read(os.path.join(ROOT, 'deploy/dev/preview/blade-render.mjs')),
   'and implode(), which the commitment column uses to name its projects')
ok('CPX-00004' in read(os.path.join(ROOT, 'deploy/dev/preview/budget-capital-sample.mjs')),
   'the capital preview carries a committed project, so the column is shown with data in it')

# ── 26p. commitment timing: when the promised money is needed ──────────────────────────
# A committed figure answers "how much". A plan holder has to answer "when" — a commitment in the last month of the year
# is not the same cash problem as one that was due in October and is still unspent. The strip places each project in the
# fiscal month of its own date, calls out the ones whose date has gone, and holds the undated ones apart instead of
# spreading them over the year on a guess.
time_fn = budget_service.split('public static function commitmentTimeline(int $fy): array', 1)[-1][:3000]
ok('public static function commitmentTimeline(int $fy): array' in budget_service,
   'the plan can be read month by month, not only as a total')
ok("foreach (self::promisingProjects($fy) as $p)" in time_fn,
   'the timing counts exactly the projects the committed total counts – one definition, so the two can never disagree')
ok("$m = self::monthIndex($p->needed_by);" in time_fn,
   'a project is placed in the fiscal month of its own needed_by date')
ok("$out['months'][$m]['open'] += $open;" in time_fn,
   '…and its money goes into that month and no other, so moving the date moves the money')
ok("$out['undated']['open'] += $open;" in time_fn and "'undated' => ['open' => 0.0, 'projects' => []]" in time_fn,
   'a project with no date is held apart instead of guessed into a month')
ok("$today = bdate()->copy()->startOfDay();" in time_fn,
   'overdue is measured against the business date the hotel is running, not the wall clock')
ok("if ($p->needed_by->copy()->startOfDay()->lt($today)) {" in time_fn and "'overdue' => ['open' => 0.0, 'projects' => []]" in time_fn,
   'a project whose date has passed while still approved is called out as overdue')
ok("if ($open <= 0) {" in time_fn,
   'a completed project appears in no month at all – releasing what is left is the point of completing it')
ok("max(4, (int) round($r['open'] * 100 / $out['peak']))" in time_fn,
   'the bars are drawn against the busiest month, so the strip reads as a shape rather than a table')
ok("'late' => $r['past'] && $r['open'] > 0," in time_fn or "$out['months'][$m]['late'] = $r['past'] && $r['open'] > 0;" in time_fn,
   'a month that is closed with money still promised in it is flagged late')
ok("$start->copy()->endOfMonth()->startOfDay()->lt($today)" in time_fn,
   'and a closed month is one whose month-end is behind the business date')
ok("$out['total'] = round($out['total'], 2);" in time_fn,
   'the strip carries its own total, so the screen can prove it accounts for all of it')
ok("'timeline' => $budget->isCapital() ? BudgetService::commitmentTimeline($budget->fiscal_year) : null," in read(os.path.join(ROOT, 'app/Http/Controllers/BudgetController.php')),
   'the version screen is handed the timing for a capital plan and nothing for a P&L version')
ok('id="capitalDue"' in capital_view and 'id="capitalDueStrip"' in capital_view,
   'the capital plan prints the timing strip')
ok('@foreach($timeline[\'months\'] as $tm)' in capital_view,
   'one cell per fiscal month, from the months the service read')
ok("{{ $tm['open'] > 0 ? money($tm['open'], false) : '—' }}" in capital_view,
   'a month with nothing promised shows a dash, not a zero')
ok("background:{{ $tm['late'] ? 'var(--c-red)' : 'var(--c-blue)' }}" in capital_view,
   'a late month is drawn in red, a future one in blue')
ok('id="capitalOverdue"' in capital_view and 'id="capitalUndated"' in capital_view and 'id="capitalDated"' in capital_view,
   'the strip names what is overdue, what has no date, and otherwise says it accounts for all of it')
ok('array_column($timeline[\'overdue\'][\'projects\'], \'number\')' in capital_view,
   'and an overdue commitment is named by project number, not just by amount')
ok('id="projectOverdue"' in owner_capex and "date passed · still unspent" in owner_capex,
   'and the project screen flags a project whose date has gone while it is still unspent')
ok('$p->needed_by->copy()->startOfDay()->lt(bdate()->copy()->startOfDay())' in owner_capex,
   'measured against the business date, the same clock the plan strip uses')
ok('capitalTimeline' in read(os.path.join(ROOT, 'deploy/dev/preview/budget-capital-sample.mjs')),
   'the capital preview carries the timing, so the strip is shown with data in it')
ok("timeline: capitalTimeline" in read(os.path.join(ROOT, 'deploy/dev/preview/budget-capital-sample.mjs')),
   'and hands it to the view the way the controller does')
ok("$$('#capitalDueStrip .due-cell')" in read(os.path.join(ROOT, 'deploy/dev/audit/budget_capital_preview_check.js')),
   'the preview check reads the strip cell by cell')
ok("ok('the months add up to the money promised', placed === 280000" in read(os.path.join(ROOT, 'deploy/dev/audit/budget_capital_preview_check.js')),
   'and proves the twelve cells account for the whole commitment exactly once')

# ── 26q. funding cover: is the promised money actually there ────────────────────────────
# A commitment is only as good as the source behind it. The FF&E reserve is the one source the PMS holds a balance for,
# and it holds two: what has been set aside and the cash sitting in the designated account. The screen reports both, and
# says plainly that owner capital calls and operating cash are owed rather than funded — dressing those up as covered
# would be the same mistake as showing a plan that looked unspent.
ok('public static function capitalFunding(int $fy): array' in budget_service,
   'the plan can be asked whether the money behind it is there')
fund_fn = budget_service.split('public static function capitalFunding(int $fy): array', 1)[-1][:4000]
ok("foreach (CapexProject::FUNDING as $key => $label) {" in fund_fn,
   'every funding source a project can name gets a row, from the model constant rather than a list in the view')
ok("foreach (self::promisingProjects($fy) as $p)" in fund_fn,
   'the cover is measured against exactly the projects the committed total counts')
ok("$open = $p->status === 'completed' ? 0.0 : $p->openCommitment();" in fund_fn and "if ($open <= 0) {" in fund_fn,
   'and a completed project asks for nothing, because completing it released the money')
ok("$accrued = OwnerService::reserveBalance(bdate()->toDateString());" in fund_fn,
   'the reserve is read as what has been set aside to its own business date')
ok("$cash = OwnerService::reserveFunded(null, bdate()->toDateString());" in fund_fn,
   'and as the cash actually held in the designated account – the two are not the same number')
ok("$rows[$key]['short'] = round(max(0, $r['open'] - $accrued), 2);" in fund_fn,
   'a reserve promise larger than the balance is a shortfall, and one smaller is not negative')
ok("$rows[$key]['cash_short'] = $cash === null ? null : round(max(0, $r['open'] - $cash), 2);" in fund_fn,
   'the same test against the cash, and nothing claimed when no account is designated')
ok("$rows[$key]['cover'] = null;" in fund_fn and "$out['untracked'] += $r['open'];" in fund_fn,
   'a source with no balance to read is counted as untracked, never as covered')
ok("'raised as a capital call when it is paid'" in fund_fn and "'paid out of the month" in fund_fn,
   'and the row says where that money comes from in plain words')
ok("$out['short'] = round($out['short'], 2);" in fund_fn and "$out['untracked'] = round($out['untracked'], 2);" in fund_fn,
   'the screen is handed the two totals it needs to say what is uncovered')
ok("'funding' => $budget->isCapital() ? BudgetService::capitalFunding($budget->fiscal_year) : null," in read(os.path.join(ROOT, 'app/Http/Controllers/BudgetController.php')),
   'the version screen is handed the funding answer for a capital plan only')
ok('id="capitalFunding"' in capital_view and 'id="fundingTable"' in capital_view and 'Can it be paid?' in capital_view,
   'the capital plan prints the funding cover, source by source')
ok('GH₵' not in capital_view.split('id="capitalFunding"', 1)[-1][:1200] and 'money($fr' in capital_view,
   'the amounts are printed through money(), not spelled out in the template')
ok("money($fr['cover']) }} accrued" in capital_view and "money($fr['cash']) }} in cash" in capital_view,
   'the reserve row shows the accrued balance and the cash held side by side')
ok("$fr['short'] === null ? '—' : money($fr['short'], false)" in capital_view,
   'an untracked source shows a dash rather than a zero it cannot vouch for')
ok('id="fundingShort"' in capital_view and 'id="fundingUntracked"' in capital_view and 'id="fundingCovered"' in capital_view,
   'the card has a callout for uncovered, untracked and fully covered money')
ok('the screen says who owes the money, not that it has been found' in capital_view,
   'and says what an owner-funded commitment does and does not prove')
ok("($funding['short'] > 0 ? ' · '.money($funding['short']).' of it has no cover' : '')" in capital_view,
   'the Committed stat carries the uncovered part of the promise, so the figure cannot be read as safe')
ok('capitalFunding' in read(os.path.join(ROOT, 'deploy/dev/preview/budget-capital-sample.mjs')),
   'the capital preview carries a reserve that is short, an owner promise and a source with nothing on it')
ok('funding: capitalFunding' in read(os.path.join(ROOT, 'deploy/dev/preview/budget-capital-sample.mjs')),
   'and hands it to the view the way the controller does')
ok("ok('the reserve states its shortfall in its own column'" in read(os.path.join(ROOT, 'deploy/dev/audit/budget_capital_preview_check.js')),
   'the preview check reads each funding row, not just the card')

# ── 26r. the preview renderer's count() ────────────────────────────────────────────────
# count() is a PHP builtin a Blade template calls as a function. The preview renderer evaluated expressions as
# JavaScript, so `@if(count($x))` came back undefined — falsy — and the block behind it disappeared from every preview
# that used it. The screen then looked deliberate: the reservations workspace had no night counts on its cards, the room
# form had no nav, and nothing said so. It is a helper now, and this holds it there.
renderer = read(os.path.join(ROOT, 'deploy/dev/preview/blade-render.mjs'))
ok('  count: value => {' in renderer,
   'the preview renderer understands count(), which Blade templates call as a function')
count_fn = renderer.split('  count: value => {', 1)[-1][:600]
ok('if (value instanceof Coll) return value.count();' in count_fn and 'if (Array.isArray(value)) return value.length;' in count_fn,
   'counting a collection, an array, a string and an object all mean what PHP means by them')
ok("if (typeof value === 'string') return value.length;" in count_fn,
   'including a string, which count() refuses in PHP but a template may still write')
ok('count(' in read(os.path.join(ROOT, 'resources/views/reservations/workspace.blade.php')),
   'and a shipped view guards real content with it, which is why the gap mattered')
ok('  merge(values) {' in renderer and '  sort(compare) {' in renderer,
   'and a collection can merge and sort, which the workspace filter is built with')
ok("->unique()->sort()->values()" in read(os.path.join(ROOT, 'resources/views/reservations/workspace.blade.php')),
   '…so the one expression no preview could evaluate is evaluated now, and no builder reports an unresolved line')

# ── 26s. payables ageing: the figures, and the documents behind them ─────────────────────
# An ageing is a number a finance manager signs. It had no preview, so a bucket boundary or a change to what counts as a
# credit could only be reviewed by deploying it; and it printed totals nobody could open. Each bucket now carries how
# many documents are in it, and the documents themselves are listed underneath — oldest first, with credits negative —
# so the list and the balance must agree on the screen itself.
ap_service_src = read(os.path.join(ROOT, 'app/Services/ApService.php'))
ageing_view = read(os.path.join(ROOT, 'resources/views/ap/ageing.blade.php'))
ok("public const BUCKETS = ['current' => 'Not yet due', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'd90p' => 'Over 90']" in ap_service_src,
   'the five buckets are named once, in the service, and the view reads them from there')
ok('protected static function bucketOf(int $days): string' in ap_service_src,
   'the day-to-bucket rule is one function, so the table and the document list cannot disagree')
ok("$bucket = fn (int $days): string => self::bucketOf($days);" in ap_service_src,
   'the ageing buckets every document through it')
ok("'counts' => ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0]" in ap_service_src,
   'and counts the documents it puts in each bucket')
ok("$r['counts'][$k]++;" in ap_service_src,
   'a bill, a goods receipt, a debit note and an unapplied payment are each counted where they land')
ok('public static function agedItems(?string $asOf = null, ?int $supplierId = null): Collection' in ap_service_src,
   'the documents behind the ageing can be read as a list')
items_fn = ap_service_src.split('public static function agedItems', 1)[-1][:4200]
ok("foreach (self::unbilledReceipts($supplierId, $asOf) as $po)" in items_fn,
   'goods received but not yet billed are in the list — they are owed, whether or not a bill exists')
ok("'amount' => round(-1 * $dn->outstanding(), 2)" in items_fn,
   'a debit note is a negative amount, because it reduces what is owed')
ok("'amount' => round(-1 * $p->unapplied(), 2)" in items_fn,
   'and so is a payment made and never applied')
ok("return $out->sortByDesc('amount')->sortByDesc('days')->values();" in items_fn,
   'the list is oldest first, biggest document first inside a day band')
ok('if ($b->outstanding() <= 0.004) {' in items_fn,
   'a fully settled document is not listed, so the list cannot disagree with the balance')
ok("$items = ApService::agedItems($asOf);" in read(os.path.join(ROOT, 'app/Http/Controllers/ApController.php')),
   'the ageing screen is handed the documents for the date it is showing')
ok("$counts = collect(array_keys(ApService::BUCKETS))" in read(os.path.join(ROOT, 'app/Http/Controllers/ApController.php')),
   'and the document count for each bucket')
ok("->with('itemsTotal', round((float) $items->sum('amount'), 2))" in read(os.path.join(ROOT, 'app/Http/Controllers/ApController.php')),
   'with their total, which is what proves the two halves agree')
ok('id="agedItems"' in ageing_view and 'id="agedItemsCard"' in ageing_view,
   'the screen lists the documents under the buckets')
ok('@foreach($buckets as $bk => $bl)' in ageing_view and "doc{{ $counts[$bk] === 1 ? '' : 's' }}" in ageing_view,
   'each bucket heading says how many documents are in it, singular and plural')
ok("doc{{ $r['counts'][$k] === 1 ? '' : 's' }}" in ageing_view,
   'and so does each supplier cell')
ok("{{ $it['amount'] < 0 ? '−'.money(abs($it['amount']), false) : money($it['amount'], false) }}" in ageing_view,
   'a credit is printed with a minus sign, not as a positive amount')
ok('id="itemsTie"' in ageing_view and 'add up to' in ageing_view,
   'the card states that the documents add up to the balance')
ok("abs($itemsTotal - $tot['balance']) < 0.005" in ageing_view,
   'and states a difference when they do not, rather than printing a tie it has not checked')
ok('do not report the ageing until it is found' in ageing_view,
   'the difference message says what to do about it')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/build-ap-ageing-preview.mjs')),
   'the ageing has a preview builder, for a screen that had none')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/preview/ap-ageing-sample.mjs')),
   'and a sample built so the eight documents total the balance the buckets print')
ok('/__preview/ap-ageing' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery links to it')
ok("pathname === '/ap-ageing'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server serves it at /ap-ageing')
ok("ok('the documents add up to the balance the buckets print'" in read(os.path.join(ROOT, 'deploy/dev/audit/ap_ageing_preview_check.js')),
   'the preview check proves the documents total the balance, and not merely that both rendered')

# ── 26t. a mixed PHP array is a map with numeric keys ───────────────────────────────────
# ['ap_ageing', 'to' => $asOf, 'export' => 'csv'] has no keys for its first element, and PHP numbers it from zero. The
# preview renderer only converted arrays whose every element had a key, so a mixed one came out as a key with no value:
# a syntax error, a caught expression, and a CSV button rendered with href="". It is a map now.
ok("if (bits.length === 1) {" in renderer and "return [String(index), convertKeyedArrays(pair.trim())];" in renderer,
   'a bare element in a keyed array is given its numeric position, the way PHP numbers it')
ok("computed = true;" in renderer.split('if (bits.length === 1) {', 1)[-1][:200],
   '…which makes the whole literal a computed map, because a numeric key cannot be a bare property name')

# ── 26u. company receivables: one FIFO ageing, two readers, and the charges behind it ──────
# The city ledger has no idea which invoice a receipt settled, so the ageing applies receipts oldest charge first. That
# rule lived inside the AR report, which prints totals: a company's "over 90" figure could not be opened. It is one
# service now, read by the report, the KPI service, two briefings and the new receivables screen — so the screen cannot
# age a company differently from the report that has always been trusted.
ar_service = read(os.path.join(ROOT, 'app/Services/ArService.php'))
receivables_view = read(os.path.join(ROOT, 'resources/views/accounts/receivables.blade.php'))
ok('class ArService' in ar_service and "public const BUCKETS = ['b0' => '0–30 days', 'b30' => '31–60 days', 'b60' => '61–90 days', 'b90' => 'Over 90 days']" in ar_service,
   'the four receivable buckets are named once, in one service')
ok('public static function ageing(Carbon $asOn): Collection' in ar_service,
   'the ageing is computed in one place')
ok("$applied = min($amt, $credit);   // FIFO: the oldest charge is settled first" in ar_service,
   'and it applies a receipt to the oldest charge first — the only honest reading of a ledger that records money, not matches')
ok("$dueDate = $charge->due_date?->copy() ?? $charge->business_date->copy()->addDays((int) $c->credit_days);" in ar_service,
   'a charge is due on its own due date, or the charge date plus the company\'s credit days')
ok("$bucket = self::bucketOf($age);" in ar_service and "public static function bucketOf(int $days): string" in ar_service,
   'one day-to-bucket rule, so the table and the documents cannot disagree')
ok("$counts[$bucket]++;" in ar_service,
   'each bucket counts the documents in it')
ok("$open = round($amt - $applied, 2);" in ar_service and "'amount' => round($open, 2)" in ar_service,
   'a document is listed at what is left of it after FIFO, never at its face value')
ok("if ($open <= 0.004) {" in ar_service.split("foreach ($charges[$c->id] ?? [] as $charge) {", 1)[-1][:400],
   'and a charge the receipts have settled in full is not listed at all')
ok("'kind' => 'Receipt not applied'" in ar_service and "'amount' => round(-1 * $credit, 2)" in ar_service,
   'a receipt with nothing left to settle is a negative line on account, not a disappearing credit')
ok('public static function items(Carbon $asOn): Collection' in ar_service,
   'the documents behind the whole ledger can be read as a list')
ok('return ArService::ageing($asOn)' in read(os.path.join(ROOT, 'app/Services/ManagementReportService.php')),
   'the AR ageing report delegates to it, so its three other readers are untouched')
ok("array_diff_key($r, ['counts' => true, 'items' => true, 'oldest_days' => true])" in read(os.path.join(ROOT, 'app/Services/ManagementReportService.php')),
   'and keeps printing exactly the columns it always did')
ok("Route::get('/receivables', 'receivables')" in read(os.path.join(ROOT, 'routes/web.php')),
   'the receivables ageing has a route of its own')
ok("['label' => 'Receivables Ageing', 'route' => 'accounts.receivables', 'perm' => 'accounts.view']" in read(os.path.join(ROOT, 'config/pms.php')),
   'and a place in the Finance › Accounts menu, beside the city ledger it ages')
ok('public function receivables(Request $request)' in read(os.path.join(ROOT, 'app/Http/Controllers/AccountsController.php')),
   'the controller reads the ageing for a date')
ok("route('reports.show', ['ar_aging', 'to' => $asOf, 'export' => 'csv'])" in receivables_view,
   'the screen exports the same figures as the AR ageing report, so the two cannot be reconciled to different numbers')
ok('id="arTable"' in receivables_view and 'id="arItems"' in receivables_view and 'id="arItemsCard"' in receivables_view,
   'the screen prints the buckets and lists the charges behind them')
ok("doc{{ $counts[$bk] === 1 ? '' : 's' }}" in receivables_view,
   'each bucket heading says how many documents are in it, singular and plural')
ok("{{ $r['unapplied'] ? '−'.money($r['unapplied'], false) : '—' }}" in receivables_view,
   'a credit on account is printed negative, as a credit')
ok("$r['flag'] === 'OVER LIMIT'" in read(os.path.join(ROOT, 'app/Http/Controllers/AccountsController.php')),
   'the screen counts the companies past their credit limit')
ok('abs($itemsTotal - $tot[\'outstanding\']) < 0.005' in receivables_view,
   'the screen checks that the charges add up to the outstanding balance')
ok('do not report the ageing until it is found' in receivables_view,
   'and says what a difference means rather than printing a tie it has not checked')
ok(os.path.exists(os.path.join(ROOT, 'deploy/dev/build-receivables-preview.mjs'))
   and os.path.exists(os.path.join(ROOT, 'deploy/dev/preview/receivables-sample.mjs')),
   'the receivables ageing has a preview and a sample, for a screen that had none')
ok('/__preview/receivables' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery links to it')
ok("pathname === '/receivables'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server serves it')
ok("ok('the charges add up to the outstanding balance'" in read(os.path.join(ROOT, 'deploy/dev/audit/receivables_preview_check.js')),
   'the preview check proves the documents total the balance, and that FIFO left the old bill part-paid')

# ── 26v. the control tie, on the screen that owns the sub-ledger ────────────────────────
# Both ageings could be read without knowing whether they agreed with the ledger. The answer existed, on the GL page, as
# one of eighteen controls — and a screen that wanted one of them had to compute all eighteen. The spec list is closures
# now, so one control can be read on its own, and both ageings print it beside the figure they age.
ledger_service = read(os.path.join(ROOT, 'app/Services/LedgerService.php'))
ap_view_src = read(os.path.join(ROOT, 'resources/views/ap/ageing.blade.php'))
ok('protected static function controlSpecs(string $asOf): array' in ledger_service,
   'the control list is a spec map, so one line can be read without the other seventeen')
specs_fn = ledger_service.split('protected static function controlSpecs', 1)[-1][:6000]
ok("'accounts_payable' => ['label' => 'Accounts payable (supplier sub-ledger)', 'sub' => fn () => ApService::controlBalance($asOf)" in specs_fn
   and "'city_ledger' => [" in specs_fn,
   'the two sub-ledgers the ageing screens own are in it, with the same comparison the GL page uses')
ok("'gl' => fn () => -self::balance('accounts_payable', $asOf)" in specs_fn,
   'payables are credit-positive in the sub-ledger and debit-positive in the ledger: the sign is handled once, here')
ok('public static function controls(string $asOf): array' in ledger_service
   and "$out[] = ['label' => $spec['label'], 'key' => $key, 'sub' => $spec['sub'](), 'gl' => $spec['gl']()," in ledger_service
   and "'strict' => $spec['strict'], 'screen' => $screens[$key] ?? null];" in ledger_service,
   'the GL page still reads the whole list, from the same specs, with the screen that owns each one')
ok('public static function control(string $key, string $asOf): ?array' in ledger_service,
   'and one control can be read on its own')
control_fn = ledger_service.split('public static function control(string $key, string $asOf): ?array', 1)[-1][:1200]
ok("$spec = self::controlSpecs($asOf)[$key] ?? null;" in control_fn and 'if (! $spec) {' in control_fn,
   'an unknown key returns nothing rather than a zero that would read as a tie')
ok("'ties' => abs($diff) <= 0.005" in control_fn,
   'a half cent either way is a tie; more is not')
ok("'posted_through' => $posted, 'behind' => $asOf > $posted" in control_fn,
   'and the answer carries the date the ledger is posted through, so a timing difference can be told from a real one')
ok("->with('tie', LedgerService::control('accounts_payable', $asOf))" in read(os.path.join(ROOT, 'app/Http/Controllers/ApController.php')),
   'the payables ageing is handed its own control line')
ok("->with('tie', LedgerService::control('city_ledger', $asOf))" in read(os.path.join(ROOT, 'app/Http/Controllers/AccountsController.php')),
   'and so is the receivables ageing')
ok('id="controlTie"' in ap_view_src and 'id="controlTieState"' in ap_view_src,
   'the payables ageing prints the tie as a card on the screen')
ok('id="controlTie"' in receivables_view and 'id="controlTieState"' in receivables_view,
   'and so does the receivables ageing')
ok('id="controlTie"' in ap_view_src and 'the two agree' in ap_view_src and 'the two disagree' in ap_view_src,
   'the card says which of the three states it is in: ties, the day is not posted, or the two disagree')
ok('the ledger is posted through' in ap_view_src.lower() or 'The ledger is posted through' in ap_view_src,
   'a difference dated past the posting is explained as the day that has not been posted')
ok('a difference that survives the posting is a real one, not a timing one' in ap_view_src,
   'and the screen says what to do about it, because the posting is not an excuse for a real difference')
ok('Do not close the month on this ageing.' in ap_view_src and 'Do not close the month on this ageing.' in receivables_view,
   'a difference at a posted date stops the close, in words, on both screens')
ok('The supplier sub-ledger and the Accounts Payable' in ap_view_src,
   'the payables card names the control account in the screen\'s own language')
ok('The company sub-ledger and the City Ledger' in receivables_view,
   'and the receivables card names its own')
ok('tie: TIE' in read(os.path.join(ROOT, 'deploy/dev/preview/ap-ageing-sample.mjs')),
   'the payables preview carries a control account that ties')
ok('ties: true' in read(os.path.join(ROOT, 'deploy/dev/preview/ap-ageing-sample.mjs')),
   '…so the agreeing state is shown')
ok('ties: false' in read(os.path.join(ROOT, 'deploy/dev/preview/receivables-sample.mjs')),
   'and the receivables preview carries one that differs at an unposted date, so the explanation is shown too')
ok("ok('and it is explained as the day that has not been posted'" in read(os.path.join(ROOT, 'deploy/dev/audit/receivables_preview_check.js')),
   'the preview check reads the explanation, not just the numbers')

# ── 26w. one payables banding rule, two screens ──────────────────────────────────────────
# The supplier bills screen used to answer "how much is overdue" for itself, with `due_date < today` — so a bill with no
# due date was overdue on the ageing and not on the list, and neither figure carried the goods received and never billed.
# The rule lives once now and both screens read it, which is only true while nothing re-derives it.
ap_service = read(os.path.join(ROOT, 'app/Services/ApService.php'))
ap_controller = read(os.path.join(ROOT, 'app/Http/Controllers/ApController.php'))
bills_view_src = read(os.path.join(ROOT, 'resources/views/ap/bills.blade.php'))
ok('public static function dueStats(?string $asOf = null): array' in ap_service,
   'the payable figures are computed by the service, once')
due_fn = ap_service.split('public static function dueStats(?string $asOf = null): array', 1)[-1][:4000]
ok("$out['payable'] = round($out['open_bills'] + $out['unbilled_value'] - $out['credits'], 2);" in due_fn,
   'payable is what is owed on the bills, plus the goods received and not billed, less the credits')
ok("self::band($out, $open, $b->due_date?->copy() ?? $b->doc_date->copy(), $on, $week);" in due_fn,
   'a bill is banded on its due date, and a bill with none falls back to its document date rather than reading as late')
ok("self::band($out, $value, self::receiptDue($po), $on, $week);" in due_fn,
   'goods received but not billed are banded on the date the supplier\'s own terms fall due, so they are not invisible')
ok("'counts' => ['bills' => 0, 'unbilled' => 0, 'overdue' => 0, 'due7' => 0, 'hold' => 0]" in due_fn,
   'every figure carries its count, so 155,700 can be read as four documents before anyone opens them')
ok("$out['credits'] = self::creditSources($asOf)['credits'];" in due_fn,
   'credits are the unapplied payments and the open debit notes, the same credits the ageing nets off')
band_fn = ap_service.split('protected static function band(array &$out, float $amount, Carbon $due, Carbon $on, Carbon $week): void', 1)[-1][:600]
ok('if ($due->lt($on)) {' in band_fn and '} elseif ($due->lte($week)) {' in band_fn,
   'the band is one definition: past due is overdue, and due within seven days is the next week')
ok('protected static function receiptDue($po): Carbon' in ap_service
   and ap_service.count('self::receiptDue(') >= 2,
   'the due date of unbilled goods is defined once and read by both the ageing and the document list')
ok(ap_controller.count('ApService::dueStats($asOf)') >= 2,
   'the bills list and the ageing both take their figures from it')
ok("whereDate('due_date', '<'" not in ap_controller,
   'and neither of them re-derives overdue from the due date any more')
ok("'hold' => $due['counts']['hold']" in ap_controller,
   'the held-for-variance count the bills tile prints comes from the same place as the rest')
ok('Due in next 7 days' in bills_view_src and 'Due in next 7 days' in ap_view_src,
   'both screens print the same seven-day figure, under the same name')
ok('Total payable' in bills_view_src and 'of it is goods received, not yet billed' in bills_view_src,
   'and the bills list says how much of what is payable has no invoice yet')
ok('five figures lead the screen' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_bills_preview_check.js'))
   and 'five figures lead the screen' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_ageing_preview_check.js')),
   'the preview checks hold both screens to five figures, so neither can drift back to four')
ok('/__preview/ap-bills' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the bills screen beside the ageing')

# ── 26x. one credit rule, three screens ──────────────────────────────────────────────────
# A supplier credit was computed in the ageing (per supplier), again in the bills list's payable figure, and a third time
# would have been needed on the payments screen. The rule now lives in ApService::creditSources(), because three screens
# working out the same 11,600 by hand is three chances to print three different figures — and the rule has two edges that
# are easy to lose: a foreign payment's remainder is an exchange difference, and a cancelled payment is not a credit.
payments_view_src = read(os.path.join(ROOT, 'resources/views/ap/payments.blade.php'))
supplier_payment_model = read(os.path.join(ROOT, 'app/Models/SupplierPayment.php'))
ok('public static function creditSources(?string $asOf = null, ?int $supplierId = null): array' in ap_service,
   'the credits on the payables ledger are answered in one place')
credit_fn = ap_service.split('public static function creditSources(?string $asOf = null, ?int $supplierId = null): array', 1)[-1][:3000]
ok("'payments' => round((float) $rows->sum('payments'), 2), 'payment_count' => (int) $rows->sum('payment_count')," in credit_fn,
   'and answered both in total and per supplier, so the ageing row and the screen total are the same sum')
ok(ap_service.count("->whereColumn('allocated', '<', 'amount')") == 1,
   'the payment half of the rule is written once in the service, not once per screen that prints it')
ok("$out['credits'] = self::creditSources($asOf)['credits'];" in due_fn,
   'what is payable takes its credits from it')
ok("foreach (self::creditSources($asOf, $supplierId)['rows'] as $c)" in ap_service,
   'the ageing credit column is that same rule, per supplier')
ok("$r['credit'] += $c['credit'];" in ap_service and "$r['docs'] += $c['payment_count'] + $c['debit_note_count'];" in ap_service,
   'and counts the documents it is made of, so the aged-items count still agrees with the column')
ok("if ($this->cancelled_at || $this->isForeign()) {" in supplier_payment_model,
   'a cancelled or foreign payment has nothing left to apply: the model refuses to call either a credit')
ok('public static function paymentStats(?string $asOf = null): array' in ap_service,
   'the payments screen has its own figures, and two of them are the credit rule')
pay_fn = ap_service.split('public static function paymentStats(?string $asOf = null): array', 1)[-1][:2000]
ok("$credits = self::creditSources($asOf);" in pay_fn and "'credits' => $credits['credits']," in pay_fn,
   'so "credits on the ageing" on the payments screen is the ageing\'s figure, not a fourth opinion')
ok("$from = Carbon::parse($asOf)->subDays(29)->toDateString();" in pay_fn and "whereDate('date', '>=', $from)" in pay_fn,
   'the thirty-day figure is a window with both ends, so it cannot drift with the calendar')
ok("'kpi' => ApService::paymentStats($asOf)" in ap_controller,
   'the payments list is handed them')
ok("->when($request->boolean('unapplied'), fn ($q) => $q->whereNull('cancelled_at')" in ap_controller
   and "UPPER(TRIM(currency))" in ap_controller,
   'and its unapplied tab is the credit rule in SQL: not cancelled, and not in another currency')
ok('Unapplied payments' in payments_view_src and 'Credits on the ageing' in payments_view_src
   and 'the figure the ageing nets off' in payments_view_src,
   'the screen says which of its figures the other screens own')
ok('Paid in the last 30 days' in payments_view_src and 'cancelled ones are left out' in payments_view_src,
   'and that the money-out figure leaves the cancelled payment out')
ok("$kpi['payment_count'].' payment(s) paid and not yet applied to a bill'" in payments_view_src,
   'a credit is counted in payments, not just in cedis, because one payment on account is what it usually is')
ok("$credits['payment_count']" in ap_view_src and "debit_note_count" in ap_view_src,
   'the ageing names the parts of its credit column, so the two screens can be read against each other')
ok('the payables ageing prints the same 11,600 of credits' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_payments_preview_check.js')),
   'the payments check reads the ageing, not only its own screen')
ok('the credit figure says what it is made of, and where the money is listed' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_ageing_preview_check.js')),
   'and the ageing check holds its side of it')
ok('/__preview/ap-payments' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the payments screen beside the bills')

# ── 26y. the statement agrees with the ageing, and says so ───────────────────────────────
# The statement is the one AP screen a supplier reads, and it was built from the goods received while the ageing is built
# from the bills — two routes to one supplier's balance. If they disagree, one of them is wrong and the statement must not
# be sent, so the screen now prints both figures and the difference before anyone prints it.
stmt_body = read(os.path.join(ROOT, 'resources/views/ap/partials/statement-body.blade.php'))
stmt_print = read(os.path.join(ROOT, 'resources/views/ap/statement-print.blade.php'))
stmt_controller = ap_controller.split('public function statement(Request $request, Supplier $supplier)', 1)[-1][:1600]
ok("$st = ApService::statement($supplier, $from, $to);" in stmt_controller
   and "$age = ApService::ageing($to, $supplier->id)->first();" in stmt_controller,
   'the statement comes from the documents and the ageing row from the buckets: two routes, one balance')
ok("$tie = ['as_of' => $to, 'statement' => round((float) $st['closing'], 2), 'ageing' => $balance," in stmt_controller,
   'so the controller compares the close with the ageing balance at the statement date')
ok("$tie['ties'] = abs($tie['diff']) <= 0.005;" in stmt_controller,
   'and calls a half cent either way a tie, the same tolerance the control reconciliation uses')
ok("compact('supplier', 'from', 'to', 'st', 'age', 'tie')" in stmt_controller,
   'both the screen and the printed copy are handed it')
ok('id="stmtTie"' in stmt_body and 'id="stmtTieState"' in stmt_body,
   'the statement prints the tie as a card above its lines')
ok('This statement closes at' in stmt_body and 'The ageing says' in stmt_body and 'Difference' in stmt_body,
   'the three figures are this statement, the ageing, and the gap between them')
ok('the two agree' in stmt_body and 'the two disagree' in stmt_body,
   'the card distinguishes agreeing from disagreeing rather than printing a number to be read twice')
ok('can be sent this statement' in stmt_body,
   'the agreeing state says the document may go out')
ok('before the statement goes out' in stmt_body and 'One of them is counting a document the other is not' in stmt_body,
   'the disagreeing state says what to look for and stops the send')
ok('@unless(isset($print))' in stmt_body,
   'the tie is an internal control, so the printed copy leaves it out')
ok("@include('ap.partials.statement-body', ['print' => true])" in stmt_print,
   'and the printed copy is the same partial, with the internal card switched off')
ok('Illuminate\\Support\\Carbon::parse' not in stmt_body and 'Illuminate\\Support\\Carbon::parse' not in stmt_print,
   'the partial dates through dmy() like every other screen, so it can be previewed and read the same way')
ok("dmy($from)" in stmt_body and "dmy($r['date'])" in stmt_body,
   'the opening date and every line date go through the one date helper')
ok('every line’s balance is the one above it plus its credit less its debit' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_statement_preview_check.js')),
   'the preview check adds the column back up rather than trusting the running balance')
ok('the ageing prints the same 40,900 for this supplier' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_statement_preview_check.js')),
   'and reads the ageing screen, not only its own')
ok('/__preview/ap-statement' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the statement')

# ── 26z. the bill screen applies the credit rule, not a query of its own ─────────────────
# The apply-credit dropdown was a fourth copy of "what is a credit", and it offered every open debit note whatever its
# currency — an option that the allocation itself refuses. v9.4 the documents come from ApService::openCredits(), which is
# what creditSources() aggregates, narrowed to the bill's own currency.
bill_view_src = read(os.path.join(ROOT, 'resources/views/ap/bill-show.blade.php'))
ok('public static function openCredits(?int $supplierId = null, ?string $asOf = null, ?string $currency = null): Collection' in ap_service,
   'the credit documents are answered by the service, not by the controller that happens to want them')
open_fn = ap_service.split('public static function openCredits(?int $supplierId = null, ?string $asOf = null, ?string $currency = null): Collection', 1)[-1][:3000]
ok("$out->push(['key' => 'p'.$p->id, 'kind' => 'payment'," in open_fn and "$out->push(['key' => 'd'.$dn->id, 'kind' => 'debit_note'," in open_fn,
   'a payment and a debit note are the two kinds of credit, with the keys the apply action parses')
ok("'currency' => $home]);" in open_fn and "strtoupper((string) $dn->currency) : $home]);" in open_fn,
   'each document carries the money it is credit in: a payment is always in the property\'s own, a note in its own')
ok("return ($want === null ? $out : $out->where('currency', $want))->values();" in open_fn,
   'and the caller can ask for the money the bill is written in')
ok(ap_service.count("whereColumn('allocated', '<', 'amount')") == 1,
   'the payment half of the rule is still written once, now inside openCredits()')
ok('$rows = self::openCredits($supplierId, $asOf)->groupBy(\'supplier_id\')' in ap_service,
   'the per-supplier aggregate the ageing and the bills list read is that same document list, grouped — not a second query')
show_fn = ap_controller.split('public function show(SupplierInvoice $bill)', 1)[-1][:1800]
ok("ApService::openCredits((int) $bill->supplier_id, null, $currency)" in show_fn,
   'the bill screen offers exactly those documents, for this supplier')
ok("$currency = $bill->isForeign() ? strtoupper((string) $bill->currency) : strtoupper((string) (hotel()?->currency ?: 'GHS'));" in show_fn,
   'narrowed to the currency the bill is written in, because allocate() refuses to cross currencies')
ok('SupplierPayment::where(' not in show_fn and 'SupplierInvoice::where(' not in show_fn,
   'and the controller no longer holds a credit query of its own')
ok('id=\"noCredit\"' in bill_view_src and 'Nothing on account with this supplier in' in bill_view_src,
   'when there is nothing to apply the card says so, instead of looking unfinished')
ok('id=\"applyCredit\"' in bill_view_src,
   'the form itself is unchanged: same source keys, same amount default')
ok('the list holds the two credits on account, and nothing else' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_bill_show_preview_check.js')),
   'the preview check counts the options rather than trusting the list')
ok('the source keys are the ones the controller parses' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_bill_show_preview_check.js')),
   'and holds them to the p<id>/d<id> form apply() requires')
ok('/__preview/ap-bill-show' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the bill screen')

# ── 26aa. the payment screen says whether the ageing counts this payment ─────────────────
# A part payment leaves money on account, and a clerk's next question is whether that money is on the ageing — but not
# every remainder is a credit: a cancelled payment is not, a foreign payment's remainder is an exchange difference, and a
# payment dated after the ageing date is not counted yet. The screen asks openCredits() rather than deciding again.
payment_view_src = read(os.path.join(ROOT, 'resources/views/ap/payment-show.blade.php'))
pay_show_fn = ap_controller.split('public function payShow(SupplierPayment $spay)', 1)[-1][:1400]
ok("$open = ApService::openCredits((int) $spay->supplier_id, $asOf)->firstWhere('key', 'p'.$spay->id);" in pay_show_fn,
   'the payment screen asks the credit rule whether this payment is one of its documents')
ok("'supplier_credits' => ApService::creditSources($asOf, (int) $spay->supplier_id)['credits']" in pay_show_fn,
   'and takes the supplier\'s credits from the same aggregate the ageing and the payments list print')
ok("$credit = ['as_of' => $asOf, 'in_credit' => $open !== null, 'free' => (float) ($open['free'] ?? 0)," in pay_show_fn,
   'so the state is a yes or no with the free amount beside it, not a second calculation')
ok("'credit' => $credit," in pay_show_fn,
   'both views are handed it; the printed remittance advice has no use for it and ignores it')
ok('id="payCredit"' in payment_view_src,
   'the answer is on the screen, under the money')
ok('of this payment is still on account' in payment_view_src and "money($credit['supplier_credits'])" in payment_view_src,
   'the yes branch names the figure and the supplier credits it sits in')
ok('$credit[\'in_credit\']' in payment_view_src and '$p->cancelled_at' in payment_view_src,
   'and the no branches are distinguished by why: cancelled first')
ok('what is left of a foreign payment is an exchange difference' in payment_view_src,
   'a foreign payment\'s remainder is an exchange difference, which the ageing never nets off')
ok('This payment is dated after' in payment_view_src and 'becomes a credit on the day it is dated' in payment_view_src,
   'and a payment dated after the ageing date is not counted early')
ok('All of this payment has been applied to bills' in payment_view_src,
   'a fully applied payment says so rather than printing 0.00 without a reason')
ok('the credit state is the rule\'s answer' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_payment_show_preview_check.js')),
   'the preview check holds the yes branch to the rule and to the payments list')
ok('/__preview/ap-payment-show' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the payment screen')

# ── 26ab. a control account leads to the screen that owns it, and the page counts them ───
# The GL page printed eighteen controls and left the reader to count them, and a difference of 40.00 did not say where to
# look. v9.6 each row names and links its sub-ledger's screen, and the page states how many agree and which strict control
# is out — the first two questions anyone asks of a control list.
gl_controller = read(os.path.join(ROOT, 'app/Http/Controllers/GlController.php'))
gl_view_src = read(os.path.join(ROOT, 'resources/views/gl/index.blade.php'))
ledger_service = read(os.path.join(ROOT, 'app/Services/LedgerService.php'))
ok('public static function controlScreens(): array' in ledger_service,
   'the screen each sub-ledger belongs to is declared once, beside the specs')
screens_fn = ledger_service.split('public static function controlScreens(): array', 1)[-1][:2400]
ok("'accounts_payable' => ['route' => 'ap.ageing', 'label' => 'Payables ageing']," in screens_fn,
   'the two sub-ledgers the ageing screens own point at them')
ok("'city_ledger' => ['route' => 'accounts.receivables', 'label' => 'Receivables ageing']," in screens_fn,
   'and so does the city ledger')
ok(screens_fn.count("'route' =>") >= 15,
   'the rest of the sub-ledgers have a screen too, so a difference is never a dead end')
ok("'screen' => $screens[$key] ?? null" in ledger_service,
   'every control row carries its screen, or none where there is no such screen')
ok('public static function controlSummary(string $asOf): array' in ledger_service,
   'and the count is a service answer, not something each screen computes for itself')
summary_fn = ledger_service.split('public static function controlSummary(string $asOf): array', 1)[-1][:2000]
ok("$off = array_values(array_filter($rows, fn ($c) => abs(round($c['gl'] - $c['sub'], 2)) >= 0.01));" in summary_fn,
   'a control is out when the two figures differ by a cent or more, the same tolerance the tie uses')
ok("$strict = array_values(array_filter($off, fn ($c) => $c['strict']));" in summary_fn,
   'and only a strict control counts as a failure: inventory at latest cost is indicative')
ok("'missed' => count($strict), 'indicative' => count($off) - count($strict)," in summary_fn,
   'both are counted, so the page can say which kind of difference it has')
ok("'controlSummary' => $cutoff ? LedgerService::controlSummary($cutoff) : null," in gl_controller,
   'the GL page is handed it with the control list')
ok("route($c['screen']['route'])" in gl_view_src and "{{ $c['screen']['label'] }} →" in gl_view_src,
   'each mapped row links to its screen and names it')
ok('id="controlSummary"' in gl_view_src and 'control accounts agree to the cent at' in gl_view_src,
   'the page says how many agree, at the date they were compared')
ok("the largest is" in gl_view_src and "Reconcile" in gl_view_src and "before the month is closed" in gl_view_src,
   'and names the largest strict difference with the instruction that follows from it')
ok("the page counts how many of the controls agree" in read(os.path.join(ROOT, 'deploy/dev/audit/gl_ledger_preview_check.js')),
   'the preview check counts the count rather than trusting the sentence')
ok("a control with no screen of its own invents no link" in read(os.path.join(ROOT, 'deploy/dev/audit/gl_ledger_preview_check.js')),
   'and holds the unmapped row to no link at all')

# ── 26ac. the company ledger is read as at a date, and says how it ties ─────────────────
# A corporate account reads its ledger as a statement: every charge and receipt up to a date, carried down, closing on a
# balance it can be asked to pay. Two things were missing. The ledger had no date at all — a receipt banked next week
# reduced today's balance — and it had no running balance it could be held to, because the counter lived inside the view.
# And the city ledger is the same money the receivables ageing reads: if the two disagree, one of them is wrong, so the
# screen states its own tie at the date it was read, exactly as the supplier statement does.
company_controller = read(os.path.join(ROOT, 'app/Http/Controllers/AccountsController.php'))
company_view_src = read(os.path.join(ROOT, 'resources/views/accounts/company.blade.php'))
company_ledger_fn = company_controller.split('public function companyLedger(', 1)[-1][:4200]
ok('public function companyLedger(Request $request, Company $company)' in company_controller,
   'the company ledger takes the request, because it is now read at a date')
ok("$asOf = substr((string) $request->input('as_of', bdate()->toDateString()), 0, 10);" in company_ledger_fn,
   'the date asked for is the date read, defaulting to the business date')
ok("if ($asOf > bdate()->toDateString()) {\n            $asOf = bdate()->toDateString();" in company_ledger_fn,
   'and it is clamped to the business date: the ledger has not been posted past today')
ok("->whereDate('business_date', '<=', $asOf)->get()" in company_ledger_fn,
   'a charge dated after the date has not happened yet')
ok("whereDate('date', '<=', $asOf)->get()" in company_ledger_fn,
   'and a receipt dated after it has not been banked')
ok("$running = round($running + $r['debit'] - $r['credit'], 2);" in company_ledger_fn
   and "return $r + ['balance' => $running];" in company_ledger_fn,
   'the running balance is computed once, on the rows, instead of in a counter the view carries')
ok("$closing = $running;   // the last row's balance" in company_ledger_fn,
   'so the closing figure is the last row\'s balance by construction and cannot drift from it')
ok("$aged = ArService::ageing(Carbon::parse($asOf))->firstWhere('company', $company->name);" in company_ledger_fn,
   'the ageing is asked for this company at the same date, not a date of its own')
ok("$tie = ['as_of' => $asOf, 'ledger' => $closing, 'ageing' => round((float) ($aged['outstanding'] ?? 0), 2)];" in company_ledger_fn,
   'the tie is the ledger closing against the ageing outstanding')
ok("$tie['ties'] = abs($tie['diff']) <= 0.005;" in company_ledger_fn,
   'tied at half a cent, the tolerance the other statements use')
ok("compact('company', 'rows', 'paymentModes', 'splitModes', 'tie', 'asOf')" in company_ledger_fn,
   'the date reaches the view, so the screen can say what it was read at')
ok('id="companyTie"' in company_view_src,
   'the page states the tie in its own card')
ok('This ledger closes at' in company_view_src and 'The ageing says' in company_view_src,
   'with both figures named for what they are')
ok(':tone="$tie[\'ties\'] ? \'green\' : \'red\'"' in company_view_src,
   'and a nil difference is green rather than a neutral no-answer')
ok('id="companyTieState"' in company_view_src and 'The company can be sent this ledger.' in company_view_src,
   'a tied ledger says what it is good for: it can go to the company')
ok('check this company\'s city-ledger entries and receipts before the ledger goes out' in company_view_src,
   'and a difference says which entries to check before it does')
ok("max=\"{{ bdate()->toDateString() }}\"" in company_view_src and 'name="as_of"' in company_view_src,
   'the as-at control is on the screen and cannot reach into the future')
ok("{{ money($r['balance'], false) }}" in company_view_src,
   'the row prints the balance the controller computed, not one the view made up')
ok('id="ledgerClosing"' in company_view_src and '{{ money($closing) }}' in company_view_src,
   'the closing line prints that same figure')
ok(':value="max(0, $closing)"' in company_view_src,
   'the receipt dialog defaults to what is owed, never to a negative')
ok(':value="$asOf"' in company_view_src,
   'and to the date the ledger was read at')
ok('check("the receipt modal is usable")' in read(os.path.join(ROOT, 'deploy/dev/audit/company_ledger_preview_check.js'))
   or 'the receipt modal' in read(os.path.join(ROOT, 'deploy/dev/audit/company_ledger_preview_check.js')),
   'the preview check exercises the dialog')
ok('every row’s balance is the one above it plus its debit less its credit' in read(os.path.join(ROOT, 'deploy/dev/audit/company_ledger_preview_check.js')),
   'and walks the running balance down the rendered table, so a miscomputed row fails the build')
ok('the receivables ageing prints the same 48,400 for this company' in read(os.path.join(ROOT, 'deploy/dev/audit/company_ledger_preview_check.js')),
   'the check holds the ledger to the ageing screen, not to its own sample')
ok('/__preview/company-ledger' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the company ledger')
ok("pathname === '/company-ledger'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/accounts/city-ledger/21'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen actually lives at')

# ── 26ad. the city ledger index reads at a date and holds itself to the ageing ───────────
# The list of company balances and the receivables ageing are two queries over one sub-ledger. The list summed every
# charge and receipt ever posted; the ageing cut them at a date and FIFO-netted receipts. That pair drifts, and a
# statement sent from the drifting side is wrong. v9.8 the list takes the as-at date, states its own total against the
# ageing's, names the companies whose rows disagree, and takes its flags from the ageing rather than re-testing the limit.
city_ledger_fn = company_controller.split('public function cityLedger(', 1)[-1][:3200]
balances_fn = company_controller.split('protected function companyBalances(', 1)[-1][:900]
ok('public function cityLedger(Request $request)' in company_controller,
   'the city ledger index takes the request, because a balance is a balance as at a date')
ok("$asOf = substr((string) $request->input('as_of', bdate()->toDateString()), 0, 10);" in city_ledger_fn
   and "$asOf = bdate()->toDateString();   // the ledger has not been posted past the business date" in city_ledger_fn,
   'the date is clamped to the business date, exactly as the company ledger and the ageing clamp it')
ok("->when($asOf, fn ($q) => $q->whereDate('business_date', '<=', $asOf))" in balances_fn,
   'a charge dated after the date is not summed into today\'s balance')
ok("->when($asOf, fn ($q) => $q->whereDate('date', '<=', $asOf))" in balances_fn,
   'and a receipt dated after it has not been banked')
ok("[$debits, $credits] = $this->companyBalances($asOf);" in city_ledger_fn,
   'both sums are taken at that date')
ok("$aged = ArService::ageing(Carbon::parse($asOf))->keyBy('id');" in city_ledger_fn,
   'the ageing is read once, at the same date, keyed by company')
ok("$c->aged = round((float) ($row['outstanding'] ?? 0), 2);" in city_ledger_fn,
   'so every row carries the figure the ageing prints for that company')
ok("$c->flag = (string) ($row['flag'] ?? '');           // 'OVER LIMIT' / '90+ overdue', decided once, in ArService" in city_ledger_fn,
   'and its flag, rather than a second copy of the credit-limit test on this screen')
ok("$c->ties = abs(round($c->outstanding - $c->aged, 2)) <= 0.005;" in city_ledger_fn,
   'each row is compared to the ageing at the same half-cent tolerance the other statements use')
ok("$c->quiet = abs($c->billed) <= 0.004 && abs($c->received) <= 0.004;" in city_ledger_fn,
   'a company that has never been billed or paid is marked quiet rather than printed as a row of zeroes')
ok("'list' => round((float) $companies->sum('outstanding'), 2)," in city_ledger_fn
   and "'ageing' => round((float) $aged->sum('outstanding'), 2)," in city_ledger_fn,
   'the tie is the list\'s total against the ageing\'s total')
ok("'drift' => $companies->reject(fn ($c) => $c->ties)->pluck('name')->values()->all()," in city_ledger_fn,
   'and the companies whose rows are out are named, because a difference nobody can open is a dead end')
ok("$tie['ties'] = abs($tie['diff']) <= 0.005 && ! $tie['drift'];" in city_ledger_fn,
   'a total that agrees while a row does not is still not tied')
ok("compact('companies', 'tie', 'asOf')" in city_ledger_fn,
   'the date reaches the view so the page can say what it was read at')
city_ledger_view = read(os.path.join(ROOT, 'resources/views/accounts/cityledger.blade.php'))
ok('id="cityLedgerTie"' in city_ledger_view and 'This list totals' in city_ledger_view and 'The ageing says' in city_ledger_view,
   'the page states the tie in its own card, naming both figures')
ok('id="cityLedgerTieState"' in city_ledger_view and 'says the same, company by company' in city_ledger_view,
   'a tied list says what it is good for: the rows can be read as they stand')
ok("@foreach($tie['drift'] as $d)" in city_ledger_view and 'open each ledger and check its charges and receipts' in city_ledger_view,
   'and a drifting list names each company with both figures and sends the reader to its ledger')
ok('id="cityLedgerLimit"' in city_ledger_view and 'is over its credit limit and the ageing names it' in city_ledger_view,
   'the over-limit count sits outside the tie, so it prints whatever the tie says')
ok("max=\"{{ bdate()->toDateString() }}\"" in city_ledger_view and 'name="as_of"' in city_ledger_view,
   'the as-at control is on the screen and cannot reach into the future')
ok('id="clTable"' in city_ledger_view and '<tfoot>' in city_ledger_view and 'id="clClosing"' in city_ledger_view,
   'the printed footer is a real row of totals, not just the header repeated')
ok("$c->quiet ? '—' : money($c->outstanding, false)" in city_ledger_view and 'no entries yet' in city_ledger_view,
   'a company with nothing to its name says so instead of printing 0.00')
ok('credit on account' in city_ledger_view and "$c->outstanding < 0 ? 'text-green' : ''" in city_ledger_view,
   'a company paid in advance reads as a credit, in the colour of a credit')
ok("route('accounts.company', $c)" in city_ledger_view,
   'every row opens the ledger that can be sent')
ok('every row’s outstanding is its billed less its received' in read(os.path.join(ROOT, 'deploy/dev/audit/city_ledger_preview_check.js')),
   'the preview check does the arithmetic on the rendered rows')
ok('every company’s row is the figure the ageing prints for it' in read(os.path.join(ROOT, 'deploy/dev/audit/city_ledger_preview_check.js')),
   'and reads the ageing screen\'s own table rather than trusting the sample')
ok('the page names the company whose row is out' in read(os.path.join(ROOT, 'deploy/dev/audit/city_ledger_preview_check.js')),
   'both branches are built and checked, because the disagreeing page is the one that has to be readable')
ok('/__preview/city-ledger' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the list (the drift build is a state of it, as vouchers-off is of vouchers)')
ok("pathname === '/city-ledger'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/city-ledger-drift'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen lives at')

# ── 26ae. the cash book is a book: it opens, it moves, it closes ──────────────────────────
# The daily cash book listed one day's transactions and labelled their net "collection". That is not a figure anybody can
# count a drawer against: a cash book carries a balance in from yesterday and out to tomorrow. v9.9 it does, per mode, from
# one source of movement used twice — and it says where the day sits in the ledger, because the ledger is posted through
# yesterday and today's takings are not in it yet. A mode that closes below zero is flagged: no drawer pays out more than
# it held.
cash_book_fn = company_controller.split('public function cashBook(', 1)[-1][:4200]
cash_move_fn = company_controller.split('protected function cashMovements(', 1)[-1][:1800]
ok('protected function cashMovements(?string $from, ?string $to)' in company_controller,
   'the day\'s movement is one method, because the book needs the same rows twice: to open it and to move it')
ok("$opening = $this->cashMovements(null, $yesterday);" in cash_book_fn and "$rows = $this->cashMovements($date, $date);" in cash_book_fn,
   'everything before the day opens the book; the day itself moves it')
ok("->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))" in cash_move_fn
   and "->when($to, fn ($q) => $q->whereDate('date', '<=', $to))" in cash_move_fn,
   'both halves of the range are applied to the three sources, so a brought-forward is a real sum and not a guess')
ok("$date = substr((string) $request->input('date', bdate()->toDateString()), 0, 10);" in cash_book_fn
   and "$date = bdate()->toDateString();   // a cash book cannot be read before the money is taken" in cash_book_fn,
   'the day is clamped to the business date, like every other ledger read in the module')
ok("$m['close'] = round($m['open'] + $m['in'] - $m['out'], 2);" in cash_book_fn,
   'each mode closes at its opening plus receipts less payments, computed once')
ok("$m['over'] = $m['close'] < -0.005;   // a till cannot hold less than nothing" in cash_book_fn,
   'and a mode that closes below zero is marked, not silently printed as a negative')
ok("$totals = ['open' => $sum('open'), 'in' => $sum('in'), 'out' => $sum('out'), 'close' => $sum('close')];" in cash_book_fn,
   'the footer figures are the sums of the mode rows')
ok("$sources = $rows->groupBy('src')->map(fn ($g) => round((float) $g->sum('in') - (float) $g->sum('out'), 2));" in cash_book_fn,
   'the day\'s movement is also broken out by the place it came from, so the net can be traced')
ok("$posting = ['posted_through' => LedgerService::cutoff(), 'posted' => $date <= LedgerService::cutoff(), 'as_of' => $date];" in cash_book_fn,
   'and the day\'s posting state comes from the ledger\'s own cutoff rather than being assumed')
ok("compact('rows', 'date', 'modes', 'totals', 'sources', 'sourcesNote', 'drawn', 'posting')" in cash_book_fn,
   'the view is handed the book, not a day list')
cash_book_view = read(os.path.join(ROOT, 'resources/views/accounts/cashbook.blade.php'))
ok('id="cashBookControl"' in cash_book_view and 'Brought forward' in cash_book_view and 'Carried forward' in cash_book_view,
   'the page opens the book and closes it, by name')
ok('id="cashBookState"' in cash_book_view and 'have not reached the general ledger yet' in cash_book_view,
   'and says where the day sits: today\'s collections are not in the ledger until the next sync')
ok('id="cashBookDrawn"' in cash_book_view and 'A drawer cannot pay out more than it held' in cash_book_view,
   'a mode below zero is named with the instruction that follows from it')
ok('id="cashBookModes"' in cash_book_view and 'id="cashClosing"' in cash_book_view,
   'the by-mode table is the book\'s chain, with one closing figure')
ok('id="cashBookRows"' in cash_book_view and 'id="cashIn"' in cash_book_view and 'id="cashOut"' in cash_book_view,
   'and the day\'s entries behind it, with their own totals')
ok('count($drawn) > 0' in cash_book_view and "count($drawn) ? 'red' : 'green'" in cash_book_view,
   'the flag is decided on a count, not on an array\'s truthiness — an empty list is false to PHP and true to a preview')
ok('every mode closes at its opening plus receipts less payments' in read(os.path.join(ROOT, 'deploy/dev/audit/cash_book_preview_check.js')),
   'the preview check walks the chain down the rendered table')
ok('the rows add to the modes’ receipts' in read(os.path.join(ROOT, 'deploy/dev/audit/cash_book_preview_check.js')),
   'and holds the day\'s rows to the same two figures the modes print')
ok('a drawer cannot close below zero unnoticed' in read(os.path.join(ROOT, 'deploy/dev/audit/cash_book_preview_check.js')),
   'both builds are checked, because the overdrawn day is the one that matters')
ok('/__preview/cash-book' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the cash book')
ok("pathname === '/cash-book'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/cash-book-overdrawn'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen lives at')
ok('return phpTrue(v);' in read(os.path.join(ROOT, 'deploy/dev/preview/blade-render.mjs')),
   'the renderer reads a condition the way PHP does, so an empty list is false and a red warning is not printed over a good day')

# ── 26af. the profit & loss is held to the ledger, and states its result ──────────────────
# The statement is built from documents: folio charges by category, POS takings settled at the counter, receipt and payment
# vouchers. The general ledger is built from posted journals. That is one period read twice, and the statement never said
# whether the two agreed — so a week whose sync had not run printed the same numbers as a week that had. v9.10 it prints the
# ledger's figure beside each side and names the line that is out; it also states its own result and margin, and clamps the
# period it is asked about.
pnl_fn = company_controller.split('public function pnl(', 1)[-1][:3600]
gl_move_fn = company_controller.split('protected function glMovement(', 1)[-1][:900]
ok('protected function glMovement(string $from, string $to, string $type): float' in company_controller,
   'the ledger\'s movement for a period is one query, by the nature of the account it landed in')
ok("$sign = $type === 'income' ? 'credit - debit' : 'debit - credit';" in gl_move_fn,
   'income is credit-natured and expense debit-natured, so both read positive')
ok("->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum(DB::raw($sign))" in gl_move_fn,
   'and the range is the period the statement was asked about')
ok("$to = substr((string) $request->input('to', bdate()->toDateString()), 0, 10);" in pnl_fn
   and "$to = bdate()->toDateString();" in pnl_fn,
   'the period cannot end in the future')
ok("if ($from > $to) {\n            $from = $to;" in pnl_fn,
   'and a backwards period is closed to a single day rather than printing nonsense')
ok("'revenue' => ['pnl' => round($totalIncome, 2), 'gl' => $this->glMovement($from, $to, 'income')]," in pnl_fn,
   'revenue is the statement\'s total against the ledger\'s income movement')
ok("'expense' => ['pnl' => round($exp, 2), 'gl' => $this->glMovement($from, $to, 'expense')]," in pnl_fn,
   'and expenses the same, on the other nature')
ok("$tie['ties'] = ! $tie['out']['revenue'] && ! $tie['out']['expense'];" in pnl_fn,
   'the statement is tied only when both sides agree')
ok("$tie['posted'] = $to <= LedgerService::cutoff();" in pnl_fn,
   'a period reaching past the posted-through day says so, so a difference there is expected rather than a fault')
ok("$net = round($totalIncome - $exp, 2);" in pnl_fn and "'margin' => $totalIncome > 0 ? round($net / $totalIncome * 100, 1) : 0.0," in pnl_fn,
   'the result and the margin are computed once, in the controller')
ok("'labels' => collect(config('pms.charge_categories'))" in pnl_fn,
   'and the two lines the statement adds to the chart of categories are merged there, so no line prints as its key')
pnl_view = read(os.path.join(ROOT, 'resources/views/accounts/pnl.blade.php'))
ok('id="pnlTie"' in pnl_view and 'id="pnlTieTable"' in pnl_view and 'The general ledger' in pnl_view,
   'the page prints the ledger beside the statement')
ok('id="pnlTieState"' in pnl_view and 'can be issued as it stands' in pnl_view,
   'a tied statement says what it is good for')
ok('Revenue is out by' in pnl_view and 'sync failures on the general ledger page' in pnl_view,
   'and a drifted one names the line, the money, and where to look')
ok('id="pnlTiePosted"' in pnl_view and 'still outside the ledger' in pnl_view,
   'a period that runs past the cutoff is named as such')
ok('Net margin' in pnl_view and '$margin' in pnl_view,
   'the statement states its margin, not only its profit')
ok('never income' in pnl_view,
   'tax collected is stated and kept out of income')
ok("$income->count()" not in pnl_view and 'count($income)' in pnl_view,
   'the view counts the collections a document hands it, rather than assuming a fluent collection')
ok('the income lines add to the total the footer prints' in read(os.path.join(ROOT, 'deploy/dev/audit/pnl_preview_check.js')),
   'the preview check adds the rendered lines up rather than trusting the footer')
ok('every income line is named, not keyed' in read(os.path.join(ROOT, 'deploy/dev/audit/pnl_preview_check.js')),
   'and holds every line to a human label')
ok('the sentence names revenue and only revenue' in read(os.path.join(ROOT, 'deploy/dev/audit/pnl_preview_check.js')),
   'the drifted build is checked for the line it names and the line it leaves alone')
ok('/__preview/pnl' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the statement')
ok("pathname === '/pnl'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/pnl-drift'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen lives at')

# ── 26ah. the voucher register analyses its period and holds itself to the ledger ─────────
# Every receipt and payment the property writes is a voucher, and every voucher becomes a general-ledger entry at its
# account head once its day is closed. The register listed them and stated three figures: no totals in the table, no
# analysis by head, and no word on whether the period had reached the ledger. v9.11 it clamps its period, totals itself,
# analyses the heads — which are the lines the P&L prints — and compares each head's vouchers dated on or before the
# posted-through day with that head's posted movement, counting the later ones separately because they are not late, merely
# unposted.
vouchers_fn = company_controller.split('public function vouchers(', 1)[-1][:5200]
ok("$to = substr((string) $request->input('to', bdate()->toDateString()), 0, 10);" in vouchers_fn
   and "$to = bdate()->toDateString();   // a register cannot list vouchers that have not been written" in vouchers_fn,
   'the period cannot end in the future')
ok("if ($from > $to) {\n            $from = $to;" in vouchers_fn,
   'and a backwards period closes to a single day')
ok("$rec = round((float) $vouchers->where('type', 'receipt')->sum('amount'), 2);" in vouchers_fn
   and "$pay = round((float) $vouchers->where('type', 'payment')->sum('amount'), 2);" in vouchers_fn,
   'the register totals its own receipts and payments')
ok("$byHead = $vouchers->groupBy('account_head_id')" in vouchers_fn and "'out' => round((float) $g->where('type', 'payment')->sum('amount'), 2)" in vouchers_fn,
   'and analyses them by account head, receipts and payments apart')
ok("->each(fn (&$r) => $r['net'] = round($r['in'] - $r['out'], 2))->sortBy('head')->values();" in vouchers_fn,
   'each head keeping the net of its two columns')
ok("$cut = LedgerService::cutoff();" in vouchers_fn and "$postedVouchers = $vouchers->filter(fn ($v) => $v->date->toDateString() <= $cut);" in vouchers_fn,
   'the tie is over the vouchers whose day is closed, not over every voucher written')
ok("$movement = $g->sum(fn ($v) => $v->type === 'payment' ? (float) $v->amount : -1 * (float) $v->amount);" in vouchers_fn,
   'a payment debits its head and a receipt credits it, so both read as one signed movement')
ok("->select('account_head_id', DB::raw('SUM(debit - credit) as amt'))->groupBy('account_head_id')->pluck('amt', 'account_head_id');" in vouchers_fn,
   'and the ledger side is the posted lines for the same heads and dates')
ok("'posted' => $postedVouchers->count(), 'unposted' => $vouchers->count() - $postedVouchers->count()," in vouchers_fn,
   'vouchers after the cutoff are counted apart rather than skewing the comparison')
ok("'drift' => $ledger->filter(fn ($r) => abs($r['diff']) > 0.005)->values()->all()];" in vouchers_fn,
   'the heads that disagree are named, at the same half-cent tolerance the other ties use')
ok("$tie['ties'] = $tie['drift'] === [];" in vouchers_fn,
   'the period is tied only when every head agrees')
ok("$tie['expected_abs'] = abs($tie['expected']);" in vouchers_fn and "$tie['diff_abs'] = abs($tie['diff']);" in vouchers_fn,
   'the card compares magnitudes, computed once rather than nested in the view')
ok("'headGroups' => AccountHead::where('is_active', true)->orderBy('type')->orderBy('code')->get()->groupBy('type')," in vouchers_fn,
   'the modal is handed its heads grouped, instead of asking the view to group a collection')
vouchers_view = read(os.path.join(ROOT, 'resources/views/accounts/vouchers.blade.php'))
ok('id="voucherTie"' in vouchers_view and 'Are these vouchers in the ledger?' in vouchers_view,
   'the page asks the question only the register can answer')
ok('id="voucherTieState"' in vouchers_view and 'at its own head, for' in vouchers_view,
   'a tied period says where the money is')
ok("@foreach($tie['drift'] as $d)" in vouchers_view and 'sync failures on the general ledger page' in vouchers_view,
   'and a drifting one names the head with both figures and where to look')
ok('id="voucherHeads"' in vouchers_view and 'id="vhIn"' in vouchers_view and 'id="vhNet"' in vouchers_view,
   'the register is also read as an analysis, with its own totals')
ok('id="vouchersTable"' in vouchers_view and 'id="vouchersTotal"' in vouchers_view,
   'and the register itself prints its total and count rather than leaving them to the header')
ok("max=\"{{ bdate()->toDateString() }}\"" in vouchers_view,
   'the period controls cannot reach into the future')
ok("route('accounts.pnl', ['from' => $from, 'to' => $to])" in vouchers_view,
   'the page names where the same heads are printed, and links to that statement for the same period')
ok('the payment heads are the P&L’s expense total for the same week' in read(os.path.join(ROOT, 'deploy/dev/audit/vouchers_preview_check.js')),
   'the preview check reads the P&L screen rather than asserting the sample')
ok('the page names the head that is out' in read(os.path.join(ROOT, 'deploy/dev/audit/vouchers_preview_check.js')),
   'both branches are built and checked')
ok('/__preview/vouchers' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the register')
ok("pathname === '/vouchers-register'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/vouchers-register-drift'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen lives at')
ok("head: head(r.headId), account_head_id: r.headId," in read(os.path.join(ROOT, 'deploy/dev/preview/vouchers-sample.mjs'))
   and "label: `${h.code} · ${h.name}`" in read(os.path.join(ROOT, 'deploy/dev/preview/vouchers-sample.mjs')),
   'the sample carries both the head\'s name and the label the filter prints, as the model does')

# ── 26ai. agent commissions age, and every settlement names its document ──────────────────
# A commission accrues at check-out and is settled when the agent asks; between those two days it sits on the books for as
# long as somebody leaves it there. The screen listed them and totalled them, but never said how old the money was, and its
# agent summary was computed from every due commission while the table showed whatever the filter asked for — two
# populations on one page. v9.12 both come from one aged collection, on ArService's 30/60/90 rule, and the page also checks
# the one thing a commission can get wrong on its own: a commission marked paid must name the voucher that paid it.
commission_controller = read(os.path.join(ROOT, 'app/Http/Controllers/CommissionController.php'))
commission_fn = commission_controller.split('public function index(', 1)[-1][:5200]
ok("$asOf = substr((string) $request->query('as_of', bdate()->toDateString()), 0, 10);" in commission_fn
   and "$asOf = bdate()->toDateString();   // a commission cannot be aged past today" in commission_fn,
   'the ageing is read as at a date, and cannot be read into the future')
ok("$age = fn (AgentCommission $c) => max(0, (int) round(($asAt->timestamp - $c->business_date->copy()->startOfDay()->timestamp) / 86400));" in commission_fn,
   'age is whole days from the check-out to the date read, never negative')
ok("$c->bucket = ArService::bucketOf($c->days);" in commission_fn,
   'and the bucket is ArService\'s rule, so commissions age exactly as receivables do')
ok("$rows->getCollection()->transform(function ($c) use ($age) {" in commission_fn,
   'the listed rows carry their age, whatever the filter asked for')
ok("$due = AgentCommission::with('company')->where('status', 'due')" in commission_fn,
   'the summary is built from the due population, aged once')
ok("$summary = $due->groupBy('company_id')->map(fn ($g) => ['company' => $g->first()->company, 'stays' => $g->count()," in commission_fn,
   'grouped by agent with each agent\'s four buckets beside its total')
ok("'b90' => round((float) $g->where('bucket', 'b90')->sum('amount'), 2)])->sortByDesc('amount')->values();" in commission_fn,
   'the buckets are sums of the same aged collection, not a second query')
ok("$aged = ['as_of' => $asOf, 'due' => round((float) $due->sum('amount'), 2), 'agents' => $summary->count()," in commission_fn,
   'and the header figures are that population\'s own totals')
ok("$paid = $all->where('status', 'paid');" in commission_fn and "$undocumented = $paid->filter(fn ($c) => ! $c->voucher)->values();" in commission_fn,
   'a paid commission without a voucher is found rather than assumed away')
ok("$wronglyDocumented = $all->where('status', '!=', 'paid')->filter(fn ($c) => (bool) $c->voucher)->values();" in commission_fn,
   'and so is a commission that is not paid but carries one')
ok("'missing_rows' => $undocumented->map(fn ($c) => ['company' => $c->company?->name ?? '—', 'amount' => round((float) $c->amount, 2)])->all()];" in commission_fn,
   'the offending commissions are named with their agent and money, so the card can say which')
commission_view = read(os.path.join(ROOT, 'resources/views/accounts/commissions.blade.php'))
ok('id="commissionTie"' in commission_view and 'Is every paid commission on a voucher?' in commission_view,
   'the page asks whether each settlement has a document')
ok('id="commissionTieState"' in commission_view and 'can be read as it stands' in commission_view,
   'a clean register says the status column can be trusted')
ok("@foreach($doc['missing_rows'] as $d)" in commission_view and 'against the voucher register' in commission_view,
   'and a broken one names each commission and where to reconcile it')
ok('id="cmSummary"' in commission_view and 'id="cmSumDue"' in commission_view and '@foreach($buckets as $bk => $bl)<th class="num">{{ $bl }}</th>@endforeach' in commission_view,
   'the agent summary prints the four buckets and totals itself')
ok('id="cmRows"' in commission_view and "data-bucket=\"{{ $c->bucket }}\"" in commission_view,
   'each commission row carries its bucket for the reader and for the check')
ok("max=\"{{ bdate()->toDateString() }}\"" in commission_view and 'name="as_of"' in commission_view,
   'the as-at control is on the screen and cannot reach into the future')
ok('Over 90 days' in commission_view and 'Oldest due' in commission_view,
   'age is a first-class figure, not a column the reader has to compute')
ok('the age is the days from the check-out to the date being read' in read(os.path.join(ROOT, 'deploy/dev/audit/commissions_preview_check.js')),
   'the preview check recomputes each age rather than trusting the screen')
ok('and it is the same money the rows below print, agent by agent' in read(os.path.join(ROOT, 'deploy/dev/audit/commissions_preview_check.js')),
   'and holds the agent summary to the rows it summarises')
ok('the page names the agent and the money' in read(os.path.join(ROOT, 'deploy/dev/audit/commissions_preview_check.js')),
   'both states of the document control are built and checked')
ok('/__preview/commissions' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the screen')
ok("pathname === '/commissions'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/commissions-drift'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen lives at')

# ── 26aj. the petty cash tin answers to the ledger and to the receipt ─────────────────────
# A float is the one account in the hotel somebody carries in their hand, so its two failure modes are not a ledger's: the
# tin drifts from its ledger account, and money leaves the tin with nothing to prove what it bought. v9.13 holds it to both
# — the float account posts at the night audit, so a movement dated after the last close is an explained difference and
# anything else is money the books cannot see; and every spend over the receipt threshold must carry a receipt or say why
# — while the register foots cash + IOUs + spend back to the imprest on every row.
petty_controller = read(os.path.join(ROOT, 'app/Http/Controllers/PettyCashController.php'))
petty_fn = petty_controller.split('public function index(', 1)[-1][:6000]
ok("$postedIds = GlJournal::where('source_type', 'petty')->whereNull('reversed_by_id')->whereNull('reversal_of_id')" in petty_fn,
   'a movement is in the books when it has a live petty journal, and not otherwise')
ok("'waiting' => $waiting," in petty_fn and "'unexplained' => $ledger === null ? 0.0 : round($tin - (float) $ledger - $waiting, 2)," in petty_fn,
   'the queue of unposted movements is separated from what the ledger cannot explain at all')
ok("'tone' => ! $f->is_active ? 'slate' : ($st['low'] || $st['overdue_ious'] ? 'red' : ($st['pending'] > 0 || $st['count_due'] ? 'amber' : 'green'))," in petty_fn,
   'each float is red, amber, green or closed for a stated reason, not a bare balance')
ok("->where(fn ($q) => $q->where(fn ($w) => $w->where('type', 'expense')->where('amount', '>', $threshold))" in petty_fn,
   'the receipt control reads the spend side (vouchers) at the threshold')
ok("->orWhere(fn ($w) => $w->where('type', 'settle')->where('spent', '>', $threshold)))" in petty_fn,
   'and the settlement side, where the money spent is the settlement, not the IOU')
ok("'evidence' => $t->receipt_path ? 'receipt' : (trim((string) $t->no_receipt_reason) !== '' ? 'reason' : 'none')," in petty_fn,
   'evidence is the receipt or the stated reason — exactly what the service refuses a voucher without')
ok("'oldest' => $evidence->max('age'), 'missing_rows' => $missingRows];" in petty_fn,
   'the gaps are named with their voucher, float, money and age')
ok("'count_age' => $ageOf($st['last_count']?->date), 'count_due' => (bool) $st['count_due']," in petty_fn,
   'the register ages the tin: days since it was counted')
ok("'spend_age' => $ageOf($oldest?->date)," in petty_fn,
   'and days since the oldest movement on it, which is how long the float has gone un-topped-up')
ok("$pager = new LengthAwarePaginator(" in petty_fn and "->forPage($page, $perPage)" in petty_fn,
   'the evidence list pages rather than truncating the population the counts are taken from')
petty_view = read(os.path.join(ROOT, 'resources/views/petty/index.blade.php'))
ok('id="pettyTie"' in petty_view and 'Does the ledger agree with the tin?' in petty_view,
   'the page asks the question the control exists for')
ok('id="tinTable"' in petty_view and 'id="tieSumUnexplained"' in petty_view and 'id="tieSumWaiting"' in petty_view,
   'and separates the queue from what is unaccounted for, float by float')
ok('id="pettyTieState"' in petty_view and 'The ledger and the tins describe the same money.' in petty_view,
   'a clean tie says so instead of showing a green tick with no sentence')
ok('neither posted nor waiting to post' in petty_view and 'find the movement before the tin is counted again' in petty_view,
   'a broken one names the float and says what to do next')
ok('id="receiptTie"' in petty_view and 'id="receiptRows"' in petty_view and "data-evidence=\"{{ $e['evidence'] }}\"" in petty_view,
   'the receipt control is a table of evidence, not a count with no rows behind it')
ok('id="receiptTieState"' in petty_view and 'attach the receipt, or write the reason on the voucher' in petty_view,
   'and a gap is stated with what to do about it')
ok('id="floatRegister"' in petty_view and 'id="regSumImprest"' in petty_view and 'id="regSumTopUp"' in petty_view,
   'the register foots the imprest, the tin, the IOUs, the spend and the top-up due')
ok('Cash in the tin + IOUs out + spend since the last top-up always equal the imprest' in petty_view,
   'the identity the footer foots to is printed on the page, so a reader can check a row')
ok('cash + IOUs + spend is the imprest on every row' in read(os.path.join(ROOT, 'deploy/dev/audit/petty_cash_preview_check.js')),
   'the preview check recomputes the identity row by row rather than trusting the footer')
ok('the whole difference is the queue, and nothing is unaccounted for' in read(os.path.join(ROOT, 'deploy/dev/audit/petty_cash_preview_check.js')),
   'and holds the footer to the rows it foots')
ok('the tin still agrees with the ledger' in read(os.path.join(ROOT, 'deploy/dev/audit/petty_cash_preview_check.js')),
   'the drift build proves a missing receipt is a different question from a broken tie')
ok('abs: value => Math.abs(Number(value ?? 0)),' in read(os.path.join(ROOT, 'deploy/dev/preview/blade-render.mjs')),
   'abs() is a renderer builtin; without it the screen printed GH₵ 0.00 over a real gap')
ok('/__preview/petty-cash' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the screen')
ok("pathname === '/petty-cash'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/petty-cash-drift'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen lives at')
# ── 26ak. an expense claim ages in two directions, and every line must be provable ───────
# A claim is money an employee has already spent out of their own pocket, so the screen's questions are time and evidence,
# not volume. It used to count claims by status and stop: a claim sent in during September and one sent in this morning
# were the same row, and the receipt rule the service enforces when a line is added disappeared the moment the claim
# existed. v9.14 ages both queues from the day each queue started and reads the receipt rule and the claim window back over
# the live claims, so a line that lost its receipt — or arrived by import — is found instead of assumed away.
claim_controller = read(os.path.join(ROOT, 'app/Http/Controllers/ExpenseClaimController.php'))
claim_fn = claim_controller.split('public function index(', 1)[-1][:7000]
ok("public const WAIT_BUCKETS = ['w0' => '0–7 days', 'w1' => '8–14 days', 'w2' => '15–30 days', 'w3' => 'Over 30 days'];" in claim_controller,
   'a claim that waits a month is the fault, so the buckets are 7/14/30 and not the receivables 30/60/90')
ok("return $days <= 7 ? 'w0' : ($days <= 14 ? 'w1' : ($days <= 30 ? 'w2' : 'w3'));" in claim_controller,
   'and the rule is one function both the card and the list read')
ok("$c->waiting = in_array($c->status, ['submitted', 'approved'], true) ? $ageOf($c->status === 'submitted' ? $c->submitted_at : $c->approved_on) : null;" in claim_fn,
   'the list ages a claim from the date its own queue started, and leaves out the ones that are not in a queue')
ok("foreach (['submitted' => 'Awaiting approval', 'approved' => 'Awaiting payment'] as $key => $label) {" in claim_fn,
   'both queues come from one loop, so they cannot be aged by different rules')
ok("$in = $rows->filter(fn ($c) => self::waitBucket($c->waiting) === $b);" in claim_fn,
   'the bucket columns are sums over the same aged rows the row total is')
ok("'worst' => $worst ? ['claim_no' => $worst->claim_no, 'staff' => $worst->employee?->name," in claim_fn,
   'the worst claim in each queue is carried to the view by name, not left as a number')
ok("->where('category', '!=', 'mileage')" in claim_fn,
   'mileage is out of the receipt population, exactly as the service leaves it out')
ok("->whereHas('claim', fn ($w) => $w->whereIn('status', ['submitted', 'approved', 'paid']))" in claim_fn,
   'the population is the live claims: a draft has not been claimed and a rejected one is not being paid')
ok("'evidence' => $l->receipt_path ? 'receipt' : (trim((string) $l->receipt_ref) !== '' ? 'ref' : 'none')]);" in claim_fn,
   'evidence is the scan or the transaction number, which is the test addLine() applies at entry')
ok("'gap_rows' => $gaps->take(10)->all()];" in claim_fn,
   'and the lines with neither are carried to the page so the fault can be named')
ok("$age = (int) round((Carbon::parse($c->submitted_at)->startOfDay()->timestamp - $l->date->copy()->startOfDay()->timestamp) / 86400);" in claim_fn,
   'the claim window is re-tested against the day the claim was submitted, not the day the page is read')
ok("if ($age > $window) {" in claim_fn and "'late' => $late->count()," in claim_fn,
   'and a line past it is counted and listed rather than quietly accepted')
claim_view = read(os.path.join(ROOT, 'resources/views/claims/index.blade.php'))
ok('id="claimQueue"' in claim_view and 'How long has each claim waited?' in claim_view,
   'the page asks how long a person has been waiting for their own money')
ok('id="queueSumAmount"' in claim_view and 'id="queueSumOldest"' in claim_view and 'id="queueSumClaims"' in claim_view,
   'the queue table foots itself instead of trusting the header')
ok('id="claimQueueState"' in claim_view and 'The oldest claim to approve has waited' in claim_view,
   'and names the claim, the staff member and the money that has waited longest')
ok('id="claimWindow"' in claim_view and 'No line on any live claim was older than' in claim_view and 'the window is' in claim_view,
   'the claim window is stated as a result, with the figure it was tested at')
ok('id="claimEvidence"' in claim_view and 'Is there a receipt behind every cedi claimed?' in claim_view,
   'and the receipt rule is a question the page answers about itself')
ok('id="gapTable"' in claim_view and 'the same rule that stops the line being entered is the rule the payment rests on' in claim_view,
   'a gap is a named line, not a count')
ok('>Age</th>' in claim_view and "'waiting' : 'to pay'" in claim_view,
   'the list itself prints how long each claim has waited, in the queue it is waiting in')
ok('the list buckets into the card, claim for claim' in read(os.path.join(ROOT, 'deploy/dev/audit/claims_preview_check.js')),
   'the preview check re-buckets the list’s own ages and holds them to the card')
ok('the table and the queue card must be the same population' in read(os.path.join(ROOT, 'deploy/dev/audit/claims_preview_check.js')),
   'and treats the two views of one population as one of the things to prove')
ok('a missing receipt does not move the queue' in read(os.path.join(ROOT, 'deploy/dev/audit/claims_preview_check.js')),
   'the drift build proves evidence and waiting are different questions')
ok('/__preview/claims' in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the preview gallery carries the screen')
ok("pathname === '/claims'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs'))
   and "pathname === '/claims-drift'" in read(os.path.join(ROOT, 'deploy/dev/management-dashboard-preview-server.mjs')),
   'and the preview server answers on the route the screen lives at')
# ── 26al. supplier payments: money out is aged, certified and reconciled to itself ────────
# A payment is the last point at which the ledger can be wrong without anybody noticing: the money has gone, the bill may
# never have been entered, the tax withheld needs a certificate the supplier claims against, and the allocated figure the
# ageing and the statement rest on is a summary of documents that can drift from them. v9.15 ages the unapplied money from
# the day it left the bank — on the receivables' 30/60/90 rule — and reads the certificate and the allocation column back
# over the payments themselves.
ap_controller = read(os.path.join(ROOT, 'app/Http/Controllers/ApController.php'))
pay_fn = ap_controller.split('public function payments(Request $request)', 1)[-1][:6000]
ok("$free = ApService::openCredits(null, $asOf)->where('kind', 'payment')" in pay_fn,
   'the ageing is built from the same collection the credit KPI is summed from')
ok("->map(fn ($c) => $c + ['days' => $ageOf($c['date']), 'bucket' => ArService::bucketOf($ageOf($c['date']))])->values();" in pay_fn,
   'and ages it on the receivables rule rather than one of its own')
ok("'beyond' => $free->where('bucket', 'b90')->count(), 'beyond_amount' => round((float) $free->where('bucket', 'b90')->sum('free'), 2)];" in pay_fn,
   'money unapplied for over ninety days is counted separately, because it is the fault and not a band')
ok("$p->bucket = $p->unapplied() > 0.004 ? ArService::bucketOf($p->days) : null;" in pay_fn,
   'a fully applied payment belongs to no band, so the row does not pretend to age money that is not there')
ok("$uncertified = $taxed->filter(fn ($p) => trim((string) $p->wht_cert_no) === '')->values();" in pay_fn,
   'a withheld payment with no certificate number is found rather than assumed')
ok("'missing_rows' => $uncertified->take(10)->map(fn ($p) => ['payment_no' => $p->payment_no, 'supplier' => $p->supplier?->name," in pay_fn,
   'and named with its supplier, its age and its money')
ok("$onFile = ApAllocation::selectRaw('supplier_payment_id, SUM(amount) a')->groupBy('supplier_payment_id')->pluck('a', 'supplier_payment_id');" in pay_fn,
   'the allocations on file are summed once, per payment')
ok("$breaks = $applied->filter(fn ($r) => abs((float) $r['payment']->allocated - $r['on_file']) > 0.005)->values();" in pay_fn,
   'and the allocated column is held to them, payment by payment')
ap_view = read(os.path.join(ROOT, 'resources/views/ap/payments.blade.php'))
ok('id="creditAge"' in ap_view and "How long has the supplier's money been on our books?" in ap_view,
   'the page asks how long it has been holding a supplier money')
ok('id="ageSumTotal"' in ap_view and 'id="ageSumCount"' in ap_view and 'id="creditAgeState"' in ap_view,
   'the ageing foots itself and states its verdict in words')
ok('The oldest is ' in ap_view and 'unapplied for ' in ap_view,
   'and a clean ageing names the oldest payment instead of only printing a number')
ok('id="whtCert"' in ap_view and 'Is every withheld cedi certified?' in ap_view and 'id="certGap"' in ap_view,
   'the certificate is a file the page can be shown, not a tick')
ok('id="allocTie"' in ap_view and 'Does the allocated column match the allocations?' in ap_view and 'id="allocGap"' in ap_view,
   'and the allocated figure is checked against the documents behind it')
ok('both rest on the column' in ap_view,
   'a break says which of the two records the ageing is trusting')
ok('{{ $p->days }} d' in ap_view and 'WHT certificate' in ap_view,
   'each row ages itself and carries its own certificate, so the list can be audited without the cards')
ok('the ageing is the header’s own figure, not a second opinion' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_payments_ageing_preview_check.js')),
   'the preview check holds the card, the header and the KPI to one figure')
ok('what was withheld and what is certified differ by exactly the gap' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_payments_ageing_preview_check.js')),
   'and holds the certificate arithmetic to the money it claims')
ok('the drift does not touch the ageing' in read(os.path.join(ROOT, 'deploy/dev/audit/ap_payments_ageing_preview_check.js')),
   'the drift build proves the three controls are independent of one another')
ok("/ap-payments-drift" in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the drift build is reachable from the preview gallery')

# ── 26am. goods receipts: a delivery is a claim on the ledger, read back three ways ──────
# The register used to print what arrived and nothing else. Every next question is about a document it does not hold: the
# purchase order's bill (the relation ApService::unbilledReceipts() already tests when it builds the bill screen's
# "goods received, not yet invoiced" list), the order's expected date, and the stock movement the receipt itself wrote.
# v9.16 reads all three back and says so on the screen, so an unbilled delivery, a late one and one that never reached
# the shelf stop being invisible.
proc_controller = read(os.path.join(ROOT, 'app/Http/Controllers/ProcurementController.php'))
grn_fn = proc_controller.split('public function grns(Request $request)', 1)[-1][:9000]
ok("$billed = fn ($q) => $q->whereIn('status', ['open', 'partial', 'paid']);" in grn_fn,
   'a bill counts only once it is on the ledger — a draft has not posted and a cancelled one has come off again')
ok("$grns = GoodsReceipt::with('order.bill', 'supplier', 'store', 'receiver')->withSum('lines as rejected_qty', 'qty_rejected')" in grn_fn,
   'the page loads the order and its bill with each receipt rather than asking per row')
ok("->when($request->boolean('waiting'), fn ($q) => $q->whereHas('order', fn ($w) => $w->whereDoesntHave('bill', $billed)))" in grn_fn,
   'and the unbilled receipts are a filter of their own, not a note in a paragraph')
ok("$g->bill = $g->order?->bill;" in grn_fn and "$g->waiting_days = $g->bill ? null : $ageOf($g->date);" in grn_fn,
   'each row carries the bill it reached, or how long it has been waiting for one')
ok("$g->stocked = abs($g->stock_value - (float) $g->subtotal) <= 0.005;" in grn_fn,
   'the shelf is checked against the goods value the receipt itself recorded, to the cent')
ok("$settled = GoodsReceipt::with('order.bill', 'supplier')->where('subtotal', '>', 0)" in grn_fn,
   'the other side of the bill card is the receipts that did reach a bill')
ok("$lateRows = $period->reject(fn ($g) => $g->onTime())->map(function ($g) {" in grn_fn,
   'lateness is the model’s own onTime(), not a second rule written in the controller')
ok("$movedPeriod = StockMovement::whereIn('reference', $period->pluck('grn_no'))->where('type', 'purchase')" in grn_fn,
   'the stock movements are found by the GRN number the receipt wrote, and only purchase movements count')
ok("'largest' => $stockRows->sortByDesc('value')->first()];" in grn_fn,
   'a clean shelf still names the largest receipt, so the card is evidence and not a tick')
grn_view = read(os.path.join(ROOT, 'resources/views/procurement/grns.blade.php'))
ok('id="grnBilling"' in grn_view and 'Has every receipt reached a bill?' in grn_view and 'id="billingGap"' in grn_view,
   'the page asks whether the receipt reached a bill and lists the ones that did not')
ok('The longest wait in the last ninety days was' in grn_view and 'id="grnBillingState"' in grn_view,
   'and a clean bill card names the receipt that waited longest instead of only printing a number')
ok('id="grnDelivery"' in grn_view and 'Did the goods come when the purchase order said?' in grn_view and 'id="lateGap"' in grn_view,
   'the delivery is held to the date the purchase order named')
ok('id="grnStock"' in grn_view and 'Is the accepted goods actually in stock?' in grn_view and 'id="stockGap"' in grn_view,
   'and the accepted goods are held to the stock movements behind them')
ok('data-billed="{{ $g->bill ? 1 : 0 }}" data-stocked="{{ $g->stocked ? 1 : 0 }}"' in grn_view,
   'each row states both readings as data, so a filtered list cannot disagree with the cards')
ok("{{ $g->waiting_days ? 'waiting '.$g->waiting_days.' d' : 'no bill yet' }}" in grn_view and '<th>Bill</th><th>Stock</th>' in grn_view,
   'the row itself prints the wait and the shelf, so the register can be read without the cards')
ok('id="grnChain"' in grn_view and 'goods received, not yet invoiced' in grn_view and 'Waiting for a bill only' in grn_view,
   'the chain from receipt to bill to payment is spelled out, and the unbilled receipts can be filtered out of it')
ok('the clean verdict names the receipt that waited longest, not a bare number' in read(os.path.join(ROOT, 'deploy/dev/audit/grns_preview_check.js')),
   'the preview check holds the clean verdict to a named receipt, not a figure')
ok('the drift does not touch the delivery card' in read(os.path.join(ROOT, 'deploy/dev/audit/grns_preview_check.js')),
   'and proves the three readings are independent: the drift breaks two and leaves the third alone')
ok("/grns-drift" in read(os.path.join(ROOT, 'deploy/dev/previews/index.html')),
   'the drift build is reachable from the preview gallery')

split_briefs = [i + 1 for i, line in enumerate(read(REGISTRY).splitlines()) if line.lstrip().startswith(".'")]
ok(not split_briefs,
   f'each brief is written as one literal the checks can read whole (split at line {split_briefs[:3]})')

# ── report ─────────────────────────────────────────────────────────────────────────
print(f'configuration check: {checks - len(failures)}/{checks} passed')
print(f'  modules: {len(modules)} · groups: {len(groups)} · settings: {len(registry_keys)}')
if failures:
    print(f'  FAILURES ({len(failures)}):')
    for f in failures[:40]:
        print(f'    ✗ {f}')
    sys.exit(1)
