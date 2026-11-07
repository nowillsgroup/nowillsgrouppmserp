#!/usr/bin/env node
/**
 * Generate config/setting-defaults.php — what each setting falls back to when the
 * property has never set it.
 *
 * The register promises "current value, default and last changed by". The default is not
 * stored anywhere: it is the second argument of the `setting()` call the code reads the
 * value with. This script reads the code the way the runtime would and writes the map the
 * register displays, so a reader can see "on the built-in default — which is 30 minutes"
 * instead of only "on default".
 *
 * Three cases, and it never guesses:
 *   literal   setting('parking_free_minutes', '30')      → 30
 *   array     setting($k, $v) inside a DEFAULTS map      → the value in that map
 *   none      setting('booking_policy')                  → no default at all (null)
 *
 * A key read with two different literal defaults would be a bug — the screen says one
 * thing and the report another — so the generator records them under `conflicting` and
 * `--check` fails rather than picking one.
 *
 * Run: node deploy/dev/build-setting-defaults.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'config/setting-defaults.php');
const checkOnly = process.argv.includes('--check');

const phpFiles = [];
(function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.name.endsWith('.php')) phpFiles.push(full);
  }
})(path.join(ROOT, 'app'));

/** The argument list of the call that starts at `open` (index of the "("), with quotes respected. */
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
      if (depth === 0) { parts.push(current); return parts; }
    }
    if (ch === ',' && depth === 1) { parts.push(current); current = ''; continue; }
    current += ch;
  }
  return parts;
}

// A PHP string literal, unescaped the way PHP unescapes it: a single-quoted string only
// knows \' and \\, a double-quoted one interprets \n, \t and friends. Getting this wrong
// turns a policy paragraph into one long word — which is exactly what a reader would notice.
const unquote = (text) => {
  const quote = text[0];
  const body = text.slice(1, -1);
  if (quote === "'") return body.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
  return body.replace(/\\(.)/g, (all, ch) => ({ n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\', $: '$' }[ch] ?? ch));
};

const literal = (expr) => {
  const text = String(expr ?? '').trim();
  if (text.length >= 2 && (text[0] === "'" || text[0] === '"') && text.at(-1) === text[0] && !text.slice(1, -1).includes(text[0])) {
    return unquote(text);
  }
  return null;
};

// The DEFAULTS maps the services loop over: `const DEFAULTS = [...]` or `$defaults = [...]`.
// Only these, never a `'key' => 'required|integer|min:1'` validation rule — a rule is not a
// default, and reading one as a default is how a register starts lying.
const arrays = new Map();
const pair = /'([a-z0-9_]+)'\s*=>\s*('[^']*'|"(?:[^"\\]|\\.)*")/g;
for (const file of phpFiles) {
  const src = fs.readFileSync(file, 'utf8');
  const starts = [...src.matchAll(/(?:const\s+DEFAULTS|static\s+\$defaults|\$defaults)\s*=\s*\[/g)];
  for (const start of starts) {
    const open = src.indexOf('[', start.index);
    let depth = 0;
    let quote = null;
    let end = src.length;
    for (let i = open; i < src.length; i += 1) {
      const ch = src[i];
      if (quote) {
        if (ch === '\\') { i += 1; continue; }
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === "'" || ch === '"') { quote = ch; continue; }
      if (ch === '[') depth += 1;
      else if (ch === ']') { depth -= 1; if (depth === 0) { end = i; break; } }
    }
    const block = src.slice(open, end);
    for (const hit of block.matchAll(pair)) {
      const value = unquote(hit[2]);
      if (!arrays.has(hit[1])) arrays.set(hit[1], new Set());
      arrays.get(hit[1]).add(value);
    }
  }
}

const literals = new Map();   // key -> Set of literal defaults
const expressions = new Map(); // key -> file
for (const file of phpFiles) {
  const src = fs.readFileSync(file, 'utf8');
  for (const hit of src.matchAll(/(?<![\w>])setting\s*\(/g)) {
    const list = args(src, hit.index + hit[0].length - 1);
    const key = literal(list[0]);
    if (!key) continue;
    const raw = (list[1] ?? '').trim();
    if (raw === '') continue; // no default at all
    const lit = literal(raw);
    if (lit !== null) {
      if (!literals.has(key)) literals.set(key, new Set());
      literals.get(key).add(lit);
    } else if (!expressions.has(key)) {
      expressions.set(key, path.relative(ROOT, file));
    }
  }
}

// A file that hands a variable to setting() — cfg($k) → setting($k, self::DEFAULTS[$k]) —
// carries the default for every key in that map without naming one in a call, so the map is
// where the default is read from. Without this the register shows "no built-in default" for
// a setting that has one, which is the one thing it must never do.
const mapDefaults = new Map(); // key -> {value, from}
for (const file of phpFiles) {
  const src = fs.readFileSync(file, 'utf8');
  if (!/(?<![\w>])settings?\s*\(\s*\$/.test(src)) continue;
  for (const hit of src.matchAll(/public\s+const\s+(?:DEFAULTS|SETTINGS|CONFIG)\s*=\s*\[([\s\S]*?)\];/g)) {
    for (const pair of hit[1].matchAll(/'([a-z][a-z0-9_]{2,})'\s*=>\s*('([^']*)'|-?[0-9][0-9.]*|\[([^\]]*)\])/g)) {
      // a scalar value is the default; a tuple (`['a label', 10]`) keeps it last
      let value = pair[3] !== undefined ? pair[3] : pair[2];
      if (pair[4] !== undefined) {
        // a tuple holds a label and a default, in either order: the default is the element
        // that does not read as a label (no spaces, not a sentence)
        const parts = [...pair[4].matchAll(/'(?:[^'\\]|\\.)*'|-?[0-9][0-9.]*/g)]
          .map((x) => (x[0].startsWith("'") ? x[0].slice(1, -1).replace(/\\'/g, "'") : x[0]));
        if (!parts.length) continue;
        const plain = parts.filter((x) => !x.includes(' ') && x.length <= 25);
        value = plain.length ? plain[0] : parts[parts.length - 1];
      }
      if (!mapDefaults.has(pair[1])) {
        mapDefaults.set(pair[1], { value, from: path.relative(ROOT, file) });
      }
    }
  }
}

const defaults = {};
const conflicting = {};
const resolvedFromArray = [];
const resolvedFromMap = [];
for (const [key, values] of [...literals].sort(([a], [b]) => a.localeCompare(b))) {
  const list = [...values].sort();
  if (list.length > 1) {
    conflicting[key] = list;
    continue;
  }
  defaults[key] = list[0];
}
for (const [key, from] of [...expressions].sort(([a], [b]) => a.localeCompare(b))) {
  if (key in defaults || key in conflicting) continue;
  const values = [...(arrays.get(key) ?? [])].sort();
  if (values.length === 1) {
    defaults[key] = values[0];
    resolvedFromArray.push({ key, from, value: values[0] });
  } else if (values.length > 1) {
    conflicting[key] = values;
  }
}

for (const [key, row] of [...mapDefaults].sort(([a], [b]) => a.localeCompare(b))) {
  if (key in defaults || key in conflicting) continue;
  defaults[key] = row.value;
  resolvedFromMap.push({ key, from: row.from, value: row.value });
}

// Emit each default as a PHP literal that parses back to exactly the same string.
const phpString = (value) => {
  const text = String(value);
  if (text.includes('\n') || text.includes('\t') || text.includes('"') || text.includes('$')) {
    return `"${text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t').replace(/\$/g, '\\$')}"`;
  }
  return `'${text.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
};
const keys = Object.keys(defaults).sort();
const lines = [];
lines.push('<?php');
lines.push('');
lines.push('/*');
lines.push(' * GENERATED — do not edit.');
lines.push(' *');
lines.push(' * The built-in default of every setting: the second argument of the `setting()` call the');
lines.push(' * code reads the value with. Settings › Configuration shows it beside the stored value so');
lines.push(' * a reader can tell "never set, running on 30 minutes" from "never set, running on');
lines.push(' * nothing". Regenerate with: node deploy/dev/build-setting-defaults.mjs');
lines.push(' *');
lines.push(' * A key with no entry here is read without a default, so its default is nothing at all —');
lines.push(" * the register prints '—' for it rather than inventing a value.");
lines.push(' */');
lines.push('');
lines.push('return [');
lines.push("    'defaults' => [");
for (const key of keys) lines.push(`        '${key}' => ${phpString(defaults[key])},`);
if (!keys.length) lines.push('        // none');
lines.push('    ],');
lines.push("    'resolved_from_array' => [");
for (const row of resolvedFromArray.sort((a, b) => a.key.localeCompare(b.key))) {
  lines.push(`        '${row.key}' => ${phpString(row.value)}, // ${row.from}`);
}
if (!resolvedFromArray.length) lines.push('        // none');
lines.push('    ],');
lines.push("    'resolved_from_map' => [");
for (const row of resolvedFromMap) lines.push(`        '${row.key}' => ${phpString(row.value)}, // ${row.from}`);
if (!resolvedFromMap.length) lines.push('        // none');
lines.push('    ],');
lines.push("    'conflicting' => [");
for (const key of Object.keys(conflicting).sort()) {
  lines.push(`        '${key}' => [${conflicting[key].map((v) => phpString(v)).join(', ')}],`);
}
if (!Object.keys(conflicting).length) lines.push('        // none');
lines.push('    ],');
lines.push('];');
lines.push('');

const php = lines.join('\n');
const before = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : null;

if (checkOnly) {
  let failures = 0;
  if (before === null) {
    console.error('config/setting-defaults.php is missing — run node deploy/dev/build-setting-defaults.mjs');
    failures += 1;
  } else if (before !== php) {
    console.error('config/setting-defaults.php is stale — run node deploy/dev/build-setting-defaults.mjs');
    failures += 1;
  }
  if (Object.keys(conflicting).length) {
    console.error(`conflicting defaults: ${Object.keys(conflicting).join(', ')}`);
    failures += 1;
  }
  console.log(`setting defaults check: ${keys.length} keys with a built-in default · ${Object.keys(conflicting).length} conflicting`);
  process.exit(failures ? 1 : 0);
}

fs.writeFileSync(OUT, php);
console.log(`wrote config/setting-defaults.php — ${keys.length} keys with a built-in default, ${resolvedFromArray.length} resolved from a DEFAULTS map`);
if (Object.keys(conflicting).length) {
  console.log(`  CONFLICTING (a key read with two different defaults): ${Object.keys(conflicting).join(', ')}`);
  process.exit(1);
}
