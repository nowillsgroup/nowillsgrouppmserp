@extends('layouts.app')
@section('title', $d->code.' '.$d->title)
@section('crumbs') My training / {{ $d->code }} @endsection
@section('content')
<div class="card" id="mySopBody"><div class="card-head"><h3>{{ $v?->title ?? $d->title }}</h3><span class="badge badge-green">v{{ $d->version }} · effective {{ $d->effective_date?->format('d M Y') }}</span></div>
  <div class="card-body sop-body">@if($d->summary)<p class="muted"><i>{{ $d->summary }}</i></p>@endif{!! \App\Models\SopDocument::render($v?->body) !!}</div></div>
<div class="card mt-1" id="signCard"><div class="card-body">
  @if($needs && $cfg['self_ack'] === '1')
    <form method="post" action="{{ route('me.sop.sign', $d) }}" id="signForm">@csrf
      <label class="small"><input type="checkbox" name="confirm" value="1" id="signConfirm" required> I, <b>{{ $e->name }}</b>, have read and understood <b>{{ $d->code }}</b> version {{ $d->version }} and will follow it.</label>
      <div class="mt-1"><button class="btn btn-primary" id="signBtn"><x-icon name="edit"/> Sign</button> <a class="btn" href="{{ route('me.learning') }}">Later</a></div>
    </form>
  @elseif($needs)
    <div class="small">Please sign the paper sign-off sheet with your supervisor.</div>
  @elseif($signed)
    <div class="small text-green" id="signedNote">✓ You signed version {{ $signed->version }} on {{ $signed->acknowledged_at->format('d M Y') }}.</div>
  @else
    <div class="small muted">This procedure does not need your signature.</div>
  @endif
</div></div>
@endsection
