@extends('layouts.app')
@section('title', 'Properties')
@section('crumbs'){{ $filters['group'] ? 'Group account' : 'Portfolio' }} / Property register@endsection
@section('actions')
  <a class="btn" href="{{ route('properties.export', array_filter($filters)) }}"><x-icon name="download" size="sm"/> Export CSV</a>
  @if($canAdd->isNotEmpty())<a class="btn btn-primary" href="{{ route('properties.create', array_filter(['group' => $filters['group']])) }}"><x-icon name="plus" size="sm"/> Open a property</a>@endif
  @if(can_do('properties.manage'))<a class="btn" href="{{ route('group.account', array_filter(['group' => $filters['group']])) }}"><x-icon name="layers" size="sm"/> Group account</a>@endif
@endsection
@section('content')

<div class="stats">
  <x-stat label="Properties" :value="$summary['properties']" icon="building" tone="blue" :sub="$summary['live'].' live · '.$summary['draft'].' draft · '.$summary['suspended'].' suspended'" />
  <x-stat label="Sellable rooms" :value="number_format($summary['rooms'])" icon="bed" tone="teal" :sub="$summary['occupied'].' occupied now'" />
  <x-stat label="Occupancy" :value="$summary['occ'].'%'" icon="chart" tone="green" :sub="$summary['in_house'].' rooms in-house'" />
  <x-stat label="People with access" :value="number_format($summary['staff'])" icon="users" tone="purple" :sub="'Across every property you can see'" />
  <x-stat label="Licence coverage" :value="$summary['narrowed'].' of '.$summary['properties']" icon="key" :tone="$summary['closed'] ? 'red' : ($summary['narrowed'] ? 'amber' : 'slate')" :sub="$summary['closed'] ? $summary['closed'].' licensed for nothing · the rest follow the group' : 'Every property follows the group licence'" />
  <x-stat label="Night audit behind" :value="$summary['behind']" icon="clock" :tone="$summary['behind'] ? 'amber' : 'slate'" :sub="$summary['behind'] ? 'Business date not rolled forward' : 'Every live property is current'" />
</div>

<div class="card">
  <form method="get" action="{{ route('properties.index') }}" class="card-body flex flex-wrap items-end gap-2">
    <div class="field" style="min-width:240px"><label for="pq">Search</label><input id="pq" name="q" value="{{ $filters['q'] }}" placeholder="Name, Hotel ID, city or brand"></div>
    <div class="field"><label for="ps">State</label>
      <select id="ps" name="status" onchange="this.form.submit()">
        <option value="">Any state</option>
        @foreach($statuses as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach
      </select>
    </div>
    @if($groups->count() > 1)
    <div class="field"><label for="pg">Group</label>
      <select id="pg" name="group" onchange="this.form.submit()">
        <option value="">All groups</option>
        @foreach($groups as $g)<option value="{{ $g->id }}" @selected((int) $filters['group'] === (int) $g->id)>{{ $g->name }}</option>@endforeach
      </select>
    </div>
    @endif
    <button class="btn">Filter</button>
    @if($filters['q'] || $filters['status'] || $filters['group'])<a class="btn btn-ghost" href="{{ route('properties.index') }}">Clear</a>@endif
  </form>
</div>

<div class="card flush mt-2">
  <div class="card-head"><h3>Properties you can see</h3><span class="small muted">{{ $rows ? count($rows) : 0 }} shown · each property keeps its own business date, currency and clock</span></div>
  <div class="card-body table-wrap">
    @if(! $rows)
      <x-empty icon="building" text="No property matches this filter." />
    @else
    <table class="table">
      <thead><tr>
        <th>Property</th><th>State</th><th>Business date</th><th class="center">Rooms</th><th class="center">Occupancy</th>
        <th class="num">In-house</th><th class="num">Staff</th><th class="num">Open POS</th><th>Licence</th><th>Clock</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($rows as $r)
        @php $p = $r['hotel']; @endphp
        <tr class="{{ $p->isArchived() ? 'muted' : '' }}">
          <td style="min-width:230px">
            <b>{{ $p->name }}</b>
            @if($p->isHeadOffice())<span class="badge badge-blue" title="Head office">HQ</span>@endif
            <div class="tiny muted">{{ $p->code }} · {{ ucwords(str_replace('_', ' ', (string) $p->property_type)) }}{{ $p->city ? ' · '.$p->city : '' }}{{ $p->brand ? ' · '.$p->brand : '' }}</div>
            @if($p->status_reason)<div class="tiny muted" title="{{ $p->status_reason }}">{{ mb_substr($p->status_reason, 0, 60) }}{{ mb_strlen($p->status_reason) > 60 ? '…' : '' }}</div>@endif
          </td>
          <td><span class="badge badge-{{ $p->statusTone() }}">{{ $p->statusLabel() }}</span>
            @if((int) $p->id === (int) $currentHotel?->id)<div class="tiny muted">current</div>@endif
          </td>
          <td class="small">
            {{ $r['business_date'] ? $r['business_date']->format('d M Y') : '—' }}
            @if($r['needs_night_audit'])<div><span class="badge badge-amber">{{ $r['lag'] }}d behind</span></div>@endif
          </td>
          <td class="center">{{ $r['rooms'] }}</td>
          <td class="center">
            @if($r['rooms'])<div class="progress" title="{{ $r['occupied'] }} of {{ $r['rooms'] }}"><span style="width:{{ $r['occ'] }}%"></span></div><div class="tiny muted">{{ $r['occ'] }}%</div>@else<span class="muted">—</span>@endif
          </td>
          <td class="num">{{ $r['in_house'] }}</td>
          <td class="num">{{ $r['staff'] }}</td>
          <td class="num">{{ $r['open_pos'] ?: '—' }}</td>
          {{-- v10.7 what this property is licensed for, so coverage is a column and not a hunt --}}
          <td class="small">
            @if($r['licence']['closed'])
              <span class="badge badge-red">Nothing licensed</span>
              <div class="tiny muted">Closed: no module is licensed here, so no screen opens</div>
            @elseif($r['licence']['narrowed'])
              <span class="badge badge-amber">{{ $r['licence']['keep'] }} of {{ $r['licence']['of'] }} modules</span>
              <div class="tiny muted">{{ $r['licence']['missing'] }} switched off at this property</div>
            @else
              <span class="badge badge-slate">Follows the group</span>
              <div class="tiny muted">{{ $r['licence']['of'] }} module(s), as the group holds them</div>
            @endif
          </td>
          <td class="small">{{ $p->currency }} · {{ $p->timezone }}</td>
          <td class="right" style="white-space:nowrap">
            <a class="btn btn-sm" href="{{ route('properties.show', $p) }}">Open</a>
            @if($p->isLive() && (int) $p->id !== (int) $currentHotel?->id)
              <form method="post" action="{{ route('switch.hotel') }}" style="display:inline">@csrf<input type="hidden" name="hotel_id" value="{{ $p->id }}"><button class="btn btn-sm btn-ghost" title="Work in this property">Switch</button></form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

<p class="tiny muted mt-2">A property is never deleted. It moves from <b>draft</b> (being set up) to <b>live</b>, may be <b>suspended</b> and resumed, and finally <b>retired</b> — at which point its books stay readable and nobody new can be granted access. Suspending or retiring a property you are standing in is refused: switch away first.</p>
@endsection
