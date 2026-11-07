/**
 * Sample data for the company ledger preview — resources/views/accounts/company.blade.php rendered the way
 * AccountsController::companyLedger() hands it over: the company, its charges and receipts to the as-at date, the modes
 * the receive-payment modal offers, and v9.7's tie to the receivables ageing.
 *
 * The company is Ashanti Builders Co-op, the one that is over its credit limit on the receivables ageing — and the
 * figures here are that screen's figures: billed 52,400, received 4,000, outstanding 48,400. Its ledger is a history: an
 * August function bill settled by a receipt, and the 48,400 September accommodation bill nobody has paid, which is the
 * 48,400 the ageing prints in its 1–30 bucket and the whole of what this company owes. So the ledger closes at 48,400,
 * the ageing says 48,400, and the city ledger index prints the same three totals for the same company.
 *
 * The period's arithmetic, as the screen prints it:
 *
 *   12 Aug  Bill     INV-7631    4,000.00 debit    balance  4,000.00
 *   20 Aug  Receipt  RCPT-2210   4,000.00 credit   balance      0.00
 *   12 Sep  Bill     INV-7712   48,400.00 debit    balance 48,400.00   ← open, 42 days past its due date
 *   ────────────────────────────────────────────
 *   closing        52,400.00 debits,  4,000.00 credits, 48,400.00    and the ageing says 48,400
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const COMPANY = { id: 21, name: 'Ashanti Builders Co-op', type: 'corporate', credit_limit: 40000, credit_days: 30 };

/* the folios behind the two settled charges: the ledger links a charge to the folio it came from */
const FOLIO = (id, no) => ({ id, folio_no: no, invoice_no: no, guest: { full_name: 'Site crew' } });

const LINES = [
  { date: day('2026-08-12'), due: day('2026-09-11'), ref: 'INV-7631', desc: 'Bill – Site crew', debit: 4000, credit: 0, folio: FOLIO(812, 'INV-7631') },
  { date: day('2026-08-20'), due: null, ref: 'RCPT-2210', desc: 'Receipt #CHQ 003998', debit: 0, credit: 4000, folio: null },
  { date: day('2026-09-12'), due: day('2026-09-26'), ref: 'INV-7712', desc: 'Bill – Site crew', debit: 48400, credit: 0, folio: FOLIO(912, 'INV-7712') },
];
// the controller adds each row's running balance, so the last row and the closing figure cannot disagree
let running = 0;
const ROWS = LINES.map((r) => {
  running = Math.round((running + r.debit - r.credit) * 100) / 100;
  return { ...r, balance: running };
});
const CLOSING = running;

/* AccountsController::companyLedger(): the closing balance and the ageing's outstanding for the same company at the
 * same date, compared. Both are 48,400 because both are built from these charges and receipts. */
const TIE = { as_of: '2026-11-07', ledger: CLOSING, ageing: 48400, diff: Math.round((CLOSING - 48400) * 100) / 100, ties: CLOSING === 48400 };

// PaymentMode::pluck('name', 'id') — the x-field select takes a map of value => label
const PAYMENT_MODES = { 1: 'Bank transfer', 2: 'Cheque', 3: 'Cash' };
const SPLIT_MODES = { 1: 'Bank transfer', 3: 'Cash', 4: 'Card' };

export function companyLedgerContext({ asOf = '2026-11-07' } = {}) {
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
      // x-field refills every input with old($name, $value): a preview of a fresh form takes the value as it stands
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
      company: COMPANY,
      rows: new Coll(ROWS),
      paymentModes: PAYMENT_MODES,
      splitModes: SPLIT_MODES,
      tie: TIE,
      closing: CLOSING,
      asOf,
      currentHotel: HOTEL,
    },
  };
}
