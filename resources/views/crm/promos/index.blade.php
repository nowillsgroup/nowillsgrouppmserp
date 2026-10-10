@extends('layouts.app')
@section('title', 'Promo Codes')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / Promo codes @endsection
@section('actions')
<a class="btn" href="{{ route('reports.show', 'crm_promos') }}"><x-icon name="chart"/> Redemption report</a>
@if(can_do('crm.manage'))<a class="btn btn-primary" href="{{ route('crm.promos.create') }}" id="newPromo"><x-icon name="plus"/> New promo code</a>@endif
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="promoTable">
<thead><tr><th>Code</th><th>Discount</th><th>Book by</th><th>Stay window</th><th>Rules</th><th class="num">Uses</th><th class="num">Room nights</th><th class="num">Revenue</th><th class="num">Discount given</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($promos as $p) @php $v = $p->value_stats; $expired = $p->book_to && $p->book_to->lt(bdate()); @endphp
<tr class="{{ $p->is_active && ! $expired ? '' : 'muted-row' }}">
  <td><b style="font-family:monospace;font-size:1rem">{{ $p->code }}</b><div class="tiny muted">{{ $p->name }}{{ $p->campaigns_count ? ' · '.$p->campaigns_count.' campaign(s)' : '' }}</div></td>
  <td class="small">{{ $p->offerLabel() }}</td>
  <td class="small nowrap">{{ $p->book_from?->format('d M') ?? 'now' }} – {{ $p->book_to?->format('d M Y') ?? 'open' }}@if($expired)<div class="tiny text-red">expired</div>@endif</td>
  <td class="small nowrap">{{ $p->stay_from || $p->stay_to ? ($p->stay_from?->format('d M') ?? 'now').' – '.($p->stay_to?->format('d M Y') ?? 'open') : 'Any' }}</td>
  <td class="tiny">min {{ $p->min_nights }} night(s){{ $p->per_guest_limit ? ' · '.$p->per_guest_limit.'× per guest' : '' }}@if($p->room_type_ids)<div class="muted">{{ collect($p->room_type_ids)->map(fn ($id) => $types[$id] ?? null)->filter()->join(', ') }}</div>@endif</td>
  <td class="num">{{ $v['bookings'] }}{{ $p->max_uses ? ' / '.$p->max_uses : '' }}</td>
  <td class="num">{{ $v['nights'] }}</td><td class="num">{{ money($v['revenue'], false) }}</td><td class="num">{{ money($v['discount'], false) }}</td>
  <td>{!! status_badge($p->is_active ? 'active' : 'inactive') !!}</td>
  <td class="nowrap right">@if(can_do('crm.manage'))<a class="btn btn-sm btn-ghost" href="{{ route('crm.promos.edit', $p) }}" title="Edit"><x-icon name="edit"/></a>
    <form method="post" action="{{ route('crm.promos.toggle', $p) }}" style="display:inline">@csrf<button class="btn btn-sm btn-ghost" title="{{ $p->is_active ? 'Deactivate' : 'Activate' }}"><x-icon name="{{ $p->is_active ? 'x' : 'check' }}"/></button></form>@endif</td>
</tr>
@empty<tr><td colspan="11"><x-empty text="No promo codes yet." icon="money"/></td></tr>@endforelse
</tbody></table></div></div>
<p class="tiny muted mt-1">Guests enter codes on the online booking engine (or arrive with the code pre-filled from a campaign link). The discount is taken off every room-night and the booking is credited to the campaign that carried the code. Cancelled bookings free up the use again.</p>
@endsection
