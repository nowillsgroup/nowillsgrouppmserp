@extends('layouts.app')
@section('title', 'Information Pages')
@section('crumbs') IPTV &amp; Guest Welcome / Information Pages @endsection
@php $u = auth()->user(); $canMg = \App\Services\IptvService::canManage($u); $P = \App\Models\TvPage::class; @endphp
@section('actions')
  @if($canMg)<button class="btn btn-primary" data-modal="tvPageModal" id="tvPageNew"><x-icon name="plus"/> New page</button>@endif
@endsection
@section('content')
<p class="small muted">The “Hotel information” tile on the TV – restaurants, spa, facilities, safety. Guests see the pages in their language; if there are none in it, the hotel default language. Places for “Explore Ghana” come from the concierge’s <a href="{{ route('concierge.guide') }}">local guide</a> (places shown on the guest portal).</p>
<div class="g-3" id="tvPageList">
@forelse($list as $p)
  <div class="card {{ $p->is_active ? '' : 'muted' }}"><div class="card-head"><h3><x-icon :name="$p->icon"/> {{ $p->title }}</h3><span class="tiny muted">{{ strtoupper($p->language) }} · {{ $P::CATEGORIES[$p->category] ?? $p->category }}</span></div><div class="card-body">
    <p class="small m-0">{!! nl2br(e(\Illuminate\Support\Str::limit($p->body, 260))) !!}</p>
    <div class="flex tiny muted" style="justify-content:space-between;align-items:center;margin-top:.5rem"><span>{{ (int) ($views[$p->title] ?? 0) }} views · 30 days{{ $p->is_active ? '' : ' · hidden' }}</span>@if($canMg)<button class="btn btn-sm" data-modal="tvPg{{ $p->id }}">Edit</button>@endif</div>
  </div></div>
@empty<div class="card"><div class="card-body muted">No pages yet.</div></div>@endforelse
</div>
@if($canMg)
<div class="modal" id="tvPageModal"><div class="modal-box lg"><form method="post" action="{{ route('iptv.pages.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>New information page</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._page_fields', ['x' => null])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@foreach($list as $p)
<div class="modal" id="tvPg{{ $p->id }}"><div class="modal-box lg"><form method="post" action="{{ route('iptv.pages.update', $p) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>{{ $p->title }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._page_fields', ['x' => $p])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endforeach
@endif
@endsection
