/**
 * Sample data for the daily cash book preview — resources/views/accounts/cashbook.blade.php rendered the way
 * AccountsController::cashBook() hands it over: the by-mode opening/movement/closing chain, the day's rows from the three
 * places money moves, the day's movement by source, and where the day sits in the ledger.
 *
 * Ashanti Builders Co-op pays its city-ledger account by transfer on the same day the tills take a Saturday's worth of
 * cash, cards and MoMo, which is why the book has four modes to carry:
 *
 *   mode            brought forward   receipts   payments   carried forward
 *   Cash                   4,150.00   1,200.00     300.00           5,050.00
 *   Bank transfer         52,300.00  12,000.00   8,400.00          55,900.00
 *   Card                  18,400.00   6,750.00          —          25,150.00
 *   MoMo                   7,200.00   2,300.00          —           9,500.00
 *   ──────────────────────────────────────────────────────────────────────
 *   total                 82,050.00  22,250.00   8,700.00          95,600.00
 *
 * 82,050.00 + 22,250.00 − 8,700.00 = 95,600.00, and the day's net 13,550.00 is the three sources' nets: front office
 * 900.00, POS 9,050.00, vouchers 3,600.00.
 *
 * The overdrawn build is the fault a cash book exists to catch: the same day with a 5,600.00 refund out of the cash
 * drawer rather than 300.00, so Cash carries forward −250.00 — a till cannot pay out more than it held.
 *
 * "Today" is 7 November 2026, and the ledger is posted through 6 November, so this day is still open.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* what the tills held at the close of 6 November, per mode — the book's brought forward */
const OPENING = { Cash: 4150, 'Bank transfer': 52300, Card: 18400, MoMo: 7200 };

/* the day's rows, in the order the controller sorts them (by date, then the sources' own order) */
const dayRows = (overdrawn) => [
  { src: 'Front Office', ref: 'FL-8842', desc: 'Room payment – Ama Serwaa', mode: 'Cash', in: 1200, out: 0 },
  { src: 'Front Office', ref: 'REF-0092', desc: 'Refund – early departure', mode: 'Cash', in: 0, out: overdrawn ? 5600 : 300 },
  { src: 'POS', ref: 'POS-2026-1188', desc: 'Garden Grill', mode: 'Card', in: 4150, out: 0 },
  { src: 'POS', ref: 'POS-2026-1189', desc: 'Beach Bar', mode: 'Card', in: 2600, out: 0 },
  { src: 'POS', ref: 'POS-2026-1190', desc: 'Palm Café', mode: 'MoMo', in: 2300, out: 0 },
  { src: 'Voucher', ref: 'RCPT-2026-0114', desc: 'City ledger receipt Ashanti Builders Co-op', mode: 'Bank transfer', in: 12000, out: 0 },
  { src: 'Voucher', ref: 'PMT-2026-0207', desc: 'Ashanti Water Ltd November supply', mode: 'Bank transfer', in: 0, out: 8400 },
];

const round2 = (value) => Math.round(Number(value ?? 0) * 100) / 100;

export function cashBookContext({ date = '2026-11-07', overdrawn = false } = {}) {
  const rows = dayRows(overdrawn);

  /* the controller's per-mode chain: opening from the book before the day, then the day's receipts and payments */
  const modes = new Map();
  const touch = (mode) => {
    if (!modes.has(mode)) modes.set(mode, { mode, open: OPENING[mode] ?? 0, in: 0, out: 0 });
    return modes.get(mode);
  };
  for (const mode of Object.keys(OPENING)) touch(mode);
  for (const r of rows) {
    const m = touch(r.mode);
    m.in = round2(m.in + r.in);
    m.out = round2(m.out + r.out);
  }
  const modeRows = [...modes.values()]
    .map(m => ({ ...m, close: round2(m.open + m.in - m.out), over: round2(m.open + m.in - m.out) < -0.005 }))
    .sort((a, b) => `${a.mode === 'Cash' ? '0' : '1'}${a.mode}`.localeCompare(`${b.mode === 'Cash' ? '0' : '1'}${b.mode}`));
  const totals = {
    open: round2(modeRows.reduce((t, m) => t + m.open, 0)),
    in: round2(modeRows.reduce((t, m) => t + m.in, 0)),
    out: round2(modeRows.reduce((t, m) => t + m.out, 0)),
    close: round2(modeRows.reduce((t, m) => t + m.close, 0)),
  };

  /* the day's movement by the place it came from */
  const bySource = new Map();
  for (const r of rows) {
    bySource.set(r.src, round2((bySource.get(r.src) ?? 0) + r.in - r.out));
  }
  const sources = Object.fromEntries(bySource);
  const money = (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const sourcesNote = Object.entries(sources).map(([k, v]) => `${k} ${money(v, false)}`).join(' · ');

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
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      request: (key) => (key === undefined ? { except: () => ({}) } : (key === 'date' ? date : null)),
      isset: value => value !== undefined && value !== null,
      old: (key, fallback = null) => fallback,
      e: value => String(value ?? ''),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : [])),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      rows: Coll.of(rows),
      date,
      modes: Coll.of(modeRows),
      totals,
      sources,
      sourcesNote,
      drawn: modeRows.filter(m => m.over).map(m => m.mode),
      // LedgerService::cutoff() is the day before the business date
      posting: { posted_through: '2026-11-06', posted: date <= '2026-11-06', as_of: date },
      currentHotel: HOTEL,
    },
  };
}
