@extends('layouts.app')
@section('title', 'Reservation Settings')
@section('crumbs') Point of Sale / Reservation settings @endsection
@section('actions')
@if($outlet && $outlet->tb_enabled)<a class="btn" href="{{ route('tb.floor', ['outlet' => $outlet->id]) }}"><x-icon name="grid"/> Floor plan</a>@endif
@if($outlet && $outlet->tb_online)<a class="btn" href="{{ route('dine.show', $currentHotel->code) }}" target="_blank" id="publicLink"><x-icon name="globe"/> Public booking page</a>@endif
@endsection
@section('content')
@if(!$outlet)
  <div class="alert alert-warn"><x-icon name="alert"/><div>Create a restaurant, bar or café outlet first (<a href="{{ route('masters.index', 'outlets') }}">Outlets</a>).</div></div>
@else
<div class="tabs">@foreach($outlets as $o)<a href="{{ route('tb.settings', ['outlet' => $o->id]) }}" class="{{ $o->id === $outlet->id ? 'active' : '' }}">{{ $o->name }} @if($o->tb_enabled)<span class="badge badge-green">on</span>@endif</a>@endforeach</div>
@php
  $durs = collect($cfg['durations'])->map(fn ($m, $p) => ['party' => $p, 'minutes' => $m])->values()->all();
  $durs = array_merge($durs, [['party' => '', 'minutes' => ''], ['party' => '', 'minutes' => '']]);
  $pers = $periods->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'start_time' => substr($p->start_time, 0, 5), 'end_time' => substr($p->end_time, 0, 5), 'days' => str_split($p->days), 'is_active' => $p->is_active])->all();
  $pers = array_merge($pers, [['id' => '', 'name' => '', 'start_time' => '', 'end_time' => '', 'days' => str_split('1234567'), 'is_active' => true]]);
@endphp
<form method="post" action="{{ route('tb.settings.save') }}" id="tbSettings">@csrf<input type="hidden" name="outlet_id" value="{{ $outlet->id }}">
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Reservations for {{ $outlet->name }}</h3></div><div class="card-body">
    <label class="check"><input type="hidden" name="tb_enabled" value="0"><input type="checkbox" name="tb_enabled" value="1" id="f_tb_enabled" @checked(old('tb_enabled', $outlet->tb_enabled))> <b>Take table reservations</b> – floor plan, bookings and waitlist</label>
    <label class="check mt-1"><input type="hidden" name="tb_online" value="0"><input type="checkbox" name="tb_online" value="1" id="f_tb_online" @checked(old('tb_online', $outlet->tb_online))> <b>Online booking</b> on <span class="small">{{ route('dine.show', $currentHotel->code) }}</span></label>
    <label class="check mt-1"><input type="hidden" name="auto_confirm_online" value="0"><input type="checkbox" name="auto_confirm_online" value="1" @checked(old('auto_confirm_online', $cfg['auto_confirm_online']))> Confirm online bookings automatically (otherwise a host confirms each one)</label>
    <label class="check mt-1"><input type="hidden" name="auto_no_show" value="0"><input type="checkbox" name="auto_no_show" value="1" @checked(old('auto_no_show', $cfg['auto_no_show']))> Mark late parties as no-show automatically after the hold time</label>
    <div class="form-grid mt-2">
      <x-field name="slot_minutes" label="Booking interval" type="select" :options="[10 => 'Every 10 min', 15 => 'Every 15 min', 20 => 'Every 20 min', 30 => 'Every 30 min', 60 => 'Every hour']" :value="old('slot_minutes', $cfg['slot_minutes'])" :placeholder="false"/>
      <x-field name="buffer" label="Reset time between parties (min)" type="number" :value="old('buffer', $cfg['buffer'])" required/>
      <x-field name="hold_minutes" label="Hold a late table for (min)" type="number" :value="old('hold_minutes', $cfg['hold_minutes'])" required/>
      <x-field name="reminder_hours" label="Reminder before (hours, 0 = off)" type="number" :value="old('reminder_hours', $cfg['reminder_hours'])"/>
      <x-field name="min_notice" label="Online: minimum notice (min)" type="number" :value="old('min_notice', $cfg['min_notice'])" required/>
      <x-field name="days_ahead" label="Online: bookable days ahead" type="number" :value="old('days_ahead', $cfg['days_ahead'])" required/>
      <x-field name="max_online_party" label="Online: largest party" type="number" :value="old('max_online_party', $cfg['max_online_party'])" required hint="bigger groups are asked to call"/>
      <x-field name="pacing_covers" label="Online: max covers per interval" type="number" :value="old('pacing_covers', $cfg['pacing_covers'])" hint="0 = no limit – protects the kitchen"/>
      <x-field name="noshow_flag" label="Flag guests after N no-shows" type="number" :value="old('noshow_flag', $cfg['noshow_flag'])" hint="0 = never"/>
    </div>
    <x-field name="online_note" label="Note on the booking page" type="textarea" :value="old('online_note', $cfg['online_note'])" placeholder="e.g. Smart casual. Tables are held for 15 minutes."/>
  </div></div>

  <div>
    <div class="card"><div class="card-head"><h3>Service periods</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="periodTable">
      <thead><tr><th>Name</th><th>First seating</th><th>Last seating</th><th>Days</th><th>On</th></tr></thead><tbody>
      @foreach($pers as $i => $p)
        <tr><td><input type="hidden" name="periods[{{ $i }}][id]" value="{{ $p['id'] }}"><input class="input input-sm" name="periods[{{ $i }}][name]" value="{{ $p['name'] }}" placeholder="{{ $p['id'] ? '' : 'e.g. Brunch' }}" style="width:110px"></td>
          <td><input class="input input-sm" type="time" name="periods[{{ $i }}][start_time]" value="{{ $p['start_time'] }}"></td><td><input class="input input-sm" type="time" name="periods[{{ $i }}][end_time]" value="{{ $p['end_time'] }}"></td>
          <td><div class="flex" style="flex-wrap:wrap;gap:.15rem .4rem;max-width:210px">@foreach(\App\Models\OutletServicePeriod::DAYS as $d => $dl)<label class="tiny nowrap"><input type="checkbox" name="periods[{{ $i }}][days][]" value="{{ $d }}" @checked(in_array((string) $d, $p['days']))>{{ substr($dl, 0, 2) }}</label>@endforeach</div></td>
          <td><input type="hidden" name="periods[{{ $i }}][is_active]" value="0"><input type="checkbox" name="periods[{{ $i }}][is_active]" value="1" @checked($p['is_active'])></td></tr>
      @endforeach
      </tbody></table><p class="tiny muted">Empty rows are ignored; clear a name and times to remove a period.</p></div></div>

    <div class="card"><div class="card-head"><h3>Dining time by party size</h3></div><div class="card-body"><table class="table table-compact" id="durTable">
      <thead><tr><th>Party up to</th><th>Minutes at the table</th></tr></thead><tbody>
      @foreach($durs as $i => $d)<tr><td><input class="input input-sm" type="number" name="durations[{{ $i }}][party]" value="{{ $d['party'] }}" min="1" max="99" style="width:90px"> guests</td><td><input class="input input-sm" type="number" name="durations[{{ $i }}][minutes]" value="{{ $d['minutes'] }}" min="30" max="360" style="width:90px"> min</td></tr>@endforeach
      </tbody></table><p class="tiny muted">Tables are held this long (plus the reset time). The largest row covers bigger parties.</p></div></div>
  </div>
</div>

<div class="card"><div class="card-head"><h3>Tables</h3><span class="muted small">Position them on the <a href="{{ route('tb.floor', ['outlet' => $outlet->id]) }}">floor plan</a> (Edit layout).</span></div><div class="card-body table-wrap"><table class="table table-compact" id="tableProps">
  <thead><tr><th>Table</th><th>Section</th><th>Seats</th><th>Min. party</th><th>Shape</th><th>Can join neighbours</th><th>Bookable online</th></tr></thead><tbody>
  @forelse($tables as $t)
    <tr class="{{ $t->is_active ? '' : 'muted-row' }}"><td class="bold">{{ $t->table_no }}@unless($t->is_active) <span class="badge badge-slate">inactive</span>@endunless</td>
      <td><input class="input input-sm" name="tables[{{ $t->id }}][section]" value="{{ $t->section }}" style="width:110px"></td>
      <td><input class="input input-sm" type="number" name="tables[{{ $t->id }}][capacity]" value="{{ $t->capacity }}" min="1" max="30" style="width:70px"></td>
      <td><input class="input input-sm" type="number" name="tables[{{ $t->id }}][min_capacity]" value="{{ $t->min_capacity }}" min="1" max="30" style="width:70px"></td>
      <td><select class="input input-sm" name="tables[{{ $t->id }}][shape]">@foreach(['square' => 'Square', 'round' => 'Round', 'rect' => 'Long', 'booth' => 'Booth'] as $k => $l)<option value="{{ $k }}" @selected($t->shape === $k)>{{ $l }}</option>@endforeach</select></td>
      <td><input type="hidden" name="tables[{{ $t->id }}][combinable]" value="0"><input type="checkbox" name="tables[{{ $t->id }}][combinable]" value="1" @checked($t->combinable)></td>
      <td><input type="hidden" name="tables[{{ $t->id }}][reservable]" value="0"><input type="checkbox" name="tables[{{ $t->id }}][reservable]" value="1" @checked($t->reservable)></td></tr>
  @empty<tr><td colspan="7"><x-empty text="No tables yet – add them under POS › Tables." icon="grid"/></td></tr>@endforelse
</tbody></table></div>
  <div class="card-foot"><button class="btn btn-primary" id="saveTbSettings"><x-icon name="check"/> Save settings</button></div></div>
</form>
@endif
@endsection
