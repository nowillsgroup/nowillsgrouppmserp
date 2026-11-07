import { phpEmpty } from './blade-render.mjs';

/**
 * Sample data for the Health Club Plans & Settings preview.
 *
 * The sandbox has no PHP and no database, so the preview renders the real
 * resources/views/club/plans.blade.php against this fixed sample. The plan list is
 * ClubService::DEFAULT_PLANS — the nine starter plans the club ships with — and the sixteen
 * settings are ClubService::SETTINGS read at their defaults, which is exactly what
 * ClubController::plans() passes to the view (`collect(ClubService::SETTINGS)->map(...)`).
 * Only the membership counts are invented; the prices, capacities and bands are the shipped ones.
 */
export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

/* ClubPlan::CATEGORIES / TERMS / FACILITIES, verbatim. */
const CATEGORIES = {
  individual: 'Individual', couple: 'Couple', family: 'Family',
  corporate: 'Corporate', student: 'Student', senior: 'Senior',
};
const TERMS = { 1: 'Monthly', 3: 'Quarterly', 6: 'Half-yearly', 12: 'Annual' };
const FACILITIES = {
  gym: 'Gym', pool: 'Swimming pool', sauna: 'Sauna & steam', tennis: 'Tennis court',
  squash: 'Squash court', kids: 'Kids club', classes: 'Group classes',
};

/* ClubService::SETTINGS — [label, default], in the order the screen prints them. */
const SETTINGS = {
  club_grace_days: ['Grace days before an unpaid charge blocks entry', 7],
  club_renew_days: ['Raise auto-renewal invoices this many days before expiry', 7],
  club_checkout_hours: ['Auto check-out visits left open after (hours)', 4],
  club_daypass_adult: ['Day pass – adult (GH₵, excl. tax)', 150],
  club_daypass_child: ['Day pass – child under 12 (GH₵, excl. tax)', 80],
  club_guestpass: ["Member's guest pass beyond the free allowance (GH₵, excl. tax)", 100],
  club_resident_free: ['In-house guests use the gym, pool and sauna free (1 = yes)', 1],
  club_class_cancel_hours: ['Late class cancellation counts as a no-show within (hours)', 2],
  club_noshow_limit: ['Class booking blocked after this many no-shows in 30 days', 3],
  club_cap_gym: ['Capacity – gym', 25],
  club_cap_pool: ['Capacity – swimming pool', 40],
  club_cap_sauna: ['Capacity – sauna & steam', 6],
  club_cap_tennis: ['Capacity – tennis court', 4],
  club_cap_squash: ['Capacity – squash court', 2],
  club_cap_kids: ['Capacity – kids club', 15],
  club_cap_classes: ['Capacity – studio (group classes)', 30],
};

/* ClubService::DEFAULT_PLANS — [code, name, category, term, price, joining, persons,
   facilities, from, to, guest passes, F&B %, spa %], plus live memberships and an on-sale flag. */
const PLANS = [
  ['GYM-M', 'Gym Monthly', 'individual', 1, 450, 200, 1, ['gym', 'classes'], null, null, 0, 0, 0, 38, true],
  ['FULL-M', 'Full Club Monthly', 'individual', 1, 650, 300, 1, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 2, 10, 10, 64, true],
  ['FULL-Q', 'Full Club Quarterly', 'individual', 3, 1800, 300, 1, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 2, 10, 10, 27, true],
  ['FULL-Y', 'Full Club Annual', 'individual', 12, 6500, 0, 1, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 4, 15, 15, 51, true],
  ['CPL-Y', 'Couple Annual', 'couple', 12, 11500, 0, 2, ['gym', 'pool', 'sauna', 'tennis', 'squash', 'classes'], null, null, 4, 15, 15, 19, true],
  ['FAM-Y', 'Family Annual', 'family', 12, 11000, 500, 4, ['gym', 'pool', 'sauna', 'tennis', 'kids', 'classes'], null, null, 4, 15, 10, 22, true],
  ['OFF-M', 'Off-Peak Monthly', 'individual', 1, 380, 150, 1, ['gym', 'pool'], '06:00:00', '16:00:00', 0, 0, 0, 31, true],
  ['STU-M', 'Student Monthly', 'student', 1, 300, 100, 1, ['gym', 'pool'], null, null, 0, 0, 0, 46, true],
  ['CORP-Y', 'Corporate Annual (per person)', 'corporate', 12, 5200, 0, 1, ['gym', 'pool', 'sauna', 'classes'], null, null, 2, 10, 10, 8, false],
];

function plan(row) {
  const [code, name, category, term, price, joining, persons, facilities, from, to, passes, fnb, spa, live, active] = row;
  return {
    code, name, category, term_months: term, price, joining_fee: joining, max_persons: persons,
    facilities, access_from: from, access_to: to, guest_passes: passes,
    fnb_discount_pct: fnb, spa_discount_pct: spa, freeze_days_max: term === 1 ? 10 : 30,
    description: null, is_active: active, live,
    // ClubPlan::termLabel() / hoursLabel() / facilityNames()
    termLabel: () => TERMS[term] ?? `${term} months`,
    hoursLabel: () => (from && to ? `${from.slice(0, 5)}–${to.slice(0, 5)}` : null),
    facilityNames: () => facilities.map(f => FACILITIES[f] ?? f).join(', '),
  };
}

export function clubPlansContext() {
  const plans = PLANS.map(plan);
  const settings = {};
  Object.entries(SETTINGS).forEach(([key, [label, value]]) => { settings[key] = { label, value }; });

  return {
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      route: name => (name === 'club.index' ? '#club' : name === 'club.billing' ? '#billing' : name === 'club.plans' ? '#plans' : '#'),
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: () => true,
      bdate: () => new Date('2026-11-05T09:00:00Z'),
      now: () => new Date('2026-11-05T09:00:00Z'),
      dmy: value => String(value ?? ''),
      setting: (key, fallback = null) => (SETTINGS[key] ? SETTINGS[key][1] : fallback),
      collect: value => value,
      count: value => (Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
      isset: (...values) => values.every(value => value !== undefined && value !== null),
      empty: phpEmpty,
      json_encode: value => JSON.stringify(value),
      in_array: (needle, haystack, strict = false) => {
        const list = Array.isArray(haystack) ? haystack : Object.values(haystack ?? {});
        return strict ? list.some(value => value === needle) : list.some(value => value == needle);
      },
      implode: (glue, list) => (Array.isArray(list) ? list : Object.values(list ?? {})).join(glue),
      round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
      max: (...values) => Math.max(...values.flat()),
      min: (...values) => Math.min(...values.flat()),
      ucfirst: value => String(value ?? '').charAt(0).toUpperCase() + String(value ?? '').slice(1),
      rtrim: (value, chars = ' ') => {
        let out = String(value ?? '');
        while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1);
        return out;
      },
      __static: (cls, method, ...args) => args[0] ?? '',
      __const: (cls, name) => {
        const owner = String(cls).split('\\').pop();
        if (owner === 'ClubPlan' && name === 'CATEGORIES') return CATEGORIES;
        if (owner === 'ClubPlan' && name === 'TERMS') return TERMS;
        if (owner === 'ClubPlan' && name === 'FACILITIES') return FACILITIES;
        return [];
      },
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
    fallbacks: {},
    scope: {
      plans,
      settings,
      // TaxService: VAT 15 + NHIL 2.5 + GETFund 2.5 + Tourism levy 1, charged on the net price.
      rate: 21,
      currentHotel: HOTEL,
    },
  };
}
