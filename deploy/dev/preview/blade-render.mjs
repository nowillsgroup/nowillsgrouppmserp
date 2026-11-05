/**
 * A small Blade renderer for static previews.
 *
 * The previews in deploy/dev/previews are images of real application screens.
 * Copying a screen by hand goes stale the moment the view changes, so this
 * module renders the actual .blade.php source with sample data instead:
 *
 *   directives   @if / @unless / @elseif / @else / @foreach / @forelse / @empty
 *                @php / @endphp / @include / @push (skipped) / @section (extracted)
 *   echoes       {{ $x }} escaped, {!! $x !!} raw
 *   components   <x-icon>, <x-empty>
 *   expressions  evaluated against the sample scope (see dashboard-sample.mjs)
 *
 * Anything the sample cannot evaluate falls back to a sensible value and is
 * counted in the render report, so a drifting preview is visible instead of
 * silently wrong.
 */
import fs from 'node:fs';
import path from 'node:path';

const OPEN = new Set(['if', 'unless', 'foreach', 'forelse', 'php', 'push', 'section', 'once', 'for', 'while', 'switch', 'auth', 'guest', 'isset', 'can', 'cannot']);
const CLOSE = new Set(['endif', 'endunless', 'endforeach', 'endforelse', 'endphp', 'endpush', 'endsection', 'endonce', 'endfor', 'endwhile', 'endswitch', 'endauth', 'endguest', 'endisset', 'endempty', 'endcan', 'endcannot']);

export const esc = (s) => String(s ?? '')
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/* ── collections & dates the views use ─────────────────────────────────────── */

export class Coll {
  constructor(items = []) { this.items = items; }
  static of(items) { return new Coll(Array.isArray(items) ? items.slice() : []); }
  all() { return this.items; }
  count() { return this.items.length; }
  isEmpty() { return this.items.length === 0; }
  isNotEmpty() { return this.items.length > 0; }
  pick(key) {
    if (key === undefined) return (i) => Number(i ?? 0);
    if (typeof key === 'function') return (i) => Number(key(i) ?? 0);
    return (i) => Number(i?.[key] ?? 0);
  }
  sum(key) {
    const pick = this.pick(key);
    return this.items.reduce((t, i) => t + pick(i), 0);
  }
  max(key) {
    if (!this.items.length) return 0;
    const pick = this.pick(key);
    return Math.max(...this.items.map(pick));
  }
  min(key) {
    if (!this.items.length) return 0;
    const pick = this.pick(key);
    return Math.min(...this.items.map(pick));
  }
  take(n) { return new Coll(this.items.slice(0, n)); }
  values() { return new Coll(this.items.slice()); }
  first() { return this.items[0]; }
  last() { return this.items[this.items.length - 1]; }
  push(...v) { this.items.push(...v); return this; }
  map(fn) { return new Coll(this.items.map((v, i) => fn(v, i))); }
  filter(fn) { return new Coll(this.items.filter((v, i) => (typeof fn === 'function' ? fn(v, i) : Boolean(v)))); }
  reject(fn) { return new Coll(this.items.filter((v, i) => !fn(v, i))); }
  contains(key, value) {
    if (key === undefined) return this.items.length > 0;
    if (value === undefined) return this.items.includes(key);
    return this.items.some(i => i?.[key] === value);
  }
  firstWhere(key, value) { return this.items.find(i => i?.[key] === value); }
  where(key, value) {
    return new Coll(this.items.filter(i => {
      const got = typeof key === 'function' ? key(i) : i?.[key];
      return got === value;
    }));
  }
  whereIn(key, list) { return new Coll(this.items.filter(i => (list?.items ?? list ?? []).includes(i?.[key]))); }
  pluck(key) {
    return new Coll(this.items.map(i => String(key).split('.').reduce((o, k) => (o == null ? o : o[k]), i)));
  }
  unique() { return new Coll([...new Set(this.items)]); }
  join(sep = ', ') { return this.items.join(sep); }
  implode(sep = '') { return this.items.map(i => (i == null ? '' : String(i))).join(sep); }
  reverse() { return new Coll(this.items.slice().reverse()); }
  toArray() { return this.items; }
}

export class SampleDate {
  constructor(date, ago) { this.d = new Date(date); this.ago = ago; }
  valueOf() { return +this.d; }
  getTime() { return +this.d; }
  copy() { return new SampleDate(this.d, this.ago); }
  addDay(n = 1) { return new SampleDate(+this.d + 86400000 * n, this.ago); }
  addMonth(n = 1) {
    const d = new Date(this.d);
    d.setUTCMonth(d.getUTCMonth() + n);
    return new SampleDate(d, this.ago);
  }
  day(n) {
    const d = new Date(this.d);
    d.setUTCDate(n);
    return new SampleDate(d, this.ago);
  }
  startOfMonth() {
    const d = new Date(this.d);
    d.setUTCDate(1);
    return new SampleDate(d, this.ago);
  }
  get year() { return this.d.getUTCFullYear(); }
  toIso8601String() { return this.d.toISOString().replace('.000Z', '+00:00'); }
  max(other) { return +this.d >= +(other?.d ?? new Date(other)) ? this : other; }
  min(other) { return +this.d <= +(other?.d ?? new Date(other)) ? this : other; }
  diffInDays(other) {
    const target = other instanceof SampleDate ? other.d : new Date(`${other}T00:00:00Z`);
    return Math.round((+this.d - +target) / 86400000);
  }
  get daysInMonth() {
    return new Date(Date.UTC(this.d.getUTCFullYear(), this.d.getUTCMonth() + 1, 0)).getUTCDate();
  }
  isSameDay(other) { return this.toDateString() === (other?.toDateString?.() ?? String(other)); }
  gt(other) { return +this.d > +new Date(`${other?.toDateString?.() ?? other}T00:00:00Z`); }
  lt(other) { return +this.d < +new Date(`${other?.toDateString?.() ?? other}T00:00:00Z`); }
  gte(other) { return !this.lt(other); }
  lte(other) { return !this.gt(other); }
  addDays(n) { return this.addDay(n); }
  subDay(n = 1) { return this.addDay(-n); }
  subDays(n) { return this.addDay(-n); }
  subMonthNoOverflow() {
    const d = new Date(this.d);
    d.setMonth(d.getMonth() - 1);
    return new SampleDate(d, this.ago);
  }
  toDateString() { return this.d.toISOString().slice(0, 10); }
  isoFormat() { return this.d.toISOString().slice(0, 10); }
  timestamp() { return Math.floor(+this.d / 1000); }
  format(pattern) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const monthsLong = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const daysLong = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const two = (n) => String(n).padStart(2, '0');
    const map = {
      D: days[this.d.getUTCDay()], l: daysLong[this.d.getUTCDay()],
      d: two(this.d.getUTCDate()), j: String(this.d.getUTCDate()),
      M: months[this.d.getUTCMonth()], F: monthsLong[this.d.getUTCMonth()],
      m: two(this.d.getUTCMonth() + 1), n: String(this.d.getUTCMonth() + 1),
      Y: String(this.d.getUTCFullYear()), w: String(this.d.getUTCDay()),
      H: two(this.d.getUTCHours()), i: two(this.d.getUTCMinutes()),
    };
    return String(pattern).replace(/D|l|d|j|M|F|m|n|Y|w|H|i/g, (t) => map[t] ?? t);
  }
  diffForHumans() {
    if (this.ago) return this.ago;
    const mins = Math.max(1, Math.round((Date.now() - +this.d) / 60000));
    if (mins < 60) return `${mins} minutes ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs} hour${hrs === 1 ? '' : 's'} ago`;
    const days = Math.round(hrs / 24);
    return `${days} day${days === 1 ? '' : 's'} ago`;
  }
}

/** Anything the sample does not model: returns a plausible small count per key. */
export function countsProxy(defaults = {}) {
  const store = { ...defaults };
  return new Proxy(store, {
    get(t, k) {
      if (k === Symbol.toPrimitive) return () => 0;
      if (k === 'toJSON') return () => ({ ...t });
      if (k in t) return t[k];
      if (typeof k === 'string') return 0;
      return undefined;
    },
  });
}

/* ── the renderer ─────────────────────────────────────────────────────────── */

export class BladePreview {
  constructor({ root, ctx, log }) {
    this.root = root;
    this.ctx = ctx;
    this.log = log;
    this.proxies = new WeakMap();
  }

  file(rel) {
    const clean = String(rel).replace(/\./g, '/').replace(/^\/+/, '');
    const candidates = [
      path.join(this.root, 'resources/views', `${clean}.blade.php`),
      path.join(this.root, 'resources/views', clean, 'index.blade.php'),
    ];
    const found = candidates.find(p => fs.existsSync(p));
    if (!found) throw new Error(`view not found: ${rel}`);
    return found;
  }

  renderFile(rel, scope) {
    return this.renderSource(fs.readFileSync(this.file(rel), 'utf8'), scope, rel);
  }

  /** Renders the body of @section('name') from a view. */
  section(rel, name, scope = {}) {
    const src = fs.readFileSync(this.file(rel), 'utf8');
    const start = src.search(new RegExp(`@section\\(\\s*'${name}'\\s*\\)`));
    if (start < 0) throw new Error(`section ${name} not found in ${rel}`);
    const from = src.indexOf(')', src.indexOf('(', start)) + 1;
    const end = src.indexOf('@endsection', from);
    return this.renderSource(src.slice(from, end < 0 ? src.length : end), scope, `${rel}#${name}`).trim();
  }

  /** Renders every @push('name') block in a view (page styles and scripts). */
  push(rel, name, scope) {
    const src = fs.readFileSync(this.file(rel), 'utf8');
    const opener = new RegExp(`@push\\(\\s*'${name}'\\s*\\)`, 'g');
    let out = '';
    let match;
    while ((match = opener.exec(src))) {
      const from = match.index + match[0].length;
      const end = src.indexOf('@endpush', from);
      if (end < 0) break;
      out += this.renderSource(src.slice(from, end), scope, `${rel}#push:${name}`);
      opener.lastIndex = end + '@endpush'.length;
    }
    return out.trim();
  }

  scopeProxy(scope) {
    let proxy = this.proxies.get(scope);
    if (proxy) return proxy;
    const self = this;
    proxy = new Proxy(scope, {
      has: () => true,
      get(t, k) {
        if (typeof k === 'symbol') return undefined;
        if (k in t) return t[k];
        const h = self.ctx.helpers;
        if (k in h) return h[k];
        return undefined;
      },
      set(t, k, v) { t[k] = v; return true; },
    });
    this.proxies.set(scope, proxy);
    return proxy;
  }

  /** Evaluates a PHP-ish expression against the sample scope. */
  evalExpr(expr, scope) {
    const raw = String(expr).trim();
    try {
      const js = toJs(raw);
      // eslint-disable-next-line no-new-func
      const fn = new Function('__scope', `with (__scope) { return (${js}); }`);
      const value = fn(this.scopeProxy(scope));
      this.log.expressions++;
      return value;
    } catch (err) {
      this.log.failed.push({ expr: raw, reason: err.message });
      if (Object.prototype.hasOwnProperty.call(this.ctx.fallbacks, raw)) {
        this.log.fallbacks++;
        return this.ctx.fallbacks[raw];
      }
      return undefined;
    }
  }

  captureAssignments(expr, scope) {
    const re = /\(?\s*\$([A-Za-z_]\w*)\s*=(?![=>])\s*/g;
    let m;
    while ((m = re.exec(String(expr)))) {
      const start = m.index + m[0].length;
      let depth = 0;
      let quote = null;
      let end = String(expr).length;
      for (let j = start; j < expr.length; j++) {
        const ch = expr[j];
        if (quote) { if (ch === '\\') { j++; continue; } if (ch === quote) quote = null; continue; }
        if (ch === "'" || ch === '"') { quote = ch; continue; }
        if ('([{'.includes(ch)) depth++;
        else if (')]}'.includes(ch)) {
          if (depth === 0) { end = j; break; }
          depth--;
          if (depth === 0) { end = j + 1; break; }
        } else if (depth === 0 && (expr.startsWith('&&', j) || expr.startsWith('||', j))) { end = j; break; }
      }
      const value = this.evalExpr(expr.slice(start, end), scope);
      if (value !== undefined) scope[m[1]] = value;
    }
  }

  bool(expr, scope) {
    if (String(expr).includes('=')) this.captureAssignments(expr, scope);
    const v = this.evalExpr(expr, scope);
    if (v === undefined) {
      this.log.conditionsDefaulted.push(String(expr).trim());
      return true;
    }
    return Boolean(v);
  }

  /** Runs a @php block: simple assignments only; anything structural is skipped. */
  runPhp(code, scope) {
    code = String(code).replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/\/\/[^\n]*/g, ' ');
    // Structural blocks (loops, braced conditionals, declarations) are beyond a sample.
    if (/\b(foreach|while|switch|function|class)\s*[({]/.test(code) || /\bif\s*\([^)]*\)\s*\{/.test(code)
        || /\b(else|elseif|endif|endforeach|endwhile)\b/.test(code)) {
      this.log.phpSkipped.push(code.trim().slice(0, 90));
      return;
    }
    for (const statement of splitTopLevel(code, ';')) {
      // A one-line `if (...) $x = ...;` cannot be evaluated; leave it out.
      if (/^\s*(if|elseif|else|foreach|while|switch|case|default|\}|\{)/.test(statement)) continue;
      const list = /^\s*\[\s*((?:\$\w+\s*,?\s*)+)\]\s*=\s*([\s\S]+)$/.exec(statement);
      if (list) {
        const value = this.evalExpr(list[2], scope);
        const names = list[1].split(',').map(n => n.trim().replace(/^\$/, '')).filter(Boolean);
        if (Array.isArray(value) || (value instanceof Coll)) {
          const items = value instanceof Coll ? value.items.map(([, v]) => v) : value;
          names.forEach((name, index) => { scope[name] = items[index]; });
        }
        continue;
      }
      const m = /^\s*\$?([A-Za-z_]\w*)\s*=\s*([\s\S]+)$/.exec(statement);
      if (!m) continue;
      const value = this.evalExpr(m[2], scope);
      if (value !== undefined) scope[m[1]] = value;
    }
  }

  icon(name, size, extraClass = '') {
    const cls = ['i', size === 'sm' ? 'i-sm' : '', extraClass].filter(Boolean).join(' ');
    return `<svg class="${cls}" aria-hidden="true"><use href="#i-${esc(name || 'grid')}"/></svg>`;
  }

  /** @class(['is-invalid' => $errors->has('photos')]) */
  classAttr(arg, scope) {
    const inner = String(arg).trim().replace(/^\[/, '').replace(/\]$/, '');
    const names = [];
    for (const part of splitTopLevel(inner, ',')) {
      const p = part.trim();
      if (!p) continue;
      const arrow = splitTopLevel(p, '=>');
      if (arrow.length > 1) {
        const key = arrow[0].trim().replace(/^['"]|['"]$/g, '');
        if (this.evalExpr(arrow.slice(1).join('=>'), scope)) names.push(key);
      } else {
        names.push(p.replace(/^['"]|['"]$/g, ''));
      }
    }
    return `class="${esc(names.join(' '))}"`;
  }

  hasError(name) {
    const errors = this.ctx?.scope?.errors;
    return Boolean(errors && typeof errors.has === 'function' && errors.has(name));
  }

  component(src, start) {
    const nameMatch = /^<x-([\w-]+)/.exec(src.slice(start));
    const tag = nameMatch[1];
    const end = findTagEnd(src, start);
    if (end < 0) return { html: '', end: start + 1 };
    const inner = src.slice(start, end + 1);
    const attrs = {};
    for (const m of inner.matchAll(/([:@]?[\w-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
      attrs[m[1].replace(/^[:@]/, '')] = { value: m[2] ?? m[3], dynamic: m[1].startsWith(':') };
    }
    // <x-icon name="{{ $icon }}"/> — Blade interpolates attributes too
    const interpolate = (text) => String(text).replace(/\{\{\s*([\s\S]+?)\s*\}\}/g, (whole, expr) => {
      const value = this.evalExpr(expr, this.currentScope);
      return value === undefined || value === null ? '' : String(value);
    });
    const read = (key) => {
      const a = attrs[key];
      if (!a) return undefined;
      if (!a.dynamic) return interpolate(a.value);
      const v = this.evalExpr(a.value, this.currentScope);
      return v === undefined ? '' : v;
    };
    function readExtra() {
      const consumed = new Set(['name', 'id', 'label', 'type', 'value', 'required',
        'options', 'placeholder', 'hint', 'class']);
      const out = [];
      for (const [key, attr] of Object.entries(attrs)) {
        if (consumed.has(key)) continue;
        const v = attr.dynamic ? (read(key) ?? '') : interpolate(attr.value);
        out.push(v === '' ? ` ${key}` : ` ${key}="${esc(v)}"`);
      }
      return out.join('');
    }
    if (tag === 'icon') return { html: this.icon(read('name'), read('size'), read('class') ?? ''), end };
    if (tag === 'field') {
      const name = read('name') ?? '';
      const type = read('type') ?? 'text';
      const id = read('id') || `f_${String(name).replace(/[[\]]/g, '_')}`;
      const label = read('label');
      const value = read('value');
      const required = Boolean(read('required'));
      const placeholder = read('placeholder');
      const hint = read('hint');
      const extra = readExtra();
      const val = value === undefined || value === null ? '' : value;
      const invalid = this.hasError(name) ? ' is-invalid' : '';
      const common = `${extra}${invalid ? ` class="${invalid.trim()}"` : ''}`;
      let control = '';
      if (type === 'select') {
        const options = read('options') ?? {};
        const rows = Object.entries(options).map(([k, v]) =>
          `<option value="${esc(k)}"${String(val) === String(k) ? ' selected' : ''}>${esc(v)}</option>`).join('');
        const blank = placeholder === false ? '' : `<option value="">${esc(placeholder ?? '— Select —')}</option>`;
        control = `<select name="${esc(name)}" id="${esc(id)}"${required ? ' required' : ''}${common}>${blank}${rows}</select>`;
      } else if (type === 'textarea') {
        control = `<textarea name="${esc(name)}" id="${esc(id)}" placeholder="${esc(placeholder ?? '')}"${required ? ' required' : ''}${common}>${esc(val)}</textarea>`;
      } else {
        control = `<input type="${esc(type)}" name="${esc(name)}" id="${esc(id)}" value="${esc(val)}" placeholder="${esc(placeholder ?? '')}"${required ? ' required' : ''}${common}>`;
      }
      const head = label ? `<label for="${esc(id)}">${esc(label)}${required ? ' <span class="req">*</span>' : ''}</label>` : '';
      const foot = hint ? `<span class="hint">${esc(hint)}</span>` : '';
      return {
        html: `<div class="field ${esc(read('class') ?? '')}">${head}${control}${foot}</div>`,
        end,
      };
    }
    if (tag === 'empty') {
      const text = read('text') ?? 'No records found.';
      return { html: `<div class="empty">${this.icon(read('icon') ?? 'box')}<div>${esc(text)}</div></div>`, end };
    }
    this.log.unknownComponents.push(tag);
    return { html: '', end };
  }

  renderSource(src, scope, file = '?') {
    const segments = splitVerbatim(src);
    if (segments.length > 1 || (segments[0] && segments[0].raw)) {
      return segments
        .map(segment => (segment.raw ? segment.text : this.renderSource(segment.text, scope, file)))
        .join('');
    }

    const previous = this.currentScope;
    const outer = scope;
    let out = '';
    let i = 0;
    const local = Object.create(outer);
    this.currentScope = local;

    while (i < src.length) {
      const next = findNext(src, i);
      if (!next) { out += src.slice(i); break; }
      out += src.slice(i, next.start);
      i = next.start;

      if (next.type === 'comment') {
        const end = src.indexOf('--}}', i);
        i = end < 0 ? src.length : end + 4;
        continue;
      }

      if (next.type === 'echo' || next.type === 'raw') {
        const close = next.type === 'echo' ? '}}' : '!!}';
        const end = src.indexOf(close, i + (next.type === 'echo' ? 2 : 3));
        const expr = src.slice(i + (next.type === 'echo' ? 2 : 3), end < 0 ? src.length : end);
        const value = this.evalExpr(expr, local);
        const rendered = value === undefined || value === null ? '' : (value instanceof Coll ? value.implode(', ') : String(value));
        out += next.type === 'echo' ? esc(rendered) : rendered;
        i = end < 0 ? src.length : end + close.length;
        continue;
      }

      if (next.type === 'component') {
        const { html, end } = this.component(src, i);
        out += html;
        i = end + 1;
        continue;
      }

      if (next.type === 'directive') {
        const word = next.word;
        const afterWord = i + 1 + word.length;
        const paren = /^\s*\(/.test(src.slice(afterWord));
        const argEnd = paren ? matchParen(src, src.indexOf('(', afterWord)) : afterWord;
        const arg = paren ? src.slice(src.indexOf('(', afterWord) + 1, argEnd) : '';
        const bodyStart = paren ? argEnd + 1 : afterWord;

        if (word === 'php') {
          const end = src.indexOf('@endphp', bodyStart);
          const code = src.slice(bodyStart, end < 0 ? src.length : end);
          this.runPhp(code, local);
          i = end < 0 ? src.length : end + 7;
          continue;
        }

        if (word === 'include') {
          const parts = splitTopLevel(arg, ',');
          const target = this.evalExpr(parts[0] ?? arg, local);
          let data = null;
          if (parts.length > 1) {
            const value = this.evalExpr(parts.slice(1).join(','), local);
            if (value && typeof value === 'object' && !(value instanceof Coll)) data = value;
            else if (value instanceof Coll) data = Object.fromEntries(value.items);
          }
          if (typeof target === 'string') {
            const scope = data ? Object.assign(Object.create(local), data) : local;
            try { out += this.renderFile(target, scope); } catch (err) { this.log.errors.push(`${file}: ${err.message}`); }
          }
          i = bodyStart;
          continue;
        }

        if (word === 'push' || word === 'section' || word === 'once') {
          const closer = word === 'push' ? '@endpush' : word === 'section' ? '@endsection' : '@endonce';
          const end = src.indexOf(closer, bodyStart);
          i = end < 0 ? src.length : end + closer.length;
          continue;
        }

        if (word === 'extends' || word === 'yield' || word === 'stack') {
          const semi = src.indexOf(')', bodyStart);
          i = semi < 0 ? bodyStart : semi + 1;
          continue;
        }

        if (word === 'if' || word === 'unless') {
          const closer = word === 'if' ? 'endif' : 'endunless';
          const { branches, end } = this.branches(src, bodyStart, closer);
          if (branches.length) branches[0].cond = arg;
          let chunk = null;
          for (const branch of branches) {
            if (branch.cond === null) { chunk = branch; break; }
            const cond = word === 'unless' ? `!(${branch.cond})` : branch.cond;
            if (this.bool(cond, local)) { chunk = branch; break; }
          }
          if (chunk) out += this.renderSource(chunk.body, local, file);
          i = end;
          continue;
        }

        if (word === 'foreach' || word === 'forelse') {
          const closer = word === 'foreach' ? 'endforeach' : 'endforelse';
          const { body, empty, end } = this.splitLoop(src, bodyStart, closer, word === 'forelse');
          const items = this.iterable(arg, local);
          if (!items.length) {
            out += empty ? this.renderSource(empty, local, file) : '';
          } else {
            items.forEach((entry, index) => {
              local.loop = {
                index: index + 1, iteration: index + 1, first: index === 0,
                last: index === items.length - 1, count: items.length,
                remaining: items.length - index - 1,
              };
              if (entry.bindings) entry.bindings.forEach(([name, value]) => { local[name] = value; });
              if (entry.keyName) local[entry.keyName] = entry.key;
              if (entry.valueName) local[entry.valueName] = entry.value;
              out += this.renderSource(body, local, file);
            });
            delete local.loop;
          }
          i = end;
          continue;
        }

        if (word === 'class') {
          out += this.classAttr(arg, local);
          i = bodyStart;
          continue;
        }

        if (word === 'error') {
          const close = src.indexOf('@enderror', bodyStart);
          const name = this.evalExpr(arg, local);
          if (this.hasError(name)) {
            out += this.renderSource(src.slice(bodyStart, close < 0 ? src.length : close), local, file);
          }
          i = close < 0 ? src.length : close + '@enderror'.length;
          continue;
        }

        if (word === 'checked' || word === 'selected' || word === 'disabled') {
          if (this.evalExpr(arg, local)) out += ` ${word}`;
          i = bodyStart;
          continue;
        }

        if (word === 'enderror' || word === 'csrf' || word === 'method') {
          i = bodyStart;
          continue;
        }

        // Unknown directive: drop it, keep going.
        this.log.unknownDirectives.push(`${word} @ ${src.slice(Math.max(0, i - 50), i + 30).replace(/\s+/g, ' ')}`);
        i = bodyStart;
        continue;
      }
    }

    this.currentScope = previous;
    return out;
  }

  /** Finds @elseif / @else / @endif boundaries for an @if block. */
  branches(src, start, closer) {
    const branches = [];
    let cursor = start;
    let cond = null;
    for (;;) {
      const stop = findStop(src, cursor, new Set(['elseif', 'else', closer]));
      if (!stop) { branches.push({ cond, body: src.slice(cursor) }); return { branches, end: src.length }; }
      branches.push({ cond, body: src.slice(cursor, stop.i) });
      if (stop.w === closer) return { branches, end: stop.i + closer.length + 1 };
      if (stop.w === 'else') { cursor = stop.i + 5; cond = null; continue; }
      const open = src.indexOf('(', stop.i);
      const close = matchParen(src, open);
      cond = src.slice(open + 1, close);
      cursor = close + 1;
    }
  }

  splitLoop(src, start, closer, hasEmpty) {
    const stops = hasEmpty ? new Set(['empty', closer]) : new Set([closer]);
    const stop = findStop(src, start, stops);
    if (!stop) return { body: src.slice(start), empty: null, end: src.length };
    if (stop.w === 'empty') {
      const tail = findStop(src, stop.i + 6, new Set([closer]));
      const emptyEnd = tail ? tail.i : src.length;
      return { body: src.slice(start, stop.i), empty: src.slice(stop.i + 6, emptyEnd), end: tail ? tail.i + closer.length + 1 : src.length };
    }
    return { body: src.slice(start, stop.i), empty: null, end: stop.i + closer.length + 1 };
  }

  iterable(arg, scope) {
    const parts = splitTopLevel(arg, ' as ');
    if (parts.length < 2) return [];
    const [sourceExpr, bindings] = parts;
    const raw = sourceExpr.trim();
    let value;
    if (/^\[/.test(raw) && raw.includes('=>')) {
      value = phpMapLiteral(raw);
    } else {
      value = this.evalExpr(raw, scope);
    }
    let entries = [];
    if (value instanceof Coll) entries = value.items;
    else if (Array.isArray(value)) entries = value;
    else if (value && typeof value === 'object') entries = Object.entries(value);
    else return [];

    const bindingText = bindings.trim();
    const list = /^\[([\s\S]+)\]$/.exec(bindingText);
    if (list) {
      const names = splitTopLevel(list[1], ',').map(part => part.trim().replace(/^\$/, ''));
      return entries.map(entry => ({
        bindings: names.map((name, index) => [name, Array.isArray(entry) ? entry[index] : entry?.[name]]),
      }));
    }
    const kv = /^\$(\w+)\s*=>\s*\$(\w+)$/.exec(bindingText);
    const single = /^\$(\w+)$/.exec(bindingText);
    if (kv) return entries.map(([k, v] = []) => Array.isArray(entries[0]) ? { keyName: kv[1], key: k, valueName: kv[2], value: v } : { keyName: kv[1], key: k, valueName: kv[2], value: v });
    if (!single) return [];
    return entries.map(v => ({ valueName: single[1], value: v }));
  }
}

/** <style> and <script> bodies are copied verbatim: their @ rules are not Blade. */
function splitVerbatim(src) {
  const parts = [];
  let i = 0;
  while (i < src.length) {
    const style = src.indexOf('<style', i);
    const script = src.indexOf('<script', i);
    const starts = [style, script].filter(position => position >= 0);
    if (!starts.length) {
      parts.push({ raw: false, text: src.slice(i) });
      break;
    }
    const start = Math.min(...starts);
    const closer = start === style ? '</style>' : '</script>';
    const end = src.indexOf(closer, start);
    if (end < 0) {
      parts.push({ raw: false, text: src.slice(i) });
      break;
    }
    if (start > i) parts.push({ raw: false, text: src.slice(i, start) });
    parts.push({ raw: true, text: src.slice(start, end + closer.length) });
    i = end + closer.length;
  }
  return parts;
}

/* ── scanning helpers ─────────────────────────────────────────────────────── */

const NEXT_RE = /\{\{--|\{\{|\{!!|@([a-zA-Z]+)\s*\(|@([a-zA-Z]+)\b|<x-[\w-]+/g;

function findNext(src, from) {
  NEXT_RE.lastIndex = from;
  const m = NEXT_RE.exec(src);
  if (!m) return null;
  const start = m.index;
  if (src.startsWith('{{--', start)) return { type: 'comment', start };
  if (src.startsWith('{{', start)) return { type: 'echo', start };
  if (src.startsWith('{!!', start)) return { type: 'raw', start };
  if (src.startsWith('<x-', start)) return { type: 'component', start };
  const word = m[1] ?? m[2];
  return { type: 'directive', start, word };
}

function findStop(src, from, stops) {
  const re = /@([a-zA-Z]+)/g;
  re.lastIndex = from;
  let depth = 0;
  let m;
  while ((m = re.exec(src))) {
    const w = m[1];
    if (depth === 0 && stops.has(w)) return { i: m.index, w };
    if (CLOSE.has(w)) {
      if (depth === 0 && stops.has(w)) return { i: m.index, w };
      depth = Math.max(0, depth - 1);
      continue;
    }
    if (OPEN.has(w)) {
      if (depth === 0 && stops.has(w)) return { i: m.index, w };
      depth++;
    }
  }
  return null;
}

function matchParen(src, open) {
  if (open < 0) return -1;
  let depth = 0;
  let quote = null;
  for (let j = open; j < src.length; j++) {
    const ch = src[j];
    if (quote) {
      if (ch === '\\') { j++; continue; }
      if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"') { quote = ch; continue; }
    if (ch === '(') depth++;
    else if (ch === ')') { depth--; if (depth === 0) return j; }
  }
  return -1;
}

function findTagEnd(src, start) {
  let quote = null;
  for (let j = start; j < src.length; j++) {
    const ch = src[j];
    if (quote) { if (ch === quote) quote = null; continue; }
    if (ch === '"' || ch === "'") { quote = ch; continue; }
    if (ch === '>') return j;
  }
  return -1;
}

/** Splits on a separator at bracket/quote depth 0. */
export function splitTopLevel(src, sep) {
  const out = [];
  let last = 0;
  let depth = 0;
  let quote = null;
  for (let j = 0; j < src.length; j++) {
    const ch = src[j];
    if (quote) {
      if (ch === '\\') { j++; continue; }
      if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"') { quote = ch; continue; }
    if ('([{'.includes(ch)) depth++;
    else if (')]}'.includes(ch)) depth--;
    if (depth !== 0) continue;
    if (src.startsWith(sep, j)) {
      out.push(src.slice(last, j));
      last = j + sep.length;
      j = last - 1;
    }
  }
  out.push(src.slice(last));
  return out;
}

/** ['a' => 1, 'b' => 2] → [['a',1], ['b',2]] */
function phpMapLiteral(raw) {
  const inner = raw.trim().replace(/^\[/, '').replace(/\]$/, '');
  return splitTopLevel(inner, ',').map(part => {
    const bits = splitTopLevel(part, '=>');
    if (bits.length < 2) return [null, unquote(bits[0]?.trim())];
    return [unquote(bits[0].trim()), unquote(bits[1].trim())];
  }).filter(([k]) => k !== null);
}

function unquote(v) {
  if (v === undefined) return v;
  const m = /^'([\s\S]*)'$/.exec(v) ?? /^"([\s\S]*)"$/.exec(v);
  if (m) return m[1].replace(/\\(['"])/g, '$1');
  if (/^-?\d+(\.\d+)?$/.test(v)) return Number(v);
  if (v === 'true') return true;
  if (v === 'false') return false;
  return v;
}

/* ── PHP-ish expression → JavaScript ──────────────────────────────────────── */

function concatFix(src) {
  let out = '';
  let quote = null;
  for (let j = 0; j < src.length; j++) {
    const ch = src[j];
    if (quote) {
      out += ch;
      if (ch === '\\') { out += src[j + 1] ?? ''; j++; continue; }
      if (ch === quote) quote = null;
      continue;
    }
    if (ch === "'" || ch === '"') { quote = ch; out += ch; continue; }
    if (ch === '.') {
      const prev = out.replace(/\s+$/, '').slice(-1);
      const next = src.slice(j + 1).replace(/^\s+/, '').charAt(0);
      const decimal = /\d/.test(prev) && /\d/.test(next);
      const member = prev === '-' || prev === '?' || next === '>';
      out += decimal || member ? '.' : ' + ';
      continue;
    }
    out += ch;
  }
  return out;
}

function convertArrows(src) {
  let out = src;
  for (;;) {
    const at = out.search(/\bfn\s*\(/);
    if (at < 0) return out;
    const paramOpen = out.indexOf('(', at);
    const paramClose = matchBracket(out, paramOpen, '(', ')');
    if (paramClose < 0) return out;
    const arrow = out.indexOf('=>', paramClose);
    if (arrow < 0) return out;
    const param = out.slice(paramOpen + 1, paramClose).replace(/\$/g, '').trim();
    let depth = 0;
    let quote = null;
    let end = out.length;
    for (let j = arrow + 2; j < out.length; j++) {
      const ch = out[j];
      if (quote) { if (ch === '\\') { j++; continue; } if (ch === quote) quote = null; continue; }
      if (ch === "'" || ch === '"') { quote = ch; continue; }
      if ('([{'.includes(ch)) depth++;
      else if (')]}'.includes(ch)) { if (depth === 0) { end = j; break; } depth--; }
      else if (depth === 0 && ch === ',') { end = j; break; }
    }
    const body = out.slice(arrow + 2, end).trim();
    out = `${out.slice(0, at)}((${param}) => ${body})${out.slice(end)}`;
  }
}

/** Applies a rewrite to code only, never to the inside of a quoted string. */
function replaceOutsideQuotes(src, regex, repl) {
  const apply = (chunk) => chunk.replace(regex, repl);
  let out = '';
  let plainFrom = 0;
  let i = 0;
  while (i < src.length) {
    const ch = src[i];
    if (ch !== "'" && ch !== '"') { i++; continue; }
    out += apply(src.slice(plainFrom, i));
    let j = i + 1;
    while (j < src.length) {
      if (src[j] === '\\') { j += 2; continue; }
      if (src[j] === ch) break;
      j++;
    }
    out += src.slice(i, j + 1);
    i = plainFrom = j + 1;
  }
  return out + apply(src.slice(plainFrom));
}

export function toJs(expr) {
  let s = String(expr);
  s = s.replace(/\bclone\s+/g, '');
  // PHP shorthand ternary  a ?: b  →  a || b
  s = s.replace(/\?:/g, '||');
  s = s.replace(/\/\*[\s\S]*?\*\//g, ' ');
  // string concatenation: 'a' . $b  /  $a . 'b' (never inside a literal)
  s = concatFix(s);
  // static calls keep their arguments
  s = s.replace(/(\\?[A-Za-z_\u00c0-\uffff][\w\\]*)::(\w+)\s*\(/g,
    (m, cls, method) => `__static(${JSON.stringify(cls.replace(/^\\/, ''))}, ${JSON.stringify(method)}, `);
  s = convertArrows(s);
  // PHP casts are no-ops here: the sample already holds the right types.
  s = s.replace(/\((?:float|int|string|array|bool|object)\)\s*/g, '');
  s = s.replace(/(\\?[A-Za-z_\u00c0-\uffff][\w\\]*)::([A-Z_][A-Z0-9_]*)\b(?!\s*\()/g,
    (m, cls, name) => `__const(${JSON.stringify(cls.replace(/^\\/, ''))}, ${JSON.stringify(name)})`);
  // $row->{$name} → $row[$name], before the arrow becomes a dot
  s = s.replace(/\?->\s*\{\s*\$?([\w\[\]'"\.]+?)\s*\}/g, '?.[$1]');
  s = s.replace(/->\s*\{\s*\$?([\w\[\]'"\.]+?)\s*\}/g, '[$1]');
  s = s.replace(/\?->/g, '?.').replace(/->/g, '.');
  s = s.replace(/\bnull\b/g, 'null').replace(/\btrue\b/g, 'true').replace(/\bfalse\b/g, 'false');
  s = replaceOutsideQuotes(s, /\band\b/g, '&&');
  s = replaceOutsideQuotes(s, /\bor\b/g, '||');
  s = s.replace(/\$([A-Za-z_]\w*)/g, '$1');
  // PHP array literals: ['a' => 1] reads as an object, [1, 2] stays an array.
  return convertKeyedArrays(s);
}

function matchBracket(src, open, openChar = '[', closeChar = ']') {
  let depth = 0;
  let quote = null;
  for (let j = open; j < src.length; j++) {
    const ch = src[j];
    if (quote) { if (ch === '\\') { j++; continue; } if (ch === quote) quote = null; continue; }
    if (ch === "'" || ch === '"') { quote = ch; continue; }
    if (ch === openChar) depth++;
    else if (ch === closeChar) { depth--; if (depth === 0) return j; }
  }
  return -1;
}

export function convertKeyedArrays(src) {
  let out = '';
  for (let i = 0; i < src.length; i++) {
    const ch = src[i];
    if (ch === "'" || ch === '"') {
      const end = src.indexOf(ch, i + 1);
      const stop = end < 0 ? src.length - 1 : end;
      out += src.slice(i, stop + 1);
      i = stop;
      continue;
    }
    if (ch !== '[') { out += ch; continue; }
    const close = matchBracket(src, i);
    if (close < 0) { out += ch; continue; }
    const inner = src.slice(i + 1, close);
    const parts = splitTopLevel(inner, '=>');
    if (parts.length > 1) {
      const pairs = splitTopLevel(inner, ',').filter(p => p.trim()).map(pair => {
        const bits = splitTopLevel(pair, '=>');
        const rawKey = (bits[0] ?? '').trim();
        const key = /^['"]/.test(rawKey) ? rawKey : JSON.stringify(rawKey);
        return `${key}: ${convertKeyedArrays((bits[1] ?? '').trim())}`;
      });
      out += `{${pairs.join(', ')}}`;
    } else {
      out += `[${convertKeyedArrays(inner)}]`;
    }
    i = close;
  }
  return out;
}
