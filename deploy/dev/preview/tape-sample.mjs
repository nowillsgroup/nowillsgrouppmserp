/**
 * Sample data for the Tape Chart preview.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/frontdesk/tapechart.blade.php against this fixed sample. The shapes
 * mirror FrontDeskController::tapeChart() — rooms grouped by room type, stays grouped
 * by room id, the unassigned list and the summary counters — so the chart composes
 * exactly as it does in the application. Only the property and the numbers are fixed.
 */
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-05';
const day = iso => new SampleDate(`${iso}T09:00:00Z`);

/* ── room types and rooms ─────────────────────────────────────────────────── */
const TYPES = {
  STG: { id: 4, code: 'STG', name: 'Standard Garden', size_sqm: 28, max_adults: 2, base_rate: 620 },
  DSV: { id: 3, code: 'DSV', name: 'Deluxe Sea View', size_sqm: 38, max_adults: 2, base_rate: 950 },
  EXS: { id: 7, code: 'EXS', name: 'Executive Suite', size_sqm: 62, max_adults: 3, base_rate: 1650 },
  FAM: { id: 9, code: 'FAM', name: 'Family Room', size_sqm: 46, max_adults: 4, base_rate: 1180 },
  SGL: { id: 11, code: 'SGL', name: 'Standard Single', size_sqm: 18, max_adults: 1, base_rate: 480 },
};

// [room_no, type, room status, housekeeping, id]
const ROOMS = [
  ['101', 'STG', 'occupied', 'dirty', 101],
  ['102', 'STG', 'vacant', 'clean', 102],
  ['103', 'STG', 'vacant', 'inspected', 103],
  ['105', 'STG', 'vacant', 'dirty', 105],
  ['104', 'FAM', 'vacant', 'clean', 104],
  ['201', 'DSV', 'occupied', 'clean', 201],
  ['202', 'DSV', 'vacant', 'clean', 202],
  ['203', 'DSV', 'vacant', 'touch_up', 203],
  ['204', 'DSV', 'occupied', 'dirty', 204],
  ['205', 'FAM', 'out_of_order', 'dirty', 205],
  ['301', 'EXS', 'occupied', 'clean', 301],
  ['302', 'EXS', 'occupied', 'clean', 302],
  ['303', 'FAM', 'vacant', 'inspected', 303],
  ['304', 'SGL', 'vacant', 'clean', 304],
  ['305', 'DSV', 'vacant', 'dirty', 305],
];

const room = ([roomNo, typeCode, status, hk, id]) => ({
  id,
  room_no: roomNo,
  status,
  hk_status: hk,
  room_type_id: TYPES[typeCode].id,
  roomType: TYPES[typeCode],
});

const reservations = [
  { no: 'RES-24101', guest: 'Sarah Osei', status: 'confirmed' },
  { no: 'RES-24109', guest: 'Grace Owusu', status: 'confirmed' },
  { no: 'RES-24117', guest: 'Kwesi Mensah', status: 'confirmed' },
  { no: 'RES-24122', guest: 'Fatima Alhassan', status: 'confirmed' },
  { no: 'RES-24126', guest: 'Adjoa Boateng', status: 'confirmed' },
  { no: 'RES-24129', guest: 'Yaw Antwi', status: 'confirmed' },
  { no: 'RES-24131', guest: 'Daniel Kusi', status: 'confirmed' },
  { no: 'RES-24133', guest: 'Linda Eshun', status: 'confirmed' },
  { no: 'RES-24138', guest: 'Kofi Asante', status: 'confirmed' },
  { no: 'RES-24140', guest: 'Ama Serwaa', status: 'tentative' },
  { no: 'RES-24144', guest: 'Peter Amoah', status: 'confirmed' },
  { no: 'RES-24088', guest: 'Michael Addo', status: 'confirmed' },
  { no: 'RES-24095', guest: 'Esi Danso', status: 'confirmed' },
  { no: 'RES-24151', guest: 'Nana Kwabena', status: 'confirmed' },
  { no: 'RES-24153', guest: 'Abena Nyarko', status: 'confirmed' },
  { no: 'RES-24158', guest: 'Ibrahim Fuseini', status: 'confirmed' },
];

/**
 * One stay per line: [reservation index, room id or null, status, arrival, departure, type code]
 * The window is 3 → 17 November, the business date is 5 November.
 */
const STAYS = [
  [11, 301, 'checked_in', '2026-11-01', '2026-11-07', 'EXS'], // Michael Addo, five nights
  [12, 105, 'checked_out', '2026-11-01', '2026-11-04', 'STG'], // Esi Danso, left yesterday
  [0, 201, 'checked_in', '2026-11-02', '2026-11-06', 'DSV'],  // Sarah Osei, checking out tomorrow
  [2, 101, 'checked_in', '2026-11-03', '2026-11-07', 'STG'],  // Kwesi Mensah
  [1, 302, 'checked_in', '2026-11-03', '2026-11-06', 'EXS'],  // Grace Owusu
  [3, 204, 'checked_in', '2026-11-04', '2026-11-08', 'DSV'],  // Fatima Alhassan
  [4, 103, 'reserved', '2026-11-05', '2026-11-08', 'STG'],    // Adjoa Boateng, arriving today
  [5, 202, 'reserved', '2026-11-05', '2026-11-07', 'DSV'],    // Yaw Antwi, arriving today
  [6, 303, 'reserved', '2026-11-06', '2026-11-11', 'FAM'],    // Daniel Kusi
  [7, 305, 'reserved', '2026-11-07', '2026-11-10', 'DSV'],    // Linda Eshun
  [8, 102, 'reserved', '2026-11-09', '2026-11-12', 'STG'],    // Kofi Asante
  [9, 203, 'reserved', '2026-11-10', '2026-11-14', 'DSV'],    // Ama Serwaa, tentative
  [10, 104, 'reserved', '2026-11-12', '2026-11-16', 'FAM'],   // Peter Amoah
  [13, null, 'reserved', '2026-11-07', '2026-11-09', 'SGL'],  // Nana Kwabena, no room yet
  [14, null, 'reserved', '2026-11-09', '2026-11-12', 'FAM'],  // Abena Nyarko, no room yet
  [15, null, 'reserved', '2026-11-14', '2026-11-17', 'DSV'],  // Ibrahim Fuseini, no room yet
];

export function tapeContext() {
  const from = day('2026-11-03');
  const days = 14;
  const to = from.copy().addDays(days);
  const today = day(BUSINESS_DATE);

  const stays = STAYS.map(([resIndex, roomId, status, arrival, departure, typeCode], index) => {
    const res = reservations[resIndex];
    return {
      id: index + 1,
      room_id: roomId,
      status,
      arrival_date: day(arrival),
      departure_date: day(departure),
      reservation_id: 9000 + resIndex,
      roomType: TYPES[typeCode],
      reservation: {
        id: 9000 + resIndex,
        reservation_no: res.no,
        status: res.status,
        guest: { full_name: res.guest, first_name: res.guest.split(' ')[0], last_name: res.guest.split(' ')[1] },
      },
    };
  });

  const rooms = ROOMS.map(room).sort((a, b) => (a.room_type_id - b.room_type_id) || a.room_no.localeCompare(b.room_no));

  // Grouped by room type name, exactly as the controller hands it to the view.
  const grouped = {};
  for (const item of rooms) {
    const key = item.roomType?.name ?? 'Unassigned room type';
    (grouped[key] ||= Coll.of([])).items.push(item);
  }
  Object.keys(grouped).forEach(key => { grouped[key] = Coll.of(grouped[key].items); });

  const byRoom = {};
  for (const stay of stays) {
    if (stay.room_id === null) continue;
    (byRoom[stay.room_id] ||= Coll.of([])).items.push(stay);
  }
  Object.keys(byRoom).forEach(key => { byRoom[key] = Coll.of(byRoom[key].items); });

  const unassigned = Coll.of(stays.filter(stay => stay.room_id === null));

  const within = stay => stay.arrival_date.gte(from) && stay.arrival_date.lt(to);
  const summary = {
    rooms: rooms.length,
    stays: stays.length,
    arrivals: stays.filter(stay => stay.status === 'reserved' && within(stay)).length,
    inHouse: stays.filter(stay => stay.status === 'checked_in').length,
    departures: stays.filter(stay => stay.status === 'checked_in'
      && stay.departure_date.gte(from) && stay.departure_date.lt(to)).length,
    unassigned: unassigned.count(),
  };

  // Dated blocks — a maintenance block or a room let as an apartment — take that room
  // off the market for those nights, so they leave both the drawing and the denominator.
  const OFF_MARKET = ['out_of_order', 'out_of_service'];
  const sellable = rooms.filter(item => !OFF_MARKET.includes(item.status)).length;
  const OUTAGES = [
    // [room id, starts on, ends on (exclusive), reason]
    [304, '2026-11-09', '2026-11-12', 'maintenance'],
  ];
  const REASONS = { maintenance: 'Maintenance', renovation: 'Renovation', vip: 'VIP hold', group: 'Group hold', management: 'Management block', house: 'House use', owner: 'Owner use', other: 'Other' };
  const offMarket = new Set(rooms.filter(item => OFF_MARKET.includes(item.status)).map(item => item.id));
  const blocksByRoom = {};
  const blockNotes = [];
  OUTAGES.forEach(([roomId, startsOn, endsOn, reason]) => {
    if (offMarket.has(roomId)) return;
    const roomNo = rooms.find(item => item.id === roomId)?.room_no ?? roomId;
    const short = REASONS[reason] ?? reason;
    (blocksByRoom[roomId] ??= []).push({
      from: day(startsOn), to: day(endsOn),
      title: `Room ${roomNo} blocked ${day(startsOn).format('d M')} – ${day(endsOn).format('d M')} (${short})`,
      short,
    });
    blockNotes.push(`room ${roomNo} is blocked ${day(startsOn).format('j M')} – ${day(endsOn).format('j M')} (${short.toLowerCase()})`);
  });
  // One flat key per room-night, exactly as the controller builds it: "304|2026-11-09".
  const blockedNights = {};
  Object.entries(blocksByRoom).forEach(([roomId, list]) => list.forEach(block => {
    for (let i = 0; i < days; i += 1) {
      const night = from.copy().addDays(i).toDateString();
      if (block.from.toDateString() <= night && block.to.toDateString() > night) {
        blockedNights[`${roomId}|${night}`] = true;
      }
    }
  }));

  // The same numbers the controller puts under the day columns: rooms sold on each
  // night, and that as a share of the rooms that can be sold that night (room 205 is out
  // of order all window; room 304 is blocked for three nights).
  const dailyTotals = Array.from({ length: days }, (_, index) => {
    const day = from.copy().addDays(index);
    const iso = day.toDateString();
    const sold = stays.filter(stay => stay.room_id !== null
      && ['reserved', 'checked_in'].includes(stay.status)
      && stay.reservation?.status !== 'tentative'
      // compare the dates, not the timestamps: the sample carries 09:00 for the day
      && stay.arrival_date.toDateString() <= iso && stay.departure_date.toDateString() > iso).length;
    const blocked = Object.keys(blockedNights).filter(key => key.endsWith(`|${iso}`)).length;
    const sellableTonight = Math.max(0, sellable - blocked);
    return {
      day: iso,
      label: day.format('l, j F Y'),
      short: day.format('j M'),
      occupied: sold,
      sellable: sellableTonight,
      blocked,
      pct: sellableTonight > 0 ? Math.round((sold / sellableTonight) * 100) : 0,
    };
  });

  const roomNights = dailyTotals.reduce((total, entry) => total + entry.occupied, 0);
  const sellableNights = dailyTotals.reduce((total, entry) => total + entry.sellable, 0);
  const peak = dailyTotals.slice().sort((a, b) => b.occupied - a.occupied)[0] ?? null;
  // Which rooms are left out of the denominator, and why — the note under the grid
  // names them so nobody has to guess why 15 active rooms count as 14.
  const excluded = rooms
    .filter(item => ['out_of_order', 'out_of_service'].includes(item.status))
    .map(item => ({
      room_no: item.room_no,
      reason: item.status === 'out_of_order' ? 'out of order' : 'out of service',
    }));
  const totalsSummary = {
    roomNights,
    sellableNights,
    sellable,
    active: rooms.length,
    excluded,
    blockedNights: dailyTotals.reduce((total, entry) => total + entry.blocked, 0),
    blockNotes,
    avgPct: sellableNights > 0 ? Math.round((roomNights / sellableNights) * 100) : 0,
    peak,
  };

  return {
    helpers: {
      number_format: value => Number(value ?? 0).toLocaleString('en-US'),
      route: () => '#',
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      bdate: () => day(BUSINESS_DATE),
      now: () => new SampleDate('2026-11-05T08:40:00Z'),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: (...values) => values.every(value => value !== undefined && value !== null),
      empty: phpEmpty,
      in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
      json: value => JSON.stringify(value),
      str_replace: (search, replace, subject) => String(subject ?? '').split(search).join(replace),
      strtolower: value => String(value ?? '').toLowerCase(),
      strtoupper: value => String(value ?? '').toUpperCase(),
      ucfirst: value => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
      abs: value => Math.abs(Number(value ?? 0)),
      round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
      max: (...values) => Math.max(...values.flat()),
      min: (...values) => Math.min(...values.flat()),
      implode: (glue, list) => (list instanceof Coll ? list.items : list ?? []).join(glue),
      __static: (cls, method, ...args) => {
        const name = String(cls).split('\\').pop();
        if (name === 'Str' && method === 'plural') {
          const [word, count] = args;
          const text = String(word ?? '');
          if (Number(count) === 1) return text;
          if (/(s|x|z|ch|sh)$/.test(text)) return `${text}es`;
          return `${text}s`;
        }
        return args[0] ?? '';
      },
      __const: () => [],
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      from,
      to,
      days,
      today,
      rooms: grouped,
      byRoom,
      unassigned,
      dailyTotals,
      totalsSummary,
      blocksByRoom,
      blockedNights,
      hasDayUse: false,
      canDrag: true,
      canMoveInHouse: true,
      canViewReservation: true,
      canViewHousekeeping: true,
      summary,
      currentHotel: HOTEL,
    },
  };
}
