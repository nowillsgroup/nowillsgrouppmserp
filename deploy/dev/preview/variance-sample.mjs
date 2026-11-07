/**
 * Sample data for the variance review preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/budgets/variance.blade.php against this fixed sample, shaped the way
 * BudgetController::variance() hands it to the view: BudgetService::worklist() (the rows the comparison flags,
 * exactly, each with the explanation that covers it), the sign-off that may or may not exist, and the blockers
 * that stop one being given.
 *
 * Two states are built from it: a month with three variances still unexplained, and the same month after every one
 * was explained and an approver signed it off.
 *
 * "Today" is 7 November 2026. The ledger is closed to 31 October, so October (fiscal month 7) is the month under
 * review; explanations for it fall due on 10 November — 10 days after month end, the setting.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const SECTIONS = {
  revenue: 'Operating revenue', direct: 'Cost of sales', opex: 'Operating expenses',
  other_income: 'Other income', nonop: 'Non-operating expenses',
};

const person = (name, id) => ({ id: id ?? name.length, name });

const row = (id, code, name, section, income, budget, actual) => {
  const varAmt = Math.round((actual - budget) * 100) / 100;
  return {
    id, code, name, section, type: income ? 'income' : 'expense',
    budget, actual, var: varAmt,
    var_pct: budget ? Math.round(varAmt * 1000 / budget) / 10 : null,
    fav: income ? varAmt > 0 : varAmt < 0,   // income over budget is favourable; expense over is not
    flag: true, ytd_actual: actual, ytd_budget: budget, year_budget: budget * 1.2,
    forecast: null, fc_var: null,
  };
};

const ROWS = [
  row(6110, '6100', 'Housekeeping supplies', 'direct', false, 46200, 58940),
  row(6210, '6200', 'Food cost', 'direct', false, 186500, 207397),
  row(6310, '6300', 'Repairs & maintenance', 'opex', false, 24000, 39800),
  row(6410, '6400', 'Staff welfare', 'opex', false, 18000, 4900),
  row(6510, '6500', 'Utilities', 'opex', false, 92000, 112600),
];

const AMA = person('Ama Serwaa', 3);
const YAW = person('Yaw Mensah', 5);

/** the explanation that covers a row, or null when it is still open */
const explained = (w, spec) => ({ ...w, ...spec });

const openRow = (r) => ({ row: r, comment: null, explained: false, owner: null, due_on: day('2026-11-10'), overdue: false, status: 'Needs explaining', tone: 'amber' });

const BASE = [
  explained(openRow(ROWS[0]), {
    comment: {
      id: 91, body: 'Two agency housekeepers for eleven nights covering the conference block; the roster is back to normal in November.',
      user: AMA, created_at: day('2026-11-03'), status: 'explained', statusLabel: () => 'Explained', statusTone: () => 'blue',
    },
    explained: true, owner: AMA, due_on: day('2026-11-10'), status: 'Explained', tone: 'blue',
  }),
  openRow(ROWS[1]),
  explained(openRow(ROWS[2]), { due_on: day('2026-11-05'), overdue: true }),   // past the deadline, still open
  openRow(ROWS[3]),
  explained(openRow(ROWS[4]), {
    comment: {
      id: 92, body: 'The pump failure in block B ran the booster set on diesel for nineteen days; the replacement is capitalised, so November carries only the hire.',
      user: YAW, created_at: day('2026-11-04'), status: 'explained', statusLabel: () => 'Explained', statusTone: () => 'blue',
    },
    explained: true, owner: YAW, due_on: day('2026-11-10'), status: 'Explained', tone: 'blue',
  }),
];

const signedRow = (w, who) => ({
  ...w,
  comment: w.comment ?? {
    id: 900 + w.row.id, body: 'Explained in the review meeting with the head of department – see the minutes of 5 November.',
    user: person('Kwesi Boateng', 2), created_at: day('2026-11-05'), status: 'accepted', statusLabel: () => 'Accepted', statusTone: () => 'green',
  },
  explained: true, overdue: false, owner: w.owner ?? person('Kwesi Boateng', 2), status: w.comment?.status === 'accepted' ? 'Accepted' : 'Explained', tone: 'green',
});

export function varianceContext({ signed = false } = {}) {
  const rows = signed ? BASE.map((w) => signedRow(w)) : BASE;
  const flagged = rows.length;
  const done = rows.filter((w) => w.explained).length;
  const meta = {
    month: 7, start: day('2026-10-01'), cutoff: day('2026-10-31'), factor: 1, partial: false, future: false,
    pre_live: false, ytd_from: null, flagged,
  };
  const signoff = signed
    ? {
        period: '2026-10', flagged, explained: flagged, signed_at: new SampleDate('2026-11-06T14:20:00Z'),
        signer: person('Kwesi Boateng', 2), note: 'Reviewed with the heads of department on 5 November.',
      }
    : null;

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (typeof value?.format === 'function' ? value.format('d M Y') : String(value ?? '—')),
      bdate: () => TODAY,
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? (params.id ?? Object.values(params)[0] ?? '') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      request: () => null,
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      isset: value => value !== undefined && value !== null,
      range: (from, to) => { const out = []; for (let i = Number(from); i <= Number(to); i++) out.push(i); return out; },
      e: value => String(value ?? ''),
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      rtrim: (value, chars = ' ') => { let out = String(value ?? ''); while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1); return out; },
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      __const: (alias, name) => (name === 'SECTIONS' ? SECTIONS : {}),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'Carbon' && method === 'create') return new SampleDate(`2000-${String(args[1]).padStart(2, '0')}-01T00:00:00Z`);
        if (owner === 'BudgetService' && method === 'fyLabel') return `FY ${args[0]}-${String(Number(args[0]) + 1).slice(2)}`;
        if (owner === 'BudgetService' && method === 'cfg') return { fy_start: 4, live_from: '2026-04-01', var_pct: 10, var_amount: 10000, control: 'warn', control_basis: 'ytd', cost_centre_required: false, explain_due_days: 10 };
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      b: {
        id: 2, budget_no: 'BUD-2026-27-01', name: 'Budget FY 2026-27', kind: 'budget', status: 'approved', is_active: true,
        department_id: null, fiscal_year: 2026,
        costCentreLabel: () => 'Whole hotel', statusLabel: () => 'Approved', statusTone: () => 'green',
        fyLabel: () => 'FY 2026-27', kindLabel: () => 'Budget',
      },
      month: 7,
      period: '2026-10',
      labels: { 1: 'April 2026', 2: 'May 2026', 3: 'June 2026', 4: 'July 2026', 5: 'August 2026', 6: 'September 2026', 7: 'October 2026', 8: 'November 2026', 9: 'December 2026', 10: 'January 2027', 11: 'February 2027', 12: 'March 2027' },
      cfg: { fy_start: 4, live_from: '2026-04-01', var_pct: 10, var_amount: 10000, control: 'warn', control_basis: 'ytd', cost_centre_required: false, explain_due_days: 10 },
      wl: { rows: new Coll(rows), period: '2026-10', month: 7, due: day('2026-11-10'), flagged, explained: done, outstanding: flagged - done, meta },
      signoff,
      blockers: signed || done === flagged ? [] : rows.filter((w) => !w.explained).map((w) => `${w.row.code} ${w.row.name} (GH₵ ${Math.abs(Number(w.row.var)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${w.row.var > 0 ? 'over' : 'under'}) has no explanation.`),
      people: new Coll([person('Ama Serwaa', 3), person('Kwesi Boateng', 2), person('Yaw Mensah', 5), person('Efua Danso', 7)]),
      currentHotel: HOTEL,
    },
  };
}
