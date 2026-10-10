@extends('layouts.app')
@section('title', 'Group Blocks')
@section('crumbs') Reservations / Group Blocks @endsection
@section('actions')
  @if(can_do('reservations.create'))<a href="{{ route('blocks.create') }}" class="btn btn-primary"><x-icon name="plus"/> New Block</a>@endif
@endsection
@section('content')
<div class="tabs">
  <a href="{{ route('blocks.index') }}" class="{{ !request('status') ? 'active' : '' }}">Holding</a>
  @foreach(['tentative','definite','released','cancelled'] as $s)
    <a href="{{ route('blocks.index', ['status' => $s]) }}" class="{{ request('status') === $s ? 'active' : '' }}">{{ ucfirst($s) }}</a>
  @endforeach
</div>
<div class="card"><div class="card-body">
  <form class="filters" method="get"><input type="hidden" name="status" value="{{ request('status') }}">
    <div class="field" style="flex:1;min-width:220px"><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="Block no or group name"></div>
    <button class="btn btn-primary">Filter</button></form>
  <p class="tiny muted" style="margin-top:.5rem">A block holds rooms for a group (wedding, conference, tour). Held rooms cannot be sold elsewhere (front desk, booking engine or OTA feeds) until guests are picked up from the rooming list or the block is released. The night audit releases unpicked rooms automatically on the cut-off date.</p>
</div></div>
<div class="card flush"><div class="card-body table-wrap">
  <table class="table">
    <thead><tr><th>Block</th><th>Company / contact</th><th>Arrival</th><th>Departure</th><th>Cut-off</th><th>Rooms held</th><th>Pickup</th><th>Rate</th><th>Status</th></tr></thead>
    <tbody>
    @forelse($blocks as $b)
      <tr>
        <td><a href="{{ route('blocks.show', $b) }}" class="bold">{{ $b->block_no }}</a><div class="tiny">{{ $b->name }}</div></td>
        <td class="small">{{ $b->company?->name ?? '—' }}<div class="tiny muted">{{ $b->contact_name }} {{ $b->contact_phone }}</div></td>
        <td class="nowrap">{{ $b->arrival_date->format('d M Y') }}</td><td class="nowrap">{{ $b->departure_date->format('d M Y') }}</td>
        <td class="nowrap {{ $b->isHolding() && $b->cutoff_date->lte(bdate()->copy()->addDays(3)) ? 'text-red bold' : '' }}">{{ $b->cutoff_date->format('d M Y') }}</td>
        <td class="small">{{ $b->lines->map(fn($l) => $l->rooms.'× '.$l->roomType->code)->join(', ') }}</td>
        <td style="min-width:110px"><div class="progress"><span style="width:{{ min(100, $b->pickup_pct) }}%"></span></div><div class="tiny">{{ $b->pickup_pct }}%</div></td>
        <td>{{ $b->rate !== null ? money($b->rate) : 'Rate plan' }}</td>
        <td>{!! status_badge($b->status) !!}</td>
      </tr>
    @empty
      <tr><td colspan="9" class="empty">No blocks here.</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
{{ $blocks->links() }}
@endsection
