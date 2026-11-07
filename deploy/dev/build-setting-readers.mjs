#!/usr/bin/env node
/**
 * Generate config/setting-readers.php — where each setting is actually read.
 *
 * The register answers "what is it set to, what does it fall back to, who changed it".
 * It does not answer the question a manager asks before changing anything: *what depends
 * on this?* That answer is in the code, so it is read from the code and written to a
 * generated file the register displays — the same discipline as the built-in defaults.
 *
 * Three things are found, and nothing is guessed:
 *   literal   setting('parking_free_minutes', '30')            → the file that reads it
 *   family    setting('sms_tpl_'.$key)                         → the family `sms_tpl_`
 *   map       cfg($k) → setting($k, self::DEFAULTS[$k])        → every key in that map
 *   accessor  MessageService::secret('sms_twilio_token')       → the class that owns the
 *             declaration in a SECRETS/SECRET constant reads the key through an accessor,
 *             so no literal call carries it; the constant is where the code names it.
 *
 * Run: node deploy/dev/build-setting-readers.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'config/setting-readers.php');
const checkOnly = process.argv.includes('--check');

// The code that reads a setting: the application, the views that print it, the routes
// that gate on it and the seeders/console commands that act on it.
const ROOTS = ['app', 'resources/views', 'routes', 'database', 'config'];
const files = [];
for (const root of ROOTS) {
  const full = path.join(ROOT, root);
  if (!fs.existsSync(full)) continue;
  (function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, entry.name);
      if (entry.isDirectory()) walk(p);
      else if (entry.name.endsWith('.php') && path.resolve(p) !== path.resolve(OUT)) files.push(p);
    }
  })(full);
}

const relatives = new Map(files.map((f) => [f, path.relative(ROOT, f)]));

const readers = new Map();   // key -> Set(relative file)
const families = new Map();  // 'sms_tpl_' -> Set(relative file)
for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = relatives.get(file);

  // setting('key' …) / settings(['key' …]) — a literal key the reader names outright.
  // A key that ends with `_` is a family, and `setting('sms_tpl_'.$k)` matches here too, so
  // those are left to the family pass below rather than counted as a key of their own.
  for (const hit of src.matchAll(/(?<![\w>])settings?\s*\(\s*'([a-z0-9_]+)'/g)) {
    if (hit[1].endsWith('_')) continue;
    if (!readers.has(hit[1])) readers.set(hit[1], new Set());
    readers.get(hit[1]).add(rel);
  }

  // setting('sms_tpl_'.$key …) — the real key is built at runtime, so the family is what
  // this file reads. A family the register already lists is the unit a reader understands.
  for (const hit of src.matchAll(/(?<![\w>])settings?\s*\(\s*'([a-z0-9_]+_)'\s*\./g)) {
    if (!families.has(hit[1])) families.set(hit[1], new Set());
    families.get(hit[1]).add(rel);
  }
}

// Credentials are never passed to setting() by name: MessageService::secret() takes the key
// from a constant and calls the store itself. The constant is the honest record of which
// class reads which credential, so it is read rather than assumed.
const accessors = new Map(); // key -> Set(relative file)
for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = relatives.get(file);
  for (const hit of src.matchAll(/public\s+const\s+SECRETS?\s*=\s*(\[[^\]]*\]|'[a-z0-9_]+')/g)) {
    for (const key of hit[1].matchAll(/'([a-z0-9_]+)'/g)) {
      if (!accessors.has(key[1])) accessors.set(key[1], new Set());
      accessors.get(key[1]).add(rel);
    }
  }
}

// A service that hands a variable to setting() — cfg($k) → setting($k, DEFAULTS[$k]) — reads
// every key in its DEFAULTS map without naming one, so the literal pass above cannot see
// them. The map is where the code names them, so the map is read: a file that passes a
// variable to setting() has its DEFAULTS/SETTINGS/CONFIG map taken as the settings it reads.
const mapped = new Map(); // key -> Set(relative file)
for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  if (!/(?<![\w>])settings?\s*\(\s*\$/.test(src)) continue;
  const rel = relatives.get(file);
  for (const hit of src.matchAll(/public\s+const\s+(?:DEFAULTS|SETTINGS|CONFIG)\s*=\s*\[([\s\S]*?)\];/g)) {
    for (const pair of hit[1].matchAll(/'([a-z][a-z0-9_]{2,})'\s*=>\s*(?:'[^']*'|-?[0-9][0-9.]*|\[)/g)) {
      if (!mapped.has(pair[1])) mapped.set(pair[1], new Set());
      mapped.get(pair[1]).add(rel);
    }
  }
}

const sorted = (map) => [...map.keys()].sort().map((key) => [key, [...map.get(key)].sort()]);
const readerRows = sorted(readers);
const familyRows = sorted(families);
const accessorRows = sorted(accessors);
const mapRows = sorted(mapped);

const phpList = (list) => `[${list.map((f) => `'${f.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`).join(', ')}]`;

const lines = [];
lines.push('<?php');
lines.push('');
lines.push('/*');
lines.push(' * GENERATED — do not edit.');
lines.push(' *');
lines.push(' * Where each setting is read: the files that call `setting(` with the key, the files that');
lines.push(' * build it from a family prefix, and — for a credential — the class whose SECRETS constant');
lines.push(' * names it and reads it through an accessor. Settings › Configuration shows this beside the');
lines.push(' * value, so nobody changes a setting without seeing what depends on it.');
lines.push(' *');
lines.push(' * Regenerate with: node deploy/dev/build-setting-readers.mjs');
lines.push(' */');
lines.push('');
lines.push('return [');
lines.push(`    'readers' => [`);
for (const [key, list] of readerRows) lines.push(`        '${key}' => ${phpList(list)},`);
if (!readerRows.length) lines.push('        // none');
lines.push('    ],');
lines.push(`    'families' => [`);
for (const [prefix, list] of familyRows) lines.push(`        '${prefix}' => ${phpList(list)},`);
if (!familyRows.length) lines.push('        // none');
lines.push('    ],');
lines.push(`    'accessors' => [`);
for (const [key, list] of accessorRows) lines.push(`        '${key}' => ${phpList(list)},`);
if (!accessorRows.length) lines.push('        // none');
lines.push('    ],');
lines.push(`    'map' => [`);
for (const [key, list] of mapRows) lines.push(`        '${key}' => ${phpList(list)},`);
if (!mapRows.length) lines.push('        // none');
lines.push('    ],');
lines.push(`    'counts' => [`);
lines.push(`        'keys' => ${readerRows.length},`);
lines.push(`        'families' => ${familyRows.length},`);
lines.push(`        'accessors' => ${accessorRows.length},`);
lines.push(`        'map' => ${mapRows.length},`);
lines.push(`        'files' => ${files.length},`);
lines.push('    ],');
lines.push('];');
lines.push('');

const php = lines.join('\n');
const before = fs.existsSync(OUT) ? fs.readFileSync(OUT, 'utf8') : null;

if (checkOnly) {
  let failures = 0;
  if (before === null) {
    console.error('config/setting-readers.php is missing — run node deploy/dev/build-setting-readers.mjs');
    failures += 1;
  } else if (before !== php) {
    console.error('config/setting-readers.php is stale — run node deploy/dev/build-setting-readers.mjs');
    failures += 1;
  }
  console.log(`setting readers check: ${readerRows.length} keys read by name · ${familyRows.length} families · ${accessorRows.length} through an accessor · ${mapRows.length} through a map · ${files.length} files scanned`);
  process.exit(failures ? 1 : 0);
}

fs.writeFileSync(OUT, php);
console.log(`wrote config/setting-readers.php — ${readerRows.length} keys, ${familyRows.length} families, ${accessorRows.length} credentials, ${mapRows.length} through a map, ${files.length} files`);