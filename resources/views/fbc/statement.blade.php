@extends('layouts.app')
@section('title', 'Food & Beverage Cost')
@section('crumbs') F&amp;B Cost Control / Cost statement @endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from }}" aria-label="From"><input type="date" name="to" value="{{ $to }}" aria-label="To">
  <button class="btn btn-sm">Show</button>
  @if(can_do('reports.view'))<a class="small" href="{{ route('reports.show', ['fbc_food_cost', 'from' => $from, 'to' => $to]) }}">daily flash report</a>@endif
</form></div></div>
<div class="g-2">
@foreach(['food' => [$food, 'Food'], 'beverage' => [$bev, 'Beverage']] as $ck => [$s, $label])
  <div class="card flush" id="fbcStmt-{{ $ck }}"><div class="card-head"><h3>Cost of {{ strtolower($label) }} sold</h3>
    <span class="badge badge-{{ $s['pct'] !== null && $s['pct'] > $s['target'] ? 'red' : 'green' }}">{{ $s['pct'] ?? '—' }}% · target {{ $s['target'] }}%</span></div>
    <div class="card-body table-wrap"><table class="table table-compact fbc-stmt">
      <tr class="bold-row"><td>{{ $label }} revenue (net)</td><td class="num">{{ money($s['revenue']) }}</td><td class="num">100%</td></tr>
      <tr><td colspan="3" class="tiny muted" style="padding-top:.6rem">COST CONSUMED FROM THE F&amp;B STORES</td></tr>
      <tr><td>Recipe consumption (POS sales)</td><td class="num">{{ money($s['consumption']) }}</td><td></td></tr>
      <tr><td>Issues to the kitchen / bar</td><td class="num">{{ money($s['issues']) }}</td><td></td></tr>
      <tr><td>Food waste log</td><td class="num">{{ money($s['waste']) }}</td><td></td></tr>
      <tr><td>Other wastage &amp; spoilage</td><td class="num">{{ money($s['other_waste']) }}</td><td></td></tr>
      <tr><td>Stock count variance</td><td class="num">{{ money($s['adjustments']) }}</td><td></td></tr>
      <tr class="bold-row"><td>Cost consumed</td><td class="num">{{ money($s['actual']) }}</td><td class="num">{{ $s['revenue'] > 0 ? round($s['actual'] / $s['revenue'] * 100, 1).'%' : '' }}</td></tr>
      <tr><td>Less employee meals <span class="tiny muted">→ employee benefits</span></td><td class="num">({{ money($s['staff_meals']) }})</td><td></td></tr>
      <tr><td>Less officers' checks / complimentary <span class="tiny muted">→ A&amp;G, S&amp;M</span></td><td class="num">({{ money($s['comps']) }})</td><td></td></tr>
      <tr class="bold-row"><td>Cost of {{ strtolower($label) }} sold</td><td class="num">{{ money($s['cost_of_sales']) }}</td><td class="num">{{ $s['pct'] ?? '—' }}%</td></tr>
      <tr><td colspan="3" class="tiny muted" style="padding-top:.6rem">AGAINST THE STANDARD</td></tr>
      <tr><td>Theoretical cost (portions sold × plate cost)</td><td class="num">{{ money($s['theoretical']) }}</td><td class="num">{{ $s['theoretical_pct'] ?? '—' }}%</td></tr>
      <tr class="{{ $s['variance'] > 0 ? 'row-amber' : '' }}"><td><b>Variance</b> (actual − theoretical)</td><td class="num"><b>{{ money($s['variance']) }}</b></td>
        <td class="num">{{ $s['pct'] !== null && $s['theoretical_pct'] !== null ? round($s['pct'] - $s['theoretical_pct'], 1).' pts' : '' }}</td></tr>
      <tr><td class="small muted">&nbsp;&nbsp;of which recipe consumption not matched by sales</td><td class="num small">{{ money($s['recipe_gap']) }}</td><td></td></tr>
      @if($s['uncosted_revenue'] > 0)<tr><td class="small muted" colspan="3">{{ money($s['uncosted_revenue']) }} of revenue came from dishes without a recipe – no theoretical cost for them.</td></tr>@endif
    </table></div></div>
@endforeach
</div>
<div class="card flush"><div class="card-head"><h3>Day by day</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="fbcDaily">
  <thead><tr><th>Date</th><th class="num">Food revenue</th><th class="num">Theoretical</th><th class="num">Cost of food sold</th><th class="num">Food %</th><th class="num">Beverage revenue</th><th class="num">Cost of beverage sold</th><th class="num">Beverage %</th></tr></thead><tbody>
  @foreach(array_reverse($daily, true) as $dt => $d)<tr class="{{ $d['food']['pct'] !== null && $d['food']['pct'] > $cfg['food_target'] + 5 ? 'row-amber' : '' }}">
    <td class="nowrap">{{ \Carbon\Carbon::parse($dt)->format('D d M') }}</td>
    <td class="num">{{ money($d['food']['revenue']) }}</td><td class="num">{{ money($d['food']['theoretical']) }}</td><td class="num">{{ money($d['food']['cost_of_sales']) }}</td><td class="num"><b>{{ $d['food']['pct'] !== null ? $d['food']['pct'].'%' : '—' }}</b></td>
    <td class="num">{{ money($d['beverage']['revenue']) }}</td><td class="num">{{ money($d['beverage']['cost_of_sales']) }}</td><td class="num"><b>{{ $d['beverage']['pct'] !== null ? $d['beverage']['pct'].'%' : '—' }}</b></td></tr>@endforeach
  </tbody></table></div></div>
<p class="tiny muted">Daily percentages swing with deliveries, issues and counts – read the period total. Revenue is net of VAT, NHIL, GETFund, tourism levy and discounts; complimentary bills carry no revenue and their plate cost is transferred out as officers' checks.</p>
@endsection
