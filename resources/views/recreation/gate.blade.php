@extends('layouts.app')
@section('title', 'Ticket Gate')
@section('crumbs') <a href="{{ route('recreation.outlet') }}">Recreation outlet</a> / Gate @endsection
@section('actions')
<a class="btn" href="{{ route('recreation.outlet') }}"><x-icon name="receipt"/> Back to the till</a>
<a class="btn" href="{{ route('recreation.shift') }}"><x-icon name="wallet"/> Shift</a>
<a class="btn" href="{{ route('recreation.day', array_filter(['date' => $board['date']])) }}"><x-icon name="chart"/> Day report</a>
@endsection
@section('content')
<div class="stats">
  <x-stat label="Tickets issued" :value="$board['issued']" icon="receipt" tone="blue" :sub="$board['pax_issued'].' guest(s)'"/>
  <x-stat label="Admitted" :value="$board['admitted']" icon="check" tone="green" :sub="$board['pax_admitted'].' guest(s) through the gate'"/>
  <x-stat label="Still to arrive" :value="$board['waiting']" icon="clock" tone="amber" :sub="'on '.dmy($board['date'])"/>
  <x-stat label="Cancelled" :value="$board['cancelled']" icon="x" tone="purple" sub="voided or refunded"/>
</div>

<div class="grid g-2-1">
  <div class="card" id="redeemCard">
    <div class="card-head"><h3>Admit a ticket</h3><span class="small muted">type or scan the code on the stub</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('recreation.gate.redeem') }}" class="form-grid" autocomplete="off">@csrf
        <div class="field span-all"><label for="code">Ticket code</label>
          <input id="code" name="code" class="input" style="font-size:20px;letter-spacing:2px;text-transform:uppercase" placeholder="DP-0091" maxlength="20" required autofocus value="{{ old('code') }}">
          <span class="hint">One code per stub. The second presentation of the same ticket is refused.</span>
        </div>
        <x-field name="pax" label="Guests arriving" type="number" placeholder="all on the pass"/>
        <x-field name="note" label="Note" placeholder="e.g. left with the children, will return"/>
        <div class="field span-all"><button class="btn btn-primary"><x-icon name="check"/> Admit</button></div>
      </form>
      <p class="tiny muted m-0 mt-1">A ticket is good only for the day it was sold, only while the sale stands, and only once.
        Refunds at the desk cancel the ticket first, so a cancelled code will not open the gate.</p>
    </div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Gate today</h3></div><div class="card-body">
      <div class="kv-grid">
        <div><span>Tickets sold</span><b>{{ $board['issued'] }}</b></div>
        <div><span>Admitted</span><b>{{ $board['admitted'] }}</b></div>
        <div><span>Guests expected</span><b>{{ $board['pax_issued'] }}</b></div>
        <div><span>Guests in</span><b>{{ $board['pax_admitted'] }}</b></div>
        <div><span>Ticket value</span><b>{{ money($board['revenue']) }}</b></div>
        <div><span>Sales today</span><b>{{ money($stats['total']) }}</b></div>
      </div>
      <div class="mt-1">
        <div class="tiny muted">Turnstile rate · {{ $board['turnstile'] }}% of today's tickets are in</div>
        <div style="height:8px;border-radius:999px;background:#eef2f1;overflow:hidden;margin-top:4px">
          <div style="height:100%;width:{{ min(100, $board['turnstile']) }}%;background:#166534"></div>
        </div>
      </div>
    </div></div>
  </div>
</div>

<div class="card flush mt-2">
  <div class="card-head"><h3>Tickets for {{ dmy($board['date']) }}</h3>
    <form method="get" class="flex gap-1 items-center">
      <input type="date" name="date" class="input input-sm" value="{{ $board['date'] }}" max="{{ bdate()->toDateString() }}">
      <button class="btn btn-sm">Show</button>
    </form>
  </div>
  <div class="card-body table-wrap"><table class="table table-compact" id="gateTable">
    <thead><tr><th>Ticket</th><th>Ticket type</th><th>Guest</th><th class="num">Pax</th><th>Sold</th><th>Gate</th></tr></thead>
    <tbody>
    @forelse($recent as $p)
      <tr class="{{ $p->cancelled_at ? 'muted' : '' }}">
        <td class="bold nowrap">{{ $p->pass_no }}</td>
        <td class="small">{{ $p->ticketType?->name ?? \App\Models\ClubDayPass::KINDS[$p->kind] ?? 'Day pass' }}</td>
        <td>{{ $p->name }}
          @if($p->host)<div class="tiny muted">guest of {{ $p->host->name }}</div>@endif
          @if($p->sale)<div class="tiny muted">{{ $p->sale->sale_no }}@if(! $p->sale->stay) · {{ $p->sale->payments->pluck('paymentMode.name')->filter()->implode(' + ') }}@else · room {{ $p->sale->stay->room?->room_no }}@endif</div>@endif
        </td>
        <td class="num">{{ $p->adults + $p->children }}</td>
        <td class="small">{{ money($p->total, false) }}</td>
        <td class="small">
          @if($p->cancelled_at)
            <span class="badge badge-red">Cancelled</span><div class="tiny muted">{{ $p->cancel_reason }}</div>
          @elseif($p->redeemed_at)
            <span class="badge badge-green">In {{ $p->redeemed_at->format('H:i') }}</span>
            <div class="tiny muted">{{ $p->redeemed_pax ?? $p->adults + $p->children }} pax{{ $p->redeemer ? ' · '.$p->redeemer->name : '' }}</div>
            @if(can_do('club.manage'))<button type="button" class="btn btn-sm btn-ghost" data-modal="reverseModal" data-title="Reverse the admission of {{ $p->pass_no }}" data-action="{{ route('recreation.gate.reverse', $p) }}">Reverse</button>@endif
          @else
            <span class="badge badge-amber">Not yet</span>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="6"><x-empty text="No tickets were sold for this date." icon="receipt"/></td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>

<div class="modal" id="reverseModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Reverse admission</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small">The ticket goes back to <b>not yet arrived</b> and can be admitted again. Use this when the wrong stub was let in,
      a visitor left immediately, or a sale has to be voided after the gate. The reversal is written to the activity log with your name.</p>
    <x-field name="reason" label="Reason" required placeholder="Wrong stub, visitor turned away, refund at the desk…"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Keep the admission</button><button class="btn btn-danger">Reverse admission</button></div>
</form></div></div>
@endsection
