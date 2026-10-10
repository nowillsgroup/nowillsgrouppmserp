@extends('layouts.app')
@section('title', 'Channels & Guide')
@section('crumbs') IPTV &amp; Guest Welcome / Channels &amp; Guide @endsection
@php $u = auth()->user(); $canMg = \App\Services\IptvService::canManage($u); $C = \App\Models\TvChannel::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('iptv.channels.export') }}" id="tvM3uExport"><x-icon name="download"/> Export M3U</a>
  @if($canMg)<button class="btn" data-modal="tvImportModal" id="tvImportBtn"><x-icon name="refresh"/> Import line-up / guide</button>
  <button class="btn btn-primary" data-modal="tvChannelModal" id="tvChannelNew"><x-icon name="plus"/> Add channel</button>@endif
@endsection
@section('content')
@if($alerts['epg_stale'])<div class="alert alert-warn"><x-icon name="alert"/><div><b>{{ $alerts['epg_stale'] }}</b> channel(s) with a guide id have less than 6 hours of programmes left. Import a fresh XMLTV file from your headend or EPG provider.</div></div>@endif
<div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end">
  <div class="field"><label>Category</label><select name="category"><option value="">All categories</option>@foreach($C::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $l }}</option>@endforeach</select></div>
  <button class="btn btn-sm">Filter</button><span class="small muted">{{ $list->where('is_active', true)->count() }} channels in the line-up · {{ $list->where('is_radio', true)->count() }} radio</span></form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="tvChannelList"><thead><tr><th class="num">No.</th><th>Channel</th><th>Category</th><th>On now</th><th>Next</th><th>Stream</th><th class="num">Guide</th><th></th></tr></thead><tbody>
@forelse($list as $c)@php $n = $nn[$c->id] ?? null; @endphp
  <tr class="{{ $c->is_active ? '' : 'muted' }}"><td class="num"><b>{{ $c->number }}</b></td><td>{{ $c->name }}<div class="tiny muted">{{ $c->language }}{{ $c->is_radio ? ' · radio' : '' }}{{ $c->is_active ? '' : ' · hidden' }}</div></td>
    <td class="small">{{ $c->categoryLabel() }}</td>
    <td class="small">@if($n && $n['now'])<b>{{ $n['now']->title }}</b><div class="tiny muted">{{ $n['now']->starts_at->format('H:i') }}–{{ $n['now']->ends_at->format('H:i') }}</div>@else<span class="muted">—</span>@endif</td>
    <td class="small">@if($n && $n['next']){{ $n['next']->starts_at->format('H:i') }} {{ $n['next']->title }}@else<span class="muted">—</span>@endif</td>
    <td class="tiny"><code>{{ \Illuminate\Support\Str::limit($c->stream_url, 38) }}</code></td>
    <td class="num small">{{ $c->epg_id ? $c->upcoming : '' }}<div class="tiny muted">{{ $c->epg_id }}</div></td>
    <td class="nowrap">@if($canMg)<button class="btn btn-sm" data-modal="tvCh{{ $c->id }}">Edit</button>@endif</td></tr>
@empty<tr><td colspan="8" class="muted">No channels yet – add them one by one or import the headend’s M3U playlist.</td></tr>@endforelse
</tbody></table></div></div>
@if($canMg)
<div class="modal" id="tvChannelModal"><div class="modal-box lg"><form method="post" action="{{ route('iptv.channels.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Add channel</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._channel_fields', ['x' => null])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add</button></div></form></div></div>
@foreach($list as $c)
<div class="modal" id="tvCh{{ $c->id }}"><div class="modal-box lg"><form method="post" action="{{ route('iptv.channels.update', $c) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>Channel {{ $c->number }} – {{ $c->name }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._channel_fields', ['x' => $c])</div></div>
  <div class="modal-foot"><button type="submit" form="tvChDel{{ $c->id }}" class="btn btn-danger" onclick="return confirm('Remove channel {{ $c->number }} and its guide?')">Remove</button><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form>
  <form method="post" action="{{ route('iptv.channels.delete', $c) }}" id="tvChDel{{ $c->id }}">@csrf @method('DELETE')</form></div></div>
@endforeach
<div class="modal" id="tvImportModal"><div class="modal-box lg">
  <div class="modal-head"><h3>Import</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body">
    <h4 class="m-0">Channel line-up (M3U playlist)</h4>
    <p class="tiny muted">From the IPTV headend / middleware. Channels are matched on <code>tvg-chno</code>, then on name; <code>group-title</code> sets the category, <code>tvg-id</code> the guide id.</p>
    <form method="post" action="{{ route('iptv.channels.import') }}" enctype="multipart/form-data" class="form-grid" id="tvM3uForm">@csrf
      <div class="field"><label>M3U file</label><input type="file" name="file" accept=".m3u,.m3u8,audio/x-mpegurl,text/plain"></div>
      <div class="field span-all"><label>…or paste the playlist</label><textarea name="text" rows="4" placeholder="#EXTM3U&#10;#EXTINF:-1 tvg-chno=&quot;101&quot; group-title=&quot;News&quot;,Joy News&#10;udp://@239.10.0.1:1234"></textarea></div>
      <div class="span-all"><button class="btn btn-primary">Import line-up</button></div>
    </form>
    <hr>
    <h4 class="m-0">Programme guide (XMLTV)</h4>
    <p class="tiny muted">Programmes from yesterday to 8 days ahead replace the guide for the channels in the file. Channels without a guide id are linked by display name.</p>
    <form method="post" action="{{ route('iptv.channels.epg') }}" enctype="multipart/form-data" class="form-grid" id="tvEpgForm">@csrf
      <div class="field"><label>XMLTV file</label><input type="file" name="file" accept=".xml,text/xml,application/xml" required></div>
      <div class="span-all"><button class="btn btn-primary">Import guide</button></div>
    </form>
  </div></div></div>
@endif
@endsection
