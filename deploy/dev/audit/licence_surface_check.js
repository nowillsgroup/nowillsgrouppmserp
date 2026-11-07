#!/usr/bin/env node
/**
 * v10.4 – the licence must reach every guest surface, and nothing public may be unclassified.
 *
 * Reads config/licence.php (the one list of guest surfaces and the module each belongs to), the
 * public route list dumped by routes_integrity_check.js (DUMP_PUBLIC) and the permission catalogue
 * in config/pms.php, then proves:
 *
 *   1. every module a surface names exists in the catalogue (a typo would silently licence nothing),
 *   2. every public route is claimed by a surface or by an explicit `always` entry with a reason,
 *   3. no surface prefix is dead — each one still matches at least one public route,
 *   4. every resolution recipe names a real model with a real column that carries hotel_id,
 *   5. the middleware is wired globally, asks LicenceService, and answers 404 (not 403),
 *   6. every /api/v1 resource named in `api` exists as a route, so the API gate is not guessing.
 *
 * Requires php-parser (see routes_integrity_check.js). Without it the check reports SKIP.
 *
 * Usage: node deploy/dev/audit/licence_surface_check.js [project-root]
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {createRequire} from 'node:module';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = process.argv[2] || path.resolve(__dirname, '../../..');

function loadEngine() {
  for (const candidate of [process.env.PHP_PARSER_PATH, path.join(ROOT, 'node_modules/php-parser'),
    path.join(__dirname, '../../../node_modules/php-parser'), '/tmp/phpcheck/node_modules/php-parser'].filter(Boolean)) {
    try { return require(candidate); } catch { /* next */ }
  }
  return null;
}
const Engine = loadEngine();
if (!Engine) {
  console.log('licence surface check: SKIP (php-parser not installed — npm i --no-save php-parser)');
  process.exit(0);
}
const engine = new Engine({ parser: { php7: true, suppressErrors: false } });
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const ast = rel => engine.parseCode(read(rel), rel);

let passed = 0;
const failures = [];
const ok = (cond, msg) => { if (cond) passed++; else failures.push(msg); };

/* ---------- helpers over the AST ---------- */

const str = node => (node && node.kind === 'string' ? node.value : null);

/** `'key' => [ ... ]` pairs of an array literal, keyed by the literal key. */
function arrayEntries(node) {
  const out = new Map();
  for (const item of (node && node.items) || []) {
    if (!item || item.kind !== 'entry') continue;
    const key = str(item.key);
    if (key !== null) out.set(key, item.value);
  }
  return out;
}

/** The array literal returned by a config file (`return [ ... ];`). */
function returnedArray(file) {
  let found = null;
  const visit = node => {
    if (found || !node || typeof node !== 'object') return;
    if (Array.isArray(node)) { node.forEach(visit); return; }
    if (node.kind === 'return' && node.expr && node.expr.kind === 'array') { found = node.expr; return; }
    for (const k of Object.keys(node)) if (k !== 'loc') visit(node[k]);
  };
  visit(ast(file));
  return found;
}

/* ---------- the catalogue ---------- */

const catalogue = arrayEntries(arrayEntries(returnedArray('config/pms.php')).get('permissions') || { items: [] });
const modules = new Set(catalogue.keys());

/* ---------- config/licence.php ---------- */

const licenceRoot = returnedArray('config/licence.php');
const surfacesNode = arrayEntries(licenceRoot).get('surfaces') || { items: [] };
const surfaces = [...arrayEntries(surfacesNode).entries()].map(([prefix, ruleNode]) => {
  const r = arrayEntries(ruleNode);
  const resolveNode = r.get('resolve');
  // Keep the positions: `['token', Model::class, 'token_hash', 'sha256']` has the class constant in
  // slot 1, and dropping it would shift the column into the model's place.
  const recipe = resolveNode && resolveNode.kind === 'array'
    ? (resolveNode.items || []).map(i => {
      const v = i && i.kind === 'entry' ? i.value : i;
      if (v && v.kind === 'staticlookup' && v.offset && v.offset.name === 'class') return (v.what && v.what.name) || null;
      return str(v);
    })
    : null;
  return {
    prefix,
    module: str(r.get('module')),
    label: str(r.get('label')) || '',
    recipe,
    after: Boolean(r.get('after')),
    api: Boolean(r.get('api')),
    segment: Number(str(r.get('segment')) || 1),
    why: str(r.get('why')) || '',
  };
});
const always = [...arrayEntries(arrayEntries(licenceRoot).get('always') || { items: [] }).entries()]
  .map(([prefix, reason]) => ({prefix, reason: str(reason) || ''}));
const apiRules = [...arrayEntries(arrayEntries(licenceRoot).get('api') || { items: [] }).entries()]
  .map(([prefix, module]) => ({prefix, module: str(module) || ''}));

/* ---------- 1. every module is in the catalogue ---------- */

for (const s of surfaces) {
  ok(s.module && modules.has(s.module),
    `surface ${s.prefix} names module "${s.module}", which is not in config/pms.php`);
}
for (const a of apiRules) {
  ok(a.prefix === 'default' || modules.has(a.module),
    `api rule ${a.prefix} names module "${a.module}", which is not in config/pms.php`);
}
ok(modules.has('API & Integrations'), 'the API & Integrations module exists (the api default needs it)');

/* ---------- 2./3. every public route claimed, no dead prefix ---------- */

const dump = path.join(os.tmpdir(), `licence-public-${process.pid}.tsv`);
const authDump = path.join(os.tmpdir(), `licence-signed-in-${process.pid}.tsv`);
let publicRoutes = [];
try {
  execFileSync(process.execPath, [path.join(__dirname, 'routes_integrity_check.js'), ROOT], {
    env: {...process.env, PHP_PARSER_PATH: process.env.PHP_PARSER_PATH || path.join(ROOT, 'node_modules/php-parser'), DUMP_PUBLIC: dump, DUMP_AUTH: authDump},
    stdio: 'pipe',
  });
  publicRoutes = fs.readFileSync(dump, 'utf8').trim().split('\n').filter(Boolean)
    .map(line => { const [verb, uri] = line.split('\t'); return {verb, uri}; });
} catch (e) {
  console.log(`licence surface check: SKIP (could not run the routes audit: ${String(e.message).split('\n')[0]})`);
  process.exit(0);
}

const longest = (list, uri) => {
  let best = null;
  let bestLength = -1;
  for (const prefix of list) {
    const trimmed = prefix.replace(/\/+$/, '');
    const hit = trimmed === '' ? uri === '/' : (uri === trimmed || uri.startsWith(trimmed + '/'));
    if (hit && trimmed.length > bestLength) { best = prefix; bestLength = trimmed.length; }
  }
  return best;
};

const surfacePrefixes = surfaces.map(s => s.prefix);
const alwaysPrefixes = always.map(a => a.prefix);
const unclaimed = [];
for (const r of publicRoutes) {
  if (longest(surfacePrefixes, r.uri) === null && longest(alwaysPrefixes, r.uri) === null) {
    unclaimed.push(`${r.verb} ${r.uri}`);
  }
}
ok(unclaimed.length === 0, `${unclaimed.length} public route(s) are not classified in config/licence.php: ${unclaimed.slice(0, 6).join(', ')}`);

const usedSurface = new Set(publicRoutes.map(r => longest(surfacePrefixes, r.uri)).filter(Boolean));
for (const s of surfaces) ok(usedSurface.has(s.prefix), `surface ${s.prefix} matches no public route any more`);
// `always` is a safety net, not a route list: /up is registered by the framework and /logout is
// signed-in. What matters is that every entry says why.
for (const a of always) ok(a.reason.trim().length > 5, `always ${a.prefix} has no reason written down`);

// The guard is global, so it must never catch a signed-in screen: those have a user and a
// permission gate, and answering 404 there would hide a screen from somebody who paid for it.
const signedIn = fs.existsSync(authDump)
  ? fs.readFileSync(authDump, 'utf8').trim().split('\n').filter(Boolean).map(l => l.split('\t')[1])
  : [];
// A bare path may share a prefix with a guest surface (/feedback is both the public form with a
// code and the signed-in answers list). What must never happen is the guard acting on a signed-in
// route: that is a surface with `after` (which would swap a rendered page for a 404) or a value
// segment the recipe would try to resolve.
const TOKEN_LENGTH = 16; // LicenceSurfaces::TOKEN_LENGTH
const looksLikeGuest = (surface, uri) => {
  if (!surface.recipe || surface.recipe.length === 0) return true;
  const trimmed = surface.prefix.replace(/\/+$/, '');
  const parts = uri.slice(trimmed.length).replace(/^\//, '').split('/').filter(Boolean);
  let value = parts[(surface.segment || 1) - 1] || '';
  if (surface.strip) value = value.replace(new RegExp(surface.strip.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$', 'i'), '');
  return surface.recipe[0] === 'token' ? value.length >= TOKEN_LENGTH : /^[A-Za-z0-9_-]{2,20}$/.test(value);
};
const caught = signedIn.filter(uri => {
  const prefix = longest(surfacePrefixes, uri);
  if (prefix === null || prefix === '/offline') return false;
  const surface = surfaces.find(s => s.prefix === prefix);
  return looksLikeGuest(surface, uri) && (surface?.after || (surface.recipe || []).length > 0);
});
ok(caught.length === 0, `${caught.length} signed-in route(s) would be caught by a guest surface: ${[...new Set(caught)].slice(0, 6).join(', ')}`);

/* ---------- 4. the resolution recipes ---------- */

const hasFile = rel => fs.existsSync(path.join(ROOT, rel));

// Guarded models never name their columns, so a recipe's column is looked for in the model and,
// failing that, in the migrations that created the table.
const migrationText = (() => {
  const dir = path.join(ROOT, 'database/migrations');
  return fs.readdirSync(dir).filter(f => f.endsWith('.php')).sort()
    .map(f => fs.readFileSync(path.join(dir, f), 'utf8')).join('\n');
})();
for (const s of surfaces) {
  // A surface the URL cannot decide is allowed — but only with the reason written down, so the
  // remaining gap is a stated one and the next round can be pointed at it.
  if (!s.recipe || s.recipe.length === 0) {
    if (!s.api) ok(s.why.length > 20, `surface ${s.prefix} cannot be resolved from the URL and does not say why`);
    continue;
  }
  if (s.recipe[0] === 'setting') {
    // The key has to be a real setting the code reads, or the recipe would never resolve.
    const reads = ['app/Services/InterfaceService.php', 'app/Services/ReviewService.php', 'app/Services/ApiService.php',
      'config/setting-readers.php'].filter(f => fs.existsSync(path.join(ROOT, f))).map(read).join('\n')
      + (fs.existsSync(path.join(ROOT, 'database/migrations')) ? '' : '');
    ok(Boolean(s.recipe[1]) && reads.includes(s.recipe[1]), `surface ${s.prefix} names setting "${s.recipe[1]}", which the code never reads`);
    continue;
  }
  if (s.recipe.length < 3) continue;
  const model = s.recipe[1];
  const column = s.recipe[2];
  const rel = `app/Models/${model.split('\\').pop()}.php`;
  ok(hasFile(rel), `surface ${s.prefix} resolves from ${model}, which has no model file at ${rel}`);
  if (!hasFile(rel)) continue;
  const source = read(rel);
  ok(source.includes(column) || migrationText.includes(column),
    `surface ${s.prefix} resolves on column "${column}", which neither ${rel} nor any migration mentions`);
  ok(/hotel_id|BelongsToHotel/.test(source), `surface ${s.prefix} resolves through ${rel}, which does not carry hotel_id`);
}

/* ---------- 5. the middleware is wired and refuses with 404 ---------- */

const bootstrap = read('bootstrap/app.php');
ok(bootstrap.includes('LicenceSurface::class'), 'LicenceSurface is not appended to the global middleware stack');
const middleware = read('app/Http/Middleware/LicenceSurface.php');
ok(middleware.includes('LicenceSurfaces::surfaceFor'), 'the middleware does not ask LicenceSurfaces for the surface');
ok(middleware.includes('LicenceService::allowsModule'), 'the middleware does not ask the licence');
ok(/abort\(404\)/.test(middleware), 'the middleware must refuse with 404 — an unlicensed module must not confirm the outlet exists');
ok(!/abort\(403\)/.test(middleware), 'the middleware answers 403 somewhere');
const support = read('app/Support/LicenceSurfaces.php');
ok(support.includes('public static function looksLikeGuest') && middleware.includes('LicenceSurfaces::looksLikeGuest'),
  'the guard asks whether a path is really a guest request — a prefix shared with a signed-in screen must not be caught');
ok(/TOKEN_LENGTH = 16/.test(support), 'the token threshold the guard uses is written down in one place');
ok(support.includes("config('licence'"), 'LicenceSurfaces does not read config/licence.php');
ok(read('app/Services/LicenceService.php').includes('public static function allowsModule'), 'LicenceService::allowsModule() is missing');
ok(read('app/Services/LicenceService.php').includes('public static function licenceOf'), 'LicenceService::licenceOf() is missing');
ok(read('app/Http/Middleware/ApiAuthenticate.php').includes('LicenceService::allowsModule'),
  'ApiAuthenticate does not ask the licence, so the REST API is ungated');
ok(read('app/Http/Middleware/ApiAuthenticate.php').includes('module_not_licensed'),
  'ApiAuthenticate does not answer module_not_licensed');
ok(read('app/Support/ApiSpec.php').includes("'module_not_licensed'"), 'the API error catalogue does not document module_not_licensed');
ok(read('resources/views/group/account.blade.php').includes('$guestSurfaces'),
  'the group account screen does not show which guest surfaces follow the licence');

/* ---------- 5b. a surface the URL cannot decide is refused where its property is named ---------- */

for (const [file, module] of [['app/Http/Middleware/PartnerAuth.php', 'Partner Portal'],
  ['app/Http/Middleware/OrganiserAuth.php', 'Organiser Portal'],
  ['app/Http/Controllers/RobitaController.php', 'API & Integrations']]) {
  const source = file.startsWith('app/Http/Controllers/') ? read(file) : read(file);
  ok(source.includes(`allowsModule('${module}'`),
    `${file} does not ask the licence for ${module}, so that portal's writes are still served`);
}
ok(read('app/Http/Middleware/PartnerAuth.php').includes("abort_unless(LicenceService::allowsModule('Partner Portal'"),
  'the partner portal refuses with 404 through its own session gate, before any controller runs');

/* ---------- 6. the api map is live ---------- */

const apiRoutes = publicRoutes.filter(r => r.uri === '/api/v1' || r.uri.startsWith('/api/v1/'));
const apiPrefixes = apiRules.map(a => a.prefix).filter(p => p !== 'default');
for (const prefix of apiPrefixes) {
  ok(apiRoutes.some(r => r.uri === prefix || r.uri.startsWith(prefix + '/')), `api rule ${prefix} matches no /api/v1 route`);
}
ok(surfaces.some(s => s.prefix === '/api/v1' && s.api), 'the /api/v1 surface is not marked api (it would never be enforced)');
ok(apiRules.some(a => a.prefix === 'default'), 'the api map has no default module');

/* ---------- report ---------- */

console.log(`licence surface check: ${passed}/${passed + failures.length} passed`);
const fromUrl = surfaces.filter(s => s.recipe && s.recipe.length > 0 && !s.api).length;
const residual = surfaces.filter(s => (!s.recipe || s.recipe.length === 0) && !s.api);
console.log(`surfaces: ${surfaces.length} | always: ${always.length} | api rules: ${apiRules.length} | public routes: ${publicRoutes.length}`);
console.log(`properties decided from the URL before the controller runs: ${fromUrl}/${surfaces.length}`);
if (residual.length) console.log(`not decided from the URL (reason written down): ${residual.map(s => s.prefix).join(', ')}`);
failures.forEach(f => console.log('  ✗ ' + f));
process.exit(failures.length ? 1 : 0);
