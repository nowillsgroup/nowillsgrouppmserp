#!/usr/bin/env node
/*
 * A git checkout on a live server has to stay clean, because that is the first
 * thing every deployment tool checks. Two kinds of file break it:
 *
 *   the web installer   the guides tell the operator to DELETE it after installing,
 *                       so tracking it leaves ` D public/install.php` for ever and
 *                       CyberPanel's Git Manager (and `git pull`) refuse to run
 *   release archives    `*.zip` builds are artifacts; committing them adds ~47 MB
 *                       to every clone and to every push
 *
 * This checks the rule rather than trusting that nobody re-adds the files, and it
 * also checks that the installer can still be reached the supported way: as a
 * template under deploy/installers, copied into place for the one install.
 *
 * Run: node deploy/dev/audit/git_tracking_check.js
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const problems = [];
const bad = (message) => problems.push(message);

const tracked = execFileSync('git', ['-C', ROOT, 'ls-files', '-z'], { encoding: 'utf8' })
  .split('\0')
  .filter(Boolean);

/* ── 1. nothing that must be absent on a live server is tracked ───────────── */
for (const path of ['install.php', 'public/install.php']) {
  if (tracked.includes(path)) {
    bad(`${path} is tracked — deleting it (as the guides instruct) makes every checkout permanently dirty`);
  }
}

const archives = tracked.filter(path => path.toLowerCase().endsWith('.zip'));
for (const path of archives) {
  bad(`${path} is a release artifact but is tracked — it belongs in dist/, not in git`);
}

/* ── 2. the installer still ships, outside the document root ──────────────── */
const templates = {
  'deploy/installers/public-install.php.txt': 'public/install.php',
  'deploy/installers/root-install.php.txt': 'install.php',
};
for (const [template, destination] of Object.entries(templates)) {
  if (!existsSync(resolve(ROOT, template))) {
    bad(`${template} is missing — the documented install has no installer to copy`);
    continue;
  }
  const text = readFileSync(resolve(ROOT, template), 'utf8');
  if (!text.startsWith('<?php')) bad(`${template} does not start with <?php — it is copied straight onto a server`);
  if (!text.includes(destination)) bad(`${template} does not name the path it is copied to (${destination})`);
  if (!text.includes('deploy/installers')) bad(`${template} does not say where it lives (a copied installer must explain itself)`);
}

/* ── 3. the ignore file matches the rule ──────────────────────────────────── */
const ignore = readFileSync(resolve(ROOT, '.gitignore'), 'utf8').split('\n').map(line => line.trim());
for (const rule of ['/install.php', '/public/install.php', '*.zip']) {
  if (!ignore.includes(rule)) bad(`.gitignore does not ignore ${rule} — the next build artefact or installer will be committed again`);
}

/* ── 4. what must stay tracked stays tracked ──────────────────────────────── */
// The deployment guides promise that no Composer run is needed on the server.
if (!tracked.includes('build/vendor/autoload.php')) {
  bad('build/vendor/autoload.php is no longer tracked — the documented install (no Composer on the server) breaks');
}

/* ── 5. the tools that explain the failure are present and helpful ────────── */
const helper = resolve(ROOT, 'deploy/cyberpanel/fix-git-tree.sh');
if (!existsSync(helper)) {
  bad('deploy/cyberpanel/fix-git-tree.sh is missing — an existing dirty checkout has no supported way out');
} else if (!(statSync(helper).mode & 0o111)) {
  bad('deploy/cyberpanel/fix-git-tree.sh is not executable');
}

const mentions = {
  'docs/INSTALL-CYBERPANEL.md': ['deploy/installers/public-install.php.txt', 'fix-git-tree.sh'],
  'deploy/cyberpanel/doctor.sh': ['fix-git-tree.sh'],
  'deploy/cyberpanel/post-deploy.sh': ['fix-git-tree.sh'],
};
for (const [file, needles] of Object.entries(mentions)) {
  const text = readFileSync(resolve(ROOT, file), 'utf8');
  for (const needle of needles) {
    if (!text.includes(needle)) bad(`${file} never mentions ${needle} — the reader cannot find the fix`);
  }
}

/* ── 6. the packaging script puts the installer back ──────────────────────── */
const packager = readFileSync(resolve(ROOT, 'deploy/package-cpanel.sh'), 'utf8');
for (const template of Object.keys(templates)) {
  if (!packager.includes(template)) bad(`deploy/package-cpanel.sh never copies ${template} into the archive — a fresh install would have no installer`);
}

/* ── report ──────────────────────────────────────────────────────────────── */
if (problems.length === 0) {
  console.log(`git tracking check: ${tracked.length} tracked files, no installer or archive committed, ${Object.keys(templates).length} installer templates shipping`);
  process.exit(0);
}

console.error(`git tracking check: ${problems.length} problem(s)`);
for (const problem of problems) console.error(`  ✗ ${problem}`);
process.exit(1);
