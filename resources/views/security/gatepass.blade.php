@extends('layouts.app')
@section('title', 'Gate pass '.$p->pass_no)
@section('crumbs') Security / <a href="{{ route('security.gp.index') }}">Gate passes</a> / {{ $p->pass_no }} @endsection
@section('actions')
  @if(! in_array($p->status, ['pending', 'rejected', 'cancelled']))<a class="btn" href="{{ route('security.gp.print', $p) }}" target="_blank" id="gpPrintBtn"><x-icon name="print"/> Print pass</a>@endif
  @if($p->status === 'pending' && can_do('security.approve'))
    <form method="post" action="{{ route('security.gp.approve', $p) }}" class="inline">@csrf<button class="btn btn-success" id="gpApproveBtn"><x-icon name="check"/> Approve</button></form>
    <button class="btn btn-danger" data-modal="gpReasonModal" data-action="{{ route('security.gp.reject', $p) }}" data-title="Reject {{ $p->pass_no }}" id="gpRejectBtn">Reject</button>
  @endif
  @if(in_array($p->status, ['pending', 'approved']) && (can_do('security.approve') || $p->requested_by === auth()->id()))
    <button class="btn" data-modal="gpReasonModal" data-action="{{ route('security.gp.cancel', $p) }}" data-title="Cancel {{ $p->pass_no }}" id="gpCancelBtn">Cancel pass</button>
  @endif
@endsection
@section('content')
@if($p->isOverdue())<div class="alert alert-error" id="gpOverdue"><x-icon name="alert"/><div><b>Overdue by {{ $p->daysOverdue() }} day(s).</b> The goods were due back on {{ $p->expected_return_date->format('D d M Y') }}. Chase {{ $p->party_name }}{{ $p->party_phone ? ' ('.$p->party_phone.')' : '' }}, move the date, or close the pass with a note.</div></div>@endif
<div class="grid g-2-1" style="gap:1.2rem">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $p->pass_no }} <span class="badge badge-{{ $p->statusTone() }}" id="gpStatus">{{ $p->statusLabel() }}</span></h3><span class="small muted">{{ \App\Models\GatePass::TYPES[$p->type] }} · {{ \App\Models\GatePass::PURPOSES[$p->purpose] ?? $p->purpose }}</span></div>
      <div class="card-body"><dl class="kv">
        <dt>Going to</dt><dd><b>{{ $p->party_name }}</b>{{ $p->party_phone ? ' · '.$p->party_phone : '' }}</dd>
        <dt>Department</dt><dd>{{ $p->department?->name ?? '–' }}</dd>
        <dt>Carried by</dt><dd>{{ $p->carrier_name ?? '–' }}{{ $p->vehicle_no ? ' · '.$p->vehicle_no : '' }}</dd>
        @if($p->type === 'returnable')<dt>Expected back</dt><dd>{{ $p->expected_return_date?->format('D d M Y') }}</dd>@endif
        <dt>Reason</dt><dd>{{ $p->reason ?? '–' }}</dd>
        @if($p->reject_reason)<dt>Rejected</dt><dd class="text-red">{{ $p->reject_reason }}</dd>@endif
        @if($p->close_note)<dt>Closing note</dt><dd>{{ $p->close_note }}</dd>@endif
      </dl></div></div>

    <div class="card flush mt-2"><div class="card-head"><h3>Items</h3></div>
      <form method="post" action="{{ route('security.gp.receive', $p) }}" id="receiveForm">@csrf
      <div class="table-wrap"><table class="table" id="gpItemTable"><thead><tr><th>Item</th><th class="right">Qty out</th><th class="right">Returned</th><th class="right">Still out</th>@if($p->type === 'returnable' && in_array($p->status, \App\Models\GatePass::AWAY) && can_do('security.gate'))<th class="right" style="width:120px">Receiving now</th>@endif</tr></thead><tbody>
      @foreach($p->items as $it)
        <tr data-item="{{ $it->id }}"><td>{{ $it->description }}@if($it->asset)<div class="tiny"><a href="{{ route('assets.show', $it->asset) }}">{{ $it->asset->asset_no }}</a> · {{ \App\Models\Asset::STATUSES[$it->asset->status] ?? $it->asset->status }}</div>@endif @if($it->est_value)<div class="tiny muted">est. {{ money($it->est_value) }}</div>@endif</td>
          <td class="right">{{ rtrim(rtrim(number_format((float) $it->quantity, 2), '0'), '.') }} {{ $it->unit }}</td>
          <td class="right">{{ rtrim(rtrim(number_format((float) $it->returned_qty, 2), '0'), '.') }}</td>
          <td class="right {{ $it->outstanding() > 0 && in_array($p->status, \App\Models\GatePass::AWAY) ? 'text-amber' : '' }}">{{ $p->type === 'returnable' ? rtrim(rtrim(number_format($it->outstanding(), 2), '0'), '.') : '–' }}</td>
          @if($p->type === 'returnable' && in_array($p->status, \App\Models\GatePass::AWAY) && can_do('security.gate'))<td class="right">@if($it->outstanding() > 0)<input type="number" name="qty[{{ $it->id }}]" class="input right" step="0.01" min="0" max="{{ $it->outstanding() }}" style="width:100px">@endif</td>@endif</tr>
      @endforeach
      </tbody></table></div>
      @if($p->type === 'returnable' && in_array($p->status, \App\Models\GatePass::AWAY) && can_do('security.gate'))
        <div class="card-body flex gap-1 wrap items-end"><div class="field" style="margin:0;flex:1"><label>Brought back by</label><input name="carrier_name" class="input" maxlength="120"></div>
          <div class="field" style="margin:0;flex:2"><label>Note</label><input name="note" class="input" maxlength="255" placeholder="Repaired – new compressor fitted"></div>
          <button class="btn btn-primary" type="submit" id="receiveBtn"><x-icon name="login"/> Receive</button></div>
      @endif
      </form>
    </div>

    @if($p->returns->isNotEmpty())
    <div class="card flush mt-2"><div class="card-head"><h3>Returns</h3></div><div class="table-wrap"><table class="table" id="gpReturns"><thead><tr><th>When</th><th>Received by</th><th>Items</th><th>Note</th></tr></thead><tbody>
      @foreach($p->returns as $r)<tr><td class="nowrap">{{ $r->returned_at->format('d M Y H:i') }}</td><td>{{ $r->receiver?->name }}{{ $r->carrier_name ? ' · from '.$r->carrier_name : '' }}</td>
        <td class="small">{{ collect($r->lines)->map(fn ($l) => ($l['qty'] + 0).' × '.($p->items->firstWhere('id', $l['item_id'])?->description ?? '?'))->join(', ') }}</td><td class="small">{{ $r->note }}</td></tr>@endforeach
    </tbody></table></div></div>
    @endif
  </div>

  <div>
    @if($p->status === 'approved' && can_do('security.gate'))
    <div class="card" id="gateOutCard"><div class="card-head"><h3><x-icon name="logout"/> At the gate</h3></div><div class="card-body">
      <form method="post" action="{{ route('security.gp.out', $p) }}">@csrf
        <p class="small">Check every item against the list, then let the goods out.</p>
        <x-field name="carrier_name" label="Carried by" :value="$p->carrier_name" maxlength="120"/>
        <x-field name="vehicle_no" label="Vehicle no." :value="$p->vehicle_no" maxlength="20"/>
        <button class="btn btn-primary btn-block" type="submit" id="gateOutBtn">Goods checked – let out</button>
      </form></div></div>
    @endif
    @if(can_do('security.approve') && $p->type === 'returnable' && in_array($p->status, ['approved', 'out', 'partial']))
    <div class="card mt-2"><div class="card-head"><h3>Return date</h3></div><div class="card-body">
      <form method="post" action="{{ route('security.gp.extend', $p) }}" class="flex gap-1 items-end" id="gpExtendForm">@csrf<div class="field" style="margin:0;flex:1"><label>New date</label><input type="date" name="date" class="input" min="{{ bdate()->toDateString() }}" value="{{ $p->expected_return_date?->toDateString() }}" required></div><button class="btn" type="submit">Move</button></form>
      @if(in_array($p->status, \App\Models\GatePass::AWAY))<button class="btn btn-block mt-1" data-modal="gpCloseModal" id="gpCloseBtn">Close without full return…</button>@endif
    </div></div>
    @endif
    <div class="card mt-2"><div class="card-head"><h3>Trail</h3></div><div class="card-body"><ul class="list small" id="gpTrail">
      <li><span>Raised by <b>{{ $p->requester?->name ?? '–' }}</b></span><span>{{ $p->created_at->format('d M H:i') }}</span></li>
      @if($p->approved_at)<li><span>{{ $p->status === 'rejected' ? 'Rejected' : 'Approved' }} by <b>{{ $p->approver?->name }}</b></span><span>{{ $p->approved_at->format('d M H:i') }}</span></li>@endif
      @if($p->out_at)<li><span>Out through the gate – <b>{{ $p->outUser?->name }}</b></span><span>{{ $p->out_at->format('d M H:i') }}</span></li>@endif
      @if($p->closed_at)<li><span>{{ $p->status === 'returned' ? 'All returned' : ucfirst($p->status) }}{{ $p->closer ? ' – '.$p->closer->name : '' }}</span><span>{{ $p->closed_at->format('d M H:i') }}</span></li>@endif
    </ul></div></div>
  </div>
</div>

<div class="modal" id="gpReasonModal"><div class="modal-box" style="max-width:480px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Reason</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-danger">Confirm</button></div>
</form></div></div>
<div class="modal" id="gpCloseModal"><div class="modal-box" style="max-width:480px"><form method="post" action="{{ route('security.gp.close', $p) }}">@csrf
  <div class="modal-head"><h3>Close {{ $p->pass_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">Use this when the rest will not come back (scrapped at the vendor, replaced under warranty, written off). Remember to update the asset register.</p><x-field name="note" label="Why" required maxlength="255"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button type="submit" class="btn btn-danger">Close pass</button></div>
</form></div></div>
@endsection
