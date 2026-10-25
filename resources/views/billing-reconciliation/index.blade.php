@extends('layouts.app')
@section('title', 'Billing Reconciliation')
@section('crumbs') Billing / Billing reconciliation @endsection
@section('content')
<div class="alert alert-info"><b>Non-posting workpaper.</b> This first release covers issued folio invoices and credit notes only. It compares the document snapshot with receipts/refunds recorded on the folio and keeps company-account transfers separate from cash. Finance records settlement evidence manually; nothing here creates or changes a journal entry.</div>

@if(can_do('billingrec.manage'))
<div class="card mb-2"><div class="card-head"><h3>Start a document workpaper</h3></div><div class="card-body">
  <form method="post" action="{{ route('billingrec.store') }}" class="filters" id="billingReconCreate">@csrf
    <div class="field"><label for="billingReconFrom">Invoice date from</label><input type="date" id="billingReconFrom" name="period_from" value="{{ old('period_from', $defaultFrom) }}" required></div>
    <div class="field"><label for="billingReconTo">Invoice date to</label><input type="date" id="billingReconTo" name="period_to" value="{{ old('period_to', $defaultTo) }}" required></div>
    <button class="btn btn-primary">Create / open workpaper</button>
  </form>
  <p class="tiny muted mt-1">A period may be up to 367 calendar days. The same date range opens its existing workpaper instead of creating a duplicate.</p>
</div></div>
@endif

<div class="card flush">
  <div class="card-head"><h3>Workpapers</h3><span class="tiny muted">Most recent period first</span></div>
  <div class="card-body table-wrap"><table class="table" id="billingReconTable">
    <thead><tr><th>Workpaper</th><th>Invoice-date period</th><th>Status</th><th>Documents</th><th>Review</th><th>Prepared by</th><th>Finalised by</th></tr></thead>
    <tbody>
    @forelse($reconciliations as $r)
    <tr>
      <td><a class="bold" href="{{ route('billingrec.show', $r) }}">{{ $r->reconciliation_no }}</a></td>
      <td>{{ $r->label() }}</td>
      <td>{!! status_badge($r->status) !!}</td>
      <td>{{ $r->items_count }}</td>
      <td>@if($r->pending_count)<span class="badge badge-amber">{{ $r->pending_count }} pending</span>@else<span class="badge badge-green">review complete</span>@endif</td>
      <td>{{ $r->preparer?->name ?? '—' }}</td>
      <td>{{ $r->reviewer?->name ?? '—' }} @if($r->finalised_at)<div class="tiny muted">{{ $r->finalised_at->format('d M Y H:i') }}</div>@endif</td>
    </tr>
    @empty<tr><td colspan="7"><x-empty text="No billing reconciliation workpapers yet." icon="receipt"/></td></tr>@endforelse
    </tbody>
  </table></div>
  @if($reconciliations->hasPages())<div class="card-body">{{ $reconciliations->links() }}</div>@endif
</div>
@endsection
