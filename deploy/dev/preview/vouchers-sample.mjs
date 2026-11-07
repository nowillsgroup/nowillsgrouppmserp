/**
 * Sample data for the voucher register preview — resources/views/accounts/vouchers.blade.php rendered the way
 * AccountsController::vouchers() hands it over: the period, the register itself, its analysis by account head, and v9.11's
 * tie between each head's vouchers and that head's posted movement.
 *
 * The period is 1–7 November 2026 and the register is the whole of it: two receipts of other income, one company receipt
 * the city ledger counts, and the eight payment vouchers behind the P&L's expense heads. Everything is derived from the
 * rows below the way the controller derives it, so the sample cannot drift from the rule it illustrates.
 *
 *   head                       vouchers   receipts   payments       net
 *   Other Income                      2   6,800.00         —     6,800.00
 *   City Ledger Receipts              1  12,000.00         —    12,000.00   ← 07 Nov, not posted yet
 *   Utilities                         2         —   18,400.00  −18,400.00
 *   Repairs & Maintenance             2         —    9,250.00   −9,250.00
 *   Cleaning Supplies                 1         —    5,600.00   −5,600.00
 *   Fuel                              1         —    7,800.00   −7,800.00
 *   Staff Welfare                     1         —    3,600.00   −3,600.00
 *   Stationery                        1         —    1,450.00   −1,450.00   ← 07 Nov, not posted yet
 *   ───────────────────────────────────────────────────────────────────────
 *   total                            11  18,800.00  46,100.00  −27,300.00
 *
 * The six payment heads total 46,100.00 — the six expense lines the P&L prints for the same week — and Other Income is
 * its 6,800.00 line. The city-ledger receipt is a voucher but not income, exactly as the P&L treats it.
 *
 * The tie is over vouchers dated on or before the posted-through day, 6 November: 9 of the 11, expecting 37,850.00 of
 * movement across six heads, which the ledger has. The drift build leaves the 7,800.00 fuel payment unposted, so Fuel is
 * named as the head that is out.
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

/* AccountHead::where('is_active', true)->orderBy('type')->orderBy('code') — the modal's optgroups, in order */
const headSpec = [
  { id: 11, code: '4010', name: 'Room Revenue', type: 'income' },
  { id: 12, code: '4900', name: 'Other Income', type: 'income' },
  { id: 13, code: '4020', name: 'City Ledger Receipts', type: 'income' },
  { id: 21, code: '5010', name: 'Utilities', type: 'expense' },
  { id: 22, code: '5020', name: 'Repairs & Maintenance', type: 'expense' },
  { id: 23, code: '5030', name: 'Cleaning Supplies', type: 'expense' },
  { id: 24, code: '5040', name: 'Fuel', type: 'expense' },
  { id: 25, code: '5050', name: 'Staff Welfare', type: 'expense' },
  { id: 26, code: '5060', name: 'Stationery', type: 'expense' },
];
// AccountHead::getLabelAttribute() — "code · name", which is what the filter and the modal print
export const HEADS = headSpec.map(h => ({ ...h, label: `${h.code} · ${h.name}`, is_active: true }));

const head = (id) => HEADS.find(h => h.id === id);

/* Voucher::with('head','paymentMode','supplier','user')->latest('date')->latest('id') — newest first, as the register prints */
const ROWS = [
  { no: 'RCPT-2026-0114', date: '2026-11-07', type: 'receipt', headId: 13, party: 'Ashanti Builders Co-op', supplier: null,
    mode: 'Bank transfer', reference: 'CHQ 004121', narration: 'City ledger settlement – September accommodation', amount: 12000 },
  { no: 'PMT-2026-0209', date: '2026-11-07', type: 'payment', headId: 26, party: null, supplier: 'Papyrus Press',
    mode: 'Cash', reference: 'INV-3391', narration: 'Guest directory reprint', amount: 1450 },
  { no: 'PMT-2026-0208', date: '2026-11-06', type: 'payment', headId: 25, party: 'Staff canteen', supplier: null,
    mode: 'Cash', reference: 'PV-118', narration: 'Canteen supplies – week 45', amount: 3600 },
  { no: 'PMT-2026-0206', date: '2026-11-06', type: 'payment', headId: 24, party: null, supplier: 'Total Energies',
    mode: 'Bank transfer', reference: 'INV-77821', narration: 'Generator diesel – 600 litres', amount: 7800 },
  { no: 'PMT-2026-0205', date: '2026-11-05', type: 'payment', headId: 23, party: null, supplier: 'Akosua Chemicals',
    mode: 'Cash', reference: 'INV-2210', narration: 'Laundry and housekeeping chemicals', amount: 5600 },
  { no: 'PMT-2026-0204', date: '2026-11-04', type: 'payment', headId: 22, party: null, supplier: 'Coastal Aircon Services',
    mode: 'Bank transfer', reference: 'INV-0455', narration: 'Chiller compressor service – block B', amount: 4000 },
  { no: 'PMT-2026-0203', date: '2026-11-03', type: 'payment', headId: 21, party: null, supplier: 'ECG',
    mode: 'Bank transfer', reference: 'INV-90114', narration: 'Electricity – October reading', amount: 9000 },
  { no: 'RCPT-2026-0111', date: '2026-11-03', type: 'receipt', headId: 12, party: 'Coconut Grove Pensions', supplier: null,
    mode: 'Bank transfer', reference: 'TRF-55210', narration: 'Conference hall hire – day delegate', amount: 2600 },
  { no: 'PMT-2026-0202', date: '2026-11-02', type: 'payment', headId: 22, party: null, supplier: 'Coastal Aircon Services',
    mode: 'Cash', reference: 'INV-0448', narration: 'Air-conditioner filters – 12 rooms', amount: 5250 },
  { no: 'RCPT-2026-0108', date: '2026-11-02', type: 'receipt', headId: 12, party: 'Garden Grill', supplier: null,
    mode: 'Cash', reference: 'RCPT-331', narration: 'Outlet space rental – November', amount: 4200 },
  { no: 'PMT-2026-0201', date: '2026-11-01', type: 'payment', headId: 21, party: null, supplier: 'Ghana Water Company',
    mode: 'Bank transfer', reference: 'INV-40118', narration: 'Water – October consumption', amount: 9400 },
];

const round2 = (value) => Math.round(Number(value ?? 0) * 100) / 100;
const POSTED_THROUGH = '2026-11-06';
// a voucher's date is a Carbon in the app and a SampleDate here: compare them as Y-m-d, which is what the controller does
const iso = (value) => (typeof value === 'string' ? value : (typeof value?.iso === 'string' ? value.iso : new Date(+value).toISOString().slice(0, 10)));

export function vouchersContext({ from = '2026-11-01', to = '2026-11-07', drift = false } = {}) {
  const vouchers = ROWS.map((r, i) => ({
    id: 2401 + i, voucher_no: r.no, date: day(r.date), type: r.type,
    head: head(r.headId), account_head_id: r.headId,
    supplier: r.supplier ? { name: r.supplier } : null, party_name: r.party,
    paymentMode: r.mode ? { name: r.mode } : null, reference: r.reference, narration: r.narration, amount: r.amount,
  }));

  const rec = round2(vouchers.filter(v => v.type === 'receipt').reduce((t, v) => t + v.amount, 0));
  const pay = round2(vouchers.filter(v => v.type === 'payment').reduce((t, v) => t + v.amount, 0));

  /* the analysis by head, built the way the controller builds it */
  const byHead = new Map();
  for (const v of vouchers) {
    if (!byHead.has(v.account_head_id)) byHead.set(v.account_head_id, { head: v.head.name, type: v.type, vouchers: 0, in: 0, out: 0 });
    const row = byHead.get(v.account_head_id);
    row.vouchers += 1;
    if (v.type === 'receipt') row.in = round2(row.in + v.amount); else row.out = round2(row.out + v.amount);
  }
  const byHeadRows = [...byHead.values()]
    .map(r => ({ ...r, net: round2(r.in - r.out) }))
    .sort((a, b) => a.head.localeCompare(b.head));

  /* the tie: each head's vouchers dated on or before the posted-through day, against that head's posted movement */
  const posted = vouchers.filter(v => iso(v.date) <= POSTED_THROUGH);
  const expected = new Map();
  for (const v of posted) {
    const movement = v.type === 'payment' ? v.amount : -1 * v.amount;
    if (!expected.has(v.head.name)) expected.set(v.head.name, { head: v.head.name, vouchers: 0, expected: 0 });
    const row = expected.get(v.head.name);
    row.vouchers += 1;
    row.expected = round2(row.expected + movement);
  }
  const ledgerRows = [...expected.values()].map(r => {
    // the drift build is the fuel payment the sync did not carry
    const missed = drift && r.head === 'Fuel';
    const actual = missed ? 0 : r.expected;
    return { ...r, actual, diff: round2(r.expected - actual) };
  }).sort((a, b) => a.head.localeCompare(b.head));

  const tie = {
    from, to, posted_through: POSTED_THROUGH,
    posted: posted.length, unposted: vouchers.length - posted.length,
    expected: round2(ledgerRows.reduce((t, r) => t + r.expected, 0)),
    actual: round2(ledgerRows.reduce((t, r) => t + r.actual, 0)),
    drift: ledgerRows.filter(r => Math.abs(r.diff) > 0.005),
  };
  tie.diff = round2(tie.expected - tie.actual);
  tie.ties = tie.drift.length === 0;
  tie.expected_abs = Math.abs(tie.expected);
  tie.actual_abs = Math.abs(tie.actual);
  tie.diff_abs = Math.abs(tie.diff);

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
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? v.voucher_no ?? '') : String(v));
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
      request: (key) => {
        if (key === undefined) return { except: () => ({}), integer: () => 0 };
        return null;
      },
      isset: value => value !== undefined && value !== null,
      old: (key, fallback = null) => fallback,
      e: value => String(value ?? ''),
      ucfirst: value => String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : [])),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      vouchers: Coll.of(vouchers),
      from, to, rec, pay,
      byHead: Coll.of(byHeadRows),
      ledger: Coll.of(ledgerRows),
      tie,
      heads: Coll.of(HEADS),
      // AccountHead::...->groupBy('type'): a keyed collection the modal reads as optgroups
      headGroups: { income: HEADS.filter(h => h.type === 'income'), expense: HEADS.filter(h => h.type === 'expense') },
      paymentModes: { 1: 'Bank transfer', 2: 'Cheque', 3: 'Cash' },
      suppliers: { 31: 'Ghana Water Company', 32: 'ECG', 33: 'Coastal Aircon Services', 34: 'Akosua Chemicals', 35: 'Total Energies', 36: 'Papyrus Press' },
      currentHotel: HOTEL,
    },
  };
}
