#!/usr/bin/env node
// Read-only static server for the preview screens (dashboard, fleet, POS, shift totals).
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(fileURLToPath(new URL('../../', import.meta.url)));
const PORT = Number(process.env.PORT ?? 4182);
// PREVIEW_LANDING lets a second instance open straight onto one screen (the root keeps the fleet dashboard by default).
const LANDING_SLUG = /^[a-z0-9-]+$/.test(process.env.PREVIEW_LANDING ?? '') ? process.env.PREVIEW_LANDING : 'fleet-overview';
const MIME = { '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.jpg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp' };

const server = createServer(async (req, res) => {
  const pathname = new URL(req.url ?? '/', 'http://preview.local').pathname;
  let file;
  if (pathname === '/previews' || pathname === '/__preview') {
    file = resolve(ROOT, 'deploy/dev/previews/index.html');
  } else if (pathname === '/') {
    // The root is a preview screen itself, so the panel opens on the module being worked on.
    file = resolve(ROOT, `deploy/dev/previews/${LANDING_SLUG}.html`);
  } else if (pathname === '/fleet-overview' || pathname === '/__preview/fleet-overview') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-overview.html');
  } else if (pathname === '/management-dashboard' || pathname === '/__preview/management-dashboard') {
    file = resolve(ROOT, 'deploy/dev/previews/management-dashboard.html');
  } else if (pathname === '/tape-chart' || pathname === '/__preview/tape-chart') {
    file = resolve(ROOT, 'deploy/dev/previews/tape-chart.html');
  } else if (pathname === '/reservations-workspace' || pathname === '/__preview/reservations-workspace') {
    file = resolve(ROOT, 'deploy/dev/previews/reservations-workspace.html');
  } else if (pathname === '/rooms-create' || pathname === '/__preview/rooms-create') {
    file = resolve(ROOT, 'deploy/dev/previews/rooms-create.html');
  } else if (pathname === '/rooms-edit' || pathname === '/__preview/rooms-edit') {
    file = resolve(ROOT, 'deploy/dev/previews/rooms-edit.html');
  } else if (pathname === '/fleet-maintenance' || pathname === '/__preview/fleet-maintenance') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-maintenance.html');
  } else if (pathname === '/fleet-fuel' || pathname === '/__preview/fleet-fuel') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-fuel.html');
  } else if (pathname === '/fleet-vehicle' || pathname === '/__preview/fleet-vehicle') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-vehicle.html');
  } else if (pathname === '/fleet-work-order' || pathname === '/__preview/fleet-work-order') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-work-order.html');
  } else if (pathname === '/pos-terminal' || pathname === '/__preview/pos-terminal') {
    file = resolve(ROOT, 'deploy/dev/previews/pos-terminal.html');
  } else if (pathname === '/qr-menu' || pathname === '/__preview/qr-menu') {
    file = resolve(ROOT, 'deploy/dev/previews/qr-menu.html');
  } else if (pathname === '/service-shift-totals' || pathname === '/__preview/service-shift-totals') {
    file = resolve(ROOT, 'deploy/dev/previews/service-shift-totals.html');
  } else if (pathname === '/fleet-cost-statement' || pathname === '/__preview/fleet-cost-statement') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-cost-statement.html');
  } else if (pathname === '/fleet-tracking' || pathname === '/__preview/fleet-tracking') {
    file = resolve(ROOT, 'deploy/dev/previews/fleet-tracking.html');
  } else if (pathname === '/pos-dashboard' || pathname === '/__preview/pos-dashboard') {
    file = resolve(ROOT, 'deploy/dev/previews/pos-dashboard.html');
  } else if (pathname === '/waiter-pos' || pathname === '/__preview/waiter-pos') {
    file = resolve(ROOT, 'deploy/dev/previews/waiter-pos.html');
  } else if (pathname === '/sidebar-navigation' || pathname === '/__preview/sidebar-navigation') {
    file = resolve(ROOT, 'deploy/dev/previews/sidebar-navigation.html');
  } else if (pathname === '/offline-pos' || pathname === '/__preview/offline-pos') {
    file = resolve(ROOT, 'deploy/dev/previews/offline-pos.html');
  } else if (pathname === '/offline-frontdesk' || pathname === '/__preview/offline-frontdesk') {
    file = resolve(ROOT, 'deploy/dev/previews/offline-frontdesk.html');
  } else if (pathname === '/offline-outbox' || pathname === '/__preview/offline-outbox') {
    file = resolve(ROOT, 'deploy/dev/previews/offline-outbox.html');
  } else if (pathname === '/css/fleet.css') {
    file = resolve(ROOT, 'public/css/fleet.css');
  } else if (pathname === '/css/app.css') {
    file = resolve(ROOT, 'public/css/app.css');
  } else if (pathname === '/css/dashboard.css') {
    file = resolve(ROOT, 'public/css/dashboard.css');
  } else if (pathname === '/css/offline.css') {
    file = resolve(ROOT, 'public/css/offline.css');
  } else if (pathname.startsWith('/preview-assets/')) {
    // sample photography for the preview screens (never shipped to the application)
    file = resolve(ROOT, 'deploy/dev/previews/assets', pathname.slice('/preview-assets/'.length));
  } else if (pathname.startsWith('/images/')) {
    file = resolve(ROOT, 'public', `.${pathname}`);
  }

  if (!file || !(file === ROOT || file.startsWith(ROOT + sep))) {
    res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Not found');
    return;
  }
  try {
    const info = await stat(file);
    if (!info.isFile()) throw new Error('Not a file');
    const body = await readFile(file);
    res.writeHead(200, {
      'content-type': MIME[extname(file).toLowerCase()] ?? 'application/octet-stream',
      'content-length': body.length,
      'cache-control': 'no-store',
      'x-content-type-options': 'nosniff',
    });
    res.end(body);
  } catch {
    res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Not found');
  }
});

server.listen(PORT, '0.0.0.0', () => console.log(`Preview server on http://0.0.0.0:${PORT}/ — root = ${LANDING_SLUG}, /previews = all screens`));
