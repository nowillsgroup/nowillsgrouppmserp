/**
 * Fixed sample for the Guest Registration Card (frontdesk/grc).
 *
 * The card is a printed document, so the sample has to look like a real one: a guest
 * with a passport on file, a company account, a rate plan and a signature captured at
 * the desk. Everything is handed to resources/views/frontdesk/grc.blade.php exactly as
 * FrontDeskController::grc() hands it over — including `$signature` as the data URI the
 * controller now builds and `$documentTypes` as the labels the clerk reads.
 *
 * grcContext({ dayUse: true }) renders the same card for a day use, which is the one
 * branch of the document that is not exercise-able from the overnight sample.
 */
import { Coll, SampleDate } from './blade-render.mjs';

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort',
  code: 'GROVE',
  address: 'Beach Road, Essipong',
  city: 'Takoradi',
  state: 'Western Region',
  pincode: 'WS-023',
  phone: '+233 31 202 4470',
  email: 'frontdesk@thegroveessipong.com',
  website: 'thegroveessipong.com',
  tax_number: 'C0012345678',
  logo_path: null,
  checkout_time: '12:00:00',
  currency_symbol: 'GH₵',
};

/** A hand-drawn signature, as the controller hands it over: a PNG data URI. */
const SIGNATURE_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 220 64" width="220" height="64">
<path d="M6 46c10-16 16-30 20-28s-6 26-2 28 12-20 17-18-4 16 2 18 14-14 19-13-3 12 3 13 15-9 20-9 8 4 14 3"
 fill="none" stroke="#12305a" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
<path d="M120 44c14-11 26-19 34-17s-6 15 2 16 18-9 26-8" fill="none" stroke="#12305a" stroke-width="2.2"
 stroke-linecap="round" stroke-linejoin="round"/>
<path d="M150 50c10-4 22-6 34-6" fill="none" stroke="#12305a" stroke-width="1.6" stroke-linecap="round"/>
</svg>`;

export const SIGNATURE_URI = `data:image/svg+xml;base64,${Buffer.from(SIGNATURE_SVG, 'utf8').toString('base64')}`;
export const SIGNATURE_PNG_URI = `data:image/png;base64,${Buffer.from(SIGNATURE_SVG, 'utf8').toString('base64')}`;

const day = (iso, hour = '09:00') => new SampleDate(`${iso}T${hour}:00Z`);
const dmy = (value) => (value instanceof SampleDate ? value.format('d M Y') : String(value ?? ''));
const money = (value) => `GH₵ ${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const ID_TYPES = {
  passport: 'Passport', ghana_card: 'Ghana Card', driving_licence: 'Driving licence',
  voter_id: 'Voter ID', national_id: 'National ID', other: 'Other document',
};

const GUEST = {
  id: 418,
  guest_no: 'G-00418',
  full_name: 'Kwabena Mensah-Aidoo',
  first_name: 'Kwabena',
  last_name: 'Mensah-Aidoo',
  gender: 'Male',
  dob: day('1987-03-14'),
  nationality: 'Ghanaian',
  phone: '+233 24 617 8820',
  email: 'k.mensah@acmemining.com.gh',
  tax_number: 'P0021884567',
  address: 'House 14, Airport Residential Area',
  city: 'Accra',
  state: 'Greater Accra',
  country: 'Ghana',
  pincode: 'GA-045',
  id_type: 'passport',
  id_number: 'G1234567',
  passport_no: 'G1234567',
  visa_expiry: null,
  privacy_status: null,
  is_blacklisted: false,
  documents: new Coll([
    { id: 92, type: 'ghana_card', original_name: 'ghana-card-front.jpg', mime: 'image/jpeg', size: 1843200, created_at: day('2026-11-06', '14:09') },
    { id: 91, type: 'passport', original_name: 'passport-page.jpg', mime: 'image/jpeg', size: 2211840, created_at: day('2026-11-06', '14:08') },
  ]),
};

const COMPANY = { id: 41, name: 'Acme Mining Ltd', tax_number: 'C0009876543', direct_billing: true, credit_limit: 25000, credit_days: 30 };
const SOURCE = { id: 4, name: 'Corporate' };

function stayFor({ dayUse, signed }) {
  const arrival = day('2026-11-06', '14:05');
  const departure = dayUse ? day('2026-11-06', '14:05') : day('2026-11-09');

  return {
    id: 5079,
    status: 'checked_in',
    hotel_id: 1,
    arrival_date: arrival,
    departure_date: departure,
    checked_in_at: day('2026-11-06', '14:12'),
    adults: 2,
    children: 0,
    rate: dayUse ? 380 : 620,
    extra_charge: 0,
    is_day_use: dayUse,
    day_use_until: dayUse ? '18:00' : null,
    grc_signed_at: signed ? day('2026-11-06', '14:14') : null,
    grc_signature_path: signed ? 'grc/1/5079-sample.png' : null,
    room_id: 102,
    room: { id: 102, room_no: '102', status: 'occupied', hk_status: 'clean' },
    room_type_id: 4,
    roomType: { id: 4, code: 'STG', name: 'Standard Garden', base_occupancy: 2, base_rate: 620 },
    rate_plan_id: 22,
    ratePlan: { id: 22, name: 'Corporate — Acme 2026', meal_plan: 'CP', rate: 620 },
    reservation: {
      id: 3391,
      reservation_no: 'RES-24391',
      arrival_date: arrival,
      departure_date: departure,
      adults: 2,
      children: 0,
      purpose: 'Contract review — quarterly site visit',
      special_request: 'High floor if possible; extra towels.',
      status: 'checked_in',
      company: COMPANY,
      source: SOURCE,
      guest: GUEST,
    },
  };
}

/**
 * The view's scope and helpers, as the controller and the framework provide them.
 * `signed: false` renders a card waiting for the guest's pen.
 */
export function grcContext({ dayUse = false, signed = true } = {}) {
  const stay = stayFor({ dayUse, signed });
  const guest = stay.reservation.guest;

  const helpers = {
    collect: (value) => new Coll(Array.isArray(value) ? value : Object.values(value ?? {})),
    money,
    dmy,
    number_format: (value) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    setting: (key, fallback = null) => (key === 'grc_terms'
      ? 'The room and its contents are let to the registered guest for the period shown above. The guest is liable for the room rate, service charge, VAT and any incidental charges posted to the folio. The hotel reserves the right to re-let the room after the stated check-out time unless a late departure has been agreed.'
      : fallback),
    config: (key, fallback = null) => {
      const match = /^pms\.id_types\.(.+)$/.exec(String(key));

      return match ? (ID_TYPES[match[1]] ?? fallback) : fallback;
    },
    now: () => day('2026-11-06', '14:20'),
    bdate: () => day('2026-11-06', '09:00'),
    substr: (value, start, length) => String(value ?? '').substr(start, length),
    strtoupper: (value) => String(value ?? '').toUpperCase(),
    str_replace: (search, replace, subject) => {
      const list = Array.isArray(search) ? search : [search];
      const into = Array.isArray(replace) ? replace : [replace];
      return list.reduce((out, needle, index) => out.split(needle).join(into[index] ?? ''), String(subject ?? ''));
    },
    implode: (glue, items) => (Array.isArray(items) ? items.join(glue) : String(items ?? '')),
    count: (value) => (Array.isArray(value) ? value.length : (value instanceof Coll ? value.count() : Object.keys(value ?? {}).length)),
    e: (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
    nl2br: (value) => String(value ?? '').replace(/\n/g, '<br>'),
    max: (...values) => Math.max(...values.map(Number)),
    round: (value) => Math.round(Number(value ?? 0)),
    optional: (value) => value,
  };

  const scope = {
    stay,
    g: guest,
    signature: signed ? SIGNATURE_PNG_URI : null,
    documentTypes: ['Passport', 'Ghana Card'],
    currentHotel: HOTEL,
  };

  return {
    scope,
    helpers,
    fallbacks: {},
    hotel: HOTEL,
    stay,
    guest,
    signed,
    dayUse,
  };
}
