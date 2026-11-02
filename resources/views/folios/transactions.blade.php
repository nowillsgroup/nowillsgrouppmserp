@extends('layouts.app')
@section('title', 'Folio transactions')
@section('actions')<a class="btn" href="{{ route('folios.index') }}">Folios</a>@endsection
@section('content')
<div class="card"><div class="card-body">
  <form class="filters" method="get" action="{{ route('folios.transactions') }}">
    <div class="field"><label for="transactionDate">Business date</label><input id="transactionDate" type="date" name="date" value="{{ $date }}" required></div>
    <div class="field"><label for="transactionType">Entry type</label><select id="transactionType" name="type"><option value="all" @selected($type === 'all')>All entry types</option><option value="charge" @selected($type === 'charge')>Charges</option><option value="discount" @selected($type === 'discount')>Discounts</option><option value="allowance" @selected($type === 'allowance')>Allowances</option><option value="payment" @selected($type === 'payment')>Payments</option><option value="refund" @selected($type === 'refund')>Refunds</option><option value="opening_balance" @selected($type === 'opening_balance')>Opening balances</option></select></div>
    <div class="field"><label for="transactionCategory">Category</label><select id="transactionCategory" name="category"><option value="">All categories</option>@foreach($categoryOptions as $key => $label)<option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>@endforeach</select></div>
    <button class="btn btn-primary">Apply</button>
  </form>
</div></div>
<div class="grid g-4">
  <div class="card"><div class="card-body"><div class="small muted">Matching entries · {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</div><strong>{{ number_format((int) $summary->entries) }}</strong></div></div>
  <div class="card"><div class="card-body"><div class="small muted">Net amount</div><strong>{{ money($summary->amount) }}</strong></div></div>
  <div class="card"><div class="card-body"><div class="small muted">Tax</div><strong>{{ money($summary->tax) }}</strong></div></div>
  <div class="card"><div class="card-body"><div class="small muted">Filtered total</div><strong>{{ money($summary->total_posted) }}</strong></div></div>
</div>
<div class="card flush"><div class="card-head"><h3>Folio entries · {{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</h3><span class="small muted">Voided entries excluded · totals match the selected filters</span></div>
  <div class="card-body table-wrap"><table class="table">
    <caption class="sr-only">Folio transactions posted on {{ $date }}</caption>
    <thead><tr><th scope="col">Folio</th><th scope="col">Guest / company</th><th scope="col">Room</th><th scope="col">Type / category</th><th scope="col">Posted at</th><th scope="col">Description</th><th scope="col">Mode</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Tax</th><th scope="col" class="num">Total</th><th scope="col">Posted by</th></tr></thead>
    <tbody>
    @forelse($transactions as $transaction)
      @php
        $entryFolio = $transaction->folio;
        $entryReservation = $entryFolio?->reservation ?? $entryFolio?->stay?->reservation;
        $entryGuest = $entryFolio?->guest ?? $entryFolio?->stay?->guest ?? $entryReservation?->guest;
        $entryCompany = $entryFolio?->company ?? $entryReservation?->company;
      @endphp
      <tr>
        <td><a class="bold" href="{{ route('folios.show', $transaction->folio) }}">{{ $transaction->folio?->folio_no ?? '—' }}</a></td>
        <td>{{ $entryGuest?->full_name ?? $entryCompany?->name ?? '—' }}@if($entryGuest && $entryCompany)<div class="tiny muted">{{ $entryCompany->name }}</div>@endif</td>
        <td>{{ $transaction->folio?->stay?->room?->room_no ?? '—' }}</td>
        <td><span class="badge badge-slate">{{ ucwords(str_replace('_', ' ', $transaction->type)) }}</span><div class="tiny muted">{{ $categoryOptions[$transaction->category] ?? ucwords(str_replace('_', ' ', $transaction->category)) }}</div></td>
        <td class="nowrap">@if($transaction->created_at)<time datetime="{{ $transaction->created_at->toIso8601String() }}">{{ $transaction->created_at->format('d M · H:i') }}</time>@else—@endif</td>
        <td>{{ $transaction->description }}</td>
        <td>{{ $transaction->paymentMode?->name ?? ($transaction->category === 'city_ledger' ? 'City Ledger' : '—') }}</td>
        <td class="num">{{ money($transaction->display_amount) }}</td>
        <td class="num">{{ money($transaction->display_tax) }}</td>
        <td class="num bold">{{ money($transaction->display_total) }}</td>
        <td>{{ $transaction->poster?->name ?? '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="11"><x-empty text="No folio entries match these filters for this business date." icon="receipt"/></td></tr>
    @endforelse
    </tbody>
  </table>{{ $transactions->links() }}</div>
</div>
@endsection
