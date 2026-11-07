/**
 * Sample data for the RFQ register — resources/views/procurement/rfqs.blade.php rendered the way
 * ProcurementController::rfqs() hands it over: the page of requests, the tabs, the ready-to-award alert, and the v9.27
 * readings computed from the invitations, the deadlines and the awards.
 *
 * An RFQ is the only document in the chain where the price is argued for rather than given, so the register answers
 * three questions that come from three different records:
 *
 *   • what came back — nine of thirteen invited suppliers quoted, 69.2 %. One declined (out of stock), one opened the
 *     request and never answered, and two never opened it at all. A register that printed "9 quotes" could not tell a
 *     buyer which of the four to chase.
 *   • the clock — three requests are past their deadline with no award made. The oldest is RFQ-2026-0055, closed on
 *     30 October and still unanswered eight days later; RFQ-2026-0061 has two quotes where this property asks for three,
 *     so any award it makes has to be justified.
 *   • what the competition bought — three awards this month, 21,460.00 placed. The same lines at the lowest rate offered
 *     for each would have cost 21,110.00 and at the highest 24,110.00, so the awards came 2,650.00 under the dearest bid
 *     and 350.00 over the cheapest. Three lines went to a supplier who was not the lowest bidder and all three carry a
 *     written justification — which is the only thing that makes that 350.00 a decision rather than a leak.
 *
 * The register itself lists ten requests: two drafts nobody has sent, three out for quotation, one closed awaiting an
 * award, three awarded in November and one cancelled.
 *
 * "Today" is 7 November 2026; the month is November to date.
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

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

/* Laravel hands the view a paginator: countable and iterable, with links() at the foot. */
class Pager extends Coll {
  links() {
    return raw('<nav class="pager" aria-label="Pages"><span class="current">1</span> <a href="?page=2">2</a></nav>');
  }
}

const supplier = (id, name, email) => ({ id, name, email, phone: '030 2' + String(20 + id) + ' 1100' });
const ACCRA = supplier(9, 'Accra Fresh Foods', 'sales@accrafresh.gh');
const COASTAL = supplier(7, 'Coastal Beverages Ltd', 'orders@coastalbeverages.gh');
const SAHEL = supplier(4, 'Sahel Textiles', 'quotes@saheltextiles.com');
const TEMA = supplier(15, 'Tema Hardware', 'sales@temahardware.gh');
const VOLTA = supplier(12, 'Volta Power Systems', 'info@voltapower.gh');
const ECOLAB = supplier(21, 'Ecolab Ghana', 'ghana@ecolab.example');
const DEVRA = supplier(23, 'Devtraco Chemicals', 'sales@devtraco.example');

const STORES = { kitchen: { id: 2, name: 'Kitchen Store' }, house: { id: 4, name: 'Housekeeping Store' },
  eng: { id: 3, name: 'Engineering Store' }, bev: { id: 5, name: 'Beverage Store' }, pool: { id: 6, name: 'Pool Store' } };

/* ── nine requests: two draft, three out, one closed, three awarded, one cancelled ── */
const RFQS = [
  { id: 56, no: 'RFQ-2026-0056', title: 'Housekeeping consumables — November', store: STORES.house, date: '2026-11-01', due: '2026-11-12', needed: '2026-11-20', status: 'draft', items: 5, invites: 0, quotes: 0, by: 'Ama Boateng' },
  { id: 59, no: 'RFQ-2026-0059', title: 'Bar glassware replacement', store: STORES.bev, date: '2026-11-04', due: '2026-11-16', needed: '2026-11-28', status: 'draft', items: 3, invites: 0, quotes: 0, by: 'Kofi Mensah' },
  { id: 61, no: 'RFQ-2026-0061', title: 'Kitchen dry goods — November', store: STORES.kitchen, date: '2026-11-02', due: '2026-11-06', needed: '2026-11-14', status: 'sent', items: 6, invites: 4, quotes: 2, by: 'Ama Boateng' },
  { id: 62, no: 'RFQ-2026-0062', title: 'Laundry linen top-up', store: STORES.house, date: '2026-11-03', due: '2026-11-09', needed: '2026-11-22', status: 'sent', items: 4, invites: 3, quotes: 1, by: 'Kofi Mensah' },
  { id: 58, no: 'RFQ-2026-0058', title: 'Engineering spares — pumps', store: STORES.eng, date: '2026-10-28', due: '2026-11-04', needed: '2026-11-18', status: 'sent', items: 4, invites: 3, quotes: 3, by: 'Yaw Antwi' },
  { id: 55, no: 'RFQ-2026-0055', title: 'Beverage restock — December', store: STORES.bev, date: '2026-10-23', due: '2026-10-30', needed: '2026-11-10', status: 'closed', items: 5, invites: 3, quotes: 3, by: 'Ama Boateng' },
  { id: 53, no: 'RFQ-2026-0053', title: 'Pool chemicals — monthly', store: STORES.pool, date: '2026-10-26', due: '2026-10-31', needed: '2026-11-08', status: 'awarded', items: 3, invites: 3, quotes: 3, by: 'Yaw Antwi', awarded: '2026-11-06' },
  { id: 51, no: 'RFQ-2026-0051', title: 'Laundry chemicals', store: STORES.house, date: '2026-10-25', due: '2026-10-30', needed: '2026-11-05', status: 'awarded', items: 3, invites: 3, quotes: 3, by: 'Kofi Mensah', awarded: '2026-11-05' },
  { id: 49, no: 'RFQ-2026-0049', title: 'Bar glassware — initial order', store: STORES.bev, date: '2026-10-22', due: '2026-10-27', needed: '2026-11-04', status: 'awarded', items: 4, invites: 3, quotes: 3, by: 'Ama Boateng', awarded: '2026-11-03' },
  { id: 60, no: 'RFQ-2026-0060', title: 'Garden machinery service', store: STORES.eng, date: '2026-11-03', due: '2026-11-10', needed: '2026-11-25', status: 'cancelled', items: 2, invites: 2, quotes: 0, by: 'Yaw Antwi' },
];

const CREATORS = { 'Ama Boateng': { name: 'Ama Boateng' }, 'Kofi Mensah': { name: 'Kofi Mensah' }, 'Yaw Antwi': { name: 'Yaw Antwi' } };

/* the invitations behind the four requests still asking for a price, so the response reading has something to count */
const INVITES = [
  { rfq: 61, supplier: ACCRA, viewed: '2026-11-02 09:12', responded: '2026-11-04 11:30', declined: null, reason: null },
  { rfq: 61, supplier: COASTAL, viewed: '2026-11-02 14:40', responded: '2026-11-05 08:05', declined: null, reason: null },
  { rfq: 61, supplier: SAHEL, viewed: '2026-11-03 10:20', responded: null, declined: null, reason: null },
  { rfq: 61, supplier: TEMA, viewed: null, responded: null, declined: '2026-11-03 16:45', reason: 'out of stock until January' },
  { rfq: 62, supplier: SAHEL, viewed: '2026-11-03 12:05', responded: '2026-11-06 09:40', declined: null, reason: null },
  { rfq: 62, supplier: ECOLAB, viewed: '2026-11-04 08:30', responded: null, declined: null, reason: null },
  { rfq: 62, supplier: DEVRA, viewed: null, responded: null, declined: null, reason: null },
  { rfq: 58, supplier: VOLTA, viewed: '2026-10-28 11:00', responded: '2026-11-01 10:15', declined: null, reason: null },
  { rfq: 58, supplier: TEMA, viewed: '2026-10-28 13:25', responded: '2026-11-02 15:50', declined: null, reason: null },
  { rfq: 58, supplier: ECOLAB, viewed: '2026-10-29 09:10', responded: '2026-11-03 12:00', declined: null, reason: null },
  { rfq: 55, supplier: COASTAL, viewed: '2026-10-23 10:40', responded: '2026-10-28 09:30', declined: null, reason: null },
  { rfq: 55, supplier: ACCRA, viewed: '2026-10-23 15:15', responded: '2026-10-29 11:20', declined: null, reason: null },
  { rfq: 55, supplier: VOLTA, viewed: '2026-10-24 08:05', responded: '2026-10-29 17:40', declined: null, reason: null },
];

const invite = (o) => ({
  supplier_id: o.supplier.id, supplier: o.supplier,
  invited_at: new SampleDate(`${o.viewed ?? '2026-11-02'}T08:00:00Z`.replace(' ', 'T').slice(0, 19) + 'Z'),
  viewed_at: o.viewed ? new SampleDate(`${o.viewed.replace(' ', 'T')}:00Z`) : null,
  responded_at: o.responded ? new SampleDate(`${o.responded.replace(' ', 'T')}:00Z`) : null,
  declined_at: o.declined ? new SampleDate(`${o.declined.replace(' ', 'T')}:00Z`) : null,
  decline_reason: o.reason, email_status: 'delivered',
  state() { return this.declined_at ? 'declined' : this.responded_at ? 'quoted' : this.viewed_at ? 'viewed' : 'invited'; },
  link: () => '#rfq-public-'.padEnd(42, 'x'),
});

const RFQ_BY_ID = Object.fromEntries(RFQS.map(r => [r.id, r]));

const requests = RFQS.map(r => ({
  id: r.id, rfq_no: r.no, title: r.title, status: r.status,
  rfq_date: day(r.date), respond_by: day(r.due), needed_by: day(r.needed), sent_at: r.status === 'draft' ? null : day(r.date),
  closed_at: r.status === 'closed' || r.status === 'awarded' ? day(r.due) : null,
  awarded_at: r.awarded ? day(r.awarded) : null, cancel_reason: r.status === 'cancelled' ? 'Specification changed — reissued as RFQ-2026-0063' : null,
  store: r.store, creator: CREATORS[r.by], awarder: r.awarded ? { name: 'Kofi Mensah' } : null,
  invites: new Coll(INVITES.filter(i => i.rfq === r.id).map(invite)),
  invites_count: r.invites, quotes_count: r.quotes, lines_count: r.items,
  deadlinePassed: () => r.due < '2026-11-07',
}));

const page = new Pager(requests);

/* ── the three readings the controller computes ─────────────────────────── */
const live = requests.filter(r => ['sent', 'closed'].includes(r.status));
const allInvites = live.flatMap(r => r.invites.items.map(i => ({ rfq: r, invite: i })));
const declined = allInvites.filter(r => r.invite.declined_at && ! r.invite.responded_at);
const silent = allInvites.filter(r => ! r.invite.responded_at && ! r.invite.declined_at && r.invite.viewed_at);
const ignored = allInvites.filter(r => ! r.invite.responded_at && ! r.invite.declined_at && ! r.invite.viewed_at);
const quoted = live.reduce((t, r) => t + r.quotes_count, 0);

const response = {
  requests: live.length, invited: allInvites.length, quoted,
  rate: Math.round((quoted / allInvites.length) * 1000) / 10,
  declined: declined.length, silent: silent.length, ignored: ignored.length,
  rows: silent.map(r => ({ rfq_no: r.rfq.rfq_no, rfq_id: r.rfq.id, supplier: r.invite.supplier.name,
    viewed: r.invite.viewed_at, deadline: r.rfq.respond_by })),
  declined_rows: declined.map(r => ({ supplier: r.invite.supplier.name, reason: r.invite.decline_reason, rfq_no: r.rfq.rfq_no })),
};
response.sub = `${response.quoted} of ${response.invited} invited suppliers answered — ${response.rate}%`;

const ready = requests.filter(r => r.status === 'closed' || (r.status === 'sent' && r.deadlinePassed()))
  .sort((a, b) => (a.respond_by.toDateString() < b.respond_by.toDateString() ? -1 : 1));
const DAY = 86400000;
const daysSince = (d) => Math.round((Date.parse('2026-11-07T00:00:00Z') - Date.parse(`${d.toDateString()}T00:00:00Z`)) / DAY);
const clock = {
  ready: ready.length, min: 3,
  oldest_days: ready.length ? daysSince(ready[0].respond_by) : 0,
  oldest: ready[0] ?? null,
  short: ready.filter(r => r.quotes_count < 3).length,
  noquotes: ready.filter(r => r.quotes_count === 0).length,
  closing: requests.filter(r => r.status === 'sent' && ! r.deadlinePassed() && daysSince(r.respond_by) * -1 <= 2).length,
  rows: ready.slice(0, 6).map(r => ({ id: r.id, rfq_no: r.rfq_no, title: r.title, quotes: r.quotes_count,
    invites: r.invites_count, deadline: r.respond_by, days: daysSince(r.respond_by), short: r.quotes_count < 3 })),
};
clock.sub = `${clock.ready} requests waiting on an award, the oldest ${clock.oldest_days} days`;

/* the three awards: placed, the lowest bid line by line, the dearest, and the lines that went elsewhere */
const AWARDS_CLEAN = [
  { rfq_no: 'RFQ-2026-0049', title: 'Bar glassware — initial order', quotes: 3, placed: 9860, cheapest: 9640, dearest: 11240, away: 2, extra: 220, justified: 2 },
  { rfq_no: 'RFQ-2026-0051', title: 'Laundry chemicals', quotes: 3, placed: 5420, cheapest: 5420, dearest: 5900, away: 0, extra: 0, justified: 0 },
  { rfq_no: 'RFQ-2026-0053', title: 'Pool chemicals — monthly', quotes: 3, placed: 6180, cheapest: 6050, dearest: 6970, away: 1, extra: 130, justified: 1 },
];

/* the drifted month: every award took the dearest bid and nobody wrote a reason. The register's whole job is to make that
   look like what it is — so the same three requests, the same lines, placed at the top of the range instead of the bottom. */
const AWARDS_DRIFT = [
  { rfq_no: 'RFQ-2026-0049', title: 'Bar glassware — initial order', quotes: 3, placed: 11240, cheapest: 9640, dearest: 11240, away: 4, extra: 1600, justified: 0 },
  { rfq_no: 'RFQ-2026-0051', title: 'Laundry chemicals', quotes: 3, placed: 5900, cheapest: 5420, dearest: 5900, away: 3, extra: 480, justified: 0 },
  { rfq_no: 'RFQ-2026-0053', title: 'Pool chemicals — monthly', quotes: 3, placed: 6970, cheapest: 6050, dearest: 6970, away: 3, extra: 920, justified: 0 },
];
const cash = (v) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/* the award reading, from whichever month the build is drawing */
const awardFor = (list) => {
  const award = {
    count: list.length,
    placed: list.reduce((t, a) => t + a.placed, 0),
    cheapest: list.reduce((t, a) => t + a.cheapest, 0),
    dearest: list.reduce((t, a) => t + a.dearest, 0),
    away: list.reduce((t, a) => t + a.away, 0),
    extra: list.reduce((t, a) => t + a.extra, 0),
    justified: list.reduce((t, a) => t + a.justified, 0),
    rows: list.map(a => ({ ...a, saved: a.dearest - a.placed })),
  };
  award.saved = award.dearest - award.placed;
  award.sub = `GH₵ ${cash(award.saved)} under the dearest bid, on GH₵ ${cash(award.placed)} placed`;
  award.tone = award.justified < award.away ? 'red' : award.away ? 'amber' : 'green';
  award.away_sub = award.away
    ? `${award.away} ${award.away === 1 ? 'line' : 'lines'} went to a supplier who was not the lowest bidder — `
      + (award.justified === award.away ? 'each one carries a written justification'
        : award.justified === 0 ? 'none of them carries a written justification' : `${award.justified} of them carry a written justification`)
      + `, and together they cost GH₵ ${cash(award.extra)} more than the lowest bid`
    : 'every line went to the lowest bidder';

  return award;
};

const BADGES = {
  draft: ['amber', 'Draft'], sent: ['green', 'Sent'], closed: ['slate', 'Closed'], awarded: ['green', 'Awarded'],
  cancelled: ['red', 'Cancelled'], quote_by: ['blue', 'Quote By'], passed: ['amber', 'Passed'],
  quoted: ['green', 'Quoted'], viewed: ['purple', 'Viewed'], invited: ['blue', 'Invited'], declined: ['red', 'Declined'],
};

export function rfqsContext({ drift = false } = {}) {
  const award = awardFor(drift ? AWARDS_DRIFT : AWARDS_CLEAN);

  return {
    scope: {
      rfqs: page, status: null, ready: new Coll(ready),
      counts: { draft: 2, sent: 3, closed: 1, awarded: 3, cancelled: 1 },
      response, clock, award, min: 3,
    },
    fallbacks: {},
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy,
      bdate: () => TODAY,
      now: () => TODAY,
      status_badge: (status) => {
        const [tone, label] = BADGES[status] ?? ['slate', String(status ?? '')];
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
