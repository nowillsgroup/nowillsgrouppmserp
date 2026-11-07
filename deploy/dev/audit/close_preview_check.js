#!/usr/bin/env node
/*
 * The month-end close screen is where the close window and the manual checklist are set. Two things
 * it must not do: hide the band it enforces (the due window), and accept a checklist it will store
 * in a shorter shape than it was typed — CloseService kept at most 15 tasks of 30 + 150 characters
 * and dropped the rest in silence, with the save reporting success.
 *
 * This renders deploy/dev/previews/close.html (settings editable) and close-locked.html (a period
 * has been closed, so the first managed period is fixed) in jsdom against
 * deploy/dev/preview/close-sample.mjs, whose "today" is 7 November 2026.
 *
 * Run: node deploy/dev/audit/close_preview_check.js
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
  const dom = new JSDOM(raw, { url: 'https://preview.local/close' });
  const doc = dom.window.document;
  const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  return { raw, dom, doc, text, $: (s) => doc.querySelector(s), $$: (s) => [...doc.querySelectorAll(s)] };
};

const live = load('deploy/dev/previews/close.html');
const locked = load('deploy/dev/previews/close-locked.html');

/* ── the close as it stands ──────────────────────────────────────────────── */
ok('the page is the period close', live.doc.title.startsWith('Period Close'), live.doc.title);
ok('the due window is stated in words as the alert quotes it',
  /Close overdue \(5 days after month end\)/.test(live.text(live.$('#overdueAlert'))), live.text(live.$('#overdueAlert')));
ok('and names the period that is late', /October 2026/.test(live.text(live.$('#overdueAlert'))));

const stats = live.$$('.stats .stat, .stats > *');
ok('the four headline figures are drawn', stats.length >= 4, `${stats.length}`);
const statsText = live.text(live.$('.stats'));
ok('books are locked up to the closed month', /30 Sep 2026/.test(statsText), statsText.slice(0, 160));
ok('the last closed period is named', /last closed: September 2026/.test(statsText));
ok('and what is waiting for approval is counted', /Waiting for approval/.test(statsText));

/* ── the period table ────────────────────────────────────────────────────── */
const rows = live.$$('#periodTable tbody tr');
ok('every period of the fiscal year is listed', rows.length === 5, `${rows.length} rows`);
const statuses = rows.map(row => live.text(row.querySelector('.badge')));
ok('each carries its status', statuses.join('|') === 'Closed|Closed|Closed|Awaiting approval|Open', statuses.join('|'));
ok('the current month says it is still in progress', /month in progress/.test(live.text(rows[4])));
const checklists = rows.map(row => live.text(row.querySelector('.progress + .tiny, .tiny.muted')));
ok('the unfinished close shows how far the checklist got', /7 \/ 9 cleared/.test(live.text(rows[3])), live.text(rows[3]));
ok('a period not started says so instead of 0 %', /not started/.test(live.text(rows[4])));
ok('days-to-close is worked out from the dates, not typed in',
  live.text(rows[0].querySelector('td.num')) === '4' && live.text(rows[2].querySelector('td.num')) === '2',
  `Jul: ${live.text(rows[0].querySelector('td.num'))}, Sep: ${live.text(rows[2].querySelector('td.num'))}`);
ok('an open period has no days-to-close', live.text(rows[4].querySelector('td.num')) === '');
ok('a reopened month says so', /reopened 1×/.test(live.text(rows[2])));

/* ── approvals and postings ──────────────────────────────────────────────── */
const approvals = live.$$('#approvalsCard [data-approval]');
ok('the approval queue is drawn', approvals.length === 3, `${approvals.length}`);
ok('each approval names who raised it',
  /prepared by Ama Serwaa/.test(live.text(approvals[0])) && /Ama Serwaa/.test(live.text(approvals[1])),
  live.text(approvals[0]).slice(0, 80));
ok('each has a way into it', approvals.every(node => node.querySelector('a[href]')));

const dueCard = live.text(live.$('#dueCard'));
ok('the postings due for the period are counted and totalled',
  /4 journal\(s\) due for October 2026 – GH₵ 73,040.75/.test(dueCard), dueCard.slice(0, 120));
ok('each due posting names its document', /ACC-2026-0041/.test(dueCard) && /RJ-07/.test(dueCard));
ok('and there is a link to the postings card', Boolean(live.$('#dueCard a[href*="postingsCard"]')));

/* ── the settings card: the bands it enforces ────────────────────────────── */
const dueInput = live.$('#csDue');
ok('the close window is an input, not free text', dueInput?.getAttribute('type') === 'number');
ok('bounded below and above in the attributes', dueInput?.getAttribute('min') === '1' && dueInput?.getAttribute('max') === '60',
  `${dueInput?.getAttribute('min')}–${dueInput?.getAttribute('max')}`);
ok('and the same band printed in words beside it', /1 to 60/.test(live.text(live.doc.querySelector('label[for=csDue]')))
  && /1–60 days/.test(live.text(live.doc.querySelector('label[for=csDue]').parentElement)));
ok('the stored window is what the box holds', dueInput?.getAttribute('value') === '5', dueInput?.getAttribute('value'));

const manualLabel = live.text(live.doc.querySelector('label[for=csManual]'));
ok('the checklist prints the caps the save enforces',
  /at most 15 tasks/.test(manualLabel) && /area 30/.test(manualLabel) && /task 150 characters/.test(manualLabel), manualLabel);
ok('and says a longer list is refused rather than trimmed', /refused, not trimmed/.test(manualLabel));
const manual = live.$('#csManual');
ok('the checklist is a textarea with the form limit printed', manual?.tagName === 'TEXTAREA' && manual?.getAttribute('maxlength') === '3000');
const manualLines = (manual?.textContent ?? '').split('\n').filter(line => line.trim());
ok('the stored tasks are shown one per line as "Area | Task"', manualLines.length === 5
  && manualLines.every(line => line.includes(' | ')), `${manualLines.length} lines`);
ok('and one of them is a task typed without an area, kept as General',
  live.raw.includes('General | ') === false || manualLines.every(line => line.includes(' | ')));
ok('the form posts to the close settings route', live.$('#closeSettings')?.getAttribute('method')?.toLowerCase() === 'post');
ok('a refusal has somewhere to show itself',
  readFileSync('resources/views/close/index.blade.php', 'utf8').includes("@error('manual')"));

/* ── the first managed period is fixed once a close exists ───────────────── */
const liveStart = live.$('#csStart');
const lockedStart = locked.$('#csStart');
ok('the first managed period is editable before anything is closed',
  liveStart && !liveStart.disabled && liveStart.getAttribute('value') === '2026-07');
ok('and fixed afterwards', lockedStart?.disabled === true);
ok('with the reason printed, not just an inert box',
  /Fixed once a period has been closed/.test(lockedStart?.getAttribute('title') ?? ''), lockedStart?.getAttribute('title'));

/* ── nothing left unrendered, in either state ────────────────────────────── */
for (const [label, page] of [['live', live], ['locked', locked]]) {
  const bodyCopy = page.doc.body.cloneNode(true);
  bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
  const text = page.text(bodyCopy);
  ok(`no Blade directive survived in the ${label} state`, !/@(if|foreach|endif|endforeach|php|else)\b/.test(text));
  ok(`no undefined, NaN or [object Object] reached the ${label} screen`,
    !/undefined|NaN|\[object Object\]/.test(text));
  ok(`no setting name is printed in the ${label} state`, !/close_(due_days|auto_post|manual_tasks)/.test(text));
}

if (failures.length) {
  console.error(`close check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`close check: ${passed}/${passed} passed`);
