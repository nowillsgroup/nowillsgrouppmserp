@extends('portal.layout')
@section('title', 'Not available')
@section('heading', 'Not available right now')
@section('content')
<div class="gp-card"><p class="m-0">{{ $state === 'upcoming' ? 'This service opens once you have checked in.' : ($state === 'departed' ? 'Your stay has ended – your bill and feedback are still available.' : 'This service is not available at the moment. Please call the front desk.') }}</p>
<a class="btn btn-block mt-1" href="{{ route('portal.home', $token) }}"><x-icon name="home"/> Back to my stay</a></div>
@endsection
