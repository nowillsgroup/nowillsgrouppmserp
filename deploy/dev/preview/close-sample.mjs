/**
 * Sample data for the month-end close preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/close/index.blade.php against this fixed sample, shaped the way
 * CloseController::index() hands it to the view: the accounting periods with their checklists and
 * who prepared or closed them, the four approval queues, the schedules due for the period in focus,
 * the fiscal-year figures, and the settings card — including the manual checklist whose caps the
 * save now enforces.
 *
 * "Today" is 7 November 2026, so October has ended with its checklist still unfinished and the
 * close window (5 days) already gone by: the overdue alert has to fire.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const STATUSES = { open: 'Open', review: 'Awaiting approval', closed: 'Closed' };
const TONES = { open: 'amber', review: 'blue', closed: 'green' };

const person = (name) => ({ name });

/* ClosePeriod rows. label() and end() are the model's own methods: the view calls them. */
function period({ period, status, cleared = 0, tasks = 0, prepared = null, by = null, closed = null, closer = null, reopened = 0 }) {
  const [year, month] = period.split('-').map(Number);
  return {
    period,
    status,
    fiscal_year: year,
    fiscal_month: month === 12 ? 12 : month,
    label: () => day(period + '-01').format('F Y'),
    end: () => day(period + '-01').endOfMonth(),
    isYearEnd: () => month === 12,
    reopen_requested_at: reopened ? day('2026-11-04') : null,
    reopen_count: reopened,
    tasks_count: tasks,
    cleared_count: cleared,
    prepared_at: prepared ? day(prepared) : null,
    preparer: by ? person(by) : null,
    closed_at: closed ? new SampleDate(`${closed}T17:40:00Z`) : null,
    closer: closer ? person(closer) : null,
  };
}

const PERIODS = [
  period({ period: '2026-07', status: 'closed', cleared: 9, tasks: 9, prepared: '2026-08-02', by: 'Ama Serwaa', closed: '2026-08-04', closer: 'Kwesi Boateng' }),
  period({ period: '2026-08', status: 'closed', cleared: 9, tasks: 9, prepared: '2026-09-01', by: 'Ama Serwaa', closed: '2026-09-03', closer: 'Kwesi Boateng' }),
  period({ period: '2026-09', status: 'closed', cleared: 9, tasks: 9, prepared: '2026-10-01', by: 'Ama Serwaa', closed: '2026-10-02', closer: 'Kwesi Boateng', reopened: 1 }),
  period({ period: '2026-10', status: 'review', cleared: 7, tasks: 9, prepared: '2026-11-04', by: 'Ama Serwaa' }),
  // the checklist does not exist until the close is prepared, so an open month is "not started"
  period({ period: '2026-11', status: 'open', cleared: 0, tasks: 0 }),
];

const MANUAL = [
  ['Receivables', 'Review the city-ledger ageing and provide for doubtful debts'],
  ['Tax', 'Agree VAT / NHIL / GETFund and tourism-levy control accounts to the GRA and GTA returns'],
  ['Payables', 'Accrue goods received not yet billed and bills received after month end'],
  ['Reporting', 'Management accounts pack reviewed by the general manager'],
  ['Stores', 'Agree the beverage stock take to the general ledger and clear the variance'],
];

export function closeContext({ canApprove = true, canClose = false } = {}) {
  const due = new Coll([
    { source: 'accrual', model: { number: 'ACC-2026-0041', description: 'Electricity — October consumption' }, period: '2026-11', amount: 48250.00 },
    { source: 'accrual', model: { number: 'ACC-2026-0043', description: 'Beach bar stock — supplier invoice to come' }, period: '2026-11', amount: 12940.75 },
    { source: 'recurring', model: { code: 'RJ-07', name: 'Insurance — annual premium apportioned' }, period: '2026-11', amount: 8650.00 },
    { source: 'recurring', model: { code: 'RJ-11', name: 'Ground rent apportioned monthly' }, period: '2026-11', amount: 3200.00 },
  ]);

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (typeof value?.format === 'function' ? value.format('d M Y') : String(value ?? '—')),
      bdate: () => TODAY,
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? (params.id ?? params.period ?? params.number ?? '') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: perm => (perm === 'close.approve' ? canApprove : true),
      old: (key, fallback = null) => fallback,
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      e: value => String(value ?? ''),
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      __const: (alias, name) => {
        // CloseService::MANUAL_* are read straight off the service by the settings card.
        if (String(alias).includes('CloseService')) {
          return { MANUAL_MAX_ROWS: 15, MANUAL_AREA_MAX: 30, MANUAL_TASK_MAX: 150 }[name] ?? '';
        }
        if (name === 'STATUSES') return STATUSES;
        if (name === 'TONES') return TONES;
        if (name === 'TYPES') return { prepaid: 'Prepaid', accrued: 'Accrued', deferred: 'Deferred' };
        if (name === 'FREQUENCIES') return { monthly: 'Monthly', quarterly: 'Quarterly' };
        return {};
      },
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'Carbon' && method === 'create') return new SampleDate(`2000-01-01T00:00:00Z`);
        if (owner === 'BudgetService' && method === 'cfg') return { fy_start: 1 };
        if (owner === 'BudgetService' && method === 'fyLabel') return `FY${args[0]}`;
        if (owner === 'CloseService' && method === 'fyEnd') return `${args[0]}-12-31`;
        if (owner === 'Str' && method === 'limit') return String(args[0] ?? '').slice(0, Number(args[1] ?? 30));
        return args[0] ?? '';
      },
      Str: { limit: (value, length = 30) => String(value ?? '').slice(0, length) },
      // PHP's number helpers: the views use them on figures straight from the query.
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      abs: value => Math.abs(Number(value ?? 0)),
      floor: value => Math.floor(Number(value ?? 0)),
      ceil: value => Math.ceil(Number(value ?? 0)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    // The file's top @php block (the model alias, the approval count, the permission) only runs when
    // the whole view renders; a section render needs those names supplied.
    scope: {
      CP: 'App\\Models\\ClosePeriod',
      canApprove,
      nApprovals: 2,
      periods: new Coll(PERIODS),
      overdue: new Coll([PERIODS[3]]),
      lock: day('2026-09-30'),
      lastClosed: PERIODS[2],
      focus: PERIODS[3],
      prepaid: 184320.00, accrued: 95120.50, deferred: 246800.00,
      approvals: {
        periods: new Coll([PERIODS[3]]),
        reopens: new Coll([]),
        accruals: new Coll([{ id: 41, number: 'ACC-2026-0041', description: 'Electricity — October consumption', type: 'accrued', amount: 48250.00, creator: person('Ama Serwaa') }]),
        recurring: new Coll([{ id: 7, code: 'RJ-07', name: 'Insurance — annual premium apportioned', frequency: 'monthly', total: 8650.00, creator: person('Kwesi Boateng') }]),
        accruals_all: new Coll([]),
      },
      due,
      activeSchedules: 6, activeRecurring: 3,
      fy: 2026,
      preview: { fy_income: 6420880.25, fy_expense: 4104520.80 },
      cfg: { start: '2026-07', due_days: 5, auto_post: true, manual: MANUAL },
      canClose,
      currentHotel: HOTEL,
    },
  };
}
