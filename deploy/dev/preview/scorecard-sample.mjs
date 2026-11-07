/**
 * Sample data for the supplier scorecard preview — resources/views/procurement/scorecard.blade.php rendered the way
 * ProcurementController::scorecard() hands it over: the ranked rows, and the three readings behind the score.
 *
 * A scorecard is the last screen the procurement chain can feed, because it needs all of it: a requisition became an
 * order, the order became a delivery, the delivery became a bill, and the bill is either paid or not. The period is the
 * six months to 7 November 2026.
 *
 *   • the delivery — 17 deliveries, 64,720.00 taken into the store, four of them late for 19,480.00. The worst is the
 *     Coastal beverages delivery: 6,320.00 that arrived on 4 November against a 20 October promise, fifteen days over.
 *   • the money — every supplier billed exactly what the shelf took in, 64,720.00 each way, and 36,500.00 is still owed
 *     across six open bills, the oldest Sahel at forty-one days past its due date.
 *   • the bid — sixteen invitations, eleven answered, one declined and four still silent: Kumasi was asked three times
 *     and answered once. Two order lines came in above the agreed rate.
 *
 * The drift build breaks the money and leaves the delivery and the bid alone: Coastal bills 2,650.00 more than it
 * delivered and Golden Gate 500.00 less, 2,150.00 apart in all — the same two bills round 106 found on the purchase
 * orders, now read from the supplier's side.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const FROM = '2026-05-01';
const TO = '2026-11-07';

/* Supplier · orders · value · deliveries · on time · fill · quality · price · priced lines · above · invited/quoted · lead · score */
const SUPPLIERS = [
  { id: 14, name: 'Volta Power Systems', orders: 4, value: 22800, deliveries: 3, on_time: 100.0, fill: 96.2, quality: 100.0,
    price: 100.0, priced: 4, above: 0, rejected: 0, invited: 3, quoted: 3, lead: 3.2, score: 99.1 },
  { id: 3, name: 'Accra Fresh Produce', orders: 3, value: 5120, deliveries: 2, on_time: 100.0, fill: 88.5, quality: 100.0,
    price: 100.0, priced: 2, above: 0, rejected: 0, invited: 1, quoted: 1, lead: 1.4, score: 97.1 },
  { id: 21, name: 'Sahel Textiles', orders: 3, value: 9640, deliveries: 3, on_time: 66.7, fill: 91.0, quality: 93.8,
    price: 100.0, priced: 3, above: 0, rejected: 12, invited: 2, quoted: 1, declined: 1, lead: 6.1, score: 84.5 },
  { id: 8, name: 'Coastal Beverages Ltd', orders: 5, value: 18420, deliveries: 4, on_time: 75.0, fill: 92.4, quality: 100.0,
    price: 66.7, priced: 3, above: 1, rejected: 0, invited: 3, quoted: 3, lead: 5.8, score: 84.4 },
  { id: 12, name: 'Tema Cleaning Supplies', orders: 2, value: 1600, deliveries: 1, on_time: 100.0, fill: 78.6, quality: 100.0,
    price: 0.0, priced: 1, above: 1, rejected: 0, invited: 2, quoted: 1, lead: 2.9, score: 79.7 },
  { id: 5, name: 'Golden Gate Hardware', orders: 2, value: 3900, deliveries: 2, on_time: 50.0, fill: 84.2, quality: 96.4,
    price: 100.0, priced: 1, above: 0, rejected: 8, invited: 2, quoted: 1, lead: 8.4, score: 77.7 },
  { id: 27, name: 'Kumasi Paper & Packaging', orders: 2, value: 3240, deliveries: 2, on_time: 50.0, fill: 72.0, quality: 88.0,
    price: 50.0, priced: 2, above: 0, rejected: 6, invited: 3, quoted: 1, lead: 4.2, score: 65.0 },
];

/* What each supplier was billed in the period, and what is still open against it. */
const MONEY = {
  14: { billed: 22800, open_bills: 1, unpaid: 12400, oldest_days: 12 },
  3: { billed: 5120, open_bills: 1, unpaid: 3560, oldest_days: 9 },
  21: { billed: 9640, open_bills: 2, unpaid: 11980, oldest_days: 41 },
  8: { billed: 18420, open_bills: 1, unpaid: 6320, oldest_days: 4 },
  12: { billed: 1600, open_bills: 0, unpaid: 0, oldest_days: 0 },
  5: { billed: 3900, open_bills: 0, unpaid: 0, oldest_days: 0 },
  27: { billed: 3240, open_bills: 1, unpaid: 2240, oldest_days: 22 },
};

const DRIFT_MONEY = { 8: { billed: 21070 }, 5: { billed: 3400 } };

/* The deliveries that arrived after the date the order gave them. */
const LATE = [
  { supplier: 'Coastal Beverages Ltd', grn: 'GRN-2026-0116', po: 'PO-2026-0296', expected: '2026-10-20', arrived: '2026-11-04', value: 6320 },
  { supplier: 'Golden Gate Hardware', grn: 'GRN-2026-0109', po: 'PO-2026-0288', expected: '2026-10-12', arrived: '2026-10-19', value: 8940 },
  { supplier: 'Kumasi Paper & Packaging', grn: 'GRN-2026-0106', po: 'PO-2026-0281', expected: '2026-10-03', arrived: '2026-10-07', value: 1240 },
  { supplier: 'Sahel Textiles', grn: 'GRN-2026-0102', po: 'PO-2026-0271', expected: '2026-09-28', arrived: '2026-09-30', value: 2980 },
];

const pc = (v) => (v === null || v === undefined ? '—' : `${Math.round(v * 10) / 10}`.replace(/\.0$/, '') + '%');
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;

export function scorecardContext({ drift = false, from = FROM, to = TO } = {}) {
  const QUERY = { from, to };
  const billedOf = (id) => round2((drift ? DRIFT_MONEY[id]?.billed : null) ?? MONEY[id].billed);

  const rows = SUPPLIERS.map(s => ({
    supplier_id: s.id, supplier: s.name, orders: s.orders, value: s.value, deliveries: s.deliveries,
    on_time: s.on_time, fill: s.fill, quality: s.quality, price: s.price, rejected_qty: s.rejected,
    priced_lines: s.priced, above_list: s.above, invited: s.invited, quoted: s.quoted, lead_days: s.lead,
    score: s.score, grade: s.score >= 90 ? 'A' : (s.score >= 75 ? 'B' : (s.score >= 60 ? 'C' : 'D')),
    on_time_label: pc(s.on_time), fill_label: pc(s.fill), quality_label: pc(s.quality), price_label: pc(s.price),
    response_label: pc(s.invited ? Math.round(s.quoted / s.invited * 1000) / 10 : null),
    tone: s.score >= 90 ? 'green' : (s.score >= 75 ? 'blue' : (s.score >= 60 ? 'amber' : 'red')),
  })).sort((a, b) => b.score - a.score);

  /* 1. the delivery record */
  const lateRows = LATE.map(l => ({ ...l, days: Math.round((Date.parse(`${l.arrived}T00:00:00Z`) - Date.parse(`${l.expected}T00:00:00Z`)) / 86400000) }))
    .sort((a, b) => b.days - a.days);
  const delivery = {
    count: 17, value: 64720, late: lateRows.length, late_value: round2(lateRows.reduce((t, l) => t + l.value, 0)),
    worst: lateRows[0], rows: lateRows, lead: 5.1, rejected: 1860,
  };

  /* 2. the money record */
  const moneyRows = SUPPLIERS.map(s => {
    const received = s.value;
    const billed = billedOf(s.id);

    return { supplier: s.name, received, billed, diff: round2(billed - received),
      open_bills: MONEY[s.id].open_bills, unpaid: MONEY[s.id].unpaid, oldest_days: MONEY[s.id].oldest_days };
  }).sort((a, b) => Math.abs(b.diff) - Math.abs(a.diff));
  const gaps = moneyRows.filter(r => Math.abs(r.diff) > 0.005);
  const money = {
    rows: moneyRows, count: moneyRows.length, gaps: gaps.length,
    received: round2(moneyRows.reduce((t, r) => t + r.received, 0)),
    billed: round2(moneyRows.reduce((t, r) => t + r.billed, 0)),
    diff: round2(moneyRows.reduce((t, r) => t + r.diff, 0)),
    worst: gaps[0] ?? null,
    unpaid: round2(moneyRows.reduce((t, r) => t + r.unpaid, 0)),
    open_bills: moneyRows.reduce((t, r) => t + r.open_bills, 0),
    oldest: moneyRows.slice().sort((a, b) => b.oldest_days - a.oldest_days)[0],
  };

  /* 3. the bid record */
  const QUOTED = { 14: 21400, 3: 5120, 21: 7400, 8: 19800, 12: 1450, 5: 3200, 27: 2900 };
  const bidRows = SUPPLIERS.map(s => {
    const declined = s.declined ?? 0;

    return { supplier: s.name, invited: s.invited, answered: s.quoted, declined,
      silent: s.invited - s.quoted - declined, quoted: round2(QUOTED[s.id]),
      rate: s.invited ? Math.round(s.quoted / s.invited * 1000) / 10 : null };
  }).sort((a, b) => b.invited - a.invited);
  const invited = bidRows.reduce((t, r) => t + r.invited, 0);
  const answered = bidRows.reduce((t, r) => t + r.answered, 0);
  const bid = {
    rows: bidRows, count: bidRows.length, invited, answered,
    silent: bidRows.reduce((t, r) => t + r.silent, 0),
    quoted: round2(bidRows.reduce((t, r) => t + r.quoted, 0)),
    rate: invited ? Math.round(answered / invited * 1000) / 10 : null,
    worst: bidRows.slice().sort((a, b) => b.silent - a.silent)[0],
    above: rows.reduce((t, r) => t + r.above_list, 0), priced: rows.reduce((t, r) => t + r.priced_lines, 0),
  };
  bid.answered_sub = `${bid.rate} % of the invitations, and GH₵ ${bid.quoted.toLocaleString('en-US', { minimumFractionDigits: 2 })} quoted`;
  delivery.late_sub = `${delivery.count === 1 ? '1 delivery' : `${delivery.count} deliveries`} · GH₵ ${delivery.late_value.toLocaleString('en-US', { minimumFractionDigits: 2 })} arrived after the date`;
  money.owed_sub = `${money.open_bills === 1 ? '1 open bill' : `${money.open_bills} open bills`} · oldest ${money.oldest.oldest_days} day${money.oldest.oldest_days === 1 ? '' : 's'} past its date`;

  const header = {
    suppliers: rows.length,
    average: Math.round(rows.reduce((t, r) => t + r.score, 0) / rows.length * 10) / 10,
    grades: ['A', 'B', 'C', 'D'].reduce((acc, g) => ({ ...acc, [g]: rows.filter(r => r.grade === g).length }), {}),
    value: round2(rows.reduce((t, r) => t + r.value, 0)),
  };

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        const d = new Date(`${String(value).slice(0, 10)}T00:00:00Z`);
        if (Number.isNaN(d.getTime())) return String(value);
        const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()];
        return `${String(d.getUTCDate()).padStart(2, '0')} ${m} ${d.getUTCFullYear()}`;
      },
      bdate: () => TODAY,
      status_badge: value => `<span class="badge badge-slate">${String(value ?? '')}</span>`,
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) tail = Array.isArray(params) ? params.map(part).join('-') : part(params);
        return `#${String(name).replace(/\./g, '-')}${tail ? '-' + tail : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      setting: (key, fallback = null) => fallback,
      request: (key) => (key === undefined
        ? { boolean: k => Boolean(QUERY[k]), except: (...skip) => Object.fromEntries(Object.entries(QUERY).filter(([k, v]) => !skip.includes(k))) }
        : (QUERY[key] ?? null)),
      http_build_query: (obj) => Object.entries(obj ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&'),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Str' && method === 'limit') return String(args[0] ?? '').slice(0, Number(args[1] ?? 30));
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);

        return args[0] ?? '';
      },
      Str: { limit: (value, length = 30) => String(value ?? '').slice(0, length) },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      rows: new Coll(rows), from, to, delivery, money, bid, header,
      currentHotel: HOTEL,
    },
  };
}
