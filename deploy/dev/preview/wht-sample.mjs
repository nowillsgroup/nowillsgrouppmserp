/**
 * Sample data for the Withholding Tax (GRA) preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/ap/wht.blade.php against this fixed sample. The shapes mirror what
 * WhtController::desk() passes to the view: the month's schedule grouped by nature of payment,
 * the rate each category carries, the filing state, suppliers with no TIN on file, and the
 * ledger total compared with the schedule. Only the numbers are fixed.
 */
import { Coll, SampleDate } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const day = iso => new SampleDate(`${iso}T09:00:00Z`);
const BUSINESS_DATE = '2026-11-05';

/* WhtService::CATEGORIES — [label, rate, annual threshold applies] */
const CATEGORIES = {
  goods: ['Goods (materials, supplies)', 3, true],
  works: ['Works (repairs, construction)', 5, true],
  services: ['Services (professional, technical)', 7.5, true],
  rent: ['Rent of premises and equipment', 5, false],
  nonresident: ['Non-resident (management, technical)', 20, false],
  none: ['Not subject to withholding', 0, false],
};

/* The schedule for October 2026, one row per nature of payment that paid tax. */
const CAT_ROWS = [
  { label: 'Services (professional, technical)', rate: 7.5, n: 4, base: 62800.00, wht: 4710.00 },
  { label: 'Goods (materials, supplies)', rate: 3, n: 6, base: 148210.50, wht: 4446.32 },
  { label: 'Works (repairs, construction)', rate: 5, n: 1, base: 18600.00, wht: 930.00 },
  { label: 'Non-resident (management, technical)', rate: 20, n: 1, base: 12400.00, wht: 2480.00 },
];

export function whtDeskContext() {
  const cats = Coll.of(CAT_ROWS.map(row => ({ ...row })));
  const wht = cats.sum('wht');
  const base = cats.sum('base');
  const wvat = 1180.40;

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      route: name => `#${String(name).replace(/\./g, '-')}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      bdate: () => day(BUSINESS_DATE),
      now: () => day(BUSINESS_DATE),
      dmy: value => String(value ?? ''),
      setting: (key, fallback = null) => ({ wht_enabled: '1', wht_threshold: '2000', wvat_agent: '1', wvat_rate: '7' }[key] ?? fallback),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
      abs: value => Math.abs(Number(value ?? 0)),
      rtrim: (value, chars = ' ') => {
        let out = String(value ?? '');
        while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1);
        return out;
      },
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'WhtService' && method === 'CATEGORIES') return CATEGORIES;
        return args[0] ?? '';
      },
      __const: (cls, name) => (name === 'CATEGORIES' ? CATEGORIES : []),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      period: '2026-10',
      periods: ['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
      enabled: true,
      // the September return is still open, so the desk warns about it
      alert: { period: '2026-09', overdue: true, due: day('2026-10-15') },
      closed: true,
      cfg: { wht_enabled: '1', wht_threshold: '2000', wvat_agent: '1', wvat_rate: '7' },
      r: {
        cats,
        // SupplierPayment rows carry the withheld column as `wht_amount`; the card counts on that name
        rows: Coll.of(CAT_ROWS.filter(row => row.wht > 0).map(row => ({ ...row, wht_amount: row.wht }))),
        base,
        wht,
        wvat,
        due: day('2026-11-15'),
        filing: null,
        missing_tin: Coll.of(['Adom Facilities Ltd', 'Kwabena Mensah']),
        // the ledger is short of the schedule by one payment that posted after the close
        gl: wht - 471.00,
      },
      currentHotel: HOTEL,
    },
  };
}
