/**
 * GRA VSDC / E-VAT API – local protocol simulator.
 *
 * Mirrors the certified GRA E-VAT response dialect used by EInvoiceService::graSubmit:
 *   POST <url>   body = invoice/REFUND payload (invoiceNumber, sellerTin, items…)
 *   Auth         header security_key: <key>
 *   Success      {status:"SUCCESS", response:{message:{ysdcid,ysdcrecnum,ysdcintdata,ysdcregsig,ysdcmrc,ysdcmrctim,ysdctime,flag,ysdcitems}, qr_code}}
 *   Errors       {status:"ERROR", code:"A08|A09|B16|B19|…", message:"…"}
 *   (GRA spells "mesaage" in some versions — served on /vsdc/legacy.)
 *
 * Usage: node deploy/dev/vsdc-sim.mjs --port=8094 --key=gra-secret [--fail-first=1]
 *   --fail-first=1  the first invoice returns HTTP 500 (exercises the retry backoff)
 */
import http from 'node:http';
import crypto from 'node:crypto';

const args = Object.fromEntries(process.argv.slice(2).map(a => {
  const m = a.match(/^--([^=]+)=?(.*)$/); return m ? [m[1], m[2]] : [a, ''];
}));
const PORT = Number(args.port ?? 8094);
const KEY = String(args.key ?? 'gra-secret');
const FAIL_FIRST = String(args.failFirst ?? args['fail-first'] ?? '') === '1';

const state = { received: [], stamps: {}, seq: 0, failures: 0, failFirstDone: !FAIL_FIRST };
const group = s => s.match(/.{1,4}/g).join('-');

const json = (res, code, obj) => {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
};

http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  const p = u.pathname;

  if (req.method === 'GET' && (p === '/' || p === '/status')) {
    return json(res, 200, { service: 'GRA VSDC simulator', received: state.received.length, stamped: Object.keys(state.stamps).length, failures: state.failures, queue: Object.keys(state.stamps) });
  }

  if (req.method !== 'POST') return json(res, 404, { status: 'ERROR', code: 'HTTP404', message: 'not found' });

  if ((req.headers.security_key ?? '') !== KEY) {
    state.failures++;
    return json(res, 401, { status: 'ERROR', code: 'HTTP401', message: 'invalid security key' });
  }

  let raw = '';
  req.on('data', c => raw += c);
  req.on('end', () => {
    let b; try { b = JSON.parse(raw || '{}'); } catch { return json(res, 400, { status: 'ERROR', code: 'HTTP400', message: 'invalid JSON' }); }
    state.received.push({ at: new Date().toISOString(), user: b.userName ?? null, doc: b.invoiceNumber ?? null, flag: b.flag ?? null });

    if (!state.failFirstDone) {           // retry-path exercise: one 500, then normal
      state.failFirstDone = true; state.failures++;
      return json(res, 500, { status: 'ERROR', code: 'HTTP500', message: 'VSDC temporarily unavailable' });
    }

    // ── GRA pre-checks (same codes the real VSDC returns) ──
    const items = Array.isArray(b.items) ? b.items : [];
    const levy = Math.round(items.reduce((s, i) => s + (+i.levyAmountA || 0) + (+i.levyAmountB || 0) + (+i.levyAmountC || 0) + (+i.levyAmountD || 0) + (+i.levyAmountE || 0), 0) * 100) / 100;
    if (Math.abs(levy - (+b.totalLevy || 0)) > 0.05) { state.failures++; return json(res, 400, { status: 'ERROR', code: 'A09', message: 'Total levy does not match the sum of item levies.' }); }
    const vat = Math.round(items.reduce((s, i) => s + (+i.vatAmount || 0), 0) * 100) / 100;
    if (Math.abs(vat - (+b.totalVat || 0)) > 0.05) { state.failures++; return json(res, 400, { status: 'ERROR', code: 'A08', message: 'Total VAT does not match the sum of item VAT.' }); }
    if (b.businessPartnerTin && b.businessPartnerTin === b.sellerTin) { state.failures++; return json(res, 400, { status: 'ERROR', code: 'B16', message: "The customer TIN cannot be the seller's TIN." }); }
    if (b.flag === 'REFUND' && !b.reference) { state.failures++; return json(res, 400, { status: 'ERROR', code: 'B19', message: 'A refund must reference the original invoice number.' }); }

    // ── idempotent stamp ──
    const doc = String(b.invoiceNumber ?? 'DOC');
    if (state.stamps[doc]) return json(res, 200, { status: 'SUCCESS', response: { message: state.stamps[doc], qr_code: state.stamps[doc].qr, info: 'DUPLICATE' } });

    state.seq++;
    const h = crypto.createHash('sha256').update(`${b.sellerTin}|${doc}|${b.totalAmount}|${state.seq}`).digest('hex').toUpperCase();
    const time = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const flag = b.flag === 'REFUND' ? 'NR' : 'NS';
    const msg = {
      ysdcid: 'VSDC-' + String(b.sellerTin ?? '0000').replace(/\D/g, '').slice(0, 4).padEnd(4, '0') + '-LIVE',
      ysdcrecnum: `${state.seq}/${state.seq} ${flag}`,
      ysdcintdata: group(h.slice(0, 24)), ysdcregsig: group(h.slice(24, 40)),
      ysdcmrc: 'GRA-' + h.slice(40, 46), ysdcmrctim: time, ysdctime: time, flag: b.flag, ysdcitems: items.length,
    };
    const claims = Buffer.from(JSON.stringify({ sdc: msg.ysdcid, num: msg.ysdcrecnum, inv: doc, tin: b.sellerTin, total: b.totalAmount, vat: b.totalVat, time })).toString('base64url');
    msg.qr = 'eyJhbGciOiJIUzI1NiJ9.' + claims + '.' + crypto.createHash('sha256').update(claims).digest('base64url');
    state.stamps[doc] = msg;
    return json(res, 200, { status: 'SUCCESS', response: { message: msg, qr_code: msg.qr } });
  });
}).listen(PORT, '0.0.0.0', () => console.log(`[vsdc-sim] GRA VSDC simulator on http://0.0.0.0:${PORT}`));
