/**
 * Sample data for the purchase order preview — resources/views/inventory/purchases.blade.php rendered the way
 * PurchaseController::index() hands it over: the page of orders, and the three readings the round adds.
 *
 * An order is three things at once, and none of them is a column the order holds:
 *
 *   • a commitment — approved or part-received with goods still to arrive. Three orders, 52,750.00, of which two are past
 *     the date they were given: the Coastal delivery 18 days over with 2,650.00 still to come, and the Sahel linen order
 *     8 days over with the whole 18,600.00 outstanding. Nothing is on the ledger for either.
 *   • a gate — four drafts totalling 85,350.00, one of them at each approval level and one raised by the person reading
 *     the screen, which segregation of duties stops them signing. The limits are the defaults: 5,000 at level 1, 25,000
 *     at level 2, senior management above that.
 *   • a match — six orders whose goods the shelf took in, 73,390.00, and six bills for the same 73,390.00. The drift build
 *     is the month that match fails: the Volta fuel bill is 500.00 short of its receipt and the Coastal delivery was billed
 *     for its full 9,800.00 when only 7,150.00 arrived — one direction each, 2,150.00 apart in all.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;
const iso = (d) => (typeof d?.toDateString === 'function' ? d.toDateString().slice(0, 10) : String(d).slice(0, 10));
const daysBetween = (a, b) => Math.round((Date.parse(`${iso(a)}T00:00:00Z`) - Date.parse(`${iso(b)}T00:00:00Z`)) / 86400000);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* Laravel hands the view a paginator: countable and iterable, with links() at the foot. */
class Pager extends Coll {
  links() {
    return raw('<nav class="pager" aria-label="Pages"><span class="current">1</span> <a href="?page=2">2</a></nav>');
  }
}

const L1 = 5000;
const L2 = 25000;
const SEGREGATION = true;
const LEVEL_LABEL = { 1: 'Store (level 1)', 2: 'Purchasing manager (level 2)', 3: 'Senior management (level 3)' };

const ORDER = [
  // received and billed — the six the match card reads
  { id: 311, no: 'PO-2026-0311', date: '2026-11-05', supplier: 'Accra Fresh Foods', store: 'Main Store', expected: '2026-11-06', total: 12480, status: 'received', received: 12480, bill: { doc: 'INV-2026-0661', total: 12480, status: 'open' }, by: 'Ama Boateng' },
  { id: 308, no: 'PO-2026-0308', date: '2026-11-03', supplier: 'Coastal Beverages Ltd', store: 'Beverage Store', expected: '2026-11-03', total: 9200, status: 'received', received: 9200, bill: { doc: 'INV-2026-0658', total: 9200, status: 'open' }, by: 'Ama Boateng' },
  { id: 305, no: 'PO-2026-0305', date: '2026-11-02', supplier: 'Tema Hardware', store: 'Engineering Store', expected: '2026-11-04', total: 5360, status: 'received', received: 5360, bill: { doc: 'INV-2026-0649', total: 5360, status: 'open' }, by: 'Kofi Mensah' },
  { id: 301, no: 'PO-2026-0301', date: '2026-10-30', supplier: 'Sahel Textiles', store: 'Linen Store', expected: '2026-11-02', total: 16400, status: 'received', received: 16400, bill: { doc: 'INV-2026-0654', total: 16400, status: 'paid' }, by: 'Kofi Mensah' },
  { id: 298, no: 'PO-2026-0298', date: '2026-10-28', supplier: 'Volta Power Systems', store: 'Fuel Store', expected: '2026-11-01', total: 22800, status: 'received', received: 22800, bill: { doc: 'INV-2026-0648', total: 22800, status: 'partial' }, by: 'Yaw Antwi' },
  // part received: the shelf took 7,150 of the 9,800 ordered, and the bill is for what arrived
  { id: 296, no: 'PO-2026-0296', date: '2026-10-20', supplier: 'Coastal Beverages Ltd', store: 'Beverage Store', expected: '2026-10-20', total: 9800, status: 'partial', received: 7150, bill: { doc: 'INV-2026-0621', total: 7150, status: 'open' }, by: 'Ama Boateng' },
  // approved, nothing arrived — the commitment, one of them overdue
  { id: 288, no: 'PO-2026-0288', date: '2026-10-22', supplier: 'Sahel Textiles', store: 'Linen Store', expected: '2026-10-30', total: 18600, status: 'approved', received: 0, bill: null, by: 'Kofi Mensah' },
  { id: 314, no: 'PO-2026-0314', date: '2026-11-06', supplier: 'Volta Power Systems', store: 'Fuel Store', expected: '2026-11-20', total: 31500, status: 'approved', received: 0, bill: null, by: 'Yaw Antwi' },
  // drafts — one at each level, and one raised by the person reading the screen
  { id: 315, no: 'PO-2026-0315', date: '2026-11-07', supplier: 'Tema Hardware', store: 'Engineering Store', expected: '2026-11-18', total: 4250, status: 'draft', received: 0, bill: null, by: 'Ama Boateng' },
  { id: 316, no: 'PO-2026-0316', date: '2026-11-07', supplier: 'Accra Fresh Foods', store: 'Main Store', expected: '2026-11-21', total: 14800, status: 'draft', received: 0, bill: null, by: 'Kofi Mensah' },
  { id: 317, no: 'PO-2026-0317', date: '2026-11-07', supplier: 'Coastal Beverages Ltd', store: 'Beverage Store', expected: '2026-11-24', total: 62400, status: 'draft', received: 0, bill: null, by: 'Yaw Antwi' },
  { id: 318, no: 'PO-2026-0318', date: '2026-11-07', supplier: 'Sahel Textiles', store: 'Linen Store', expected: '2026-11-28', total: 3900, status: 'draft', received: 0, bill: null, by: 'property administrator' },
];

const order = (o, drift) => {
  let bill = o.bill ? { doc_no: o.bill.doc, total: o.bill.total, status: o.bill.status } : null;
  if (drift && o.no === 'PO-2026-0298') bill = { ...bill, total: 22300 };   // 500.00 of the receipt never invoiced
  if (drift && o.no === 'PO-2026-0296') bill = { ...bill, total: 9800 };    // billed for the whole order, 2,650.00 never received
  const p = {
    id: o.id, po_no: o.no, po_date: day(o.date), expected_date: day(o.expected), status: o.status,
    total: o.total, received: o.received, supplier: { name: o.supplier }, store: { name: o.store },
    rfq_id: null, items: [{ aboveList: () => false }], bill, currency: null, fx_rate: null, fx_total: null,
    isForeign: () => false,
    fxLabel: () => '',
    created_by_name: o.by,
  };
  p.isOverdue = () => ['approved', 'partial'].includes(p.status) && iso(p.expected_date) < '2026-11-07';
  p.receipt_value = round2(o.received);
  p.bill_total = round2(bill ? bill.total : 0);
  p.open_value = round2(o.total - o.received);

  return p;
};

export function purchasesContext({ drift = false, status = null, late = false, page = null } = {}) {
  const QUERY = { status: status || null, late: late || null, page: null };
  const ALL = ORDER.map(o => order(o, drift));
  const levelOf = (p) => (p.total <= L1 ? 1 : (p.total <= L2 ? 2 : 3));
  const blockerOf = (p) => {
    if (p.status !== 'draft') return 'Only draft purchase orders can be approved.';
    if (SEGREGATION && p.created_by_name === 'property administrator') {
      return 'Segregation of duties is on: the person who raised a PO cannot approve it.';
    }

    return null;
  };

  /* 1. the commitment */
  const openRows = ALL.filter(p => ['approved', 'partial'].includes(p.status) && p.open_value > 0.005)
    .map(p => ({ po_no: p.po_no, supplier: p.supplier.name, store: p.store.name, expected: p.expected_date,
      total: round2(p.total), received: round2(p.received), open: round2(p.open_value),
      late: p.expected_date && iso(p.expected_date) < '2026-11-07' ? Math.max(0, daysBetween('2026-11-07', p.expected_date)) : 0 }));
  const lateRows = openRows.filter(r => r.late > 0).sort((a, b) => b.late - a.late);
  const commit = {
    orders: openRows.length, value: round2(openRows.reduce((t, r) => t + r.open, 0)),
    late: lateRows.length, late_value: round2(lateRows.reduce((t, r) => t + r.open, 0)),
    worst: lateRows[0] ?? null,
    soonest: openRows.filter(r => r.late === 0 && r.expected).sort((a, b) => (iso(a.expected) < iso(b.expected) ? -1 : 1))[0] ?? null,
    rows: [...openRows].sort((a, b) => b.open - a.open).slice(0, 10),
  };

  /* 2. the gate */
  const drafts = ALL.filter(p => p.status === 'draft' && p.total > 0);
  const levels = [1, 2, 3].map(level => {
    const set = drafts.filter(p => levelOf(p) === level).sort((a, b) => b.total - a.total);
    return { level, label: LEVEL_LABEL[level], count: set.length, value: round2(set.reduce((t, p) => t + p.total, 0)),
      largest: set[0]?.po_no ?? null, largest_value: round2(set[0]?.total ?? 0) };
  });
  const blockedRows = drafts.filter(p => blockerOf(p) !== null)
    .map(p => ({ po_no: p.po_no, supplier: p.supplier.name, total: round2(p.total), why: blockerOf(p) }));
  const approvable = drafts.filter(p => blockerOf(p) === null);
  const largestApprovable = [...approvable].sort((a, b) => b.total - a.total)[0] ?? null;
  const gate = {
    drafts: drafts.length, value: round2(drafts.reduce((t, p) => t + p.total, 0)), levels,
    approvable: approvable.length, approvable_value: round2(approvable.reduce((t, p) => t + p.total, 0)),
    largest_approvable: largestApprovable ? { po_no: largestApprovable.po_no, total: largestApprovable.total } : null,
    blocked: blockedRows.length, blocked_value: round2(blockedRows.reduce((t, r) => t + r.total, 0)),
    blocked_rows: blockedRows.slice(0, 10), user_level: 3, limits: [L1, L2], segregation: SEGREGATION,
  };

  /* 3. the match */
  const matchRows = ALL.filter(p => ['received', 'partial', 'closed'].includes(p.status) && p.receipt_value > 0.005)
    .map(p => ({ po_no: p.po_no, supplier: p.supplier.name, store: p.store.name, status: p.status,
      ordered: round2(p.total), received: round2(p.receipt_value), billed: round2(p.bill_total),
      bill_no: p.bill?.doc_no ?? null, diff: round2(p.receipt_value - p.bill_total) }));
  const gaps = matchRows.filter(r => Math.abs(r.diff) > 0.005).sort((a, b) => Math.abs(b.diff) - Math.abs(a.diff));
  const match = {
    orders: matchRows.length, received: round2(matchRows.reduce((t, r) => t + r.received, 0)),
    billed: round2(matchRows.reduce((t, r) => t + r.billed, 0)), diff: round2(matchRows.reduce((t, r) => t + r.diff, 0)),
    breaks: gaps.length, rows: gaps.slice(0, 10), unbilled: matchRows.filter(r => r.billed <= 0.005).length,
    largest: [...matchRows].sort((a, b) => b.received - a.received)[0] ?? null,
  };

  /* the page of orders */
  let table = ALL.filter(p => (status ? p.status === status : true)
    && (late ? ['approved', 'partial'].includes(p.status) && p.expected_date && iso(p.expected_date) < '2026-11-07' : true));
  table = [...table].sort((a, b) => (iso(a.po_date) === iso(b.po_date) ? b.id - a.id : (iso(a.po_date) < iso(b.po_date) ? 1 : -1)));

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
      // x-field reads old($name, $value) before it prints: there is no session in a preview, so the value is the value
      old: (name, value = null) => value,
      status_badge: (value) => {
        const map = { draft: 'amber', approved: 'blue', partial: 'amber', received: 'green', closed: 'slate', cancelled: 'red' };
        const label = String(value ?? '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        return `<span class="badge badge-${map[value] ?? 'slate'}">${label}</span>`;
      },
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? v.po_no ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) {
          if (Array.isArray(params)) tail = params.map(part).join('-');
          else tail = part(params);
        }
        return `#${String(name).replace(/\./g, '-')}${tail ? '-' + tail : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      request: (key) => (key === undefined
        ? { boolean: (k) => Boolean(QUERY[k]), except: (...skip) => Object.fromEntries(Object.entries(QUERY).filter(([k, v]) => v !== null && v !== '' && v !== false && !skip.includes(k))) }
        : (QUERY[key] ?? null)),
      setting: (key, fallback = null) => (key === 'po_segregation' ? (SEGREGATION ? '1' : '0') : (key === 'po_limit_1' ? String(L1) : (key === 'po_limit_2' ? String(L2) : fallback))),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      orders: new Pager(table),
      status: status || null,
      as_of: '2026-11-07',
      commit, gate, match,
      mine: new Coll(approvable),
      late_pos: new Coll(ALL.filter(p => p.isOverdue())),
      limits: [L1, L2],
      segregation: SEGREGATION,
      suppliers: { 9: 'Accra Fresh Foods', 7: 'Coastal Beverages Ltd', 4: 'Sahel Textiles', 12: 'Volta Power Systems', 15: 'Tema Hardware' },
      currentHotel: HOTEL,
    },
  };
}
