@extends('layouts.app')
@section('title', 'Configuration — what it was before')
@section('crumbs') Administration / <a href="{{ route('configuration.index') }}">Configuration</a> / <b>Value trail</b> @endsection
@section('actions')
  <a class="btn btn-soft" href="{{ route('configuration.index') }}"><x-icon name="grid"/> The register</a>
  <a class="btn btn-soft" href="{{ route('configuration.changed') }}"><x-icon name="swap"/> What this property changed</a>
  <a class="btn" href="{{ route('configuration.print') }}"><x-icon name="print"/> Print the register</a>
@endsection
@section('content')

<div class="grid g-4 mb-2 cfg-strip">
  <div class="stat"><span class="ico" style="background:#f0f4ff;color:#3b5bdb"><x-icon name="clock"/></span><div><div class="v">{{ number_format($trail['rows']) }}</div><div class="l">value changes recorded</div></div></div>
  <div class="stat"><span class="ico" style="background:#e6f7f2;color:#189b78"><x-icon name="settings"/></span><div><div class="v">{{ number_format($trail['keys']) }} of {{ number_format($trail['keys_total']) }}</div><div class="l">settings with a recorded value change</div></div></div>
  <div class="stat"><span class="ico" style="background:#fff2df;color:#ec8c20"><x-icon name="calendar"/></span><div><div class="v">{{ $trail['since'] ? $trail['since']->format('d M Y') : '—' }}</div><div class="l">the trail starts here</div></div></div>
  <div class="stat"><span class="ico" style="background:#f4eefe;color:#7c4dff"><x-icon name="info"/></span><div><div class="v">{{ number_format($coverage['changed']) }}</div><div class="l">settings currently off their built-in default</div></div></div>
</div>

<div class="card mb-2">
  <div class="card-body small">
    <p><b>The value trail records observed setting changes.</b> The activity log says a screen was saved and which
      settings it touched; it cannot say whether the free-cancellation window went from 48 hours to 24 or from 48 to 72.
      This page shows changes recorded by the setting model on saves and deletes. Bulk database updates or deletes
      bypass model events and may not appear; earlier missing entries cannot be recovered.</p>
    <p class="muted">The trail begins with this release: earlier changes may appear in the activity log by label, but not
      by value, and some changes may not have been logged at all. Recording can also fail without blocking the original save. A value a seeder or a command wrote appears only when it replaced
      something — filling a new property in for the first time is provisioning, not a decision. A credential records that
      it changed and never what it is.</p>
    <p><b>One action on this page: put it back.</b> A value the property itself recorded can be restored — but only for
      the settings whose rules this area can check, which is the flat Settings form's typed, bounded keys. Everywhere else
      the module screen owns the rules, and the row says so rather than offering a button that would fail after the click.
      Values over 2,000 characters are truncated in the trail; those cannot be restored from this page. A restore writes through the setting model, so it lands in this same trail under the name of whoever did it.</p>
  </div>
</div>

@if($module)
<div class="card mb-2">
  <div class="card-head"><h3>{{ $module['label'] }}</h3><span class="badge badge-blue">this module only</span>
    <a class="btn btn-soft btn-sm" href="{{ route('configuration.history') }}" style="margin-left:auto">Every module</a></div>
  <div class="card-body small muted">Showing the value trail for {{ $module['label'] }}. <a href="{{ route('configuration.show', $module['key']) }}">Open the module's settings</a>.</div>
</div>
@endif

<div class="card flush mb-2">
  <div class="card-head"><h3>What it was before</h3><span class="badge badge-slate">{{ count($changes) }}</span></div>
  @if(count($changes))
    <div class="card-body table-wrap"><table class="table">
      <thead><tr><th>When</th><th>Module</th><th>Setting</th><th>Was</th><th>Now</th><th>By</th><th></th></tr></thead>
      <tbody>
      @foreach($changes as $c)
        <tr>
          <td class="small muted">{{ $c['when'] ? $c['when']->format('d M Y H:i') : '—' }}</td>
          <td class="small">
            @if($c['module'])
              <a href="{{ route('configuration.show', $c['module']) }}">{{ $c['module_label'] }}</a>
            @else
              {{ $c['module_label'] }}
            @endif
          </td>
          <td class="bold">{{ $c['label'] }} <code class="small muted">{{ $c['key'] }}</code></td>
          <td class="{{ $c['was'] === null ? 'muted' : '' }}">{{ $c['created'] ? 'nothing — it was not set' : ($c['was'] ?? 'cleared') }}</td>
          <td>{{ $c['now'] }}</td>
          <td class="small muted">{{ $c['by'] ?? 'not recorded' }}</td>
          <td class="right">
            @if($c['restore_ok'] && can_do('settings.manage'))
              <form method="post" action="{{ route('configuration.restore', $c['id']) }}">
                @csrf
                <button class="btn btn-soft btn-sm" title="Put {{ $c['label'] }} back to the value it had before this change">
                  <x-icon name="refresh" size="sm"/> Put it back
                </button>
              </form>
            @elseif($c['restore_ok'])
              <span class="tiny muted">Requires settings management permission</span>
            @else
              <span class="tiny muted" title="{{ $c['restore_blocked'] }}">{{ $c['restore_blocked'] }}</span>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
  @else
    <div class="card-body small muted">
      Nothing has been recorded yet{{ $module ? ' for '.$module['label'] : '' }}. The first time somebody changes a
      setting, the value it replaced is written here — the page will fill itself in.
    </div>
  @endif
</div>

<div class="card mb-2">
  <div class="card-body small muted">
    <b>Where the value came from:</b> this trail is the database side of a change. The story around it — who opened the
    screen, what else the same save touched, and the findings that sent them there — is on
    <a href="{{ route('configuration.index') }}">the register</a>, on
    <a href="{{ route('configuration.changed') }}">what this property decided</a>, and in each module's own activity log.
  </div>
</div>

@endsection
