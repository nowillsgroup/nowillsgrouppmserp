/**
 * Sample data for the supplier payments preview — resources/views/ap/payments.blade.php rendered the way
 * ApController::payments() hands it over: the page of payments, the supplier list, and the four figures.
 *
 * The point of the screen is that two of its figures are not its own opinion. v9.2 "unapplied payments" and "open debit
 * notes" come from ApService::creditSources(), and their sum — 8,000 + 3,600 = 11,600 — is the credit the payables
 * ageing nets off and the bills list takes off what is payable. The same 11,600 is on both of those screens.
 *
 * The payments are chosen to teach the edges of that rule:
 *
 *   • PAY-2026-0102 — 8,000 paid on account, nothing applied: a credit, and the only one here;
 *   • PAY-2026-0105 — a euros payment. 31,440 was paid for a bill booked at 31,200, and the 240 gap is an exchange
 *     difference that has already been posted; what is left of a foreign payment is never a supplier credit;
 *   • PAY-2026-0093 — cancelled, and a cancelled payment is not a credit either, whatever its allocation says;
 *   • two payments fully applied, so the list is not all exceptions;
 *   • the 3,600 debit note behind the credit is on the bills screen, not in this list — the figure says so.
 *
 * v9.15 adds the two records a payment can get wrong on its own. Three of the five withheld tax and each carries a
 * certificate — 1,500.00 on the Coastal cheque, 480.00 on the Sahel linen run, 2,340.00 on the Volta diesel payment,
 * 4,320.00 in all. And the allocated column is checked against the allocations on file, payment by payment: 67,200.00
 * over the three payments that have any. The drift build is a payment file that does not add up in two places: the Sahel
 * payment's certificate is missing (480.00 the supplier cannot claim) and the Volta payment's allocated figure is 500.00
 * more than the allocations behind it.
 *
 * "Today" is 7 November 2026, so the thirty-day window opens on 9 October.
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

const mode = (id, name) => ({ id, name });
const BANK = mode(1, 'Bank transfer');
const CHEQUE = mode(2, 'Cheque');

// SupplierPayment: unapplied() is amount − allocated, and zero when the payment is cancelled or in a foreign currency
const payment = (o) => ({
  ...o,
  cancelled_at: o.cancelled_at ?? null,
  wht_amount: o.wht_amount ?? 0,
  wht_base: o.wht_base ?? 0,
  wht_rate: o.wht_rate ?? 0,
  wht_category: o.wht_category ?? null,
  wht_cert_no: o.wht_cert_no ?? null,
  wvat_amount: o.wvat_amount ?? 0,
  allocated: o.allocated ?? 0,
  currency: o.currency ?? null,
  fx_amount: o.fx_amount ?? null,
  fx_rate: o.fx_rate ?? null,
  fx_diff: o.fx_diff ?? 0,
  unapplied: () => {
    if (o.cancelled_at || isForeign(o)) return 0;
    return Math.round((Number(o.amount) - Number(o.allocated ?? 0)) * 100) / 100;
  },
  isForeign: () => isForeign(o),
  fxLabel: () => `${String(o.currency ?? 'GHS').toUpperCase()} ${Number(o.fx_amount ?? o.amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
  fxDifference: () => (isForeign(o) ? Number(o.fx_diff ?? 0) : 0),
});
const isForeign = (o) => Boolean(o.currency) && String(o.currency).toUpperCase() !== 'GHS';

const PAYMENTS = [
  payment({ id: 102, payment_no: 'PAY-2026-0102', supplier: COASTAL, paymentMode: BANK, reference: 'ON ACCOUNT — OCT',
    date: day('2026-10-30'), amount: 8000, allocated: 0 }),
  payment({ id: 105, payment_no: 'PAY-2026-0105', supplier: VOLTA, paymentMode: BANK, reference: 'DIESEL-Q3 / EUR',
    date: day('2026-11-03'), amount: 31440, allocated: 31200, currency: 'EUR', fx_amount: 2400, fx_rate: 13.1, fx_diff: 240,
    wht_amount: 2340, wht_base: 31200, wht_rate: 7.5, wht_category: 'services', wht_cert_no: 'WHT-2026-0027' }),
  payment({ id: 71, payment_no: 'PAY-2026-0071', supplier: COASTAL, paymentMode: CHEQUE, reference: 'CHQ 004118',
    date: day('2026-09-18'), amount: 20000, allocated: 20000,
    wht_amount: 1500, wht_base: 20000, wht_rate: 7.5, wht_category: 'services', wht_cert_no: 'WHT-2026-0022' }),
  payment({ id: 93, payment_no: 'PAY-2026-0093', supplier: ACCRA, paymentMode: CHEQUE, reference: 'CHQ 004130',
    date: day('2026-10-24'), amount: 5000, allocated: 0, cancelled_at: day('2026-10-27') }),
  payment({ id: 98, payment_no: 'PAY-2026-0098', supplier: SAHEL, paymentMode: BANK, reference: 'LINEN-RUN-2',
    date: day('2026-07-20'), amount: 16000, allocated: 16000,
    wht_amount: 480, wht_base: 16000, wht_rate: 3, wht_category: 'goods', wht_cert_no: 'WHT-2026-0011' }),
];

/* ApService::paymentStats(): the credit is creditSources() — 8,000 unapplied + 3,600 debit note = 11,600, the figure the
 * ageing nets off; 39,440 went out in the thirty days to today, the cancelled 5,000 excluded. */
const KPI = {
  as_of: '2026-11-07',
  payments: 8000, payment_count: 1,
  debit_notes: 3600, debit_note_count: 1,
  credits: 11600,
  paid: 39440, paid_count: 2,
  cancelled_count: 1,
};

const SUPPLIERS = [ACCRA, COASTAL, SAHEL, VOLTA];

export function apPaymentsContext({ drift = false, unapplied = null, cancelled = null, supplier = null, from = null, to = null, q = null } = {}) {
  const QUERY = { unapplied, cancelled, supplier, from, to, q, page: null };

  /* v9.15 — the documents, derived here exactly as the controller derives them */
  const iso = (d) => (typeof d?.toDateString === 'function' ? d.toDateString().slice(0, 10) : String(d).slice(0, 10));
  const days = (d) => Math.max(0, Math.round((Date.parse('2026-11-07T00:00:00Z') - Date.parse(`${iso(d)}T00:00:00Z`)) / 86400000));
  const bucketOf = (n) => (n <= 30 ? 'b0' : (n <= 60 ? 'b30' : (n <= 90 ? 'b60' : 'b90')));
  const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;

  // the drill: the sample's rows are not mutated, the drift build changes what the two records say
  const rows = PAYMENTS.map(p => {
    if (drift && p.payment_no === 'PAY-2026-0098') p = { ...p, wht_cert_no: null };
    if (drift && p.payment_no === 'PAY-2026-0105') p = { ...p, allocated: 30700 };
    return p;
  });

  const free = rows.filter(p => p.unapplied() > 0.004).map(p => ({ doc: p.payment_no, supplier: p.supplier, free: p.unapplied(), days: days(p.date), bucket: bucketOf(days(p.date)) }));
  const oldest = [...free].sort((a, b) => b.days - a.days)[0] ?? null;
  const aged = {
    total: round2(free.reduce((t, c) => t + c.free, 0)), count: free.length, oldest: free.length ? Math.max(...free.map(c => c.days)) : 0,
    worst: oldest ? { doc: oldest.doc, supplier: oldest.supplier.name, free: oldest.free, days: oldest.days } : null,
    beyond: free.filter(c => c.bucket === 'b90').length, beyond_amount: round2(free.filter(c => c.bucket === 'b90').reduce((t, c) => t + c.free, 0)),
  };
  for (const b of ['b0', 'b30', 'b60', 'b90']) {
    aged[b] = round2(free.filter(c => c.bucket === b).reduce((t, c) => t + c.free, 0));
    aged[`n${b}`] = free.filter(c => c.bucket === b).length;
  }

  const taxed = rows.filter(p => !p.cancelled_at && Number(p.wht_amount) > 0);
  const uncertified = taxed.filter(p => !String(p.wht_cert_no ?? '').trim());
  const cert = {
    payments: taxed.length, amount: round2(taxed.reduce((t, p) => t + Number(p.wht_amount), 0)),
    certified: taxed.length - uncertified.length,
    certified_amount: round2(taxed.filter(p => p.wht_cert_no).reduce((t, p) => t + Number(p.wht_amount), 0)),
    missing: uncertified.length, missing_amount: round2(uncertified.reduce((t, p) => t + Number(p.wht_amount), 0)),
    missing_rows: uncertified.map(p => ({ payment_no: p.payment_no, supplier: p.supplier.name, date: p.date, days: days(p.date), amount: round2(p.wht_amount), gross: round2(p.amount) })),
  };

  const applied = rows.filter(p => !p.cancelled_at && Number(p.allocated) > 0).map(p => ({ payment: p, on_file: round2(Number(p.allocated) - (drift && p.payment_no === 'PAY-2026-0105' ? 500 : 0)) }));
  const breaks = applied.filter(r => Math.abs(Number(r.payment.allocated) - r.on_file) > 0.005);
  const alloc = {
    payments: applied.length, allocated: round2(applied.reduce((t, r) => t + Number(r.payment.allocated), 0)),
    on_file: round2(applied.reduce((t, r) => t + r.on_file, 0)), breaks: breaks.length,
    diff: round2(applied.reduce((t, r) => t + Number(r.payment.allocated) - r.on_file, 0)),
    rows: breaks.map(r => ({ payment_no: r.payment.payment_no, supplier: r.payment.supplier.name, date: r.payment.date,
      allocated: round2(r.payment.allocated), on_file: r.on_file })),
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
      request: (key) => (key === undefined
        ? { except: (...skip) => Object.fromEntries(Object.entries(QUERY).filter(([k, v]) => v !== null && v !== '' && v !== false && !skip.includes(k))) }
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
      payments: new Pager(rows.map(p => ({ ...p, days: days(p.date), bucket: p.unapplied() > 0.004 ? bucketOf(days(p.date)) : null }))),
      suppliers: new Coll(SUPPLIERS),
      kpi: KPI,
      as_of: '2026-11-07',
      aged, cert, alloc,
      buckets: { b0: '0–30 days', b30: '31–60 days', b60: '61–90 days', b90: 'Over 90 days' },
      currentHotel: HOTEL,
    },
  };
}
