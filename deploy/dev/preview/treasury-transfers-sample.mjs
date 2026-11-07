/**
 * Sample data for the treasury banking & transfers preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/treasury/transfers.blade.php against this fixed sample, shaped the way
 * TreasuryController::transfers() hands it to the view: the transfers already made, the accounts that
 * can be the source of a new one, and — for each of them — TreasuryService::suggest(), whose note has
 * to add up to the amount printed beside it.
 *
 * "Today" is 7 November 2026. The cash box holds GH₵ 5,043.75 against a float of GH₵ 5,000 and a
 * GH₵ 2,000 deposit already requested, so the suggestion is 0 rounded down from 43.75 — the case in
 * which the old note ("cash in hand less the float") explained a number it did not produce.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const KINDS = { deposit: 'Bank deposit', sweep: 'Acquirer sweep', transfer: 'Bank transfer', withdrawal: 'Withdrawal' };
const STATUSES = { requested: 'Awaiting approval', approved: 'Approved', in_transit: 'In transit', completed: 'Completed', cancelled: 'Cancelled', rejected: 'Rejected' };
const TONES = { requested: 'amber', approved: 'blue', in_transit: 'blue', completed: 'green', cancelled: 'slate', rejected: 'red' };
const ACCOUNT_KINDS = { bank: 'Bank account', momo: 'Mobile Money', cash: 'Cash box', clearing: 'Card clearing', transit: 'Cash in transit', petty: 'Petty cash', forex: 'Foreign cash', cheques: 'Cheques in hand' };

const person = (name) => ({ name });

/* Laravel hands the view a paginator: countable and iterable, with links() at the foot. */
class Pager extends Coll {
  links() {
    return '<nav class="pager" aria-label="Pages"><span class="current">1</span> <a href="?page=2">2</a></nav>';
  }
}
const head = (system_key = null) => ({ system_key, id: 1, code: '1010', name: 'Cash' });

/* The accounts a transfer can start from, and what the service suggests from each. */
const CASH = { id: 3, kind: 'cash', name: 'Front office cash box', head: head('cash_on_hand') };
const CLEARING = { id: 6, kind: 'clearing', name: 'Ecobank – card settlement', head: head('card_clearing'), settle_days: 2, fee_rate: 1.75 };
const MOMO = { id: 7, kind: 'momo', name: 'MTN MoMo – collections', head: head('momo_clearing'), settle_days: 3, fee_rate: 1.0 };
const BANK_MAIN = { id: 1, kind: 'bank', name: 'Ecobank – current', head: head('bank_main') };
const BANK_PAY = { id: 2, kind: 'bank', name: 'GCB – payroll', head: head('bank_payroll') };
const TRANSIT = { id: 9, kind: 'transit', name: 'Cash in transit', head: head('cash_in_transit') };
const GATEWAY = { id: 8, kind: 'clearing', name: 'Paystack gateway clearing', head: head('gateway_clearing'), settle_days: 1, fee_rate: 1.95 };

const SUGGEST = {
  3: {
    amount: 0,
    fee: 0,
    note: 'Cash in hand GH₵ 5,043.75 less the float of GH₵ 5,000.00 less GH₵ 2,000.00 already requested.',
  },
  6: {
    amount: 18240.5,
    fee: 319.21,
    note: 'Receipts up to 05 Nov 2026 (T+2), fee 1.75%.',
  },
  7: {
    amount: 4610.0,
    fee: 46.1,
    note: 'Receipts up to 04 Nov 2026 (T+3), fee 1%.',
  },
};

const TRANSFERS = [
  { id: 41, number: 'TRF-2026-0041', slip_no: 'ECO-88213', date: '2026-11-06', kind: 'deposit', cheque_amount: 0, from: CASH, to: BANK_MAIN, amount: 8420.0, fee: 0, variance: 0, status: 'completed', requester: person('Ama Serwaa') },
  { id: 40, number: 'TRF-2026-0040', slip_no: null, date: '2026-11-05', kind: 'sweep', cheque_amount: 0, from: CLEARING, to: BANK_MAIN, amount: 21980.75, fee: 384.66, variance: 0, status: 'completed', requester: person('Kwesi Boateng') },
  { id: 39, number: 'TRF-2026-0039', slip_no: 'ECO-88190', date: '2026-11-04', kind: 'deposit', cheque_amount: 12000.0, from: CASH, to: BANK_MAIN, amount: 19600.0, fee: 0, variance: -50.0, status: 'in_transit', requester: person('Ama Serwaa') },
  { id: 38, number: 'TRF-2026-0038', slip_no: null, date: '2026-11-03', kind: 'transfer', cheque_amount: 0, from: BANK_MAIN, to: BANK_PAY, amount: 120000.0, fee: 25.0, variance: 0, status: 'approved', requester: person('Kwesi Boateng') },
  { id: 37, number: 'TRF-2026-0037', slip_no: null, date: '2026-11-02', kind: 'withdrawal', cheque_amount: 0, from: BANK_MAIN, to: CASH, amount: 15000.0, fee: 0, variance: 0, status: 'requested', requester: person('Yaw Mensah') },
];

const CHEQUES = [
  { id: 11, cheque_no: '004512', bank_name: 'GCB', amount: 4200.0, party: () => 'Sekondi Hardware Ltd' },
  { id: 12, cheque_no: '118823', bank_name: 'Absa', amount: 7800.0, party: () => 'Takoradi Chambers' },
];

export function treasuryTransfersContext({ canOp = true } = {}) {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (typeof value?.format === 'function' ? value.format('d M Y') : String(value ?? '—')),
      bdate: () => TODAY,
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? (params.id ?? '') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => canOp,
      old: (key, fallback = null) => fallback,
      request: (key) => (key === 'kind' || key === 'status' ? '' : null),
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      e: value => String(value ?? ''),
      in_array: (needle, hay, strict = false) => (hay instanceof Coll ? hay.items : Array.isArray(hay) ? hay : Object.values(hay ?? {})).includes(needle),
      rtrim: (value, chars = ' ') => { let out = String(value ?? ''); while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1); return out; },
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      __const: (alias, name) => {
        // $TT::KINDS / $TA::KINDS are different lists on the same screen.
        const owner = String(alias).split('\\').pop();
        if (owner === 'TreasuryTransfer') {
          return { KINDS, STATUSES, TONES }[name] ?? {};
        }
        if (owner === 'TreasuryAccount') return name === 'KINDS' ? ACCOUNT_KINDS : {};
        return { KINDS, STATUSES, TONES }[name] ?? {};
      },
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        return args[0] ?? '';
      },
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      // the file's top @php block supplies these when the whole view renders
      TA: 'App\\Models\\TreasuryAccount',
      TT: 'App\\Models\\TreasuryTransfer',
      canOp,
      KINDS, STATUSES, TONES,
      transfers: new Pager(TRANSFERS),
      accounts: new Coll([BANK_MAIN, BANK_PAY, CASH, CLEARING, MOMO, TRANSIT, GATEWAY]),
      suggest: SUGGEST,
      cheques: new Coll(CHEQUES),
      threshold: 50000.0,
      feeHeads: [
        { id: 31, code: '5201', name: 'Bank charges' },
        { id: 32, code: '5202', name: 'Mobile money fees' },
      ],
      currentHotel: HOTEL,
    },
  };
}
