<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="hotel-code" content="{{ $currentHotel->code ?? '' }}">
  <title>@yield('title', 'Dashboard') · {{ $currentHotel->name ?? config('app.name') }}</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%231d4ed8'/><text x='16' y='22' font-size='16' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>N</text></svg>">
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('pms.version') }}">
  <link rel="stylesheet" href="{{ asset('css/offline.css') }}?v={{ config('pms.version') }}">
  @stack('head')
</head>
<body>
@include('partials.icons')
@php
  $user = auth()->user();
  $menu = config('pms.menu');
  $myEmployee = $user ? \App\Models\Employee::where('user_id', $user->id)->where('is_active', true)->exists() : false;
  // v7.55 notification centre: derived from live data, permission-scoped, built once
  // per request (the page body and the bell share the same cached array).
  $alerts = $user ? \App\Services\NotificationCenter::forUser($user) : [];
  $alertTotal = \App\Services\NotificationCenter::total($alerts);
  $alertPreview = \App\Services\NotificationCenter::preview($alerts);
@endphp
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">N</div>
      <div class="brand-copy"><strong>NOWILLS PMS</strong><small>PROPERTY MANAGEMENT SYSTEM</small></div>
      <button type="button" class="sidebar-collapse" data-collapse-sidebar aria-label="Collapse sidebar" title="Collapse sidebar"><x-icon name="chev"/></button>
    </div>
    <label class="nav-search" for="navModuleSearch">
      <x-icon name="search" size="sm"/>
      <input id="navModuleSearch" type="search" placeholder="Search modules…" autocomplete="off" aria-label="Search modules">
      <button type="button" class="nav-search-clear" data-clear-nav-search aria-label="Clear module search" title="Clear search">×</button>
    </label>
    @php
    $isActive = function (array $item) use (&$isActive): bool {
        if (isset($item['route'])) {
            return request()->url() === route($item['route'], $item['params'] ?? []);
        }

        foreach ($item['children'] ?? [] as $child) {
            if ($isActive($child)) {
                return true;
            }
        }

        return false;
    };
    $canSee = function (array $item) use (&$canSee, $user, $myEmployee): bool {
        if (($item['employee'] ?? false) && ! $myEmployee) {
            return false;
        }

        if (! empty($item['children'])) {
            foreach ($item['children'] as $child) {
                if ($canSee($child)) {
                    return true;
                }
            }

            return false;
        }

        if (empty($item['perm'])) {
            return true;
        }

        return collect(explode(',', $item['perm']))->contains(fn ($permission) => $user->hasPermission(trim($permission)));
    };
  @endphp
  <nav class="nav" aria-label="Primary navigation">
    @include('partials.sidebar-nav', ['items' => $menu, 'isActive' => $isActive, 'canSee' => $canSee])
  </nav>
    <div class="side-profile">
      <a href="{{ route('profile') }}" title="Open profile">
        <div class="avatar">{{ strtoupper(substr($user->name, 0, 1)) }}{{ strtoupper(substr(strstr($user->name, ' ') ?: '', 1, 1)) }}</div>
        <span class="side-profile-copy"><b>{{ $user->name }}</b><small>{{ $user->is_super_admin ? 'Administrator' : ($user->role->name ?? 'User') }}</small></span>
      </a>
      <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" aria-label="Sign out" title="Sign out"><x-icon name="logout" size="sm"/></button>
      </form>
    </div>
    <div class="side-foot">v{{ config('pms.version') }} · {{ $currentHotel->code }}</div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="btn btn-ghost btn-icon menu-btn" data-toggle-nav aria-label="Menu"><x-icon name="menu"/></button>
      <form class="top-search" action="{{ route('search') }}" method="get" role="search">
        <x-icon name="search" size="sm"/>
        <input id="globalSearch" name="q" value="{{ request('q') }}" placeholder="Search guest, reservation, room, company, folio…  ( / )" autocomplete="off">
        <div id="search-suggest" class="search-suggest" hidden></div>
      </form>
      <div class="top-right">
        <nav class="top-tools" aria-label="Quick links">
          <button type="button" class="top-tool" id="app-theme" aria-label="Switch to dark mode" title="Switch to dark mode">
            <span class="top-tool-icon top-tool-icon-moon"><x-icon name="moon" size="sm"/></span>
          </button>
          <button type="button" class="top-tool" id="nav-fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">
            <span class="top-tool-icon top-tool-icon-blue"><x-icon name="expand" size="sm"/></span>
          </button>
          <div class="offline-indicator" data-offline-indicator role="status" aria-live="polite" title="Offline sync status">
            <span class="offline-indicator-dot" data-offline-dot></span><span data-offline-label>Online</span><span class="offline-indicator-pending" data-offline-pending></span>
          </div>
          <div class="top-notify">
            <button type="button" class="top-tool" data-dropdown="notifyMenu" aria-haspopup="true"
                    aria-label="Notifications{{ $alertTotal ? ' — '.$alertTotal.' waiting' : '' }}" title="Notifications">
              <span class="top-tool-icon top-tool-icon-blue"><x-icon name="bell" size="sm"/></span>
            </button>
            @if($alertTotal)<span class="notify-count" aria-hidden="true">{{ $alertTotal > 99 ? '99+' : $alertTotal }}</span>@endif
            <div class="dropdown dropdown-notify" id="notifyMenu" role="menu" aria-label="Notifications">
              <div class="notify-drop-head">
                <b>Notifications</b>
                <span class="tiny text-2">{{ $alertTotal ? $alertTotal.' waiting' : 'All clear' }}</span>
              </div>
              @forelse($alertPreview as $row)
                <a class="notify-drop-row" role="menuitem" href="{{ $row['url'] ?? route('notifications.index') }}">
                  <span class="notify-drop-icon notify-tone-{{ $row['tone'] }}"><x-icon :name="$row['icon']" size="sm"/></span>
                  <span class="notify-drop-copy"><b>{{ $row['text'] }}</b><span class="tiny text-2">{{ $row['meta'] }}</span></span>
                </a>
              @empty
                <p class="notify-drop-empty small text-2">Nothing needs you right now.</p>
              @endforelse
              <a class="notify-drop-all" role="menuitem" href="{{ route('notifications.index') }}">See all notifications →</a>
            </div>
          </div>
          @if(can_do('desk.messages'))<a class="top-tool" href="{{ route('desk.messages') }}" aria-label="Desk messages" title="Desk messages">
            <span class="top-tool-icon top-tool-icon-amber"><x-icon name="mail" size="sm"/></span>
          </a>@endif
          @if(can_do('inbox.view') || can_do('inbox.reply'))<a class="top-tool" href="{{ route('inbox.index') }}" aria-label="Guest inbox" title="Guest inbox">
            <span class="top-tool-icon top-tool-icon-purple"><x-icon name="message" size="sm"/></span>
          </a>@endif
          @if(can_do('banquet.view'))<a class="top-tool" href="{{ route('banquet.calendar') }}" aria-label="Banquet calendar" title="Banquet calendar">
            <span class="top-tool-icon top-tool-icon-teal"><x-icon name="calendar" size="sm"/></span>
          </a>@endif
        </nav>
        <span class="bdate" title="Business (audit) date"><x-icon name="calendar" size="sm"/> {{ bdate()->format('D, d M Y') }}</span>
        @php $hotels = $user->accessibleHotels(); @endphp
        @if($hotels->count() > 1)
          <form class="hotel-switch" method="post" action="{{ route('switch.hotel') }}">@csrf
            <select name="hotel_id" onchange="this.form.submit()" aria-label="Switch property">
              @foreach($hotels as $h)<option value="{{ $h->id }}" @selected($h->id === $currentHotel->id)>{{ $h->name }}</option>@endforeach
            </select>
          </form>
        @else
          <span class="small text-2 hotel-switch"><x-icon name="hotel" size="sm"/> {{ $currentHotel->name }}</span>
        @endif
        <div class="user-chip" data-dropdown="userMenu">
          <div class="avatar">{{ strtoupper(substr($user->name, 0, 1)) }}{{ strtoupper(substr(strstr($user->name, ' ') ?: '', 1, 1)) }}</div>
          <div class="meta"><b>{{ $user->name }}</b><span>{{ $user->is_super_admin ? 'Administrator' : ($user->role->name ?? 'User') }}</span></div>
          <div class="dropdown" id="userMenu">
            <a href="{{ route('profile') }}"><x-icon name="user" size="sm"/> My Profile</a>
            @if(can_do('settings.manage'))<a href="{{ route('settings.index') }}"><x-icon name="building" size="sm"/> Hotel Settings</a>@endif
            <form method="post" action="{{ route('logout') }}">@csrf<button type="submit"><x-icon name="logout" size="sm"/> Sign out</button></form>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
      <div class="page-head">
        <div>
          @hasSection('crumbs')<div class="crumbs">@yield('crumbs')</div>@endif
          <h1>@yield('title', 'Dashboard')</h1>
        </div>
        <div class="actions">@yield('actions')</div>
      </div>
      @include('partials.flash')
      @yield('content')
    </main>
  </div>
</div>
<script src="{{ asset('js/app.js') }}?v={{ config('pms.version') }}"></script>
<script src="{{ asset('js/offline-sync.js') }}?v={{ config('pms.version') }}"></script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('{{ asset('sw.js') }}', { updateViaCache: 'none' }).catch(() => {}));
}
</script>
<script>
(function () {
  const input = document.getElementById('globalSearch');
  const box = document.getElementById('search-suggest');
  if (!input || !box) return;
  let timer = null;
  const url = @json(route('search.suggest'));
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
    timer = setTimeout(() => {
      fetch(url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
          const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
          const groups = [['Guests', data.guests], ['Reservations', data.reservations], ['Rooms', data.rooms], ['Companies', data.companies], ['Folios', data.folios], ['Waitlist', data.waitlist]];
          const html = groups.filter(([, rows]) => rows && rows.length).map(([title, rows]) =>
            '<div class="sg-title">' + esc(title) + '</div>' + rows.map(row => '<a href="' + esc(row.url) + '"><b>' + esc(row.label) + '</b><small>' + esc(row.meta) + '</small></a>').join('')
          ).join('');
          box.innerHTML = html || '<div class="sg-title">No matches</div>';
          box.hidden = false;
        }).catch(() => { box.hidden = true; });
    }, 200);
  });
  document.addEventListener('click', e => { if (!e.target.closest('.top-search')) box.hidden = true; });
})();
(function () {
  const key = 'nowills-theme';
  const saved = localStorage.getItem(key) || localStorage.getItem('nowills-desk-theme');
  const dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
  const apply = mode => {
    document.body.classList.toggle('dark', mode === 'dark');
    document.getElementById('desk')?.classList.toggle('dark', mode === 'dark');
    // The bar is icon-only, so the current mode lives in the accessible name
    // (and the native tooltip) rather than in a visible word.
    const btn = document.getElementById('app-theme');
    if (btn) {
      const name = mode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      btn.setAttribute('aria-label', name);
      btn.title = name;
      btn.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
      // sun while dark (the next click brings light back), moon while light
      btn.querySelector('use')?.setAttribute('href', mode === 'dark' ? '#i-sun' : '#i-moon');
    }
  };
  apply(dark ? 'dark' : 'light');
  document.getElementById('app-theme')?.addEventListener('click', () => {
    const next = document.body.classList.contains('dark') ? 'light' : 'dark';
    localStorage.setItem(key, next);
    localStorage.setItem('nowills-desk-theme', next);
    apply(next);
  });
})();
</script>
<script>
  // Global fullscreen toggle (header bar)
  document.getElementById('nav-fullscreen')?.addEventListener('click', () => {
    if (!document.fullscreenElement) document.documentElement.requestFullscreen?.(); else document.exitFullscreen?.();
  });
</script>
@stack('scripts')
</body>
</html>
