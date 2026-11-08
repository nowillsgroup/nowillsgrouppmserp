/**
 * Sample data for the static dashboard preview.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/dashboard*.blade.php source against this fixed sample. The
 * shapes here mirror app/Http/Controllers/DashboardController.php and the
 * services the view calls, so the screen composes exactly as it does in the
 * application — only the numbers are fixed.
 */
import { Coll, SampleDate, countsProxy } from './blade-render.mjs';

const DAY = 86400000;
const d = (iso, ago) => new SampleDate(`${iso}T09:00:00Z`, ago);
const BUSINESS_DATE = '2026-11-05';

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort',
  code: 'GROVE',
  currency: 'GHS',
  currency_symbol: 'GH₵',
};

const money = (v) => `${HOTEL.currency_symbol} ${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const numberFormat = (v, places = 0) => Number(v).toLocaleString('en-US', {
  minimumFractionDigits: places,
  maximumFractionDigits: places,
});

/* ── room status ──────────────────────────────────────────────────────────── */
const total = 42;
const sellable = 36;
const occupied = 29;
const vacant = 7;
const unavailable = 6;
const dirty = 5;
const occPct = Math.round(occupied * 100 / sellable * 10) / 10;

/* ── money for the business date, net of tax ──────────────────────────────── */
const roomRevenue = 31320;
const otherRevenue = 9845;
const roomRevenuePositive = 31320;
const otherRevenuePositive = 10120;
const collections = 28640;
const adr = roomRevenue / occupied;

/* ── 14 completed audit days ──────────────────────────────────────────────── */
const auditSeries = [
  [42310, 78.4], [39870, 74.1], [44120, 82.7], [45890, 85.3], [41260, 79.2],
  [38940, 72.8], [43110, 80.1], [46720, 88.6], [45210, 84.9], [42980, 81.4],
  [44650, 83.2], [47120, 89.7], [43980, 82.1], [45290, 84.4],
];
const audits = Coll.of(auditSeries.map(([revenue, pct], index) => {
  const date = new SampleDate(+d(BUSINESS_DATE) - DAY * (auditSeries.length - index));
  return {
    business_date: date,
    room_revenue: Math.round(revenue * 0.72),
    other_revenue: Math.round(revenue * 0.28),
    occupancy_pct: pct,
  };
}));

/* ── mix charts (the Blade @php blocks that build these are skipped) ──────── */
function stopsFor(rows, totalValue) {
  const stops = [];
  let angle = 0;
  for (const row of rows) {
    if (!row.value) continue;
    const next = Math.min(360, angle + (row.value / totalValue * 360));
    stops.push(`${row.color} ${angle.toFixed(2)}deg ${next.toFixed(2)}deg`);
    angle = next;
  }
  return stops.length ? `conic-gradient(${stops.join(', ')})` : '';
}

const revenueSlices = Coll.of([
  { label: 'Rooms & extra bed', value: roomRevenuePositive, amount: roomRevenue, color: '#16834a' },
  { label: 'Other & POS', value: otherRevenuePositive, amount: otherRevenue, color: '#3b82f6' },
]);
const revenueMixTotal = roomRevenuePositive + otherRevenuePositive;

const sourceRows = Coll.of([
  { label: 'Booking.com', value: 38, color: '#16834a' },
  { label: 'Direct', value: 24, color: '#3b82f6' },
  { label: 'Expedia', value: 13, color: '#f59e0b' },
  { label: 'Walk-in', value: 9, color: '#8b5cf6' },
  { label: 'No source', value: 6, color: '#e11d48' },
  { label: 'Other sources', value: 4, color: '#64748b' },
]);
const sourceMixTotal = 94;

const posRows = Coll.of([
  { label: 'Open', value: 4, color: '#f59e0b' },
  { label: 'Billed', value: 6, color: '#3b82f6' },
  { label: 'Settled', value: 16, color: '#16834a' },
]);
const posMixTotal = 26;

/* ── front desk work lists ────────────────────────────────────────────────── */
const arrivalRows = [
  ['Nana Adjei-Mensah', 'RSV-2026-0481', '104', 'Ready to check in'],
  ['Efua Boateng', 'RSV-2026-0482', '212', 'Ready to check in'],
  ['Kwame Osei', 'RSV-2026-0483', 'Unassigned', 'Room not assigned yet'],
  ['Ama Serwaa', 'RSV-2026-0484', '118', 'Awaiting guest ID'],
  ['Yaw Frimpong', 'RSV-2026-0485', '307', 'Ready to check in'],
  ['Adjoa Nyarko', 'RSV-2026-0488', '205', 'Deposit outstanding'],
];
const arrivals = Coll.of(arrivalRows.map(([guest, no, room, ready]) => ({
  reservation: { guest: { full_name: guest }, reservation_no: no },
  room: room === 'Unassigned' ? null : { room_no: room },
  _ready: ready,
})));

const departureRows = [
  ['Kofi Mensah', 'RSV-2026-0411', '108', 4120],
  ['Akosua Darko', 'RSV-2026-0414', '216', 2760],
  ['Selorm Agbeko', 'RSV-2026-0418', '303', 6340],
  ['Abena Owusu', 'RSV-2026-0421', '112', null],
  ['Ibrahim Sulemana', 'RSV-2026-0423', '208', 1980],
  ['Esi Quansah', 'RSV-2026-0426', '311', 8450],
].map(([guest, no, room, due]) => ({
  reservation: { guest: { full_name: guest }, reservation_no: no },
  room: { room_no: room },
  _due: due,
}));
const departures = Coll.of(departureRows);

const activity = Coll.of([
  ['Front desk', 'Checked in Nana Adjei-Mensah to room 104', 'Akua Mensimah', '6 minutes ago'],
  ['POS', 'Settled check #POS-1184 at Beach Bar · GH₵ 486.00', 'Kojo Amankwah', '12 minutes ago'],
  ['Reservations', 'New booking RSV-2026-0492 · 3 nights from 12 Nov', 'Ama Serwaa', '24 minutes ago'],
  ['Housekeeping', 'Room 214 marked clean after departure', 'Yaa Asantewaa', '38 minutes ago'],
  ['Night audit', 'Closed business date 04 Nov 2026 · 29 rooms sold', 'System', '1 hour ago'],
  ['Purchasing', 'Raised PO-2026-0217 for kitchen gas · GH₵ 4,980.00', 'Kofi Boateng', '2 hours ago'],
  ['Maintenance', 'Closed work order WO-0912 · leaking tap, room 118', 'Samuel Tetteh', '3 hours ago'],
  ['Finance', 'Posted bank transfer GH₵ 18,400.00 to Stanbic current', 'Grace Addo', '4 hours ago'],
].map(([module, description, user, ago]) => ({
  module,
  description,
  user: { name: user },
  created_at: d(BUSINESS_DATE, ago),
})));

const lowStock = Coll.of([
  ['Bath towel', 'piece', 8],
  ['Toilet roll', 'roll', 24],
  ['Cooking gas', 'kg', 12],
  ['Diesel', 'litre', 180],
  ['Bottled water 75cl', 'crate', 6],
].map(([name, unit, qty]) => ({ name, unit, stock_qty: qty })));

const events = Coll.of([
  ['Akyekyeku & Co. end-of-year dinner', '2026-11-07', 'Atlantic Hall', 180],
  ['Essipong Beach wedding reception', '2026-11-14', 'Garden Pavilion', 320],
  ['Ghana Hotels Association meeting', '2026-11-19', 'Lagoon Room', 60],
  ['Staff awards night', '2026-12-05', 'Atlantic Hall', 220],
].map(([title, date, hall, pax]) => ({
  title,
  event_date: d(date),
  functions: Coll.of([{ hall: { name: hall } }]),
  pax: () => pax,
  statusBadge: () => '<span class="badge badge-green">definite</span>',
})));

/* ── executive KPI strip (labels and wording from DashboardController) ───── */
const executiveKpis = [
  { label: 'Room occupancy', value: `${occPct.toFixed(1)}%`, detail: `${occupied} occupied of ${sellable} sellable rooms`, icon: 'bed', tone: 'blue' },
  { label: 'Arrivals due', value: '11', detail: '4 checked in · 7 remaining', icon: 'login', tone: 'green' },
  { label: 'In-house guests', value: '52', detail: '5 departures pending', icon: 'users', tone: 'purple' },
  { label: 'Revenue today', value: money(roomRevenue + otherRevenue), detail: 'Room + other revenue, net of tax', icon: 'trend', tone: 'green' },
  { label: 'Collections today', value: money(collections), detail: 'Payments received · excludes city ledger', icon: 'wallet', tone: 'teal' },
  { label: 'Room ADR', value: money(adr), detail: 'Posted room revenue per occupied room', icon: 'chart', tone: 'amber' },
];

const managementModules = [
  { title: 'Rooms & front office', value: `${occPct.toFixed(1)}%`, label: 'occupancy', detail: '7 arrivals · 5 departures', icon: 'bed', tone: 'blue', href: '#' },
  { title: 'Reservations & channels', value: '6', label: 'new bookings today', detail: '94 bookings in source mix · last 30 days · 5 channel alerts', icon: 'calendar', tone: 'green', href: '#' },
  { title: 'Finance & revenue', value: money(roomRevenue + otherRevenue), label: 'revenue today', detail: `${money(collections)} collected · ADR ${money(adr)}`, icon: 'wallet', tone: 'teal', href: '#' },
  { title: 'Food & beverage', value: '26', label: 'POS orders today', detail: `4 currently open · net sales ${money(8260)}`, icon: 'cup', tone: 'amber', href: '#' },
  { title: 'Housekeeping', value: String(dirty), label: 'rooms marked dirty', detail: '9 unfinished tasks due by today', icon: 'broom', tone: 'purple', href: '#' },
  { title: 'Events & banquets', value: '2', label: 'event bookings today', detail: '150 expected guests · 4 upcoming bookings shown below', icon: 'star', tone: 'pink', href: '#' },
  { title: 'Maintenance', value: '3', label: 'open work orders', detail: `${unavailable} rooms unavailable · includes outages / leases`, icon: 'tool', tone: 'red', href: '#' },
  { title: 'Inventory & procurement', value: '5', label: 'items at / below reorder level', detail: '7 open purchase orders', icon: 'box', tone: 'amber', href: '#' },
  { title: 'Guest experience', value: '62', label: 'NPS · last 30 days', detail: '18 feedback responses · 4.3/5 average rating', icon: 'message', tone: 'green', href: '#' },
  { title: 'People & payroll', value: '68', label: 'active employees', detail: '61 attendance records today', icon: 'users', tone: 'blue', href: '#' },
];

const kpiEv = Coll.of([
  { severity: 'critical', message: 'Occupancy in Beach Wing below 45% for 3 days', business_date: d('2026-11-05') },
  { severity: 'warning', message: 'ADR down 8.4% against the 7-day average', business_date: d('2026-11-04') },
  { severity: 'info', message: 'City ledger balance above 30-day threshold', business_date: d('2026-11-03') },
]);

const pinned = Coll.of([
  { name: 'Daily revenue', user_id: 1, is_pinned: true, url: () => '#' },
  { name: 'Occupancy & ADR', user_id: 1, is_pinned: true, url: () => '#' },
  { name: 'City ledger ageing', user_id: 1, is_pinned: true, url: () => '#' },
]);

/* ── department snapshots ────────────────────────────────────────────────── */
const fleetCost = {
  month: d('2026-11-01'),
  fuel: 18640,
  service: 7420,
  other: 1260,
  total: 27320,
  delta: 6.4,
  prev_total: 25678,
  top: { vehicle: { code: 'GT-4821-24' }, spend: 8240 },
  fills: 18,
  litres: 1240.5,
};

const budget = {
  budget: { fiscal_year: 2026 },
  month: 10,
  cutoff: d('2026-11-04'),
  revenue: { budget: 980000, actual: 742500, var: -237500, var_pct: -24.2, fav: false },
  gop: { budget: 392000, actual: 318400, var: -73600, var_pct: -18.8, fav: false },
  flagged: 3,
  pending: 1,
};

export function sampleContext({ version = '' } = {}) {
  const helpers = {};
  const services = {};
  const models = {
    Company: 2, Reservation: 3, ExpenseClaim: 4, Lease: 2, ClubMembership: 1,
    Vehicle: 7, ChannelConnection: 1, PosOrder: 4, Outlet: 1, TableReservation: 9,
    TransportTrip: 4, MaintenanceRequest: 3, PurchaseOrder: 7,
  };

  /* models: the query chains the view builds end in count()/exists() */
  class Query {
    constructor(cls) { this.cls = cls; }
    where(key, op) {
      if (this.cls === 'TableReservation' && key === 'start_at') this._n = op === '<' ? 2 : 6;
      return this;
    }
    whereIn(key, list) {
      const values = (list?.items ?? list ?? []).map(String);
      if (this.cls === 'TableReservation') this._n = values.includes('waiting') ? 3 : values.includes('booked') ? 6 : 2;
      if (this.cls === 'TransportTrip') this._n = 4;
      return this;
    }
    whereNotIn() { return this; }
    whereDate() { return this; } whereBetween() { return this; } whereNotNull() { return this; }
    whereHas() { return this; } orderBy() { return this; } orderByDesc() { return this; }
    take() { return this; } limit() { return this; } with() { return this; }
    count() { return this._n ?? 2; } exists() { return true; }
    sum() { return 0; } first() { return null; } get() { return new Coll([]); }
  }

  const serviceTable = {
    'KpiService.openEvents': () => kpiEv,
    'ReportScheduleService.viewsFor': () => pinned,
    'ContractService.alerts': () => ({ expiring: 3, drafts: 2 }),
    'ChannelService.attentionCount': () => 5,
    'PrivacyService.alerts': () => ({
      overdue: 1, due_soon: 2, breach: 1,
      breach_next: { ref: 'BR-0031', deadline: () => d('2026-11-06') },
    }),
    'GstService.alerts': () => ({
      failed: 2, pos_failed: 1, return_period: '2026-10', return_overdue: false,
      return_due: d('2026-11-21'),
    }),
    'BankRecService.overview': () => Coll.of([
      { state: 'overdue', review: true, unmatched: 4, account: { name: 'Stanbic current' } },
      { state: 'open', review: false, unmatched: 3, account: { name: 'GCB payroll' } },
    ]),
    'TimeClockService.today': () => ({ on_duty: 41, late: 2, no_show: 1 }),
    'RosterService.pendingSwaps': () => 2,
    'TimeClockService.attentionCount': () => 3,
    'ClubBillingService.arrears': () => Coll.of([{ amount: 1450 }, { amount: 890 }]),
    'LeaseBillingService.arrears': () => Coll.of([]),
    'IncomeAuditService.stats': () => ({ open_findings: 2, signed: 1, pending_auth: 1, count_issues: 1 }),
    'FoodSafetyService.attention': () => ({ total: 4, breaches: 1, cooling: 0, overdue: 2, failed: 1 }),
    'FoodSafetyService.certProblems': () => ({ expired: 1, missing: 2 }),
    'ProcurementService.awaitingMyApproval': () => Coll.of([{}, {}]),
    'ProcurementService.overdueDeliveries': () => Coll.of([{}]),
    'ProcurementService.readyToAward': () => Coll.of([{}, {}, {}]),
    'PettyCashService.attentionCount': () => 3,
    'StatutoryService.alerts': () => Coll.of([
      { period: '2026-10', kind: 'paye', total: 14680, overdue: false },
      { period: '2026-10', kind: 'ssnit', total: 21440, overdue: true },
    ]),
    'WhtService.alert': () => ({ period: '2026-09', overdue: true, due: d('2026-11-15') }),
    'FeedbackService.alerts': () => ({ overdue: 2, high: 3, reviews_waiting: 6, reviews_pending: 2 }),
    'TransportService.alerts': () => ({ requested: 3, unassigned: 2, papers_expired: 1, licences: 2 }),
    'FleetService.alerts': () => ({
      gps_offline: 1, no_signal: 1, off_trip_today: 1, speed_today: 2,
      restricted_today: 1, geofence_today: 4, fuel_checks: 3, inspection_due: 2,
      incidents_open: 1, maintenance_overdue: 2, open_repairs: 3,
    }),
    'FleetService.costSummary': () => fleetCost,
    'FormCService.enabled': () => true,
    'FormCService.alerts': () => ({ overdue: 1, due_soon: 2, departures: 3 }),
    'ForexService.enabled': () => true,
    'ForexService.alerts': () => ({ rates_missing: 2, surrender_overdue: 1 }),
    'FrontDeskOpsService.counts': () => ({
      wakeups_due: 3, wakeups_late: 1, messages: 4, parcels: 2, departed_parcels: 1,
      traces_today: 5, log_unread: 2, log_urgent: 1, lf_due: 2, lf_reports: 1,
    }),
    'InterfaceService.snapshot': () => ({
      calls_today: 34, charge_today: 486, calls_pending: 2, cards_active: 78,
      keys_out: 6, keys_overdue: 1, commands_failed: 0,
    }),
    'GiftVoucherService.snapshot': () => ({
      outstanding: 24680, live: 41, sold_today: 1200, redeemed_today: 850,
      expiring: 5, expiring_days: 30, expiring_value: 3200, suspended: 1,
    }),
    'SecurityService.snapshot': () => ({
      on_site: 14, visitors_today: 32, awaiting: 2, overstays: 1,
      gp_away: 5, gp_overdue: 1, gp_pending: 2, inc_open: 3, inc_serious: 1,
    }),
    'UtilityService.snapshot': () => ({
      read_today: 6, meters: 8, anomalies: 1,
      yesterday: { kwh: 3860, kwh_por: 13.4, cost: 5120 },
    }),
    'ComplianceService.snapshot': () => ({
      counts: { expired: 1, due: 4, renewing: 2 }, critical_expired: 1,
    }),
    'BudgetService.snapshot': () => budget,
    'BudgetService.monthLabels': () => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    'BudgetService.currentFy': () => 2026,
    'BudgetService.fyLabel': (fy) => `FY ${fy}`,
    'Carbon.parse': (str) => new SampleDate(`${str}T09:00:00Z`),
    'StayService.checkInReady': (stay) => stay?._ready ?? 'Ready to check in',
    'StayService.quoteCheckoutFigures': (stay) => ({ due: stay?._due ?? null }),
    'AvailabilityService.NON_SELLABLE_ROOM_STATUSES': ['out_of_order', 'out_of_service'],
    'LeaseService.blockedRoomIds': () => [],
  };

  const ctx = {
    hotel: HOTEL,
    version,
    helpers,
    fallbacks: {
      "['critical' => 'red', 'warning' => 'amber', 'info' => 'blue'][$ev->severity] ?? 'slate'": 'amber',
    },
  };

  function staticCall(cls, method, ...args) {
    const short = cls.split('\\').pop();
    if (models[short] !== undefined) {
      const query = new Query(short);
      query._n = models[short];
      return query;
    }
    const key = `${short}.${method}`;
    if (key in serviceTable) {
      const entry = serviceTable[key];
      return typeof entry === 'function' ? entry(...args) : entry;
    }
    if (services[key]) return services[key](...args);
    return countsProxy({});
  }

  Object.assign(helpers, {
    money: (v) => money(v ?? 0),
    money_short: (v) => money(v ?? 0),
    number_format: (v, places = 0) => numberFormat(v ?? 0, places),
    route: () => '#',
    asset: (p) => `/${String(p).replace(/^\/+/, '')}`,
    config: (key) => (key === 'pms.version' ? version : ''),
    can_do: () => true,
    bdate: () => d(BUSINESS_DATE),
    auth: () => ({ id: () => 1, user: () => ({ id: 1, name: 'Property Administrator' }) }),
    now: () => d(BUSINESS_DATE),
    today: () => d(BUSINESS_DATE),
    collect: (arr) => Coll.of(arr ?? []),
    count: (v) => (v instanceof Coll ? v.count() : Array.isArray(v) ? v.length : 0),
    in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
    rtrim: (s, chars = ' \t\n\r\0\x0B') => String(s ?? '').replace(new RegExp(`[${chars.replace(/[\\\]]/g, '\\$&')}]+$`), ''),
    ltrim: (s) => String(s ?? '').replace(/^\s+/, ''),
    trim: (s) => String(s ?? '').trim(),
    strtoupper: (s) => String(s ?? '').toUpperCase(),
    strtolower: (s) => String(s ?? '').toLowerCase(),
    ucfirst: (s) => { const t = String(s ?? ''); return t.charAt(0).toUpperCase() + t.slice(1); },
    e: (s) => String(s ?? ''),
    max: (...a) => Math.max(...a.flat()),
    min: (...a) => Math.min(...a.flat()),
    round: (v, p = 0) => { const f = 10 ** p; return Math.round(v * f) / f; },
    abs: (v) => Math.abs(v),
    __static: staticCall,
    __const: (cls, name) => (name === 'UPCOMING' ? ['booked', 'confirmed'] : name === 'WAITING' ? ['waiting'] : []),
    __coll: (arr) => Coll.of(arr),
    Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
    parseFloat, parseInt, isNaN, isFinite, typeof: undefined,
    undefined: undefined, NaN: NaN, Infinity: Infinity,
  });

  ctx.scope = {
    currentHotel: { ...HOTEL, currency: HOTEL.currency_symbol },
    total, sellable, occupied, vacant, unavailable, dirty, occPct,
    canSeeFinance: true,
    roomRevenue, otherRevenue, roomRevenuePositive, otherRevenuePositive,
    collections, adr,
    arrivalsCount: 7, departuresCount: 5, arrivalsCheckedIn: 4, arrivalsExpected: 11, inHouseGuests: 52,
    arrivals, departures, activity, audits,
    newBookings: 6, openTickets: 3, openPos: 4,
    posOrdersToday: posMixTotal,
    posOrderStatusToday: Object.assign({ open: 4, billed: 6, settled: 16 }, { sum: () => 26 }),
    housekeepingOpen: 9, eventsToday: 2, eventCoversToday: 150,
    lowStock, lowStockCount: 5, openPurchaseOrders: 7,
    activeEmployees: 68, attendanceLogged: 61,
    guestFeedback: { nps: { nps: 62 }, responses: 18, csat: 4.3 },
    executiveKpis, managementModules, events,
    fleetCost, kpiEv, pinned,
    sourceMix: Coll.of([
      { name: 'Booking.com', c: 38 }, { name: 'Direct', c: 24 }, { name: 'Expedia', c: 13 },
      { name: 'Walk-in', c: 9 }, { name: 'No source', c: 6 }, { name: 'Agents', c: 4 },
    ]),
    revenueSlices, revenueMixTotal, revenueMixHasAdjustments: true,
    revenueGradient: stopsFor(revenueSlices.items, revenueMixTotal),
    sourceRows, sourceMixTotal, sourceGradient: stopsFor(sourceRows.items, sourceMixTotal),
    posRows, posMixTotal, posStatusLabel: posRows.items.map(row => `${row.label} ${row.value}`).join('; '), posGradient: stopsFor(posRows.items, posMixTotal),
    bs: budget,
    tbToCome: 6, tbLate: 2, tbWait: 3, trToday: 4,
  };

  return ctx;
}

export { d as sampleDate, money as sampleMoney, BUSINESS_DATE };
