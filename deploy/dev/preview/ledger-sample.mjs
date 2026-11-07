/**
 * Sample data for the stock ledger preview — resources/views/inventory/ledger.blade.php rendered the way
 * InventoryController::ledger() hands it over: the paginated movements plus the three readings worked out from them.
 *
 * Every figure the screen prints is computed here from the movement list below, the way the controller computes it from
 * the rows: the flow groups by type, the balance rolls each item from its opening quantity to its closing one, and the
 * gaps are the movements out that name neither a reference nor a department. Nothing is written down twice, so the
 * cards and the table cannot disagree — which is the whole point of the round.
 *
 * The window is 09 Oct – 07 Nov 2026, thirty movements:
 *
 *   • the flow — GH₵ 77,760.00 in against 37,809.50 out, a net 39,950.50, the largest single line the 1,200 L diesel
 *     receipt on 09 Oct at 28,800.00;
 *   • the balance — ten items rolled forward from 39,984.00 to 80,172.00, and the engine oil closes at −6 L: twelve
 *     litres on the book, eighteen issued, nothing received;
 *   • the gaps — four movements out with no document, 1,197.50, 3.2 % of the value that left: the diesel dip, two
 *     wastages and a damaged cartridge, the largest the 22 L adjustment by Kwesi Appiah.
 *
 * The drift build is the morning after two things get fixed: a late 24 L engine-oil receipt is keyed in, which brings
 * that item back above zero, and the rice wastage gets a wastage form, which takes one movement off the gap list.
 * Nothing else on the shelf changes.
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

/* Laravel hands the view a paginator: countable, iterable, with links() at the foot. */
class Pager extends Coll {
  total() {
    return this.count();
  }

  links() {
    return raw('');
  }
}

const STORES = { 1: 'Main Store', 2: 'Kitchen Store', 3: 'Engineering Store', 4: 'Housekeeping Store' };

/* name, unit, rate, and the quantity on the book the morning the window opened */
const ITEMS = [
  { id: 1, name: 'Diesel', unit: 'L', rate: 24.00, opening: 400 },
  { id: 2, name: 'Cooking gas', unit: 'cylinders', rate: 380.00, opening: 6 },
  { id: 3, name: 'Engine oil', unit: 'L', rate: 45.00, opening: 12 },
  { id: 4, name: 'Chicken', unit: 'kg', rate: 38.00, opening: 40 },
  { id: 5, name: 'Rice', unit: 'kg', rate: 9.50, opening: 520 },
  { id: 6, name: 'Sugar', unit: 'kg', rate: 12.00, opening: 15 },
  { id: 7, name: 'Guest soap', unit: 'pcs', rate: 4.20, opening: 40 },
  { id: 8, name: 'Laundry powder', unit: 'kg', rate: 28.00, opening: 22 },
  { id: 9, name: 'Bath towel', unit: 'pcs', rate: 65.00, opening: 300 },
  { id: 10, name: 'Toner cartridge', unit: 'pcs', rate: 320.00, opening: 2 },
];

const item = (name) => ITEMS.find(i => i.name === name);

/** One stock movement, shaped like StockMovement. */
const mv = (o) => ({
  date: day(o.d), item: item(o.item), store: { name: STORES[o.store] }, type: o.type,
  qty: o.qty, rate: o.rate ?? item(o.item).rate,
  department: o.dept ?? null, reference: o.ref ?? null, remarks: o.note ?? null,
  user: o.by ? { username: o.by } : null,
});

const MOVES = [
  // receipts
  mv({ d: '2026-10-09', item: 'Diesel', store: 1, type: 'purchase', qty: 1200, ref: 'GRN-2026-0341', by: 'ama' }),
  mv({ d: '2026-10-12', item: 'Rice', store: 1, type: 'purchase', qty: 500, ref: 'GRN-2026-0344', by: 'ama' }),
  mv({ d: '2026-10-12', item: 'Cooking gas', store: 1, type: 'purchase', qty: 20, ref: 'GRN-2026-0344', by: 'ama' }),
  mv({ d: '2026-10-16', item: 'Chicken', store: 2, type: 'purchase', qty: 180, ref: 'GRN-2026-0348', by: 'yaw' }),
  mv({ d: '2026-10-20', item: 'Guest soap', store: 1, type: 'purchase', qty: 400, ref: 'GRN-2026-0351', by: 'ama' }),
  mv({ d: '2026-10-23', item: 'Bath towel', store: 4, type: 'purchase', qty: 60, ref: 'GRN-2026-0355', by: 'efua' }),
  mv({ d: '2026-10-27', item: 'Laundry powder', store: 4, type: 'purchase', qty: 40, ref: 'GRN-2026-0358', by: 'efua' }),
  mv({ d: '2026-11-02', item: 'Diesel', store: 1, type: 'purchase', qty: 800, ref: 'GRN-2026-0362', by: 'ama' }),
  mv({ d: '2026-11-03', item: 'Sugar', store: 1, type: 'purchase', qty: 120, ref: 'GRN-2026-0364', by: 'ama' }),
  mv({ d: '2026-11-05', item: 'Toner cartridge', store: 1, type: 'purchase', qty: 4, ref: 'GRN-2026-0366', by: 'kofi' }),

  // issues
  mv({ d: '2026-10-10', item: 'Diesel', store: 3, type: 'issue', qty: -300, dept: 'Engineering', ref: 'SI-2026-0781', by: 'kwesi' }),
  mv({ d: '2026-10-13', item: 'Rice', store: 2, type: 'issue', qty: -220, dept: 'Kitchen', ref: 'SI-2026-0784', by: 'kwesi' }),
  mv({ d: '2026-10-14', item: 'Cooking gas', store: 2, type: 'issue', qty: -6, dept: 'Kitchen', ref: 'SI-2026-0786', by: 'kwesi' }),
  mv({ d: '2026-10-17', item: 'Chicken', store: 2, type: 'issue', qty: -140, dept: 'Kitchen', ref: 'SI-2026-0790', by: 'kwesi' }),
  mv({ d: '2026-10-21', item: 'Guest soap', store: 4, type: 'issue', qty: -180, dept: 'Housekeeping', ref: 'SI-2026-0794', by: 'efua' }),
  mv({ d: '2026-10-24', item: 'Bath towel', store: 4, type: 'issue', qty: -90, dept: 'Housekeeping', ref: 'SI-2026-0798', by: 'efua' }),
  mv({ d: '2026-10-28', item: 'Laundry powder', store: 4, type: 'issue', qty: -35, dept: 'Housekeeping', ref: 'SI-2026-0801', by: 'efua' }),
  mv({ d: '2026-11-01', item: 'Rice', store: 2, type: 'issue', qty: -180, dept: 'Kitchen', ref: 'SI-2026-0805', by: 'kwesi' }),
  mv({ d: '2026-11-04', item: 'Diesel', store: 3, type: 'issue', qty: -260, dept: 'Engineering', ref: 'SI-2026-0810', by: 'kwesi' }),
  mv({ d: '2026-11-06', item: 'Cooking gas', store: 2, type: 'issue', qty: -4, dept: 'Kitchen', ref: 'SI-2026-0813', by: 'kwesi' }),
  mv({ d: '2026-11-06', item: 'Engine oil', store: 3, type: 'issue', qty: -18, dept: 'Engineering', ref: 'SI-2026-0813', by: 'kwesi' }),
  mv({ d: '2026-11-06', item: 'Toner cartridge', store: 1, type: 'issue', qty: -2, dept: 'Administration', ref: 'SI-2026-0814', by: 'kofi' }),

  // one internal transfer, both sides on the same reference
  mv({ d: '2026-10-15', item: 'Rice', store: 1, type: 'transfer_out', qty: -80, ref: 'TR-2026-0021', by: 'ama' }),
  mv({ d: '2026-10-15', item: 'Rice', store: 2, type: 'transfer_in', qty: 80, ref: 'TR-2026-0021', by: 'ama' }),

  // adjustments — two of the three name nothing
  mv({ d: '2026-10-19', item: 'Diesel', store: 1, type: 'adjustment', qty: -22, by: 'kwesi', note: 'Dip reading, no document' }),
  mv({ d: '2026-10-26', item: 'Bath towel', store: 4, type: 'adjustment', qty: 6, by: 'efua', note: 'Found in the linen room' }),
  mv({ d: '2026-11-05', item: 'Toner cartridge', store: 1, type: 'adjustment', qty: -1, by: 'kofi', note: 'Damaged cartridge' }),

  // wastage
  mv({ d: '2026-10-22', item: 'Chicken', store: 2, type: 'wastage', qty: -12, dept: 'Kitchen', by: 'yaw', note: 'Spoiled in the walk-in' }),
  mv({ d: '2026-10-30', item: 'Laundry powder', store: 4, type: 'wastage', qty: -4, by: 'efua', note: 'Bag split' }),
  mv({ d: '2026-11-02', item: 'Rice', store: 1, type: 'wastage', qty: -25, by: 'ama', note: 'Weevils' }),
];

const LABELS = {
  opening: 'Opening', purchase: 'Purchase', issue: 'Issue', transfer_in: 'Transfer in', transfer_out: 'Transfer out',
  adjustment: 'Adjustment', wastage: 'Wastage', consumption: 'Consumption',
};

export function ledgerContext({ drift = false } = {}) {
  const QUERY = {};

  const moves = MOVES.concat(drift ? [
    // the late receipt: the engine oil that was issued before it was ever received
    mv({ d: '2026-11-07', item: 'Engine oil', store: 3, type: 'purchase', qty: 24, ref: 'GRN-2026-0368', by: 'kofi' }),
  ] : []).map(m => ({ ...m }));

  // the rice wastage stops being a gap the moment somebody raises the form for it
  if (drift) {
    const waste = moves.find(m => m.item.name === 'Rice' && m.type === 'wastage');
    waste.reference = 'WS-2026-0091';
    waste.department = 'Kitchen';
  }

  const money = (m) => Math.abs(Number(m.qty) * Number(m.rate));
  const rows = moves.slice().sort((a, b) => +b.date - +a.date);

  /* ── the flow ─────────────────────────────────────────────────────────── */
  const ins = moves.filter(m => m.qty > 0);
  const outs = moves.filter(m => m.qty < 0);
  const valueIn = round2(ins.reduce((s, m) => s + money(m), 0));
  const valueOut = round2(outs.reduce((s, m) => s + money(m), 0));
  const unitsIn = round2(ins.reduce((s, m) => s + Number(m.qty), 0));
  const unitsOut = round2(outs.reduce((s, m) => s + Math.abs(Number(m.qty)), 0));

  const flowRows = Object.keys(LABELS).map(type => {
    const set = moves.filter(m => m.type === type);
    if (!set.length) return null;
    const i = set.filter(m => m.qty > 0);
    const o = set.filter(m => m.qty < 0);
    const vi = round2(i.reduce((s, m) => s + money(m), 0));
    const vo = round2(o.reduce((s, m) => s + money(m), 0));

    return { type, label: LABELS[type], tone: ['purchase', 'opening', 'transfer_in'].includes(type) ? 'green' : 'amber',
      count: set.length, units_in: round2(i.reduce((s, m) => s + Number(m.qty), 0)),
      units_out: round2(o.reduce((s, m) => s + Math.abs(Number(m.qty)), 0)), value_in: vi, value_out: vo, net: round2(vi - vo) };
  }).filter(Boolean).sort((a, b) => (Math.abs(b.value_in) + Math.abs(b.value_out)) - (Math.abs(a.value_in) + Math.abs(a.value_out)));

  const big = moves.slice().sort((a, b) => money(b) - money(a))[0];
  const flow = {
    count: moves.length, in_count: ins.length, out_count: outs.length, units_in: unitsIn, units_out: unitsOut,
    value_in: valueIn, value_out: valueOut, net: round2(valueIn - valueOut), rows: flowRows,
    biggest: { date: big.date, item: big.item.name, unit: big.item.unit, store: big.store.name, type: LABELS[big.type],
      qty: Math.abs(big.qty), qty_label: Number(Math.abs(big.qty)).toLocaleString('en-US'),
      rate: big.rate, value: round2(money(big)), inward: big.qty > 0 },
  };
  flow.net_tone = flow.net < 0 ? 'red' : 'green';
  flow.sub = `${flow.in_count} in · ${flow.out_count} out over the window`;

  /* ── the balance ──────────────────────────────────────────────────────── */
  const touch = [...new Set(moves.map(m => m.item))];
  const balanceRows = touch.map(i => {
    const set = moves.filter(m => m.item === i);
    const open = round2(i.opening);
    const inn = round2(set.filter(m => m.qty > 0).reduce((s, m) => s + Number(m.qty), 0));
    const out = round2(set.filter(m => m.qty < 0).reduce((s, m) => s + Math.abs(Number(m.qty)), 0));
    const closing = round2(open + inn - out);

    return { item: i.name, unit: i.unit, opening: open, in: inn, out, closing, rate: i.rate,
      value: round2(closing * i.rate), tone: closing < 0 ? 'text-red bold' : '' };
  }).sort((a, b) => a.value - b.value);

  const below = balanceRows.filter(r => r.closing < 0);
  const balance = {
    items: balanceRows.length,
    opening_value: round2(balanceRows.reduce((s, r) => s + r.opening * r.rate, 0)),
    closing_value: round2(balanceRows.reduce((s, r) => s + r.value, 0)),
    below: below.length, below_value: round2(below.reduce((s, r) => s + r.value, 0)),
    worst: below[0] ?? null, rows: balanceRows.slice(0, 12),
  };
  balance.net = round2(balance.closing_value - balance.opening_value);
  balance.tone = balance.below ? 'red' : 'green';
  balance.sub = `${balance.items} item${balance.items === 1 ? '' : 's'} rolled forward from opening to closing`;
  balance.below_sub = balance.below
    ? 'closed the window with a book quantity below zero — stock issued that was never received'
    : 'every item closed the window at or above zero';

  /* ── the gaps ─────────────────────────────────────────────────────────── */
  const gaps = moves.filter(m => m.qty < 0 && !m.reference && !m.department);
  const adjust = moves.filter(m => m.type === 'adjustment');
  const waste = moves.filter(m => m.type === 'wastage');
  const worth = round2(gaps.reduce((s, m) => s + money(m), 0));
  const worstGap = gaps.slice().sort((a, b) => money(b) - money(a))[0];
  const byUser = {};
  gaps.forEach(m => {
    const name = m.user ? m.user.username.charAt(0).toUpperCase() + m.user.username.slice(1) : 'Nobody signed';
    byUser[name] = byUser[name] ?? { name, count: 0, value: 0 };
    byUser[name].count++;
    byUser[name].value = round2(byUser[name].value + money(m));
  });

  const gap = {
    count: gaps.length, value: worth,
    adjust: adjust.length, adjust_value: round2(adjust.reduce((s, m) => s + money(m), 0)),
    waste: waste.length, waste_value: round2(waste.reduce((s, m) => s + money(m), 0)),
    share: valueOut > 0 ? Math.round(worth / valueOut * 1000) / 10 : null,
    worst: worstGap ? { date: worstGap.date, item: worstGap.item.name, unit: worstGap.item.unit, type: LABELS[worstGap.type],
      qty: Math.abs(worstGap.qty), value: round2(money(worstGap)), remarks: worstGap.remarks,
      user: worstGap.user ? worstGap.user.username.charAt(0).toUpperCase() + worstGap.user.username.slice(1) : null } : null,
    by: Object.values(byUser).sort((a, b) => b.value - a.value),
    rows: gaps.slice().sort((a, b) => +b.date - +a.date).slice(0, 10).map(m => ({
      date: m.date, item: m.item.name, unit: m.item.unit, type: LABELS[m.type], qty: Math.abs(m.qty),
      store: m.store.name, value: round2(money(m)),
      user: m.user ? m.user.username.charAt(0).toUpperCase() + m.user.username.slice(1) : '—' })),
  };
  gap.share_label = `${gap.share} % of the value that left the store`;
  gap.adjust_sub = `${gap.adjust} movement${gap.adjust === 1 ? '' : 's'}, GH₵ ${gap.adjust_value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} in all`;
  gap.tone = gap.count ? 'amber' : 'green';

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
      ucfirst: value => String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1),
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
      moves: new Pager(rows), from: '2026-10-09', to: '2026-11-07',
      items: Object.fromEntries(ITEMS.map(i => [i.id, i.name])), stores: STORES,
      flow, balance, gaps: gap, currentHotel: HOTEL,
    },
  };
}
