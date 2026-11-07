/**
 * Sample data for the purchase order document — resources/views/inventory/po-show.blade.php rendered the way
 * PurchaseController::show() hands it over: the order with its supplier, its lines, the deliveries that came through the
 * gate, the supplier bill matched to it, and the v9.26 readings computed from those three.
 *
 * PO-2026-0302 is Coastal Beverages' beverage run for the month: four lines, 8,579.00 with VAT, into the Beverage Store,
 * expected on 28 October. It is part delivered and eight days past its expected date — the shape of order where every
 * figure on this screen is contested by somebody.
 *
 *   • the order — 7,460.00 net, 1,119.00 VAT, 8,579.00 total. One line is priced above the supplier's agreed rate:
 *     Coca-Cola at 38.00 a crate against 36.50 agreed, 30.00 over on twenty crates. That is the only thing on the order
 *     a buyer has to explain, so it is the one line the rate card points at.
 *   • the gate — two deliveries. GRN-2026-0043 came on 28 October, the day it was expected, with 4,940.00 of goods.
 *     GRN-2026-0051 came on 3 November, six days late, with 1,552.00: two crates of Club Beer were refused as broken and
 *     never entered the store. Accepted to date 6,492.00 net, 7,465.80 with VAT.
 *   • the money — the supplier's bill CBL-2026-0629 is for exactly what arrived, 7,465.80, and is still open. So the
 *     three-way match closes: nothing has been charged for that the store did not receive. What is left is 1,113.20 of
 *     goods still owed on 22 crates of Club Beer — 20 never delivered, 2 refused — which is the figure the header calls
 *     "still owed".
 *
 * The drift build is the same order with the bill entered for the whole of it, 8,579.00: the supplier has been charged
 * 1,113.20 for beer that never came, and the match card turns red. That is the exact failure the screen exists to catch,
 * and it is the same break the purchase-order list counts as its own drift build.
 *
 * "Today" is 7 November 2026. Every figure below is computed from the lines, not typed twice.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const SUPPLIER = {
  id: 7, name: 'Coastal Beverages Ltd', contact_person: 'Esi Danquah', phone: '030 221 4478',
  tax_number: 'C0012345678', payment_terms_days: 30,
};

const STORE = { id: 5, name: 'Beverage Store' };

/* ── the order's four lines ─────────────────────────────────────────────── */
const LINES = [
  { id: 41, name: 'Club Beer 330ml (crate of 24)', unit: 'crate', qty: 100, rate: 44.00, list: 44.00, tax: 15 },
  { id: 42, name: 'Malta Guinness (crate of 24)', unit: 'crate', qty: 30, rate: 40.00, list: 40.00, tax: 15 },
  { id: 43, name: 'Voltic Water 1.5L (pack of 12)', unit: 'pack', qty: 50, rate: 22.00, list: 22.00, tax: 15 },
  { id: 44, name: 'Coca-Cola 300ml (crate of 24)', unit: 'crate', qty: 20, rate: 38.00, list: 36.50, tax: 15 },
];

/* what the gate accepted, by line: GRN-0043 on 28 Oct, GRN-0051 on 3 Nov */
const RECEIVED = { 41: { first: 60, second: 18 }, 42: { first: 30, second: 0 }, 43: { first: 50, second: 0 }, 44: { first: 0, second: 20 } };
const REJECTED = { 41: 2, 42: 0, 43: 0, 44: 0 };
const REJECT_REASON = 'broken crates';

const round2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
/* the same figure money() prints, for the sentences the controller builds in PHP */
const cash = (v) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const accepted = (l) => (RECEIVED[l.id].first + RECEIVED[l.id].second);
const outstandingQty = (l) => Math.max(0, round2(l.qty - accepted(l)));

/* PurchaseOrderItem, priced the way the order priced it */
const orderLine = (l) => {
  const received = accepted(l);
  const amount = round2(l.qty * l.rate);
  const over = l.list === null ? 0 : round2((l.rate - l.list) * l.qty);

  return {
    id: l.id, qty: l.qty, received_qty: received, rejected_qty: REJECTED[l.id],
    rate: l.rate, list_rate: l.list, tax_rate: l.tax, amount,
    fx_unit_rate: 0, fx_amount: 0,
    item: { id: l.id, name: l.name, unit: l.unit },
    outstanding: () => Math.max(0, round2(l.qty - received)),
    aboveList: () => l.list !== null && l.rate > round2(l.list * 1.02) + 0.004,
    __over: over,
  };
};

const ORDER_LINES = LINES.map(orderLine);
const SUBTOTAL = round2(ORDER_LINES.reduce((t, l) => t + l.amount, 0));
const TAX = round2(ORDER_LINES.reduce((t, l) => t + round2(l.amount * l.tax_rate / 100), 0));
const TOTAL = round2(SUBTOTAL + TAX);

/* ── the two deliveries ─────────────────────────────────────────────────── */
const GRN_LINES = (grn) => new Coll(LINES.filter(l => RECEIVED[l.id][grn] > 0 || (grn === 'second' && REJECTED[l.id] > 0)).map(l => {
  const qty = RECEIVED[l.id][grn];
  const rejected = grn === 'second' ? REJECTED[l.id] : 0;

  return {
    id: `${l.id}-${grn}`, qty_accepted: qty, qty_rejected: rejected,
    reject_reason: rejected ? REJECT_REASON : null,
    rate: l.rate, item: { id: l.id, name: l.name, unit: l.unit },
  };
}));

const grn = (o) => {
  const lines = GRN_LINES(o.slot);
  const net = round2(lines.items.reduce((t, l) => t + l.qty_accepted * l.rate, 0));
  const tax = round2(net * 0.15);
  const rejectedValue = round2(lines.items.reduce((t, l) => t + l.qty_rejected * l.rate, 0) * 1.15);

  return {
    id: o.id, grn_no: o.no, date: day(o.date), delivery_note: o.note, invoice_no: o.invoice ?? null,
    currency: null, fx_rate: null, fx_date: null, fx_subtotal: null, fx_tax: null, fx_total: null, fx_rejected_value: null,
    subtotal: net, tax, total: round2(net + tax), accepted_value: net,
    rejected_value: rejectedValue, lines,
    receiver: { name: o.by }, order: { expected_date: day('2026-10-28') },
    isForeign: () => false,
    fxLabel: () => `GHS ${Number(net + tax).toFixed(2)}`,
    onTime: () => o.date <= '2026-10-28',
  };
};

const RECEIPTS = new Coll([
  grn({ id: 43, slot: 'first', no: 'GRN-2026-0043', date: '2026-10-28', note: 'CBL/DN/00412', by: 'Yaw Antwi' }),
  grn({ id: 51, slot: 'second', no: 'GRN-2026-0051', date: '2026-11-03', note: 'CBL/DN/00467', invoice: 'CBL-2026-0629', by: 'Yaw Antwi' }),
]);

const arriveValue = (asOf) => round2(RECEIPTS.items.filter(g => !asOf || g.date.toDateString() <= asOf).reduce((t, g) => t + g.total, 0));

/* ── the supplier bill ──────────────────────────────────────────────────── */
const bill = (drift) => {
  const arrived = arriveValue();
  const total = drift ? TOTAL : arrived;
  const net = round2(total / 1.15);
  const tax = round2(total - net);

  return {
    id: 629, doc_no: 'CBL-2026-0629', supplier_ref: 'CBL/INV/21/0629', status: 'open', kind: 'po',
    doc_date: day('2026-11-04'), due_date: day('2026-12-04'), opening_date: null, cancelled_at: null, cancel_reason: null,
    currency: null, fx_rate: null, fx_date: null, fx_total: null, fx_subtotal: null, fx_tax: null,
    purchase_order_id: 302, subtotal: net, tax, total, allocated: 0, notes: null,
    isForeign: () => false,
    isOpening: () => false,
    isDebitNote: () => false,
    outstanding: () => total,
    variance: () => 0,
    fxDifference: () => 0,
    fxLabel: () => `GHS ${Number(total).toFixed(2)}`,
  };
};

export function poShowContext({ drift = false } = {}) {
  const BILL = bill(drift);

  const PO = {
    id: 302, po_no: 'PO-2026-0302', status: 'partial',
    po_date: day('2026-10-24'), expected_date: day('2026-10-28'), received_at: new SampleDate('2026-11-03T15:40:00Z'),
    approved_at: new SampleDate('2026-10-24T16:05:00Z'), approval_level: 2, approved_by: 4,
    closed_at: null, close_reason: null, received_by: null,
    currency: null, fx_rate: null, fx_date: null, fx_subtotal: null, fx_tax: null, fx_total: null,
    subtotal: SUBTOTAL, tax: TAX, total: TOTAL, invoice_no: null, notes: null,
    supplier_id: SUPPLIER.id, supplier: SUPPLIER, store: STORE, store_id: STORE.id, rfq: null,
    creator: { name: 'Ama Boateng' }, approver: { name: 'Kofi Mensah' },
    items: new Coll(ORDER_LINES), receipts: RECEIPTS, bill: BILL,
    isForeign: () => false,
    fxLabel: () => `GHS ${Number(TOTAL).toFixed(2)}`,
    receivedValue: (asOf) => arriveValue(asOf),
    receivedSplit: () => [0, 0],
    receivedForeignSplit: () => [0, 0],
    hasGrns: () => true,
    billable: () => false,
    isOverdue: () => true,
  };

  /* ── v9.26: the controller's readings, computed from the lines and the receipts ── */
  const ordered = TOTAL;
  const arrived = arriveValue();
  const billed = BILL.total;
  const gap = round2(arrived - billed);

  const lines = new Coll(ORDER_LINES.map(l => ({
    id: l.id, item: l.item.name, unit: l.item.unit,
    qty: l.qty, received: l.received_qty, rejected: l.rejected_qty, outstanding: l.outstanding(),
    rate: l.rate, outstanding_value: round2(l.outstanding() * l.rate),
    above: l.aboveList(), list_rate: l.list_rate, over: l.__over,
  })));

  const owedRows = lines.items.filter(r => r.outstanding > 0.005).sort((a, b) => b.outstanding_value - a.outstanding_value);
  const outstanding = {
    qty: round2(lines.items.reduce((t, r) => t + r.outstanding, 0)),
    value: round2(owedRows.reduce((t, r) => t + r.outstanding_value, 0)),
    lines: owedRows.length, rows: owedRows.slice(0, 5), top: owedRows[0], gross: round2(ordered - arrived),
    sub: `line${owedRows.length === 1 ? '' : 's'} still to come`,
  };
  outstanding.sub = `${outstanding.lines} ${outstanding.sub}`;

  const late = RECEIPTS.items.filter(g => !g.onTime());
  const reasons = RECEIPTS.items.flatMap(g => g.lines.items
    .filter(l => l.qty_rejected > 0)
    .map(l => ({ item: l.item.name, qty: l.qty_rejected, reason: l.reject_reason })));
  const delivery = {
    count: RECEIPTS.count(), late: late.length,
    first: RECEIPTS.items[0].date.toDateString(), last: RECEIPTS.items[RECEIPTS.count() - 1].date.toDateString(),
    accepted: arrived,
    rejected: round2(RECEIPTS.items.reduce((t, g) => t + g.rejected_value, 0)),
    worst_days: 6, worst_no: 'GRN-2026-0051', reasons,
    reject_sub: reasons.length ? reasons.map(r => `${r.qty} of ${r.item} — ${r.reason}`).join(', ') : 'nothing was refused at the gate',
    sub: `${late.length} of ${RECEIPTS.count()} arrived after the expected date`,
  };

  const match = {
    ordered, arrived, billed, gap,
    unbilled: gap > 0.005, overbilled: gap < -0.005,
    invoice: BILL.doc_no, invoice_status: BILL.status,
    rows: owedRows.slice(0, 6).map(r => ({ item: r.item, unit: r.unit, qty: r.outstanding, value: r.outstanding_value })),
  };
  if (match.overbilled) {
    match.tone = 'red';
    match.head = 'the supplier has billed for goods that never arrived';
    match.sub = `billed GH₵ ${cash(Math.abs(gap))} more than was received — the goods or the credit note is missing`;
  } else if (match.unbilled) {
    match.tone = 'amber';
    match.head = 'goods received, not yet invoiced';
    match.sub = `GH₵ ${cash(gap)} of what arrived is not on the payables ledger yet`;
  } else {
    match.tone = 'green';
    match.head = 'the bill matches what arrived';
    match.sub = 'nothing has been charged for that the store did not receive';
  }

  const above = lines.items.filter(r => r.above).sort((a, b) => b.over - a.over);
  const rate = { count: above.length, over: round2(above.reduce((t, r) => t + r.over, 0)), rows: above, top: above[0] };

  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  // Carbon::parse() takes a date object or the ISO string a toDateString() leaves behind
  const dmy = (value) => {
    if (value == null || value === '') return '—';
    const iso = typeof value?.toDateString === 'function' ? value.toDateString() : String(value).slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return String(value);

    return `${iso.slice(8, 10)} ${MONTHS[Number(iso.slice(5, 7)) - 1]} ${iso.slice(0, 4)}`;
  };

  return {
    scope: {
      /* the view's top @php block sits outside the extracted content section, so the three values it computes for the
         header and the action bar are supplied here exactly as it computes them */
      manage: true, canApprove: false, byGrn: true, aboveN: 1, home: 'GHS', currency: 'GHS',
      po: PO, level: 'manager', levelLabel: 'Manager approval', blocker: null, overPct: 5,
      lines, outstanding, delivery, match, rate, overdueDays: 10,
      ordered, arrived, billed,
    },
    fallbacks: {},
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy,
      bdate: () => TODAY,
      status_badge: (status) => {
        const map = { draft: ['grey', 'Draft'], approved: ['blue', 'Approved'], partial: ['blue', 'Partial'],
          received: ['green', 'Received'], closed: ['grey', 'Closed'], cancelled: ['red', 'Cancelled'],
          open: ['blue', 'Open'], paid: ['green', 'Paid'] };
        const [tone, label] = map[status] ?? ['grey', String(status ?? '')];
        return `<span class="badge badge-${tone}">${label}</span>`;
      },
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) {
          if (params && typeof params === 'object' && !Array.isArray(params) && params.id !== undefined) tail = '-' + params.id;
          else if (Array.isArray(params)) tail = '-' + params.map(part).join('-');
          else if (typeof params === 'object') {
            const segments = [];
            const query = [];
            Object.entries(params).forEach(([k, v]) => {
              if (/^\d+$/.test(k)) segments.push(part(v));
              else if (Array.isArray(v)) query.push(`${k}[]=${v.map(part).join(',')}`);
              else query.push(`${k}=${part(v)}`);
            });
            tail = (segments.length ? '-' + segments.join('-') : '') + (query.length ? '?' + query.join('&') : '');
          } else tail = '-' + part(params);
        }

        return `#${String(name).replace(/\./g, '-')}${tail}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      old: (key, fallback = null) => fallback,
      can_do: (cap) => ['inventory.manage', 'ap.view', 'ap.manage', 'ap.pay'].includes(cap),
      request: (key) => (key === undefined ? { except: () => ({}) } : null),
      setting: (key, fallback = null) => (key === 'po_price_tolerance_pct' ? '2' : fallback),
      hotel: () => HOTEL,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : [])),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      isset: value => value !== undefined && value !== null,
      in_array: (needle, haystack) => haystack.some(v => String(v) === String(needle)),
      min: (...args) => Math.min(...args.map(Number)),
      max: (...args) => Math.max(...args.map(Number)),
      e: value => String(value ?? ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
  };
}
