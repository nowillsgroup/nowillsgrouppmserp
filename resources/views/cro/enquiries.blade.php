@extends('layouts.app')
@section('title', 'Reservations Enquiries')
@section('crumbs') Central Reservations / Enquiries @endsection
@php $E = \App\Models\CroEnquiry::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'cro_enquiries']) }}"><x-icon name="download"/> Register</a>
  @if($canBook)<button class="btn btn-primary" data-modal="croLogModal" id="logEnquiryBtn"><x-icon name="phone"/> Log enquiry</button>@endif
@endsection
@section('content')
<div class="stats" id="enqStats">
  <x-stat label="Enquiries" :value="$kpi['total']" icon="phone" tone="blue" :sub="$from->format('d M').' – '.$to->format('d M Y')"/>
  <x-stat label="Booked" :value="$kpi['booked']" icon="check" tone="green" :sub="money_short($kpi['booked_revenue']).' · conversion '.($kpi['conversion'] !== null ? $kpi['conversion'].'%' : '—')"/>
  <x-stat label="Referred to sisters" :value="$kpi['referred']" icon="swap" tone="purple" :sub="money_short($kpi['referred_revenue']).' kept in the group'"/>
  <x-stat label="Denials / regrets" :value="$kpi['denials'].' / '.$kpi['regrets']" icon="alert" tone="red" :sub="money_short($kpi['lost_revenue']).' lost'"/>
  <x-stat label="Follow-ups due" :value="$alerts['follow_ups']" icon="clock" :tone="$alerts['follow_ups'] ? 'amber' : 'slate'" :href="route('cro.enquiries', ['status' => 'open'])"/>
</div>
<div class="tabs mt-1" id="enqTabs"><a class="{{ ! $status ? 'active' : '' }}" href="{{ route('cro.enquiries', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">All</a>@foreach($E::STATUSES as $k => $s)<a class="{{ $status === $k ? 'active' : '' }}" href="{{ route('cro.enquiries', ['status' => $k, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">{{ $s[0] }}</a>@endforeach</div>
<div class="card"><div class="card-body"><form method="get" class="flex gap-1 wrap" style="align-items:center" id="enqFilter">@if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
  <input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"><input type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To">
  <select name="type" aria-label="Loss type"><option value="">Any outcome reason</option><option value="denial" @selected($type === 'denial')>Denials</option><option value="regret" @selected($type === 'regret')>Regrets</option></select>
  <select name="agent" aria-label="Agent"><option value="">All agents</option>@foreach($people as $p)<option value="{{ $p->id }}" @selected($agent === $p->id)>{{ $p->name }}</option>@endforeach</select>
  <input name="q" value="{{ $term }}" placeholder="Caller, phone, e-mail, ENQ no." aria-label="Search">
  <button class="btn btn-sm">Filter</button>@if($status === 'open')<span class="tiny muted">Open enquiries are shown regardless of date.</span>@endif
</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="enqTable"><thead><tr><th>Enquiry</th><th>Caller</th><th>Stay</th><th>Asked</th><th class="num">Value</th><th>Outcome</th><th>Agent</th></tr></thead><tbody>
  @forelse($rows as $e)<tr data-enq="{{ $e->number }}" class="{{ $e->isFollowUpDue() ? 'row-alert' : '' }}">
    <td class="nowrap"><a href="{{ route('cro.enquiry', $e) }}"><b>{{ $e->number }}</b></a><div class="tiny muted">{{ $e->created_at->format('d M H:i') }} · {{ $e->channelLabel() }}</div></td>
    <td>{{ $e->caller_name }}<div class="tiny muted">{{ $e->phone ?: $e->email }}</div></td>
    <td class="small nowrap">{{ $e->arrival_date->format('d M') }} – {{ $e->departure_date->format('d M') }}<div class="tiny muted">{{ $e->nights() }} n · {{ $e->rooms }} rm · {{ $e->adults }}+{{ $e->children }}</div></td>
    <td class="small">{{ $e->roomType?->name ?? 'Any' }}@if($e->quoted_rate !== null)<div class="tiny muted">{{ number_format($e->quoted_rate, 2) }} / n</div>@endif</td>
    <td class="num">{{ $e->booked_value !== null ? number_format($e->booked_value, 2) : ($e->quoted_total !== null ? number_format($e->quoted_total, 2) : '—') }}</td>
    <td><span class="badge badge-{{ $e->statusTone() }}">{{ $e->statusLabel() }}</span>@if($e->loss_type) <span class="badge badge-{{ $E::LOSS_TYPES[$e->loss_type][1] }}">{{ $E::LOSS_TYPES[$e->loss_type][0] }}</span>@endif
      <div class="tiny muted">@if($e->status === 'booked'){{ $e->reservation?->reservation_no }}{{ $e->referredFrom ? ' · from '.$e->referredFrom->name : '' }}@elseif($e->status === 'referred')→ {{ $e->referredHotel?->name }} {{ $e->referred_reservation_no }}@elseif($e->status === 'lost'){{ $e->reasonLabel() }}{{ $e->competitor ? ' · '.$e->competitor->name : '' }}@elseif($e->follow_up_at)follow up {{ $e->follow_up_at->format('d M H:i') }}@endif</div></td>
    <td class="small">{{ $e->agent?->name }}</td></tr>
  @empty<tr><td colspan="7" class="muted">No enquiries match.</td></tr>@endforelse
</tbody></table></div></div>
{{ $rows->links() }}
@if($canBook)@include('cro._log_modal')@endif
@endsection
