@extends('layouts.app')
@section('title', 'Influencers')
@section('crumbs') Digital Marketing / Influencers @endsection
@php $I = \App\Models\DmInfluencer::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'digital_influencers']) }}"><x-icon name="download"/> Influencer report</a>
  @if(can_do('digital.manage'))<button class="btn btn-primary" data-modal="infModalNew" id="newInfBtn"><x-icon name="plus"/> New collaboration</button>@endif
@endsection
@section('content')
@include('digital._workspace-nav')
@php
  $overdueCollaborations = $rows->filter(fn ($row) => $row[0]->isOverdue())->count();
  $recordedCreatorValue = (float) $rows->sum(fn ($row) => $row[1]['cost']);
  $deliveredCollaborations = $rows->filter(fn ($row) => $row[0]->status === 'delivered')->count();
@endphp
<div class="stats" id="influencerStats">
  <x-stat label="Collaborations in view" :value="number_format($rows->count())" icon="users" tone="blue" :sub="$status ? $I::STATUSES[$status][0] : 'all statuses'"/>
  <x-stat label="Content overdue" :value="number_format($overdueCollaborations)" icon="alert" :tone="$overdueCollaborations ? 'red' : 'green'" sub="hosted collaborations past their due date"/>
  <x-stat label="Recorded creator value" :value="money_short($recordedCreatorValue)" icon="wallet" tone="amber" sub="fees + complimentary value in view"/>
  <x-stat label="Content delivered" :value="number_format($deliveredCollaborations)" icon="check" tone="green" sub="collaborations in view"/>
</div>
<nav class="tabs mt-1" aria-label="Influencer collaboration status"><a href="{{ route('digital.influencers') }}" class="{{ ! $status ? 'active' : '' }}" @if(! $status) aria-current="page" @endif>All</a>@foreach($I::STATUSES as $k => [$l])<a href="{{ route('digital.influencers', ['status' => $k]) }}" class="{{ $status === $k ? 'active' : '' }}" @if($status === $k) aria-current="page" @endif>{{ $l }}</a>@endforeach</nav>
<div class="card flush"><div class="card-head"><h3><x-icon name="users"/> Creator collaborations</h3><span class="tiny muted">{{ number_format($rows->count()) }} record{{ $rows->count() === 1 ? '' : 's' }}</span></div><div class="card-body table-wrap"><table class="table table-compact" id="infTable"><thead><tr><th scope="col">Creator</th><th scope="col">Status</th><th scope="col">Stay</th><th scope="col">Content due</th><th scope="col" class="num">Cost</th><th scope="col" class="num">Reach</th><th scope="col" class="num">Eng.</th><th scope="col" class="num">Cost / eng.</th><th scope="col" class="num">Bookings</th><th scope="col" class="num">ROI</th></tr></thead><tbody>
@forelse($rows as [$i, $s])<tr data-inf="{{ $i->number }}"><td><a href="{{ route('digital.influencer', $i) }}"><b>{{ $i->number }}</b> {{ $i->name }}</a><div class="tiny muted">{{ $i->handle }} · {{ \App\Models\DmChannel::PLATFORMS[$i->platform][0] ?? $i->platform }} · {{ number_format($i->followers) }} followers</div></td>
  <td><span class="badge badge-{{ $i->isOverdue() ? 'red' : $i->statusTone() }}">{{ $i->isOverdue() ? 'Content overdue' : $i->statusLabel() }}</span></td><td class="small nowrap">{{ $i->stay_from ? $i->stay_from->format('d M').' – '.$i->stay_to->format('d M') : '—' }}</td>
  <td class="small">{{ $i->due_date?->format('d M') ?? '—' }}</td><td class="num">{{ money($s['cost']) }}</td><td class="num">{{ $i->reach ? number_format($i->reach) : '—' }}</td><td class="num">{{ $i->engagements ? number_format($i->engagements) : '—' }}</td>
  <td class="num">{{ $s['cpe'] !== null ? money($s['cpe']) : '—' }}</td><td class="num">{{ $s['bookings'] }}</td><td class="num {{ $s['roi'] !== null ? ($s['roi'] >= 0 ? 'text-green' : 'text-red') : '' }}">{{ $s['roi'] !== null && $i->status === 'delivered' ? number_format($s['roi'], 0).'%' : '—' }}</td></tr>
@empty<tr><td colspan="10" class="empty">{{ $status ? 'No collaborations match this status.' : 'No influencer collaborations yet. Create a proposal to start tracking a creator partnership.' }}</td></tr>@endforelse</tbody></table></div></div>
<p class="tiny muted mt-1">Cost = cash fee + complimentary stay value. Cash fees of hosted / delivered collaborations count towards the digital line of the marketing budget. Bookings are those made through the creator's tracked link.</p>
@if(can_do('digital.manage'))@include('digital._influencer_modal', ['i' => null])@endif
@endsection
