#!/usr/bin/env node
// Minimal, read-only server for the standalone login UI preview.
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(fileURLToPath(new URL('../../', import.meta.url)));
const PORT = Number(process.env.PORT ?? 4181);
const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.js': 'text/javascript; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp',
};

const server = createServer(async (req, res) => {
  const pathname = new URL(req.url ?? '/', 'http://preview.local').pathname;
  let file;
  if (pathname === '/' || pathname === '/login') {
    file = resolve(ROOT, 'deploy/dev/previews/login-page.html');
  } else if (pathname === '/css/app.css') {
    file = resolve(ROOT, 'public/css/app.css');
  } else if (pathname === '/favicon.svg') {
    file = resolve(ROOT, 'public/favicon.svg');
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

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Login UI preview at http://0.0.0.0:${PORT}/`);
});
