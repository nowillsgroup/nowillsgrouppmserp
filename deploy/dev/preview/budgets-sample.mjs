/**
 * Sample data for the budgets preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/budgets/index.blade.php against this fixed sample, shaped the way
 * BudgetController::index() hands it to the view: the budget versions with their totals and who
 * prepared and approved them, the fiscal years, and the settings modal.
 *
 * The settings modal is the point. The brief said the variance threshold runs 0–100 %, the controller
 * allowed 0–1000 %, and the input printed neither floor nor ceiling — three answers to one question,
 * and a threshold above 100 % means no variance is ever flagged.
 *
 * "Today" is 7 November 2026, in fiscal year 2026-27 (the year starts in April), with the go-live
 * date set so the comparison is live.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const METHODS = { blank: 'Blank', last_year: 'Last year actuals', run_rate: 'Current run-rate', copy: 'Copy another version' };
const STATUSES = { draft: 'Draft', submitted: 'Awaiting approval', approved: 'Approved', returned: 'Returned' };

const person = (name) => ({ name });

const budget = (id, no, name, kind, status, active, income, expense, extra = {}) => ({
  id, budget_no: no, name, kind, status, is_active: active,
  parent: extra.parent ?? null, actuals_through: extra.actuals_through ?? day('2026-10-31'),
  method: extra.method ?? 'last_year', growth_income: extra.growth_income ?? 8.5, growth_expense: extra.growth_expense ?? 6,
  return_note: null, creator: extra.creator ?? person('Ama Serwaa'), created_at: extra.created_at ?? day('2026-03-18'),
  // `??` would treat an explicit null (a version nobody has approved yet) as "not given" and invent an approver
  submitter: 'submitter' in extra ? extra.submitter : person('Ama Serwaa'),
  submitted_at: 'submitted_at' in extra ? extra.submitted_at : day('2026-03-20'),
  approver: 'approver' in extra ? extra.approver : person('Kwesi Boateng'),
  approved_at: 'approved_at' in extra ? extra.approved_at : day('2026-03-26'),
  // v8.3 – the second signature a version over the approval threshold needs
  secondApprover: extra.secondApprover ?? null, second_approved_by: extra.secondApprover ? 2 : null,
  second_approved_at: extra.second_approved_at ?? (extra.secondApprover ? day('2026-04-01') : null),
  totals: { income, expense, net: income - expense, capital: extra.capital ?? 0 },
  department_id: extra.department_id ?? null,
  department: extra.department ?? null,
  costCentreLabel: () => extra.cc ?? 'Whole hotel',
  fyLabel: () => `FY ${extra.fy ?? '2026-27'}`,
  kindLabel: () => ({ forecast: 'Re-forecast', capital: 'Capital plan' }[kind] ?? 'Budget'),
  isCapital: () => kind === 'capital',
  statusTone: () => ({ draft: 'slate', submitted: 'amber', approved: 'green', returned: 'red' }[status]),
  statusLabel: () => STATUSES[status],
  isDraft: () => status === 'draft',
});

const VERSIONS = [
  // v8.4 – the year's capital plan: asset purchases, not a P&L version, so revenue/expense/net are empty and the
  // capital column carries the figure a signature is given for
  budget(6, 'CAP-00001', 'Capital plan FY 2026-27', 'capital', 'approved', true, 0, 0, { fy: '2026-27', capital: 1450000, method: 'blank',
    secondApprover: person('Ama Serwaa'), second_approved_at: day('2026-04-04'), approved_at: day('2026-04-03') }),
  budget(5, 'BUD-2026-27-11', 'Housekeeping – FY 2026-27', 'budget', 'approved', false, 0, 620000, { fy: '2026-27', department_id: 1, department: { name: 'Housekeeping' }, cc: 'HK · Housekeeping', approver: person('Kwesi Boateng'), approved_at: day('2026-04-02') }),
  budget(4, 'BUD-2026-27-03', 'Re-forecast after Q2', 'forecast', 'submitted', false, 6280000, 4150000, { fy: '2026-27', creator: person('Yaw Mensah'), created_at: day('2026-10-28'), approver: null, approved_at: null, method: 'run_rate', growth_income: 3 }),
  // over the GH₵5,000,000 threshold and signed once: the row reads "1 of 2 approved" and stays awaiting approval
  budget(3, 'BUD-2026-27-02', 'Revised after conference season', 'budget', 'submitted', false, 6198000, 4600000, { fy: '2026-27', method: 'copy', parent: { budget_no: 'BUD-2026-27-01' },
    submitter: person('Yaw Mensah'), submitted_at: new SampleDate('2026-11-03T10:15:00Z'), approver: person('Kwesi Boateng'), approved_at: new SampleDate('2026-11-05T15:40:00Z') }),
  budget(2, 'BUD-2026-27-01', 'Budget FY 2026-27', 'budget', 'approved', true, 6050000, 3980000, { fy: '2026-27', method: 'last_year', secondApprover: person('Ama Serwaa'), second_approved_at: day('2026-04-01') }),
  budget(1, 'BUD-2025-26-01', 'Budget FY 2025-26', 'budget', 'approved', false, 5420000, 3710000, { fy: '2025-26', approver: person('Kwesi Boateng'), approved_at: day('2025-03-20'), secondApprover: person('Ama Serwaa'), second_approved_at: day('2025-03-21') }),
];

export function budgetsContext({ liveFrom = '2026-04-01' } = {}) {
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
      // PHP sprintf – the growth column prints '+8.5 % / +6.0 %'.
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
        if (owner === 'Budget') return { METHODS, STATUSES }[name] ?? {};
        return { METHODS, STATUSES }[name] ?? {};
      },
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'Carbon' && method === 'create') return new SampleDate(`2000-${String(args[1]).padStart(2, '0')}-01T00:00:00Z`);
        if (owner === 'BudgetService' && method === 'fyLabel') return `FY ${args[0]}-${String(Number(args[0]) + 1).slice(2)}`;
        if (owner === 'BudgetService' && method === 'totals') return args[0]?.totals ?? { income: 0, expense: 0, net: 0 };
        if (owner === 'BudgetService' && method === 'cfg') return { fy_start: 4, live_from: liveFrom, var_pct: 10, var_amount: 10000, control: 'warn', control_basis: 'ytd', cost_centre_required: false, explain_due_days: 10, dual_approval_above: 5000000, capex_control: 'warn', capex_prefixes: ['15'] };
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      cfg: { fy_start: 4, live_from: liveFrom, var_pct: 10, var_amount: 10000, control: 'warn', control_basis: 'ytd', cost_centre_required: false, explain_due_days: 10, dual_approval_above: 5000000, capex_control: 'warn', capex_prefixes: ['15'] },
      // v8.1 – the cost-centre dimension: departments for the create modal, and how many versions await approval
      departments: { 1: 'HK · Housekeeping', 2: 'FB · Food & Beverage', 3: 'FO · Front Office', 4: 'ENG · Engineering & Maintenance' },
      costCentres: 1,
      cur: 2026,
      fy: null,
      years: { 2027: 'FY 2027-28', 2026: 'FY 2026-27', 2025: 'FY 2025-26' },
      active: VERSIONS.find(v => v.is_active && v.kind === 'budget'),   // the active P&L budget, not the active capital plan
      forecast: VERSIONS.find(v => v.kind === 'forecast'),
      pending: 2,
      versions: new Coll(VERSIONS),
      totals: Object.fromEntries(VERSIONS.map(v => [v.id, v.totals])),
      capitalPlan: VERSIONS.find(v => v.isCapital() && v.is_active),
      capexPrefixes: '15',
      copyable: new Coll(VERSIONS),
      // v8.3 – how many signatures each version needs (its size against the GH₵5,000,000 threshold) and how many it has
      approvals: Object.fromEntries(VERSIONS.map(v => {
        const size = v.isCapital() ? Math.abs(v.totals.capital) : Math.max(Math.abs(v.totals.income), Math.abs(v.totals.expense));
        const need = size >= 5000000 ? 2 : 1;
        const signed = (v.approver ? 1 : 0) + (v.secondApprover ? 1 : 0);
        return [v.id, { size, need, signed, awaiting: need === 2 && signed === 1 && v.status === 'submitted', mine: false }];
      })),
      currentHotel: HOTEL,
    },
  };
}
