@extends('layouts.app')
@section('title', 'Marketing Budget '.$year)
@section('crumbs') Sales & Marketing / Marketing budget @endsection
@php $months = range(1, 12); $tb = array_sum(array_column($rows, 'budget_total')); $ta = array_sum(array_column($rows, 'actual_total')); $tc = array_sum(array_column($rows, 'committed_total')); @endphp
@section('actions')
  <a class="btn" href="?year={{ $year - 1 }}">← {{ $year - 1 }}</a><a class="btn" href="?year={{ $year + 1 }}">{{ $year + 1 }} →</a>
  @if(! $edit && (can_do('sales.marketing') || can_do('sales.manage')))<a class="btn btn-primary" href="?year={{ $year }}&edit=1" id="editBudgetBtn"><x-icon name="edit"/> Edit budget</a>@endif
@endsection
@section('content')
<div class="stats" id="budgetStats">
  <x-stat label="Budget {{ $year }}" :value="money_short($tb)" icon="wallet" tone="blue"/>
  <x-stat label="Spent" :value="money_short($ta)" icon="money" :tone="$tb > 0 && $ta > $tb ? 'red' : 'green'" :sub="$tb > 0 ? round($ta * 100 / $tb, 1).'% of budget' : 'no budget set'"/>
  <x-stat label="Committed" :value="money_short($tc)" icon="calendar" tone="amber" sub="planned & confirmed events"/>
  <x-stat label="Remaining" :value="money_short($tb - $ta - $tc)" icon="chart" :tone="$tb - $ta - $tc < 0 ? 'red' : 'slate'" sub="budget − spent − committed"/>
</div>
@if($edit)
<form method="post" action="{{ route('sales.budget.save') }}" id="budgetForm">@csrf<input type="hidden" name="year" value="{{ $year }}">
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Line</th>@foreach($months as $m)<th class="num">{{ \Carbon\Carbon::create(2000, $m, 1)->format('M') }}</th>@endforeach</tr></thead><tbody>
  @foreach($rows as $k => $r)<tr><td class="small"><b>{{ $r['label'] }}</b></td>@foreach($months as $m)<td><input type="number" name="b[{{ $k }}][{{ $m }}]" min="0" step="0.01" value="{{ $r['budget'][$m] ?: '' }}" style="width:6.5rem"></td>@endforeach</tr>@endforeach
</tbody></table></div></div>
<div class="mt-1 flex gap-1"><button class="btn btn-primary" id="saveBudget">Save budget</button><a class="btn" href="?year={{ $year }}">Cancel</a></div></form>
@else
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="budgetTable"><thead><tr><th>Line</th>@foreach($months as $m)<th class="num">{{ \Carbon\Carbon::create(2000, $m, 1)->format('M') }}</th>@endforeach<th class="num">Budget ({{ hotel()->currency_symbol ?? 'GH₵' }})</th><th class="num">Actual</th><th class="num">Committed</th><th class="num">Variance</th></tr></thead><tbody>
  @foreach($rows as $k => $r)@continue(! $r['budget_total'] && ! $r['actual_total'] && ! $r['committed_total'])
  <tr data-line="{{ $k }}"><td class="small"><b>{{ $r['label'] }}</b></td>@foreach($months as $m)<td class="num tiny">@if($r['budget'][$m] || $r['actual'][$m])<div>{{ $r['budget'][$m] ? number_format($r['budget'][$m]) : '·' }}</div><div class="{{ $r['actual'][$m] > $r['budget'][$m] ? 'text-red' : 'muted' }}">{{ $r['actual'][$m] ? number_format($r['actual'][$m]) : '' }}</div>@else · @endif</td>@endforeach
    <td class="num">{{ number_format($r['budget_total']) }}</td><td class="num">{{ number_format($r['actual_total']) }}</td><td class="num">{{ $r['committed_total'] ? number_format($r['committed_total']) : '·' }}</td><td class="num {{ $r['budget_total'] - $r['actual_total'] < 0 ? 'text-red bold' : '' }}">{{ number_format($r['budget_total'] - $r['actual_total']) }}</td></tr>
  @endforeach
  <tr class="bold-row"><td>Total</td>@foreach($months as $m)<td class="num tiny">{{ number_format(array_sum(array_map(fn ($r) => $r['budget'][$m], $rows))) }}<div class="muted">{{ number_format(array_sum(array_map(fn ($r) => $r['actual'][$m], $rows))) }}</div></td>@endforeach<td class="num">{{ number_format($tb) }}</td><td class="num">{{ number_format($ta) }}</td><td class="num">{{ number_format($tc) }}</td><td class="num">{{ number_format($tb - $ta) }}</td></tr>
</tbody></table></div></div>
<p class="tiny muted mt-1">Each month shows budget (top) and actual (below). Actual = closed / cancelled marketing events by start month, client entertainment on completed sales activities, and digital spend.</p>
@endif
@endsection
