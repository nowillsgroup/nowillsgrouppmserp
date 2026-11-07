#!/usr/bin/env node
/*
 * A day-use stay is only honest if the walk-in form actually offers it and the
 * rest of the form behaves when it is chosen. Neither shows up as an error:
 * a hidden day-use field, a night count still demanding one night, or a rate
 * label still promising "per night" all look like a working form.
 *
 * This renders deploy/dev/previews/walkin-checkin.html in jsdom and checks the
 * stay-type pair, the hour field, the nights field, the rate, the room list and
 * the script that switches between the two stay types.
 *
 * Run: node deploy/dev/audit/walkin_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/walkin-checkin.html';
const dom = new JSDOM(readFileSync(FILE, 'utf8'), { url: 'https://preview.local/frontdesk/walkin' });
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
const pageLabelText = () => $$('label').map(l => l.textContent.replace(/\s+/g, ' ').trim()).join(' | ');

// ── the screen is a screen, not a fragment ──────────────────────────────────
ok('is a whole document', /^<!doctype html>/i.test(readFileSync(FILE, 'utf8').trimStart()));
ok('carries the application shell', $$('nav a, aside a').length > 5);
ok('titles the screen', /Walk-in check-in/i.test(doc.title), doc.title);
ok('holds the form', Boolean($('form')));

// ── the stay-type pair ─────────────────────────────────────────────────────
const overnight = $('input[name="stay_type"][value="overnight"]');
const dayUse = $('input[name="stay_type"][value="day_use"]');
ok('offers an overnight stay', Boolean(overnight));
ok('offers a day use stay', Boolean(dayUse));
ok('the pair shares one name', overnight?.getAttribute('name') === 'stay_type');
ok('the stay type group is marked required', /Stay \*/.test(pageLabelText()) || /<label id="stayTypeLabel">Stay[\s\S]{0,60}req/.test(readFileSync(FILE, 'utf8')));
ok('the stay type is a radio pair', overnight?.type === 'radio' && dayUse?.type === 'radio');
ok('day use is the one held open', Boolean(dayUse?.checked), 'the sample re-shows a day-use submission');
ok('overnight is not also held', !overnight?.checked);

// ── the hour the room is needed until ──────────────────────────────────────
const dayField = $('#walkinDayUseField');
const hours = $('#f_day_use_hours');
const hourOptions = [...(hours?.options ?? [])];
ok('the day-use field exists', Boolean(dayField));
ok('the day use asks for a number of hours', hours?.tagName === 'SELECT', hours?.tagName ?? 'missing');
ok('the hours post as day_use_hours', hours?.getAttribute('name') === 'day_use_hours');
ok('the hours start at one', hourOptions[0]?.value === '1', hourOptions[0]?.textContent.trim() ?? '');
ok('the hours run to twelve', hourOptions.length === 12, `${hourOptions.length} options`);
ok('the hours are written out for the desk', hourOptions[0]?.textContent.trim() === '1 hour' && hourOptions[11]?.textContent.trim() === '12 hours');
ok('the hours open on four', hours?.value === '4', hours?.value ?? 'none');
ok('the hours are labelled', /^Hours/.test(text('label[for="f_day_use_hours"]')));

// ── the night count, which a day use stay must not demand ──────────────────
const nights = $('#f_nights');
ok('the night count exists', Boolean(nights));
ok('the night count starts at one', nights?.getAttribute('min') === '1');
ok('the night count keeps its full run', nights?.getAttribute('max') === '90', nights?.getAttribute('max') ?? 'no maximum');
ok('the night count is a plain number field', nights?.getAttribute('type') === 'number');
ok('the night count posts as nights', nights?.getAttribute('name') === 'nights');
// The night count is not a stay-type detail: it stays on the form either way, so
// the desk can always record how many nights were booked.
const nightsField = doc.getElementById('walkinNightsField');
ok('the night count is on the form for a day use', Boolean(nightsField) && !nightsField.hasAttribute('hidden'));
ok('the night count can be posted for a day use', !nights?.hasAttribute('disabled'));
ok('the night count keeps its whole range for a day use', nights?.getAttribute('min') === '1' && nights?.getAttribute('max') === '90');
ok('the night count is left as a plain field', !doc.getElementById('walkinNightsHint'));

// ── the room and the money ─────────────────────────────────────────────────
const rooms = $$('#room_id option');
ok('the vacant rooms are listed', rooms.length >= 10, `${rooms.length} options`);
ok('each room carries its housekeeping state', rooms.some(o => /Clean/i.test(o.textContent)));
ok('a room is held open', $('#room_id')?.value === '102', $('#room_id')?.value ?? 'none');
const rate = $('#f_rate');
ok('the rate field exists', Boolean(rate));
ok('the rate is filled in', rate?.getAttribute('value') === '95.00', rate?.getAttribute('value') ?? 'empty');
ok('the rate is a decimal amount', rate?.getAttribute('step') === '0.01');
ok('the rate is required', Boolean(rate?.hasAttribute('required')));

// ── the copy the desk reads ────────────────────────────────────────────────
const pageText = doc.body.textContent.replace(/\s+/g, ' ');
ok('one tap offers the night', /Night/.test(text('.walkin-toggle-opt')) || /\bNight\b/.test(pageText));
ok('the same tap offers the day', [...doc.querySelectorAll('.walkin-toggle-opt')].some(opt => opt.textContent.trim() === 'Day'));
ok('the tap reads as one control, not two cards', $$('.walkin-toggle').length === 1 && $$('.walkin-staytype').length === 0);
ok('the day use opens by default', dayUse?.checked === true);

// ── the script that switches between them ──────────────────────────────────
const script = $$('script').map(s => s.textContent).join('\n');
ok('the screen switches stay type on change', /applyStayType\s*\(/.test(script));
ok('the script knows the hours field', /walkinDayUseField/.test(script) && /day_use_hours/.test(script));
ok('the script counts the hours in words', /dayUseHoursText/.test(script));
ok('the script relabels the rate for day use', /Rate \/ hour/.test(script), 'hourly wording present');
ok('the script hides the nights row for day use', /nights/i.test(script) && /hidden/.test(script));
ok('the hour field is ready for the day-use default', dayField && !dayField.hasAttribute('hidden'));
ok('the script re-shows it for a day use submission', /removeAttribute\(\s*'hidden'\s*\)|\.hidden\s*=/.test(script));
ok('the rate plans are handed to the script', /const plans\s*=\s*\{/.test(script), 'plan data missing');
ok('the company billing rules reach the script', /companyBilling\s*=\s*\{/.test(script));

// ── nothing half-rendered ──────────────────────────────────────────────────
ok('no Blade directives survive', !/@(if|endif|foreach|endforeach|endpush|json|php|endphp)\(?/.test(doc.body.textContent));
// Script bodies use undefined on purpose (toLocaleString(undefined, …)), so the
// check looks at what the page actually shows, not at the code behind it.
const visible = doc.body.cloneNode(true);
[...visible.querySelectorAll('script, style')].forEach(node => node.remove());
ok('no undefined leaks to the page', !/\bundefined\b/.test(visible.textContent) && !/NaN/.test(visible.innerHTML));
ok('no object stringification leaks', !/\[object Object\]/.test(doc.body.innerHTML));
ok('no empty attribute leaks', !/="undefined"/.test(doc.body.innerHTML));
ok('no illustrative-data notice', !/illustrative|sample data|demo data|no records are created|not connected to/i.test(pageText));

// ── the screen once its own script has run ─────────────────────────────────
// The static markup shows both stay types; only the page script decides which
// one is live. Running it is the only way to know the desk sees a day-use form.
const live = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously',
  pretendToBeVisual: true,
  url: 'https://preview.local/frontdesk/walkin',
});
const scriptErrors = [];
live.virtualConsole.on('jsdomError', error => scriptErrors.push(String(error.message ?? error)));
await new Promise(resolve => setTimeout(resolve, 300));
const liveDoc = live.window.document;
const lq = (selector) => liveDoc.querySelector(selector);
const liveDayField = lq('#walkinDayUseField');

ok('the page script runs without error', scriptErrors.length === 0, scriptErrors[0] ?? '');
ok('the hour field is shown for a day-use submission', liveDayField?.hidden === false);
ok('the nights row stays for day use', lq('#f_nights')?.closest('.field')?.hidden !== true);
ok('nights keep the full selection under day use', lq('#f_nights')?.getAttribute('min') === '1' && lq('#f_nights')?.getAttribute('max') === '90');
ok('the summary counts the hours chosen', /4 hours/.test(lq('#walkinSummaryNights')?.textContent ?? ''), lq('#walkinSummaryNights')?.textContent?.trim() ?? '');
ok('the departure line counts the same hours', /Same day · 4 hours/.test(lq('#depDate')?.value ?? ''), lq('#depDate')?.value ?? '');
ok('the day-use estimate stays one charge', /380\.00/.test(lq('#est')?.textContent ?? ''), lq('#est')?.textContent?.trim() ?? '');
ok('the rate is asked for as a rate per hour', /Rate \/ hour/.test(lq('#f_rate')?.closest('.field')?.querySelector('label')?.textContent ?? ''), lq('#f_rate')?.closest('.field')?.querySelector('label')?.textContent ?? '');
ok('the summary asks for the rate per hour', /Rate \/ hour/.test(lq('#walkinSummaryRate')?.previousElementSibling?.textContent ?? ''), lq('#walkinSummaryRate')?.previousElementSibling?.textContent ?? '');
ok('the summary counts one day, not nights', /Day use/.test(lq('#walkinSummaryNights')?.textContent ?? ''), lq('#walkinSummaryNights')?.textContent?.trim() ?? '');
ok('the summary names the room', /102/.test(lq('#walkinSummaryRoom')?.textContent ?? ''));
ok('the summary carries the hourly rate', /95\.00/.test(lq('#walkinSummaryRate')?.textContent ?? ''), lq('#walkinSummaryRate')?.textContent?.trim() ?? '');
ok('the day-use estimate is hours x the hourly rate', /380\.00/.test(lq('#est')?.textContent ?? ''), lq('#est')?.textContent?.trim() ?? '');

// The day-use form must not post a night it does not sell: a disabled control is
// left out of the submission entirely, which is what the controller expects.
const walkInForm = [...liveDoc.querySelectorAll('form')]
  .sort((a, b) => b.querySelectorAll('input,select,textarea').length - a.querySelectorAll('input,select,textarea').length)[0];
const dayPayload = new live.window.FormData(walkInForm);
ok('a day-use stay still submits its night count', dayPayload.has('nights'), String(dayPayload.get('nights')));
ok('the submitted night count is a real number', Number(dayPayload.get('nights')) >= 1);
ok('a day-use stay submits the stay type', dayPayload.get('stay_type') === 'day_use');
ok('a day-use stay submits its hours', dayPayload.get('day_use_hours') === '4', String(dayPayload.get('day_use_hours')));
ok('the hour the room runs until is left to the server', !dayPayload.has('day_use_until'));
ok('a day-use stay submits its rate per hour', dayPayload.get('rate') === '95.00', String(dayPayload.get('rate')));
ok('a day-use stay submits the hours bought', dayPayload.get('day_use_hours') === '4', String(dayPayload.get('day_use_hours')));
ok('the server, not the form, works out the day-use charge', !dayPayload.has('day_use_charge'));
ok('the nights control is live for a day use', lq('#f_nights')?.disabled === false);
ok('the departure line reads as a day use', /Same day/i.test(lq('#depDate')?.value ?? ''), lq('#depDate')?.value ?? '');

// Choosing overnight must put the form back the way it was.
const overnightInput = liveDoc.querySelector('input[name="stay_type"][value="overnight"]');
if (overnightInput) {
  overnightInput.checked = true;
  overnightInput.dispatchEvent(new live.window.Event('change', { bubbles: true }));
}
ok('choosing overnight leaves the nights in place', lq('#f_nights')?.closest('.field')?.hidden === false);
ok('choosing overnight hides the hour field', lq('#walkinDayUseField')?.hidden === true);
ok('choosing overnight switches the hours off', lq('#f_day_use_hours')?.disabled === true);
ok('a night stay posts no hours', !new live.window.FormData(walkInForm).has('day_use_hours'));
ok('choosing overnight keeps the nights control live', lq('#f_nights')?.disabled === false);
ok('choosing overnight leaves the whole night range', lq('#f_nights')?.getAttribute('min') === '1' && lq('#f_nights')?.getAttribute('max') === '90');
ok('an overnight stay submits its nights again', new live.window.FormData(walkInForm).has('nights'));
ok('an overnight stay submits the night chosen', new live.window.FormData(walkInForm).get('nights') === '1');
ok('choosing overnight relabels the rate', /Rate \/ night/.test(lq('#f_rate')?.closest('.field')?.querySelector('label')?.textContent ?? ''));
ok('choosing overnight restores the nights summary', /night/i.test(lq('#walkinSummaryNights')?.textContent ?? '') && !/Day use/.test(lq('#walkinSummaryNights')?.textContent ?? ''));
// ── every script on the page parses ────────────────────────────────────────
const pageScripts = [...readFileSync(FILE, 'utf8').matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
const parseErrors = [];
pageScripts.forEach((source, index) => {
  try { new Function(source); } catch (error) { parseErrors.push(`script ${index + 1}: ${error.message}`); }
});
ok('the page carries its scripts', pageScripts.length >= 2, String(pageScripts.length));
ok('every script on the page parses', parseErrors.length === 0, parseErrors.join(' · '));

// --- the city-ledger dialog, shared with the new-reservation form -------------
ok('the company field offers a create button', Boolean(lq('#walkinCreateCityLedger')));
ok('the create button opens the dialog', lq('#walkinCreateCityLedger')?.getAttribute('data-modal') === 'walkinCompanyCreateModal');
ok('the dialog is on the page', Boolean(lq('#walkinCompanyCreateModal')));
ok('the dialog keeps the walk-in prefix', Boolean(lq('#walkinCompanyCreateForm')) && Boolean(lq('#walkinCompanyCreateErrors')) && Boolean(lq('#walkinCompanyCreateSave')));
ok('the dialog asks for the company name', Boolean(lq('[id="walkinCompanyCreateForm"] [name="name"]')));
ok('the dialog takes credit terms', Boolean(lq('[id="walkinCompanyCreateForm"] [name="credit_limit"]')) && Boolean(lq('[id="walkinCompanyCreateForm"] [name="credit_days"]')));
ok('the walk-in desk listens for the new account', /city-ledger-created/.test(readFileSync(FILE, 'utf8')));

// --- the guest title picker, shared with the new-reservation form -------------
const titleSelect = lq('[name="guest[title]"]');
const titleOptionValues = [...(titleSelect?.options ?? [])].map(o => o.value);
ok('the title picker offers Others', [...(titleSelect?.options ?? [])].some(o => o.value === '__other__' && o.textContent.trim() === 'Others'));
ok('the title picker keeps the usual titles first', titleOptionValues.filter(v => v).slice(0, 5).join(',') === 'Mr,Mrs,Ms,Dr,Prof', titleOptionValues.join(','));
ok('Others sits after the usual titles', titleOptionValues.filter(v => v).indexOf('__other__') === 5, titleOptionValues.join(','));
ok('the new-title field starts hidden', lq('#guestCustomTitleWrap')?.hidden === true);
ok('the new-title field starts disabled', lq('#guestCustomTitle')?.disabled === true);
if (titleSelect) {
  titleSelect.value = '__other__';
  titleSelect.dispatchEvent(new live.window.Event('change', { bubbles: true }));
}
ok('choosing Others reveals the new-title field', lq('#guestCustomTitleWrap')?.hidden === false);
ok('choosing Others enables the new-title field', lq('#guestCustomTitle')?.disabled === false);
ok('the new-title field caps at 30 characters', lq('#guestCustomTitle')?.getAttribute('maxlength') === '30');
ok('Save title waits for a value', lq('#guestCustomTitleSave')?.disabled === true);
const customTitleInput = lq('#guestCustomTitle');
if (customTitleInput) {
  customTitleInput.value = 'Chief';
  customTitleInput.dispatchEvent(new live.window.Event('input', { bubbles: true }));
}
ok('Save title wakes once a title is typed', lq('#guestCustomTitleSave')?.disabled === false);
lq('#guestCustomTitleSave')?.dispatchEvent(new live.window.MouseEvent('click', { bubbles: true }));
const titleAfterSave = lq('[name="guest[title]"]');
ok('Save title adds the typed title to the picker', [...(titleAfterSave?.options ?? [])].some(o => o.value === 'Chief'));
ok('Save title selects the typed title', titleAfterSave?.value === 'Chief', titleAfterSave?.value ?? '');
ok('the typed title travels with the form', new live.window.FormData(walkInForm).get('guest[title]') === 'Chief', String(new live.window.FormData(walkInForm).get('guest[title]')));

live.window.close();

if (failures.length) {
  console.error(`walk-in preview check: ${failures.length} failed, ${passed} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`walk-in preview check: ${passed} assertions passed`);
