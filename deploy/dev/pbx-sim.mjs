#!/usr/bin/env bun
// deploy/dev/pbx-sim.mjs — TCP PBX simulator for the live interface driver.
//
// Answers command lines with "OK <line>" (like a configured PABX command port)
// and streams SMDR call-detail lines (like a PBX SMDR output). Used by
// docs/PBX-ADAPTER.md's certification walkthrough:
//
//   bun deploy/dev/pbx-sim.mjs --cmd-port=4455 --smdr-port=4456 --close-after=3
//   deploy/dev/bin/php artisan pms:pbx:listen --hotel=1 --host=127.0.0.1 --port=4456 --once
//
// Options:
//   --cmd-port=N    command listener port (default 4455)
//   --smdr-port=N   SMDR stream port (default 4456)
//   --close-after=N send N SMDR lines per connection then close (default: keep open)
//   --ext=XXX       extension used in sample lines (default 101)
//   --line="..."    append a raw SMDR line (repeatable), in place of samples

import net from 'node:net';

const args = process.argv.slice(2);
const opt = (name, def) => {
  const hit = args.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : def;
};
const cmdPort = Number(opt('cmd-port', '4455'));
const smdrPort = Number(opt('smdr-port', '4456'));
const closeAfter = Number(opt('close-after', '0'));
const ext = opt('ext', '101');
const customLines = args.filter((a) => a.startsWith('--line=')).map((a) => a.slice(7));

const sampleLines = () => {
  if (customLines.length) return customLines;
  const t = (minsAgo) => {
    const d = new Date(Date.now() - minsAgo * 60_000);
    return `${d.toISOString().slice(0, 10)},${d.toTimeString().slice(0, 8)}`;
  };
  return [
    `${t(40)},${ext},09,+233244123456,00:03:25`,
    `${t(25)},${ext},09,0302555012,00:01:10`,
    `${t(5)},${ext},10,100,00:00:42`, // free/internal
  ];
};

// ── command listener: read a line, reply "OK …" ─────────────────────────────
net
  .createServer((sock) => {
    sock.on('data', (data) => {
      for (const raw of data.toString().split(/\r?\n/)) {
        const line = raw.trim();
        if (!line) continue;
        console.log(`[cmd ] ← ${line}`);
        sock.write(`OK ${line}\r\n`);
        console.log(`[cmd ] → OK ${line}`);
      }
    });
    sock.on('error', () => {});
  })
  .listen(cmdPort, '0.0.0.0', () => console.log(`[sim ] command listener on 0.0.0.0:${cmdPort}`));

// ── SMDR listener: stream call-detail lines on connect ──────────────────────
net
  .createServer((sock) => {
    console.log(`[smdr] PMS connected (${sock.remoteAddress}:${sock.remotePort})`);
    const lines = sampleLines();
    let sent = 0;
    const timer = setInterval(() => {
      const done = closeAfter > 0 && sent >= closeAfter;
      if (done || sent >= lines.length) {
        clearInterval(timer);
        if (closeAfter > 0) {
          console.log(`[smdr] sent ${sent} line(s) — closing (close-after=${closeAfter})`);
          sock.end();
        }
        return;
      }
      const line = lines[sent++];
      sock.write(`${line}\r\n`);
      console.log(`[smdr] → ${line}`);
    }, 250);
    sock.on('error', () => {});
    sock.on('close', () => clearInterval(timer));
  })
  .listen(smdrPort, '0.0.0.0', () => console.log(`[sim ] SMDR listener on 0.0.0.0:${smdrPort}`));
