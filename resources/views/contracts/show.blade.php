@extends('layouts.app')
@section('title', 'Contract '.$c->code)
@section('crumbs') <a href="{{ route('contracts.index') }}">Rate Contracts</a> / {{ $c->code }} @endsection
@php $st = $c->state(); @endphp
@section('actions')
<a class="btn" href="{{ route('contracts.letter', $c) }}" id="letterBtn"><x-icon name="print"/> Agreement letter</a>
@if(can_do('contracts.manage') && $c->isEditable())<a class="btn" href="{{ route('contracts.edit', $c) }}" id="editContract"><x-icon name="edit"/> Edit</a>@endif
@if(can_do('contracts.manage') && in_array($st, ['active', 'expired', 'upcoming']))<button class="btn" data-modal="renewModal" id="renewBtn"><x-icon name="refresh"/> Renew</button>@endif
@if(can_do('contracts.approve') && $c->status === 'active')<button class="btn btn-danger" data-modal="suspendModal" id="suspendBtn"><x-icon name="x"/> Suspend</button>@endif
@if(can_do('contracts.approve') && in_array($c->status, ['draft', 'suspended']) && $st !== 'expired')
  <form method="post" action="{{ route('contracts.activate', $c) }}" class="inline" data-confirm="Approve and activate {{ $c->code }}? Its rates and allotments take effect immediately.">@csrf<button class="btn btn-primary" id="activateBtn"><x-icon name="check"/> Approve &amp; activate</button></form>
@endif
@if(can_do('contracts.manage') && $c->status === 'draft' && $prod['bookings'] === 0)
  <form method="post" action="{{ route('contracts.destroy', $c) }}" class="inline" data-confirm="Delete draft {{ $c->code }}?">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon" title="Delete draft"><x-icon name="trash"/></button></form>
@endif
@endsection
@section('content')
@if($c->status === 'draft')<div class="alert alert-warn" id="draftNote"><x-icon name="clock"/><div><b>Draft – awaiting approval.</b> Drafted by {{ $c->creator?->name ?? '—' }}. Bookings get public rates until an approver activates it.</div></div>
@elseif($c->status === 'suspended')<div class="alert alert-error"><x-icon name="alert"/><div><b>Suspended.</b> New bookings get public rates; existing bookings keep their price.</div></div>
@elseif($st === 'active' && $c->daysToExpiry() <= (int) setting('contract_expiry_alert_days', '60'))<div class="alert alert-warn" id="expiryNote"><x-icon name="clock"/><div>This contract ends in <b>{{ $c->daysToExpiry() }} day(s)</b> ({{ $c->valid_to->format('d M Y') }}).@if($renewals->isEmpty()) Draft the renewal now to avoid a gap.@else Renewal: @foreach($renewals as $r)<a href="{{ route('contracts.show', $r) }}">{{ $r->code }}</a> ({{ $r->status }}) @endforeach @endif</div></div>@endif
<div class="stats req5">
  <x-stat label="Bookings" :value="$prod['bookings']" icon="calendar" tone="blue" :sub="$prod['cancelled'].' cancelled'"/>
  <x-stat label="Room nights" :value="number_format($prod['nights'])" icon="moon" tone="purple" :sub="number_format($prod['stayed']).' stayed so far'"/>
  <x-stat label="Target" :value="$prod['target'] ? $prod['target_pct'].'%' : '—'" icon="trend" :tone="$prod['target'] && $prod['target_pct'] < $prod['pace_pct'] * .8 ? 'amber' : 'green'" :sub="$prod['target'] ? 'of '.number_format($prod['target']).' RN · '.$prod['pace_pct'].'% of period elapsed' : 'no target set'"/>
  <x-stat label="Room revenue" :value="money($prod['revenue'])" icon="money" tone="green" sub="booked, before tax"/>
  <x-stat label="ADR" :value="money($prod['adr'])" icon="chart" tone="slate" sub="average contract rate"/>
</div>
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Rates</h3><span class="small">{{ $c->basisLabel() }}</span></div><div class="card-body table-wrap">
      @if($c->pricing === 'net' && $c->rates->isNotEmpty())
      <table class="table" id="rateList"><thead><tr><th>Room type</th><th>Season</th><th class="num">Single</th><th class="num">Double</th><th class="num">Extra adult</th><th class="num">Public</th><th class="num">Saving</th></tr></thead><tbody>
      @foreach($c->rates->sortBy(fn ($r) => [$r->roomType?->base_rate, $r->season_from?->toDateString() ?? '']) as $r) @php $pub = (float) ($publicRates[$r->room_type_id] ?? 0); @endphp
        <tr><td>{{ $r->roomType?->name }}</td><td class="small">{{ $r->season_from ? ($r->season_name ?: 'Season').' · '.$r->season_from->format('d M').' – '.$r->season_to->format('d M Y') : 'All period' }}</td>
          <td class="num bold">{{ money($r->rate) }}</td><td class="num">{{ $r->double_rate ? money($r->double_rate) : '—' }}</td><td class="num">{{ $r->extra_adult_rate !== null ? money($r->extra_adult_rate) : '—' }}</td>
          <td class="num muted">{{ money($pub) }}</td><td class="num">{{ $pub > 0 ? round((1 - $r->rate / $pub) * 100).'%' : '—' }}</td></tr>
      @endforeach
      </tbody></table>
      @if($c->discount_pct > 0)<p class="small muted mt-1">Room types without a net line: {{ rtrim(rtrim((string) $c->discount_pct, '0'), '.') }}% off the public rate.</p>@endif
      @elseif($c->pricing === 'discount')<p><b>{{ rtrim(rtrim((string) $c->discount_pct, '0'), '.') }}% off</b> the public rate of the night (best available rate incl. seasons), all room types.</p>
      @else<x-empty text="No rate lines yet." icon="money"/>@endif
      @if($c->blackouts->isNotEmpty())<h4 class="mt-2">Blackout dates <span class="tiny muted">public rate applies</span></h4><ul class="list" id="blackList">@foreach($c->blackouts as $b)<li><span>{{ $b->date_from->format('d M Y') }} – {{ $b->date_to->format('d M Y') }}</span><span class="muted">{{ $b->reason }}</span></li>@endforeach</ul>@endif
    </div></div>

    <div class="card"><div class="card-head"><h3>Allotment – next 30 days</h3><span class="tiny muted">left / allotted per night · <span style="background:#e2e8f0;padding:0 .3rem;border-radius:3px">grey</span> = released to general sale</span></div><div class="card-body table-wrap">
      @if($c->allotments->isEmpty())<x-empty text="No allotment – bookings take rooms from general availability." icon="bed"/>
      @else
      <table class="table" id="allotGrid" style="font-size:.72rem"><thead><tr><th>Room type</th>@for($i = 0; $i < 30; $i++) @php $d = $gridFrom->copy()->addDays($i); @endphp<th class="center" style="padding:.2rem;{{ $d->isWeekend() ? 'background:#f8fafc' : '' }}">{{ $d->format('d') }}<div class="muted">{{ $d->format('D')[0] }}</div></th>@endfor</tr></thead><tbody>
      @foreach($c->allotments as $a)
        <tr><td class="nowrap">{{ $a->roomType?->name }}<div class="tiny muted">{{ $a->rooms }}/night · release {{ $a->release_days }}d</div></td>
        @foreach($grid[$a->id] ?? [] as $date => $cell)
          @if(! $cell)<td></td>
          @else @php $bg = $cell['released'] ? '#e2e8f0' : ($cell['left'] === 0 ? '#dcfce7' : ($cell['picked'] ? '#dbeafe' : '#fff')); @endphp
            <td class="center" style="padding:.2rem;background:{{ $bg }}" title="{{ $date }}: {{ $cell['picked'] }} picked up of {{ $cell['allotted'] }}{{ $cell['released'] ? ' (released)' : '' }}">{{ $cell['left'] }}<span class="muted">/{{ $cell['allotted'] }}</span></td>
          @endif
        @endforeach</tr>
      @endforeach
      </tbody></table>
      <table class="table mt-1" id="allotUsage"><thead><tr><th>Allotment</th><th>Period</th><th class="num">Allotted RN</th><th class="num">Picked up</th><th class="num">Released unsold</th><th class="num">Utilisation</th></tr></thead><tbody>
      @foreach($c->allotments as $a) @php $u = $usage[$a->id]; @endphp
        <tr><td>{{ $a->roomType?->name }} × {{ $a->rooms }}</td><td class="small">{{ $a->date_from->format('d M Y') }} – {{ $a->date_to->format('d M Y') }}</td><td class="num">{{ number_format($u['allotted']) }}</td><td class="num">{{ number_format($u['picked']) }}</td><td class="num">{{ number_format($u['released']) }}</td><td class="num bold">{{ $u['pct'] }}%</td></tr>
      @endforeach
      </tbody></table>
      @endif
    </div></div>

    <div class="card flush"><div class="card-head"><h3>Bookings on this contract</h3><span class="tiny muted">latest 25</span></div><div class="card-body table-wrap"><table class="table" id="contractBookings">
      <thead><tr><th>Reservation</th><th>Guest</th><th>Stay</th><th>Rooms</th><th class="num">Rate</th><th>Status</th></tr></thead><tbody>
      @forelse($bookings as $b)
        <tr><td>@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $b) }}">{{ $b->reservation_no }}</a>@else{{ $b->reservation_no }}@endif</td><td>{{ $b->guest?->full_name }}</td>
          <td class="small nowrap">{{ $b->arrival_date->format('d M') }} – {{ $b->departure_date->format('d M Y') }}</td>
          <td class="small">{{ $b->rooms->where('status', '!=', 'cancelled')->groupBy(fn ($s) => $s->roomType?->name)->map->count()->map(fn ($n, $t) => "$n × $t")->implode(', ') }}</td>
          <td class="num">{{ money($b->rooms->where('status', '!=', 'cancelled')->avg('rate') ?? 0) }}</td><td>{!! status_badge($b->status) !!}</td></tr>
      @empty<tr><td colspan="6"><x-empty text="No bookings yet." icon="calendar"/></td></tr>@endforelse
      </tbody></table></div></div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>{{ $c->name }}</h3>{!! status_badge($st) !!}</div><div class="card-body"><dl class="kv">
      <dt>Company</dt><dd>@if(can_do('credit.view'))<a href="{{ route('credit.show', $c->company) }}">{{ $c->company->name }}</a>@else{{ $c->company->name }}@endif</dd>
      <dt>Validity</dt><dd>{{ $c->valid_from->format('d M Y') }} – {{ $c->valid_to->format('d M Y') }}</dd>
      <dt>Meal plan</dt><dd>{{ \App\Models\RateContract::MEAL_PLANS[$c->meal_plan] ?? $c->meal_plan }}</dd>
      @if($c->inclusions)<dt>Inclusions</dt><dd>{{ $c->inclusions }}</dd>@endif
      <dt>Last-room avail.</dt><dd>{{ $c->lra ? 'Yes – ignores stop-sell & min stay' : 'No' }}</dd>
      <dt>Cancellation</dt><dd>Free until {{ $c->cancel_days }} day(s) before arrival</dd>
      <dt>Commission</dt><dd>{{ $c->commission_pct !== null ? rtrim(rtrim((string) $c->commission_pct, '0'), '.').'%' : 'Company default ('.rtrim(rtrim((string) $c->company->commission_pct, '0'), '.').'%)' }}</dd>
      <dt>Web code</dt><dd>{!! $c->access_code ? '<code>'.e($c->access_code).'</code>' : '<span class="muted">—</span>' !!}</dd>
      <dt>Drafted by</dt><dd>{{ $c->creator?->name ?? '—' }}</dd>
      <dt>Approved</dt><dd>{{ $c->approver ? $c->approver->name.' · '.$c->approved_at?->format('d M Y') : '—' }}</dd>
      @if($c->renewedFrom)<dt>Renewal of</dt><dd><a href="{{ route('contracts.show', $c->renewedFrom) }}">{{ $c->renewedFrom->code }}</a></dd>@endif
    </dl>
    @if($c->notes)<div class="small mt-1" style="white-space:pre-line;background:#f8fafc;border-radius:8px;padding:.5rem .7rem">{{ $c->notes }}</div>@endif
    </div></div>
  </div>
</div>

<div class="modal" id="suspendModal"><div class="modal-box"><form method="post" action="{{ route('contracts.suspend', $c) }}">@csrf
  <div class="modal-head"><h3>Suspend {{ $c->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small">New bookings will get public rates and the allotment is freed. Existing bookings keep their price.</p><x-field name="reason" label="Reason" required placeholder="e.g. Rates under renegotiation"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger">Suspend</button></div>
</form></div></div>
<div class="modal" id="renewModal"><div class="modal-box"><form method="post" action="{{ route('contracts.renew', $c) }}">@csrf
  <div class="modal-head"><h3>Renew {{ $c->code }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <p class="small span-all">Creates a <b>draft</b> for the following period (from {{ $c->valid_to->copy()->addDay()->format('d M Y') }}) with the same terms, seasons, blackouts and allotments shifted forward, and net rates raised by the uplift.</p>
    @php $nextCode = preg_match('/\d{2,4}(?!.*\d)/', $c->code) ? preg_replace_callback('/(\d{2,4})(?!.*\d)/', fn ($m) => str_pad((string) ((int) $m[1] + 1), strlen($m[1]), '0', STR_PAD_LEFT), $c->code) : $c->code.'-R'; @endphp
    <x-field name="code" label="New contract code" :value="$nextCode" required/>
    <x-field name="uplift" label="Rate uplift %" type="number" step="0.5" min="-50" max="100" value="5" required/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Draft renewal</button></div>
</form></div></div>
@endsection
