#!/usr/bin/env node
/**
 * Static integrity audit for `routes/*.php`.
 *
 * Walks the route-file AST (no Laravel bootstrap, no PHP binary needed beyond the
 * parser) and reports:
 *   - duplicate route names,
 *   - routes whose controller class or method does not exist under app/Http/Controllers,
 *   - string actions with no controller in scope, unresolvable actions,
 *   - authenticated routes with no permission gate (informational).
 * Controller-scoped groups, nested groups, `Route::controller()`, fluent
 * ->name()/->middleware() chains and loop-generated actions are all handled; an
 * action picked from a loop variable is treated as dynamic rather than unknown.
 *
 * Requires the `php-parser` npm package, resolved from $PHP_PARSER_PATH,
 * ./node_modules or /tmp/phpcheck. Without it the check reports SKIP and exits 0.
 *
 * Usage: node deploy/dev/audit/routes_integrity_check.js [project-root]
 *        VERBOSE=1 node …   (list every public route and every gated-route gap)
 */
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const require = createRequire(import.meta.url);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = process.argv[2] || path.resolve(__dirname, '../../..');

function loadEngine() {
  const candidates = [
    process.env.PHP_PARSER_PATH,
    path.join(ROOT, 'node_modules/php-parser'),
    path.join(__dirname, '../../../node_modules/php-parser'),
    '/tmp/phpcheck/node_modules/php-parser',
  ].filter(Boolean);
  for (const candidate of candidates) {
    try {
      return require(candidate);
    } catch (e) {
      /* try the next location */
    }
  }
  return null;
}

const Engine = loadEngine();
if (!Engine) {
  console.log('routes integrity check: SKIP (php-parser not installed — npm i --no-save php-parser)');
  process.exit(0);
}
const engine = new Engine({ parser: { php7: true, suppressErrors: false } });
const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'view', 'redirect'];

const literal = node => (node && node.kind === 'string' ? node.value : null);
const className = node => {
  if (!node) return null;
  if (node.kind === 'staticlookup' && node.offset && node.offset.name === 'class') return (node.what && node.what.name) || null;
  if (node.kind === 'name') return node.name;
  return null;
};
const isStatic = node => node && node.kind === 'staticlookup';
const isCall = node => node && node.kind === 'call';

function usesMap(src) {
  const map = new Map();
  for (const m of src.matchAll(/^use\s+([^;]+);/gm)) {
    const [fq, alias] = m[1].trim().split(/\s+as\s+/);
    map.set((alias || fq.split('\\').pop()).trim(), fq.replace(/^\\/, ''));
  }
  return map;
}

const classMethods = new Map();
(function collect(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) { collect(p); continue; }
    if (!entry.name.endsWith('.php')) continue;
    const src = fs.readFileSync(p, 'utf8');
    let ast;
    try { ast = engine.parseCode(src, p); } catch (e) { continue; }
    let ns = '';
    let cls = null;
    const methods = new Set();
    (function find(node) {
      if (!node || typeof node !== 'object') return;
      if (Array.isArray(node)) { node.forEach(find); return; }
      if (node.kind === 'namespace' && node.name) ns = node.name;
      if (node.kind === 'class' && node.name && !cls) {
        cls = (node.name && typeof node.name === 'object') ? node.name.name : node.name;
        for (const item of (node.body || [])) {
          if (item && item.kind === 'method' && item.name) methods.add(typeof item.name === 'object' ? String(item.name.name) : String(item.name));
        }
      }
      for (const key of Object.keys(node)) {
        if (key === 'loc' || key === 'leadingComments') continue;
        find(node[key]);
      }
    })(ast);
    if (!cls) continue;
    const fqcn = (ns ? ns + '\\' : '') + cls;
    classMethods.set(fqcn, methods);
  }
})(path.join(ROOT, 'app/Http/Controllers'));

function normalise(name, uses) {
  if (!name) return name;
  name = name.replace(/^\\/, '');
  if (uses.has(name)) return uses.get(name);
  const parts = name.split('\\');
  if (uses.has(parts[0])) return [uses.get(parts[0]), ...parts.slice(1)].join('\\');
  if (classMethods.has('App\\Http\\Controllers\\' + name)) return 'App\\Http\\Controllers\\' + name;
  return name;
}

function chain(node) {
  const steps = [];
  let cursor = node;
  while (cursor) {
    if (isCall(cursor) && cursor.what && cursor.what.kind === 'propertylookup') {
      steps.push({ method: cursor.what.offset && cursor.what.offset.name, args: cursor.arguments || [] });
      cursor = cursor.what.what;
      continue;
    }
    if (isCall(cursor) && isStatic(cursor.what)) {
      steps.push({ method: cursor.what.offset && cursor.what.offset.name, args: cursor.arguments || [], root: cursor.what.what && cursor.what.what.name });
      break;
    }
    if (isStatic(cursor)) {
      steps.push({ method: cursor.offset && cursor.offset.name, args: cursor.arguments || [], root: cursor.what && cursor.what.name });
      break;
    }
    break;
  }
  return steps;
}

const RESOURCE_VERBS = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];
const API_RESOURCE_VERBS = ['index', 'store', 'show', 'update', 'destroy'];

/** `foreach (['start', 'dnd'] as $a)` — the literal values a loop variable takes. */
function loopValues(node) {
  if (!node || node.kind !== 'foreach' || !node.source) return null;
  const items = node.source.kind === 'array' ? (node.source.items || []) : null;
  if (!items) return null;
  const values = items.map(i => literal(i && i.kind === 'entry' ? i.value : i)).filter(v => v !== null);
  const target = node.value;
  if (!values.length || !target || target.kind !== 'variable') return null;
  return { name: target.name, values };
}

function literalList(node) {
  if (!node || node.kind !== 'array') return null;
  return (node.items || []).map(i => literal(i && i.kind === 'entry' ? i.value : i)).filter(v => v !== null);
}

/** Approximate the URI Laravel gives a resource action (used for reporting only). */
function uriOf(prefix, base, action) {
  const member = ['show', 'edit', 'update', 'destroy'].includes(action);
  const path = prefix + '/' + base + (action === 'index' || action === 'store' ? '' : '/' + (member ? '{' + base + '}' : action));
  return path.replace(/\/+/g, '/').replace(/\/$/, '');
}

function groupConfig(steps) {
  const cfg = { prefix: '', name: '', middleware: [], controller: null };
  for (const step of [...steps].reverse()) {
    if (step.method === 'prefix') cfg.prefix += '/' + (literal(step.args[0]) || '');
    if (step.method === 'name') cfg.name += literal(step.args[0]) || '';
    if (step.method === 'middleware') cfg.middleware.push(...step.args.flatMap(a => literalList(a) || [literal(a) || 'expr']));
    if (step.method === 'controller') {
      const nm = className(step.args[0]);
      if (nm) cfg.controller = nm;
    }
  }
  return cfg;
}

function auditFile(relFile) {
  const file = path.join(ROOT, relFile);
  const src = fs.readFileSync(file, 'utf8');
  const uses = usesMap(src);
  const routes = [];
  const ast = engine.parseCode(src, file);

  function visit(node, ctx) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) { node.forEach(n => visit(n, ctx)); return; }

    const steps = (isCall(node) || isStatic(node)) ? chain(node) : null;
    if (steps && steps.length) {
      const outer = steps[0];
      // The group step is the one that carries the closure — `Route::group(…)` (the closure is the
      // first arg) or `Route::middleware(…)->prefix(…)->group(fn)` (the closure is the last step's
      // first arg). Matching only `steps[0]` silently dropped the middleware of every group declared
      // middleware-first, which made signed-in screens look public.
      const groupStep = steps.find(st => st.method === 'group' && st.root === 'Route') || (outer.method === 'group' ? outer : null);
      if (groupStep) {
        const cfg = groupConfig(steps);
        visit((groupStep.args || [])[0], {
          prefix: (ctx.prefix + cfg.prefix).replace(/\/+/g, '/'),
          name: ctx.name + cfg.name,
          middleware: [...ctx.middleware, ...cfg.middleware],
          controller: (cfg.controller && normalise(cfg.controller, uses)) || ctx.controller,
          loops: ctx.loops || {},
        });
        return;
      }
      // A registration is either `Route::verb(…)` (the verb step carries the
      // facade) or fluent — `Route::middleware(…)->post(…)`, `Route::name(…)->get(…)`
      // — where the verb sits in the middle of the chain. Both are collected.
      const verbIdx = steps.findIndex((s, i) =>
        VERBS.includes(s.method) && (s.root === 'Route' || i < steps.length - 1));
      const routeStep = verbIdx >= 0 ? steps[verbIdx] : null;
      const isRoute = routeStep && (routeStep.root === 'Route'
        || (steps[verbIdx + 1] && steps[verbIdx + 1].root === 'Route'));
      if (isRoute) {
        const inner = groupConfig(steps.slice(verbIdx + 1));
        const trailing = { name: '', middleware: [] };
        for (const step of steps.slice(0, verbIdx)) {
          if (step.method === 'name') trailing.name += literal(step.args[0]) || '';
          if (step.method === 'middleware') trailing.middleware.push(...step.args.flatMap(a => literalList(a) || [literal(a) || 'expr']));
        }
        const ctxPrefix = (ctx.prefix + inner.prefix).replace(/\/+/g, '/');
        const ctxName = ctx.name + inner.name;
        const ctxMiddleware = [...ctx.middleware, ...inner.middleware];
        const ctxController = (inner.controller && normalise(inner.controller, uses)) || ctx.controller;
        const actionNode = routeStep.args[1];
        let action = null;
        if (actionNode) {
          if (actionNode.kind === 'string') action = { type: 'method', method: actionNode.value };
          else if (actionNode.kind === 'array') {
            const items = (actionNode.items || []).map(e => (e && e.kind === 'entry') ? e.value : e);
            const cls = className(items[0]);
            action = { type: 'array', class: cls ? normalise(cls, uses) : null, method: literal(items[1]) };
          } else if (actionNode.kind === 'closure' || actionNode.kind === 'arrowfunc') action = { type: 'closure' };
          else if (isStatic(actionNode)) action = { type: 'invokable', class: normalise(className(actionNode), uses) };
          else if (actionNode.kind === 'variable') action = { type: 'dynamic' };
          else action = { type: 'other' };
        }
        const uri = (ctxPrefix + '/' + (literal(routeStep.args[0]) || '')).replace(/\/+/g, '/');
        // `->name($a)` where `$a` walks a literal array: one route per value.
        const nameArg = routeStep.args.length ? steps.slice(0, verbIdx).find(st => st.method === 'name') : null;
        const nameNode = nameArg && nameArg.args[0];
        const names = nameNode && nameNode.kind === 'variable' && ctx.loops && ctx.loops[nameNode.name]
          ? ctx.loops[nameNode.name]
          : [trailing.name];
        for (const suffix of names) {
          routes.push({
            file: relFile, verb: routeStep.method, uri: uri.replace(/\/$/, '') || '/',
            name: ctxName + suffix, middleware: [...ctxMiddleware, ...trailing.middleware],
            controller: ctxController, action,
            line: node.loc && node.loc.start ? node.loc.start.line : null,
          });
        }
        return;
      }

      // Route::resource('guests', GuestController::class)->except([…]): the names
      // are generated by the framework, so expand them the way Laravel does.
      const resource = steps.find(st => /^(api)?[Rr]esource$|^(api)?[Ss]ingleton$/.test(st.method || '') && st.root === 'Route');
      if (resource) {
        const base = literal(resource.args[0]);
        const cls = className(resource.args[1]);
        if (base) {
          const api = /^api/.test(resource.method);
          const singleton = /ngleton$/.test(resource.method);
          let verbs = api ? [...API_RESOURCE_VERBS] : [...RESOURCE_VERBS];
          for (const step of steps) {
            if (step === resource) break;
            if (step.method === 'only') verbs = literalList(step.args[0]) || verbs;
            if (step.method === 'except') {
              const drop = literalList(step.args[0]) || [];
              verbs = verbs.filter(v => !drop.includes(v));
            }
          }
          if (singleton) verbs = api ? ['show', 'update', 'destroy'] : ['show', 'edit', 'update'];
          // `Route::resource(…)->middleware('perm:…')` gates every generated action; the chain is
          // walked in reverse, so the calls that follow `resource` sit before it.
          const trailingMw = [];
          for (const step of steps) {
            if (step === resource) break;
            if (step.method === 'middleware') trailingMw.push(...step.args.flatMap(a => literalList(a) || [literal(a) || 'expr']));
          }
          for (const name of verbs) {
            routes.push({
              file: relFile,
              verb: ['index', 'show', 'create', 'edit'].includes(name) ? 'get' : (name === 'destroy' ? 'delete' : (name === 'update' ? 'put' : 'post')),
              uri: uriOf(ctx.prefix, base, name),
              name: ctx.name + base + '.' + name,
              middleware: [...ctx.middleware, ...trailingMw],
              controller: null,
              action: { type: 'resource', class: cls ? normalise(cls, uses) : null, method: name },
              line: node.loc && node.loc.start ? node.loc.start.line : null,
            });
          }
          return;
        }
      }
    }
    const loop = loopValues(node);
    if (loop) {
      visit(node.body, { ...ctx, loops: { ...(ctx.loops || {}), [loop.name]: loop.values } });
      return;
    }
    for (const key of Object.keys(node)) {
      if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') continue;
      visit(node[key], ctx);
    }
  }

  visit(ast, { prefix: '', name: '', middleware: [], controller: null, loops: {} });
  return routes;
}

const files = fs.readdirSync(path.join(ROOT, 'routes')).filter(f => f.endsWith('.php')).sort();
let all = [];
for (const f of files) all = all.concat(auditFile('routes/' + f));

const failures = [];
const byName = new Map();
for (const r of all) {
  if (!r.name) continue;
  if (byName.has(r.name)) failures.push(`duplicate route name "${r.name}": ${byName.get(r.name)} and ${r.verb.toUpperCase()} ${r.uri}`);
  else byName.set(r.name, `${r.verb.toUpperCase()} ${r.uri}`);
}

const noPermission = [];
const authedNoPerm = [];
for (const r of all) {
  if (!r.action) continue;
  let cls = null, method = null;
  if (r.action.type === 'array') { cls = r.action.class; method = r.action.method; }
  else if (r.action.type === 'method') { cls = r.controller; method = r.action.method; }
  else if (r.action.type === 'invokable') { cls = r.action.class; method = '__invoke'; }
  else if (r.action.type === 'resource') { cls = r.action.class; method = null; }
  else if (r.action.type === 'dynamic') continue; // action chosen at runtime (e.g. a loop variable)
  else if (r.action.type === 'other') { failures.push(`${r.verb.toUpperCase()} ${r.uri}: unresolvable action at ${r.file}:${r.line}`); continue; }
  if (r.action.type === 'closure') continue;
  if (!cls) { failures.push(`${r.verb.toUpperCase()} ${r.uri}: string action "${r.action.method}" with no controller in scope at ${r.file}:${r.line}`); continue; }
  if (!classMethods.has(cls)) { failures.push(`${r.verb.toUpperCase()} ${r.uri}: controller ${cls} not found (${r.file}:${r.line})`); continue; }
  if (method && method !== '__invoke' && !classMethods.get(cls).has(method)) {
    failures.push(`${r.verb.toUpperCase()} ${r.uri}: ${cls.split('\\').pop()}::${method}() missing`);
  }
  const hasPerm = r.middleware.some(m => /^perm:|^can:|role:|permission:/.test(m));
  const authed = r.middleware.some(m => /^auth|^tenant|^twofactor/.test(m));
  if (!hasPerm && authed) authedNoPerm.push(`${r.verb.toUpperCase()} ${r.uri}  [${r.middleware.join(', ')}]`);
  else if (!hasPerm) noPermission.push(`${r.verb.toUpperCase()} ${r.uri}`);
}

if (process.env.DUMP_PERMS) {
  // Lets the configuration register prove every module's declared permission is the
  // one the route actually enforces (and find the screens that have a settings route
  // but no permission gate at all).
  const lines = all.filter(r => r.name && r.action).map(r => {
    const gate = (r.middleware || []).find(m => /^perm:|^can:/.test(m)) || '';

    return [r.name, gate, r.verb.toUpperCase(), r.uri].join('\t');
  });
  fs.writeFileSync(process.env.DUMP_PERMS, lines.sort().join('\n') + '\n');
  console.log(`route permissions written to ${process.env.DUMP_PERMS} (${lines.length})`);
}

if (process.env.DUMP_PUBLIC) {
  // Lets the licensing audit prove every genuinely public (unauthenticated) surface is
  // classified in config/licence.php, and that nothing classified has gone away.
  const rows = all.filter(r => !r.middleware.some(m => /^auth|^tenant|^twofactor/.test(m)) && !r.middleware.some(m => /^perm:|^can:|role:|permission:/.test(m)))
    .map(r => [r.verb.toUpperCase(), '/' + String(r.uri).replace(/^\//, '')].join('\t'));
  fs.writeFileSync(process.env.DUMP_PUBLIC, [...new Set(rows)].sort().join('\n') + '\n');
  console.log(`public routes written to ${process.env.DUMP_PUBLIC} (${new Set(rows).size})`);
}

if (process.env.DUMP_AUTH) {
  // Lets the licensing audit prove the guest-surface guard can never catch a signed-in screen.
  const rows = all.filter(r => r.middleware.some(m => /^auth|^tenant/.test(m)))
    .map(r => [r.verb.toUpperCase(), '/' + String(r.uri).replace(/^\//, '')].join('\t'));
  fs.writeFileSync(process.env.DUMP_AUTH, [...new Set(rows)].sort().join('\n') + '\n');
  console.log(`signed-in routes written to ${process.env.DUMP_AUTH} (${new Set(rows).size})`);
}

if (process.env.DUMP_NAMES) {
  // Lets other audits (and a route('…') reference check) reuse the name list.
  fs.writeFileSync(process.env.DUMP_NAMES, [...byName.keys()].sort().join('\n') + '\n');
  console.log(`route names written to ${process.env.DUMP_NAMES} (${byName.size})`);
}

console.log(`route files: ${files.length} | routes: ${all.length} | named: ${byName.size}`);
console.log(`integrity failures: ${failures.length}`);
(failures.length > 40 && !process.env.VERBOSE ? failures.slice(0, 25) : failures).forEach(f => console.log('  - ' + f));
console.log(`authenticated routes with no permission gate: ${authedNoPerm.length}`);
authedNoPerm.slice(0, process.env.VERBOSE ? 400 : 15).forEach(f => console.log('  ! ' + f));
console.log(`public / tokenised routes (no permission gate by design): ${noPermission.length}`);
if (process.env.VERBOSE) noPermission.forEach(f => console.log('  · ' + f));
process.exit(failures.length ? 1 : 0);
