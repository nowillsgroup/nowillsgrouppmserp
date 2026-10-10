@extends('layouts.app')
@section('title', 'Influencers')
@section('crumbs') Digital Marketing / Influencers @endsection
@php $I = \App\Models\DmInfluencer::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_influencers']) }}"><x-icon name="download"/> Influencer report</a>
  @if(can_do('digital.manage'))<button class="btn btn-primary" data-modal="infModalNew" id="newInfBtn"><x-icon name="plus"/> New collaboration</button>@endif
@endsection
@section('content')
<div class="tabs"><a href="?" class="{{ ! $status ? 'active' : '' }}">All</a>@foreach($I::STATUSES as $k => [$l])<a href="?status={{ $k }}" class="{{ $status === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="infTable"><thead><tr><th>Creator</th><th>Status</th><th>Stay</th><th>Content due</th><th class="num">Cost</th><th class="num">Reach</th><th class="num">Eng.</th><th class="num">Cost / eng.</th><th class="num">Bookings</th><th class="num">ROI</th></tr></thead><tbody>
@forelse($rows as [$i, $s])<tr data-inf="{{ $i->number }}"><td><a href="{{ route('digital.influencer', $i) }}"><b>{{ $i->number }}</b> {{ $i->name }}</a><div class="tiny muted">{{ $i->handle }} · {{ \App\Models\DmChannel::PLATFORMS[$i->platform][0] ?? $i->platform }} · {{ number_format($i->followers) }} followers</div></td>
  <td><span class="badge badge-{{ $i->isOverdue() ? 'red' : $i->statusTone() }}">{{ $i->isOverdue() ? 'Content overdue' : $i->statusLabel() }}</span></td><td class="small nowrap">{{ $i->stay_from ? $i->stay_from->format('d M').' – '.$i->stay_to->format('d M') : '—' }}</td>
  <td class="small">{{ $i->due_date?->format('d M') ?? '—' }}</td><td class="num">{{ number_format($s['cost']) }}</td><td class="num">{{ $i->reach ? number_format($i->reach) : '—' }}</td><td class="num">{{ $i->engagements ? number_format($i->engagements) : '—' }}</td>
  <td class="num">{{ $s['cpe'] !== null ? number_format($s['cpe'], 2) : '—' }}</td><td class="num">{{ $s['bookings'] }}</td><td class="num {{ ($s['roi'] ?? 0) >= 0 ? '' : 'text-red' }}">{{ $s['roi'] !== null && $i->status === 'delivered' ? number_format($s['roi'], 0).'%' : '—' }}</td></tr>
@empty<tr><td colspan="10" class="empty">No collaborations yet.</td></tr>@endforelse</tbody></table></div></div>
<p class="tiny muted mt-1">Cost = cash fee + complimentary stay value. Cash fees of hosted / delivered collaborations count towards the digital line of the marketing budget. Bookings are those made through the creator's tracked link.</p>
@if(can_do('digital.manage'))@include('digital._influencer_modal', ['i' => null])@endif
@endsection
