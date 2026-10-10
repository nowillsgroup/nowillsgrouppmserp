@extends('layouts.app')
@section('title', 'Table QR Codes')
@section('crumbs') <a href="{{ route('pos.index', ['outlet' => $outlet?->id]) }}">Point of Sale</a> / Table QR Codes @endsection
@section('actions')
<button class="btn btn-primary" onclick="window.print()"><x-icon name="print"/> Print QR sheet</button>
@endsection
@section('content')
@if(!$enabled)<div class="alert alert-warn"><x-icon name="alert"/><div>QR self-ordering is switched off in Hotel Settings – scanned codes will show “not found”.</div></div>@endif
<div class="tabs no-print">@foreach($outlets as $o)<a href="{{ route('pos.qr', ['outlet' => $o->id]) }}" class="{{ $o->id === $outlet?->id ? 'active' : '' }}">{{ $o->name }}</a>@endforeach</div>
<p class="small muted no-print">Guests scan the code on their table to browse the menu and place orders. Orders appear on the POS terminal as <b>pending</b> and are only sent to the kitchen and billed once staff accept them. Regenerate a code if a printed card is lost or misused.</p>
@if($tables->isEmpty())<x-empty title="No tables" text="Add tables for this outlet under POS › Tables." icon="grid"/>@endif
<div class="qr-grid">
@foreach($tables as $t)
  <div class="qr-card">
    <div class="qr-hotel">{{ hotel()->name }}</div>
    <div class="qr-outlet">{{ $outlet->name }}</div>
    <div class="qr-svg">{!! $codes[$t->id]['svg'] !!}</div>
    <div class="qr-table">Table {{ $t->table_no }}</div>
    <div class="qr-hint">Scan to view menu &amp; order</div>
    <div class="no-print flex gap-1 mt-1 justify-center">
      <a class="btn btn-sm" href="{{ $codes[$t->id]['url'] }}" target="_blank">Open</a>
      @if(can_do('masters.manage'))<form method="post" action="{{ route('pos.qr.regenerate', $t) }}" data-confirm="Issue a new QR code for table {{ $t->table_no }}? The old printed code will stop working.">@csrf<button class="btn btn-sm btn-ghost">Regenerate</button></form>@endif
    </div>
  </div>
@endforeach
</div>
@endsection
@push('head')
<style>
.qr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem}
.qr-card{background:#fff;border:1px dashed #94a3b8;border-radius:14px;padding:1rem;text-align:center;break-inside:avoid}
.qr-hotel{font-weight:700;font-size:.95rem}.qr-outlet{font-size:.8rem;color:#64748b}
.qr-svg svg{width:170px;height:170px;margin:.5rem auto;display:block}
.qr-table{font-size:1.3rem;font-weight:800;letter-spacing:.02em}.qr-hint{font-size:.75rem;color:#475569}
@media print{.sidebar,.topbar,.page-head,.no-print,.alert{display:none!important}.main,.content{margin:0!important;padding:0!important}.qr-grid{grid-template-columns:repeat(3,1fr)}}
</style>
@endpush
