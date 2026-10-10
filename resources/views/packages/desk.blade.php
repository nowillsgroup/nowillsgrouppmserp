@extends('layouts.app')
@section('title', 'Meal Desk')
@section('crumbs') <a href="{{ route('packages.index') }}">Packages & meals</a> / Meal desk @endsection
@section('actions')
<form method="get" action="{{ route('packages.desk') }}" class="flex gap-1" style="display:inline-flex;align-items:center">
  @if($pkg)<input type="hidden" name="package" value="{{ $pkg->id }}">@endif
  <input type="search" name="q" value="{{ $q }}" class="input-sm" placeholder="Room no. or guest" aria-label="Search room or guest" id="deskSearch" autofocus>
  <button class="btn"><x-icon name="search"/> Find</button>
</form>
@endsection
@php $ov = setting('pkg_overage_default', 'folio'); @endphp
@section('content')
@if($pkgs->isEmpty())
  <div class="card"><x-empty text="No redeemable packages are set up. Create breakfast / dinner packages in the catalogue first." icon="coffee"/></div>
@else
<div class="pkg-tabs">@foreach($pkgs as $p)
  <a href="{{ route('packages.desk', ['package' => $p->id]) }}" class="pkg-tab {{ $pkg->id === $p->id ? 'active' : '' }}" data-pkg="{{ $p->code }}">{{ $p->name }} <span class="badge {{ $counts[$p->id] ? 'badge-blue' : 'badge-slate' }}">{{ $counts[$p->id] }}</span></a>
@endforeach</div>

<div class="grid g-2-1">
<div class="card flush" id="entitledCard"><div class="card-head"><h3><x-icon name="coffee"/> {{ $pkg->name }} – {{ bdate()->format('D d M Y') }}</h3>
  <span class="small muted">{{ $pkg->redeem_mode === 'amount' ? 'allowance – enter the bill amount' : 'count the covers served' }}{{ $pkg->outlet ? ' · '.$pkg->outlet->name : '' }}</span></div>
<div class="card-body table-wrap"><table class="table" id="deskTable">
  <thead><tr><th>Room</th><th>Guest</th><th>Rate</th><th class="num">{{ $pkg->redeem_mode === 'amount' ? 'Allowance left' : 'Covers left' }}</th><th style="min-width:300px">Record</th></tr></thead>
  <tbody>@forelse($rows as $e) @php $s = $e->stay; @endphp
    <tr data-room="{{ $s?->room?->room_no }}"><td class="bold big">{{ $s?->room?->room_no }}</td>
      <td class="small">{{ $s?->guest?->full_name }}<div class="tiny muted">{{ $e->adults }}A{{ $e->children ? ' + '.$e->children.'C' : '' }} · {{ $e->source === 'addon' ? 'add-on' : 'included' }}{{ $e->service_date->lt(bdate()) ? ' · from '.$e->service_date->format('d M') : '' }}</div></td>
      <td class="small">{{ $s?->ratePlan?->code ?? '—' }}</td>
      <td class="num">@if($pkg->redeem_mode === 'amount')<b>{{ money($e->remainingAmount(), false) }}</b><div class="tiny muted">of {{ money($e->amount, false) }}</div>@else<b class="big">{{ $e->remainingQty() }}</b><span class="muted">/{{ $e->qty }}</span>@endif</td>
      <td><form method="post" action="{{ route('packages.redeem') }}" class="flex gap-1 desk-form">@csrf
        <input type="hidden" name="reservation_room_id" value="{{ $e->reservation_room_id }}"><input type="hidden" name="package_id" value="{{ $pkg->id }}">
        @if($pkg->redeem_mode === 'amount')
          <input type="number" name="amount" step="0.01" min="0.01" class="input-sm" style="width:110px" placeholder="Bill GH₵" required aria-label="Bill amount">
          <input name="pos_ref" class="input-sm" style="width:90px" placeholder="Bill no." maxlength="40" aria-label="Bill number">
        @else
          <input type="number" name="covers" min="1" max="50" value="{{ max(1, $e->remainingQty()) }}" class="input-sm" style="width:64px" required aria-label="Covers">
        @endif
        <select name="overage_mode" class="input-sm" title="If more than the entitlement" aria-label="Extras">@foreach(\App\Models\PackageRedemption::OVERAGE as $k => $l)<option value="{{ $k }}" @selected($k === $ov) @disabled($k === 'folio' && $s?->folio?->status !== 'open')>{{ $l }}</option>@endforeach</select>
        <button class="btn btn-sm btn-primary"><x-icon name="check" size="sm"/> Record</button>
      </form></td></tr>
  @empty
    <tr><td colspan="5"><x-empty text="{{ $q !== '' ? 'No room matching “'.$q.'” is entitled to '.$pkg->name.' today.' : 'Every entitled room has used its '.$pkg->name.' today.' }}" icon="check"/></td></tr>
  @endforelse</tbody>
</table></div></div>

<div>
<div class="card" id="walkinCard"><div class="card-head"><h3><x-icon name="plus"/> Room without {{ $pkg->name }}</h3></div>
  <div class="card-body"><p class="small muted" style="margin-top:0">In-house guest on a rate that doesn't include it{{ (float) $pkg->overage_price > 0 && $pkg->redeem_mode === 'covers' ? ' – charged at '.money($pkg->overage_price).' per cover' : '' }}.</p>
  <form method="post" action="{{ route('packages.redeem') }}" class="form-grid">@csrf<input type="hidden" name="package_id" value="{{ $pkg->id }}">
    <div class="field"><label for="wiRoom">Room</label><select name="reservation_room_id" id="wiRoom" required><option value="">—</option>@foreach($inHouse as $s)<option value="{{ $s->id }}">{{ $s->room?->room_no }} · {{ $s->guest?->full_name }}</option>@endforeach</select></div>
    @if($pkg->redeem_mode === 'amount')
      <div class="field"><label for="wiAmt">Bill amount</label><input type="number" step="0.01" min="0.01" name="amount" id="wiAmt" required></div>
    @else
      <div class="field"><label for="wiCov">Covers</label><input type="number" min="1" max="50" name="extra_covers" id="wiCov" value="1" required></div>
    @endif
    <div class="field"><label for="wiMode">Settle</label><select name="overage_mode" id="wiMode">@foreach(\App\Models\PackageRedemption::OVERAGE as $k => $l)<option value="{{ $k }}" @selected($k === $ov)>{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><button class="btn btn-soft">Record</button></div>
  </form></div>
</div>

<div class="card flush" id="servedCard"><div class="card-head"><h3><x-icon name="check"/> Served today</h3><span class="small muted">{{ $served->whereNull('voided_at')->sum('covers') + $served->whereNull('voided_at')->sum('extra_covers') }} covers</span></div>
<div class="card-body table-wrap"><table class="table">
  <tbody>@forelse($served as $r)
    <tr class="{{ $r->voided_at ? 'muted strike' : '' }}" data-redemption="{{ $r->redemption_no }}"><td class="small"><b>{{ $r->stay?->room?->room_no }}</b> {{ $r->stay?->guest?->full_name }}<div class="tiny muted">{{ $r->redemption_no }} · {{ $r->created_at->format('H:i') }} · {{ $r->recorder?->name }}{{ $r->voided_at ? ' · voided: '.$r->void_reason : '' }}</div></td>
      <td class="num small">{{ $pkg->redeem_mode === 'amount' ? money($r->amount, false) : $r->covers }}@if($r->extra_covers || (float) $r->extra_amount > 0)<div class="tiny text-amber">+{{ $pkg->redeem_mode === 'amount' ? '' : $r->extra_covers.' · ' }}{{ money($r->extra_amount, false) }} {{ $r->overage_mode }}</div>@endif</td>
      <td class="right">@if(! $r->voided_at && can_do('packages.manage'))<form method="post" action="{{ route('packages.void', $r) }}" data-confirm="Void {{ $r->redemption_no }}? Any extra charged to the room is reversed." class="flex gap-1">@csrf<input type="hidden" name="reason" value="Recorded in error"><button class="btn btn-sm btn-ghost" title="Void"><x-icon name="x" size="sm"/></button></form>@endif</td></tr>
  @empty <tr><td><x-empty text="Nothing recorded yet today." icon="coffee"/></td></tr> @endforelse</tbody>
</table></div></div>
</div>
</div>
@endif
@endsection
