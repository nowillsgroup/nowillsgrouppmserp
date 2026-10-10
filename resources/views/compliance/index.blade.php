@extends('layouts.app')
@section('title', 'Licences & Compliance')
@section('crumbs') Engineering / Licences & Compliance / {{ \App\Http\Controllers\ComplianceController::TABS[$tab] }} @endsection
@section('actions')
  <a class="btn" href="{{ route('compliance.print') }}" target="_blank"><x-icon name="print"/> Print register</a>
  @if(can_do('compliance.manage'))<button class="btn btn-primary" data-modal="itemModal" id="addItemBtn"><x-icon name="plus"/> Add licence / item</button>@endif
@endsection
@section('content')
@php $s = $snap; $c = $s['counts']; @endphp
@if($s['critical_expired'])<div class="alert alert-error" id="criticalAlert"><x-icon name="alert"/><div><b>{{ $s['critical_expired'] }} critical licence(s) expired</b> – operating without them risks penalties or closure: {{ $s['attention']->filter(fn ($i) => $i->is_critical && $i->state() === 'expired')->pluck('name')->implode(', ') }}.</div></div>@endif
@if($s['total'] === 0)<div class="alert alert-info"><x-icon name="book"/><div>The register is empty. @if(can_do('compliance.manage'))<form method="post" action="{{ route('compliance.template') }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary">Load the standard hotel checklist</button></form> – FSSAI, Fire NOC, trade, bar, pollution, police, lift, boiler, music licences, insurance and recurring inspections.@endif</div></div>@endif

<div class="stats">
  <x-stat label="Expired / overdue" :value="$c['expired']" icon="alert" :tone="$c['expired'] ? 'red' : 'green'" :href="route('compliance.index', ['state' => 'expired'])" sub="renew immediately"/>
  <x-stat label="Due for renewal" :value="$c['due']" icon="clock" :tone="$c['due'] ? 'amber' : 'green'" :href="route('compliance.index', ['state' => 'due'])" sub="inside the renewal window"/>
  <x-stat label="Renewal applied" :value="$c['renewing']" icon="refresh" tone="blue" :href="route('compliance.index', ['state' => 'renewing'])" sub="awaiting the authority"/>
  <x-stat label="Valid" :value="$c['valid'] + $c['perpetual']" icon="check" tone="green" :href="route('compliance.index', ['state' => 'valid'])" :sub="$s['total'].' items tracked'"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\ComplianceController::TABS as $k => $l)
      @if($k !== 'settings' || can_do('compliance.manage'))<a href="{{ route('compliance.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}</a>@endif
    @endforeach
  </div></div>

@if($tab === 'register')
  <div class="card-body" style="padding-bottom:.4rem">
    <form method="get" class="flex" style="gap:.5rem;flex-wrap:wrap" id="compFilter">
      <input class="input" name="q" value="{{ request('q') }}" placeholder="Name, authority or reference" style="max-width:260px">
      <select class="input" style="width:auto" name="state"><option value="">All (active)</option>@foreach(\App\Models\ComplianceItem::STATES as $k => [$l])<option value="{{ $k }}" @selected(request('state') === $k)>{{ $l }}</option>@endforeach</select>
      <select class="input" style="width:auto" name="category"><option value="">All categories</option>@foreach(\App\Models\ComplianceItem::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $l }}</option>@endforeach</select>
      <select class="input" style="width:auto" name="department"><option value="">All departments</option>@foreach(\App\Models\ComplianceItem::DEPARTMENTS as $d)<option @selected(request('department') === $d)>{{ $d }}</option>@endforeach</select>
      <button class="btn"><x-icon name="search"/> Filter</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table" id="compTable">
    <thead><tr><th>Item</th><th>Category</th><th>Authority</th><th>Reference</th><th>Valid until / next due</th><th class="right">Days</th><th>Status</th><th>Owner</th></tr></thead>
    <tbody>
    @forelse($items as $i)
      @php $st = $i->state(); $dl = $i->daysLeft(); @endphp
      <tr data-state="{{ $st }}">
        <td><a href="{{ route('compliance.show', $i) }}"><b>{{ $i->name }}</b></a>@if($i->is_critical) <span class="badge badge-red" title="Operating without it risks penalties or closure">Critical</span>@endif
          <div class="tiny muted">{{ $i->department }}@if($i->isRecurring()) · every {{ $i->frequency_months }} month(s)@endif</div></td>
        <td class="small">{{ $i->categoryLabel() }}</td>
        <td class="small">{{ $i->authority }}</td>
        <td class="small mono">{{ $i->reference_no ?: '—' }}</td>
        <td class="nowrap">{{ $i->expires_on?->format('d M Y') ?? '—' }}</td>
        <td class="right {{ $dl !== null && $dl < 0 ? 'text-red' : '' }}">{{ $dl ?? '' }}</td>
        <td><span class="badge badge-{{ $i->stateColor() }}">{{ $i->stateLabel() }}</span>@if($st === 'renewing' && $i->applied_on)<div class="tiny muted">applied {{ $i->applied_on->format('d M') }}</div>@endif</td>
        <td class="small">{{ $i->owner?->name ?? '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="muted center">Nothing matches.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  @if(can_do('compliance.manage') && $s['total'])<div class="card-body flex" style="gap:.5rem;justify-content:flex-end">
    <form method="post" action="{{ route('compliance.template') }}">@csrf<button class="btn btn-sm btn-ghost">Add missing standard items</button></form>
    <form method="post" action="{{ route('compliance.alerts') }}">@csrf<button class="btn btn-sm" id="runAlertsBtn"><x-icon name="mail"/> Send reminders now</button></form>
  </div>@endif

@elseif($tab === 'calendar')
  <div class="card-body">
    <p class="muted small" style="margin-top:0">Expiries and inspection due dates for the next 12 months · renewal fees due in this window: <b>{{ money($yearFees) }}</b></p>
    @if($overdue->count())<div class="alert alert-error"><x-icon name="alert"/><div><b>Overdue:</b> @foreach($overdue as $i)<a href="{{ route('compliance.show', $i) }}">{{ $i->name }}</a> ({{ $i->expires_on->format('d M') }})@if(! $loop->last), @endif @endforeach</div></div>@endif
    <div class="cal-grid" id="compCalendar">
      @foreach($months as $k => $mo)
        <div class="cal-month"><h4>{{ $mo['label'] }} <span class="muted small">{{ $mo['items']->count() ?: '' }}</span></h4>
          @foreach($mo['items'] as $i)<div class="it"><a href="{{ route('compliance.show', $i) }}">{{ $i->name }}</a><span class="nowrap"><span class="badge badge-{{ $i->stateColor() }}">{{ $i->expires_on->format('d') }}</span></span></div>@endforeach
          @if(! $mo['items']->count())<div class="tiny muted">—</div>@endif
        </div>
      @endforeach
    </div>
  </div>

@elseif($tab === 'settings')
  <form method="post" action="{{ route('compliance.settings') }}" class="card-body" id="compSettings">@csrf
    <div class="form-grid">
      <x-field name="lead_days" label="Default renewal lead time (days before expiry)" type="number" min="1" max="365" :value="$cfg['lead_days']" required/>
      <x-field name="digest_to" label="Also e-mail the daily digest to" :value="$cfg['digest_to']" placeholder="gm@hotel.com, engineering@hotel.com" class="span-2" :hint="'Empty = the hotel address ('.($currentHotel->email ?? '—').')'"/>
    </div>
    <p class="small muted">Every morning the <code>pms:engineering</code> job e-mails each item's owner when it enters its renewal window and again if it lapses, and writes a log-book entry for the duty managers (urgent when a critical licence has expired).</p>
    <div class="right"><button class="btn btn-primary">Save settings</button></div>
  </form>
@endif
</div>

@if(can_do('compliance.manage'))
<div class="modal" id="itemModal"><div class="modal-box" style="max-width:760px"><form method="post" action="{{ route('compliance.store') }}" enctype="multipart/form-data" data-reset>@csrf
  <div class="modal-head"><h3>Add licence / compliance item</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('compliance._form', ['i' => null])
    <x-field name="file" label="Document (PDF / image)" type="file" accept=".pdf,image/*" class="span-2"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Add to register</button></div>
</form></div></div>
@endif
@endsection
