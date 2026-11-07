#!/usr/bin/env node
/*
 * The online-payment settings screen decides whether a stranger on the internet can pay, and how
 * much the property asks for before it holds a room. Two things had to be checked rather than
 * assumed: that no secret is ever printed back to the browser, and that the screen tells the truth
 * about the deposit — OnlinePaymentService::depositPolicy() answers 'none' the moment the gateway
 * is switched off, so a deposit policy chosen here collects nothing while it is off.
 *
 * This renders both built states in jsdom: deploy/dev/previews/payments-settings.html (online
 * payments on, 30 % deposit) and payments-settings-off.html (gateway off).
 *
 * Run: node deploy/dev/audit/payments_settings_preview_check.js
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
  const dom = new JSDOM(readFileSync(file, 'utf8'), { url: 'https://preview.local/payments/settings' });
  const doc = dom.window.document;
  const text = (node) => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  return { doc, text, $: (selector) => doc.querySelector(selector), $$: (selector) => [...doc.querySelectorAll(selector)] };
};

const on = load('deploy/dev/previews/payments-settings.html');
const off = load('deploy/dev/previews/payments-settings-off.html');

/* ── the screen, as a live property sees it ──────────────────────────────── */
ok('the page is titled for online payment settings', on.doc.title.startsWith('Online Payment Settings'), on.doc.title);
const providers = on.$$('input[name=pay_provider]').map(input => input.value);
ok('every provider the service knows is offered', providers.join(',') === 'demo,paystack,stripe,razorpay', providers.join(','));
ok('exactly one provider is chosen', on.$$('input[name=pay_provider]').filter(input => input.checked).length === 1);
ok('and it is the one stored', on.$('input[name=pay_provider]:checked')?.value === 'paystack');
ok('a provider with keys says ready', on.$$('.badge-green').length === 2 && /ready/.test(on.text(on.doc.body)));
ok('a provider without keys says so rather than looking ready',
  on.$$('.badge-slate').length === 2 && /keys missing/.test(on.text(on.doc.body)));

/* ── secrets are never printed ───────────────────────────────────────────── */
const secrets = on.$$('input[type=password][name^=pay_]');
ok('each secret key has a password field', secrets.length === 5, `${secrets.length} secret fields`);
ok('and none of them carries a value', secrets.every(input => !input.getAttribute('value')),
  secrets.map(input => `${input.name}=${input.getAttribute('value')}`).join(', '));
ok('a saved key is announced by its placeholder, not by its value',
  secrets.filter(input => /saved – leave blank to keep/.test(input.getAttribute('placeholder') ?? '')).length === 1,
  secrets.map(input => input.getAttribute('placeholder')).join(' | '));
ok('and only that one can be removed',
  on.$$('input[name="clear_secrets[]"]').length === 1,
  `${on.$$('input[name="clear_secrets[]"]').length} remove boxes`);
// the labels name the key shapes ("sk_live_… / sk_test_…"), so what must not appear is a key: a
// prefix followed by real characters rather than an ellipsis
ok('no live key value reaches the page',
  !/(?:sk_live_|sk_test_|whsec_|rzp_live_)[A-Za-z0-9]{6,}/.test(readFileSync('deploy/dev/previews/payments-settings.html', 'utf8')));
ok('the page says keys are stored encrypted and never shown again',
  /Secret keys are stored encrypted and never shown again/.test(on.text(on.doc.body)));

/* ── the bands the save enforces ─────────────────────────────────────────── */
const linkHours = on.$('input[name=pay_link_hours]');
ok('a payment link prints the 1–720 hours the controller allows',
  linkHours?.getAttribute('min') === '1' && linkHours?.getAttribute('max') === '720',
  `${linkHours?.getAttribute('min')}–${linkHours?.getAttribute('max')}`);
ok('with the value in force', linkHours?.value === '72', linkHours?.value);

const hold = on.$('input[name=pay_booking_hold_minutes]');
ok('the hold prints the 15–1440 minutes the controller allows',
  hold?.getAttribute('min') === '15' && hold?.getAttribute('max') === '1440',
  `${hold?.getAttribute('min')}–${hold?.getAttribute('max')}`);
ok('with the value in force', hold?.value === '60', hold?.value);

const pct = on.$('input[name=pay_booking_deposit_pct]');
ok('the deposit prints the 1–100 % the controller allows',
  pct?.getAttribute('min') === '1' && pct?.getAttribute('max') === '100',
  `${pct?.getAttribute('min')}–${pct?.getAttribute('max')}`);
ok('and takes half-percent steps', pct?.getAttribute('step') === '0.5', pct?.getAttribute('step'));

const policy = on.$('select[name=pay_booking_deposit]');
ok('the deposit policy offers every policy the service knows',
  [...(policy?.options ?? [])].map(option => option.value).join(',') === 'none,percent,first_night,full',
  [...(policy?.options ?? [])].map(option => option.value).join(','));
ok('and the stored one is selected', policy?.querySelector('option[selected]')?.value === 'percent');
ok('the deposit field is visible while the policy is a percentage',
  !/display:\s*none/.test(on.$('input[name=pay_booking_deposit_pct]')?.closest('.field')?.getAttribute('style') ?? ''),
  on.$('input[name=pay_booking_deposit_pct]')?.closest('.field')?.getAttribute('style'));
ok('the wording says a deposit makes the booking tentative until it is paid',
  /held as tentative/.test(on.text(on.doc.body)) && /becomes confirmed/.test(on.text(on.doc.body)));

/* ── the webhooks ────────────────────────────────────────────────────────── */
const hooks = on.$$('.hook-url').map(node => on.text(node));
ok('every gateway prints its webhook URL', hooks.length === 3, `${hooks.length} webhooks`);
ok('and each points at its own provider',
  hooks[0].includes('/paystack/') && hooks[1].includes('/stripe/') && hooks[2].includes('/razorpay/'), hooks.join(' | '));
ok('the Stripe events are named so the reader can configure them',
  /checkout\.session\.completed/.test(on.text(on.doc.body)));

/* ── the dependency the screen used to hide ──────────────────────────────── */
ok('the deposit card says it only applies while payments are on',
  /applies only while online payments are on/.test(on.text(on.doc.body)));
ok('with the gateway on there is no "nothing is collected" warning',
  !/so nothing is collected/.test(on.text(on.doc.body)));

ok('the switch itself is on in the live state', on.$('input[name=pay_enabled]')?.checked === true);
ok('and off in the second state', off.$('input[name=pay_enabled]')?.checked === false);
ok('the off state says outright that nothing will be collected',
  /Online payments are off/.test(off.text(off.doc.body)) && /so nothing is collected/.test(off.text(off.doc.body)),
  off.text(off.doc.body).slice(0, 160));
ok('and that a booking is then confirmed with no deposit and no hold',
  /confirmed immediately, with no deposit and no hold/.test(off.text(off.doc.body)));
ok('the off state keeps every band and every secret field, so the form is still usable',
  off.$$('input[type=password][name^=pay_]').length === 5
  && off.$('input[name=pay_link_hours]')?.getAttribute('max') === '720');

/* ── nothing left unrendered, in either state ────────────────────────────── */
for (const [label, page] of [['on', on], ['off', off]]) {
  const bodyCopy = page.doc.body.cloneNode(true);
  bodyCopy.querySelectorAll('script, style').forEach(node => node.remove());
  const text = page.text(bodyCopy);
  ok(`no Blade directive survived in the ${label} state`, !/@(if|foreach|endif|endforeach|php|elseif|style)\b/.test(text));
  ok(`no undefined or NaN reached the ${label} screen`, !/undefined|NaN/.test(text));
}

if (failures.length) {
  console.error(`payments settings check: ${passed}/${passed + failures.length} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`payments settings check: ${passed}/${passed} passed`);
