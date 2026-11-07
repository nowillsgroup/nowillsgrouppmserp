#!/usr/bin/env node
/*
 * Builds deploy/dev/previews/guest-dining.html from the real in-room dining page.
 *
 * The guest portal's dining screen is rendered, its own script is run against a sample menu, and the guest's own taps
 * are replayed: ADD on a dish that comes with a side dish, a side dish ticked and counted twice, the order reviewed.
 * Two states are frozen side by side, in phone frames — the side-dish step, and the order it produced. Nobody types
 * this markup by hand, so the preview cannot drift from the screen.
 *
 * Run: node deploy/dev/build-guest-dining-preview.js [--check]
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = resolve(ROOT, 'deploy/dev/previews/guest-dining.html');
const read = path => readFileSync(resolve(ROOT, path), 'utf8');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('guest dining preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

const BLADE = read('resources/views/portal/dining.blade.php');

// ── the menu the sample room is looking at ──────────────────────────────────
const card = ({ id, name, description, price, variants = [], required = false }) =>
  `<div class="qm-item" data-id="${id}" data-name="${name}" data-price="${price}" data-modifiers="[]" data-variants='${JSON.stringify(variants)}' data-side-required="${required ? '1' : '0'}">
     <div class="info"><div class="nm"><span class="veg-dot veg"></span> ${name}</div>${description ? `<div class="ds">${description}</div>` : ''}<div class="pr">${price.toFixed(2)}</div>${variants.length ? `<div class="tiny muted">Served with a side dish${required ? ' – please choose one' : ''}</div>` : ''}</div>
     <div class="ctl"><button type="button" class="qm-add">ADD</button></div>
   </div>`;

const SIDES = [{ id: 501, name: 'Garden salad', price_delta: 0 }, { id: 502, name: 'French fries', price_delta: 25 }];
const CATEGORIES = [
  {
    id: 1, name: 'Local dishes', items: [
      card({ id: 11, name: 'Banku & Okro Stew', description: 'Banku, okro stew, grilled tilapia', price: 58, variants: SIDES, required: true }),
      card({ id: 12, name: 'Jollof rice', description: 'Smoky jollof, chicken, fried plantain', price: 65 }),
    ],
  },
  {
    id: 2, name: 'Grills & light', items: [
      card({ id: 13, name: 'Grilled chicken', description: 'Half chicken, pepper sauce', price: 75, variants: SIDES }),
      card({ id: 14, name: 'Bottled water', price: 8 }),
    ],
  },
];

function menu() {
  const nav = `<nav class="qm-cats">${CATEGORIES.map(c => `<a href="#cat${c.id}">${c.name}</a>`).join('')}</nav>`;
  const sections = CATEGORIES.map(c => `<section class="qm-cat" id="cat${c.id}"><h2>${c.name}</h2>${c.items.join('\n')}</section>`).join('\n');
  return nav + sections;
}

function buildPage() {
  const start = BLADE.indexOf("@section('content')") + "@section('content')".length;
  const end = BLADE.indexOf("@push('scripts')");
  let html = BLADE.slice(start, end);
  html = html.replace(/@if\(\$orders[\s\S]*?@endif/, '');
  html = html.replace(/@if\(\$cats[\s\S]*?@endforelse/, menu());
  html = html.replace(/@forelse[\s\S]*?@endforelse/, '');
  html = html.replace(/\{\{[\s\S]*?\}\}/g, '');
  html = html.replace(/@(csrf|if|endif)\b[^\n]*/g, '');
  html = html.replace(/<x-icon[^>]*\/>/g, '');
  return `<!doctype html><html><head><meta charset="utf-8"></head><body>${html}</body></html>`;
}

function bladeScript() {
  const script = BLADE.slice(BLADE.indexOf('<script>') + 8, BLADE.indexOf('</script>'));
  return script
    .replace(/@json\((?:[^()]|\([^()]*\))*\)/g, "'GH₵'")
    .split('\n')
    .filter(line => !line.trim().startsWith('@'))
    .join('\n');
}

const dom = new JSDOM(buildPage(), { url: 'https://pms.test/portal/stay/abc/dining', runScripts: 'dangerously', pretendToBeVisual: true });
const { window } = dom;
const { document } = window;

const tag = document.createElement('script');
tag.textContent = bladeScript();
document.body.appendChild(tag);
tag.remove(); // the frames are frozen markup: no script travels with them

const click = el => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
const row = name => [...document.querySelectorAll('#sideChoices .gp-side-row')].find(r => r.textContent.includes(name));
const dish = id => document.querySelector(`.qm-item[data-id="${id}"]`);

// the guest taps the dish that comes with a side dish
click(dish(11).querySelector('.qm-add'));
const sides = row('French fries');
click(sides.querySelector('[data-d="1"]'));
click(row('French fries').querySelector('[data-d="1"]'));
click(row('Garden salad').querySelector('input[type=checkbox]'));
const step = document.body.innerHTML;

// …then adds it, and the order reads “French fries ×2” beside the included salad
click(document.getElementById('sideAdd'));
click(document.querySelector('.qm-bar button'));
const order = document.body.innerHTML;

const CSS = `
  body{margin:0;background:#e9eff6;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#0f172a}
  .wrap{max-width:1000px;margin:0 auto;padding:clamp(14px,3vw,30px)}
  h1{font-size:1.25rem;margin:0 0 .3rem} .lead{margin:0 0 1.6rem;font-size:.88rem;color:#475569}
  .frames{display:flex;gap:clamp(14px,3vw,34px);flex-wrap:wrap;justify-content:center}
  figure{margin:0}
  figcaption{font-size:.8rem;color:#475569;margin-top:.6rem;text-align:center;max-width:360px}
  .phone{position:relative;width:360px;height:720px;border-radius:30px;border:9px solid #0f172a;background:#f4f6fb;overflow:hidden;box-shadow:0 18px 40px rgba(15,23,42,.22)}
  .screen{position:relative;height:100%;overflow:hidden}
  .screen .qm-sheet{position:absolute}
  .screen .qm-bar{position:absolute;bottom:.6rem}
  .screen .qm-cats{position:static}
`;

const page = `<!doctype html>
<!-- Generated by deploy/dev/build-guest-dining-preview.js from resources/views/portal/dining.blade.php, driven by the
     page's own script with a fixed sample menu. The live page reads the tenant's own menu, sides and rates. -->
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Guest portal · in-room dining · Nowills PMS</title>
<link rel="stylesheet" href="/css/app.css">
<style>${CSS}</style>
</head>
<body>
<main class="wrap">
  <h1>In-room dining — the guest picks the side dish</h1>
  <p class="lead">The guest portal page on the guest's own phone. A dish that comes with a side dish asks for it before it joins the order; a side dish can be counted twice, and the double portion is charged twice. Left: the side-dish step. Right: the order it produced.</p>
  <div class="frames">
    <figure><div class="phone"><div class="screen">${step}</div></div><figcaption>Side dish — the salad included, French fries counted twice</figcaption></figure>
    <figure><div class="phone"><div class="screen">${order}</div></div><figcaption>The order: “French fries ×2” priced at 2 × 25.00</figcaption></figure>
  </div>
</main>
</body>
</html>
`;

if (process.argv.includes('--check')) {
  if (!existsSync(OUT) || readFileSync(OUT, 'utf8') !== page) {
    console.error('guest dining preview is stale — run node deploy/dev/build-guest-dining-preview.js');
    process.exit(1);
  }
  console.log('guest dining preview is current');
  process.exit(0);
}

writeFileSync(OUT, page);
console.log(`wrote deploy/dev/previews/guest-dining.html (${Buffer.byteLength(page)} bytes)`);
