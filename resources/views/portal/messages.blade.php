@extends('portal.layout')
@section('title', 'Messages')
@section('heading', 'Messages & parcels')
@section('sub', 'Messages and deliveries the front desk is holding for you.')
@section('content')
<div class="gp-card" id="gpMessages">
  @forelse($list as $m)
    <div class="gp-row" data-msg="{{ $m->message_no }}" style="align-items:flex-start">
      <div style="flex:1">
        <b class="small">{{ $m->isParcel() ? '📦 Parcel' : '✉ Message' }} from {{ $m->from_name }}{{ $m->from_company ? ', '.$m->from_company : '' }}</b>
        @if(in_array($m->id, $unseen))<span class="st new">New</span>@endif
        <div class="tiny muted">{{ $m->created_at->format('D d M · H:i') }}</div>
        @if($m->isParcel())
          <div class="small">{{ $m->pieces }} piece{{ $m->pieces > 1 ? 's' : '' }}{{ $m->courier ? ' via '.$m->courier : '' }} – {{ $m->status === 'delivered' ? 'collected' : 'please collect it at the front desk' }}.</div>
        @elseif($m->body)
          <div class="small" style="white-space:pre-line;margin-top:.2rem">{{ $m->body }}</div>
        @endif
        @if($m->from_phone && ! $m->isParcel())<div class="tiny">Call back: <a href="tel:{{ $m->from_phone }}">{{ $m->from_phone }}</a></div>@endif
      </div>
      <span class="st {{ $m->status === 'delivered' || ! $m->isParcel() ? 'done' : 'acknowledged' }}">{{ $m->isParcel() ? ($m->status === 'delivered' ? 'Collected' : 'At the desk') : 'Read' }}</span>{{-- opening this page marks messages read --}}
    </div>
  @empty
    <p class="muted small m-0" id="gpNoMessages">No messages for you right now.</p>
  @endforelse
</div>
@endsection
