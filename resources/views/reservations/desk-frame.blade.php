@extends('layouts.app')
@section('title', $title ?? 'Reservation')
@section('content')
<p class="small"><a href="{{ route('reservations.index') }}">← Reservation desk</a></p>
<div class="card"><div class="card-body">
  @include($inner)
</div></div>
@endsection
