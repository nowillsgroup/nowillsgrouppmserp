#!/usr/bin/env node
/*
 * Petty cash — does the tin still describe the money, and is there something behind every cedi that left it?
 *
 * A float is the one account in a hotel that somebody carries in their hand, so the two things that can go wrong are
 * different from a ledger's: the tin drifts from its ledger account, and money leaves the tin with nothing to prove what
 * it bought. This check holds the screen to both, and to the arithmetic that ties the whole register together:
 *
 *   1. the imprest identity, row by row — cash + IOUs out + spend since the top-up must equal the imprest, and the
 *      top-up due must be what restores it; a register that foots only in its footer is a register that hides a float;
 *   2. the tin against the ledger — the gap must be exactly the movements waiting for the night audit, and the footer
 *      totals must be the sum of the rows, not a second calculation;
 *   3. the receipt control — every spend the header counts must exist as a row, the rows' money must be the header's
 *      money, and nobody over the threshold may be left with neither a receipt nor a reason;
 *   4. the ages — days since the tin was counted and days since it was last topped up, recomputed here from the dates
 *      the screen prints rather than trusted as text.
 *
 * Renders deploy/dev/previews/petty-cash.html and petty-cash-drift.html in jsdom against
 * deploy/dev/preview/petty-cash-sample.mjs.
 *
 * Run: node deploy/dev/audit/petty_cash_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const load = (file, url) => new JSDOM(readFileSync(file, 'utf8'), { url }).window.document;
const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
const num = (cell) => Number((((text(cell).match(/[−-]?[\d,]+\.\d{2}/) ?? ['0'])[0]).replace(/−/g, '-').replace(/,/g, '')) || 0);
const round2 = (value) => Math.round(value * 100) / 100;

const AS_OF = Date.parse('2026-11-07T00:00:00Z');
const daysFrom = (label) => {
  const [d, m, y] = String(label).split(' ');
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return Math.round((AS_OF - Date.parse(`${y}-${String(months.indexOf(m) + 1).padStart(2, '0')}-${d}T00:00:00Z`)) / 86400000);
};

const doc = load('deploy/dev/previews/petty-cash.html', 'https://preview.local/accounts/petty-cash');
const $ = (s) => doc.querySelector(s);
const $$ = (s) => [...doc.querySelectorAll(s)];

/* ── the document ───────────────────────────────────────────────────────── */
ok('the page is the petty cash screen', /Petty Cash/.test(doc.title), doc.title);
ok('it sits under accounts', /Accounts \/ Petty cash/.test(text($('.crumbs'))), text($('.crumbs')));
ok('the actions bar is still there', /New float/.test(text($('.page-head .actions'))) && /Cash book report/.test(text($('.page-head .actions'))), text($('.page-head .actions')));
ok('the receipt threshold the page is judged by is the one on the settings form', $('#f_petty_receipt_threshold')?.value === '100', $('#f_petty_receipt_threshold')?.value);
ok('and the count cycle too', $('#f_petty_count_days')?.value === '7', $('#f_petty_count_days')?.value);

/* ── the header ─────────────────────────────────────────────────────────── */
const stats = [...doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')].map(s => text(s));
ok('the header states four figures', stats.length === 4, `${stats.length}`);
ok('the cash in the tins is 4,880.00 across three active floats', /Cash in the tins/.test(stats[0]) && /4,880\.00/.test(stats[0]) && /3 active floats · 1 closed/.test(stats[0]), stats[0]);
ok('the IOUs out are 2,440.00 across three IOUs', /IOUs out/.test(stats[1]) && /2,440\.00/.test(stats[1]) && /3 IOUs with staff/.test(stats[1]), stats[1]);
ok('and the overdue one is named as overdue, in red',
  /1 overdue by up to 6 days/.test(stats[1]) && doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')[1].classList.contains('tone-red'),
  stats[1]);
ok('three items are waiting for a manager', /Waiting for approval/.test(stats[2]) && /^3/.test(stats[2]) && /1 voucher · 1 top-up · 1 count difference/.test(stats[2]), stats[2]);
ok('and no cedi is out without evidence, stated in green',
  /Without a receipt/.test(stats[3]) && /^0/.test(stats[3]) && /every spend over GH₵ 100\.00 is evidenced/.test(stats[3])
  && doc.querySelectorAll('.stats')[0].querySelectorAll('.stat')[3].classList.contains('tone-green'), stats[3]);

/* ── the tin against the ledger ─────────────────────────────────────────── */
const tie = $('#pettyTie');
ok('the page asks whether the ledger agrees with the tin', tie !== null && /Does the ledger agree with the tin\?/.test(text(tie)));
ok('the head states both sides', /4 floats · GH₵ 4,880\.00 in the tins · GH₵ 3,460\.00 in the ledger/.test(text(tie.querySelector('.card-head'))), text(tie.querySelector('.card-head')));
const tinRows = [...$$('#tinTable tbody tr')].map(r => ({
  float: text(r.children[0]), tin: num(r.children[1]), ledger: num(r.children[2]),
  waiting: num(r.children[3]), unexplained: num(r.children[4]), state: text(r.children[5]),
}));
ok('every float is on the tin table', tinRows.length === 4, `${tinRows.length}`);
ok('each row is a float we expect', tinRows.map(r => r.float).join(' | ') === 'Front Office | Kitchen & Stores | Maintenance | Bar', tinRows.map(r => r.float).join(' | '));
ok('each row’s unaccounted figure is its tin less its ledger less its queue',
  tinRows.every(r => Math.abs(r.tin - r.ledger - r.waiting - r.unexplained) < 0.005),
  tinRows.map(r => `${r.float}: ${r.tin}−${r.ledger}−${r.waiting} vs ${r.unexplained}`).join(' | '));
ok('the queue is what the night audit has not posted, float by float — 780.00 and 640.00',
  tinRows.filter(r => r.waiting > 0).length === 2
  && round2(tinRows.reduce((t, r) => t + r.waiting, 0)) === 1420,
  tinRows.map(r => `${r.float}:${r.waiting}`).join(' | '));
ok('the footer is the sum of the rows', num($('#tieSumTin')) === round2(tinRows.reduce((t, r) => t + r.tin, 0))
  && num($('#tieSumLedger')) === round2(tinRows.reduce((t, r) => t + r.ledger, 0))
  && num($('#tieSumWaiting')) === round2(tinRows.reduce((t, r) => t + r.waiting, 0)),
  `${text($('#tieSumTin'))} / ${text($('#tieSumLedger'))} / ${text($('#tieSumWaiting'))}`);
ok('the whole difference is the queue, and nothing is unaccounted for',
  round2(num($('#tieSumTin')) - num($('#tieSumLedger'))) === num($('#tieSumWaiting')) && num($('#tieSumUnexplained')) === 0,
  `${num($('#tieSumTin'))} − ${num($('#tieSumLedger'))} vs ${num($('#tieSumWaiting'))}, unaccounted ${num($('#tieSumUnexplained'))}`);
ok('every float is marked as agreeing', tinRows.every(r => r.state === 'agrees'), tinRows.map(r => r.state).join(' | '));
const tieState = text($('#pettyTieState'));
ok('and the page says so in words', /Every float's difference between the tin and its ledger account is a movement dated after the last night audit/.test(tieState), tieState);
ok('naming the queue it is explained by', /GH₵ 1,420\.00 still to post/.test(tieState) && /The ledger and the tins describe the same money\./.test(tieState), tieState);
ok('the page does not cry defect when there is none', !/neither posted nor waiting/.test(tieState));

/* ── the receipt control ────────────────────────────────────────────────── */
const receipt = $('#receiptTie');
ok('the page asks whether a receipt stands behind every cedi', /Is there a receipt behind every cedi out of the tin\?/.test(text(receipt)));
ok('the head counts the population and the evidence', /8 spends over GH₵ 100\.00 · 6 on file · 2 with a stated reason · 0 without either/.test(text(receipt.querySelector('.card-head'))),
  text(receipt.querySelector('.card-head')));
const rStats = [...receipt.querySelectorAll('.stat')].map(s => text(s));
ok('the money spent over the threshold is stated', /Spent over the threshold/.test(rStats[0]) && /4,180\.00/.test(rStats[0]) && /8 vouchers and IOU settlements/.test(rStats[0]), rStats[0]);
ok('the receipts on file cover 3,560.00 of it', /On file/.test(rStats[1]) && /3,560\.00/.test(rStats[1]) && /6 with a receipt attached/.test(rStats[1]), rStats[1]);
ok('two vouchers say why they have none', /Stated reason/.test(rStats[2]) && /^2/.test(rStats[2]), rStats[2]);
ok('and nothing is left with neither, in green', /Without either/.test(rStats[3]) && /0\.00/.test(rStats[3])
  && receipt.querySelectorAll('.stat')[3].classList.contains('tone-green'), rStats[3]);
const evidence = [...$$('#receiptRows tbody tr')].map(r => ({
  date: text(r.children[0]), age: Number((text(r.children[1]).match(/(\d+) d/) ?? [0, -1])[1]),
  float: text(r.children[2]), txn: text(r.children[3]), payee: text(r.children[4]),
  amount: num(r.children[6]), badge: text(r.children[7]),
}));
ok('every counted spend is a row on the screen', evidence.length === 8, `${evidence.length}`);
ok('the rows are the money the head counts', round2(evidence.reduce((t, e) => t + e.amount, 0)) === 4180, `${evidence.reduce((t, e) => t + e.amount, 0)}`);
ok('six rows carry a receipt', evidence.filter(e => e.badge === 'receipt').length === 6);
ok('and their money is the money the card says is on file',
  round2(evidence.filter(e => e.badge === 'receipt').reduce((t, e) => t + e.amount, 0)) === 3560,
  `${evidence.filter(e => e.badge === 'receipt').reduce((t, e) => t + e.amount, 0)}`);
ok('two rows state a reason instead', evidence.filter(e => e.badge === 'reason given').length === 2);
ok('no row is left with nothing', evidence.every(e => e.badge !== 'nothing'), evidence.map(e => e.badge).join(' | '));
ok('the age printed on each row is the days from its date', evidence.every(e => e.age === daysFrom(e.date)),
  evidence.map(e => `${e.date}=${e.age}/${daysFrom(e.date)}`).join(' | '));
ok('the oldest spend is sixteen days old', Math.max(...evidence.map(e => e.age)) === 16);
ok('the spends are drawn from all three active tins',
  new Set(evidence.map(e => e.float)).size === 3, [...new Set(evidence.map(e => e.float))].join(' | '));
const rState = text($('#receiptTieState'));
ok('the page says every spend carries something', /All 8 spends over GH₵ 100\.00 carry a receipt or a reason/.test(rState), rState);
ok('and that no cash left without it', /Nothing left the tins without something behind it\./.test(rState), rState);
ok('the card pages its rows rather than printing them all', $('nav.pager') !== null);

/* ── the register: the imprest identity, row by row ─────────────────────── */
const regRows = [...$$('#floatTable tbody tr')].map(r => ({
  name: text(r.children[0]), custodian: text(r.children[1]), imprest: num(r.children[2]), tin: num(r.children[3]),
  ious: num(r.children[4]), spend: num(r.children[5]), topUp: num(r.children[6]), pct: Number((text(r.children[7]).match(/(\d+) %/) ?? [0, -1])[1]),
  count: text(r.children[8]), state: text(r.children[9]),
}));
ok('the register lists every float', regRows.length === 4, `${regRows.length}`);
ok('cash + IOUs + spend is the imprest on every row — the identity the system is built on',
  regRows.every(r => Math.abs(r.tin + r.ious + r.spend - r.imprest) < 0.005),
  regRows.map(r => `${r.name}: ${r.tin}+${r.ious}+${r.spend} vs ${r.imprest}`).join(' | '));
ok('the top-up due is what restores the imprest', regRows.every(r => Math.abs(Math.max(0, r.imprest - r.tin - r.ious) - r.topUp) < 0.005),
  regRows.map(r => `${r.name}: ${r.topUp}`).join(' | '));
ok('each float’s percentage is its cash against its imprest', regRows.every(r => r.pct === Math.round(100 * r.tin / r.imprest)),
  regRows.map(r => `${r.name}: ${r.pct}%`).join(' | '));
ok('the register footer is the sum of its rows',
  num($('#regSumImprest')) === round2(regRows.reduce((t, r) => t + r.imprest, 0))
  && num($('#regSumTin')) === round2(regRows.reduce((t, r) => t + r.tin, 0))
  && num($('#regSumIous')) === round2(regRows.reduce((t, r) => t + r.ious, 0))
  && num($('#regSumSpend')) === round2(regRows.reduce((t, r) => t + r.spend, 0))
  && num($('#regSumTopUp')) === round2(regRows.reduce((t, r) => t + r.topUp, 0)),
  `${text($('#regSumImprest'))} / ${text($('#regSumTin'))} / ${text($('#regSumIous'))} / ${text($('#regSumSpend'))} / ${text($('#regSumTopUp'))}`);
ok('and its totals agree with the tin table and the header',
  num($('#regSumTin')) === num($('#tieSumTin')) && num($('#regSumSpend')) === 4180 && num($('#regSumIous')) === 2440,
  `${text($('#regSumTin'))} vs ${text($('#tieSumTin'))}`);
ok('the footer states the float position as a share of imprest',
  /42 %/.test(text($('#floatTable tfoot tr'))) && /of imprest/.test(text($('#floatTable tfoot tr'))), text($('#floatTable tfoot tr')));

/* ── the register: ageing ───────────────────────────────────────────────── */
ok('each float prints how long since it was counted',
  regRows.map(r => (r.count.match(/^(\d+) d/) ?? [])[1] ?? r.count).join(' | ') === '3 | 11 | 15 | never',
  regRows.map(r => r.count).join(' | '));
ok('a float counted inside the cycle is not flagged', /Below 25 %/.test(regRows[0].state) && !/Count due/.test(regRows[0].state), regRows[0].state);
ok('a float past the count cycle says the count is due', /Count due/.test(regRows[1].state) && /count due every 7 days/.test(regRows[1].count + ' ' + regRows[1].state), regRows[1].state);
ok('the oldest spend on each float is aged beside the count', /oldest spend 7 d/.test(regRows[0].count) && /oldest spend 16 d/.test(regRows[1].count),
  `${regRows[0].count} / ${regRows[1].count}`);
ok('a float below the low-balance mark is red', /Below 25 %/.test(regRows[0].state)
  && doc.querySelector('#floatTable tbody tr').getAttribute('data-tone') === 'red');
ok('a closed float is closed, not merely empty', /Closed/.test(regRows[3].state) && regRows[3].tin === 1500 && regRows[3].spend === 0, regRows[3].state);
ok('the page explains the model it is judged by', /Imprest system: each float holds a fixed amount\. Cash in the tin \+ IOUs out \+ spend since the last top-up always equal the imprest/.test(text(doc.body)));

/* ── what is waiting, and what is late ──────────────────────────────────── */
const appRows = [...$$('#approvalTable tbody tr')];
ok('three items wait for a manager', appRows.length === 3, `${appRows.length}`);
ok('the over-limit voucher is the 780.00 one', /PV-2026-0412/.test(text(appRows[0])) && /780\.00/.test(text(appRows[0])), text(appRows[0]));
ok('the top-up to pay is named as an approved top-up', /RPL-2026-0088/.test(text(appRows[1])) && /Top-up to pay/.test(text(appRows[1])) && /1,960\.00/.test(text(appRows[1])), text(appRows[1]));
ok('the count difference is signed and red as a shortage', /CNT-2026-0034/.test(text(appRows[2])) && /−40\.00|-40\.00/.test(text(appRows[2])), text(appRows[2]));
const overRows = [...$$('#overdueTable tbody tr')];
ok('the overdue IOU is listed with how late it is', overRows.length === 1 && /IOU-2026-0117/.test(text(overRows[0])) && /1,200\.00/.test(text(overRows[0])) && /6d late/.test(text(overRows[0])), text(overRows[0]));
ok('and it is the only late one, which the header already said', /3 IOUs with staff · 1 overdue/.test(stats[1]));

/* ── the drift build: money out with nothing behind it ──────────────────── */
const drift = load('deploy/dev/previews/petty-cash-drift.html', 'https://preview.local/accounts/petty-cash');
const d$ = (s) => drift.querySelector(s);
const dText = (n) => (n?.textContent ?? '').replace(/\s+/g, ' ').trim();
const dTie = drift.querySelector('#receiptTie');
ok('the drift build counts one spend with nothing behind it', /8 spends over GH₵ 100\.00 · 5 on file · 2 with a stated reason · 1 without either/.test(dText(dTie.querySelector('.card-head'))), dText(dTie.querySelector('.card-head')));
const dStats = [...dTie.querySelectorAll('.stat')].map(s => dText(s));
ok('the money without evidence is stated', /Without either/.test(dStats[3]) && /640\.00/.test(dStats[3]) && /1 spend with nothing behind it/.test(dStats[3]), dStats[3]);
ok('and it is red, not merely noted', dTie.querySelectorAll('.stat')[3].classList.contains('tone-red') === true);
ok('the receipts on file now cover 2,920.00', /2,920\.00/.test(dStats[1]), dStats[1]);
ok('the header card says there is one without a receipt', /Without a receipt/.test(dText(drift.querySelectorAll('.stats')[0])) && /640\.00 spent with nothing behind it/.test(dText(drift.querySelectorAll('.stats')[0])));
const dState = dText(drift.querySelector('#receiptTieState'));
ok('the page names the voucher, the float, the money and the age', /PV-2026-0396/.test(dState) && /Front Office/.test(dState) && /640\.00/.test(dState) && /4 days old/.test(dState), dState);
ok('and says what to do about it', /attach the receipt, or write the reason on the voucher/.test(dState), dState);
const dRow = [...drift.querySelectorAll('#receiptRows tbody tr')].find(r => /PV-2026-0396/.test(dText(r)));
ok('the row itself is badged as having nothing', dRow !== undefined && dText(dRow.children[7]) === 'nothing' && dRow.getAttribute('data-evidence') === 'none', dText(dRow));
ok('the other rows are untouched by it', [...drift.querySelectorAll('#receiptRows tbody tr')].filter(r => dText(r.children[7]) === 'nothing').length === 1);
ok('the tin still agrees with the ledger — a missing receipt is a different question',
  /0\.00/.test(dText(drift.querySelector('#tieSumUnexplained'))) && /The ledger and the tins describe the same money\./.test(dText(drift.querySelector('#pettyTieState'))),
  dText(drift.querySelector('#pettyTieState')));
ok('and the imprest identity still holds on every float',
  [...drift.querySelectorAll('#floatTable tbody tr')].every(r => {
    const tin = num(r.children[3]); const ious = num(r.children[4]); const spend = num(r.children[5]); const imprest = num(r.children[2]);
    return Math.abs(tin + ious + spend - imprest) < 0.005;
  }));

/* ── nothing leaked, in either build ────────────────────────────────────── */
for (const [name, target] of [['the screen', doc], ['the drifted screen', drift]]) {
  const copy = target.body.cloneNode(true);
  copy.querySelectorAll('script, style').forEach(node => node.remove());
  const clean = text(copy);
  ok(`${name} holds no Blade directive`, !/@(if|elseif|else|endif|foreach|endforeach|forelse|php|endphp|unless|endunless|isset|endisset)\b/.test(clean));
  ok(`${name} holds no undefined, NaN or [object Object]`, !/undefined|NaN|\[object Object\]/.test(clean));
  ok(`${name} holds no raw placeholder`, !/\{\{|\}\}/.test(clean));
  ok(`${name} holds no escaped markup printed as text`, !/&lt;(nav|span|a|div|td)\b/.test(clean));
}

if (failures.length) {
  console.error(`petty cash check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`petty cash check: ${passed}/${passed} passed`);
