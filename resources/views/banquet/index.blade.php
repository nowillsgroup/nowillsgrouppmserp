@extends('layouts.app')
@section('title', 'Banquet Events & Enquiries')
@section('crumbs') Banquet / Events @endsection
@section('actions')
<a class="btn" href="{{ route('banquet.diary') }}"><x-icon name="calendar"/> Function diary</a>
@if(can_do('banquet.manage'))<a class="btn btn-primary" href="{{ route('banquet.create') }}" id="newEvent"><x-icon name="plus"/> New enquiry</a>@endif
@endsection
@section('content')
<div class="bq-page">
  <section class="bq-intro" aria-labelledby="bqIntroTitle">
    <div class="bq-intro-copy">
      <span class="bq-eyebrow"><x-icon name="calendar" size="sm"/> EVENT SALES WORKSPACE</span>
      <h2 id="bqIntroTitle">Every event, from first enquiry to final bill.</h2>
      <p>Keep client conversations, venue options and confirmed functions moving through one clear pipeline.</p>
    </div>
    <ol class="bq-journey" aria-label="Banquet event workflow">
      <li><span class="bq-journey-dot">1</span><span>Enquiry</span></li>
      <li><span class="bq-journey-dot">2</span><span>Option hold</span></li>
      <li><span class="bq-journey-dot">3</span><span>Definite</span></li>
      <li><span class="bq-journey-dot">4</span><span>Bill &amp; close</span></li>
    </ol>
  </section>

  <div class="stats bq-stats">
    <x-stat label="Definite business" :value="money($stats['definite'])" icon="check" tone="green" :sub="$stats['definite_n'].' open event(s), before tax'" href="?show=upcoming"/>
    <x-stat label="Tentative holds" :value="money($stats['tentative'])" icon="clock" :tone="$stats['expiring'] ? 'amber' : 'blue'" :sub="$stats['tentative_n'].' hold(s)'.($stats['expiring'] ? ' · '.$stats['expiring'].' option(s) due ≤ 3 days' : '')" href="?show=tentative"/>
    <x-stat label="Open enquiries" :value="$stats['enquiry_n']" icon="message" tone="slate" :sub="$stats['conversion'] !== null ? 'conversion '.$stats['conversion'].'% over 90 days' : 'no decisions in 90 days'" href="?show=enquiry"/>
    <x-stat label="Deposits held" :value="money($stats['deposits'])" icon="wallet" tone="purple" sub="on open events"/>
    <x-stat label="Ready to bill" :value="$stats['to_bill']" icon="receipt" :tone="$stats['to_bill'] ? 'red' : 'green'" sub="functions finished, not billed" href="?show=to_bill"/>
  </div>

  <nav class="tabs bq-tabs" aria-label="Banquet pipeline stage">
    @foreach(\App\Http\Controllers\BanquetController::TABS as $k => $v)
      <a href="?{{ http_build_query(array_filter(['show' => $k, 'q' => $search, 'type' => $type, 'sales' => $salesFilter])) }}" class="{{ $tab === $k ? 'active' : '' }}" @if($tab === $k) aria-current="page" @endif>{{ $v }}</a>
    @endforeach
  </nav>

  <section class="bq-filter-panel" aria-labelledby="bqFilterTitle">
    <div class="bq-filter-heading">
      <div><span class="bq-eyebrow">FIND AN EVENT</span><h2 id="bqFilterTitle">Search the pipeline</h2><p>Look up an event, client, company, phone or email.</p></div>
      @if($search !== '' || $type !== '' || $salesFilter !== null)
        <a class="btn btn-ghost bq-reset" href="?show={{ urlencode($tab) }}"><x-icon name="x"/> Reset filters</a>
      @endif
    </div>
    <form class="filters banquet-filters" method="get" role="search" aria-label="Filter banquet events">
      <input type="hidden" name="show" value="{{ $tab }}">
      <div class="field banquet-search"><label class="sr-only" for="banquetSearch">Search events, clients and contacts</label><div class="bq-searchbox"><x-icon name="search"/><input name="q" id="banquetSearch" class="input" type="search" maxlength="150" placeholder="Event number, title, client, phone or email" value="{{ $search }}"></div></div>
      <div class="field"><label class="sr-only" for="banquetType">Event type</label><select name="type" id="banquetType" class="input"><option value="">All event types</option>@foreach(\App\Models\BanquetEvent::TYPES as $k => $v)<option value="{{ $k }}" @selected($type === $k)>{{ $v }}</option>@endforeach</select></div>
      <div class="field"><label class="sr-only" for="banquetSales">Sales person</label><select name="sales" id="banquetSales" class="input"><option value="">All sales people</option>@foreach($sales as $id => $n)<option value="{{ $id }}" @selected((string) $salesFilter === (string) $id)>{{ $n }}</option>@endforeach</select></div>
      <button class="btn btn-primary"><x-icon name="search"/> Apply filters</button>
    </form>
  </section>

  <div class="bq-list-summary" role="status" aria-live="polite">
    @if($events->total())
      <span>Showing <b>{{ $events->firstItem() }}–{{ $events->lastItem() }}</b> of <b>{{ number_format($events->total()) }}</b> in <b>{{ \App\Http\Controllers\BanquetController::TABS[$tab] }}</b>.</span>
    @else
      <span>No events match this stage and filter combination.</span>
    @endif
    <span class="bq-result-count">{{ number_format($events->total()) }} {{ $events->total() === 1 ? 'event' : 'events' }}</span>
  </div>

  <section class="card flush bq-table-card" aria-labelledby="bqTableTitle">
    <div class="bq-table-head"><div><h2 id="bqTableTitle">{{ \App\Http\Controllers\BanquetController::TABS[$tab] }}</h2><p>Open an event to manage its team, functions, proposal and payments.</p></div><span class="badge badge-slate">{{ number_format($events->total()) }} total</span></div>
    <div class="card-body table-wrap"><table class="table" id="eventTable">
      <caption class="sr-only">Banquet events and enquiries for the selected pipeline stage</caption>
      <thead><tr><th scope="col">Event</th><th scope="col">Client</th><th scope="col">Event date(s)</th><th scope="col">Functions &amp; venues</th><th scope="col" class="num">Peak guests</th><th scope="col" class="num">Est. net</th><th scope="col">Pipeline stage</th></tr></thead>
      <tbody>
      @forelse($events as $e)
        <tr class="bq-event-row {{ $e->optionExpired() || $e->readyToBill() ? 'row-warn' : '' }}">
          <td data-label="Event"><a class="bq-event-title" href="{{ route('banquet.show', $e) }}" aria-label="Open {{ $e->event_no }}: {{ $e->title ?: $e->contact_name }}">{{ $e->title ?: 'Untitled event' }}<x-icon name="chev" size="sm"/></a><div class="bq-event-meta"><span class="bq-event-number">{{ $e->event_no }}</span><span>{{ \App\Models\BanquetEvent::TYPES[$e->event_type] ?? ucfirst($e->event_type) }}</span>@if($e->salesUser)<span>{{ $e->salesUser->name }}</span>@endif</div></td>
          <td class="small" data-label="Client">{{ $e->company?->name ?? $e->contact_name }}@if($e->company)<div class="tiny muted">Contact: {{ $e->contact_name }}</div>@endif @if($e->contact_phone)<div class="tiny"><a href="tel:{{ preg_replace('/[^0-9+]/', '', $e->contact_phone) }}" aria-label="Call {{ $e->contact_name }} at {{ $e->contact_phone }}">{{ $e->contact_phone }}</a></div>@endif @if($e->contact_email)<div class="tiny"><a href="mailto:{{ $e->contact_email }}">{{ $e->contact_email }}</a></div>@endif</td>
          <td class="small" data-label="Event date(s)"><span class="nowrap">{{ $e->event_date->format('D d M Y') }}</span>@if($e->start_time)<div class="tiny muted">Starts {{ substr($e->start_time, 0, 5) }}</div>@endif @if($e->end_date && ! $e->end_date->eq($e->event_date))<div class="tiny muted">through {{ $e->end_date->format('d M Y') }}</div>@endif</td>
          <td class="small" data-label="Functions &amp; venues"><b>{{ $e->functions->count() }} {{ $e->functions->count() === 1 ? 'function' : 'functions' }}</b><div class="tiny muted">{{ $e->functions->pluck('hall.name')->unique()->join(', ') ?: 'Venue not set' }}</div></td>
          <td class="num" data-label="Peak guests"><b>{{ number_format($e->pax()) }}</b></td>
          <td class="num" data-label="Estimated net"><b>{{ money($e->net(), false) }}</b><span class="tiny muted bq-value-note">before tax</span></td>
          <td data-label="Pipeline stage">{!! $e->statusBadge() !!}
            @if($e->status === 'tentative' && $e->option_date)<div class="tiny {{ $e->optionExpired() ? 'text-red bold' : ($e->option_date->lte(bdate()->copy()->addDays(3)) ? 'text-amber' : 'muted') }}">{{ $e->optionExpired() ? 'Option expired' : 'Option until' }} {{ $e->option_date->format('d M Y') }}</div>@endif
            @if($e->readyToBill())<div class="tiny text-red bold">Ready to bill</div>@endif
            @if($e->status === 'lost' && $e->lost_reason)<div class="tiny muted">{{ mb_substr($e->lost_reason, 0, 40) }}</div>@endif
          </td>
        </tr>
      @empty
        <tr class="bq-empty-row"><td colspan="7"><div class="bq-empty"><x-empty :text="$search !== '' || $type !== '' || $salesFilter !== null ? 'No events match your filters.' : 'Nothing in this pipeline stage yet.'" icon="calendar"/>@if($search !== '' || $type !== '' || $salesFilter !== null)<a class="btn btn-sm" href="?show={{ urlencode($tab) }}">Clear filters</a>@elseif(can_do('banquet.manage'))<a class="btn btn-sm btn-primary" href="{{ route('banquet.create') }}"><x-icon name="plus"/> Create an enquiry</a>@endif</div></td></tr>
      @endforelse
      </tbody>
    </table></div>
  </section>
  {{ $events->links() }}

  <aside class="bq-workflow-note" aria-label="Banquet booking workflow note"><x-icon name="book"/><p><b>Workflow:</b> An enquiry is non-binding. A tentative option holds the venue until its expiry date; confirming it makes the booking definite. Deposits are recorded against the event folio, and final billing collects the remaining balance.</p></aside>
</div>
@endsection
