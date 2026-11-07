@extends('layouts.app')
@section('title', $rfq->rfq_no.' · '.$rfq->title)
@section('crumbs') <a href="{{ route('rfq.index') }}">RFQs</a> / {{ $rfq->rfq_no }} @endsection
@php
  $buyer = can_do('purchasing.rfq');
  $canAward = $buyer && in_array($rfq->status, ['sent', 'closed']) && $cmp['quotes']->count();
  $quotes = $cmp['quotes'];
  $awarded = $rfq->status === 'awarded';
@endphp
@section('actions')
{!! status_badge($rfq->status) !!}
<a class="btn" href="{{ route('rfq.print', $rfq) }}?autoprint=1" target="_blank"><x-icon name="print"/> RFQ</a>
@if($quotes->count())<a class="btn" href="{{ route('rfq.comparative', $rfq) }}?autoprint=1" target="_blank" id="cmpPrint"><x-icon name="print"/> Comparative</a>@endif
@if($buyer)
  @if($rfq->status === 'draft')<a class="btn" href="{{ route('rfq.edit', $rfq) }}"><x-icon name="edit"/> Edit</a>
    <form method="post" action="{{ route('rfq.send', $rfq) }}">@csrf<button class="btn btn-primary" id="rfqSend"><x-icon name="mail"/> Send to suppliers</button></form>@endif
  @if($rfq->status === 'sent')<form method="post" action="{{ route('rfq.close', $rfq) }}" data-confirm="Stop accepting quotations?">@csrf<button class="btn" id="rfqClose">Close quotations</button></form>@endif
  @if(in_array($rfq->status, $openStatuses))<button class="btn btn-danger" data-modal="rfqCancel">Cancel</button>@endif
@endif
@endsection
@section('content')

{{-- v9.28 the request in five figures: what came in, what the best of it costs, what the lines cost last time, and what cannot be taken --}}
<div class="stats wide">
  <x-stat label="Quotes in" :value="$head['quotes']" icon="message" :tone="$head['quotes'] >= $minQuotes ? 'green' : 'amber'" :sub="$head['sub'].($head['quotes'] < $minQuotes ? ' — below the '.$minQuotes.' this property asks for' : '')" href="#comparative"/>
  <x-stat label="Lowest landed" :value="money($head['low'])" icon="money" tone="blue"
    :sub="$head['lowest'] ? $head['lowest']->supplier?->name.' — the whole request at the lowest rate offered line by line' : 'no bid to compare'" href="#comparative"/>
  <x-stat label="Last paid" :value="money($head['at_stake'])" icon="receipt" tone="slate"
    :sub="$head['saving'] > 0 ? 'these lines at the rates last paid — the cheapest bid saves '.money($head['saving']) : 'these lines at the rates last paid, and no bid beats them'"/>
  <x-stat label="Cannot be taken" :value="$head['late'] + $head['expired']" icon="alert" :tone="$head['blocked_tone']"
    :sub="$head['blocked_sub']" href="#rfqPromise"/>
  <x-stat label="Needed by" :value="dmy($rfq->needed_by)" icon="clock" :tone="$head['needed_tone']" :sub="$head['needed_sub']"/>
</div>

<div class="card"><div class="card-body grid g-3">
  <dl class="kv"><dt>Store</dt><dd>{{ $rfq->store->name }}</dd><dt>Raised</dt><dd>{{ $rfq->rfq_date->format('d M Y') }} · {{ $rfq->creator?->name }}</dd></dl>
  <dl class="kv"><dt>Quotes due</dt><dd>{{ $rfq->respond_by->format('D d M Y') }}@if($rfq->status === 'sent') {!! $rfq->deadlinePassed() ? '<span class="badge badge-amber">passed</span>' : '<span class="badge badge-blue">open</span>' !!}@endif</dd><dt>Needed by</dt><dd>{{ dmy($rfq->needed_by) }}</dd></dl>
  <dl class="kv"><dt>Quotes</dt><dd id="rfqQuoteCount">{{ $quotes->count() }} of {{ $rfq->invites->count() }} invited</dd>@if($awarded)<dt>Awarded</dt><dd>{{ $rfq->awarder?->name }} · {{ $rfq->awarded_at?->format('d M Y') }}</dd>@endif</dl>
</div>@if($rfq->cancel_reason)<div class="card-body"><span class="text-red">Cancelled: {{ $rfq->cancel_reason }}</span></div>@endif</div>

<div class="card" id="rfqInvites"><div class="card-head"><h3>Suppliers invited</h3>
  @if($buyer && in_array($rfq->status, ['draft', 'sent']) && $others->count())<form method="post" action="{{ route('rfq.supplier', $rfq) }}" class="flex gap-1">@csrf<select name="supplier_id" class="input input-sm" required><option value="">Add supplier…</option>@foreach($others as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select><button class="btn btn-sm">Add</button></form>@endif
</div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Supplier</th><th>Status</th><th>E-mail</th><th class="num">Quote</th><th>Quote link</th><th></th></tr></thead><tbody>
@foreach($rfq->invites as $inv)@php $q = $quotes->firstWhere('supplier_id', $inv->supplier_id); @endphp
<tr data-invite="{{ $inv->supplier_id }}"><td class="bold">{{ $inv->supplier->name }}<div class="tiny muted">{{ $inv->supplier->email ?: 'no e-mail' }} · {{ $inv->supplier->phone }}</div></td>
  <td>{!! status_badge($inv->state()) !!}@if($inv->declined_at)<div class="tiny muted">{{ $inv->decline_reason }}</div>@endif @if($inv->viewed_at && ! $inv->responded_at)<div class="tiny muted">opened {{ $inv->viewed_at->format('d M H:i') }}</div>@endif</td>
  <td class="small">{{ $inv->email_status ?? '—' }}</td>
  <td class="num">@if($q){{ money($q->total, false) }}<div class="tiny muted">{{ $q->source === 'portal' ? 'online' : 'keyed in' }}@if($q->revision > 1) · rev {{ $q->revision }}@endif</div>@else — @endif</td>
  <td>@if($rfq->status !== 'draft')<input class="input input-sm mono" style="max-width:260px" readonly value="{{ $inv->link() }}" onclick="this.select()" data-link>@else<span class="tiny muted">after sending</span>@endif</td>
  <td class="right nowrap">@if($buyer && in_array($rfq->status, ['sent', 'closed']))<a class="btn btn-sm" href="{{ route('rfq.quote', [$rfq, $inv]) }}" data-enter-quote>{{ $q ? 'Edit quote' : 'Enter quote' }}</a>@endif
    @if($buyer && $rfq->status === 'sent' && $inv->supplier->email)<form method="post" action="{{ route('rfq.resend', [$rfq, $inv]) }}" style="display:inline">@csrf<button class="btn btn-sm btn-ghost" title="E-mail again"><x-icon name="mail" size="sm"/></button></form>@endif
    <a class="btn btn-sm btn-ghost" href="{{ route('rfq.print', $rfq) }}?supplier={{ $inv->supplier_id }}&autoprint=1" target="_blank" title="Print for this supplier"><x-icon name="print" size="sm"/></a></td></tr>
@endforeach
</tbody></table></div></div>

@if($quotes->count())
<form method="post" action="{{ route('rfq.award', $rfq) }}" id="awardForm" data-confirm="Award and raise draft purchase orders?">@csrf
<div class="card" id="comparative"><div class="card-head"><h3>Comparative statement</h3><span class="small muted">Landed rate = rate incl. tax · <span class="cmp-low-key">lowest</span> = L1 for the full quantity · total at L1 {{ money($cmp['lowTotal']) }}</span></div>
<div class="card-body table-wrap"><table class="table table-compact cmp">
<thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Last rate</th>@foreach($quotes as $q)<th class="num">{{ $q->supplier->name }}</th>@endforeach @if($canAward)<th>Award / justification</th>@endif</tr></thead><tbody>
@foreach($cmp['rows'] as $r)@php $line = $r['line']; @endphp
<tr data-line="{{ $line->id }}"><td class="bold">{{ $line->item->name }}@if($line->specification)<div class="tiny muted">{{ $line->specification }}</div>@endif</td><td class="num">{{ (float) $line->qty }} {{ $line->item->unit }}</td><td class="num muted">{{ $r['last'] !== null ? money($r['last'], false) : '—' }}</td>
  @foreach($quotes as $q)@php $c = $r['cells'][$q->id]; $isLow = $c && $c['ql']->id === $r['lowest']; $isWon = $awarded && $c && $line->awarded_quote_line_id === $c['ql']->id; @endphp
  <td class="num {{ $isLow ? 'cmp-low' : '' }} {{ $isWon ? 'cmp-won' : '' }}" @if($isLow) data-lowest @endif>
    @if($c)
      @if($canAward)<label class="check" style="justify-content:flex-end"><input type="radio" name="award[{{ $line->id }}]" value="{{ $c['ql']->id }}" @checked((int) old('award.'.$line->id, $r['lowest']) === $c['ql']->id)> <b>{{ money($c['ql']->rate, false) }}</b></label>@else<b>{{ money($c['ql']->rate, false) }}</b>@endif
      <div class="tiny muted">{{ (float) $c['ql']->tax_rate }}% → {{ number_format($c['landed'], 2) }}@if(! $c['full']) · <span class="text-amber">only {{ $c['qty'] }}</span>@endif</div>
      @if($c['ql']->remarks)<div class="tiny">{{ $c['ql']->remarks }}</div>@endif
      @if($isWon)<div class="tiny text-green bold">awarded</div>@endif
    @else <span class="muted">no bid</span> @endif
  </td>@endforeach
  @if($canAward)<td style="min-width:220px"><label class="check tiny"><input type="radio" name="award[{{ $line->id }}]" value="" @checked(old('award.'.$line->id) === '')> <span>do not award</span></label>
    <input class="input input-sm" name="reason[{{ $line->id }}]" value="{{ old('reason.'.$line->id) }}" placeholder="Justification if not L1" maxlength="255"></td>@endif
</tr>
@if($awarded && $line->award_reason)<tr><td colspan="{{ 3 + $quotes->count() }}" class="tiny muted">↳ {{ $line->item->name }} not awarded to L1: {{ $line->award_reason }}</td></tr>@endif
@endforeach
</tbody><tfoot>
<tr class="bold"><td colspan="3" class="right">Quote total (incl. tax)</td>@foreach($quotes as $q)<td class="num">{{ money($q->total, false) }}</td>@endforeach @if($canAward)<td></td>@endif</tr>
<tr><td colspan="3" class="right small">Items quoted</td>@foreach($quotes as $q)<td class="num small">{{ $q->lines->count() }} / {{ $rfq->lines->count() }}</td>@endforeach @if($canAward)<td></td>@endif</tr>
<tr><td colspan="3" class="right small">Delivery (days)</td>@foreach($quotes as $q)<td class="num small">{{ $q->delivery_days ?? '—' }}</td>@endforeach @if($canAward)<td></td>@endif</tr>
<tr><td colspan="3" class="right small">Payment terms (days)</td>@foreach($quotes as $q)<td class="num small">{{ $q->payment_terms_days ?? '—' }}</td>@endforeach @if($canAward)<td></td>@endif</tr>
<tr><td colspan="3" class="right small">Valid until</td>@foreach($quotes as $q)<td class="num small">{{ dmy($q->valid_until) }}</td>@endforeach @if($canAward)<td></td>@endif</tr>
<tr><td colspan="3" class="right small">Supplier score (12 months)</td>@foreach($quotes as $q)@php $sc = $cmp['scores'][$q->supplier_id] ?? null; @endphp<td class="num small">@if($sc && $sc['score'] !== null)<b>{{ $sc['grade'] }}</b> {{ $sc['score'] }}@else — @endif</td>@endforeach @if($canAward)<td></td>@endif</tr>
</tfoot></table></div>
@if($canAward)
<div class="card-body" style="border-top:1px solid var(--c-border)">
  @if($quotes->count() < $minQuotes)<div class="alert alert-warn" id="fewQuotes"><x-icon name="alert"/><div>Only {{ $quotes->count() }} quotation(s) received – policy is {{ $minQuotes }}. Record why the award is going ahead.</div></div>@endif
  <div class="form-grid"><x-field name="note" label="Award note / recommendation" class="span-2" :required="$quotes->count() < $minQuotes"/></div>
  <label class="check small mt-1"><input type="checkbox" name="update_prices" value="1" checked> <span>Add the awarded rates to the supplier price lists (valid until the quote expiry)</span></label>
  <div class="mt-1"><button class="btn btn-success" id="awardBtn" ><x-icon name="check"/> Award &amp; raise purchase orders</button></div>
</div>
@endif
</div></form>
@elseif($rfq->status !== 'draft')
<div class="card"><div class="card-body"><x-empty text="No quotations yet. Suppliers quote through their private link; paper or e-mailed quotes can be keyed in with “Enter quote”." icon="mail"/></div></div>
@endif

@if($promise->count())
{{-- v9.28 the same three suppliers read on the axes the price table cannot show: when it lands, on what terms, and for how long the price holds --}}
<div class="card" id="rfqPromise">
  <div class="card-head"><h3>What each supplier is promising</h3>
    <span class="badge badge-{{ $head['tone'] }}">{{ $head['late'] + $head['expired'] ? 'not every bid can be taken' : 'every bid can be taken' }}</span></div>
  <div class="card-body">
    <div class="table-wrap"><table class="table table-compact" id="promiseTable">
      <thead><tr><th>Supplier</th><th class="num">Quote</th><th class="num">Lines</th><th class="num">Delivery</th><th>Arrives</th><th class="num">Terms</th><th>Valid until</th><th class="num">Score</th></tr></thead><tbody>
      @foreach($promise as $p)<tr data-supplier="{{ $p['supplier'] }}" data-late="{{ $p['late'] ? 'yes' : 'no' }}" data-expired="{{ $p['expired'] ? 'yes' : 'no' }}">
        <td class="bold">{{ $p['supplier'] }}</td>
        <td class="num">{{ money($p['total'], false) }}</td>
        <td class="num">{{ $p['lines'] }} / {{ $p['of'] }}@if($p['partial']) <span class="badge badge-amber">part</span>@endif</td>
        <td class="num">{{ $p['delivery_days'] !== null ? $p['delivery_days'].' days' : '—' }}</td>
        <td class="small {{ $p['late'] ? 'text-red bold' : '' }}">{{ $p['arrives'] ? $p['arrives']->format('d M Y') : '—' }}@if($p['late']) <span class="badge badge-red">{{ $p['late_days'] }} days late</span>@endif</td>
        <td class="num">{{ $p['terms_days'] !== null ? $p['terms_days'].' days' : '—' }}</td>
        <td class="small {{ $p['expired'] ? 'text-red bold' : '' }}">{{ dmy($p['valid_until']) }}@if($p['expired']) <span class="badge badge-red">expired</span>@elseif($p['expires_in'] !== null && $p['expires_in'] <= 3) <span class="badge badge-amber">expires in {{ $p['expires_in'] }} day{{ $p['expires_in'] === 1 ? '' : 's' }}</span>@endif</td>
        <td class="num">@if($p['score'] !== null)<b>{{ $p['grade'] }}</b> {{ $p['score'] }}@else — @endif</td></tr>@endforeach
      </tbody></table></div>
    <p class="small" id="rfqPromiseState" style="margin:.6rem 0 0"><x-icon name="info"/> {{ $head['verdict'] }}</p>
    <p class="small muted" style="margin:.3rem 0 0">
      Delivery is counted from today, {{ dmy(bdate()) }}, not from the date the quote was given, because that is when the
      order would be placed. A quote whose validity has run out is not a cheap quote any more — it is a starting point for
      a phone call, and the award screen will still let it be chosen, which is why it is marked here.
    </p>
  </div>
</div>
@endif

{{-- v9.28 where this document sits --}}
<div class="card" id="rfqChain"><div class="card-body small">
  <x-icon name="link"/> The comparative statement decides who raises the purchase order, and everything after it — the
  order, the goods receipt, the supplier bill — inherits that choice. Price is the axis the table ranks on because it is
  the only one that can be compared line by line; delivery, terms and the life of the quote are the axes that decide
  whether the cheapest line can actually be taken, and they are the ones a buyer is held to when the goods do not arrive.
</div></div>

@if($awarded)
<div class="card" id="rfqPos"><div class="card-head"><h3>Purchase orders raised</h3></div><div class="card-body table-wrap"><table class="table table-compact"><tbody>
@foreach($rfq->purchaseOrders as $po)<tr><td><a class="bold" href="{{ route('purchases.show', $po) }}">{{ $po->po_no }}</a></td><td>{{ $po->supplier->name }}</td><td class="num">{{ money($po->total) }}</td><td>{!! status_badge($po->status) !!}</td></tr>@endforeach
</tbody></table>@if($rfq->award_note)<p class="small mt-1"><b>Award note:</b> {{ $rfq->award_note }}</p>@endif</div></div>
@endif
@if($rfq->terms)<div class="card"><div class="card-head"><h3>Terms sent to suppliers</h3></div><div class="card-body small" style="white-space:pre-line">{{ $rfq->terms }}</div></div>@endif

<div class="modal" id="rfqCancel"><div class="modal-box"><form method="post" action="{{ route('rfq.cancel', $rfq) }}">@csrf
<div class="modal-head"><h3>Cancel {{ $rfq->rfq_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><x-field name="reason" label="Reason" required/></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Cancel RFQ</button></div></form></div></div>
@endsection
@push('head')<style>.cmp td.cmp-low{background:#ecfdf5}.cmp td.cmp-won{outline:2px solid #059669;outline-offset:-2px}.cmp-low-key{background:#ecfdf5;padding:0 .35rem;border-radius:4px}</style>@endpush
