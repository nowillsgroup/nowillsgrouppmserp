/**
 * Sample data for the supplier payment preview — resources/views/ap/payment-show.blade.php rendered the way
 * ApController::payShow() hands it over: the payment, the journals behind it, and v9.5's credit state.
 *
 * The payment is Coastal Beverages' PAY-2026-0102 — 8,000 paid on account on 30 October, nothing applied. The question a
 * clerk asks after a part payment is "where is the rest of this money, and does the ageing know about it?", so the screen
 * answers it from the credit rule itself: openCredits() finds this payment, so the 8,000 is a credit, and it is part of
 * the 11,600 the ageing nets off for this supplier at 7 November.
 *
 * The other three answers exist on the same screen and are asserted in the configuration check rather than previewed
 * again: a cancelled payment is not a credit, a foreign payment's remainder is an exchange difference, and a payment dated
 * after the ageing date is counted on the day it is dated.
 *
 * "Today" is 7 November 2026.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const SUPPLIER = { id: 7, name: 'Coastal Beverages Ltd', payment_terms_days: 30 };
const MODE = { id: 1, name: 'Bank transfer' };

const PAYMENT = {
  id: 102, payment_no: 'PAY-2026-0102', supplier_id: SUPPLIER.id, supplier: SUPPLIER, paymentMode: MODE,
  reference: 'ON ACCOUNT — OCT', date: day('2026-10-30'), created_at: new SampleDate('2026-10-30T11:05:00Z'),
  creator: { name: 'Ama Mensah' }, notes: null, cancelled_at: null, cancel_reason: null,
  amount: 8000, allocated: 0, currency: null, fx_amount: null, fx_rate: null, fx_basis: null, fx_date: null, fx_diff: 0,
  wht_category: null, wht_rate: 0, wht_base: 0, wht_amount: 0, wvat_base: 0, wvat_amount: 0, wht_cert_no: null, wht_note: null,
  allocations: new Coll([]),
  isForeign: () => false,
  unapplied: () => Math.round((Number(PAYMENT.amount) - Number(PAYMENT.allocated)) * 100) / 100,
  fxLabel: () => 'GHS 8,000.00',
  fxDifference: () => 0,
  withheld: () => 0,
  netPaid: () => 8000,
};

/* ApController::payShow(): openCredits() finds this payment, so it is a credit; and the supplier's credits are the 11,600
 * the ageing nets off (the 8,000 here plus the 3,600 debit note). */
const CREDIT = { as_of: '2026-11-07', in_credit: true, free: 8000, supplier_credits: 11600 };

export function apPaymentShowContext() {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      // dmy() takes a Carbon or a plain ISO date (the credit state carries its as-of date as a string)
      dmy: (value) => {
        if (value == null || value === '') return '—';
        if (typeof value?.format === 'function') return value.format('d M Y');
        const d = new Date(`${String(value).slice(0, 10)}T00:00:00Z`);
        if (Number.isNaN(d.getTime())) return String(value);
        const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()];
        return `${String(d.getUTCDate()).padStart(2, '0')} ${m} ${d.getUTCFullYear()}`;
      },
      bdate: () => TODAY,
      status_badge: () => '<span class="badge badge-blue">Open</span>',
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        const path = [];
        const query = [];
        const take = (key, value) => {
          if (typeof key === 'number' || /^\d+$/.test(String(key))) path.push(part(value));
          else query.push(`${key}=${part(value)}`);
        };
        // a model binds by its key, not by every column it happens to carry
        if (params && typeof params === 'object' && !Array.isArray(params) && params.id !== undefined) path.push(params.id);
        else if (Array.isArray(params)) params.forEach((value, index) => take(index, value));
        else if (params && typeof params === 'object') Object.entries(params).forEach(([key, value]) => take(key, value));
        else if (params !== undefined && params !== null) path.push(part(params));
        return `#${String(name).replace(/\./g, '-')}${path.length ? '-' + path.join('-') : ''}${query.length ? '?' + query.join('&') : ''}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      request: (key) => (key === undefined ? { boolean: () => false, except: () => ({}) } : null),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      p: PAYMENT,
      credit: CREDIT,
      journals: new Coll([]),
      currentHotel: HOTEL,
    },
  };
}
