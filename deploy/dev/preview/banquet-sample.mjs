/**
 * Sample data for the Banquet booking form preview.
 *
 * The sandbox has no PHP or database, so the preview renders the real
 * resources/views/banquet/form.blade.php against this fixed sample. The shapes mirror
 * BanquetController::formData() and the `$fn` array that create()/edit() hand to the
 * view, so the form composes exactly as it does in the application — only the property
 * and the numbers are fixed.
 *
 * Two modes:
 *   new  — BanquetController::create(): an empty enquiry with one blank function
 *   edit — BanquetController::edit(): a tentative hold, two functions, one of them with
 *          the guarantee locked, and extras on the reception
 *
 * Nothing here invents behaviour. Where the screen does not know something — whether a
 * venue is already held on the night — the sample does not know it either, which is the
 * point: the check script asserts that the form validates capacity live and says nothing
 * about availability until the form is saved.
 */
import { Coll, SampleDate } from './blade-render.mjs';

const BUSINESS_DATE = '2026-11-05';
const day = iso => new SampleDate(`${iso}T09:00:00Z`);

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const nf = (v, places = 2) => Number(v ?? 0).toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places });
const money = (v, symbol = true) => (symbol ? `${HOTEL.currency_symbol} ${nf(v)}` : nf(v));

/** App\Models\BanquetFunction::SETUPS */
export const SETUPS = {
  theatre: 'Theatre',
  classroom: 'Classroom',
  banquet: 'Banquet (rounds)',
  ushape: 'U-shape',
  cocktail: 'Cocktail / standing',
  boardroom: 'Boardroom',
};

/** App\Models\BanquetEvent::TYPES */
export const EVENT_TYPES = {
  wedding: 'Wedding',
  reception: 'Reception',
  engagement: 'Engagement',
  conference: 'Conference',
  meeting: 'Meeting',
  corporate: 'Corporate function',
  gala: 'Gala / awards',
  training: 'Training / workshop',
  other: 'Other',
};

/** App\Models\BanquetEvent::SOURCES */
export const SOURCES = {
  phone: 'Phone',
  email: 'E-mail',
  walk_in: 'Walk-in',
  website: 'Website',
  agent: 'Agent / planner',
  repeat: 'Repeat client',
  referral: 'Referral',
};

/** App\Models\BanquetEvent::STATUSES */
export const STATUSES = {
  enquiry: 'Enquiry',
  tentative: 'Tentative',
  definite: 'Definite',
  completed: 'Completed',
  cancelled: 'Cancelled',
  lost: 'Lost',
};

/**
 * Venues, with the capacity each set-up style allows and the two rent cards. The numbers
 * are the ones a banquet manager would quote from: a ballroom that seats 350 on rounds is
 * a 600-standing room and a 220-classroom room, and the rent doubles after half a day.
 */
export const HALLS = [
  { id: 1, name: 'The Grand Ballroom', capacity: 400, caps: { theatre: 400, classroom: 220, banquet: 350, ushape: 90, cocktail: 600, boardroom: 60 }, half: 6500, full: 11000, sort: 1 },
  { id: 2, name: 'Oceanview Suite', capacity: 180, caps: { theatre: 180, classroom: 100, banquet: 150, ushape: 60, cocktail: 240, boardroom: 40 }, half: 3800, full: 6200, sort: 2 },
  { id: 3, name: 'Garden Pavilion', capacity: 200, caps: { theatre: 200, classroom: 120, banquet: 180, ushape: 70, cocktail: 300, boardroom: 45 }, half: 4200, full: 7000, sort: 3 },
  { id: 4, name: 'Conference Hall A', capacity: 90, caps: { theatre: 90, classroom: 60, banquet: 70, ushape: 40, cocktail: 120, boardroom: 34 }, half: 1900, full: 3200, sort: 4 },
  { id: 5, name: 'Boardroom', capacity: 18, caps: { theatre: 18, classroom: 12, banquet: 0, ushape: 16, cocktail: 30, boardroom: 18 }, half: 900, full: 1500, sort: 5 },
];

/** Menu packages, grouped on the form by type. min_pax is the package's own floor. */
export const PACKAGES = [
  { id: 11, name: 'Gold Buffet', type: 'buffet', price_per_pax: 320, min_pax: 50, tax_rate: 21, sort: 1,
    // What BanquetPackage::items holds in the database: one dish or inclusion per line.
    items: [
      'Jollof rice with grilled chicken',
      'Fried rice with beef in oyster sauce',
      'Banku and grilled tilapia with pepper relish',
      'Waakye with shito, egg and gari',
      'Assorted salads and coleslaw',
      'Kelewele and plantain chips',
      'Seasonal fruit platter',
      'Chocolate mousse and petits fours',
    ] },
  { id: 12, name: 'Silver Buffet', type: 'buffet', price_per_pax: 260, min_pax: 40, tax_rate: 21, sort: 2,
    items: ['Jollof rice with chicken', 'Fried plantain', 'Garden salad', 'Seasonal fruit', 'Soft drink'] },
  { id: 13, name: 'Plated three-course', type: 'plated', price_per_pax: 385, min_pax: 30, tax_rate: 21, sort: 3,
    items: ['Starter — smoked mackerel and avocado', 'Main — grilled guinea fowl, jollof and steamed vegetables', 'Dessert — coconut panna cotta'] },
  { id: 14, name: 'Plated four-course', type: 'plated', price_per_pax: 460, min_pax: 30, tax_rate: 21, sort: 4,
    items: ['Starter — prawn and mango salad', 'Soup — groundnut and chicken', 'Main — braised lamb, spiced rice and greens', 'Dessert — chocolate fondant'] },
  { id: 15, name: 'Cocktail reception', type: 'cocktail', price_per_pax: 210, min_pax: 60, tax_rate: 21, sort: 5,
    items: ['Chicken satay skewers', 'Mini meat pies and spring rolls', 'Prawn tartlets', 'Vegetable samosas', 'Sobolo and hibiscus coolers'] },
  { id: 16, name: 'Coffee break', type: 'coffee', price_per_pax: 65, min_pax: 20, tax_rate: 21, sort: 6,
    items: ['Coffee and tea station', 'Assorted pastries', 'Fruit skewers', 'Bottled water and juice'] },
  { id: 17, name: 'Gala dinner', type: 'plated', price_per_pax: 520, min_pax: 80, tax_rate: 21, sort: 7,
    items: ['Starter — seared scallops', 'Main — beef fillet with truffle mash', 'Dessert — gold-leaf chocolate torte', 'Petits fours and coffee'] },
  // Drinks packages: what the second package slot is for.
  { id: 18, name: 'House drinks package', type: 'beverage', price_per_pax: 95, min_pax: 50, tax_rate: 21, sort: 8,
    items: ['Beer, house wine and soft drinks', 'Sobolo and hibiscus cooler', 'Bottled water at every table', 'Bar open 30 minutes before dinner'] },
  { id: 19, name: 'Wine with dinner', type: 'beverage', price_per_pax: 140, min_pax: 30, tax_rate: 21, sort: 9,
    items: ['White and red wine poured with the main', 'Sparkling toast for the head table', 'No spirits'] },
  { id: 20, name: 'Premium open bar', type: 'beverage', price_per_pax: 210, min_pax: 80, tax_rate: 21, sort: 10,
    items: ['Full spirits bar with a named barman', 'Cocktail of the evening', 'Wine service throughout', 'Bar closes 30 minutes before the function ends'] },
];

/** Extras: audio-visual, décor, entertainment, furniture, staffing. */
export const SERVICES = [
  { id: 21, name: 'PA system + 2 microphones', category: 'av', price: 1800, unit: 'event', tax_rate: 15, sort: 1 },
  { id: 22, name: 'LED wall 4m × 2.5m', category: 'av', price: 5500, unit: 'event', tax_rate: 15, sort: 2 },
  { id: 23, name: 'Stage lighting package', category: 'av', price: 2400, unit: 'event', tax_rate: 15, sort: 3 },
  { id: 24, name: 'Table centrepieces', category: 'decor', price: 120, unit: 'table', tax_rate: 15, sort: 4 },
  { id: 25, name: 'Floral arch', category: 'decor', price: 3200, unit: 'event', tax_rate: 15, sort: 5 },
  { id: 26, name: 'Live band (4 pieces)', category: 'entertainment', price: 7500, unit: 'event', tax_rate: 15, sort: 6 },
  { id: 27, name: 'DJ + sound', category: 'entertainment', price: 3500, unit: 'event', tax_rate: 15, sort: 7 },
  { id: 28, name: 'Round table (10 seats)', category: 'equipment', price: 95, unit: 'table', tax_rate: 15, sort: 8 },
  { id: 29, name: 'Chiavari chair', category: 'equipment', price: 35, unit: 'chair', tax_rate: 15, sort: 9 },
  { id: 30, name: 'Usher / hostess', category: 'staffing', price: 320, unit: 'staff', tax_rate: 15, sort: 10 },
  { id: 31, name: 'Security officer', category: 'staffing', price: 380, unit: 'staff', tax_rate: 15, sort: 11 },
  { id: 32, name: 'Cloakroom attendant', category: 'other', price: 260, unit: 'staff', tax_rate: 15, sort: 12 },
];

export const DIETS = { vegetarian: 'Vegetarian', vegan: 'Vegan', gluten_free: 'Gluten-free', coeliac: 'Coeliac', nut_allergy: 'Nut allergy', other: 'Other' };

/** Holds that could collide with what the desk is typing: BanquetSalesService::busy(). */
export const BUSY = [
  { hall: 1, date: '2026-11-19', start: '19:00', end: '23:00', event: 'BQT-26039', title: 'Zenith Bank staff awards', status: 'definite', fn: 'Awards dinner' },
  { hall: 3, date: '2026-11-21', start: '12:00', end: '16:00', event: 'BQT-26044', title: 'Ministry of Tourism workshop', status: 'tentative', fn: 'Workshop' },
  { hall: 2, date: '2026-11-14', start: '18:00', end: '22:00', event: 'BQT-26037', title: 'Ashanti Gold gala', status: 'definite', fn: 'Gala dinner' },
  { hall: 4, date: '2026-12-02', start: '08:30', end: '12:30', event: 'BQT-26051', title: 'Ghana Revenue Authority briefing', status: 'tentative', fn: 'Briefing' },
];

export const SERVICE_CATEGORIES = { av: 'Audio-visual', decor: 'Décor & flowers', entertainment: 'Entertainment', equipment: 'Equipment & furniture', staffing: 'Staffing', other: 'Other' };

const CAPACITY_KEYS = Object.keys(SETUPS);

/** hallData — what the form's script uses to warn on capacity and to price the rent. */
export const hallData = () => Object.fromEntries(HALLS.map(hall => [hall.id, {
  cap: hall.capacity,
  caps: Object.fromEntries(CAPACITY_KEYS.map(style => [style, hall.caps[style] ?? 0])),
  half: hall.half,
  full: hall.full,
}]));

/** pkgData — p: rate per pax, min: package floor, t: tax rate. */
export const pkgData = () => Object.fromEntries(PACKAGES.map(pkg => [pkg.id, { p: pkg.price_per_pax, min: pkg.min_pax, t: pkg.tax_rate, n: pkg.name }]));

/** svcData — n: name, p: price, t: tax, u: unit (pax means qty follows the guest count). */
export const svcData = () => Object.fromEntries(SERVICES.map(svc => [svc.id, { n: svc.name, p: svc.price, t: svc.tax_rate, u: svc.unit }]));

const blankEvent = {
  exists: false, id: null, event_no: null, status: 'enquiry',
  title: null, event_type: 'wedding', source: 'phone',
  contact_name: null, contact_phone: null, contact_email: null,
  company_id: null, sales_user_id: 12, room_block_id: null,
  deposit_due: null, deposit_due_date: null,
  requirements: null, internal_notes: null,
  statusBadge: () => '<span class="badge badge-slate">Enquiry</span>',
};

/**
 * The held event. Two functions: a ceremony in the Garden Pavilion with the guarantee
 * already final, and a reception in the Grand Ballroom with the gold buffet and three extras.
 */
const EDIT_EVENT = {
  exists: true, id: 418, event_no: 'BQT-26041', status: 'tentative',
  title: 'Ama Serwaa & Kofi Boateng — wedding', event_type: 'wedding', source: 'referral',
  contact_name: 'Ama Serwaa Mensah', contact_phone: '+233 24 118 6620', contact_email: 'ama.serwaa@example.com',
  company_id: 3, sales_user_id: 12, room_block_id: 77,
  deposit_due: 42000, deposit_due_date: day('2026-11-12'),
  requirements: 'Traditional ceremony from 14:00 with the family elders seated at the front. Reception is a seated '
    + 'dinner for 180 with a live band; the family will provide the cake. Two guests are coeliac and one is '
    + 'wheelchair-dependent — please keep a clear route to the head table.',
  internal_notes: 'Option expires 21 Nov. Client is comparing us with the Ridge; the band is the deciding factor. '
    + 'Deposit of GH₵ 42,000 was promised for 12 Nov.',
  statusBadge: () => '<span class="badge badge-amber">Tentative</span>',
};

const FUNCTION_SPECS = {
  new: () => [{
    id: null, name: '', banquet_hall_id: null, date: '2026-11-19',
    start_time: '19:00', end_time: '23:00', setup_style: 'banquet',
    expected_pax: null, guaranteed_pax: null, banquet_package_id: null,
    rate_per_pax: null, hall_rate: null, notes: null, dietary: [], banquet_bar_package_id: null, bar_rate_per_pax: null, items: [], locked: false,
  }],
  // v10.35 the keying order a desk actually produces: the evening reception was booked first, the afternoon ceremony
  // second. The form prints them down the clock, so the ceremony reads as function 1 whatever the order they arrived in.
  edit: () => [
    {
      id: 4102, name: 'Reception & dinner', banquet_hall_id: 1, date: '2026-12-12',
      start_time: '19:00', end_time: '23:30', setup_style: 'banquet',
      expected_pax: 180, guaranteed_pax: 180, banquet_package_id: 11,
      rate_per_pax: 320, hall_rate: 11000, banquet_bar_package_id: 18, bar_rate_per_pax: 95, notes: 'Head table for 12 on the dais. Band to finish by 23:00 per the '
        + 'house rule; 600-standing for the cocktail hour before dinner.',
      locked: false,
      dietary: [
        { diet: 'vegetarian', covers: 12, dish: 'Vegetable jollof with grilled plantain' },
        { diet: 'coeliac', covers: 2, dish: 'Grilled fish, gluten-free plate' },
        { diet: 'nut_allergy', covers: 1, dish: 'No satay — plain grilled chicken' },
      ],
      items: [
        { banquet_service_id: 23, description: 'Stage lighting package', qty: 1, unit_price: 2400, tax_rate: 15 },
        { banquet_service_id: 24, description: 'Table centrepieces', qty: 18, unit_price: 120, tax_rate: 15 },
        { banquet_service_id: 26, description: 'Live band (4 pieces)', qty: 1, unit_price: 7500, tax_rate: 15 },
      ],
    },
    {
      id: 4101, name: 'Traditional ceremony', banquet_hall_id: 3, date: '2026-12-12',
      start_time: '14:00', end_time: '17:00', setup_style: 'theatre',
      expected_pax: 150, guaranteed_pax: 150, banquet_package_id: null,
      rate_per_pax: 0, hall_rate: 4200, banquet_bar_package_id: null, bar_rate_per_pax: 0, notes: 'Elders seated at the front; two chairs for the officiants. '
        + 'Family provides the drinks — no corkage to be charged. Guarantee closed three days out.',
      locked: true,
      dietary: [],
      items: [
        { banquet_service_id: 21, description: 'PA system + 2 microphones', qty: 1, unit_price: 1800, tax_rate: 15 },
        { banquet_service_id: 29, description: 'Chiavari chair', qty: 156, unit_price: 35, tax_rate: 15 },
      ],
    },
  ],
};

/**
 * @param {'new'|'edit'} mode
 */
export function banquetContext(mode = 'new') {
  const event = mode === 'edit' ? { ...EDIT_EVENT } : { ...blankEvent };
  const functions = FUNCTION_SPECS[mode]();
  const halls = Coll.of(HALLS.map(hall => ({ id: hall.id, name: hall.name })));
  const packages = Coll.of(PACKAGES.map(pkg => ({ ...pkg, itemList: () => (pkg.items ?? []) })));
  const services = Coll.of(SERVICES.map(svc => ({ ...svc })));
  const halfDayHours = 5;
  const hallTax = 21;

  const helperSet = {
    money,
    number_format: (v, places = 0) => nf(v, places),
    rtrim: (value, chars = ' ') => {
      let out = String(value ?? '');
      while (out.length && chars.includes(out[out.length - 1])) out = out.slice(0, -1);
      return out;
    },
    ucfirst: (value) => {
      const text = String(value ?? '');
      return text.charAt(0).toUpperCase() + text.slice(1);
    },
    old: (key, fallback = null) => fallback,
    route: (name, params) => {
      // these three take a query string, not a record
      const queryPath = { 'banquet.create': '/banquet/create', 'banquet.availability': '/banquet/availability', 'banquet.diary': '/banquet/diary' }[name];
      if (queryPath) {
        const query = Object.entries(params && typeof params === 'object' ? params : {})
          .filter(([, value]) => value !== null && value !== undefined && value !== '')
          .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`).join('&');
        return query ? `${queryPath}?${query}` : queryPath;
      }
      const id = params && typeof params === 'object' && 'id' in params ? params.id : (params ?? '');
      switch (name) {
        case 'banquet.index': return '/banquet';
        case 'banquet.store': return '/banquet';
        case 'banquet.show': return `/banquet/${id}`;
        case 'banquet.update': return `/banquet/${id}`;
        default: return '#';
      }
    },
    asset: (path) => `/${String(path ?? '').replace(/^\/+/, '')}`,
    can_do: () => true,
    setting: (key, fallback = null) => ({ 'banquet_half_day_hours': '5', 'banquet_hall_tax_rate': '21', 'banquet_deposit_pct': '25' }[key] ?? fallback),
    // config('pms.banquet_service_categories.'.$c, ucfirst($c)) — the item template labels its optgroups
    config: (key, fallback = null) => {
      const category = String(key ?? '').replace('pms.banquet_service_categories.', '');
      return SERVICE_CATEGORIES[category] ?? fallback;
    },
    auth: () => ({ id: () => 12, user: () => ({ name: 'Efua Danso' }) }),
    bdate: () => day(BUSINESS_DATE),
    now: () => new SampleDate('2026-11-05T09:14:22Z'),
    collect: (value) => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
    count: (value) => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
    empty: (value) => (value instanceof Coll ? value.isEmpty() : Array.isArray(value) ? value.length === 0 : !value),
    in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
    array_values: (value) => (value instanceof Coll ? value.items.slice() : Object.values(value ?? {})),
    array_merge: (...parts) => Object.assign({}, ...parts.map(part => part ?? {})),
    array_filter: (list, fn) => {
      const test = (value) => (typeof fn === 'function' ? fn(value) : Boolean(value));
      if (list instanceof Coll) return list.items.filter(test);
      if (Array.isArray(list)) return list.filter(test);
      // a keyed array — array_filter(['pax' => null, 'hall' => 2]) keeps the truthy entries
      return Object.fromEntries(Object.entries(list ?? {}).filter(([, value]) => test(value)));
    },
    array_slice: (list, offset, length) => (list instanceof Coll ? list.items : list ?? []).slice(offset, length === undefined ? undefined : offset + length),
    implode: (glue, list) => (list instanceof Coll ? list.items : list ?? []).join(glue),
    is_numeric: (value) => value !== null && value !== '' && !Number.isNaN(Number(value)),
    strval: (value) => String(value ?? ''),
    trim: (value) => String(value ?? '').trim(),
    round: (value, places = 0) => {
      const factor = 10 ** places;
      return Math.round(Number(value ?? 0) * factor) / factor;
    },
    max: (...values) => Math.max(...values.flat()),
    min: (...values) => Math.min(...values.flat()),
    abs: (value) => Math.abs(Number(value ?? 0)),
    __static: (cls, method, ...args) => {
      const owner = String(cls).split('\\').pop();
      if (owner === 'Carbon' && method === 'parse') {
        const value = args[0];
        return value instanceof SampleDate ? value : new SampleDate(`${String(value)}T09:00:00Z`);
      }
      if (owner === 'Str' && method === 'plural') return Number(args[1] ?? 0) === 1 ? args[0] : `${args[0]}s`;
      return args[0] ?? '';
    },
    // App\Models\BanquetEvent::TYPES / SOURCES / STATUSES, App\Models\BanquetFunction::SETUPS
    __const: (cls, name) => {
      const owner = String(cls).split('\\').pop();
      if (owner === 'BanquetEvent' && name === 'TYPES') return EVENT_TYPES;
      if (owner === 'BanquetEvent' && name === 'SOURCES') return SOURCES;
      if (owner === 'BanquetEvent' && name === 'STATUSES') return STATUSES;
      if (owner === 'BanquetFunction' && name === 'SETUPS') return SETUPS;
      if (owner === 'BanquetFunction' && name === 'DIETS') return DIETS;
      return [];
    },
    Math, Number, String, Boolean, Array, Object, Date, JSON, RegExp,
    parseFloat, parseInt, isNaN, isFinite, undefined: undefined, NaN: NaN, Infinity: Infinity,
  };

  return {
    helpers: helperSet,
    fallbacks: {},
    scope: {
      event,
      functions,
      halls,
      packages,
      services,
      companies: { 3: 'Ashanti Gold Ltd', 5: 'Zenith Bank Ghana', 8: 'Ministry of Tourism' },
      blocks: { 77: 'RB-2612 – Serwaa wedding block (12 rooms)' },
      salesUsers: { 12: 'Efua Danso', 14: 'Kwame Asare', 19: 'Nadia Owusu' },
      hallData: hallData(),
      pkgData: pkgData(),
      // the booking form answers the venue question as the desk types, from this list
      busy: BUSY,
      turnaround: 60,
      svcData: svcData(),
      halfDayHours,
      hallTax,
      // what the layout adds around the form
      title: mode === 'edit' ? `Edit ${EDIT_EVENT.event_no}` : 'New Banquet Enquiry',
    },
  };
}

/**
 * The venue search: BanquetController::availability() rendered.
 *
 *   mixed  date 2026-11-19, 19:00–23:00, 120 guests at rounds — one venue held by a
 *          definite wedding, two free, two too small for that set-up
 *   idle   the page before the desk has typed anything: the prompt, and the turn-around note
 */
export function availabilityContext(variant = 'mixed') {
  const base = banquetContext('new');
  const turnaround = 60;
  const searched = variant !== 'idle';
  const slot = { date: '2026-11-19', start: '19:00', end: '23:00', pax: searched ? 120 : 0, style: 'banquet' };
  const hours = (Number(slot.end.slice(0, 2)) * 60 + Number(slot.end.slice(3))) / 60
    - (Number(slot.start.slice(0, 2)) * 60 + Number(slot.start.slice(3))) / 60;

  // BUSY's hall-1 hold sits squarely on this slot; every other venue in BUSY is on another night
  const clash = BUSY.find(row => row.hall === 1 && row.date === slot.date);
  const heldFunction = clash && {
    id: 4121, name: clash.fn, banquet_hall_id: clash.hall, date: clash.date,
    start_time: clash.start, end_time: clash.end,
    timeRange: () => `${clash.start}–${clash.end}`,
    event: { event_no: clash.event, title: clash.title, contact_name: 'Zenith Bank Ghana', status: clash.status },
  };

  const rank = { free: 0, held: 1, small: 2 };
  const rows = searched ? HALLS.map(hall => {
    const capacity = hall.caps[slot.style] || hall.capacity;
    const held = clash && clash.hall === hall.id ? heldFunction : null;
    return {
      hall: { id: hall.id, name: hall.name },
      capacity,
      rent: hours > 5 ? hall.full : hall.half,
      held,
      status: held ? 'held' : (capacity > 0 && slot.pax > capacity ? 'small' : 'free'),
    };
  }).sort((a, b) => rank[a.status] - rank[b.status] || a.hall.id - b.hall.id) : [];
  const count = status => rows.filter(row => row.status === status).length;
  const firstFree = rows.find(row => row.status === 'free');

  return {
    helpers: base.helpers,
    fallbacks: base.fallbacks,
    scope: {
      ...base.scope,
      date: slot.date, start: slot.start, end: slot.end, pax: slot.pax, style: slot.style, searched,
      rows, freeCount: count('free'), heldCount: count('held'), smallCount: count('small'),
      firstFreeHall: firstFree ? firstFree.hall.id : null,
      turnaround,
      setups: SETUPS,
      title: 'Find a venue',
    },
  };
}
