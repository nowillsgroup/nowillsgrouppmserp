/**
 * Sample data for the stock-on-hand preview — resources/views/inventory/index.blade.php rendered the way
 * InventoryController::index() hands it over: the item list with its per-store quantities, plus the three readings.
 *
 * Every figure on the screen is computed here from the item table below, the way the controller computes it from the
 * movement sums: the shelf totals value at each item's last cost, the store card splits that value by store, and the
 * ageing card is the items nobody has drawn from in ninety days. Nothing is written down twice.
 *
 * Fifteen active lines, fourteen of them holding stock, 90,374.50 on the shelf:
 *
 *   • the shelf — diesel is 1,818 L and 43,632.00, 48.3 % of everything on it; the three largest lines are 74.9 %,
 *     which is what makes a store fragile;
 *   • the split — the Main Store carries 61,166.50 of it (67.7 %), and four items are held in more than one store;
 *   • the age — four items worth 10,440.00 have not moved in ninety days, 11.6 % of the shelf, the oldest the
 *     Christmas decoration set at 322 days.
 *
 * The drift build is the quarter after the store is tidied: the chair covers are transferred to the banqueting store
 * and issued out, the pool chlorine is drawn down, and one more menu folder run is printed — the ageing card empties
 * to a single item and the shelf value falls with it, and nothing else about the count moves.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;
const pct = (v, of) => Math.round((of ? v / of * 100 : 0) * 10) / 10;
const TODAY_MS = Date.parse('2026-11-07T00:00:00Z');
const daysSince = (iso) => Math.round((TODAY_MS - Date.parse(`${iso}T00:00:00Z`)) / 86400000);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* Store::orderBy('name') — the column order the screen prints */
const STORES = [
  { id: 3, name: 'Engineering Store' },
  { id: 4, name: 'Housekeeping Store' },
  { id: 2, name: 'Kitchen Store' },
  { id: 1, name: 'Main Store' },
];

/* code, item, category, unit, reorder level, last cost, quantity per store, last movement (null = never) */
const ITEMS = [
  { id: 9, code: 'LN-001', name: 'Bath towel', category: 'Linen', unit: 'pcs', reorder: 60, cost: 65.00,
    stores: { 4: 276 }, last: '2026-10-26' },
  { id: 21, code: 'LN-014', name: 'Banqueting chair cover', category: 'Linen', unit: 'pcs', reorder: 40, cost: 22.00,
    stores: { 1: 240 }, last: '2026-06-14' },
  { id: 4, code: 'FD-004', name: 'Chicken', category: 'Food', unit: 'kg', reorder: 40, cost: 38.00,
    stores: { 2: 68 }, last: '2026-10-22' },
  { id: 2, code: 'FU-002', name: 'Cooking gas', category: 'Fuel', unit: 'cylinders', reorder: 8, cost: 380.00,
    stores: { 1: 10, 2: 6 }, last: '2026-11-06' },
  { id: 22, code: 'AD-008', name: 'Christmas decoration set', category: 'Administration', unit: 'sets', reorder: 4,
    cost: 180.00, stores: { 1: 12 }, last: '2025-12-20' },
  { id: 1, code: 'FU-001', name: 'Diesel', category: 'Fuel', unit: 'L', reorder: 400, cost: 24.00,
    stores: { 1: 1758, 3: 60 }, last: '2026-11-04' },
  { id: 3, code: 'FU-003', name: 'Engine oil', category: 'Fuel', unit: 'L', reorder: 40, cost: 45.00,
    stores: { 3: -6 }, last: '2026-11-06' },
  { id: 7, code: 'GS-002', name: 'Guest soap', category: 'Guest Supplies', unit: 'pcs', reorder: 200, cost: 4.20,
    stores: { 1: 60, 4: 200 }, last: '2026-10-21' },
  { id: 8, code: 'HK-003', name: 'Laundry powder', category: 'Housekeeping', unit: 'kg', reorder: 20, cost: 28.00,
    stores: { 4: 23 }, last: '2026-10-30' },
  { id: 23, code: 'EG-011', name: 'Pool chlorine', category: 'Engineering', unit: 'kg', reorder: 20, cost: 34.00,
    stores: { 3: 60 }, last: '2026-08-02' },
  { id: 24, code: 'AD-012', name: 'Printed menu folder', category: 'Administration', unit: 'pcs', reorder: 25,
    cost: 12.00, stores: { 1: 80 }, last: '2026-07-11' },
  { id: 5, code: 'FD-006', name: 'Rice', category: 'Food', unit: 'kg', reorder: 300, cost: 9.50,
    stores: { 1: 415, 2: 180 }, last: '2026-11-02' },
  { id: 6, code: 'FD-009', name: 'Sugar', category: 'Food', unit: 'kg', reorder: 100, cost: 12.00,
    stores: { 1: 135 }, last: '2026-11-03' },
  { id: 10, code: 'AD-003', name: 'Toner cartridge', category: 'Administration', unit: 'pcs', reorder: 2, cost: 320.00,
    stores: { 1: 3 }, last: '2026-11-06' },
  { id: 25, code: 'FD-014', name: 'Chafing fuel', category: 'Food', unit: 'pcs', reorder: 24, cost: 15.00,
    stores: {}, last: null },
];

export function stockContext({ drift = false } = {}) {
  const QUERY = {};

  const base = ITEMS.map(i => ({ ...i, stores: { ...i.stores } }));

  if (drift) {
    // the tidy-up: the chair covers leave the Main Store, the chlorine is drawn down, one menu run is printed
    const cover = base.find(i => i.name === 'Banqueting chair cover');
    cover.stores = {};
    cover.last = '2026-11-07';
    const chlorine = base.find(i => i.name === 'Pool chlorine');
    chlorine.stores = { 3: 18 };
    chlorine.last = '2026-11-05';
    const folder = base.find(i => i.name === 'Printed menu folder');
    folder.stores = { 1: 55 };
    folder.last = '2026-11-06';
  }

  const items = base.map(i => {
    const total = round2(Object.values(i.stores).reduce((s, q) => s + q, 0));
    const value = round2(total * i.cost);
    const last = i.last ? day(i.last) : null;
    const days = i.last ? daysSince(i.last) : null;

    return { ...i, total, value, last_moved: last, days_still: days,
      total_label: total.toLocaleString('en-US', { maximumFractionDigits: 2 }),
      cells: Object.fromEntries(STORES.map(s => [s.id, i.stores[s.id]
        ? i.stores[s.id].toLocaleString('en-US', { maximumFractionDigits: 2 }) : '—'])) };
  });

  const held = items.filter(i => Math.abs(i.total) > 0.004);
  const value = round2(items.reduce((s, i) => s + i.value, 0));
  const byValue = held.slice().sort((a, b) => b.value - a.value);
  const top = byValue[0];

  const shelf = {
    items: held.length, value, zero: items.filter(i => Math.abs(i.total) <= 0.004).length,
    stores: STORES.filter(s => items.some(i => Math.abs(i.stores[s.id] ?? 0) > 0.004)).length,
    negative: held.filter(i => i.total < 0).length,
    top: { item: top.name, unit: top.unit, qty: top.total, qty_label: Number(top.total).toLocaleString('en-US'), value: top.value,
      share: pct(top.value, value), store: STORES.find(s => (top.stores[s.id] ?? 0) === Math.max(...Object.values(top.stores)))?.name },
    rows: byValue.slice(0, 8).map(i => ({ item: i.name, unit: i.unit, qty: Number(i.total).toLocaleString('en-US'), value: i.value,
      category: i.category, share: pct(i.value, value) })),
  };
  shelf.top_three = pct(byValue.slice(0, 3).reduce((s, i) => s + i.value, 0), value);
  shelf.sub = `${held.length} items with stock, ${shelf.zero} at zero`;
  shelf.zero_sub = `${shelf.zero} active item${shelf.zero === 1 ? '' : 's'} at zero, and left out of this count`;

  const whereRows = STORES.map(s => {
    const inStore = items.filter(i => Math.abs(i.stores[s.id] ?? 0) > 0.004);
    const worth = round2(inStore.reduce((t, i) => t + (i.stores[s.id] ?? 0) * i.cost, 0));

    return { id: s.id, store: s.name, items: inStore.length, value: worth,
      share: pct(worth, value), tone: worth < 0 ? 'text-red' : '' };
  }).filter(r => r.items > 0).sort((a, b) => b.value - a.value);

  const multi = items.filter(i => Object.values(i.stores).filter(q => Math.abs(q) > 0.004).length > 1).length;
  const where = { rows: whereRows, top: whereRows[0], multi };
  where.sub = `${whereRows.length} stores holding stock · ${multi} items are split across more than one`;
  where.split_sub = `${multi} item${multi === 1 ? '' : 's'} are held in more than one store, and count twice against the same purchase`;

  const asleep = held.filter(i => i.days_still === null || i.days_still >= 90).sort((a, b) => b.value - a.value);
  const asleepValue = round2(asleep.reduce((s, i) => s + i.value, 0));
  const oldest = asleep.slice().sort((a, b) => (b.days_still ?? 1e9) - (a.days_still ?? 1e9))[0];
  const still = {
    count: asleep.length, value: asleepValue, share: pct(asleepValue, value),
    never: held.filter(i => i.days_still === null).length,
    oldest: oldest ? { item: oldest.name, unit: oldest.unit, qty: Number(oldest.total).toLocaleString('en-US'), value: oldest.value,
      days: oldest.days_still, last: oldest.last_moved } : null,
    rows: asleep.slice(0, 10).map(i => ({ item: i.name, unit: i.unit, qty: Number(i.total).toLocaleString('en-US'), value: i.value,
      date: i.last_moved, days: i.days_still })),
  };
  still.tone = asleep.length ? 'amber' : 'green';
  still.share_label = `${still.share} % of the value on the shelf`;
  still.sub = `${asleep.length} item${asleep.length === 1 ? '' : 's'} untouched for ninety days`;
  still.asleep_sub = 'on the shelf and not drawn from since 09 Aug 2026';

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
      items: new Coll(items.map(i => ({
        id: i.id, code: i.code, name: i.name, unit: i.unit, category: { name: i.category },
        reorder_level: i.reorder, last_cost: i.cost, by_store: i.stores, total: i.total, value: i.value,
        total_label: i.total_label, cells: i.cells, last_moved: i.last_moved, days_still: i.days_still,
      }))),
      stores: new Coll(STORES), categories: { 1: 'Administration', 2: 'Engineering', 3: 'Food', 4: 'Fuel', 5: 'Guest Supplies', 6: 'Housekeeping', 7: 'Linen' },
      value, shelf, where, still, currentHotel: HOTEL,
    },
  };
}
