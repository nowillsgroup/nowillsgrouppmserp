#!/usr/bin/env node
/**
 * Behavioural check for the Recreation outlet previews.
 *
 * The sandbox has no browser, so this loads the built preview HTML in jsdom, runs the real
 * inline scripts, and drives the screens the way a cashier and a manager do:
 *
 *   recreation-outlet.html  add two tiles → quantities, net/tax/total → quick cash → change,
 *                           room charge swaps the tender block, search/category filter hides
 *                           tiles, and "Complete sale" refuses an empty till.
 *   recreation-shift.html   the denomination count drives counted cash and the variance,
 *                           and a short count is called out as short.
 *   recreation-day.html     the Z report totals reconcile: tenders add up to the takings,
 *                           categories to the net + tax, and every shift shows a drawer.
 *
 * Each assertion is written so it fails if the feature is removed (proved by mutating the
 * preview and re-running).
 *
 * Run: node deploy/dev/audit/recreation_preview_check.js
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
  const dom = new JSDOM(fs.readFileSync(preview(name), 'utf8'), { runScripts: 'dangerously', pretendToBeVisual: true });
  return dom.window;
}

const money = (text) => Number(String(text).replace(/[^\d.-]/g, ''));

// ── the till ──────────────────────────────────────────────────────────────────────────
await (async () => {
  const win = load('recreation-outlet.html');
  const doc = win.document;
  const $ = (s) => doc.querySelector(s);

  ok(doc.querySelectorAll('.pos-tile').length === 8, 'outlet: eight catalogue tiles');
  ok(/open/i.test($('#posShift')?.textContent ?? ''), 'outlet: the shift strip shows the open shift');
  ok(/Akosua Mensah/.test($('#posShift')?.textContent ?? ''), 'outlet: the strip names the cashier on the drawer');
  ok(doc.querySelectorAll('#quickCash button').length === 0, 'outlet: no quick-cash buttons before there is a total');
  ok($('#posShift').dataset.shiftRequired === '0', 'outlet: the till knows whether a shift is compulsory');
  ok($('#posShift').dataset.shiftOpen === '1', 'outlet: the till knows a shift is open');
  ok($('#completeSale').disabled === false, 'outlet: the till can take money while the shift is open');

  // add a day pass (120) and two towels (15) — 150 net, tax added on top
  const add = (id, times = 1) => { for (let i = 0; i < times; i++) doc.querySelector(`[data-add="${id}"]`).click(); };
  add(1);
  add(5, 2);

  ok(doc.querySelectorAll('#cartTable tbody tr').length === 2, 'outlet: two cart lines after adding three items');
  ok(/Day pass — adult/.test($('#cartTable').textContent), 'outlet: cart names the item');
  ok(doc.querySelectorAll('#cartTable tbody tr')[1].querySelector('.pos-qty b').textContent === '2', 'outlet: the towel line counts two');

  ok(money($('#sumSub').textContent) === 150, 'outlet: net is GH₵ 150.00 (120 + 2×15)');
  ok(money($('#sumTax').textContent) === 31.5, 'outlet: tax is 21% of the net = GH₵ 31.50');
  ok(money($('#sumTotal').textContent) === 181.5, 'outlet: total is GH₵ 181.50');

  // the tender row auto-fills the total, and quick cash rounds up to the next 200
  ok(Math.abs(money(doc.querySelector('.pay-amount').value) - 181.5) < 0.005, 'outlet: the tender row auto-fills the total');
  ok(money($('#sumChange').textContent) === 0, 'outlet: no change due with exact tender');
  ok(doc.querySelectorAll('#quickCash button').length >= 2, 'outlet: quick-cash buttons appear with a total');
  const quick = [...doc.querySelectorAll('#quickCash button')].find((b) => /\+20/.test(b.textContent));
  quick.click();
  ok(money(doc.querySelector('.pay-amount').value) === 200, 'outlet: “+20” tenders GH₵ 200.00');
  ok(money($('#sumChange').textContent) === 18.5, 'outlet: change due is GH₵ 18.50');
  ok(!$('#changeRow').hidden, 'outlet: the change row is shown when the guest over-tenders');

  // decrement removes the line when it reaches zero
  // the cart re-renders on every change, so each click takes a fresh button
  doc.querySelectorAll('#cartTable [data-dec]')[1].click();
  doc.querySelectorAll('#cartTable [data-dec]')[1].click();
  ok(doc.querySelectorAll('#cartTable tbody tr').length === 1, 'outlet: the towel line disappears at zero');

  // room charge swaps the tender block for the room number
  doc.querySelector('[data-settle="room"]').click();
  ok(!$('#roomField').hidden && $('#payBlock').hidden, 'outlet: room charge hides the tenders and asks for a room');
  doc.querySelector('[data-settle="pay"]').click();
  ok($('#roomField').hidden && !$('#payBlock').hidden, 'outlet: taking payment brings the tenders back');

  // the catalogue filter narrows the grid and the count chips are honest
  const chip = doc.querySelector('.pos-chip[data-cat="rental"]');
  chip.click();
  const visible = [...doc.querySelectorAll('.pos-tile')].filter((t) => !t.hidden);
  ok(visible.length === 3 && visible.every((t) => t.dataset.cat === 'rental'), 'outlet: the rental chip shows only the three rentals');
  doc.querySelector('.pos-chip[data-cat=""]').click();
  ok([...doc.querySelectorAll('.pos-tile')].filter((t) => !t.hidden).length === 8, 'outlet: “All” restores the catalogue');

  $('#posSearch').value = 'sauna';
  $('#posSearch').dispatchEvent(new win.Event('input'));
  ok([...doc.querySelectorAll('.pos-tile')].filter((t) => !t.hidden).length === 1, 'outlet: search finds the sauna');

  // ── completing a sale: the counter hands over tickets and a receipt ──
  const posts = [];
  win.fetch = async (url, options) => {
    posts.push({ url, body: JSON.parse(options.body) });
    return { json: async () => ({
      ok: true, sale_no: 'TKT-004599', total: 'GH₵ 145.20', change: 'GH₵ 54.80',
      tickets: [{ pass_no: 'DP-0091', name: 'Day pass — adult', pax: 1 }],
      receipt_url: '/recreation/sales/9/receipt',
    }) };
  };
  const settle = async () => {
    $('#completeSale').click();
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setTimeout(resolve, 0));
  };

  await settle();
  ok(posts.length === 1, 'outlet: completing a sale posts once');
  ok(typeof posts[0]?.url === 'string' && posts[0].url.length > 0, 'outlet: the sale is posted to an endpoint');
  ok(posts[0]?.body.items?.length === 1 && posts[0].body.items[0].type_id === 1, 'outlet: the sale carries the ticket type, not a client price');
  ok(posts[0]?.body.settle === 'pay' && posts[0].body.payments.length === 1, 'outlet: the sale carries one direct tender');
  ok(Math.abs(posts[0].body.payments[0].amount - 200) < 0.005, 'outlet: the tendered amount is what the cashier took');
  ok($('#doneModal')?.classList.contains('show') === true, 'outlet: the receipt modal opens when the sale completes');
  ok($('#doneSaleNo')?.textContent === 'TKT-004599', 'outlet: the modal shows the sale number');
  ok(/DP-0091/.test($('#doneTickets').textContent), 'outlet: the modal lists the entry ticket to hand over');
  ok($('#doneReceipt')?.getAttribute('href') === '/recreation/sales/9/receipt', 'outlet: the modal links to the printed receipt');
  ok(/54\.80/.test($('#doneChange').textContent), 'outlet: the modal tells the cashier what change to give');
  ok(doc.querySelectorAll('#cartTable [data-inc]').length === 0 && /Tap items/.test($('#cartTable').textContent), 'outlet: the till empties itself after a sale');

  // ── charging a room sends no tender at all ──
  doc.querySelector('[data-add="3"]').click();
  doc.querySelector('[data-settle="room"]').click();
  $('#room_no').value = '204';
  await settle();
  ok(posts[1]?.body.settle === 'room' && posts[1].body.payments.length === 0, 'outlet: a room charge posts no tender');
  ok(posts[1]?.body.room_no === '204', 'outlet: a room charge posts the room number');

  // an empty till refuses to complete (the message is set, no POST attempted)
  const before = posts.length;
  doc.querySelector('#clearCart').click();
  $('#completeSale').click();
  ok(posts.length === before && !$('#tillMsg').hidden && /at least one item/i.test($('#tillMsg').textContent), 'outlet: an empty till refuses to complete');

  win.close();
})();

// ── the shift close-out ───────────────────────────────────────────────────────────────
{
  const win = load('recreation-shift.html');
  const doc = win.document;
  const $ = (s) => doc.querySelector(s);

  ok(doc.querySelectorAll('#denomTable [data-denom]').length === 11, 'shift: eleven cedi denominations to count');
  ok(/Akosua Mensah/.test(doc.body.textContent), 'shift: the open drawer names its cashier');
  ok(money($('#counted_cash').value) === 1370, 'shift: counted cash starts from the expected drawer');
  ok(/balanced/.test($('#variancePreview').textContent), 'shift: an untouched count reads balanced');

  // count GH₵ 1,360.00: 6×200 + 2×50 + 5×10 + 1×5 + 5×1 = 1200+100+50+5+5 = 1360
  const set = (denom, qty) => {
    const input = doc.querySelector(`[data-denom="${denom}"]`);
    input.value = String(qty);
    input.dispatchEvent(new win.Event('input'));
  };
  set('200', 6); set('50', 2); set('10', 5); set('5', 1); set('1', 5);

  ok(money($('#denomTotal').textContent) === 1360, 'shift: the denomination grid totals GH₵ 1,360.00');
  ok(money($('#counted_cash').value) === 1360, 'shift: the grid writes the counted cash');
  ok(/short/.test($('#variancePreview').textContent), 'shift: a short count says short');
  ok(money($('#variancePreview').textContent) === 10, 'shift: the variance is GH₵ 10.00 short');

  // the counter policy is a manager switch, and the close offers both hand-over routes
  ok(Boolean($('#counterSettings')), 'shift: the counter settings card is on the screen');
  ok($('#counterSettings input[name="recreation_shift_required"]') !== null, 'shift: the shift-required switch is offered');
  ok($('#counterSettings input[name="recreation_shift_required"]')?.checked === false, 'shift: the switch reflects the stored policy');
  const printBtns = [...doc.querySelectorAll('#closeShiftForm button[name="print"]')];
  ok(printBtns.length === 2 && printBtns.some((b) => b.value === '1'), 'shift: closing can hand the manager the Z to print');

  // the history explains each closed shift's variance
  const history = $('#shiftHistory').textContent;
  ok(/Efua Danso/.test(history), 'shift: the history lists a closed shift');
  ok(/-7\.50/.test(history), 'shift: a short closed shift keeps its variance');

  win.close();
}

// ── the Z report ──────────────────────────────────────────────────────────────────────
{
  const win = load('recreation-day.html');
  const doc = win.document;
  const $ = (s) => doc.querySelector(s);

  const tenderRows = [...doc.querySelectorAll('#dayTenders tbody tr')];
  ok(tenderRows.length === 3, 'day: three tenders were taken');
  const tenderTotal = tenderRows.reduce((sum, row) => sum + money(row.lastElementChild.textContent), 0);
  ok(Math.abs(tenderTotal - 2250.6) < 0.01, 'day: the tenders add up to the takings');
  ok(/drawer/.test($('#dayTenders').textContent), 'day: cash is marked as the drawer tender');

  const catRows = [...doc.querySelectorAll('#dayCategories tbody tr')];
  const catTotal = catRows.reduce((sum, row) => sum + money(row.children[2].textContent) + money(row.children[3].textContent), 0);
  ok(Math.abs(catTotal - 2250.6) < 0.01, 'day: net + tax by category reconciles to the takings');
  ok(/Access ticket/.test($('#dayCategories').textContent), 'day: the access category is labelled in words');

  const typeRows = [...doc.querySelectorAll('#dayTypes tbody tr')];
  ok(typeRows.length === 6, 'day: six ticket types sold');
  ok(typeRows.some((r) => /Day pass — adult/.test(r.textContent)), 'day: the best seller is listed');

  const shiftRows = [...doc.querySelectorAll('#dayShifts tbody tr')];
  ok(shiftRows.length === 3, 'day: three shifts are reconciled');
  ok(/open/.test(shiftRows[2].textContent), 'day: the still-open shift is flagged');

  ok(doc.querySelectorAll('#daySales tbody tr').length === 6, 'day: the sale list shows the sales');
  ok(/TKT-004512/.test($('#daySales').textContent), 'day: sale numbers are listed');
  ok(/Room 204/.test($('#daySales').textContent), 'day: a room-charged sale shows its room');
  ok(/Yaw Boateng/.test($('#daySales').textContent), 'day: a sale shows who rang it up');

  ok(/VAT 15%/.test(doc.body.textContent), 'day: the tax build-up is spelled out');
  ok(/Voids/.test(doc.body.textContent), 'day: voided sales are reported');
  ok(/Admissions/.test(doc.body.textContent) && /11 of 14 ticket/.test(doc.body.textContent), 'day: admissions are reconciled against the tickets sold');
  ok(/21 of 26/.test(doc.body.textContent), 'day: guests through the gate are counted');

  win.close();
}


// ── the receipt the counter hands over ────────────────────────────────────────────────
{
  const win = load('recreation-receipt.html');
  const doc = win.document;
  const $ = (s) => doc.querySelector(s);

  ok(/TKT-004512/.test(doc.body.textContent), 'receipt: the sale number is on it');
  ok(/Day pass — adult/.test($('.table').textContent), 'receipt: the items are itemised');
  ok(/411\.40/.test($('.table tfoot').textContent), 'receipt: the total is the amount taken');
  ok(/Subtotal[\s\S]*340\.00/.test($('.table tfoot').textContent), 'receipt: the net is shown before the tax');
  ok(/71\.40/.test($('.table tfoot').textContent), 'receipt: the tax is shown');
  ok(/Cash/.test(doc.body.textContent) && /MP-7741902/.test(doc.body.textContent), 'receipt: both tenders are named, MoMo with its reference');
  ok(/Change/.test(doc.body.textContent) && /38\.60/.test(doc.body.textContent), 'receipt: the change given is printed');
  ok(/DP-0091/.test(doc.body.textContent) && /DP-0092/.test(doc.body.textContent), 'receipt: both entry tickets are printed as stubs');
  ok(/valid 05 Nov 2026/.test(doc.body.textContent), 'receipt: each stub carries the visit date');
  ok(!/VOID/.test(doc.body.textContent), 'receipt: a paid sale is not stamped void');

  win.close();
}

// ── the outlet ledger ────────────────────────────────────────────────────────────────
{
  const win = load('recreation-sales.html');
  const doc = win.document;
  const $ = (s) => doc.querySelector(s);

  ok(doc.querySelectorAll('table tbody tr').length === 6, 'ledger: six sales are listed');
  const head = doc.querySelector('table thead').textContent;
  ok(/Shift/.test(head), 'ledger: the shift each sale belonged to is a column');
  ok(/Morning 07:05 start/.test($('table tbody').textContent), 'ledger: a shift sale names its shift and start');
  ok(/—/.test(doc.querySelectorAll('table tbody tr')[5].textContent), 'ledger: a sale rung up outside a shift shows no shift');
  ok(/taken by Akosua Mensah/.test($('table tbody').textContent), 'ledger: the row says who took the money');
  ok(/Void/.test($('table tbody').textContent), 'ledger: a manager can void from the ledger');
  ok(/Day report/.test(doc.body.textContent), 'ledger: it links to the day (Z) report');

  win.close();
}

// ── the till before a shift is opened ────────────────────────────────────────────────
{
  const win = load('recreation-outlet-closed.html');
  const doc = win.document;
  const $ = (s) => doc.querySelector(s);

  ok(/No outlet shift open/i.test($('#posShift').textContent), 'closed: the strip says no shift is open');
  ok(/blocked until a shift is open/i.test($('#posShift').textContent), 'closed: the cashier is told sales are blocked');
  ok($('#completeSale').disabled === true, 'closed: the till will not take money without a shift');
  ok(/Open a shift to take money/.test($('#completeSale').textContent), 'closed: the button says why it is disabled');
  ok(!$('#tillMsg').hidden && /Open your outlet shift/i.test($('#tillMsg').textContent), 'closed: the till explains the block');
  ok(doc.querySelectorAll('.pos-tile').length === 8, 'closed: the catalogue still loads so the counter can see prices');

  win.close();
}

// ── the gate: admitting the ticket the counter sold ───────────────────────────────────
{
  const win = load('recreation-gate.html');
  const doc = win.document;
  const $ = (s) => doc.querySelector(s);

  ok($('#code') !== null && $('#code').tagName === 'INPUT', 'gate: there is a code field to admit against');
  ok(/DP-0091/.test($('#code').getAttribute('placeholder')), 'gate: the field shows the shape of a ticket code');
  ok(/second presentation/i.test($('#redeemCard').textContent), 'gate: the screen warns that a ticket admits once');

  ok(/4Tickets issued/.test(doc.body.textContent), 'gate: four tickets were issued today');
  ok(/2Admitted/.test(doc.body.textContent), 'gate: two of them have been admitted');
  ok(/Turnstile rate · 50%/.test(doc.body.textContent), 'gate: the turnstile rate is admitted ÷ issued');

  const rows = [...doc.querySelectorAll('#gateTable tbody tr')];
  ok(rows.length === 5, 'gate: the board lists the day\'s five tickets');
  ok(/In 08:31/.test(rows[0].textContent), 'gate: an admitted ticket shows the time it came in');
  ok(/Kwame Asare/.test(rows[0].textContent), 'gate: an admitted ticket names who let them in');
  ok(/Not yet/.test(rows[2].textContent), 'gate: a ticket that has not arrived says so');
  ok(/Cancelled/.test(rows[4].textContent), 'gate: a refunded ticket is shown cancelled, not waiting');
  ok(/room 204/.test(rows[3].textContent), 'gate: a room-charged ticket shows the room it went to');

  // a manager can take an admission back, and only on a ticket that is actually in
  const reverses = [...doc.querySelectorAll('#gateTable [data-modal="reverseModal"]')];
  ok(reverses.length === 2, 'gate: only admitted tickets offer a reversal');
  ok(reverses.every((b) => typeof b.dataset.action === 'string' && b.dataset.action.length > 0), 'gate: each reversal carries its own post target');
  ok(doc.querySelector('#reverseModal input[name="reason"]') !== null, 'gate: a reversal must give a reason');
  ok(/goes back to/.test(doc.querySelector('#reverseModal').textContent), 'gate: the dialog explains that the ticket becomes valid again');

  win.close();
}

// ── the printed Z ────────────────────────────────────────────────────────────────────
{
  const win = load('recreation-z.html');
  const doc = win.document;
  const text = doc.body.textContent;

  ok(/Z\) report|end-of-day/.test(text), 'z: it is headed as the end-of-day report');
  ok(/Thursday, 05 November 2026/.test(text), 'z: it carries the business date in words');
  ok(/Taken by tender/.test(text) && /Cash \(drawer\)/.test(text), 'z: it prints the tenders and marks cash as the drawer');
  ok(/Collections[\s\S]*2,250\.60/.test(text), 'z: collections match the takings');
  ok(/Revenue by category/.test(text) && /Access ticket/.test(text), 'z: it prints revenue by category');
  ok(/What sold/.test(text) && /Day pass — adult/.test(text), 'z: it prints what sold');
  ok(/Shifts and drawers/.test(text) && /Efua Danso/.test(text), 'z: every cashier\'s drawer is on the sheet');
  ok(/-7\.50/.test(text), 'z: a short drawer keeps its variance in print');
  ok(/Admissions/.test(text) && /11 of 14 ticket/.test(text), 'z: it prints admissions against tickets sold');
  ok(/Voids/.test(text) && /1 · 145\.20/.test(text), 'z: it prints the voids');
  ok(/VAT 15%/.test(text), 'z: the tax build-up is printed');
  ok(doc.querySelector('button[onclick*="print"]') !== null, 'z: the sheet prints itself');

  win.close();
}

// ── the catalogue the manager maintains ──────────────────────────────────────────────
{
  const win = load('recreation-types.html');
  const doc = win.document;
  const rows = [...doc.querySelectorAll('table tbody tr')];

  ok(rows.length === 8, 'types: eight products are on sale');
  ok(/Guest pays/.test(doc.querySelector('table thead').textContent), 'types: the guest price is a column, not just the net');
  ok(/145\.20/.test(rows[0].textContent), 'types: a GH₵ 120 ticket shows the guest paying GH₵ 145.20 at 21%');
  ok(/Adults/.test(rows[0].textContent), 'types: a ticket says who it covers');
  ok(/issues entry tickets/.test(rows[0].textContent), 'types: an access ticket says it issues a ticket');
  ok(/no ticket/.test(rows[3].textContent), 'types: a rental says it does not');
  ok(doc.querySelector('#typeModal input[name="price"]') !== null, 'types: the manager can set a price');
  ok(doc.querySelector('#typeModal input[name="tax_rate"]') !== null, 'types: a per-type tax override is offered');

  win.close();
}

if (failures.length) {
  console.error(`recreation preview check: ${checks - failures.length}/${checks} passed`);
  failures.forEach((label) => console.error(`  FAIL ${label}`));
  process.exit(1);
}
console.log(`recreation preview check: ${checks}/${checks} passed`);
