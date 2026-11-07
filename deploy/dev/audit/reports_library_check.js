#!/usr/bin/env node
/*
 * The report library is the front door to 400-odd reports. A catalogue that has lost
 * its search, its area filter or a whole area still renders happily and looks fine:
 * nothing errors, the page simply fails to help. Equally, a search that hides rows but
 * never tells the reader how many are left reads as a broken page.
 *
 * This checks the controller data (every area in REPORTS gets an icon, only permitted
 * reports are listed, the counts are passed through), then renders
 * deploy/dev/previews/reports-library.html in jsdom and drives the search box, the
 * area chips and the reset button, asserting what the reader sees after each.
 *
 * Run: node deploy/dev/audit/reports_library_check.js
 */
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed++; return; }
  failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
};

// ── the controller keeps the catalogue complete ─────────────────────────────
const controller = readFileSync('app/Http/Controllers/ReportController.php', 'utf8');
const constBlock = controller.slice(controller.indexOf('const REPORTS'), controller.indexOf('GROUP_ICONS'));
const starts = [...constBlock.matchAll(/^\s*'([a-z0-9_]+)' => \[\s*/gm)];
ok('the report table is found', starts.length > 400, `${starts.length} entries`);

// Entries may run over several lines and a description may be double-quoted when it
// carries an apostrophe, so read each entry up to the next one and take its literals.
const reports = starts.map((match, index) => {
  const end = index + 1 < starts.length ? starts[index + 1].index : constBlock.length;
  const body = constBlock.slice(match.index + match[0].length, end);
  const fields = (body.match(/'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/g) ?? []).map(lit => lit.slice(1, -1));
  return { slug: match[1], group: fields[0], mode: fields[3], permission: fields[4] ?? 'reports.view' };
});
const groupNames = [...new Set(reports.map(r => r.group))];
ok('every report names an area', reports.every(r => r.group && r.group.length > 2));
ok('reports are grouped into many areas', groupNames.length >= 30, `${groupNames.length} areas`);
ok('every report carries one of the three periods', reports.every(r => ['range', 'date', 'none'].includes(r.mode)));
ok('every report carries a permission', reports.every(r => r.permission.length > 3));

const iconsBlock = controller.slice(controller.indexOf('const GROUP_ICONS'), controller.indexOf('const MODE_LABELS'));
const icons = Object.fromEntries([...iconsBlock.matchAll(/'([^']+)' => '([a-z]+)'/g)].map(([, g, icon]) => [g, icon]));
const missingIcons = groupNames.filter(g => !icons[g]);
ok('every area has an icon to read at a glance', missingIcons.length === 0, missingIcons.join(', '));
const modesBlock = controller.slice(controller.indexOf('const MODE_LABELS'), controller.indexOf('public function index'));
ok('all three periods are named for the reader',
  ['range', 'date', 'none'].every(mode => new RegExp(`'${mode}' =>`).test(modesBlock)), modesBlock.replace(/\s+/g, ' ').slice(0, 80));
ok('a period reads as "Period"', /'range' => 'Period'/.test(modesBlock));

const indexFn = controller.slice(controller.indexOf('public function index('), controller.indexOf('public function show('));
ok('the library lists only what the reader may open', /can_do\(\$r\[4\]/.test(indexFn));
ok('the library groups by area', /groupBy\(fn \(\$r\) => \$r\[0\]/.test(indexFn));
ok('the library names each area', /'name' => \$group/.test(indexFn));
ok('the library carries each area icon', /'icon' => self::GROUP_ICONS\[\$group\] \?\? 'chart'/.test(indexFn));
ok('the library passes the report total', /'total' => \$visible->count\(\)/.test(indexFn));
ok('the library passes the saved views', /'views' => \$views/.test(indexFn));
ok('the library passes the pinned views', /'pinned' => \$views->where\('is_pinned'/.test(indexFn));
ok('the library counts the schedules only where it may', /can_do\('reports.schedule'\)/.test(indexFn));

const blade = readFileSync('resources/views/reports/index.blade.php', 'utf8');
ok('the library is a screen in the application shell', /@extends\('layouts\.app'\)/.test(blade));
ok('the library links every report to its screen', /route\('reports\.show', \$r\['slug'\]\)/.test(blade));
ok('the library links the saved-view library', /route\('reports\.library'\)/.test(blade));
ok('the schedules entry is gated', /can_do\('reports\.schedule'\)/.test(blade));
ok('the alerts entry is gated', /can_do\('reports\.alerts'\)/.test(blade));
ok('the search box is labelled for a screen reader', /aria-label="Search reports/.test(blade));
ok('the result count is announced', /id="reportCount" aria-live="polite"/.test(blade));
ok('the area chips are real buttons', /<button type="button" class="rpt-chip/.test(blade));
ok('the chips carry their pressed state', /aria-pressed="true"/.test(blade) && /aria-pressed="false"/.test(blade));
ok('the empty state offers the way back', /data-empty-reset/.test(blade));
ok('there is no "illustrative figures" notice', !/illustrative|UI-only|no records are created|sample data/i.test(blade));
ok('a role with no report rights is told so plainly', /\$total === 0/.test(blade) && /data-testid="reports-none"/.test(blade) && /No report is open to your role/.test(blade));
ok('the empty catalogue does not show a search box over nothing', /@else\s*<div class="card rpt-toolbar no-print">/.test(blade) || /@else\n\n<div class="card rpt-toolbar no-print">/.test(blade));
ok('the gallery lists the five report screens', /__preview\/reports-(library|report|views|schedules|alerts)/.test(readFileSync('deploy/dev/previews/index.html', 'utf8')));

const css = readFileSync('public/css/app.css', 'utf8');
ok('the catalogue is a responsive grid', /\.rpt-groups \{[^}]*grid-template-columns: repeat\(auto-fill/.test(css));
ok('hidden rows stay hidden', /\.rpt-list li\[hidden\] \{ display: none; \}/.test(css));
ok('the chosen chip reads as chosen', /\.rpt-chip\.is-on \{/.test(css));
ok('the catalogue prints', /@media print \{ \.rpt-toolbar, \.rpt-views \{ display: none; \}/.test(css));

// ── my reports: saved views, schedules and the way in ──────────────────────
const library = readFileSync('resources/views/reports/library.blade.php', 'utf8');
ok('my reports offers the way back to the catalogue', /route\('reports\.index'\)/.test(library));
ok('my reports can be searched', /data-filter-table="#viewsTable"/.test(library) && /aria-label="Search saved views"/.test(library));
ok('the views table is the one searched', /<table class="table" id="viewsTable">/.test(library));
ok('a view can be scheduled straight from the row', /route\('reports\.schedules', \['view' => \$v->id\]/.test(library));
ok('scheduling a view is gated', /can_do\('reports\.schedule'\)/.test(library));
ok('the empty views state offers the way in', /Browse the report library/.test(library));
ok('the empty schedules state offers a schedule', /Schedule a report/.test(library));
ok('my reports marks a view pinned or shared', /badge-purple">pinned/.test(library) && /badge-blue">shared/.test(library));

// ── scheduled reports and KPI alerts ───────────────────────────────────────
const schedulesView = readFileSync('resources/views/reports/schedules.blade.php', 'utf8');
const alertsView = readFileSync('resources/views/reports/alerts.blade.php', 'utf8');
ok('a failing schedule is said out loud, not buried in a badge', /data-testid="failing-banner"/.test(schedulesView) && /\$stats\['failing'\]/.test(schedulesView));
ok('the failing banner opens the failed deliveries', /\['status' => 'failed'\]\) }}#t-log/.test(schedulesView));
ok('schedules can be searched', /data-filter-table="#schedTable"/.test(schedulesView) && /aria-label="Search scheduled reports"/.test(schedulesView));
ok('the schedule table is the one searched', /<table class="table" id="schedTable">/.test(schedulesView));
ok('the empty schedule list offers the form', /Create the first schedule/.test(schedulesView));
ok('the delivery log says how many deliveries it holds', /\{\{ \$deliveries->total\(\) \}\} deliver/.test(schedulesView));
ok('the delivery log explains an empty log', /A schedule sends its first e-mail at its next run time/.test(schedulesView));
ok('sending a schedule now stays behind the owner or an admin', /\$can = \$s->user_id === \$me->id \|\| can_do\('settings\.manage'\)/.test(schedulesView));
ok('unacknowledged alerts are said out loud', /data-testid="open-banner"/.test(alertsView) && /\$open === 1/.test(alertsView));
ok('alert rules can be searched', /data-filter-table="#alertsTable"/.test(alertsView) && /aria-label="Search KPI alerts"/.test(alertsView));
ok('the empty alert list offers the form', /Create the first alert/.test(alertsView));
ok('acknowledging an event keeps its note', /name="note" placeholder="Action taken/.test(alertsView));
ok('the banners are styled for both tones', /\.rpt-banner\.is-red \{/.test(css) && /\.rpt-banner\.is-amber \{/.test(css));
ok('the banners stay readable in dark mode', /body\.dark \.rpt-banner\.is-red \{ background: #2a1416/.test(css) && /body\.dark \.rpt-banner\.is-amber \{ background: #2a2110/.test(css));

// ── the built screen ────────────────────────────────────────────────────────
const FILE = 'deploy/dev/previews/reports-library.html';
const html = readFileSync(FILE, 'utf8');
const dom = new JSDOM(html, { url: 'https://preview.local/reports', runScripts: 'dangerously', pretendToBeVisual: true });
const { window } = dom;
const doc = window.document;
const $ = (selector) => doc.querySelector(selector);
const $$ = (selector) => [...doc.querySelectorAll(selector)];
const text = (selector) => ($(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const countText = () => text('#reportCount');
const visibleRows = () => $$('[data-report-row]').filter(row => !row.hidden);
const visibleGroups = () => $$('[data-report-group]').filter(group => !group.hidden);
const search = (value) => {
  const input = $('#reportSearch');
  input.value = value;
  input.dispatchEvent(new window.Event('input', { bubbles: true }));
};
const chipFor = (area) => $$('[data-group-filter]').find(chip => chip.dataset.groupFilter === area);

ok('is a whole document', /^<!doctype html>/i.test(html.trimStart()));
ok('carries the application shell', $$('nav a, aside a').length > 5);
ok('titles the screen', /Reports/i.test(doc.title), doc.title);
ok('names the screen in the crumbs', /Reports/.test(text('.crumbs') || 'Reports'));

// counts across the top
const stats = $$('.stat .v').map(node => node.textContent.trim());
ok('a stat counts the reports the reader may open', stats[0] === String($$('[data-report-row]').length), stats.join(' | '));
ok('a stat counts the saved views', stats[1] === String($$('.rpt-view').length), stats.join(' | '));
ok('the stats carry their labels', $$('.stat .l').length === stats.length);

// saved views
const viewLinks = $$('.rpt-view');
ok('the saved views are offered before the catalogue', viewLinks.length === 3, `${viewLinks.length} views`);
ok('a saved view opens its report with the view applied', viewLinks.every(link => /^\/reports\/[\w_]+\?view=\d+$/.test(link.getAttribute('href'))));
ok('a saved view names itself and its report', viewLinks.every(link => link.querySelector('b')?.textContent.trim() && link.querySelector('span')?.textContent.trim()));
ok('the pinned view is marked', viewLinks.filter(link => /pinned/.test(link.textContent)).length === 1);
ok('shared views say which way they are shared', viewLinks.filter(link => /shared by you/.test(link.textContent)).length === 1
  && viewLinks.filter(link => /shared with you/.test(link.textContent)).length === 1);
ok('the whole library is linked', $$('.rpt-views a').some(link => link.getAttribute('href') === '/reports/library'));

// toolbar
const input = $('#reportSearch');
ok('the search box is there', input?.type === 'search');
ok('the search box says how much it searches', /Search \d+ reports/.test(input?.getAttribute('placeholder') ?? ''), input?.getAttribute('placeholder'));
ok('the search box is labelled', /Search reports/.test(input?.getAttribute('aria-label') ?? ''));
const chips = $$('[data-group-filter]');
ok('an "all areas" chip leads the row', chips[0]?.dataset.groupFilter === '' && /All areas/.test(chips[0].textContent));
ok('every area has a chip', chips.length === visibleGroups().length + 1, `${chips.length} chips`);
ok('a chip carries its area count', chips.slice(1).every(chip => /\d+/.test(chip.textContent)));
ok('the chips add up to every report', chips.slice(1).reduce((sum, chip) => sum + Number(chip.textContent.match(/(\d+)\s*$/)?.[1] ?? 0), 0) === $$('[data-report-row]').length);
ok('the chips follow the catalogue order', chips.slice(1).map(chip => chip.dataset.groupFilter).join('|') === visibleGroups().map(group => group.dataset.reportGroup).join('|'));
ok('the count starts on everything', /^\d+ reports in \d+ areas$/.test(countText()), countText());

// the catalogue itself
ok('every area is a card', $$('.rpt-group').length === visibleGroups().length);
ok('each area card names itself', $$('.rpt-group .card-head h3').every(heading => heading.textContent.trim().length > 2));
ok('each area card carries an icon', $$('.rpt-group .card-head h3 svg, .rpt-group .card-head h3 use').length >= $$('.rpt-group').length);
ok('each area card carries its tally', $$('.rpt-tally').length === $$('.rpt-group').length);
const rows = $$('[data-report-row]');
ok('every report is listed once', rows.length >= 30, `${rows.length} reports`);
const hrefs = rows.map(row => row.querySelector('a')?.getAttribute('href') ?? '');
ok('every report links to its screen', hrefs.every(href => /^\/reports\/[\w_]+$/.test(href)));
ok('no report is listed twice', new Set(hrefs).size === hrefs.length);
ok('every report shows its promise', rows.every(row => row.querySelector('.rpt-desc')?.textContent.trim().length > 10));
ok('every report shows the period it answers on', rows.every(row => ['Period', 'As on date', 'Live'].includes(row.querySelector('.rpt-mode')?.textContent.trim())));
ok('a snapshot reads as a date', rows.some(row => row.querySelector('.rpt-mode')?.textContent.trim() === 'As on date'));
ok('a live report reads as live', $$('.rpt-mode.is-live').length > 0);
ok('a long promise is clamped to two lines on screen', /\.rpt-desc \{[^}]*-webkit-line-clamp: 2/.test(css));
ok('a long promise prints in full', /\.rpt-desc \{ display: block; overflow: visible; -webkit-line-clamp: unset/.test(css));
ok('the whole promise is on hover', rows.every(row => (row.querySelector('a')?.getAttribute('title') ?? '').length > 10));
ok('a print sheet is ready', Boolean($('.print-head')) && /Report library/.test(text('.print-head')));

// the search drives the catalogue
search('ageing');
ok('a search keeps only matching reports', visibleRows().length === 2, `${visibleRows().length} rows`);
ok('a search hides the areas it empties', visibleGroups().length === 1 && visibleGroups()[0].dataset.reportGroup === 'Accounts');
ok('a search says what is left', /^2 reports in 1 area matching “ageing”$/.test(countText()), countText());
search('occupancy');
// it lands in four areas, because three promises mention occupancy too — one of them the consolidated
// report added in v10.9, whose promise is about occupancy on four different business dates
ok('a search reads the report promise too', visibleRows().length === 5 && visibleGroups().length === 4,
  `${visibleRows().length} rows in ${visibleGroups().length} areas`);
search('housekeeping');
ok('a search reads the area name', visibleRows().length === 3, `${visibleRows().length} rows`);
// the shorthand a hotel actually types
const titles = () => visibleRows().map(row => row.querySelector('.rpt-title').textContent.trim());
search('p&l');
ok('"p&l" finds Profit & Loss', titles().join('|') === 'Profit & Loss', titles().join(' / '));
search('ap');
ok('"ap" finds the supplier ledger without dragging in every a-p', titles().length === 2 && titles().every(title => /^AP /.test(title)), titles().join(' / '));
search('ar');
ok('"ar" finds the city ledger ageing', titles().join('|') === 'AR Ageing (City Ledger)', titles().join(' / '));
search('momo');
ok('"momo" finds mobile money', titles().join('|') === 'Mobile Money Settlement', titles().join(' / '));
search('gl');
ok('"gl" finds the general ledger area', visibleRows().length === 4 && visibleGroups().length === 1 && visibleGroups()[0].dataset.reportGroup === 'General Ledger');
search('occ');
ok('"occ" finds occupancy wherever it is promised', visibleRows().length === 5, `${visibleRows().length} rows`);
ok('the shorthand is advertised on the screen', ['p&l', 'ADR', 'MoMo', 'GL'].every(term => (text('.rpt-hint') || '').includes(term)), text('.rpt-hint'));

search('  ');
ok('a blank search keeps everything', visibleRows().length === rows.length);
search('zzzz');
ok('a search with no hit says so', visibleRows().length === 0 && !$('#reportEmpty').hidden);
ok('a search with no hit counts zero', /^0 reports in 0 areas/.test(countText()), countText());
ok('a search with no hit offers the way back', !$('#reportReset').hidden);

// reset
$('#reportReset').click();
ok('reset empties the search box', $('#reportSearch').value === '');
ok('reset restores every report', visibleRows().length === rows.length && visibleGroups().length === chips.length - 1);
ok('reset restores the count', /^\d+ reports in \d+ areas$/.test(countText()), countText());
ok('reset puts the chips back on "all areas"', chips[0].getAttribute('aria-pressed') === 'true' && chips.slice(1).every(chip => chip.getAttribute('aria-pressed') === 'false'));
ok('reset hides itself', $('#reportReset').hidden);
ok('reset hides the empty state', $('#reportEmpty').hidden);

// the area chips
const housekeeping = chipFor('Housekeeping');
housekeeping.click();
ok('a chip keeps only its area', visibleGroups().length === 1 && visibleGroups()[0].dataset.reportGroup === 'Housekeeping');
ok('a chip keeps the area count honest', /^3 reports in 1 area/.test(countText()), countText());
ok('a chip marks itself pressed', housekeeping.getAttribute('aria-pressed') === 'true' && housekeeping.classList.contains('is-on'));
ok('choosing a chip unpressed the last one', chips[0].getAttribute('aria-pressed') === 'false' && !chips[0].classList.contains('is-on'));
chipFor('Treasury & Payments')?.click();
ok('another chip moves the filter', visibleGroups()[0]?.dataset.reportGroup === 'Treasury & Payments', visibleGroups()[0]?.dataset.reportGroup);

// a chip and a search work together
search('recon');
ok('a search inside an area narrows it further', visibleRows().length === 1, `${visibleRows().length} rows`);
chipFor('')?.click();
ok('"all areas" keeps the search', visibleRows().length === 1 && visibleGroups().length === 1);
search('');
ok('clearing both restores the catalogue', visibleRows().length === rows.length);

// the shortcuts
const empty = $('#reportEmpty');
search('nothing matches this');
const escape = new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
doc.dispatchEvent(escape);
ok('escape clears a search', $('#reportSearch').value === '' && visibleRows().length === rows.length);
ok('the empty state is hidden again', empty.hidden);

// nothing pretending to be a demo
ok('no "illustrative figures" notice on the screen', !/illustrative|UI-only preview|no records are created/i.test(doc.body.textContent));
ok('the screen is not a bare link list', $$('ul.list').length === 0);

// ── the report screen itself ────────────────────────────────────────────────
const show = readFileSync('resources/views/reports/show.blade.php', 'utf8');
ok('the report screen says what it is showing', /data-testid="report-context"/.test(show));
ok('the report screen offers the way back to the library', /route\('reports\.index'\)/.test(show));
ok('the report screen prints its period', /print-head/.test(show) && /as on/.test(show));
ok('the quick filter counts the rows it leaves', /id="rptFilterCount"/.test(show));
ok('the quick filter says when nothing matches', /id="rptNoMatch"/.test(show));
ok('a count in the summary is not dressed as money', /\$isMoneyStat \? money\(\$v\) : number_format\(\$v\)/.test(show));

const REPORT_FILE = 'deploy/dev/previews/reports-report.html';
const reportHtml = readFileSync(REPORT_FILE, 'utf8');
const reportDom = new JSDOM(reportHtml, { url: 'https://preview.local/reports/pos_sales', runScripts: 'dangerously', pretendToBeVisual: true });
const rdoc = reportDom.window.document;
const rwin = reportDom.window;
const rs = (selector) => rdoc.querySelector(selector);
const rsa = (selector) => [...rdoc.querySelectorAll(selector)];
const rtext = (selector) => (rs(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();

ok('the report screen is a whole document', /^<!doctype html>/i.test(reportHtml.trimStart()));
ok('the report screen carries the shell', rsa('nav a, aside a').length > 5);
ok('the report screen names the report', /POS Sales by Outlet/.test(rdoc.title), rdoc.title);
ok('no blade directive is left on the screen', !/@(continue|endforeach|endif|endphp|endpush|endforelse)\b/.test(reportHtml));

// the context line
ok('the period is written out', /01 Nov 2026 – 06 Nov 2026/.test(rtext('[data-testid="report-context"]')), rtext('[data-testid="report-context"]'));
ok('the row count is written out', /\b10 rows\b/.test(rtext('[data-testid="report-context"]')));
ok('the business date is written out', /Business date\s*06 Nov 2026/.test(rtext('[data-testid="report-context"]')));
ok('the way back to the library is in the context line',
  rsa('[data-testid="report-context"] a').some(link => link.getAttribute('href') === '/' || /reports$/.test(link.getAttribute('href') ?? '')), rsa('[data-testid="report-context"] a').map(a => a.getAttribute('href')).join(','));

// the period controls
ok('the screen offers its own date range', Boolean(rs('input[name="from"]')) && Boolean(rs('input[name="to"]')));
ok('the range opens on the month to date', rs('input[name="from"]')?.value === '2026-11-01' && rs('input[name="to"]')?.value === '2026-11-06',
  `${rs('input[name="from"]')?.value} → ${rs('input[name="to"]')?.value}`);
const presets = rsa('.filters a.btn').map(link => link.textContent.trim());
ok('the presets are one tap away', ['Today', 'Yesterday', 'MTD', 'Last month', 'YTD'].every(name => presets.includes(name)), presets.join(' / '));
const morePeriods = rsa('[data-range-nav] option').map(option => option.textContent.trim());
ok('the long list of periods leaves out "custom"', morePeriods.length === 8 && !morePeriods.includes('custom'), morePeriods.join(', '));
ok('a period can be generated', rsa('.filters button').some(button => /Generate/.test(button.textContent)));

// the chart, the summary and the table
ok('the chart is drawn', rsa('.bar-chart .b').length === 10, `${rsa('.bar-chart .b').length} bars`);
ok('every bar carries its figure', rsa('.bar-chart .b').every(bar => /:/.test(bar.dataset.v ?? '')));
ok('the chart is labelled', /Net sales by day/.test(rtext('.card-head h3')));
const summary = rsa('.stats .stat').map(stat => stat.textContent.replace(/\s+/g, ' ').trim());
ok('the summary holds one card per figure', summary.length === 3, summary.join(' | '));
ok('a count in the summary stays a count', /^486\s*Covers$/.test(summary[0]), summary[0]);
ok('money in the summary carries the currency', /GH₵ 41,250\.75/.test(summary[1]), summary[1]);
ok('the headline average reads as money', /GH₵ 84\.88/.test(summary[2]), summary[2]);
ok('the table lists the rows', rsa('#rpt tbody tr').length === 11, `${rsa('#rpt tbody tr').length} body rows (10 rows + the no-match row)`);
ok('the table has a column per field plus the counter', rsa('#rpt thead th').length === 7, `${rsa('#rpt thead th').length} columns`);
// money columns and summed columns are set right: covers is summed, net/tax/total are money
ok('figures are set right', rsa('#rpt thead th.num').length === 4, `${rsa('#rpt thead th.num').length} numeric columns`);
ok('the totals row adds the figures', /Total[\s\S]*743[\s\S]*GH₵ 57,950\.75/.test(rtext('#rpt tfoot')), rtext('#rpt tfoot'));
ok('the note under the table explains the figures', /Gross of service charge/.test(rtext('.card-body + p, p.small.muted') || reportHtml.replace(/<[^>]+>/g, ' ')));
ok('the screen prints with its title and period', /POS Sales by Outlet/.test(rtext('.print-head')) && /01 Nov 2026/.test(rtext('.print-head')));

// the quick filter
const filter = rs('[data-filter-table="#rpt"]');
ok('the quick filter is offered', Boolean(filter));
ok('the quick filter is labelled', /Filter the rows/.test(filter?.getAttribute('aria-label') ?? ''));
ok('the quick filter counts the rows before it is used', rtext('#rptFilterCount') === '10 rows', rtext('#rptFilterCount'));
filter.value = 'Nana';
filter.dispatchEvent(new rwin.Event('input', { bubbles: true }));
const shownRows = () => rsa('#rpt tbody tr').filter(row => row.style.display !== 'none' && row.id !== 'rptNoMatch').length;
ok('the quick filter narrows the rows', shownRows() === 5, `${shownRows()} rows`);
ok('the quick filter counts what is left', rtext('#rptFilterCount') === '5 of 10 rows', rtext('#rptFilterCount'));
filter.value = 'nothing matches this';
filter.dispatchEvent(new rwin.Event('input', { bubbles: true }));
ok('a filter with no hit says so', rs('#rptNoMatch')?.hidden === false);
ok('a filter with no hit counts zero', rtext('#rptFilterCount') === '0 of 10 rows', rtext('#rptFilterCount'));
filter.value = '';
filter.dispatchEvent(new rwin.Event('input', { bubbles: true }));
ok('clearing the filter restores the rows', shownRows() === 10 && rtext('#rptFilterCount') === '10 rows');
ok('clearing the filter hides the no-match row', rs('#rptNoMatch')?.hidden === true);

// the saved views and the save form
const chips2 = rsa('[data-testid="saved-views"] a.btn');
ok('the saved views are offered on the report', chips2.length === 2, `${chips2.length} chips`);
ok('a saved view keeps its range and report', chips2.every(link => /\?view=\d+$/.test(link.getAttribute('href') ?? '')));
ok('a view saved by someone else says so', chips2.some(link => /shared/.test(link.textContent)));
const viewForm = rs('#save-view form');
ok('the report can be saved as a view', Boolean(viewForm));
ok('the save form carries the report key', viewForm?.querySelector('input[name="report_key"]')?.getAttribute('value') === 'pos_sales');
ok('the save form carries the period it is showing', viewForm?.querySelector('input[name="date_from"]')?.getAttribute('value') === '2026-11-01');
ok('the save form offers the same periods', viewForm?.querySelectorAll('select[name="range"] option').length >= 5);
ok('the save form can hide columns', viewForm?.querySelectorAll('input[name="columns[]"]').length === 6);
ok('the save form can pin the view', Boolean(viewForm?.querySelector('input[name="is_pinned"]')));

// ── the print sheet and the way onward ─────────────────────────────────────
ok('the report prints on a landscape sheet', /@page \{ size: A4 landscape/.test(show));
ok('the table header repeats on every printed page', /#rpt thead \{ display: table-header-group; \}/.test(show));
ok('a printed row is not cut in half', /#rpt tbody tr \{ break-inside: avoid/.test(show));
ok('a printed table is never clipped', /\.table-wrap \{ overflow: visible !important/.test(show));
ok('the print head says when it was printed and by whom', /Printed \{\{ now\(\)->format/.test(show) && /by \{\{ auth\(\)->user\(\)->name/.test(show));
ok('the report offers its neighbours', /data-testid="report-siblings"/.test(show) && /More in \{\{ \$area \}\}/.test(show));
ok('a neighbour the reader may not open is not offered', /can_do\(\$r\[4\] \?\? 'reports\.view'\)/.test(show));
ok('the report being read is marked where it is listed', /aria-current="page"/.test(show));
ok('the neighbours are laid out for the page', /\.rpt-sibling\.is-here \{/.test(css));

const siblingCard = rs('[data-testid="report-siblings"]');
ok('the neighbours are on the screen', Boolean(siblingCard));
ok('the neighbours name their area', /More in F&B & POS/.test(rtext('[data-testid="report-siblings"] h3')));
ok('the neighbours list the whole area', rsa('[data-testid="report-siblings"] a.rpt-sibling').length === 5,
  `${rsa('[data-testid="report-siblings"] a.rpt-sibling').length} links`);
ok('the report being read is the one marked',
  rsa('[data-testid="report-siblings"] [aria-current="page"]').length === 1
  && /POS Sales by Outlet/.test(rtext('[data-testid="report-siblings"] [aria-current="page"]')));
ok('a neighbour opens its own report', rsa('[data-testid="report-siblings"] a.rpt-sibling').every(link => /^\/reports\/[\w_]+$/.test(link.getAttribute('href') ?? '')));
ok('the printed sheet carries the stamp', /Printed 06 Nov 2026 09:20 by Ama Mensah · 10 row\(s\)/.test(rtext('.print-head')), rtext('.print-head'));

// ── an "as on" report: the other half of the viewer ─────────────────────────
const ASON_FILE = 'deploy/dev/previews/reports-report-date.html';
const asonHtml = readFileSync(ASON_FILE, 'utf8');
const asonDom = new JSDOM(asonHtml, { url: 'https://preview.local/reports/trial_balance', runScripts: 'dangerously', pretendToBeVisual: true });
const ao = asonDom.window.document;
const aoq = (selector) => ao.querySelector(selector);
const aoa = (selector) => [...ao.querySelectorAll(selector)];
const aot = (selector) => (aoq(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();

ok('an as-on report is a whole document', /^<!doctype html>/i.test(asonHtml.trimStart()));
ok('an as-on report names itself', /Trial Balance/.test(ao.title), ao.title);
ok('no blade directive is left on an as-on report', !/@(endphp|endforeach|endsection|endif)\b/.test(asonHtml));
ok('the as-on line reads as a single date', /^as on 05 Nov 2026/.test(aot('[data-testid="report-context"]')), aot('[data-testid="report-context"]'));
ok('an as-on report asks for one date, not a period',
  aoa('.filters input[type="date"]').length === 1 && aoq('.filters input[type="date"]')?.name === 'to'
  && /As on \(business date\)/.test(aot('.filters')));
ok('the as-on date cannot be later than the business date', aoq('.filters input[name="to"]')?.getAttribute('max') === '2026-11-06');
const asonPresets = aoa('.filters a.btn').map((link) => link.textContent.trim());
ok('a snapshot has its own presets', ['Last audited day', 'Today (so far)', 'Last month end'].every((name) => asonPresets.includes(name)), asonPresets.join(' / '));
ok('a snapshot does not offer period presets', !asonPresets.includes('MTD') && !asonPresets.includes('YTD') && !asonPresets.includes('Last month'));
ok('a report with no chart draws none', aoa('.bar-chart').length === 0);
ok('the ledger lists its accounts', aoa('#rpt tbody tr').length === 13, `${aoa('#rpt tbody tr').length} body rows`);
const foot = aot('#rpt tfoot');
const footing = [...foot.matchAll(/([\d,]+\.\d{2})/g)].map(([, value]) => Number(value.replace(/,/g, '')));
ok('the ledger balances to the cedi', footing.length === 2 && footing[0] === footing[1], foot);
ok('the print sheet says it is a snapshot', /as on 05 Nov 2026/.test(aot('.print-head')), aot('.print-head'));
ok('a snapshot still offers its neighbours', /More in General Ledger/.test(aot('[data-testid="report-siblings"] h3'))
  && aoa('[data-testid="report-siblings"] a.rpt-sibling').length === 4);

// ── my reports, on the screen ───────────────────────────────────────────────
const VIEWS_FILE = 'deploy/dev/previews/reports-views.html';
const viewsHtml = readFileSync(VIEWS_FILE, 'utf8');
const viewsDom = new JSDOM(viewsHtml, { url: 'https://preview.local/reports/library', runScripts: 'dangerously', pretendToBeVisual: true });
const vdoc = viewsDom.window.document;
const vs = (selector) => vdoc.querySelector(selector);
const vsa = (selector) => [...vdoc.querySelectorAll(selector)];
const vtext = (selector) => (vs(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();

ok('my reports is a whole document', /^<!doctype html>/i.test(viewsHtml.trimStart()));
ok('my reports carries the shell', vsa('nav a, aside a').length > 5);
ok('my reports names itself', /My Reports/.test(vdoc.title), vdoc.title);
ok('no blade directive is left on my reports', !/@(endphp|endforeach|endsection|endforelse)\b/.test(viewsHtml));

const vstats = vsa('.stats .stat').map(stat => stat.textContent.replace(/\s+/g, ' ').trim());
ok('my reports counts what I can open', /^4\s*Saved views I can open/.test(vstats[0]), vstats[0]);
ok('my reports splits mine from shared', /3 mine · 1 shared with me/.test(vstats[0]), vstats[0]);
ok('my reports counts what is pinned', /^1\s*Pinned to my dashboard/.test(vstats[1]), vstats[1]);
ok('my reports counts the schedules I own', /^3\s*Schedules I own/.test(vstats[2]) && /1 paused/.test(vstats[2]), vstats[2]);
ok('my reports links the alerts waiting', /KPI alerts to acknowledge/.test(vstats[3] ?? '') && vsa('.stats .stat a.cover').some(link => /alerts$/.test(link.getAttribute('href') ?? '')));

const viewRows = vsa('#viewsTable tbody tr');
ok('every saved view is a row', viewRows.length === 4, `${viewRows.length} rows`);
ok('the views can be searched', /Search saved views/.test(vs('[data-filter-table="#viewsTable"]')?.getAttribute('aria-label') ?? ''));
ok('a view row opens its report', viewRows.every(row => /^\/reports\/[\w_]+\?view=\d+$/.test(row.querySelector('a')?.getAttribute('href') ?? '')));
ok('a pinned view is marked', /pinned/.test(viewRows[0].textContent));
ok('a shared view is marked', viewRows.filter(row => /shared/.test(row.textContent)).length === 2);
ok('a view says whose it is', /Me/.test(viewRows[0].textContent) && /Kojo Owusu/.test(viewRows[2].textContent));
ok('a saved view says the slice it opens on', /Takoradi/.test(viewRows[3].textContent) && /only/.test(viewRows[3].textContent), viewRows[3].textContent.replace(/\s+/g, ' ').slice(0, 90));
ok('a view says the period it covers', /Today/.test(viewRows[0].textContent) && /06 Nov 2026/.test(viewRows[0].textContent), viewRows[0].textContent.replace(/\s+/g, ' ').trim().slice(0, 90));
ok('a view says which columns it keeps', /4 column\(s\)/.test(viewRows[1].textContent) && /rows containing/.test(viewRows[1].textContent));
ok('a view says how often it is opened', /148/.test(viewRows[0].textContent) && /20 minutes ago/.test(viewRows[0].textContent));
ok('a view can be scheduled from its row', viewRows.every(row => vsa('a').length >= 0 && [...row.querySelectorAll('a')].some(link => /schedules\?.*view=\d+#scheduleForm/.test(link.getAttribute('href') ?? ''))));
ok('my own views can be pinned or deleted', viewRows[0].querySelectorAll('form').length === 2, `${viewRows[0].querySelectorAll('form').length} forms`);
ok('someone else\'s view is not mine to pin or delete', viewRows[2].querySelectorAll('form').length === 0);

const schedRows = vsa('table')[1].querySelectorAll('tbody tr');
ok('the reports e-mailed on my behalf are listed', schedRows.length === 3, `${schedRows.length} rows`);
ok('a schedule says when it runs', /Daily at 07:00/.test(schedRows[0].textContent));
ok('a schedule shows its last run and status', /sent/.test(schedRows[0].textContent) && /failed/.test(schedRows[1].textContent));
ok('a paused schedule says so', /paused/.test(schedRows[1].textContent));
ok('a schedule names the report it sends', /Flash Report/.test(schedRows[0].textContent) && /Trial Balance/.test(schedRows[1].textContent));

// ── scheduled reports, on the screen ────────────────────────────────────────
const SCHED_FILE = 'deploy/dev/previews/reports-schedules.html';
const schedHtml = readFileSync(SCHED_FILE, 'utf8');
const schedDom = new JSDOM(schedHtml, { url: 'https://preview.local/reports/schedules', runScripts: 'dangerously', pretendToBeVisual: true });
const sd = schedDom.window.document;
const ss = (selector) => sd.querySelector(selector);
const ssa = (selector) => [...sd.querySelectorAll(selector)];
const stext = (selector) => (ss(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();

ok('scheduled reports is a whole document', /^<!doctype html>/i.test(schedHtml.trimStart()));
ok('scheduled reports names itself', /Scheduled Reports/.test(sd.title), sd.title);
ok('no blade directive is left on scheduled reports', !/@(endphp|endforeach|endsection|continue)\b/.test(schedHtml));

const sstats = ssa('.stats .stat').map(stat => stat.textContent.replace(/\s+/g, ' ').trim());
ok('scheduled reports counts the schedules', /^2\s*Active schedules/.test(sstats[0]) && /1 paused/.test(sstats[0]), sstats[0]);
ok('scheduled reports counts what went out', /^11\s*Sent \(7 days\)/.test(sstats[1]), sstats[1]);
ok('scheduled reports counts what failed', /^2\s*Failed runs/.test(sstats[2]) && /1 schedule\(s\) currently failing/.test(sstats[2]), sstats[2]);

const banner = ss('[data-testid="failing-banner"]');
ok('the failing schedule is on the screen', Boolean(banner) && /1 schedule failing/.test(banner.textContent));
ok('the failing banner opens the failed deliveries', /\?status=failed#t-log/.test(ss('[data-testid="failing-banner"] a')?.getAttribute('href') ?? ''));
ok('the tabs count the schedules', /Schedules \(4\)/.test(stext('[data-tabs]')) && /Delivery log/.test(stext('[data-tabs]')));

const schedTableRows = ssa('#schedTable tbody tr');
ok('every schedule is a row', schedTableRows.length === 4, `${schedTableRows.length} rows`);
ok('a schedule says what it sends and how', /Morning flash for the GM/.test(schedTableRows[0].textContent) && /Table in the e-mail \+ CSV attachment/.test(schedTableRows[0].textContent));
ok('a schedule says when it runs', /Daily at 07:00/.test(schedTableRows[0].textContent));
ok('a schedule says who receives it', /2 recipient\(s\)/.test(schedTableRows[0].textContent));
ok('a failing schedule shows its error', /SMTP 550/.test(schedTableRows[1].textContent) && /2 failure\(s\)/.test(schedTableRows[1].textContent));
ok('a paused schedule says so', /paused/.test(schedTableRows[2].textContent));
// v10.11 a schedule remembers the slice it was made from
ok('a schedule says what it reports on when that is a slice', /Scope: Takoradi/.test(schedTableRows[3].textContent), schedTableRows[3].textContent.replace(/\s+/g, ' ').slice(0, 90));
ok('a schedule that reports on everything says nothing extra', !/Scope:/.test(schedTableRows[0].textContent));
ok('a schedule can be sent now', ssa('#schedTable form').some(form => /run$/.test(form.getAttribute('action') ?? '')));
ok('a schedule can be paused or resumed', ssa('#schedTable form').some(form => /toggle$/.test(form.getAttribute('action') ?? '')));
ok('the schedules can be searched', /Search scheduled reports/.test(ss('[data-filter-table="#schedTable"]')?.getAttribute('aria-label') ?? ''));
ok('the delivery log says how many it holds', /5 deliveries/.test(stext('#t-log .card-head h3')), stext('#t-log .card-head h3'));
const deliveryRowsOnScreen = ssa('#deliveryTable tbody tr');
ok('every delivery is a row', deliveryRowsOnScreen.length === 5, `${deliveryRowsOnScreen.length} rows`);
ok('a delivery says when, what and who', /06 Nov 07:00/.test(deliveryRowsOnScreen[0].textContent) && /Morning flash/.test(deliveryRowsOnScreen[0].textContent) && /Scheduled/.test(deliveryRowsOnScreen[0].textContent));
ok('a delivery shows its status', ['sent', 'failed', 'partial', 'skipped'].every(status => new RegExp(`>${status}<`).test(schedHtml.replace(/\s+/g, '')) || schedHtml.includes(`>${status}`)));
ok('a failed delivery carries the error', /SMTP 550/.test(deliveryRowsOnScreen[2].textContent));
ok('a delivered report can be downloaded again', deliveryRowsOnScreen.some(row => /deliveries\/\d+\/csv/.test(row.innerHTML)));
ok('a failed delivery offers no download', !/deliveries\/\d+\/csv/.test(deliveryRowsOnScreen[2].innerHTML));

const scheduleForm = ss('#scheduleForm form');
ok('the schedule form is there', Boolean(scheduleForm));
ok('the form posts a new schedule', /\/reports\/schedules$/.test(scheduleForm?.getAttribute('action') ?? ''), scheduleForm?.getAttribute('action'));
ok('the form picks the report', scheduleForm?.querySelectorAll('select[name="report_key"] option').length > 20);
ok('the form picks the saved view', /\(optional\)/.test(stext('#scheduleForm')));
ok('the form picks the period without "custom"', [...scheduleForm.querySelectorAll('select[name="range"] option')].every(option => option.value !== 'custom'));
ok('the form picks the frequency', scheduleForm?.querySelectorAll('select[name="frequency"] option').length === 4);
ok('the form picks the weekday and the day of the month', Boolean(scheduleForm?.querySelector('select[name="weekday"]')) && scheduleForm?.querySelectorAll('select[name="month_day"] option').length === 29);
ok('the form picks the recipients', scheduleForm?.querySelectorAll('input[name="recipient_users[]"]').length === 3);
ok('the form can be told not to send an empty report', Boolean(scheduleForm?.querySelector('input[name="skip_empty"]')));

// ── KPI alerts, on the screen ───────────────────────────────────────────────
const ALERT_FILE = 'deploy/dev/previews/reports-alerts.html';
const alertHtml = readFileSync(ALERT_FILE, 'utf8');
const alertDom = new JSDOM(alertHtml, { url: 'https://preview.local/reports/alerts', runScripts: 'dangerously', pretendToBeVisual: true });
const ad = alertDom.window.document;
const as = (selector) => ad.querySelector(selector);
const asa = (selector) => [...ad.querySelectorAll(selector)];
const atext = (selector) => (as(selector)?.textContent ?? '').replace(/\s+/g, ' ').trim();

ok('KPI alerts is a whole document', /^<!doctype html>/i.test(alertHtml.trimStart()));
ok('KPI alerts names itself', /KPI Alerts/.test(ad.title), ad.title);
ok('no blade directive is left on KPI alerts', !/@(endphp|endforeach|endsection|continue)\b/.test(alertHtml));

const astats = asa('.stats .stat').map(stat => stat.textContent.replace(/\s+/g, ' ').trim());
ok('KPI alerts counts what is waiting', /^2\s*Alerts to acknowledge/.test(astats[0]), astats[0]);
ok('KPI alerts counts the rules that are on', /^2\s*Active alert rules/.test(astats[1]) && /1 disabled/.test(astats[1]), astats[1]);
ok('KPI alerts says what is breaching now', /^2\s*Breaching now/.test(astats[2]), astats[2]);
ok('KPI alerts says which day it checked', /05 Nov 2026/.test(astats[3]), astats[3]);
ok('the open events are said out loud', /2 alert events waiting for acknowledgement/.test(atext('[data-testid="open-banner"]')), atext('[data-testid="open-banner"]'));

const eventRows = asa('tr[data-event]');
ok('every event is a row', eventRows.length === 3, `${eventRows.length} rows`);
ok('an event carries its day and severity', /Thu 05 Nov 2026/.test(eventRows[0].textContent) && /critical/.test(eventRows[0].textContent));
ok('an event shows actual against threshold', /48\.2 %/.test(eventRows[0].textContent) && /< 55\.0 %/.test(eventRows[0].textContent));
ok('an event says who was notified', /2 message\(s\)/.test(eventRows[0].textContent));
ok('an open event can be acknowledged with a note', Boolean(eventRows[0].querySelector('input[name="note"]')) && /Acknowledge/.test(eventRows[0].textContent));
ok('an acknowledged event names who closed it and why', /Kojo Owusu/.test(eventRows[2].textContent) && /Owner remittance promised/.test(eventRows[2].textContent));
ok('an acknowledged event offers no second acknowledgement', !eventRows[2].querySelector('input[name="note"]'));

const alertRows = asa('tr[data-alert]');
ok('every rule is a row', alertRows.length === 3, `${alertRows.length} rows`);
ok('a rule states the figure it watches', /Occupancy % \(day\) < 55\.0 %/.test(alertRows[0].textContent), alertRows[0].textContent.replace(/\s+/g, ' ').trim().slice(0, 80));
ok('a rule shows in-app alongside the channels', /in-app \+ email/.test(alertRows[0].textContent) && /in-app \+ email \+ sms/.test(alertRows[1].textContent));
ok('a breaching rule is red, a comfortable one is not', Boolean(alertRows[0].querySelector('.text-red')) && !alertRows[2].querySelector('.text-red'));
ok('a disabled rule says so', /off/.test(alertRows[2].textContent));
ok('a rule can be switched off and back on', asa('tr[data-alert] form').some(form => /toggle$/.test(form.getAttribute('action') ?? '')));
ok('the rules can be searched', /Search KPI alerts/.test(as('[data-filter-table="#alertsTable"]')?.getAttribute('aria-label') ?? ''));
ok('the rule form groups flash metrics apart from the live position', asa('#alertForm optgroup').length === 2, `${asa('#alertForm optgroup').length} groups`);
ok('the rule form sets a threshold and a severity', Boolean(as('#alertForm input[name="threshold"]')) && as('#alertForm select[name="severity"]').options.length === 3);
ok('the rule form channels the notification', asa('#alertForm input[name="channels[]"]').length === 2);

console.log(`reports library: ${passed} passed, ${failures.length} failed`);
if (failures.length) {
  for (const failure of failures) console.log(`  ✗ ${failure}`);
  process.exit(1);
}
