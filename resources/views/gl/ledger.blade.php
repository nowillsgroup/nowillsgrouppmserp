@extends('layouts.app')
@section('title', 'Ledger · '.$head->label)
@section('actions')<a class="btn" href="{{ route('gl.index') }}"><x-icon name="book"/> Trial balance</a><button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')
@php $side = fn ($v) => $v == 0 ? '' : ($head->isDebitNature() ? ($v > 0 ? 'Dr' : 'Cr') : ($v > 0 ? 'Cr' : 'Dr')); @endphp
<div class="card"><div class="card-body"><form class="filters">
<div class="field" style="min-width:260px"><label>Account</label><select onchange="location.href='{{ url('gl/accounts') }}/'+this.value+'?from={{ $from }}&to={{ $to }}'">@foreach($accounts as $a)<option value="{{ $a->id }}" @selected($a->id === $head->id)>{{ $a->label }}</option>@endforeach</select></div>
<div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
<button class="btn btn-primary">Show</button></form></div></div>
<div class="stats">
<x-stat label="Opening balance" :value="money(abs($l['opening'])).' '.$side($l['opening'])" icon="book" tone="slate"/>
<x-stat label="Debits" :value="money($l['dr'])" icon="plus" tone="blue"/>
<x-stat label="Credits" :value="money($l['cr'])" icon="minus" tone="amber"/>
<x-stat label="Closing balance" :value="money(abs($l['closing'])).' '.$side($l['closing'])" icon="wallet" tone="green" :sub="\App\Models\AccountHead::TYPES[$head->type].($head->group_name ? ' · '.$head->group_name : '').($head->system_key ? ' · system account' : '')"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="ledger">
<thead><tr><th>Date</th><th>Journal</th><th>Source</th><th>Reference</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
<tr class="row-sub"><td>{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}</td><td colspan="6">Opening balance</td><td class="num">{{ money(abs($l['opening']), false) }}<span class="dr-cr">{{ $side($l['opening']) }}</span></td></tr>
@forelse($l['lines'] as $x)@php $ln = $x['line']; $j = $ln->journal; @endphp
<tr><td class="nowrap">{{ $ln->date->format('d M Y') }}</td><td><a href="{{ route('gl.journals.show', $j) }}">{{ $j->journal_no }}</a>@if($j->reversal_of_id) <span class="badge badge-amber">reversal</span>@endif</td>
<td class="small">{{ \App\Models\GlJournal::SOURCES[$j->source_type] ?? $j->source_type }}</td><td class="small">{{ $j->reference }}</td><td class="small">{{ $j->narration }}@if($ln->memo) <span class="muted">· {{ $ln->memo }}</span>@endif</td>
<td class="num">{{ (float) $ln->debit ? money($ln->debit, false) : '' }}</td><td class="num">{{ (float) $ln->credit ? money($ln->credit, false) : '' }}</td>
<td class="num bold">{{ money(abs($x['balance']), false) }}<span class="dr-cr">{{ $side($x['balance']) }}</span></td></tr>
@empty<tr><td colspan="8" class="muted">No postings in this period.</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="5">Closing balance</td><td class="num">{{ money($l['dr'], false) }}</td><td class="num">{{ money($l['cr'], false) }}</td><td class="num">{{ money(abs($l['closing']), false) }}<span class="dr-cr">{{ $side($l['closing']) }}</span></td></tr></tfoot></table></div></div>
@endsection
