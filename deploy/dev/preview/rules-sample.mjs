/**
 * Sample data for the Revenue Pricing Rules preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/revenue/rules.blade.php against this fixed sample. The rules mirror the shapes
 * YieldRule::conditionLabel() and adjustmentLabel() spell out — an occupancy band, a lead-time
 * band, chosen nights, a stay-date range, a room-type-only rule, a percentage and a fixed amount —
 * and the room types carry the guard-rails the limits table edits. Only the numbers are fixed.
 */
import { Coll, phpEmpty } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const day = iso => new Date(`${iso}T09:00:00Z`);
const MODES = { off: 'Off', recommend: 'Recommend – I publish', auto: 'Auto-pilot – live pricing' };

/* YieldRule::DAYS */
const DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };

const TYPES = [
  { id: 4, name: 'Standard Garden', base_rate: 620, weekend_rate: 700, min_rate: 480, max_rate: 900 },
  { id: 3, name: 'Deluxe Sea View', base_rate: 950, weekend_rate: 1050, min_rate: 780, max_rate: 1400 },
  { id: 7, name: 'Executive Suite', base_rate: 1650, weekend_rate: null, min_rate: 1400, max_rate: 2400 },
  { id: 9, name: 'Family Room', base_rate: 1180, weekend_rate: 1300, min_rate: null, max_rate: 1800 },
  { id: 11, name: 'Standard Single', base_rate: 480, weekend_rate: null, min_rate: 420, max_rate: null },
];

/* One rule of each shape the screen has to explain, in the order RevenueController::rules() sorts
   them: lowest priority number first, then id. */
const RULES = [
  { id: 1, priority: 1, name: 'Africa Cup group stage', room_type_id: null, basis: 'hotel', occ_min: null, occ_max: null,
    lead_min: null, lead_max: null, days: null, date_from: '2026-11-20', date_to: '2026-11-29',
    adjust_type: 'amount', adjust_value: 200, min_stay: 3, is_active: true },
  { id: 2, priority: 2, name: 'High demand', room_type_id: null, basis: 'hotel', occ_min: 85, occ_max: 100,
    lead_min: null, lead_max: null, days: null, date_from: null, date_to: null,
    adjust_type: 'percent', adjust_value: 18, min_stay: null, is_active: true },
  { id: 3, priority: 3, name: 'Weekend premium', room_type_id: null, basis: 'hotel', occ_min: null, occ_max: null,
    lead_min: null, lead_max: null, days: '5,6', date_from: null, date_to: null,
    adjust_type: 'percent', adjust_value: 8, min_stay: 2, is_active: true },
  { id: 4, priority: 4, name: 'Last-minute low demand', room_type_id: null, basis: 'type', occ_min: 0, occ_max: 45,
    lead_min: 0, lead_max: 7, days: null, date_from: null, date_to: null,
    adjust_type: 'percent', adjust_value: -12, min_stay: null, is_active: true },
  { id: 5, priority: 5, name: 'Early booking', room_type_id: null, basis: 'hotel', occ_min: null, occ_max: null,
    lead_min: 60, lead_max: 730, days: null, date_from: null, date_to: null,
    adjust_type: 'percent', adjust_value: -5, min_stay: null, is_active: true },
  { id: 6, priority: 6, name: 'Suite quiet Tuesdays', room_type_id: 7, basis: 'type', occ_min: 0, occ_max: 55,
    lead_min: null, lead_max: null, days: '2', date_from: null, date_to: null,
    adjust_type: 'percent', adjust_value: 4, min_stay: null, is_active: false },
];

const typeById = Object.fromEntries(TYPES.map(type => [type.id, type]));

/* YieldRule::conditionLabel() and adjustmentLabel(), spelled exactly as the model spells them. */
function rule(row) {
  const pct = row.adjust_type !== 'amount';
  const value = Number(row.adjust_value);
  const nf = (n, places) => Number(n).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places });
  const trim = text => String(text).replace(/0+$/, '').replace(/\.$/, '');
  const dayList = row.days ? row.days.split(',').map(Number) : [];

  return {
    ...row,
    roomType: row.room_type_id ? typeById[row.room_type_id] : null,
    adjustmentLabel: () => {
      const sign = value >= 0 ? '+' : '−';
      return pct ? `${sign}${trim(nf(Math.abs(value), 2))}%` : `${sign}GH₵ ${nf(Math.abs(value), 2)}`;
    },
    conditionLabel: () => {
      const parts = [];
      if (row.occ_min !== null || row.occ_max !== null) {
        parts.push(`${row.basis === 'type' ? 'room-type' : 'hotel'} occupancy ${row.occ_min ?? 0}–${row.occ_max ?? 100}%`);
      }
      if (row.lead_min !== null || row.lead_max !== null) {
        parts.push(row.lead_min === null ? `booked ≤ ${row.lead_max} days ahead`
          : row.lead_max === null ? `booked ≥ ${row.lead_min} days ahead`
            : `booked ${row.lead_min}–${row.lead_max} days ahead`);
      }
      if (dayList.length) parts.push(`${dayList.map(d => DAYS[d] ?? d).join('/')} nights`);
      if (row.date_from || row.date_to) {
        const dmy = iso => (iso ? `${iso.slice(8, 10)} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(iso.slice(5, 7)) - 1]}` : '…');
        parts.push(`${dmy(row.date_from)} – ${dmy(row.date_to)}${row.date_to ? ` ${row.date_to.slice(0, 4)}` : ''}`);
      }
      return parts.length ? parts.join(' · ') : 'always';
    },
  };
}

export function revenueRulesContext() {
  const rules = Coll.of(RULES.map(rule));
  const types = Coll.of(TYPES.map(type => ({ ...type })));

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      route: name => (name === 'revenue.index' ? '#revenue' : name === 'revenue.pricing' ? '#pricing' : '#rules'),
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      bdate: () => day('2026-11-05'),
      now: () => day('2026-11-05'),
      dmy: value => String(value ?? ''),
      old: (key, fallback = null) => fallback,
      // RevenueService::mode() / horizon() / setting('rm_round_to', 50)
      setting: (key, fallback = null) => ({ rm_round_to: 50, rm_horizon_days: 90, rm_mode: 'recommend' }[key] ?? fallback),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      isset: (...values) => values.every(value => value !== undefined && value !== null),
      empty: phpEmpty,
      in_array: (needle, haystack, strict = false) => (haystack?.items ?? haystack ?? []).includes(needle),
      round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
      json: value => JSON.stringify(value),
      __static: (cls, method, ...args) => args[0] ?? '',
      __const: (cls, name) => (name === 'MODES' ? MODES : name === 'DAYS' ? DAYS : []),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      rules,
      types,
      mode: 'recommend',
      roundTo: 50,
      horizon: 90,
      currentHotel: HOTEL,
    },
  };
}
