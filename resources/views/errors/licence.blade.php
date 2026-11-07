@extends('layouts.app')
@section('title', $module.' is not licensed')
@section('crumbs')<a href="{{ route('dashboard') }}">Dashboard</a> / Licence@endsection
@section('content')

<div class="card">
  <div class="card-head">
    <h3>{{ $module }} is not licensed here</h3>
    <span class="badge badge-slate">Refused at the route</span>
  </div>
  <div class="card-body">
    <p class="small">
      You hold the permission this screen needs — <span class="mono">{{ $permission }}</span> — so this is
      not a question of your rights. The module behind it is not on the licence that applies where you
      are working.
    </p>

    @if($groupHoldsIt)
      <div class="alert alert-warn" style="margin-top:8px">
        <x-icon name="alert" />
        <div>
          <b>Your group is licensed for {{ $module }}.</b>
          {{ $property ? $property->name : 'The property you are working in' }} has switched it off for itself.
          A property may run fewer modules than its group, and this one does.
          <div class="tiny muted" style="margin-top:4px">Ask the group administrator who runs {{ $property ? $property->name : 'this property' }} — the change is on the property's own page, and it does not affect any other property in the group.</div>
        </div>
      </div>
    @else
      <div class="alert alert-warn" style="margin-top:8px">
        <x-icon name="alert" />
        <div>
          <b>{{ $module }} is not on your group's licence.</b>
          That is a commercial decision, not an operational one: no screen in this group can change it.
          <div class="tiny muted" style="margin-top:4px">Ask your group administrator to take it up with the platform administrator, who sets a tenant's licence on the group account.</div>
        </div>
      </div>
    @endif

    <div class="grid grid-3 mt-2">
      <div class="card"><div class="card-body">
        <b class="small">Nothing has been deleted</b>
        <p class="tiny muted">Switching a module off hides its screens and refuses its URLs. The rows behind it stay exactly where they were, so licensing it again restores the work rather than recovering it.</p>
      </div></div>
      <div class="card"><div class="card-body">
        <b class="small">{{ $property ? 'This property only' : 'This tenant only' }}</b>
        <p class="tiny muted">{{ $property ? 'The rest of the group keeps the module. Only '.$property->name.' refuses it.' : 'Another tenant of the platform is unaffected — licences are per group.' }}</p>
      </div></div>
      <div class="card"><div class="card-body">
        <b class="small">The guest side follows too</b>
        <p class="tiny muted">The guest pages, the kiosk and TV screens and the REST API endpoints of a module are refused with it — this is the same licence, not a separate switch.</p>
      </div></div>
    </div>

    <div class="flex gap-1 mt-2" style="flex-wrap:wrap">
      <a class="btn" href="{{ route('dashboard') }}"><x-icon name="home" size="sm"/> Back to the dashboard</a>
      @if($property && can_do('properties.view'))
        <a class="btn" href="{{ route('properties.show', $property) }}"><x-icon name="building" size="sm"/> Open {{ $property->name }}</a>
      @endif
      @if(can_do('group.dashboard'))
        <a class="btn" href="{{ route('group.account') }}"><x-icon name="key" size="sm"/> The group's licence</a>
      @endif
    </div>
  </div>
</div>

@endsection
