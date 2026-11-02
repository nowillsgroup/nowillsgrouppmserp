@extends('layouts.app')
@section('title', 'Rate Calendar')
@section('crumbs') <a href="{{ route('reservations.index') }}">Reservations</a> / Rate Calendar @endsection
@section('actions')
<form class="rc-view-controls" method="get" aria-label="Rate calendar date range">
  <label class="rc-control"><span>Start date</span><input type="date" name="from" value="{{ $from->toDateString() }}" aria-label="Calendar start date" data-autosubmit></label>
  <label class="rc-control"><span>Window</span><select name="days" aria-label="Calendar window length" data-autosubmit>@foreach([14,21,30,45,60] as $d)<option value="{{ $d }}" @selected($days==$d)>{{ $d }} days</option>@endforeach</select></label>
</form>
<a class="btn btn-icon rc-nav-btn" href="{{ route('rates.index', ['from' => $from->copy()->subDays($days)->toDateString(), 'days' => $days]) }}" aria-label="Previous {{ $days }} days" title="Previous {{ $days }} days"><x-icon name="chev" class="rc-chevron-left"/></a>
<a class="btn btn-icon rc-nav-btn" href="{{ route('rates.index', ['from' => $from->copy()->addDays($days)->toDateString(), 'days' => $days]) }}" aria-label="Next {{ $days }} days" title="Next {{ $days }} days"><x-icon name="chev"/></a>
<a class="btn btn-icon rc-nav-btn" href="{{ route('rates.index', ['from' => bdate()->toDateString(), 'days' => $days]) }}" aria-label="Jump to today" title="Today"><x-icon name="calendar"/></a>
<button type="button" class="btn btn-primary btn-icon" data-modal="rateModal" aria-label="Add a rate rule" title="Bulk update rates" @disabled($types->isEmpty())><x-icon name="plus"/></button>
@endsection
@push('head')
<style>
.rc-page{--rc-green:#176239;--rc-deep:#103a23;--rc-line:var(--c-border);--rc-surface:var(--c-surface);--rc-muted:var(--c-text-2);min-width:0}
.rc-view-controls{display:flex;align-items:end;gap:.5rem;margin:0}.rc-control{display:grid;gap:.22rem}.rc-control span{color:var(--c-text-2);font-size:.64rem;font-weight:750;letter-spacing:.04em;text-transform:uppercase}.rc-control input,.rc-control select{min-height:36px;padding:.35rem .55rem;font-size:.8rem}.rc-nav-btn{min-width:36px;min-height:36px;justify-content:center}.rc-chevron-left{transform:rotate(180deg)}
.rc-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.2rem 1.35rem;margin-bottom:1rem;border-radius:17px;background:linear-gradient(118deg,#103a23,#176239 68%,#247849);color:#f5fff7;box-shadow:0 12px 28px rgba(16,58,35,.14)}.rc-hero-copy{min-width:0}.rc-kicker{display:inline-flex;align-items:center;gap:.42rem;color:#c7efd0;font-size:.68rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.rc-kicker svg{width:15px;height:15px}.rc-hero-copy p{margin:.35rem 0 0;max-width:710px;color:#e0f2e5;font-size:.88rem}.rc-hero-window{display:grid;gap:.15rem;min-width:180px;padding:.65rem .8rem;border:1px solid #ffffff32;border-radius:12px;background:#ffffff12;text-align:right}.rc-hero-window span{color:#c7efd0;font-size:.64rem;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.rc-hero-window strong{font-size:.95rem}.rc-mode-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.34rem .6rem;border-radius:999px;background:#ffffff18;color:#f2fff4;font-size:.7rem;font-weight:750;white-space:nowrap}.rc-mode-pill svg{width:14px;height:14px}
.rc-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-bottom:1rem}.rc-kpi{min-width:0;padding:.8rem .9rem;border:1px solid var(--c-border);border-radius:13px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.rc-kpi-label{color:var(--c-text-2);font-size:.67rem;font-weight:750;letter-spacing:.06em;text-transform:uppercase}.rc-kpi-value{display:block;margin-top:.18rem;font-size:1.12rem;font-weight:800;letter-spacing:-.02em;overflow-wrap:anywhere}.rc-kpi-note{display:block;margin-top:.12rem;color:var(--c-text-2);font-size:.72rem}
.rc-calendar-card{overflow:hidden}.rc-calendar-head{display:flex;align-items:center;justify-content:space-between;gap:1rem}.rc-calendar-head h3{margin:0}.rc-calendar-head p{margin:.2rem 0 0}.rc-date-caption{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .55rem;border-radius:9px;background:var(--c-bg);color:var(--c-text-2);font-size:.73rem;white-space:nowrap}.rc-date-caption svg{width:14px;height:14px}.rc-scroll{max-width:100%;overflow:auto;overscroll-behavior-x:contain;scrollbar-gutter:stable;outline:none}.rc-scroll:focus-visible{box-shadow:inset 0 0 0 2px var(--c-primary)}.rc-calendar{width:max-content;min-width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0}.rc-calendar th,.rc-calendar td{width:92px;min-width:92px;padding:.35rem;border-right:1px solid var(--c-border);border-bottom:1px solid var(--c-border);text-align:center;vertical-align:middle}.rc-calendar thead th{position:sticky;top:0;z-index:3;background:var(--c-surface);color:var(--c-text-2);font-size:.68rem;line-height:1.15}.rc-calendar .rc-room-head,.rc-calendar .rc-room-cell{position:sticky;left:0;width:188px;min-width:188px;text-align:left}.rc-calendar .rc-room-head{z-index:5;background:var(--c-surface)}.rc-calendar .rc-room-cell{z-index:2;background:var(--c-surface);box-shadow:5px 0 9px -9px rgba(15,23,42,.45)}.rc-room-name{display:block;font-size:.8rem;font-weight:800;line-height:1.25}.rc-room-base{display:block;margin-top:.15rem;color:var(--c-text-2);font-size:.68rem}.rc-room-sub{display:block;margin-top:.22rem;color:var(--rc-green);font-size:.64rem;font-weight:700}.rc-day{display:grid;justify-items:center;gap:.15rem;min-height:42px}.rc-day-name{font-weight:750}.rc-day-date{font-variant-numeric:tabular-nums}.rc-calendar th.rc-weekend{background:#f4f7f5;color:#815e14}.rc-calendar th.rc-today{box-shadow:inset 0 -3px 0 #1d8b4c;color:#176239}.rc-calendar th.rc-weekend.rc-today{background:#edf6ef}.rc-day-flag{font-size:.55rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#176239}
.rc-calendar td.rc-above{background:#fffbeb}.rc-calendar td.rc-below{background:#f0fdf4}.rc-calendar td.rc-stop{background:#fef2f2}.rc-calendar td.rc-neutral{background:var(--c-surface)}.rc-calendar td.rc-weekend:not(.rc-stop){box-shadow:inset 0 3px 0 #e5c56f}.rc-cell{display:flex;width:100%;min-height:58px;flex-direction:column;align-items:center;justify-content:center;gap:.1rem;padding:.3rem .15rem;border:1px solid transparent;border-radius:8px;background:transparent;color:inherit;cursor:pointer;font:inherit;line-height:1.15;text-align:center}.rc-cell:hover{border-color:var(--rc-green);background:#ffffffa8}.rc-cell:focus-visible{outline:2px solid var(--c-primary);outline-offset:1px}.rc-price{font-size:.78rem;font-weight:750;font-variant-numeric:tabular-nums;white-space:nowrap}.rc-price.has-rule{font-weight:900}.rc-cell-meta{display:flex;align-items:center;justify-content:center;gap:.18rem;flex-wrap:wrap;color:var(--c-text-2);font-size:.58rem;line-height:1.1}.rc-tag{display:inline-flex;align-items:center;gap:.12rem;padding:.12rem .25rem;border-radius:4px;background:#ede9fe;color:#5b21b6;font-size:.54rem;font-weight:850;letter-spacing:.02em}.rc-tag svg{width:9px;height:9px}.rc-tag.fixed{background:#e2e8f0;color:#334155}.rc-tag.closed{background:#fee2e2;color:#991b1b}.rc-tag.min{background:#eff6ff;color:#1d4ed8}.rc-tag.season{max-width:75px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:#ecfdf5;color:#166534}
.rc-legend{display:flex;flex-wrap:wrap;gap:.5rem 1rem;padding:.75rem 1rem;border-top:1px solid var(--c-border);color:var(--c-text-2);font-size:.72rem}.rc-legend span{display:inline-flex;align-items:center;gap:.38rem}.rc-swatch{width:11px;height:11px;border:1px solid var(--c-border);border-radius:3px}.rc-swatch.above{background:#fffbeb;border-color:#fcd34d}.rc-swatch.below{background:#f0fdf4;border-color:#86efac}.rc-swatch.stopped{background:#fef2f2;border-color:#fca5a5}.rc-rm-note{display:flex;align-items:flex-start;gap:.55rem;margin:.8rem 0 0;padding:.8rem .95rem;border:1px solid #d7c7fa;border-radius:11px;background:#f6f2ff;color:#493582;font-size:.82rem}.rc-rm-note svg{width:17px;height:17px;flex:none}.rc-rm-note a{color:inherit;font-weight:800;text-decoration:underline;text-underline-offset:2px}
.rc-rules-card{margin-top:1.1rem;overflow:hidden}.rc-rules-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem}.rc-rules-head h3{margin:0}.rc-rules-count{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .6rem;border-radius:999px;background:#e9f5ec;color:#176239;font-size:.72rem;font-weight:800;white-space:nowrap}.rc-rules-table{min-width:900px}.rc-rules-table th{font-size:.68rem;white-space:nowrap}.rc-rules-table td{vertical-align:middle}.rc-rule-name{display:flex;align-items:center;gap:.4rem}.rc-period{white-space:nowrap;font-variant-numeric:tabular-nums}.rc-remove{min-width:34px;min-height:34px}.rc-empty{padding:1rem}
.rc-modal-box{max-width:700px}.rc-modal-intro{margin:-.25rem 0 .25rem;color:var(--c-text-2);font-size:.8rem}.rc-type-field{grid-column:span 2;min-width:0;margin:0;padding:0;border:0}.rc-type-field legend{margin-bottom:.4rem;font-size:.8rem;font-weight:650}.rc-type-actions{display:flex;align-items:center;gap:.45rem}.rc-type-list{display:flex;flex-wrap:wrap;gap:.45rem}.rc-type-list .check{padding:.38rem .55rem;border:1px solid var(--c-border);border-radius:8px;background:var(--c-surface)}.rc-type-list .check:has(input:checked){border-color:#5aa870;background:#edf8ef}.rc-form-hint{grid-column:span 2;margin:-.45rem 0 0;color:var(--c-text-2);font-size:.72rem}.rc-error{grid-column:span 2;color:var(--c-red);font-size:.78rem}.rc-checkbox{align-self:end;min-height:42px}.rc-modal-box .modal-body{max-height:min(72vh,720px);overflow:auto}.rc-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
body.dark .rc-hero{background:linear-gradient(118deg,#0d2718,#143d25 68%,#1d5433);box-shadow:none}body.dark .rc-calendar th.rc-weekend{background:#29291f;color:#edd590}body.dark .rc-calendar th.rc-today{color:#a9e1b7}body.dark .rc-calendar th.rc-weekend.rc-today{background:#203328}body.dark .rc-calendar td.rc-above{background:#332d1b}body.dark .rc-calendar td.rc-below{background:#183225}body.dark .rc-calendar td.rc-stop{background:#382326}body.dark .rc-cell:hover{background:#ffffff12}body.dark .rc-tag{background:#332850;color:#d8c7ff}body.dark .rc-tag.fixed{background:#303842;color:#d5dee8}body.dark .rc-tag.closed{background:#48282a;color:#ffb9b9}body.dark .rc-tag.min{background:#203248;color:#b6d8ff}body.dark .rc-tag.season{background:#1e3c2b;color:#abe1ba}body.dark .rc-rules-count{background:#183a27;color:#a9e1b7}body.dark .rc-rm-note{background:#281e3a;border-color:#4c3b70;color:#e2d5ff}
@media(max-width:980px){.rc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.rc-calendar-head{align-items:flex-start;flex-direction:column}.rc-rules-head{align-items:flex-start}}
@media(max-width:760px){.rc-hero{align-items:flex-start;flex-direction:column;padding:1rem}.rc-hero-window{width:100%;min-width:0;text-align:left}.rc-view-controls{gap:.35rem}.rc-control input{max-width:144px}.rc-control select{max-width:100px}.rc-rules-count{align-self:flex-start}}
@media(max-width:520px){.rc-view-controls{flex-wrap:wrap}.rc-control span{font-size:.58rem}.rc-control input{max-width:130px}.rc-control select{max-width:88px}.rc-nav-btn{min-width:34px;padding:.4rem}.rc-kpis{gap:.5rem}.rc-kpi{padding:.68rem}.rc-kpi-value{font-size:1rem}.rc-calendar .rc-room-head,.rc-calendar .rc-room-cell{width:152px;min-width:152px}.rc-calendar th,.rc-calendar td{width:84px;min-width:84px;padding:.25rem}.rc-price{font-size:.72rem}.rc-cell{min-height:55px}.rc-legend{gap:.45rem .75rem;padding:.65rem}.rc-type-field,.rc-form-hint,.rc-error{grid-column:1/-1}}
@media(prefers-reduced-motion:reduce){.rc-cell{scroll-behavior:auto;transition:none}}
</style>
@endpush
@section('content')
@php
  $calendarCells = collect($grid)->flatMap(fn ($dates) => array_values($dates))->values();
  $visibleRates = $calendarCells->pluck('rate');
  $lowestVisibleRate = $visibleRates->isNotEmpty() ? $visibleRates->min() : null;
  $highestVisibleRate = $visibleRates->isNotEmpty() ? $visibleRates->max() : null;
  $ruleCells = $calendarCells->where('override', true)->count();
  $stopSellCells = $calendarCells->where('stop', true)->count();
  $windowEnd = $from->copy()->addDays($days - 1);
  $modeLabels = ['off' => 'Revenue management off', 'recommend' => 'Recommendations', 'auto' => 'Auto-pilot live'];
@endphp
<div class="rc-page">
  <section class="rc-hero" aria-label="Rate calendar overview">
    <div class="rc-hero-copy"><span class="rc-kicker"><x-icon name="chart"/> Revenue &amp; distribution · daily pricing</span><p>Compare nightly room rates at a glance. Select any date to create a one-day rule, or apply a seasonal change across dates and room types.</p></div>
    <div class="rc-mode-pill"><x-icon name="trend"/> {{ $modeLabels[$rmMode] ?? 'Revenue management' }}</div>
    <div class="rc-hero-window"><span>Calendar window · {{ $days }} days</span><strong>{{ $from->format('j M') }} – {{ $windowEnd->format('j M Y') }}</strong><span>Prices shown per room / night · {{ $currentHotel->currency_symbol ?? 'GH₵' }}</span></div>
  </section>

  <section class="rc-kpis" aria-label="Rate calendar summary">
    <article class="rc-kpi"><span class="rc-kpi-label">Room types</span><strong class="rc-kpi-value">{{ $types->count() }}</strong><span class="rc-kpi-note">Active inventory in this calendar</span></article>
    <article class="rc-kpi"><span class="rc-kpi-label">Nightly rate range</span><strong class="rc-kpi-value">{{ $lowestVisibleRate !== null ? number_format($lowestVisibleRate, 0).' – '.number_format($highestVisibleRate, 0) : '—' }}</strong><span class="rc-kpi-note">Lowest to highest visible nightly price</span></article>
    <article class="rc-kpi"><span class="rc-kpi-label">Rule-covered cells</span><strong class="rc-kpi-value">{{ number_format($ruleCells) }}</strong><span class="rc-kpi-note">Room type and date combinations with an override</span></article>
    <article class="rc-kpi"><span class="rc-kpi-label">Stop-sell cells</span><strong class="rc-kpi-value">{{ number_format($stopSellCells) }}</strong><span class="rc-kpi-note">Closed room type and date combinations</span></article>
  </section>

  @if($rmCount || $rmMode !== 'off')
    <div class="rc-rm-note" role="note"><x-icon name="trend"/><span>Revenue management is <b>{{ $modeLabels[$rmMode] ?? $rmMode }}</b>.{{ $rmCount ? ' '.$rmCount.' revenue-managed price override(s) are tracked separately from seasonal rules.' : '' }}@if(can_do('revenue.view')) <a href="{{ route('revenue.pricing') }}">Open the pricing grid</a>@endif</span></div>
  @endif

  <section class="card flush rc-calendar-card" aria-labelledby="rcCalendarTitle">
    <div class="card-head rc-calendar-head"><div><h3 id="rcCalendarTitle">Daily room rates</h3><p class="tiny muted">Click or keyboard-focus a date cell to prepare a rule for that room type and night.</p></div><span class="rc-date-caption"><x-icon name="calendar"/> {{ $from->format('D, j M Y') }} – {{ $windowEnd->format('D, j M Y') }}</span></div>
    @if($types->isEmpty())
      <div class="rc-empty"><x-empty title="No active room types" text="Activate a room type before setting seasonal rates or stop-sell dates." icon="bed"/></div>
    @else
    <div class="rc-scroll" role="region" aria-label="Scrollable {{ $days }}-day rate calendar; use the arrow keys or horizontal scrolling to browse dates" tabindex="0">
      <table class="table table-compact rc-calendar" aria-describedby="rcLegend">
        <thead><tr><th scope="col" class="rc-room-head">Room type / base rate</th>
          @for($i=0;$i<$days;$i++) @php $d = $from->copy()->addDays($i); $isToday = $d->isSameDay(bdate()); $isWeekend = in_array($d->dayOfWeek, \App\Services\RateService::WEEKEND, true); @endphp
            <th scope="col" class="{{ $isWeekend ? 'rc-weekend ' : '' }}{{ $isToday ? 'rc-today' : '' }}"><time class="rc-day" datetime="{{ $d->toDateString() }}"><span class="rc-day-name">{{ $d->format('D') }}</span><span class="rc-day-date">{{ $d->format('j M') }}</span>@if($isToday)<span class="rc-day-flag">Today</span>@endif</time></th>
          @endfor
        </tr></thead>
        <tbody>
        @foreach($types as $t)
          <tr><th scope="row" class="rc-room-cell"><span class="rc-room-name">{{ $t->name }}</span><span class="rc-room-base">Base {{ money($t->base_rate) }} / night</span>@if($t->code)<span class="rc-room-sub">{{ $t->code }}</span>@endif</th>
          @foreach($grid[$t->id] ?? [] as $date => $c)
            @php
              $rateClass = $c['stop'] ? 'rc-stop' : ($c['rate'] > $t->base_rate ? 'rc-above' : ($c['rate'] < $t->base_rate ? 'rc-below' : 'rc-neutral'));
              $dateLabel = \Illuminate\Support\Carbon::parse($date)->format('l j F Y');
              $rateLabel = $t->name.', '.$dateLabel.', '.money($c['rate']).' per room per night';
              $detailLabel = $c['season'] ? 'Rule '.$c['season'].'. ' : ($c['override'] ? 'Seasonal override. ' : 'Base rate. ');
              $detailLabel .= $c['min_stay'] > 1 ? 'Minimum stay '.$c['min_stay'].' nights. ' : '';
              $detailLabel .= $c['stop'] ? 'Stop-sell, closed to online booking. ' : 'Open for booking. ';
              $detailLabel .= !empty($c['fixed']) ? 'Fixed price. ' : '';
              $detailLabel .= (($c['source'] ?? null) === 'rm' || !empty($c['dynamic'])) ? 'Revenue-managed rate. ' : '';
              $cellTitle = $rateLabel.'. '.$detailLabel.'Select to add a one-night rule.';
            @endphp
            <td class="{{ $rateClass }} {{ $c['weekend'] ? 'rc-weekend' : '' }}">
              <button type="button" class="rc-cell" data-modal="rateModal" data-type="{{ $t->id }}" data-fdate_from="{{ $date }}" data-fdate_to="{{ $date }}" data-fname="Rate change · {{ \Illuminate\Support\Carbon::parse($date)->format('d M') }}" aria-label="{{ $cellTitle }}" title="{{ $cellTitle }}" aria-haspopup="dialog">
                <span class="rc-price {{ $c['override'] ? 'has-rule' : '' }}">{{ number_format($c['rate'], 0) }}</span>
                <span class="rc-cell-meta">
                  @if(($c['source'] ?? null) === 'rm' || !empty($c['dynamic']))<span class="rc-tag" title="Set by revenue management">RM</span>@endif
                  @if(!empty($c['fixed']))<span class="rc-tag fixed"><x-icon name="lock"/> Fixed</span>@endif
                  @if($c['stop'])<span class="rc-tag closed">Closed</span>@endif
                  @if($c['min_stay'] > 1)<span class="rc-tag min">{{ $c['min_stay'] }}+ nights</span>@endif
                  @if($c['season'])<span class="rc-tag season" title="{{ $c['season'] }}">{{ $c['season'] }}</span>@endif
                </span>
              </button>
            </td>
          @endforeach</tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <div class="rc-legend" id="rcLegend" aria-label="Rate calendar legend">
      <span><i class="rc-swatch above"></i> Above base rate</span><span><i class="rc-swatch below"></i> Below base rate</span><span><i class="rc-swatch stopped"></i> Stop-sell</span><span><b>Bold</b> = rule affects the date</span><span><sup class="rc-tag">RM</sup> Revenue-managed rate</span><span><span class="rc-tag fixed"><x-icon name="lock"/> Fixed</span> Protected from revenue management</span><span>Select any date to add a rule</span>
    </div>
    @endif
  </section>

  <section class="card flush rc-rules-card" aria-labelledby="rcRulesTitle">
    <div class="card-head rc-rules-head"><div><h3 id="rcRulesTitle">Seasonal &amp; date-specific rules</h3><span class="tiny muted">Newest rule wins where prices overlap; restrictions combine across covering rules.</span></div><span class="rc-rules-count">{{ $overrides->count() }} active / upcoming</span></div>
    <div class="card-body table-wrap"><table class="table rc-rules-table"><thead><tr><th scope="col">Rule</th><th scope="col">Room type</th><th scope="col">From</th><th scope="col">To</th><th scope="col" class="num">Nightly rate</th><th scope="col" class="num">Fri / Sat</th><th scope="col" class="center">Min stay</th><th scope="col" class="center">Booking</th><th scope="col">Created by</th><th scope="col"><span class="rc-sr-only">Actions</span></th></tr></thead>
    <tbody>
    @forelse($overrides as $o)
      <tr><td><div class="rc-rule-name"><strong>{{ $o->name }}</strong>@if($o->is_fixed)<span class="badge badge-slate" title="Revenue management never changes these dates"><x-icon name="lock" size="sm"/> Fixed</span>@endif</div></td><td>{{ $o->roomType?->name ?? '—' }}</td><td class="rc-period">{{ dmy($o->date_from) }}</td><td class="rc-period">{{ dmy($o->date_to) }}</td>
      <td class="num">{{ $o->rate !== null ? money($o->rate) : '—' }}</td><td class="num">{{ $o->weekend_rate !== null ? money($o->weekend_rate) : '—' }}</td>
      <td class="center">{{ $o->min_stay }}</td><td class="center">{!! $o->stop_sell ? '<span class="badge badge-red">Closed</span>' : '<span class="muted">Open</span>' !!}</td><td class="small muted">{{ $o->creator?->name ?? '—' }}</td>
      <td class="right"><form method="post" action="{{ route('rates.destroy', $o) }}" data-confirm="Remove the ‘{{ $o->name }}’ rate rule? Base and other overlapping rules will apply again.">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-ghost btn-icon rc-remove" aria-label="Remove {{ $o->name }} rate rule for {{ $o->roomType?->name }}" title="Remove rule"><x-icon name="trash" size="sm"/></button></form></td></tr>
    @empty
      <tr><td colspan="10"><x-empty title="No seasonal rates yet" text="All dates currently use each room type’s base price. Add a date rule from a calendar cell or use Bulk update rates for a season, weekend price or stop-sell." icon="calendar"/></td></tr>
    @endforelse
    </tbody></table></div>
  </section>
</div>

<div class="modal" id="rateModal" role="dialog" aria-modal="true" aria-labelledby="rateModalTitle"><div class="modal-box rc-modal-box"><form method="post" action="{{ route('rates.store') }}" data-reset>@csrf
  <div class="modal-head"><div><h3 id="rateModalTitle">Bulk update rates</h3><p class="tiny muted mb-0">Apply a price or booking restriction to selected room types and dates.</p></div><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close rate form" title="Close"><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="name" label="Season / rule name" required placeholder="e.g. Christmas peak, Weekend promo" class="span-2"/>
    <fieldset class="rc-type-field"><legend>Room types <span class="req">*</span></legend><div class="rc-type-actions"><div class="rc-type-list">@foreach($types as $t)<label class="check"><input type="checkbox" name="room_type_ids[]" value="{{ $t->id }}" class="rt-box" @checked(in_array($t->id, old('room_type_ids', [])))> {{ $t->name }}</label>@endforeach</div><button type="button" class="btn btn-sm btn-ghost" id="rcSelectAll">Select all</button></div>@error('room_type_ids')<span class="err">{{ $message }}</span>@enderror</fieldset>
    @if($errors->has('room_type_ids.*'))<span class="rc-error">{{ $errors->first('room_type_ids.*') }}</span>@endif
    <x-field name="date_from" label="From date" type="date" required/>
    <x-field name="date_to" label="To date (inclusive)" type="date" required/>
    <x-field name="rate" label="Nightly rate" type="number" step="0.01" min="0" hint="Leave blank to use each room type’s base rate"/>
    <x-field name="weekend_rate" label="Friday / Saturday rate" type="number" step="0.01" min="0" hint="Optional weekend price; leave blank to use the nightly rate"/>
    <x-field name="min_stay" label="Minimum stay (nights)" type="number" min="1" max="30" value="1" hint="Restrictions combine; the highest applies"/>
    <div class="field rc-checkbox"><label>&nbsp;</label><label class="check"><input type="hidden" name="stop_sell" value="0"><input type="checkbox" name="stop_sell" value="1"> Stop-sell · close online booking</label></div>
    <div class="field span-2"><label class="check"><input type="hidden" name="is_fixed" value="0"><input type="checkbox" name="is_fixed" value="1"> Fixed price · protect these dates from revenue management</label></div>
    <p class="rc-form-hint">Every active rule covering a date can contribute restrictions. Price selection follows the newest rule that provides a price. Final booking availability is still checked by the PMS.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn btn-primary"><x-icon name="check" size="sm"/> Apply rule</button></div>
</form></div></div>
@endsection
@push('scripts')
<script>
(() => {
  const roomBoxes = [...document.querySelectorAll('.rt-box')];
  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-modal="rateModal"]');
    if (!trigger) return;
    const form = document.querySelector('#rateModal form');
    if (trigger.classList.contains('rc-cell')) {
      // app.js has already reset the form and applied the cell's date/name data.
      roomBoxes.forEach(box => { box.checked = box.value === trigger.dataset.type; });
      return;
    }
    // Start bulk entry clean so an earlier clicked cell cannot constrain a new range rule.
    form?.querySelector('[name="name"]') && (form.querySelector('[name="name"]').value = '');
    ['date_from', 'date_to', 'rate', 'weekend_rate'].forEach(name => {
      const input = form?.querySelector(`[name="${name}"]`);
      if (input) input.value = '';
    });
    const minStay = form?.querySelector('[name="min_stay"]');
    if (minStay) minStay.value = '1';
    roomBoxes.forEach(box => { box.checked = false; });
    form?.querySelectorAll('input[type="checkbox"][name="stop_sell"], input[type="checkbox"][name="is_fixed"]').forEach(box => { box.checked = false; });
  });
  document.getElementById('rcSelectAll')?.addEventListener('click', () => roomBoxes.forEach(box => { box.checked = true; }));
  @if($errors->any()) document.getElementById('rateModal')?.classList.add('show'); @endif
})();
</script>
@endpush
