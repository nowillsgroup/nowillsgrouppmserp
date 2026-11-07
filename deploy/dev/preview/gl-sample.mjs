/**
 * Sample data for the General Ledger preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/gl/index.blade.php against this fixed sample. The shapes mirror what
 * GlController::index() passes to the view — the posted-up-to day, the last sync, the lock
 * date, the trial-balance totals, the control accounts compared with their sub-ledgers, and
 * the source documents that failed the last sync. Only the numbers are fixed.
 */
import { Coll, SampleDate } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const day = iso => new SampleDate(`${iso}T09:00:00Z`);
const BUSINESS_DATE = '2026-11-05';

/* Account ids for AccountHead::sys(), so a control row's link has somewhere to point. */
const SYS = { guest: 1100, city: 1110, inventory: 1200, ap: 2010, pos_clearing: 1150 };

// LedgerService::controlScreens(): the screen that owns each sub-ledger, where the reader should go to see why
const CONTROLS = [
  { key: 'guest', label: 'Guest ledger (in-house folios)', sub: 412880.40, gl: 412880.40, strict: true, screen: { route: 'folios.index', label: 'Guest folios' } },
  { key: 'city', label: 'City ledger (company accounts)', sub: 1186420.00, gl: 1186420.00, strict: true, screen: { route: 'accounts.receivables', label: 'Receivables ageing' } },
  { key: 'ap', label: 'Accounts payable (supplier balances)', sub: 863115.75, gl: 863115.75, strict: true, screen: { route: 'ap.ageing', label: 'Payables ageing' } },
  { key: 'pos_clearing', label: 'POS tills awaiting settlement', sub: 24710.00, gl: 24690.00, strict: true, screen: null },
  { key: 'inventory', label: 'Inventory at latest cost', sub: 301862.10, gl: 302480.60, strict: false, screen: { route: 'inventory.ledger', label: 'Stock ledger' } },
];

/* LedgerService::controlSummary(): the count, and the strict control that is out — asserted, not typed twice. */
const CONTROL_SUMMARY = (() => {
  const off = CONTROLS.filter(c => Math.abs(Math.round((c.gl - c.sub) * 100) / 100) >= 0.01);
  const strict = off.filter(c => c.strict).sort((a, b) => Math.abs(b.gl - b.sub) - Math.abs(a.gl - a.sub));
  const worst = strict[0];
  return {
    as_of: BUSINESS_DATE, total: CONTROLS.length, tied: CONTROLS.length - off.length,
    missed: strict.length, indicative: off.length - strict.length,
    worst: worst ? { key: worst.key, label: worst.label, diff: Math.round((worst.gl - worst.sub) * 100) / 100, screen: worst.screen } : null,
  };
})();

/* Trial balance, grouped the way the view groups it: one header row per account type. */
const TB = {
  'Assets': [
    { code: '1100', account: 'Guest Ledger', id: 1100, open_dr: 368420.10, open_cr: 0, dr: 1288930.55, cr: 1244470.25, close_dr: 412880.40, close_cr: 0 },
    { code: '1110', account: 'City Ledger Receivable', id: 1110, open_dr: 1098210.00, open_cr: 0, dr: 486300.00, cr: 398090.00, close_dr: 1186420.00, close_cr: 0 },
    { code: '1200', account: 'Inventory', id: 1200, open_dr: 296140.20, open_cr: 0, dr: 187220.40, cr: 180880.00, close_dr: 302480.60, close_cr: 0 },
    { code: '1120', account: 'Bank – GCB Main', id: 1120, open_dr: 1502440.00, open_cr: 0, dr: 902110.00, cr: 764330.45, close_dr: 1640219.55, close_cr: 0 },
  ],
  'Liabilities': [
    { code: '2010', account: 'Accounts Payable', id: 2010, open_dr: 0, open_cr: 741020.15, dr: 214300.00, cr: 336395.60, close_dr: 0, close_cr: 863115.75 },
    { code: '2100', account: 'Output Tax Payable', id: 2100, open_dr: 0, open_cr: 86420.30, dr: 0, cr: 24110.20, close_dr: 0, close_cr: 110530.50 },
    { code: '2130', account: 'Membership Fees in Advance', id: 2130, open_dr: 0, open_cr: 96400.00, dr: 14200.00, cr: 22800.00, close_dr: 0, close_cr: 105000.00 },
  ],
  'Income': [
    { code: '4010', account: 'Room Revenue', id: 4010, open_dr: 0, open_cr: 0, dr: 0, cr: 1846220.00, close_dr: 0, close_cr: 1846220.00 },
    { code: '4020', account: 'Food Revenue', id: 4020, open_dr: 0, open_cr: 0, dr: 0, cr: 612480.50, close_dr: 0, close_cr: 612480.50 },
    { code: '4042', account: 'Health Club Membership Revenue', id: 4042, open_dr: 0, open_cr: 0, dr: 0, cr: 184300.00, close_dr: 0, close_cr: 184300.00 },
  ],
  'Expenses': [
    { code: '5010', account: 'Kitchen & Bar Cost', id: 5010, open_dr: 0, open_cr: 0, dr: 214860.30, cr: 0, close_dr: 214860.30, close_cr: 0 },
    { code: '5020', account: 'Housekeeping Supplies', id: 5020, open_dr: 0, open_cr: 0, dr: 68420.00, cr: 0, close_dr: 68420.00, close_cr: 0 },
    { code: '5040', account: 'Wastage & Stock Variance', id: 5040, open_dr: 0, open_cr: 0, dr: 9310.75, cr: 0, close_dr: 9310.75, close_cr: 0 },
  ],
};

const sum = rows => rows.reduce((total, row) => total + Number(row), 0);

// The screen computes its own trial balance from the rows it prints: if the sample does not balance,
// the preview shows a red "Off by" card. The sample is invented, so it is made to balance by
// construction — one equity line carries whatever the visible accounts leave over, which is what a
// real chart of accounts does with retained earnings.
const rows0 = Object.values(TB).flat();
const openGap = sum(rows0.map(row => row.open_dr)) - sum(rows0.map(row => row.open_cr));
const closeGap = sum(rows0.map(row => row.close_dr)) - sum(rows0.map(row => row.close_cr));
const move = closeGap - openGap;
TB['Equity'] = [{
  code: '3100', account: 'Retained Earnings', id: 3100,
  open_dr: 0, open_cr: Math.round(openGap * 100) / 100,
  dr: move < 0 ? Math.round(-move * 100) / 100 : 0,
  cr: move > 0 ? Math.round(move * 100) / 100 : 0,
  close_dr: 0, close_cr: Math.round(closeGap * 100) / 100,
}];

export function glIndexContext() {
  const rows = Object.values(TB).flat();
  const totals = {
    dr: sum(rows.map(row => row.dr)),
    cr: sum(rows.map(row => row.cr)),
    close_dr: sum(rows.map(row => row.close_dr)),
    close_cr: sum(rows.map(row => row.close_cr)),
  };

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      route: name => `#${String(name).replace(/\./g, '-')}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      bdate: () => day(BUSINESS_DATE),
      now: () => new SampleDate('2026-11-05T08:40:00Z'),
      // dmy() takes a Carbon or a plain ISO date, and prints 'd M Y' either way
      dmy: value => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        const d = new Date(`${String(value).slice(0, 10)}T00:00:00Z`);
        if (Number.isNaN(d.getTime())) return String(value);
        const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()];
        return `${String(d.getUTCDate()).padStart(2, '0')} ${m} ${d.getUTCFullYear()}`;
      },
      setting: (key, fallback = null) => ({ gl_input_tax: '1' }[key] ?? fallback),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      array_slice: (list, start, length) => (Array.isArray(list) ? list.slice(start, length === undefined ? undefined : start + length) : []),
      abs: value => Math.abs(Number(value ?? 0)),
      round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
      max: (...values) => Math.max(...values.flat()),
      min: (...values) => Math.min(...values.flat()),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'AccountHead' && method === 'sys') return SYS[args[0]] ?? 0;
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      cutoff: '2026-11-04',
      nextCutoff: '2026-11-05',
      syncedAt: '2026-11-05 03:12:00',
      journalCount: 2431,
      lock: '2026-10-31',
      totals,
      glErrors: [
        "Folio #4821: charge category 'spa' has no revenue account mapped",
        'Voucher #119: dated 12 Aug 2026, on or before the lock date',
        'Purchase order PO-2291: no goods received to match against the bill',
      ],
      controls: Coll.of(CONTROLS.map(row => ({ ...row }))),
      controlSummary: CONTROL_SUMMARY,
      from: '2026-11-01',
      to: '2026-11-04',
      tb: TB,
      currentHotel: HOTEL,
    },
  };
}
