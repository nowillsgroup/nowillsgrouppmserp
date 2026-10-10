@extends('layouts.app')
@section('title', 'Laundry Orders')
@section('crumbs') Laundry & Linen / Laundry orders @endsection
@section('actions')
<a class="btn" href="{{ route('laundry.prices') }}"><x-icon name="receipt"/> Price list</a>
@if(can_do('laundry.orders'))<a class="btn btn-primary" href="{{ route('laundry.create') }}"><x-icon name="plus"/> Collect laundry</a>@endif
@endsection
@section('content')
@if($stats['requests'] && can_do('laundry.orders'))
<div class="alert alert-info"><x-icon name="bell"/><div><b>{{ $stats['requests'] }} laundry pick-up request(s)</b> from the guest portal are waiting. <a href="{{ route('laundry.create') }}">Collect now →</a></div></div>
@endif
<div class="stats wide">
  <x-stat label="Collected – to sort" :value="$stats['collected']" icon="box" tone="blue" href="?tab=collected"/>
  <x-stat label="Processing" :value="$stats['processing']" icon="refresh" tone="amber" :sub="$stats['pieces'].' pcs with the laundry'" href="?tab=processing"/>
  <x-stat label="Ready to deliver" :value="$stats['ready']" icon="check" tone="purple" href="?tab=ready"/>
  <x-stat label="Overdue" :value="$stats['overdue']" icon="clock" :tone="$stats['overdue'] ? 'red' : 'green'" sub="past the promised time" href="?tab=overdue"/>
  <x-stat label="Laundry revenue today" :value="money($stats['today'])" icon="money" tone="green" :sub="'Month: '.money($stats['month'])"/>
</div>
<div class="tabs">@foreach(['open' => 'Open', 'overdue' => 'Overdue', 'collected' => 'Collected', 'processing' => 'Processing', 'ready' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $v)<a href="?{{ http_build_query(['tab' => $k, 'q' => $q ?: null]) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters mb-1 flex gap-1 items-center" method="get"><input type="hidden" name="tab" value="{{ $tab }}"><input name="q" class="input input-sm" style="max-width:320px" value="{{ $q }}" placeholder="Order no., room, guest, bag tag"><button class="btn btn-sm"><x-icon name="search"/> Search</button></form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="laundryTable">
<thead><tr><th>Order</th><th>Room / customer</th><th>Bag</th><th class="num">Pieces</th><th class="num">Amount</th><th>Promised</th><th>Status</th></tr></thead><tbody>
@forelse($list as $o)
<tr class="{{ $o->isOverdue() ? 'row-warn' : '' }}"><td><a class="bold" href="{{ route('laundry.show', $o) }}">{{ $o->order_no }}</a>@if($o->speed === 'express') <span class="badge badge-red">Express</span>@endif<div class="tiny muted">{{ $o->collected_at?->format('d M, H:i') }}</div></td>
<td>@if($o->type === 'walk_in'){{ $o->guest_name }} <span class="badge badge-slate">Walk-in</span>@else<b>{{ $o->room?->room_no }}</b> · {{ $o->guest_name }}@endif</td>
<td class="small">{{ $o->bag_no ?: '—' }}@if($o->bag_pieces && ! $o->pieces)<div class="tiny muted">{{ $o->bag_pieces }} pcs counted</div>@endif</td>
<td class="num">{{ $o->pieces ?: '—' }}</td>
<td class="num bold">{{ (float) $o->total ? money($o->total) : '—' }}</td>
<td class="small {{ $o->isOverdue() ? 'text-red bold' : '' }}">{{ $o->status === 'delivered' ? 'Delivered '.$o->delivered_at?->format('d M H:i') : $o->promised_at?->format('d M, H:i') }}</td>
<td><span class="badge badge-{{ \App\Models\LaundryOrder::TONES[$o->status] }}">{{ $o->statusLabel() }}</span>@if($o->isOverdue()) <span class="badge badge-red">Overdue</span>@endif</td></tr>
@empty<tr><td colspan="7"><x-empty text="No laundry orders here." icon="shirt"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $list->links() }}
@endsection
