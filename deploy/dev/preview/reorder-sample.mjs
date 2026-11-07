/**
 * Sample data for the re-order suggestions preview — resources/views/inventory/reorder.blade.php rendered the way
 * StockTakeController::reorder() hands it over: the rows, and the three readings behind the suggestion.
 *
 * The suggestion is the one screen where the procurement chain starts again: a level says what somebody once thought
 * was enough, a usage figure says what the shelf is really doing, and a draft order says what is not coming. Ten items
 * are at or below their re-order level in this sample, 2,000 units short of twice the level and 34,164.00 apart.
 *
 *   • the gap — 10 items, two of them at zero (toilet paper and guest soap, which is a service waiting to be refused),
 *     and 34,164.00 to bring the shelf back to twice the level. The largest is diesel: 180 litres against a 400 level,
 *     620 litres short at 14.20 = 8,804.00.
 *   • the pipeline — 7,468.00 on order across four items, 3,040.00 of it sitting in a draft order nobody approved
 *     (the cooking gas), and 3,050.00 requested by departments that no order covers. Five of the ten shortages have
 *     nothing at all against them.
 *   • the cover — 8,850 units issued in sixty days, 171,906.00 at the rates they left at. Guest soap has already run
 *     out, diesel has two days, and the engine oil has not moved at all, so its level is a habit and not a demand.
 *
 * The drift build breaks the pipeline and the cover and leaves the gap alone: a sugar line appears in a draft order
 * (4,030.00 now sitting unapproved, four shortages uncovered instead of five), and a banqueting week lifts the chicken
 * and diesel draw while the print paper stops moving altogether — 10,626 units and 215,538.00 of usage, two items
 * silent, and one item fewer with a usage figure to measure against.
 *
 * "Today" is 7 November 2026, the usage window opens 8 September 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const SINCE = '2026-09-08';
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const SUPPLIERS = {
  3: 'Accra Fresh Produce', 5: 'Golden Gate Hardware', 8: 'Coastal Beverages Ltd',
  12: 'Tema Cleaning Supplies', 14: 'Volta Power Systems', 21: 'Sahel Textiles', 27: 'Kumasi Paper & Packaging',
};

/* name · unit · category · stock · level · on order · in draft · requested · used 60 d · rate · supplier · last moved */
const ITEMS = [
  { id: 4, name: 'Bath towels', unit: 'pcs', cat: 'Linen', stock: 18, level: 30, on_order: 0, draft: 0, requested: 0,
    used60: 60, rate: 85.00, supplier: 21, moved: '2026-09-20' },
  { id: 6, name: 'Chicken breast', unit: 'kg', cat: 'Food', stock: 22, level: 40, on_order: 60, draft: 0, requested: 20,
    used60: 480, rate: 46.00, supplier: 3, moved: '2026-11-06' },
  { id: 2, name: 'Cooking gas', unit: 'cylinders', cat: 'Fuel', stock: 4, level: 12, on_order: 0, draft: 8, requested: 0,
    used60: 96, rate: 380.00, supplier: 14, moved: '2026-11-02' },
  { id: 1, name: 'Diesel', unit: 'L', cat: 'Fuel', stock: 180, level: 400, on_order: 0, draft: 0, requested: 150,
    used60: 4800, rate: 14.20, supplier: 14, moved: '2026-11-06' },
  { id: 9, name: 'Engine oil 15W-40', unit: 'L', cat: 'Engineering', stock: 12, level: 20, on_order: 0, draft: 0, requested: 0,
    used60: 0, rate: 62.00, supplier: 5, moved: '2026-06-14' },
  { id: 10, name: 'Guest soap 40 g', unit: 'pcs', cat: 'Guest Supplies', stock: 0, level: 300, on_order: 200, draft: 0, requested: 0,
    used60: 780, rate: 4.50, supplier: 12, moved: '2026-10-28' },
  { id: 3, name: 'Laundry detergent', unit: 'kg', cat: 'Laundry', stock: 45, level: 60, on_order: 0, draft: 0, requested: 0,
    used60: 390, rate: 28.00, supplier: 27, moved: '2026-11-03' },
  { id: 11, name: 'Print paper A4', unit: 'reams', cat: 'Admin', stock: 8, level: 15, on_order: 0, draft: 0, requested: 0,
    used60: 24, rate: 42.00, supplier: 27, moved: '2026-09-01' },
  { id: 5, name: 'Sugar', unit: 'kg', cat: 'Food', stock: 45, level: 50, on_order: 0, draft: 0, requested: 0,
    used60: 900, rate: 18.00, supplier: 3, moved: '2026-11-07' },
  { id: 7, name: 'Toilet paper 2-ply', unit: 'rolls', cat: 'Guest Supplies', stock: 0, level: 240, on_order: 120, draft: 0, requested: 0,
    used60: 1320, rate: 6.40, supplier: 12, moved: '2026-11-05' },
];

const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const isoOf = (d) => (typeof d?.toDateString === 'function' ? d.toDateString().slice(0, 10) : String(d).slice(0, 10));

export function reorderContext({ drift = false } = {}) {
  const QUERY = {};

  const rows = ITEMS.map(base => {
    const it = { ...base };
    if (drift) {
      if (it.name === 'Sugar') it.draft = 55;
      if (it.name === 'Chicken breast') it.used60 = 1080;
      if (it.name === 'Diesel') it.used60 = 6000;
      if (it.name === 'Print paper A4') { it.used60 = 0; it.moved = '2026-07-20'; }
    }
    const onOrder = round2(it.on_order + it.draft);
    const level = it.level;
    const stock = it.stock;
    const short = round2(Math.max(0, level * 2 - stock));
    const perDay = round2(it.used60 / 60);
    const daysCover = perDay > 0 ? Math.round(stock / perDay) : null;
    const runout = daysCover !== null
      ? new SampleDate(new Date(Date.parse('2026-11-07T00:00:00Z') + Math.max(0, daysCover) * 86400000).toISOString())
      : null;

    return {
      item: { id: it.id, name: it.name, unit: it.unit, category: { name: it.cat } },
      stock, level, on_order: onOrder, suggest: Math.max(0, Math.ceil(level * 2 - stock - onOrder)),
      supplier_id: it.supplier, rate: it.rate,
      used60: round2(it.used60), used_value: round2(it.used60 * it.rate), per_day: perDay, days_cover: daysCover,
      runout, last_moved: it.moved,
      short, short_value: round2(short * it.rate), used_label: Number(it.used60).toLocaleString('en-US'),
      draft_qty: round2(it.draft), requested_qty: round2(it.requested),
    };
  });

  const byShort = rows.slice().sort((a, b) => b.short_value - a.short_value);
  const gap = {
    count: rows.length, zero: rows.filter(r => r.stock <= 0).length,
    short_qty: round2(rows.reduce((t, r) => t + r.short, 0)),
    short_value: round2(rows.reduce((t, r) => t + r.short_value, 0)),
    worst: byShort[0], rows: byShort.slice(0, 10),
  };
  gap.short_qty_label = gap.short_qty.toLocaleString('en-US');

  const piped = rows.filter(r => r.on_order > 0 || r.requested_qty > 0).sort((a, b) => b.on_order - a.on_order);
  const pipeline = {
    value: round2(rows.reduce((t, r) => t + r.on_order * r.rate, 0)),
    items: rows.filter(r => r.on_order > 0).length,
    draft_value: round2(rows.reduce((t, r) => t + r.draft_qty * r.rate, 0)),
    draft_items: rows.filter(r => r.draft_qty > 0).length,
    requested_value: round2(rows.reduce((t, r) => t + r.requested_qty * r.rate, 0)),
    requested_items: rows.filter(r => r.requested_qty > 0).length,
    nothing: rows.filter(r => r.on_order <= 0 && r.requested_qty <= 0 && r.suggest > 0).length,
    worst: rows.slice().sort((a, b) => b.draft_qty * b.rate - a.draft_qty * a.rate)[0],
    rows: piped,
  };

  const covered = rows.filter(r => r.days_cover !== null).sort((a, b) => a.days_cover - b.days_cover);
  const cover = {
    usage_qty: round2(rows.reduce((t, r) => t + r.used60, 0)),
    usage_value: round2(rows.reduce((t, r) => t + r.used_value, 0)),
    silent: rows.filter(r => r.used60 <= 0).length,
    silent_rows: rows.filter(r => r.used60 <= 0),
    aged: rows.filter(r => r.last_moved && Date.parse(`${r.last_moved}T00:00:00Z`) < Date.parse('2026-08-09T00:00:00Z')).length,
    fastest: covered[0] ?? null,
    rows: covered.slice(0, 10),
  };
  cover.usage_qty_label = cover.usage_qty.toLocaleString('en-US');
  cover.aged_sub = cover.aged
    ? `${cover.aged === 1 ? '1 of them has' : `${cover.aged} of them have`} not moved in ninety days, so the level is a habit not a demand`
    : 'every item below the level has been used in the last sixty days';

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        const d = new Date(`${isoOf(value)}T00:00:00Z`);
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
      old: (key, fallback = null) => fallback,
      setting: (key, fallback = null) => fallback,
      request: (key) => (key === undefined ? { boolean: () => false, except: () => ({}) } : (QUERY[key] ?? null)),
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
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);

        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      rows: new Coll(rows), suppliers: SUPPLIERS,
      stores: { 1: 'Main Store', 2: 'Kitchen Store', 3: 'Engineering Store' },
      since: SINCE, gap, pipeline, cover,
      currentHotel: HOTEL,
    },
  };
}
