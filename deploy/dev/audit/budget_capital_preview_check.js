#!/usr/bin/env node
/*
 * Capex vs opex — the capital plan screen.
 *
 * Capital is not an expense. A capital purchase puts an asset on the balance sheet; it does not reduce the year's
 * profit, so it must never be added to revenue, expense or net. The screen has to make that plain and it has to show
 * the figure a supplier bill is measured against:
 *
 *   1. the plan says what it is, in its own words, and no P&L total appears anywhere on it;
 *   2. its totals are capital — committed, spent to date, remaining — not revenue and profit;
 *   3. the plan against capital spend is printed per capital asset account, with the plan to date beside the spend;
 *   4. it is signed like any other version, and one approver is enough at GH₵1,450,000 under the GH₵5,000,000 threshold.
 *
 * Renders deploy/dev/previews/budget-capital.html in jsdom against deploy/dev/preview/budget-capital-sample.mjs.
 *
 * Run: node deploy/dev/audit/budget_capital_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/budget-capital.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/budgets/6' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const body = text(doc.body);

/* ── the screen says what it is ──────────────────────────────────────────── */
ok('the page is the capital plan', /CAP-00001/.test(doc.title), doc.title);
ok('the breadcrumb names it', /CAP-00001 · Capital plan FY 2026-27/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the version is labelled a capital plan, not a budget', /Capital plan/.test(body));
const note = $('#capexNote');
ok('the note says this is money committed to assets, not to the profit and loss',
  Boolean(note) && /money committed to assets, not to the profit and loss/.test(text(note)), text(note));
ok('…that a capital purchase is not an expense of the year', /not an expense of the year/.test(text(note)));
ok('…and that no figure here enters revenue, expenses or net profit', /no figure here enters revenue, expenses or net profit/.test(text(note)));
ok('…and that a supplier bill coded to these heads is measured against the plan',
  /A supplier bill coded to one of these capital asset heads is measured against this plan/.test(text(note)));
ok('…and that an operating cost cannot be coded here', /an operating cost can never be coded here/.test(text(note)));

/* ── the totals are capital, not a profit nobody can make on assets ─────── */
const stats = text($('.stats'));
ok('the first total is the capital budget', /Capital budget/.test(stats) && /GH₵ 1,450,000\.00/.test(stats), stats.slice(0, 200));
ok('…then what has been spent to date', /Spent to date/.test(stats) && /GH₵ 620,000\.00/.test(stats), stats.slice(0, 300));
ok('…then what is committed to approved projects', /Committed/.test(stats) && /GH₵ 280,000\.00/.test(stats) && /approved projects, not yet spent/.test(stats), stats.slice(0, 460));
ok('…and what is left once commitments are counted, not just what is unspent',
  /Remaining/.test(stats) && /GH₵ 550,000\.00/.test(stats), stats.slice(0, 560));
ok('the share of the plan already spent is printed with its unit',
  /42\.8 % of the plan for the year/.test(stats), stats.slice(0, 400));
ok('no revenue, expense or profit figure is presented as a total',
  !/Budgeted revenue|Budgeted net profit|Net profit/.test(stats), stats);

/* ── plan against capital spend, per capital asset account ─────────────── */
const card = $('#capitalSpendCard');
ok('the plan is compared with capital spend', Boolean(card));
const head = text($('#capitalTable thead'));
ok('the columns are the plan, the plan to date, the spend, the commitments and what is left',
  /Plan for the year/.test(head) && /Plan to date/.test(head) && /Spent to date/.test(head) && /Committed/.test(head) && /Remaining/.test(head), head);
const rows = $$('#capitalTable tbody tr');
ok('every capital asset account with a plan or spend is listed', rows.length === 4, `${rows.length} rows`);
const kitchen = rows.find(r => /1500/.test(text(r)));
ok('a row carries the account code and its name', /Kitchen and laundry equipment/.test(text(kitchen)), text(kitchen));
ok('the spend is what the ledger debited, not the plan', /380,000\.00/.test(text(kitchen)), text(kitchen));
ok('what is left is printed unsigned with the word carrying the direction', /40,000\.00 left/.test(text(kitchen)), text(kitchen));
ok('the share of the plan spent is a percentage', /90\.5 %/.test(text(kitchen)), text(kitchen));
const vehicle = rows.find(r => /1520/.test(text(r)));
ok('an account with a plan and no spend yet says so in figures', /260,000\.00/.test(text(vehicle)) && /0 %/.test(text(vehicle)), text(vehicle));
ok('the plan to date is shown beside the full-year plan', /420,000\.00/.test(text(kitchen)));
// v8.5 – commitment: an approved project promises money the plan has not yet been debited for. Showing plan − spent
// alone lets the same money be approved twice.
const vehicleRow = rows.find(r => /1520/.test(text(r)));
ok('a head with approved projects shows what they have committed', /120,000\.00/.test(text(vehicleRow)), text(vehicleRow));
ok('…and names the projects behind the figure', /CPX-00004/.test(text(vehicleRow)), text(vehicleRow));
ok('what is left is the plan less the spend AND less the commitment', /140,000\.00 left/.test(text(vehicleRow)), text(vehicleRow));
const roof = rows.find(r => /1530/.test(text(r)));
ok('a head fully committed still shows nothing left', /0\.00 left/.test(text(roof)), text(roof));
const kitchenRow = rows.find(r => /1500/.test(text(r)));
ok('a head with no approved project shows a dash, not a zero', /—/.test([...kitchenRow.children][5].textContent), [...kitchenRow.children][5].textContent.trim());
const foot = text($('#capitalTable tfoot'));
ok('the total is the plan, the spend, the commitment and what is left',
  /1,450,000\.00/.test(foot) && /620,000\.00/.test(foot) && /280,000\.00/.test(foot) && /550,000\.00/.test(foot), foot);
const projects = $$('#capitalProjects tbody tr');
ok('the approved projects drawing on the plan are listed, one row each', projects.length === 2, `${projects.length} rows`);
const shuttle = projects.find(r => /CPX-00004/.test(text(r)));
ok('a project row says what it buys and what is still to spend',
  /Replacement 15-seat shuttle/.test(text(shuttle)) && /260,000\.00/.test(text(shuttle)) && /120,000\.00/.test(text(shuttle)), text(shuttle));
ok('the card says a completed project releases what is left', /Completing or cancelling a project releases what is left/.test(text(card)));
ok('the spend to date is dated to the month the ledger has closed', /through fiscal month 7 of 12/.test(text(card)), text(card).slice(0, 160));

/* ── a capital plan has no profit to report ─────────────────────────────── */
const table = $('#budgetGrid');
ok('the monthly grid is rendered', Boolean(table) && table.querySelectorAll('tbody tr').length > 4);
ok('its one section is capital expenditure', /Total capital expenditure/.test(text(table)), text(table).slice(0, 200));
ok('the section totals to the capital budget', /1,450,000\.00/.test(text($('tr[data-sectotal="capital"] td[data-m="t"]'))), text($('tr[data-sectotal="capital"] td[data-m="t"]')));
ok('no gross operating profit row exists on a capital plan', !$('tr[data-derived="gop"]') && !/GOP/.test(text(table)));
ok('no net profit row exists either', !$('tr[data-derived="net"]') && !/Net profit/.test(text(table)));
ok('the amount legend says capital never enters the profit and loss',
  /money committed to assets, positive; it never enters the profit and loss/.test(text($('#gridForm'))), text($('#gridForm')).slice(0, 240));
ok('a capital plan has no rooms statistics tab', ![...$$('.tabs button')].some(b => /Rooms statistics/.test(text(b))));
ok('the tab is named for what it holds', [...$$('.tabs button')].some(b => /Capital by month/.test(text(b))));

/* ── signed like any other version ──────────────────────────────────────── */
ok('an approved, active plan is not offered an approve button', !$('#approveBtn'));
ok('it can still be re-opened for changes', Boolean($('#reopenBtn')));
ok('the history records the plan being approved and made active', /Made active/.test(text($('#budgetEvents'))) && /Approved/.test(text($('#budgetEvents'))));
ok('the details panel names the kind', /Capital plan/.test(text($('.kv-grid'))), text($('.kv-grid')).slice(0, 200));

/* ── when it falls due ──────────────────────────────────────────────────── */
const due = $('#capitalDue');
ok('the plan shows when the money promised falls due', Boolean(due), 'no #capitalDue');
const cells = $$('#capitalDueStrip .due-cell');
ok('one cell per fiscal month', cells.length === 12, `${cells.length} cells`);
ok('the months run in fiscal order', cells.map(c => c.dataset.month).join('|') ===
  'Apr 26|May 26|Jun 26|Jul 26|Aug 26|Sep 26|Oct 26|Nov 26|Dec 26|Jan 27|Feb 27|Mar 27',
  cells.map(c => c.dataset.month).join('|'));
const cellOf = (m) => cells.find(c => c.dataset.month === m);
const oct = cellOf('Oct 26');
const feb = cellOf('Feb 27');
ok('a project whose date has passed sits in that month, flagged late', Boolean(oct) && oct.classList.contains('late') && /120,000\.00/.test(text(oct)), text(oct));
ok('a project needed later sits in its own month, not flagged', Boolean(feb) && !feb.classList.contains('late') && /160,000\.00/.test(text(feb)), text(feb));
ok('a month with nothing promised shows a dash, not a zero', /—/.test(text(cellOf('Apr 26'))) && !/0\.00/.test(text(cellOf('Apr 26'))), text(cellOf('Apr 26')));
ok('the bars are drawn against the busiest month', /height:100%/.test(feb.querySelector('.due-bar').getAttribute('style')) &&
  /height:75%/.test(oct.querySelector('.due-bar').getAttribute('style')), feb.querySelector('.due-bar').getAttribute('style'));
ok('a month with nothing promised has no bar', /height:0%/.test(cellOf('Apr 26').querySelector('.due-bar').getAttribute('style')));
ok('a late month is drawn in red, a future one in blue', /var\(--c-red\)/.test(oct.querySelector('.due-bar').getAttribute('style')) &&
  /var\(--c-blue\)/.test(feb.querySelector('.due-bar').getAttribute('style')));
ok('closed months are shaded, open ones are not', oct.classList.contains('past') && !feb.classList.contains('past'));
ok('a cell names the project behind it', oct.getAttribute('title') === 'CPX-00004' && feb.getAttribute('title') === 'CPX-00003',
  `${oct.getAttribute('title')} / ${feb.getAttribute('title')}`);
ok('each project is placed in exactly one month, so none is counted twice',
  cells.filter(c => /CPX-0000[34]/.test(c.getAttribute('title') ?? '')).length === 2);
const placed = cells.reduce((t, c) => t + Number(c.dataset.open ?? 0), 0);
ok('the months add up to the money promised', placed === 280000, `GH₵${placed}`);
ok('the strip agrees with the Committed figure above it',
  /280,000\.00/.test(text($('#capitalTable tfoot'))), text($('#capitalTable tfoot')));
const over = $('#capitalOverdue');
ok('an overdue commitment is called out with its amount and project', Boolean(over) && /120,000\.00/.test(text(over)) && /CPX-00004/.test(text(over)), text(over));
ok('the callout says the date has passed and what to do about it', /date has passed/.test(text(over)) && /move the date, or complete the project/.test(text(over)));
ok('with every project dated, the strip claims all of it and no undated notice appears',
  Boolean($('#capitalDated')) && !$('#capitalUndated') && /280,000\.00/.test(text($('#capitalDated'))), text($('#capitalDated')));
ok('the card explains a project sits in one month only', /A project sits in one month only/.test(text(due)));

/* ── can it be paid? ────────────────────────────────────────────────────── */
const fund = $('#capitalFunding');
ok('the plan asks whether the promised money can be paid', Boolean(fund), 'no #capitalFunding');
const fundRows = $$('#fundingTable tbody tr');
ok('one row per funding source', fundRows.length === 3 && fundRows.map(r => r.dataset.source).join('|') === 'reserve|owner|operating',
  fundRows.map(r => r.dataset.source).join('|'));
const reserveRow = $('tr[data-source="reserve"]');
const ownerRow = $('tr[data-source="owner"]');
const cashRow = $('tr[data-source="operating"]');
ok('the reserve row shows what it promised and what it holds', /120,000\.00/.test(text(reserveRow)) && /90,000\.00 accrued/.test(text(reserveRow)), text(reserveRow));
ok('and the cash actually held beside the accrued balance', /60,000\.00 in cash/.test(text(reserveRow)), text(reserveRow));
ok('the reserve states its shortfall in its own column', /30,000\.00/.test(text(reserveRow.querySelectorAll('td')[3])), text(reserveRow));
ok('a reserve row with a shortfall is coloured', reserveRow.classList.contains('text-red'));
ok('an owner-funded promise has no balance to show and says so', /raised as a capital call when it is paid/.test(text(ownerRow)) &&
  /—/.test(text(ownerRow.querySelectorAll('td')[3])), text(ownerRow));
ok('nothing is claimed about a source with nothing promised', /nothing promised from here/.test(text(cashRow)) && /^—$/.test(text(cashRow.querySelectorAll('td')[4])));
ok('each source names the projects drawing on it', /CPX-00004/.test(text(reserveRow)) && /CPX-00003/.test(text(ownerRow)));
ok('the total promised is the commitment total', /280,000\.00/.test(text($('#fundingTable tfoot'))), text($('#fundingTable tfoot')));
ok('the footer says how much comes from a source with no balance', /160,000\.00 comes from a source with no balance to read/.test(text($('#fundingTable tfoot'))));
const shortCall = $('#fundingShort');
ok('an uncovered reserve promise is called out', Boolean(shortCall) && /30,000\.00/.test(text(shortCall)), text(shortCall));
ok('the callout says even the cash does not cover it', /it holds GH₵ 60,000\.00 in cash/i.test(text(shortCall)) || /60,000\.00/.test(text(shortCall)));
ok('and names the three ways out', /Fund the reserve, move the project to a later year, or ask the owner for a capital call/.test(text(shortCall)));
const untracked = $('#fundingUntracked');
ok('money owed by the owner is reported as owed, not as found', Boolean(untracked) && /160,000\.00/.test(text(untracked)) &&
  /the screen says who owes the money, not that it has been found/.test(text(untracked)), text(untracked));
ok('a covered reserve would say so instead of showing a shortfall', !$('#fundingCovered'), 'both callouts rendered');
ok('the Committed stat carries the uncovered part of the promise', /of it has no cover/.test(text($('.stats'))), text($('.stats')).slice(0, 300));
ok('the card says only the reserve is a balance the hotel holds', /only the FF&E reserve is a balance this hotel holds/.test(text(fund)));

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no setting key is printed', !/budget_capex|budget_dual_approval|budget_var_/.test(clean));

if (failures.length) {
  console.error(`capital plan check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`capital plan check: ${passed}/${passed} passed`);
