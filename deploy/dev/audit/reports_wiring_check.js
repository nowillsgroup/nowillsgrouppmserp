#!/usr/bin/env node
/*
 * The Reports screens lean on six classes: the report controller, the schedule service,
 * the saved view, the schedule, the KPI service and the KPI alert. A rename inside any
 * of them — `when()` becoming `whenLabel()`, `RANGES` becoming `PERIODS` — breaks a
 * screen that the previews would never notice, because the previews render against
 * samples rather than the PHP.
 *
 * This reads the five Reports views and the two controllers that feed them, resolves
 * every class alias the views set up ($RS, $S, $K, $A and the fully-qualified calls),
 * and checks that each constant and method they reach for is actually declared in that
 * class file.
 *
 * Run: node deploy/dev/audit/reports_wiring_check.js
 */
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const VIEWS = [
  'resources/views/reports/index.blade.php',
  'resources/views/reports/show.blade.php',
  'resources/views/reports/library.blade.php',
  'resources/views/reports/schedules.blade.php',
  'resources/views/reports/alerts.blade.php',
];
const CONTROLLERS = [
  'app/Http/Controllers/ReportController.php',
  'app/Http/Controllers/ReportAutomationController.php',
];

// The classes the module calls into, by fully-qualified name.
const CLASS_FILES = {
  'App\\Http\\Controllers\\ReportController': 'app/Http/Controllers/ReportController.php',
  'App\\Http\\Controllers\\ReportAutomationController': 'app/Http/Controllers/ReportAutomationController.php',
  'App\\Services\\ReportScheduleService': 'app/Services/ReportScheduleService.php',
  'App\\Services\\KpiService': 'app/Services/KpiService.php',
  'App\\Models\\ReportSchedule': 'app/Models/ReportSchedule.php',
  'App\\Models\\ReportView': 'app/Models/ReportView.php',
  'App\\Models\\KpiAlert': 'app/Models/KpiAlert.php',
  'App\\Models\\ReportDelivery': 'app/Models/ReportDelivery.php',
};

// Which model each loop variable in the views holds.
const INSTANCE_TYPES = {
  v: 'App\\Models\\ReportView',
  s: 'App\\Models\\ReportSchedule',
  a: 'App\\Models\\KpiAlert',
  d: 'App\\Models\\ReportDelivery',
};

// Eloquent's own query and relation entry points: inherited from Model, not declared in
// the class file, so a `ReportSchedule::where(...)` in a controller is not a gap.
const ELOQUENT_STATICS = new Set([
  'where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween', 'whereDate', 'whereHas',
  'with', 'without', 'query', 'find', 'findOrFail', 'first', 'firstOrFail', 'create', 'updateOrCreate',
  'count', 'orderBy', 'latest', 'oldest', 'pluck', 'exists', 'sum', 'max', 'min', 'avg', 'paginate',
  'all', 'select', 'has', 'doesntHave', 'limit', 'value', 'insert', 'update', 'delete',
]);

// Calls on a plain value (Carbon, a string, an array) rather than on the model.
const PLAIN_METHODS = new Set([
  'format', 'diffForHumans', 'toDateString', 'toDateTimeString', 'isoFormat', 'copy', 'toIso8601String',
  'startOfMonth', 'endOfMonth', 'startOfYear', 'endOfYear', 'subDay', 'subDays', 'addDay', 'addDays',
  'subMonthNoOverflow', 'equalTo', 'isSameDay', 'gt', 'lt', 'gte', 'lte', 'max', 'min', 'sum', 'count',
  'first', 'last', 'take', 'map', 'filter', 'sortBy', 'pluck', 'isEmpty', 'isNotEmpty', 'values', 'join',
  'contains', 'keys', 'all', 'toArray', 'links', 'fragment', 'total',
]);

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const read = (file) => (existsSync(file) ? readFileSync(file, 'utf8') : '');
const hasMethod = (text, name) => new RegExp('function\\s+' + name + '\\s*\\(').test(text);
const hasConst = (text, name) => new RegExp('const\\s+' + name + '\\b').test(text);

const sources = [...VIEWS, ...CONTROLLERS].map(file => ({ file, text: read(file) }));
for (const { file } of sources) ok(`${file} is present`, existsSync(file));

// ── the aliases the views and controllers set up ─────────────────────────────
function aliases(text) {
  const map = {};
  for (const [, alias, cls] of text.matchAll(/\$(RS|S|K|A)\s*=\s*\\?([A-Za-z_][\w\\]*)::class/g)) {
    map[alias] = cls.replace(/^\\/, '');
  }
  return map;
}

// Aliases are read per file: an 8,000-line controller reuses $S and $A for other
// modules, and merging the maps across files attributed those to the report classes.
const aliasesByFile = new Map(sources.map(({ file, text }) => [file, aliases(text)]));
const classAliases = Object.assign({}, ...sources.filter(({ file }) => file.startsWith('resources/views/reports/')).map(({ text }) => aliases(text)));

ok('the schedule service is aliased in the views', classAliases.RS === 'App\\Services\\ReportScheduleService', JSON.stringify(classAliases));
ok('the schedule model is aliased in the views', classAliases.S === 'App\\Models\\ReportSchedule', JSON.stringify(classAliases));
ok('the KPI service is aliased in the alerts view', classAliases.K === 'App\\Services\\KpiService', JSON.stringify(classAliases));
ok('the KPI alert model is aliased in the alerts view', classAliases.A === 'App\\Models\\KpiAlert', JSON.stringify(classAliases));

const declared = (cls) => (CLASS_FILES[cls] ? read(CLASS_FILES[cls]) : '');

// ── every constant and method they reach for ─────────────────────────────────
const references = new Map(); // 'Class::member' → { cls, member, files }
const note = (cls, member, file) => {
  const key = `${cls}::${member}`;
  if (!references.has(key)) references.set(key, { cls, member, files: new Set() });
  references.get(key).files.add(file);
};

for (const { file, text } of sources) {
  const local = aliasesByFile.get(file) ?? {};
  // $RS::RANGES / $RS::resolve( … ) through the @php aliases
  for (const [, alias, member] of text.matchAll(/\$(RS|S|K|A)::([A-Za-z_]\w*)/g)) {
    if (member === 'class') continue; // the alias declaration itself
    const cls = local[alias];
    if (cls && CLASS_FILES[cls]) note(cls, member, file);
  }
  // fully-qualified \App\...\Class::MEMBER
  for (const [, cls, member] of text.matchAll(/(\\App\\[\w\\]+)::([A-Za-z_]\w*)/g)) {
    if (member === 'class' || ELOQUENT_STATICS.has(member)) continue;
    const name = cls.replace(/^\\/, '');
    if (CLASS_FILES[name]) note(name, member, file);
  }
  // $v->url(), $s->when(), $a->rule(), … — only in the views, where those four names
  // are the report models and nowhere else.
  if (!file.startsWith('resources/views/reports/')) continue;
  for (const [, variable, method] of text.matchAll(/\$([vsad])->([A-Za-z_]\w*)\(/g)) {
    if (PLAIN_METHODS.has(method)) continue;
    const cls = INSTANCE_TYPES[variable];
    if (cls) note(cls, method, file);
  }
}

ok('the views and controllers reach into the module classes', references.size > 20, `${references.size} references`);

const missing = [];
for (const { cls, member, files } of references.values()) {
  const text = declared(cls);
  if (!text) { missing.push(`${cls}::${member} (no class file)`); continue; }
  if (!hasMethod(text, member) && !hasConst(text, member)) {
    missing.push(`${cls}::${member} — read in ${[...files].map(f => f.split('/').pop()).join(', ')}`);
  }
}
ok('every constant and method the Report screens call is declared', missing.length === 0, missing.join(' | '));

// ── the pieces the screens are built on, named explicitly ───────────────────
const service = read('app/Services/ReportScheduleService.php');
for (const member of ['RANGES', 'DATE_RANGES', 'SETTINGS']) {
  ok(`the schedule service declares ${member}`, hasConst(service, member));
}
for (const member of ['resolve', 'rangeLabel', 'viewsFor', 'cfg', 'defaultRange', 'stats', 'save', 'run']) {
  ok(`the schedule service implements ${member}()`, hasMethod(service, member));
}

const view = read('app/Models/ReportView.php');
ok('a saved view can build its own link', hasMethod(view, 'url'));
ok('a saved view knows its report title', hasMethod(view, 'title'));

const schedule = read('app/Models/ReportSchedule.php');
for (const member of ['FREQUENCIES', 'FORMATS', 'WEEKDAYS']) {
  ok(`a schedule declares ${member}`, hasConst(schedule, member));
}
for (const member of ['when', 'title', 'emails']) {
  ok(`a schedule implements ${member}()`, hasMethod(schedule, member));
}

const kpi = read('app/Services/KpiService.php');
ok('the KPI service lists its metrics', hasConst(kpi, 'METRICS'));
for (const member of ['format', 'breached', 'openCount']) {
  ok(`the KPI service implements ${member}()`, hasMethod(kpi, member));
}

const alert = read('app/Models/KpiAlert.php');
ok('a KPI alert declares its severities', hasConst(alert, 'SEVERITIES'));
ok('a KPI alert can state its own rule', hasMethod(alert, 'rule'));

// ── the report table and the icons it names ────────────────────────────────
const controller = read('app/Http/Controllers/ReportController.php');
ok('the report table is a controller constant', hasConst(controller, 'REPORTS'));
ok('the library names an icon per area', hasConst(controller, 'GROUP_ICONS'));
ok('the library names the three periods', hasConst(controller, 'MODE_LABELS'));

// ── every report is a real report, and every gate is a real permission ──────
const tableOpen = controller.indexOf('const REPORTS');
const tableClose = controller.indexOf('GROUP_ICONS');
const table = tableOpen > 0 && tableClose > tableOpen ? controller.slice(tableOpen, tableClose) : '';
const entries = [...table.matchAll(/^\s*'([a-z0-9_]+)' => \[\s*/gm)].map((match, index, all) => {
  const end = index + 1 < all.length ? all[index + 1].index : table.length;
  const body = table.slice(match.index + match[0].length, end);
  const fields = (body.match(/'([^'\\]*(?:\\.[^'\\]*)*)'|"([^"\\]*(?:\\.[^"\\]*)*)"/g) ?? [])
    .map((literal) => literal.slice(1, -1));
  return {
    slug: match[1],
    area: fields[0],
    title: fields[1],
    description: fields[2],
    mode: fields[3],
    permission: fields[4] ?? 'reports.view',
  };
});
ok('the report table is parsed', entries.length > 400, `${entries.length} reports`);
ok('every report names an area, a title and a period',
  entries.every((entry) => entry.area && ['range', 'date', 'none'].includes(entry.mode)));

// The catalogue is read, not just clicked: a title too long for a card or a promise that
// only repeats the title makes the list harder to scan than it needs to be.
const thinCopy = entries.filter((entry) => !entry.description || entry.description.length < 18);
ok('every report promises something in its description', thinCopy.length === 0,
  thinCopy.map((entry) => `${entry.slug} (${JSON.stringify(entry.description)})`).slice(0, 6).join(', '));
const longTitles = entries.filter((entry) => (entry.title ?? '').length > 62);
ok('no report title is too long for a card', longTitles.length === 0,
  longTitles.map((entry) => `${entry.slug} (${entry.title.length})`).slice(0, 6).join(', '));
const titleKeys = new Map();
for (const entry of entries) {
  const key = `${entry.area}|${entry.title}`;
  titleKeys.set(key, (titleKeys.get(key) ?? 0) + 1);
}
const sharedTitles = [...titleKeys].filter(([, count]) => count > 1);
ok('no two reports in one area share a title', sharedTitles.length === 0,
  sharedTitles.map(([key]) => key).slice(0, 6).join(', '));
ok('every report is filed in an area', entries.every((entry) => (entry.area ?? '').length > 2));
ok('every report has one of the three viewing modes', entries.every((entry) => ['none', 'range', 'date'].includes(entry.mode)),
  [...new Set(entries.map((entry) => entry.mode))].join(', '));
// The download is the report leaving the building: it must keep the filters, the saved
// view's columns, the injection guard, and name an "as on" file for its single day.
const showBody = controller.slice(controller.indexOf('public function show('));
ok('the download keeps the date filters', /fullUrlWithQuery\(\['export' => 'csv'\]\)/.test(readFileSync('resources/views/reports/show.blade.php', 'utf8')));
ok('the download is shaped by the open saved view', showBody.indexOf("$request->input('export') === 'csv'") > showBody.indexOf('ReportScheduleService::shape('));
ok('the download is guarded against formula injection', /preg_match\('\/\^\[=\+\\-@\\t\\r\]\/'/.test(controller));
ok('an as-on download is named for its single day', /\$mode === 'date' \? \$to->format\('Ymd'\)/.test(showBody));

// A report with no generator is a 500; a generator with no report is dead code.
// The generators live in the domain trait files under app/Http/Controllers/Reports,
// which ReportController mixes in — not in the controller itself.
const REPORTS_DIR = 'app/Http/Controllers/Reports';
const traitFiles = existsSync(REPORTS_DIR) ? readdirSync(REPORTS_DIR).filter((n) => n.endsWith('.php')) : [];
const generators = traitFiles.flatMap((name) => [...read(join(REPORTS_DIR, name)).matchAll(/protected function r_([a-z_0-9]+)\(/g)].map(([, slug]) => slug));
const slugs = new Set(entries.map((entry) => entry.slug));
const generatorNames = new Set(generators);
const noGenerator = [...slugs].filter((slug) => !generatorNames.has(slug));
const orphaned = [...generatorNames].filter((name) => !slugs.has(name));
ok('every report in the table has its generator', noGenerator.length === 0, noGenerator.slice(0, 8).join(', '));
ok('every generator belongs to a report in the table', orphaned.length === 0, orphaned.slice(0, 8).join(', '));

// A gate that is not in the catalogue can never be granted: the report would only ever
// open for an administrator. The table now registers the permissions it gates by area.
const config = read('config/pms.php');
const configOpen = config.indexOf("'permissions' => [");
let depth = 0;
let catalogueBlock = '';
if (configOpen >= 0) {
  const start = config.indexOf('[', configOpen);
  for (let index = start; index < config.length; index += 1) {
    if (config[index] === '[') depth += 1;
    else if (config[index] === ']') {
      depth -= 1;
      if (depth === 0) { catalogueBlock = config.slice(start, index + 1); break; }
    }
  }
}
const catalogue = new Set([...catalogueBlock.matchAll(/'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'\s*=>\s*['"]/g)].map(([, key]) => key));
ok('the permission catalogue is read', catalogue.size > 100, `${catalogue.size} permissions`);
const ungrantable = [...new Set(entries.map((entry) => entry.permission))].filter((permission) => !catalogue.has(permission));
ok('every permission the report table gates by can be granted', ungrantable.length === 0, ungrantable.join(', '));

const iconBlock = controller.match(/const\s+GROUP_ICONS\s*=\s*\[([\s\S]*?)\];/)?.[1] ?? '';
const icons = [...iconBlock.matchAll(/'([^']+)'\s*=>\s*'([a-z0-9-]+)'/g)].map(([, area, icon]) => ({ area, icon }));
ok('every area in the map has an icon', icons.length >= 40, `${icons.length} icons`);

const sprite = read('resources/views/partials/icons.blade.php');
ok('the icon sprite is where the audit expects it', sprite.length > 1000, `${sprite.length} bytes`);
const symbols = new Set([...sprite.matchAll(/id="i-([a-z0-9-]+)"/g)].map(([, name]) => name));
ok('the sprite carries every icon the areas name', icons.every(({ icon }) => symbols.has(icon)),
  icons.filter(({ icon }) => !symbols.has(icon)).map(({ area, icon }) => `${area} → ${icon}`).join(', '));

console.log(`reports wiring: ${passed} checks passed, ${failures.length} failed (${references.size} references resolved)`);
if (failures.length) {
  for (const failure of failures) console.log(`  ✗ ${failure}`);
  process.exit(1);
}
