#!/usr/bin/env node
/*
 * Builds deploy/dev/previews/tv-dining.html from the real in-room TV client.
 *
 * public/js/tv.js is booted for a room, the dial is walked to In-room dining, a dish that comes with a side dish is
 * opened, the side is ticked and counted twice, and the order is placed. Two states are frozen side by side in
 * television frames — the side-dish step, and the order it made — so the screenshot is the client's own markup.
 *
 * Run: node deploy/dev/build-tv-dining-preview.js [--check]
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

// the TV draws a clock in its top bar: the preview is built at a fixed minute, in UTC, so the file never drifts
process.env.TZ = 'UTC';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = resolve(ROOT, 'deploy/dev/previews/tv-dining.html');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('room TV preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

// The TV's own words, straight out of the dictionary the server sends (English column).
const lang = read('app/Support/TvLang.php');
const en = lang.slice(lang.indexOf("'en' => ["), lang.indexOf("'fr' => ["));
const T = {};
for (const m of en.matchAll(/'([a-z_]+)' => '([^']*)'/g)) T[m[1]] = m[2];

const BOOT = {
  mode: 'room', lang: 'en', t: T, hotel: { name: 'Nowills Grand Hotel' }, guest: { room: '204', name: 'Ama Mensah' },
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
      dish({ id: 12, name: 'Jollof rice', price: 'GH₵ 65.00', raw: 65 }),
      dish({ id: 13, name: 'Grilled chicken', price: 'GH₵ 75.00', raw: 75, sides: SIDES }),
      dish({ id: 14, name: 'Bottled water', price: 'GH₵ 8.00', raw: 8 }),
    ],
  }],
};

const SHELL = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Nowills Grand Hotel</title></head>
<body class="tv"><div id="tvApp" data-base="/tv/token">
  <script type="application/json" id="tvBoot">${JSON.stringify(BOOT)}</script>
</div></body></html>`;

const dom = new JSDOM(SHELL, { url: 'https://tv.test/tv/token', runScripts: 'outside-only', pretendToBeVisual: true });
const { window } = dom;
const { document } = window;

window.__respond = (req) => {
  if (req.url.endsWith('/data/dining')) return { status: 200, body: DINING };
  if (req.url.endsWith('/order')) return { status: 200, body: { number: 'RS-0001' } };
  if (req.url.endsWith('/state')) return { status: 200, body: BOOT };
  return { status: 404, body: {} };
};
window.XMLHttpRequest = class {
  constructor() { this.readyState = 0; this.status = 0; this.responseText = ''; }
  open(method, url) { this.method = method; this.url = url; this.readyState = 1; }
  setRequestHeader() {}
  send(body) {
    const res = window.__respond({ method: this.method, url: this.url, body: body ? JSON.parse(body) : null });
    setTimeout(() => {
      this.readyState = 4;
      this.status = res.status;
      this.responseText = JSON.stringify(res.body);
      if (this.onreadystatechange) this.onreadystatechange();
    }, 0);
  }
};

// jsdom's SVGElement.className has no setter; every TV browser accepts a string
Object.defineProperty(window.SVGElement.prototype, 'className', {
  configurable: true,
  get() { return this.getAttribute('class') || ''; },
  set(value) { this.setAttribute('class', value); },
});

const FROZEN = new Date('2026-11-07T19:40:00Z').getTime();
const RealDate = window.Date;
window.Date = class extends RealDate {
  constructor(...args) { super(...(args.length ? args : [FROZEN])); }
  static now() { return FROZEN; }
};

window.eval(read('public/js/tv.js'));

const settle = () => new Promise(done => setTimeout(done, 25));
const click = (node) => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
const app = document.getElementById('tvApp');
const byText = (root, text) => [...root.querySelectorAll('button, .tv-dish, .tv-tile')].find(node => node.textContent.includes(text));
const step = (name, dir) => {
  const row = [...document.querySelectorAll('.tv-modal .tv-side-row')].find(r => r.textContent.includes(name));
  click(row.querySelectorAll('.tv-q')[dir === '+' ? 1 : 0]);
};

await settle();
click(byText(app, 'In-room dining'));
await settle();
click([...document.querySelectorAll('.tv-dish')].find(node => node.textContent.includes('Banku')));
await settle();
step('Garden salad', '+');
step('French fries', '+');
step('French fries', '+');
await settle();
const sides = app.outerHTML;

click([...document.querySelectorAll('.tv-modal .tv-row button')][0]);   // add, with the side dishes it carries
await settle();
const order = app.outerHTML;

const sprite = read('resources/views/tv/_icons.blade.php');

const CSS = `
  body{margin:0;background:#0b1413;color:#e7efe9;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  .wrap{max-width:1300px;margin:0 auto;padding:clamp(14px,3vw,32px)}
  h1{font-size:1.25rem;margin:0 0 .35rem;color:#fff}
  .lead{margin:0 0 1.6rem;font-size:.88rem;color:#9fb3a8;max-width:70ch}
  .frames{display:flex;gap:clamp(16px,3vw,34px);flex-wrap:wrap}
  figure{margin:0}
  figcaption{font-size:.8rem;color:#9fb3a8;margin-top:.7rem;max-width:640px}
  .tv-frame{position:relative;width:704px;height:396px;border-radius:14px;overflow:hidden;background:#101c1a;border:8px solid #050909;box-shadow:0 22px 48px rgba(0,0,0,.5)}
  .tv-frame>.screen{position:absolute;top:0;left:0;width:1280px;height:720px;transform:scale(.55);transform-origin:top left;background:#101c1a}
  .tv-frame .tv-modal{position:absolute}
  .tv-frame .tv-modal-box{max-height:none}
`;

const page = `<!doctype html>
<!-- Generated by deploy/dev/build-tv-dining-preview.js from public/js/tv.js, booted for a sample room and driven with a
     fixed dining payload. The live screen reads the hotel's own menu, side dishes and rates. -->
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Room TV · in-room dining · Nowills PMS</title>
<link rel="stylesheet" href="/css/tv.css">
<style>${CSS}</style>
</head>
<body class="tv">
${sprite}
<main class="wrap">
  <h1>In-room dining on the room TV — the guest picks the side dish</h1>
  <p class="lead">The television in room 204, driven with the remote: a dish that comes with a side dish opens the side-dish step first, the included side reads “included”, French fries can be counted twice, and a dish whose side is required will not be ordered without one. Left: the step. Right: the order it made.</p>
  <div class="frames">
    <figure><div class="tv-frame"><div class="screen">${sides}</div></div><figcaption>The side-dish step — the salad included, French fries counted twice</figcaption></figure>
    <figure><div class="tv-frame"><div class="screen">${order}</div></div><figcaption>The order: “French fries ×2” priced at 2 × 25.00, ahead of Place order</figcaption></figure>
  </div>
</main>
</body>
</html>
`;

if (process.argv.includes('--check')) {
  if (!existsSync(OUT) || readFileSync(OUT, 'utf8') !== page) {
    console.error('room TV preview is stale — run node deploy/dev/build-tv-dining-preview.js');
    process.exit(1);
  }
  console.log('room TV preview is current');
  process.exit(0);
}

writeFileSync(OUT, page);
console.log(`wrote deploy/dev/previews/tv-dining.html (${Buffer.byteLength(page)} bytes)`);
process.exit(0);
