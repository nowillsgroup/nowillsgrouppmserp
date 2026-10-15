/**
 * Expedia Rapid — local simulator (v3-style JSON with HMAC-SHA512 request signing).
 *
 * THIS SIM REJECTS BAD SIGNATURES — a passing e2e proves the driver signs
 * exactly like Rapid expects:
 *   signature = base64(HMAC-SHA512(api_secret, request_path_with_query + json_body))
 *   Authorization: {api_key}:{signature}
 *
 * Endpoints (mirrors ExpediaRapidChannelDriver):
 *   GET  /v3/rooms?property_id=…                        property content
 *   GET  /v3/booking?property_id=…&records.updated_from=…  booking retrieval
 *   PUT  /v3/availability                               ARI push (signed body)
 *   POST /v3/booking/{id}/supplier-cancel               supplier cancel
 *   POST /__bookings                                    queue more itineraries (testing)
 *   GET  /                                              status
 *
 * Usage: node deploy/dev/expedia-sim.mjs --port=8095 --key=expkey --secret=expsecret
 */
import http from 'node:http';
import crypto from 'node:crypto';

const args = Object.fromEntries(process.argv.slice(2).map(a => {
  const m = a.match(/^--([^=]+)=?(.*)$/); return m ? [m[1], m[2]] : [a, ''];
}));
const PORT = Number(args.port ?? 8095);
const API_KEY = String(args.key ?? 'expkey');
const API_SECRET = String(args.secret ?? 'expsecret');

const state = { ari: [], cancelled: [], served: 0, badSig: 0 };

const day = n => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
state.queue = [
  {
    itinerary_id: 'EXP-88421001', property_id: '300452', checkin: day(3), checkout: day(6), modified: false, cancel: false,
    guest: { first_name: 'Lena', last_name: 'Hoffmann', email: 'lena@example.de', phone: '+49 30 1234567', country: 'DE' },
    room_id: '100021', rate_plan_id: '200033', number_of_adults: 2, number_of_children: 0,
    total: { amount: '1450.00', currency: 'USD' }, special_request: 'High floor if possible',
  },
  {
    itinerary_id: 'EXP-88421002', property_id: '300452', checkin: day(5), checkout: day(7), modified: true, cancel: false,
    guest: { first_name: 'Carlos', last_name: 'Mendez', email: 'carlos@example.mx', phone: '+52 55 1234 5678', country: 'MX' },
    room_id: '100022', rate_plan_id: '200034', number_of_adults: 1, number_of_children: 1,
    total: { amount: '980.50', currency: 'USD' }, special_request: null,
  },
  {
    itinerary_id: 'EXP-88421003', property_id: '300452', checkin: day(2), checkout: day(4), modified: false, cancel: true,
    guest: { first_name: 'Priya', last_name: 'Rao', email: 'priya@example.in', phone: null, country: 'IN' },
    room_id: '100021', rate_plan_id: '200033', number_of_adults: 2, number_of_children: 0,
    total: { amount: '640.00', currency: 'USD' }, special_request: null,
  },
];

const json = (res, code, obj) => {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
};

const verify = (req, path, body) => {
  const auth = String(req.headers.authorization ?? '');
  const sep = auth.indexOf(':');
  if (sep < 0) return false;
  const key = auth.slice(0, sep), sig = auth.slice(sep + 1);
  if (key !== API_KEY) return false;
  const expect = crypto.createHmac('sha512', API_SECRET).update(path + body).digest('base64');
  return crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expect));
};

http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  const p = u.pathname;
  const signedPath = u.pathname + (u.search ?? '');

  if (req.method === 'GET' && (p === '/' || p === '/status')) {
    return json(res, 200, { service: 'expedia-sim', ari: state.ari.length, cancelled: state.cancelled, served: state.served, bad_sig: state.badSig, queued: state.queue.length });
  }
  if (req.method === 'POST' && p === '/__bookings') {
    let raw = '';
    req.on('data', c => raw += c);
    req.on('end', () => {
      try { const r = JSON.parse(raw); state.queue.push(r); json(res, 200, { queued: r.itinerary_id }); }
      catch { json(res, 400, { error: 'invalid JSON' }); }
    });
    return;
  }

  let raw = '';
  req.on('data', c => raw += c);
  req.on('end', () => {
    if (! verify(req, signedPath, raw)) {
      state.badSig++;
      return json(res, 401, { errors: [{ message: 'Request signature verification failed.', code: 'SIGNATURE_INVALID' }] });
    }

    if (req.method === 'GET' && p === '/v3/rooms') {
      return json(res, 200, [{ id: '100021', name: 'Standard King' }, { id: '100022', name: 'Twin Room' }]);
    }
    if (req.method === 'GET' && p === '/v3/booking') {
      const rows = state.queue; state.queue = [];
      state.served += rows.length;
      return json(res, 200, { itineraries: rows });
    }
    if (req.method === 'PUT' && p === '/v3/availability') {
      try { const b = JSON.parse(raw || '{}'); state.ari.push({ at: new Date().toISOString(), kind: b.kind, n: (b.values ?? []).length }); return json(res, 200, { ok: true }); }
      catch { return json(res, 400, { errors: [{ message: 'invalid JSON' }] }); }
    }
    const cancel = p.match(/^\/v3\/booking\/([^/]+)\/supplier-cancel$/);
    if (req.method === 'POST' && cancel) {
      state.cancelled.push(cancel[1]);
      return json(res, 200, { itinerary_id: cancel[1], status: 'cancelled' });
    }

    return json(res, 404, { errors: [{ message: 'unknown endpoint ' + p }] });
  });
}).listen(PORT, '0.0.0.0', () => console.log(`[expedia-sim] Rapid simulator (signature-checking) on http://0.0.0.0:${PORT} key=${API_KEY}`));
