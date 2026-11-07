#!/usr/bin/env node
/*
 * The room TV, worked with the remote.
 *
 * public/js/tv.js is the real client: it is booted in jsdom with a room's boot payload and a stub transport, then the
 * dial is walked the way a guest walks it — home, In-room dining, a dish that comes with a side dish, the side picked
 * and counted twice, the order sent. What this check asserts is what the TV would post.
 *
 * Run: node deploy/dev/audit/tv_dining_check.js
 * Needs jsdom: npm install --no-save jsdom   (it skips itself when that is absent)
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('room TV dining: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

let failures = 0;
let passed = 0;
const ok = (label, condition, detail = '') => {
  if (condition) {
    passed += 1;
    console.log(`  ✓ ${label}`);
    return;
  }
  failures += 1;
  console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ''}`);
};

// ── the room the TV is in ───────────────────────────────────────────────────
const T = {
  dining: 'In-room dining', home: 'Home', back: 'Back', add: 'Add to order', cancel: 'Cancel', order: 'Place order',
  cart: 'Your order', total: 'Total', sent: 'Sent', side: 'Side dish', choose_side: 'This dish comes with a side dish – choose one.',
  no_side: 'No side dish', included: 'included', required_side: 'required', unavailable: 'Not available right now.',
};
const BOOT = {
  mode: 'room', lang: 'en', t: T, hotel: { name: 'Nowills Grand' }, guest: { room: '204', name: 'Ama Mensah' },
  features: { dining: true }, unread: 0, desk_messages: 0, welcome: { show: false }, heartbeat: 600,
};

const SIDES = [{ id: 501, name: 'Garden salad', rate: 0 }, { id: 502, name: 'French fries', rate: 25 }];
const dish = (o) => Object.assign({ veg: false, image: null, price: 'GH₵ 0.00', raw: 0, modifiers: [], sides: [], side_required: false }, o);
const DINING = {
  outlet: 'Garden Restaurant',
  cats: [{
    name: 'Local dishes',
    items: [
      dish({ id: 11, name: 'Banku & Okro Stew', price: 'GH₵ 58.00', raw: 58, sides: SIDES, side_required: true }),
      dish({
        id: 12, name: 'Waakye special', price: 'GH₵ 40.00', raw: 40, sides: [{ id: 503, name: 'Extra shito', rate: 5 }],
        modifiers: [{ id: 7, name: 'Spice level', min: 1, max: 1, required: true, options: [{ id: 71, name: 'Mild', price_delta: 0 }] }],
      }),
      dish({ id: 13, name: 'Bottled water', price: 'GH₵ 6.00', raw: 6 }),
    ],
  }],
};

const SHELL = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Nowills Grand</title></head>
<body class="tv"><div id="tvApp" data-base="/tv/token">
  <script type="application/json" id="tvBoot">${JSON.stringify(BOOT)}</script>
</div></body></html>`;

const dom = new JSDOM(SHELL, { url: 'https://tv.test/tv/token', runScripts: 'outside-only', pretendToBeVisual: true });
const { window } = dom;
const { document } = window;

// the transport: every request is recorded, and answered from the fixtures above
const sent = [];
window.__requests = sent;
window.__respond = (req) => {
  if (req.url.endsWith('/data/dining')) return { status: 200, body: DINING };
  if (req.url.endsWith('/order')) return { status: 200, body: { number: 'RS-0001' } };
  if (req.url.endsWith('/state')) return { status: 200, body: BOOT };
  return { status: 404, body: { error: 'not found' } };
};
window.XMLHttpRequest = class {
  constructor() { this.readyState = 0; this.status = 0; this.responseText = ''; }
  open(method, url) { this.method = method; this.url = url; this.readyState = 1; }
  setRequestHeader() {}
  send(body) {
    const req = { method: this.method, url: this.url, body: body ? JSON.parse(body) : null };
    sent.push(req);
    const res = window.__respond(req);
    setTimeout(() => {
      this.readyState = 4;
      this.status = res.status;
      this.responseText = JSON.stringify(res.body);
      if (this.onreadystatechange) this.onreadystatechange();
    }, 0);
  }
};

// jsdom exposes SVGElement.className as a read-only SVGAnimatedString; every TV browser accepts a string, so the
// harness restores the setter rather than change the client
Object.defineProperty(window.SVGElement.prototype, 'className', {
  configurable: true,
  get() { return this.getAttribute('class') || ''; },
  set(value) { this.setAttribute('class', value); },
});

window.eval(read('public/js/tv.js'));

const settle = () => new Promise(done => setTimeout(done, 25));
const click = (node) => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
const byText = (root, text) => [...root.querySelectorAll('button, .tv-dish, .tv-tile')].find(node => node.textContent.includes(text));
const modal = () => document.querySelector('.tv-modal');
const sideRows = () => [...document.querySelectorAll('.tv-modal .tv-side-row')];
const sideRow = name => sideRows().find(row => row.textContent.includes(name));
const sideCount = name => Number(sideRow(name).querySelector('.tv-side-q').textContent.replace('×', ''));
const step = (name, dir) => click(sideRow(name).querySelectorAll('.tv-q')[dir === '+' ? 1 : 0]);
const cartLines = () => [...document.querySelectorAll('.tv-cart-l')];
const confirmBtn = () => [...document.querySelectorAll('.tv-modal .tv-row button')][0];
const toastText = () => document.querySelector('.tv-toast').textContent;

console.log('room TV — in-room dining side dishes');
await settle();

// ── the dial reaches in-room dining ────────────────────────────────────────
const tile = byText(document.getElementById('tvApp'), 'In-room dining');
ok('the home screen offers in-room dining', !!tile);
click(tile);
await settle();
ok('the dining screen is drawn from the TV payload', document.querySelectorAll('.tv-dish').length === 3, `${document.querySelectorAll('.tv-dish').length} dishes`);
const banku = [...document.querySelectorAll('.tv-dish')].find(node => node.textContent.includes('Banku'));
ok('a dish that comes with a side dish says so on its tile', banku.textContent.includes('Side dish · required'));
ok('a dish with no side dishes says nothing', [...document.querySelectorAll('.tv-dish')].find(n => n.textContent.includes('water')).textContent.trim().endsWith('GH₵ 6.00'));

// ── the side-dish step ─────────────────────────────────────────────────────
click(banku);
await settle();
ok('the side-dish step opens before the dish is added', !!modal() && modal().textContent.includes('· Side dish'));
ok('both side dishes are offered', sideRows().length === 2, `${sideRows().length} rows`);
ok('the included side dish reads as included', sideRow('Garden salad').textContent.includes('included'));
ok('the charged side dish shows its rate', sideRow('French fries').textContent.includes('+25.00'));
ok('the step starts uncounted', sideCount('French fries') === 0 && sideCount('Garden salad') === 0);
ok('the confirm button asks for the side dish while none is chosen', confirmBtn().textContent === 'Side dish');

click(confirmBtn());
await settle();
ok('a required side dish cannot be skipped', !!modal() && toastText().includes('choose one'), toastText());

step('French fries', '+');
await settle();
ok('+ counts one portion', sideCount('French fries') === 1);
step('French fries', '+');
await settle();
ok('and a second', sideCount('French fries') === 2);
ok('the step stays open while the count is set', !!modal());
ok('a chosen side dish unlocks the order', confirmBtn().textContent === 'Add to order');
step('Garden salad', '+');
step('French fries', '−');
await settle();
ok('− takes a portion back off', sideCount('French fries') === 1);
step('French fries', '+');
await settle();

// ── the cart ───────────────────────────────────────────────────────────────
click(confirmBtn());
await settle();
ok('the step closes and the dish lands in the order', !modal() && cartLines().length === 1);
ok('the line reads “French fries ×2”', cartLines()[0].textContent.includes('French fries ×2'), cartLines()[0].textContent);
ok('the included side dish is named without a price', cartLines()[0].textContent.includes('Garden salad'));
ok('the double portion is charged twice', document.querySelector('.tv-total').textContent.includes('108.00'), document.querySelector('.tv-total').textContent);
ok('the same dish with neither side dish is not offered as a shortcut', document.querySelectorAll('.tv-cart .tv-q').length === 2);

// ── the order it sends ─────────────────────────────────────────────────────
click(byText(document.querySelector('.tv-cart'), 'Place order'));
await settle();
const order = sent.filter(req => req.url.endsWith('/order')).pop();
ok('the order reaches the server', !!order && order.method === 'POST');
ok('one line was posted', order.body.items.length === 1, JSON.stringify(order.body.items));
ok('the side dishes are posted unit by unit', JSON.stringify(order.body.items[0].variants) === '[501,502,502]', JSON.stringify(order.body.items[0].variants));
ok('the dish is ordered once', order.body.items[0].qty === 1);
ok('the cart is emptied after sending', cartLines().length === 0);

// ── sides and options on one dish ──────────────────────────────────────────
const waakye = [...document.querySelectorAll('.tv-dish')].find(node => node.textContent.includes('Waakye'));
click(waakye);
await settle();
ok('a dish with sides and options asks for the side dish first', !!modal() && modal().textContent.includes('· Side dish'));
step('Extra shito', '+');
click(confirmBtn());
await settle();
ok('then for its options', !!modal() && modal().textContent.includes('Spice level') && !modal().textContent.includes('· Side dish'));
click(byText(modal(), 'Mild'));
click(confirmBtn());
await settle();
click(byText(document.querySelector('.tv-cart'), 'Place order'));
await settle();
const both = sent.filter(req => req.url.endsWith('/order')).pop().body.items[0];
ok('sides and options travel together', JSON.stringify(both.variants) === '[503]' && JSON.stringify(both.modifiers) === '[71]', JSON.stringify(both));

// ── a dish with neither goes straight in ───────────────────────────────────
const water = [...document.querySelectorAll('.tv-dish')].find(node => node.textContent.includes('water'));
click(water);
await settle();
ok('a plain dish is added without a step', !modal() && cartLines().length === 1);
click(byText(document.querySelector('.tv-cart'), 'Place order'));
await settle();
const plain = sent.filter(req => req.url.endsWith('/order')).pop().body.items[0];
ok('and posts no side dishes', JSON.stringify(plain.variants) === '[]' && JSON.stringify(plain.modifiers) === '[]');

// ── the server keeps the same promise ─────────────────────────────────────
const service = read('app/Services/IptvService.php');
ok('the TV menu publishes the side dishes', service.includes("'sides' => $i->variants->map("));
ok('and says on each dish whether a side dish is owed', service.includes("'side_required' => (bool) $i->side_dish_required,"));
ok('the TV order counts side dishes instead of de-duplicating them', service.includes("$sideIds = array_map('intval', (array) ($l['variants'] ?? []));"));
ok('the options are still de-duplicated', service.includes("array_values(array_unique(array_map('intval', (array) ($l['modifiers'] ?? []))));"));
ok('a dish that owes a side dish is refused by the server too', service.includes("GuestPortalService::orderDining($stay, $clean, null, 'tv', true)"));
ok('the TV has the words for the step in every language', ['side', 'choose_side', 'no_side', 'included', 'required_side'].every(key =>
  (read('app/Support/TvLang.php').match(new RegExp(`'${key}' =>`, 'g')) || []).length === 6));

window.close();
console.log(failures ? `\n${failures} assertion(s) failed (${passed} passed)` : `\nroom TV dining: OK (${passed} assertions)`);
process.exit(failures ? 1 : 0);
