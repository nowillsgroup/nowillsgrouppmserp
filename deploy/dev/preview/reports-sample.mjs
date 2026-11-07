/**
 * Sample data for the Report library preview.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/reports/index.blade.php against this fixed catalogue: the areas the
 * property files reports into, a working cross-section of the reports in each, and the
 * saved views a reader would see. The shapes mirror ReportController::index() so the
 * screen composes exactly as it does in the application. Only the property and the
 * figures are fixed.
 */
import { Coll, SampleDate } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-06';
const ME = { id: 7, name: 'Ama Mensah', initials: 'AM', role: 'Front Office Manager' };

// [slug, title, one-line promise, mode] — mode is Period | As on date | Live
const GROUPS = [
  ['Front Office', 'bed', [
    ['arrivals_today', 'Arrivals Today', 'Every expected arrival for the business date with room, rate, source and deposit', 'As on date'],
    ['departures_today', 'Departures Today', 'Who is leaving, what they owe and whether the folio is ready to settle', 'As on date'],
    ['in_house', 'In-house Guest List', 'Everyone currently in a room, with nights, rate and balance', 'Live'],
    ['no_shows', 'No-shows', 'Expected arrivals that were never checked in, with the charge applied', 'Period'],
    ['occupancy', 'Rooms Occupancy', 'Rooms sold, rooms available and occupancy by night', 'Period'],
    ['rate_check', 'Rate Check', 'Rate actually charged against the room type and rate plan for each stay', 'Period'],
  ]],
  ['F&B & POS', 'coffee', [
    ['pos_sales', 'POS Sales by Outlet', 'Net sales, covers and average check for every outlet that traded', 'Period'],
    ['pos_items', 'Item Sales Mix', 'What sold, by menu item, with quantity and revenue share', 'Period'],
    ['pos_voids', 'Voids & Discounts', 'Every void and discount with who authorised it and why', 'Period'],
    ['pos_shift', 'Shift Totals', 'Cashier shift takings by payment mode, with expected versus counted cash', 'Period'],
    ['menu_margin', 'Menu Margins', 'Contribution per dish after food cost and side-dish rate', 'Period'],
  ]],
  ['Housekeeping', 'broom', [
    ['hk_board', 'Housekeeping Board', 'Room state, cleaning credit and turnaround time for the day', 'Live'],
    ['hk_credits', 'Room Attendant Credits', 'Rooms cleaned per attendant, with credits and inspection results', 'Period'],
    ['laundry_linen', 'Linen Movement', 'Linen sent, returned and short, by item and by day', 'Period'],
  ]],
  ['Accounts', 'wallet', [
    ['ap_ageing', 'AP Ageing (Suppliers)', 'Supplier balances by days past due, with unbilled goods receipts and unapplied credits', 'As on date'],
    ['ar_ageing', 'AR Ageing (City Ledger)', 'Who owes the hotel, how old the debt is and what is on hold', 'As on date'],
    ['allotment_utilisation', 'Allotment Utilisation', 'Room nights allotted, picked up, released unsold and still held per account', 'Period'],
    ['ap_register', 'AP Register', 'Every supplier invoice captured, with tax and payment status', 'Period'],
  ]],
  ['General Ledger', 'book', [
    ['trial_balance', 'Trial Balance', 'Every account with its debit and credit balance as at the last closed day', 'As on date'],
    ['gl_pnl', 'Profit & Loss', 'Revenue, cost of sales and expenses for the period against the same period last year', 'Period'],
    ['balance_sheet', 'Balance Sheet', 'Assets, liabilities and equity as at the last closed day', 'As on date'],
    ['journal_register', 'Journal Register', 'Manual and system journals posted, with the person who posted them', 'Period'],
  ]],
  ['Revenue Management', 'chart', [
    ['rm_pickup', 'Pickup & Pace', 'Bookings taken over the last fourteen days for each future arrival date', 'Period'],
    ['rm_forecast', 'Forecast Occupancy', 'Projected occupancy, ADR and RevPAR for the next thirty days', 'Period'],
    ['channel_parity', 'Rate Parity', 'Our public rate against every connected channel, day by day', 'Period'],
  ]],
  ['Treasury & Payments', 'money', [
    ['cash_position', 'Cash Position', 'Cash at the desk, in the safe and in the bank as at the business date', 'As on date'],
    ['bank_recon', 'Bank Reconciliation', 'Statement lines matched, unmatched and in dispute', 'Period'],
    ['momo_settlement', 'Mobile Money Settlement', 'MoMo collected, settled and still in transit, by network', 'Period'],
  ]],
  ['Management & MIS', 'trend', [
    ['flash', 'Flash Report (Day / MTD / YTD)', "Manager's daily flash: occupancy, ADR, RevPAR, revenue, collections and the day's comparisons", 'Period'],
    ['kpi_scorecard', 'KPI Scorecard', 'The scored board: every KPI, its target, its result and who owns it', 'Period'],
    ['management_pack', 'Management Pack', 'The month in one pack: trading, payroll, cost of sales and capital, ready to circulate', 'Period'],
  ]],
  ['Hotel Group & Owner Reporting', 'building', [
    // v10.9 the consolidated report: the one report that reads more than one property
    ['group_operating_summary', 'Consolidated Operating Summary', 'Every property you may open on its own business date: rooms, occupancy, in-house, arrivals and departures, revenue today and month to date, ADR and collections — a property licensed for nothing is out of the totals and named beneath them', 'Live'],
    ['hotel_groups', 'Hotel Group Register', 'The groups behind the properties you may open, with property, room and licence counts', 'Live'],
    ['hotel_kpis', 'Hotel KPIs by Month', 'Occupancy, ADR, RevPAR, TRevPAR, GOPPAR and margins, month by month', 'Period'],
  ]],
  ['Compliance & HSE', 'shield', [
    ['foreign_guests', 'Form C (Foreign Guests)', 'Every foreign guest with the arrival report filed or outstanding', 'Period'],
    ['hse_incidents', 'Incidents & Accidents', 'What happened, where, who was involved and what was done', 'Period'],
    ['fire_permits', 'Permits & Certificates', 'Certificates held, expiring soon and expired', 'As on date'],
  ]],
];

// The report table the controller exposes, keyed by slug: [area, title, promise, mode, permission].
const REPORTS = {};
GROUPS.forEach(([group, , reports]) => reports.forEach(([slug, title, desc, mode]) => {
  REPORTS[slug] = [group, title, desc, mode === 'As on date' ? 'date' : (mode === 'Live' ? 'none' : 'range'), 'reports.view'];
}));

/** A Laravel-style collection that keeps its keys, as collect(REPORTS) does. */
class KeyedCollection {
  constructor(entries) { this.entries = entries; }
  static of(value) { return new KeyedCollection(Object.entries(value ?? {})); }
  filter(callback) { return new KeyedCollection(this.entries.filter(([key, item]) => callback(item, key))); }
  map(callback) { return new KeyedCollection(this.entries.map(([key, item]) => [key, callback(item, key)])); }
  count() { return this.entries.length; }
  isEmpty() { return this.entries.length === 0; }
  isNotEmpty() { return this.entries.length > 0; }
  take(limit) { return new KeyedCollection(this.entries.slice(0, limit)); }
  values() { return Coll.of(this.entries.map(([, item]) => item)); }
}

const SAVED_VIEWS = [
  { id: 12, name: 'Today at the desk', report_key: 'arrivals_today', range: 'today', user_id: ME.id, is_pinned: true, is_shared: false },
  { id: 18, name: 'Month-end accounts', report_key: 'trial_balance', range: 'last_month', user_id: ME.id, is_pinned: false, is_shared: true },
  { id: 21, name: 'Weekend F&B mix', report_key: 'pos_sales', range: 'last_7', user_id: 4, is_pinned: false, is_shared: true },
];

const RANGES = {
  today: 'Today', yesterday: 'Yesterday', last_7: 'Last 7 days', last_30: 'Last 30 days',
  mtd: 'Month to date', last_month: 'Last month', ytd: 'Year to date', custom: 'Custom',
};

const HELPERS = {
  can_do: () => true,
  auth: () => ({ id: () => ME.id, user: () => ({ name: ME.name }) }),
  hotel: () => HOTEL,
  bdate: () => new SampleDate(`${BUSINESS_DATE}T09:00:00Z`),
  now: () => new SampleDate(`${BUSINESS_DATE}T09:20:00Z`),
  route: (name, argument) => {
    // Laravel reads a model argument as its route key.
    const arg = argument && typeof argument === 'object' && 'id' in argument ? argument.id : argument;
    if (name === 'reports.show') return `/reports/${arg ?? 'arrivals_today'}`;
    if (name === 'reports.index') return '/reports';
    if (name === 'reports.library') return '/reports/library';
    if (name === 'reports.alerts') {
      const query = arg && typeof arg === 'object' ? `?${new URLSearchParams(arg).toString()}` : '';
      return `/reports/alerts${query}`;
    }
    if (name === 'reports.schedules.store') return '/reports/schedules';
    if (name === 'reports.schedules.run') return `/reports/schedules/${arg}/run`;
    if (name === 'reports.schedules.toggle') return `/reports/schedules/${arg}/toggle`;
    if (name === 'reports.schedules.destroy') return `/reports/schedules/${arg}`;
    if (name === 'reports.alerts.store') return '/reports/alerts';
    if (name === 'reports.alerts.toggle') return `/reports/alerts/${arg}/toggle`;
    if (name === 'reports.alerts.ack') return `/reports/alerts/${arg}/ack`;
    if (name === 'reports.alerts.evaluate') return '/reports/alerts/evaluate';
    if (name === 'reports.deliveries.csv') return `/reports/deliveries/${arg}/csv`;
    if (name === 'reports.schedules') {
      const query = arg && typeof arg === 'object' ? `?${new URLSearchParams(arg).toString()}` : '';
      return `/reports/schedules${query}`;
    }
    if (name === 'reports.views.pin' || name === 'reports.views.destroy' || name === 'reports.views.update' || name === 'reports.views.store') return '/reports/views';
    if (name === 'reports.alerts') return '/reports/alerts';
    return '#';
  },
  money: value => `GH₵ ${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
  implode: (glue, items) => [...(items?.items ?? items ?? [])].join(glue),
  array_merge: (...lists) => lists.flatMap(list => (Array.isArray(list) ? list : Object.values(list ?? {}))),
  array_flip: (value) => Object.fromEntries(Object.entries(value ?? {}).map(([key, item]) => [item, key])),
  array_intersect_key: (a, b) => Object.fromEntries(Object.entries(a ?? {}).filter(([key]) => key in (b ?? {}))),
  in_array_strict: (needle, haystack) => (haystack ?? []).includes(needle),
  strtolower: value => String(value ?? '').toLowerCase(),
  strtoupper: value => String(value ?? '').toUpperCase(),
  ucfirst: value => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
  trim: value => String(value ?? '').trim(),
  str_contains: (subject, needle) => String(subject ?? '').includes(String(needle ?? '')),
  number_format: value => Number(value ?? 0).toLocaleString('en-US'),
  collect: value => {
    if (value instanceof Coll || value instanceof KeyedCollection) return value;
    if (Array.isArray(value)) return Coll.of(value);
    return KeyedCollection.of(value ?? {});
  },
  count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
  empty: value => value === undefined || value === null || value === ''
    || (Array.isArray(value) && !value.length) || (value instanceof Coll && value.isEmpty()),
  __static: (cls, method, ...args) => (method === 'resolve' ? [new SampleDate(`${BUSINESS_DATE}T00:00:00Z`), new SampleDate(`${BUSINESS_DATE}T00:00:00Z`)] : args[0] ?? ''),
  __const: (cls, name) => (name === 'RANGES' ? RANGES : []),
  e: value => String(value ?? ''),
  in_array: (needle, haystack) => {
    const list = Array.isArray(haystack) ? haystack : Object.values(haystack ?? {});
    return list.some(value => value === needle || String(value) === String(needle));
  },
  isset: value => value !== null && value !== undefined,
  // request() is read both ways in the views: request('new') and request()->fullUrlWithQuery(…)
  request: Object.assign(
    (key) => (key === undefined
      ? { fullUrlWithQuery: params => `?${new URLSearchParams(params).toString()}`, query: () => null, input: () => null, integer: () => 0 }
      : null),
    {
      fullUrlWithQuery: params => `?${new URLSearchParams(params).toString()}`,
      query: () => null,
      input: () => null,
      integer: () => 0,
    },
  ),
  old: (key, fallback = null) => fallback,
  session: () => null,
  errors: { any: () => false, has: () => false, first: () => '' },
  ordinal_day: (day) => {
    const n = Number(day);
    const suffix = n % 100 >= 11 && n % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] ?? 'th');
    return `${n}${suffix}`;
  },
  is_string: value => typeof value === 'string',
  is_int: value => Number.isInteger(value),
  is_float: value => typeof value === 'number' && !Number.isInteger(value),
  is_numeric: value => value !== null && value !== '' && Number.isFinite(Number(value)),
  is_array: value => Array.isArray(value),
  is_null: value => value === null || value === undefined,
  max: (...values) => Math.max(...values),
  min: (...values) => Math.min(...values),
  round: (value, precision = 0) => {
    const factor = 10 ** precision;
    return Math.round(Number(value ?? 0) * factor) / factor;
  },
  floor: value => Math.floor(Number(value ?? 0)),
  ceil: value => Math.ceil(Number(value ?? 0)),
  abs: value => Math.abs(Number(value ?? 0)),
  Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
  parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
};

export function reportsLibraryContext() {
  const groups = {};
  let total = 0;
  GROUPS.forEach(([name, icon, reports]) => {
    groups[name] = {
      name,
      icon,
      reports: reports.map(([slug, title, desc, mode]) => ({ slug, title, desc, mode })),
    };
    total += reports.length;
  });

  const views = Coll.of(SAVED_VIEWS.map(view => ({
    ...view,
    user: view.user_id === ME.id ? { name: ME.name } : { name: 'Kojo Owusu' },
    title: () => (GROUPS.flatMap(g => g[2]).find(r => r[0] === view.report_key)?.[1] ?? view.report_key),
    url: () => `/reports/${view.report_key}?view=${view.id}`,
  })));

  const scope = {
    me: ME,
    groups,
    total,
    views,
    pinned: views.filter(view => view.is_pinned),
    mine: SAVED_VIEWS.filter(view => view.user_id === ME.id).length,
    shared: SAVED_VIEWS.filter(view => view.user_id !== ME.id).length,
    schedules: 2,
  };

  return { helpers: HELPERS, scope, fallbacks: {} };
}

/**
 * The report screen: "POS Sales by Outlet" over month-to-date.
 *
 * Mirrors what ReportController::show() hands the view — the resolved period, the
 * columns, the rows, which of them are money, which are totalled, the bar chart, the
 * summary strip — so the viewer composes as it does in the application.
 */
export function reportsDetailContext() {
  const from = new SampleDate('2026-11-01T00:00:00Z');
  const to = new SampleDate('2026-11-06T00:00:00Z');

  const cols = {
    date: 'Date',
    outlet: 'Outlet',
    covers: 'Covers',
    net: 'Net sales',
    tax: 'Tax',
    total: 'Total',
  };

  const rows = Coll.of([
    { date: '01 Nov', outlet: 'Nana Fripongmaa 1', covers: 92, net: 7820.5, tax: 938.46, total: 8758.96 },
    { date: '01 Nov', outlet: 'Beach Bar', covers: 64, net: 4115.0, tax: 493.8, total: 4608.8 },
    { date: '02 Nov', outlet: 'Nana Fripongmaa 1', covers: 88, net: 7042.25, tax: 845.07, total: 7887.32 },
    { date: '02 Nov', outlet: 'Poolside Grill', covers: 41, net: 3298.4, tax: 395.81, total: 3694.21 },
    { date: '03 Nov', outlet: 'Nana Fripongmaa 1', covers: 101, net: 8610.75, tax: 1033.29, total: 9644.04 },
    { date: '03 Nov', outlet: 'Beach Bar', covers: 70, net: 4680.0, tax: 561.6, total: 5241.6 },
    { date: '04 Nov', outlet: 'Beach Bar', covers: 58, net: 3724.5, tax: 446.94, total: 4171.44 },
    { date: '05 Nov', outlet: 'Nana Fripongmaa 1', covers: 97, net: 8215.0, tax: 985.8, total: 9200.8 },
    { date: '05 Nov', outlet: 'Poolside Grill', covers: 46, net: 3540.0, tax: 424.8, total: 3964.8 },
    { date: '06 Nov', outlet: 'Nana Fripongmaa 1', covers: 86, net: 6904.35, tax: 828.52, total: 7732.87 },
  ]);

  const views = Coll.of([
    {
      id: 21, name: 'Weekend F&B mix', report_key: 'pos_sales', range: 'last_7', user_id: 4, is_shared: true,
      user: { name: 'Kojo Owusu' }, columns: null, filter: null,
      title: () => 'POS Sales by Outlet',
      url: () => '/reports/pos_sales?view=21',
    },
    {
      id: 24, name: 'Month-to-date takings', report_key: 'pos_sales', range: 'mtd', user_id: ME.id, is_shared: false,
      user: { name: ME.name }, columns: null, filter: null,
      title: () => 'POS Sales by Outlet',
      url: () => '/reports/pos_sales?view=24',
    },
  ]);

  const scope = {
    report: 'pos_sales',
    title: 'POS Sales by Outlet',
    mode: 'range',
    range: 'mtd',
    from,
    to,
    views,
    activeView: null,
    allCols: cols,
    data: {
      cols,
      rows,
      money: ['net', 'tax', 'total'],
      sum: ['covers', 'net', 'tax', 'total'],
      chart: { key: 'total', label: 'Net sales by day', x: 'date' },
      summary: { Covers: 486, 'Net sales': 41250.75, 'Average check': 84.88 },
      note: 'Gross of service charge, net of voids and discounts.',
    },
  };

  const helpers = {
    ...HELPERS,
    __static: (cls, method, ...args) => {
      if (method === 'limit') return String(args[0] ?? '').length > args[1] ? `${String(args[0]).slice(0, args[1])}…` : String(args[0] ?? '');
      if (method === 'resolve') return [new SampleDate('2026-11-01T00:00:00Z'), new SampleDate('2026-11-06T00:00:00Z')];
      return args[0] ?? '';
    },
    __const: (cls, name) => {
      if (name === 'RANGES') return RANGES;
      if (name === 'DATE_RANGES') return ['today', 'yesterday', 'last_month'];
      if (name === 'REPORTS') return REPORTS;
      return [];
    },
  };

  return { helpers, scope, fallbacks: {} };
}

/**
 * The consolidated report: v10.9's cross-property screen, read by a group administrator.
 *
 * Mirrors what ReportController::r_group_operating_summary() hands reports/show.blade.php: one row per
 * property the reader may open, each on its own business date, the ratios left out of the totals
 * because a total of four occupancies means nothing, and the property licensed for nothing named in
 * the note under the table instead of being added to it.
 */
export function reportsConsolidatedContext() {
  const from = new SampleDate('2026-11-01T00:00:00Z');
  const to = new SampleDate('2026-11-07T00:00:00Z');

  const cols = {
    property: 'Property', code: 'Code', status: 'Status', date: 'Business date',
    rooms: 'Sellable', occupied: 'Occupied', occ: 'Occupancy',
    in_house: 'In-house', arrivals: 'Arr', departures: 'Dep',
    rev_today: 'Revenue today', adr: 'ADR', rev_mtd: 'Revenue MTD',
    collections: 'Collections', open_pos: 'Open POS', licence: 'Licence',
  };

  const rows = Coll.of([
    {
      property: 'The Grove Essipong Beach Resort', code: 'GROVE', status: 'Live', date: '07-11-2026',
      rooms: 96, occupied: 78, occ: '81.3%', in_house: 61, arrivals: 14, departures: 9,
      rev_today: 38450, adr: 493, rev_mtd: 812400, collections: 36700, open_pos: 3, licence: '6 of 7 modules',
    },
    {
      property: 'Nowills Airport City Hotel', code: 'ACC', status: 'Live', date: '06-11-2026',
      rooms: 120, occupied: 103, occ: '85.8%', in_house: 88, arrivals: 21, departures: 17,
      rev_today: 41250, adr: 401, rev_mtd: 946800, collections: 45100, open_pos: 5, licence: 'Group licence',
    },
    {
      property: 'The Grove Cape Coast', code: 'CCP', status: 'Live', date: '07-11-2026',
      rooms: 24, occupied: 14, occ: '58.3%', in_house: 11, arrivals: 3, departures: 2,
      rev_today: 6180, adr: 441, rev_mtd: 96400, collections: 5900, open_pos: 1, licence: 'Group licence',
    },
    {
      property: 'Nowills Tamale Lodge', code: 'TML', status: 'Live', date: '30-09-2026',
      rooms: 40, occupied: 0, occ: '0%', in_house: 0, arrivals: 0, departures: 0,
      rev_today: 0, adr: 0, rev_mtd: 0, collections: 0, open_pos: 0, licence: 'Group licence',
    },
  ]);

  // The show view names the area from ReportController::REPORTS, so the same static lookup the
  // detail screen supplies has to be supplied here too.
  const helpers = {
    ...HELPERS,
    // PHP hands the strip floats for money and strings for counts (occupancy, properties, shut). A JS
    // number cannot tell an int from a float, and every non-string value in THIS report's summary is
    // money, so the question "is it an int?" is answered the way PHP would answer it here.
    is_int: () => false,
    __static: (cls, method, ...args) => args[0] ?? '',
    __const: (cls, name) => {
      if (name === 'REPORTS') return REPORTS;
      if (name === 'RANGES') return RANGES;
      return [];
    },
  };

  return {
    helpers,
    fallbacks: {},
    scope: {
      report: 'group_operating_summary',
      title: 'Consolidated Operating Summary',
      desc: 'Every property you may open, each on its own business date.',
      mode: 'none',
      range: 'custom',
      from,
      to,
      views: Coll.of([]),
      activeView: null,
      allCols: cols,
      data: {
        cols,
        rows,
        // v10.10 the report can be sliced by brand and by city; the slices are links, so each one is a
        // URL that can be bookmarked, printed and scheduled like any other state of the report.
        choices: [
          { label: 'All properties', url: '/reports/group_operating_summary', count: 5, active: true },
          { label: 'The Grove', url: '/reports/group_operating_summary?slice=brand&value=The%20Grove', count: 2, active: false },
          { label: 'Nowills', url: '/reports/group_operating_summary?slice=brand&value=Nowills', count: 2, active: false },
          { label: 'Accra', url: '/reports/group_operating_summary?slice=city&value=Accra', count: 1, active: false },
          { label: 'Takoradi', url: '/reports/group_operating_summary?slice=city&value=Takoradi', count: 1, active: false },
          { label: 'Cape Coast', url: '/reports/group_operating_summary?slice=city&value=Cape%20Coast', count: 1, active: false },
          { label: 'Tamale', url: '/reports/group_operating_summary?slice=city&value=Tamale', count: 1, active: false },
          { label: 'Kumasi', url: '/reports/group_operating_summary?slice=city&value=Kumasi', count: 1, active: false },
        ],
        money: ['rev_today', 'adr', 'rev_mtd', 'collections'],
        sum: ['rooms', 'occupied', 'in_house', 'arrivals', 'departures', 'rev_today', 'rev_mtd', 'collections', 'open_pos'],
        summary: {
          'Properties trading': '4',
          'Group occupancy': '64.6%',
          'Revenue today': 85880,
          'Revenue MTD': 1855600,
          'Properties shut': '1',
        },
        note: 'Each property is read on its own business date, in its own books. Not in these totals, because nobody can post there: '
          + 'Nowills Kumasi City (KSI). Its history is untouched — licensing a module again puts it straight back in this report.',
      },
    },
  };
}

/**
 * My reports: the saved views a reader keeps and the reports e-mailed on their behalf.
 *
 * Mirrors ReportAutomationController::library(), including the `$RS` service alias and
 * `$me` the view takes from @php — the renderer runs the content section only, so both
 * are supplied in the scope as well as by the block.
 */
export function reportsSavedViewsContext() {
  const opened = (iso, ago) => new SampleDate(iso, ago);

  const view = (data) => ({
    ...data,
    title: () => (GROUPS.flatMap(group => group[2]).find(entry => entry[0] === data.report_key)?.[1] ?? data.report_key),
    url: () => `/reports/${data.report_key}?view=${data.id}`,
  });

  const views = Coll.of([
    view({
      id: 12, name: 'Today at the desk', report_key: 'arrivals_today', range: 'today', user_id: ME.id,
      columns: null, filter: null, opens: 148, last_opened_at: opened('2026-11-06T07:40:00Z', '20 minutes ago'),
      is_pinned: true, is_shared: false, user: { name: ME.name },
    }),
    view({
      id: 24, name: 'Month-to-date takings', report_key: 'pos_sales', range: 'mtd', user_id: ME.id,
      columns: ['date', 'outlet', 'net', 'total'], filter: 'Nana', opens: 31, last_opened_at: opened('2026-11-04T18:05:00Z', '2 days ago'),
      is_pinned: false, is_shared: true, user: { name: ME.name },
    }),
    view({
      id: 21, name: 'Weekend F&B mix', report_key: 'pos_sales', range: 'last_7', user_id: 4,
      columns: null, filter: null, opens: 12, last_opened_at: opened('2026-11-02T09:15:00Z', '4 days ago'),
      is_pinned: false, is_shared: true, user: { name: 'Kojo Owusu' },
    }),
  ]);

  const schedule = (data) => ({
    ...data,
    title: () => (GROUPS.flatMap(group => group[2]).find(entry => entry[0] === data.report_key)?.[1] ?? data.report_key),
    when: () => data.whenText,
  });

  const mySchedules = Coll.of([
    schedule({
      id: 5, name: 'Morning flash for the GM', report_key: 'flash', frequency: 'daily', whenText: 'Daily at 07:00',
      is_active: true, last_run_at: opened('2026-11-06T07:00:00Z'), last_status: 'sent',
      next_run_at: opened('2026-11-07T07:00:00Z'),
    }),
    schedule({
      id: 9, name: 'Month-end accounts pack', report_key: 'trial_balance', frequency: 'monthly', whenText: 'Monthly on the 1st at 06:00',
      is_active: false, last_run_at: opened('2026-11-01T06:00:00Z'), last_status: 'failed',
      next_run_at: null,
    }),
  ]);

  const scope = {
    RS: 'App\\Services\\ReportScheduleService',
    me: ME,
    views,
    mySchedules,
    stats: { open_events: 2 },
  };

  const helpers = {
    ...HELPERS,
    __static: (cls, method, ...args) => {
      if (method === 'resolve') {
        const span = { today: [5, 5], yesterday: [4, 4], last_7: [0, 5], last_30: [0, 5], mtd: [0, 5], last_month: [0, 5], ytd: [0, 5], custom: [0, 5] };
        const [start, end] = span[args[0]] ?? [0, 5];
        const day = (offset) => new SampleDate(`2026-11-${String(offset + 1).padStart(2, '0')}T00:00:00Z`);
        return [day(start), day(end)];
      }
      if (method === 'rangeLabel') {
        const from = args[2]; const to = args[3];
        if (!from || !to) return '—';
        return from.toDateString() === to.toDateString() ? from.format('d M Y') : `${from.format('d M Y')} – ${to.format('d M Y')}`;
      }
      if (method === 'limit') return String(args[0] ?? '').slice(0, args[1] ?? 90);
      return args[0] ?? '';
    },
    __const: (cls, name) => {
      if (name === 'RANGES') return RANGES;
      if (name === 'DATE_RANGES') return ['today', 'yesterday', 'last_month'];
      if (name === 'REPORTS') return REPORTS;
      return [];
    },
  };

  return { helpers, scope, fallbacks: {} };
}

/** A paginator is a collection that also knows its total and draws no pager here. */
class SamplePaginator extends Coll {
  constructor(items) { super(items); }
  total() { return this.items.length; }
  fragment() { return this; }
  links() { return ''; }
}

/**
 * Scheduled reports: the schedules on the books, the delivery log and the form.
 *
 * Mirrors ReportAutomationController::schedules() and the @php block above the view's
 * content section, which the renderer does not run — so the aliases and closures that
 * block would define are supplied in the scope instead.
 */
export function reportsSchedulesContext() {
  const at = (iso) => new SampleDate(iso);
  const titleOf = (key) => REPORTS[key]?.[1] ?? key;

  const schedules = Coll.of([
    {
      id: 5, name: 'Morning flash for the GM', report_key: 'flash', range: 'yesterday', format: 'both', user_id: ME.id,
      frequency: 'daily', is_active: true, fail_count: 0, last_error: null,
      last_run_at: at('2026-11-06T07:00:00Z'), last_status: 'sent', next_run_at: at('2026-11-07T07:00:00Z'),
      user: { name: ME.name }, view: null, recipient_emails: null,
      title: () => titleOf('flash'), when: () => 'Daily at 07:00',
      emails: () => [`${ME.name.toLowerCase().replace(' ', '.')}@nowills.com.gh`, 'gm@nowills.com.gh'],
    },
    {
      id: 9, name: 'Month-end accounts pack', report_key: 'trial_balance', range: 'last_month', format: 'csv', user_id: 4,
      frequency: 'monthly', is_active: true, fail_count: 2, last_error: 'SMTP 550: mailbox unavailable for k.owusu@nowills.com.gh',
      last_run_at: at('2026-11-01T06:00:00Z'), last_status: 'failed', next_run_at: at('2026-12-01T06:00:00Z'),
      user: { name: 'Kojo Owusu' }, view: { name: 'Month-end accounts' }, recipient_emails: 'owner@nowills.com.gh',
      title: () => titleOf('trial_balance'), when: () => 'Monthly on the 1st at 06:00',
      emails: () => ['k.owusu@nowills.com.gh', 'owner@nowills.com.gh'],
    },
    {
      id: 11, name: 'Weekend F&B mix', report_key: 'pos_sales', range: 'last_7', format: 'html', user_id: ME.id,
      frequency: 'weekly', is_active: false, fail_count: 0, last_error: null,
      last_run_at: at('2026-10-31T08:00:00Z'), last_status: 'sent', next_run_at: null,
      user: { name: ME.name }, view: { name: 'Weekend F&B mix' }, recipient_emails: null,
      title: () => titleOf('pos_sales'), when: () => 'Weekly on Friday at 08:00',
      emails: () => [`${ME.name.toLowerCase().replace(' ', '.')}@nowills.com.gh`],
    },
  ]);

  const deliveries = new SamplePaginator([
    { id: 88, created_at: at('2026-11-06T07:00:00Z'), title: 'Morning flash for the GM', period_from: at('2026-11-05T00:00:00Z'), period_to: at('2026-11-05T00:00:00Z'),
      trigger: 'schedule', user: null, status: 'sent', rows: 12, delivered: 2, recipients: 2, sent_to: 'ama.mensah@nowills.com.gh, gm@nowills.com.gh', error: null },
    { id: 87, created_at: at('2026-11-05T07:00:00Z'), title: 'Morning flash for the GM', period_from: at('2026-11-04T00:00:00Z'), period_to: at('2026-11-04T00:00:00Z'),
      trigger: 'schedule', user: null, status: 'sent', rows: 9, delivered: 2, recipients: 2, sent_to: 'ama.mensah@nowills.com.gh, gm@nowills.com.gh', error: null },
    { id: 86, created_at: at('2026-11-04T09:20:00Z'), title: 'Month-end accounts pack', period_from: at('2026-10-01T00:00:00Z'), period_to: at('2026-10-31T00:00:00Z'),
      trigger: 'manual', user: { name: ME.name }, status: 'failed', rows: 148, delivered: 0, recipients: 2, sent_to: 'k.owusu@nowills.com.gh, owner@nowills.com.gh',
      error: 'SMTP 550: mailbox unavailable for k.owusu@nowills.com.gh' },
    { id: 85, created_at: at('2026-11-03T07:00:00Z'), title: 'Morning flash for the GM', period_from: at('2026-11-02T00:00:00Z'), period_to: at('2026-11-02T00:00:00Z'),
      trigger: 'after_audit', user: null, status: 'partial', rows: 11, delivered: 1, recipients: 2, sent_to: 'ama.mensah@nowills.com.gh, gm@nowills.com.gh',
      error: 'gm@nowills.com.gh: mailbox full' },
    { id: 84, created_at: at('2026-11-02T07:00:00Z'), title: 'Morning flash for the GM', period_from: at('2026-11-01T00:00:00Z'), period_to: at('2026-11-01T00:00:00Z'),
      trigger: 'schedule', user: null, status: 'skipped', rows: 0, delivered: 0, recipients: 2, sent_to: 'ama.mensah@nowills.com.gh, gm@nowills.com.gh',
      error: 'Skipped: the report was empty and "don\'t send when empty" is on.' },
  ]);

  const reports = Object.fromEntries(Object.entries(REPORTS).sort((a, b) => a[1][1].localeCompare(b[1][1])).map(([key, entry]) => [key, entry[1]]));

  const scope = {
    RS: 'App\\Services\\ReportScheduleService',
    S: 'App\\Models\\ReportSchedule',
    me: ME,
    stats: { active: 2, paused: 1, sent_7d: 11, failed_7d: 2, failing: 1, views: 3, alerts: 2, open_events: 2 },
    schedules,
    deliveries,
    reports,
    users: Coll.of([
      { id: ME.id, name: ME.name, email: 'ama.mensah@nowills.com.gh' },
      { id: 4, name: 'Kojo Owusu', email: 'k.owusu@nowills.com.gh' },
      { id: 9, name: 'Adjoa Boateng', email: 'a.boateng@nowills.com.gh' },
    ]),
    views: reportsSavedViewsContext().scope.views,
    editing: null,
    errors: { any: () => false, has: () => false, first: () => '' },
    // the closures the view's @php block defines
    e: null,
    newKey: null,
    newView: null,
    f: (key, fallback = null) => fallback,
    selKey: 'flash',
    selView: null,
    selUsers: [ME.id],
    tone: { sent: 'green', partial: 'amber', failed: 'red', skipped: 'slate' },
    allowed: null,
  };

  const helpers = {
    ...HELPERS,
    can_do: (perm) => perm !== 'settings.manage',
    __static: (cls, method, ...args) => {
      if (method === 'cfg') return args[0] === 'rpt_max_recipients' ? 8 : null;
      if (method === 'defaultRange') return 'yesterday';
      return args[0] ?? '';
    },
    __const: (cls, name) => {
      if (name === 'RANGES') return RANGES;
      if (name === 'DATE_RANGES') return ['today', 'yesterday', 'last_month'];
      if (name === 'FREQUENCIES') return { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', after_audit: 'After the night audit' };
      if (name === 'FORMATS') return { both: 'Table in the e-mail + CSV attachment', html: 'Table in the e-mail', csv: 'CSV attachment only' };
      if (name === 'WEEKDAYS') return { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };
      if (name === 'REPORTS') return REPORTS;
      return [];
    },
  };

  return { helpers, scope, fallbacks: {} };
}

/**
 * KPI alerts: the rules being watched and the events they raised.
 *
 * Mirrors ReportAutomationController::alerts(), with KpiService::METRICS and the
 * formatting the view leans on, so severities, thresholds and acknowledgements read
 * exactly as they do in the application.
 */
export function reportsAlertsContext() {
  const METRICS = {
    occ: ['Occupancy %', 'pct', 'flash', 'Room nights sold ÷ sellable room-nights'],
    adr: ['ADR', 'money', 'flash', 'Room revenue ÷ room nights sold'],
    revpar: ['RevPAR', 'money', 'flash', 'Room revenue ÷ sellable room-nights'],
    fnb: ['F&B revenue', 'money', 'flash', 'Restaurant / bar sales incl. room-charged'],
    collect: ['Collections', 'money', 'flash', 'Cash, card, MoMo & bank received'],
    otb_tomorrow: ['On-the-books occupancy tomorrow', 'pct', 'now', 'Rooms booked for the next night ÷ sellable rooms'],
    ar_over_60: ['City ledger over 60 days', 'money', 'now', 'Company bills unpaid for more than 60 days'],
    ooo_rooms: ['Out-of-order rooms', 'int', 'now', 'Rooms currently out of order'],
    inhouse_balance: ['In-house guest balances', 'money', 'now', 'Unsettled balance on open folios of in-house guests'],
  };

  const format = (value, fmt) => {
    const number = Number(value ?? 0);
    if (fmt === 'pct') return `${number.toFixed(1)} %`;
    if (fmt === 'money') return `GH₵ ${number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    return number.toFixed(0);
  };

  const alert = (data) => ({
    ...data,
    rule: () => {
      const metric = METRICS[data.metric] ?? [data.metric, 'int', 'now'];
      return `${metric[0]}${metric[2] === 'flash' ? ` (${data.period === 'mtd' ? 'month to date' : 'day'})` : ''} `
        + `${data.operator === 'below' ? '<' : '>'} ${format(data.threshold, metric[1])}`;
    },
  });

  const alerts = Coll.of([
    alert({
      id: 3, name: 'Low occupancy', metric: 'occ', period: 'day', operator: 'below', threshold: 55, severity: 'critical',
      is_active: true, last_value: 48.2, last_date: new SampleDate('2026-11-05T00:00:00Z'), last_triggered_at: new SampleDate('2026-11-06T06:10:00Z'),
      triggers: 4, channels: ['email'], recipient_users: [7, 4], recipient_emails: 'gm@nowills.com.gh', recipient_phones: null,
    }),
    alert({
      id: 5, name: 'City ledger ageing', metric: 'ar_over_60', period: 'day', operator: 'above', threshold: 25000, severity: 'warning',
      is_active: true, last_value: 31480.5, last_date: new SampleDate('2026-11-05T00:00:00Z'), last_triggered_at: new SampleDate('2026-11-05T09:00:00Z'),
      triggers: 9, channels: ['email', 'sms'], recipient_users: [4], recipient_emails: 'owner@nowills.com.gh', recipient_phones: '+233244000000',
    }),
    alert({
      id: 8, name: 'Tomorrow looks thin', metric: 'otb_tomorrow', period: 'day', operator: 'below', threshold: 40, severity: 'info',
      is_active: false, last_value: 62.5, last_date: new SampleDate('2026-11-04T00:00:00Z'), last_triggered_at: null,
      triggers: 0, channels: ['email'], recipient_users: [7], recipient_emails: null, recipient_phones: null,
    }),
  ]);

  const events = new SamplePaginator([
    {
      id: 41, business_date: new SampleDate('2026-11-05T00:00:00Z'), severity: 'critical',
      alert: { id: 3, name: 'Low occupancy', metric: 'occ', operator: 'below', threshold: 55 },
      message: 'Occupancy closed the day at 48.2 %, under the 55.0 % floor.',
      value: 48.2, threshold: 55, notified: 2, acknowledged_at: null, acknowledger: null, ack_note: null,
    },
    {
      id: 40, business_date: new SampleDate('2026-11-05T00:00:00Z'), severity: 'warning',
      alert: { id: 5, name: 'City ledger ageing', metric: 'ar_over_60', operator: 'above', threshold: 25000 },
      message: 'City ledger over 60 days stood at GH₵ 31,480.50, over the GH₵ 25,000.00 limit.',
      value: 31480.5, threshold: 25000, notified: 2, acknowledged_at: null, acknowledger: null, ack_note: null,
    },
    {
      id: 39, business_date: new SampleDate('2026-11-04T00:00:00Z'), severity: 'warning',
      alert: { id: 5, name: 'City ledger ageing', metric: 'ar_over_60', operator: 'above', threshold: 25000 },
      message: 'City ledger over 60 days stood at GH₵ 29,110.00, over the GH₵ 25,000.00 limit.',
      value: 29110, threshold: 25000, notified: 2,
      acknowledged_at: new SampleDate('2026-11-05T08:15:00Z'), acknowledger: { name: 'Kojo Owusu' }, ack_note: 'Owner remittance promised for Friday.',
    },
  ]);

  const scope = {
    K: 'App\\Services\\KpiService',
    A: 'App\\Models\\KpiAlert',
    me: ME,
    open: 2,
    alerts,
    events,
    users: Coll.of([
      { id: ME.id, name: ME.name },
      { id: 4, name: 'Kojo Owusu' },
      { id: 9, name: 'Adjoa Boateng' },
    ]),
    date: new SampleDate('2026-11-05T00:00:00Z'),
    editing: null,
    errors: { any: () => false, has: () => false, first: () => '' },
    e: null,
    f: (key, fallback = null) => fallback,
    sevTone: { critical: 'red', warning: 'amber', info: 'blue' },
    selUsers: [ME.id],
    selCh: ['email'],
  };

  const helpers = {
    ...HELPERS,
    __static: (cls, method, ...args) => {
      if (method === 'breached') return args[0]?.operator === 'below' ? Number(args[1]) < Number(args[0]?.threshold) : Number(args[1]) > Number(args[0]?.threshold);
      if (method === 'format') return format(args[0], args[1]);
      if (method === 'metricLabel') return METRICS[args[0]]?.[0] ?? args[0];
      if (method === 'resolve') return [new SampleDate('2026-11-05T00:00:00Z'), new SampleDate('2026-11-05T00:00:00Z')];
      return args[0] ?? '';
    },
    __const: (cls, name) => {
      if (name === 'METRICS') return METRICS;
      if (name === 'SEVERITIES') return { info: 'Info', warning: 'Warning', critical: 'Critical' };
      if (name === 'RANGES') return RANGES;
      return [];
    },
  };

  return { helpers, scope, fallbacks: {} };
}

/**
 * An "as on" report: the Trial Balance read at a business date.
 *
 * The viewer has a second shape for these — one date instead of a period, and its own
 * presets — so the preview shows that branch rather than only the range one.
 */
export function reportsAsOnContext() {
  const asOn = new SampleDate('2026-11-05T00:00:00Z');

  const cols = { code: 'Code', account: 'Account', type: 'Type', debit: 'Debit', credit: 'Credit' };

  // A trial balance has to balance: both sides total GH₵ 445,861.50.
  const rows = Coll.of([
    { code: '1010', account: 'Cash on hand', type: 'Asset', debit: 18420.5, credit: 0 },
    { code: '1020', account: 'Bank — GCB current', type: 'Asset', debit: 128640.75, credit: 0 },
    { code: '1100', account: 'Accounts receivable — city ledger', type: 'Asset', debit: 96480.0, credit: 0 },
    { code: '1200', account: 'Inventory — food & beverage', type: 'Asset', debit: 41320.25, credit: 0 },
    { code: '1300', account: 'Furniture & equipment, net', type: 'Asset', debit: 127790.0, credit: 0 },
    { code: '2010', account: 'Accounts payable — suppliers', type: 'Liability', debit: 0, credit: 73450.6 },
    { code: '2100', account: 'VAT / NHIL / GETFund payable', type: 'Liability', debit: 0, credit: 18962.4 },
    { code: '2200', account: 'SSNIT & Tier 2 payable', type: 'Liability', debit: 0, credit: 14208.0 },
    { code: '3010', account: "Owner's capital", type: 'Equity', debit: 0, credit: 150000.0 },
    { code: '4010', account: 'Room revenue', type: 'Revenue', debit: 0, credit: 100000.0 },
    { code: '4020', account: 'F&B revenue', type: 'Revenue', debit: 0, credit: 89240.5 },
    { code: '5010', account: 'Cost of food sales', type: 'Expense', debit: 33210.0, credit: 0 },
  ]);

  const views = Coll.of([{
    id: 18, name: 'Month-end accounts', report_key: 'trial_balance', range: 'last_month', user_id: ME.id, is_shared: true,
    user: { name: ME.name }, columns: null, filter: null,
    title: () => 'Trial Balance',
    url: () => '/reports/trial_balance?view=18',
  }]);

  const scope = {
    report: 'trial_balance',
    title: 'Trial Balance',
    mode: 'date',
    range: 'last_month',
    from: asOn,
    to: asOn,
    views,
    activeView: null,
    allCols: cols,
    data: {
      cols,
      rows,
      money: ['debit', 'credit'],
      sum: ['debit', 'credit'],
      chart: null,
      summary: { Accounts: 128, 'Debit total': 445861.5, 'Credit total': 445861.5 },
      note: 'Cents ignored. Accounts with no movement in the period are hidden.',
    },
  };

  const helpers = {
    ...HELPERS,
    __static: (cls, method, ...args) => {
      if (method === 'resolve') return [asOn, asOn];
      if (method === 'rangeLabel') return asOn.format('d M Y');
      if (method === 'limit') return String(args[0] ?? '').slice(0, args[1] ?? 90);
      return args[0] ?? '';
    },
    __const: (cls, name) => {
      if (name === 'RANGES') return RANGES;
      if (name === 'DATE_RANGES') return ['today', 'yesterday', 'last_month'];
      if (name === 'REPORTS') return REPORTS;
      return [];
    },
  };

  return { helpers, scope, fallbacks: {} };
}
