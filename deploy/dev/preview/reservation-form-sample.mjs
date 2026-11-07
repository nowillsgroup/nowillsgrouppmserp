/**
 * Sample data for the New Reservation preview.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/reservations/form.blade.php against this fixed sample: a brand-new
 * booking (no reservation yet), the room types and rate plans the desk sells, the
 * business sources, the company accounts and the payment modes. The shapes mirror
 * ReservationController::create() so the form composes exactly as it does in the
 * application. Only the property and the numbers are fixed.
 */
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-06';
const day = iso => new SampleDate(`${iso}T09:00:00Z`);

const TYPES = {
  STG: { id: 4, code: 'STG', name: 'Standard Garden', base_rate: 620, base_occupancy: 2, extra_adult_rate: 90, max_adults: 2, size_sqm: 28 },
  DSV: { id: 3, code: 'DSV', name: 'Deluxe Sea View', base_rate: 950, base_occupancy: 2, extra_adult_rate: 120, max_adults: 2, size_sqm: 38 },
  FAM: { id: 9, code: 'FAM', name: 'Family Room', base_rate: 1180, base_occupancy: 3, extra_adult_rate: 150, max_adults: 4, size_sqm: 46 },
  EXS: { id: 7, code: 'EXS', name: 'Executive Suite', base_rate: 1650, base_occupancy: 3, extra_adult_rate: 180, max_adults: 3, size_sqm: 62 },
  SGL: { id: 11, code: 'SGL', name: 'Standard Single', base_rate: 480, base_occupancy: 1, extra_adult_rate: 80, max_adults: 1, size_sqm: 18 },
};

const RATE_PLANS = [
  { id: 21, name: 'Rack', rate: 620, room_type_id: TYPES.STG.id },
  { id: 22, name: 'Corporate — Acme Ltd', rate: 540, room_type_id: TYPES.STG.id },
  { id: 23, name: 'Rack', rate: 950, room_type_id: TYPES.DSV.id },
  { id: 24, name: 'Weekend package', rate: 1080, room_type_id: TYPES.DSV.id },
  { id: 25, name: 'Rack', rate: 1180, room_type_id: TYPES.FAM.id },
  { id: 26, name: 'Rack', rate: 1650, room_type_id: TYPES.EXS.id },
  { id: 27, name: 'Rack', rate: 480, room_type_id: TYPES.SGL.id },
];

const SOURCES = { 1: 'Walk-in', 2: 'Phone', 3: 'Booking.com', 4: 'Corporate' };
const COMPANIES = { 41: 'Acme Mining Ltd', 42: 'Ghana Ports Authority', 43: 'Mensah Wedding Party' };
const PAYMENT_MODES = { 1: 'Cash', 2: 'Mobile Money — MTN', 3: 'Mobile Money — Telecel', 4: 'Visa / Mastercard', 5: 'Bank transfer' };

const HELPERS = {
  can_do: () => true,
  session: () => ({ hasOldInput: () => false, get: () => null, has: () => false, flash: () => null }),
  old: (key, fallback = null) => fallback,
  route: () => '#',
  url: () => ({ previous: () => '#' }),
  asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
  money: value => `GH₵ ${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
  number_format: value => Number(value ?? 0).toLocaleString('en-US'),
  bdate: () => day(BUSINESS_DATE),
  now: () => new SampleDate('2026-11-06T09:20:00Z'),
  collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
  count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
  empty: phpEmpty,
  in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
  json: value => JSON.stringify(value ?? null),
  json_encode: value => JSON.stringify(value ?? null),
  str_replace: (search, replace, subject) => String(subject ?? '').split(search).join(replace),
  strtolower: value => String(value ?? '').toLowerCase(),
  strtoupper: value => String(value ?? '').toUpperCase(),
  ucfirst: value => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
  ucwords: value => String(value ?? '').replace(/\b\w/g, letter => letter.toUpperCase()),
  substr: (value, start, length) => String(value ?? '').substr(start, length),
  trim: value => String(value ?? '').trim(),
  number: value => Number(value ?? 0),
  intval: value => Number.parseInt(value, 10) || 0,
  abs: value => Math.abs(Number(value ?? 0)),
  round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
  max: (...values) => Math.max(...values.flat()),
  min: (...values) => Math.min(...values.flat()),
  implode: (glue, list) => (list instanceof Coll ? list.items : list ?? []).join(glue),
  isset: (...values) => values.every(value => value !== undefined && value !== null),
  optional: value => ({
    toDateString: () => (value?.toDateString ? value.toDateString() : ''),
    format: () => '',
    __value: value,
  }),
  config: (key, fallback = null) => ({
    'pms.id_types': ['Ghana Card', 'Passport', 'Driver licence', 'Voter ID', 'Other'],
  })[key] ?? fallback,
  __static: (cls, method, ...args) => {
    const name = String(cls).split('\\').pop();
    if (name === 'Str' && method === 'plural') {
      const [word, count] = args;
      const text = String(word ?? '');
      if (Number(count) === 1) return text;
      if (/(s|x|z|ch|sh)$/.test(text)) return `${text}es`;
      return `${text}s`;
    }
    if (name === 'Str' && method === 'title') return String(args[0] ?? '');
    return args[0] ?? '';
  },
  __const: () => [],
  // Blade escapes with e(); the renderer already escapes interpolations it emits.
  e: value => String(value ?? ''),
  Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
  parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
};

export function reservationFormContext() {
  // A booking being taken now: no record yet, so every field opens at its default.
  const reservation = {
    exists: false,
    id: null,
    reservation_no: null,
    arrival_date: day('2026-11-12'),
    departure_date: day('2026-11-15'),
    eta: '14:00',
    booking_type: 'individual',
    group_name: '',
    status: 'confirmed',
    business_source_id: 2,
    company_id: null,
    billing_instruction: 'guest',
    ota_reference: '',
    purpose: 'Leisure',
    discount_pct: 0,
    special_request: '',
    rateContract: null,
  };

  // Laravel's $errors bag: the preview shows a clean form, so nothing is invalid.
  const errors = {
    any: () => false, has: () => false, all: () => [],
    first: () => '', get: () => [],
  };

  const scope = {
    errors,
    reservation,
    // `@php $editing = $reservation->exists; @endphp` sits above the section, so the
    // renderer never runs it; the value it would produce is supplied here.
    editing: false,
    guest: {},
    allowCustomGuestTitle: true,
    roomTypes: Coll.of(Object.values(TYPES)),
    ratePlans: Coll.of(RATE_PLANS),
    lines: [],
    sources: SOURCES,
    companies: COMPANIES,
    paymentModes: PAYMENT_MODES,
    currentHotel: HOTEL,
  };

  return { helpers: HELPERS, scope, fallbacks: {} };
}
