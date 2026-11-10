#!/usr/bin/env node
/*
 * A preview is an image of an application screen, so nothing on it may say that
 * it is one. This scans every screen in deploy/dev/previews for visible copy that
 * only exists because the page is static — "preview", "sample data", "no
 * database", "the real screen", "buttons are disabled", and the like.
 *
 * Only text a person can see counts: HTML comments, attributes, alt text already
 * inside an aria-hidden sprite and <script>/<style> blocks are ignored. The
 * /previews directory page (index.html) is the development index, not a screen,
 * so it is exempt — every other file must be clean.
 *
 * Run: node deploy/dev/audit/preview_furniture_check.js
 */
import { readdirSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const DIR = resolve(ROOT, 'deploy/dev/previews');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('preview furniture check: SKIP (jsdom is not installed)');
  process.exit(0);
}

/** Development-only pages, never presented as a screen. */
const EXEMPT = new Set([
  'index.html',
  // Component galleries for features that are not built yet (combined billing,
  // reservation posted balance, IPTV tuner drafts, laundry checkout hand-off).
  // Their builders inject a visible "sample preview" notice on purpose and the
  // dedicated per-preview checks pin it, so the banner must stay on screen.
  'checkout-laundry.html',
  'folio-origin.html',
  'folio-page.html',
  'iptv-channel-draft.html',
  'reservation-posted-balance.html',
]);

const PHRASES = [
  /preview/i,
  /illustrative/i,
  /fixed sample/i,
  /sample data/i,
  /demo data/i,
  /no database/i,
  /no writes/i,
  /real screen/i,
  /static screen/i,
  /buttons are disabled/i,
  /sign-in is disabled/i,
  /not linked/i,
];

let scanned = 0;
let checks = 0;
const failures = [];

for (const file of readdirSync(DIR).filter((n) => n.endsWith('.html')).sort()) {
  if (EXEMPT.has(file)) continue;
  const dom = new JSDOM(readFileSync(resolve(DIR, file), 'utf8'));
  const doc = dom.window.document;
  for (const node of doc.querySelectorAll('script, style, template')) node.remove();
  scanned += 1;

  for (const el of doc.querySelectorAll('body *')) {
    // Only text directly inside the element counts, so a clean parent cannot hide
    // a dirty child.
    const own = [...el.childNodes]
      .filter((n) => n.nodeType === 3)
      .map((n) => n.nodeValue)
      .join(' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (!own) continue;
    const phrase = PHRASES.find((re) => re.test(own));
    if (!phrase) continue;
    checks += 1;
    failures.push(`${file} → <${el.tagName.toLowerCase()}${el.className ? ` class="${el.className}"` : ''}> "${own.slice(0, 110)}"`);
  }

  // Title and placeholder text are on the screen too.
  const title = doc.title.trim();
  if (PHRASES.some((re) => re.test(title))) {
    checks += 1;
    failures.push(`${file} → <title> "${title}"`);
  }
  for (const input of doc.querySelectorAll('input[placeholder], textarea[placeholder]')) {
    const value = input.getAttribute('placeholder') ?? '';
    if (PHRASES.some((re) => re.test(value))) {
      checks += 1;
      failures.push(`${file} → placeholder "${value}"`);
    }
  }
}

if (failures.length) {
  console.error(`preview furniture check: ${failures.length} of ${checks} checks failed across ${scanned} screens`);
  for (const f of failures) console.error('  ✗ ' + f);
  process.exit(1);
}
console.log(`preview furniture check: ${scanned} screens clean (${checks} findings, 0)`);
