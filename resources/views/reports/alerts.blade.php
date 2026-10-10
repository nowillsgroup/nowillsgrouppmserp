@extends('layouts.app')
@section('title', 'KPI Alerts')
@section('crumbs') <a href="{{ route('reports.index') }}">Reports</a> / KPI alerts @endsection
@section('actions')
<form method="post" action="{{ route('reports.alerts.evaluate') }}" class="flex gap-1" style="display:inline-flex;align-items:center">@csrf
  <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ bdate()->copy()->subDay()->toDateString() }}" class="input-sm" aria-label="Business date">
  <button class="btn btn-primary" id="evaluateNow"><x-icon name="refresh"/> Check now</button>
</form>
<a class="btn" href="{{ route('reports.library') }}"><x-icon name="star"/> My reports</a>
@endsection
@php
  $K = \App\Services\KpiService::class; $A = \App\Models\KpiAlert::class; $e = $editing;
  $f = fn ($k, $def = null) => old($k, $e ? $e->{$k} : $def);
  $sevTone = ['critical' => 'red', 'warning' => 'amber', 'info' => 'blue'];
  $selUsers = array_map('intval', (array) old('recipient_users', $e?->recipient_users ?? [auth()->id()]));
  $selCh = (array) old('channels', $e?->channels ?? ['email']);
@endphp
@section('content')
<div class="stats">
  <x-stat label="Alerts to acknowledge" :value="$open" icon="alert" :tone="$open ? 'red' : 'green'" sub="last 14 days"/>
  <x-stat label="Active alert rules" :value="$alerts->where('is_active', true)->count()" icon="bell" tone="blue" :sub="$alerts->where('is_active', false)->count().' disabled'"/>
  <x-stat label="Breaching now" :value="$alerts->filter(fn ($a) => $a->is_active && $a->last_value !== null && $K::breached($a, (float) $a->last_value))->count()" icon="trend" tone="amber" :sub="'as last checked'"/>
  <x-stat label="Checked for" :value="$date->format('d M Y')" icon="calendar" tone="slate" sub="last audited business date"/>
</div>

<div class="card flush" id="eventsCard"><div class="card-head"><h3><x-icon name="alert"/> Alert events</h3>
  <div class="flex gap-1"><a class="btn btn-sm {{ request('show') !== 'all' ? 'btn-primary' : 'btn-soft' }}" href="{{ route('reports.alerts') }}">Unacknowledged</a><a class="btn btn-sm {{ request('show') === 'all' ? 'btn-primary' : 'btn-soft' }}" href="{{ route('reports.alerts', ['show' => 'all']) }}">All</a></div></div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Business date</th><th>Severity</th><th>Alert</th><th class="num">Actual</th><th class="num">Threshold</th><th>Notified</th><th>Acknowledged</th></tr></thead>
  <tbody>@forelse($events as $ev) @php $fmt = $K::METRICS[$ev->alert?->metric][1] ?? 'int'; @endphp
    <tr data-event="{{ $ev->id }}"><td class="small nowrap">{{ $ev->business_date->format('D d M Y') }}</td>
      <td><span class="badge badge-{{ $sevTone[$ev->severity] ?? 'slate' }}">{{ $ev->severity }}</span></td>
      <td class="small"><b>{{ $ev->alert?->name }}</b><div class="tiny muted">{{ $ev->message }}</div></td>
      <td class="num bold text-{{ $ev->severity === 'critical' ? 'red' : 'amber' }}">{{ $K::format((float) $ev->value, $fmt) }}</td>
      <td class="num small">{{ $ev->alert?->operator === 'below' ? '<' : '>' }} {{ $K::format((float) $ev->threshold, $fmt) }}</td>
      <td class="small">{{ $ev->notified ? $ev->notified.' message(s)' : 'in-app' }}</td>
      <td class="small">@if($ev->acknowledged_at){{ $ev->acknowledger?->name }} <div class="tiny muted">{{ $ev->acknowledged_at->format('d M H:i') }}{{ $ev->ack_note ? ' · '.$ev->ack_note : '' }}</div>
        @else<form method="post" action="{{ route('reports.alerts.ack', $ev) }}" class="flex gap-1">@csrf<input class="input-sm" name="note" placeholder="Action taken (optional)" maxlength="255" style="max-width:190px"><button class="btn btn-sm btn-primary"><x-icon name="check"/> Acknowledge</button></form>@endif</td></tr>
  @empty
    <tr><td colspan="7"><x-empty text="No alert events{{ request('show') === 'all' ? '' : ' waiting for acknowledgement' }}." icon="check"/></td></tr>
  @endforelse</tbody>
</table></div></div>
{{ $events->links() }}

<div class="card flush" id="alertsCard"><div class="card-head"><h3><x-icon name="bell"/> Alert rules</h3><span class="small muted">checked automatically after every night audit</span></div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Alert</th><th>Rule</th><th>Severity</th><th>Notify</th><th class="num">Last value</th><th>Last triggered</th><th></th></tr></thead>
  <tbody>@forelse($alerts as $a) @php $fmt = $K::METRICS[$a->metric][1] ?? 'int'; $bad = $a->last_value !== null && $K::breached($a, (float) $a->last_value); @endphp
    <tr data-alert="{{ $a->id }}" class="{{ $a->is_active ? '' : 'muted' }}">
      <td class="bold">{{ $a->name }} @if(! $a->is_active)<span class="badge badge-slate">off</span>@endif</td>
      <td class="small">{{ $a->rule() }}</td>
      <td><span class="badge badge-{{ $sevTone[$a->severity] ?? 'slate' }}">{{ $a->severity }}</span></td>
      <td class="small">{{ implode(' + ', array_merge(['in-app'], $a->channels ?? [])) }}<div class="tiny muted">{{ count($a->recipient_users ?? []) }} user(s){{ $a->recipient_emails || $a->recipient_phones ? ' + external' : '' }}</div></td>
      <td class="num">@if($a->last_value !== null)<span class="{{ $bad ? 'bold text-red' : 'text-green' }}">{{ $K::format((float) $a->last_value, $fmt) }}</span><div class="tiny muted">{{ $a->last_date?->format('d M') }}</div>@else — @endif</td>
      <td class="small">{{ $a->last_triggered_at?->format('d M H:i') ?? 'never' }}<div class="tiny muted">{{ $a->triggers }} time(s)</div></td>
      <td class="nowrap right">
        <form method="post" action="{{ route('reports.alerts.toggle', $a) }}" style="display:inline">@csrf<button class="btn btn-sm">{{ $a->is_active ? 'Disable' : 'Enable' }}</button></form>
        <a class="btn btn-sm btn-ghost" href="{{ route('reports.alerts', ['edit' => $a->id]) }}#alertForm" title="Edit"><x-icon name="edit"/></a>
        <form method="post" action="{{ route('reports.alerts.destroy', $a) }}" style="display:inline" data-confirm="Delete the alert “{{ $a->name }}” and its history?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash"/></button></form>
      </td></tr>
  @empty
    <tr><td colspan="7"><x-empty text="No KPI alerts yet." icon="bell"/></td></tr>
  @endforelse</tbody>
</table></div></div>

<details class="card" id="alertForm" {{ $e || $errors->any() || session('error') ? 'open' : '' }}>
  <summary class="card-head" style="cursor:pointer"><h3><x-icon name="{{ $e ? 'edit' : 'plus' }}"/> {{ $e ? 'Edit alert “'.$e->name.'”' : 'New KPI alert' }}</h3></summary>
  <div class="card-body">
  <form method="post" action="{{ $e ? route('reports.alerts.update', $e) : route('reports.alerts.store') }}">@csrf @if($e) @method('PUT') @endif
    <div class="form-grid">
      <div class="field"><label for="k_name">Name</label><input id="k_name" name="name" maxlength="120" value="{{ $f('name') }}" placeholder="e.g. Low occupancy"></div>
      <div class="field"><label for="k_metric">Metric</label><select id="k_metric" name="metric">
        <optgroup label="Flash report (audited day)">@foreach($K::METRICS as $mk => $m) @continue($m[2] !== 'flash')<option value="{{ $mk }}" @selected($f('metric', 'occ') === $mk) title="{{ $m[3] }}">{{ $m[0] }}</option>@endforeach</optgroup>
        <optgroup label="Live position">@foreach($K::METRICS as $mk => $m) @continue($m[2] !== 'now')<option value="{{ $mk }}" @selected($f('metric') === $mk) title="{{ $m[3] }}">{{ $m[0] }}</option>@endforeach</optgroup>
      </select></div>
      <div class="field"><label for="k_period">Figure</label><select id="k_period" name="period"><option value="day" @selected($f('period', 'day') === 'day')>The day</option><option value="mtd" @selected($f('period') === 'mtd')>Month to date</option></select><small class="muted">Flash metrics only.</small></div>
      <div class="field"><label for="k_op">Fire when</label><select id="k_op" name="operator"><option value="below" @selected($f('operator', 'below') === 'below')>Below</option><option value="above" @selected($f('operator') === 'above')>Above</option></select></div>
      <div class="field"><label for="k_thr">Threshold</label><input id="k_thr" type="number" step="0.01" name="threshold" required value="{{ $f('threshold') }}"><small class="muted">% for occupancy, GH₵ for money, a count otherwise.</small></div>
      <div class="field"><label for="k_sev">Severity</label><select id="k_sev" name="severity">@foreach($A::SEVERITIES as $k => $l)<option value="{{ $k }}" @selected($f('severity', 'warning') === $k)>{{ $l }}</option>@endforeach</select></div>
    </div>
    <div class="field mt-1"><label>Notify (in-app is always on)</label><div class="flex gap-2">
      <label class="check"><input type="checkbox" name="channels[]" value="email" @checked(in_array('email', $selCh))> <span>E-mail</span></label>
      <label class="check"><input type="checkbox" name="channels[]" value="sms" @checked(in_array('sms', $selCh))> <span>SMS</span></label></div></div>
    <div class="field"><label>Users</label><div class="flex gap-2 wrap">@foreach($users as $u)<label class="check small"><input type="checkbox" name="recipient_users[]" value="{{ $u->id }}" @checked(in_array($u->id, $selUsers))> <span>{{ $u->name }}</span></label>@endforeach</div></div>
    <div class="form-grid">
      <div class="field span-2"><label for="k_em">Other e-mails</label><input id="k_em" name="recipient_emails" value="{{ $f('recipient_emails') }}" placeholder="gm@example.com"></div>
      <div class="field span-2"><label for="k_ph">Other phones (SMS)</label><input id="k_ph" name="recipient_phones" value="{{ $f('recipient_phones') }}" placeholder="+233244000000"></div>
    </div>
    <div class="flex gap-1 mt-1"><button class="btn btn-primary" id="alertSave">{{ $e ? 'Update alert' : 'Create alert' }}</button>@if($e)<a class="btn" href="{{ route('reports.alerts') }}">Cancel</a>@endif</div>
  </form>
  </div>
</details>
@endsection
