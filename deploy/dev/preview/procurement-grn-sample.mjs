/**
 * Sample data for the goods receipts preview — resources/views/procurement/grns.blade.php rendered the way
 * ProcurementController::grns() hands it over: the page of receipts, the supplier list, and the three readings the
 * round adds.
 *
 * A receipt is not an opinion about money. It is a claim on Accounts Payable from the day the goods are accepted, and
 * the three questions below are answered from other documents rather than from the receipt's own columns:
 *
 *   • the bill — the purchase order's own bill relation, the one ApService::unbilledReceipts() tests when it builds the
 *     "goods received, not yet invoiced" list on the bill screen. Six receipts on record, six bills: 72,190.00 of goods
 *     that are on the shelf and on the payables ledger. The longest wait was the Sahel linen run, billed four days after
 *     it arrived.
 *   • the delivery — the purchase order's expected date against the day the goods came. Two of the five receipts in the
 *     window are late, 20,480.00 of the 65,040.00 accepted, and the worst is three days on a beverage delivery.
 *   • the shelf — the stock movement the receipt wrote, found again by GRN number and valued at qty × rate. The net goods
 *     value is 65,040.00 and the movements are 65,040.00, so every accepted line is where the paperwork says it is.
 *
 * The drift build is a month in which two of those readings break: two bills have been left as drafts, so 20,480.00 of
 * goods is on the shelf and nowhere on the ledger, and one receipt's stock movement is missing, so the shelf is 5,360.00
 * short of what was signed for.
 *
 * "Today" is 7 November 2026; the window is the month to date, 1–7 November.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

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

const supplier = (id, name) => ({ id, name });
const ACCRA = supplier(9, 'Accra Fresh Foods');
const COASTAL = supplier(7, 'Coastal Beverages Ltd');
const SAHEL = supplier(4, 'Sahel Textiles');
const VOLTA = supplier(12, 'Volta Power Systems');
const TEMA = supplier(15, 'Tema Hardware');

/* PurchaseOrder: the bill relation is the one the bill screen's unbilled list tests, so it is what "billed" means here. */
const po = (o) => ({
  po_no: o.no, expected_date: day(o.expected), status: o.status, supplier: o.supplier,
  bill: o.bill ? { doc_no: o.bill.doc, doc_date: day(o.bill.date), status: o.bill.status } : null,
});

const P = {
  '0311': po({ no: 'PO-2026-0311', expected: '2026-11-06', status: 'received', supplier: ACCRA, bill: { doc: 'INV-2026-0661', date: '2026-11-07', status: 'open' } }),
  '0308': po({ no: 'PO-2026-0308', expected: '2026-11-03', status: 'received', supplier: COASTAL, bill: { doc: 'INV-2026-0658', date: '2026-11-07', status: 'open' } }),
  '0305': po({ no: 'PO-2026-0305', expected: '2026-11-04', status: 'received', supplier: TEMA, bill: { doc: 'INV-2026-0649', date: '2026-11-05', status: 'open' } }),
  '0301': po({ no: 'PO-2026-0301', expected: '2026-11-02', status: 'received', supplier: SAHEL, bill: { doc: 'INV-2026-0654', date: '2026-11-06', status: 'paid' } }),
  '0298': po({ no: 'PO-2026-0298', expected: '2026-11-01', status: 'received', supplier: VOLTA, bill: { doc: 'INV-2026-0648', date: '2026-11-03', status: 'partial' } }),
  '0296': po({ no: 'PO-2026-0296', expected: '2026-10-20', status: 'partial', supplier: COASTAL, bill: { doc: 'INV-2026-0621', date: '2026-10-24', status: 'open' } }),
};

const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;
const iso = (d) => (typeof d?.toDateString === 'function' ? d.toDateString().slice(0, 10) : String(d).slice(0, 10));
const daysBetween = (a, b) => Math.round((Date.parse(`${iso(a)}T00:00:00Z`) - Date.parse(`${iso(b)}T00:00:00Z`)) / 86400000);
const ageDays = (d) => Math.max(0, daysBetween('2026-11-07', d));

/* GoodsReceipt: onTime() is the PO's expected date against the day the goods arrived, as the model has it. */
const grn = (o) => {
  const g = {
    id: o.id, grn_no: o.no, date: day(o.date), purchase_order_id: o.po.id ?? null, order: null,
    supplier: o.supplier, store: { name: o.store }, delivery_note: o.dn,
    subtotal: o.subtotal, tax: o.tax ?? 0, total: round2(o.subtotal + (o.tax ?? 0)),
    rejected_value: o.rejected ?? 0, receiver: { name: o.by }, moved: o.moved,
  };
  g.onTime = () => !o.po.expected_date || iso(g.date) <= iso(o.po.expected_date);

  return { ...g, order: o.po };
};

const RECEIPTS = [
  grn({ id: 184, no: 'GRN-2026-0184', date: '2026-11-07', po: P['0311'], supplier: ACCRA, store: 'Main Store', dn: 'DN-4412',
    subtotal: 12480, rejected: 0, by: 'Ama Boateng', moved: 12480 }),
  grn({ id: 183, no: 'GRN-2026-0183', date: '2026-11-06', po: P['0308'], supplier: COASTAL, store: 'Beverage Store', dn: 'DN-2288',
    subtotal: 8000, tax: 1200, rejected: 620, by: 'Ama Boateng', moved: 8000 }),
  grn({ id: 181, no: 'GRN-2026-0181', date: '2026-11-04', po: P['0305'], supplier: TEMA, store: 'Engineering Store', dn: 'DN-0917',
    subtotal: 5360, rejected: 0, by: 'Kofi Mensah', moved: 5360 }),
  grn({ id: 178, no: 'GRN-2026-0178', date: '2026-11-02', po: P['0301'], supplier: SAHEL, store: 'Linen Store', dn: 'DN-3345',
    subtotal: 16400, rejected: 1240, by: 'Kofi Mensah', moved: 16400 }),
  grn({ id: 176, no: 'GRN-2026-0176', date: '2026-11-01', po: P['0298'], supplier: VOLTA, store: 'Fuel Store', dn: 'DN-7702',
    subtotal: 22800, rejected: 0, by: 'Yaw Antwi', moved: 22800 }),
  // the one that will not fit the month window, so the bill card has to read every date to find it
  grn({ id: 169, no: 'GRN-2026-0169', date: '2026-10-22', po: P['0296'], supplier: COASTAL, store: 'Beverage Store', dn: 'DN-2214',
    subtotal: 7150, rejected: 0, by: 'Yaw Antwi', moved: 7150 }),
];

const SUPPLIERS = { 9: 'Accra Fresh Foods', 7: 'Coastal Beverages Ltd', 4: 'Sahel Textiles', 12: 'Volta Power Systems', 15: 'Tema Hardware' };

/* ProcurementService::overdueDeliveries(): approved POs past their expected date with nothing received. */
const overdue = (state) => [
  { po_no: 'PO-2026-0288', supplier: SAHEL, status: 'approved', expected_date: day('2026-10-30') },
];

export function grnRegisterContext({ drift = false, supplierId = null, from = null, to = null, rejections = false, waiting = false, page = null } = {}) {
  const FROM = from || '2026-11-01';
  const TO = to || '2026-11-07';
  const QUERY = { from: from || null, to: to || null, supplier: supplierId || null, rejections: rejections || null, waiting: waiting || null, page: null };

  // the drill: two bills left as drafts, so their receipts are unbilled again, and one stock movement missing
  const BILLED = ['open', 'partial', 'paid'];
  const rows = RECEIPTS.map((g) => {
    const bill = drift && (g.grn_no === 'GRN-2026-0184' || g.grn_no === 'GRN-2026-0183')
      ? { doc_no: g.order.bill.doc_no, doc_date: g.order.bill.doc_date, status: 'draft' }
      : g.order.bill;
    const moved = drift && g.grn_no === 'GRN-2026-0181' ? 0 : g.moved;

    return { ...g, order: { ...g.order, bill }, moved };
  });
  const isBilled = (g) => Boolean(g.order.bill) && BILLED.includes(g.order.bill.status);

  const period = rows.filter(g => iso(g.date) >= FROM && iso(g.date) <= TO && (!supplierId || g.supplier.id === Number(supplierId)));

  /* 1. the bill — every receipt on record, not only the window */
  const stuck = rows.filter(g => g.subtotal > 0 && !isBilled(g))
    .map(g => ({ grn_no: g.grn_no, supplier: g.supplier.name, store: g.store.name, date: g.date, days: ageDays(g.date), value: round2(g.subtotal) }))
    .sort((a, b) => (a.date === b.date ? 0 : (iso(a.date) < iso(b.date) ? -1 : 1)));
  const settled = rows.filter(g => g.subtotal > 0 && isBilled(g) && iso(g.date) >= '2026-08-09')
    .map(g => ({ grn_no: g.grn_no, supplier: g.supplier.name, date: g.date, value: round2(g.subtotal),
      bill: g.order.bill.doc_no, bill_date: g.order.bill.doc_date, wait: Math.max(0, daysBetween(g.order.bill.doc_date, g.date)) }));
  const slowest = [...settled].sort((a, b) => b.wait - a.wait)[0] ?? null;
  const oldestRow = [...stuck].sort((a, b) => b.days - a.days)[0] ?? null;
  const billing = {
    waiting: stuck.length, waiting_value: round2(stuck.reduce((t, r) => t + r.value, 0)),
    oldest: stuck.length ? Math.max(...stuck.map(r => r.days)) : 0, oldest_row: oldestRow,
    billed: settled.length, billed_value: round2(settled.reduce((t, r) => t + r.value, 0)), slowest,
    rows: [...stuck].sort((a, b) => b.days - a.days).slice(0, 10),
  };

  /* 2. the delivery — the PO's expected date against the day the goods came, valued on the goods, not the tax */
  const lateRows = period.filter(g => !g.onTime())
    .map(g => ({ grn_no: g.grn_no, supplier: g.supplier.name, date: g.date, expected: g.order.expected_date,
      days: Math.max(0, daysBetween(g.date, g.order.expected_date)), value: round2(g.subtotal) }))
    .sort((a, b) => b.days - a.days);
  const goods = round2(period.reduce((t, g) => t + g.subtotal, 0));
  const lateValue = round2(lateRows.reduce((t, r) => t + r.value, 0));
  const late = {
    receipts: period.length, value: goods, late: lateRows.length, late_value: lateValue,
    share: goods > 0 ? Math.round(100 * lateValue / goods) : 0, worst: lateRows[0] ?? null,
    rows: lateRows.slice(0, 10), overdue: overdue().length,
  };

  /* 3. the shelf — the accepted goods value against the movements the receipts wrote */
  const stockRows = period.map(g => ({ grn_no: g.grn_no, supplier: g.supplier.name, store: g.store.name, date: g.date,
    value: round2(g.subtotal), moved: round2(g.moved) }))
    .map(r => ({ ...r, diff: round2(r.value - r.moved) }));
  const gaps = stockRows.filter(r => Math.abs(r.diff) > 0.005);
  const stock = {
    receipts: stockRows.length, goods: round2(stockRows.reduce((t, r) => t + r.value, 0)),
    moved: round2(stockRows.reduce((t, r) => t + r.moved, 0)), diff: round2(stockRows.reduce((t, r) => t + r.diff, 0)),
    breaks: gaps.length, rows: gaps.slice(0, 10),
    largest: [...stockRows].sort((a, b) => b.value - a.value)[0] ?? null,
  };

  /* the page of receipts: same filters as the query, newest first */
  let table = period.filter(g => (rejections ? Number(g.rejected_value) > 0 : true) && (waiting ? !isBilled(g) : true));
  table = [...table].sort((a, b) => (iso(a.date) === iso(b.date) ? b.id - a.id : (iso(a.date) < iso(b.date) ? 1 : -1)));
  const paged = table.map(g => ({
    ...g, bill: isBilled(g) ? g.order.bill : null,
    waiting_days: isBilled(g) ? null : ageDays(g.date),
    stock_value: round2(g.moved), stocked: Math.abs(round2(g.moved) - Number(g.subtotal)) <= 0.005,
  }));

  const tot = {
    n: period.length,
    v: round2(period.reduce((t, g) => t + Number(g.total), 0)),
    r: round2(period.reduce((t, g) => t + Number(g.rejected_value), 0)),
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
      status_badge: (value) => {
        const map = { approved: 'blue', partial: 'amber', received: 'green', closed: 'slate', draft: 'amber', cancelled: 'red' };
        const label = String(value ?? '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        return `<span class="badge badge-${map[value] ?? 'slate'}">${label}</span>`;
      },
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? v.grn_no ?? v.po_no ?? '') : String(v));
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
        ? { except: (...skip) => Object.fromEntries(Object.entries(QUERY).filter(([k, v]) => v !== null && v !== '' && v !== false && !skip.includes(k))) }
        : (QUERY[key] ?? null)),
      setting: (key, fallback = null) => fallback,
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
      grns: new Pager(paged),
      from: FROM, to: TO,
      suppliers: SUPPLIERS,
      overdue: new Coll(overdue()),
      tot,
      as_of: '2026-11-07',
      billing, late, stock,
      currentHotel: HOTEL,
    },
  };
}
