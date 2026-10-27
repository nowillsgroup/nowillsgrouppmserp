<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', 'Partner Portal') · {{ $hotel->name }}</title>
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="ptn-body">
@include('partials.icons')
@php
  $links = [['partner.home', 'Home', 'home', true], ['partner.book', 'Book a stay', 'calendar', $partner->can('book')], ['partner.bookings', 'Bookings', 'book', true],
    ['partner.requests', 'Requests', 'message', true], ['partner.statement', 'Statement', 'receipt', $partner->can('finance')],
    ['partner.commissions', 'Commissions', 'wallet', $company->type === 'travel_agent' && $partner->can('finance')], ['partner.users', 'Users', 'users', $partner->can('users')]];
@endphp
<header class="ptn-top">
  <div class="ptn-wrap ptn-bar">
    <a class="ptn-brand" href="{{ route('partner.home') }}"><span class="brand-mark">{{ strtoupper(substr($hotel->name, 0, 1)) }}</span><span>{{ $hotel->name }}<small>{{ $company->type === 'travel_agent' ? 'TRAVEL AGENT' : 'CORPORATE' }} PARTNER PORTAL</small></span></a>
    <div class="ptn-who">
      <span class="ptn-co"><x-icon name="building" size="sm"/> {{ $company->name }}</span>
      <a href="{{ route('partner.profile') }}" class="ptn-user" id="ptnProfile"><span class="avatar">{{ strtoupper(substr($partner->name, 0, 1)) }}</span><span><b>{{ $partner->name }}</b><small>{{ \App\Models\PartnerUser::ROLES[$partner->role] }}</small></span></a>
      <form method="post" action="{{ route('partner.logout') }}">@csrf<button class="btn btn-ghost btn-sm" type="submit" id="ptnLogout"><x-icon name="logout" size="sm"/> Sign out</button></form>
    </div>
  </div>
  <nav class="ptn-wrap ptn-nav" id="ptnNav">
    @foreach($links as [$rt, $label, $ico, $show])
      @if($show)<a href="{{ route($rt) }}" class="{{ request()->routeIs(...(['partner.book' => ['partner.book', 'partner.book.*'], 'partner.bookings' => ['partner.bookings', 'partner.booking', 'partner.booking.*'], 'partner.statement' => ['partner.statement', 'partner.invoice'], 'partner.requests' => ['partner.requests*'], 'partner.users' => ['partner.users*']][$rt] ?? [$rt])) ? 'active' : '' }}"><x-icon :name="$ico" size="sm"/> {{ $label }}</a>@endif
    @endforeach
  </nav>
</header>
<main class="ptn-wrap ptn-main">
  <div class="page-head">
    <div>@hasSection('crumbs')<div class="crumbs">@yield('crumbs')</div>@endif<h1>@yield('title', 'Partner Portal')</h1></div>
    <div class="actions">@yield('actions')</div>
  </div>
  @include('partials.flash')
  @yield('content')
</main>
<footer class="ptn-wrap ptn-foot">
  <span>{{ $hotel->name }}{{ $hotel->phone ? ' · '.$hotel->phone : '' }}{{ $hotel->email ? ' · '.$hotel->email : '' }}</span>
  <span>Signed in as {{ $partner->email }} · sessions end after {{ setting('partner_idle', 30) }} minutes without activity</span>
</footer>
<script src="{{ asset('js/app.js') }}?v={{ config('pms.version') }}"></script>
@stack('scripts')
</body>
</html>
