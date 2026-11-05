#!/usr/bin/env node
/*
 * A stylesheet is not compiled: a single missing brace in a `<style>` block silently
 * ends the rule set, and every rule after it stops applying. The blade parser does not
 * care, PHP does not care, and the screen simply looks wrong with no error anywhere.
 *
 * This parses every stylesheet the application ships — the files in public/css and the
 * `<style>` blocks inside views — with a real CSS parser.
 *
 * Run: node deploy/dev/audit/css_syntax_check.js
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

let postcss;
try {
  postcss = (await import('postcss')).default;
} catch (_) {
  console.log('css syntax check: SKIP (postcss is not installed)');
  process.exit(0);
}

const files = [];
const walk = (dir, filter) => {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path, filter);
    else if (filter(entry)) files.push(path);
  }
};

walk(join(ROOT, 'public/css'), (name) => name.endsWith('.css'));
walk(join(ROOT, 'resources/views'), (name) => name.endsWith('.blade.php'));

let sheets = 0;
let rules = 0;
let declarations = 0;
const failures = [];

/**
 * A `<style>` block in a Blade view may hold Blade: `{{ $colour }}` inside a value, or
 * an `@if` around a rule. Those are compiled before a browser sees them, so neutralise
 * them here — a placeholder value parses, and a stray expression does not.
 */
const BLADE_DIRECTIVES = /^\s*@(if|elseif|else|endif|unless|endunless|foreach|endforeach|forelse|endforelse|for|endfor|while|endwhile|php|endphp|include|each|isset|empty|auth|endauth|guest|endguest|can|cannot|endcan|switch|case|break|default|endswitch|once|endonce|push|endpush|section|endsection|verbatim|endverbatim|checked|selected|disabled|class|style|json|continue|csrf|method|error|enderror|production|endproduction|env|endenv)\b[^\n]*$/gm;

const neutralise = (css) => css
  .replace(/\{\{[\s\S]*?\}\}/g, ' placeholder ')
  .replace(/\{!![\s\S]*?!!\}/g, ' placeholder ')
  .replace(/@php[\s\S]*?@endphp/g, ' ')
  .replace(BLADE_DIRECTIVES, ' ');

const check = (css, label) => {
  sheets += 1;
  try {
    const root = postcss.parse(css, { from: label });
    root.walkRules(() => rules++);
    root.walkDecls(() => declarations++);
  } catch (error) {
    failures.push(`${label}:${error.line}:${error.column}  ${error.reason}`);
  }
};

for (const file of files) {
  const relative = file.slice(ROOT.length + 1);
  const text = readFileSync(file, 'utf8');

  if (file.endsWith('.css')) {
    check(text, relative);
    continue;
  }

  // every <style> block in the view, whether it is pushed to the head or inline
  const pattern = /<style\b[^>]*>([\s\S]*?)<\/style>/g;
  let match;
  let index = 0;
  while ((match = pattern.exec(text)) !== null) {
    index += 1;
    const line = text.slice(0, match.index).split('\n').length;
    if (match[1].trim() === '') continue;
    check(neutralise(match[1]), `${relative} (style #${index}, line ${line})`);
  }
}

if (failures.length) {
  console.log(`css syntax check: ${failures.length} FAILED (${sheets} sheets parsed)`);
  failures.slice(0, 20).forEach((failure) => console.log(`  ✗ ${failure}`));
  if (failures.length > 20) console.log(`  … ${failures.length - 20} more`);
  process.exit(1);
}

console.log(`css syntax check: ${sheets} stylesheets parsed (${rules} rules, ${declarations} declarations), no syntax errors`);

// The workspace sheet is the one this round rewrote; say so out loud when it is missing.
if (!existsSync(join(ROOT, 'resources/views/reservations/workspace.blade.php'))) {
  console.log('  note: the reservation workspace view was not found');
}
