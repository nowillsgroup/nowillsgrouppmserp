@extends('layouts.print')
@section('title', 'Configuration handover')
@section('doctitle', 'Configuration handover')
@section('docmeta')
  <div class="small">Property <b>{{ $hotel->name }}</b> ({{ $hotel->code }})</div>
  <div class="small">{{ $coverage['modules'] }} modules · {{ $coverage['keys'] }} settings tracked</div>
  <div class="small">Printed {{ $printedAt->format('d M Y H:i') }}</div>
  <div class="small">Fingerprint v2 <b class="cfg-fingerprint">{{ $fingerprint['short'] }}</b></div>
@endsection
@section('content')

<p class="small"><b>What this sheet is.</b> A property's configuration is not the settings somebody remembers changing: it is
  every value the property stores, every value it does not, and what the code does when nobody set one. This sheet states
  all of that as it stands at the print time above, so an auditor, an owner or an incoming manager can check it line by
  line instead of asking somebody.</p>
<p class="small muted"><b>The fingerprint.</b> {{ $fingerprint['short'] }} is computed from all {{ number_format($fingerprint['keys']) }}
  registered settings and their stored values this minute. Compare twelve characters on two version 2 sheets as a quick
  indicator, not proof of identical configuration. Credentials contribute only whether one is stored, so rotating a
  credential that remains set does not change the fingerprint. Module-table data and unregistered settings are excluded.
  Version 2 uses a new encoding: it cannot be compared directly with sheets printed under the previous version.</p>

<h3 class="cfg-print-group">1 · The decisions this property made</h3>
<p class="small">{{ number_format($decisions['counts']['drifted']) }} settings were moved off the value the code ships with, and
  {{ number_format($decisions['counts']['chosen']) }} were filled in where the code has no default at all — across
  {{ number_format($decisions['counts']['modules']) }} modules. Everything else on this property is running on the code's own
  default, which is not a decision anybody here made and which moves when the product is updated.</p>
@if($decisions['drifted'])
<table class="table table-sm">
  <thead><tr><th style="width:20%">Module</th><th style="width:34%">Setting</th><th>On this property</th><th>Built-in default</th></tr></thead>
  <tbody>
  @foreach($decisions['drifted'] as $d)
    <tr>
      <td class="small">{{ $d['module_label'] }}</td>
      <td class="small">{{ $d['label'] }} <code class="tiny">{{ $d['key'] }}</code></td>
      <td class="small">{{ $d['value'] }}</td>
      <td class="small muted">{{ $d['default'] ?? '—' }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
@endif
@if($decisions['chosen'])
<p class="small muted">Filled in where the code ships no default:</p>
<ul class="small">
  @foreach($decisions['chosen'] as $d)
    <li>{{ $d['module_label'] }} — {{ $d['label'] }} = {{ $d['value'] }}</li>
  @endforeach
</ul>
@endif

<h3 class="cfg-print-group">2 · What an unset setting runs on</h3>
<p class="small">{{ number_format($unset['counts']['total']) }} settings have no built-in default. Where the code supplies a
  value at its read site the sheet says so; where it does not, the service genuinely sees nothing — and that is the
  difference between "off" and "somebody forgot".</p>
<table class="table table-sm">
  <thead><tr><th style="width:16%">Module</th><th style="width:30%">Setting</th><th style="width:20%">On this property</th><th>What an unset value runs on</th></tr></thead>
  <tbody>
  @foreach($unset['groups'] as $g)
    @foreach($g['rows'] as $r)
      <tr>
        <td class="small">{{ $r['module_label'] }}</td>
        <td class="small">{{ $r['label'] }} <code class="tiny">{{ $r['key'] }}</code></td>
        <td class="small">{{ $r['set'] ? $r['value'] : 'not set' }}</td>
        <td class="small">{{ $r['what'] }}</td>
      </tr>
    @endforeach
  @endforeach
  </tbody>
</table>

<h3 class="cfg-print-group">3 · What the settings contradict</h3>
@if(count($findings))
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
  <p class="tiny muted">{{ $findingCounts['blocker'] }} {{ $findingCounts['blocker'] === 1 ? 'finding means' : 'findings mean' }} a feature cannot work as configured;
    {{ $findingCounts['warning'] }} are contradictions between saved settings; {{ $findingCounts['note'] }} are policy
    choices recorded so they are seen.</p>
@else
  <p class="small muted">Nothing on this property contradicts anything else, as far as the checks reach. What they reach is
    the next section.</p>
@endif

<h3 class="cfg-print-group">4 · What is checked against what</h3>
<p class="small">{{ count($guarded) }} of {{ $coverage['modules'] }} modules have at least one automated configuration check —
  for example, a log driver that sends nothing or a payment provider left on test. These checks cover specific cases,
  not every possible issue. The other {{ count($unguarded) }} modules have no such check. That is not the same as "checked and clean"
  for any module, and this sheet does not say so.</p>
<p class="small">Beyond the modules: <b>{{ $mapGap }}</b> settings are read by the code through a service map — a
  default, a value and a behaviour, all real — and are not in this register yet, so this sheet can neither show them
  nor fingerprint them. They are named in the register's own audit rather than left out of it.</p>
<p class="small muted"><b>At least one check:</b> {{ $guardedNames }}</p>
<p class="small muted"><b>No automated checks:</b> {{ $unguardedNames }}</p>

<h3 class="cfg-print-group">5 · Credentials</h3>
<p class="small">{{ $credentials['keys'] }} settings on this property are credentials, and {{ $credentials['set'] }} of them
  {{ $credentials['set'] === 1 ? 'is' : 'are' }} set across {{ $credentials['modules'] }}
  {{ $credentials['modules'] === 1 ? 'module' : 'modules' }}. This sheet says how many and never what they are: a
  credential is stored encrypted and is not printed, exported or hashed here. The screens that own them show whether one
  is set.</p>

<h3 class="cfg-print-group">6 · What it was before</h3>
<p class="small">{{ number_format($trail['rows']) }} value changes are recorded, covering {{ number_format($trail['keys']) }}
  of {{ number_format($trail['keys_total']) }} settings{{ $trail['since'] ? ', the earliest on '.$trail['since']->format('d M Y') : '' }}.
  Earlier changes may appear in the activity log by label only; some may not have been logged. Bulk database writes bypass the value trail, and trail recording can fail without blocking the original write.</p>
@if($recentChanges)
  <table class="table table-sm">
    <thead><tr><th style="width:18%">When</th><th style="width:26%">Setting</th><th>Was</th><th>Now</th><th>By</th></tr></thead>
    <tbody>
    @foreach($recentChanges as $c)
      <tr>
        <td class="small">{{ $c['when'] ? $c['when']->format('d M Y H:i') : '—' }}</td>
        <td class="small">{{ $c['label'] }} <code class="tiny">{{ $c['key'] }}</code></td>
        <td class="small">{{ $c['created'] ? 'nothing — it was not set' : ($c['was'] ?? 'cleared') }}</td>
        <td class="small">{{ $c['now'] }}</td>
        <td class="small muted">{{ $c['by'] ?? 'not recorded' }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
@else
  <p class="small muted">No value change has been recorded yet.</p>
@endif

<h3 class="cfg-print-group">7 · Sign-off</h3>
<table class="table table-sm cfg-signoff">
  <tbody>
    <tr><td style="width:26%"><b>Prepared by</b></td><td></td><td style="width:14%">Date</td><td></td></tr>
    <tr><td><b>Reviewed by</b></td><td></td><td>Date</td><td></td></tr>
    <tr><td><b>Changes agreed</b></td><td colspan="3"></td></tr>
  </tbody>
</table>
<p class="tiny muted">Exported from Settings › Configuration. Fingerprint v2 {{ $fingerprint['short'] }} · {{ $fingerprint['lines'] }}
  records hashed · printed {{ $printedAt->format('d M Y H:i') }}. A credential is never printed: the sheet says whether one is
  set, not what it is.</p>
@endsection
