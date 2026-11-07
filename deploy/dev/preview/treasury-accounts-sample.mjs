/**
 * Sample data for the treasury bank-accounts preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/treasury/accounts.blade.php against this fixed sample, shaped the way
 * TreasuryController::accounts() hands it to the view: the cash and bank accounts with their ledger
 * codes and balances, the treasury settings card, and the IAS 7 cash-flow classification.
 *
 * The settings card is the point: the approval threshold and the banking float used to be the only
 * two money settings in Finance with no band printed anywhere, while the service clamps them at zero
 * and nothing else. They now carry 0–10,000,000 and 0–1,000,000, printed here as well as in the
 * brief and in the controller.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const ACCOUNT_KINDS = { bank: 'Bank account', momo: 'Mobile Money', cash: 'Cash box', clearing: 'Card clearing', transit: 'Cash in transit', petty: 'Petty cash', forex: 'Foreign cash', cheques: 'Cheques in hand' };
const ACCOUNT_TONES = { bank: 'blue', momo: 'purple', cash: 'green', clearing: 'amber', transit: 'slate', petty: 'slate', forex: 'blue', cheques: 'slate' };

const CF_CLASSES = { operating: 'Operating', investing: 'Investing', financing: 'Financing', cash: 'Cash & equivalents' };

const head = (id, code, name, group, cf = null) => ({ id, code, name, group_name: group, cf_class: cf, type: 'asset' });

const ACCOUNTS = [
  { id: 1, kind: 'bank', name: 'Ecobank – current', bank_name: 'Ecobank Ghana', branch: 'Takoradi', account_no: '1441002233', currency: 'GHS', min_balance: 25000, overdraft_limit: 150000, fee_rate: 0, settle_days: 0, is_active: true, in_position: true, notes: 'Main collections account', head: head(1, '1021', 'Ecobank – current', 'Cash & Bank') },
  { id: 2, kind: 'bank', name: 'GCB – payroll', bank_name: 'GCB Bank', branch: 'Sekondi', account_no: '3011188642', currency: 'GHS', min_balance: 120000, overdraft_limit: 0, fee_rate: 0, settle_days: 0, is_active: true, in_position: true, notes: 'Salaries and statutory payments', head: head(2, '1022', 'GCB – payroll', 'Cash & Bank') },
  { id: 3, kind: 'cash', name: 'Front office cash box', bank_name: '', branch: '', account_no: '', currency: 'GHS', min_balance: 0, overdraft_limit: 0, fee_rate: 0, settle_days: 0, is_active: true, in_position: true, notes: '', head: head(3, '1010', 'Cash in hand', 'Cash & Bank') },
  { id: 6, kind: 'clearing', name: 'Ecobank – card settlement', bank_name: 'Ecobank Ghana', branch: 'Takoradi', account_no: '1441009900', currency: 'GHS', min_balance: 0, overdraft_limit: 0, fee_rate: 1.75, settle_days: 2, is_active: true, in_position: false, notes: 'Card acquirer settlement account', head: head(6, '1031', 'Card clearing', 'Cash & Bank') },
  { id: 7, kind: 'momo', name: 'MTN MoMo – collections', bank_name: 'MTN Ghana', branch: '', account_no: '0244118823', currency: 'GHS', min_balance: 0, overdraft_limit: 0, fee_rate: 1.0, settle_days: 3, is_active: true, in_position: true, notes: '', head: head(7, '1025', 'MTN MoMo – collections', 'Cash & Bank') },
  { id: 9, kind: 'transit', name: 'Cash in transit', bank_name: '', branch: '', account_no: '', currency: 'GHS', min_balance: 0, overdraft_limit: 0, fee_rate: 0, settle_days: 0, is_active: true, in_position: true, notes: '', head: head(9, '1015', 'Cash in transit', 'Cash & Bank') },
  { id: 11, kind: 'petty', name: 'Petty cash – housekeeping', bank_name: '', branch: '', account_no: '', currency: 'GHS', min_balance: 0, overdraft_limit: 0, fee_rate: 0, settle_days: 0, is_active: false, in_position: true, notes: 'Closed when the imprest moved to stores', head: head(11, '1018', 'Petty cash', 'Petty Cash') },
];

const HEADS = [
  head(1, '1021', 'Ecobank – current', 'Cash & Bank', 'cash'),
  head(2, '1022', 'GCB – payroll', 'Cash & Bank', 'cash'),
  head(3, '1010', 'Cash in hand', 'Cash & Bank', 'cash'),
  head(6, '1031', 'Card clearing', 'Cash & Bank', 'cash'),
  head(9, '1015', 'Cash in transit', 'Cash & Bank', 'cash'),
  head(21, '2010', 'Supplier payables', 'Current Liabilities', 'operating'),
  head(22, '2110', 'Bank loan – GCB', 'Long Term Liabilities', 'financing'),
  head(23, '1210', 'Kitchen equipment', 'Fixed Assets', 'investing'),
];

const BALANCES = { 1: 1284530.22, 2: 341880.5, 3: 5043.75, 6: 18240.5, 7: 4610.0, 9: 19600.0, 11: 0 };

export function treasuryAccountsContext({ canManage = true } = {}) {
  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (typeof value?.format === 'function' ? value.format('d M Y') : String(value ?? '—')),
      bdate: () => TODAY,
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? (params.id ?? '') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => canManage,
      old: (key, fallback = null) => fallback,
      request: () => null,
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? ''),
      rtrim: (value, chars = ' ') => { let out = String(value ?? ''); while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1); return out; },
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      __const: (alias, name) => {
        const owner = String(alias).split('\\').pop();
        if (owner === 'TreasuryAccount') return { KINDS: ACCOUNT_KINDS, TONES: ACCOUNT_TONES }[name] ?? {};
        if (owner === 'TreasuryService') return name === 'CF_CLASSES' ? CF_CLASSES : {};
        return { CF_CLASSES, KINDS: ACCOUNT_KINDS, TONES: ACCOUNT_TONES }[name] ?? {};
      },
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        // TreasuryService::autoClass() derives the IAS 7 class from the account's own code group.
        if (owner === 'TreasuryService' && method === 'autoClass') return args[0]?.cf_class ?? 'operating';
        return args[0] ?? '';
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      // top @php block aliases; a section render needs the names supplied
      TA: 'App\\Models\\TreasuryAccount',
      TS: 'App\\Services\\TreasuryService',
      canManage,
      accounts: new Coll(ACCOUNTS.map(a => ({ ...a, maskedNo: () => (a.account_no ? '•••• ' + a.account_no.slice(-4) : '—') }))),
      pos: {
        as_of: '2026-11-07',
        rows: new Coll(ACCOUNTS.map(a => ({ account: a, balance: BALANCES[a.id] ?? 0, below_min: (BALANCES[a.id] ?? 0) < a.min_balance, overdrawn: false, unpresented: 0 }))),
        cash: 1689304.47, banks: 1433549.27, on_hand: 24643.75, transit: 19600, clearing: 22850.5, unpresented: 12000, alerts: new Coll([]),
      },
      bal: BALANCES,
      heads: new Coll(HEADS),
      cfg: {
        treasury_approval_threshold: '50000', treasury_cash_float: '5000', treasury_payday: '28',
        treasury_collect_pct: '85', treasury_arrival_pct: '90', treasury_clear_days: '2',
      },
      currentHotel: HOTEL,
    },
  };
}
