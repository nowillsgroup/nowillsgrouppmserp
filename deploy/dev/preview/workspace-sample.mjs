/**
 * Sample data for the Reservation Workspace preview.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/reservations/workspace.blade.php against this fixed sample. The
 * shapes mirror app/Services/ReservationWorkspaceService::snapshot() and the
 * controller's attention/kpiTiles arrays, so the screen composes exactly as it does
 * in the application — only the property and the numbers are fixed.
 */
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

const BUSINESS_DATE = '2026-11-05';
const day = iso => new SampleDate(`${iso}T09:00:00Z`);

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const money = (v) => `${HOTEL.currency_symbol} ${Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const ROOM_TYPES = [
  { id: 3, name: 'Deluxe Sea View', code: 'DSV', size_sqm: 38, max_adults: 2, base_rate: 950, image: null },
  { id: 4, name: 'Standard Garden', code: 'STG', size_sqm: 28, max_adults: 2, base_rate: 620, image: null },
  { id: 7, name: 'Executive Suite', code: 'EXS', size_sqm: 62, max_adults: 3, base_rate: 1650, image: null },
  { id: 9, name: 'Family Room', code: 'FAM', size_sqm: 46, max_adults: 4, base_rate: 1180, image: null },
  { id: 11, name: 'Standard Single', code: 'SGL', size_sqm: 18, max_adults: 1, base_rate: 480, image: null },
  // created in Master Data, rate not entered yet — the board must not advertise GH₵ 0.00
  { id: 12, name: 'Standard Double', code: 'SDB', size_sqm: 24, max_adults: 2, base_rate: 0, image: null },
];

export const FLOORS = [{ id: 1, name: 'Ground' }, { id: 2, name: 'First' }, { id: 3, name: 'Second' }];

const AMENITIES = {
  DSV: ['Sea view', 'Air conditioning', 'Wi-Fi', 'Smart TV', 'Mini bar', 'Safe', 'Balcony', 'Coffee machine', 'Bathrobe', 'Room service', 'Desk', 'Hairdryer'],
  STG: ['Garden view', 'Air conditioning', 'Wi-Fi', 'Smart TV', 'Desk', 'Safe'],
  EXS: ['Sea view', 'Air conditioning', 'Wi-Fi', 'Smart TV', 'Mini bar', 'Safe', 'Lounge', 'Coffee machine', 'Bathrobe', 'Butler service', 'Dining table', 'Hairdryer', 'Bath tub'],
  FAM: ['Garden view', 'Air conditioning', 'Wi-Fi', 'Smart TV', 'Bunk beds', 'Safe', 'Kitchenette', 'Sofa bed', 'Desk', 'Hairdryer'],
  SGL: ['Garden view', 'Air conditioning', 'Wi-Fi', 'Desk', 'Safe'],
  SDB: ['Garden view', 'Air conditioning', 'Wi-Fi', 'Smart TV', 'Desk', 'Safe'],
};

const guest = (first, last, nationality = 'Ghana', vip = false) => ({ first_name: first, last_name: last, nationality, is_vip: vip, phone: '+233 24 000 0000', email: `${first.toLowerCase()}@example.com` });

const source = (name) => ({ id: name.length, name });

/**
 * One card per room: [roomNo, typeId, floorId, cardStatus, roomStatus, hk, stay?]
 * stay = [status, guest, source, rate, nights, arrival, departure, due, balance, ref]
 */
const CARD_SPECS = [
  ['101', 4, 1, 'occupied', 'occupied', 'dirty', ['checked_in', guest('Kwesi', 'Mensah'), 'Direct', 620, 3, '2026-11-03', '2026-11-06', 1860, 1860, 'RES-24117']],
  ['102', 4, 1, 'available', 'vacant', 'clean', null],
  ['103', 4, 1, 'reserved', 'vacant', 'inspected', ['reserved', guest('Adjoa', 'Boateng'), 'Booking.com', 620, 2, '2026-11-05', '2026-11-07', 1240, 1240, 'RES-24126']],
  ['104', 9, 1, 'available', 'vacant', 'dirty', null],
  ['105', 4, 1, 'maintenance', 'maintenance', 'dirty', null],
  ['201', 3, 2, 'occupied', 'occupied', 'clean', ['checked_in', guest('Sarah', 'Osei', 'United Kingdom', true), 'Expedia', 950, 4, '2026-11-02', '2026-11-06', 3800, 2100, 'RES-24101']],
  ['202', 3, 2, 'reserved', 'vacant', 'clean', ['reserved', guest('Yaw', 'Antwi'), 'Corporate · Ashanti Gold', 950, 1, '2026-11-05', '2026-11-06', 950, 950, 'RES-24129']],
  ['203', 12, 2, 'available', 'vacant', 'inspected', null],
  ['204', 3, 2, 'occupied', 'occupied', 'clean', ['checked_in', guest('Fatima', 'Alhassan', 'Nigeria'), 'Direct', 950, 2, '2026-11-04', '2026-11-06', 1900, 1900, 'RES-24122']],
  ['205', 9, 2, 'out_of_order', 'out_of_order', 'dirty', null],
  ['301', 7, 3, 'occupied', 'occupied', 'clean', ['checked_in', guest('Michael', 'Addo', 'Ghana', true), 'Travel agent · Sunseekers', 1650, 5, '2026-11-01', '2026-11-06', 8250, 4100, 'RES-24088']],
  ['302', 7, 3, 'occupied', 'occupied', 'dirty', ['checked_in', guest('Grace', 'Owusu'), 'Direct', 1650, 3, '2026-11-03', '2026-11-05', 4950, 0, 'RES-24109']],
  ['303', 9, 3, 'reserved', 'vacant', 'clean', ['reserved', guest('Daniel', 'Kusi'), 'Website', 1180, 3, '2026-11-05', '2026-11-08', 3540, 3540, 'RES-24131']],
  ['304', 11, 3, 'available', 'vacant', 'touch_up', null],
  ['305', 3, 3, 'reserved', 'vacant', 'dirty', ['reserved', guest('Linda', 'Eshun'), 'Booking.com', 950, 2, '2026-11-05', '2026-11-07', 1900, 1900, 'RES-24133']],
];

function roomTypeOf(id) {
  return ROOM_TYPES.find(type => type.id === id);
}

function floorOf(id) {
  return FLOORS.find(floor => floor.id === id);
}

function buildStay([status, person, sourceName, rate, nights, arrival, departure, due, balance, ref], room) {
  const reservation = {
    id: Number(String(ref).replace(/\D/g, '')),
    reservation_no: ref,
    guest_id: 1,
    business_source_id: sourceName.length,
    businessSource: source(sourceName),
    guest: person,
    group_name: null,
    ota_reference: /Booking|Expedia/.test(sourceName) ? `OTA-${ref.slice(-5)}` : null,
    company: null,
  };
  return {
    id: reservation.id * 10,
    status,
    guest_id: 1,
    reservation_id: reservation.id,
    room_id: room?.id ?? null,
    room_type_id: room?.room_type_id ?? null,
    room,
    guest: person,
    reservation,
    arrival_date: day(arrival),
    departure_date: day(departure),
    adults: 2,
    children: 0,
    rate,
    due,
    balance,
  };
}

// The screens in deploy/dev/previews/assets/ stand in for the photographs each room
// carries; the application reads the room's primary photo, then its type's.
const ROOM_PHOTOS = {
  '101': '/preview-assets/room-204-bed.jpg',
  '102': '/preview-assets/room-204-bath.jpg',
  '103': '/preview-assets/room-204-balcony.jpg',
  '104': '/preview-assets/room-204-bed.jpg',
  '105': '/preview-assets/room-204-bath.jpg',
  '201': '/preview-assets/room-204-balcony.jpg',
  '202': '/preview-assets/room-204-bed.jpg',
  '203': '/preview-assets/room-204-bath.jpg',
  '204': '/preview-assets/room-204-balcony.jpg',
  '205': '/preview-assets/room-204-bed.jpg',
  '301': '/preview-assets/room-204-bed.jpg',
  '302': '/preview-assets/room-204-bath.jpg',
  '303': '/preview-assets/room-204-balcony.jpg',
  '304': '/preview-assets/room-204-bed.jpg',
  '305': '/preview-assets/room-204-bath.jpg',
};

export function buildCards() {
  return Coll.of(CARD_SPECS.map(([roomNo, typeId, floorId, status, roomStatus, hk, staySpec]) => {
    const room = {
      id: Number(`${floorId}${roomNo.slice(-2)}`),
      room_no: roomNo,
      room_type_id: typeId,
      floor_id: floorId,
      status: roomStatus,
      hk_status: hk,
      is_smoking: false,
      is_active: roomStatus !== 'out_of_order',
      roomType: roomTypeOf(typeId),
      floor: floorOf(floorId),
    };
    const stay = staySpec ? buildStay(staySpec, room) : null;
    return {
      room,
      status,
      stay,
      balance: stay ? stay.balance : null,
      due: stay ? stay.due : null,
      rate: stay?.rate ?? roomTypeOf(typeId).base_rate,
      amenities: AMENITIES[roomTypeOf(typeId).code] ?? [],
      // a stand-in for the room's own uploaded photo (the app reads the primary one)
      photo: ROOM_PHOTOS[roomNo] ?? null,
      photo_full: ROOM_PHOTOS[roomNo] ?? null,
      arriving_on_date: Boolean(stay && stay.status === 'reserved' && stay.arrival_date.toDateString() === BUSINESS_DATE),
    };
  }));
}

const cards = buildCards();
const staysFor = (statuses) => cards.map(card => card.stay).filter(stay => stay && statuses.includes(stay.status));

export function workspaceContext() {
  const arrivals = Coll.of([
    ...staysFor(['reserved']).items,
    // one arrival with no room yet: the desk still has to place it
    buildStay(['reserved', guest('Nana', 'Ampofo'), 'Direct', 950, 2, BUSINESS_DATE, '2026-11-07', 1900, 1900, 'RES-24140'], null),
  ]);
  const departures = Coll.of(staysFor(['checked_in']).items.slice(0, 3));
  const vip = Coll.of(staysFor(['checked_in', 'reserved']).items.filter(stay => stay.guest?.is_vip));
  const departureFigures = {};
  departures.items.forEach((stay, index) => {
    departureFigures[stay.id] = { amount: stay.due ?? 0, blocked: index === 0 ? ['Outstanding balance GH₵ 2,100.00'] : [] };
  });

  const trend = {
    occupancy: [68, 71, 74, 72, 76, 78, 74, 71, 73, 77, 79, 76, 74, 73],
    revenue: [28400, 29150, 30200, 29800, 31600, 32400, 30150, 28900, 29750, 31200, 32800, 31450, 30600, 30120],
    available: [12, 11, 9, 10, 8, 7, 9, 11, 10, 8, 7, 8, 9, 10],
    occupied: [26, 27, 28, 27, 29, 30, 28, 27, 28, 29, 31, 30, 28, 27],
    reserved: [4, 4, 5, 5, 5, 5, 5, 4, 4, 5, 4, 4, 5, 5],
    arrivals: [8, 7, 9, 6, 10, 12, 7, 5, 6, 9, 11, 8, 7, 9],
    departures: [6, 7, 5, 8, 7, 6, 9, 6, 5, 7, 8, 6, 7, 6],
    inhouse: [82, 85, 88, 84, 92, 96, 90, 86, 88, 94, 98, 95, 91, 88],
    vips: [3, 3, 4, 3, 5, 5, 4, 3, 4, 5, 4, 4, 5, 5],
  };
  const deltas = { occupancy: 2.1, revenue: -1.4, available: 12.5, occupied: -3.2, reserved: 0, arrivals: 12.5, departures: -14.3, inhouse: -3.3, vips: 25 };

  const kpis = { occupancy: 73, revenue: 30120, available: 10, occupied: 27, reserved: 5, arrivals: 9, departures: 6, inhouse: 88, vips: 5 };

  const statusSummary = {
    Available: [10, '#10b981'],
    Occupied: [27, '#3b82f6'],
    Reserved: [5, '#f59e0b'],
    'House Use': [1, '#a855f7'],
    'Out of Order': [1, '#ef4444'],
    'Out of Service': [1, '#b91c1c'],
    Leased: [0, '#64748b'],
    Maintenance: [1, '#f97316'],
    'Group Hold': [1, '#0ea5e9'],
    'Other Block': [0, '#64748b'],
  };

  const kpiTiles = [
    ['occupancy', 'Occupancy', '73%', '+2.1%', 'green'],
    ['revenue', 'Room-rate total', money(30120), '-1.4%', 'red'],
    ['available', 'Available', '10', '+12.5%', 'neutral'],
    ['occupied', 'Occupied', '27', '-3.2%', 'neutral'],
    ['reserved', 'Reserved', '5', '0%', 'neutral'],
    ['arrivals', 'Arrivals', '9', '+12.5%', 'neutral'],
    ['departures', 'Departures', '6', '-14.3%', 'neutral'],
    ['inhouse', 'In-house guests', '88', '-3.3%', 'neutral'],
    ['vips', 'VIP guests', '5', '+25%', 'neutral'],
  ].map(([key, label, value, delta, tone]) => ({
    key,
    label,
    value,
    delta,
    tone,
    series: trend[key],
    link: '#',
    permission: key === 'revenue' ? 'revenue.view' : 'frontdesk.view',
    opens: key !== 'revenue',
  }));

  const attention = [
    { key: 'unassigned', label: 'Arrivals without a room', count: 1, detail: 'Assign a room before the guest reaches the desk.', url: '#frontdesk-arrivals', tone: 'amber' },
    { key: 'checkin-blocked', label: 'Check-ins that will be refused', count: 1, detail: 'The room is not vacant, inactive or carries an inventory block.', url: '#frontdesk-arrivals', tone: 'red' },
    { key: 'checkout-held', label: 'Checkouts on hold', count: 1, detail: 'A balance, unposted charge or open folio is holding the checkout.', url: '#frontdesk-departures', tone: 'red' },
    { key: 'rooms-not-ready', label: 'Arrival rooms not ready', count: 2, detail: 'Still dirty or waiting for a touch-up.', url: '#housekeeping', tone: 'amber' },
    { key: 'waitlist', label: 'Guests on the waitlist', count: 3, detail: 'Waiting for a room that matches their dates.', url: '#waitlist', tone: 'slate' },
  ];

  const waitlist = Coll.of([
    { id: 1, guest: guest('Ama', 'Serwaa'), contact_name: null, arrival_date: day('2026-11-06'), departure_date: day('2026-11-08'), roomType: roomTypeOf(3) },
    { id: 2, guest: guest('Peter', 'Nkrumah'), contact_name: null, arrival_date: day('2026-11-07'), departure_date: day('2026-11-09'), roomType: roomTypeOf(7) },
  ]);

  const assignable = {};
  cards.items.filter(card => card.status === 'available').slice(0, 4).forEach((card, index) => {
    assignable[arrivals.items[index]?.id ?? 0] = Coll.of([card.room]);
  });

  const monthEvents = { arrivals: {}, departures: {} };
  ['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-11', '2026-11-14', '2026-11-19', '2026-11-24'].forEach(date => { monthEvents.arrivals[date] = 8; });
  ['2026-11-05', '2026-11-06', '2026-11-09', '2026-11-12', '2026-11-17', '2026-11-22', '2026-11-28'].forEach(date => { monthEvents.departures[date] = 6; });

  const helperSet = {
    money,
    number_format: (v, places = 0) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
    route: (name, params) => {
      if (name === 'reservations.workspace') {
        const date = params && params.date ? params.date : BUSINESS_DATE;
        return `/reservations/workspace?date=${date}`;
      }
      return '#';
    },
    asset: (path) => `/${String(path ?? '').replace(/^\/+/, '')}`,
    can_do: () => true,
    bdate: () => day(BUSINESS_DATE),
    now: () => new SampleDate('2026-11-05T09:14:22Z'),
    request: () => ({ except: () => ({}), only: () => ({}), query: (key, fallback) => fallback, has: () => false }),
    collect: (value) => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
    count: (value) => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
    empty: phpEmpty,
    in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
    array_flip: (obj) => Object.fromEntries(Object.entries(obj ?? {}).map(([key, value]) => [value, key])),
    array_merge: (...parts) => Object.assign({}, ...parts.map(part => part ?? {})),
    array_map: (fn, list) => (list ?? []).map(item => (typeof fn === 'function' ? fn(item) : (helperSet[fn] ? helperSet[fn](item) : item))),
    array_filter: (list, fn) => (list ?? []).filter(item => (typeof fn === 'function' ? fn(item) : Boolean(item))),
    range: (start, end) => Array.from({ length: Math.max(0, end - start + 1) }, (_, i) => start + i),
    array_unique: (list) => [...new Set(list ?? [])],
    is_numeric: (value) => value !== null && value !== '' && !Number.isNaN(Number(value)),
    array_slice: (list, offset, length) => (list instanceof Coll ? list.items : list ?? []).slice(offset, length === undefined ? undefined : offset + length),
    isset: (...values) => values.every(value => value !== undefined && value !== null),
    implode: (glue, list) => (list instanceof Coll ? list.items : list ?? []).join(glue),
    is_string: (value) => typeof value === 'string',
    is_scalar: (value) => ['string', 'number', 'boolean'].includes(typeof value),
    strval: (value) => String(value ?? ''),
    trim: (value) => String(value ?? '').trim(),
    rtrim: (value, chars = ' \t\n\r\0\x0B') => String(value ?? '').replace(new RegExp(`[${chars.replace(/[\\\]]/g, '\\$&')}]+$`), ''),
    strtoupper: (value) => String(value ?? '').toUpperCase(),
    str_replace: (search, replace, subject) => String(subject ?? '').split(search).join(replace),
    str_contains: (haystack, needle) => String(haystack ?? '').includes(String(needle ?? '')),
    ucfirst: (value) => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
    mb_substr: (value, start, length) => String(value ?? '').substr(start, length ?? undefined),
    abs: (value) => Math.abs(Number(value ?? 0)),
    round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
    max: (...values) => Math.max(...values.flat()),
    min: (...values) => Math.min(...values.flat()),
    __static: (cls, method, ...args) => {
      const name = String(cls).split('\\').pop();
      if (name === 'Str' && method === 'plural') {
        const [word, count] = args;
        const text = String(word ?? '');
        if (Number(count) === 1) return text;
        const irregular = { person: 'people', child: 'children', stay: 'stays' };
        if (irregular[text]) return irregular[text];
        if (/[^aeiou]y$/.test(text)) return `${text.slice(0, -1)}ies`;
        if (/(s|x|z|ch|sh)$/.test(text)) return `${text}es`;
        return `${text}s`;
      }
      if (name === 'Carbon' && method === 'parse') {
        const value = args[0];
        return value instanceof SampleDate ? value : new SampleDate(`${String(value)}T09:00:00Z`);
      }
      if (name === 'WS' || name === 'ReservationWorkspaceService') {
        if (method === 'amenityIcon') return 'wifi';
        if (method === 'sortAssignable') {
          const priority = { inspected: 0, clean: 1 };
          const list = (args[0] instanceof Coll ? args[0].items : args[0] ?? []).slice();
          return Coll.of(list.sort((a, b) => ((priority[a.hk_status] ?? 2) - (priority[b.hk_status] ?? 2))
            || String(a.room_no).localeCompare(String(b.room_no), undefined, { numeric: true })));
        }
        if (method === 'spark') return '<svg class="ws-spark" viewBox="0 0 100 22" aria-hidden="true"><path d="M2 16 L18 12 L30 14 L44 8 L58 10 L70 5 L84 9 L98 4" fill="none" stroke="#16a34a" stroke-width="1.6"/></svg>';
      }
      return args[0] ?? '';
    },
    __const: () => [],
    Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
    parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
  };

  return {
    helpers: helperSet,
    fallbacks: {},
    scope: {
      date: day(BUSINESS_DATE),
      businessDate: day(BUSINESS_DATE),
      dateMin: day('2026-09-04'),
      dateMax: day('2027-01-06'),
      isHistory: false,
      isFuture: false,
      inventoryBlocks: {},
      trend,
      deltas,
      kpis,
      kpiTiles,
      attention,
      cards,
      arrivals,
      departures,
      departureFigures,
      vip,
      monthEvents,
      statusSummary,
      hk: { Dirty: 5, Clean: 9, 'Touch-up': 1 },
      waitlist,
      waitlistCount: 3,
      assignableByStay: assignable,
      roomTypes: Coll.of(ROOM_TYPES),
      floors: Coll.of(FLOORS),
      sourceOptions: Coll.of(['Booking.com', 'Corporate · Ashanti Gold', 'Direct', 'Expedia', 'Travel agent · Sunseekers', 'Website']),
      guestDisplayName: (stay, fallback = 'Guest details unavailable') => {
        const person = stay?.guest ?? stay?.reservation?.guest;
        const name = `${person?.first_name ?? ''} ${person?.last_name ?? ''}`.trim();
        return name || fallback;
      },
      guestInitial: (stay) => {
        const person = stay?.guest ?? stay?.reservation?.guest;
        return (person?.first_name || person?.last_name || 'G').slice(0, 1);
      },
      housekeepingLabel: (status) => ({ dirty: 'Dirty', clean: 'Clean', inspected: 'Inspected', touch_up: 'Touch-up' }[status] ?? 'Unknown'),
      stayDateFormat: () => 'd M',
      checkInBlockReason: () => 'Assigned room status: occupied.',
    },
  };
}
