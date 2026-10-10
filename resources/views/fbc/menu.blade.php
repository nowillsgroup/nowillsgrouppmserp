@extends('layouts.app')
@section('title', 'Menu Engineering')
@section('crumbs') F&amp;B Cost Control / Menu engineering @endsection
@php $C = \App\Services\FbCostService::CLASSES; @endphp
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input type="date" name="from" value="{{ $from }}" aria-label="From"><input type="date" name="to" value="{{ $to }}" aria-label="To">
  <select name="outlet" aria-label="Outlet"><option value="">All outlets</option>@foreach($outlets as $o)<option value="{{ $o->id }}" @selected((int) request('outlet') === $o->id)>{{ $o->name }}</option>@endforeach</select>
  <select name="category" aria-label="Category"><option value="">Whole menu</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected((int) request('category') === $c->id)>{{ $c->name }}</option>@endforeach</select>
  <button class="btn btn-sm">Analyse</button>
  <span class="small muted">Analyse one menu section or outlet at a time for the fairest comparison.</span>
</form></div></div>
<div class="stats" id="fbcMeStats">
  <x-stat label="Items sold" :value="number_format($me['n'])" icon="receipt" tone="blue" :sub="$me['count'].' costed menu items'"/>
  <x-stat label="Menu revenue" :value="money_short($me['revenue'])" icon="money" tone="green" sub="net of tax & discounts"/>
  <x-stat label="Contribution margin" :value="money_short($me['cm'])" icon="trend" tone="purple" :sub="'average '.money($me['avg_cm']).' per item'"/>
  <x-stat label="Menu cost %" :value="$me['pct'] !== null ? $me['pct'].'%' : '—'" icon="chart" :tone="$me['pct'] !== null && $me['pct'] > $cfg['food_target'] ? 'amber' : 'green'" sub="theoretical"/>
  <x-stat label="Popularity threshold" :value="$me['threshold'].'%'" icon="users" tone="slate" :sub="$cfg['popularity'].'% of an equal share (1/'.$me['count'].')'"/>
</div>
<div class="g-2-1">
  <div class="card"><div class="card-head"><h3>Menu engineering matrix</h3><span class="small muted">x = menu mix % · y = contribution margin</span></div><div class="card-body">
    @php
      $rows = $me['rows']; $W = 640; $H = 380; $pad = 40;
      $mxX = max(1, $rows->max('mix') ?? 1, $me['threshold'] * 2) * 1.1;
      $cms = $rows->pluck('cm'); $loY = min(0, $cms->min() ?? 0); $hiY = max(1, $cms->max() ?? 1, $me['avg_cm'] * 2) * 1.1;
      $sx = fn ($v) => round($pad + $v / $mxX * ($W - $pad - 10), 1);
      $sy = fn ($v) => round($H - $pad - ($v - $loY) / ($hiY - $loY) * ($H - $pad - 10), 1);
      $tx = $sx($me['threshold']); $ty = $sy($me['avg_cm']);
      $fill = ['star' => '#16a34a', 'plowhorse' => '#d97706', 'puzzle' => '#2563eb', 'dog' => '#dc2626'];
    @endphp
    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="fbc-matrix" id="fbcMatrix" role="img" aria-label="Menu engineering matrix">
      <rect x="{{ $tx }}" y="10" width="{{ $W - 10 - $tx }}" height="{{ $ty - 10 }}" fill="#16a34a" opacity=".07"/>
      <rect x="{{ $pad }}" y="10" width="{{ $tx - $pad }}" height="{{ $ty - 10 }}" fill="#2563eb" opacity=".07"/>
      <rect x="{{ $tx }}" y="{{ $ty }}" width="{{ $W - 10 - $tx }}" height="{{ $H - $pad - $ty }}" fill="#d97706" opacity=".07"/>
      <rect x="{{ $pad }}" y="{{ $ty }}" width="{{ $tx - $pad }}" height="{{ $H - $pad - $ty }}" fill="#dc2626" opacity=".07"/>
      <line x1="{{ $pad }}" y1="{{ $H - $pad }}" x2="{{ $W - 10 }}" y2="{{ $H - $pad }}" stroke="#94a3b8"/><line x1="{{ $pad }}" y1="10" x2="{{ $pad }}" y2="{{ $H - $pad }}" stroke="#94a3b8"/>
      <line x1="{{ $tx }}" y1="10" x2="{{ $tx }}" y2="{{ $H - $pad }}" stroke="#64748b" stroke-dasharray="4 3"/>
      <line x1="{{ $pad }}" y1="{{ $ty }}" x2="{{ $W - 10 }}" y2="{{ $ty }}" stroke="#64748b" stroke-dasharray="4 3"/>
      <text x="{{ $W - 14 }}" y="24" text-anchor="end" class="q">STARS</text><text x="{{ $pad + 6 }}" y="24" class="q">PUZZLES</text>
      <text x="{{ $W - 14 }}" y="{{ $H - $pad - 8 }}" text-anchor="end" class="q">PLOWHORSES</text><text x="{{ $pad + 6 }}" y="{{ $H - $pad - 8 }}" class="q">DOGS</text>
      <text x="{{ $tx + 3 }}" y="{{ $H - $pad + 14 }}" class="ax">{{ $me['threshold'] }}%</text><text x="{{ $pad - 4 }}" y="{{ $ty - 3 }}" text-anchor="end" class="ax">{{ number_format($me['avg_cm']) }}</text>
      <text x="{{ ($W + $pad) / 2 }}" y="{{ $H - 8 }}" text-anchor="middle" class="ax">menu mix % (popularity)</text>
      <text x="12" y="{{ ($H - $pad) / 2 }}" transform="rotate(-90 12 {{ ($H - $pad) / 2 }})" text-anchor="middle" class="ax">contribution margin (GH₵)</text>
      @foreach($rows as $r)<g><circle cx="{{ $sx($r->mix) }}" cy="{{ $sy($r->cm) }}" r="5" fill="{{ $fill[$r->cls] }}"><title>{{ $r->item->name }} – {{ $r->n }} sold ({{ $r->mix }}%), CM {{ money($r->cm) }}</title></circle>
        <text x="{{ $sx($r->mix) + 7 }}" y="{{ $sy($r->cm) + 4 }}" class="lbl">{{ \Illuminate\Support\Str::limit($r->item->name, 18) }}</text></g>@endforeach
    </svg>
  </div></div>
  <div>
    @foreach($C as $ck => [$cl, $tone, $act])<div class="card" id="me-{{ $ck }}"><div class="card-body">
      <div class="flex" style="justify-content:space-between;align-items:center"><h3 style="margin:0"><span class="badge badge-{{ $tone }}">{{ $cl }}s</span></h3><b>{{ $me['counts'][$ck] }}</b></div>
      <p class="small muted" style="margin:.4rem 0">{{ $act }}</p>
      <div class="small">{{ $me['rows']->where('cls', $ck)->map(fn ($r) => $r->item->name)->implode(', ') ?: '—' }}</div>
    </div></div>@endforeach
  </div>
</div>
<div class="card flush"><div class="card-head"><h3>Menu engineering worksheet</h3><span class="small muted">{{ \Carbon\Carbon::parse($from)->format('d M') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</span></div>
<div class="card-body table-wrap"><table class="table table-compact" id="fbcMeTable">
  <thead><tr><th>Menu item</th><th class="num">Sold</th><th class="num">Mix %</th><th class="num">Avg price</th><th class="num">Plate cost</th><th class="num">CM</th><th class="num">Revenue</th><th class="num">Total cost</th><th class="num">Total CM</th><th>Popularity</th><th>CM</th><th>Class</th></tr></thead><tbody>
  @forelse($me['rows'] as $r)<tr>
    <td><a href="{{ route('fbc.card', $r->item) }}"><b>{{ $r->item->name }}</b></a><div class="tiny muted">{{ $r->item->category?->name }}</div></td>
    <td class="num">{{ number_format($r->n) }}</td><td class="num">{{ $r->mix }}%</td><td class="num">{{ money($r->price) }}</td><td class="num">{{ money($r->cost) }}</td>
    <td class="num"><b>{{ money($r->cm) }}</b></td><td class="num">{{ money($r->revenue) }}</td><td class="num">{{ money($r->total_cost) }}</td><td class="num">{{ money($r->total_cm) }}</td>
    <td class="small">{{ $r->mix >= $me['threshold'] && $r->n > 0 ? 'High' : 'Low' }}</td><td class="small">{{ $r->cm >= $me['avg_cm'] ? 'High' : 'Low' }}</td>
    <td><span class="badge badge-{{ $C[$r->cls][1] }}">{{ $C[$r->cls][0] }}</span></td></tr>
  @empty<tr><td colspan="12" class="muted">No costed items for this selection.</td></tr>@endforelse
  </tbody>
  @if($me['rows']->isNotEmpty())<tfoot><tr class="bold-row"><td>Total</td><td class="num">{{ number_format($me['n']) }}</td><td class="num">100%</td><td></td><td></td><td class="num">{{ money($me['avg_cm']) }}</td>
    <td class="num">{{ money($me['revenue']) }}</td><td class="num">{{ money($me['cost']) }}</td><td class="num">{{ money($me['cm']) }}</td><td colspan="3"></td></tr></tfoot>@endif
</table></div></div>
@if($me['uncosted']->isNotEmpty())
<div class="card flush"><div class="card-head"><h3>Not analysed – no recipe</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="fbcMeUncosted"><tbody>
  @foreach($me['uncosted'] as $r)<tr><td>{{ $r->item->name }}</td><td class="num">{{ number_format($r->n) }} sold</td><td class="num">{{ money($r->revenue) }}</td><td><a class="small" href="{{ route('fbc.card', $r->item) }}">recipe card</a></td></tr>@endforeach
</tbody></table></div></div>
@endif
@endsection
