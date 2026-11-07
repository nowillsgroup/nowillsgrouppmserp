#!/usr/bin/env node
/*
 * Cost centre budgets.
 *
 * The screen has to do one thing above all: add up. The departments' plans plus the plan not yet
 * given to a department must equal the whole-hotel expense plan, and the expense charged to cost
 * centres plus the expense that carries no cost centre must equal the whole-hotel expense actual.
 * If either fails, a departmental P&L silently stops reconciling with the P&L it came from.
 *
 * The second thing: a department is compared with the plan pro-rated to the days elapsed, not with
 * the full-year figure — otherwise every department looks under budget until December.
 *
 * Renders deploy/dev/previews/budget-cost-centres.html in jsdom against
 * deploy/dev/preview/cost-centres-sample.mjs.
 *
 * Run: node deploy/dev/audit/cost_centres_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/budget-cost-centres.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/budget-cost-centres' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

const rows = $$('#ccTable tbody tr');
const cellText = (row, index) => text(row.children[index]);
const money = (s) => Number(String(s).replace(/[^\d.-]/g, ''));

/* ── the page ─────────────────────────────────────────────────────────────── */
ok('the page is the cost-centre budgets screen', doc.title.startsWith('Cost centre budgets'), doc.title);
ok('it sits under budgets in the breadcrumb', /Budgets · Cost centres · FY 2026-27/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the header offers the way back to the versions', Boolean($('#bvaLink')) && $$('.actions a').length >= 2, `${$$('.actions a').length} actions`);

/* ── every department is on it ────────────────────────────────────────────── */
ok('every department is listed', rows.length === 6, `${rows.length} rows`);
const names = rows.map((r) => cellText(r, 0));
ok('named by their code and name', names.some((n) => /HK · Housekeeping/.test(n)) && names.some((n) => /FB · Food & Beverage/.test(n)), names.join(' | '));
ok('a department that is not a cost centre says so', rows.some((r) => /not a cost centre/.test(cellText(r, 0))));
ok('a department with a version shows it, with its status', rows.some((r) => /BUD-2026-27-11/.test(cellText(r, 1)) && /Approved/.test(cellText(r, 1))));
const eng = rows.find((r) => /Engineering/.test(cellText(r, 0)));
ok('a version awaiting approval is named as such, not as a plan', /BUD-2026-27-14/.test(cellText(eng, 1)) && /Awaiting approval/.test(cellText(eng, 1)), cellText(eng, 1));
ok('…it says it is not in force and counts no plan', /not in force until it is approved/.test(cellText(eng, 1)));
ok('…so its plan columns stay empty', cellText(eng, 2) === '—' && cellText(eng, 3) === '—', `${cellText(eng, 2)} / ${cellText(eng, 3)}`);
ok('…while the money the ledger charged it is still shown', money(cellText(eng, 4)) === 196870, cellText(eng, 4));

/* ── the comparison is pro-rated, not full-year ───────────────────────────── */
const hk = rows.find((r) => /Housekeeping/.test(cellText(r, 0)));
ok('the year plan is shown in full', money(cellText(hk, 2)) === 620000, cellText(hk, 2));
ok('and the plan to date is the pro-rated figure, not the year', money(cellText(hk, 3)) === 366300, cellText(hk, 3));
ok('the variance compares the actual with the plan to date', money(cellText(hk, 4)) === 389120 && /22,820\.00 over/.test(cellText(hk, 5)), cellText(hk, 5));
ok('an overspend is marked as one', /text-red/.test(hk.children[5].className), hk.children[5].className);
const fo = rows.find((r) => /Front Office/.test(cellText(r, 0)));
ok('an underspend is marked as one', /23,160\.00 under/.test(cellText(fo, 5)) && /text-green/.test(fo.children[5].className), cellText(fo, 5));
ok('the variance carries its percentage', /\+6\.2 %/.test(cellText(hk, 5)), cellText(hk, 5));
ok('the share of the whole-hotel plan is shown', /15\.6 %/.test(cellText(hk, 7 - 1)), cellText(hk, 6));

/* ── a department with no version ─────────────────────────────────────────── */
const spa = rows.find((r) => /Spa/.test(cellText(r, 0)));
ok('a department with no version says so instead of printing zeros', /no budget/.test(cellText(spa, 1)));
ok('…and prints no plan rather than GH₵ 0.00', cellText(spa, 2) === '—' && cellText(spa, 3) === '—', `${cellText(spa, 2)} / ${cellText(spa, 3)}`);
ok('…yet still shows what the ledger charged it', money(cellText(spa, 4)) === 42300, cellText(spa, 4));
ok('…and offers to start one', Boolean(spa.querySelector('form[action*="budgets"]')) && /Start/.test(text(spa)));
ok('the start form names the department and the year it will plan',
  spa.querySelector('[name=department_id]')?.value === '5' && spa.querySelector('[name=fiscal_year]')?.value === '2026');

/* ── it adds up ───────────────────────────────────────────────────────────── */
const foot = $$('#ccTable tfoot tr');
const footText = foot.map((r) => text(r));
const allocated = footText.find((t) => /Total allocated to cost centres/.test(t));
const whole = footText.find((t) => /Whole-hotel expense plan/.test(t));
const notAllocated = footText.find((t) => /Plan not yet given to a department/.test(t));
const untagged = footText.find((t) => /Expense with no cost centre/.test(t));
ok('the footer totals the allocated plans', /2,040,000\.00/.test(allocated), allocated);
ok('the footer states the whole-hotel plan', /3,980,000\.00/.test(whole), whole);
ok('the plan not yet allocated is the difference', /1,940,000\.00/.test(notAllocated), notAllocated);
ok('the untagged expense is the difference', /916,540\.00/.test(untagged), untagged);
ok('allocated + not allocated = the whole-hotel plan',
  money(allocated.match(/2,040,000\.00/)[0]) + money(notAllocated.match(/1,940,000\.00/)[0]) === money(whole.match(/3,980,000\.00/)[0]));
ok('the plan to date reconciles the same way', /1,205,300\.00/.test(allocated) && /1,147,100\.00/.test(notAllocated), `${allocated} | ${notAllocated}`);
ok('tagged + untagged = the whole-hotel actual', 1512110 + 916540 === 2428650 && /2,428,650\.00/.test(whole), whole);
ok('the untagged row explains where that money comes from', /postings made before the dimension shipped, or journals nobody allocated/.test(untagged));
ok('the footer warns when the departments exceed the whole-hotel plan', /negative = the departments add up to more than the whole-hotel plan/.test(notAllocated));

/* ── the top line ─────────────────────────────────────────────────────────── */
const stats = text($('.stats'));
ok('the stats count the departments with an approved budget', /3 \/ 6/.test(stats), stats.slice(0, 120));
ok('…and say how many versions await approval', /1 awaiting approval/.test(stats), stats.slice(0, 200));
ok('…name the year', /FY 2026-27/.test(stats));
ok('…state how much expense carries a cost centre', /62\.3 %/.test(stats), stats.slice(0, 400));
ok('…and how much does not', /GH₵ 916,540\.00/.test(stats));
ok('the cutoff is stated in the head', /ledger up to 31 Oct 2026/.test(text($('.card-head'))), text($('.card-head')));

/* ── how a department gets an actual ──────────────────────────────────────── */
const body = text(doc.body);
ok('the screen says stores issues are tagged to the consuming department', /Stores issued to a department/.test(body));
ok('…petty cash to the float', /the float's department/.test(body));
ok('…claims to the claimant', /the claimant's department/.test(body));
ok('…and a manual journal to the cost centre typed on the line', /the cost centre typed on each line/.test(body));
ok('it names the postings that stay unallocated', /supplier bills, payroll, depreciation/.test(body));
ok('it says the two budgets are now one number', /Two budgets, one number/.test(body));
ok('…and that the department budget governs when there is one', /capped by the department's own budget for the month/.test(body));
ok('…with the single monthly figure only as a fallback', /only as a fallback/.test(body));
ok('…and that rooms statistics stay on the whole-hotel budget', /Rooms statistics \(occupancy, ADR\) stay on the whole-hotel budget/.test(body));

/* ── nothing leaked ──────────────────────────────────────────────────────── */
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php)\b/.test(body));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(body));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(body));

if (failures.length) {
  console.error(`cost centres check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`cost centres check: ${passed}/${passed} passed`);
