#!/usr/bin/env bun
// Local TCP encoder simulator. Example:
//   bun deploy/dev/lock-sim.mjs --port=4457
//   configure Interface settings with host 127.0.0.1, port 4457, driver TCP,
//   and templates such as ENCODE {room} {until} {areas}.
// It echoes a successful reply and a deterministic serial for encode commands.
import net from 'node:net';

const portArg = process.argv.find((a) => a.startsWith('--port='));
const port = Number(portArg?.slice(7) || 4457);
const server = net.createServer((socket) => {
  let pending = '';
  socket.on('data', (buf) => {
    pending += buf.toString('utf8');
    const nl = pending.indexOf('\n');
    if (nl < 0) return;
    const line = pending.slice(0, nl).replace(/\r$/, '').trim();
    const serial = `SIM${Date.now().toString(16).slice(-8)}`.toUpperCase();
    const reply = /^(ENCODE|DUPLICATE)\b/i.test(line)
      ? `OK UID=${serial} COMMAND=${line.split(/\s+/, 1)[0]}`
      : `OK COMMAND=${line.split(/\s+/, 1)[0] || 'EMPTY'}`;
    socket.end(`${reply}\r\n`);
    console.log(`${new Date().toISOString()} ${line} -> ${reply}`);
  });
});
server.listen(port, '0.0.0.0', () => console.log(`Lock encoder simulator listening on 0.0.0.0:${port}`));
