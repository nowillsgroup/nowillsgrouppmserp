/**
 * Sample data for the company receivables (city ledger) ageing preview — resources/views/accounts/receivables.blade.php
 * rendered the way AccountsController::receivables() hands it over: the FIFO buckets with their document counts, the
 * totals, and the charges behind them (ArService::items()).
 *
 * The sample is built so the FIFO rule is visible rather than asserted in a comment. Zenith Oil owes 88,000 for a June
 * conference and has paid 60,000 since; because receipts are applied oldest charge first, what is left of that conference
 * is 28,000 and it is 112 days past its due date, while the October dinner is untouched at 46,500. Ashanti Builders is
 * over its 40,000 limit. Meridian Travel has paid more than it was billed, so it is a 2,700 credit on account — a negative
 * outstanding, listed as such.
 *
 *   bucket            total     documents
 *   0–30 days        46,500     1   (Zenith's October dinner, 6 days late)
 *   31–60 days       48,400     1   (Ashanti Builders, 42 days late)
 *   61–90 days            0
 *   over 90 days     28,000     1   (Zenith's June conference, part-paid, 112 days late)
 *   on account       −2,700          (Meridian's receipt with nothing left to settle)
 *   ─────────────────────────
 *   outstanding     120,200     and the four documents total exactly this
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

export const BUCKETS = { b0: '0–30 days', b30: '31–60 days', b60: '61–90 days', b90: 'Over 90 days' };

const items = (rows) => rows;
const ROWS = [
  {
    company: 'Zenith Oil Ghana Ltd', type: 'Corporate', billed: 134500, received: 60000,
    b0: 46500, b30: 0, b60: 0, b90: 28000, unapplied: 0, outstanding: 74500, limit: 120000,
    oldest: '112 d', oldest_days: 112, flag: '90+ overdue', id: 14, counts: { b0: 1, b30: 0, b60: 0, b90: 1 },
    items: items([
      { kind: 'Bill', company_id: 14, company: 'Zenith Oil Ghana Ltd', doc: 'INV-8841', desc: 'Conference – 3 nights, 12 rooms', date: '2026-06-18', due: '2026-07-18', days: 112, bucket: 'b90', amount: 28000, note: null },
      { kind: 'Bill', company_id: 14, company: 'Zenith Oil Ghana Ltd', doc: 'INV-9033', desc: 'Executive dinner – board', date: '2026-10-02', due: '2026-11-01', days: 6, bucket: 'b0', amount: 46500, note: null },
    ]),
  },
  {
    company: 'Ashanti Builders Co-op', type: 'Corporate', billed: 52400, received: 4000,
    b0: 0, b30: 48400, b60: 0, b90: 0, unapplied: 0, outstanding: 48400, limit: 40000,
    oldest: '42 d', oldest_days: 42, flag: 'OVER LIMIT', id: 21, counts: { b0: 0, b30: 1, b60: 0, b90: 0 },
    items: items([
      { kind: 'Bill', company_id: 21, company: 'Ashanti Builders Co-op', doc: 'INV-7712', desc: 'Site crew accommodation – September', date: '2026-09-12', due: '2026-09-26', days: 42, bucket: 'b30', amount: 48400, note: null },
    ]),
  },
  {
    company: 'Meridian Travel & Tours', type: 'Travel agent', billed: 9800, received: 12500,
    b0: 0, b30: 0, b60: 0, b90: 0, unapplied: 2700, outstanding: -2700, limit: 5000,
    oldest: '—', oldest_days: 0, flag: '', id: 33, counts: { b0: 0, b30: 0, b60: 0, b90: 0 },
    items: items([
      { kind: 'Receipt not applied', company_id: 33, company: 'Meridian Travel & Tours', doc: 'on account', desc: null, date: '2026-11-07', due: null, days: 0, bucket: 'b0', amount: -2700, note: 'credit' },
    ]),
  },
];

const KEYS = ['b0', 'b30', 'b60', 'b90', 'unapplied', 'outstanding', 'billed', 'received'];
const TOT = Object.fromEntries(KEYS.map(k => [k, Math.round(ROWS.reduce((t, r) => t + r[k], 0) * 100) / 100]));
const COUNTS = Object.fromEntries(Object.keys(BUCKETS).map(k => [k, ROWS.reduce((t, r) => t + r.counts[k], 0)]));
const ITEMS = ROWS.flatMap(r => r.items).sort((a, b) => b.days - a.days || b.amount - a.amount);
const ITEM_TOTAL = Math.round(ITEMS.reduce((t, r) => t + r.amount, 0) * 100) / 100;
const OVER_LIMIT = ROWS.filter(r => r.flag === 'OVER LIMIT').length;

/* LedgerService::control('city_ledger', …): the control account beside the ageing. It differs by 1,700 here, and the */
/* screen has to explain that as the day not yet posted rather than as a fault — 7 November is past the 6 November the */
/* ledger is posted through. */
const TIE = {
  key: 'city_ledger', label: 'City ledger (company receivables)',
  sub: 120200, gl: 118500, diff: 1700, ties: false, strict: true,
  as_of: '2026-11-07', posted_through: '2026-11-06', behind: true,
};

export function receivablesContext() {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      // deterministic, and exactly what Carbon's d M Y prints — the platform's locale must not decide how a date reads
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
      array_keys: values => (values instanceof Coll ? values.items.map((_, i) => i) : Object.keys(values ?? {})),
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
      overLimit: OVER_LIMIT,
      tie: TIE,
      currentHotel: HOTEL,
    },
  };
}
