@extends('layouts.app')
@section('title', 'Recipe card – '.$m->name)
@section('crumbs') <a href="{{ route('fbc.recipes') }}">Recipe cards</a> / {{ $m->name }} @endsection
@php $u = auth()->user(); $canCost = \App\Services\FbCostService::canCost($u); $canPrice = \App\Services\FbCostService::canPrice($u); @endphp
@section('actions')
  <a class="btn" href="{{ route('fbc.card.print', [$m, 'autoprint' => 1]) }}" target="_blank"><x-icon name="print"/> Print card</a>
  @if($canCost)<a class="btn" href="{{ route('fbc.card.edit', $m) }}"><x-icon name="edit"/> Edit recipe</a>@endif
  @if($canPrice && $card && ! $card->approved_at && $lines->isNotEmpty())
  <form method="post" action="{{ route('fbc.card.approve', $m) }}" style="display:inline" id="fbcApproveForm">@csrf<button class="btn btn-primary"><x-icon name="check"/> Approve card</button></form>@endif
@endsection
@section('content')
<div class="stats" id="fbcCardStats">
  <x-stat label="Menu price (ex tax)" :value="money($price)" icon="money" tone="blue" :sub="$m->category?->name.' · '.$cls"/>
  <x-stat label="Plate cost" :value="$lines->isNotEmpty() ? money($cost) : '—'" icon="coffee" tone="slate" :sub="$lines->count().' ingredient(s)'"/>
  <x-stat label="Cost %" :value="$pct !== null ? $pct.'%' : '—'" icon="chart" :tone="$pct !== null && $pct > $target ? 'red' : 'green'" :sub="'target '.$target.'%'"/>
  <x-stat label="Gross profit / portion" :value="$lines->isNotEmpty() ? money($price - $cost) : '—'" icon="trend" tone="green" :sub="$suggested ? 'price at target: '.money($suggested) : ''"/>
  <x-stat label="Cost creep" :value="$creep ? ($creep['pct'] > 0 ? '+' : '').$creep['pct'].'%' : '—'" icon="refresh" :tone="$creep && $creep['pct'] >= $cfg['creep_pct'] ? 'red' : 'slate'" :sub="$creep ? 'since '.$creep['on']->format('d M').' ('.money($creep['was']).')' : 'no snapshot yet'"/>
</div>
<div class="g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Ingredients per portion</h3>
      @if($card)<span class="small muted">batch of {{ $card->batch_portions }} portion(s)</span>@endif</div>
      <div class="card-body table-wrap"><table class="table table-compact" id="fbcCardLines"><thead><tr><th>Ingredient</th><th class="num">Net qty</th><th class="num">Yield</th><th class="num">Gross qty</th><th class="num">Unit cost</th><th class="num">Cost</th><th class="num">Share</th>
        @if($card && $card->batch_portions > 1)<th class="num">Per batch</th>@endif</tr></thead><tbody>
      @forelse($lines as $l)<tr>
        <td><b>{{ $l->item->name }}</b><div class="tiny muted">{{ $l->item->code }} · {{ $l->item->category?->name }}</div></td>
        <td class="num">{{ rtrim(rtrim(number_format($l->qty, 3), '0'), '.') }} {{ $l->item->unit }}</td>
        <td class="num">{{ rtrim(rtrim(number_format($l->item->yield_pct, 1), '0'), '.') }}%</td>
        <td class="num">{{ rtrim(rtrim(number_format($l->gross, 3), '0'), '.') }}</td>
        <td class="num">{{ money($l->unit_cost) }}</td><td class="num"><b>{{ money($l->cost) }}</b></td>
        <td class="num small">{{ $cost > 0 ? round($l->cost / $cost * 100) : 0 }}%</td>
        @if($card && $card->batch_portions > 1)<td class="num small">{{ rtrim(rtrim(number_format($l->qty * $card->batch_portions, 3), '0'), '.') }}</td>@endif
      </tr>
      @empty<tr><td colspan="8" class="muted">No ingredients yet. @if($canCost)<a href="{{ route('fbc.card.edit', $m) }}">Write the recipe</a>.@endif</td></tr>@endforelse
      </tbody>
      @if($lines->isNotEmpty())<tfoot><tr class="bold-row"><td colspan="5">Plate cost</td><td class="num">{{ money($cost) }}</td><td></td>@if($card && $card->batch_portions > 1)<td class="num small">{{ money($cost * $card->batch_portions) }}</td>@endif</tr></tfoot>@endif
      </table></div></div>
    <div class="card"><div class="card-head"><h3>Method</h3></div><div class="card-body">
      @if($card?->method)<div class="fbc-method">{!! nl2br(e($card->method)) !!}</div>@else<p class="muted">No method written yet.</p>@endif
      @if($card?->garnish)<p class="small mt-1"><b>Garnish / presentation:</b> {{ $card->garnish }}</p>@endif
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Card</h3></div><div class="card-body">
      <div class="kv-grid" id="fbcCardHead">
        <div><span>Portion</span>{{ $card?->portion_size ?? '—' }}</div>
        <div><span>Batch yields</span>{{ $card?->batch_portions ?? 1 }} portion(s)</div>
        <div><span>Preparation</span>{{ $card?->prep_minutes !== null && $card ? $card->prep_minutes.' min' : '—' }}</div>
        <div><span>Station</span>{{ $card?->station ?? $m->category?->kitchen_station ?? '—' }}</div>
        <div><span>Target cost</span>{{ $target }}%{{ $card?->target_pct ? ' (own)' : '' }}</div>
        <div><span>Version</span>{{ $card ? 'v'.$card->version : '—' }}</div>
        <div><span>Approved</span>@if($card?->approved_at){{ $card->approver?->name }} · {{ $card->approved_at->format('d M Y') }}@else<span class="badge badge-amber">not approved</span>@endif</div>
        <div><span>Last changed by</span>{{ $card?->editor?->name ?? '—' }}</div>
      </div>
      @if($m->allergens)<p class="small mt-1"><b>Allergens:</b> {{ implode(', ', (array) $m->allergens) }}</p>@endif
    </div></div>
    <div class="card"><div class="card-head"><h3>Plate cost history</h3><span class="small muted">night-audit snapshots</span></div><div class="card-body" id="fbcHistory">
      @if($history->count() >= 2)
      @php
        $vals = $history->pluck('cost')->push($cost); $lo = $vals->min() * 0.95; $hi = max($vals->max() * 1.05, $lo + 0.01);
        $n = $history->count(); $pts = [];
        foreach ($history->values() as $i => $h) { $pts[] = round($i / max(1, $n - 1) * 300, 1).','.round(90 - ($h->cost - $lo) / ($hi - $lo) * 80, 1); }
      @endphp
      <svg viewBox="0 0 300 100" class="fbc-spark" role="img" aria-label="Plate cost trend"><polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#2563eb" stroke-width="2"/></svg>
      <div class="flex small muted" style="justify-content:space-between"><span>{{ $history->first()->business_date->format('d M') }} · {{ money($history->first()->cost) }}</span><span>{{ $history->last()->business_date->format('d M') }} · {{ money($history->last()->cost) }}</span></div>
      @else<p class="muted small">Not enough snapshots yet – the night audit takes one every day.</p>@endif
    </div></div>
    <div class="card flush"><div class="card-head"><h3>Price history</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="fbcCardPrices"><tbody>
      @forelse($changes as $c)<tr><td class="small nowrap">{{ $c->effective_on->format('d M Y') }}</td><td class="num small nowrap">{{ money($c->old_price) }} → <b>{{ money($c->new_price) }}</b></td>
        <td class="small">{{ $c->reason }}<div class="tiny muted">{{ $c->user?->name }} · cost then {{ money($c->cost_at) }}</div></td></tr>
      @empty<tr><td class="muted">No price changes logged.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
</div>
@endsection
