/**
 * Sample data for the daily-briefing previews.
 *
 * The sandbox has no PHP or database, so the previews render the real
 * resources/views/briefings/*.blade.php against these fixed samples. The shapes mirror
 * BriefingService::pack() / exec(), so every block the service can emit — a band of stats,
 * a table with array cells, a list with tones, an empty state — is exercised here the same
 * way the application composes it on a live business date.
 *
 * The packs describe a normal trading Thursday at the resort: the house at 84 %, a good
 * F&B day, housekeeping still turning rooms, and a laundry that has not opened yet (the
 * empty-state screen).
 */
import { Coll, SampleDate, phpEmpty } from './blade-render.mjs';

export const HOTEL = { name: 'The Grove Essipong Beach Resort', code: 'GROVE', currency_symbol: 'GH₵' };

const BUSINESS_DATE = '2026-11-05';
const at = (iso) => new SampleDate(iso.includes('T') ? iso : `${iso}T09:00:00Z`);
const day = at(BUSINESS_DATE);

const HELPERS = {
  route: () => '#',
  asset: (path) => `/${String(path ?? '').replace(/^\/+/, '')}`,
  old: (key, fallback = null) => fallback,
  collect: (value) => (value instanceof Coll ? value : Coll.of(Array.isArray(value) ? value : Object.values(value ?? {}))),
  count: (value) => (value instanceof Coll ? value.count() : Array.isArray(value) ? value.length : value && typeof value === 'object' ? Object.keys(value).length : 0),
  empty: phpEmpty,
  in_array: (needle, haystack) => (haystack instanceof Coll ? haystack.items : haystack ?? []).includes(needle),
  is_array: (value) => Array.isArray(value),
  array_values: (value) => (Array.isArray(value) ? value.slice() : Object.values(value ?? {})),
  strtoupper: (value) => String(value ?? '').toUpperCase(),
  ucfirst: (value) => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
  implode: (glue, values) => (values instanceof Coll ? values.items : values ?? []).join(glue),
  round: (value, places = 0) => { const factor = 10 ** places; return Math.round(Number(value ?? 0) * factor) / factor; },
  abs: (value) => Math.abs(Number(value ?? 0)),
  csrf_field: () => '',
  money: (amount, symbol = true) => `${symbol ? 'GH₵ ' : ''}${Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
  money_short: (amount) => `GH₵ ${Number(amount).toLocaleString('en-US', { maximumFractionDigits: 0 })}`,
  number_format: (v, d = 0) => Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }),
  bdate: () => day,
  can_do: () => true,
  setting: (_k, d = null) => d,
  dmy: () => '05 Nov 2026',
  status_badge: () => '',
  hotel: () => HOTEL,
  auth: () => ({ user: { name: 'Yaa Boateng' }, id: () => 5 }),
  now: () => at('2026-11-05T07:40:00Z'),
  csrf_token: () => 'csrf',
};

// ── shape helpers, mirroring BriefingService::st()/table()/items()/li() ────────────────
const st = (label, value, sub = null, tone = 'slate', icon = 'grid') => ({ label, value, sub, tone, icon });
const stats = (key, title, note, rows) => ({ key, title, note, kind: 'stats', stats: rows, empty: null });
// The service stores headings as a list of { key, label } so the view and the CSV walk the
// same shape; the sample mirrors that exactly.
// Every cell is { text, tone } — the shape BriefingService::table() normalises to.
const cell = (value) => (value && typeof value === 'object'
  ? { text: String(value.text ?? '—'), tone: value.tone ?? null }
  : { text: String(value ?? '—'), tone: null });

const HEADING_OVERRIDES = { signoff: { note: 'Note' }, revenue: { note: 'Volume' },
  budget: { section: 'Section', budget: 'Budget MTD', actual: 'Actual MTD', variance: 'Variance', pct: 'Variance %' },
  pl: { metric: 'Line', ytd: 'Fiscal year to date' },
  ageing: { bucket: 'Bucket', amount: 'Amount' },
  attendance: { status: 'Status', people: 'People' },
  departments: { dept: 'Department', staff: 'On the books', shifts: 'Rostered', on: 'On duty', off: 'Absent' },
  leave: { employee: 'Employee', type: 'Type', from: 'From', to: 'To', days: 'Days' },
  ending: { employee: 'Employee', kind: 'Kind', ends: 'Ends', days: 'Days left' },
  kinds: { kind: 'Kind', cars: 'Cars', value: 'Charged' },
  zones: { zone: 'Zone', cars: 'Cars today', bays: 'In use', value: 'Charged' },
  curve: { hour: 'Window', cars: 'Cars' },
  permits: { holder: 'Holder', type: 'Type', expires: 'Expires', fee: 'Charge' },
  arriving: { guest: 'Guest', room: 'Room', nights: 'Nights', rate: 'Rate' },
  departing: { guest: 'Guest', room: 'Room', balance: 'Balance', state: 'At the desk' },
  vip: { guest: 'Guest', room: 'Room', when: 'Stay', flag: 'Status' },
  onsite: { guest: 'Visitor', host: 'Host', purpose: 'Purpose', since: 'In since' },
  keys: { key: 'Key', holder: 'Holder', out: 'Out since', due: 'Due back' },
  passes: { pass: 'Pass', party: 'Party', due: 'Due back', flag: 'Position' },
  incidents: { title: 'Incident', type: 'Type', place: 'Where', status: 'Status' },
  pass: { order: 'Order', item: 'Dish', qty: 'Qty', fired: 'Fired', wait: 'Waiting' },
  allergens: { order: 'Order', item: 'Dish', qty: 'Qty', state: 'State' },
  temps: { point: 'Reading point', reading: 'Reading', range: 'Safe range', at: 'Taken' },
  queue: { req: 'Requisition', dept: 'Department', need: 'Needed by', state: 'Position', value: 'Estimate' },
  deliveries: { grn: 'GRN', supplier: 'Supplier', value: 'Value', rejected: 'Rejected' },
  low: { item: 'Item', stock: 'In stock', level: 'Re-order at', suggest: 'Suggested' },
  auth: { auth: 'Request', kind: 'Kind', dates: 'Dates', amount: 'Value', asked: 'Asked by' },
  leakage: { type: 'Type', for: 'What for', area: 'Area', amount: 'Value' },
  overdue: { ticket: 'Ticket', job: 'Job', where: 'Where', due: 'Promised', late: 'Days late' },
  soon: { schedule: 'Schedule', due: 'Due', owner: 'Owner', priority: 'Priority' },
  priority: { priority: 'Priority', open: 'Open', oldest: 'Oldest due' },
  pm: { title: 'Schedule', due: 'Was due' },
  mix: { type: 'Room type', rooms: 'Rooms', adr: 'ADR', base: 'Base rate', position: 'Position' },
  pace: { day: 'Night', rooms: 'Rooms', occ: 'Occupancy', adr: 'ADR', revpar: 'RevPAR' },
  source: { source: 'Source', bookings: 'Bookings' },
  quiet: { number: 'Enquiry', account: 'Account', stage: 'Stage', value: 'Value', moved: 'Last moved' },
  won: { number: 'Enquiry', account: 'Account', nights: 'Room nights', value: 'Value', owner: 'Owner' },
  lost_detail: { number: 'Enquiry', account: 'Account', value: 'Value', reason: 'Reason' },
  urgent: { case: 'Case', about: 'About', area: 'Department', due: 'Due', owner: 'Owner' },
  written: { guest: 'Guest', score: 'Score', mood: 'Mood', said: 'They said' },
  reviews: { source: 'Source', rating: 'Rating', status: 'Status', replied: 'Replied' },
  sources: { source: 'Channel', count: 'Responses', avg: 'Average' },
  pipeline: { stage: 'Stage', count: 'Enquiries', nights: 'Room nights', value: 'Value' },
  agenda: { when: 'Time', subject: 'Subject', who: 'Account', owner: 'Owner', status: 'Status' },
  targets: { seller: 'Seller', nights: 'Room nights', revenue: 'Revenue', activities: 'Activities' },
  beos: { event: 'Event', title: 'What it is', hall: 'Space', starts: 'Starts', pax: 'Covers' },
  unbilled: { event: 'Event', client: 'Client', closed: 'Closed', pax: 'Guaranteed', value: 'Value' },
  broken: { system: 'System', command: 'Command', when: 'When', tries: 'Tries', answer: 'What came back' },
  endpoints: { endpoint: 'Endpoint', event: 'Event', code: 'Code', tries: 'Tries' },
  today: { event: 'Event', title: 'What it is', hall: 'Space', pax: 'Covers', value: 'Value', status: 'Status' },
  week: { when: 'Date', count: 'Events', pax: 'Guests' },
  interfaces: { system: 'System', commands: 'Commands', failed: 'Failed' },
  webhooks: { status: 'Result', count: 'Deliveries', ms: 'Average ms' },
  mv: { type: 'Type', trips: 'Trips', pax: 'Passengers', done: 'Completed', open: 'Still to run', value: 'Revenue' },
  board: { vehicle: 'Vehicle', type: 'Type', seats: 'Seats', trips: 'Trips today', km: 'Km today', state: 'Papers' },
  crew: { driver: 'Driver', licence: 'Licence', trips: 'Trips', out: 'Time out', state: 'Standing' },
  billing: { how: 'Billing', trips: 'Trips', value: 'Value', note: 'What it means' },
  cost: { kind: 'Cost', count: 'Entries', litres: 'Litres', amount: 'Spent' },
  workshop: { vehicle: 'Vehicle', job: 'Job', due: 'Due', priority: 'Priority', est: 'Estimate' },
  faults: { when: 'When', what: 'What', vehicle: 'Vehicle', state: 'State' },
  rentals: { rental: 'Agreement', customer: 'Customer', due: 'Due back', value: 'Value', state: 'State' },
  tender: { mode: 'Mode', count: 'Payments', amount: 'Amount', cash: 'Kind' },
  type: { item: 'Item', qty: 'Qty', net: 'Net', tax: 'Tax', total: 'Charged' },
  passes: { pass: 'Pass', guest: 'Guest', kind: 'Kind', pax: 'Guests', value: 'Paid', state: 'At the gate' },
  sales: { sale: 'Sale', time: 'Time', guest: 'Guest', pax: 'Guests', tender: 'Tender', total: 'Total', state: 'State' },
  category: { category: 'Category', qty: 'Qty', net: 'Net', tax: 'Tax' },
  shift: { shift: 'Shift', opened: 'Opened', closed: 'Closed', float: 'Float', expected: 'Expected cash', counted: 'Counted', variance: 'Variance' },
  tax: { tax: 'Tax', authority: 'Payable to', rate: 'Rate', amount: 'Collected' },
  cashiers: { name: 'Cashier', sales: 'Sales', total: 'Taken' } };

const table = (key, title, note, cols, rows, empty) => ({
  key, title, note, kind: 'table', empty,
  cols: cols.map(column => ({ key: column, label: HEADING_OVERRIDES[key]?.[column] ?? HEADINGS[column] ?? column })),
  rows: rows.map(row => Object.fromEntries(cols.map(column => [column, cell(row[column])]))),
});

const HEADINGS = {
  outlet: 'Outlet', bills: 'Bills', covers: 'Covers', gross: 'Gross', discount: 'Discount', service: 'Service',
  net: 'Net', avg: 'Avg / cover', mode: 'Mode', count: 'Payments', amount: 'Amount', item: 'Item', qty: 'Qty',
  type: 'Task', due: 'Raised', done: 'Closed', open: 'Open', credits: 'Credits', moves: 'Entries',
  status: 'Status', orders: 'Orders', value: 'Value', entries: 'Entries', metric: 'Metric', day: 'Today',
  mtd: 'Month to date', ytd: 'Year to date', dept: 'Department', watch: 'Open', signed: 'Signed off',
  source: 'Department', share: 'Share', note: 'Volume', date: 'Business date', rooms: 'Rooms', occ: 'Occupancy',
  adr: 'ADR', revpar: 'RevPAR', revenue: 'Room revenue', collected: 'Collected', account: 'Debtor',
  b0: 'Current', b30: '1–30 d', b60: '31–60 d', b90: '60 d +', total: 'Outstanding', head: 'Head',
  by: 'Signed by', at: 'At',
};
const list = (key, title, note, items, empty) => ({ key, title, note, kind: 'list', items, empty });
const li = (text, value = null, tone = 'slate') => ({ text, value, tone });

const HOUSE = stats('house', 'The house today', 'Room nights sold and revenue as posted on the business date.', [
  st('Occupancy', '84.2 %', '▲ 6.1 pts vs last week', 'green', 'bed'),
  st('Rooms sold', '109', 'of 129 available', 'slate', 'bed'),
  st('Guests in house', '218', '109 rooms occupied', 'purple', 'users'),
  st('Room revenue', 'GH₵ 158,410.00', '▲ 12,240.00 vs last week', 'green', 'money'),
  st('Arrivals / departures', '31 / 26', '2 no-show, 3 cancelled', 'amber', 'swap'),
  st('F&B revenue', 'GH₵ 46,880.00', '▲ 3,110.00 vs last week', 'teal', 'cup'),
]);

// ── the 18 department packs, as the hub lists them ────────────────────────────────────
const PACKS = [
  ['frontoffice', 'Front Office', 'Front Office Manager', 'bell', true, 'Akosua Mensah', '09:12', 'Two rooms released late — noted.'],
  ['housekeeping', 'Housekeeping', 'Executive Housekeeper', 'broom', true, 'Efua Danso', '08:40', null],
  ['fnb', 'Food & Beverage', 'F&B Manager', 'cup', true, 'Kwame Asare', '10:05', 'Beach bar fridge 2 down — engineering called.'],
  ['kitchen', 'Kitchen', 'Executive Chef', 'coffee', true, 'Chef Ibrahim Musah', '10:20', null],
  ['banquet', 'Banquet & Events', 'Banquet Manager', 'layers', false, null, null, null],
  ['sales', 'Sales & Marketing', 'Director of Sales & Marketing', 'trend', true, 'Nana Adjei', '08:05', null],
  ['revenue', 'Revenue', 'Revenue Manager', 'chart', true, 'Selorm Agbeko', '07:55', null],
  ['recreation', 'Spa & Recreation', 'Recreation Manager', 'dumbbell', false, null, null, null],
  ['laundry', 'Laundry & Linen', 'Laundry Manager', 'shirt', false, null, null, null],
  ['maintenance', 'Engineering', 'Chief Engineer', 'tool', true, 'Kofi Mensah', '08:15', 'Chiller 2 on bypass.'],
  ['security', 'Security', 'Security Manager', 'shield', true, 'Daniel Owusu', '06:50', null],
  ['hr', 'People & HR', 'Human Resources Manager', 'users', false, null, null, null],
  ['finance', 'Finance & Accounts', 'Financial Controller', 'wallet', true, 'Ama Boakye', '09:50', 'Drawer 3 short GH₵ 40 — being followed up.'],
  ['guestexp', 'Guest Experience', 'Guest Relations Manager', 'star', true, 'Adjoa Sarpong', '09:35', null],
  ['it', 'IT & Systems', 'IT Manager', 'wifi', false, null, null, null],
  ['stores', 'Purchasing & Stores', 'Purchasing & Stores Manager', 'box', false, null, null, null],
  ['fleet', 'Transport & Fleet', 'Transport Supervisor', 'car', true, 'Yaw Antwi', '07:30', null],
  ['parking', 'Parking & Valet', 'Parking Supervisor', 'truck', false, null, null, null],
];

// How often each head has signed over the last seven business dates. Eleven have signed
// today; these are the days behind that, which is what the compliance band reports.
const WEEK = {
  frontoffice: 7, housekeeping: 6, fnb: 7, kitchen: 6, banquet: 0, sales: 7, revenue: 7, recreation: 2,
  laundry: 4, maintenance: 6, security: 7, hr: 3, finance: 6, guestexp: 5, it: 4, stores: 3, fleet: 7, parking: 2,
};

const packIndex = (overrides = {}) => Coll.of(PACKS.map(([key, label, role, icon, signed, signedBy, signedAt, note]) => ({
  key, label, role, icon, signed, signed_by: signedBy, signed_at: signedAt, note, can: true,
  brief: overrides[key] ?? `${role} daily pack.`,
  week_signed: WEEK[key], week_days: 7,
})));

// The brief line the hub prints under each card — the same text as the service registry.
const BRIEFS = {
  frontoffice: 'Arrivals still to run and departures with the balance their folio still carries, the house tonight, cashiering at the desk and the guest requests the desk still owns.',
  housekeeping: 'Where the rooms stand, what the floor still has to turn, inspections, linen and lost property.',
  fnb: 'Outlet sales and covers, how guests paid, leakage through voids, comps and discounts, and the open bills.',
  kitchen: 'Tickets fired and cleared, what is still on the pass, dish times, what sold, the allergen lines a chef must verify, the cold chain and the kitchen checklists.',
  banquet: 'Events on the floor today, the event orders still unpublished, the closed events not yet billed and what is chasing on deposits.',
  sales: 'Pipeline by stage, the enquiries that have gone quiet, what was won and lost today with the reason recorded, and each seller against target.',
  revenue: 'The fortnight on the books, the mix against each base rate, the rate decisions waiting for a ruling and every discount or allowance given away today.',
  recreation: 'Takings by tender and line by line, every sale on the till roll, the passes sold against the guests the gate actually admitted, the shift drawer with its float, expected cash and variance, and the tax split the way the return is filed.',
  laundry: 'Orders taken and delivered, the wash still in hand, guest vs house linen and the money on the desk.',
  maintenance: 'Jobs raised and closed, what is past its promise with the days late, the work already planned for the week and the rooms held out of the sale.',
  security: 'Visitors on the door and everyone still inside, gate passes out and back, incidents logged and every master key off the board.',
  hr: 'Who is on against the roster, department by department, the leave queue, the contracts and probation running out, and the people decisions waiting.',
  finance: 'Money taken and how, receipts issued, receivables ageing, payables due, the day\'s profit against budget and the audit exceptions open.',
  guestexp: 'Scores and net promoter, whether every review was answered, the recovery cases ranked by severity and what the guests actually wrote.',
  it: 'Every interface command that failed today and what came back, the endpoints not taking our updates, kitchen tickets that never printed and the night run.',
  stores: 'What the stores issued, stock at re-order level with the quantity suggested, requisitions waiting at the counter and the deliveries that came in.',
  fleet: 'Trips run and still to run with the passengers on them, which car can legally move, the drivers and their licences, how each trip was billed and what is still to post, fuel against kilometres, the workshop and open defects, and the rentals out on the road.',
  parking: 'Cars in the bays zone by zone, when they came in, valet calls answered, revenue collected and permits expiring.',
};

const signoffFor = (name, time, note = null) => ({ user: { name }, signed_at: at(`${BUSINESS_DATE}T${time}:00Z`), note });

// ── F&B: a full trading day ────────────────────────────────────────────────────────────

// The profit and budget bands are printed by the executive brief and the finance pack, so the
// sample builds them once, exactly as BriefingService::plSection()/budgetSection() do.
const plTable = () => (
table('pl', 'Profit, day · month · year', 'Income and cost as the general ledger holds them, for FY 2026-27 — the same classification the budget uses.',
  ['metric', 'day', 'mtd', 'ytd'], [
    { metric: 'Operating revenue', day: '218,744.00', mtd: '4,339,180.00', ytd: '31,904,220.00' },
    { metric: 'Cost of sales', day: '62,140.00', mtd: '1,238,900.00', ytd: '9,118,400.00' },
    { metric: 'Operating expenses', day: '96,880.00', mtd: '1,941,260.00', ytd: '14,742,180.00' },
    { metric: { text: 'Gross operating profit', tone: 'slate' }, day: { text: '59,724.00', tone: 'green' }, mtd: { text: '1,159,020.00', tone: 'green' }, ytd: { text: '8,043,640.00', tone: 'green' } },
    { metric: 'Other income', day: '4,200.00', mtd: '82,400.00', ytd: '640,200.00' },
    { metric: 'Non-operating expenses', day: '12,400.00', mtd: '244,800.00', ytd: '1,884,600.00' },
    { metric: { text: 'Net profit', tone: 'slate' }, day: { text: '51,524.00', tone: 'green' }, mtd: { text: '996,620.00', tone: 'green' }, ytd: { text: '6,799,240.00', tone: 'green' } },
    { metric: 'Net margin', day: '23.6 %', mtd: '23.0 %', ytd: '21.3 %' },
  ], 'Nothing has been posted to the general ledger for this date.')
);

const budgetTable = () => (
table('budget', 'Budget against actual, month to date', 'Approved budget BUD-2026-01 · FY 2026-27 operating budget · November. Positive variance is favourable: revenue above budget, cost below it.',
  ['section', 'budget', 'actual', 'variance', 'pct'], [
    { section: 'Operating revenue', budget: '4,180,000.00', actual: '4,339,180.00', variance: { text: '+159,180.00', tone: 'green' }, pct: { text: '+3.8 %', tone: 'green' } },
    { section: 'Cost of sales', budget: '1,260,000.00', actual: '1,238,900.00', variance: { text: '+21,100.00', tone: 'green' }, pct: { text: '+1.7 %', tone: 'green' } },
    { section: 'Operating expenses', budget: '1,890,000.00', actual: '1,941,260.00', variance: { text: '−51,260.00', tone: 'red' }, pct: { text: '−2.7 %', tone: 'red' } },
    { section: 'Other income', budget: '75,000.00', actual: '82,400.00', variance: { text: '+7,400.00', tone: 'green' }, pct: { text: '+9.9 %', tone: 'green' } },
    { section: 'Non-operating expenses', budget: '250,000.00', actual: '244,800.00', variance: { text: '+5,200.00', tone: 'green' }, pct: { text: '+2.1 %', tone: 'green' } },
    { section: { text: 'Net profit', tone: 'slate' }, budget: '855,000.00', actual: { text: '996,620.00', tone: 'green' }, variance: { text: '+141,620.00', tone: 'green' }, pct: { text: '+16.6 %', tone: 'green' } },
  ], 'No approved budget is active for this fiscal year, so the month cannot be compared with one.')
);

function fnbPack() {
  const outlets = [
    { outlet: 'Beach Bar', bills: '48', covers: '96', gross: '18,420.00', discount: '320.00', service: '1,843.20', net: '24,338.00', avg: '253.52' },
    { outlet: 'Terrace Restaurant', bills: '36', covers: '81', gross: '14,905.00', discount: '0.00', service: '1,490.50', net: '19,876.00', avg: '245.38' },
    { outlet: 'Pool Grill', bills: '22', covers: '39', gross: '4,120.00', discount: '120.00', service: '412.00', net: '4,702.00', avg: '120.56' },
    { outlet: 'Lobby Lounge', bills: '13', covers: '21', gross: '2,010.00', discount: '0.00', service: '201.00', net: '2,678.00', avg: '127.52' },
  ];
  const tender = [
    { mode: 'Room charge', count: '64', amount: '31,204.00' },
    { mode: 'Mobile Money — MTN', count: '21', amount: '8,330.00' },
    { mode: 'Cash', count: '26', amount: '6,142.00' },
    { mode: 'Visa / Mastercard', count: '7', amount: '5,180.00' },
    { mode: 'Bank transfer', count: '1', amount: '738.00' },
  ];
  const top = [
    { item: 'Grilled tilapia & banku', qty: '42', amount: '6,510.00' },
    { item: 'Jollof with grilled chicken', qty: '38', amount: '4,940.00' },
    { item: 'Club sandwich', qty: '31', amount: '2,635.00' },
    { item: 'Fresh coconut', qty: '29', amount: '1,015.00' },
    { item: 'Beef khebab (plate)', qty: '24', amount: '2,400.00' },
    { item: 'Red Red with plantain', qty: '19', amount: '1,805.00' },
    { item: 'Pineapple juice', qty: '18', amount: '720.00' },
    { item: 'Chocolate fondant', qty: '14', amount: '1,050.00' },
  ];

  return {
    key: 'fnb', label: 'Food & Beverage', role: 'F&B Manager', icon: 'cup', perm: 'fnb.view',
    title: 'F&B Manager — daily briefing', date: day, is_exec: false,
    signoff: signoffFor('Kwame Asare', '10:05', 'Beach bar fridge 2 down — engineering called.'),
    headline: [
      st('Net sales', 'GH₵ 51,594.00', '▲ 3,110.00 vs last week', 'green', 'money'),
      st('Covers', '237', '119 bills', 'blue', 'users'),
      st('Average cover', 'GH₵ 217.70', 'net of service and tax', 'purple', 'receipt'),
      st('Taken directly', 'GH₵ 20,390.00', 'cash, card, MoMo at the till', 'teal', 'wallet'),
      st('Charged to rooms', 'GH₵ 31,204.00', 'posted to guest folios', 'slate', 'bed'),
      st('Leakage', 'GH₵ 440.00', '2 bills voided', 'amber', 'alert'),
    ],
    house: HOUSE,
    sections: [
      table('outlets', 'By outlet', 'Settled bills on the business date, net of discount and including service charge and tax.',
        ['outlet', 'bills', 'covers', 'gross', 'discount', 'service', 'net', 'avg'], outlets, 'No outlet bill has been settled on this date.'),
      table('tender', 'How guests paid', 'Payments taken at the outlets. Room charges are collected through the folio instead.',
        ['mode', 'count', 'amount'], tender, 'No direct payment was taken at the outlets on this date.'),
      table('top', 'What sold', 'The dishes and drinks that carried the day, by value.',
        ['item', 'qty', 'amount'], top, 'No item was sold on this date.'),
      list('leakage', 'Leakage and open bills', 'Where margin goes and what is still unpaid at the outlets.', [
        li('2 bills voided', 'GH₵ 440.00', 'red'),
        li('Complimentary bills', 'GH₵ 1,120.00', 'amber'),
        li('Discounts given', 'GH₵ 440.00', 'amber'),
        li('4 bills still open', 'GH₵ 2,880.00', 'red'),
        li('Staff meals', '12 covers · GH₵ 540.00', 'slate'),
      ], 'No leakage recorded and no bill is open.'),
    ],
    watch: [
      '4 outlet bills are still open at GH₵ 2,880.00',
      '2 bills were voided — check the reasons before close',
      'Beach bar fridge 2 is not holding temperature',
    ],
    todo: ['Close or post the 4 open bills.', 'Check the void sheets against the till before the shift closes.', 'Reconcile the direct takings to the cashier shift drawer.'],
    sources: 'pos_orders, pos_order_items, pos_payments, fb_staff_meals, outlets',
  };
}

// ── Housekeeping: the floor at 08:40 ──────────────────────────────────────────────────
function housekeepingPack() {
  return {
    key: 'housekeeping', label: 'Housekeeping', role: 'Executive Housekeeper', icon: 'broom', perm: 'housekeeping.view',
    title: 'Executive Housekeeper — daily briefing', date: day, is_exec: false,
    signoff: signoffFor('Efua Danso', '08:40'),
    headline: [
      st('Rooms ready', '96', 'clean or inspected of 129', 'green', 'bed'),
      st('Still to turn', '27', 'dirty or touch-up', 'amber', 'broom'),
      st('Tasks closed', '71 / 108', '58 inspected clean', 'blue', 'check'),
      st('Awaiting inspection', '11', '2 failed inspection today', 'amber', 'eye'),
      st('Out of order', '6', 'rooms withdrawn from sale', 'red', 'alert'),
      st('Credits earned', '71.5', '26 min average per room', 'purple', 'star'),
    ],
    house: HOUSE,
    sections: [
      table('types', 'The floor by task type', 'Every task raised for the business date, and how much of it is closed.',
        ['type', 'due', 'done', 'open', 'credits'], [
          { type: 'Departure clean', due: '26', done: '21', open: { text: '5', tone: 'amber' }, credits: '31.5' },
          { type: 'Stay-over clean', due: '68', done: '46', open: { text: '22', tone: 'amber' }, credits: '34.0' },
          { type: 'Turndown', due: '9', done: '2', open: { text: '7', tone: 'amber' }, credits: '4.5' },
          { type: 'Public area', due: '4', done: '2', open: { text: '2', tone: 'amber' }, credits: '1.5' },
          { type: 'Inspection', due: '1', done: '0', open: { text: '1', tone: 'amber' }, credits: '0.0' },
        ], 'No housekeeping task has been raised for this date.'),
      table('linen', 'Linen moved', 'Par stock in and out of the floors and the laundry on the business date.',
        ['type', 'moves', 'qty'], [
          { type: 'Soiled collected', moves: '18', qty: '1,240' },
          { type: 'Sent to laundry', moves: '14', qty: '1,180' },
          { type: 'Returned clean', moves: '11', qty: '960' },
          { type: 'Issued to floors', moves: '22', qty: '1,020' },
        ], 'No linen movement has been recorded on this date.'),
      list('watch', 'What the housekeeper watches', 'The exceptions that decide whether the block can be sold tonight.', [
        li('3 rooms on do-not-disturb', 'leave until the guest clears it', 'amber'),
        li('2 re-cleans', 'room failed inspection after cleaning', 'amber'),
        li('2 inspections failed today', 'coach and re-inspect', 'red'),
        li('4 lost-property items found today', 'log and store', 'slate'),
        li('37 items still in lost property', '6 of them valuable', 'slate'),
        li('6 rooms out of order', 'engineering and housekeeping to release', 'red'),
      ], 'Nothing flagged for the housekeeper.'),
    ],
    watch: ['27 rooms are not ready for sale', '11 cleaned rooms are waiting for an inspection', '2 rooms failed inspection today'],
    todo: ['Inspect the 11 rooms waiting, then release them.', "Confirm the linen par stock for tomorrow's arrivals.", 'Hand the dirty-room list to the evening shift with the departures sheet.'],
    sources: 'rooms, housekeeping_tasks, linen_movements, lost_found_items, room_outages',
  };
}

// ── Laundry at 06:40: nothing in yet — every empty state ──────────────────────────────
function laundryEarlyPack() {
  return {
    key: 'laundry', label: 'Laundry & Linen', role: 'Laundry Manager', icon: 'shirt', perm: 'laundry.view',
    title: 'Laundry Manager — daily briefing', date: day, is_exec: false, signoff: null,
    headline: [
      st('Orders taken', '0', '0 pieces', 'blue', 'shirt'),
      st('Delivered today', '0', '0 pieces', 'slate', 'check'),
      st('Still in hand', '3', 'GH₵ 186.00 of work', 'amber', 'clock'),
      st('Revenue', 'GH₵ 0.00', "on the orders taken today", 'slate', 'money'),
      st('Paid at the desk', 'GH₵ 0.00', 'the rest charged to rooms', 'slate', 'wallet'),
      st('Unsettled', '0', 'GH₵ 0.00 collected but unpaid', 'green', 'alert'),
    ],
    house: HOUSE,
    sections: [
      table('queue', 'The wash in hand', 'Orders taken but not yet back with the guest.',
        ['status', 'orders', 'value'], [
          { status: 'Collected', orders: '1', value: '64.00' },
          { status: 'Processing', orders: '1', value: '82.00' },
          { status: 'Ready', orders: '1', value: '40.00' },
        ], 'Nothing is in the wash.'),
      table('linen', 'House linen through the laundry', 'What the floors and the laundry moved on the business date.',
        ['type', 'entries', 'qty'], [], 'No linen movement recorded on this date.'),
      list('desk', 'The desk side', 'What the guest is told and what the cashier has to collect.', [],
        'Nothing for the desk to chase.'),
    ],
    watch: [],
    todo: ['Check the promised times on every order still in hand.', 'Settle or post the orders the guest has collected.', 'Reconcile the linen count for the floors before the shift ends.'],
    sources: 'laundry_orders, laundry_order_lines, linen_movements',
  };
}

// ── the executive brief ───────────────────────────────────────────────────────────────
function execPack() {
  const flashRows = [
    { metric: 'Rooms available', day: '129', mtd: '2,709', ytd: '26,451' },
    { metric: 'Room nights sold', day: '109', mtd: '2,214', ytd: '21,884' },
    { metric: 'Occupancy %', day: '84.5%', mtd: '81.7%', ytd: '82.7%' },
    { metric: 'Guests in house (end of period)', day: '218', mtd: '218', ytd: '218' },
    { metric: 'Room revenue', day: '158,410.00', mtd: '3,142,880.00', ytd: '30,118,440.00' },
    { metric: 'ADR (avg. daily rate)', day: '1,453.30', mtd: '1,419.53', ytd: '1,376.26' },
    { metric: 'RevPAR', day: '1,227.98', mtd: '1,160.13', ytd: '1,138.74' },
    { metric: 'F&B revenue (room-posted + outlet)', day: '51,594.00', mtd: '1,032,140.00', ytd: '9,884,220.00' },
    { metric: 'Other revenue', day: '9,180.00', mtd: '182,400.00', ytd: '1,704,300.00' },
    { metric: 'Discounts & allowances', day: '440.00', mtd: '18,240.00', ytd: '212,880.00' },
    { metric: 'Net revenue (excl. tax)', day: '218,744.00', mtd: '4,339,180.00', ytd: '41,494,080.00' },
    { metric: 'Taxes collected', day: '33,268.00', mtd: '659,842.00', ytd: '6,310,660.00' },
    { metric: 'Collections (cash, card, UPI…)', day: '196,420.00', mtd: '4,102,880.00', ytd: '39,884,120.00' },
    { metric: 'Arrivals', day: '31', mtd: '648', ytd: '6,204' },
    { metric: 'Departures', day: '26', mtd: '612', ytd: '5,988' },
    { metric: 'No-shows', day: '2', mtd: '14', ytd: '128' },
    { metric: 'Cancellations', day: '3', mtd: '41', ytd: '396' },
    { metric: 'POS bills', day: '119', mtd: '2,486', ytd: '24,118' },
  ];

  const scorecard = PACKS.map(([key, label, role, icon, signed, signedBy, signedAt]) => {
    const headline = {
      frontoffice: ['In house', '109 rooms', 'blue'],
      housekeeping: ['Rooms ready', '96', 'green'],
      fnb: ['Net sales', 'GH₵ 51,594.00', 'green'],
      kitchen: ['Tickets fired', '132', 'green'],
      banquet: ['Events today', '2', 'blue'],
      sales: ['Open pipeline', 'GH₵ 1,284,000.00', 'blue'],
      revenue: ['Tonight', '84.5 %', 'green'],
      recreation: ['Takings', 'GH₵ 4,180.00', 'green'],
      laundry: ['Orders taken', '0', 'blue'],
      maintenance: ['Jobs raised', '9', 'blue'],
      security: ['Visitors booked in', '41', 'blue'],
      hr: ['Headcount', '184', 'blue'],
      finance: ['Collected', 'GH₵ 196,420.00', 'green'],
      guestexp: ['Feedback received', '18', 'blue'],
      it: ['Interface commands', '316', 'green'],
      stores: ['Issued today', 'GH₵ 22,140.00', 'green'],
      fleet: ['Trips today', '14', 'blue'],
      parking: ['Cars in today', '63', 'blue'],
    }[key];
    return {
      dept: label, metric: headline[0], value: { text: headline[1], tone: headline[2] },
      watch: { text: ['2', '0', '3', '1', '0', '2', '0', '1', '0', '4', '1', '2', '3', '1', '1', '2', '0', '1'][PACKS.findIndex(p => p[0] === key)] ?? '0', tone: 'amber' },
      signed: signed ? { text: `${signedBy} · ${signedAt}`, tone: 'green' } : { text: 'Not yet', tone: 'red' },
    };
  });

  return {
    key: 'exec', label: 'Executive brief', role: 'General Manager & CEO', icon: 'hotel', perm: 'reports.exec',
    title: 'Daily executive brief — General Manager & CEO', date: day, is_exec: true,
    signed_count: 11, pack_count: 18,
    headline: [
      st('Occupancy', '84.5 %', '▲ 6.1 pts vs last week', 'green', 'bed'),
      st('ADR', 'GH₵ 1,453.30', '▲ 42.10 vs last week', 'green', 'money'),
      st('RevPAR', 'GH₵ 1,227.98', '▲ 96.40 vs last week', 'green', 'chart'),
      st('Net revenue today', 'GH₵ 218,744.00', '▲ 18,240.00 vs last week', 'green', 'trend'),
      st('Month to date', 'GH₵ 4,339,180.00', '▲ 212,400.00 vs last month', 'green', 'layers'),
      st('Collected today', 'GH₵ 196,420.00', '97 receipts', 'green', 'wallet'),
      st('Receivables', 'GH₵ 428,160.00', 'GH₵ 94,220.00 older than 30 days', 'amber', 'receipt'),
      st('Guest score', '4.4 / 5', '26 responses today', 'purple', 'star'),
    ],
    sections: [
      table('flash', 'The numbers, day · month · year', "The manager's flash report for the business date, cut from source transactions.",
        ['metric', 'day', 'mtd', 'ytd'], flashRows, 'No figure has been posted yet.'),
      table('depts', 'Department scorecard', "Each head's day in one line, and whether they have signed for it.",
        ['dept', 'metric', 'value', 'watch', 'signed'], scorecard, 'No department has filed a briefing.'),
      table('compliance', 'Sign-off compliance, last seven business dates', 'Whether each head has signed their briefing over the week, not only today.',
        ['dept', 'week', 'last', 'state'], PACKS.map(([key, label, , , signed]) => {
          const n = WEEK[key];
          return {
            dept: label,
            week: { text: `${n} / 7`, tone: n === 7 ? 'green' : n === 0 ? 'red' : 'amber' },
            last: signed ? 'Thu 5 Nov' : n === 0 ? { text: 'Never', tone: 'red' } : 'Tue 3 Nov',
            state: n === 7 ? { text: 'Every day', tone: 'green' }
              : n === 0 ? { text: 'Never signed', tone: 'red' }
                : { text: signed ? `${n} days running` : 'Lapsed', tone: signed ? 'amber' : 'red' },
          };
        }), 'No head has signed a briefing in the last week.'),
      table('revenue', 'Where the revenue came from', 'Every revenue-producing department on the business date, net of tax.',
        ['source', 'amount', 'share', 'note'], [
          { source: 'Rooms', amount: '158,410.00', share: '72.4 %', note: '109 room nights' },
          { source: 'Food & beverage', amount: '51,594.00', share: '23.6 %', note: '119 bills' },
          { source: 'Banquet & events', amount: '4,200.00', share: '1.9 %', note: '2 events' },
          { source: 'Spa & recreation', amount: '2,940.00', share: '1.3 %', note: '18 sales' },
          { source: 'Transport', amount: '980.00', share: '0.4 %', note: 'trips billed' },
          { source: 'Parking', amount: '460.00', share: '0.2 %', note: 'cars charged on exit' },
          { source: 'Laundry', amount: '160.00', share: '0.1 %', note: 'guest laundry' },
        ], 'No revenue has been posted today.'),
      plTable(),
      budgetTable(),
      table('cash', 'Cash and banking', 'What came in, how, and whether the drawers balanced.',
        ['mode', 'count', 'amount'], [
          { mode: 'Visa / Mastercard', count: '31', amount: '92,180.00' },
          { mode: 'Mobile Money — MTN', count: '38', amount: '61,240.00' },
          { mode: 'Cash', count: '24', amount: '34,860.00' },
          { mode: 'Bank transfer', count: '4', amount: '8,140.00' },
        ], 'No money was taken on this date.'),
      table('trend', 'The last seven business dates', 'Occupancy and revenue as recorded by the night audit.',
        ['date', 'rooms', 'occ', 'adr', 'revpar', 'revenue', 'collected'], [
          { date: 'Fri 30 Oct', rooms: '98 / 129', occ: '76.0 %', adr: '1,388.20', revpar: '1,054.20', revenue: '136,044.00', collected: '141,220.00' },
          { date: 'Sat 31 Oct', rooms: '118 / 129', occ: '91.5 %', adr: '1,512.80', revpar: '1,383.60', revenue: '178,510.00', collected: '182,940.00' },
          { date: 'Sun 1 Nov', rooms: '104 / 129', occ: '80.6 %', adr: '1,402.60', revpar: '1,130.40', revenue: '145,870.00', collected: '149,320.00' },
          { date: 'Mon 2 Nov', rooms: '96 / 129', occ: '74.4 %', adr: '1,366.10', revpar: '1,016.40', revenue: '131,146.00', collected: '138,880.00' },
          { date: 'Tue 3 Nov', rooms: '101 / 129', occ: '78.3 %', adr: '1,398.40', revpar: '1,094.80', revenue: '141,238.00', collected: '146,020.00' },
          { date: 'Wed 4 Nov', rooms: '106 / 129', occ: '82.2 %', adr: '1,428.90', revpar: '1,174.20', revenue: '151,463.00', collected: '158,640.00' },
          { date: 'Thu 5 Nov', rooms: '109 / 129', occ: '84.5 %', adr: '1,453.30', revpar: '1,227.98', revenue: '158,410.00', collected: '196,420.00' },
        ], 'No night audit has been run in the last week.'),
      table('ar', 'Receivables', 'City-ledger balances still open, with the largest accounts.',
        ['account', 'b0', 'b30', 'b60', 'b90', 'total'], [
          { account: 'All accounts', b0: '286,940.00', b30: '47,000.00', b60: '36,220.00', b90: { text: '58,000.00', tone: 'red' }, total: '428,160.00' },
          { account: 'Ghana Cocoa Board', b0: '84,200.00', b30: '0.00', b60: '0.00', b90: { text: '0.00', tone: 'slate' }, total: '84,200.00' },
          { account: 'Tullow Oil Ghana', b0: '61,400.00', b30: '12,000.00', b60: { text: '0.00', tone: 'slate' }, b90: { text: '0.00', tone: 'slate' }, total: '73,400.00' },
          { account: 'Ecobank Regional Training', b0: '0.00', b30: '0.00', b60: '21,220.00', b90: { text: '18,000.00', tone: 'red' }, total: '39,220.00' },
          { account: 'Ministry of Tourism', b0: '32,880.00', b30: '0.00', b60: '0.00', b90: { text: '0.00', tone: 'slate' }, total: '32,880.00' },
          { account: 'Star Assurance', b0: '0.00', b30: '0.00', b60: '15,000.00', b90: { text: '0.00', tone: 'slate' }, total: '15,000.00' },
        ], 'Nothing is owed by any company.'),
      stats('risk', 'What needs the GM today', 'Open items that carry money, safety or reputation.', [
        st('Drawer variance', 'GH₵ 40.00', '6 drawers counted and closed', 'red', 'wallet'),
        st('Open audit findings', '7', 'audit day open', 'red', 'alert'),
        st('Maintenance open', '23', '5 past due', 'red', 'tool'),
        st('Rooms out / dirty', '6 / 27', 'cannot be sold as they stand', 'amber', 'bed'),
        st('Guest requests open', '14', 'across the property', 'amber', 'message'),
        st('Staff on duty', '142', '6 absent, 11 on leave', 'amber', 'users'),
      ]),
      list('actions', "The day's open items", "Pulled from every head of department's briefing, worst first.", [
        li('7 income-audit findings are open', 'Finance', 'red'),
        li('5 maintenance jobs are past due', 'Engineering', 'red'),
        li('3 security incidents are open', 'Security', 'red'),
        li('2 interface commands failed today', 'IT', 'red'),
        li('The night audit has not been run for this business date', 'Front Office', 'red'),
        li('14 guest reviews have no reply', 'Guest Experience', 'amber'),
        li('11 leave requests await a decision', 'HR', 'amber'),
        li('27 rooms are not ready for sale', 'Housekeeping', 'amber'),
        li('3 discounts or comps await authorisation', 'Revenue', 'amber'),
        li('9 items are at re-order level', 'Stores', 'amber'),
      ], 'Nothing is outstanding across the property.'),
      table('signoff', 'Who has signed for their numbers', 'Each head confirms their briefing before the GM reads it.',
        ['dept', 'head', 'by', 'at', 'note'], PACKS.map(([key, label, role, , signed, signedBy, signedAt, note]) => ({
          dept: label, head: role,
          by: signed ? signedBy : { text: 'Not signed', tone: 'red' },
          at: signed ? signedAt : '—',
          note: note ?? '—',
        })), 'No head has signed today.'),
    ],
    watch: [
      '7 income-audit findings are open',
      '5 maintenance jobs are past due',
      '3 security incidents are open',
      '2 interface commands failed today',
      'The night audit has not been run for this business date',
    ],
    todo: ['Read the departments with items in red before the morning meeting.', 'Chase the heads who have not signed their briefing.', 'Sign off the day once the night audit has run.'],
    sources: 'flash report, pos_orders, folio_transactions, night_audits, audit_findings, supplier & city-ledger data, all department tables',
  };
}


// ── finance: the controller's own page, with the profit and budget position on it ──────
function financePack() {
  const collected = [
    { mode: 'Cash', count: '26', amount: '38,140.00' },
    { mode: 'Mobile Money — MTN', count: '41', amount: '44,880.00' },
    { mode: 'Mobile Money — Telecel', count: '12', amount: '16,340.00' },
    { mode: 'Visa / Mastercard', count: '34', amount: '74,400.00' },
    { mode: 'Bank transfer', count: '2', amount: '52,000.00' },
    { mode: 'Room charge', count: '27', amount: '6,000.00' },
  ];

  return {
    key: 'finance', label: 'Finance & Accounts', role: 'Financial Controller', icon: 'wallet', perm: 'accounts.view',
    title: 'Financial Controller — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Ama Boakye', '09:50', 'Drawer 3 short GH₵ 40 — being followed up.'),
    headline: [
      st('Collected', 'GH₵ 231,760.00', '142 receipts', 'green', 'wallet'),
      st('Tax collected', 'GH₵ 31,632.00', 'on today\'s revenue', 'blue', 'receipt'),
      st('Receivables', 'GH₵ 412,880.00', 'GH₵ 96,400.00 over 90 days', 'red', 'money'),
      st('Payables due', 'GH₵ 184,200.00', '2 invoices overdue', 'red', 'trend'),
      st('Audit exceptions', '7', 'day open, 2 high', 'red', 'alert'),
      st('Discounts & allowances', 'GH₵ 440.00', 'given today', 'amber', 'minus'),
    ],
    sections: [
      table('collected', 'Money in today', 'Every receipt, at the desk and at the outlets, by the mode it came in on.',
        ['mode', 'count', 'amount'], collected, 'Nothing has been collected on this date.'),
      table('ageing', 'Receivables ageing', 'City-ledger bills still open, bucketed by how long they are past due.',
        ['bucket', 'amount'], [
          { bucket: 'Not yet due', amount: '188,400.00' },
          { bucket: '1 – 30 days', amount: '128,080.00' },
          { bucket: '31 – 60 days', amount: '62,900.00' },
          { bucket: 'Over 60 days', amount: { text: '33,500.00', tone: 'red' } },
        ], 'No company bill is outstanding.'),
      plTable(),
      budgetTable(),
      list('close', 'Close and control', 'What stands between today and a clean close.', [
        li('Night audit run for this date', 'occupancy 84.5 %', 'green'),
        li('Refunds paid today', 'GH₵ 2,140.00', 'amber'),
        li('142 receipts issued', 'GH₵ 231,760.00', 'green'),
        li('2 supplier invoices overdue', 'GH₵ 61,400.00', 'red'),
        li('7 income-audit findings unresolved', 'clear before the day closes', 'red'),
      ], 'Nothing is holding the close.'),
    ],
    watch: [
      'GH₵ 33,500.00 of receivables is over 60 days old',
      '2 supplier invoices are overdue',
      '7 income-audit findings are unresolved',
    ],
    todo: [
      'Recount the cashier drawers against the receipts.',
      'Bank the takings and post the deposit.',
      'Chase the oldest city-ledger bills with the sales team.',
    ],
    sources: 'folio_transactions, pos_payments, vouchers, supplier_invoices, gl_lines, budgets, audit_days, audit_findings, night_audits',
  };
}


// ── people: the HR manager's pack, with the department roster and the leave queue ─────
function hrPack() {
  return {
    key: 'hr', label: 'People & HR', role: 'Human Resources Manager', icon: 'users', perm: 'hr.view',
    title: 'Human Resources Manager — daily briefing', date: day, is_exec: false, house: HOUSE, signoff: null,
    headline: [
      st('Headcount', '131', 'active employees', 'blue', 'users'),
      st('On duty today', '118', '131 rostered shifts', 'green', 'check'),
      st('Absent', '4', '2 on a half day', 'red', 'alert'),
      st('On leave', '6', 'approved for today', 'purple', 'calendar'),
      st('Leave awaiting decision', '3', 'requests in the queue', 'amber', 'mail'),
      st('Contracts to watch', '3', '2 on probation ending soon', 'amber', 'id'),
    ],
    sections: [
      table('attendance', 'Attendance', 'How the property clocked in on the business date.', ['status', 'people'], [
        { status: 'Present', people: '114' },
        { status: 'Half day', people: '4' },
        { status: 'Absent', people: '4' },
        { status: 'Leave', people: '6' },
        { status: 'Week off', people: '3' },
      ], 'No attendance was recorded on this date — check the time clock.'),
      table('departments', 'Attendance by department', 'Who was on the roster, who clocked in and who did not, department by department.',
        ['dept', 'staff', 'shifts', 'on', 'off'], [
          { dept: 'Housekeeping', staff: '38', shifts: '34', on: '32', off: { text: '1', tone: 'red' } },
          { dept: 'Food & Beverage', staff: '31', shifts: '29', on: '28', off: { text: '1', tone: 'red' } },
          { dept: 'Kitchen', staff: '24', shifts: '23', on: '21', off: { text: '2', tone: 'red' } },
          { dept: 'Front Office', staff: '16', shifts: '14', on: '14', off: { text: '0', tone: 'green' } },
          { dept: 'Engineering', staff: '12', shifts: '11', on: '11', off: { text: '0', tone: 'green' } },
          { dept: 'Other', staff: '10', shifts: '20', on: '12', off: { text: '0', tone: 'green' } },
        ], 'No employee is on the books, so there is nothing to compare.'),
      table('leave', 'The leave queue', 'Requests waiting for a decision, oldest dates first.',
        ['employee', 'type', 'from', 'to', 'days'], [
          { employee: 'Yaa Owusu', type: 'Annual leave', from: 'Fri 6 Nov', to: 'Fri 13 Nov', days: '8.0' },
          { employee: 'Kofi Amankwah', type: 'Compassionate', from: 'Mon 9 Nov', to: 'Tue 10 Nov', days: '2.0' },
          { employee: 'Adjoa Mensimah', type: 'Annual leave', from: 'Mon 16 Nov', to: 'Wed 18 Nov', days: '3.0' },
        ], 'No leave request is waiting for a decision.'),
      table('ending', 'Contracts and probation running out', 'The next sixty days, soonest first.',
        ['employee', 'kind', 'ends', 'days'], [
          { employee: 'Selorm Agbeko', kind: 'Contract', ends: 'Tue 10 Nov', days: { text: '5', tone: 'red' } },
          { employee: 'Efua Danso', kind: 'Probation', ends: 'Fri 20 Nov', days: { text: '15', tone: 'amber' } },
          { employee: 'Ibrahim Musah', kind: 'Probation', ends: 'Wed 2 Dec', days: { text: '27', tone: 'amber' } },
        ], 'No contract or probation period ends in the next sixty days.'),
      list('people', 'People decisions waiting', 'What HR has to act on today.', [
        li('3 leave requests waiting for a decision', 'approve or decline today', 'amber'),
        li('4 absentees', 'cover the shift before service', 'red'),
        li('2 staff reach the end of probation within 30 days', 'confirm or extend', 'amber'),
        li('3 contracts expire within 60 days', 'renew or release', 'slate'),
      ], 'Nothing waiting on HR.'),
    ],
    watch: ['4 staff did not report for duty', '3 leave requests are undecided', 'Selorm Agbeko\'s contract ends in five days'],
    todo: [
      'Reconcile the time clock against the roster.',
      'Decide the leave queue before the next roster is published.',
      'Send the headcount and absentee list to the GM.',
    ],
    sources: 'employees, departments, attendances, leave_requests, roster_shifts',
  };
}

// ── parking: the valet desk's pack, with the bays by zone and the drop-off curve ──────
function parkingPack() {
  return {
    key: 'parking', label: 'Parking & Valet', role: 'Parking Supervisor', icon: 'truck', perm: 'parking.view',
    title: 'Parking Supervisor — daily briefing', date: day, is_exec: false, house: HOUSE, signoff: null,
    headline: [
      st('Cars in today', '64', '48 in the bays now', 'blue', 'car'),
      st('Bays in use', '60.0 %', '80 bays in total', 'green', 'home'),
      st('Revenue', 'GH₵ 2,340.00', 'charged on exit', 'green', 'money'),
      st('Valet calls', '3', 'cars requested or waiting at the door', 'amber', 'bell'),
      st('Lost tickets', '1', 'fee applied or waived', 'amber', 'alert'),
      st('Permits expiring', '4', 'within 30 days', 'amber', 'id'),
    ],
    sections: [
      table('kinds', 'Who parked today', 'Guest, visitor and staff cars.', ['kind', 'cars', 'value'], [
        { kind: 'In-house guest', cars: '34', value: '1,420.00' },
        { kind: 'Visitor', cars: '22', value: '860.00' },
        { kind: 'Staff / permit', cars: '8', value: '60.00' },
      ], 'No car was parked on this date.'),
      table('zones', 'The bays by zone', 'How each part of the yard is filling, and what it has charged.',
        ['zone', 'cars', 'bays', 'value'], [
          { zone: 'Main car park', cars: '42', bays: { text: '36 / 50', tone: 'slate' }, value: '1,470.00' },
          { zone: 'Beach front', cars: '16', bays: { text: '10 / 20', tone: 'slate' }, value: '640.00' },
          { zone: 'Staff and valet', cars: '6', bays: { text: '2 / 10', tone: 'slate' }, value: '230.00' },
        ], 'No parking zone is active, so there are no bays to report on.'),
      table('curve', 'When they came', 'Cars by the time they entered, so the drive can be staffed for the peak.',
        ['hour', 'cars'], [
          { hour: '06:00 – 09:00', cars: '9' },
          { hour: '09:00 – 12:00', cars: '14' },
          { hour: '12:00 – 15:00', cars: '17' },
          { hour: '15:00 – 18:00', cars: '12' },
          { hour: '18:00 – 21:00', cars: '8' },
          { hour: '21:00 – 06:00', cars: '4' },
        ], 'No car entered the yard on this date.'),
      table('permits', 'Permits falling due', 'Active permits that expire in the next thirty days, soonest first.',
        ['holder', 'type', 'expires', 'fee'], [
          { holder: 'Mensah Kofi', type: 'Staff', expires: 'Mon 9 Nov', fee: '120.00' },
          { holder: 'Beach Road Pharmacy', type: 'Company', expires: 'Fri 13 Nov', fee: '480.00' },
          { holder: 'Nana Ama Boateng', type: 'Resident', expires: 'Tue 24 Nov', fee: '240.00' },
        ], 'No permit expires in the next thirty days.'),
      list('valet', 'The valet desk', 'What is on the drive and what the cashier has to collect.', [
        li('3 cars requested at the door', 'run them promptly', 'amber'),
        li('1 lost-ticket fee', 'record the waiver reason', 'amber'),
        li('48 of 80 bays in use', '32 free', 'green'),
      ], 'Nothing on the drive.'),
    ],
    watch: ['3 valet calls are outstanding', '4 permits expire within a fortnight'],
    todo: [
      'Collect and bank the parking cash.',
      'Release the spaces held for arrivals tonight.',
      'Call the four permit holders before they lapse.',
    ],
    sources: 'parking_sessions, parking_zones, parking_permits',
  };
}


// ── front office: the desk's pack, with the arrivals still to come and the folios to settle
function frontOfficePack() {
  return {
    key: 'frontoffice', label: 'Front Office', role: 'Front Office Manager', icon: 'bell', perm: 'frontdesk.view',
    title: 'Front Office Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Akosua Mensah', '09:12', 'Two rooms released late — noted.'),
    headline: [
      st('In house', '109 rooms', '218 guests', 'blue', 'bed'),
      st('Arrivals', '31 of 46', '2 still to be roomed', 'amber', 'login'),
      st('Departures', '26 of 38', '4 late check-out agreed', 'purple', 'logout'),
      st('Cashiered at the desk', 'GH₵ 41,280.00', 'GH₵ 2,140.00 refunded', 'green', 'wallet'),
      st('No-shows', '1', '3 cancellations today', 'amber', 'alert'),
      st('Open guest requests', '3', '2 past their promised time', 'red', 'message'),
    ],
    sections: [
      stats('movement', 'Movement across the desk', 'Stays as they stand on the business date.', [
        st('Arrivals expected', '15', '1 overdue from an earlier day', 'amber', 'login'),
        st('Arrived', '31', 'checked in today', 'green', 'check'),
        st('Departures due', '12', 'still in house', 'amber', 'logout'),
        st('Checked out', '26', 'folio closed', 'green', 'check'),
        st('Room moves', '2', 'recorded today', 'slate', 'swap'),
        st('Rooms occupied', '109 / 129', '2 out of order', 'blue', 'bed'),
      ]),
      table('arriving', 'Arrivals still to run', "The rest of today's arrivals, and whether a room is waiting for them.",
        ['guest', 'room', 'nights', 'rate'], [
          { guest: 'Kwesi Amoah', room: { text: '118', tone: 'green' }, nights: '3', rate: '1,480.00' },
          { guest: 'Adjoa Nyarko', room: { text: 'Not roomed', tone: 'amber' }, nights: '2', rate: '1,320.00' },
          { guest: 'Beatrice Osei', room: { text: '204', tone: 'green' }, nights: '1', rate: '1,150.00' },
          { guest: 'Kofi Boateng', room: { text: 'Not roomed', tone: 'amber' }, nights: '4', rate: '1,620.00' },
        ], 'No arrival is still to run for this date.'),
      table('departing', 'Departures to settle', 'Guests leaving today and where their folio stands before they go.',
        ['guest', 'room', 'balance', 'state'], [
          { guest: 'Yaa Asantewaa', room: '212', balance: { text: '4,210.00', tone: 'red' }, state: 'In house' },
          { guest: 'Nana Owusu', room: '108', balance: { text: '0.00', tone: 'green' }, state: 'Checked out' },
          { guest: 'Selorm Agbeko', room: '305', balance: { text: '1,890.00 credit', tone: 'green' }, state: 'Late check-out' },
        ], 'No guest is due to leave on this date.'),
      table('cashier', 'Cashiered at the desk', 'Folio payments posted on the business date, by the mode the guest paid with.',
        ['mode', 'bills', 'amount'], [
          { mode: 'Cash', bills: '9', amount: '12,480.00' },
          { mode: 'Mobile Money — MTN', bills: '6', amount: '11,220.00' },
          { mode: 'Visa / Mastercard', bills: '11', amount: '14,600.00' },
          { mode: 'Bank transfer', bills: '1', amount: '2,980.00' },
        ], 'Nothing has been received at the desk on this date.'),
      list('desk', 'Work the desk still owns', 'Open items anywhere in the property that the front office has to close.', [
        li('3 guest requests open', '2 overdue', 'red'),
        li('4 traces due today', 'not yet done', 'amber'),
        li('2 of today\'s arrivals have no room assigned', 'assign before the guest reaches the desk', 'amber'),
        li('4 late check-outs agreed', 'housekeeping has been told', 'slate'),
      ], 'The desk has nothing open.'),
      table('vip', 'Guests to look after personally', 'VIPs staying tonight and VIPs still to arrive.',
        ['guest', 'room', 'when', 'flag'], [
          { guest: 'Nana Adjei', room: '401', when: '03 Nov → 07 Nov', flag: { text: 'In house', tone: 'green' } },
          { guest: 'Grace Mensah', room: '—', when: '05 Nov → 08 Nov', flag: { text: 'Arriving', tone: 'blue' } },
        ], 'No VIP on the books for this date.'),
    ],
    watch: ['2 guest requests are past the time the guest was promised', '2 arrivals have no room assigned'],
    todo: ['Room the 2 arrivals without a room number.', 'Settle the cashier float and hand over to the next shift.'],
    sources: 'reservation_rooms, folios, folio_transactions, guest_requests, traces, wakeup_calls, room_moves',
  };
}

// ── security: the duty officer's pack, with who is inside and which keys are out ──────
function securityPack() {
  return {
    key: 'security', label: 'Security', role: 'Security Manager', icon: 'shield', perm: 'security.view',
    title: 'Security Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Daniel Owusu', '06:50'),
    headline: [
      st('Visitors booked in', '46', '8 on site now', 'blue', 'id'),
      st('Turned away', '0', 'nobody refused', 'green', 'shield'),
      st('Gate passes raised', '18', '3 still out', 'amber', 'box'),
      st('Incidents today', '3', '1 open in total', 'red', 'alert'),
      st('Returns overdue', '1', 'goods past their return date', 'red', 'clock'),
      st('On site now', '8', 'visitors not yet checked out', 'purple', 'users'),
    ],
    sections: [
      table('visitors', 'The visitor register', 'Everyone booked through the door on the business date.',
        ['status', 'count'], [
          { status: 'Checked out', count: '38', tone: 'green' },
          { status: 'On site', count: '8', tone: 'blue' },
          { status: 'Denied', count: '0', tone: 'slate' },
        ], 'No visitor was booked in on this date.'),
      table('onsite', 'On site now', 'Everyone still inside the property, longest visit first.',
        ['guest', 'host', 'purpose', 'since'], [
          { guest: 'Kwabena Owusu', host: 'Procurement', purpose: 'Delivery', since: '07:10' },
          { guest: 'Esi Nyame', host: 'Human Resources', purpose: 'Interview', since: '08:25' },
          { guest: 'Samuel Addo', host: 'Maintenance', purpose: 'Contractor', since: '09:40' },
        ], 'No visitor is inside the property.'),
      table('keys', 'Master keys signed out', 'Every key off the board, and when it is due back.',
        ['key', 'holder', 'out', 'due'], [
          { key: 'Master 3 — floors 2–3', holder: 'Ama Serwaa', out: '06:15', due: { text: '14:00', tone: 'red' } },
          { key: 'Pool gate', holder: 'Kofi Mensah', out: '07:00', due: { text: '17:00', tone: 'slate' } },
        ], 'Every key is on the board.'),
      table('passes', 'Goods out on a gate pass', 'Returnable property that has left the building.',
        ['pass', 'party', 'due', 'flag'], [
          { pass: 'GP-0041', party: 'Akosua Rentals', due: '03 Nov', flag: { text: 'Overdue', tone: 'red' } },
          { pass: 'GP-0044', party: 'Grove Laundry Services', due: '06 Nov', flag: { text: 'Out', tone: 'amber' } },
        ], 'Nothing is out on a gate pass.'),
      table('incidents', 'Incidents', 'What was reported and where it stands.',
        ['title', 'type', 'place', 'status'], [
          { title: 'Guest reported a missing phone charger', type: 'Loss', place: 'Room 214', status: { text: 'Open', tone: 'red' } },
          { title: 'Gate barrier failed to lift', type: 'Equipment', place: 'Main gate', status: { text: 'Closed', tone: 'green' } },
          { title: 'Unauthorised vehicle in the staff bay', type: 'Access', place: 'Staff car park', status: { text: 'Closed', tone: 'green' } },
        ], 'No incident was reported on this date.'),
      list('duty', 'On the door', 'What the duty officer has to close today.', [
        li('8 visitors have not checked out', 'confirm before the shift ends', 'amber'),
        li('1 gate pass is overdue', 'call the party', 'red'),
        li('1 incident remains open', 'follow up and close', 'red'),
        li('2 master keys are signed out', '1 past its return time', 'amber'),
      ], 'The door is clear.'),
    ],
    watch: ['1 gate pass is past its return date', '3 incidents reported today', '8 visitors are still on site'],
    todo: ['Walk the perimeter and log the patrol.', 'Check every overnight visitor out before the shift changes.'],
    sources: 'visitors, gate_passes, security_incidents, key_loans, master_keys',
  };
}


// ── kitchen: the chef's pack, with the pass, the allergen lines and the cold chain ────
function kitchenPack() {
  return {
    key: 'kitchen', label: 'Kitchen', role: 'Executive Chef', icon: 'coffee', perm: 'pos.kitchen',
    title: 'Executive Chef — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Chef Ibrahim Musah', '10:20'),
    headline: [
      st('Tickets fired', '214', 'all printed', 'green', 'print'),
      st('Dishes served', '486', '312 lines', 'blue', 'cup'),
      st('Average dish time', '14 min', 'fired to ready', 'green', 'clock'),
      st('Still on the pass', '7', 'lines not yet ready', 'amber', 'coffee'),
      st('Allergen lines', '3', '1 verified by the chef', 'red', 'alert'),
      st('Temperature checks', '18', '1 out of range', 'red', 'thermo'),
    ],
    sections: [
      table('dishes', 'What the pass sent out', 'The busiest lines of the day.', ['item', 'qty', 'amount'], [
        { item: 'Grilled tilapia & banku', qty: '42.00', amount: '6,510.00' },
        { item: 'Jollof with grilled chicken', qty: '38.00', amount: '4,940.00' },
        { item: 'Club sandwich', qty: '31.00', amount: '2,635.00' },
        { item: 'Fresh coconut', qty: '29.00', amount: '1,015.00' },
      ], 'Nothing has been sent from the pass on this date.'),
      table('pass', 'Still on the pass', 'Lines fired and not yet ready, longest wait first.', ['order', 'item', 'qty', 'fired', 'wait'], [
        { order: '#4821', item: 'Grilled tilapia & banku', qty: '2.00', fired: '12:10', wait: { text: '26 min', tone: 'red' } },
        { order: '#4823', item: 'Jollof with grilled chicken', qty: '3.00', fired: '12:22', wait: { text: '14 min', tone: 'amber' } },
        { order: '#4826', item: 'Beef khebab (plate)', qty: '1.00', fired: '12:31', wait: { text: '5 min', tone: 'amber' } },
      ], 'Nothing is waiting on the pass.'),
      table('allergens', 'Allergen lines to verify', 'A chef signs every line that carries an allergen flag before it leaves the pass.',
        ['order', 'item', 'qty', 'state'], [
          { order: '#4818', item: 'Peanut soup with rice balls', qty: '1.00', state: { text: 'Not verified', tone: 'red' } },
          { order: '#4821', item: 'Club sandwich (no mayo)', qty: '1.00', state: { text: 'Not verified', tone: 'red' } },
        ], 'Every allergen line has been verified by a chef.'),
      table('temps', 'Readings outside the safe range', 'Cold rooms and hot holding that failed their check today.',
        ['point', 'reading', 'range', 'at'], [
          { point: 'Cold room 2', reading: { text: '9.4 °C', tone: 'red' }, range: '0.0 – 5.0 °C', at: '11:40' },
        ], 'Every reading taken today was inside its safe range.'),
      table('prints', 'KOT printing', 'Kitchen tickets and how the printers handled them.', ['status', 'count'], [
        { status: 'Printed', count: '206' },
        { status: 'Sent', count: '8' },
      ], 'No kitchen ticket has been printed on this date.'),
      list('checks', 'Food safety and the pass', 'What the kitchen has to answer for today.', [
        li('1 cold-room reading out of range', 'corrective action needed', 'red'),
        li('18 temperature readings taken', '18 logged', 'green'),
        li('4 checklists completed', '1 with failures', 'amber'),
        li('2 allergen lines not verified', 'a chef must check every one', 'red'),
        li('3 lines cancelled after firing', 'GH₵ 640.00', 'amber'),
      ], 'Nothing open for the kitchen.'),
    ],
    watch: ['2 allergen lines have not been verified', '1 temperature reading is outside the safe range', '1 line has waited 26 minutes'],
    todo: ['Verify the open allergen lines before the next service.', 'Reconcile the dishes sent against the menu mix at the end of service.'],
    sources: 'pos_order_items, pos_orders, kot_print_jobs, fs_temp_logs, fs_checklist_runs',
  };
}

// ── stores: purchasing's pack, with the counter queue and the dock ───────────────────
function storesPack() {
  return {
    key: 'stores', label: 'Purchasing & Stores', role: 'Purchasing & Stores Manager', icon: 'box', perm: 'inventory.view',
    title: 'Purchasing & Stores Manager — daily briefing', date: day, is_exec: false, house: HOUSE, signoff: null,
    headline: [
      st('Issued today', 'GH₵ 14,710.00', '14 store issues', 'green', 'box'),
      st('Requisitions waiting', '5', 'GH₵ 21,400.00 estimated', 'amber', 'mail'),
      st('At re-order level', '9', 'items to buy', 'red', 'alert'),
      st('Orders raised', '4', 'GH₵ 61,200.00 committed', 'blue', 'receipt'),
      st('Goods received', 'GH₵ 38,940.00', '6 GRNs', 'teal', 'truck'),
      st('Approved to issue', '3', 'waiting at the counter', 'purple', 'check'),
    ],
    sections: [
      table('low', 'Buy these', 'Items at or below their re-order level, with the quantity the system suggests.',
        ['item', 'stock', 'level', 'suggest'], [
          { item: 'Bed linen — double', stock: '12.00', level: '20.00', suggest: { text: '28.00', tone: 'red' } },
          { item: 'Toiletries — soap 40 g', stock: '180.00', level: '200.00', suggest: { text: '320.00', tone: 'red' } },
          { item: 'Diesel', stock: '140.00', level: '200.00', suggest: { text: '260.00', tone: 'red' } },
        ], 'Every item is above its re-order level.'),
      table('queue', 'Waiting at the counter', 'Requisitions still to be issued, soonest needed first.',
        ['req', 'dept', 'need', 'state', 'value'], [
          { req: 'REQ-1141', dept: 'Housekeeping', need: 'Thu 5 Nov', state: { text: 'Awaiting approval', tone: 'amber' }, value: '6,400.00' },
          { req: 'REQ-1138', dept: 'Kitchen', need: 'Fri 6 Nov', state: { text: 'Approved', tone: 'green' }, value: '9,180.00' },
          { req: 'REQ-1132', dept: 'Laundry & Linen', need: 'Sat 7 Nov', state: { text: 'Part issued', tone: 'green' }, value: '5,820.00' },
        ], 'Nothing is waiting at the counter.'),
      table('deliveries', 'What came in today', 'Deliveries received at the store, with anything rejected on the dock.',
        ['grn', 'supplier', 'value', 'rejected'], [
          { grn: 'GRN-0912', supplier: 'Coastal Foods Ltd', value: '24,180.00', rejected: { text: '0.00', tone: 'slate' } },
          { grn: 'GRN-0913', supplier: 'Takoradi Fresh Produce', value: '8,900.00', rejected: { text: '640.00', tone: 'red' } },
          { grn: 'GRN-0914', supplier: 'Grove Laundry Supplies', value: '5,860.00', rejected: { text: '0.00', tone: 'slate' } },
        ], 'No delivery was received on this date.'),
      table('req', 'Requisitions in the queue', 'Departments waiting on the store.', ['status', 'count', 'value'], [
        { status: 'Submitted', count: '2', value: '11,220.00' },
        { status: 'Approved', count: '2', value: '8,180.00' },
        { status: 'Part issued', count: '1', value: '2,000.00' },
      ], 'No requisition is open.'),
      list('store', 'The store today', 'What went out and what came in.', [
        li('14 issues to departments', 'GH₵ 14,710.00', 'green'),
        li('6 deliveries received', 'GH₵ 38,940.00', 'green'),
        li('9 items need a purchase order', 'raise them before service', 'red'),
      ], 'The store is quiet.'),
    ],
    watch: ['9 items are at or below re-order level', '2 requisitions are waiting for approval'],
    todo: ['Raise the purchase orders for the items at re-order level.', 'Approve or return the requisitions in the queue.'],
    sources: 'store_issues, requisitions, purchase_orders, goods_receipts, stock_movements',
  };
}


// ── revenue: the manager's pack, with the rate decisions and what was given away ─────
function revenuePack() {
  const pace = [];
  const occ = [84, 94, 100, 74, 57, 61, 68, 72, 66, 59, 63, 71, 78, 82];
  const rooms = [109, 121, 129, 96, 74, 79, 88, 93, 85, 76, 81, 92, 101, 106];
  const adr = ['1,453.30', '1,510.00', '1,588.40', '1,402.10', '1,338.60', '1,352.00', '1,371.20', '1,384.60', '1,362.00', '1,341.80', '1,349.40', '1,368.00', '1,392.50', '1,410.00'];
  const labels = ['Tonight', 'Fri 6 Nov', 'Sat 7 Nov', 'Sun 8 Nov', 'Mon 9 Nov', 'Tue 10 Nov', 'Wed 11 Nov', 'Thu 12 Nov', 'Fri 13 Nov', 'Sat 14 Nov', 'Sun 15 Nov', 'Mon 16 Nov', 'Tue 17 Nov', 'Wed 18 Nov'];
  for (let i = 0; i < labels.length; i += 1) {
    pace.push({
      day: labels[i], rooms: String(rooms[i]),
      occ: { text: `${occ[i]}.0 %`, tone: occ[i] >= 80 ? 'green' : occ[i] >= 50 ? 'amber' : 'red' },
      adr: adr[i], revpar: String(Math.round((Number(adr[i].replace(',', '')) * rooms[i]) / 129)).replace(/\B(?=(\d{3})+(?!\d))/g, ','),
    });
  }

  return {
    key: 'revenue', label: 'Revenue', role: 'Revenue Manager', icon: 'chart', perm: 'revenue.view',
    title: 'Revenue Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Selorm Agbeko', '07:55'),
    headline: [
      st('Tonight', '84.5 %', '109 of 129 sellable', 'blue', 'bed'),
      st('ADR on the books', 'GH₵ 1,453.30', 'tonight, before extras', 'green', 'money'),
      st('RevPAR on the books', 'GH₵ 1,227.98', 'per sellable room tonight', 'teal', 'chart'),
      st('Tomorrow', '93.8 %', '121 rooms held', 'purple', 'calendar'),
      st('Next 7 nights', '80.6 %', 'average occupancy on the books', 'amber', 'trend'),
      st('Arrivals booked today', '27', 'new bookings on the business date', 'slate', 'plus'),
    ],
    sections: [
      table('pace', 'The next fortnight', 'What is on the books tonight and every night after it.',
        ['day', 'rooms', 'occ', 'adr', 'revpar'], pace, 'No room is on the books.'),
      table('mix', 'Who is in the house tonight', 'Room types sold tonight against the published base rate.',
        ['type', 'rooms', 'adr', 'base', 'position'], [
          { type: 'Ocean view double', rooms: '48', adr: '1,612.40', base: '1,550.00', position: { text: '4.0 %', tone: 'green' } },
          { type: 'Garden double', rooms: '34', adr: '1,318.20', base: '1,320.00', position: { text: '-0.1 %', tone: 'red' } },
          { type: 'Beach suite', rooms: '19', adr: '2,210.00', base: '2,050.00', position: { text: '7.8 %', tone: 'green' } },
          { type: 'Standard twin', rooms: '8', adr: '1,140.00', base: '1,150.00', position: { text: '-0.9 %', tone: 'red' } },
        ], 'Nothing is sold tonight.'),
      table('auth', 'Rate decisions waiting', 'Discounts, comps and house use the revenue manager has to decide on.',
        ['auth', 'kind', 'dates', 'amount', 'asked'], [
          { auth: 'RA-0142', kind: 'Rate below expected', dates: '06 Nov → 08 Nov', amount: { text: '1,840.00', tone: 'amber' }, asked: 'Akosua Mensah' },
          { auth: 'RA-0143', kind: 'Complimentary room', dates: '05 Nov', amount: { text: '1,450.00', tone: 'amber' }, asked: 'Adjoa Sarpong' },
          { auth: 'RA-0144', kind: 'Folio discount / allowance', dates: '05 Nov', amount: { text: '320.00', tone: 'amber' }, asked: 'Financial Controller' },
        ], 'Nothing is waiting for a revenue decision.'),
      table('leakage', 'What was given away today', 'Every discount and allowance posted on the business date, with what it was for.',
        ['type', 'for', 'area', 'amount'], [
          { type: 'Discount', for: 'Loyalty rate — returning guest', area: 'Rooms', amount: { text: '240.00', tone: 'amber' } },
          { type: 'Allowance', for: 'Service recovery — air conditioning', area: 'Rooms', amount: { text: '200.00', tone: 'amber' } },
        ], 'Nothing was discounted or allowed on this date.'),
      table('source', 'Where tonight came from', 'Business source on the reservations arriving today.',
        ['source', 'bookings'], [
          { source: 'Direct — website', bookings: '11' },
          { source: 'Booking.com', bookings: '9' },
          { source: 'Corporate — Tullow Ghana', bookings: '4' },
          { source: 'Walk-in', bookings: '2' },
        ], 'No reservation arrives today.'),
      list('actions', 'Revenue actions', 'What the revenue manager does with this position.', [
        li('Review the price against the channel for the next weekend', 'rate parity', 'slate'),
        li('Garden double is selling below base', 'check the rate plan that produced it', 'amber'),
      ], 'No action is needed.'),
    ],
    watch: ['Garden double is 0.1 % below its published base rate'],
    todo: ['Check the rate position on every channel before the day sells out.', "Decide the three rate requests before tomorrow's arrivals."],
    sources: 'reservation_rooms, reservations, room_types, rooms, revenue_authorisations, folio_transactions',
  };
}

// ── engineering: the chief engineer's pack, with the overdue list and the plan ───────
function maintenancePack() {
  return {
    key: 'maintenance', label: 'Engineering', role: 'Chief Engineer', icon: 'tool', perm: 'maintenance.manage',
    title: 'Chief Engineer — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Kofi Mensah', '08:15', 'Chiller 2 on bypass.'),
    headline: [
      st('Jobs raised', '14', '2 urgent', 'red', 'tool'),
      st('Jobs closed', '11', 'GH₵ 3,180.00 spent', 'green', 'check'),
      st('Open jobs', '23', '5 past the promised date', 'red', 'alert'),
      st('Planned maintenance due', '3', '2 more in the next 7 days', 'amber', 'calendar'),
      st('Rooms out of order', '2', '2 outage records open', 'red', 'bed'),
      st('Cost today', 'GH₵ 4,620.00', 'posted against jobs', 'purple', 'money'),
    ],
    sections: [
      table('priority', 'Open work by priority', 'Everything the engineering team still has to close.',
        ['priority', 'open', 'oldest'], [
          { priority: 'Urgent', open: '4', oldest: '28 Oct' },
          { priority: 'High', open: '7', oldest: '01 Nov' },
          { priority: 'Normal', open: '12', oldest: '03 Nov' },
        ], 'No job is open.'),
      table('overdue', 'Past the promised date', 'Jobs the team has promised and not closed, worst first.',
        ['ticket', 'job', 'where', 'due', 'late'], [
          { ticket: 'MR-0912', job: 'Bathroom tap dripping', where: 'Room 214', due: '01 Nov', late: { text: '4 d', tone: 'red' } },
          { ticket: 'MR-0918', job: 'Chiller 2 on bypass', where: 'Plant room', due: '02 Nov', late: { text: '3 d', tone: 'red' } },
          { ticket: 'MR-0921', job: 'Beach bar fridge not cooling', where: 'Beach bar', due: '03 Nov', late: { text: '2 d', tone: 'red' } },
        ], 'Nothing is past its promised date.'),
      table('soon', 'Planned maintenance in the next seven days', 'Work already scheduled, so the team can plan around it.',
        ['schedule', 'due', 'owner', 'priority'], [
          { schedule: 'Chiller service', due: 'Fri 6 Nov', owner: 'Cool Air Ghana', priority: 'High' },
          { schedule: 'Lift quarterly inspection', due: 'Tue 10 Nov', owner: 'Schindler', priority: 'Urgent' },
        ], 'Nothing is planned for the next seven days.'),
      table('pm', 'Planned maintenance due now', 'Schedules that have reached their due date — statutory and plant maintenance.',
        ['title', 'due'], [
          { title: 'Pool pump service', due: { text: '03 Nov', tone: 'red' } },
          { title: 'Generator load test', due: { text: '04 Nov', tone: 'red' } },
        ], 'Nothing is due.'),
      list('out', 'Rooms out of the sale', 'What housekeeping cannot sell because engineering has not released it.', [
        li('Room 210 — bathroom regrouting', 'to 07 Nov', 'red'),
        li('Room 214 — tap and shower head', 'no return date', 'red'),
      ], 'No room is withdrawn from sale.'),
      table('raised', 'Raised today', 'Where the calls came from.', ['priority', 'jobs', 'cost'], [
        { priority: 'Urgent', jobs: '2', cost: '1,240.00' },
        { priority: 'High', jobs: '5', cost: '1,880.00' },
        { priority: 'Normal', jobs: '7', cost: '1,500.00' },
      ], 'No job was raised on this date.'),
    ],
    watch: ['5 maintenance jobs are past their promised date', '2 rooms are out of order and cannot be sold'],
    todo: ['Clear or re-date the 5 overdue jobs.', 'Sign off the 3 planned maintenance schedules.'],
    sources: 'maintenance_requests, pm_schedules, room_outages, rooms',
  };
}


// ── sales: the enquiries that went quiet, and today's wins and losses ────────────────
function salesPack() {
  return {
    key: 'sales', label: 'Sales', role: 'Sales Manager', icon: 'trend', perm: 'sales.view',
    title: 'Sales Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Akosua Mensah', '08:05'),
    headline: [
      st('Open pipeline', 'GH₵ 486,300.00', '23 live enquiries', 'blue', 'trend'),
      st('Won today', '2', 'GH₵ 62,400.00', 'green', 'check'),
      st('Lost today', '1', 'record the reason on each', 'red', 'x'),
      st('Bookings created', '27', '4 arrivals still expected', 'purple', 'calendar'),
      st('Activities due today', '9', '3 overdue', 'red', 'phone'),
      st('Targets live', '4', '4 sellers on this month', 'teal', 'users'),
    ],
    sections: [
      table('pipeline', 'The pipeline by stage', 'Live enquiries and what they are worth if they convert.',
        ['stage', 'count', 'nights', 'value'], [
          { stage: 'Prospect', count: '9', nights: '84', value: '142,600.00' },
          { stage: 'Qualified', count: '7', nights: '96', value: '168,900.00' },
          { stage: 'Proposal', count: '4', nights: '61', value: '118,400.00' },
          { stage: 'Negotiation', count: '3', nights: '38', value: '56,400.00' },
        ], 'Nothing in the pipeline.'),
      table('quiet', 'Enquiries that have gone quiet', 'Still open, but nothing has moved on them for a fortnight.',
        ['number', 'account', 'stage', 'value', 'moved'], [
          { number: 'SO-0231', account: 'Tullow Ghana', stage: 'Proposal', value: '38,400.00', moved: { text: '22 d ago', tone: 'amber' } },
          { number: 'SO-0224', account: 'Ghana Ports Authority', stage: 'Qualified', value: '24,600.00', moved: { text: '18 d ago', tone: 'amber' } },
          { number: 'SO-0218', account: 'Ecobank Ghana', stage: 'Negotiation', value: '19,800.00', moved: { text: '15 d ago', tone: 'amber' } },
        ], 'Nothing has been left sitting.'),
      table('won', 'What was won today', 'The enquiries that turned into business on the business date.',
        ['number', 'account', 'nights', 'value', 'owner'], [
          { number: 'SO-0240', account: 'Nestlé Ghana', nights: '46', value: { text: '41,600.00', tone: 'green' }, owner: 'Akosua Mensah' },
          { number: 'SO-0243', account: 'MTN Ghana', nights: '24', value: { text: '20,800.00', tone: 'green' }, owner: 'Kwame Boateng' },
        ], 'Nothing was won on this date.'),
      table('lost_detail', 'What was lost today, and why', 'Each enquiry lost, with the reason the team recorded.',
        ['number', 'account', 'value', 'reason'], [
          { number: 'SO-0236', account: 'Stanbic Bank', value: '14,200.00', reason: { text: 'Rate — competitor undercut us', tone: 'amber' } },
        ], 'Nothing was lost on this date.'),
      table('agenda', "Today's calls and visits", 'What the team said it would do today.',
        ['when', 'subject', 'who', 'owner', 'status'], [
          { when: '09:30', subject: 'Site inspection — conference wing', who: 'Unilever Ghana', owner: 'Akosua Mensah', status: { text: 'Planned', tone: 'amber' } },
          { when: '11:00', subject: 'Rate agreement renewal', who: 'Tullow Ghana', owner: 'Kwame Boateng', status: { text: 'Planned', tone: 'amber' } },
          { when: '14:30', subject: 'Follow up the proposal', who: 'Ghana Ports Authority', owner: 'Kwame Boateng', status: { text: 'Done', tone: 'green' } },
        ], 'No selling activity is scheduled for today.'),
      table('targets', 'What each seller carries this month', 'Targets set for the period and the activity behind them.',
        ['seller', 'nights', 'revenue', 'activities'], [
          { seller: 'Akosua Mensah', nights: '220', revenue: '248,000.00', activities: '38' },
          { seller: 'Kwame Boateng', nights: '180', revenue: '196,000.00', activities: '31' },
        ], 'No target has been set for this month.'),
      list('lost', 'Behind the numbers', 'Enquiries slipping and the reasons being recorded.', [
        li('3 activities are overdue', 'oldest 02 Nov', 'red'),
        li('1 lost today', 'Rate — competitor undercut us', 'amber'),
        li('4 enquiries below 25 % confidence', 'qualify or close them', 'amber'),
      ], 'Nothing behind the numbers today.'),
    ],
    watch: ['3 sales activities are overdue'],
    todo: ['Work the overdue activities first.', 'Update every enquiry that moved stage today.'],
    sources: 'sales_opportunities, sales_activities, sales_targets, reservations, companies, users',
  };
}

// ── guest experience: what the guests wrote and the cases that need an answer ────────
function guestExpPack() {
  return {
    key: 'guestexp', label: 'Guest Experience', role: 'Guest Relations Manager', icon: 'star', perm: 'feedback.view',
    title: 'Guest Relations Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Efua Appiah', '08:40', 'Two long-stay guests to be seen personally.'),
    headline: [
      st('Feedback received', '31', '4.4 / 5 average', 'blue', 'star'),
      st('Net promoter', '58.0', '22 promoters, 4 detractors', 'green', 'trend'),
      st('Reviews in', '6', '4.2 / 5 average', 'purple', 'globe'),
      st('Awaiting a reply', '5', '2 of them are complaints', 'red', 'mail'),
      st('Open cases', '7', '2 high severity', 'amber', 'alert'),
      st('Compliments', '24', 'guests who wrote in happy', 'teal', 'check'),
    ],
    sections: [
      table('reviews', 'Reviews on the business date', 'What the review sites showed and whether the property has answered.',
        ['source', 'rating', 'status', 'replied'], [
          { source: 'Tripadvisor', rating: '4.0', status: { text: 'New', tone: 'blue' }, replied: { text: 'No', tone: 'red' } },
          { source: 'Google', rating: '3.0', status: { text: 'Draft', tone: 'blue' }, replied: { text: 'No', tone: 'red' } },
          { source: 'Booking.com', rating: '4.8', status: { text: 'Posted', tone: 'green' }, replied: { text: 'Yes', tone: 'green' } },
        ], 'No review was left on this date.'),
      table('urgent', 'Cases that need an answer', 'Open recovery cases, the most severe and the soonest due first.',
        ['case', 'about', 'area', 'due', 'owner'], [
          { case: 'FC-0312', about: { text: 'Air conditioning failed in 214', tone: 'red' }, area: 'Engineering', due: { text: 'Thu 5 Nov 18:00', tone: 'red' }, owner: 'Efua Appiah' },
          { case: 'FC-0314', about: { text: 'Dinner served cold in the restaurant', tone: 'red' }, area: 'Kitchen', due: { text: 'Fri 6 Nov 12:00', tone: 'slate' }, owner: 'Efua Appiah' },
          { case: 'FC-0316', about: { text: 'Airport transfer arrived late', tone: 'amber' }, area: 'Front Office', due: { text: 'Fri 6 Nov 09:00', tone: 'slate' }, owner: 'Unassigned' },
        ], 'No case is open.'),
      table('written', 'What the guests wrote', 'The last few days of comments, unedited.',
        ['guest', 'score', 'mood', 'said'], [
          { guest: 'Adjoa Sarpong', score: '5.0', mood: { text: 'Positive', tone: 'green' }, said: 'The beach dinner was the best part of the trip — please thank the team.' },
          { guest: 'Kwesi Amankwah', score: '2.0', mood: { text: 'Negative', tone: 'red' }, said: 'Air conditioning did not cool the room and nobody came until the next morning.' },
          { guest: 'Linda Owusu', score: '4.0', mood: { text: 'Positive', tone: 'green' }, said: 'Clean, quiet, and the breakfast spread is excellent.' },
        ], 'No guest has written in for three days.'),
      table('sources', 'Where the feedback came from', 'Score and volume by channel.',
        ['source', 'count', 'avg'], [
          { source: 'Positive', count: '24', avg: '4.8' },
          { source: 'Neutral', count: '4', avg: '3.5' },
          { source: 'Negative', count: '3', avg: '2.1' },
        ], 'No feedback was captured on this date.'),
      list('cases', 'Complaints still open', 'Cases being worked, and who owes the guest an answer.', [
        li('2 negative reviews still unanswered', 'the guest is waiting', 'red'),
        li('7 recovery cases open', '2 high severity', 'amber'),
        li('5 reviews awaiting approval or posting', 'approve and reply', 'amber'),
      ], 'Every guest has been answered.'),
    ],
    watch: ['2 negative reviews have no reply', '2 high-severity recovery cases are open'],
    todo: ['Answer every review, starting with the lowest score.', 'Close the recovery cases that have been compensated.'],
    sources: 'guest_feedback, online_reviews, feedback_cases, guests, users',
  };
}


// ── banquet: the orders still unpublished and the finished events not billed ────────
function banquetPack() {
  return {
    key: 'banquet', label: 'Banquet', role: 'Banquet Manager', icon: 'layers', perm: 'banquet.view',
    title: 'Banquet Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Yaw Antwi', '07:45', 'Two rooms short for the Osei wedding.'),
    headline: [
      st('Events today', '3', '1 cancelled', 'blue', 'layers'),
      st('Guests expected', '410', 'across the function space', 'purple', 'users'),
      st('Value on the floor', 'GH₵ 68,400.00', 'net of tax', 'green', 'money'),
      st('Deposits outstanding', '1', 'GH₵ 12,000.00', 'amber', 'wallet'),
      st('BEO not published', '2', 'event orders still with the planner', 'amber', 'book'),
      st('Next 7 days', '6 events', '540 guests expected', 'teal', 'calendar'),
    ],
    sections: [
      table('today', 'On the floor today', 'Every event on the business date, its space, the covers and who it is for.',
        ['event', 'title', 'hall', 'pax', 'value', 'status'], [
          { event: 'BE-0412', title: 'Osei wedding reception', hall: 'Atlantic Ballroom', pax: '260', value: '42,800.00', status: { text: 'Definite', tone: 'green' } },
          { event: 'BE-0414', title: 'Ecobank board lunch', hall: 'Lagoon Room', pax: '40', value: '9,600.00', status: { text: 'Definite', tone: 'green' } },
          { event: 'BE-0416', title: 'Baby shower', hall: 'Garden Terrace', pax: '110', value: '16,000.00', status: { text: 'Tentative', tone: 'amber' } },
        ], 'No event is scheduled for this date.'),
      table('beos', 'Event orders still to publish', 'Nothing reaches the kitchen or the floor until the order is published.',
        ['event', 'title', 'hall', 'starts', 'pax'], [
          { event: 'BE-0412', title: 'Osei wedding reception', hall: 'Atlantic Ballroom', starts: { text: '18:00', tone: 'amber' }, pax: '260' },
          { event: 'BE-0416', title: 'Baby shower', hall: 'Garden Terrace', starts: { text: '15:30', tone: 'amber' }, pax: '110' },
        ], 'Every event order on the floor is published.'),
      table('unbilled', 'Finished, not yet billed', 'Events that have closed and left the building with the bill still to raise.',
        ['event', 'client', 'closed', 'pax', 'value'], [
          { event: 'BE-0409', client: 'Tullow Ghana', closed: '04 Nov', pax: '80', value: { text: '21,600.00', tone: 'amber' } },
        ], 'Every closed event has been billed.'),
      list('settle', 'Money and paperwork', 'What the banquet manager has to close today.', [
        li('1 events past their deposit date with nothing received', 'chase the client today', 'red'),
        li('2 event orders not yet published', 'kitchen and service are working blind', 'amber'),
        li('1 finished events not yet billed', 'GH₵ 21,600.00', 'amber'),
        li('1 tentative events today', 'confirm or release the space', 'amber'),
      ], 'Nothing outstanding on the floor.'),
      table('week', 'The week ahead', 'Events already contracted, so the kitchen and the floor can plan.',
        ['when', 'count', 'pax'], [
          { when: 'Fri 6 Nov', count: '2', pax: '180' },
          { when: 'Sat 7 Nov', count: '3', pax: '320' },
          { when: 'Sun 8 Nov', count: '1', pax: '40' },
        ], 'No event is booked in the next seven days.'),
    ],
    watch: ['1 events are past their deposit date', '1 closed events are still unbilled'],
    todo: ['Publish the 2 missing event order(s).', 'Bill the events that closed, against the guaranteed covers.'],
    sources: 'banquet_events, banquet_halls, banquet_event_items, companies',
  };
}

// ── Fleet: the board, the crew, what the trips earned and what the cars cost ─────────
// Mirrors buildFleet() section for section: mv, board, crew, billing, cost, workshop,
// faults, rentals, desk. The Hilux is the car that cannot move — its fitness paper has
// lapsed and its service is overdue — so every warning in the pack is one story.
function fleetPack() {
  return {
    key: 'fleet', label: 'Transport & Fleet', role: 'Transport Supervisor', icon: 'car', perm: 'transport.view',
    title: 'Transport Supervisor — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Yaw Antwi', '07:35', 'Hilux grounded — fitness expired, booked for the test on Monday.'),
    headline: [
      st('Trips today', '26', '71 passengers · 8 pick-ups', 'blue', 'car'),
      st('On the road now', '5', '18 completed · 2 lost', 'amber', 'truck'),
      st('Not yet assigned', '3', 'no vehicle or driver', 'red', 'alert'),
      st('Trip revenue', 'GH₵ 6,480.00', 'GH₵ 1,050.00 given complimentary', 'green', 'money'),
      st('Vehicles available', '7', '1 in the workshop of 8', 'amber', 'tool'),
      st('Papers and licences', '2', '2 more due within 30 days', 'red', 'id'),
    ],
    sections: [
      table('mv', 'The day\'s movements', 'Every transfer, pick-up and drop on the business date, by type.',
        ['type', 'trips', 'pax', 'done', 'open', 'value'], [
          { type: 'Pick-up', trips: '8', pax: '19', done: '5', open: { text: '3', tone: 'amber' }, value: '2,180.00' },
          { type: 'Drop', trips: '11', pax: '28', done: '9', open: { text: '2', tone: 'amber' }, value: '2,640.00' },
          { type: 'Transfer / local', trips: '7', pax: '24', done: '4', open: { text: '3', tone: 'amber' }, value: '1,660.00' },
        ], 'No movement is scheduled for this date.'),
      table('board', 'The board — which car can move',
        'Each vehicle as the dispatcher sees it: what it did today and whether its papers would stop it.',
        ['vehicle', 'type', 'seats', 'trips', 'km', 'state'], [
          { vehicle: 'HIACE-1 · GT 4821-21', type: 'Van', seats: '12', trips: '9', km: '214', state: { text: 'In order', tone: 'green' } },
          { vehicle: 'CAMRY-1 · GE 2210-20', type: 'Sedan', seats: '4', trips: '7', km: '96', state: { text: 'Insurance due', tone: 'amber' } },
          { vehicle: 'SORENTO-1 · GR 3390-23', type: 'SUV / MUV', seats: '6', trips: '4', km: '58', state: { text: 'In order', tone: 'green' } },
          { vehicle: 'HILUX-1 · GT 7712-22 · workshop', type: 'SUV / MUV', seats: '5', trips: '0', km: '0', state: { text: 'Fitness expired', tone: 'red' } },
          { vehicle: 'COASTER-1 · GW 1180-19', type: 'Mini-bus (Tempo Traveller)', seats: '26', trips: '6', km: '312', state: { text: 'In order', tone: 'green' } },
        ], 'No vehicle is on the books.'),
      table('crew', 'The drivers',
        'Who is on, what they drove and how long they were out — with the licence that would ground them.',
        ['driver', 'licence', 'trips', 'out', 'state'], [
          { driver: 'Yaw Antwi', licence: '14 Aug 2028', trips: '8', out: '7h 20m', state: { text: 'In order', tone: 'green' } },
          { driver: 'Kwesi Mensah', licence: '02 Oct 2026', trips: '6', out: '5h 45m', state: { text: 'Licence expired', tone: 'red' } },
          { driver: 'Ibrahim Sule', licence: '30 Jan 2029', trips: '7', out: '6h 10m', state: { text: 'In order', tone: 'green' } },
          { driver: 'Joseph Owusu', licence: '—', trips: '5', out: '4h 05m', state: { text: 'No licence on file', tone: 'amber' } },
        ], 'No driver is on the roster.'),
      table('billing', 'What the trips earned, and how it was billed',
        'A room charge is money on a folio; a direct trip is cash the driver must hand in; a complimentary trip is a cost dressed as revenue.',
        ['how', 'trips', 'value', 'note'], [
          { how: 'Post to room bill', trips: '17', value: '4,920.00', note: 'posted to the guest folio' },
          { how: 'Guest pays the driver / desk', trips: '6', value: '1,560.00', note: 'paid to the driver or the desk' },
          { how: 'Complimentary', trips: '3', value: '1,050.00', note: 'never charged — carried at fare value' },
        ], 'No trip carried a price on this date.'),
      table('cost', 'Fuel and upkeep today',
        'What the cars cost to run on the business date, against the kilometres they actually covered.',
        ['kind', 'count', 'litres', 'amount'], [
          { kind: 'Fuel', count: '2', litres: '64.0', amount: '1,240.00' },
          { kind: 'Toll', count: '3', litres: '—', amount: '90.00' },
          { kind: 'Parking', count: '1', litres: '—', amount: '40.00' },
        ], 'Nothing was spent on the fleet on this date.'),
      table('workshop', 'In the workshop, and what is due',
        'Work orders still open, worst first — a car in the workshop cannot take the morning airport run.',
        ['vehicle', 'job', 'due', 'priority', 'est'], [
          { vehicle: 'HILUX-1 · GT 7712-22', job: '40,000 km service (Preventive service)', due: { text: '03 Nov', tone: 'red' }, priority: 'Urgent', est: '1,850.00' },
          { vehicle: 'HILUX-1 · GT 7712-22', job: 'Front brake pads (Repair)', due: 'Mon 9 Nov', priority: 'High', est: '720.00' },
          { vehicle: 'HIACE-1 · GT 4821-21', job: 'Tyres — rotate and balance (Tyres)', due: 'Fri 13 Nov', priority: 'Normal', est: '480.00' },
        ], 'Nothing is in the workshop.'),
      table('faults', 'Defects, incidents and failed inspections',
        'The record behind every car that should not be handed to a guest.',
        ['when', 'what', 'vehicle', 'state'], [
          { when: '05 Nov', what: 'Inspection: Minor — wiper blades worn', vehicle: 'CAMRY-1 · GE 2210-20', state: { text: 'Open defect', tone: 'red' } },
          { when: '04 Nov', what: 'Damage · Minor', vehicle: 'HILUX-1 · GT 7712-22', state: { text: 'Under investigation', tone: 'amber' } },
        ], 'No defect or incident is on record.'),
      table('rentals', 'Self-drive rentals',
        'Cars out on a rental agreement, and what is due back — the same fleet, on a different promise.',
        ['rental', 'customer', 'due', 'value', 'state'], [
          { rental: 'CR-0418', customer: 'Adjoa Boateng', due: { text: '05 Nov 18:00', tone: 'red' }, value: '1,200.00', state: { text: 'On rent', tone: 'green' } },
          { rental: 'CR-0419', customer: 'Tullow Ghana', due: { text: '07 Nov 09:00', tone: 'slate' }, value: '2,400.00', state: { text: 'On rent', tone: 'green' } },
          { rental: 'CR-0421', customer: 'Kofi Asante', due: { text: '10 Nov 09:00', tone: 'slate' }, value: '900.00', state: { text: 'Reserved', tone: 'blue' } },
        ], 'No rental is out or booked.'),
      list('desk', 'The transport desk', 'What has to be fixed before the cars move.', [
        li('3 trips have no vehicle or driver', 'assign before the pickup', 'red'),
        li('1 trips are past their window', 'call the driver and close the trip', 'red'),
        li('2 vehicles have a paper expired or due', 'a lapsed permit grounds the car', 'red'),
        li('1 drivers have an expired licence', 'they cannot be assigned', 'red'),
        li('1 workshop jobs are past their due date', 'the car stays in the yard', 'amber'),
        li('1 incidents are still open', 'insurance cannot be claimed until they close', 'amber'),
        li('1 rentals are past their return time', 'the car is on someone else\'s promise', 'red'),
        li('2 room trips are not yet on a folio', 'GH₵ 610.00 posts at check-out', 'amber'),
        li('The cars ran 680 km on GH₵ 1,330.00 of fuel', 'about GH₵ 1.96 a kilometre', 'slate'),
      ], 'The desk is clear.'),
    ],
    watch: ['3 trips are unassigned', '1 trips are past their window', '2 vehicles have an expired paper',
      '1 drivers have an expired licence', '1 workshop jobs are overdue', '1 rentals are past their return time',
      '1 vehicle incidents are open'],
    todo: [
      'Assign the 3 unassigned trips and tell the guests their driver.',
      'Renew the papers on the 2 vehicles that cannot legally move.',
      'Take the 1 drivers with a lapsed licence off the roster today.',
    ],
    sources: 'transport_trips, vehicles, drivers, vehicle_expenses, vehicle_maintenance_records, vehicle_inspections, vehicle_incidents, car_rentals',
  };
}

// ── Spa & Recreation: the till roll, the gate, the drawer and the tax split ─────────
// Mirrors buildRecreation() band for band: tender, type, passes, sales, category, shift,
// tax, gate (list), cashiers. 63 tickets were sold, 41 passes have been presented, one
// shift is still open and one closed short — so the watch strip has something to say.
function recreationPack() {
  return {
    key: 'recreation', label: 'Spa & Recreation', role: 'Recreation Manager', icon: 'dumbbell', perm: 'club.view',
    title: 'Recreation Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Efua Appiah', '08:05', 'Gate sheet reconciled at 07:50; the short shift is the unbanked float.'),
    headline: [
      st('Takings', 'GH₵ 8,940.00', '63 tickets sold', 'green', 'money'),
      st('Guests through the gate', '41 / 63', '22 passes still to present', 'amber', 'check'),
      st('Net of tax', 'GH₵ 8,108.40', 'GH₵ 831.60 tax collected', 'blue', 'receipt'),
      st('Charged to rooms', 'GH₵ 3,120.00', 'the rest was paid at the counter', 'slate', 'bed'),
      st('Cash in the drawer', 'GH₵ 4,860.00', 'GH₵ 240.00 change given', 'teal', 'wallet'),
      st('Voids', '1', 'GH₵ 180.00', 'red', 'alert'),
    ],
    sections: [
      table('tender', 'Takings by tender', 'Every payment the counter took, split by the mode it came in on.',
        ['mode', 'count', 'amount', 'cash'], [
          { mode: 'Cash', count: '31', amount: '4,860.00', cash: { text: 'Cash', tone: 'green' } },
          { mode: 'Mobile money', count: '14', amount: '1,880.00', cash: { text: 'Other', tone: 'slate' } },
          { mode: 'Card', count: '12', amount: '1,640.00', cash: { text: 'Other', tone: 'slate' } },
          { mode: 'Room charge', count: '6', amount: '560.00', cash: { text: 'Other', tone: 'slate' } },
        ], 'No payment was taken at the outlet on this date.'),
      table('type', 'What sold, line by line', 'The tickets, day rates and services behind the total, so the manager can see what is earning.',
        ['item', 'qty', 'net', 'tax', 'total'], [
          { item: 'Day pass — adult', qty: '28', net: '2,940.00', tax: '301.35', total: '3,241.35' },
          { item: 'Day pass — child', qty: '11', net: '770.00', tax: '78.90', total: '848.90' },
          { item: 'Guest pass', qty: '18', net: '3,420.00', tax: '350.55', total: '3,770.55' },
          { item: 'Gym day rate', qty: '6', net: '978.40', tax: '100.20', total: '1,078.60' },
        ], 'Nothing was sold on this date.'),
      table('passes', 'Through the gate', 'Every pass the counter sold today and whether the gate has seen the guest.',
        ['pass', 'guest', 'kind', 'pax', 'value', 'state'], [
          { pass: 'DP-2671', guest: 'Adjoa Boateng', kind: 'Day pass', pax: '3', value: '420.00', state: { text: 'Admitted 09:12', tone: 'green' } },
          { pass: 'DP-2672', guest: 'Kwame Asare', kind: 'Day pass', pax: '2', value: '280.00', state: { text: 'Admitted 10:40', tone: 'green' } },
          { pass: 'GP-0455', guest: 'Nana Yaa (guest of Mensah)', kind: 'Guest of a member', pax: '4', value: '0.00', state: { text: 'Not presented', tone: 'amber' } },
          { pass: 'DP-2674', guest: 'Ibrahim Sule', kind: 'Day pass', pax: '2', value: '280.00', state: { text: 'Not presented', tone: 'amber' } },
          { pass: 'DP-2668', guest: 'Akosua Frimpong', kind: 'Day pass', pax: '2', value: '280.00', state: { text: 'Cancelled', tone: 'red' } },
        ], 'No pass was sold for this date.'),
      table('sales', 'Every sale at the counter', 'The till roll: what was rung, by whom it was paid and what state it is in.',
        ['sale', 'time', 'guest', 'pax', 'tender', 'total', 'state'], [
          { sale: 'CS-8841', time: '09:12', guest: 'Adjoa Boateng', pax: '3', tender: 'Cash', total: '420.00', state: { text: 'Paid', tone: 'green' } },
          { sale: 'CS-8842', time: '10:40', guest: 'Kwame Asare', pax: '2', tender: 'Mobile money', total: '280.00', state: { text: 'Paid', tone: 'green' } },
          { sale: 'CS-8843', time: '11:05', guest: 'Walk-in', pax: '—', tender: 'Card', total: '180.00', state: { text: 'Void', tone: 'red' } },
          { sale: 'CS-8844', time: '12:20', guest: 'Tullow Ghana (day out)', pax: '12', tender: 'Room charge', total: '1,680.00', state: { text: 'Paid', tone: 'green' } },
        ], 'No sale was rung on this date.'),
      table('category', 'What sold by category', 'The same sales grouped the way the Z-report groups them.',
        ['category', 'qty', 'net', 'tax'], [
          { category: 'Day pass', qty: '39', net: '3,710.00', tax: '380.25' },
          { category: 'Guest pass', qty: '18', net: '3,420.00', tax: '350.55' },
          { category: 'Gym', qty: '6', net: '978.40', tax: '100.20' },
        ], 'Nothing was sold on this date.'),
      table('shift', 'The outlet shift and the drawer',
        'Who held the float, what the till said it should hold, and what was counted.',
        ['shift', 'opened', 'closed', 'float', 'expected', 'counted', 'variance'], [
          { shift: 'Morning · Efua Appiah', opened: '07:00', closed: '14:05', float: '500.00', expected: '3,860.00', counted: '3,830.00', variance: { text: '−30.00', tone: 'red' } },
          { shift: 'Afternoon · Joseph Owusu', opened: '14:05', closed: { text: 'Still open', tone: 'amber' }, float: '400.00', expected: '1,060.00', counted: '—', variance: '—' },
        ], 'The outlet has not opened a shift on this date.'),
      table('tax', 'The tax on what sold',
        'A VAT-registered outlet files each component separately, so the briefing splits the tax the same way.',
        ['tax', 'authority', 'rate', 'amount'], [
          { tax: 'VAT', authority: 'Ghana Revenue Authority', rate: '21.00 %', amount: '698.54' },
          { tax: 'NHIL', authority: 'Ghana Revenue Authority', rate: '21.00 %', amount: '20.79' },
          { tax: 'GETFund levy', authority: 'Ghana Revenue Authority', rate: '21.00 %', amount: '20.79' },
          { tax: 'Tourism levy', authority: 'Ghana Tourism Authority', rate: '21.00 %', amount: '8.32' },
        ], 'No tax was charged on this date.'),
      list('gate', 'The gate and the drawer', 'Admissions reconciliation and what the shift still owes.', [
        li('63 tickets issued', '89 guests', 'slate'),
        li('41 admitted at the gate', '62 guests in', 'green'),
        li('22 tickets not yet presented', 'valid on their own date only', 'amber'),
        li('1 cancelled', 'refund or credit', 'red'),
        li('1 sales voided', 'GH₵ 180.00', 'red'),
        li('GH₵ 240.00 change given from the drawer', 'expected cash is the float plus takings less change', 'slate'),
      ], 'Nothing to reconcile.'),
      table('cashiers', 'On the counter', 'Who sold what, for the shift hand-over.',
        ['name', 'sales', 'total'], [
          { name: 'Efua Appiah', sales: '31', total: '4,860.00' },
          { name: 'Joseph Owusu', sales: '18', total: '2,400.00' },
        ], 'No sale was rung on this date.'),
    ],
    watch: ['1 outlet sales were voided', '22 passes sold today have not been presented',
      'A shift is still open — the drawer has not been counted', 'A closed shift does not balance'],
    todo: ['Count the drawer and close the outlet shift.',
      'Check the 22 unpresented tickets before the gate closes.',
      'Print the day report and file it with the Z.'],
    sources: 'club_ticket_sales, club_ticket_items, club_ticket_payments, club_day_passes, department_shift_sessions, tax components',
  };
}

// ── IT: what actually failed, and who is not taking our updates ─────────────────────
function itPack() {
  return {
    key: 'it', label: 'IT', role: 'IT Manager', icon: 'link', perm: 'interfaces.view',
    title: 'IT Manager — daily briefing', date: day, is_exec: false, house: HOUSE,
    signoff: signoffFor('Nana Yeboah', '08:30', 'PBX gateway replaced at 06:40.'),
    headline: [
      st('Interface commands', '1,842', '3 failed', 'red', 'link'),
      st('Commands waiting', '5', 'queued or sent, no answer', 'amber', 'clock'),
      st('Webhooks delivered', '312', '4 failed', 'red', 'globe'),
      st('Kitchen tickets', '206', '2 never printed', 'red', 'print'),
      st('Night audit', 'Completed', 'business date 05 Nov', 'green', 'moon'),
      st('Kiosk check-ins', '18', 'self-service sessions today', 'blue', 'qr'),
    ],
    sections: [
      table('interfaces', 'Interfaces', 'What the PMS told the locks, the PBX and the TVs today.',
        ['system', 'commands', 'failed'], [
          { system: 'Locks', commands: '1,204', failed: { text: '1', tone: 'red' } },
          { system: 'Pbx', commands: '386', failed: { text: '2', tone: 'red' } },
          { system: 'Tv', commands: '252', failed: { text: '0', tone: 'green' } },
        ], 'No interface command was issued on this date.'),
      table('broken', 'What failed today', 'The commands that did not get through, oldest first.',
        ['system', 'command', 'when', 'tries', 'answer'], [
          { system: 'Pbx', command: 'wake_up_call', when: { text: '06:12', tone: 'red' }, tries: '5', answer: 'gateway timeout after 5 s' },
          { system: 'Locks', command: 'encode_key', when: { text: '09:41', tone: 'red' }, tries: '3', answer: 'card encoder offline' },
          { system: 'Pbx', command: 'checkout_clear', when: { text: '11:05', tone: 'red' }, tries: '2', answer: 'no route to extension 214' },
        ], 'Nothing failed on this date.'),
      table('endpoints', 'Endpoints not answering', 'Who is not taking our updates, and what they said.',
        ['endpoint', 'event', 'code', 'tries'], [
          { endpoint: 'Channel manager', event: 'room.rate_changed', code: { text: '502', tone: 'red' }, tries: '6' },
          { endpoint: 'Accounting bridge', event: 'folio.posted', code: { text: 'none', tone: 'red' }, tries: '4' },
        ], 'Every endpoint is answering.'),
      table('webhooks', 'Webhook deliveries', 'Outbound events and how the far end answered.',
        ['status', 'count', 'ms'], [
          { status: 'Delivered', count: '308', ms: '214' },
          { status: 'Failed', count: '4', ms: '3,002' },
        ], 'Nothing was delivered on this date.'),
      list('queue', 'What needs a hand', 'Failures and queues the IT desk has to clear.', [
        li('3 interface commands failed', 'retry from the interface screen', 'red'),
        li('4 webhook deliveries failed', 'check the endpoint and retry', 'red'),
        li('5 commands are waiting for an answer', 'check the interface PC', 'amber'),
        li('2 kitchen tickets were never printed', 'printer or paper', 'red'),
      ], 'Everything is clean.'),
    ],
    watch: ['3 interface failures today', '4 failed webhook deliveries'],
    todo: ['Clear the failed interface commands and note what caused them.', 'Check the printers before the next service.'],
    sources: 'interface_commands, webhook_deliveries, webhook_endpoints, kot_print_jobs, audit_days, kiosk_sessions',
  };
}

// ── contexts the build script hands to the renderer ───────────────────────────────────
const base = (extra) => ({
  helpers: HELPERS,
  scope: { ...extra },
  fallbacks: {},
});

export function briefingHubContext() {
  return base({
    packs: packIndex(BRIEFS),
    hidden: 2,
    canExec: true,
    signoffs: Coll.of(PACKS.filter(p => p[4]).map(p => ({ department_key: p[0], user: { name: p[5] } }))),
    date: day,
  });
}

const withPacks = (pack) => base({ pack, date: day, today: day, packs: packIndex(BRIEFS) });

export const briefingFnbContext = () => withPacks(fnbPack());
export const briefingHousekeepingContext = () => withPacks(housekeepingPack());
export const briefingLaundryContext = () => withPacks(laundryEarlyPack());
export const briefingFinanceContext = () => withPacks(financePack());
export const briefingHrContext = () => withPacks(hrPack());
export const briefingParkingContext = () => withPacks(parkingPack());
export const briefingFrontOfficeContext = () => withPacks(frontOfficePack());
export const briefingSecurityContext = () => withPacks(securityPack());
export const briefingKitchenContext = () => withPacks(kitchenPack());
export const briefingStoresContext = () => withPacks(storesPack());
export const briefingRevenueContext = () => withPacks(revenuePack());
export const briefingMaintenanceContext = () => withPacks(maintenancePack());
export const briefingSalesContext = () => withPacks(salesPack());
export const briefingGuestExpContext = () => withPacks(guestExpPack());
export const briefingBanquetContext = () => withPacks(banquetPack());
export const briefingItContext = () => withPacks(itPack());
export const briefingFleetContext = () => withPacks(fleetPack());
export const briefingRecreationContext = () => withPacks(recreationPack());

export function briefingExecContext() {
  return base({ pack: execPack(), date: day, today: day });
}

export function briefingPrintContext() {
  return base({ pack: fnbPack() });
}

// ── the briefing book: every pack in one printable document ────────────────────────────
// Three packs already exist above in full (F&B, housekeeping, laundry). The other fifteen
// are described here compactly: six headline figures, the one table that department reads
// first, and what the head has to act on.
const btable = (key, title, note, cols, rows, empty = 'Nothing was posted for this department on the business date.') => ({
  key, title, note, kind: 'table', empty, cols,
  rows: rows.map(row => Object.fromEntries(cols.map(column => [column.key, cell(row[column.key])]))),
});

const BOOK_ONLY = {
  frontoffice: {
    head: [['Arrivals due', '46', '12 before noon', 'green', 'bell'], ['Departures', '38', '30 cleared by 11:00', 'green', 'swap'],
      ['Occupancy', '84.5 %', '▲ 5.3 pts vs last Wed', 'green', 'bed'], ['Rooms out of order', '2', '210, 214', 'amber', 'tool'],
      ['Guest requests open', '3', '2 past their promise', 'amber', 'message'], ['Cashiered at the desk', 'GH₵ 41,280.00', 'every drawer balanced', 'green', 'wallet']],
    title: 'The desk today', note: 'Movements posted against the business date.',
    cols: [{ key: 'item', label: 'Movement' }, { key: 'qty', label: 'Rooms' }, { key: 'amount', label: 'Note' }],
    rows: [{ item: 'Arrivals', qty: '46', amount: '12 early' }, { item: 'Departures', qty: '38', amount: '8 still to clear' },
      { item: 'Stayovers', qty: '81', amount: '' }, { item: 'No-shows', qty: '1', amount: 'Boateng, 3 nights' },
      { item: 'Walk-ins', qty: '4', amount: 'all rack rate' }],
    watch: ['3 guest requests are open, 2 past their promise.'],
    todo: ['Clear the two overdue requests before noon.', 'Confirm the 8 departures still to clear before 11:00.', 'Release the 2 out-of-order rooms only after engineering signs them off.'],
    sources: 'reservations, reservation_rooms, folio_transactions, guest_requests, traces',
  },
  kitchen: {
    head: [['Tickets fired', '214', '38 at the busiest hour', 'slate', 'coffee'], ['Fire to pass', '14.2 min', '▼ 1.4 min vs last week', 'green', 'trend'],
      ['Dishes sold', '486', 'across 4 outlets', 'green', 'cup'], ['Allergen flags', '3', 'all acknowledged', 'amber', 'alert'],
      ['Stock-outs', '1', 'prawns at the beach bar', 'amber', 'box'], ['Kitchen waste', 'GH₵ 420.00', '1.1 % of sales', 'green', 'layers']],
    title: 'What sold', note: 'Dishes cleared through the pass on the business date.',
    cols: [{ key: 'item', label: 'Dish' }, { key: 'qty', label: 'Sold' }, { key: 'amount', label: 'Revenue' }],
    rows: [{ item: 'Grilled tilapia & banku', qty: '42', amount: '6,510.00' }, { item: 'Jollof with grilled chicken', qty: '38', amount: '4,940.00' },
      { item: 'Club sandwich', qty: '31', amount: '2,635.00' }, { item: 'Fresh coconut', qty: '29', amount: '1,015.00' },
      { item: 'Beef khebab (plate)', qty: '24', amount: '2,400.00' }],
    watch: ['One dish is out of stock — prawns at the beach bar.'],
    todo: ['Re-cost the khebab plate; the last three deliveries moved.'],
    sources: 'kot_print_jobs, pos_order_items, recipes',
  },
  banquet: {
    head: [['Events today', '3', '2 in the Owuo hall', 'purple', 'layers'], ['Covers promised', '180', 'per the function sheets', 'slate', 'users'],
      ['Covers billed', '174', '6 short of promise', 'amber', 'receipt'], ['Function sheets open', '1', 'Owuo hall, evening', 'amber', 'calendar'],
      ['AV issues', '0', 'sound and projector checked', 'green', 'wifi'], ['Event revenue', 'GH₵ 42,600.00', 'posted net of tax', 'green', 'money']],
    title: 'On the floor today', note: 'Functions running against the business date.',
    cols: [{ key: 'item', label: 'Event' }, { key: 'value', label: 'Covers' }, { key: 'note', label: 'Hall' }],
    rows: [{ item: 'Amankwah wedding lunch', value: '110', note: 'Owuo hall' }, { item: 'Bank of Ghana seminar', value: '48', note: 'Owuo hall' },
      { item: 'Board dinner', value: '16', note: 'Terrace room' }],
    watch: ['The evening function sheet for the Owuo hall is still open.'],
    todo: ['Close the evening function sheet before 15:00.', 'Reconcile the 6 covers not billed.'],
    sources: 'banquet_events, banquet_functions, banquet_halls',
  },
  sales: {
    head: [['Enquiries taken', '12', '4 from the website', 'green', 'trend'], ['Site inspections', '4', '2 corporate, 2 wedding', 'green', 'users'],
      ['Pipeline', 'GH₵ 1,840,000.00', '34 live opportunities', 'purple', 'chart'], ['Proposals out', '6', 'GH₵ 412,000.00 in play', 'amber', 'receipt'],
      ['Contracts signed', '2', 'GH₵ 96,400.00', 'green', 'check'], ['Activities logged', '19', 'calls, visits, follow-ups', 'slate', 'message']],
    title: 'Pipeline by stage', note: 'Open opportunities and what each stage is worth.',
    cols: [{ key: 'item', label: 'Stage' }, { key: 'value', label: 'Opportunities' }, { key: 'note', label: 'Value' }],
    rows: [{ item: 'Qualified', value: '12', note: 'GH₵ 620,000.00' }, { item: 'Proposal sent', value: '6', note: 'GH₵ 412,000.00' },
      { item: 'Negotiation', value: '4', note: 'GH₵ 511,000.00' }, { item: 'Verbal yes', value: '2', note: 'GH₵ 297,000.00' }],
    watch: ['Six proposals are out with no follow-up date recorded.'],
    todo: ['Put a follow-up date on all six proposals.', 'Confirm the site visit for the mining company on Friday.'],
    sources: 'sales_opportunities, sales_activities',
  },
  revenue: {
    head: [['Pick-up', '27 rooms', '▲ 6 vs the same day last week', 'green', 'trend'], ['Occupancy tonight', '84.5 %', '109 of 129 sellable', 'green', 'bed'],
      ['ADR', 'GH₵ 1,453.30', '▲ 2.1 %', 'green', 'money'], ['RevPAR', 'GH₵ 1,227.98', '▲ GH₵ 61.40', 'green', 'chart'],
      ['Rate codes changed', '4', 'weekend shoulder opened', 'slate', 'layers'], ['Regrets', '9', '4 on rate, 5 on availability', 'amber', 'alert']],
    title: 'The pace', note: 'Rooms on the books for the next seven nights.',
    cols: [{ key: 'item', label: 'Night' }, { key: 'value', label: 'Rooms on books' }, { key: 'note', label: 'Occupancy' }],
    rows: [{ item: 'Thu 5 Nov', value: '109', note: '84.5 %' }, { item: 'Fri 6 Nov', value: '121', note: '93.8 %' },
      { item: 'Sat 7 Nov', value: '129', note: '100 %' }, { item: 'Sun 8 Nov', value: '96', note: '74.4 %' },
      { item: 'Mon 9 Nov', value: '74', note: '57.4 %' }],
    watch: ['Sunday and Monday are filling slowly — 74 rooms is below the pace.'],
    todo: ['Review the Sunday–Monday rate ladder before the weekend closes.'],
    sources: 'revenue_authorisations, rate_calendar, reservations, rooms',
  },
  recreation: {
    head: [['Ticket sales', '63', 'day passes and guest passes', 'green', 'star'], ['Takings', 'GH₵ 8,940.00', 'net of VAT', 'green', 'wallet'],
      ['Admissions at the gate', '41', 'of 63 tickets sold', 'slate', 'users'], ['Members in', '22', 'at the gym and pool', 'purple', 'dumbbell'],
      ['Drawer', 'GH₵ 0.00', 'shift closed and counted', 'green', 'check'], ['Passes redeemed', '12', '8 guest, 4 day', 'teal', 'receipt']],
    title: 'What sold at the outlet', note: 'Tickets and admissions posted on the business date.',
    cols: [{ key: 'item', label: 'Item' }, { key: 'qty', label: 'Sold' }, { key: 'amount', label: 'Takings' }],
    rows: [{ item: 'Day pass — adult', qty: '28', amount: '3,360.00' }, { item: 'Day pass — child', qty: '11', amount: '770.00' },
      { item: 'Guest pass', qty: '18', amount: '3,420.00' }, { item: 'Gym day rate', qty: '6', amount: '1,390.00' }],
    watch: ['22 of 63 tickets were not presented at the gate.'],
    todo: ['Reconcile the un-presented passes with the gate sheet before close.'],
    sources: 'club_ticket_sales, club_payments, recreation_shifts',
  },
  maintenance: {
    head: [['Jobs raised', '14', '6 from housekeeping', 'slate', 'tool'], ['Jobs closed', '11', '3 by the night team', 'green', 'check'],
      ['Open jobs', '23', '11 in the guest areas', 'amber', 'alert'], ['Past due', '5', 'oldest 4 days', 'red', 'trend'],
      ['Planned maintenance due', '3', 'chiller, lift, pool pump', 'amber', 'calendar'], ['Rooms out of order', '2', '210, 214', 'amber', 'bed']],
    title: 'Open jobs', note: 'Work still open at the end of the shift.',
    cols: [{ key: 'item', label: 'Area' }, { key: 'open', label: 'Open' }, { key: 'note', label: 'Oldest' }],
    rows: [{ item: 'Guest rooms', open: '11', note: '4 days' }, { item: 'Public areas', open: '5', note: '2 days' },
      { item: 'Kitchen and laundry', open: '4', note: '1 day' }, { item: 'Plant and grounds', open: '3', note: '6 days' }],
    watch: ['Five jobs are past their promised date.', 'Chiller 2 is still on bypass.'],
    todo: ['Close the four-day-old bathroom job in 214.', 'Book the chiller service before the weekend.'],
    sources: 'maintenance_requests, pm_schedules, room_outages',
  },
  security: {
    head: [['Visitors on the door', '46', '38 signed out', 'slate', 'users'], ['Gate passes out', '18', '15 returned', 'amber', 'truck'],
      ['Incidents logged', '3', '1 open, 2 closed', 'amber', 'alert'], ['Keys still out', '2', 'master 3, pool gate', 'red', 'shield'],
      ['Patrol rounds', '12 / 12', 'every round walked', 'green', 'check'], ['Lost property', '1', 'guest sunglasses', 'slate', 'box']],
    title: 'Where the day stands', note: 'Movements through the gate on the business date.',
    cols: [{ key: 'item', label: 'Item' }, { key: 'count', label: 'Today' }, { key: 'note', label: 'Note' }],
    rows: [{ item: 'Visitors signed in', count: '46', note: '8 still on site' }, { item: 'Gate passes issued', count: '18', note: '3 not yet back' },
      { item: 'Vehicle searches', count: '22', note: 'no finds' }, { item: 'Patrol rounds', count: '12', note: 'all logged' }],
    watch: ['Two keys are still out against the register.', 'One incident remains open.'],
    todo: ['Recover master 3 and the pool gate key.', 'Close the open incident with the duty manager.'],
    sources: 'visitors, gate_passes, security_incidents, key_loans',
  },
  hr: {
    head: [['Staff on duty', '118', 'of 131 rostered', 'green', 'users'], ['Absent', '4', '2 no-call', 'red', 'alert'],
      ['On leave', '6', '2 approved yesterday', 'slate', 'calendar'], ['Roster gaps', '2', 'laundry and pool bar', 'amber', 'swap'],
      ['Contracts expiring', '3', 'within 30 days', 'amber', 'receipt'], ['Overtime hours', '46', 'mostly night audit', 'slate', 'trend']],
    title: 'Attendance by department', note: 'Clock records for the business date.',
    cols: [{ key: 'item', label: 'Department' }, { key: 'count', label: 'On duty' }, { key: 'note', label: 'Absent' }],
    rows: [{ item: 'Housekeeping', count: '32', note: '1' }, { item: 'Food & beverage', count: '28', note: '1' },
      { item: 'Kitchen', count: '21', note: '2' }, { item: 'Front office', count: '14', note: '0' }, { item: 'Other', count: '23', note: '0' }],
    watch: ['Four staff did not report, two without a call.'],
    todo: ['Cover the laundry gap before the 14:00 shift.', 'Start the three contract renewals.'],
    sources: 'employees, attendances, leave_requests, roster_shifts',
  },
  finance: {
    head: [['Collected today', 'GH₵ 231,760.00', '142 receipts', 'green', 'wallet'], ['Receivables', 'GH₵ 412,880.00', 'GH₵ 96,400.00 over 30 days', 'amber', 'receipt'],
      ['Payables due this week', 'GH₵ 184,200.00', '11 invoices', 'amber', 'layers'], ['Open audit findings', '7', '2 high', 'red', 'alert'],
      ['Drawer variance', 'GH₵ 40.00', 'drawer 3 short, being followed up', 'amber', 'money'], ['VAT due', 'GH₵ 38,420.00', 'period closes on the 15th', 'slate', 'chart']],
    title: 'Where the money stands', note: 'Collections and balances as posted on the business date.',
    cols: [{ key: 'item', label: 'Item' }, { key: 'value', label: 'Today' }, { key: 'note', label: 'Note' }],
    rows: [{ item: 'Cash', value: '38,140.00', note: 'every drawer counted' }, { item: 'Mobile money', value: '61,220.00', note: 'MTN, Telecel' },
      { item: 'Cards', value: '74,400.00', note: 'settled overnight' }, { item: 'Bank transfers', value: '52,000.00', note: '2 city-ledger receipts' },
      { item: 'Room charges', value: '6,000.00', note: 'carried to the folio' }],
    watch: ['Seven income-audit findings are open, two of them high.', 'Drawer 3 was short GH₵ 40 at the count.'],
    todo: ['Clear the two high audit findings before the night audit runs.', 'Chase the GH₵ 40 drawer shortfall with the cashier.'],
    sources: 'folio_transactions, pos_payments, vouchers, supplier_invoices, audit_days, audit_findings, night_audits',
  },
  guestexp: {
    head: [['Feedback received', '24', '11 at breakfast', 'green', 'star'], ['Average rating', '4.4 / 5', '▲ 0.2 vs last week', 'green', 'trend'],
      ['Online reviews', '7', '6 answered', 'amber', 'message'], ['NPS', '+61', '4 promoters, 1 detractor', 'green', 'chart'],
      ['Complaints open', '2', 'both in the room', 'amber', 'alert'], ['Replies overdue', '1', 'Google, 2 days', 'red', 'receipt']],
    title: 'What guests said', note: 'Feedback and reviews logged on the business date.',
    cols: [{ key: 'item', label: 'Theme' }, { key: 'count', label: 'Mentions' }, { key: 'note', label: 'Sentiment' }],
    rows: [{ item: 'Staff friendliness', count: '9', note: 'positive' }, { item: 'Food quality', count: '6', note: 'positive' },
      { item: 'Room cleanliness', count: '4', note: '2 negative' }, { item: 'Beach and pool', count: '3', note: 'positive' }],
    watch: ['One review has been unanswered for two days.'],
    todo: ['Reply to the Google review before noon.', 'Close the two open room complaints with housekeeping.'],
    sources: 'guest_feedback, online_reviews, feedback_cases',
  },
  it: {
    head: [['Terminals online', '14 / 14', 'POS and kiosks', 'green', 'wifi'], ['Key cards issued', '22', '1 failed encode', 'green', 'key'],
      ['Webhook deliveries', '1,284', '2 failed and retried', 'amber', 'layers'], ['Interface commands', '318', '2 failed', 'amber', 'swap'],
      ['Night run', 'Complete', '03:10, 94 seconds', 'green', 'check'], ['Open tickets', '3', '1 with the vendor', 'slate', 'message']],
    title: 'Systems today', note: 'Interface and device activity for the business date.',
    cols: [{ key: 'item', label: 'System' }, { key: 'count', label: 'Events' }, { key: 'note', label: 'State' }],
    rows: [{ item: 'PMS to POS', count: '186', note: 'healthy' }, { item: 'Key encoder', count: '22', note: '1 retry' },
      { item: 'Channel manager', count: '94', note: 'healthy' }, { item: 'Payment terminal', count: '16', note: 'healthy' }],
    watch: ['Two interface commands failed today.'],
    todo: ['Replay the two failed commands and confirm the rooms are in sync.'],
    sources: 'interface_commands, webhook_deliveries, kot_print_jobs, kiosk_sessions',
  },
  stores: {
    head: [['Issues from the store', '38', '12 to housekeeping', 'slate', 'box'], ['Requisitions waiting', '5', 'oldest 2 days', 'amber', 'receipt'],
      ['Purchase orders raised', '4', 'GH₵ 61,200.00', 'green', 'layers'], ['Goods received', '6', 'every line counted', 'green', 'check'],
      ['At re-order level', '9', '3 critical', 'red', 'alert'], ['Stock value', 'GH₵ 612,400.00', 'as at the last count', 'purple', 'money']],
    title: 'What moved', note: 'Store issues and receipts on the business date.',
    cols: [{ key: 'item', label: 'Item' }, { key: 'qty', label: 'Issued' }, { key: 'value', label: 'Value' }],
    rows: [{ item: 'Bed linen', qty: '64', value: '4,160.00' }, { item: 'Toiletries', qty: '180', value: '1,440.00' },
      { item: 'Cleaning chemicals', qty: '22', value: '2,310.00' }, { item: 'Diesel', qty: '400', value: '6,800.00' }],
    watch: ['Nine items are at re-order level, three of them critical.'],
    todo: ['Raise the purchase orders for the three critical items today.'],
    sources: 'store_issues, requisitions, purchase_orders, goods_receipts, stock_movements',
  },
  fleet: {
    head: [['Trips run', '26', '8 to the airport', 'green', 'car'], ['Vehicles on the road', '5', 'of 8 in the fleet', 'slate', 'truck'],
      ['Revenue', 'GH₵ 6,480.00', 'all billed to rooms', 'green', 'money'], ['Fuel', 'GH₵ 1,240.00', '2 fills', 'slate', 'wallet'],
      ['Maintenance due', '1', 'Hilux, service overdue', 'amber', 'tool'], ['Booked tomorrow', '11', '3 airport runs', 'purple', 'calendar']],
    title: 'The fleet today', note: 'Trips against the business date.',
    cols: [{ key: 'item', label: 'Vehicle' }, { key: 'qty', label: 'Trips' }, { key: 'value', label: 'Revenue' }],
    rows: [{ item: 'Toyota Hiace (GT 4821-21)', qty: '9', value: '2,430.00' }, { item: 'Toyota Camry (GE 2210-20)', qty: '7', value: '1,960.00' },
      { item: 'Toyota Hilux (GT 7712-22)', qty: '6', value: '1,380.00' }, { item: 'Kia Sorento (GR 3390-23)', qty: '4', value: '710.00' }],
    watch: ['The Hilux is past its service date.'],
    todo: ['Book the Hilux service before it goes out again.'],
    sources: 'transport_trips, vehicles',
  },
  parking: {
    head: [['Cars in the bays', '64 / 80', 'evening peak expected', 'slate', 'truck'], ['Valet calls answered', '23', 'all under 6 minutes', 'green', 'bell'],
      ['Revenue', 'GH₵ 2,340.00', 'charged on exit', 'green', 'wallet'], ['Permits expiring', '4', 'within 14 days', 'amber', 'receipt'],
      ['Overstays', '2', 'followed up', 'amber', 'alert'], ['Incidents', '0', 'no damage reported', 'green', 'check']],
    title: 'The bays today', note: 'Sessions charged on the business date.',
    cols: [{ key: 'item', label: 'Zone' }, { key: 'qty', label: 'Cars' }, { key: 'value', label: 'Revenue' }],
    rows: [{ item: 'Main car park', qty: '42', value: '1,470.00' }, { item: 'Beach front', qty: '16', value: '640.00' },
      { item: 'Staff and valet', qty: '6', value: '230.00' }],
    watch: ['Four permits expire within a fortnight.'],
    todo: ['Call the four permit holders before they lapse.'],
    sources: 'parking_sessions, parking_zones, parking_permits',
  },
};

const BOOK_BUILT = { fnb: fnbPack, housekeeping: housekeepingPack, laundry: laundryEarlyPack };

function bookPackFrom([key, label, role, icon, signed, signedBy, signedAt, note]) {
  const spec = BOOK_ONLY[key];
  const sections = [btable(`${key}-main`, spec.title, spec.note, spec.cols, spec.rows)];

  return {
    key, label, role, icon, perm: `${key}.view`, title: `${role} — daily briefing`, date: day,
    house: HOUSE, is_exec: false, signoff: signed ? signoffFor(signedBy, signedAt, note) : null,
    headline: spec.head.map(([l, v, s, t, i]) => st(l, v, s, t, i)),
    sections,
    watch: spec.watch ?? [],
    todo: spec.todo,
    sources: spec.sources,
  };
}

const bookPacks = () => Coll.of(PACKS.map(entry => (BOOK_BUILT[entry[0]] ?? bookPackFrom)(entry)));

/** The briefing book: the executive brief followed by all eighteen department packs. */
export function briefingBookContext() {
  return base({ exec: execPack(), packs: bookPacks(), date: day, today: day });
}
