@extends('layouts.app')
@section('title', 'Configuration — what this property changed')
@section('crumbs') Administration / <a href="{{ route('configuration.index') }}">Configuration</a> / <b>Changed here</b> @endsection
@section('actions')
  <a class="btn btn-soft" href="{{ route('configuration.index') }}"><x-icon name="grid"/> The register</a>
  <a class="btn" href="{{ route('configuration.print') }}"><x-icon name="print"/> Print the register</a>
  <a class="btn btn-soft" href="{{ route('configuration.csv') }}"><x-icon name="download"/> Excel / CSV</a>
@endsection
@section('content')

<div class="grid g-4 mb-2 cfg-strip">
  <div class="stat"><span class="ico" style="background:#f0f4ff;color:#3b5bdb"><x-icon name="swap"/></span><div><div class="v">{{ number_format($decisions['counts']['drifted']) }}</div><div class="l">moved off a built-in default</div></div></div>
  <div class="stat"><span class="ico" style="background:#eaf3ff;color:#267dca"><x-icon name="settings"/></span><div><div class="v">{{ number_format($decisions['counts']['chosen']) }}</div><div class="l">filled in where the code has no default</div></div></div>
  <div class="stat"><span class="ico" style="background:#e6f7f2;color:#189b78"><x-icon name="check"/></span><div><div class="v">{{ number_format($decisions['counts']['modules']) }}</div><div class="l">modules this property configured</div></div></div>
  <div class="stat"><span class="ico" style="background:#fff2df;color:#ec8c20"><x-icon name="settings"/></span><div><div class="v">{{ number_format($coverage['unset']) }} of {{ number_format($coverage['keys']) }}</div><div class="l">settings left on the built-in default</div></div></div>
</div>

<div class="card mb-2">
  <div class="card-body small">
    <p><b>This is the property's own configuration decisions.</b> Every other setting is running on the value the
      code ships with — nobody chose it here, and it moves when the product is updated. That difference is what a
      handover, an audit or a second property compares.</p>
    <p class="muted">Nothing on this page edits a value: each row opens the module that owns it, where the change keeps
      that module's validation, permission and activity trail. A credential is listed as a decision without its value.</p>
  </div>
</div>

@if($decisions['counts']['drifted'])
<div class="card flush mb-2">
  <div class="card-head"><h3>Moved off the built-in default</h3><span class="badge badge-blue">{{ $decisions['counts']['drifted'] }}</span></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Module</th><th>Setting</th><th>On this property</th><th>Built-in default</th><th>Last change</th></tr></thead>
    <tbody>
    @foreach($decisions['drifted'] as $d)
      <tr>
        <td class="small"><a href="{{ route('configuration.show', $d['module']) }}">{{ $d['module_label'] }}</a></td>
        <td class="bold">{{ $d['label'] }} <code class="small muted">{{ $d['key'] }}</code></td>
        <td>{{ $d['value'] }}</td>
        <td class="small muted">{{ $d['default'] ?? '—' }}</td>
        <td class="small muted">
          @if($d['when']){{ $d['when']->format('d M Y H:i') }} · {{ $d['by'] }}@else never recorded @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
</div>
@else
<div class="card mb-2"><div class="card-body small muted">No setting has been moved off its built-in default on this property yet.</div></div>
@endif

@if($decisions['counts']['chosen'])
<div class="card flush mb-2">
  <div class="card-head"><h3>Filled in where the code has no default</h3><span class="badge badge-slate">{{ $decisions['counts']['chosen'] }}</span></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Module</th><th>Setting</th><th>On this property</th><th>Last change</th></tr></thead>
    <tbody>
    @foreach($decisions['chosen'] as $d)
      <tr>
        <td class="small"><a href="{{ route('configuration.show', $d['module']) }}">{{ $d['module_label'] }}</a></td>
        <td class="bold">{{ $d['label'] }} <code class="small muted">{{ $d['key'] }}</code></td>
        <td>{{ $d['value'] }}</td>
        <td class="small muted">
          @if($d['when']){{ $d['when']->format('d M Y H:i') }} · {{ $d['by'] }}@else never recorded @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
  <div class="card-body small muted" style="padding-top:0">
    These settings have no built-in default to compare with — the code runs on nothing until somebody fills them in,
    so a blank one is a decision too, just not one made in this register.
  </div>
</div>
@else
<div class="card mb-2"><div class="card-body small muted">No setting without a built-in default has been filled in on this property.</div></div>
@endif

@endsection
