@extends('layouts.app')
@section('title', $p->name)
@section('crumbs') Business Development / <a href="{{ route('bd.partners') }}">Partnerships</a> / {{ $p->number }} @endsection
@php $w = can_do('bd.edit') || can_do('bd.manage'); $ap = can_do('bd.approve') || can_do('bd.manage'); $PT = \App\Models\BdPartner::class; @endphp
@section('actions')
  @if($w && $p->status !== 'terminated')<button class="btn" data-modal="partnerModal" id="editPartnerBtn"><x-icon name="edit"/> Edit</button>@endif
  @if($w && in_array($p->status, ['prospect', 'negotiating']))
    @if($p->status === 'prospect')<form method="post" action="{{ route('bd.partner.status', $p) }}">@csrf<input type="hidden" name="status" value="negotiating"><button class="btn" id="negotiateBtn">Start negotiating</button></form>@endif
    @if($ap)<form method="post" action="{{ route('bd.partner.status', $p) }}" data-confirm="Mark the agreement as signed and active?">@csrf<input type="hidden" name="status" value="active"><button class="btn btn-primary" id="activateBtn"><x-icon name="check"/> Signed – activate</button></form>@endif
  @endif
  @if($w && $p->status === 'active')<form method="post" action="{{ route('bd.partner.status', $p) }}" data-confirm="Mark this partnership as expired?">@csrf<input type="hidden" name="status" value="expired"><button class="btn" id="expireBtn">Mark expired</button></form>@endif
  @if($ap && $p->status === 'expired')<button class="btn btn-primary" data-modal="renewModal" id="renewBtn"><x-icon name="refresh"/> Renew</button>@endif
  @if($w && $p->status !== 'terminated')<button class="btn btn-danger" data-modal="endModal" id="terminateBtn">End partnership</button>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-{{ $p->statusTone() }}" id="partnerStatus" data-status="{{ $p->status }}">{{ $p->statusLabel() }}</span><span class="badge badge-blue">{{ $p->typeLabel() }}</span><span class="badge badge-slate">{{ $PT::AGREEMENTS[$p->agreement] }}</span>@foreach($flags as $f)<span class="badge badge-{{ $f[2] }}">{{ $f[1] }}</span>@endforeach</div>
<div class="stats" id="partnerStats">
  <x-stat label="Revenue {{ bdate()->year }}" :value="money_short($ytd['revenue'])" icon="money" tone="green" :sub="$ly['revenue'] > 0 ? 'LY '.money_short($ly['revenue']) : 'no production last year'"/>
  <x-stat label="Room nights" :value="number_format($ytd['nights'])" icon="moon" tone="blue" :sub="$ytd['bookings'].' bookings'.($p->target_nights ? ' · target '.number_format($p->target_nights) : '')"/>
  <x-stat label="Target achieved" :value="($ytd['revenue_pct'] ?? $ytd['nights_pct']) !== null ? ($ytd['revenue_pct'] ?? $ytd['nights_pct']).'%' : '—'" icon="trend" tone="purple" :sub="(float) $p->target_revenue ? 'of '.money_short($p->target_revenue) : ''"/>
  <x-stat label="Commission" :value="money_short($ytd['commission'])" icon="wallet" tone="amber" :sub="$p->commission_pct !== null ? rtrim(rtrim($p->commission_pct, '0'), '.').'% of room revenue' : 'no commission'"/>
</div>
<div class="g-2-1 mt-1">
  <div>
    <div class="card flush" id="partnerMonths"><div class="card-head"><h3><x-icon name="calendar"/> {{ bdate()->year }} by arrival month</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Month</th><th class="num">Bookings</th><th class="num">Nights</th><th class="num">Revenue</th></tr></thead><tbody>
      @foreach($months as $m)<tr class="{{ $m['month']->gt(bdate()) ? 'muted' : '' }}"><td>{{ $m['month']->format('M') }}</td><td class="num">{{ $m['bookings'] ?: '' }}</td><td class="num">{{ $m['nights'] ?: '' }}</td><td class="num">{{ $m['revenue'] ? number_format($m['revenue'], 2) : '' }}</td></tr>@endforeach
    </tbody></table></div></div>
    <div class="card flush mt-1" id="partnerStays"><div class="card-head"><h3><x-icon name="bed"/> Recent stays credited</h3></div><div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Reservation</th><th>Guest</th><th>Arrival</th><th>Source</th><th>Status</th></tr></thead><tbody>
      @forelse($stays as $r)<tr><td><a href="{{ route('reservations.show', $r) }}">{{ $r->reservation_no }}</a></td><td>{{ $r->guest?->full_name }}</td><td class="nowrap">{{ $r->arrival_date->format('d M Y') }}</td><td class="small">{{ $r->source?->name }}</td><td class="small">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</td></tr>
      @empty<tr><td colspan="5" class="muted">{{ $p->hasTracking() ? 'No stays yet.' : 'Link a business source, promo code or company to credit stays.' }}</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><div class="card-body"><dl class="kv">
      <dt>Contact</dt><dd>{{ $p->contact_name ?? '—' }}@if($p->email)<div class="small"><a href="mailto:{{ $p->email }}">{{ $p->email }}</a></div>@endif @if($p->phone)<div class="small">{{ $p->phone }}</div>@endif</dd>
      <dt>Term</dt><dd>{{ $p->start_date?->format('d M Y') ?? '—' }} – {{ $p->end_date?->format('d M Y') ?? '—' }}</dd>
      <dt>Review</dt><dd>{{ $p->review_date?->format('d M Y') ?? '—' }}</dd>
      <dt>Tracks</dt><dd class="small">{{ collect([$p->source ? 'source '.$p->source->name : null, $p->promo ? 'promo '.$p->promo->code : null, $p->company ? 'company '.$p->company->name : null])->filter()->implode(' · ') ?: '—' }}</dd>
      <dt>Owner</dt><dd>{{ $p->owner?->name ?? '—' }}</dd>
      @if($p->end_reason)<dt>Ended</dt><dd class="text-red small">{{ $p->end_reason }}</dd>@endif
    </dl></div></div>
    @if($p->terms)<div class="card mt-1"><div class="card-head"><h3>Terms</h3></div><div class="card-body small">{!! nl2br(e($p->terms)) !!}</div></div>@endif
    @if($p->initiatives->count())<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="layers"/> Initiatives</h3></div><div class="card-body">@foreach($p->initiatives as $i)<a class="dm-item" href="{{ route('bd.initiative', $i) }}"><b>{{ $i->number }}</b> {{ $i->name }} <span class="badge badge-{{ $i->stageTone() }}">{{ $i->stageLabel() }}</span></a>@endforeach</div></div>@endif
    @if($p->notes)<div class="card mt-1"><div class="card-body small muted">{!! nl2br(e($p->notes)) !!}</div></div>@endif
  </div>
</div>
@if($w && $p->status !== 'terminated')
@include('bd._partner_modal', ['p' => $p])
<div class="modal" id="endModal"><div class="modal-box" style="max-width:32rem"><form method="post" action="{{ route('bd.partner.status', $p) }}">@csrf<input type="hidden" name="status" value="terminated">
  <div class="modal-head"><h3>End partnership</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><textarea name="reason" rows="3" required maxlength="255"></textarea></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">End partnership</button></div></form></div></div>
@endif
@if($ap && $p->status === 'expired')
<div class="modal" id="renewModal"><div class="modal-box" style="max-width:30rem"><form method="post" action="{{ route('bd.partner.status', $p) }}">@csrf<input type="hidden" name="status" value="active">
  <div class="modal-head"><h3>Renew partnership</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>New end date</label><input type="date" name="end_date" required min="{{ bdate()->copy()->addDay()->toDateString() }}"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Renew</button></div></form></div></div>
@endif
@endsection
