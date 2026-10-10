@extends('layouts.app')
@section('title', 'Revenue Authorisations')
@section('crumbs') <a href="{{ route('audit.index') }}">Income Audit</a> / Authorisations @endsection
@php
  $stTone = ['pending' => 'amber', 'approved' => 'green', 'declined' => 'red', 'cancelled' => 'slate'];
  $canDecide = can_do('income_audit.authorise') || can_do('income_audit.close');
  $stLabel = ['checked_in' => 'in house', 'reserved' => 'arriving', 'checked_out' => 'departed'];
@endphp
@section('content')
<div class="stats">
  <x-stat label="Waiting for approval" :value="$counts['pending'] ?? 0" icon="clock" :tone="($counts['pending'] ?? 0) ? 'amber' : 'slate'" sub="another manager must decide"/>
  <x-stat label="Approved" :value="$counts['approved'] ?? 0" icon="check" tone="green" sub="accepted automatically by the audit"/>
  <x-stat label="Declined" :value="$counts['declined'] ?? 0" icon="x" tone="red"/>
  <x-stat label="Manager limit" :value="money($limit)" icon="shield" tone="blue" :sub="$compNights.' comp night(s) – above: GM / controller'"/>
</div>

@if(can_do('income_audit.request'))
<div class="card mt-1" id="requestCard"><div class="card-head"><h3><x-icon name="plus"/> Request an authorisation</h3><span class="small muted">get approval before giving revenue away – the income audit flags anything unapproved</span></div><div class="card-body">
  <form method="post" action="{{ route('audit.auth.store') }}" id="authForm">@csrf<div class="grid g-3" style="gap:.2rem 1rem">
    <div class="field"><label for="a_kind">What <span class="req">*</span></label><select name="kind" id="a_kind" required>@foreach(\App\Models\RevenueAuthorisation::KINDS as $k => $v)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="field span-2" data-for="comp house_use rate"><label for="a_stay">Stay <span class="req">*</span></label><select name="reservation_room_id" id="a_stay"><option value="">— Select the room / guest —</option>
      @foreach($stays as $s)<option value="{{ $s->id }}" data-rate="{{ $s->rate }}" @selected((string) old('reservation_room_id') === (string) $s->id)>Room {{ $s->room?->room_no ?? '—' }} · {{ $s->guest?->full_name }} · {{ $s->reservation?->reservation_no }} · {{ dmy($s->arrival_date, 'd M') }}–{{ dmy($s->departure_date, 'd M') }} · {{ money($s->rate) }} · {{ $stLabel[$s->status] ?? $s->status }}</option>@endforeach</select></div>
    <div class="field span-2" data-for="discount" hidden><label for="a_folio">Folio <span class="req">*</span></label><select name="folio_id" id="a_folio"><option value="">— Select the open folio —</option>
      @foreach($folios as $f)<option value="{{ $f->id }}" @selected((string) old('folio_id') === (string) $f->id)>{{ $f->folio_no }} · {{ $f->guest?->full_name ?? $f->name }}{{ $f->stay?->room ? ' · Room '.$f->stay->room->room_no : '' }}</option>@endforeach</select></div>
    <div class="field" data-for="comp house_use rate"><label for="a_from">First night</label><input type="date" name="date_from" id="a_from" value="{{ old('date_from') }}"><span class="hint">blank = tonight / arrival</span></div>
    <div class="field" data-for="comp house_use rate"><label for="a_to">Last night</label><input type="date" name="date_to" id="a_to" value="{{ old('date_to') }}"><span class="hint">blank = the night before departure</span></div>
    <div class="field" data-for="rate discount" hidden><label for="a_amount" id="a_amount_l">Amount (GH₵) <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" id="a_amount" value="{{ old('amount') }}"></div>
    <div class="field span-3"><label for="a_reason">Reason <span class="req">*</span></label><input name="reason" id="a_reason" required maxlength="255" value="{{ old('reason') }}" placeholder="e.g. Service recovery – air-conditioning failed, GM agreed one night complimentary"></div>
  </div><button class="btn btn-primary mt-1" id="authSubmit">Send for approval</button></form>
</div></div>
@endif

<form class="filters mt-1" method="get"><div class="field"><label>Status</label><select name="status" onchange="this.form.submit()">@foreach(['pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Withdrawn', 'all' => 'All'] as $k => $v)<option value="{{ $k }}" @selected($show === $k)>{{ $v }}</option>@endforeach</select></div>
  <div class="field"><label>Type</label><select name="kind" onchange="this.form.submit()"><option value="">All types</option>@foreach(\App\Models\RevenueAuthorisation::KINDS as $k => $v)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $v }}</option>@endforeach</select></div></form>

<div class="card flush" id="authList"><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>No.</th><th>Type</th><th>For</th><th>Nights</th><th class="num">Rate / amount</th><th class="num">Given away</th><th>Reason</th><th>Requested</th><th>Decision</th><th></th></tr></thead>
  <tbody>@forelse($rows as $a) @php $senior = $a->status === 'pending' && \App\Services\RevenueAuthService::needsSenior($a); @endphp
    <tr data-auth="{{ $a->auth_no }}"><td class="bold">{{ $a->auth_no }}</td>
      <td class="small">{{ \App\Models\RevenueAuthorisation::KINDS[$a->kind] }}</td>
      <td class="small">@if($a->stay)<a href="{{ route('reservations.show', $a->stay->reservation_id) }}">{{ $a->subject() }}</a>@elseif($a->folio)<a href="{{ route('folios.show', $a->folio) }}">{{ $a->subject() }}</a>@endif</td>
      <td class="small">{{ $a->date_from ? dmy($a->date_from, 'd M').($a->nights() > 1 ? ' – '.dmy($a->date_to, 'd M') : '').' ('.$a->nights().')' : '' }}</td>
      <td class="num">{{ $a->kind === 'discount' ? money($a->amount, false) : ($a->kind === 'rate' ? money($a->amount, false) : 'free') }}@if($a->previous_rate !== null)<div class="tiny muted">was {{ money($a->previous_rate, false) }}</div>@endif</td>
      <td class="num">{{ money(\App\Services\RevenueAuthService::value($a), false) }}@if($senior)<div><span class="badge badge-purple" title="Above the manager limit">GM</span></div>@endif</td>
      <td class="small" style="max-width:18rem">{{ $a->reason }}</td>
      <td class="small">{{ $a->requester?->name }}<div class="tiny muted">{{ $a->created_at->format('d M H:i') }}</div></td>
      <td class="small"><span class="badge badge-{{ $stTone[$a->status] }}">{{ $a->status === 'cancelled' ? 'withdrawn' : $a->status }}</span>@if($a->decider && $a->status !== 'cancelled')<div class="tiny">{{ $a->decider->name }} · {{ $a->decided_at?->format('d M H:i') }}</div>@endif @if($a->decision_note)<div class="tiny muted">{{ $a->decision_note }}</div>@endif
        @if($a->transaction)<div class="tiny muted">posted to {{ $a->folio?->folio_no }}</div>@endif @if($a->restored_at)<div class="tiny muted">rate put back</div>@elseif($a->applied_at)<div class="tiny muted">rate applied</div>@endif</td>
      <td class="num" style="white-space:nowrap">@if($a->status === 'pending')
        @if($canDecide && $a->requested_by !== auth()->id())<button class="btn btn-sm btn-primary" data-modal="approveModal" data-title="Approve {{ $a->auth_no }}" data-action="{{ route('audit.auth.approve', $a) }}">Approve</button>
          <button class="btn btn-sm" data-modal="declineModal" data-title="Decline {{ $a->auth_no }}" data-action="{{ route('audit.auth.decline', $a) }}">Decline</button>@endif
        @if($a->requested_by === auth()->id() || $canDecide)<form method="post" action="{{ route('audit.auth.cancel', $a) }}" style="display:inline">@csrf<button class="btn btn-sm btn-ghost" title="Withdraw">✕</button></form>@endif
      @endif</td></tr>
  @empty <tr><td colspan="10"><x-empty text="No authorisations." icon="check"/></td></tr> @endforelse</tbody>
</table></div></div>
<div class="mt-1">{{ $rows->links() }}</div>

<div class="modal" id="approveModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3>Approve</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">Approving applies it: a discount is posted to the folio; a comp / house-use / rate changes the stay's rate for the authorised nights and is put back by the night audit afterwards.</p><x-field name="note" label="Note (optional)"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="approveSave">Approve</button></div>
</form></div></div>
<div class="modal" id="declineModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3>Decline</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="note" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="declineSave">Decline</button></div>
</form></div></div>
@endsection
@push('scripts')
<script>
(function () {
  var kind = document.getElementById('a_kind'); if (!kind) return;
  function sync() {
    document.querySelectorAll('#authForm [data-for]').forEach(function (el) { el.hidden = el.dataset.for.split(' ').indexOf(kind.value) < 0; });
    var l = document.getElementById('a_amount_l'); if (l) l.firstChild.nodeValue = kind.value === 'rate' ? 'Approved nightly rate (GH₵) ' : 'Discount amount (GH₵) ';
  }
  kind.addEventListener('change', sync); sync();
})();
</script>
@endpush
