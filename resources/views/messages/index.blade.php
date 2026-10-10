@extends('layouts.app')
@section('title', 'SMS / WhatsApp Log')
@section('crumbs') Administration / SMS &amp; WhatsApp Log @endsection
@section('actions')@if(can_do('settings.manage'))<a class="btn" href="{{ route('messages.settings') }}"><x-icon name="message"/> Messaging settings</a>@endif @endsection
@section('content')
<div class="card flush"><div class="card-head"><h3>Guest messages</h3><span class="small text-2">Driver: <b>{{ strtoupper(setting('sms_driver', 'log')) }}</b>{{ setting('sms_driver', 'log') === 'log' ? ' – demo mode, messages are only written to the log' : '' }}</span></div>
  <div class="card-body"><form method="get" class="filters">
    <div class="field"><input name="q" value="{{ request('q') }}" placeholder="Number or text"></div>
    <div class="field"><select name="channel"><option value="">SMS &amp; WhatsApp</option><option value="sms" @selected(request('channel')==='sms')>SMS</option><option value="whatsapp" @selected(request('channel')==='whatsapp')>WhatsApp</option></select></div>
    <div class="field"><select name="status"><option value="">Any status</option><option @selected(request('status')==='sent')>sent</option><option @selected(request('status')==='failed')>failed</option></select></div>
    <button class="btn">Filter</button></form></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>When</th><th>Channel</th><th>To</th><th>Message</th><th>Type</th><th>Related</th><th>Status</th><th>By</th></tr></thead><tbody>
    @forelse($logs as $l)
      <tr><td class="nowrap small">{{ $l->created_at->format('d M H:i') }}</td>
        <td><span class="badge {{ $l->channel === 'whatsapp' ? 'badge-green' : 'badge-blue' }}">{{ $l->channel === 'whatsapp' ? 'WhatsApp' : 'SMS' }}</span><div class="tiny muted">{{ $l->driver }}</div></td>
        <td class="nowrap">{{ $l->to ?: '—' }}</td><td class="small" style="max-width:380px">{{ $l->body }}</td><td class="small">{{ str_replace('_', ' ', $l->template) }}</td>
        <td class="small">@if($l->related_type === 'Reservation')<a href="{{ route('reservations.show', $l->related_id) }}">Reservation</a>@else — @endif</td>
        <td>@if($l->status === 'sent')<span class="badge badge-green">sent</span>@else<span class="badge badge-red">failed</span><div class="tiny text-red">{{ $l->error }}</div>@endif</td>
        <td class="tiny muted">{{ $l->user?->username ?? 'system' }}</td></tr>
    @empty<tr><td colspan="8"><x-empty text="No messages yet." icon="message"/></td></tr>@endforelse
    </tbody></table></div>
  @if($logs->hasPages())<div class="card-foot">{{ $logs->links() }}</div>@endif
</div>
@endsection
