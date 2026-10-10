@extends('layouts.app')
@section('title', 'Room Count')
@section('crumbs') <a href="{{ route('audit.index') }}">Income Audit</a> / Room count @endsection
@section('actions')@if(can_do('income_audit.authorise') || can_do('income_audit.view'))<a class="btn" href="{{ route('audit.discrepancies') }}"><x-icon name="alert"/> Discrepancies</a>@endif @endsection
@section('content')
<div class="alert alert-info"><x-icon name="broom"/> Physical room check for <b>{{ dmy($date, 'D d M Y') }}</b>: go to every room and record what you see – occupied (guest or luggage in the room), vacant, or out of order. @if($blind) Front-office status is hidden so the count is independent. @endif Differences go to front office to resolve.</div>
<form method="post" action="{{ route('audit.count.save') }}" id="countForm">@csrf
<div class="card flush"><div class="card-head"><h3><x-icon name="bed"/> {{ $rooms->count() }} rooms</h3><span class="small muted">{{ $done->count() }} counted today</span></div><div class="card-body table-wrap"><table class="table table-compact">
  <thead><tr><th>Room</th><th>Type</th>@unless($blind)<th>Front office</th>@endunless<th>Housekeeping sees</th><th>Luggage</th>@unless($blind)<th>Result</th>@endunless</tr></thead>
  <tbody>@foreach($rooms as $r) @php $c = $done[$r->id] ?? null; $v = old("rooms.{$r->id}.hk", $c?->hk_status); @endphp
    <tr data-room="{{ $r->room_no }}"><td class="bold">{{ $r->room_no }}</td><td class="small">{{ $r->roomType?->name }}</td>
      @unless($blind)<td class="small">{{ str_replace('_', ' ', \App\Services\IncomeAuditService::foStatus($r)) }}</td>@endunless
      <td class="rc-opts">@foreach(\App\Models\RoomCount::HK as $k => $l)<label class="rc-opt"><input type="radio" name="rooms[{{ $r->id }}][hk]" value="{{ $k }}" @checked($v === $k)> {{ $l }}</label>@endforeach</td>
      <td><label class="rc-opt"><input type="checkbox" name="rooms[{{ $r->id }}][luggage]" value="1" @checked(old("rooms.{$r->id}.luggage", $c?->luggage))> yes</label></td>
      @unless($blind)<td class="small">@if($c)<span class="badge badge-{{ $c->result === 'ok' ? 'green' : ($c->resolution ? 'slate' : 'red') }}">{{ $c->result === 'ok' ? 'agrees' : str_replace('_', ' ', $c->result) }}</span>@endif</td>@endunless
    </tr>@endforeach</tbody>
</table></div></div>
<button class="btn btn-primary mt-1" id="countSave"><x-icon name="check"/> Save room count</button>
</form>
@endsection
