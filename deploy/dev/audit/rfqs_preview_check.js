#!/usr/bin/env node
/*
 * The RFQ register, read for the three things a buyer actually asks.
 *
 * A request for quotation is the only document in the chain where the price is argued for rather than given, and this
 * register could say how many requests were sent and how many quotes came back. It could not say:
 *
 *   1. whether anybody answered — nine of thirteen invited suppliers quoted (69.2 %), one declined for a reason worth
 *      recording, two opened the request and never replied, two never opened it. A count of "9 quotes" cannot tell a
 *      buyer which of the four to chase;
 *   2. whether a deadline had run out with nobody deciding — three requests wait on an award, the oldest eight days past
 *      its deadline, and one of them holds two quotes where this property asks for three;
 *   3. what the competition bought — three awards placed 21,460.00, which is 2,650.00 under the dearest bid and 350.00
 *      over the cheapest, on three lines that went to a supplier who was not the lowest bidder and all three carry a
 *      written justification.
 *
 * The drift build is the month the competition did not happen: the same requests, the same quotes, and every award taken
 * at the dearest bid with no justification — 24,110.00 placed, ten lines away from the lowest, 3,000.00 over the
 * cheapest, and the award reading red.
 *
 * Renders deploy/dev/previews/rfqs.html and rfqs-drift.html in jsdom against deploy/dev/preview/rfqs-sample.mjs.
 *
 * Run: node deploy/dev/audit/rfqs_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file) => new JSDOM(readFileSync(file, 'utf8'), { url: 'https://preview.local/rfqs' }).window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const num = (node) => Number((((text(node).match(/[−-]?[\d,]+\.?\d*/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const round2 = (v) => Math.round(v * 100) / 100;

const doc = load('deploy/dev/previews/rfqs.html');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the register in five figures ───────────────────────────────────────── */
const head = [...$$('.stats.wide')[0].children].map(s => text(s));
ok('the header carries five figures', head.length === 5, `${head.length}`);
ok('the requests still asking for a price are counted', /^4/.test(head[0]) && /13 suppliers invited across them/.test(head[0]), head[0]);
ok('and the quotes that came back are counted against the invitations',
  /^9/.test(head[1]) && /9 of 13 invited suppliers answered — 69\.2%/.test(head[1]), head[1]);
ok('the deadlines that ran out with no award are on the header, in amber',
  /^3/.test(head[2]) && /3 requests waiting on an award, the oldest 8 days/.test(head[2])
  && $$('.stats.wide')[0].children[2].classList.contains('tone-amber'), head[2]);
ok('the request short of the minimum is counted, in red',
  /^1/.test(head[3]) && /fewer quotes than the 3 this property asks for/.test(head[3])
  && $$('.stats.wide')[0].children[3].classList.contains('tone-red'), head[3]);
ok('and what the competition saved is money, not a count',
  /^GH₵ 2,650\.00/.test(head[4]) && /under the dearest bid, on GH₵ 21,460\.00 placed/.test(head[4]), head[4]);

/* ── what came back ─────────────────────────────────────────────────────── */
const resp = $('#rfqResponse');
ok('the response card is asked as a question about who answered', /What came back/.test(text(resp)));
ok('its head counts quotes, refusals and silence',
  /9 quotes · 1 declined · 3 never answered/.test(text(resp.querySelector('.card-head'))), text(resp.querySelector('.card-head')));
const rStats = [...resp.querySelectorAll('.stat')].map(s => text(s));
ok('invited, answered, declined and opened-silent are the four readings',
  /Invited/.test(rStats[0]) && /^13/.test(rStats[0]) && /Answered/.test(rStats[1]) && /^9/.test(rStats[1])
  && /Declined/.test(rStats[2]) && /^1/.test(rStats[2]) && /Opened, silent/.test(rStats[3]) && /^2/.test(rStats[3]),
  rStats.join(' || '));
ok('a refusal is treated as an answer, which is the distinction the card is making',
  /the supplier said no — that is an answer/.test(rStats[2]), rStats[2]);
ok('and silence is what the buyer has to act on', /2 read the request and never quoted — worth a phone call/.test(rStats[3]), rStats[3]);
const silent = [...$$('#silentTable tbody tr')].map(r => ({ rfq: text(r.children[0]), supplier: text(r.children[1]),
  opened: text(r.children[2]), due: text(r.children[3]), link: r.querySelector('a')?.getAttribute('href') ?? '' }));
ok('the suppliers who read the request and said nothing are listed by name',
  silent.length === 2 && silent.map(s => s.supplier).join(',') === 'Sahel Textiles,Ecolab Ghana',
  silent.map(s => s.supplier).join(','));
ok('with the moment they opened it, so the chase can be timed',
  /03 Nov 2026 10:20/.test(silent[0].opened) && /04 Nov 2026 08:30/.test(silent[1].opened), silent.map(s => s.opened).join(' | '));
ok('and the deadline of the request they are sitting on', silent.every(s => /Nov 2026/.test(s.due)));
ok('each silent supplier leads to the request it was invited to', silent.every(s => /rfq-show/.test(s.link)));
ok('the one refusal is quoted with its reason', /Tema Hardware declined RFQ-2026-0061: out of stock until January\./.test(text($('#rfqDeclined'))),
  text($('#rfqDeclined')));

/* ── the clock ──────────────────────────────────────────────────────────── */
const clock = $('#rfqClock');
ok('the clock card is a queue, not a count', /Waiting on an award decision/.test(text(clock)));
ok('its head says how long the oldest has been waiting',
  /3 requests · oldest 8 days past its deadline/.test(text(clock.querySelector('.card-head'))), text(clock.querySelector('.card-head')));
const cStats = [...clock.querySelectorAll('.stat')].map(s => text(s));
ok('waiting, oldest, below the minimum and closing are the four readings',
  /Waiting/.test(cStats[0]) && /Oldest/.test(cStats[1]) && /Below the minimum/.test(cStats[2]) && /Closing/.test(cStats[3]), cStats.join(' || '));
ok('the oldest is named with its title and the day quotes were due',
  /RFQ-2026-0055 — Beverage restock — December · quotes were due 30 Oct 2026/.test(cStats[1]), cStats[1]);
ok('the minimum is stated as the property’s own rule, not a number the page invented',
  /this property asks for 3 quotes before an award — the award can be made, but it has to be justified/.test(cStats[2]), cStats[2]);
const queue = [...$$('#clockTable tbody tr')].map(r => ({ rfq: text(r.children[0]), title: text(r.children[1]),
  quotes: num(r.children[2]), due: text(r.children[3]), days: num(r.children[4]), short: r.dataset.short === 'yes' }));
ok('the queue is ordered by how long each has been waiting', queue.map(r => r.days).join(',') === '8,3,1', queue.map(r => r.days).join(','));
ok('the request holding two quotes of a minimum of three is marked short',
  queue[2].short && queue.filter(r => r.short).length === 1 && queue[2].quotes === 2, JSON.stringify(queue.map(r => [r.rfq, r.short])));
ok('a request waiting more than five days is marked in red',
  clock.querySelector('#clockTable tbody tr:first-child .text-red') !== null);
ok('every row leads to the screen where the award is made',
  [...$$('#clockTable tbody tr')].every(r => /rfq-show/.test(r.querySelector('a')?.getAttribute('href') ?? '')));

/* ── what the competition bought ────────────────────────────────────────── */
const aw = $('#rfqAward');
ok('the award card is titled as a result, not a list', /What the competition bought/.test(text(aw)));
ok('its head states the count and the money placed',
  /3 awards this month · GH₵ 21,460\.00 placed/.test(text(aw.querySelector('.card-head'))), text(aw.querySelector('.card-head')));
const aStats = [...aw.querySelectorAll('.stat')].map(s => text(s));
ok('placed, cheapest, dearest and away-from-lowest are the four readings',
  /Placed/.test(aStats[0]) && /21,460\.00/.test(aStats[0]) && /Cheapest bid/.test(aStats[1]) && /21,110\.00/.test(aStats[1])
  && /Dearest bid/.test(aStats[2]) && /24,110\.00/.test(aStats[2]) && /Away from the lowest/.test(aStats[3]), aStats.join(' || '));
ok('the three prices are explained as the same lines at three different rates',
  /the same lines would have cost at the lowest rate offered for each/.test(aStats[1])
  && /the same lines would have cost at the highest rate offered for each/.test(aStats[2]));
ok('and the lines that did not take the lowest bid are counted with their cost',
  /^3/.test(aStats[3]) && /3 lines went to a supplier who was not the lowest bidder/.test(aStats[3])
  && /each one carries a written justification/.test(aStats[3]) && /GH₵ 350\.00 more than the lowest bid/.test(aStats[3]), aStats[3]);
ok('the card says why a non-lowest award is allowed at all',
  /An award that takes the lowest bid needs no explanation/.test(text($('#rfqAwardState')))
  && /a decision somebody has to defend/.test(text($('#rfqAwardState'))), text($('#rfqAwardState')));
ok('and closes with the two numbers that differ',
  /came GH₵ 2,650\.00 under the dearest bid and GH₵ 350\.00 over the cheapest/.test(text($('#rfqAwardState'))));
const awards = [...$$('#awardTable tbody tr')].map(r => ({ rfq: text(r.children[0]), quotes: num(r.children[2]),
  placed: num(r.children[3]), lowest: num(r.children[4]), saved: num(r.children[5]), away: text(r.children[6]) }));
ok('the award table lists each request with what it placed', awards.length === 3
  && awards.map(a => a.placed).join(',') === '9860,5420,6180', awards.map(a => a.placed).join(','));
ok('the request awarded entirely at the lowest bid shows no lines away', awards[1].away === '—' && awards[1].lowest === awards[1].placed);
ok('saved per request is the gap between placed and the dearest bid, row by row',
  round2(awards.reduce((t, a) => t + a.saved, 0)) === 2650
  && round2(awards.reduce((t, a) => t + a.placed, 0)) === 21460
  && round2(awards.reduce((t, a) => t + a.lowest, 0)) === 21110);
ok('and the foot repeats the three totals', /Total\s*21,460\.00\s*21,110\.00\s*2,650\.00\s*3/.test(text($('#awardTable tfoot'))),
  text($('#awardTable tfoot')));

/* ── the register itself, and the chain ─────────────────────────────────── */
const cols = [...$$('#rfqTable thead th')].map(th => text(th));
ok('the register keeps its nine columns', cols.join('|') === 'RFQ|Title|Store|Quote by|Needed by|Items|Quotes|Status|By', cols.join('|'));
const rows = [...$$('#rfqTable tbody tr')].map(r => ({ rfq: text(r.querySelector('a.bold')), quotes: text(r.children[6]),
  status: text(r.children[7]), passed: r.children[3].querySelector('.badge') !== null }));
ok('every request is listed', rows.length === 10, `${rows.length}`);
ok('a request past its deadline is marked on the list, and only those still accepting quotes',
  rows.filter(r => r.passed).map(r => r.rfq).join(',') === 'RFQ-2026-0061,RFQ-2026-0058',
  rows.filter(r => r.passed).map(r => r.rfq).join(','));
ok('the quote column reads as answered against invited, not as a bare count',
  rows.every(r => /^\d+ \/ \d+$/.test(r.quotes)), rows.map(r => r.quotes).join(' | '));
ok('each status is badged with its own word', ['Draft', 'Sent', 'Closed', 'Awarded', 'Cancelled'].every(s => rows.some(r => r.status === s)),
  rows.map(r => r.status).join(','));
ok('the page places the register in the chain it starts',
  /the only document in the chain where the price is not given but argued for/.test(text($('#rfqChain')))
  && /the award that did not take the lowest bid is the one that needs a reason on the record/.test(text($('#rfqChain'))), text($('#rfqChain')));

/* ── the drift build ────────────────────────────────────────────────────── */
const drift = load('deploy/dev/previews/rfqs-drift.html');
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dHead = [...drift.querySelectorAll('.stats.wide')[0].children].map(s => dText(s));
ok('the drifted month saves nothing at all', /^GH₵ 0\.00/.test(dHead[4]) && /on GH₵ 24,110\.00 placed/.test(dHead[4]), dHead[4]);
ok('and that is red on the header', drift.querySelectorAll('.stats.wide')[0].children[4].classList.contains('tone-red'),
  drift.querySelectorAll('.stats.wide')[0].children[4].className);
const dStats = [...drift.querySelectorAll('#rfqAward .stat')].map(s => dText(s));
ok('placed at the dearest bid, with the cheapest still shown beside it',
  /24,110\.00/.test(dStats[0]) && /21,110\.00/.test(dStats[1]) && /24,110\.00/.test(dStats[2]));
ok('ten lines went elsewhere and none of them was justified',
  /^10/.test(dStats[3]) && /10 lines went to a supplier who was not the lowest bidder/.test(dStats[3])
  && /none of them carries a written justification/.test(dStats[3]) && /GH₵ 3,000\.00 more than the lowest bid/.test(dStats[3]), dStats[3]);
ok('the sentence says the same thing', /came GH₵ 0\.00 under the dearest bid and GH₵ 3,000\.00 over the cheapest/.test(dText(drift.querySelector('#rfqAwardState'))));
ok('the award card is red, because an unjustified award is a finding and not a statistic',
  drift.querySelector('#rfqAward .stat:last-child').classList.contains('tone-red'),
  drift.querySelector('#rfqAward .stat:last-child').className);
ok('the register itself did not change — the invitations and the clock are the same',
  dText(drift.querySelectorAll('.stats.wide')[0].children[1]) === text(doc.querySelectorAll('.stats.wide')[0].children[1])
  && dText(drift.querySelector('#rfqClock .card-head')) === text(doc.querySelector('#rfqClock .card-head')));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the register', doc], ['the drifted register', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style, nav.pager').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|php|endphp|unless|selected|checked|error|enderror|push|endpush)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder or leaked attribute`, !/\{\{|\}\}|:sub=|:tone=/.test(clean));
  ok(`${name} holds no unpluralised shorthand`, !/\(s\)|\(ies\)|award\(s\)/.test(clean));
  ok(`${name} prints no figure with a bare decimal`, !/GH₵ [\d,]+\.\d(?!\d)/.test(clean));
  ok(`${name} holds no empty stat`, target.querySelectorAll('.stat').length >= 15);
}

if (failures.length) {
  console.error(`RFQ register check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`RFQ register check: ${passed}/${passed} passed`);
