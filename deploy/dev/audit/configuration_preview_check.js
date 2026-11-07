#!/usr/bin/env node
/**
 * Behavioural check for the Settings › Configuration previews.
 *
 * The sandbox has no browser, so this loads the built preview HTML in jsdom and reads
 * the register the way an administrator does: can I find a setting, does the module list
 * cover the estate, does each card tell me where the value is changed, and does the
 * printed sheet carry every key. Every assertion is written so that deleting the feature
 * from the view fails the check (proved by mutating the preview and re-running).
 *
 * Run: node deploy/dev/audit/configuration_preview_check.js
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

const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();

// How many settings the registry holds: the hub prints it, so the check reads it from the
// registry rather than carrying a number that has to be edited every time one is added.
const REGISTRY_SRC = fs.readFileSync(path.join(ROOT, 'config/settings.php'), 'utf8');
const REGISTER_KEYS = [...REGISTRY_SRC.matchAll(/^ {16}'([a-z0-9_]+)' => /gm)].length;
// …and how many modules: adding one is registering something, not editing a check
const MODULE_COUNT = [...REGISTRY_SRC.matchAll(/^ {8}'([a-z_]+)' => \[/gm)].length;

// Which modules the code checks against each other, read from ConfigurationChecks::MODULES.
// The screen states a number and this is the source of it, so a rule added to the code can
// never leave "13 of 69" on the hub and still pass.
const ruleSource = fs.readFileSync(path.join(ROOT, 'app/Support/ConfigurationChecks.php'), 'utf8');
const ruleBlock = ruleSource.slice(ruleSource.indexOf('const MODULES = ['), ruleSource.indexOf('];', ruleSource.indexOf('const MODULES = [')));
const RULE_MODULES = [...ruleBlock.matchAll(/'([a-z0-9_]+)' => '([a-z0-9_]+)',/g)].map((m) => ({ id: m[1], module: m[2] }));
const GUARDED = new Set(RULE_MODULES.map((r) => r.module)).size;
const findingsSource = fs.readFileSync(path.join(ROOT, 'deploy/dev/preview/configuration-sample.mjs'), 'utf8');
const findBlock = findingsSource.slice(findingsSource.indexOf('const FINDINGS = ['), findingsSource.indexOf('const SEVERITY_LABEL'));
const FINDING_ROWS = [...findBlock.matchAll(/id: '([a-z0-9_]+)', severity: '([a-z]+)'/g)].map((m) => ({ id: m[1], severity: m[2] }));
const severityCount = (sev) => FINDING_ROWS.filter((f) => f.severity === sev).length;

// ── the hub: is the whole estate in one place ───────────────────────────────────────
{
  const win = load('configuration.html');
  const doc = win.document;

  const cards = [...doc.querySelectorAll('.cfg-card')];
  ok(cards.length === MODULE_COUNT, 'hub: every module in the register is on the sheet');
  ok(doc.querySelectorAll('.cfg-group-head').length === 11, 'hub: the modules are grouped, not dumped in one list');
  ok([...doc.querySelectorAll('.cfg-group-head')].some(h => /Food & Beverage/.test(text(h))),
    'hub: the groups are the ones the sidebar uses');

  const stats = [...doc.querySelectorAll('.stat .v')].map(text);
  ok(stats.includes(String(MODULE_COUNT)), 'hub: the strip counts the modules');
  ok(stats.includes(String(MODULE_COUNT)) && /have their own screen/.test(text(doc.body)),
    'hub: every module has its own screen');
  ok(stats.includes(String(REGISTER_KEYS)), `hub: the strip counts the settings tracked (${REGISTER_KEYS})`);

  ok(cards.some(c => /Banquet & events/.test(text(c)) && /Open the screen/.test(text(c))),
    'hub: a module card opens the screen that owns its settings');
  ok(cards.some(c => /Property profile/.test(text(c)) && /10 of 10 set/.test(text(c))),
    'hub: a card says how many of its settings are set on this property');
  ok(cards.some(c => /Banquet & events/.test(text(c)) && /1 on default/.test(text(c))),
    'hub: a card calls out the settings still running on the built-in default');
  ok(cards.some(c => /last changed 04 Nov 2026/.test(text(c))), 'hub: a card shows when it was last changed');
  ok(cards.some(c => /Updated banquet settings/.test(text(c))),
    'hub: a card says what that change was, not only when and by whom');
  ok(cards.some(c => /Needs payroll.view/.test(text(c))),
    'hub: a screen the reader may not manage is shown as locked, not hidden');
  ok(cards.some(c => /On its own screen/.test(text(c))),
    'hub: a module that configures itself in its own tables says so instead of "0 of 0"');

  // this round closed the last six gaps: every module in the register now has a screen
  ok(doc.querySelectorAll('.cfg-gap-chip').length === 0, 'hub: no module is left without a screen');
  ok(/What it was before/.test(text(doc.body)), 'hub: the value trail is reachable from the hub action bar');
  ok(stats.includes('412'), 'hub: and the strip counts the value changes recorded against it');
  ok(stats.includes(`${GUARDED} of ${MODULE_COUNT}`), `hub: the strip says how many modules are checked against each other (${GUARDED} of ${MODULE_COUNT}), not that all are`);
  ok(/modules whose settings are checked against each other/.test(text(doc.body)), 'hub: and says what that number means');
  ok(!/Not yet behind a screen/.test(text(doc.body)), 'hub: the worklist panel is gone once the worklist is empty');
  ok(cards.some(c => /Linen & laundry par levels/.test(text(c)) && /changing it needs laundry.manage/.test(text(c))),
    'hub: a screen that only reads says which permission it takes to change it');

  ok(/moved off the built-in default/.test(text(doc.body)),
    'hub: the strip counts the settings changed on purpose, not only the ones left alone');
  ok(cards.some(c => /changed from the default/.test(text(c))),
    'hub: a card says how many of its settings were moved off the default');

  ok(doc.querySelector('#cfg-q') !== null, 'hub: there is a search box');
  ok(doc.querySelectorAll('.cfg-change').length === 8, 'hub: the recent changes strip lists what changed');
  ok(/Updated banquet settings/.test(text(doc.querySelector('.cfg-change'))),
    'hub: the audit strip names the change, not just the date');

  win.close();
}

// ── the findings: what the saved settings contradict ────────────────────────────────
// A register says where a value is set. It can also say when two values that are already
// set contradict each other — the case a settings screen cannot see, because each screen
// only knows its own fields.
{
  const win = load('configuration.html');
  const doc = win.document;

  const panel = doc.querySelector('.cfg-findings');
  ok(panel !== null, 'findings: the hub carries a panel for what the settings contradict');
  ok(/What the settings contradict/.test(text(panel)), 'findings: the panel says what it is');

  const items = [...doc.querySelectorAll('.cfg-find')];
  ok(items.length === FINDING_ROWS.length, `findings: every contradiction this property carries is listed (${FINDING_ROWS.length})`);
  ok(text(panel).includes(`${severityCount('blocker')} cannot work`), 'findings: the badge counts the ones that mean a feature cannot work');
  const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
  ok(text(panel).includes(plural(severityCount('warning'), 'contradiction', 'contradictions')),
    'findings: the badge counts the contradictions separately, and says it in the plural when there are several');
  ok(text(panel).includes(plural(severityCount('note'), 'policy choice', 'policy choices')),
    'findings: a policy choice is reported without being called an error, in the plural when there are several');

  const sev = [...doc.querySelectorAll('.cfg-find-sev')].map(text);
  ok(sev.filter((x) => x === 'Cannot work').length === severityCount('blocker'), 'findings: the blockers are marked as such');
  ok(sev.filter((x) => x === 'Contradiction').length === severityCount('warning'), 'findings: the contradictions are marked as such');
  ok(sev.filter((x) => x === 'Policy choice').length === severityCount('note'), 'findings: the policy choices are marked as such');
  ok(items[0].querySelector('.cfg-find-sev') && /Cannot work/.test(text(items[0].querySelector('.cfg-find-sev'))),
    'findings: a blocker is printed before a contradiction');
  ok(items.some((i) => /Online payment is switched on, but on the built-in test gateway/.test(text(i))),
    'findings: the payment finding names the trap in plain words');
  ok(items.some((i) => /No real money is collected/.test(text(i))),
    'findings: the finding says what actually happens, not just which setting is wrong');
  ok(items.every((i) => /fix it there/.test(text(i))), 'findings: every finding sends the reader to the screen that owns it');
  const pay = items.find((i) => /Online payment is switched on/.test(text(i)));
  // routes render as "#" in a static preview, so the check reads what the link says —
  // the module label comes from the service, and naming the module is the point
  ok(/Online payments . fix it there/.test(text(pay.querySelector('a.cfg-find-go'))),
    'findings: the payment finding links to the payments module page');
  ok(/Online payments/.test(text(pay)), 'findings: a finding names the module by its label, not its key');

  win.close();
}

// ── a module page that carries a finding ────────────────────────────────────────────
{
  const win = load('configuration-module-findings.html');
  const doc = win.document;

  ok(/Online payments/.test(text(doc.querySelector('.card-head'))), 'finding module: the page is the module that carries it');
  const items = [...doc.querySelectorAll('.cfg-find')];
  ok(items.length === 1, 'finding module: only the findings that touch this module are shown');
  ok(/Online payment is switched on, but on the built-in test gateway/.test(text(items[0])),
    'finding module: the finding is repeated where the value is owned');
  ok(/Reads pay_enabled · pay_provider/.test(text(items[0])),
    'finding module: the page names the settings that are in conflict');
  ok([...doc.querySelectorAll('tbody tr')].some((r) => /pay_provider/.test(text(r))),
    'finding module: the keys the finding names are the keys the module table lists');
  const rzk = [...doc.querySelectorAll('tbody tr')].find((r) => /pay_razorpay_key_id/.test(text(r)));
  ok(/nothing — the service reads it bare in OnlinePaymentService/.test(text(rzk)),
    'finding module: an unset setting with no default says what it runs on instead of printing a blank');
  ok(!/nothing — read with no default/.test(text(doc.body)),
    'finding module: the bare "no default" wording is gone — the cell explains what actually happens');

  // the credentials: the fixture property has a Paystack and a Stripe secret stored, as
  // ciphertext. The page must say one is stored and must not carry the ciphertext at all.
  const cred = [...doc.querySelectorAll('tbody tr')].filter((r) => /credential/.test(text(r)));
  ok(cred.length === 5, 'finding module: every credential of the module is marked as one');
  ok(cred.some((r) => /pay_paystack_secret/.test(text(r)) && /Set — hidden/.test(text(r))),
    'finding module: a stored credential says it is set, never what it is');
  ok(cred.some((r) => /pay_stripe_webhook_secret/.test(text(r)) && /Not set/.test(text(r))),
    'finding module: a credential the property never stored says so, instead of showing a default');
  ok(!/eyJpdiI/.test(doc.body.innerHTML), 'finding module: the stored ciphertext is nowhere on the page');
  ok(/stored encrypted and the register only ever says whether one is set/.test(text(doc.body)),
    'finding module: the page explains why the value is missing');

  win.close();
}

// ── the search state: can a manager find a setting they cannot place ────────────────
{
  const win = load('configuration-search.html');
  const doc = win.document;

  // one module matched, and the page says so in the singular rather than "1 module(s)"
  ok(/1 module matches .valet./.test(text(doc.body)), 'search: the hub says how many modules matched');
  ok(doc.querySelectorAll('.cfg-card').length === 1, 'search: only the matching module is shown');
  ok(/Parking & valet/.test(text(doc.querySelector('.cfg-card'))),
    'search: the module that owns the valet settings is the one found');
  ok(doc.querySelector('#cfg-q').value === 'valet', 'search: the box keeps what was typed');
  ok(/Clear/.test(text(doc.querySelector('.cfg-search'))), 'search: there is a way back to the whole register');

  win.close();
}

// ── one module in full ──────────────────────────────────────────────────────────────
{
  const win = load('configuration-module.html');
  const doc = win.document;

  ok(/Banquet & events/.test(text(doc.querySelector('.card-head'))), 'module: the page is titled with the module');
  const rows = [...doc.querySelectorAll('tbody tr')];
  ok(rows.length === 7, 'module: every setting the module reads is listed');
  ok(rows.some(r => /Deposit as a percentage of the event/.test(text(r)) && /banquet_deposit_pct/.test(text(r)) && /25/.test(text(r))),
    'module: a row carries the label, the key and the value on this property');
  ok(rows.some(r => /Cancellation policy/.test(text(r)) && /Default/.test(text(r))),
    'module: a setting the property never set says it is running on the default, not blank');
  ok(rows.every(r => r.querySelectorAll('td').length === 6),
    'module: every row carries value, default, state and where the setting is read');
  ok(/Read in/.test(text(doc.querySelector('thead'))), 'module: the table has a column for what depends on the setting');
  ok(rows.some(r => /banquet_deposit_pct/.test(text(r)) && /BanquetController, quote\.blade/.test(text(r))),
    'module: a row names the files that read the setting');
  ok(rows.filter(r => /—/.test(r.querySelectorAll('td')[5]?.textContent ?? '')).length === 0,
    'module: every setting of this module is read somewhere — the register never lists a dead key');
  ok(/banquet\.settings/.test(text(doc.querySelector('.cfg-meta'))), 'module: the page names the screen that edits it');
  ok(/opens with banquet\.setup/.test(text(doc.querySelector('.cfg-meta'))),
    'module: the page names the permission the screen needs');
  ok(/Built-in default/.test(text(doc.querySelector('thead'))),
    'module: the table carries the built-in default beside the value');
  ok(rows.some(r => /Guaranteed covers are fixed/.test(text(r)) && /7/.test(text(r)) && /Changed/.test(text(r))),
    'module: a setting the property moved off its default says so');
  ok(rows.some(r => /Deposit as a percentage/.test(text(r)) && /25/.test(text(r)) && /Set/.test(text(r))),
    'module: a setting set to exactly its default is not called a change');
  ok(/moved off the built-in default/.test(text(doc.querySelector('.cfg-meta'))),
    'module: the page counts how many of its settings were changed on purpose');
  ok(doc.querySelectorAll('.cfg-change').length === 2, 'module: its own changes are shown');

  win.close();
}

// ── the two screens this round built ────────────────────────────────────────────────
{
  const win = load('rentals-settings.html');
  const doc = win.document;

  ok(/Car Rental Settings/.test(text(doc.querySelector('.page-title, h2, .topbar'))) || /Car Rental \/ Settings/.test(text(doc.body)),
    'rentals: the screen is the car rental settings page');
  ok(doc.querySelector('input[name="rental_free_km"]')?.value === '150', 'rentals: the free kilometre allowance is on the screen');
  ok(doc.querySelector('input[name="rental_fuel_price"]')?.value === '250', 'rentals: the tank refill charge is settable');
  ok(doc.querySelector('input[name="rental_late_per_hour"]')?.value === '60', 'rentals: the late hour is settable');
  ok(doc.querySelector('input[name="rental_mileage_per_km"]')?.value === '2.5', 'rentals: the excess kilometre is settable');
  ok(/first three hours after the due time are free/.test(text(doc.body)),
    'rentals: the screen states the grace the desk applies');

  win.close();
}

{
  const win = load('hr-settings.html');
  const doc = win.document;

  ok(/HR Settings/.test(text(doc.body)), 'hr: the screen is the HR settings page');
  ok(doc.querySelector('input[name="hr_leave_sla_hours"]')?.value === '24', 'hr: the leave answer window is on the screen');
  ok(!doc.querySelector('input[name="leave_count_all_days"][type="checkbox"]').checked,
    'hr: the working-days default is unticked, which is the Act 651 behaviour');
  ok(doc.querySelector('input[name="leave_count_all_days"][type="hidden"]')?.value === '0',
    'hr: an unticked box still saves an explicit 0, not nothing');
  ok(/Act 651/.test(text(doc.body)), 'hr: the screen says what the counting rule means in law');
  ok(/Payroll › Components carries the same counting rule/.test(text(doc.body)),
    'hr: the screen is honest that the same setting is written from payroll too');

  win.close();
}

// ── what this property changed: the decisions, not the defaults ──────────────────────
{
  const win = load('configuration-changed.html');
  const doc = win.document;

  ok(/Changed here/.test(text(doc.querySelector('.crumbs, .page-title, h2') || doc.body)),
    "changed: the page is the property's decisions, not the register again");
  const stats = [...doc.querySelectorAll('.stat .v')].map(text);
  ok(stats.includes('48'), 'changed: it counts the settings moved off a built-in default');
  ok(stats.includes('22') || stats.some((x) => Number(x) > 0 && Number(x) < MODULE_COUNT),
    'changed: and how many modules this property actually configured');
  ok(/Every other setting is running on the value\s+the code ships with/.test(text(doc.body).replace(/\s+/g, ' '))
     || /running on the value the code ships with/.test(text(doc.body).replace(/\s+/g, ' ')),
    'changed: it says what is *not* on the page and why that matters');

  const tables = [...doc.querySelectorAll('.card table')];
  ok(tables.length === 2, 'changed: the decisions are split into drifted and chosen, not mixed');
  const drifted = tables[0] ? [...tables[0].querySelectorAll('tbody tr')] : [];
  const chosen = tables[1] ? [...tables[1].querySelectorAll('tbody tr')] : [];
  ok(drifted.length === 48, 'changed: every setting moved off its default is listed');
  ok(chosen.length > 0, 'changed: every setting with no default to move off is listed too');
  // the page's own headline counts are the rows it prints, so a row cannot appear or
  // vanish without the number moving with it
  ok(stats[0] === String(drifted.length), `changed: the headline count is the rows beneath it (${drifted.length} drifted)`);
  ok(stats[1] === String(chosen.length), `changed: and the same for the ones with no default (${chosen.length} chosen)`);
  ok(drifted.some((r) => /banquet_guarantee_days/.test(text(r)) && /Banquet & events/.test(text(r)) && /Yaw Antwi/.test(text(r))),
    'changed: a row says what changed, where it belongs, and who changed it');
  ok(drifted.some((r) => /banquet_guarantee_days/.test(text(r)) && /\b7\b/.test(text(r)) && /\b3\b/.test(text(r))),
    'changed: the row carries the value beside the default it moved off');
  ok(chosen.some((r) => /booking_policy/.test(text(r))),
    'changed: a setting the code has no default for is a decision too');
  ok(!/eyJpdiI/.test(doc.body.innerHTML) && !/Set — hidden/.test(text(doc.body)),
    'changed: a credential is never a value on this page either');
  ok(drifted.every((r) => r.querySelectorAll('td').length === 5) && chosen.every((r) => r.querySelectorAll('td').length === 4),
    'changed: the two tables say what they can honestly say — a decided value has no default to show');

  win.close();
}

// ── what an unset setting runs on: the 51 keys with no default at all ───────────────
{
  const win = load('configuration-on-null.html');
  const doc = win.document;

  // The count on the page is checked against the generated file, not against itself: a
  // setting added to the registry has to appear here or the check fails.
  const fbSrc = fs.readFileSync(path.join(ROOT, 'config/setting-fallbacks.php'), 'utf8');
  const fbRows = [...fbSrc.matchAll(/^        '([a-z0-9_]+)' => \['module' => '([^']*)', 'service' => (.+?), 'view' => (.+?)(?:,|])/gm)]
    .map((m) => ({ key: m[1], module: m[2], service: m[3] === 'null' ? null : m[3], view: m[4] === 'null' ? null : m[4] }));
  const keys = [...fbSrc.slice(fbSrc.indexOf("'keys' => [")).matchAll(/^        '([a-z0-9_]+)',$/gm)].map((m) => m[1]);
  const byState = (state) => fbRows.filter((r) => (state === 'code' ? r.service !== null : state === 'page' ? r.service === null && r.view !== null : r.service === null && r.view === null));

  ok(/what an unset setting runs on/i.test(text(doc.body)), 'unset: the page says what it is about');
  const stats = [...doc.querySelectorAll('.stat .v')].map(text);
  ok(stats.includes(String(keys.length)), `unset: it counts the settings with no default to fall back on (${keys.length})`);
  ok(stats.includes(String(byState('code').length)), 'unset: and the ones the code supplies a value for');
  ok(stats.includes(String(byState('page').length)), 'unset: and the ones only a page prints a value for');
  ok(stats.includes(String(byState('null').length)), 'unset: and the ones that run on nothing');

  const cards = [...doc.querySelectorAll('.card.flush')];
  ok(cards.length === 3, 'unset: the three answers are three groups, not one list');
  const headings = cards.map((c) => text(c.querySelector('h3')));
  ok(headings.some((h) => /Falls back to a value written in the code/.test(h)), 'unset: "the code supplies it" is its own group');
  ok(headings.some((h) => /A page prints a value; the service gets null/.test(h)),
    'unset: a value only a page prints is called out — the screen reassuring, the service blind');
  ok(headings.some((h) => /Nothing at all/.test(h)), 'unset: "nothing at all" is said out loud, not left blank');

  const rows = cards.flatMap((c) => [...c.querySelectorAll('tbody tr')]);
  ok(rows.length === fbRows.length, `unset: every key with no default is on the page (${rows.length})`);

  const rowFor = (key) => rows.find((r) => text(r).includes(key));
  ok(/runs on 30 — written in SpaController/.test(text(rowFor('spa_slot_min'))),
    'unset: an unset value is described by the literal the code falls back to, and where it is written');
  ok(/the service sees null; a page prints log/.test(text(rowFor('sms_driver'))),
    'unset: a value only the page prints is described as that, not as the value the service uses');
  ok(/nothing — the service reads it bare/.test(text(rowFor('hk_checklist'))),
    'unset: a setting that really does run on nothing says so');
  ok(!/runs on/.test(text(rowFor('hk_checklist'))), 'unset: and it does not borrow a value from the group above');
  ok(cards[0] && [...cards[0].querySelectorAll('tbody tr')].every((r) => /runs on/.test(text(r))),
    'unset: every row in the first group names the value it runs on');

  ok(rows.every((r) => r.querySelector('td a')), 'unset: every row opens the module that owns the setting');
  ok(rows.every((r) => text(r.querySelector('td a')).length > 2),
    'unset: and the link is the module\'s name, not a bare icon');
  ok(!rows.some((r) => /sms_twilio_token|gst_evat_key|pay_stripe_secret/.test(text(r))),
    'unset: a credential is not described here — its value is never printed to fall back from');
  ok(!rows.some((r) => /(^|\s)(gl_cat_|sms_tpl_|fs_shelf_|fb_sla_)(\s|$)/.test(text(r))),
    'unset: a family prefix is not here either — what it holds is whatever rows exist');
  ok(rows.some((r) => /sms_tpl_welcome/.test(text(r))),
    'unset: but a real key that merely starts like a family is here');
  // every row either prints the value the property set, the value the code supplies, or
  // the words "not set" — an empty cell would leave the reader to guess which it is
  ok(rows.every((r) => /not set|runs on/.test(text(r)) || /\S/.test(text(r.querySelectorAll('td')[2] ?? r))),
    'unset: every row says what the setting runs on, or that it is not set — never an empty cell');
  ok(rows.length === keys.length, `unset: every setting with no built-in default is on the page (${keys.length})`);
  ok(/What an unset value runs on/.test(text(doc.body)) && /Read in/.test(text(doc.body)),
    'unset: the row says where the setting is read, as the register does');
  ok(/each row opens the module that owns it/.test(text(doc.body)), 'unset: the page says it does not edit a value');

  win.close();
}

// ── what it was before: the value trail behind "last changed by" ─────────────────────
{
  const win = load('configuration-history.html');
  const doc = win.document;
  const body = text(doc.body);

  ok(/what it was before/i.test(body), 'trail: the page says what it is');
  const stats = [...doc.querySelectorAll('.stat .v')].map(text);
  ok(stats.includes('412'), 'trail: it counts the value changes recorded');
  ok(stats.includes('96 of 276'), 'trail: and how much of the register the trail covers, rather than implying all of it');

  const rows = [...doc.querySelectorAll('tbody tr')];
  ok(rows.length === 9, `trail: one row per recorded change (${rows.length})`);
  const columnNames = [...doc.querySelectorAll('thead th')].map(text).join('|');
  ok(/Was\|Now/.test(columnNames), 'trail: the table has a "was" and a "now" column, not just "changed"');

  const rowFor = (key) => rows.find((r) => text(r).includes(key));
  const hours = rowFor('cancel_free_hours');
  ok(/72/.test(text(hours)) && /48/.test(text(hours)),
    'trail: the row carries both values — 72 became 48, which no activity log could say');
  ok(/Yaw Antwi/.test(text(hours)), 'trail: and who changed it');

  const secret = rowFor('portal_wifi_password');
  ok(secret && (text(secret).match(/Set — hidden/g) ?? []).length === 2,
    'trail: a credential shows the same masked text it always shows, in both cells');
  ok(!/Set — hidden/.test(text(hours)), 'trail: an ordinary value is not masked');

  ok(/nothing — it was not set/.test(text(rowFor('sms_driver'))),
    'trail: a setting that did not exist says so, rather than showing was = 0');
  ok(/cleared/.test(text(rowFor('flash_report_emails'))),
    'trail: a value cleared back to nothing says "cleared" rather than printing a blank cell');
  ok(rows.every((r) => r.querySelector('td a')), 'trail: every row opens the module that owns the setting');
  ok(rows.every((r) => /\b\d{2} \w{3} \d{4}\b/.test(text(r))), 'trail: every row is dated');
  ok(/The trail begins with this release/.test(body),
    'trail: the page admits the changes it cannot show instead of implying it has them all');

  // the one write: restore a value, only where the register can check the rule
  const buttons = [...doc.querySelectorAll('tbody form button')];
  ok(buttons.length === 4, `trail: a restore is offered where the register can check the value (${buttons.length})`);
  ok(buttons.every((b) => /Put it back/.test(text(b))), 'trail: the action says what it does');
  const formFor = (key) => rowFor(key)?.querySelector('form');
  ok(Boolean(formFor('spa_slot_min')), 'trail: a typed setting that was changed can be put back');
  ok(!formFor('portal_wifi_password'), 'trail: a credential is never offered — only that it changed was recorded');
  ok(!formFor('banquet_guarantee_days'), 'trail: a module-owned setting is not offered here');
  ok(!formFor('pos_kot_print_mode'), 'trail: a setting that was first set is not a restore');
  ok(/owned by its module screen/.test(text(rowFor('banquet_guarantee_days'))),
    'trail: the row says where its rules live instead of offering a button that would fail');
  ok(/a credential is not recorded here/.test(text(rowFor('portal_wifi_password'))),
    'trail: and a credential row says why it cannot come back');
  ok(/One action on this page: put it back/.test(body),
    'trail: the page states the one thing it can do, rather than claiming to be read-only');
  ok([...doc.querySelectorAll('tbody form')].length === 4, 'trail: and nothing else on the page posts');
  win.close();
}

{
  // the module screen answers the same question where the value is actually set
  const win = load('configuration-module.html');
  const doc = win.document;
  ok(/What these settings were before/.test(text(doc.body)),
    'trail: the module screen carries its own slice of the trail');
  ok(/Full trail/.test(text(doc.body)), 'trail: and links to the whole trail');
  win.close();
}

// ── the handover sheet: one page, and a fingerprint two sheets can be compared by ────
{
  const win = load('configuration-handover.html');
  const doc = win.document;
  const body = text(doc.body);

  for (const heading of ['1 · The decisions this property made', '2 · What an unset setting runs on',
    '3 · What the settings contradict', '4 · What is checked against what', '5 · Credentials',
    '6 · What it was before', '7 · Sign-off']) {
    ok(body.includes(heading), `handover: the sheet carries "${heading}"`);
  }

  // the fingerprint: twelve characters, and the register prints the same twelve
  const fp = (html) => {
    const win2 = new JSDOM(html).window;
    return text(win2.document.querySelector('.cfg-fingerprint'));
  };
  const mine = fp(fs.readFileSync(preview('configuration-handover.html'), 'utf8'));
  const theirs = fp(fs.readFileSync(preview('configuration-register.html'), 'utf8'));
  ok(/^[0-9A-F]{12}$/.test(mine), `handover: the fingerprint is twelve characters a person can compare (${mine})`);
  ok(mine === theirs, 'handover: the register prints the same fingerprint, so two sheets that disagree are two configurations');
  ok(/the same fingerprint means the same configuration/.test(body), 'handover: it says how to use it');

  // the sheet and the page must say the same thing about the same configuration
  const changedWin = new JSDOM(fs.readFileSync(preview('configuration-changed.html'), 'utf8'));
  const changedStats = [...changedWin.window.document.querySelectorAll('.stat .v')].map((n) => (n.textContent ?? '').trim());
  const driftedCount = changedStats[0];
  const chosenCount = changedStats[1];
  ok(body.includes(`${driftedCount} settings were moved off the value the code ships with`),
    `handover: the decisions on the sheet are the decisions on the page (${driftedCount} moved off the default)`);
  ok(body.includes(`${chosenCount} were filled in where the code has no default at all`),
    `handover: including the ones with no default to move off (${chosenCount})`);
  // and the count of settings with no built-in default comes from the two generated files
  // the sheet counts what the fallbacks file counts: registered keys with no built-in
  // default, a credential or a family prefix excluded
  const fbSrcHand = fs.readFileSync(path.join(ROOT, 'config/setting-fallbacks.php'), 'utf8');
  const noDefault = [...fbSrcHand.slice(fbSrcHand.indexOf("'keys' => [")).matchAll(/^ {8}'([a-z0-9_]+)',$/gm)].length;
  ok(body.includes(`${noDefault} settings have no built-in default`),
    `handover: it says what an unset value runs on (${noDefault} settings with no default)`);
  ok(/Online payment is switched on, but on the built-in test gateway/.test(body),
    'handover: the findings are printed in full, not as a count');
  ok(new RegExp(`${GUARDED} of ${MODULE_COUNT} modules have settings the code compares with each other`).test(body),
    'handover: it says how far the checks reach');
  // the sheet also states what the register does not hold: settings read behind a service
  // map. The number is recomputed here from the same two files the fixture reads.
  const readersSrcJs = fs.readFileSync(path.join(ROOT, 'config/setting-readers.php'), 'utf8');
  const mapAt = readersSrcJs.indexOf("'map' => [");
  const mapBlock = readersSrcJs.slice(readersSrcJs.indexOf('\n', mapAt), readersSrcJs.indexOf('\n    ],', mapAt));
  const MAP_KEYS = [...mapBlock.matchAll(/'([a-z0-9_]+)' => \[/g)].map((m) => m[1]);
  const registryNames = new Set([...fs.readFileSync(path.join(ROOT, 'config/settings.php'), 'utf8')
    .matchAll(/^ {16}'([a-z0-9_]+)' => /gm)].map((m) => m[1]));
  const GAP = MAP_KEYS.filter((k) => !registryNames.has(k)).length;
  ok(MAP_KEYS.length > 300, `handover: the read graph carries the settings read behind a map (${MAP_KEYS.length})`);
  ok(body.includes(`${GAP} settings are read by the code through a service map`),
    `handover: the sheet states the ${GAP} settings read behind a map that the register does not hold`);

  ok(/not the same as "checked and clean"/.test(body),
    'handover: and refuses to let an unchecked module read as verified');
  ok(/9 of them/.test(body) || /are set across/.test(body), 'handover: it counts the credentials that are set');
  // section 3 states the findings by severity, so the sheet an auditor signs counts them
  // the way the hub does — in the plural when there is more than one, never "finding(s)"
  const count = (n, one, many) => `${n} ${n === 1 ? one : many}`;
  ok(body.includes(`${count(severityCount('blocker'), 'finding means', 'findings mean')} a feature cannot work`),
    `handover: section 3 counts the blockers (${severityCount('blocker')}) in words, not as "finding(s)"`);
  ok(!/finding\(s\)/.test(body), 'handover: and the sheet carries no "(s)" hedge for a person to sign');
  ok(!/Grove-Guest-2026|whk_9d21c4/.test(body), 'handover: and prints no credential value');
  ok(/value changes are recorded/.test(body), 'handover: the value trail is on the sheet');
  // the number outside the register is arithmetic over two generated files: the map reads the
  // code makes, minus the keys the register now holds. Typing it would let it drift silently.
  const registered = new Set([...fs.readFileSync(path.join(ROOT, 'config/settings.php'), 'utf8')
    .matchAll(/^ {16}'([a-z0-9_]+)' => /gm)].map(m => m[1]));
  const readersSrc = fs.readFileSync(path.join(ROOT, 'config/setting-readers.php'), 'utf8');
  const mapSrc = readersSrc.slice(readersSrc.indexOf("'map' => ["), readersSrc.indexOf("\n    ],", readersSrc.indexOf("'map' => [")));
  const mapKeys = [...mapSrc.matchAll(/^ {8}'([a-z0-9_]+)' => /gm)].map(m => m[1]);
  const gap = mapKeys.filter(k => !registered.has(k)).length;
  ok(new RegExp('Beyond the modules:\\s*' + gap + '\\b').test(body),
    `handover: the settings outside the register are counted — ${gap} of ${mapKeys.length} map-read keys`);
  ok(/Prepared by/.test(body) && /Reviewed by/.test(body), 'handover: there is somewhere to sign it');

  // The fixture must hash a credential as set/unset, never its value: otherwise the preview
  // would be built from a hash that could be used to guess one, and comparing the two sheets
  // above would still pass because both would be wrong in the same way.
  const sample = fs.readFileSync(path.join(ROOT, 'deploy/dev/preview/configuration-sample.mjs'), 'utf8');
  ok(/k\.secret\) \{ lines\.push\(`\$\{k\.raw\}=credential:/.test(sample),
    'handover: the fixture hashes a credential as set or unset, never its value');

  win.close();
}

// ── the printed register: every key on paper ────────────────────────────────────────
{
  const win = load('configuration-register.html');
  const doc = win.document;

  ok(doc.querySelectorAll('.cfg-print-group').length === 12, 'print: every group is on the sheet, plus the findings');
  ok(doc.querySelectorAll('.cfg-print-module').length === MODULE_COUNT, 'print: every module is on the sheet');
  const rows = [...doc.querySelectorAll('.cfg-print-module table tbody tr')];
  ok(rows.length === REGISTER_KEYS, 'print: every setting is printed, not a sample — one row per registered key');
  ok(rows.some(r => /banquet_deposit_pct/.test(text(r)) && /25/.test(text(r))),
    'print: a printed row carries the key and the value');
  ok(rows.some(r => /parking_hour_rate/.test(text(r)) && /5\.00/.test(text(r))),
    'print: a value from another module is printed too');
  ok(/every module that has something to configure|Every module that has something to configure/i.test(text(doc.body)),
    'print: the sheet states what it is');
  ok(/default/.test(text(doc.body)), 'print: an unset key prints as default so a new property can be checked against it');
  ok(/Built-in default/.test(text(doc.body)), 'print: the sheet carries the default a new property would start from');
  ok(/·changed/.test(text(doc.body)), 'print: a setting moved off its default is marked on paper too');
  ok(rows.some(r => /Drill frequency \(30–730 days\)/.test(text(r))),
    'print: a brief states the range the screen enforces, so nobody types an impossible value');
  // round 20: the health club and the front desk were registering nothing on the sheet even
  // though both have a settings screen; a row proves the value and the default reached paper
  ok(rows.some(r => /Capacity – swimming pool/.test(text(r)) && /club_cap_pool/.test(text(r)) && /\b40\b/.test(text(r))),
    'print: the health club capacities are printed with the default the tuple map ships');
  ok(rows.some(r => /Morning shift starts \(HH:MM\)/.test(text(r)) && /desk_shift_morning/.test(text(r)) && /07:00/.test(text(r))),
    'print: the desk shift start is printed as a time, not as a counter');
  ok(rows.some(r => /Valuable from estimated value \(GH₵\)/.test(text(r)) && /desk_lf_valuable_amount/.test(text(r))),
    'print: and a lost-property threshold carries its unit on paper');
  // round 21: the forex desk's screen has been there all along, posting short field names; its
  // rows and the licence it prints on a certificate belong on the sheet like any other
  ok(rows.some(r => /Cash limit per foreign guest \(USD\)/.test(text(r)) && /forex_cash_cap_usd/.test(text(r)) && /3000/.test(text(r))),
    'print: the forex cash cap is printed with the USD limit the service ships');
  ok(/forex_licence/.test(text(doc.body)) && /forex_franchiser/.test(text(doc.body)),
    'print: the licence and the principal are printed, so the desk knows which bureau it trades for');
  ok(/USD 0–10000/.test(text(doc.body)),
    'print: the forex brief states the cap it enforces, not only the key name');
  // round 22: the hardware screen is where a property actually sets its PABX and lock links, and
  // a reply pattern printed without its backslashes would be a command that never matches
  ok(rows.some(r => /Seconds to wait for the PABX command reply/.test(text(r)) && /pbx_tcp_timeout/.test(text(r)) && /\b5\b/.test(text(r))),
    'print: the PABX command timeout is printed with the seconds the service ships');
  ok(new RegExp('pbx_tcp_ok.*?\\\\b\\(OK').test(text(doc.body)),
    'print: a reply pattern is printed with the backslashes it is matched by');
  ok(rows.some(r => /A visitor pass is valid for \(hours\)/.test(text(r)) && /sec_pass_hours/.test(text(r)) && /\b4\b/.test(text(r))),
    'print: the security desk\'s pass length is printed with its default');
  // round 23: the Robita locks screen had no module of its own — the sheet has to show one
  ok(rows.some(r => /Flag a lock at or below this battery level \(%\)/.test(text(r)) && /robita_battery_alert/.test(text(r)) && /\b20\b/.test(text(r))),
    'print: the Robita lock battery level is printed with its default, on its own module');
  ok(rows.some(r => /Send offers this many days before arrival/.test(text(r)) && /upsell_days_before/.test(text(r)) && /\b3\b/.test(text(r))),
    'print: the upselling window is printed with its default');
  ok(rows.some(r => /Allow channel and test calls to private addresses/.test(text(r)) && /api_allow_private/.test(text(r))),
    'print: the API private-address switch sits with the API, not with the hardware');
  ok((text(doc.body).match(/Allow channel and test calls to private addresses/g) || []).length === 1,
    'print: and it is printed once — a setting filed in two modules would drift between them');
  // round 24: the ESG baseline and the transport desk — a factor printed without its unit or a
  // lead time printed without its minutes would be a number nobody could check
  ok(rows.some(r => /Washing and drying linen \(kg CO₂e per kg\)/.test(text(r)) && /esg_ef_laundry/.test(text(r)) && /0\.60/.test(text(r))),
    'print: the laundry emission factor is printed with its unit and the figure the service ships');
  ok(rows.some(r => /Total floor area \(m²\)/.test(text(r)) && /esg_area_total/.test(text(r)) && /9500/.test(text(r))),
    'print: the floor area the carbon is allocated by is printed with its value');
  ok(rows.some(r => /A pick-up leaves this many minutes before the flight lands/.test(text(r)) && /transport_pickup_lead/.test(text(r)) && /\b60\b/.test(text(r))),
    'print: the transport pick-up lead time is printed with its default');
  ok(/0–1000000 in all/.test(text(doc.body)) && /0–300 minutes/.test(text(doc.body)),
    'print: both briefs state the range the code refuses outside of');
  // round 25: the TV and the kiosk — a language list or a signed terms text is still a setting
  ok(rows.some(r => /A TV heartbeats every \(seconds\)/.test(text(r)) && /tv_heartbeat/.test(text(r)) && /\b20\b/.test(text(r))),
    'print: the TV heartbeat is printed with the seconds the service ships');
  ok(rows.some(r => /Refundable incidentals deposit/.test(text(r)) && /kiosk_incidentals/.test(text(r))),
    'print: the kiosk deposit is printed as a money setting, not only a switch');
  ok(rows.some(r => /Registration terms the guest signs at the kiosk/.test(text(r)) && /kiosk_terms/.test(text(r))),
    'print: the terms a guest signs are listed as a setting a manager can change');
  // round 27: four more modules on the sheet — the inbox, the sales pipeline, the food-safety
  // critical limits and the report desk
  ok(rows.some(r => /First reply due within \(1–240 minutes\)/.test(text(r)) && /inbox_sla/.test(text(r))),
    'print: the inbox reply target is listed with the minutes its box allows');
  ok(rows.some(r => /Proposal stage probability \(1–99 %\)/.test(text(r)) && /sales_prob_proposal/.test(text(r))),
    'print: a sales stage probability is listed with its 1–99 % band');
  ok(/critical limit is held between -40 and 100/.test(text(doc.body)) && /fs_hot_hold_min/.test(text(doc.body)),
    'print: the food-safety limits are listed under the band the controller enforces');
  ok(rows.some(r => /Maximum recipients per schedule \(1–100\)/.test(text(r)) && /rpt_max_recipients/.test(text(r))),
    "print: the report desk's recipient cap is listed");
  // round 28: privacy, the concierge desk, learning and the fleet
  ok(rows.some(r => /Answer a data-subject request within \(1–90 days\)/.test(text(r)) && /privacy_response_days/.test(text(r))),
    'print: the data-subject response window is listed with its 1–90 days');
  ok(/ID scans are pruned after 0–3650 days/.test(text(doc.body)) && /retention_id_days/.test(text(doc.body)),
    'print: the retention rules are printed with the window the controller enforces');
  ok(rows.some(r => /Treat a GPS device as offline after \(2–1440 minutes\)/.test(text(r)) && /transport_gps_stale_minutes/.test(text(r))),
    'print: the fleet GPS threshold is listed under the fleet module, not the transport desk');
  // round 29: the last thirty keys — energy factors, the lease desk, statutory payroll and the
  // withholding-tax desk — and the sheet now says nothing is left outside the register
  ok(rows.some(r => /Grid electricity emission factor \(0–100 kg CO₂e \/ kWh\)/.test(text(r)) && /eng_ef_electricity/.test(text(r))),
    'print: the grid emission factor is listed with the 0–100 clamp the service applies');
  ok(rows.some(r => /Withholding tax a company tenant deducts from rent \(0–100000 %\)/.test(text(r)) && /lease_wht_rate/.test(text(r))),
    'print: the lease withholding rate is listed under the lease desk');
  ok(rows.some(r => /Deduct withholding tax above \(0–10000000 GH₵\)/.test(text(r)) && /wht_threshold/.test(text(r))),
    'print: the withholding-tax threshold is listed under payables');
  // round 26: nine screens print their bounds on a plain input; the sheet has to say what those
  // bounds are, or a reader learns the limit by having a value refused
  ok(/Parking is free for 0–240 minutes/.test(text(doc.body)) && /validated for 0–24 hours/.test(text(doc.body)),
    'print: the parking brief states the free minutes and the validation window its screen enforces');
  ok(/40–320 hours a month/.test(text(doc.body)) && /30–1800 seconds/.test(text(doc.body)),
    'print: the HSE brief states the training month and the evacuation target');
  ok(/The booking deposit is 1–100 % of the stay/.test(text(doc.body)),
    'print: the payments brief states the deposit percentage beside the link lifetime');
  ok(rows.some(r => /Evacuation drill target \(30–1,800 seconds/.test(text(r))),
    'print: and it states the unit the code validates in, not the one it was mistaken for');
  ok(/What the settings contradict/.test(text(doc.body)), 'print: the findings are on paper as well as on screen');
  ok(/Guests earn loyalty points that are worth nothing/.test(text(doc.body)),
    'print: a finding is printed in full, so the paper shows what the screen shows');
  ok(/findings mean a feature cannot work as configured/.test(text(doc.body)),
    'print: the sheet explains how to read the findings, and says it in the plural when there are several');

  win.close();
}

// ── report ─────────────────────────────────────────────────────────────────────────
console.log(`configuration preview check: ${checks - failures.length}/${checks} passed`);
if (failures.length) {
  console.log('  FAILURES:');
  for (const f of failures) console.log(`    ✗ ${f}`);
  process.exit(1);
}
