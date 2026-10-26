@extends('layouts.app')
@section('title', 'Notifications')
@section('crumbs') Home / Notifications @endsection

@section('actions')
  <a class="btn" href="{{ route('dashboard') }}"><x-icon name="home"/> Dashboard</a>
@endsection

@section('content')
{{-- v7.55: everything waiting for you, grouped by the page that clears it.
     There is no "mark as read" on purpose — the list is built from live data,
     so fixing the arrival / closing the request is what removes the row. --}}
<div class="page-intro">
  <p class="small text-2">
    @if($total)
      <b>{{ $total }}</b> item{{ $total === 1 ? '' : 's' }} need{{ $total === 1 ? 's' : '' }} attention.
      Only what your permissions let you act on is shown.
    @else
      Nothing needs you right now.
    @endif
  </p>
</div>

@forelse($groups as $group)
  <section class="card notify-card" data-notify="{{ $group['key'] }}">
    <div class="card-head">
      <h3>
        <span class="top-tool-icon top-tool-icon-{{ $group['tone'] === 'red' ? 'amber' : $group['tone'] }}"><x-icon :name="$group['icon']" size="sm"/></span>
        {{ $group['label'] }}
        <span class="badge badge-{{ $group['tone'] === 'red' ? 'red' : ($group['tone'] === 'amber' ? 'amber' : 'blue') }}">{{ $group['count'] }}</span>
      </h3>
      <div class="notify-head-side">
        <span class="tiny text-2">{{ $group['hint'] }}</span>
        @if($group['url'])<a class="btn btn-sm" href="{{ $group['url'] }}">Open <x-icon name="chev" size="sm"/></a>@endif
      </div>
    </div>
    <div class="card-body flush">
      @if($group['items'])
        <ul class="notify-list">
          @foreach($group['items'] as $item)
            <li>
              <span class="notify-item-main">
                <b>{{ $item['text'] }}</b>
                @if($item['meta'])<span class="tiny text-2">{{ $item['meta'] }}</span>@endif
              </span>
              @if($item['url'])<a class="notify-go" href="{{ $item['url'] }}" aria-label="Open {{ $item['text'] }}"><x-icon name="chev" size="sm"/></a>@endif
            </li>
          @endforeach
        </ul>
        @if($group['count'] > count($group['items']))
          <p class="notify-more small text-2">
            … and {{ $group['count'] - count($group['items']) }} more
            @if($group['url'])<a href="{{ $group['url'] }}">on the {{ $group['label'] }} screen</a>@endif
          </p>
        @endif
      @else
        <p class="notify-more small text-2">{{ $group['count'] }} waiting @if($group['url'])<a href="{{ $group['url'] }}">— open the screen</a>@endif</p>
      @endif
    </div>
  </section>
@empty
  <div class="card">
    <div class="card-body notify-empty">
      <span class="notify-empty-icon"><x-icon name="check"/></span>
      <h3>You are all caught up</h3>
      <p class="small text-2">
        No arrivals, departures, open guest requests or engineering tickets are waiting on you.
        This page fills up as the day moves; there is nothing to dismiss.
      </p>
      <a class="btn" href="{{ route('dashboard') }}"><x-icon name="grid"/> Back to the dashboard</a>
    </div>
  </div>
@endforelse
@endsection
