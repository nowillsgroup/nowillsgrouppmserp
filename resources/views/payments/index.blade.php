@extends('layouts.app')
@section('title', 'Online Payments')
@section('crumbs') Billing / Online payments @endsection
@section('actions')
@if(can_do('payments.settle'))<a class="btn" href="{{ route('payments.payouts') }}"><x-icon name="download"/> Gateway payouts</a>@endif
@if(can_do('payments.settings'))<a class="btn" href="{{ route('payments.settings') }}"><x-icon name="tool"/> Settings</a>@endif
@endsection
@section('content')
@if(! $enabled)<div class="alert alert-warn"><x-icon name="alert"/><div>Online payments are <b>off</b> – no new links can be created.@if(can_do('payments.settings')) <a href="{{ route('payments.settings') }}">Choose a gateway and switch them on</a>.@endif</div></div>
@elseif($provider === 'demo')<div class="alert alert-info"><x-icon name="alert"/><div>The <b>built-in test gateway</b> is active – guests can try the full flow but no real money moves. Choose Paystack, Stripe or Razorpay in Settings to go live.</div></div>@endif
<div class="stats wide">
  <x-stat label="Collected today" :value="money($stats['today'])" icon="money" tone="green" :sub="$stats['today_n'].' payment(s)'" href="?status=paid"/>
  <x-stat label="Collected this month" :value="money($stats['month'])" icon="trend" tone="blue" href="?status=paid"/>
  <x-stat label="Awaiting payment" :value="money($stats['pending'])" icon="clock" tone="amber" :sub="$stats['pending_n'].' open link(s)'" href="?status=pending"/>
  <x-stat label="Needs review" :value="$stats['review_n']" icon="alert" :tone="$stats['review_n'] ? 'red' : 'green'" href="?status=review" sub="amount / currency mismatch"/>
  <x-stat label="Refunded this month" :value="money($stats['refunds'])" icon="swap" tone="slate" href="?status=refunded"/>
  <x-stat label="Awaiting payout" :value="money($stats['clearing'])" icon="wallet" tone="purple" sub="1050 gateway clearing" :href="can_do('payments.settle') ? route('payments.payouts') : null"/>
</div>
<div class="tabs">@foreach(['' => 'All', 'pending' => 'Awaiting payment', 'paid' => 'Paid', 'review' => 'Needs review', 'refunded' => 'Refunded', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $k => $v)<a href="?{{ http_build_query(array_merge(request()->except('page', 'status'), ['status' => $k])) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters mb-1 flex gap-1 items-center" method="get"><input type="hidden" name="status" value="{{ $status }}"><input name="q" class="input input-sm" style="max-width:320px" value="{{ request('q') }}" placeholder="Link no., guest, reservation, transaction id"><button class="btn btn-sm"><x-icon name="search"/> Search</button></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="olpTable">
<thead><tr><th>Link</th><th>Created</th><th>Guest</th><th>For</th><th>Gateway</th><th class="num">Amount</th><th>Status</th><th>Paid</th></tr></thead><tbody>
@forelse($list as $p)@php $st = $p->displayStatus(); @endphp
<tr><td><a class="bold" href="{{ route('payments.show', $p) }}">{{ $p->pay_no }}</a><div class="tiny muted">{{ \App\Models\OnlinePayment::PURPOSES[$p->purpose] ?? ['city_ledger' => 'City ledger settlement', 'banquet' => 'Event payment'][$p->purpose] ?? $p->purpose }}</div></td>
<td class="small">{{ $p->created_at->format('d M, H:i') }}<div class="tiny muted">{{ $p->creator?->name ?? 'Guest / online' }}</div></td>
<td>{{ $p->payer_name }}<div class="tiny muted">{{ $p->payer_email ?: $p->payer_phone }}</div></td>
<td class="small">@if($p->company_id && $p->company)<a href="{{ route('accounts.company', $p->company_id) }}">{{ $p->company->name }}</a><div class="tiny muted">City ledger</div> @endif @if($p->reservation)<a href="{{ route('reservations.show', $p->reservation) }}">{{ $p->reservation->reservation_no }}</a>@endif @if($p->folio)<div class="tiny muted">Folio {{ $p->folio->folio_no }}</div>@endif</td>
<td class="small">{{ ucfirst($p->provider) }}@if($p->channel)<div class="tiny muted">{{ str_replace('_', ' ', $p->channel) }}</div>@endif</td>
<td class="num bold">{{ money($p->amount) }}@if((float) $p->refunded_amount > 0)<div class="tiny text-red">−{{ money($p->refunded_amount) }} refunded</div>@endif</td>
<td><span class="badge badge-{{ ['paid' => 'green', 'part_refunded' => 'amber', 'refunded' => 'slate', 'pending' => 'blue', 'review' => 'red', 'expired' => 'slate', 'cancelled' => 'red'][$st] ?? 'slate' }}">{{ $p->statusLabel() }}</span>@if($st === 'pending' && $p->expires_at)<div class="tiny muted">until {{ $p->expires_at->format('d M H:i') }}</div>@endif</td>
<td class="small">{{ $p->paid_at?->format('d M, H:i') ?? '—' }}</td></tr>
@empty<tr><td colspan="8"><x-empty text="No online payments yet. Create a payment link from a reservation or a folio." icon="link"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $list->links() }}
@endsection
