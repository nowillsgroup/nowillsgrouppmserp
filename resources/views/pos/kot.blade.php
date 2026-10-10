@extends('layouts.print')
@section('title', 'KOT '.$kot)
@section('pageclass', 'kot-sheet')
@section('nohead', true)
@section('content')
@php
  $svc = app(\App\Services\KotPrintService::class);
  $counts = $all->where('is_cancelled', false)->groupBy(fn ($l) => (int) ($l->kot_printer_id ?: 0))->map->count();
  $picked = collect($picked ?? []);
@endphp
<div class="no-print card" style="margin-bottom:1rem;padding:1rem">
  <div class="flex justify-between items-center gap-1" style="flex-wrap:wrap">
    <div><b>Print KOT #{{ $kot }}</b> <span class="tiny muted">{{ $order->order_no }} · {{ $svc->whereText($order) }}</span></div>
    <a class="btn btn-sm" href="{{ route('pos.terminal', $order) }}">Back to order</a>
  </div>
  <p class="small muted" style="margin:.4rem 0 .6rem">Each station printer gets only its dishes. Open each printer on the PC that feeds it, leave a <a href="{{ route('kitchen.station') }}">print station</a> running, or send a network printer directly.</p>
  @if($printers->isEmpty())
    <p class="small">No printers are set up yet — this is one combined ticket. <a href="{{ route('pos.printers') }}">Add printers</a> to split hot, bar, pastry and a captain copy.</p>
  @else
  <form method="get" action="{{ route('pos.printkot', [$order, $kot]) }}" id="kotPick">
    <div class="kot-picks">
      @foreach($printers as $p)
        @php $n = $counts[$p->id] ?? 0; @endphp
        <label class="kot-pick">
          <input type="checkbox" class="kot-pick-box" name="printers[]" value="{{ $p->id }}" @checked($picked->contains($p->id) || ($picked->isEmpty() && $n))>
          <span><b>{{ $p->name }}</b> <span class="tiny muted">{{ $p->role === 'captain' ? 'every item' : ($n ? $n.' item'.($n === 1 ? '' : 's') : 'no items') }} · {{ $p->connection === 'network' ? $p->host : 'browser' }}{{ $p->copies > 1 ? ' · '.$p->copies.' copies' : '' }}</span></span>
        </label>
      @endforeach
    </div>
    <div class="flex gap-1 mt-1" style="flex-wrap:wrap;align-items:center">
      <label class="check"><input type="checkbox" name="combined" value="1" @checked(request()->boolean('combined'))> Combined sheet</label>
      <label class="check"><input type="checkbox" name="void" value="1" @checked(request()->boolean('void'))> Cancelled items only</label>
      <label class="check"><input type="checkbox" name="force" value="1" form="kotSend"> Send every item to each selected printer</label>
      <button class="btn btn-sm btn-primary" type="submit">Show selected</button>
      <button class="btn btn-sm btn-accent" type="button" id="kotOpenEach"><x-icon name="print" size="sm"/> Open each printer</button>
      <button class="btn btn-sm" type="submit" form="kotSend">Send to network printers</button>
      <a class="btn btn-sm" href="{{ route('pos.printkot', [$order, $kot]) }}">All slips</a>
    </div>
  </form>
  <form method="post" action="{{ route('pos.printkot.send', [$order, $kot]) }}" id="kotSend">@csrf
    <input type="hidden" name="combined" value="0" id="kotSendCombined">
  </form>
  @endif
</div>

@forelse($slips as $si => $slip)
  @for($copy = 1; $copy <= ($slip['copies'] ?? 1); $copy++)
    @include('pos.kot-slip', ['slip' => $slip, 'order' => $order, 'kot' => $kot, 'copy' => $copy, 'firstAllergy' => $si === 0 && $copy === 1])
  @endfor
@empty
  <div class="kot-slip"><div class="center"><b>KITCHEN ORDER TICKET</b><br>Nothing to print on this KOT.</div></div>
@endforelse
@endsection
@push('scripts')
<script>
document.getElementById('kotOpenEach')?.addEventListener('click', () => {
  const ids = [...document.querySelectorAll('.kot-pick-box:checked')].map(i => i.value);
  if (!ids.length) { alert('Select at least one printer.'); return; }
  const base = @json(route('pos.printkot', [$order, $kot]));
  ids.forEach((id, n) => setTimeout(() => window.open(base + '?printer=' + id + '&autoprint=1', 'kotp' + id), n * 400));
});
document.getElementById('kotSend')?.addEventListener('submit', () => {
  const form = document.getElementById('kotSend');
  form.querySelectorAll('input[name="printers[]"]').forEach(el => el.remove());
  document.querySelectorAll('.kot-pick-box:checked').forEach(box => {
    const h = document.createElement('input'); h.type = 'hidden'; h.name = 'printers[]'; h.value = box.value; form.appendChild(h);
  });
  const c = document.getElementById('kotSendCombined');
  if (c) c.value = document.querySelector('#kotPick [name=combined]')?.checked ? '1' : '0';
});
</script>
@endpush
