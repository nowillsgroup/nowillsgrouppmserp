/**
 * Sample data for the bank reconciliation previews.
 *
 * The sandbox has no PHP and no database, so these previews render the real
 * resources/views/bankrec/{index,show}.blade.php against this fixed sample, shaped the way
 * BankRecController hands them to the views: the statements and the reconciling items on the index,
 * and one draft statement with its lines, its uncleared book entries, the reconciling statement and
 * the reasons it cannot be finalised yet.
 *
 * The setting story: the brief promises auto-matching within ±0–31 days of the statement line, and
 * the reference pass used to ignore the window entirely — and the candidate pool ran back to the
 * account's first statement — so a line could be paired with a book entry a year earlier whose
 * reference merely repeated. Both now work to the same window, and the settings card says so.
 *
 * "Today" is 7 November 2026: the October statement on Ecobank – current is loaded, two lines are
 * still to match, and the same person who loaded it is looking at it.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T06:00:00Z');
const day = (iso) => new SampleDate(`${iso}T00:00:00Z`);

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const SOURCES = {
  front_desk: 'Front desk', pos: 'POS', ap: 'Payables', ar: 'Receivables', gl: 'General journal',
  treasury: 'Treasury', payroll: 'Payroll',
};

const person = (name) => ({ name });
const ACCOUNT = { id: 1, code: '1021', name: 'Ecobank – current', head: { system_key: 'bank_main' } };

const journal = (no, narration, reference = null) => ({
  journal_no: no, narration, reference, source_type: 'treasury', sourceUrl: () => `#gl-journal-${no}`,
});

const glLine = (id, date, debit, credit, jr) => ({ id, date: day(date), debit, credit, journal: jr });

const STATEMENT = {
  id: 41,
  statement_no: 'BRS-2026-10-1021',
  account: ACCOUNT,
  account_head_id: 1,
  status: 'draft',
  period_from: day('2026-10-01'),
  period_to: day('2026-10-31'),
  opening_balance: 1180420.5,
  closing_balance: 1284530.22,
  reconciled_at: null,
  reconciler: null,
  reopen_reason: null,
  needs_review: false,
  created_by: 7,
  isDraft: () => true,
  label: () => '01 Oct – 31 Oct 2026',
  sourceLabel: () => 'CSV upload · Ecobank statement 2026-10',
};

const LINE = (id, date, description, reference, amount, extra = {}) => ({
  id, date: day(date), description, reference, amount,
  status: 'unmatched', match_type: null, entry_kind: null, note: null, ignore_reason: null,
  glLines: new Coll([]), ...extra,
});

const LINES = [
  LINE(3101, '2026-10-02', 'Card settlement – 30 Sep takings', 'SETTLE-0930', 42180.4, {
    status: 'matched', match_type: 'group',
    glLines: new Coll([
      glLine(9001, '2026-09-30', 24180.4, 0, journal('GL-2026-09-0412', 'Card takings – restaurant', 'SETTLE-0930')),
      glLine(9002, '2026-09-30', 18000.0, 0, journal('GL-2026-09-0413', 'Card takings – rooms', 'SETTLE-0930')),
    ]),
  }),
  LINE(3102, '2026-10-06', 'Transfer from GCB payroll', 'TRF-2051', 120000.0, {
    status: 'matched', match_type: 'reference',
    glLines: new Coll([glLine(9040, '2026-10-05', 120000.0, 0, journal('GL-2026-10-0021', 'Treasury transfer TRF-2051', 'TRF-2051'))]),
  }),
  LINE(3103, '2026-10-14', 'Supplier payment – Sekondi Hardware', 'CHQ-004512', -18450.0, {
    status: 'matched', match_type: 'amount',
    glLines: new Coll([glLine(9099, '2026-10-13', 0, 18450.0, journal('GL-2026-10-0104', 'Cheque 004512 – Sekondi Hardware'))]),
  }),
  LINE(3104, '2026-10-22', 'Bank charges – October', 'FEE-1022', -186.5, {
    status: 'matched', match_type: 'manual', entry_kind: 'bank_charge',
    glLines: new Coll([glLine(9142, '2026-10-22', 0, 186.5, journal('GL-2026-10-0188', 'Bank charges October (booked from the statement)'))]),
  }),
  LINE(3105, '2026-10-28', 'Direct credit – Takoradi Chambers', 'DEP-7781', 12000.0),
  LINE(3106, '2026-10-30', 'Standing order – insurance', 'SO-3312', -3450.0,
    { note: 'Nothing in the books for this amount in October.' }),
  LINE(3107, '2026-10-31', 'Bank error – reversed 2 Nov', 'ERR-0091', -900.0,
    { status: 'ignored', ignore_reason: 'bank error – reversed by the bank on 2 Nov' }),
];

const OPEN = new Coll([
  glLine(9201, '2026-10-27', 12000.0, 0, journal('GL-2026-10-0203', 'City ledger – Takoradi Chambers, deposit', 'DEP-7781')),
  glLine(9202, '2026-10-31', 0, 3450.0, journal('GL-2026-11-0004', 'Insurance standing order', 'SO-3312')),
  glLine(9203, '2026-11-02', 8900.0, 0, journal('GL-2026-11-0011', 'POS takings banked 2 Nov')),
]);

const COUNTS = { matched: 4, unmatched: 2, ignored: 1, total: 7 };

const SUM = {
  counts: COUNTS,
  balances_ok: true,
  computed_closing: 1284530.22,
  lines_total: 104109.9,
  continuity_ok: true,
  difference: -6850.0,
  book: 1277680.22,
  closing: 1284530.22,
  uncleared_in: 20900.0,
  uncleared_out: -3450.0,
  bank_only: -900.0,
  adjusted: 1277680.22,
  opening_diff: 0.0,
  start: '2026-10-01',
  U: new Coll([]),
  S: new Coll([]),
};

export function bankrecContext({ segregation = true } = {}) {
  const blockers = [
    '2 statement line(s) still to match, book or ignore.',
    'Unexplained difference of GH₵ -6,850.00.',
  ];
  if (segregation) {
    blockers.push('You loaded this statement – someone else must finalise it.');
  }

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: value => (typeof value?.format === 'function' ? value.format('d M Y') : String(value ?? '—')),
      bdate: () => TODAY,
      status_badge: (status) => {
        const label = { draft: 'Draft', reconciled: 'Reconciled', reopened: 'Reopened' }[status] ?? status;
        const tone = { draft: 'amber', reconciled: 'green', reopened: 'red' }[status] ?? 'slate';
        return `<span class="badge badge-${tone}">${label}</span>`;
      },
      route: (name, params) => `#${String(name).replace(/\./g, '-')}${params ? '-' + (typeof params === 'object' ? (params.id ?? Object.values(params)[0] ?? '') : params) : ''}`,
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      old: (key, fallback = null) => fallback,
      request: (key) => (key === 'filter' ? null : null),
      setting: (key, fallback = null) => fallback,
      collect: value => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      count: value => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : 0),
      isset: value => value !== undefined && value !== null,
      e: value => String(value ?? ''),
      rtrim: (value, chars = ' ') => { let out = String(value ?? ''); while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1); return out; },
      trim: (value, chars = ' ') => String(value ?? '').replace(new RegExp(`^[${chars}]+|[${chars}]+$`, 'g'), ''),
      round: (value, places = 0) => { const f = 10 ** places; return Math.round(Number(value ?? 0) * f) / f; },
      abs: value => Math.abs(Number(value ?? 0)),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      implode: (separator, items) => (items instanceof Coll ? items.items : Array.isArray(items) ? items : Object.values(items ?? {})).join(separator),
      __const: (alias, name) => {
        const owner = String(alias).split('\\').pop();
        if (owner === 'GlJournal' && name === 'SOURCES') return SOURCES;
        if (owner === 'BankRecService' && name === 'ENTRY_KINDS') {
          return { bank_charge: ['Bank charge', 'bank_charge'], interest: ['Interest', 'interest'], other: ['Other', 'other'] };
        }
        return { SOURCES }[name] ?? {};
      },
      __static: (cls, method, ...args) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'Carbon' && method === 'parse') return new SampleDate(`${String(args[0]).slice(0, 10)}T09:00:00Z`);
        if (owner === 'Str' && method === 'limit') return String(args[0] ?? '').slice(0, Number(args[1] ?? 30));
        if (owner === 'BankRecService' && method === 'ENTRY_KINDS') return { bank_charge: ['Bank charge'] };
        return args[0] ?? '';
      },
      Str: { limit: (value, length = 30) => String(value ?? '').slice(0, length) },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      // top @php aliases the section render does not run
      draft: true,
      manage: true,
      c: COUNTS,
      kinds: { bank_charge: 'Bank charge', interest: 'Interest', other: 'Other' },
      typeLbl: { reference: 'auto · reference', amount: 'auto · amount', group: 'auto · batch', manual: 'by hand', entry: 'booked' },
      shown: new Coll(LINES),
      s: STATEMENT,
      sum: SUM,
      blockers,
      lines: new Coll(LINES),
      open: OPEN,
      filter: null,
      heads: { 21: '2010 Supplier payables', 22: '5201 Bank charges', 23: '4100 Other income' },
      isLatest: true,
      // the index screen
      accounts: new Coll([ACCOUNT, { id: 2, code: '1022', name: 'GCB – payroll' }, { id: 7, code: '1025', name: 'MTN MoMo – collections' }]),
      account: null,
      statements: new Coll([
        { id: 41, statement_no: 'BRS-2026-10-1021', account: ACCOUNT, label: () => '01 Oct – 31 Oct 2026', sourceLabel: () => 'CSV upload · Ecobank statement 2026-10', opening_balance: 1180420.5, closing_balance: 1284530.22, matched_count: 4, lines_count: 7, open_count: 2, status: 'draft', needs_review: false, reconciled_at: null, reconciler: null },
        { id: 40, statement_no: 'BRS-2026-09-1021', account: ACCOUNT, label: () => '01 Sep – 30 Sep 2026', sourceLabel: () => 'CSV upload · Ecobank statement 2026-09', opening_balance: 1104220.0, closing_balance: 1180420.5, matched_count: 41, lines_count: 41, open_count: 0, status: 'reconciled', needs_review: false, reconciled_at: day('2026-10-04'), reconciler: person('Kwesi Boateng') },
        { id: 39, statement_no: 'BRS-2026-08-1021', account: ACCOUNT, label: () => '01 Aug – 31 Aug 2026', sourceLabel: () => 'CSV upload · Ecobank statement 2026-08', opening_balance: 964880.0, closing_balance: 1104220.0, matched_count: 38, lines_count: 38, open_count: 0, status: 'reconciled', needs_review: true, reconciled_at: day('2026-09-03'), reconciler: person('Kwesi Boateng') },
      ]),
      cfg: {
        bankrec_window_days: '5',
        bankrec_stale_days: '35',
        bankrec_segregation: segregation ? '1' : '0',
      },
      currentHotel: HOTEL,
    },
  };
}
