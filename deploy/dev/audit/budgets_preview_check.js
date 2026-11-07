#!/usr/bin/env node
/*
 * Budgets: the settings modal decides when a variance has to be explained and whether an
 * overspent bill is warned about or blocked. Its variance threshold had three different answers —
 * the brief said 0–100 %, the controller accepted 0–1000 %, and the input printed no band at all.
 * A threshold above 100 % means no variance in the year is ever flagged.
 *
 * This renders deploy/dev/previews/budgets.html (go-live set) and budgets-no-golive.html (no go-live
 * date, so the screen has to prompt for one) in jsdom against deploy/dev/preview/budgets-sample.mjs.
 *
 * Run: node deploy/dev/audit/budgets_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file) => {
  const raw = readFileSync(file, 'utf8');
  const dom = new JSDOM(raw, { url: 'https://preview.local/budgets' });
  const doc = dom.window.document;
  const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  return { raw, dom, doc, text, $: (s) => doc.querySelector(s), $$: (s) => [...doc.querySelectorAll(s)] };
};

const live = load('deploy/dev/previews/budgets.html');
const noGo = load('deploy/dev/previews/budgets-no-golive.html');
const field = (page, name) => page.$(`#settingsForm [name=${name}]`);
const hintOf = (page, name) => {
  const input = field(page, name);
  return page.text(input?.closest('.field') ?? input?.parentElement);
};

/* ── the versions ────────────────────────────────────────────────────────── */
ok('the page is the budget workspace', live.doc.title.startsWith('Budgets'), live.doc.title);
const rows = live.$$('#budgetsTable tbody tr');
ok('every budget version is listed', rows.length === 6, `${rows.length} rows`);
const table = live.text(live.$('#budgetsTable'));
ok('the approved version is marked active', /Active/.test(table));
ok('a re-forecast is named as one', /Re-forecast/.test(table));
ok('and says which version it came from', /from BUD-2026-27-01/.test(table));
ok('what a version was built from is shown', /Last year/.test(table) && /Copy/.test(table) && /Run-rate/.test(table));
ok('growth is shown signed', /\+8\.5 % \/ \+6\.0 %/.test(table), table.slice(0, 300));
ok('each row carries its totals',
  /6,050,000\.00/.test(table) && /3,980,000\.00/.test(table) && /2,070,000\.00/.test(table));

/* ── v8.1 cost centre dimension: a version can plan one department ─────────── */
const deptRow = rows.find(r => /Housekeeping/.test(text2(r)));
function text2(n) { return (n?.textContent ?? '').replace(/\s+/g, ' ').trim(); }
ok('a departmental version is listed and names its cost centre', Boolean(deptRow) && /HK · Housekeeping/.test(text2(deptRow)), text2(deptRow).slice(0, 120));
ok('the whole-hotel versions say so rather than leaving the column blank',
  rows.filter(r => /Whole hotel/.test(text2(r))).length === 5, `${rows.filter(r => /Whole hotel/.test(text2(r))).length} whole-hotel rows`);
ok('the cost centre column is headed', /Cost centre/.test(live.text(live.$('#budgetsTable thead'))));
ok('the table still has a header cell for every column',
  live.$$('#budgetsTable thead th').length === 13 && live.$('#budgetsTable tbody tr').children.length === 13,
  `${live.$$('#budgetsTable thead th').length} headers / ${live.$('#budgetsTable tbody tr').children.length} cells`);
const ccField = live.$('#newBudgetForm [name=department_id]');
ok('a new version can be given a cost centre', Boolean(ccField));
ok('…and blank means the whole hotel', /whole hotel/i.test(text2(ccField?.closest('.field'))), text2(ccField?.closest('.field')).slice(0, 160));
ok('the create form warns that a departmental version holds money only',
  /rooms statistics stay on the whole-hotel budget/.test(text2(ccField?.closest('.field'))));
ok('the screen links to the cost-centre analysis', Boolean(live.$('#ccLink')) && /Cost centres/.test(text2(live.$('#ccLink'))));

const stats = live.text(live.$('.stats'));
ok('the active budget is named up top', /BUD-2026-27-01/.test(stats), stats.slice(0, 140));
ok('with its budgeted revenue and net margin', /GH₵ 6,050,000\.00/.test(stats) && /34\.2 % margin/.test(stats), stats.slice(0, 260));
ok('and what is waiting for approval', /Awaiting approval/.test(stats) && /Active forecast BUD-2026-27-03/.test(stats));

ok('the fiscal year and go-live are stated in the head',
  /Fiscal year starts in April · compared from 01 Apr 2026/.test(live.text(live.doc.body)));

/* ── the settings modal: the band the brief promises ─────────────────────── */
ok('the settings form is on the screen', Boolean(live.$('#settingsForm')));

const pct = field(live, 'var_pct');
ok('the variance threshold is a number', pct?.getAttribute('type') === 'number');
ok('it has a floor', pct?.getAttribute('min') === '0', pct?.getAttribute('min'));
ok('and the ceiling the brief states', pct?.getAttribute('max') === '100', pct?.getAttribute('max'));
ok('it takes one decimal place', pct?.getAttribute('step') === '0.1', pct?.getAttribute('step'));
ok('the band is printed in words too', /0 to 100/.test(hintOf(live, 'var_pct')), hintOf(live, 'var_pct'));
ok('and the stored threshold is what the box holds', pct?.getAttribute('value') === '10', pct?.getAttribute('value'));

const amount = field(live, 'var_amount');
ok('the amount test has a floor of zero', amount?.getAttribute('min') === '0');
ok('and says it has no ceiling rather than leaving it silent', /no ceiling/.test(hintOf(live, 'var_amount')), hintOf(live, 'var_amount'));

const fy = field(live, 'fy_start');
ok('the fiscal year start is a choice of twelve months', fy?.tagName === 'SELECT' && fy.options.length === 12, `${fy?.options.length}`);
ok('with April chosen', fy?.value === '4', fy?.value);
ok('the go-live date is a date, with its consequence explained',
  field(live, 'live_from')?.getAttribute('type') === 'date'
  && /earlier months are ignored/.test(hintOf(live, 'live_from')));

ok('spend control is a choice of three', field(live, 'control')?.options.length === 3);
ok('including the block that needs an override and a reason',
  /Block – needs budget override \+ reason/.test(live.text(field(live, 'control'))), live.text(field(live, 'control')));
ok('and the basis offers month, year to date and full year',
  [...(field(live, 'control_basis')?.options ?? [])].map(option => option.value).join(',') === 'month,ytd,annual');

/* ── the go-live prompt ──────────────────────────────────────────────────── */
ok('with no go-live date the screen asks for one', /set the go-live date/.test(noGo.text(noGo.doc.body)));
ok('and then does not claim a comparison start', !/compared from/.test(noGo.text(noGo.doc.body)));
ok('the settings offer the cost-centre rule for manual journals',
  Boolean(field(live, 'cost_centre_required')) && /off or on/.test(hintOf(live, 'cost_centre_required')), hintOf(live, 'cost_centre_required'));
ok('and say who refuses what when it is on', /the journal screen and the server both refuse/.test(hintOf(live, 'cost_centre_required')));

ok('the settings still state the same band without it',
  field(noGo, 'var_pct')?.getAttribute('max') === '100');

/* ── v8.3 – approval thresholds by value ─────────────────────────────────── */
// Four eyes are not enough for every plan: above the value in settings a version needs a second approver, and the list
// has to say which versions those are before anyone opens them.
const threshold = field(live, 'dual_approval_above');
ok('the approval threshold is a setting with the value on the screen', threshold?.value === '5000000', threshold?.value);
ok('…and its band is stated, including what 0 means',
  /0 and up/.test(hintOf(live, 'dual_approval_above')) && /0 means one approver is enough/.test(hintOf(live, 'dual_approval_above')),
  hintOf(live, 'dual_approval_above'));
const versionRows = (page) => [...page.$$('#budgetsTable tbody tr')].map(r => ({ row: r, text: page.text(r), no: r.dataset.budget }));
const at = (page, no) => versionRows(page).find(r => r.no === no);

const once = at(live, 'BUD-2026-27-02');
ok('a version signed once over the threshold says 1 of 2 approved', /1 of 2 approved/.test(once?.text ?? ''), once?.text?.slice(0, 120));
ok('…and is still shown as awaiting approval, not approved', /Awaiting approval/.test(once?.text ?? ''));
ok('a version over the threshold nobody has signed says 2 approvers needed',
  /2 approvers needed/.test(at(live, 'BUD-2026-27-03')?.text ?? ''), at(live, 'BUD-2026-27-03')?.text?.slice(0, 120));
const fully = at(live, 'BUD-2026-27-01');
ok('a version with both signatures says 2 of 2 approved', /2 of 2 approved/.test(fully?.text ?? ''), fully?.text?.slice(0, 120));
ok('…and names the second approver beside the first', /2nd: Ama Serwaa/.test(fully?.text ?? ''), fully?.text?.slice(0, 200));
ok('a version below the threshold carries no count at all',
  !/of 2 approved|2 approvers needed/.test(at(live, 'BUD-2026-27-11')?.text ?? ''), at(live, 'BUD-2026-27-11')?.text?.slice(0, 120));
ok('the rule is printed with the figure taken from settings',
  /GH₵ 5,000,000\.00/.test(live.text(live.doc.body)) && /needs two approvers, one after the other, from two different people/.test(live.text(live.doc.body)));
ok('the awaiting-approval count includes a version waiting for its second signature', /Awaiting approval[\s\S]{0,40}>?2</.test(live.raw.replace(/\s+/g, ' ')), 'pending count');

/* ── v8.4 – capex vs opex ────────────────────────────────────────────────── */
// A capital plan is a version of the same year whose lines are capital asset accounts. The list has to show it as such
// and stop printing three zeros where revenue, expense and net would be.
const capitalRow = at(live, 'CAP-00001');
ok('the capital plan is listed with its own number and kind', /Capital plan/.test(capitalRow?.text ?? ''), capitalRow?.text?.slice(0, 120));
const capitalCells = [...capitalRow.row.children].map(c => live.text(c));
ok('…with dashes where revenue, expenses and net would be, not zeros',
  capitalCells[5] === '—' && capitalCells[6] === '—' && capitalCells[7] === '—', JSON.stringify(capitalCells.slice(5, 9)));
ok('…and its capital figure in the capital column', /1,450,000\.00/.test(capitalCells[8]), capitalCells[8]);
ok('a P&L version carries no capital figure', !/\d/.test(at(live, 'BUD-2026-27-01') ? [...at(live, 'BUD-2026-27-01').row.children].map(c => live.text(c))[8] : 'x'));
ok('the active capital plan is named in its own card, with the money committed',
  /Active capital plan/.test(stats) && /CAP-00001/.test(stats) && /Capital budget GH₵ 1,450,000\.00/.test(stats), stats.slice(0, 320));
ok('the capital plan on the screen is not presented as the active P&L budget',
  /Active budget · FY 2026-27/.test(stats) && /BUD-2026-27-01/.test(stats.split('Budgeted revenue')[0]), stats.slice(0, 200));
ok('the new-version dialog asks what is being planned, P&L or capital',
  /name="kind"/.test(live.$('#newBudgetForm')?.outerHTML ?? '') && /Capital expenditure \(assets\)/.test(live.text(live.$('#newBudgetForm'))),
  live.text(live.$('#newBudgetForm'))?.slice(0, 240));
ok('the screen explains that capital is not an expense, in its own words',
  /Capital is not an expense/.test(live.text(live.doc.body)) && /buying a generator puts an asset on the balance sheet, it does not reduce the year's profit/.test(live.text(live.doc.body)));
ok('the note names which asset codes count as capital', /the asset heads coded 15/.test(live.text(live.doc.body)));
const capexField = field(live, 'capex_control');
ok('capital purchases have their own control setting', capexField?.value === 'warn' && live.$$('#settingsForm [name="capex_control"] option').length === 3, capexField?.value);
ok('…and the code prefixes are a setting, not hard-coded', field(live, 'capex_prefixes')?.value === '15', field(live, 'capex_prefixes')?.value);
ok('the prefix field states what it holds', /comma separated, e\.g\. 15,16/.test(hintOf(live, 'capex_prefixes')), hintOf(live, 'capex_prefixes'));

/* ── how it works, and nothing left unrendered ───────────────────────────── */
ok('the screen explains the four-eyes rule', /a different user approves it/.test(live.text(live.doc.body)));
ok('and how a re-forecast is built', /closed months take the actuals from the general ledger/.test(live.text(live.doc.body)));

for (const [label, page] of [['live', live], ['no go-live', noGo]]) {
  const bodyCopy = page.doc.body.cloneNode(true);
  bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
  const text = page.text(bodyCopy);
  ok(`no Blade directive survived in the ${label} state`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php)\b/.test(text));
  ok(`no undefined, NaN or [object Object] reached the ${label} screen`,
    !/undefined|NaN|\[object Object\]/.test(text));
  ok(`no setting key is printed in the ${label} state`, !/budget_(var_pct|var_amount|control_basis|live_from)/.test(text));
}

if (failures.length) {
  console.error(`budgets check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`budgets check: ${passed}/${passed} passed`);
