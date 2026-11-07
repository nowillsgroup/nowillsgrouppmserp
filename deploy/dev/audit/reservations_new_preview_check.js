#!/usr/bin/env node
/*
 * The booking desk and the walk-in desk must offer the same guest title picker: the
 * usual titles, an "Others" entry, a box for the new title, and a save that puts it
 * on the list. A preview that merely shows the dropdown looks right and does nothing,
 * so this renders deploy/dev/previews/reservation-form.html in jsdom with its scripts
 * running and drives the picker the way a receptionist would.
 *
 * It also keeps the rest of the New Reservation screen honest: the stay dates, the
 * nights the script derives, one blank room line, the billing and advance blocks.
 *
 * Run: node deploy/dev/audit/reservations_new_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const FILE = 'deploy/dev/previews/reservation-form.html';
const html = readFileSync(FILE, 'utf8');

// The page talks to the server only when the city-ledger dialog is submitted; the
// sandbox has no PHP, so fetch answers from this stub and records what was sent.
const desk = { calls: [], reply: null };
const stubFetch = (url, options) => {
  desk.calls.push({ url, options });
  const reply = desk.reply ?? { status: 201, body: { company: { id: 77, name: 'Mensah Wedding Party', direct_billing: true } } };
  return Promise.resolve({
    ok: reply.status >= 200 && reply.status < 300,
    status: reply.status,
    json: () => Promise.resolve(reply.body),
  });
};

const dom = new JSDOM(html, {
  url: 'https://preview.local/reservations/create',
  runScripts: 'dangerously',
  pretendToBeVisual: true,
  beforeParse(window) { window.fetch = stubFetch; },
});
const doc = dom.window.document;
const live = dom.window;

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const text = (selector) => ($(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const fire = (el, type) => el?.dispatchEvent(new live.Event(type, { bubbles: true }));

// ── the screen is a screen, not a fragment ──────────────────────────────────
ok('is a whole document', /^<!doctype html>/i.test(html.trimStart()));
ok('carries the application shell', $$('nav a, aside a').length > 5);
ok('titles the screen', /New Reservation/i.test(doc.title), doc.title);
ok('holds the form', Boolean($('#resForm')));
ok('the form posts to the reservation route', ($('#resForm')?.getAttribute('method') ?? '').toLowerCase() === 'post');
ok('the save button reads Save Reservation', /Save Reservation/.test(text('#resForm')));

// ── the booking itself ─────────────────────────────────────────────────────
ok('opens on the arrival date', /^\d{4}-\d{2}-\d{2}$/.test($('[name="arrival_date"]')?.value ?? ''), $('[name="arrival_date"]')?.value ?? '');
ok('opens on the departure date', /^\d{4}-\d{2}-\d{2}$/.test($('[name="departure_date"]')?.value ?? ''), $('[name="departure_date"]')?.value ?? '');
ok('the nights are worked out from the two dates', $('#nights')?.value === '3', $('#nights')?.value ?? '');
ok('the nights field stays read-only', $('#nights')?.hasAttribute('readonly'));
ok('the stay opens as a confirmed individual booking', $('[name="booking_type"]')?.value === 'individual' && $('[name="status"]')?.value === 'confirmed');
ok('offers the business sources', $$('[name="business_source_id"] option').length > 1);
ok('offers the company accounts', $$('[name="company_id"] option').length > 1);
ok('offers the payment modes', $$('[name="advance_mode"] option').length > 1);
ok('starts with one room line', $$('#linesTable tbody tr').length === 1, String($$('#linesTable tbody tr').length));
ok('the room line offers the room types', $$('[data-k="room_type_id"] option').length > 2);
ok('the room line offers the rate plans', $$('[data-k="rate_plan_id"] option').length >= 1);
ok('the room line is named for the server', ($('[data-k="room_type_id"]')?.getAttribute('name') ?? '').startsWith('rooms[0]'));
ok('the guest block is on the form', Boolean($('[name="guest[first_name]"]')));
ok('the advance payment block opens for a new booking', Boolean($('[name="advance"]')));

// ── the guest title picker, exactly as the walk-in desk has it ─────────────
const titleSelect = $('[name="guest[title]"]');
const titleValues = [...(titleSelect?.options ?? [])].map(o => o.value);
ok('the title picker is on the form', Boolean(titleSelect));
ok('the title picker offers Others', [...(titleSelect?.options ?? [])].some(o => o.value === '__other__' && o.textContent.trim() === 'Others'));
ok('the title picker keeps the usual titles first', titleValues.filter(v => v).slice(0, 5).join(',') === 'Mr,Mrs,Ms,Dr,Prof', titleValues.join(','));
ok('Others sits after the usual titles', titleValues.filter(v => v).indexOf('__other__') === 5, titleValues.join(','));
ok('the new-title field starts hidden', $('#guestCustomTitleWrap')?.hidden === true);
ok('the new-title field starts disabled', $('#guestCustomTitle')?.disabled === true);
ok('the new-title field is called guest[custom_title]', $('#guestCustomTitle')?.getAttribute('name') === 'guest[custom_title]');
ok('the new-title field caps at 30 characters', $('#guestCustomTitle')?.getAttribute('maxlength') === '30');
ok('the new-title field is described to the reader', Boolean($('#guestCustomTitle')?.getAttribute('aria-describedby')));
ok('Save title starts disabled', $('#guestCustomTitleSave')?.disabled === true);

if (titleSelect) {
  titleSelect.value = '__other__';
  fire(titleSelect, 'change');
}
ok('choosing Others reveals the new-title field', $('#guestCustomTitleWrap')?.hidden === false);
ok('choosing Others enables the new-title field', $('#guestCustomTitle')?.disabled === false);
ok('choosing Others leaves Save title waiting', $('#guestCustomTitleSave')?.disabled === true);

const customInput = $('#guestCustomTitle');
if (customInput) { customInput.value = 'Chief'; fire(customInput, 'input'); }
ok('Save title wakes once a title is typed', $('#guestCustomTitleSave')?.disabled === false);
// While Others is chosen the form carries both halves: the sentinel and the title.
ok('Others sends its sentinel', new live.FormData($('#resForm')).get('guest[title]') === '__other__', String(new live.FormData($('#resForm')).get('guest[title]')));
ok('the typed title is sent alongside it', new live.FormData($('#resForm')).get('guest[custom_title]') === 'Chief', String(new live.FormData($('#resForm')).get('guest[custom_title]')));

// The reserved word is refused, so a guest can never be titled "__other__".
if (customInput) {
  customInput.value = '__other__';
  fire(customInput, 'input');
  $('#guestCustomTitleSave')?.dispatchEvent(new live.MouseEvent('click', { bubbles: true }));
}
ok('a title of __other__ is refused', $('[name="guest[title]"]')?.value !== '__other__' || Boolean(customInput?.validationMessage), $('[name="guest[title]"]')?.value ?? '');

if (customInput) {
  customInput.value = 'Chief';
  fire(customInput, 'input');
  $('#guestCustomTitleSave')?.dispatchEvent(new live.MouseEvent('click', { bubbles: true }));
}
const after = $('[name="guest[title]"]');
ok('Save title adds the new title to the picker', [...(after?.options ?? [])].some(o => o.value === 'Chief'));
ok('Save title selects the new title', after?.value === 'Chief', after?.value ?? '');
ok('the new title is marked as typed here', [...(after?.options ?? [])].find(o => o.value === 'Chief')?.dataset.customGuestTitle === 'true');
ok('the new title travels with the form', new live.FormData($('#resForm')).get('guest[title]') === 'Chief', String(new live.FormData($('#resForm')).get('guest[title]')));
ok('the new-title box is closed again', $('#guestCustomTitleWrap')?.hidden === true);
ok('a saved title is submitted as the title alone', new live.FormData($('#resForm')).get('guest[custom_title]') === null);

// Going back to a listed title must not leave the box demanding anything.
if (after) { after.value = 'Mrs'; fire(after, 'change'); }
ok('choosing a listed title closes the box', $('#guestCustomTitleWrap')?.hidden === true);
ok('choosing a listed title switches the box off', $('#guestCustomTitle')?.disabled === true);
ok('a listed title is what the form sends', new live.FormData($('#resForm')).get('guest[title]') === 'Mrs');

// ── Night or Day use: a day use is sold by the hour ────────────────────────
const nightRadio = $('#stayTypeNight');
const dayRadio = $('#stayTypeDay');
ok('the booking desk offers the stay type', Boolean(nightRadio) && Boolean(dayRadio));
ok('the pair shares one name', nightRadio?.getAttribute('name') === 'stay_type' && dayRadio?.getAttribute('name') === 'stay_type');
ok('the booking opens on a night', nightRadio?.checked === true && dayRadio?.checked === false);
ok('the day-use hours and rate wait until Day use is chosen', $('#dayUsePanel')?.hidden === true);
ok('the nights are the count for a night', $('#nights')?.value === '3', $('#nights')?.value ?? '');
ok('the rate column sells a night', $('#rateColHead')?.textContent === 'Rate / night');
ok('the departure date is the desk\'s to choose for a night', $('[name="departure_date"]')?.readOnly !== true);

if (dayRadio) { dayRadio.checked = true; fire(dayRadio, 'change'); }
ok('choosing Day use opens the hours and the hourly rate', $('#dayUsePanel')?.hidden === false);
ok('the hours are a dropdown of hours', $('#f_day_use_hours')?.tagName === 'SELECT' && $$('#f_day_use_hours option').length === 12, String($$('#f_day_use_hours option').length));
ok('the hours open on four', $('#f_day_use_hours')?.value === '4', $('#f_day_use_hours')?.value ?? '');
ok('the day use is priced per hour', Boolean($('#f_day_use_rate')));
ok('the rate column now sells an hour', $('#rateColHead')?.textContent === 'Rate / hour');
ok('a day use has no nights', $('#nights')?.value === '0', $('#nights')?.value ?? '');
ok('a day use leaves on the day it arrives', $('[name="departure_date"]')?.value === $('[name="arrival_date"]')?.value);
ok('the departure is no longer the desk\'s to move', $('[name="departure_date"]')?.readOnly === true);
ok('the room line rate follows the hourly rate', $('[data-k="rate"]')?.readOnly === true);

const hourly = $('#f_day_use_rate');
if (hourly) { hourly.value = '80'; fire(hourly, 'input'); }
const hoursField = $('#f_day_use_hours');
if (hoursField) { hoursField.value = '4'; fire(hoursField, 'change'); }
ok('the room line takes the hourly rate', $('[data-k="rate"]')?.value === '80', $('[data-k="rate"]')?.value ?? '');
ok('the line total is hours x rate', $('#linesTable [data-total]')?.textContent === '320.00', $('#linesTable [data-total]')?.textContent ?? '');
ok('the booking total is hours x rate', $('#grandTotal')?.textContent === '320.00', $('#grandTotal')?.textContent ?? '');
ok('the desk is told the day-use arithmetic', /4 hours × 80\.00 = 320\.00/.test(text('#dayUseTotal')), text('#dayUseTotal'));

const dayForm = new live.FormData($('#resForm'));
ok('the form submits the day use', dayForm.get('stay_type') === 'day_use', String(dayForm.get('stay_type')));
ok('the form submits the hours bought', dayForm.get('day_use_hours') === '4', String(dayForm.get('day_use_hours')));
ok('the form submits the hourly rate', dayForm.get('day_use_rate') === '80', String(dayForm.get('day_use_rate')));
ok('the form submits no night for a day use', dayForm.get('departure_date') === dayForm.get('arrival_date'));

if (nightRadio) { nightRadio.checked = true; fire(nightRadio, 'change'); }
ok('going back to a night closes the day-use panel', $('#dayUsePanel')?.hidden === true);
ok('going back to a night gives the departure date back', $('[name="departure_date"]')?.readOnly === false);
ok('going back to a night counts nights again', Number($('#nights')?.value) === 3, $('#nights')?.value ?? '');
ok('going back to a night relabels the rate column', $('#rateColHead')?.textContent === 'Rate / night');
ok('going back to a night reopens the rate input', $('[data-k="rate"]')?.readOnly === false);
ok('the night total is nights x rate', $('#grandTotal')?.textContent === '240.00', $('#grandTotal')?.textContent ?? '');

// ── every script on the page parses ────────────────────────────────────────
const inlineScripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1]);
const scriptErrors = [];
inlineScripts.forEach((source, index) => {
  try { new Function(source); } catch (error) { scriptErrors.push(`script ${index + 1}: ${error.message}`); }
});
ok('the page carries its scripts', inlineScripts.length >= 2, String(inlineScripts.length));
ok('every script on the page parses', scriptErrors.length === 0, scriptErrors.join(' · '));

// ── Create City Ledger / Company, exactly as the walk-in desk has it ───────
ok('the company field offers a create button', Boolean($('#resCompanyCreate')));
ok('the create button opens the dialog', $('#resCompanyCreate')?.getAttribute('data-modal') === 'resCompanyCreateModal');
ok('the dialog is on the page', Boolean($('#resCompanyCreateModal')));
ok('the dialog posts to the reservation company route', $('#resCompanyCreateForm')?.getAttribute('method')?.toLowerCase() === 'post');
ok('the dialog asks for the company name', Boolean($('[id="resCompanyCreateForm"] [name="name"]')));
ok('the dialog asks for the account type', $$('[id="resCompanyCreateForm"] [name="type"] option').length === 2);
ok('the dialog takes a credit limit and terms', Boolean($('[id="resCompanyCreateForm"] [name="credit_limit"]')) && Boolean($('[id="resCompanyCreateForm"] [name="credit_days"]')));
ok('the dialog saves through its own button', /Save City Ledger \/ Company/.test(text('#resCompanyCreateSave')));
ok('the dialog starts without an error strip', $('#resCompanyCreateErrors')?.hidden === true);
ok('the company status line starts empty', $('#resCompanyStatus')?.hidden === true);
$('#resCompanyCreate')?.dispatchEvent(new live.MouseEvent('click', { bubbles: true, cancelable: true }));
ok('clicking the create button opens the dialog', $('#resCompanyCreateModal')?.classList.contains('show'));
await new Promise(resolve => setTimeout(resolve, 60));
ok('the dialog takes the focus on open', doc.activeElement?.getAttribute('name') === 'name', doc.activeElement?.getAttribute('name') ?? String(doc.activeElement?.tagName));
$('#resCompanyCreateModal [data-close]')?.dispatchEvent(new live.MouseEvent('click', { bubbles: true }));
ok('closing the dialog puts it away', !$('#resCompanyCreateModal')?.classList.contains('show'));

const companySelect = $('[name="company_id"]');
const beforeCompanies = [...(companySelect?.options ?? [])].length;

// A refused save must read back the server's reasons and add nothing.
const dialogForm = $('#resCompanyCreateForm');
const fill = (name, value) => {
  const field = dialogForm?.querySelector(`[name="${name}"]`);
  if (field) { field.value = value; field.dispatchEvent(new live.Event('input', { bubbles: true })); }
};
fill('name', 'Mensah Wedding Party');
fill('credit_limit', '0');
fill('credit_days', '30');
desk.reply = { status: 422, body: { errors: { name: ['A company with this name already exists. Select it from Company / account instead.'] } } };
dialogForm?.dispatchEvent(new live.Event('submit', { bubbles: true, cancelable: true }));
await new Promise(resolve => setTimeout(resolve, 10));
ok('a refused name is reported in the dialog', $('#resCompanyCreateErrors')?.hidden === false);
ok('the refusal names the duplicate', /already exists/.test(text('#resCompanyCreateErrors')), text('#resCompanyCreateErrors'));
ok('a refused account is not added to the picker', [...(companySelect?.options ?? [])].length === beforeCompanies);

// A good save adds the account, picks it and says where it now lives.
desk.reply = null;
desk.calls.length = 0;
fill('name', 'Mensah Wedding Party');
$('#resCompanyCreateModal')?.classList.add('show');
dialogForm?.dispatchEvent(new live.Event('submit', { bubbles: true, cancelable: true }));
await new Promise(resolve => setTimeout(resolve, 10));
ok('the dialog posts the form it holds', desk.calls.length === 1, String(desk.calls.length));
ok('the post carries the company name', desk.calls[0]?.options?.body instanceof live.FormData);
ok('the post goes to the dialog\'s own action', String(desk.calls[0]?.url ?? '').endsWith(String(dialogForm?.getAttribute('action') ?? '#')), String(desk.calls[0]?.url ?? ''));
ok('the new account joins the company picker', [...(companySelect?.options ?? [])].some(o => o.value === '77'));
ok('the new account is selected', companySelect?.value === '77', companySelect?.value ?? '');
ok('the booking now carries the company', new live.FormData($('#resForm')).get('company_id') === '77', String(new live.FormData($('#resForm')).get('company_id')));
ok('the desk is told the account exists', $('#resCompanyStatus')?.hidden === false);
ok('the desk is told where the account lives', /Finance → City Ledger/.test(text('#resCompanyStatus')), text('#resCompanyStatus'));
ok('the dialog closes after saving', !$('#resCompanyCreateModal')?.classList.contains('show'));
ok('the dialog is empty for the next account', ($('[id="resCompanyCreateForm"] [name="name"]')?.value ?? '') === '', $('[id="resCompanyCreateForm"] [name="name"]')?.value ?? '');

live.window.close();

if (failures.length) {
  console.error(`new reservation preview check: ${failures.length} failed, ${passed} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`new reservation preview check: ${passed} assertions passed`);
