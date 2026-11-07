/**
 * Sample data for the cost-centre budgets preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/budgets/cost-centres.blade.php against this fixed sample, shaped the way
 * BudgetController::costCentres() (and behind it BudgetService::costCentres()) hands it to the view:
 * every department with its approved version (or none), the plan for the year, the plan pro-rated to
 * the days elapsed, what the ledger actually charged to that cost centre, and the two figures that
 * never balance on their own — plan not yet given to a department, and expense carrying no cost
 * centre at all.
 *
 * The figures reconcile: the department plans add up to less than the whole-hotel plan (the rest is
 * not yet allocated), and the departments' actuals plus the untagged expense equal the whole-hotel
 * expense actual. That is the point of the screen — a departmental P&L that still adds up.
 *
 * "Today" is 7 November 2026; the ledger is closed to 31 October 2026, in fiscal year 2026-27.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const CUTOFF = new SampleDate('2026-10-31T00:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const dept = (id, code, name, costCentre = true) => ({
  id, code, name, is_cost_centre: costCentre,
  label: () => (code ? `${code} · ${name}` : name),
});

const version = (id, no, name, status = 'approved', active = true) => ({
  id, budget_no: no, name, status, is_active: active, department_id: 1,
  statusTone: () => ({ draft: 'slate', submitted: 'amber', approved: 'green' }[status]),
  statusLabel: () => ({ draft: 'Draft', submitted: 'Awaiting approval', approved: 'Approved' }[status]),
  costCentreLabel: () => 'HK · Housekeeping',
});

const row = (department, budget, plan, planYtd, actual, perCent) => ({
  department, budget, pending: null, plan, plan_ytd: planYtd, actual,
  variance: planYtd === null ? null : Math.round((actual - planYtd) * 100) / 100,
  variance_pct: planYtd ? Math.round((actual - planYtd) * 1000 / planYtd) / 10 : null,
  per_cent: perCent,
});

const HK = dept(1, 'HK', 'Housekeeping');
const FB = dept(2, 'FB', 'Food & Beverage');
const FO = dept(3, 'FO', 'Front Office');
const ENG = dept(4, 'ENG', 'Engineering & Maintenance');
const SPA = dept(5, 'SPA', 'Spa & Wellness');
const SEC = dept(6, 'SEC', 'Security', false);

const ROWS = [
  row(HK, version(12, 'BUD-2026-27-11', 'Housekeeping FY 2026-27'), 620000, 366300, 389120, 15.6),
  row(FB, version(13, 'BUD-2026-27-12', 'Kitchens & bars FY 2026-27'), 1180000, 697200, 731480, 29.6),
  row(FO, version(14, 'BUD-2026-27-13', 'Front office FY 2026-27'), 240000, 141800, 118640, 6.0),
  row(ENG, null, null, null, 196870, null),
  row(SPA, null, null, null, 42300, null),
  row(SEC, null, null, null, 33700, null),
];

const ENG_PENDING = { ...version(15, 'BUD-2026-27-14', 'Engineering FY 2026-27', 'submitted', false) };
ROWS.find(r => /Engineering/.test(r.department.name)).pending = ENG_PENDING;

const allocPlan = 2040000;
const allocPlanYtd = 1205300;
const allocActual = 1512110;
const wholePlan = 3980000;
const wholePlanYtd = 2352400;
const wholeActual = 2428650;

export function costCentresContext() {
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
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      __const: () => ({}),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'Carbon' && method === 'create') return new SampleDate(`2000-${String(args[1]).padStart(2, '0')}-01T00:00:00Z`);
        if (owner === 'BudgetService' && method === 'fyLabel') return `FY ${args[0]}-${String(Number(args[0]) + 1).slice(2)}`;
        if (owner === 'BudgetService' && method === 'cfg') return { fy_start: 4, live_from: '2026-04-01', var_pct: 10, var_amount: 10000, control: 'warn', control_basis: 'ytd', cost_centre_required: false };
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      cur: 2026,
      fy: 2026,
      years: { 2027: 'FY 2027-28', 2026: 'FY 2026-27', 2025: 'FY 2025-26' },
      cfg: { fy_start: 4, live_from: '2026-04-01', var_pct: 10, var_amount: 10000, control: 'warn', control_basis: 'ytd', cost_centre_required: false },
      whole: version(11, 'BUD-2026-27-01', 'Budget FY 2026-27'),
      data: {
        rows: new Coll(ROWS),
        totals: { plan: allocPlan, plan_ytd: allocPlanYtd, actual: allocActual },
        whole: { plan: wholePlan, plan_ytd: wholePlanYtd, actual: wholeActual },
        not_allocated: wholePlan - allocPlan,
        not_allocated_ytd: wholePlanYtd - allocPlanYtd,
        unallocated: wholeActual - allocActual,
        tagged_pct: Math.round(allocActual * 1000 / wholeActual) / 10,
        meta: {
          fy: 2026, cutoff: CUTOFF, whole: null, budgeted: 3, pending: 1, departments: ROWS.length,
          from: '2026-04-01', to: '2026-10-31',
        },
      },
      currentHotel: HOTEL,
    },
  };
}
