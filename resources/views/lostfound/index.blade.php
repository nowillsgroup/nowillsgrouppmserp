@extends('layouts.app')
@section('title', 'Lost & Found')
@section('crumbs') Desk Operations / Lost & Found @endsection
@section('actions')
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'lost_found_register') }}"><x-icon name="chart"/> Register</a>@endif
  <button class="btn" data-modal="reportModal" id="newReportBtn"><x-icon name="search"/> Guest lost something</button>
  <button class="btn btn-primary" data-modal="lfModal" id="newItemBtn"><x-icon name="plus"/> Log found item</button>
@endsection
@section('content')
@php $c = $counts; $cats = collect(\App\Models\LostFoundItem::CATEGORIES)->map(fn ($x) => $x[0])->all(); @endphp
@if($c['lf_due'])<div class="alert alert-warn" id="lfDue"><x-icon name="alert"/><div><a href="{{ route('lostfound.index', ['tab' => 'due']) }}"><b>{{ $c['lf_due'] }}</b> item(s) are past their retention date</a> and may now be donated, destroyed or handed over (valuables need a witness).</div></div>@endif
<div class="stats">
  <x-stat label="In store" :value="$c['lf_stored']" icon="box" tone="blue" :sub="\App\Models\LostFoundItem::where('status', 'stored')->where('valuable', true)->count().' valuables in the safe'"/>
  <x-stat label="Open loss reports" :value="$c['lf_reports']" icon="search" :tone="$c['lf_reports'] ? 'amber' : 'green'" sub="guests looking for something"/>
  <x-stat label="Due for disposal" :value="$c['lf_due']" icon="trash" :tone="$c['lf_due'] ? 'amber' : 'green'" :sub="'kept '.$cfg['lf_retention'].' d · valuables '.$cfg['lf_retention_valuable'].' d'"/>
  <x-stat label="Returned this month" :value="\App\Models\LostFoundItem::where('status', 'returned')->where('returned_date', '>=', bdate()->copy()->startOfMonth()->toDateString())->count()" icon="check" tone="green" sub="to owners"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\LostFoundController::TABS as $k => $l)<a href="{{ route('lostfound.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}@if($k === 'due' && $c['lf_due']) <span class="badge badge-amber">{{ $c['lf_due'] }}</span>@elseif($k === 'reports' && $c['lf_reports']) <span class="badge badge-blue">{{ $c['lf_reports'] }}</span>@endif</a>@endforeach
  </div>
  <form method="get" class="flex" style="gap:.4rem;flex-wrap:wrap"><input type="hidden" name="tab" value="{{ $tab }}">
    <input class="input" name="q" value="{{ request('q') }}" placeholder="{{ $tab === 'reports' ? 'Report no, name, item' : 'Item no, name, room' }}" style="max-width:13rem">
    @if($tab !== 'reports')<select class="input" name="category" style="max-width:11rem"><option value="">All categories</option>@foreach($cats as $k => $l)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $l }}</option>@endforeach</select>
    <label class="flex small" style="gap:.3rem;align-items:center"><input type="checkbox" name="valuable" value="1" @checked(request('valuable'))> valuables</label>
    @else<select class="input" name="status" style="max-width:9rem">@foreach(['open' => 'Open', 'matched' => 'Matched', 'closed' => 'Closed', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected(request('status', 'open') === $k)>{{ $l }}</option>@endforeach</select>@endif
    <button class="btn btn-sm">Filter</button></form></div>
  <div class="card-body table-wrap">
  @if($tab === 'reports')
    <table class="table" id="reportsTable"><thead><tr><th>Report</th><th>Guest</th><th>Lost item</th><th>When / where</th><th>Status</th></tr></thead><tbody>
    @forelse($reports as $r)
      <tr data-report="{{ $r->id }}"><td><a href="{{ route('lostfound.report.show', $r) }}">{{ $r->report_no }}</a><div class="small muted">{{ $r->created_at->format('d M') }}</div></td>
        <td>{{ $r->name }}<div class="small muted">{{ $r->phone ?? $r->email }}</div></td>
        <td>{{ $r->item }}<div class="small muted">{{ $cats[$r->category] ?? '' }}</div></td>
        <td class="small">{{ $r->lost_date?->format('d M') ?? '—' }} · {{ $r->where_lost ?? '—' }}</td>
        <td><span class="badge badge-{{ ['open' => 'amber', 'matched' => 'blue', 'closed' => 'slate'][$r->status] }}">{{ ucfirst($r->status) }}</span>@if($r->foundItem) <a class="small" href="{{ route('lostfound.show', $r->foundItem) }}">{{ $r->foundItem->item_no }}</a>@endif</td></tr>
    @empty<tr><td colspan="5" class="muted">No loss reports.</td></tr>@endforelse
    </tbody></table>
  @else
    <table class="table" id="itemsTable"><thead><tr><th>Item no.</th><th>Item</th><th>Found</th><th>Probable owner</th><th>Stored</th><th>{{ in_array($tab, ['returned', 'disposed']) ? 'Outcome' : 'Keep until' }}</th></tr></thead><tbody>
    @forelse($items as $i)
      <tr data-item="{{ $i->id }}" style="{{ $i->isDue() ? 'background:#fffbeb' : '' }}"><td class="nowrap"><a href="{{ route('lostfound.show', $i) }}">{{ $i->item_no ?? '#'.$i->id }}</a></td>
        <td><b>{{ $i->item_name }}</b>@if($i->valuable) <span class="badge badge-red">Valuable</span>@endif<div class="small muted">{{ $i->categoryLabel() }}{{ $i->description ? ' · '.\Illuminate\Support\Str::limit($i->description, 60) : '' }}</div></td>
        <td class="small">{{ $i->found_date->format('d M Y') }}<div class="muted">{{ $i->location ?? '—' }} · {{ $i->found_by }}</div></td>
        <td class="small">{{ $i->guest?->full_name ?? '—' }}@if($i->report)<div><span class="badge badge-blue">{{ $i->report->report_no }}</span></div>@endif</td>
        <td class="small">{{ $i->storage_location ?? '—' }}</td>
        <td class="small">@if($i->status === 'returned')<span class="text-green">Returned {{ $i->returned_date?->format('d M') }}</span><div class="muted">{{ $i->returned_to }}</div>@elseif($i->status === 'disposed')<span class="muted">{{ \App\Models\LostFoundItem::DISPOSAL[$i->disposal_method] ?? 'Disposed' }} {{ $i->disposed_date?->format('d M') }}</span>@else<span class="{{ $i->isDue() ? 'text-amber' : '' }}">{{ $i->retain_until?->format('d M Y') ?? '—' }}</span>@endif</td></tr>
    @empty<tr><td colspan="6" class="muted" id="itemsEmpty">Nothing here.</td></tr>@endforelse
    </tbody></table>
  @endif
  </div>
  @php $pg = $tab === 'reports' ? $reports : $items; @endphp
  @if($pg->hasPages())<div class="card-foot">{{ $pg->links() }}</div>@endif
</div>

<div class="modal" id="lfModal"><div class="modal-box" style="max-width:700px"><form method="post" action="{{ route('lostfound.store') }}">@csrf
  <div class="modal-head"><h3>Log a found item</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="item_name" label="Item" required placeholder="e.g. Black leather wallet"/>
    <x-field name="category" label="Category" type="select" :options="$cats" value="other" :placeholder="false"/>
    <x-field name="description" label="Description (colour, brand, contents)" type="textarea" rows="2" class="span-all" hint="For wallets / bags: list the contents with a witness – don't record card numbers"/>
    <x-field name="found_date" label="Found on" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="room_id" label="Room" type="select" :options="$rooms" placeholder="— public area —"/>
    <x-field name="location" label="Where exactly" placeholder="e.g. lobby sofa, bedside drawer"/>
    <x-field name="found_by" label="Found by" :value="auth()->user()->name"/>
    <x-field name="est_value" label="Estimated value" type="number" step="0.01" min="0"/>
    <x-field name="storage_location" label="Stored in" placeholder="Safe / L&F store shelf B"/>
    <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="valuable" value="1"> Valuable (safe, ID check, witnessed disposal)</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveItem">Log item</button></div>
</form></div></div>

<div class="modal" id="reportModal"><div class="modal-box" style="max-width:700px"><form method="post" action="{{ route('lostfound.report') }}">@csrf
  <div class="modal-head"><h3>Guest reports a lost item</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="reservation_room_id" label="In-house guest (optional)" type="select" :options="$stays" placeholder="— past guest / visitor —" class="span-all"/>
    <x-field name="name" label="Name" required/>
    <x-field name="phone" label="Phone"/>
    <x-field name="email" label="E-mail" type="email"/>
    <x-field name="item" label="What was lost" required/>
    <x-field name="category" label="Category" type="select" :options="$cats" value="other" :placeholder="false"/>
    <x-field name="lost_date" label="Lost on (approx.)" type="date"/>
    <x-field name="where_lost" label="Where" placeholder="room, restaurant, pool…"/>
    <x-field name="description" label="Description" type="textarea" rows="2" class="span-all"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveReport">Record & search store</button></div>
</form></div></div>
@endsection
