/**
 * Sample data for the supplier statement preview — resources/views/ap/statement.blade.php with its body partial,
 * rendered the way ApController::statement() hands it over: the supplier, the period, the statement lines, the ageing
 * summary for the same supplier, and the tie between the two.
 *
 * The statement is the document a supplier is sent, so it has to agree with what we say internally. v9.3 the screen says
 * so itself: the statement closes at 40,900 and the ageing at 7 November says the same 40,900, because both are built
 * from these documents by different routes — the statement line by line from the goods received, the bills and the
 * credit, the ageing from the buckets. The card is there to be read before the statement is sent.
 *
 * The period's arithmetic, as it appears on the screen:
 *
 *   opening balance      0.00      nothing was owed on 10 August
 *   24 Aug  GRN-2026-0312   22,000.00 credit    goods received — owed from the day they arrive
 *   02 Sep  PAY-2026-0061   22,000.00 debit     paid by transfer, back to nothing
 *   25 Sep  CBL-2026-0771   18,500.00 credit    an expense bill, no receipt behind it
 *   19 Oct  GRN-2026-0326   26,000.00 credit    the November drinks delivery
 *   21 Oct  CBL-2026-0802        0.00 credit    the bill matched to that receipt: only a variance would move it
 *   28 Oct  DN-2026-0044     3,600.00 debit     short delivery, credited by the supplier
 *   ────────────────────────────────────────
 *   closing balance     40,900.00               and Coastal Beverages' ageing row says 40,900
 *
 * "Today" is 7 November 2026; the period runs from 10 August.
 */
import { SampleDate, phpEmpty } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

export const SUPPLIER = { id: 7, name: 'Coastal Beverages Ltd', payment_terms_days: 30 };

const rows = [
  { date: '2026-08-24', doc: 'GRN-2026-0312', type: 'Goods received (PO-2026-0298)', ref: 'INV-7781', dr: 0, cr: 22000, link: '#purchases-show-298' },
  { date: '2026-09-02', doc: 'PAY-2026-0061', type: 'Payment – Bank transfer', ref: 'CHQ 004102', dr: 22000, cr: 0, link: '#ap-payments-show-61' },
  { date: '2026-09-25', doc: 'CBL-2026-0771', type: 'Expense bill', ref: 'BEV-OCT', dr: 0, cr: 18500, link: '#ap-bills-show-771' },
  { date: '2026-10-19', doc: 'GRN-2026-0326', type: 'Goods received (PO-2026-0311)', ref: 'INV-7920', dr: 0, cr: 26000, link: '#purchases-show-311' },
  { date: '2026-10-21', doc: 'CBL-2026-0802', type: 'Bill (matched to receipt)', ref: 'BEV-NOV', dr: 0, cr: 0, info: 'GH₵ 26,000.00', link: '#ap-bills-show-802' },
  { date: '2026-10-28', doc: 'DN-2026-0044', type: 'Debit note', ref: 'SHORT-DELIVERY', dr: 3600, cr: 0, link: '#ap-bills-show-44' },
];

let running = 0;
const ST_ROWS = rows.map((r) => {
  running = Math.round((running + r.cr - r.dr) * 100) / 100;
  return { ...r, balance: running };
});

/* ApService::statement(): opening, the period's lines with a running balance, the close, and the period's two columns. */
const ST = {
  opening: 0,
  rows: ST_ROWS,
  closing: running,
  dr: Math.round(ST_ROWS.reduce((t, r) => t + r.dr, 0) * 100) / 100,
  cr: Math.round(ST_ROWS.reduce((t, r) => t + r.cr, 0) * 100) / 100,
};

/* ApService::ageing($to, $supplier->id)->first(): the same supplier's row on the ageing screen. */
const AGE = {
  supplier_id: 7, supplier: 'Coastal Beverages Ltd', terms: 30, oldest: 13,
  current: 26000, d30: 18500, d60: 0, d90: 0, d90p: 0, unbilled: 0, credit: 3600, balance: 40900,
  counts: { current: 1, d30: 1, d60: 0, d90: 0, d90p: 0 }, docs: 3,
};

/* the tie the controller computes: statement closing against the ageing balance, both at the statement date */
const TIE = { as_of: '2026-11-07', statement: ST.closing, ageing: AGE.balance, diff: 0, ties: true };

export function apStatementContext({ from = '2026-08-10', to = '2026-11-07' } = {}) {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: (value) => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        const d = new Date(`${String(value).slice(0, 10)}T00:00:00Z`);
        if (Number.isNaN(d.getTime())) return String(value);
        const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()];
        return `${String(d.getUTCDate()).padStart(2, '0')} ${m} ${d.getUTCFullYear()}`;
      },
      bdate: () => TODAY,
      // route('ap.statement', [$supplier, 'from' => …, 'to' => …, 'print' => 1]): the supplier is a path segment,
      // the rest is the query string
      route: (name, params) => {
        const base = `#${String(name).replace(/\./g, '-')}`;
        const segments = [];
        const query = [];
        const take = (key, value) => {
          const shown = value && typeof value === 'object' ? String(value.id ?? '') : String(value);
          if (typeof key === 'number' || /^\d+$/.test(String(key))) segments.push(shown);
          else query.push(`${key}=${shown}`);
        };
        if (Array.isArray(params)) params.forEach((value, index) => take(index, value));
        else if (params && typeof params === 'object') Object.entries(params).forEach(([key, value]) => take(key, value));
        else if (params !== undefined && params !== null) segments.push(String(params));
        return base + (segments.length ? '-' + segments.join('-') : '') + (query.length ? '?' + query.join('&') : '');
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      request: (key) => (key === undefined ? { except: () => ({}) } : (key === 'from' ? from : key === 'to' ? to : null)),
      isset: value => value !== undefined && value !== null,
      empty: phpEmpty,
      collect: value => ({ items: Array.isArray(value) ? value : [], sum: (k) => (Array.isArray(value) ? value.reduce((t, r) => t + Number(r[k] ?? 0), 0) : 0) }),
      count: value => (Array.isArray(value) ? value.length : 0),
      e: value => String(value ?? ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      supplier: SUPPLIER,
      from,
      to,
      st: ST,
      age: AGE,
      tie: TIE,
      currentHotel: HOTEL,
    },
  };
}
