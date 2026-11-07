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

// @continue / @break inside a loop body end the iteration by unwinding the render;
// the partial output of that iteration is dropped with it, which is what the directives mean.
const LOOP_CONTINUE = { loopContinue: true, loopBreak: false };
const LOOP_BREAK = { loopContinue: false, loopBreak: true };

const OPEN = new Set(['if', 'unless', 'foreach', 'forelse', 'php', 'push', 'section', 'once', 'for', 'while', 'switch', 'auth', 'guest', 'isset', 'can', 'cannot']);
const CLOSE = new Set(['endif', 'endunless', 'endforeach', 'endforelse', 'endphp', 'endpush', 'endsection', 'endonce', 'endfor', 'endwhile', 'endswitch', 'endauth', 'endguest', 'endisset', 'endempty', 'endcan', 'endcannot']);

// {{ }} escapes everything except an HtmlString, which Laravel prints as it is — `$bills->links()` is one, so
// escaping it would put the pager on the screen as text.
export const raw = (html) => ({ toHtml: () => String(html ?? '') });
export const esc = (s) => {
  if (s && typeof s === 'object' && typeof s.toHtml === 'function') return s.toHtml();
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
};

/* ── collections & dates the views use ─────────────────────────────────────── */

/**
 * PHP's truthiness, not JavaScript's.
 *
 * An empty array is false in PHP and true in JS, so `@if($drawn)` — a list of payment modes that close below zero — took
 * the offending branch on a page where nothing was wrong, and printed a red warning over an ordinary day. `'0'` is false
 * in PHP too. Every @if/@elseif/@unless goes through this.
 */
const phpTrue = (value) => {
  if (value instanceof Coll) return value.count() > 0;
  if (Array.isArray(value)) return value.length > 0;
  if (value && typeof value === 'object') return Object.keys(value).length > 0;
  if (typeof value === 'string') return value !== '' && value !== '0';
  return Boolean(value);
};

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
  avg(key) {
    if (!this.items.length) return 0;
    return this.sum(key) / this.items.length;
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
  /** Ascending / descending ordering by a key or a picker, as Laravel's collections do. */
  sortBy(key) {
    const value = (item) => (typeof key === 'function' ? key(item) : (key === undefined ? item : item?.[key]));

    return new Coll(this.items.slice().sort((a, b) => {
      const left = value(a); const right = value(b);

      return left === right ? 0 : (left > right ? 1 : -1);
    }));
  }
  sortByDesc(key) {
    return new Coll(this.sortBy(key).items.slice().reverse());
  }
  first() { return this.items[0]; }
  last() { return this.items[this.items.length - 1]; }
  push(...v) { this.items.push(...v); return this; }
  map(fn) { return new Coll(this.items.map((v, i) => fn(v, i))); }
  filter(fn) { return new Coll(this.items.filter((v, i) => (typeof fn === 'function' ? fn(v, i) : Boolean(v)))); }
  flatMap(fn) {
    const out = [];
    this.items.forEach((value, index) => {
      const mapped = fn(value, index);
      if (mapped instanceof Coll) out.push(...mapped.items);
      else if (Array.isArray(mapped)) out.push(...mapped);
      else if (mapped && typeof mapped === 'object') out.push(...Object.values(mapped));
      else out.push(mapped);
    });
    return new Coll(out);
  }
  reject(fn) { return new Coll(this.items.filter((v, i) => !fn(v, i))); }
  contains(key, value) {
    if (key === undefined) return this.items.length > 0;
    if (value === undefined) return this.items.includes(key);
    return this.items.some(i => i?.[key] === value);
  }
  // mapWithKeys(fn ($v, $k) => [$k => $v]) — the views build select options this way.
  mapWithKeys(callback) {
    const out = {};
    this.items.forEach((item, index) => {
      const pair = callback(item, index);
      if (pair && typeof pair === 'object') Object.assign(out, pair);
    });
    return new Coll(out);
  }
  firstWhere(key, value) { return this.items.find(i => i?.[key] === value); }
  groupBy(key) {
    const groups = {};
    this.items.forEach((item, index) => {
      const name = typeof key === 'function' ? key(item, index) : item?.[key];
      (groups[name] ??= []).push(item);
    });
    Object.keys(groups).forEach(name => { groups[name] = new Coll(groups[name]); });
    return groups;
  }
  /**
   * countBy('col') / countBy(fn ($x) => …) — Laravel's tally, keyed the way PHP keys it. The views read it
   * back with ->get($key, 0), ->isNotEmpty() and ->map(fn ($count, $key) => …), so the tally answers to those.
   */
  countBy(key) {
    const tally = {};
    this.items.forEach((item, index) => {
      const value = typeof key === 'function' ? key(item, index) : (key === undefined ? item : item?.[key]);
      const name = value == null ? '' : String(value);
      tally[name] = (tally[name] ?? 0) + 1;
    });
    return new CountMap(tally);
  }
  /** where('col', value) or where('col', '!=', value), as the views write both. */
  where(key, operator, value) {
    const hasOperator = value !== undefined;
    const test = hasOperator ? operator : '=';
    const wanted = hasOperator ? value : operator;
    return new Coll(this.items.filter(i => {
      const got = typeof key === 'function' ? key(i) : i?.[key];
      switch (test) {
        case '!=': case '<>': return got !== wanted;
        case '>': return got > wanted;
        case '<': return got < wanted;
        case '>=': return got >= wanted;
        case '<=': return got <= wanted;
        case 'like': return String(got ?? '').toLowerCase().includes(String(wanted ?? '').replace(/%/g, '').toLowerCase());
        default: return got === wanted;
      }
    }));
  }
  whereIn(key, list) { return new Coll(this.items.filter(i => (list?.items ?? list ?? []).includes(i?.[key]))); }
  pluck(key) {
    return new Coll(this.items.map(i => String(key).split('.').reduce((o, k) => (o == null ? o : o[k]), i)));
  }
  unique() { return new Coll([...new Set(this.items)]); }
  join(sep = ', ') { return this.items.join(sep); }
  implode(sep = '') { return this.items.map(i => (i == null ? '' : String(i))).join(sep); }
  // a @php block is evaluated for its assignments when it can be, and the workspace builds its source filter with
  // merge() + sort(); without them the block silently produced nothing and the filter lost every channel source
  merge(values) {
    const extra = values instanceof Coll ? values.items : (Array.isArray(values) ? values : Object.values(values ?? {}));
    return new Coll([...this.items, ...extra]);
  }
  sort(compare) { return new Coll(this.items.slice().sort(compare ?? ((a, b) => (a > b ? 1 : a < b ? -1 : 0)))); }
  reverse() { return new Coll(this.items.slice().reverse()); }
  toArray() { return this.items; }
  toJson() { return JSON.stringify(this.items); }
}

/**
 * PHP's array functions, as the views write them.
 *
 * A view that sums a column — `array_sum(array_column($rows, 'revenue'))` — rendered an
 * empty cell in every preview until these existed, because the expression threw and the
 * renderer fell back to a blank rather than to a wrong number. They are merged under each
 * sample's own helpers, so a sample may still override one.
 */
const phpArray = (values) => {
  if (values instanceof Coll) return values.items.slice();
  if (Array.isArray(values)) return values.slice();
  if (values && typeof values === 'object') return Object.values(values);
  return values == null ? [] : [values];
};

/** PHP's empty(): false, 0, 0.0, '0', '', null, undefined, an empty array and an empty countable count as empty.
 *  It lives here and nowhere else because fifteen sample contexts each used to define their own weaker copy
 *  (`undefined || null || ''`) which reported `false` as *not* empty — so `@if(!empty($x['active']))` marked every
 *  chip on a report as the chosen one, and the preview showed eleven selected slices at once. */
export function phpEmpty(value) {
  if (value instanceof Coll) return value.count() === 0;
  if (Array.isArray(value)) return value.length === 0;
  if (value && typeof value === 'object') return Object.keys(value).length === 0;
  if (typeof value === 'number') return value === 0;
  if (typeof value === 'boolean') return value === false;
  return value === undefined || value === null || value === '' || value === '0';
}

export const BASE_HELPERS = {
  array_column: (rows, key) => phpArray(rows).map(row => (row == null ? null : row[key])),
  array_filter: (values, predicate) => {
    const items = phpArray(values);
    if (typeof predicate !== 'function') return items.filter(Boolean);
    return items.filter((value, index) => predicate(value, index));
  },
  array_values: values => phpArray(values),
  array_keys: values => Object.keys(values instanceof Coll ? values.items : (values ?? {})),
  array_sum: values => phpArray(values).reduce((total, value) => total + Number(value ?? 0), 0),
  implode: (glue, values) => phpArray(values).map(value => String(value ?? '')).join(String(glue ?? '')),
  join: (glue, values) => phpArray(values).map(value => String(value ?? '')).join(String(glue ?? '')),
  array_slice: (values, offset, length) => phpArray(values).slice(offset, length),
  array_map: (callback, values) => phpArray(values).map((value, index) => callback(value, index)),
  array_key_exists: (key, values) => values != null && Object.prototype.hasOwnProperty.call(values instanceof Coll ? values.items : values, key),
  // http_build_query() builds the tab and filter links on every list screen; without it each tab rendered href="?"
  http_build_query: (values, prefix = '', separator = '&') => {
    const items = values instanceof Coll ? Object.fromEntries(values.items.map((v, i) => [i, v])) : (values ?? {});
    const parts = [];
    const walk = (prefixKey, value) => {
      if (value === null || value === undefined) return;
      if (typeof value === 'object' && !(value instanceof Date)) {
        for (const [k, v] of Object.entries(value instanceof Coll ? Object.fromEntries(value.items.map((x, i) => [i, x])) : value)) {
          walk(prefixKey ? `${prefixKey}[${k}]` : k, v);
        }
        return;
      }
      parts.push(`${encodeURIComponent(prefixKey)}=${encodeURIComponent(typeof value === 'boolean' ? (value ? 1 : 0) : String(value))}`);
    };
    for (const [k, v] of Object.entries(items)) walk(prefix ? `${prefix}${k}` : k, v);
    return parts.join(separator);
  },
  // in_array() is the other builtin a view reaches for in a @php block; without it the whole assignment threw and the
  // value it was building rendered as nothing — the AP inbox lost every "N days overdue" marker that way
  in_array: (needle, haystack, strict = false) => {
    const items = haystack instanceof Coll ? haystack.items : (Array.isArray(haystack) ? haystack : Object.values(haystack ?? {}));
    // eslint-disable-next-line eqeqeq
    return items.some(value => (strict ? value === needle : value == needle));
  },
  // array_merge() overwrites a string key and appends a numeric one — the tabs on a list screen are built with it, and
  // without it every tab link on every paginated screen rendered as href=""
  array_merge: (...sources) => {
    const out = {};
    let next = 0;
    for (const source of sources) {
      const items = source instanceof Coll ? source.items : (Array.isArray(source) ? source : Object.entries(source ?? {}));
      const pairs = Array.isArray(source) || source instanceof Coll ? items.map((value, index) => [index, value]) : items;
      for (const [key, value] of pairs) {
        if (typeof key === 'number' || /^\d+$/.test(String(key))) {
          out[next++] = value;
        } else {
          out[key] = value;
        }
      }
    }
    return out;
  },
  array_reverse: values => phpArray(values).reverse(),
  // string casing is a PHP builtin, not a sample's business: a view that prints strtolower($label) rendered a blank
  // wherever a sample had not defined it by hand
  // PHP's empty() is a language construct, but a view writes it as a call; without it the renderer defaulted the
  // condition and printed the block anyway, which is how an empty cell reaches a preview looking like a real one
  empty: phpEmpty,
  // count() is a PHP builtin a view calls as a function. It was missing, so every `@if(count($x))` guard evaluated to
  // undefined — falsy — and the block behind it silently vanished from the preview instead of rendering. That is the
  // worst kind of preview bug: the screen looked deliberate.
  count: value => {
    if (value instanceof Coll) return value.count();
    if (Array.isArray(value)) return value.length;
    if (typeof value === 'string') return value.length;
    if (value && typeof value === 'object') return Object.keys(value).length;
    return 0;
  },
  // abs() and round() are PHP builtins the accounts screens lean on to turn a signed difference into a figure worth
  // printing; without them the renderer defaulted the condition around them and printed "GH₵ 0.00" over a real gap
  abs: value => Math.abs(Number(value ?? 0)),
  round: (value, places = 0) => {
    const factor = 10 ** Number(places);
    return Math.round(Number(value ?? 0) * factor) / factor;
  },
  strtolower: value => String(value ?? '').toLowerCase(),
  strtoupper: value => String(value ?? '').toUpperCase(),
  ucfirst: value => { const text = String(value ?? ''); return text.charAt(0).toUpperCase() + text.slice(1); },
  range: (from, to) => {
    const out = [];
    if (typeof from === 'string') {
      for (let i = from.charCodeAt(0); i <= to.charCodeAt(0); i++) out.push(String.fromCharCode(i));
      return out;
    }
    const step = from <= to ? 1 : -1;
    for (let i = Number(from); step > 0 ? i <= Number(to) : i >= Number(to); i += step) out.push(i);
    return out;
  },
};

// ── the preview clock ──────────────────────────────────────────────────────────────
// Previews are rendered against a fixed instant, never the wall clock. "8 hours ago"
// becomes "9 hours ago" while the hour turns, which makes a built preview differ from
// the same view rendered a minute later and turns `--check` into a coin toss. A sample
// that needs a different instant moves the clock with setPreviewNow().
export const PREVIEW_NOW = new Date('2026-11-05T09:20:00Z');
let previewNow = PREVIEW_NOW;
export function setPreviewNow(value) {
  previewNow = value instanceof Date ? value : new Date(value);
  return previewNow;
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
  startOfDay() {
    const d = new Date(this.d);
    d.setUTCHours(0, 0, 0, 0);
    return new SampleDate(d, this.ago);
  }
  startOfMonth() {
    const d = new Date(this.d);
    d.setUTCDate(1);
    return new SampleDate(d, this.ago);
  }
  endOfMonth() {
    const d = new Date(this.d);
    d.setUTCMonth(d.getUTCMonth() + 1, 0);
    return new SampleDate(d, this.ago);
  }
  startOfYear() {
    const d = new Date(this.d);
    d.setUTCMonth(0, 1);
    return new SampleDate(d, this.ago);
  }
  endOfYear() {
    const d = new Date(this.d);
    d.setUTCMonth(11, 31);
    return new SampleDate(d, this.ago);
  }
  get year() { return this.d.getUTCFullYear(); }
  toIso8601String() { return this.d.toISOString().replace('.000Z', '+00:00'); }
  max(other) { return +this.d >= +(other?.d ?? new Date(other)) ? this : other; }
  min(other) { return +this.d <= +(other?.d ?? new Date(other)) ? this : other; }
  // Carbon returns an absolute number of days unless you ask for the sign with a
  // second argument (`diffInDays($x, false)`), which is how the views use it.
  diffInDays(other, absolute = true) {
    const target = other instanceof SampleDate ? other.d : new Date(`${other}T00:00:00Z`);
    const value = Math.round((+this.d - +target) / 86400000);
    return absolute === false ? value : Math.abs(value);
  }
  get daysInMonth() {
    return new Date(Date.UTC(this.d.getUTCFullYear(), this.d.getUTCMonth() + 1, 0)).getUTCDate();
  }
  isSameDay(other) { return this.toDateString() === (other?.toDateString?.() ?? String(other)); }
  equalTo(other) { return this.isSameDay(other); }
  isWeekend() { const d = this.d.getUTCDay(); return d === 0 || d === 6; }
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
  /** Carbon's `->dayOfWeek`, which the views compare against RateService::WEEKEND. */
  get dayOfWeek() { return this.d.getUTCDay(); }
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
    const diff = Math.round((+previewNow - +this.d) / 60000);
    const ahead = diff < 0; // Carbon says "in 3 hours", not "-3 hours ago"
    const mins = Math.max(1, Math.abs(diff));
    const say = (text) => (ahead ? `in ${text}` : `${text} ago`);
    if (mins < 60) return say(`${mins} minutes`);
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return say(`${hrs} hour${hrs === 1 ? '' : 's'}`);
    const days = Math.round(hrs / 24);
    return say(`${days} day${days === 1 ? '' : 's'}`);
  }
}

/** The tally Coll.countBy() returns: keyed like a PHP array, read like a Laravel collection. */
export class CountMap {
  constructor(tally) { this.tally = tally; }
  get(key, fallback = null) {
    const name = String(key);
    return Object.prototype.hasOwnProperty.call(this.tally, name) ? this.tally[name] : fallback;
  }
  keys() { return new Coll(Object.keys(this.tally)); }
  values() { return new Coll(Object.values(this.tally)); }
  all() { return { ...this.tally }; }
  count() { return Object.keys(this.tally).length; }
  isEmpty() { return Object.keys(this.tally).length === 0; }
  isNotEmpty() { return Object.keys(this.tally).length > 0; }
  sum() { return Object.values(this.tally).reduce((total, value) => total + Number(value ?? 0), 0); }
  map(fn) { return new Coll(Object.entries(this.tally).map(([k, v], i) => fn(v, k, i))); }
  sortByDesc() { return this; }
  toArray() { return { ...this.tally }; }
  toJson() { return JSON.stringify(this.tally); }
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
    // The PHP array functions every view expects are always in scope; a sample's own
    // helpers win where the two share a name.
    this.ctx = { ...ctx, helpers: { ...BASE_HELPERS, ...(ctx.helpers ?? {}) } };
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
        // `__helpers` is how a helper is reached when a local variable of the same name
        // shadows it — PHP keeps `$max` (a variable) and `max()` (a function) apart.
        if (k === '__helpers') return self.ctx.helpers;
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
  evalExpr(expr, scope, options = {}) {
    const raw = String(expr).trim();
    try {
      let js = toJs(raw);
      // A helper called as a function wins over a variable of the same name: the views
      // declare `$max = max(1, …)`, and PHP would still read `max()` as the function.
      const helperNames = Object.keys(this.ctx.helpers)
        .filter(name => /^[A-Za-z_]\w*$/.test(name) && typeof this.ctx.helpers[name] === 'function');
      if (helperNames.length) {
        const pattern = new RegExp(`(?<![.\\w$])(${helperNames.join('|')})\\s*\\(`, 'g');
        js = js.replace(pattern, '__helpers.$1(');
      }
      // eslint-disable-next-line no-new-func
      const fn = new Function('__scope', `with (__scope) { return (${js}); }`);
      const value = fn(this.scopeProxy(scope));
      this.log.expressions++;
      return value;
    } catch (err) {
      if (!options.quiet) this.log.failed.push({ expr: raw, reason: err.message });
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
    return phpTrue(v);
  }

  /** Runs a @php block: simple assignments only; anything structural is skipped. */
  runPhp(code, scope) {
    code = String(code).replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/\/\/[^\n]*/g, ' ');
    // A `@php` block in this application often builds a value with a `foreach` — the pricing
    // grid assembles each cell's tooltip that way — so a loop over a sample collection is run
    // rather than skipped. `while`, `switch` and declarations stay beyond a sample.
    for (const statement of this.phpStatements(code)) {
      const text = statement.trim();
      if (!text) continue;
      const loop = /^foreach\s*\(([\s\S]*)\)\s*\{([\s\S]*)\}$/.exec(text);
      if (loop) { this.runPhpForeach(loop[1], loop[2], scope); continue; }
      if (/\b(while|switch|function|class)\s*[({]/.test(text)) {
        this.log.phpSkipped.push(text.slice(0, 90));
        continue;
      }
      this.phpStatement(text, scope);
    }
  }

  /** `foreach ($rows as $r)` and `foreach ($rows as $k => $v)` inside a @php block. */
  runPhpForeach(head, body, scope) {
    const parts = splitTopLevel(head, ' as ');
    if (parts.length < 2) { this.log.phpSkipped.push(`foreach (${head})`); return; }
    const values = this.evalExpr(parts[0], scope, { quiet: true });
    let entries = phpArray(values);
    const binding = parts[1].trim();
    const kv = /^\$(\w+)\s*=>\s*\$(\w+)$/.exec(binding);
    const single = /^\$(\w+)$/.exec(binding);
    if (kv) entries = entries.every(entry => Array.isArray(entry) && entry.length === 2)
      ? entries : entries.map((value, index) => [index, value]);
    for (const [index, entry] of entries.entries()) {
      if (kv) { scope[kv[1]] = entry[0]; scope[kv[2]] = entry[1]; }
      else if (single) scope[single[1]] = entry;
      else { this.log.phpSkipped.push(`foreach (${head})`); return; }
      this.runPhp(body, scope);
    }
  }

  /** Split PHP into statements, keeping a braced `if (...) { ... }` as one unit. */
  phpStatements(code) {
    const out = [];
    let buf = '', depth = 0, quote = null;
    for (let i = 0; i < code.length; i++) {
      const ch = code[i];
      if (quote) {
        buf += ch;
        if (ch === '\\') { buf += code[++i] ?? ''; continue; }
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") { quote = ch; buf += ch; continue; }
      if (ch === '{') { depth += 1; buf += ch; continue; }
      if (ch === '}') {
        depth = Math.max(0, depth - 1);
        buf += ch;
        if (depth === 0) { out.push(buf.trim()); buf = ''; }
        continue;
      }
      if (ch === ';' && depth === 0) { out.push(buf.trim()); buf = ''; continue; }
      buf += ch;
    }
    if (buf.trim()) out.push(buf.trim());
    return out.filter(Boolean);
  }

  /** Index of the bracket that closes the one at `start`, or -1. */
  matchBrace(src, start, open, close) {
    let depth = 0, quote = null;
    for (let i = start; i < src.length; i++) {
      const ch = src[i];
      if (quote) {
        if (ch === '\\') { i += 1; continue; }
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === '"' || ch === "'") { quote = ch; continue; }
      if (ch === open) depth += 1;
      else if (ch === close) { depth -= 1; if (!depth) return i; }
    }
    return -1;
  }

  phpStatement(statement, scope) {
    let text = String(statement).trim().replace(/^\}+/, '').trim();
    if (!text) return;

    // if (...) { ... } [else { ... }] / one-line if (...) $x = ...;
    if (/^if\s*\(/.test(text)) {
      const open = text.indexOf('(');
      const close = this.matchBrace(text, open, '(', ')');
      if (close < 0) return;
      const cond = text.slice(open + 1, close);
      const rest = text.slice(close + 1).trim();
      let passes = false;
      try { passes = Boolean(this.evalExpr(cond, scope, { quiet: true })); } catch { passes = false; }
      if (!rest.startsWith('{')) {
        // `if ($x) $row = …;` assigns; `if ($x) $rows->push(…)` calls. The second is how the dashboard's
        // source mix adds its "No source" and "Other sources" slices, so a bare call is run, not skipped.
        if (passes) {
          if (/^\$[A-Za-z_]\w*\s*=(?![=>])/.test(rest)) this.phpStatement(rest, scope);
          else this.evalExpr(rest, scope, { quiet: true });
        }
        return;
      }
      const end = this.matchBrace(rest, 0, '{', '}');
      if (end < 0) return;
      const body = rest.slice(1, end);
      const tail = rest.slice(end + 1).trim();
      if (passes) this.runPhp(body, scope);
      else if (/^else\s*\{/.test(tail)) {
        const altOpen = tail.indexOf('{');
        const altEnd = this.matchBrace(tail, altOpen, '{', '}');
        if (altEnd > altOpen) this.runPhp(tail.slice(altOpen + 1, altEnd), scope);
      } else if (tail) {
        this.log.phpSkipped.push(text.trim().slice(0, 90));
      }
      return;
    }

    if (/^\s*(elseif|else|foreach|while|switch|case|default|\}|\{)/.test(text)) return;

    const list = /^\s*\[\s*((?:\$\w+\s*,?\s*)+)\]\s*=\s*([\s\S]+)$/.exec(text);
    if (list) {
      const value = this.evalExpr(list[2], scope);
      const names = list[1].split(',').map(n => n.trim().replace(/^\$/, '')).filter(Boolean);
      if (Array.isArray(value) || (value instanceof Coll)) {
        const items = value instanceof Coll ? value.items.map(([, v]) => v) : value;
        names.forEach((name, index) => { scope[name] = items[index]; });
      }
      return;
    }
    // $options['__other__'] = 'Others'; — a keyed assignment, used to extend a picker.
    const keyed = /^\$([A-Za-z_]\w*)\s*\[\s*(['"])(.*?)\2\s*\]\s*=\s*([\s\S]+)$/.exec(text);
    if (keyed) {
      const [, name, , key, valueExpr] = keyed;
      if (!scope[name] || typeof scope[name] !== 'object') scope[name] = {};
      const value = this.evalExpr(valueExpr, scope, { quiet: true });
      if (value !== undefined) scope[name][key] = value;
      return;
    }
    // `$tip .= ' …'` builds a string across statements; the pricing grid writes each cell's
    // tooltip that way, so a concatenating assignment is applied rather than dropped.
    const append = /^\$([A-Za-z_]\w*)\s*\.=\s*([\s\S]+)$/.exec(text);
    if (append) {
      const value = this.evalExpr(append[2], scope, { quiet: true });
      if (value !== undefined) scope[append[1]] = `${scope[append[1]] ?? ''}${value ?? ''}`;
      return;
    }
    const m = /^\$?([A-Za-z_]\w*)\s*=\s*([\s\S]+)$/.exec(text);
    if (!m) return;
    // A closure is often still evaluable through the mocked model layer, so try it;
    // only when it comes back empty is the statement set aside as unrenderable.
    const closure = /\bfn\s*\(|\bfunction\s*\(/.test(m[2]);
    const value = closure ? this.evalExpr(m[2], scope, { quiet: true }) : this.evalExpr(m[2], scope);
    if (value !== undefined) scope[m[1]] = value;
    else if (closure) this.log.phpSkipped.push(text.trim().slice(0, 90));
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
    // Valueless attributes (`<x-field … required/>`) carry meaning too.
    const bare = inner.slice(1, -1)
      .replace(/[:@]?[\w-]+\s*=\s*(?:"[^"]*"|'[^']*')/g, ' ')
      .replace(new RegExp(`^\\s*x-${tag}\\b`), ' ');
    for (const match of bare.matchAll(/(?:^|\s)([\w-]+)(?=\s|\/?\s*$)/g)) {
      if (match[1] && !(match[1] in attrs)) attrs[match[1]] = { value: match[1], dynamic: false };
    }
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
      // The real component re-fills every field with old($name, $value); a preview
      // of a re-shown form has to do the same or the inputs come out blank.
      const fallback = value === undefined || value === null ? 'null' : JSON.stringify(value);
      let val = this.evalExpr(`old(${JSON.stringify(String(name))}, ${fallback})`, this.currentScope ?? {});
      if (val === undefined || val === null) val = '';
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
    // A component without a bespoke handler is still a Blade file: render it, with
    // its @props defaults and its attributes bound to camelCase names. That is how
    // the payment-tender components reach the screen.
    const rel = `components.${tag}`;
    let componentFile = null;
    try {
      componentFile = this.file(rel);
    } catch (error) {
      componentFile = null;
    }
    if (componentFile) {
      try {
        let source = fs.readFileSync(componentFile, 'utf8');
        const camel = key => String(key).replace(/-([a-z])/g, (match, letter) => letter.toUpperCase());
        const scope = Object.create(this.currentScope);
        for (const [key, attr] of Object.entries(attrs)) {
          scope[camel(key)] = attr.dynamic ? read(key) : interpolate(attr.value);
        }
        const propsAt = source.indexOf('@props(');
        if (propsAt >= 0) {
          const open = source.indexOf('(', propsAt);
          const close = matchParen(source, open);
          if (close > open) {
            // ['name', 'other' => 'default'] — a list and a map in one literal, so it
            // is read part by part instead of being evaluated as JavaScript.
            const literal = source.slice(open + 1, close).trim().replace(/^\[/, '').replace(/\]$/, '');
            splitTopLevel(literal, ',').forEach(part => {
              const bits = splitTopLevel(part, '=>');
              const name = (bits[0] ?? '').trim().replace(/^['"]|['"]$/g, '');
              if (!name) return;
              if (scope[name] === undefined) {
                scope[name] = bits.length > 1 ? this.evalExpr(bits.slice(1).join('=>').trim(), scope) : undefined;
              }
            });
            source = source.slice(0, propsAt) + source.slice(close + 1);
          }
        }
        if (scope.class === undefined) scope.class = '';
        // `class` is a reserved word in JavaScript, so a bare `$class` interpolation
        // cannot be evaluated as written; bind it to a normal name first.
        scope.componentClass = scope.class ?? '';
        source = source.replace(/\$class\b/g, '$componentClass');
        const attrText = Object.entries(attrs)
          .map(([key, attr]) => ` ${key}="${esc(attr.dynamic ? (read(key) ?? '') : interpolate(attr.value))}"`)
          .join('');
        scope.attributes = {
          toString: () => attrText,
          merge: () => scope.attributes,
        };
        return { html: this.renderSource(source, scope, rel), end };
      } catch (error) {
        this.log.errors.push(`component ${tag}: ${error.message}`);
      }
    }
    this.log.unknownComponents.push(tag);
    return { html: '', end };
  }

  renderSource(src, scope, file = '?') {
    // A <style> body is CSS, not Blade: hold it aside behind a marker so the directive
    // scanner still sees one continuous source. Splitting the source instead broke any
    // @push that contained a style block — the closer then lived in another part.
    const styles = [];
    const held = String(src).replace(/<style\b[\s\S]*?<\/style>/gi, match => {
      styles.push(match);
      return `\u0000STYLE${styles.length - 1}\u0000`;
    });
    const rendered = this.renderParts(held, scope, file);
    return String(rendered).replace(/\u0000STYLE(\d+)\u0000/g, (match, index) => styles[Number(index)] ?? '');
  }

  renderParts(src, scope, file) {

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
        const rendered = value === undefined || value === null ? '' : (value instanceof Coll ? value.implode(', ') : value);
        out += next.type === 'echo' ? esc(rendered) : String(rendered);
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

        if (word === 'isset') {
          // @isset($x->y) @endisset — the property may not exist on the sample, and a
          // bare property read would throw, so the test is made guardedly.
          const close = src.indexOf('@endisset', bodyStart);
          const body = src.slice(bodyStart, close < 0 ? src.length : close);
          let set = false;
          try {
            set = this.evalExpr(`(() => { try { return (${arg}) != null; } catch (e) { return false; } })()`, local);
          } catch (error) {
            set = false;
          }
          if (set) out += this.renderSource(body, local, file);
          i = close < 0 ? src.length : close + '@endisset'.length;
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
            let stopped = false;
            for (let index = 0; index < items.length && !stopped; index += 1) {
              const entry = items[index];
              local.loop = {
                index: index + 1, iteration: index + 1, first: index === 0,
                last: index === items.length - 1, count: items.length,
                remaining: items.length - index - 1,
              };
              if (entry.bindings) entry.bindings.forEach(([name, value]) => { local[name] = value; });
              if (entry.keyName) local[entry.keyName] = entry.key;
              if (entry.valueName) local[entry.valueName] = entry.value;
              try {
                out += this.renderSource(body, local, file);
              } catch (signal) {
                if (signal === LOOP_CONTINUE) continue;
                if (signal === LOOP_BREAK) { stopped = true; continue; }
                throw signal;
              }
            }
            delete local.loop;
          }
          i = end;
          continue;
        }

        if (word === 'for') {
          // @for($i = 0; $i < $days; $i++) … @endfor — the counter is a normal PHP
          // variable, so it lives in the local scope while the body renders.
          // `@endfor` is a prefix of `@endforeach`, so the terminator needs a word
          // boundary: matching the prefix truncated every loop that contained a
          // @foreach and left "each" behind in the output.
          const closeMatch = /@endfor\b/.exec(src.slice(bodyStart));
          const close = closeMatch ? bodyStart + closeMatch.index : -1;
          const bodySrc = src.slice(bodyStart, close < 0 ? src.length : close);
          const [init = '', cond = '', step = ''] = arg.split(';');
          const assigned = init.match(/\$([A-Za-z_]\w*)\s*=\s*([\s\S]+)$/);
          if (assigned) {
            const name = assigned[1];
            local[name] = this.evalExpr(assigned[2], local);
            let guard = 0;
            while (this.bool(cond, local) && guard++ < 5000) {
              out += this.renderSource(bodySrc, local, file);
              const next = this.stepFor(step, name, local);
              if (next === null) break;
              local[name] = next;
            }
          }
          i = close < 0 ? src.length : close + '@endfor'.length;
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

        if (word === 'json') {
          // @json($x) writes a JavaScript literal; the script tags use it to hand
          // sample data to the page.
          const value = this.evalExpr(arg, local);
          out += esc(JSON.stringify(value === undefined ? null : value))
            .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<').replace(/&gt;/g, '>');
          i = bodyStart;
          continue;
        }

        if (word === 'checked' || word === 'selected' || word === 'disabled') {
          if (this.evalExpr(arg, local)) out += ` ${word}`;
          i = bodyStart;
          continue;
        }

        // @style(['display:none' => $cond, 'color:red' => true]) writes a style attribute from the
        // declarations whose condition holds; left as text it printed "@style(['display:none' => …"
        // into the page, which is how the online-payment deposit field gave itself away.
        if (word === 'style') {
          const inner = String(arg).trim().replace(/^\[/, '').replace(/\]$/, '');
          const declarations = [];
          for (const part of splitTopLevel(inner, ',')) {
            const arrow = splitTopLevel(part, '=>');
            if (arrow.length < 2) continue;
            const declaration = arrow[0].trim().replace(/^['"]|['"]$/g, '');
            if (this.evalExpr(arrow.slice(1).join('=>'), local)) declarations.push(declaration);
          }
          if (declarations.length) out += ` style="${esc(declarations.join('; '))}"`;
          i = bodyStart;
          continue;
        }

        if (word === 'continue' || word === 'break') {
          // @continue($cond) / @break($cond) — bare, the directive always applies.
          const applies = arg ? this.bool(arg, local) : true;
          i = bodyStart;
          if (!applies) continue;
          throw word === 'continue' ? LOOP_CONTINUE : LOOP_BREAK;
        }

        if (word === 'enderror' || word === 'csrf' || word === 'method') {
          i = bodyStart;
          continue;
        }

        // A directive this renderer does not implement is left exactly as written, so
        // nothing silently disappears from the screen.
        this.log.unknownDirectives.push(`${word} @ ${src.slice(Math.max(0, i - 50), i + 30).replace(/\s+/g, ' ')}`);
        out += src.slice(i, bodyStart);
        i = bodyStart;
        continue;
      }
    }

    this.currentScope = previous;
    return out;
  }

  /**
   * The third clause of an @for loop: `$i++`, `$i+=2`, `$i = $i + 1`, `$i--`.
   * Returns the next value, or null when the shape is not understood (the loop then
   * stops rather than spinning).
   */
  stepFor(step, name, local) {
    const text = String(step).trim();
    const current = Number(local[name] ?? 0);
    if (new RegExp(`^\\$?${name}\\+\\+$`).test(text) || text === `${name}++`) return current + 1;
    if (new RegExp(`^\\$?${name}--$`).test(text) || text === `${name}--`) return current - 1;
    const plus = text.match(/^\$?(\w+)\s*\+=\s*([\s\S]+)$/);
    if (plus && plus[1] === name) return current + Number(this.evalExpr(plus[2], local) ?? 0);
    const minus = text.match(/^\$?(\w+)\s*-=\s*([\s\S]+)$/);
    if (minus && minus[1] === name) return current - Number(this.evalExpr(minus[2], local) ?? 0);
    const assign = text.match(new RegExp(`^\\$?${name}\\s*=\\s*([\\s\\S]+)$`));
    if (assign) return Number(this.evalExpr(assign[1], local) ?? 0);
    return null;
  }

  /** Finds @elseif / @else / @endif boundaries for an @if block. */
  branches(src, start, closer) {
    const branches = [];
    let cursor = start;
    let cond = null;
    // `@if` may contain another `@if` — a partial with a custom-title block inside a
    // script tag does exactly that. Only the terminator at depth zero ends this one;
    // matching the first `@endif` left the inner block's `@endif` in the output.
    const opener = closer === 'endif' ? 'if' : closer === 'endunless' ? 'unless' : null;
    for (;;) {
      const stop = findStop(src, cursor, new Set(['elseif', 'else', closer]));
      if (!stop) { branches.push({ cond, body: src.slice(cursor) }); return { branches, end: src.length }; }
      if (opener && stop.w === closer) {
        const inner = src.slice(cursor, stop.i);
        const opened = (inner.match(new RegExp(`@${opener}\\b`, 'g')) ?? []).length
          - (inner.match(new RegExp(`@${closer}\\b`, 'g')) ?? []).length;
        if (opened > 0) {
          // the closer belongs to a nested block: skip past it and keep looking
          branches.push({ cond, body: src.slice(cursor, stop.i + closer.length + 1) });
          cursor = stop.i + closer.length + 1;
          cond = null;
          branches[branches.length - 1].merged = true;
          continue;
        }
      }
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
    // A keyed collection keeps Laravel's keys: `collect(REPORTS)` filtered and then
    // read as `foreach ($reports as $slug => $report)`.
    else if (value && typeof value === 'object' && Array.isArray(value.entries)) entries = value.entries;
    else if (value && typeof value === 'object') entries = Object.entries(value);
    else return [];

    const bindingText = bindings.trim();
    // `foreach ($rows as $i => $row)` over a plain list hands back the index and the row, not a
    // [key, value] pair — pairing by position is what PHP does, and it is how the revenue view
    // reads its forecast rows.
    const pairList = entries.length > 0 && entries.every(entry => Array.isArray(entry) && entry.length === 2);
    const list = /^\[([\s\S]+)\]$/.exec(bindingText);
    if (list) {
      const names = splitTopLevel(list[1], ',').map(part => part.trim().replace(/^\$/, ''));
      return entries.map(entry => ({
        bindings: names.map((name, index) => [name, Array.isArray(entry) ? entry[index] : entry?.[name]]),
      }));
    }
    // `foreach ($map as $k => [$label, $description])` is how the pricing-mode fieldset writes its
    // three choices; without this the whole fieldset rendered empty and nothing complained.
    const kvList = /^\$(\w+)\s*=>\s*\[([\s\S]+)\]$/.exec(bindingText);
    if (kvList) {
      const names = splitTopLevel(kvList[2], ',').map(part => part.trim().replace(/^\$/, ''));
      const pairs = pairList ? entries : entries.map((value, index) => [index, value]);
      return pairs.map(([k, v]) => ({
        keyName: kvList[1], key: k,
        bindings: names.map((name, index) => [name, Array.isArray(v) ? v[index] : v?.[name]]),
      }));
    }
    const kv = /^\$(\w+)\s*=>\s*\$(\w+)$/.exec(bindingText);
    const single = /^\$(\w+)$/.exec(bindingText);
    if (kv) {
      const pairs = pairList ? entries : entries.map((value, index) => [index, value]);
      return pairs.map(([k, v]) => ({ keyName: kv[1], key: k, valueName: kv[2], value: v }));
    }
    if (!single) return [];
    return entries.map(v => ({ valueName: single[1], value: v }));
  }
}

/**
 * <style> bodies are copied verbatim: `@media`, `@apply` and friends are CSS, not
 * Blade. <script> bodies are NOT: the application puts `@if`, `@include` and `@json`
 * inside its scripts, and so does Blade itself — leaving them unread floated raw
 * `@if($x)` text onto the screen.
 */
function splitVerbatim(src) {
  const parts = [];
  let i = 0;
  while (i < src.length) {
    const style = src.indexOf('<style', i);
    const starts = [style].filter(position => position >= 0);
    if (!starts.length) {
      parts.push({ raw: false, text: src.slice(i) });
      break;
    }
    const start = Math.min(...starts);
    const closer = '</style>';
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
    // The body is always an expression in PHP, but a keyed array inside it is rewritten to an object
    // literal later (`['id' => 1]` → `{id: 1}`) — and an arrow whose body opens with `{` reads it as a
    // block, which is a syntax error. Bracketing the body keeps it an expression.
    out = `${out.slice(0, at)}((${param}) => (${body}))${out.slice(end)}`;
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
  // Foo::class is the class name as a string, which is how the views alias a service
  // (`$RS = \\App\\Services\\ReportScheduleService::class`) and then read its constants.
  s = s.replace(/(\\?[A-Za-z_\u00c0-\uffff][\w\\]*)::class\b/g,
    (m, cls) => JSON.stringify(cls.replace(/^\\/, '')));
  // …and a constant or static call through that alias.
  s = s.replace(/\$([A-Za-z_]\w*)::(\w+)\s*\(/g,
    (m, alias, method) => `__static(${alias}, ${JSON.stringify(method)}, `);
  s = s.replace(/\$([A-Za-z_]\w*)::([A-Z_][A-Z0-9_]*)\b(?!\s*\()/g,
    (m, alias, name) => `__const(${alias}, ${JSON.stringify(name)})`);
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
      let computed = false;
      const pairs = splitTopLevel(inner, ',').filter(p => p.trim()).map((pair, index) => {
        const bits = splitTopLevel(pair, '=>');
        // PHP numbers a bare element in a keyed array from zero: ['ap_ageing', 'to' => $x] is
        // [0 => 'ap_ageing', 'to' => $x]. Without this the element became a key with no value and the whole
        // expression was a syntax error — which is how the ageing's CSV link rendered as href="".
        if (bits.length === 1) {
          computed = true;

          return [String(index), convertKeyedArrays(pair.trim())];
        }
        const rawKey = (bits[0] ?? '').trim();
        // A quoted key is a literal and a number stays a number. Anything else is an expression —
        // `[$m => ...]` keys the map by the value of $m, not by the text "m" — and those are built
        // with Object.fromEntries, so no `{` lands where an arrow body would read it as a block.
        const literalKey = /^['"]/.test(rawKey) || /^-?\d+(?:\.\d+)?$/.test(rawKey);
        if (!literalKey) computed = true;
        return [literalKey ? rawKey : convertKeyedArrays(rawKey), convertKeyedArrays((bits[1] ?? '').trim())];
      });
      out += computed
        ? `Object.fromEntries([${pairs.map(([k, v]) => `[${k}, ${v}]`).join(', ')}])`
        : `{${pairs.map(([k, v]) => `${k}: ${v}`).join(', ')}}`;
    } else {
      out += `[${convertKeyedArrays(inner)}]`;
    }
    i = close;
  }
  return out;
}
