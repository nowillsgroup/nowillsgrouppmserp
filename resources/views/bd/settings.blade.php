@extends('layouts.app')
@section('title', 'Business Development Settings')
@section('crumbs') Business Development / Settings @endsection
@section('content')
<div class="card" style="max-width:44rem" id="bdSettings"><div class="card-body"><form method="post" action="{{ route('bd.settings.save') }}" class="form-grid">@csrf
  <div class="field"><label>Hurdle (discount) rate %</label><input type="number" name="discount_rate" min="0" max="60" step="0.01" required value="{{ $cfg['discount_rate'] }}"><div class="tiny muted">Used for the NPV of initiatives – cedi projects usually need 20–30%.</div></div>
  <div class="field"><label>Rate shop goes stale after (days)</label><input type="number" name="shop_stale_days" min="1" max="60" required value="{{ $cfg['shop_stale_days'] }}"></div>
  <div class="field"><label>Partnership renewal notice (days)</label><input type="number" name="partner_notice_days" min="7" max="180" required value="{{ $cfg['partner_notice_days'] }}"></div>
  <div class="field"><label>Our benchmark room type</label><select name="benchmark_room_type"><option value="">Cheapest ({{ $types->first()?->name }})</option>@foreach($types as $t)<option value="{{ $t->id }}" @selected($cfg['benchmark_room_type'] === $t->id)>{{ $t->name }}</option>@endforeach</select><div class="tiny muted">Its live rate is our rate in rate shops.</div></div>
  <div class="field span-all"><button class="btn btn-primary">Save settings</button></div>
</form></div></div>
@endsection
