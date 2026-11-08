#!/usr/bin/env node
/**
 * Behavioural check for the daily-briefing previews.
 *
 * The sandbox has no browser, so this loads the built preview HTML in jsdom and reads the
 * screens the way a head of department and the general manager do. Every assertion is
 * written so that removing the feature from the view fails the check (proved by mutating
 * the preview and re-running).
 *
 * Run: node deploy/dev/audit/briefing_preview_check.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const preview = (name) => path.join(ROOT, 'deploy/dev/previews', name);

let checks = 0;
const failures = [];
const ok = (condition, label) => {
  checks += 1;
  if (!condition) failures.push(label);
};

function load(name) {
  const dom = new JSDOM(fs.readFileSync(preview(name), 'utf8'));
  return dom.window;
}

const win_close = (doc) => { try { doc.defaultView?.close(); } catch {} };
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const band = (doc, key) => doc.querySelector(`[data-band="${key}"]`);

// ── the hub ───────────────────────────────────────────────────────────────────────────
{
  const win = load('briefing-hub.html');
  const doc = win.document;

  const cards = [...doc.querySelectorAll('.brf-card')];
  ok(cards.length === 18, 'hub: every department pack is offered');
  ok(cards.some(c => /Food & Beverage/.test(text(c))), 'hub: the F&B pack is listed');
  ok(cards.some(c => /Executive Housekeeper/.test(text(c))), 'hub: a pack names the head who owns it');
  ok(cards.filter(c => /Signed /.test(text(c.querySelector('.badge')))).length === 11,
    'hub: signed packs carry the time they were signed');
  ok(cards.filter(c => /Not signed/.test(text(c.querySelector('.badge')))).length === 7,
    'hub: unsigned packs are called out, not hidden');
  ok(doc.querySelectorAll('.brf-exec-card').length === 1, 'hub: the executive brief has its own entry');
  ok(/11\/18 visible signed/.test(text(doc.querySelector('.brf-exec-card'))), 'hub: the executive card counts the sign-offs');
  ok(/18\s*Visible departments/.test(text(doc.querySelector('.flash-summary'))), 'hub: the hub states how many packs exist');
  ok(/11\s*Signed today/.test(text(doc.querySelector(".flash-summary"))), 'hub: the hub counts today\'s signatures');
  ok(/further department flash/.test(text(doc.body)), 'hub: packs the user may not read are counted, not shown');
  ok(cards.every(c => (c.querySelector('.flash-card-actions a')?.getAttribute('href') ?? '').length > 0), 'hub: every card opens its pack');
  ok(doc.querySelectorAll('.brf-card').length === 18 && !/Manager daily pack/.test(text(doc.body)),
    'hub: each card carries the real description of the pack');
  const weeks = [...doc.querySelectorAll('.brf-card-week')];
  ok(weeks.length === 18, 'hub: every card says how the head has been signing this week');
  ok(/7 of the last 7 days signed/.test(text(cards.find(c => /Food & Beverage/.test(text(c))))),
    'hub: a head who signs every day shows a full week');
  const banquetCard = cards.find(c => /Banquet & Events/.test(text(c)));
  ok(/0 of the last 7 days signed/.test(text(banquetCard)), 'hub: a head who never signs reads as zero, not blank');
  ok(banquetCard.querySelector('.brf-card-week').classList.contains('is-thin'),
    'hub: a thin signing record is coloured, so the GM sees it without reading');

  win.close();
}

// ── a full trading pack (F&B) ─────────────────────────────────────────────────────────
{
  const win = load('briefing-fnb.html');
  const doc = win.document;

  ok(/F&B Manager/.test(text(doc.querySelector('.brf-masthead'))), 'pack: the masthead names the head of department');
  ok(/Business date/.test(text(doc.querySelector('.brf-masthead'))), 'pack: the masthead fixes the business date');
  ok(/Signed 10:05/.test(text(doc.querySelector('.brf-masthead-side'))), 'pack: a signed pack shows the time it was signed');
  ok(/Kwame Asare noted/.test(text(doc.body)), 'pack: the note left at sign-off is shown back');

  const headline = [...doc.querySelectorAll('.brf-headline .stat')];
  ok(headline.length === 6, 'pack: six headline figures');
  ok(headline.some(h => /Net sales/.test(text(h)) && /51,594/.test(text(h))), 'pack: net sales is a headline figure');
  ok(headline.some(h => /vs last week/.test(text(h))), 'pack: a headline carries its comparison');

  ok(band(doc, 'house') !== null, 'pack: the house band is on every pack');
  ok(/Occupancy/.test(text(band(doc, 'house'))), 'pack: the house band carries occupancy');

  const outlets = band(doc, 'outlets');
  ok(outlets !== null && outlets.querySelectorAll('tbody tr').length === 4, 'pack: sales are broken down by outlet');
  ok(/Beach Bar/.test(text(outlets)) && /24,338\.00/.test(text(outlets)), 'pack: an outlet row carries its net');
  const tender = band(doc, 'tender');
  ok(tender !== null && /Room charge/.test(text(tender)), 'pack: how guests paid is shown, room charge included');
  ok(/Top|What sold/.test(text(band(doc, 'top'))), 'pack: what sold is listed');
  ok(band(doc, 'leakage') !== null && /still open/.test(text(band(doc, 'leakage'))), 'pack: open bills are called out as leakage');

  const watch = doc.querySelector('.brf-watch');
  ok(/On the head's desk/.test(text(watch)), 'pack: the head\'s desk is its own block');
  ok(watch.querySelectorAll('.brf-li').length === 3, 'pack: every watch item is listed');
  ok(watch.querySelectorAll('.tone-red .brf-dot').length >= 1, 'pack: a red item is marked red');
  ok(/Actions for today/.test(text(watch)), 'pack: the actions for the day are listed separately');

  const sign = doc.querySelector('.brf-sign');
  ok(/Signed by/.test(text(sign)) && /Kwame Asare/.test(text(sign)), 'pack: the sign-off block names the signer');
  ok(doc.querySelector('form[action]') !== null, 'pack: a head can re-sign after a correction');
  ok(/Where these numbers come from/.test(text(doc.body)), 'pack: the pack says which tables the numbers come from');

  const switcher = doc.querySelectorAll('.brf-switch-link');
  ok(switcher.length === 18, 'pack: a head who owns several departments can switch between packs');
  ok([...switcher].some(a => a.classList.contains('is-current') && /Food & Beverage/.test(text(a))),
    'pack: the switcher marks the pack being read');
  ok([...switcher].filter(a => a.querySelector('.brf-switch-dot')).length === 11,
    'pack: the switcher marks the packs already signed');

  win.close();
}

// ── housekeeping: a different department, same skeleton ──────────────────────────────
{
  const win = load('briefing-housekeeping.html');
  const doc = win.document;

  ok(/Executive Housekeeper/.test(text(doc.querySelector('.brf-masthead'))), 'housekeeping: the pack is the housekeeper\'s');
  ok(/Rooms ready/.test(text(doc.querySelector('.brf-headline'))), 'housekeeping: rooms ready leads');
  const types = band(doc, 'types');
  ok(types !== null && types.querySelectorAll('tbody tr').length === 5, 'housekeeping: the floor is broken down by task type');
  ok(/Departure clean/.test(text(types)), 'housekeeping: departure cleans are a task type');
  ok(types.querySelector('.badge') !== null, 'housekeeping: an open count is badged when it is not zero');
  ok(/Soiled collected/.test(text(band(doc, 'linen'))), 'housekeeping: linen movement is shown');
  ok(/do-not-disturb/.test(text(band(doc, 'watch'))), 'housekeeping: DND rooms are on the watch list');

  win.close();
}

// ── the morning before anything happens: empty states ────────────────────────────────
{
  const win = load('briefing-laundry.html');
  const doc = win.document;

  ok(/Laundry Manager/.test(text(doc.querySelector('.brf-masthead'))), 'laundry: the pack is the laundry manager\'s');
  ok(/Not signed/.test(text(doc.querySelector('.brf-masthead-side'))), 'laundry: an unsigned pack says so');
  ok(/Sign this flash/.test(text(doc.body)), 'laundry: the sign button is offered before signing');
  ok(/No linen movement recorded/.test(text(doc.body)), 'laundry: an empty table states why it is empty');
  ok(/Nothing for the desk to chase/.test(text(doc.body)), 'laundry: an empty list states why it is empty');
  ok(/Nothing is flagged against this department today/.test(text(doc.body)), 'laundry: an empty watch list says nothing is flagged');
  ok(doc.querySelector('.brf-headline .stat') !== null && /0/.test(text(doc.querySelector('.brf-headline'))),
    'laundry: a quiet day shows honest zeros rather than hiding the figures');

  win.close();
}

// ── the executive brief ──────────────────────────────────────────────────────────────
{
  const win = load('briefing-exec.html');
  const doc = win.document;

  ok(/General Manager & CEO/.test(text(doc.querySelector('.brf-masthead'))), 'exec: the brief is addressed to the GM and CEO');
  ok(/11 of 18 departments signed/.test(text(doc.querySelector('.brf-masthead'))), 'exec: the masthead counts the sign-offs');
  ok(/11\/18 signed/.test(text(doc.querySelector('.brf-masthead-side'))), 'exec: the sign-off badge carries the same count');

  const headline = [...doc.querySelectorAll('.brf-headline .stat')];
  ok(headline.length === 8, 'exec: eight executive figures');
  for (const [label, pattern] of [['Occupancy', /Occupancy/], ['ADR', /ADR/], ['RevPAR', /RevPAR/], ['Net revenue', /Net revenue/], ['MTD', /Month to date/], ['Receivables', /Receivables/]]) {
    ok(headline.some(h => pattern.test(text(h))), `exec: ${label} is a headline figure`);
  }

  const flash = band(doc, 'flash');
  ok(flash !== null && flash.querySelectorAll('tbody tr').length === 18, 'exec: the flash report carries every line');
  ok(/Net revenue \(excl\. tax\)/.test(text(flash)), 'exec: the flash report reaches net revenue');
  ok(flash.querySelectorAll('thead th').length === 4, 'exec: the flash report reads day, month and year');

  const scorecard = band(doc, 'depts');
  ok(scorecard !== null && scorecard.querySelectorAll('tbody tr').length === 18, 'exec: the scorecard carries all 18 departments');
  ok(/Signed off/.test(text(scorecard)) && /Not yet/.test(text(scorecard)), 'exec: the scorecard shows who has signed and who has not');
  ok([...scorecard.querySelectorAll('tbody tr')].some(r => /Food & Beverage/.test(text(r)) && /51,594/.test(text(r))),
    'exec: a department row carries its own headline figure');

  const revenue = band(doc, 'revenue');
  ok(revenue !== null && /Rooms/.test(text(revenue)) && /72\.4 %/.test(text(revenue)), 'exec: revenue is split by department with a share');
  ok(/Food & beverage/.test(text(revenue)), 'exec: F&B appears separately from rooms');

  ok(/Mobile Money/.test(text(band(doc, 'cash'))), 'exec: cash is broken down by mode');
  ok(band(doc, 'trend') !== null && band(doc, 'trend').querySelectorAll('tbody tr').length === 7,
    'exec: the last seven business dates are shown');
  ok(/All accounts/.test(text(band(doc, 'ar'))) && /60 d \+/.test(text(band(doc, 'ar'))), 'exec: receivables are aged, with a total row');
  ok(/Drawer variance/.test(text(band(doc, 'risk'))), 'exec: the drawer variance is on the risk band');
  ok(/income-audit findings/.test(text(band(doc, 'actions'))), 'exec: the open items are pulled from the department packs');

  const pl = band(doc, 'pl');
  ok(pl?.querySelectorAll('tbody tr').length === 8, 'exec: profit runs day, month and fiscal year on the same lines');
  ok(/Fiscal year to date/.test(text(pl)), 'exec: the profit band says the third column is the fiscal year');
  ok(/FY 2026-27/.test(text(pl)), 'exec: the profit band names the fiscal year it covers');
  ok([...(pl?.querySelectorAll('tbody tr') ?? [])].some(r => /Net margin/.test(text(r)) && /23.6 %/.test(text(r))),
    'exec: the net margin is on the profit band, per column');
  ok([...(pl?.querySelectorAll('tbody tr') ?? [])].some(r => /Net profit/.test(text(r)) && /51,524.00/.test(text(r))),
    'exec: the net profit for the day is stated, not left to be worked out');

  const budget = band(doc, 'budget');
  ok(budget?.querySelectorAll('tbody tr').length === 6, 'exec: the month is compared with the approved budget');
  ok(/Approved budget BUD-2026-01/.test(text(budget)), 'exec: the budget band names the version it compares against');
  ok(/Positive variance is favourable/.test(text(budget)), 'exec: the budget band explains which way the variance reads');
  ok([...(budget?.querySelectorAll('tbody tr') ?? [])].some(r => /Net profit/.test(text(r)) && /\+141,620\.00/.test(text(r))),
    'exec: the net profit variance is stated against budget');
  ok([...(budget?.querySelectorAll('tbody tr') ?? [])].some(r => /Operating expenses/.test(text(r)) && r.querySelector('.badge-red')),
    'exec: a section that has overspent against budget is marked red, not smoothed over');

  const compliance = band(doc, 'compliance');
  ok(compliance !== null && compliance.querySelectorAll('tbody tr').length === 18,
    'exec: sign-off compliance covers every department');
  ok(/last seven business dates/.test(text(compliance)), 'exec: the compliance band says what window it reports');
  ok(/Every day/.test(text(compliance)), 'exec: a head who signs daily is called out as such');
  ok(/Never signed/.test(text(compliance)), 'exec: a head who has not signed all week is red in the band');

  const signoff = band(doc, 'signoff');
  ok(signoff !== null && signoff.querySelectorAll('tbody tr').length === 18, 'exec: every department appears in the sign-off tracker');
  ok([...signoff.querySelectorAll('tbody tr')].some(r => /Not signed/.test(text(r))), 'exec: an unsigned department is red in the tracker');
  ok([...signoff.querySelectorAll('tbody tr')].some(r => /engineering called/.test(text(r))), 'exec: the note left at sign-off reaches the GM');

  ok(/Read the departments with items in red/.test(text(doc.body)), 'exec: the GM is told what to do with the brief');
  ok(/Excel \/ CSV/.test(text(doc.body)), 'exec: the brief can be exported');
  ok(/Print/.test(text(doc.body)), 'exec: the brief can be printed');

  win.close();
}

// ── the printed pack ─────────────────────────────────────────────────────────────────
{
  const win = load('briefing-print.html');
  const doc = win.document;

  ok(/F&B Manager — Daily Flash/.test(text(doc.querySelector('.print-head'))), 'print: the sheet is titled with the pack');
  ok(/Business date/.test(text(doc.querySelector('.print-head'))), 'print: the sheet is dated');
  ok(/Printed /.test(text(doc.querySelector('.print-head'))), 'print: the sheet says when it was printed');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'print: the headline figures print');
  ok(doc.querySelectorAll('.brf-band').length >= 6, 'print: every block prints');
  ok(/Signed by Kwame Asare/.test(text(doc.body)), 'print: the sign-off is on the sheet');
  ok(doc.querySelector('.brf-sign') === null, 'print: the signing form does not print');

  win.close();
}

// ── the finance pack: the controller reads the same profit and budget ────────────────
{
  const win = load('briefing-finance.html');
  const doc = win.document;

  ok(/Financial Controller/.test(text(doc.querySelector('.brf-masthead'))), 'finance: the pack is mastheaded with the controller');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'finance: six headline figures');
  ok(/Collected/.test(text(doc.body)) && /Tax collected/.test(text(doc.body)), 'finance: what came in and what is owed are on the page');
  ok(band(doc, 'collected').querySelectorAll('tbody tr').length === 6, 'finance: the money is broken down by the mode it came in on');
  ok(band(doc, 'ageing') !== null && /Over 60 days/.test(text(band(doc, 'ageing'))), 'finance: receivables are aged');
  ok(band(doc, 'pl') !== null && /Net profit/.test(text(band(doc, 'pl'))), 'finance: the controller sees the profit lines, not only receipts');
  ok(band(doc, 'budget') !== null && /Approved budget BUD-2026-01/.test(text(band(doc, 'budget'))),
    'finance: the month is shown against the approved budget');
  ok(/income-audit findings/.test(text(band(doc, 'close'))), 'finance: the close controls are listed');
  ok(/Signed 09:50/.test(text(doc.body)) && /Ama Boakye/.test(text(doc.body)), 'finance: the sign-off is on the page');
  ok(doc.querySelector('.brf-sign-form') !== null, 'finance: the controller can sign the pack from the screen');

  win.close();
}

// ── the HR pack: the roster, the leave queue and the contracts running out ───────────
{
  const win = load('briefing-hr.html');
  const doc = win.document;

  ok(/Human Resources Manager/.test(text(doc.querySelector('.brf-masthead'))), 'hr: the pack is mastheaded with the HR manager');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'hr: six headline figures');
  const attendance = band(doc, 'attendance');
  ok(attendance !== null && attendance.querySelectorAll('tbody tr').length === 5, 'hr: attendance is broken down by status');
  ok(/Half day/.test(text(attendance)), 'hr: a half day is a status of its own, not an absence');
  const depts = band(doc, 'departments');
  ok(depts !== null && depts.querySelectorAll('tbody tr').length === 6, 'hr: attendance is compared department by department');
  ok(/Rostered/.test(text(depts)) && /On duty/.test(text(depts)), 'hr: the roster sits beside who actually clocked in');
  ok([...(depts?.querySelectorAll('tbody tr') ?? [])].some(r => /Kitchen/.test(text(r)) && r.querySelector('.badge-red')),
    'hr: a department with absentees is marked, not buried in a total');
  const leave = band(doc, 'leave');
  ok(leave !== null && leave.querySelectorAll('tbody tr').length === 3, 'hr: the leave queue is listed, not just counted');
  ok(/Annual leave/.test(text(leave)), 'hr: each leave request names its type');
  const ending = band(doc, 'ending');
  ok(ending !== null && ending.querySelectorAll('tbody tr').length === 3, 'hr: contracts and probation ending are listed');
  ok(/Days left/.test(text(ending)) && ending.querySelector('.badge-red') !== null,
    'hr: a contract ending within a fortnight is red in the list');
  ok(/Not signed/.test(text(doc.querySelector('.brf-masthead'))), 'hr: an unsigned pack says so on the masthead');

  win.close();
}

// ── the parking pack: the yard by zone, the curve and the permits ────────────────────
{
  const win = load('briefing-parking.html');
  const doc = win.document;

  ok(/Parking Supervisor/.test(text(doc.querySelector('.brf-masthead'))), 'parking: the pack is mastheaded with the supervisor');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'parking: six headline figures');
  ok(band(doc, 'kinds').querySelectorAll('tbody tr').length === 3, 'parking: cars are split by who they are');
  const zones = band(doc, 'zones');
  ok(zones !== null && zones.querySelectorAll('tbody tr').length === 3, 'parking: the yard is reported zone by zone');
  ok(/In use/.test(text(zones)) && /36 \/ 50/.test(text(zones)), 'parking: each zone states how full it is against its bays');
  const curve = band(doc, 'curve');
  ok(curve !== null && curve.querySelectorAll('tbody tr').length === 6, 'parking: the drop-off curve covers the day in windows');
  ok([...(curve?.querySelectorAll('tbody tr') ?? [])].some(r => /12:00 – 15:00/.test(text(r)) && /17/.test(text(r))),
    'parking: the peak window is readable at a glance');
  const permits = band(doc, 'permits');
  ok(permits !== null && permits.querySelectorAll('tbody tr').length === 3, 'parking: permits falling due are listed, not just counted');
  ok(/Expires/.test(text(permits)), 'parking: each permit shows when it lapses');
  ok(/3 valet calls are outstanding/.test(text(doc.querySelector('.brf-watch'))), 'parking: the valet backlog is flagged');

  win.close();
}

// ── the front office pack: the arrivals to run and the folios to settle ─────────────
{
  const win = load('briefing-frontoffice.html');
  const doc = win.document;

  ok(/Front Office Manager/.test(text(doc.querySelector('.brf-masthead'))), 'frontoffice: the pack is mastheaded with the desk manager');
  ok(/Signed 09:12/.test(text(doc.querySelector('.brf-masthead'))), 'frontoffice: a signed pack shows the time it was signed');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'frontoffice: six headline figures');
  const arriving = band(doc, 'arriving');
  ok(arriving !== null && arriving.querySelectorAll('tbody tr').length === 4, 'frontoffice: the arrivals still to run are listed');
  const unroomed = [...(arriving?.querySelectorAll('tbody tr') ?? [])].filter(r => /Not roomed/.test(text(r)));
  ok(unroomed.length === 2 && unroomed.every(r => r.querySelector('.badge-amber')),
    'frontoffice: every arrival with no room is flagged, not left as a dash');
  const departing = band(doc, 'departing');
  ok(departing !== null && departing.querySelectorAll('tbody tr').length === 3, 'frontoffice: the departures to settle are listed');
  ok([...(departing?.querySelectorAll('tbody tr') ?? [])].some(r => /4,210\.00/.test(text(r)) && r.querySelector('.badge-red')),
    'frontoffice: a folio still owing is red before the guest leaves');
  ok([...(departing?.querySelectorAll('tbody tr') ?? [])].some(r => /credit/.test(text(r))),
    'frontoffice: a folio in credit says so rather than showing a negative balance');
  ok(band(doc, 'cashier').querySelectorAll('tbody tr').length === 4, 'frontoffice: the desk cash is broken down by mode');
  ok(/2 guest requests are past the time/.test(text(doc.querySelector('.brf-watch'))), 'frontoffice: overdue guest requests are flagged');

  win.close();
}

// ── the security pack: who is inside, and what has left the key board ───────────────
{
  const win = load('briefing-security.html');
  const doc = win.document;

  ok(/Security Manager/.test(text(doc.querySelector('.brf-masthead'))), 'security: the pack is mastheaded with the security manager');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'security: six headline figures');
  const register = band(doc, 'visitors');
  ok(register !== null && register.querySelectorAll('tbody tr').length === 3, 'security: the visitor register is broken down by status');
  const onSite = band(doc, 'onsite');
  ok(onSite !== null && onSite.querySelectorAll('tbody tr').length === 3, 'security: everyone inside the property is listed by name');
  ok(/In since/.test(text(onSite)) && /07:10/.test(text(onSite)), 'security: each visitor shows when they came in');
  const keys = band(doc, 'keys');
  ok(keys !== null && keys.querySelectorAll('tbody tr').length === 2, 'security: the keys off the board are listed');
  ok([...(keys?.querySelectorAll('tbody tr') ?? [])].some(r => /Master 3/.test(text(r)) && r.querySelector('.badge-red')),
    'security: a key past its return time is red');
  const passes = band(doc, 'passes');
  ok(passes !== null && passes.querySelectorAll('tbody tr').length === 2, 'security: gate passes still out are listed with the party');
  ok([...(passes?.querySelectorAll('tbody tr') ?? [])].some(r => /Overdue/.test(text(r))),
    'security: an overdue gate pass says so in words');
  ok(band(doc, 'incidents').querySelectorAll('tbody tr').length === 3, 'security: the day incidents are on the pack');

  win.close();
}

// ── the kitchen pack: the pass, the allergens and the cold chain ────────────────────
{
  const win = load('briefing-kitchen.html');
  const doc = win.document;

  ok(/Executive Chef/.test(text(doc.querySelector('.brf-masthead'))), 'kitchen: the pack is mastheaded with the chef');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'kitchen: six headline figures');
  ok(band(doc, 'dishes').querySelectorAll('tbody tr').length === 4, 'kitchen: the busiest dishes of the day are listed');
  const pass = band(doc, 'pass');
  ok(pass !== null && pass.querySelectorAll('tbody tr').length === 3, 'kitchen: what is still on the pass is listed line by line');
  ok([...(pass?.querySelectorAll('tbody tr') ?? [])].some(r => /26 min/.test(text(r)) && r.querySelector('.badge-red')),
    'kitchen: a line waiting too long is red');
  const allergens = band(doc, 'allergens');
  ok(allergens !== null && allergens.querySelectorAll('tbody tr').length === 2, 'kitchen: every unverified allergen line is named');
  ok(/Peanut soup/.test(text(allergens)) && /Not verified/.test(text(allergens)),
    'kitchen: the allergen list names the dish and says it is unverified');
  const temps = band(doc, 'temps');
  ok(temps !== null && temps.querySelectorAll('tbody tr').length === 1, 'kitchen: readings out of range are listed, not just counted');
  ok(/9\.4 °C/.test(text(temps)) && /0\.0 – 5\.0 °C/.test(text(temps)),
    'kitchen: the failing reading shows the reading and the range it should have been in');
  ok(/temperature reading is outside the safe range/.test(text(doc.querySelector('.brf-watch'))), 'kitchen: the cold chain failure is flagged on the pack');

  win.close();
}

// ── the stores pack: what to buy, the counter queue and the dock ─────────────────────
{
  const win = load('briefing-stores.html');
  const doc = win.document;

  ok(/Purchasing & Stores Manager/.test(text(doc.querySelector('.brf-masthead'))), 'stores: the pack is mastheaded with the stores manager');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'stores: six headline figures');
  const low = band(doc, 'low');
  ok(low !== null && low.querySelectorAll('tbody tr').length === 3, 'stores: the buy list is on the pack');
  ok(/Re-order at/.test(text(low)) && /Suggested/.test(text(low)), 'stores: the buy list shows the level and the suggested quantity');
  const queue = band(doc, 'queue');
  ok(queue !== null && queue.querySelectorAll('tbody tr').length === 3, 'stores: the counter queue is listed with the department waiting');
  ok([...(queue?.querySelectorAll('tbody tr') ?? [])].some(r => /Awaiting approval/.test(text(r)) && r.querySelector('.badge-amber')),
    'stores: a requisition still waiting for approval is amber');
  const deliveries = band(doc, 'deliveries');
  ok(deliveries !== null && deliveries.querySelectorAll('tbody tr').length === 3, 'stores: the deliveries received are listed');
  ok([...(deliveries?.querySelectorAll('tbody tr') ?? [])].some(r => /640\.00/.test(text(r)) && r.querySelector('.badge-red')),
    'stores: anything rejected on the dock is red against the delivery');

  win.close();
}

// ── the revenue pack: the pace, the rate decisions and the leakage ──────────────────
{
  const win = load('briefing-revenue.html');
  const doc = win.document;

  ok(/Revenue Manager/.test(text(doc.querySelector('.brf-masthead'))), 'revenue: the pack is mastheaded with the revenue manager');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'revenue: six headline figures');
  ok(band(doc, 'pace').querySelectorAll('tbody tr').length === 14, 'revenue: the fortnight of pace is on the pack');
  ok([...band(doc, 'pace').querySelectorAll('tbody tr')].some(r => /Tonight/.test(text(r))),
    'revenue: the pace starts with tonight');
  ok(band(doc, 'mix').querySelectorAll('tbody tr').length === 4, 'revenue: the mix is set against published base rates');
  ok([...band(doc, 'mix').querySelectorAll('tbody tr')].some(r => /Garden double/.test(text(r)) && r.querySelector('.badge-red')),
    'revenue: a room type selling under its base rate is red');
  const auth = band(doc, 'auth');
  ok(auth !== null && auth.querySelectorAll('tbody tr').length === 3, 'revenue: the rate decisions waiting are listed');
  ok(/Rate below expected/.test(text(auth)) && /Akosua Mensah/.test(text(auth)),
    'revenue: each request names its kind and who asked');
  const leakage = band(doc, 'leakage');
  ok(leakage !== null && leakage.querySelectorAll('tbody tr').length === 2, 'revenue: what was given away today is listed');
  ok(/Service recovery/.test(text(leakage)), 'revenue: leakage says what the discount was for');

  win.close();
}

// ── the engineering pack: the overdue list and the plan ─────────────────────────────
{
  const win = load('briefing-maintenance.html');
  const doc = win.document;

  ok(/Chief Engineer/.test(text(doc.querySelector('.brf-masthead'))), 'maintenance: the pack is mastheaded with the chief engineer');
  ok(/Chiller 2 on bypass/.test(text(doc.body)), 'maintenance: the note left at sign-off reaches the pack');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'maintenance: six headline figures');
  const overdue = band(doc, 'overdue');
  ok(overdue !== null && overdue.querySelectorAll('tbody tr').length === 3, 'maintenance: the overdue jobs are listed, not just counted');
  ok([...(overdue?.querySelectorAll('tbody tr') ?? [])].some(r => /MR-0912/.test(text(r)) && /4 d/.test(text(r)) && r.querySelector('.badge-red')),
    'maintenance: an overdue job shows its ticket, the days late and is red');
  const soon = band(doc, 'soon');
  ok(soon !== null && soon.querySelectorAll('tbody tr').length === 2, 'maintenance: planned work for the next week is on the pack');
  ok(/Cool Air Ghana/.test(text(soon)), 'maintenance: planned work names who does it');
  ok(band(doc, 'pm').querySelectorAll('tbody tr').length === 2, 'maintenance: schedules already due are listed');
  ok(/Room 210/.test(text(band(doc, 'out'))), 'maintenance: rooms held out of the sale are named');

  win.close();
}

// ── the sales pack: the quiet enquiries, today's wins and today's losses ────────────
{
  const win = load('briefing-sales.html');
  const doc = win.document;

  ok(/Sales Manager/.test(text(doc.querySelector('.brf-masthead'))), 'sales: the pack is mastheaded with the sales manager');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'sales: six headline figures');
  ok(band(doc, 'pipeline').querySelectorAll('tbody tr').length === 4, 'sales: the pipeline by stage is on the pack');
  const quiet = band(doc, 'quiet');
  ok(quiet !== null && quiet.querySelectorAll('tbody tr').length === 3, 'sales: the enquiries that have gone quiet are listed');
  ok([...(quiet?.querySelectorAll('tbody tr') ?? [])].some(r => /SO-0231/.test(text(r)) && /22 d ago/.test(text(r)) && r.querySelector('.badge-amber')),
    'sales: a quiet enquiry shows how long it has sat and is amber');
  const won = band(doc, 'won');
  ok(won !== null && /Nestlé Ghana/.test(text(won)) && won.querySelector('.badge-green'), 'sales: what was won today is listed by account, in green');
  const lost = band(doc, 'lost_detail');
  ok(lost !== null && lost.querySelectorAll('tbody tr').length === 1, 'sales: what was lost today is listed');
  ok(/competitor undercut us/.test(text(lost)), 'sales: a loss carries the reason the team recorded');

  win.close();
}

// ── the guest experience pack: what was said, and what needs an answer ──────────────
{
  const win = load('briefing-guestexp.html');
  const doc = win.document;

  ok(/Guest Relations Manager/.test(text(doc.querySelector('.brf-masthead'))), 'guestexp: the pack is mastheaded with the guest relations manager');
  ok(/Two long-stay guests to be seen personally/.test(text(doc.body)), 'guestexp: the note left at sign-off reaches the pack');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'guestexp: six headline figures');
  const urgent = band(doc, 'urgent');
  ok(urgent !== null && urgent.querySelectorAll('tbody tr').length === 3, 'guestexp: the cases that need an answer are listed');
  ok(/FC-0312/.test(text(urgent)) && urgent.querySelector('.badge-red'), 'guestexp: a high-severity case is red on the list');
  ok(/Unassigned/.test(text(urgent)), 'guestexp: a case with no owner says so');
  const written = band(doc, 'written');
  ok(written !== null && written.querySelectorAll('tbody tr').length === 3, 'guestexp: what the guests wrote is on the pack');
  ok(/Air conditioning did not cool the room/.test(text(written)), 'guestexp: a guest comment is quoted, not summarised');
  ok([...(written?.querySelectorAll('tbody tr') ?? [])].some(r => /Kwesi Amankwah/.test(text(r)) && r.querySelector('.badge-red')),
    'guestexp: an unhappy guest is red');

  win.close();
}

// ── the banquet pack: the floor, the unpublished orders and the unbilled events ─────
{
  const win = load('briefing-banquet.html');
  const doc = win.document;

  ok(/Banquet Manager/.test(text(doc.querySelector('.brf-masthead'))), 'banquet: the pack is mastheaded with the banquet manager');
  ok(/Two rooms short for the Osei wedding/.test(text(doc.body)), 'banquet: the note left at sign-off reaches the pack');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'banquet: six headline figures');
  ok(band(doc, 'today').querySelectorAll('tbody tr').length === 3, 'banquet: the floor today is listed event by event');
  const beos = band(doc, 'beos');
  ok(beos !== null && beos.querySelectorAll('tbody tr').length === 2, 'banquet: the orders still to publish are listed');
  ok(/BE-0412/.test(text(beos)) && beos.querySelector('.badge-amber'),
    'banquet: an unpublished order names the event and is amber');
  const unbilled = band(doc, 'unbilled');
  ok(unbilled !== null && /BE-0409/.test(text(unbilled)) && /Tullow Ghana/.test(text(unbilled)),
    'banquet: a closed event not yet billed is named with its client');
  ok(/21,600.00/.test(text(unbilled)), 'banquet: an unbilled event shows the value to raise');

  win.close();
}

// ── the IT pack: what failed, and who is not answering ──────────────────────────────
{
  const win = load('briefing-it.html');
  const doc = win.document;

  ok(/IT Manager/.test(text(doc.querySelector('.brf-masthead'))), 'it: the pack is mastheaded with the IT manager');
  ok(/PBX gateway replaced/.test(text(doc.body)), 'it: the note left at sign-off reaches the pack');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'it: six headline figures');
  const broken = band(doc, 'broken');
  ok(broken !== null && broken.querySelectorAll('tbody tr').length === 3, 'it: every failed command is listed, not just counted');
  ok(/wake_up_call/.test(text(broken)) && /gateway timeout/.test(text(broken)),
    'it: a failure shows the command and what came back');
  ok(/06:12/.test(text(broken)) && broken.querySelector('.badge-red'), 'it: a failure shows when it happened and is red');
  const endpoints = band(doc, 'endpoints');
  ok(endpoints !== null && endpoints.querySelectorAll('tbody tr').length === 2, 'it: the endpoints not answering are named');
  ok(/502/.test(text(endpoints)) && /none/.test(text(endpoints)), 'it: an endpoint shows the code it returned, or none');
  ok(band(doc, 'webhooks').querySelectorAll('tbody tr').length === 2, 'it: the webhook result split is on the pack');

  win.close();
}

// ── fleet: the board, the crew, the money and the workshop ──────────────────────────
{
  const win = load('briefing-fleet.html');
  const doc = win.document;

  ok(/Transport Supervisor/.test(text(doc.querySelector('.brf-masthead'))), 'fleet: the pack is mastheaded with the supervisor');
  ok(/Hilux grounded/.test(text(doc.body)), 'fleet: the note left at sign-off reaches the pack');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'fleet: six headline figures');
  ok(/GH₵ 6,480.00/.test(text(doc.querySelector('.brf-headline'))), 'fleet: the day\'s trip revenue is on the headline');
  ok(/given complimentary/.test(text(doc.querySelector('.brf-headline'))),
    'fleet: the revenue figure says how much of it was given away, not just what was billed');

  const mv = band(doc, 'mv');
  ok(mv !== null && mv.querySelectorAll('tbody tr').length === 3, 'fleet: the movements are split by type');
  ok(/Passengers/.test(text(mv)) && /71|19/.test(text(mv)), 'fleet: a movement row carries the passengers, not only the trips');

  const board = band(doc, 'board');
  ok(board !== null && board.querySelectorAll('tbody tr').length === 5, 'fleet: the board lists the cars, not just a count');
  ok(/GT 7712-22/.test(text(board)) && /Fitness expired/.test(text(board)),
    'fleet: a car with a lapsed paper says which paper, on the row');
  ok(board.querySelector('.badge-red') !== null, 'fleet: a grounded car is red, not merely mentioned');
  ok(/Km today/.test(text(board)), 'fleet: the board reports the kilometres the car actually covered');

  const crew = band(doc, 'crew');
  ok(crew !== null && crew.querySelectorAll('tbody tr').length === 4, 'fleet: the drivers are listed with what they drove');
  ok(/Licence expired/.test(text(crew)) && /No licence on file/.test(text(crew)),
    'fleet: a licence that has lapsed is spelled out, and so is a missing one');
  ok(/7h 20m/.test(text(crew)), 'fleet: the time a driver was out is on the pack');

  const billing = band(doc, 'billing');
  ok(billing !== null && billing.querySelectorAll('tbody tr').length === 3, 'fleet: the trips are split by how they were billed');
  ok(/still to post|posted to the guest folio/.test(text(billing)), 'fleet: a room trip says whether it reached a folio');

  const cost = band(doc, 'cost');
  ok(cost !== null && cost.querySelectorAll('tbody tr').length === 3, 'fleet: fuel and upkeep are reported by kind');
  ok(/64.0/.test(text(cost)), 'fleet: the litres are shown, not only the money');

  const workshop = band(doc, 'workshop');
  ok(workshop !== null && workshop.querySelectorAll('tbody tr').length === 3, 'fleet: the workshop queue is printed');
  ok(/Preventive service/.test(text(workshop)) && /03 Nov/.test(text(workshop)),
    'fleet: a work order carries its category and the date it was due');

  const faults = band(doc, 'faults');
  ok(faults !== null && faults.querySelectorAll('tbody tr').length === 2, 'fleet: defects and failed inspections are on the pack');
  ok(/Open defect/.test(text(faults)), 'fleet: a failed inspection is carried as an open defect');

  const rentals = band(doc, 'rentals');
  ok(rentals !== null && rentals.querySelectorAll('tbody tr').length === 3, 'fleet: the self-drive rentals are on the same pack');
  ok(/CR-0418/.test(text(rentals)) && /05 Nov 18:00/.test(text(rentals)), 'fleet: a rental shows the agreement and when it is due back');

  const desk = band(doc, 'desk');
  ok(desk !== null && desk.querySelectorAll('li, .brf-item').length >= 8, 'fleet: the desk list carries every action');
  ok(/a lapsed permit grounds the car/.test(text(desk)), 'fleet: the desk says why a paper matters, not only that it is due');
  ok(/posts at check-out/.test(text(desk)), 'fleet: unposted room charges say when they will land, which is what the code does');
  ok(/a kilometre/.test(text(desk)), 'fleet: cost per kilometre is derived, not left to the reader');

  ok(/unassigned/.test(text(doc.querySelector('.brf-watch'))), 'fleet: the watch strip flags the unassigned trips');
  ok(/transport_trips, vehicles, drivers/.test(text(doc.querySelector('.brf-sources'))),
    'fleet: the pack declares the tables it reads');

  win.close();
}

// ── spa & recreation: the till roll, the gate, the drawer and the tax ───────────────
{
  const win = load('briefing-recreation.html');
  const doc = win.document;

  ok(/Recreation Manager/.test(text(doc.querySelector('.brf-masthead'))), 'recreation: the pack is mastheaded with the outlet manager');
  ok(/Gate sheet reconciled/.test(text(doc.body)), 'recreation: the note left at sign-off reaches the pack');
  ok(doc.querySelectorAll('.brf-headline .stat').length === 6, 'recreation: six headline figures');
  ok(/41 \/ 63/.test(text(doc.querySelector('.brf-headline'))),
    'recreation: the headline counts guests through the gate, not tickets sold');

  const tender = band(doc, 'tender');
  ok(tender !== null && tender.querySelectorAll('tbody tr').length === 4, 'recreation: takings are split by the mode they came in on');
  ok(/Cash/.test(text(tender)) && tender.querySelector('.badge-green') !== null,
    'recreation: cash is marked apart from the other tenders, which is what the drawer is reconciled from');

  const type = band(doc, 'type');
  ok(type !== null && type.querySelectorAll('tbody tr').length === 4, 'recreation: what sold is listed line by line');
  ok(/Day pass — adult/.test(text(type)) && /2,940.00/.test(text(type)) && /301.35/.test(text(type)),
    'recreation: a line carries its net, its tax and what was charged');

  const passes = band(doc, 'passes');
  ok(passes !== null && passes.querySelectorAll('tbody tr').length === 5, 'recreation: the passes sold are listed against the gate');
  ok(/Admitted 09:12/.test(text(passes)), 'recreation: a pass shows the time the gate admitted it');
  ok(/Not presented/.test(text(passes)), 'recreation: a pass the gate has not seen is called out');
  ok(/Cancelled/.test(text(passes)) && passes.querySelector('.badge-red') !== null, 'recreation: a cancelled pass is red');
  ok(/Guest of a member/.test(text(passes)), 'recreation: a member\'s guest is not reported as a day pass');

  const sales = band(doc, 'sales');
  ok(sales !== null && sales.querySelectorAll('tbody tr').length === 4, 'recreation: every sale rung is on the pack');
  ok(/CS-8843/.test(text(sales)) && /Void/.test(text(sales)), 'recreation: a voided sale stays on the till roll and is marked');
  ok(/Mobile money/.test(text(sales)), 'recreation: a sale says how it was paid');

  const shift = band(doc, 'shift');
  ok(shift !== null && shift.querySelectorAll('tbody tr').length === 2, 'recreation: the shift drawer is reported');
  ok(/Still open/.test(text(shift)), 'recreation: a shift that has not been closed says so');
  ok(/−30.00/.test(text(shift)) && /Expected cash/.test(text(shift)),
    'recreation: a closed shift shows the variance against what the till expected');
  ok(/500.00/.test(text(shift)), 'recreation: the float the cashier started with is on the pack');

  const tax = band(doc, 'tax');
  ok(tax !== null && tax.querySelectorAll('tbody tr').length === 4, 'recreation: the tax is split by its statutory components');
  ok(/Ghana Revenue Authority/.test(text(tax)) && /Ghana Tourism Authority/.test(text(tax)),
    'recreation: each component says which authority it is payable to');

  const gate = band(doc, 'gate');
  ok(gate !== null && /from the drawer/.test(text(gate)),
    'recreation: the reconciliation explains the change given, not just the cash taken');
  ok(/22 tickets not yet presented/.test(text(gate)), 'recreation: the unpresented passes are on the reconciliation');

  ok(/drawer has not been counted/.test(text(doc.querySelector('.brf-watch'))),
    'recreation: an open shift is in the watch strip');
  ok(/club_ticket_sales, club_ticket_items/.test(text(doc.querySelector('.brf-sources'))),
    'recreation: the pack declares the tables it reads');

  win.close();
}

// ── the briefing book: the whole morning meeting in one document ─────────────────────
{
  const win = load('briefing-book.html');
  const doc = win.document;

  ok(/Daily Flash book/.test(text(doc.querySelector('.print-head'))), 'book: the document is titled on every page head');
  ok(/General Manager & CEO/.test(text(doc.querySelector('.print-head'))), 'book: the document says who it is for');
  ok(/Business date/.test(text(doc.querySelector('.print-head'))), 'book: the document is dated');

  const toc = doc.querySelector('.brf-book-toc');
  ok(toc !== null && /19 parts/.test(text(toc)), 'book: the contents page states what the book holds');
  ok(toc.querySelectorAll('.brf-book-list li').length === 19, 'book: the contents page lists the executive brief and all 18 departments');
  ok(toc.querySelector('a[href="#book-exec"]') !== null, 'book: the executive brief is reachable from the contents');
  ok(toc.querySelectorAll('.brf-book-list a[href^="#book-"]').length === 19, 'book: every part is linked from the contents');
  ok(toc.querySelectorAll('.brf-book-state.is-open').length === 7, 'book: the contents page marks the departments that have not signed');
  ok(/11 of 18 signed/.test(text(toc)), 'book: the contents page counts the sign-offs');

  const parts = [...doc.querySelectorAll('.brf-book-part')];
  ok(parts.length === 19, 'book: one part per department plus the executive brief');
  ok(parts[0].id === 'book-exec', 'book: the executive brief comes first');
  ok(parts.every(p => (p.id ?? '').startsWith('book-')), 'book: every part is addressable, so a link opens the right page');
  ok(parts[0].querySelectorAll('.brf-headline .stat').length === 8, 'book: the executive brief opens with its eight headline figures');
  ok(parts.slice(1).every(p => p.querySelectorAll('.brf-headline .stat').length === 6), 'book: every department opens with its six headline figures');
  ok(parts.slice(1).every(p => p.querySelectorAll('.brf-band').length >= 3), 'book: every department part carries its blocks');
  ok(/Where these numbers come from/.test(text(parts[0])) && parts.slice(1).every(p => /Where these numbers come from/.test(text(p))),
    'book: every part says where its numbers come from');

  const fnb = doc.querySelector('#book-fnb');
  ok(/Food & Beverage/.test(text(fnb.querySelector('.brf-book-head'))), 'book: a department part is headed with the department');
  ok(/F&B Manager/.test(text(fnb.querySelector('.brf-book-head'))), 'book: a department part names the head who owns it');
  ok(fnb.querySelectorAll('tbody tr').length >= 4, 'book: a department part table prints its rows');
  ok(/Signed by Kwame Asare/.test(text(fnb)), 'book: a signed department shows who signed it');
  ok(/Sign-off compliance/.test(text(parts[0])), 'book: the executive brief in the book carries the week compliance band');
  ok(/Budget against actual/.test(text(parts[0])), 'book: the book prints the budget comparison with the brief');
  ok(/Net margin/.test(text(parts[0])), 'book: the book prints the profit lines, not only revenue');

  const banquet = doc.querySelector('#book-banquet');
  ok(/Not signed at the time of printing/.test(text(banquet)), 'book: an unsigned department says so in the book');
  ok(/What needs attention/.test(text(banquet)), 'book: each part carries its open items');

  win.close();
}

// ── the two exports reachable from the screens ───────────────────────────────────────
{
  const hub = load('briefing-hub.html').document;
  ok(/Flash book/.test(text(hub.body)), 'hub: the GM can open the whole book from the hub');

  const fnb = load('briefing-fnb.html').document;
  ok(/Excel \/ CSV/.test(text(fnb.body)), 'pack: a head can export their own figures to a spreadsheet');

  win_close(fnb);
}

if (failures.length) {
  console.error(`briefing preview check: ${checks - failures.length}/${checks} passed`);
  failures.forEach((label) => console.error(`  FAIL ${label}`));
  process.exit(1);
}
console.log(`briefing preview check: ${checks}/${checks} passed`);
