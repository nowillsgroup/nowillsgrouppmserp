/**
 * Sample data for the petty cash preview — resources/views/petty/index.blade.php rendered the way
 * PettyCashController::index() hands it over: the floats with their stats, the tin-against-ledger control, the receipt
 * control over every spend above the threshold, and the aged register.
 *
 * Four floats on the imprest system, 7 November 2026:
 *
 *   float               custodian      imprest   cash in tin   IOUs out   spend since top-up   top-up due   ledger   waiting
 *   Front Office        Ama Serwaa       5,000         1,200      1,800                2,000        2,000      420       780
 *   Kitchen & Stores    Kofi Mensah      3,000         1,860        640                  500          500    1,220       640
 *   Maintenance         Yaw Boateng      2,000           320          —                1,680        1,680      320         —
 *   Bar (closed)        —                1,500         1,500          —                    —            —    1,500         —
 *   ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
 *   total                               11,500         4,880      2,440                4,180        4,180    3,460     1,420
 *
 * Cash + IOUs + spend = imprest on every row, which is the imprest identity the register foots to. The ledger is
 * 1,420.00 behind the tins because the night audit has closed 06 November and the two 07 November movements have not
 * posted yet — so the tie is clean: 1,420.00 gap, 1,420.00 waiting, 0.00 unaccounted for.
 *
 * The receipt threshold is 100.00 and all eight spends since the last top-ups are above it, totalling 4,180.00: six carry
 * a receipt (3,560.00), two state why they have none (620.00), none has neither. The drift build is the fault that control
 * exists for: the 640.00 water bill losing its receipt, so 640.00 left the Front Office tin with nothing behind it —
 * and the imprest and ledger ties are untouched by it, because those are different questions.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const AS_OF = Date.parse('2026-11-07T00:00:00Z');

const daysTo = (iso) => Math.max(0, Math.round((AS_OF - Date.parse(`${iso}T00:00:00Z`)) / 86400000));
const round2 = (value) => Math.round(Number(value ?? 0) * 100) / 100;
const sum = (rows, key) => round2(rows.reduce((t, r) => t + Number(r[key] ?? 0), 0));

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

export const CFG = {
  petty_receipt_threshold: '100', petty_iou_days: '3', petty_count_days: '7',
  petty_low_pct: '25', petty_variance_tolerance: '1.00',
};

/* Every spend since the last top-up, all of them above the 100.00 threshold — six receipts, two stated reasons. */
const SPENDS = [
  { code: 'FO', date: '2026-11-05', txn_no: 'PV-2026-0408', payee: "Adjoa's Provisions", head: 'Cleaning materials', amount: 320 },
  { code: 'FO', date: '2026-11-04', txn_no: 'PV-2026-0405', payee: 'Ghana Post', head: 'Postage & courier', amount: 180, reason: 'Counterfoil kept at reception' },
  { code: 'FO', date: '2026-11-03', txn_no: 'PV-2026-0396', payee: 'Ghana Water Company', head: 'Utilities', amount: 640 },
  { code: 'FO', date: '2026-10-31', txn_no: 'PV-2026-0388', payee: 'City Express', head: 'Guest transfers', amount: 860 },
  { code: 'KIT', date: '2026-10-27', txn_no: 'PV-2026-0377', payee: "Adjoa's Provisions", head: 'Kitchen supplies', amount: 300 },
  { code: 'KIT', date: '2026-10-22', txn_no: 'PV-2026-0364', payee: 'Kaneshie Fish Market', head: 'Food cost', amount: 200 },
  { code: 'MNT', date: '2026-10-29', txn_no: 'PV-2026-0384', payee: 'Hardware Plus', head: 'Repairs & maintenance', amount: 1240 },
  { code: 'MNT', date: '2026-10-28', txn_no: 'PV-2026-0381', payee: 'Total Filling Station', head: 'Fuel & lubricants', amount: 440, reason: 'Attendant had no printer; ticket photographed' },
];

/* the four tins: imprest, cash, IOUs out and spend since the last top-up always add back to the imprest */
const FLOATS = [
  { id: 11, code: 'FO', name: 'Front Office', custodian: 'Ama Serwaa', department: 'Front Office', is_active: true,
    imprest: 5000, cash: 1200, ious: [1200, 600], spend: 2000, ledger: 420, waiting: 780, count_age: 3, pending: 780, overdue: 1 },
  { id: 12, code: 'KIT', name: 'Kitchen & Stores', custodian: 'Kofi Mensah', department: 'Kitchen', is_active: true,
    imprest: 3000, cash: 1860, ious: [640], spend: 500, ledger: 1220, waiting: 640, count_age: 11, pending: 0, overdue: 0 },
  { id: 13, code: 'MNT', name: 'Maintenance', custodian: 'Yaw Boateng', department: 'Maintenance', is_active: true,
    imprest: 2000, cash: 320, ious: [], spend: 1680, ledger: 320, waiting: 0, count_age: 15, pending: 0, overdue: 0 },
  { id: 14, code: 'BAR', name: 'Bar', custodian: null, department: 'Food & Beverage', is_active: false,
    imprest: 1500, cash: 1500, ious: [], spend: 0, ledger: 1500, waiting: 0, count_age: null, pending: 0, overdue: 0 },
];

const floatObject = (f) => ({
  id: f.id, code: f.code, name: f.name, is_active: f.is_active, imprest_amount: f.imprest, voucher_limit: 500,
  account_head_id: 200 + f.id, custodian_id: f.custodian ? 40 + f.id : null,
  custodian: f.custodian ? { id: 40 + f.id, name: f.custodian } : null,
  department: { id: f.id, name: f.department },
});

const toneOf = (f) => (!f.is_active ? 'slate' : (f.low || f.overdue ? 'red' : (f.pending > 0 || f.count_due ? 'amber' : 'green')));

export function pettyContext({ drift = false } = {}) {
  const register = FLOATS.map(f => {
    const ious = f.ious.length;
    return {
      float: floatObject(f), tin: round2(f.cash), ledger: round2(f.ledger), waiting: round2(f.waiting),
      unexplained: round2(f.cash - f.ledger - f.waiting), imprest: round2(f.imprest),
      ious: round2(f.ious.reduce((t, a) => t + a, 0)), ious_count: ious, overdue: f.overdue,
      spend: round2(f.spend), top_up: round2(f.ious.length || f.spend ? Math.max(0, f.imprest - f.cash - f.ious.reduce((t, a) => t + a, 0)) : 0),
      pct: Math.round(100 * f.cash / f.imprest), pending: round2(f.pending),
      low: f.cash < f.imprest * 25 / 100, count_age: f.count_age, count_due: f.count_age === null || f.count_age > 7,
      spend_age: f.spend ? Math.max(...SPENDS.filter(s => s.code === f.code).map(s => daysTo(s.date))) : null,
      active: f.is_active,
      tone: toneOf({ ...f, low: f.cash < f.imprest * 25 / 100, count_due: f.count_age === null || f.count_age > 7 }),
    };
  });

  const registerColl = Coll.of(register);

  const tie = {
    tin: sum(register, 'tin'), ledger: sum(register, 'ledger'), waiting: sum(register, 'waiting'),
    unexplained: sum(register, 'unexplained'), floats: register.length, active: register.filter(r => r.active).length,
    counted: register.filter(r => r.count_age !== null).length,
    oldest_count: registerColl.max('count_age'), under: register.filter(r => r.low).length,
    with_gap: Coll.of(register.filter(r => Math.abs(r.unexplained) >= 0.005)),
  };
  tie.gap = round2(tie.tin - tie.ledger);

  const evidence = SPENDS.map(s => {
    const withReceipt = !(drift && s.code === 'FO' && s.amount === 640);
    return {
      date: s.date, age: daysTo(s.date), float: FLOATS.find(f => f.code === s.code).name, txn_no: s.txn_no,
      payee: s.payee, head: s.head, amount: s.amount,
      evidence: withReceipt ? (s.reason ? 'reason' : 'receipt') : 'none',
      reason: withReceipt ? s.reason : null, status: 'posted', receipt: withReceipt && !s.reason,
    };
  });

  const missingRows = evidence.filter(e => e.evidence === 'none');
  const receipt = {
    threshold: 100, needing: evidence.length,
    with: evidence.filter(e => e.evidence === 'receipt').length,
    stated: evidence.filter(e => e.evidence === 'reason').length,
    missing: missingRows.length, amount: sum(evidence, 'amount'),
    with_amount: round2(evidence.filter(e => e.evidence === 'receipt').reduce((t, e) => t + e.amount, 0)),
    missing_amount: round2(missingRows.reduce((t, e) => t + e.amount, 0)),
    oldest: Math.max(...evidence.map(e => e.age)),
    missing_rows: missingRows.map(e => ({ float: e.float, txn_no: e.txn_no, payee: e.payee, amount: e.amount, age: e.age })),
  };

  const approvals = {
    txns: Coll.of([{
      id: 311, txn_no: 'PV-2026-0412', type: 'expense', typeLabel: () => 'Voucher', status: 'pending',
      float: { name: 'Front Office' }, amount: 780, date: day('2026-11-07'), description: 'Repair of the lobby air-conditioner remote',
      party: () => 'Ama Serwaa', head: { label: 'Repairs & maintenance' }, creator: { name: 'Ama Serwaa' }, receipt_path: null,
    }]),
    repl: Coll.of([{
      id: 88, repl_no: 'RPL-2026-0088', status: 'approved', float: { name: 'Front Office', imprest_amount: 5000 },
      amount: 1960, date: day('2026-11-06'), requester: { name: 'Ama Serwaa' },
    }]),
    counts: Coll.of([{
      id: 34, count_no: 'CNT-2026-0034', float: { name: 'Maintenance' }, counted: 280, book: 320, variance: -40,
      note: 'Short after the pump repair', date: day('2026-11-07'), counter: { name: 'Yaw Boateng' },
    }]),
  };

  const overdue = Coll.of([
    { txn_no: 'IOU-2026-0117', petty_float_id: 11, float: { name: 'Front Office' }, employee: { name: 'Kwesi Appiah' },
      description: 'Transport to Tarkwa for the site visit', amount: 1200, due_date: day('2026-11-01') },
  ]);

  /* Laravel hands the view a paginator: countable and iterable, with the pager at the foot of the card. */
  class Pager extends Coll {
    links() {
      return raw('<nav class="pager" aria-label="Pages"><span class="current">1</span> <a href="?evidence=2">2</a></nav>');
    }
  }
  const pages = new Pager(evidence.slice(0, 8));

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
      floats: Coll.of(FLOATS.map(floatObject)),
      stats: Object.fromEntries(FLOATS.map(f => [f.id, { balance: f.cash, pending: f.pending }])),
      register: registerColl,
      tie,
      receipt,
      evidence: pages,
      approvals,
      overdue,
      cfg: CFG,
      heads: { 6100: 'Repairs & maintenance' },
      modes: { 1: 'Bank transfer', 2: 'Cheque', 3: 'Cash' },
      employees: { 41: 'Kwesi Appiah (EMP-041)' },
      users: { 51: 'Ama Serwaa', 52: 'Kofi Mensah' },
      departments: { 1: 'Front Office', 2: 'Kitchen', 3: 'Maintenance', 4: 'Food & Beverage' },
      currentHotel: HOTEL,
    },
  };
}
