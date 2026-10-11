#!/usr/bin/env node
// serve — PMS development server (Bun HTTP → WASM PHP), bound on 0.0.0.0.
//
// Static files under public/ are served straight from disk; everything else
// is dispatched to public/index.php like the production .htaccess does.
import { createPhp, makeMutex, REPO_ROOT, APP_MOUNT } from './lib.mjs';
import { existsSync, statSync } from 'node:fs';
import { join, normalize, extname } from 'node:path';

const PORT = Number(process.env.PORT ?? 8000);
const HOST = process.env.HOST ?? '0.0.0.0';
const PUBLIC_DIR = join(REPO_ROOT, 'public');

// Boot PHP in the background so the HTTP port binds instantly — the preview
// proxy needs to observe port 8000 within the process startup window, and
// dynamic requests simply wait for the runtime below.
let php = null;
const phpReady = createPhp().then((p) => {
  php = p;
  process.stderr.write('[pms-serve] PHP runtime ready\n');
  return p;
});
const run = makeMutex();

// ── dev preview auto-login ────────────────────────────────────────────────
// The live preview should land inside the app, not on the marketing login
// page. Requests without a session cookie are signed in behind the scenes as
// the demo admin; `/` then redirects to the preview home (reservation desk).
// Disable with PMS_DEV_AUTOLOGIN=0; tune with PMS_DEV_USER / PMS_DEV_PASS /
// PMS_DEV_HOTEL / PMS_DEV_HOME.
const DEV_LOGIN = (process.env.PMS_DEV_AUTOLOGIN ?? '1') !== '0';
const DEV_USER = process.env.PMS_DEV_USER ?? 'admin';
const DEV_PASS = process.env.PMS_DEV_PASS ?? 'Admin@123';
const DEV_HOTEL = process.env.PMS_DEV_HOTEL ?? 'NOWILLS';
const DEV_HOME = process.env.PMS_DEV_HOME ?? '/reservations';
let devCookies = null;

// ── /__dev — development changes index ────────────────────────────────────
// Standing workflow: EVERY development change is recorded here with deep
// links to the screens where it can be seen in the live preview. Newest
// first. A small "dev changes" chip is injected into previewed pages so
// this index is always one click away.
const DEV_CHANGES = [
  {
    title: 'v7.37 — Mobile Money collections (request-to-pay)',
    detail: 'Split settlements just got automated MoMo: send a payment prompt to the guest phone (MTN/Telecel/AirtelTigo), the payment posts to the folio on approval via webhook or Check status. Webhook loop proven against the sandbox provider simulator (auto-posted folio transaction).',
    screens: [
      ['Folio — Request MoMo button + prompt modal', '/folios/159'],
      ['Payments — collection monitor (pending → paid)', '/payments'],
      ['MoMo provider simulator', '/'],
    ],
  },
  {
    title: 'v7.37 — OTA/channel driver layer (in progress)',
    detail: 'ChannelDriver contract + registry + Hub/Simulator/Mirror drivers. Test connection button on every connection; signed-JSON hub protocol proven end-to-end against the sandbox simulator (240 room-nights pushed over HTTP 200, booking pull works). Real OTA adapters plug in next.',
    screens: [
      ['Channel manager', '/channel-manager'],
      ['Connection: Sandbox Hub — Test / Push / Full sync', '/channel-manager/connections/6'],
      ['Connection: Sandbox Simulator', '/channel-manager/connections/5'],
    ],
  },
  {
    title: 'Preview delivery system',
    detail: 'Live preview auto-signs in and lands on the app; pages ship styled (inline CSS); iframe-embeddable; this changes index.',
    screens: [
      ['Development changes index', '/__dev'],
      ['Reservation desk (preview home)', '/reservations'],
    ],
  },
  {
    title: 'v7.36 — Live PABX capture',
    detail: 'pms:pbx:listen streams SMDR call records from the PABX (connect + listen modes) via a TCP driver, with command-link templates and a link test.',
    screens: [
      ['Interfaces › PABX settings', '/interfaces'],
      ['Call accounting records', '/calls'],
    ],
  },
  {
    title: 'v7.35 — Data migration & cutover toolkit',
    detail: 'Upload → validate → apply legacy data (companies, guests, employees, rooms, reservations) with dry-run, per-row errors, and CSV templates.',
    screens: [['Settings › Data migration', '/settings/migration']],
  },
];

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function devChangesPage() {
  let commits = '';
  try {
    const g = Bun.spawnSync(['git', '-C', REPO_ROOT, 'log', '--oneline', '-25'], { stdout: 'pipe' });
    commits = new TextDecoder().decode(g.stdout ?? new Uint8Array())
      .trim().split('\n').filter(Boolean)
      .map((l) => {
        const m = /^([0-9a-f]{7,40})\s+(.*)$/.exec(l);
        return m ? `<li><code>${m[1]}</code> ${esc(m[2])}</li>` : `<li>${esc(l)}</li>`;
      }).join('\n');
  } catch {
    commits = '<li>git log unavailable</li>';
  }
  const cards = DEV_CHANGES.map(
    (c) => `<article class="card"><h2>${esc(c.title)}</h2><p>${esc(c.detail)}</p>
      <p class="links">${c.screens.map(([label, path]) => `<a class="btn" href="${esc(path)}">${esc(label)}</a>`).join('')}</p></article>`
  ).join('\n');
  return new Response(
    `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nowills PMS — development changes</title><style>
  :root { color-scheme: dark; }
  body { margin: 0; font: 14px/1.55 system-ui, sans-serif; background: #0b1220; color: #e6edf7; }
  header { padding: 22px 26px 10px; border-bottom: 1px solid #1d2a44; }
  h1 { margin: 0 0 4px; font-size: 19px; }
  .sub { color: #8db9ff; margin: 0; }
  main { padding: 18px 26px 40px; max-width: 880px; }
  .card { background: #111b2e; border: 1px solid #1d2a44; border-radius: 10px; padding: 14px 16px; margin: 0 0 14px; }
  .card h2 { margin: 0 0 6px; font-size: 15px; color: #bcd6ff; }
  .card p { margin: 0 0 10px; color: #aebdd4; }
  .links a.btn { display: inline-block; margin: 0 8px 8px 0; padding: 7px 12px; background: #1b64f2; color: #fff;
    border-radius: 7px; text-decoration: none; font-weight: 600; font-size: 13px; }
  .links a.btn:hover { background: #2c74ff; }
  h3 { margin: 22px 0 8px; font-size: 14px; color: #8db9ff; }
  ul { margin: 0; padding-left: 20px; color: #9fb0c8; }
  li code { color: #7dd3fc; }
  footer { padding: 0 26px 30px; color: #5b6b85; font-size: 12px; }
</style></head><body>
<header><h1>Development changes</h1>
  <p class="sub">Every development change, with deep links to see it in the live app.</p></header>
<main>
${cards}
  <h3>Recent commits</h3>
  <ul>
${commits}
  </ul>
</main>
<footer>Preview server · <a href="${DEV_HOME}" style="color:#8db9ff">open the app</a> · disable auto-login with PMS_DEV_AUTOLOGIN=0</footer>
</body></html>`,
    { headers: { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-cache' } }
  );
}

// Persistent corner chip on previewed app pages → the changes index above.
const DEV_CHIP = `<a href="/__dev" style="position:fixed;right:12px;bottom:12px;z-index:2147483647;padding:7px 12px;border-radius:999px;background:#1b64f2;color:#fff;font:600 12px/1 system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.35);opacity:.92">⚡ dev changes</a>`;

async function phpRequest(method, path, { cookies = '', body = null, host = `localhost:${PORT}` } = {}) {
  await phpReady;
  const enc = body ? new TextEncoder().encode(body) : new Uint8Array(0);
  const headers = new Headers({
    host,
    'content-type': 'application/x-www-form-urlencoded',
    'x-forwarded-proto': 'https',
  });
  if (cookies) headers.set('cookie', cookies);
  const { srv, rawHeaders } = headersToServer(headers, new URL(path, `http://${host}`), method, enc.byteLength);
  const resp = await run(() =>
    php.run({
      scriptPath: `${APP_MOUNT}/public/index.php`,
      relativeUri: path,
      method,
      headers: rawHeaders,
      body: enc,
      $_SERVER: srv,
      env: { HOME: '/tmp' },
    })
  );
  const fh = flattenHeaders(resp.headers);
  const setCookies = typeof fh.getSetCookie === 'function' ? fh.getSetCookie() : [];
  return {
    status: resp.httpStatusCode || 200,
    body: new TextDecoder().decode(resp.bytes ?? new Uint8Array()),
    setCookies,
    location: fh.get('location') ?? '',
  };
}

async function ensureDevLogin() {
  if (devCookies) return devCookies;
  const g = await phpRequest('GET', '/login');
  const m = /name="_token" value="([^"]+)"/.exec(g.body);
  if (!m) throw new Error('auto-login: no CSRF token on /login');
  const jar = g.setCookies.map((c) => c.split(';')[0]);
  const body = new URLSearchParams({ _token: m[1], hotel_code: DEV_HOTEL, username: DEV_USER, password: DEV_PASS }).toString();
  const p = await phpRequest('POST', '/login', { cookies: jar.join('; '), body, host: `localhost:${PORT}` });
  const ok = p.status >= 300 && p.status < 400 && !/\/login/.test(p.location ?? '');
  process.stderr.write(`[pms-serve] auto-login POST → ${p.status} loc=${p.location} (${p.setCookies.length} cookies)\n`);
  if (!ok) throw new Error(`login POST returned ${p.status}`);
  // Dedupe by name, keeping the newest value (login regenerates the session id).
  const map = new Map();
  for (const c of [...g.setCookies, ...p.setCookies]) {
    const kv = c.split(';')[0];
    const i = kv.indexOf('=');
    if (i > 0) map.set(kv.slice(0, i), kv.slice(i + 1));
  }
  devCookies = [...map.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  process.stderr.write(`[pms-serve] dev preview signed in as ${DEV_USER}@${DEV_HOTEL} [${[...map.keys()].join(', ')}]\n`);
  return devCookies;
}

function hostFileFor(uriPath) {
  // Map a URL path onto public/ safely (no traversal).
  let p = decodeURIComponent(uriPath.split('?')[0]);
  if (p.includes('\0')) return null;
  p = normalize(p).replace(/^(\.\.[/\\])+/, '');
  const full = join(PUBLIC_DIR, p);
  if (!full.startsWith(PUBLIC_DIR)) return null;
  return full;
}

function isFile(f) {
  try {
    return existsSync(f) && statSync(f).isFile();
  } catch {
    return false;
  }
}

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.webp': 'image/webp',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.map': 'application/json',
  '.txt': 'text/plain; charset=utf-8',
  '.pdf': 'application/pdf',
  '.zip': 'application/zip',
  '.ics': 'text/calendar',
  '.xml': 'application/xml',
};

function headersToServer(headers, url, method, bodyLen) {
  const h = {};
  for (const [k, v] of headers.entries()) h[k.toLowerCase()] = v;
  const host = h['host'] ?? `localhost:${PORT}`;
  const proto = h['x-forwarded-proto'] ?? 'https';
  const serverName = host.replace(/:\d+$/, '');
  const query = url.search.startsWith('?') ? url.search.slice(1) : '';
  const srv = {
    REQUEST_METHOD: method,
    REQUEST_URI: url.pathname + url.search,
    QUERY_STRING: query,
    SERVER_PROTOCOL: 'HTTP/1.1',
    SERVER_NAME: serverName,
    SERVER_PORT: proto === 'https' ? '443' : '80',
    HTTP_HOST: host,
    HTTPS: proto === 'https' ? 'on' : 'off',
    DOCUMENT_ROOT: `${APP_MOUNT}/public`,
    SCRIPT_NAME: '/index.php',
    SCRIPT_FILENAME: `${APP_MOUNT}/public/index.php`,
    PHP_SELF: '/index.php',
    REQUEST_TIME: String(Math.floor(Date.now() / 1000)),
    REQUEST_TIME_FLOAT: String(Date.now() / 1000),
    REMOTE_ADDR: h['x-forwarded-for']?.split(',')[0]?.trim() ?? '127.0.0.1',
    REMOTE_PORT: '0',
    SERVER_SOFTWARE: 'pms-dev-server',
    CONTENT_LENGTH: String(bodyLen),
  };
  if (h['content-type']) srv.CONTENT_TYPE = h['content-type'];
  for (const [k, v] of Object.entries(h)) {
    if (k.startsWith('x-') || k === 'authorization' || k === 'cookie') {
      srv['HTTP_' + k.toUpperCase().replace(/-/g, '_')] = v;
    }
  }
  return { srv, rawHeaders: h };
}

function flattenHeaders(hdrs) {
  const out = new Headers();
  for (const [k, vals] of Object.entries(hdrs ?? {})) {
    if (k.toLowerCase() === 'status') continue;
    for (const v of vals ?? []) out.append(k, v);
  }
  return out;
}

// The Arena live preview embeds this server in a cross-site iframe on an
// https://{port}-{sandbox}.e2b.app origin. Loosen exactly what blocks that:
// CSP frame-ancestors, X-Frame-Options, and SameSite on session cookies.
let cssCache = null;
async function inlineStylesheets(html) {
  if (!cssCache) {
    cssCache = {
      'app.css': await Bun.file(`${PUBLIC_DIR}/css/app.css`).text(),
      'offline.css': await Bun.file(`${PUBLIC_DIR}/css/offline.css`).text(),
    };
  }
  return html.replace(/<link\b[^>]*href="[^"]*\/css\/(app|offline)\.css[^"]*"[^>]*>/g, (_m, name) => `<style>${cssCache[name + '.css']}</style>`);
}

function previewFriendly(h) {
  const csp = h.get('content-security-policy');
  if (csp) {
    h.set('content-security-policy', csp
      .replace(/frame-ancestors [^;]*/i, "frame-ancestors 'self' https://*.e2b.app")
      // Sandboxed preview iframes get an opaque origin, where 'self' matches
      // nothing — allow the preview host explicitly so styles/images/scripts load.
      .replace(/default-src 'self'/i, "default-src 'self' https://*.e2b.app data: blob:")
      .replace(/style-src 'self'/i, "style-src 'self' https://*.e2b.app")
      .replace(/img-src 'self'/i, "img-src 'self' https://*.e2b.app")
      .replace(/script-src 'self'/i, "script-src 'self' https://*.e2b.app")
      .replace(/font-src 'self'/i, "font-src 'self' https://*.e2b.app")
      .replace(/connect-src 'self'/i, "connect-src 'self' https://*.e2b.app"));
  }
  h.delete('x-frame-options');
  const cookies = typeof h.getSetCookie === 'function' ? h.getSetCookie() : (h.get('set-cookie') ? [h.get('set-cookie')] : []);
  if (cookies.length) {
    h.delete('set-cookie');
    for (const c of cookies) {
      h.append('set-cookie', c.replace(/;\s*[Ss]ame[Ss]ite=[^;]*/, '; SameSite=none'));
    }
  }
  return h;
}

const server = Bun.serve({
  hostname: HOST,
  port: PORT,
  maxRequestBodySize: 64 * 1024 * 1024,
  async fetch(req) {
    const url = new URL(req.url);
    const method = req.method.toUpperCase();

    // Dev preview entry: skip the marketing landing page, open the app home.
    if (DEV_LOGIN && url.pathname === '/') {
      const target = new URL(DEV_HOME, url);
      target.protocol = 'https:';
      return Response.redirect(target.toString(), 302);
    }

    // Development changes index (dev server only — not part of the product).
    if (url.pathname === '/__dev') return devChangesPage();

    // Static files (mirrors the rewrite rules in public/.htaccess).
    if (method === 'GET' || method === 'HEAD') {
      const f = hostFileFor(url.pathname);
      if (f && isFile(f) && extname(f).toLowerCase() !== '.php') {
        const mime = MIME[extname(f).toLowerCase()] ?? 'application/octet-stream';
        return new Response(method === 'HEAD' ? null : Bun.file(f), {
          headers: { 'content-type': mime, 'cache-control': 'no-cache' },
        });
      }
    }

    // Everything else: front controller (public/index.php), or a direct
    // .php file under public/ (e.g. install.php during setup).
    let script = `${APP_MOUNT}/public/index.php`;
    let scriptName = '/index.php';
    const f = hostFileFor(url.pathname);
    if (f && isFile(f) && extname(f).toLowerCase() === '.php') {
      const rel = f.slice(PUBLIC_DIR.length).replace(/\\/g, '/');
      script = `${APP_MOUNT}/public${rel}`;
      scriptName = rel;
    }

    const ab = method === 'GET' || method === 'HEAD' ? new Uint8Array(0) : new Uint8Array(await req.arrayBuffer());
    let reqHeaders = req.headers;
    if (DEV_LOGIN && !(reqHeaders.get('cookie') ?? '').includes('nowills-pms-session')) {
      try {
        const auth = await ensureDevLogin();
        reqHeaders = new Headers(reqHeaders);
        reqHeaders.set('cookie', [reqHeaders.get('cookie'), auth].filter(Boolean).join('; '));
        process.stderr.write(`[pms-serve] inject → ${auth.split('; ').map((c) => c.slice(0, c.indexOf('=') + 6)).join(' | ')}\n`);
      } catch (err) {
        process.stderr.write(`[pms-serve] auto-login failed: ${String(err?.message ?? err)}\n`);
      }
    }
    const { srv, rawHeaders } = headersToServer(reqHeaders, url, method, ab.byteLength);
    srv.SCRIPT_NAME = scriptName;
    srv.SCRIPT_FILENAME = script;
    srv.PHP_SELF = scriptName;

    try {
      await phpReady;
      const resp = await run(() =>
        php.run({
          scriptPath: script,
          relativeUri: url.pathname + url.search,
          method,
          headers: rawHeaders,
          body: ab,
          $_SERVER: srv,
          env: { HOME: '/tmp' },
        })
      );
      const head = previewFriendly(flattenHeaders(resp.headers));
      const status = resp.httpStatusCode || 200;
      let body = method === 'HEAD' ? null : resp.bytes;
      // Inline the stylesheets so the page is styled even when subresource
      // fetches are blocked (sandboxed iframes / strict CSP on the preview side).
      if (body && (head.get('content-type') ?? '').toLowerCase().includes('text/html')) {
        let html = await inlineStylesheets(new TextDecoder().decode(resp.bytes));
        if (DEV_LOGIN && !html.includes('/__dev')) {
          html = html.replace(/<\/body>/i, `${DEV_CHIP}</body>`);
        }
        head.delete('content-length');
        head.delete('transfer-encoding');
        body = new TextEncoder().encode(html);
      }
      return new Response(body, { status, headers: head });
    } catch (err) {
      process.stderr.write(`[pms-serve] ${method} ${url.pathname} -> ${String(err?.stack ?? err)}\n`);
      return new Response('Internal Server Error', { status: 500 });
    }
  },
});

console.log(`[pms-serve] Nowills PMS dev server on http://${HOST}:${PORT}`);
console.log(`[pms-serve] repo: ${REPO_ROOT}`);
