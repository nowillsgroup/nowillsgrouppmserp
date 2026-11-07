#!/usr/bin/env node
/*
 * The Reservation Workspace is a working desk screen, so the preview is checked the
 * way a receptionist uses it: read the day's numbers, filter the board, switch to the
 * rack, group by floor, open a room. The page's own script is executed here, not just
 * parsed, so a broken handler or a view switch that does not switch fails the check.
 *
 * Run: node deploy/dev/audit/workspace_preview_check.js
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const FILE = resolve(ROOT, 'deploy/dev/previews/reservations-workspace.html');

let JSDOM;
try {
  ({ JSDOM } = await import('jsdom'));
} catch (_) {
  console.log('workspace preview check: SKIP (jsdom is not installed)');
  process.exit(0);
}

let passed = 0;
const failures = [];
const ok = (label, condition, detail = '') => {
  if (condition) { passed += 1; return; }
  failures.push(detail ? `${label} — ${detail}` : label);
};

ok('the preview exists', existsSync(FILE));
if (!existsSync(FILE)) {
  console.log(`workspace preview check: 1 FAILED\n  ✗ ${failures[0]}`);
  process.exit(1);
}

const dom = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously',
  url: 'https://preview.local/reservations/workspace?date=2026-11-05',
  pretendToBeVisual: true,
});
const { window } = dom;
const doc = window.document;
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const cards = $$('#wsGrid .ws-card');

/* ── the day, at a glance ─────────────────────────────────────────────────── */
ok('the screen is titled', ($('.ws-title')?.textContent ?? '').includes('Reservation Workspace'));
ok('the board names its date', /November 2026/.test($('.ws-date-nav')?.textContent ?? ''));
ok('the day queues sit in the head', $$('.ws-head-stat').length === 4, `${$$('.ws-head-stat').length}`);
ok('arrivals and departures are counted', /Arrivals/.test($('.ws-head-stats')?.textContent ?? '')
  && /Departures/.test($('.ws-head-stats')?.textContent ?? ''));
ok('the KPI strip renders every tile', $$('.ws-kpi').length === 9, `${$$('.ws-kpi').length}`);
ok('each tile shows a sparkline', $$('.ws-kpi .ws-spark').length === 9);

/* ── the command bar ─────────────────────────────────────────────────────── */
ok('the command bar is above the board', Boolean($('#wsCmd')));
ok('search, filters and layout live together', Boolean($('#wsCmd #fSearch')) && Boolean($('#wsCmd #wsTabRack')));
ok('the board and rack are offered as two layouts', $$('[data-ws-view]').length === 2);
ok('the board is the default layout', $('#wsGrid')?.hidden === false && $('#wsRack')?.hidden === true);
ok('grouping is available', Boolean($('#wsGroupBy')));
ok('the keyboard hints are shown', Boolean($('.ws-keys')));

/* ── the board itself ────────────────────────────────────────────────────── */
ok('every room has a card', cards.length === 15, `${cards.length} cards`);
ok('cards carry the names the filters and rack need',
  cards.every(card => card.dataset.status && card.dataset.typeName && card.dataset.floorName && card.dataset.statusLabel));
ok('the board says how many rooms it is showing', /15 \/ 15 rooms/.test($('#wsVisibleCount')?.textContent ?? ''),
  $('#wsVisibleCount')?.textContent);

/* the card is a picture of the room, with the facts laid over it */
ok('each card opens with the room’s picture', cards.every(card => card.firstElementChild?.classList.contains('ws-photo')));
ok('the picture is actually a photograph',
  cards.every(card => /\/preview-assets\//.test(card.querySelector('.ws-photo-img')?.getAttribute('src') ?? '')),
  cards[0]?.querySelector('.ws-photo-img')?.getAttribute('src'));
ok('off-screen photographs are deferred', cards.every(card => card.querySelector('.ws-photo-img')?.getAttribute('loading') === 'lazy'));
ok('a room with no picture still gets its block', /\.ws-photo\{[^}]*background:#eef2f0/.test($('style')?.textContent ?? ''));
ok('the room number and status sit on the picture',
  cards.every(card => card.querySelector('.ws-photo .ws-card-head .ws-room-no')
    && card.querySelector('.ws-photo .ws-card-head .badge')));
ok('the picture carries the rate and the room facts',
  cards.every(card => card.querySelector('.ws-photo .ws-photo-rate') && card.querySelector('.ws-photo .ws-photo-meta')));
ok('the facts under the picture are the room’s own',
  (cards[0].querySelector('.ws-photo-meta')?.textContent ?? '').includes('m²')
    && /guests/.test(cards[0].querySelector('.ws-photo-meta')?.textContent ?? ''),
  cards[0].querySelector('.ws-photo-meta')?.textContent?.trim());
ok('the body holds the action row and nothing else',
  cards.every(card => card.querySelector('.ws-card-body .ws-actions'))
    && $$('#wsGrid .ws-card .ws-amen').length === 0);
// v10.13 five across: a front desk scans the house by state, so the board is read across the row rather
// than down it. The card was cut to fit — and each step down keeps a card at a legible width instead of
// squeezing five into a phone.
const wsStyle = $('style')?.textContent ?? '';
ok('the board fits five rooms across', /\.ws-grid\{[^}]*grid-template-columns:repeat\(5,minmax\(0,1fr\)\)/.test(wsStyle),
  wsStyle.match(/\.ws-grid\{[^}]*\}/)?.[0]);
ok('and a grouped board fits five across too', /\.ws-group-grid\{[^}]*grid-template-columns:repeat\(5,minmax\(0,1fr\)\)/.test(wsStyle),
  wsStyle.match(/\.ws-group-grid\{[^}]*\}/)?.[0]);
ok('the grid steps down to four, three, two and one as the screen narrows',
  [[1399, 4], [1023, 3], [767, 2], [479, 1]].every(([width, count]) =>
    new RegExp(`@media \\(max-width:${width}px\\)\\{\\.ws-grid\\{grid-template-columns:${count === 1 ? 'minmax\\(0,1fr\\)' : `repeat\\(${count},minmax\\(0,1fr\\)\\)`}`).test(wsStyle)),
  [[1399, 4], [1023, 3], [767, 2], [479, 1]]
    .map(([w, c]) => `${w}px→${c}`).join(' · '));
// v10.14 the column count follows the board, not the window: the sidebar and the rail take 576px between them,
// so a 1500px window holds a 908px board and the viewport query used to call that four.
ok('the board is a query container, so the count is its own width',
  /\.ws-main\{[^}]*container-type:inline-size/.test(wsStyle)
    && /\.ws-main\{[^}]*container-name:wsboard/.test(wsStyle),
  wsStyle.match(/\.ws-main\{[^}]*\}/)?.[0]);
ok('the ladder is re-run against the board\'s width, after the viewport fallback',
  [[799, 4], [639, 3], [479, 2], [319, 1]].every(([width, count]) =>
    new RegExp(`@container wsboard \\(max-width:${width}px\\)\\{\\.ws-grid\\{grid-template-columns:${count === 1 ? 'minmax\\(0,1fr\\)' : `repeat\\(${count},minmax\\(0,1fr\\)\\)`}`).test(wsStyle))
    && wsStyle.lastIndexOf('@container wsboard (max-width:799px)') > wsStyle.indexOf('@media (max-width:1399px){.ws-grid'),
  [[799, 4], [639, 3], [479, 2], [319, 1]].map(([w, c]) => `${w}px→${c}`).join(' · '));
ok('a board wide enough for more than five never settles for five fat cards',
  /@container wsboard \(min-width:1600px\)\{\.ws-grid\{grid-template-columns:repeat\(6/.test(wsStyle)
    && /@container wsboard \(min-width:1900px\)\{\.ws-grid\{grid-template-columns:repeat\(7/.test(wsStyle),
  'six from a 1600px board, seven from 1900px');
// v10.15 the rail steps aside where the board is already down to four, not at 1200 where it forced three,
// and it spreads into its own columns underneath instead of running as one tall strip.
ok('the rail steps aside before it squeezes the board into three',
  /@container wsscreen \(max-width:1068px\)\{\.ws-body\{grid-template-columns:1fr/.test(wsStyle),
  wsStyle.match(/@container wsscreen \(max-width:1068px\)\{[\s\S]{0,160}/)?.[0]?.replace(/\s+/g, ' '));
ok('and the board keeps every column it had while the rail is beside it',
  /\.ws-body\{display:grid;grid-template-columns:1fr 320px/.test(wsStyle));
// v10.16 the anchored rail is ranked: what needs acting on first is first. A row-flowed grid put the second
// item to the RIGHT of the first, which reads as second-best; multi-column keeps the ranking in the order you
// read. The check holds both the mechanism and the meaning.
ok('the stacked rail keeps its ranking in the reading order',
  /\.ws-rail\{display:block;columns:260px/.test(wsStyle)
    && /\.ws-rail-card\{break-inside:avoid/.test(wsStyle)
    && /@container wsscreen \(max-width:1068px\)\{[\s\S]{0,200}\.ws-rail\{display:block/.test(wsStyle),
  wsStyle.match(/\.ws-rail\{[^}]*\}/g)?.join(' | '));
const railOrder = $$('.ws-rail .ws-rail-card')
  .map(card => (card.querySelector('.ws-rail-title')?.textContent ?? '').replace(/\s+/g, ' ').trim());
ok('and the list is still ranked the way it is written, attention first',
  /NEEDS ATTENTION/.test(railOrder[0] ?? '') && railOrder.length >= 5,
  railOrder.slice(0, 5).join(' → '));
ok('the card is its own container, so the action row can answer to the card',
  /\.ws-card\{[^}]*container-type:inline-size/.test(wsStyle) && /\.ws-card\{[^}]*container-name:wscard/.test(wsStyle),
  wsStyle.match(/\.ws-card\{[^}]*\}/)?.[0]?.slice(0, 120));
ok('a narrow card folds its secondary actions instead of taking a second row',
  /@container wscard \(max-width:249px\)\{\.ws-actions \.ws-act-minor\{display:none\}/.test(wsStyle)
    && /@container wscard \(max-width:379px\)\{[\s\S]{0,200}\.ws-all \.ws-act-label\{/.test(wsStyle),
  'one row from 250px down: the primary action and ···');
ok('and the extend form folds first, because it is the widest thing in the row',
  /@container wscard \(max-width:379px\)\{\.ws-actions \.ws-extend-form\{display:none\}/.test(wsStyle)
    && wsStyle.indexOf('\u00b7 under 380px') > 0,
  'a 140px date field and a button do not share a line with anything');
// v10.22 took the guest and the stay off the card; v10.23 took the provenance line and the money with it; v10.24
// took the amenity icons. What is left on the card is the room's picture and the one action that moves it.
ok('the card prints no guest, no stay dates and no money',
  $$('#wsGrid .ws-card .ws-stay').length === 0
    && $$('#wsGrid .ws-card .ws-guest .bold.small').length === 0
    && $$('#wsGrid .ws-card .ws-rate-row').length === 0
    && $$('#wsGrid .ws-card .ws-guest-note').every(el => el.classList.contains('sr-only')),
  `${cards.length} cards, none printing a name, a date or a balance`);
// v10.25 a vacant room used to get a block of its own — VACANT · RACK RATE, the rate again at 18px, Reservice Room —
// which made every vacant card taller than its neighbours. State is the badge, the rate is the photo chip, and
// reserving is an action: it sits in the action row with the others, and every card is now the same two parts.
ok('every card is the same two parts, whatever state it is in',
  $$('#wsGrid .ws-card').every(card => card.children.length === 2
    && card.children[0].classList.contains('ws-photo')
    && card.children[1].classList.contains('ws-card-body'))
    && $$('#wsGrid .ws-card .ws-guest').length === 0,
  `${new Set($$('#wsGrid .ws-card').map(card => card.children.length)).size} shape(s) across ${cards.length} cards`);
ok('an empty room offers its reservation as an action, not as a block',
  $$('#wsGrid .ws-card[data-status="available"] .ws-actions .ws-reserve').length
    === $$('#wsGrid .ws-card[data-status="available"]').length,
  `${$$('#wsGrid .ws-card .ws-actions .ws-reserve').length} Reserve actions, none of them a block`);
ok('and what the room is, is still in the sentence a screen reader and the sheet get',
  $$('#wsGrid .ws-card .ws-guest-note').length === cards.length
    && /Available, no guest on this date/.test($$('#wsGrid .ws-card[data-status="available"] .ws-guest-note')[0]?.textContent ?? ''),
  $$('#wsGrid .ws-card[data-status="available"] .ws-guest-note')[0]?.textContent?.trim());
ok('nor does the card keep dead rules for what it dropped',
  !/\.ws-stay\{/.test(wsStyle) && !/\.ws-nights\{/.test(wsStyle)
    && !/\.ws-rate-row\{/.test(wsStyle) && !/\.ws-guest-src\{/.test(wsStyle),
  'the .ws-stay, .ws-nights, .ws-rate-row and .ws-guest-src rules all went with their markup');
ok('and the narrow-card block that only served the rate row went too',
  !/@container wscard \(max-width:199px\)\{[\s\S]{0,600}\.ws-rate-row/.test(wsStyle),
  'the 199px step that survives is the one that makes room for the rail, and it says so');
ok('but every stay still reads out in full to a screen reader',
  $$('#wsGrid .ws-card[data-guest] .ws-guest-note').length === $$('#wsGrid .ws-card[data-guest]').length
    && $$('#wsGrid .ws-card[data-guest] .ws-guest-note').every(el =>
      /Guest .+/.test(el.textContent) && /Arrive .+, depart .+/.test(el.textContent)
      && /\d+ nights? at /.test(el.textContent)),
  $$('#wsGrid .ws-card .ws-guest-note')[0]?.textContent?.trim());
ok('and the card carries guest, source, nights and the money as data for whoever still needs them',
  $$('#wsGrid .ws-card[data-guest]').every(el => (el.dataset.guest ?? '').length > 0
    && (el.dataset.guestMeta ?? '').length > 0
    && /^\d+$/.test(el.dataset.nights ?? '')),
  `${$$('#wsGrid .ws-card[data-guest]').length} cards carry data-guest, data-guest-meta and data-nights`);
ok('the dialog the card opens is where all of it went',
  $$('.ws-modal .ws-resv').length === $$('#wsGrid .ws-card[data-guest]').length
    && $$('.ws-modal .ws-resv').every(box => (box.querySelector('.bold')?.textContent ?? '').trim().length > 0
      && /→/.test(box.textContent)),
  `every dialog still states the guest and 12 Nov → 15 Nov · 3n`);
// v10.24 with the stay block and the amenity strip gone there was nothing left to absorb the slack a stretched card
// was given, so the card grew a band of white between its photo and its action row. The grid sizes cards to their
// contents now, and the body no longer claims the slack.
ok('cards are as tall as what they hold, not as tall as the tallest in the row',
  /\.ws-grid\{[^}]*align-items:start/.test(wsStyle)
    && /\.ws-group-grid\{[^}]*align-items:start/.test(wsStyle)
    && /\.ws-card-body\{[^}]*\}/.test(wsStyle)
    && !/\.ws-card-body\{[^}]*flex:1/.test(wsStyle),
  'no stretched card, no gap under the photo');
// v10.26 the facilities came back, but as a column down the left of the photograph rather than a row under it: a
// column costs the card no height, and height is the one thing this board cannot spare.
ok('the facilities are a column on the photo, not a row under it',
  $$('#wsGrid .ws-card .ws-photo .ws-amen-rail').length === cards.length
    && $$('#wsGrid .ws-card .ws-amen').length === 0,
  `${$$('#wsGrid .ws-card .ws-photo .ws-amen-rail').length} rails, one per card, none in the body`);
ok('and it is drawn downward, tight against the left edge',
  /\.ws-amen-rail\{[^}]*position:absolute/.test(wsStyle)
    && /\.ws-amen-rail\{[^}]*left:0/.test(wsStyle)
    && /\.ws-amen-rail\{[^}]*flex-direction:column/.test(wsStyle)
    && /\.ws-amen-rail\{[^}]*justify-content:center/.test(wsStyle),
  wsStyle.match(/\.ws-amen-rail\{[^}]*\}/)?.[0]?.slice(0, 110));
ok('four names fit a 104px photo, and the count is honest when there are more',
  $$('#wsGrid .ws-amen-rail').every(rail => rail.children.length <= 4)
    // a rail that reports a remainder shows three names and the count; a rail that reports none shows all it has
    && $$('#wsGrid .ws-amen-rail').every(rail => {
      const count = rail.querySelector('.ws-amen-more');
      const shown = rail.querySelectorAll('span[role="img"]').length;
      return count ? shown === 3 : shown >= 1 && shown <= 4;
    })
    && $$('#wsGrid .ws-amen-rail .ws-amen-more').every(button =>
      Number(button.textContent.replace(/\D/g, ''))
        === Number((button.getAttribute('aria-label') ?? '').match(/(\d+) more/)?.[1] ?? -1))
    && $$('#wsGrid .ws-amen-rail span[role="img"]').every(span => (span.getAttribute('title') ?? '').length > 0),
  `${$$('#wsGrid .ws-amen-rail span[role="img"]').length} names and ${$$('#wsGrid .ws-amen-rail .ws-amen-more').length} counts across ${cards.length} cards`);
ok('and the count opens the dialog that lists them all',
  $$('#wsGrid .ws-amen-rail .ws-amen-more').every(button => /roomModal\d+/.test(button.getAttribute('aria-controls') ?? '')),
  "the same trigger the photo and the ··· use");
ok('the dialog names every amenity the card used to draw in icons',
  $$('.ws-modal .ws-fac-chip').length > 0,
  `${$$('.ws-modal .ws-fac-chip').length} facility chips across the dialogs`);
// v10.25 the polish pass: one line per head row, one action-bar height, one corner radius, and a keyboard ring.
ok('the head cuts a long type name with an ellipsis instead of wrapping it',
  /\.ws-card-head \.bold\.small,\.ws-card-head \.muted\.small\{[^}]*white-space:nowrap\}?[^}]*text-overflow:ellipsis/.test(wsStyle)
    || (/\.ws-card-head \.bold\.small,\.ws-card-head \.muted\.small\{/.test(wsStyle)
      && /text-overflow:ellipsis/.test(wsStyle.match(/\.ws-card-head \.bold\.small[^{]*\{[^}]*\}/)?.[0] ?? '')),
  wsStyle.match(/\.ws-card-head \.bold\.small[^{]*\{[^}]*\}/)?.[0]);
ok('the action bar is one height on every card',
  /\.ws-actions\{[^}]*min-height:34px/.test(wsStyle)
    && /\.ws-actions \.btn\{[^}]*min-height:26px/.test(wsStyle),
  'a card with one action and a card with four are the same height');
ok('and the card answers the keyboard, not only the mouse',
  /\.ws-card:focus-within\{outline:2px solid/.test(wsStyle)
    && /\.ws-card:hover\{[^}]*transform:translateY\(-1px\)/.test(wsStyle)
    && /\.ws-card\{[^}]*border-radius:12px/.test(wsStyle),
  'focus ring, a 1px lift on hover, and one corner radius for the whole board');
ok('the buttons that fold are marked as secondary',
  $$('#wsGrid .ws-card .ws-actions .ws-act-minor').length >= 1
    && $$('#wsGrid .ws-card .ws-actions .ws-all .ws-act-label').length === cards.length,
  `${$$('#wsGrid .ws-card .ws-actions .ws-act-minor').length} foldable controls across ${cards.length} cards`);
ok('and each one is still reachable in the room dialog, as the same form',
  $$('.ws-modal .ws-modal-actions .ws-inline-action').length === $$('#wsGrid .ws-card .ws-actions .ws-inline-action').length
    && $$('.ws-modal .ws-modal-actions .ws-extend-form').length === $$('#wsGrid .ws-card .ws-actions .ws-extend-form').length,
  `${$$('.ws-modal .ws-modal-actions .ws-inline-action').length} mark-dirty and ${$$('.ws-modal .ws-modal-actions .ws-extend-form').length} extend forms in the dialogs`);
ok('the dialog says what that block is for',
  $$('.ws-modal .ws-modal-actions').every(block => /ROOM ACTIONS/.test(block.previousElementSibling?.textContent ?? '')),
  $$('.ws-modal .ws-modal-actions')[0]?.previousElementSibling?.textContent?.trim());
ok('the screen itself is a container, so the header answers to it too',
  /\.ws-screen\{[^}]*container-type:inline-size/.test(wsStyle) && /\.ws-screen\{[^}]*container-name:wsscreen/.test(wsStyle),
  wsStyle.match(/\.ws-screen\{[^}]*\}/)?.[0]);
// a container establishes a containing block for position:fixed descendants, and every room dialog is a fixed
// overlay: if one ever landed inside .ws-screen it would be pinned to the screen box and scroll away with it
ok('the room dialogs sit outside the screen container, where a fixed overlay belongs',
  $$('.ws-screen .ws-modal').length === 0 && $$('.ws-modal').length === cards.length,
  `${$$('.ws-modal').length} dialogs, none inside .ws-screen`);
ok('and the board, the rail and the KPI strip sit inside it',
  $$('.ws-screen #wsGrid').length === 1 && $$('.ws-screen .ws-rail').length === 1 && $$('.ws-screen .ws-kpis').length === 1);
ok('the KPI strip, filters, toolbar and shortcut hints switch on the screen\'s width',
  /@container wsscreen \(max-width:908px\)\{\.ws-kpis\{/.test(wsStyle)
    && /@container wsscreen \(max-width:688px\)\{\.ws-kpis\{/.test(wsStyle)
    && /@container wsscreen \(max-width:388px\)\{\s*\.ws-head\{/.test(wsStyle)
    && /@container wsscreen \(max-width:388px\)\{\s*\.ws-toolbar\{/.test(wsStyle)
    && /@container wsscreen \(max-width:888px\)\{\.ws-keys\{display:none\}/.test(wsStyle),
  'one width decides the whole screen');
ok('and none of them is still counting the window',
  !/@media \(max-width:\d+px\)\{\.ws-kpis/.test(wsStyle)
    && !/@media \(max-width:\d+px\)\{\.ws-keys/.test(wsStyle)
    && !/@media \(max-width:\d+px\)\{\.ws-cmd/.test(wsStyle),
  'the screen-level breakpoints are container queries now');
// v10.17 the rack was the last place asking a reader to scroll sideways: 840px of table in a 600px panel.
const rackHeads = $$('.ws-rack-table thead th[data-col]').map(th => th.dataset.col);
// the body is built by the screen's own script, so the cells are held to the same names through the script
const rackScript = $$('script').map(s => s.textContent || '').join('\n');
ok('every rack column is named, head and cell alike',
  rackHeads.join(',') === 'room,type,status,guest,nights,rate,balance,hk,action'
    && rackHeads.every(col => new RegExp(`dataset\\.col = '${col}'`).test(rackScript)),
  `${rackHeads.join(' · ')} — ${rackHeads.filter(col => new RegExp(`dataset\\.col = '${col}'`).test(rackScript)).length} of ${rackHeads.length} cells tagged in the script`);
// v10.19 the action cell is a column of buttons, and it was copying every control the card had folded.
ok('the rack marks the controls it may have to give up',
  /copy\.classList\.add\('ws-rack-extra'\);/.test(rackScript)
    && /if \(node\.matches\('\.ws-act-minor'\)\) copy\.classList\.add\('ws-rack-extra'\);/.test(rackScript),
  'the extend and mark-dirty forms, and the details link, travel tagged');
ok('and a narrow rack keeps only the action that moves the room',
  /@container wsscreen \(max-width:839px\)\{\.ws-rack-table \[data-col="action"\] \.ws-rack-extra\{display:none\}\}/.test(wsStyle)
    && /\.ws-rack-open/.test(wsStyle),
  'Details stays in the room cell, and the dialog carries the rest');
ok('a group heading stays on the sheet with the rooms it names',
  /@media print\{[\s\S]{0,1200}\.ws-group-head\{break-after:avoid;break-inside:avoid\}/.test(wsStyle)
    && /@media print\{[\s\S]{0,1200}\.ws-group\{break-inside:auto\}/.test(wsStyle),
  'three across on paper, so a group can straddle a page');
ok('the rack reads the housekeeping state, not the sentence around it',
  /classList\.contains\('ws-hk-prefix'\)/.test(rackScript) && !/Current\\s\*\(HK/.test(rackScript),
  'the chip\'s prefix span is skipped rather than stripped by regex');
ok('the rack stops pretending it has 840px of room', /\.ws-rack-table\{width:100%;min-width:840px/.test(wsStyle)
  && /@container wsscreen \(max-width:839px\)\{\s*\.ws-rack-table\{min-width:0\}/.test(wsStyle),
  'the floor is given up before the reader is asked to scroll');
ok('the columns retreat in the order that costs the reader least',
  [[839, 'nights'], [839, 'balance'], [719, 'type'], [719, 'hk'], [519, 'rate']].every(([width, col]) =>
    new RegExp(`@container wsscreen \\(max-width:${width}px\\)\\{[\\s\\S]{0,200}\\[data-col="${col}"\\]`).test(wsStyle)),
  'nights and balance, then type and housekeeping, then rate');
ok('a room, its state and its action are never hidden',
  ['room', 'status', 'action'].every(col =>
    !new RegExp(`\\[data-col="${col}"\\]\\s*\\{[^}]*display:none`).test(wsStyle)),
  'the three a row is nothing without');
// v10.20 the guest is the fourth, but below 520px it stops being a column and becomes the room's second line
ok('and the guest moves under the room number rather than off the page',
  /@container wsscreen \(max-width:519px\)\{[\s\S]{0,600}\[data-col="guest"\]\{display:none\}/.test(wsStyle)
    && /@container wsscreen \(max-width:519px\)\{[\s\S]{0,800}\.ws-rack-table \.ws-rack-sub\{display:block\}/.test(wsStyle)
    && /\.ws-rack-sub\{display:none/.test(wsStyle),
  'hidden as a column at 520px, shown as a line in the room cell');
ok('the script puts the guest in the room cell for that narrow row',
  /sub\.className = 'ws-rack-sub'/.test(rackScript) && /roomText\.appendChild\(sub\)/.test(rackScript)
    && /\[guest \|\| 'No guest', guestMeta\]/.test(rackScript),
  'one line: guest and where they came from');
// v10.18 the sort lives on columns that retreat, so the order is stated where it cannot be hidden — and the
// statement is the control: activate to reverse, clear to return to room order.
const sortChip = $('#wsRackSortState');
ok('the rack states its order outside the columns',
  Boolean(sortChip) && sortChip.getAttribute('role') === 'status' && sortChip.hasAttribute('aria-live')
    && sortChip.hasAttribute('hidden'),
  `${sortChip?.tagName} role=${sortChip?.getAttribute('role')} hidden=${sortChip?.hasAttribute('hidden')}`);
ok('and the statement is rendered by the screen, not baked in',
  /function renderSortState\(\)/.test(rackScript)
    && /renderSortState\(\);/.test(rackScript)
    && /chip\.append\(toggle, clear\)/.test(rackScript),
  'a toggle and a way back to room order');
ok('it reverses and clears from the chip itself',
  /toggle\.textContent = `Sorted: \$\{label\} \$\{arrow\}`/.test(rackScript)
    && /applyRackSort\(rackSort\.key, rackSort\.dir === 'asc' \? 'desc' : 'asc'\)/.test(rackScript)
    && /applyRackSort\(null, null\)/.test(rackScript),
  'the order that survives the fold is also the control');
ok('it hides when there is no order to state, and outside the rack',
  /\.ws-rack-sortstate\[hidden\]\{display:none\}/.test(wsStyle)
    && /if \(!rackSort \|\| !inRack\) \{ chip\.hidden = true; return; \}/.test(rackScript),
  'board view does not carry a rack order');
ok('printing gives the board its own count instead of the screen\'s',
  /@media print\{[\s\S]{0,900}\.ws-grid,\.ws-group-grid\{grid-template-columns:repeat\(3,minmax\(0,1fr\)\)!important/.test(wsStyle),
  wsStyle.match(/@media print\{[\s\S]{0,220}/)?.[0]?.replace(/\s+/g, ' '));
ok('and the sheet leaves the sort chip and the action rows off the paper',
  /@media print\{[\s\S]{0,200}\.ws-rack-sortstate\{display:none!important\}/.test(wsStyle),
  'three across, no screen controls');
// v10.22 a sheet of paper has no dialog, so the name the card stopped printing comes back on paper
ok('and paper puts the guest name back, because a sheet has no dialog to open',
  /@media print\{[\s\S]{0,2000}#wsGrid \.ws-guest-note\{position:static;display:block/.test(wsStyle),
  'the sr-only sentence is printed as a line of its own on the sheet');
ok('the grouped board answers to the same container',
  /@container wsboard \(max-width:799px\)\{\.ws-group-grid\{/.test(wsStyle));
ok('nothing on the card face is hidden to buy the fifth column',
  /\.ws-photo-meta>span:nth-child\(3\)\{display:none\}/.test(wsStyle) === false
    && /\.ws-hk-prefix\{position:absolute/.test(wsStyle) === false,
  'the smoking rule and the housekeeping words are on the narrow card too');
ok('the housekeeping chip wraps rather than dropping its words',
  /\.ws-hk-badge\{[^}]*white-space:normal/.test(wsStyle)
    && cards.every(card => /Current\s*HK|housekeeping/.test(card.querySelector('.ws-hk-badge')?.textContent ?? '')),
  cards[0]?.querySelector('.ws-hk-badge')?.textContent?.trim());
ok('the card itself was cut to that size, not just squeezed',
  /\.ws-photo\{[^}]*aspect-ratio:16\/11[^}]*min-height:104px/.test(wsStyle)
    && /\.ws-room-no\{font-size:21px/.test(wsStyle)
    && /\.ws-avatar\{width:28px/.test(wsStyle)
    && /\.ws-actions \.btn\{[^}]*font-size:10px/.test(wsStyle),
  [wsStyle.match(/\.ws-photo\{[^}]*\}/)?.[0], wsStyle.match(/\.ws-room-no\{[^}]*\}/)?.[0]].join(' | '));
ok('the housekeeping chip still names what it is talking about',
  cards.every(card => card.querySelector('.ws-hk-badge .ws-hk-prefix')),
  `${cards.filter(card => card.querySelector('.ws-hk-badge .ws-hk-prefix')).length}/${cards.length} chips carry the words`);

/* ── the needs-attention rail ────────────────────────────────────────────── */
const attention = $$('#wsAttention .ws-attention-row');
ok('the rail opens with what needs acting on', attention.length === 5, `${attention.length} rows`);
ok('each item carries its count', attention.every(row => /^\d+$/.test(row.querySelector('.ws-attention-n')?.textContent?.trim() ?? '')));
ok('the total is badged', ($('#wsAttention .ws-attention-count')?.textContent ?? '').trim() === '8',
  $('#wsAttention .ws-attention-count')?.textContent);
ok('the attention card comes before the calendar', $('#wsAttention')?.nextElementSibling
  ? /ws-cal|ws-rail-card/.test($('#wsAttention').nextElementSibling.className) : false);

/* ── filtering ───────────────────────────────────────────────────────────── */
const statusSelect = $('#fStatus');
statusSelect.value = 'occupied';
statusSelect.dispatchEvent(new window.Event('change'));
const visibleCards = cards.filter(card => !card.hidden);
ok('filtering actually hides cards', visibleCards.length === 5, `${visibleCards.length} visible`);
ok('filtering updates the count', /5 \/ 15 rooms/.test($('#wsVisibleCount')?.textContent ?? ''), $('#wsVisibleCount')?.textContent);
ok('the active filter becomes a chip', $$('#wsChips .ws-chip').length === 1 && /Occupied/.test($('#wsChips')?.textContent ?? ''),
  $('#wsChips')?.textContent);

const chipButton = $('#wsChips .ws-chip button');
chipButton?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('a chip can be removed', $('#fStatus')?.value === '', $('#fStatus')?.value);
ok('removing the chip restores the board', cards.every(card => !card.hidden));

/* ── search ──────────────────────────────────────────────────────────────── */
const search = $('#fSearch');
search.value = 'Osei';
search.dispatchEvent(new window.Event('input'));
ok('search narrows the board', cards.filter(card => !card.hidden).length === 1,
  `${cards.filter(card => !card.hidden).length} visible`);
search.value = '';
search.dispatchEvent(new window.Event('input'));

/* ── grouping ────────────────────────────────────────────────────────────── */
const groupBy = $('#wsGroupBy');
groupBy.value = 'floor';
groupBy.dispatchEvent(new window.Event('change'));
const groups = $$('#wsGrid .ws-group');
ok('grouping by floor builds a section per floor', groups.length === 3, `${groups.length} groups`);
ok('each group is titled', groups.every(group => (group.querySelector('.ws-group-head')?.textContent ?? '').trim().length > 0));
ok('the group counts add up to the board', groups.reduce((total, group) => total + Number(group.querySelector('.ws-group-count')?.textContent ?? 0), 0) === cards.length);
ok('no card is lost when grouping', $$('#wsGrid .ws-card').length === cards.length);
ok('the grouping is in the address bar', /(^|[?&])group=floor(&|$)/.test(window.location.search), window.location.search);
ok('the filters keep the layout and grouping',
  /view=|group=floor/.test(window.location.search) && !/group=$/.test(window.location.search));

groupBy.value = '';
groupBy.dispatchEvent(new window.Event('change'));
ok('turning grouping off returns the flat board', $$('#wsGrid .ws-group').length === 0);

/* ── the rack ────────────────────────────────────────────────────────────── */
$('#wsTabRack')?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('the rack replaces the board', $('#wsRack')?.hidden === false && $('#wsGrid')?.hidden === true);
ok('the layout is in the address bar', /(^|[?&])view=rack(&|$)/.test(window.location.search), window.location.search);
const rows = $$('#wsRackBody tr');
ok('every room has a rack row', rows.length === cards.length, `${rows.length} rows`);
ok('the rack shows the room number', (rows[0]?.querySelector('.ws-rack-room b')?.textContent ?? '').length === 3,
  rows[0]?.querySelector('.ws-rack-room b')?.textContent);
// v10.22 the board stopped naming the guest and counting the nights; the rack is where that lives now, read from
// the card's data rather than from markup the card no longer prints.
ok('the rack still names the guest and counts the nights the card dropped',
  rows.filter(r => (r.querySelector('.ws-rack-guest b')?.textContent ?? '').trim().length > 2).length >= 1
    && rows.filter(r => /^\d+n$/.test((r.querySelector('[data-col="nights"]')?.textContent ?? '').trim())).length >= 1,
  `${rows.filter(r => /^\d+n$/.test((r.querySelector('[data-col="nights"]')?.textContent ?? '').trim())).length} rows carry a night count`);
ok('the rack shows status, guest, rate and an action',
  Boolean(rows[0]?.querySelector('.badge')) && rows[0].querySelectorAll('td').length >= 7,
  `${rows[0]?.querySelectorAll('td').length} cells`);
ok('the rack can open a room', Boolean(rows[0]?.querySelector('.ws-room-open')));
ok('the rack shows the room’s picture too', rows.filter(row => row.querySelector('.ws-rack-thumb')).length === rows.length,
  `${rows.filter(row => row.querySelector('.ws-rack-thumb')).length} of ${rows.length} rows`);
ok('the rack thumbnail is the card’s own photograph',
  /url\(["']?\/preview-assets\//.test(rows[0]?.querySelector('.ws-rack-thumb')?.style.backgroundImage ?? ''),
  rows[0]?.querySelector('.ws-rack-thumb')?.style.backgroundImage);
ok('the rack headers sort', $$('.ws-rack-sort').length === 5, `${$$('.ws-rack-sort').length}`);

const rateHeader = $$('.ws-rack-sort').find(button => button.dataset.wsSort === 'rate');
rateHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
const firstRate = Number(($$('#wsRackBody tr')[0]?.dataset.rate ?? '').replace(/[^\d.]/g, ''));
const rates = $$('#wsRackBody tr').map(row => Number((row.dataset.rate ?? '').replace(/[^\d.]/g, '')));
ok('sorting by rate orders the rack', firstRate === Math.min(...rates), `${firstRate} vs min ${Math.min(...rates)}`);
ok('the sorted column says so', rateHeader?.closest('th')?.getAttribute('aria-sort') === 'ascending',
  rateHeader?.closest('th')?.getAttribute('aria-sort'));
ok('the chosen order is remembered', window.sessionStorage.getItem('wsSort') === 'rate:asc',
  window.sessionStorage.getItem('wsSort'));

const statusHeader = $$('.ws-rack-sort').find(button => button.dataset.wsSort === 'room');
statusHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('sorting by room starts at 101', /101/.test($$('#wsRackBody tr')[0]?.textContent ?? ''), $$('#wsRackBody tr')[0]?.textContent?.slice(0, 20));
ok('only one column is marked as sorted', $$('.ws-rack-table th[aria-sort]').length === 1,
  `${$$('.ws-rack-table th[aria-sort]').length}`);
rateHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('a new column starts ascending', rateHeader?.closest('th')?.getAttribute('aria-sort') === 'ascending');
rateHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('clicking the same header again reverses it', rateHeader?.closest('th')?.getAttribute('aria-sort') === 'descending'
  && Number(($$('#wsRackBody tr')[0]?.dataset.rate ?? '').replace(/[^\d.]/g, '')) === Math.max(...rates),
  rateHeader?.closest('th')?.getAttribute('aria-sort'));
statusHeader?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

/* the rack's action forms are copies — they still have to confirm the right thing */
const rackExtend = $$('#wsRackBody .ws-extend-form')[0];
ok('the rack carries the board\u2019s actions', Boolean(rackExtend));
if (rackExtend) {
  const dateInput = rackExtend.querySelector('[name="departure_date"]');
  dateInput.value = '2026-11-09';
  rackExtend.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  ok('extending from the rack names the date it will use',
    /2026-11-09|9 November|Nov 9/.test(rackExtend.dataset.confirm ?? ''), rackExtend.dataset.confirm);
}

statusSelect.value = 'reserved';
statusSelect.dispatchEvent(new window.Event('change'));
ok('the rack obeys the same filters', $$('#wsRackBody tr').filter(row => !row.hidden).length === 4,
  `${$$('#wsRackBody tr').filter(row => !row.hidden).length} visible rows`);
statusSelect.value = '';
statusSelect.dispatchEvent(new window.Event('change'));

/* ── keyboard ────────────────────────────────────────────────────────────── */
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'b', bubbles: true }));
ok('B returns to the board', $('#wsGrid')?.hidden === false && $('#wsRack')?.hidden === true);
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'r', bubbles: true }));
ok('R opens the rack', $('#wsRack')?.hidden === false);
search.value = 'zzz-not-a-room';
search.dispatchEvent(new window.Event('input', { bubbles: true }));
ok('a search that matches nothing says so', $('#wsNoResults')?.hidden === false);
search.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
ok('Escape clears the search first', search.value === '' && $('#wsNoResults')?.hidden === true,
  `value "${search.value}"`);
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: '/' }));
ok('slash focuses the search', doc.activeElement === search, doc.activeElement?.id);
doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'b' }));

/* ── the screen, not a mock-up of it ─────────────────────────────────────── */
ok('the stylesheet is linked', $$('link[rel="stylesheet"]').some(link => /app\.css/.test(link.getAttribute('href') ?? '')));
ok('the page carries its own styles', /\.ws-rack-table\{/.test($('style')?.textContent ?? ''));
ok('a printed rack fits the page', /\.ws-rack-table\{min-width:0/.test($('style')?.textContent ?? ''));
ok('hidden rooms are really hidden by the stylesheet',
  /\.ws-card\[hidden\]/.test($('style')?.textContent ?? ''));
ok('the page carries its own script', /applyWsFilters/.test($('script')?.textContent ?? ''));
ok('the sidebar is around it', Boolean($('aside.sidebar')) && Boolean($('.page-head h1')));
ok('no preview furniture on the screen',
  !/sample|preview|illustrative|not linked|static screen/i.test($('main')?.textContent ?? ''));

/* ── what a screen reader gets ───────────────────────────────────────────── */
const controls = [...doc.querySelectorAll('button, a[href], [role="button"]')];
const named = (element) => {
  const label = (element.getAttribute('aria-label') || '').trim();
  if (label) return true;
  if (element.getAttribute('aria-labelledby')) return true;
  const text = (element.textContent || '').replace(/\s+/g, ' ').trim();
  return text.length > 0;
};
const anonymous = controls.filter(control => !named(control));
ok('every control has a name', anonymous.length === 0,
  anonymous.slice(0, 3).map(node => node.outerHTML.slice(0, 70)).join(' · '));
ok('every picture is described or marked decorative',
  $$('#wsGrid img').every(img => img.hasAttribute('alt')));
ok('the housekeeping badges are read out in full',
  $$('.ws-hk-badge').every(badge => /Current .*housekeeping/i.test(badge.textContent.replace(/\s+/g, ' '))
    || Boolean(badge.getAttribute('title'))));
ok('the sortable rack headers say what they sort by',
  $$('.ws-rack-sort').every(button => (button.textContent || '').trim().length > 0));

/* ── the copy says the right number ──────────────────────────────────────── */
const pluralTrap = () => {
  const said = [doc.body.textContent, ...[...doc.querySelectorAll('[aria-label], [title]')]
    .flatMap(node => [node.getAttribute('aria-label'), node.getAttribute('title')])].join(' ');
  return said.match(/\b1 (guests|rooms|nights|adults|persons|photos|stays|days)\b/gi) ?? [];
};
ok('nothing says "1 guests"', pluralTrap().length === 0, pluralTrap().slice(0, 3).join(', '));

const single = cards.find(card => (card.getAttribute('data-room') === '304')
  || Boolean(card.querySelector('[data-room="304"]')));
ok('a single room reads "1 guest"',
  /\b1 guest\b/.test(single?.querySelector('.ws-photo-meta')?.textContent ?? '')
    && !/1 guests/.test(single?.querySelector('.ws-photo-meta')?.textContent ?? ''),
  single?.querySelector('.ws-photo-meta')?.textContent?.replace(/\s+/g, ' ').trim());

/* ── a room with no rate does not advertise a free night ─────────────────── */
const unpriced = cards.find(card => (card.getAttribute('data-room') === '203')
  || Boolean(card.querySelector('[data-room="203"]')));
ok('a room whose rate is not set says so',
  /No rate/.test(unpriced?.querySelector('.ws-photo-rate')?.textContent ?? ''),
  unpriced?.querySelector('.ws-photo-rate')?.textContent?.trim());
// 620.00 is a price; a zero is a zero — the character before it must not be a digit
ok('no card advertises a night at zero',
  $$('.ws-photo-rate').every(pill => !/[^\d.,]0\.00/.test(pill.textContent)),
  $$('.ws-photo-rate').map(pill => pill.textContent.trim()).find(text => /[^\d.,]0\.00/.test(text)));

/* ── the modals the board opens ──────────────────────────────────────────── */
ok('every room has a details dialog', $$('.ws-modal').length === cards.length, `${$$('.ws-modal').length}`);
$('#wsTabBoard')?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
$('.ws-photo-link')?.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
ok('opening a room shows its dialog', $$('.ws-modal.show').length === 1);
ok('the dialog names the room', /Room|Deluxe|Standard/.test($('.ws-modal.show')?.textContent ?? ''));
ok('the dialog shows the room’s picture', /url\(["']?\/preview-assets\//.test($('.ws-modal.show .ws-modal-hero')?.style.background ?? ''),
  $('.ws-modal.show .ws-modal-hero')?.style.background?.slice(0, 60));
ok('the focus ring sits inside the picture', /outline-offset:-4px/.test($('style')?.textContent ?? ''));

/* ── coming back to the screen ───────────────────────────────────────────── */
// A desk keeps its layout and its order between refreshes; the page stores both.
const reopened = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously',
  pretendToBeVisual: true,
  beforeParse(instance) {
    instance.sessionStorage.setItem('wsSort', 'rate:desc');
  },
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&view=rack&group=floor',
});
const again = reopened.window.document;
const reopenedRates = [...again.querySelectorAll('#wsRackBody tr')]
  .map(row => Number((row.dataset.rate ?? '').replace(/[^\d.]/g, '')));
ok('the saved order comes back', again.querySelector('#wsRack')?.hidden === false
  && reopenedRates[0] === Math.max(...reopenedRates), `${reopenedRates[0]} of ${reopenedRates.length} rows`);
ok('a link opens the rack it points at', again.querySelector('#wsRack')?.hidden === false
  && again.querySelector('#wsTabRack')?.getAttribute('aria-selected') === 'true');
ok('a link opens the grouping it points at', again.querySelectorAll('#wsGrid .ws-group').length === 3,
  `${again.querySelectorAll('#wsGrid .ws-group').length} groups`);
const reopenedNav = [...again.querySelectorAll('a[data-ws-nav]')];
ok('changing the date keeps the layout and grouping',
  reopenedNav.length > 0 && reopenedNav.every(link => /view=rack/.test(link.getAttribute('href') ?? '')
    && /group=floor/.test(link.getAttribute('href') ?? '')),
  reopenedNav[0]?.getAttribute('href'));
ok('the saved order comes back marked on its column',
  again.querySelector('.ws-rack-sort[data-ws-sort="rate"]')?.closest('th')?.getAttribute('aria-sort') === 'descending');
reopened.window.close();

// A pasted link filters the board; an invalid value clears the filter instead of
// quietly emptying it.
const linked = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously', pretendToBeVisual: true,
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&status=occupied&hk=dirty',
});
const linkedDoc = linked.window.document;
ok('a pasted link sets the filters',
  linkedDoc.querySelector('#fStatus').value === 'occupied' && linkedDoc.querySelector('#fHousekeeping').value === 'dirty');
ok('a pasted link shows its chips', linkedDoc.querySelectorAll('#wsChips .ws-chip').length === 2,
  `${linkedDoc.querySelectorAll('#wsChips .ws-chip').length} chips`);
ok('a pasted link filters the board',
  [...linkedDoc.querySelectorAll('#wsGrid .ws-card')].filter(card => !card.hidden).length < linkedDoc.querySelectorAll('#wsGrid .ws-card').length);
linked.window.close();

// A link that asks for the rack, a filter and a grouping at once has to honour all three
const compound = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously', pretendToBeVisual: true,
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&view=rack&status=occupied&group=floor',
});
const compoundDoc = compound.window.document;
const compoundRows = [...compoundDoc.querySelectorAll('#wsRackBody tr')];
ok('a link can ask for the rack, a filter and a grouping at once',
  compoundDoc.querySelector('#wsRack').hidden === false && compoundDoc.querySelectorAll('#wsGrid .ws-group').length === 3);
ok('the rack obeys the filter on first paint',
  compoundRows.filter(row => !row.hidden).length
    === [...compoundDoc.querySelectorAll('#wsGrid .ws-card')].filter(card => !card.hidden).length,
  `${compoundRows.filter(row => !row.hidden).length} rows`);
ok('the group counts match the filtered board',
  [...compoundDoc.querySelectorAll('#wsGrid .ws-group-count')].reduce((total, node) => total + Number(node.textContent), 0)
    === [...compoundDoc.querySelectorAll('#wsGrid .ws-card')].filter(card => !card.hidden).length);
compound.window.close();

const bogus = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously', pretendToBeVisual: true,
  url: 'https://preview.local/reservations/workspace?date=2026-11-05&status=banana',
});
const bogusDoc = bogus.window.document;
ok('a filter value that does not exist is ignored',
  bogusDoc.querySelector('#fStatus').value === ''
    && [...bogusDoc.querySelectorAll('#wsGrid .ws-card')].every(card => !card.hidden));
bogus.window.close();

// No link, no session — a plain reload still reopens the layout last used.
const remembered = new JSDOM(readFileSync(FILE, 'utf8'), {
  runScripts: 'dangerously',
  url: 'https://preview.local/reservations/workspace?date=2026-11-05',
  pretendToBeVisual: true,
  beforeParse(instance) { instance.sessionStorage.setItem('wsView', 'rack'); },
});
const rememberedDoc = remembered.window.document;
ok('a plain reload reopens the layout last used', rememberedDoc.querySelector('#wsRack')?.hidden === false
  && rememberedDoc.querySelector('#wsGrid')?.hidden === true);
remembered.window.close();

if (failures.length) {
  console.log(`workspace preview check: ${failures.length} FAILED, ${passed} passed`);
  failures.forEach(failure => console.log(`  ✗ ${failure}`));
  process.exit(1);
}
console.log(`workspace preview check: ${passed} assertions passed`);
window.close();
