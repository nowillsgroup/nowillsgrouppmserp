#!/usr/bin/env node
// Verify preview and the fail-closed source plumbing without pretending to test a Samsung TV.
import {readFileSync} from 'node:fs';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {JSDOM} from 'jsdom';
import assert from 'node:assert/strict';
const root=resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const read=p=>readFileSync(resolve(root,p),'utf8');
const doc=new JSDOM(read('deploy/dev/previews/iptv-channel-draft.html')).window.document;
assert.equal(doc.title.includes('Channels & Guide'),true);
assert.ok(doc.querySelector('select[name="source_type"] option[value="tuner"]'));
assert.equal(doc.querySelector('input[name="tuner_number"]').min,'1');
assert.equal(doc.querySelector('input[name="tuner_number"]').max,'9999');
assert.match(doc.body.textContent,/hidden until Samsung tuner integration is verified/);
const service=read('app/Services/IptvService.php');
assert.match(service,/\$source === 'stream' && ! empty\(\$d\['is_active'\]\)/);
assert.match(service,/where\('is_active', true\)->where\('source_type', 'stream'\)->orderBy\('number'\)->get\(\)->map/);
assert.match(service,/\$ch && \$ch->source_type === 'tuner'[\s\S]*?\$out\['skipped'\]\+\+/);
assert.match(service,/where\('is_active', true\)->where\('source_type', 'stream'\)->orderBy\('number'\)->get\(\) as \$c/);
assert.match(service,/\$tunerNumber >= 1 && \$tunerNumber <= 9999/);
const migration=read('database/migrations/2026_11_08_000450_add_tv_tuner_channel_source.php');
assert.match(migration,/default\('stream'\)/);
assert.match(migration,/whereNotNull\('tuner_number'\)->exists\(\)/);
console.log('IPTV tuner draft preview and source checks passed (no hardware or database exercised).');
