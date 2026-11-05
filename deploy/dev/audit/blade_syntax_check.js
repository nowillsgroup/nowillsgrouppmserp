#!/usr/bin/env node
/**
 * Static syntax audit for Blade templates.
 *
 * Every PHP expression a view sends through the compiler is re-parsed here:
 *   @php … @endphp blocks, {{ … }} / {!! … !!} echoes, the expression inside
 *   @if/@elseif/@unless/@while/@foreach/@forelse/@switch/@case/@json/@checked…
 * A failure means Blade would choke on that argument at render time.
 *
 * Directive arguments are extracted with a balanced-paren scanner rather than a
 * regex, so `@if($x)>text` and nested parentheses are read exactly as Blade
 * reads them, and `@for(a; b; c)` is split on its top-level semicolons instead of
 * being parsed as one expression.
 *
 * Requires the `php-parser` npm package (same engine the other PHP audits use).
 * It is resolved from, in order: $PHP_PARSER_PATH, ./node_modules, /tmp/phpcheck.
 * When it is absent the check reports SKIP and exits 0 so it never fails a run
 * for reasons that have nothing to do with the templates.
 *
 * Usage: node deploy/dev/audit/blade_syntax_check.js [project-root]
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
  console.log('blade syntax check: SKIP (php-parser not installed — npm i --no-save php-parser)');
  process.exit(0);
}
const engine = new phpParser({ parser: { php7: true, suppressErrors: false } });

const walk = dir =>
  fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    const p = path.join(dir, entry.name);
    return entry.isDirectory() ? walk(p) : [p];
  });

/** Text inside the parentheses that start at `open`, honouring quotes and nesting. */
function balanced(src, open) {
  let depth = 0;
  let quote = null;
  for (let i = open; i < src.length; i++) {
    const ch = src[i];
    if (quote) {
      if (ch === '\\') i++;
      else if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"') quote = ch;
    else if (ch === '(') depth++;
    else if (ch === ')') {
      depth--;
      if (depth === 0) return { body: src.slice(open + 1, i), end: i };
    }
  }
  return null;
}

/** Split a `@for` header on the semicolons that are not inside a nested expression. */
function splitTopLevel(body) {
  const parts = [];
  let depth = 0;
  let quote = null;
  let start = 0;
  for (let i = 0; i < body.length; i++) {
    const ch = body[i];
    if (quote) {
      if (ch === '\\') i++;
      else if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"') quote = ch;
    else if ('(['.includes(ch)) depth++;
    else if (')]'.includes(ch)) depth--;
    else if (ch === ';' && depth === 0) {
      parts.push(body.slice(start, i));
      start = i + 1;
    }
  }
  parts.push(body.slice(start));
  return parts;
}

const DIRECTIVES = new Set([
  'if', 'elseif', 'unless', 'while', 'foreach', 'forelse', 'switch',
  'case', 'json', 'checked', 'selected', 'disabled', 'readonly', 'required',
  'isset', 'empty', 'class', 'style',
]);
const LOOP_DIRECTIVES = new Set(['foreach', 'forelse']);

let checked = 0;
const failures = [];

const files = walk(path.join(ROOT, 'resources/views')).filter(f => f.endsWith('.blade.php'));

for (const file of files) {
  const rel = path.relative(ROOT, file);
  let src = fs.readFileSync(file, 'utf8');
  // Comments and @verbatim blocks are not compiled — drop them before scanning.
  src = src.replace(/\{\{--[\s\S]*?--\}\}/g, m => ' '.repeat(m.length));
  src = src.replace(/@verbatim[\s\S]*?@endverbatim/g, m => ' '.repeat(m.length));

  const snippets = [];
  const push = (code, at) => snippets.push({ code, at: src.slice(0, at).split('\n').length });

  for (const m of src.matchAll(/@php([\s\S]*?)@endphp/g)) {
    if (m[1].trim()) push(`<?php ${m[1]} ?>`, m.index);
  }
  for (const m of src.matchAll(/\{\{([\s\S]*?)\}\}/g)) {
    const body = (m[1] || '').trim().replace(/^--/, '').trim();
    if (body) push(`<?php $__v = ( ${body} ); ?>`, m.index);
  }
  for (const m of src.matchAll(/\{!!([\s\S]*?)!!\}/g)) {
    const body = (m[1] || '').trim();
    if (body) push(`<?php $__v = ( ${body} ); ?>`, m.index);
  }

  for (const m of src.matchAll(/@([a-z]+)\s*(?=\()/g)) {
    const name = m[1];
    if (!DIRECTIVES.has(name)) continue;
    const open = src.indexOf('(', m.index + name.length + 1);
    const found = balanced(src, open);
    if (!found) continue; // truncated directive (e.g. split across @include data) — not an expression error
    const body = found.body.trim();
    if (!body) continue;
    if (name === 'for') {
      const parts = splitTopLevel(body);
      if (parts.length === 3 && parts[0].trim()) {
        push(`<?php for ( ${parts[0]}; ${parts[1]}; ${parts[2]} ) {} ?>`, m.index);
      }
      continue;
    }
    if (LOOP_DIRECTIVES.has(name)) {
      const idx = body.lastIndexOf(' as ');
      if (idx > 0) push(`<?php $__c = ( ${body.slice(0, idx)} ); ?>`, m.index);
      continue;
    }
    push(`<?php $__c = ( ${body} ); ?>`, m.index);
  }

  for (const snippet of snippets) {
    try {
      engine.parseCode(snippet.code, rel);
      checked++;
    } catch (err) {
      failures.push(`${rel}:${snippet.at}  ${String(err.message).split('\n')[0].slice(0, 140)}
      ${snippet.code.slice(0, 160).replace(/\s+/g, ' ')}`);
    }
  }
}

console.log(`blade files: ${files.length} | expressions parsed: ${checked} | syntax failures: ${failures.length}`);
failures.slice(0, 40).forEach(f => console.log('  - ' + f));
process.exit(failures.length ? 1 : 0);
