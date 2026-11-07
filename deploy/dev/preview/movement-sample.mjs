/**
 * Sample data for the stock-movement preview — resources/views/inventory/movement.blade.php rendered the way
 * InventoryController::movement() hands it over: the store's position, what has left it this month, and the movements
 * nobody can account for.
 *
 * Every figure is computed here from the two lists below — the store's holdings and the month's outward movements — the
 * way the controller computes them from the movement table. Nothing is written down twice.
 *
 * The Kitchen Store, 10 items worth 10,159.00, three of them at or below their level (rice 180 kg against a 300 kg
 * level is the tightest), and 8 movements out of it since 01 Nov worth 4,765.00:
 *
 *   • 3 issues for 3,686.00, all against requisition notes, the largest 180 kg of rice on 01 Nov at 1,710.00;
 *   • 1 transfer out, 20 kg of chicken at 760.00 on a transfer sheet;
 *   • 2 wastages (54.00 and 9.00) and 2 adjustments (132.00 and 124.00) with no reference and no department — 319.00
 *     that the store cannot explain, because until v9.25 the form had no field to record a document in.
 *
 * The drift build is the same store in the next month with the reference field in use: the two wastages and the two
 * adjustments are re-written against breakage reports and adjustment notes, so the gap card empties and the ledger can
 * answer for every movement out.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const STORES = { 1: 'Main Store', 2: 'Kitchen Store', 3: 'Engineering Store', 4: 'Housekeeping Store' };

/* the Kitchen Store's position: item, unit, quantity in the store, reorder level, last cost */
const HOLDINGS = [
  { item: 'Cooking gas', unit: 'cylinders', qty: 6, level: 8, cost: 380.00 },
  { item: 'Chicken', unit: 'kg', qty: 68, level: 40, cost: 38.00 },
  { item: 'Rice', unit: 'kg', qty: 180, level: 300, cost: 9.50 },
  { item: 'Beef', unit: 'kg', qty: 28, level: 20, cost: 62.00 },
  { item: 'Cooking oil', unit: 'L', qty: 24, level: 12, cost: 32.00 },
  { item: 'Dishwashing liquid', unit: 'L', qty: 10, level: 8, cost: 22.00 },
  { item: 'Sugar', unit: 'kg', qty: 38, level: 40, cost: 12.00 },
  { item: 'Tomatoes', unit: 'kg', qty: 12, level: 10, cost: 18.00 },
  { item: 'Onions', unit: 'kg', qty: 21, level: 10, cost: 9.00 },
  { item: 'Tilapia', unit: 'kg', qty: 0, level: 20, cost: 45.00 },
];

/* the month's movements out of the Kitchen Store: date, item, type, quantity (always out), cost, department, reference */
const OUT = [
  { d: '2026-11-01', item: 'Rice', type: 'issue', qty: 180, dept: 'Kitchen', ref: 'SI-2026-0805', by: 'kwesi' },
  { d: '2026-11-03', item: 'Chicken', type: 'issue', qty: 12, dept: 'Banqueting', ref: 'SI-2026-0807', by: 'kwesi' },
  { d: '2026-11-06', item: 'Cooking gas', type: 'issue', qty: 4, dept: 'Kitchen', ref: 'SI-2026-0813', by: 'kwesi' },
  { d: '2026-11-04', item: 'Chicken', type: 'transfer_out', qty: 20, dept: null, ref: 'TR-2026-0022', by: 'yaw' },
  { d: '2026-11-02', item: 'Tomatoes', type: 'wastage', qty: 3, dept: null, ref: null, by: 'yaw' },
  { d: '2026-11-05', item: 'Onions', type: 'wastage', qty: 1, dept: null, ref: null, by: 'yaw' },
  { d: '2026-11-06', item: 'Dishwashing liquid', type: 'adjustment', qty: 6, dept: null, ref: null, by: 'efua' },
  { d: '2026-11-07', item: 'Beef', type: 'adjustment', qty: 2, dept: null, ref: null, by: 'efua' },
];

const LABELS = { issue: 'Issue', transfer_out: 'Transfer out', adjustment: 'Adjustment', wastage: 'Wastage' };

export function movementContext({ drift = false } = {}) {
  const QUERY = {};

  const out = OUT.map(m => ({ ...m, date: day(m.d), cost: HOLDINGS.find(h => h.item === m.item).cost,
    unit: HOLDINGS.find(h => h.item === m.item).unit }));

  /* ── the store's position ─────────────────────────────────────────────── */
  const rows = HOLDINGS.map(h => {
    const value = round2(h.qty * h.cost);
    const standing = h.qty <= 0 ? 'at zero' : (h.qty <= h.level ? 'below level' : 'in stock');

    return { item: h.item, unit: h.unit, qty: h.qty, level: h.level, value, standing, ratio: h.qty / h.level };
  }).sort((a, b) => b.value - a.value);

  const below = rows.filter(r => r.qty > 0 && r.qty <= r.level);
  const zero = rows.filter(r => r.qty <= 0);
  const tightest = below.slice().sort((a, b) => a.ratio - b.ratio)[0];
  const value = round2(rows.reduce((s, r) => s + r.value, 0));

  const position = {
    store: 'Kitchen Store', items: rows.length, value, below: below.length, zero: zero.length,
    top: rows[0], tightest, rows: rows.slice(0, 10),
  };
  position.sub = `${rows.length} items in the Kitchen Store, ${below.length} at or below level`;
  position.zero_sub = zero.length
    ? `${zero.length} item${zero.length === 1 ? '' : 's'} with nothing in the store, so nothing can be issued against them`
    : 'every item on the list has something in the store';

  /* ── what has left this month ─────────────────────────────────────────── */
  const money = (m) => round2(m.qty * m.cost);
  const monthValue = round2(out.reduce((s, m) => s + money(m), 0));
  const monthUnits = round2(out.reduce((s, m) => s + m.qty, 0));

  const types = Object.keys(LABELS).map(type => {
    const set = out.filter(m => m.type === type);
    if (!set.length) return null;

    return { type, label: LABELS[type], count: set.length, units: round2(set.reduce((s, m) => s + m.qty, 0)),
      value: round2(set.reduce((s, m) => s + money(m), 0)), tone: type === 'adjustment' ? 'amber' : 'slate' };
  }).filter(Boolean).sort((a, b) => b.value - a.value);

  const big = out.slice().sort((a, b) => money(b) - money(a))[0];
  const adjust = out.filter(m => m.type === 'adjustment');
  const month = {
    count: out.length, units: monthUnits, value: monthValue, rows: types,
    biggest: { item: big.item, unit: big.unit, qty: big.qty, date: big.date, value: money(big), department: big.dept },
    corrections: adjust.length, corrections_value: round2(adjust.reduce((s, m) => s + money(m), 0)),
  };
  month.sub = `${out.length} movements since 01 Nov 2026`;
  month.corrections_sub = adjust.length
    ? `GH₵ ${month.corrections_value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} corrected by hand — nothing posted is edited or deleted`
    : 'nothing has needed correcting: no adjustment has been posted out of this store this month';

  /* ── what nobody can answer for ───────────────────────────────────────── */
  const bare = drift ? [] : out.filter(m => !m.ref && !m.dept);
  const gapsValue = round2(bare.reduce((s, m) => s + money(m), 0));
  const gapRows = bare.slice().sort((a, b) => money(b) - money(a)).map(m => ({
    date: m.date, item: m.item, unit: m.unit, type: LABELS[m.type], qty: m.qty, value: money(m),
    user: m.by.charAt(0).toUpperCase() + m.by.slice(1) }));

  const gaps = {
    count: bare.length, value: gapsValue, waste: bare.filter(m => m.type === 'wastage').length,
    rows: gapRows, worst: gapRows[0] ?? null,
  };
  gaps.tone = bare.length ? 'amber' : 'green';
  gaps.head_sub = bare.length
    ? `GH₵ ${gapsValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} left this store with nothing behind it`
    : 'every movement out names a document or a department';
  gaps.sub = bare.length
    ? `${bare.length} movement${bare.length === 1 ? '' : 's'} out of this store with no reference and nobody named`
    : 'every movement out of this store this month names a document or a department';

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
      status_badge: (value) => `<span class="badge badge-slate">${String(value ?? '')}</span>`,
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) tail = Array.isArray(params) ? params.map(part).join('-') : part(params);
        return `#${String(name).replace(/\./g, '-')}${tail ? '-' + tail : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      setting: (key, fallback = null) => fallback,
      request: (key) => (key === undefined ? { boolean: () => false, except: () => ({}) } : (QUERY[key] ?? null)),
      http_build_query: (obj) => Object.entries(obj ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&'),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      blank: value => value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0),
      e: value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      ucfirst: value => String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);

        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      items: new Coll(HOLDINGS.map((h, i) => ({ id: i + 1, name: h.item, unit: h.unit }))),
      stores: STORES, storeId: 2,
      departments: { Kitchen: 'Kitchen', Banqueting: 'Banqueting', Engineering: 'Engineering', Housekeeping: 'Housekeeping' },
      position, month, gaps, currentHotel: HOTEL,
    },
  };
}
