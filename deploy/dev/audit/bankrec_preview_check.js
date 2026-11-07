#!/usr/bin/env node
/*
 * Bank reconciliation. The settings card promises auto-matching within ±0–31 days of the statement
 * line; the reference pass used to ignore that window completely, and its candidate pool ran back to
 * the account's first statement, so a line could be paired with a book entry a year earlier whose
 * reference merely repeated. Both now work to the window, and the screen says what the window bounds.
 *
 * The reconciliation screen also has to explain itself: every reason it cannot be finalised is
 * listed, including segregation, and every matched line says which pass matched it.
 *
 * Renders deploy/dev/previews/bankrec.html and bankrec-show.html in jsdom against
 * deploy/dev/preview/bankrec-sample.mjs.
 *
 * Run: node deploy/dev/audit/bankrec_preview_check.js
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
  const dom = new JSDOM(raw, { url: 'https://preview.local/accounts/bank-reconciliation' });
  const doc = dom.window.document;
  const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  return { raw, dom, doc, text, $: (s) => doc.querySelector(s), $$: (s) => [...doc.querySelectorAll(s)] };
};

const index = load('deploy/dev/previews/bankrec.html');
const show = load('deploy/dev/previews/bankrec-show.html');

/* ── the statements ──────────────────────────────────────────────────────── */
ok('the index is the reconciliation workspace', /^Bank Reconciliation/.test(index.doc.title), index.doc.title);
const rows = index.$$('#statementTable tbody tr');
ok('every statement is listed', rows.length === 3, `${rows.length}`);
ok('each says how many lines are matched', /4 \/ 7 matched/.test(index.text(rows[0])), index.text(rows[0]));
ok('and how many are still open', /2 open/.test(index.text(rows[0])));
ok('a reconciled statement carries its date and who did it',
  /04 Oct 2026/.test(index.text(rows[1])) && /Kwesi Boateng/.test(index.text(rows[1])));
ok('a statement whose book entry changed after reconciliation is flagged',
  /review/.test(index.text(rows[2])), index.text(rows[2]));

/* ── the settings card ───────────────────────────────────────────────────── */
const setting = (name) => index.$(`#recSettings [name=${name}]`);
const labelOf = (name) => index.text(index.doc.querySelector(`label[for=${setting(name)?.id}]`));

const win = setting('bankrec_window_days');
ok('the auto-match window is bounded below at zero', win?.getAttribute('min') === '0');
ok('and above at 31 days', win?.getAttribute('max') === '31', win?.getAttribute('max'));
ok('with the band in words beside it', /0 to 31/.test(labelOf('bankrec_window_days')), labelOf('bankrec_window_days'));
ok('and the screen says what the window bounds',
  /Bounds every automatic pairing/.test(index.text(win?.parentElement)), index.text(win?.parentElement));
ok('naming the three passes it governs',
  /by reference, by amount and by settlement batch/.test(index.text(win?.parentElement)));
ok('and what zero means', /0 = the same day only/.test(index.text(win?.parentElement)));

const stale = setting('bankrec_stale_days');
ok('overdue is bounded 7 to 365', stale?.getAttribute('min') === '7' && stale?.getAttribute('max') === '365',
  `${stale?.getAttribute('min')}–${stale?.getAttribute('max')}`);
ok('and printed as a band', /7 to 365/.test(labelOf('bankrec_stale_days')));
ok('the stored values are what the boxes hold',
  win?.getAttribute('value') === '5' && stale?.getAttribute('value') === '35');
ok('segregation is a switch that reads as on', setting('bankrec_segregation')?.checked === true);
ok('the card explains that statements follow on from each other',
  /each starting the day after the previous one ends/.test(index.text(index.doc.body)));

/* ── the reconciliation screen ───────────────────────────────────────────── */
ok('the statement page names the statement and its account',
  /BRS-2026-10-1021/.test(show.text(show.doc.body)) && /Ecobank – current/.test(show.text(show.doc.body)));
ok('its status is shown', /Draft/.test(show.text(show.doc.body)));

const stats = show.text(show.$('.stats'));
ok('the statement balances agree', /GH₵ 1,284,530\.22/.test(stats), stats.slice(0, 200));
ok('matched lines are counted against the total', /4 \/ 7/.test(stats) && /2 to match/.test(stats) && /1 ignored/.test(stats));
ok('the unexplained difference is stated', /GH₵ -6,850\.00/.test(stats));

const blockers = show.text(show.$('#recBlockers'));
ok('everything blocking the finalise is listed, not just the first',
  /2 statement line\(s\) still to match/.test(blockers) && /Unexplained difference/.test(blockers));
ok('including the segregation rule that the setting switched on',
  /You loaded this statement – someone else must finalise it/.test(blockers), blockers);
const finalise = show.$('#finaliseBtn');
ok('the finalise button is disabled while it is blocked', finalise?.hasAttribute('disabled'));
ok('and carries the same reason in its title',
  /someone else must finalise it/.test(finalise?.getAttribute('title') ?? ''), finalise?.getAttribute('title'));

/* ── the lines, and which pass matched them ──────────────────────────────── */
const lines = show.$$('#lineTable tbody tr');
ok('every statement line is on the page', lines.length === 7, `${lines.length}`);
const lineText = show.text(show.$('#lineTable'));
for (const [type, label] of [['reference', 'auto · reference'], ['amount', 'auto · amount'], ['group', 'auto · batch'], ['manual', 'by hand']]) {
  ok(`a line matched by ${type} says so`, lineText.includes(label), label);
}
ok('a matched line shows the book entry it was matched to', /GL-2026-10-0021/.test(lineText));
ok('an ignored line says why', /bank error – reversed by the bank on 2 Nov/.test(lineText));
ok('a line with nothing in the books says so', /Nothing in the books for this amount in October/.test(lineText));
ok('the footer totals the lines both ways',
  /7 line\(s\)/.test(show.text(show.$('#lineTable tfoot'))), show.text(show.$('#lineTable tfoot')));

/* ── what is still open in the books ─────────────────────────────────────── */
const open = show.$$('#openTable tbody tr');
ok('the book entries not yet cleared are listed', open.length === 3, `${open.length}`);
ok('one dated after the statement is greyed out so it cannot be picked by mistake',
  open.filter(row => row.className.includes('muted')).length === 1);
ok('and the screen says so in words', /Greyed entries are dated after the statement/.test(show.text(show.doc.body)));

/* ── the reconciling statement ───────────────────────────────────────────── */
const brs = show.text(show.$('#brsTable'));
ok('the reconciliation starts from the statement balance', /Balance per statement/.test(brs) && /1,284,530\.22/.test(brs));
ok('adds deposits not yet on the statement', /deposits not yet on the statement/.test(brs) && /20,900\.00/.test(brs));
ok('lesses payments not yet presented', /payments not yet on the statement/.test(brs) && /3,450\.00/.test(brs));
ok('adjusts for statement items not in the books', /statement items not in the books/.test(brs));
ok('arrives at the book balance', /Balance per books/.test(brs) && /1,277,680\.22/.test(brs));
ok('and prints the difference as the plug', /-6,850\.00/.test(show.text(show.$('#brsDiff'))), show.text(show.$('#brsDiff')));

/* ── nothing left unrendered ─────────────────────────────────────────────── */
for (const [label, page] of [['index', index], ['statement', show]]) {
  const bodyCopy = page.doc.body.cloneNode(true);
  bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
  const text = page.text(bodyCopy);
  ok(`no Blade directive survived on the ${label} screen`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php)\b/.test(text));
  ok(`no undefined, NaN or [object Object] reached the ${label} screen`,
    !/undefined|NaN|\[object Object\]/.test(text));
  ok(`no setting key is printed on the ${label} screen`, !/bankrec_(window|stale)_days/.test(text));
}

if (failures.length) {
  console.error(`bankrec check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`bankrec check: ${passed}/${passed} passed`);
