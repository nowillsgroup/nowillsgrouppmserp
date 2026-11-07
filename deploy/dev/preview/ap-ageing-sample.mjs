/**
 * Sample data for the accounts-payable ageing preview — resources/views/ap/ageing.blade.php rendered the way
 * ApController::ageing() hands it over: the per-supplier buckets with their document counts, the totals, the documents
 * behind them (`ApService::agedItems()`), and the foreign-currency exposure card.
 *
 * The sample is built so the two halves of the screen can be proved against each other: the eight documents below add up,
 * to the cent, to the 182,500 the ageing says is owed. That is the whole point of the card — an ageing figure nobody can
 * open is a number taken on trust.
 *
 *   current (not due)   38,400   26,000 bill + 12,400 goods received, not yet billed
 *   1–30                18,500   one bill, 13 days past its due date
 *   31–60               73,200   42,000 bill + 31,200 EUR bill
 *   61–90                    0
 *   over 90             64,000   one part-paid bill, 115 days past due
 *   credits            −11,600   3,600 debit note + 8,000 unapplied payment
 *   ─────────────────────────
 *   balance            182,500   and the eight documents total exactly this
 *
 * "Today" is 7 November 2026, which is also the business date the ageing is run at.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

export const BUCKETS = { current: 'Not yet due', d30: '1–30', d60: '31–60', d90: '61–90', d90p: 'Over 90' };

const counts = (current = 0, d30 = 0, d60 = 0, d90 = 0, d90p = 0) => ({ current, d30, d60, d90, d90p });

/* One row per supplier, exactly as ApService::ageing() returns them: bucket sums, bucket counts, balance. */
const ROWS = [
  {
    supplier_id: 4, supplier: 'Sahel Textiles', terms: 45, oldest: 115,
    current: 0, d30: 0, d60: 0, d90: 0, d90p: 64000, unbilled: 0, credit: 8000, balance: 56000,
    counts: counts(0, 0, 0, 0, 1), docs: 2,
  },
  {
    supplier_id: 9, supplier: 'Accra Fresh Foods', terms: 30, oldest: 33,
    current: 12400, d30: 0, d60: 42000, d90: 0, d90p: 0, unbilled: 12400, credit: 0, balance: 54400,
    counts: counts(1, 0, 1), docs: 2,
  },
  {
    supplier_id: 12, supplier: 'Volta Power Systems', terms: 14, oldest: 38,
    current: 0, d30: 0, d60: 31200, d90: 0, d90p: 0, unbilled: 0, credit: 0, balance: 31200,
    counts: counts(0, 0, 1), docs: 1,
  },
  {
    supplier_id: 7, supplier: 'Coastal Beverages Ltd', terms: 30, oldest: 13,
    current: 26000, d30: 18500, d60: 0, d90: 0, d90p: 0, unbilled: 0, credit: 3600, balance: 40900,
    counts: counts(1, 1), docs: 3,
  },
];

const KEYS = ['current', 'd30', 'd60', 'd90', 'd90p', 'unbilled', 'credit', 'balance'];
const TOT = Object.fromEntries(KEYS.map(k => [k, Math.round(ROWS.reduce((t, r) => t + r[k], 0) * 100) / 100]));
const COUNTS = Object.fromEntries(Object.keys(BUCKETS).map(k => [k, ROWS.reduce((t, r) => t + r.counts[k], 0)]));

/* ApService::agedItems(): the documents behind it, oldest first. Credits are negative because they reduce what is owed. */
const ITEMS = [
  { kind: 'Bill', supplier_id: 4, supplier: 'Sahel Textiles', doc: 'ST-2026-0114', ref: 'LINEN-RUN-2', date: '2026-07-15', due: '2026-07-15', days: 115, bucket: 'd90p', amount: 64000, note: 'part-paid' },
  { kind: 'Bill', supplier_id: 12, supplier: 'Volta Power Systems', doc: 'VPS-8841', ref: 'DIESEL-Q3', date: '2026-09-30', due: '2026-09-30', days: 38, bucket: 'd60', amount: 31200, note: 'EUR bill' },
  { kind: 'Bill', supplier_id: 9, supplier: 'Accra Fresh Foods', doc: 'AFF-2026-0912', ref: 'PRODUCE-WK36', date: '2026-09-05', due: '2026-10-05', days: 33, bucket: 'd60', amount: 42000, note: null },
  { kind: 'Bill', supplier_id: 7, supplier: 'Coastal Beverages Ltd', doc: 'CBL-2026-0771', ref: 'BEV-OCT', date: '2026-09-25', due: '2026-10-25', days: 13, bucket: 'd30', amount: 18500, note: null },
  { kind: 'Bill', supplier_id: 7, supplier: 'Coastal Beverages Ltd', doc: 'CBL-2026-0802', ref: 'BEV-NOV', date: '2026-10-21', due: '2026-11-12', days: 0, bucket: 'current', amount: 26000, note: null },
  { kind: 'Goods received', supplier_id: 9, supplier: 'Accra Fresh Foods', doc: 'PO-2026-0341', ref: null, date: '2026-10-20', due: '2026-11-19', days: 0, bucket: 'current', amount: 12400, note: 'not yet billed' },
  { kind: 'Debit note', supplier_id: 7, supplier: 'Coastal Beverages Ltd', doc: 'DN-2026-0044', ref: 'SHORT-DELIVERY', date: '2026-10-28', due: null, days: 0, bucket: 'current', amount: -3600, note: 'credit' },
  { kind: 'Unapplied payment', supplier_id: 4, supplier: 'Sahel Textiles', doc: 'PAY-2026-0188', ref: null, date: '2026-10-30', due: null, days: 0, bucket: 'current', amount: -8000, note: 'paid, not applied' },
];
const ITEM_TOTAL = Math.round(ITEMS.reduce((t, r) => t + r.amount, 0) * 100) / 100;

/* ApService::fxExposure(): the one currency this hotel still owes money in, at today's board. */
const FX = {
  home: 'GHS', as_of: '2026-11-07', unpriced: 0, payments: 1, realised: 40,
  booked: 31200, at_board: 31440, movement: 240,
  rows: [{
    currency: 'EUR', bills: 1, foreign: 2400, booked: 31200, rate_now: 13.1, board_date: '2026-11-06', stale: false,
    at_board: 31440, movement: 240, payments: 1, paid_foreign: 800, realised: 40,
  }],
};

/* LedgerService::control('accounts_payable', …): the control account the ageing has to agree with. It ties */
/* here — the eight documents above total 182,500 and so does the posted control balance. */
const TIE = {
  key: 'accounts_payable', label: 'Accounts payable (supplier sub-ledger)',
  sub: 182500, gl: 182500, diff: 0, ties: true, strict: true,
  as_of: '2026-11-07', posted_through: '2026-11-06', behind: true,
};

/* ApService::dueStats(): the same banding the buckets use, read as three figures. The bills behind it are the five open
 * bills (181,700), the unbilled receipt is added (12,400) and the credits taken off (11,600), which is the 182,500
 * balance above — that is the point of computing it once. One 26,000 bill falls due inside the next seven days. */
const DUE = {
  as_of: '2026-11-07', open_bills: 181700, unbilled_value: 12400, credits: 11600,
  overdue: 155700, due7: 26000, payable: 182500,
  counts: { bills: 5, unbilled: 1, overdue: 4, due7: 1, hold: 0 },
};

/* ApService::creditSources(): the credit column is one unapplied payment of 8,000 and one open debit note of 3,600 —
 * the same two documents the supplier payments screen prints, which is where the money can be seen. */
const CREDITS = {
  as_of: '2026-11-07', payments: 8000, payment_count: 1, debit_notes: 3600, debit_note_count: 1, credits: 11600,
};

export function apAgeingContext() {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      // the view hands dmy() the strings ApService::agedItems() returns, so a plain ISO date has to format like a Carbon
      dmy: value => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        const d = new Date(`${String(value).slice(0, 10)}T00:00:00Z`);
        if (Number.isNaN(d.getTime())) return String(value);
        const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()];
        return `${String(d.getUTCDate()).padStart(2, '0')} ${m} ${d.getUTCFullYear()}`;
      },
      bdate: () => TODAY,
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? Object.values(params).join('-') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      request: () => null,
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
      asOf: '2026-11-07',
      rows: new Coll(ROWS),
      tot: TOT,
      counts: COUNTS,
      buckets: BUCKETS,
      items: new Coll(ITEMS),
      itemsTotal: ITEM_TOTAL,
      fx: FX,
      tie: TIE,
      due: DUE,
      credits: CREDITS,
      currentHotel: HOTEL,
    },
  };
}
