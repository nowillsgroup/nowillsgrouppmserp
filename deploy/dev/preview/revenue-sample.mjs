/**
 * Sample data for the Revenue Dashboard preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/revenue/index.blade.php against this fixed sample. The shapes mirror
 * RevenueService::forecast() and RevenueService::kpis() — one row per stay date with its
 * capacity, on-the-books rooms, group holds, ADR, revenue, pickup and forecast — so the
 * chart, the four stat cards, the table and the totals compose exactly as they do in the
 * application. Only the property and the numbers are fixed.
 */
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-05';
const day = iso => new SampleDate(`${iso}T09:00:00Z`);
const CAPACITY = 96;

const MODES = { off: 'Off', recommend: 'Recommend – I publish', auto: 'Auto-pilot – live pricing' };

/* ── fourteen nights, quiet weekdays and a sold-out weekend ────────────────── */
// [date, rooms on the books, group holds, adr, pickup 1d, pickup 7d, forecast rooms]
const NIGHTS = [
  ['2026-11-05', 61, 4, 812, 3, 9, 66],
  ['2026-11-06', 74, 6, 892, 2, 11, 84],
  ['2026-11-07', 88, 6, 948, 1, 14, 94],
  ['2026-11-08', 52, 0, 704, 4, 6, 55],
  ['2026-11-09', 44, 0, 676, 1, 3, 46],
  ['2026-11-10', 41, 0, 664, 0, 2, 44],
  ['2026-11-11', 45, 0, 672, 2, 5, 48],
  ['2026-11-12', 49, 0, 688, 1, 4, 52],
  ['2026-11-13', 66, 8, 824, 3, 8, 78],
  ['2026-11-14', 81, 8, 906, 2, 12, 90],
  ['2026-11-15', 46, 0, 690, 1, null, 49],
  ['2026-11-16', 38, 0, 648, 0, null, 41],
  ['2026-11-17', 35, 0, 642, 1, null, 38],
  ['2026-11-18', 33, 0, 638, null, null, 36],
];

const WEEKEND_DOW = [5, 6]; // Friday, Saturday — RateService::WEEKEND

function buildRows() {
  return NIGHTS.map(([date, rooms, held, adr, pickup1, pickup7, fcRooms]) => {
    const d = day(date);
    const sold = rooms + held;
    const revenue = Math.round(rooms * adr * 100) / 100;
    const fcAdd = Math.max(0, fcRooms - sold);

    return {
      date,
      dow: d.format('D'),
      weekend: WEEKEND_DOW.includes(new Date(`${date}T00:00:00Z`).getUTCDay()),
      lead: Math.round((d - day(BUSINESS_DATE)) / 86400000),
      cap: CAPACITY,
      rooms,
      held,
      occ: Math.round(sold * 1000 / CAPACITY) / 10,
      revenue,
      adr,
      revpar: Math.round(revenue / CAPACITY * 100) / 100,
      pickup1,
      pickup7,
      fc_rooms: fcRooms,
      fc_occ: Math.round(fcRooms * 1000 / CAPACITY) / 10,
      fc_revenue: Math.round((revenue + fcAdd * adr + held * adr) * 100) / 100,
    };
  });
}

/** The same arithmetic RevenueService::kpis() does over a slice of the forecast. */
function aggregate(rows) {
  const sum = key => rows.reduce((total, row) => total + Number(row[key] ?? 0), 0);
  const cap = sum('cap');
  const rooms = sum('rooms');
  const held = sum('held');
  const revenue = sum('revenue');

  return {
    occ: cap ? Math.round((rooms + held) * 1000 / cap) / 10 : 0,
    adr: rooms ? Math.round(revenue / rooms * 100) / 100 : 0,
    revpar: cap ? Math.round(revenue / cap * 100) / 100 : 0,
    revenue: Math.round(revenue * 100) / 100,
    rooms,
    fc_occ: cap ? Math.round(sum('fc_rooms') * 1000 / cap) / 10 : 0,
    fc_revenue: Math.round(sum('fc_revenue') * 100) / 100,
  };
}

export function revenueContext() {
  const rows = buildRows();

  const kpi = {
    d7: aggregate(rows.slice(0, 7)),
    d30: aggregate(rows),
    pickup: { bookings: 23, nights: 61, revenue: 48620, cancellations: 2, cx_nights: 4, cx_revenue: 3312 },
  };

  // Pickup by booking source, the shape the controller's groupBy produces.
  const sources = Coll.of([
    { name: 'Website', bookings: 9, nights: 26, revenue: 21460 },
    { name: 'Booking.com', bookings: 6, nights: 15, revenue: 12280 },
    { name: 'Telephone', bookings: 4, nights: 11, revenue: 9180 },
    { name: 'Walk-in', bookings: 3, nights: 6, revenue: 4190 },
    { name: 'Corporate account', bookings: 1, nights: 3, revenue: 1510 },
  ]).sortByDesc('revenue').values();

  const ctx = {
    helpers: {
      number_format: value => Number(value ?? 0).toLocaleString('en-US'),
      route: name => (name === 'revenue.pricing' ? '#pricing' : '#'),
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      bdate: () => day(BUSINESS_DATE),
      now: () => new SampleDate('2026-11-05T08:40:00Z'),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (value instanceof SampleDate ? value.format('d M Y') : String(value ?? '')),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: (...values) => values.every(value => value !== undefined && value !== null),
      empty: phpEmpty,
      in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
      json: value => JSON.stringify(value),
      round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
      max: (...values) => Math.max(...values.flat()),
      min: (...values) => Math.min(...values.flat()),
      implode: (glue, list) => (list instanceof Coll ? list.items : list ?? []).join(glue),
      // the view asks Carbon to parse a stored date and the service for its mode labels
      __static: (cls, method, ...args) => {
        const name = String(cls).split('\\').pop();
        if (name === 'Carbon' && method === 'parse') return day(String(args[0]).slice(0, 10));
        return args[0] ?? '';
      },
      __const: (cls, name) => (String(cls).endsWith('RevenueService') && name === 'MODES' ? MODES : []),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      days: rows.length,
      rows,
      kpi,
      sources,
      mode: 'recommend',
      rulesCount: 4,
      pending: 6,
      lastSnapshot: day('2026-11-05'),
      currentHotel: HOTEL,
    },
  };

  return ctx;
}
