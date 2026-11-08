#!/usr/bin/env node
// Render the actual channel fields as a read-only sample of the back-office workflow.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BladePreview } from './preview/blade-render.mjs';
import { shell, nav, spriteSymbols } from './preview/app-shell.mjs';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const log = { expressions:0, fallbacks:0, failed:[], conditionsDefaulted:[], phpSkipped:[], unknownComponents:[], unknownDirectives:[], errors:[] };
const categories = {local:'Ghana & local',news:'News',sports:'Sports',movies:'Movies & series',entertainment:'Entertainment',kids:'Kids',music:'Music',business:'Business',documentary:'Documentary',religion:'Religion',hotel:'Hotel channel',radio:'Radio'};
const ctx = { helpers:{ old: (_key, fallback) => fallback, __const: (_cls, name) => name === 'CATEGORIES' ? categories : {} }, fallbacks:{} };
const renderer = new BladePreview({root,ctx,log});
const fields = renderer.renderFile('iptv/_channel_fields',{x:null});
if (log.failed.length || log.errors.length) { console.error(log.failed, log.errors); process.exit(1); }
const body = `<div class="alert alert-info"><b>Sample preview only.</b> Local and DStv channels already tuned on the TV can be entered as tuner drafts. Drafts are hidden until Samsung tuner integration is verified.</div><div class="card"><div class="card-head"><h3>Add channel</h3></div><div class="card-body"><form class="form-grid" onsubmit="event.preventDefault()">${fields}<div class="field span-all"><button type="button" class="btn btn-primary">Add (preview only)</button></div></form></div></div>`;
const html = shell(nav().navHtml, spriteSymbols(),body,{title:'Channels & Guide',crumbs:'IPTV &amp; Guest Welcome / Channels &amp; Guide',heading:'Channels & Guide'});
const out=path.join(root,'deploy/dev/previews/iptv-channel-draft.html');
if(process.argv.includes('--check')) { if(!fs.existsSync(out)||fs.readFileSync(out,'utf8')!==html){console.error('IPTV preview is stale');process.exit(1)} }
else {fs.writeFileSync(out,html); console.log('Wrote IPTV channel draft preview');}
