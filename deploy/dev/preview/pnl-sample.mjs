/**
 * Sample data for the profit & loss preview — resources/views/accounts/pnl.blade.php rendered the way
 * AccountsController::pnl() hands it over: the period, income by charge category and other income, expenses by head, the
 * net result and margin, and v9.10's tie to the general ledger.
 *
 * The period is 1–7 November 2026, the first week of the month the property is working through:
 *
 *   income                                   expenses (payment vouchers)
 *   Room Tariff              96,400.00       Utilities                  18,400.00
 *   Food & Beverage          38,250.00       Repairs & maintenance       9,250.00
 *   Banquet                  22,000.00       Cleaning supplies           5,600.00
 *   Spa                       6,500.00       Fuel                        7,800.00
 *   Laundry                   4,800.00       Staff welfare               3,600.00
 *   Minibar                   2,150.00       Stationery                  1,450.00
 *   Miscellaneous             1,250.00       ─────────────────────────────────────
 *   Telephone                   900.00       total                      46,100.00
 *   Parking & Valet             750.00
 *   ─────────────────────────────────
 *   charges                 173,000.00
 *   less discounts           −4,600.00
 *   POS direct settlements   28,400.00
 *   other income (vouchers)   6,800.00
 *   ─────────────────────────────────
 *   total income            203,600.00      net profit 157,500.00 · margin 77.4 % · tax collected 34,600.00
 *
 * The ledger posted the same 203,600.00 of income and 46,100.00 of expense, so the statement ties. The drift build is the
 * fault the tie exists for: the 6,800.00 receipt voucher has not reached the ledger, so revenue is out by 6,800.00 while
 * expenses still agree, and the page has to say which line is out before the statement is issued.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

export const LABELS = {
  room: 'Room Tariff', extra_bed: 'Extra Bed / Person', fnb: 'Food & Beverage', laundry: 'Laundry',
  telephone: 'Telephone', minibar: 'Minibar', transport: 'Transport / Pickup', spa: 'Spa',
  banquet: 'Banquet', parking: 'Parking & Valet', pay_tv: 'Pay TV / Movies', damage: 'Damages',
  cancellation: 'Cancellation / No-show Fee', misc: 'Miscellaneous', excursion: 'Excursions & Tickets (agency)',
  discounts: 'Less: Discounts & allowances', pos_direct: 'POS sales (direct settlement)',
};

/* the order the statement prints: the categories in config, then the two lines it adds */
const INCOME = {
  room: 96400, fnb: 38250, banquet: 22000, spa: 6500, laundry: 4800, minibar: 2150, misc: 1250, telephone: 900, parking: 750,
  discounts: -4600, pos_direct: 28400,
};
const OTHER_INCOME = { 'Banquet hall hire – non-resident': 6800 };
const EXPENSES = {
  Utilities: 18400, 'Repairs & maintenance': 9250, 'Cleaning supplies': 5600, Fuel: 7800, 'Staff welfare': 3600, Stationery: 1450,
};

const round2 = (value) => Math.round(Number(value ?? 0) * 100) / 100;

export function pnlContext({ from = '2026-11-01', to = '2026-11-07', drift = false } = {}) {
  const income = round2(Object.values(INCOME).reduce((t, v) => t + v, 0));
  const other = round2(Object.values(OTHER_INCOME).reduce((t, v) => t + v, 0));
  const totalIncome = round2(income + other);
  const exp = round2(Object.values(EXPENSES).reduce((t, v) => t + v, 0));
  const net = round2(totalIncome - exp);
  const margin = totalIncome > 0 ? Math.round(net / totalIncome * 1000) / 10 : 0;

  // the ledger posted the same period; the drift build leaves the 6,800.00 receipt voucher unposted
  const revenueGl = drift ? round2(totalIncome - 6800) : totalIncome;
  const expenseGl = exp;
  const tie = {
    from, to,
    revenue: { pnl: totalIncome, gl: revenueGl, diff: round2(totalIncome - revenueGl) },
    expense: { pnl: exp, gl: expenseGl, diff: round2(exp - expenseGl) },
  };
  tie.revenue.abs = Math.abs(tie.revenue.diff);
  tie.expense.abs = Math.abs(tie.expense.diff);
  tie.out = { revenue: tie.revenue.abs > 0.005, expense: tie.expense.abs > 0.005 };
  tie.ties = !tie.out.revenue && !tie.out.expense;
  tie.posted = to <= '2026-11-06';   // LedgerService::cutoff() is the day before the business date

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
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      request: (key) => {
        if (key === undefined) return { except: () => ({}) };
        if (key === 'from') return from;
        if (key === 'to') return to;
        return null;
      },
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
      from, to,
      income: INCOME,
      otherIncome: OTHER_INCOME,
      expenses: EXPENSES,
      totalIncome,
      exp,
      net,
      margin,
      labels: LABELS,
      taxCollected: 34600,
      tie,
      currentHotel: HOTEL,
    },
  };
}
