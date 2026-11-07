/**
 * Sample data for the expense claims preview — resources/views/claims/index.blade.php rendered the way
 * ExpenseClaimController::index() hands it over: the two queues aged, the receipt rule read back over the live claims, the
 * claim window re-tested, and the claim list.
 *
 * Twelve claims as at 7 November 2026. Eight of them are in one of the two queues and they age from the day the queue
 * started, not from the day the claim was touched:
 *
 *   queue               claims     money   0–7 days   8–14 days   15–30 days   over 30   oldest
 *   Awaiting approval        5   4,510      1,800          940          310     1,460    33 d
 *   Awaiting payment         3   1,580        780          260          540         —    22 d
 *   ─────────────────────────────────────────────────────────────────────────────────────────────
 *   total                    8   6,090      2,580        1,200          850     1,460    33 d
 *
 * The worst approval is CLM-2026-0104 — Kofi Mensah's September market runs, 1,460.00, sent in on 5 October and still
 * unanswered; the worst payment is CLM-2026-0112, Yaw Boateng's generator fuel, approved 16 October and unpaid.
 *
 * Sixteen lines on live claims are over the 50.00 receipt threshold, 8,140.00 in all: twelve carry a receipt (7,220.00)
 * and four a transaction number (920.00), none has neither. Mileage is outside the population — it is paid at the rate and
 * needs no receipt, exactly as ExpenseClaimService::addLine() checks. No line was older than 27 days when its claim was
 * submitted, against a 60-day window.
 *
 * The drift build is the fault the receipt rule exists for: the 940.00 pump-parts line on CLM-2026-0131 losing both its
 * receipt and its reference — a line that cannot be entered but can certainly be inherited, and which the screen used to
 * show as an ordinary expense.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const AS_OF = Date.parse('2026-11-07T00:00:00Z');
const daysTo = (iso) => Math.max(0, Math.round((AS_OF - Date.parse(`${iso}T00:00:00Z`)) / 86400000));
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

export const CFG = { claims_mileage_rate: '2.50', claims_receipt_threshold: '50', claims_max_age_days: '60' };

export const WAIT_BUCKETS = { w0: '0–7 days', w1: '8–14 days', w2: '15–30 days', w3: 'Over 30 days' };
const waitBucket = (days) => (days <= 7 ? 'w0' : (days <= 14 ? 'w1' : (days <= 30 ? 'w2' : 'w3')));

const STAFF = {
  kwesi: { id: 41, name: 'Kwesi Appiah', department: { id: 1, name: 'Front Office' } },
  ama: { id: 42, name: 'Ama Serwaa', department: { id: 1, name: 'Front Office' } },
  yaw: { id: 43, name: 'Yaw Boateng', department: { id: 3, name: 'Maintenance' } },
  efua: { id: 44, name: 'Efua Mensah', department: { id: 4, name: 'Housekeeping' } },
  kofi: { id: 45, name: 'Kofi Mensah', department: { id: 2, name: 'Kitchen' } },
};

/* the twelve claims, in the order the list shows them (most recently touched first) */
const CLAIMS = [
  { no: 'CLM-2026-0144', staff: 'ama', title: 'Airtime top-up for the duty phone', status: 'draft', total: 0, vat: 0, updated: '2026-11-07', lines: 0 },
  { no: 'CLM-2026-0142', staff: 'kwesi', title: 'Kumasi supplier visit, 03–04 Nov', status: 'submitted', total: 1180, vat: 40, submitted: '2026-11-06', updated: '2026-11-06', lines: 3 },
  { no: 'CLM-2026-0136', staff: 'kwesi', title: 'Client dinner at the Golden Tulip', status: 'approved', total: 780, vat: 60, submitted: '2026-11-02', approved: '2026-11-04', updated: '2026-11-04', lines: 1 },
  { no: 'CLM-2026-0121', staff: 'efua', title: 'Accommodation and meals, Takoradi audit', status: 'paid', total: 1190, vat: 55, submitted: '2026-10-28', approved: '2026-11-01', paid: '2026-11-03', updated: '2026-11-03', lines: 2 },
  { no: 'CLM-2026-0139', staff: 'ama', title: 'Airport transfers for the Lagos group', status: 'submitted', total: 620, vat: 0, submitted: '2026-11-02', updated: '2026-11-02', lines: 1 },
  { no: 'CLM-2026-0129', staff: 'ama', title: 'Airtime & data, October', status: 'approved', total: 260, vat: 0, submitted: '2026-10-24', approved: '2026-10-27', updated: '2026-10-27', lines: 1 },
  { no: 'CLM-2026-0131', staff: 'yaw', title: 'Pump parts from Takoradi', status: 'submitted', total: 940, vat: 0, submitted: '2026-10-26', updated: '2026-10-26', lines: 1 },
  { no: 'CLM-2026-0126', staff: 'kwesi', title: 'Taxi to the customs office', status: 'rejected', total: 420, vat: 0, submitted: '2026-10-20', updated: '2026-10-22', lines: 3, reject: 'No receipt on the taxi line — resubmit with it' },
  { no: 'CLM-2026-0115', staff: 'kofi', title: 'Gas cylinder refills, kitchen', status: 'paid', total: 860, vat: 0, submitted: '2026-10-16', approved: '2026-10-19', paid: '2026-10-21', updated: '2026-10-21', lines: 1 },
  { no: 'CLM-2026-0118', staff: 'efua', title: 'Linen transport, three trips', status: 'submitted', total: 310, vat: 0, submitted: '2026-10-18', updated: '2026-10-18', lines: 2 },
  { no: 'CLM-2026-0112', staff: 'yaw', title: 'Generator fuel, night shift', status: 'approved', total: 540, vat: 0, submitted: '2026-10-13', approved: '2026-10-16', updated: '2026-10-16', lines: 1 },
  { no: 'CLM-2026-0104', staff: 'kofi', title: 'Market runs, 08–12 Sep', status: 'submitted', total: 1460, vat: 0, submitted: '2026-10-05', updated: '2026-10-05', lines: 3 },
];

/* every line over the 50.00 threshold on a live claim; mileage is outside the population by the service's own rule */
const LINES = [
  { claim: 'CLM-2026-0142', date: '2026-11-04', category: 'accommodation', description: 'Guest house, Kumasi — two nights', amount: 490, evidence: 'receipt' },
  { claim: 'CLM-2026-0142', date: '2026-11-03', category: 'transport', description: 'Trotro and taxi, Accra–Kumasi', amount: 480, evidence: 'receipt' },
  { claim: 'CLM-2026-0142', date: '2026-11-03', category: 'meals', description: 'Meals on duty, two days', amount: 210, evidence: 'ref' },
  { claim: 'CLM-2026-0139', date: '2026-11-02', category: 'transport', description: 'Four airport runs, Lagos group', amount: 620, evidence: 'receipt' },
  { claim: 'CLM-2026-0136', date: '2026-11-01', category: 'entertainment', description: 'Client dinner, six covers', amount: 780, evidence: 'receipt' },
  { claim: 'CLM-2026-0121', date: '2026-10-28', category: 'meals', description: 'Meals on duty, audit week', amount: 300, evidence: 'receipt' },
  { claim: 'CLM-2026-0121', date: '2026-10-27', category: 'accommodation', description: 'Lodge, Takoradi — three nights', amount: 890, evidence: 'receipt' },
  { claim: 'CLM-2026-0131', date: '2026-10-26', category: 'repairs', description: 'Pump parts from Takoradi', amount: 940, evidence: 'receipt' },
  { claim: 'CLM-2026-0129', date: '2026-10-26', category: 'airtime', description: 'Airtime and data bundle, October', amount: 260, evidence: 'ref' },
  { claim: 'CLM-2026-0115', date: '2026-10-20', category: 'other', description: 'Gas cylinder refills, kitchen', amount: 860, evidence: 'receipt' },
  { claim: 'CLM-2026-0118', date: '2026-10-18', category: 'transport', description: 'Linen van, Tuesdays and Fridays', amount: 180, evidence: 'receipt' },
  { claim: 'CLM-2026-0118', date: '2026-10-17', category: 'transport', description: 'Linen van, extra Saturday run', amount: 130, evidence: 'ref' },
  { claim: 'CLM-2026-0112', date: '2026-10-15', category: 'fuel', description: 'Diesel for the standby generator', amount: 540, evidence: 'receipt' },
  { claim: 'CLM-2026-0104', date: '2026-09-12', category: 'fuel', description: 'Fuel for the market run', amount: 620, evidence: 'receipt' },
  { claim: 'CLM-2026-0104', date: '2026-09-10', category: 'fuel', description: 'Fuel for the market run', amount: 520, evidence: 'receipt' },
  { claim: 'CLM-2026-0104', date: '2026-09-08', category: 'stationery', description: 'Market bags and wrapping', amount: 320, evidence: 'ref' },
];

/* the paginator Laravel hands a list view */
class Page extends Coll {
  hasPages() { return false; }
  links() { return raw(''); }
}

export function claimsContext({ drift = false } = {}) {
  const waitingOf = (c) => (c.status === 'submitted' ? daysTo(c.submitted) : (c.status === 'approved' ? daysTo(c.approved) : null));
  const claims = CLAIMS.map(c => ({
    id: 700 + Number(String(c.no).slice(-3)), claim_no: c.no, title: c.title, status: c.status,
    total: round2(c.total), vat_total: round2(c.vat ?? 0), lines_count: c.lines,
    submitted_at: c.submitted ? day(c.submitted) : null, approved_on: c.approved ? day(c.approved) : null,
    paid_on: c.paid ? day(c.paid) : null, reject_reason: c.reject ?? null,
    employee: STAFF[c.staff], approver: c.approved ? { name: 'Nana Owusu' } : null,
    updated_at: day(c.updated), waiting: waitingOf(c), bucket: waitingOf(c) === null ? null : waitBucket(waitingOf(c)),
  }));

  const of = (status) => claims.filter(c => c.status === status);
  const counts = {
    submitted: { n: of('submitted').length, t: round2(of('submitted').reduce((t, c) => t + c.total, 0)) },
    approved: { n: of('approved').length, t: round2(of('approved').reduce((t, c) => t + c.total, 0)) },
    rejected: { n: of('rejected').length, t: round2(of('rejected').reduce((t, c) => t + c.total, 0)) },
    paid: { n: of('paid').length, t: round2(of('paid').reduce((t, c) => t + c.total, 0)) },
  };

  const queue = {};
  for (const [key, label] of [['submitted', 'Awaiting approval'], ['approved', 'Awaiting payment']]) {
    const rows = of(key);
    const worst = [...rows].sort((a, b) => b.waiting - a.waiting)[0] ?? null;
    const row = {
      key, label, claims: rows.length, amount: round2(rows.reduce((t, c) => t + c.total, 0)),
      oldest: Math.max(0, ...rows.map(c => c.waiting ?? 0)),
      worst: worst ? { claim_no: worst.claim_no, staff: worst.employee.name, amount: worst.total, days: worst.waiting } : null,
    };
    for (const b of Object.keys(WAIT_BUCKETS)) {
      const inB = rows.filter(c => c.bucket === b);
      row[b] = round2(inB.reduce((t, c) => t + c.total, 0));
      row[`n${b}`] = inB.length;
    }
    queue[key] = row;
  }

  const evidence = LINES.map(l => {
    const ev = drift && l.claim === 'CLM-2026-0131' ? 'none' : l.evidence;
    const claim = claims.find(c => c.claim_no === l.claim);
    return {
      claim_no: l.claim, staff: claim.employee.name, date: day(l.date), age: daysTo(l.date), category: l.category,
      amount: round2(l.amount), description: l.description, status: claim.status, evidence: ev,
    };
  });
  const gaps = evidence.filter(e => e.evidence === 'none');
  const receipt = {
    threshold: 50, needing: evidence.length,
    with_receipt: evidence.filter(e => e.evidence === 'receipt').length,
    with_ref: evidence.filter(e => e.evidence === 'ref').length,
    missing: gaps.length,
    amount: round2(evidence.reduce((t, e) => t + e.amount, 0)),
    missing_amount: round2(gaps.reduce((t, e) => t + e.amount, 0)),
    oldest: Math.max(...evidence.map(e => e.age)),
    gap_rows: gaps.slice(0, 10),
  };

  /* the window, re-tested against the day each claim was submitted */
  let oldestAtSubmit = 0;
  const late = [];
  for (const c of claims.filter(c => ['submitted', 'approved', 'paid'].includes(c.status) && c.submitted_at)) {
    for (const l of LINES.filter(l => l.claim === c.claim_no)) {
      const age = Math.round((Date.parse(`${c.submitted_at.toDateString().slice(0, 10)}T00:00:00Z`) - Date.parse(`${l.date}T00:00:00Z`)) / 86400000);
      oldestAtSubmit = Math.max(oldestAtSubmit, age);
      if (age > 60) late.push({ claim_no: c.claim_no, staff: c.employee.name, date: day(l.date), age, amount: round2(l.amount), description: l.description });
    }
  }
  const windowDoc = { window: 60, oldest: oldestAtSubmit, late: late.length, late_amount: round2(late.reduce((t, l) => t + l.amount, 0)), rows: late.slice(0, 10) };

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
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : (value && typeof value === 'object' ? Object.values(value) : []))),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      claims: new Page(claims),
      queue,
      waitBuckets: WAIT_BUCKETS,
      receipt,
      windowDoc,
      counts,
      status: 'submitted',
      q: '',
      mine: false,
      employees: { 41: 'Kwasi Appiah (EMP-041)' },
      cfg: CFG,
      me: { id: 42, name: 'Ama Serwaa', emp_code: 'EMP-042' },
      currentHotel: HOTEL,
    },
  };
}
