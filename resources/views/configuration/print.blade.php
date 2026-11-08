@extends('layouts.print')
@section('title', 'Configuration register')
@section('doctitle', 'Configuration register')
@section('docmeta')
  <div class="small">Property <b>{{ $hotel->name }}</b> ({{ $hotel->code }})</div>
  <div class="small">{{ $coverage['modules'] }} modules · {{ $coverage['keys'] }} settings tracked</div>
  <div class="small">Printed {{ $printedAt->format('d M Y H:i') }}</div>
  <div class="small">Fingerprint v2 <b class="cfg-fingerprint">{{ $fingerprint['short'] }}</b></div>
@endsection
@section('content')

@if(count($findings))
<h3 class="cfg-print-group">What the settings contradict</h3>
<table class="table table-sm">
  <thead><tr><th style="width:12%">Severity</th><th style="width:32%">Finding</th><th style="width:14%">Module</th><th>What it means</th></tr></thead>
  <tbody>
  @foreach($findings as $f)
    <tr>
      <td class="small">{{ $f['severity_label'] }}</td>
      <td><b>{{ $f['title'] }}</b></td>
      <td class="small">{{ $f['module_label'] }}</td>
      <td class="small">{{ $f['detail'] }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
<p class="tiny muted">{{ $findingCounts['blocker'] }} {{ $findingCounts['blocker'] === 1 ? 'finding means' : 'findings mean' }} a feature cannot work as configured; {{ $findingCounts['warning'] }} are contradictions between saved settings; {{ $findingCounts['note'] }} are policy choices recorded so they are seen.</p>
@endif

<p class="small muted">Every module that has something to configure, the screen that owns it, the value on this property and the built-in default the code falls back to. A setting marked <b>not set</b> has never been set here; one marked <b>·changed</b> has been moved off its default on purpose. A <b>credential</b> is never printed: the sheet says whether one is stored, not what it is.</p>

@foreach($groups as $group => $modules)
  <h3 class="cfg-print-group">{{ $group }}</h3>
  @foreach($modules as $m)
    <div class="cfg-print-module">
      <div class="cfg-print-head">
        <b>{{ $m['label'] }}</b>
        <span class="small muted">{{ $m['route'] ? $m['route'] : 'no screen yet' }} · {{ $m['set'] }} of {{ $m['total'] }} set</span>
      </div>
      <p class="tiny muted">{{ $m['brief'] }}</p>
      @if($m['keys'])
        <table class="table table-sm">
          <thead><tr><th style="width:34%">Setting</th><th>Key</th><th>Value on this property</th><th>Built-in default</th></tr></thead>
          <tbody>
          @foreach($m['keys'] as $k)
            <tr>
              <td>{{ $k['label'] }}</td>
              <td class="tiny muted"><code>{{ $k['key'] }}</code></td>
              <td>{{ $k['set'] ? $k['value'] : 'not set' }} @if($k['secret']) <span class="tiny muted">credential</span>@endif</td>
              <td class="small {{ $k['changed'] ? '' : 'muted' }}">{{ $k['default_show'] ?? '—' }} @if($k['changed']) ·changed @endif</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>
  @endforeach
@endforeach

<p class="tiny muted">Exported from Settings › Configuration. Values are as stored on this property; a blank key is unset and the module's built-in default applies.</p>
@endsection
