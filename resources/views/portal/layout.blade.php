<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer"><meta name="theme-color" content="#10271c">
<title>@yield('title', 'My stay') · {{ $hotel->name }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<style>
body{background:#f4f6fb;padding-bottom:calc(5.2rem + env(safe-area-inset-bottom))}
.gp-head{background:linear-gradient(135deg,#10271c,#166534);color:#fff;padding:1.1rem 1rem 2.6rem}
.gp-head .top{display:flex;justify-content:space-between;align-items:center;gap:.6rem;max-width:720px;margin:0 auto}
.gp-head .brand{font-weight:700;font-size:1.05rem;display:flex;align-items:center;gap:.5rem}
.gp-head .brand img{height:28px;border-radius:6px;background:#fff;padding:2px}
.gp-head .pill{background:rgba(255,255,255,.18);border-radius:999px;padding:.2rem .7rem;font-size:.8rem;white-space:nowrap}
.gp-head h1{font-size:1.45rem;margin:.9rem auto 0;max-width:720px}
.gp-head .sub{opacity:.85;font-size:.85rem;max-width:720px;margin:.15rem auto 0}
.gp-wrap{max-width:720px;margin:-1.7rem auto 0;padding:0 .8rem}
.gp-card{background:#fff;border:1px solid var(--c-border);border-radius:14px;padding:1rem;margin-bottom:.8rem;box-shadow:0 2px 8px rgba(15,23,42,.04)}
.gp-card h3{font-size:1rem;margin:0 0 .6rem}
.gp-tiles{display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem;margin-bottom:.8rem}
.gp-tile{background:#fff;border:1px solid var(--c-border);border-radius:14px;padding:.9rem;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:.35rem;min-height:92px}
.gp-tile .ic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#f0fdf4;color:#166534}
.gp-tile b{font-size:.95rem}.gp-tile span{font-size:.78rem;color:#64748b}
.gp-kv{display:flex;justify-content:space-between;gap:1rem;padding:.4rem 0;border-bottom:1px dashed #e2e8f0;font-size:.9rem}.gp-kv:last-child{border:0}
.gp-kv span:first-child{color:#64748b}
.gp-nav{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--c-border);display:flex;justify-content:space-around;padding:.35rem .3rem calc(.35rem + env(safe-area-inset-bottom));z-index:40;box-shadow:0 -4px 16px rgba(15,23,42,.06)}
.gp-nav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:.1rem;font-size:.7rem;color:#64748b;text-decoration:none;padding:.3rem 0;position:relative;max-width:110px}
.gp-nav a.on{color:#166534;font-weight:700}
.gp-nav .dot{position:absolute;top:0;right:calc(50% - 20px);background:#dc2626;color:#fff;border-radius:999px;font-size:.62rem;min-width:16px;height:16px;display:grid;place-items:center;padding:0 3px}
.st{font-size:.72rem;padding:.12rem .5rem;border-radius:999px;white-space:nowrap;font-weight:600}
.st.new,.st.pending{background:#fef3c7;color:#92400e}.st.acknowledged,.st.booked{background:#e0e7ff;color:#3730a3}.st.in_progress{background:#dbeafe;color:#1e40af}
.st.done,.st.accepted,.st.completed{background:#dcfce7;color:#166534}.st.declined,.st.cancelled,.st.no_show{background:#fee2e2;color:#991b1b}
.gp-row{display:flex;justify-content:space-between;align-items:flex-start;gap:.6rem;padding:.65rem 0;border-bottom:1px solid #f1f5f9}.gp-row:last-child{border:0}
.gp-chips{display:flex;flex-wrap:wrap;gap:.4rem}
.gp-chip{border:1px solid #bbf7d0;background:#f0fdf4;color:#14532d;border-radius:999px;padding:.4rem .8rem;font-size:.85rem;cursor:pointer}
.gp-chip.on,.gp-chip:has(input:checked){background:#166534;color:#fff;border-color:#166534}
.gp-chip input{display:none}
.stars{display:inline-flex;flex-direction:row-reverse;gap:.15rem}.stars input{display:none}
.stars label{font-size:1.6rem;color:#cbd5e1;cursor:pointer;line-height:1}.stars input:checked~label,.stars label:hover,.stars label:hover~label{color:#f59e0b}
.wifi-pass{font-family:ui-monospace,monospace;background:#f1f5f9;border-radius:6px;padding:.1rem .45rem}
</style><link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">
</head>
<body>@include('partials.icons')
<header class="gp-head">
  <div class="top"><div class="brand">@if($hotel->logo_path)<img src="{{ asset($hotel->logo_path) }}" alt="">@endif{{ $hotel->name }}</div>
    @if($stay->room)<span class="pill">Room {{ $stay->room->room_no }}</span>@endif</div>
  <h1>@yield('heading', 'Hello'.($guest?->first_name ? ', '.$guest->first_name : ''))</h1>
  <div class="sub">@yield('sub', $stay->arrival_date->format('D d M').' → '.$stay->departure_date->format('D d M Y'))</div>
</header>
<main class="gp-wrap">
@if(session('success'))<div class="alert alert-success"><x-icon name="check"/><div>{{ session('success') }}</div></div>@endif
@if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
@yield('content')
<p class="center tiny muted mt-2">{{ $hotel->name }}{{ $hotel->phone ? ' · '.$hotel->phone : '' }} · Powered by Nowills PMS<br>This page is private to your stay – please don't share the link.</p>
</main>
@if(in_array($state, ['inhouse', 'upcoming', 'departed']))
<nav class="gp-nav">
  <a href="{{ route('portal.home', $token) }}" class="{{ $tab === 'home' ? 'on' : '' }}"><x-icon name="home"/>Home</a>
  <a href="{{ route('portal.requests', $token) }}" class="{{ $tab === 'requests' ? 'on' : '' }}"><x-icon name="bell"/>Requests @if($openRequests)<span class="dot">{{ $openRequests }}</span>@endif</a>
  @if($state === 'inhouse' && $diningOn)<a href="{{ route('portal.dining', $token) }}" class="{{ $tab === 'dining' ? 'on' : '' }}"><x-icon name="cup"/>Dining</a>@endif
  @if($state === 'inhouse' && $spaOn)<a href="{{ route('portal.spa', $token) }}" class="{{ $tab === 'spa' ? 'on' : '' }}"><x-icon name="spa"/>Spa</a>@endif
  @if(in_array($state, ['inhouse', 'upcoming']) && ($transportOn ?? false))<a href="{{ route('portal.transport', $token) }}" class="{{ $tab === 'transport' ? 'on' : '' }}" id="gpNavTransport"><x-icon name="car"/>Transport</a>@endif
  @if($chatOn ?? false)<a href="{{ route('portal.chat', $token) }}" class="{{ $tab === 'chat' ? 'on' : '' }}" id="gpNavChat"><x-icon name="message"/>Chat @if($chatUnread ?? 0)<span class="dot">{{ $chatUnread }}</span>@endif</a>@endif
  <a href="{{ route('portal.bill', $token) }}" class="{{ $tab === 'bill' ? 'on' : '' }}"><x-icon name="receipt"/>My bill</a>
</nav>
@endif
<script>document.querySelectorAll('form').forEach(f => f.addEventListener('submit', () => f.querySelectorAll('button[type=submit],button:not([type])').forEach(b => setTimeout(() => b.disabled = true))));</script>
@stack('scripts')
</body></html>
