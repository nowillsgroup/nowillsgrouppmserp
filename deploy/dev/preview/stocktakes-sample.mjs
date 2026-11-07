/**
 * Sample data for the stock take preview — resources/views/inventory/stocktakes.blade.php rendered the way
 * StockTakeController::index() hands it over: the count sheets, the per-take summary, and the three readings.
 *
 * A count sheet is the only document in the app that checks the stock book against the shelf, and it is worth nothing
 * until somebody posts it — and then only for the lines somebody actually counted. Six sheets are on record:
 *
 *   • the variance — three posted counts, 102 lines, 13 of them adjusted, 3,660.00 short against 870.00 over: a net
 *     2,790.00 the ledger has been told about. The largest single line is cooking gas on ST-2026-0118: 2 cylinders
 *     counted against 14 on the book, 4,560.00.
 *   • the coverage — 96 of the 102 lines were counted, so six were posted with the book quantity untouched. ST-2026-0118
 *     is the thinnest at 44 of 48 lines, 91.7 %, which is a partial proof of the shelf passed off as a full one.
 *   • the posting — two drafts, one of them (ST-2026-0119, engineering) counted on 3 November and still waiting, with
 *     18 of its 26 lines filled in and 1,240.00 the book has not been told about.
 *
 * The drift build posts that engineering sheet and changes nothing else on the shelf: the variance moves into the books
 * (4,030.00 net), the coverage falls to 89.1 % because 18 of 128 lines were never counted, and one draft is left.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* Laravel hands the view a paginator: countable and iterable, with links() at the foot. */
class Pager extends Coll {
  total() {
    return this.count();
  }

  links() {
    return raw('');
  }
}

const TAKES = [
  { id: 120, no: 'ST-2026-0120', store: 'Main Store', date: '2026-11-07', status: 'draft', lines: 48, counted: 0,
    varied: 0, short: 0, over: 0, value: 0, creator: 'Ama Boateng', poster: null, variance_value: 0 },
  { id: 119, no: 'ST-2026-0119', store: 'Engineering Store', date: '2026-11-03', status: 'draft', lines: 26, counted: 18,
    varied: 4, short: -1400, over: 160, value: -1240, creator: 'Yaw Antwi', poster: null, variance_value: 0 },
  { id: 118, no: 'ST-2026-0118', store: 'Main Store', date: '2026-11-04', status: 'posted', lines: 48, counted: 44,
    varied: 6, short: -2560, over: 220, value: -2340, creator: 'Ama Boateng', poster: 'Kofi Mensah', variance_value: -2340 },
  { id: 117, no: 'ST-2026-0117', store: 'Kitchen Store', date: '2026-11-02', status: 'posted', lines: 32, counted: 30,
    varied: 4, short: -980, over: 120, value: -860, creator: 'Kwesi Appiah', poster: 'Kofi Mensah', variance_value: -860 },
  { id: 116, no: 'ST-2026-0116', store: 'Housekeeping Store', date: '2026-10-28', status: 'posted', lines: 22, counted: 22,
    varied: 3, short: -120, over: 530, value: 410, creator: 'Efua Mensah', poster: 'Kofi Mensah', variance_value: 410 },
  { id: 115, no: 'ST-2026-0115', store: 'Kitchen Store', date: '2026-10-21', status: 'cancelled', lines: 30, counted: 0,
    varied: 0, short: 0, over: 0, value: 0, creator: 'Kwesi Appiah', poster: null, variance_value: 0 },
];

const round2 = (v) => Math.round(Number(v ?? 0) * 100) / 100;
const daysBack = (iso) => Math.max(0, Math.round((Date.parse('2026-11-07T00:00:00Z') - Date.parse(`${iso}T00:00:00Z`)) / 86400000));

export function stocktakesContext({ drift = false } = {}) {
  const QUERY = {};

  // the drift is one thing: the engineering sheet gets posted
  const posted = TAKES.map(t => (drift && t.no === 'ST-2026-0119'
    ? { ...t, status: 'posted', poster: 'Kofi Mensah', variance_value: t.value }
    : t));

  const takeObjs = posted.map(t => ({
    id: t.id, take_no: t.no, store: { name: t.store }, count_date: day(t.date), status: t.status,
    creator: { name: t.creator }, poster: t.poster ? { name: t.poster } : null,
    variance_value: t.status === 'posted' ? t.variance_value : 0,
    lines_count: t.lines, counted_count: t.counted,
  }));

  const summary = {};
  posted.forEach(t => {
    summary[t.id] = { lines: t.lines, counted: t.counted, pct: t.lines ? Math.round(t.counted / t.lines * 1000) / 10 : null,
      varied: t.varied, value: round2(t.value), short: round2(t.short), over: round2(t.over), days: daysBack(t.date) };
  });

  const isPosted = (t) => t.status === 'posted';
  const postedTakes = posted.filter(isPosted);
  const lines = postedTakes.reduce((s, t) => s + t.lines, 0);
  const counted = postedTakes.reduce((s, t) => s + t.counted, 0);

  const postedRows = postedTakes.map(t => ({ take: t.no, store: t.store, counted: t.counted, lines: t.lines,
    pct: summary[t.id].pct, varied: t.varied, short: t.short, over: t.over, value: t.value, days: summary[t.id].days }));

  const worstTake = postedTakes.slice().sort((a, b) => summary[a.id].pct - summary[b.id].pct)[0];

  const pctTone = (Math.round(counted / (lines || 1) * 1000) / 10) >= 95 ? 'green' : 'amber';

  const variance = {
    takes: postedTakes.length, lines, counted, skipped: lines - counted,
    pct: lines ? Math.round(counted / lines * 1000) / 10 : null,
    value: round2(postedTakes.reduce((s, t) => s + t.value, 0)),
    short: round2(postedTakes.reduce((s, t) => s + t.short, 0)),
    over: round2(postedTakes.reduce((s, t) => s + t.over, 0)),
    adjusted: postedTakes.reduce((s, t) => s + t.varied, 0),
    rows: postedRows.slice().sort((a, b) => Math.abs(b.value) - Math.abs(a.value)),
    coverage_rows: postedRows.slice().sort((a, b) => a.pct - b.pct).map(r => ({ ...r, tone: r.pct < 95 ? 'text-red bold' : '' })),
    worst_take: { id: worstTake.id, take_no: worstTake.no, store: worstTake.store },
    pct_tone: pctTone,
    worst_short: { item: 'Cooking gas', unit: 'cylinders', take: 'ST-2026-0118', counted: 2, system: 14, qty: 12, cost: 380, value: 4560 },
  };
  variance.pct_label = `${variance.pct} %`;
  variance.value_sub = `${variance.adjusted} line${variance.adjusted === 1 ? '' : 's'} adjusted across the posted counts`;

  const drafts = posted.filter(t => t.status === 'draft').sort((a, b) => (a.date < b.date ? -1 : 1));
  const oldestDays = drafts.length ? Math.max(...drafts.map(t => daysBack(t.date))) : 0;

  const posting = {
    drafts: drafts.length,
    cancelled: posted.filter(t => t.status === 'cancelled').length,
    value: round2(drafts.reduce((s, t) => s + Math.abs(t.value), 0)),
    lines: drafts.reduce((s, t) => s + t.lines, 0),
    counted: drafts.reduce((s, t) => s + t.counted, 0),
    oldest: drafts[0] ? { id: drafts[0].id, take_no: drafts[0].no, count_date: day(drafts[0].date) } : null,
    oldest_days: oldestDays,
    oldest_tone: oldestDays > 7 ? 'red' : 'slate',
    sub: `${drafts.length} draft${drafts.length === 1 ? '' : 's'} · oldest ${oldestDays} day${oldestDays === 1 ? '' : 's'} unposted`,
    rows: drafts.map(t => ({ take: t.no, store: t.store, date: day(t.date), days: daysBack(t.date),
      counted: t.counted, lines: t.lines, value: round2(t.value), tone: daysBack(t.date) > 7 ? 'text-red bold' : '' })),
  };

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
      status_badge: (value) => `<span class="badge badge-${{ draft: 'amber', posted: 'green', cancelled: 'slate' }[value] ?? 'slate'}">${String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1)}</span>`,
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) tail = Array.isArray(params) ? params.map(part).join('-') : part(params);
        return `#${String(name).replace(/\./g, '-')}${tail ? '-' + tail : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      setting: (key, fallback = null) => fallback,
      request: (key) => (key === undefined ? { boolean: () => false, except: () => ({}) } : (QUERY[key] ?? null)),
      http_build_query: (obj) => Object.entries(obj ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&'),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);

        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      takes: new Pager(takeObjs), summary, variance, posting,
      stores: { 1: 'Main Store', 2: 'Kitchen Store', 3: 'Engineering Store', 4: 'Housekeeping Store' },
      categories: { 1: 'Food', 2: 'Fuel', 3: 'Guest Supplies', 4: 'Linen' },
      currentHotel: HOTEL,
    },
  };
}
