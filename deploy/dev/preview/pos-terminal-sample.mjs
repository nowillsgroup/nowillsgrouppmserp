/**
 * Sample data for the POS terminal — resources/views/pos/terminal.blade.php rendered the way PosController::terminal()
 * hands it over: the outlet's menu, the categories, the allergen map, the printer routing, and the running order.
 *
 * The screen this preview exists for is the side-dish picker. Four of the eight dishes below come with side dishes, and
 * tapping one opens "Side dish for <dish>" before the dish ever reaches the cart. Two of those four also carry option
 * groups (a portion, a spice level), so the whole chain is on the page: dish → side dish → options → order line.
 *
 *   • Grilled Tilapia & Banku — three sides, one of them included, two charged; no option groups, and the side is
 *     required, which is what "Continue with none" is not allowed to answer.
 *   • Jollof Rice (Special)  — three sides and a required portion group (Regular / Large +15.00).
 *   • Banku & Okro Stew      — two chargeable sides.
 *   • Grilled Chicken & Chips— three sides, a required spice level, and a required side; it is the dish already on the
 *     check, sent with its chips, and the one that proves the rule survives the options step.
 *
 * The running order carries two lines — a served Club Beer and a Grilled Chicken & Chips already sent with "Extra chips"
 * — so the "Side dishes: …" line can be seen on a real order line as well as in the draft ticket.
 *
 * No guest allergy is set on this order: the allergen branches call AllergenService statically, which a preview cannot
 * resolve, and an empty allergen set renders them out honestly rather than as blanks.
 *
 * "Today" is 7 November 2026, service on the terrace, table 12.
 */
import { Coll, SampleDate, raw } from './blade-render.mjs';

const TODAY = new SampleDate('2026-11-07T18:20:00Z');

export const HOTEL = {
  name: 'The Grove Essipong Beach Resort', code: 'GROVE',
  currency: 'GHS', currency_symbol: 'GH₵',
};

const CATS = [
  { id: 1, name: 'Kitchen' },
  { id: 2, name: 'Grill' },
  { id: 3, name: 'Bar' },
];

const side = (id, name, rate) => ({ id, name, rate });
const modifier = (id, name, price_delta) => ({ id, name, price_delta, is_available: true });
const group = (id, name, min, max, required, options) => ({
  id, name, min_selections: min, max_selections: max, is_required: required,
  modifiers: new Coll(options.map(([mid, mname, delta]) => modifier(mid, mname, delta))).where('is_available', true),
});

const item = (o) => ({
  id: o.id, name: o.name, code: o.code, price: o.price, menu_category_id: o.cat, food_type: o.food,
  is_available: true, outlet_id: null, image_path: null, tax_rate: 15,
  side_dish_required: o.sideRequired === true,
  variants: new Coll(o.sides || []),
  modifierGroups: new Coll(o.groups || []),
  category: CATS.find(c => c.id === o.cat),
  imageUrl: () => '',
});

const ITEMS = [
  // the one dish whose side dish is not optional: the kitchen plates it with a side, so the till will not ring it up
  // without one — "Continue with none" is not an answer here
  item({ id: 501, name: 'Grilled Tilapia & Banku', code: 'GTB', price: 85.00, cat: 1, food: 'non_veg', sideRequired: true,
    sides: [side(1, 'Garden salad', 0), side(2, 'Extra banku', 12.00), side(3, 'Fried plantain', 10.00)] }),
  item({ id: 502, name: 'Jollof Rice (Special)', code: 'JRS', price: 65.00, cat: 1, food: 'non_veg',
    sides: [side(4, 'Grilled chicken', 25.00), side(5, 'Fried fish', 30.00), side(6, 'Extra shito', 5.00)],
    groups: [group(1, 'Portion', 1, 1, true, [[11, 'Regular', 0], [12, 'Large', 15.00]])] }),
  item({ id: 503, name: 'Banku & Okro Stew', code: 'BOS', price: 58.00, cat: 1, food: 'non_veg',
    sides: [side(7, 'Extra fish', 28.00), side(8, 'Extra okro', 8.00)] }),
  // the dish already on the check, and the one with both a required side and an option group: the rule has to survive
  // the options step too, where the sides can still be unticked
  item({ id: 504, name: 'Grilled Chicken & Chips', code: 'GCC', price: 78.00, cat: 2, food: 'non_veg', sideRequired: true,
    sides: [side(9, 'Coleslaw', 0), side(10, 'Extra chips', 10.00), side(11, 'Fried plantain', 8.00)],
    groups: [group(2, 'Spice level', 1, 1, true, [[21, 'Mild', 0], [22, 'Medium', 0], [23, 'Hot', 0]])] }),
  item({ id: 505, name: 'Kelewele', code: 'KLW', price: 20.00, cat: 2, food: 'veg' }),
  item({ id: 506, name: 'Club Beer 330ml', code: 'CLB', price: 30.00, cat: 3, food: 'beverage' }),
  item({ id: 507, name: 'Malta Guinness', code: 'MLT', price: 25.00, cat: 3, food: 'beverage' }),
  item({ id: 508, name: 'Voltic Water 1.5L', code: 'VLC', price: 12.00, cat: 3, food: 'beverage' }),
];

const MENU = new Coll(ITEMS);

/* the two lines already on the check: one plain, one that carried a side dish through the picker */
const ORDER_LINES = new Coll([
  { id: 901, menu_item_id: 506, item_name: 'Club Beer 330ml', qty: 2, rate: 30.00, amount: 60.00, tax_rate: 15,
    is_open: false, is_cancelled: false, is_pending: false, is_complimentary: false, comp_reason: null,
    kitchen_status: 'served', kitchen_station: 'Bar', kot_no: 41, kot_printer_id: 1, notes: null, allergy_flag: null,
    modifiers: [],
    created_at: new SampleDate('2026-11-07T17:52:00Z') },
  { id: 902, menu_item_id: 504, item_name: 'Grilled Chicken & Chips', qty: 1, rate: 88.00, amount: 88.00, tax_rate: 15,
    is_open: false, is_cancelled: false, is_pending: false, is_complimentary: false, comp_reason: null,
    kitchen_status: 'preparing', kitchen_station: 'Grill', kot_no: 42, kot_printer_id: 2, notes: 'No pepper on the chips',
    allergy_flag: null,
    modifiers: [{ type: 'side_dish', id: 10, group: 'Side dishes', name: 'Extra chips', price_delta: 10.00 },
      { type: 'modifier', id: 22, group: 'Spice level', name: 'Medium', price_delta: 0 }],
    created_at: new SampleDate('2026-11-07T18:04:00Z') },
]);

const OUTLET = { id: 3, name: 'Terrace Restaurant' };

const ORDER = {
  id: 412, order_no: 'POS-2026-0412', status: 'open', source: 'waiter', order_type: 'dine_in',
  outlet_id: OUTLET.id, outlet: OUTLET, table: { id: 12, table_no: '12' }, stay: null,
  customer_name: 'Mr. K. Adjei', customer_phone: '024 551 8802', covers: 2, steward: 'Ama Boateng',
  bill_no: null, merged_into_id: null, split_from_id: null, settlement: null, payment: null,
  allergens: [], allergy_note: null, notes: null,
  subtotal: 148.00, service_charge: 0, tax: 22.20, discount: 0, round_off: 0, total: 170.20,
  paid: 0, balance: 170.20, comp: 0,
  club: false, club_member_id: null, reservation_room_id: null,
  delivery_fee: 0, delivery_status: null, delivery_address: null, delivery_notes: null, delivery_driver_id: null,
  payments: new Coll([]), paymentMode: null, items: ORDER_LINES,
  paidAmount: () => 0, balanceDue: () => 170.20, compValue: () => 0,
};

export function posTerminalContext() {
  return {
    scope: {
      order: ORDER, items: MENU, cats: new Coll(CATS),
      allergenMap: Object.fromEntries(ITEMS.map(i => [i.id, { contains: [], may: [] }])),
      printerMap: {}, guestAll: [], autoPrintUrl: null,
      kotPrinters: new Coll([]), flashJobs: new Coll([]),
      printerNames: { 1: 'Bar printer', 2: 'Grill printer' },
      freeTables: new Coll([
        { id: 7, table_no: '7', section: 'Garden', capacity: 4 },
        { id: 15, table_no: '15', section: 'Terrace', capacity: 2 },
        { id: 18, table_no: '18', section: 'Terrace', capacity: 6 },
      ]),
      otherOrders: new Coll([
        { id: 409, order_no: 'POS-2026-0409', order_type: 'dine_in', table: { table_no: '8' }, total: 212.50 },
        { id: 410, order_no: 'POS-2026-0410', order_type: 'room_charge', table: null, total: 96.00 },
      ]),
      posCurrencySymbol: 'GH₵',
    },
    fallbacks: {},
    helpers: {
      number_format: (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places }),
      money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      dmy: (value) => {
        if (value == null || value === '') return '—';
        const iso = typeof value?.toDateString === 'function' ? value.toDateString() : String(value).slice(0, 10);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        return `${iso.slice(8, 10)} ${months[Number(iso.slice(5, 7)) - 1]} ${iso.slice(0, 4)}`;
      },
      bdate: () => TODAY,
      now: () => TODAY,
      session: (key) => (key === 'print_kot' ? null : null),
      hotel: () => HOTEL,
      status_badge: (status) => {
        const map = { open: ['amber', 'Open'], billed: ['blue', 'Billed'], settled: ['green', 'Settled'],
          cancelled: ['red', 'Cancelled'] };
        const [tone, label] = map[status] ?? ['slate', String(status ?? '')];
        return `<span class="badge badge-${tone}">${label}</span>`;
      },
      route: (name, params) => {
        const part = (v) => (v && typeof v === 'object' ? String(v.id ?? '') : String(v));
        let tail = '';
        if (params !== undefined && params !== null) {
          if (params && typeof params === 'object' && !Array.isArray(params) && params.id !== undefined) tail = '-' + params.id;
          else if (Array.isArray(params)) tail = '-' + params.map(part).join('-');
          else if (typeof params === 'object') {
            const segments = [];
            const query = [];
            Object.entries(params).forEach(([k, v]) => {
              if (/^\d+$/.test(k)) segments.push(part(v));
              else if (Array.isArray(v)) query.push(`${k}[]=${v.map(part).join(',')}`);
              else query.push(`${k}=${part(v)}`);
            });
            tail = (segments.length ? '-' + segments.join('-') : '') + (query.length ? '?' + query.join('&') : '');
          } else tail = '-' + part(params);
        }

        return `#${String(name).replace(/\./g, '-')}${tail}`;
      },
      asset: path => `/${String(path ?? '').replace(/^\/+/, '')}`,
      can_do: (cap) => ['pos.view', 'pos.order', 'pos.settle', 'pos.void', 'pos.refund'].includes(cap),
      request: (key) => (key === undefined
        ? { route: () => null, routeIs: () => false, filled: () => false, boolean: () => false, has: () => false, input: () => null }
        : null),
      setting: (key, fallback = null) => fallback,
      ucwords: value => String(value ?? '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
      collect: value => (value instanceof Coll ? value
        : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
      max: (...args) => Math.max(...args.map(Number)),
      min: (...args) => Math.min(...args.map(Number)),
      // str_replace(['_', '-'], ' ', $x) is how the views turn a snake_case column into a label
      str_replace: (search, replace, subject) => {
        const searches = Array.isArray(search) ? search : [search];
        const replacements = Array.isArray(replace) ? replace : [replace];
        return searches.reduce((text, needle, index) => text.split(needle).join(replacements[index] ?? ''), String(subject ?? ''));
      },
      ucfirst: value => { const s = String(value ?? ''); return s.charAt(0).toUpperCase() + s.slice(1); },
      // Blade's `{{ }}` escapes what it echos; `e()` escapes explicitly. A JSON string written into a
      // double-quoted attribute must be escaped exactly once — see build-pos-terminal-preview.mjs.
      e: value => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'),
      json_encode: (value) => {
        const plain = (v) => (v instanceof Coll ? v.items.map(plain) : Array.isArray(v) ? v.map(plain)
          : v && typeof v === 'object' ? Object.fromEntries(Object.entries(v).map(([k, x]) => [k, plain(x)])) : v);
        return JSON.stringify(plain(value));
      },
      array_intersect: (a, b) => (Array.isArray(a) ? a : []).filter(v => (Array.isArray(b) ? b : []).includes(v)),
      fmod: (a, b) => Number(a) % Number(b),
      // The allergen service is called statically from the view; a preview cannot reach PHP, so the two
      // calls it makes are answered here — and this order carries no declared allergies.
      __static: (cls, method) => {
        const key = `${String(cls).replace(/^\\/, '')}::${method}`;
        const table = {
          'App\\Services\\AllergenService::clean': () => [],
          'App\\Services\\AllergenService::names': () => '',
          'App\\Services\\AllergenService::code': () => '',
          'App\\Services\\AllergenService::map': () => ({}),
          'App\\Services\\PosEvatService::enabled': () => false,
        };
        return table[key];
      },
      __const: (cls, name) => {
        const key = `${String(cls).replace(/^\\/, '')}::${name}`;
        const table = {
          'App\\Models\\PosOrder::COMP_REASONS': ['Manager comp', 'Staff meal', 'Guest complaint', 'Owner entertain', 'Promotion'],
          'App\\Services\\AllergenService::ALLERGENS': ['Gluten', 'Nuts', 'Shellfish', 'Dairy', 'Eggs', 'Soy', 'Fish', 'Sesame'],
        };
        return table[key];
      },
      old: (key, fallback = null) => fallback,
      raw,
      Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
      parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
    },
  };
}
