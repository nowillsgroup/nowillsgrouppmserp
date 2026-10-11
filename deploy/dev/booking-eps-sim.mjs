/**
 * Booking.com EPS (External Connectivity Standard) – local simulator.
 *
 * Speaks the same dialect as BookingEpsChannelDriver:
 *   GET  /eps/v1/properties/:id              handshake
 *   POST /eps/v1/ari                         OTA_HotelAvailNotif / OTA_HotelRateAmountNotif
 *   GET  /eps/v1/reservations?since=ISO      OTA_HotelResNotif list (queued, served once per pull)
 *   POST /eps/v1/reservations/:id/cancel     cancellation advice
 *   POST /__queue                            add a reservation to the queue (testing)
 *
 * Auth: Authorization: Bearer <secret>  (any non-empty secret if --secret omitted)
 * Usage: bun deploy/dev/booking-eps-sim.mjs --port=8093 --secret=epstest
 */
import http from 'node:http';

const args = Object.fromEntries(process.argv.slice(2).map(a => {
  const m = a.match(/^--([^=]+)=?(.*)$/); return m ? [m[1], m[2]] : [a, ''];
}));
const PORT = Number(args.port ?? 8093);
const SECRET = String(args.secret ?? '');

const state = {
  ariPushes: [],        // {at, message_type, rooms, room_nights}
  queue: [],            // pending reservations
  served: [],           // already pulled
  cancelled: [],
};
const day = n => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);

// Seed the queue with a fresh Booking.com reservation and a modification.
state.queue.push({
  reservation_id: 'BDC-4457781202', status: 'new', checkin: day(5), checkout: day(8),
  room_code: 'STD', rate_code: 'BAR', adults: 2, children: 1, total: 4200.0, currency: 'GHS',
  guest: { first_name: 'Johannes', last_name: 'Müller', email: 'jm@example.de', phone: '+49 170 1234567', country: 'Germany' },
  special_requests: 'High floor — arriving late evening',
});
state.queue.push({
  reservation_id: 'BDC-4457780991', status: 'modified', checkin: day(12), checkout: day(14),
  room_code: 'DLX', rate_code: 'BAR', adults: 2, children: 0, total: 6400.0, currency: 'GHS',
  guest: { first_name: 'Sophie', last_name: 'Dubois', email: 'sophie.d@example.fr', phone: '+33 6 12 34 56 78', country: 'France' },
  special_requests: '',
});

const json = (res, code, obj) => {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
};

const authed = req => {
  const a = req.headers.authorization ?? '';
  if (!a.startsWith('Bearer ') || !a.slice(7)) return false;
  return !SECRET || a.slice(7) === SECRET;
};

http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  const p = u.pathname;

  if (req.method === 'GET' && (p === '/' || p === '/status')) {
    const html = `<html><body style="font-family:system-ui;padding:24px">
      <h2>Booking.com EPS simulator</h2>
      <p>Dialect: EPS JSON (OTA_HotelAvailNotif / OTA_HotelRateAmountNotif / OTA_HotelResNotif)</p>
      <ul>
        <li>ARI pushes received: <b>${state.ariPushes.length}</b> (last: ${state.ariPushes.at(-1)?.at ?? '—'}, room-nights total: <b>${state.ariPushes.reduce((s, x) => s + x.room_nights, 0)}</b>)</li>
        <li>Reservation queue: <b>${state.queue.length}</b> pending · ${state.served.length} served · ${state.cancelled.length} cancelled</li>
      </ul>
      <h3>Queue</h3><pre>${JSON.stringify([...state.queue, ...state.served], null, 2)}</pre>
    </body></html>`;
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(html);
  }

  if (!authed(req)) return json(res, 401, { error: 'invalid or missing bearer token' });

  if (req.method === 'GET' && /^\/eps\/v1\/properties\/[^/]+$/.test(p)) {
    return json(res, 200, { property_id: decodeURIComponent(p.split('/').pop()), name: 'Simulated Booking.com property', status: 'active', currency: 'EUR' });
  }

  if (req.method === 'POST' && p === '/eps/v1/ari') {
    let raw = '';
    req.on('data', c => raw += c);
    req.on('end', () => {
      let body; try { body = JSON.parse(raw || '{}'); } catch { return json(res, 400, { error: 'invalid JSON' }); }
      const rooms = Array.isArray(body.rooms) ? body.rooms : [];
      const roomNights = rooms.reduce((s, r) => s + (Array.isArray(r.dates) ? r.dates.length : 0), 0);
      state.ariPushes.push({ at: new Date().toISOString(), message_type: body.message_type ?? '?', rooms: rooms.length, room_nights: roomNights });
      return json(res, 200, { accepted: rooms.length, room_nights: roomNights, message_type: body.message_type });
    });
    return;
  }

  if (req.method === 'GET' && p === '/eps/v1/reservations') {
    const since = u.searchParams.get('since');
    // Serve queued items (pull semantics: each message is delivered until pulled).
    const batch = state.queue.splice(0, state.queue.length);
    state.served.push(...batch);
    return json(res, 200, { reservations: batch, since: since ?? null, remaining: state.queue.length });
  }

  const cancel = p.match(/^\/eps\/v1\/reservations\/([^/]+)\/cancel$/);
  if (req.method === 'POST' && cancel) {
    const id = decodeURIComponent(cancel[1]);
    state.cancelled.push(id);
    return json(res, 200, { reservation_id: id, status: 'cancelled' });
  }

  if (req.method === 'POST' && p === '/__queue') {
    let raw = '';
    req.on('data', c => raw += c);
    req.on('end', () => {
      try { const r = JSON.parse(raw); state.queue.push(r); json(res, 200, { queued: r.reservation_id }); }
      catch { json(res, 400, { error: 'invalid JSON' }); }
    });
    return;
  }

  return json(res, 404, { error: 'not found' });
}).listen(PORT, '0.0.0.0', () => console.log(`[booking-eps-sim] Booking.com EPS simulator on http://0.0.0.0:${PORT}`));
