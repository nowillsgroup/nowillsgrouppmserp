@extends('layouts.app')
@section('title', 'Revenue Pricing Grid')
@section('crumbs') <a href="{{ route('revenue.index') }}">Revenue</a> / Pricing Grid @endsection
@section('actions')
<span class="rm-mode {{ $mode }}" title="Pricing mode"><x-icon name="trend" size="sm"/> {{ \App\Services\RevenueService::MODES[$mode] }}</span>
<form class="rmp-view-controls" method="get" aria-label="Pricing grid date range"><label><span>Start date</span><input type="date" name="from" value="{{ $from->toDateString() }}" min="{{ bdate()->toDateString() }}" aria-label="Pricing grid start date" data-autosubmit></label>
<label><span>Window</span><select name="days" aria-label="Pricing grid window" data-autosubmit>@foreach([7,14,21,28] as $d)<option value="{{ $d }}" @selected($days==$d)>{{ $d }} days</option>@endforeach</select></label></form>
@if($from->gt(bdate()))<a class="btn btn-icon rmp-nav" href="{{ route('revenue.pricing', ['from' => $from->copy()->subDays($days)->max(bdate())->toDateString(), 'days' => $days]) }}" aria-label="Previous date window" title="Previous window"><x-icon name="chev" class="rmp-chevron-left"/></a>@endif
<a class="btn btn-icon rmp-nav" href="{{ route('revenue.pricing', ['from' => $from->copy()->addDays($days)->toDateString(), 'days' => $days]) }}" aria-label="Next date window" title="Next window"><x-icon name="chev"/></a>
<a class="btn btn-icon rmp-nav" href="{{ route('revenue.pricing', ['from' => bdate()->toDateString(), 'days' => $days]) }}" aria-label="Jump to today" title="Today"><x-icon name="calendar"/></a>
<a class="btn btn-icon rmp-nav" href="{{ route('rates.index', ['from' => $from->toDateString(), 'days' => 30]) }}" aria-label="Open Rate Calendar" title="Rate Calendar"><x-icon name="chart"/></a>
@endsection
@push('head')
<style>
.rmp-page{min-width:0}.rmp-view-controls{display:flex;align-items:end;gap:.45rem;margin:0}.rmp-view-controls label{display:grid;gap:.2rem}.rmp-view-controls label span{color:var(--c-text-2);font-size:.62rem;font-weight:750;letter-spacing:.04em;text-transform:uppercase}.rmp-view-controls input,.rmp-view-controls select{min-height:36px;padding:.35rem .5rem;font-size:.78rem}.rmp-nav{min-width:36px;min-height:36px;justify-content:center}.rmp-chevron-left{transform:rotate(180deg)}
.rmp-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.15rem 1.3rem;margin-bottom:1rem;border-radius:17px;background:linear-gradient(118deg,#103a23,#176239 68%,#247849);color:#f5fff7;box-shadow:0 12px 28px rgba(16,58,35,.14)}.rmp-hero-copy{min-width:0}.rmp-kicker{display:flex;align-items:center;gap:.4rem;color:#c7efd0;font-size:.67rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.rmp-kicker svg{width:15px;height:15px}.rmp-hero-copy p{margin:.35rem 0 0;max-width:720px;color:#e0f2e5;font-size:.86rem}.rmp-window{display:grid;gap:.15rem;min-width:170px;padding:.65rem .8rem;border:1px solid #ffffff32;border-radius:12px;background:#ffffff12;text-align:right}.rmp-window span{color:#c7efd0;font-size:.62rem;font-weight:750;letter-spacing:.08em;text-transform:uppercase}.rmp-window strong{font-size:.94rem}.rmp-mode-note{display:flex;align-items:flex-start;gap:.55rem;margin:0 0 1rem;padding:.8rem .95rem;border-radius:11px;font-size:.83rem}.rmp-mode-note svg{width:18px;height:18px;flex:none}.rmp-mode-note p{margin:0}.rmp-mode-note.auto{border:1px solid #d7c7fa;background:#f6f2ff;color:#493582}.rmp-mode-note.recommend{border:1px solid #b8dfc2;background:#eff9f1;color:#1b5430}.rmp-mode-note.off{border:1px solid #f3d08b;background:#fff8e8;color:#754b0b}.rmp-mode-note.view-only{border:1px solid var(--c-border);background:var(--c-bg);color:var(--c-text-2)}
.rmp-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-bottom:1rem}.rmp-kpi{min-width:0;padding:.8rem .9rem;border:1px solid var(--c-border);border-radius:13px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.rmp-kpi-label{color:var(--c-text-2);font-size:.66rem;font-weight:750;letter-spacing:.055em;text-transform:uppercase}.rmp-kpi-value{display:block;margin-top:.18rem;font-size:1.1rem;font-weight:800;letter-spacing:-.02em}.rmp-kpi-note{display:block;margin-top:.12rem;color:var(--c-text-2);font-size:.7rem}
.rmp-grid-card{overflow:hidden}.rmp-grid-head{display:flex;align-items:center;justify-content:space-between;gap:1rem}.rmp-grid-head h3{margin:0}.rmp-grid-head p{margin:.2rem 0 0}.rmp-head-range{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .55rem;border-radius:9px;background:var(--c-bg);color:var(--c-text-2);font-size:.72rem;white-space:nowrap}.rmp-head-range svg{width:14px;height:14px}.rmp-publish-controls{display:flex;align-items:center;justify-content:flex-end;gap:.45rem;flex-wrap:wrap}.rmp-select-summary{margin-right:auto;color:var(--c-text-2);font-size:.72rem}.rmp-select-summary b{color:var(--c-text);font-variant-numeric:tabular-nums}.rmp-publish-controls .btn{white-space:nowrap}.rmp-publish-controls button:disabled{opacity:.48;cursor:not-allowed}.rmp-scroll{max-width:100%;overflow:auto;overscroll-behavior-x:contain;scrollbar-gutter:stable;outline:none}.rmp-scroll:focus-visible{box-shadow:inset 0 0 0 2px var(--c-primary)}.rmp-grid{width:max-content;min-width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0}.rmp-grid th,.rmp-grid td{width:98px;min-width:98px;padding:.35rem;border-right:1px solid var(--c-border);border-bottom:1px solid var(--c-border);text-align:center;vertical-align:middle}.rmp-grid thead th{position:sticky;top:0;z-index:3;background:var(--c-surface);color:var(--c-text-2);font-size:.67rem;line-height:1.15}.rmp-grid .rmp-room-head,.rmp-grid .rmp-room-cell{position:sticky;left:0;width:190px;min-width:190px;text-align:left}.rmp-grid .rmp-room-head{z-index:5;background:var(--c-surface)}.rmp-grid .rmp-room-cell{z-index:2;background:var(--c-surface);box-shadow:5px 0 9px -9px rgba(15,23,42,.45)}.rmp-room-name{display:block;font-size:.79rem;font-weight:800;line-height:1.25}.rmp-room-base,.rmp-room-limit{display:block;margin-top:.16rem;color:var(--c-text-2);font-size:.65rem}.rmp-room-limit{color:var(--c-primary)}.rmp-day{display:grid;justify-items:center;gap:.15rem;min-height:40px}.rmp-day strong{font-weight:750}.rmp-day time{font-variant-numeric:tabular-nums}.rmp-day-flag{font-size:.55rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#176239}.rmp-grid th.rmp-weekend{background:#f4f7f5;color:#815e14}.rmp-grid th.rmp-today{box-shadow:inset 0 -3px 0 #1d8b4c;color:#176239}.rmp-grid th.rmp-weekend.rmp-today{background:#edf6ef}
.rmp-occ-row th,.rmp-occ-row td{font-size:.72rem;font-weight:750;padding:.4rem .3rem}.rmp-occ-cell{display:block;font-size:.78rem;font-weight:850;font-variant-numeric:tabular-nums}.rmp-occ-detail{display:block;margin-top:.12rem;color:var(--c-text-2);font-size:.6rem;font-weight:500}.rmp-cell{min-height:105px}.rmp-cell.up{background:#fffbeb}.rmp-cell.down{background:#f0fdf4}.rmp-cell.fixed{background:#f8fafc;color:#64748b}.rmp-cell.stop{box-shadow:inset 0 3px 0 #dc2626}.rmp-live{display:block;color:var(--c-text-2);font-size:.64rem;font-weight:650}.rmp-live sup{margin-left:.12rem}.rmp-price{width:84px;max-width:100%;padding:.24rem .3rem;text-align:center;font-weight:800;font-size:.8rem;font-variant-numeric:tabular-nums}.rmp-cell.up .rmp-price{border-color:#f59e0b;background:#fffdf4}.rmp-cell.down .rmp-price{border-color:#22c55e;background:#f6fff8}.rmp-delta{min-height:.8rem;margin:.12rem 0;color:var(--c-text-2);font-size:.62rem;font-weight:750}.rmp-cell.up .rmp-delta{color:#9a5300}.rmp-cell.down .rmp-delta{color:#18733c}.rmp-pick{display:inline-flex;align-items:center;justify-content:center;gap:.2rem;color:var(--c-text-2);font-size:.61rem;cursor:pointer}.rmp-pick input{width:13px;height:13px;margin:0}.rmp-current-rate{display:block;font-size:.8rem;font-weight:800;font-variant-numeric:tabular-nums}.rmp-rec-label{display:block;margin-top:.12rem;color:var(--c-text-2);font-size:.62rem}.rmp-meta{display:flex;justify-content:center;gap:.2rem;flex-wrap:wrap;margin-top:.17rem;color:var(--c-text-2);font-size:.58rem;line-height:1.1}.rmp-tag{display:inline-flex;align-items:center;gap:.1rem;padding:.12rem .23rem;border-radius:4px;background:#ede9fe;color:#5b21b6;font-size:.52rem;font-weight:850}.rmp-tag.closed{background:#fee2e2;color:#991b1b}.rmp-tag.min{background:#eff6ff;color:#1d4ed8}.rmp-tag.fixed{background:#e2e8f0;color:#334155}.rmp-legend{display:flex;flex-wrap:wrap;gap:.5rem 1rem;padding:.7rem 1rem;border-top:1px solid var(--c-border);color:var(--c-text-2);font-size:.7rem}.rmp-legend span{display:inline-flex;align-items:center;gap:.35rem}.rmp-swatch{width:11px;height:11px;border:1px solid var(--c-border);border-radius:3px}.rmp-swatch.up{background:#fffbeb;border-color:#f59e0b}.rmp-swatch.down{background:#f0fdf4;border-color:#22c55e}.rmp-swatch.fixed{background:#f8fafc;border-color:#cbd5e1}.rmp-swatch.stop{background:#fef2f2;border-color:#fca5a5}
.rmp-empty{padding:1rem}.rmp-bottom-grid{align-items:start}.rmp-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.65rem}.rmp-step{padding:.75rem .85rem;border:1px solid var(--c-border);border-radius:11px;background:var(--c-bg);font-size:.8rem}.rmp-step b{display:block;margin-bottom:.2rem}.rmp-reset-form{align-items:end}.rmp-reset-form .field label{font-size:.75rem}
body.dark .rmp-hero{background:linear-gradient(118deg,#0d2718,#143d25 68%,#1d5433);box-shadow:none}body.dark .rmp-mode-note.auto{background:#281e3a;border-color:#4c3b70;color:#e2d5ff}body.dark .rmp-mode-note.recommend{background:#173324;border-color:#315f3d;color:#c3ebcc}body.dark .rmp-mode-note.off{background:#332914;border-color:#695321;color:#f2d895}body.dark .rmp-grid th.rmp-weekend{background:#29291f;color:#edd590}body.dark .rmp-grid th.rmp-today{color:#a9e1b7}body.dark .rmp-grid th.rmp-weekend.rmp-today{background:#203328}body.dark .rmp-grid .rmp-room-cell,body.dark .rmp-grid .rmp-room-head{background:var(--c-surface)}body.dark .rmp-cell.up{background:#332d1b}body.dark .rmp-cell.down{background:#183225}body.dark .rmp-cell.fixed{background:#303842;color:#d5dee8}body.dark .rmp-cell.up .rmp-price{background:#3b321c}body.dark .rmp-cell.down .rmp-price{background:#1b3d29}body.dark .rmp-tag{background:#332850;color:#d8c7ff}body.dark .rmp-tag.closed{background:#48282a;color:#ffb9b9}body.dark .rmp-tag.min{background:#203248;color:#b6d8ff}body.dark .rmp-tag.fixed{background:#303842;color:#d5dee8}body.dark .rmp-swatch.up{background:#332d1b}body.dark .rmp-swatch.down{background:#183225}body.dark .rmp-swatch.fixed{background:#303842}
@media(max-width:1050px){.rmp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.rmp-grid-head{align-items:flex-start;flex-direction:column}.rmp-publish-controls{justify-content:flex-start;width:100%}.rmp-select-summary{flex-basis:100%}}
@media(max-width:760px){.rmp-hero{align-items:flex-start;flex-direction:column;padding:1rem}.rmp-window{width:100%;min-width:0;text-align:left}.rmp-view-controls{gap:.32rem}.rmp-view-controls input{max-width:145px}.rmp-view-controls select{max-width:95px}.rmp-publish-controls{gap:.35rem}.rmp-publish-controls .btn{padding:.4rem .55rem;font-size:.75rem}}
@media(max-width:520px){.rmp-view-controls{flex-wrap:wrap}.rmp-view-controls label span{font-size:.56rem}.rmp-view-controls input{max-width:126px}.rmp-view-controls select{max-width:84px}.rmp-nav{min-width:33px;padding:.35rem}.rmp-kpis{gap:.5rem}.rmp-kpi{padding:.65rem}.rmp-kpi-value{font-size:1rem}.rmp-grid .rmp-room-head,.rmp-grid .rmp-room-cell{width:155px;min-width:155px}.rmp-grid th,.rmp-grid td{width:88px;min-width:88px;padding:.25rem}.rmp-cell{min-height:100px}.rmp-price{width:76px}.rmp-publish-controls .btn{font-size:.7rem}}
@media(prefers-reduced-motion:reduce){.rmp-hero,.rmp-cell{scroll-behavior:auto;transition:none}}
</style>
@endpush
@section('content')
@php
  $canManage = can_do('revenue.manage');
  $editable = $canManage && $mode !== 'auto';
  $gridCells = collect($grid)->flatMap(fn ($dates) => array_values($dates))->values();
  $fixedCount = $gridCells->where('fixed', true)->count();
  $stopCount = $gridCells->where('stop', true)->count();
  $occupancyValues = collect($occ)->pluck('hotel');
  $averageOccupancy = $occupancyValues->isNotEmpty() ? (int) round($occupancyValues->avg()) : 0;
  $windowEnd = $from->copy()->addDays($days - 1);
@endphp
<div class="rmp-page">
  <section class="rmp-hero" aria-label="Revenue pricing overview">
    <div class="rmp-hero-copy"><span class="rmp-kicker"><x-icon name="trend"/> Revenue management · pricing workspace</span><p>Review the current nightly price beside the rule-based recommendation. Protected dates stay untouched; publishing a selected change creates a revenue-managed day rate.</p></div>
    <div class="rmp-window"><span>Forecast window · {{ $days }} days</span><strong>{{ $from->format('j M') }} – {{ $windowEnd->format('j M Y') }}</strong><span>Prices per room / night · {{ $currentHotel->currency_symbol ?? 'GH₵' }}</span></div>
  </section>

  @if($mode === 'auto')
    <div class="rmp-mode-note auto" role="status"><x-icon name="trend"/><p><b>Auto-pilot is on.</b> Rates are applied live to booking quotes and channel pricing. This grid is read-only; nothing needs publishing. Fixed-price dates remain protected.</p></div>
  @elseif($mode === 'off')
    <div class="rmp-mode-note off" role="status"><x-icon name="alert"/><p><b>Revenue management is off.</b> Recommendations are for review only; accounts with publish permission can still publish them manually.</p></div>
  @else
    <div class="rmp-mode-note recommend" role="status"><x-icon name="trend"/><p><b>Review before publishing.</b> Recommendations do not change live rates until an authorized user selects and publishes them. Fixed-price dates are never adjusted.</p></div>
  @endif
  @unless($canManage)<div class="rmp-mode-note view-only" role="note"><x-icon name="lock"/><p>This is a view-only pricing grid. You can review live rates and recommendations but cannot publish changes.</p></div>@endunless

  <section class="rmp-kpis" aria-label="Pricing grid summary">
    <article class="rmp-kpi"><span class="rmp-kpi-label">{{ $mode === 'auto' ? 'Live / recommended differences' : 'Recommendations to review' }}</span><strong class="rmp-kpi-value">{{ number_format($pending) }}</strong><span class="rmp-kpi-note">Room type and date combinations with a price change</span></article>
    <article class="rmp-kpi"><span class="rmp-kpi-label">Average hotel occupancy</span><strong class="rmp-kpi-value">{{ $averageOccupancy }}%</strong><span class="rmp-kpi-note">Across the visible forecast window</span></article>
    <article class="rmp-kpi"><span class="rmp-kpi-label">Fixed-price cells</span><strong class="rmp-kpi-value">{{ number_format($fixedCount) }}</strong><span class="rmp-kpi-note">Never adjusted by revenue management</span></article>
    <article class="rmp-kpi"><span class="rmp-kpi-label">Stop-sell cells</span><strong class="rmp-kpi-value">{{ number_format($stopCount) }}</strong><span class="rmp-kpi-note">Already closed to online booking</span></article>
  </section>

  <form method="post" action="{{ route('revenue.publish') }}" id="pubForm">@csrf
    <input type="hidden" name="from" value="{{ $from->toDateString() }}"><input type="hidden" name="days" value="{{ $days }}">
    <section class="card flush rmp-grid-card" aria-labelledby="rmpGridTitle">
      <div class="card-head rmp-grid-head"><div><h3 id="rmpGridTitle">Recommended nightly rates</h3><p class="tiny muted">Blue = on the books · held group rooms are counted as sold · focus the date grid and scroll horizontally to compare nights.</p></div>
        <span class="rmp-head-range"><x-icon name="calendar"/> {{ $from->format('D, j M Y') }} – {{ $windowEnd->format('D, j M Y') }}</span>
        @if($editable)
          <div class="rmp-publish-controls" aria-label="Publish recommendations">
            <span class="rmp-select-summary" aria-live="polite"><b id="pickCount">0</b> selected · {{ number_format($pending) }} recommendation(s) differ from live</span>
            <button type="button" class="btn btn-sm btn-ghost" id="selChanges">Select changes</button>
            <button type="button" class="btn btn-sm btn-ghost" id="selNone">Clear selection</button>
            <button type="submit" class="btn btn-sm btn-primary" id="pubBtn" disabled><x-icon name="check" size="sm"/> Publish selected</button>
            @if($pending)<button type="submit" class="btn btn-sm btn-accent" id="acceptAllBtn" name="accept_all" value="1" onclick="return confirm('Publish all {{ $pending }} recommended rate(s) in this range?')">Accept all</button>@endif
          </div>
        @endif
      </div>
      @if($types->isEmpty())
        <div class="rmp-empty"><x-empty title="No active room types" text="Activate room types before reviewing recommendations or publishing revenue-managed prices." icon="bed"/></div>
      @else
      <div class="rmp-scroll" role="region" aria-label="Scrollable {{ $days }}-day rate recommendation grid" tabindex="0">
        <table class="table table-compact rm-grid rmp-grid" aria-describedby="rmpLegend">
          <thead><tr><th scope="col" class="rmp-room-head">Room type / rate guard-rails</th>
            @foreach($occ as $date => $o) @php $d = \Illuminate\Support\Carbon::parse($date); $isWeekend = in_array($d->dayOfWeek, \App\Services\RateService::WEEKEND, true); $isToday = $d->isSameDay(bdate()); @endphp
              <th scope="col" class="{{ $isWeekend ? 'rmp-weekend ' : '' }}{{ $isToday ? 'rmp-today' : '' }}"><div class="rmp-day"><strong>{{ $d->format('D') }}</strong><time datetime="{{ $d->toDateString() }}">{{ $d->format('j M') }}</time>@if($isToday)<span class="rmp-day-flag">Today</span>@endif</div></th>
            @endforeach
          </tr></thead>
          <tbody>
            <tr class="rmp-occ-row"><th scope="row" class="rmp-room-cell">Hotel occupancy <span class="rmp-room-base">On the books / capacity</span></th>
              @foreach($occ as $date => $o)<td class="{{ $o['hotel'] >= 80 ? 'occ-hi' : ($o['hotel'] >= 50 ? 'occ-mid' : 'occ-lo') }}" aria-label="{{ $o['hotel'] }} percent occupancy, {{ $o['sold'] }} sold of {{ $o['cap'] }} rooms"><span class="rmp-occ-cell">{{ $o['hotel'] }}%</span><span class="rmp-occ-detail">{{ $o['sold'] }}/{{ $o['cap'] }}</span></td>@endforeach
            </tr>
            @foreach($types as $t)
              <tr><th scope="row" class="rmp-room-cell"><span class="rmp-room-name">{{ $t->name }}</span><span class="rmp-room-base">Base {{ money($t->base_rate) }}</span>
                <span class="rmp-room-limit">@if($t->min_rate !== null || $t->max_rate !== null)Floor {{ $t->min_rate !== null ? number_format($t->min_rate, 0) : '—' }} · ceiling {{ $t->max_rate !== null ? number_format($t->max_rate, 0) : '—' }}@else No floor / ceiling @endif</span></th>
              @foreach($grid[$t->id] ?? [] as $date => $c)
                @php
                  $rec = $c['rec']; $target = $mode === 'auto' ? $c['current'] : $rec['rate']; $delta = $target - $c['current'];
                  $cellClass = $c['fixed'] ? 'fixed' : ($c['change'] ? ($delta > 0 ? 'up' : 'down') : 'steady');
                  $dateLabel = \Illuminate\Support\Carbon::parse($date)->format('l j F Y');
                  $tip = $dateLabel.' · lead '.$rec['lead'].' days · hotel occupancy '.$c['occ_hotel'].'% · room occupancy '.$c['occ_type'].'%. Base rate '.money($c['base']).'.';
                  foreach ($rec['rules'] as $r) { $tip .= ' '.$r['name'].' '.$r['adj'].' ('.($r['delta'] >= 0 ? '+' : '').money($r['delta']).').'; }
                  if ($rec['limit']) { $tip .= ' Rate held at the '.$rec['limit'].'.'; }
                  if ($rec['min_stay']) { $tip .= ' Minimum stay '.$rec['min_stay'].' nights.'; }
                  $tip .= ' Recommended '.money($rec['rate']).' · live '.money($c['current']).($c['published'] ? ' (published).' : '.');
                  $cellLabel = $t->name.', '.$dateLabel.'. Live rate '.money($c['current']).'. Recommended '.money($rec['rate']).($c['fixed'] ? '. Fixed price, not editable by revenue management.' : ($c['stop'] ? '. Stop-sell, closed to online booking.' : ($c['change'] ? '. Rate differs from recommendation.' : '. No rate change recommended.')));
                  $pickKey = $t->id.'|'.$date;
                @endphp
                <td class="rmp-cell {{ $cellClass }} {{ $c['stop'] ? 'stop' : '' }}" title="{{ $tip }}" aria-label="{{ $cellLabel }}">
                  @if($c['fixed'])
                    <span class="rmp-current-rate">{{ number_format($c['current'], 0) }}</span><span class="rmp-meta"><span class="rmp-tag fixed">Fixed</span></span>
                  @elseif($editable)
                    <span class="rmp-live">Live {{ number_format($c['current'], 0) }}@if($c['published'])<sup class="rm-tag">RM</sup>@endif</span>
                    <input class="rmp-price" type="number" step="1" min="1" name="rate[{{ $t->id }}][{{ $date }}]" value="{{ old('rate.'.$t->id.'.'.$date, round($rec['rate'])) }}" data-cur="{{ $c['current'] }}" data-pick="{{ $pickKey }}" aria-label="{{ $t->name }} recommended rate for {{ $dateLabel }}" inputmode="decimal">
                    <div class="rmp-delta" aria-live="polite">{{ $c['change'] ? ($delta > 0 ? '▲ +' : '▼ ').number_format($delta, 0) : 'No change' }}</div>
                    <label class="rmp-pick"><input type="checkbox" name="pick[]" value="{{ $pickKey }}" data-change="{{ $c['change'] ? 1 : 0 }}" aria-label="Select {{ $t->name }} on {{ $dateLabel }}" @checked(in_array($pickKey, old('pick', [])))><span>Select</span></label>
                  @else
                    <span class="rmp-current-rate">{{ number_format($c['current'], 0) }}@if($c['published'] || ($mode === 'auto' && $rec['rules']))<sup class="rm-tag">RM</sup>@endif</span>
                    @if($mode !== 'auto' && $c['change'])<span class="rmp-rec-label">Recommend {{ number_format($rec['rate'], 0) }}</span>@elseif($mode === 'auto' && abs($c['current'] - $c['base']) >= .01)<span class="rmp-rec-label"><s>Base {{ number_format($c['base'], 0) }}</s></span>@else<span class="rmp-rec-label">{{ $c['change'] ? 'Differs from recommendation' : 'On target' }}</span>@endif
                  @endif
                  <span class="rmp-meta">@if($c['stop'])<span class="rmp-tag closed">Closed</span>@endif @if($rec['min_stay'])<span class="rmp-tag min">Min {{ $rec['min_stay'] }} nts</span>@endif @if($c['left'] !== null)<span>{{ $c['left'] }} left</span>@endif</span>
                </td>
              @endforeach</tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="rmp-legend" id="rmpLegend" aria-label="Pricing grid legend"><span><i class="rmp-swatch up"></i> Recommend raise</span><span><i class="rmp-swatch down"></i> Recommend lower</span><span><i class="rmp-swatch fixed"></i> Fixed / protected</span><span><i class="rmp-swatch stop"></i> Stop-sell</span><span><sup class="rm-tag">RM</sup> Revenue-managed rate is live</span><span>Occupancy row shows rooms sold / capacity</span></div>
      @endif
    </section>
  </form>

  <div class="grid g-2 mt-2 rmp-bottom-grid">
    <section class="card" aria-labelledby="rmpHowTitle"><div class="card-head"><h3 id="rmpHowTitle">How recommendations are made</h3></div><div class="card-body rmp-steps">
      <div class="rmp-step"><b>1 · Base rate</b>The room type’s base / weekend price and applicable seasonal overrides form the starting rate; revenue-managed overrides are excluded.</div>
      <div class="rmp-step"><b>2 · Yield rules</b>Matching occupancy, lead-time, weekday, and date rules apply in priority order.</div>
      <div class="rmp-step"><b>3 · Guard-rails</b>The suggested price is rounded to the nearest {{ setting('rm_round_to', 50) }}, then limited by the room type’s floor and ceiling.</div>
    </div></section>
    @if($canManage)
    <section class="card" aria-labelledby="rmpResetTitle"><div class="card-head"><h3 id="rmpResetTitle">Reset published rates</h3></div><div class="card-body">
      <form method="post" action="{{ route('revenue.reset') }}" class="form-grid rmp-reset-form" onsubmit="return confirm('Remove revenue-managed rates in this range?')">@csrf
        <input type="hidden" name="days" value="{{ $days }}">
        <div class="field"><label>From</label><input type="date" name="date_from" value="{{ $from->toDateString() }}" required></div>
        <div class="field"><label>To (inclusive)</label><input type="date" name="date_to" value="{{ $windowEnd->toDateString() }}" required></div>
        <div class="field"><label>Room type</label><select name="room_type_id"><option value="">All room types</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
        <div class="field"><button type="submit" class="btn btn-danger"><x-icon name="trash" size="sm"/> Remove published rates</button></div>
      </form>
      <p class="small muted mb-0">Seasonal and fixed rates from the Rate Calendar are not removed. Reset only removes revenue-managed day-rate overrides.</p>
    </div></section>
    @endif
  </div>
</div>
@endsection
@push('scripts')
<script>
(() => {
  const boxes = [...document.querySelectorAll('.rm-grid input[name="pick[]"]')];
  const count = () => {
    const selected = boxes.filter(box => box.checked).length;
    const output = document.getElementById('pickCount');
    const publish = document.getElementById('pubBtn');
    if (output) output.textContent = selected;
    if (publish) publish.disabled = selected === 0;
  };
  document.querySelectorAll('.rm-grid input[type=number][data-pick]').forEach(input => input.addEventListener('input', () => {
    const cell = input.closest('td');
    const current = Number.parseFloat(input.dataset.cur);
    const value = Number.parseFloat(input.value);
    const delta = value - current;
    cell.classList.toggle('up', delta > 0.5);
    cell.classList.toggle('down', delta < -0.5);
    cell.querySelector('.rmp-delta').textContent = Number.isNaN(delta) || Math.abs(delta) < 0.5 ? 'No change' : (delta > 0 ? '▲ +' : '▼ ') + Math.round(delta).toLocaleString();
    const checkbox = cell.querySelector('input[type=checkbox]');
    if (checkbox) checkbox.checked = true;
    count();
  }));
  boxes.forEach(box => box.addEventListener('change', count));
  document.getElementById('selChanges')?.addEventListener('click', () => { boxes.forEach(box => { box.checked = box.dataset.change === '1'; }); count(); });
  document.getElementById('selNone')?.addEventListener('click', () => { boxes.forEach(box => { box.checked = false; }); count(); });
  count();
})();
</script>
@endpush
