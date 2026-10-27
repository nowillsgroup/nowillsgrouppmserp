<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', 'Organiser Portal') · {{ $hotel->name }}</title>
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="ptn-body">
@include('partials.icons')
@php
  $links = [['organiser.home', 'Home', 'home', true], ['organiser.events', 'My events', 'calendar', true], ['organiser.requests', 'Requests', 'message', $organiser->can('events')], ['organiser.users', 'Users', 'users', $organiser->can('users')]];
@endphp
<header class="ptn-top" style="background:#10271c">
  <div class="ptn-wrap ptn-bar">
    <a class="ptn-brand" href="{{ route('organiser.home') }}"><span class="brand-mark">{{ strtoupper(substr($hotel->name, 0, 1)) }}</span><span>{{ $hotel->name }}<small>EVENT ORGANISER PORTAL</small></span></a>
    <div class="ptn-who">
      <span class="ptn-co"><x-icon name="building" size="sm"/> {{ $company?->name ?? 'Private client' }}</span>
      <a href="{{ route('organiser.profile') }}" class="ptn-user" id="orgProfile"><span class="avatar">{{ strtoupper(substr($organiser->name, 0, 1)) }}</span><span><b>{{ $organiser->name }}</b><small>{{ \App\Models\OrganiserUser::ROLES[$organiser->role] }}</small></span></a>
      <form method="post" action="{{ route('organiser.logout') }}">@csrf<button class="btn btn-ghost btn-sm" type="submit" id="orgLogout"><x-icon name="logout" size="sm"/> Sign out</button></form>
    </div>
  </div>
  <nav class="ptn-wrap ptn-nav" id="orgNav">
    @foreach($links as [$rt, $label, $ico, $show])
      @if($show)<a href="{{ route($rt) }}" class="{{ request()->routeIs(...(['organiser.events' => ['organiser.events', 'organiser.event', 'organiser.beo', 'organiser.attendees'], 'organiser.requests' => ['organiser.requests*'], 'organiser.users' => ['organiser.users*']][$rt] ?? [$rt])) ? 'active' : '' }}"><x-icon :name="$ico" size="sm"/> {{ $label }}</a>@endif
    @endforeach
  </nav>
</header>
<main class="ptn-wrap ptn-main">
  <div class="page-head">
    <div>@hasSection('crumbs')<div class="crumbs">@yield('crumbs')</div>@endif<h1>@yield('title', 'Organiser Portal')</h1></div>
    <div class="actions">@yield('actions')</div>
  </div>
  @include('partials.flash')
  @yield('content')
</main>
<footer class="ptn-wrap ptn-foot">
  <span>{{ $hotel->name }}{{ $hotel->phone ? ' · '.$hotel->phone : '' }}{{ $hotel->email ? ' · '.$hotel->email : '' }}</span>
  <span>Signed in as {{ $organiser->email }} · sessions end after {{ setting('organiser_idle', 30) }} minutes without activity</span>
</footer>
<script src="{{ asset('js/app.js') }}?v={{ config('pms.version') }}"></script>
@stack('scripts')
</body>
</html>
