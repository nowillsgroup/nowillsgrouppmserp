@extends('layouts.app')
@section('title', 'Banquet Events & Enquiries')
@section('crumbs') Banquet / Events @endsection
@section('actions')
<a class="btn" href="{{ route('banquet.diary') }}"><x-icon name="calendar"/> Function diary</a>
@if(can_do('banquet.manage'))<a class="btn btn-primary" href="{{ route('banquet.create') }}" id="newEvent"><x-icon name="plus"/> New enquiry</a>@endif
@endsection
@section('content')
<div class="stats req5">
  <x-stat label="Definite business" :value="money($stats['definite'])" icon="check" tone="green" :sub="$stats['definite_n'].' open event(s), before tax'" href="?show=upcoming"/>
  <x-stat label="Tentative holds" :value="money($stats['tentative'])" icon="clock" :tone="$stats['expiring'] ? 'amber' : 'blue'" :sub="$stats['tentative_n'].' hold(s)'.($stats['expiring'] ? ' · '.$stats['expiring'].' option(s) due ≤ 3 days' : '')" href="?show=tentative"/>
  <x-stat label="Enquiries" :value="$stats['enquiry_n']" icon="message" tone="slate" :sub="$stats['conversion'] !== null ? 'conversion '.$stats['conversion'].'% (90 days)' : 'no decisions in 90 days'" href="?show=enquiry"/>
  <x-stat label="Deposits held" :value="money($stats['deposits'])" icon="wallet" tone="purple" sub="on open events"/>
  <x-stat label="To bill" :value="$stats['to_bill']" icon="receipt" :tone="$stats['to_bill'] ? 'red' : 'green'" sub="functions over, not billed" href="?show=to_bill"/>
</div>
<div class="tabs">@foreach(\App\Http\Controllers\BanquetController::TABS as $k => $v)<a href="?{{ http_build_query(array_filter(['show' => $k, 'q' => request('q'), 'type' => request('type'), 'sales' => request('sales')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<form class="filters" method="get"><input type="hidden" name="show" value="{{ $tab }}">
  <input name="q" class="input" placeholder="Event no., title or contact" value="{{ request('q') }}">
  <select name="type" class="input"><option value="">All event types</option>@foreach(\App\Models\BanquetEvent::TYPES as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select>
  <select name="sales" class="input"><option value="">All sales people</option>@foreach($sales as $id => $n)<option value="{{ $id }}" @selected((string) request('sales') === (string) $id)>{{ $n }}</option>@endforeach</select>
  <button class="btn"><x-icon name="search"/> Filter</button>
</form>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="eventTable">
<thead><tr><th>Event</th><th>Client</th><th>Date(s)</th><th>Functions / venues</th><th class="num">Pax</th><th class="num">Value</th><th>Status</th></tr></thead><tbody>
@forelse($events as $e)
<tr class="{{ $e->optionExpired() || $e->readyToBill() ? 'row-warn' : '' }}">
  <td><a class="bold" href="{{ route('banquet.show', $e) }}">{{ $e->event_no }}</a> <span class="small">{{ $e->title }}</span><div class="tiny muted">{{ \App\Models\BanquetEvent::TYPES[$e->event_type] ?? ucfirst($e->event_type) }}{{ $e->salesUser ? ' · '.$e->salesUser->name : '' }}</div></td>
  <td class="small">{{ $e->company?->name ?? $e->contact_name }}@if($e->company)<div class="tiny muted">{{ $e->contact_name }}</div>@endif</td>
  <td class="small nowrap">{{ $e->event_date->format('D d M Y') }}@if($e->end_date && ! $e->end_date->eq($e->event_date))<div class="tiny muted">to {{ $e->end_date->format('d M') }}</div>@endif</td>
  <td class="small">{{ $e->functions->count() }} function(s)<div class="tiny muted">{{ $e->functions->pluck('hall.name')->unique()->join(', ') }}</div></td>
  <td class="num">{{ $e->pax() }}</td>
  <td class="num">{{ money($e->net(), false) }}</td>
  <td>{!! $e->statusBadge() !!}
    @if($e->status === 'tentative' && $e->option_date)<div class="tiny {{ $e->optionExpired() ? 'text-red bold' : ($e->option_date->lte(bdate()->copy()->addDays(3)) ? 'text-amber' : 'muted') }}">option {{ $e->option_date->format('d M') }}{{ $e->optionExpired() ? ' – expired' : '' }}</div>@endif
    @if($e->readyToBill())<div class="tiny text-red">to bill</div>@endif
    @if($e->status === 'lost' && $e->lost_reason)<div class="tiny muted">{{ \Illuminate\Support\Str::limit($e->lost_reason, 40) }}</div>@endif
  </td>
</tr>
@empty<tr><td colspan="7"><x-empty text="No events here." icon="star"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $events->links() }}
<p class="tiny muted mt-1">Enquiry → <b>tentative</b> (the venue is held until the option date, then released automatically) → <b>definite</b> (once the deposit is in) → billed after the event. Deposits are real payments on the event's folio, so the final bill only collects the balance.</p>
@endsection
