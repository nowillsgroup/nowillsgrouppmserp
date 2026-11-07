#!/usr/bin/env node
/*
 * The e-GRC screen is what a guest signs on a tablet, so the details it shows have to be
 * the ones they are agreeing to: a day use printed as "3 night / GH₵ 620.00 / night"
 * is a signature against the wrong contract.
 *
 * This reads deploy/dev/previews/egrc-signature.html in jsdom and checks the screen
 * against deploy/dev/preview/egrc-sample.mjs — the same record the printed card uses —
 * then renders the day-use and unsigned states through the same builder.
 *
 * Run: node deploy/dev/audit/egrc_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { renderEgrc } from '../build-egrc-preview.mjs';

const FILE = 'deploy/dev/previews/egrc-signature.html';
const source = readFileSync(FILE, 'utf8');
const dom = new JSDOM(source, { url: 'https://preview.local/front-desk/stay/5079/grc/sign' });
const doc = dom.window.document;

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const text = (selector) => ($(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();

const facts = (root) => [...root.querySelectorAll('.egrc-fact')]
  .map(fact => [fact.querySelector('dt')?.textContent.trim() ?? '', fact.querySelector('dd')?.textContent.trim() ?? '']);
const fact = (root, label) => facts(root).find(([name]) => name === label)?.[1];

// ── the application shell ──────────────────────────────────────────────────
ok('is a whole document', /^<!doctype html>/i.test(source.trimStart()));
ok('carries the sidebar', $$('.sidebar .nav a').length > 50, `${$$('.sidebar .nav a').length} links`);
ok('titles the page', /e-Registration card/.test(doc.title));
ok('names the screen in the page head', /e-Registration card/.test(text('.page-head h1')));
ok('breadcrumbs name the reservation', /RES-24391/.test(text('.crumbs')), text('.crumbs'));
ok('the page offers the printed card', /Print GRC/.test(text('.page-head .actions')));
ok('the page offers the way back', /Back to reservation/.test(text('.page-head .actions')));

// ── the hero ───────────────────────────────────────────────────────────────
const chips = $$('.egrc-chip, .egrc-sign-state').map(chip => chip.textContent.replace(/\s+/g, ' ').trim());
ok('the hero names the room and type', chips.some(chip => /102 · Standard Garden/.test(chip)), chips.join(' | '));
ok('the hero states the stay status', chips.some(chip => chip === 'CHECKED IN'));
ok('a signed card says when it was signed', chips.some(chip => /^Signed 06 Nov 2026 · 14:14$/.test(chip)), chips.join(' | '));

// ── the record the guest is signing ────────────────────────────────────────
ok('four cards carry the record', $$('.egrc-card h2').length === 4, `${$$('.egrc-card h2').length}`);
ok('the guest identity card is present', /Guest identity & contact/.test(text('.egrc-card h2')));
ok('the guest is named', fact(doc, 'Guest') === 'Kwabena Mensah-Aidoo', fact(doc, 'Guest') ?? '');
ok('the document type is spelled out', fact(doc, 'ID type') === 'Passport', fact(doc, 'ID type') ?? '');
ok('the document number is shown', fact(doc, 'ID number') === 'G1234567');
ok('the address is assembled, not blanked', /Airport Residential Area, Accra, Greater Accra, Ghana/.test(fact(doc, 'Address') ?? ''), fact(doc, 'Address') ?? '');
ok('the stay names the reservation', fact(doc, 'Reservation') === 'RES-24391');
ok('the stay names the room', /102 · Standard Garden/.test(fact(doc, 'Room') ?? ''));
ok('the arrival is the check-in time', /06 Nov 2026 · 14:12/.test(fact(doc, 'Arrival') ?? ''), fact(doc, 'Arrival') ?? '');
ok('an overnight shows its departure date', /09 Nov 2026/.test(fact(doc, 'Departure') ?? ''));
ok('an overnight counts its nights', fact(doc, 'Length of stay') === '3 night(s)', fact(doc, 'Length of stay') ?? '');
ok('the rate is qualified per night and by board', /GH₵ 620\.00 \/ night · Bed & breakfast/.test(fact(doc, 'Room rate') ?? ''), fact(doc, 'Room rate') ?? '');
ok('the party is counted', /2 adult\(s\)/.test(fact(doc, 'Party') ?? ''));
ok('the declaration is the property terms', /liable for the room rate/.test(text('.egrc-terms')));
ok('the declaration names check-out time', /Check-out time is 12:00/.test(text('.egrc-terms')));
ok('the signature card points at those terms', $('#sigForm')?.getAttribute('aria-describedby') === 'egrcTerms');

// ── the signature pad ──────────────────────────────────────────────────────
ok('the pad is a canvas', $('#sigPad')?.tagName === 'CANVAS');
ok('the pad is labelled for a tablet', /Draw the guest signature here/.test($('#sigPad')?.getAttribute('aria-label') ?? ''));
ok('the save button waits for ink', $('#sigSave')?.hasAttribute('disabled'));
ok('the guest must accept the terms', $('input[name="accept"]')?.hasAttribute('required'));
ok('the acceptance names the guest', /I, Kwabena Mensah-Aidoo, confirm the details above/.test(text('.egrc-accept')));
ok('a signed card shows the signature on file', /Current signature on file/.test(text('.egrc-sign-existing')));
ok('the saved signature is served from the private route', $('#sigPad') && /Open original signature image/.test(text('.egrc-sign-existing')));
ok('the script clears and saves the pad', /sigClear/.test(source) && /sigData/.test(source));

// ── documents ──────────────────────────────────────────────────────────────
ok('the document card is present', $$('.egrc-card h2').some(head => /Identity documents/.test(head.textContent)));
ok('the document card is private by default', /Stored privately/.test(text('.egrc-doc-head-note')));
ok('every document type is offered', $$('#egrcDocumentType option').length === 6, `${$$('#egrcDocumentType option').length}`);
ok('the scans on file are listed', $$('.doc-card').length === 2, `${$$('.doc-card').length}`);
ok('each scan carries its label', $$('.doc-card').some(card => /Ghana Card/.test(card.textContent)));

// ── nothing half-rendered ──────────────────────────────────────────────────
ok('nothing leaks from Blade', !/@(if|endif|foreach|endforeach|endsection|endpush|php|endphp)\(?/.test(text('body')));
ok('nothing prints as undefined', !/\bundefined\b/.test(text('body')) && !/NaN/.test(doc.body.innerHTML));
ok('nothing prints as an object', !/\[object Object\]/.test(doc.body.innerHTML));
ok('the screen does not describe itself as a preview', !/preview|illustrative|sample data|demo data/i.test(text('body')));

// ── the day-use screen ─────────────────────────────────────────────────────
const dayDoc = new JSDOM(renderEgrc({ dayUse: true }).html).window.document;
const dayText = (selector) => (dayDoc.querySelector(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dayChips = [...dayDoc.querySelectorAll('.egrc-chip, .egrc-sign-state')].map(c => c.textContent.replace(/\s+/g, ' ').trim());

ok('a day use is chipped with its hour', dayChips.some(chip => chip === 'Day use · released 18:00'), dayChips.join(' | '));
ok('a day use asks for the release hour, not a departure date', fact(dayDoc, 'Room released') === '18:00', fact(dayDoc, 'Room released') ?? '');
ok('a day use has no departure row', fact(dayDoc, 'Departure') === undefined);
ok('a day use is not counted in nights', fact(dayDoc, 'Length of stay') === 'Day use · charged once', fact(dayDoc, 'Length of stay') ?? '');
ok('a day use prices itself once', /GH₵ 380\.00/.test(fact(dayDoc, 'Day-use charge') ?? ''), fact(dayDoc, 'Day-use charge') ?? '');
ok('a day use carries no nightly rate', fact(dayDoc, 'Room rate') === undefined && !/night/.test(dayText('.egrc-card') ?? ''));
ok('a day use still shows the terms and the pad', Boolean(dayDoc.querySelector('.egrc-terms')) && Boolean(dayDoc.querySelector('#sigPad')));

// ── the unsigned screen ────────────────────────────────────────────────────
const unDoc = new JSDOM(renderEgrc({ signed: false }).html).window.document;
const unText = (selector) => (unDoc.querySelector(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const unChips = [...unDoc.querySelectorAll('.egrc-chip, .egrc-sign-state')].map(c => c.textContent.replace(/\s+/g, ' ').trim());
ok('an unsigned card is chipped as pending', unChips.some(chip => chip === 'Signature pending'), unChips.join(' | '));
ok('an unsigned card shows no saved signature', !unDoc.querySelector('.egrc-sign-existing'));
ok('an unsigned card says the signature card is empty', unChips.includes('Not signed') && Boolean(unDoc.querySelector('#sigPad')), unChips.join(' | '));
ok('an unsigned card keeps the terms visible', /liable for the room rate/.test(unText('.egrc-terms')));

if (failures.length) {
  console.error(`e-GRC preview check: ${failures.length} failed, ${passed} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`e-GRC preview check: ${passed} assertions passed`);
