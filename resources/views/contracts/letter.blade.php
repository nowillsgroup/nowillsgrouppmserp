@extends('layouts.print')
@section('title', 'Rate agreement '.$c->code)
@section('doctitle', 'RATE AGREEMENT')
@section('docmeta')<div class="small"><b>{{ $c->code }}</b></div><div class="small">{{ $c->valid_from->format('d M Y') }} – {{ $c->valid_to->format('d M Y') }}</div>@if($c->status !== 'active')<div class="small" style="color:#b91c1c"><b>{{ strtoupper($c->status) }} – NOT YET VALID</b></div>@endif @endsection
@section('content')
<p class="mt-2">To,<br><b>{{ $c->company->name }}</b>@if($c->company->contact_person)<br>Attn: {{ $c->company->contact_person }}@endif @if($c->company->address)<br>{{ $c->company->address }}@endif</p>
<p>Dear {{ $c->company->contact_person ?: 'Sir / Madam' }},</p>
<p>Thank you for choosing <b>{{ $currentHotel->name }}</b>. We are pleased to confirm the following {{ $c->company->type === 'corporate' ? 'corporate' : 'trade' }} rates for your {{ $c->company->type === 'corporate' ? 'employees and guests' : 'clients' }}, valid for stays from <b>{{ $c->valid_from->format('d F Y') }}</b> to <b>{{ $c->valid_to->format('d F Y') }}</b>.</p>

@if($c->pricing === 'net' && $c->rates->isNotEmpty())
<table class="table mt-1"><thead><tr><th>Room type</th><th>Period</th><th class="num">Single</th><th class="num">Double</th><th class="num">Extra adult</th></tr></thead><tbody>
@foreach($c->rates->sortBy(fn ($r) => [$r->roomType?->base_rate, $r->season_from?->toDateString() ?? '']) as $r)
<tr><td>{{ $r->roomType?->name }}</td><td>{{ $r->season_from ? ($r->season_name ? $r->season_name.': ' : '').$r->season_from->format('d M Y').' – '.$r->season_to->format('d M Y') : 'Entire period' }}</td>
  <td class="num">{{ money($r->rate) }}</td><td class="num">{{ $r->double_rate ? money($r->double_rate) : money($r->rate) }}</td><td class="num">{{ $r->extra_adult_rate !== null ? money($r->extra_adult_rate) : '—' }}</td></tr>
@endforeach
</tbody></table>
@if($c->discount_pct > 0)<p class="small">Other room categories: {{ rtrim(rtrim((string) $c->discount_pct, '0'), '.') }}% off the prevailing best available rate.</p>@endif
@else
<p class="mt-1"><b>{{ rtrim(rtrim((string) $c->discount_pct, '0'), '.') }}% discount</b> on the prevailing best available rate for all room categories.</p>
@endif

<h4 class="mt-2">Terms</h4>
<ul style="padding-left:1.1rem;line-height:1.6">
  <li>Rates are per room per night in {{ $currentHotel->currency ?? 'GHS' }}, <b>{{ \App\Models\RateContract::MEAL_PLANS[$c->meal_plan] ?? $c->meal_plan }}</b>, exclusive of applicable taxes.</li>
  @if($c->inclusions)<li>Inclusions: {{ $c->inclusions }}.</li>@endif
  <li>{{ $c->lra ? 'Last-room availability: the agreed rate is available whenever the hotel has a room to sell.' : 'Rates are subject to availability at the time of booking.' }}</li>
  @foreach($c->allotments as $a)<li>Allotment: {{ $a->rooms }} {{ $a->roomType?->name }} room(s) per night from {{ $a->date_from->format('d M Y') }} to {{ $a->date_to->format('d M Y') }}; unsold rooms are released {{ $a->release_days }} day(s) before arrival.</li>@endforeach
  @if($c->blackouts->isNotEmpty())<li>Blackout dates (agreed rates do not apply; best available rate is charged): {{ $c->blackouts->map(fn ($b) => $b->date_from->format('d M').' – '.$b->date_to->format('d M Y').($b->reason ? ' ('.$b->reason.')' : ''))->implode('; ') }}.</li>@endif
  <li>Cancellation: free of charge until {{ $c->cancel_days }} day(s) before arrival; later cancellations and no-shows are charged as per hotel policy.</li>
  @if($c->company->direct_billing)<li>Direct billing: approved, subject to a credit limit of {{ money($c->company->credit_limit) }} and payment within {{ $c->company->credit_days }} days of the invoice date.</li>
  @else<li>Payment: settled directly by the guest at check-out.</li>@endif
  @if($c->commission_pct !== null && $c->commission_pct > 0)<li>Commission: {{ rtrim(rtrim((string) $c->commission_pct, '0'), '.') }}% on room revenue of consumed room nights.</li>@endif
  @if($c->access_code)<li>Online bookings: enter the corporate code <b>{{ $c->access_code }}</b> on our website to book at these rates.</li>@endif
  @if($c->room_night_target)<li>These rates are based on an estimated production of {{ number_format($c->room_night_target) }} room nights for the period.</li>@endif
</ul>
<p class="mt-2">Please sign and return a copy of this letter as acceptance.</p>
<div class="flex between" style="margin-top:3rem">
  <div style="border-top:1px solid #94a3b8;padding-top:.3rem;min-width:220px">For {{ $currentHotel->name }}<div class="small muted">{{ $c->approver?->name ?? 'Authorised signatory' }}</div></div>
  <div style="border-top:1px solid #94a3b8;padding-top:.3rem;min-width:220px">Accepted for {{ $c->company->name }}<div class="small muted">Name, designation, seal</div></div>
</div>
@endsection
