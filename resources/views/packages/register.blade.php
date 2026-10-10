@extends('layouts.app')
@section('title', 'Package Entitlements')
@section('crumbs') <a href="{{ route('packages.index') }}">Packages & meals</a> / Entitlements @endsection
@php $E = \App\Models\PackageEntitlement::class; @endphp
@section('content')
<form method="get" class="filters card" id="regFilters"><div class="card-body flex gap-1 wrap items-end">
  <div class="field"><label for="fFrom">Service from</label><input type="date" name="from" id="fFrom" value="{{ $from->toDateString() }}"></div>
  <div class="field"><label for="fTo">to</label><input type="date" name="to" id="fTo" value="{{ $to->toDateString() }}"></div>
  <div class="field"><label for="fPkg">Package</label><select name="package" id="fPkg"><option value="">All</option>@foreach($pkgs as $p)<option value="{{ $p->id }}" @selected(request('package') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
  <div class="field"><label for="fSrc">Source</label><select name="source" id="fSrc"><option value="">All</option><option value="included" @selected(request('source') === 'included')>Included in rate</option><option value="addon" @selected(request('source') === 'addon')>Add-on</option></select></div>
  <div class="field"><label for="fSt">Status</label><select name="status" id="fSt"><option value="">All</option>@foreach($E::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
  <button class="btn btn-primary">Filter</button>
</div></form>

<div class="stats">
  <x-stat label="Entitlements" :value="(int) $tot->n" icon="book" tone="slate" :sub="(int) $tot->used.' / '.(int) $tot->covers.' covers used'"/>
  <x-stat label="Allocated" :value="money($tot->allocated)" icon="swap" tone="blue"/>
  <x-stat label="Used" :value="money($tot->consumed)" icon="check" tone="green"/>
  <x-stat label="Breakage" :value="money($tot->breakage)" icon="trend" :tone="$tot->breakage > 0 ? 'red' : 'green'"/>
</div>

<div class="card flush"><div class="card-body table-wrap"><table class="table" id="entTable">
  <thead><tr><th>Service date</th><th>Room / guest</th><th>Package</th><th>Source</th><th class="num">Covers</th><th class="num">Value</th><th class="num">Used</th><th class="num">Breakage</th><th>Status</th></tr></thead>
  <tbody>@forelse($rows as $e)
    <tr data-ent="{{ $e->id }}"><td class="small nowrap">{{ $e->service_date->format('D d M') }}<div class="tiny muted">night {{ $e->night_date->format('d M') }}</div></td>
      <td class="small"><b>{{ $e->stay?->room?->room_no }}</b> {{ $e->stay?->guest?->full_name }}</td>
      <td class="small">{{ $e->package?->name }}@unless($e->is_allowance)<div class="tiny muted">fixed inclusion</div>@endunless</td>
      <td class="small">{{ $e->source === 'addon' ? 'Add-on' : 'Included' }}</td>
      <td class="num">{{ $e->consumed_qty }}/{{ $e->qty }}</td>
      <td class="num">{{ money($e->amount, false) }}</td>
      <td class="num">{{ money($e->consumed_amount, false) }}</td>
      <td class="num {{ (float) $e->breakage_amount > 0 ? 'text-red' : '' }}">{{ (float) $e->breakage_amount > 0 ? money($e->breakage_amount, false) : '–' }}</td>
      <td><span class="badge badge-{{ $e->statusTone() }}">{{ $E::STATUSES[$e->status] ?? $e->status }}</span></td></tr>
  @empty <tr><td colspan="9"><x-empty text="No entitlements in this period." icon="book"/></td></tr> @endforelse</tbody>
</table></div></div>
{{ $rows->links() }}
@endsection
