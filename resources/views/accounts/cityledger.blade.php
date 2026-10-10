@extends('layouts.app')
@section('title', 'City Ledger – Company Receivables')
@section('content')
<div class="stats"><x-stat label="Total billed" :value="money($companies->sum('billed'))" icon="receipt" tone="blue"/><x-stat label="Received" :value="money($companies->sum('received'))" icon="money" tone="green"/><x-stat label="Outstanding" :value="money($companies->sum('outstanding'))" icon="alert" tone="red"/></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Company / Agent</th><th>Type</th><th>Contact</th><th class="num">Credit limit</th><th class="num">Billed</th><th class="num">Received</th><th class="num">Outstanding</th><th></th></tr></thead><tbody>
@forelse($companies as $c)<tr><td class="bold">{{ $c->name }}</td><td>{{ ucfirst(str_replace('_',' ',$c->type)) }}</td><td class="small">{{ $c->contact_person }} {{ $c->phone }}</td><td class="num">{{ money($c->credit_limit, false) }}</td><td class="num">{{ money($c->billed, false) }}</td><td class="num">{{ money($c->received, false) }}</td>
<td class="num bold {{ $c->outstanding > 0 ? 'text-red' : '' }} {{ $c->credit_limit > 0 && $c->outstanding > $c->credit_limit ? 'badge badge-red' : '' }}">{{ money($c->outstanding, false) }}</td><td><a class="btn btn-sm" href="{{ route('accounts.company', $c) }}">Ledger</a></td></tr>
@empty<tr><td colspan="8"><x-empty text="No companies." icon="building"/></td></tr>@endforelse</tbody></table></div></div>
@endsection
