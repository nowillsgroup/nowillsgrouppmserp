/**
 * Sample data for the requisitions preview — resources/views/requisitions/index.blade.php rendered the way
 * RequisitionController::index() hands it over: the page of requisitions, the tab counts, and the three readings the
 * round adds.
 *
 * A requisition is goods going the other way. A goods receipt asks whether the money behind the goods reached a bill;
 * a requisition asks how long it has waited on a person, whether the department still has room in its month, and whether
 * anybody signed for what left the store.
 *
 *   • the queue — two requisitions with a head of department (16,730.00, the oldest four days) and four with the store
 *     (35,300.00, the oldest six); two of the six are past the date the department asked for the goods by.
 *   • the allowance — five departments, four with a budget for November: 175,000.00 between them, 140,080.00 used, and
 *     Maintenance the tightest at 91.7 % with 5,000.00 left. Security has no allowance, so nothing is held to it.
 *   • the handover — two issue notes nobody has signed for, 5,460.00, the older eight days old; the two that were signed
 *     in the last thirty days were acknowledged a day after the goods left.
 *
 * The drift build breaks two of those readings and leaves the first alone: a delivery to the Front Office entered late
 * takes that department 760.00 over its allowance, and a third issue note — the 5,480.00 maintenance issue of 28 October —
 * loses its signature, so the store is 10,940.00 short of proof.
 *
 * "Today" is 7 November 2026; the month is November.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const at = (iso, time = '09:00') => new SampleDate(`${iso}T${time}:00Z`);
const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;
const iso = (d) => (typeof d?.toDateString === 'function' ? d.toDateString().slice(0, 10) : String(d).slice(0, 10));

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const TABS = { action: 'Needs my action', open: 'Open', draft: 'Drafts', submitted: 'Awaiting approval', approved: 'To issue',
  partial: 'Part issued', receipt: 'Awaiting receipt', done: 'Completed', all: 'All' };
const TONES = { draft: 'slate', submitted: 'amber', approved: 'blue', partial: 'purple', issued: 'green', closed: 'slate', rejected: 'red', cancelled: 'slate' };
const LABELS = { draft: 'Draft', submitted: 'Submitted', approved: 'Approved', partial: 'Part issued', issued: 'Issued', closed: 'Closed', rejected: 'Rejected', cancelled: 'Cancelled' };

const DEPT = { kitchen: { id: 2, name: 'Kitchen' }, housekeeping: { id: 4, name: 'Housekeeping' }, front: { id: 1, name: 'Front Office' },
  maintenance: { id: 7, name: 'Maintenance' }, security: { id: 9, name: 'Security' } };

const issue = (o) => ({ id: o.id, date: day(o.date), value: o.value, received_at: o.received ? at(o.received, '16:20') : null,
  fromStore: { name: o.store }, department: DEPT[o.dept], issuer: { name: o.by ?? 'Yaw Antwi' } });

const ISSUES = {
  'REQ-2026-0145': [issue({ id: 501, date: '2026-11-03', value: 3400, received: '2026-11-04', store: 'Main Store', dept: 'kitchen' }),
    issue({ id: 506, date: '2026-11-05', value: 3000, received: null, store: 'Kitchen Store', dept: 'kitchen', by: 'Adjoa Sarpong' })],
  'REQ-2026-0146': [issue({ id: 502, date: '2026-10-30', value: 3120, received: null, store: 'Main Store', dept: 'housekeeping', by: 'Adjoa Sarpong' })],
  'REQ-2026-0147': [issue({ id: 503, date: '2026-10-28', value: 5480, received: '2026-10-29', store: 'Engineering Store', dept: 'maintenance' })],
  'REQ-2026-0149': [issue({ id: 504, date: '2026-11-04', value: 2340, received: null, store: 'Main Store', dept: 'kitchen' })],
  'REQ-2026-0150': [issue({ id: 505, date: '2026-10-21', value: 6780, received: '2026-10-22', store: 'Main Store', dept: 'housekeeping' })],
};

const REQ = [
  { id: 152, no: 'REQ-2026-0152', date: '2026-11-06', dept: 'maintenance', store: 'Engineering Store', to: null, items: 8,
    est: 15900, status: 'approved', submitted: '2026-11-06', approved: '2026-11-07', required: '2026-11-12', raiser: 'Yaw Antwi', approver: 'Kofi Mensah', purpose: 'Compressor service kit and belts' },
  { id: 148, no: 'REQ-2026-0148', date: '2026-11-07', dept: 'security', store: 'Main Store', to: null, items: 2,
    est: 940, status: 'draft', submitted: null, approved: null, required: null, raiser: 'Ama Boateng', approver: null, purpose: 'Torch batteries for the night patrol' },
  { id: 145, no: 'REQ-2026-0145', date: '2026-10-31', dept: 'kitchen', store: 'Main Store', to: 'Kitchen Store', items: 7,
    est: 9640, status: 'partial', submitted: '2026-11-01', approved: '2026-11-01', required: '2026-11-02', raiser: 'Kwesi Appiah', approver: 'Kofi Mensah', purpose: 'Weekly dry goods for the kitchen' },
  { id: 144, no: 'REQ-2026-0144', date: '2026-11-04', dept: 'front', store: 'Main Store', to: null, items: 3,
    est: 1860, status: 'approved', submitted: '2026-11-04', approved: '2026-11-05', required: '2026-11-09', raiser: 'Ama Boateng', approver: 'Kofi Mensah', purpose: 'Registration cards and key wallets' },
  { id: 143, no: 'REQ-2026-0143', date: '2026-11-01', dept: 'maintenance', store: 'Engineering Store', to: null, items: 4,
    est: 7900, status: 'approved', submitted: '2026-11-01', approved: '2026-11-02', required: '2026-11-04', raiser: 'Yaw Antwi', approver: 'Kofi Mensah', purpose: 'Pool pump seals and piping' },
  { id: 142, no: 'REQ-2026-0142', date: '2026-11-05', dept: 'kitchen', store: 'Main Store', to: 'Kitchen Store', items: 9,
    est: 12480, status: 'submitted', submitted: '2026-11-05', approved: null, required: '2026-11-08', raiser: 'Kwesi Appiah', approver: null, purpose: 'Produce and dairy for the weekend' },
  { id: 141, no: 'REQ-2026-0141', date: '2026-11-03', dept: 'housekeeping', store: 'Main Store', to: 'Housekeeping Store', items: 6,
    est: 4250, status: 'submitted', submitted: '2026-11-03', approved: null, required: '2026-11-06', raiser: 'Efua Mensah', approver: null, purpose: 'Guest amenities and cleaning chemicals' },
  { id: 149, no: 'REQ-2026-0149', date: '2026-11-02', dept: 'kitchen', store: 'Main Store', to: 'Kitchen Store', items: 3,
    est: 2340, status: 'issued', submitted: '2026-11-02', approved: '2026-11-03', required: '2026-11-04', raiser: 'Kwesi Appiah', approver: 'Kofi Mensah', purpose: 'Gas refill for the pastry section' },
  { id: 147, no: 'REQ-2026-0147', date: '2026-10-26', dept: 'maintenance', store: 'Engineering Store', to: null, items: 2,
    est: 5480, status: 'issued', submitted: '2026-10-26', approved: '2026-10-27', required: '2026-10-29', raiser: 'Yaw Antwi', approver: 'Kofi Mensah', purpose: 'Generator fuel filters' },
  { id: 146, no: 'REQ-2026-0146', date: '2026-10-28', dept: 'housekeeping', store: 'Main Store', to: 'Housekeeping Store', items: 5,
    est: 3120, status: 'issued', submitted: '2026-10-28', approved: '2026-10-28', required: '2026-10-30', raiser: 'Efua Mensah', approver: 'Kofi Mensah', purpose: 'Laundry detergent and softener' },
  { id: 150, no: 'REQ-2026-0150', date: '2026-10-20', dept: 'housekeeping', store: 'Main Store', to: 'Housekeeping Store', items: 4,
    est: 6780, status: 'closed', submitted: '2026-10-20', approved: '2026-10-20', required: '2026-10-22', raiser: 'Efua Mensah', approver: 'Kofi Mensah', purpose: 'Bed linen replacement' },
  { id: 151, no: 'REQ-2026-0151', date: '2026-10-29', dept: 'front', store: 'Main Store', to: null, items: 1,
    est: 420, status: 'rejected', submitted: '2026-10-29', approved: null, required: null, raiser: 'Ama Boateng', approver: 'Kofi Mensah', purpose: 'Decorative lobby flowers' },
];

/* Department allowances for November, as RequisitionService::budgetStatus() reports them: issued + still committed. */
const BUDGET = {
  kitchen: { budget: 60000, issued: 21400, committed: 22120, note: 'BD-2026-04 · FY2026 · month 11' },
  housekeeping: { budget: 35000, issued: 19850, committed: 4250, note: 'BD-2026-05 · FY2026 · month 11' },
  front: { budget: 20000, issued: 15600, committed: 1860, note: 'monthly figure on the department' },
  maintenance: { budget: 60000, issued: 31200, committed: 23800, note: 'BD-2026-07 · FY2026 · month 11' },
  security: { budget: null, issued: 0, committed: 0, note: null },
};

export function requisitionsContext({ drift = false, tab = 'open', department = null, mine = false, q = null, page = null } = {}) {
  const QUERY = { show: tab, department, mine, q, page: null };
  const reqOf = (o) => {
    const issues = (ISSUES[o.no] ?? []).map(i => (drift && o.no === 'REQ-2026-0147' ? { ...i, received_at: null } : i));
    const r = {
      id: o.id, req_no: o.no, req_date: day(o.date), required_by: o.required ? day(o.required) : null, status: o.status,
      priority: o.no === 'REQ-2026-0143' ? 'urgent' : 'normal', purpose: o.purpose, est_value: o.est,
      submitted_at: o.submitted ? at(o.submitted) : null, approved_at: o.approved ? at(o.approved) : null,
      department: DEPT[o.dept], fromStore: { name: o.store }, toStore: o.to ? { name: o.to } : null,
      raiser: { name: o.raiser }, approver: o.approver ? { name: o.approver } : null,
      over_budget: false, approval_note: null,
      lines: Array.from({ length: o.items }, (_, k) => ({ id: o.id * 100 + k, outstanding: () => 0 })), issues,
    };
    r.destination = () => (o.to ? o.to : `Used by ${DEPT[o.dept].name}`);
    r.isOpenForIssue = () => ['approved', 'partial'].includes(o.status);
    r.outstandingQty = () => (o.status === 'partial' ? 6 : (o.status === 'approved' ? 12 : 0));
    r.outstanding_qty = r.outstandingQty();
    r.isOverdue = () => Boolean(o.required) && ['submitted', 'approved', 'partial'].includes(o.status) && o.required < '2026-11-07';
    r.statusBadge = () => `<span class="badge badge-${TONES[o.status] ?? 'slate'}">${LABELS[o.status] ?? o.status}</span>`;
    r.unack_count = issues.filter(i => !i.received_at).length;
    const lastIssue = [...issues].sort((a, b) => (iso(a.date) < iso(b.date) ? 1 : -1))[0] ?? null;
    const since = o.status === 'submitted' ? r.submitted_at
      : (['approved', 'partial'].includes(o.status) ? r.approved_at
        : (['issued', 'closed'].includes(o.status) ? (lastIssue ? lastIssue.date : r.req_date) : r.req_date));
    r.state_since = since;
    r.age_days = Math.max(0, Math.round((Date.parse('2026-11-07T00:00:00Z') - Date.parse(`${iso(since)}T00:00:00Z`)) / 86400000));

    return r;
  };

  const ALL = REQ.map(reqOf);
  // the issues behind the handover card, and the ones that were signed
  const allIssues = ALL.flatMap(r => r.issues.map(i => ({ ...i, req_no: r.req_no })));
  const unsignedRows = allIssues.filter(i => !i.received_at)
    .map(i => ({ req_no: i.req_no, department: i.department?.name, store: i.fromStore.name, date: i.date,
      days: Math.max(0, Math.round((Date.parse('2026-11-07T00:00:00Z') - Date.parse(`${iso(i.date)}T00:00:00Z`)) / 86400000)),
      value: round2(i.value), by: i.issuer?.name }))
    .sort((a, b) => b.days - a.days);
  const signedDays = allIssues.filter(i => i.received_at).map(i => Math.max(0,
    Math.round((Date.parse(`${iso(i.received_at)}T00:00:00Z`) - Date.parse(`${iso(i.date)}T00:00:00Z`)) / 86400000)));

  const counts = {
    action: 6, open: 7, draft: 1, submitted: 2, approved: 3, partial: 1, receipt: unsignedRows.length,
    done: 5, all: ALL.length,
  };

  /* 1. the queues */
  const queue = (statuses, stamp) => {
    const set = ALL.filter(r => statuses.includes(r.status)).map(r => {
      const since = stamp === 'approved' ? (r.approved_at ?? r.submitted_at ?? r.req_date) : (r.submitted_at ?? r.req_date);

      return { req_no: r.req_no, department: r.department?.name, value: round2(r.est_value), since,
        days: Math.max(0, Math.round((Date.parse('2026-11-07T00:00:00Z') - Date.parse(`${iso(since)}T00:00:00Z`)) / 86400000)),
        required_by: r.required_by };
    }).sort((a, b) => b.days - a.days);

    return { count: set.length, value: round2(set.reduce((t, r) => t + r.value, 0)), oldest_days: set.length ? set[0].days : 0,
      oldest: set[0] ?? null, pending: set.filter(r => r.required_by && iso(r.required_by) < '2026-11-07').length };
  };
  const age = { head: queue(['submitted'], 'submitted'), store: queue(['approved', 'partial'], 'approved') };

  /* 2. the allowance */
  const budgetRows = Object.entries(BUDGET).map(([key, b]) => {
    const issued = drift && key === 'front' ? 18900 : b.issued;
    const used = round2(issued + b.committed);

    return { name: DEPT[key].name, budget: b.budget, issued: round2(issued), committed: round2(b.committed), used,
      left: b.budget === null ? null : round2(b.budget - used), pct: b.budget ? Math.round(used / b.budget * 1000) / 10 : null, note: b.note };
  }).filter(r => r.budget !== null).sort((a, b) => b.pct - a.pct);
  const overRows = budgetRows.filter(r => r.left < -0.005);
  const budget = {
    rows: budgetRows.slice(0, 10), count: budgetRows.length, over: overRows.length,
    over_value: round2(overRows.reduce((t, r) => t + Math.abs(r.left), 0)), over_rows: overRows.slice(0, 10),
    worst: budgetRows[0] ?? null, no_budget: Object.values(BUDGET).filter(b => b.budget === null).length,
    total_budget: round2(budgetRows.reduce((t, r) => t + r.budget, 0)), total_used: round2(budgetRows.reduce((t, r) => t + r.used, 0)),
  };

  /* 3. the handover */
  const handover = { count: unsignedRows.length, value: round2(unsignedRows.reduce((t, r) => t + r.value, 0)),
    oldest: unsignedRows[0] ?? null, rows: unsignedRows.slice(0, 10), signed: signedDays.length,
    avg_days: signedDays.length ? Math.round(signedDays.reduce((t, d) => t + d, 0) / signedDays.length * 10) / 10 : null };

  /* the page: the tab, the department filter, and the "raised by me" filter */
  const inTab = (r) => ({ action: true, open: ['draft', 'submitted', 'approved', 'partial'].includes(r.status),
    draft: r.status === 'draft', submitted: r.status === 'submitted', approved: r.status === 'approved',
    partial: r.status === 'partial', receipt: r.unack_count > 0,
    done: ['issued', 'closed', 'rejected', 'cancelled'].includes(r.status), all: true }[tab] ?? true);
  const rows = ALL.filter(r => inTab(r) && (!department || r.department.id === Number(department)) && (!mine || r.raiser.name === 'Ama Boateng'))
    .sort((a, b) => b.id - a.id);

  const monthIssues = allIssues.filter(i => iso(i.date) >= '2026-11-01');

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        const d = new Date(`${String(value).slice(0, 10)}T00:00:00Z`);
        if (Number.isNaN(d.getTime())) return String(value);
        const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()];
        return `${String(d.getUTCDate()).padStart(2, '0')} ${m} ${d.getUTCFullYear()}`;
      },
      bdate: () => TODAY,
      status_badge: (value) => `<span class="badge badge-${TONES[value] ?? 'slate'}">${LABELS[value] ?? value}</span>`,
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? v.req_no ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) {
          if (Array.isArray(params)) tail = params.map(part).join('-');
          else tail = part(params);
        }
        return `#${String(name).replace(/\./g, '-')}${tail ? '-' + tail : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      request: (key) => (key === undefined
        ? { boolean: (k) => Boolean(QUERY[k]), except: (...skip) => Object.fromEntries(Object.entries(QUERY).filter(([k, v]) => v !== null && v !== '' && v !== false && !skip.includes(k))) }
        : (QUERY[key] ?? null)),
      setting: (key, fallback = null) => fallback,
      http_build_query: (obj) => Object.entries(obj ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&'),
      array_filter: (obj) => Object.fromEntries(Object.entries(obj ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== false)),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'RequisitionController' && method === 'TABS') return TABS;
        if (owner === 'Str' && method === 'limit') return String(args[0] ?? '').slice(0, Number(args[1] ?? 30)).trimEnd();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);

        return args[0] ?? '';
      },
      Str: { limit: (value, length = 30) => String(value ?? '').slice(0, length) },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      rows: new Coll(rows), tab, counts,
      departments: { 1: 'Front Office', 2: 'Kitchen', 4: 'Housekeeping', 7: 'Maintenance', 9: 'Security' },
      tabs: TABS,
      monthIssued: round2(monthIssues.reduce((t, i) => t + i.value, 0)),
      monthIssues: monthIssues.length,
      as_of: '2026-11-07', age, budget, handover,
      currentHotel: HOTEL,
    },
  };
}
