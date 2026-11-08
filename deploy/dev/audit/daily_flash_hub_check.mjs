import assert from 'node:assert/strict';
import fs from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';
import { BladePreview, Coll } from '../preview/blade-render.mjs';
import { briefingHubContext } from '../preview/briefing-sample.mjs';

const html = fs.readFileSync('deploy/dev/previews/briefing-hub.html', 'utf8');
const errors = [];
const vc = new VirtualConsole(); vc.on('jsdomError', error => errors.push(error.message));
const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'http://preview.test/', virtualConsole: vc, beforeParse(window) {
  window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
} });
const doc = dom.window.document;
assert.match(doc.title, /^Daily Flash ·/);
assert.match(doc.querySelector('[role=note]').textContent, /fictional data.*cannot be saved/i);
assert.equal(doc.querySelectorAll('.flash-card').length, 18);
assert.equal(doc.querySelectorAll('.flash-dialog').length, 18);
assert.match(doc.querySelector('.flash-summary').textContent, /18\s*Visible departments\s*11\s*Signed today\s*7\s*Awaiting sign-off/);
const cards = [...doc.querySelectorAll('.flash-card')];
for (const card of cards) {
  const button = card.querySelector('[data-flash-open]');
  const dialog = doc.getElementById(button.dataset.flashOpen);
  assert.equal(button.getAttribute('aria-controls'), dialog.id);
  assert.equal(dialog.getAttribute('aria-labelledby'), dialog.querySelector('h2').id);
  assert.ok(dialog.querySelector('.flash-dialog-body > p').textContent.trim(), 'full description is retained');
  assert.ok(card.querySelector('.flash-card-actions a').getAttribute('href').startsWith('/briefing-'));
}
const fnb = doc.getElementById('flash-detail-fnb');
doc.querySelector('[data-flash-open="flash-detail-fnb"]').click();
assert.equal(fnb.hasAttribute('open'), true, 'details button opens its dialog');
assert.match(fnb.textContent, /Outlet sales and covers/);
assert.match(fnb.textContent, /Kwame Asare/);
assert.match(fnb.textContent, /Beach bar fridge 2 down/);
assert.equal(fnb.querySelector('form[method=dialog] button').getAttribute('aria-label'), 'Close details');
assert.deepEqual(errors, []);
dom.window.close();

// Render restricted and empty permission outcomes from the same Blade view.
const render = (packs, signed, canExec, hidden) => {
  const ctx = briefingHubContext();
  ctx.scope.packs = Coll.of(packs); ctx.scope.visibleSigned = signed; ctx.scope.canExec = canExec; ctx.scope.hidden = hidden;
  const log = { expressions: 0, fallbacks: 0, failed: [], conditionsDefaulted: [], phpSkipped: [], unknownComponents: [], unknownDirectives: [], errors: [] };
  const body = new BladePreview({ root: process.cwd(), ctx, log }).section('briefings/index', 'content', ctx.scope);
  assert.deepEqual(log.failed, []); assert.deepEqual(log.errors, []);
  return new JSDOM(body).window.document;
};
const all = briefingHubContext().scope.packs.items;
const limited = render([all[2], all[4]], 1, false, 16);
assert.equal(limited.querySelectorAll('.flash-card').length, 2);
assert.equal(limited.querySelector('.flash-executive'), null);
assert.match(limited.querySelector('.flash-summary').textContent, /2\s*Visible departments\s*1\s*Signed today\s*1\s*Awaiting sign-off/);
assert.match(limited.querySelector('.flash-visibility-note').textContent, /16 further department flashes/);
assert.equal(limited.querySelector('#flash-detail-finance'), null, 'inaccessible details are not rendered');
const empty = render([], 0, true, 18);
assert.equal(empty.querySelectorAll('.flash-card').length, 0);
assert.match(empty.querySelector('.flash-summary').textContent, /0\s*Visible departments\s*0\s*Signed today\s*0\s*Awaiting sign-off/);
assert.match(empty.querySelector('.flash-executive .badge').textContent, /0\/0 visible signed/);
assert.ok(empty.querySelector('.flash-executive .badge').classList.contains('badge-amber'));
const controller = fs.readFileSync('app/Http/Controllers/BriefingController.php', 'utf8');
assert.ok(controller.includes("'visibleSigned' => $visible->where('signed', true)->count()"), 'server counts only visible signed packs');
const show = fs.readFileSync('resources/views/briefings/show.blade.php', 'utf8');
assert.ok(show.includes("['pack' => $p['key'], 'date' => $date->toDateString()]"));
assert.ok(show.includes("$date->toDateString() === $today->toDateString() && $p['signed']"), 'today sign-off indicator is not shown as historical');
console.log('Daily Flash hub and permission-scoped preview checks passed.');
