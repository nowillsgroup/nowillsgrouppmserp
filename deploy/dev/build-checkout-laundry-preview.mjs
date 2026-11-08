#!/usr/bin/env node
/** Render the real checkout view in the app shell using static sample data. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
import { checkoutLaundryContext } from './preview/checkout-laundry-sample.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const out = path.join(root, 'deploy/dev/previews/checkout-laundry.html');
const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
const ctx = checkoutLaundryContext();
const renderer = new BladePreview({ root, ctx, log });
// No companies in this sample. The credit picker's database calculation has
// no preview equivalent; provide an empty $info and leave the real form intact.
// The renderer does not handle @js yet, so resolve this fixed empty old input.
const renderSource = renderer.renderSource.bind(renderer);
renderer.renderSource = (source, scope, label) => renderSource(
  (label === 'credit._picker' || label === 'credit/_picker' ? source.replace(/@php[\s\S]*?@endphp/, '') : source)
    .replace("@js(old('payments', []))", '[]'), scope, label);
const content = renderer.section('frontdesk/checkout', 'content', ctx.scope);
const actions = renderer.section('frontdesk/checkout', 'actions', ctx.scope);
const scripts = renderer.push('frontdesk/checkout', 'scripts', ctx.scope);
const { navHtml } = nav();
const banner = '<div class="alert alert-info" role="note">Sample preview only — no live guest data, orders or checkout actions.</div>';
const html = shell(navHtml, spriteSymbols(), banner + content, { title: `Check-out · Room ${ctx.scope.stay.room.room_no}`, crumbs: 'Front Office · Check-out', actions, scripts }).replace(/[ \t]+$/gm, '');
if (log.failed.length || log.errors.length || log.unknownComponents.length || log.unknownDirectives.length) {
  console.error(JSON.stringify({ failed: log.failed, errors: log.errors, unknownComponents: log.unknownComponents, unknownDirectives: log.unknownDirectives }, null, 2));
  process.exit(1);
}
if (process.argv.includes('--check')) {
  if (!fs.existsSync(out) || fs.readFileSync(out, 'utf8') !== html) { console.error('checkout laundry preview is stale'); process.exit(1); }
  console.log('checkout laundry preview is up to date');
} else { fs.writeFileSync(out, html); console.log(`wrote ${out} (${log.expressions} expressions)`); }
