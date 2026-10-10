@extends('layouts.app')
@section('title', 'Depreciation '.$run->run_no)
@section('crumbs') <a href="{{ route('assets.depreciation') }}">Depreciation</a> / {{ $run->run_no }} @endsection
@section('actions')
<span class="badge badge-{{ $run->status === 'posted' ? 'green' : 'slate' }}" id="runStatus">{{ $run->status === 'posted' ? 'Posted' : 'Reversed' }}</span>
@if($journal)<a class="btn" href="{{ route('gl.journals.show', $journal) }}"><x-icon name="book"/> Journal {{ $journal->journal_no }}</a>@endif
@if($run->status === 'posted' && $isLast && can_do('assets.depreciation'))<button class="btn btn-danger" data-modal="revModal" id="reverseRun"><x-icon name="refresh"/> Reverse</button>@endif
@endsection
@section('content')
<div class="stats wide">
  <x-stat label="Month" :value="$run->label()" icon="calendar" tone="blue"/>
  <x-stat label="Depreciation" :value="money($run->total)" icon="trend" tone="amber"/>
  <x-stat label="Assets" :value="$run->assets" icon="layers" tone="purple"/>
  <x-stat label="Posted by" :value="$run->creator?->name ?? 'System'" icon="user" tone="green" :sub="$run->created_at->format('d M Y H:i')"/>
</div>
@if($run->status === 'reversed')<div class="alert alert-warn"><x-icon name="alert"/><div>Reversed {{ $run->reversed_at?->format('d M Y H:i') }} by {{ $run->reverser?->name }} – {{ $run->reverse_reason }}</div></div>@endif
<div class="grid g-2-1">
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="runLines">
  <thead><tr><th>Asset</th><th>Category</th><th class="num">Opening book value</th><th class="num">Depreciation</th><th class="num">Closing</th></tr></thead><tbody>
  @foreach($lines as $l)<tr><td><a href="{{ route('assets.show', $l->asset) }}">{{ $l->asset->asset_no }}</a> <span class="small">{{ \Illuminate\Support\Str::limit($l->asset->name, 44) }}</span>@if($l->days < $run->period_end->daysInMonth)<span class="tiny muted"> · {{ $l->days }} days</span>@endif</td><td class="small">{{ $l->asset->category?->name }}</td><td class="num">{{ money($l->opening_nbv, false) }}</td><td class="num">{{ money($l->amount, false) }}</td><td class="num">{{ money($l->opening_nbv - $l->amount, false) }}</td></tr>@endforeach
  </tbody></table></div></div>
<div class="card flush"><div class="card-head"><h3>By category</h3></div><div class="card-body table-wrap"><table class="table table-compact">
  <tbody>@foreach($lines->groupBy(fn ($l) => $l->asset->category?->name) as $cat => $ls)<tr><td>{{ $cat }}</td><td class="num">{{ $ls->count() }}</td><td class="num bold">{{ money($ls->sum('amount'), false) }}</td></tr>@endforeach</tbody>
  <tfoot><tr><td>Total</td><td class="num">{{ $lines->count() }}</td><td class="num bold">{{ money($run->total) }}</td></tr></tfoot></table></div></div>
</div>
@if($run->status === 'posted' && $isLast && can_do('assets.depreciation'))
<div class="modal" id="revModal"><div class="modal-box"><form method="post" action="{{ route('assets.depreciation.reverse', $run) }}">@csrf
  <div class="modal-head"><h3>Reverse {{ $run->run_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required/><p class="small muted">The journal is reversed and {{ $run->label() }} can be run again.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="confirmReverse">Reverse run</button></div>
</form></div></div>
@endif
@endsection
