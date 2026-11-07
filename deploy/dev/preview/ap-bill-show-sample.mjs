/**
 * Sample data for the supplier bill preview — resources/views/ap/bill-show.blade.php rendered the way
 * ApController::show() hands it over: the bill with its supplier, its lines, what has been applied, the credits that
 * could be applied, and the journals behind it.
 *
 * The bill is Coastal Beverages' CBL-2026-0771, the 18,500 that sits in the ageing's 1–30 bucket, 13 days past its due
 * date. It is part-paid: 23,000 entered (20,000 net, 3,000 VAT), 4,500 applied, 18,500 outstanding — which is the figure
 * the payables ageing prints for that bucket, so the three screens agree before anything is clicked.
 *
 * The apply-credit list is v9.4's reason for existing: it holds the supplier's 8,000 payment on account and the 3,600
 * debit note, both in cedis, because those are exactly the documents ApService::openCredits() returns — the same set the
 * ageing nets off its buckets and the payments screen lists. A credit in another currency is not offered here: applying
 * it to a cedi bill is refused, and an option that throws is not an offer.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const SUPPLIER = { id: 7, name: 'Coastal Beverages Ltd', payment_terms_days: 30 };
const MODE = { id: 1, name: 'Bank transfer' };

const badge = '<span class="badge badge-blue">Open</span>';

/* the three lines: subtotal 20,000, VAT at 15% 3,000, total 23,000 */
const LINES = [
  { head: { label: 'Cost of Beverages' }, description: 'House beer, October delivery', amount: 10000, tax_rate: 15, tax_amount: 1500 },
  { head: { label: 'Cost of Beverages' }, description: 'Soft drinks and water', amount: 6000, tax_rate: 15, tax_amount: 900 },
  { head: { label: 'Operating Supplies' }, description: 'Empty crates and keg deposit', amount: 4000, tax_rate: 15, tax_amount: 600 },
];
const SUBTOTAL = LINES.reduce((t, l) => t + l.amount, 0);
const TAX = LINES.reduce((t, l) => t + l.tax_amount, 0);
const TOTAL = SUBTOTAL + TAX;

const PAYMENT = {
  id: 58, payment_no: 'PAY-2026-0058', date: day('2026-09-30'), reference: 'CHQ 004098',
  paymentMode: MODE, currency: null, fx_amount: null, fx_rate: null,
  isForeign: () => false,
  fxLabel: () => `GHS ${Number(4500).toFixed(2)}`,
};

const ALLOCATED = 4500;

/* ApAllocation::with('payment.paymentMode') */
const ALLOCATIONS = new Coll([{ id: 918, date: day('2026-09-30'), amount: ALLOCATED, payment: PAYMENT, debitNote: null }]);

const BILL = {
  id: 771, doc_no: 'CBL-2026-0771', supplier_ref: 'BEV-OCT', status: 'open', kind: 'expense',
  supplier_id: SUPPLIER.id, supplier: SUPPLIER,
  doc_date: day('2026-09-25'), due_date: day('2026-10-25'), opening_date: null, cancelled_at: null, cancel_reason: null,
  created_at: new SampleDate('2026-09-25T14:20:00Z'), approved_at: null, approver: null, creator: { name: 'Ama Mensah' },
  currency: null, fx_rate: null, fx_date: null, fx_total: null, fx_subtotal: null, fx_tax: null,
  purchase_order_id: null, purchaseOrder: null, againstInvoice: null,
  subtotal: SUBTOTAL, tax: TAX, total: TOTAL, allocated: ALLOCATED, notes: null,
  lines: new Coll(LINES), allocations: ALLOCATIONS, applications: new Coll([]),
  isOpening: () => false,
  isDebitNote: () => false,
  isForeign: () => false,
  outstanding: () => Math.round((TOTAL - ALLOCATED) * 100) / 100,
  daysOverdue: (asOf) => {
    const due = Date.parse('2026-10-25T00:00:00Z');
    const on = Date.parse(`${asOf ?? '2026-11-07'}T00:00:00Z`);
    return Math.max(0, Math.round((on - due) / 86400000));
  },
  variance: () => 0,
  fxDifference: () => 0,
  fxLabel: () => 'GHS 0.00',
};

/* ApService::openCredits(7, null, 'GHS'), mapped by the controller into the dropdown's options */
const CREDITS = new Coll([
  { key: 'p102', label: 'PAY-2026-0102 · 30 Oct · unapplied GH₵ 8,000.00', free: 8000 },
  { key: 'd44', label: 'DN-2026-0044 · debit note · unapplied GH₵ 3,600.00', free: 3600 },
]);

/* the bill's own posting, and no goods-receipt journal because nothing was received for an expense */
const JOURNALS = new Coll([{ id: 1188, journal_no: 'GL-2026-1188', date: day('2026-09-26'), total: TOTAL, reversal_of_id: null, reversed_by_id: null }]);

export function apBillShowContext() {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: (value) => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        return String(value);
      },
      bdate: () => TODAY,
      status_badge: () => badge,
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) {
          // a model binds by its key; an array of named parameters becomes the query string
          if (params && typeof params === 'object' && !Array.isArray(params) && params.id !== undefined) tail = '-' + params.id;
          else if (Array.isArray(params)) tail = params.map(part).join('-');
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
      can_do: () => true,
      request: (key) => (key === undefined ? { except: () => ({}) } : null),
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : [])),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      isset: value => value !== undefined && value !== null,
      in_array: (needle, haystack) => haystack.some(v => String(v) === String(needle)),
      min: (...args) => Math.min(...args.map(Number)),
      max: (...args) => Math.max(...args.map(Number)),
      e: value => String(value ?? ''),
      e: value => String(value ?? ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      bill: BILL,
      credits: CREDITS,
      journals: JOURNALS,
      grnJournal: null,
      currentHotel: HOTEL,
    },
  };
}
