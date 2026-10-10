@extends('layouts.app')
@section('title', $i->number.' '.$i->name)
@section('crumbs') <a href="{{ route('digital.influencers') }}">Digital Marketing / Influencers</a> / {{ $i->number }} @endsection
@php $I = \App\Models\DmInfluencer::class; $mg = can_do('digital.manage'); $w = can_do('digital.content') || $mg; $open = ! in_array($i->status, ['delivered', 'cancelled'], true); @endphp
@section('actions')
  @if($mg && $open)<button class="btn" data-modal="infModal" id="editInfBtn"><x-icon name="edit"/> Edit</button>
    @if($i->status === 'proposed')<form method="post" action="{{ route('digital.influencer.status', $i) }}">@csrf<input type="hidden" name="status" value="agreed"><button class="btn btn-primary" id="agreeBtn"><x-icon name="check"/> Agreed</button></form>@endif
    @if($i->status === 'agreed')<form method="post" action="{{ route('digital.influencer.status', $i) }}">@csrf<input type="hidden" name="status" value="hosted"><button class="btn btn-primary" id="hostedBtn"><x-icon name="hotel"/> Hosted</button></form>@endif
    @if($i->status === 'hosted')<button class="btn btn-primary" data-modal="deliverModal" id="deliverBtn"><x-icon name="star"/> Content delivered</button>@endif
    @if(in_array($i->status, ['proposed', 'agreed'], true))<button class="btn btn-danger" data-modal="cancelInfModal" id="cancelInfBtn">Cancel</button>@endif
  @endif
  @if($w && in_array($i->status, ['hosted', 'delivered'], true))<button class="btn" data-modal="resultsModal" id="infResultsBtn"><x-icon name="chart"/> Results</button>@endif
@endsection
@section('content')
<div class="flex gap-1 wrap mb-1"><span class="badge badge-{{ $i->isOverdue() ? 'red' : $i->statusTone() }}" id="infStatus" data-status="{{ $i->status }}">{{ $i->isOverdue() ? 'Hosted – content overdue' : $i->statusLabel() }}</span><span class="badge badge-slate">{{ \App\Models\DmChannel::PLATFORMS[$i->platform][0] ?? $i->platform }} {{ $i->handle }}</span>@if($i->rating)<span class="badge badge-amber">{{ str_repeat('★', $i->rating) }}</span>@endif</div>
@if($i->status === 'cancelled')<div class="alert alert-error">Cancelled: {{ $i->cancel_reason }}</div>@endif
<div class="stats" id="infStats">
  <x-stat label="Cost" :value="money_short($s['cost'])" icon="wallet" tone="amber" :sub="'fee '.money($i->fee).' · comp '.money($i->comp_value)"/>
  <x-stat label="Reach" :value="number_format($i->reach)" icon="eye" tone="purple" :sub="$s['cpm'] !== null ? money($s['cpm']).' per 1,000' : number_format($i->followers).' followers'"/>
  <x-stat label="Engagements" :value="number_format($i->engagements)" icon="star" tone="blue" :sub="$s['engagement_rate'] !== null ? $s['engagement_rate'].'% · '.money($s['cpe']).' each' : null"/>
  <x-stat label="Bookings via link" :value="$s['bookings']" icon="bed" tone="green" :sub="money_short($s['revenue']).($s['roi'] !== null ? ' · ROI '.number_format($s['roi'], 0).'%' : '')"/>
</div>
<div class="g-2-1 mt-1">
<div class="card"><div class="card-body"><dl class="kv"><dt>Stay</dt><dd>{{ $i->stay_from ? $i->stay_from->format('D d M').' – '.$i->stay_to->format('D d M Y') : '—' }}@if($i->reservation) · <a href="{{ route('reservations.show', $i->reservation) }}">{{ $i->reservation->reservation_no }}</a>@endif</dd>
  <dt>Content due</dt><dd>{{ $i->due_date?->format('d M Y') ?? '—' }}</dd><dt>Tracked link</dt><dd>@if($i->link)<a href="{{ route('digital.link', $i->link) }}">{{ $i->link->shortUrl() }}</a>@else — @endif</dd>
  <dt>Contact</dt><dd>{{ $i->email ?? '' }} {{ $i->phone ?? '' }}</dd><dt>Niche</dt><dd>{{ $i->niche ?? '—' }}</dd><dt>Owner</dt><dd>{{ $i->owner?->name }}</dd></dl>
  @if($i->deliverables)<h4 class="mt-1">Deliverables</h4><p class="small sa-notes">{{ $i->deliverables }}</p>@endif
  @if($i->notes)<h4 class="mt-1">Notes</h4><p class="small sa-notes">{{ $i->notes }}</p>@endif</div></div>
<div class="card" id="contentCard"><div class="card-head"><h3><x-icon name="link"/> Published content</h3></div><div class="card-body">@forelse($i->urls() as $u)<div class="small" style="word-break:break-all"><a href="{{ $u }}" target="_blank" rel="noopener">{{ $u }}</a></div>@empty<p class="muted small">Nothing yet.</p>@endforelse</div></div>
</div>
@if($mg && $open)@include('digital._influencer_modal', ['i' => $i])
<div class="modal" id="deliverModal"><div class="modal-box" style="max-width:38rem"><form method="post" action="{{ route('digital.influencer.status', $i) }}">@csrf<input type="hidden" name="status" value="delivered">
  <div class="modal-head"><h3>Content delivered</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label>Content links (one per line)</label><textarea name="content_urls" rows="4" required>{{ $i->content_urls }}</textarea></div>
    <div class="field"><label>Reach</label><input type="number" min="0" name="reach" value="{{ $i->reach }}"></div><div class="field"><label>Engagements</label><input type="number" min="0" name="engagements" value="{{ $i->engagements }}"></div>
    <div class="field"><label>Rating (1–5)</label><select name="rating"><option value="">—</option>@foreach([5, 4, 3, 2, 1] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Close collaboration</button></div></form></div></div>
<div class="modal" id="cancelInfModal"><div class="modal-box" style="max-width:32rem"><form method="post" action="{{ route('digital.influencer.status', $i) }}">@csrf<input type="hidden" name="status" value="cancelled">
  <div class="modal-head"><h3>Cancel collaboration</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="cancel_reason" maxlength="255" required></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Cancel collaboration</button></div></form></div></div>
@endif
@if($w && in_array($i->status, ['hosted', 'delivered'], true))
<div class="modal" id="resultsModal"><div class="modal-box" style="max-width:38rem"><form method="post" action="{{ route('digital.influencer.results', $i) }}">@csrf
  <div class="modal-head"><h3>Results</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid"><div class="field span-all"><label>Content links (one per line)</label><textarea name="content_urls" rows="4">{{ $i->content_urls }}</textarea></div>
    <div class="field"><label>Reach</label><input type="number" min="0" name="reach" value="{{ $i->reach }}"></div><div class="field"><label>Engagements</label><input type="number" min="0" name="engagements" value="{{ $i->engagements }}"></div>
    <div class="field"><label>Rating (1–5)</label><select name="rating"><option value="">—</option>@foreach([5, 4, 3, 2, 1] as $n)<option value="{{ $n }}" @selected($i->rating === $n)>{{ $n }}</option>@endforeach</select></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save results</button></div></form></div></div>
@endif
@endsection
