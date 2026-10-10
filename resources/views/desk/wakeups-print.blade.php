@extends('layouts.print')
@section('title', 'Wake-up sheet')
@section('doctitle', 'Wake-up Call Sheet')
@section('docmeta')<div class="small">Next 24 hours from {{ $from->format('d M Y H:i') }}</div><div class="small">{{ $calls->count() }} call(s)</div>@endsection
@section('content')
<table class="table" style="margin-top:1rem" id="wakeSheet"><thead><tr><th style="width:14%">Time</th><th style="width:9%">Room</th><th>Guest</th><th>Instructions</th><th style="width:9%">1st</th><th style="width:9%">2nd</th><th style="width:9%">3rd</th><th style="width:12%">Initials</th></tr></thead><tbody>
@forelse($calls as $w)
  <tr><td><b>{{ $w->call_at->format('H:i') }}</b> <span class="small">{{ $w->call_at->format('d M') }}</span></td><td><b>{{ $w->stay?->room?->room_no }}</b></td><td>{{ \App\Services\GuestMessageService::stayGuest($w->stay)?->full_name }}{{ $w->daily ? ' (daily)' : '' }}</td><td class="small">{{ $w->note }}</td><td></td><td></td><td></td><td></td></tr>
@empty<tr><td colspan="8">No wake-up calls in the next 24 hours.</td></tr>@endforelse
</tbody></table>
<p class="small" style="margin-top:1rem">Tick the attempt column when the call is made (✓ answered, ✗ no answer). After the last unanswered attempt send someone to knock on the door and record it in the log book.</p>
@endsection
