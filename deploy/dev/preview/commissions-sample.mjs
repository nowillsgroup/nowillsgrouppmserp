/**
 * Sample data for the agent commissions preview — resources/views/accounts/commissions.blade.php rendered the way
 * CommissionController::index() hands it over: the filtered rows with their age and bucket, the due population summarised by
 * agent, the aged totals, and v9.12's document control over the paid ones.
 *
 * Six commissions are outstanding as at 7 November 2026, across three agents, and two of them are old enough to worry
 * about — which is the point of ageing the screen:
 *
 *   agent                    rate   stays   room revenue    0–30      31–60     61–90     over 90        due   oldest
 *   Zenith Oil Ghana Ltd      10%       2        126,000       —      5,200         —       7,400      12,600   142 d
 *   Meridian Travel & Tours   12%       2         53,500   1,500          —         —       4,920       6,420   193 d
 *   Ashanti Builders Co-op     8%       2         60,000   1,760      3,040         —           —       4,800    56 d
 *   ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
 *   total                              6        239,500   3,260      8,240         —      12,320      23,820   193 d
 *
 * The buckets are ArService::bucketOf()'s rule over the days from each check-out to 7 November: 142 d and 193 d are over
 * ninety, 36 d and 56 d are in 31–60, 2 d and 18 d are in 0–30 — so each bucket holds two commissions and none sits in
 * 61–90, which is what the screen should show rather than a tidy spread.
 *
 * Four commissions have been paid, each naming its voucher (PMT-2026-0133, 0141, 0152, 0166 — 9,070.00), and one was
 * voided. The drift build is the fault the document control exists for: the 1,120.00 Ashanti commission marked paid with
 * no voucher behind it, so the card names it instead of trusting the status column.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const AS_OF = '2026-11-07';
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* the buckets are ArService's, so commissions age on the same 30/60/90 rule as the receivables */
export const BUCKETS = { b0: '0–30 days', b30: '31–60 days', b60: '61–90 days', b90: 'Over 90 days' };
const bucketOf = (days) => (days <= 30 ? 'b0' : (days <= 60 ? 'b30' : (days <= 90 ? 'b60' : 'b90')));

const AGENTS = {
  14: { id: 14, name: 'Zenith Oil Ghana Ltd', commission_pct: 10 },
  21: { id: 21, name: 'Ashanti Builders Co-op', commission_pct: 8 },
  33: { id: 33, name: 'Meridian Travel & Tours', commission_pct: 12 },
};

/* days from the check-out to the day being read */
const daysTo = (iso) => Math.max(0, Math.round((Date.parse(`${AS_OF}T00:00:00Z`) - Date.parse(`${iso}T00:00:00Z`)) / 86400000));

const commission = (id, companyId, iso, revenue, nights, room, guest, resNo, pct, amount) => {
  const days = daysTo(iso);
  return {
    id, status: 'due', business_date: day(iso), days, bucket: bucketOf(days),
    reservation_id: 5000 + id, reservation: { reservation_no: resNo, guest: { full_name: guest } },
    stay: room ? { room: { room_no: room } } : null,
    company: AGENTS[companyId], nights, room_revenue: revenue, pct, amount,
    voucher: null, paid_at: null, voucher_id: null,
  };
};

const DUE = [
  commission(9101, 14, '2026-06-18', 74000, 3, '112', 'Nana Ampofo', 'RES-2026-3341', 10, 7400),
  commission(9102, 14, '2026-10-02', 52000, 2, '204', 'Akosua Mensah', 'RES-2026-4102', 10, 5200),
  commission(9103, 21, '2026-09-12', 38000, 4, '118', 'Kofi Mensah', 'RES-2026-3877', 8, 3040),
  commission(9104, 21, '2026-10-20', 22000, 1, '301', 'Ama Serwaa', 'RES-2026-4288', 8, 1760),
  commission(9105, 33, '2026-04-28', 41000, 5, '109', 'Yaa Asantewaa', 'RES-2026-2905', 12, 4920),
  commission(9106, 33, '2026-11-05', 12500, 1, '215', 'Kwame Boateng', 'RES-2026-4410', 12, 1500),
];

const paidCommission = (id, companyId, iso, revenue, amount, voucherNo, paidOn, withVoucher = true) => ({
  id, status: 'paid', business_date: day(iso), days: daysTo(iso), bucket: bucketOf(daysTo(iso)),
  reservation_id: 5200 + id, reservation: { reservation_no: `RES-2026-3${id}`, guest: { full_name: 'Corporate guest' } }, stay: null,
  company: AGENTS[companyId], nights: 2, room_revenue: revenue, pct: AGENTS[companyId].commission_pct, amount,
  voucher: withVoucher ? { voucher_no: voucherNo } : null, voucher_id: withVoucher ? 700 + id : null,
  paid_at: withVoucher ? day(paidOn) : null,
});

const VOIDED = {
  id: 9109, status: 'void', business_date: day('2026-08-14'), days: daysTo('2026-08-14'), bucket: bucketOf(daysTo('2026-08-14')),
  reservation_id: 5309, reservation: { reservation_no: 'RES-2026-3690', guest: { full_name: 'Walk-in transfer' } }, stay: null,
  company: AGENTS[33], nights: 1, room_revenue: 6400, pct: 12, amount: 640, voucher: null, voucher_id: null, paid_at: null,
};

const round2 = (value) => Math.round(Number(value ?? 0) * 100) / 100;

/* Laravel hands the view a paginator: countable and iterable, with links() at the foot. */
class Pager extends Coll {
  links() {
    return raw('<nav class="pager" aria-label="Pages"><span class="current">1</span> <a href="?page=2">2</a></nav>');
  }
}

export function commissionsContext({ drift = false, asOf = AS_OF } = {}) {
  const due = DUE;
  const paid = [
    paidCommission(9201, 14, '2026-07-15', 36000, 3600, 'PMT-2026-0133', '2026-07-20'),
    paidCommission(9202, 21, '2026-07-30', 30625, 2450, 'PMT-2026-0141', '2026-08-04'),
    paidCommission(9203, 33, '2026-08-12', 15833, 1900, 'PMT-2026-0152', '2026-08-18'),
    // the drift build: the same 1,120.00 settlement, with nothing recording that it left the bank
    paidCommission(9204, 21, '2026-09-09', 14000, 1120, 'PMT-2026-0166', '2026-09-14', !drift),
  ];

  const grouped = new Map();
  for (const c of due) {
    if (!grouped.has(c.company.id)) grouped.set(c.company.id, { company: c.company, stays: 0, revenue: 0, amount: 0, oldest: 0, b0: 0, b30: 0, b60: 0, b90: 0 });
    const s = grouped.get(c.company.id);
    s.stays += 1;
    s.revenue = round2(s.revenue + c.room_revenue);
    s.amount = round2(s.amount + c.amount);
    s.oldest = Math.max(s.oldest, c.days);
    s[c.bucket] = round2(s[c.bucket] + c.amount);
  }
  const summary = [...grouped.values()].sort((a, b) => b.amount - a.amount);

  const aged = {
    as_of: asOf, due: round2(due.reduce((t, c) => t + c.amount, 0)), agents: summary.length,
    stays: due.length, oldest: Math.max(...due.map(c => c.days)),
  };
  for (const k of Object.keys(BUCKETS)) {
    aged[k] = round2(due.filter(c => c.bucket === k).reduce((t, c) => t + c.amount, 0));
    aged[`n${k}`] = due.filter(c => c.bucket === k).length;
  }

  const missing = paid.filter(c => !c.voucher);
  const doc = {
    paid: paid.length, paid_amount: round2(paid.reduce((t, c) => t + c.amount, 0)),
    documented: paid.filter(c => c.voucher).length, missing: missing.length,
    stray: 0,
    missing_rows: missing.map(c => ({ company: c.company.name, amount: c.amount })),
  };

  const money = (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money,
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
      request: (key) => (key === undefined ? { integer: () => 0, query: () => null, except: () => ({}) } : null),
      isset: value => value !== undefined && value !== null,
      old: (key, fallback = null) => fallback,
      e: value => String(value ?? ''),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : [])),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      rows: new Pager(due),
      summary: Coll.of(summary),
      status: 'due',
      asOf,
      aged,
      doc,
      buckets: BUCKETS,
      companies: { 14: AGENTS[14].name, 21: AGENTS[21].name, 33: AGENTS[33].name },
      paymentModes: { 1: 'Bank transfer', 2: 'Cheque', 3: 'Cash' },
      currentHotel: HOTEL,
    },
  };
}
