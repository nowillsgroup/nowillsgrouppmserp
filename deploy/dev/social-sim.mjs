/**
 * Social connectors – local simulator (Facebook Graph + WhatsApp Cloud + review feeds).
 *
 * Mirrors the endpoints SocialPublishService uses:
 *   GET  /v20.0/me                          token check (Graph "who am I")
 *   POST /v20.0/{page_id}/feed              Facebook page post → {id:"fb_post_…"}
 *   POST /v20.0/{phone_id}/messages         WhatsApp Cloud send → {messages:[{id:"wamid.…"}]}
 *   GET  /reviews/{platform}                review feed (booking|google|tripadvisor|…)
 *   POST /__reviews                         queue more reviews (testing)
 *   GET  /                                  status page
 *
 * Auth: Bearer <fb-token> (any non-empty if --fb-token omitted); WhatsApp uses the same.
 * Usage: node deploy/dev/social-sim.mjs --port=8095 [--fb-token=demo] [--wa-token=demo]
 */
import http from 'node:http';

const args = Object.fromEntries(process.argv.slice(2).map(a => {
  const m = a.match(/^--([^=]+)=?(.*)$/); return m ? [m[1], m[2]] : [a, ''];
}));
const PORT = Number(args.port ?? 8095);
const FB_TOKEN = String(args.fbToken ?? args['fb-token'] ?? '');
const WA_TOKEN = String(args.waToken ?? args['wa-token'] ?? '');

const state = { posts: [], messages: [], reviews: { booking: [], google: [], tripadvisor: [] }, served: 0 };

// Seed one fresh review per platform
const day = n => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);
state.reviews.booking = [{ external_id: 'BDC-R-991001', reviewer_name: 'Hannah Schmidt', reviewer_location: 'Berlin', review_date: day(2), rating: 8.6, rating_scale: 10, title: 'Great stay', body: 'Friendly front desk and a lovely pool. Breakfast was excellent.', url: 'https://booking.com/reviews/991001', trip_type: 'business' }];
state.reviews.google = [{ external_id: 'GOOG-R-5521', reviewer_name: 'Kwesi Appiah', reviewer_location: 'Accra', review_date: day(1), rating: 5, rating_scale: 5, title: 'Perfect weekend', body: 'Rooms were spotless, housekeeping excellent. Will come back.', url: 'https://g.page/reviews/5521' }];
state.reviews.tripadvisor = [{ external_id: 'TA-R-7781', reviewer_name: 'Priya Nair', reviewer_location: 'Mumbai', review_date: day(3), rating: 4, rating_scale: 5, title: 'Very good', body: 'Great location and staff. The gym could open earlier.', url: 'https://tripadvisor.com/7781', trip_type: 'family' }];

const json = (res, code, obj) => {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
};

http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  const p = u.pathname;

  if (req.method === 'GET' && (p === '/' || p === '/status')) {
    return json(res, 200, { service: 'social-sim', posts: state.posts.length, messages: state.messages.length, reviews: Object.fromEntries(Object.entries(state.reviews).map(([k, v]) => [k, v.length])) });
  }

  // ── review feeds (no auth — feed URLs may carry their own keys) ──
  const feed = p.match(/^\/reviews\/([^/]+)$/);
  if (req.method === 'GET' && feed) {
    const platform = feed[1];
    const rows = state.reviews[platform] ?? [];
    state.reviews[platform] = [];          // pull semantics: serve once
    state.served += rows.length;
    return json(res, 200, { reviews: rows, platform });
  }
  if (req.method === 'POST' && p === '/__reviews') {
    let raw = '';
    req.on('data', c => raw += c);
    req.on('end', () => {
      try { const r = JSON.parse(raw); (state.reviews[r.platform ?? 'other'] ??= []).push(r); json(res, 200, { queued: r.external_id }); }
      catch { json(res, 400, { error: 'invalid JSON' }); }
    });
    return;
  }

  // ── Graph / Cloud (Bearer auth) ──
  const auth = String(req.headers.authorization ?? '').replace(/^Bearer\s+/i, '');
  const tokenOk = t => !t || (auth !== '' && auth === t);

  if (req.method === 'GET' && /^\/v20\.0\/me$/.test(p)) {
    if (!tokenOk(FB_TOKEN)) return json(res, 401, { error: { message: 'Invalid OAuth access token.', type: 'OAuthException', code: 190 } });
    return json(res, 200, { id: '1234567890', name: 'Simulated Hotel Page' });
  }

  const feedPost = p.match(/^\/v20\.0\/([^/]+)\/feed$/);
  if (req.method === 'POST' && feedPost) {
    let raw = '';
    req.on('data', c => raw += c);
    req.on('end', () => {
      const body = new URLSearchParams(raw);
      // Graph accepts the token as a body parameter or a Bearer header
      const bodyToken = body.get('access_token') ?? '';
      const okToken = !FB_TOKEN || auth === FB_TOKEN || bodyToken === FB_TOKEN;
      if (!okToken) return json(res, 401, { error: { message: 'Invalid OAuth access token.', type: 'OAuthException', code: 190 } });
      const id = 'fb_post_' + (state.posts.length + 1);
      state.posts.push({ at: new Date().toISOString(), page: feedPost[1], message: body.get('message')?.slice(0, 80), link: body.get('link') });
      return json(res, 200, { id });
    });
    return;
  }

  const wa = p.match(/^\/v20\.0\/([^/]+)\/messages$/);
  if (req.method === 'POST' && wa) {
    if (!tokenOk(WA_TOKEN)) return json(res, 401, { error: { message: 'Invalid OAuth access token.', type: 'OAuthException', code: 190 } });
    let raw = '';
    req.on('data', c => raw += c);
    req.on('end', () => {
      try {
        const b = JSON.parse(raw || '{}');
        const id = 'wamid.' + Buffer.from((b.to ?? '') + Date.now()).toString('base64url').slice(0, 24);
        state.messages.push({ at: new Date().toISOString(), phone_id: wa[1], to: b.to, body: (b.text?.body ?? '').slice(0, 80) });
        return json(res, 200, { messaging_product: 'whatsapp', messages: [{ id }] });
      } catch { return json(res, 400, { error: { message: 'invalid JSON' } }); }
    });
    return;
  }

  return json(res, 404, { error: { message: 'unknown endpoint ' + p } });
}).listen(PORT, '0.0.0.0', () => console.log(`[social-sim] Facebook/WhatsApp/review simulator on http://0.0.0.0:${PORT}`));
