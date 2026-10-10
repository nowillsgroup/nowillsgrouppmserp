@extends('layouts.app')
@section('title', 'Monthly Owner Packs')
@section('crumbs') Owner Reporting / Monthly owner packs @endsection
@php $OS = \App\Models\OwnerStatement::class; $canOp = can_do('owner.operate'); $have = $statements->pluck('period')->flip(); @endphp
@section('content')
<div class="grid g-2-1">
<div>
  <div class="card flush" id="packsCard"><div class="card-head"><h3><x-icon name="book"/> Owner packs</h3></div>
  <div class="card-body table-wrap"><table class="table" id="packsTable">
    <thead><tr><th>Month</th><th>Status</th><th>Issued</th><th>Acknowledged</th><th>Queries</th><th></th></tr></thead>
    <tbody>@forelse($statements as $s)
      <tr data-period="{{ $s->period }}" data-status="{{ $s->status }}">
        <td><a href="{{ route('owner.statements.show', $s->period) }}"><b>{{ $s->label() }}</b></a></td>
        <td><span class="badge badge-{{ $OS::TONES[$s->status] }}">{{ $OS::STATUSES[$s->status] }}</span></td>
        <td class="small">{{ $s->issued_at ? $s->issued_at->format('d M Y').' · '.$s->issuer?->name : '—' }}</td>
        <td class="small">{{ $s->acknowledged_at ? $s->acknowledged_at->format('d M Y').' · '.$s->acknowledger?->name : '—' }}</td>
        <td>@if($s->open_queries)<span class="badge badge-amber">{{ $s->open_queries }} open</span>@endif</td>
        <td class="right"><a class="btn btn-sm" href="{{ route('owner.statements.show', $s->period) }}">Open</a> @if($s->status !== 'draft')<a class="btn btn-sm btn-ghost" href="{{ route('owner.statements.show', ['period' => $s->period, 'print' => 1]) }}" target="_blank"><x-icon name="print"/></a>@endif</td>
      </tr>
    @empty<tr><td colspan="6" class="empty">No owner packs yet.</td></tr>@endforelse</tbody>
  </table></div></div>
</div>
<div>
  @if($canOp)
  <div class="card" id="prepareCard"><div class="card-head"><h3><x-icon name="plus"/> Prepare a pack</h3></div><div class="card-body">
    <form method="post" action="{{ route('owner.statements.prepare') }}" id="prepareForm">@csrf
      <div class="field"><label for="spPeriod">Month</label><select name="period" id="spPeriod" required>@foreach($periods as $k => $l) @continue(isset($have[$k]))<option value="{{ $k }}" @selected(in_array($k, $packsDue, true))>{{ $l }}</option>@endforeach</select></div>
      <button class="btn btn-primary mt-1"><x-icon name="book"/> Prepare</button>
    </form>
    <p class="tiny muted">A pack is the month's USALI statement, KPIs, management-fee calculation, FF&E reserve and capex status with the general manager's commentary. It can be issued once the month is closed; the figures are frozen when it is issued.</p>
  </div></div>
  @endif
  <div class="card mt-1" id="dueCard"><div class="card-head"><h3><x-icon name="clock"/> Closed months without an issued pack</h3></div><div class="card-body">
    @forelse($packsDue as $p)<div class="between-row small"><span>{{ \Illuminate\Support\Carbon::parse($p.'-01')->format('F Y') }}</span>@if(isset($have[$p]))<a href="{{ route('owner.statements.show', $p) }}">draft ›</a>@endif</div>
    @empty<div class="tiny muted">All closed months have been reported to the owner.</div>@endforelse
  </div></div>
</div>
</div>
@endsection
