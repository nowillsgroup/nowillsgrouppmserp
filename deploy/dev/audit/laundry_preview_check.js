#!/usr/bin/env node
/**
 * Laundry tax & settlement check.
 *
 * Two rules this screen has to hold:
 *
 *   1. a laundry price attracts the tax components (VAT, NHIL, GETFund, tourism levy), and
 *      the counter can see the tax and the payable without doing sums in their head;
 *   2. an in-house guest can either put the order on the room folio or settle it there and
 *      then — like the parking exit, the club desk and the outlet screens — and the direct
 *      tender block only takes part in the form when it is actually being used.
 *
 * Renders are the real blade files against deploy/dev/preview/laundry-sample.mjs.
 *
 *   node deploy/dev/audit/laundry_preview_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) passed += 1;
  else failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

const read = (rel) => readFileSync(rel, 'utf8');
const load = (file) => {
  const dom = new JSDOM(read(`deploy/dev/previews/${file}`), { url: `https://preview.local/${file}`, runScripts: 'dangerously', pretendToBeVisual: true });
  return dom.window.document;
};
const text = (doc, selector) => (doc.querySelector(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const num = (value) => Number(String(value).replace(/[^\d.]/g, ''));

// ── the order screen ────────────────────────────────────────────────────────
const orderHtml = read('deploy/dev/previews/laundry-order.html');
const order = load('laundry-order.html');

ok('the order screen is a whole document', /^<!doctype html>/i.test(orderHtml.trimStart()));
ok('no blade directive is left on the order screen', !/@(endphp|endforeach|endsection|endif|endpush)\b/.test(orderHtml));
ok('the screen names the order', /LND-000412/.test(order.title), order.title);

// Tax is on the money panel, with the payable first.
ok('the panel leads with what the guest pays', num(text(order, '#loPayable')) === 834.9, text(order, '#loPayable'));
const panel = text(order, '#loPayable') && order.querySelector('#loPayable').closest('.right').textContent.replace(/\s+/g, ' ').trim();
ok('the panel says the payable includes tax', /INCL\. 21% TAX/.test(panel), panel.slice(0, 80));
ok('the panel shows the net and the tax', /690\.00 \+ tax 144\.90/.test(panel), panel);
ok('the old "amount before tax" heading is gone', !/AMOUNT \(BEFORE TAX\)/.test(orderHtml));

// A delivered order's ledger line states the tax the bill carried and how it was settled —
// this order is still open, so the assertion is on the template that renders it.
const showBlade = read('resources/views/laundry/show.blade.php');
ok('the charge line states its rate and total',
  /money\(\$o->folioTransaction->tax_amount\).*tax .*money\(\(float\) \$o->folioTransaction->amount \+ \(float\) \$o->folioTransaction->tax_amount\)/.test(showBlade));
ok('a direct payment reads as settled, not charged', /\$o->settledDirectly\(\) \? 'Settled' : 'Charged to'/.test(showBlade));
ok('the delivery times are real dates', !/NaN/.test(order.body.textContent), (order.body.textContent.match(/.{0,40}NaN.{0,40}/) ?? [''])[0]);

// The itemising grid carries the rate so the footer can price as you type.
const grid = order.querySelector('#laundryGrid');
ok('the itemising grid knows the laundry tax rate', grid?.dataset.taxRate === '21', grid?.dataset.taxRate);
ok('the grid names the tax components', /VAT 15%/.test(grid?.dataset.taxLabel ?? ''), grid?.dataset.taxLabel);
ok('priced cells are filled in from the order', order.querySelector('input[name="q[1][wash]"]')?.value === '2');

// The delivery dialog offers both settlement routes.
const settle = order.querySelector('[data-laundry-settle]');
const settleOptions = [...(settle?.querySelectorAll('option') ?? [])].map((option) => option.value);
ok('the delivery dialog offers room or counter', settleOptions.join(',') === 'room,pay', settleOptions.join(','));
ok('posting to the room names the room', /Charge to room 202's folio/.test(text(order, '[data-laundry-settle]')));
ok('paying now names the amount and the invoice', /Pay now – GH₵ 834\.90 at the counter, issue an invoice/.test(text(order, '[data-laundry-settle]')));
ok('the dialog repeats the tax it is asking for', /690\.00 \+ 144\.90 tax = GH₵ 834\.90/.test(text(order, '#deliverModal')), text(order, '#deliverModal').slice(0, 140));
ok('the dialog says what the tax is made of', /VAT 15% \+ NHIL 2\.5% \+ GETFund levy 2\.5% \+ Tourism levy 1%/.test(text(order, '#deliverModal')));

// The counter tender block exists, is complete, and is inert until "pay now" is chosen.
const direct = order.querySelector('[data-laundry-direct]');
ok('there is a counter tender block', Boolean(direct));
ok('it starts hidden, because the room is the default', direct?.hidden === true);
const tenderFields = [...(direct?.querySelectorAll('input, select, textarea') ?? [])];
ok('a hidden tender block submits nothing', tenderFields.length > 0 && tenderFields.every((field) => field.disabled), `${tenderFields.length} fields`);
ok('the tender block offers payment modes', (direct?.querySelectorAll('select[name="payment_mode_id"] option') ?? []).length >= 5);
ok('mobile money details are collected', Boolean(direct?.querySelector('[name="momo_network"]')));
ok('card / cheque details are collected', Boolean(direct?.querySelector('[name="cheque_bank_name"]')));
ok('split tender is available', Boolean(direct?.querySelector('[data-split-tender-widget]')));

// Choosing "pay now" wakes the block up and puts it on the page.
settle.value = 'pay';
settle.dispatchEvent(new order.defaultView.Event('change', { bubbles: true }));
ok('choosing "pay now" shows the tender block', direct.hidden === false);
ok('choosing "pay now" makes its fields live', [...direct.querySelectorAll('input, select, textarea')].every((field) => !field.disabled));
ok('the payment mode is required when paying', direct.querySelector('select[name="payment_mode_id"]')?.required === true);
settle.value = 'room';
settle.dispatchEvent(new order.defaultView.Event('change', { bubbles: true }));
ok('going back to the room hides it again', direct.hidden === true
  && [...direct.querySelectorAll('input, select, textarea')].every((field) => field.disabled));

// Nothing anywhere promises "charged to the room" regardless of the choice.
ok('the deliver button does not presume the room', /Deliver & settle/.test(text(order, '#loDeliver')), text(order, '#loDeliver'));
ok('the explanation covers paying at the counter', /if the guest settles at the counter/.test(text(order, '.card-body.small.muted') || orderHtml));

// ── the price list ──────────────────────────────────────────────────────────
const prices = load('laundry-prices.html');
ok('the price list shows the list price', /690/.test(prices.body.textContent) || prices.querySelectorAll('#priceTable tbody tr').length > 5);
ok('the price list states the laundry tax rate', /21\s*%/.test(text(prices, '#priceTax')), text(prices, '#priceTax').slice(0, 60));
ok('the price list names the components', /VAT 15%/.test(text(prices, '#priceTax')));
ok('the price list says whether prices carry the tax', /before tax|tax-inclusive/.test(text(prices, '#priceTax')));
ok('the price list points at where the rate is set', /Masters › Taxes/.test(text(prices, '#priceTax')));
ok('no blade directive is left on the price list', !/@(endphp|endforeach|endsection|endif|endpush)\b/.test(read('deploy/dev/previews/laundry-prices.html')));

// ── once the guest has paid ─────────────────────────────────────────────────
const settled = load('laundry-settled.html');
const settledText = settled.body.textContent;
ok('a settled order says it is delivered', text(settled, '#loStatus') === 'Delivered', text(settled, '#loStatus'));
ok('a settled order reads as settled, not charged', /Settled/.test(text(settled, '.kv-grid')), text(settled, '.kv-grid').slice(0, 60));
ok('it names the invoice the guest was given', /invoice INV-002241/.test(text(settled, '.kv-grid')));
ok('it states the tax the line carried', /690\.00 \+ GH₵ 144\.90 tax \(21%\) = GH₵ 834\.90/.test(text(settled, '.kv-grid')), text(settled, '.kv-grid').slice(-180));
ok('it names how it was paid', /paid across the counter by Mobile Money — MTN/.test(text(settled, '.kv-grid')));
ok('a delivered order cannot be delivered again', !settled.querySelector('#loDeliver'));
ok('the timeline says it was paid', /Delivered.*paid/.test(text(settled, '#loTimeline')));
ok('the items footer adds up to the payable', /Subtotal 690\.00 \+ tax 144\.90 = GH₵ 834\.90/.test(text(settled, 'tfoot')), text(settled, 'tfoot'));
ok('no unresolved expression is left on the settled screen', !/NaN|undefined|\[object Object\]/.test(settledText));

// ── the desk list ───────────────────────────────────────────────────────────
const list = load('laundry-list.html');
const headers = [...list.querySelectorAll('#laundryTable thead th')].map((cell) => cell.textContent.trim());
ok('the list column is the payable, not a pre-tax amount', headers.includes('Payable') && !headers.includes('Amount'), headers.join(' | '));
const payables = [...list.querySelectorAll('#laundryTable tbody tr')].map((row) => row.querySelector('td.num.bold')?.textContent.replace(/\s+/g, ' ').trim() ?? '');
ok('the list prices the order with its tax', payables[0].startsWith('GH₵ 834.90'), payables[0]);
ok('the list says what the payable is made of', /690\.00 \+ tax/.test(payables[0]), payables[0]);
ok('an order with nothing itemised shows no money', payables.includes('—'));
ok('the list has no unresolved expression', !/NaN|undefined|\[object Object\]/.test(list.body.textContent));

// ── the service behind the screen ───────────────────────────────────────────
const service = read('app/Services/LaundryService.php');
ok('the service quotes tax through the tax engine', /TaxService::splitPrice\(\$listed, null, 'laundry'\)/.test(service));
ok('the service respects inclusive pricing', /'inclusive' => TaxService::pricesInclusive\(\)/.test(service));
ok('delivery accepts a settle choice', /array \$payments = \[\], \?string \$settle = null/.test(service));
ok('a walk-in always settles on delivery', /\$direct = \$o->type === 'walk_in' \|\| \$settle === 'pay'/.test(service));
ok('the guest keeps their own guest record when paying', /openStandalone\(\['guest_id' => \$guestId/.test(service));
ok('the net of tax is what gets posted', /FolioService::charge\(\$folio, 'laundry', \$desc, \$quote\['net'\]/.test(service));

// Every surface that shows a laundry price has to carry the tax with it: the guest's
// portal page, the bag docket, the price list and the desk list.
const portalController = read('app/Http/Controllers/GuestPortalController.php');
ok('the guest portal is given the laundry tax', /'tax' => \\App\\Services\\LaundryService::taxInfo\(\)/.test(portalController));
const portalBlade = read('resources/views/portal/requests.blade.php');
ok('the portal names the tax on the request form', /\$laundry\['tax'\]\['label'\]/.test(portalBlade));
ok('the portal offers paying at the desk', /paid at the desk when it comes back/.test(portalBlade));
ok('the portal shows what a laundry order came to', /LaundryService::quote\(\$lo\)/.test(portalBlade));
ok('the docket controller prices the docket', /'quote' => LaundryService::quote\(\$lorder\)/.test(read('app/Http/Controllers/LaundryController.php')));
ok('the price list gets the tax card data', /'tax' => LaundryService::taxInfo\(\)\]\)/.test(read('app/Http/Controllers/LaundryController.php')));
ok('the create screen gets the tax for its grid', /'tax' => LaundryService::taxInfo\(\),/.test(read('app/Http/Controllers/LaundryController.php')));
ok('the desk list prices each row', /LaundryService::quote\(\$o\)/.test(read('resources/views/laundry/index.blade.php')));

const controller = read('app/Http/Controllers/LaundryController.php');
ok('the controller validates the settle choice', /'settle' => 'nullable\|in:room,pay'/.test(controller));
ok('the controller hands the choice to the service', /\$payments, \$data\['settle'\] \?\? null\)/.test(controller));
ok('the flash says which happened', /\$o->settledDirectly\(\) \? " received/.test(controller));

console.log(`laundry tax & settlement: ${passed} assertions passed${failures.length ? `, ${failures.length} failed` : ''}`);
for (const failure of failures) console.error(`  FAIL: ${failure}`);
process.exit(failures.length ? 1 : 0);
