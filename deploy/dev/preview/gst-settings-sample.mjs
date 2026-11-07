/**
 * Sample data for the GRA VAT & E-VAT settings preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/gst/settings.blade.php against this fixed sample. The shapes mirror what
 * GstController::settings() passes to the view: the property's registration record, the
 * turnover test behind its VAT status, the E-VAT switches and credentials as stored (the key is
 * never printed), the tax components in force, and whether the integration is blocked.
 *
 * The property is registered, E-VAT is ON and the connection is the sandbox simulator — the
 * state in which the screen has to warn that stamps are not reaching GRA.
 */
import { Coll, SampleDate } from './blade-render.mjs';

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', legal_name: 'The Grove Essipong Beach Resort Ltd',
  code: 'GROVE', currency: 'GHS', currency_symbol: 'GH₵',
  address: 'Beach Road, Essipong', city: 'Sekondi-Takoradi',
};

/* GstService::DEFAULTS, as stored for a registered property. */
const SETTINGS = {
  gst_einvoice_enabled: '1',
  gst_einvoice_driver: 'sandbox',
  gst_einvoice_auto: '1',
  gst_evat_pos: '1',
  gst_evat_url: '',
  gst_evat_key: 'stored-secret-placeholder-not-a-key',
  gst_evat_username: '',
  tax_prices_inclusive: '1',
};

/* The tax components Ghana applies in 2026. */
const TAXES = [
  { name: 'VAT', code: 'VAT', authority: 'GRA', rate: 15, applies_to: 'services', is_active: true },
  { name: 'NHIL', code: 'NHIL', authority: 'GRA', rate: 2.5, applies_to: 'services', is_active: true },
  { name: 'GETFund levy', code: 'GETFUND', authority: 'GRA', rate: 2.5, applies_to: 'services', is_active: true },
  { name: 'Tourism levy', code: 'TL', authority: 'GTA', rate: 1, applies_to: 'services', is_active: true },
];

export function gstSettingsContext({ enabled = true, driver = 'sandbox', blocked = false } = {}) {
  if (!enabled) SETTINGS.gst_einvoice_enabled = '0';
  SETTINGS.gst_einvoice_driver = driver;

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      route: name => `#${String(name).replace(/\./g, '-')}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      // old() reflects *submitted* input only. On a fresh GET it is null, so the view's own fallback
      // is used — which is why the stored security key is never echoed back into the password box.
      old: (key, fallback = null) => fallback,
      request: () => ({ routeIs: () => false }),
      setting: (key, fallback = null) => (SETTINGS[key] !== undefined ? SETTINGS[key] : fallback),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      e: value => String(value ?? ''),
      rtrim: (value, chars = ' ') => {
        let out = String(value ?? '');
        while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1);
        return out;
      },
      ucfirst: value => String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1),
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'GstService' && method === 'tinType') return 'Company (C)';
        if (owner === 'GstService' && method === 'alerts') {
          return { failed: 1, pending: 2, return_period: '2026-10', return_overdue: false };
        }
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      hotel: HOTEL,
      tin: 'C0001234567',
      tinOk: true,
      s: SETTINGS,
      taxes: TAXES,
      blocker: blocked ? 'the security key stored under Settings does not authenticate against the endpoint' : null,
      reg: {
        status: 'registered',
        status_label: 'Registered',
        registered_at: '2024-07-01',
        supply_kind_label: 'Services and goods',
        threshold_label: 'GH₵ 200,000 of taxable supplies in any twelve months',
        window: 'January – October 2026',
        // floats, the way GstService::vatRegistration() sums them — money() formats them
        turnover: { completed: 6184320.50, rolling: 7402118.75, open_period: 'November' },
        pct: 3702,
        act: 'Charge VAT on every taxable supply',
        note: 'This property is registered, so VAT, NHIL, GETFund and the Tourism Levy are charged on every taxable supply and reported monthly by the 15th.',
      },
      currentHotel: HOTEL,
    },
  };
}
