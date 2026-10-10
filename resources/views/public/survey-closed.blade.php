@extends('public.layout')
@section('title', 'Survey closed')
@section('content')
<div class="card" style="max-width:640px;margin:0 auto" id="surveyClosed"><div class="card-body center" style="padding:2.2rem 1.5rem">
  <h2>This survey has closed</h2>
  <p class="muted mt-1">Thank you for your interest. The feedback window for this stay has ended. You're always welcome to write to us{{ $hotel->email ? ' at '.$hotel->email : '' }}.</p>
</div></div>
@endsection
