#!/usr/bin/env node
/*
 * Approval thresholds by value — the version screen.
 *
 * Above the value in Budget settings a version needs two approvers, one after the other, from two different people;
 * at or below it one is enough. Four things must hold on this screen:
 *
 *   1. it says which signature is outstanding and who gave the first one, in figures — not "approved by";
 *   2. the button does the right thing: it reads "Second approval", and the dialog says this is the final one;
 *   3. the version is accounted for twice over — the Details panel prints both signatures and the threshold;
 *   4. nothing about it is a lie: no preparer's buttons, no zeros where the grid has totals, no raw action codes.
 *
 * Renders deploy/dev/previews/budget-approval.html in jsdom against
 * deploy/dev/preview/budget-approval-sample.mjs: BUD-2026-27-02, GH₵6,198,000 of income and GH₵4,600,000 of expense against a GH₵5,000,000 threshold,
 * submitted 3 November 2026 and signed once by Kwesi Boateng, seen by the second approver on 7 November.
 *
 * Run: node deploy/dev/audit/budget_approval_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const raw = readFileSync('deploy/dev/previews/budget-approval.html', 'utf8');
const dom = new JSDOM(raw, { url: 'https://preview.local/budgets/3' });
const doc = dom.window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];
const body = text(doc.body);

/* ── the screen ──────────────────────────────────────────────────────────── */
ok('the page is the version', /BUD-2026-27-02/.test(doc.title), doc.title);
ok('the breadcrumb names the version', /BUD-2026-27-02 · Revised after conference season/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the totals are the version\'s own', /GH₵ 6,198,000\.00/.test(text($('.stats'))) && /GH₵ 4,600,000\.00/.test(text($('.stats'))));
ok('the status is awaiting approval', /Awaiting approval/.test(text($('.stats'))) && !/tone-green/.test($('.stats').innerHTML.split('tone-')[1] ?? ''));

/* ── which signature is outstanding, in figures ──────────────────────────── */
const note = $('#approvalNote');
ok('the header note says the version was submitted', Boolean(note) && /Submitted by Yaw Mensah/.test(text(note)), text(note));
ok('…names who gave the first approval and when', /Kwesi Boateng gave the first approval on 05 Nov 2026 15:40/.test(text(note)), text(note));
ok('…prints the size of the version and the threshold it is measured against',
  /This version is GH₵ 6,198,000\.00, over the GH₵ 5,000,000\.00 threshold/.test(text(note)), text(note));
ok('…and says a second approver must sign before it counts', /a second approver must sign before it counts/.test(text(note)), text(note));
ok('four-eyes is stated to apply to both signatures', /four-eyes applies to both signatures/.test(text(note)));
ok('the status card calls a first signature a first signature, not an approval',
  /first approval by Kwesi Boateng · second needed/.test(text($('.stats'))) && !/approved by Kwesi Boateng/.test(text($('.stats'))),
  text($('.stats')));

/* ── the button gives the second approval ────────────────────────────────── */
const approveBtn = $('#approveBtn');
ok('the header offers the second approval', Boolean(approveBtn) && /Second approval/.test(text(approveBtn)), text(approveBtn));
ok('…and it is enabled — the second approver is exactly who should press it', approveBtn && !approveBtn.hasAttribute('disabled'));
ok('the return path stays available to the same approver', Boolean($('#returnBtn')));
ok('a preparer\'s buttons are absent from a submitted version',
  !$('#saveGridBtn') && !$('#importBtn') && !$('#adjustBtn') && !$('#deleteBtn'),
  [$('#saveGridBtn'), $('#importBtn'), $('#adjustBtn'), $('#deleteBtn')].map(n => Boolean(n)).join(','));

const dialog = $('#twoApproverNote');
ok('the dialog says the version needs two approvers', Boolean(dialog) && /needs two approvers/.test(text(dialog)), text(dialog));
ok('…because of its size against the threshold',
  /GH₵ 6,198,000\.00, at or above the GH₵ 5,000,000\.00 threshold/.test(text(dialog)), text(dialog));
ok('…and calls this click the second and final approval', /second and final/.test(text(dialog)), text(dialog));
ok('the confirm button is worded for it', /Give the second approval/.test(text($('#confirmApprove'))), text($('#confirmApprove')));
ok('the dialog still lets the approver make it the active version', Boolean($('#approveForm [name=activate]')));

/* ── both signatures and the threshold are on the record ─────────────────── */
const details = text($('.kv-grid'));
ok('the Details panel names the first approver and marks it the first', /Approved byKwesi Boateng · 05 Nov 2026 \(first\)/.test(details), details.slice(0, 400));
ok('…has a row for the second signature and says it is outstanding', /Second approvalawaiting the second approver/.test(details), details.slice(0, 500));
ok('…prints the threshold and what it means for this version', /Approval thresholdGH₵ 5,000,000\.00 – two approvers/.test(details), details.slice(0, 600));
const history = text($('#budgetEvents'));
ok('the audit trail records the first approval as a first approval, in figures',
  /First approval · Kwesi Boateng 05 Nov 2026 15:40/.test(history), history.slice(0, 200));
ok('…and the note carries the reason a second signature is needed',
  /First approval for GH₵ 6,198,000\.00 – over the GH₵ 5,000,000\.00 threshold, a second approver is required/.test(history), history.slice(0, 300));
ok('an action code is never printed raw', !/approved_first|Approved_first/.test(body));

/* ── the grid is a real grid ─────────────────────────────────────────────── */
const table = $('#budgetGrid');
ok('the monthly grid is rendered', Boolean(table) && table.querySelectorAll('tbody tr').length > 20);
ok('every section prints a column total', $$('#budgetGrid tr.sec-total').length === 5, `${$$('#budgetGrid tr.sec-total').length} section totals`);
ok('the net profit row is totalled, not zero', /Net profit[\s\S]{0,400}1,598,000\.00/.test(text(table)), text($('tr[data-derived=net]')));
ok('gross operating profit is derived and printed', /GOP[\s\S]{0,60}/.test(text(table)) && /1,782,000\.00/.test(text(table)), text($('tr[data-derived=gop]')));
ok('a submitted version prints figures, not inputs', table.querySelectorAll('input.bg-cell').length === 0, `${table.querySelectorAll('input.bg-cell').length} editable cells`);

// The derived rows must reconcile with the section totals printed above them — that identity is why the columns are
// computed in BudgetService::gridTotals() rather than added up in the template.
const num = (node) => Number(text(node).replace(/[^\d.-]/g, ''));
const sectionTotal = (sec) => num($(`tr[data-sectotal="${sec}"] td[data-m="t"]`));
const netTotal = num($('tr[data-derived="net"] td[data-m="t"]'));
const gopTotal = num($('tr[data-derived="gop"] td[data-m="t"]'));
ok('net profit is income less every expense section, to the cent',
  Math.abs((sectionTotal('revenue') + sectionTotal('other_income') - sectionTotal('direct') - sectionTotal('opex') - sectionTotal('nonop')) - netTotal) < 0.01,
  `${netTotal} vs sections`);
ok('gross operating profit is revenue less cost of sales and operating expenses',
  Math.abs((sectionTotal('revenue') - sectionTotal('direct') - sectionTotal('opex')) - gopTotal) < 0.01, `${gopTotal} vs sections`);
ok('the net row agrees with the header total', Math.abs(netTotal - 1598000) < 0.01, String(netTotal));

/* ── nothing leaked ──────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('no Blade directive survived', !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp)\b/.test(clean));
ok('no undefined, NaN or [object Object] reached the screen', !/undefined|NaN|\[object Object\]/.test(clean));
ok('no raw placeholder was printed', !/\{\{|\}\}/.test(clean));
ok('no setting key is printed', !/budget_dual_approval_above|dual_approval_above/.test(clean));
ok('every modal has a form behind it', $$('.modal').every(m => m.querySelector('form')), $$('.modal').map(m => m.id).join(','));

if (failures.length) {
  console.error(`budget approval check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`budget approval check: ${passed}/${passed} passed`);
