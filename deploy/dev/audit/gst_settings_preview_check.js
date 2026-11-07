#!/usr/bin/env node
/*
 * The GRA VAT settings screen decides whether this property's invoices are stamped at all, and
 * with what. Two ways it could mislead: printing the stored security key back into the form, and
 * letting a property believe it is filing with GRA while the sandbox simulator is generating the
 * stamps. Both are checked here, in both switch states.
 *
 * This renders deploy/dev/previews/gst-settings.html (E-VAT on, sandbox connection) and
 * gst-settings-off.html (E-VAT off) in jsdom against deploy/dev/preview/gst-settings-sample.mjs.
 *
 * Run: node deploy/dev/audit/gst_settings_preview_check.js
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
  const dom = new JSDOM(raw, { url: 'https://preview.local/gst/settings' });
  const doc = dom.window.document;
  const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  return { raw, doc, text, $: (s) => doc.querySelector(s), $$: (s) => [...doc.querySelectorAll(s)] };
};

const on = load('deploy/dev/previews/gst-settings.html');
const off = load('deploy/dev/previews/gst-settings-off.html');

/* ── the screen ──────────────────────────────────────────────────────────── */
ok('the page is titled for GRA VAT and E-VAT', on.doc.title.startsWith('GRA VAT'), on.doc.title);
ok('the section navigation is on the screen', Boolean(on.$('#gstNav')));
ok('and Settings is the tab in force', /Settings/.test(on.text(on.$('#gstNav'))));

/* ── registration ────────────────────────────────────────────────────────── */
const rows = on.$$('.bq-list li').map(li => on.text(li.querySelector('span')));
ok('the registration card lists the property details', rows.length >= 8, `${rows.length} rows`);
ok('the TIN is shown with its validity', /C0001234567/.test(on.text(on.doc.body)) && /valid/.test(on.text(on.doc.body)));
ok('the VAT status is stated, not implied', /Registered/.test(on.text(on.$('#vatStatus'))), on.text(on.$('#vatStatus')));
ok('with the date it registered', /since 01 Jul 2024/.test(on.text(on.$('#vatStatus'))), on.text(on.$('#vatStatus')));
ok('the threshold the test uses is printed', /200,000/.test(on.text(on.doc.body)));
ok('this period\'s taxable supplies are shown', /GH₵ 6,184,320.50/.test(on.text(on.doc.body)));
ok('and the rolling twelve months', /GH₵ 7,402,118.75/.test(on.text(on.doc.body)));
ok('the percentage of the threshold is worked out, not guessed', /3702%/.test(on.text(on.doc.body)));
ok('the status explains what the property must do', /Charge VAT on every taxable supply/.test(on.text(on.doc.body)));

/* ── the switches ────────────────────────────────────────────────────────── */
ok('stamping is switched on in the live state', on.$('input[name=gst_einvoice_enabled]')?.checked === true);
ok('auto-stamping and outlet bills are separate switches',
  Boolean(on.$('input[name=gst_einvoice_auto]')) && Boolean(on.$('input[name=gst_evat_pos]')));
ok('the connection is a choice of exactly two drivers',
  on.$$('select[name=gst_einvoice_driver] option').map(option => option.value).join(',') === 'sandbox,gra',
  on.$$('select[name=gst_einvoice_driver] option').map(option => option.value).join(','));
ok('and the stored driver is the one selected',
  on.$('select[name=gst_einvoice_driver] option[selected]')?.value === 'sandbox');

/* ── the secret ──────────────────────────────────────────────────────────── */
const key = on.$('input[name=gst_evat_key]');
ok('the security key is a password field', key?.getAttribute('type') === 'password', key?.getAttribute('type'));
ok('and carries no stored value', (key?.getAttribute('value') ?? '') === '', key?.getAttribute('value'));
ok('a saved key is announced by its placeholder', /stored – leave blank to keep/.test(key?.getAttribute('placeholder') ?? ''));
ok('no stored key is written anywhere in the page',
  !/stored-secret-placeholder/.test(on.raw) && !/(?:sk_live_|live_sec_)[A-Za-z0-9]{6,}/.test(on.raw));

/* ── the warning the screen used to hide ─────────────────────────────────── */
ok('a sandbox connection says the stamps are not reported to GRA',
  Boolean(on.$('#evatSandboxNote')) && /not<\/b> reported to GRA|not reported to GRA/.test(on.text(on.$('#evatSandboxNote'))),
  on.text(on.$('#evatSandboxNote')).slice(0, 120));
ok('and says invoices are stamped as if they were', /stamped as if they were/.test(on.text(on.$('#evatSandboxNote'))));
ok('with the gateway off there is no sandbox warning', !off.$('#evatSandboxNote'));
ok('but the off state says nothing below is in use', Boolean(off.$('#evatOffNote'))
  && /nothing below is in use/.test(off.text(off.$('#evatOffNote'))), off.text(off.$('#evatOffNote')));

/* ── price entry and the tax components ──────────────────────────────────── */
const radios = on.$$('input[name=tax_prices_inclusive]');
ok('price entry is a choice, not a free text field', radios.length === 2, `${radios.length} radios`);
ok('exactly one is chosen', radios.filter(radio => radio.checked).length === 1);
ok('and it is the stored one (tax-inclusive)', radios.find(radio => radio.checked)?.value === '1');
ok('the difference between the two is spelled out',
  /Tax-exclusive/.test(on.text(on.doc.body)) && /Tax-inclusive/.test(on.text(on.doc.body)));
ok('the note says which screens the choice governs',
  /POS outlets, the recreation ticket outlet, club day passes/.test(on.text(on.doc.body)));

const taxRows = on.$$('#taxTable tbody tr');
ok('every tax component in force is listed', taxRows.length === 4, `${taxRows.length} rows`);
ok('with its rate and authority',
  on.text(taxRows[0]).includes('15%') && on.text(taxRows[0]).includes('GRA')
  && on.text(taxRows[3]).includes('1%') && on.text(taxRows[3]).includes('GTA'),
  on.text(taxRows[0]));
ok('and the Act each falls under is cited',
  /VAT Act 2025/.test(on.text(on.doc.body)) && /Act 817/.test(on.text(on.doc.body)));

/* ── nothing left unrendered, in either state ────────────────────────────── */
for (const [label, page] of [['on', on], ['off', off]]) {
  const bodyCopy = page.doc.body.cloneNode(true);
  bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
  const text = page.text(bodyCopy);
  ok(`no Blade directive survived in the ${label} state`, !/@(if|foreach|endif|endforeach|php|else|endif)\b/.test(text));
  ok(`no undefined or NaN reached the ${label} screen`, !/undefined|NaN/.test(text));
}

if (failures.length) {
  console.error(`gst settings check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`gst settings check: ${passed}/${passed} passed`);
