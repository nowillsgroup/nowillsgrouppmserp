#!/usr/bin/env node
/*
 * A registration card fails quietly. A missing section still prints, a blank line where
 * the passport should be looks like a form waiting to be filled in, and a day use printed
 * as "3 nights" is a charge dispute three weeks later.
 *
 * This reads deploy/dev/previews/guest-registration-card.html in jsdom and checks the
 * sheet against deploy/dev/preview/grc-sample.mjs, then renders the two states the sheet
 * has to survive — a day use, and a card not yet signed — through the same builder.
 *
 * Run: node deploy/dev/audit/grc_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { renderCard } from '../build-grc-preview.mjs';

const FILE = 'deploy/dev/previews/guest-registration-card.html';
const source = readFileSync(FILE, 'utf8');
const dom = new JSDOM(source, { url: 'https://preview.local/front-desk/stay/5079/grc' });
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
const textOf = (root) => (root?.textContent ?? '').replace(/\s+/g, ' ').trim();

// ── it is a printed sheet, not a page in the app shell ─────────────────────
ok('is a whole document', /^<!doctype html>/i.test(source.trimStart()));
ok('prints on the print canvas', Boolean($('.print-page.grc-sheet')));
ok('carries the print controls', Boolean($('.print-bar') && $('.print-bar button')));
ok('shows the property as stationery', /The Grove Essipong Beach Resort/.test(text('.print-head')));
ok('shows the property contact line', /\+233 31 202 4470/.test(text('.print-head')) && /frontdesk@thegroveessipong\.com/.test(text('.print-head')));
ok('names the document', /Guest Registration Card/.test(text('.print-head')));
ok('no sidebar or app chrome', !$('.sidebar') && !$('.topbar'));

// ── the card ───────────────────────────────────────────────────────────────
const card = $('#guestRegistrationCard');
ok('the card the checks look for is present', Boolean(card));
ok('the card is titled for the reader', /Guest registration/.test(text('.grc-band h2')));
ok('the card carries its own reference', /GRC-RES-24391-5079/.test(text('.grc-band')));
ok('the reference appears in the document meta too', /GRC-RES-24391-5079/.test(text('.grc-docmeta')));

const chips = $$('.grc-chip').map(chip => textOf(chip));
ok('the card states the stay status', chips.includes('CHECKED IN'), chips.join(' | '));
ok('a signed card says so', chips.includes('E-signed') && !chips.includes('Signature required'), chips.join(' | '));

// ── the four facts read first ──────────────────────────────────────────────
const glance = $$('.grc-glance > div');
ok('four facts sit at the top', glance.length === 4, `${glance.length} cells`);
ok('the guest is named', /Kwabena Mensah-Aidoo/.test(textOf(glance[0])));
ok('the guest line carries nationality and document', /Ghanaian · Passport/.test(textOf(glance[0])));
ok('the room is shown with its type', /102/.test(textOf(glance[1])) && /Standard Garden/.test(textOf(glance[1])));
ok('the stay reads as dates', /06 Nov → 09 Nov 2026/.test(textOf(glance[2])));
ok('the party is counted', /2 adults/.test(textOf(glance[2])));
ok('the rate is money, not a number', /GH₵ 620\.00/.test(textOf(glance[3])));
ok('the board is spelled out', /Bed & breakfast/.test(textOf(glance[3])));

// ── the numbered blocks ────────────────────────────────────────────────────
const blocks = $$('.grc-block > h3').map(head => textOf(head));
ok('the card has four numbered blocks', blocks.length === 4, `${blocks.length} blocks`);
ok('block 1 is the guest', /^1 Guest particulars/.test(blocks[0]), blocks[0] ?? '');
ok('block 2 is the identity document', /^2 Identity document/.test(blocks[1]), blocks[1] ?? '');
ok('block 3 is the stay and charges', /^3 Stay, room and charges/.test(blocks[2]), blocks[2] ?? '');
ok('block 4 is the declaration', /^4 Guest declaration/.test(blocks[3]), blocks[3] ?? '');

const fields = $$('.grc-f');
const fieldPairs = fields.map(field => [textOf(field.querySelector('dt')), textOf(field.querySelector('dd'))]);
const field = (label) => fieldPairs.find(([name]) => name === label)?.[1];
ok('the card puts the particulars on it', fields.length >= 24, `${fields.length} fields`);
ok('a full name is printed', field('Full name') === 'Kwabena Mensah-Aidoo', field('Full name') ?? '');
ok('the date of birth is printed in the house format', /^\d{2} \w{3} \d{4}$/.test(field('Date of birth') ?? ''), field('Date of birth') ?? '');
ok('the document number is printed', field('Document number') === 'G1234567', field('Document number') ?? '');
ok('the passport number is printed', field('Passport number') === 'G1234567');
ok('the arrival time is the check-in time', /06 Nov 2026 14:12/.test(field('Arrival') ?? ''), field('Arrival') ?? '');
ok('the nights are counted', field('Nights') === '3', field('Nights') ?? '');
ok('the room rate is qualified per night', /GH₵ 620\.00 \/ night/.test(field('Room rate') ?? ''), field('Room rate') ?? '');
ok('the company account is carried', field('Company / account') === 'Acme Mining Ltd');
ok('the company TIN is carried', field('Company TIN') === 'C0009876543');
ok('the scans on file are named', /Passport, Ghana Card/.test(text('.grc-note')));

// ── blanks are for the clerk's pen, not sentences ───────────────────────────
const visaCell = fields.find(field => textOf(field.querySelector('dt')) === 'Visa expiry')?.querySelector('dd');
ok('a missing visa is left as a ruled blank', visaCell?.classList.contains('blank') === true && textOf(visaCell) === '');
ok('no field says "Not provided"', !/Not provided/.test(textOf(card)));
const blankCells = $$('dd.blank');
ok('empty values are ruled blanks', blankCells.every(cell => textOf(cell) === ''), `${blankCells.length} blanks`);
ok('every blank sits under a label', blankCells.every(cell => Boolean(cell.closest('.grc-f')?.querySelector('dt'))));

// ── the declaration, the signature, the office strip ───────────────────────
ok('the declaration carries the property terms', /liable for the room rate/.test(text('.grc-declare')));
ok('the declaration names check-out time', /Check-out time is 12:00/.test(text('.grc-declare')));
ok('the declaration lists the guest duties', $$('.grc-declare li').length >= 4);
const signature = $('.grc-sign-guest img');
ok('the signed card carries the signature image', Boolean(signature));
ok('the signature is inlined, never linked', /^data:image\//.test(signature?.getAttribute('src') ?? ''), (signature?.getAttribute('src') ?? '').slice(0, 24));
ok('the signature has alt text', signature?.getAttribute('alt') === 'Guest signature');
ok('the signature block is dated', /E-signed 06 Nov 2026 14:14/.test(text('.grc-sign')));
ok('the desk signs the same card', /Front-office representative/.test(text('.grc-sign')));
ok('the office-use strip is present', /For office use/.test(text('.grc-office')));
ok('the strip has its ticks and lines', $$('.grc-tick').length === 4, `${$$('.grc-tick').length}`);
ok('the footer closes the sheet', /GRC-RES-24391-5079/.test(text('.grc-footer')) && /Printed 06 Nov 2026 14:20/.test(text('.grc-footer')));

// ── print rules, and nothing half-rendered ─────────────────────────────────
ok('the sheet asks for A4 portrait', /@page\s*\{\s*size:\s*A4 portrait/.test(source));
ok('blocks are told not to split across pages', /break-inside:\s*avoid/.test(source));
ok('the bands keep their colour when printed', /print-color-adjust:\s*exact/.test(source));
ok('nothing leaks from Blade', !/@(if|endif|foreach|endforeach|endsection|endpush|php|endphp)\(?/.test(textOf(doc.body)));
ok('nothing prints as undefined', !/\bundefined\b/.test(textOf(doc.body)) && !/NaN/.test(textOf(card)));
ok('nothing prints as an object', !/\[object Object\]/.test(card.innerHTML));
ok('the sheet does not describe itself as a preview', !/preview|illustrative|sample data|demo data/i.test(textOf(doc.body)));

// ── the day use card ───────────────────────────────────────────────────────
const dayCard = new JSDOM(renderCard({ dayUse: true }).html).window.document;
const dq = (selector) => dayCard.querySelector(selector);
const dqText = (selector) => (dq(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dayFields = [...dayCard.querySelectorAll('.grc-f')].map(f => [f.querySelector('dt').textContent.trim(), f.querySelector('dd').textContent.trim()]);
const dayField = (label) => dayFields.find(([name]) => name === label)?.[1];

ok('a day use is chipped as one', [...dayCard.querySelectorAll('.grc-chip')].some(c => c.textContent.trim() === 'Day use'));
ok('a day use says when the room is released', /Room released 18:00/.test(dqText('.grc-glance')));
ok('a day use is not sold as nights', dayField('Sold as') === 'Day use · charged once', dayField('Sold as') ?? '');
ok('a day use has no night count on the card', dayField('Nights') === undefined);
ok('a day use prices itself once', /GH₵ 380\.00/.test(dayField('Day-use charge') ?? ''), dayField('Day-use charge') ?? '');
ok('a day use says no night is sold', /no night sold/.test(dqText('.grc-glance')));
ok('a day use still carries the declaration and signature', Boolean(dq('.grc-declare')) && Boolean(dq('.grc-sign-guest img')));

// ── the unsigned card ──────────────────────────────────────────────────────
const un = new JSDOM(renderCard({ signed: false }).html).window.document;
const unText = (selector) => (un.querySelector(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();
ok('an unsigned card asks for the signature', [...un.querySelectorAll('.grc-chip')].some(c => c.textContent.trim() === 'Signature required'));
ok('an unsigned card shows no signature image', !un.querySelector('.grc-sign-guest img'));
ok('an unsigned card leaves the line ready to sign', /Signed at the desk on arrival/.test(unText('.grc-sign-guest')));
ok('an unsigned card still dates the line', /Date: 06 Nov 2026/.test(unText('.grc-sign')));

if (failures.length) {
  console.error(`GRC preview check: ${failures.length} failed, ${passed} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`GRC preview check: ${passed} assertions passed`);
