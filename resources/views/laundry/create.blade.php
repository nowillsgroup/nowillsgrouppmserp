@extends('layouts.app')
@section('title', 'Collect Laundry')
@section('crumbs') <a href="{{ route('laundry.index') }}">Laundry orders</a> / Collect @endsection
@section('content')
<form method="post" action="{{ route('laundry.store') }}" id="laundryForm">@csrf
<div class="grid g-2-1">
<div class="card"><div class="card-head"><h3>Who & how fast</h3></div><div class="card-body form-grid">
  <div class="field span-all"><label>Customer</label>
    <div class="flex gap-2"><label class="check"><input type="radio" name="type" value="guest" @checked(old('type', 'guest') === 'guest') onchange="lfType()"> In-house guest</label>
    <label class="check"><input type="radio" name="type" value="walk_in" @checked(old('type') === 'walk_in') onchange="lfType()"> Walk-in customer (pays on delivery)</label></div></div>
  <div class="field span-2 lf-guest"><label for="lfStay">Room</label>
    <select name="reservation_room_id" id="lfStay" class="input"><option value="">Choose the room…</option>
      @foreach($stays as $s)<option value="{{ $s->id }}" @selected((int) old('reservation_room_id', $stayId) === $s->id)>{{ $s->room?->room_no }} – {{ $s->guest?->full_name }}</option>@endforeach
    </select></div>
  <div class="field lf-walk"><label>Customer name</label><input name="guest_name" class="input" value="{{ old('guest_name') }}" maxlength="150"></div>
  <div class="field lf-walk"><label>Phone</label><input name="phone" class="input" value="{{ old('phone') }}" maxlength="30"></div>
  <div class="field span-all"><label>Service</label><div class="flex gap-2">
    <label class="check"><input type="radio" name="speed" value="normal" @checked(old('speed', 'normal') === 'normal')> Normal – back in {{ $hours['normal'] }} h</label>
    <label class="check"><input type="radio" name="speed" value="express" @checked(old('speed', str_contains((string) $req?->item, 'Express') ? 'express' : 'normal') === 'express')> Express – {{ $hours['express'] }} h, +{{ rtrim(rtrim(number_format($expressPct, 2), '0'), '.') }} %</label></div></div>
  <div class="field"><label>Bag / tag no.</label><input name="bag_no" class="input" value="{{ old('bag_no') }}" maxlength="30"></div>
  <div class="field"><label>Pieces counted</label><input type="number" name="bag_pieces" min="1" max="500" class="input" value="{{ old('bag_pieces') }}"><span class="hint">At pick-up, before sorting.</span></div>
  <div class="field span-all"><label>Notes</label><input name="notes" class="input" value="{{ old('notes', $req?->notes) }}" maxlength="500" placeholder="e.g. no starch, fold don't hang"></div>
  <input type="hidden" name="guest_request_id" value="{{ old('guest_request_id', $req?->id) }}">
</div></div>
<div>
  @if($req)<div class="alert alert-info"><x-icon name="bell"/><div>From guest request <b>{{ $req->request_no }}</b> – room {{ $req->room?->room_no }}: {{ $req->item }}. It's marked <i>in progress</i> when you save and <i>done</i> on delivery.</div></div>@endif
  <div class="card"><div class="card-head"><h3>Pick-up requests</h3></div><div class="card-body">
    @forelse($requests as $gr)<a class="list-link {{ $req?->id === $gr->id ? 'active' : '' }}" href="{{ route('laundry.create', ['request' => $gr->id]) }}"><b>{{ $gr->room?->room_no }}</b> · {{ $gr->item }}<span class="tiny muted"> {{ $gr->created_at->diffForHumans() }}</span></a>
    @empty<p class="small muted m-0">No open laundry requests from the guest portal.</p>@endforelse
  </div></div>
</div>
</div>
<div class="card"><div class="card-head"><h3>Items</h3><span class="small muted">Optional now – sort the bag and add items later if you prefer.</span></div><div class="card-body">
@include('laundry._grid', ['current' => [], 'remarks' => [], 'readonly' => false])
</div></div>
<button class="btn btn-primary"><x-icon name="check"/> Save laundry order</button>
</form>
<script>
function lfType() {
  const walk = document.querySelector('[name=type]:checked').value === 'walk_in';
  document.querySelectorAll('.lf-walk').forEach(e => e.style.display = walk ? '' : 'none');
  document.querySelectorAll('.lf-guest').forEach(e => e.style.display = walk ? 'none' : '');
}
lfType();
</script>
@endsection
