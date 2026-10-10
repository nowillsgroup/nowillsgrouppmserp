@extends('layouts.app')
@section('title', 'Rate Management')
@section('crumbs') <a href="{{ route('reservations.index') }}">Reservations</a> / Rate Calendar @endsection
@section('actions')
<form class="filters" method="get"><div class="field"><input type="date" name="from" value="{{ $from->toDateString() }}" data-autosubmit></div>
<div class="field"><select name="days" data-autosubmit>@foreach([14,21,30,45,60] as $d)<option value="{{ $d }}" @selected($days==$d)>{{ $d }} days</option>@endforeach</select></div></form>
<a class="btn" href="{{ route('rates.index', ['from' => $from->copy()->subDays($days)->toDateString(), 'days' => $days]) }}">‹</a>
<a class="btn" href="{{ route('rates.index', ['from' => $from->copy()->addDays($days)->toDateString(), 'days' => $days]) }}">›</a>
<button class="btn btn-primary" data-modal="rateModal"><x-icon name="plus"/> Bulk update rates</button>
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap">
<table class="table table-compact avail ratecal">
  <thead><tr><th>Room Type</th>@for($i=0;$i<$days;$i++) @php $d = $from->copy()->addDays($i); @endphp<th class="center {{ in_array($d->dayOfWeek, \App\Services\RateService::WEEKEND) ? 'text-amber' : '' }}">{{ $d->format('D') }}<br>{{ $d->format('d/m') }}</th>@endfor</tr></thead>
  <tbody>
  @foreach($types as $t)
    <tr><td class="bold nowrap">{{ $t->name }}<div class="tiny muted">Base {{ money($t->base_rate) }}</div></td>
    @foreach($grid[$t->id] as $date => $c)
      @php $cls = $c['stop'] ? 'low' : ($c['rate'] > $t->base_rate ? 'mid' : ($c['rate'] < $t->base_rate ? 'ok' : '')); @endphp
      <td class="center {{ $cls }}" title="{{ $c['season'] ? $c['season'].' · ' : '' }}{{ \Illuminate\Support\Carbon::parse($date)->format('D d M') }}{{ $c['min_stay'] > 1 ? ' · min '.$c['min_stay'].' nights' : '' }}{{ $c['stop'] ? ' · STOP SELL' : '' }}"
          data-modal="rateModal" data-fdate_from="{{ $date }}" data-fdate_to="{{ $date }}" data-type="{{ $t->id }}" style="cursor:pointer">
        <span class="{{ $c['override'] ? 'bold' : '' }}">{{ number_format($c['rate'], 0) }}</span>@if(($c['source'] ?? null) === 'rm' || !empty($c['dynamic']))<sup class="rm-tag" title="Set by revenue management">RM</sup>@elseif(!empty($c['fixed']))<sup class="rm-tag fixed" title="Fixed price – revenue management never changes it">🔒</sup>@endif
        @if($c['stop'])<div class="tiny">✕ closed</div>@elseif($c['min_stay'] > 1)<div class="tiny">min {{ $c['min_stay'] }}</div>@endif
      </td>
    @endforeach</tr>
  @endforeach
  </tbody>
</table>
</div></div>
<div class="legend"><span><i style="background:#fffbeb;border:1px solid #fcd34d"></i>Above base rate</span><span><i style="background:#f0fdf4;border:1px solid #86efac"></i>Discounted</span><span><i style="background:#fef2f2;border:1px solid #fca5a5"></i>Stop-sell (closed online)</span><span><b>Bold</b> = seasonal override. Click any cell to change that date.</span><span><sup class="rm-tag">RM</sup> revenue-managed{{ $rmMode === 'auto' ? ' (auto-pilot, live)' : '' }}</span><span>🔒 fixed price</span></div>
@if($rmCount || $rmMode !== 'off')
<div class="alert alert-info mt-2"><x-icon name="trend" size="sm"/> Revenue management is <b>{{ ['off' => 'off', 'recommend' => 'in recommend mode', 'auto' => 'on auto-pilot'][$rmMode] }}</b>{{ $rmCount ? ' · '.$rmCount.' published day rate(s) are not listed below' : '' }}.
@if(can_do('revenue.view'))<a href="{{ route('revenue.pricing') }}">Open the pricing grid →</a>@endif</div>
@endif

<div class="card mt-2"><div class="card-head"><h3>Active &amp; upcoming rate rules</h3><span class="muted small">Newest rule wins where dates overlap</span></div>
<div class="card-body table-wrap"><table class="table">
<thead><tr><th>Season / rule</th><th>Room type</th><th>From</th><th>To</th><th class="num">Rate</th><th class="num">Fri/Sat rate</th><th class="center">Min stay</th><th class="center">Stop-sell</th><th>By</th><th></th></tr></thead>
<tbody>
@forelse($overrides as $o)
<tr><td class="bold">{{ $o->name }}@if($o->is_fixed) <span class="badge badge-slate" title="Revenue management never changes these dates">🔒 Fixed</span>@endif</td><td>{{ $o->roomType->name }}</td><td>{{ dmy($o->date_from) }}</td><td>{{ dmy($o->date_to) }}</td>
<td class="num">{{ $o->rate !== null ? money($o->rate) : '—' }}</td><td class="num">{{ $o->weekend_rate !== null ? money($o->weekend_rate) : '—' }}</td>
<td class="center">{{ $o->min_stay }}</td><td class="center">{!! $o->stop_sell ? '<span class="badge badge-red">Closed</span>' : '—' !!}</td><td class="small muted">{{ $o->creator?->name }}</td>
<td class="right"><form method="post" action="{{ route('rates.destroy', $o) }}" onsubmit="return confirm('Remove this rate rule?')">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Remove"><x-icon name="trash" size="sm"/></button></form></td></tr>
@empty
<tr><td colspan="10"><x-empty title="No seasonal rates" text="All dates use the base rate of each room type. Use “Bulk update rates” to add a season, weekend pricing or stop-sell."/></td></tr>
@endforelse
</tbody></table></div></div>

<div class="modal" id="rateModal"><div class="modal-box"><form method="post" action="{{ route('rates.store') }}">@csrf
  <div class="modal-head"><h3>Bulk update rates</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="name" label="Season / rule name" required placeholder="e.g. Christmas peak, Weekend promo" class="span-2"/>
    <div class="field span-2"><label>Room types <span class="req">*</span></label>
      <div class="flex wrap gap-1">@foreach($types as $t)<label class="check"><input type="checkbox" name="room_type_ids[]" value="{{ $t->id }}" class="rt-box" @checked(in_array($t->id, old('room_type_ids', [])))> {{ $t->name }}</label>@endforeach
      <button type="button" class="btn btn-sm btn-ghost" onclick="document.querySelectorAll('.rt-box').forEach(c => c.checked = true)">All</button></div>
      @error('room_type_ids')<span class="err">{{ $message }}</span>@enderror</div>
    <x-field name="date_from" label="From date" type="date" required/>
    <x-field name="date_to" label="To date (inclusive)" type="date" required/>
    <x-field name="rate" label="Nightly rate" type="number" step="0.01" min="0" hint="Leave blank to keep base rate"/>
    <x-field name="weekend_rate" label="Fri / Sat night rate" type="number" step="0.01" min="0" hint="Optional weekend price"/>
    <x-field name="min_stay" label="Minimum stay (nights)" type="number" min="1" max="30" value="1"/>
    <div class="field"><label>&nbsp;</label><label class="check"><input type="hidden" name="stop_sell" value="0"><input type="checkbox" name="stop_sell" value="1"> Stop-sell (close online booking)</label></div>
    <div class="field span-2"><label class="check"><input type="hidden" name="is_fixed" value="0"><input type="checkbox" name="is_fixed" value="1"> Fixed price – exclude these dates from revenue management (contract / event rate)</label></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Apply</button></div>
</form></div></div>
@endsection
@push('scripts')
<script>
// clicking a calendar cell pre-ticks that room type
document.querySelectorAll('.ratecal td[data-type]').forEach(td => td.addEventListener('click', () => {
  document.querySelectorAll('.rt-box').forEach(c => c.checked = c.value === td.dataset.type);
  document.querySelector('#rateModal [name=name]').value ||= 'Rate change';
}));
@if($errors->any()) document.getElementById('rateModal').classList.add('show'); @endif
</script>
@endpush
