@extends('layouts.app')
@section('title', 'Asset Register')
@section('crumbs') Fixed Assets / Asset register @endsection
@section('actions')
<a class="btn" href="{{ route('assets.export') }}"><x-icon name="download"/> Export CSV</a>
<button class="btn" type="submit" form="tagForm" id="printTags"><x-icon name="qr"/> Print tags</button>
@if(can_do('assets.manage'))<a class="btn btn-primary" href="{{ route('assets.create') }}" id="addAsset"><x-icon name="plus"/> Add asset</a>@endif
@endsection
@section('content')
@if($next && $next->lt(bdate()) && can_do('assets.depreciation'))
<div class="alert alert-info" id="depDue"><x-icon name="calendar"/><div>Depreciation for <b>{{ $next->format('F Y') }}</b> hasn't been run yet. <a href="{{ route('assets.depreciation') }}">Review &amp; post →</a></div></div>
@endif
<div class="stats wide">
  <x-stat label="Assets in use" :value="$stats['count']" icon="layers" tone="blue" :sub="$stats['repair'] ? $stats['repair'].' under repair' : 'none under repair'"/>
  <x-stat label="Cost" :value="money($stats['cost'])" icon="money" tone="purple"/>
  <x-stat label="Accumulated depreciation" :value="money($stats['acc'])" icon="trend" tone="amber" :sub="$stats['cost'] ? round($stats['acc'] * 100 / $stats['cost']).' % of cost written off' : null"/>
  <x-stat label="Net book value" :value="money($stats['nbv'])" icon="chart" tone="green"/>
  <x-stat label="Warranty ending ≤ 60 days" :value="$stats['warranty']" icon="shield" :tone="$stats['warranty'] ? 'red' : 'green'" href="?warranty=expiring"/>
</div>
<div class="tabs">@foreach(['live' => 'In use', 'active' => 'Working', 'in_repair' => 'Under repair', 'disposed' => 'Disposed', 'all' => 'All'] as $k => $v)<a href="?{{ http_build_query(array_filter(['status' => $k, 'category' => request('category'), 'q' => request('q')])) }}" class="{{ request('status', 'live') === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters mb-1 flex gap-1 items-center wrap" method="get">
  <input type="hidden" name="status" value="{{ request('status', 'live') }}">
  <input name="q" class="input input-sm" style="max-width:280px" value="{{ request('q') }}" placeholder="Name, asset no., serial, room">
  <select name="category" class="input input-sm" style="max-width:240px"><option value="">All categories</option>@foreach($categories as $id => $n)<option value="{{ $id }}" @selected(request('category') == $id)>{{ $n }}</option>@endforeach</select>
  <select name="department" class="input input-sm" style="max-width:200px"><option value="">All departments</option>@foreach(\App\Models\Asset::DEPARTMENTS as $d)<option @selected(request('department') === $d)>{{ $d }}</option>@endforeach</select>
  <button class="btn btn-sm"><x-icon name="search"/> Filter</button>
</form>
<form method="get" action="{{ route('assets.tags') }}" target="_blank" id="tagForm">
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="assetTable">
<thead><tr><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.tagPick').forEach(c => c.checked = this.checked)" title="Select all"></th><th>Asset</th><th>Category</th><th>Location</th><th>Purchased</th><th class="num">Cost</th><th class="num">Acc. dep.</th><th class="num">Book value</th><th>Status</th></tr></thead><tbody>
@forelse($assets as $a)
@php $disp = $a->isDisposed(); $w = $a->warrantyState(); @endphp
<tr>
  <td>@unless($disp)<input type="checkbox" class="tagPick" name="ids[]" value="{{ $a->id }}">@endunless</td>
  <td><a class="bold" href="{{ route('assets.show', $a) }}">{{ $a->name }}</a><div class="tiny muted">{{ $a->asset_no }}{{ $a->make ? ' · '.$a->make : '' }}{{ $a->model ? ' '.$a->model : '' }}{{ $a->serial_no ? ' · S/N '.$a->serial_no : '' }}</div></td>
  <td class="small">{{ $a->category?->name }}<div class="tiny muted">{{ strtoupper($a->method) }}{{ $a->method === 'wdv' ? ' '.rtrim(rtrim(number_format((float) $a->wdv_rate, 2), '0'), '.').' %' : ' '.\App\Models\AssetCategory::years($a->life_months) }}</div></td>
  <td class="small">{{ $a->placeLabel() ?: '—' }}@if($a->department)<div class="tiny muted">{{ $a->department }}</div>@endif</td>
  <td class="small">{{ $a->purchase_date->format('d M Y') }}@if($w === 'expiring')<div class="tiny text-red">warranty ends {{ $a->warranty_until->format('d M') }}</div>@elseif($w === 'valid')<div class="tiny text-green">in warranty</div>@endif</td>
  <td class="num">{{ money($a->cost, false) }}</td>
  <td class="num">{{ money($disp ? (float) $a->cost - (float) $a->disposal_nbv : $a->accumulated(), false) }}</td>
  <td class="num bold">{{ $disp ? '—' : money($a->nbv(), false) }}</td>
  <td><span class="badge badge-{{ \App\Models\Asset::TONES[$a->status] }}">{{ \App\Models\Asset::STATUSES[$a->status] }}</span>@if($disp)<div class="tiny muted">{{ \App\Models\Asset::DISPOSALS[$a->disposal_type] ?? '' }} {{ $a->disposed_date?->format('d M Y') }}</div>@endif</td>
</tr>
@empty<tr><td colspan="9"><x-empty text="No assets match." icon="layers"/></td></tr>@endforelse
</tbody></table>{{ $assets->links() }}</div></div>
</form>
<p class="tiny muted mt-1">Tick assets and use <b>Print tags</b> for QR labels (nothing ticked = all assets in use). Scanning a tag opens the asset's page – history, depreciation and maintenance tickets.</p>
@endsection
