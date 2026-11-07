/**
 * Sample data for the RFQ award screen — resources/views/procurement/rfq-show.blade.php rendered the way
 * ProcurementController::show() hands it over: the request, its invitations, ProcurementService::comparative()'s rows and
 * cells, the v9.28 promise readings, and the verdict they add up to.
 *
 * The request is RFQ-2026-0055, the December beverage restock the register lists as closed on 30 October and still
 * waiting on an award eight days later: five lines into the Beverage Store, needed by 10 November, three suppliers asked
 * and three answered.
 *
 * The comparative statement ranks the bids on landed price line by line — the only fair way to compare them — and it
 * ranks Coastal Beverages first at 5,639.60 against Accra Fresh Foods at 5,781.05 and Kaneshie Wholesale at 6,065.10.
 * That ranking is correct and it is also useless on its own:
 *
 *   • Coastal quotes seven days from the day the order is placed, so its goods land on 14 November — four days after the
 *     store needs them. It is the cheapest bid and it cannot be taken.
 *   • Kaneshie quotes two days and is on time, but its price expired on 31 October. The award screen will still let it be
 *     chosen, which is exactly why the expiry is marked.
 *   • Accra is 141.45 dearer than Coastal and lands on the needed date. It is the only bid that is both valid and
 *     deliverable — which is the finding this round exists to print, and the reason the verdict on the card names it.
 *
 * Against the rates the property last paid for the same lines — 44.00, 40.00, 22.00 and 36.50 twice, 5,122.00 net — the
 * cheapest bid saves 218.00. The comparison is on landed price, so the statement's own 218.00 is stated as the net saving
 * it is rather than being added to a VAT-inclusive figure.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);
const dmy = (value) => {
  if (value == null || value === '') return '—';
  const iso = typeof value?.toDateString === 'function' ? value.toDateString() : String(value).slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return String(value);

  return `${iso.slice(8, 10)} ${MONTHS[Number(iso.slice(5, 7)) - 1]} ${iso.slice(0, 4)}`;
};
const round2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;
const cash = (v) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const supplier = (id, name, email) => ({ id, name, email, phone: '030 2' + String(20 + id) + ' 1100' });
const COASTAL = supplier(7, 'Coastal Beverages Ltd', 'orders@coastalbeverages.gh');
const ACCRA = supplier(9, 'Accra Fresh Foods', 'sales@accrafresh.gh');
const KANESHIE = supplier(31, 'Kaneshie Wholesale Ltd', 'quotes@kaneshiewholesale.gh');

const STORE = { id: 5, name: 'Beverage Store' };

/* ── the five lines the store asked for, and the rate each was last bought at ── */
const LINES = [
  { id: 201, name: 'Club Beer 330ml (crate of 24)', unit: 'crate', qty: 60, last: 44.00 },
  { id: 202, name: 'Malta Guinness (crate of 24)', unit: 'crate', qty: 20, last: 40.00 },
  { id: 203, name: 'Voltic Water 1.5L (pack of 12)', unit: 'pack', qty: 30, last: 22.00 },
  { id: 204, name: 'Coca-Cola 300ml (crate of 24)', unit: 'crate', qty: 16, last: 36.50 },
  { id: 205, name: 'Sprite 300ml (crate of 24)', unit: 'crate', qty: 12, last: 36.50 },
];

const RFQ_LINES = LINES.map(l => ({
  id: l.id, qty: l.qty, specification: null, award_reason: null, awarded_quote_line_id: null,
  item: { id: l.id, name: l.name, unit: l.unit },
}));

/* ── three quotes, each answering all five lines ─────────────────────────── */
const QUOTE_SPECS = [
  { id: 551, supplier: COASTAL, rates: [42.00, 38.00, 21.00, 35.50, 35.50], delivery: 7, terms: 30,
    valid: '2026-11-20', source: 'portal', revision: 1, date: '2026-10-28', remarks: null },
  { id: 552, supplier: ACCRA, rates: [43.00, 39.00, 21.50, 36.50, 36.50], delivery: 3, terms: 14,
    valid: '2026-11-12', source: 'portal', revision: 1, date: '2026-10-29', remarks: 'Prices hold if collected in two drops.' },
  { id: 553, supplier: KANESHIE, rates: [45.00, 41.00, 23.00, 38.00, 38.00], delivery: 2, terms: 45,
    valid: '2026-10-31', source: 'manual', revision: 2, date: '2026-10-29', remarks: 'Keyed in from the supplier’s e-mail.' },
];

const quoteLines = (spec) => new Coll(LINES.map((l, i) => ({
  id: spec.id * 10 + i + 1, rfq_line_id: l.id, rate: spec.rates[i], tax_rate: 15, qty_offered: null,
  remarks: null, amount: round2(l.qty * spec.rates[i]),
  // the service rounds landed to four places so the line total can carry cents: 35.50 × 1.15 is 40.825, not 40.83
  landed: () => Math.round(spec.rates[i] * 1.15 * 10000) / 10000,
})));

const QUOTES = QUOTE_SPECS.map(spec => {
  const lines = quoteLines(spec);
  const net = round2(lines.items.reduce((t, l) => t + l.amount, 0));
  const tax = round2(net * 0.15);

  return {
    id: spec.id, supplier_id: spec.supplier.id, supplier: spec.supplier, quote_date: day(spec.date),
    delivery_days: spec.delivery, payment_terms_days: spec.terms, valid_until: day(spec.valid),
    source: spec.source, revision: spec.revision, remarks: spec.remarks,
    subtotal: net, tax, total: round2(net + tax), lines,
  };
}).sort((a, b) => a.total - b.total);

/* ── ProcurementService::comparative()'s rows, cells and totals ─────────── */
const SCORES = {
  7: { grade: 'A', score: 4.6 }, 9: { grade: 'B', score: 3.9 }, 31: { grade: 'C', score: 3.1 },
};
const CMP_ROWS = LINES.map((l, i) => {
  const cells = {};
  for (const q of QUOTES) {
    const ql = q.lines.items[i];
    cells[q.id] = { ql, landed: ql.landed(), full: true, qty: l.qty, amount: round2(l.qty * ql.landed()) };
  }
  const ranked = Object.values(cells).sort((a, b) => a.landed - b.landed);
  const low = ranked[0];

  return {
    line: RFQ_LINES[i], cells, lowest: low.ql.id, lowestLanded: low.landed, bids: 3, last: l.last,
    saving: round2((l.last - low.ql.rate) * l.qty),
  };
});

const LOW_TOTAL = round2(CMP_ROWS.reduce((t, r) => t + r.cells[QUOTES[0].id].amount, 0));

const CMP = {
  quotes: new Coll(QUOTES), rows: CMP_ROWS, lowTotal: LOW_TOTAL,
  scores: Object.fromEntries(Object.entries(SCORES).map(([id, s]) => [id, { supplier_id: Number(id), ...s }])),
};

/* ── the invitations, all three of which answered ─────────────────────────*/
const invite = (sup, viewed, responded) => ({
  supplier_id: sup.id, supplier: sup, invited_at: new SampleDate('2026-10-23T09:00:00Z'),
  viewed_at: new SampleDate(`${viewed.replace(' ', 'T')}:00Z`), responded_at: new SampleDate(`${responded.replace(' ', 'T')}:00Z`),
  declined_at: null, decline_reason: null, email_status: 'delivered',
  state: () => 'quoted',
  link: () => raw(`#rfq-public-${'x'.repeat(30)}`),
});

const INVITES = new Coll([
  invite(COASTAL, '2026-10-23 10:40', '2026-10-28 09:30'),
  invite(ACCRA, '2026-10-23 15:15', '2026-10-29 11:20'),
  invite(KANESHIE, '2026-10-24 08:05', '2026-10-29 17:40'),
]);

const RFQ = {
  id: 55, rfq_no: 'RFQ-2026-0055', title: 'Beverage restock — December', status: 'closed',
  rfq_date: day('2026-10-23'), respond_by: day('2026-10-30'), needed_by: day('2026-11-10'),
  sent_at: new SampleDate('2026-10-23T09:05:00Z'), closed_at: day('2026-10-30'), awarded_at: null,
  cancel_reason: null, award_note: null,
  terms: 'Prices per unit as quoted, delivered to the Beverage Store, goods to arrive on or before 10 November 2026. Part deliveries are not accepted without written agreement.',
  store: STORE, creator: { name: 'Ama Boateng' }, awarder: null,
  lines: new Coll(RFQ_LINES), invites: INVITES, quotes: new Coll(QUOTES), purchaseOrders: new Coll([]),
  deadlinePassed: () => true,
  acceptingQuotes: () => false,
};

export function rfqShowContext() {
  /* ── v9.28 the promise readings and the verdict, as the controller computes them ── */
  const needed = RFQ.needed_by;
  const promise = new Coll(CMP.quotes.items.map(q => {
    const days = q.delivery_days;
    const arrives = TODAY.copy().addDays(days);
    const lateDays = arrives.toDateString() > needed.toDateString()
      ? Math.round((Date.parse(`${arrives.toDateString()}T00:00:00Z`) - Date.parse(`${needed.toDateString()}T00:00:00Z`)) / 86400000) : 0;
    const score = CMP.scores[q.supplier_id];
    const expiresIn = Math.round((Date.parse(`${q.valid_until.toDateString()}T00:00:00Z`) - Date.parse('2026-11-07T00:00:00Z')) / 86400000);

    return {
      id: q.id, supplier: q.supplier.name, total: q.total, lines: q.lines.count(), of: RFQ.lines.count(),
      partial: false, delivery_days: days, arrives, late: lateDays > 0, late_days: lateDays,
      terms_days: q.payment_terms_days, valid_until: q.valid_until,
      expired: q.valid_until.toDateString() < '2026-11-07', expires_in: expiresIn,
      grade: score?.grade ?? null, score: score?.score ?? null,
    };
  }));

  const late = promise.items.filter(p => p.late);
  const expired = promise.items.filter(p => p.expired);
  const usable = promise.items.filter(p => ! p.late && ! p.expired);
  const atStake = round2(CMP_ROWS.reduce((t, r) => t + r.last * r.line.qty, 0));
  const saving = round2(CMP_ROWS.reduce((t, r) => t + r.saving, 0));
  const lowest = CMP.quotes.items[0];
  const pick = usable[0];

  const head = {
    quotes: CMP.quotes.count(), invited: INVITES.count(), low: LOW_TOTAL, lowest,
    at_stake: atStake, saving, late: late.length, expired: expired.length,
    pick, worst: usable[usable.length - 1], days_left: 3,
    sub: `${CMP.quotes.count()} of ${INVITES.count()} invited suppliers quoted`,
  };
  const cheapest = promise.items.find(p => p.id === lowest.id);
  head.verdict = `The cheapest bid is ${cheapest.supplier} at GH₵ ${cash(cheapest.total)}, but it lands `
    + `${cheapest.late_days} days after the store needs it. ${pick.supplier} is GH₵ ${cash(pick.total - cheapest.total)} `
    + 'dearer and can be delivered on time.';
  head.tone = 'amber';
  head.blocked_sub = [
    late.length ? `${late.length} arrive${late.length === 1 ? 's' : ''} after the store needs it` : null,
    expired.length ? `${expired.length} quote${expired.length === 1 ? '' : 's'} expired before the award` : null,
  ].filter(Boolean).join(' · ') || 'every bid can still be taken';
  head.blocked_tone = late.length + expired.length ? 'red' : 'green';
  head.needed_tone = 'slate';
  head.needed_sub = '3 days left for the goods to arrive';

  return {
    scope: {
      rfq: RFQ, cmp: CMP, minQuotes: 3, promise, head,
      openStatuses: ['draft', 'sent', 'closed'],
      buyer: true, canAward: true, quotes: CMP.quotes, awarded: false,
      others: new Coll([]),
    },
    fallbacks: {},
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy,
      bdate: () => TODAY,
      now: () => TODAY,
      status_badge: (status) => {
        const map = { draft: ['amber', 'Draft'], sent: ['green', 'Sent'], closed: ['slate', 'Closed'],
          awarded: ['green', 'Awarded'], cancelled: ['red', 'Cancelled'], quoted: ['green', 'Quoted'],
          viewed: ['purple', 'Viewed'], invited: ['blue', 'Invited'], declined: ['red', 'Declined'] };
        const [tone, label] = map[status] ?? ['slate', String(status ?? '')];
        return `<span class="badge badge-${tone}">${label}</span>`;
      },
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) {
          if (params && typeof params === 'object' && !Array.isArray(params) && params.id !== undefined) tail = '-' + params.id;
          else if (Array.isArray(params)) tail = '-' + params.map(part).join('-');
          else if (typeof params === 'object') {
            const segments = [];
            const query = [];
            Object.entries(params).forEach(([k, v]) => {
              if (/^\d+$/.test(k)) segments.push(part(v));
              else if (Array.isArray(v)) query.push(`${k}[]=${v.map(part).join(',')}`);
              else query.push(`${k}=${part(v)}`);
            });
            tail = (segments.length ? '-' + segments.join('-') : '') + (query.length ? '?' + query.join('&') : '');
          } else tail = '-' + part(params);
        }

        return `#${String(name).replace(/\./g, '-')}${tail}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      old: (key, fallback = null) => fallback,
      can_do: (cap) => ['purchasing.rfq'].includes(cap),
      request: (key) => (key === undefined ? { except: () => ({}) } : null),
      setting: (key, fallback = null) => (key === 'rfq_min_quotes' ? '3' : fallback),
      hotel: () => HOTEL,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : [])),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      isset: value => value !== undefined && value !== null,
      in_array: (needle, haystack) => haystack.some(v => String(v) === String(needle)),
      min: (...args) => Math.min(...args.map(Number)),
      max: (...args) => Math.max(...args.map(Number)),
      e: value => String(value ?? ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
  };
}
