#!/usr/bin/env node
/*
 * The Rooms master has to let staff load a room's pictures, so the preview of that
 * screen is checked the way a person would use it: the create screen offers the
 * upload, the edit screen shows the photos already on the room and offers to promote
 * or remove them, and both post to the routes the controller serves.
 *
 * Run: node deploy/dev/audit/rooms_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const DIR = resolve(ROOT, 'deploy/dev/previews');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('rooms preview check: SKIP (jsdom is not installed)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (label, condition, detail = '') => {
  if (condition) { passed += 1; return; }
  failures.push(detail ? `${label} — ${detail}` : label);
};

const load = (file) => {
  const path = resolve(DIR, file);
  ok(`${file} exists`, existsSync(path));
  if (!existsSync(path)) return null;
  return new JSDOM(readFileSync(path, 'utf8')).window.document;
};

/* ── the create screen: the upload lives on the form itself ───────────────── */
const create = load('rooms-create.html');
if (create) {
  const form = create.querySelector('main form.card');
  ok('create: the room form is on the page', Boolean(form));
  ok('create: the form posts a new room', form?.getAttribute('action') === '/masters/rooms',
    form?.getAttribute('action') ?? 'no action');
  ok('create: the form accepts files', form?.getAttribute('enctype') === 'multipart/form-data',
    form?.getAttribute('enctype') ?? 'no enctype');

  const input = form?.querySelector('input[type="file"][name="photos[]"]');
  ok('create: a room photo upload is on the form', Boolean(input));
  ok('create: the upload takes more than one photo', input?.hasAttribute('multiple') === true);
  ok('create: the upload only takes pictures',
    /image\/jpeg/.test(input?.getAttribute('accept') ?? '') && /image\/webp/.test(input?.getAttribute('accept') ?? ''));
  ok('create: the upload sits under a label', Boolean(form?.querySelector('label[for="f_photos"]')));
  ok('create: a caption can be written for the upload', Boolean(form?.querySelector('input[name="photo_caption"]')));
  ok('create: the size rule is stated', /4 MB/.test(form?.textContent ?? ''));
  ok('create: the form says which photo becomes primary', /primary/.test(form?.textContent ?? ''));
  ok('create: no gallery before the room exists', !create.querySelector('.room-photo-grid'));
  ok('create: the room fields render', create.querySelectorAll('main form.card .field input, main form.card .field select').length >= 5,
    `${create.querySelectorAll('main form.card .field input, main form.card .field select').length} controls`);
  ok('create: the save button is there', /Save/.test(create.querySelector('.card-foot')?.textContent ?? ''));
}

/* ── the edit screen: the photos already on the room ─────────────────────── */
const edit = load('rooms-edit.html');
if (edit) {
  const form = edit.querySelector('main form.card');
  ok('edit: the form updates the room', form?.getAttribute('action') === '/masters/rooms/204',
    form?.getAttribute('action') ?? 'no action');
  ok('edit: more photos can be added from the form', Boolean(form?.querySelector('input[type="file"][name="photos[]"]')));

  const cards = [...edit.querySelectorAll('main .card')].filter(c => /Room photos/.test(c.querySelector('h3')?.textContent ?? ''));
  ok('edit: the gallery card is on the record', cards.length === 1, `${cards.length} cards`);
  const card = cards[0];

  const figures = card ? [...card.querySelectorAll('.room-photo')] : [];
  ok('edit: every photo on the room is shown', figures.length === 3, `${figures.length} photos`);
  ok('edit: each photo is an image with a source',
    figures.length > 0 && figures.every(f => (f.querySelector('img')?.getAttribute('src') ?? '').startsWith('/preview-assets/')));
  ok('edit: each photo has alt text', figures.every(f => (f.querySelector('img')?.getAttribute('alt') ?? '').length > 0));
  ok('edit: each tile links to the full-size photo',
    figures.every(f => (f.querySelector('a.room-photo-full')?.getAttribute('href') ?? '').startsWith('/preview-assets/')),
    figures[0]?.querySelector('a.room-photo-full')?.getAttribute('href'));
  ok('edit: one photo is marked primary',
    figures.filter(f => /Primary/.test(f.textContent)).length === 1,
    `${figures.filter(f => /Primary/.test(f.textContent)).length} marked`);
  ok('edit: the count on file is shown', /3 on file/.test(card?.textContent ?? ''),
    (card?.textContent ?? '').trim().slice(0, 60));

  const primaryButtons = card ? [...card.querySelectorAll('form button')].filter(b => /Make primary/.test(b.textContent)) : [];
  ok('edit: the other photos can be made primary', primaryButtons.length === 2, `${primaryButtons.length} buttons`);
  const hideForms = card ? [...card.querySelectorAll('form')].filter(f => /\/hide$/.test(f.getAttribute('action') ?? '')) : [];
  ok('edit: every photo can be removed', hideForms.length === 3, `${hideForms.length} remove forms`);
  ok('edit: removing asks first', hideForms.every(f => (f.dataset.confirm ?? '').length > 20));
  ok('edit: the primary photo cannot be made primary again',
    !figures.filter(f => /Primary/.test(f.textContent)).some(f => /Make primary/.test(f.textContent)));

  const more = card ? [...card.querySelectorAll('form')].find(f => f.getAttribute('enctype') === 'multipart/form-data') : null;
  ok('edit: more photos can be uploaded from the gallery', Boolean(more));
  ok('edit: the gallery upload posts to the photo route', more?.getAttribute('action') === '/masters/rooms/204/photos',
    more?.getAttribute('action') ?? 'no action');

  const primaryForm = card ? [...card.querySelectorAll('form')].find(f => /\/primary$/.test(f.getAttribute('action') ?? '')) : null;
  ok('edit: promoting a photo posts to the primary route',
    primaryForm?.getAttribute('action') === '/masters/rooms/204/photos/42/primary',
    primaryForm?.getAttribute('action') ?? 'no action');
}

/* ── both screens are application screens ────────────────────────────────── */
for (const [label, doc] of [['create', create], ['edit', edit]]) {
  if (!doc) continue;
  ok(`${label}: the application shell is around the page`, Boolean(doc.querySelector('aside, nav.nav')));
  ok(`${label}: the page head names the screen`, Boolean(doc.querySelector('.page-head h1')));
  ok(`${label}: the stylesheet is linked`,
    [...doc.querySelectorAll('link[rel="stylesheet"]')].some(l => /app\.css/.test(l.getAttribute('href') ?? '')));
}

if (failures.length) {
  console.log(`rooms preview check: ${failures.length} FAILED, ${passed} passed`);
  failures.forEach(f => console.log(`  ✗ ${f}`));
  process.exit(1);
}
console.log(`rooms preview check: ${passed} assertions passed`);
