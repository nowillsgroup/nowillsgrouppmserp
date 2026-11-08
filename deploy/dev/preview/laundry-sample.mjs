/**
 * Sample data for the Laundry preview.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/laundry/show.blade.php and laundry/prices.blade.php against this fixed
 * sample. The shapes mirror LaundryController::show() and ::prices(), so the order
 * composes exactly as it does in the application. The order is an in-house guest's
 * laundry sitting in "processing" — the moment the counter is asked to hand it back and
 * decide whether it goes on the room folio or is paid for there and then.
 *
 * The tax figures are the statutory Ghana components (VAT 15 + NHIL 2.5 + GETFund 2.5 +
 * Tourism levy 1 = 21%) applied to a GH₵ 610.00 order, exactly as LaundryService::quote()
 * computes them.
 */
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-05';
const at = (iso) => new SampleDate(iso.includes('T') ? iso : `${iso}T09:00:00Z`);

const SERVICES = { wash: 'Wash & iron', dryclean: 'Dry clean', press: 'Press only' };
const CATEGORIES = { gents: "Gents", ladies: 'Ladies', kids: 'Kids', household: 'Household' };
const TONES = { collected: 'blue', processing: 'amber', ready: 'purple', delivered: 'green', cancelled: 'slate' };

const rate = (wash, dryclean, press) => ({ wash, dryclean, press });

const items = [
  { id: 1, name: 'Shirt', category: 'gents', sort_order: 1, is_active: true, ...rate(60, 150, 30) },
  { id: 2, name: 'Trousers', category: 'gents', sort_order: 2, is_active: true, ...rate(70, 160, 35) },
  { id: 3, name: 'Suit (2 pc)', category: 'gents', sort_order: 3, is_active: true, ...rate(null, 450, 150) },
  { id: 4, name: 'Kurta', category: 'gents', sort_order: 4, is_active: true, ...rate(70, 170, 35) },
  { id: 5, name: 'Dress', category: 'ladies', sort_order: 5, is_active: true, ...rate(110, 250, 60) },
  { id: 6, name: 'Blouse / top', category: 'ladies', sort_order: 6, is_active: true, ...rate(60, 150, 30) },
  { id: 7, name: 'Saree', category: 'ladies', sort_order: 7, is_active: true, ...rate(150, 350, 90) },
  { id: 8, name: 'Kids garment', category: 'kids', sort_order: 8, is_active: true, ...rate(35, 90, 20) },
  { id: 9, name: 'Socks (pair)', category: 'household', sort_order: 9, is_active: true, ...rate(25, null, null) },
];

const withRate = (item) => ({
  ...item,
  rate: (service) => item[service] ?? null,
});

const list = Coll.of(items.map(withRate));
const grouped = {};
for (const item of list.items) {
  (grouped[item.category] ??= []).push(item);
}
Object.keys(grouped).forEach((key) => { grouped[key] = Coll.of(grouped[key]); });

// The order in the preview: two shirts washed and pressed, a suit dry-cleaned and a
// dress pressed — GH₵ 690.00 of items on normal service, so no express surcharge.
const lines = Coll.of([
  { id: 11, laundry_item_id: 1, item_name: 'Shirt', service: 'wash', qty: 2, rate: 60, amount: 120, remark: 'Cuff button missing' },
  { id: 12, laundry_item_id: 1, item_name: 'Shirt', service: 'press', qty: 2, rate: 30, amount: 60, remark: null },
  { id: 13, laundry_item_id: 3, item_name: 'Suit (2 pc)', service: 'dryclean', qty: 1, rate: 450, amount: 450, remark: 'Ink mark on the sleeve' },
  { id: 14, laundry_item_id: 5, item_name: 'Dress', service: 'press', qty: 1, rate: 60, amount: 60, remark: null },
]);

const ROOM = { id: 202, room_no: '202' };
const GUEST = { id: 71, full_name: 'Ama Mensah', phone: '024 555 0188' };

const order = withMethods({
  id: 41,
  order_no: 'LND-000412',
  type: 'guest',
  status: 'processing',
  speed: 'normal',
  pieces: 6,
  bag_no: 'B-2081',
  bag_pieces: 6,
  subtotal: 690,
  express_charge: 0,
  total: 690,
  collected_at: at('2026-11-05T07:15:00Z'),
  promised_at: at('2026-11-06T07:15:00Z'),
  ready_at: null,
  delivered_at: null,
  notes: 'Guest needs the suit back for a wedding on Saturday — ring room 202 before delivery.',
  cancel_reason: null,
  guest_name: 'Ama Mensah',
  phone: '024 555 0188',
  room_id: ROOM.id,
  room: ROOM,
  guest: GUEST,
  lines,
  creator: { name: 'Kofi Boateng' },
  deliverer: null,
  guest_request: null,
  guestRequest: null,
  folio: null,
  folioTransaction: null,
  folio_transaction_id: null,
  paymentMode: null,
  payment_mode_id: null,
});

function withMethods(o) {
  return Object.assign(o, {
    isOpen: () => ['collected', 'processing', 'ready'].includes(o.status),
    isOverdue: () => ['collected', 'processing', 'ready'].includes(o.status) && o.promised_at?.isPast?.() === true,
    statusLabel: () => ({ collected: 'Collected', processing: 'Processing', ready: 'Ready', delivered: 'Delivered', cancelled: 'Cancelled' }[o.status] ?? o.status),
    settledDirectly: () => Boolean(o.folio_transaction_id) && o.folio?.type === 'walk_in',
    whoLabel: () => (o.type === 'walk_in' ? `${o.guest_name} (walk-in)` : `Room ${o.room?.room_no ?? '—'} · ${o.guest_name}`),
  });
}

// LaundryService::quote() for the order above: 690.00 + 21% = 834.90.
const quote = {
  listed: 690,
  net: 690,
  tax: 144.9,
  total: 834.9,
  rate: 21,
  inclusive: false,
  components: { VAT: 15, NHIL: 2.5, GETFUND: 2.5, TL: 1 },
};

const tax = { rate: 21, label: 'VAT 15% + NHIL 2.5% + GETFund levy 2.5% + Tourism levy 1%', inclusive: false };

const modes = { 1: 'Cash', 2: 'Mobile Money — MTN', 3: 'Mobile Money — Telecel', 4: 'Visa / Mastercard', 5: 'Bank transfer' };

const HELPERS = {
  can_do: () => true,
  old: (key, fallback = null) => (key === 'settle' ? 'room' : fallback),
  route: () => '#',
  asset: (path) => `/${String(path ?? '').replace(/^\/+/, '')}`,
  money: (value, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
  number_format: (value, decimals = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }),
  bdate: () => at(BUSINESS_DATE),
  now: () => new SampleDate('2026-11-05T09:20:00Z'),
  collect: (value) => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
  count: (value) => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
  empty: phpEmpty,
  in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
  json: (value) => JSON.stringify(value ?? null),
  json_encode: (value) => JSON.stringify(value ?? null),
  str_replace: (search, replace, subject) => String(subject ?? '').split(search).join(replace),
  strtolower: (value) => String(value ?? '').toLowerCase(),
  str_contains: (haystack, needle) => String(haystack ?? '').includes(String(needle ?? '')),
  strtoupper: (value) => String(value ?? '').toUpperCase(),
  ucfirst: (value) => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
  substr: (value, start, length) => String(value ?? '').substr(start, length),
  trim: (value) => String(value ?? '').trim(),
  number: (value) => Number(value ?? 0),
  intval: (value) => Number.parseInt(value, 10) || 0,
  abs: (value) => Math.abs(Number(value ?? 0)),
  round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
  max: (...values) => Math.max(...values.flat()),
  min: (...values) => Math.min(...values.flat()),
  implode: (glue, values) => (values instanceof Coll ? values.items : values ?? []).join(glue),
  isset: (...values) => values.every((value) => value !== undefined && value !== null),
  optional: (value) => ({ toDateString: () => (value?.toDateString ? value.toDateString() : ''), format: () => '' }),
  config: (key, fallback = null) => fallback,
  __const: (cls, name) => {
    const key = `${String(cls).split('\\').pop()}:${name}`;
    return ({ 'LaundryItem:SERVICES': SERVICES, 'LaundryItem:CATEGORIES': CATEGORIES, 'LaundryOrder:TONES': TONES })[key] ?? [];
  },
  __static: (cls, method, ...args) => {
    const name = String(cls).split('\\').pop();
    if (name === 'LaundryService' && method === 'SPEEDS') return { normal: 'Normal', express: 'Express' };
    if (name === 'LaundryService' && method === 'taxLabel') return tax.label;
    // LaundryService::quote($order): the same arithmetic the service does, at 21%.
    if (name === 'LaundryService' && method === 'quote') {
      const listed = Math.round(Number(args[0]?.total ?? 0) * 100) / 100;
      const taxAmount = Math.round(listed * 21) / 100;
      return { listed, net: listed, tax: taxAmount, total: Math.round((listed + taxAmount) * 100) / 100, rate: 21, inclusive: false, components: quote.components };
    }
    return args[0] ?? '';
  },
  rtrim: (value, chars = ' \t\n\r\0\x0B') => String(value ?? '').replace(new RegExp(`[${chars.replace(/[\\\]]/g, '\\$&')}]+$`), ''),
  ltrim: (value, chars = ' \t\n\r\0\x0B') => String(value ?? '').replace(new RegExp(`^[${chars.replace(/[\\\]]/g, '\\$&')}]+`), ''),
  array_keys: (value) => Object.keys(value ?? {}),
  array_filter: (value, fn) => (Array.isArray(value) ? value : Object.values(value ?? {}))
    .filter((entry) => (typeof fn === 'function' ? fn(entry) : Boolean(entry))),
  array_sum: (value) => (Array.isArray(value) ? value : Object.values(value ?? {})).reduce((total, entry) => total + Number(entry ?? 0), 0),
  array_map: (fn, list) => (Array.isArray(list) ? list : Object.values(list ?? {})).map(fn),
  is_array: (value) => Array.isArray(value),
  http_build_query: (params) => new URLSearchParams(Object.entries(params ?? {})
    .filter(([, value]) => value !== null && value !== undefined && value !== '')
    .map(([key, value]) => [key, String(value)])).toString(),
  e: (value) => String(value ?? ''),
  Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
  parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
};

const errors = { any: () => false, has: () => false, all: () => [], first: () => '', get: () => [] };

// The itemising grid reads $current[item][service] for every offer, so the form state
// carries a quantity for each cell (0 = nothing ordered) rather than a sparse map.
const current = Object.fromEntries(list.items.map((item) => [item.id, { wash: 0, dryclean: 0, press: 0 }]));
current[1] = { wash: 2, dryclean: 0, press: 2 };
current[3] = { wash: 0, dryclean: 1, press: 0 };
current[5] = { wash: 0, dryclean: 0, press: 1 };

/** The order screen: an in-house guest's express order, ready to be delivered and settled. */
export function laundryOrderContext() {
  return {
    helpers: HELPERS,
    scope: {
      errors,
      o: order,
      items: grouped,
      current,
      savedRates: Object.fromEntries(list.items.map(item => [item.id, { wash: null, dryclean: null, press: null, ...(item.id === 1 ? { wash: 60, press: 30 } : item.id === 3 ? { dryclean: 450 } : item.id === 5 ? { press: 60 } : {}) }])),
      savedNames: {}, blockedItems: {}, preservedLines: Coll.of([]), editRevision: 'sample-read-only',
      remarks: { 1: 'Cuff button missing', 3: 'Ink mark on the sleeve' },
      modes,
      expressPct: 50,
      quote,
      tax,
      hotel: HOTEL,
      currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}

/** A guest pickup already being handled, ready to collect for its own stay. */
export function laundryRequestContext() {
  const req = { id: 61, request_no: 'GR-00234', reservation_room_id: 71, room: ROOM,
    item: 'Express laundry (same day)', notes: 'Please collect after breakfast', status: 'in_progress',
    created_at: at('2026-11-05T07:30:00Z') };
  const ctx = laundryOrderContext();
  ctx.helpers = { ...HELPERS, old: (key, fallback = null) => key === 'q' ? Object.fromEntries(list.items.map(item => [item.id, { wash: 0, dryclean: 0, press: 0 }])) : fallback };
  Object.assign(ctx.scope, { req, requestUnavailable: false, stays: Coll.of([{ id: 71, room: ROOM, guest: GUEST }, { id: 72, room: { room_no: '305' }, guest: { full_name: 'Kofi Adjei' } }]),
    requests: Coll.of([req]), stayId: 71, hours: { normal: 24, express: 6 } });
  return ctx;
}

/** Price changes and a discontinued garment, shown as a read-only example. */
export function laundryPreservationContext() {
  const ctx = laundryOrderContext();
  const preserved = { id: 15, laundry_item_id: 22, item_name: 'Evening jacket', service: 'dryclean', qty: 1, rate: 100, amount: 100, remark: 'Loose button at collection' };
  ctx.scope.o = withMethods({ ...order, pieces: 7, subtotal: 790, total: 790, lines: Coll.of([...lines.items, preserved]) });
  ctx.scope.items = { ...grouped, gents: Coll.of(grouped.gents.items.map(item => item.id === 1 ? withRate({ ...item, name: 'Shirt (new catalogue name)', wash: 90 }) : item)) };
  ctx.scope.savedNames = { 1: 'Shirt' };
  ctx.scope.preservedLines = Coll.of([preserved]);
  ctx.scope.quote = { ...quote, listed: 790, net: 790, tax: 165.90, total: 955.90 };
  return ctx;
}

/** The price list: what each garment costs and the tax every laundry charge carries. */
export function laundryPricesContext() {
  return {
    helpers: HELPERS,
    scope: {
      errors,
      items: grouped,
      v: { laundry_express_pct: '50', laundry_normal_hours: '24', laundry_express_hours: '6' },
      tax,
      hotel: HOTEL,
      currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}

/** A paginator is a collection that also knows its total and draws no pager here. */
class SamplePaginator extends Coll {
  total() { return this.items.length; }
  fragment() { return this; }
  links() { return ''; }
}

/** The order as it looks after the guest paid at the counter: delivered, invoiced, tax shown. */
export function laundrySettledContext() {
  const folio = { id: 903, folio_no: 'FOL-004481', invoice_no: 'INV-002241', type: 'walk_in', status: 'closed' };
  const settled = withMethods({
    ...order,
    id: 41,
    status: 'delivered',
    ready_at: at('2026-11-05T12:05:00Z'),
    delivered_at: at('2026-11-05T12:20:00Z'),
    deliverer: { name: 'Yaa Asantewaa' },
    folio,
    folio_id: folio.id,
    folio_transaction_id: 88,
    folioTransaction: {
      id: 88, amount: 690, tax_amount: 144.9, tax_rate: 21, is_void: false,
      description: 'Laundry LND-000412 – 6 pcs', category: 'laundry',
    },
    paymentMode: { id: 2, name: 'Mobile Money — MTN' },
    payment_mode_id: 2,
  });

  return {
    helpers: HELPERS,
    scope: {
      errors,
      o: settled,
      items: grouped,
      current: {},
      remarks: {},
      modes,
      expressPct: 50,
      quote,
      tax,
      hotel: HOTEL,
      currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}

/** The desk list: what is with the laundry today and what each order will cost the guest. */
export function laundryIndexContext() {
  const rows = [
    order,
    withMethods({
      ...order,
      id: 42, order_no: 'LND-000409', type: 'walk_in', status: 'ready', pieces: 8, bag_no: 'B-2077', bag_pieces: 8,
      subtotal: 940, total: 940, speed: 'normal', guest_name: 'Kwesi Owusu', phone: '020 411 7733', room: null, room_id: null,
      collected_at: at('2026-11-04T15:40:00Z'), promised_at: at('2026-11-05T11:00:00Z'), ready_at: at('2026-11-05T10:20:00Z'),
      guest: null, guestRequest: null,
    }),
    withMethods({
      ...order,
      id: 43, order_no: 'LND-000415', type: 'guest', status: 'collected', pieces: 0, bag_no: 'B-2085', bag_pieces: 4,
      subtotal: 0, total: 0, room: { id: 305, room_no: '305' }, guest_name: 'Nana Adjei',
      collected_at: at('2026-11-05T08:05:00Z'), promised_at: at('2026-11-06T08:05:00Z'), lines: Coll.of([]),
    }),
  ];

  return {
    helpers: HELPERS,
    scope: {
      errors,
      list: new SamplePaginator(rows),
      tab: 'open',
      q: '',
      stats: { collected: 1, processing: 1, ready: 1, pieces: 14, overdue: 1, today: 834.9, month: 12480.5, requests: 2 },
      hotel: HOTEL,
      currentHotel: HOTEL,
    },
    fallbacks: {},
  };
}
