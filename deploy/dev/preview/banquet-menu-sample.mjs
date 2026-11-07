/**
 * Sample data for the Banquet kitchen menu sheet preview.
 *
 * The sheet is resources/views/banquet/menu.blade.php, fed by BanquetController::menu().
 * That method hands the view three things — the event ($e), its functions ordered by
 * date and time ($fns), and the optional single-function focus — so the sample mirrors
 * exactly that and nothing more.
 *
 * The shapes follow the models: a function exposes billablePax(), timeRange() and
 * guaranteeCutoff(), its hall exposes a name, its package exposes itemList() — the
 * BanquetPackage::items text split one dish per line, which is how the real column is
 * kept.
 *
 * The event is the same tentative wedding hold as the booking-form preview (BQT-26041),
 * so the two screens describe one booking: a ceremony with no F&B at all, and a
 * reception on the Gold Buffet. The client's requirements carry the only dietary
 * information the system holds — two coeliac guests, one wheelchair user — which is the
 * point the dietary block on the sheet has to make honestly.
 */
import { Coll, SampleDate } from './blade-render.mjs';
import { HOTEL, HALLS, PACKAGES } from './banquet-sample.mjs';

const day = iso => new SampleDate(`${iso}T09:00:00Z`);
const hall = id => HALLS.find(h => h.id === id);

/** BanquetPackage::itemList() over the package's one-dish-per-line items column. */
const pkg = id => {
  const row = PACKAGES.find(p => p.id === id);
  return row ? { id: row.id, name: row.name, type: row.type, itemList: () => [...(row.items ?? [])] } : null;
};

const timeRange = f => `${f.start_time}–${f.end_time}`;

/** BanquetFunction, with the three methods the sheet calls. */
const fn = (spec) => ({
  ...spec,
  // banquet_functions.date is cast to a date by the model; the sample is a date too.
  date: day(spec.date),
  hall: { id: spec.banquet_hall_id, name: hall(spec.banquet_hall_id)?.name ?? 'Venue not set' },
  package: spec.banquet_package_id ? pkg(spec.banquet_package_id) : null,
  timeRange: () => timeRange(spec),
  // max(guarantee or expected, actual) — BanquetFunction::billablePax()
  billablePax: () => Math.max(Number(spec.guaranteed_pax ?? spec.expected_pax ?? 0), Number(spec.actual_pax ?? 0)),
  // last day the guarantee can still be lowered — banquet_guarantee_days, 3
  guaranteeCutoff: () => day(spec.date).addDay(-3),
  items: Coll.of(spec.items ?? []),
});

export const FUNCTIONS = [
  fn({
    id: 4101, name: 'Traditional ceremony', banquet_hall_id: 3, date: '2026-12-12',
    start_time: '14:00', end_time: '17:00', setup_style: 'theatre',
    expected_pax: 150, guaranteed_pax: 150, actual_pax: null,
    banquet_package_id: null, rate_per_pax: 0,
    notes: 'Elders seated at the front; two chairs for the officiants. The family provides the drinks — no corkage '
      + 'to be charged. No food service: water and kola on the head table only. Venue turns around to the '
      + 'reception in 60 minutes from 17:00.',
    items: [],
  }),
  fn({
    id: 4102, name: 'Reception & dinner', banquet_hall_id: 1, date: '2026-12-12',
    start_time: '19:00', end_time: '23:30', setup_style: 'banquet',
    expected_pax: 180, guaranteed_pax: 180, actual_pax: null,
    banquet_package_id: 11, rate_per_pax: 320,
    notes: 'Head table for 12 on the dais. Buffet open at 19:45, cleared by 21:15 — the band plays through the '
      + 'dessert course, finishing 23:00. Two coeliac guests and one wheelchair user are on the reception; keep a '
      + 'clear route to the head table. The family brings the cake — plate it, do not portion it on the buffet.',
    items: [],
  }),
];

export const EVENT = {
  exists: true, id: 418, event_no: 'BQT-26041', status: 'tentative',
  title: 'Ama Serwaa & Kofi Boateng — wedding', event_type: 'wedding',
  event_date: day('2026-12-12'), end_date: null,
  option_date: day('2026-11-21'),
  contact_name: 'Ama Serwaa Mensah', contact_phone: '+233 24 118 6620',
  contact_email: 'ama.serwaa@example.com',
  requirements: 'Traditional ceremony from 14:00 with the family elders seated at the front. Reception is a seated '
    + 'dinner for 180 with a live band; the family will provide the cake. Two guests are coeliac and one is '
    + 'wheelchair-dependent — please keep a clear route to the head table.',
  internal_notes: 'Option expires 21 Nov. Client is comparing us with the Ridge; the band is the deciding factor.',
};

export function menuContext({ focus = 0 } = {}) {
  const helperSet = {
    number_format: (v, places = 0) => Number(v ?? 0).toLocaleString('en-US', {
      minimumFractionDigits: places, maximumFractionDigits: places,
    }),
    ucfirst: (value) => {
      const text = String(value ?? '');
      return text.charAt(0).toUpperCase() + text.slice(1);
    },
    strtoupper: (value) => String(value ?? '').toUpperCase(),
    strtolower: (value) => String(value ?? '').toLowerCase(),
    in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
    ceil: v => Math.ceil(Number(v ?? 0)),
    now: () => new SampleDate('2026-11-05T09:14:22Z'),
    bdate: () => day('2026-11-05'),
    route: (name, params) => {
      if (name === 'banquet.show') return '/banquet/418';
      if (name === 'banquet.menu') return '/banquet/418/menu';
      return '#';
    },
    setting: (key, fallback = null) => ({ banquet_guarantee_days: '3', banquet_turnaround_minutes: '60' }[key] ?? fallback),
    __static: (cls, method, ...args) => {
      const owner = String(cls).split('\\').pop();
      if (owner === 'Carbon' && method === 'parse') return args[0] instanceof SampleDate ? args[0] : day(String(args[0]));
      return args[0] ?? '';
    },
    __const: (cls, name) => {
      const owner = String(cls).split('\\').pop();
      if (owner === 'BanquetEvent' && name === 'STATUSES') {
        return { enquiry: 'Enquiry', tentative: 'Tentative', definite: 'Definite', completed: 'Completed', cancelled: 'Cancelled', lost: 'Lost' };
      }
      if (owner === 'BanquetEvent' && name === 'TYPES') {
        return { wedding: 'Wedding', reception: 'Reception', conference: 'Conference', gala: 'Gala dinner', other: 'Other' };
      }
      if (owner === 'BanquetFunction' && name === 'SETUPS') {
        return { theatre: 'Theatre', banquet: 'Banquet rounds', classroom: 'Classroom', ushape: 'U-shape', cocktail: 'Cocktail', boardroom: 'Boardroom' };
      }
      return [];
    },
    Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
    parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
  };

  const fns = Coll.of(FUNCTIONS.filter(f => !focus || f.id === focus));

  return {
    helpers: helperSet,
    fallbacks: {},
    scope: {
      e: EVENT,
      fns,
      // the cover summary BanquetController::menu() computes before the view is rendered
      expectedTotal: fns.items.reduce((sum, f) => sum + Number(f.guaranteed_pax ?? f.expected_pax ?? 0), 0),
      cookTotal: fns.items.reduce((sum, f) => sum + (f.billablePax() > 0 ? Math.ceil(f.billablePax() * 1.05) : 0), 0),
      focus,
      hotel: { ...HOTEL, address: 'Beach Road, Essipong · Takoradi · Ghana' },
      title: `Kitchen menu · ${EVENT.event_no}`,
    },
  };
}
