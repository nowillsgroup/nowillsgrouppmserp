@extends('layouts.app')
@section('title', 'Credit notes & cancellations')
@section('crumbs') Billing / GRA VAT / Credit notes @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'credit_notes') }}"><x-icon name="chart"/> Credit note register</a>@endif
@endsection
@section('content')
@include('gst._nav')
<div class="alert alert-info"><x-icon name="alert"/><div class="small">To reduce an invoice after it was issued (rate correction, complaint, wrong charge, post-sale discount) open the invoice and choose <b>Issue credit note</b>.
  Invoices issued today that have not been stamped by GRA E-VAT can simply be re-opened or cancelled; once stamped, only a credit note (E-VAT refund referencing the invoice) is possible.</div></div>
<div class="card flush">
  <div class="card-head"><h3><x-icon name="swap"/> Credit notes</h3>
  <form class="filters" style="margin:0"><select name="status" onchange="this.form.submit()"><option value="">All</option><option value="closed" @selected(request('status') === 'closed')>Issued</option><option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option></select>
    <input name="q" value="{{ request('q') }}" placeholder="Note / invoice no or buyer"></form></div>
  <div class="card-body table-wrap"><table class="table" id="cnTable">
    <thead><tr><th>Credit note</th><th>Date</th><th>Against invoice</th><th>Buyer</th><th>Reason</th><th class="num">Taxable</th><th class="num">Tax</th><th class="num">Value</th><th>Settled</th><th>Status</th></tr></thead><tbody>
    @forelse($notes as $n) @php $t = $n->gstTotals(); $set = $n->transactions()->whereIn('type', ['payment', 'refund'])->first(); @endphp
    <tr><td class="nowrap"><a class="bold" href="{{ route('folios.show', $n) }}">{{ $n->invoice_no }}</a></td><td class="small nowrap">{{ $n->invoice_date?->format('d M Y') }}</td>
      <td class="small">@if($n->original)<a href="{{ route('folios.show', $n->original) }}">{{ $n->original->invoice_no }}</a><div class="tiny muted">{{ $n->original->invoice_date?->format('d M Y') }}</div>@endif</td>
      <td class="small">{{ $n->buyer_name ?? $n->guest?->full_name }}@if($n->buyer_gstin)<div class="tiny muted mono">{{ $n->buyer_gstin }}</div>@endif</td>
      <td class="small">{{ \App\Models\Folio::NOTE_REASONS[$n->note_reason] ?? $n->note_reason }}@if($n->note_remarks)<div class="tiny muted">{{ \Illuminate\Support\Str::limit($n->note_remarks, 60) }}</div>@endif</td>
      <td class="num">{{ money(abs($t['taxable']), false) }}</td><td class="num">{{ money(abs($t['tax']), false) }}</td><td class="num bold">{{ money(abs($t['value']), false) }}</td>
      <td class="small">{{ $set ? ($set->type === 'refund' ? 'Refund' : 'Company account') : '—' }}</td>
      <td class="nowrap">{!! $n->status === 'cancelled' ? '<span class="badge badge-slate">cancelled</span>' : '<span class="badge badge-green">issued</span>' !!} {!! $n->irnBadge() !!}</td></tr>
    @empty<tr><td colspan="10"><x-empty text="No credit notes." icon="swap"/></td></tr>@endforelse
    </tbody></table></div>
  @if($notes->hasPages())<div class="card-foot">{{ $notes->links() }}</div>@endif
</div>

<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="x"/> Cancelled document numbers</h3><span class="tiny muted">kept for the numbering audit trail · numbers are never reused</span></div>
  <div class="card-body table-wrap"><table class="table" id="cxTable">
  <thead><tr><th>Number</th><th>Type</th><th>Dated</th><th>Buyer TIN</th><th class="num">Value</th><th>E-VAT</th><th>Reason</th><th>Cancelled</th></tr></thead><tbody>
  @forelse($cancelled as $c)<tr><td class="bold">@if($c->folio_id)<a href="{{ route('folios.show', $c->folio_id) }}">{{ $c->doc_no }}</a>@else{{ $c->doc_no }}@endif</td><td class="small">{{ $c->doc_type === 'invoice' ? 'Invoice' : 'Credit note' }}</td>
    <td class="small">{{ $c->doc_date->format('d M Y') }}</td><td class="small mono">{{ $c->buyer_gstin ?? '—' }}</td><td class="num">{{ money($c->value, false) }}</td>
    <td class="small">{!! $c->irn ? '<span class="badge badge-slate">pre-v4.3 IRN</span>' : '—' !!}</td><td class="small">{{ \App\Models\GstCancelledDoc::REASONS[$c->reason_code] ?? $c->reason_code }}<div class="tiny muted">{{ $c->remarks }}</div></td>
    <td class="small">{{ $c->created_at->format('d M Y H:i') }}<div class="tiny muted">{{ $c->user?->name }}</div></td></tr>
  @empty<tr><td colspan="8"><x-empty text="No cancelled invoices or credit notes." icon="check"/></td></tr>@endforelse
  </tbody></table></div></div>
@endsection
