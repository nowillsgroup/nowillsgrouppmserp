/**
 * The application shell around a preview screen.
 *
 * Every preview shows a page as staff see it, so the frame matters as much as the
 * page: the sidebar, the icon sprite, the top bar and the page head. None of it is
 * hand-copied — it is read from the real sources:
 *
 *   sidebar   ← config/pms.php  ('menu' — every section, group and item)
 *   sprite    ← resources/views/partials/icons.blade.php
 *   top bar   ← resources/views/layouts/app.blade.php
 *
 * The page itself is rendered from its Blade view by deploy/dev/preview/blade-render.mjs.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

export { ROOT };

/* ── a small reader for the literal PHP arrays in config/pms.php ───────────── */

function parsePhpString(src, i) {
  // src[i] is the opening quote
  const quote = src[i];
  let out = '';
  for (let j = i + 1; j < src.length; j++) {
    const ch = src[j];
    if (ch === '\\') {
      const next = src[j + 1];
      out += next === 'n' ? '\n' : next === 't' ? '\t' : next;
      j++;
      continue;
    }
    if (ch === quote) return { value: out, end: j + 1 };
    out += ch;
  }
  throw new Error('unterminated string');
}

function skipSpace(src, i) {
  for (;;) {
    while (i < src.length && /\s/.test(src[i])) i++;
    if (src.startsWith('//', i)) {
      const nl = src.indexOf('\n', i);
      i = nl === -1 ? src.length : nl + 1;
      continue;
    }
    if (src.startsWith('/*', i)) {
      const close = src.indexOf('*/', i);
      i = close === -1 ? src.length : close + 2;
      continue;
    }
    return i;
  }
}

function parsePhpValue(src, i) {
  i = skipSpace(src, i);
  const ch = src[i];
  if (ch === '[') return parsePhpArray(src, i);
  if (ch === "'" || ch === '"') return parsePhpString(src, i);
  const token = /^(-?\d+(?:\.\d+)?|true|false|null)/.exec(src.slice(i));
  if (token) {
    const raw = token[1];
    const value = raw === 'true' ? true : raw === 'false' ? false : raw === 'null' ? null : Number(raw);
    return { value, end: i + raw.length };
  }
  throw new Error(`unexpected token near: ${src.slice(i, i + 40)}`);
}

function parsePhpArray(src, i) {
  // src[i] is '['
  const items = [];
  let j = i + 1;
  for (;;) {
    j = skipSpace(src, j);
    if (src[j] === ']') return { value: items, end: j + 1 };
    const key = parsePhpValue(src, j);
    j = skipSpace(src, key.end);
    if (src.startsWith('=>', j)) {
      const value = parsePhpValue(src, skipSpace(src, j + 2));
      items.push([key.value, value.value]);
      j = skipSpace(src, value.end);
    } else {
      items.push([null, key.value]);
      j = skipSpace(src, key.end);
    }
    if (src[j] === ',') j++;
  }
}

/** PHP arrays become objects when every entry has a key, arrays when none do. */
function toJs(items) {
  const keyed = items.some(([key]) => key !== null);
  if (!keyed) return items.map(([, value]) => (Array.isArray(value) ? toJs(value) : value));
  const out = {};
  for (const [key, value] of items) out[key] = Array.isArray(value) ? toJs(value) : value;
  return out;
}

export function menuFromConfig() {
  const src = fs.readFileSync(path.join(ROOT, 'config/pms.php'), 'utf8');
  const start = src.indexOf("'menu' =>");
  if (start < 0) throw new Error("config/pms.php has no 'menu' key");
  const open = src.indexOf('[', start);
  return toJs(parsePhpArray(src, open).value);
}

/* ── the pieces of the screen ──────────────────────────────────────────────── */

export const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const slug = s => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
export const icon = (name, cls = 'i') => `<svg class="${cls}" aria-hidden="true"><use href="#i-${esc(name || 'grid')}"/></svg>`;

export function spriteSymbols() {
  const src = fs.readFileSync(path.join(ROOT, 'resources/views/partials/icons.blade.php'), 'utf8');
  return [...src.matchAll(/<symbol id="i-[a-z0-9-]+"[\s\S]*?<\/symbol>/g)].map(m => m[0].trim());
}

/** Does this item survive the sidebar's tests? Mirrors Blade's recursive $canSee. */
function visibleInNav(item, state) {
  if (item.employee === true) return false;
  if ((state.hide || new Set()).has(item.label)) return false;
  const children = item.children || [];
  return children.length ? children.some(child => visibleInNav(child, state)) : true;
}

/** Mirrors resources/views/partials/sidebar-nav.blade.php. */
export function renderNav(items, depth = 0, parentKey = '', state = { lastSection: null, activeKey: 'dashboard-overview' }) {
  let html = '';
  for (const item of items) {
    if (item.employee === true) continue; // only linked staff see these; the preview user is not linked
    // v10.7 the sidebar now drops an item whose module the licence in force does not allow — and a
    // heading whose children are all dropped goes with them, exactly as the Blade $canSee does — so a
    // preview of a property that switched a module off must drop the same items (see sidebar-nav).
    if ((state.hide || new Set()).has(item.label)) continue;
    if ((item.children || []).length && !item.children.some(child => visibleInNav(child, state))) continue;
    const key = [parentKey, slug(item.label)].filter(Boolean).join('.');
    const children = item.children || [];
    const isGroup = children.length > 0;
    const section = depth === 0 ? item.section : null;
    const active = Boolean(item.route && item.route === 'dashboard');

    if (section && section !== state.lastSection) {
      html += `    <div class="nav-section-label">${esc(section)}</div>\n`;
      state.lastSection = section;
    }

    if (isGroup) {
      const open = children.some(child => child.route === 'dashboard');
      html += `    <div class="nav-item nav-group ${depth === 0 ? 'nav-category' : 'nav-module'}${open ? ' open' : ''}" data-nav-key="${esc(key)}" data-nav-label="${esc(item.label)}">
      <button type="button" aria-expanded="${open}" aria-controls="${esc(key)}-children" title="${esc(item.label)}">
        ${icon(item.icon)}
        <span class="nav-label">${esc(item.label)}</span>
        <svg class="i chev" aria-hidden="true"><use href="#i-chev"/></svg>
      </button>
      <div class="nav-sub" id="${esc(key)}-children">
${renderNav(children, depth + 1, key, state)}      </div>
    </div>
`;
    } else if (item.route) {
      html += `    <div class="nav-item nav-link-item${active ? ' active' : ''}" data-nav-key="${esc(key)}" data-nav-label="${esc(item.label)}">
      <a href="#"${active ? ' aria-current="page"' : ''} title="${esc(item.label)}" data-route="${esc(item.route)}">
        ${icon(item.icon)}
        <span class="nav-label">${esc(item.label)}</span>
      </a>
    </div>
`;
    }
  }
  return html;
}

export const hotel = { name: 'The Grove Essipong Beach Resort', code: 'GROVE' };
const user = { name: 'Property Administrator', role: 'Administrator', initials: 'PA' };
export const version = /'version'\s*=>\s*'([\d.]+)'/.exec(fs.readFileSync(path.join(ROOT, 'config/pms.php'), 'utf8'))?.[1] ?? '';
const businessDate = new Date('2026-11-05T09:00:00Z').toLocaleDateString('en-GB', {
  weekday: 'short', day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC',
});

export function shell(navHtml, sprite, content, page = {}) {
  return `<!doctype html>
<!-- Generated by a builder in deploy/dev/. The sidebar, sprite and page head come
     from config/pms.php, resources/views/partials/icons.blade.php and
     resources/views/layouts/app.blade.php; the page itself is its Blade view
     rendered with the fixed sample in deploy/dev/preview/. Do not edit this file —
     rebuild it. -->
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#14532d">
  <title>${esc(page.title ?? 'Dashboard')} · ${esc(hotel.name)}</title>
  <link rel="stylesheet" href="/css/app.css">
  <link rel="stylesheet" href="/css/offline.css">
  <link rel="stylesheet" href="/css/dashboard.css">
${page.head ?? ''}</head>
<body>
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
${sprite.join('\n')}
</svg>
<div class="app">
  <aside class="sidebar" id="primary-sidebar" aria-label="Application sidebar">
    <div class="brand">
      <img class="brand-logo-full" src="/images/brand/nowills-pms-premium-gold.webp" alt="Nowills PMS — Cloud Hospitality Suite">
      <img class="brand-logo-mark" src="/images/brand/nowills-pms-premium-mark.webp" alt="Nowills PMS">
      <button type="button" class="sidebar-collapse" data-collapse-sidebar aria-controls="primary-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">${icon('chev')}</button>
      <button type="button" class="sidebar-mobile-close" data-close-nav aria-label="Close navigation" title="Close navigation"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="m18 6-12 12M6 6l12 12"/></svg></button>
    </div>
    <div class="nav-search">
      ${icon('search', 'i i-sm')}
      <input id="navModuleSearch" type="search" placeholder="Search modules…" autocomplete="off" aria-label="Search modules">
      <button type="button" class="nav-search-clear" data-clear-nav-search aria-label="Clear module search" title="Clear search">×</button>
    </div>
    <nav class="nav" aria-label="Primary navigation">
${navHtml}    </nav>
    <div class="side-profile">
      <a href="#" title="Open profile">
        <div class="avatar">${esc(user.initials)}</div>
        <span class="side-profile-copy"><b>${esc(user.name)}</b><small>${esc(user.role)}</small></span>
      </a>
      <form method="post" action="#">
        <button type="submit" aria-label="Sign out" title="Sign out">${icon('logout', 'i i-sm')}</button>
      </form>
    </div>
    <div class="side-foot">v${esc(version)} · ${esc(hotel.code)}</div>
  </aside>
  <button type="button" class="nav-scrim" data-close-nav aria-label="Close navigation" tabindex="-1"></button>

  <div class="main">
    <header class="topbar">
      <button class="btn btn-ghost btn-icon menu-btn" data-toggle-nav aria-label="Open navigation" aria-expanded="false" aria-controls="primary-sidebar">${icon('menu')}</button>
      <form class="top-search" action="#" method="get" role="search">
        ${icon('search', 'i i-sm')}
        <input id="globalSearch" name="q" value="" placeholder="Search guest, reservation, room, company, folio…  ( / )" autocomplete="off" aria-label="Search">
      </form>
      <div class="top-right">
        <nav class="top-tools" aria-label="Quick links">
          <button type="button" class="top-tool" id="app-theme" aria-label="Switch to dark mode" title="Switch to dark mode">
            <span class="top-tool-icon top-tool-icon-moon">${icon('moon', 'i i-sm')}</span>
          </button>
          <button type="button" class="top-tool" id="nav-fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">
            <span class="top-tool-icon top-tool-icon-blue">${icon('expand', 'i i-sm')}</span>
          </button>
          <div class="offline-indicator" role="status" aria-live="polite" title="Offline sync status">
            <span class="offline-indicator-dot"></span><span>Online</span>
          </div>
          <div class="top-notify">
            <button type="button" class="top-tool" data-dropdown="notifyMenu" aria-haspopup="true" aria-label="Notifications" title="Notifications">
              <span class="top-tool-icon top-tool-icon-blue">${icon('bell', 'i i-sm')}</span>
            </button>
            <div class="dropdown dropdown-notify" id="notifyMenu" role="menu" aria-label="Notifications">
              <div class="notify-drop-head"><b>Notifications</b><span class="tiny text-2">All clear</span></div>
              <p class="notify-drop-empty small text-2">Nothing needs you right now.</p>
              <a class="notify-drop-all" role="menuitem" href="#">See all notifications →</a>
            </div>
          </div>
          <a class="top-tool" href="#" aria-label="Desk messages" title="Desk messages"><span class="top-tool-icon top-tool-icon-amber">${icon('mail', 'i i-sm')}</span></a>
          <a class="top-tool" href="#" aria-label="Guest inbox" title="Guest inbox"><span class="top-tool-icon top-tool-icon-purple">${icon('message', 'i i-sm')}</span></a>
          <a class="top-tool" href="#" aria-label="Banquet calendar" title="Banquet calendar"><span class="top-tool-icon top-tool-icon-teal">${icon('calendar', 'i i-sm')}</span></a>
        </nav>
        <span class="bdate" title="Business (audit) date">${icon('calendar', 'i i-sm')} ${esc(businessDate)}</span>
        <span class="small text-2 hotel-switch">${icon('hotel', 'i i-sm')} ${esc(hotel.name)}</span>
        <div class="user-chip" data-dropdown="userMenu">
          <div class="avatar">${esc(user.initials)}</div>
          <div class="meta"><b>${esc(user.name)}</b><span>${esc(user.role)}</span></div>
          <div class="dropdown" id="userMenu">
            <a href="#">${icon('user', 'i i-sm')} My Profile</a>
            <a href="#">${icon('building', 'i i-sm')} Hotel Settings</a>
            <form method="post" action="#"><button type="submit">${icon('logout', 'i i-sm')} Sign out</button></form>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <div class="page-head">
        <div>
          <div class="crumbs">${page.crumbs ?? ''}</div>
          <h1>${esc(page.title ?? 'Dashboard')}</h1>
        </div>
        <div class="actions">
${page.actions ?? ''}
        </div>
      </div>
${content}
    </main>
  </div>
</div>
${page.scripts ?? ''}
<script>
// The application's modal plumbing (public/js/app.js): a button that opens nothing
// would hide a bug, so the rendered shell answers [data-modal] the same way.
document.addEventListener('click', e => {
  const opener = e.target.closest('[data-modal]');
  if (opener) {
    e.preventDefault();
    const modal = document.getElementById(opener.dataset.modal);
    if (modal) {
      modal.classList.add('show');
      const resetting = modal.querySelector('form[data-reset]');
      if (resetting) resetting.reset();
      const field = modal.querySelector('input:not([type=hidden]),select,textarea');
      if (field) setTimeout(() => field.focus(), 50);
    }
  }
  if (e.target.closest('[data-close]') || e.target.classList.contains('modal')) {
    e.target.closest('.modal')?.classList.remove('show');
  }
});
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  document.querySelectorAll('.modal.show').forEach(modal => modal.classList.remove('show'));
});
</script>
</body>
</html>
`;
}

/** The sidebar exactly as the layout renders it, plus the parsed menu. */
export function nav(options = {}) {
  const menu = menuFromConfig();
  const state = { lastSection: null, activeKey: 'dashboard-overview', hide: new Set(options.hide || []) };
  return { menu, navHtml: renderNav(menu, 0, '', state) };
}
