/**
 * Sample data for the Online Payment Settings preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/payments/settings.blade.php against this fixed sample. The shapes mirror what
 * OnlinePaymentController::settings() passes to the view: the plain settings as stored, which
 * secret keys are saved (never their values), the webhook URL per provider, and whether each
 * provider has its keys. The numbers and keys are the shipped defaults; only the "saved" flags
 * are invented, and no secret is ever printed.
 */
import { Coll } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

/* OnlinePaymentService::PROVIDERS / DEPOSIT_POLICIES, verbatim. */
const PROVIDERS = {
  demo: 'Built-in test gateway',
  paystack: 'Paystack (Ghana, cards & MoMo)',
  stripe: 'Stripe (international cards)',
  razorpay: 'Razorpay (India)',
};
const DEPOSIT_POLICIES = {
  none: 'No deposit — book now, pay on arrival',
  percent: 'A deposit of a percentage of the stay',
  first_night: 'A deposit of the first night',
  full: 'Payment in full',
};
const SECRETS = ['pay_paystack_secret', 'pay_stripe_secret', 'pay_stripe_webhook_secret', 'pay_razorpay_key_secret', 'pay_razorpay_webhook_secret'];

/* The stored settings. Online payments are ON and the deposit is 30 % of the stay — the state a
   live property would be in, and the one where the deposit card actually takes money. */
const VALUES = {
  pay_enabled: '1',
  pay_provider: 'paystack',
  pay_link_hours: '72',
  pay_receipt_email: '1',
  pay_portal_enabled: '1',
  pay_booking_deposit: 'percent',
  pay_booking_deposit_pct: '30',
  pay_booking_hold_minutes: '60',
  pay_razorpay_key_id: '',
};

export function paymentsSettingsContext({ enabled = true } = {}) {
  // The same screen has one story when the gateway is on and another when it is off:
  // OnlinePaymentService::depositPolicy() answers 'none' the moment the gateway is off, so the
  // deposit card has to say it is taking no money. Both states are built and both are checked.
  if (!enabled) VALUES.pay_enabled = '0';
  const secretSet = {};
  SECRETS.forEach(key => { secretSet[key] = key === 'pay_paystack_secret'; });

  return {
    helpers: {
      old: (key, fallback = null) => (VALUES[key] !== undefined ? VALUES[key] : fallback),
      e: value => String(value ?? ''),
      route: name => `#${String(name).replace(/\./g, '-')}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      setting: (key, fallback = null) => (VALUES[key] !== undefined ? VALUES[key] : fallback),
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      json: value => JSON.stringify(value),
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'OnlinePaymentService' && method === 'currency') return 'GHS';
        return args[0] ?? '';
      },
      __const: (cls, name) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'OnlinePaymentService' && name === 'PROVIDERS') return PROVIDERS;
        if (owner === 'OnlinePaymentService' && name === 'DEPOSIT_POLICIES') return DEPOSIT_POLICIES;
        return [];
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      v: VALUES,
      secretSet,
      configured: { demo: true, paystack: true, stripe: false, razorpay: false },
      hooks: {
        paystack: 'https://grove.nowills.app/pay/webhook/paystack/GROVE',
        stripe: 'https://grove.nowills.app/pay/webhook/stripe/GROVE',
        razorpay: 'https://grove.nowills.app/pay/webhook/razorpay/GROVE',
      },
      currentHotel: HOTEL,
    },
  };
}
