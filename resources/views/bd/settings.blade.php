@extends('layouts.app')
@section('title', 'Business Development Settings')
@section('crumbs') Business Development / Settings @endsection
@section('content')
@include('bd._workspace-nav')
@php $selectedBenchmarkType = $cfg['benchmark_room_type'] && $types->contains('id', $cfg['benchmark_room_type']) ? $cfg['benchmark_room_type'] : ''; @endphp
<div class="card" style="max-width:54rem" id="bdSettings">
  <div class="card-head">
    <div><h3><x-icon name="tool"/> Planning &amp; reminder controls</h3><p class="tiny muted mt-1">These settings apply to {{ hotel()->name }} only.</p></div>
    <span class="badge badge-slate">Property settings</span>
  </div>
  <div class="card-body">
    <div class="alert alert-info mb-1"><x-icon name="alert"/><div>These controls affect forecasts, portfolio reminders, and the reference rate used in manual rate shops. They do not publish rates or change agreements and reservations. Changing the hurdle rate recalculates NPV displays; approved business-case inputs remain frozen.</div></div>
    <form method="post" action="{{ route('bd.settings.save') }}" class="form-grid" id="bdSettingsForm">@csrf
      <div class="field">
        <label for="discountRate">Hurdle (discount) rate (%)</label>
        <input type="number" id="discountRate" name="discount_rate" min="0" max="60" step="0.01" required value="{{ old('discount_rate', $cfg['discount_rate']) }}">
        <span class="tiny muted">Used to discount future annual net contributions when calculating initiative NPV. Use the rate approved for your property's business cases.</span>
      </div>
      <div class="field">
        <label for="shopStaleDays">Rate-shop reminder after (days)</label>
        <input type="number" id="shopStaleDays" name="shop_stale_days" min="1" max="60" required value="{{ old('shop_stale_days', $cfg['shop_stale_days']) }}">
        <span class="tiny muted">The dashboard flags the competitive-set rate shop when no recent shop is recorded within this interval. It does not fetch rates from other hotels.</span>
      </div>
      <div class="field">
        <label for="partnerNoticeDays">Partnership renewal notice (days)</label>
        <input type="number" id="partnerNoticeDays" name="partner_notice_days" min="7" max="180" required value="{{ old('partner_notice_days', $cfg['partner_notice_days']) }}">
        <span class="tiny muted">Active agreements ending within this window are flagged for review.</span>
      </div>
      <div class="field">
        <label for="benchmarkRoomType">Our benchmark room type</label>
        <select id="benchmarkRoomType" name="benchmark_room_type">
          <option value="" @selected((string) old('benchmark_room_type', $selectedBenchmarkType) === '')>Cheapest active{{ $types->first() ? ' ('.$types->first()->name.')' : ' (no active room types configured)' }}</option>
          @foreach($types as $t)<option value="{{ $t->id }}" @selected((string) old('benchmark_room_type', $selectedBenchmarkType) === (string) $t->id)>{{ $t->name }}</option>@endforeach
        </select>
        <span class="tiny muted">Current reference: {{ $type?->name ?? 'no active room type' }}. Its live BAR is used in rate shops; selecting a type does not publish or change its rate.</span>
      </div>
      <div class="field span-all"><button type="submit" class="btn btn-primary">Save settings</button></div>
    </form>
  </div>
</div>
@endsection
