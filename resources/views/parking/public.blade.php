@extends('public.layout')
@section('title', 'Your car')
@section('content')
<div class="card" style="max-width:420px;margin:0 auto;text-align:center" id="valetPage">
  <div style="background:#0f172a;color:#fff;padding:1.1rem;border-radius:12px 12px 0 0">
    <div style="font-size:.75rem;letter-spacing:.14em;text-transform:uppercase;opacity:.8">{{ $hotel->name }}</div>
    <div style="font-size:1.4rem;font-weight:900;letter-spacing:.18em;margin-top:.2rem">VALET</div>
  </div>
  <div class="card-body">
    <div class="mono" style="font-size:1.6rem;font-weight:800;letter-spacing:.08em" id="valetPlate">{{ $s->plate }}</div>
    <div class="muted small">{{ $s->vehicle?->description() }} · ticket {{ $s->number }}</div>
    @include('partials.flash')
    @if($s->status === 'parked')
      <p class="mt-1">Your car is parked safely. Press the button about {{ $cfg['sla_minutes'] }} minutes before you need it.</p>
      <form method="post" action="{{ route('parking.public.request', ['token' => $s->token]) }}">@csrf<button class="btn btn-primary" style="font-size:1.1rem;padding:.8rem 1.6rem" id="valetRequestBtn">Bring my car</button></form>
    @elseif($s->status === 'requested')
      <h3 class="mt-1" id="valetState">On its way</h3><p class="small muted">Requested at {{ $s->requested_at->format('H:i') }} – please come to the main entrance.</p>
    @elseif($s->status === 'ready')
      <h3 class="mt-1" id="valetState">Waiting for you at the entrance</h3>
    @else
      <h3 class="mt-1" id="valetState">This ticket is closed.</h3>
    @endif
    <p class="tiny muted mt-2">Questions? Call {{ $hotel->phone }}.</p>
  </div>
</div>
@endsection
