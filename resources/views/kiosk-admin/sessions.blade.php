@extends('layouts.app')
@section('title', 'Kiosk Guest Visits')
@section('crumbs') Self-Service Kiosk / Guest visits @endsection
@php $S = \App\Models\KioskSession::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'kiosk_sessions', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Report</a>
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end" id="kskFilter">
  <div class="field"><label>From</label><input type="date" name="from" class="input" value="{{ $from }}"></div>
  <div class="field"><label>To</label><input type="date" name="to" class="input" value="{{ $to }}"></div>
  <div class="field"><label>Service</label><select name="kind" class="input"><option value="">All</option>@foreach($S::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Outcome</label><select name="status" class="input"><option value="">All</option>@foreach($S::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Kiosk</label><select name="kiosk_id" class="input"><option value="">All</option>@foreach($kiosks as $k)<option value="{{ $k->id }}" @selected((string) request('kiosk_id') === (string) $k->id)>{{ $k->name }}</option>@endforeach</select></div>
  <div class="field"><label>Guest / booking</label><input name="q" class="input" value="{{ request('q') }}" placeholder="surname, RES-…, KSK-…"></div>
  <button class="btn btn-sm">Filter</button></form></div></div>
<div class="stats">
  <x-stat label="Visits" :value="$totals['visits']" icon="user" tone="blue"/>
  <x-stat label="Finished at the kiosk" :value="$totals['completed']" icon="check" tone="green" :sub="$totals['visits'] ? round($totals['completed'] * 100 / $totals['visits']).'%' : null"/>
  <x-stat label="Handed to the desk" :value="$totals['assist']" icon="bell" tone="red"/>
  <x-stat label="Walked away / started over" :value="$totals['abandoned']" icon="x" tone="slate"/>
  <x-stat label="Paid at the kiosk" :value="money($totals['paid'])" icon="money" tone="teal"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="kskSessions"><thead><tr><th>Visit</th><th>Started</th><th>Service</th><th>Guest</th><th>Room</th><th>Reached</th><th class="num">Paid</th><th>Time</th><th>Outcome</th></tr></thead><tbody>
@forelse($list as $s)
  <tr><td><a href="{{ route('kiosks.session', $s) }}"><b>{{ $s->number }}</b></a><div class="tiny muted">{{ $s->kiosk?->name }}</div></td>
    <td class="small nowrap">{{ $s->started_at->format('d M H:i') }}</td><td class="small">{{ $s->kindLabel() }}</td>
    <td class="small">@if($s->guest){{ $s->guest->full_name }}@else<span class="ksk-anon">not identified</span>@endif<div class="tiny muted">{{ $s->reservation?->reservation_no }}</div></td>
    <td class="small">{{ $s->room?->room_no ?? '—' }}</td><td class="small">{{ $s->stepLabel() }}</td>
    <td class="num small">{{ (float) $s->amount_paid ? money($s->amount_paid) : '—' }}</td><td class="small nowrap">{{ $s->durationLabel() }}</td>
    <td><span class="badge badge-{{ $S::STATUS_TONES[$s->status] ?? 'slate' }}">{{ $s->statusLabel() }}</span>@if($s->status === 'assist')<div class="tiny muted">{{ $s->assistLabel() }}{{ $s->resolved_at ? ' · dealt with' : '' }}</div>@endif</td></tr>
@empty<tr><td colspan="9" class="muted">No kiosk visits in this period.</td></tr>@endforelse
</tbody></table></div></div>
{{ $list->links() }}
@endsection
