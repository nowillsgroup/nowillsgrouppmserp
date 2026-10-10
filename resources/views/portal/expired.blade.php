@extends('portal.layout')
@section('title', 'Link expired')
@section('heading', 'This link has expired')
@section('sub', $hotel->name)
@section('content')
<div class="gp-card center"><x-icon name="lock" style="width:40px;height:40px;color:#64748b"/>
  <p>For your privacy, the guest portal is only available during your stay{{ (int) setting('portal_days_after', 2) > 0 ? ' and for '.(int) setting('portal_days_after', 2).' day(s) after check-out' : '' }}.</p>
  <p class="small muted m-0">Need a copy of your invoice? Contact us{{ $hotel->email ? ' at '.$hotel->email : '' }}{{ $hotel->phone ? ' or '.$hotel->phone : '' }}.</p></div>
@endsection
