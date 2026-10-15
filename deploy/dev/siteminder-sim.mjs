/**
 * SiteMinder — local simulator (OTA XML: AvailRateNotify push, HotelResModifyNotif pull, CancelRQ).
 *
 * THIS SIM CHECKS THE BEARER TOKEN and answers a malformed document with an OTA <Errors> block, so a passing e2e
 * proves the driver speaks the dialect and authenticates the way SiteMinderChannelDriver claims.
 *
 * Endpoints (mirrors SiteMinderChannelDriver):
 *   GET  /status                                             simulator state (JSON, unauthenticated)
 *   GET  /hotels/{code}                                      property content (JSON) — the driver's test() probe
 *   POST /hotels/{code}/ari                                  OTA_AvailRateNotifyRQ  → OTA_AvailRateNotifyRS
 *   GET  /hotels/{code}/reservations?modified_since=ISO      OTA_HotelResModifyNotifRS (queue drains on read)
 *   POST /hotels/{code}/reservations/{ref}/cancel            OTA_CancelRQ → OTA_CancelRS (idempotent)
 *   POST /__bookings                                         queue one more reservation for the next pull (JSON)
 *   POST /__fail?n=2                                         fail the next n ARI pushes (HTTP 503 + OTA Errors)
 *
 * Usage: node deploy/dev/siteminder-sim.mjs --port=8096 --token=smtoken --hotel=NWG01
 */
import http from 'node:http';

const args = Object.fromEntries(process.argv.slice(2).map(a => {
  const m = a.match(/^--([^=]+)=?(.*)$/); return m ? [m[1], m[2]] : [a, ''];
}));
const PORT = Number(args.port ?? 8096);
const TOKEN = String(args.token ?? 'smtoken');
const HOTEL = String(args.hotel ?? 'NWG01');

const day = n => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
const state = {
  ari: [], cancelled: [], served: 0, badAuth: 0, badXml: 0, failNext: 0, failures: 0,
  queue: [
    {
      ref: 'SM-77120001', modify: 'New', arrival: day(3), departure: day(6),
      first: 'Lena', last: 'Hoffmann', email: 'lena@example.de', phone: '+49 30 1234567', country: 'DE',
      room_code: 'SM-STD', rate_code: 'SM-BAR', adults: 2, children: 0, amount: '1450.00', currency: 'USD',
      comments: ['High floor if possible'],
    },
    {
      ref: 'SM-77120002', modify: 'Modify', arrival: day(5), departure: day(7),
      first: 'Carlos', last: 'Mendez', email: 'carlos@example.mx', phone: '+52 55 1234 5678', country: 'MX',
      room_code: 'SM-TWN', rate_code: 'SM-NR', adults: 1, children: 1, amount: '980.50', currency: 'USD',
      comments: [],
    },
    {
      ref: 'SM-77120003', modify: 'Cancel', arrival: day(2), departure: day(4),
      first: 'Priya', last: 'Rao', email: 'priya@example.in', phone: null, country: 'IN',
      room_code: 'SM-STD', rate_code: 'SM-BAR', adults: 2, children: 0, amount: '640.00', currency: 'USD',
      comments: ['Guest cancelled at the channel'],
    },
  ],
};

const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const send = (res, code, body, type = 'application/xml') => {
  res.writeHead(code, { 'Content-Type': type + '; charset=utf-8', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
};
const json = (res, code, obj) => send(res, code, JSON.stringify(obj), 'application/json');
const ok = (res, root) => send(res, 200, `<?xml version="1.0" encoding="UTF-8"?><${root} Version="1.0"><Success/></${root}>`);
const fault = (res, code, errCode, text, root = 'OTA_AvailRateNotifyRS') => send(res, code,
  `<?xml version="1.0" encoding="UTF-8"?><${root} Version="1.0"><Errors><Error Code="${errCode}" ShortText="${esc(text)}"/></Errors></${root}>`);

const authorised = req => {
  const auth = String(req.headers.authorization ?? '');
  return auth === `Bearer ${TOKEN}`;
};

/** Reservation row → the OTA_HotelResModifyNotifRS document a channel manager serves. */
const reservationXml = rows => {
  const body = rows.map(r => `
    <HotelReservation ResModifyType="${esc(r.modify)}" CreateDateTime="${new Date().toISOString()}">
      <UniqueID Type="14" ID="${esc(r.ref)}"/>
      <RoomStays><RoomStay>
        <RoomTypes><RoomType RoomTypeCode="${esc(r.room_code)}"/></RoomTypes>
        <RatePlans><RatePlan RatePlanCode="${esc(r.rate_code)}"/></RatePlans>
        <GuestCounts>
          <GuestCount AgeQualifyingCode="10" Count="${Number(r.adults ?? 2)}"/>
          <GuestCount AgeQualifyingCode="8" Count="${Number(r.children ?? 0)}"/>
        </GuestCounts>
        <TimeSpan Start="${esc(r.arrival)}" End="${esc(r.departure)}"/>
        <Total AmountAfterTax="${esc(r.amount)}" CurrencyCode="${esc(r.currency ?? 'USD')}"/>
      </RoomStay></RoomStays>
      <ResGlobalInfo>
        <TimeSpan Start="${esc(r.arrival)}" End="${esc(r.departure)}"/>
        <Total AmountAfterTax="${esc(r.amount)}" CurrencyCode="${esc(r.currency ?? 'USD')}"/>
        <Profiles><ProfileInfo><Profile><Customer>
          <PersonName><GivenName>${esc(r.first)}</GivenName><Surname>${esc(r.last)}</Surname></PersonName>
          ${r.email ? `<Email>${esc(r.email)}</Email>` : ''}
          ${r.phone ? `<Telephone PhoneNumber="${esc(r.phone)}"/>` : ''}
          ${r.country ? `<Address><CountryName Code="${esc(r.country)}"/></Address>` : ''}
        </Customer></Profile></ProfileInfo></Profiles>
        ${(r.comments ?? []).length ? `<Comments>${r.comments.map(c => `<Comment><Text>${esc(c)}</Text></Comment>`).join('')}</Comments>` : ''}
      </ResGlobalInfo>
    </HotelReservation>`).join('');
  return `<?xml version="1.0" encoding="UTF-8"?>
<OTA_HotelResModifyNotifRS Version="1.0" TimeStamp="${new Date().toISOString()}" HotelCode="${esc(HOTEL)}">
  <HotelReservations>${body}</HotelReservations>
</OTA_HotelResModifyNotifRS>`;
};

http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  const p = u.pathname;

  if (req.method === 'GET' && (p === '/' || p === '/status')) {
    return json(res, 200, {
      service: 'siteminder-sim', hotel: HOTEL, ari: state.ari.length, cancelled: state.cancelled,
      served: state.served, bad_auth: state.badAuth, bad_xml: state.badXml, queued: state.queue.length,
      fail_next: state.failNext, failures: state.failures,
    });
  }

  let raw = '';
  req.on('data', c => raw += c);
  req.on('end', () => {
    // testing hooks are unauthenticated on purpose (they never touch PMS state)
    if (req.method === 'POST' && p === '/__bookings') {
      try { const r = JSON.parse(raw); state.queue.push(r); return json(res, 200, { queued: r.ref }); }
      catch { return json(res, 400, { error: 'invalid JSON' }); }
    }
    if (req.method === 'POST' && p === '/__fail') {
      state.failNext = Number(u.searchParams.get('n') ?? (JSON.parse(raw || '{}').n ?? 1));
      return json(res, 200, { fail_next: state.failNext });
    }

    if (! authorised(req)) {
      state.badAuth++;
      return json(res, 401, { error: 'invalid_token', error_description: 'Bearer token missing or wrong.' });
    }

    const hotel = p.match(/^\/hotels\/([^/]+)(?:\/(ari|reservations)(?:\/([^/]+)\/cancel)?)?$/);
    if (!hotel) return fault(res, 404, '390', `unknown endpoint ${p}`, 'OTA_ErrorRS');
    if (hotel[1] !== HOTEL) return fault(res, 404, '391', `Unknown hotel code ${hotel[1]}`, 'OTA_ErrorRS');

    // GET /hotels/{code} — property content, the driver's test() probe
    if (req.method === 'GET' && !hotel[2]) {
      return json(res, 200, {
        hotel_code: HOTEL, name: 'Nowills Group Resort (SiteMinder sandbox)', currency: 'USD',
        room_types: [{ code: 'SM-STD', name: 'Standard King' }, { code: 'SM-TWN', name: 'Twin Room' }],
      });
    }

    // POST /hotels/{code}/ari — ARI push
    if (req.method === 'POST' && hotel[2] === 'ari') {
      if (state.failNext > 0) {
        state.failNext--; state.failures++;
        return fault(res, 503, '421', 'Channel is temporarily unavailable – retry later.');
      }
      const root = raw.match(/^\s*(?:<\?xml[^>]*\?>\s*)?<([A-Za-z_][\w.\-]*)/);
      if (!root || root[1] !== 'OTA_AvailRateNotifyRQ') {
        state.badXml++;
        return fault(res, 400, '32', `Expected an OTA_AvailRateNotifyRQ document, got ${root ? root[1] : 'non-XML'}.`);
      }
      const hotelCode = raw.match(/HotelCode="([^"]*)"/);
      if (!hotelCode || hotelCode[1] !== HOTEL) {
        state.badXml++;
        return fault(res, 400, '391', `HotelCode ${hotelCode ? hotelCode[1] : 'missing'} does not match ${HOTEL}.`);
      }
      state.ari.push({
        at: new Date().toISOString(),
        avail: (raw.match(/<AvailStatusMessage /g) ?? []).length,
        rates: (raw.match(/<Rate /g) ?? []).length,
        closed: (raw.match(/Status="Close"/g) ?? []).length,
        bytes: raw.length,
      });
      return ok(res, 'OTA_AvailRateNotifyRS');
    }

    // GET /hotels/{code}/reservations — reservation feed (drains the queue, like a real channel manager)
    if (req.method === 'GET' && hotel[2] === 'reservations') {
      const rows = state.queue; state.queue = [];
      state.served += rows.length;
      return send(res, 200, reservationXml(rows));
    }

    // POST /hotels/{code}/reservations/{ref}/cancel
    if (req.method === 'POST' && hotel[2] === 'reservations' && hotel[3]) {
      if (!raw.includes('<OTA_CancelRQ')) {
        state.badXml++;
        return fault(res, 400, '32', 'Expected an OTA_CancelRQ document.', 'OTA_CancelRS');
      }
      if (!state.cancelled.includes(hotel[3])) state.cancelled.push(hotel[3]);
      return ok(res, 'OTA_CancelRS'); // idempotent: cancelling twice still answers Success
    }

    return fault(res, 405, '392', `${req.method} not allowed on ${p}`, 'OTA_ErrorRS');
  });
}).listen(PORT, '0.0.0.0', () => console.log(`[siteminder-sim] OTA XML simulator on http://0.0.0.0:${PORT} hotel=${HOTEL}`));
