#!/usr/bin/env node
/**
 * Reports links check — every door into the report module.
 *
 * The catalogue owns 472 slugs. Screens all over the app link into `reports.show`
 * with a slug baked into the blade; `show()` 404s on a slug it does not know, so a
 * renamed report silently turns a button on some other screen into a 404. This
 * walks every call site, resolves the literal slugs against the catalogue, names
 * the call sites whose slug is dynamic (and therefore has to be guarded at the
 * source), and checks the guards that make a stale key safe.
 *
 * Also reports — without failing — links that pass query parameters `show()`
 * does not read, because those land on the right report for the wrong period.
 *
 *   node deploy/dev/audit/reports_links_check.js
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = process.cwd();
const CONTROLLER = 'app/Http/Controllers/ReportController.php';
let passed = 0;
const failures = [];
const notes = [];

const ok = (name, condition, detail = '') => {
  if (condition) passed += 1;
  else failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const read = (rel) => readFileSync(join(ROOT, rel), 'utf8');

// ── the catalogue ───────────────────────────────────────────────────────────
const controller = read(CONTROLLER);
const table = controller.slice(controller.indexOf('const REPORTS'), controller.indexOf('GROUP_ICONS'));
const slugs = [...table.matchAll(/^\s*'([a-z0-9_]+)' => \[\s*/gm)].map((match) => match[1]);
const catalogue = new Set(slugs);
ok('the catalogue is the expected size', slugs.length === 472, `${slugs.length} reports`);
ok('no report slug is registered twice', catalogue.size === slugs.length);

// ── every call site ─────────────────────────────────────────────────────────
const files = [];
const walk = (dir) => {
  for (const name of readdirSync(join(ROOT, dir))) {
    const rel = join(dir, name);
    const stat = statSync(join(ROOT, rel));
    if (stat.isDirectory()) {
      if (!/^(vendor|node_modules|\.git|storage)$/.test(name)) walk(rel);
    } else if (/\.(php)$/.test(name)) files.push(rel);
  }
};
for (const dir of ['app', 'resources', 'routes']) walk(dir);

const literal = [];
const dynamic = [];
const extraParams = new Map();

for (const file of files) {
  const src = read(file);
  if (!src.includes("reports.show")) continue;
  const call = /route\(\s*'reports\.show'\s*,\s*/g;
  let match;
  while ((match = call.exec(src))) {
    // read the second argument up to its closing parenthesis, tracking nesting
    let depth = 0;
    let end = match.index + match[0].length;
    for (; end < src.length; end += 1) {
      const ch = src[end];
      if (ch === '(' || ch === '[') depth += 1;
      else if (ch === ')' || ch === ']') {
        if (depth === 0) break;
        depth -= 1;
      } else if (ch === ',' && depth === 0) break;
    }
    const argument = src.slice(match.index + match[0].length, end).trim();
    const line = src.slice(0, match.index).split('\n').length;
    const named = argument.match(/^\[\s*'report'\s*=>\s*'([a-z0-9_]+)'/);
    const positional = argument.match(/^\[?\s*'([a-z0-9_]+)'/);
    const slug = named ? named[1] : positional ? positional[1] : null;
    if (slug) {
      literal.push({ file, line, slug, argument });
      for (const param of argument.matchAll(/'([a-z_]+)'\s*=>/g)) {
        if (param[1] === 'report') continue;
        const key = `${slug}: ${param[1]}`;
        extraParams.set(key, (extraParams.get(key) ?? 0) + 1);
      }
    } else {
      dynamic.push({ file, line, argument: argument.slice(0, 70) });
    }
  }
}

ok('the app links into the report module', literal.length >= 100, `${literal.length} literal call sites`);

const dangling = literal.filter((site) => !catalogue.has(site.slug));
ok('every link names a report that exists', dangling.length === 0,
  dangling.map((site) => `${site.slug} (${site.file}:${site.line})`).slice(0, 8).join(', '));

// A dynamic slug cannot be resolved statically, so each one is named here with the
// reason it is safe: it comes from a row that was validated against the catalogue.
const expectedDynamic = [
  'app/Models/ReportView.php',
  'resources/views/emails/scheduled-report.blade.php',
  'resources/views/reports/index.blade.php',
  'resources/views/reports/show.blade.php',
];
const dynamicFiles = [...new Set(dynamic.map((site) => site.file))];
ok('dynamic links are the known, guarded ones',
  dynamicFiles.every((file) => expectedDynamic.includes(file)),
  dynamicFiles.filter((file) => !expectedDynamic.includes(file)).join(', '));
ok('dynamic links are few enough to reason about', dynamic.length <= 6, `${dynamic.length} call sites`);

// ── the guards on the dynamic doors ─────────────────────────────────────────
const viewsFor = read('app/Services/ReportScheduleService.php');
ok('a saved view for a report that has gone is filtered out of every list',
  /isset\(ReportController::REPORTS\[\$v->report_key\]\)/.test(viewsFor));

const dashboard = read('resources/views/dashboard.blade.php');
ok('the dashboard pins saved views', /dash-pinned-reports/.test(dashboard));
ok('the dashboard pins come from the filtered list',
  /ReportScheduleService::viewsFor\(auth\(\)->user\(\)\)/.test(dashboard));
ok('a saved view opens itself through its own url()', /\$pv->url\(\)/.test(dashboard));

const viewModel = read('app/Models/ReportView.php');
ok('a saved view falls back to the catalogue title when the report is gone',
  /REPORTS\[\$this->report_key\]\[1\] \?\? \$this->report_key/.test(viewModel));

const mail = read('resources/views/emails/scheduled-report.blade.php');
ok('a scheduled e-mail falls back to the library instead of a 404',
  /isset\(\\App\\Http\\Controllers\\ReportController::REPORTS\[\$schedule->report_key\]\)/.test(mail)
  && /route\('reports\.index'\)/.test(mail));

// ── what the links carry ────────────────────────────────────────────────────
// show() reads from, to, view and export. Anything else a screen passes is dropped
// on the way in, so the report opens on its default period instead of the caller's.
const readParams = new Set(['from', 'to', 'view', 'export']);
const dropped = [...extraParams].filter(([key]) => !readParams.has(key.split(': ')[1]));
if (dropped.length) {
  notes.push(`links pass parameters show() does not read: ${dropped.map(([key, n]) => `${key} (×${n})`).join(', ')}`);
}
ok('most links carry only what show() reads',
  dropped.length <= 2,
  dropped.map(([key]) => key).join(', '));

// ── report ─────────────────────────────────────────────────────────────────
for (const note of notes) console.log(`note: ${note}`);
for (const failure of failures) console.log(`FAIL: ${failure}`);
console.log(`reports links: ${passed} checks passed, ${failures.length} failed (${literal.length} literal links, ${dynamic.length} dynamic call sites)`);
process.exit(failures.length ? 1 : 0);
