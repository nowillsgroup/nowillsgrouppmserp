#!/usr/bin/env node
/**
 * Build the HR employee-file preview as the real application screen.
 *
 * resources/views/hr/employee.blade.php rendered by deploy/dev/preview/blade-render.mjs
 * against deploy/dev/preview/hr-employee-sample.mjs, inside the application shell — the
 * page's own @push('head') styles included, so the photograph frame and the document
 * table are the ones the application draws.
 *
 * Output: deploy/dev/previews/hr-employee.html
 *
 * Run: node deploy/dev/build-hr-employee-preview.mjs [--check]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { hrEmployeeContext, EMPLOYEE } from './preview/hr-employee-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const { menu, navHtml } = nav();
const sprite = spriteSymbols();

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const ctx = hrEmployeeContext();
const renderer = new BladePreview({ root: ROOT, ctx, log });

let content = '';
let actions = '';
let head = '';
try {
  content = renderer.section('hr/employee', 'content', ctx.scope);   // the file itself
  actions = renderer.section('hr/employee', 'actions', ctx.scope);   // edit employee, pay setup
  head = renderer.push('hr/employee', 'head', ctx.scope);            // the page's own styles
} catch (error) {
  console.error(`render failed: ${error.message}`);
  process.exit(1);
}

const html = shell(navHtml, sprite, indent(content), {
  title: `Employee file · ${EMPLOYEE.name}`,
  crumbs: `HR / ${esc('Employees')} / ${esc(EMPLOYEE.emp_code)}`,
  heading: 'Employee file',
  actions: indent(actions, '        '),
  head,
});

const out = path.join(ROOT, 'deploy/dev/previews/hr-employee.html');
const previous = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
if (previous === html) {
  console.log('hr-employee.html is up to date');
} else if (checkOnly) {
  console.error('hr-employee.html is stale — run node deploy/dev/build-hr-employee-preview.mjs');
  process.exit(1);
} else {
  fs.writeFileSync(out, html);
  console.log(`wrote deploy/dev/previews/hr-employee.html (${html.length} bytes)`);
}

if (log.failed.length) {
  console.log(`  unresolved expressions .. ${log.failed.length}`);
  for (const item of log.failed.slice(0, 12)) console.log(`    ${item.expr.slice(0, 110)} → ${item.reason}`);
}
if (log.unknownComponents.length) console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
if (log.unknownDirectives.length) console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
if (log.errors.length) log.errors.forEach(error => console.error(`  error: ${error}`));
console.log(`  hr-employee: expressions ${log.expressions} | fallbacks ${log.fallbacks} | head style block ${head.length > 0 ? 'yes' : 'no'}`);
console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length}`);
