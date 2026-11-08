#!/usr/bin/env node
/** Render the actual folio page with fictional, read-only sample data. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { folioPageContext } from './preview/folio-page-sample.mjs';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const out = path.join(root, 'deploy/dev/previews/folio-page.html');
const ctx = folioPageContext();
const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
const renderer = new BladePreview({ root, ctx, log });
// The sample has no companies: replace the picker's DB-backed PHP with its empty result.
// This renderer does not handle @js yet; there is no old split-tender input in the sample.
const renderSource = renderer.renderSource.bind(renderer);
renderer.renderSource = (source, scope, label) => renderSource(
  (label === 'credit._picker' || label === 'credit/_picker' ? source.replace(/@php[\s\S]*?@endphp/, '') : source)
    .replace("@js(old('payments', []))", '[]'), scope, label);
const content = renderer.section('folios/show', 'content', ctx.scope);
const actions = renderer.section('folios/show', 'actions', ctx.scope);
const scripts = renderer.push('folios/show', 'scripts', ctx.scope);
const crumbs = renderer.section('folios/show', 'crumbs', ctx.scope);
const banner = '<div class="alert alert-info" role="note"><b>Sample folio preview.</b> Fictional data; forms cannot save. Combined reservation checkout is not yet available.</div>';
const readOnly = '<script>document.addEventListener("submit", function(e) { e.preventDefault(); });</script>';
const html = shell(nav().navHtml, spriteSymbols(), banner + content, { title: `Folio ${ctx.scope.folio.folio_no}`, crumbs, actions, scripts: readOnly + scripts }).replace(/[ \t]+$/gm, '');
if (log.failed.length || log.errors.length || log.unknownComponents.length || log.unknownDirectives.length) {
  console.error(JSON.stringify({ failed: log.failed, errors: log.errors, components: log.unknownComponents, directives: log.unknownDirectives, phpSkipped: log.phpSkipped }, null, 2));
  process.exit(1);
}
if (process.argv.includes('--check')) {
  if (!fs.existsSync(out) || fs.readFileSync(out, 'utf8') !== html) { console.error('folio page preview is stale'); process.exit(1); }
  console.log('folio page preview is up to date');
} else { fs.writeFileSync(out, html); console.log(`wrote ${out} (${log.expressions} expressions)`); }
