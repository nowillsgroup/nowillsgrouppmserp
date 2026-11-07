/**
 * Sample data for the count-sheet preview — resources/views/inventory/stocktake-show.blade.php rendered the way
 * StockTakeController::show() hands it over: the sheet, its lines, and the three readings worked out from them.
 *
 * Every figure on the page is computed here from the line list below, the way the controller computes it from the same
 * lines: progress is counted lines over lines by category, the found card is the short and over side of the counted
 * lines, and the posting card is what posting would actually write. Nothing is written down twice.
 *
 * ST-2026-0121 — Kitchen Store, counted 07 Nov 2026 by Ama Boateng, still a draft. Eighteen lines, thirteen of them
 * carrying a figure and five left blank:
 *
 *   • the count is 72.2 % done — Food, Fuel and Guest Supplies are finished, Housekeeping is one line in three and
 *     Linen two in five;
 *   • what it has found is 2,078.00 short against 25.00 over, a net 2,053.00 on the counted lines, the largest the
 *     tilapia counted at zero: 18 kg against 18 on the book, 810.00;
 *   • what posting would do is write ten movements for 2,053.00 and leave the five blank lines on the book — 16,154.00
 *     that the "treat blank counts as zero" box would write off in one click, the largest of them the bed sheets at
 *     8,550.00.
 *
 * The drift build is the same sheet after the linen run is finished: the five blanks are counted, three of them agree
 * with the book and two are short, so the postable value rises and the cost of the box falls to nothing.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;
const pct = (v, of) => Math.round((of ? v / of * 100 : 0) * 10) / 10;

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* item, code, category, unit, book qty, counted qty (null = never counted), unit cost, remark */
const LINES = [
  { item: 'Chicken', code: 'FD-004', cat: 'Food', unit: 'kg', sys: 68, counted: 62, cost: 38.00, note: 'trimmed weight' },
  { item: 'Rice', code: 'FD-006', cat: 'Food', unit: 'kg', sys: 180, counted: 180, cost: 9.50, note: null },
  { item: 'Sugar', code: 'FD-009', cat: 'Food', unit: 'kg', sys: 42, counted: 38, cost: 12.00, note: null },
  { item: 'Cooking oil', code: 'FD-011', cat: 'Food', unit: 'L', sys: 24, counted: 24, cost: 32.00, note: null },
  { item: 'Tomatoes', code: 'FD-017', cat: 'Food', unit: 'kg', sys: 15, counted: 12, cost: 18.00, note: 'spoiled' },
  { item: 'Onions', code: 'FD-018', cat: 'Food', unit: 'kg', sys: 20, counted: 21, cost: 9.00, note: null },
  { item: 'Beef', code: 'FD-021', cat: 'Food', unit: 'kg', sys: 30, counted: 28, cost: 62.00, note: null },
  { item: 'Tilapia', code: 'FD-024', cat: 'Food', unit: 'kg', sys: 18, counted: 0, cost: 45.00, note: 'none left — the book still carries it' },
  { item: 'Cooking gas', code: 'FU-002', cat: 'Fuel', unit: 'cylinders', sys: 6, counted: 5, cost: 380.00, note: 'one cylinder out on loan' },
  { item: 'Guest soap', code: 'GS-002', cat: 'Guest Supplies', unit: 'pcs', sys: 0, counted: 0, cost: 4.20, note: null },
  { item: 'Dishwashing liquid', code: 'HK-006', cat: 'Housekeeping', unit: 'L', sys: 12, counted: 10, cost: 22.00, note: null },
  { item: 'Laundry powder', code: 'HK-003', cat: 'Housekeeping', unit: 'kg', sys: 23, counted: null, cost: 28.00, note: null },
  { item: 'Bin liners', code: 'HK-009', cat: 'Housekeeping', unit: 'packs', sys: 40, counted: null, cost: 6.00, note: null },
  { item: 'Bath towel', code: 'LN-001', cat: 'Linen', unit: 'pcs', sys: 276, counted: 270, cost: 65.00, note: 'six out of circulation' },
  { item: 'Table cloth', code: 'LN-004', cat: 'Linen', unit: 'pcs', sys: 84, counted: null, cost: 35.00, note: null },
  { item: 'Napkin', code: 'LN-006', cat: 'Linen', unit: 'pcs', sys: 320, counted: 322, cost: 8.00, note: null },
  { item: 'Bed sheet', code: 'LN-008', cat: 'Linen', unit: 'pcs', sys: 190, counted: null, cost: 45.00, note: null },
  { item: 'Pillow case', code: 'LN-010', cat: 'Linen', unit: 'pcs', sys: 210, counted: null, cost: 18.00, note: null },
];

export function stocktakeShowContext({ drift = false } = {}) {
  const QUERY = {};

  const base = LINES.map(l => ({ ...l }));
  if (drift) {
    // the linen run finishes: the table cloths and bed sheets agree, the pillow cases are short
    base.find(l => l.item === 'Laundry powder').counted = 23;
    base.find(l => l.item === 'Bin liners').counted = 40;
    base.find(l => l.item === 'Table cloth').counted = 84;
    base.find(l => l.item === 'Bed sheet').counted = 190;
    base.find(l => l.item === 'Pillow case').counted = 206;
  }

  const lines = base.map((l, i) => {
    const variance = l.counted === null ? null : round2(l.counted - l.sys);
    const value = variance === null ? 0 : round2(variance * l.cost);

    return { ...l, id: 4200 + i, variance, value, blank: l.counted === null,
      zero: l.counted === 0 && l.sys !== 0, same: variance !== null && Math.abs(variance) < 0.005,
      moved: variance !== null && Math.abs(variance) >= 0.005 };
  });

  const counted = lines.filter(l => !l.blank);
  const blank = lines.filter(l => l.blank);
  const zeroLines = lines.filter(l => l.zero);
  const moved = lines.filter(l => l.moved);
  const sameLines = lines.filter(l => l.same);
  const progressPct = pct(counted.length, lines.length);

  const cats = [...new Set(lines.map(l => l.cat))].sort();
  const byCat = cats.map(c => {
    const set = lines.filter(l => l.cat === c);
    const c2 = set.filter(l => !l.blank);
    const short = round2(c2.reduce((s, l) => s + Math.min(0, l.value), 0));
    const over = round2(c2.reduce((s, l) => s + Math.max(0, l.value), 0));

    return { category: c, lines: set.length, counted: c2.length, blank: set.length - c2.length,
      pct: pct(c2.length, set.length), short, over, net: round2(short + over),
      tone: pct(c2.length, set.length) < 100 ? 'text-red bold' : '' };
  });

  const progress = {
    lines: lines.length, counted: counted.length, blank: blank.length, pct: progressPct,
    zero: zeroLines.length, same: sameLines.length, moved: moved.length,
    rows: byCat.map(c => ({ category: c.category, lines: c.lines, counted: c.counted, blank: c.blank, pct: c.pct, tone: c.tone })),
  };
  progress.pct_tone = progressPct >= 100 ? 'green' : 'amber';
  progress.sub = `${counted.length} counted · ${blank.length} blank`;
  const behind = byCat.filter(c => c.blank > 0).map(c => c.category);
  progress.behind = behind.length > 1 ? `${behind.slice(0, -1).join(', ')} and ${behind[behind.length - 1]}` : (behind[0] ?? '');

  const short = round2(counted.reduce((s, l) => s + Math.min(0, l.value), 0));
  const over = round2(counted.reduce((s, l) => s + Math.max(0, l.value), 0));
  const worst = counted.filter(l => l.value < -0.005).sort((a, b) => a.value - b.value)[0];

  const found = {
    value: round2(short + over), short, over,
    rows: byCat.map(c => ({ category: c.category, counted: c.counted, short: c.short, over: c.over, net: c.net })),
    worst: worst ? { item: worst.item, unit: worst.unit, system: worst.sys, counted: worst.counted,
      qty: Math.abs(worst.variance), cost: worst.cost, value: Math.abs(worst.value) } : null,
  };
  found.tone = short < 0 ? 'red' : 'green';

  const blankValue = round2(blank.reduce((s, l) => s + l.sys * l.cost, 0));
  const zeroValue = round2(zeroLines.reduce((s, l) => s + l.sys * l.cost, 0));
  const biggestBlank = blank.slice().sort((a, b) => b.sys * b.cost - a.sys * a.cost)[0];

  const writes = {
    moved: moved.length, value: round2(moved.reduce((s, l) => s + l.value, 0)),
    zero: zeroLines.length, zero_value: zeroValue, same: sameLines.length,
    blank: blank.length, blank_value: blankValue,
    biggest_blank: biggestBlank ? { item: biggestBlank.item, unit: biggestBlank.unit, qty: biggestBlank.sys,
      value: round2(biggestBlank.sys * biggestBlank.cost) } : null,
    rows: moved.slice().sort((a, b) => Math.abs(b.value) - Math.abs(a.value)).slice(0, 10).map(l => ({
      item: l.item, unit: l.unit, system: l.sys, counted: l.counted, qty: l.variance, value: l.value,
      tone: l.variance < 0 ? 'text-red' : 'text-green' })),
  };
  writes.tone = blank.length ? 'amber' : 'green';
  writes.blank_head_sub = blank.length
    ? `GH₵ ${blankValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} stays on the book unless the box below is ticked`
    : 'every line carries a figure, so nothing is left unseen';
  writes.box_sub = blank.length
    ? 'tick “treat blank counts as zero” and this is written off the book, sight unseen'
    : 'with every line counted there is nothing for the box to write off';
  writes.blank_sub = blank.length
    ? `${blank.length} line${blank.length === 1 ? '' : 's'} with no figure — posting leaves them on the book`
    : 'every line carries a figure, so posting covers the whole sheet';
  writes.zero_sub = zeroLines.length
    ? `${zeroLines.length} line${zeroLines.length === 1 ? '' : 's'} counted at zero — the whole quantity is written off`
    : 'nothing was counted at zero against a book quantity';

  const take = {
    id: 121, take_no: 'ST-2026-0121', store: { name: 'Kitchen Store' }, count_date: day('2026-11-07'),
    status: 'draft', creator: { name: 'Ama Boateng' }, poster: null, posted_at: null, variance_value: 0,
    lines: new Coll(lines.map(l => ({
      id: l.id, item: { name: l.item, code: l.code, unit: l.unit, category: { name: l.cat } },
      system_qty: l.sys, counted_qty: l.counted, unit_cost: l.cost, remarks: l.note,
      varianceQty: () => l.variance, varianceValue: () => l.value,
    }))),
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
      status_badge: (value) => `<span class="badge badge-${{ draft: 'amber', posted: 'green', cancelled: 'slate' }[value] ?? 'slate'}">${String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1)}</span>`,
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
    scope: { take, progress, found, writes, editable: true, currentHotel: HOTEL,
      stores: { 2: 'Kitchen Store' }, categories: { 3: 'Food' } },
  };
}
