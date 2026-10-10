@extends('layouts.print')
@section('title', 'Message '.$m->message_no)
@section('doctitle', $m->isParcel() ? 'Parcel Advice' : 'While You Were Out')
@section('docmeta')<div class="small"><b>{{ $m->message_no }}</b></div><div class="small">{{ $m->created_at->format('d M Y H:i') }}</div>@endsection
@section('content')
<table class="table" style="margin-top:1rem;font-size:1rem" id="slipTable">
  <tr><th style="width:28%">For</th><td><b>{{ $m->guest?->full_name ?? '—' }}</b></td></tr>
  <tr><th>Room</th><td><b style="font-size:1.3rem">{{ $m->stay?->room?->room_no ?? '—' }}</b></td></tr>
  <tr><th>From</th><td>{{ $m->from_name }}{{ $m->from_company ? ', '.$m->from_company : '' }}</td></tr>
  @if($m->from_phone)<tr><th>Please call back</th><td>{{ $m->from_phone }}</td></tr>@endif
  <tr><th>Received</th><td>{{ \App\Models\GuestMessage::CHANNELS[$m->channel] ?? $m->channel }} · taken by {{ $m->taker?->name ?? '—' }}</td></tr>
  @if($m->isParcel())<tr><th>Parcel</th><td>{{ $m->pieces }} piece(s){{ $m->courier ? ' · '.$m->courier : '' }}{{ $m->tracking_no ? ' · AWB '.$m->tracking_no : '' }} – please collect at the front desk.</td></tr>@endif
  @if($m->body)<tr><th>{{ $m->isParcel() ? 'Description' : 'Message' }}</th><td style="white-space:pre-line;min-height:6rem">{{ $m->body }}</td></tr>@endif
  @if($m->urgent)<tr><th>Priority</th><td><b>URGENT</b></td></tr>@endif
</table>
<p class="small" style="margin-top:1.5rem">@if($m->isParcel())Collected by: __________________________ &nbsp; Signature: ______________ &nbsp; Date/time: ______________ @else Delivered by: ______________ &nbsp; Time: ______________ @endif</p>
@endsection
