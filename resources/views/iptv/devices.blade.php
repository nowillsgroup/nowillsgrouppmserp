@extends('layouts.app')
@section('title', 'TVs & Screens')
@section('crumbs') IPTV &amp; Guest Welcome / TVs &amp; Screens @endsection
@php $u = auth()->user(); $canMg = \App\Services\IptvService::canManage($u); $D = \App\Models\TvDevice::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'iptv_devices']) }}"><x-icon name="download"/> Device report</a>
  @if($canMg)<button class="btn btn-primary" data-modal="tvAddModal" id="tvAddBtn"><x-icon name="plus"/> Add TV</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end">
      <div class="field"><label>Type</label><select name="kind"><option value="">All</option>@foreach($D::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label>State</label><select name="state"><option value="">All</option>@foreach(['online' => 'Online', 'offline' => 'Offline', 'pending' => 'Awaiting pairing', 'disabled' => 'Disabled'] as $k => $l)<option value="{{ $k }}" @selected(request('state') === $k)>{{ $l }}</option>@endforeach</select></div>
      <button class="btn btn-sm">Filter</button></form></div></div>
    <div class="card flush"><div class="card-head"><h3>{{ $list->count() }} TV(s)</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="tvDeviceList"><thead><tr><th>TV</th><th>Where</th><th>Platform</th><th>Guest</th><th>Last seen</th><th>State</th></tr></thead><tbody>
    @forelse($list as $d)@php $st = $d->room_id ? ($stays[$d->room_id] ?? null) : null; $g = $st ? ($st->guest ?? $st->reservation?->guest) : null; @endphp
      <tr><td>@if($d->status === 'pending')<b>{{ $d->name }}</b><div class="tiny muted">code <b class="tv-code">{{ $d->pairing_code }}</b> · expires {{ $d->pairing_expires_at?->format('H:i') }}</div>@else<a href="{{ route('iptv.device', $d) }}"><b>{{ $d->name }}</b></a>@endif</td>
        <td class="small">{{ $d->place() }}</td><td class="small">{{ $D::PLATFORMS[$d->platform] ?? $d->platform }}<div class="tiny muted">{{ $d->model }}</div></td>
        <td class="small">{{ $g ? trim($g->first_name.' '.$g->last_name) : '—' }}</td>
        <td class="small nowrap">{{ $d->last_seen_at?->diffForHumans() ?? 'never' }}<div class="tiny muted">{{ $d->last_ip }}</div></td>
        <td><span class="badge badge-{{ $d->stateTone() }}">{{ $d->stateLabel() }}</span></td></tr>
    @empty<tr><td colspan="6" class="muted">No TVs match.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <div>
    @if($canMg)
    <div class="card" id="pair"><div class="card-head"><h3>Pair a new TV</h3></div><div class="card-body">
      <ol class="small tv-steps">
        <li>On the TV (hotel mode / URL launcher / browser) open<br><code class="tv-url" id="tvSetupUrl">{{ $setupUrl }}</code></li>
        <li>The TV shows a 6-digit pairing code – enter it below with the room.</li>
        <li>The TV switches to the guest portal by itself; it keeps its own start URL.</li>
      </ol>
      <form method="post" action="{{ route('iptv.devices.pair') }}" class="form-grid" id="tvPairForm">@csrf
        <div class="field span-all"><label>Pairing code</label><input name="code" class="input tv-code-in" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required value="{{ old('code') }}" autocomplete="off"></div>
        @include('iptv._device_fields', ['x' => null])
        <div class="span-all"><button class="btn btn-primary">Pair TV</button></div>
      </form>
    </div></div>
    @endif
    <div class="card"><div class="card-head"><h3>Supported TVs</h3></div><div class="card-body small">
      <p class="m-0">Any hotel TV or set-top box that can open a web page full-screen: Samsung hotel TVs (Tizen hospitality mode, “URL launcher”), LG Pro:Centric (webOS), Philips MediaSuite, Android / Google TV hotel launchers or a kiosk browser on a mini-PC. Live channels play from the hotel’s headend (multicast / HLS) – the portal shows the line-up and guide; set-top-box players tune <code>udp://</code> streams natively.</p>
    </div></div>
  </div>
</div>
@if($canMg)
<div class="modal" id="tvAddModal"><div class="modal-box lg"><form method="post" action="{{ route('iptv.devices.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Add a TV without pairing</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><p class="small muted">The TV gets its own start URL – type it into the TV’s hotel-mode / URL launcher settings.</p><div class="form-grid">@include('iptv._device_fields', ['x' => null])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add TV</button></div></form></div></div>
@endif
@endsection
