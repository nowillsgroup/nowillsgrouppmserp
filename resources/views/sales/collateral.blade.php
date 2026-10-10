@extends('layouts.app')
@section('title', 'Collateral Library')
@section('crumbs') Sales & Marketing / Collateral @endsection
@php $K = \App\Models\MarketingCollateral::class; $mk = can_do('sales.marketing') || can_do('sales.manage'); @endphp
@section('actions')
  @if($mk)<button class="btn btn-primary" data-modal="collModalNew" id="addCollBtn"><x-icon name="plus"/> Add collateral</button>@endif
@endsection
@section('content')
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center">
  <input name="q" value="{{ request('q') }}" placeholder="Title" aria-label="Search"><select name="kind" aria-label="Kind"><option value="">Any kind</option>@foreach($K::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select>
  <label class="small"><input type="checkbox" name="inactive" value="1" @checked(request('inactive'))> Include archived</label><button class="btn">Filter</button></form></div></div>
<div class="card flush mt-1"><div class="card-body table-wrap"><table class="table table-compact" id="collTable">
  <thead><tr><th>Title</th><th>Kind</th><th>Language</th><th>Version</th><th>Valid until</th><th class="num">Downloads</th><th></th></tr></thead>
  <tbody>@forelse($list as $x)
    <tr data-coll="{{ $x->id }}" class="{{ $x->isExpired() ? 'row-warn' : '' }}"><td><b>{{ $x->title }}</b>@unless($x->is_active) <span class="badge badge-slate">archived</span>@endunless<div class="tiny muted">{{ $x->file_name }}{{ $x->file_size ? ' · '.number_format($x->file_size / 1024).' KB' : '' }}{{ $x->notes ? ' · '.$x->notes : '' }}</div></td>
      <td class="small">{{ $K::KINDS[$x->kind] ?? $x->kind }}</td><td class="small">{{ $K::LANGUAGES[$x->language] ?? $x->language }}</td><td class="small">{{ $x->version ?? '—' }}</td>
      <td class="small {{ $x->isExpired() ? 'text-red bold' : '' }}">{{ $x->valid_until?->format('d M Y') ?? '—' }}{{ $x->isExpired() ? ' · expired' : '' }}</td><td class="num">{{ $x->downloads }}</td>
      <td class="nowrap">@if($x->file_path)<a class="btn btn-sm" href="{{ route('sales.collateral.download', $x) }}"><x-icon name="download"/></a>@endif @if($x->url)<a class="btn btn-sm btn-ghost" href="{{ $x->url }}" target="_blank" rel="noopener"><x-icon name="link"/></a>@endif
        @if($mk)<button class="btn btn-sm btn-ghost btn-icon" data-modal="collModal{{ $x->id }}" title="Edit"><x-icon name="edit"/></button>@endif</td></tr>
  @empty<tr><td colspan="7" class="empty">No collateral yet – upload the hotel brochure, rate cards and fact sheets so the sales team always sends the current version.</td></tr>@endforelse</tbody>
</table></div></div>
@if($mk)
@foreach($list->prepend(null) as $x)@php $mid = $x ? 'collModal'.$x->id : 'collModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ $x ? route('sales.collateral.update', $x) : route('sales.collateral.store') }}" enctype="multipart/form-data">@csrf
  <div class="modal-head"><h3>{{ $x ? 'Edit '.$x->title : 'Add collateral' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Title</label><input name="title" maxlength="150" required value="{{ $x?->title }}"></div>
    <div class="field"><label>Kind</label><select name="kind">@foreach($K::KINDS as $k => $l)<option value="{{ $k }}" @selected($x?->kind === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Language</label><select name="language">@foreach($K::LANGUAGES as $k => $l)<option value="{{ $k }}" @selected(($x?->language ?? 'en') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Version</label><input name="version" maxlength="20" value="{{ $x?->version }}" placeholder="2026 v2"></div>
    <div class="field"><label>Valid until</label><input type="date" name="valid_until" value="{{ $x?->valid_until?->toDateString() }}"></div>
    <div class="field span-all"><label>File {{ $x?->file_name ? '(replace '.$x->file_name.')' : '' }} <span class="tiny muted">PDF, image, Office, MP4 or ZIP · max 20 MB</span></label><input type="file" name="file"></div>
    <div class="field span-all"><label>…or link</label><input name="url" maxlength="255" value="{{ $x?->url }}" placeholder="https://"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="500" value="{{ $x?->notes }}"></div>
    @if($x)<label class="small"><input type="checkbox" name="is_active" value="1" @checked($x->is_active)> Active (untick to archive)</label>@endif
  </div></div>
  <div class="modal-foot">@if($x)<button type="submit" form="collDel{{ $x->id }}" class="btn btn-danger" style="margin-right:auto">Delete</button>@endif<button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@if($x)<form method="post" action="{{ route('sales.collateral.delete', $x) }}" id="collDel{{ $x->id }}" data-confirm="Delete {{ $x->title }}?">@csrf</form>@endif
@endforeach
@endif
@endsection
