@extends('layouts.app')
@section('title', 'RFQs & Quotations')
@section('actions')@if(can_do('purchasing.rfq'))<a class="btn btn-primary" href="{{ route('rfq.create') }}" id="newRfq"><x-icon name="plus"/> New RFQ</a>@endif @endsection
@section('content')

{{-- v9.27 the register of the only document where a price is decided by competition --}}
<div class="stats wide">
  <x-stat label="Out for quotation" :value="$response['requests'] ?? 0" icon="mail" tone="blue"
    :sub="$response['invited'].' suppliers invited across them'" href="#rfqResponse"/>
  <x-stat label="Quotes back" :value="$response['quoted']" icon="message" tone="green" :sub="$response['sub']" href="#rfqResponse"/>
  <x-stat label="Past the deadline" :value="$clock['ready']" icon="clock" :tone="$clock['ready'] ? 'amber' : 'green'"
    :sub="$clock['sub']" href="#rfqClock"/>
  <x-stat label="Short of the minimum" :value="$clock['short']" icon="alert" :tone="$clock['short'] ? 'red' : 'green'"
    :sub="$clock['short'] ? 'waiting on an award with fewer quotes than the '.$clock['min'].' this property asks for' : 'every request waiting on an award has the '.$clock['min'].' quotes it needs'"/>
  <x-stat label="Saved on awards" :value="money($award['saved'])" icon="trend" :tone="$award['tone']" :sub="$award['sub']" href="#rfqAward"/>
</div>

{{-- what came back, and who is still silent --}}
<div class="card" id="rfqResponse">
  <div class="card-head"><h3>What came back</h3>
    <span class="muted small">{{ $response['quoted'] }} quotes · {{ $response['declined'] }} declined · {{ $response['silent'] + $response['ignored'] }} never answered</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Invited" :value="$response['invited']" icon="users" tone="blue" :sub="'across '.$response['requests'].' requests still asking for a price'"/>
      <x-stat label="Answered" :value="$response['quoted']" icon="message" tone="green" :sub="$response['sub']"/>
      <x-stat label="Declined" :value="$response['declined']" icon="x" :tone="$response['declined'] ? 'amber' : 'green'"
        :sub="$response['declined'] ? 'the supplier said no — that is an answer, and the reason is on their invitation' : 'no supplier has turned a request down'"/>
      <x-stat label="Opened, silent" :value="$response['silent']" icon="eye" :tone="$response['silent'] ? 'amber' : 'green'"
        :sub="$response['silent'] ? $response['silent'].' read the request and never quoted — worth a phone call before the deadline' : 'everyone who opened a request answered it'"/>
    </div>
    @if($response['rows'])
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="silentTable">
      <thead><tr><th>Request</th><th>Supplier</th><th>Opened</th><th>Quotes due</th><th></th></tr></thead><tbody>
      @foreach($response['rows'] as $r)<tr data-rfq="{{ $r['rfq_no'] }}">
        <td class="bold">{{ $r['rfq_no'] }}</td><td>{{ $r['supplier'] }}</td>
        <td class="small">{{ $r['viewed'] ? $r['viewed']->format('d M Y H:i') : '—' }}</td>
        <td class="small">{{ dmy($r['deadline']) }}</td>
        <td class="right"><a class="btn btn-sm" href="{{ route('rfq.show', $r['rfq_id']) }}">Open</a></td></tr>@endforeach
      </tbody></table></div>
    @endif
    @if($response['declined_rows'])
    <p class="small" id="rfqDeclined" style="margin:.6rem 0 0"><x-icon name="info"/>
      @foreach($response['declined_rows'] as $d){{ $d['supplier'] }} declined {{ $d['rfq_no'] }}: {{ $d['reason'] ?: 'no reason given' }}{{ $loop->last ? '.' : ' · ' }}@endforeach
    </p>
    @endif
  </div>
</div>

{{-- the deadline ran out; somebody has to decide --}}
<div class="card" id="rfqClock">
  <div class="card-head"><h3>Waiting on an award decision</h3>
    <span class="muted small">{{ $clock['ready'] }} requests · oldest {{ $clock['oldest_days'] }} days past its deadline</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Waiting" :value="$clock['ready']" icon="clock" :tone="$clock['ready'] ? 'amber' : 'green'" :sub="$clock['sub']"/>
      <x-stat label="Oldest" :value="$clock['oldest_days'] ? $clock['oldest_days'].' days' : 'none'" icon="alert" :tone="$clock['oldest_days'] ? 'red' : 'green'"
        :sub="$clock['oldest'] ? $clock['oldest']->rfq_no.' — '.$clock['oldest']->title.' · quotes were due '.dmy($clock['oldest']->respond_by) : 'nothing has been left waiting'"/>
      <x-stat label="Below the minimum" :value="$clock['short']" icon="alert" :tone="$clock['short'] ? 'red' : 'green'"
        :sub="'this property asks for '.$clock['min'].' quotes before an award — the award can be made, but it has to be justified'"/>
      <x-stat label="Closing in 2 days" :value="$clock['closing']" icon="mail" tone="slate"
        :sub="$clock['closing'] ? 'still taking quotes, deadline within two days' : 'no request closes in the next two days'"/>
    </div>
    @if($clock['rows'])
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="clockTable">
      <thead><tr><th>Request</th><th>Title</th><th class="num">Quotes</th><th>Quotes due</th><th class="num">Days</th><th></th></tr></thead><tbody>
      @foreach($clock['rows'] as $r)<tr data-rfq="{{ $r['rfq_no'] }}" data-short="{{ $r['short'] ? 'yes' : 'no' }}">
        <td class="bold">{{ $r['rfq_no'] }}</td><td>{{ $r['title'] }}</td>
        <td class="num">{{ $r['quotes'] }} / {{ $r['invites'] }}@if($r['short']) <span class="badge badge-red">short</span>@endif</td>
        <td class="small">{{ dmy($r['deadline']) }}</td>
        <td class="num {{ $r['days'] > 5 ? 'text-red bold' : '' }}">{{ $r['days'] }}</td>
        <td class="right"><a class="btn btn-sm" href="{{ route('rfq.show', $r['id']) }}">Compare &amp; award</a></td></tr>@endforeach
      </tbody></table></div>
    @endif
  </div>
</div>

{{-- what the competition actually bought --}}
<div class="card" id="rfqAward">
  <div class="card-head"><h3>What the competition bought</h3>
    <span class="muted small">{{ $award['count'] }} award{{ $award['count'] === 1 ? '' : 's' }} this month · {{ money($award['placed']) }} placed</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Placed" :value="money($award['placed'])" icon="receipt" tone="blue" :sub="'the value of every line awarded in '.now()->format('F')"/>
      <x-stat label="Cheapest bid" :value="money($award['cheapest'])" icon="trend" tone="green" :sub="'what the same lines would have cost at the lowest rate offered for each'"/>
      <x-stat label="Dearest bid" :value="money($award['dearest'])" icon="trend" tone="slate" :sub="'what the same lines would have cost at the highest rate offered for each'"/>
      <x-stat label="Away from the lowest" :value="$award['away']" icon="alert" :tone="$award['justified'] < $award['away'] ? 'red' : ($award['away'] ? 'amber' : 'green')"
        :sub="$award['away_sub']"/>
    </div>
    <p class="small" id="rfqAwardState" style="margin:.6rem 0 0"><x-icon name="info"/>
      An award that takes the lowest bid needs no explanation; an award that does not is a decision somebody has to
      defend, which is why the field beside each line on the award screen records it. This month
      {{ $award['count'] }} award{{ $award['count'] === 1 ? '' : 's' }} came {{ money($award['saved']) }} under the dearest bid
      and {{ money($award['extra']) }} over the cheapest — the difference between buying well and buying cheaply.
    </p>
    @if($award['rows'])
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="awardTable">
      <thead><tr><th>Request</th><th>Title</th><th class="num">Quotes</th><th class="num">Placed</th><th class="num">Lowest</th><th class="num">Saved</th><th class="num">Away</th></tr></thead><tbody>
      @foreach($award['rows'] as $r)<tr data-rfq="{{ $r['rfq_no'] }}">
        <td class="bold">{{ $r['rfq_no'] }}</td><td>{{ $r['title'] }}</td>
        <td class="num">{{ $r['quotes'] }}</td><td class="num">{{ money($r['placed'], false) }}</td>
        <td class="num muted">{{ money($r['cheapest'], false) }}</td><td class="num">{{ money($r['saved'], false) }}</td>
        <td class="num {{ $r['away'] ? 'text-amber' : 'muted' }}">{{ $r['away'] ?: '—' }}</td></tr>@endforeach
      </tbody><tfoot><tr><td colspan="3" class="right bold">Total</td><td class="num bold">{{ money($award['placed'], false) }}</td>
        <td class="num bold">{{ money($award['cheapest'], false) }}</td><td class="num bold">{{ money($award['saved'], false) }}</td>
        <td class="num bold">{{ $award['away'] }}</td></tr></tfoot></table></div>
    @endif
  </div>
</div>

@if($ready->count())
<div class="alert alert-info" id="rfqReady"><x-icon name="bell"/><div><b>Waiting for an award decision:</b>
  @foreach($ready as $r)<a href="{{ route('rfq.show', $r) }}">{{ $r->rfq_no }}</a> ({{ $r->quotes_count }} quote{{ $r->quotes_count === 1 ? '' : 's' }}){{ $loop->last ? '' : ', ' }}@endforeach</div></div>
@endif
<div class="tabs">@foreach(['' => 'All', 'open' => 'Open', 'draft' => 'Draft', 'sent' => 'Sent', 'closed' => 'Closed', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled'] as $k => $v)<a href="?status={{ $k }}" class="{{ (string) $status === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="rfqTable">
<thead><tr><th>RFQ</th><th>Title</th><th>Store</th><th>Quote by</th><th>Needed by</th><th class="num">Items</th><th class="num">Quotes</th><th>Status</th><th>By</th></tr></thead><tbody>
@forelse($rfqs as $r)<tr>
  <td><a class="bold" href="{{ route('rfq.show', $r) }}">{{ $r->rfq_no }}</a><div class="tiny muted">{{ $r->rfq_date->format('d M Y') }}</div></td><td>{{ $r->title }}</td><td>{{ $r->store->name }}</td>
  <td>{{ $r->respond_by->format('d M Y') }}@if($r->status === 'sent' && $r->deadlinePassed()) <span class="badge badge-amber">passed</span>@endif</td><td>{{ dmy($r->needed_by) }}</td>
  <td class="num">{{ $r->lines_count }}</td><td class="num">{{ $r->quotes_count }} / {{ $r->invites_count }}</td><td>{!! status_badge($r->status) !!}</td><td class="small">{{ $r->creator?->name }}</td></tr>
@empty<tr><td colspan="9"><x-empty text="No requests for quotation yet." icon="mail"/></td></tr>@endforelse
</tbody></table>{{ $rfqs->links() }}</div></div>

{{-- v9.27 where this document sits in the chain --}}
<div class="card" id="rfqChain"><div class="card-body small">
  <x-icon name="link"/> A request for quotation is the only document in the chain where the price is not given but
  argued for: the requisition says what is needed, three suppliers say what it costs, and the award decides which of
  them raises the purchase order. Everything downstream — the order, the goods receipt, the bill — inherits that choice,
  which is why the award that did not take the lowest bid is the one that needs a reason on the record.
</div></div>
@endsection
