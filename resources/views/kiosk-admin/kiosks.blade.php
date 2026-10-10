@extends('layouts.app')
@section('title', 'Kiosks')
@section('crumbs') Self-Service Kiosk / Kiosks @endsection
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'kiosk_sessions']) }}"><x-icon name="download"/> Visit report</a>
  @if($canManage)<button class="btn btn-primary" data-modal="kskAddModal" id="kskAddBtn"><x-icon name="plus"/> Add kiosk</button>@endif
@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>{{ $kiosks->count() }} kiosk(s)</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="kskList"><thead><tr><th>Kiosk</th><th>Services</th><th>Hardware</th><th>Visits</th><th>Last seen</th><th>State</th>@if($canManage)<th></th>@endif</tr></thead><tbody>
    @forelse($kiosks as $k)
      <tr><td class="ksk-name">@if($k->status === 'pending')<b>{{ $k->name }}</b><div class="tiny muted">code <b class="tv-code">{{ $k->pairing_code }}</b> · expires {{ $k->pairing_expires_at?->format('H:i') }}</div>@else<b>{{ $k->name }}</b><div class="tiny muted">{{ $k->location }}{{ $k->serial ? ' · '.$k->serial : '' }}</div>@endif</td>
        <td class="small">{{ implode(', ', $k->services()) ?: '—' }}</td>
        <td class="small">{{ implode(', ', array_keys(array_filter(['Encoder' => $k->has_encoder, 'Camera' => $k->has_camera]))) ?: '—' }}</td>
        <td class="small">{{ $k->today_count }} today<div class="tiny muted">{{ $k->sessions_count }} in all</div></td>
        <td class="small nowrap">{{ $k->last_seen_at?->diffForHumans() ?? 'never' }}<div class="tiny muted">{{ $k->last_ip }}</div></td>
        <td><span class="badge badge-{{ $k->stateTone() }}">{{ $k->stateLabel() }}</span></td>
        @if($canManage)<td class="ksk-acts">@if($k->status !== 'pending')
          <button class="btn btn-xs btn-sm" data-modal="kskEdit{{ $k->id }}">Edit</button>
          <form method="post" action="{{ route('kiosks.status', $k) }}" class="inline">@csrf<input type="hidden" name="status" value="{{ $k->status === 'active' ? 'disabled' : 'active' }}"><button class="btn btn-sm">{{ $k->status === 'active' ? 'Take out of service' : 'Switch on' }}</button></form>
          <form method="post" action="{{ route('kiosks.rotate', $k) }}" class="inline" onsubmit="return confirm('Issue a new start address? The kiosk stops working until the new address is entered.')">@csrf<button class="btn btn-sm">New address</button></form>
          @if(! $k->sessions_count)<form method="post" action="{{ route('kiosks.destroy', $k) }}" class="inline" onsubmit="return confirm('Remove this kiosk?')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Remove</button></form>@endif
        @endif</td>@endif</tr>
    @empty<tr><td colspan="7" class="muted">No kiosks yet.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <div>
    @if($canManage)
    <div class="card" id="pair"><div class="card-head"><h3>Pair a new kiosk</h3></div><div class="card-body">
      <ol class="small tv-steps">
        <li>On the kiosk (touch PC or tablet, browser in kiosk / full-screen mode) open<br><code class="tv-url" id="kskSetupUrl">{{ $setupUrl }}</code></li>
        <li>It shows a 6-digit pairing code – enter it below.</li>
        <li>The kiosk opens its welcome screen by itself and keeps its own start address.</li>
      </ol>
      <form method="post" action="{{ route('kiosks.pair') }}" class="form-grid" id="kskPairForm">@csrf
        <div class="field span-all"><label>Pairing code</label><input name="code" class="input tv-code-in" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required value="{{ old('code') }}" autocomplete="off"></div>
        @include('kiosk-admin._fields', ['x' => null])
        <div class="span-all"><button class="btn btn-primary">Pair kiosk</button></div>
      </form>
    </div></div>
    @endif
    <div class="card"><div class="card-head"><h3>Hardware</h3></div><div class="card-body small">
      <p class="m-0">Any touch screen that runs a modern browser full-screen: a lobby kiosk PC, an all-in-one or a wall-mounted tablet. The camera photographs ID documents; a USB QR / barcode scanner (keyboard mode) reads pre-check-in QR codes; the key-card encoder is driven through <b>Interfaces › Door locks</b>. Payment is by QR – guests pay on their own phone with mobile money or a card.</p>
    </div></div>
  </div>
</div>
@if($canManage)
<div class="modal" id="kskAddModal"><div class="modal-box lg"><form method="post" action="{{ route('kiosks.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>Add a kiosk without pairing</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><p class="small muted">The kiosk gets its own start address – type it into the kiosk browser.</p><div class="form-grid">@include('kiosk-admin._fields', ['x' => null])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Add kiosk</button></div></form></div></div>
@foreach($kiosks->where('status', '!=', 'pending') as $k)
<div class="modal" id="kskEdit{{ $k->id }}"><div class="modal-box lg"><form method="post" action="{{ route('kiosks.update', $k) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>{{ $k->name }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('kiosk-admin._fields', ['x' => $k])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endforeach
@endif
@endsection
