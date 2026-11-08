@extends('layouts.app')
@section('title', 'Agent Commissions')
@section('crumbs') Accounts / Agent Commissions @endsection
@section('content')
@php $companyId = request()->integer('company_id'); $payable = $companyId && $status === 'due' && can_do('accounts.manage') && $rows->count(); @endphp

<div class="stats">
  <x-stat label="Commission due" :value="money($aged['due'])" icon="wallet" tone="amber" :sub="'as at '.dmy($aged['as_of']).' · '.$aged['stays'].' unsettled stays'"/>
  <x-stat label="Over 90 days" :value="money($aged['b90'])" icon="alert" :tone="$aged['b90'] > 0 ? 'red' : 'green'" :sub="$aged['nb90'].' commission'.($aged['nb90'] === 1 ? '' : 's').' older than three months'"/>
  <x-stat label="Agents with dues" :value="$aged['agents']" icon="building" tone="blue" sub="each with its own commission rate"/>
  <x-stat label="Oldest due" :value="$aged['oldest'].' days'" icon="clock" :tone="$aged['oldest'] > 90 ? 'red' : 'slate'" sub="from check-out to the day being read"/>
</div>

{{-- v9.12 a commission marked paid must name the voucher that paid it: the register checks its own documents rather than
     asking anyone to trust the status column --}}
<div class="card" id="commissionTie"><div class="card-head"><h3>Is every paid commission on a voucher?</h3>
  <span class="muted small">{{ $doc['paid'] }} paid · {{ $doc['documented'] }} on a voucher · {{ $doc['missing'] }} without one</span></div>
  <div class="card-body"><div class="stats" style="margin:0">
    <x-stat label="Marked paid" :value="money($doc['paid_amount'])" icon="check" tone="slate" :sub="$doc['paid'].' commission'.($doc['paid'] === 1 ? '' : 's').' settled'" />
    <x-stat label="On a voucher" :value="$doc['documented']" icon="receipt" tone="slate" sub="each one names the payment that cleared it"/>
    <x-stat label="Without one" :value="$doc['missing']" icon="alert" :tone="$doc['missing'] > 0 ? 'red' : 'green'" :sub="$doc['missing'] > 0 ? 'settled with no document behind it' : 'nothing is paid without a document'"/>
  </div>
  @if($doc['missing'] === 0 && $doc['stray'] === 0)
    <p class="small" id="commissionTieState" style="margin:.6rem 0 0"><x-icon name="check"/>Every one of the {{ $doc['paid'] }} paid commissions names its voucher, and no commission that is
      still due or void carries one. The status column can be read as it stands.</p>
  @else
    <p class="small text-red" id="commissionTieState" style="margin:.6rem 0 0"><x-icon name="alert"/>@if($doc['missing'] > 0){{ $doc['missing'] }} commission{{ $doc['missing'] === 1 ? ' is' : 's are' }} marked paid with no voucher:
      @foreach($doc['missing_rows'] as $d){{ $d['company'] }} {{ money($d['amount'], false) }} @if(! $loop->last), @endif @endforeach — the money left the bank but nothing here records it. @endif
      @if($doc['stray'] > 0) {{ $doc['stray'] }} commission{{ $doc['stray'] === 1 ? '' : 's' }} that {{ $doc['stray'] === 1 ? 'is' : 'are' }} not paid {{ $doc['stray'] === 1 ? 'carries' : 'carry' }} a voucher anyway. @endif
      Reconcile these against the voucher register before the agents' statement goes out.</p>
  @endif
  </div>
</div>

@if($summary->isNotEmpty())
<div class="card flush" id="cmSummaryCard"><div class="card-head"><h3>Due by agent</h3>
  <span class="muted small">accrued at check-out on the room revenue charged × the agent's commission %, aged to {{ dmy($aged['as_of']) }}</span></div>
<div class="card-body table-wrap"><table class="table table-compact" id="cmSummary">
  <thead><tr><th>Agent / company</th><th class="num">Commission %</th><th class="num">Stays</th><th class="num">Room revenue</th>
    @foreach($buckets as $bk => $bl)<th class="num">{{ $bl }}</th>@endforeach
    <th class="num">Due</th><th class="num">Oldest</th><th></th></tr></thead><tbody>
  @foreach($summary as $s)<tr data-agent="{{ $s['company']->id }}">
    <td class="bold">{{ $s['company']->name }}</td><td class="num">{{ (float) $s['company']->commission_pct }}%</td><td class="num">{{ $s['stays'] }}</td>
    <td class="num">{{ money($s['revenue'], false) }}</td>
    @foreach(array_keys($buckets) as $bk)<td class="num {{ $bk !== 'b0' && $s[$bk] > 0 ? 'text-red' : '' }}">{{ $s[$bk] ? money($s[$bk], false) : '—' }}</td>@endforeach
    <td class="num bold">{{ money($s['amount'], false) }}</td><td class="num muted">{{ $s['oldest'] }} d</td>
    <td class="right"><a class="btn btn-sm btn-primary" href="{{ route('accounts.commissions', ['company_id' => $s['company']->id, 'status' => 'due']) }}">Settle</a></td></tr>@endforeach
  </tbody><tfoot><tr class="bold"><td colspan="3">Total — {{ $aged['agents'] }} agents</td><td class="num">{{ money($summary->sum('revenue'), false) }}</td>
    @foreach(array_keys($buckets) as $bk)<td class="num" id="cmSum{{ $bk }}">{{ money($aged[$bk], false) }}</td>@endforeach
    <td class="num" id="cmSumDue">{{ money($aged['due'], false) }}</td><td class="num">{{ $aged['oldest'] }} d</td><td></td></tr></tfoot></table></div></div>
@endif

<form method="get" class="filters card"><div class="card-body flex gap-1 wrap items-end">
  <x-field name="company_id" label="Agent" type="select" :options="$companies" :value="$companyId ?: ''" placeholder="All agents"/>
  <x-field name="status" label="Status" type="select" :options="['due' => 'Due', 'paid' => 'Paid', 'void' => 'Void', 'all' => 'All']" :value="$status"/>
  <div class="field"><label>As at</label><input type="date" name="as_of" value="{{ $asOf }}" max="{{ bdate()->toDateString() }}" class="input"></div>
  <button class="btn">Filter</button>
</div></form>

<form method="post" action="{{ route('accounts.commissions.pay') }}" id="payForm" @if($payable) data-confirm="Create a payment voucher for the selected commissions?" @endif>@csrf
<input type="hidden" name="company_id" value="{{ $companyId }}">
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="cmRows">
<thead><tr>@if($payable)<th style="width:28px"><input type="checkbox" checked onclick="document.querySelectorAll('.cm-chk').forEach(c => { c.checked = this.checked; }); cmTotal()"></th>@endif<th>Check-out</th><th class="num">Age</th><th>Reservation</th><th>Guest / room</th><th>Agent</th><th class="num">Nights</th><th class="num">Room revenue</th><th class="num">%</th><th class="num">Commission</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($rows as $c)<tr data-status="{{ $c->status }}" data-bucket="{{ $c->bucket }}">
  @if($payable)<td><input type="checkbox" class="cm-chk" name="ids[]" value="{{ $c->id }}" data-amt="{{ $c->amount }}" checked onchange="cmTotal()"></td>@endif
  <td>{{ $c->business_date->format('d M Y') }}</td>
  <td class="num {{ $c->status === 'due' && $c->days > 90 ? 'text-red' : 'muted' }}">{{ $c->days }} d<div class="tiny muted">{{ $buckets[$c->bucket] }}</div></td>
  <td><a href="{{ route('reservations.show', $c->reservation_id) }}">{{ $c->reservation->reservation_no }}</a></td>
  <td>{{ $c->reservation->guest?->full_name }} <span class="tiny muted">{{ $c->stay?->room ? 'Rm '.$c->stay->room->room_no : '' }}</span></td><td>{{ $c->company->name }}</td>
  <td class="num">{{ $c->nights }}</td><td class="num">{{ money($c->room_revenue, false) }}</td><td class="num">{{ (float) $c->pct }}</td><td class="num bold">{{ money($c->amount, false) }}</td>
  <td>{!! status_badge($c->status) !!} @if($c->voucher)<div class="tiny muted">{{ $c->voucher->voucher_no }} · {{ $c->paid_at?->format('d M') }}</div>@endif</td>
  <td class="right">@if($c->status === 'due' && can_do('accounts.manage'))<button type="button" class="btn btn-sm btn-ghost" data-modal="voidCm" data-action="{{ route('accounts.commissions.void', $c) }}" title="Void"><x-icon name="x" size="sm"/></button>@endif</td>
</tr>@empty<tr><td colspan="12"><x-empty text="No commissions for this filter." icon="wallet"/></td></tr>@endforelse
</tbody></table></div></div>
@if($payable)
<div class="card"><div class="card-body flex gap-1 wrap items-end">
  <div><div class="small muted">Selected</div><b style="font-size:1.3rem" id="cmTotal">{{ money($rows->sum('amount')) }}</b></div>
  <x-field name="payment_mode_id" label="Paid by" type="select" :options="$paymentModes" required/>
  <x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/>
  <x-field name="reference" label="Reference / cheque no."/>
  <button class="btn btn-primary"><x-icon name="money"/> Pay selected</button>
</div></div>
@elseif($status === 'due' && $rows->count() && can_do('accounts.manage'))
<p class="small muted">To record a payment, choose an agent above or click <b>Settle</b>.</p>
@endif
</form>
{{ $rows->links() }}

<p class="small muted">A commission accrues when the guest checks out: the room revenue charged on the stay × the agent's own rate, and it stays on the books until
it is settled by a payment voucher. The buckets are days since that check-out, on the same 30/60/90 rule the receivables ageing uses, so an agent
that leaves a commission for a quarter shows it here before it shows up anywhere else. The settlement itself is a voucher like any other, which is why
every paid commission names one.</p>

<div class="modal" id="voidCm"><div class="modal-box"><form method="post">@csrf
  <div class="modal-head"><h3>Void commission</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Void</button></div>
</form></div></div>
@endsection
@push('scripts')
<script>
function cmTotal() { let t = 0; document.querySelectorAll('.cm-chk:checked').forEach(c => t += +c.dataset.amt); const el = document.getElementById('cmTotal'); if (el) el.textContent = @json(hotel()->currency_symbol ?? '') + t.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
</script>
@endpush
