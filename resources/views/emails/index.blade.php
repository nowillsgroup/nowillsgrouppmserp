@extends('layouts.app')
@section('title', 'E-mail Log')
@section('crumbs') Administration / E-mail Log @endsection
@section('content')
<div class="grid g-3 mb-2">
  <div class="stat"><div class="stat-label">Sent</div><div class="stat-value text-green">{{ $stats['sent'] ?? 0 }}</div></div>
  <div class="stat"><div class="stat-label">Failed</div><div class="stat-value text-red">{{ $stats['failed'] ?? 0 }}</div></div>
  <div class="stat"><div class="stat-label">Mail transport</div><div class="stat-value" style="font-size:1.1rem">{{ strtoupper($mailer) }}</div>
    <div class="stat-sub">@if($mailer === 'log')Demo mode – mails are written to storage/logs. Set MAIL_MAILER=smtp in .env to deliver.@else Configured in .env @endif</div></div>
</div>
<div class="card flush"><div class="card-head"><h3>Guest e-mails</h3>
  <div class="flex gap-1 wrap"><a class="btn btn-sm" target="_blank" href="{{ route('emails.preview', 'reservation_confirmation') }}">Preview confirmation</a><a class="btn btn-sm" target="_blank" href="{{ route('emails.preview', 'invoice') }}">Preview invoice</a></div></div>
  <div class="card-body"><form method="get" class="filters">
    <div class="field"><input name="q" value="{{ request('q') }}" placeholder="Recipient or subject"></div>
    <div class="field"><select name="template"><option value="">All templates</option><option value="reservation_confirmation" @selected(request('template')==='reservation_confirmation')>Reservation confirmation</option><option value="invoice" @selected(request('template')==='invoice')>Invoice</option></select></div>
    <div class="field"><select name="status"><option value="">Any status</option><option @selected(request('status')==='sent')>sent</option><option @selected(request('status')==='failed')>failed</option></select></div>
    <button class="btn">Filter</button></form></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Related</th><th>Status</th><th>By</th></tr></thead><tbody>
    @forelse($logs as $l)
      <tr><td class="nowrap small">{{ $l->created_at->format('d M H:i') }}</td><td>{{ $l->to_email ?: '—' }}</td><td>{{ $l->subject }}</td>
        <td class="small">@if($l->related_type === 'Reservation')<a href="{{ route('reservations.show', $l->related_id) }}">Reservation</a>@elseif($l->related_type === 'Folio')<a href="{{ route('folios.show', $l->related_id) }}">Folio</a>@else — @endif</td>
        <td>@if($l->status === 'sent')<span class="badge badge-green">sent</span>@else<span class="badge badge-red">failed</span><div class="tiny text-red">{{ $l->error }}</div>@endif</td>
        <td class="tiny muted">{{ $l->user?->username ?? 'system' }}</td></tr>
    @empty<tr><td colspan="6"><x-empty text="No e-mails sent yet." icon="mail"/></td></tr>@endforelse
    </tbody></table></div>
  @if($logs->hasPages())<div class="card-foot">{{ $logs->links() }}</div>@endif
</div>
@endsection
