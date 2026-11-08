/* Nowills PMS — vanilla JS behaviours (no build step) */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  window.PMS = { $, $$ };
  const moduleSearch = $('#navModuleSearch');
  const nav = $('.nav');

  // Sidebar groups and persisted desktop compact mode.
  const navStateKey = 'nowills-nav-state';
  const syncFlyoutDescendants = (root, visible) => {
    root.querySelectorAll('.nav-group').forEach(child => {
      const trigger = child.querySelector(':scope > button');
      if (trigger) trigger.setAttribute('aria-expanded', visible && child.classList.contains('open') ? 'true' : 'false');
    });
  };
  $$('.nav-group').forEach(group => {
    const button = group.querySelector(':scope > button');
    const key = group.dataset.navKey;
    if (!button || !key) return;
    const hasActiveChild = !!group.querySelector('.nav-link-item.active');
    const saved = localStorage.getItem(navStateKey + ':' + key);
    if (!hasActiveChild && saved !== null) group.classList.toggle('open', saved === 'open');
    button.setAttribute('aria-expanded', group.classList.contains('open') ? 'true' : 'false');
    button.addEventListener('click', () => {
      group.classList.remove('flyout-dismissed');
      const open = group.classList.toggle('open');
      // Search deliberately reveals multiple matching paths; otherwise keep only
      // one open group at each level without closing its ancestors.
      if (open && !moduleSearch?.value.trim()) {
        Array.from(group.parentElement.children).forEach(sibling => {
          if (sibling === group || !sibling.classList.contains('nav-group')) return;
          sibling.classList.remove('open');
          sibling.querySelector(':scope > button')?.setAttribute('aria-expanded', 'false');
          syncFlyoutDescendants(sibling, false);
          if (sibling.dataset.navKey) localStorage.setItem(navStateKey + ':' + sibling.dataset.navKey, 'closed');
        });
      }
      const flyoutVisible = document.body.classList.contains('sidebar-collapsed')
        && window.innerWidth > 860
        && group.matches(':hover, :focus-within');
      button.setAttribute('aria-expanded', flyoutVisible || open ? 'true' : 'false');
      syncFlyoutDescendants(group, group.parentElement === nav ? (collapsedDesktop() ? flyoutVisible : open) : open);
      if (!moduleSearch?.value.trim()) localStorage.setItem(navStateKey + ':' + key, open ? 'open' : 'closed');
    });
    const collapsedDesktop = () => document.body.classList.contains('sidebar-collapsed') && window.innerWidth > 860;
    const placeCollapsedFlyout = () => {
      if (!collapsedDesktop() || document.body.classList.contains('sidebar-searching') || group.classList.contains('flyout-dismissed') || group.parentElement !== nav) return;
      const flyout = group.querySelector(':scope > .nav-sub');
      if (!flyout) return;
      const anchor = group.getBoundingClientRect();
      const height = Math.min(flyout.scrollHeight, window.innerHeight - 16);
      const top = Math.max(8, Math.min(anchor.top, window.innerHeight - 8 - height));
      flyout.style.position = 'fixed';
      flyout.style.left = `${sidebar?.getBoundingClientRect().right ?? 72}px`;
      flyout.style.top = `${top}px`;
      syncFlyoutDescendants(group, true);
    };
    group.addEventListener('mouseenter', () => {
      if (collapsedDesktop()) {
        group.classList.remove('flyout-dismissed');
        button.setAttribute('aria-expanded', 'true');
        placeCollapsedFlyout();
      }
    });
    group.addEventListener('mouseleave', () => {
      if (!group.matches(':focus-within')) group.classList.remove('flyout-dismissed');
      if (collapsedDesktop() && !group.matches(':focus-within')) {
        button.setAttribute('aria-expanded', 'false');
        if (group.parentElement === nav) syncFlyoutDescendants(group, false);
      }
    });
    group.addEventListener('focusin', () => {
      if (collapsedDesktop() && !group.classList.contains('flyout-dismissed')) {
        button.setAttribute('aria-expanded', 'true');
        placeCollapsedFlyout();
      }
    });
    group.addEventListener('focusout', event => {
      if (collapsedDesktop() && !group.contains(event.relatedTarget)) {
        group.classList.remove('flyout-dismissed');
        const hoverVisible = group.matches(':hover');
        button.setAttribute('aria-expanded', hoverVisible ? 'true' : 'false');
        if (group.parentElement === nav) syncFlyoutDescendants(group, hoverVisible);
      }
    });
    if (nav && group.parentElement === nav) {
      nav.addEventListener('scroll', () => {
        if (group.matches(':hover, :focus-within')) placeCollapsedFlyout();
      }, { passive: true });
    }
  });
  // Older saved state may have several siblings open. Prefer the current page's
  // path, then the first saved open group, and clear the remaining siblings.
  const groupParents = new Set($$('.nav-group', nav || document).map(group => group.parentElement));
  groupParents.forEach(parent => {
    const openGroups = Array.from(parent.children).filter(child => child.classList.contains('nav-group') && child.classList.contains('open'));
    const keep = openGroups.find(group => group.querySelector('.nav-link-item.active')) || openGroups[0];
    openGroups.forEach(group => {
      if (group === keep) return;
      group.classList.remove('open');
      group.querySelector(':scope > button')?.setAttribute('aria-expanded', 'false');
      if (group.dataset.navKey) localStorage.setItem(navStateKey + ':' + group.dataset.navKey, 'closed');
    });
  });
  const navToggleButtons = $$('[data-toggle-nav]');
  const closeNavButtons = $$('[data-close-nav]');
  const sidebar = $('#primary-sidebar');
  const setMobileNavOpen = (open, returnFocus = false) => {
    const mobileViewport = window.innerWidth <= 860;
    if (sidebar) {
      sidebar.inert = mobileViewport && !open;
      if (mobileViewport) sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
      else sidebar.removeAttribute('aria-hidden');
    }
    document.body.classList.toggle('nav-open', open);
    navToggleButtons.forEach(button => {
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    });
    if (open) $('#navModuleSearch')?.focus({ preventScroll: true });
    else if (returnFocus) navToggleButtons[0]?.focus();
  };
  navToggleButtons.forEach(button => button.addEventListener('click', () => {
    setMobileNavOpen(!document.body.classList.contains('nav-open'));
  }));
  closeNavButtons.forEach(button => button.addEventListener('click', () => setMobileNavOpen(false, true)));
  setMobileNavOpen(false);
  $$('.nav a').forEach(a => a.addEventListener('click', () => setMobileNavOpen(false)));
  window.addEventListener('resize', () => {
    const open = document.body.classList.contains('nav-open');
    setMobileNavOpen(window.innerWidth > 860 ? false : open);
  });

  const sidebarKey = 'nowills-sidebar-collapsed';
  const collapseButton = $('[data-collapse-sidebar]');
  const applySidebarMode = collapsed => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    const searching = !!moduleSearch?.value.trim();
    document.body.classList.toggle('sidebar-searching', collapsed && searching);
    if (!collapsed) {
      $$('.nav > .nav-group > .nav-sub').forEach(flyout => {
        flyout.style.removeProperty('position');
        flyout.style.removeProperty('left');
        flyout.style.removeProperty('top');
      });
    }
    $$('.nav-group').forEach(group => {
      const button = group.querySelector(':scope > button');
      if (!button) return;
      const expanded = collapsed && !searching
        ? group.parentElement === nav && group.matches(':hover, :focus-within') && !group.classList.contains('flyout-dismissed')
        : group.classList.contains('open');
      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    });
    if (collapseButton) {
      const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
      collapseButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      collapseButton.setAttribute('aria-label', label);
      collapseButton.title = label;
    }
  };
  applySidebarMode(localStorage.getItem(sidebarKey) === '1' && window.innerWidth > 860);
  collapseButton?.addEventListener('click', () => {
    const collapsed = !document.body.classList.contains('sidebar-collapsed');
    applySidebarMode(collapsed);
    localStorage.setItem(sidebarKey, collapsed ? '1' : '0');
  });
  window.addEventListener('resize', () => {
    applySidebarMode(window.innerWidth > 860 && localStorage.getItem(sidebarKey) === '1');
  });

  // Client-side module search keeps the permission-filtered menu and reveals matching paths.
  const clearModuleSearch = $('[data-clear-nav-search]');
  if (moduleSearch && nav) {
    const groups = $$('.nav-group', nav);
    const items = $$('.nav-item', nav);
    const emptyMessage = $('[data-nav-search-empty]', nav);
    const syncExpanded = group => {
      const button = group.querySelector(':scope > button');
      if (button) button.setAttribute('aria-expanded', group.classList.contains('open') ? 'true' : 'false');
    };
    const revealAncestors = item => {
      item.classList.remove('nav-search-hidden');
      let parent = item.parentElement?.closest('.nav-group');
      while (parent) {
        parent.classList.remove('nav-search-hidden');
        parent.classList.add('open');
        syncExpanded(parent);
        parent = parent.parentElement?.closest('.nav-group');
      }
    };
    const revealGroupContents = group => {
      group.classList.remove('nav-search-hidden');
      group.classList.add('open');
      syncExpanded(group);
      group.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('nav-search-hidden');
        if (item.classList.contains('nav-group')) {
          item.classList.add('open');
          syncExpanded(item);
        }
      });
    };
    const runModuleSearch = () => {
      const query = moduleSearch.value.trim().toLowerCase();
      moduleSearch.closest('.nav-search')?.classList.toggle('has-value', !!query);
      nav.classList.toggle('nav-searching', !!query);
      document.body.classList.toggle('sidebar-searching', !!query && document.body.classList.contains('sidebar-collapsed'));
      if (!query) {
        groups.forEach(group => {
          if (group.dataset.searchOpen !== undefined) group.classList.toggle('open', group.dataset.searchOpen === '1');
          delete group.dataset.searchOpen;
          syncExpanded(group);
        });
        items.forEach(item => item.classList.remove('nav-search-hidden'));
        if (emptyMessage) emptyMessage.hidden = true;
        return;
      }
      groups.forEach(group => {
        if (group.dataset.searchOpen === undefined) group.dataset.searchOpen = group.classList.contains('open') ? '1' : '0';
      });
      items.forEach(item => item.classList.add('nav-search-hidden'));
      items.forEach(item => {
        const label = (item.dataset.navLabel || '').trim().toLowerCase();
        if (!label.includes(query)) return;
        if (item.classList.contains('nav-group')) revealGroupContents(item);
        else revealAncestors(item);
      });
      if (emptyMessage) emptyMessage.hidden = !$$('.nav-link-item:not(.nav-search-hidden)', nav).length;
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
  document.addEventListener('keydown', e => {
    if (e.key === 'Tab' && document.body.classList.contains('nav-open') && sidebar) {
      const focusable = $$('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', sidebar)
        .filter(element => element.getClientRects().length > 0 && !element.closest('[inert]'));
      if (!focusable.length) {
        e.preventDefault();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!sidebar.contains(document.activeElement)) {
        e.preventDefault();
        (e.shiftKey ? last : first).focus();
      } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
      return;
    }
    if (e.key !== 'Escape') return;
    if (document.body.classList.contains('sidebar-collapsed') && !document.body.classList.contains('sidebar-searching') && window.innerWidth > 860 && nav) {
      let group = document.activeElement.closest('.nav-item.nav-group');
      while (group && group.parentElement !== nav) group = group.parentElement?.closest('.nav-item.nav-group');
      if (group) {
        group.classList.add('flyout-dismissed');
        const trigger = group.querySelector(':scope > button');
        trigger?.setAttribute('aria-expanded', 'false');
        trigger?.focus({ preventScroll: true });
        trigger?.setAttribute('aria-expanded', 'false');
        syncFlyoutDescendants(group, false);
        e.preventDefault();
        return;
      }
    }
    $$('.modal.show').forEach(m => m.classList.remove('show'));
    if (document.body.classList.contains('nav-open')) setMobileNavOpen(false, true);
  });

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
