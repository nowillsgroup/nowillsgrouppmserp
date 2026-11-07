#!/usr/bin/env node
/**
 * Generate config/setting-fallbacks.php — what a setting runs on when the code ships no
 * built-in default at all.
 *
 * `build-setting-defaults.mjs` records the second argument of `setting('key', '30')`. That
 * covers 209 of the 276 keys. The other 67 are read with **no** second argument, so the
 * runtime hands back null — but the code around the read usually does not stop there: it
 * writes `?? 'log'`, or `?: 30`, or a page prints a default of its own. Until now the
 * register could only print '—' for those, which is true but useless to whoever has to
 * decide whether a blank means "off" or "somebody forgot".
 *
 * This script reads every read site of those keys and records, per key and per side:
 *
 *   service   what a service/controller/model/command gets — a literal it falls back to
 *   view      what a page prints — a literal a Blade falls back to
 *
 * and never guesses:
 *   literal    setting('k', 'log')            → 'log'
 *              setting('k') ?? 'log'          → 'log'
 *              setting('k') ?: 30             → 30
 *   computed   setting('k', $default)         → no value; the call site decides
 *   nothing    setting('k')                   → nothing: the code sees null
 *
 * A key whose two sides disagree, or whose sides disagree with themselves, is recorded
 * under `conflicting` rather than resolved — the screen says so instead of picking one.
 *
 * Run: node deploy/dev/build-setting-fallbacks.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'config/setting-fallbacks.php');
const checkOnly = process.argv.includes('--check');

// ── the keys this script is about: registered, read with no built-in default ──────────
const registry = fs.readFileSync(path.join(ROOT, 'config/settings.php'), 'utf8');
const body = registry.slice(registry.indexOf("'modules' => ["));
const starts = [...body.matchAll(/^        '([a-z0-9_]+)' => \[$/gm)];
const keys = [];   // [module, key]
const secrets = new Set();
const moduleOf = new Map();
for (let n = 0; n < starts.length; n += 1) {
  const block = body.slice(starts[n].index, n + 1 < starts.length ? starts[n + 1].index : body.length);
  const kblock = block.slice(block.indexOf("'keys' => ["));
  const secret = block.match(/'secret' => \[([^\]]*)\]/);
  for (const k of secret ? secret[1].matchAll(/'([a-z0-9_]+)'/g) : []) secrets.add(k[1]);
  for (const hit of kblock.matchAll(/'([a-z0-9_]+)' => '/g)) {
    keys.push([starts[n][1], hit[1]]);
    moduleOf.set(hit[1], starts[n][1]);
  }
}

const defaultsSrc = fs.readFileSync(path.join(ROOT, 'config/setting-defaults.php'), 'utf8');
const defaulted = new Set();
const dbody = defaultsSrc.slice(defaultsSrc.indexOf("'defaults' => ["), defaultsSrc.indexOf("'resolved_from_array'"));
for (const hit of dbody.matchAll(/^        '([a-z0-9_]+)' =>/gm)) defaulted.add(hit[1]);

// A credential is never read through `setting()` — MessageService::secret() is the honest
// "not configured" — so a credential has no fallback to report and is left out.
// A family prefix (`gl_cat_`, `fs_shelf_`, `fb_sla_`, `sms_tpl_`) is a *prefix*, not a key:
// what it holds is whatever rows the property created, so it has no single value either.
const targets = new Set(
  keys.map(([, k]) => k).filter((k) => !defaulted.has(k) && !secrets.has(k) && !k.endsWith('_')),
);

// ── read the call sites ──────────────────────────────────────────────────────────────
const files = [];
(function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.name.endsWith('.php')) files.push(full);
  }
})(path.join(ROOT, 'app'));
(function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.name.endsWith('.blade.php')) files.push(full);
  }
})(path.join(ROOT, 'resources/views'));

/** The argument list of the call whose `(` is at `open`, quotes and nesting respected. */
function args(src, open) {
  let depth = 0;
  let quote = null;
  const parts = [];
  let current = '';
  for (let i = open; i < src.length; i += 1) {
    const ch = src[i];
    if (quote) {
      current += ch;
      if (ch === '\\') { current += src[i + 1] ?? ''; i += 1; continue; }
      if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"') { quote = ch; current += ch; continue; }
    if (ch === '(' || ch === '[') { depth += 1; if (depth === 1) continue; }
    if (ch === ')' || ch === ']') {
      depth -= 1;
      if (depth === 0) { parts.push(current); return { parts, end: i }; }
    }
    if (ch === ',' && depth === 1) { parts.push(current); current = ''; continue; }
    current += ch;
  }
  return { parts, end: src.length };
}

const unquote = (text) => {
  const quote = text[0];
  const body = text.slice(1, -1);
  if (quote === "'") return body.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
  return body.replace(/\\(.)/g, (all, ch) => ({ n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\', $: '$' }[ch] ?? ch));
};

/** A value the code can be said to fall back to, or null when the expression is not one. */
const literal = (expr) => {
  const text = String(expr ?? '').trim();
  if (text === '') return null;
  if (text.length >= 2 && (text[0] === "'" || text[0] === '"') && text.at(-1) === text[0]) return unquote(text);
  if (/^-?\d+(?:\.\d+)?$/.test(text)) return text;
  if (text === 'true' || text === 'false') return text;
  if (text === 'null') return 'null';
  if (text === '[]' || text === 'array()') return '[]';
  return null;
};

// key -> side -> { literals:Set, computed:Set<file>, bare:Set<file>, files:Set }
const seen = new Map();
const blank = () => ({ literals: new Set(), where: new Map(), computed: new Set(), bare: new Set() });
const touch = (key) => {
  if (!seen.has(key)) seen.set(key, { service: blank(), view: blank() });
  return seen.get(key);
};

for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file).split(path.sep).join('/');
  const side = rel.startsWith('resources/views/') ? 'view' : 'service';
  for (const hit of src.matchAll(/(?<![\w>])setting\s*\(/g)) {
    const { parts, end } = args(src, hit.index + hit[0].length - 1);
    const key = literal(parts[0]);
    if (!key || !targets.has(key)) continue;
    const bucket = touch(key)[side];
    const second = (parts[1] ?? '').trim();
    let value = null;
    if (second !== '') {
      value = literal(second);
      if (value === null) bucket.computed.add(rel);
      else { bucket.literals.add(value); if (!bucket.where.has(value)) bucket.where.set(value, rel); }
      continue;
    }
    // No second argument: the site may still guard the null right after the call.
    const tail = src.slice(end + 1, end + 400);
    const chain = tail.match(/^\s*(?:\?\?|\?:)\s*('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"|-?\d+(?:\.\d+)?|true|false|null|\[\])/);
    value = chain ? literal(chain[1]) : null;
    if (value === null) bucket.bare.add(rel);
    else { bucket.literals.add(value); if (!bucket.where.has(value)) bucket.where.set(value, rel); }
  }
}

const fallbacks = {};
const conflicting = {};
for (const key of [...targets].sort()) {
  const sides = seen.get(key) ?? { service: { literals: new Set() }, view: { literals: new Set() } };
  const one = (bucket) => {
    const values = [...(bucket.literals ?? [])];
    if (values.length > 1) return { conflict: values.sort() };
    if (values.length === 1) return { value: values[0] };
    return { value: null };
  };
  const service = one(sides.service);
  const view = one(sides.view);
  if (service.conflict || view.conflict) {
    const list = [...new Set([...(service.conflict ?? []), ...(view.conflict ?? [])])].sort();
    conflicting[key] = { values: list, module: moduleOf.get(key) };
    continue;
  }
  const first = (set) => [...(set ?? [])].sort()[0] ?? null;
  const siteOf = (bucket, value) => (value !== null && bucket.where.get(value))
    || first(bucket.literals.size ? bucket.where : null)
    || first(bucket.computed) || first(bucket.bare) || null;
  fallbacks[key] = {
    module: moduleOf.get(key),
    service: service.value,
    view: view.value,
    service_file: siteOf(sides.service, service.value),
    view_file: siteOf(sides.view, view.value),
  };
}

const phpString = (value) => {
  const text = String(value);
  if (text.includes('\n') || text.includes('\t') || text.includes('"') || text.includes('$')) {
    return `"${text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t').replace(/\$/g, '\\$')}"`;
  }
  return `'${text.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
};
const nullable = (value) => (value === null ? 'null' : phpString(value));

const lines = [];
lines.push('<?php');
lines.push('');
lines.push('/*');
lines.push(' * GENERATED — do not edit.');
lines.push(' *');
lines.push(' * What a setting runs on when the code ships no built-in default. 67 of the 276');
lines.push(' * registered keys are read with no second argument, so the runtime hands back null and');
lines.push(' * the fallback — if there is one — lives at the read site: `?? \'log\'`, `?: 30`, or a');
lines.push(" * default a page prints. Settings › Configuration prints it, so 'never set' reads as");
lines.push(' * \'running on null\' or \'running on 30, written in SpaBookingService.php\' rather than as');
lines.push(" * a bare '—'.");
lines.push(' *');
lines.push(' *   service   what a service, controller, model or command gets');
lines.push(' *   view      what a page prints');
lines.push(' *   null      nothing: the code sees null and each call site decides');
lines.push(' *');
lines.push(' * A credential has no entry (it is never read through `setting()`), and a family prefix');
lines.push(' * (`gl_cat_`, `fs_shelf_`, `fb_sla_`, `sms_tpl_`) holds whatever rows the property');
lines.push(' * created, so it has no single value either.');
lines.push(' *');
lines.push(' * Regenerate with: node deploy/dev/build-setting-fallbacks.mjs');
lines.push(' */');
lines.push('');
lines.push('return [');
lines.push("    'fallbacks' => [");
for (const key of Object.keys(fallbacks)) {
  const row = fallbacks[key];
  const parts = [
    `'module' => ${phpString(row.module)}`,
    `'service' => ${nullable(row.service)}`,
    `'view' => ${nullable(row.view)}`,
  ];
  if (row.service_file) parts.push(`'service_file' => ${phpString(row.service_file)}`);
  if (row.view_file) parts.push(`'view_file' => ${phpString(row.view_file)}`);
  lines.push(`        '${key}' => [${parts.join(', ')}],`);
}
if (!Object.keys(fallbacks).length) lines.push('        // none');
lines.push('    ],');
lines.push("    'conflicting' => [");
for (const key of Object.keys(conflicting).sort()) {
  lines.push(`        '${key}' => [${conflicting[key].values.map(phpString).join(', ')}],`);
}
if (!Object.keys(conflicting).length) lines.push('        // none');
lines.push('    ],');
lines.push("    'keys' => [");
for (const key of [...targets].sort()) lines.push(`        '${key}',`);
if (!targets.size) lines.push('        // none');
lines.push('    ],');
lines.push('];');
const out = lines.join('\n') + '\n';

if (checkOnly) {
  const current = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : '';
  if (current !== out) {
    console.error('config/setting-fallbacks.php is stale — run node deploy/dev/build-setting-fallbacks.mjs');
    process.exit(1);
  }
  const withFallback = Object.values(fallbacks).filter((r) => r.service !== null || r.view !== null).length;
  console.log(`setting fallbacks check: ${Object.keys(fallbacks).length} keys with no built-in default · ${withFallback} have a fallback in the code · ${Object.keys(conflicting).length} conflicting`);
} else {
  fs.writeFileSync(OUT, out);
  const withFallback = Object.values(fallbacks).filter((r) => r.service !== null || r.view !== null).length;
  const serviceOnly = Object.values(fallbacks).filter((r) => r.service !== null).length;
  const viewOnly = Object.values(fallbacks).filter((r) => r.service === null && r.view !== null).length;
  console.log(`wrote config/setting-fallbacks.php — ${Object.keys(fallbacks).length} keys, ${serviceOnly} with a service fallback, ${viewOnly} only in a page, ${Object.keys(fallbacks).length - withFallback} on null, ${Object.keys(conflicting).length} conflicting`);
}
