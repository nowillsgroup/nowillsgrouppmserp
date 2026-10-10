@extends('layouts.app')
@section('title', 'Journal Entries')
@section('actions')@if(can_do('gl.journal'))<a class="btn btn-primary" href="{{ route('gl.journals.create') }}"><x-icon name="plus"/> Manual Journal</a>@endif <a class="btn" href="{{ route('gl.index') }}"><x-icon name="book"/> Trial balance</a>@endsection
@section('content')
<div class="card"><div class="card-body"><form class="filters">
<div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
<div class="field"><label>Source</label><select name="source"><option value="">All</option>@foreach(\App\Models\GlJournal::SOURCES as $k => $v)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $v }}</option>@endforeach</select></div>
<div class="field"><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="JV no, reference, narration"></div>
<button class="btn btn-primary">Filter</button></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="journals">
<thead><tr><th>Journal</th><th>Date</th><th>Source</th><th>Reference</th><th>Narration</th><th class="num">Lines</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
@forelse($journals as $j)
<tr><td class="bold"><a href="{{ route('gl.journals.show', $j) }}">{{ $j->journal_no }}</a></td><td class="nowrap">{{ $j->date->format('d M Y') }}</td>
<td><span class="badge {{ $j->is_manual ? 'badge-purple' : 'badge-slate' }}">{{ \App\Models\GlJournal::SOURCES[$j->source_type] ?? $j->source_type }}</span></td>
<td class="small">{{ $j->reference }}</td><td class="small">{{ \Illuminate\Support\Str::limit($j->narration, 70) }}</td><td class="num">{{ $j->lines_count }}</td><td class="num">{{ money($j->total, false) }}</td>
<td>@if($j->reversed_by_id)<span class="badge badge-red">reversed</span>@elseif($j->reversal_of_id)<span class="badge badge-amber">reversal</span>@else<span class="badge badge-green">posted</span>@endif</td></tr>
@empty<tr><td colspan="8"><x-empty text="No journals in this period." icon="book"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $journals->links() }}
@endsection
