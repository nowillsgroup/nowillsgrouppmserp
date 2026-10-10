@extends('layouts.app')
@section('title', 'RFQs & Quotations')
@section('actions')@if(can_do('purchasing.rfq'))<a class="btn btn-primary" href="{{ route('rfq.create') }}" id="newRfq"><x-icon name="plus"/> New RFQ</a>@endif @endsection
@section('content')
<div class="grid g-3">
  <x-stat label="Out for quotation" :value="$counts['sent'] ?? 0" icon="mail" tone="blue" href="?status=sent"/>
  <x-stat label="Ready to award" :value="$ready->count()" icon="check" tone="amber" sub="deadline passed / closed"/>
  <x-stat label="Awarded" :value="$counts['awarded'] ?? 0" icon="receipt" tone="green" href="?status=awarded"/>
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
@endsection
