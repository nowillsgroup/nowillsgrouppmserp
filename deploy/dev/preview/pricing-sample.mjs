/**
 * Sample data for the Revenue Pricing Grid preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/revenue/pricing.blade.php against this fixed sample. The shapes mirror
 * RevenueService::recommendations() — one cell per room type and night, each carrying the
 * live rate, the base rate, the recommendation with the rules that produced it, the
 * guard-rail it hit, whether the date is fixed or closed, and how many rooms are left —
 * so the grid composes exactly as it does in the application. Only the numbers are fixed.
 */
import { Coll, SampleDate } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-05';
const day = iso => new SampleDate(`${iso}T09:00:00Z`);
const MODES = { off: 'Off', recommend: 'Recommend – I publish', auto: 'Auto-pilot – live pricing' };
const WEEKEND = [5, 6]; // RateService::WEEKEND — Friday, Saturday

const DATES = ['2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11'];

/* ── five room types with their guard-rails ────────────────────────────────── */
const TYPES = [
  { id: 4, name: 'Standard Garden', base_rate: 620, min_rate: 480, max_rate: 900 },
  { id: 3, name: 'Deluxe Sea View', base_rate: 950, min_rate: 780, max_rate: 1400 },
  { id: 7, name: 'Executive Suite', base_rate: 1650, min_rate: 1400, max_rate: 2400 },
  { id: 9, name: 'Family Room', base_rate: 1180, min_rate: null, max_rate: 1800 },
  { id: 11, name: 'Standard Single', base_rate: 480, min_rate: 420, max_rate: null },
];

// [hotel occupancy %, rooms sold, sellable capacity] — one entry per night in DATES.
const OCCUPANCY = [
  [68, 65, 96], [79, 76, 96], [91, 87, 96], [54, 52, 96], [46, 44, 96], [43, 41, 96], [47, 45, 96],
];

/** A rule that fires on a cell, spelled the way YieldRule::adjustmentLabel() spells it. */
const rule = (name, adj, delta) => ({ name, adj, delta });

export function pricingContext() {
  const types = Coll.of(TYPES.map(type => ({ ...type })));
  const occ = {};
  DATES.forEach((date, index) => {
    const [hotel, sold, cap] = OCCUPANCY[index];
    occ[date] = {
      hotel,
      sold,
      cap,
      types: Object.fromEntries(TYPES.map(type => [type.id, Math.min(100, Math.round(hotel * (type.id === 7 ? 0.8 : 1)))])),
      left: Object.fromEntries(TYPES.map(type => [type.id, Math.max(0, Math.round((cap - sold) * (type.id === 7 ? 0.35 : 0.6)))])),
    };
  });

  // One deliberate case of each thing the grid has to show: a raise, a lower, a fixed
  // contract date, a stop-sell, a rate held at the ceiling, and a minimum-stay rule.
  const grid = {};
  TYPES.forEach(type => {
    grid[type.id] = {};
    DATES.forEach((date, index) => {
      const lead = Math.round((day(date) - day(BUSINESS_DATE)) / 86400000);
      const hotelOcc = OCCUPANCY[index][0];
      const base = type.base_rate * (WEEKEND.includes(new Date(`${date}T00:00:00Z`).getUTCDay()) ? 1.15 : 1);
      let rate = base;
      const rules = [];
      let limit = null;
      let minStay = null;

      if (hotelOcc >= 88) {
        rules.push(rule('High occupancy', '+12%', Math.round(base * 0.12)));
        rate = base * 1.12;
      } else if (hotelOcc <= 45) {
        rules.push(rule('Low occupancy', '-8%', -Math.round(base * 0.08)));
        rate = base * 0.92;
      }
      if (lead === 1 && type.id === 3) {
        rules.push(rule('Last-minute lead time', '+5%', Math.round(rate * 0.05)));
        rate *= 1.05;
        minStay = 2;
      }
      // Guard-rails: round to rm_round_to (50), then the floor and ceiling.
      const step = 50;
      if (rules.length) rate = Math.round(rate / step) * step;
      if (type.max_rate !== null && rate > type.max_rate) { rate = type.max_rate; limit = 'ceiling'; }
      if (type.min_rate !== null && rate < type.min_rate) { rate = type.min_rate; limit = 'floor'; }

      const fixed = type.id === 9 && (date === '2026-11-07' || date === '2026-11-08');
      const stop = type.id === 7 && date === '2026-11-07';
      // The live rate is the base the calendar resolves, unchanged by revenue management.
      const live = Math.round(base);
      const current = type.id === 3 && date === '2026-11-06' ? rate : live;

      grid[type.id][date] = {
        current,
        base: Math.round(base),
        rec: {
          base: Math.round(base), rate: Math.round(rate), rules, min_stay: minStay,
          occ_hotel: hotelOcc, occ_type: occ[date].types[type.id], lead, limit,
        },
        fixed,
        published: type.id === 11 && date === '2026-11-05',
        stop,
        left: occ[date].left[type.id],
        occ_type: occ[date].types[type.id],
        occ_hotel: hotelOcc,
        change: !fixed && Math.abs(Math.round(rate) - current) >= 0.01,
      };
    });
  });

  const pending = Object.values(grid)
    .flatMap(dates => Object.values(dates))
    .filter(cell => cell.change).length;

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      route: name => (name === 'revenue.pricing' ? '#pricing' : name === 'revenue.index' ? '#revenue' : '#'),
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      bdate: () => day(BUSINESS_DATE),
      now: () => new SampleDate('2026-11-05T08:40:00Z'),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (value instanceof SampleDate ? value.format('d M Y') : String(value ?? '')),
      // The settings the grid reads: the rounding step and the horizon the forecast runs to.
      setting: (key, fallback = null) => ({ rm_round_to: 50, rm_horizon_days: 90, rm_mode: 'recommend' }[key] ?? fallback),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: (...values) => values.every(value => value !== undefined && value !== null),
      empty: value => value === undefined || value === null || value === ''
        || (Array.isArray(value) && !value.length) || (value instanceof Coll && value.isEmpty()),
      in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
      json: value => JSON.stringify(value),
      round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
      max: (...values) => Math.max(...values.flat()),
      min: (...values) => Math.min(...values.flat()),
      implode: (glue, list) => (list instanceof Coll ? list.items : list ?? []).join(glue),
      __static: (cls, method, ...args) => {
        const name = String(cls).split('\\').pop();
        if (name === 'Carbon' && method === 'parse') return day(String(args[0]).slice(0, 10));
        return args[0] ?? '';
      },
      __const: (cls, name) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'RevenueService' && name === 'MODES') return MODES;
        if (owner === 'RateService' && name === 'WEEKEND') return WEEKEND;
        return [];
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      from: day(BUSINESS_DATE),
      days: DATES.length,
      types,
      grid,
      occ,
      pending,
      mode: 'recommend',
      currentHotel: HOTEL,
    },
  };
}
