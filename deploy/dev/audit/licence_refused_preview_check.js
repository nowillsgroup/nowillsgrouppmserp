#!/usr/bin/env node
/*
 * v10.6 the refusal that explains itself, held to what it claims.
 *
 * v10.3 answered an unlicensed module with one sentence: "not on this group's licence". That is
 * wrong half the time — a group may hold the module and the property the reader is standing in may
 * have switched it off, and the fix then belongs to a different administrator. This check reads the
 * built page the way the person who typed the URL does, and holds it to saying which of the two it
 * is, naming the permission it did not doubt, and not sending them to a screen they cannot open.
 *
 * Run: node deploy/dev/audit/licence_refused_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('Licence refusal preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) {
    passed += 1;
    return;
  }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const page = new JSDOM(read('deploy/dev/previews/licence-refused.html'), { url: 'https://preview.local/spa/bookings' });
const doc = page.window.document;
const body = doc.body.textContent.replace(/\s+/g, ' ');

ok('the page is titled for the module that was refused', doc.title.includes('Spa & Activities is not licensed'), doc.title);
ok('the module is named at the top', body.includes('Spa & Activities is not licensed here'));
ok('the refusal says it happened at the route, not at a permission check', body.includes('Refused at the route'));
ok('the permission the reader does hold is named, so the doubt is removed', body.includes('spa.bookings'));
ok('and it says outright that this is not about their rights', body.includes('not a question of your rights'));

// the branch that matters: the group has it, the property does not
ok('it says the group is licensed for the module', body.includes('Your group is licensed for Spa & Activities'));
ok('and that this property switched it off for itself', body.includes('has switched it off for itself'));
ok('it names the property', body.includes('The Grove Essipong Beach Resort'));
ok('it names the person who can fix it', body.includes('Ask the group administrator who runs The Grove Essipong Beach Resort'));
ok('and says the rest of the group is unaffected', body.includes('does not affect any other property'));
ok('it does not claim the group never bought it', !body.includes('is not on your group'));
ok('it does not claim the reader lacks the permission', !body.includes("You do not have permission"));

// what the refusal promises about the data and the rest of the product
ok('it says nothing was deleted', body.includes('Nothing has been deleted'));
ok('it says the rows stay and re-licensing restores the work', body.includes('restores the work rather than recovering it'));
ok('it scopes the refusal to this property', body.includes('This property only'));
ok('it says the guest side follows the same licence', body.includes('The guest side follows the same licence') || body.includes('refused with it'));

// the ways back
ok('it offers a way back to the dashboard', [...doc.querySelectorAll('a')].some(a => (a.getAttribute('href') || '').includes('dashboard')));
ok('it offers the property page the reader may open', [...doc.querySelectorAll('a')].some(a => (a.getAttribute('href') || '').includes('properties-show')));
ok('it does not offer a screen this reader cannot open', ![...doc.querySelectorAll('a')].some(a => (a.getAttribute('href') || '').includes('group-account')));

// v10.7 the sidebar is held to the same licence: the screens the page says are hidden are gone
const navLabels = [...doc.querySelectorAll('.nav-item[data-nav-label]')].map(el => el.getAttribute('data-nav-label'));
ok('the sidebar no longer offers the module this page refuses', !navLabels.includes('Appointments'));
ok('nor its masters screens', !navLabels.includes('Services & Activities') && !navLabels.includes('Treatment Rooms'));
ok('nor the group it hung under', !navLabels.includes('Spa & Activities'));
ok('while the rest of the product is still there', navLabels.includes('Front Desk') && navLabels.includes('Housekeeping'));
ok('and the page says the screens are hidden, not merely refused', body.includes('hides its screens'));

if (failures.length) {
  console.error(`Licence refusal preview check: ${failures.length} failed, ${passed} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`Licence refusal preview check: ${passed} assertions passed`);
