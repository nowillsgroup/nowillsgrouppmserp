/** Static sample for the real checkout Blade; no data or transactions are live. */
import { Coll, SampleDate, raw } from './blade-render.mjs';
import { walkinContext } from './walkin-sample.mjs';

export function checkoutLaundryContext() {
  const ctx = walkinContext();
  const date = value => new SampleDate(`${value}T09:00:00Z`);
  const empty = () => Coll.of([]);
  const laundry = { id: 1, order_no: 'LND-000412', status: 'processing', total: 150, statusLabel: () => 'Processing' };
  const stay = { id: 1, reservation_id: 1, arrival_date: date('2026-11-03'), room: { id: 202, room_no: '202' }, reservation: { reservation_no: 'RES-00124', guest: { full_name: 'Ama Mensah' }, billing_instruction: 'guest', company_id: null } };
  const holds = { pos: { count: 0, total: 0 }, trips: { count: 0, total: 0 }, activities: { count: 0, total: 0 }, formc: { count: 0, total: 0 }, laundry: { count: 1, total: 150 }, blocking: 1 };
  Object.assign(ctx.scope, { stay, folio: { id: 1, folio_no: 'FOL-00124' }, totals: { charges: 900, tax: 0, discount: 0, payments: 200, refunds: 0, balance: 700 }, checkoutDue: 700, checkoutFigures: { missing: 0 }, checkoutHolds: holds, holds,
    checkoutQuote: 'Open laundry blocks checkout: 1 order(s). Deliver and settle or cancel each order first. Nothing is saved until you complete checkout.',
    canViewFolio: true, transactions: Coll.of([{ business_date: date('2026-11-03'), description: 'Room tariff', type: 'charge', amount: 900, tax_amount: 0, total: 900, is_void: false, paymentMode: null }, { business_date: date('2026-11-03'), description: 'Advance received', type: 'payment', amount: 200, tax_amount: 0, total: 200, is_void: false, paymentMode: { name: 'Cash' } }]),
    openPos: empty(), openLaundry: Coll.of([laundry]), openParking: empty(), openTrips: empty(), deskOpen: empty(), deskWakeups: empty(), deskItems: empty(), formcOpen: empty(), forexOk: false,
    companies: {}, paymentModes: { 1: 'Cash', 2: 'Card', 3: 'Mobile money' },
    info: {}, guestName: 'Ama Mensah', currentBalance: 700, projectedBalance: 700, checkoutDate: date('2026-11-05'), postable: false,
  });
  ctx.helpers.status_badge = () => raw('<span class="badge badge-green">Checked in</span>');
  ctx.helpers.route = () => '#';
  return ctx;
}
