@extends('layouts.app')
@section('title', 'Configuration')
@section('crumbs') Administration / <b>Configuration</b> @endsection
@section('actions')
  <a class="btn btn-soft" href="{{ route('configuration.changed') }}"><x-icon name="swap"/> What this property changed</a>
  <a class="btn btn-soft" href="{{ route('configuration.on-null') }}"><x-icon name="info"/> What an unset setting runs on</a>
  <a class="btn btn-soft" href="{{ route('configuration.history') }}"><x-icon name="clock"/> What it was before</a>
  <a class="btn btn-soft" href="{{ route('configuration.handover') }}"><x-icon name="download"/> Handover sheet</a>
  <a class="btn" href="{{ route('configuration.print') }}"><x-icon name="print"/> Print the register</a>
  <a class="btn btn-soft" href="{{ route('configuration.csv') }}"><x-icon name="download"/> Excel / CSV</a>
  <a class="btn btn-soft" href="{{ route('settings.index') }}"><x-icon name="settings"/> Hotel settings</a>
@endsection
@section('content')

<div class="grid g-4 mb-2 cfg-strip">
  <div class="stat"><span class="ico" style="background:#e9efff;color:#5377ef"><x-icon name="grid"/></span><div><div class="v">{{ number_format($coverage['modules']) }}</div><div class="l">modules with settings</div></div></div>
  <div class="stat"><span class="ico" style="background:#e6f7f2;color:#189b78"><x-icon name="check"/></span><div><div class="v">{{ number_format($coverage['screens']) }}</div><div class="l">have their own screen</div></div></div>
  <div class="stat"><span class="ico" style="background:#eaf3ff;color:#267dca"><x-icon name="settings"/></span><div><div class="v">{{ number_format($coverage['keys']) }}</div><div class="l">settings tracked</div></div></div>
  <div class="stat"><span class="ico" style="background:#fff2df;color:#ec8c20"><x-icon name="alert"/></span><div><div class="v">{{ number_format($coverage['unset']) }}</div><div class="l">left on the built-in default</div></div></div>
  <div class="stat"><span class="ico" style="background:#f0f4ff;color:#3b5bdb"><x-icon name="swap"/></span><div><div class="v">{{ number_format($coverage['changed']) }}</div><div class="l">moved off the built-in default</div></div></div>
  <div class="stat"><span class="ico" style="background:#fdf1f1;color:#c0392b"><x-icon name="alert"/></span><div><div class="v">{{ number_format($coverage['guarded']) }} of {{ number_format($coverage['modules']) }}</div><div class="l">modules with at least one configuration check</div></div></div>
  <div class="stat"><span class="ico" style="background:#f4eefe;color:#7c4dff"><x-icon name="info"/></span><div><div class="v">{{ number_format($coverage['on_null']) }}</div><div class="l">read with no default to fall back on — <a href="{{ route('configuration.on-null') }}">what they run on</a></div></div></div>
  <div class="stat"><span class="ico" style="background:#eef7ff;color:#267dca"><x-icon name="clock"/></span><div><div class="v">{{ number_format($trail['rows']) }}</div><div class="l">value changes recorded — <a href="{{ route('configuration.history') }}">what it was before</a></div></div></div>
</div>

<form method="get" action="{{ route('configuration.index') }}" class="card mb-2">
  <div class="card-body cfg-search">
    <div class="field" style="flex:1;margin:0">
      <label for="cfg-q">Find a setting</label>
      <input id="cfg-q" name="q" value="{{ $q }}" placeholder="printer, deposit, VAT, overtime, spare key, retention…" autocomplete="off">
    </div>
    <button class="btn btn-primary"><x-icon name="search"/> Search the register</button>
    @if($q !== '')
      <a class="btn" href="{{ route('configuration.index') }}"><x-icon name="x"/> Clear</a>
    @endif
  </div>
</form>

@if($q !== '')
  <p class="small muted mb-2">{{ $matched }} {{ $matched === 1 ? 'module matches' : 'modules match' }} “{{ $q }}”. Results show the module and the setting that matched.</p>
@endif

@if(! $q && count($gaps))
<div class="card mb-2 cfg-gap">
  <div class="card-head"><h3><x-icon name="alert"/> Not yet behind a screen</h3><span class="badge badge-amber">{{ count($gaps) }} {{ count($gaps) === 1 ? 'module' : 'modules' }}</span></div>
  <div class="card-body">
    <p class="small muted">These modules hold settings that are read by the code but have no configuration screen of their own yet. They are the worklist — each one becomes a screen, and then a card in this register like the rest.</p>
    <div class="cfg-gap-list">
      @foreach($gaps as $g)
        <a class="cfg-gap-chip" href="{{ route('configuration.show', $g['key']) }}">
          <b>{{ $g['label'] }}</b>
          <span>{{ $g['total'] ? $g['total'].' to configure' : 'screen to build' }}</span>
        </a>
      @endforeach
    </div>
  </div>
</div>
@endif

@if(! $q && count($findings))
<div class="card mb-2 cfg-findings">
  <div class="card-head">
    <h3><x-icon name="alert"/> What the settings contradict</h3>
    <span class="badge {{ $findingCounts['blocker'] ? 'badge-red' : ($findingCounts['warning'] ? 'badge-amber' : 'badge-slate') }}">
      {{ $findingCounts['blocker'] }} cannot work · {{ $findingCounts['warning'] }} contradiction{{ $findingCounts['warning'] === 1 ? '' : 's' }} · {{ $findingCounts['note'] }} policy {{ $findingCounts['note'] === 1 ? 'choice' : 'choices' }}
    </span>
  </div>
  <div class="card-body">
    <p class="small muted">These checks flag specific conflicts and policy choices in the saved configuration. They do not cover every possible issue. Nothing is changed for you; each line names the screen that owns the value.</p>
    @foreach($findings as $f)
      <div class="cfg-find cfg-find-{{ $f['severity'] }}">
        <span class="cfg-find-sev">{{ $f['severity_label'] }}</span>
        <div class="cfg-find-body">
          <b>{{ $f['title'] }}</b>
          <p class="small">{{ $f['detail'] }}</p>
          <a class="cfg-find-go" href="{{ route('configuration.show', $f['module']) }}">{{ $f['module_label'] }} &rsaquo; fix it there</a>
        </div>
      </div>
    @endforeach
  </div>
</div>
@elseif(! $q)
<div class="card mb-2 cfg-findings cfg-findings-clear">
  <div class="card-head"><h3><x-icon name="check"/> What the settings contradict</h3><span class="badge badge-green">Nothing</span></div>
  <div class="card-body">
    <p class="small muted">Every dependency this property relies on is satisfied: the features that are switched on have the credentials, outlets, tariffs and policies they need.</p>
  </div>
</div>
@endif

<div class="grid g-2-1">
  <div>
    @foreach($groups as $group => $modules)
      <div class="cfg-group">
        <h2 class="cfg-group-head">{{ $group }}<span class="badge badge-slate">{{ count($modules) }}</span></h2>
        <div class="grid g-2">
          @foreach($modules as $m)
            <div class="card cfg-card {{ $m['open'] ? '' : 'cfg-card-gap' }}">
              <div class="card-head">
                <h3>{{ $m['label'] }}</h3>
                @if(! $m['open'])
                  <span class="badge badge-amber">No screen yet</span>
                @elseif(! $m['total'])
                  <span class="badge badge-slate">On its own screen</span>
                @elseif($m['missing'])
                  <span class="badge badge-blue">{{ $m['missing'] }} on default</span>
                @else
                  <span class="badge badge-green">All set</span>
                @endif
              </div>
              <div class="card-body">
                <p class="small muted cfg-brief">{{ $m['brief'] }}</p>
                <div class="cfg-meta">
                  @if($m['total'])
                    <span><b>{{ $m['set'] }}</b> of {{ $m['total'] }} set</span>
                    @if($m['changed'])
                      <span><b>{{ $m['changed'] }}</b> changed from the default</span>
                    @endif
                  @else
                    <span>Nothing in the settings store — configured in the module's own tables</span>
                  @endif
                  @if($m['last'])
                    <span title="{{ $m['last']['what'] }}">last changed {{ $m['last']['at']?->format('d M Y H:i') }} by {{ $m['last']['by'] }}<br><span class="tiny muted">{{ $m['last']['what'] }}</span></span>
                  @else
                    <span>never changed</span>
                  @endif
                </div>
                <div class="cfg-actions">
                  @if($m['open'] && $m['reachable'])
                    <a class="btn btn-sm btn-primary" href="{{ route($m['route']) }}"><x-icon name="settings" size="sm"/> Open the screen</a>
                    @if($m['edit'] && $m['edit'] !== $m['perm'])
                      <span class="tiny muted">changing it needs {{ $m['edit'] }}</span>
                    @endif
                  @elseif($m['open'])
                    <span class="badge badge-slate"><x-icon name="lock" size="sm"/> Needs {{ $m['perm'] }}</span>
                  @endif
                  @if($m['total'])
                    <a class="btn btn-sm" href="{{ route('configuration.show', $m['key']) }}">Settings &amp; values</a>
                  @endif
                </div>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h3>What changed recently</h3></div>
      <div class="card-body">
        @forelse($recent as $r)
          <div class="cfg-change">
            <b>{{ $r->description }}</b>
            <span>{{ $r->user?->name ?? 'System' }} · {{ $r->created_at?->format('d M H:i') }}</span>
          </div>
        @empty
          <p class="small muted">No configuration has been changed on this property yet. Every module is running on its built-in defaults.</p>
        @endforelse
      </div>
    </div>

    <div class="card mt-2">
      <div class="card-head"><h3>How this works</h3></div>
      <div class="card-body small">
        <p>This page is the register, not the editor. Every setting is changed on the screen that owns it, so the module keeps its own validation, permission and audit trail — this hub never writes a value behind a module's back.</p>
        <p class="muted">A setting marked <span class="badge badge-blue">on default</span> has never been set on this property and the code is using its built-in value. The printed sheet and the CSV list every key with its current value, so a new property can be checked line by line against a working one.</p>
      </div>
    </div>
  </div>
</div>
@endsection
