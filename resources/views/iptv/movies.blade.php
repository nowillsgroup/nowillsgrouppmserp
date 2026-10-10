@extends('layouts.app')
@section('title', 'Movies & Rentals')
@section('crumbs') IPTV &amp; Guest Welcome / Movies &amp; Rentals @endsection
@php $u = auth()->user(); $canMg = \App\Services\IptvService::canManage($u); $M = \App\Models\TvMovie::class; $live = $rentals->where('status', '!=', 'refunded'); @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'iptv_vod', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Rentals report</a>
  @if($canMg)<button class="btn btn-primary" data-modal="tvMovieModal" id="tvMovieNew"><x-icon name="plus"/> Add movie</button>@endif
@endsection
@section('content')
<div class="stats" id="tvVodStats">
  <x-stat label="Rentals" :value="$live->count()" icon="eye" tone="teal" :sub="\Carbon\Carbon::parse($from)->format('d M').' – '.\Carbon\Carbon::parse($to)->format('d M Y')"/>
  <x-stat label="Revenue before tax" :value="money($live->sum('amount'))" icon="money" tone="green" :sub="money($live->sum('total')).' incl. VAT & levies · posted to room folios'"/>
  <x-stat label="Refunded" :value="$rentals->where('status', 'refunded')->count()" icon="refresh" tone="amber" :sub="money($rentals->where('status', 'refunded')->sum('total'))"/>
  <x-stat label="Catalogue" :value="$list->where('is_active', true)->count()" icon="book" tone="purple" :sub="$cfg['vod'] ? 'rentals on · '.($cfg['pin_purchase'] ? 'PIN required' : 'no PIN') : 'movie rentals switched off'"/>
</div>
<div class="g-1-2">
  <div class="card flush"><div class="card-head"><h3>Catalogue</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="tvMovieList"><thead><tr><th>Title</th><th class="num">Price</th><th class="num">Rented</th><th></th></tr></thead><tbody>
  @forelse($list as $m)<tr class="{{ $m->is_active ? '' : 'muted' }}"><td><b>{{ $m->title }}</b><div class="tiny muted">{{ $M::GENRES[$m->genre] ?? $m->genre }} · {{ $m->rating }} · {{ $m->duration_min }} min{{ $m->year ? ' · '.$m->year : '' }}{{ $m->is_adult ? ' · adult' : '' }}{{ $m->is_active ? '' : ' · off' }}</div></td>
    <td class="num small">{{ money($m->gross()) }}<div class="tiny muted">{{ $m->rental_hours }} h</div></td><td class="num">{{ $m->purchases_count }}</td>
    <td>@if($canMg)<button class="btn btn-sm" data-modal="tvMv{{ $m->id }}">Edit</button>@endif</td></tr>
  @empty<tr><td colspan="4" class="muted">No movies yet.</td></tr>@endforelse
  </tbody></table></div></div>
  <div>
    <div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end">
      <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div><button class="btn btn-sm">Show</button></form></div></div>
    <div class="card flush"><div class="card-head"><h3>Rentals</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="tvRentalList"><thead><tr><th>Ref</th><th>Movie</th><th>Room / guest</th><th>Started</th><th class="num">Total</th><th>State</th><th></th></tr></thead><tbody>
    @forelse($rentals as $p)@php $g = $p->stay?->guest ?? $p->stay?->reservation?->guest; @endphp
      <tr><td class="nowrap"><b>{{ $p->number }}</b></td><td class="small">{{ $p->title }}</td><td class="small">{{ $p->room ? 'Rm '.$p->room->room_no : '' }}<div class="tiny muted">{{ $g ? trim($g->first_name.' '.$g->last_name) : '' }}</div></td>
        <td class="small nowrap">{{ $p->starts_at->format('d M H:i') }}</td><td class="num small">{{ money($p->total) }}</td>
        <td><span class="badge badge-{{ $p->stateTone() }}" @if($p->refund_reason) title="{{ $p->refund_reason }} – {{ $p->refunder?->name }}" @endif>{{ $p->state() }}</span></td>
        <td class="nowrap">@if($canMg && $p->status !== 'refunded')<details class="esg-pop"><summary class="btn btn-sm">Refund</summary><form method="post" action="{{ route('iptv.rentals.refund', $p) }}">@csrf<input name="reason" class="input" maxlength="190" placeholder="e.g. stream would not play" required><button class="btn btn-sm btn-danger">Refund</button></form></details>@endif</td></tr>
    @empty<tr><td colspan="7" class="muted">No rentals in the period.</td></tr>@endforelse
    </tbody></table></div></div>
    <p class="tiny muted">A rental posts to the room folio as “Pay TV / Movies” with VAT, NHIL and GETFund. A refund voids the folio charge – possible while the folio is open and the business day has not been locked by the income audit.</p>
  </div>
</div>
@if($canMg)
<div class="modal" id="tvMovieModal"><div class="modal-box lg"><form method="post" action="{{ route('iptv.movies.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Add movie</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._movie_fields', ['x' => null])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add</button></div></form></div></div>
@foreach($list as $m)
<div class="modal" id="tvMv{{ $m->id }}"><div class="modal-box lg"><form method="post" action="{{ route('iptv.movies.update', $m) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>{{ $m->title }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._movie_fields', ['x' => $m])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endforeach
@endif
@endsection
