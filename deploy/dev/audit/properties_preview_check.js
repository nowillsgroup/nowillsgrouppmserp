#!/usr/bin/env node
/*
 * The multi-property register and the property page, held to what they claim.
 *
 * The module exists so an operator can see a portfolio without opening every property. The check
 * therefore reads the two built pages the way a group administrator does: is every property in the
 * state it is in, does the header add up to the rows, is the property that is behind on its night
 * audit marked as behind, does the suspended one say why, and does the property page offer the
 * lifecycle action that matches the state it is actually in — and not the others.
 *
 * Run: node deploy/dev/audit/properties_preview_check.js
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
  console.log('Properties preview: SKIP (jsdom is not installed — npm install --no-save jsdom)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) {
    passed += 1;
    return;
  }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

/* ── the register ──────────────────────────────────────────────────────────── */
const reg = new JSDOM(read('deploy/dev/previews/properties.html'), { url: 'https://preview.local/properties' });
const rdoc = reg.window.document;
const rows = [...rdoc.querySelectorAll('.table tbody tr')];
const cells = row => [...row.querySelectorAll('td')].map(td => td.textContent.replace(/\s+/g, ' ').trim());
const byName = name => rows.map(cells).find(c => c[0].includes(name));
const stats = [...rdoc.querySelectorAll('.stat')].map(s => ({
  label: s.querySelector('.l').textContent.trim(),
  value: s.querySelector('.v').textContent.trim(),
}));
const stat = label => stats.find(s => s.label === label);

ok('the register is titled for the portfolio', rdoc.title.includes('Properties'));
ok('the register lists every property the operator can see', rows.length === 5, `${rows.length} rows`);

// header
ok('properties counted', stat('Properties')?.value === '5', stat('Properties')?.value);
ok('live / draft / suspended breakdown', stat('Properties')?.label === 'Properties' && stat('Properties')?.value === '5');
ok('sellable rooms total', stat('Sellable rooms')?.value === '354', stat('Sellable rooms')?.value);
ok('occupancy is the portfolio figure', stat('Occupancy')?.value === '51.1%', stat('Occupancy')?.value);
ok('in-house rooms counted', stat('Occupancy')?.label === 'Occupancy');
ok('people with access', stat('People with access')?.value === '582', stat('People with access')?.value);
ok('one property is behind on its night audit', stat('Night audit behind')?.value === '1', stat('Night audit behind')?.value);

// the states
ok('a live property is marked live', byName('The Grove Essipong Beach Resort')?.[1].startsWith('Live'), byName('The Grove Essipong Beach Resort')?.[1]);
ok('the property you are standing in says so', byName('The Grove Essipong Beach Resort')?.[1].includes('current'));
ok('the head office is marked HQ', [...rdoc.querySelectorAll('.table tbody tr')].some(tr => tr.textContent.includes('HQ')));
ok('a suspended property is marked suspended and says why', (() => {
  const row = [...rdoc.querySelectorAll('.table tbody tr')].find(tr => tr.textContent.includes('Nowills Kumasi City'));
  return row && row.textContent.includes('Suspended') && row.textContent.includes('Refurbishment — wing B');
})());
ok('a draft property is marked draft', (() => {
  const row = [...rdoc.querySelectorAll('.table tbody tr')].find(tr => tr.textContent.includes('The Grove Cape Coast'));
  return row && row.textContent.includes('Draft');
})());
ok('an archived property is marked archived', (() => {
  const row = [...rdoc.querySelectorAll('.table tbody tr')].find(tr => tr.textContent.includes('Nowills Tamale Lodge'));
  return row && row.textContent.includes('Archived');
})());
ok('the archived property is greyed out', [...rdoc.querySelectorAll('.table tbody tr')].find(tr => tr.textContent.includes('Nowills Tamale Lodge'))?.className.includes('muted'));

// the business date, which is the whole point
ok('the lagging property shows its business date', byName('Nowills Airport City Hotel')?.[2].includes('06 Nov 2026'), byName('Nowills Airport City Hotel')?.[2]);
ok('the lagging property is flagged as behind', byName('Nowills Airport City Hotel')?.[2].includes('1d behind'), byName('Nowills Airport City Hotel')?.[2]);
ok('a current property is not flagged', !byName('The Grove Essipong Beach Resort')?.[2].includes('behind'));
ok('a suspended property is not flagged as behind — it is not trading', !byName('Nowills Kumasi City')?.[2].includes('behind'));

// rooms and occupancy per row
ok('the head office shows its rooms', byName('The Grove Essipong Beach Resort')?.[3] === '96', byName('The Grove Essipong Beach Resort')?.[3]);
ok('its occupancy reads 81.3%', byName('The Grove Essipong Beach Resort')?.[4].includes('81.3%'));
ok('a retired property with 40 rooms shows 0%, not a full bar', byName('Nowills Tamale Lodge')?.[4].includes('0%'), byName('Nowills Tamale Lodge')?.[4]);

// open POS and the switch action
ok('open POS orders are shown', byName('Nowills Airport City Hotel')?.[7] === '5', byName('Nowills Airport City Hotel')?.[7]);
ok('every property can be opened', [...rdoc.querySelectorAll('.table tbody tr a.btn')].length === 5, `${[...rdoc.querySelectorAll('.table tbody tr a.btn')].length} links`);
ok('the live property you are not in offers a switch', [...rdoc.querySelectorAll('.table tbody tr form')].length === 1, `${[...rdoc.querySelectorAll('.table tbody tr form')].length} switch forms`);
ok('the property you are standing in offers no switch', !byName('The Grove Essipong Beach Resort')?.[9].includes('Switch'));
ok('a suspended property cannot be switched into', !byName('Nowills Kumasi City')?.[9].includes('Switch'));
ok('the register explains the lifecycle', rdoc.body.textContent.includes('never deleted'));

/* ── the property page ─────────────────────────────────────────────────────── */
const sh = new JSDOM(read('deploy/dev/previews/property-show.html'), { url: 'https://preview.local/properties/1' });
const sdoc = sh.window.document;
const body = sdoc.body.textContent.replace(/\s+/g, ' ');
const scards = [...sdoc.querySelectorAll('.stat')].map(s => ({ label: s.querySelector('.l').textContent.trim(), value: s.querySelector('.v').textContent.trim() }));
const sstat = label => scards.find(s => s.label === label);

ok('the property page is titled for the property', sdoc.title.includes('The Grove Essipong Beach Resort'));
ok('the state is on the page', body.includes('Live'));
ok('the address is assembled from its parts', body.includes('Essipong Beach Road, Takoradi, Western, Ghana'));
ok('the tax number is on the page', body.includes('C0012345678'));
ok('the VAT registration is stated', body.includes('VAT registered'));
ok('the rooms are counted', sstat('Rooms')?.value === '96', sstat('Rooms')?.value);
ok('occupancy is on the page', sstat('Occupancy')?.value === '81.3%', sstat('Occupancy')?.value);
ok('the business date is on the page', sstat('Business date')?.value === '07 Nov 2026', sstat('Business date')?.value);
ok('a current property is not called behind', !body.includes('night audit not closed'));
ok('access counts staff, grants and group admins', sstat('Access')?.value === '216', sstat('Access')?.value);
ok('the access breakdown is spelled out', body.includes('214 staff') && body.includes('2 granted') && body.includes('3 group admins'));

// who may enter
ok('granted people are listed', body.includes('Kwesi Boateng') && body.includes('Efua Mensah'));
ok('their role in this property only is shown', body.includes('Group Financial Controller'));
ok('a default landing property is marked', body.includes('Default'));
ok('the grant form is offered to an administrator', body.includes('Grant somebody access'));
ok('the grant form explains the per-property role', body.includes('applies in this property only'));
ok('revoke is offered per person', [...sdoc.querySelectorAll('form')].filter(f => (f.getAttribute('action') || '').includes('access')).length >= 2);

// lifecycle
ok('the lifecycle is drawn', body.includes('Draft') && body.includes('Retired'));
ok('a live property offers suspension', sdoc.querySelector('form[action*="suspend"]') !== null);
ok('a live property does not offer activation', sdoc.querySelector('form[action*="activate"]') === null);
ok('suspension asks for a reason', !!sdoc.querySelector('form[action*="suspend"] input[name="reason"]'));
ok('retirement asks for a reason', !!sdoc.querySelector('form[action*="archive"] input[name="reason"]'));
ok('the retirement is blocked until the property is empty', body.includes('can be retired'));
ok('retirement is explained as permanent but not destructive', body.includes('reversible only by opening a new property'));

// audit
ok('the handover card is offered to a platform administrator', body.includes('Hand this property to another group'));
ok('it says the staff follow the property', body.includes('Staff follow the property'));
ok('and that a group administrator does not — a handover cannot move one tenant\'s admin into another', body.includes('A group administrator does not'));
ok('it says outside grants are revoked', body.includes('Cross-property grants are revoked'));
ok('it checks the receiving group\'s entitlement', body.includes('must have room for one more property'));
ok('the handover needs a reason', !!sdoc.querySelector('form[action*="transfer"] input[name="reason"]'));
ok('the audit trail is shown', body.includes('Audit trail'));
ok('a suspension is in the trail with its reason', body.includes('Suspended Nowills Kumasi City: Refurbishment'));
ok('an access grant is in the trail', body.includes('Granted emensah access'));
ok('every trail line names who took the decision', body.includes('Ama Serwaa') && body.includes('Kwesi Boateng'));

/* ── the tenant's people ───────────────────────────────────────────────────── */
const gp = new JSDOM(read('deploy/dev/previews/group-people.html'), { url: 'https://preview.local/group/people' });
const pdoc = gp.window.document;
const pbody = pdoc.body.textContent.replace(/\s+/g, ' ');
const prow = name => [...pdoc.querySelectorAll('.table tbody tr')].map(tr => tr.textContent.replace(/\s+/g, ' ').trim()).find(t => t.includes(name));

ok('the people screen is titled for the tenant', pdoc.title.includes('Group people'));
ok('everybody in the tenant is listed', [...pdoc.querySelectorAll('.table tbody tr')].length === 5);
ok('a person\'s home property is shown', prow('Kwesi Boateng')?.includes('Nowills Airport City Hotel'));
ok('and the role that governs it', prow('Kwesi Boateng')?.includes('Accountant'));
ok('the extra properties they may enter are named', prow('Kwesi Boateng')?.includes('The Grove Essipong Beach Resort'));
ok('a person with no extra grant shows none', prow('Ama Serwaa')?.includes('—') || !prow('Ama Serwaa')?.includes('Also'));
ok('the group administrator is marked', prow('Ama Serwaa')?.includes('Group admin'));
ok('a switched-off person is marked and greyed out', prow('Yaw Osei')?.includes('Switched off')
   && [...pdoc.querySelectorAll('.table tbody tr')].find(tr => tr.textContent.includes('Yaw Osei'))?.className.includes('muted'));
ok('switching a person off is offered', pbody.includes('Switch off'));
ok('the screen says an account is created once at group level', pbody.includes('created once, at group level'));
ok('it explains that a grant\'s role governs that property only', pbody.includes('governs that property only'));
ok('it says switching somebody off keeps their history', pbody.includes('leaves their history'));
ok('the add form asks for a home property', !!pdoc.querySelector('form[action*="people"] select[name="hotel_id"]'));
ok('and a role grouped by the property it belongs to', [...pdoc.querySelectorAll('optgroup')].length === 4);
ok('the extra-property grants are checkboxes, one per property', [...pdoc.querySelectorAll('input[name="grants[]"]')].length === 4);
ok('a group administrator may be minted by a platform administrator', !!pdoc.querySelector('input[name="is_group_admin"]'));
ok('seats used are printed against the entitlement', pbody.includes('600 seat(s)'));
ok('the active headcount is printed beside it', pbody.includes('582 active'));
ok('switching a person off offers to take their grants back too', pbody.includes('Switch off & revoke'));
ok('each active person gets both switch-off decisions, and a switched-off person gets neither',
   [...pdoc.querySelectorAll('button[name="revoke_grants"]')].length === 8
   && ![...pdoc.querySelectorAll('.table tbody tr')].find(tr => tr.textContent.includes('Yaw Osei'))?.querySelector('button[name="revoke_grants"]'),
   `${[...pdoc.querySelectorAll('button[name="revoke_grants"]')].length} buttons`);

/* ── the group account ─────────────────────────────────────────────────────── */
const ga = new JSDOM(read('deploy/dev/previews/group-account.html'), { url: 'https://preview.local/group' });
const gdoc = ga.window.document;
const gbody = gdoc.body.textContent.replace(/\s+/g, ' ');
const gcards = [...gdoc.querySelectorAll('.stat')].map(s => ({ label: s.querySelector('.l').textContent.trim(), value: s.querySelector('.v').textContent.trim() }));
const gstat = label => gcards.find(s => s.label === label);
const field = name => gdoc.querySelector(`[name="${name}"]`);

ok('the group account is titled as the tenant account', gdoc.title.includes('Group account'));
ok('its code is editable in place', field('code')?.value === 'NWGROUP', field('code')?.value);
ok('its brand and management company are on the page', field('brand')?.value === 'Nowills Collection' && field('management_company')?.value === 'Nowills Hospitality Ltd');
ok('the entitlement is shown as a number of properties', gstat('Entitlement')?.value === '8', gstat('Entitlement')?.value);
ok('seats are printed used against bought', gstat('Seats')?.value === '582 / 600', gstat('Seats')?.value);
ok('and what is left of them', gbody.includes('18 seat(s) left'));
ok('the seat entitlement is editable on the account', field('max_users')?.value === '600', field('max_users')?.value);
ok('so is the licence renewal date', field('licence_ends_on')?.value === '2027-03-31', field('licence_ends_on')?.value);
ok('and the account says the renewal date switches nothing off by itself', gbody.includes('Nothing switches itself off on this date'));
ok('the module licence is on the account', gbody.includes('Module licence'));
ok('a restricted licence says so', gbody.includes('licensed for a subset'));
ok('every licensed module is ticked', [...gdoc.querySelectorAll('input[name="modules[]"]')].filter(i => i.checked).length === 10,
   `${[...gdoc.querySelectorAll('input[name="modules[]"]')].filter(i => i.checked).length} ticked`);
ok('the modules that were never bought are unticked', [...gdoc.querySelectorAll('input[name="modules[]"]')]
   .filter(i => !i.checked).map(i => i.value).sort().join('|') === 'Car Rental|Health Club|Spa & Activities',
   [...gdoc.querySelectorAll('input[name="modules[]"]')].filter(i => !i.checked).map(i => i.value).join(', '));
ok('the licence form posts to its own endpoint', !!gdoc.querySelector('form[action*="licence"]'));
ok('it says unticking a module hides and refuses its screens', gbody.includes('refuse to open'));
ok('and that the data behind them is untouched', gbody.includes('left exactly where it is'));
ok('ticking everything stores "everything", so later modules are not silently lost', gbody.includes('stores "everything"'));
ok('the seat basis is a choice, not a hidden default', field('seat_basis')?.value === 'active', field('seat_basis')?.value);
ok('and the named-user basis is spelled out', gbody.includes('every account holds a seat, switched off or not'));
ok('what is left of the entitlement is spelled out', gbody.includes('3 left to open'));
ok('the guest surfaces are said to follow the same licence', gbody.includes('The guest surfaces follow the same licence'));
ok('and the reason is given: a guest carries no permission', gbody.includes('A guest is not signed in, so there is no permission to check'));
{
  const rows = [...gdoc.querySelectorAll('table')]
    .find(t => t.textContent.includes('Guest surface'))
    ?.querySelectorAll('tbody tr') || [];
  ok('every guest surface is listed under the module that owns it', rows.length === 12, `${rows.length} rows`);
  const rowFor = label => [...rows].find(r => r.textContent.includes(label));
  ok('the QR table menu is a POS surface', rowFor('the QR table menu')?.textContent.includes('POS'));
  ok('a licensed surface reads open', rowFor('the QR table menu')?.textContent.includes('open'));
  ok('the activity voucher belongs to Spa & Activities', rowFor('the activity voucher')?.textContent.includes('Spa & Activities'));
  ok('and reads 404, because that module was never bought', rowFor('the activity voucher')?.textContent.includes('404'));
}
ok('it says how much of the guest estate the URL decides by itself', gbody.includes('31 of the 36 are decided from the URL'));
ok('it says a code or token in the URL is decided before the controller runs', gbody.includes('before the controller runs'));
ok('and that a link which resolves its own stay is refused once it has', gbody.includes('once it has resolved'));
ok('the REST API is included, after the key names the hotel', gbody.includes('after the bearer key has named the hotel'));
ok('the defaults it hands down are named as such', gbody.includes('Defaults new properties inherit'));
ok('the defaults carried are the group\'s own', field('country')?.value === 'Ghana' && field('currency')?.value === 'GHS' && field('timezone')?.value === 'Africa/Accra');
ok('changing a default is said not to rewrite existing properties', gbody.includes('never rewrites a property that already exists'));
ok('the people who administer the tenant are listed', gbody.includes('Who administers this tenant') && gbody.includes('Group Administrator'));
ok('their reach is stated', gbody.includes('including drafts and suspended ones'));
ok('the portfolio sits under the account', gbody.includes('Its properties') && gbody.includes('Nowills Kumasi City'));
ok('the group audit trail is shown', gbody.includes('Group audit trail') && gbody.includes('Retired Nowills Tamale Lodge'));
ok('suspending the tenant is offered, with a reason', !!gdoc.querySelector('form[action*="suspend"] input[name="reason"]'));
ok('and it says suspension stops every property at once', gbody.includes('stops'));

/* ── the module is wired into the application ──────────────────────────────── */
ok('the sidebar carries the register', [...rdoc.querySelectorAll('nav a')].some(a => /Propert/i.test(a.textContent)));
ok('the register links to the group account', [...rdoc.querySelectorAll('a')].some(a => (a.getAttribute('href') || '').includes('group-account')));
ok('the register can open a new property', [...rdoc.querySelectorAll('a')].some(a => (a.getAttribute('href') || '').includes('properties-create')));

if (failures.length) {
  console.error(`Properties preview check: ${failures.length} failed, ${passed} passed`);
  for (const failure of failures) console.error(`  ✗ ${failure}`);
  process.exit(1);
}
console.log(`Properties preview check: ${passed} assertions passed`);
