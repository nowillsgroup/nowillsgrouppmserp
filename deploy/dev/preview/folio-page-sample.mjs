/** Fixed, fictional data matching FolioController::show; no combined checkout is simulated. */
import { Coll, SampleDate } from './blade-render.mjs';
import { walkinContext } from './walkin-sample.mjs';

export function folioPageContext() {
  const ctx = walkinContext();
  const d = value => new SampleDate(`${value}T09:00:00Z`);
  const guest = { id: 1, full_name: 'Ama Mensah', email: 'ama@example.test', phone: '024 000 0000' };
  const reservation = { id: 1, reservation_no: 'RES-00124', guest, company: null };
  const stay = { id: 1, reservation_id: 1, reservation, guest, room: { room_no: '202' }, roomType: { name: 'Deluxe Sea View' }, status: 'checked_in', arrival_date: d('2026-11-03'), departure_date: d('2026-11-07'), rate: 950, adults: 2, children: 0, rate_plan_id: null, ratePlan: null };
  const folio = { id: 1, folio_no: 'FOL-00124', type: 'guest', status: 'open', invoice_no: null, closed_at: null, consolidated_into_folio_id: null, guest, company: null, reservation, stay, isCreditNote: () => false };
  const entry = (id, date, description, type, category, amount, tax, extra = {}) => ({ id, business_date: d(date), created_at: d(date), description, type, category, amount, tax_amount: tax, tax_rate: 21, total: amount + tax, is_void: false, isOpening: () => false, source_type: type === 'charge' ? 'night' : null, reference: null, paymentMode: null, company: null, poster: { name: 'Front Desk' }, due_date: null, cheque_number: null, consolidationTransfer: null, ...extra });
  const transactions = Coll.of([
    entry(1, '2026-11-03', 'Room tariff — Room 202 (03 Nov)', 'charge', 'room', 950, 199.50),
    entry(2, '2026-11-03', 'Advance received', 'payment', 'advance', 500, 0, { paymentMode: { name: 'Mobile Money' }, reference: 'MOMO-1024' }),
    entry(3, '2026-11-04', 'Room tariff — Room 202 (04 Nov)', 'charge', 'room', 950, 199.50),
    entry(4, '2026-11-05', 'Restaurant — dinner', 'charge', 'fnb', 200, 42, { source_type: 'pos', reference: 'POS-0042' }),
  ]);
  const totals = { charges: 2541, opening: 0, tax: 441, discount: 0, payments: 500, refunds: 0, balance: 2041 };
  Object.assign(ctx.scope, { folio, transactions, totals, fixedCharges: Coll.of([]), targets: Coll.of([]),
    categories: { fnb: 'Food & beverage', laundry: 'Laundry', misc: 'Miscellaneous' }, paymentModes: { 1: 'Cash', 2: 'Mobile Money', 3: 'Card' }, companies: {}, info: {},
    openingTx: null, linkedReservation: reservation, folioGuest: guest, folioCompany: null, incPk: Coll.of([]) });
  const permitted = new Set(['folio.view', 'folio.post', 'folio.payment', 'folio.split', 'folio.void', 'folio.discount', 'frontdesk.checkout', 'reservations.view']);
  ctx.helpers.can_do = permission => permitted.has(permission);
  ctx.helpers.status_badge = () => '<span class="badge badge-green">Open</span>';
  ctx.helpers.old = (key, fallback = null) => fallback;
  ctx.helpers.number_format = (value, places = 0) => Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: Number(places), maximumFractionDigits: Number(places) });
  const priorStatic = ctx.helpers.__static;
  ctx.helpers.__static = (cls, method, ...args) => {
    const name = String(cls).split('\\').pop();
    if (name === 'PackageService' && method === 'includedIn') return Coll.of([]);
    if (name === 'LoyaltyService' && method === 'enabled') return false;
    return priorStatic(cls, method, ...args);
  };
  const previousConfig = ctx.helpers.config;
  ctx.helpers.config = (key, fallback = null) => {
    const categories = { room: 'Room', fnb: 'Food & beverage', laundry: 'Laundry', misc: 'Miscellaneous', advance: 'Advance payment' };
    if (key === 'pms.charge_categories') return categories;
    if (String(key).startsWith('pms.charge_categories.')) return categories[String(key).split('.').pop()] ?? fallback;
    return previousConfig(key, fallback);
  };
  return ctx;
}
