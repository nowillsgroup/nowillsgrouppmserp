@extends('layouts.app')
@section('title', $title ?? 'Reservation')
@if(($inner ?? null) === 'reservations.desk-room')
  @section('hidePageHead', '1')
@endif
@section('content')
@if(($inner ?? null) === 'reservations.desk-room')
  <nav class="room-page-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('reservations.index', ['view' => 'board']) }}">Room board</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ $title ?? 'Room' }}</span>
  </nav>
  <div class="room-page-frame">@include($inner)</div>
@else
  <p class="small"><a href="{{ route('reservations.index') }}">← Reservation desk</a></p>
  <div class="card"><div class="card-body">
    @include($inner)
  </div></div>
@endif
@endsection
