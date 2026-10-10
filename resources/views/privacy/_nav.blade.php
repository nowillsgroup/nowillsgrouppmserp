@php $pa = \App\Services\PrivacyService::alerts(); @endphp
<div class="tabs" id="privacyNav">
  <a href="{{ route('privacy.index') }}" class="{{ request()->routeIs('privacy.index', 'privacy.requests.*') ? 'active' : '' }}">Requests @if($pa['overdue'] + $pa['due_soon'])<span class="badge {{ $pa['overdue'] ? 'badge-red' : 'badge-amber' }}">{{ $pa['overdue'] + $pa['due_soon'] }}</span>@endif</a>
  <a href="{{ route('privacy.breaches.index') }}" class="{{ request()->routeIs('privacy.breaches.*') ? 'active' : '' }}">Breach register @if($pa['breach'])<span class="badge badge-red">{{ $pa['breach'] }}</span>@endif</a>
  <a href="{{ route('privacy.retention') }}" class="{{ request()->routeIs('privacy.retention') ? 'active' : '' }}">Retention</a>
  <a href="{{ route('privacy.access') }}" class="{{ request()->routeIs('privacy.access') ? 'active' : '' }}">PII access log</a>
  <a href="{{ route('privacy.public', hotel()->code) }}" target="_blank" rel="noopener">Public privacy page ↗</a>
</div>
