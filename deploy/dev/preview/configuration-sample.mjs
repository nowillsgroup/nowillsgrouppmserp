/**
 * Sample data for the Settings › Configuration previews.
 *
 * The register itself is not invented here: this module reads config/settings.php —
 * the same file the application loads — and renders the real module list, the real
 * setting keys and the real briefs. Only the *values* are a fixture (there is no PHP
 * in the preview sandbox), so a screen that claims to show "every module's settings"
 * cannot quietly drift from the registry the application ships.
 *
 * Shapes mirror ConfigurationService::modules()/coverage()/grouped() so the screens
 * compose exactly as they do in the application.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const at = (iso) => new SampleDate(iso.includes('T') ? iso : `${iso}T08:00:00Z`);
const BUSINESS_DAY = '2026-11-05';

// Helpers the views reach for. The sandbox has no PHP, so the same surface the
// briefing/laundry samples expose is provided here.
const HELPERS = {
  route: () => '#',
  asset: (p) => `/${String(p ?? '').replace(/^\/+/, '')}`,
  old: (key, fallback = null) => fallback,
  count: (v) => (v instanceof Coll ? v.count() : Array.isArray(v) ? v.length : v && typeof v === 'object' ? Object.keys(v).length : 0),
  empty: phpEmpty,
  in_array: (needle, hay) => (hay instanceof Coll ? hay.items : hay ?? []).includes(needle),
  array_values: (v) => (Array.isArray(v) ? v.slice() : Object.values(v ?? {})),
  implode: (glue, v) => (v instanceof Coll ? v.items : v ?? []).join(glue),
  number_format: (v, d = 0) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }),
  money: (amount, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(amount ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
  ucfirst: (v) => { const t = String(v ?? ''); return t.charAt(0).toUpperCase() + t.slice(1); },
  can_do: () => true,
  setting: (_k, d = null) => d,
  bdate: () => at('2026-11-05T00:00:00Z'),
  dmy: () => '05 Nov 2026',
  now: () => at('2026-11-05T08:10:00Z'),
  hotel: () => HOTEL,
  csrf_field: () => '',
  csrf_token: () => 'csrf',
  status_badge: () => '',
};

// ── a small PHP array reader for the registry file ────────────────────────────────────
// config/settings.php is machine-emitted with a fixed shape (see the generator note in
// its header), so a token walk is enough — and it is the real file, not a copy.
function phpStrings(src) {
  const out = [];
  for (let i = 0; i < src.length; i += 1) {
    if (src[i] !== "'") continue;
    let s = '';
    i += 1;
    while (i < src.length) {
      if (src[i] === '\\') { s += src[i + 1]; i += 2; continue; }
      if (src[i] === "'") break;
      s += src[i];
      i += 1;
    }
    out.push({ value: s, end: i });
  }
  return out;
}

export function parseRegistry(file) {
  const src = fs.readFileSync(file, 'utf8');
  const body = src.slice(src.indexOf("'modules' => ["));
  const modules = [];
  // A module block starts at eight spaces with `'key' => [`. Anchor on the start lines
  // rather than on `=> [\n…],` — the `'modules' => [` wrapper opens the map and would
  // otherwise swallow the first module (property) inside itself.
  const starts = [...body.matchAll(/^        '([a-z0-9_]+)' => \[$/gm)];
  for (let n = 0; n < starts.length; n += 1) {
    const key = starts[n][1];
    const block = body.slice(starts[n].index, n + 1 < starts.length ? starts[n + 1].index : body.length);
    const str = (name) => {
      const mm = block.match(new RegExp(`'${name}' => '((?:[^'\\\\]|\\\\.)*)'`));
      return mm ? mm[1].replace(/\\(.)/g, '$1') : '';
    };
    const route = block.match(/'route' => '([^']+)'/);
    const perm = block.match(/'perm' => '([^']+)'/);
    const edit = block.match(/'edit' => '([^']+)'/);
    const keys = [];
    const kblock = block.slice(block.indexOf("'keys' => ["));
    const kr = /'([a-z0-9_]+)' => '((?:[^'\\]|\\.)*)'/g;
    let k;
    while ((k = kr.exec(kblock)) !== null) keys.push([k[1], k[2].replace(/\\(.)/g, '$1')]);
    // `'secret' => ['a', 'b']` — the credential keys of this module. Their stored value is
    // encrypted at rest and must never reach a screen, so the fixture never carries one.
    const secret = block.match(/'secret' => \[([^\]]*)\]/);
    const secretKeys = secret ? [...secret[1].matchAll(/'([a-z0-9_]+)'/g)].map((h) => h[1]) : [];
    modules.push({
      key, label: str('label'), group: str('group'), brief: str('brief'),
      route: route ? route[1] : null, perm: perm ? perm[1] : null,
      edit: edit ? edit[1] : (perm ? perm[1] : null), keys, secretKeys,
    });
  }
  const groups = [...src.matchAll(/^        '([A-Za-z &]+)',$/gm)].map((g) => g[1]);

  return { modules, groups: groups.slice(0, 11) };
}

// ── the value fixture: what this property has actually set ────────────────────────────
const SET = {
  hotel_name: 'The Grove Essipong Beach Resort',
  api_allow_http: '1', // sample finding: plain HTTP endpoints are permitted
  booking_engine_enabled: '1',
  booking_policy: 'Free cancellation until 48 hours before check-in. After that the first night is charged.',
  cancel_free_hours: '48',
  email_auto_confirm: '1',
  flash_report_emails: 'gm@groveessipong.com, owner@groveessipong.com',
  grc_terms: 'The guest is liable for damage to the room beyond fair wear and tear.',
  invoice_terms: 'Payment due within 30 days. VAT charged where applicable.',
  noshow_fee_auto: '1',
  online_checkin_enabled: '1',
  require_2fa_admins: '1',
  tax_prices_inclusive: '1',
  hk_checklist: 'Strip and remake the bed\nBathroom: shower, basin, mirror\nReplace towels\nRestock minibar and amenities',
  hk_checklist_required: '1',
  hk_require_inspection: '1',
  hk_credits_departure: '2',
  hk_credits_stayover: '1',
  hk_credits_deep: '3',
  minibar_store_id: '4',
  pos_kot_print_mode: 'split',
  pos_kot_captain: '1',
  qr_menu_enabled: '1',
  banquet_deposit_pct: '25',
  banquet_guarantee_days: '7',
  banquet_half_day_hours: '4',
  banquet_hall_tax_rate: '0',
  banquet_turnaround_minutes: '120',
  banquet_terms: 'The client guarantees the covers seven days before the event.',
  rm_mode: 'recommend',
  rm_round_to: '50',
  rm_horizon_days: '90',
  parking_hour_rate: '5.00',
  parking_guest_rate: '0',
  parking_valet_rate: '20.00',
  parking_valet_fee: '20.00',
  parking_daily_max: '40.00',
  parking_free_minutes: '15',
  parking_full_pct: '90',
  parking_overstay_hours: '4',
  parking_lost_fee: '30.00',
  parking_sla_minutes: '8',
  parking_validation_max: '120',
  parking_audit_post: '1',
  payroll_payday: '25',
  payroll_ot_multiplier: '1.5',
  payroll_holiday_multiplier: '2',
  payroll_shift_hours: '8',
  payroll_segregation: '1',
  payroll_notify: 'gm@groveessipong.com',
  roster_week_hours: '40',
  roster_max_days: '6',
  roster_min_rest_hours: '12',
  timeclock_grace_min: '5',
  timeclock_break_min: '60',
  timeclock_self: '1',
  gst_einvoice_enabled: '1',
  gst_evat_url: 'https://vsdc.gra.gov.gh/api',
  gst_evat_username: 'GROVE001',
  gst_einvoice_auto: '1',
  gl_cutoff: '06:00',
  gl_lock_date: '2026-10-31',
  ap_tolerance_pct: '1',
  ap_tolerance_abs: '1',
  ap_segregation: '1',
  loyalty_enabled: '1',
  loyalty_spend_per_point: '100',
  loyalty_point_value: '1',
  loyalty_min_redeem: '100',
  portal_enabled: '1',
  portal_days_after: '2',
  portal_sla_minutes: '15',
  portal_spa_enabled: '1',
  portal_wifi_name: 'Grove-Guest',
  portal_info: 'Breakfast: 06:30 – 10:30, Garden Restaurant\nPool: 07:00 – 20:00\nFront desk: dial 9',
  sms_enabled: '1',
  sms_driver: 'hubtel',
  sms_hubtel_client_id: 'grove-hubtel',
  sms_sender: 'GROVE',
  sms_default_country_code: '233',
  sms_pre_arrival_days: '2',
  sms_auto_confirmation: '1',
  sms_auto_thankyou: '1',
  pay_enabled: '1',
  pay_provider: 'paystack',
  pay_link_hours: '24',
  pay_booking_deposit: '1',
  pay_booking_deposit_pct: '30',
  pay_booking_hold_minutes: '20',
  fb_owners: 'gm@groveessipong.com, roberta@groveessipong.com',
  fb_sla_inhouse: '15',
  fb_goodwill_max: '500',
  fb_alert_rating: '3',
  fb_review_approval: '1',
  fb_signature: 'Guest Relations, The Grove Essipong',
  fb_review_link_google: 'https://g.page/r/grove-essipong',
  po_limit_1: '5000',
  po_limit_2: '25000',
  po_price_tolerance_pct: '2',
  po_over_receipt_pct: '5',
  req_auto_approve_below: '1000',
  rfq_min_quotes: '3',
  spa_open: '09:00',
  spa_close: '21:00',
  spa_slot_min: '30',
  laundry_express_pct: '50',
  recreation_shift_required: '1',
  talent_offer_days: '7',
  talent_probation_months: '3',
  qa_pass_mark: '80',
  qa_target_score: '90',
  hse_drill_days: '90',
  esg_green_enabled: '1',
  lease_grace_days: '5',
  contract_expiry_alert_days: '60',
  privacy_ready: '1',
  timeclock_self_ips: '41.66.0.0/16',
};

// modules that were changed in the last few days, as the audit trail shows them
const LAST = {
  banquet: ['2026-11-04T16:20:00Z', 'Yaw Antwi', 'Updated banquet settings'],
  parking: ['2026-11-03T09:05:00Z', 'Nana Ama Owusu', 'Updated parking tariffs and the valet promise'],
  payroll: ['2026-11-02T11:40:00Z', 'Adjoa Sarpong', 'Updated payroll settings'],
  property: ['2026-11-01T07:30:00Z', 'Daniel Ofori', 'Updated hotel settings'],
};

const shown = (v) => {
  const s = String(v).trim();
  if (s === '') return '—';
  if (s === '1' || s === '0') return s === '1' ? 'On' : 'Off';
  const flat = s.replace(/\s+/g, ' ');
  return flat.length > 80 ? `${flat.slice(0, 77)}…` : flat;
};

/** app/Support/ConfigurationChecks.php — which module owns each contradiction rule. */
function parseRuleModules() {
  const file = path.join(ROOT, 'app/Support/ConfigurationChecks.php');
  if (!fs.existsSync(file)) return {};
  const src = fs.readFileSync(file, 'utf8');
  const start = src.indexOf('public const MODULES = [');
  if (start < 0) return {};
  const block = src.slice(start, src.indexOf('];', start));
  const out = {};
  for (const hit of block.matchAll(/'([a-z0-9_]+)' => '([a-z0-9_]+)'/g)) out[hit[1]] = hit[2];
  return out;
}

/** config/setting-readers.php — where each key is read, exactly as the service reads it. */
export function parseReaders() {
  const file = path.join(ROOT, 'config/setting-readers.php');
  if (!fs.existsSync(file)) return { readers: {}, families: {}, accessors: {}, map: {}, counts: {} };
  const src = fs.readFileSync(file, 'utf8');
  const block = (name) => {
    let start = src.indexOf(`'${name}' => [`);
    if (start < 0) return '';
    start = src.indexOf('\n', start); // past the section header, which is not an entry
    const end = src.indexOf('\n    ],', start);
    return src.slice(start, end < 0 ? src.length : end);
  };
  const map = (name) => {
    const out = {};
    for (const hit of block(name).matchAll(/'([a-z0-9_]+)' => \[([^\]]*)\]/g)) {
      out[hit[1]] = [...hit[2].matchAll(/'([^']+)'/g)].map((m) => m[1]);
    }
    return out;
  };
  const counts = {};
  for (const hit of src.matchAll(/'([a-z_]+)' => (\d+),/g)) counts[hit[1]] = Number(hit[2]);
  return { readers: map('readers'), families: map('families'), accessors: map('accessors'), map: map('map'), counts };
}

/** config/setting-defaults.php — the built-in default of each key, exactly as the service reads it. */
export function parseDefaults() {
  const file = path.join(ROOT, 'config/setting-defaults.php');
  if (!fs.existsSync(file)) return {};
  const src = fs.readFileSync(file, 'utf8');
  const body = src.slice(src.indexOf("'defaults' => ["), src.indexOf("'resolved_from_array'"));
  const out = {};
  for (const line of body.split('\n')) {
    const hit = line.match(/^\s*'([a-z0-9_]+)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")\s*,?\s*$/);
    if (!hit) continue;
    const raw = hit[2] !== undefined ? hit[2].replace(/\\'/g, "'") : hit[3].replace(/\\(.)/g, (all, ch) => ({ n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\' }[ch] ?? ch));
    out[hit[1]] = raw;
  }
  return out;
}

const DEFAULTS = parseDefaults();

// config/setting-fallbacks.php — what the code does when a setting has no built-in
// default at all: the literal at its read site, on the service side and on the page side.
// It is generated by deploy/dev/build-setting-fallbacks.mjs; the audit proves it is in
// step with the code, so the preview cannot show a fallback the application does not have.
export function parseFallbacks() {
  const file = path.join(ROOT, 'config/setting-fallbacks.php');
  const empty = { fallbacks: {}, conflicting: {}, keys: [] };
  if (!fs.existsSync(file)) return empty;
  const src = fs.readFileSync(file, 'utf8');
  const body = src.slice(src.indexOf("'fallbacks' => ["), src.indexOf("'conflicting' => ["));
  const fallbacks = {};
  for (const line of body.split('\n')) {
    // one row: 'key' => ['module' => 'ap', 'service' => '1', 'view' => null, …],
    const row = line.match(/^\s*'([a-z0-9_]+)' => \[(.*)\],\s*$/);
    if (!row) continue;
    const entry = {};
    for (const pair of row[2].matchAll(/'([a-z_]+)' => (null|'(?:[^'\\]|\\.)*')/g)) {
      entry[pair[1]] = pair[2] === 'null' ? null : pair[2].slice(1, -1).replace(/\\'/g, "'");
    }
    fallbacks[row[1]] = entry;
  }
  const cbody = src.slice(src.indexOf("'conflicting' => ["), src.indexOf("'keys' => ["));
  const conflicting = {};
  for (const line of cbody.split('\n')) {
    const hit = line.match(/^\s*'([a-z0-9_]+)' => \[(.*)\],?$/);
    if (hit) conflicting[hit[1]] = [...hit[2].matchAll(/'([^']*)'/g)].map((m) => m[1]);
  }
  const kbody = src.slice(src.indexOf("'keys' => ["));
  const keys = [...kbody.matchAll(/^\s+'([a-z0-9_]+)',$/gm)].map((m) => m[1]);
  return { fallbacks, conflicting, keys };
}

const FALLBACKS = parseFallbacks();

/** ConfigurationService::unsetShow() — the sentence the screen prints for an unset value. */
function unsetShow(key) {
  const f = FALLBACKS.fallbacks[key];
  if (!f) return null;
  const base = (p) => (p ? path.basename(p, '.php') : null);
  const where = base(f.service_file);
  const page = base(f.view_file);
  if (f.service !== null) {
    let text = `runs on ${shown(f.service)}${where ? ` — written in ${where}` : ''}`;
    if (f.view !== null && f.view !== f.service) text += ` (a page prints ${shown(f.view)})`;
    return text;
  }
  if (f.view !== null) return `the service sees null; a page prints ${shown(f.view)}${page ? ` — ${page}` : ''}`;
  return `nothing — the service reads it bare${where ? ` in ${where}` : ''}`;
}

/** Two values that are the same number are not a change: "250" and "250.00" are one price. */
function sameNumber(a, b) {
  const x = String(a).trim();
  const y = String(b).trim();
  if (x === y) return true;
  return x !== '' && y !== '' && !Number.isNaN(Number(x)) && !Number.isNaN(Number(y)) && Math.abs(Number(x) - Number(y)) < 0.000001;
}

// ── the credentials this property has stored ─────────────────────────────────────────
// A credential is encrypted at rest (MessageService::storeSecret) and the register prints
// only whether one is set. The ciphertext below is the whole point of the fixture: it must
// never appear in a built screen, and configuration_preview_check.js greps the output for it.
const HIDDEN = 'Set — hidden';
const CIPHERTEXT = 'eyJpdiI6IkZBS0UtQ0lQSEVSVEVYVCIsInZhbHVlIjoiZmFrZSIsIm1hYyI6ImZha2UifQ==';
const SECRETS_SET = {
  gst_evat_key: 'evat-live-7f3c9a12',            // stored in the clear by the e-VAT screen, still a credential
  inbox_webhook_secret: CIPHERTEXT,
  pay_paystack_secret: CIPHERTEXT,
  pay_stripe_secret: CIPHERTEXT,
  pbx_token: 'pbx-0c41b7',
  portal_wifi_password: 'Grove-Guest-2026',
  robita_webhook_token: 'whk_9d21c4',
  sms_hubtel_client_secret: CIPHERTEXT,
  sms_twilio_token: CIPHERTEXT,
};

function buildModules() {
  const { modules, groups } = parseRegistry(path.join(ROOT, 'config/settings.php'));
  const READ = parseReaders();
  const RULE_MODULES = parseRuleModules();
  const rulesOf = (module) => Object.values(RULE_MODULES).filter((m) => m === module).length;
  const readBy = (key) => {
    const files = key.endsWith('_')
      ? (READ.families[key] ?? [])
      : (READ.readers[key] ?? READ.accessors[key] ?? []);
    const names = files.map((f) => path.basename(f, '.php'));
    return {
      readers: files.length,
      read_in: files.length === 0 ? '—' : (files.length > 2 ? `${files.length} files` : names.join(', ')),
      read_paths: files.join(' · '),
    };
  };
  const out = modules.map((m) => {
    const keys = m.keys.map(([key, label]) => {
      const family = key.endsWith('_');
      const secret = m.secretKeys.includes(key);
      const v = family || secret ? null : SET[key];
      const set = secret
        ? String(SECRETS_SET[key] ?? '').trim() !== ''
        : v !== undefined && v !== null && String(v).trim() !== '';
      if (secret) {
        // the same masking the service does — the value is never shaped, shortened or exported
        return {
          key, raw: key, label, family: false, module: m.key, secret: true,
          value: set ? HIDDEN : null, set, state: set ? 'set' : 'default', count: 1,
          default: null, default_show: null, changed: false, ...readBy(key),
        };
      }
      const def = family ? null : (Object.prototype.hasOwnProperty.call(DEFAULTS, key) ? DEFAULTS[key] : null);
      const changed = set && def !== null && !sameNumber(String(v), def);
      return {
        key: family ? `${key}…` : key, raw: key, label, family, module: m.key, secret: false,
        value: set ? shown(v) : null, set, state: set ? 'set' : 'default', count: 1,
        default: def, default_show: def === null ? null : shown(def), changed,
        unset_show: def === null ? unsetShow(key) : null, ...readBy(key),
      };
    });
    const set = keys.filter((k) => k.set).length;
    const changed = keys.filter((k) => k.changed).length;
    const known = keys.filter((k) => k.default !== null).length;
    const secrets = keys.filter((k) => k.secret).length;
    const last = LAST[m.key];
    return {
      key: m.key, label: m.label, group: m.group, brief: m.brief, route: m.route, perm: m.perm,
      keys, total: keys.length, set, missing: keys.length - set, changed, defaults_known: known, secrets,
      rules: rulesOf(m.key),
      edit: m.edit,
      last: last ? { at: at(last[0]), by: last[1], what: last[2] } : null,
      open: Boolean(m.route), reachable: Boolean(m.route) && m.key !== 'payroll',
    };
  });

  return { modules: out, groups };
}

function groupList(modules, groups) {
  const out = {};
  for (const g of groups) {
    const inGroup = modules.filter((m) => m.group === g);
    if (inGroup.length) out[g] = inGroup;
  }
  return out;
}

function coverageOf(modules) {
  return {
    modules: modules.length,
    screens: modules.filter((m) => m.open).length,
    gaps: modules.filter((m) => !m.open).length,
    keys: modules.reduce((n, m) => n + m.total, 0),
    set: modules.reduce((n, m) => n + m.set, 0),
    unset: modules.reduce((n, m) => n + m.total - m.set, 0),
    changed: modules.reduce((n, m) => n + m.changed, 0),
    defaults_known: modules.reduce((n, m) => n + m.defaults_known, 0),
    on_null: FALLBACKS.keys.length,
    guarded: modules.filter((m) => m.rules > 0).length,
    groups: new Set(modules.map((m) => m.group)).size,
  };
}

// ── what the fixture property's settings contradict ─────────────────────────────────
// The ids are the rule ids in App/Support/ConfigurationChecks.php; deploy/dev/audit/
// configuration_check.py proves the two lists are identical, so a rule can never exist
// in the code and be missing here (or the reverse).
export const ADVISORY_IDS = [
  'vat_without_einvoicing', 'einvoicing_incomplete', 'inclusive_prices_unregistered',
  'payments_on_test_gateway', 'payments_without_credentials',
  'portal_link_without_sms', 'portal_dining_unrouted', 'portal_wifi_missing',
  'sms_without_sender',
  'loyalty_points_worth_nothing', 'loyalty_earning_fallback',
  'credit_hold_no_grace', 'cashier_shift_optional', 'admin_2fa_optional',
  'parking_posts_nothing',
  'checklist_without_inspection',
  'booking_engine_without_policy',
  // round 9 added these: the gateway that only writes to the log, a gateway with no
  // credentials, WhatsApp on a driver that cannot carry it, a spa with no slot, a target
  // under the pass mark, stay-over work worth nothing, a tax-free banquet hall, and a
  // portal offering a spa that cannot be booked.
  'sms_on_the_log_driver', 'sms_without_gateway_credentials', 'whatsapp_on_a_driver_that_cannot_send_it',
  'spa_never_open', 'portal_spa_without_slots', 'qa_target_below_pass_mark', 'stayover_without_credit',
  'banquet_hall_untaxed',
  // round 15 widened the reach from 13 modules to 18: an automation under a switch that is
  // off, a second approval tier nobody can reach, a tolerance of zero, unmarked days paid as
  // worked, a rest minimum that can never warn, and a ledger locked past the business date.
  'sms_automations_without_sms', 'no_second_approval_tier', 'payables_tolerance_is_zero',
  'payroll_unmarked_days_are_paid', 'roster_min_rest_too_short', 'locked_period_ahead_of_business_date',
  // round 16: the checks reach six modules nobody had looked at — clock, close, rentals, fx, rep, recreation
  'self_clock_from_anywhere', 'close_starts_after_today', 'excess_mileage_is_free', 'conversions_without_forex', 'surveys_without_cooldown', 'recreation_sales_without_a_shift',
  // round 17: six more — crm, digital, fs, leases, esg, partner
  'campaign_revenue_expires_the_day_it_is_sent', 'listings_are_always_overdue_for_audit', 'certificate_warning_arrives_on_the_last_day', 'rent_cap_not_enforced', 'emissions_approved_by_their_own_author', 'portal_switches_on_a_closed_portal',
  'cro_follow_up_after_expiry', 'fleet_speed_marked_parked', 'inbox_chat_under_disabled_inbox', 'tv_rental_limit_zero',
  'api_http_endpoints_allowed', 'api_private_endpoints_allowed', 'pbx_tcp_endpoint_missing', 'lock_tcp_endpoint_missing',
  'kiosk_walkin_without_payment', 'organiser_pay_without_gateway', 'upsell_email_with_upsell_off', 'bankrec_same_person_finalises', 'income_audit_four_eyes_off', 'hse_permit_self_issue', 'privacy_scheduled_retention_off', 'formc_no_advance_alert', 'robita_no_auto_revoke', 'budget_capex_no_prefix', 'voucher_min_above_max', 'sales_stage_probabilities_unordered',
];

// The six findings this property actually carries, as ConfigurationService::advisories()
// returns them: decorated with the module's label and sorted blockers first.
const FINDINGS = [
  {
    id: 'bankrec_same_person_finalises', severity: 'note', module: 'bankrec',
    title: 'Bank statements do not require a different finaliser',
    detail: 'The statement loader is not prevented by the segregation setting from finalising their own reconciliation. Other reconciliation checks and permissions still apply.',
    keys: ['bankrec_segregation'],
  },
  {
    id: 'api_http_endpoints_allowed', severity: 'note', module: 'developers',
    title: 'Plain HTTP webhook endpoints are allowed',
    detail: 'The webhook URL and delivery checks permit unencrypted HTTP while this option is on. Review it for the property; the setting alone does not mean an HTTP endpoint exists.',
    keys: ['api_allow_http'],
  },
  {
    id: 'payments_on_test_gateway', severity: 'blocker', module: 'payments',
    title: 'Online payment is switched on, but on the built-in test gateway',
    detail: 'No real money is collected: a guest can pay a deposit or a balance and the property receives nothing. Choose the live provider and store its keys.',
    keys: ['pay_enabled', 'pay_provider'],
  },
  {
    id: 'loyalty_points_worth_nothing', severity: 'blocker', module: 'loyalty',
    title: 'Guests earn loyalty points that are worth nothing',
    detail: 'Points accrue at settlement and can be redeemed, but a point redeems for zero — the programme promises a benefit it never pays.',
    keys: ['loyalty_enabled', 'loyalty_point_value'],
  },
  {
    id: 'portal_dining_unrouted', severity: 'warning', module: 'portal',
    title: 'The portal offers in-room dining with nowhere to send it',
    detail: 'No outlet is chosen for portal dining orders, so a guest can order and the kitchen never receives it.',
    keys: ['portal_dining_outlet_id'],
  },
  {
    id: 'credit_hold_no_grace', severity: 'warning', module: 'credit',
    title: 'City ledger accounts are held the moment they fall overdue',
    detail: 'With no grace period a good payer is frozen on the first day past terms, which usually costs more in goodwill than the exposure it saves.',
    keys: ['credit_auto_hold', 'credit_hold_grace_days'],
  },
  {
    id: 'banquet_hall_untaxed', severity: 'warning', module: 'banquet',
    title: 'Hall hire is charged with no tax at a VAT-registered property',
    detail: 'Every banquet function and cancellation fee is posted at a zero tax rate, so venue income leaves the property untaxed while the rest of the folio is charged VAT.',
    keys: ['banquet_hall_tax_rate'],
  },
  {
    id: 'sms_automations_without_sms', severity: 'warning', module: 'messages',
    title: 'Automatic texts are switched on underneath an SMS switch that is off',
    detail: 'The confirmation and thank-you toggles read "on", but the code that sends them checks the SMS switch first and stops. Guests are never texted, and nothing on the screen says so.',
    keys: ['sms_enabled', 'sms_auto_confirmation', 'sms_auto_thankyou'],
  },
  {
    id: 'no_second_approval_tier', severity: 'warning', module: 'proc',
    title: 'The second approval tier sits at or below the first, so nobody ever reaches it',
    detail: 'A purchase order up to the first limit is approved by the store; everything above it needs the high approver. The middle tier never decides anything, so the named approver is not the check the policy describes.',
    keys: ['po_limit_1', 'po_limit_2'],
  },
  {
    id: 'payables_tolerance_is_zero', severity: 'note', module: 'ap',
    title: 'No difference between a bill and the goods received is tolerated',
    detail: 'A supplier bill is held for any difference at all, down to a pesewa of rounding. Most properties keep one direction a little slack to avoid holding every invoice by hand.',
    keys: ['ap_tolerance_pct', 'ap_tolerance_abs'],
  },
  {
    id: 'payroll_unmarked_days_are_paid', severity: 'note', module: 'payroll',
    title: 'A day nobody marked is paid as a day worked',
    detail: 'When the time register is not filled in, the day is paid as present rather than held as absent. That is a policy, not an error — but it is the difference between a payroll run nobody signed and one somebody did.',
    keys: ['payroll_unmarked'],
  },
  {
    id: 'roster_min_rest_too_short', severity: 'warning', module: 'roster',
    title: 'The minimum rest between shifts is below what the roster check calls a warning',
    detail: 'The roster only warns when the rest between two shifts falls under this number — at a few hours it never warns, and a close followed by an early start is published as fine.',
    keys: ['roster_min_rest_hours'],
  },
  {
    id: 'locked_period_ahead_of_business_date', severity: 'warning', module: 'gl',
    title: 'The ledger is locked past the business date, so today cannot be posted',
    detail: 'A journal dated on or before the lock date is moved to the first open day, and payroll refuses it outright. Every posting made today lands on a future date, which the close will disagree with.',
    keys: ['gl_lock_date'],
  },
  {
    id: 'self_clock_from_anywhere', severity: 'warning', module: 'clock',
    title: 'Staff can clock in from any address, not only from the property',
    detail: 'The self-service clock reads an empty address list as "anywhere", so a phone on any network can start a shift. That is the difference between a time record and a claim.',
    keys: ['timeclock_self', 'timeclock_self_ips'],
  },
  {
    id: 'close_starts_after_today', severity: 'warning', module: 'close',
    title: 'The period close starts at a month the ledger has not reached',
    detail: 'The close manages the months from this one forward, so every month before it is outside the close and there is nothing to close.',
    keys: ['close_start'],
  },
  {
    id: 'excess_mileage_is_free', severity: 'warning', module: 'rentals',
    title: 'Distance beyond the free allowance is charged at nothing',
    detail: 'The free allowance per day is real, but the rate beyond it is zero — a vehicle can come back a thousand kilometres over and the invoice does not move.',
    keys: ['rental_free_km', 'rental_mileage_per_km'],
  },
  {
    id: 'conversions_without_forex', severity: 'note', module: 'fx',
    title: 'Foreign exchange is off, but its conversions are still switched on',
    detail: 'Every conversion asks whether foreign exchange itself is on before it looks at its own switch, so these read as enabled and none of them can run.',
    keys: ['forex_enabled', 'forex_channel_convert', 'forex_ap_convert', 'forex_ap_pay'],
  },
  {
    id: 'surveys_without_cooldown', severity: 'warning', module: 'rep',
    title: 'The same guest is surveyed on every single stay',
    detail: 'The cooldown is the only thing that stops a second invitation going out. At zero the check is skipped entirely.',
    keys: ['fb_cooldown_days'],
  },
  {
    id: 'recreation_sales_without_a_shift', severity: 'warning', module: 'recreation',
    title: 'Recreation takes money without an open shift, so the close reconciles nothing',
    detail: 'The counter only refuses a sale when a shift is required. With it off a cashier takes cash with no opening float, and the end-of-shift report has nothing to count the takings against.',
    keys: ['recreation_shift_required'],
  },
  {
    id: 'campaign_revenue_expires_the_day_it_is_sent', severity: 'warning', module: 'crm',
    title: 'A campaign is credited for one day only, so campaign revenue reads as nothing',
    detail: 'The attribution window decides whether a booking counts as the campaign\'s. At zero a booking only counts on the day the message went out, so every campaign shows its spend and none of its result.',
    keys: ['crm_attribution_days'],
  },
  {
    id: 'listings_are_always_overdue_for_audit', severity: 'warning', module: 'digital',
    title: 'Every listing reads as overdue for audit the moment it is audited',
    detail: 'A listing is held stale until it is audited inside the window. At zero days the window has already passed, so the list never clears.',
    keys: ['dm_audit_days'],
  },
  {
    id: 'certificate_warning_arrives_on_the_last_day', severity: 'warning', module: 'fs',
    title: 'Nothing warns before a food handler\'s certificate lapses',
    detail: 'At zero a handler is flagged only on the day their certificate runs out, by which time they should already be off food handling.',
    keys: ['fs_cert_warn_days'],
  },
  {
    id: 'rent_cap_not_enforced', severity: 'warning', module: 'leases',
    title: 'The Rent Act cap on advance rent is not enforced',
    detail: 'Residential tenancies are billed and renewed without the statutory cap, so a tenant can be asked for a year up front and the lease will not object.',
    keys: ['lease_enforce_rent_cap'],
  },
  {
    id: 'emissions_approved_by_their_own_author', severity: 'warning', module: 'esg',
    title: 'One person can approve their own emissions initiative',
    detail: 'With the four-eyes check off, the emissions record can be signed off by the person who proposed the figure — the first thing an assurance provider asks about.',
    keys: ['esg_four_eyes'],
  },
  {
    id: 'portal_switches_on_a_closed_portal', severity: 'note', module: 'partner',
    title: 'The partner portal is closed, but its controls are still switched on',
    detail: 'Paying, amending and cancelling are read only once the portal is live, so these three read as available and none of them can be reached.',
    keys: ['partner_enabled', 'partner_pay', 'partner_amend', 'partner_cancel'],
  },
  {
    id: 'cashier_shift_optional', severity: 'note', module: 'desk',
    title: 'Money can be taken without a cashier shift being open',
    detail: 'Every payment is still recorded, but nothing ties a drawer to the person who took the money, so a variance cannot be pinned to a shift.',
    keys: ['require_cashier_shift'],
  },
];

const SEVERITY_LABEL = { blocker: 'Cannot work', warning: 'Contradiction', note: 'Policy choice' };
const SEVERITY_RANK = { blocker: 0, warning: 1, note: 2 };

/** ConfigurationService::advisories() — decorated and ordered, optionally for one module. */
function advisoriesFor(modules, module = null) {
  const byKey = new Map(modules.map((m) => [m.key, m]));
  return FINDINGS
    .filter((f) => (module ? f.module === module : true))
    .map((f) => {
      const m = byKey.get(f.module);
      return {
        ...f,
        module_label: m ? m.label : f.module,
        route: m ? m.route : null,
        perm: m ? m.perm : null,
        severity_label: SEVERITY_LABEL[f.severity],
        keys_text: f.keys.join(' · '),
      };
    })
    .sort((a, b) => SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity] || a.title.localeCompare(b.title));
}

function countsOf(findings) {
  const counts = { blocker: 0, warning: 0, note: 0, total: findings.length };
  for (const f of findings) counts[f.severity] += 1;
  return counts;
}

const RECENT = [
  ['Updated banquet settings', 'Yaw Antwi', '2026-11-04T16:20:00Z'],
  ['Updated parking tariffs and the valet promise', 'Nana Ama Owusu', '2026-11-03T09:05:00Z'],
  ['Updated payroll settings', 'Adjoa Sarpong', '2026-11-02T11:40:00Z'],
  ['Updated hotel settings', 'Daniel Ofori', '2026-11-01T07:30:00Z'],
  ['Updated reputation and review-response settings', 'Efua Appiah', '2026-10-30T14:10:00Z'],
  ['Updated purchasing approval limits', 'Kwame Boateng', '2026-10-29T10:15:00Z'],
  ['Updated time clock grace and breaks', 'Adjoa Sarpong', '2026-10-27T08:45:00Z'],
  ['Updated VAT and e-invoicing credentials', 'Daniel Ofori', '2026-10-24T17:05:00Z'],
];

// ── the two screens this round built: the rate card and the leave policy ────────────
export function rentalSettingsContext() {
  return {
    scope: {
      c: { rental_free_km: '150', rental_fuel_price: '250', rental_late_per_hour: '60', rental_mileage_per_km: '2.5' },
      hotel: HOTEL, $currentHotel: HOTEL, helpers: HELPERS,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function hrSettingsContext() {
  return {
    scope: {
      c: { hr_leave_sla_hours: '24', leave_count_all_days: '0' },
      hotel: HOTEL, $currentHotel: HOTEL, helpers: HELPERS,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function configurationIndexContext() {
  const { modules, groups } = buildModules();
  return {
    scope: {
      groups: groupList(modules, groups),
      coverage: coverageOf(modules),
      trail: TRAIL_COVERAGE,
      gaps: modules.filter((m) => !m.open),
      findings: advisoriesFor(modules),
      findingCounts: countsOf(advisoriesFor(modules)),
      recent: Coll.of(RECENT.map(([description, by, iso]) => ({ description, user: { name: by }, created_at: at(iso) }))),
      q: '',
      matched: modules.length,
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function configurationChangedContext() {
  const { modules } = buildModules();

  // ConfigurationService::changed(): a decision is a value moved off its built-in default,
  // or a value filled in where the code has no default at all. The fixture property's own
  // LAST map supplies who changed it and when.
  const decision = (m, k) => ({
    module: m.key, module_label: m.label, group: m.group, route: m.route, perm: m.perm,
    reachable: m.reachable, key: k.key, raw: k.raw, label: k.label, value: k.value,
    default: k.default_show, secret: k.secret, family: k.family,
    when: m.last ? m.last.at : null, by: m.last ? m.last.by : null, what: m.last ? m.last.what : null,
  });

  const drifted = [];
  const chosen = [];
  const touched = {};
  for (const m of modules) {
    for (const k of m.keys) {
      if (!k.set) continue;
      if (k.changed) { drifted.push(decision(m, k)); touched[m.key] = m.label; }
      else if (k.default === null && !k.secret) { chosen.push(decision(m, k)); touched[m.key] = m.label; }
    }
  }

  return {
    scope: {
      decisions: {
        drifted, chosen, modules: touched,
        counts: { drifted: drifted.length, chosen: chosen.length, total: drifted.length + chosen.length, modules: Object.keys(touched).length },
      },
      coverage: coverageOf(modules),
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
      printedAt: at('2026-11-05T09:20:00Z'),
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function configurationOnNullContext() {
  const { modules } = buildModules();

  // ConfigurationService::onNull(): the settings the code ships no default for, grouped by
  // what an unset value actually runs on — a literal in the code, a literal only a page
  // prints, or nothing at all. Never a guess: the fallback comes from the read site.
  const groups = {
    code: { state: 'code', title: 'Falls back to a value written in the code', hint: 'Unset on this property, so the code supplies the value at its read site. Change it here and you take over from that literal.', rows: [] },
    page: { state: 'page', title: 'A page prints a value; the service gets null', hint: 'The screen shows something reassuring while the service that acts sees nothing. Worth a decision, not a shrug.', rows: [] },
    null: { state: 'null', title: 'Nothing at all', hint: 'Read with no default, so the service gets null and each call site decides what that means. Unset here is genuinely unset.', rows: [] },
  };
  const seen = {};
  const modulesTouched = [];

  for (const m of modules) {
    for (const k of m.keys) {
      if (k.default !== null || k.secret || k.family) continue;
      const f = FALLBACKS.fallbacks[k.raw];
      if (!f) continue;
      const state = f.service !== null ? 'code' : (f.view !== null ? 'page' : 'null');
      groups[state].rows.push({
        key: k.key, raw: k.raw, label: k.label, module: m.key, module_label: m.label,
        group: m.group, route: m.route, perm: m.perm, reachable: m.reachable,
        set: k.set, value: k.value, what: unsetShow(k.raw),
        service: f.service, view: f.view, service_file: f.service_file, view_file: f.view_file,
        read_in: k.read_in, read_paths: k.read_paths,
      });
      if (!seen[m.key]) { seen[m.key] = m.label; modulesTouched.push(m.label); }
    }
  }

  const counts = {
    code: groups.code.rows.length, page: groups.page.rows.length,
    null: groups.null.rows.length, total: 0, modules: modulesTouched.length,
  };
  counts.total = counts.code + counts.page + counts.null;

  return {
    scope: {
      groups: [groups.code, groups.page, groups.null],
      counts,
      conflicting: FALLBACKS.conflicting,
      coverage: coverageOf(modules),
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
      printedAt: at('2026-11-05T09:20:00Z'),
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

// ── the value trail: what each setting was before ──────────────────────────────────
// ConfigurationService::valueTrail() reads App\Models\SettingChange, which
// App\Observers\SettingValueObserver writes on every write of a settings row. The fixture
// keeps the same shape — [key, was, now, by, iso, created?, secret?] — so the page renders
// exactly as it does in the application. Note the second row: a credential records that it
// changed and never what it is.
const TRAIL = [
  ['booking_policy', 'A'.repeat(2000) + '…', 'Updated policy', 'Yaw Antwi', '2026-11-05T09:20:00Z'],
  ['loyalty_spend_per_point', '0', '100', 'Yaw Antwi', '2026-11-05T09:10:00Z'],
  ['cancel_free_hours', '72', '48', 'Yaw Antwi', '2026-11-04T16:20:00Z'],
  ['pos_kot_print_mode', null, 'split', 'Chef Mani', '2026-11-04T12:02:00Z', true],
  ['portal_wifi_password', 'Set — hidden', 'Set — hidden', 'Efua Asante', '2026-11-04T09:12:00Z', false, true],
  ['banquet_guarantee_days', '7', '3', 'Yusuf Ibrahim', '2026-11-03T18:40:00Z'],
  ['sms_driver', null, 'log', 'Kwame Boateng', '2026-11-03T11:05:00Z', true],
  ['spa_slot_min', '45', '30', 'Grace Mensah', '2026-11-02T15:30:00Z'],
  ['pay_booking_deposit_pct', '25', '30', 'Yaw Antwi', '2026-11-02T10:00:00Z'],
  ['flash_report_emails', 'accounts@nowills.example', 'cleared', 'Meera Iyer', '2026-11-01T20:15:00Z'],
  ['loyalty_point_value', '1', '0', 'System Administrator', '2026-10-31T07:45:00Z'],
];

const TRAIL_COVERAGE = { rows: 412, keys: 96, keys_total: 276, since: at('2026-10-31T07:45:00Z') };

/**
 * The flat Settings form's typed keys — SettingController::KEYS. These are the only settings
 * whose value the register can check before offering to put it back, so the fixture reads the
 * same table rather than keeping a list of its own.
 */
function typedKeys() {
  const src = fs.readFileSync(path.join(ROOT, 'app/Http/Controllers/SettingController.php'), 'utf8');
  const start = src.indexOf('public const KEYS = [');
  const body = src.slice(start, src.indexOf('\n    ];', start));
  const out = {};
  for (const line of body.split('\n')) {
    const hit = line.match(/^\s*'([a-z0-9_]+)' => \['((?:[^'\\]|\\.)*)', '([a-z]+)'/);
    if (hit) out[hit[1]] = { label: hit[2], type: hit[3] };
  }
  return out;
}

const TYPED = typedKeys();
// Read the form's own numeric bounds instead of inventing a preview-only rule.
const settingsSource = fs.readFileSync(path.join(ROOT, 'app/Http/Controllers/SettingController.php'), 'utf8');
const boundsStart = settingsSource.indexOf('public const BOUNDS = [');
const boundsBlock = settingsSource.slice(boundsStart, settingsSource.indexOf('\n    ];', boundsStart));
const BOUNDS = Object.fromEntries([...boundsBlock.matchAll(/'([a-z0-9_]+)' => \['([0-9.]+)', '([0-9.]+)'\]/g)]
  .map(m => [m[1], [Number(m[2]), Number(m[3])]]));

/** ConfigurationService::restoreBlocked() — why a value cannot be put back, or null. */
function restoreBlocked(row) {
  if (row.secret) return 'credential values cannot be restored from this trail';
  if (!TYPED[row.key]) return 'this setting is owned by its module screen, which holds its own rules';
  if (row.created) return 'there was nothing here before — it was first set, not changed';
  if (row.was === null) return 'the value before this change was empty';
  if (typeof row.was_raw === 'string' && [...row.was_raw].length === 2001 && row.was_raw.endsWith('…')) return 'the previous value was truncated in the trail — restore it from a backup or the owning screen';
  if (TYPED[row.key].type === 'number' && BOUNDS[row.key] && (Number(row.was_raw) < BOUNDS[row.key][0] || Number(row.was_raw) > BOUNDS[row.key][1])) return 'the value it had is no longer accepted: it is outside the range this screen accepts';
  if (TYPED[row.key].type === 'number' && !/^-?\d+(\.\d+)?$/.test(String(row.was))) {
    return 'the value it had is no longer accepted: it is not a number this screen accepts';
  }
  return null;
}

/** ConfigurationService::valueTrail() — newest first, decorated with the register's labels. */
function valueTrail(module, limit = 60) {
  const labels = LABELS();
  const rows = [];
  for (const [key, was, now, by, iso, created, secret] of TRAIL) {
    const at0 = labels[key];
    if (!at0) continue;
    if (module && at0.module !== module) continue;
    const row = {
      id: rows.length + 1, key, label: at0.label, module: at0.module, module_label: at0.module_label,
      was: created ? null : (typeof was === 'string' && was.length > 80 ? was.slice(0, 77) + '…' : was), now, secret: Boolean(secret), created: Boolean(created),
      when: at(iso), by, was_raw: created || secret ? null : was,
    };
    row.restore_blocked = restoreBlocked(row);
    row.restore_ok = row.restore_blocked === null;
    rows.push(row);
  }
  return rows.slice(0, limit);
}

/** The register's key → {label, module} map, built from config/settings.php itself. */
function LABELS() {
  const { modules } = parseRegistry(path.join(ROOT, 'config/settings.php'));
  const out = {};
  for (const m of modules) {
    for (const [key, label] of m.keys) {
      out[key] = { label, module: m.key, module_label: m.label, group: m.group, route: m.route, perm: m.perm };
    }
  }
  return out;
}

export function configurationHistoryContext() {
  const { modules } = buildModules();
  const module = modules.find((x) => x.key === 'messages') ?? null;
  return {
    scope: {
      changes: valueTrail(null, 120),
      trail: TRAIL_COVERAGE,
      module: null,
      modules,
      coverage: coverageOf(modules),
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function configurationHistoryModuleContext() {
  return configurationHistoryContext().scope;
}

/** Version 2 fingerprint using the sample's raw setting values. */
export function fingerprintOf(modules) {
  const keys = [...new Map(modules.flatMap(m => m.keys).map(k => [k.raw, k])).values()];
  // Registry keys are ASCII; compare by code unit like PHP SORT_STRING.
  keys.sort((a, b) => a.raw < b.raw ? -1 : a.raw > b.raw ? 1 : 0);
  const secrets = new Set(keys.filter(k => k.secret).map(k => k.raw));
  const values = { ...SET, ...SECRETS_SET };
  const records = keys.map(k => {
    if (k.secret) return [k.raw, 'credential', k.set];
    if (k.family) {
      const members = Object.keys(values).filter(key => key.startsWith(k.raw)).sort()
        .map(key => [key, secrets.has(key) ? ['credential', values[key] != null && String(values[key]).trim() !== ''] : ['value', values[key] == null ? null : String(values[key])]]);
      return [k.raw, 'family', members];
    }
    return k.raw in SET ? [k.raw, 'value', SET[k.raw] == null ? null : String(SET[k.raw])] : [k.raw, 'missing'];
  });
  const full = crypto.createHash('sha256').update(JSON.stringify(records)).digest('hex');
  return { full, short: full.slice(0, 12).toUpperCase(), keys: keys.length, lines: records.length,
    at: at('2026-11-04T16:20:00Z') };
}

// Settings the code reads through a DEFAULTS map that the register does not hold: the same
// arithmetic the service does, over the same generated file, so the sheet cannot print a
// number the code does not support.
export function mapGapCount() {
  const map = parseReaders().map ?? {};
  const { modules } = buildModules();
  const known = new Set(modules.flatMap((m) => m.keys.map((k) => k.raw)));
  return Object.keys(map).filter((k) => !known.has(k)).length;
}

export function configurationHandoverContext() {
  const { modules } = buildModules();
  const guarded = modules.filter((m) => m.rules > 0);
  const unguarded = modules.filter((m) => m.rules === 0);
  const credentialKeys = modules.flatMap((m) => m.keys.filter((k) => k.secret));
  const secretsSet = credentialKeys.filter((k) => k.set);
  return {
    scope: {
      fingerprint: fingerprintOf(modules),
      decisions: configurationChangedContext().scope.decisions,
      unset: configurationOnNullContext().scope,
      findings: advisoriesFor(modules),
      findingCounts: countsOf(advisoriesFor(modules)),
      coverage: coverageOf(modules),
      credentials: {
        modules: modules.filter((m) => m.keys.some((k) => k.secret && k.set)).length,
        keys: credentialKeys.length, set: secretsSet.length,
      },
      guarded, unguarded,
      guardedNames: guarded.map((m) => m.label).join(' · '),
      unguardedNames: unguarded.map((m) => m.label).join(' · '),
      mapGap: mapGapCount(),
      trail: TRAIL_COVERAGE,
      recentChanges: valueTrail(null, 12),
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
      printedAt: at('2026-11-05T09:20:00Z'),
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function configurationShowContext() {
  const { modules } = buildModules();
  const m = modules.find((x) => x.key === 'banquet');
  return {
    scope: {
      m,
      history: Coll.of([
        { description: 'Updated banquet settings', user: { name: 'Yaw Antwi' }, created_at: at('2026-11-04T16:20:00Z') },
        { description: 'Updated banquet settings', user: { name: 'Yusuf Ibrahim' }, created_at: at('2026-10-18T13:05:00Z') },
      ]),
      findings: advisoriesFor(modules, 'banquet'),
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

/** One module that carries a finding — the payments screen, where the register points. */
export function configurationModuleFindingsContext() {
  const { modules } = buildModules();
  const m = modules.find((x) => x.key === 'payments');
  return {
    scope: {
      m,
      history: Coll.of([
        { description: 'Updated online payment provider', user: { name: 'Nana Ama Owusu' }, created_at: at('2026-11-03T09:05:00Z') },
        { description: 'Updated online payment provider', user: { name: 'Daniel Ofori' }, created_at: at('2026-10-11T15:40:00Z') },
      ]),
      valueTrail: valueTrail('payments', 8),
      findings: advisoriesFor(modules, 'payments'),
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function configurationPrintContext() {
  const { modules, groups } = buildModules();
  return {
    scope: {
      groups: groupList(modules, groups),
      coverage: coverageOf(modules),
      findings: advisoriesFor(modules),
      findingCounts: countsOf(advisoriesFor(modules)),
      fingerprint: fingerprintOf(modules),
      hotel: HOTEL,
      printedAt: at('2026-11-05T08:10:00Z'),
      $currentHotel: HOTEL,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}

export function configurationSearchContext() {
  const { modules, groups } = buildModules();
  const needle = 'valet';
  const hits = modules.filter((m) => JSON.stringify(m).toLowerCase().includes(needle));
  return {
    scope: {
      groups: groupList(hits, groups),
      coverage: coverageOf(modules),
      trail: TRAIL_COVERAGE,
      gaps: modules.filter((m) => !m.open),
      findings: advisoriesFor(modules),
      findingCounts: countsOf(advisoriesFor(modules)),
      recent: Coll.of([]),
      q: 'valet',
      matched: hits.length,
      hotel: HOTEL,
      businessDate: BUSINESS_DAY,
    },
    $currentHotel: HOTEL,
    helpers: HELPERS,
  };
}
