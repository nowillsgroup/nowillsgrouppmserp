@extends('layouts.app')
@section('title', 'Credit – '.$co->name)
@section('crumbs') <a href="{{ route('credit.index') }}">Credit Control</a> / {{ $co->name }} @endsection
@section('actions')
@if(can_do('accounts.view'))<a class="btn" href="{{ route('accounts.company', $co) }}"><x-icon name="book"/> Ledger &amp; receipts</a>@endif
@if(can_do('credit.manage'))
  <form method="post" action="{{ route('credit.statement', $co) }}" class="inline">@csrf<button class="btn" id="statementBtn" @disabled(! $co->email) title="{{ $co->email ?: 'No e-mail address on file' }}"><x-icon name="mail"/> E-mail statement</button></form>
  @if($co->onHold())<button class="btn btn-primary" data-modal="releaseModal" id="releaseBtn"><x-icon name="check"/> Release hold</button>
  @else<button class="btn btn-danger" data-modal="holdModal" id="holdBtn"><x-icon name="lock"/> Put on hold</button>@endif
@endif
@endsection
@section('content')
@if($co->onHold())<div class="alert alert-error" id="holdNote"><x-icon name="lock"/><div><b>Credit hold{{ $co->credit_hold_auto ? ' (automatic)' : '' }}</b> since {{ $co->credit_hold_at?->format('d M Y H:i') }} – {{ $co->credit_hold_reason }}. City-ledger billing needs a credit override with a reason.</div></div>
@elseif($p['over_limit'])<div class="alert alert-warn"><x-icon name="alert"/><div><b>Over the credit limit</b> by {{ money(-$p['available']) }}. Further billing needs a credit override.</div></div>
@elseif(! $co->direct_billing)<div class="alert alert-info"><x-icon name="alert"/><div>Not approved for direct billing – guests settle their own bills.</div></div>@endif
<div class="stats req5">
  <x-stat label="Exposure" :value="money($p['exposure'])" icon="wallet" tone="blue" :sub="$p['limit'] > 0 ? $p['used_pct'].'% of '.money($p['limit']) : 'no limit set'"/>
  <x-stat label="Available" :value="$p['available'] !== null ? money($p['available']) : '—'" icon="check" :tone="($p['available'] ?? 1) < 0 ? 'red' : 'green'" sub="limit − exposure"/>
  <x-stat label="City ledger" :value="money($p['outstanding'])" icon="building" tone="purple" :sub="money($p['billed']).' billed · '.money($p['received']).' received'"/>
  <x-stat label="Overdue" :value="money($p['overdue'])" icon="clock" :tone="$p['overdue'] > 0 ? 'red' : 'green'" :sub="$p['overdue'] > 0 ? 'oldest '.$p['overdue_days'].' day(s) past due' : 'nothing past due'"/>
  <x-stat label="In-house to bill" :value="money($p['pending'])" icon="bed" tone="slate" :sub="$inhouse->count().' open folio(s)'"/>
</div>
<div class="grid g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Open bills</h3><span class="tiny muted">after receipts (oldest first)</span></div><div class="card-body table-wrap"><table class="table" id="openBills">
      <thead><tr><th>Bill date</th><th>Due</th><th class="num">Age</th><th class="num">Open amount</th><th>Status</th></tr></thead><tbody>
      @forelse($p['open_bills'] as $b)
        <tr><td>{{ $b['date']->format('d M Y') }}</td><td>{{ $b['due']->format('d M Y') }}</td><td class="num">{{ $b['age'] }} d</td><td class="num">{{ money($b['amount']) }}</td>
          <td>@if($b['overdue_days'] > 0)<span class="badge badge-red">{{ $b['overdue_days'] }} d overdue</span>@else<span class="badge badge-green">Not due</span>@endif</td></tr>
      @empty<tr><td colspan="5"><x-empty text="No unpaid bills." icon="check"/></td></tr>@endforelse
      </tbody></table></div></div>
    @if($inhouse->isNotEmpty())
    <div class="card flush"><div class="card-head"><h3>In-house – will be billed</h3></div><div class="card-body table-wrap"><table class="table" id="inhouseFolios">
      <thead><tr><th>Folio</th><th>Guest</th><th>Room</th><th class="num">Balance</th></tr></thead><tbody>
      @foreach($inhouse as $f)<tr><td>@if(can_do('folio.view'))<a href="{{ route('folios.show', $f) }}">{{ $f->folio_no }}</a>@else{{ $f->folio_no }}@endif</td><td>{{ $f->guest?->full_name }}</td><td>{{ $f->stay?->room?->room_no ?? '—' }}</td><td class="num">{{ money($f->balance()) }}</td></tr>@endforeach
      </tbody></table></div></div>
    @endif
    <div class="card flush"><div class="card-head"><h3>Credit log</h3></div><div class="card-body table-wrap"><table class="table" id="creditLog">
      <thead><tr><th>When</th><th>Action</th><th>Detail</th><th class="num">Amount</th><th>By</th></tr></thead><tbody>
      @forelse($logs as $l)
        <tr><td class="small nowrap">{{ $l->created_at->format('d M Y H:i') }}</td><td>{!! status_badge(['hold' => 'on_hold', 'release' => 'released', 'override' => 'acknowledged', 'terms' => 'approved', 'statement' => 'sent'][$l->action] ?? $l->action) !!}<div class="tiny muted">{{ $l->action }}</div></td>
          <td class="small">{{ $l->detail }}@if($l->folio)<div class="tiny muted">Folio {{ $l->folio->folio_no }}</div>@endif</td><td class="num">{{ $l->amount !== null ? money($l->amount) : '' }}</td><td class="small">{{ $l->user?->name ?? 'System' }}</td></tr>
      @empty<tr><td colspan="5"><x-empty text="No credit events yet." icon="clock"/></td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Account</h3></div><div class="card-body"><dl class="kv">
      <dt>Type</dt><dd>{{ ucwords(str_replace('_', ' ', $co->type)) }}</dd>
      <dt>Contact</dt><dd>{{ $co->contact_person ?: '—' }}<div class="tiny muted">{{ $co->phone }} {{ $co->email }}</div></dd>
      <dt>Account manager</dt><dd>{{ $co->accountManager?->name ?? '—' }}</dd>
      <dt>Last statement</dt><dd>{{ $co->last_statement_at?->format('d M Y') ?? 'never' }}</dd>
    </dl></div></div>
    @if(can_do('credit.manage'))
    <form method="post" action="{{ route('credit.terms', $co) }}" class="card" id="termsForm">@csrf
      <div class="card-head"><h3>Credit terms</h3></div>
      <div class="card-body">
        <x-field name="credit_limit" label="Credit limit (0 = no limit)" type="number" step="0.01" min="0" :value="$co->credit_limit"/><div class="mt-1"></div>
        <x-field name="credit_days" label="Credit days" type="number" min="0" max="365" :value="$co->credit_days"/><div class="mt-1"></div>
        <label class="check"><input type="hidden" name="direct_billing" value="0"><input type="checkbox" name="direct_billing" value="1" @checked($co->direct_billing)> Approved for direct billing</label><div class="mt-1"></div>
        <x-field name="note" label="Note (logged)" placeholder="e.g. Revised after FY review"/>
      </div>
      <div class="card-foot"><button class="btn btn-primary btn-block">Save terms</button></div>
    </form>
    @else
    <div class="card"><div class="card-head"><h3>Credit terms</h3></div><div class="card-body"><dl class="kv"><dt>Limit</dt><dd>{{ $p['limit'] > 0 ? money($p['limit']) : 'none' }}</dd><dt>Credit days</dt><dd>{{ $co->credit_days }}</dd><dt>Direct billing</dt><dd>{{ $co->direct_billing ? 'Yes' : 'No' }}</dd></dl></div></div>
    @endif
    @if(can_do('contracts.view'))
    <div class="card"><div class="card-head"><h3>Rate contracts</h3>@if(can_do('contracts.manage'))<a class="btn btn-sm" href="{{ route('contracts.create', ['company_id' => $co->id]) }}"><x-icon name="plus"/> New</a>@endif</div><div class="card-body">
      @forelse($contracts as $k)<div class="flex between small mb-1"><a href="{{ route('contracts.show', $k) }}">{{ $k->code }}</a><span>{!! status_badge($k->state()) !!} <span class="tiny muted">to {{ $k->valid_to->format('d M Y') }}</span></span></div>
      @empty<p class="small muted">No contracts.</p>@endforelse
    </div></div>
    @endif
  </div>
</div>
@if(can_do('credit.manage'))
<div class="modal" id="holdModal"><div class="modal-box"><form method="post" action="{{ route('credit.hold', $co) }}">@csrf
  <div class="modal-head"><h3>Put {{ $co->name }} on credit hold</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">Check-outs and folio transfers to this company's city ledger will be refused unless a user with override rights gives a reason. Existing bills are not affected.</p><x-field name="reason" label="Reason" required placeholder="e.g. Cheque bounced – awaiting replacement"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Put on hold</button></div>
</form></div></div>
<div class="modal" id="releaseModal"><div class="modal-box"><form method="post" action="{{ route('credit.release', $co) }}">@csrf
  <div class="modal-head"><h3>Release credit hold</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="e.g. Payment received – UTR 12345"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Release</button></div>
</form></div></div>
@endif
@endsection
