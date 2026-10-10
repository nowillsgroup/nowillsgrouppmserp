@extends('layouts.app')
@section('title', $block->block_no.' · '.$block->name)
@section('crumbs') Reservations / Group Blocks / {{ $block->block_no }} @endsection
@section('actions')
  @if($block->isHolding())
    @if(can_do('reservations.edit'))
      <a href="{{ route('blocks.edit', $block) }}" class="btn"><x-icon name="edit"/> Edit</a>
      @if($block->status === 'tentative')<form method="post" action="{{ route('blocks.status', $block) }}" class="inline">@csrf<input type="hidden" name="action" value="definite"><button class="btn">Mark definite</button></form>@endif
      <form method="post" action="{{ route('blocks.status', $block) }}" class="inline" data-confirm="Release all unpicked rooms back to general inventory now?">@csrf<input type="hidden" name="action" value="release"><button class="btn">Release now</button></form>
      <form method="post" action="{{ route('blocks.status', $block) }}" class="inline" data-confirm="Cancel this block? Reservations already picked up are kept.">@csrf<input type="hidden" name="action" value="cancel"><button class="btn btn-danger">Cancel block</button></form>
    @endif
  @endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Status" :value="ucfirst($block->status)" icon="layers" tone="{{ $block->status === 'definite' ? 'green' : ($block->isHolding() ? 'amber' : 'slate') }}"/>
  <x-stat label="Stay" :value="$block->arrival_date->format('d M').' → '.$block->departure_date->format('d M')" icon="calendar" tone="blue"/>
  <x-stat label="Cut-off" :value="$block->cutoff_date->format('d M Y')" icon="clock" tone="{{ $block->isHolding() && $block->cutoff_date->lte(bdate()->copy()->addDays(3)) ? 'red' : 'slate' }}"/>
  <x-stat label="Pickup" :value="$pct.'%'" icon="users" tone="purple"/>
</div>
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Pickup by night</h3><span class="tiny muted">picked / held</span></div><div class="card-body table-wrap">
    <table class="table"><thead><tr><th>Type</th>@foreach(array_keys(reset($grid) ?: []) as $d)<th class="center nowrap">{{ \Carbon\Carbon::parse($d)->format('D d') }}</th>@endforeach</tr></thead><tbody>
    @foreach($block->lines as $l)
      <tr><td class="bold">{{ $l->roomType->code }}</td>
      @foreach($grid[$l->room_type_id] ?? [] as $d => $c)
        <td class="center"><span class="badge {{ $c['picked'] >= $c['held'] ? 'badge-green' : ($c['picked'] ? 'badge-amber' : 'badge-slate') }}">{{ $c['picked'] }}/{{ $c['held'] }}</span></td>
      @endforeach</tr>
    @endforeach
    </tbody></table>
    <div class="small" style="margin-top:.6rem">
      <div><b>Company:</b> {{ $block->company?->name ?? '—' }} · <b>Source:</b> {{ $block->source?->name ?? '—' }} · <b>Billing:</b> {{ $block->billing_instruction === 'company' ? 'Bill to company' : 'Guest pays' }}</div>
      <div><b>Contact:</b> {{ $block->contact_name ?? '—' }} {{ $block->contact_phone }} {{ $block->contact_email }}</div>
      <div><b>Rate:</b> {{ $block->rate !== null ? money($block->rate).' / night' : 'Rate plan' }}</div>
      @if($block->notes)<div class="muted">{{ $block->notes }}</div>@endif
      @if($block->released_at)<div class="muted">{{ ucfirst($block->status) }} {{ $block->released_at->format('d M Y H:i') }}</div>@endif
    </div>
  </div></div>
  <div class="card"><div class="card-head"><h3>Rooming list pickup</h3></div><div class="card-body">
    @if($block->isHolding() && can_do('reservations.create'))
    <form method="post" action="{{ route('blocks.pickup', $block) }}" class="form-grid">@csrf
      <x-field name="names" label="Guest names (one per line)" type="textarea" rows="6" required class="span-all" placeholder="One guest per line, e.g. Kwame Mensah" hint="Paste the rooming list straight from the organiser's e-mail or Excel"/>
      <x-field name="room_type_id" label="Room type" type="select" :options="$block->lines->mapWithKeys(fn($l) => [$l->room_type_id => $l->roomType->name])" :placeholder="false" required/>
      <x-field name="adults" label="Adults / room" type="number" min="1" max="6" value="2" required/>
      <x-field name="arrival_date" label="Arrival" type="date" :value="$block->arrival_date->toDateString()" required/>
      <x-field name="departure_date" label="Departure" type="date" :value="$block->departure_date->toDateString()" required/>
      <div class="span-all"><button class="btn btn-primary"><x-icon name="users" size="sm"/> Create reservations</button> <span class="tiny muted">One confirmed reservation per name, linked to this block{{ $block->company ? ' and '.$block->company->name : '' }}.</span></div>
    </form>
    @else
      <p class="small muted">This block is {{ $block->status }}. Rooms are no longer held, so add guests as normal reservations.</p>
    @endif
  </div></div>
</div>
<div class="card flush"><div class="card-head" style="padding:1rem 1.2rem 0"><h3>Picked-up reservations ({{ $block->reservations->count() }})</h3></div><div class="card-body table-wrap">
  <table class="table"><thead><tr><th>Res. No</th><th>Guest</th><th>Arrival</th><th>Departure</th><th>Room</th><th>Rate</th><th>Status</th></tr></thead><tbody>
  @forelse($block->reservations->sortBy('arrival_date') as $r)
    @foreach($r->rooms as $s)
    <tr><td><a href="{{ route('reservations.show', $r) }}" class="bold">{{ $r->reservation_no }}</a></td><td>{{ $r->guest->full_name }}</td>
      <td>{{ $s->arrival_date->format('d M') }}</td><td>{{ $s->departure_date->format('d M') }}</td>
      <td>{{ $s->room?->room_no ?? $s->roomType?->code }}</td><td>{{ money($s->rate) }}</td><td>{!! status_badge($s->status) !!}</td></tr>
    @endforeach
  @empty<tr><td colspan="7" class="empty">No guests picked up yet.</td></tr>@endforelse
  </tbody></table>
</div></div>
@endsection
