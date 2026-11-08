import fs from 'node:fs';
import assert from 'node:assert/strict';
const html = fs.readFileSync('deploy/dev/previews/reservation-posted-balance.html', 'utf8');
for (const text of ['<title>Reservation RES-00124', 'Sample component preview only', 'Reservation posted balance', 'GH₵ 2,760.00', 'GH₵ 1,000.00', 'GH₵ 1,760.00', 'Room folios are still settled separately']) assert.ok(html.includes(text), text);
console.log('7 sample reservation posted balance preview assertions passed.');
