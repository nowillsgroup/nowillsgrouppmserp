@extends('layouts.app')
@section('title', 'Inspection Round')
@section('crumbs') Health &amp; Safety / Inspection round @endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <select name="scope" aria-label="Scope"><option value="due" @selected($scope === 'due')>Due in 7 days, overdue or defective</option><option value="all" @selected($scope === 'all')>Everything</option></select>
  <select name="floor" aria-label="Floor"><option value="">All floors</option>@foreach($floors as $f)<option value="{{ $f }}" @selected(request('floor') === $f)>{{ $f }}</option>@endforeach</select>
  <button class="btn btn-sm">Show</button><span class="tiny muted">Walk the floor with this list on a phone or tablet: mark each item Pass or Fail. A failure marks the item defective and goes to engineering.</span>
</form></div></div>
<form method="post" action="{{ route('hse.round.save') }}" id="hseRoundForm">@csrf
<div class="card flush"><div class="card-head"><h3>{{ $items->count() }} item(s)</h3><div class="flex gap-1" style="align-items:center"><label class="small">Date <input type="date" name="checked_on" value="{{ bdate()->toDateString() }}" max="{{ bdate()->toDateString() }}"></label>
  <button type="button" class="btn btn-sm" id="hseAllPass">Mark all pass</button></div></div>
<div class="card-body table-wrap"><table class="table table-compact" id="hseRoundTable"><thead><tr><th>Item</th><th>Location</th><th>Due</th><th>Check</th><th>Pass</th><th>Fail</th><th>Fault</th></tr></thead><tbody>
  @forelse($items as $e)<tr class="{{ in_array($e->st, ['overdue', 'defective', 'expired'], true) ? 'row-red' : '' }}" data-code="{{ $e->code }}">
    <td><b>{{ $e->code }}</b><div class="tiny muted">{{ $e->typeLabel() }}</div></td><td class="small">{{ $e->location }}{{ $e->floor ? ' · '.$e->floor : '' }}</td>
    <td class="small nowrap">{{ $e->next_check_on?->format('d M') ?? 'now' }} @include('hse._state', ['s' => $e->st])</td>
    <td class="tiny muted">{{ implode(' · ', $e->checklist()) }}</td>
    <td class="center"><input type="radio" name="rows[{{ $e->id }}][result]" value="pass" aria-label="{{ $e->code }} pass"></td>
    <td class="center"><input type="radio" name="rows[{{ $e->id }}][result]" value="fail" aria-label="{{ $e->code }} fail"></td>
    <td><input name="rows[{{ $e->id }}][note]" maxlength="120" placeholder="what is wrong" style="min-width:10rem"></td></tr>
  @empty<tr><td colspan="7" class="muted">Nothing is due. Choose "Everything" to check items early.</td></tr>@endforelse
</tbody></table></div></div>
@if($items->count())<button class="btn btn-primary">Save round</button>@endif
</form>
<script>document.getElementById('hseAllPass')?.addEventListener('click', () => document.querySelectorAll('#hseRoundTable input[value=pass]').forEach(r => { if (!r.closest('tr').querySelector('input[value=fail]').checked) r.checked = true; }));</script>
@endsection
