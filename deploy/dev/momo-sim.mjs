#!/usr/bin/env node
// momo-sim — local Mobile Money provider simulator for the MoMo collections
// gateway (docs/MOMO-GATEWAY.md). Accepts collection requests over the signed
// JSON protocol, then confirms them by webhook back to the PMS (or via GET
// status polling) — exactly like MTN MoMo / Telecel / AirtelTigo do.
//
//   bun deploy/dev/momo-sim.mjs --port=8092 --secret=devsecret \
//        [--callback-base=http://127.0.0.1:8000] [--auto=3]   # auto-confirm after 3s
//
// PMS settings: MessageService secret "momo" = --secret, setting momo_base_url
// = http://127.0.0.1:8092.
import http from 'node:http';
import crypto from 'node:crypto';

const arg = (name, dflt) => {
  const a = process.argv.find((x) => x.startsWith(`--${name}=`));
  return a ? a.split('=').slice(1).join('=') : dflt;
};
const port = Number(arg('port', '8092'));
const secret = arg('secret', 'devsecret');
const callbackBase = arg('callback-base', 'http://127.0.0.1:8000');
const autoSeconds = Number(arg('auto', '3'));
const collections = new Map();
let seq = 0;

function fireWebhook(col) {
  const body = JSON.stringify({
    reference: col.reference, status: 'successful', transaction_id: col.transaction_id,
    amount: col.amount, currency: col.currency, fee: 0, message: 'Approved on the phone (simulator)',
    event_id: `MOMOSIM-${++seq}`,
  });
  const target = new URL(col.callbackPath, callbackBase);
  const req = http.request(target, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-provider-signature': 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex'),
      'content-length': Buffer.byteLength(body),
    },
  }, (res) => {
    console.log(`[momo-sim] webhook → ${target.pathname} HTTP ${res.statusCode}`);
    res.resume();
  });
  req.on('error', (e) => console.log(`[momo-sim] webhook error: ${e.message}`));
  req.end(body);
}

const server = http.createServer((req, res) => {
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    const url = new URL(req.url, `http://127.0.0.1:${port}`);
    const send = (code, obj) => {
      res.writeHead(code, { 'content-type': 'application/json' });
      res.end(JSON.stringify(obj));
    };
    if (req.method === 'GET' && url.pathname === '/') {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
      return res.end(`<!doctype html><meta charset="utf-8"><title>MoMo provider simulator</title>
<body style="font:14px/1.6 system-ui;background:#0b1220;color:#e6edf7;padding:28px">
<h1 style="margin:0 0 6px">Mobile Money provider simulator</h1>
<p style="color:#8db9ff">${collections.size} collection request(s) so far — auto-confirm after ${autoSeconds}s via webhook.</p>
<ul><li>POST <code>/v1/collections</code> — request-to-pay</li>
<li>GET <code>/v1/collections/{ref}</code> — status</li>
<li>POST <code>/v1/collections/{ref}/refund</code> — refund</li></ul>
<p style="color:#5b6b85">PMS settings: secret <code>momo</code> = <code>${secret}</code> · momo_base_url = <code>http://127.0.0.1:${port}</code></p></body>`);
    }
    if (req.method === 'POST' && url.pathname === '/v1/collections') {
      let j = {};
      try { j = JSON.parse(body); } catch {}
      const col = {
        reference: j.reference ?? `MOMOSIM-${Date.now()}`,
        transaction_id: 'MOMOSIM-' + String(++seq).padStart(6, '0'),
        amount: j.amount, currency: j.currency ?? 'GHS', phone: j.phone, network: j.network,
        callbackPath: new URL(j.callback_url ?? '/webhooks/pay/momo/NOWILLS', callbackBase).pathname,
        status: 'pending', created: Date.now(),
      };
      collections.set(col.reference, col);
      console.log(`[momo-sim] collect ${col.reference} ${col.currency} ${col.amount} ${col.network} ${col.phone} → confirming in ${autoSeconds}s`);
      if (autoSeconds >= 0) setTimeout(() => {
        if (col.status === 'pending') {
          col.status = 'successful';
          fireWebhook(col);
        }
      }, autoSeconds * 1000);
      return send(200, { status: 'pending', transaction_id: col.transaction_id, message: 'Prompt sent to ' + col.phone });
    }
    const m = /^\/v1\/collections\/([^/]+)$/.exec(url.pathname);
    if (req.method === 'GET' && m) {
      const col = collections.get(decodeURIComponent(m[1]));
      return col
        ? send(200, { status: col.status, transaction_id: col.transaction_id, amount: col.amount, currency: col.currency, message: col.status === 'pending' ? 'Awaiting customer approval' : 'Approved on the phone (simulator)' })
        : send(404, { error: 'unknown reference' });
    }
    const r = /^\/v1\/collections\/([^/]+)\/refund$/.exec(url.pathname);
    if (req.method === 'POST' && r) {
      console.log(`[momo-sim] refund ${decodeURIComponent(r[1])}`);
      return send(200, { transaction_id: 'MOMOSIM-RF-' + String(++seq).padStart(4, '0'), status: 'processed' });
    }
    return send(404, { error: 'not found' });
  });
});

server.listen(port, '0.0.0.0', () => {
  console.log(`[momo-sim] MoMo provider simulator on http://0.0.0.0:${port} (secret=${secret}, callback via ${callbackBase})`);
});
