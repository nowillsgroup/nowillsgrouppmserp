/**
 * Sample data for the budget approval preview — resources/views/budgets/show.blade.php.
 *
 * The sandbox has no PHP and no database, so the preview renders the real view against this fixed
 * sample, shaped the way BudgetController::show() hands it over: the version with its lines grouped
 * by section, the totals, the twelve month labels, the audit trail, and (v8.3) the approval state.
 *
 * The version shown is BUD-2026-27-02, submitted on 3 November 2026 and signed once — the same
 * version the Budgets list prints "1 of 2 approved" for. It is GH₵6,198,000 of income, over the
 * GH₵5,000,000 threshold in settings, so it needs two approvers: this screen is what the second
 * approver sees, and the button reads "Second approval".
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const at = (iso) => new SampleDate(iso);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

// BudgetService::SECTIONS, in the order the grid prints them
const SECTIONS = {
  revenue: 'Operating revenue',
  direct: 'Cost of sales',
  opex: 'Operating expenses',
  other_income: 'Other income',
  nonop: 'Non-operating expenses',
};

const METHODS = { blank: 'Blank', last_year: 'Last year actuals', run_rate: 'Current run-rate', copy: 'Copy another version' };

const person = (name) => ({ name });

// App\Models\BudgetEvent::LABELS
const EVENT_LABELS = {
  created: 'Created', submitted: 'Submitted', approved: 'Approved', approved_first: 'First approval',
  returned: 'Returned to the preparer', reopened: 'Re-opened', activated: 'Made active',
};

// fiscal 2026-27 starts in April, so month 1 is Apr 26
const LABELS = { 1: 'Apr 26', 2: 'May 26', 3: 'Jun 26', 4: 'Jul 26', 5: 'Aug 26', 6: 'Sep 26', 7: 'Oct 26', 8: 'Nov 26', 9: 'Dec 26', 10: 'Jan 27', 11: 'Feb 27', 12: 'Mar 27' };

// the season: low in the rains (May–July), peak over Christmas
const SEASON = [0.86, 0.72, 0.68, 0.74, 0.88, 0.96, 1.04, 1.18, 1.34, 1.12, 0.94, 0.92];

const line = (account, annual, note = null) => {
  const months = {};
  const totalWeight = SEASON.reduce((a, b) => a + b, 0);
  let allocated = 0;
  for (let m = 1; m <= 12; m++) {
    const value = m === 12 ? annual - allocated : Math.round(annual * SEASON[m - 1] / totalWeight);
    months[m] = m === 12 ? value : Math.round(value / 10) * 10;
    allocated += months[m];
  }
  return {
    account_head_id: account.id, note,
    month: (m) => months[m] ?? 0,
    total: () => Object.values(months).reduce((a, b) => a + b, 0),
  };
};

// the chart of accounts this version uses, with the annual figure each line plans
const HEAD = [
  ['revenue', '4000', 'Room revenue', 3180000, 'ADR GH₵ 1,150 · 62 % occupancy'],
  ['revenue', '4010', 'Food revenue', 1820000, 'conference season re-dated to October'],
  ['revenue', '4020', 'Beverage revenue', 780000, null],
  ['revenue', '4030', 'Other operating revenue', 400000, 'laundry, recreation and day use'],
  ['direct', '5000', 'Cost of food sold', 640000, '35 % of food revenue'],
  ['direct', '5010', 'Cost of beverage sold', 234000, '30 % of beverage revenue'],
  ['direct', '5020', 'Guest supplies', 96000, null],
  ['opex', '6000', 'Salaries and wages', 1980000, 'full establishment · annual increment 8 % from October'],
  ['opex', '6100', 'Housekeeping supplies', 148000, null],
  ['opex', '6200', 'Repairs and maintenance', 232000, 'generator service brought forward'],
  ['opex', '6300', 'Utilities – electricity and water', 346000, 'tariff up 12 % from July'],
  ['opex', '6400', 'Marketing and commissions', 268000, 'OTA commission at 18 %'],
  ['opex', '6500', 'Administrative and general', 214000, null],
  ['opex', '6600', 'Rent and lease', 240000, 'beach land lease, fixed'],
  ['other_income', '7100', 'Interest received', 18000, null],
  ['nonop', '8000', 'Bank charges', 42000, null],
  ['nonop', '8010', 'Interest on the term loan', 160000, null],
];

const accounts = {};
const lines = {};
HEAD.forEach(([section, code, name, annual, note], i) => {
  const account = { id: i + 1, code, name, section };
  accounts[account.id] = account;
  (lines[section] ??= []).push(line(account, annual, note));
});
Object.keys(lines).forEach(sec => { lines[sec] = new Coll(lines[sec]); });

// BudgetService::totals() counts every income-type account as income and every expense-type one as expense, so the
// header, the net row and the approval size are all read off the lines rather than typed in beside them.
const annual = (sections) => HEAD.filter(([sec]) => sections.includes(sec)).reduce((total, [, , , value]) => total + value, 0);
const INCOME = annual(['revenue', 'other_income']);
const EXPENSE = annual(['direct', 'opex', 'nonop']);

const EVENTS = [
  { id: 9, action: 'approved_first', user: person('Kwesi Boateng'), created_at: at('2026-11-05T15:40:00Z'),
    note: 'First approval for GH₵ 6,198,000.00 – over the GH₵ 5,000,000.00 threshold, a second approver is required · Conference dates confirmed with the sales office' },
  { id: 8, action: 'submitted', user: person('Yaw Mensah'), created_at: at('2026-11-03T10:15:00Z'), note: null },
  { id: 7, action: 'created', user: person('Ama Serwaa'), created_at: at('2026-11-02T16:05:00Z'), note: 'copied from BUD-2026-27-01' },
];

const budget = {
  id: 3, budget_no: 'BUD-2026-27-02', name: 'Revised after conference season', kind: 'budget', status: 'submitted',
  is_active: false, department_id: null, method: 'copy', parent: { budget_no: 'BUD-2026-27-01' },
  growth_income: 6, growth_expense: 4.5, return_note: null,
  notes: 'Conference season re-dated to October after the ferry timetable changed; utilities carry the 12 % tariff rise from July.',
  creator: person('Ama Serwaa'), submitter: person('Yaw Mensah'), submitted_at: at('2026-11-03T10:15:00Z'),
  approver: person('Kwesi Boateng'), approved_at: at('2026-11-05T15:40:00Z'),
  secondApprover: null, second_approved_by: null, second_approved_at: null,
  events: new Coll(EVENTS),
  lockedMonths: () => [],
  costCentreLabel: () => 'Whole hotel',
  isCapital: () => false,   // v8.4 – this is a P&L version; the capital plan is a separate kind and a separate screen
  fyLabel: () => 'FY 2026-27',
  kindLabel: () => 'Budget',
  statusLabel: () => 'Awaiting approval',
  statusTone: () => 'amber',
  isDraft: () => false,
};

// the approval state the controller passes: GH₵6,180,000 against a GH₵5,000,000 threshold, one signature in
const APPROVAL = {
  size: INCOME, threshold: 5000000, needed: 2, signed: 1,
  first: person('Kwesi Boateng'), first_at: at('2026-11-05T15:40:00Z'),
  second: null, second_at: null, awaiting: true, mine: false,
};

/**
 * BudgetService::gridTotals() — the section columns and the two derived rows, which the controller now passes to the
 * view as $grid. The same identity the service applies: GOP is operating revenue less cost of sales and operating
 * expenses, net profit adds other income and takes the non-operating expenses off.
 */
const grid = (() => {
  const cols = {};
  Object.entries(lines).forEach(([sec, ls]) => {
    cols[sec] = {};
    for (let m = 1; m <= 12; m++) cols[sec][m] = ls.sum(l => l.month(m));
    cols[sec].t = Object.keys(cols[sec]).reduce((total, key) => total + cols[sec][key], 0);
  });
  const col = (sections, m) => sections.reduce((total, sec) => total + Number(cols[sec]?.[m] ?? 0), 0);
  const gop = {}, net = {};
  for (let m = 1; m <= 12; m++) {
    gop[m] = Math.round((col(['revenue'], m) - col(['direct', 'opex'], m)) * 100) / 100;
    net[m] = Math.round((col(['revenue', 'other_income'], m) - col(['direct', 'opex', 'nonop'], m)) * 100) / 100;
  }
  gop.t = Object.keys(gop).reduce((total, key) => total + gop[key], 0);
  net.t = Object.keys(net).reduce((total, key) => total + net[key], 0);
  return { cols, gop, net };
})();

export function budgetApprovalContext() {
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
      can_do: (perm) => perm !== 'budget.manage',   // the second approver is not the preparer
      old: (key, fallback = null) => fallback,
      request: () => null,
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : Array.isArray(haystack) ? haystack : Object.values(haystack ?? {})).map(String).includes(String(needle)),
      range: (from, to) => { const out = []; for (let i = Number(from); i <= Number(to); i++) out.push(i); return out; },
      e: value => String(value ?? ''),
      ucfirst: value => { const s = String(value ?? ''); return s.charAt(0).toUpperCase() + s.slice(1); },
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
      // \App\Models\Budget::METHODS['copy'] and \App\Services\BudgetService::SECTIONS
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
        if (owner === 'BudgetEvent' && method === 'label') return EVENT_LABELS[args[0]] ?? String(args[0] ?? '');   // App\Models\BudgetEvent::label()
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      b: budget,
      lines,
      accounts,
      labels: LABELS,
      locked: [],
      totals: { income: INCOME, expense: EXPENSE, net: INCOME - EXPENSE },
      actuals: {},
      lastYear: {},
      stats: {},
      actualStats: Object.fromEntries(Object.keys(LABELS).map(m => [m, { available: 0, occupancy: 0, sold: 0, adr: 0, revpar: 0 }])),
      canEdit: false,
      roomAccount: 1,
      forecasts: new Coll([]),
      approval: APPROVAL,
      capital: null,   // a P&L version has no capital spend card
      // BudgetService::gridTotals(), as the controller hands it over
      grid,
      // the view's two formatting closures, which the renderer does not execute (see the note above)
      secLabels: SECTIONS,
      n: value => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
      raw: value => (Number(value) === Math.round(Number(value)) ? String(Math.round(Number(value))) : Number(value).toFixed(2)),
      closedMonths: { '2026-10-31': 'Oct 2026' },
      currentHotel: HOTEL,
    },
  };
}
