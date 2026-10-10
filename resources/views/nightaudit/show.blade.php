@extends('layouts.app')
@section('title', 'Night Audit Report – '.$a->business_date->format('d M Y'))
@section('crumbs') <a href="{{ route('nightaudit.index') }}">Night Audit</a> / {{ $a->business_date->format('d M Y') }} @endsection
@section('actions')<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')
<div class="stats">
<x-stat label="Occupancy" :value="(float)$a->occupancy_pct.'%'" icon="bed" tone="blue"/><x-stat label="Rooms sold / total" :value="$a->rooms_occupied.' / '.$a->rooms_total" icon="key" tone="purple"/>
<x-stat label="ADR" :value="money($a->adr)" icon="chart" tone="green"/><x-stat label="RevPAR" :value="money($a->revpar)" icon="chart" tone="green"/>
<x-stat label="Arrivals / Departures" :value="$a->arrivals.' / '.$a->departures" icon="swap" tone="slate"/><x-stat label="No-shows" :value="$a->no_shows" icon="alert" tone="red"/>
<x-stat label="Room revenue" :value="money($a->room_revenue)" icon="money" tone="blue"/><x-stat label="Collections" :value="money($a->collections)" icon="wallet" tone="green"/>
</div>
<div class="grid g-3">
<div class="card flush"><div class="card-head"><h3>Revenue by category</h3></div><div class="card-body"><table class="table table-compact"><thead><tr><th>Category</th><th class="num">Net</th><th class="num">Tax</th></tr></thead><tbody>
@forelse($charges as $cat=>$c)<tr><td>{{ config('pms.charge_categories')[$cat] ?? $cat }}</td><td class="num">{{ money($c['net'], false) }}</td><td class="num">{{ money($c['tax'], false) }}</td></tr>@empty<tr><td colspan="3" class="muted">No charges.</td></tr>@endforelse</tbody>
<tfoot><tr><td>Total</td><td class="num">{{ money($charges->sum('net'), false) }}</td><td class="num">{{ money($charges->sum('tax'), false) }}</td></tr></tfoot></table></div></div>
<div class="card flush"><div class="card-head"><h3>Settlements by mode</h3></div><div class="card-body"><table class="table table-compact"><tbody>
@forelse($payments as $m=>$v)<tr><td>{{ $m }}</td><td class="num">{{ money($v, false) }}</td></tr>@empty<tr><td class="muted">No payments.</td></tr>@endforelse</tbody><tfoot><tr><td>Total</td><td class="num">{{ money($payments->sum(), false) }}</td></tr></tfoot></table></div></div>
<div class="card flush"><div class="card-head"><h3>POS sales by outlet</h3></div><div class="card-body"><table class="table table-compact"><tbody>
@forelse($pos as $o=>$v)<tr><td>{{ $o }}</td><td class="num">{{ money($v, false) }}</td></tr>@empty<tr><td class="muted">No POS sales.</td></tr>@endforelse</tbody><tfoot><tr><td>Total</td><td class="num">{{ money($pos->sum(), false) }}</td></tr></tfoot></table></div></div>
</div>
<div class="card"><div class="card-body small muted">Run by {{ $a->user?->name ?? 'system' }} at {{ $a->created_at->format('d M Y H:i') }}. {{ $a->notes }}</div></div>
@endsection
