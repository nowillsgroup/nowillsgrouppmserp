@extends('layouts.print')
@section('title', 'Shift hand-over')
@section('doctitle', 'Shift Hand-over')
@section('docmeta')<div class="small"><b>{{ \App\Models\LogEntry::SHIFTS[$shift] }} shift</b> · {{ now()->format('d M Y H:i') }}</div><div class="small">Business date {{ bdate()->format('d M Y') }} · since {{ $since->format('d M H:i') }}</div>@endsection
@section('content')
@php $s = $snap; @endphp
<table class="table" style="margin-top:1rem" id="hoSnap"><tr>
  <th>In house</th><td>{{ $s['inhouse'] }}</td><th>Arrivals pending</th><td>{{ $s['arrivals_pending'] }}</td><th>Departures pending</th><td>{{ $s['departures_pending'] }}</td></tr>
  <tr><th>Wake-ups (12 h)</th><td>{{ $s['wakeups_next12'] }}</td><th>Messages / parcels</th><td>{{ $s['messages'] }} / {{ $s['parcels'] }}</td><th>Open traces</th><td>{{ $s['traces_today'] }}</td></tr></table>
@foreach(['Pinned' => $pinned, 'Logged since '.$since->format('H:i') => $recent, 'Still open from earlier' => $open] as $h => $list)
  @if($list->isNotEmpty())
  <h3 style="margin:1rem 0 .3rem">{{ $h }}</h3>
  <table class="table"><thead><tr><th style="width:14%">Time</th><th style="width:14%">Type</th><th>Entry</th><th style="width:8%">Room</th><th style="width:14%">By</th><th style="width:10%">Status</th></tr></thead><tbody>
  @foreach($list as $e)<tr><td>{{ $e->created_at->format('d M H:i') }}</td><td>{{ $e->categoryLabel() }}{{ $e->priority !== 'normal' ? ' · '.strtoupper($e->priority) : '' }}</td><td><b>{{ $e->title }}</b>@if($e->body)<br><span class="small">{{ \Illuminate\Support\Str::limit($e->body, 300) }}</span>@endif</td><td>{{ $e->stay?->room?->room_no ?? $e->room?->room_no }}</td><td>{{ $e->source === 'system' ? 'System' : $e->author?->name }}</td><td>{{ ['open' => 'Open', 'resolved' => 'Resolved', 'note' => 'Note'][$e->status] }}</td></tr>@endforeach
  </tbody></table>
  @endif
@endforeach
@if($wakeups->isNotEmpty())<h3 style="margin:1rem 0 .3rem">Wake-up calls</h3><table class="table"><tbody>@foreach($wakeups as $w)<tr><td style="width:18%">{{ $w->call_at->format('d M H:i') }}</td><td style="width:10%">{{ $w->stay?->room?->room_no }}</td><td>{{ \App\Services\GuestMessageService::stayGuest($w->stay)?->full_name }} {{ $w->note ? '– '.$w->note : '' }}</td></tr>@endforeach</tbody></table>@endif
@if($traces->isNotEmpty())<h3 style="margin:1rem 0 .3rem">Traces due</h3><table class="table"><tbody>@foreach($traces as $t)<tr><td style="width:18%">{{ $t->due_time ? substr($t->due_time, 0, 5) : 'Today' }}</td><td style="width:18%">{{ \App\Services\TraceService::DEPARTMENTS[$t->department] ?? '' }}</td><td>{{ $t->text }}</td></tr>@endforeach</tbody></table>@endif
<table style="width:100%;margin-top:2.5rem"><tr><td style="width:50%">Handed over by: ______________________</td><td>Taken over by: ______________________</td></tr></table>
@endsection
