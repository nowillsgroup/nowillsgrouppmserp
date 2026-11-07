@extends('layouts.app')
@section('title', 'Configuration — what an unset setting runs on')
@section('crumbs') Administration / <a href="{{ route('configuration.index') }}">Configuration</a> / <b>Unset</b> @endsection
@section('actions')
  <a class="btn btn-soft" href="{{ route('configuration.index') }}"><x-icon name="grid"/> The register</a>
  <a class="btn btn-soft" href="{{ route('configuration.changed') }}"><x-icon name="swap"/> What this property changed</a>
  <a class="btn" href="{{ route('configuration.print') }}"><x-icon name="print"/> Print the register</a>
@endsection
@section('content')

<div class="grid g-4 mb-2 cfg-strip">
  <div class="stat"><span class="ico" style="background:#f0f4ff;color:#3b5bdb"><x-icon name="settings"/></span><div><div class="v">{{ number_format($counts['total']) }}</div><div class="l">settings read with no default to fall back on</div></div></div>
  <div class="stat"><span class="ico" style="background:#e6f7f2;color:#189b78"><x-icon name="check"/></span><div><div class="v">{{ number_format($counts['code']) }}</div><div class="l">fall back to a value written in the code</div></div></div>
  <div class="stat"><span class="ico" style="background:#fff2df;color:#ec8c20"><x-icon name="alert"/></span><div><div class="v">{{ number_format($counts['page']) }}</div><div class="l">where only a page prints a value</div></div></div>
  <div class="stat"><span class="ico" style="background:#fdecec;color:#c4453d"><x-icon name="info"/></span><div><div class="v">{{ number_format($counts['null']) }}</div><div class="l">run on nothing at all</div></div></div>
</div>

<div class="card mb-2">
  <div class="card-body small">
    <p><b>These {{ number_format($counts['total']) }} settings are read with no second argument</b>, so the runtime hands back
      <code>null</code> and whatever the property actually gets is decided at the read site. That is why a blank in the
      register tells you nothing on its own: "off" and "somebody forgot" look identical there. This page reads the sites
      and says which of the three it is.</p>
    <p class="muted">More keys have no built-in default than the {{ number_format($counts['total']) }} listed here: the rest are
      credentials and family prefixes, which have no single value to describe. A credential is never read through
      <code>setting()</code> at all, and a prefix (<code>gl_cat_</code>, <code>sms_tpl_</code>) holds whatever rows the
      property created. Nothing on this page edits a value either — each row opens the module that owns it.</p>
  </div>
</div>

@if($conflicting)
<div class="card mb-2">
  <div class="card-head"><h3>Two different values at the read sites</h3><span class="badge badge-red">{{ count($conflicting) }}</span></div>
  <div class="card-body small">
    <p class="mb-1">The code falls back to more than one value for these settings, so nothing here can say what an
      unset value means. That is a code problem, not a configuration one — the page refuses to pick a side.</p>
    <ul class="mb-0">
      @foreach($conflicting as $key => $values)
        <li><code>{{ $key }}</code> — {{ implode(' · ', $values) }}</li>
      @endforeach
    </ul>
  </div>
</div>
@endif

@foreach($groups as $g)
<div class="card flush mb-2">
  <div class="card-head">
    <h3>{{ $g['title'] }}</h3>
    <span class="badge badge-{{ $g['state'] === 'code' ? 'green' : ($g['state'] === 'page' ? 'amber' : 'slate') }}">{{ count($g['rows']) }}</span>
  </div>
  @if(count($g['rows']))
    <div class="card-body table-wrap"><table class="table">
      <thead><tr><th>Module</th><th>Setting</th><th>Key</th><th>On this property</th><th>What an unset value runs on</th><th>Read in</th></tr></thead>
      <tbody>
      @foreach($g['rows'] as $r)
        <tr>
          <td class="small"><a href="{{ route('configuration.show', $r['module']) }}">{{ $r['module_label'] }}</a></td>
          <td class="bold">{{ $r['label'] }}</td>
          <td><code class="small muted">{{ $r['key'] }}</code></td>
          <td class="{{ $r['set'] ? '' : 'muted' }}">{{ $r['set'] ? $r['value'] : 'not set' }}</td>
          <td class="small">{{ $r['what'] }}</td>
          <td class="small muted" title="{{ $r['read_paths'] }}">{{ $r['read_in'] }}</td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
  @else
    <div class="card-body small muted">Nothing falls into this group on this property.</div>
  @endif
  <div class="card-body small muted" style="padding-top:0">{{ $g['hint'] }}</div>
</div>
@endforeach

@endsection
