#!/usr/bin/env node
/*
 * The award screen, read the way the buyer reading it has to.
 *
 * The comparative statement already ranks the bids on landed price line by line — the only fair way to compare them, and
 * the reason the table exists. v9.28 adds the axes the price table cannot show, because a bid that cannot be delivered is
 * not cheap:
 *
 *   1. the request in five figures — 3 of 3 suppliers quoted, 5,639.60 at the lowest rate offered line by line, 5,122.00
 *      at the rates last paid (a 218.00 saving), 2 bids that cannot be taken, and 3 days left for the goods to arrive;
 *   2. the comparative statement itself, unchanged and checked: five lines, three columns, the lowest cell on each line
 *      marked, landed = rate + 15 %, and a foot carrying the three totals, the items quoted, delivery, terms, validity
 *      and the twelve-month score;
 *   3. what each supplier is actually promising — Coastal lands 14 November, four days after the store needs it, at the
 *      lowest price; Kaneshie is two days and on time but its price expired on 31 October; Accra is 141.45 dearer and
 *      lands on the date;
 *   4. the verdict that follows: the cheapest bid named, why it cannot be taken, and the bid that can.
 *
 * Renders deploy/dev/previews/rfq-show.html in jsdom against deploy/dev/preview/rfq-show-sample.mjs.
 *
 * Run: node deploy/dev/audit/rfq_show_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file) => new JSDOM(readFileSync(file, 'utf8'), { url: 'https://preview.local/rfqs/55' }).window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const num = (node) => Number((((text(node).match(/[−-]?[\d,]+\.?\d*/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/rfq-show.html');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the request in five figures ────────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures', head.length === 5, `${head.length}`);
ok('the quotes received are counted against the invitations',
  /^3/.test(head[0]) && /3 of 3 invited suppliers quoted/.test(head[0]), head[0]);
ok('the cheapest of them is money, and named',
  /^GH₵ 5,639\.60/.test(head[1]) && /Coastal Beverages Ltd/.test(head[1])
  && /the whole request at the lowest rate offered line by line/.test(head[1]), head[1]);
ok('what the same lines cost last time is stated with the saving beside it',
  /^GH₵ 5,122\.00/.test(head[2]) && /the cheapest bid saves GH₵ 218\.00/.test(head[2]), head[2]);
ok('the bids that cannot be taken are counted and explained in red',
  /^2/.test(head[3]) && /1 arrives after the store needs it · 1 quote expired before the award/.test(head[3])
  && $$('.stats.wide')[0].children[3].classList.contains('tone-red'), head[3]);
ok('and the date the goods are needed by is on the header, with the days left',
  /^10 Nov 2026/.test(head[4]) && /3 days left for the goods to arrive/.test(head[4]), head[4]);

/* ── the comparative statement, which this round must not have broken ───── */
ok('the statement is still on the page and still ranked on landed price',
  /Landed rate = rate incl\. tax/.test(text($('#comparative'))));
ok('its headline total is the lowest bid line by line', /total at L1 GH₵ 5,639\.60/.test(text($('#comparative'))),
  text($('#comparative')).slice(0, 120));
const cols = [...$$('#comparative thead th')].map(th => text(th));
ok('the three suppliers are the three columns, in price order',
  cols.join('|') === 'Item|Qty|Last rate|Coastal Beverages Ltd|Accra Fresh Foods|Kaneshie Wholesale Ltd|Award / justification', cols.join('|'));
const cmpRows = [...$$('#comparative tbody tr')].filter(r => r.cells.length > 3).map(r => ({
  item: text(r.children[0]), qty: num(r.children[1]), last: num(r.children[2]),
  cells: [3, 4, 5].map(i => ({ text: text(r.children[i]), low: r.children[i].classList.contains('cmp-low') })),
}));
ok('all five lines are compared', cmpRows.length === 5, `${cmpRows.length}`);
ok('the lowest landed rate on each line is the one marked', (() => {
  const lowest = ['48.30', '43.70', '24.15', '40.83', '40.83'];

  return cmpRows.every((r, i) => r.cells[0].low && r.cells[0].text.includes(lowest[i]))
    && cmpRows.every(r => r.cells.filter(c => c.low).length === 1);
})(), JSON.stringify(cmpRows.map(r => r.cells.map(c => [c.text, c.low]))));
ok('every cell shows the rate, its tax and the landed rate beside it',
  cmpRows.every(r => r.cells.every(c => /15% → [\d.]+/.test(c.text))));
ok('the landed rate is the rate plus fifteen per cent, to the pesewa',
  cmpRows[0].cells.map(c => Number((c.text.match(/→ ([\d.]+)/) ?? [])[1])).join(',') === '48.3,49.45,51.75',
  cmpRows[0].cells.map(c => c.text).join(' | '));
ok('the rate last paid is on every line, so the comparison has a baseline',
  cmpRows.map(r => r.last).join(',') === '44,40,22,36.5,36.5', cmpRows.map(r => r.last).join(','));
const footRows = [...$$('#comparative tfoot tr')].map(r => text(r));
ok('the foot carries the three quote totals',
  /Quote total \(incl\. tax\)\s*5,639\.60\s*5,781\.05\s*6,065\.10/.test(footRows[0]), footRows[0]);
ok('and what each supplier actually quoted for', /Items quoted\s*5 \/ 5\s*5 \/ 5\s*5 \/ 5/.test(footRows[1]), footRows[1]);
ok('and the promise, in the same columns: delivery, terms, validity',
  /Delivery \(days\)\s*7\s*3\s*2/.test(footRows[2]) && /Payment terms \(days\)\s*30\s*14\s*45/.test(footRows[3])
  && /Valid until\s*20 Nov 2026\s*12 Nov 2026\s*31 Oct 2026/.test(footRows[4]), footRows.slice(2, 5).join(' /// '));
ok('and the twelve-month score, so price is not the only thing on the page',
  /Supplier score \(12 months\)\s*A 4\.6\s*B 3\.9\s*C 3\.1/.test(footRows[5]), footRows[5]);
ok('the award radios are still there: three bids and a "do not award" on each of five lines',
  $$('#comparative input[name^="award["]').length === 20, `${$$('#comparative input[name^="award["]').length}`);
ok('and the lowest bid is the one pre-selected on every line', (() => {
  const checked = $$('#comparative input[type="radio"]:checked');
  const byName = {};
  for (const radio of checked) byName[radio.name] = (byName[radio.name] ?? 0) + 1;

  return checked.length === 5 && Object.keys(byName).length === 5 && checked.every(r => r.value !== '');
})(), $$('#comparative input[type="radio"]:checked').length);

/* ── what each supplier is promising ────────────────────────────────────── */
const promise = $('#rfqPromise');
ok('the promise card is on the page and says what it is', /What each supplier is promising/.test(text(promise)));
ok('and flags that not every bid can be taken',
  /not every bid can be taken/.test(text(promise.querySelector('.badge'))), text(promise.querySelector('.badge')));
const pCols = [...$$('#promiseTable thead th')].map(th => text(th));
ok('it reads supplier, quote, lines, delivery, arrival, terms, validity and score',
  pCols.join('|') === 'Supplier|Quote|Lines|Delivery|Arrives|Terms|Valid until|Score', pCols.join('|'));
const rows = [...$$('#promiseTable tbody tr')].map(r => ({ supplier: text(r.children[0]), quote: num(r.children[1]),
  lines: text(r.children[2]), delivery: text(r.children[3]), arrives: text(r.children[4]), terms: text(r.children[5]),
  valid: text(r.children[6]), score: text(r.children[7]), late: r.dataset.late === 'yes', expired: r.dataset.expired === 'yes' }));
ok('all three suppliers are read on both axes', rows.length === 3
  && rows.map(r => r.supplier).join(',') === 'Coastal Beverages Ltd,Accra Fresh Foods,Kaneshie Wholesale Ltd');
ok('the cheapest bid is the one that arrives late, and it says by how many days',
  rows[0].late && /14 Nov 2026 4 days late/.test(rows[0].arrives) && rows[0].delivery === '7 days', rows[0].arrives);
ok('the second lands on the day the store needs it, and is not marked late',
  !rows[1].late && /^10 Nov 2026/.test(rows[1].arrives) && rows[1].delivery === '3 days', rows[1].arrives);
ok('the third is the earliest of all and still cannot be taken, because its price ran out',
  !rows[2].late && /^09 Nov 2026/.test(rows[2].arrives) && rows[2].expired && /31 Oct 2026 expired/.test(rows[2].valid), rows[2].valid);
ok('each row carries the payment terms the supplier is asking for',
  rows.map(r => r.terms).join(',') === '30 days,14 days,45 days', rows.map(r => r.terms).join(','));
ok('each row carries the twelve-month score, so a cheap untested supplier is visible as one',
  rows.map(r => r.score).join('|') === 'A 4.6|B 3.9|C 3.1', rows.map(r => r.score).join('|'));
ok('and every one of them quoted all five lines', rows.every(r => r.lines === '5 / 5'));
ok('the verdict names the cheapest bid, why it cannot be taken, and the one that can',
  /The cheapest bid is Coastal Beverages Ltd at GH₵ 5,639\.60, but it lands 4 days after the store needs it\. Accra Fresh Foods is GH₵ 141\.45 dearer and can be delivered on time\./.test(text($('#rfqPromiseState'))),
  text($('#rfqPromiseState')));
ok('the card explains that delivery is counted from today, not from the day of the quote',
  /Delivery is counted from today, 07 Nov 2026, not from the date the quote was given/.test(text(promise)));
ok('and says what an expired quote is: an opening for a phone call, not a price',
  /A quote whose validity has run out is not a cheap quote any more — it is a starting point for a phone call/.test(text(promise)));

/* ── the rest of the screen still works ─────────────────────────────────── */
const invites = [...$$('#rfqInvites tbody tr')].map(r => ({ supplier: text(r.children[0]), state: text(r.children[1]),
  quote: num(r.children[3]) }));
ok('the invited suppliers are still listed with what each quoted', invites.length === 3
  && invites.map(i => i.quote).join(',') === '5639.6,5781.05,6065.1', invites.map(i => i.quote).join(','));
ok('every one of them is marked as having quoted', invites.every(i => /Quoted/.test(i.state)));
ok('the award controls are still there, with the minimum-quote check satisfied',
  $('#awardBtn') !== null && $('#fewQuotes') === null && $$('#awardForm input[name="note"]').length === 1);
ok('the terms sent to suppliers are quoted verbatim', /goods to arrive on or before 10 November 2026/.test(text(doc.body)));
ok('the page places the award in the chain it decides',
  /The comparative statement decides who raises the purchase order/.test(text($('#rfqChain')))
  && /the goods do not arrive/.test(text($('#rfqChain'))), text($('#rfqChain')));

/* ── nothing leaked ─────────────────────────────────────────────────────── */
const copy = doc.body.cloneNode(true);
copy.querySelectorAll('script, style').forEach(node => node.remove());
const clean = text(copy);
ok('the page holds no Blade directive', !/@(if|elseif|else|endif|foreach|endforeach|php|endphp|unless|selected|checked|error|enderror|push|endpush)\b/.test(clean));
ok('the page holds no undefined, NaN or [object Object]', !/undefined|NaN|\[object Object\]/.test(clean));
ok('the page holds no raw placeholder or leaked attribute', !/\{\{|\}\}|:sub=|:tone=/.test(clean));
ok('the page holds no unpluralised shorthand', !/\(s\)|\(ies\)|quotation\(s\)|day\(s\)/.test(clean));
ok('no figure is printed with a bare decimal', !/GH₵ [\d,]+\.\d(?!\d)/.test(clean));
ok('the landed column is not printed to four places', !/→ \d+\.\d{3,}/.test(clean));

if (failures.length) {
  console.error(`RFQ award check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`RFQ award check: ${passed}/${passed} passed`);
