/**
 * Sample data for the city ledger index preview — resources/views/accounts/cityledger.blade.php rendered the way
 * AccountsController::cityLedger() hands it over: every company with its balance to the as-at date, the ageing's own
 * figure for the same company, and v9.8's tie between the two totals.
 *
 * The four companies are the receivables ageing's population plus one that has never been billed or paid, and the figures
 * are that screen's figures, because the two screens read one sub-ledger:
 *
 *   company                    billed     received   outstanding   ageing    flag
 *   Zenith Oil Ghana Ltd      134,500      60,000        74,500   74,500    90+ overdue
 *   Ashanti Builders Co-op     52,400       4,000        48,400   48,400    OVER LIMIT
 *   Meridian Travel & Tours     9,800      12,500        −2,700   −2,700    credit on account
 *   Coconut Grove Pensions          0           0             0        0    no entries yet
 *   ──────────────────────────────────────────────────────────────────
 *   total                     196,700      76,500       120,200  120,200    the two agree, company by company
 *
 * The drift variant is the fault the page exists to catch: a receipt the list counted and the ageing did not, so Meridian
 * reads 300.00 here against −2,700.00 there, the totals differ by 3,000.00, and the page has to name the row.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* Company::orderBy('name') — the order the list prints */
const COMPANY_ROWS = [
  { id: 21, name: 'Ashanti Builders Co-op', type: 'corporate', contact_person: 'Kofi Mensah', phone: '024 555 0180',
    credit_limit: 40000, billed: 52400, received: 4000, flag: 'OVER LIMIT' },
  { id: 40, name: 'Coconut Grove Pensions', type: 'corporate', contact_person: 'Adjoa Boateng', phone: '030 274 6611',
    credit_limit: 0, billed: 0, received: 0, flag: '' },
  { id: 33, name: 'Meridian Travel & Tours', type: 'travel_agent', contact_person: 'Yaa Asantewaa', phone: '024 401 9922',
    credit_limit: 5000, billed: 9800, received: 12500, flag: '' },
  { id: 14, name: 'Zenith Oil Ghana Ltd', type: 'corporate', contact_person: 'Nana Ampofo', phone: '030 702 3140',
    credit_limit: 120000, billed: 134500, received: 60000, flag: '90+ overdue' },
];

/* the ageing's outstanding for the same company at the same date — ArService::ageing() keyed by id */
const AGED = { 21: 48400, 40: 0, 33: -2700, 14: 74500 };

const round2 = (value) => Math.round(Number(value ?? 0) * 100) / 100;

export function cityLedgerContext({ asOf = '2026-11-07', drift = false } = {}) {
  const rows = COMPANY_ROWS.map((c) => {
    // The drift variant: one receipt counted here and not by the ageing, which is exactly how two queries fall out.
    const received = drift && c.id === 33 ? 9500 : c.received;
    const outstanding = round2(c.billed - received);
    const aged = drift && c.id === 33 ? -2700 : AGED[c.id];
    return { ...c, received, outstanding, aged, ties: Math.abs(round2(outstanding - aged)) <= 0.005,
      quiet: Math.abs(c.billed) <= 0.004 && Math.abs(received) <= 0.004 };
  });
  const sum = (key) => round2(rows.reduce((total, r) => total + r[key], 0));
  const ageing = round2(rows.reduce((total, r) => total + r.aged, 0));
  const tie = {
    as_of: asOf,
    list: sum('outstanding'),
    ageing,
    rows: rows.length,
    over_limit: rows.filter(r => r.flag === 'OVER LIMIT').length,
    drift: rows.filter(r => !r.ties).map(r => ({ id: r.id, name: r.name, list: r.outstanding, ageing: r.aged })),
  };
  tie.diff = round2(tie.list - tie.ageing);
  tie.ties = Math.abs(tie.diff) <= 0.005 && tie.drift.length === 0;

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
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        const path = [];
        const query = [];
        const take = (key, value) => {
          if (typeof key === 'number' || /^\d+$/.test(String(key))) path.push(part(value));
          else query.push(`${key}=${part(value)}`);
        };
        if (params && typeof params === 'object' && !Array.isArray(params) && params.id !== undefined) path.push(params.id);
        else if (Array.isArray(params)) params.forEach((value, index) => take(index, value));
        else if (params && typeof params === 'object') Object.entries(params).forEach(([key, value]) => take(key, value));
        else if (params !== undefined && params !== null) path.push(part(params));
        return `#${String(name).replace(/\./g, '-')}${path.length ? '-' + path.join('-') : ''}${query.length ? '?' + query.join('&') : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      request: (key) => (key === undefined ? { except: () => ({}) } : (key === 'as_of' ? asOf : null)),
      isset: value => value !== undefined && value !== null,
      old: (key, fallback = null) => fallback,
      e: value => String(value ?? ''),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      abs: value => Math.abs(Number(value ?? 0)),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      ucfirst: value => String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1),
      str_replace: (search, replace, subject) => String(subject ?? '').split(search).join(replace),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : [])),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      companies: Coll.of(rows),
      tie,
      asOf,
      currentHotel: HOTEL,
    },
  };
}
