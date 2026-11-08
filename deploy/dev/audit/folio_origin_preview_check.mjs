import fs from 'node:fs';
import assert from 'node:assert/strict';
const html = fs.readFileSync('deploy/dev/previews/folio-origin.html', 'utf8');
for (const text of ['<title>Folio FOL-00124', 'Illustrative component preview only', 'Combined billing is not yet available', 'From FOL-0027', 'stay #11', 'last assigned room 202', 'From FOL-0028', 'reservation folio', 'folio #29', 'source folio unavailable', 'No consolidation source']) assert.ok(html.includes(text), text);
console.log('11 folio origin sample preview assertions passed.');
