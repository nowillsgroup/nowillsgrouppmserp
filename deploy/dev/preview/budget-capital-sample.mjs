/**
 * Sample data for the capital plan preview — resources/views/budgets/show.blade.php, rendered for a version of
 * kind `capital` (v8.4: capex is not opex).
 *
 * The sandbox has no PHP and no database, so the preview renders the real view against this fixed sample, shaped the
 * way BudgetController::show() hands it over for a capital plan: its lines are capital asset heads only, its totals
 * carry capital and no revenue/expense/profit, and `$capital` is BudgetService::capitalSpend() — the plan against what
 * the ledger has actually debited to those asset accounts, which is exactly what a supplier bill is checked against.
 *
 * CAP-00001 for FY 2026-27 is approved and active: GH₵1,450,000 committed to assets, of which GH₵620,000 has been
 * spent by fiscal month 7 (October 2026). "Today" is 7 November 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const at = (iso) => new SampleDate(iso);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const SECTIONS = { revenue: 'Operating revenue', direct: 'Cost of sales', opex: 'Operating expenses', other_income: 'Other income', nonop: 'Non-operating expenses', capital: 'Capital expenditure' };
const METHODS = { blank: 'Start blank', last_year: "Last year's actuals (same months)", run_rate: 'Run-rate (average of the last 3 closed months)', copy: 'Copy another version' };
const person = (name) => ({ name });

const LABELS = { 1: 'Apr 26', 2: 'May 26', 3: 'Jun 26', 4: 'Jul 26', 5: 'Aug 26', 6: 'Sep 26', 7: 'Oct 26', 8: 'Nov 26', 9: 'Dec 26', 10: 'Jan 27', 11: 'Feb 27', 12: 'Mar 27' };

// capital is bought in lumps, not in a seasonal curve: each item has its own month(s)
const line = (account, months, note = null) => ({
  account_head_id: account.id, note,
  month: (m) => Number(months[m] ?? 0),
  total: () => Object.values(months).reduce((a, b) => a + Number(b), 0),
});

const HEAD = [
  ['1500', 'Kitchen and laundry equipment', { 4: 180000, 5: 240000 }, 'two combi ovens and a dryer'],
  ['1510', 'Furniture, fixtures and equipment', { 2: 210000, 6: 220000, 9: 180000 }, 'rooms 101–118 refurbishment'],
  ['1520', 'Vehicles', { 8: 260000 }, 'replacement 15-seat shuttle'],
  ['1530', 'Building and plant improvements', { 10: 120000, 12: 40000 }, 'beach bar roof and walkway'],
];

const accounts = {};
const capitalLines = [];
HEAD.forEach(([code, name, months, note], i) => {
  const account = { id: 100 + i, code, name, section: 'capital', type: 'asset' };
  accounts[account.id] = account;
  capitalLines.push(line(account, months, note));
});

const INCOME = 0;
const EXPENSE = 0;
const CAPITAL = capitalLines.reduce((total, l) => total + l.total(), 0);

const EVENTS = [
  { id: 4, action: 'activated', user: person('Kwesi Boateng'), created_at: at('2026-04-03T09:10:00Z'), note: 'whole hotel' },
  { id: 3, action: 'approved', user: person('Kwesi Boateng'), created_at: at('2026-04-03T09:05:00Z'), note: null },
  { id: 2, action: 'submitted', user: person('Ama Serwaa'), created_at: at('2026-04-01T11:20:00Z'), note: null },
  { id: 1, action: 'created', user: person('Ama Serwaa'), created_at: at('2026-03-28T15:40:00Z'), note: 'Start blank' },
];

const budget = {
  id: 6, budget_no: 'CAP-00001', name: 'Capital plan FY 2026-27', kind: 'capital', status: 'approved',
  is_active: true, department_id: null, method: 'blank', parent: null,
  growth_income: 0, growth_expense: 0, return_note: null,
  notes: 'Owners approved the FF&E reserve drawdown for the rooms refurbishment in March; the shuttle replaces the 2016 van at the end of the rains.',
  creator: person('Ama Serwaa'), submitter: person('Ama Serwaa'), submitted_at: at('2026-04-01T11:20:00Z'),
  approver: person('Kwesi Boateng'), approved_at: at('2026-04-03T09:05:00Z'),
  secondApprover: null, second_approved_by: null, second_approved_at: null,
  events: new Coll(EVENTS),
  lockedMonths: () => [],
  costCentreLabel: () => 'Whole hotel',
  fyLabel: () => 'FY 2026-27',
  kindLabel: () => 'Capital plan',
  statusLabel: () => 'Approved',
  statusTone: () => 'green',
  isDraft: () => false,
  isCapital: () => true,
};

// BudgetService::capitalSpend(): the plan for the year, the plan to date (fiscal month 7), what the ledger has debited,
// and — v8.5 — what approved capital projects have promised but not yet spent, linked by capital asset account.
const SPEND = [
  { code: '1500', plan: 420000, to_date: 420000, actual: 380000, committed: 0 },
  { code: '1510', plan: 610000, to_date: 430000, actual: 240000, committed: 0 },
  { code: '1520', plan: 260000, to_date: 0, actual: 0, committed: 120000 },
  { code: '1530', plan: 160000, to_date: 0, actual: 0, committed: 160000 },
];
// Owner-reporting capex projects: approved, capitalised to one of the heads above, part-spent
const PROJECTS = {
  '1520': [{ number: 'CPX-00004', title: 'Replacement 15-seat shuttle', budget: 260000, spent: 140000, open: 120000 }],
  '1530': [{ number: 'CPX-00003', title: 'Beach bar roof and walkway', budget: 160000, spent: 0, open: 160000 }],
};
const byCode = Object.fromEntries(Object.values(accounts).map(a => [a.code, a]));
const capitalRows = SPEND.map(r => ({
  account: byCode[r.code], plan: r.plan, to_date: r.to_date, actual: r.actual, committed: r.committed,
  projects: PROJECTS[r.code] ?? [],
  remaining: r.plan - r.actual - r.committed, pct: r.plan > 0 ? Math.round(r.actual * 1000 / r.plan) / 10 : null,
}));
const capitalSummary = {
  rows: capitalRows,
  plan: capitalRows.reduce((t, r) => t + r.plan, 0),
  to_date: capitalRows.reduce((t, r) => t + r.to_date, 0),
  actual: capitalRows.reduce((t, r) => t + r.actual, 0),
  through: 7,
};
capitalSummary.committed = capitalRows.reduce((t, r) => t + r.committed, 0);
capitalSummary.remaining = capitalSummary.plan - capitalSummary.actual - capitalSummary.committed;
capitalSummary.pct = capitalSummary.plan > 0 ? Math.round(capitalSummary.actual * 1000 / capitalSummary.plan) / 10 : null;

// BudgetService::commitmentTimeline(): the same promised money placed in the fiscal month each project is needed in.
// CPX-00004 was needed in October and is still unspent (fiscal month 7, past its date); CPX-00003 is needed in February
// (fiscal month 11). Nothing is undated, so the strip accounts for all of it.
const DUE = {
  '7': [{ number: 'CPX-00004', title: 'Replacement 15-seat shuttle', open: 120000, head: '1520' }],
  '11': [{ number: 'CPX-00003', title: 'Beach bar roof and walkway', open: 160000, head: '1530' }],
};
const TIMELINE_PEAK = Math.max(...Object.values(DUE).flat().map(p => p.open));
const timelineMonths = [];
for (let m = 1; m <= 12; m++) {
  const projects = DUE[m] ?? [];
  const open = projects.reduce((t, p) => t + p.open, 0);
  timelineMonths.push({
    label: LABELS[m], open, projects,
    height: TIMELINE_PEAK > 0 && open > 0 ? Math.max(4, Math.round(open * 100 / TIMELINE_PEAK)) : 0,
    past: m <= 7,                       // end of October is behind the 5 November business date
    late: m <= 7 && open > 0,
  });
}
const capitalTimeline = {
  months: timelineMonths,
  undated: { open: 0, projects: [] },
  overdue: { open: 120000, projects: DUE['7'] },
  total: 280000,
  peak: TIMELINE_PEAK,
};

// BudgetService::capitalFunding(): who owes the money, and whether the balance behind it is there. The reserve has been
// promised 120,000 and holds 90,000 accrued and 60,000 in cash, so 30,000 of the promise has no cover; the owner-funded
// project has no balance to read against it at all, which is reported rather than dressed up as covered.
const capitalFunding = {
  rows: [
    { key: 'reserve', label: 'FF&E reserve', open: 120000, cover: 90000, cash: 60000, short: 30000, cash_short: 60000,
      note: 'drawn from the reserve balance',
      projects: [{ number: 'CPX-00004', title: 'Replacement 15-seat shuttle', open: 120000 }] },
    { key: 'owner', label: 'Owner (capital call)', open: 160000, cover: null, cash: null, short: null, cash_short: null,
      note: 'raised as a capital call when it is paid',
      projects: [{ number: 'CPX-00003', title: 'Beach bar roof and walkway', open: 160000 }] },
    { key: 'operating', label: 'Operating cash', open: 0, cover: null, cash: null, short: null, cash_short: null,
      note: 'nothing promised from here', projects: [] },
  ],
  open: 280000, short: 30000, untracked: 160000, accrued: 90000, cash: 60000,
};

const APPROVAL = {
  size: CAPITAL, threshold: 5000000, needed: 1, signed: 1,
  first: person('Kwesi Boateng'), first_at: at('2026-04-03T09:05:00Z'),
  second: null, second_at: null, awaiting: false, mine: false,
};

const grid = (() => {
  const cols = { capital: {} };
  for (let m = 1; m <= 12; m++) cols.capital[m] = capitalLines.reduce((t, l) => t + l.month(m), 0);
  cols.capital.t = Object.values(cols.capital).reduce((a, b) => a + b, 0);

  return { cols, gop: {}, net: {} };
})();

export function budgetCapitalContext() {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (typeof value?.format === 'function' ? value.format('d M Y') : String(value ?? '—')),
      bdate: () => TODAY,
      hotel: () => HOTEL,
      currentHotel: HOTEL,
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? (params.id ?? Object.values(params)[0] ?? '') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      request: () => null,
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : Array.isArray(haystack) ? haystack : Object.values(haystack ?? {})).map(String).includes(String(needle)),
      range: (from, to) => { const out = []; for (let i = Number(from); i <= Number(to); i++) out.push(i); return out; },
      e: value => String(value ?? ''),
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      sprintf: (format, ...args) => {
        let i = 0;
        return String(format).replace(/%([+-])?\.?(\d+)?([fds%])/g, (m, sign, places, kind) => {
          if (kind === '%') return '%';
          const value = args[i++];
          if (kind === 'd') return String(Number(value ?? 0));
          if (kind === 's') return String(value ?? '');
          const n = Number(value ?? 0).toFixed(places ? Number(places) : 0);
          return sign === '+' && n >= 0 ? `+${n}` : n;
        });
      },
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      abs: value => Math.abs(Number(value ?? 0)),
      __const: (alias, name) => {
        const owner = String(alias).split('\\').pop();
        if (owner === 'BudgetService' && name === 'SECTIONS') return SECTIONS;
        if (owner === 'Budget') return { METHODS, STATUSES: { draft: 'Draft', submitted: 'Awaiting approval', approved: 'Approved' } }[name] ?? {};
        return { SECTIONS, METHODS }[name] ?? {};
      },
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'Carbon' && method === 'create') return new SampleDate(`2000-${String(args[1]).padStart(2, '0')}-01T00:00:00Z`);
        if (owner === 'BudgetEvent' && method === 'label') {
          return { created: 'Created', submitted: 'Submitted', approved: 'Approved', approved_first: 'First approval', activated: 'Made active' }[args[0]] ?? String(args[0] ?? '');
        }
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      b: budget,
      lines: { capital: new Coll(capitalLines) },
      accounts,
      labels: LABELS,
      locked: [],
      totals: { income: INCOME, expense: EXPENSE, net: INCOME - EXPENSE, capital: CAPITAL },
      grid,
      actuals: {},
      lastYear: {},
      stats: {},
      actualStats: Object.fromEntries(Object.keys(LABELS).map(m => [m, { available: 0, occupancy: 0, sold: 0, adr: 0, revpar: 0 }])),
      canEdit: false,
      roomAccount: 1,
      forecasts: new Coll([]),
      approval: APPROVAL,
      capital: capitalSummary,
      timeline: capitalTimeline,
      funding: capitalFunding,
      closedMonths: { '2026-10-31': 'Oct 2026' },
      currentHotel: HOTEL,
    },
  };
}
