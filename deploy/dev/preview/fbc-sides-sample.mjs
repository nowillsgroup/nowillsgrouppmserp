/**
 * Sample data for the side-dish (F&B) preview — resources/views/fbc/sides.blade.php rendered the way
 * FbCostController::sides() hands it over: what FbCostService::sideDishes() returns for the 28 days to 6 November 2026.
 *
 * The sample is built so the screen can be proved against itself: every row's gross profit is its revenue less its cost,
 * the row costs add up to the total cost to the pesewa, and the five side dishes add up to the 682 portions and the
 * 8,152.00 the header claims.
 *
 *   French fries      25.00 +  7.50 × 214   revenue 5,350.00   cost 1,605.00   GP 3,745.00   70.0%
 *   Garden salad       0.00 +  6.20 × 168   revenue     0.00   cost 1,041.60   GP −1,041.60  —
 *   Extra banku       12.00 +  4.10 ×  96   revenue 1,152.00   cost   393.60   GP   758.40   65.8%
 *   Extra shito        5.00 +  1.15 × 141   revenue   705.00   cost   162.15   GP   542.85   77.0%
 *   Fried plantain    15.00 +  5.00 ×  63   revenue   945.00   cost   315.00   GP   630.00   66.7%
 *   ─────────────────────────────────────────────────────────────────────────────────────────────────────
 *   total                        682        revenue 8,152.00   cost 3,517.35   GP 4,634.65   56.9%
 *
 * The free salad is the point of the screen: the dish it comes with earns nothing extra, and the salad still costs money.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-06T06:00:00Z');

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency: 'GHS', currency_symbol: 'GH₵' };

const row = (id, name, units, revenue, cost) => {
  const gp = Math.round((revenue - cost) * 100) / 100;
  return { id, name, units, revenue, cost, gp, gp_pct: revenue > 0 ? Math.round(gp / revenue * 1000) / 10 : null, share: 0 };
};

const ROWS = [
  row(501, 'French fries', 214, 5350.00, 1605.00),
  row(502, 'Garden salad', 168, 0.00, 1041.60),
  row(503, 'Extra banku', 96, 1152.00, 393.60),
  row(504, 'Extra shito', 141, 705.00, 162.15),
  row(505, 'Fried plantain', 63, 945.00, 315.00),
];

const units = ROWS.reduce((sum, r) => sum + r.units, 0);
const revenue = Math.round(ROWS.reduce((sum, r) => sum + r.revenue, 0) * 100) / 100;
const cost = Math.round(ROWS.reduce((sum, r) => sum + r.cost, 0) * 100) / 100;
const gp = Math.round((revenue - cost) * 100) / 100;
for (const r of ROWS) r.share = Math.round(r.revenue / revenue * 1000) / 10;

export const SIDES = {
  rows: ROWS,
  units,
  revenue,
  cost,
  gp,
  gp_pct: revenue > 0 ? Math.round(gp / revenue * 1000) / 10 : null,
  uncosted: 0,
};

export function fbcSidesContext() {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      // money_short: millions and hundred-thousands compacted; below 100K the full amount, as app/helpers.php does
      money_short: (value) => {
        const a = Number(value ?? 0);
        const fmt = (n, places) => n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: places });
        if (Math.abs(a) >= 1_000_000) return `GH₵ ${fmt(a / 1_000_000, 2)}M`;
        if (Math.abs(a) >= 100_000) return `GH₵ ${fmt(a / 1_000, 1)}K`;
        return `GH₵ ${a.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      },
      bdate: () => TODAY,
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? Object.values(params).join('-') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      request: () => null,
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? ''),
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      from: '2026-10-10',
      to: '2026-11-06',
      outlet: null,
      outlets: new Coll([
        { id: 1, name: 'Garden Restaurant' },
        { id: 2, name: 'Pool Bar' },
        { id: 3, name: 'Room Service' },
      ]),
      sides: SIDES,
      currentHotel: HOTEL,
    },
  };
}
