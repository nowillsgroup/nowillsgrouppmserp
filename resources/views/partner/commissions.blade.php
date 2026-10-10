@extends('partner.layout')
@section('title', 'Commissions')
@section('content')
<div class="stats" id="ptnCommStats">
  <x-stat label="Due to you" :value="money($due)" icon="money" tone="amber"/>
  <x-stat label="Paid" :value="money($paid)" icon="check" tone="green"/>
  <x-stat label="Commission rate" :value="rtrim(rtrim(number_format($company->commission_pct, 2), '0'), '.').'%'" icon="trend" tone="slate" sub="of room revenue, accrued on check-out"/>
</div>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No commission accrued yet – it is earned when your guests check out." icon="wallet"/>@else
  <table class="table" id="ptnCommissions"><thead><tr><th>Date</th><th>Booking</th><th>Guest</th><th>Nights</th><th class="right">Room revenue</th><th class="right">%</th><th class="right">Commission</th><th>Status</th></tr></thead><tbody>
  @foreach($rows as $c)
    <tr><td>{{ $c->business_date->format('d M Y') }}</td><td>{{ $c->reservation?->reservation_no }}</td><td>{{ $c->reservation?->guest?->full_name }}</td><td>{{ $c->nights }}</td><td class="right">{{ money($c->room_revenue) }}</td><td class="right">{{ rtrim(rtrim(number_format($c->pct, 2), '0'), '.') }}</td><td class="right"><b>{{ money($c->amount) }}</b></td>
      <td><span class="badge {{ $c->status === 'paid' ? 'badge-green' : 'badge-amber' }}">{{ ucfirst($c->status) }}</span>@if($c->paid_at)<div class="tiny muted">{{ $c->paid_at->format('d M Y') }}</div>@endif</td></tr>
  @endforeach
  </tbody></table>@endif
</div></div>
@endsection
