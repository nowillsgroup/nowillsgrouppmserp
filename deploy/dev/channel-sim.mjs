#!/usr/bin/env node
// channel-sim — local OTA-side simulator speaking the Nowills channel hub
// protocol (docs/CHANNEL-ADAPTER.md). For sandbox end-to-end tests of the
// channel drivers: run this, point a hub/mirror connection at it, then use
// Test connection / Push changes / Full sync on the connection page.
//
//   bun deploy/dev/channel-sim.mjs [--port=8091]
//
// Endpoints:  POST /ari                      → accept an ARI push (logs it)
//             GET  /bookings?since=…         → {"bookings":[…]} demo fixture
//             POST /bookings/{ref}/cancel    → {"ok":true}
import http from 'node:http';

const port = Number((process.argv.find((a) => a.startsWith('--port=')) ?? '--port=8091').split('=')[1]);
let pushes = 0;

const server = http.createServer((req, res) => {
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    const url = new URL(req.url, `http://127.0.0.1:${port}`);
    const send = (code, obj) => {
      res.writeHead(code, { 'content-type': 'application/json' });
      res.end(JSON.stringify(obj));
    };
    const sig = req.headers['x-nowills-signature'] ?? '(none)';
    if (req.method === 'GET' && url.pathname === '/') {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
      return res.end(`<!doctype html><meta charset="utf-8"><title>Nowills channel simulator</title>
<body style="font:14px/1.6 system-ui;background:#0b1220;color:#e6edf7;padding:28px">
<h1 style="margin:0 0 6px">OTA channel simulator</h1>
<p style="color:#8db9ff">Speaking the Nowills channel hub protocol — ${pushes} ARI push(es) received so far.</p>
<ul><li>POST <code>/ari</code> — accept ARI pushes (signed JSON)</li>
<li>GET <code>/ari/bookings</code> — booking pull (demo fixture)</li>
<li>POST <code>/ari/bookings/{ref}/cancel</code> — cancel</li></ul>
<p style="color:#5b6b85">Point a hub/mirror connection endpoint at <code>http://127.0.0.1:${port}/ari</code>, then use Test connection / Push changes on the connection page.</p></body>`);
    }
    if (req.method === 'POST' && url.pathname === '/ari') {
      let kind = '?';
      let rooms = 0;
      try {
        const p = JSON.parse(body);
        kind = p.kind ?? '?';
        rooms = JSON.stringify(p.ari ?? p.data ?? {}).length;
      } catch {}
      pushes++;
      console.log(`[channel-sim] ARI push #${pushes} kind=${kind} bytes=${body.length} sig=${String(sig).slice(0, 22)}…`);
      return send(200, { ok: true, accepted: true, kind });
    }
    if (req.method === 'GET' && url.pathname === '/ari/bookings') {
      console.log(`[channel-sim] booking pull since=${url.searchParams.get('since') ?? '-'} sig=${String(sig).slice(0, 22)}…`);
      return send(200, {
        bookings: [
          { ref: 'SIM-2001', guest: 'Kojo Mensah', room_type: 'STD', arrival: '+5 days', departure: '+7 days', nights: 2, rate: 980 },
          { ref: 'SIM-2002', guest: 'Efua Asante', room_type: 'EXE', arrival: '+12 days', departure: '+13 days', nights: 1, rate: 2450 },
        ],
      });
    }
    const m = /^\/ari\/bookings\/([^/]+)\/cancel$/.exec(url.pathname);
    if (req.method === 'POST' && m) {
      console.log(`[channel-sim] cancel ${decodeURIComponent(m[1])}`);
      return send(200, { ok: true });
    }
    return send(404, { error: 'not found' });
  });
});

server.listen(port, '0.0.0.0', () => {
  console.log(`[channel-sim] OTA-side channel simulator on http://0.0.0.0:${port}`);
  console.log(`[channel-sim] point a hub/mirror connection endpoint at http://127.0.0.1:${port}/ari`);
});
