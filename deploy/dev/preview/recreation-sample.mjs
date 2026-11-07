/**
 * Sample data for the Recreation outlet previews.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/recreation/{outlet,shift,day}.blade.php against this fixed sample.
 * The shapes mirror RecreationController::outlet()/shift()/day() and
 * RecreationShiftService::summary()/dayReport(), so the screens compose exactly as they
 * do in the application: a mid-morning outlet counter with the shift open, a drawer that
 * has taken cash and mobile money, and a day report that closes the outlet.
 *
 * Tax is the statutory Ghana build-up on the club/service group — VAT 15 + NHIL 2.5 +
 * GETFund 2.5 + Tourism levy 1 = 21% — added on top of the entered price (exclusive mode),
 * exactly as ClubBillingService::tax() does.
 */
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-05';
const at = (iso) => new SampleDate(iso.includes('T') ? iso : `${iso}T09:00:00Z`);

const CATEGORIES = { access: 'Access ticket', rental: 'Rental / hire', service: 'Service' };

// ── the outlet catalogue (ClubTicketType rows) ──────────────────────────────────────────
const types = [
  { id: 1, code: 'DAYAD', name: 'Day pass — adult', category: 'access', price: 120, pax_type: 'adult', tax_rate: null, issues_pass: true, is_active: true, sort: 1 },
  { id: 2, code: 'DAYCH', name: 'Day pass — child', category: 'access', price: 60, pax_type: 'child', tax_rate: null, issues_pass: true, is_active: true, sort: 2 },
  { id: 3, code: 'POOL', name: 'Pool only — adult', category: 'access', price: 80, pax_type: 'adult', tax_rate: null, issues_pass: true, is_active: true, sort: 3 },
  { id: 4, code: 'SUNB', name: 'Sunbed & parasol', category: 'rental', price: 40, pax_type: 'adult', tax_rate: null, issues_pass: false, is_active: true, sort: 4 },
  { id: 5, code: 'TOWEL', name: 'Beach towel', category: 'rental', price: 15, pax_type: 'adult', tax_rate: null, issues_pass: false, is_active: true, sort: 5 },
  { id: 6, code: 'TENNIS', name: 'Tennis court — hour', category: 'rental', price: 90, pax_type: 'adult', tax_rate: null, issues_pass: false, is_active: true, sort: 6 },
  { id: 7, code: 'COACH', name: 'Coaching session', category: 'service', price: 150, pax_type: 'adult', tax_rate: null, issues_pass: false, is_active: true, sort: 7 },
  { id: 8, code: 'SAUNA', name: 'Sauna — 30 min', category: 'service', price: 70, pax_type: 'adult', tax_rate: null, issues_pass: false, is_active: true, sort: 8 },
];

const groupedTypes = {};
for (const type of types) (groupedTypes[type.category] ??= []).push(type);
Object.keys(groupedTypes).forEach((key) => { groupedTypes[key] = Coll.of(groupedTypes[key]); });

const MODES = [
  { id: 1, name: 'Cash', type: 'cash', is_active: true },
  { id: 2, name: 'Mobile Money — MTN', type: 'wallet', is_active: true },
  { id: 3, name: 'Mobile Money — Telecel', type: 'wallet', is_active: true },
  { id: 4, name: 'Visa / Mastercard', type: 'card', is_active: true },
  { id: 5, name: 'Bank transfer', type: 'bank', is_active: true },
];

// ── the open shift (DepartmentShiftSession, department = recreation) ───────────────────
const cashier = { name: 'Akosua Mensah' };

const openShift = {
  id: 91,
  user_id: 7,
  department: 'recreation',
  shift: 'Morning',
  business_date: at(BUSINESS_DATE),
  opened_at: at('2026-11-05T07:05:00Z'),
  closed_at: null,
  status: 'open',
  opening_float: '300.00',
  opening_note: 'float from the front office safe',
  closing_note: null,
  expected_cash: null,
  counted_cash: null,
  variance: null,
  close_snapshot: null,
  user: cashier,
};

// RecreationShiftService::summary() for the open shift above.
// 9 paid sales, GH₵ 1,090.00 cash taken, GH₵ 20.00 change given.
const openSummary = {
  sales: 9,
  tickets: 14,
  items: 22,
  void_sales: 0,
  void_total: 0,
  net: 1860.00,
  tax: 390.60,
  total: 2250.60,
  room_total: 145.20,
  change: 20.00,
  opening_float: 300.00,
  cash_in: 1090.00,
  expected_cash: 1370.00,
  tenders: {
    Cash: { amount: 1090, count: 6, is_cash: true },
    'Mobile Money — MTN': { amount: 780.60, count: 2, is_cash: false },
    'Visa / Mastercard': { amount: 380, count: 1, is_cash: false },
  },
  by_category: {
    access: { qty: 12, net: 1140, tax: 239.40 },
    rental: { qty: 6, net: 420, tax: 88.20 },
    service: { qty: 4, net: 300, tax: 63.00 },
  },
};

const dayStats = { sales: 9, total: 2250.60, tickets: 14, items: 22 };

// Earlier shifts on the same business day, closed and reconciled.
const recentShifts = Coll.of([
  openShift,
  {
    id: 90, user_id: 8, department: 'recreation', shift: 'Night', business_date: at(BUSINESS_DATE),
    opened_at: at('2026-11-04T23:00:00Z'), closed_at: at('2026-11-05T06:40:00Z'), status: 'closed',
    opening_float: '200.00', expected_cash: '640.00', counted_cash: '640.00', variance: '0.00',
    close_snapshot: { sales: 4, total: 712.80 },
    user: { name: 'Yaw Boateng' },
  },
  {
    id: 89, user_id: 9, department: 'recreation', shift: 'Evening', business_date: at(BUSINESS_DATE),
    opened_at: at('2026-11-04T15:10:00Z'), closed_at: at('2026-11-04T22:45:00Z'), status: 'closed',
    opening_float: '250.00', expected_cash: '1180.00', counted_cash: '1172.50', variance: '-7.50',
    close_snapshot: { sales: 11, total: 1986.40 },
    user: { name: 'Efua Danso' },
  },
]);

// ── the day report (RecreationShiftService::dayReport) ─────────────────────────────────
const saleMethods = (items, payments) => ({
  sale_no: items.sale_no,
  sold_at: items.sold_at,
  guest_name: items.guest_name,
  user: items.user,
  stay: items.stay ?? null,
  reservation_room_id: items.stay ? 441 : null,
  total: items.total,
  status: items.status ?? 'paid',
  payments: Coll.of(payments.map(([id, amount]) => ({ payment_mode_id: id, amount, paymentMode: MODES.find((m) => m.id === id) }))),
  isVoid() { return this.status === 'void'; },
});

const salesList = Coll.of([
  saleMethods({ sale_no: 'TKT-004512', sold_at: at('2026-11-05T08:12:00Z'), guest_name: 'Kojo Antwi', user: cashier, total: 145.20, stay: { id: 441, room: { room_no: '204' } } }, [[1, 145.20]]),
  saleMethods({ sale_no: 'TKT-004513', sold_at: at('2026-11-05T08:40:00Z'), guest_name: null, user: cashier, total: 435.60 }, [[1, 435.60]]),
  saleMethods({ sale_no: 'TKT-004514', sold_at: at('2026-11-05T09:05:00Z'), guest_name: 'Ama Serwaa', user: cashier, total: 254.10 }, [[2, 254.10]]),
  saleMethods({ sale_no: 'TKT-004515', sold_at: at('2026-11-05T09:30:00Z'), guest_name: null, user: cashier, total: 508.20 }, [[1, 300], [4, 208.20]]),
  saleMethods({ sale_no: 'TKT-004516', sold_at: at('2026-11-05T09:52:00Z'), guest_name: 'Esi Mansa', user: { name: 'Yaw Boateng' }, total: 181.50 }, [[1, 181.50]]),
  saleMethods({ sale_no: 'TKT-004517', sold_at: at('2026-11-05T10:04:00Z'), guest_name: null, user: { name: 'Yaw Boateng' }, total: 726.00 }, [[2, 526.50], [1, 199.50]]),
]);

const dayReport = {
  date: BUSINESS_DATE,
  sales: 9,
  void_sales: 1,
  void_total: 145.20,
  tickets: 14,
  items: 22,
  net: 1860.00,
  tax: 390.60,
  total: 2250.60,
  room_total: 145.20,
  change: 20.00,
  cash_in: 1090.00,
  tenders: {
    Cash: { amount: 1090, count: 6, is_cash: true },
    'Mobile Money — MTN': { amount: 780.60, count: 2, is_cash: false },
    'Visa / Mastercard': { amount: 380, count: 1, is_cash: false },
  },
  by_type: {
    'Day pass — adult': { qty: 8, net: 960, tax: 201.60, total: 1161.60 },
    'Day pass — child': { qty: 4, net: 240, tax: 50.40, total: 290.40 },
    'Sunbed & parasol': { qty: 4, net: 160, tax: 33.60, total: 193.60 },
    'Beach towel': { qty: 2, net: 30, tax: 6.30, total: 36.30 },
    'Tennis court — hour': { qty: 2, net: 180, tax: 37.80, total: 217.80 },
    'Coaching session': { qty: 2, net: 300, tax: 63.00, total: 363.00 },
  },
  by_category: {
    access: { qty: 12, net: 1140, tax: 239.40, total: 1379.40 },
    rental: { qty: 6, net: 420, tax: 88.20, total: 508.20 },
    service: { qty: 4, net: 300, tax: 63.00, total: 363.00 },
  },
  cashiers: [
    { name: 'Akosua Mensah', sales: 7, total: 1524.60, change: 20.00 },
    { name: 'Yaw Boateng', sales: 2, total: 907.50, change: 0.00 },
  ],
  shifts: Coll.of([
    { session: recentShifts.items[2], summary: { sales: 11, total: 1986.40, expected_cash: 1180.00 } },
    { session: recentShifts.items[1], summary: { sales: 4, total: 712.80, expected_cash: 640.00 } },
    { session: openShift, summary: { sales: 9, total: 2250.60, expected_cash: 1370.00 } },
  ]),
  admissions: { issued: 14, admitted: 11, waiting: 3, cancelled: 1, pax_issued: 26, pax_admitted: 21 },
  components: { VAT: 15, NHIL: 2.5, GETFUND: 2.5, TL: 1 },
  rate: 21,
  sales_list: salesList,
};

// ── helpers the three views call ──────────────────────────────────────────────────────
const HELPERS = {
  can_do: () => true,
  old: (key, fallback = null) => fallback,
  route: () => '#',
  asset: (path) => `/${String(path ?? '').replace(/^\/+/, '')}`,
  money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
  // PHP's number_format($v, $places, $decimal, $thousands): the views pass '' as the
  // thousands separator for figures they hand to <input type="number">, which rejects a comma.
  number_format: (value, places = 0, decimal = '.', thousands = ',') => {
    const [whole, fraction] = Number(value ?? 0).toFixed(places).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
    return fraction === undefined ? grouped : `${grouped}${decimal}${fraction}`;
  },
  // dmy() accepts a Carbon instance, a "Y-m-d" string or null — the views pass all three.
  dmy: (value, pattern = 'd M Y') => {
    if (!value) return '—';
    if (typeof value.format === 'function') return value.format(pattern);
    return new SampleDate(`${String(value).slice(0, 10)}T00:00:00Z`).format(pattern);
  },
  bdate: () => at(BUSINESS_DATE),
  now: () => new SampleDate('2026-11-05T10:26:00Z'),
  auth: () => ({ id: () => 7, check: () => true, user: () => cashier }),
  hotel: () => HOTEL,
  collect: (value) => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
  count: (value) => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
  empty: phpEmpty,
  in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
  array_filter: (value) => Object.fromEntries(Object.entries(value ?? {}).filter(([, entry]) => entry !== null && entry !== undefined && entry !== '')),
  array_values: (value) => (Array.isArray(value) ? value.slice() : Object.values(value ?? {})),
  json: (value) => JSON.stringify(value ?? null),
  json_encode: (value) => JSON.stringify(value ?? null),
  str_replace: (search, replace, subject) => String(subject ?? '').split(search).join(replace),
  strtolower: (value) => String(value ?? '').toLowerCase(),
  strtoupper: (value) => String(value ?? '').toUpperCase(),
  ucfirst: (value) => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
  substr: (value, start, length) => String(value ?? '').substr(start, length),
  trim: (value) => String(value ?? '').trim(),
  rtrim: (value, chars = ' \t\n\r\0\x0B') => String(value ?? '').replace(new RegExp(`[${chars.replace(/[\\\]]/g, '\\$&')}]+$`), ''),
  number: (value) => Number(value ?? 0),
  intval: (value) => Number.parseInt(value, 10) || 0,
  abs: (value) => Math.abs(Number(value ?? 0)),
  round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
  max: (...values) => Math.max(...values.flat()),
  min: (...values) => Math.min(...values.flat()),
  implode: (glue, values) => (values instanceof Coll ? values.items : values ?? []).join(glue),
  isset: (...values) => values.every((value) => value !== undefined && value !== null),
  config: (key, fallback = null) => fallback,
  setting: (key, fallback = null) => (key === 'recreation_shift_required' ? '0' : fallback),
  csrf_field: () => '',
  method_field: () => '',
  __const: (cls, name) => {
    const key = `${String(cls).split('\\').pop()}:${name}`;
    return ({
      'ClubTicketType:CATEGORIES': CATEGORIES,
      'ClubTicketType:PAX_TYPES': { adult: 'Adults', child: 'Children' },
      'TaxService:COMPONENTS': { VAT: ['VAT', 'GRA', 15], NHIL: ['NHIL', 'GRA', 2.5], GETFUND: ['GETFund levy', 'GRA', 2.5], TL: ['Tourism levy', 'GTA', 1] },
    })[key] ?? [];
  },
  __static: (cls, method, ...args) => {
    const name = String(cls).split('\\').pop();
    // \Illuminate\Support\Carbon::parse('2026-11-05') — the Z header.
    if (/Carbon/.test(name) && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T00:00:00Z`);
    if (name === 'TaxService' && method === 'pricesInclusive') return false;
    if (name === 'TaxService' && method === 'componentRates') return dayReport.components;
    if (name === 'TaxService' && method === 'rateFor') return 21;
    return args[0] ?? '';
  },
  e: (value) => String(value ?? ''),
  Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
  parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
};

const errors = { any: () => false, has: () => false, all: () => [], first: () => '', get: () => [] };

/** The till: catalogue on the left, cart and tenders on the right, shift open. */
export function recreationOutletContext() {
  return {
    helpers: HELPERS,
    scope: {
      errors, types: groupedTypes, modes: Coll.of(MODES),
      modesJson: MODES.map(({ id, name, type }) => ({ id, name, type })),
      stats: dayStats, rate: 21,
      shift: openShift, shiftSummary: openSummary, shiftRequired: false,
      hotel: HOTEL, currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}

/** The till before anyone has opened a shift, at an outlet that requires one. */
export function recreationOutletNoShiftContext() {
  const base = recreationOutletContext();
  return {
    helpers: HELPERS,
    scope: { ...base.scope, shift: null, shiftSummary: null, shiftRequired: true, stats: { sales: 0, total: 0, tickets: 0, items: 0 } },
    fallbacks: {},
  };
}

/** The shift screen: the open drawer, the denomination grid, and the shift history. */
export function recreationShiftContext() {
  return {
    helpers: HELPERS,
    scope: {
      errors, shift: openShift, summary: openSummary, recent: recentShifts,
      live: { 91: openSummary },
      denominations: [200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1],
      dayTotal: dayReport,
      hotel: HOTEL, currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}

/** The day report (Z): what the outlet took, by tender, category, cashier and shift. */
export function recreationDayContext() {
  return {
    helpers: HELPERS,
    scope: { errors, report: dayReport, hotel: HOTEL, currentHotel: HOTEL },
    fallbacks: {},
  };
}

// ── one sale as the counter hands it over: day passes, a sunbed, cash + MoMo ───────────
const saleItems = Coll.of([
  { id: 501, description: 'Day pass — adult', qty: 2, unit_price: 120, line_total: 240, tax_rate: 21, tax: 50.40 },
  { id: 502, description: 'Day pass — child', qty: 1, unit_price: 60, line_total: 60, tax_rate: 21, tax: 12.60 },
  { id: 503, description: 'Sunbed & parasol', qty: 1, unit_price: 40, line_total: 40, tax_rate: 21, tax: 8.40 },
]);

const salePasses = Coll.of([
  { id: 801, pass_no: 'DP-0091', name: 'Day pass — adult', adults: 2, children: 0, date: at(BUSINESS_DATE) },
  { id: 802, pass_no: 'DP-0092', name: 'Day pass — child', adults: 0, children: 1, date: at(BUSINESS_DATE) },
]);

const receiptSale = {
  id: 512,
  sale_no: 'TKT-004512',
  sold_at: at('2026-11-05T08:12:00Z'),
  guest_name: 'Kojo Antwi',
  phone: '024 551 8890',
  host: null,
  user: cashier,
  stay: null,
  reference: null,
  status: 'paid',
  cancel_reason: null,
  subtotal: 340,
  tax: 71.40,
  total: 411.40,
  change_due: 38.60,
  items: saleItems,
  passes: salePasses,
  payments: Coll.of([
    { id: 900, amount: 300, reference: null, paymentMode: MODES[0] },
    { id: 901, amount: 150, reference: 'MP-7741902', paymentMode: MODES[1] },
  ]),
  isVoid() { return this.status === 'void'; },
};

/** The receipt and entry tickets the counter hands the visitor. */
export function recreationReceiptContext() {
  return {
    helpers: HELPERS,
    scope: { errors, sale: receiptSale, hotel: HOTEL, currentHotel: HOTEL },
    fallbacks: {},
  };
}

/** A paginator is a collection that also knows its total and draws no pager here. */
class SamplePaginator extends Coll {
  total() { return this.items.length; }
  fragment() { return this; }
  links() { return ''; }
}

const LEDGER_LINES = [
  ['Day pass — adult', 'Sunbed & parasol'],
  ['Day pass — child', 'Beach towel'],
  ['Tennis court — hour', 'Coaching session'],
];

/** The outlet ledger: every sale, which shift it belonged to and who took the money. */
export function recreationSalesContext() {
  const rows = salesList.items.map((sale, index) => ({
    ...sale,
    id: 512 + index,
    host: null,
    phone: index === 0 ? '024 551 8890' : null,
    shift: index < 4 ? openShift : null,
    reference: index === 2 ? 'MP-7741902' : null,
    payments: Coll.of(sale.payments.items.map((pay) => ({ ...pay, receiver: cashier }))),
    items: Coll.of([
      { description: LEDGER_LINES[index % LEDGER_LINES.length][0], qty: 2, line_total: 240 },
      { description: LEDGER_LINES[index % LEDGER_LINES.length][1], qty: 1, line_total: 60 },
    ]),
    passes: Coll.of(Array.from({ length: (index % 2) + 1 }, () => ({ adults: 2, children: 0 }))),
  }));

  return {
    helpers: HELPERS,
    scope: {
      errors,
      rows: new SamplePaginator(rows),
      date: BUSINESS_DATE,
      sumCount: 9,
      sumTotal: 2250.60,
      sumTax: 390.60,
      sumTickets: 14,
      hotel: HOTEL,
      currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}

// ── the gate: today's tickets, who has come through and who has not ────────────────────
const gatePasses = Coll.of([
  {
    id: 811, pass_no: 'DP-0091', date: at(BUSINESS_DATE), kind: 'day', name: 'Kojo Antwi', phone: '024 551 8890',
    adults: 2, children: 0, total: 290.40, host: null, ticketType: { name: 'Day pass — adult' },
    sale: { sale_no: 'TKT-004512', stay: null, payments: Coll.of([{ paymentMode: { name: 'Cash' } }]) },
    redeemed_at: at('2026-11-05T08:31:00Z'), redeemed_pax: 2, redeemer: { name: 'Kwame Asare' },
    cancelled_at: null, cancel_reason: null,
  },
  {
    id: 812, pass_no: 'DP-0092', date: at(BUSINESS_DATE), kind: 'day', name: 'Ama Serwaa', phone: null,
    adults: 1, children: 1, total: 217.80, host: null, ticketType: { name: 'Day pass — child' },
    sale: { sale_no: 'TKT-004514', stay: null, payments: Coll.of([{ paymentMode: { name: 'Mobile Money — MTN' } }]) },
    redeemed_at: at('2026-11-05T09:12:00Z'), redeemed_pax: 2, redeemer: { name: 'Kwame Asare' },
    cancelled_at: null, cancel_reason: null,
  },
  {
    id: 813, pass_no: 'DP-0093', date: at(BUSINESS_DATE), kind: 'day', name: 'Esi Mansa', phone: '020 774 1188',
    adults: 2, children: 2, total: 435.60, host: null, ticketType: { name: 'Day pass — adult' },
    sale: { sale_no: 'TKT-004515', stay: null, payments: Coll.of([{ paymentMode: { name: 'Visa / Mastercard' } }]) },
    redeemed_at: null, redeemed_pax: null, redeemer: null,
    cancelled_at: null, cancel_reason: null,
  },
  {
    id: 814, pass_no: 'DP-0094', date: at(BUSINESS_DATE), kind: 'day', name: 'Yaa Frimpong', phone: null,
    adults: 1, children: 0, total: 145.20, host: { name: 'Nana Adjei' }, ticketType: { name: 'Pool only — adult' },
    sale: { sale_no: 'TKT-004516', stay: { room: { room_no: '204' } }, payments: Coll.of([]) },
    redeemed_at: null, redeemed_pax: null, redeemer: null,
    cancelled_at: null, cancel_reason: null,
  },
  {
    id: 815, pass_no: 'DP-0095', date: at(BUSINESS_DATE), kind: 'day', name: 'Walk-out', phone: null,
    adults: 2, children: 0, total: 290.40, host: null, ticketType: { name: 'Day pass — adult' },
    sale: { sale_no: 'TKT-004509', stay: null, payments: Coll.of([{ paymentMode: { name: 'Cash' } }]) },
    redeemed_at: null, redeemed_pax: null, redeemer: null,
    cancelled_at: at('2026-11-05T07:55:00Z'), cancel_reason: 'Wrong price — refunded at the desk',
  },
]);

const gateBoard = {
  date: BUSINESS_DATE,
  passes: gatePasses,
  issued: 4,
  admitted: 2,
  waiting: 2,
  cancelled: 1,
  pax_issued: 8,
  pax_admitted: 4,
  revenue: 1089.00,
  turnstile: 50,
};

/** The gate: the counter's tickets, admitted one stub at a time. */
export function recreationGateContext() {
  return {
    helpers: HELPERS,
    scope: {
      errors,
      board: gateBoard,
      recent: gatePasses,
      stats: dayStats,
      hotel: HOTEL,
      currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}

/** The ticket types a manager maintains: prices, tax overrides and what the guest pays. */
export function recreationTypesContext() {
  const rows = types.map((type) => ({
    ...type,
    categoryLabel: () => CATEGORIES[type.category] ?? type.category,
  }));

  return {
    helpers: HELPERS,
    scope: { errors, rows: Coll.of(rows), rate: 21, hotel: HOTEL, currentHotel: HOTEL },
    fallbacks: {},
  };
}
