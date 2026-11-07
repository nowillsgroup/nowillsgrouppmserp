/**
 * Sample data for the supplier bills preview — resources/views/ap/bills.blade.php rendered the way ApController::bills()
 * hands it over: the page of documents, the supplier list, and the figures.
 *
 * The figures are the ones the payables ageing prints, because v9.1 they come from the same banding rule
 * (`ApService::dueStats()`): 181,700 sits on the open bills, 12,400 is goods received and not yet billed, 11,600 is
 * credits, and the total payable is 182,500 — the same 182,500 the ageing calls its balance. The list shows the
 * documents behind that: an overdue produce bill, a European fuel bill, a part-paid linen bill 115 days past due, a
 * bill held for a price variance, and a debit note that reduces what is owed rather than adding to it.
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

/* Laravel hands the view a paginator: countable and iterable, with links() at the foot. */
class Pager extends Coll {
  links() {
    return raw('<nav class="pager" aria-label="Pages"><span class="current">1</span> <a href="?page=2">2</a></nav>');
  }
}

const supplier = (id, name) => ({ id, name });
const ACCRA = supplier(9, 'Accra Fresh Foods');
const COASTAL = supplier(7, 'Coastal Beverages Ltd');
const VOLTA = supplier(12, 'Volta Power Systems');
const SAHEL = supplier(4, 'Sahel Textiles');

const badge = {
  open: '<span class="badge badge-blue">Open</span>',
  partial: '<span class="badge badge-amber">Part-paid</span>',
  on_hold: '<span class="badge badge-red">On hold (variance)</span>',
};

/** One supplier document, shaped like SupplierInvoice. */
const bill = (o) => ({
  id: o.id, doc_no: o.doc_no, supplier_ref: o.ref ?? null, supplier: o.supplier,
  doc_date: day(o.date), due_date: o.due ? day(o.due) : null, total: o.total, allocated: o.allocated ?? 0,
  status: o.status ?? 'open', kind: o.kind ?? 'expense', opening_date: o.opening ? day(o.date) : null,
  currency: o.currency ?? null, fx_rate: o.fx_rate ?? null, fx_total: o.fx_total ?? 2400,
  // SupplierInvoice::fxLabel(): the document's own currency first, then its rate, and the FX difference in home money
  fxLabel: (amount) => `${o.currency ?? 'GHS'} ${Number(amount ?? o.fx_total ?? 2400).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}`,
  isForeign: () => Boolean(o.currency),
  purchaseOrder: o.po ? { po_no: o.po } : null,
  isDebitNote: () => o.debit === true,
  isOpening: () => o.opening === true,
  isForeign: () => Boolean(o.currency),
  fxDifference: () => o.fx_difference ?? 0,
  variance: () => o.variance ?? 0,
  daysOverdue: (asOf) => {
    if (o.debit) return 0;
    const due = o.due ? new Date(`${o.due}T00:00:00Z`) : new Date(`${o.date}T00:00:00Z`);
    const on = new Date(`${asOf ?? '2026-11-07'}T00:00:00Z`);
    return Math.max(0, Math.round((on - due) / 86400000));
  },
  outstanding: () => Math.round((o.total - (o.allocated ?? 0)) * 100) / 100,
  statusBadge: () => badge[o.status ?? 'open'] ?? '',
});

const BILLS = [
  bill({ id: 412, doc_no: 'BILL-2026-0412', ref: 'BEV-NOV', supplier: COASTAL, date: '2026-10-21', due: '2026-11-12', total: 26000 }),
  bill({ id: 401, doc_no: 'BILL-2026-0401', ref: 'PRODUCE-WK36', supplier: ACCRA, date: '2026-09-05', due: '2026-10-05', total: 42000 }),
  bill({ id: 390, doc_no: 'BILL-2026-0390', ref: 'DIESEL-Q3', supplier: VOLTA, date: '2026-09-30', due: '2026-09-30', total: 31200,
    kind: 'goods', po: 'PO-2026-0337', currency: 'EUR', fx_rate: 13.0, fx_difference: -240 }),
  bill({ id: 355, doc_no: 'BILL-2026-0355', ref: 'LINEN-RUN-2', supplier: SAHEL, date: '2026-07-15', due: '2026-07-15', total: 80000, allocated: 16000, status: 'partial' }),
  bill({ id: 404, doc_no: 'BILL-2026-0404', ref: 'PO-2026-0349', supplier: ACCRA, date: '2026-10-28', due: null, total: 9600,
    kind: 'goods', po: 'PO-2026-0349', status: 'on_hold', variance: 800 }),
  bill({ id: 44, doc_no: 'DN-2026-0044', ref: 'SHORT-DELIVERY', supplier: COASTAL, date: '2026-10-28', total: 3600, debit: true, status: 'open' }),
];

/* Goods received and not yet billed: owed at receipt value until the supplier's invoice is matched. */
const UNBILLED = [{
  id: 341, po_no: 'PO-2026-0341', supplier: ACCRA, grnDate: () => '2026-10-20', invoice_no: null,
  receiptValue: 12400, total: 12400, status: 'received', bill: null,
}];

/* ApService::dueStats(): the figures both AP screens print. These are the ageing's numbers to the cent. */
const KPI = {
  as_of: '2026-11-07', open_bills: 181700, unbilled_value: 12400, credits: 11600,
  overdue: 155700, due7: 26000, payable: 182500,
  counts: { bills: 5, unbilled: 1, overdue: 4, due7: 1, hold: 1 },
  outstanding: 181700, hold: 1,
  unbilled: new Coll(UNBILLED),
};

const SUPPLIERS = [ACCRA, COASTAL, SAHEL, VOLTA];

export function apBillsContext({ status = '' } = {}) {
  const QUERY = { status, supplier: null, type: null, q: null, overdue: null, page: null };
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
      status_badge: (s) => badge[s] ?? `<span class="badge badge-slate">${String(s)}</span>`,
      // a route parameter may be a model: Laravel binds it by its key, so the sample uses its id (and nothing else)
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) {
          if (Array.isArray(params)) tail = params.map(part).join('-');
          else tail = part(params);
        }
        return `#${String(name).replace(/\./g, '-')}${tail ? '-' + tail : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      // the screen reads request('status') and also request()->except(...) for the tab links
      request: (key) => (key === undefined
        ? { except: (...skip) => Object.fromEntries(Object.entries(QUERY).filter(([k, v]) => v !== null && v !== '' && !skip.includes(k))) }
        : (QUERY[key] ?? null)),
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? ''),
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
      bills: new Pager(BILLS),
      suppliers: new Coll(SUPPLIERS),
      kpi: KPI,
      currentHotel: HOTEL,
    },
  };
}
