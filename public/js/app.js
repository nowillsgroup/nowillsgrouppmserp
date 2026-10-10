/* Nowills PMS — vanilla JS behaviours (no build step) */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  window.PMS = { $, $$ };

  // Sidebar groups and persisted desktop compact mode.
  const navStateKey = 'nowills-nav-state';
  $$('.nav-group').forEach(group => {
    const button = group.querySelector(':scope > button');
    const key = group.dataset.navKey;
    if (!button || !key) return;
    const hasActiveChild = !!group.querySelector('.nav-link-item.active');
    const saved = localStorage.getItem(navStateKey + ':' + key);
    if (!hasActiveChild && saved !== null) group.classList.toggle('open', saved === 'open');
    button.setAttribute('aria-expanded', group.classList.contains('open') ? 'true' : 'false');
    button.addEventListener('click', () => {
      const open = group.classList.toggle('open');
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      localStorage.setItem(navStateKey + ':' + key, open ? 'open' : 'closed');
    });
  });
  $$('[data-toggle-nav]').forEach(b => b.addEventListener('click', () => document.body.classList.toggle('nav-open')));
  $$('.nav a').forEach(a => a.addEventListener('click', () => document.body.classList.remove('nav-open')));

  const sidebarKey = 'nowills-sidebar-collapsed';
  const collapseButton = $('[data-collapse-sidebar]');
  const applySidebarMode = collapsed => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    if (collapseButton) {
      collapseButton.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      collapseButton.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }
  };
  applySidebarMode(localStorage.getItem(sidebarKey) === '1' && window.innerWidth > 860);
  collapseButton?.addEventListener('click', () => {
    const collapsed = !document.body.classList.contains('sidebar-collapsed');
    applySidebarMode(collapsed);
    localStorage.setItem(sidebarKey, collapsed ? '1' : '0');
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth <= 860) document.body.classList.remove('sidebar-collapsed');
    else if (localStorage.getItem(sidebarKey) === '1') document.body.classList.add('sidebar-collapsed');
  });

  // Client-side module search keeps the existing permission-filtered menu intact.
  const moduleSearch = $('#navModuleSearch');
  const nav = $('.nav');
  const clearModuleSearch = $('[data-clear-nav-search]');
  if (moduleSearch && nav) {
    const groups = $$('.nav-group', nav);
    const items = $$('.nav-item', nav);
    const runModuleSearch = () => {
      const query = moduleSearch.value.trim().toLowerCase();
      moduleSearch.closest('.nav-search')?.classList.toggle('has-value', !!query);
      nav.classList.toggle('nav-searching', !!query);
      if (!query) {
        groups.forEach(group => {
          if (group.dataset.searchOpen !== undefined) group.classList.toggle('open', group.dataset.searchOpen === '1');
          delete group.dataset.searchOpen;
        });
        items.forEach(item => item.classList.remove('nav-search-hidden'));
        return;
      }
      groups.forEach(group => {
        if (group.dataset.searchOpen === undefined) group.dataset.searchOpen = group.classList.contains('open') ? '1' : '0';
      });
      items.forEach(item => {
        const labels = $$('.nav-label', item).map(label => label.textContent.toLowerCase());
        const match = labels.some(label => label.includes(query));
        const descendantMatch = $$('.nav-label', item).some(label => label.textContent.toLowerCase().includes(query));
        item.classList.toggle('nav-search-hidden', !match && !descendantMatch);
        if (descendantMatch && item.classList.contains('nav-group')) item.classList.add('open');
      });
    };
    moduleSearch.addEventListener('input', runModuleSearch);
    clearModuleSearch?.addEventListener('click', () => { moduleSearch.value = ''; runModuleSearch(); moduleSearch.focus(); });
  }

  // Dropdowns
  document.addEventListener('click', e => {
    if (e.target.closest('.dropdown')) return; // let links/buttons inside menus work
    const t = e.target.closest('[data-dropdown]');
    $$('.dropdown.show').forEach(d => { if (!t || d !== document.getElementById(t.dataset.dropdown)) d.classList.remove('show'); });
    if (t) { e.preventDefault(); e.stopPropagation(); document.getElementById(t.dataset.dropdown)?.classList.toggle('show'); }
  });

  // Modals
  window.openModal = id => { const m = document.getElementById(id); if (m) { m.classList.add('show'); const f = m.querySelector('input:not([type=hidden]),select,textarea'); f && setTimeout(() => f.focus(), 50); } };
  window.closeModal = el => (el.closest ? el.closest('.modal') : document.getElementById(el))?.classList.remove('show');
  document.addEventListener('click', e => {
    const o = e.target.closest('[data-modal]'); if (o) { e.preventDefault(); openModal(o.dataset.modal);
      const rf = document.querySelector('#' + o.dataset.modal + ' form[data-reset]'); if (rf) rf.reset();
      const tt = document.querySelector('#' + o.dataset.modal + ' [data-title]'); if (tt && o.dataset.title) tt.textContent = o.dataset.title;
      // pass data-* to modal form fields
      Object.entries(o.dataset).forEach(([k, v]) => { if (k.startsWith('f')) { const n = k.slice(1).replace(/^./, c => c.toLowerCase()); const els = document.querySelectorAll('#' + o.dataset.modal + ' [name="' + n + '"]'); els.forEach(el => { if (el.type === 'checkbox') el.checked = (v === '1'); else if (el.type !== 'hidden' || els.length === 1) el.value = v; }); } });
      if (o.dataset.action) { const f = document.querySelector('#' + o.dataset.modal + ' form'); if (f) f.action = o.dataset.action; }
    }
    if (e.target.closest('[data-close]') || e.target.classList.contains('modal')) { e.target.closest('.modal')?.classList.remove('show'); }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('.modal.show').forEach(m => m.classList.remove('show')); });

  // Confirmations
  document.addEventListener('submit', e => {
    const f = e.target; if (f.dataset.confirm && !confirm(f.dataset.confirm)) { e.preventDefault(); return; }
    const b = f.querySelector('[type=submit]'); if (b && !f.dataset.noLock) { setTimeout(() => { b.disabled = true; }, 0); }
  });
  document.addEventListener('click', e => { const a = e.target.closest('a[data-confirm]'); if (a && !confirm(a.dataset.confirm)) e.preventDefault(); });

  // Tabs
  $$('.tabs[data-tabs] button').forEach(b => b.addEventListener('click', () => {
    const wrap = b.closest('[data-tabwrap]') || document;
    $$('.tabs[data-tabs] button', wrap).forEach(x => x.classList.toggle('active', x === b));
    $$('.tab-pane', wrap).forEach(p => p.classList.toggle('active', p.id === b.dataset.tab));
    history.replaceState(null, '', '#' + b.dataset.tab);
  }));
  if (location.hash) { const b = $('.tabs[data-tabs] button[data-tab="' + location.hash.slice(1) + '"]'); b && b.click(); }

  // Toast auto-hide
  setTimeout(() => $$('.toast .alert').forEach(a => { a.style.transition = 'opacity .4s'; a.style.opacity = 0; setTimeout(() => a.remove(), 400); }), 5000);

  // Auto-submit filters
  $$('[data-autosubmit]').forEach(el => el.addEventListener('change', () => el.form.submit()));

  // Nights calculator
  window.calcNights = (a, d, out) => {
    const A = new Date($(a).value), D = new Date($(d).value);
    const n = Math.round((D - A) / 86400000); if ($(out)) $(out).value = n > 0 ? n : 0; return n;
  };

  // JSON helper
  window.getJSON = (url) => fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.json());

  // Live table filter
  $$('[data-filter-table]').forEach(inp => inp.addEventListener('input', () => {
    const q = inp.value.toLowerCase(); $$(inp.dataset.filterTable + ' tbody tr').forEach(tr => tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none');
  }));

  // Keyboard shortcut: "/" focuses global search
  document.addEventListener('keydown', e => { if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) { e.preventDefault(); $('#globalSearch')?.focus(); } });
})();
