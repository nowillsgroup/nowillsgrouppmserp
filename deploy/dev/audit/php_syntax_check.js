#!/usr/bin/env node
/**
 * Parse every PHP file in the application with php-parser.
 *
 * This is a syntax gate, not a linter: it guarantees the file can be compiled by
 * PHP at all (unbalanced braces, a broken string, a stray backslash in a class
 * reference). Behavioural problems still need tests.
 *
 * Requires the `php-parser` npm package, resolved from $PHP_PARSER_PATH,
 * ./node_modules or /tmp/phpcheck. Without it the check reports SKIP and exits 0.
 *
 * Usage: node deploy/dev/audit/php_syntax_check.js [project-root]
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

const phpParser = loadEngine();
if (!phpParser) {
  console.log('php syntax check: SKIP (php-parser not installed — npm i --no-save php-parser)');
  process.exit(0);
}
const engine = new phpParser({ parser: { php7: true, suppressErrors: false } });

// The installers live outside the web root as .php.txt templates; they are still PHP
// that someone copies straight onto a server, so they get parsed like the rest.
const TARGETS = ['app', 'config', 'database', 'routes', 'tests', 'deploy/installers'];

const walk = dir =>
  fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) return entry.name === 'node_modules' ? [] : walk(p);
    return entry.name.endsWith('.php') || entry.name.endsWith('.php.txt') ? [p] : [];
  });

let parsed = 0;
const failures = [];
for (const dir of TARGETS) {
  const abs = path.join(ROOT, dir);
  if (!fs.existsSync(abs)) continue;
  for (const file of walk(abs)) {
    const rel = path.relative(ROOT, file);
    try {
      engine.parseCode(fs.readFileSync(file, 'utf8'), rel);
      parsed++;
    } catch (err) {
      failures.push(`${rel}:${(err.message.match(/line (\d+)/) || [])[1] || '?'}  ${String(err.message).split('\n')[0].slice(0, 160)}`);
    }
  }
}

console.log(`php files parsed: ${parsed} | syntax errors: ${failures.length}`);
failures.slice(0, 40).forEach(f => console.log('  - ' + f));
process.exit(failures.length ? 1 : 0);
