#!/usr/bin/env node
/*
 * Permissions are strings, and a string that is not in the catalogue is a control
 * that can never be granted: `can_do('frontdesk.check_in')` would quietly hide a
 * button for every user forever, and no route or view audit would notice.
 *
 * This checks every permission the application asks for against the catalogue in
 * config/pms.php — the list the roles screen is built from and the seeder uses.
 *
 * Run: node deploy/dev/audit/permission_reference_check.js
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

const problems = [];
const note = (file, line, message) => problems.push(`${file}:${line}  ${message}`);

/* ── the catalogue ───────────────────────────────────────────────────────── */

/** The `'permissions' => [ … ]` block, brace-matched so nested groups are kept. */
function permissionBlock(php) {
  const marker = php.indexOf("'permissions' => [");
  if (marker === -1) return '';
  const open = php.indexOf('[', marker);
  let depth = 0;
  for (let i = open; i < php.length; i++) {
    if (php[i] === '[') depth += 1;
    else if (php[i] === ']') {
      depth -= 1;
      if (depth === 0) return php.slice(open, i + 1);
    }
  }
  return '';
}

const config = readFileSync(join(ROOT, 'config/pms.php'), 'utf8');
const block = permissionBlock(config);
if (!block) {
  console.log('permission reference check: FAILED\n  the permission catalogue is not in config/pms.php');
  process.exit(1);
}

// 'group' => ['key' => 'Label', "key" => "Label"] — labels may use either quote
const catalogue = new Set();
for (const match of block.matchAll(/'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'\s*=>\s*['"]/g)) {
  catalogue.add(match[1]);
}

/**
 * Permission strings the code asks for that are deliberately not catalogue entries,
 * because hasPermission() resolves them from something else.
 */
const resolvedElsewhere = new Map([
  ['group.dashboard', 'User::hasPermission() — reserved for group administrators'],
]);

if (catalogue.size < 100) {
  console.log(`permission reference check: FAILED\n  only ${catalogue.size} permissions parsed — the catalogue parser is out of date`);
  process.exit(1);
}

/* ── what the code asks for ──────────────────────────────────────────────── */

const files = [];
const walk = (dir) => {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path);
    else if (/\.(php)$/.test(entry)) files.push(path);
  }
};
walk(join(ROOT, 'app'));
walk(join(ROOT, 'resources/views'));
walk(join(ROOT, 'routes'));

const relative = (path) => path.slice(ROOT.length + 1);
let checked = 0;
const used = new Map();

const record = (perm, file, line) => {
  checked += 1;
  used.set(perm, (used.get(perm) || 0) + 1);
  if (catalogue.has(perm) || resolvedElsewhere.has(perm)) return;
  note(relative(file), line, `"${perm}" is not in the permission catalogue — nothing can grant it`);
};

for (const file of files) {
  const text = readFileSync(file, 'utf8');
  const lines = text.split('\n');
  lines.forEach((line, index) => {
    for (const match of line.matchAll(/(?:can_do|hasPermission)\(\s*'([^']+)'(\s*\.)?/g)) {
      // 'talent.'.$perm is built at the call site: the prefix alone is not a permission
      if (match[2]) continue;
      record(match[1], file, index + 1);
    }
    // route middleware: perm:one,two
    for (const match of line.matchAll(/perm:([a-z0-9_.,]+)/g)) {
      match[1].split(',').filter(Boolean).forEach((perm) => record(perm, file, index + 1));
    }
  });
}

/*
 * The report table gates each report by a permission string in its fifth column. Those
 * are asks too: a report gated by a permission nobody can be granted is a report nobody
 * can open, and nothing else in the codebase mentions the string.
 */
const reportsFile = join(ROOT, 'app/Http/Controllers/ReportController.php');
if (existsSync(reportsFile)) {
  const source = readFileSync(reportsFile, 'utf8');
  const open = source.indexOf('const REPORTS');
  const close = source.indexOf('GROUP_ICONS');
  if (open > 0 && close > open) {
    const table = source.slice(open, close);
    const entries = [...table.matchAll(/^\s*'([a-z0-9_]+)' => \[\s*/gm)];
    entries.forEach((entry, index) => {
      const end = index + 1 < entries.length ? entries[index + 1].index : table.length;
      const body = table.slice(entry.index + entry[0].length, end);
      const fields = (body.match(/'([^'\\]*(?:\\.[^'\\]*)*)'|"([^"\\]*(?:\\.[^"\\]*)*)"/g) ?? [])
        .map((literal) => literal.slice(1, -1));
      const permission = fields[4] ?? 'reports.view';
      const line = source.slice(0, open + entry.index).split('\n').length;
      record(permission, reportsFile, line);
    });
  }
}

/* ── the standard roles grant tokens that have to exist ──────────────────── */

/*
 * HotelProvisioner::ROLES is what a new property is seeded with. A token with a
 * trailing dot ('qa.') means "everything under this prefix"; a typo there is a role
 * that silently grants nothing.
 */
const provisioner = readFileSync(join(ROOT, 'app/Services/HotelProvisioner.php'), 'utf8');
const rolesStart = provisioner.indexOf('const ROLES = [');
const rolesOpen = provisioner.indexOf('[', rolesStart);
let rolesDepth = 0;
let rolesBlock = '';
if (rolesStart !== -1) {
  for (let i = rolesOpen; i < provisioner.length; i++) {
    if (provisioner[i] === '[') rolesDepth += 1;
    else if (provisioner[i] === ']') {
      rolesDepth -= 1;
      if (rolesDepth === 0) { rolesBlock = provisioner.slice(rolesOpen, i + 1); break; }
    }
  }
}

const prefixes = new Set();
catalogue.forEach((perm) => {
  const parts = perm.split('.');
  for (let i = 1; i < parts.length; i++) prefixes.add(parts.slice(0, i).join('.') + '.');
});

let roleTokens = 0;
const roleProblems = [];
for (const match of rolesBlock.matchAll(/'([a-z][a-z0-9_]*(?:\.[a-z0-9_]*)*\.?)'/g)) {
  const token = match[1];
  if (!token.includes('.')) continue;
  roleTokens += 1;
  const line = provisioner.slice(0, rolesStart + rolesOpen + match.index).split('\n').length;
  if (token.endsWith('.')) {
    if (!prefixes.has(token)) roleProblems.push(`HotelProvisioner.php:${line}  "${token}" grants nothing — no catalogue entry starts with it`);
  } else if (!catalogue.has(token)) {
    roleProblems.push(`HotelProvisioner.php:${line}  "${token}" is not in the permission catalogue`);
  }
}

/* ── the catalogue is allowed to be larger than the code ─────────────────── */

const unused = [...catalogue].filter((perm) => !used.has(perm));

if (roleProblems.length) {
  console.log(`permission reference check: ${roleProblems.length} FAILED in the standard roles (${roleTokens} tokens)`);
  roleProblems.slice(0, 40).forEach((problem) => console.log(`  ✗ ${problem}`));
  if (roleProblems.length > 40) console.log(`  … ${roleProblems.length - 40} more`);
  process.exit(1);
}

if (problems.length) {
  console.log(`permission reference check: ${problems.length} FAILED (${checked} asked for, ${catalogue.size} in the catalogue)`);
  problems.slice(0, 40).forEach((problem) => console.log(`  ✗ ${problem}`));
  if (problems.length > 40) console.log(`  … ${problems.length - 40} more`);
  process.exit(1);
}

console.log(`permission reference check: ${checked} permissions asked for, all in the catalogue (${catalogue.size} entries)`);
console.log(`  ${roleTokens} standard-role tokens all resolve to catalogue entries or prefixes`);
if (unused.length) {
  console.log(`  ${unused.length} catalogue entries nothing asks for: ${unused.slice(0, 12).join(', ')}${unused.length > 12 ? ' …' : ''}`);
}
