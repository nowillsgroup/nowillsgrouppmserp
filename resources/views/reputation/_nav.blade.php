@php $ra = \App\Services\FeedbackService::alerts(); @endphp
<div class="tabs" id="repNav">
  @if(can_do('feedback.view'))
  <a href="{{ route('rep.index') }}" class="{{ request()->routeIs('rep.index') ? 'active' : '' }}">Overview</a>
  <a href="{{ route('feedback.index') }}" class="{{ request()->routeIs('feedback.index', 'rep.responses.*') ? 'active' : '' }}">Guest feedback</a>
  @endif
  @if(can_do('feedback.cases'))<a href="{{ route('rep.cases.index') }}" class="{{ request()->routeIs('rep.cases.*') ? 'active' : '' }}">Guest cases @if($ra['open'])<span class="badge {{ $ra['overdue'] ? 'badge-red' : 'badge-amber' }}">{{ $ra['open'] }}</span>@endif</a>@endif
  @if(can_do('feedback.view'))
  <a href="{{ route('rep.reviews.index') }}" class="{{ request()->routeIs('rep.reviews.*') ? 'active' : '' }}">Online reviews @if($ra['reviews_waiting'] + $ra['reviews_pending'])<span class="badge badge-red">{{ $ra['reviews_waiting'] + $ra['reviews_pending'] }}</span>@endif</a>
  <a href="{{ route('rep.surveys.index') }}" class="{{ request()->routeIs('rep.surveys.*', 'rep.invitations') ? 'active' : '' }}">Surveys</a>
  @endif
  @if(can_do('feedback.manage'))<a href="{{ route('rep.settings') }}" class="{{ request()->routeIs('rep.settings') ? 'active' : '' }}">Settings</a>@endif
</div>
