@extends('layouts.app')
@section('title', $i->item_no ?? 'Found item')
@section('crumbs') Desk Operations / <a href="{{ route('lostfound.index') }}">Lost & Found</a> / {{ $i->item_no }} @endsection
@section('actions')
  <a class="btn" href="{{ route('lostfound.tag', $i) }}" target="_blank" id="tagBtn"><x-icon name="print"/> Tag</a>
  @if($i->status === 'returned')<a class="btn" href="{{ route('lostfound.receipt', $i) }}" target="_blank" id="receiptBtn"><x-icon name="print"/> Return receipt</a>@endif
  @if($i->isStored())
    <button class="btn" data-modal="editModal" id="editBtn"><x-icon name="edit"/> Edit</button>
    <button class="btn" data-modal="disposeModal" id="disposeBtn"><x-icon name="trash"/> Dispose</button>
    <button class="btn btn-success" data-modal="returnModal" id="returnBtn"><x-icon name="check"/> Return to owner</button>
  @endif
@endsection
@section('content')
@php $cats = collect(\App\Models\LostFoundItem::CATEGORIES)->map(fn ($x) => $x[0])->all(); @endphp
@if($i->isStored() && $i->stay?->status === 'checked_in')
<div class="alert alert-info" id="ownerInHouse"><x-icon name="user"/><div>The probable owner <b>{{ $i->guest?->full_name }}</b> is still in-house in room <b>{{ $i->stay->room?->room_no }}</b> – contact the guest and return the item before check-out.</div></div>
@endif
@if($i->isDue())<div class="alert alert-warn" id="itemDue"><x-icon name="alert"/><div>Retention period ended on {{ $i->retain_until->format('d M Y') }} – this item may now be disposed of.</div></div>@endif
<div style="display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:1rem;align-items:start">
<div class="card"><div class="card-head"><h3>{{ $i->item_name }}</h3><div class="flex" style="gap:.35rem">@if($i->valuable)<span class="badge badge-red">Valuable</span>@endif<span class="badge badge-{{ $i->statusTone() }}" id="itemStatus">{{ $i->statusLabel() }}</span></div></div>
  <div class="card-body"><dl class="kv">
    <dt>Item no.</dt><dd><b>{{ $i->item_no }}</b></dd>
    <dt>Category</dt><dd>{{ $i->categoryLabel() }}</dd>
    <dt>Description</dt><dd style="white-space:pre-line">{{ $i->description ?: '—' }}</dd>
    @if($i->est_value !== null)<dt>Estimated value</dt><dd>{{ money($i->est_value) }}</dd>@endif
    <dt>Found</dt><dd>{{ $i->found_date->format('D d M Y') }} · {{ $i->location ?? '—' }}</dd>
    <dt>Found by</dt><dd>{{ $i->found_by ?? '—' }}</dd>
    <dt>Stored in</dt><dd>{{ $i->storage_location ?? '—' }}</dd>
    <dt>Keep until</dt><dd>{{ $i->retain_until?->format('d M Y') ?? '—' }}</dd>
    <dt>Probable owner</dt><dd>@if($i->guest)<a href="{{ route('guests.show', $i->guest) }}">{{ $i->guest->full_name }}</a>@if($i->stay) · stay {{ $i->stay->arrival_date->format('d M') }}–{{ $i->stay->departure_date->format('d M') }}@if($i->stay->reservation) (<a href="{{ route('reservations.show', $i->stay->reservation) }}">{{ $i->stay->reservation->reservation_no }}</a>)@endif @endif @else — @endif</dd>
    @if($i->report)<dt>Loss report</dt><dd><a href="{{ route('lostfound.report.show', $i->report) }}">{{ $i->report->report_no }}</a> · {{ $i->report->name }} {{ $i->report->phone ? '· '.$i->report->phone : '' }}</dd>@endif
  </dl></div></div>
<div>
  @if($i->status === 'returned')
  <div class="card" id="returnedCard"><div class="card-head"><h3>Returned</h3></div><div class="card-body"><dl class="kv">
    <dt>To</dt><dd>{{ $i->returned_to }} {{ $i->claimant_phone ? '· '.$i->claimant_phone : '' }}</dd>
    @if($i->claimant_id_type)<dt>ID checked</dt><dd>{{ \App\Services\LostFoundService::ID_TYPES[$i->claimant_id_type] ?? $i->claimant_id_type }} · {{ \App\Models\Guest::mask($i->claimant_id_no) }}</dd>@endif
    <dt>How</dt><dd>{{ \App\Models\LostFoundItem::RETURN_METHODS[$i->return_method] ?? '—' }}@if($i->courier) · {{ $i->courier }} {{ $i->tracking_no }}@endif</dd>
    @if((float) $i->courier_charge > 0)<dt>Courier charge</dt><dd>{{ money($i->courier_charge) }}</dd>@endif
    <dt>Date</dt><dd>{{ $i->returned_date?->format('d M Y') }} · released by {{ $i->releaser?->name ?? '—' }}</dd>
  </dl></div></div>
  @elseif($i->status === 'disposed')
  <div class="card" id="disposedCard"><div class="card-head"><h3>Disposed</h3></div><div class="card-body"><dl class="kv">
    <dt>Method</dt><dd>{{ \App\Models\LostFoundItem::DISPOSAL[$i->disposal_method] ?? '—' }}</dd>
    <dt>Date</dt><dd>{{ $i->disposed_date?->format('d M Y') }} · by {{ $i->disposer?->name ?? '—' }}</dd>
    @if($i->witness)<dt>Witness</dt><dd>{{ $i->witness->name }}</dd>@endif
    @if($i->disposal_note)<dt>Note</dt><dd>{{ $i->disposal_note }}</dd>@endif
  </dl></div></div>
  @endif
  @if($openReports->isNotEmpty())
  <div class="card" id="possibleReports"><div class="card-head"><h3>Open loss reports that may match</h3></div><div class="card-body table-wrap"><table class="table"><tbody>
    @foreach($openReports as $r)<tr><td><a href="{{ route('lostfound.report.show', $r) }}">{{ $r->report_no }}</a></td><td>{{ $r->name }}<div class="small muted">{{ $r->item }}</div></td>
      <td class="right"><form method="post" action="{{ route('lostfound.report.match', [$r, $i]) }}">@csrf<button class="btn btn-sm btn-primary">Match</button></form></td></tr>@endforeach
  </tbody></table></div></div>
  @endif
</div>
</div>

@if($i->isStored())
<div class="modal" id="returnModal"><div class="modal-box" style="max-width:640px"><form method="post" action="{{ route('lostfound.return', $i) }}">@csrf
  <div class="modal-head"><h3>Return {{ $i->item_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    @if($i->valuable)<div class="alert alert-warn"><x-icon name="lock"/><div>Valuable – ask the claimant to describe it first, check photo ID{{ can_do('lostfound.release') ? '' : ' and call a supervisor to release it' }}.</div></div>@endif
    <div class="form-grid">
    <x-field name="returned_to" label="Returned to" required :value="$i->report?->name ?? $i->guest?->full_name"/>
    <x-field name="claimant_phone" label="Phone" :value="$i->report?->phone ?? $i->guest?->phone"/>
    <x-field name="claimant_id_type" label="Photo ID" type="select" :options="\App\Services\LostFoundService::ID_TYPES" placeholder="— not checked —"/>
    <x-field name="claimant_id_no" label="ID number"/>
    <x-field name="return_method" label="How" type="select" :options="\App\Models\LostFoundItem::RETURN_METHODS" value="in_person" :placeholder="false"/>
    <x-field name="courier" label="Courier"/>
    <x-field name="tracking_no" label="Tracking / AWB"/>
    <x-field name="courier_charge" label="Courier charge (collected)" type="number" step="0.01" min="0"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" id="confirmReturn">Return item</button></div>
</form></div></div>

<div class="modal" id="disposeModal"><div class="modal-box"><form method="post" action="{{ route('lostfound.dispose', $i) }}">@csrf
  <div class="modal-head"><h3>Dispose of {{ $i->item_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    @unless($i->isDue())<div class="alert alert-warn"><x-icon name="clock"/><div>Retention runs until {{ $i->retain_until?->format('d M Y') }} – only a police hand-over is possible before then.</div></div>@endunless
    <x-field name="disposal_method" label="Method" type="select" :options="\App\Models\LostFoundItem::DISPOSAL" :value="$i->category === 'documents' || ! $i->isDue() ? 'police' : 'donated'" :placeholder="false"/>
    <x-field name="disposal_note" label="Note" placeholder="e.g. donated to the Red Cross, police receipt no."/>
    @if($i->valuable)
      <div class="alert alert-info" style="margin-top:.6rem"><x-icon name="users"/><div>A second member of staff must witness the disposal of a valuable.</div></div>
      <div class="form-grid"><x-field name="witness_username" label="Witness username" required/><x-field name="witness_password" label="Witness password" type="password" required autocomplete="off"/></div>
    @endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmDispose">Dispose</button></div>
</form></div></div>

<div class="modal" id="editModal"><div class="modal-box"><form method="post" action="{{ route('lostfound.update', $i) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>Edit {{ $i->item_no }}</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="item_name" label="Item" required :value="$i->item_name"/>
    <x-field name="category" label="Category" type="select" :options="$cats" :value="$i->category" :placeholder="false"/>
    <x-field name="description" label="Description" type="textarea" rows="2" class="span-all" :value="$i->description"/>
    <x-field name="est_value" label="Estimated value" type="number" step="0.01" :value="$i->est_value"/>
    <x-field name="storage_location" label="Stored in" :value="$i->storage_location"/>
    <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="valuable" value="1" @checked($i->valuable)> Valuable</label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
