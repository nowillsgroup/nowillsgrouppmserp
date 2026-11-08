@extends('layouts.app')
@section('title', $m['label'].' — configuration')
@section('crumbs') Administration / <a href="{{ route('configuration.index') }}">Configuration</a> / {{ $m['label'] }} @endsection
@section('actions')
  @if($m['open'] && $m['reachable'])
    <a class="btn btn-primary" href="{{ route($m['route']) }}"><x-icon name="settings"/> Open the screen</a>
  @endif
  <a class="btn" href="{{ route('configuration.index') }}"><x-icon name="chev"/> All modules</a>
@endsection
@section('content')

<div class="card mb-2">
  <div class="card-head">
    <h3>{{ $m['label'] }}</h3>
    <span class="badge badge-{{ $m['open'] ? (! $m['total'] ? 'slate' : ($m['missing'] ? 'blue' : 'green')) : 'amber' }}">{{ $m['open'] ? (! $m['total'] ? 'On its own screen' : ($m['missing'] ? $m['missing'].' on default' : 'All set')) : 'No screen yet' }}</span>
  </div>
  <div class="card-body">
    <p class="small muted">{{ $m['brief'] }}</p>
    <div class="cfg-meta">
      @if($m['total'])
        <span><b>{{ $m['set'] }}</b> of {{ $m['total'] }} {{ $m['total'] === 1 ? 'setting' : 'settings' }} set on this property</span>
        <span><b>{{ $m['changed'] }}</b> moved off the built-in default</span>
      @else
        <span>No settings in the store — this module configures itself in its own tables</span>
      @endif
      <span>Group: {{ $m['group'] }}</span>
      @if($m['route'])
        <span>Screen: <a href="{{ $m['reachable'] ? route($m['route']) : '#' }}">{{ $m['route'] }}</a>@if($m['perm']) · opens with <code>{{ $m['perm'] }}</code>@endif @if($m['edit'] && $m['edit'] !== $m['perm']) · changing it needs <code>{{ $m['edit'] }}</code>@endif</span>
      @else
        <span>Screen: none yet — these settings are read by the code but nothing edits them</span>
      @endif
    </div>
  </div>
</div>

@if(count($findings))
<div class="card mb-2 cfg-findings">
  <div class="card-head"><h3><x-icon name="alert"/> What this module's settings contradict</h3><span class="badge badge-red">{{ count($findings) }}</span></div>
  <div class="card-body">
    @foreach($findings as $f)
      <div class="cfg-find cfg-find-{{ $f['severity'] }}">
        <span class="cfg-find-sev">{{ $f['severity_label'] }}</span>
        <div class="cfg-find-body">
          <b>{{ $f['title'] }}</b>
          <p class="small">{{ $f['detail'] }}</p>
          <span class="cfg-find-keys">Reads <code>{{ $f['keys_text'] }}</code></span>
        </div>
      </div>
    @endforeach
  </div>
</div>
@elseif($m['rules'] ?? 0)
<div class="card mb-2">
  <div class="card-body small muted">
    No findings from this module's {{ $m['rules'] }} configuration
    {{ $m['rules'] === 1 ? 'rule' : 'rules' }} right now. Other issues may not be covered.
  </div>
</div>
@else
<div class="card mb-2">
  <div class="card-body small muted">
    This module has no automated configuration checks yet. Its settings are listed here, but a conflict or risky policy
    in this module would not be reported by these checks.
  </div>
</div>
@endif

@if($m['total'])
<div class="card flush mb-2">
  <div class="card-head"><h3>Every setting this module reads</h3><span class="badge badge-slate">{{ $m['total'] }}</span></div>
  @if($m['secrets'] ?? 0)
    <div class="card-body small muted" style="padding-bottom:0">
      {{ $m['secrets'] }} of these are credentials. They are stored encrypted and the register only ever says whether one is set —
      the screen is the place that reads one back, and it never shows it either.
    </div>
  @endif
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Setting</th><th>Key</th><th>On this property</th><th>Built-in default</th><th>State</th><th>Read in</th></tr></thead>
    <tbody>
    @foreach($m['keys'] as $k)
      <tr>
        <td class="bold">{{ $k['label'] }} @if($k['secret']) <span class="tiny muted">credential</span>@endif</td>
        <td class="small muted"><code>{{ $k['key'] }}</code></td>
        <td class="{{ $k['set'] ? '' : 'muted' }}">{{ $k['set'] ? $k['value'] : ($k['secret'] ? 'Not set' : ($k['default_show'] ?? ($k['unset_show'] ?? 'nothing — read with no default'))) }}</td>
        <td class="small {{ $k['default_show'] === null ? 'muted' : '' }}">{{ $k['default_show'] ?? '—' }}</td>
        <td>
          @if($k['changed'])
            <span class="badge badge-blue">Changed</span>
          @elseif($k['set'] && $k['secret'])
            <span class="badge badge-green">Set (hidden)</span>
          @elseif($k['set'])
            <span class="badge badge-green">Set</span>
          @else
            <span class="badge badge-slate">Default</span>
          @endif
        </td>
        <td class="small muted" title="{{ $k['read_paths'] }}">{{ $k['read_in'] }}</td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
</div>
@else
<div class="card mb-2"><div class="card-body small muted">This module configures itself on its own screen — venues, packages, plans or prices live in the module's tables rather than in the settings store. Those records are not listed or fingerprinted as settings here.</div></div>
@endif

<div class="card mb-2">
  <div class="card-head"><h3>What these settings were before</h3>
    <span class="badge badge-slate">{{ count($valueTrail) }}</span>
    <a class="btn btn-soft btn-sm" href="{{ route('configuration.history', ['module' => $m['key']]) }}" style="margin-left:auto">Full trail</a>
  </div>
  <div class="card-body">
    @forelse($valueTrail as $v)
      <div class="cfg-change">
        <b>{{ $v['label'] }}</b>
        <span>{{ $v['created'] ? 'was not set' : ($v['was'] ?? 'cleared') }} → {{ $v['now'] }} · {{ $v['by'] ?? 'not recorded' }} · {{ $v['when'] ? $v['when']->format('d M Y H:i') : '—' }}</span>
      </div>
    @empty
      <p class="small muted">No value change has been recorded on this module yet. The activity log below may show saves;
        missing or bypassed value-history entries cannot be reconstructed.</p>
    @endforelse
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Changes to this module</h3>
    @if(($m['route'] ?? null) === 'settings.index')
      <span class="tiny muted">this module is changed on the hotel Settings form, which names the settings each save touched</span>
    @endif
  </div>
  <div class="card-body">
    @forelse($history as $h)
      <div class="cfg-change">
        <b>{{ $h->description }}</b>
        <span>{{ $h->user?->name ?? 'System' }} · {{ $h->created_at?->format('d M Y H:i') }}</span>
      </div>
    @empty
      <p class="small muted">No matching activity has been recorded in the available log.</p>
    @endforelse
  </div>
</div>
@endsection
