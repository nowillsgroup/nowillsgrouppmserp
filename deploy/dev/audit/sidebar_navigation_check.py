#!/usr/bin/env python3
"""Static regression checks for the application sidebar and its UI-only preview."""
from collections import Counter
from html.parser import HTMLParser
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[3]
LAYOUT = (ROOT / 'resources/views/layouts/app.blade.php').read_text()
CONFIG = (ROOT / 'config/pms.php').read_text()
NAV = (ROOT / 'resources/views/partials/sidebar-nav.blade.php').read_text()
CSS = (ROOT / 'public/css/app.css').read_text()
JS = (ROOT / 'public/js/app.js').read_text()
SERVER = (ROOT / 'deploy/dev/serve.mjs').read_text()
PREVIEW = (ROOT / 'deploy/dev/previews/sidebar-navigation.html').read_text()
checks = []


def check(label: str, ok: bool) -> None:
    checks.append((label, bool(ok)))


class PreviewParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.ids: list[str] = []
        self.controls: list[str] = []

    def handle_starttag(self, tag, attrs) -> None:
        attrs = dict(attrs)
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        if 'aria-controls' in attrs:
            self.controls.append(attrs['aria-controls'])


preview_parser = PreviewParser()
preview_parser.feed(PREVIEW)

# Product markup and permission-aware recursive navigation.
check('sidebar retains a named primary-navigation landmark', '<nav class="nav" aria-label="Primary navigation">' in LAYOUT)
check('desktop collapse control is preserved', 'data-collapse-sidebar' in LAYOUT)
check('collapse control exposes its controlled sidebar and expanded state', 'data-collapse-sidebar aria-controls="primary-sidebar" aria-expanded="true"' in LAYOUT and "collapseButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true')" in JS)
check('mobile drawer includes a labelled close control', 'class="sidebar-mobile-close" data-close-nav aria-label="Close navigation"' in LAYOUT)
check('sidebar is named for assistive technology', 'id="primary-sidebar" aria-label="Application sidebar"' in LAYOUT)
for short_label in ('Dashboard', 'Front Office', 'Finance', 'HR & Payroll', 'Administration'):
    check(f'top-level menu includes concise label {short_label}', f"['label' => '{short_label}'" in CONFIG)
for long_label in ('Dashboard & Operations', 'Front Office & Guest Services', 'Revenue & Financial Management', 'HR & Workforce', 'Administration & Configuration'):
    check(f'legacy long category label is removed: {long_label}', long_label not in CONFIG)
check('repeated parent and child labels are replaced with concise names', all(f"['label' => '{label}'" in CONFIG for label in ('Overview', 'Front Desk', 'Workforce', 'System Admin')))
check('UI preview reflects the shortened category and child labels', all(label in PREVIEW for label in ('Dashboard', 'Front Office', 'Finance', 'Overview', 'Front Desk', 'Workforce', 'System Admin')) and 'HR &amp; Payroll' in PREVIEW)
check('recursive renderer still filters every item through its permission gate', "@continue(! $canSee($item))" in NAV)
check('permission-filtered recursive navigation is unchanged', "@include('partials.sidebar-nav'" in NAV and '$user->hasPermission(trim($permission))' in LAYOUT)
check('current page continues to expose aria-current', 'aria-current="page"' in NAV)
check('menu group buttons continue to expose aria-expanded and aria-controls', 'aria-expanded="{{ $active ? \'true\' : \'false\' }}"' in NAV and 'aria-controls="{{ $key }}-children"' in NAV)
check('collapsed group clicks keep aria-expanded truthful while a fly-out remains visible', 'const flyoutVisible = document.body.classList.contains(\'sidebar-collapsed\')' in JS and "button.setAttribute('aria-expanded', flyoutVisible || open ? 'true' : 'false')" in JS)
check('collapsed hidden groups expose accurate expanded state and restore it when expanded', 'const expanded = collapsed && !searching' in JS and "button.setAttribute('aria-expanded', expanded ? 'true' : 'false')" in JS)
check('expanding or resizing out of desktop collapse clears fixed fly-out coordinates', "flyout.style.removeProperty('position')" in JS and "applySidebarMode(window.innerWidth > 860 && localStorage.getItem(sidebarKey) === '1')" in JS)
check('collapsed focusout preserves expanded state if the pointer still shows a fly-out', "const hoverVisible = group.matches(':hover');" in JS and "button.setAttribute('aria-expanded', hoverVisible ? 'true' : 'false')" in JS)
check('Escape dismisses collapsed keyboard fly-outs and returns focus to their trigger', "group.classList.add('flyout-dismissed')" in JS and "trigger?.focus({ preventScroll: true })" in JS and 'nav-item.nav-group:not(.flyout-dismissed):focus-within > .nav-sub' in CSS)

# Existing menu behaviors and the mobile drawer accessibility contract.
check('desktop collapsed state remains persisted', "'nowills-sidebar-collapsed'" in JS and 'localStorage.setItem(sidebarKey' in JS)
check('saved desktop collapse is applied before the sidebar markup paints', 'localStorage.getItem(\'nowills-sidebar-collapsed\') === \'1\'' in LAYOUT and LAYOUT.index('<body') < LAYOUT.index('localStorage.getItem(\'nowills-sidebar-collapsed\')') < LAYOUT.index('<div class="app">'))
check('module search still filters the navigation tree', 'const runModuleSearch = () =>' in JS and 'nav.classList.toggle(\'nav-searching\', !!query)' in JS)
check('closed mobile drawer is inert and hidden from assistive technology', 'sidebar.inert = mobileViewport && !open;' in JS and "sidebar.setAttribute('aria-hidden', open ? 'false' : 'true')" in JS)
check('opening the drawer focuses module search', "if (open) $('#navModuleSearch')?.focus({ preventScroll: true });" in JS)
check('mobile drawer traps forward and reverse Tab navigation', "e.key === 'Tab' && document.body.classList.contains('nav-open')" in JS and 'e.shiftKey && document.activeElement === first' in JS and '!e.shiftKey && document.activeElement === last' in JS)
check('Escape closes the drawer and returns focus', "if (document.body.classList.contains('nav-open')) setMobileNavOpen(false, true);" in JS and 'else if (returnFocus) navToggleButtons[0]?.focus();' in JS)
check('resize synchronizes drawer accessibility state', 'setMobileNavOpen(window.innerWidth > 860 ? false : open);' in JS)

# Responsive design, visual hierarchy and reduced-motion support.
check('mobile drawer close control appears at the navigation breakpoint', '@media (max-width: 860px)' in CSS and '.sidebar-mobile-close { display: grid; margin-left: auto; }' in CSS)
check('mobile drawer hides the desktop collapse control', '.sidebar-collapse { display: none !important; }' in CSS)
check('collapsed navigation fly-outs remain keyboard reachable', 'body.sidebar-collapsed .nav-item.nav-group:not(.flyout-dismissed):focus-within > .nav-sub' in CSS)
check('sidebar motion honors reduced-motion preference', '@media (prefers-reduced-motion: reduce)' in CSS and '.sidebar-mobile-close { transition-duration: .01ms !important; }' in CSS)
check('desktop navigation rail is slightly narrower while mobile drawer width is retained', CSS.count('--side-w: 244px') == 2 and '--side-w: 264px' not in CSS and '--side-w: 280px' in CSS)
check('collapsed-state handle protrudes visibly for easy expansion', 'body.sidebar-collapsed .sidebar-collapse { right: -14px;' in CSS and 'body.sidebar-collapsed .sidebar-collapse:hover' in CSS)
check('collapsed rail remains vertically scrollable with adjacent hover or focus fly-outs', 'body.sidebar-collapsed .nav { padding-left: .55rem; padding-right: .55rem; overflow-x: hidden; overflow-y: auto;' in CSS and 'body.sidebar-collapsed .nav-item.nav-group:not(.flyout-dismissed):focus-within > .nav-sub { display: block !important; position: fixed; left: 72px;' in CSS and 'flyout.style.left = `${sidebar?.getBoundingClientRect().right ?? 72}px`;' in JS)
check('nested fly-out groups stay inside the viewport-positioned parent panel', 'body.sidebar-collapsed .nav-item.nav-group:not(.flyout-dismissed):focus-within > .nav-sub .nav-sub { position: static; left: auto; top: auto;' in CSS)
check('collapsed fly-outs are kept within the viewport and repositioned while scrolling', "flyout.style.position = 'fixed';" in JS and 'nav.addEventListener(\'scroll\'' in JS and 'window.innerHeight - 8 - height' in JS)
check('collapsed search escapes the scroll rail then returns inside the expanded results rail', 'body.sidebar-collapsed .nav-search input:focus { color: #fff; position: fixed;' in CSS and 'body.sidebar-searching .nav-search input:focus { position: static;' in CSS)
check('content rail animates in sync with collapse and honors reduced motion', '.main { flex: 1; margin-left: var(--side-w); min-width: 0; display: flex; flex-direction: column; transition: margin-left .2s ease; }' in CSS and '.sidebar, .main, .sidebar-collapse' in CSS)
check('long menu labels stay on one line and truncate cleanly in the narrower rail', '.nav-item > a > .nav-label, .nav-item > button > .nav-label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }' in CSS)

# Preview is navigable, read-only and linked from the ordinary dev preview router.
check('preview shows the rail itself, with no notice about the preview on it', 'UI-only preview' not in PREVIEW and 'no operational records' not in PREVIEW and 'sidebar-preview-note' not in PREVIEW)
check('preview loads the actual application sidebar CSS and JavaScript', 'href="/css/app.css"' in PREVIEW and 'src="/js/app.js"' in PREVIEW)
check('expanded and collapsed preview routes are registered in the standard development router', "'/__preview/sidebar-navigation'" in SERVER and "'/__preview/sidebar-navigation-collapsed'" in SERVER and "'sidebar-navigation.html'" in SERVER)
check('both sidebar states are linked from the development changes index', 'Sidebar Navigation UI preview' in SERVER and 'Collapsed sidebar preview' in SERVER)
check('collapsed preview path initializes the compact rail before app JavaScript', "location.pathname.endsWith('/sidebar-navigation-collapsed')" in PREVIEW and "localStorage.setItem('nowills-sidebar-collapsed', '1')" in PREVIEW)
check('preview applies saved/collapsed state before shell markup to avoid a width flash', PREVIEW.index('<body>') < PREVIEW.index('localStorage.setItem') < PREVIEW.index('<div class="app">'))
check('preview has no duplicate IDs', not [key for key, count in Counter(preview_parser.ids).items() if count > 1])
check('all preview aria-controls values resolve to an element', all(value in preview_parser.ids for value in preview_parser.controls))

failed = [label for label, ok in checks if not ok]
for label, ok in checks:
    print(f"{'PASS' if ok else 'FAIL'}: {label}")
print(f"Sidebar navigation static audit: {len(checks) - len(failed)}/{len(checks)} passed.")
if failed:
    sys.exit(1)
