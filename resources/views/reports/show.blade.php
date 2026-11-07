@extends('layouts.app')
@section('title', $title)
@section('crumbs') <a href="{{ route('reports.index') }}">Reports</a> / {{ $title }} @endsection
@section('actions')
<a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><x-icon name="download"/> Excel / CSV</a>
<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>
<button class="btn" type="button" data-modal="save-view"><x-icon name="star"/> Save view</button>
@if(can_do('reports.schedule'))<a class="btn" href="{{ route('reports.schedules', ['new' => $report] + ($activeView ? ['view' => $activeView->id] : []) + (request()->query('value') ? ['slice' => request()->query('slice'), 'value' => request()->query('value')] : [])) }}"><x-icon name="mail"/> Schedule{{ request()->query('value') ? ' this slice' : '' }}</a>@endif
@endsection
@section('content')
@php $cols = $data['cols']; $rows = $data['rows']; $money = $data['money']; $sum = $data['sum'];
  $RS = \App\Services\ReportScheduleService::class;
  $rangeOpts = $mode === 'date' ? array_intersect_key($RS::RANGES, array_flip([...$RS::DATE_RANGES, 'custom'])) : ($mode === 'range' ? $RS::RANGES : ['today' => 'Current position']);
  $myView = $activeView && $activeView->user_id === auth()->id(); @endphp
{{-- What this screen is showing, in one line: the period it answers on, how many rows
     survived any filter, and the business date the figures are cut at. It stays on the
     print sheet so a printed report is never undated. --}}
<div class="rpt-context" data-testid="report-context">
  <span><x-icon name="calendar"/>
    @if($mode === 'range')
      <b>{{ $from->format('d M Y') }}</b> – <b>{{ $to->format('d M Y') }}</b>
    @elseif($mode === 'date')
      as on <b>{{ $to->format('d M Y') }}</b>
    @else
      <b>Current position</b>
    @endif
  </span>
  <span><b>{{ $rows->count() }}</b> row{{ $rows->count() === 1 ? '' : 's' }}@if(!empty($data['note'])) · {{ $data['note'] }}@endif</span>
  <span>Business date <b>{{ bdate()->format('d M Y') }}</b></span>
  @if($activeView)
    <span>View <b>{{ $activeView->name }}</b>@if($activeView->user_id !== auth()->id() && $activeView->user) · shared by {{ $activeView->user->name }}@endif</span>
  @endif
  <a class="rpt-context-link no-print" href="{{ route('reports.index') }}">← All reports</a>
</div>
{{-- v10.10 a report may offer slices of its own scope — the group's report offers brand and city.
     They are links, so a slice can be bookmarked, printed and e-mailed like any other state of the
     report, and the chip that is on says which slice the figures below answer for. --}}
@if(!empty($data['choices']))
{{-- v10.12 one row per question the report asks of its scope: which properties ("Show"), and whether
     they are compared or listed ("Compare"). A row is chips of links, so every state is a URL. --}}
<div class="rpt-chips no-print" role="group" aria-label="Restrict this report" data-testid="report-slices">
  @foreach($data['choices'] as $row)
    <span class="small muted"><x-icon name="list"/> {{ $row['label'] }}:</span>
    @foreach($row['choices'] as $choice)
      <a class="rpt-chip {{ !empty($choice['active']) ? 'is-on' : '' }}" href="{{ $choice['url'] }}"
         aria-pressed="{{ !empty($choice['active']) ? 'true' : 'false' }}"
         @if(!empty($choice['active'])) aria-current="true" @endif>{{ $choice['label'] }} <span>{{ $choice['count'] }}</span></a>
    @endforeach
  @endforeach
</div>
@endif
@if($views->count() || $activeView)
<div class="card no-print" data-testid="saved-views"><div class="card-body flex gap-1 wrap" style="align-items:center">
  <span class="small muted"><x-icon name="star"/> Saved views:</span>
  @foreach($views as $v)
    <a class="btn btn-sm {{ $activeView?->id === $v->id ? 'btn-primary' : 'btn-soft' }}" href="{{ $v->url() }}" title="{{ $RS::RANGES[$v->range] ?? $v->range }}{{ $v->user_id !== auth()->id() ? ' · shared by '.$v->user?->name : '' }}">{{ $v->name }}@if($v->is_shared) <span class="tiny">· shared</span>@endif</a>
  @endforeach
  @if($activeView)
    <span class="small muted" style="margin-left:auto" data-testid="active-view">Showing <b>{{ $activeView->name }}</b> · {{ $RS::RANGES[$activeView->range] ?? $activeView->range }}@if($activeView->columns) · {{ count($cols) }} of {{ count($allCols) }} columns @endif @if($activeView->filter) · filter “{{ $activeView->filter }}”@endif</span>
    @if($myView)<button type="button" class="btn btn-sm" data-modal="edit-view">Edit view</button>@endif
    <a class="btn btn-sm btn-soft" href="{{ route('reports.show', $report) }}">Clear</a>
  @endif
</div></div>
@endif
@if($mode === 'range')
<div class="card no-print"><div class="card-body"><form class="filters">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from->toDateString() }}"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="{{ $to->toDateString() }}"></div>
  <div class="flex gap-1">
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->toDateString() }}&to={{ bdate()->toDateString() }}">Today</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->subDay()->toDateString() }}&to={{ bdate()->copy()->subDay()->toDateString() }}">Yesterday</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->startOfMonth()->toDateString() }}&to={{ bdate()->toDateString() }}">MTD</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->subMonthNoOverflow()->startOfMonth()->toDateString() }}&to={{ bdate()->copy()->subMonthNoOverflow()->endOfMonth()->toDateString() }}">Last month</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->startOfYear()->toDateString() }}&to={{ bdate()->toDateString() }}">YTD</a>
    <select class="input input-sm" data-range-nav aria-label="More periods" style="max-width:170px"><option value="">More periods…</option>
      @foreach($RS::RANGES as $rk => $rl) @continue($rk === 'custom') @php [$pf, $pt] = $RS::resolve($rk); @endphp<option value="?from={{ $pf->toDateString() }}&to={{ $pt->toDateString() }}" @selected($range === $rk)>{{ $rl }}</option>@endforeach
    </select>
  </div>
  <button class="btn btn-primary">Generate</button>
</form></div></div>
@endif
@if($mode === 'date')
<div class="card no-print"><div class="card-body"><form class="filters">
  <div class="field"><label>As on (business date)</label><input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
  <div class="flex gap-1">
    <a class="btn btn-sm btn-soft" href="?to={{ bdate()->copy()->subDay()->toDateString() }}">Last audited day</a>
    <a class="btn btn-sm btn-soft" href="?to={{ bdate()->toDateString() }}">Today (so far)</a>
    <a class="btn btn-sm btn-soft" href="?to={{ bdate()->copy()->subMonthNoOverflow()->endOfMonth()->toDateString() }}">Last month end</a>
  </div>
  <button class="btn btn-primary">Generate</button>
</form></div></div>
@endif
<div class="print-only print-head"><h2>{{ hotel()->name }}</h2><div>{{ $title }}
  @if($mode==='range') · {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} @elseif($mode==='date') · as on {{ $to->format('d M Y') }} @else · as on {{ bdate()->format('d M Y') }} @endif
  <div class="tiny">Printed {{ now()->format('d M Y H:i') }}@if(auth()->user()) by {{ auth()->user()->name }}@endif · {{ $rows->count() }} row(s)@if($activeView) · view “{{ $activeView->name }}”@endif</div>
</div></div>

@if(!empty($data['chart']) && $rows->count() > 1)
@php $ck = $data['chart']['key']; $max = max(1, $rows->max($ck)); $chartRows = $rows->take(40); @endphp
<div class="card no-print"><div class="card-head"><h3>{{ $data['chart']['label'] }}</h3></div><div class="card-body">
  <div class="bar-chart">@foreach($chartRows as $r)<div class="col"><div class="b" data-v="{{ $r[$data['chart']['x']] }}: {{ $r[$ck] }}" style="height:{{ max(1, round($r[$ck] / $max * 100)) }}%"></div><small>{{ \Illuminate\Support\Str::limit((string) $r[$data['chart']['x']], 5, '') }}</small></div>@endforeach</div>
</div></div>
@endif

@if(!empty($data['summary']) && count($data['summary']))
{{-- A summary line may hold counts (covers, rooms, guests) beside money. A whole
     number reads as a count; a fractional one carries the currency. --}}
<div class="stats">@foreach($data['summary'] as $k => $v)@php $isMoneyStat = ! is_string($v) && ! is_int($v); @endphp<x-stat :label="$k ?: '—'" :value="is_string($v) ? $v : ($isMoneyStat ? money($v) : number_format($v))" :icon="$isMoneyStat ? 'money' : 'chart'" tone="slate"/>@endforeach</div>
@endif

<div class="card flush"><div class="card-head"><h3>{{ $rows->count() }} record(s)</h3>
  <span class="tiny muted no-print" id="rptFilterCount" aria-live="polite">{{ $rows->count() }} rows</span>
  <input class="input input-sm no-print" placeholder="Quick filter…" aria-label="Filter the rows of this report" data-filter-table="#rpt" style="max-width:220px"></div>
<div class="card-body table-wrap"><table class="table table-compact" id="rpt">
<thead><tr><th>#</th>@foreach($cols as $k => $l)<th class="{{ in_array($k, $money) || in_array($k, $sum) ? 'num' : '' }}">{{ $l }}</th>@endforeach</tr></thead>
<tbody>
@forelse($rows as $r)
<tr class="{{ $r['_class'] ?? '' }}"><td class="muted tiny">{{ $loop->iteration }}</td>@foreach($cols as $k => $l)<td class="{{ in_array($k, $money) || in_array($k, $sum) ? 'num' : '' }}">{{ in_array($k, $money) ? (! isset($r[$k]) ? '' : money($r[$k], false)) : ($r[$k] ?? '') }}</td>@endforeach</tr>
@empty
<tr><td colspan="{{ count($cols) + 1 }}"><x-empty text="No data for the selected period." icon="chart"/></td></tr>
@endforelse
<tr id="rptNoMatch" hidden><td colspan="{{ count($cols) + 1 }}" class="small muted">No row in this report matches that filter.</td></tr>
</tbody>
@if($rows->count() && count($sum))
<tfoot><tr><td></td>@foreach($cols as $k => $l)<td class="num">@if(in_array($k, $sum)){{ in_array($k, $money) ? money($rows->sum($k), false) : $rows->sum($k) }}@elseif($loop->first) Total @endif</td>@endforeach</tr></tfoot>
@endif
</table></div></div>
@if(!empty($data['note']))<p class="small muted">{{ $data['note'] }}</p>@endif

@php
  // The other reports of the same area, so a reader never has to go back to the
  // catalogue to find the next one — and only the ones they may open.
  $area = \App\Http\Controllers\ReportController::REPORTS[$report][0] ?? null;
  $siblings = $area
    ? collect(\App\Http\Controllers\ReportController::REPORTS)->filter(fn ($r) => $r[0] === $area && can_do($r[4] ?? 'reports.view'))
    : collect();
@endphp
@if($siblings->count() > 1)
<section class="card no-print rpt-siblings" data-testid="report-siblings">
  <div class="card-head">
    <h3><x-icon name="grid"/> More in {{ $area }}</h3>
    <a class="btn btn-sm btn-soft" href="{{ route('reports.index') }}">All {{ $siblings->count() }} reports in {{ $area }} →</a>
  </div>
  <div class="card-body flex gap-1 wrap">
    @foreach($siblings as $slug => $r)
      <a class="rpt-sibling{{ $slug === $report ? ' is-here' : '' }}" href="{{ route('reports.show', $slug) }}"@if($slug === $report) aria-current="page"@endif>{{ $r[1] }}</a>
    @endforeach
  </div>
</section>
@endif

@foreach(['save-view' => null] + ($myView ? ['edit-view' => $activeView] : []) as $mid => $ev)
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:720px">
  <form method="POST" action="{{ $ev ? route('reports.views.update', $ev) : route('reports.views.store') }}">@csrf @if($ev) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $ev ? 'Edit view “'.$ev->name.'”' : 'Save this report as a view' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <input type="hidden" name="report_key" value="{{ $report }}">
    {{-- v10.11 the view keeps the slice the reader is looking at: a saved view that forgets it opens
         on the whole portfolio, which is a different report. --}}
    @foreach(\App\Http\Controllers\ReportController::REPORT_PARAMS as $rp)
      @if(request()->query($rp))<input type="hidden" name="{{ $rp }}" value="{{ request()->query($rp) }}">@endif
    @endforeach
    <input type="hidden" name="date_from" value="{{ $ev?->date_from?->toDateString() ?? $from->toDateString() }}"><input type="hidden" name="date_to" value="{{ $ev?->date_to?->toDateString() ?? $to->toDateString() }}">
    <div class="form-grid">
      <div class="field"><label>Name</label><input class="input" name="name" required maxlength="120" value="{{ $ev?->name }}" placeholder="e.g. {{ $title }} – {{ $RS::RANGES[$range] ?? 'custom' }}"></div>
      <div class="field"><label>Date range</label><select class="input" name="range">@foreach($rangeOpts as $rk => $rl)<option value="{{ $rk }}" @selected(($ev?->range ?? $range) === $rk)>{{ $rk === 'custom' ? 'Fixed: '.$from->format('d M').' – '.$to->format('d M Y') : $rl }}</option>@endforeach</select>
        <small class="muted">Relative ranges always follow the current business date.</small></div>
    </div>
    <div class="field"><label>Columns <span class="muted tiny">(untick to hide)</span></label>
      <div class="flex gap-1 wrap">@foreach($allCols as $ck => $cl)<label class="check small"><input type="checkbox" name="columns[]" value="{{ $ck }}" @checked(! $ev || ! $ev->columns ? isset($cols[$ck]) : in_array($ck, $ev->columns))> {{ $cl }}</label>@endforeach</div></div>
    <div class="form-grid">
      <div class="field"><label>Only rows containing</label><input class="input" name="filter" maxlength="100" value="{{ $ev?->filter ?? $activeView?->filter }}" placeholder="optional text filter"></div>
      <div class="field"><label>&nbsp;</label>
        <label class="check"><input type="checkbox" name="is_pinned" value="1" @checked($ev?->is_pinned)> Pin to my dashboard</label>
        @if(can_do('reports.schedule'))<label class="check"><input type="checkbox" name="is_shared" value="1" @checked($ev?->is_shared)> Share with colleagues who can see this report</label>@endif
      </div>
    </div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">{{ $ev ? 'Update view' : 'Save view' }}</button></div>
  </form>
</div></div>
@endforeach
@push('head')
<style>
  /* The report prints on a landscape sheet: a wide report must never have its columns
     clipped, its header must repeat on every page, and the rows must not straddle one. */
  @page { size: A4 landscape; margin: 10mm; }
  @media print {
    .table-wrap { overflow: visible !important; max-height: none !important; }
    #rpt thead { display: table-header-group; }
    #rpt thead th { position: static; }
    #rpt tbody tr { break-inside: avoid; page-break-inside: avoid; }
    .stats, .rpt-context { break-inside: avoid; }
    .print-head .tiny { font-weight: 400; }
  }
</style>
@endpush
@push('scripts')
<script>
document.querySelectorAll('[data-range-nav]').forEach(s => s.addEventListener('change', () => { if (s.value) location.href = s.value; }));

// The quick filter narrows the rows; it also has to say how many are left, and to say
// so plainly when nothing matches rather than leaving a headless table on the screen.
(() => {
  const input = document.querySelector('[data-filter-table="#rpt"]');
  const table = document.getElementById('rpt');
  const count = document.getElementById('rptFilterCount');
  const noMatch = document.getElementById('rptNoMatch');
  if (!input || !table || !table.tBodies[0]) return;
  const rows = [...table.tBodies[0].rows].filter(row => row.id !== 'rptNoMatch' && !row.querySelector('td[colspan]'));
  const apply = () => {
    const q = input.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(row => {
      const hit = !q || row.textContent.toLowerCase().includes(q);
      row.style.display = hit ? '' : 'none';
      if (hit) shown += 1;
    });
    if (count) count.textContent = q ? `${shown} of ${rows.length} rows` : `${rows.length} rows`;
    if (noMatch) noMatch.hidden = !q || shown > 0;
  };
  input.addEventListener('input', apply);
  apply();
})();
</script>
@endpush
@endsection
