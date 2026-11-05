#!/usr/bin/env node
/**
 * Build the Rooms master screens (create and edit) as full application screens.
 *
 * The content is not hand-copied: resources/views/masters/form.blade.php and
 * resources/views/masters/room-photos.blade.php are rendered by
 * deploy/dev/preview/blade-render.mjs against the sample in
 * deploy/dev/preview/rooms-sample.mjs, inside the application shell from
 * deploy/dev/preview/app-shell.mjs. A change to the form — including the room
 * photo upload — shows up here instead of drifting.
 *
 * Output: deploy/dev/previews/rooms-create.html, deploy/dev/previews/rooms-edit.html
 *
 * Run: node deploy/dev/build-rooms-preview.mjs [--check]
 *      --check exits 1 when a file on disk is stale instead of rewriting it.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols, esc } from './preview/app-shell.mjs';
import { roomsContext, roomsScope, roomsDef } from './preview/rooms-sample.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const checkOnly = process.argv.includes('--check');

const log = {
  expressions: 0, fallbacks: 0,
  failed: [], conditionsDefaulted: [], phpSkipped: [],
  unknownComponents: [], unknownDirectives: [], errors: [],
};

const { menu, navHtml } = nav();
const sprite = spriteSymbols();

const indent = (html, pad = '      ') => html.trimEnd()
  .split('\n')
  .map(line => (line.trim() ? pad + line.trimStart() : line))
  .join('\n');

const screens = [
  {
    file: 'rooms-create.html',
    mode: 'create',
    title: 'New Room',
    crumbs: `Masters / <a href="/masters/rooms">Rooms</a> / New`,
  },
  {
    file: 'rooms-edit.html',
    mode: 'edit',
    title: 'Edit Room',
    crumbs: `Masters / <a href="/masters/rooms">Rooms</a> / Edit`,
  },
];

let stale = 0;
for (const screen of screens) {
  const ctx = { ...roomsContext() };
  const scope = roomsScope(screen.mode);
  const renderer = new BladePreview({ root: ROOT, ctx, log });
  const contentRaw = renderer.section('masters/form', 'content', scope);

  const html = shell(navHtml, sprite, indent(contentRaw), {
    title: screen.title,
    crumbs: screen.crumbs,
  });

  const out = path.join(ROOT, 'deploy/dev/previews', screen.file);
  const previous = fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : null;
  if (previous === html) {
    console.log(`${screen.file} is up to date`);
    continue;
  }
  if (checkOnly) {
    stale++;
    console.error(`${screen.file} is stale — run node deploy/dev/build-rooms-preview.mjs`);
    continue;
  }
  fs.writeFileSync(out, html);
  console.log(`wrote deploy/dev/previews/${screen.file} (${html.length} bytes)`);
}

if (log.failed.length) {
  console.log(`  unresolved expressions .. ${log.failed.length}`);
  for (const item of log.failed.slice(0, 10)) console.log(`    ${item.expr} → ${item.reason}`);
}
if (log.unknownComponents.length) {
  console.log(`  unknown components ...... ${[...new Set(log.unknownComponents)].join(', ')}`);
}
if (log.unknownDirectives.length) {
  console.log(`  unknown directives ...... ${[...new Set(log.unknownDirectives.map(d => d.split(' ')[0]))].join(', ')}`);
}
if (log.errors.length) log.errors.forEach(e => console.error(`  error: ${e}`));

console.log(`menu sections: ${menu.length} | sprite symbols: ${sprite.length} | fields: ${Object.keys(roomsDef.fields).length}`);

if (checkOnly && stale) process.exit(1);
